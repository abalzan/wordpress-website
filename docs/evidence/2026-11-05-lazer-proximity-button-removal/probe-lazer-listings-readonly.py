#!/usr/bin/env python3
"""READ-ONLY probe: /lazer/ listings + infinite scroll after the header cleanup.

Proves the two things the header cleanup must not disturb:
  * the server-rendered leisure cards still render, and
  * the shared infinite-scroll enhancement still appends the next batch.

Writes nothing (no post, meta, term or option). Refuses a production host.

Usage:
  python3 docs/evidence/2026-11-05-lazer-proximity-button-removal/probe-lazer-listings-readonly.py \
      --base-url http://localhost:8080
"""

from __future__ import annotations

import argparse
import json
import sys

from playwright.sync_api import sync_playwright

PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")

PROBE = """
() => ({
  cards: document.querySelectorAll('[data-infinite-scroll] > article').length,
  gridTag: (document.querySelector('[data-infinite-scroll]') || {}).className || null,
  toggle: document.querySelectorAll('.leisure-proximity-toggle').length,
  proximityNodes: document.querySelectorAll('[data-leisure-proximity]').length,
  filterBar: document.querySelectorAll('.leisure-filters, [data-leisure-filters]').length,
  statusText: (document.querySelector('.infinite-scroll__status') || {}).textContent || ''
})
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://localhost:8080")
    parser.add_argument("--path", default="/lazer/")
    args = parser.parse_args()

    for host in PRODUCTION_HOSTS:
        if host in args.base_url:
            print(f"REFUSED: production host in --base-url ({host})", file=sys.stderr)
            return 2

    url = args.base_url.rstrip("/") + args.path
    page_errors: list[str] = []

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(locale="pt-BR")
        page = context.new_page()
        page.on("pageerror", lambda e: page_errors.append(str(e)))

        response = page.goto(url, wait_until="load", timeout=45000)
        page.wait_for_timeout(1500)
        before = page.evaluate(PROBE)

        for _ in range(3):
            page.mouse.wheel(0, 20000)
            page.wait_for_timeout(1800)

        after = page.evaluate(PROBE)
        browser.close()

    result = {
        "url": url,
        "httpStatus": response.status if response else None,
        "beforeScroll": before,
        "afterScroll": after,
        "cardsGrew": after["cards"] > before["cards"],
        "pageErrors": page_errors,
    }
    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0 if (after["cards"] > before["cards"] and not page_errors) else 1


if __name__ == "__main__":
    raise SystemExit(main())
