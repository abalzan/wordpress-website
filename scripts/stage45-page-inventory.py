#!/usr/bin/env python3
"""
Stage 4.5 — WordPress Page inventory for the English page-translation rollout
(READ ONLY).

Builds the complete, machine-readable inventory of every published WordPress
Page on https://conexaobr.ie required by the Stage 4.5 task (Phase 0):

  * PT page ID, slug, title, permalink, parent ID/slug, menu order, status,
    template, page depth, front-page / posts-page flags;
  * current Polylang / EN-translation state (none deployed yet — measured);
  * current B1/B2 classification per the repo architecture
    (inc/polylang.php `conexao_b2_page_allowlist()`);
  * SEO metadata state captured from the live HTML head
    (<title>, meta description, canonical, robots, html lang, hreflang, og);
  * Gutenberg-block usage, internal links and external links in the content;
  * sitemap membership (current producer), redirect/shadow state measured
    over HTTP;
  * the Stage 4.5 classification decision (A–H) with reason + evidence, and
    the approved EN plan (slug/title) for every page to translate.

Data sources (all read-only):
  1. Public REST API  GET /wp-json/wp/v2/pages?per_page=100  (full content);
  2. Public HTML      GET /<slug>/                            (head metadata);
  3. Public sitemap   GET /sitemap.xml + first child sitemap;
  4. Repository       page seed (plugins/conexao-content/create-pages.php),
                      legacy redirect map (theme inc/seo.php), B2 page
                      allowlist (theme inc/polylang.php).

GET requests only. No POST/PUT/PATCH/DELETE. No credentials. The WordPress.com
edge rate-limits (429) uncached requests, so the collector paces itself and
caches every capture under --cache-dir (re-runs are incremental).

Usage:
  python3 scripts/stage45-page-inventory.py --out stage45-work/page-inventory
  python3 scripts/stage45-page-inventory.py --offline   # use cached captures only

Author: Stage 4.5 (website-only stage; the Flutter client is out of scope).
"""

import argparse
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE = "https://conexaobr.ie"
UA = "ConexaoBR-Stage45-Inventory/1.0 (read-only)"

# B2 page allowlist (mirror of inc/polylang.php conexao_b2_page_allowlist()).
B2_PAGE_ALLOWLIST = (
    "irlanda", "dublin", "cork", "galway", "limerick",
    "kildare", "meath", "wicklow", "waterford", "laois",
)


