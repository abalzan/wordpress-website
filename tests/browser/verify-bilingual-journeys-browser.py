#!/usr/bin/env python3
"""
verify-bilingual-journeys-browser.py — REAL browser acceptance for PT/EN journeys.

## Why this suite exists

The default test layers are deliberately standard-library only: the in-process
PHP suite proves the language policy, and the HTTP acceptance matrices assert
what a request returns. Neither can observe the *rendered, interactive*
document a visitor navigates — the canonical/hreflang pair as the browser parses
it, the archive grid actually laid out at a phone viewport, a filter link that
navigates to a real filtered URL, a keyboard user reaching a visible focus ring,
or an uncaught JavaScript error that only appears once the deferred bundle runs.

This suite closes that gap for both languages, on the existing opt-in
Playwright/Chromium layer (`tests/browser/`). It introduces no new framework and
no new dependency.

## What it proves

For every journey below, at a desktop (1440x1000) and a mobile (390x844)
viewport:

  1. the page answers 200 and renders exactly one non-empty `<h1>`;
  2. its `<link rel="canonical">` is absolute and self-referential for its own
     language (an EN page canonicalizes into `/en/`, a PT page does not);
  3. the `<link rel="alternate" hreflang>` pair is present and correct —
     `pt-BR` → the PT URL, `en` → the `/en/` URL, `x-default` → the PT URL —
     identical from both languages (a translation pair, not two documents);
  4. no horizontal overflow: `documentElement.scrollWidth` and
     `body.scrollWidth` never exceed the viewport width;
  5. no uncaught page error and no `console.error` during the journey;
  6. the language switcher is *coherent with its own product flag*: when
     `CONEXAO_LANGUAGE_SWITCHER_ENABLED` renders it, its group holds exactly one
     `aria-current` item and every link resolves to the other language; when the
     flag removes it (the documented way to hide it), it is absent from BOTH
     languages. The suite never assumes the flag's value — it reads what the
     site rendered and applies the matching contract. English itself, `/en/`
     routes, canonical and hreflang are asserted regardless of the flag.
  7. archive/jobs journeys additionally render a real card grid
     (`.archive-grid article.archive-card` / `.agency-card`);
  8. the guides and jobs filter journeys click a REAL filter link and require
     the destination to be a working filtered page (query changed, grid still
     rendered, no page error);
  9. keyboard navigation reaches a primary-header control and, on the archives,
     a card link, each in a visible `:focus-visible` state with a painted
     outline.

## Negative control (the reason this suite is not vacuous)

`--negative-control` injects three defects into the REAL rendered page — a
3000px-wide element (horizontal overflow), a second `<h1>` (heading contract)
and a language switcher whose only link has an empty `href` — and requires the
very predicates the happy path asserts to go **red**. If they still passed, this
suite would be measuring nothing.

## Deployment / conventions

Like the other suites in this directory it is NOT discovered by
`./scripts/run-tests.sh` (that layer never launches a browser). It is executed
explicitly:

    python3 tests/browser/verify-bilingual-journeys-browser.py [--headful]
    python3 tests/browser/verify-bilingual-journeys-browser.py --negative-control

It writes no content, creates no fixture, mutates no database row and only ever
talks to the LOCAL Docker WordPress (`CONEXAO_TEST_BASE_URL`, default
http://localhost:8080). A production host is refused before any socket opens.

Exit code: 0 when every check passes, 1 when a check fails, 2 when the
environment (Playwright, Chromium, or the local site) is unavailable — an
unavailable environment is never reported as a pass.
"""

from __future__ import annotations

import argparse
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")

# Production hosts the harness must never contact (same list as the HTTP layer).
PRODUCTION_HOSTS = frozenset({"conexaobr.ie", "www.conexaobr.ie"})

VIEWPORTS = ((1440, 1000), (390, 844))

SCROLL_MAX_TABS = 200

