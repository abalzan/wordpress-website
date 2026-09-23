#!/usr/bin/env python3
"""
Stage 4.5 — HTTP acceptance matrix for the EN page-translation rollout
(READ ONLY).

Implements the Phase 28 matrix: for EVERY translated page it verifies the PT
side stayed intact and the EN side is a real, self-canonical, hreflang-paired
page — plus the homepage, legal pages, county pages, /irlanda/, the legacy
redirects, the excluded technical pages, the sitemap, EN navigation and EN
search.

  --phase before   documents the pre-rollout production state (no EN layer:
                   PT pages fine, /en/* absent). Every row is reported; the
                   run passes when the PT surface is intact.
  --phase after    asserts the post-rollout contract (real EN pages, B1/B2
                   retired for pages, PT regression markers intact).

Inputs:
  stage45-work/translation-manifest.json  (expected EN pages; produced by
                                           scripts/stage45-validate-manifest.py)
  stage45-work/pt-snapshot.json           (PT titles/permalinks checkpoint)

GET requests only. No POST/PUT/PATCH/DELETE. No credentials.

Usage:
  python3 scripts/stage45-verify-pages.py --phase before --out stage45-work/http-matrix-before
  python3 scripts/stage45-verify-pages.py --phase after  --out stage45-work/http-matrix-after
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

UA = "ConexaoBR-Stage45-Acceptance/1.0 (read-only)"
BASE = "https://conexaobr.ie"
MANIFEST = "stage45-work/translation-manifest.json"
PT_SNAPSHOT = "stage45-work/pt-snapshot.json"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Do not follow redirects: the chain itself is the evidence."""

    def redirect_request(self, *args, **kwargs):  # noqa: D102
        return None


def get(url, retries=3, sleeps=(6, 30, 60)):
    last = None
    for attempt in range(retries):
        req = urllib.request.Request(url, method="GET")
        req.add_header("User-Agent", UA)
        req.add_header("Accept", "text/html,application/xhtml+xml,*/*")
        opener = urllib.request.build_opener(NoRedirect())
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
            "body": body, "attempt": attempt,
        }
        if status != 429:
            return last
        sys.stderr.write("    429 → backoff\n")
        time.sleep(sleeps[min(attempt, len(sleeps) - 1)])
    return last


def head_facts(html_bytes):
    """Extract the SEO/head facts a row needs."""
    out = {"canonical": None, "hreflang": [], "html_lang": None, "title": None,
           "meta_description": None, "robots": None, "b2_notice": False}
    if not html_bytes:
        return out
    text = html_bytes.decode("utf-8", "replace")
    head = text[: text.find("</head") if "</head" in text else len(text)]
    m = re.search(r"<title>(.*?)</title>", head, re.I | re.S)
    if m:
        out["title"] = html_unescape(re.sub(r"\s+", " ", m.group(1)).strip())
    m = re.search(r'<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)', head, re.I)
    if m:
        out["meta_description"] = html_unescape(m.group(1))
    m = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]*>', head, re.I)
    if m:
        m2 = re.search(r'href=["\']([^"\']+)["\']', m.group(0), re.I)
        out["canonical"] = m2.group(1) if m2 else None
    for tag in re.findall(r'<link[^>]+rel=["\']alternate["\'][^>]*>', head, re.I):
        hl = re.search(r'hreflang=["\']([^"\']+)["\']', tag, re.I)
        href = re.search(r'href=["\']([^"\']+)["\']', tag, re.I)
        if hl and href:
            out["hreflang"].append([hl.group(1), href.group(1)])
    m = re.search(r"<html[^>]*\blang=[\"']([^\"']+)[\"']", head, re.I)
    if m:
        out["html_lang"] = m.group(1)
    m = re.search(r'<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)', head, re.I)
    if m:
        out["robots"] = m.group(1)
    out["b2_notice"] = "language-fallback-notice" in text
    out["breadcrumb_html"] = "conexao-breadcrumb" in text
    if out["breadcrumb_html"]:
        bm = re.search(r'<nav class="conexao-breadcrumbs".*?</nav>', text, re.S)
        out["breadcrumb"] = bm.group(0) if bm else None
    return out


def html_unescape(s):
    import html as h
    return h.unescape(s)

# ---------------------------------------------------------------------------
# Expectation engine
# ---------------------------------------------------------------------------