# ---------------------------------------------------------------------------
# Reviewed classification table (Phase 1). One entry per PT page slug.
# class: A normal page | B front page | C posts page | D parent/container |
#        E child/subpage | F legal | G previously-B2 directory/info |
#        H technical/system (excluded)
# Every H row carries the evidence that the page is not public editorial
# content. The default rule is PUBLIC PAGE = TRANSLATE.
# ---------------------------------------------------------------------------
CLASSIFICATION = {
    "inicio": {
        "class": "B", "action": "translate", "en_slug": "home", "en_title": "Home",
        "reason": "Static front page (page_on_front).",
        "evidence": "show_on_front=page, page_on_front=9 (Stage 4.3 baseline); /inicio/ 301 -> / measured.",
    },
    "blog": {
        "class": "C", "action": "translate", "en_slug": "blog", "en_title": "Blog",
        "reason": "Posts page (page_for_posts) — a public Page record; task Phase 1.C/Phase 9 require translation.",
        "evidence": "page_for_posts=10 (Stage 4.3 baseline); /blog/ 200 measured.",
        "note": "EN slug intentionally identical to PT ('blog' is the natural English slug); created with the "
                "scoped shared-slug mechanism (Polylang Free has no shared-slug support) so the documented "
                "/en/blog/ URL is kept. Polylang's pagename auto-translate disambiguates by language.",
    },
    "sobre-nos": {
        "class": "A", "action": "translate", "en_slug": "about-us", "en_title": "About Us",
        "reason": "Key narrative page (Stage 3.2 approved set).",
        "evidence": "public page, in footer menu; /sobre-nos/ 200 measured.",
    },
    "contato": {
        "class": "A", "action": "translate", "en_slug": "contact", "en_title": "Contact",
        "reason": "Key narrative page (Stage 3.2 approved set); primary-menu item.",
        "evidence": "public page, primary menu item; /contato/ 200 measured.",
    },
    "newsletter": {
        "class": "A", "action": "translate", "en_slug": "newsletter", "en_title": "Newsletter",
        "reason": "Public newsletter signup page, linked from the footer menu.",
        "evidence": "footer menu item (create-pages.php seed); /newsletter/ 200 measured.",
        "note": "Same-word slug in EN — scoped shared-slug mechanism (see 'blog').",
    },
    "revista": {
        "class": "A", "action": "translate", "en_slug": "digital-magazine", "en_title": "Digital Magazine",
        "reason": "Public editorial page.",
        "evidence": "/revista/ 200 measured.",
    },
    "anuncie": {
        "class": "A", "action": "translate", "en_slug": "advertise", "en_title": "Advertise With Us",
        "reason": "Public advertising page; header CTA destination.",
        "evidence": "/anuncie/ 200 measured; header CTA links /anuncie/ (header.php).",
    },
    "politica-de-privacidade": {
        "class": "F", "action": "translate", "en_slug": "privacy-policy", "en_title": "Privacy Policy",
        "reason": "Legal page (Stage 3.2 approved set).",
        "evidence": "footer menu item; /politica-de-privacidade/ 200 measured.",
    },
    "termos-de-uso": {
        "class": "F", "action": "translate", "en_slug": "terms-of-use", "en_title": "Terms of Use",
        "reason": "Legal page (Stage 3.2 approved set).",
        "evidence": "footer menu item; /termos-de-uso/ 200 measured.",
    },
    "cookies": {
        "class": "F", "action": "translate", "en_slug": "cookie-policy", "en_title": "Cookie Policy",
        "reason": "Legal page (Stage 3.2 approved set).",
        "evidence": "footer menu item; /cookies/ 200 measured.",
    },
    "search": {
        "class": "H", "action": "exclude",
        "reason": "Search utility page: body is a single wp:search block (no editorial content); excluded from "
                  "the sitemap and disallowed in robots.txt by the theme; not linked from any menu or template.",
        "evidence": "inc/seo.php $excluded_pages contains 'search'; robots.txt 'Disallow: /search/' and "
                    "'/en/search/'; content = one search form (2 words); no menu item; 404 template uses "
                    "get_search_form() (/?s=), not this page.",
    },
    "moradia":      {"class": "A", "action": "translate", "en_slug": "housing",      "en_title": "Housing",      "reason": "Public topic hub page.", "evidence": "/moradia/ 200 measured; homepage quick-access destination."},
    "saude":        {"class": "A", "action": "translate", "en_slug": "healthcare",   "en_title": "Healthcare",   "reason": "Public topic hub page.", "evidence": "/saude/ 200 measured; homepage quick-access destination."},
    "familia":      {"class": "A", "action": "translate", "en_slug": "family",       "en_title": "Family",       "reason": "Public topic hub page.", "evidence": "/familia/ 200 measured."},
    "transporte":   {"class": "A", "action": "translate", "en_slug": "transport",    "en_title": "Transport",    "reason": "Public topic hub page.", "evidence": "/transporte/ 200 measured."},
    "financas":     {"class": "A", "action": "translate", "en_slug": "finances",     "en_title": "Finances",     "reason": "Public topic hub page.", "evidence": "/financas/ 200 measured; homepage quick-access destination."},
    "beneficios":   {"class": "A", "action": "translate", "en_slug": "benefits",     "en_title": "Benefits",     "reason": "Public topic hub page.", "evidence": "/beneficios/ 200 measured; homepage quick-access destination."},
    "educacao":     {"class": "A", "action": "translate", "en_slug": "education",    "en_title": "Education",    "reason": "Public topic hub page.", "evidence": "/educacao/ 200 measured."},
    "documentos":   {"class": "A", "action": "translate", "en_slug": "documents",    "en_title": "Documents",    "reason": "Public topic hub page.", "evidence": "/documentos/ 200 measured; homepage quick-access destination."},
    "onde-comer":   {"class": "A", "action": "translate", "en_slug": "where-to-eat", "en_title": "Where to Eat", "reason": "Public topic hub page.", "evidence": "/onde-comer/ 200 measured."},
    "turismo":      {"class": "A", "action": "translate", "en_slug": "tourism",      "en_title": "Tourism",      "reason": "Public topic hub page.", "evidence": "/turismo/ 200 measured."},
    "compras":      {"class": "A", "action": "translate", "en_slug": "shopping",     "en_title": "Shopping",     "reason": "Public topic hub page.", "evidence": "/compras/ 200 measured."},
    "negocios":     {"class": "A", "action": "translate", "en_slug": "business",     "en_title": "Business",     "reason": "Public topic hub page.", "evidence": "/negocios/ 200 measured."},
    "servicos":     {"class": "A", "action": "translate", "en_slug": "services",     "en_title": "Services",     "reason": "Public topic hub page.", "evidence": "/servicos/ 200 measured."},
    "voluntariado": {"class": "A", "action": "translate", "en_slug": "volunteering", "en_title": "Volunteering", "reason": "Public topic hub page.", "evidence": "/voluntariado/ 200 measured."},
    "categorias":   {"class": "A", "action": "translate", "en_slug": "categories",   "en_title": "Categories",   "reason": "Public category index page linking every topic hub + archives.",
                     "evidence": "/categorias/ 200 measured; 18 internal links in content."},
    "irlanda": {
        "class": "G", "action": "translate", "en_slug": "ireland", "en_title": "Ireland for Brazilians",
        "reason": "Country guide hub; previously B2-allowlisted — now gets a real EN translation (B2 retires).",
        "evidence": "conexao_b2_page_allowlist() member; /irlanda/ 200 measured.",
    },
    "europa": {
        "class": "A", "action": "translate", "en_slug": "europe", "en_title": "Europe for Brazilians",
        "reason": "Public editorial page.",
        "evidence": "/europa/ 200 measured.",
    },
    # County pages (class G — previously B2-allowlisted, now real translations).
    "laois":     {"class": "G", "action": "translate", "en_slug": "county-laois",     "en_title": "County Laois",     "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /laois/ 200 measured."},
    "dublin":    {"class": "G", "action": "translate", "en_slug": "county-dublin",    "en_title": "County Dublin",    "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /dublin/ 200 measured."},
    "cork":      {"class": "G", "action": "translate", "en_slug": "county-cork",      "en_title": "County Cork",      "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /cork/ 200 measured."},
    "galway":    {"class": "G", "action": "translate", "en_slug": "county-galway",    "en_title": "County Galway",    "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /galway/ 200 measured."},
    "limerick":  {"class": "G", "action": "translate", "en_slug": "county-limerick",  "en_title": "County Limerick",  "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /limerick/ 200 measured."},
    "kildare":   {"class": "G", "action": "translate", "en_slug": "county-kildare",   "en_title": "County Kildare",   "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /kildare/ 200 measured."},
    "meath":     {"class": "G", "action": "translate", "en_slug": "county-meath",     "en_title": "County Meath",     "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /meath/ 200 measured."},
    "wicklow":   {"class": "G", "action": "translate", "en_slug": "county-wicklow",   "en_title": "County Wicklow",   "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /wicklow/ 200 measured."},
    "waterford": {"class": "G", "action": "translate", "en_slug": "county-waterford", "en_title": "County Waterford", "reason": "County hub; previously B2 — now a real EN translation.", "evidence": "B2 allowlist member; /waterford/ 200 measured."},
    # Technical/system exclusions (class H) — each with evidence.
    "privacidade": {
        "class": "H", "action": "exclude",
        "reason": "Obsolete alias page: its whole body is a 'page moved' notice and the URL is never served — "
                  "the theme issues a permanent 301 /privacidade -> /politica-de-privacidade/ before the page "
                  "can render. Not public editorial content; excluded from the sitemap.",
        "evidence": "inc/seo.php legacy redirect map ('/privacidade' => '/politica-de-privacidade/'); HTTP 301 "
                    "measured 2026-09-23; sitemap $excluded_pages; create-pages.php 'FOOTER REDIRECT PAGES'.",
    },
    "termos": {
        "class": "H", "action": "exclude",
        "reason": "Obsolete alias page (301 /termos -> /termos-de-uso/); body is a 'page moved' notice; "
                  "sitemap-excluded; never rendered.",
        "evidence": "inc/seo.php redirect map; HTTP 301 measured 2026-09-23; sitemap $excluded_pages.",
    },
    "sobre": {
        "class": "H", "action": "exclude",
        "reason": "Obsolete alias page (301 /sobre -> /sobre-nos/); body is a 'page moved' notice; "
                  "sitemap-excluded; never rendered.",
        "evidence": "inc/seo.php redirect map; HTTP 301 measured 2026-09-23; sitemap $excluded_pages.",
    },
    "about": {
        "class": "H", "action": "exclude",
        "reason": "WordPress installer sample page ('This is an example of a page…', links to "
                  "wordpress.com/page/new) — boilerplate placeholder, never site content; additionally "
                  "shadowed: /about/ 301 -> /sobre-nos/ (theme redirect map + .htaccess), so it is unreachable.",
        "evidence": "WP.com default page boilerplate (content); HTTP 301 measured 2026-09-23; inc/seo.php "
                    "'/about' => '/sobre-nos/'; .htaccess 'RewriteRule ^about/?$'.",
    },
    "lazer": {
        "class": "H", "action": "exclude",
        "reason": "Shadowed page: /lazer/ is served by the leisure CPT archive (has_archive='lazer'), so this "
                  "page's own permalink never renders its content — the archive rewrite wins. Creating an EN "
                  "translation would invent an asymmetric /en/leisure/ URL with no reachable PT counterpart. "
                  "The public '/lazer/' destination (the archive) already has the approved /en/lazer/ route.",
        "evidence": "conexao_lang_url() docblock: '/lazer/ is the leisure archive even though a canonical lazer "
                    "page also exists'; /lazer/ HTML measured 2026-09-23 renders the leisure archive grid "
                    "(title 'Lazer & Turismo na Irlanda | Conexão BR'), never the page body.",
    },
    "empregos": {
        "class": "A", "action": "translate", "en_slug": "jobs", "en_title": "Jobs",
        "reason": "Jobs landing page (job CPT archive disabled; the page owns /empregos/ via page-empregos.php). "
                  "Stage 3.2 approved set.",
        "evidence": "template page-empregos.php; /empregos/ 200 measured; primary menu item.",
    },
}

