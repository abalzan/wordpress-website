#!/usr/bin/env python3
"""Christmas landing routes — real-browser verification with the event ACTIVE.

Not part of `./scripts/run-tests.sh`: like the other `tests/browser/verify-*` scripts
it is an explicitly invoked, evidence-producing check. It loads the LOCAL site only
(`CONEXAO_TEST_BASE_URL`, default http://localhost:8080), performs GET requests and
writes screenshots plus one JSON report into the directory you give it. A production
host is refused outright.

Why a signed preview: the defect this script guards
(`docs/reports/2026-11-06-christmas-landing-page-active-dispatch-fix.md`) only
exists while the Christmas occurrence is inside its window, which is not the case
for most of the year. The signed, capability-gated preview is the engine's own
sanctioned way to request that state without touching data or the clock.

What it proves, on `/natal/` and `/en/christmas/`, at 320x568, 390x844, 768x1024
and 1366x768:

  - the route answers 200 with the private-preview banner (so the event is active);
  - the landing page is NOT blank: one `<h1>`, a non-empty page hero and at least
    three landing modules are rendered, and the two-sky journey renders once;
  - the page title and copy are in the route language and the other language never
    leaks in;
  - the homepage welcome is absent and no dialog auto-opens on the landing route;
  - no horizontal overflow, no broken image, no console error, no uncaught page
    error and no failed request;
  - a CTA navigates to a real page;
  - with JavaScript disabled the server-rendered landing page is still usable.

Credentials are never read from a file: mint an ephemeral, local admin session and
pass its cookie and the signed-preview nonce in the environment (see the report's
"How to reproduce" section). This script writes nothing to WordPress.

Usage:
    CONEXAO_PREVIEW_COOKIE_NAME=... CONEXAO_PREVIEW_COOKIE_VALUE=... \
    CONEXAO_PREVIEW_NONCE=... \
    python3 tests/browser/verify-christmas-landing-dispatch-browser.py --out DIR
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

from playwright.sync_api import sync_playwright  # noqa: E402

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")

COOKIE_NAME = os.environ.get("CONEXAO_PREVIEW_COOKIE_NAME", "")
COOKIE_VALUE = os.environ.get("CONEXAO_PREVIEW_COOKIE_VALUE", "")
PREVIEW_NONCE = os.environ.get("CONEXAO_PREVIEW_NONCE", "")

ROUTES = (
    ("pt", "/natal/", "Natal", "Dois céus, uma mesma estrela", "Two skies, one star"),
    ("en", "/en/christmas/", "Christmas", "Two skies, one star", "Dois céus, uma mesma estrela"),
)

PROFILES = (
    ("320x568", 320, 568),
    ("390x844", 390, 844),
    ("768x1024", 768, 1024),
    ("1366x768", 1366, 768),
)

FAILURES: list[str] = []


def check(condition: bool, label: str) -> bool:
    """Record one assertion."""
    if condition:
        print(f"  PASS: {label}")
    else:
        print(f"  FAIL: {label}")
        FAILURES.append(label)
    return condition


def preview_url(path: str) -> str:
    """The landing route inside the signed, private preview of the active event."""
    return (
        f"{BASE_URL}{path}?conexao_preview=christmas"
        f"&_wpnonce={PREVIEW_NONCE}&_ce_ts={int(time.time())}"
    )


def new_context(browser, width: int, height: int, java_script: bool = True):
    """Open a fresh context with the ephemeral session and one viewport."""
    context = browser.new_context(
        viewport={"width": width, "height": height},
        java_script_enabled=java_script,
    )

    if COOKIE_NAME and COOKIE_VALUE:
        context.add_cookies([{"name": COOKIE_NAME, "value": COOKIE_VALUE, "url": BASE_URL}])

    context.set_default_timeout(20000)

    return context


def verify_route(browser, label: str, path: str, title: str, own: str, foreign: str, out_dir: str) -> dict:
    """Verify one landing route in the active-event state at every profile."""
    print(f"\n== {label}: {path} (event active, signed preview) ==")
    result: dict = {"path": path, "profiles": {}}

    for profile, width, height in PROFILES:
        prefix = f"{label}/{profile}"
        context = new_context(browser, width, height)
        page = context.new_page()
        observed = observe(page)

        response = page.goto(preview_url(path), wait_until="networkidle")

        profile_result: dict = {"status": response.status if response else 0}

        check(response is not None and response.status == 200, f"{prefix}: the route answers 200")
        check(
            page.locator(".conexao-preview-banner").count() == 1,
            f"{prefix}: the signed preview is authorised (the event is active)",
        )
        check(page.locator("h1").count() == 1, f"{prefix}: exactly one h1")
        check(
            page.locator(".ce-landing__title").count() == 1,
            f"{prefix}: the landing page hero is rendered exactly once",
        )

        hero = page.locator(".ce-landing__title")
        heading = hero.inner_text().strip() if hero.count() else ""
        profile_result["heading"] = heading
        check(heading == title, f"{prefix}: the hero is the {label.upper()} page title ({title})")
        check(
            page.locator("[data-ce-landing]").count() == 1,
            f"{prefix}: the shell is attributed to the queried page",
        )

        modules = page.locator("section.ce-module").count()
        profile_result["modules"] = modules
        check(modules >= 3, f"{prefix}: at least three landing modules render ({modules})")
        check(
            page.locator(".ce-christmas-journey").count() == 1,
            f"{prefix}: the two-sky journey renders once",
        )

        welcome = page.locator(".ce-xmas-welcome, [data-ce-welcome-dialog]").count()
        profile_result["welcome_locators"] = welcome
        check(welcome == 0, f"{prefix}: the homepage welcome is absent from the landing route")
        check(page.locator("dialog[open]").count() == 0, f"{prefix}: no dialog auto-opens on the landing route")

        overflow = page.evaluate(
            "() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]"
        )
        profile_result["overflow"] = overflow
        check(overflow[0] <= overflow[1], f"{prefix}: no horizontal overflow ({overflow[0]} <= {overflow[1]})")

        broken = page.evaluate(
            "() => Array.from(document.images)"
            ".filter((i) => i.complete && i.naturalWidth === 0)"
            ".map((i) => i.currentSrc || i.src)"
        )
        profile_result["broken_images"] = broken
        check(not broken, f"{prefix}: no broken image ({len(broken)} broken)")

        body = page.inner_text("main")
        check(own in body, f"{prefix}: the landing copy is in the route language")
        check(foreign not in body, f"{prefix}: the other language copy never leaks in")

        ctas = page.evaluate(
            "() => Array.from(document.querySelectorAll('.ce-landing a[href]'))"
            ".map((a) => a.getAttribute('href'))"
        )
        profile_result["ctas"] = ctas
        check(bool(ctas), f"{prefix}: the page exposes real CTA links")
        check(
            all(isinstance(href, str) and href.startswith("http") for href in ctas),
            f"{prefix}: every CTA has a real destination",
        )

        profile_result["console"] = observed["console"]
        profile_result["pageerror"] = observed["pageerror"]
        profile_result["requestfailed"] = observed["requestfailed"]
        check(not observed["console"], f"{prefix}: no console error")
        check(not observed["pageerror"], f"{prefix}: no uncaught page error")
        check(not observed["requestfailed"], f"{prefix}: no failed request")

        shot = os.path.join(out_dir, f"landing-active-{label}-{profile}.png")
        page.screenshot(path=shot, full_page=True)
        profile_result["screenshot"] = os.path.basename(shot)

        context.close()
        result["profiles"][profile] = profile_result

    return result


def observe(page) -> dict:
    """Attach error collectors to a page."""
    observed = {"console": [], "pageerror": [], "requestfailed": []}

    page.on(
        "console",
        lambda message: observed["console"].append(f"{message.type}: {message.text}")
        if message.type == "error"
        else None,
    )
    page.on("pageerror", lambda error: observed["pageerror"].append(str(error)))
    page.on("requestfailed", lambda request: observed["requestfailed"].append(request.url))

    return observed


def verify_cta_navigation(browser, label: str, path: str) -> dict:
    """Follow the closing CTA and require a real page."""
    context = new_context(browser, 1366, 768)
    page = context.new_page()

    page.goto(preview_url(path), wait_until="networkidle")

    link = page.locator(".ce-christmas-closing__cta")
    href = link.get_attribute("href") if link.count() else ""
    status = 0

    if href:
        response = page.goto(href, wait_until="domcontentloaded")
        status = response.status if response else 0

    context.close()

    check(bool(href) and href.startswith("http"), f"{label}: the closing CTA has a real destination ({href})")
    check(status == 200, f"{label}: the closing CTA lands on a page that answers 200 ({status})")

    return {"href": href, "status": status}


def verify_no_javascript(browser, label: str, path: str) -> dict:
    """The server-rendered landing page stays usable without JavaScript."""
    context = new_context(browser, 390, 844, java_script=False)
    page = context.new_page()

    response = page.goto(preview_url(path), wait_until="domcontentloaded")

    result = {
        "status": response.status if response else 0,
        "hero": page.locator(".ce-landing__title").count(),
        "modules": page.locator("section.ce-module").count(),
        "banner": page.locator(".conexao-preview-banner").count(),
    }

    context.close()

    check(result["status"] == 200, f"{label}: no-js route answers 200")
    check(result["hero"] == 1, f"{label}: no-js renders the page hero")
    check(result["modules"] >= 3, f"{label}: no-js renders the landing modules")
    check(result["banner"] == 1, f"{label}: no-js keeps the preview banner")

    return result


def main() -> int:
    """Run every route and write the report."""
    parser = argparse.ArgumentParser(description="Christmas landing dispatch browser verification.")
    parser.add_argument(
        "--out",
        default=os.path.join(
            "docs", "evidence", "2026-11-06-christmas-landing-page-active-dispatch-fix"
        ),
    )
    args = parser.parse_args()

    host = BASE_URL.split("//", 1)[-1].split("/", 1)[0].split(":")[0]

    if host in PRODUCTION_HOSTS:
        print("REFUSED: this verifier never runs against production.")
        return 2

    if not (COOKIE_NAME and COOKIE_VALUE and PREVIEW_NONCE):
        print(
            "REFUSED: set CONEXAO_PREVIEW_COOKIE_NAME, CONEXAO_PREVIEW_COOKIE_VALUE and "
            "CONEXAO_PREVIEW_NONCE to mint the signed preview (see the report)."
        )
        return 2

    out_dir = os.path.abspath(args.out)
    os.makedirs(out_dir, exist_ok=True)

    print(f"base url: {BASE_URL}")
    print(f"evidence: {out_dir}")

    report: dict = {
        "base_url": BASE_URL,
        "state": "active (signed preview of the christmas event)",
        "routes": {},
    }

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        report["browser"] = f"chromium {browser.version}"
        print(f"browser: {report['browser']}")

        for label, path, title, own, foreign in ROUTES:
            report["routes"][label] = verify_route(browser, label, path, title, own, foreign, out_dir)
            report["routes"][label]["cta_navigation"] = verify_cta_navigation(browser, label, path)
            report["routes"][label]["no_javascript"] = verify_no_javascript(browser, label, path)

        browser.close()

    report["failures"] = FAILURES
    report["status"] = "pass" if not FAILURES else "fail"

    with open(
        os.path.join(out_dir, "landing-active-browser-verification.json"), "w", encoding="utf-8"
    ) as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)

    print(f"\n{report['status'].upper()}: {len(FAILURES)} failure(s)")
    return 0 if not FAILURES else 1


if __name__ == "__main__":
    sys.exit(main())

