#!/usr/bin/env python3
"""
Phase 36 - capture semantic HTTP output for the bilingual route set, so the
pre/post refactor comparison is evidence-based rather than assumed.

Usage: capture_http.py <output-file>
Local only (http://localhost:8080). No production requests, ever.
"""
import re
import sys
import urllib.request
import urllib.error

BASE = "http://localhost:8080"
ROUTES = [
    "/", "/en/", "/guias/", "/en/guias/", "/empregos/", "/en/jobs/",
    "/eventos/", "/en/eventos/", "/lazer/", "/en/lazer/",
    "/apoiadores/", "/en/sponsors/", "/blog/", "/en/blog/",
    "/cursos/", "/en/courses/", "/sitemap.xml", "/robots.txt",
]

# The tags whose presence/values define the SEO+bilingual contract.
PATTERNS = {
    "title": re.compile(r"<title>(.*?)</title>", re.S),
    "canonical": re.compile(r'<link rel="canonical" href="([^"]*)"'),
    "hreflang": re.compile(r'<link rel="alternate" hrefLang="([^"]*)" href="([^"]*)"', re.I),
    "meta_desc": re.compile(r'<meta name="description" content="([^"]*)"'),
    "og_url": re.compile(r'<meta property="og:url" content="([^"]*)"'),
    "og_locale": re.compile(r'<meta property="og:locale" content="([^"]*)"'),
    "html_lang": re.compile(r'<html lang="([^"]*)"'),
    "robots": re.compile(r'<meta name="robots" content="([^"]*)"'),
    "jsonld_types": re.compile(r'"@type"\s*:\s*"([^"]*)"'),
    "location": re.compile(r"(?im)^location:\s*(.+)$"),
    "h1": re.compile(r"<h1[^>]*>(.*?)</h1>", re.S),
}


def fetch(path):
    req = urllib.request.Request(BASE + path, headers={"User-Agent": "StageF-verify/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return 0, {}, "ERROR: %s" % e


def clean(s):
    return re.sub(r"\s+", " ", s).strip()


def main(out):
    lines = []
    for path in ROUTES:
        status, headers, body = fetch(path)
        lines.append("### %s  status=%s" % (path, status))
        for hdr in ("Location", "Content-Type", "X-Redirect-By", "Cache-Control"):
            if hdr in headers:
                lines.append("  header %s: %s" % (hdr, headers[hdr]))
        for key, rx in PATTERNS.items():
            found = rx.findall(body)
            if key == "jsonld_types":
                found = sorted(set(found))
            else:
                found = [clean(" ".join(f) if isinstance(f, tuple) else f) for f in found]
            if found:
                lines.append("  %-12s %s" % (key, " | ".join(str(f) for f in found)))
        lines.append("")
    with open(out, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))
    print("captured %d routes -> %s" % (len(ROUTES), out))


if __name__ == "__main__":
    main(sys.argv[1])