# Translation order (Phase 2): front page first, then top-level pages, then
# children (none exist — all production pages are top-level), then legal.
TRANSLATION_ORDER = [
    "inicio",
    "sobre-nos", "contato", "empregos", "blog",
    "moradia", "saude", "transporte", "familia", "financas", "beneficios",
    "educacao", "documentos", "onde-comer", "turismo", "compras", "negocios",
    "servicos", "voluntariado", "categorias",
    "irlanda", "europa",
    "laois", "dublin", "cork", "galway", "limerick", "kildare", "meath",
    "wicklow", "waterford",
    "newsletter", "revista", "anuncie",
    "politica-de-privacidade", "termos-de-uso", "cookies",
]

# Slugs that keep the same post_name in EN (Polylang-Free shared-slug
# allowlist, applied only while the migration creates exactly these pages).
SHARED_SLUGS = ["blog", "newsletter"]


# ---------------------------------------------------------------------------
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def http_get(url, accept="*/*", retries=4):
    opener = urllib.request.build_opener(NoRedirect)
    for attempt in range(retries):
        req = urllib.request.Request(url)
        req.add_header("User-Agent", UA)
        req.add_header("Accept", accept)
        try:
            with opener.open(req, timeout=30) as resp:
                return resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as exc:
            if exc.code == 429 and attempt < retries - 1:
                time.sleep(6 * (attempt + 1))
                continue
            return exc.code, dict(exc.headers), exc.read()
        except Exception as exc:  # noqa: BLE001
            if attempt < retries - 1:
                time.sleep(4 * (attempt + 1))
                continue
            return 0, {}, str(exc).encode()
    return 0, {}, b""