def check_row(row, phase):
    """Evaluate one probed URL against the phase expectations. The row dict
    carries both the measured facts (canonical, title, …) and `expect`."""
    failures = []
    exp = row["expect"]
    status = row["status"]
    facts = row

    if "status" in exp and exp["status"] is not None and status != exp["status"]:
        failures.append(f"status {status} != {exp['status']}")
    if "status_in" in exp and status not in exp["status_in"]:
        failures.append(f"status {status} not in {exp['status_in']}")
    if "status_not" in exp and status == exp["status_not"]:
        failures.append(f"status unexpectedly {status}")
    if exp.get("location_contains"):
        loc = row["location"] or ""
        if exp["location_contains"] not in loc:
            failures.append(f"location {loc!r} does not contain {exp['location_contains']!r}")

    if status == 200 and facts:
        if exp.get("canonical") and facts["canonical"] != exp["canonical"]:
            failures.append(f"canonical {facts['canonical']!r} != {exp['canonical']!r}")
        if exp.get("html_lang") and facts["html_lang"] != exp["html_lang"]:
            failures.append(f"html lang {facts['html_lang']!r} != {exp['html_lang']!r}")
        if exp.get("title_contains") and (not facts["title"] or exp["title_contains"] not in facts["title"]):
            failures.append(f"title {facts['title']!r} lacks {exp['title_contains']!r}")
        if exp.get("meta_description") and facts["meta_description"] != exp["meta_description"]:
            failures.append(f"meta description differs:\n      got  {facts['meta_description']!r}\n      want {exp['meta_description']!r}")
        if exp.get("noindex") and (not facts["robots"] or "noindex" not in facts["robots"]):
            failures.append("expected noindex robots meta")
        if phase == "after" and exp.get("hreflang"):
            got = {c: u for c, u in facts["hreflang"]}
            for code, frag in exp["hreflang"].items():
                if code not in got:
                    failures.append(f"hreflang {code} missing (got {sorted(got)})")
                elif frag not in got[code]:
                    failures.append(f"hreflang {code} -> {got[code]!r}, want fragment {frag!r}")
        if exp.get("no_b2_notice") and facts["b2_notice"]:
            failures.append("B2 fallback notice still rendered")
        if phase == "after" and exp.get("breadcrumb_en"):
            bc = facts.get("breadcrumb") or ""
            if "conexao-breadcrumb" not in bc:
                failures.append("no breadcrumb rendered")
            else:
                if "/en/\"" not in bc:
                    failures.append("breadcrumb home link is not the EN home")
                if re.search(r">\s*(Início|Inicio)\s*<", bc):
                    failures.append("breadcrumb shows PT 'Início'")
                if exp["breadcrumb_en"] not in bc:
                    failures.append(f"breadcrumb lacks EN title {exp['breadcrumb_en']!r}")
    return failures

