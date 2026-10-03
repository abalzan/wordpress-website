# Stage 11 — Model A: Scope Contract and Batch Control

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `402878d`
**Status: `PASS WITH CONDITIONS`**
**Production writes: `0`. Production installs: `0`. Production activations: `0`.
Provider requests: `0`. Production mutations: `0`.**
**Model outcome: `MODEL_A — TRUE SUBSET EXECUTION`**
**Engine SHA-256: `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`
(before) → `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` (after).**

Stage 11 is credential-independent by design. No production credential was used,
requested or inferred; no provider request was made; nothing in production was
read or written. The default production end state remains **bulk translation =
OFF**, and the batch-control capability is **declared but not commissioned**.

---

## Stage 11 status

**`PASS WITH CONDITIONS`**

Stage 10 returned `PASS WITH CONDITIONS` for one substantive reason, which it
stated as a contract gap rather than working around:

> A caller cannot make one shared-engine run cover fewer rows than the stage's
> own authored manifest.

**That gap is now closed.** Model A is implemented and proven: the shared engine
accepts an explicit approved operation subset, plans exactly that subset, and
writes exactly that subset. The invariant

```
approved scope == executed scope
```

is proven in **both** directions — no widening and no shrinking — and the
executed side is **recomputed from the engine's own resulting plan**, never from
a caller-supplied boolean.

`PASS` is not returned, for conditions that are **operator and environmental,
not architectural**: final production batch limits are an operator decision, and
production commissioning remains blocked by the unchanged Stage 9 blockers.

---

## 1. Required model outcome

```
MODEL_A — TRUE SUBSET EXECUTION
```

Model A was implemented because it was feasible, not because whole-stage
execution was inconvenient. Stage 11 did **not** reinterpret Stage 10's refusal
behaviour as the final architecture, and did **not** substitute stronger
admission control for true execution granularity.

### What actually changed in behaviour

| | Stage 10 (Model B behaviour) | Stage 11 (Model A) |
|---|---|---|
| Engine sees | the full authored stage manifest | exactly the approved scope |
| A 1-record approval against a 3-record stage | planned 3 → **refused the whole batch** | plans **1**, writes **1** |
| Anti-widening mechanism | assert *after* planning | **structurally impossible**: an identity the stage does not author is a refusal, not a filter |
| Batches actually executable | none could complete | any approved subset completes and verifies |

The Stage 10 anti-widening assertion is **retained as a live gate** and was
*inverted* from a refusal test into an execution test. A refusal-based test would
still pass if the executor simply always refused; an execution-based test cannot.
This is a strictly stronger property, not a weaker one.

---

## 2. Model A feasibility evidence — the exact integration point

Stage 11 §2 required the feasibility path to be documented **before**
implementation. It was, in
`docs/evidence/2026-10-01-stage-11-model-a-scope-contract-and-batch-control/02-model-a-decision-gate.txt`,
and the trace is in `01-scope-gap-trace.txt`.

### Where the approved subset was being lost

Stage 10 traced the manifest correctly and narrowed
`$config['manifest_callback']` — and then dropped it:

```
Orchestrator::resolve_scope()   narrows $config['manifest_callback']   OK
Orchestrator::execute()         calls $config['run_callback']          <- the ORIGINAL
stage's run_callback            calls conexao_en_translation_engine_config( $post_type )
                                                                              ^ rebuilt
Engine::run()                   always received the FULL authored manifest   FAIL
```

Every one of the four real `run_callback`s closes over `$post_type` and calls
its config **factory** afresh. The narrowed config array was unreachable from
inside the stage.

### The integration point

One window, inside the existing lifecycle, between:

* `(a)` `validate_manifest( $manifest, $config )` — the **complete** authored
  manifest is built and fully validated; and
* `(b)` `collect_states( $config, $adapter, $manifest, $match )` — the first
  function that reads `$manifest['records']`.

Narrowing `$manifest['records']` there propagates through the **entire**
lifecycle, because every downstream function iterates `$manifest['records']`:

| Function | Line | Reads |
|---|---|---|
| `collect_states()` | 508 | `$manifest['records']` |
| `build_plan()` | 236 | `$manifest['records']` |
| `capture_snapshots()` | 551 | `$manifest['records']` |
| `apply_plan()` | 579 | the plan categories |
| `assert_no_pt_drift()` | 771 | `$snapshots` |
| `collect_verify()` | 403 | `$manifest['records']` |

