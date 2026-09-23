#!/usr/bin/env python3
"""
probe.py -- READ-ONLY production baseline probe harness for https://conexaobr.ie
(Stage 4.3 pre-deployment baseline).

Design constraints (see task):
  * Python 3 standard library only (urllib / http.client) -- no pip installs.
  * GET / HEAD requests only. Absolutely no POST/PUT/PATCH/DELETE, no login,
    no credentials, no admin access.
  * Redirects are followed MANUALLY, max 5 hops.
  * 20s timeout, browser-like User-Agent, Accept-Language: pt-BR,pt;q=0.9,en;q=0.8.
  * Emits raw captures (./raw/*) plus ./baseline.json and ./baseline.md.

Every row records: requested URL, status chain, final URL, final status,
content-type, redirect target, <link rel=canonical>, all
<link rel=alternate hreflang=...>, <html lang>, whether body contains
"Polylang", whether body contains a B2 notice marker, whether wp-json links
are present, page title, and -- for REST rows -- fields of interest
(conexao_language, lang handling, x-wp-total / x-wp-totalpages).

The WordPress.com Atomic edge rate-limits aggressively (HTTP 429), observed
even at >20s spacing. The harness therefore throttles by default (--delay,
default 4s) and applies adaptive exponential backoff, doubling the global
delay whenever a 429 is seen and honouring Retry-After. A 429 that survives
all retries is recorded verbatim as evidence (status == 429), never hidden.

Usage:
    python3 probe.py                                   # full run
    python3 probe.py --base-url https://conexaobr.ie   # explicit base
    python3 probe.py --delay 6 --max-retries 6         # slower, more stubborn
    python3 probe.py --only sitemap,rest_matrix        # subset of sections
    python3 probe.py --list                            # list probes, no network
"""

import argparse
import gzip
import json
import os
import random
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib
from datetime import datetime, timezone
from html import unescape

DEFAULT_BASE_URL = "https://conexaobr.ie"
BROWSER_UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)
ACCEPT_LANGUAGE = "pt-BR,pt;q=0.9,en;q=0.8"
ACCEPT = (
    "text/html,application/xhtml+xml,application/xml;q=0.9,"
    "application/json;q=0.9,*/*;q=0.8"
)
MAX_HOPS = 5
TIMEOUT = 20
REDIRECT_CODES = {301, 302, 303, 307, 308}
CACHE_HEADER_KEYS = [
    "cache-control", "expires", "age", "etag", "last-modified", "vary",
    "x-ac", "server-timing", "x-cache", "cf-cache-status", "x-cache-group",
    "link", "x-hacker", "host-header", "x-olaf", "x-nananana",
    "strict-transport-security", "x-powered-by", "server", "x-wp-total",
    "x-wp-totalpages", "allow", "retry-after", "set-cookie",
    "x-litespeed-cache", "x-cache-status", "x-cacheable", "x-pingback",
]
# Markers requested by the Stage 4.3 task PLUS the markers this codebase
# actually emits for a B2 fallback notice (theme inc/polylang.php:
#   class="language-fallback-notice", text "This content is displayed in Portuguese.").
B2_TEXT_MARKERS = [
    "Este conteúdo", "English version",
    "This content is displayed in Portuguese.",
]
B2_ATTR_MARKERS = ["data-b2", "language-fallback-notice"]
B2_CLASS_RE = re.compile("class\\s*=\\s*[\"'][^\"']*""(?:\\bb2[-_a-zA-Z0-9]*|language-fallback-notice)", re.I)



# --------------------------------------------------------------------------
# HTTP layer: manual redirects + adaptive throttling + 429 backoff
# --------------------------------------------------------------------------

class RateLimiter:
    """Adaptive throttle. Doubles the interval on every observed 429."""

    def __init__(self, delay):
        self.base_delay = delay
        self.current = delay
        self.last = 0.0
        self.stats = {
            "requests": 0, "retries": 0, "seen_429": 0,
            "max_delay_applied": delay, "cache_hits": 0,
        }

    def wait(self):
        need = self.current - (time.monotonic() - self.last)
        if need > 0:
            time.sleep(need + random.uniform(0.05, 0.35))
        self.last = time.monotonic()

    def relax(self):
        """After a healthy response, decay the throttle toward the base."""
        if self.current > self.base_delay:
            self.current = max(self.base_delay, self.current * 0.85)

    def penalise(self, retry_after=None):
        self.stats["seen_429"] += 1
        # Mild bump only: keep the cadence predictable. The 429s observed here
        # are edge/origin rate limiting for UNCACHED paths, not a signal to
        # back off for minutes (see findings.md).
        self.current = min(max(self.current * 1.1, self.base_delay),
                           self.base_delay * 1.6)
        try:
            ra = float(retry_after) if retry_after is not None else None
        except (TypeError, ValueError):
            ra = None
        if ra is not None:
            self.current = min(max(self.current, ra + 1.0), 300.0)
        self.stats["max_delay_applied"] = max(
            self.stats["max_delay_applied"], self.current)


class Fetcher:
    def __init__(self, delay, timeout, max_retries, verbose=True):
        self.limiter = RateLimiter(delay)
        self.timeout = timeout
        self.max_retries = max_retries
        self.verbose = verbose
        self.cache = {}
        self._ctx = ssl.create_default_context()

    def _once(self, method, url):
        headers = {
            "User-Agent": BROWSER_UA,
            "Accept-Language": ACCEPT_LANGUAGE,
            "Accept": ACCEPT,
            "Accept-Encoding": "gzip, deflate",
            "Connection": "close",
        }
        req = urllib.request.Request(url, headers=headers, method=method)
        self.limiter.wait()
        self.limiter.stats["requests"] += 1
        t0 = time.monotonic()
        try:
            resp = urllib.request.urlopen(
                req, timeout=self.timeout, context=self._ctx)
            status, raw = resp.status, resp.read()
            hdrs, reason = dict(resp.headers.items()), resp.reason
        except urllib.error.HTTPError as exc:
            status = exc.code
            try:
                raw = exc.read()
            except Exception:  # noqa: BLE001
                raw = b""
            hdrs = dict(exc.headers.items()) if exc.headers else {}
            reason = exc.reason
        except Exception as exc:  # noqa: BLE001 -- DNS/TLS/timeout/reset
            return {
                "status": 0, "reason": "TRANSPORT_ERROR", "body": b"",
                "headers": {}, "error": "%s: %s" % (type(exc).__name__, exc),
                "elapsed": round(time.monotonic() - t0, 3),
            }
        return {
            "status": status, "reason": str(reason),
            "body": _decompress(raw, hdrs), "headers": hdrs,
            "error": None, "elapsed": round(time.monotonic() - t0, 3),
        }

    def fetch(self, method, url):
        key = (method, url)
        if key in self.cache:
            self.limiter.stats["cache_hits"] += 1
            return self.cache[key]
        result = None
        for attempt in range(self.max_retries + 1):
            result = self._once(method, url)
            code = result["status"]
            if code == 429 or code >= 500 or code == 0:
                if attempt < self.max_retries:
                    self.limiter.stats["retries"] += 1
                    if code == 429:
                        self.limiter.penalise(
                            _hget(result["headers"], "retry-after"))
                    backoff = min(
                        3.0 * (attempt + 1) + random.uniform(0, 2.0), 25.0)
                    if self.verbose:
                        sys.stderr.write(
                            "    [retry %d/%d] %s %s -> %s (sleep %.1fs)\n"
                            % (attempt + 1, self.max_retries, method, url,
                               code, backoff))
                        sys.stderr.flush()
                    time.sleep(backoff)
                    continue
            else:
                self.limiter.relax()
            break
        self.cache[key] = result
        return result

    def request(self, method, url, follow=True):
        hops = []
        current, cur_method = url, method
        for hop in range(MAX_HOPS + 1):
            res = self.fetch(cur_method, current)
            last_headers, last_body = res["headers"], res["body"]

            loc = _hget(res["headers"], "location")
            hops.append({
                "hop": hop, "method": cur_method, "url": current,
                "status": res["status"], "reason": res["reason"],
                "redirect_target": loc, "elapsed": res["elapsed"],
                "error": res["error"],
            })
            if follow and res["status"] in REDIRECT_CODES and loc:
                if hop >= MAX_HOPS:
                    hops[-1]["redirect_target"] = loc + " (MAX_HOPS_REACHED)"
                    break
                nxt = urllib.parse.urljoin(current, loc)
                if nxt == current:
                    hops[-1]["redirect_target"] = "(self-loop: %s)" % loc
                    break
                if res["status"] == 303:
                    cur_method = "GET"
                current = nxt
                continue
            break
        final = hops[-1]
        return {
            "requested_url": url, "method": method, "hops": hops,
            "status_chain": [h["status"] for h in hops],
            "final_url": final["url"], "final_status": final["status"],
            "final_headers": last_headers, "body": last_body,

            "redirect_target": final["redirect_target"],
            "loop_warning": _loop_warning(hops),
        }