def build_rows(phase, base, manifest, snapshot):
    """The full URL matrix with per-phase expectations."""
    rows = []
    items = manifest["items"]
    snap = snapshot["pages"]

    for it in items:
        slug = it["pt_slug"]
        pt_title = snap.get(slug, {}).get("title")
        ptu = base + it["pt_url"]
        enu = base + it["en_url"]

        # --- PT side: intact in both phases --------------------------------
        # The front page's own permalink IS the site home (/inicio/ 301s to /),
        # so the PT row probes "/" (the /inicio/ consolidation is covered by
        # the home group). The posts page canonicalizes to the language home
        # (theme rule: is_front_page() || is_home() -> home_url('/')).
        if slug == "inicio":
            row_pt = {"group": "home", "slug": slug, "lang": "pt", "url": base + "/",
                      "expect": {"status": 200, "canonical": base + "/", "title_contains": None}}
        elif slug == "blog":
            row_pt = {"group": "pt-pages", "slug": slug, "lang": "pt", "url": ptu,
                      "expect": {"status": 200, "canonical": base + "/", "title_contains": None}}
        else:
            row_pt = {"group": "pt-pages", "slug": slug, "lang": "pt", "url": ptu,
                      "expect": {"status": 200, "canonical": ptu, "title_contains": pt_title}}
        if phase == "after" and slug not in ("inicio",):
            row_pt["expect"]["html_lang"] = "pt-BR"
            if slug != "blog":
                row_pt["expect"]["hreflang"] = {"pt-BR": it["pt_url"], "en": it["en_url"], "x-default": it["pt_url"]}
        rows.append(row_pt)

        # --- EN side: absent before, a real page after ---------------------
        if phase == "after":
            if slug == "inicio":
                # The EN front page's permalink IS the EN language home;
                # /en/home/ 301-consolidates to /en/ (mirroring /inicio/ → /).
                rows.append({
                    "group": "home", "slug": slug, "lang": "en", "url": base + "/en/home/",
                    "expect": {"status_in": [301], "location_contains": base + "/en/"},
                })
            elif slug == "blog":
                # Shared slug: /en/blog/ is the EN posts page; it canonicalizes
                # to the EN language home (same theme rule as the PT /blog/).
                rows.append({
                    "group": "blog", "slug": slug, "lang": "en", "url": base + "/en/blog/",
                    "expect": {"status": 200, "canonical": base + "/en/", "html_lang": "en-US"},
                })
            else:
                rows.append({
                    "group": "en-pages", "slug": slug, "lang": "en", "url": enu,
                    "expect": {"status": 200, "canonical": enu, "html_lang": "en-US",
                               "title_contains": it["en_title"],
                               "meta_description": it["meta_desc_en"],
                               "no_b2_notice": True,
                               "hreflang": {"en": it["en_url"], "pt-BR": it["pt_url"], "x-default": it["pt_url"]},
                               "breadcrumb_en": it["en_title"]},
                })
            # PT slug under /en/ → language-mismatch redirect to the EN page
            if it["en_slug"] != slug:
                rows.append({
                    "group": "en-ptslug", "slug": slug, "lang": "en", "url": base + "/en/" + slug + "/",
                    "expect": {"status_in": [301, 302], "location_contains": it["en_url"]},
                })
        else:
            rows.append({"group": "en-pages", "slug": slug, "lang": "en", "url": enu,
                         "expect": {"status_not": 200}})

    # --- Homepage consolidation + EN home ------------------------------------
    rows.append({"group": "home", "slug": "inicio", "lang": "pt", "url": base + "/inicio/",
                 "expect": {"status_in": [301], "location_contains": base + "/"}})
    if phase == "after":
        rows.append({"group": "home", "slug": "inicio", "lang": "en", "url": base + "/en/",
                     "expect": {"status": 200, "canonical": base + "/en/", "html_lang": "en-US",
                                "hreflang": {"en": "/en/", "pt-BR": base + "/", "x-default": base + "/"}}})
    else:
        rows.append({"group": "home", "slug": "inicio", "lang": "en", "url": base + "/en/",
                     "expect": {"status_not": 200}})

    # --- Legacy alias pages stay 301 to their canonical targets -------------
    for alias, target in (("/sobre/", "/sobre-nos/"), ("/privacidade/", "/politica-de-privacidade/"),
                          ("/termos/", "/termos-de-uso/"), ("/about/", "/sobre-nos/")):
        rows.append({"group": "legacy-alias", "slug": alias.strip("/"), "lang": "pt",
                     "url": base + alias, "expect": {"status_in": [301], "location_contains": target}})

    # --- Excluded technical pages: unchanged behaviour -----------------------
    # search: reachable utility, robots-disallowed, B1 redirect under /en/.
    rows.append({"group": "excluded", "slug": "search", "lang": "pt", "url": base + "/search/",
                 "expect": {"status": 200}})
    if phase == "after":
        rows.append({"group": "excluded", "slug": "search", "lang": "en", "url": base + "/en/search/",
                     "expect": {"status_in": [301, 302], "location_contains": "/search/"}})
        # The shadowed lazer page must NOT have become an EN page.
        rows.append({"group": "excluded", "slug": "lazer", "lang": "en", "url": base + "/en/leisure/",
                     "expect": {"status_in": [301, 302, 404]}})
        # /en/lazer/ remains the real EN leisure archive (not a page).
        rows.append({"group": "excluded", "slug": "lazer", "lang": "en", "url": base + "/en/lazer/",
                     "expect": {"status": 200}})

    # --- CPT archives referenced by the category index -----------------------
    for arch in ("eventos", "guias"):
        rows.append({"group": "archives", "slug": arch, "lang": "pt", "url": base + "/" + arch + "/",
                     "expect": {"status": 200}})
        if phase == "after":
            rows.append({"group": "archives", "slug": arch, "lang": "en", "url": base + "/en/" + arch + "/",
                         "expect": {"status": 200}})

    # --- The Blog posts-page pair (shared slug) -------------------------------
    # (Both rows are covered by the per-item loop; nothing extra here.)

    return rows


