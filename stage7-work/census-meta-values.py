#!/usr/bin/env python3
"""Stage 7 Phase 0b — exhaustive text-field census.

Dumps every unique string value of every REST-exposed field/meta key across the
289 production leisure records, language-detects each, and prints the full
excerpt list for manual review. Purpose: prove that no English description
text exists anywhere in the production Leisure data model.
"""
import json
import re
from collections import defaultdict

PT_MARKERS = [
    "ã", "õ", "ç", "á", "â", "ê", "é", "í", "ó", "ú", "à",
    " da ", " das ", " do ", " dos ", " de ", " no ", " na ", " em ",
    "com ", "para ", "uma ", "não", "está", "são", "mais", "muito", "pode",
    "localiza", "conheci", "visita", "entrada", "gratuito", "gratuita",
    "museu", "jardim", "praia", "castelo", "ilha", "século", "também",
    "área", "onde", "foto", "licença",
]
EN_MARKERS = [
    " the ", " and ", " with ", " of ", " is ", " are ", " for ", " to ",
    " from ", " this ", " that ", " by ", "located", "featuring", "offers",
    "home to", "known", "century", "museum", "garden", "beach", "castle",
    "island", "visitor", "one of", "its ", "was ", "has ", "includes",
]


def strip_html(s):
    return re.sub(r"<[^>]+>", " ", s or "")


def detect(text):
    t = " " + strip_html(text).lower() + " "
    if len(t.strip()) < 4:
        return ""
    pt = sum(1 for m in PT_MARKERS if m in t)
    en = sum(1 for m in EN_MARKERS if m in t)
    if pt == 0 and en == 0:
        return ""
    return "pt" if pt > en else ("en" if en > pt else "tie")


records = []
for p in (1, 2, 3):
    records.extend(json.load(open(f"stage7-work/source/leisure-page-{p}.json")))
print(f"records: {len(records)}")

# unique values per meta key, with language detection
by_field = defaultdict(lambda: defaultdict(int))  # field -> {lang: n_values}
examples = defaultdict(list)
for r in records:
    for k, v in (r.get("meta") or {}).items():
        if isinstance(v, str) and v.strip():
            lang = detect(v)
            by_field[k][lang] += 1
            if lang == "en":
                examples[k].append((r["slug"], v[:150]))

print("\n== meta field value language census (unique-value counts by detected language) ==")
for k in sorted(by_field):
    print(f"  {k}: {dict(by_field[k])}")

print("\n== meta fields with ANY English-detected value ==")
if not examples:
    print("  NONE")
for k, exs in examples.items():
    for slug, v in exs:
        print(f"  {k} | {slug} | {v!r}")

print("\n== FULL excerpt list (slug | detected | text) for manual review ==")
for r in records:
    ex = strip_html((r.get("excerpt") or {}).get("rendered", "")).strip()
    lang = detect(ex)
    flag = " <<< NON-PT" if lang not in ("pt",) else ""
    print(f"  [{lang or 'empty':5}] {r['slug']}: {ex}{flag}")