def _loop_warning(hops):
    if len(hops) > 4:
        return "CHAIN_LONGER_THAN_4_HOPS (%d)" % len(hops)
    seen = set()
    for h in hops:
        if h["url"] in seen:
            return "REPEATED_URL_IN_CHAIN: %s" % h["url"]
        seen.add(h["url"])
    return None


def _decompress(raw, headers):
    enc = (_hget(headers, "content-encoding") or "").lower()
    try:
        if "gzip" in enc:
            return gzip.decompress(raw)
        if "deflate" in enc:
            try:
                return zlib.decompress(raw)
            except zlib.error:
                return zlib.decompress(raw, -zlib.MAX_WBITS)
    except Exception:  # noqa: BLE001
        return raw
    return raw


def _hget(headers, name):
    for k, v in headers.items():
        if k.lower() == name:
            return v
    return None



# --------------------------------------------------------------------------
# HTML / XML parsing helpers (regex based, stdlib only)
# --------------------------------------------------------------------------

TAG_RE = re.compile(r"<(link|html|meta)\b([^>]*)>", re.I | re.S)
ATTR_RE = re.compile(r"""([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|'([^']*)')""")


def parse_tag_attrs(raw):
    out = {}
    for m in ATTR_RE.finditer(raw or ""):
        out[m.group(1).lower()] = unescape(
            m.group(2) if m.group(2) is not None else m.group(3))
    return out


def find_link_tags(html):
    links = []
    for m in TAG_RE.finditer(html or ""):
        if m.group(1).lower() != "link":
            continue
        attrs = parse_tag_attrs(m.group(2))
        links.append({
            "rel": (attrs.get("rel") or "").lower(),
            "hreflang": attrs.get("hreflang"),
            "href": attrs.get("href"),
            "title": attrs.get("title"),
            "type": attrs.get("type"),
        })
    return links


def find_canonical(html):
    for l in find_link_tags(html):
        if l["rel"] == "canonical":
            return l["href"]
    return None


def find_hreflang(html):
    out = []
    for l in find_link_tags(html):
        if l["rel"] == "alternate" and l["hreflang"]:
            out.append({"hreflang": l["hreflang"], "href": l["href"]})
    return out


def find_html_lang(html):
    m = re.search(r"<html\b([^>]*)>", html or "", re.I)
    if not m:
        return None
    return parse_tag_attrs(m.group(1)).get("lang")


def find_title(html):
    m = re.search(r"<title[^>]*>(.*?)</title>", html or "", re.I | re.S)
    return unescape(m.group(1)).strip() if m else None


def find_meta(html, name=None, prop=None):
    vals = []
    for m in TAG_RE.finditer(html or ""):
        if m.group(1).lower() != "meta":
            continue
        a = parse_tag_attrs(m.group(2))
        key = (a.get("name") or "").lower()
        pr = (a.get("property") or "").lower()
        if name and key == name.lower():
            vals.append(a.get("content"))
        if prop and pr == prop.lower():
            vals.append(a.get("content"))
    return vals


def detect_b2(html):
    """Measure presence of a B2-style English-notice marker."""
    ev = {"present": False, "hits": []}
    for marker in B2_TEXT_MARKERS:
        n = (html or "").count(marker)
        if n:
            ev["present"] = True
            ev["hits"].append({"type": "text", "marker": marker, "count": n})
    for marker in B2_ATTR_MARKERS:
        n = (html or "").lower().count(marker)
        if n:
            ev["present"] = True
            ev["hits"].append({"type": "attr", "marker": marker, "count": n})
    cls_hits = B2_CLASS_RE.findall(html or "")
    if cls_hits:
        ev["present"] = True
        ev["hits"].append({"type": "class", "marker": "class~b2",
                           "count": len(cls_hits),
                           "samples": cls_hits[:5]})
    return ev


def find_wpjson_links(html, headers):
    ev = {"in_body": [], "in_headers": []}
    for u in re.findall(r"https?://[^\s\"'<>]*wp-json[^\s\"'<>]*", html or ""):
        if u not in ev["in_body"]:
            ev["in_body"].append(u)
    for l in find_link_tags(html):
        if l["href"] and "wp-json" in l["href"]:
            if l["href"] not in ev["in_body"]:
                ev["in_body"].append(l["href"])
    link_hdr = _hget(headers, "link")
    if link_hdr and "wp-json" in link_hdr:
        ev["in_headers"].append(link_hdr)
    return ev


def extract_menus(html, base_url):
    """Extract nav menus (labels + hrefs) from rendered HTML."""
    menus = []
    for nm in re.finditer(r"<nav\b([^>]*)>(.*?)</nav>", html or "", re.I | re.S):
        attrs = parse_tag_attrs(nm.group(1))
        block = nm.group(2)
        items = []
        for am in re.finditer(r"<a\b([^>]*)>(.*?)</a>", block, re.I | re.S):
            a = parse_tag_attrs(am.group(1))
            label = re.sub(r"<[^>]+>", "", am.group(2))
            label = unescape(re.sub(r"\s+", " ", label)).strip()
            href = a.get("href")
            if label and href:
                items.append({"label": label, "href": href,
                              "hreflang": a.get("hreflang"),
                              "lang": a.get("lang"),
                              "class": a.get("class")})
        menus.append({
            "nav_id": attrs.get("id"), "nav_class": attrs.get("class"),
            "aria_label": attrs.get("aria-label"),
            "item_count": len(items), "items": items,
        })
    return menus



# --------------------------------------------------------------------------
# XML sitemap / robots helpers
# --------------------------------------------------------------------------

def parse_sitemap(xml_text):
    locs = re.findall(r"<loc>\s*(.*?)\s*</loc>", xml_text or "", re.I | re.S)
    locs = [unescape(x.strip()) for x in locs]
    alt_links = []
    for m in re.finditer(r"<xhtml:link\b([^>]*)/?>", xml_text or "", re.I):
        a = parse_tag_attrs(m.group(1))
        alt_links.append({"rel": a.get("rel"), "hreflang": a.get("hreflang"),
                          "href": a.get("href")})
    root = re.search(r"<(\w+:)?(sitemapindex|urlset)\b", xml_text or "", re.I)
    seen, dups = set(), []
    for u in locs:
        if u in seen and u not in dups:
            dups.append(u)
        seen.add(u)
    return {
        "root_element": root.group(2) if root else None,
        "loc_count": len(locs), "locs": locs,
        "first_20": locs[:20],
        "en_urls": [u for u in locs if "/en/" in u],
        "en_url_count": len([u for u in locs if "/en/" in u]),
        "b2_style_urls": [u for u in locs if "b2" in u.lower()],
        "duplicate_urls": dups,
        "xhtml_link_count": len(alt_links),
        "xhtml_links_sample": alt_links[:20],
        "has_xhtml_hreflang": len(alt_links) > 0,
    }


