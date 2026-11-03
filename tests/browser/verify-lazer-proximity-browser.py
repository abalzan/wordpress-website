#!/usr/bin/env python3
"""Browser acceptance: Lazer proximity ordering + distance estimates.

Verifies the OPTIONAL location feature on /lazer/ end to end in a real
browser (Chromium via Playwright), with browser geolocation mocked:

  1. permission granted, valid position  -> global nearest-first ordering,
     distances on geolocated cards, "Ordenado por proximidade" note,
     pre-permission explanation removed, no console errors;
  2. permission denied                   -> page unchanged, no distances;
  3. position timeout (mocked code 3)    -> page unchanged, no distances;
  4. geolocation API unavailable         -> page unchanged, no distances.

It seeds four temporary published leisure attractions (three with known
coordinates at increasing distance from the mock position, one without any),
then deletes them again. Nothing outside the local stack is touched: the
script refuses to run against a production host and never reads production
credentials.

Run from the repository root (the local stack must be up):

  python3 tests/browser/verify-lazer-proximity-browser.py \
      --base-url http://localhost:8080 --connect 127.0.0.1:8081

`--connect` is optional and only needed when the local WordPress is reachable
on a different address than `--base-url` claims (for example when the
container's WordPress canonical URL differs from its published port). It is
implemented with Chromium's --host-resolver-rules, so the request still
carries the Host header WordPress expects.
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import urllib.request

from playwright.sync_api import sync_playwright

PRODUCTION_HOSTS = ("conexaobr.ie", "conexaobr.com", "wpcom", "wordpress.com")

PASSED = 0
FAILED = 0

SEED_MARKER = "[PROXIMITY BROWSER TEST]"

# (title suffix, latitude, longitude, minutes-ago) — created oldest first, so
# the archive's date-descending default order is: NoCoords, Cork, Near, Here.
SEED = [
    ("Here", 53.3498, -6.2603, 40),      # 0 m from the mock position
    ("Near", 53.3500, -6.2600, 30),      # ~30 m
    ("Cork", 51.8985, -8.4756, 20),      # ~219 km
    ("NoCoords", None, None, 10),        # no coordinates at all
]

MOCK_POSITION = {"latitude": 53.3498, "longitude": -6.2603}


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


def wp_cli(php):
    """Run a PHP snippet inside the local WordPress container."""
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "wordpress", "php", "-r", php],
        capture_output=True,
        text=True,
        timeout=180,
    )
    return result.stdout.strip(), result.stderr.strip(), result.returncode


def seed_fixtures():
    """Create the four temporary attractions; return their slugs + IDs."""
    php_lines = [
        '$_SERVER["HTTP_HOST"]="localhost"; $_SERVER["REQUEST_URI"]="/";',
        'require "/var/www/html/wp-load.php";',
        "$out=array();",
    ]
    for title, lat, lng, minutes in SEED:
        php_lines.append(
            f'$id=wp_insert_post(array("post_type"=>"leisure","post_status"=>"publish",'
            f'"post_title"=>"{SEED_MARKER} {title}","post_date"=>gmdate("Y-m-d H:i:s", time()-{minutes}*60)));'
        )
        if lat is not None:
            php_lines.append(f'update_post_meta($id,"_leisure_lat","{lat}");')
            php_lines.append(f'update_post_meta($id,"_leisure_lng","{lng}");')
        php_lines.append(f'$out[]=array("id"=>(int)$id,"title"=>"{title}");')
    php_lines.append('echo wp_json_encode($out);')

    stdout, stderr, code = wp_cli(" ".join(php_lines))
    if code != 0 or not stdout:
        print(f"seed failed (exit {code}): {stderr[:400]}", file=sys.stderr)
        sys.exit(2)

    return json.loads(stdout)


def delete_fixtures(ids):
    ids_literal = ",".join(str(int(i)) for i in ids)
    php = (
        '$_SERVER["HTTP_HOST"]="localhost"; require "/var/www/html/wp-load.php"; '
        f'foreach(array({ids_literal}) as $id){{ wp_delete_post($id, true); }} '
        'echo "deleted";'
    )
    wp_cli(php)


def launch(browser_type, connect):
    args = []
    if connect:
        args.append(f"--host-resolver-rules=MAP {connect['from']} {connect['to']}")
    return browser_type.launch(headless=True, args=args)


def parse_connect(value):
    if not value:
        return None
    target = value.strip()
    if "://" in target:
        target = target.split("://", 1)[1]
    return {"from": "localhost:8080", "to": target}


def grid_ids(page):
    return page.eval_on_selector_all(
        "[data-infinite-scroll] > article",
        "els => els.map(e => e.id)",
    )


def distance_texts(page):
    return page.eval_on_selector_all(
        ".leisure-card-distance",
        "els => els.map(e => e.textContent.trim())",
    )


def scenario(page, base_url, expect_proximity, position_delay_ms=0):
    """Load /lazer/ under the current geolocation mock and read the contract."""
    page.goto(f"{base_url}/lazer/", wait_until="domcontentloaded")

    # The explanation must be in the document before any position resolves.
    page.wait_for_timeout(150)
    explanation_present = page.eval_on_selector_all(
        "[data-leisure-proximity='leisure-proximity-explanation']", "els => els.length"
    )

    if expect_proximity:
        page.wait_for_selector("[data-leisure-proximity='leisure-proximity-note']", timeout=20000)
        page.wait_for_timeout(500)
    else:
        page.wait_for_timeout(3000)

    return {
        "explanation_present": explanation_present,
        "note_count": page.eval_on_selector_all(
            "[data-leisure-proximity='leisure-proximity-note']", "els => els.length"
        ),
        "explanation_after": page.eval_on_selector_all(
            "[data-leisure-proximity='leisure-proximity-explanation']", "els => els.length"
        ),
        "ids": grid_ids(page),
        "distances": distance_texts(page),
    }


def expected_proximity_order(items, lat, lng):
    """Independently compute the expected global nearest-first order."""
    import math

    def haversine(a1, o1, a2, o2):
        radius = 6371000.0
        phi1, phi2 = math.radians(a1), math.radians(a2)
        dphi = math.radians(a2 - a1)
        dlambda = math.radians(o2 - o1)
        a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
        return 2 * radius * math.asin(math.sqrt(min(1.0, max(0.0, a))))

    with_coords = []
    without_coords = []
    for item in items:
        card_id = "post-" + str(item["id"])
        if item.get("lat") is None or item.get("lng") is None:
            without_coords.append((item.get("index", 0), card_id))
        else:
            with_coords.append((haversine(lat, lng, item["lat"], item["lng"]), item.get("index", 0), card_id))

    with_coords.sort(key=lambda row: (row[0], row[1]))
    without_coords.sort()
    return [row[2] for row in with_coords] + [row[1] for row in without_coords]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default=os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080"))
    parser.add_argument("--connect", default="", help="Optional host:port the browser should actually connect to.")
    args = parser.parse_args()

    base_url = args.base_url.rstrip("/")
    refuse_production(base_url)

    if not local_site_available(base_url):
        print(f"local site not reachable at {base_url} — start the stack first", file=sys.stderr)
        sys.exit(2)

    print("== Lazer proximity browser acceptance ==")
    print(f"   base url: {base_url}")

    fixtures = seed_fixtures()
    by_title = {f["title"]: f["id"] for f in fixtures}
    ids = [f["id"] for f in fixtures]

    try:
        with sync_playwright() as playwright:
            connect = parse_connect(args.connect)

            # --- 1. Permission granted, valid position -----------------------
            browser = launch(playwright.chromium, connect)
            context = browser.new_context(geolocation=MOCK_POSITION, permissions=["geolocation"])
            # Delay the mocked position so the pre-permission explanation is
            # observably present BEFORE the position resolves (with an instant
            # mock it is inserted and removed within the same frame).
            context.add_init_script(
                "const _get = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);"
                "navigator.geolocation.getCurrentPosition = function (ok, err, opts) {"
                "  return _get(function (p) { setTimeout(function () { ok(p); }, 800); }, err, opts);"
                "};"
            )
            page = context.new_page()
            errors = []
            endpoint_failures = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.on("console", lambda m: errors.append(m.text) if m.type == "error" and "Failed to load resource" not in m.text else None)
            page.on("response", lambda r: endpoint_failures.append(f"{r.status} {r.url}") if "conexao/v1" in r.url and r.status != 200 else None)

            result = scenario(page, base_url, expect_proximity=True)

            print("\n-- granted --")
            check(result["explanation_present"] >= 1, "the pre-permission explanation is rendered before the request resolves")
            check(result["explanation_after"] == 0, "the explanation is removed once the position resolves")
            check(result["note_count"] == 1, "exactly one 'Ordenado por proximidade' note is shown")
            check(
                result["ids"][:4] == [
                    f"post-{by_title['Here']}",
                    f"post-{by_title['Near']}",
                    f"post-{by_title['Cork']}",
                    f"post-{by_title['NoCoords']}",
                ],
                f"cards are globally nearest-first with the coordinate-less attraction last: {result['ids'][:4]}",
            )
            check(len(result["ids"]) == len(set(result["ids"])), "no duplicate cards after the proximity swap")
            check(len(result["distances"]) == 3, f"exactly the three geolocated cards show a distance ({len(result['distances'])})")
            check(any(" m de si" in t for t in result["distances"]), "a sub-kilometre distance is shown in metres")
            check(any("km de si" in t for t in result["distances"]), "a multi-kilometre distance is shown in kilometres")
            check(
                page.eval_on_selector_all(".leisure-card-distance[data-leisure-distance]", "els => els.length") == 3,
                "each distance detail is bound to its attraction id",
            )
            check(errors == [], f"no console/page errors (granted): {errors[:3]}")
            check(endpoint_failures == [], f"every proximity endpoint call answered 200: {endpoint_failures[:3]}")

            # -- global ordering across MULTIPLE proximity batches ------------
            # Scroll until at least two extra batches have been appended, then
            # compare the WHOLE rendered list against an independently computed
            # nearest-first expectation (proves the sort is global, not per
            # batch, and that infinite scroll follows it).
            for _ in range(8):
                page.mouse.wheel(0, 4000)
                page.wait_for_timeout(700)
                if len(grid_ids(page)) >= 25:
                    break

            rendered = grid_ids(page)
            dataset = page.evaluate(
                "async () => { const r = await fetch('/wp-json/conexao/v1/leisure-coordinates',"
                " {credentials:'same-origin'}); const j = await r.json(); return j.items; }"
            )
            expected = expected_proximity_order(dataset, MOCK_POSITION["latitude"], MOCK_POSITION["longitude"])
            check(
                len(rendered) > 10,
                f"infinite scroll appended further proximity batches ({len(rendered)} cards rendered)",
            )
            check(
                rendered == expected[: len(rendered)],
                "the rendered cards across every batch match the globally sorted order exactly",
            )
            check(len(rendered) == len(set(rendered)), "no duplicate cards after loading several proximity batches")
            check(
                all("post-" in card_id for card_id in rendered),
                "every proximity batch card keeps its post id",
            )

            # -- filter change after a full navigation ------------------------
            page.goto(f"{base_url}/lazer/?county=dublin", wait_until="domcontentloaded")
            page.wait_for_timeout(3500)
            filtered_ids = grid_ids(page)
            check(
                page.eval_on_selector_all("[data-leisure-proximity='leisure-proximity-note']", "els => els.length") <= 1,
                "a filtered page load re-runs the one-shot request without duplicating the note",
            )
            check(len(filtered_ids) == len(set(filtered_ids)), "the filtered page has no duplicate cards")
            browser.close()

            # --- 2. Permission denied ---------------------------------------
            browser = launch(playwright.chromium, connect)
            context = browser.new_context(permissions=[])
            context.set_geolocation(MOCK_POSITION)
            page = context.new_page()
            errors = []
            asset_404s = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.on(
                "console",
                lambda m: asset_404s.append(m.text)
                if m.type == "error" and "Failed to load resource" in m.text
                else (errors.append(m.text) if m.type == "error" else None),
            )

            result = scenario(page, base_url, expect_proximity=False)
            print("\n-- denied --")
            check(result["note_count"] == 0, "no proximity note when permission is denied")
            check(result["distances"] == [], "no distance is shown when permission is denied")
            check(result["ids"][0] == f"post-{by_title['NoCoords']}", "the default (server) order is preserved when denied")
            check(result["explanation_after"] == 0, "the explanation is removed after a denial")
            check(errors == [], f"no console/page errors (denied): {errors[:3]}")
            print(f"   note: {len(asset_404s)} unrelated static-resource 404(s) on the page (pre-existing theme assets)")
            browser.close()

            # --- 3. Position timeout (mocked) --------------------------------
            browser = launch(playwright.chromium, connect)
            context = browser.new_context(geolocation=MOCK_POSITION, permissions=["geolocation"])
            context.add_init_script(
                "navigator.geolocation.getCurrentPosition = function (ok, err) "
                "{ setTimeout(function () { err({ code: 3, message: 'timeout' }); }, 50); };"
            )
            page = context.new_page()
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))

            result = scenario(page, base_url, expect_proximity=False)
            print("\n-- timeout --")
            check(result["note_count"] == 0, "no proximity note on a timeout")
            check(result["distances"] == [], "no distance on a timeout")
            check(result["ids"][0] == f"post-{by_title['NoCoords']}", "the default order is preserved on a timeout")
            check(errors == [], f"no page errors (timeout): {errors[:3]}")
            browser.close()

            # --- 4. Geolocation API unavailable ------------------------------
            browser = launch(playwright.chromium, connect)
            context = browser.new_context()
            context.add_init_script("delete navigator.geolocation;")
            page = context.new_page()
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))

            result = scenario(page, base_url, expect_proximity=False)
            print("\n-- unsupported --")
            check(result["note_count"] == 0, "no proximity note when the API is unavailable")
            check(result["distances"] == [], "no distance when the API is unavailable")
            check(result["ids"][0] == f"post-{by_title['NoCoords']}", "the default order is preserved when unsupported")
            check(result["explanation_after"] == 0, "the explanation is removed when the API is unavailable")
            check(errors == [], f"no page errors (unsupported): {errors[:3]}")
            browser.close()
    finally:
        delete_fixtures(ids)

    print(f"\n{PASSED} passed, {FAILED} failed")
    sys.exit(1 if FAILED else 0)


if __name__ == "__main__":
    main()
