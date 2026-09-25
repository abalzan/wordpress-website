#!/usr/bin/env python3
"""Stage 6 — EN Jobs HTTP acceptance matrix + B2 transition proof.

Runs the BEFORE/AFTER matrix against a running WordPress instance (the local
Docker site or the sandbox clone). Every translated job is tested — never a
sample.

Usage:
    python3 scripts/stage6-job-verify.py --base http://localhost:8080 --phase before
    python3 scripts/stage6-job-verify.py --base http://localhost:8080 --phase after \
        --wp-eval "/tmp/wpclone/wp eval" --write stage6-work/http-matrix-after.json

`--wp-eval` is required in the `after` phase for the dynamic B2 state-A probe
(a throwaway untranslated PT job is created, measured over HTTP and deleted).

The «Vagas»/“Openings” preview section was later removed from the Jobs landing
pages by product decision (rendering only — the job records, the language-aware
query in inc/empregos-landing.php and the CSS are retained). The landing-card
assertions below are therefore reported as SKIP while that surface is not
rendered, so this matrix stays valid in both states; the query's membership /
B2-set contract is asserted in-process by
wp-content/themes/conexao-br-irlanda/tests/test-job-en-translation.php.

Exit code is non-zero when any check fails.
"""
import argparse
import json
import re
import subprocess
import sys
import urllib.request
import urllib.error

PASSED = []
FAILED = []


def check(ok, name, detail=""):
    row = {"check": name, "pass": bool(ok), "detail": detail}
    (PASSED if ok else FAILED).append(row)
    print(("  PASS: " if ok else "  FAIL: ") + name + ("" if ok else f" — {detail}"))


def fetch(base, path, follow=False):
    """GET a URL, returning (status, final_url, headers, body_text)."""
    url = base.rstrip("/") + path
    req = urllib.request.Request(url, headers={"User-Agent": "stage6-verify/1.0"})

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k):
            return None

    opener = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    try:
        resp = opener.open(req, timeout=30)
        return resp.status, resp.geturl(), dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, url, dict(e.headers or {}), e.read().decode("utf-8", "replace")


def html_lang(html):
    m = re.search(r'<html lang="([^"]+)"', html)
    return m.group(1) if m else ""


def canonical(html):
    m = re.search(r'rel="canonical" href="([^"]+)"', html)
    return m.group(1) if m else ""


