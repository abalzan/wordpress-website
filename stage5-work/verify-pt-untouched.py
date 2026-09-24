#!/usr/bin/env python3
"""Stage 5 — Portuguese-originals integrity check (Phase 19 hard gate).

Compares every public Portuguese blog post in the target WordPress against the
production REST payload it was imported from (`stage5-work/source/<slug>.json`)
and writes `stage5-work/pt-integrity.json`.

A difference in title / body / slug / date / status / categories means the
translation work modified a Portuguese original: the gate FAILS.

Usage:
    python3 stage5-work/verify-pt-untouched.py stage5-work/inventory-after.json
"""
import glob
import html
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
FIELDS = ("title", "content", "slug", "date")


def main():
    inventory_path = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, "inventory-after.json")
    inventory = json.load(open(inventory_path, encoding="utf-8"))
    local = {p["slug"]: p for p in inventory["pt"]}

    checked = 0
    diffs = []
    missing = []

    for path in sorted(glob.glob(os.path.join(HERE, "source", "*.json"))):
        if path.endswith("_categories.json"):
            continue
        source = json.load(open(path, encoding="utf-8"))
        slug = source["slug"]
        post = local.get(slug)

        if post is None:
            missing.append(slug)
            continue

        checked += 1
        expected = {
            "title": html.unescape(source["title"]["rendered"]),
            "content": source["content"]["rendered"],
            "slug": slug,
            # REST returns ISO-8601; the DB stores "YYYY-MM-DD HH:MM:SS".
            "date": source["date"].replace("T", " "),
        }

        for field in FIELDS:
            if post[field] != expected[field]:
                diffs.append(
                    {
                        "slug": slug,
                        "field": field,
                        "expected_head": str(expected[field])[:120],
                        "actual_head": str(post[field])[:120],
                    }
                )

        if post["status"] != "publish":
            diffs.append({"slug": slug, "field": "status", "expected_head": "publish", "actual_head": post["status"]})

        if post["language"] != "pt":
            diffs.append({"slug": slug, "field": "language", "expected_head": "pt", "actual_head": post["language"]})

    result = {
        "checked": checked,
        "source_posts": checked + len(missing),
        "missing_locally": missing,
        "differences": diffs,
        "pass": not diffs,
    }

    with open(os.path.join(HERE, "pt-integrity.json"), "w", encoding="utf-8") as handle:
        json.dump(result, handle, ensure_ascii=False, indent=1)

    print(f"PT originals compared: {checked} (source: {result['source_posts']})")
    if missing:
        print("NOT PRESENT LOCALLY (not a modification): " + ", ".join(missing))
    for diff in diffs:
        print(f"DIFF {diff['slug']} [{diff['field']}] {diff['expected_head']!r} -> {diff['actual_head']!r}")
    print("PT ORIGINALS UNCHANGED" if result["pass"] else "PT INTEGRITY GATE FAILED")
    return 0 if result["pass"] else 1


if __name__ == "__main__":
    sys.exit(main())
