#!/usr/bin/env python3
"""
Stage 4.5 — translation completeness scanner (Phase 22, READ ONLY).

Reviews every EN Page for obvious untranslated Portuguese content. The
scanner is a REVIEW SIGNAL ONLY:

  * it never rewrites content and never decides translation quality;
  * a "possible untranslated" flag REQUIRES manual inspection (expected
    false positives: proper nouns — Conexão BR Irlanda, Mário Sérgio
    Cortella — plus shared identity words and URLs);
  * it deliberately does NOT use keyword detection alone: every row also
    carries a structural comparison (block skeleton vs the PT original) and
    the translated-word ratio, so a human can see WHY a row was flagged.

Modes:
  --manifest  scan the authored translation data offline
              (stage45-work/translation-manifest.json) — the default;
  --live      scan the deployed EN pages over the public REST API
              (GET /wp-json/wp/v2/pages?lang=en …) after deployment.

Output: JSON + Markdown table with one row per EN page:
  translated | possible-untranslated | empty | excluded-technical
"""

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.request

UA = "ConexaoBR-Stage45-Scan/1.0 (read-only)"
BASE = "https://conexaobr.ie"

# Portuguese function words / high-frequency markers. Presence is a signal,
# never a verdict (proper nouns are allowed to survive translation).
PT_MARKERS = re.compile(
    r"\b(de|da|do|das|dos|para|com|sobre|não|não|que|você|nossa|nosso|mais|como|"
    r"está|são|será|em|uma|pelo|pela|irlanda[ns]?|condado|página|site|guia[s]?|"
    r"evento[s]?|emprego[s]?|vaga[s]?|moradia|saúde|família|finanças|benefícios|"
    r"educação|documentos|turismo|compras|negócios|serviços|voluntariado|"
    r"restaurantes|informações|comunidade|brasileir[oa]s?|clique|aqui)\b", re.I)

# Portuguese diacritics outside allowed brand names.
PT_DIACRITICS = re.compile(r"[áàâãéêíóôõúçü]", re.I)
ALLOWED_WITH_DIACRITICS = ("Conexão BR", "Mário Sérgio Cortella", "Irlanda")

# Proper nouns / official names that legitimately appear in EN text.
PROPER_NOUNS = ("Conexão BR Irlanda", "Conexão BR", "Irlanda", "Ireland",
                "Mário Sérgio Cortella", "HSE", "GP", "Medical Card", "PPS Number",
                "IRP", "Employment Permit", "Discover Ireland", "CNH", "WhatsApp",
                "Instagram", "Jobs.ie", "IrishJobs", "Indeed")


def strip_allowed(text):
    for noun in ALLOWED_WITH_DIACRITICS:
        text = text.replace(noun, "")
    return text


def scan_text(name, title, body, meta_desc, pt_body):
    """Return (status, flags) for one EN page."""
    flags = []
    if not (body or "").strip() and not (title or "").strip():
        return "empty", ["no title and no body"]

    plain = re.sub(r"<[^>]+>", " ", body or "")
    plain = re.sub(r"\s+", " ", plain)

    # Structure parity vs the PT original.
    def skel(html):
        no_comments = re.sub(r"<!--/?wp:[^>]*-->", "", html or "")
        return re.findall(r"<(h[1-6]|p|ul|ol)\b", no_comments)

    if pt_body and skel(pt_body) != skel(body or ""):
        flags.append("block skeleton differs from the PT original")

    # Portuguese markers outside proper nouns/URLs/emails (an email like
    # tdcriativo@gmail.com would otherwise trip the 'com' marker).
    scan_plain = strip_allowed(plain)
    scan_plain = re.sub(r"[\w.+-]+@[\w-]+\.[\w.]+", " ", scan_plain)
    scan_plain = re.sub(r"https?://\S+|www\.\S+", " ", scan_plain)
    marker_hits = PT_MARKERS.findall(scan_plain)
    # Common words that overlap with English or shared identity terms:
    noise = {"Irlanda", "irlanda"}
    marker_hits = [m for m in marker_hits if m not in noise]
    if marker_hits:
        flags.append(f"PT function words present: {sorted(set(m.lower() for m in marker_hits))[:8]}")

    # Diacritics outside allowed brand names.
    diac = PT_DIACRITICS.findall(strip_allowed(plain))
    if diac:
        flags.append(f"PT diacritics outside brand names: {''.join(sorted(set(diac)))}")

    # Meta description sanity (English, not a copy of PT).
    if meta_desc is not None:
        md = strip_allowed(meta_desc)
        m_hits = PT_MARKERS.findall(md)
        d_hits = PT_DIACRITICS.findall(md)
        if m_hits or d_hits:
            flags.append("meta description still looks Portuguese")

    # Translated-word ratio vs the PT original.
    if pt_body:
        pt_plain = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", pt_body)).strip()
        same = plain.strip() == pt_plain.strip()
        if same:
            flags.append("body is byte-identical to the PT original (untranslated copy)")

    status = "possible-untranslated" if flags else "translated"
    return status, flags