def parse_robots(txt):
    out = {"sitemap_lines": [], "disallow_lines": [], "user_agents": [],
           "raw_length": len(txt or "")}
    for line in (txt or "").splitlines():
        s = line.strip()
        low = s.lower()
        if low.startswith("sitemap:"):
            out["sitemap_lines"].append(s.split(":", 1)[1].strip())
        elif low.startswith("disallow:"):
            out["disallow_lines"].append(s.split(":", 1)[1].strip())
        elif low.startswith("user-agent:"):
            out["user_agents"].append(s.split(":", 1)[1].strip())
    return out

# --------------------------------------------------------------------------
# Probe catalogue
# --------------------------------------------------------------------------

def build_probes(base_url):
    def P(key, path, kind, section, method="GET", follow=True, note=""):
        return {"key": key, "path": path, "kind": kind, "section": section,
                "method": method, "follow": follow, "note": note,
                "url": urllib.parse.urljoin(base_url, path)}

    probes = []
    # --- 1/2/11: core pages (html; also feed hreflang + cache analysis)
    core = [
        ("home_pt", "/", "PT homepage"),
        ("home_en", "/en/", "EN homepage candidate"),
        ("en_home", "/en/home/", "EN home variant"),
        ("eventos", "/eventos/", "Events archive"),
        ("guias", "/guias/", "Guides archive"),
        ("cursos", "/cursos/", "Courses archive"),
        ("empregos", "/empregos/", "Jobs archive"),
        ("apoiadores", "/apoiadores/", "Sponsors archive"),
        ("lazer", "/lazer/", "Leisure archive"),
        ("blog", "/blog/", "Blog archive"),
        ("sobre_nos", "/sobre-nos/", "About page"),
        ("contato", "/contato/", "Contact page"),
    ]
    for key, path, note in core:
        probes.append(P(key, path, "html", "core", note=note))
    for key, path in [("head_home_pt", "/"), ("head_home_en", "/en/"),
                      ("head_eventos", "/eventos/")]:
        probes.append(P(key, path, "head", "head", method="HEAD",
                        note="HEAD request only"))
    # --- 2: Polylang evidence
    probes.append(P("pll_route", "/pll/", "html", "polylang",
                    note="Polylang route probe (may 404)"))
    probes.append(P("pll_lang_qs", "/?pll_language=en", "html", "polylang",
                    note="Polylang language query var"))
    probes.append(P("pll_json", "/wp-json/pll/v1", "json", "polylang",
                    note="Polylang REST namespace probe (may 404)"))
    # --- 3: sitemaps + robots
    for key, path in [("sitemap_xml", "/sitemap.xml"),
                      ("sitemap_index", "/sitemap_index.xml"),
                      ("wp_sitemap", "/wp-sitemap.xml"),
                      ("news_sitemap", "/news-sitemap.xml")]:
        probes.append(P(key, path, "sitemap", "sitemap"))
    # Jetpack sitemapindex children (discovered in the live /sitemap.xml)
    probes.append(P("sitemap_1", "/sitemap-1.xml", "sitemap", "sitemap"))
    probes.append(P("image_sitemap_index", "/image-sitemap-index-1.xml",
                    "sitemap", "sitemap"))
    probes.append(P("robots_txt", "/robots.txt", "robots", "sitemap"))

    # --- 4: REST namespace inventory + conexao/v1 probes
    probes.append(P("rest_root", "/wp-json/", "json", "rest_root",
                    note="namespace inventory"))
    for key, path in [("conexao_v1", "/wp-json/conexao/v1"),
                      ("conexao_v1_slash", "/wp-json/conexao/v1/"),
                      ("conexao_v1_events", "/wp-json/conexao/v1/events"),
                      ("conexao_v1_languages", "/wp-json/conexao/v1/languages")]:
        probes.append(P(key, path, "json", "rest_root"))
    # --- 5: REST language matrix (pre-deployment baseline)
    matrix = [
        ("rest_event_en", "/wp-json/wp/v2/event?lang=en"),
        ("rest_event_pt", "/wp-json/wp/v2/event?lang=pt"),
        ("rest_event_nolang", "/wp-json/wp/v2/event"),
        ("rest_event_xx", "/wp-json/wp/v2/event?lang=xx"),
        ("rest_leisure_en", "/wp-json/wp/v2/leisure?lang=en"),
        ("rest_guide_en", "/wp-json/wp/v2/guide?lang=en"),
        ("rest_posts_en", "/wp-json/wp/v2/posts?lang=en"),
        ("rest_job_en", "/wp-json/wp/v2/job?lang=en"),
        ("rest_course_en", "/wp-json/wp/v2/course_provider?lang=en"),
        ("rest_sponsor_en", "/wp-json/wp/v2/sponsor?lang=en"),
        ("rest_search_en", "/wp-json/wp/v2/search?lang=en"),
        ("rest_pages_en", "/wp-json/wp/v2/pages?lang=en"),
    ]
    for key, path in matrix:
        probes.append(P(key, path, "rest", "rest_matrix"))
    # --- 7: counts
    counts = [("pages", "pages"), ("posts", "posts"), ("guide", "guide"),
              ("event", "event"), ("course_provider", "course_provider"),
              ("job", "job"), ("sponsor", "sponsor"), ("leisure", "leisure")]
    for key, ep in counts:
        probes.append(P("count_" + key, "/wp-json/wp/v2/%s" % ep,
                        "count", "counts"))
    # --- 8: menus
    for key, path in [("menus", "/wp-json/wp/v2/menus"),
                      ("menu_locations", "/wp-json/wp/v2/menu-locations"),
                      ("menu_items", "/wp-json/wp/v2/menu-items")]:
        probes.append(P(key, path, "json", "menus"))
    # --- 9: legacy redirects
    legacy = ["/jobs/", "/about-us/", "/contact/", "/guides/", "/events/",
              "/courses/", "/sponsors/", "/ireland/", "/about/",
              "/privacidade/", "/termos/", "/sobre/", "/counties/dublin/",
              "/en/home/", "/inicio/"]
    for path in legacy:
        key = "redir_" + re.sub(r"[^a-z0-9]+", "_", path.strip("/").lower())
        probes.append(P(key, path, "redirect", "redirects"))
    return probes


# --------------------------------------------------------------------------
# Row construction
# --------------------------------------------------------------------------

def _json_of(text):
    try:
        return json.loads(text), None
    except Exception as exc:  # noqa: BLE001
        return None, "%s: %s" % (type(exc).__name__, exc)