def hreflangs(html):
    out = {}
    for m in re.finditer(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', html):
        out[m.group(1)] = m.group(2)
    return out


def title_tag(html):
    m = re.search(r"<title>([^<]*)</title>", html)
    return m.group(1).strip() if m else ""


def meta_description(html):
    m = re.search(r'name="description" content="([^"]*)"', html)
    return m.group(1) if m else ""


def jobs_section(html):
    m = re.search(r'<section class="empregos-jobs".*?</section>', html, re.S)
    return m.group(0) if m else ""


def job_cards(section_html):
    """[(url, title)] of every job card in the jobs section."""
    return re.findall(r'archive-card-title[^>]*>\s*<a href="([^"]+)"[^>]*>([^<]+)</a>', section_html)


def breadcrumbs(html):
    m = re.search(r'<ol class="conexao-breadcrumb-list">(.*?)</ol>', html, re.S)
    if not m:
        return []
    crumbs = []
    for li in re.finditer(r"<li[^>]*>(.*?)</li>", m.group(1), re.S):
        body = li.group(1)
        link = re.search(r'href="([^"]+)"[^>]*>([^<]+)</a>', body)
        cur = re.search(r'breadcrumb-current[^>]*>([^<]+)<', body)
        if link:
            crumbs.append({"url": link.group(1), "label": link.group(2).strip()})
        elif cur:
            crumbs.append({"url": "", "label": cur.group(1).strip()})
    return crumbs


def _path(permalink):
    parts = permalink.split("/")
    return "/" + "/".join(parts[3:])


def load_pairs(inventory_path):
    """Read the PT↔EN job pairs from an inventory JSON produced by
    scripts/stage6-job-inventory.php (each row carries `translation` = the
    sibling post ID)."""
    inv = json.load(open(inventory_path, encoding="utf-8"))
    by_id = {int(r["id"]): r for r in inv.get("pt", []) + inv.get("en", [])}
    pairs = []
    for row in inv.get("pt", []):
        pair = {
            "pt_id": int(row["id"]),
            "pt_slug": row["slug"],
            "pt_title": row["title"],
            "pt_url_path": _path(row["permalink"]),
            "content_hash": row["content_hash"],
            "en_id": 0,
            "en_slug": "",
            "en_title": "",
            "en_url_path": "",
        }
        sib = int(row.get("translation", 0) or 0)
        if sib and sib in by_id:
            er = by_id[sib]
            pair.update(
                en_id=int(er["id"]),
                en_slug=er["slug"],
                en_title=er["title"],
                en_url_path=_path(er["permalink"]),
            )
        pairs.append(pair)
    return pairs, inv


def wp_eval(wp_eval_cmd, code):
    """Run a WP-CLI eval snippet, returning stdout (single-quote shell escaped
    so PHP `$variables` survive the shell)."""
    cmd = f"{wp_eval_cmd} '" + code.replace("'", "'\"'\"'") + "'"
    out = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=60)
    return out.stdout.strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True, help="Base URL, e.g. http://localhost:8080")
    ap.add_argument("--phase", choices=["before", "after"], required=True)
    ap.add_argument("--inventory", required=True, help="Inventory JSON for the phase")
    ap.add_argument("--wp-eval", default="", help="WP-CLI eval prefix for the B2 probe (after phase)")
    ap.add_argument("--write", default="", help="Write the machine-readable matrix here")
    args = ap.parse_args()

    base = args.base.rstrip("/")
    pairs, inv = load_pairs(args.inventory)
    print(f"== Stage 6 HTTP matrix — phase={args.phase} base={base} ==")
    print(f"   pairs discovered: {len(pairs)}")

    # ------------------------------------------------------------------
    print("\n-- Landing pages --")
    status, _, _, pt_landing = fetch(base, "/empregos/")
    check(status == 200, "PT /empregos/ is 200")
    check(html_lang(pt_landing).startswith("pt"), "PT /empregos/ lang is pt-BR", html_lang(pt_landing))
    check(canonical(pt_landing).rstrip("/").endswith("/empregos"), "PT /empregos/ self-canonical", canonical(pt_landing))

    # The «Vagas»/“Openings” preview section is intentionally not rendered on
    # the Jobs landing pages (rendering only — see the module docstring). While
    # it is absent these card checks have no subject and are reported as SKIP.
    pt_section = jobs_section(pt_landing)
    if pt_section:
        pt_cards = job_cards(pt_section)
        check(len(pt_cards) == len(pairs), "PT /empregos/ lists exactly the public PT jobs", f"{len(pt_cards)} cards vs {len(pairs)} pairs")
        for pair in pairs:
            found = [c for c in pt_cards if c[0].rstrip("/").endswith("/empregos/" + pair["pt_slug"])]
            check(bool(found), f"PT card for {pair['pt_slug']} links to the PT job", str(pt_cards))
    else:
        print("  SKIP: PT /empregos/ renders no «Vagas» preview section (intentionally removed)")

    status, _, _, en_landing = fetch(base, "/en/jobs/")
    check(status == 200, "EN /en/jobs/ is 200")
    check(html_lang(en_landing) == "en-US", "EN /en/jobs/ lang is en-US", html_lang(en_landing))
    check(canonical(en_landing).rstrip("/").endswith("/en/jobs"), "EN /en/jobs/ self-canonical", canonical(en_landing))
    hl = hreflangs(en_landing)
    check(hl.get("en", "").rstrip("/").endswith("/en/jobs"), "/en/jobs/ hreflang en → itself", str(hl))
    check(hl.get("pt-BR", "").rstrip("/").endswith("/empregos"), "/en/jobs/ hreflang pt-BR → /empregos/", str(hl))
    check(hl.get("x-default") == hl.get("pt-BR"), "/en/jobs/ x-default → PT", str(hl))

    en_section = jobs_section(en_landing)
    en_cards = job_cards(en_section)
    if not en_section:
        print("  SKIP: EN /en/jobs/ renders no “Openings” preview section (intentionally removed)")
    elif args.phase == "before":
        # B2 state: no EN jobs yet — the listing shows the PT records (B2 set),
        # never empty, linking to their canonical PT URLs.
        check(len(en_cards) == len(pairs), "BEFORE: /en/jobs/ shows the B2 set (PT jobs, EN chrome) — not empty", f"{len(en_cards)} cards")
        for pair in pairs:
            check(
                any(c[0].rstrip("/").endswith("/empregos/" + pair["pt_slug"]) for c in en_cards),
                f"BEFORE: /en/jobs/ B2 card for {pair['pt_slug']} links to the canonical PT job",
                str(en_cards),
            )
    else:
        translated = [p for p in pairs if p.get("en_slug")]
        check(len(en_cards) == len(translated), "AFTER: /en/jobs/ lists exactly the EN jobs", f"{len(en_cards)} cards vs {len(translated)} translated")
        seen = set()
        for url, ctitle in en_cards:
            check(url not in seen, "no duplicate job card on /en/jobs/", url)
            seen.add(url)
            check("/en/empregos/" in url, "every /en/jobs/ card links to an EN job detail", url)
        for pair in translated:
            check(
                any(c[0].rstrip("/").endswith("/en/empregos/" + pair["en_slug"]) for c in en_cards),
                f"AFTER: EN card for {pair['en_slug']} is listed and links to the EN job",
                str(en_cards),
            )
            check(
                not any(c[0].rstrip("/").endswith("/empregos/" + pair["pt_slug"]) for c in en_cards),
                f"AFTER: no PT-primary card for {pair['pt_slug']} on /en/jobs/",
                str(en_cards),
            )
        # Card-level language correctness.
        for url, ctitle in en_cards:
            for pair in translated:
                if url.rstrip("/").endswith("/en/empregos/" + pair["en_slug"]):
                    check(ctitle.strip() == pair["en_title"], f"card title is the EN title for {pair['en_slug']}", ctitle)


    # ------------------------------------------------------------------
    print("\n-- Job detail pages --")
    for pair in pairs:
        pt_path = pair["pt_url_path"]
        status, _, _, pt_html = fetch(base, pt_path)
        check(status == 200, f"PT job {pair['pt_slug']} is 200")
        check(html_lang(pt_html).startswith("pt"), f"PT job {pair['pt_slug']} lang pt-BR", html_lang(pt_html))
        check(canonical(pt_html).rstrip("/") == (base + pt_path).rstrip("/"), f"PT job {pair['pt_slug']} self-canonical", canonical(pt_html))
        check(pair["pt_title"] in title_tag(pt_html), f"PT job {pair['pt_slug']} title in <title>", title_tag(pt_html))
        check("language-fallback-notice" not in pt_html, f"PT job {pair['pt_slug']} has no B2 notice")

        pt_hl = hreflangs(pt_html)
        if args.phase == "after" and pair.get("en_slug"):
            check(pt_hl.get("en", "").rstrip("/") == (base + pair["en_url_path"]).rstrip("/"), f"PT job {pair['pt_slug']} hreflang en → EN job", str(pt_hl))
            check(pt_hl.get("x-default", "").rstrip("/") == (base + pt_path).rstrip("/"), f"PT job {pair['pt_slug']} x-default → PT", str(pt_hl))
        else:
            check("en" not in pt_hl, f"BEFORE: PT job {pair['pt_slug']} emits no EN alternate", str(pt_hl))

        # Wrong-language request: the PT slug under /en/.
        status, final, _, _ = fetch(base, "/en/empregos/" + pair["pt_slug"] + "/")
        if args.phase == "before":
            # Approved B2 single: 200, EN shell, PT body, canonical → PT.
            check(status == 200, f"BEFORE: /en/empregos/{pair['pt_slug']}/ is 200 (B2)", str(status))
            _, _, _, b2_html = fetch(base, "/en/empregos/" + pair["pt_slug"] + "/")
            check("language-fallback-notice" in b2_html, f"BEFORE: /en/empregos/{pair['pt_slug']}/ shows the B2 notice")
            check(html_lang(b2_html) == "en-US", f"BEFORE: B2 shell lang en-US for {pair['pt_slug']}", html_lang(b2_html))
            check(canonical(b2_html).rstrip("/").endswith("/empregos/" + pair["pt_slug"]), f"BEFORE: B2 canonical → PT for {pair['pt_slug']}", canonical(b2_html))
        else:
            # The PT master has a real translation: never render PT under /en/.
            check(status in (301, 302) and final.rstrip("/").endswith("/empregos/" + pair["pt_slug"]), f"AFTER: /en/empregos/{pair['pt_slug']}/ redirects to the PT job (replaced master)", f"{status} -> {final}")

        if args.phase == "after" and pair.get("en_slug"):
            en_path = pair["en_url_path"]
            status, _, _, en_html = fetch(base, en_path)
            check(status == 200, f"EN job {pair['en_slug']} is 200")
            check(html_lang(en_html) == "en-US", f"EN job {pair['en_slug']} lang en-US", html_lang(en_html))
            check(canonical(en_html).rstrip("/") == (base + en_path).rstrip("/"), f"EN job {pair['en_slug']} self-canonical", canonical(en_html))
            check(pair["en_title"] in title_tag(en_html), f"EN job {pair['en_slug']} EN title in <title>", title_tag(en_html))
            check("language-fallback-notice" not in en_html, f"EN job {pair['en_slug']} has no B2 notice")
            en_hl = hreflangs(en_html)
            check(en_hl.get("en", "").rstrip("/") == (base + en_path).rstrip("/"), f"EN job {pair['en_slug']} hreflang en → itself", str(en_hl))
            check(en_hl.get("pt-BR", "").rstrip("/") == (base + pt_path).rstrip("/"), f"EN job {pair['en_slug']} hreflang pt-BR → PT job", str(en_hl))
            check(en_hl.get("x-default", "").rstrip("/") == (base + pt_path).rstrip("/"), f"EN job {pair['en_slug']} x-default → PT", str(en_hl))
            md = meta_description(en_html)
            check(bool(md) and "job opportunities" in md.lower(), f"EN job {pair['en_slug']} EN meta description", md[:80])
            crumbs = breadcrumbs(en_html)
            check(len(crumbs) == 3, f"EN job {pair['en_slug']} breadcrumb has 3 levels", str(crumbs))
            if len(crumbs) == 3:
                check(crumbs[1]["url"].rstrip("/").endswith("/en/jobs"), f"EN job {pair['en_slug']} breadcrumb Jobs → /en/jobs/", str(crumbs))
                check(crumbs[2]["label"] == pair["en_title"], f"EN job {pair['en_slug']} breadcrumb ends on the EN title", str(crumbs))
            # No Portuguese prose leaks (official names/identifiers exempt).
            body = re.sub(r"<[^>]+>", " ", en_html)
            check("compartilhamos novas oportunidades" not in body, f"EN job {pair['en_slug']} has no PT body leak")
            check("vagas estão separadas" not in body, f"EN job {pair['en_slug']} has no PT body leak (2)")


    # ------------------------------------------------------------------
    print("\n-- Search (language-aware) --")
    for pair in pairs:
        pt_term = pair["pt_slug"].split("-")[0]
        status, _, _, s_pt = fetch(base, "/?s=" + pt_term)
        check(status == 200 and pair["pt_slug"] in s_pt, f"PT search finds the PT job ({pt_term})")
        if args.phase == "after" and pair.get("en_slug"):
            en_term = pair["en_slug"].split("-")[0]
            status, _, _, s_en = fetch(base, "/en/?s=" + en_term)
            check(status == 200 and pair["en_slug"] in s_en, f"EN search finds the EN job ({en_term})")
            # No duplicate identity: the EN results must not also carry the PT URL.
            check(not re.search(r'href="[^"]*/empregos/' + re.escape(pair["pt_slug"]) + r'/"', s_en), f"EN search for {en_term} does not duplicate the PT job URL")
            status, _, _, s_pt2 = fetch(base, "/?s=" + pt_term)
            check(not re.search(r'href="[^"]*/en/empregos/', s_pt2), f"PT search for {pt_term} does not surface EN job URLs")

    # ------------------------------------------------------------------
    print("\n-- Sitemap --")
    status, _, _, sm = fetch(base, "/sitemap.xml")
    check(status == 200, "sitemap.xml is 200")
    for pair in pairs:
        pt_url = base + pair["pt_url_path"]
        check(sm.count("<loc>" + pt_url + "</loc>") == 1, f"sitemap lists the PT job exactly once ({pair['pt_slug']})")
        if args.phase == "after" and pair.get("en_slug"):
            en_url = base + pair["en_url_path"]
            check(sm.count("<loc>" + en_url + "</loc>") == 1, f"sitemap lists the EN job exactly once ({pair['en_slug']})")
        else:
            check("/en/empregos/" not in sm, "BEFORE: sitemap has no EN job URLs (B2 URLs are never indexable)")

    # ------------------------------------------------------------------
    if args.phase == "after":
        print("\n-- B2 state machine probe (state A: untranslated future job) --")
        if not args.wp_eval:
            print("  SKIP: --wp-eval not provided")
        else:
            probe_code = (
                "$id = wp_insert_post(array('post_type'=>'job','post_name'=>'s6-b2-http-probe',"
                "'post_title'=>'S6 B2 HTTP Probe','post_content'=>'<p>Conteúdo PT de sonda.</p>',"
                "'post_status'=>'publish')); "
                "if (is_wp_error($id)) { echo 'ERR:' . $id->get_error_message(); } else { "
                "pll_set_post_language($id, 'pt'); echo 'ID:' . $id; }"
            )
            out = wp_eval(args.wp_eval, probe_code)
            m = re.search(r"ID:(\d+)", out)
            check(bool(m), "B2 probe job created", out[:120])
            if m:
                probe_id = m.group(1)
                status, _, _, probe_html = fetch(base, "/en/empregos/s6-b2-http-probe/")
                check(status == 200, "STATE A: untranslated PT job under /en/ is 200 (B2 shell)", str(status))
                check("language-fallback-notice" in probe_html, "STATE A: B2 notice present on the probe")
                check("Conteúdo PT de sonda" in probe_html, "STATE A: probe renders the PT body under EN chrome")
                check(canonical(probe_html).rstrip("/").endswith("/empregos/s6-b2-http-probe"), "STATE A: probe canonical → PT", canonical(probe_html))
                # The probe used to join the /en/jobs/ listing as a B2 card. That
                # listing section was removed by product decision (rendering
                # only), so the landing renders no job cards at all now; the
                # probe's B2 ELIGIBILITY stays asserted in-process by
                # tests/test-job-en-translation.php.
                _, _, _, en_landing_probe = fetch(base, "/en/jobs/")
                probe_section = jobs_section(en_landing_probe)
                if probe_section:
                    probe_cards = job_cards(probe_section)
                    check(
                        any(c[0].rstrip("/").endswith("/empregos/s6-b2-http-probe") for c in probe_cards),
                        "STATE A: the untranslated probe appears on /en/jobs/ as a B2 card (canonical PT URL)",
                        str(probe_cards),
                    )
                else:
                    print("  SKIP: STATE A probe card — the “Openings” preview section is not rendered")
                # Cleanup.
                wp_eval(args.wp_eval, f"wp_delete_post({probe_id}, true); echo 'deleted';")
                _, _, _, en_landing_after = fetch(base, "/en/jobs/")
                check(
                    "s6-b2-http-probe" not in en_landing_after,
                    "probe removed from /en/jobs/ after deletion",
                )

    # ------------------------------------------------------------------
    print("\n-- Blog regression (Stage 5 surfaces stay intact) --")
    for path, expect_lang in (("/blog/", "pt"), ("/en/blog/", "en")):
        status, _, _, bhtml = fetch(base, path)
        check(status == 200, f"blog archive {path} is 200")
        check(html_lang(bhtml).startswith(expect_lang), f"blog archive {path} lang {expect_lang}", html_lang(bhtml))

    # ------------------------------------------------------------------
    total = len(PASSED) + len(FAILED)
    print(f"\n== {len(PASSED)} passed, {len(FAILED)} failed (of {total}) ==")

    if args.write:
        with open(args.write, "w", encoding="utf-8") as fh:
            json.dump(
                {
                    "phase": args.phase,
                    "base": args.base,
                    "pairs": pairs,
                    "passed": PASSED,
                    "failed": FAILED,
                    "counts": {"passed": len(PASSED), "failed": len(FAILED), "total": total},
                },
                fh,
                indent=1,
                ensure_ascii=False,
            )
        print(f"wrote {args.write}")

    sys.exit(1 if FAILED else 0)


if __name__ == "__main__":
    main()

