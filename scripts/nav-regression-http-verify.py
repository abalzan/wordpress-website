#!/usr/bin/env python3
"""
Header/primary-navigation regression — HTTP verification.

Rendered-level companion to
wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php
(logic level) for the regression documented in
CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md.

Checks, per language and per viewport-relevant markup block:

  PT homepage / content page
    - primary nav renders the curated WordPress menu ("Menu Principal") with
      menu-item classes, in the canonical nine-item order
    - NO wp_page_menu page-list fallback (no bare `page_item` <li> in the nav)
  EN homepage / content pages
    - primary nav renders the English menu ("Main Menu") with the canonical
      EN nine-item labels and EN-language destinations
    - no page-list fallback either (safe empty fallback is reserved for
      genuinely unassigned locations, never a substitute for a valid menu)
    - language switcher still exposes PT (link to /) and EN (current)
  Header actions (PT + EN)
    - theme toggle, desktop + mobile search, hamburger toggle, full and
      compact "Anuncie Aqui" CTAs, language switcher

Usage:
  python3 scripts/nav-regression-http-verify.py [BASE_URL]

  BASE_URL defaults to http://localhost:8080

Read-only: performs GET requests only.
"""

import re
import sys
import urllib.request
from html import unescape

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"

passed = 0
failed = 0


def fetch(path: str) -> str:
    with urllib.request.urlopen(BASE + path, timeout=15) as resp:
        return resp.read().decode("utf-8", errors="replace")


def check(label: str, ok: bool, detail: str = "") -> None:
    global passed, failed
    if ok:
        passed += 1
        print(f"  PASS  {label}")
    else:
        failed += 1
        print(f"  FAIL  {label}" + (f" — {detail}" if detail else ""))


def block(html: str, start: str, end: str) -> str:
    i = html.find(start)
    if i < 0:
        return ""
    j = html.find(end, i)
    return html[i : j + len(end)] if j >= 0 else html[i : i + 8000]


NAV_START = '<nav id="site-navigation"'
NAV_END = "</nav>"
MOBILE_NAV_START = '<nav class="mobile-menu-nav"'
MOBILE_NAV_END = "</nav>"

# wp_page_menu emits <li class="page_item page-item-N"> with NO menu-item
# class on the li. WordPress core *also* appends page_item classes to real
# post-type menu items, so the fallback signature is specifically a page_item
# li that lacks menu-item — matched here as a bare `class="page_item` start.
PAGE_LIST_LI = re.compile(r'<li class="page_item[^"]*"\s')

CANONICAL = [
    "Início",
    "Apoiadores",
    "Guias",
    "Eventos",
    "Cursos",
    "Lazer e turismo",
    "Empregos",
    "Blog",
    "Contato",
]

CANONICAL_EN = [
    "Home",
    "Sponsors",
    "Guides",
    "Events",
    "Courses",
    "Leisure & Tourism",
    "Jobs",
    "Blog",
    "Contact",
]


def nav_labels(nav_html: str) -> list:
    anchors = re.findall(r'<a href="[^"]*"[^>]*>([^<]+)</a>', nav_html)
    return [unescape(a.strip()) for a in anchors]


print(f"=== Header/nav regression HTTP verification — {BASE} ===\n")

# --- PT homepage -------------------------------------------------------
html = fetch("/")
nav = block(html, NAV_START, NAV_END)
print("[PT homepage /]")
check("PT home: primary nav renders a wp_nav_menu list", 'id="primary-menu"' in nav)
check("PT home: nav uses menu-item classes", "menu-item " in nav)
check("PT home: no wp_page_menu page-list fallback in nav", not PAGE_LIST_LI.search(nav))
labels = nav_labels(nav)
check(
    "PT home: canonical nine-item curated order",
    labels == CANONICAL,
    f"got {labels}",
)
check("PT home: Início is marked current (aria-current)", bool(re.search(r'<a href="[^"]*"[^>]*aria-current="page"[^>]* class="nav-link">Início</a>', nav)))
check("PT home: language switcher shows PT current + EN link", 'is-current" aria-current="true" lang="pt-BR">PT<' in html and 'hreflang="en"' in html)
check("PT home: mobile drawer nav renders the curated menu", all(t in block(html, MOBILE_NAV_START, MOBILE_NAV_END) for t in CANONICAL))

