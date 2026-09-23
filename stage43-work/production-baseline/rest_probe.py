#!/usr/bin/env python3
"""
Stage 4.3 — read-only production probe for https://conexaobr.ie

STRICTLY READ ONLY: GET requests only. No POST/PUT/PATCH/DELETE anywhere.

Credentials: read from an env file OUTSIDE the repository (default
/tmp/prod-rest.env) providing WP_BASE_URL, WP_USERNAME,
WP_APPLICATION_PASSWORD. The credential is never written to disk by this
script and never included in any output artefact.

Outputs (this directory):
  raw-rest/<name>.meta.json / <name>.body   raw captures
  rest-probe.json                            machine-readable summary

Rate-limit aware: spaces requests, retries 429 with backoff, never records a
429 as the final result unless all retries failed.
"""

import base64
import json
import os
import re
import ssl
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(HERE, "raw-rest")
ENV_FILE = os.environ.get("CONEXAO_PROD_ENV", "/tmp/prod-rest.env")
UA_PLAIN = "ConexaoBR-Stage43-Audit/1.0 (read-only production audit)"
# NOTE: the WordPress.com WAF answers HTTP 403 to *authenticated* REST requests
# that carry a browser (Mozilla) User-Agent, and the edge rate-limits (429)
# uncached HTML requests from a datacenter IP when they look like a browser.
# A plain non-browser UA is accepted for both authenticated and anonymous GETs
# (measured 2026-09-23), so this harness uses it everywhere.
SPACING = float(os.environ.get("PROBE_SPACING", "2"))
RETRY_SLEEP = float(os.environ.get("PROBE_RETRY_SLEEP", "45"))
MAX_RETRY = 3


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def load_env(path):
    cfg = {}
    if os.path.exists(path):
        with open(path) as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                v = v.strip().strip("'\"")
                cfg[k.strip()] = v
    return cfg


CFG = load_env(ENV_FILE)
BASE = os.environ.get("WP_BASE_URL", CFG.get("WP_BASE_URL", "https://conexaobr.ie")).rstrip("/")
USER = os.environ.get("WP_USERNAME", CFG.get("WP_USERNAME"))
PASS = os.environ.get("WP_APPLICATION_PASSWORD", CFG.get("WP_APPLICATION_PASSWORD"))
CTX = ssl.create_default_context()


def auth_header():
    if not USER or not PASS:
        return None
    tok = base64.b64encode(f"{USER}:{PASS}".encode()).decode()
    return "Basic " + tok


def fetch(url, label, auth=False, follow=False, accept="application/json"):
    last = None
    for attempt in range(1, MAX_RETRY + 2):
        req = urllib.request.Request(url, method="GET")
        req.add_header("User-Agent", UA_PLAIN)
        req.add_header("Accept", accept)
        req.add_header("Accept-Language", "pt-BR,pt;q=0.9,en;q=0.8")
        if auth:
            h = auth_header()
            if h:
                req.add_header("Authorization", h)
        opener = urllib.request.build_opener(
            urllib.request.HTTPRedirectHandler() if follow else NoRedirect())
        started = time.time()
        try:
            with opener.open(req, timeout=40) as resp:
                body = resp.read()
                status, headers = resp.status, dict(resp.headers)
        except urllib.error.HTTPError as exc:
            body = exc.read()
            status, headers = exc.code, dict(exc.headers)
        except Exception as exc:  # noqa: BLE001
            body, status, headers = str(exc).encode(), 0, {}
        rec = {
            "label": label, "url": url, "auth": auth, "follow": follow,
            "status": status, "location": headers.get("Location"),
            "content_type": headers.get("Content-Type"),
            "x_redirect_by": headers.get("X-Redirect-By"),
            "x_ac": headers.get("X-Ac") or headers.get("x-ac"),
            "cache_control": headers.get("Cache-Control"),
            "x_wp_total": headers.get("X-WP-Total"),
            "x_wp_totalpages": headers.get("X-WP-TotalPages"),
            "elapsed_ms": round((time.time() - started) * 1000),
            "attempt": attempt, "body_len": len(body),
        }
        with open(os.path.join(RAW, label + ".meta.json"), "w") as fh:
            json.dump(rec, fh, indent=1)
        with open(os.path.join(RAW, label + ".body"), "wb") as fh:
            fh.write(body)
        last = rec
        if status != 429:
            return rec
        sys.stderr.write(f"  429 on {label}, retry in {RETRY_SLEEP}s\n")
        time.sleep(RETRY_SLEEP)
    return last


def jget(rec):
    try:
        with open(os.path.join(RAW, rec["label"] + ".body")) as fh:
            return json.load(fh)
    except Exception:  # noqa: BLE001
        return None


REST_AUTH = [
    ("rest_root", "/wp-json/"),
    ("rest_plugins", "/wp-json/wp/v2/plugins"),
    ("rest_themes", "/wp-json/wp/v2/themes"),
    ("rest_types", "/wp-json/wp/v2/types"),
    ("rest_taxonomies", "/wp-json/wp/v2/taxonomies"),
    ("rest_settings", "/wp-json/wp/v2/settings"),
    ("rest_users_me", "/wp-json/wp/v2/users/me?context=edit"),
    ("rest_page_sobrenos", "/wp-json/wp/v2/pages?slug=sobre-nos&status=publish&context=edit"),
    ("rest_pages_lang_en", "/wp-json/wp/v2/pages?lang=en&per_page=5&_fields=id,slug,link"),
    ("rest_conexao_v1", "/wp-json/conexao/v1"),
    ("rest_pll_v1", "/wp-json/pll/v1/languages"),
    ("rest_menus", "/wp-json/wp/v2/menus"),
    ("rest_event_encore",
     "/wp-json/wp/v2/event?slug=encore-halloween-edition-special-guest-1926&status=publish&_fields=id,slug,link"),
]

