# Report — Stage 2: engine promotion, lock and apply safety

> **This stage changed no production state.**
> `Production writes: 0`. Production was not contacted at all. No plugin was
> uploaded, installed, activated or scheduled. No production route, cron job or
> option was created. No PT or EN content, taxonomy term, menu or Polylang
> relationship was changed. No translation was performed. `conexao-content` was
> not deactivated or reactivated. The Flutter/mobile repository was not
> accessed.
>
> **Production installation of the promoted artifacts REQUIRES OPERATOR ACTION**
> and was not performed (§7). No successful production installation is
> simulated or claimed anywhere in this document.

| | |
|---|---|
| **Stage / task name** | Stage 2 — Engine promotion (B1), site-wide lock (B3/C1–C6), apply safety (F7) |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `cc4a2295da4a8bae30127a2010e27d8353efbef7` |
| **Predecessor** | Stage 1 — PASS WITH CONDITIONS |
| **Status** | **PASS WITH CONDITIONS** — see §12 |

---

## 1. Stage 2 status

**PASS WITH CONDITIONS.**

The shared engine is correctly promoted, the permanent automation plugin is
classified as a production platform plugin, the site-wide lock is implemented
and fail-closed, and the "no apply without a preceding PASS dry-run" boundary is
established and tested with the approval cryptographically bound to the exact
plan, run and environment. The shared engine is **byte-identical** before and
after. Every new test passes.

The single condition is **production installation has not been performed and
was not authorised**. That is not a defect: §7 specifies the operator sequence,
and the repository tooling deliberately cannot deploy. Every claim below is
labelled with what actually verified it.

## 2. Promotion result

`plugins.json` changes — exactly two entries, no unrelated plugin touched:

| Plugin | Before | After |
|---|---|---|
| `conexao-translation-rollout` | `tooling`, `production: false`, `build: false` | **`platform`, `production: true`, `build: true`** |
| `conexao-translation-automation` | `tooling`, `production: false`, `build: false` | **`platform`, `production: true`, `build: true`** |

Release metadata followed mechanically from the registry through the supported
generator (`php scripts/generate-registry-docs.php --write`): the release build
list in `scripts/build-plugins-zip.sh`, the production activation order, and 10
generated documentation regions (24 regions total).

**`validate_lifecycle_rules()` was NOT weakened.** The promotion was accepted by
the existing rules as written, because `platform` + `production: true` +
`build: true` is already legal. The gate re-asserts all five lifecycle
invariants independently, so a future edit that tries to buy a promotion by
weakening a rule fails.

The dependency model was respected: the engine precedes its dependent in load
order, the registry dependency matches the plugin's real
`Requires Plugins: conexao-translation-rollout` header, and the dependency
graph is acyclic and correctly ordered.

## 3. Engine integrity

| | SHA-256 |
|---|---|
| **Before Stage 2** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **After Stage 2** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **Result** | **`identical`** |

This matches the Stage 1 baseline exactly. The promotion was a pure metadata
change; not one byte of engine source moved. The admin class
(`class-conexao-translation-rollout-admin.php`, `da977b13…`) and the plugin
entry point (`33fe1a2e…`) are likewise byte-identical.

The digest is asserted in **three independent places**: the boundary suite, the
promotion suite, and the script gate. A regression fails the build.

## 4. Lock design

**Primitive: a conditional `INSERT` through `$wpdb`, relying on the UNIQUE key
on `wp_options.option_name`.** The database, not PHP, decides the winner.

Two plausible alternatives were investigated and **rejected on evidence**:

| Candidate | Why rejected |
|---|---|
| `add_option()` | Check-then-insert is a TOCTOU race, **and** its INSERT is `ON DUPLICATE KEY UPDATE` — so a losing racer would *silently overwrite* the winner's lock and get a truthy result. It would not merely fail to lock; it would destroy a live lock. |
| `wp_cache_add()` | Only atomic and durable with a **persistent** object cache. With the default non-persistent cache the key vanishes when the request ends, so the lock would evaporate mid-run. This repository does not assume persistent object-cache semantics without evidence. |