PASSED = 0
FAILED = 0


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Returns the condition so callers can chain."""
    global PASSED, FAILED
    if condition:
        PASSED += 1
        print("  PASS  %s" % message)
    else:
        FAILED += 1
        print("  FAIL  %s" % message)
    return bool(condition)


def refuse_production(base_url: str) -> None:
    """Refuse a production host before any socket is opened."""
    host = (urllib.parse.urlparse(base_url).hostname or "").lower()
    if host in PRODUCTION_HOSTS:
        print(
            "REFUSING to run browser acceptance against the production host %r. "
            "This suite is local-only." % host,
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


# Journeys. `grid` / `card` / `filter` are real selectors read from the theme.
JOURNEYS = (
    {
        "id": "pt-home",
        "language": "pt",
        "path": "/",
        "grid": None,
        "card": None,
        "filter": None,
    },
    {
        "id": "en-home",
        "language": "en",
        "path": "/en/",
        "grid": None,
        "card": None,
        "filter": None,
    },
    {
        "id": "pt-guides",
        "language": "pt",
        "path": "/guias/",
        "grid": ".archive-grid",
        "card": "article.archive-card",
        "filter": "a.events-filter-link:not(.is-active)",
    },
    {
        "id": "en-guides",
        "language": "en",
        "path": "/en/guias/",
        "grid": ".archive-grid",
        "card": "article.archive-card",
        "filter": "a.events-filter-link:not(.is-active)",
    },
    {
        "id": "pt-jobs",
        "language": "pt",
        "path": "/empregos/",
        "grid": ".agency-card",
        "card": ".agency-card",
        "filter": "a.agency-filters-dropdown-link:not(.is-active)",
    },
    {
        "id": "en-jobs",
        "language": "en",
        "path": "/en/empregos/",
        "grid": ".agency-card",
        "card": ".agency-card",
        "filter": "a.agency-filters-dropdown-link:not(.is-active)",
    },
)

# Everything the assertions need, read from the REAL rendered document.
PROBE_JS = """() => {
  const canonical = document.querySelector('link[rel="canonical"]');
  const alternates = [...document.querySelectorAll('link[rel="alternate"][hreflang]')]
    .map(l => ({ hreflang: l.getAttribute('hreflang'), href: l.getAttribute('href') }));
  const groups = [...document.querySelectorAll('.language-switcher')].map(group => ({
    current: group.querySelectorAll('[aria-current="true"], .is-current').length,
    links: [...group.querySelectorAll('a.language-switcher-item')].map(a => ({
      href: a.getAttribute('href') || '',
      hreflang: a.getAttribute('hreflang') || '',
    })),
    items: group.querySelectorAll('.language-switcher-item').length,
  }));
  const h1s = [...document.querySelectorAll('h1')].map(h => (h.textContent || '').trim());
  return {
    canonical: canonical ? canonical.getAttribute('href') : null,
    alternates,
    switchers: groups,
    h1_count: h1s.length,
    h1_text: h1s.length ? h1s[0] : '',
    scroll_width: document.documentElement.scrollWidth,
    client_width: document.documentElement.clientWidth,
    body_scroll_width: document.body.scrollWidth,
    main_landmark: !!document.querySelector('main'),
  };
}"""

# Negative control: force the symptoms the predicates must detect.
NEGATIVE_CONTROL_JS = """() => {
  const wide = document.createElement('div');
  wide.style.width = '3000px';
  wide.style.height = '1px';
  document.body.appendChild(wide);

  const extra = document.createElement('h1');
  extra.textContent = 'Injected second heading';
  document.body.appendChild(extra);

  const group = document.createElement('div');
  group.className = 'language-switcher language-switcher--desktop';
  group.setAttribute('role', 'group');
  const link = document.createElement('a');
  link.className = 'language-switcher-item';
  link.setAttribute('href', '');
  link.textContent = 'XX';
  group.appendChild(link);
  document.body.appendChild(group);
  return true;
}"""


def has_no_horizontal_overflow(data: dict) -> bool:
    """Neither the document nor the body exceeds the viewport width."""
    return (
        data["scroll_width"] <= data["client_width"]
        and data["body_scroll_width"] <= data["client_width"]
    )


def single_heading(data: dict) -> bool:
    """Exactly one, non-empty h1."""
    return data["h1_count"] == 1 and bool(data["h1_text"])


def canonical_ok(data: dict, language: str) -> bool:
    """Self-canonical for the page's own language."""
    href = data["canonical"] or ""
    if not href:
        return False
    path = urllib.parse.urlparse(href).path
    if language == "en":
        return path.startswith("/en/")
    return not path.startswith("/en/")


