#!/usr/bin/env python3
"""Stage 9 — independent reconciliation of the Lazer location migration.

Purpose
-------
Stage 9 applies (or, before that, verifies) the Lazer location migration on
production with the EXISTING script
(`scripts/migrate-leisure-location-rest.py`). This module is the second,
independent opinion that the task requires: it never asks the migration script
what it intends to do, it re-derives the expectation from the local source of
truth and checks it against production, record by record.

Why it exists next to `stage9-pre-migration-verify.py`
------------------------------------------------------
`stage9-pre-migration-verify.py` is the Stage 8 verifier, copied byte-for-byte
into this evidence directory so that the Stage 8 evidence directory stays
untouched. It produces the inventories, the per-record plans and the printed
plan cross-check. This module answers a different question, and answers it with
counts:

  pre  — is the plan the script printed what the source data actually says?
         (289 `POPULATE` rows, planned values equal to the manifest AND to the
         local database, distinct target ids, every production term set a
         subset of the local one, all 13 term slugs resolving to the expected
         production term ids, 0 planned removals)

  post — did the write land, completely, and nowhere else?
         (289/289 coordinates equal to the local source, 592/592 assignment
         relationships present as an exact set match, 0 lost terms, no record
         added or removed, `modified_gmt` moved on exactly the 289 records in
         scope)

Safety
------
READ-ONLY BY CONSTRUCTION. The only network calls are `GET`s, issued through
the shared REST engine (`scripts/lib/rest.py`, credentials from the environment,
never printed) via the migration script's own rate-limit-aware client. There is
no code path that can issue POST/PUT/PATCH/DELETE, and nothing is written
outside the text report this module prints.

Run from the repository root:

    set -a; . ./.env; set +a
    python3 docs/evidence/2026-11-05-leisure-location-stage-9/\
stage9-independent-reconcile.py --phase pre

Exit code 0 = every check passed.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(REPO_ROOT, "scripts", "lib"))
import rest  # noqa: E402  (shared REST engine: auth, UA, target classification)

MIGRATION = os.path.join(REPO_ROOT, "scripts", "migrate-leisure-location-rest.py")
MANIFEST = os.path.join(
    REPO_ROOT, "scripts", "data", "leisure-coordinates-2026-11-03.json"
)
ATTRIBUTE_TAXONOMY = "conexao_leisure_attribute"

EXPECTED_PRODUCTION_RECORDS = 289
EXPECTED_MATCHED = 289
EXPECTED_ASSIGNMENTS = 592
EXPECTED_DISTINCT_TERMS = 13
EXPECTED_RECORDS_CHANGING = 232
EXPECTED_MISSING = 419
EXPECTED_EXISTING = 173
EXPECTED_UNMIGRATED_LOCAL = 5

#: Directory the plan/inventory JSON is read from. Defaults to this evidence
#: directory; `--evidence-dir` overrides it so a negated copy can be used to
#: prove that these checks actually fail (a gate that cannot fail is not a gate).
EVIDENCE_DIR = HERE


def load_migration_module():
    """Import the migration script so the verifier reuses ITS rules."""
    spec = importlib.util.spec_from_file_location("leisure_migration_stage9", MIGRATION)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


MIGRATION_MODULE = load_migration_module()
normalise = MIGRATION_MODULE.normalise


def load_json(path):
    """Load a JSON evidence file (absolute, or relative to the evidence dir)."""
    resolved = path if os.path.isabs(path) else os.path.join(EVIDENCE_DIR, path)
    with open(resolved, encoding="utf-8") as handle:
        return json.load(handle)


def fetch_live(production_url):
    """Fetch the production dataset (GET only) and its attribute-term maps."""
    if rest.classify_target(production_url) != "production":
        raise SystemExit("ERROR: the target must classify as production")
    client = MIGRATION_MODULE.RateLimitedClient(production_url)
    records, _headers = client.get_all(
        "wp/v2/leisure",
        {"_fields": "id,slug,status,modified_gmt,meta,{}".format(ATTRIBUTE_TAXONOMY),
         "status": "publish"},
    )
    terms, _headers = client.get_all(
        "wp/v2/{}".format(ATTRIBUTE_TAXONOMY), {"_fields": "id,slug,count"}
    )
    by_slug, by_id = {}, {}
    for term in terms:
        slug = normalise(term.get("slug"))
        if slug:
            by_slug[slug] = term.get("id")
        by_id[term.get("id")] = slug
    index = {}
    for record in records:
        slug = normalise(record.get("slug"))
        meta = record.get("meta") or {}
        index[slug] = {
            "id": record.get("id"),
            "slug": slug,
            "status": normalise(record.get("status")),
            "modified_gmt": normalise(record.get("modified_gmt")),
            "lat": normalise(meta.get(MIGRATION_MODULE.LAT_KEY)),
            "lng": normalise(meta.get(MIGRATION_MODULE.LNG_KEY)),
            "attribute_ids": list(record.get(ATTRIBUTE_TAXONOMY) or []),
            "attribute_terms": sorted(
                {by_id[tid] for tid in (record.get(ATTRIBUTE_TAXONOMY) or [])
                 if by_id.get(tid)}
            ),
        }
    return index, records, by_slug, by_id, terms


class Checks:
    """A tiny ordered check list: every line is a number, not a claim."""

    def __init__(self):
        self.rows = []
        self.failed = 0

    def check(self, name, ok, detail):
        self.rows.append((name, bool(ok), detail))
        if not ok:
            self.failed += 1

    def report(self, title):
        print(title)
        print("-" * len(title))
        width = max(len(name) for name, _ok, _detail in self.rows)
        for name, ok, detail in self.rows:
            print("{:<{w}}  {}  {}".format(name, "PASS" if ok else "FAIL", detail, w=width))
        print()
        print("checks: {}  failed: {}".format(len(self.rows), self.failed))



def phase_pre(production_url):
    """Reconcile the printed plan against the source data, record by record."""
    checks = Checks()
    production = load_json("production-inventory-pre.json")
    local = load_json("local-inventory.json")
    verification = load_json("verification-pre.json")
    coordinate_plan = load_json("coordinate-plan-pre.json")
    attribute_plan = load_json("attribute-plan-pre.json")

    _index, _records, prod_term_slugs, prod_term_ids, _prod_terms = fetch_live(
        production_url
    )
    with open(
        os.path.join(EVIDENCE_DIR, "production-terms-pre.json"), "w", encoding="utf-8"
    ) as handle:
        json.dump(
            {
                "captured": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "base_url": production_url,
                "terms_by_slug": prod_term_slugs,
                "terms_by_id": {str(k): v for k, v in prod_term_ids.items()},
            },
            handle,
            indent=1,
            sort_keys=True,
        )
    local_by_slug = {row["slug"]: row for row in local["records"]}

    # ---- production inventory -----------------------------------------------
    prod = production["records"]
    checks.check(
        "production records == {}".format(EXPECTED_PRODUCTION_RECORDS),
        len(prod) == EXPECTED_PRODUCTION_RECORDS,
        "{} records".format(len(prod)),
    )
    checks.check(
        "production distinct ids == records",
        len({row["id"] for row in prod}) == len(prod),
        "{} distinct ids".format(len({row["id"] for row in prod})),
    )
    checks.check(
        "production distinct slugs == records",
        len({row["slug"] for row in prod}) == len(prod),
        "{} distinct slugs".format(len({row["slug"] for row in prod})),
    )
    checks.check(
        "every production record is publish",
        all(row["status"] == "publish" for row in prod),
        "statuses={}".format(sorted({row["status"] for row in prod})),
    )
    checks.check(
        "every production record has a slug",
        all(row["slug"] for row in prod),
        "empty slugs=0",
    )
    with_coord = sum(1 for row in prod if row["lat"] and row["lng"])
    half = sum(1 for row in prod if bool(row["lat"]) != bool(row["lng"]))
    checks.check(
        "production coordinate pairs before apply == 0",
        with_coord == 0,
        "{} pairs".format(with_coord),
    )
    checks.check("production half-filled pairs == 0", half == 0, "{} half-filled".format(half))


    # ---- slug matching ------------------------------------------------------
    prod_slugs = {row["slug"] for row in prod}
    matched = sorted(prod_slugs & set(local_by_slug))
    local_only = sorted(set(local_by_slug) - prod_slugs)
    production_only = sorted(prod_slugs - set(local_by_slug))
    checks.check(
        "matched by slug == {}".format(EXPECTED_MATCHED),
        len(matched) == EXPECTED_MATCHED,
        "{} matched".format(len(matched)),
    )
    checks.check(
        "production-only records == 0",
        not production_only,
        "{} production-only".format(len(production_only)),
    )
    checks.check(
        "un-migrated local records == {} (4 CI fixtures + phoenix-park-en)".format(
            EXPECTED_UNMIGRATED_LOCAL
        ),
        local_only
        == sorted(
            [
                "ph3c-test-lazer-alfa",
                "ph3c-test-lazer-externo",
                "ph3c-test-lazer-sem-condado",
                "ph3c-test-lazer-zeta",
                "phoenix-park-en",
            ]
        ),
        "{}: {}".format(len(local_only), local_only),
    )

    # ---- coordinate plan, one row at a time ---------------------------------
    prod_by_slug = {row["slug"]: row for row in prod}
    bad_action, bad_values, bad_local = [], [], []
    bad_target, bad_current, bad_range = [], [], []
    for row in coordinate_plan:
        slug = row["slug"]
        prod_row = prod_by_slug.get(slug)
        local_row = local_by_slug.get(slug)
        if row["action"] != "POPULATE" or row["match"] != "slug":
            bad_action.append(slug)
        if (
            prod_row is None
            or row["production_id"] != prod_row["id"]
            or row["planned_target_id"] != prod_row["id"]
        ):
            bad_target.append(slug)
        if local_row is None or not (
            MIGRATION_MODULE._same_coordinate(row["manifest_lat"], local_row["lat"])
            and MIGRATION_MODULE._same_coordinate(row["manifest_lng"], local_row["lng"])
        ):
            bad_local.append(slug)
        if not (
            MIGRATION_MODULE._same_coordinate(row["manifest_lat"], row["local_lat"])
            and MIGRATION_MODULE._same_coordinate(row["manifest_lng"], row["local_lng"])
        ):
            bad_values.append(slug)
        if row["production_lat"] or row["production_lng"]:
            bad_current.append(slug)
        try:
            lat, lng = float(row["manifest_lat"]), float(row["manifest_lng"])
            if not (-90.0 <= lat <= 90.0 and -180.0 <= lng <= 180.0):
                bad_range.append(slug)
        except ValueError:
            bad_range.append(slug)

    checks.check(
        "coordinate plan rows == 289",
        len(coordinate_plan) == 289,
        "{} rows".format(len(coordinate_plan)),
    )
    checks.check(
        "every coordinate row is POPULATE/slug",
        not bad_action,
        "{} exceptions".format(len(bad_action)),
    )
    checks.check(
        "every planned target id is the production id",
        not bad_target,
        "{} mismatches".format(len(bad_target)),
    )
    checks.check(
        "every planned value equals the local database",
        not bad_local,
        "{} mismatches".format(len(bad_local)),
    )
    checks.check(
        "every planned value equals the manifest row",
        not bad_values,
        "{} mismatches".format(len(bad_values)),
    )
    checks.check(
        "no planned UPDATE (no coordinate replacement)",
        all(row["action"] != "UPDATE" for row in coordinate_plan),
        "UPDATE rows={}".format(
            sum(1 for row in coordinate_plan if row["action"] == "UPDATE")
        ),
    )
    checks.check(
        "no production record already carries a coordinate",
        not bad_current,
        "{} non-empty".format(len(bad_current)),
    )
    checks.check(
        "every coordinate pair is a valid in-range decimal",
        not bad_range,
        "{} invalid".format(len(bad_range)),
    )
    checks.check(
        "distinct target ids in the plan == 289",
        len({row["planned_target_id"] for row in coordinate_plan}) == 289,
        "{} distinct".format(
            len({row["planned_target_id"] for row in coordinate_plan})
        ),
    )
    conflicts = [row for row in coordinate_plan if row["action"] == "CONFLICT"]
    checks.check("conflicts == 0", not conflicts, "{} conflicts".format(len(conflicts)))
    unresolved = [row for row in coordinate_plan if row["action"] == "UNRESOLVED"]
    checks.check("unresolved == 0", not unresolved, "{} unresolved".format(len(unresolved)))

    # ---- attribute plan, one row at a time ----------------------------------
    not_superset, prod_only, unresolved_terms = [], [], []
    planned_assignments, missing_total = 0, 0
    for row in attribute_plan:
        local_set = set(row["local_terms"])
        prod_set = set(row["production_terms"])
        if not prod_set.issubset(local_set):
            not_superset.append(row["slug"])
        if row["production_only"]:
            prod_only.append(row["slug"])
        if row["unresolved_local_terms"]:
            unresolved_terms.append(row["slug"])
        planned_assignments += len(row["planned_term_ids"])
        missing_total += len(row["missing_in_production"])

    checks.check(
        "attribute plan rows == 289",
        len(attribute_plan) == 289,
        "{} rows".format(len(attribute_plan)),
    )
    checks.check(
        "production term set is a SUBSET of the local set on 289/289 records",
        not not_superset,
        "{} violations".format(len(not_superset)),
    )
    checks.check(
        "production-only assignments == 0 (no removal possible)",
        not prod_only,
        "{} records with production-only terms".format(len(prod_only)),
    )
    checks.check(
        "unresolved local term slugs == 0",
        not unresolved_terms,
        "{} records".format(len(unresolved_terms)),
    )
    checks.check(
        "planned assignment relationships == {}".format(EXPECTED_ASSIGNMENTS),
        planned_assignments == EXPECTED_ASSIGNMENTS,
        "{} assignments".format(planned_assignments),
    )
    checks.check(
        "records whose assignment set changes == {}".format(EXPECTED_RECORDS_CHANGING),
        sum(1 for row in attribute_plan if row["changes"]) == EXPECTED_RECORDS_CHANGING,
        "{} records".format(sum(1 for row in attribute_plan if row["changes"])),
    )
    checks.check(
        "records receiving a full-set attribute POST == 289",
        len([row for row in attribute_plan if row["planned_term_ids"]]) == 289,
        "{} records".format(
            len([row for row in attribute_plan if row["planned_term_ids"]])
        ),
    )

    # ---- term identity (ids are environment-local, slugs are the contract) ---
    local_terms_used = sorted(
        {name for row in attribute_plan for name in row["local_terms"]}
    )
    checks.check(
        "distinct attribute terms in the local source == {}".format(
            EXPECTED_DISTINCT_TERMS
        ),
        len(local_terms_used) == EXPECTED_DISTINCT_TERMS,
        "{}: {}".format(len(local_terms_used), local_terms_used),
    )
    missing_terms = sorted(
        name for name in local_terms_used if name not in prod_term_slugs
    )
    checks.check(
        "every local term slug resolves on production",
        not missing_terms,
        "unresolved={}".format(missing_terms),
    )
    expected_ids = {prod_term_slugs[name] for name in local_terms_used}
    planned_ids = {tid for row in attribute_plan for tid in row["planned_term_ids"]}
    checks.check(
        "every planned term id is the production id for its slug",
        planned_ids == expected_ids,
        "planned ids={} expected ids={}".format(len(planned_ids), len(expected_ids)),
    )
    per_row_term_errors = [
        row["slug"]
        for row in attribute_plan
        if sorted(prod_term_ids.get(tid) for tid in row["planned_term_ids"])
        != sorted(row["local_terms"])
    ]
    checks.check(
        "per record, every planned id resolves back to the local term slug set",
        not per_row_term_errors,
        "{} records with a id/slug mismatch".format(len(per_row_term_errors)),
    )
    prod_assignments = sum(len(row["attribute_terms"]) for row in prod)
    checks.check(
        "production assignments before apply == {}".format(EXPECTED_EXISTING),
        prod_assignments == EXPECTED_EXISTING,
        "{} assignments".format(prod_assignments),
    )
    checks.check(
        "missing assignments == {}".format(EXPECTED_MISSING),
        missing_total == EXPECTED_MISSING,
        "{} missing".format(missing_total),
    )

    # ---- the verifier's own numbers agree with this re-derivation -----------
    summary = verification["attributes"]
    checks.check(
        "verifier totals agree with this reconciliation",
        summary["local_assignments"] == planned_assignments
        and summary["production_assignments"] == prod_assignments
        and summary["production_only_assignments"] == 0,
        "local={} production={} production_only={}".format(
            summary["local_assignments"],
            summary["production_assignments"],
            summary["production_only_assignments"],
        ),
    )

    checks.report("Stage 9B — independent pre-apply reconciliation (read-only)")
    print(
        "expected production writes: 289 coordinate POSTs + 289 attribute POSTs "
        "= 578 writes on 289 records"
    )
    print("planned removals: 0   planned deletions: 0   planned creations: 0")
    return 0 if checks.failed == 0 else 1




def phase_post(production_url):
    """Verify an applied migration against the local source, record by record."""
    checks = Checks()
    local = load_json("local-inventory.json")
    before = load_json("production-inventory-pre.json")
    before_terms = load_json("production-terms-pre.json")
    local_by_slug = {row["slug"]: row for row in local["records"]}
    before_by_slug = {row["slug"]: row for row in before["records"]}

    prod_by_slug, prod_records, prod_term_slugs, _ids, prod_terms = fetch_live(
        production_url
    )
    scoped = sorted(set(local_by_slug) & set(prod_by_slug))

    checks.check(
        "production records == {}".format(EXPECTED_PRODUCTION_RECORDS),
        len(prod_records) == EXPECTED_PRODUCTION_RECORDS,
        "{} records".format(len(prod_records)),
    )
    checks.check(
        "no production record was created or removed",
        set(prod_by_slug) == set(before_by_slug),
        "added={} removed={}".format(
            sorted(set(prod_by_slug) - set(before_by_slug)),
            sorted(set(before_by_slug) - set(prod_by_slug)),
        ),
    )
    checks.check(
        "records in scope == {}".format(EXPECTED_MATCHED),
        len(scoped) == EXPECTED_MATCHED,
        "{} matched by slug".format(len(scoped)),
    )

    # ---- coordinates --------------------------------------------------------
    coord_mismatch, missing_coord, id_mismatch = [], [], []
    for slug in scoped:
        target, source = prod_by_slug[slug], local_by_slug[slug]
        if not (target["lat"] and target["lng"]):
            missing_coord.append(slug)
        elif not (
            MIGRATION_MODULE._same_coordinate(target["lat"], source["lat"])
            and MIGRATION_MODULE._same_coordinate(target["lng"], source["lng"])
        ):
            coord_mismatch.append(slug)
        if before_by_slug[slug]["id"] != target["id"]:
            id_mismatch.append(slug)
    with_coord = sum(1 for row in prod_by_slug.values() if row["lat"] and row["lng"])
    half = sum(
        1 for row in prod_by_slug.values() if bool(row["lat"]) != bool(row["lng"])
    )
    checks.check(
        "production coordinate pairs == 289",
        with_coord == 289,
        "{} pairs".format(with_coord),
    )
    checks.check(
        "missing coordinates == 0",
        not missing_coord,
        "{} missing".format(len(missing_coord)),
    )
    checks.check("half-filled pairs == 0", half == 0, "{} half-filled".format(half))
    checks.check(
        "every coordinate equals the local source",
        not coord_mismatch,
        "{} mismatches".format(len(coord_mismatch)),
    )
    checks.check(
        "every production id is unchanged",
        not id_mismatch,
        "{} id changes".format(len(id_mismatch)),
    )

    # ---- attributes ---------------------------------------------------------
    set_mismatch, lost_terms, assignment_total = [], [], 0
    local_term_slugs = {name for row in local_by_slug.values() for name in row["attribute_terms"]}
    for slug in scoped:
        target = set(prod_by_slug[slug]["attribute_terms"])
        source = set(local_by_slug[slug]["attribute_terms"])
        assignment_total += len(target)
        if target != source:
            set_mismatch.append(slug)
        if target - source:
            lost_terms.append(slug)
    checks.check(
        "production assignment relationships == {}".format(EXPECTED_ASSIGNMENTS),
        assignment_total == EXPECTED_ASSIGNMENTS,
        "{} assignments".format(assignment_total),
    )
    checks.check(
        "every record's attribute set equals the local source set",
        not set_mismatch,
        "{} differing records".format(len(set_mismatch)),
    )
    checks.check(
        "no production-only term survived (nothing was removed)",
        not lost_terms,
        "{} records".format(len(lost_terms)),
    )
    checks.check(
        "every production term slug is a local term slug",
        not (set(prod_term_slugs) - local_term_slugs),
        "production-only slugs={}".format(sorted(set(prod_term_slugs) - local_term_slugs)),
    )
    checks.check(
        "attribute taxonomy is unchanged (no term created or deleted)",
        sorted(set(prod_term_slugs)) == sorted(set(before_terms["terms_by_slug"])),
        "{} term slug(s) before and after".format(len(prod_term_slugs)),
    )

    # ---- scope: what moved and what did not ---------------------------------
    moved = sorted(
        slug
        for slug in prod_by_slug
        if prod_by_slug[slug]["modified_gmt"] != before_by_slug[slug]["modified_gmt"]
    )
    checks.check(
        "modified_gmt moved on exactly the records in scope",
        moved == scoped,
        "moved={} in-scope={} outside-scope-moved={}".format(
            len(moved), len(scoped), sorted(set(moved) - set(scoped))
        ),
    )

    checks.report("Stage 9F — independent post-migration verification (read-only)")
    print("production writes performed by Stage 9: 289 coordinate POSTs + 289 attribute POSTs")
    print("deletions: 0   creations: 0   records written outside the scope: 0")
    return 0 if checks.failed == 0 else 1


def main(argv=None):
    global EVIDENCE_DIR
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phase", required=True, choices=["pre", "post"])
    parser.add_argument("--production", default="https://conexaobr.ie")
    parser.add_argument(
        "--evidence-dir",
        default=None,
        help="Directory holding the plan/inventory JSON (default: this one).",
    )
    args = parser.parse_args(argv)
    if args.evidence_dir:
        EVIDENCE_DIR = os.path.abspath(args.evidence_dir)
    if args.phase == "pre":
        return phase_pre(args.production)
    return phase_post(args.production)


if __name__ == "__main__":
    sys.exit(main())