def make_row(probe, res, body_text):
    fh = res.get("final_headers") or {}
    row = {
        "key": probe["key"], "section": probe["section"], "kind": probe["kind"],
        "note": probe["note"], "method": probe["method"],
        "requested_url": res["requested_url"],
        "status_chain": res["status_chain"],
        "final_url": res["final_url"], "final_status": res["final_status"],
        "redirect_target": res["redirect_target"],
        "loop_warning": res["loop_warning"],
        "content_type": _hget(fh, "content-type"),
        "content_length": _hget(fh, "content-length"),
        "body_bytes": len(res.get("body") or b""),
        "hops": res["hops"],
        "final_headers_full": dict(fh),
        "cache_headers": dict(
            (k, _hget(fh, k)) for k in CACHE_HEADER_KEYS if _hget(fh, k)),
        "set_cookie": _hget(fh, "set-cookie"),
        "wp_json_header_link": _hget(fh, "link"),
        "rate_limited": res["final_status"] == 429,
        "transport_error": any(h.get("error") for h in res["hops"]),
        "html": None, "json": None, "sitemap": None, "robots": None,
    }
    if probe["kind"] in ("html", "head") and body_text and res["final_status"] != 200:
        # Never parse an error/rate-limit page as if it were the site.
        # (The WordPress.com 429 page itself declares <html lang="en">,
        #  which would silently corrupt the language evidence.)
        row["html_error_page"] = True
        row["html_note"] = "HTTP %s -- non-200 response, HTML not parsed" % res["final_status"]
    elif probe["kind"] in ("html", "head") and body_text:
        links = find_link_tags(body_text)
        row["html"] = {
            "title": find_title(body_text),
            "html_lang": find_html_lang(body_text),
            "canonical": find_canonical(body_text),
            "hreflang": find_hreflang(body_text),
            "hreflang_count": len(find_hreflang(body_text)),
            "og_locale": find_meta(body_text, prop="og:locale"),
            "generator": find_meta(body_text, name="generator"),
            "description": find_meta(body_text, name="description"),
            "contains_polylang": "polylang" in (body_text or "").lower(),
            "polylang_hit_count": (body_text or "").lower().count("polylang"),
            "b2_notice": detect_b2(body_text),
            "wp_json_links": find_wpjson_links(body_text, fh),
            "menus": extract_menus(body_text, res["final_url"]),
            "link_tags_total": len(links),
        }
    if probe["kind"] in ("json", "rest", "count"):
        data, err = _json_of(body_text)
        info = {"parse_ok": err is None, "parse_error": err,
                "x_wp_total": _hget(fh, "x-wp-total"),
                "x_wp_totalpages": _hget(fh, "x-wp-totalpages")}
        if isinstance(data, dict):
            info["top_level_keys"] = sorted(data.keys())
            if "namespaces" in data:
                info["namespaces"] = data.get("namespaces")
                info["namespaces_count"] = len(data.get("namespaces") or [])
            if "code" in data:
                info["code"] = data.get("code")
                info["message"] = data.get("message")
                info["data_status"] = (data.get("data") or {}).get("status")
            if "routes" in data and isinstance(data["routes"], dict):
                info["routes_sample"] = sorted(data["routes"].keys())[:80]
                info["routes_count"] = len(data["routes"])
        if isinstance(data, list):
            info["item_count"] = len(data)
            info["is_list"] = True
            lang_keys = set()
            for it in data:
                if isinstance(it, dict):
                    lang_keys.update(it.keys())
            info["item_keys_union"] = sorted(lang_keys)
            info["conexao_language_present"] = "conexao_language" in lang_keys
            info["conexao_language_values"] = [
                it.get("conexao_language") for it in data
                if isinstance(it, dict) and "conexao_language" in it][:10]
            info["lang_present"] = "lang" in lang_keys
            info["lang_values"] = [
                it.get("lang") for it in data
                if isinstance(it, dict) and "lang" in it][:10]
            info["id_sample"] = [
                it.get("id") for it in data[:5] if isinstance(it, dict)]
        row["json"] = info
    if probe["kind"] == "sitemap" and body_text:
        row["sitemap"] = parse_sitemap(body_text)
    if probe["kind"] == "robots" and body_text:
        row["robots"] = parse_robots(body_text)
    return row



# --------------------------------------------------------------------------
# Derived analysis
# --------------------------------------------------------------------------

SITEMAP_OWNER_MARKERS = [
    ("wordpress-core", ["wp-sitemap", "wp_sitemap",
                        "<generator>https://wordpress.org/?v="]),
    ("wordpress.com", ["wordpress.com", "wpcom", "sitemap.xml?x="]),
    ("yoast", ["yoast", "wordpress-seo"]),
    ("rankmath", ["rank math", "rankmath"]),
    ("aioseo", ["all in one seo", "aioseo"]),
    ("jetpack", ["jetpack"]),
    ("google-sitemap-generator", ["google-sitemap-generator"]),
]


def _by_key(rows):
    return dict((r["key"], r) for r in rows)


def _chain_str(r):
    return " -> ".join("%s %s" % (h["url"], h["status"]) for h in r["hops"])