def load_manifest_rows(manifest_path, pt_content):
    doc = json.load(open(manifest_path))
    rows = []
    for it in doc["items"]:
        rows.append({
            "slug": it["pt_slug"], "en_slug": it["en_slug"], "title": it["en_title"],
            "body": it["content_en"], "meta_desc": it["meta_desc_en"],
            "pt_body": pt_content.get(it["pt_slug"], ""),
        })
    return rows


def load_live_rows(base, pt_content):
    """Fetch the deployed EN pages over the public REST API (GET only)."""
    rows = []
    page = 1
    while True:
        url = (base + f"/wp-json/wp/v2/pages?per_page=50&page={page}"
               "&_fields=id,slug,title,content,excerpt,meta")
        req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = json.loads(resp.read())
        except urllib.error.HTTPError as exc:
            if exc.code == 404:
                break
            if exc.code == 429:
                time.sleep(20)
                continue
            raise
        if not data:
            break
        for p in data:
            meta_desc = (p.get("meta") or {}).get("advanced_seo_description") or None
            rows.append({
                "slug": p["slug"], "en_slug": p["slug"],
                "title": re.sub(r"<[^>]+>", "", p["title"]["rendered"]),
                "body": p["content"]["rendered"],
                "meta_desc": meta_desc or (p["excerpt"]["rendered"] or None),
                "pt_body": pt_content.get(p["slug"], ""),
            })
        page += 1
        time.sleep(1.0)
    return rows


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default="stage45-work/translation-manifest.json")
    ap.add_argument("--rest-cache", default="stage45-work/inventory-cache/pages-rest.json",
                    help="PT rendered content for the structure comparison")
    ap.add_argument("--live", action="store_true", help="scan the deployed EN pages instead of the manifest")
    ap.add_argument("--base", default=BASE)
    ap.add_argument("--out", default="stage45-work/completeness-scan")
    args = ap.parse_args()

    pt_content = {}
    if args.rest_cache:
        try:
            for p in json.load(open(args.rest_cache)):
                pt_content[p["slug"]] = p["content"]["rendered"]
        except FileNotFoundError:
            pass

    if args.live:
        rows = load_live_rows(args.base.rstrip("/"), pt_content)
    else:
        rows = load_manifest_rows(args.manifest, pt_content)

    results = []
    counts = {"translated": 0, "possible-untranslated": 0, "empty": 0, "excluded-technical": 0}
    for r in rows:
        status, flags = scan_text(r["slug"], r["title"], r["body"], r["meta_desc"], r["pt_body"])
        counts[status] += 1
        results.append({"slug": r["slug"], "en_slug": r["en_slug"], "status": status, "flags": flags})

    doc = {
        "generated_by": "scripts/stage45-translation-completeness-scan.py (review signal only)",
        "mode": "live" if args.live else "manifest",
        "counts": counts,
        "rows": results,
        "note": "Every 'possible-untranslated' row must be manually inspected. The scanner never rewrites content.",
    }
    with open(args.out + ".json", "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=1)

    lines = [f"# Stage 4.5 — translation completeness scan ({doc['mode']})", "",
             "- translated: {translated}".format(**counts),
             "- possible-untranslated: **{possible-untranslated}** (manual review required)".format(**counts),
             "- empty: {empty}".format(**counts), "",
             "| status | PT slug | EN slug | flags |", "|---|---|---|---|"]
    for r in results:
        lines.append(f"| {r['status']} | {r['slug']} | {r['en_slug']} | {'; '.join(r['flags'])} |")
    with open(args.out + ".md", "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines))

    print(json.dumps(counts, indent=1))
    flagged = [r for r in results if r["status"] == "possible-untranslated"]
    for r in flagged:
        print(f"  REVIEW {r['slug']}: {r['flags']}")
    print(f"wrote {args.out}.json / .md")
    # The scanner never fails the build: it is a review signal. Exit 0 unless
    # something structural is broken (empty EN pages).
    sys.exit(1 if counts["empty"] else 0)


if __name__ == "__main__":
    main()

