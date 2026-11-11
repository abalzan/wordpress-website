#!/usr/bin/env python3
"""Christmas signature experience — real-browser verification (opt-in, read-only).

Not part of `./scripts/run-tests.sh`: like the other `tests/browser/verify-*` scripts it
is an explicitly invoked, evidence-producing check. It loads the LOCAL site only
(`CONEXAO_TEST_BASE_URL`, default http://localhost:8080), performs GET requests and
writes screenshots plus one JSON report into the directory you give it. A production
host is refused outright.

What it proves, on `/natal/` and `/en/christmas/`:

  - the two-sky journey is upgraded into a real tabs widget (roles, aria-selected,
    roving tabindex, one visible panel), by pointer AND by keyboard;
  - both perspectives exist with the same structure and the same image-free scenes;
  - there is no horizontal overflow at 320 / 390 / 768 / 1366 px, and no console error,
    page error or failed request;
  - reduced motion removes the star's travel and the control transition;
  - the decorative star is hidden from assistive technology and not focusable;
  - the landing route stays a normal page (200, one `<h1>`, not a preview).

Usage:
    python3 tests/browser/verify-christmas-signature-browser.py --out DIR
"""

from __future__ import annotations

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

from playwright.sync_api import sync_playwright  # noqa: E402

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080")
PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")
ROUTES = (("pt", "/natal/"), ("en", "/en/christmas/"))
WIDTHS = (320, 390, 768, 1366)

FAILURES: list[str] = []


def check(condition: bool, label: str) -> bool:
    """Record one assertion."""
    if condition:
        print(f"  PASS: {label}")
    else:
        print(f"  FAIL: {label}")
        FAILURES.append(label)
    return condition


def open_page(browser, width: int):
    """Open a fresh context at one viewport width."""
    context = browser.new_context(viewport={"width": width, "height": 900})
    page = context.new_page()
    observed = {"console": [], "pageerror": [], "requestfailed": []}

    page.on(
        "console",
        lambda message: observed["console"].append(f"{message.type}: {message.text}")
        if message.type in ("error", "warning")
        else None,
    )
    page.on("pageerror", lambda error: observed["pageerror"].append(str(error)))
    page.on("requestfailed", lambda request: observed["requestfailed"].append(request.url))

    return context, page, observed