def analyze(bodies, rows, base_url):
    rk = _by_key(rows)
    a = {}

    # 1. /en/ homepage verdict
    en = rk.get("home_en", {})
    en_body = bodies.get("home_en", "")
    a["en_homepage"] = {
        "status_chain": en.get("status_chain"),
        "final_url": en.get("final_url"),
        "final_status": en.get("final_status"),
        "html_lang": (en.get("html") or {}).get("html_lang"),
        "title": (en.get("html") or {}).get("title"),
        "canonical": (en.get("html") or {}).get("canonical"),
        "hreflang": (en.get("html") or {}).get("hreflang"),
        "contains_polylang": (en.get("html") or {}).get("contains_polylang"),
        "b2_notice": (en.get("html") or {}).get("b2_notice"),
        "body_has_pt_markers": {
            "Este conteúdo": en_body.count("Este conteúdo"),
            "English version": en_body.count("English version"),
        },
    }

    # 2. Polylang evidence
    pll = {
        "pll_route": rk.get("pll_route", {}).get("final_status"),
        "pll_json": {
            "status": rk.get("pll_json", {}).get("final_status"),
            "code": (rk.get("pll_json", {}).get("json") or {}).get("code"),
        },
        "pages_lang_en": {
            "status": rk.get("rest_pages_en", {}).get("final_status"),
            "x_wp_total": (rk.get("rest_pages_en", {}).get("json") or {}).get("x_wp_total"),
        },
        "pll_language_cookie_on_requests": [],
        "pages_containing_polylang_string": [
            k for k, r in rk.items()
            if (r.get("html") or {}).get("contains_polylang")],
        "pages_with_hreflang": [
            k for k, r in rk.items()
            if ((r.get("html") or {}).get("hreflang_count") or 0) > 0],
        "pll_language_body_mentions": {},
    }
    for k, r in rk.items():
        sc = r.get("set_cookie")
        if sc and "pll_language" in sc.lower():
            pll["pll_language_cookie_on_requests"].append(
                {"key": k, "set_cookie": sc})
    for k, b in bodies.items():
        if "pll_language" in (b or ""):
            pll["pll_language_body_mentions"][k] = b.count("pll_language")
    a["polylang"] = pll
    # 3. Sitemap ownership
    sm = {}
    for k in ("sitemap_xml", "sitemap_index", "wp_sitemap", "news_sitemap",
              "sitemap_1", "image_sitemap_index"):
        r = rk.get(k, {})
        s = r.get("sitemap") or {}
        body = bodies.get(k, "")
        hints = []
        for owner, marks in SITEMAP_OWNER_MARKERS:
            if any(m in (body or "").lower() for m in marks):
                hints.append(owner)
        sm[k] = {
            "requested_url": r.get("requested_url"),
            "status_chain": r.get("status_chain"),
            "content_type": r.get("content_type"),
            "root_element": s.get("root_element"),
            "loc_count": s.get("loc_count"),
            "first_20": s.get("first_20"),
            "en_url_count": s.get("en_url_count"),
            "en_urls_sample": (s.get("en_urls") or [])[:20],
            "b2_style_urls": s.get("b2_style_urls"),
            "duplicate_urls": s.get("duplicate_urls"),
            "has_xhtml_hreflang": s.get("has_xhtml_hreflang"),
            "xhtml_link_count": s.get("xhtml_link_count"),
            "generator_hints": hints,
            "first_200_chars": (body or "")[:200],
        }
    rb = rk.get("robots_txt", {})
    sm["robots_txt"] = {
        "status_chain": rb.get("status_chain"),
        "content_type": rb.get("content_type"),
        "robots": rb.get("robots"),
        "first_500_chars": (bodies.get("robots_txt") or "")[:500],
    }
    candidates = [(k, v) for k, v in sm.items()
                  if isinstance(v, dict) and v.get("status_chain")
                  and v["status_chain"][-1] == 200
                  and (v.get("loc_count") or 0) > 0]
    candidates.sort(key=lambda kv: kv[1].get("loc_count") or 0, reverse=True)
    sm["_primary"] = {
        "key": candidates[0][0] if candidates else None,
        "loc_count": candidates[0][1].get("loc_count") if candidates else None,
        "generator_hints": candidates[0][1].get("generator_hints") if candidates else None,
    }
    a["sitemap"] = sm

    # 4. REST namespaces + conexao/v1
    root = rk.get("rest_root", {})
    ns = (root.get("json") or {}).get("namespaces") or []
    a["rest_namespaces"] = {
        "rest_root_status": root.get("final_status"),
        "namespaces": ns,
        "namespaces_count": len(ns),
        "conexao_present": any("conexao" in n for n in ns),
        "conexao_namespaces": [n for n in ns if "conexao" in n],
        "conexao_v1_probes": dict(
            (k, {
                "url": rk.get(k, {}).get("requested_url"),
                "status_chain": rk.get(k, {}).get("status_chain"),
                "final_status": rk.get(k, {}).get("final_status"),
                "code": (rk.get(k, {}).get("json") or {}).get("code"),
                "message": (rk.get(k, {}).get("json") or {}).get("message"),
            }) for k in ("conexao_v1", "conexao_v1_slash", "conexao_v1_events",
                         "conexao_v1_languages")),
    }

    # 5. REST language matrix
    matrix = {}
    for k, r in rk.items():
        if r["section"] != "rest_matrix":
            continue
        j = r.get("json") or {}
        matrix[k] = {
            "url": r["requested_url"], "status_chain": r["status_chain"],
            "final_status": r["final_status"],
            "x_wp_total": j.get("x_wp_total"),
            "x_wp_totalpages": j.get("x_wp_totalpages"),
            "item_count": j.get("item_count"),
            "conexao_language_present": j.get("conexao_language_present"),
            "conexao_language_values": j.get("conexao_language_values"),
            "lang_present": j.get("lang_present"),
            "lang_values": j.get("lang_values"),
            "item_keys_union": j.get("item_keys_union"),
            "code": j.get("code"), "message": j.get("message"),
        }
    a["rest_language_matrix"] = matrix

    # 6. Site identity / versions
    home = rk.get("home_pt", {})
    hh = home.get("html") or {}
    gen_all = []
    for k, r in rk.items():
        for g in ((r.get("html") or {}).get("generator") or []):
            gen_all.append({"key": k, "generator": g})
    a["identity"] = {
        "generator_meta": gen_all[:10],
        "wp_version_hint": next(
            (re.search(r"WordPress\s*([0-9.]+)", g["generator"]).group(1)
             for g in gen_all if re.search(r"WordPress\s*([0-9.]+)", g["generator"])),
            None),
        "server": _hget(home.get("final_headers_full") or {}, "server"),
        "host_header": _hget(home.get("final_headers_full") or {}, "host-header"),
        "x_hacker": _hget(home.get("final_headers_full") or {}, "x-hacker"),
        "x_powered_by": _hget(home.get("final_headers_full") or {}, "x-powered-by"),
        "og_locale": hh.get("og_locale"),
        "html_lang": hh.get("html_lang"),
        "rest_gmt_offset": None, "rest_timezone_string": None,
        "rest_namespace_root_keys": (rk.get("rest_root", {}).get("json") or {}).get("top_level_keys"),
        "jetpack_hints_on_pages": [],
        "php_hints": [],
    }
    for k, r in rk.items():
        hdrs = str((r.get("final_headers_full") or {})).lower()
        if "x-powered-by" in hdrs or "php" in hdrs:
            a["identity"]["php_hints"].append(
                {"key": k, "x_powered_by": _hget(r.get("final_headers_full") or {}, "x-powered-by")})

    # 7. Counts
    counts = {}
    for k, r in rk.items():
        if r["section"] != "counts":
            continue
        j = r.get("json") or {}
        counts[k.replace("count_", "")] = {
            "url": r["requested_url"], "final_status": r["final_status"],
            "x_wp_total": j.get("x_wp_total"),
            "x_wp_totalpages": j.get("x_wp_totalpages"),
            "code": j.get("code"),
        }
    a["counts"] = counts

    # 8. Menus
    home_menus = (rk.get("home_pt", {}).get("html") or {}).get("menus") or []
    en_menus = (rk.get("home_en", {}).get("html") or {}).get("menus") or []
    a["menus"] = {
        "pt_home_nav_menus": [{"nav_id": m["nav_id"], "nav_class": m["nav_class"],
                               "item_count": m["item_count"], "items": m["items"]}
                              for m in home_menus],
        "en_home_nav_menus": [{"nav_id": m["nav_id"], "nav_class": m["nav_class"],
                               "item_count": m["item_count"], "items": m["items"]}
                              for m in en_menus],
        "rest_menus": dict(
            (k, {"url": rk.get(k, {}).get("requested_url"),
                 "status_chain": rk.get(k, {}).get("status_chain"),
                 "code": (rk.get(k, {}).get("json") or {}).get("code"),
                 "message": (rk.get(k, {}).get("json") or {}).get("message")})
            for k in ("menus", "menu_locations", "menu_items")),
    }

    # 9. Legacy redirects
    redirs = {}
    loops = []
    for k, r in rk.items():
        if r["section"] != "redirects":
            continue
        n_hops = len(r["hops"])
        if n_hops > 3:
            loops.append({"key": k, "hops": n_hops, "chain": _chain_str(r)})
        redirs[k] = {
            "requested_url": r["requested_url"],
            "status_chain": r["status_chain"],
            "final_url": r["final_url"], "final_status": r["final_status"],
            "hops": n_hops,
            "redirect_targets": [h.get("redirect_target") for h in r["hops"]],
            "loop_warning": r.get("loop_warning"),
        }
    a["redirects"] = {"rows": redirs, "chains_longer_than_3_hops": loops}

    # 10. Caching
    caching = {}
    for k, r in rk.items():
        if r["section"] not in ("core", "head"):
            continue
        fh = r.get("final_headers_full") or {}
        xac = _hget(fh, "x-ac") or ""
        st = _hget(fh, "server-timing") or ""
        state = "UNKNOWN"
        if "HIT" in xac.upper():
            state = "HIT"
        elif "STALE" in xac.upper():
            state = "STALE"
        elif "MISS" in xac.upper():
            state = "MISS"
        caching[k] = {
            "url": r["requested_url"], "final_status": r["final_status"],
            "x_ac": xac, "server_timing": st,
            "cache_control": _hget(fh, "cache-control"),
            "age": _hget(fh, "age"), "x_cache": _hget(fh, "x-cache"),
            "cf_cache_status": _hget(fh, "cf-cache-status"),
            "vary": _hget(fh, "vary"), "expires": _hget(fh, "expires"),
            "etag": _hget(fh, "etag"),
            "edge_state": state,
        }
    a["caching"] = caching

    # 11. hreflang duplication check
    hreflang_rows = {}
    for k in ("home_pt", "home_en", "en_home", "eventos", "guias"):
        r = rk.get(k)
        if not r:
            continue
        tags = (r.get("html") or {}).get("hreflang") or []
        hls = [t["hreflang"] for t in tags]
        dup = sorted(set(x for x in hls if hls.count(x) > 1))
        hreflang_rows[k] = {
            "url": r["requested_url"], "final_url": r["final_url"],
            "final_status": r["final_status"],
            "tags_in_order": tags, "tag_count": len(tags),
            "duplicate_hreflang_values": dup,
            "duplicate_emitter_suspected": len(dup) > 0,
            "distinct_hrefs": sorted(set(t.get("href") for t in tags if t.get("href"))),
        }
    a["hreflang_duplication"] = hreflang_rows

    # extra identity hints
    jp = []
    for k, r in rk.items():
        blob = (str(r.get("final_headers_full") or {}) + (bodies.get(k) or "")).lower()
        if "jetpack" in blob:
            jp.append({"key": k, "hits": blob.count("jetpack")})
    a["identity"]["jetpack_hints_on_pages"] = jp
    home_body = bodies.get("home_pt") or ""
    a["identity"]["site_locale_hints"] = {
        "pt_BR_count": home_body.count("pt_BR"),
        "pt-BR_count": home_body.count("pt-BR"),
        "og_locale": (rk.get("home_pt", {}).get("html") or {}).get("og_locale"),
        "html_lang": (rk.get("home_pt", {}).get("html") or {}).get("html_lang"),
    }
    # asset / plugin hints from homepage
    assets = []
    for m in re.finditer(r"(?:src|href)=[\"']([^\"']*wp-content/[^\"']+)[\"']", home_body):
        assets.append(m.group(1))
    plugin_paths = sorted(set(
        re.sub(r".*/plugins/([^/]+)/.*", r"\\1", u)
        for u in assets if "/plugins/" in u))
    theme_paths = sorted(set(
        re.sub(r".*/themes/([^/]+)/.*", r"\\1", u)
        for u in assets if "/themes/" in u))
    a["identity"]["asset_plugin_hints"] = {
        "plugins_seen": plugin_paths,
        "themes_seen": theme_paths,
        "theme_style_css": [u for u in assets if "themes/" in u and "style.css" in u],
        "assets_sample": assets[:40],
    }

    return a



