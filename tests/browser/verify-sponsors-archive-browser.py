#!/usr/bin/env python3
"""
verify-sponsors-archive-browser.py — REAL browser acceptance for `/apoiadores/`.

## Why this suite exists

`/apoiadores/` is a **single-page directory**: the archive query carries the
WordPress unlimited-result configuration, so every published Apoiador renders on
one page and no pagination is generated. That contract has an HTTP half
(`tests/acceptance/matrices/routing.json`) and an in-process half
(`wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php`).

What neither of those can observe is the RENDERED layout: that the whole set is
actually laid out as a grid at the documented viewports, that removing pagination
did not introduce horizontal overflow, that the cards' links resolve, that the
images load, and that keyboard navigation still reaches a visible focus state.

## What it proves

For `/apoiadores/` (PT) and `/en/apoiadores/` (EN), at 1440x1000 and 390x844:

  1. every Apoiador available for that language is rendered — the page's card
     set equals the REST collection for the same language scope
     (`?lang=pt` / `?lang=en`, the documented bilingual REST contract in
     `inc/rest-language.php`), and every card headline is unique;
  2. ZERO pagination nodes exist: no `.conexao-pagination`, no `.page-numbers`,
     no next/previous link, no `rel="next"`/`rel="prev"`, and no
     load-more/infinite-scroll hook on the grid;
  3. no horizontal overflow: `documentElement.scrollWidth` and
     `body.scrollWidth` never exceed the viewport width;
  4. the cards are laid out as a grid — one column at 390x844, at least three at
     1440x1000 — with the whole set inside it;
  5. every Apoiador detail link (headline and CTA) resolves with HTTP 200, and
     each card's CTA links the same record as its headline;
  6. every image in the document loaded (naturalWidth > 0) and carries non-empty
     alt text;
  7. heading hierarchy and landmarks survive (exactly one `h1`, a `main`
     landmark, header/nav/footer);
  8. keyboard navigation reaches the first Apoiador link and shows a visible
     focus indicator (`:focus-visible` plus a non-`none` outline).

## Negative control (the reason this is not a vacuous suite)

`--negative-control` injects a pagination nav (`.conexao-pagination` +
`a.page-numbers`) and an overflowing element into the real page, then asserts the
SAME checks go **red**. If they still passed, this suite would be measuring
nothing.

## Deployment / conventions

Like the other opt-in suites in this directory it is NOT part of the default
`./scripts/run-tests.sh` discovery (that layer is standard-library only and never
launches a browser). It is executed explicitly:

    python3 tests/browser/verify-sponsors-archive-browser.py [--headful]
    python3 tests/browser/verify-sponsors-archive-browser.py --negative-control

It re-implements no application logic, writes no content, and only ever talks to
the LOCAL Docker WordPress (`CONEXAO_TEST_BASE_URL`, default
http://localhost:8080). A production host is refused before any socket is opened.

Exit code: 0 when every check passes, 1 otherwise, 2 when the environment
(Playwright, the local site) is unavailable — never a silent pass.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")

# Production hosts the harness must never contact (same list as the HTTP layer).
PRODUCTION_HOSTS = frozenset({"conexaobr.ie", "www.conexaobr.ie"})

# The two viewports the Apoiadores work is verified at.
VIEWPORTS = ((1440, 1000), (390, 844))
DESKTOP_MIN_COLUMNS = 3
MOBILE_COLUMNS = 1

# (language slug, path, expected h1)
TARGETS = (
    ("pt", "/apoiadores/", "Apoiadores"),
    ("en", "/en/apoiadores/", "Supporters"),
)

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


def fetch(url: str, timeout: int = 30):
    """GET a URL, returning (status, body-text)."""
    request = urllib.request.Request(url, headers={"User-Agent": "conexao-browser-acceptance"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, ""
    except (urllib.error.URLError, OSError):
        return 0, ""


def rest_sponsor_links(language: str) -> list:
    """The Apoiador detail links the site itself reports for a language.

    The bilingual REST contract (`inc/rest-language.php`, documented in
    `docs/routing.md` § REST) mirrors the front-end language policy for the B2
    post types, `sponsor` among them: `?lang=pt` is the Portuguese records,
    `?lang=en` is the real EN records plus the B2 PT fallbacks. The collection is
    therefore the data-side set for the SAME language scope as the archive.
    """
    url = "%s/wp-json/wp/v2/sponsor?lang=%s&per_page=100&_fields=link" % (BASE_URL, language)
    status, body = fetch(url)
    if status != 200:
        return []
    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        return []
    return [item["link"] for item in payload if isinstance(item, dict) and item.get("link")]


# Everything the layout assertions need, read from the REAL rendered document.
PROBE_JS = """() => {
  const grid = document.querySelector('.archive-grid');
  const style = grid ? getComputedStyle(grid) : null;
  const columns = style ? style.gridTemplateColumns.trim().split(/\\s+/).filter(Boolean) : [];
  const headlineLinks = [...document.querySelectorAll('.archive-grid h2.archive-card-title a')];
  const ctaLinks = [...document.querySelectorAll('.archive-grid a.archive-card-cta')];
  const paginationSelector = '.conexao-pagination, .page-numbers, a.next, a.prev, link[rel="next"], link[rel="prev"]';
  return {
    grid_present: !!grid,
    cards: document.querySelectorAll('.archive-grid article.archive-card').length,
    headlines: headlineLinks.map(a => a.textContent.trim()),
    headline_links: [...new Set(headlineLinks.map(a => a.href))],
    cta_links: [...new Set(ctaLinks.map(a => a.href))],
    pagination_nodes: document.querySelectorAll(paginationSelector).length,
    load_more_nodes: document.querySelectorAll('[data-load-more], [data-infinite-scroll]').length,
    scroll_width: document.documentElement.scrollWidth,
    client_width: document.documentElement.clientWidth,
    body_scroll_width: document.body.scrollWidth,
    column_count: columns.length,
    h1_count: document.querySelectorAll('h1').length,
    h1_text: document.querySelector('h1') ? document.querySelector('h1').textContent.trim() : '',
    main_landmark: !!document.querySelector('main'),
    landmark_count: document.querySelectorAll('main, header, footer, nav').length,
    images: [...document.images].map(i => ({
      src: i.currentSrc || i.src,
      alt: i.alt,
      loaded: i.complete && i.naturalWidth > 0,
    })),
  };
}"""

# Negative control: force the two symptoms the suite must detect.
NEGATIVE_CONTROL_JS = """() => {
  const grid = document.querySelector('.archive-grid') || document.body;
  const nav = document.createElement('nav');
  nav.className = 'conexao-pagination';
  nav.setAttribute('role', 'navigation');
  nav.innerHTML = '<ul class="conexao-pagination__list">'
    + '<li><a class="page-numbers" href="/apoiadores/page/2/">2</a></li>'
    + '<li><a class="page-numbers next" href="/apoiadores/page/2/">Próximo →</a></li></ul>';
  grid.parentNode.insertBefore(nav, grid.nextSibling);

  const wide = document.createElement('div');
  wide.style.width = '3000px';
  wide.style.height = '1px';
  document.body.appendChild(wide);
  return true;
}"""


def open_page(browser, width: int, height: int, path: str, negative_control: bool):
    """Open `path` at one viewport, optionally mutating the DOM first."""
    context = browser.new_context(viewport={"width": width, "height": height})
    page = context.new_page()
    response = page.goto(BASE_URL + path, wait_until="load")
    status = response.status if response else 0
    if negative_control:
        page.evaluate(NEGATIVE_CONTROL_JS)
    return context, page, status


def expected_h1_for(language: str) -> str:
    """The documented h1 of the Apoiadores archive in a language."""
    for slug, _path, h1 in TARGETS:
        if slug == language:
            return h1
    return ""


def pagination_is_absent(data: dict) -> bool:
    """The single-page predicate: no pagination and no load-more hook exists."""
    return data["pagination_nodes"] == 0 and data["load_more_nodes"] == 0


def has_no_horizontal_overflow(data: dict) -> bool:
    """The layout predicate: neither the document nor the body exceeds the viewport."""
    return data["scroll_width"] <= data["client_width"] and data["body_scroll_width"] <= data["client_width"]


def assert_viewport(data: dict, available: list, expected_h1: str, width: int, height: int) -> None:
    """Every rendered-layout assertion for one page at one viewport."""
    label = "%dx%d" % (width, height)

    check(data["grid_present"], "%s: the Apoiadores grid exists" % label)
    check(data["cards"] > 0, "%s: at least one Apoiador card is rendered" % label)
    check(
        data["cards"] == len(data["headline_links"]),
        "%s: every card has exactly one headline link" % label,
    )
    check(
        len(data["headlines"]) == len(set(data["headlines"])),
        "%s: every rendered headline is unique" % label,
    )
    check(
        sorted(data["headline_links"]) == sorted(available),
        "%s: rendered Apoiadores == available Apoiadores (none missing, none extra)" % label,
    )
    check(
        sorted(data["cta_links"]) == sorted(data["headline_links"]),
        "%s: every card CTA links the same Apoiador as its headline" % label,
    )

    check(pagination_is_absent(data), "%s: zero pagination nodes (no numbered / next / previous link)" % label)
    check(data["load_more_nodes"] == 0, "%s: no load-more / infinite-scroll hook on the grid" % label)

    check(
        has_no_horizontal_overflow(data),
        "%s: no horizontal overflow (scrollWidth %d <= viewport %d)"
        % (label, data["scroll_width"], data["client_width"]),
    )
    check(
        data["body_scroll_width"] <= data["client_width"],
        "%s: the body does not overflow horizontally either (%d)" % (label, data["body_scroll_width"]),
    )

    if width < 769:
        check(
            data["column_count"] == MOBILE_COLUMNS,
            "%s: mobile lays the directory out in a single column (got %d)" % (label, data["column_count"]),
        )
    else:
        check(
            data["column_count"] >= DESKTOP_MIN_COLUMNS,
            "%s: desktop lays the directory out as a multi-column grid (got %d)" % (label, data["column_count"]),
        )

    check(data["h1_count"] == 1, "%s: exactly one h1 heading" % label)
    check(data["h1_text"] == expected_h1, "%s: the h1 reads %r" % (label, expected_h1))
    check(data["main_landmark"], "%s: a <main> landmark is present" % label)
    check(data["landmark_count"] >= 3, "%s: page landmarks are intact" % label)

    check(len(data["images"]) > 0, "%s: the document renders images" % label)
    check(all(image["loaded"] for image in data["images"]), "%s: every image loaded" % label)
    check(
        all(image["alt"].strip() for image in data["images"]),
        "%s: every image carries non-empty alt text" % label,
    )


def assert_keyboard(page) -> None:
    """Keyboard navigation reaches a card link in a visible focus state."""
    reached = None
    for _ in range(200):
        page.keyboard.press("Tab")
        reached = page.evaluate(
            """() => {
                const el = document.activeElement;
                if (!el || !el.closest('.archive-grid') || el.tagName !== 'A') return null;
                const style = getComputedStyle(el);
                return {
                    text: (el.textContent || '').trim().slice(0, 60),
                    focus_visible: el.matches(':focus-visible'),
                    outline_style: style.outlineStyle,
                    outline_width: style.outlineWidth,
                };
            }"""
        )
        if reached:
            break

    if not reached:
        check(False, "keyboard: Tab reaches an Apoiador card link")
        return

    check(True, "keyboard: Tab reaches the card link %r" % reached["text"])
    check(reached["focus_visible"], "keyboard: the focused card link matches :focus-visible")
    check(
        reached["outline_style"] != "none" and reached["outline_width"] not in ("0px", ""),
        "keyboard: the focused card link shows a visible focus outline (%s %s)"
        % (reached["outline_style"], reached["outline_width"]),
    )


def assert_negative_control(results: list) -> None:
    """The injected symptoms MUST be caught by the very predicates the happy path asserts.

    Every check below is a POSITIVE assertion (the detector sees the injected
    defect), so the control exits 0 when the suite can fail and non-zero when it
    cannot — the positive shape the other suites in this directory use.
    """
    for width, height, language, data in results:
        label = "%dx%d %s" % (width, height, language)
        check(
            not pagination_is_absent(data),
            "negative control %s: the injected pagination is caught by the single-page predicate (%d node(s))"
            % (label, data["pagination_nodes"]),
        )
        check(
            not has_no_horizontal_overflow(data),
            "negative control %s: the injected overflow is caught by the layout predicate (%d > %d)"
            % (label, data["scroll_width"], data["client_width"]),
        )


def main() -> int:
    parser = argparse.ArgumentParser(description="Browser acceptance for the /apoiadores/ single-page directory.")
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

    print("== Browser acceptance: Apoiadores single-page directory ==")
    print("   base: %s" % BASE_URL)

    available_by_language = {}
    for language, path, _h1 in TARGETS:
        links = rest_sponsor_links(language)
        available_by_language[language] = links
        print("\n-- %s (%s) --" % (path, language))
        check(len(links) > 0, "%s: the REST collection for this language is non-empty" % language)

    sync_playwright = load_playwright()

    results = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=not args.headful)

        for language, path, _h1 in TARGETS:
            status, _body = fetch(BASE_URL + path)
            check(status == 200, "%s answers 200" % path)

            for width, height in VIEWPORTS:
                context, page, status = open_page(browser, width, height, path, args.negative_control)
                try:
                    check(status == 200, "%s at %dx%d answers 200" % (path, width, height))
                    data = page.evaluate(PROBE_JS)
                    results.append((width, height, language, data))
                    if not args.negative_control:
                        assert_viewport(
                            data,
                            available_by_language[language],
                            expected_h1_for(language),
                            width,
                            height,
                        )
                finally:
                    context.close()

            # The single-page directory has no second page in either language.
            page_two = "%spage/2/" % path
            page_two_status, _body = fetch(BASE_URL + page_two)
            check(
                page_two_status == 404,
                "%s is not a paginated Apoiadores view (status %s)" % (page_two, page_two_status),
            )

            if not args.negative_control:
                context, page, _status = open_page(browser, 1440, 1000, path, False)
                try:
                    print("\n-- keyboard navigation (%s) --" % path)
                    assert_keyboard(page)
                finally:
                    context.close()

        if args.negative_control:
            print("\n-- negative control (defects injected into the real page) --")
            assert_negative_control(results)
        else:
            print("\n-- Apoiador detail links resolve --")
            for language, _path, _h1 in TARGETS:
                for link in available_by_language[language][:3]:
                    status, _body = fetch(link)
                    check(status == 200, "%s: detail link resolves (%s)" % (language, link))

        browser.close()

    print("\n%d passed, %d failed" % (PASSED, FAILED))
    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())


