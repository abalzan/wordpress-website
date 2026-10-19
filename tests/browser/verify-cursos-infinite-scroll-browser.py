#!/usr/bin/env python3
"""Browser acceptance: /cursos/ infinite scroll (part 1 of 2; appended)."""

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
CARDS_Q = 'article[id^="post-"]'


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
        req = urllib.request.Request(base_url + "/cursos/", method="GET")
        with urllib.request.urlopen(req, timeout=25) as r:
            return r.status == 200
    except Exception:
        return False


def probe(page, js):
    return page.evaluate(js)


class Capture:
    def __init__(self, page):
        self.requests = []
        self.responses = []
        self.console = []
        page.on("request", lambda r: self.requests.append(r))
        page.on("response", lambda r: self.responses.append(r))
        page.on("console", lambda m: self.console.append(m))
        page.on("pageerror", lambda e: self.console.append(f"PAGEERROR: {e}"))


def open_page(pw, headful, url, viewport):
    browser = pw.chromium.launch(headless=not headful)
    context = browser.new_context(viewport=viewport)
    page = context.new_page()
    cap = Capture(page)
    page.goto(url)
    page.wait_for_load_state("networkidle")
    return page, cap


def course_ids(page):
    return probe(page, "Array.from(document.querySelectorAll('" + CARDS_Q + "')).map((a) => a.id)")


def run_cursos_scenario(pw, viewport, headful):
    detail = f"[cursos {viewport['width']}x{viewport['height']}]"
    page, cap = open_page(pw, headful, f"{BASE_URL}/cursos/", viewport)
    ids = course_ids(page)
    check(len(ids) == 8, f"{detail} initial render: 8 course cards ({len(ids)})")
    check(
        probe(page, "!!document.querySelector('[data-infinite-scroll]')"),
        f"{detail} grid tagged data-infinite-scroll",
    )
    check(
        not probe(page, "!!document.querySelector('[data-load-more]')"),
        f"{detail} grid NOT tagged data-load-more",
    )
    check(
        not probe(page, "!!document.querySelector('.load-more__button')"),
        f"{detail} no Carregar mais button rendered (old Load More unbound)",
    )
    check(
        len(ids) == len(set(ids)) and all(i.startswith("post-") for i in ids),
        f"{detail} cards carry unique id=post-ID ({ids})",
    )
    check(
        not probe(page, "!!document.querySelector('.conexao-pagination')"),
        f"{detail} single page: no .conexao-pagination rendered",
    )
    before = len(cap.responses)
    probe(page, "window.scrollTo(0, document.body.scrollHeight)")
    page.wait_for_timeout(3000)
    page_fetches = [r for r in cap.responses[before:] if "/cursos/page/" in r.url]
    check(
        not page_fetches,
        f"{detail} scrolling fires no /cursos/page/ fetch (single-page finish)",
    )
    check(
        len(course_ids(page)) == len(ids),
        f"{detail} card set unchanged after scroll (still {len(ids)})",
    )
    js_errors = [m for m in cap.console if m.type == "error" or str(m).startswith("PAGEERROR")]
    net_errors = [r for r in cap.responses if r.status >= 400]
    check(not js_errors, f"{detail} no JS console errors ({len(js_errors)})")
    check(not net_errors, f"{detail} no failed network responses ({len(net_errors)})")
    page.context.close()
    page.context.browser.close()
def run_filter_scenario(pw, headful):
    vp = {"width": 1440, "height": 1000}
    page, cap = open_page(pw, headful, f"{BASE_URL}/cursos/?categoria=educacao", vp)
    ids = course_ids(page)
    check(
        0 < len(ids) < 8,
        f"[filter] ?categoria=educacao scopes the archive ({len(ids)} of 8: {ids})",
    )
    check("categoria=educacao" in page.url, "[filter] categoria=educacao present in URL")
    check(
        probe(page, "!!document.querySelector('[data-infinite-scroll]')"),
        "[filter] filtered grid keeps data-infinite-scroll",
    )
    check(
        not probe(page, "!!document.querySelector('[data-load-more]')"),
        "[filter] filtered grid NOT tagged data-load-more",
    )
    page.context.close()
    page.context.browser.close()


def run_language_scenario(pw, headful):
    vp = {"width": 1440, "height": 1000}
    page, cap = open_page(pw, headful, f"{BASE_URL}/en/cursos/", vp)
    ids = course_ids(page)
    check(len(ids) == 8, f"[language] /en/cursos/ renders 8 cards ({len(ids)})")
    check(
        probe(page, "document.documentElement.lang.startsWith('en')"),
        "[language] /en/cursos/ renders with lang=en",
    )
    check(
        probe(page, "!!document.querySelector('[data-infinite-scroll]')"),
        "[language] /en/cursos/ grid tagged data-infinite-scroll",
    )
    check(
        not probe(page, "!!document.querySelector('[data-load-more]')"),
        "[language] /en/cursos/ grid NOT tagged data-load-more",
    )
    page.context.close()
    page.context.browser.close()


def run_multipage_regression(pw, route, headful):
    vp = {"width": 1440, "height": 1000}
    page, cap = open_page(pw, headful, f"{BASE_URL}{route}", vp)
    initial = probe(page, "document.querySelectorAll('" + CARDS_Q + "').length")
    probe(page, "window.scrollTo(0, document.body.scrollHeight)")
    got_p2 = False
    deadline = time.time() + 90
    while time.time() < deadline and not got_p2:
        page.wait_for_timeout(1000)
        got_p2 = any(f"{route}page/2/" in r.url for r in cap.responses)
        probe(page, "window.scrollTo(0, document.body.scrollHeight)")
    after = probe(page, "document.querySelectorAll('" + CARDS_Q + "').length")
    check(
        got_p2 and after > initial,
        f"[{route.strip('/')} regression] infinite scroll loads page 2 ({initial} -> {after})",
    )
    page.context.close()
    page.context.browser.close()


def main():
    parser = argparse.ArgumentParser(
        description="Browser acceptance for /cursos/ infinite scroll."
    )
    parser.add_argument("--headful", action="store_true", help="show the browser window")
    args = parser.parse_args()
    refuse_production(BASE_URL)
    if not local_site_available(BASE_URL):
        print(f"ENVIRONMENT UNAVAILABLE: no local WordPress at {BASE_URL}", file=sys.stderr)
        return 2
    print("== Browser acceptance: /cursos/ infinite scroll (reusing /lazer/) ==")
    print(f"   base: {BASE_URL}")
    with sync_playwright() as pw:
        run_cursos_scenario(pw, {"width": 1440, "height": 1000}, args.headful)
        run_cursos_scenario(pw, {"width": 390, "height": 844}, args.headful)
        run_filter_scenario(pw, args.headful)
        run_language_scenario(pw, args.headful)
        run_multipage_regression(pw, "/eventos/", args.headful)
        run_multipage_regression(pw, "/lazer/", args.headful)
    print(f"\n{PASSED} passed, {FAILED} failed")
    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())