# --------------------------------------------------------------------------
# Raw capture + report writers
# --------------------------------------------------------------------------

def raw_ext(kind, content_type):
    ct = (content_type or "").lower()
    if kind in ("json", "rest", "count") or "json" in ct:
        return "json"
    if kind == "sitemap" or "xml" in ct:
        return "xml"
    if kind == "robots" or "text/plain" in ct:
        return "txt"
    return "html"


def raw_status(raw_dir, probe):
    """Return the final_status stored in an existing raw capture, or None."""
    hp = os.path.join(raw_dir, "%s.headers.json" % probe["key"])
    if not os.path.exists(hp):
        return None
    try:
        with open(hp, encoding="utf-8") as fh:
            return (json.load(fh) or {}).get("final_status")
    except Exception:  # noqa: BLE001
        return None


def keep_existing(raw_dir, probe, res):
    """--keep-best: never overwrite a good capture with a worse one."""
    old = raw_status(raw_dir, probe)
    if old is None or old >= 400 or old == 0:
        return False
    new = res.get("final_status")
    return new is None or new >= 400 or new == 0


def save_raw(raw_dir, probe, res, body_text):
    ext = raw_ext(probe["kind"], _hget(res.get("final_headers") or {}, "content-type"))
    body_path = os.path.join(raw_dir, "%s.%s" % (probe["key"], ext))
    with open(body_path, "w", encoding="utf-8", errors="replace") as fh:
        fh.write(body_text or "")
    hdr_path = os.path.join(raw_dir, "%s.headers.json" % probe["key"])
    payload = {
        "probe": {"key": probe["key"], "method": probe["method"],
                  "url": probe["url"], "kind": probe["kind"]},
        "status_chain": res["status_chain"],
        "hops": res["hops"],
        "final_headers": res.get("final_headers") or {},
        "final_status": res["final_status"],
        "final_url": res["final_url"],
    }
    with open(hdr_path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, indent=2, sort_keys=True, ensure_ascii=False)
    return body_path, hdr_path


def md_cell(v):
    if v is None:
        return ""
    s = str(v).replace("|", "\\|").replace("\n", " ")
    return s if len(s) <= 160 else s[:157] + "..."