| Concern | Rule |
|---|---|
| Scope | **Site-wide**, one option, not per-stage — two stages would otherwise race on the same PT records and EN slugs |
| Ownership | Unique `run_id` on every acquisition, propagated through orchestration, the structured result, the audit record and lock error strings |
| Atomicity | `INSERT IGNORE` + the UNIQUE key; no read-then-write window |
| Stale policy | Reclaimable only when `now - acquired_at > ttl`; TTL default 900s, hard max 86400s |
| Malformed state | **Never** reclaimed, overwritten or deleted — fails closed for a human to inspect |
| Release | Only the owner, enforced by a value-scoped `DELETE`, not by a read that could go stale |
| Concurrency result | A held lock yields `LOCKED` + **no mutation**. Nothing is queued; a second run is never allowed to overwrite or release another's lock |

Every mutation is a **compare-and-swap on the exact stored bytes**, so a racing
operation can never act on a row it did not create. Reads bypass the object
cache deliberately: a lock decision must never be made from a cached value
another process has already replaced.

**Concurrency is proven deterministically, not by racing two processes on a
timer.** The tests assert the CAS scoping property directly against the real
options table: a swap scoped to superseded bytes affects zero rows and cannot
overwrite the winner, and a duplicate `INSERT` affects zero rows — the exact
signal `acquire()` treats as "held".

## 5. Apply safety

The prerequisite chain, in this exact order:

```
lock → dry-run (forced) → environment guard → F7 PASS → approval binding
     → snapshot persisted → APPLY → verify → release lock
```

No step is reorderable. Every link fails closed, and the lock is released in a
`finally` block on **every** path including early returns.

`dry-run gate != PASS → NO APPLY`. `FAIL`, `ERROR`, invalid, missing, stale,
mismatched and unavailable are all "not PASS". A PASS is never inferred from a
plan merely existing, and never inherited from an unrelated run.

**The approval is a digest bound to the exact work — never a boolean.** It covers
the manifest digest, plan digest, snapshot digest, stage, run id, environment and
configuration identity (plugin + engine + stage versions). A dry run publishes
the approval for its own plan, so the operator reviews the real gate and hands
the same run the same approval. `dry-run for plan A → apply plan B` is
rejected, as is a cross-run, cross-environment or cross-configuration approval.

**Snapshot sequencing:** `validated PASS → persist plan/snapshot identity →
apply`. If persistence fails there is **NO APPLY** — never "apply and record
afterwards", which could no longer prove what the run was meant to do.

**No rollback automation was implemented.** Rollback remains the established
wp-admin/operator procedure.

The apply branch is **structurally reachable in local tests only**, proven
against in-memory adapters. No test writes to a WordPress record: adapters
throw on any unintended write, and the single permitted-apply case uses a
counting adapter asserting the exact write count.

## 6. Authorization and environment

`Conexao_Translation_Automation_Environment` makes it impossible for a
development or test invocation to target production accidentally:

- the declared environment **and** `wp_get_environment_type()` must agree;
- production additionally requires explicit positive authorisation;
- an absent or unknown environment is never treated as "not production";
- asserting production authorisation in a non-production run is contradictory
  and refused;
- missing or contradictory metadata fails closed.

There is no `if ( production ) { apply(); }` anywhere. Two independent signals
must agree, so a bug or a spoofed context key cannot talk the plugin into
believing it is somewhere it is not.

## 7. Production installation

**REQUIRES OPERATOR ACTION.** No production deployment authorisation was given
to the agent, so nothing was deployed, and no successful installation is
simulated. The required operator sequence (production is WordPress.com: no SSH,
no WP-CLI, no filesystem, no database):

1. Install the promoted shared engine (`conexao-translation-rollout.zip`).
2. Activate it.
3. Verify presence and dependency state **read-only**.
4. Install the permanent automation plugin (`conexao-translation-automation.zip`).
5. Activate it (`Requires Plugins` makes WordPress refuse without the engine).
6. Verify plugin state read-only.
7. Verify no content mutation occurred.
8. Verify no cron was created.
9. Verify no public REST route was created.
10. Verify the automation plugin still cannot apply without an authorised,
    environment-checked PASS dry-run.

No production endpoint was introduced for verification. Artifacts are built by
the supported machinery (`scripts/build-plugins-zip.sh`) and are byte-identical
across rebuilds, with no tests and no development-only files inside.

## 8. Evidence

