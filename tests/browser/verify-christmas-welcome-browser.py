#!/usr/bin/env python3
"""Christmas homepage welcome — real-browser verification (opt-in, read-only).

Not part of `./scripts/run-tests.sh`: like the other `tests/browser/verify-*`
scripts it is an explicitly invoked, evidence-producing check. It loads the LOCAL
site only (`CONEXAO_TEST_BASE_URL`, default http://localhost:8080), performs GET
requests and writes screenshots plus one JSON report into the directory you give
it. A production host is refused outright. Nothing is written to WordPress.

Four phases:

  A. PUBLIC ROUTES — `/` and `/en/` with no active event: no welcome markup, no
dialog, no commemorative asset request, and the normal homepage still works.
  B. LANDING PAGES — `/natal/` and `/en/christmas/` never carry the homepage
welcome modal.
  C. SIGNED PRIVATE PREVIEW — the REAL server-rendered placement: the preview
banner stays visible, the dialog stays CLOSED (never an inline card), the compact
fallback card carries the hidden-until-wired preview trigger, the modal does NOT
auto-open, and clicking that trigger opens the exact same modal through
showModal() without ever touching the visitor dismissal key (that is how every
layout profile below is measured on real markup).
  D. GENERATED HARNESS — the same placement markup, captured verbatim from the
preview page, with the preview flag removed so the AUTO-OPEN path can be measured
without weakening preview authentication: open-without-gesture, once-per-tab-
session dismissal leaving NOTHING inline, a fresh session, denied storage,
reduced motion, light/dark, and the no-JavaScript fallback card.

Viewport matrix (required for acceptance): 320x568, 375x667, 390x844, 768x1024,
1024x768, 1366x768, 1440x900 and landscape 844x390.

Usage:
    python3 tests/browser/verify-christmas-welcome-browser.py --out DIR
"""

from __future__ import annotations

import argparse
import http.server
import json
import os
import re
import socketserver
import sys
import threading
import urllib.parse

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

from playwright.sync_api import sync_playwright  # noqa: E402

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
PRODUCTION_HOSTS = ("conexaobr.ie", "www.conexaobr.ie")
USERNAME = os.environ.get("WP_USERNAME", "test")
PASSWORD = os.environ.get("WP_TEST_USER_PASSWORD", "test")
SESSION_KEY = "ce_christmas_welcome_dismissed_v1"

dialog = ".ce-xmas-welcome__dialog"
root = ".ce-xmas-welcome[data-ce-welcome]"
close_button = "[data-ce-welcome-close]"