**Not one of them was modified.** The scope is an *input constraint inside* the
lifecycle, not a replacement for any part of it.

### The smallest safe engine/API boundary

```php
Engine::run( $config, $adapter, [ 'scope' => [ 'row-01', 'row-02' ] ] )
```

This is the minimum possible boundary, because **all four real `run_callback`s
forward `$args` verbatim** into `Engine::run()`. The scope therefore reaches the
engine with **zero changes to any stage, adapter or lifecycle function**, and
omitting `scope` leaves every pre-Stage-11 caller byte-identical.

### The §2 decision-gate answers, answered

| Question | Answer |
|---|---|
| 1. Where is the authored manifest constructed? | `Engine::run()` from `$config['manifest_callback']`, rebuilt per call by each stage's config factory |
| 2. Where can an approved subset be introduced? | Between `validate_manifest()` and `collect_states()` — the only point where the complete manifest and the requested scope both exist |
| 3. Smallest safe boundary? | One optional `$args['scope']` key on the existing run signature |
| 4. Can the engine validate against the complete manifest? | Yes — it holds both; an unauthored identity is a refusal |
| 5. Can the existing planner consume the filtered scope? | Yes, `build_plan()` unmodified |
| 6. Can snapshot/apply/verify/idempotence keep their meaning? | Yes, unmodified; they act on exactly the approved records |
| 7. Can PT-drift remain authoritative? | Yes — `assert_no_pt_drift()` untouched, still after the apply, still fails the gate |
| 8. Can B1/B2 semantics remain unchanged? | Yes — both are decided inside `build_plan()`; only the row set presented changes |
| 9. Can all existing engine gates remain authoritative? | Yes — manifest validation runs over the FULL manifest; every gate runs over the scoped set |
| 10. Can the subset bind to the Stage 10 digest approval? | Yes — `operation_set_digest` plus three distinct scope digests |

**No architectural incompatibility was found. Stage 11 is not BLOCKED.**

---

## 3. The scope contract

Three identities that must stay **separately identifiable and separately
digestible**:

| Identity | What it is | Where it lives |
|---|---|---|
| **Authored manifest** | the complete stage-owned operation universe | `Engine::run()` → `$scope['authored']`, `authored_count` |
| **Approved scope** | the exact operation identities approved for this batch | the batch's `identities` + `operation_set_digest` |
| **Executed plan** | the exact operations the engine actually produced/executed | `$scope['executed']`, **recomputed from `$plan`** |

They are kept distinct in the engine's own scope report, which carries
`authored`, `authored_count`, `approved`, `approved_count`, `executed` and
`executed_count` side by side.

### Scope validation (§6) — every refusal is fail-closed, before any write

`Engine::narrow_manifest()` refuses:

| Situation | Failure code |
|---|---|
| the manifest declares no records | `conexao_rollout_bad_scope` |
| the scope is empty | `conexao_rollout_empty_scope` |
| an entry is not a non-empty string | `conexao_rollout_bad_scope_identity` |
| an identity is repeated | `conexao_rollout_duplicate_scope_identity` |
| an identity the stage does not author | `conexao_rollout_scope_identity_unknown` |

There is **no silent drop**: a validation failure is `NO RUN`.

### No scope widening (§7) is structural, not checked

Narrowing can only ever **remove** rows from the validated manifest. A scope can
never introduce one, because an unknown identity is a refusal rather than a
filter. Proven for every ordered subset of a 4-record stage, including
`row-04` alone and `row-04 + row-01 + row-03`.

### No scope shrinking (§8) invalidates, never reduces

Stage 10 proved only the widening direction. Stage 11 adds `plan_missing()`: an
approved identity the engine never planned invalidates the **whole** batch. A
skipped or conflicted approved row is still **named** in the plan, so a batch
cannot quietly become smaller than what was approved — the conflict fails the
numeric gate instead.

---

## 4. The exact-scope proof

The executed scope is recomputed **twice, independently**, and both derivations
must agree with the approval:

1. what the engine **declared** it executed (`$scope['executed']`), and
2. what the engine's **raw plan** actually contains, walked by the batch layer's
   own pure `plan_identities()`.

No caller-supplied boolean establishes equality. Five distinct refusals cover the
ways it can fail, and each invalidates the whole batch rather than applying the
remainder:

