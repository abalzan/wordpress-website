#!/usr/bin/env python3
"""Stage 5 — Blog EN translation HTTP verification matrix.

Runs the acceptance checks of the Blog translation against a running WordPress
(local Docker or the sandbox clone) and writes:

    stage5-work/http-matrix.json      machine-readable results
    stage5-work/http-matrix-after.md  human-readable table

Usage:
    python3 stage5-work/verify-blog-en.py http://localhost:8080
"""
import json
import os
import re
import sys
import urllib.error
import urllib.request
from collections import Counter

UA = "conexao-stage5-verify/1.0"
HERE = os.path.dirname(os.path.abspath(__file__))

PASS, FAIL = [], []


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        return exc.code, dict(exc.headers), exc.read().decode("utf-8", "replace")


def parse(body):
    return {
        "lang": (re.search(r'<html[^>]*\blang="([^"]+)"', body) or [None, ""])[1],
        "canonical": (re.search(r'<link rel="canonical" href="([^"]+)"', body) or [None, ""])[1],
        "hreflang": dict(re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', body)),
        "og_locale": (re.search(r'property="og:locale" content="([^"]+)"', body) or [None, ""])[1],
        "robots": (re.search(r'name="robots" content="([^"]+)"', body) or [None, ""])[1],
        "notice": "language-fallback-notice" in body,
        "cards": [
            re.sub(r"<[^>]+>", "", c).strip()
            for c in re.findall(r'class="archive-card-title">\s*<a[^>]*>(.*?)</a>', body, re.S)
        ],
        "body": body,
    }


def get(base, path):
    status, headers, body = fetch(base.rstrip("/") + path)
    data = parse(body)
    data.update({"path": path, "status": status, "location": headers.get("Location", "")})
    return data


def check(label, condition, detail=""):
    (PASS if condition else FAIL).append(label)
    print(("PASS  " if condition else "FAIL  ") + label + ((" — " + detail) if detail and not condition else ""))


def slim(data):
    return {k: v for k, v in data.items() if k != "body"}


def main():
    base = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"
    results = {}

    # --- PT archive stays Portuguese and self-canonical ---------------------
    pt = get(base, "/blog/")
    results["pt_blog"] = slim(pt)
    check("PT /blog/ 200", pt["status"] == 200)
    check("PT /blog/ lang=pt-BR", pt["lang"] == "pt-BR", pt["lang"])
    check("PT /blog/ self-canonical", pt["canonical"].endswith("/blog/"), pt["canonical"])
    check("PT /blog/ no EN fallback notice", not pt["notice"])
    check("PT /blog/ hreflang pair present", pt["hreflang"].get("en", "").endswith("/en/blog/"))
    check("PT /blog/ shows Portuguese post titles",
          any("Quem voc\u00ea se tornou" in c for c in pt["cards"]), str(pt["cards"][:2]))
    check("PT /blog/ has PT pagination", get(base, "/blog/page/2/")["status"] == 200)
    check("PT /blog/page/5/ 404 (out of range)", get(base, "/blog/page/5/")["status"] == 404)

    # --- EN archive is a real English archive ------------------------------
    en = get(base, "/en/blog/")
    results["en_blog"] = slim(en)
    check("EN /en/blog/ 200 (no redirect to PT)", en["status"] == 200, en["location"])
    check("EN /en/blog/ lang=en-US", en["lang"] == "en-US", en["lang"])
    check("EN /en/blog/ self-canonical", en["canonical"].endswith("/en/blog/"), en["canonical"])
    check("EN /en/blog/ hreflang -> PT /blog/", en["hreflang"].get("pt-BR", "").endswith("/blog/"))
    check("EN /en/blog/ no B2 fallback notice", not en["notice"])
    check("EN /en/blog/ og:locale=en_US", en["og_locale"] == "en_US", en["og_locale"])
    check("EN /en/blog/ not noindex", "noindex" not in en["robots"], en["robots"])
    check("EN /en/blog/ shows English titles",
          any("Who Have You Become" in c for c in en["cards"]), str(en["cards"][:2]))
    pt_titles = set(t for t in pt["cards"] if t)
    check("EN /en/blog/ shows no Portuguese card when an EN translation exists",
          not (set(en["cards"]) & pt_titles), str(set(en["cards"]) & pt_titles))
    check("EN /en/blog/ filter bar uses EN category labels",
          "Health &amp; Wellbeing" in en["body"] or "Health & Wellbeing" in en["body"])

    # --- EN pagination over the EN post set -------------------------------
    for page in (2, 3, 4):
        p = get(base, "/en/blog/page/%d/" % page)
        results["en_blog_page%d" % page] = slim(p)
        check("EN /en/blog/page/%d/ 200" % page, p["status"] == 200, str(p["status"]))
        check("EN /en/blog/page/%d/ English titles" % page,
              bool(p["cards"]) and not any(re.search(r"[\u00e3\u00e7\u00ea\u00e1\u00ed\u00f3\u00fa]", c) for c in p["cards"]))
        check("EN /en/blog/page/%d/ canonical collapses to archive" % page,
              p["canonical"].endswith("/en/blog/"), p["canonical"])

    # --- category filters (language-specific slugs, per architecture) ------
    en_cat = get(base, "/en/blog/?categoria=health-and-wellbeing")
    pt_cat = get(base, "/blog/?categoria=health-and-wellbeing")
    results["en_blog_en_category_filter"] = slim(en_cat)
    check("EN /en/blog/?categoria=<EN slug> filters the EN set",
          en_cat["status"] == 200 and bool(en_cat["cards"]) and set(en_cat["cards"]) != set(en["cards"]),
          "filtered=%s unfiltered=%s" % (en_cat["cards"][:2], en["cards"][:2]))
    check("EN view with a PT category slug does not return the EN set",
          set(get(base, "/en/blog/?categoria=saude-e-bem-estar")["cards"]) != set(en_cat["cards"]))
    check("PT filter bar uses PT category labels",
          "Sa\u00fade e Bem-estar" in pt_cat["body"])

    # --- category archives ------------------------------------------------
    en_term = get(base, "/en/category/health-and-wellbeing/")
    results["en_category_archive"] = slim(en_term)
    check("EN /en/category/<en-slug>/ 200", en_term["status"] == 200, str(en_term["status"]))
    check("EN category archive lang=en-US", en_term["lang"] == "en-US", en_term["lang"])
    check("EN category archive self-canonical", "health-and-wellbeing" in en_term["canonical"], en_term["canonical"])
    check("EN category archive has English posts", bool(en_term["cards"]))
    pt_term = get(base, "/category/saude-e-bem-estar/")
    check("PT category archive unchanged (PT posts)", pt_term["status"] == 200 and bool(pt_term["cards"]))

    # --- singles ----------------------------------------------------------
    pt_single = get(base, "/quem-voce-se-tornou-longe-de-casa/")
    en_single = get(base, "/en/who-have-you-become-away-from-home/")
    results["en_single"] = slim(en_single)
    check("EN single post 200", en_single["status"] == 200, str(en_single["status"]))
    check("EN single lang=en-US", en_single["lang"] == "en-US", en_single["lang"])
    check("EN single self-canonical", en_single["canonical"].endswith("/en/who-have-you-become-away-from-home/"), en_single["canonical"])
    check("EN single hreflang -> PT post", pt_single["canonical"].replace(base, "") in en_single["hreflang"].get("pt-BR", ""))
    check("EN single body is English", "Existe uma armadilha" not in en_single["body"] and "silent trap" in en_single["body"])
    check("EN single has no B2 notice", not en_single["notice"])
    check("PT single canonical unchanged", pt_single["canonical"].endswith("/quem-voce-se-tornou-longe-de-casa/"), pt_single["canonical"])
    check("PT single still Portuguese", "Existe uma armadilha" in pt_single["body"])
    check("PT single links to its EN translation (switcher)", "/en/who-have-you-become-away-from-home/" in pt_single["body"])

    # --- internal link localization ---------------------------------------
    internal = get(base, "/en/training-and-upskilling-in-laois/")
    results["en_single_training"] = slim(internal)
    check("EN post with external references renders", internal["status"] == 200, str(internal["status"]))
    check("EN post keeps external links untouched", "portlaoise" in internal["body"].lower() or "fetc" in internal["body"].lower())

    # --- navigation -------------------------------------------------------
    en_home = get(base, "/en/")
    results["en_home"] = slim(en_home)
    en_blog_hrefs = re.findall(r'href="([^"]*blog[^"]*)"', en_home["body"])
    check("EN navigation Blog link is /en/blog/", any(h.endswith("/en/blog/") for h in en_blog_hrefs), str(en_blog_hrefs[:3]))
    pt_blog_re = re.compile("^https?://[^/]+/blog/?$")
    check("EN navigation has no PT /blog/ leak", not any(pt_blog_re.match(h) for h in en_blog_hrefs))
    pt_home = get(base, "/")
    pt_blog_hrefs = re.findall(r'href="([^"]*blog[^"]*)"', pt_home["body"])
    check("PT navigation Blog link is /blog/", any(h.endswith("/blog/") for h in pt_blog_hrefs), str(pt_blog_hrefs[:3]))
    check("PT navigation has no /en/blog/ leak", not any("/en/blog/" in h for h in pt_blog_hrefs))

    # --- sitemap ----------------------------------------------------------
    status, _headers, sitemap = fetch(base.rstrip("/") + "/sitemap.xml")
    locs = re.findall(r"<loc>([^<]+)</loc>", sitemap)
    results["sitemap"] = {"status": status, "count": len(locs)}
    check("sitemap served", status == 200, str(status))
    check("sitemap lists PT blog URLs", any(l.endswith("/quem-voce-se-tornou-longe-de-casa/") for l in locs))
    check("sitemap lists EN blog URLs", any(l.endswith("/en/who-have-you-become-away-from-home/") for l in locs))
    check("sitemap lists the EN posts page", any(l.endswith("/en/blog/") for l in locs))
    check("sitemap lists the PT posts page", any(l.endswith("/blog/") for l in locs))
    dupe = [u for u, n in Counter(locs).items() if n > 1]
    check("sitemap has no duplicate URLs", not dupe, str(dupe[:5]))
    check("sitemap carries alternates for translated posts", 'hreflang="en"' in sitemap and 'hreflang="pt-BR"' in sitemap)

    # --- search -----------------------------------------------------------
    en_search = get(base, "/en/?s=cupcake")
    pt_search = get(base, "/?s=cupcake")
    results["en_search"] = slim(en_search)
    check("EN search returns the EN translation", "cupcake" in en_search["body"].lower())
    check("EN search does not show the PT post title", "CUPCAKE DA GR\u00c1NNY" not in en_search["body"])
    check("PT search still returns the PT post", "CUPCAKE DA GR\u00c1NNY" in pt_search["body"] or "cupcake" in pt_search["body"].lower())

    out_json = os.path.join(HERE, "http-matrix.json")
    with open(out_json, "w", encoding="utf-8") as handle:
        json.dump({"base": base, "results": results, "pass": PASS, "fail": FAIL}, handle, ensure_ascii=False, indent=1)

    with open(os.path.join(HERE, "http-matrix-after.md"), "w", encoding="utf-8") as handle:
        handle.write("# Stage 5 — Blog EN translation matrix\n\n")
        handle.write("Base: %s\n\n" % base)
        handle.write("| result | check |\n|---|---|\n")
        for label in PASS:
            handle.write("| PASS | %s |\n" % label)
        for label in FAIL:
            handle.write("| **FAIL** | %s |\n" % label)
        handle.write("\n%d passed, %d failed\n" % (len(PASS), len(FAIL)))

    print("\n%d passed, %d failed  ->  %s" % (len(PASS), len(FAIL), out_json))
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