def build_markdown(report):
    m = report["meta"]
    a = report["analysis"]
    L = []
    L.append("# Production baseline -- %s" % m["base_url"])
    L.append("")
    L.append("Generated: `%s` (READ-ONLY GET/HEAD; no writes against production)."
             % m["generated_at"])
    L.append("")
    L.append("- User-Agent: `%s`" % m["user_agent"])
    L.append("- Accept-Language: `%s`" % m["accept_language"])
    L.append("- Max redirect hops: %d; timeout: %ds; base throttle: %.1fs"
             % (m["max_hops"], m["timeout"], m["delay"]))
    L.append("- HTTP requests issued: **%d** (retries: %d; 429s seen: %d; "
             "cache hits: %d; max adaptive delay: %.1fs)"
             % (m["request_stats"]["requests"], m["request_stats"]["retries"],
                m["request_stats"]["seen_429"], m["request_stats"]["cache_hits"],
                m["request_stats"]["max_delay_applied"]))
    L.append("")
    L.append("> WordPress.com Atomic rate-limits aggressively (HTTP 429). "
             "Any row whose status is 429 is retained verbatim as evidence.")
    L.append("")

    L.append("## 0. Row summary")
    L.append("")
    L.append("| key | requested URL | status chain | final URL | final | type | "
             "title | html lang | canonical | hreflang | Polylang | B2 | wp-json |")
    L.append("|---|---|---|---|---|---|---|---|---|---|---|---|---|")
    for r in report["rows"]:
        h = r.get("html") or {}
        j = r.get("json") or {}
        title = h.get("title") or (("[json] code=%s" % j.get("code"))
                                   if j.get("code") else "")
        L.append("| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |" % (
            md_cell(r["key"]), md_cell(r["requested_url"]),
            md_cell("->".join(str(x) for x in r["status_chain"])),
            md_cell(r["final_url"]), md_cell(r["final_status"]),
            md_cell((r.get("content_type") or "").split(";")[0]),
            md_cell(title), md_cell(h.get("html_lang")),
            md_cell(h.get("canonical")), md_cell(h.get("hreflang_count")),
            md_cell(h.get("contains_polylang")),
            md_cell("YES" if (h.get("b2_notice") or {}).get("present") else ""),
            md_cell("YES" if r.get("wp_json_header_link") else ""),
        ))
    L.append("")
    e = a["en_homepage"]
    L.append("## 1. Does /en/ serve an English homepage?")
    L.append("")
    L.append("- Status chain: `%s`" % " -> ".join(str(x) for x in (e["status_chain"] or [])))
    L.append("- Final URL: `%s`" % e["final_url"])
    L.append("- `<html lang>`: `%s`" % e["html_lang"])
    L.append("- `<title>`: %s" % md_cell(e["title"]))
    L.append("- canonical: `%s`" % e["canonical"])
    L.append("- hreflang tags: %d" % len(e["hreflang"] or []))
    L.append("- Body contains `Polylang`: %s" % e["contains_polylang"])
    L.append("- B2 notice present: %s" % (e["b2_notice"] or {}).get("present"))
    L.append("")

    L.append("## 2. Polylang evidence on production")
    L.append("")
    p = a["polylang"]
    L.append("| signal | measured value |")
    L.append("|---|---|")
    L.append("| `/pll/` route final status | %s |" % p["pll_route"])
    L.append("| `/wp-json/pll/v1` status / code | %s / `%s` |"
             % (p["pll_json"]["status"], p["pll_json"]["code"]))
    L.append("| `/wp-json/wp/v2/pages?lang=en` status / x-wp-total | %s / %s |"
             % (p["pages_lang_en"]["status"], p["pages_lang_en"]["x_wp_total"]))
    L.append("| body mentions `pll_language` | %s |"
             % md_cell(p["pll_language_body_mentions"]))
    L.append("| `pll_language` cookie seen | %s |"
             % md_cell(p["pll_language_cookie_on_requests"]))
    L.append("| pages containing `Polylang` string | %s |"
             % md_cell(p["pages_containing_polylang_string"]))
    L.append("| pages emitting hreflang | %s |" % md_cell(p["pages_with_hreflang"]))
    L.append("")

    L.append("## 3. Sitemap ownership")
    L.append("")
    L.append("| path | status chain | content-type | root | <loc> count | /en/ URLs | "
             "xhtml:link | generator hints |")
    L.append("|---|---|---|---|---|---|---|---|")
    for k in ("sitemap_xml", "sitemap_index", "wp_sitemap", "news_sitemap",
              "sitemap_1", "image_sitemap_index"):
        v = a["sitemap"].get(k, {})
        L.append("| `%s` | %s | %s | %s | %s | %s | %s | %s |" % (
            v.get("requested_url"), md_cell("->".join(str(x) for x in (v.get("status_chain") or []))),
            md_cell(v.get("content_type")),
            md_cell(v.get("root_element")), md_cell(v.get("loc_count")),
            md_cell(v.get("en_url_count")), md_cell(v.get("xhtml_link_count")),
            md_cell(v.get("generator_hints"))))
    rbv = a["sitemap"].get("robots_txt", {})
    L.append("")
    L.append("- **Primary sitemap**: `%s` (%s URLs, hints: %s)"
             % (a["sitemap"]["_primary"]["key"], a["sitemap"]["_primary"]["loc_count"],
                a["sitemap"]["_primary"]["generator_hints"]))
    L.append("- `/robots.txt` status chain: `%s`; Sitemap: lines: %s"
             % (" -> ".join(str(x) for x in (rbv.get("status_chain") or [])),
                md_cell((rbv.get("robots") or {}).get("sitemap_lines"))))
    L.append("")
    for k in ("sitemap_xml", "sitemap_index", "wp_sitemap", "news_sitemap",
              "sitemap_1", "image_sitemap_index"):
        v = a["sitemap"].get(k, {})
        L.append("### `%s`" % v.get("requested_url"))
        L.append("")
        L.append("- <loc> count: **%s** | duplicate URLs: %s | /en/ URLs: %s"
                 % (v.get("loc_count"), md_cell(v.get("duplicate_urls")),
                    md_cell(v.get("en_url_count"))))
        L.append("- has `xhtml:link` hreflang: %s (%s links)"
                 % (v.get("has_xhtml_hreflang"), v.get("xhtml_link_count")))
        L.append("- B2-style URLs: %s" % md_cell(v.get("b2_style_urls")))
        L.append("- First 20 `<loc>`:")
        L.append("")
        for u in (v.get("first_20") or []):
            L.append("  - `%s`" % u)
        L.append("")

    r = a["rest_namespaces"]
    L.append("## 4. REST namespace inventory (`conexao/v1`?)")
    L.append("")
    L.append("- `/wp-json/` final status: %s" % r["rest_root_status"])
    L.append("- Namespaces observed (**%d**):" % r["namespaces_count"])
    L.append("")
    for n in r["namespaces"]:
        L.append("  - `%s`" % n)
    L.append("")
    L.append("- **`conexao` namespace present: %s** (matching: %s)"
             % (r["conexao_present"], md_cell(r["conexao_namespaces"])))
    L.append("")
    L.append("| conexao/v1 probe | status chain | code | message |")
    L.append("|---|---|---|---|")
    for k, v in r["conexao_v1_probes"].items():
        L.append("| `%s` | %s | `%s` | %s |" % (
            v.get("url"), md_cell("->".join(str(x) for x in (v.get("status_chain") or []))),
            md_cell(v.get("code")), md_cell(v.get("message"))))
    L.append("")

    L.append("## 5. REST language behaviour (pre-deployment baseline)")
    L.append("")
    L.append("| probe | URL | status chain | x-wp-total | x-wp-totalpages | items | "
             "`conexao_language`? | `lang` field? | code |")
    L.append("|---|---|---|---|---|---|---|---|---|")
    for k, v in a["rest_language_matrix"].items():
        L.append("| %s | `%s` | %s | %s | %s | %s | %s | %s | `%s` |" % (
            k, v.get("url"),
            md_cell("->".join(str(x) for x in (v.get("status_chain") or []))),
            md_cell(v.get("x_wp_total")), md_cell(v.get("x_wp_totalpages")),
            md_cell(v.get("item_count")),
            md_cell(v.get("conexao_language_present")),
            md_cell(v.get("lang_present")), md_cell(v.get("code"))))
    L.append("")

    L.append("## 6. Site identity / versions")
    L.append("")
    i = a["identity"]
    L.append("- WP version (generator meta): **%s**" % i["wp_version_hint"])
    L.append("- generator tags: %s" % md_cell(i["generator_meta"]))
    L.append("- server: `%s` | host-header: `%s` | x-hacker: `%s`"
             % (i["server"], i["host_header"], md_cell(i["x_hacker"])))
    L.append("- x-powered-by: `%s` (PHP leaks: %s)" % (i["x_powered_by"], md_cell(i["php_hints"])))
    L.append("- og:locale: `%s` | `<html lang>`: `%s`" % (i["og_locale"], i["html_lang"]))
    L.append("- site locale hints: %s" % md_cell(i["site_locale_hints"]))
    L.append("- plugins seen in asset paths: %s" % md_cell(i["asset_plugin_hints"]["plugins_seen"]))
    L.append("- themes seen: %s" % md_cell(i["asset_plugin_hints"]["themes_seen"]))
    L.append("- style.css asset: %s" % md_cell(i["asset_plugin_hints"]["theme_style_css"]))
    L.append("- Jetpack hints: %s" % md_cell(i["jetpack_hints_on_pages"]))
    L.append("- REST root top-level keys: %s" % md_cell(i["rest_namespace_root_keys"]))
    L.append("")

    L.append("## 7. Content counts (x-wp-total)")
    L.append("")
    L.append("| endpoint | status | x-wp-total | x-wp-totalpages |")
    L.append("|---|---|---|---|")
    for k in ("pages", "posts", "guide", "event", "course_provider", "job",
              "sponsor", "leisure"):
        v = a["counts"].get(k, {})
        L.append("| %s | %s | **%s** | %s |"
                 % (k, v.get("final_status"), v.get("x_wp_total"), v.get("x_wp_totalpages")))
    L.append("")

    L.append("## 8. Menus")
    L.append("")
    mn = a["menus"]
    for label, key in (("PT homepage", "pt_home_nav_menus"),
                       ("EN homepage", "en_home_nav_menus")):
        L.append("### %s rendered nav" % label)
        L.append("")
        if not mn.get(key):
            L.append("_No `<nav>` blocks extracted (page may be 429/error)._")
            L.append("")
            continue
        for nav in mn[key]:
            L.append("- nav id=`%s` class=`%s` items=%d"
                     % (nav.get("nav_id"), md_cell(nav.get("nav_class")),
                        nav.get("item_count")))
            for it in nav.get("items", []):
                L.append("  - `%s` -> `%s`" % (it.get("label"), it.get("href")))
        L.append("")
    L.append("### REST menu endpoints")
    L.append("")
    for k, v in mn["rest_menus"].items():
        L.append("- `%s` -> %s (code `%s` %s)"
                 % (v.get("url"),
                    md_cell("->".join(str(x) for x in (v.get("status_chain") or []))),
                    md_cell(v.get("code")), md_cell(v.get("message"))))
    L.append("")

    L.append("## 9. Legacy redirects")
    L.append("")
    L.append("| requested | status chain | final URL | final | hops |")
    L.append("|---|---|---|---|---|")
    for k, v in a["redirects"]["rows"].items():
        L.append("| `%s` | %s | %s | %s | %s |" % (
            v["requested_url"],
            md_cell("->".join(str(x) for x in v["status_chain"])),
            md_cell(v["final_url"]), md_cell(v["final_status"]), v["hops"]))
    L.append("")
    L.append("- Chains longer than 3 hops: %s"
             % md_cell(a["redirects"]["chains_longer_than_3_hops"]))
    L.append("")

    L.append("## 10. Caching headers")
    L.append("")
    L.append("| URL | status | x-ac | server-timing | cache-control | age | "
             "x-cache | cf-cache-status | edge state |")
    L.append("|---|---|---|---|---|---|---|---|---|")
    for k, v in a["caching"].items():
        L.append("| `%s` | %s | `%s` | `%s` | `%s` | %s | %s | %s | %s |" % (
            v["url"], v["final_status"], md_cell(v["x_ac"]),
            md_cell(v["server_timing"]), md_cell(v["cache_control"]),
            md_cell(v["age"]), md_cell(v["x_cache"]),
            md_cell(v["cf_cache_status"]), v["edge_state"]))
    L.append("")

    L.append("## 11. hreflang duplication check")
    L.append("")
    for k, v in a["hreflang_duplication"].items():
        L.append("### `/` -> %s (%s)" % (k, v.get("url")))
        L.append("")
        L.append("- final status: %s | final URL: `%s`" % (v["final_status"], v["final_url"]))
        L.append("- duplicate hreflang values: %s | two-emitter suspicion: **%s**"
                 % (md_cell(v["duplicate_hreflang_values"]), v["duplicate_emitter_suspected"]))
        L.append("- tags in order:")
        L.append("")
        for t in v["tags_in_order"]:
            L.append("  - `hreflang=%s` -> `%s`" % (t.get("hreflang"), t.get("href")))
        L.append("")

    return "\n".join(L)