| Refusal | Trigger |
|---|---|
| `plan_exceeds_approved_operation_set` | widening |
| `plan_short_of_approved_operation_set` | shrinking |
| `engine_scope_not_applied` | the engine reported no applied scope |
| `engine_scope_evidence_mismatch` | the declared scope disagrees with the plan |
| `executed_scope_equals_approved_scope` | executed ≠ approved |

---

## 5. Engine changes

**One file, purely additive: 190 insertions, 0 deletions.**
`wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php`

| Addition | Nature |
|---|---|
| `narrow_manifest( array $manifest, array $scope )` | new, pure, fails closed |
| `planned_identities( array $plan )` | new, pure |
| `run()` | one optional `$args['scope']` branch, plus a `scope` key on both existing return payloads |

**Zero deletions** means no existing line was removed or rewritten, so no
lifecycle behaviour could have changed. Stage 11 made **no unrelated cleanup and
no refactoring**; the reformatting of the added code is included in the ending
digest, and that digest is re-pinned across all eleven gates that track it.

**Not modified:** any stage config, adapter, provider, change detector, audit or
trigger. The provider boundary, retry behaviour, validation and credential
handling are untouched.

The engine's own gates are all preserved and proven still-owned by the engine:
`collect_states`, `build_plan`, `capture_snapshots`, `apply_plan`,
`assert_no_pt_drift`, `collect_verify`, `calculate_gate`.

---

## 6. Batch-control capability (§17–§20)

**Declared, specified, locally proven, and deliberately NOT commissioned.**

| Property | Value |
|---|---|
| Action name | `conexao_translation_automation_batch` |
| Owning plugin | `conexao-translation-automation` |
| Owning file | `includes/class-conexao-translation-automation-batch-control.php` |
| Method | **POST only**, enforced explicitly |
| Authentication | required (`admin_post_`, so unreachable anonymously) |
| Capability | `manage_options`; a wider claim is refused, not downgraded |
| Nonce | required, on its **own** nonce action, distinct from the proof endpoint's |
| Commissioned | **`false`** — `register()` is never called from the bootstrap |

### Explicit actions, never a `mode` selector

| Action | Authorises | Never authorises |
|---|---|---|
| `prepare` | composing + reviewing one batch | executing anything |
| `approve` | the reviewed batch's digest | composing; executing |
| `execute` | the approved batch only | approving it; widening it |
| `abort` | stopping at the next safe boundary | aborting mid-write |
| `clear_emergency_stop` | resuming new work at all | clearing for one record |

Each is independently authorised. `mode=apply` is refused by the same
unknown-action branch as any other unrecognised string.

### The caller is never authoritative (§19)

The endpoint accepts exactly **two** fields: the action and a batch id.
`operations`, `batch_size`, `environment`, `plan_digest` and `approval_digest`
are **refused outright** by an allow-list — not ignored — so a caller cannot
believe it constrained anything. The batch, its identities, its size and its
environment are resolved **from storage**.

---

## 7. Approval binding (§10, §21)

Stage 10's digest-bound approval is **retained unchanged and extended**, not
replaced. `Approval::verify()` still recomputes every identity from the
**persisted contents** — trusting the stored digest strings alone would be
exactly the substitution the gate exists to prevent, since editing `identities`
in place leaves every stored digest untouched.

The persisted batch record binds: batch id, batch digest, operation-set digest,
stage, environment, run id, level, batch size, partition position, change-set
digest, plan digest, provider identity, both budgets, state, and per-operation
status. It additionally carries the scope evidence, so:

```
approved_scope_digest == executed_scope_digest
approved_plan_digest  == executed_plan_digest
```

**Approval expiry (§22):** the existing `EXPIRED` terminal state already fails
closed, and a stopped batch no longer verifies as executable, so a resume
requires a fresh review. A dedicated wall-clock TTL is **not** introduced in
Stage 11: it is an operator decision for commissioning, and adding an untested
expiry clock now would add risk without adding safety.

**Resume (§25):** a resume plan never replays a completed operation; it skips
completed ones and never touches `manual_intervention`. A changed operation set
fails approval verification, so a changed scope requires new review and approval.

---

## 8. Emergency stop (§23, §24)