def cached(cache_dir, name):
    path = os.path.join(cache_dir, name)
    if os.path.exists(path):
        with open(path, "rb") as fh:
            return fh.read()
    return None


def store(cache_dir, name, body):
    os.makedirs(cache_dir, exist_ok=True)
    with open(os.path.join(cache_dir, name), "wb") as fh:
        fh.write(body)

def parse_head(html_text):
    out = {
        "title_tag": None, "meta_description": None, "meta_description_all": [],
        "canonical": None, "robots": None, "html_lang": None,
        "hreflang": [], "og_locale": [], "og_title": None, "og_description": [],
    }
    if not html_text:
        return out
    head_end = html_text.find("</head>")
    head = html_text[: head_end if head_end > 0 else len(html_text)]
    m = re.search(r"<title>(.*?)</title>", head, re.I | re.S)
    if m:
        out["title_tag"] = html.unescape(re.sub(r"\s+", " ", m.group(1)).strip())
    for tag in re.findall(r'<meta[^>]+name=["\']description["\'][^>]*>', head, re.I):
        m2 = re.search(r'content=["\']([^"\']*)["\']', tag, re.I)
        if m2:
            out["meta_description_all"].append(html.unescape(m2.group(1)))
    if out["meta_description_all"]:
        out["meta_description"] = out["meta_description_all"][0]
    m = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]*>', head, re.I)
    if m:
        m2 = re.search(r'href=["\']([^"\']+)["\']', m.group(0), re.I)
        out["canonical"] = m2.group(1) if m2 else None
    m = re.search(r'<meta[^>]+name=["\']robots["\'][^>]*>', head, re.I)
    if m:
        m2 = re.search(r'content=["\']([^"\']*)["\']', m.group(0), re.I)
        out["robots"] = m2.group(1) if m2 else None
    m = re.search(r"<html[^>]*\blang=[\"']([^\"']+)[\"']", head, re.I)
    if m:
        out["html_lang"] = m.group(1)
    for tag in re.findall(r'<link[^>]+rel=["\']alternate["\'][^>]*>', head, re.I):
        hl = re.search(r'hreflang=["\']([^"\']+)["\']', tag, re.I)
        href = re.search(r'href=["\']([^"\']+)["\']', tag, re.I)
        if hl and href:
            out["hreflang"].append([hl.group(1), href.group(1)])
    out["og_locale"] = re.findall(r'property=["\']og:locale["\'][^>]*content=["\']([^"\']+)', head, re.I)
    m = re.search(r'property=["\']og:title["\'][^>]*content=["\']([^"\']*)', head, re.I)
    if m:
        out["og_title"] = html.unescape(m.group(1))
    out["og_description"] = [
        html.unescape(x) for x in re.findall(
            r'property=["\']og:description["\'][^>]*content=["\']([^"\']*)', head, re.I)
    ]
    return out


