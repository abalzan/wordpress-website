# Content change (the six-step contract)

## Purpose

Execute a content-writing change safely: records, terms, navigation menus,
meta, media or a migration — anything that writes data. This skill turns the
engineering standard's six-step content-change contract into the operational
sequence: inventory → manifest → dry-run plan → snapshot → idempotent apply →
verify + numeric gate.

## When to use

- A task writes or bulk-updates content and it is **not** English coverage
  (`wp-translation-rollout`) and **not** a schema change
  (`wp-add-content-type`): menu changes, term repairs, metadata corrections,
  image refreshes, importer pipelines, admin-screen-driven bulk edits.
- Running or extending an existing content script (import, repair, seed,
  reorder) listed in `scripts/README.md`.

## When not to use

- English coverage for a content type — `wp-translation-rollout` (it applies
  this same contract with the EN-specific strategy and PT-immutability gate).
- A new post type, taxonomy or meta field — `wp-add-content-type`.
- A content-**model** redesign — plan it; the content-change contract does
  not bless a redesign.
- A release or deployment — `wp-release-deploy` / `wp-production-operations`.

## Required reading

- `AGENTS.md` — the non-negotiable safety rules.
- `docs/engineering-standard.md` §0 principles 3–7 (dry-run, portable
  identifiers, snapshots, numeric gates) and **§5.2** — the six-step contract
  itself, which this skill operationalises.
- `scripts/README.md` — the script contract: `--help`, `--dry-run`,
  `--apply`, `--confirm-production`, `--json`, and the safety levels.
- `docs/content-model.md` — the types, taxonomies and identity meta involved.
- The owning plugin's doc under `docs/plugins/` when the change extends its
  importer, seeder or repair script.

## Authoritative sources

- `docs/engineering-standard.md` §5.2 owns the six-step contract and its
  MUSTs; this skill adds no rule of its own.
- `scripts/README.md` owns the script flags and safety levels; the shared
  libraries (`scripts/lib/bootstrap.php`, `scripts/lib/rest.py`,
  `scripts/lib/plan.py`) own the run header, target classification and the
  production write guard.
- The stage reports under `docs/reports/` are **history**, not instructions:
  match an earlier stage's *shape*, never copy its numbers or assume its
  data still exists.

## Preconditions

- A written plan (from `docs/templates/plan.md`) naming the affected records
  and the numeric gate — the contract is not optional for content writes.
- A local target that can be exercised end to end (the local Compose site, or
  a REST-reachable staging target) before any authorised production run.
- For a production write: explicit task authorisation, an explicit target and
  `--confirm-production` (see Guardrails).

## Steps

1. **Inventory.** Read-only, machine-readable capture of the affected
   dataset, keyed by a **stable identifier** (never a local post ID). Print
   it; it becomes the "before" half of the evidence.
2. **Manifest.** Author the intended end-state in a **versioned** data file
   (committed), one entry per record, keyed by the same stable identifier
   plus slug. Fail closed on a missing key, a malformed row or a duplicate
   identifier.
3. **Dry-run plan.** Run with `--dry-run` (the default): it must print a
   machine-readable plan — `create[]`, `update[]`, `skip[]` **with a
   reason**, `conflicts[]` — and perform **zero writes**. Identical input
   must produce an identical plan (determinism check).
4. **Snapshot.** Capture the affected records (fields + identifiers, or at
   least counts + IDs) **before** any write. This is the rollback substrate
   and the untouched-records comparison, wherever records are out of scope.
5. **Apply, idempotently.** Run with `--apply`. Every write reports its match
   strategy (`stable-id` / `slug` / `title`) with per-strategy counts; slug or
   title matches are a **reported fallback**, never the primary key. Re-run
   the apply: it must report zero changes (idempotence proof).
6. **Verify + numeric gate.** Express the outcome as a number that must reach
   `0` (or an explicit, documented allowlist), store it as `gate.json` under
   `docs/evidence/<date>-<stage>/`, and re-verify the unchanged records you
   promised not to touch (title, slug, status, date, meta).

## Guardrails

- **Dry-run first is not optional**; `--apply` is the only mode that writes.
- Never match records by local WordPress post/attachment IDs — stable
  identifiers only (authored keys, `_leisure_uuid` / `_leisure_export_uuid`,
  event `source + source_id + export UUID`).
- No production write without explicit task authorisation, an explicit target
  and `--confirm-production`; production is WordPress.com — REST scripts
  write only with application-password credentials from the environment
  (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`), never literals.
- No localhost URLs written into content; no hotlinked media — images are
  local Media Library attachments with attribution/license meta preserved.
- A GET or page load must never write; writes live in explicit apply paths.
- Reuse the shared libraries — never copy a script's lifecycle into a new
  bespoke file; extend the owning script or engine instead.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --scripts       # the script-contract gate (dry-run default, guards)
./scripts/run-tests.sh --only <owner>  # the owning component's suites
python3 scripts/verify-permanent-gates.py
```

- The dry-run output in evidence shows zero writes performed, before the
  apply.
- The second apply reports zero changes (idempotence).
- The numeric gate is `0` or the documented allowlist, recorded as evidence.
- The untouched-records assertion (fields compared) passed, or stated as
  not applicable with the reason.

## Failure handling

- **A conflict appears in the dry run:** stop; resolve the identity conflict
  in the manifest. Never "apply anyway and fix up after".
- **The apply is not idempotent:** the script is defective — fix it before
  proceeding; a second run must be a no-op.
- **Records you promised not to touch changed:** restore from the snapshot,
  treat it as a defect, and re-run the whole verification.
- **The gate will not reach 0:** either the data is still wrong (fix the
  data) or the gate is mis-framed (re-frame it as a number; never weaken it).
- **A production write is blocked** (no authorisation, missing
  `--confirm-production`): that block is correct — record it and ask.

## Evidence and reporting

- Under `docs/evidence/<date>-<stage>/`: the inventory, the dry-run plan, the
  pre-apply snapshot, the apply output with per-strategy counts, the
  idempotence re-run, and `gate.json`.
- The report (from `docs/templates/report.md`) states the counts, the gate
  number, the match strategies used, and what was **not** done.

## Definition of done

- [ ] A plan existed before any write, naming the gate.
- [ ] Inventory, manifest, dry-run, snapshot, apply and gate evidence are
      stored under `docs/evidence/<date>-<stage>/`.
- [ ] The dry run performed zero writes and was deterministic.
- [ ] Apply was idempotent; match strategies and counts are reported.
- [ ] The numeric gate is `0` or an explicit documented allowlist.
- [ ] Records promised untouched are proven untouched (fields listed).
- [ ] No local-ID matching, no credential literal, no localhost URL in
      content, no hotlinked media.
- [ ] No production write occurred, or it was explicitly authorised and
      separately verified.
