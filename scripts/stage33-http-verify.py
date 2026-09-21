#!/usr/bin/env python3
"""
stage33-http-verify.py — Stage 3.3 HTTP verification (local / staging only).

Read-only: performs GET requests and asserts the Stage 3.3 contracts at the
rendered level — redirect precedence, B1/B2 behaviour, canonical, hreflang,
sitemap membership and English homepage navigation links.

Usage:
  python3 scripts/stage33-http-verify.py [base-url]   # default http://localhost:8080

Exit code 0 = every check passed.
"""

import re
import sys
import urllib.error
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080").rstrip("/")

PASS = 0
FAIL = 0
ROWS = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request(path, follow=False):
    opener = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    req = urllib.request.Request(
        BASE + path, headers={"User-Agent": "stage33-http-verify", "Accept-Language": "en"}
    )
    try:
        with opener.open(req, timeout=30) as resp:
            return resp.status, resp.headers, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.headers, exc.read().decode("utf-8", "replace")


def check(condition, label):
    global PASS, FAIL
    if condition:
        PASS += 1
        print(f"  PASS: {label}")
    else:
        FAIL += 1
        print(f"  FAIL: {label}")


def expect_status(path, expected, label):
    status, _, _ = request(path)
    ROWS.append((path, status, "-"))
    check(status == expected, f"{label}: {path} -> {status} (expected {expected})")


def expect_redirect(path, expected_code, expected_target, label):
    status, headers, _ = request(path)
    target = (headers.get("Location") or "").replace(BASE, "")
    ROWS.append((path, status, target))
    check(
        status == expected_code and target == expected_target,
        f"{label}: {path} -> {status} {target} (expected {expected_code} {expected_target})",
    )


def head_links(path):
    """canonical path + hreflang pairs of a rendered page."""
    _, _, body = request(path, follow=True)
    canonical = re.search(r'<link rel="canonical" href="([^"]+)"', body)
    hreflangs = re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', body)
    return (
        body,
        canonical.group(1).replace(BASE, "") if canonical else None,
        [(code, url.replace(BASE, "")) for code, url in hreflangs],
    )


print("== Stage 3.3 - HTTP verification ==")

# ---------------------------------------------------------------------------
print("\n-- Redirect precedence (legacy map still wins) --")
for path, target in (
    ("/jobs/", "/empregos/"),
    ("/about-us/", "/sobre-nos/"),
    ("/contact/", "/contato/"),
    ("/guides/", "/guias/"),
    ("/events/", "/eventos/"),
    ("/courses/", "/cursos/"),
    ("/sponsors/", "/apoiadores/"),
    ("/ireland/", "/irlanda/"),
    ("/about/", "/sobre-nos/"),
):
    expect_redirect(path, 301, target, "legacy 301")

expect_redirect("/en/home/", 301, "/en/", "language home consolidation")

print("\n-- English surfaces: real translations (200) --")
for path in (
    "/",
    "/en/",
    "/en/about-us/",
    "/en/contact/",
    "/en/jobs/",
    "/en/privacy-policy/",
    "/en/terms-of-use/",
    "/en/cookie-policy/",
):
    expect_status(path, 200, "real EN destination")

print("\n-- B1 (302 to Portuguese) / B2 (PT content under EN URL) --")
for path, target in (
    ("/en/blog/", "/blog/"),
    ("/en/moradia/", "/moradia/"),
    ("/en/anuncie/", "/anuncie/"),
    ("/en/europa/", "/europa/"),
):
    expect_redirect(path, 302, target, "B1 untranslated")

for path in ("/en/irlanda/", "/en/dublin/", "/en/cork/", "/en/galway/"):
    expect_status(path, 200, "B2 fallback")

print("\n-- English archives --")
for path in ("/en/guias/", "/en/eventos/", "/en/lazer/", "/en/cursos/", "/en/apoiadores/"):
    expect_status(path, 200, "EN archive")

print("\n-- Canonical / hreflang --")
en_pages = (
    "/en/",
    "/en/about-us/",
    "/en/contact/",
    "/en/jobs/",
    "/en/privacy-policy/",
    "/en/terms-of-use/",
    "/en/cookie-policy/",
    "/en/guias/how-to-get-a-pps-number/",
    "/en/community-celebrates-festa-junina-in-dublin/",
    "/en/eventos/festa-junina-dublin-2026-en/",
    "/en/eventos/irish-dance-workshop-dublin/",
    "/en/lazer/phoenix-park-en/",
    "/en/apoiadores/brasil-market-dublin-en/",
    "/en/cursos/fetch-courses-en/",
    "/en/empregos/kitchen-assistant-dublin/",
)

for path in en_pages:
    _, canonical, hreflangs = head_links(path)
    codes = {code for code, _ in hreflangs}
    check(canonical == path, f"self-canonical: {path} (got {canonical})")
    check("en" in codes, f"hreflang en on {path} (got {sorted(codes)})")

