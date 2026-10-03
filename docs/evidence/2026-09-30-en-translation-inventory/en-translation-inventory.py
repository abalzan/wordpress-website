#!/usr/bin/env python3
"""en-translation-inventory.py — READ-ONLY production EN translation inventory.

Phase 1 of the EN Translation Rollout: inventory + manifest discovery ONLY.

It NEVER writes. The only HTTP verb in this file is GET. It:

  1. reads the live Polylang configuration (settings + languages);
  2. enumerates the live PT and EN record state of every repository-defined EN
     translation stage through the bilingual REST contract the theme already
     exposes (``?lang=pt`` / ``?lang=en`` plus the read-only
     ``conexao_language`` field);
  3. joins that live state with the repository's OWN authored stage manifests
     (dumped from ``wp-content/plugins/conexao-en-translation`` into
     ``--manifests``), so the expected work is never re-invented here;
  4. classifies every source object into exactly one inventory category and
     emits a deterministic manifest;
  5. captures a reproducible PT protection baseline (ids, slugs, statuses,
     counts and content digests) for the later dry-run/apply/verify phase.

There is no create/update/delete verb, no ``--apply`` flag and no
``assert_write_allowed`` call: this script has nothing to write.

Usage:
  CONEXAO_SITE_URL=https://conexaobr.ie \\
  WP_USERNAME=... WP_APPLICATION_PASSWORD=... \\
  python3 en-translation-inventory.py --manifests manifests.json --out raw.json
"""

from __future__ import annotations

import argparse
import datetime as _dt
import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(_HERE, "..", "..", "..", "scripts", "lib"))

from rest import RestClient, RestError, classify_target, resolve_base_url  # noqa: E402

USER_AGENT = "ConexaoBR-EN-Translation-Inventory/1.0 (read-only)"

#: The repository's translated post types — the identity model the theme
#: declares in ``conexao_polylang_translated_post_types()`` (guard.php).
TRANSLATED_POST_TYPES = ("guide", "event", "leisure", "sponsor", "job", "course_provider")

#: REST base for a WordPress post type (core uses plural collection bases).
REST_BASE = {"post": "posts", "page": "pages"}

#: The repository's translated taxonomies
#: (``conexao_polylang_translated_taxonomies()`` — real vocabulary).
TRANSLATED_TAXONOMIES = ("conexao_category", "conexao_tag")

#: The repository's shared proper-name taxonomies. These must NEVER enter the
#: translated-taxonomy manifest: one physical term serves both languages.
SHARED_TAXONOMIES = ("conexao_county", "conexao_town")

#: B1 post types with a real, linked EN-record stage
#: (``conexao_en_translation_post_types()``).
B1_POST_TYPES = ("guide", "page", "post")

#: B2 post types (theme ``conexao_b2_post_types()``): an EN request resolves the
#: PT record; no EN duplicate identity is ever expected.
B2_POST_TYPES = ("event", "leisure", "sponsor", "course_provider", "job")

#: stage -> (object kind, post type, B1/B2), read from HEAD.
#: ``record`` = a real, linked EN record is expected (B1).
#: ``field``  = the English is a field on the SAME PT record (B2 description
#:              layer): there is never a second identity.
STAGE_MODEL = {
    "en-guide": ("record", "guide", "B1"),
    "en-page": ("record", "page", "B1"),
    "en-post": ("record", "post", "B1"),
    "en-blog-page": ("record", "page", "B1"),
    "en-jobs-page": ("record", "page", "B1"),
    "en-leisure-description": ("field", "leisure", "B2"),
    "en-course-provider-description": ("field", "course_provider", "B2"),
}

#: The two stages that hold the shared-slug permit
#: (``conexao_en_translation_with_shared_page_slug()``): the EN record
#: deliberately reuses the PT post_name, so one canonical path serves two
#: languages instead of two translated slugs.
SHARED_SLUG_STAGES = ("en-blog-page", "en-jobs-page")


