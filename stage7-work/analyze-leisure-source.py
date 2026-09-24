#!/usr/bin/env python3
"""Stage 7 Phase 0 — measure the production Leisure dataset.

Reads stage7-work/source/leisure-page-*.json (public REST capture of
https://conexaobr.ie, CPT `leisure`) and answers, with evidence:

1. Which text-bearing fields exist on Leisure records (meta keys, excerpt,
   content, SEO description)?
2. What language is each field's value (PT vs EN detector over PT-only and
   EN-only stopword sets)?
3. Does any field carry an original ENGLISH description for the EN archive?

No mutation — pure measurement over the captured JSON.
"""
import json
import re
import sys
from collections import Counter

PT_MARKERS = [
    "ã", "õ", "ç", "á", "â", "ê", "é", "í", "ó", "ú", "à",
    " da ", " das ", " do ", " dos ", " de ", " no ", " na ", " em ",
    "com", "para", "uma", "não", "está", "são", "mais", "muito", "pode",
    "localizada", "localizado", "conhecida", "conhecido", "visita", "entrada",
    "gratuito", "gratuita", "museu", "jardim", "praia", "castelo", "ilha",
    "milhares", "século", "popular", "também", "área", "através", "onde",
]
EN_MARKERS = [
    " the ", " and ", " with ", " of ", " a ", " an ", " is ", " are ",
    " for ", " to ", " from ", " this ", " that ", " by ", " on ", " in ",
    "located", "featuring", "offers", "home to", "known", "popular",
    "century", "museum", "garden", "beach", "castle", "island", "visitor",
    "one of", "its", "was", "has", "includes", "includes", "free",
]


def strip_html(s):
    return re.sub(r"<[^>]+>", " ", s or "")


def detect_language(text):
    """Return (lang, pt_score, en_score) — 'pt', 'en', '' (undetermined)."""
    t = " " + strip_html(text).lower() + " "
    if len(t.strip()) < 4:
        return "", 0, 0
    pt = sum(1 for m in PT_MARKERS if m in t)
    en = sum(1 for m in EN_MARKERS if m in t)
    if pt == 0 and en == 0:
        return "", 0, 0
    if pt > en:
        return "pt", pt, en
    if en > pt:
        return "en", pt, en
    return "tie", pt, en


def main():
    records = []
    for p in (1, 2, 3):
        page = json.load(open(f"stage7-work/source/leisure-page-{p}.json"))
        records.extend(page)
    print(f"total leisure records: {len(records)}")

    # 1. which meta keys exist and how often
    meta_keys = Counter()
    for r in records:
        for k in (r.get("meta") or {}):
            meta_keys[k] += 1
    print("\n== meta keys (count of records carrying the key, non-null) ==")
    for k, n in sorted(meta_keys.items()):
        print(f"  {k}: {n}")

    # 2. text-bearing field language census
    fields = {
        "excerpt": lambda r: (r.get("excerpt") or {}).get("rendered", ""),
        "content": lambda r: (r.get("content") or {}).get("rendered", ""),
        "advanced_seo_description": lambda r: (r.get("meta") or {}).get("advanced_seo_description", ""),
    }
    census = {f: Counter() for f in fields}
    examples = {}
    for r in records:
        for f, fn in fields.items():
            val = fn(r)
            lang = detect_language(val)
            census[f][lang] += 1
            if lang and f not in examples:
                examples[f] = (r["slug"], val[:120], lang)
    print("\n== language census per text field ==")
    for f, c in census.items():
        print(f"  {f}: {dict(c)}")
    print("\n== first example per field ==")
    for f, ex in examples.items():
        print(f"  {f}: {ex[2]} | {ex[0]} | {ex[1]!r}")

    # 3. any meta field with English text?
    meta_lang = {}
    for r in records:
        for k, v in (r.get("meta") or {}).items():
            if isinstance(v, str) and len(v) > 20:
                lang = detect_language(v)
                if lang == "en":
                    meta_lang.setdefault(k, []).append(r["slug"])
    print("\n== meta fields ever detected ENGLISH ==")
    for k, slugs in meta_lang.items():
        print(f"  {k}: {len(slugs)} records, e.g. {slugs[:3]}")

    # 4. per-record evidence table (first 30)
    print("\n== per-record evidence (first 30) ==")
    print("slug | excerpt_lang | seo_desc_lang | seo_desc")
    for r in records[:30]:
        ex = detect_language((r.get("excerpt") or {}).get("rendered", ""))[0] or "-"
        seo = detect_language((r.get("meta") or {}).get("advanced_seo_description", ""))[0] or "-"
        sd = (r.get("meta") or {}).get("advanced_seo_description", "")[:60]
        print(f"  {r['slug']} | {ex} | {seo} | {sd!r}")


if __name__ == "__main__":
    sys.exit(main())
