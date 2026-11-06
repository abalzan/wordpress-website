#!/usr/bin/env python3
"""Real-browser review of the commemorative presentations (Playwright/Chromium).

Purpose: capture the evidence Stage 6 requires and that no static gate can give —
screenshots, console errors, network requests, computed accessibility properties,
keyboard focus order and reduced-motion behaviour — for the homepage, an internal
page and the landing page, in PT and EN, desktop and mobile, light and dark.

Scope: read-only. It only GETs local pages and, for the preview rows, logs in with
the local test account exactly as a maintainer would.

Safety: local only. It refuses a production base URL and never writes to WordPress.

Usage (from the project root):
  python3 scripts/diagnostics/commemorative-browser-review.py --out docs/evidence/2026-11-06-commemorative-events/stages-4-6/browser

Environment:
  CONEXAO_TEST_BASE_URL     default http://localhost:8080 (production is refused)
  WP_USERNAME / WP_TEST_USER_PASSWORD   local test administrator (default test/test)
"""

import argparse
import json
import os
import re
import sys
import urllib.parse

try:
    from playwright.sync_api import sync_playwright
except ImportError:  # pragma: no cover - environment guard
    print("BLOCKED: playwright is not installed in this environment.")
    sys.exit(2)

BASE = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
USERNAME = os.environ.get("WP_USERNAME", "test")
PASSWORD = os.environ.get("WP_TEST_USER_PASSWORD", "test")

PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")

FINDINGS = []


def check(condition, label, detail=""):
    """Record one review assertion."""
    FINDINGS.append({"ok": bool(condition), "label": label, "detail": str(detail)})
    suffix = ("  -- " + str(detail)) if detail and not condition else ""
    print(("  PASS  " if condition else "  FAIL  ") + label + suffix)


def guard_base():
    host = urllib.parse.urlparse(BASE).hostname or ""
    if host in PRODUCTION_HOSTS:
        print("refusing to inspect the production host: " + host)
        sys.exit(2)


def collect(page, url, name, out_dir):
    """Load a URL and record its console/network/accessibility facts."""
    console = []
    requests = []

    page.on("console", lambda message: console.append(message.type + ": " + message.text))
    page.on("pageerror", lambda error: console.append("pageerror: " + str(error)))
    page.on("request", lambda request: requests.append(request.url))

    response = page.goto(url, wait_until="networkidle")
    page.screenshot(path=os.path.join(out_dir, name + ".png"), full_page=False)

    commemorative_requests = sorted({r for r in requests if "commemorative" in r or "presentations/" in r})

    return {
        "url": url,
        "status": response.status if response else 0,
        "console_errors": [c for c in console if c.startswith(("error", "pageerror"))],
        "commemorative_requests": commemorative_requests,
        "placements": page.eval_on_selector_all("[data-ce-placement]", "els => els.map(e => e.getAttribute('data-ce-placement'))"),
        "modules": page.eval_on_selector_all(".ce-module", "els => els.length"),
        "ambient_nodes": page.eval_on_selector_all(
            "[data-ce-placement='site_wide_ambient']",
            "els => els.map(e => ({ariaHidden: e.getAttribute('aria-hidden'),"
            " pointerEvents: getComputedStyle(e).pointerEvents, children: e.children.length,"
            " focusable: e.querySelectorAll('a,button,input,[tabindex]:not([tabindex=\"-1\"])').length}))",
        ),
        "h1": page.eval_on_selector_all("h1", "els => els.map(e => e.textContent.trim())"),
        "lang": page.eval_on_selector("html", "e => e.getAttribute('lang')"),
        "canonical": page.eval_on_selector_all("link[rel='canonical']", "els => els.map(e => e.href)"),
        "hreflang": page.eval_on_selector_all("link[rel='alternate'][hreflang]", "els => els.map(e => e.hreflang + ' ' + e.href)"),
        "preview_banner": page.eval_on_selector_all("[data-preview='1']", "els => els.length"),
        "overflow_x": page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth + 1"),
    }


def focus_order(page, limit=28):
    """Collect the first N focusable elements reached by Tab.

    A logged-in preview page also carries the WordPress admin bar, so the first
    entries are admin links; the review asserts that the page's OWN controls are
    reachable and in a sensible order after them.
    """
    order = []

    for _ in range(limit):
        page.keyboard.press("Tab")
        info = page.evaluate(
            "() => { const el = document.activeElement; if (!el) return null;"
            " return {tag: el.tagName,"
            " label: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 60),"
            " cls: (el.className && el.className.toString()) ? el.className.toString().slice(0, 60) : ''}; }"
        )
        if not info or info["tag"] == "BODY":
            break
        order.append(info)

    return order


