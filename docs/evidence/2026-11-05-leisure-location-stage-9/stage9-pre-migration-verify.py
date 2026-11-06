#!/usr/bin/env python3
"""Stage 8 — read-only verification of the Lazer location migration dry-run.

Purpose
-------
Stage 8 runs the EXISTING migration script
(`scripts/migrate-leisure-location-rest.py`) in dry-run mode against production
and must prove, record by record, that the plan it prints is the plan we want.
This verifier is the independent second opinion that makes that possible:

  * it builds the fresh production inventory (GET only),
  * it builds the local source inventory (GET only),
  * it re-derives every planned coordinate from the manifest and compares it to
    the local meta value and to the row the migration script printed,
  * it counts the `conexao_leisure_attribute` ASSIGNMENT RELATIONSHIPS on both
    sides (local vs production), so "592" is 592 (record, term) pairs and not
    592 records or 592 terms,
  * it checks the per-record superset property the add-only attribute write
    depends on (production set subset of local set) and reports every
    production-only term — the only way that write could ever remove something,
  * it checks that every local attribute slug resolves to a production term
    (the script silently drops an unresolvable slug),
  * it compares a BEFORE and an AFTER production sweep, so "the dry run wrote
    nothing" is measured on `modified_gmt` and on the meta values themselves,
    not asserted from the script's own claim.

Safety
------
READ-ONLY BY CONSTRUCTION. This module implements `get()` only; there is no
code path that can issue POST/PUT/PATCH/DELETE. Credentials come from the
environment through `scripts/lib/rest.py` and are never printed. It refuses to
run against an unclassified host and never writes outside its `--out-dir`.

Run from the repository root:

    set -a; . ./.env; set +a
    python3 docs/evidence/2026-11-05-leisure-location-stage-8/stage8-dry-run-verify.py \
        --phase pre --out-dir docs/evidence/2026-11-05-leisure-location-stage-8

Exit code 0 = every check passed.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

REPO_ROOT = os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..")
)
sys.path.insert(0, os.path.join(REPO_ROOT, "scripts", "lib"))
import rest  # noqa: E402  (shared REST engine: auth, UA, target classification)

MIGRATION = os.path.join(REPO_ROOT, "scripts", "migrate-leisure-location-rest.py")
MANIFEST = os.path.join(
    REPO_ROOT, "scripts", "data", "leisure-coordinates-2026-11-03.json"
)
USER_AGENT = "ConexaoBR-stage8-dryrun-verifier/1.0 (+repository tooling)"
ATTRIBUTE_TAXONOMY = "conexao_leisure_attribute"
PER_PAGE = 100


def load_migration_module():
    """Import the migration script so the verifier uses ITS rules, not copies."""
    spec = importlib.util.spec_from_file_location("leisure_migration_stage8", MIGRATION)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


MIGRATION_MODULE = load_migration_module()
normalise = MIGRATION_MODULE.normalise


class ReadOnlyClient:
    """GET-only client bound to one site. There is no write method."""

    def __init__(self, base_url, delay=0.5, timeout=45):
        self.base_url = base_url.rstrip("/")
        self.delay = delay
        self.timeout = timeout
        self.headers = rest.default_headers(USER_AGENT, authenticated=True)
        self.requests = 0

    def get(self, path, params=None, attempts=6):
        """One GET, retrying 429/5xx with Retry-After. Still read-only."""
        url = "{}/wp-json/{}".format(self.base_url, path.lstrip("/"))
        if params:
            url = "{}?{}".format(url, urllib.parse.urlencode(params))
        for attempt in range(1, attempts + 1):
            request = urllib.request.Request(url, headers=self.headers, method="GET")
            self.requests += 1
            try:
                with urllib.request.urlopen(request, timeout=self.timeout) as response:
                    body = json.loads(
                        response.read().decode("utf-8", "replace") or "null"
                    )
                    return response.status, dict(response.headers), body
            except urllib.error.HTTPError as error:
                if error.code in (429, 500, 502, 503, 504) and attempt < attempts:
                    retry_after = error.headers.get("Retry-After") if error.headers else None
                    try:
                        wait = min(float(retry_after), 60.0)
                    except (TypeError, ValueError):
                        wait = min(3.0 * attempt, 30.0)
                    sys.stderr.write(
                        "  HTTP {} on {}; waiting {:.0f}s and retrying [{}/{}]\n".format(
                            error.code, path, wait, attempt, attempts
                        )
                    )
                    time.sleep(wait)
                    continue
                detail = error.read().decode("utf-8", "replace")[:200]
                raise SystemExit(
                    "ERROR: GET {} -> HTTP {}: {}".format(url, error.code, detail)
                )
            finally:
                time.sleep(self.delay)
        raise SystemExit("ERROR: GET {} exhausted its retries".format(url))


    def get_all(self, path, params):
        """Follow X-WP-TotalPages; return (records, page_report)."""
        collected, pages = [], []
        page = 1
        while True:
            query = dict(params, per_page=PER_PAGE, page=page)
            status, headers, body = self.get(path, query)
            if not isinstance(body, list):
                raise SystemExit(
                    "ERROR: expected a list from {} page {}; got {}".format(
                        path, page, type(body).__name__
                    )
                )
            total_pages = int(headers.get("X-WP-TotalPages") or 1)
            pages.append(
                {
                    "page": page,
                    "status": status,
                    "returned": len(body),
                    "x_wp_total": headers.get("X-WP-Total"),
                    "x_wp_total_pages": headers.get("X-WP-TotalPages"),
                }
            )
            collected.extend(body)
            if page >= total_pages:
                break
            page += 1
        return collected, pages


def records_by_slug(client, label):
    """Fetch published `leisure` records and index them by slug (fails closed)."""
    records, pages = client.get_all(
        "wp/v2/leisure",
        {
            "_fields": "id,slug,status,modified_gmt,meta,{}".format(ATTRIBUTE_TAXONOMY),
            "status": "publish",
        },
    )
    index, by_id, duplicates = {}, {}, []
    for record in records:
        slug = normalise(record.get("slug"))
        post_id = record.get("id")
        if not slug:
            duplicates.append("<empty slug: {}>".format(post_id))
            continue
        if slug in index:
            duplicates.append(slug)
            continue
        meta = record.get("meta") or {}
        index[slug] = {
            "id": post_id,
            "slug": slug,
            "status": normalise(record.get("status")),
            "modified_gmt": normalise(record.get("modified_gmt")),
            "lat": normalise(meta.get(MIGRATION_MODULE.LAT_KEY)),
            "lng": normalise(meta.get(MIGRATION_MODULE.LNG_KEY)),
            "attribute_ids": list(record.get(ATTRIBUTE_TAXONOMY) or []),
        }
        if post_id in by_id:
            duplicates.append("duplicate id {}".format(post_id))
        by_id[post_id] = slug
    if duplicates:
        raise SystemExit(
            "ERROR: {} has ambiguous records: {}".format(label, duplicates[:10])
        )
    return index, by_id, records, pages


def term_map(client):
    """Fetch one taxonomy's terms keyed by slug AND by id."""
    terms, pages = client.get_all(
        "wp/v2/{}".format(ATTRIBUTE_TAXONOMY), {"_fields": "id,slug,name,count"}
    )
    by_slug, by_id = {}, {}
    for term in terms:
        slug = normalise(term.get("slug"))
        if slug:
            by_slug[slug] = term.get("id")
        by_id[term.get("id")] = slug
    return by_slug, by_id, terms, pages


