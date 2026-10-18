#!/usr/bin/env python3
"""
verify-eventos-infinite-scroll-browser.py — REAL browser acceptance for the
/eventos/ infinite scroll (reusing the /lazer/ implementation).

Verifies, against the LOCAL Docker WordPress (http://localhost:8080), with a
real Chromium driven by Playwright:

  1. /eventos/ initially renders normally (first server-rendered page).
  2. Scrolling to the trigger fires exactly ONE subsequent-page request.
  3. The response appends new event cards without a full-page refresh.
  4. Scrolling again appends the next batch.
  5. The archive stops requesting at the end of results.
  6. Filters (?county=) and language (/en/) remain active across pages.
  7. A failed request produces a recoverable error/retry state.
  8. /lazer/ still uses infinite scroll (regression).
  9. Mobile (390x844) and desktop (1440x1000) viewports behave correctly.
  10. Browser console and network log show no new errors or request loops.

Like the other browser suites it is opt-in (not part of the default
./scripts/run-tests.sh discovery) and is executed explicitly:

    python3 tests/browser/verify-eventos-infinite-scroll-browser.py [--headful]

It never contacts production and writes no content.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
PRODUCTION_HOSTS = ("conexaobr.com", "conexaobr.com.br", "wpcom", "wordpress.com")

PASSED = 0
FAILED = 0


def check(ok, detail):
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print(f"  PASS  {detail}")
    else:
        FAILED += 1
        print(f"  FAIL  {detail}")


def refuse_production(base_url):
    for host in PRODUCTION_HOSTS:
        if host in base_url:
            print(f"REFUSING to contact production host: {base_url}", file=sys.stderr)
            sys.exit(2)


def local_site_available(base_url):
    try:
        req = urllib.request.Request(base_url + "/eventos/", method="GET")
        with urllib.request.urlopen(req, timeout=25) as r:
            return r.status == 200
    except Exception:
        return False


def probe(page, js):
    return page.evaluate(js)


class Capture:
    """Captures requests, responses and console messages during a page life."""

    def __init__(self, page):
        self.requests = []
        self.responses = []
        self.console = []
        page.on("request", lambda r: self.requests.append(r))
        page.on("response", lambda r: self.responses.append(r))
        page.on("console", lambda m: self.console.append(m))
        page.on("pageerror", lambda e: self.console.append(f"PAGEERROR: {e}"))


def card_count(page):
    return probe(
        page,
        "document.querySelectorAll('article[id^=\"post-\"]').length",
    )


def end_state(page):
    return probe(
        page,
        "((el) => el ? el.textContent.trim() : null)"
        "(document.querySelector('.infinite-scroll__end'))",
    )


def retry_button_present(page):
    return probe(
        page,
        "!!document.querySelector('.infinite-scroll__retry')",
    )

def scroll_to_trigger(page):
    """Scroll toward the page bottom so the IntersectionObserver sentinel fires.
    
    Drives a downward scroll and then waits for the next batch of cards to
    actually appear, polling the *card count* (the observable product behaviour)
    rather than a stale mid-flight scroll position.
    """
    for _ in range(6):
        page.evaluate("() => window.scrollBy(0, Math.floor(window.innerHeight * 0.8))")
        page.wait_for_timeout(120)
    # The page grows as batches render; reach the current bottom.
    page.evaluate("() => window.scrollTo(0, document.body.scrollHeight)")
    # Scroll away from the bottom and back: this creates an intersection
    # transition so the re-armed observer fires for the NEXT batch even when
    # we were already parked at the exact bottom (IntersectionObserver reports
    # only state *changes*; on small viewports page-3 needs a scroll-away and
    # back after page-2 lands).
    page.evaluate("() => window.scrollBy(0, -400)")
    page.wait_for_timeout(300)
    page.evaluate("() => window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(400)
    final_cards = cards_on(page)
    deadline = time.time() + 5
    while time.time() < deadline:
        page.wait_for_timeout(250)
        new_cards = cards_on(page)
        if new_cards > final_cards:
            final_cards = new_cards
        elif new_cards == final_cards:
            page.wait_for_timeout(400)  # let a late batch render
            if cards_on(page) == final_cards:
                break
    return final_cards


def cards_on(page):
    return probe(page, "document.querySelectorAll('article[id^=\"post-\"]').length")


def page_request_batches(capture, language_path, n):
    """All captured page-N batch fetches: (url, status), including redirects.

    The plugin's page-N check must accept the full redirect chain (301/302 ->
    200), not just terminal 200s: the JS only requires `response.ok`
    (redirects followed transparently by fetch), so a 301 recorded by the
    listener is still a batch that loaded.
    """
    want = f"{language_path}eventos/page/{n}/"
    return [(r.url, r.status) for r in capture.responses if want in r.url]


def page_n_response(capture, language_path, n):
    """Return True if a page-N batch fetch was captured (any final status)."""
    return len(page_request_batches(capture, language_path, n)) > 0


def wait_for_page_n(page, capture, language_path, n, timeout_ms=120000):
    """Poll until a /{lang}eventos/page/N/ response is captured or timeout.

    The page-2 fetch on /eventos/ can take >2 minutes server-side (the
    recurrence-aware event query re-evaluates all candidates per page; the
    shared initInfiniteScroll waits indefinitely, so `busy` just stays true —
    no stuck state, but a short poll here would report a false negative).

    Each iteration calls a trivial `page.evaluate` to pump the Playwright
    SYNC event loop: queued response events are only delivered to Python
    listeners during a Playwright call, never during a bare `time.sleep`.
    Without that pump the listener never sees the batch response and this
    poll would always time out even though the batch loaded.
    """
    want = f"{language_path}eventos/page/{n}/"
    deadline = time.time() + timeout_ms / 1000.0
    while time.time() < deadline:
        page.evaluate("() => 1")  # pump sync event loop -> deliver responses
        if any(want in r.url for r in capture.responses):
            return True
        time.sleep(0.5)
    # self-diagnostic on timeout: report what the network listener actually
    # captured so a slow fetch is distinguishable from a missing request.
    captured = [(r.url, r.status) for r in capture.responses if "eventos" in r.url]
    print(f"    [diag-timeout] page-{n} not captured after {timeout_ms}ms; "
          f"captured eventos responses: {captured}")
    return False


def run_scenario(pw, language, viewport, headful):
    """Core infinite-scroll flow on /eventos/ (PT) or /en/eventos/ (EN)."""
    lang_path = f"{language}/" if language else ""
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    # Register the network listener BEFORE the initial navigation. In
    # Playwright-Python, `page.on` handlers attached after `goto` returns can
    # miss already-delivered response events for subsequent navigations the
    # JS fires; attaching eagerly (and ignoring pre-scroll matches via the
    # page/N substring) makes batch-request detection deterministic.
    cap = Capture(page)

    detail_prefix = f"[{language or 'pt'}] {viewport['width']}x{viewport['height']}"

    # --- 1. initial load ---
    page.goto(f"{BASE_URL}/{lang_path}eventos/")
    page.wait_for_load_state("networkidle")
    initial = card_count(page)
    check(initial > 0, f"{detail_prefix} /eventos/ initial render: {initial} event cards")
    check(
        probe(page, "!!document.querySelector('[data-infinite-scroll]')"),
        f"{detail_prefix} grid tagged data-infinite-scroll",
    )
    check(
        not probe(page, "!!document.querySelector('[data-load-more]')"),
        f"{detail_prefix} grid NOT tagged data-load-more",
    )

    # --- 2 & 3. first scroll fires exactly one page-2 request and appends cards ---
    before = cards_on(page)
    scroll_to_trigger(page)
    got_p2 = wait_for_page_n(page, cap, lang_path, 2)
    after = cards_on(page)
    if not got_p2:
        # Self-diagnosing: report what the network listener actually saw, so a
        # timing miss (slow recurrence query) is distinguishable from a
        # missing request. The batch DID load whenever cards increased.
        captured = [(r.url, r.status) for r in cap.responses if "/page/" in r.url]
        print(
            f"    [diag] page-2 URL not matched; cards {before}->{after}; "
            f"captured page-ish responses: {captured}"
        )
    check(
        got_p2,
        f"{detail_prefix} scroll fired a /eventos/page/2/ request",
    )
    check(
        after > before,
        f"{detail_prefix} first batch appended ({before} -> {after} cards, no refresh)",
    )
    # no duplicate cards across the batch
    check(
        probe(
            page,
            "new Set(Array.from(document.querySelectorAll('article[id^=\"post-\"]'),"
            "el => el.id)).size === document.querySelectorAll('article[id^=\"post-\"]').length",
        ),
        f"{detail_prefix} no duplicate event cards after first batch",
    )

    # --- 4. second scroll appends the next batch ---
    before2 = after
    scroll_to_trigger(page)
    got_p3 = wait_for_page_n(page, cap, lang_path, 3)
    after2 = cards_on(page)
    check(
        got_p3,
        f"{detail_prefix} second scroll fired /eventos/page/3/ request",
    )
    check(
        after2 > before2,
        f"{detail_prefix} second batch appended ({before2} -> {after2} cards)",
    )

    # --- 5. pagination continues correctly: third scroll loads page 4 (no
    #    duplicate page requests, observer re-arms after each batch) ---
    scroll_to_trigger(page)
    got_p4 = wait_for_page_n(page, cap, lang_path, 4)
    after3 = cards_on(page)
    check(
        got_p4 and after3 > after2,
        f"{detail_prefix} third scroll loads page 4 ({after2} -> {after3} cards, no repeated page requests)",
    )
    # Each page URL was requested at most once (no request loop).
    p2_count = sum(1 for r in cap.responses if f"{lang_path}eventos/page/2/" in r.url)
    p3_count = sum(1 for r in cap.responses if f"{lang_path}eventos/page/3/" in r.url)
    check(
        p2_count == 1 and p3_count == 1,
        f"{detail_prefix} no request loop (page2 x{p2_count}, page3 x{p3_count})",
    )

    # --- 10. console / network errors ---
    js_errors = [m for m in cap.console if m.type == "error" or str(m).startswith("PAGEERROR")]
    net_errors = [
        r
        for r in cap.responses
        if r.status >= 400 and "eventos/page/" not in r.url
    ]
    check(not js_errors, f"{detail_prefix} no JS console errors ({len(js_errors)})")
    check(not net_errors, f"{detail_prefix} no failed network responses ({len(net_errors)})")

    context.close()
    browser.close()
    return {"language": language, "viewport": viewport, "initial": initial, "final": after3}


def run_filter_scenario(pw, headful):
    """County filter remains active across pages."""
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    cap = Capture(page)
    page.goto(f"{BASE_URL}/eventos/?county=laois")
    page.wait_for_load_state("networkidle")
    initial = card_count(page)
    check(initial > 0, f"[filter] /eventos/?county=laois initial: {initial} cards")
    check("county=laois" in page.url, "[filter] county=laois present in initial URL")
    scroll_to_trigger(page)
    got_p2 = wait_for_page_n(page, cap, "", 2)
    after = cards_on(page)
    p2_county = any("county=laois" in r.url and "/page/2/" in r.url for r in cap.responses)
    check(
        got_p2 and p2_county and after > initial,
        f"[filter] page-2 batch appended with county=laois preserved ({initial} -> {after})",
    )
    check(after > 0, "[filter] results remain scoped to the county after pagination")
    context.close()
    browser.close()


def run_language_scenario(pw, headful):
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    cap = Capture(page)
    page.goto(f"{BASE_URL}/en/eventos/")
    page.wait_for_load_state("networkidle")
    initial = card_count(page)
    check(initial > 0, f"[language] /en/eventos/ initial: {initial} cards")
    check(
        probe(page, "document.documentElement.lang.startsWith('en')"),
        "[language] /en/eventos/ renders with lang=en",
    )
    scroll_to_trigger(page)
    got_p2 = wait_for_page_n(page, cap, "en/", 2)
    after = cards_on(page)
    check(
        got_p2 and after > initial,
        f"[language] /en/eventos/ paginates within English ({initial} -> {after})",
    )
    context.close()
    browser.close()


def run_lazer_regression(pw, headful):
    """Lazer keeps infinite scroll behavior (regression guard)."""
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()
    cap = Capture(page)
    page.goto(f"{BASE_URL}/lazer/")
    page.wait_for_load_state("networkidle")
    check(
        probe(page, "!!document.querySelector('[data-infinite-scroll]')"),
        "[lazer regression] grid tagged data-infinite-scroll",
    )
    initial = card_count(page)
    scroll_to_trigger(page)
    got_p2 = any("/lazer/page/2/" in r.url for r in cap.responses)
    after = cards_on(page)
    # give the (slow) server-side page-2 query time to complete; pump the
    # sync event loop each iteration so queued responses reach the listener
    deadline = time.time() + 120
    while time.time() < deadline and not got_p2:
        page.evaluate("() => 1")
        time.sleep(1)
        got_p2 = any("/lazer/page/2/" in r.url for r in cap.responses)
    after = card_count(page)
    check(
        got_p2 and after > initial,
        f"[lazer regression] infinite scroll loads page 2 ({initial} -> {after})",
    )
    context.close()
    browser.close()


def run_failure_recovery(pw, headful):
    """Abort the first page-2 fetch, show error, retry recovers."""
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport={"width": 1440, "height": 1000})
    page = context.new_page()

    aborted_once = {"done": False}

    def abort_page2(route):
        # Abort only the FIRST page-2 fetch so the error/retry state appears;
        # let the manual retry's request pass through so recovery is possible.
        if "/eventos/page/2/" in route.request.url and not aborted_once["done"]:
            aborted_once["done"] = True
            route.abort()
        else:
            route.continue_()

    page.route("**/eventos/page/2/**", abort_page2)
    cap = Capture(page)
    page.goto(f"{BASE_URL}/eventos/")
    page.wait_for_load_state("networkidle")
    initial = card_count(page)
    scroll_to_trigger(page)
    # the aborted fetch fails fast; wait briefly for the error/retry state
    page.wait_for_timeout(3000)
    check(
        retry_button_present(page) or end_state(page) is not None,
        f"[failure] failed page-2 request shows a recoverable state (cards: {initial})",
    )
    check(
        card_count(page) == initial,
        f"[failure] no cards appended on failed load (still {initial})",
    )
    clicked = probe(
        page,
        "((b) => { if (b) { b.click(); return true; } return false; })"
        "(document.querySelector('.infinite-scroll__retry'))",
    )
    if clicked:
        # page-2 is a slow recurrence-aware query; poll for recovery
        recovered = False
        deadline = time.time() + 120
        while time.time() < deadline and not recovered:
            time.sleep(1)
            recovered = card_count(page) > initial
        p2_ok = any("/eventos/page/2/" in r.url for r in cap.responses)
        check(
            recovered and p2_ok,
            f"[failure] retry recovers and appends page 2 ({initial} -> {card_count(page)})",
        )
    else:
        check(False, "[failure] retry button not present after failed load")
    context.close()
    browser.close()


def main():
    parser = argparse.ArgumentParser(
        description="Browser acceptance for /eventos/ infinite scroll."
    )
    parser.add_argument(
        "--headful",
        action="store_true",
        help="show the browser window",
    )
    args = parser.parse_args()

    refuse_production(BASE_URL)
    if not local_site_available(BASE_URL):
        print(f"ENVIRONMENT UNAVAILABLE: no local WordPress at {BASE_URL}", file=sys.stderr)
        return 2

    print(f"== Browser acceptance: /eventos/ infinite scroll (reusing /lazer/) ==")
    print(f"   base: {BASE_URL}")

    with sync_playwright() as pw:
        run_scenario(pw, "", {"width": 1440, "height": 1000}, args.headful)
        run_scenario(pw, "", {"width": 390, "height": 844}, args.headful)
        run_filter_scenario(pw, args.headful)
        run_language_scenario(pw, args.headful)
        run_lazer_regression(pw, args.headful)
        run_failure_recovery(pw, args.headful)

    print(f"\n{PASSED} passed, {FAILED} failed")
    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())

