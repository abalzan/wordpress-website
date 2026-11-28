#!/usr/bin/env python3
"""
verify-frontend-performance-baseline.py — reproducible LOCAL frontend baseline.

## Why this exists

`docs/frontend.md` records a lot of deliberate performance work (hero LCP
preload + responsive ladder, non-blocking Google Fonts, asset cache-busting,
language-scoped transients). "It is fast" is not a measurement, and a change
made without a baseline is a guess. This collector produces the number.

It drives real Chromium (Playwright, already the repository's browser engine)
against the **local Docker WordPress only**, and records, per route × viewport ×
repetition, what the browser itself measured:

  * **TTFB** — Navigation Timing `responseStart` (server + network, first byte);
  * **LCP** — `PerformanceObserver('largest-contentful-paint')`, the final entry;
  * **CLS** — `PerformanceObserver('layout-shift')`, the sum of non-input shifts;
  * **INP proxy** — the longest `PerformanceObserver('event')` duration for ONE
    scripted activation of a real control. This is *not* field INP (which needs
    real visitors); it is the observable interaction latency for a deterministic
    input, and it is labelled as a proxy everywhere;
  * **transfer bytes** and **request count** — Resource Timing `transferSize`;
  * the **largest assets** by transferred bytes.

## What it deliberately does not do

* No production contact. A production host is refused before a socket opens; a
  production number is not a baseline for this laptop, and probing production is
  not authorised by a local test.
* No Lighthouse score, no "performance budget". Neither was measured here, and
  inventing one would be a fabricated target.
* No throttling. The local stack has no network shaping, so absolute values are
  *local* and only comparable **between runs on the same machine**.

## Output

A median per (route, viewport) plus the raw samples, printed as a table and
optionally written as one machine-readable JSON file:

    python3 tests/browser/verify-frontend-performance-baseline.py \\
        --out docs/evidence/2026-11-28-frontend-performance/perf-baseline.json

Exit code: 0 when every route×viewport produced usable samples, 1 when a route
failed to load, 2 when the environment (Playwright, Chromium, the local site) is
unavailable — an unavailable environment is never reported as a measurement.
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")

PRODUCTION_HOSTS = frozenset({"conexaobr.ie", "www.conexaobr.ie"})

VIEWPORTS = (
    {"name": "desktop", "width": 1440, "height": 1000},
    {"name": "mobile", "width": 390, "height": 844},
)

# Representative Portuguese and English routes: the homepage plus the four
# high-traffic content/archive surfaces. Kept deliberately short so a run is a
# few minutes, not an hour.
ROUTES = (
    {"id": "pt-home", "language": "pt", "kind": "home", "path": "/"},
    {"id": "en-home", "language": "en", "kind": "home", "path": "/en/"},
    {"id": "pt-guides", "language": "pt", "kind": "archive", "path": "/guias/"},
    {"id": "en-guides", "language": "en", "kind": "archive", "path": "/en/guias/"},
    {"id": "pt-jobs", "language": "pt", "kind": "archive", "path": "/empregos/"},
    {"id": "en-jobs", "language": "en", "kind": "archive", "path": "/en/empregos/"},
    {"id": "pt-events", "language": "pt", "kind": "archive", "path": "/eventos/"},
    {"id": "en-events", "language": "en", "kind": "archive", "path": "/en/eventos/"},
    {"id": "pt-leisure", "language": "pt", "kind": "archive", "path": "/lazer/"},
    {"id": "en-leisure", "language": "en", "kind": "archive", "path": "/en/lazer/"},
)

# Installed BEFORE navigation so the observers see the whole load.
INIT_SCRIPT = """
window.__conexaoPerf = { lcp: 0, cls: 0, events: [] };
try {
  new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) {
      window.__conexaoPerf.lcp = entry.startTime;
    }
  }).observe({ type: 'largest-contentful-paint', buffered: true });
} catch (e) { /* unsupported: LCP stays 0 */ }
try {
  new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) {
      if (!entry.hadRecentInput) { window.__conexaoPerf.cls += entry.value; }
    }
  }).observe({ type: 'layout-shift', buffered: true });
} catch (e) { /* unsupported: CLS stays 0 */ }
try {
  new PerformanceObserver((list) => {
    for (const entry of list.getEntries()) {
      window.__conexaoPerf.events.push(entry.duration);
    }
  }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
} catch (e) { /* unsupported: no interaction sample */ }
"""

# Reads the browser's own measurements after the page has settled.
READ_JS = """
() => {
  const nav = performance.getEntriesByType('navigation')[0] || null;
  const resources = performance.getEntriesByType('resource').map((entry) => ({
    name: entry.name,
    type: entry.initiatorType,
    transfer: entry.transferSize || 0,
    duration: entry.duration || 0,
  }));
  const perf = window.__conexaoPerf || { lcp: 0, cls: 0, events: [] };
  return {
    ttfb_ms: nav ? nav.responseStart : null,
    dom_content_loaded_ms: nav ? nav.domContentLoadedEventEnd : null,
    load_ms: nav ? nav.loadEventEnd : null,
    lcp_ms: perf.lcp || 0,
    cls: perf.cls || 0,
    event_durations: perf.events || [],
    document_transfer: nav ? (nav.transferSize || 0) : 0,
    resources,
  };
}"""


def refuse_production(base_url: str) -> None:
    """Refuse a production host before any socket is opened."""
    host = (urllib.parse.urlparse(base_url).hostname or "").lower()
    if host in PRODUCTION_HOSTS:
        print(
            "REFUSING to measure the production host %r. This collector is local-only."
            % host,
            file=sys.stderr,
        )
        sys.exit(2)


def local_site_available(base_url: str) -> bool:
    """True when the local WordPress answers."""
    try:
        with urllib.request.urlopen(base_url + "/", timeout=15) as response:
            return response.status == 200
    except (urllib.error.URLError, OSError):
        return False


def load_playwright():
    """Import Playwright or exit 2 (environment unavailable, never a pass)."""
    try:
        from playwright.sync_api import sync_playwright  # noqa: PLC0415
    except ImportError:
        print("ENVIRONMENT UNAVAILABLE: playwright is not installed", file=sys.stderr)
        print("  install with: pip install playwright && playwright install chromium", file=sys.stderr)
        sys.exit(2)
    return sync_playwright


def short_name(url: str) -> str:
    """A compact, privacy-free asset label for the report."""
    parsed = urllib.parse.urlparse(url)
    name = os.path.basename(parsed.path) or parsed.path or url
    return name[:70]


def measure_once(browser, viewport: dict, route: dict) -> dict:
    """One cold-context load of one route at one viewport."""
    context = browser.new_context(
        viewport={"width": viewport["width"], "height": viewport["height"]}
    )
    page = context.new_page()
    page.add_init_script(INIT_SCRIPT)
    page.set_default_navigation_timeout(20000)
    try:
        # `domcontentloaded`, not `load`: the page pulls a third-party
        # stylesheet, and `load` waits for it. LCP/CLS are observed through
        # PerformanceObserver with `buffered: true`, so a later paint is still
        # captured — but a hung external subresource must never hang the run.
        response = page.goto(BASE_URL + route["path"], wait_until="domcontentloaded")
        status = response.status if response else 0
        if status != 200:
            return {"status": status, "error": "non-200 response"}

        # Let the observers settle, then drive ONE real interaction: the header
        # theme toggle exists at every breakpoint and mutates the document.
        page.wait_for_timeout(1200)
        toggle = page.query_selector(".theme-toggle")
        if toggle:
            toggle.click()
            page.wait_for_timeout(150)
            toggle.click()
            page.wait_for_timeout(150)

        data = page.evaluate(READ_JS)
    finally:
        context.close()

    resources = data["resources"]
    transfer = (data["document_transfer"] or 0) + sum(
        resource["transfer"] for resource in resources
    )
    events = [duration for duration in data["event_durations"] if duration]
    largest = sorted(resources, key=lambda item: item["transfer"], reverse=True)[:5]

    return {
        "status": 200,
        "ttfb_ms": round(data["ttfb_ms"], 1) if data["ttfb_ms"] is not None else None,
        "dom_content_loaded_ms": (
            round(data["dom_content_loaded_ms"], 1)
            if data["dom_content_loaded_ms"] is not None
            else None
        ),
        "load_ms": round(data["load_ms"], 1) if data["load_ms"] is not None else None,
        "lcp_ms": round(data["lcp_ms"], 1),
        "cls": round(data["cls"], 4),
        "inp_proxy_ms": round(max(events), 1) if events else None,
        "interaction_observed": bool(toggle),
        "requests": len(resources) + 1,
        "transfer_bytes": transfer,
        "largest_assets": [
            {
                "name": short_name(item["name"]),
                "type": item["type"],
                "bytes": item["transfer"],
            }
            for item in largest
        ],
    }


def median(values: list) -> float | None:
    """Median of the non-null samples, or None when there are none."""
    clean = [value for value in values if value is not None]
    return round(statistics.median(clean), 1) if clean else None


def summarise(samples: list[dict]) -> dict:
    """Median plus spread for one (route, viewport) group."""
    lcp = [sample.get("lcp_ms") for sample in samples]
    return {
        "samples": len(samples),
        "ttfb_ms_median": median([sample.get("ttfb_ms") for sample in samples]),
        "lcp_ms_median": median(lcp),
        "lcp_ms_min": min([value for value in lcp if value is not None], default=None),
        "lcp_ms_max": max([value for value in lcp if value is not None], default=None),
        "cls_median": median([sample.get("cls") for sample in samples]),
        "inp_proxy_ms_median": median(
            [sample.get("inp_proxy_ms") for sample in samples]
        ),
        "requests_median": median([sample.get("requests") for sample in samples]),
        "transfer_bytes_median": median(
            [sample.get("transfer_bytes") for sample in samples]
        ),
    }


def render_table(results: list[dict]) -> str:
    """A fixed-width table of the medians, for the console and the report."""
    header = (
        "route".ljust(14)
        + "viewport".ljust(9)
        + "TTFB".rjust(9)
        + "LCP".rjust(9)
        + "CLS".rjust(8)
        + "INP*".rjust(8)
        + "req".rjust(6)
        + "transfer".rjust(12)
    )
    lines = [header, "-" * len(header)]
    for result in results:
        summary = result["median"]
        transfer_kb = (
            "%.1f KB" % (summary["transfer_bytes_median"] / 1024)
            if summary["transfer_bytes_median"]
            else "n/a"
        )
        lines.append(
            result["route"].ljust(14)
            + result["viewport"].ljust(9)
            + ("%s ms" % summary["ttfb_ms_median"]).rjust(9)
            + ("%s ms" % summary["lcp_ms_median"]).rjust(9)
            + ("%s" % summary["cls_median"]).rjust(8)
            + ("%s ms" % summary["inp_proxy_ms_median"]).rjust(8)
            + ("%s" % summary["requests_median"]).rjust(6)
            + transfer_kb.rjust(12)
        )
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Local-only frontend performance baseline (Playwright/Chromium)."
    )
    parser.add_argument("--repeats", type=int, default=5, help="loads per route/viewport (default 5)")
    parser.add_argument("--out", default=None, help="write the machine-readable result here")
    parser.add_argument("--headful", action="store_true", help="show the browser window")
    args = parser.parse_args()

    refuse_production(BASE_URL)

    if not local_site_available(BASE_URL):
        print("ENVIRONMENT UNAVAILABLE: no local WordPress at %s" % BASE_URL, file=sys.stderr)
        return 2

    repeats = max(1, args.repeats)
    sync_playwright = load_playwright()

    print("== Frontend performance baseline (local only) ==")
    print("   base:    %s" % BASE_URL)
    print("   routes:  %d (PT + EN)" % len(ROUTES))
    print("   repeats: %d per route/viewport" % repeats)
    print("   note:    local, unthrottled values — comparable only between runs on this machine")

    results: list[dict] = []
    samples_raw: list[dict] = []
    failures = 0

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headful)
        user_agent = browser.new_page().evaluate("() => navigator.userAgent")

        for route in ROUTES:
            for viewport in VIEWPORTS:
                samples = []
                for _ in range(repeats):
                    sample = measure_once(browser, viewport, route)
                    if sample.get("status") != 200:
                        failures += 1
                    samples.append(sample)
                    samples_raw.append(
                        {
                            "route": route["id"],
                            "path": route["path"],
                            "viewport": viewport["name"],
                            "language": route["language"],
                            "kind": route["kind"],
                            **sample,
                        }
                    )
                usable = [sample for sample in samples if sample.get("status") == 200]
                results.append(
                    {
                        "route": route["id"],
                        "path": route["path"],
                        "language": route["language"],
                        "kind": route["kind"],
                        "viewport": viewport["name"],
                        "viewport_px": "%dx%d" % (viewport["width"], viewport["height"]),
                        "median": summarise(usable),
                    }
                )
                print(
                    "  measured %-12s %-8s (%d/%d usable)"
                    % (route["id"], viewport["name"], len(usable), len(samples))
                )

        browser.close()

    print("\n" + render_table(results))
    print("\n* INP column is an interaction-latency PROXY (one scripted theme-toggle")
    print("  activation measured with PerformanceObserver('event')), not field INP.")

    document = {
        "kind": "frontend-performance-baseline",
        "generated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "base_url": BASE_URL,
        "user_agent": user_agent,
        "repeats": repeats,
        "viewport_set": list(VIEWPORTS),
        "routes": list(ROUTES),
        "medians": results,
        "samples": samples_raw,
        "limitations": [
            "Local Docker WordPress with no network throttling: absolute values are not",
            "production numbers and are comparable only between runs on this machine.",
            "INP is an interaction-latency proxy from one scripted activation, not field INP.",
            "CLS is the sum of non-input layout shifts (no session-window segmentation).",
            "No Lighthouse score and no performance budget were measured or derived.",
        ],
        "failures": failures,
    }

    if args.out:
        directory = os.path.dirname(os.path.abspath(args.out))
        os.makedirs(directory, exist_ok=True)
        with open(args.out, "w", encoding="utf-8") as handle:
            json.dump(document, handle, indent=2, sort_keys=False)
            handle.write("\n")
        print("\nwrote %s" % args.out)

    if failures:
        print("\n%d sample(s) did not answer 200 — the baseline is incomplete" % failures, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