def attribute_slugs(record, by_id):
    """Local/production term SLUGS for one record, sorted (ids are env-local)."""
    return sorted(
        {by_id[tid] for tid in record["attribute_ids"] if tid in by_id and by_id[tid]}
    )


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phase", required=True, choices=["pre", "post"])
    parser.add_argument(
        "--production", default="https://conexaobr.ie", help="Production base URL."
    )
    parser.add_argument("--local", default="http://localhost:8080")
    parser.add_argument("--out-dir", required=True)
    parser.add_argument(
        "--baseline",
        default=None,
        help="A previous phase JSON to compare against (used by --phase post).",
    )
    parser.add_argument(
        "--report",
        default=None,
        help="The migration dry-run JSON report to cross-check row for row.",
    )
    args = parser.parse_args(argv)

    if rest.classify_target(args.production) != "production":
        raise SystemExit("ERROR: --production must classify as production")
    if rest.classify_target(args.local) != "local":
        raise SystemExit("ERROR: --local must classify as local")

    os.makedirs(args.out_dir, exist_ok=True)
    started = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    prod = ReadOnlyClient(args.production)
    local = ReadOnlyClient(args.local)

    prod_index, prod_by_id, prod_records, prod_pages = records_by_slug(
        prod, "production"
    )
    local_index, _local_by_id, local_records, local_pages = records_by_slug(
        local, "local"
    )
    prod_terms, prod_term_ids, prod_term_rows, _ = term_map(prod)
    local_terms, local_term_ids, local_term_rows, _ = term_map(local)

    # ---- coordinate plan re-derivation (the migration's own rules) ---------
    entries = MIGRATION_MODULE.load_manifest(MANIFEST)
    eligible, skipped_manifest = MIGRATION_MODULE.eligible_entries(entries)

    rows = []
    for entry in eligible:
        row = MIGRATION_MODULE.plan_entry(entry, prod_index, overwrite=False)
        slug = row["slug"]
        local_record = local_index.get(slug)
        prod_record = prod_index.get(slug)
        rows.append(
            {
                "slug": slug,
                "uuid": row["uuid"],
                "title": row["title"],
                "action": row["action"],
                "reason": row["reason"],
                "manifest_lat": row["lat"],
                "manifest_lng": row["lng"],
                "local_lat": local_record["lat"] if local_record else None,
                "local_lng": local_record["lng"] if local_record else None,
                "production_id": prod_record["id"] if prod_record else None,
                "production_lat": prod_record["lat"] if prod_record else None,
                "production_lng": prod_record["lng"] if prod_record else None,
                "planned_target_id": row["target_id"],
                "match": row["match"],
            }
        )

    # ---- attribute ASSIGNMENT RELATIONSHIPS, (record -> term) pairs --------
    local_by_slug_attr, prod_by_slug_attr = {}, {}
    for slug in sorted(set(local_index) | set(prod_index)):
        local_by_slug_attr[slug] = (
            set(attribute_slugs(local_index[slug], local_term_ids))
            if slug in local_index
            else set()
        )
        prod_by_slug_attr[slug] = (
            set(attribute_slugs(prod_index[slug], prod_term_ids))
            if slug in prod_index
            else set()
        )

    per_record, resolved_local_slugs = [], set()
    for slug in sorted(set(local_index) & set(prod_index)):
        local_set = local_by_slug_attr[slug]
        prod_set = prod_by_slug_attr[slug]
        planned_ids = sorted(prod_terms[name] for name in local_set if name in prod_terms)
        per_record.append(
            {
                "slug": slug,
                "production_id": prod_index[slug]["id"],
                "local_terms": sorted(local_set),
                "production_terms": sorted(prod_set),
                "missing_in_production": sorted(local_set - prod_set),
                "production_only": sorted(prod_set - local_set),
                "unresolved_local_terms": sorted(local_set - set(prod_terms)),
                "planned_term_ids": planned_ids,
                "changes": bool(local_set - prod_set),
            }
        )
        resolved_local_slugs |= local_set & set(prod_terms)

    unresolved_local_terms = sorted(
        {name for row in per_record for name in row["unresolved_local_terms"]}
    )

    # ---- cross-check the migration's printed report, row for row ------------
    report_check = {"checked": False}
    if args.report:
        with open(args.report, encoding="utf-8") as handle:
            document = json.load(handle)
        plan_by_slug = {row["slug"]: row for row in document.get("plan", [])}
        attr_by_slug = {row["slug"]: row for row in document.get("attributes", [])}
        coord_mismatches, attr_mismatches, missing_rows = [], [], []
        for row in rows:
            printed = plan_by_slug.get(row["slug"])
            if printed is None:
                missing_rows.append(row["slug"])
                continue
            if (
                printed.get("action") != row["action"]
                or normalise(printed.get("lat")) != row["manifest_lat"]
                or normalise(printed.get("lng")) != row["manifest_lng"]
                or printed.get("target_id") != row["production_id"]
                or printed.get("match") != "slug"
            ):
                coord_mismatches.append(
                    {
                        "slug": row["slug"],
                        "printed": {
                            "action": printed.get("action"),
                            "lat": printed.get("lat"),
                            "lng": printed.get("lng"),
                            "target_id": printed.get("target_id"),
                        },
                        "expected": {
                            "action": row["action"],
                            "lat": row["manifest_lat"],
                            "lng": row["manifest_lng"],
                            "target_id": row["production_id"],
                        },
                    }
                )
        for row in per_record:
            printed = attr_by_slug.get(row["slug"])
            if not row["planned_term_ids"]:
                continue
            if printed is None:
                attr_mismatches.append({"slug": row["slug"], "why": "missing plan row"})
                continue
            if sorted(printed.get("term_ids") or []) != row["planned_term_ids"]:
                attr_mismatches.append(
                    {
                        "slug": row["slug"],
                        "printed": printed.get("term_ids"),
                        "expected": row["planned_term_ids"],
                    }
                )
        report_check = {
            "checked": True,
            "report": os.path.relpath(args.report, REPO_ROOT),
            "report_summary": document.get("summary"),
            "plan_rows_printed": len(plan_by_slug),
            "attribute_rows_printed": len(attr_by_slug),
            "coordinate_mismatches": coord_mismatches,
            "attribute_mismatches": attr_mismatches,
            "plan_rows_missing": missing_rows,
        }

    # ---- production snapshot (the before/after zero-write evidence) --------
    production_snapshot = {
        "captured": started,
        "base_url": args.production,
        "pages": prod_pages,
        "records": [
            {
                "id": record["id"],
                "slug": record["slug"],
                "status": record["status"],
                "modified_gmt": record["modified_gmt"],
                "lat": record["lat"],
                "lng": record["lng"],
                "attribute_ids": record["attribute_ids"],
                "attribute_terms": attribute_slugs(record, prod_term_ids),
            }
            for record in sorted(prod_index.values(), key=lambda item: item["slug"])
        ],
    }
    production_snapshot_path = os.path.join(
        args.out_dir, "production-inventory-{}.json".format(args.phase)
    )
    with open(production_snapshot_path, "w", encoding="utf-8") as handle:
        json.dump(production_snapshot, handle, indent=1, ensure_ascii=False)

    if args.phase == "pre":
        with open(
            os.path.join(args.out_dir, "local-inventory.json"),
            "w",
            encoding="utf-8",
        ) as handle:
            json.dump(
                {
                    "captured": started,
                    "base_url": args.local,
                    "pages": local_pages,
                    "records": [
                        {
                            "id": record["id"],
                            "slug": record["slug"],
                            "status": record["status"],
                            "modified_gmt": record["modified_gmt"],
                            "lat": record["lat"],
                            "lng": record["lng"],
                            "attribute_terms": attribute_slugs(record, local_term_ids),
                        }
                        for record in sorted(
                            local_index.values(), key=lambda item: item["slug"]
                        )
                    ],
                },
                handle,
                indent=1,
                ensure_ascii=False,
            )

    # ---- BEFORE/AFTER comparison: the zero-write proof ---------------------
    write_proof = {"checked": False}
    if args.baseline:
        with open(args.baseline, encoding="utf-8") as handle:
            before = json.load(handle)
        before_by_slug = {row["slug"]: row for row in before["records"]}
        changed, added = [], []
        for record in production_snapshot["records"]:
            previous = before_by_slug.get(record["slug"])
            if previous is None:
                added.append(record["slug"])
            elif previous != record:
                changed.append({"slug": record["slug"], "before": previous, "after": record})
        after_slugs = {row["slug"] for row in production_snapshot["records"]}
        removed = sorted(set(before_by_slug) - after_slugs)
        write_proof = {
            "checked": True,
            "baseline": os.path.relpath(args.baseline, REPO_ROOT),
            "records_before": len(before_by_slug),
            "records_after": len(after_slugs),
            "changed_records": changed,
            "added_records": added,
            "removed_records": removed,
            "identical": not changed and not added and not removed,
        }

    coordinate_counts = {}
    for row in rows:
        coordinate_counts[row["action"]] = coordinate_counts.get(row["action"], 0) + 1

    summary = {
        "phase": args.phase,
        "captured": started,
        "get_requests": {"production": prod.requests, "local": local.requests},
        "production": {
            "base_url": args.production,
            "pages": prod_pages,
            "records": len(prod_records),
            "distinct_ids": len(prod_by_id),
            "distinct_slugs": len(prod_index),
            "with_both_coordinates": sum(
                1 for record in prod_index.values() if record["lat"] and record["lng"]
            ),
            "half_filled": sum(
                1
                for record in prod_index.values()
                if bool(record["lat"]) != bool(record["lng"])
            ),
            "statuses": sorted({record["status"] for record in prod_index.values()}),
            "attribute_terms": {
                "count": len(prod_term_rows),
                "slugs": sorted(prod_terms),
            },
            "attribute_assignments": sum(
                len(terms) for terms in prod_by_slug_attr.values()
            ),
            "records_with_attributes": sum(
                1 for terms in prod_by_slug_attr.values() if terms
            ),
        },
        "local": {
            "base_url": args.local,
            "pages": local_pages,
            "records": len(local_records),
            "distinct_ids": len({record["id"] for record in local_index.values()}),
            "distinct_slugs": len(local_index),
            "with_both_coordinates": sum(
                1 for record in local_index.values() if record["lat"] and record["lng"]
            ),
            "attribute_terms": {
                "count": len(local_term_rows),
                "slugs": sorted(local_terms),
            },
            "attribute_assignments": sum(
                len(terms) for terms in local_by_slug_attr.values()
            ),
            "records_with_attributes": sum(
                1 for terms in local_by_slug_attr.values() if terms
            ),
        },
        "matching": {
            "local_only": sorted(set(local_index) - set(prod_index)),
            "production_only": sorted(set(prod_index) - set(local_index)),
            "matched_by_slug": len(set(local_index) & set(prod_index)),
        },
        "coordinates": {
            "manifest_entries": len(entries),
            "eligible_entries": len(eligible),
            "eligible_skipped_by_reason": skipped_manifest,
            "actions": coordinate_counts,
            "manifest_vs_local_mismatches": [
                {
                    "slug": row["slug"],
                    "manifest": [row["manifest_lat"], row["manifest_lng"]],
                    "local": [row["local_lat"], row["local_lng"]],
                }
                for row in rows
                if row["local_lat"] is None
                or not MIGRATION_MODULE._same_coordinate(
                    row["manifest_lat"], row["local_lat"]
                )
                or not MIGRATION_MODULE._same_coordinate(
                    row["manifest_lng"], row["local_lng"]
                )
            ],
            "local_missing_coordinate": [
                row["slug"]
                for row in rows
                if row["local_lat"] is None and row["production_lat"] is None
            ],
        },
        "attributes": {
            "local_assignments": sum(
                len(terms) for terms in local_by_slug_attr.values()
            ),
            "production_assignments": sum(
                len(terms) for terms in prod_by_slug_attr.values()
            ),
            "missing_in_production": sum(
                len(row["missing_in_production"]) for row in per_record
            ),
            "production_only_assignments": sum(
                len(row["production_only"]) for row in per_record
            ),
            "records_with_changes": sum(1 for row in per_record if row["changes"]),
            "records_in_plan": sum(1 for row in per_record if row["planned_term_ids"]),
            "planned_assignments": sum(
                len(row["planned_term_ids"]) for row in per_record
            ),
            "unresolved_local_terms": unresolved_local_terms,
            "resolved_local_term_slugs": sorted(resolved_local_slugs),
        },
        "report_cross_check": report_check,
        "write_proof": write_proof,
        "per_record": per_record,
    }

    with open(
        os.path.join(args.out_dir, "verification-{}.json".format(args.phase)),
        "w",
        encoding="utf-8",
    ) as handle:
        json.dump(summary, handle, indent=1, ensure_ascii=False)
    with open(
        os.path.join(args.out_dir, "coordinate-plan-{}.json".format(args.phase)),
        "w",
        encoding="utf-8",
    ) as handle:
        json.dump(rows, handle, indent=1, ensure_ascii=False)
    with open(
        os.path.join(args.out_dir, "attribute-plan-{}.json".format(args.phase)),
        "w",
        encoding="utf-8",
    ) as handle:
        json.dump(per_record, handle, indent=1, ensure_ascii=False)

    errors = []
    if len(prod_records) != len(prod_index):
        errors.append("production record count differs from its distinct-slug count")
    if len(prod_by_id) != len(prod_records):
        errors.append("production ids are not unique")
    if summary["matching"]["production_only"]:
        errors.append("production has records with no local counterpart")
    if summary["coordinates"]["manifest_vs_local_mismatches"]:
        errors.append("manifest coordinates disagree with the local database")
    if unresolved_local_terms:
        errors.append("local attribute slugs do not resolve in production")
    if report_check.get("checked"):
        if report_check["coordinate_mismatches"]:
            errors.append("the printed coordinate plan disagrees with the re-derivation")
        if report_check["attribute_mismatches"]:
            errors.append("the printed attribute plan disagrees with the re-derivation")
        if report_check["plan_rows_missing"]:
            errors.append("the printed plan is missing manifest records")
    if write_proof.get("checked") and not write_proof["identical"]:
        errors.append("production changed between the two sweeps")

    print("phase:       {}".format(args.phase))
    print("captured:    {}".format(started))
    print(
        "production:  {} records, {} distinct ids, {} distinct slugs, "
        "{} with coordinates, {} with attributes ({} assignments, {} terms)".format(
            summary["production"]["records"],
            summary["production"]["distinct_ids"],
            summary["production"]["distinct_slugs"],
            summary["production"]["with_both_coordinates"],
            summary["production"]["records_with_attributes"],
            summary["production"]["attribute_assignments"],
            summary["production"]["attribute_terms"]["count"],
        )
    )
    print(
        "local:       {} records, {} with coordinates, {} with attributes "
        "({} assignments, {} terms)".format(
            summary["local"]["records"],
            summary["local"]["with_both_coordinates"],
            summary["local"]["records_with_attributes"],
            summary["local"]["attribute_assignments"],
            summary["local"]["attribute_terms"]["count"],
        )
    )
    print(
        "matching:    {} matched by slug, local_only={}, production_only={}".format(
            summary["matching"]["matched_by_slug"],
            summary["matching"]["local_only"],
            summary["matching"]["production_only"],
        )
    )
    print(
        "coordinates: manifest={} eligible={} actions={} manifest/local mismatches={}".format(
            len(entries),
            len(eligible),
            coordinate_counts,
            len(summary["coordinates"]["manifest_vs_local_mismatches"]),
        )
    )
    print(
        "attributes:  local={} production={} missing={} production_only={} "
        "records_changing={} plan_records={} planned_assignments={} unresolved={}".format(
            summary["attributes"]["local_assignments"],
            summary["attributes"]["production_assignments"],
            summary["attributes"]["missing_in_production"],
            summary["attributes"]["production_only_assignments"],
            summary["attributes"]["records_with_changes"],
            summary["attributes"]["records_in_plan"],
            summary["attributes"]["planned_assignments"],
            unresolved_local_terms,
        )
    )
    if report_check.get("checked"):
        print(
            "report:      {} (plan rows {}, attribute rows {}, coordinate mismatches {}, "
            "attribute mismatches {}, missing rows {})".format(
                report_check["report"],
                report_check["plan_rows_printed"],
                report_check["attribute_rows_printed"],
                len(report_check["coordinate_mismatches"]),
                len(report_check["attribute_mismatches"]),
                len(report_check["plan_rows_missing"]),
            )
        )
    if write_proof.get("checked"):
        print(
            "write proof: before={} after={} changed={} added={} removed={} identical={}".format(
                write_proof["records_before"],
                write_proof["records_after"],
                len(write_proof["changed_records"]),
                len(write_proof["added_records"]),
                len(write_proof["removed_records"]),
                write_proof["identical"],
            )
        )
    if errors:
        print("FAIL: " + "; ".join(errors))
        return 1
    print("PASS: every Stage 8 check holds")
    return 0


if __name__ == "__main__":
    sys.exit(main())

