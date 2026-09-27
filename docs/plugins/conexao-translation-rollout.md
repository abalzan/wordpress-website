# conexao-translation-rollout

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-translation-rollout/conexao-translation-rollout.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

## Purpose

The shared translation-rollout engine (Stage H). It implements the
content-change contract once, for every one-shot EN translation stage, so a new
rollout no longer needs its own `apply.php` + `audit.php` + admin class.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-translation-rollout/` |
| Engine | `includes/class-conexao-translation-rollout-engine.php` |
| Admin screen | Tools → **Translation Rollouts** (Preview → Apply → Remove) |
| Frontend effect | **none** — orchestration only; never writes on bootstrap or activation |
| Safe to deactivate | yes, when no rollout is running |
| Runtime dependency | none (a stage adapter needs Polylang at run time) |
| Tests | `wp-content/plugins/conexao-translation-rollout/tests/` (3 suites, 46 assertions) |

Owner: project maintainer. Introduced by Stage H
(`docs/reports/2026-09-26-stage-h-shared-rollout-engine.md`).

## Ownership boundary (the whole point of this plugin)

| Concern | Owner |
|---|---|
| inventory, dry-run plan, snapshot orchestration, apply traversal, PT-drift guard, verify counters, numeric gate, result formatting, admin capability/nonce flow, rollback traversal | **engine** |
| authored translated copy, stable keys, stage identity, source/target language, field mapping, eligibility, landing-page verification, remove safety declaration | **stage** |

The engine contains **no** translated titles or bodies, no stage record lists,
no inline translation payloads and no production identifiers. A stage that
needs orchestration that the engine does not provide must extend the engine —
it must not re-implement the lifecycle.

## Six-step lifecycle

| Step | Engine entry point | Writes | Contract |
|---|---|---|---|
| 1. Inventory | `run()` → `collect_states()` | none | read-only state per manifest record: stable key, PT record, current EN relation, eligibility, conflicts |
| 2. Manifest | `validate_manifest()` | none | fails closed on a missing key, a language mismatch, a malformed row, a missing required field or a **duplicate stable identifier** |
| 3. Dry-run plan | `build_plan()` | none | deterministic `create` / `update` / `skip` / `conflicts`, each with a `reason`; identical input state ⇒ identical plan |
| 4. Snapshot | `capture_snapshots()` | none | every affected PT record captured before any write, using the stage's field list |
| 5. Apply | `apply_plan()` / `apply_removals()` | explicit only | idempotent: an already-linked, verified record is skipped, never re-created; every write reports its match strategy (`stable-id`, `slug`, `title`) with per-strategy counts |
| 6. Verify + gate | `collect_verify()` + `calculate_gate()` | none | numeric counters and a machine-readable `gate.json` |

PT-drift protection is `assert_no_pt_drift()`: every snapshotted PT record is
re-compared after the run and any changed field is reported as
`PT SOURCE CHANGED`. The only tolerated difference is a Polylang language
backfill (`'' → 'pt'`) on a record that had no language at all.

Dry-run performs **zero** writes by construction: the apply path is not entered
when `dry_run => true`.

## Stage configuration contract

A stage registers one array. Required keys:

| Key | Meaning |
|---|---|
| `stage` | stable stage identifier (`[a-z0-9-]`) |
| `source_post_type` / `source_lang` / `target_lang` | identity and languages |
| `manifest_callback` | returns `array( 'source_lang', 'target_lang', 'records' )` keyed by portable stable key |
| `snapshot_callback` | the PT fields the engine snapshots and byte-compares |
| `build_en_args_callback` | builds the EN create arguments |
| `copy_fields_callback` | copies stage-specific fields/meta to the EN record |
| `run_callback` | how the admin screen starts a run for this stage |

Optional keys: `verify_landing_callback` (an extra gate input, e.g. a landing
page pair that an earlier stage owns), `extra_gate_callback` (additional
failure count), `allowlist` (documented gate exclusions), `allow_remove`
(**only** `true` when removal is proven safe; otherwise the engine refuses
`remove` with a `WP_Error`).

`validate_config()` fails closed on a missing/blank required key, a malformed
stage identifier, a non-callable required callback or a non-array allowlist.

## Data-manifest contract

```php
array(
    'source_lang' => 'pt',
    'target_lang' => 'en',
    'records'     => array(
        '<portable stable key>' => array(
            'en_slug'   => 'required',
            'en_title'  => '...',
            // stage-specific translated fields
        ),
    ),
)
```

- **Portable identity is the authored stable key** (for the migrated Job stage:
  the PT slug). Local WordPress post IDs are never the cross-environment
  identity; they are only reported in result rows.
- Slug and title matching are **reported fallbacks** with explicit counts
  (`match.slug`, `match.title`). A stage that needs one must report it; there
  is no silent fallback.
- The manifest lives in the stage plugin (`includes/translation-map.php` or a
  versioned JSON data file), is version-controlled and auditable, and is never
  embedded in the engine.

## Rollback / remove contract

- `mode => 'remove'` is **refused** (`WP_Error`, zero writes) unless the stage
  declares `allow_remove => true`.
- When declared safe, removal deletes only the EN record each manifest record
  owns, then re-runs the same PT-drift comparison; the PT originals are never
  written.
- The shared admin action enforces the same `manage_options` + nonce gate as
  Apply, and the Remove button previews first (Preview is a separate action).

The migrated **Job** stage declares `allow_remove => false`: the Stage 6
rollout's EN jobs carry a verbatim `_job_*` meta layer and shared media whose
pre-removal state is not reconstructible from the manifest, so a destructive
remove is not claimed. Recovery for that stage is the documented state in its
plugin doc, not an engine `remove`.

## Admin workflow

Tools → **Translation Rollouts**:

1. The stage selector lists only stages registered with the engine.
2. **Preview (dry run)** — zero writes, prints the plan categories and the gate.
3. **Apply** — writes, then re-verifies PT-unchanged and recalculates the gate.
4. **Remove (rollback)** — only for stages that declare `allow_remove`.

Every write path checks `manage_options` **and** `check_admin_referer()` before
touching content. The page load and every GET perform no writes.

## Adding the next rollout

1. **Add the versioned data manifest** — authored translations keyed by a
   portable stable identity. Never a post ID.
2. **Add a small stage config** — `includes/stage-config.php` with the keys
   above. No lifecycle code.
3. **Register the stage** — `Conexao_Translation_Rollout_Engine::register_stage()`
   on `plugins_loaded`. Registration is in-memory and writes nothing.
4. **Run inventory** — confirm the engine sees the records you expect.
5. **Run the dry-run** — review `create` / `update` / `skip` / `conflicts`.
6. **Review the snapshot** — the returned `snapshot` payload is the PT state
   the run will compare against.
7. **Apply only in the intended environment.**
8. **Verify PT-unchanged** — `pt_drift` must be 0.
9. **Verify the numeric gate** — `missing_en` must be 0 (or a documented
   allowlist).
10. **Record evidence** under `docs/evidence/<date>-<stage>/`.
11. **Roll back / remove** when the stage declares it safe, otherwise follow
    the stage's documented recovery.

No `apply.php`, no `audit.php`, no admin class is copied.

## Additive extension: `stage_conflict`

`build_plan()` and `collect_states()` accept one optional state key,
`stage_conflict` (default `''`). When a stage's `find_en_for_pt()` returns a
non-empty value for it, the row is planned as a **hard conflict** with the
stage's own reason string, instead of falling through to the engine's generic
`EN record exists but the pair link is broken` text.

It exists for stages whose eligibility rule is richer than
exists / linked / slug-collision — in practice the two authored
description-field stages, [`en-leisure-description`](conexao-en-translation.md)
and [`en-course-provider-description`](conexao-en-translation.md), which must
refuse to write an English description whose Portuguese source has changed since
the translation was authored. Without it the stage would have had to either reuse a
misleading reason string or re-implement plan traversal, which the ownership
boundary above forbids.

The key is **optional and additive**: the record-creating stages do not set it, so
their plans, counters and gates are byte-identical to before.

## Hard warnings

- **Never run a rollout command against production casually.** Production is
  WordPress.com and has **no WP-CLI**; production changes go through the admin
  screen (Preview → Apply) with a real operator.
- **Dry-run is mandatory before apply.** A dry-run performs zero writes.
- **Snapshots precede writes.** The PT snapshot is captured before the first
  write and compared after the last one.
- **Gates must be numeric.** `eligible public PT <type> missing EN = 0`, plus
  zero conflicts and zero PT drift. A textual "looks good" gate is not a gate.
- **PT remains canonical.** EN is a *linked translation* of the same identity,
  never a second identity and never a renamed PT record.
- **Local post IDs are not portable identifiers.** Use the authored stable key
  (or a repository-native UUID / source key).
- **Rollback/remove must be defined** per stage, and must not be claimed unless
  it is implemented and tested.

## Verification

```bash
# engine contract, idempotence/PT-drift/failure proofs, future-stage smoke proof
./scripts/run-tests.sh --only conexao-translation-rollout
./scripts/run-tests.sh --only conexao-job-translation

php scripts/generate-registry-docs.php --check
```

_Last verified: 2026-09-27 by the EN Leisure description rollout — additive `stage_conflict` state key_