PROFILES = (
    ("iphone-small-320x568", 320, 568),
    ("iphone-se-375x667", 375, 667),
    ("iphone-modern-390x844", 390, 844),
    ("tablet-768x1024", 768, 1024),
    ("laptop-1024x768", 1024, 768),
    ("desktop-1366x768", 1366, 768),
    ("wide-1440x900", 1440, 900),
    ("landscape-844x390", 844, 390),
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


def watch(page) -> dict:
    """Collect console errors, page errors and failed requests for one page."""
    observed = {"console": [], "pageerror": [], "requestfailed": [], "requests": [], "bad": []}

    page.on(
        "console",
        lambda message: observed["console"].append(f"{message.type}: {message.text}")
        if message.type in ("error", "warning")
        else None,
    )
    page.on("pageerror", lambda error: observed["pageerror"].append(str(error)))
    page.on("requestfailed", lambda request: observed["requestfailed"].append(request.url))
    page.on("response", lambda response: observed["requests"].append(response.url))
    page.on(
        "response",
        lambda response: observed["bad"].append((response.status, response.url))
        if response.status >= 400
        else None,
    )

    return observed


def modal_state(page) -> dict:
    """Read the welcome's live state without touching it."""
    return page.evaluate(
        """() => {
            const dialog = document.querySelector('.ce-xmas-welcome__dialog');
            const close = document.querySelector('[data-ce-welcome-close]');
            const root = document.querySelector('.ce-xmas-welcome[data-ce-welcome]');
            const band = document.querySelector('.ce-christmas-hero');
            const fallback = document.querySelector('[data-ce-welcome-fallback]');
            const trigger = document.querySelector('[data-ce-welcome-preview-open]');
            const box = dialog ? dialog.getBoundingClientRect() : null;
            const visible = (el) => !!el && !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            return {
                present: !!dialog,
                isModal: dialog ? dialog.matches(':modal') : false,
                hasOpen: dialog ? dialog.hasAttribute('open') : false,
                state: document.documentElement.getAttribute('data-ce-welcome-state'),
                preview: root ? root.hasAttribute('data-ce-welcome-preview') : false,
                closeHidden: close ? close.hasAttribute('hidden') : null,
                closeVisible: close ? !!(close.offsetWidth || close.offsetHeight) : null,
                bandVisible: band ? visible(band) : null,
                fallbackVisible: visible(fallback),
                triggerVisible: visible(trigger),
                bodyPosition: document.body.style.position,
                scrollY: window.scrollY,
                box: box ? { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) } : null,
            };
        }"""
    )


def profile_checks(page, label: str, expected_open: bool, out_dir: str, prefix: str) -> dict:
    """Assert the modal layout contract on the CURRENT viewport."""
    print(f"\n  -- {label} --")
    state = modal_state(page)
    result = {"profile": label, "state": state}

    if not check(state["present"], f"{prefix}: the welcome dialog is in the DOM"):
        return result

    check(state["isModal"] is expected_open, f"{prefix}: modal state is {'open (top layer)' if expected_open else 'closed (never an inline card)'}")

    if expected_open:
        check(state["hasOpen"] is True, f"{prefix}: an open dialog carries the open attribute")
        check(state["fallbackVisible"] is False, f"{prefix}: the fallback card is hidden while the modal is open")
        inline_copies = page.evaluate(
            "() => Array.from(document.querySelectorAll('.ce-christmas-hero'))"
            ".filter((el) => !el.closest('dialog')).length"
        )
        check(
            inline_copies == 0,
            f"{prefix}: no second Natal card exists outside the dialog ({inline_copies})",
        )
    else:
        check(state["hasOpen"] is False, f"{prefix}: a closed dialog never carries the open attribute")

    metrics = page.evaluate(
        """() => {
            const dialog = document.querySelector('.ce-xmas-welcome__dialog');
            const close = document.querySelector('[data-ce-welcome-close]');
            const cta = document.querySelector('.ce-christmas-hero__cta');
            const image = document.querySelector('.ce-christmas-hero__art img');
            const hero = document.querySelector('.ce-christmas-hero');
            const backdrop = getComputedStyle(dialog, '::backdrop').backgroundColor;
            const rect = (el) => {
                if (!el) return null;
                const r = el.getBoundingClientRect();
                return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), bottom: Math.round(r.bottom), right: Math.round(r.right) };
            };
            const d = rect(dialog);
            const natural = image ? image.naturalWidth / image.naturalHeight : 0;
            const rendered = image && image.getBoundingClientRect().height ? image.getBoundingClientRect().width / image.getBoundingClientRect().height : 0;
            return {
                viewport: { w: window.innerWidth, h: window.innerHeight },
                docOverflow: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
                dialog: d,
                backdrop: backdrop,
                close: rect(close),
                cta: rect(cta),
                image: rect(image),
                imageRatio: { natural: Math.round(natural * 1000) / 1000, rendered: Math.round(rendered * 1000) / 1000 },
                heroScroll: hero ? { client: hero.clientHeight, scroll: hero.scrollHeight } : null,
                activeInside: dialog ? dialog.contains(document.activeElement) : false,
                closeText: close ? close.textContent.trim() : '',
                ctaText: cta ? cta.textContent.trim() : '',
                ctaHref: cta ? cta.getAttribute('href') : '',
            };
        }"""
    )
    result["metrics"] = metrics

    vw, vh = metrics["viewport"]["w"], metrics["viewport"]["h"]
    box = metrics["dialog"]
    close = metrics["close"]
    cta = metrics["cta"]

    # The backdrop must clearly separate the modal from the page without being
    # fully opaque: a visible, non-transparent tint over the whole viewport.
    backdrop = metrics.get("backdrop") or ""
    if expected_open:
        check(
            backdrop not in ("", "rgba(0, 0, 0, 0)", "transparent"),
            f"{prefix}: the modal backdrop is visible ({backdrop})",
        )

    check(metrics["docOverflow"][0] <= metrics["docOverflow"][1], f"{prefix}: no horizontal page overflow")
    check(box["w"] <= vw, f"{prefix}: the dialog is not wider than the viewport ({box['w']} <= {vw})")
    check(box["x"] >= 0 and box["right"] <= vw, f"{prefix}: the dialog is fully inside the viewport horizontally")
    check(box["y"] >= 0 and box["bottom"] <= vh, f"{prefix}: the dialog is fully inside the viewport vertically ({box['y']}..{box['bottom']} of {vh})")
    check(abs((box["x"] + box["w"] / 2) - vw / 2) <= 2, f"{prefix}: the dialog is horizontally centred")

    # The image keeps its 16:9 frame, so Brazil and Ireland both stay visible.
    check(
        abs(metrics["imageRatio"]["natural"] - metrics["imageRatio"]["rendered"]) <= 0.02,
        f"{prefix}: the panorama is not cropped or stretched ({metrics['imageRatio']})",
    )
    check(metrics["image"]["w"] > 0 and metrics["image"]["h"] > 0, f"{prefix}: the panorama is rendered")

    check(state["bodyPosition"] == "fixed", f"{prefix}: the page behind the modal cannot scroll")
    check(metrics["activeInside"], f"{prefix}: focus is inside the dialog")

    check(close is not None and close["w"] >= 44 and close["h"] >= 44, f"{prefix}: the close control is at least 44x44")
    check(close["y"] >= box["y"] and close["bottom"] <= box["bottom"], f"{prefix}: the close control stays inside the card")
    check(close["bottom"] <= vh, f"{prefix}: the close control is inside the viewport")
    check(metrics["closeText"].lower() in ("close", "fechar"), f"{prefix}: the close control is labelled ({metrics['closeText']})")

    # The CTA: visible, at least 44 px tall, and reachable by scrolling INSIDE the
    # dialog when the card is shorter than its content.
    if cta["bottom"] > box["bottom"] or cta["y"] < box["y"]:
        page.evaluate(
            """() => {
                const cta = document.querySelector('.ce-christmas-hero__cta');
                const hero = document.querySelector('.ce-christmas-hero');
                hero.scrollTop = hero.scrollHeight;
                cta.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }"""
        )
        page.wait_for_timeout(120)
        cta = page.evaluate(
            """() => { const r = document.querySelector('.ce-christmas-hero__cta').getBoundingClientRect();
                return { y: Math.round(r.y), h: Math.round(r.height), bottom: Math.round(r.bottom) }; }"""
        )

    check(cta["h"] >= 44, f"{prefix}: the CTA is a 44 px touch target ({cta['h']}px)")
    check(cta["y"] >= box["y"] and cta["bottom"] <= box["bottom"] + 1, f"{prefix}: the CTA is reachable inside the dialog")
    check(metrics["ctaHref"].startswith("http"), f"{prefix}: the CTA still points at a real URL ({metrics['ctaHref']})")

    shot = os.path.join(out_dir, f"welcome-{prefix.replace(':', '-')}-{label}.png")
    page.screenshot(path=shot)
    result["screenshot"] = os.path.basename(shot)

    return result


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
        page.goto(f"{BASE_URL}/wp-admin/post.php?post={post_id}&action=edit", wait_until="domcontentloaded")
        match = re.search(
            r"https?://[^\"'<> ]*\?[^\"'<> ]*conexao_preview=christmas[^\"'<> ]*",
            page.content(),
        )

        if match:
            return match.group(0).replace("&#038;", "&").replace("&amp;", "&")

    return ""



def real_errors(observed: dict) -> dict:
    """Separate this page's own errors from pre-existing missing uploads.

    Three `/wp-content/uploads/2026/06/*-400x300.jpg` derivatives do not exist in the
    local content fixture, so `/en/` answers 404 for them. That is a pre-existing local
    media condition, unrelated to the welcome: it is recorded in the JSON and does not
    count as a failure of this feature.
    """
    media = [url for status, url in observed.get("bad", []) if status >= 400 and "/wp-content/uploads/" in url]

    return {
        "console": [message for message in observed["console"] if "Failed to load resource" not in message],
        "pageerror": list(observed["pageerror"]),
        "requestfailed": [url for url in observed["requestfailed"] if "/wp-content/uploads/" not in url],
        "media_404": sorted(set(media)),
    }


def public_route(browser, label: str, path: str, out_dir: str, expect_package_assets: bool = False) -> dict:
    """Phase A/B: a route that must NOT carry the welcome at all."""
    print(f"\n== {label}: {path} ==")
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    observed = watch(page)
    page.add_init_script(LCP_SCRIPT)
    response = page.goto(BASE_URL + path, wait_until="networkidle")
    page.wait_for_timeout(900)

    errors = real_errors(observed)
    result = {
        "path": path,
        "lcp": page.evaluate("() => window.__ceLcp"),
        "errors": errors,
        "status": response.status if response else 0,
        "welcome_roots": page.locator(root).count(),
        "dialogs": page.locator("dialog").count(),
        "commemorative_requests": [url for url in observed["requests"] if "presentations/christmas" in url],
    }

    check(result["status"] == 200, f"{label}: the route answers 200")
    check(result["welcome_roots"] == 0, f"{label}: no welcome component is emitted")
    check(result["dialogs"] == 0, f"{label}: no dialog element is emitted")

    if expect_package_assets:
        # The documented archival exception: an opted-in landing page keeps loading
        # its OWN package assets outside the window, and nothing else.
        check(
            bool(result["commemorative_requests"])
            and all("presentations/christmas" in url for url in result["commemorative_requests"]),
            f"{label}: only the Christmas package assets load (archival exception)",
        )
    else:
        check(not result["commemorative_requests"], f"{label}: no Christmas asset is requested")
    check(page.locator("header").count() > 0, f"{label}: the header is present")
    check(not errors["console"], f"{label}: no console error or warning")
    check(not errors["pageerror"], f"{label}: no uncaught page error")
    check(not errors["requestfailed"], f"{label}: no failed request")

    page.screenshot(path=os.path.join(out_dir, f"public-{label}-390x844.png"))
    context.close()

    return result


def preview_phase(browser, preview: str, out_dir: str, storage_state: dict) -> dict:
    """Phase C: the REAL server-rendered placement behind the signed preview."""
    print("\n== C: signed private preview (real markup) ==")
    context = browser.new_context(viewport={"width": 390, "height": 844}, storage_state=storage_state)
    page = context.new_page()
    observed = watch(page)
    page.add_init_script(LCP_SCRIPT)
    response = page.goto(preview, wait_until="networkidle")
    page.wait_for_timeout(900)

    result = {"status": response.status if response else 0, "url_length": len(preview)}

    check(result["status"] == 200, "preview: the previewed homepage answers 200")
    check(page.locator(".conexao-preview-banner").count() == 1, "preview: exactly one preview banner")
    check(page.locator(".conexao-preview-banner").is_visible(), "preview: the banner is visible")

    state = modal_state(page)
    result["initial_state"] = state
    result["lcp_fallback"] = page.evaluate("() => window.__ceLcp")

    check(state["present"], "preview: the real placement renders the welcome component")
    check(state["preview"] is True, "preview: the template marks the view as a private preview")
    check(state["state"] == "preview", f"preview: the controller claims the page into the preview state ({state['state']})")
    check(state["isModal"] is False, "preview: the welcome does NOT auto-open (banner stays visible)")
    check(state["hasOpen"] is False, "preview: the dialog stays CLOSED — never a non-modal inline card")
    check(state["bandVisible"] is False, "preview: the full Natal card is never rendered inline")
    check(state["fallbackVisible"] is True, "preview: the compact fallback card hosts the preview trigger")
    check(state["triggerVisible"] is True, "preview: the preview trigger is revealed by the wired controller")
    check(state["closeHidden"] is True, "preview: no dead close control is offered before the modal opens")

    # A maintainer opens the REAL modal through the preview trigger — the only
    # preview affordance — never through an inline dialog.
    trigger = page.locator("[data-ce-welcome-preview-open]")
    check(trigger.is_visible(), "preview: the preview trigger is visible and clickable")
    trigger.click()
    page.wait_for_timeout(300)
    state = modal_state(page)
    result["opened"] = {"state": state}

    check(state["isModal"] is True, "preview: the trigger opens a real modal (top layer)")
    result["lcp_modal"] = page.evaluate("() => window.__ceLcp")

    profiles = {}

    for label, width, height in PROFILES:
        page.set_viewport_size({"width": width, "height": height})
        page.wait_for_timeout(320)
        profiles[label] = profile_checks(page, label, True, out_dir, "preview")

    result["profiles"] = profiles

    page.set_viewport_size({"width": 390, "height": 844})
    page.wait_for_timeout(200)
    page.keyboard.press("Escape")
    page.wait_for_timeout(400)
    state = modal_state(page)
    result["after_escape"] = state

    check(state["isModal"] is False, "preview: Escape dismisses the welcome")
    check(state["hasOpen"] is False, "preview: the dialog is closed again — never an inline card")
    check(state["state"] == "preview", f"preview: closing returns to the preview trigger card ({state['state']})")
    check(state["fallbackVisible"] is True and state["triggerVisible"] is True, "preview: the fallback card and its trigger are visible again")
    check(state["bodyPosition"] != "fixed", "preview: the scroll lock is released")
    check(page.locator(".conexao-preview-banner").is_visible(), "preview: the banner is still visible")
    check(
        page.evaluate(f"() => sessionStorage.getItem('{SESSION_KEY}')") is None,
        "preview: opening and closing the preview NEVER writes the visitor dismissal key",
    )

    page.reload(wait_until="networkidle")
    page.wait_for_timeout(300)
    state = modal_state(page)
    result["after_reload"] = state
    check(state["isModal"] is False, "preview: a reload in the same session does not reopen it")
    check(state["hasOpen"] is False, "preview: the reloaded page renders the dialog closed")
    check(state["triggerVisible"] is True, "preview: the reloaded page offers the preview trigger again")
    check(
        page.evaluate(f"() => sessionStorage.getItem('{SESSION_KEY}')") is None,
        "preview: a preview reload still leaves the visitor dismissal key untouched",
    )

    errors = real_errors(observed)
    result["errors"] = errors
    check(not errors["console"], "preview: no console error or warning")
    check(not errors["pageerror"], "preview: no uncaught page error")
    check(not errors["requestfailed"], "preview: no failed request")

    # Everything the harness phase needs: the placement markup EXACTLY as the server
    # rendered it, and the asset stack it depends on. Captured on the PORTUGUESE
    # preview — before any language switch below — so the harness stays PT-BR.
    result["markup"] = page.eval_on_selector(root, "el => el.outerHTML")
    # The local WordPress.com-style stack serves ONE concatenated stylesheet
    # (`/_static/??…`) with the enqueued handles in `data-handles`, so the harness
    # takes the page's whole stylesheet stack in order, plus the two commemorative
    # scripts. That is the real CSS the placement is designed against.
    result["assets"] = page.evaluate(
        """() => ({
            styles: Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((link) => link.href),
            scripts: Array.from(document.querySelectorAll('script[src]'))
                .map((script) => script.src)
                .filter((src) => src.indexOf('commemorative.js') !== -1 || src.indexOf('christmas.js') !== -1),
        })"""
    )

    # English signed preview: same identity, same contract, English strings and
    # the English landing destination. The nonce is per event_key, not per language.
    en_preview = preview.replace(f"{BASE_URL}/?", f"{BASE_URL}/en/?")
    page.goto(en_preview, wait_until="networkidle")
    page.wait_for_timeout(600)
    en_state = modal_state(page)
    en_texts = page.evaluate(
        """() => ({
            heading: (document.querySelector('.ce-christmas-hero__title') || {}).textContent || '',
            fallback: (document.querySelector('.ce-xmas-welcome__fallback-title') || {}).textContent || '',
            ctaHref: document.querySelector('.ce-christmas-hero__cta') ? document.querySelector('.ce-christmas-hero__cta').getAttribute('href') : '',
        })"""
    )
    result["en_preview"] = {"state": en_state, "texts": en_texts}
    check(en_state["preview"] is True, "en preview: the English homepage renders the signed preview")
    check(en_state["isModal"] is False and en_state["hasOpen"] is False, "en preview: the dialog stays closed, no inline card")
    check(en_state["state"] == "preview", f"en preview: the preview state is claimed ({en_state['state']})")
    check("One Christmas, two skies" in en_texts["heading"], f"en preview: the heading is English ({en_texts['heading']})")
    check("One Christmas, two skies" in en_texts["fallback"], "en preview: the fallback heading is English")
    check(en_texts["ctaHref"].endswith("/en/christmas/"), f"en preview: the CTA leads to the English landing page ({en_texts['ctaHref']})")
    page.screenshot(path=os.path.join(out_dir, "preview-en-closed-390x844.png"))

    context.close()

    return result

LCP_SCRIPT = """
window.__ceLcp = null;
try {
    new PerformanceObserver((list) => {
        const entries = list.getEntries();
        const last = entries[entries.length - 1];
        if (last) {
            window.__ceLcp = {
                url: last.url || (last.element && last.element.currentSrc) || '',
                size: last.size,
                tag: last.element ? last.element.tagName : '',
            };
        }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
} catch (error) {
    window.__ceLcp = { error: String(error) };
}
"""


def write_harness(out_dir: str, markup: str, assets: dict) -> dict:
    """Write the two harness pages and return their names.

    The markup is the REAL server output; the only difference between the two files is
    the preview attribute, which is exactly the flag under test. Preview
    authentication is untouched: nothing here is served from WordPress.
    """
    styles = "\n".join(f'<link rel="stylesheet" href="{url}">' for url in assets["styles"])
    scripts = "\n".join(f'<script src="{url}" defer></script>' for url in assets["scripts"])

    def page(markup_block: str, note: str) -> str:
        return (
            "<!doctype html>\n<html lang=\"pt-BR\" data-theme=\"light\"><head><meta charset=\"utf-8\">\n"
            "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
            f"<title>Christmas welcome harness — {note}</title>\n{styles}\n"
            "<style>body{margin:0;font-family:system-ui,sans-serif;background:#0b1020;color:#fff}\n"
            ".hero-section{min-height:620px;padding:16px 0}\n"
            ".harness-note{max-width:640px;margin:0 auto 16px;padding:0 16px;font:14px/1.5 system-ui;color:#cfd6e6}\n"
            ".ce-placement--homepage_hero{position:static}\n"
            ".harness-spacer{height:1400px}\n"
            "</style></head>\n<body class=\"ce-active ce-package-christmas ce-placement-homepage-hero\">\n"
            f"<section class=\"hero-section\">\n<p class=\"harness-note\">{note}</p>\n{markup_block}\n</section>\n"
            "<div class=\"harness-spacer\"></div>\n"
            f"{scripts}\n</body></html>\n"
        )

    # The capture is the LIVE DOM after the preview controller wired it, so two
    # controller-owned mutations must be reverted to the server-rendered truth:
    #   - `data-ce-welcome-claimed` would make the harness controller refuse the root;
    #   - the revealed preview trigger must ship `hidden` again (a no-JS visitor must
    #     never meet a dead button; the preview harness re-reveals it at boot).
    markup = markup.replace(' data-ce-welcome-claimed="1"', "")

    def _restore_trigger_hidden(match) -> str:
        tag = match.group(0)
        return tag if " hidden" in tag else tag[:-1] + " hidden>"

    markup = re.sub(r"<button[^>]*data-ce-welcome-preview-open[^>]*>", _restore_trigger_hidden, markup)

    public_markup = markup.replace(' data-ce-welcome-preview="1"', "")

    names = {"preview": "welcome-harness-preview.html", "public": "welcome-harness-public.html"}

    with open(os.path.join(out_dir, names["public"]), "w", encoding="utf-8") as handle:
        handle.write(
            page(
                public_markup,
                "Generated harness: the placement markup below is the real server output "
                "captured from the signed preview. Only the private-preview attribute is "
                "removed, so the public auto-open path can be measured.",
            )
        )

    with open(os.path.join(out_dir, names["preview"]), "w", encoding="utf-8") as handle:
        handle.write(
            page(
                markup,
                "Generated harness: the same real markup WITH the private-preview "
                "attribute, proving the flag suppresses the auto-open.",
            )
        )

    return names


def serve(directory: str):
    """Serve one directory over loopback on a free port."""
    handler = lambda *args, **kwargs: http.server.SimpleHTTPRequestHandler(  # noqa: E731
        *args, directory=directory, **kwargs
    )
    httpd = socketserver.TCPServer(("127.0.0.1", 0), handler)
    thread = threading.Thread(target=httpd.serve_forever, daemon=True)
    thread.start()

    return httpd, httpd.server_address[1]


def harness_phase(browser, base: str, names: dict, out_dir: str) -> dict:
    """Phase D: the auto-open / frequency / failure behaviour on real markup."""
    print("\n== D: generated harness (auto-open, frequency, failure) ==")
    result: dict = {}

    # --- JavaScript disabled: the compact fallback card is the usable state ---
    context = browser.new_context(viewport={"width": 390, "height": 844}, java_script_enabled=False)
    page = context.new_page()
    page.goto(f"{base}/{names['public']}", wait_until="load")
    page.wait_for_timeout(250)
    nojs = {
        "dialog_visible": page.locator(dialog).is_visible(),
        "fallback_visible": page.locator("[data-ce-welcome-fallback]").is_visible(),
        "close_visible": page.locator(close_button).is_visible(),
        "trigger_visible": page.locator("[data-ce-welcome-preview-open]").is_visible(),
        "cta": page.locator("[data-ce-welcome-fallback] .ce-xmas-welcome__fallback-cta").is_visible(),
        "cta_href": page.get_attribute("[data-ce-welcome-fallback] .ce-xmas-welcome__fallback-cta", "href"),
        "heading": page.locator(".ce-xmas-welcome__fallback-title").inner_text(),
        "overflow": page.evaluate("[document.documentElement.scrollWidth, document.documentElement.clientWidth]"),
    }
    result["no_javascript"] = nojs
    check(nojs["dialog_visible"] is False, "harness(no-js): the dialog stays closed — no inline dialog banner")
    check(nojs["fallback_visible"], "harness(no-js): the compact fallback card is visible")
    check(nojs["cta"], "harness(no-js): the fallback CTA is visible and usable")
    check(nojs["close_visible"] is False, "harness(no-js): no dead close control is offered")
    check(nojs["trigger_visible"] is False, "harness(no-js): no dead preview trigger is offered")
    check(nojs["overflow"][0] <= nojs["overflow"][1], "harness(no-js): no horizontal page overflow")
    check("Um Natal" in nojs["heading"], f"harness(no-js): the fallback heading is real server text ({nojs['heading']})")
    check(nojs["cta_href"].endswith("/natal/"), f"harness(no-js): the fallback CTA leads to the landing page ({nojs['cta_href']})")
    page.screenshot(path=os.path.join(out_dir, "welcome-harness-no-javascript-390x844.png"))
    context.close()

    # --- the preview flag still suppresses the auto-open ---
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    observed = watch(page)
    page.goto(f"{base}/{names['preview']}", wait_until="networkidle")
    page.wait_for_timeout(250)
    state = modal_state(page)
    result["preview_harness"] = state

    check(state["present"], "harness(preview): the welcome component renders")
    check(state["isModal"] is False, "harness(preview): the preview flag suppresses the auto-open")
    check(state["hasOpen"] is False, "harness(preview): the dialog stays closed — never an inline card")
    check(state["state"] == "preview", f"harness(preview): the preview state is claimed ({state['state']})")
    check(
        state["fallbackVisible"] is True and state["triggerVisible"] is True,
        "harness(preview): the trigger card is visible instead of an inline dialog",
    )
    preview_errors = real_errors(observed)
    check(not preview_errors["console"] and not preview_errors["pageerror"], "harness(preview): no console error")
    context.close()

    # --- auto-open without any gesture, at every required viewport ---
    profiles = {}

    for label, width, height in PROFILES:
        context = browser.new_context(viewport={"width": width, "height": height})
        page = context.new_page()
        observed = watch(page)
        page.add_init_script(LCP_SCRIPT)
        page.goto(f"{base}/{names['public']}", wait_until="networkidle")

        try:
            page.wait_for_function(
                "() => { const d = document.querySelector('.ce-xmas-welcome__dialog'); return d && d.matches(':modal'); }",
                timeout=4000,
            )
            opened = True
        except Exception:  # noqa: BLE001 - a timeout is a failed assertion below
            opened = False

        check(opened, f"harness({label}): the welcome opens with no user gesture")
        page.wait_for_timeout(320)
        profiles[label] = profile_checks(page, label, True, out_dir, "harness")
        errors = real_errors(observed)
        profiles[label]["errors"] = errors
        profiles[label]["lcp"] = page.evaluate("() => window.__ceLcp")
        check(not errors["console"], f"harness({label}): no console error or warning")
        check(not errors["pageerror"], f"harness({label}): no uncaught page error")
        check(not errors["requestfailed"], f"harness({label}): no failed request")

        context.close()

    result["profiles"] = profiles

    # --- dismissal, once per tab session, new session ---
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    observed = watch(page)
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")
    page.wait_for_function("() => document.querySelector('.ce-xmas-welcome__dialog')?.matches(':modal')", timeout=4000)
    page.wait_for_timeout(250)

    hero_geometry = """() => {
        const h = document.querySelector('.hero-section');
        const r = h.getBoundingClientRect();
        return { height: Math.round(r.height), paddingBottom: getComputedStyle(h).paddingBottom };
    }"""

    locked = page.evaluate("() => [document.body.style.position, window.scrollY]")
    hero_open = page.evaluate(hero_geometry)

    page.locator(close_button).click()
    page.wait_for_timeout(400)

    after_close = modal_state(page)
    hero_closed = page.evaluate(hero_geometry)
    result["dismissal"] = {"locked": locked, "after": after_close, "hero_open": hero_open, "hero_closed": hero_closed}

    check(locked[0] == "fixed", "harness: the background is locked while open")
    check(after_close["isModal"] is False, "harness: the close button dismisses")
    check(after_close["hasOpen"] is False, "harness: the dialog is closed, never re-opened as an inline card")
    check(after_close["state"] == "dismissed", f"harness: the dismissed state is exposed ({after_close['state']})")
    check(after_close["bandVisible"] is False, "harness: NO inline Natal card remains after the dismissal")
    check(after_close["fallbackVisible"] is False, "harness: the fallback stays hidden for a JavaScript visitor")
    check(after_close["bodyPosition"] != "fixed", "harness: the scroll lock is released")
    check(
        page.evaluate(f"() => sessionStorage.getItem('{SESSION_KEY}')") == "1",
        "harness: the dismissal is stored in the tab session",
    )
    check(
        page.evaluate("() => localStorage.getItem('ce_christmas_welcome_dismissed_v1')") is None,
        "harness: nothing is written to the persistent storage",
    )

    page.reload(wait_until="networkidle")
    page.wait_for_timeout(350)
    state = modal_state(page)
    hero_reload = page.evaluate(hero_geometry)
    result["after_reload"] = state
    check(state["isModal"] is False, "harness: a reload in the same session does not reopen it")
    check(state["bandVisible"] is False, "harness: nothing is rendered inline after the dismissal")
    check(state["fallbackVisible"] is False, "harness: the fallback stays hidden after the dismissal")
    check(
        hero_open == hero_closed == hero_reload,
        f"harness: hero geometry is identical at open, after close and after reload — no gap, no shift ({hero_open} / {hero_closed} / {hero_reload})",
    )
    check(
        hero_closed["paddingBottom"] == "16px",
        f"harness: the hero keeps only the harness's own padding — no 286 px band reservation ({hero_closed['paddingBottom']})",
    )

    context.close()

    # --- the exact scroll position is captured and restored (measured from a real
    # pre-scroll state: the session flag keeps it closed until the test opens it) ---
    context = browser.new_context(viewport={"width": 390, "height": 844})
    context.add_init_script(f"try {{ sessionStorage.setItem('{SESSION_KEY}', '1'); }} catch (error) {{}}")
    page = context.new_page()
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")
    page.wait_for_timeout(200)
    check(
        page.evaluate("() => document.querySelector('.ce-xmas-welcome__dialog').matches(':modal')") is False,
        "harness: a recorded dismissal keeps the welcome closed",
    )
    page.evaluate("() => window.scrollTo(0, 260)")
    page.wait_for_timeout(150)
    before = page.evaluate("() => window.scrollY")
    opened = page.evaluate("() => window.ConexaoChristmas.openWelcome()")
    page.wait_for_timeout(250)
    locked = page.evaluate("() => [document.body.style.position, document.body.style.top, window.scrollY]")
    page.locator(close_button).click()
    page.wait_for_timeout(400)
    restored = page.evaluate("() => window.scrollY")
    result["scroll"] = {"before": before, "opened": opened, "locked": locked, "restored": restored}
    check(before == 260, f"harness: the page was scrolled before opening ({before})")
    check(locked[0] == "fixed" and locked[1] == "-260px", f"harness: the lock pins the page at its own offset ({locked})")
    check(restored == 260, f"harness: the exact previous scroll position is restored ({restored})")
    context.close()

    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")
    try:
        page.wait_for_function("() => document.querySelector('.ce-xmas-welcome__dialog')?.matches(':modal')", timeout=4000)
        fresh = True
    except Exception:  # noqa: BLE001
        fresh = False
    result["fresh_session"] = fresh
    check(fresh, "harness: a brand-new session is eligible again")
    context.close()

    # --- storage denied (private browsing): the page must not break ---
    context = browser.new_context(viewport={"width": 390, "height": 844})
    context.add_init_script(
        """() => { Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('SecurityError'); } }); }"""
    )
    page = context.new_page()
    observed = watch(page)
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")

    try:
        page.wait_for_function("() => document.querySelector('.ce-xmas-welcome__dialog')?.matches(':modal')", timeout=4000)
        opened = True
    except Exception:  # noqa: BLE001
        opened = False

    check(opened, "harness(storage-denied): the welcome still opens")
    page.wait_for_timeout(200)
    page.locator(close_button).click()
    page.wait_for_timeout(300)
    state = modal_state(page)
    result["storage_denied"] = {"opened": opened, "after": state, "errors": real_errors(observed)}
    check(state["isModal"] is False, "harness(storage-denied): the close still works")
    check(state["hasOpen"] is False, "harness(storage-denied): the dialog stays closed, never an inline card")
    check(state["state"] == "dismissed", f"harness(storage-denied): the in-memory flag caps this document ({state['state']})")
    check(state["bandVisible"] is False, "harness(storage-denied): nothing is rendered inline")
    check(not real_errors(observed)["pageerror"], "harness(storage-denied): no uncaught error")
    context.close()

    # --- reduced motion, light/dark and the CTA navigation ---
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    page.emulate_media(reduced_motion="reduce")
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")
    page.wait_for_timeout(350)

    motion = page.evaluate(
        """() => ({
            dialog: getComputedStyle(document.querySelector('.ce-xmas-welcome__dialog')).animationName,
            backdrop: getComputedStyle(document.querySelector('.ce-xmas-welcome__dialog'), '::backdrop').animationName,
            transition: getComputedStyle(document.querySelector('.ce-xmas-welcome__dialog')).transitionDuration,
        })"""
    )
    result["reduced_motion"] = motion
    check(motion["dialog"] == "none", "harness: reduced motion removes the entrance animation")
    check(motion["backdrop"] == "none", "harness: reduced motion removes the backdrop animation")

    light = page.evaluate(
        """() => ({
            title: getComputedStyle(document.querySelector('.ce-christmas-hero__title')).color,
            ctaBg: getComputedStyle(document.querySelector('.ce-christmas-hero__cta')).backgroundColor,
            ctaColor: getComputedStyle(document.querySelector('.ce-christmas-hero__cta')).color,
        })"""
    )
    page.evaluate("() => document.documentElement.setAttribute('data-theme', 'dark')")
    page.wait_for_timeout(220)
    dark = page.evaluate(
        """() => ({
            title: getComputedStyle(document.querySelector('.ce-christmas-hero__title')).color,
            ctaBg: getComputedStyle(document.querySelector('.ce-christmas-hero__cta')).backgroundColor,
            ctaColor: getComputedStyle(document.querySelector('.ce-christmas-hero__cta')).color,
        })"""
    )
    result["themes"] = {"light": light, "dark": dark}
    check(light["title"] != dark["title"], "harness: the light and dark palettes differ")
    check(dark["title"] == "rgb(253, 247, 238)", f"harness: dark mode uses the twilight ink ({dark['title']})")
    check(
        light["ctaBg"] == dark["ctaBg"] == "rgb(232, 194, 115)",
        f"harness: the CTA keeps the gold surface in both themes ({light['ctaBg']} / {dark['ctaBg']})",
    )
    check(light["ctaColor"] == "rgb(9, 13, 35)" or light["ctaColor"] == "rgb(8, 12, 34)", f"harness: the CTA label is deep ink on gold ({light['ctaColor']})")
    context.close()

    # CTA navigation on a clean session.
    context = browser.new_context(viewport={"width": 390, "height": 844})
    page = context.new_page()
    page.goto(f"{base}/{names['public']}", wait_until="networkidle")
    page.wait_for_function("() => document.querySelector('.ce-xmas-welcome__dialog')?.matches(':modal')", timeout=4000)
    page.wait_for_timeout(250)
    href = page.get_attribute(".ce-christmas-hero__cta", "href")
    page.click(".ce-christmas-hero__cta")
    page.wait_for_load_state("domcontentloaded")
    result["cta"] = {"href": href, "landed": page.url}
    check(page.url.startswith(href.split("?")[0]), f"harness: the CTA navigates to its landing page ({page.url})")
    context.close()

    # --- image transfer, measured (not assumed) ---
    transfer = {}

    for dpr in (1, 2):
        for label, width, height in (("mobile", 390, 844), ("desktop", 1366, 768)):
            context = browser.new_context(
                viewport={"width": width, "height": height}, device_scale_factor=dpr
            )
            page = context.new_page()
            observed = watch(page)
            page.goto(f"{base}/{names['public']}", wait_until="networkidle")
            page.wait_for_timeout(300)
            requested = sorted(
                {url for url in observed["requests"] if "two-skies-hero" in url}
            )
            sizes = {}

            for url in requested:
                response = context.request.get(url)
                sizes[url.split("/")[-1]] = len(response.body())

            transfer[f"{label}-dpr{dpr}"] = {
                "requests": [url.split("/")[-1] for url in requested],
                "bytes": sizes,
                "count": len(requested),
            }
            check(len(requested) == 1, f"harness(image {label} dpr{dpr}): exactly one panorama derivative is fetched")
            result[f"image_{label}_dpr{dpr}"] = page.evaluate("() => window.__ceLcp")
            context.close()

    result["image_transfer"] = transfer

    return result


def main() -> int:
    """Run every phase and write screenshots + one JSON report."""
    parser = argparse.ArgumentParser(description="Christmas homepage welcome browser verification.")
    parser.add_argument(
        "--out",
        default=os.path.join("docs", "evidence", "2026-11-06-christmas-homepage-popup-fix"),
    )
    args = parser.parse_args()

    host = BASE_URL.split("//", 1)[-1].split("/", 1)[0].split(":")[0]

    if host in PRODUCTION_HOSTS:
        print("REFUSED: this verifier never runs against production.")
        return 2

    out_dir = os.path.abspath(args.out)
    os.makedirs(out_dir, exist_ok=True)

    print(f"base url: {BASE_URL}")
    print(f"evidence: {out_dir}")

    report: dict = {"base_url": BASE_URL, "routes": {}, "phases": {}}

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        report["browser"] = f"chromium {browser.version}"
        print(f"browser: {report['browser']}")

        # A. public routes with no active event.
        for label, path in (("pt", "/"), ("en", "/en/")):
            report["routes"][label] = public_route(browser, label, path, out_dir)

        # B. the landing pages are pages, never the welcome.
        for label, path in (("pt-landing", "/natal/"), ("en-landing", "/en/christmas/")):
            report["routes"][label] = public_route(browser, label, path, out_dir, expect_package_assets=True)

        # C. the signed private preview (real markup).
        context = browser.new_context(viewport={"width": 390, "height": 844})
        page = context.new_page()

        if not login(page):
            print("BLOCKED: could not authenticate against wp-login.php")
            context.close()
            browser.close()
            report["status"] = "blocked"
            with open(os.path.join(out_dir, "browser-verification.json"), "w", encoding="utf-8") as handle:
                json.dump(report, handle, indent=2, ensure_ascii=False)
            return 2

        preview = find_preview_url(page)
        storage_state = context.storage_state()
        context.close()

        if not preview:
            print("BLOCKED: the event editor printed no signed preview link")
            browser.close()
            report["status"] = "blocked"
            with open(os.path.join(out_dir, "browser-verification.json"), "w", encoding="utf-8") as handle:
                json.dump(report, handle, indent=2, ensure_ascii=False)
            return 2

        report["preview_url_shape"] = preview.split("?")[0] + "?conexao_preview=…"
        preview_result = preview_phase(browser, preview, out_dir, storage_state)

        # D. the generated harness (auto-open on real markup).
        names = write_harness(out_dir, preview_result["markup"], preview_result["assets"])
        httpd, port = serve(out_dir)

        try:
            harness = harness_phase(browser, f"http://127.0.0.1:{port}", names, out_dir)
        finally:
            httpd.shutdown()
            httpd.server_close()

        # The markup itself is evidence, but not repeated in the JSON.
        preview_result.pop("markup", None)
        preview_result.pop("assets", None)
        report["phases"]["preview"] = preview_result
        report["phases"]["harness"] = harness
        report["harness_files"] = names

        browser.close()

    report["failures"] = FAILURES
    report["status"] = "pass" if not FAILURES else "fail"

    with open(os.path.join(out_dir, "browser-verification.json"), "w", encoding="utf-8") as handle:
        json.dump(report, handle, indent=2, ensure_ascii=False)

    print(f"\n{report['status'].upper()}: {len(FAILURES)} failure(s)")

    return 0 if not FAILURES else 1


if __name__ == "__main__":
    sys.exit(main())
