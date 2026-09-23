#!/usr/bin/env python3
"""
Stage 4.3 — production English acceptance matrix (READ ONLY).

Implements the Phase 19 HTTP matrix of CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md:
it probes every production URL class of the bilingual architecture, captures the
exact HTTP status / redirect target / canonical / hreflang set / html lang /
B2-fallback state / sitemap membership / REST contract fields, and (optionally)
asserts the post-deployment expectations.

GET requests only. No POST/PUT/PATCH/DELETE. No writes of any kind.
Credentials are read from an env file OUTSIDE the repository (default
/tmp/prod-rest.env; WP_BASE_URL, WP_USERNAME, WP_APPLICATION_PASSWORD) and are
never echoed, logged or written to the output artefacts.

Usage
-----
  python3 scripts/stage43-verify-english.py --phase before --out /tmp/s43-before
  python3 scripts/stage43-verify-english.py --phase after  --out /tmp/s43-after
  python3 scripts/stage43-verify-english.py --phase after --group b2

Notes
-----
* WordPress.com answers 403 to authenticated REST GETs that carry a browser
  User-Agent, and rate-limits (429) browser-looking uncached HTML requests from
  datacenter IPs. This harness therefore always sends the plain UA below, which
  is accepted for both authenticated and anonymous reads (measured 2026-09-23).
* Output: <out>.json, <out>.md, and <out>-raw/ with the raw capture of every row.

Author: Stage 4.3 (website-only stage; the Flutter client is out of scope).
"""

