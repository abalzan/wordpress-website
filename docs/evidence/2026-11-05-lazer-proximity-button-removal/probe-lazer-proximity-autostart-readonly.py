#!/usr/bin/env python3
"""READ-ONLY probe: automatic Lazer proximity (no button) with mocked geolocation.

Proves the post-cleanup contract:
  * the archive header renders only the shared archive-header nodes;
  * no button / control / explanation exists anywhere;
  * the location service starts on its own (exactly ONE geolocation request);
  * with a granted permission the list is re-ordered nearest-first, distances
    are rendered, and an independent Haversine recomputation agrees;
  * with a denied permission ONE friendly note appears ABOVE the results grid
    (never inside the header), the server order and the cards are untouched;
  * proximity notes are never injected into .page-header.

Writes nothing: GET-only traffic against the local stack. Refuses production.

Usage:
  python3 docs/evidence/2026-11-05-lazer-proximity-button-removal/probe-lazer-proximity-autostart-readonly.py \
      --base-url http://localhost:8080
"""

from __future__ import annotations

import argparse
import json
import math
import sys
import urllib.request

from playwright.sync_api import sync_playwright

PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")
DUBLIN = {"latitude": 53.3498, "longitude": -6.2603}

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
  const grid = document.querySelector('[data-infinite-scroll]');
  const note = document.querySelector("[data-leisure-proximity='leisure-proximity-note']");
  const error = document.querySelector("[data-leisure-proximity='leisure-proximity-error']");
  const cards = grid ? Array.prototype.slice.call(grid.children) : [];
  return {
    headerChildren: header
      ? Array.prototype.map.call(header.children, (el) => el.tagName.toLowerCase() + '.' + el.className)
      : null,
    headerHasProximityNode: header ? header.querySelectorAll('[data-leisure-proximity]').length : null,
    toggleCount: document.querySelectorAll('.leisure-proximity-toggle').length,
    controlCount: document.querySelectorAll("[data-leisure-proximity='leisure-proximity-control']").length,
    explanationCount: document.querySelectorAll("[data-leisure-proximity='leisure-proximity-explanation']").length,
    noteText: note ? note.textContent.trim() : null,
    noteAboveGrid: !!(note && grid && note.nextElementSibling === grid),
    errorText: error ? error.textContent.trim() : null,
    errorAboveGrid: !!(error && grid && error.nextElementSibling === grid),
    cardIds: cards.map((c) => c.id).filter(Boolean),
    distances: Array.prototype.map.call(
      document.querySelectorAll('.leisure-card-distance'),
      (el) => [el.getAttribute('data-leisure-distance'), el.textContent.trim()]
    ),
    geoCalls: window.__geoCalls,
    proximityHelpers: typeof window.ConexaoLeisureProximity
  };
}
"""

def haversine_m(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
    """Independent great-circle distance in metres."""
    radius = 6371000.0
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    d_phi = phi2 - phi1
    d_lambda = math.radians(lng2 - lng1)
    a = math.sin(d_phi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(d_lambda / 2) ** 2
    return 2 * radius * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def expected_order(base_url: str):
    """The documented global order: geolocated ascending by distance (ties by
    the original archive index), then attractions without coordinates in their
    original relative order."""
    url = base_url.rstrip("/") + "/wp-json/conexao/v1/leisure-coordinates"
    request = urllib.request.Request(url, headers={"X-Requested-With": "conexao-leisure-proximity"})
    payload = json.load(urllib.request.urlopen(request, timeout=30))
    items = payload.get("items", [])

    geolocated, missing = [], []
    for item in items:
        if item.get("lat") is None or item.get("lng") is None:
            missing.append(item)
            continue
        metres = haversine_m(DUBLIN["latitude"], DUBLIN["longitude"], item["lat"], item["lng"])
        geolocated.append((metres, item.get("index", 0), item["id"]))

    geolocated.sort(key=lambda row: (row[0], row[1]))
    order = [row[2] for row in geolocated]
    order += [item["id"] for item in sorted(missing, key=lambda i: i.get("index", 0))]
    distances = {row[2]: row[0] for row in geolocated}
    return payload.get("count"), order, distances


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
    count, order, distances = expected_order(args.base_url)
    results: dict = {}
    failures: list[str] = []

    def check(condition: bool, message: str) -> None:
        print(("  PASS  " if condition else "  FAIL  ") + message)
        if not condition:
            failures.append(message)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)

        # --- 1. GRANTED: the service must order + render on its own ---
        print("-- 1. geolocation GRANTED (simulated Dublin position) --")
        granted = browser.new_context(locale="pt-BR")
        granted.add_init_script(GEO_COUNTER)
        granted.grant_permissions(["geolocation"], origin=args.base_url)
        granted.set_geolocation(DUBLIN)
        page = granted.new_page()
        console_errors: list[str] = []
        page_errors: list[str] = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: page_errors.append(str(e)))
        response = page.goto(url, wait_until="load", timeout=45000)
        try:
            page.wait_for_selector("[data-leisure-proximity='leisure-proximity-note']", timeout=25000)
        except Exception:  # noqa: BLE001 - reported as a failed assertion below
            pass
        page.wait_for_timeout(1200)
        granted_state = page.evaluate(PROBE)
        granted.close()
        granted_state["httpStatus"] = response.status if response else None
        granted_state["consoleErrors"] = console_errors
        granted_state["pageErrors"] = page_errors
        granted_state["apiCount"] = count
        results["granted"] = granted_state

        shared_header = ["span.section-eyebrow", "h1.page-title", "p.page-description"]
        check(granted_state["httpStatus"] == 200, f"the page loads (status {granted_state['httpStatus']})")
        check(granted_state["headerChildren"] == shared_header,
              f"the header is exactly the shared archive header (got {granted_state['headerChildren']})")
        check(granted_state["headerHasProximityNode"] == 0, "nothing is injected into .page-header")
        check(granted_state["toggleCount"] == 0, "the 'Mostrar distância de mim' button is absent")
        check(granted_state["controlCount"] == 0, "no proximity control wrapper exists")
        check(granted_state["explanationCount"] == 0, "no explanation paragraph is rendered")
        check(granted_state["geoCalls"] == 1, f"exactly ONE geolocation request is made (got {granted_state['geoCalls']})")
        check(granted_state["noteText"] == "Ordenado por proximidade",
              f"the ordered note is shown (got {granted_state['noteText']!r})")
        check(granted_state["noteAboveGrid"], "the note sits directly above the results grid")
        check(len(granted_state["distances"]) >= 10, f"distances are rendered ({len(granted_state['distances'])} spans)")

        rendered_ids = [int(card.split("-", 1)[1]) for card in granted_state["cardIds"] if card.startswith("post-")]
        check(rendered_ids == order[: len(rendered_ids)],
              f"the first {len(rendered_ids)} cards are the independently computed nearest-first order")

        labels = [text for _, text in granted_state["distances"]]
        check(bool(labels) and all(label.endswith("de você") for label in labels),
              f"every rendered label is a '<distance> de você' string ({labels[:3]})")
        geolocated_rendered = [i for i in rendered_ids if i in distances]
        check(len(granted_state["distances"]) == len(geolocated_rendered),
              f"every geolocated rendered card shows a distance ({len(granted_state['distances'])}/{len(geolocated_rendered)})")
        check(not granted_state["pageErrors"], f"no JS page errors ({granted_state['pageErrors'][:2]})")

        # --- 2. DENIED: one friendly note above the grid, nothing else moves ---
        print("-- 2. geolocation DENIED (headless default) --")
        denied = browser.new_context(locale="pt-BR")
        denied.add_init_script(GEO_COUNTER)
        denied.clear_permissions()
        page2 = denied.new_page()
        denied_console: list[str] = []
        denied_errors: list[str] = []
        page2.on("console", lambda m: denied_console.append(m.text) if m.type == "error" else None)
        page2.on("pageerror", lambda e: denied_errors.append(str(e)))
        response2 = page2.goto(url, wait_until="load", timeout=45000)
        try:
            page2.wait_for_selector("[data-leisure-proximity='leisure-proximity-error']", timeout=25000)
        except Exception:  # noqa: BLE001 - reported as a failed assertion below
            pass
        page2.wait_for_timeout(800)
        denied_state = page2.evaluate(PROBE)
        denied.close()
        denied_state["httpStatus"] = response2.status if response2 else None
        denied_state["consoleErrors"] = denied_console
        denied_state["pageErrors"] = denied_errors
        results["denied"] = denied_state

        check(denied_state["httpStatus"] == 200, f"the page still loads (status {denied_state['httpStatus']})")
        check(denied_state["headerChildren"] == shared_header,
              f"the header is unchanged on denial (got {denied_state['headerChildren']})")
        check(denied_state["headerHasProximityNode"] == 0, "nothing is injected into .page-header on denial")
        check(denied_state["geoCalls"] == 1, f"exactly one attempt is made (got {denied_state['geoCalls']})")
        check(denied_state["errorText"] is not None, "ONE friendly failure note is shown")
        check(denied_state["errorAboveGrid"], "the failure note sits directly above the results grid")
        check(len(denied_state["cardIds"]) >= 10,
              f"the server-rendered cards are untouched ({len(denied_state['cardIds'])}) ")
        check(not denied_state["distances"], f"no distances are shown (got {len(denied_state['distances'])})")
        check(not denied_state["pageErrors"], f"no JS page errors on denial ({denied_state['pageErrors'][:2]})")

        browser.close()

    print()
    print(json.dumps({"failures": failures, "granted": results.get("granted"), "denied": results.get("denied")},
                     indent=2, ensure_ascii=False)[:6000])
    print()
    print(f"{'ALL CHECKS PASSED' if not failures else 'CHECKS FAILED'} ({len(failures)} failed)")
    return 0 if not failures else 1


if __name__ == "__main__":
    raise SystemExit(main())
