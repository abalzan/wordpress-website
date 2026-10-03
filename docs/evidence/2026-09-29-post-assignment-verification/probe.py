#!/usr/bin/env python3
"""Post-assignment production verification probe (READ-ONLY).

Verification-only, 2026-09-29. Issues GET only against the production REST API.
It performs no write of any kind: every request in this file is a ``GET`` and
the shared client's production write guard is never invoked.

Usage:
    set -a && . ./.env && set +a && python3 probe.py

It reuses ``scripts/lib/rest.py`` for credentials, as the engineering standard
requires. Credentials come from the environment only and are never printed or
written to evidence.
"""

from __future__ import annotations

import hashlib
import json
import os
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "scripts", "lib"))

import rest  # noqa: E402  (shared client, scripts/lib/rest.py)

BASE = "https://conexaobr.ie"
API = BASE + "/wp-json/wp/v2/"

#: REST base per post type slug (post/page differ from the type name).
REST_BASE = {
    "post": "posts",
    "page": "pages",
    "guide": "guide",
    "event": "event",
    "leisure": "leisure",
    "job": "job",
    "sponsor": "sponsor",
    "course_provider": "course_provider",
    "recruitment_agency": "recruitment_agency",
    "permit_employer": "permit_employer",
}

#: The six post types the repository contract declares Polylang-translated.
TRANSLATED_TYPES = ["guide", "event", "leisure", "sponsor", "job", "course_provider"]

HEADERS = rest.default_headers("conexao-verify-readonly/1.0")


def fetch(url, tries=3):
    """GET ``url``. Retries transport errors only; a 4xx returns immediately."""
    for attempt in range(tries):
        try:
            request = urllib.request.Request(url, headers=HEADERS)
            request.add_header("Cache-Control", "no-cache")
            with urllib.request.urlopen(request, timeout=90) as response:
                return (
                    response.status,
                    dict(response.headers),
                    response.read().decode("utf-8", "replace"),
                )
        except urllib.error.HTTPError as error:
            return (
                error.code,
                dict(error.headers),
                error.read().decode("utf-8", "replace"),
            )
        except Exception:
            if attempt == tries - 1:
                raise
            time.sleep(2)
    raise RuntimeError("unreachable")


def total(path):
    """X-WP-Total for a REST collection path."""
    _, headers, _ = fetch(API + path + ("&" if "?" in path else "?") + "per_page=1")
    value = headers.get("X-WP-Total")
    return int(value) if value is not None else None


def page_all(base):
    """Every record of a collection, following X-WP-Total pagination."""
    out, page, expected = [], 1, None
    while True:
        _, headers, body = fetch(
            f"{API}{base}?per_page=100&page={page}&_fields=id,slug,status,link,title"
        )
        if expected is None:
            expected = int(headers.get("X-WP-Total", 0))
        chunk = json.loads(body)
        if not isinstance(chunk, list):
            break
        out.extend(chunk)
        if len(out) >= expected or not chunk:
            break
        page += 1
    return out


def digest(records):
    """Stable MD5 over the sorted slug list of a collection."""
    slugs = sorted(str(r.get("slug", "")) for r in records)
    return hashlib.md5("\n".join(slugs).encode()).hexdigest()


def ids_in(base, lang):
    """IDs of a collection filtered by one language (page 1 only)."""
    _, _, body = fetch(f"{API}{base}?lang={lang}&per_page=100&_fields=id")
    try:
        return {r["id"] for r in json.loads(body)}
    except Exception:
        return set()


def section(title):
    print()
    print("=" * 74)
    print(title)
    print("=" * 74)


