# Change content safely

## Purpose

The procedure for **any write of content, meta or terms that is not an English
rollout** — an importer, a migration, a seed, a repair, a backfill, a bulk edit.
This is the operational reading of the content-change contract. The *policy* is
`docs/engineering-standard.md` §5.2; the *data* being changed is
`docs/content-model.md`.

## When to use

- An importer or migration creates or updates records, attachments or terms.
- A repair or backfill fixes data that drifted.
- A seed populates local fixtures or a demo dataset.
- A bulk edit or admin action mutates existing content.
- Any script in `scripts/` that writes, or any admin screen that writes.

Do **not** use this for English coverage — that is `wp-translation-rollout`,
which has additional invariants (PT immutability, B1/B2, completeness gate).

## When not to use

- Adding a post type, taxonomy or meta *schema* — that is `wp-add-content-type`.
- Changing a route, filter or redirect — `docs/routing.md` plus
  `wp-http-acceptance-matrix`.
- Writing a new admin screen — `wp-add-admin-screen`.
- Releasing or deploying — `wp-release-deploy` / `wp-production-operations`.
- A read-only inventory or verification.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §5.2 (the six-step content-change contract),
  §5.1 (declaring or changing content), §5.3 (import/export payloads) and §0
  principles 3, 4, 5, 6, 7.
- `docs/content-model.md` — the types, taxonomies, meta and identity fields you
  are writing.
- `scripts/lib/bootstrap.php` — the single WordPress loader for PHP scripts.
- `scripts/lib/rest.py` — the shared REST client (auth, retries, pagination, base
  URL) for Python scripts.
- `scripts/lib/plan.py` — the shared machine-readable plan shape.
- `scripts/README.md` — the entry for the script you are changing, including its
  safety level and default mode.

## Authoritative sources

| Fact | Read it from |
|---|---|
| The six-step contract and its MUSTs | `docs/engineering-standard.md` §5.2 |
| Fields, taxonomies, meta, identity meta | `docs/content-model.md` |
| Portable identifier strategy per dataset | `docs/content-model.md` §Import/Export Identifiers |
| Script flags, safety level, default mode | `scripts/README.md` |
| Rollout lifecycle for a translation stage | `docs/plugins/conexao-translation-rollout.md` |

## Preconditions

- A written plan exists (`docs/templates/plan.md`) with the content/data impact
  section answered, including the portable-identifier strategy.

## Steps

1. **Inventory (read-only).** Produce a machine-readable inventory of what exists
   today, keyed by a **portable identifier**, with counts. This is the baseline
   you diff against, and it must perform zero writes.
2. **Author a versioned manifest or plan.** One entry per target record, keyed by
   a stable identifier plus slug/title as a *reported* fallback. Never key on a
   local post/attachment ID — those are environment-local and are not identity.
   The plan is machine-readable and prints `create` / `update` / `skip` (with a
   reason) / `conflicts`.
3. **Dry-run (plan only, zero writes).** Run the write-capable tool in its default
   dry-run mode. Identical input MUST produce an identical plan. Capture the
   output as evidence — this is the artefact that proves what *would* change.
4. **Snapshot before any write.** Capture the affected dataset: at minimum the
   records with their field values and identifiers, stored under
   `docs/evidence/<date>-<stage>/`. Without a snapshot there is no rollback, and
   a change that cannot be reversed must not be applied.
5. **Apply (idempotent).** Perform the write. Requirements that are not optional:
   - it must be **idempotent** — a second run reports zero changes;
   - every write reports its **match strategy** (`stable-id` / `slug` / `title`)
     with per-strategy counts;
   - it writes only inside the declared scope and says so in `--help`.
6. **Verify + numeric gate.** Re-run the inventory and compare. State a gate as a
   **number** (e.g. `records missing field X = 0`) or an explicit allowlist; a
   gate that cannot be expressed as a number must be re-framed until it can.
   Confirm the PT-unchanged assertion where PT content is in scope.
7. **Prove idempotence and prove the gate.** Re-apply and show zero changes; show
   the gate is `0`. Both are evidence, not assertions in prose.
8. **Record the rollback.** State the reversal action in the report, and state
   what rollback does **not** cover (for example, a media deletion is not reversed
   by re-running the importer).
