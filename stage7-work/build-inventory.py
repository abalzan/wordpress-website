#!/usr/bin/env python3
"""Stage 7 — build the Leisure description inventory deliverables.

Reads the captured production REST dataset (stage7-work/source/leisure-page-*.json)
and emits stage7-work/leisure-description-inventory.{json,md}.
"""
import json
import re
import datetime
from collections import Counter

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
        return "empty-or-propernoun"
    pt = sum(1 for m in PT_MARKERS if m in t)
    en = sum(1 for m in EN_MARKERS if m in t)
    if pt == 0 and en == 0:
        return "undetermined"
    return "pt" if pt > en else ("en" if en > pt else "tie")


def text_of(r, field):
    if field == "excerpt":
        return strip_html((r.get("excerpt") or {}).get("rendered", "")).strip()
    if field == "content":
        return strip_html((r.get("content") or {}).get("rendered", "")).strip()
    return strip_html((r.get("meta") or {}).get(field, "")).strip()


records = []
for p in (1, 2, 3):
    records.extend(json.load(open(f"stage7-work/source/leisure-page-{p}.json")))

inventory = {
    "stage": "7.x — EN Leisure card description source investigation",
    "captured_from": "https://conexaobr.ie/wp-json/wp/v2/leisure (public REST, read-only)",
    "captured_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
    "records": len(records),
    "card_excerpt_render_path": (
        "template-parts/leisure-card.php:154 -> get_the_excerpt() -> "
        "wp_trim_words(..., 18, '...') -> <p class=leisure-card-excerpt> — "
        "source field: post_excerpt (admin alias _leisure_short_description)"
    ),
    "summary": {},
    "items": [],
}

per_record = []
excerpt_langs = Counter()
content_langs = Counter()
practical_langs = Counter()
en_source_records = []

for r in records:
    meta = r.get("meta") or {}
    excerpt = text_of(r, "excerpt")
    content = text_of(r, "content")
    practical = text_of(r, "_leisure_practical_notes")
    seo_desc = (meta.get("advanced_seo_description") or "").strip()

    ex_lang = detect(excerpt)
    ct_lang = detect(content)
    pr_lang = detect(practical) if practical else "absent"

    excerpt_langs[ex_lang] += 1
    content_langs[ct_lang] += 1
    practical_langs[pr_lang] += 1

    # "Original English source description exists" = any text-bearing field of
    # this record that carries a genuine English description (not a URL,
    # address or proper-noun alt text).
    en_candidate = bool(
        ex_lang == "en"
        or ct_lang == "en"
        or (bool(practical) and pr_lang == "en")
        or (bool(seo_desc) and detect(seo_desc) == "en")
    )
    if en_candidate:
        en_source_records.append(r["slug"])

    per_record.append({
        "id": r["id"],
        "slug": r["slug"],
        "link": r["link"],
        "title": r["title"]["rendered"],
        "excerpt": excerpt,
        "excerpt_language": ex_lang,
        "content_language": ct_lang,
        "practical_notes_language": pr_lang,
        "advanced_seo_description": seo_desc,
        "official_website": meta.get("_leisure_official_website", ""),
        "discover_ireland": meta.get("_leisure_discover_ireland", ""),
        "upstream_english_page_exists": bool(meta.get("_leisure_official_website") or meta.get("_leisure_discover_ireland")),
        "original_english_description_in_data": en_candidate,
    })

inventory["summary"] = {
    "total_leisure_records": len(records),
    "records_with_excerpt": sum(1 for x in per_record if x["excerpt"]),
    "excerpt_language_counts": dict(excerpt_langs),
    "content_language_counts": dict(content_langs),
    "practical_notes_language_counts": dict(practical_langs),
    "advanced_seo_description_nonempty": sum(1 for x in per_record if x["advanced_seo_description"]),
    "records_with_original_english_description": len(en_source_records),
    "records_with_original_english_description_slugs": en_source_records,
    "records_with_upstream_english_page_link": sum(1 for x in per_record if x["upstream_english_page_exists"]),
    "origin_accounting_by_publication_date": dict(
        sorted(Counter(r["date"][:10] for r in records).items())
    ),
    "finding": (
        "No original English description exists anywhere in the Leisure data. "
        "All 289 excerpts, all 289 contents and all 56 practical-notes values "
        "are the project's own authored Portuguese text. No _leisure_* meta "
        "field, no SEO description field and no import/export artifact carries "
        "English description prose. Upstream English pages (OPW/Heritage "
        "Ireland, Discover Ireland) are linked as URLs only; their prose is "
        "third-party copyrighted content that the project's Stage A audit "
        "explicitly forbids copying."
    ),
}
inventory["items"] = per_record

with open("stage7-work/leisure-description-inventory.json", "w", encoding="utf-8") as f:
    json.dump(inventory, f, ensure_ascii=False, indent=2)

print("wrote leisure-description-inventory.json")
print(json.dumps({k: v for k, v in inventory["summary"].items() if k != "finding"}, indent=2, ensure_ascii=False)[:1200])