def main():
    result = {}

    section("1. POLYLANG STATE  (GET /pll/v1/settings, GET /pll/v1/languages)")
    _, _, body = fetch(BASE + "/wp-json/pll/v1/settings")
    settings = json.loads(body)
    result["pll_settings"] = settings
    for key in sorted(settings):
        print(f"  {key:16} = {settings[key]!r}")
    _, _, body = fetch(BASE + "/wp-json/pll/v1/languages")
    result["pll_languages"] = [
        {
            "slug": lang["slug"],
            "name": lang["name"],
            "locale": lang["locale"],
            "term_id": lang["term_id"],
            "is_default": lang["is_default"],
            "term_count": lang.get("term_props", {}).get("language", {}).get("count"),
        }
        for lang in json.loads(body)
    ]
    for lang in result["pll_languages"]:
        print(
            f"  {lang['slug']:4} locale={lang['locale']:6} term_id={lang['term_id']:<6} "
            f"default={str(lang['is_default']):5} assigned_count={lang['term_count']}"
        )
    print(f"  language count = {len(result['pll_languages'])}")

    section("2. TRANSLATED SCOPE TOTALS  (unfiltered / ?lang=pt / ?lang=en)")
    print(f"  {'post type':18}{'unfiltered':>12}{'?lang=pt':>11}{'?lang=en':>11}")
    scope, sum_un, sum_pt, sum_en = {}, 0, 0, 0
    for post_type in TRANSLATED_TYPES:
        base = REST_BASE[post_type]
        un, pt, en = total(base), total(f"{base}?lang=pt"), total(f"{base}?lang=en")
        scope[post_type] = {"unfiltered": un, "lang_pt": pt, "lang_en": en}
        sum_un, sum_pt, sum_en = sum_un + un, sum_pt + pt, sum_en + en
        print(f"  {post_type:18}{un:>12}{pt:>11}{en:>11}")
    print(f"  {'SUM (6 types)':18}{sum_un:>12}{sum_pt:>11}{sum_en:>11}")
    result["scope"] = scope
    result["scope_sum"] = {"unfiltered": sum_un, "lang_pt": sum_pt, "lang_en": sum_en}
    print("\n  Outside the translated scope (not translated by contract):")
    for post_type in ("post", "page"):
        base = REST_BASE[post_type]
        entry = {
            "unfiltered": total(base),
            "lang_pt": total(f"{base}?lang=pt"),
            "lang_en": total(f"{base}?lang=en"),
        }
        scope[post_type] = entry
        print(
            f"  {post_type:18}{entry['unfiltered']:>12}"
            f"{entry['lang_pt']:>11}{entry['lang_en']:>11}"
        )

    section("3. REPRESENTATIVE ASSIGNMENT PROOF (sampled records)")
    proof = []
    for post_type in TRANSLATED_TYPES:
        base = REST_BASE[post_type]
        pt_ids, en_ids = ids_in(base, "pt"), ids_in(base, "en")
        _, _, body = fetch(
            f"{API}{base}?per_page=3&_fields=id,slug,status,conexao_language,link"
        )
        for record in json.loads(body):
            rid = record["id"]
            field = record.get("conexao_language") or {}
            row = {
                "post_type": post_type,
                "id": rid,
                "slug": record["slug"],
                "status": record["status"],
                "conexao_language": field.get("lang"),
                "translations": field.get("translations"),
                "in_lang_pt_collection": rid in pt_ids,
                "in_lang_en_collection": rid in en_ids,
            }
            proof.append(row)
            print(
                f"  {post_type:16} id={rid:<7} lang_field={str(row['conexao_language']):5}"
                f" in_pt={str(row['in_lang_pt_collection']):5}"
                f" in_en={str(row['in_lang_en_collection']):5}"
                f" translations={row['translations']}"
            )
    result["assignment_proof"] = proof
    in_pt = sum(1 for r in proof if r["in_lang_pt_collection"])
    in_en = sum(1 for r in proof if r["in_lang_en_collection"])
    print(f"\n  sampled={len(proof)}  in ?lang=pt collection={in_pt}  in ?lang=en collection={in_en}")

    section("4. CENSUS + PT IMMUTABILITY vs PRE-ACTION BASELINE")
    baseline_path = os.path.join(
        ROOT, "docs", "evidence", "2026-09-29-post-admin-i18n-verification",
        "06-content-taxonomy-census.json",
    )
    with open(baseline_path) as handle:
        baseline = json.load(handle)
    drift, census = [], {}
    for group, names in (
        ("content", list(REST_BASE)),
        ("taxonomies", list(baseline["taxonomies"])),
    ):
        for name in names:
            records = page_all(REST_BASE.get(name, name))
            now = {"count": len(records), "slug_digest": digest(records)}
            census[name] = now
            before = baseline[group].get(name)
            if before is None:
                print(f"  {name:24} (no baseline entry)")
                continue
            same = before["count"] == now["count"] and before["slug_digest"] == now["slug_digest"]
            if not same:
                drift.append({"name": name, "before": before, "after": now})
            print(
                f"  {name:24} count {before['count']:>6} -> {now['count']:<6} "
                f"slug digest {'MATCH' if same else '*** DIFFERENT ***'}"
            )
    _, headers, _ = fetch(API + "media?per_page=1")
    media_total = int(headers.get("X-WP-Total", 0))
    print(f"  {'media X-WP-Total':24} baseline 9301 -> {media_total} (informational)")
    result["census"] = census
    result["drift"] = drift
    result["pt_drift_entities"] = len(drift)
    result["media_total"] = media_total
    print(f"\n  PT CONTENT + TAXONOMY IDENTITY/SLUG DRIFT = {len(drift)}")

    section("5. TAXONOMY STATE (names/slugs/counts)")
    taxonomy_state = {}
    for taxonomy in ("conexao_category", "conexao_tag", "conexao_county", "conexao_town"):
        records = page_all(taxonomy)
        taxonomy_state[taxonomy] = {"count": len(records), "slug_digest": digest(records)}
        sample = [
            {
                "id": r["id"],
                "slug": r["slug"],
                "name": (r.get("title") or {}).get("rendered"),
                "count": r.get("count"),
            }
            for r in records[:4]
        ]
        print(f"  {taxonomy}: {len(records)} terms, digest {digest(records)}")
        print(f"    first 4: {json.dumps(sample, ensure_ascii=False)}")
    result["taxonomy_state"] = taxonomy_state

    with open(os.path.join(HERE, "01-probe.json"), "w") as handle:
        json.dump(result, handle, indent=1, ensure_ascii=False)
    print("\nwrote 01-probe.json")


if __name__ == "__main__":
    main()