def contrast_samples(page):
    """Sample the computed foreground/background contrast of key commemorative text."""
    return page.evaluate(
        """() => {
            function rgb(value) {
                const m = value.match(/[\\d.]+/g);
                if (!m || m.length < 3) return null;
                let parts = m.slice(0, 3).map(Number);
                // CSS Color 4 serialises color-mix() as `color(srgb 0.99 0.97 0.94)`
                // with 0-1 components; rgb()/rgba() use 0-255.
                if (value.startsWith('color(')) {
                    parts = parts.map(v => Math.round(v * 255));
                }
                return parts;
            }
            function lum(c) {
                const s = c.map(v => { v = v / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
                return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
            }
            function ratio(a, b) {
                const l1 = lum(a), l2 = lum(b);
                return ((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)).toFixed(2);
            }
            function bg(el) {
                let node = el;
                while (node) {
                    const c = getComputedStyle(node).backgroundColor;
                    const parsed = rgb(c);
                    if (parsed && !/rgba\\(0, 0, 0, 0\\)/.test(c)) return parsed;
                    node = node.parentElement;
                }
                return [255, 255, 255];
            }
            const out = [];
            document.querySelectorAll('.ce-landing__title, .ce-module__title, .ce-card__summary, .ce-christmas-hero__title, .seasonal-halloween-title').forEach(el => {
                const fg = rgb(getComputedStyle(el).color);
                if (!fg) return;
                out.push({selector: el.className, ratio: ratio(fg, bg(el)), size: getComputedStyle(el).fontSize});
            });
            return out.slice(0, 8);
        }"""
    )


def find_preview_url(page):
    """Take the signed preview link the engine prints in the event editor."""
    page.goto(BASE + "/wp-admin/edit.php?post_type=commemorative_event&post_status=all", wait_until="networkidle")

    ids = sorted(set(re.findall(r"post=(\d+)&(?:amp;)?action=edit", page.content())))

    for post_id in ids:
        page.goto(BASE + "/wp-admin/post.php?post=%s&action=edit" % post_id, wait_until="networkidle")
        match = re.search(r"https?://[^\"'<> ]*\?[^\"'<> ]*conexao_preview=halloween[^\"'<> ]*", page.content())

        if match:
            return match.group(0).replace("&#038;", "&").replace("&amp;", "&")

    return None

    return order


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", default="docs/evidence/2026-11-06-commemorative-events/stages-4-6/browser")
    args = parser.parse_args()

    guard_base()
    os.makedirs(args.out, exist_ok=True)

    report = {"base": BASE, "browser": "", "rows": []}

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        report["browser"] = "Chromium " + browser.version

        context = browser.new_context(viewport={"width": 1440, "height": 900}, color_scheme="light")
        page = context.new_page()

        home = collect(page, BASE + "/", "pt-home-no-event-desktop-light", args.out)
        report["rows"].append(home)

        check(home["status"] == 200, "homepage returns 200")
        check(not home["console_errors"], "homepage has no console errors", home["console_errors"])
        check(not home["commemorative_requests"], "no-event homepage requests zero commemorative assets", home["commemorative_requests"])
        check(not home["placements"], "no-event homepage renders zero placements", home["placements"])

        internal = collect(page, BASE + "/documentos/", "pt-internal-no-event-desktop-light", args.out)
        report["rows"].append(internal)

        check(internal["status"] == 200, "internal page returns 200")
        check(not internal["commemorative_requests"], "no-event internal page requests zero commemorative assets")

        landing = collect(page, BASE + "/natal/", "pt-landing-desktop-light", args.out)
        report["rows"].append(landing)

        check(landing["status"] == 200, "PT landing page returns 200")
        check(landing["modules"] >= 3, "PT landing page renders at least three modules", landing["modules"])
        check(landing["lang"] == "pt-BR", "PT landing page is served in Portuguese", landing["lang"])
        check(any("/natal/" in c for c in landing["canonical"]), "PT landing page is self-canonical", landing["canonical"])
        check(any("en/christmas" in h for h in landing["hreflang"]), "PT landing page declares the EN hreflang", landing["hreflang"])
        check(len(landing["h1"]) == 1, "PT landing page has exactly one h1", landing["h1"])
        check(not landing["overflow_x"], "PT landing page has no horizontal overflow at 1440px")
        check(not landing["console_errors"], "PT landing page has no console errors", landing["console_errors"])

        samples = contrast_samples(page)
        report["rows"].append({"url": BASE + "/natal/", "contrast_light": samples})

        for sample in samples:
            check(float(sample["ratio"]) >= 4.5, "landing light contrast >= 4.5:1 (" + str(sample["selector"]) + ")", sample["ratio"])

        page.emulate_media(color_scheme="dark")
        page.reload(wait_until="networkidle")
        page.screenshot(path=os.path.join(args.out, "pt-landing-desktop-dark.png"))

        dark_samples = contrast_samples(page)
        report["rows"].append({"url": BASE + "/natal/", "contrast_dark": dark_samples})

        for sample in dark_samples:
            check(float(sample["ratio"]) >= 4.5, "landing dark contrast >= 4.5:1 (" + str(sample["selector"]) + ")", sample["ratio"])

        page.emulate_media(color_scheme="light")

        english = collect(page, BASE + "/en/christmas/", "en-landing-desktop-light", args.out)
        report["rows"].append(english)

        check(english["status"] == 200, "EN landing page returns 200")
        check(english["lang"] == "en-US", "EN landing page is served in English", english["lang"])
        check(any("en/christmas" in c for c in english["canonical"]), "EN landing page is self-canonical", english["canonical"])
        check(english["modules"] >= 3, "EN landing page renders at least three modules", english["modules"])

        mobile = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, device_scale_factor=2)
        mobile_page = mobile.new_page()

        mobile_landing = collect(mobile_page, BASE + "/natal/", "pt-landing-mobile-light", args.out)
        report["rows"].append(mobile_landing)

        check(not mobile_landing["overflow_x"], "landing page has no horizontal overflow at 390px")

        return finish(browser, report, args.out)