| Question | Finding | Evidence | Status |
|---|---|---|---|
| Is the engine promoted correctly? | `platform` / `production: true` / `build: true` | `promotion-gate.txt`; `plugins-json-diff.txt` | VERIFIED |
| Were lifecycle rules weakened? | No — accepted as written | `promotion-gate.txt` ("every lifecycle invariant still holds") | VERIFIED |
| Is the dependency graph valid? | Acyclic, engine precedes dependent, header matches registry | `promotion-gate.txt` | VERIFIED |
| Is the engine byte-identical? | `baf85283…a6ce4` before and after | `engine-digest-before.txt`, `engine-digest-after.txt` | VERIFIED |
| Was the engine promoted alone? | All three engine files unchanged | `engine-digest-after.txt` | VERIFIED |
| Are artifacts deterministic and schema-valid? | Byte-identical across two builds; no tests/JSON/junk | `promotion-gate.txt` | VERIFIED |
| Is the lock atomic and fail-closed? | UNIQUE-key `INSERT`; contention → `locked`, no mutation | `test-automation-lock.php` (51 assertions) | VERIFIED (local) |
| Can a non-owner release the lock? | No; the owner can | `test-automation-lock.php` | VERIFIED (local) |
| Is stale reclamation bounded and auditable? | Only beyond TTL; the event names the prior run | `test-automation-lock.php` | VERIFIED (local) |
| Does malformed state fail closed? | Yes; never reclaimed, overwritten or deleted | `test-automation-lock.php` | VERIFIED (local) |
| Does a lock failure prevent engine mutation? | Yes; 0 mutating calls, 0 writes | `test-automation-apply-safety.php` | VERIFIED (local) |
| Is concurrency proven deterministically? | CAS scoping proven against the real table | `test-automation-lock.php` | VERIFIED (local) |
| Does a non-PASS dry-run block apply? | Yes, for FAIL/ERROR/invalid/missing/stale/mismatched | `test-automation-apply-safety.php` | VERIFIED (local) |
| Does a snapshot-persistence failure block apply? | Yes | `test-automation-apply-safety.php` | VERIFIED (local) |
| Can an approval be replayed across plans/runs/envs? | No; bound by digest | `test-automation-apply-safety.php` | VERIFIED (local) |
| Can a dev invocation target production? | No; two signals must agree | `test-automation-apply-safety.php` | VERIFIED (local) |
| Is the stage allowlist still fail-closed? | Yes, plus a registry-consistency assertion | `test-automation-apply-safety.php` | VERIFIED (local) |
| Do audit records carry required identifiers? | Yes, with `assert_no_secrets()` retained | `test-automation-boundary.php` | VERIFIED (local) |
| Is activation free of side effects? | Yes; no activation hook, no content write | `test-automation-boundary.php`, `test-automation-promotion.php` | VERIFIED (local) |
| Is there still no cron? | Yes | `test-automation-promotion.php` | VERIFIED (local) |
| Is there still no public endpoint? | Yes | `test-automation-promotion.php` | VERIFIED (local) |
| Is there still no second engine or provider? | Yes | `test-automation-promotion.php` | VERIFIED (local) |
| Are production artifacts installed? | No | §7 | **REQUIRES OPERATOR ACTION** |
| Was production verified? | Not attempted; unauthorised | §7 | NOT PERFORMED |

## 9. Tests

Exact commands and results:

```
$ ./scripts/run-tests.sh
In-process PHP suites: 67 total, 67 passed, 0 failed
Assertions: 4521 passed, 0 failed
Script-contract suites: 7 total, 6 passed, 1 failed
HTTP acceptance suites: 3 total, 3 passed, 0 failed
```

The **single** remaining failure is the **pre-existing** `i18n_freshness`
baseline failure on 3 stale catalogues, unchanged from Stage 1 (§11). The
catalogues were deliberately **not** regenerated to make the run green.

Stage 2 suites (all passing):

| Suite | Assertions |
|---|---|
| `test-automation-boundary.php` | 136 |
| `test-automation-lock.php` | 51 |
| `test-automation-apply-safety.php` | 107 |
| `test-automation-promotion.php` | 11 |
| `tests/scripts/verify-stage2-promotion.py` | 28 |

```
$ ./scripts/lint.sh
OK: no PHP parse errors.
OK: no new PHPCS violations against the baseline.
PHPStan: [OK] No errors
lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
```

