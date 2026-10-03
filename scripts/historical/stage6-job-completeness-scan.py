#!/usr/bin/env python3
"""Stage 6 — EN Jobs completeness scanner (read-only).

For every EN job linked to a PT job, classifies each translated field as
`translated` / `possible-untranslated` / `empty` and flags Portuguese leakage
in the visible layer. Also reports the reverse direction (EN jobs missing a
PT sibling) and eligible PT jobs missing EN.

The scanner does NOT declare translation quality — it flags candidates for
manual review. Required final state: 0 missing EN, 0 EN without PT, 0 empty,
0 unreviewed flags.

Usage:
    python3 scripts/stage6-job-completeness-scan.py \
        --inventory stage6-work/job-inventory-clone-after.json \
        --write-json stage6-work/completeness-scan.json \
        --write-md   stage6-work/completeness-scan.md
"""
import argparse
import json
import re
import sys

# Portuguese tell-tales for the visible layer (conservative: whole words,
# accented forms that do not occur in English prose).
PT_MARKERS = re.compile(
    r"\b(compartilhamos|vagas|estão|currículo|destaques|diariamente|"
    r"oportunidade[s]? na irlanda|clique aqui|procure|procurando|"
    r"condado|moradia|saúde|então|você|está|nosso|nossa)\b",
    re.IGNORECASE,
)


def text(html):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html or "")).strip()


def scan_field(name, en_value, pt_value):
    """Classify one field."""
    if not (en_value or "").strip():
        return {"field": name, "status": "empty", "flags": ["empty EN value"]}
    flags = []
    en_t = text(en_value)
    pt_t = text(pt_value)
    if en_t and pt_t and en_t == pt_t:
        flags.append("identical to PT source")
    hits = PT_MARKERS.findall(en_t)
    if hits:
        flags.append("PT markers: " + ", ".join(sorted(set(hits))))
    return {"field": name, "status": "possible-untranslated" if flags else "translated", "flags": flags}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--inventory", required=True, help="After-inventory JSON")
    ap.add_argument("--write-json", default="")
    ap.add_argument("--write-md", default="")
    args = ap.parse_args()

    inv = json.load(open(args.inventory, encoding="utf-8"))
    pt_by_id = {int(r["id"]): r for r in inv.get("pt", [])}
    en_rows = inv.get("en", [])

    rows = []
    totals = {"translated": 0, "possible-untranslated": 0, "empty": 0}
    en_without_pt = []
    pt_missing_en = []

    for pt in inv.get("pt", []):
        if not int(pt.get("translation", 0) or 0):
            pt_missing_en.append(pt["slug"])

    for en in en_rows:
        pt = pt_by_id.get(int(en.get("translation", 0) or 0))
        if pt is None:
            en_without_pt.append(en["slug"])
            continue

        fields = [
            scan_field("title", en["title"], pt["title"]),
            scan_field("content", en["content"], pt["content"]),
            scan_field("excerpt", en["excerpt"] or en["content"], pt["excerpt"] or pt["content"]),
            scan_field("meta_desc", en.get("meta_desc", ""), pt.get("meta_desc", "")),
        ]
        # Visible structured meta: identical-by-policy (company/location/URLs…)
        # is fine; flag only text-bearing fields that equal non-trivial PT text.
        for key in sorted(k for k in en.get("meta", {}) if k.startswith("_job_")):
            en_v = en["meta"].get(key) or ""
            pt_v = pt.get("meta", {}).get(key) or ""
            if isinstance(en_v, str) and isinstance(pt_v, str) and len(pt_v) > 20 and en_v == pt_v and PT_MARKERS.search(pt_v):
                fields.append({"field": "meta." + key, "status": "possible-untranslated", "flags": ["text-bearing meta identical to PT"]})
            else:
                fields.append({"field": "meta." + key, "status": "translated", "flags": []})

        status = "translated"
        if any(f["status"] == "empty" for f in fields):
            status = "empty"
        elif any(f["status"] == "possible-untranslated" for f in fields):
            status = "possible-untranslated"
        totals[status] += 1
        rows.append(
            {
                "en_id": en["id"],
                "en_slug": en["slug"],
                "pt_id": pt["id"],
                "pt_slug": pt["slug"],
                "status": status,
                "fields": fields,
            }
        )

    result = {
        "generated_by": "scripts/stage6-job-completeness-scan.py",
        "inventory": args.inventory,
        "en_jobs": len(en_rows),
        "eligible_pt_missing_en": pt_missing_en,
        "en_without_pt": en_without_pt,
        "totals": totals,
        "unreviewed_flags": sum(len(f["flags"]) for r in rows for f in r["fields"] if f["flags"]),
        "rows": rows,
        "gate": "PASS"
        if not pt_missing_en and not en_without_pt and totals["empty"] == 0 and totals["possible-untranslated"] == 0
        else "FAIL",
    }

    print(f"EN jobs scanned: {len(rows)}")
    print(f"  translated: {totals['translated']}, possible-untranslated: {totals['possible-untranslated']}, empty: {totals['empty']}")
    print(f"  eligible PT missing EN: {len(pt_missing_en)} | EN without PT: {len(en_without_pt)}")
    print("GATE:", result["gate"])

    if args.write_json:
        with open(args.write_json, "w", encoding="utf-8") as fh:
            json.dump(result, fh, indent=1, ensure_ascii=False)
        print("wrote", args.write_json)

    if args.write_md:
        lines = [
            "# Stage 6 — EN Jobs completeness scan",
            "",
            f"- EN jobs scanned: **{len(rows)}**",
            f"- translated: **{totals['translated']}** | possible-untranslated: **{totals['possible-untranslated']}** | empty: **{totals['empty']}**",
            f"- eligible PT jobs missing EN: **{len(pt_missing_en)}** {pt_missing_en or ''}",
            f"- EN jobs without a PT sibling: **{len(en_without_pt)}** {en_without_pt or ''}",
            f"- unreviewed flags: **{result['unreviewed_flags']}**",
            f"- **GATE: {result['gate']}**",
            "",
            "| EN slug | PT slug | status | flags |",
            "|---|---|---|---|",
        ]
        for r in rows:
            flags = [f["field"] + ": " + "; ".join(f["flags"]) for f in r["fields"] if f["flags"]]
            lines.append(f"| {r['en_slug']} | {r['pt_slug']} | {r['status']} | {'; '.join(flags) or '—'} |")
        with open(args.write_md, "w", encoding="utf-8") as fh:
            fh.write("\n".join(lines) + "\n")
        print("wrote", args.write_md)

    sys.exit(0 if result["gate"] == "PASS" else 1)


if __name__ == "__main__":
    main()

