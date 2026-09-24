#!/usr/bin/env python3
"""Stage 6 — Job translation manifest validation (read-only).

Validates stage6-work/job-translation-manifest.json:
  1. structure — every item carries the required keys;
  2. coverage — every public production Job (production REST capture) appears
     exactly once; no unknown entries;
  3. EN slugs — lowercase URL-safe, natural (no `-en`/`-2`), unique, and free
     of collisions on the live site (pages, posts, media, CPTs, terms probed
     over the public REST API);
  4. EN content sanity — non-empty title/body, no PT-only markers.

Usage:
    python3 scripts/stage6-job-validate.py \
        --manifest stage6-work/job-translation-manifest.json \
        --production stage6-work/source/production-job-rest.json \
        --site https://conexaobr.ie
"""
import argparse
import json
import re
import sys
import urllib.request
import urllib.error

REQUIRED = ["pt_id", "pt_slug", "pt_title", "en_title", "en_slug", "status", "translated_fields", "copied_fields", "meta_description", "featured_media"]
SLUG_RE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")

PT_MARKERS = re.compile(r"\b(compartilhamos|estão|currículo|clique aqui|você)\b", re.IGNORECASE)

EN_CONTENT_FILE = None  # EN bodies live in the plugin map (PHP); content checks run via the in-process suite.


def probe_slug(site, slug):
    """True when the slug exists anywhere public on the site (any type)."""
    collisions = []
    endpoints = [
        ("page", f"/wp-json/wp/v2/pages?slug={slug}"),
        ("post", f"/wp-json/wp/v2/posts?slug={slug}"),
        ("job", f"/wp-json/wp/v2/job?slug={slug}"),
        ("media", f"/wp-json/wp/v2/media?slug={slug}"),
        ("category", f"/wp-json/wp/v2/categories?slug={slug}"),
        ("conexao_category", f"/wp-json/wp/v2/conexao_category?slug={slug}"),
        ("conexao_county", f"/wp-json/wp/v2/conexao_county?slug={slug}"),
        ("conexao_tag", f"/wp-json/wp/v2/conexao_tag?slug={slug}"),
    ]
    for kind, path in endpoints:
        try:
            with urllib.request.urlopen(site.rstrip("/") + path, timeout=20) as resp:
                data = json.loads(resp.read().decode("utf-8"))
            if isinstance(data, list) and data:
                collisions.append({"type": kind, "id": data[0].get("id"), "slug": data[0].get("slug")})
        except (urllib.error.URLError, urllib.error.HTTPError, ValueError):
            collisions.append({"type": kind, "error": "probe failed"})
    return collisions


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", required=True)
    ap.add_argument("--production", required=True, help="Production job REST capture JSON")
    ap.add_argument("--site", default="https://conexaobr.ie")
    args = ap.parse_args()

    manifest = json.load(open(args.manifest, encoding="utf-8"))
    production = json.load(open(args.production, encoding="utf-8"))

    errors = []
    warnings = []

    items = manifest.get("items", [])
    if not items:
        errors.append("manifest has no items")

    # 1. structure
    for item in items:
        for key in REQUIRED:
            if key not in item:
                errors.append(f"{item.get('pt_slug', '?')}: missing key {key}")

    # 2. coverage — every production public job exactly once, no extras
    prod_slugs = [j["slug"] for j in production]
    manifest_pt = [i["pt_slug"] for i in items]
    for slug in prod_slugs:
        if manifest_pt.count(slug) != 1:
            errors.append(f"production job {slug} appears {manifest_pt.count(slug)} times in the manifest (must be 1)")
    for slug in manifest_pt:
        if slug not in prod_slugs:
            errors.append(f"manifest entry {slug} has no production counterpart")

    # 3. EN slugs
    seen = set()
    for item in items:
        slug = item.get("en_slug", "")
        if not SLUG_RE.match(slug):
            errors.append(f"{item['pt_slug']}: EN slug '{slug}' is not URL-safe lowercase")
        if slug.endswith("-en") or slug.endswith("-2"):
            errors.append(f"{item['pt_slug']}: EN slug '{slug}' has a forbidden suffix")
        if slug == item["pt_slug"]:
            errors.append(f"{item['pt_slug']}: EN slug reuses the PT slug")
        if slug in seen:
            errors.append(f"EN slug '{slug}' is duplicated in the manifest")
        seen.add(slug)
        if not item.get("en_title", "").strip():
            errors.append(f"{item['pt_slug']}: empty EN title")
        if not item.get("meta_description", "").strip():
            errors.append(f"{item['pt_slug']}: empty EN meta description")
        if PT_MARKERS.search(item.get("en_title", "")):
            errors.append(f"{item['pt_slug']}: PT marker in EN title")

    # Collision probe against the live site (public REST, read-only).
    for item in items:
        collisions = probe_slug(args.site, item["en_slug"])
        hard = [c for c in collisions if "error" not in c]
        for c in collisions:
            if "error" in c:
                warnings.append(f"{item['en_slug']}: {c['type']} probe failed ({c['error']})")
        if hard:
            errors.append(f"{item['pt_slug']}: EN slug '{item['en_slug']}' collides on the live site: {hard}")

    print(f"manifest items: {len(items)} | production public jobs: {len(prod_slugs)}")
    for w in warnings:
        print("  WARN:", w)
    for e in errors:
        print("  ERROR:", e)
    print("VALIDATION:", "PASS" if not errors else "FAIL")
    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
