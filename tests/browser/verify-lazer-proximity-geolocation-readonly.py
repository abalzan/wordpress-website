#!/usr/bin/env python3
"""Browser acceptance (READ-ONLY): Lazer proximity with SIMULATED geolocation.

Answers one question end to end in a real browser (Chromium via Playwright):
does browser geolocation successfully provide coordinates to the /lazer/
front end, and does the feature calculate, display and sort distances
correctly?

Design constraints (deliberate, see the task brief):

  * READ-ONLY. Unlike tests/browser/verify-lazer-proximity-browser.py this
    script NEVER seeds, mutates or deletes a post. It uses the existing
    published leisure records and only reads them through the public REST
    endpoints and the rendered archive. Running it leaves the database
    byte-for-byte unchanged.
  * SIMULATED location only. The visitor position is Portlaoise, Co. Laois
    (53.0344, -7.2994), supplied through Playwright's context geolocation.
    The real user's location is never requested.
  * INDEPENDENT maths. The expected nearest-first order and every expected
    distance string are recomputed in Python (Haversine + the documented
    display table). The front end's own value is never used as the expected
    result.

Scenarios (all against the canonical http://localhost:8080 — the obsolete
`--connect 127.0.0.1:8081` workaround is not needed and not used):

  1. geolocation GRANTED   -> the control is present and inert until clicked;
                              a click yields the simulated position, the grid
                              is re-ordered nearest-first, distances appear and
                              match the independent calculation, and no
                              JavaScript error is raised;
  2. geolocation DENIED    -> the page is unchanged, one friendly failure
                              message is shown, the control stays as a retry;
  3. POSITION_UNAVAILABLE  -> same graceful handling (mocked code 2);
  4. TIMEOUT               -> same graceful handling (mocked code 3);
  5. API UNSUPPORTED       -> no control is offered at all, no request made.

Run from the repository root (the local stack must be up):

  python3.14 tests/browser/verify-lazer-proximity-geolocation-readonly.py \\
      --base-url http://localhost:8080

Exit codes: 0 pass, 1 assertion failure, 2 environment unavailable (the local
site is not reachable). A 2 is never reported as a pass.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import urllib.request
from decimal import Decimal, ROUND_HALF_UP

from playwright.sync_api import sync_playwright

PRODUCTION_HOSTS = ("conexaobr.ie", "conexaobr.com", "wpcom", "wordpress.com")

DEFAULT_BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080")

# Simulated visitor position: Portlaoise, Co. Laois, Ireland.
VISITOR = {"latitude": 53.0344, "longitude": -7.2994}

# Local image assets that 404 in this Docker stack are expected and ignored.
IGNORABLE_ASSET_MARKER = "/wp-content/uploads/"

PASSED = 0
FAILED = 0

CONTROL_SELECTOR = "[data-leisure-proximity='leisure-proximity-control'] button"
NOTE_SELECTOR = "[data-leisure-proximity='leisure-proximity-note']"
EXPLANATION_SELECTOR = "[data-leisure-proximity='leisure-proximity-explanation']"
ERROR_SELECTOR = "[data-leisure-proximity='leisure-proximity-error']"
GRID_SELECTOR = "[data-infinite-scroll] > article"


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
        request = urllib.request.Request(base_url + "/lazer/", method="GET")
        with urllib.request.urlopen(request, timeout=25) as response:
            return response.status == 200
    except Exception:
        return False


def fetch_coordinates(base_url):
    """Read the coordinate dataset over HTTP exactly as the front end does."""
    url = base_url + "/wp-json/conexao/v1/leisure-coordinates?lang=pt"
    request = urllib.request.Request(url, headers={"X-Requested-With": "conexao-leisure-proximity"})
    with urllib.request.urlopen(request, timeout=25) as response:
        payload = json.loads(response.read().decode("utf-8"))
    return payload.get("items") if isinstance(payload, dict) else None


def haversine_meters(lat1, lng1, lat2, lng2):
    """Great-circle distance in metres — an INDEPENDENT re-implementation of
    the Haversine formula the browser feature uses."""
    radius = 6371000.0
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lng2 - lng1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * radius * math.asin(math.sqrt(min(1.0, max(0.0, a))))


def format_distance_value(meters):
    """Independent mirror of formatDistanceValue() in leisure-proximity.js for
    pt-BR (comma decimal separator, period thousands separator).

    The approved display table: metres below 1 km (nearest 10), one decimal
    from 1 to 10 km, whole kilometres from 10 km on.
    """
    rounded = int((Decimal(str(meters)) / 10).quantize(Decimal("1"), rounding=ROUND_HALF_UP)) * 10
    if rounded < 1000:
        return "%s m" % f"{rounded:,}".replace(",", ".")

    kilometres = Decimal(rounded) / 1000
    digits = 1 if kilometres < 10 else 0
    step = Decimal("0.1") if digits else Decimal("1")
    value = kilometres.quantize(step, rounding=ROUND_HALF_UP)
    grouped = f"{value:,.{digits}f}"
    # pt-BR: thousands '.' and decimal ','.
    grouped = grouped.replace(",", "\u0000").replace(".", ",").replace("\u0000", ".")
    return grouped + " km"


def parse_distance_text(text):
    """Parse a rendered distance label ('6,1 km de você' / '850 m de você')
    into (value, unit) or None."""
    core = text.replace(" de você", "").strip()
    if core.endswith(" km"):
        return float(core[:-3].replace(".", "").replace(",", ".")), "km"
    if core.endswith(" m"):
        return float(core[:-2].replace(".", "")), "m"
    return None


def expected_proximity_order(items, lat, lng):
    """Independently compute the expected global nearest-first order (nearest
    first, ties by original archive index, records without coordinates last in
    their original order) and the expected distance in metres per ID."""
    with_coords = []
    without_coords = []
    distances = {}
    for item in items:
        index = item.get("index")
        if not isinstance(index, (int, float)):
            index = 0
        if item.get("lat") is None or item.get("lng") is None:
            without_coords.append((index, item["id"]))
        else:
            metres = haversine_meters(lat, lng, item["lat"], item["lng"])
            distances[item["id"]] = metres
            with_coords.append((metres, index, item["id"]))

    with_coords.sort(key=lambda row: (row[0], row[1]))
    without_coords.sort()
    order = [row[2] for row in with_coords] + [row[1] for row in without_coords]
    return order, distances


class Diagnostics:
    """Collect browser console errors, page exceptions and failed requests and
    split them into the two categories the brief requires: expected/ignorable
    local image 404s, and everything else (relevant)."""

    def __init__(self):
        self.page_errors = []
        self.console_errors = []   # (text, url)
        self.bad_responses = []    # (status, url)
        self.failed_requests = []  # (url, reason)

    def attach(self, page):
        page.on("pageerror", lambda e: self.page_errors.append(str(e)))
        page.on("console", self._on_console)
        page.on("response", self._on_response)
        page.on("requestfailed", self._on_request_failed)

    def _on_console(self, msg):
        if msg.type == "error":
            location = msg.location or {}
            self.console_errors.append((msg.text, location.get("url", "")))

    def _on_response(self, response):
        if response.status >= 400:
            self.bad_responses.append((response.status, response.url))

    def _on_request_failed(self, request):
        self.failed_requests.append((request.url, request.failure or ""))

    @staticmethod
    def _ignorable(url, text=""):
        return IGNORABLE_ASSET_MARKER in (url or "") or IGNORABLE_ASSET_MARKER in (text or "")

    def relevant_console_errors(self):
        return [t for (t, u) in self.console_errors if not self._ignorable(u, t)]

    def relevant_bad_responses(self):
        return [(s, u) for (s, u) in self.bad_responses if not self._ignorable(u)]

    def relevant_failed_requests(self):
        return [(u, r) for (u, r) in self.failed_requests if not self._ignorable(u)]

    def ignorable_count(self):
        n = 0
        n += sum(1 for (t, u) in self.console_errors if self._ignorable(u, t))
        n += sum(1 for (s, u) in self.bad_responses if self._ignorable(u))
        n += sum(1 for (u, r) in self.failed_requests if self._ignorable(u))
        return n

    def report(self):
        print("  diagnostics:")
        print(f"    page exceptions        : {len(self.page_errors)}")
        print(f"    relevant console errors: {self.relevant_console_errors()}")
        print(f"    relevant bad responses : {self.relevant_bad_responses()}")
        print(f"    relevant failed reqs   : {self.relevant_failed_requests()}")
        print(f"    ignorable (uploads 404): {self.ignorable_count()}")

    def clean(self):
        return (
            not self.page_errors
            and not self.relevant_console_errors()
            and not self.relevant_bad_responses()
            and not self.relevant_failed_requests()
        )


def launch(browser_type):
    """Launch Chromium through the repository's existing Playwright setup.

    The obsolete `--connect 127.0.0.1:8081` host-resolver workaround is not
    needed here: the canonical http://localhost:8080 serves the site directly.
    """
    return browser_type.launch(headless=True)


def install_geo_counter(page, mock_code=None):
    """Count every getCurrentPosition() call so 'nothing is requested until the
    visitor acts' is provable rather than assumed.

    When `mock_code` is given (2 POSITION_UNAVAILABLE / 3 TIMEOUT) the wrapper
    also invokes the error callback with that code, so the failure branch is
    exercised without waiting for a real timeout. Installing the counter and the
    mock in ONE init script guarantees the mock is the function that is counted.
    """
    if mock_code is None:
        page.add_init_script(
            "window.__geoRequests = 0;"
            "var _g = navigator.geolocation && navigator.geolocation.getCurrentPosition;"
            "if (_g) {"
            "  navigator.geolocation.getCurrentPosition = function () {"
            "    window.__geoRequests++;"
            "    return _g.apply(navigator.geolocation, arguments);"
            "  };"
            "}"
        )
        return

    page.add_init_script(
        "window.__geoRequests = 0;"
        "var _g = navigator.geolocation && navigator.geolocation.getCurrentPosition;"
        "if (_g) {"
        "  navigator.geolocation.getCurrentPosition = function (ok, err) {"
        "    window.__geoRequests++;"
        f"    setTimeout(function () {{ err({{ code: {int(mock_code)}, message: 'mocked' }}); }}, 50);"
        "  };"
        "}"
    )


def geo_request_count(page):
    return page.evaluate("() => window.__geoRequests || 0")


def grid_ids(page):
    return page.eval_on_selector_all(GRID_SELECTOR, "els => els.map(e => e.id)")


def distance_spans(page):
    """Return [(cardId, text)] for every rendered distance label, keyed by the
    card it belongs to (data-leisure-distance carries the attraction ID)."""
    return page.eval_on_selector_all(
        ".leisure-card-distance",
        "els => els.map(e => [e.getAttribute('data-leisure-distance'), e.textContent.trim()])",
    )


def load_archive(page, base_url):
    """Navigate to /lazer/ and return the navigation response."""
    return page.goto(f"{base_url}/lazer/", wait_until="domcontentloaded")


def assert_page_contract(page, totals):
    """Page + proximity JavaScript + coordinates endpoint are all healthy."""
    check(page.evaluate("() => document.readyState") in ("interactive", "complete"),
          "the /lazer/ page is loaded")

    script_srcs = page.eval_on_selector_all("script[src]", "els => els.map(e => e.src)")
    check(any("leisure-proximity.js" in src for src in script_srcs),
          "the proximity JavaScript (leisure-proximity.js) is loaded")

    js_ready = page.evaluate(
        "() => !!(window.ConexaoLeisureProximity"
        " && typeof window.ConexaoLeisureProximity.haversineMeters === 'function'"
        " && typeof window.ConexaoLeisureProximity.sortByProximity === 'function')"
    )
    check(js_ready, "window.ConexaoLeisureProximity exposes the proximity helpers")

    check(page.evaluate("() => document.body.classList.contains('conexao-leisure-location')"),
          "the archive body carries the leisure-location scope class")

    config_ok = page.evaluate(
        "() => !!(window.ConexaoLeisureLocation"
        " && typeof window.ConexaoLeisureLocation.coordinatesUrl === 'string'"
        " && typeof window.ConexaoLeisureLocation.cardsUrl === 'string')"
    )
    check(config_ok, "window.ConexaoLeisureLocation carries coordinatesUrl + cardsUrl")

    # The coordinates REST endpoint, read from inside the browser exactly as the
    # feature does (same-origin fetch).
    endpoint = page.evaluate(
        """async () => {
            const r = await fetch(window.ConexaoLeisureLocation.coordinatesUrl + '?lang=pt', {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'conexao-leisure-proximity' }
            });
            const j = await r.json();
            const items = Array.isArray(j.items) ? j.items : [];
            return {
                status: r.status,
                total: items.length,
                withCoords: items.filter(i => i.lat !== null && i.lng !== null).length,
                keys: items.length ? Object.keys(items[0]).sort() : []
            };
        }"""
    )
    check(endpoint["status"] == 200, f"the coordinates endpoint returns HTTP 200 (got {endpoint['status']})")
    check(endpoint["total"] == totals["total"],
          f"the endpoint returns {totals['total']} records (got {endpoint['total']})")
    check(endpoint["withCoords"] == totals["with_coords"],
          f"{totals['with_coords']} records carry a valid coordinate pair (got {endpoint['withCoords']})")
    check(endpoint["keys"] == ["id", "index", "lat", "lng"],
          f"each record carries id/index/lat/lng (got {endpoint['keys']})")


def scenario_granted(browser, base_url, expected_order, expected_distances, totals):
    """1. Geolocation granted, simulated Portlaoise position."""
    print("\n-- 1. geolocation GRANTED (simulated: Portlaoise 53.0344, -7.2994) --")
    context = browser.new_context(geolocation=VISITOR, permissions=["geolocation"])
    page = context.new_page()
    diag = Diagnostics()
    diag.attach(page)
    install_geo_counter(page)

    response = load_archive(page, base_url)
    page.wait_for_timeout(300)
    check(response is not None and response.status == 200,
          f"navigating to {base_url}/lazer/ returns HTTP 200")

    assert_page_contract(page, totals)

    control_name = page.eval_on_selector_all(CONTROL_SELECTOR, "els => els.map(e => e.textContent.trim())")
    check(control_name == ["Mostrar distância de mim"],
          f"the opt-in control is offered with its accessible name (got {control_name})")
    check(page.eval_on_selector_all(EXPLANATION_SELECTOR, "els => els.length") == 1,
          "the pre-permission explanation is shown before any request")

    # Nothing is requested until the visitor explicitly opts in.
    check(geo_request_count(page) == 0,
          "no geolocation request is made automatically on page load")

    # Explicit opt-in.
    page.click(CONTROL_SELECTOR)
    page.wait_for_selector(NOTE_SELECTOR, timeout=20000)
    page.wait_for_timeout(700)

    check(geo_request_count(page) == 1,
          f"clicking the control issues exactly one geolocation request (got {geo_request_count(page)})")

    note = page.eval_on_selector_all(NOTE_SELECTOR, "els => els.map(e => e.textContent.trim())")
    check(note == ["Ordenado por proximidade"],
          f"the 'ordered by proximity' note appears (got {note})")
    check(page.eval_on_selector_all(CONTROL_SELECTOR, "els => els.length") == 0,
          "the opt-in control is removed once proximity is active")
    check(page.eval_on_selector_all(EXPLANATION_SELECTOR, "els => els.length") == 0,
          "the explanation is removed once proximity is active")
    check(page.eval_on_selector_all(ERROR_SELECTOR, "els => els.length") == 0,
          "no failure message is shown when geolocation succeeds")

    rendered_ids = grid_ids(page)
    page_size = len(rendered_ids)
    check(page_size >= 1, f"the proximity grid rendered {page_size} card(s)")

    # --- Independent ordering check -------------------------------------
    expected_first = ["post-" + str(i) for i in expected_order[:page_size]]
    check(rendered_ids == expected_first,
          f"the visible cards match the independent nearest-first order\n"
          f"          expected {expected_first}\n"
          f"          got      {rendered_ids}")

    check(rendered_ids and rendered_ids[0] == "post-" + str(expected_order[0]),
          f"the nearest attraction is the independently computed nearest (post-{expected_order[0]})")

    # --- Distance display checks ----------------------------------------
    spans = distance_spans(page)
    spans_by_id = {cid: text for cid, text in spans}
    geolocated_visible = [rid for rid in rendered_ids if int(rid.split("-", 1)[1]) in expected_distances]
    check(len(spans) == len(geolocated_visible),
          f"every geolocated visible card shows a distance ({len(spans)} spans / {len(geolocated_visible)} cards)")

    parsed_values = []
    exact = 0
    within_rounding = 0
    for cid, text in spans:
        attraction_id = int(cid)
        parsed = parse_distance_text(text)
        if parsed is None:
            check(False, f"distance label '{text}' on post-{attraction_id} is parseable")
            continue
        value, unit = parsed
        check(math.isfinite(value) and value >= 0,
              f"distance on post-{attraction_id} is finite and non-negative ('{text}')")

        independent = format_distance_value(expected_distances[attraction_id])
        expected_text = independent + " de você"
        if text == expected_text:
            exact += 1
            within_rounding += 1
        else:
            # Allow a one-step rounding boundary flip (float vs Decimal).
            independent_value, independent_unit = parse_distance_text(expected_text)
            step = 10.0 if unit == "m" else (0.1 if value < 10 else 1.0)
            same_unit = unit == independent_unit
            within_rounding += 1 if same_unit and abs(value - independent_value) <= step else 0
        if unit == "km":
            parsed_values.append(value * 1000.0)
        else:
            parsed_values.append(value)

    check(within_rounding == len(spans),
          f"every displayed distance matches the independent calculation "
          f"({exact}/{len(spans)} exact, {within_rounding}/{len(spans)} within rounding)")

    if spans:
        nearest_id = int(rendered_ids[0].split("-", 1)[1])
        nearest_expected = format_distance_value(expected_distances[nearest_id]) + " de você"
        check(spans_by_id.get(str(nearest_id)) == nearest_expected,
              f"the nearest attraction's distance matches the independent value "
              f"(expected '{nearest_expected}', got '{spans_by_id.get(str(nearest_id))}')")

    check(all(parsed_values[i] <= parsed_values[i + 1] for i in range(len(parsed_values) - 1)),
          f"visible distances are sorted nearest-first: {[round(v) for v in parsed_values]}")

    diag.report()
    check(diag.clean(), "no relevant JavaScript errors or network failures")

    context.close()
    return diag


def scenario_failure(browser, base_url, label, mock_code):
    """2/3/4. Denied, unavailable or timeout: the page must be unchanged, ONE
    friendly message shown, and the control must stay available as a retry.

    mock_code=None exercises a real permission denial (Chromium returns code 1
    when geolocation is not granted); mock_code=2/3 exercises POSITION_UNAVAILABLE
    and TIMEOUT without waiting for a real timeout.
    """
    print(f"\n-- {label} --")
    if mock_code is None:
        context = browser.new_context(geolocation=VISITOR, permissions=[])
    else:
        context = browser.new_context(geolocation=VISITOR, permissions=["geolocation"])

    page = context.new_page()
    diag = Diagnostics()
    diag.attach(page)
    install_geo_counter(page, mock_code=mock_code)

    response = load_archive(page, base_url)
    page.wait_for_timeout(300)
    check(response is not None and response.status == 200, "the page still loads")

    control_name = page.eval_on_selector_all(CONTROL_SELECTOR, "els => els.map(e => e.textContent.trim())")
    check(control_name == ["Mostrar distância de mim"],
          f"the control is offered because the API exists (got {control_name})")

    default_ids = grid_ids(page)

    page.click(CONTROL_SELECTOR)
    page.wait_for_selector(ERROR_SELECTOR, timeout=20000)
    page.wait_for_timeout(400)

    check(geo_request_count(page) == 1,
          f"exactly one geolocation request is attempted (got {geo_request_count(page)})")
    check(page.eval_on_selector_all(ERROR_SELECTOR, "els => els.length") == 1,
          "exactly one friendly failure message is shown")
    message = page.eval_on_selector(ERROR_SELECTOR, "el => el.textContent.trim()")
    check("localiza" in message.lower(),
          f"the message is a meaningful location-unavailable state ('{message}')")
    check(page.eval_on_selector_all(NOTE_SELECTOR, "els => els.length") == 0,
          "no 'ordered by proximity' note on failure")
    check(distance_spans(page) == [], "no distances are shown on failure")
    check(grid_ids(page) == default_ids,
          "the default server order is preserved (no fake location substituted)")
    check(page.eval_on_selector_all(EXPLANATION_SELECTOR, "els => els.length") == 0,
          "the explanation is replaced by the failure message")

    retry = page.eval_on_selector_all(CONTROL_SELECTOR, "els => els.map(e => e.textContent.trim())")
    check(retry == ["Tentar novamente"],
          f"the control stays available as a retry (got {retry})")

    # The retry must issue a fresh request and must never stack messages.
    page.click(CONTROL_SELECTOR)
    page.wait_for_timeout(1500)
    check(geo_request_count(page) == 2, "the retry issues a second geolocation request")
    check(page.eval_on_selector_all(ERROR_SELECTOR, "els => els.length") == 1,
          "exactly one failure message after a retry (messages never stack)")

    diag.report()
    check(not diag.page_errors, f"no JavaScript exception: {diag.page_errors}")
    check(not diag.relevant_bad_responses(),
          f"no relevant failed REST request: {diag.relevant_bad_responses()}")
    check(not diag.relevant_console_errors(),
          f"no relevant console error: {diag.relevant_console_errors()}")

    context.close()
    return diag


def main():
    parser = argparse.ArgumentParser(
        description="Read-only browser acceptance for the /lazer/ proximity feature."
    )
    parser.add_argument(
        "--base-url",
        default=DEFAULT_BASE_URL,
        help="Local site base URL (default: http://localhost:8080; env CONEXAO_TEST_BASE_URL).",
    )
    args = parser.parse_args()

    base_url = args.base_url.rstrip("/")
    refuse_production(base_url)

    if not local_site_available(base_url):
        print(f"local site not reachable at {base_url} — start the stack first", file=sys.stderr)
        sys.exit(2)

    print("== Lazer proximity — read-only browser acceptance (simulated geolocation) ==")
    print(f"   base url        : {base_url}")
    print(f"   simulated visitor: Portlaoise {VISITOR['latitude']}, {VISITOR['longitude']}")

    # Independent ground truth, read over plain HTTP (no browser, no seeding).
    items = fetch_coordinates(base_url)
    if not items:
        print("coordinates endpoint returned no items — cannot verify", file=sys.stderr)
        sys.exit(2)

    totals = {
        "total": len(items),
        "with_coords": sum(1 for i in items if i.get("lat") is not None and i.get("lng") is not None),
        "without_coords": sum(1 for i in items if i.get("lat") is None or i.get("lng") is None),
    }
    expected_order, expected_distances = expected_proximity_order(
        items, VISITOR["latitude"], VISITOR["longitude"]
    )

    print(f"   dataset          : {totals['total']} records, "
          f"{totals['with_coords']} with coordinates, "
          f"{totals['without_coords']} without")

    # Independent structural expectation: coordinate-less records sort last.
    no_coord_ids = sorted(i["id"] for i in items if i.get("lat") is None or i.get("lng") is None)
    check(expected_order[-len(no_coord_ids):] == no_coord_ids if no_coord_ids else True,
          f"coordinate-less records are ordered last by the independent calculation ({no_coord_ids})")
    check(len(expected_distances) == totals["with_coords"],
          "every record with coordinates has an independent distance")

    with sync_playwright() as playwright:
        browser = launch(playwright.chromium)
        try:
            scenario_granted(browser, base_url, expected_order, expected_distances, totals)
            scenario_failure(browser, base_url, "2. geolocation DENIED", mock_code=None)
            scenario_failure(browser, base_url, "3. geolocation POSITION_UNAVAILABLE (code 2)", mock_code=2)
            scenario_failure(browser, base_url, "4. geolocation TIMEOUT (code 3)", mock_code=3)
            scenario_unsupported(browser, base_url)
        finally:
            browser.close()

    print(f"\n{PASSED} passed, {FAILED} failed")
    sys.exit(1 if FAILED else 0)


def scenario_unsupported(browser, base_url):
    """5. The Geolocation API is absent: no control is offered, nothing is
    requested, and the page is left exactly as it was."""
    print("\n-- 5. geolocation API UNSUPPORTED --")
    context = browser.new_context()
    # `delete navigator.geolocation` is a no-op (the property lives on
    # Navigator.prototype), so remove it with an own-property getter.
    context.add_init_script(
        "Object.defineProperty(navigator, 'geolocation',"
        " { get: function () { return undefined; }, configurable: true });"
    )
    page = context.new_page()
    diag = Diagnostics()
    diag.attach(page)

    response = load_archive(page, base_url)
    page.wait_for_timeout(400)
    check(response is not None and response.status == 200, "the page still loads")

    check(page.eval_on_selector_all(CONTROL_SELECTOR, "els => els.length") == 0,
          "no opt-in control is offered when the browser cannot geolocate")
    check(page.eval_on_selector_all(EXPLANATION_SELECTOR, "els => els.length") == 0,
          "the explanation is removed when the API is unavailable")
    check(distance_spans(page) == [], "no distances are shown when unsupported")

    diag.report()
    check(diag.clean(), "no relevant JavaScript errors or network failures when unsupported")

    context.close()
    return diag


if __name__ == "__main__":
    main()