def verify_route(browser, label: str, path: str, out_dir: str) -> dict:
    """Verify one landing route at every width and return its observations."""
    print(f"\n== {label}: {path} ==")
    result: dict = {"path": path}

    context, page, observed = open_page(browser, WIDTHS[-1])
    response = page.goto(BASE_URL + path, wait_until="networkidle")

    check(response is not None and response.status == 200, f"{label}: the route answers 200")
    check(page.locator("h1").count() == 1, f"{label}: exactly one h1 on the page")
    check(
        page.locator("[data-ce-preview], .conexao-preview-banner").count() == 0,
        f"{label}: not a preview",
    )

    result["tabs"] = page.locator("[data-ce-journey-tab]").count()
    result["panels"] = page.locator("[data-ce-journey-panel]").count()
    check(result["tabs"] == 2, f"{label}: two sky controls")
    check(result["panels"] == 2, f"{label}: two sky panels")

    result["tablist_role"] = page.get_attribute("[data-ce-journey-tabs]", "role")
    check(result["tablist_role"] == "tablist", f"{label}: the control is a tablist")

    result["panel_roles"] = page.eval_on_selector_all(
        "[data-ce-journey-panel]", "els => els.map(e => e.getAttribute('role'))"
    )
    check(result["panel_roles"] == ["tabpanel", "tabpanel"], f"{label}: both panels are tabpanels")

    result["panel_labels"] = page.eval_on_selector_all(
        "[data-ce-journey-panel]", "els => els.map(e => e.getAttribute('aria-labelledby'))"
    )
    check(
        result["panel_labels"] == ["ce-christmas-journey-tab-brasil", "ce-christmas-journey-tab-irlanda"],
        f"{label}: each panel is labelled by its own control",
    )

    result["visible_panels"] = page.locator("[data-ce-journey-panel]:visible").count()
    check(result["visible_panels"] == 1, f"{label}: exactly one sky is visible at a time")

    result["details_per_panel"] = page.eval_on_selector_all(
        "[data-ce-journey-panel]",
        "els => els.map(e => e.querySelectorAll('.ce-christmas-journey__details li').length)",
    )
    check(
        result["details_per_panel"] == [3, 3],
        f"{label}: both skies carry the same three observed details (equal depth)",
    )

    result["img_in_scenes"] = page.eval_on_selector_all(
        "[data-ce-journey-panel]",
        "els => els.reduce((n, e) => n + e.querySelectorAll('img').length, 0)",
    )
    check(result["img_in_scenes"] == 0, f"{label}: the sky scenes are CSS, not extra images")

    result["star_hidden"] = page.eval_on_selector_all(
        ".ce-xmas-star",
        "els => els.length > 0 && els.every(e => e.getAttribute('aria-hidden') === 'true')",
    )
    check(result["star_hidden"], f"{label}: every decorative star is aria-hidden")

    result["star_focusable"] = page.eval_on_selector_all(
        ".ce-xmas-star", "els => els.some(e => e.getAttribute('focusable') !== 'false')"
    )
    check(not result["star_focusable"], f"{label}: no star is focusable")

    # --- pointer ---
    page.click("[data-ce-journey-tab='irlanda']")
    page.wait_for_timeout(850)
    result["state_after_click"] = page.get_attribute("[data-ce-journey]", "data-ce-journey-state")
    result["selected_after_click"] = page.eval_on_selector_all(
        "[data-ce-journey-tab]", "els => els.map(e => e.getAttribute('aria-selected'))"
    )
    check(result["state_after_click"] == "irlanda", f"{label}: a click switches the sky")
    check(result["selected_after_click"] == ["false", "true"], f"{label}: aria-selected follows the sky")
    check(
        page.locator("[data-ce-journey-panel]:visible").count() == 1,
        f"{label}: still exactly one visible sky after the switch",
    )

    # --- keyboard ---
    page.focus("[data-ce-journey-tab='irlanda']")
    page.keyboard.press("ArrowRight")
    page.wait_for_timeout(300)
    result["state_after_arrow"] = page.get_attribute("[data-ce-journey]", "data-ce-journey-state")
    check(result["state_after_arrow"] == "brasil", f"{label}: ArrowRight moves to the next sky")

    page.keyboard.press("End")
    page.wait_for_timeout(300)
    check(
        page.get_attribute("[data-ce-journey]", "data-ce-journey-state") == "irlanda",
        f"{label}: End jumps to the last sky",
    )

    result["focus_after_keyboard"] = page.evaluate("() => document.activeElement.id")
    check(
        result["focus_after_keyboard"] == "ce-christmas-journey-tab-irlanda",
        f"{label}: the focus follows the selected sky",
    )

    # --- widths, overflow, screenshots ---
    for width in WIDTHS:
        page.set_viewport_size({"width": width, "height": 900})
        page.wait_for_timeout(250)
        overflow = page.evaluate(
            "() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]"
        )
        result[f"overflow_{width}"] = overflow
        check(overflow[0] <= overflow[1], f"{label}: no horizontal overflow at {width}px")
        page.screenshot(
            path=os.path.join(out_dir, f"christmas-{label}-{width}.png"),
            full_page=True,
        )

    # --- reduced motion ---
    page.emulate_media(reduced_motion="reduce")
    page.set_viewport_size({"width": 1366, "height": 900})
    page.wait_for_timeout(200)
    result["reduced_motion"] = page.evaluate(
        """() => {
            const star = document.querySelector('.ce-christmas-journey__star');
            const tab = document.querySelector('[data-ce-journey-tab]');
            return [getComputedStyle(star).transitionDuration, getComputedStyle(tab).transitionDuration];
        }"""
    )
    check(
        result["reduced_motion"] == ["0s", "0s"],
        f"{label}: reduced motion removes the star travel and the control transition",
    )
    page.emulate_media(reduced_motion="no-preference")

    result["console"] = observed["console"]
    result["pageerror"] = observed["pageerror"]
    result["requestfailed"] = observed["requestfailed"]
    check(not observed["console"], f"{label}: no console error or warning")
    check(not observed["pageerror"], f"{label}: no uncaught page error")
    check(not observed["requestfailed"], f"{label}: no failed request")

    context.close()
    return result


def main() -> int:
    """Run every route and write the report."""
    parser = argparse.ArgumentParser(description="Christmas signature browser verification.")
    parser.add_argument(
        "--out",
        default=os.path.join("docs", "evidence", "2026-11-06-christmas-signature-experience"),
    )
    args = parser.parse_args()

    host = BASE_URL.split("//", 1)[-1].split("/", 1)[0]

    if host in PRODUCTION_HOSTS:
        print("REFUSED: this verifier never runs against production.")
        return 2

    out_dir = os.path.abspath(args.out)
    os.makedirs(out_dir, exist_ok=True)

    print(f"base url: {BASE_URL}")
    print(f"evidence: {out_dir}")

    report: dict = {"base_url": BASE_URL, "routes": {}}

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        report["browser"] = f"chromium {browser.version}"
        print(f"browser: {report['browser']}")

        for label, path in ROUTES:
            report["routes"][label] = verify_route(browser, label, path, out_dir)

        browser.close()

    report["failures"] = FAILURES
    report["status"] = "pass" if not FAILURES else "fail"

    with open(os.path.join(out_dir, "browser-verification.json"), "w", encoding="utf-8") as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)

    print(f"\n{report['status'].upper()}: {len(FAILURES)} failure(s)")
    return 0 if not FAILURES else 1


if __name__ == "__main__":
    sys.exit(main())