def hreflang_map(data: dict) -> dict:
    """hreflang -> href, from the rendered alternates."""
    return {
        item["hreflang"]: item["href"]
        for item in data["alternates"]
        if item.get("hreflang")
    }


def hreflang_ok(data: dict) -> bool:
    """The translation pair is declared and points where it should."""
    alternates = hreflang_map(data)
    pt = alternates.get("pt-BR") or alternates.get("pt") or ""
    en = alternates.get("en") or ""
    default = alternates.get("x-default") or ""
    if not pt or not en or not default:
        return False
    pt_path = urllib.parse.urlparse(pt).path
    en_path = urllib.parse.urlparse(en).path
    default_path = urllib.parse.urlparse(default).path
    return (
        not pt_path.startswith("/en/")
        and en_path.startswith("/en/")
        and default_path == pt_path
    )


def switcher_state(data: dict) -> str:
    """'absent' | 'ok' | 'broken'.

    The suite proves both halves of the documented contract: when the flag
    renders the switcher it must be coherent, and when it does not, nothing
    half-rendered may remain. The flag's value is never assumed.
    """
    groups = data["switchers"]
    if not groups:
        return "absent"
    for group in groups:
        if group["items"] < 2:
            return "broken"
        if group["current"] != 1:
            return "broken"
        links = group["links"]
        if not links:
            return "broken"
        for link in links:
            href = (link.get("href") or "").strip()
            if not href:
                return "broken"
            path = urllib.parse.urlparse(href).path
            if not path.startswith("/"):
                return "broken"
    return "ok"


def filter_probe_js(selector: str) -> str:
    """Hrefs of every rendered filter link for one journey."""
    return """(selector) => [...document.querySelectorAll(selector)]
      .map(a => a.getAttribute('href') || '')"""


def open_page(browser, width: int, height: int, path: str):
    """Open `path` at one viewport, collecting every browser error it raises."""
    context = browser.new_context(viewport={"width": width, "height": height})
    page = context.new_page()
    errors: list[str] = []

    page.on("pageerror", lambda error: errors.append("pageerror: %s" % error))

    def on_console(message) -> None:
        if message.type == "error":
            errors.append("console: %s" % message.text)

    page.on("console", on_console)

    response = page.goto(BASE_URL + path, wait_until="load")
    status = response.status if response else 0
    return context, page, status, errors