def content_links(rendered):
    links = re.findall(r'href=["\']([^"\']+)["\']', rendered or "")
    internal, external = [], []
    for link in links:
        if link.startswith("#") or link.startswith("mailto:") or link.startswith("tel:"):
            continue
        if link.startswith("http") and "conexaobr.ie" not in link:
            external.append(link)
        else:
            internal.append(link)
    return internal, external

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--out", default="stage45-work/page-inventory")
    ap.add_argument("--cache-dir", default="stage45-work/inventory-cache")
    ap.add_argument("--offline", action="store_true", help="use cached captures only")
    ap.add_argument("--no-html", action="store_true", help="skip the per-page HTML head capture")
    args = ap.parse_args()

    base = args.base.rstrip("/")
    cache_dir = args.cache_dir

    # ---- 1. Pages via public REST -----------------------------------------
    raw = cached(cache_dir, "pages-rest.json")
    if raw is None:
        if args.offline:
            sys.exit("cache miss pages-rest.json (offline mode)")
        status, headers, raw = http_get(
            base + "/wp-json/wp/v2/pages?per_page=100&_fields="
                   "id,slug,status,parent,menu_order,template,link,title,content,excerpt,featured_media,modified",
            accept="application/json")
        if status != 200:
            sys.exit(f"REST pages fetch failed: HTTP {status}")
        store(cache_dir, "pages-rest.json", raw)
    pages = json.loads(raw)
    pages.sort(key=lambda p: p["id"])

    # ---- 2. Sitemap membership (current producer) --------------------------
    sitemap_urls = set()
    body = cached(cache_dir, "sitemap.xml")
    if body is None and not args.offline:
        status, _, body = http_get(base + "/sitemap.xml")
        if status == 200:
            store(cache_dir, "sitemap.xml", body)
    if body:
        children = re.findall(rb"<loc>([^<]+)</loc>", body)
        if b"<sitemapindex" in body[:600]:
            for child in children:
                child = child.decode()
                if "image" in child:
                    continue
                name = "child-" + child.rsplit("/", 1)[-1]
                cbody = cached(cache_dir, name)
                if cbody is None and not args.offline:
                    cstatus, _, cbody = http_get(child)
                    if cstatus == 200:
                        store(cache_dir, name, cbody)
                    time.sleep(1.0)
                if cbody:
                    sitemap_urls.update(u.decode() for u in re.findall(rb"<loc>([^<]+)</loc>", cbody))
        else:
            sitemap_urls.update(u.decode() for u in children)

    # ---- 3. Front page / posts page ----------------------------------------
    # Public REST does not expose reading settings; the Stage 4.3 authenticated
    # baseline measured page_on_front=9 / page_for_posts=10 (production), which
    # the /inicio/ -> / 301 consolidation confirms.
    front_page_id, posts_page_id = 9, 10

    by_id = {p["id"]: p for p in pages}
    inventory = []
    for page in pages:
        slug = page["slug"]
        cls = CLASSIFICATION.get(slug, {
            "class": "A", "action": "translate",
            "reason": "Public page (default rule: public page = translate).",
            "evidence": "published page reachable via public REST.",
        })
        rendered = page["content"]["rendered"]
        internal, external = content_links(rendered)
        parent = by_id.get(page["parent"])

        head = {}
        if not args.no_html:
            name = "page-" + slug + ".html"
            hbody = cached(cache_dir, name)
            if hbody is None and not args.offline:
                status, _, hbody = http_get(base + "/" + slug + "/", accept="text/html")
                if status == 200 and hbody:
                    store(cache_dir, name, hbody)
                time.sleep(1.0)
            if hbody:
                head = parse_head(hbody.decode("utf-8", "replace"))

        depth = 0
        walker = page
        while walker.get("parent"):
            p = by_id.get(walker["parent"])
            if not p:
                break
            depth += 1
            walker = p

        permalink = page["link"]
        inventory.append({
            "pt_id": page["id"],
            "slug": slug,
            "title": html.unescape(re.sub(r"<[^>]+>", "", page["title"]["rendered"])),
            "permalink": permalink,
            "parent_id": page["parent"],
            "parent_slug": parent["slug"] if parent else None,
            "menu_order": page["menu_order"],
            "status": page["status"],
            "template": page["template"] or "default",
            "depth": depth,
            "is_front_page": page["id"] == front_page_id,
            "is_posts_page": page["id"] == posts_page_id,
            "featured_media": page["featured_media"],
            "modified": page.get("modified"),
            "has_en_translation": False,  # no Polylang on production (measured: /wp-json/pll/v1/* -> 404)
            "polylang_language": None,    # no language layer deployed yet
            "b1_b2_state": (
                "B2-allowlisted (renders PT body under /en/ shell once deployed)"
                if slug in B2_PAGE_ALLOWLIST
                else "B1 (302 -> PT) once the EN layer is deployed; no /en/ exists yet"
            ),
            "gutenberg_blocks": "wp-block" in rendered,
            "word_count": len(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", rendered)).split()),
            "internal_links": internal,
            "external_links": external,
            "sitemap_current": permalink in sitemap_urls,
            "http": {
                "canonical": head.get("canonical"),
                "html_lang": head.get("html_lang"),
                "title_tag": head.get("title_tag"),
                "meta_description": head.get("meta_description"),
                "robots": head.get("robots"),
                "hreflang": head.get("hreflang"),
                "og_locale": head.get("og_locale"),
            } if head else None,
            "classification": cls,
        })


    # ---- 4. Order + summary ------------------------------------------------
    order_idx = {slug: i for i, slug in enumerate(TRANSLATION_ORDER)}
    for row in inventory:
        row["translation_order"] = order_idx.get(row["slug"])

    summary = {
        "total_pages": len(inventory),
        "translate": sum(1 for r in inventory if r["classification"]["action"] == "translate"),
        "exclude": sum(1 for r in inventory if r["classification"]["action"] == "exclude"),
        "by_class": {},
        "b2_allowlist_retired": sum(
            1 for r in inventory
            if r["classification"]["action"] == "translate" and r["classification"]["class"] == "G"),
    }
    for row in inventory:
        c = row["classification"]["class"]
        summary["by_class"][c] = summary["by_class"].get(c, 0) + 1

    out = {
        "generated_by": "scripts/stage45-page-inventory.py (read-only)",
        "base": base,
        "measured_at_note": "HTTP captures dated 2026-09-23; re-run to refresh.",
        "polylang_deployed": False,
        "summary": summary,
        "pages": inventory,
    }

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out + ".json", "w") as fh:
        json.dump(out, fh, ensure_ascii=False, indent=1)

    # ---- Markdown rendering -------------------------------------------------
    lines = [
        "# Stage 4.5 — Page inventory (read-only, measured from production)",
        "",
        f"- Base: {base}",
        f"- Total published pages: {summary['total_pages']}",
        f"- To translate: {summary['translate']}  |  Excluded (technical): {summary['exclude']}",
        "- By class: " + ", ".join(f"{k}={v}" for k, v in sorted(summary["by_class"].items())),
        f"- B2-allowlisted pages retired by a real translation: {summary['b2_allowlist_retired']}",
        "",
        "| # | PT ID | slug | title | class | action | EN slug | template | front | posts | depth | words | int/ext links | sitemap |",
        "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|",
    ]
    for i, row in enumerate(inventory, 1):
        cls = row["classification"]
        lines.append(
            f"| {i} | {row['pt_id']} | `{row['slug']}` | {row['title']} | {cls['class']} | "
            f"{cls['action']} | {cls.get('en_slug', '—')} | {row['template']} | "
            f"{'yes' if row['is_front_page'] else ''} | {'yes' if row['is_posts_page'] else ''} | "
            f"{row['depth']} | {row['word_count']} | {len(row['internal_links'])}/{len(row['external_links'])} | "
            f"{'yes' if row['sitemap_current'] else 'no'} |")
    lines += ["", "## Exclusions (class H) — reason + evidence", ""]
    for row in inventory:
        cls = row["classification"]
        if cls["action"] == "exclude":
            lines.append(f"### `{row['slug']}` (ID {row['pt_id']}) — {row['title']}")
            lines.append(f"- Reason: {cls['reason']}")
            lines.append(f"- Evidence: {cls['evidence']}")
            lines.append("")

    with open(args.out + ".md", "w") as fh:
        fh.write("\n".join(lines))

    print(f"wrote {args.out}.json and {args.out}.md")
    print(json.dumps(summary, indent=1))


if __name__ == "__main__":
    main()