COUNTS = {
    "pages": "/wp-json/wp/v2/pages", "posts": "/wp-json/wp/v2/posts",
    "media": "/wp-json/wp/v2/media", "guide": "/wp-json/wp/v2/guide",
    "event": "/wp-json/wp/v2/event", "course_provider": "/wp-json/wp/v2/course_provider",
    "job": "/wp-json/wp/v2/job", "sponsor": "/wp-json/wp/v2/sponsor",
    "leisure": "/wp-json/wp/v2/leisure", "categories": "/wp-json/wp/v2/categories",
    "tags": "/wp-json/wp/v2/tags", "conexao_category": "/wp-json/wp/v2/conexao_category",
    "conexao_tag": "/wp-json/wp/v2/conexao_tag", "conexao_county": "/wp-json/wp/v2/conexao_county",
    "conexao_town": "/wp-json/wp/v2/conexao_town",
}

NOFOLLOW = [
    "/en/", "/en/home/", "/inicio/", "/jobs/", "/about-us/", "/contact/",
    "/guides/", "/events/", "/courses/", "/sponsors/", "/ireland/", "/about/",
    "/privacidade/", "/termos/", "/sobre/", "/counties/dublin/",
    "/sitemap.xml", "/sitemap_index.xml", "/wp-sitemap.xml", "/robots.txt",
]

REST_AUTH_2 = [
    ("rest_event_lang_en", "/wp-json/wp/v2/event?lang=en&per_page=1"),
    ("rest_event_lang_xx", "/wp-json/wp/v2/event?lang=xx&per_page=1"),
    ("rest_posts_lang_en", "/wp-json/wp/v2/posts?lang=en&per_page=1"),
    ("rest_leisure_lang_en", "/wp-json/wp/v2/leisure?lang=en&per_page=1"),
    ("rest_guide_lang_en", "/wp-json/wp/v2/guide?lang=en&per_page=1"),
    ("rest_job_lang_en", "/wp-json/wp/v2/job?lang=en&per_page=1"),
    ("rest_course_lang_en", "/wp-json/wp/v2/course_provider?lang=en&per_page=1"),
    ("rest_sponsor_lang_en", "/wp-json/wp/v2/sponsor?lang=en&per_page=1"),
    ("rest_search_lang_en", "/wp-json/wp/v2/search?lang=en&per_page=1"),
    ("rest_page_irlanda", "/wp-json/wp/v2/pages?slug=irlanda&_fields=id,slug,link"),
    ("rest_terms_conexao_category", "/wp-json/wp/v2/conexao_category?per_page=5&_fields=id,slug,name"),
    ("rest_terms_conexao_county", "/wp-json/wp/v2/conexao_county?per_page=5&_fields=id,slug,name"),
    ("rest_terms_tags", "/wp-json/wp/v2/tags?per_page=3&_fields=id,slug,name"),
]

def main():
    os.makedirs(RAW, exist_ok=True)
    groups = (sys.argv[1] if len(sys.argv) > 1 else "all").split(",")
    want = lambda name: "all" in groups or name in groups  # noqa: E731
    rows = []
    print(f"base={BASE} auth={'yes' if auth_header() else 'no'} groups={groups}")
    if want("rest"):
        for label, path in REST_AUTH:
            rows.append(fetch(BASE + path, label, auth=True, follow=True))
            print(f"  {label} -> {rows[-1]['status']} total={rows[-1]['x_wp_total']}")
            time.sleep(SPACING)
    if want("counts"):
        for label, path in COUNTS.items():
            rec = fetch(BASE + path + "?per_page=1&_fields=id", "count_" + label,
                        auth=True, follow=True)
            rows.append(rec)
            print(f"  count_{label} -> {rec['status']} total={rec['x_wp_total']}")
            time.sleep(SPACING)
    if want("http"):
        for path in NOFOLLOW:
            label = "http_" + (re.sub(r"[^a-z0-9]+", "_", path.strip("/")) or "home")
            if path == "/en/":
                label = "http_en_root"
            rec = fetch(BASE + path, label, auth=False, follow=False, accept="*/*")
            rows.append(rec)
            print(f"  {label} -> {rec['status']} loc={rec['location']}")
            time.sleep(SPACING)
    if want("follow"):
        for path in ["/", "/guias/", "/eventos/", "/en/", "/lazer/", "/apoiadores/", "/cursos/", "/blog/"]:
            label = "http_follow_" + (re.sub(r"[^a-z0-9]+", "_", path.strip("/")) or "home")
            rec = fetch(BASE + path, label, auth=False, follow=True, accept="text/html")
            rows.append(rec)
            print(f"  {label} -> {rec['status']}")
            time.sleep(SPACING)
    if want("rest2"):
        for label, path in REST_AUTH_2:
            rows.append(fetch(BASE + path, label, auth=True, follow=True))
            print(f"  {label} -> {rows[-1]['status']} total={rows[-1]['x_wp_total']}")
            time.sleep(SPACING)
    if rows:
        path = os.path.join(HERE, "rest-probe.json")
        previous = []
        if os.path.exists(path):
            try:
                with open(path) as fh:
                    previous = json.load(fh).get("rows", [])
            except Exception:  # noqa: BLE001
                previous = []
        seen = {r["label"]: r for r in previous}
        for r in rows:
            seen[r["label"]] = r
        with open(path, "w") as fh:
            json.dump({"base": BASE,
                       "generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                       "rows": list(seen.values())}, fh, indent=1)
        print(f"rows={len(rows)} (accumulated {len(seen)}) -> rest-probe.json")


if __name__ == "__main__":
    main()