import argparse
import base64
import json
import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "ConexaoBR-Stage43-Acceptance/1.0 (read-only production verification)"
CTX = ssl.create_default_context()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Do not follow redirects: the chain itself is the evidence."""

    def redirect_request(self, *args, **kwargs):  # noqa: D102
        return None


def get(url, auth=None, follow=False, accept="*/*", retries=3, sleeps=(5, 30, 60)):
    """GET with manual redirect handling and 429 backoff."""
    last = None
    for attempt in range(retries + 1):
        req = urllib.request.Request(url, method="GET")
        req.add_header("User-Agent", UA)
        req.add_header("Accept", accept)
        if auth:
            req.add_header("Authorization", "Basic " + auth)
        opener = urllib.request.build_opener(
            urllib.request.HTTPRedirectHandler() if follow else NoRedirect())
        try:
            with opener.open(req, timeout=40) as resp:
                body, status, headers = resp.read(), resp.status, dict(resp.headers)
        except urllib.error.HTTPError as exc:
            body, status, headers = exc.read(), exc.code, dict(exc.headers)
        except Exception as exc:  # noqa: BLE001
            body, status, headers = str(exc).encode(), 0, {}
        last = {
            "url": url, "status": status, "location": headers.get("Location"),
            "content_type": headers.get("Content-Type"),
            "x_redirect_by": headers.get("X-Redirect-By"),
            "x_ac": headers.get("X-Ac"),
            "x_wp_total": headers.get("X-WP-Total"),
            "body": body, "attempt": attempt,
        }
        if status != 429:
            return last
        sys.stderr.write("    429 → backoff\n")
        time.sleep(sleeps[min(attempt, len(sleeps) - 1)])
    return last


def follow_chain(url, auth=None, accept="*/*", max_hops=5):
    chain, current, rec = [], url, None
    for _ in range(max_hops):
        rec = get(current, auth=auth, follow=False, accept=accept)
        chain.append({"url": current, "status": rec["status"],
                      "location": rec["location"],
                      "x_redirect_by": rec["x_redirect_by"]})
        loc = rec["location"]
        if rec["status"] in (301, 302, 303, 307, 308) and loc:
            current = urllib.parse.urljoin(current, loc)
            continue
        return chain, rec
    return chain, rec


def head_links(html):
    """Extract canonical / hreflang / html lang and chrome markers."""
    out = {"canonical": None, "hreflang": [], "html_lang": None, "title": None,
           "b2_notice": False, "polylang": False, "language_switcher": False}
    if not html:
        return out
    canon = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]*>', html, re.I)
    if canon:
        m = re.search(r'href=["\']([^"\']+)["\']', canon.group(0), re.I)
        out["canonical"] = m.group(1) if m else None
    for tag in re.findall(r'<link[^>]+rel=["\']alternate["\'][^>]*>', html, re.I):
        hl = re.search(r'hreflang=["\']([^"\']+)["\']', tag, re.I)
        href = re.search(r'href=["\']([^"\']+)["\']', tag, re.I)
        if hl and href:
            out["hreflang"].append([hl.group(1), href.group(1)])
    m = re.search(r'<html[^>]*\blang=["\']([^"\']+)["\']', html, re.I)
    if m:
        out["html_lang"] = m.group(1)
    m = re.search(r"<title>(.*?)</title>", html, re.S | re.I)
    if m:
        out["title"] = m.group(1).strip()[:160]
    low = html.lower()
    out["b2_notice"] = ("language-fallback-notice" in low
                        or "This content is displayed in Portuguese." in html)
    out["polylang"] = ("polylang" in low) or ("pll_" in low)
    out["language_switcher"] = ("language-switcher" in low
                                or "lang-switch" in low
                                or "conexao-language-switcher" in low)
    return out


def load_env(path):
    cfg = {}
    if path and os.path.exists(path):
        with open(path) as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                cfg[k.strip()] = v.strip().strip("'\"")
    return cfg


ROWS = [
    # group, name, path, kind, expectation (post-deployment)
    ("home", "home_pt", "/", "html",
     {"status": 200, "canonical": "self", "html_lang": "pt-BR",
      "hreflang": ["en", "pt-BR", "x-default"]}),
    ("home", "home_en", "/en/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"]}),
    ("home", "inicio_pt_consolidation", "/inicio/", "redirect",
     {"status": 301, "final": "/"}),
    ("home", "en_home_slug_consolidation", "/en/home/", "redirect",
     {"status": 301, "final": "/en/"}),

    ("en_pages", "en_about_us", "/en/about-us/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),
    ("en_pages", "en_contact", "/en/contact/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),
    ("en_pages", "en_jobs", "/en/jobs/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),
    ("en_pages", "en_privacy_policy", "/en/privacy-policy/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),
    ("en_pages", "en_terms_of_use", "/en/terms-of-use/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),
    ("en_pages", "en_cookie_policy", "/en/cookie-policy/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US",
      "hreflang": ["en", "pt-BR", "x-default"], "switcher": True}),

    ("b2", "b2_blog", "/en/blog/", "render", {"status": 200, "final": "/en/blog/"}),
    ("b1", "b1_moradia", "/en/moradia/", "redirect", {"status": 302, "final": "/moradia/"}),
    ("b1", "b1_europa", "/en/europa/", "redirect", {"status": 302, "final": "/europa/"}),

    ("b2", "b2_irlanda", "/en/irlanda/", "html",
     {"status": 200, "canonical": "pt", "html_lang": "en-US",
      "hreflang": ["x-default"], "b2_notice": True}),
    ("b2", "b2_dublin", "/en/dublin/", "html",
     {"status": 200, "canonical": "pt", "html_lang": "en-US",
      "hreflang": ["x-default"], "b2_notice": True}),
    ("b2", "b2_cork", "/en/cork/", "html",
     {"status": 200, "canonical": "pt", "html_lang": "en-US",
      "hreflang": ["x-default"], "b2_notice": True}),
    ("b2", "b2_galway", "/en/galway/", "html",
     {"status": 200, "canonical": "pt", "html_lang": "en-US",
      "hreflang": ["x-default"], "b2_notice": True}),
]


ROWS += [
    ("archives", "en_archive_guias", "/en/guias/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US"}),
    ("archives", "en_archive_eventos", "/en/eventos/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US"}),
    ("archives", "en_archive_lazer", "/en/lazer/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US"}),
    ("archives", "en_archive_apoiadores", "/en/apoiadores/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US"}),
    ("archives", "en_archive_cursos", "/en/cursos/", "html",
     {"status": 200, "canonical": "self", "html_lang": "en-US"}),
    ("archives", "pt_archive_guias", "/guias/", "html",
     {"status": 200, "canonical": "self", "html_lang": "pt-BR"}),
    ("archives", "pt_archive_eventos", "/eventos/", "html",
     {"status": 200, "canonical": "self", "html_lang": "pt-BR"}),
    ("archives", "pt_archive_lazer", "/lazer/", "html",
     {"status": 200, "canonical": "self", "html_lang": "pt-BR"}),

    ("search", "en_search", "/en/?s=housing", "html",
     {"status": 200, "html_lang": "en-US"}),
    ("search", "pt_search", "/?s=moradia", "html", {"status": 200}),

    ("filters", "en_filter_translated_category", "/en/guias/?categoria=documents",
     "html", {"status": 200, "html_lang": "en-US"}),
    ("filters", "pt_filter_category", "/guias/?categoria=documentos",
     "html", {"status": 200}),
    ("filters", "en_filter_shared_county", "/en/lazer/?county=dublin",
     "html", {"status": 200, "html_lang": "en-US"}),
    ("filters", "pt_filter_shared_county", "/lazer/?county=dublin",
     "html", {"status": 200}),

    ("legacy", "legacy_jobs", "/jobs/", "redirect", {"status": 301, "final": "/empregos/"}),
    ("legacy", "legacy_about_us", "/about-us/", "redirect", {"status": 301, "final": "/sobre-nos/"}),
    ("legacy", "legacy_contact", "/contact/", "redirect", {"status": 301, "final": "/contato/"}),
    ("legacy", "legacy_guides", "/guides/", "redirect", {"status": 301, "final": "/guias/"}),
    ("legacy", "legacy_events", "/events/", "redirect", {"status": 301, "final": "/eventos/"}),
    ("legacy", "legacy_courses", "/courses/", "redirect", {"status": 301, "final": "/cursos/"}),
    ("legacy", "legacy_sponsors", "/sponsors/", "redirect", {"status": 301, "final": "/apoiadores/"}),
    ("legacy", "legacy_ireland", "/ireland/", "redirect", {"status": 301, "final": "/irlanda/"}),
    ("legacy", "legacy_about", "/about/", "redirect", {"status": 301, "final": "/sobre-nos/"}),
    ("legacy", "legacy_privacidade", "/privacidade/", "redirect",
     {"status": 301, "final": "/politica-de-privacidade/"}),
    ("legacy", "legacy_termos", "/termos/", "redirect", {"status": 301, "final": "/termos-de-uso/"}),
    ("legacy", "legacy_sobre", "/sobre/", "redirect", {"status": 301, "final": "/sobre-nos/"}),
    ("legacy", "legacy_counties_dublin", "/counties/dublin/", "redirect",
     {"status": 301, "final": "/dublin/"}),
]
REST_ROWS = [
    ("rest", "rest_event_pt", "/wp-json/wp/v2/event?lang=pt&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_event_en", "/wp-json/wp/v2/event?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_leisure_en", "/wp-json/wp/v2/leisure?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_guide_en", "/wp-json/wp/v2/guide?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_posts_en", "/wp-json/wp/v2/posts?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_job_en", "/wp-json/wp/v2/job?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_course_en", "/wp-json/wp/v2/course_provider?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_sponsor_en", "/wp-json/wp/v2/sponsor?lang=en&per_page=1",
     {"status": 200, "field": "conexao_language"}),
    ("rest", "rest_search_en", "/wp-json/wp/v2/search?lang=en&per_page=1",
     {"status": 200}),
    ("rest", "rest_invalid_lang", "/wp-json/wp/v2/event?lang=xx&per_page=1",
     {"status": 400, "code": "conexao_rest_invalid_lang"}),
    ("rest", "rest_terms_translated_category",
     "/wp-json/wp/v2/conexao_category?lang=en&per_page=3&_fields=id,slug,name",
     {"status": 200}),
    ("rest", "rest_terms_shared_county",
     "/wp-json/wp/v2/conexao_county?lang=en&per_page=3&_fields=id,slug,name",
     {"status": 200}),
]

SITEMAP_ROWS = [
    ("sitemap", "sitemap_xml", "/sitemap.xml", "sitemap",
     {"status": 200, "must_include": ["/"], "must_exclude_b2": True,
      "no_duplicates": True}),
    ("sitemap", "robots_txt", "/robots.txt", "sitemap", {"status": 200}),
    ("sitemap", "theme_sitemap_index", "/sitemap_index.xml", "sitemap",
     {"status": 200, "must_exclude_b2": True, "no_duplicates": True}),
]


def eval_html(path, rec, chain, info, expect):
    """Evaluate expectations for an HTML row: list of (check, ok, detail)."""
    first = chain[0]["status"]
    checks = [(f"{path} status==200 (no redirect)",
               first == 200 and rec["status"] == 200,
               f"first {first}, final {rec['status']}, "
               f"hops {[c['status'] for c in chain]}")]
    if rec["status"] != 200:
        return checks
    if "canonical" in expect:
        want = expect["canonical"]
        got = info.get("canonical")
        if want == "self":
            ok = got == rec["url"]
        elif want == "pt":
            ok = bool(got) and "/en/" not in got
        else:
            ok = got == want
        checks.append((f"{path} canonical={want}", ok, f"canonical {got}"))
    if "html_lang" in expect:
        checks.append((f"{path} html lang=={expect['html_lang']}",
                       info.get("html_lang") == expect["html_lang"],
                       f"lang {info.get('html_lang')}"))
    if "hreflang" in expect:
        got = sorted(x[0] for x in info.get("hreflang", []))
        checks.append((f"{path} hreflang=={sorted(expect['hreflang'])}",
                       got == sorted(expect["hreflang"]), f"got {got}"))
    if expect.get("b2_notice"):
        checks.append((f"{path} B2 notice present", bool(info.get("b2_notice")),
                       f"notice {info.get('b2_notice')}"))
    if expect.get("switcher"):
        checks.append((f"{path} language switcher in HTML",
                       bool(info.get("language_switcher")),
                       f"switcher {info.get('language_switcher')}"))
    return checks


def eval_redirect(path, rec, chain, expect):
    statuses = [c["status"] for c in chain]
    checks = [(f"{path} status=={expect['status']}",
               chain[0]["status"] == expect["status"], f"chain {statuses}")]
    if "final" in expect:
        final_path = urllib.parse.urlparse(chain[-1]["url"]).path
        checks.append((f"{path} final={expect['final']}", final_path == expect["final"],
                       f"final {final_path} (chain {statuses})"))
    checks.append((f"{path} no redirect loop", len(statuses) <= 3, f"hops {len(chain)}"))
    return checks



def eval_rest(path, rec, expect):
    checks = [(f"{path} status=={expect['status']}", rec["status"] == expect["status"],
               f"status {rec['status']}")]
    body = rec["body"].decode("utf-8", "replace")
    if expect.get("field"):
        present = expect["field"] in body
        checks.append((f"{path} carries {expect['field']}", present,
                       "present" if present else "ABSENT"))
    if expect.get("code"):
        present = expect["code"] in body
        checks.append((f"{path} error code {expect['code']}", present,
                       "present" if present else "ABSENT"))
    return checks


def eval_sitemap(path, rec, expect):
    first = rec["status"]
    checks = [(f"{path} status==200", first == 200, f"status {first}")]
    body = rec["body"].decode("utf-8", "replace")
    if not path.endswith(".xml") or rec["status"] != 200:
        return checks
    locs = re.findall(r"<loc>\s*([^<\s]+)\s*</loc>", body)
    checks.append((f"{path} has <loc> entries", len(locs) > 0, f"{len(locs)} urls"))
    if expect.get("no_duplicates"):
        checks.append((f"{path} no duplicate <loc>", len(locs) == len(set(locs)),
                       f"{len(locs) - len(set(locs))} duplicates"))
    for fragment in expect.get("must_include", []):
        hit = any(urllib.parse.urlparse(u).path == fragment for u in locs)
        checks.append((f"{path} includes {fragment}", hit,
                       "present" if hit else "MISSING"))
    if expect.get("must_exclude_b2"):
        b2_paths = ["/en/irlanda/", "/en/dublin/", "/en/cork/", "/en/galway/",
                    "/en/limerick/", "/en/kildare/", "/en/meath/", "/en/wicklow/",
                    "/en/waterford/", "/en/laois/"]
        offenders = [u for u in locs if urllib.parse.urlparse(u).path in b2_paths]
        checks.append((f"{path} excludes B2 fallback URLs", not offenders,
                       f"offenders {offenders[:5]}" if offenders else "none"))
    return checks



def main():
    ap = argparse.ArgumentParser(description="Stage 4.3 production English matrix (read only)")
    ap.add_argument("--base-url", default=None)
    ap.add_argument("--env-file", default="/tmp/prod-rest.env")
    ap.add_argument("--phase", choices=["before", "after"], default="before")
    ap.add_argument("--out", default="/tmp/stage43-matrix")
    ap.add_argument("--group", default="all", help="comma list of row groups")
    ap.add_argument("--spacing", type=float, default=1.5)
    ap.add_argument("--include-rest", action="store_true",
                    help="also run the REST rows (needs credentials)")
    args = ap.parse_args()

    cfg = load_env(args.env_file)
    base = (args.base_url or cfg.get("WP_BASE_URL") or "https://conexaobr.ie").rstrip("/")
    auth = None
    if cfg.get("WP_USERNAME") and cfg.get("WP_APPLICATION_PASSWORD"):
        auth = base64.b64encode(
            f"{cfg['WP_USERNAME']}:{cfg['WP_APPLICATION_PASSWORD']}".encode()).decode()

    groups = args.group.split(",")

    def norm(row, kind):
        return row if len(row) == 5 else (row[0], row[1], row[2], kind, row[3])

    rows = [r for r in ROWS if "all" in groups or r[0] in groups]
    rows += [norm(r, "sitemap") for r in SITEMAP_ROWS
             if "all" in groups or r[0] in groups]
    if args.include_rest or "all" in groups:
        rows += [norm(r, "rest") for r in REST_ROWS
                 if "all" in groups or r[0] in groups]

    raw_dir = args.out + "-raw"
    os.makedirs(raw_dir, exist_ok=True)
    results, checks = [], []
    for group, name, path, kind, expect in rows:
        url = base + path
        info = {}
        if kind == "redirect":
            chain, rec = follow_chain(url, accept="*/*")
        else:
            rec = get(url, auth=auth if kind == "rest" else None, follow=False,
                      accept="application/json" if kind == "rest" else "*/*")
            chain = [{"url": url, "status": rec["status"], "location": rec["location"]}]
            if kind in ("html", "sitemap") and rec["status"] in (301, 302, 303, 307, 308):
                chain, rec = follow_chain(url, accept="*/*")
        body_text = rec["body"].decode("utf-8", "replace")
        if kind == "html":
            info = head_links(body_text)
        with open(os.path.join(raw_dir, name + ".body"), "wb") as fh:
            fh.write(rec["body"])
        results.append({"group": group, "name": name, "path": path, "kind": kind,
                        "status": rec["status"], "location": rec["location"],
                        "x_wp_total": rec["x_wp_total"],
                        "x_redirect_by": rec["x_redirect_by"], "chain": chain,
                        "info": info, "bytes": len(rec["body"])})
        print(f"  {name:32s} status={rec['status']:<4} "
              f"loc={(rec['location'] or '')[:58]}")
        if args.phase == "after":
            if kind == "html":
                checks += eval_html(path, rec, chain, info, expect)
            elif kind == "redirect":
                checks += eval_redirect(path, rec, chain, expect)
            elif kind == "rest":
                checks += eval_rest(path, rec, expect)
            elif kind == "sitemap":
                checks += eval_sitemap(path, rec, expect)
        time.sleep(args.spacing)

    payload = {"base": base, "phase": args.phase,
               "generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
               "rows": results,
               "checks": [{"check": c, "ok": o, "detail": d} for c, o, d in checks],
               "failed": sum(1 for _, o, _ in checks if not o),
               "passed": sum(1 for _, o, _ in checks if o)}
    with open(args.out + ".json", "w") as fh:
        json.dump(payload, fh, indent=1)
    with open(args.out + ".md", "w") as fh:
        fh.write(f"# Stage 4.3 production matrix — phase `{args.phase}`\n\n")
        fh.write(f"Generated {payload['generated']} · base `{base}` · {len(results)} rows · "
                 f"{payload['passed']} checks passed · {payload['failed']} failed\n\n")
        fh.write("| group | row | path | status | redirect | canonical | hreflang | lang | B2 |\n")
        fh.write("|---|---|---|---|---|---|---|---|---|\n")
        for r in results:
            i = r["info"] or {}
            hl = " ".join(x[0] for x in (i.get("hreflang") or []))
            fh.write(f"| {r['group']} | `{r['name']}` | `{r['path']}` | {r['status']} | "
                     f"{r['location'] or ''} | {i.get('canonical') or ''} | {hl} | "
                     f"{i.get('html_lang') or ''} | {i.get('b2_notice')} |\n")
        if checks:
            fh.write("\n## Checks\n\n")
            for c, o, d in checks:
                fh.write(f"- {'PASS' if o else 'FAIL'} — {c} ({d})\n")
    print(f"wrote {args.out}.json/.md  checks={len(checks)} failed={payload['failed']}")
    if args.phase == "after" and payload["failed"]:
        sys.exit(1)


if __name__ == "__main__":
    main()