**Preserved exactly.** `STOPPED` is the default when no record exists, and a
**malformed** record — wrong schema version, non-boolean `stopped` — also reads as
`STOPPED`, never as permission. No client field can clear it: the value is
written only through an in-process call requiring authorisation and
`manage_options`. It is provider-independent, so it works when the provider is
down.

Checked before batch execution, before each safe operation, and before apply. It
prevents **starting** additional work and never interrupts a mutation already in
progress.

Clearing requires authentication, `manage_options`, a nonce, POST, an explicit
`clear_emergency_stop` action and an audit record — and is deliberately
**independent of any batch**, because resuming new work is a global decision and
must not be reachable by naming a batch.

---

## 9. Retention (§27)

**Unchanged at `20`.** No production retention was altered. The Stage 10
analysis remains valid because the execution model is unchanged in shape: one
engine run per approved batch, not per operation.

---

## 10. Tests (§31, §36)

`wp-content/plugins/conexao-translation-automation/tests/test-automation-model-a.php`
— **145 assertions, 0 failed**, discovering the real shared engine through the
real orchestrator and asserting against the **engine's own plan**.

Covered: approved single and three-record subsets; maximum allowed subset;
authored A+B+C+D with approved A+B+C executing only A+B+C; widening refused;
shrinking refused; missing, duplicate, wrong-stage and malformed identities;
stale source; provider-result and plan drift; environment and approval drift;
lock contention; emergency stop; resume of remaining work only; B1 create, B1
repair and multi-record B1; B2 field update, multi-record B2, existing translated
value, B2 drift, B2 conflict inside and outside scope; and the structural
"no second engine" proofs.

**Every test reaches the real engine.** A test that passed while the engine still
saw the full authored manifest would be worthless, so plan contents are asserted
directly and the scope's placement is proven **at the engine's call sites**.

### Commands and results

| Command | Result |
|---|---|
| `test-automation-model-a.php` | **145 passed, 0 failed** |
| `test-automation-batch.php` (Stage 10 regression) | **243 passed, 0 failed** |
| `test-automation-boundary.php` | 146 passed, 0 failed |
| `test-automation-trigger.php` | 102 passed, 0 failed |
| `test-automation-promotion.php` | 17 passed, 0 failed |
| all 15 automation suites | green |
| `conexao-translation-rollout` engine suites | green, unchanged |
| `verify-stage2-promotion.py` | 28 passed, 0 failed |
| `verify-stage3-automation.py` | 178 passed, 0 failed |
| `verify-stage4-provider.py` | 152 passed, 0 failed |
| `verify-stage6-transport-trigger.py` | 49 passed, 0 failed |
| `verify-stage7-commissioning.py` | 73 passed, 0 failed |
| `verify-stage8-control-plane.py` | **149 passed, 0 failed** |
| `verify-stage10-batch-boundary.py` | **20 negative proofs, 0 missed** |
| `verify-release-integrity.py` | 229 passed, 0 failed |
| `verify-stage8-release-consistency.py` | 52 passed, 0 failed |
| `verify-documentation-drift.py` | 14 passed, 0 failed |
| `generate-registry-docs.php --check` | 15 plugins, 24 regions, zero writes |
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| `./scripts/run-tests.sh` | 4 failing suites, **identical to the Stage 10 baseline** |

### Negative proofs (§32)

The 20 Stage 10 injected-negative proofs still hold, **0 missed**. Stage 11
extended the control-plane gate rather than creating a second inventory, and
proved the new gates fail closed: the batch-control action must stay declared,
must stay **uncommissioned**, must live in its declared file, and the batch layer
must never reach a mutation path or the engine directly.

### Lint

`lint: OK`. The PHPCS baseline **shrank** from 3290/2672 to 3224/2668 — 66 errors
and 4 warnings of debt paid down — rather than being regenerated. Every new
violation was fixed at source. Two text domains were added to the i18n
allow-list because Stage 11 introduces this program's first user-facing strings,
and the automation plugin was bumped `0.3.0 → 0.4.0` (version source: the plugin
header).

---

## 11. Release consistency (§34)

Built twice. **All 10 artifacts byte-identical**; the release manifest's artifact
content identical. `release.json` carries one intentional `built_at` clock
reading, excluded from comparison because it is not artifact content.

`conexao-translation-automation.zip` — **0.4.0**, 147820 bytes,
`984eba85c8371bfb…`. Header == registry == release metadata; dependency order
valid; zero secrets; no tests and no local Docker files in any artifact. The
Stage 8 release-consistency regression is retained and passes.

