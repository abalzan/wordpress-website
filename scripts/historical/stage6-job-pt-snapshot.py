#!/usr/bin/env python3
"""Stage 6 — PT integrity gate (before/after comparison).

Compares the Portuguese job layer between two inventory JSONs produced by
scripts/historical/stage6-job-inventory.php (typically before and after the migration
apply) and writes the comparison to stage6-work/pt-snapshot.json.

The gate: every tracked PT field must be identical — title, content (hash),
excerpt, slug, date, status, author, menu order, thumbnail, taxonomy terms,
`_job_*` meta, meta description. The ONLY permitted difference is a Polylang
language backfill ('' → 'pt') on a record that had no language.

Usage:
    python3 scripts/stage6-job-pt-snapshot.py \
        --before stage6-work/job-inventory-clone-before.json \
        --after  stage6-work/job-inventory-clone-after.json \
        --write  stage6-work/pt-snapshot.json

Exit code is non-zero when any PT change is detected.
"""
import argparse
import json
import sys

TRACKED = [
    "slug",
    "title",
    "status",
    "date",
    "modified",
    "author",
    "menu_order",
    "permalink",
    "excerpt",
    "content_hash",
    "thumbnail",
    "template",
    "meta_desc",
]


def index_pt(inventory_path):
    inv = json.load(open(inventory_path, encoding="utf-8"))
    return {int(r["id"]): r for r in inv.get("pt", [])}, inv


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--before", required=True)
    ap.add_argument("--after", required=True)
    ap.add_argument("--write", default="")
    args = ap.parse_args()

    before, inv_b = index_pt(args.before)
    after, inv_a = index_pt(args.after)

    changes = []
    rows = []

    for pt_id, b in before.items():
        a = after.get(pt_id)
        if a is None:
            changes.append({"id": pt_id, "slug": b["slug"], "field": "(record)", "before": "present", "after": "MISSING"})
            continue

        row_changes = []
        for field in TRACKED:
            if b.get(field) != a.get(field):
                row_changes.append({"field": field, "before": b.get(field), "after": a.get(field)})
        if b.get("meta", {}) != a.get("meta", {}):
            for key in sorted(set(b.get("meta", {})) | set(a.get("meta", {}))):
                if b.get("meta", {}).get(key) != a.get("meta", {}).get(key):
                    row_changes.append({"field": "meta." + key, "before": b.get("meta", {}).get(key), "after": a.get("meta", {}).get(key)})
        if b.get("taxonomies", {}) != a.get("taxonomies", {}):
            row_changes.append({"field": "taxonomies", "before": b.get("taxonomies"), "after": a.get("taxonomies")})
        # The only permitted difference: language backfill '' → 'pt'.
        if b.get("language", "") != a.get("language", ""):
            if b.get("language", "") == "" and a.get("language") == "pt":
                pass  # permitted Polylang backfill
            else:
                row_changes.append({"field": "language", "before": b.get("language"), "after": a.get("language")})

        rows.append({"id": pt_id, "slug": b["slug"], "changed": bool(row_changes), "changes": row_changes})
        changes.extend({"id": pt_id, "slug": b["slug"], **c} for c in row_changes)

    for pt_id, a in after.items():
        if pt_id not in before:
            changes.append({"id": pt_id, "slug": a["slug"], "field": "(record)", "before": "MISSING", "after": "present"})

    result = {
        "generated_by": "scripts/stage6-job-pt-snapshot.py",
        "before": args.before,
        "after": args.after,
        "pt_records_before": len(before),
        "pt_records_after": len(after),
        "pt_changes": len(changes),
        "changes": changes,
        "rows": rows,
        "gate": "PASS" if not changes else "FAIL",
    }

    print(f"PT records before: {len(before)} / after: {len(after)}")
    print(f"PT changes: {len(changes)}")
    for c in changes:
        print(f"  CHANGED #{c['id']} {c['slug']} field {c['field']}: {str(c['before'])[:80]} -> {str(c['after'])[:80]}")
    print("GATE:", result["gate"])

    if args.write:
        with open(args.write, "w", encoding="utf-8") as fh:
            json.dump(result, fh, indent=1, ensure_ascii=False)
        print("wrote", args.write)

    sys.exit(1 if changes else 0)


if __name__ == "__main__":
    main()