# --- EN homepage -------------------------------------------------------
html = fetch("/en/")
nav = block(html, NAV_START, NAV_END)
print("\n[EN homepage /en/]")
check("EN home: /en/ renders", bool(html))
check("EN home: primary nav renders a wp_nav_menu list", 'id="primary-menu"' in nav)
check("EN home: nav uses menu-item classes", "menu-item " in nav)
check("EN home: no wp_page_menu page-list fallback in nav", not PAGE_LIST_LI.search(nav))
labels = nav_labels(nav)
check(
    "EN home: canonical nine-item EN order",
    labels == CANONICAL_EN,
    f"got {labels}",
)
check("EN home: Home is marked current (aria-current)", bool(re.search(r'<a href="[^"]*"[^>]*aria-current="page"[^>]* class="nav-link">Home</a>', nav)))
en_urls = re.findall(r'<a href="([^"]*)"', nav)
check(
    "EN home: nav destinations stay in the EN URL space (or approved B1 PT)",
    all(
        u.startswith(BASE)
        and (
            u[len(BASE) :].rstrip("/") in ("/en", "")
            or u[len(BASE) :].startswith("/en/")
            or u[len(BASE) :].rstrip("/") in ("/empregos", "/blog")  # approved B1 destinations
        )
        for u in en_urls
    ),
    f"got {en_urls}",
)
check("EN home: language switcher shows EN current + PT link", 'is-current" aria-current="true" lang="en">EN<' in html and 'hreflang="pt-BR"' in html)
check("EN home: mobile drawer nav renders the EN menu", all(t in unescape(block(html, MOBILE_NAV_START, MOBILE_NAV_END)) for t in CANONICAL_EN))

# --- PT content page ---------------------------------------------------
html = fetch("/contato/")
nav = block(html, NAV_START, NAV_END)
print("\n[PT content page /contato/]")
check("PT content page: curated nav present", "menu-item " in nav and not PAGE_LIST_LI.search(nav))

# --- EN content page ---------------------------------------------------
html = fetch("/en/contact/")
nav = block(html, NAV_START, NAV_END)
print("\n[EN content page /en/contact/]")
check("EN content page: /en/ single renders", bool(html))
check("EN content page: curated EN nav present", "menu-item " in nav and not PAGE_LIST_LI.search(nav))
check("EN content page: EN labels render", all(t in unescape(nav) for t in CANONICAL_EN))
check("EN content page: language switcher shows EN current + PT link", 'is-current" aria-current="true" lang="en">EN<' in html and 'hreflang="pt-BR"' in html)

html = fetch("/en/about-us/")
nav = block(html, NAV_START, NAV_END)
print("\n[EN content page /en/about-us/]")
check("EN content page: /en/about-us/ renders", bool(html))
check("EN content page: no page-list fallback", not PAGE_LIST_LI.search(nav))
check("EN content page: curated EN nav present", "menu-item " in nav)

# --- Header actions (both languages) -----------------------------------
print("\n[Header actions]")
for path in ("/", "/en/"):
    html = fetch(path)
    tag = "PT" if path == "/" else "EN"
    checks = {
        "logo": 'class="logo"' in html,
        "theme toggle": 'class="theme-toggle"' in html,
        "desktop search": "header-search-field" in html,
        "mobile search": "mobile-search-field" in html,
        "hamburger toggle": "mobile-menu-toggle" in html,
        "full Anuncie Aqui CTA": 'class="header-cta"' in html,
        "compact Anuncie Aqui CTA": 'class="header-cta-compact"' in html,
        "drawer Anuncie Aqui CTA": 'class="mobile-menu-cta"' in html,
        "language switcher": "language-switcher" in html,
    }
    for name, ok in checks.items():
        check(f"{tag} {path}: {name} present", ok)

print(f"\n{passed} passed, {failed} failed.\n")
sys.exit(0 if failed == 0 else 1)