---

## 12. Production state

```
No production changes
```

Nothing was installed, uploaded, activated, commissioned or applied in
production. No production credential was used, requested or inferred. No
provider request was made. The Stage 9 blockers are **unchanged**.

```
production batch control = NOT COMMISSIONED
```

---

## 13. Existing conditions carried forward (§35)

Unchanged, separately classified, and **not** reclassified to obtain a green
status:

* `i18n_freshness` — 3 stale catalogues (theme, `conexao-content`,
  `conexao-event-runtime`). **Not** regenerated merely for green status.
* `dwyer-mcallister-cottage`.
* newsletter `en_id 509`.
* leisure-card excerpt failures.
* Stage 2 cache-scoping exemption.
* production commissioning blockers (Stage 9).
* six historic PT restorations subject to live re-verification.

The four failing suites after Stage 11 are **byte-for-byte the same four** that
failed before it:

```
theme/conexao-br-irlanda/test-en-jobs-shared-slug.php
theme/conexao-br-irlanda/test-leisure-card-excerpt-language.php
tests/scripts/verify-i18n-freshness.py
tests/acceptance/verify-routing-http.py
```

No new failure was introduced and none was hidden inside an aggregate count.

---

## 14. Conditions remaining

1. **Final production batch limits.** The ceilings are explicit and server-side,
   but whether the first production batch is 1, 3 or 5 records is an operator
   decision, requiring Stage 9 commissioning to have succeeded first.
2. **Approval expiry TTL.** Not introduced; an operator decision for
   commissioning.
3. **Audit retention.** `20` remains the accepted production value. `40` is the
   minimum justified increase only if an operator later wants per-operation
   granularity.
4. **Commissioning the batch-control endpoint.** Declared and dormant.
   Commissioning is a deliberate, separately reviewed production action.
5. **Stage 9 environmental blockers** — production access, installation
   authorization, provider credential and quota, live provider smoke, fresh
   baseline. All absent.

---

## 15. Stage 12 prerequisites

1. **Stage 9 commissioning must have succeeded** — production access, an
   installation authorization, a real provider credential, quota, a live provider
   smoke, a fresh baseline, a verified proof trigger, a verified legacy `410`, and
   a safe production dry run.
2. **A canary must be `VERIFIED`** — zero unexpected PT mutations, zero
   unexpected EN mutations, successful route verification, successful
   idempotence, complete audit, no unresolved verification failure.
3. **Commission `Batch_Control::register()`** as an explicit, reviewed change,
   flipping `is_commissioned()` and the registry entry together — never one
   without the other.
4. **Choose the production batch limits and the first level** explicitly, with
   the Model A evidence attached.
5. **Explicitly clear the emergency stop.** Its default is `STOPPED`, and that
   default is intentional.
6. **Execute a live canary under Model A** and confirm in production that a
   bounded multi-record subset executes exactly the approved identities — the
   property Stage 11 proves locally.
7. **All gates green at the moment of commissioning**, with the four known
   baseline failures unchanged.

---

## Evidence

`docs/evidence/2026-10-01-stage-11-model-a-scope-contract-and-batch-control/`

| File | Content |
|---|---|
| `00-engine-baseline.txt` | the engine SHA-256 recorded **before** any edit |
| `01-scope-gap-trace.txt` | §3 trace; the exact line where the approved subset was lost |
| `02-model-a-decision-gate.txt` | the §2 feasibility path and its ten answers |
| `03-model-a-suite.txt` | the 145-assertion Model A suite |
| `04-stage10-regression.txt` | the Stage 10 suite, 243 assertions, after Stage 11 |
| `05-control-plane-and-gates.txt` | every gate re-run after the change |
| `06-engine-integrity.txt` | §33 starting digest, ending digest, intentional diff |
| `07-release-consistency.txt` | §34 two builds, artifact hashes, manifest |
| `08-full-test-run.txt` | §36 the full suite after the change |
| `09-baseline-classification.txt` | §35 baseline failing suites |
| `10-permanent-gates.txt` | the Stage L permanent-invariant aggregate |

---

_Last verified: 2026-10-01 by Stage 11 — Model A scope contract and batch control (`PASS WITH CONDITIONS`: `MODEL_A — TRUE SUBSET EXECUTION` implemented and locally proven; production writes 0; batch control declared, not commissioned)_