# Translation pairs (a PT master exists) must emit en + pt-BR + x-default.
for path, pt_path in (
    ("/en/", "/"),
    ("/en/about-us/", "/sobre-nos/"),
    ("/en/guias/how-to-get-a-pps-number/", "/guias/como-tirar-o-pps-number/"),
    ("/en/eventos/festa-junina-dublin-2026-en/", "/eventos/festa-junina-dublin-2026/"),
):
    _, canonical, hreflangs = head_links(path)
    pairs = dict(hreflangs)
    check(canonical == path, f"real translation self-canonical: {path}")
    check(pairs.get("pt-BR") == pt_path, f"hreflang pt-BR -> {pt_path} on {path} (got {pairs.get('pt-BR')})")
    check(pairs.get("en") == path, f"hreflang en -> {path}")
    check(pairs.get("x-default") == pt_path, f"hreflang x-default -> {pt_path} on {path}")

for path in ("/en/irlanda/", "/en/dublin/"):
    _, canonical, hreflangs = head_links(path)
    codes = [code for code, _ in hreflangs]
    check(canonical not in ("", path), f"B2 canonical points at the Portuguese URL: {path} -> {canonical}")
    check(codes == ["x-default"], f"B2 emits only x-default hreflang on {path} (got {codes})")

# Source-inherited EN record: no PT counterpart exists, so self-only hreflang
# and a self-canonical are the approved outcome (Stage 3.2 §15).
_, canonical, hreflangs = head_links("/en/eventos/irish-dance-workshop-dublin/")
check(canonical == "/en/eventos/irish-dance-workshop-dublin/", "source-inherited EN event is self-canonical")
check(
    [code for code, _ in hreflangs] == ["en"],
    f"source-inherited EN event emits self-only hreflang=en (got {hreflangs})",
)

# B1 redirect-only destinations never emit hreflang on the redirect response.
status, headers, _ = request("/en/moradia/")
check(status == 302 and "hreflang" not in (headers.get("Link") or "").lower(), "B1 redirect emits no hreflang")

print("\n-- Sitemap --")
_, _, sitemap = request("/sitemap.xml", follow=True)
locs = [loc.replace(BASE, "") for loc in re.findall(r"<loc>([^<]+)</loc>", sitemap)]
check(bool(locs), "sitemap is not empty")
check(
    not [loc for loc in locs if loc.startswith("/en/irlanda/") or loc.startswith("/en/dublin/")],
    "B2 URLs are absent from the sitemap",
)
check(not [loc for loc in locs if loc.startswith("/en/blog/")], "B1-only EN URL is absent from the sitemap")
check(len(locs) == len(set(locs)), "no duplicate sitemap URLs")
for path in (
    "/en/",
    "/en/about-us/",
    "/en/guias/how-to-get-a-pps-number/",
    "/en/eventos/festa-junina-dublin-2026-en/",
    "/en/lazer/phoenix-park-en/",
):
    check(locs.count(path) == 1, f"{path} appears exactly once in the sitemap (got {locs.count(path)})")

print("\n-- English homepage navigation --")
_, _, en_home = request("/en/", follow=True)
_, _, pt_home = request("/", follow=True)

en_hrefs = [url.replace(BASE, "") for url in re.findall(r'href="([^"]+)"', en_home)]
for expected in (
    "/en/eventos/",
    "/en/lazer/",
    "/en/cursos/",
    "/en/apoiadores/",
    "/en/jobs/",
    "/en/privacy-policy/",
    "/en/terms-of-use/",
    "/en/cookie-policy/",
    "/en/guias/?categoria=documents",
):
    check(expected in en_hrefs, f"EN homepage links to {expected}")

for forbidden in (
    "/eventos/",
    "/lazer/",
    "/cursos/",
    "/apoiadores/",
    "/empregos/",
    "/politica-de-privacidade/",
    "/termos-de-uso/",
    "/cookies/",
):
    check(forbidden not in en_hrefs, f"EN homepage no longer links to {forbidden}")

pt_hrefs = [url.replace(BASE, "") for url in re.findall(r'href="([^"]+)"', pt_home)]
for expected in (
    "/eventos/",
    "/lazer/",
    "/cursos/",
    "/apoiadores/",
    "/empregos/",
    "/politica-de-privacidade/",
    "/termos-de-uso/",
    "/cookies/",
    "/blog/",
):
    check(expected in pt_hrefs, f"PT homepage still links to {expected}")

check("/en/eventos/" not in pt_hrefs and "/en/jobs/" not in pt_hrefs, "PT homepage gained no English destination")

print("\n-- Search --")
pt_status, _, pt_search = request("/?s=festa", follow=True)
en_status, _, en_search = request("/en/?s=festa", follow=True)
check(pt_status == 200 and "noindex" in pt_search.lower(), "PT search renders with noindex")
check(en_status == 200 and "noindex" in en_search.lower(), "EN search renders with noindex")

print("\n-- Fetched matrix --")
for path, status, target in ROWS:
    print(f"   {path:45s} {status} {target}")

print(f"\nstage 3.3 http verify: {PASS} passed, {FAIL} failed")
sys.exit(1 if FAIL else 0)