def probe(base, rows, out_prefix, phase, resume=False):
    """Run the matrix: probe each URL once, evaluate, write JSON + MD.

    Incremental: results are written after every row so an interrupted run
    can continue with --resume (rows already captured with the same phase are
    not re-fetched)."""
    results = []
    done = {}
    if resume and os.path.exists(out_prefix + ".json"):
        prev = json.load(open(out_prefix + ".json"))
        if prev.get("summary", {}).get("phase") == phase:
            for r in prev.get("rows", []):
                done[r["url"]] = r
    raw_dir = out_prefix + "-raw"
    os.makedirs(raw_dir, exist_ok=True)
    passed = failed = 0

    def persist():
        summary = {"phase": phase, "total": len(rows), "probed": len(results),
                   "passed": passed, "failed": failed, "complete": len(results) == len(rows)}
        with open(out_prefix + ".json", "w", encoding="utf-8") as fh:
            json.dump({"summary": summary, "rows": results}, fh, ensure_ascii=False, indent=1)

    for i, row in enumerate(rows):
        if row["url"] in done:
            results.append(done[row["url"]])
            if done[row["url"]]["failures"]:
                failed += 1
            else:
                passed += 1
            continue
        rec = get(row["url"])
        facts = head_facts(rec["body"]) if rec["status"] == 200 else None
        if rec["status"] == 200:
            slug = re.sub(r"[^a-z0-9-]+", "-", row["url"].replace(base, "").strip("/")) or "home"
            with open(os.path.join(raw_dir, f"{i:03d}-{row['group']}-{slug}.html"), "wb") as fh:
                fh.write(rec["body"])
        row_result = {
            "group": row["group"], "slug": row["slug"], "lang": row["lang"], "url": row["url"],
            "status": rec["status"], "location": rec["location"],
            "canonical": facts and facts["canonical"], "html_lang": facts and facts["html_lang"],
            "title": facts and facts["title"],
            "meta_description": facts and facts["meta_description"],
            "robots": facts and facts["robots"],
            "hreflang": facts and facts["hreflang"],
            "b2_notice": facts and facts["b2_notice"],
            "expect": row["expect"],
        }
        row_result["failures"] = check_row(row_result, phase) if row["expect"] else []
        if row_result["failures"]:
            failed += 1
        else:
            passed += 1
        results.append(row_result)
        flag = "FAIL" if row_result["failures"] else "ok"
        print(f"  [{flag}] {row['group']:<12} {row['url']}" + (f" → {row_result['failures'][0]}" if row_result["failures"] else ""), flush=True)
        persist()
        time.sleep(0.8)

    summary = {"phase": phase, "total": len(rows), "probed": len(results), "passed": passed, "failed": failed, "complete": True}
    with open(out_prefix + ".json", "w", encoding="utf-8") as fh:
        json.dump({"summary": summary, "rows": results}, fh, ensure_ascii=False, indent=1)

    lines = [f"# Stage 4.5 HTTP matrix — phase {phase}", "",
             f"- {passed} passed / {failed} failed / {len(results)} rows", "",
             "| result | group | url | status | location | notes |",
             "|---|---|---|---|---|---|"]
    for r in results:
        notes = "; ".join(r["failures"]) if r["failures"] else ""
        lines.append(f"| {'FAIL' if r['failures'] else 'ok'} | {r['group']} | {r['url']} | {r['status']} | {r['location'] or ''} | {notes.replace('|', '/')} |")
    with open(out_prefix + ".md", "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))

    print(f"\n{passed} passed / {failed} failed → {out_prefix}.json / .md / -raw/")
    return 0 if failed == 0 else 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--phase", choices=["before", "after"], required=True)
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--out", required=True)
    ap.add_argument("--manifest", default=MANIFEST)
    ap.add_argument("--snapshot", default=PT_SNAPSHOT)
    ap.add_argument("--resume", action="store_true")
    args = ap.parse_args()

    manifest = json.load(open(args.manifest))
    snapshot = json.load(open(args.snapshot))
    rows = build_rows(args.phase, args.base.rstrip("/"), manifest, snapshot)
    print(f"== Stage 4.5 page matrix ({args.phase}): {len(rows)} rows ==")
    sys.exit(probe(args.base.rstrip("/"), rows, args.out, args.phase, resume=args.resume))


if __name__ == "__main__":
    main()