def get(client: RestClient, path: str, params: dict | None = None):
    """One authenticated GET returning ``(body, x_wp_total)``.

    An on-disk response cache keyed by the exact request URL makes re-runs
    cheap during development. It is a pure read cache: a cached response is
    byte-identical to what the server returned, and ``--no-cache`` disables it
    so a determinism check always re-reads production.
    """
    url = client.url_for(path) if not path.startswith("http") else path
    if params:
        url = f"{url}?{urllib.parse.urlencode(params, doseq=True)}"
    if _CACHE_DIR:
        cached = _cache_path(url)
        if os.path.exists(cached):
            with open(cached, encoding="utf-8") as handle:
                return json.load(handle), None
    request = urllib.request.Request(url, headers=client.headers, method="GET")
    try:
        with urllib.request.urlopen(request, timeout=client.timeout) as response:
            body = response.read().decode("utf-8", "replace")
            total = response.headers.get("X-WP-Total")
    except urllib.error.HTTPError as error:
        raise RestError(
            f"HTTP {error.code} on GET {path}: {error.read().decode('utf-8', 'replace')[:200]}"
        ) from error
    payload = json.loads(body) if body.strip() else None
    if _CACHE_DIR:
        with open(_cache_path(url), "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False)
    return payload, total


_CACHE_DIR = ""


def _cache_path(url: str) -> str:
    key = hashlib.sha256(url.encode("utf-8")).hexdigest()[:32]
    return os.path.join(_CACHE_DIR, f"{key}.json")


def collect(client: RestClient, base: str, params: dict) -> list:
    """Page through a REST collection in a deterministic order (ascending id)."""
    rows: list = []
    for page in range(1, 400):
        query = dict(params)
        query.update({"per_page": "100", "page": str(page), "orderby": "id", "order": "asc"})
        body, _ = get(client, f"{client.base_url}/wp-json/wp/v2/{base}", query)
        if not body:
            break
        rows.extend(body if isinstance(body, list) else [body])
        if len(body) < 100:
            break
    return rows


def digest(parts) -> str:
    """A reproducible SHA-256 over a canonical, key-sorted serialisation."""
    hasher = hashlib.sha256()
    for part in parts:
        hasher.update(json.dumps(part, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
        hasher.update(b"\n")
    return hasher.hexdigest()


def record_identity(row: dict) -> dict:
    """The stable identity + language state of one production record."""
    lang = row.get("conexao_language") or {}
    return {
        "id": int(row["id"]),
        "slug": str(row.get("slug", "")),
        "status": str(row.get("status", "")),
        "lang": lang.get("lang"),
        "is_fallback": bool(lang.get("is_fallback", False)),
        "translations": {k: int(v["id"]) for k, v in sorted((lang.get("translations") or {}).items())},
        "link": str(row.get("link", "")),
        "title": ((row.get("title") or {}).get("rendered") or ""),
        "excerpt": ((row.get("excerpt") or {}).get("rendered") or ""),
        "date": str(row.get("date", "")),
        "modified": str(row.get("modified", "")),
        "parent": int(row.get("parent", 0) or 0),
        "menu_order": int(row.get("menu_order", 0) or 0),
        "terms": {
            key: [int(t) for t in value]
            for key, value in sorted(row.items())
            if key.startswith("conexao_") and isinstance(value, list)
        },
        "meta": {k: v for k, v in sorted((row.get("meta") or {}).items())},
    }


def term_identity(row: dict) -> dict:
    """The stable identity + language state of one production term."""
    lang = row.get("conexao_language") or {}
    name = row.get("name", "")
    return {
        "id": int(row["id"]),
        "slug": str(row.get("slug", "")),
        "name": name.get("rendered", "") if isinstance(name, dict) else str(name),
        "description": str(row.get("description", "")),
        "count": int(row.get("count", 0) or 0),
        "lang": lang.get("lang"),
        "translations": {k: int(v["id"]) for k, v in sorted((lang.get("translations") or {}).items())},
    }


def resolve_page_languages(client: RestClient, identities: dict) -> None:
    """Fill in each page's own language and translation links, in place.

    The bilingual REST contract registers ``conexao_language`` on the seven
    content post types, not on ``page``; the ``search-result`` surface registers
    the same field, so each page is resolved by searching for its own title and
    matching the returned id. A page with no search hit is left unresolved and
    is reported as such rather than guessed.
    """
    for post_id, identity in identities.items():
        for term in (identity.get("title") or "", identity.get("slug") or ""):
            if not term.strip():
                continue
            rows, _ = get(client, f"{client.base_url}/wp-json/wp/v2/search",
                          {"search": term, "subtype": "page", "per_page": "100"})
            for row in rows or []:
                if int(row.get("id", 0)) != post_id:
                    continue
                lang = row.get("conexao_language") or {}
                identity["lang"] = lang.get("lang")
                identity["translations"] = {
                    k: int(v["id"]) for k, v in sorted((lang.get("translations") or {}).items())
                }
                identity["language_source"] = "search"
                break
            if identity.get("language_source"):
                break


def read_live_state(client: RestClient, drafts: bool) -> tuple:
    """Enumerate the live post-type and taxonomy state (GET only).

    ``status=any`` is used so unpublished records are visible too. The
    ``conexao_language`` field is schema-registered for the ``view``/``embed``
    contexts only, so ``context=edit`` is deliberately NOT requested: it would
    strip the very field that carries the record's own language and translation
    links.
    """
    scope = {"status": "any"} if drafts else {"status": "publish"}
    post_types: dict = {}
    for post_type in list(dict.fromkeys(list(TRANSLATED_POST_TYPES) + list(B1_POST_TYPES))):
        base = REST_BASE.get(post_type, post_type)
        everything = collect(client, base, dict(scope))
        pt_rows = collect(client, base, dict(scope, lang="pt"))
        en_rows = collect(client, base, dict(scope, lang="en"))
        identities = {int(r["id"]): record_identity(r) for r in everything}
        for row in pt_rows + en_rows:
            identities[int(row["id"])] = record_identity(row)
        # `page` is NOT in conexao_rest_language_post_types(), so the pages
        # collection carries no `conexao_language` field. Its language and
        # translation links are resolved through the search surface instead,
        # which registers the same field for search results.
        if post_type == "page":
            resolve_page_languages(client, identities)
        # The record's OWN language is the authority; the request `lang` is not.
        post_types[post_type] = {
            "rest_base": base,
            "total_records": len(identities),
            "pt_records": len([i for i in identities.values() if i["lang"] == "pt"]),
            "en_records": len([i for i in identities.values() if i["lang"] == "en"]),
            "unassigned_records": len([i for i in identities.values() if i["lang"] is None]),
            "collection_pt": len(pt_rows),
            "collection_en": len(en_rows),
            "by_id": identities,
        }

    taxonomies: dict = {}
    for taxonomy in list(TRANSLATED_TAXONOMIES) + list(SHARED_TAXONOMIES):
        everything = collect(client, taxonomy, {"hide_empty": "false"})
        pt_rows = collect(client, taxonomy, {"hide_empty": "false", "lang": "pt"})
        en_rows = collect(client, taxonomy, {"hide_empty": "false", "lang": "en"})
        identities = {int(r["id"]): term_identity(r) for r in everything}
        for row in pt_rows + en_rows:
            identities[int(row["id"])] = term_identity(row)
        taxonomies[taxonomy] = {
            "total_terms": len(identities),
            "pt_terms": len([i for i in identities.values() if i["lang"] == "pt"]),
            "en_terms": len([i for i in identities.values() if i["lang"] == "en"]),
            "unassigned_terms": len([i for i in identities.values() if i["lang"] is None]),
            "collection_pt": len(pt_rows),
            "collection_en": len(en_rows),
            "by_id": identities,
        }
    return post_types, taxonomies


# -- classification ---------------------------------------------------------

CATEGORIES = (
    "CREATE_CANDIDATE",
    "ALREADY_COMPLETE",
    "B2_ONLY",
    "CONFLICT",
    "ORPHAN",
    "OUT_OF_SCOPE",
    "INVALID",
)


def _normalise(value: str) -> str:
    """The Stage 7 drift normaliser: representation only, never content."""
    table = {"‘": "'", "’": "'", "“": '"', "”": '"',
             "–": "-", "—": "-", "…": "...", " ": " "}
    out = str(value or "")
    for needle, replacement in table.items():
        out = out.replace(needle, replacement)
    return re.sub(r"\s+", " ", out).strip()


def _strip_html(value: str) -> str:
    """Flatten a rendered WordPress field to its plain text."""
    text = re.sub(r"<[^>]+>", " ", value or "")
    text = (text.replace("&nbsp;", " ").replace("&amp;", "&")
                .replace("&#8217;", "’").replace("&#8220;", "“")
                .replace("&#8221;", "”").replace("&#8211;", "–"))
    return re.sub(r"\s+", " ", text).strip()


def classify_record_stage(stage: str, post_type: str, pt: dict, row: dict, live: dict) -> dict:
    """Classify one B1 ``record`` stage row against live production state.

    This mirrors the shared engine's ``build_plan()`` decision order exactly
    (PT language -> linked EN -> EN slug holder -> create) so the manifest is
    the engine's own plan, evaluated read-only.
    """
    en_slug = str(row.get("en_slug", ""))
    entry = {
        "stage": stage,
        "object_type": "record",
        "post_type": post_type,
        "b1_b2": "B1",
        "source_pt_id": int(pt["id"]),
        "source_slug": pt["slug"],
        "source_status": pt["status"],
        "source_lang": pt["lang"],
        "canonical_url": pt["link"],
        "target_lang": "en",
        "target_object_type": "post",
        "target_slug": en_slug,
        "target_post_type": post_type,
        "existing_translations": pt["translations"],
    }
    if pt["lang"] != "pt":
        entry["category"] = "INVALID"
        entry["reason"] = f"PT source carries language {pt['lang']!r}, not 'pt'"
        return entry

    linked_en_id = int(pt["translations"].get("en", 0) or 0)
    if linked_en_id:
        en = live[post_type]["by_id"].get(linked_en_id)
        entry["en_id"] = linked_en_id
        if en is None:
            entry["category"] = "CONFLICT"
            entry["reason"] = "PT links to an EN record that is not retrievable"
            return entry
        entry["en_slug"] = en["slug"]
        entry["en_status"] = en["status"]
        entry["en_link"] = en["link"]
        if int(en["translations"].get("pt", 0) or 0) != int(pt["id"]):
            entry["category"] = "CONFLICT"
            entry["reason"] = "EN record does not link back to this PT record (one-way link)"
            return entry
        if en["status"] != "publish":
            entry["category"] = "CONFLICT"
            entry["reason"] = f"linked EN record status is {en['status']!r}, not 'publish'"
            return entry
        if en_slug and en["slug"] != en_slug:
            entry["category"] = "ALREADY_COMPLETE"
            entry["slug_drift"] = True
            entry["reason"] = "EN linked and published; slug drift repairable on apply (engine 'update')"
            return entry
        entry["category"] = "ALREADY_COMPLETE"
        entry["reason"] = "EN translation already linked and verified"
        return entry

    # The engine's slug_collision(): the PT record holding its own EN slug is
    # the approved SHARED-SLUG shape (one canonical path in two languages), and
    # the record that is this PT's EN translation is the existing translation.
    # Neither is a clash. Anything else on the EN slug is a duplicate identity.
    shared_slug = en_slug == pt["slug"]
    holders = [i for i in live[post_type]["by_id"].values() if en_slug and i["slug"] == en_slug]
    foreign = [h for h in holders
               if int(h["id"]) != int(pt["id"])
               and int(h["translations"].get("pt", 0) or 0) != int(pt["id"])]
    if shared_slug:
        entry["shared_slug"] = True
        entry["reason"] = ("no EN counterpart exists; the stage creates a linked EN record on the "
                           "SHARED slug (one canonical path in two languages)")
    if foreign:
        entry["category"] = "CONFLICT"
        entry["reason"] = "authored EN slug already held by a record that is not this PT's EN translation"
        entry["slug_holders"] = sorted(h["id"] for h in foreign)
        return entry

    entry["category"] = "CREATE_CANDIDATE"
    if not shared_slug:
        entry["reason"] = "no EN translation linked yet; stage expects a real linked EN record"
    if en_slug and any(int(h["id"]) == int(pt["id"]) for h in holders):
        # The PT record itself holds the authored EN slug but the stage has no
        # shared-slug permit: WordPress would uniquify the new EN record's slug
        # and the authored URL could not be reached. Flagged, not silently kept.
        if stage not in SHARED_SLUG_STAGES:
            entry["slug_creation_risk"] = (
                "authored EN slug is already held by the PT record and this stage has no "
                "shared-slug permit; WordPress would uniquify it (documented pre-existing debt)"
            )
    return entry


def classify_field_stage(stage: str, post_type: str, pt: dict, row: dict, meta_key: str,
                         already_complete: bool) -> dict:
    """Classify one B2 ``field`` stage row: English authored onto the PT record.

    B2 means there is never a second identity, so this can only ever be a
    CREATE CANDIDATE (the field is absent) or ALREADY COMPLETE (the field is
    stored). It can never be an EN post.
    """
    entry = {
        "stage": stage,
        "object_type": "field",
        "post_type": post_type,
        "b1_b2": "B2",
        "source_pt_id": int(pt["id"]),
        "source_slug": pt["slug"],
        "source_status": pt["status"],
        "source_lang": pt["lang"],
        "canonical_url": pt["link"],
        "target_lang": "en",
        "target_object_type": "post_meta",
        "target_meta_key": meta_key,
        "target_slug": pt["slug"],
        "target_post_type": post_type,
        "existing_translations": pt["translations"],
        "en_record_expected": False,
    }
    if pt["lang"] != "pt":
        entry["category"] = "INVALID"
        entry["reason"] = f"PT source carries language {pt['lang']!r}, not 'pt'"
        return entry
    if pt["status"] != "publish":
        entry["category"] = "INVALID"
        entry["reason"] = "PT record not published (not user-facing)"
        return entry

    # The stage's own PT-drift guard: the authored English was written against
    # this exact Portuguese description. Drift is a hard conflict, never a
    # silent apply.
    authored_source = str(row.get("pt_source", ""))
    if authored_source and _normalise(_strip_html(pt["excerpt"])) != _normalise(authored_source):
        entry["category"] = "CONFLICT"
        entry["reason"] = "PT excerpt drifted from the source the English was authored against"
        return entry

    if already_complete:
        entry["category"] = "ALREADY_COMPLETE"
        entry["reason"] = f"{meta_key} is already stored on the PT record"
        return entry

    entry["category"] = "CREATE_CANDIDATE"
    entry["reason"] = f"no authored English description stored on the PT record (B2 field {meta_key})"
    return entry


def stage_manifest(repo: dict, stage: str) -> dict:
    """The repository's own authored manifest for a record stage."""
    if stage in ("en-guide", "en-page", "en-post"):
        return repo["b1_types"][STAGE_MODEL[stage][1]]
    if stage == "en-blog-page":
        return repo["blog_page"]
    if stage == "en-jobs-page":
        return repo["jobs_page"]
    raise KeyError(stage)


def absent_row(stage: str, kind: str, post_type: str, model: str, slug: str, target: dict) -> dict:
    return {
        "stage": stage, "object_type": kind, "post_type": post_type, "b1_b2": model,
        "source_pt_id": 0, "source_slug": str(slug), "target_lang": "en",
        "category": "OUT_OF_SCOPE",
        "reason": "authored EN row has no PT source on this site (documented exclusion)",
        **target,
    }


def build_manifest(repo: dict, live: dict, taxonomies: dict) -> list:
    """Join the repository stage manifests with the live production state."""
    entries: list = []
    for stage in repo["stage_ids"]:
        kind, post_type, model = STAGE_MODEL[stage]
        index = {i["slug"]: i for i in live[post_type]["by_id"].values()}
        if kind == "record":
            records = stage_manifest(repo, stage)["records"]
            for pt_slug, row in sorted(records.items()):
                pt = index.get(str(pt_slug))
                target = {"target_object_type": "post", "target_slug": str(row.get("en_slug", "")),
                          "target_post_type": post_type}
                entries.append(
                    classify_record_stage(stage, post_type, pt, row, live) if pt
                    else absent_row(stage, "record", post_type, model, pt_slug, target)
                )
        else:
            dataset = repo["leisure_desc"] if stage == "en-leisure-description" else repo["course_desc"]
            meta_key = "_leisure_excerpt_en" if stage == "en-leisure-description" else "_provider_excerpt_en"
            for pt_slug, row in sorted(dataset.items()):
                pt = index.get(str(pt_slug))
                target = {"target_object_type": "post_meta", "target_meta_key": meta_key,
                          "target_slug": str(pt_slug)}
                entries.append(
                    classify_field_stage(stage, post_type, pt, row, meta_key, False) if pt
                    else absent_row(stage, "field", post_type, model, pt_slug, target)
                )

    # -- the translated-taxonomy half of the en-guide stage.
    category_index = {t["slug"]: t for t in taxonomies["conexao_category"]["by_id"].values()}
    for pt_slug, row in sorted(repo["guide_terms"].items()):
        en_slug = str(row.get("slug", ""))
        term = category_index.get(str(pt_slug))
        entry = {
            "stage": "en-guide", "object_type": "term", "taxonomy": "conexao_category",
            "b1_b2": "B1", "source_pt_id": int(term["id"]) if term else 0,
            "source_slug": str(pt_slug), "target_lang": "en",
            "target_object_type": "term", "target_taxonomy": "conexao_category",
            "target_slug": en_slug, "target_name": str(row.get("name", "")), "en_record_expected": True,
        }
        if term is None:
            entry["category"] = "OUT_OF_SCOPE"
            entry["reason"] = "authored EN term has no PT term in conexao_category on this site"
        elif int(term["translations"].get("en", 0) or 0):
            entry["category"] = "ALREADY_COMPLETE"
            entry["en_id"] = int(term["translations"]["en"])
            entry["reason"] = "EN term already linked to its PT term"
        else:
            holders = [t for t in taxonomies["conexao_category"]["by_id"].values() if t["slug"] == en_slug]
            if holders:
                entry["category"] = "CONFLICT"
                entry["reason"] = "authored EN term slug already held by another term"
                entry["slug_holders"] = sorted(h["id"] for h in holders)
            else:
                entry["category"] = "CREATE_CANDIDATE"
                entry["reason"] = "no EN term linked to this PT category term"
        entries.append(entry)

    # -- B1 scope: published PT records of the B1 post types that no authored
    #    stage row covers. They are inventoried and explicitly out of scope,
    #    so the categories reconcile against the full discovered corpus.
    covered = {(e["post_type"], e.get("source_slug")) for e in entries if e.get("post_type")}
    for post_type in B1_POST_TYPES:
        for identity in sorted(live[post_type]["by_id"].values(), key=lambda i: i["id"]):
            if identity["status"] != "publish" or identity["lang"] != "pt":
                continue
            if (post_type, identity["slug"]) in covered:
                continue
            entries.append({
                "stage": "(none)", "object_type": "b1-scope", "post_type": post_type, "b1_b2": "B1",
                "source_pt_id": int(identity["id"]), "source_slug": identity["slug"],
                "source_status": identity["status"], "source_lang": identity["lang"],
                "canonical_url": identity["link"], "target_lang": "en",
                "target_object_type": "none", "target_slug": identity["slug"],
                "en_record_expected": True, "category": "OUT_OF_SCOPE",
                "reason": ("B1 post type with no authored EN row in any registered stage "
                           "(not part of the current rollout scope)"),
            })

    # -- B2-only scope: published PT records no stage creates an EN identity for.
    for post_type in B2_POST_TYPES:
        for identity in sorted(live[post_type]["by_id"].values(), key=lambda i: i["id"]):
            if identity["status"] != "publish" or identity["lang"] != "pt":
                continue
            if (post_type, identity["slug"]) in covered:
                continue
            entries.append({
                "stage": "(none)", "object_type": "b2-scope", "post_type": post_type, "b1_b2": "B2",
                "source_pt_id": int(identity["id"]), "source_slug": identity["slug"],
                "source_status": identity["status"], "source_lang": identity["lang"],
                "canonical_url": identity["link"], "target_lang": "en",
                "target_object_type": "none", "target_slug": identity["slug"],
                "en_record_expected": False, "category": "B2_ONLY",
                "reason": "B2 directory type: the /en/ route resolves the PT record; no EN identity is expected",
            })

    entries.sort(key=lambda e: (e["stage"], e.get("object_type", ""), str(e.get("source_slug", "")),
                                int(e.get("source_pt_id", 0) or 0)))
    return entries


def validate(entries: list, live: dict) -> dict:
    """The manifest consistency checks required before a dry-run may use it."""
    checks: dict = {}

    dupes: dict = {}
    for e in entries:
        key = (e["stage"], e.get("object_type", ""), e.get("source_slug", ""))
        dupes.setdefault(key, []).append(e)
    checks["no_duplicate_source_ids"] = {
        "pass": not any(len(v) > 1 for v in dupes.values()),
        "duplicates": sorted(f"{k[0]}:{k[1]}:{k[2]}" for k, v in dupes.items() if len(v) > 1),
    }

    targets: dict = {}
    for e in entries:
        if e.get("category") != "CREATE_CANDIDATE" or e.get("target_object_type") != "post":
            continue
        key = f"{e.get('target_post_type', e.get('post_type'))}::{e.get('target_slug')}"
        targets.setdefault(key, []).append(e)
    checks["no_duplicate_target_identity"] = {
        "pass": not any(len(v) > 1 for v in targets.values()),
        "collisions": sorted(k for k, v in targets.items() if len(v) > 1),
    }

    # A source record must not be claimed by two stages of the same post type.
    owners: dict = {}
    for e in entries:
        if e["stage"] == "(none)" or e.get("object_type") == "b2-scope":
            continue
        owners.setdefault((e.get("post_type"), e.get("source_slug")), set()).add(e["stage"])
    conflicts = {f"{k[0]}::{k[1]}": sorted(v) for k, v in owners.items() if len(v) > 1}
    checks["no_conflicting_stage_ownership"] = {"pass": not conflicts, "owners": conflicts}

    # No proposed operation may WRITE TO a record that currently carries
    # language 'pt'. A shared-slug row legitimately reuses the PT post_name,
    # but it still CREATES a second, EN-language record — the PT record is never
    # the write target, so it is reported separately rather than conflated.
    pt_write_targets = [
        e for e in entries
        if e.get("category") in ("CREATE_CANDIDATE", "ALREADY_COMPLETE")
        and e.get("target_object_type") == "post"
        and int(e.get("en_id", 0) or 0) == int(e.get("source_pt_id", 0) or 0)
        and int(e.get("en_id", 0) or 0) > 0
    ]
    shared_slug_rows = sorted(
        f"{e['stage']}::{e['source_slug']}" for e in entries if e.get("shared_slug")
    )
    checks["no_pt_overwrite"] = {
        "pass": not pt_write_targets,
        "pt_write_targets": [f"{e['stage']}::{e['source_slug']}" for e in pt_write_targets],
        "shared_slug_rows_creating_a_new_en_record": shared_slug_rows,
        "shared_slug_note": ("a shared slug is a routing shape, not a fork: the EN record is a "
                             "NEW record in the 'en' language and the PT record is only read"),
    }

    # Every existing EN object is accounted for, and none is proposed for creation.
    existing_en: set = set()
    for post_type, data in live.items():
        for identity in data["by_id"].values():
            if identity["lang"] == "en":
                existing_en.add(f"{post_type}::{identity['id']}")
    claimed = {f"{e.get('target_post_type', e.get('post_type'))}::{e['en_id']}"
               for e in entries if e.get("en_id")}
    checks["no_accidental_en_update"] = {
        "pass": existing_en.issubset(claimed) or not existing_en,
        "existing_en_records": len(existing_en),
        "unclaimed": sorted(existing_en - claimed),
    }

    shared_entered = [e for e in entries
                      if e.get("taxonomy") in SHARED_TAXONOMIES
                      or e.get("target_taxonomy") in SHARED_TAXONOMIES]
    checks["taxonomy_consistency"] = {
        "pass": not shared_entered,
        "shared_taxonomies": list(SHARED_TAXONOMIES),
        "shared_terms_in_manifest": len(shared_entered),
    }
    return checks


def pt_baseline(live: dict) -> dict:
    """A reproducible PT protection baseline: ids, slugs, statuses, counts, digests."""
    scope = list(dict.fromkeys(list(TRANSLATED_POST_TYPES) + list(B1_POST_TYPES)))
    per_type: dict = {}
    identity_rows: list = []
    for post_type in scope:
        rows = sorted(
            (i for i in live[post_type]["by_id"].values() if i["lang"] == "pt"),
            key=lambda i: i["id"],
        )
        identity_rows.extend([post_type, i["id"], i["slug"], i["status"]] for i in rows)
        content_rows = [
            {
                "id": i["id"], "slug": i["slug"], "status": i["status"], "title": i["title"],
                "excerpt": i["excerpt"], "date": i["date"], "modified": i["modified"],
                "parent": i["parent"], "menu_order": i["menu_order"], "terms": i["terms"],
                "translations": i["translations"],
            }
            for i in rows
        ]
        per_type[post_type] = {
            "pt_records": len(rows),
            "published": len([i for i in rows if i["status"] == "publish"]),
            "identity_digest": digest([[i["id"], i["slug"], i["status"]] for i in rows]),
            "content_digest": digest(content_rows),
            "ids": [i["id"] for i in rows],
            "slugs": [i["slug"] for i in rows],
        }
    return {
        "scope": scope,
        "translated_cpt_scope": list(TRANSLATED_POST_TYPES),
        "translated_cpt_pt_records": sum(per_type[t]["pt_records"] for t in TRANSLATED_POST_TYPES),
        "b1_editorial_scope": [t for t in B1_POST_TYPES if t not in TRANSLATED_POST_TYPES],
        "b1_editorial_pt_records": sum(
            per_type[t]["pt_records"] for t in B1_POST_TYPES if t not in TRANSLATED_POST_TYPES
        ),
        "total_pt_records": sum(per_type[t]["pt_records"] for t in scope),
        "identity_digest_all_types": digest(identity_rows),
        "per_type": per_type,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Read-only EN translation inventory (GET only).")
    parser.add_argument("--base-url", default=None, help="Target site URL (default CONEXAO_SITE_URL or local).")
    parser.add_argument("--manifests", required=True, help="JSON dump of the repository EN stage manifests.")
    parser.add_argument("--out", required=True, help="Where to write the inventory JSON.")
    parser.add_argument("--drafts", action="store_true", help="Also read non-published records (context=edit).")
    parser.add_argument("--cache-dir", default="", help="Optional read cache directory (development only).")
    args = parser.parse_args()

    global _CACHE_DIR
    if args.cache_dir:
        os.makedirs(args.cache_dir, exist_ok=True)
        _CACHE_DIR = args.cache_dir

    base_url = resolve_base_url(args.base_url)
    target = classify_target(base_url)
    print(f"EN translation inventory — READ ONLY\n  target: {base_url} ({target})\n  verb:   GET only\n")

    client = RestClient(base_url, user_agent=USER_AGENT, authenticated=True,
                        timeout=60, retries=3, backoff=3.0)
    with open(args.manifests, encoding="utf-8") as handle:
        repo = json.load(handle)

    pll_settings, _ = get(client, f"{base_url}/wp-json/pll/v1/settings")
    pll_languages, _ = get(client, f"{base_url}/wp-json/pll/v1/languages")
    live, taxonomies = read_live_state(client, args.drafts)
    entries = build_manifest(repo, live, taxonomies)
    checks = validate(entries, live)
    baseline = pt_baseline(live)

    counts = {c: len([e for e in entries if e["category"] == c]) for c in CATEGORIES}
    by_stage: dict = {}
    for e in entries:
        slot = by_stage.setdefault(e["stage"], {c: 0 for c in CATEGORIES})
        slot[e["category"]] += 1

    report = {
        "generated_at_utc": _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "target": base_url, "target_class": target, "http_verbs_used": ["GET"],
        "production_writes": 0,
        "polylang": {
            "settings": pll_settings,
            "languages": [
                {"slug": l["slug"], "name": l["name"], "locale": l["locale"],
                 "term_id": int(l["term_id"]), "is_default": bool(l["is_default"]),
                 "home_url": l["home_url"],
                 "assigned_count": int(((l.get("term_props") or {}).get("language") or {}).get("count", 0))}
                for l in pll_languages
            ],
        },
        "post_types": {pt: {k: v for k, v in d.items() if k != "by_id"} for pt, d in live.items()},
        "taxonomies": {t: {k: v for k, v in d.items() if k != "by_id"} for t, d in taxonomies.items()},
        "en_state": {
            pt: {
                "en_ids": sorted(i["id"] for i in d["by_id"].values() if i["lang"] == "en"),
                "en_slugs": sorted(i["slug"] for i in d["by_id"].values() if i["lang"] == "en"),
                "linked_pairs": sorted(
                    f"{pt}::{i['id']}->en::{i['translations']['en']}"
                    for i in d["by_id"].values() if i["lang"] == "pt" and i["translations"].get("en")
                ),
                "orphan_en": sorted(
                    i["id"] for i in d["by_id"].values()
                    if i["lang"] == "en" and not i["translations"].get("pt")
                ),
                "unassigned": sorted(i["id"] for i in d["by_id"].values() if i["lang"] is None),
            }
            for pt, d in live.items()
        },
        "stage_model": {s: {"object_kind": k, "post_type": p, "b1_b2": m}
                        for s, (k, p, m) in sorted(STAGE_MODEL.items())},
        "stage_counts": by_stage,
        "category_counts": counts,
        "total_source_objects": len(entries),
        "validation": checks,
        "pt_baseline": baseline,
        "manifest_digest": digest(entries),
    }

    with open(args.out, "w", encoding="utf-8") as handle:
        json.dump({"report": report, "manifest": entries, "live": live,
                   "taxonomies_full": taxonomies}, handle, ensure_ascii=False, indent=1, sort_keys=True)

    print(f"  total source objects : {len(entries)}")
    for c in CATEGORIES:
        print(f"    {c:<18} {counts[c]}")
    print(f"  manifest digest      : {report['manifest_digest']}")
    print(f"  PT records (scope)   : {baseline['total_pt_records']}")
    print(f"  checks               : {'ALL PASS' if all(v['pass'] for v in checks.values()) else 'FAILED'}")
    print(f"  wrote                : {args.out}")
    return 0 if all(v["pass"] for v in checks.values()) else 2


if __name__ == "__main__":
    sys.exit(main())
