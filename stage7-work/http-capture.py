#!/usr/bin/env python3
"""Stage 7 HTTP evidence capture (read-only, production).

Captures the current HTTP + rendered-card state of the Leisure archives:

  /lazer/        PT archive (must stay unchanged)
  /en/lazer/     EN archive (production: English layer not deployed yet)
  /lazer/?county=dublin  shared filter behaviour

For each: status, redirect, <html lang>, title, canonical, hreflang,
og:locale, .leisure-card-excerpt values, and byte-comparison of the
rendered excerpts against the captured REST post_excerpt values.
"""
import json
import re
import sys
import urllib.request

UA = "conexao-stage7-leisure-verify/1.0"
BASE = "https://conexaobr.ie"


def fetch(path):
    req = urllib.request.Request(BASE + path, headers={"User-Agent": UA})
    # Never follow redirects: the /en/ → PT redirect status is part of the
    # measured behaviour (production currently has no English layer).
    opener = urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(req, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, dict(exc.headers), exc.read().decode("utf-8", "replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def probe(path):
    status, headers, body = fetch(path)
    excerpts = [
        re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", e)).strip()
        for e in re.findall(r'class="leisure-card-excerpt">(.*?)</p>', body, re.S)
    ]
    titles = [
        re.sub(r"\s+", " ", t).strip()
        for t in re.findall(r'class="leisure-card-title[^"]*"[^>]*>\s*<a[^>]*>(.*?)</a>', body, re.S)
    ]
    return {
        "path": path,
        "status": status,
        "location": headers.get("Location", ""),
        "lang": (re.search(r'<html[^>]*\blang="([^"]+)"', body) or [None, None])[1],
        "title": (re.search(r"<title>(.*?)</title>", body, re.S) or [None, ""])[1].strip(),
        "canonical": (re.search(r'<link rel="canonical" href="([^"]+)"', body) or [None, ""])[1],
        "hreflang": re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', body),
        "og_locale": (re.search(r'property="og:locale" content="([^"]+)"', body) or [None, ""])[1],
        "leisure_cards": len(excerpts),
        "card_titles": titles,
        "card_excerpts": excerpts,
        "b2_notice": "language-fallback-notice" in body,
    }


def main():
    out = {"base": BASE, "user_agent": UA, "paths": {}}
    for path in sys.argv[1:] or ["/lazer/", "/en/lazer/", "/lazer/?county=dublin"]:
        out["paths"][path] = probe(path)

    # byte-compare the rendered /lazer/ excerpts against REST post_excerpt
    rest = []
    for p in (1, 2, 3):
        rest.extend(json.load(open(f"stage7-work/source/leisure-page-{p}.json")))
    rest_excerpt_by_title = {}
    for r in rest:
        t = re.sub(r"\s+", " ", r["title"]["rendered"]).strip()
        rest_excerpt_by_title[t] = re.sub(
            r"\s+", " ", re.sub(r"<[^>]+>", " ", r["excerpt"]["rendered"])
        ).strip()
    lazer = out["paths"].get("/lazer/")
    if lazer:
        matched, mismatched, trimmed = 0, [], 0
        for title, exc in zip(lazer["card_titles"], lazer["card_excerpts"]):
            src = rest_excerpt_by_title.get(title)
            if src is None:
                mismatched.append([title, "no REST record with this title"])
                continue
            if exc == src:
                matched += 1
            elif exc == " ".join(src.split()[:18]) + "...":
                # leisure-card.php: wp_trim_words( get_the_excerpt(), 18, '...' )
                trimmed += 1
            else:
                mismatched.append([title, exc[:60], src[:60]])
        lazer["excerpt_vs_rest"] = {
            "identical": matched,
            "trim_18_words_then_dots": trimmed,
            "mismatched": mismatched,
        }
    print(json.dumps(out, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
