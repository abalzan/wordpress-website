#!/usr/bin/env python3
"""HTTP verification helper (local WordPress) for the Blog EN translation work.

Usage:
    python3 blog-en-http-verify.py <base-url> <path> [<path> ...]

Prints one JSON object per path with the facts the acceptance criteria need:
status, redirect location, <html lang>, <title>, canonical, hreflang links,
B2 fallback notice, archive card titles, pagination links, and a few UI labels.
"""
import json
import re
import sys
import urllib.error
import urllib.request

UA = "conexao-blog-en-verify/1.0"


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, dict(exc.headers), exc.read().decode("utf-8", "replace")


def probe(base, path):
    url = base.rstrip("/") + path
    status, headers, body = fetch(url)
    out = {
        "path": path,
        "url": url,
        "status": status,
        "location": headers.get("Location", ""),
        "lang": (re.search(r"<html[^>]*\blang=\"([^\"]+)\"", body) or [None, None])[1],
        "title": (re.search(r"<title>(.*?)</title>", body, re.S) or [None, ""])[1].strip(),
        "canonical": (re.search(r'<link rel="canonical" href="([^"]+)"', body) or [None, ""])[1],
        "hreflang": re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', body),
        "og_locale": (re.search(r'property="og:locale" content="([^"]+)"', body) or [None, ""])[1],
        "b2_notice": "language-fallback-notice" in body,
        "noindex": "noindex" in body,
        "cards": [
            re.sub(r"\s+", " ", t).strip()
            for t in re.findall(r'class="archive-card-title">\s*<a[^>]*>(.*?)</a>', body, re.S)
        ],
        "filter_links": re.findall(
            r'class="events-filter-link[^"]*"\s+href="([^"]+)"[^>]*>\s*([^<]*)', body
        ),
        "next_page": (re.search(r'href="([^"]*page/2/?)"[^>]*>\s*(?:Next|Próxim|Older|&raquo;)', body) or [None, ""])[1],
        "body_size": len(body),
    }
    out["cards"] = [re.sub(r"<[^>]+>", "", c) for c in out["cards"]]
    return out


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 2
    base = sys.argv[1]
    results = [probe(base, p) for p in sys.argv[2:]]
    print(json.dumps(results, ensure_ascii=False, indent=1))
    return 0


if __name__ == "__main__":
    sys.exit(main())