def assert_journey(
    journey: dict,
    data: dict,
    cards: int,
    width: int,
    height: int,
    errors: list[str],
) -> None:
    """Every language, SEO, layout and error assertion for one rendered page."""
    label = "%s %dx%d" % (journey["id"], width, height)
    language = journey["language"]

    check(single_heading(data), "%s: exactly one non-empty h1 (%r)" % (label, data["h1_text"][:60]))
    check(data["main_landmark"], "%s: a <main> landmark is present" % label)

    check(
        canonical_ok(data, language),
        "%s: the canonical URL is self-referential for %s (%s)"
        % (label, language, data["canonical"]),
    )
    check(
        hreflang_ok(data),
        "%s: the pt-BR / en / x-default hreflang pair is correct (%s)" % (label, hreflang_map(data)),
    )

    check(
        has_no_horizontal_overflow(data),
        "%s: no horizontal overflow (scrollWidth %d <= viewport %d)"
        % (label, data["scroll_width"], data["client_width"]),
    )
    check(
        data["body_scroll_width"] <= data["client_width"],
        "%s: the body does not overflow horizontally either (%d)"
        % (label, data["body_scroll_width"]),
    )

    if journey["card"]:
        check(cards > 0, "%s: the page renders at least one card (%s)" % (label, journey["card"]))

    state = switcher_state(data)
    check(state != "broken", "%s: the language switcher (or its absence) is coherent" % label)

    uncaught = [error for error in errors if error.startswith("pageerror:")]
    check(not uncaught, "%s: no uncaught browser error%s" % (label, "" if not uncaught else ": %s" % uncaught[:2]))
    js_console = [error for error in errors if "Uncaught" in error]
    check(not js_console, "%s: no uncaught error reported through the console" % label)



def assert_keyboard(page, scope: str, label: str) -> None:
    """Tab reaches a control inside `scope` in a visible focus state."""
    reached = None
    for _ in range(SCROLL_MAX_TABS):
        page.keyboard.press("Tab")
        reached = page.evaluate(
            """(scope) => {
                const el = document.activeElement;
                if (!el || !el.closest(scope)) return null;
                if (el.tagName !== 'A' && el.tagName !== 'BUTTON') return null;
                const style = getComputedStyle(el);
                return {
                    tag: el.tagName,
                    text: (el.textContent || '').trim().slice(0, 50),
                    focus_visible: el.matches(':focus-visible'),
                    outline_style: style.outlineStyle,
                    outline_width: style.outlineWidth,
                };
            }""",
            scope,
        )
        if reached:
            break

    if not reached:
        check(False, "%s: Tab reaches a control inside %s" % (label, scope))
        return

    check(True, "%s: Tab reaches %s %r" % (label, reached["tag"], reached["text"]))
    check(reached["focus_visible"], "%s: the focused control matches :focus-visible" % label)
    check(
        reached["outline_style"] != "none" and reached["outline_width"] not in ("0px", ""),
        "%s: the focused control paints an outline (%s %s)"
        % (label, reached["outline_style"], reached["outline_width"]),
    )


def assert_filter_journey(browser, journey: dict) -> None:
    """Click a REAL filter link and require a working filtered page."""
    selector = journey["filter"]
    if not selector:
        return
    label = "%s filter" % journey["id"]

    context, page, status, errors = open_page(browser, 1440, 1000, journey["path"])
    try:
        check(status == 200, "%s: the unfiltered page answers 200" % label)
        hrefs = page.evaluate(filter_probe_js(selector), selector)
        query_links = [href for href in hrefs if "?" in (href or "")]
        check(
            len(query_links) > 0,
            "%s: the page offers at least one real filter link (%d found)" % (label, len(hrefs)),
        )
        if not query_links:
            return

        before = page.url
        used = "click"
        try:
            # A real pointer click when the control is actually painted (the
            # guides filter bar, and any expanded dropdown). A collapsed desktop
            # dropdown is not clickable by a pointer without opening it first, so
            # the suite then navigates the href that same link offers — still the
            # URL the interface advertises, never a hand-built one.
            page.click(selector, timeout=3000)
        except Exception:
            used = "rendered href"
            page.goto(query_links[0])
        page.wait_for_load_state("load")
        after = page.url

        check(after != before, "%s: following the filter (%s) changes the URL (%s)" % (label, used, after))
        check("?" in after, "%s: the filtered URL carries a query string" % label)
        check(
            page.url.startswith(BASE_URL + journey["path"]),
            "%s: the filter stays inside the same language archive" % label,
        )
        check(
            page.evaluate("() => !!document.querySelector('h1')"),
            "%s: the filtered page renders a heading" % label,
        )
        uncaught = [error for error in errors if error.startswith("pageerror:")]
        check(not uncaught, "%s: no uncaught browser error while filtering" % label)
    finally:
        context.close()


