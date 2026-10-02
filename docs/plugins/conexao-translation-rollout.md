# conexao-translation-rollout

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | platform |
| **Production** | yes |
| **Build** | yes |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.2.0 (authoritative source: `wp-content/plugins/conexao-translation-rollout/conexao-translation-rollout.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Production platform plugin.** Part of the production steady state.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

## Purpose

The shared translation-rollout engine (Stage H). It implements the
content-change contract once, for every one-shot EN translation stage, so a new
rollout no longer needs its own `apply.php` + `audit.php` + admin class.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-translation-rollout/` |
| Engine | `includes/class-conexao-translation-rollout-engine.php` |
| Admin screen | Tools → **Translation Rollouts (deprecated)** — **closed in v1.2.0**; it starts no run |
| Production entry point | **none.** The engine owns the lifecycle but is reachable only through `conexao-translation-automation` |
| Frontend effect | **none** — orchestration only; never writes on bootstrap or activation |
| Safe to deactivate | yes, when no rollout is running |
| Runtime dependency | none (a stage adapter needs Polylang at run time) |
| Tests | `wp-content/plugins/conexao-translation-rollout/tests/` (4 suites) |

Owner: project maintainer. Introduced by Stage H
(`docs/reports/2026-09-26-stage-h-shared-rollout-engine.md`).

## The admin endpoint is CLOSED (Stage 8, v1.2.0)

`admin_post_conexao_translation_rollout_run` used to be a second,
apply-capable production mutation entry point:

```
admin-post.php -> handle_run()
  -> manage_options + nonce
  -> $_POST['mode'] === 'apply'          (caller-controlled)
  -> call_user_func( $config['run_callback'], [ 'dry_run' => false ] )
  -> Conexao_Translation_Rollout_Engine::run( ... )
  -> apply_plan()                        (real writes)
```

It bypassed the stage allowlist, the F7 PASS gate, the digest-bound approval,
the lock, the audit trail, the environment guard, change detection, provider
validation and human review. A capability and a nonce were the whole story.

It is now a **hard-fail deprecation stub**: `handle_run()` refuses every request
with **HTTP 410** and names its replacement. The hook stays registered on
purpose — an old bookmarked URL then gets a named refusal instead of
WordPress's indistinguishable "0 handlers" response. The handler contains no
`call_user_func`, no `run_callback`, no `Engine::` reference and reads no
superglobal, so no request value can become a mode.

| | |
|---|---|
| Original action | `conexao_translation_rollout_run` |
| Original purpose | Preview / Apply / Remove one authored EN stage (Stage H) |
| Disposition | **Removed.** The apply capability is gone, not deprecated-with-a-flag |
| Replacement | `conexao-translation-automation` → `admin_post_conexao_translation_automation_proof` (Tools → Translation Automation) |
| Compatibility | none lost: `conexao-en-translation` is the only stage-registering plugin and is `production: false`, so in the production steady state no stage is registered and the old endpoint could only ever have reached `unknown stage` |
| Enforced by | `tests/scripts/verify-stage8-control-plane.py` (control-plane contract) and `tests/scripts/verify-stage7-commissioning.py` (keeps the action name watched) |

**The engine itself did not change.**
`class-conexao-translation-rollout-engine.php` is byte-identical to its
historical digest `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`,
which the Stage 8 gate re-asserts. Stage 8 closed an *entry point*; the mutation
authority is untouched.

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
`remove` with a `WP_Error`), and the translated-taxonomy pair
`taxonomy_callback` / `taxonomy_gate_callback` (see below).

`validate_config()` fails closed on a missing/blank required key, a malformed
stage identifier, a non-callable required callback or a non-array allowlist.

## Optional capability: a translated taxonomy

A stage whose records are filed under a **translated** taxonomy needs the
counterpart terms to exist before the records can be filed under them. The engine
owned no term-creation step, so a stage had to re-implement one — the exact thing
this class exists to prevent. Two optional keys close that gap:

| Key | Signature | Meaning |
|---|---|---|
| `taxonomy_callback` | `function ( bool $dry_run, string $mode ): array` | Performs the stage's taxonomy work and returns counters. Invoked by the **engine**, **before** the record plan, and dry-run aware, so a dry-run reports the same plan and still writes nothing. The counters land in `summary['taxonomy']`. |
| `taxonomy_gate_callback` | `function (): int` | Returns a **failure count**, folded into `extra_failures` so it fails the same numeric PASS\|FAIL gate. |

Both are **additive and inert** for every stage that does not declare them: the
keys are optional, `run_taxonomy()` returns `array()`, `summary['taxonomy']` is
`array()`, and such a stage's plan, counters and gate are byte-identical to
before. `test-translation-rollout-engine.php` asserts exactly that, alongside the
dry-run/no-write and fail-closed properties.

The engine owns the **ordering and the verdict**; the stage owns only the term
data and the Polylang calls. No second engine, no second lifecycle, and the
translated/shared policy stays a stage declaration: a stage that owns
`conexao_category` must never list `conexao_county` / `conexao_town`, which stay
shared one-term taxonomies.

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

php scripts/generate-registry-docs.php --check
```

_Last verified: 2026-09-27 by the EN Leisure description rollout — additive `stage_conflict` state key_

_Last verified: 2026-09-28 by the B1 English Guides content rollout — the additive optional taxonomy keys (`taxonomy_callback` / `taxonomy_gate_callback`)_