9. **Update the documentation** for anything the change altered permanently: a
   new meta field, a changed identifier strategy, a new importer contract.

## Guardrails

- **Dry run is not optional, and dry run is the default.** A write-capable tool
  plans by default and writes only on an explicit apply flag. A tool that writes
  by default is a defect.
- **No production write from this repository** unless the task explicitly
  authorises it. Production is WordPress.com: no SSH, no WP-CLI, no filesystem,
  no database. A production write happens through an admin screen operated by a
  maintainer, with `--confirm-production`-style explicit intent and a printed
  resolved target.
- **Never use a local post/attachment ID as cross-environment identity.** Use the
  dataset's stable key (`_leisure_uuid`, event `source` + `source_id` + export
  UUID, or an authored stable key). Slug/title matches are a *printed fallback*
  with counts.
- **PT content is immutable** in an English change, and generally must not be
  mutated as a side effect. A change that must alter PT content is a different
  change with its own plan.
- **No secrets, no localhost URLs in production data, no hotlinked media.**
  Images are local Media Library attachments with attribution/licence meta
  preserved; Wikimedia metadata is attribution-only.
- **A write-capable script is idempotent or refuses to re-run** without an
  explicit force flag.
- **Never write outside the declared scope**, and never on a page load or GET.
- **Never copy a shared engine** — a second importer that reimplements the rollout
  lifecycle or the plan shape is a defect, not a variation.

## Verification

```bash
# the tool you changed, in its documented order
<tool> --dry-run                 # plan only; capture the output
<tool> --apply                   # idempotent; second run must report zero changes
python3 scripts/verify-permanent-gates.py
./scripts/run-tests.sh
./scripts/lint.sh
```

- The dry-run output exists as evidence and shows **zero writes performed**.
- The apply is idempotent: a second run reports zero changes.
- The numeric gate is `0`, or the allowlist is explicit and justified.
- The snapshot exists and the rollback action is written down.
- The PT-unchanged assertion passed, if PT content was in scope.
- No production write occurred, or it was explicitly authorised and performed
  through the documented admin path.

## Failure handling

- *The dry-run plan is not reproducible for identical input* — stop. A
  non-deterministic plan means unordered iteration or a time/random dependency;
  the write is not safe to apply.
- *The apply partially fails.* Do not re-run blindly. Re-inventory, diff against
  the snapshot, and determine exactly which records landed. Only then decide
  between re-applying and restoring from the snapshot.
- *The gate does not reach 0.* Investigate the residuals and record them. Never
  allowlist a residual to make the gate pass; an allowlist is a stated decision
  with a reason, not a silencer.
- *A match falls back to slug or title.* Check the reported per-strategy counts. A
  high fallback rate means the portable identifier is missing from the source data
  and the mapping is not trustworthy across environments.
- *The snapshot is missing or was taken after the write.* Treat the change as
  unrecoverable by script and escalate; state it plainly in the report.

## Evidence and reporting

Under `docs/evidence/<date>-<stage>/`: the inventory, the dry-run plan, the
snapshot, the apply result with per-strategy counts, the idempotence proof, the
gate, and the before/after diff. The report (from `docs/templates/report.md`)
records: records created/updated/deleted, the portable-identifier strategy, the
match-strategy counts, the gate value, the PT-integrity result, the rollback and
what it does not cover, and an explicit production-actions statement.

## Definition of done

- [ ] A read-only inventory with counts exists as the baseline.
- [ ] The plan/manifest is versioned and keyed by a portable identifier.
- [ ] The dry run performed **zero writes** and its output is stored as evidence.
- [ ] A pre-write snapshot exists; the rollback action is documented.
- [ ] The apply is idempotent and a second run reports zero changes.
- [ ] Per-strategy match counts were reported.
- [ ] A numeric gate reached `0`, or the allowlist is explicit and justified.
- [ ] PT content is unchanged, and the assertion is recorded.
- [ ] Nothing was written outside the declared scope; no write on GET/page load.
- [ ] No production write occurred without explicit authorisation.
- [ ] Permanent documentation reflects the change; no root-level report added.

- Never touch the Flutter/mobile repository from this task.

- The dataset can be **inventoried** read-only before anything is written.
- The change is scoped to a declared set of records. "All events" is not a scope;
  a filter with printed counts is.
- A rollback has been thought through **before** the write, not after.
