#!/usr/bin/env python3
"""READ-ONLY probe: the /lazer/ header + the proximity control.

Requirement (2026-11-05 Lazer header cleanup): the Lazer page header must match
the archive header implemented on the reference branch, and the
"Mostrar distância de mim" button must no longer be rendered, while the
location service (leisure-proximity.js helpers, the injected config, the two
REST endpoints and the geolocation code path) stays present.

This probe loads the page in real Chromium, mocks nothing but *counts* the
geolocation requests, and prints machine-readable JSON. It writes nothing: no
post, no meta, no term, no option. It refuses a production host.

Usage:
  python3 docs/evidence/2026-11-05-lazer-proximity-button-removal/probe-lazer-header-readonly.py \
      --base-url http://localhost:8080
"""

from __future__ import annotations

import argparse
import json
import sys

from playwright.sync_api import sync_playwright

PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")

# Installed before any page script: counts getCurrentPosition() calls without
# changing the result (no permission prompt, no position is produced).
GEO_COUNTER = """
(() => {
  window.__geoCalls = 0;
  if (navigator.geolocation && navigator.geolocation.getCurrentPosition) {
    const original = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
    navigator.geolocation.getCurrentPosition = function (ok, fail, options) {
      window.__geoCalls += 1;
      return original(ok, fail, options);
    };
  }
})();
"""

PROBE = """
() => {
  const header = document.querySelector('.page-header');
  const proximityNodes = Array.prototype.map.call(
    document.querySelectorAll('[data-leisure-proximity]'),
    (el) => el.getAttribute('data-leisure-proximity')
  );
  return {
    title: document.title,
    bodyClassHasLeisureLocation: document.body.classList.contains('conexao-leisure-location'),
    headerExists: !!header,
    headerHtml: header ? header.innerHTML.replace(/\\s+/g, ' ').trim() : null,
    headerChildren: header
      ? Array.prototype.map.call(header.children, (el) => el.tagName.toLowerCase() + '.' + el.className)
      : [],
    toggleCount: document.querySelectorAll('.leisure-proximity-toggle').length,
    controlCount: document.querySelectorAll("[data-leisure-proximity='leisure-proximity-control']").length,
    proximityNodes: proximityNodes,
    proximityLabel: document.querySelectorAll('.leisure-proximity-toggle').length
      ? document.querySelector('.leisure-proximity-toggle').textContent.trim()
      : null,
    distanceSpans: document.querySelectorAll('.leisure-card-distance').length,
    gridCards: document.querySelectorAll('[data-infinite-scroll] > article').length,
    filtersPresent: document.querySelectorAll('.leisure-filters, [data-leisure-filters], .directory-filters').length,
    geoCalls: window.__geoCalls,
    proximityJsLoaded: Array.prototype.some.call(
      document.querySelectorAll('script[src]'),
      (s) => s.src.indexOf('leisure-proximity.js') !== -1
    ),
    proximityHelpers: typeof window.ConexaoLeisureProximity,
    locationConfig: window.ConexaoLeisureLocation
      ? Object.keys(window.ConexaoLeisureLocation).sort()
      : null,
    reapplyHook: typeof window.ConexaoLeisureProximityReapply
  };
}
"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://localhost:8080")
    parser.add_argument("--path", default="/lazer/")
    parser.add_argument("--language", default="pt")
    args = parser.parse_args()

    for host in PRODUCTION_HOSTS:
        if host in args.base_url:
            print(f"REFUSED: production host in --base-url ({host})", file=sys.stderr)
            return 2

    url = args.base_url.rstrip("/") + args.path
    console_errors: list[str] = []
    page_errors: list[str] = []
    failed_urls: list[str] = []

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context = browser.new_context(locale="pt-BR")
        context.add_init_script(GEO_COUNTER)
        page = context.new_page()
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: page_errors.append(str(e)))
        page.on(
            "response",
            lambda r: failed_urls.append(f"{r.status} {r.url}") if r.status >= 400 else None,
        )

        response = page.goto(url, wait_until="load", timeout=45000)
        page.wait_for_timeout(2500)  # let deferred scripts + the API bootstrap run

        result = page.evaluate(PROBE)
        result["url"] = url
        result["httpStatus"] = response.status if response else None
        result["consoleErrors"] = console_errors
        result["pageErrors"] = page_errors
        result["failedResponses"] = failed_urls

        browser.close()

    print(json.dumps(result, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