```
$ php scripts/generate-registry-docs.php --check
registry OK: 15 plugins validated, 24 generated regions current (zero writes).
```

```
$ sha256sum wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php
baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
```

## 10. Production changes

**None.**

No production write of any kind. Production was not contacted. No plugin state,
option, route, cron job, content, term, menu or Polylang relationship changed.
No translation was performed. The only repository changes are local source,
registry, tests and documentation.

## 11. Known limitations

1. **Production installation is unperformed** (§7). Requires explicit operator
   authorisation. This is the sole `PASS WITH CONDITIONS` condition.
2. **`i18n_freshness` still fails** on 3 pre-existing stale catalogues
   (`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`). Recorded
   as the same baseline failure as Stage 1, not a Stage 2 regression, and not
   masked by regenerating catalogues.
3. **The audit record is returned, not persisted.** Stage 2 implements only the
   minimal boundary required for safety. The storage and retention decision is
   deliberately deferred to Stage 3 (§13).
4. **No trigger exists.** B2 remains open: nothing schedules or initiates a run,
   and there is no admin/REST entry point, so the boundary is only reachable
   in-process from PHP.
5. **The lock's admin-scope exemption.** Promoting the engine brought its admin
   screen into the cache-scoping gate, which flagged a pre-existing,
   non-language-scoped transient
   (`conexao_rollout_report_<user_id>`). It cannot be fixed in Stage 2 because
   the engine's bytes are pinned. It is exempt under a **conditional** rule that
   requires the `get_current_user_id()` concatenation, and this is verified: a
   bare or differently-scoped `conexao_rollout_report_` key still fails the gate.
   The proper fix belongs to a later deliberate stage.
6. **Apply is reachable only in local tests**, with in-memory adapters. It has
   never run against production content and cannot without an authorised,
   environment-checked PASS dry-run.
7. **`NON_AUTOMATABLE_STAGES` is an explicit list** (`job`), enumerating retired
   rollout-plugin stages that may register with the shared engine without
   becoming automatable. Adding a new one is a deliberate, reviewed edit.

## 12. Stage 2 status rationale

Not `PASS`, because production installation and verification require explicit
operator authorisation that was not given, and this repository's tooling
deliberately cannot deploy.

Not `BLOCKED`, because every safety condition is established and locally proven:
the apply boundary fails closed, the lock is atomic and fail-closed, the approval
is bound to the exact plan/run/environment, snapshot state is established before
apply, and the shared engine is byte-identical. Production installation is an
operator action on already-built, already-verified artifacts — it cannot
weaken a boundary that already refuses without it.

## 13. Stage 3 prerequisites

1. **Change-detection contract (B5)** — persisted inventory/digest state, with
   the documented contract that hooks are a wake-up **hint** and the inventory
   diff is the **source of truth**, so a missed hook cannot desynchronise the
   system.
2. **Provider interface (B4)** — an interface only, no implementation and no
   network calls, able to return translated content, source identity,
   provider/model identity, a deterministic request identity, validation status
   and failure status. Unknown provider responses must fail closed.
3. **Trigger model (B2)** — resolve WordPress.com pv-cron reliability or choose
   an external/manual kick, so the pipeline does not depend on pv-cron. Any
   admin path must carry `manage_options` **and** a nonce, following
   `Conexao_Translation_Rollout_Admin`.
4. **Final audit storage and retention** — decide where the run record lives,
   reusing the contract already in `Conexao_Translation_Automation_Result`,
   keeping `assert_no_secrets()` on the write path.
5. **Apply trigger** — the final mechanism that authorises apply in production,
   built on the F7 chain established here.
6. **Re-baseline production** — Stage 0 found the rollout plugins absent from
   production; re-measure before any apply.
7. **Extend the permanent gates** — assert the automation plugin registers no
   public route and no cron without an explicit allowlist, now that it is a
   production plugin.
8. **Resolve the cache-scope exemption** (limitation 5) in a stage permitted to
   change the engine's bytes.

---

_Evidence: `docs/evidence/2026-09-30-stage-2-engine-promotion-lock-safety/`_

_Last verified: 2026-09-30 by Stage 2 — engine promotion, lock and apply safety_