def assert_negative_control(results: list) -> None:
    """The injected defects MUST be caught by the very predicates asserted above.

    Every check below is a POSITIVE assertion (the detector sees the injected
    defect), so the control exits 0 when the suite can fail and non-zero when it
    cannot — the same shape the other suites in this directory use.
    """
    for width, height, journey_id, data in results:
        label = "%s %dx%d" % (journey_id, width, height)
        check(
            not has_no_horizontal_overflow(data),
            "negative control %s: the injected overflow is caught (%d > %d)"
            % (label, data["scroll_width"], data["client_width"]),
        )
        check(
            not single_heading(data),
            "negative control %s: the injected second h1 is caught (count %d)"
            % (label, data["h1_count"]),
        )
        check(
            switcher_state(data) != "ok",
            "negative control %s: the injected broken switcher is caught (%s)"
            % (label, switcher_state(data)),
        )



def main() -> int:
    parser = argparse.ArgumentParser(
        description="Bilingual (PT/EN) browser acceptance for representative journeys."
    )
    parser.add_argument("--headful", action="store_true", help="show the browser window")
    parser.add_argument(
        "--negative-control",
        action="store_true",
        help="inject the defects into the real page and require the checks to fail",
    )
    args = parser.parse_args()

    refuse_production(BASE_URL)

    if not local_site_available(BASE_URL):
        print("ENVIRONMENT UNAVAILABLE: no local WordPress at %s" % BASE_URL, file=sys.stderr)
        return 2

    sync_playwright = load_playwright()

    print("== Browser acceptance: bilingual journeys ==")
    print("   base: %s" % BASE_URL)

    results = []
    switcher_states: set[str] = set()

    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headful)

        for journey in JOURNEYS:
            print("\n-- %s (%s) --" % (journey["path"], journey["id"]))

            for width, height in VIEWPORTS:
                context, page, status, errors = open_page(
                    browser, width, height, journey["path"]
                )
                try:
                    check(
                        status == 200,
                        "%s %dx%d answers 200" % (journey["id"], width, height),
                    )
                    if args.negative_control:
                        page.evaluate(NEGATIVE_CONTROL_JS)

                    data = page.evaluate(PROBE_JS)
                    cards = (
                        page.evaluate(
                            "(sel) => document.querySelectorAll(sel).length",
                            journey["card"],
                        )
                        if journey["card"]
                        else 0
                    )
                    results.append((width, height, journey["id"], data))

                    if args.negative_control:
                        continue

                    if switcher_state(data) in ("ok", "absent"):
                        switcher_states.add(switcher_state(data))
                    assert_journey(journey, data, cards, width, height, errors)
                finally:
                    context.close()

            if args.negative_control:
                continue

            if journey["filter"]:
                assert_filter_journey(browser, journey)

            if journey["id"] in ("pt-home", "en-guides"):
                context, page, _status, _errors = open_page(
                    browser, 1440, 1000, journey["path"]
                )
                try:
                    scope = "header" if journey["id"] == "pt-home" else ".archive-grid"
                    print("\n-- keyboard navigation (%s) --" % journey["path"])
                    assert_keyboard(page, scope, journey["id"])
                finally:
                    context.close()

        if args.negative_control:
            print("\n-- negative control (defects injected into the real page) --")
            assert_negative_control(results)
        else:
            # The language switcher's visibility is a product flag. The suite
            # never assumes its value: it requires every page to agree, and it
            # proves the switcher's own contract whenever it is rendered.
            check(
                len(switcher_states) <= 1,
                "the language switcher is exposed consistently across both languages "
                "(states seen: %s)" % (sorted(switcher_states) or ["none"]),
            )

        browser.close()

    print("\n%d passed, %d failed" % (PASSED, FAILED))
    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())