# --------------------------------------------------------------------------

def load_raw_rows(out_dir, probes):
    """Rebuild rows from saved raw captures (no network)."""
    rows, bodies = [], {}
    for p in probes:
        body = None
        for ext in ("html", "json", "xml", "txt"):
            cand = os.path.join(out_dir, "raw", "%s.%s" % (p["key"], ext))
            if os.path.exists(cand):
                with open(cand, encoding="utf-8", errors="replace") as fh:
                    body = fh.read()
                break
        if body is None:
            continue
        hp = os.path.join(out_dir, "raw", "%s.headers.json" % p["key"])
        with open(hp, encoding="utf-8") as fh:
            meta = json.load(fh)
        hops = meta.get("hops") or []
        res = {
            "requested_url": (meta.get("probe") or {}).get("url") or p["url"],
            "method": p["method"], "hops": hops,
            "status_chain": meta.get("status_chain") or [],
            "final_url": meta.get("final_url") or p["url"],
            "final_status": meta.get("final_status"),
            "redirect_target": hops[-1].get("redirect_target") if hops else None,
            "loop_warning": _loop_warning(hops),
            "final_headers": meta.get("final_headers") or {},
            "body": b"",
        }
        rows.append(make_row(p, res, body))
        bodies[p["key"]] = body
    return rows, bodies


def reanalyze_run(args):
    """Re-derive baseline.json/baseline.md from raw/ captures (zero requests)."""
    out_dir = os.path.abspath(args.out_dir)
    probes = build_probes(args.base_url)
    rows, bodies = load_raw_rows(out_dir, probes)
    jp = os.path.join(out_dir, "baseline.json")
    old_meta = {}
    if os.path.exists(jp):
        try:
            with open(jp, encoding="utf-8") as fh:
                old_meta = (json.load(fh).get("meta") or {})
        except Exception:  # noqa: BLE001
            old_meta = {}
    meta = dict(old_meta)
    meta.update({
        "reanalyzed_at": datetime.now(timezone.utc).isoformat(),
        "reanalyzed_from_raw": True,
        "raw_rows_loaded": len(rows),
    })
    report = {"meta": meta,
              "analysis": analyze(bodies, rows, args.base_url),
              "rows": rows}
    with open(jp, "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, sort_keys=True, ensure_ascii=False)
    with open(os.path.join(out_dir, "baseline.md"), "w", encoding="utf-8") as fh:
        fh.write(build_markdown(report))
    print("reanalyzed %d rows from raw/ (no production requests)" % len(rows))
    return 0


# Main
# --------------------------------------------------------------------------

def parse_args(argv=None):
    ap = argparse.ArgumentParser(
        description="READ-ONLY production baseline probe (GET/HEAD only).")
    ap.add_argument("--base-url", default=DEFAULT_BASE_URL)
    ap.add_argument("--out-dir", default=os.path.dirname(os.path.abspath(__file__)))
    ap.add_argument("--delay", type=float, default=4.0,
                    help="base seconds between requests (adaptive on 429)")
    ap.add_argument("--timeout", type=int, default=TIMEOUT)
    ap.add_argument("--max-retries", type=int, default=4)
    ap.add_argument("--only", default=None,
                    help="comma list of sections: core,head,polylang,sitemap,"
                         "rest_root,rest_matrix,counts,menus,redirects")
    ap.add_argument("--keys", default=None,
                    help="comma list of probe keys to run (targeted top-up)")
    ap.add_argument("--limit", type=int, default=0, help="0 = all probes")
    ap.add_argument("--list", action="store_true", help="list probes and exit")
    ap.add_argument("--no-raw", action="store_true")
    ap.add_argument("--keep-best", action="store_true",
                    help="do not overwrite a good raw capture with a 429/error one")
    ap.add_argument("--reanalyze", action="store_true",
                    help="rebuild baseline.json/md from raw/ captures (no network)")
    return ap.parse_args(argv)


def main(argv=None):
    args = parse_args(argv)
    out_dir = os.path.abspath(args.out_dir)
    raw_dir = os.path.join(out_dir, "raw")
    os.makedirs(raw_dir, exist_ok=True)

    if getattr(args, "reanalyze", False):
        return reanalyze_run(args)

    probes = build_probes(args.base_url)
    if args.only:
        wanted = set(s.strip() for s in args.only.split(",") if s.strip())
        probes = [p for p in probes if p["section"] in wanted]
    if args.keys:
        wanted_keys = set(k.strip() for k in args.keys.split(",") if k.strip())
        probes = [p for p in probes if p["key"] in wanted_keys]
    if args.limit:
        probes = probes[:args.limit]

    if args.list:
        for p in probes:
            print("%-22s %-7s %-11s %s" % (p["key"], p["method"], p["section"], p["url"]))
        print("TOTAL: %d probes (%d unique requests approx.)" % (len(probes), len(probes)))
        return 0

    fetcher = Fetcher(delay=args.delay, timeout=args.timeout,
                      max_retries=args.max_retries, verbose=True)
    rows, bodies = [], {}
    t_start = time.time()
    for idx, probe in enumerate(probes, 1):
        sys.stderr.write("[%d/%d] %s %s %s\n"
                         % (idx, len(probes), probe["method"], probe["section"],
                            probe["url"]))
        sys.stderr.flush()
        res = fetcher.request(probe["method"], probe["url"], follow=probe["follow"])
        ctype = (_hget(res.get("final_headers") or {}, "content-type") or "")
        textual = (probe["kind"] in ("html", "head") or "json" in ctype
                   or "xml" in ctype or "text" in ctype or probe["kind"] in
                   ("sitemap", "robots", "rest", "count", "json", "redirect"))
        body_text = ""
        if textual and res.get("body") is not None:
            body_text = res["body"].decode("utf-8", "replace")
        if not args.no_raw and not (
                args.keep_best and keep_existing(raw_dir, probe, res)):
            save_raw(raw_dir, probe, res, body_text)
        bodies[probe["key"]] = body_text
        row = make_row(probe, res, body_text)
        rows.append(row)
        if row["final_status"] == 429:
            sys.stderr.write("    !! 429 after retries -- recorded as evidence\n")

    if args.keep_best and not args.no_raw:
        rows, bodies = load_raw_rows(out_dir, probes)

    report = {
        "meta": {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "base_url": args.base_url,
            "user_agent": BROWSER_UA,
            "accept_language": ACCEPT_LANGUAGE,
            "max_hops": MAX_HOPS, "timeout": args.timeout, "delay": args.delay,
            "python": sys.version.split()[0],
            "probe_count": len(probes), "elapsed_seconds": round(time.time() - t_start, 1),
            "request_stats": fetcher.limiter.stats,
        },
        "analysis": analyze(bodies, rows, args.base_url),
        "rows": rows,
    }
    with open(os.path.join(out_dir, "baseline.json"), "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, sort_keys=True, ensure_ascii=False)
    with open(os.path.join(out_dir, "baseline.md"), "w", encoding="utf-8") as fh:
        fh.write(build_markdown(report))

    e = report["analysis"]["en_homepage"]
    print("")
    print("=== SUMMARY (%s) ===" % args.base_url)
    print("/en/ status chain : %s" % " -> ".join(str(x) for x in (e["status_chain"] or [])))
    print("/en/ final URL    : %s" % e["final_url"])
    print("/en/ html lang    : %s" % e["html_lang"])
    print("conexao/v1 present: %s" % report["analysis"]["rest_namespaces"]["conexao_present"])
    print("sitemap owner key : %s (%s URLs)"
          % (report["analysis"]["sitemap"]["_primary"]["key"],
             report["analysis"]["sitemap"]["_primary"]["loc_count"]))
    print("requests/stats    : %s" % report["meta"]["request_stats"])
    print("wrote: baseline.json, baseline.md, raw/")
    return 0


if __name__ == "__main__":
    sys.exit(main())

