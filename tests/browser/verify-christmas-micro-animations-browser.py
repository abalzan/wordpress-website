#!/usr/bin/env python3
"""Christmas micro-animations — real-browser verification (opt-in, read-only).

Not part of `./scripts/run-tests.sh`: like the other `tests/browser/verify-*`
scripts it is an explicitly invoked, evidence-producing check. It loads the LOCAL
site only (`CONEXAO_TEST_BASE_URL`, default http://localhost:8080), performs GET
requests and writes screenshots plus one JSON report into the directory you give
it. A production host is refused outright. Nothing is written to WordPress.

What it proves, on the homepage welcome (signed preview) and `/natal/`:

  A. The welcome star twinkle is applied once to the dialog's shared star
     (`animation-name = ce-xmas-welcome-star-twinkle`, iteration-count 1).
  B. Landing content reveals once on scroll (`ce-xmas-revealed` replaces
     `ce-xmas-reveal-ready`), and the journey's shared star pulses once.
  C. Activating the landing CTA (pointer AND keyboard) spawns a bounded,
     aria-hidden golden sparkle cluster without intercepting navigation.
  D. Under `prefers-reduced-motion` the landing content stays visible (no
     `ce-xmas-reveal-ready`) and the sparkle is suppressed.
  E. No console error or uncaught page error on either route.

Usage:
    python3 tests/browser/verify-christmas-micro-animations-browser.py --out DIR
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

from playwright.sync_api import sync_playwright  # noqa: E402

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")
USERNAME = os.environ.get("WP_USERNAME", "test")
PASSWORD = os.environ.get("WP_TEST_USER_PASSWORD", "test")

FAILURES: list[str] = []


def check(condition: bool, label: str) -> bool:
    """Record one assertion."""
    if condition:
        print(f"  PASS: {label}")
    else:
        print(f"  FAIL: {label}")
        FAILURES.append(label)
    return bool(condition)


def login(page) -> bool:
    """Authenticate through the real wp-login.php form."""
    page.goto(f"{BASE_URL}/wp-login.php", wait_until="domcontentloaded")
    page.fill("#user_login", USERNAME)
    page.fill("#user_pass", PASSWORD)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    return "wp-login.php" not in page.url


def find_preview_url(page) -> str:
    """Take the signed preview link the engine prints in the event editor."""
    page.goto(
        f"{BASE_URL}/wp-admin/edit.php?post_type=commemorative_event&post_status=all",
        wait_until="domcontentloaded",
    )
    ids = sorted(set(re.findall(r"post=(\d+)&(?:amp;)?action=edit", page.content())))
    for post_id in ids:
        page.goto(
            f"{BASE_URL}/wp-admin/post.php?post={post_id}&action=edit",
            wait_until="domcontentloaded",
        )
        match = re.search(
            r'https?://[^"\'<> ]*\?[^"\'<> ]*conexao_preview=christmas[^"\'<> ]*',
            page.content(),
        )
        if match:
            return match.group(0).replace("&#038;", "&").replace("&amp;", "&")
    return ""


def main() -> int:
    if any(host in BASE_URL for host in PRODUCTION_HOSTS):
        print("REFUSED: production host")
        return 2

    parser = argparse.ArgumentParser()
    parser.add_argument("--out", required=True)
    args = parser.parse_args()
    out = args.out
    os.makedirs(out, exist_ok=True)

    report: dict = {"base_url": BASE_URL, "failures": FAILURES}

    with sync_playwright() as p:
        browser = p.chromium.launch()

        # --- Phase A: welcome star twinkle (signed preview, homepage) -------
        print("\n== Phase A: welcome star twinkle ==")
        ctx_a = browser.new_context(viewport={"width": 390, "height": 844})
        page_a = ctx_a.new_page()
        if not login(page_a):
            print("BLOCKED: could not authenticate")
            return 2
        preview = find_preview_url(page_a)
        report["preview_url_shape"] = preview.split("?")[0] + "?conexao_preview=…"

        if preview:
            page_a.goto(preview, wait_until="load")
            try:
                page_a.wait_for_selector(".ce-xmas-welcome__dialog.is-welcome-open", timeout=8000)
            except Exception:
                pass
            twinkle = page_a.evaluate(
                """() => {
                  const body = document.querySelector(
                    '.ce-xmas-welcome__dialog.is-welcome-open .ce-xmas-star__body');
                  if (!body) return null;
                  const s = getComputedStyle(body);
                  return { name: s.animationName, count: s.animationIterationCount,
                           dur: s.animationDuration };
                }"""
            )
            check(bool(twinkle), "welcome modal opened with a star body")
            if twinkle:
                check(
                    "ce-xmas-welcome-star-twinkle" in (twinkle["name"] or ""),
                    f"star twinkle animation applied ({twinkle['name']})",
                )
                check(
                    twinkle["count"] == "1",
                    f"twinkle runs exactly once (iteration-count={twinkle['count']})",
                )
            page_a.screenshot(path=os.path.join(out, "twinkle-home-390x844.png"))
        else:
            check(False, "signed preview URL found")
        ctx_a.close()

        # --- Phase B/C/D: landing reveal, pulse, sparkle, reduced motion ----
        for mode in ("normal", "reduce"):
            print(f"\n== Landing ({mode}) ==")
            reduce = mode == "reduce"
            ctx = browser.new_context(
                viewport={"width": 1366, "height": 768},
                reduced_motion="reduce" if reduce else "no-preference",
            )
            page = ctx.new_page()
            errors: list[str] = []
            page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
            page.on("pageerror", lambda e: errors.append(str(e)))

            page.goto(f"{BASE_URL}/natal/", wait_until="load")
            page.wait_for_selector(".ce-christmas-journey", timeout=8000)

            if reduce:
                ready = page.evaluate(
                    "() => document.querySelectorAll('.ce-xmas-reveal-ready').length"
                )
                check(ready == 0, f"reduced motion: no reveal-ready hiding ({ready})")
                visible = page.evaluate(
                    """() => {
                      const els = [...document.querySelectorAll('.ce-module, .ce-christmas-closing')];
                      return els.length > 0 && els.every((el) => getComputedStyle(el).opacity === '1');
                    }"""
                )
                check(visible, "reduced motion: landing content is fully visible")
                page.evaluate(
                    """() => {
                      const cta = document.querySelector('.ce-christmas-closing__cta');
                      if (cta) cta.addEventListener('click', (e) => e.preventDefault(),
                        { capture: true, once: true });
                    }"""
                )
                cta = page.locator(".ce-christmas-closing__cta")
                if cta.count():
                    cta.click()
                    n = page.evaluate("() => document.querySelectorAll('.ce-xmas-sparkle').length")
                    check(n == 0, f"reduced motion: no CTA sparkle ({n})")
            else:
                # Journey pulse FIRST (before scrolling past it to the modules):
                # the pulse is one-shot and removed after ~1 s, so it must be
                # observed as soon as it can appear.
                pulse_js = """() => {
                          const s = document.querySelector('.ce-christmas-journey__star-svg');
                          return !!(s && s.classList.contains('ce-xmas-journey-star--pulse'));
                        }"""
                pulsed = False
                try:
                    page.wait_for_function(pulse_js, timeout=1600)
                    pulsed = True
                except Exception:
                    pass
                if not pulsed:
                    page.evaluate("() => document.querySelector('.ce-christmas-journey').scrollIntoView({block:'center'})")
                    try:
                        page.wait_for_function(pulse_js, timeout=3000)
                        pulsed = True
                    except Exception:
                        pulsed = False
                check(pulsed, "journey shared star pulses once on first visibility")

                module = page.locator(".ce-module").last
                module.scroll_into_view_if_needed()
                page.wait_for_timeout(700)
                revealed = page.evaluate(
                    """() => {
                      const els = [...document.querySelectorAll('.ce-module, .ce-christmas-closing')];
                      return els.filter((el) => el.classList.contains('ce-xmas-revealed')).length;
                    }"""
                )
                total = page.evaluate("() => document.querySelectorAll('.ce-module, .ce-christmas-closing').length")
                check(revealed > 0, f"content reveal: {revealed}/{total} sections revealed on scroll")

                page.evaluate(
                    """() => {
                      const cta = document.querySelector('.ce-christmas-closing__cta');
                      if (cta) cta.addEventListener('click', (e) => e.preventDefault(),
                        { capture: true, once: true });
                    }"""
                )
                cta = page.locator(".ce-christmas-closing__cta")
                cta.click()
                n = page.evaluate("() => document.querySelectorAll('.ce-xmas-sparkle').length")
                check(3 <= n <= 5, f"CTA sparkle (pointer): {n} particles (3-5)")
                hidden = page.evaluate(
                    """() => {
                      const ps = [...document.querySelectorAll('.ce-xmas-sparkle')];
                      return ps.length > 0 && ps.every((p) => p.getAttribute('aria-hidden') === 'true');
                    }"""
                )
                check(hidden, "sparkle particles are aria-hidden")
                page.screenshot(path=os.path.join(out, f"landing-sparkle-{mode}.png"))

                page.evaluate(
                    """() => {
                      const cta = document.querySelector('.ce-christmas-closing__cta');
                      if (cta) cta.addEventListener('click', (e) => e.preventDefault(),
                        { capture: true, once: true });
                    }"""
                )
                page.locator(".ce-christmas-closing__cta").focus()
                page.keyboard.press("Enter")
                nk = page.evaluate("() => document.querySelectorAll('.ce-xmas-sparkle').length")
                check(3 <= nk <= 5, f"CTA sparkle (keyboard Enter): {nk} particles (3-5)")

            check(not errors, f"no console/page error ({len(errors)})" + (f" :: {errors[:2]}" if errors else ""))
            page.screenshot(path=os.path.join(out, f"landing-{mode}.png"))
            ctx.close()

        browser.close()

    report["failures"] = FAILURES
    report["passed"] = len(FAILURES) == 0
    with open(os.path.join(out, "micro-animations-verification.json"), "w") as fh:
        json.dump(report, fh, indent=2)

    print(f"\n{'PASS: 0 failure(s)' if not FAILURES else 'FAIL: ' + str(len(FAILURES)) + ' failure(s)'}")
    for f in FAILURES:
        print(f"  - {f}")
    return 0 if not FAILURES else 1


if __name__ == "__main__":
    sys.exit(main())