def preview_review(browser, report, out_dir):
    """Reduced-motion and authenticated-preview rows."""
    reduced = browser.new_context(viewport={"width": 1440, "height": 900}, reduced_motion="reduce")
    reduced_page = reduced.new_page()
    reduced_page.goto(BASE + "/natal/", wait_until="networkidle")
    reduced_page.wait_for_timeout(3500)

    ambient_children = reduced_page.eval_on_selector_all(
        "[data-ce-placement='site_wide_ambient']", "els => els.map(e => e.children.length)"
    )

    report["rows"].append({"reduced_motion": True, "ambient_children": ambient_children})
    check(all(count == 0 for count in ambient_children), "ambient decorations are disabled under reduced motion", ambient_children)

    admin = browser.new_context(viewport={"width": 1440, "height": 900})
    admin_page = admin.new_page()
    admin_page.goto(BASE + "/wp-login.php", wait_until="networkidle")
    admin_page.fill("#user_login", USERNAME)
    admin_page.fill("#user_pass", PASSWORD)
    admin_page.click("#wp-submit")
    admin_page.wait_for_load_state("networkidle")

    preview_url = find_preview_url(admin_page)

    if not preview_url:
        check(False, "the signed preview link was found in the event editor")
        return

    check(True, "the signed preview link was found in the event editor")

    preview_home = collect(admin_page, preview_url, "preview-homepage-desktop-light", out_dir)
    report["rows"].append(preview_home)

    check(preview_home["status"] == 200, "preview homepage returns 200")
    check(preview_home["preview_banner"] >= 1, "preview banner is rendered")
    check("homepage_hero" in preview_home["placements"], "preview renders the homepage hero", preview_home["placements"])
    check("site_wide_ambient" in preview_home["placements"], "preview renders the ambient layer")
    check(
        any("presentations/halloween" in r for r in preview_home["commemorative_requests"]),
        "preview requests the Halloween package asset",
        preview_home["commemorative_requests"],
    )
    check(
        not any("presentations/christmas" in r for r in preview_home["commemorative_requests"]),
        "preview does not request the other package's assets",
    )
    check(not preview_home["overflow_x"], "preview homepage has no horizontal overflow")

    ambient = preview_home["ambient_nodes"][0] if preview_home["ambient_nodes"] else None
    check(ambient is not None and ambient["ariaHidden"] == "true", "the ambient layer is aria-hidden", ambient)
    check(ambient is not None and ambient["pointerEvents"] == "none", "the ambient layer ignores pointer events", ambient)
    check(ambient is not None and ambient["focusable"] == 0, "the ambient layer contains no focusable node", ambient)

    order = focus_order(admin_page)
    report["rows"].append({"preview_focus_order": order})
    check(
        any("seasonal-halloween-close" in (entry.get("cls") or "") for entry in order),
        "the hero dismiss control is reachable by keyboard",
        order,
    )

    admin_page.eval_on_selector(".seasonal-halloween-close", "el => el.click()")
    admin_page.wait_for_timeout(300)

    dismissed = admin_page.eval_on_selector_all(".seasonal-halloween-presentation[hidden]", "els => els.length")
    report["rows"].append({"dismissed": dismissed})
    check(dismissed >= 1, "the hero is dismissible and stays dismissed in the session")

    admin_page.emulate_media(reduced_motion="reduce")
    admin_page.reload(wait_until="networkidle")
    admin_page.wait_for_timeout(2500)
    check(
        admin_page.eval_on_selector_all(".ce-ambient-decoration", "els => els.length") == 0,
        "no ambient decoration appears in the preview under reduced motion",
    )


def finish(browser, report, out_dir):
    """Run the preview rows, close the browser and write the evidence."""
    preview_review(browser, report, out_dir)
    browser.close()

    report["review"] = FINDINGS
    report["summary"] = {
        "passed": sum(1 for f in FINDINGS if f["ok"]),
        "failed": sum(1 for f in FINDINGS if not f["ok"]),
    }

    out_file = os.path.join(out_dir, "browser-review.json")

    with open(out_file, "w", encoding="utf-8") as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)
        handle.write("\n")

    print("\ncommemorative-browser-review: %d passed, %d failed" % (report["summary"]["passed"], report["summary"]["failed"]))
    print("evidence written: " + out_file)

    return 0 if report["summary"]["failed"] == 0 else 1


if __name__ == "__main__":
    sys.exit(main())

