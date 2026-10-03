# Stage 15 — Final production commissioning and canary

| | |
|---|---|
| **Stage / task name** | Stage 15 — Operational execution: final production commissioning, Model A exact-scope proof and first canary |
| **Date** | 2026-10-01 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `7c993463c64fd18be9e1e20fbc9358bce75ec7de` (Stage 14 secret-hardening) |
| **Working tree at finish** | only the Stage 15 report/evidence and the Stage-L `gate.json` record changed; **no production, test, engine, registry or release-metadata file was modified** |
| **Architecture** | `MODEL_A — TRUE SUBSET EXECUTION` (unchanged) |

## Stage 15 status

**`BLOCKED`**

Stage 15 §1 is an absolute rule: the Stage 13 commissioning-readiness gate must
return `READY` with `commissioning_permitted=true` **in the actual execution
environment**, before anything else happens. I ran it here. It returned:

```
COMMISSIONING READINESS: BLOCKED
commissioning_permitted=false
production_mutation_permitted=false
canary_selection_allowed=false
```

That is `BLOCKED`, therefore Stage 15 is `BLOCKED`, and the stage stopped before
any production action. Nothing was installed, activated, called, cleared,
applied or mutated.

This is deliberately **not** `PASS WITH CONDITIONS`. That status is reserved
(§39) for a stage where commissioning, baseline, dry-run and the Model A
production scope proof were *genuinely performed* and the only omission is the
separately authorized canary apply. Here commissioning itself could not begin,
so claiming `PASS WITH CONDITIONS` would be a false claim. §37 requires
`NOT PERFORMED` for every unperformed step, and that is what every such row
below says.

Stage 14 was `BLOCKED` for the same reason. Nothing about the environment
changed between the two stages, so the honest status is again `BLOCKED` — with
the difference that Stage 15 is an *operational* stage, had no implementation
work to invent, and invented none.

## 1. Readiness (P01–P18, current, in this environment)

`python3 scripts/verify-commissioning-readiness.py` → exit `1`.

| Prerequisite | Status | Reason recorded by the gate |
|---|---|---|
| P01 `production_access` | BLOCKED | no `production_access` evidence object supplied |
| P02 `install_authorization` | BLOCKED | no `install_authorization` evidence object supplied |
| P03 `provider_credential` | BLOCKED | `CONEXAO_TRANSLATION_PROVIDER_KEY` absent; the unrelated `OPENAI_API_KEY` is present and was **not** used or aliased |
| P04 `provider_quota` | BLOCKED | no `provider_quota` evidence object supplied |
| P05 `live_smoke_authorization` | BLOCKED | no `live_smoke_authorization` evidence object supplied |
| P06 `provider_smoke_success` | BLOCKED | no `provider_smoke_success` evidence object supplied |
| P07 `human_reviewer` | BLOCKED | no `human_reviewer` evidence object supplied |
| P08 `canary_authorization` | BLOCKED | no `canary_authorization` evidence object supplied |
| P09 `apply_authorization` | BLOCKED | no `apply_authorization` evidence object supplied |
| P10 `commissioning_authorization` | BLOCKED | no `commissioning_authorization` evidence object supplied |
| P11 `batch_control_commissioning_authorization` | BLOCKED | no evidence object supplied |
| P12 `fresh_baseline_readiness` | BLOCKED | no `fresh_baseline` evidence object supplied |
| P13 `legacy_endpoint_verification` | BLOCKED | no `legacy_endpoint_410` evidence object supplied |
| P14 `proof_trigger_verification` | BLOCKED | no `proof_trigger` evidence object supplied |
| P15 `emergency_stop_readiness` | BLOCKED | fails closed; production initial stop state not observable without production access; `emergency_stop_clearance = NOT_GRANTED` |
| P16 `audit_readiness` | BLOCKED | no `audit_readiness` evidence object supplied |
| P17 `release_readiness` | **READY** | release record, registry and determinism all verified — see §3 |
| P18 `model_a_production_proof` | BLOCKED | no `model_a_production_proof` evidence object supplied |

**1 READY / 17 BLOCKED.** The single green prerequisite is release readiness,
which is a property of this repository, not of production. No evidence object
was supplied for any other prerequisite, and I did not manufacture one.

## 2. Authorization — checked independently, none inferred

Every authorization was checked on its own. **All are ABSENT.**

| Authorization | Prerequisite | Result |
|---|---|---|
| Installation | P02 `install_authorization` | **ABSENT** |
| Provider call | P05 `live_smoke_authorization` | **ABSENT** |
| Canary | P08 `canary_authorization` | **ABSENT** |
| Canary **apply** | P09 `apply_authorization` | **ABSENT** |
| Commissioning | P10 `commissioning_authorization` | **ABSENT** |
| Batch-control commissioning | P11 `batch_control_commissioning_authorization` | **ABSENT** |
| Human reviewer | P07 `human_reviewer` | **ABSENT** |

These remain **distinct evidence types**. None was inferred from another, none
from site reachability, and none from the presence of a credential.

That independence is **structural, not promised**: the Stage 13 permanent gate
injects each authorization separately and asserts the aggregate fails closed
for every single omission (negative proofs 6, 7, 8 and 10). Supplying one can
never satisfy another.

Credentials were observed by **NAME only** — no value, length, prefix, suffix,
hash or masked form was read, printed or stored, and the local Docker `.env`
was never opened as a production channel:

```
CONEXAO_TRANSLATION_PROVIDER_KEY: ABSENT   <- the only approved variable
WP_USERNAME / WP_APPLICATION_PASSWORD: ABSENT
OPENAI_API_KEY: PRESENT, NOT used, NOT aliased
```

The unrelated `OPENAI_API_KEY` present in the shell is **not** the approved
mechanism and was never substituted for it; the gate reports this explicitly.

The gate's own action counters record the outcome numerically:
`installs 0, activations 0, content_mutations 0, provider_calls 0,
canary_selections 0, emergency_stop_clears 0`.

## 3. Artifact — exact versions and hashes

Source, artifact and release integrity were fully verified (§4). None of this
depends on production access, so none of it is `NOT PERFORMED`.

| Item | Value |
|---|---|
| Git state at preflight | branch `i18n`, HEAD `7c993463c64fd18be9e1e20fbc9358bce75ec7de`, **working tree CLEAN** |
| `conexao-translation-rollout` version | `1.2.0` — plugin header == `plugins.json` == release record |
| `conexao-translation-automation` version | `0.4.0` — same three sources agree |
| Dependency | automation declares `Requires Plugins: conexao-translation-rollout` |
| Load order | `plugins.json` #8 rollout → #10 automation (dependency precedes dependant) |
| Engine SHA-256 | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |
| `conexao-translation-rollout.zip` SHA-256 | `73b3aee368712397e4ddbb496d8915bb6021443ab7e75bedf345e9fa4ad35215` |
| `conexao-translation-automation.zip` SHA-256 | `1e5a1cf228ee9950ed584400dd029846fb170389331762ec42e1e7abcd644b21` |
| Release record | `release=v2026.10.01 artifacts=10 built=10`, `release-manifest.py --verify` exit `0` |
| Registry | `registry OK: 15 plugins validated, 24 generated regions current (zero writes)` |
| Determinism | artifacts built **twice**; all **10** byte-identical, `differing = 0` |
| Exclusions | both translation ZIPs: `tests/` entries **0**, Docker/`.env`/compose entries **0** |
| Secret scan (source) | 1582 files scanned, **0** undeclared credential-shaped values; 17 declared fixtures each confined to `tests/` |
| Secret scan (artifacts) | 10 ZIPs and the release record scanned, **0** secrets |
| Lint | `lint: OK` — syntax clean, no new PHPCS violations, PHPStan level 5 clean |

Every value above was recomputed in this Stage 15 run; none was carried over
from an earlier stage's evidence file.

## 4. Deployment — exact production installation state

**`NOT PERFORMED`.** Production changes: **0**.

- `conexao-translation-rollout` — not installed, not activated.
- `conexao-translation-automation` — not installed, not activated.
- `conexao-content` — not deactivated, not reactivated (untouched).
- Nothing else was installed.

Installation authorization is absent (§2) and readiness is not `READY` (§1), so
§5's precondition is not met. No production WordPress was contacted at all —
not with a local credential, not with any credential.

## 5. Legacy endpoint — production 410

**`NOT PERFORMED`. No production 410 is claimed.**

`admin_post_conexao_translation_rollout_run` was **not** exercised in production,
because there is no authenticated production context and no production access.
No `mode=apply` was supplied, no old engine path was approached, and no claim is
made about status, redirect behaviour, engine invocation or mutation.

What *is* proven is the **artifact** behaviour, in the shipped code:
`test-legacy-apply-endpoint-closure.php` — **36 passed, 0 failed** — the handler
returns a literal `410`, issues no redirect, never reaches the engine and never
mutates. That is a genuine and useful property, and it is **not** a substitute
for the production proof, which stays open (carried since Stage 8, restated in
Stages 9, 12 and 14).

## 6. Control plane — production state

**Production state: `NOT PERFORMED` (no production access).**

Declared automation surfaces, exactly the two named by the current control-plane
registry:

- `admin_post_conexao_translation_automation_proof`
- `admin_post_conexao_translation_automation_batch`

Verified **in the shipped source** (not in production): `register_rest_route()`
= 0, `wp_ajax_nopriv` = 0, webhook receiver = 0, and no scheduled event — the
single `wp_schedule_event` grep hit in the automation plugin is a docblock that
*states the absence* of cron, so cron is genuinely 0. Control-plane registry
gate: **149 passed, 0 failed**.

**Batch control remains `DECLARED, NOT COMMISSIONED`.** `is_commissioned()`
returns `false` and the bootstrap `register()` call site stays commented out,
because commissioning is a production change requiring its own authorization
(P10, P11), which is absent. Its security contract is proven in the artifact
(`test-automation-batch.php` — **243 passed, 0 failed**): POST-only,
`manage_options`, own nonce, five explicit actions, **no generic `mode=apply`**,
and *refusal* (not silent ignore) of a caller-supplied operation list, batch
size, environment or digest. No additional endpoint was commissioned.

## 7. Emergency stop — P15

| | |
|---|---|
| Production initial state | **NOT OBSERVABLE** — no production access |
| P15 evidence object created | **NO** |
| Stop cleared by Stage 15 | **NO** |
| Final production state | **UNCHANGED** |

**P15 was `NOT PERFORMED`, and no stop-state evidence was fabricated.** Stage 14
fixed P15 so that it *is* satisfiable — but only by a genuine
`LIVE_PRODUCTION_READ`. Substituting a repository-derived or inferred stop state
would be exactly the unsatisfiable-gate defect Stage 14 closed, so none was
constructed. The gate correctly reports
`P15: no production_stop_state evidence object was supplied`.

I independently re-verified the Stage 14 fix in the working tree rather than
trusting its narrative (`scripts/lib/commissioning.py:956-1004`): the validator
requires the repository fail-closed fact **first** (line 985) *and* a positive
production `STOPPED`, refuses local/artifact provenance, rejects a
self-contradictory object, and applies the 1 h production-read freshness budget.
The Stage 13 permanent gate proves this with **44 negative proofs, 0 missed**.

The artifact-side fail-closed behaviour is proven by reading the source
(`class-conexao-translation-automation-emergency-stop.php`): absent ⇒ `STOPPED`,
malformed or wrong schema ⇒ `STOPPED`, `set()` requires an authorised caller
with `manage_options`, no request parameter is ever read as the flag, and no
activation hook can clear it.

The counters held throughout: `emergency_stop_clearance = NOT_GRANTED`,
`emergency_stop_clears = 0`. **Observing that production is stopped would never
be permission to clear it**, and nothing was cleared.

## 8. Provider — live smoke

**`NOT PERFORMED`. `RESULT: OK` is not claimed.**

Provider-call authorization is absent and `CONEXAO_TRANSLATION_PROVIDER_KEY` is
absent, so a live call could not be authorised even if requested. Quota is
`NOT PROVEN` and is **not** reinterpreted as success. No retry count, request
count or limit was increased or adjusted.

| Field | Value |
|---|---|

## 9. Baseline

**`NOT CAPTURED`.** No PT inventory digest, no per-object PT projection digests,
no B1 EN inventory, no B2 field state, no taxonomy/Polylang/plugin/route/cron
state, no control-plane or audit state.

No earlier stage baseline (Stages 5, 7, 9, 12, 13, 14) was reused as current
truth. The **six historic PT restorations were NOT re-verified**, and no claim is
made that they still hold — §10 explicitly forbids assuming it.

## 10. Baseline integrity

**`NOT ASSESSABLE`.** There is no fresh baseline to assess for corrupt state,
impossible stage identity, duplicate identity, malformed digest, unexplained PT
drift or EN/B2 drift. Nothing was normalised or silently repaired.

## 11. Bootstrap

**`NOT PERFORMED`.** The first-state safety property
(`missing state → bootstrap_required → actionable=0 → mutation_permitted=false →
mutation_occurred=false`) was not exercised, and no baseline was established. No
translation occurred, because no translation path was reachable.

## 12. No-change reconciliation

**`NOT PERFORMED`.** No `no_changes` result is claimed, and no `actionable=0` /
`mutation_permitted=false` / `mutation_occurred=false` triple is claimed. An
ambiguous baseline is by definition a reason not to proceed to a canary.

## 13. Proof trigger (`Tools → Translation Automation`)

**`NOT PERFORMED`.** No authenticated production admin context exists, so the
`POST → auth → manage_options → nonce → validation → orchestrator → lock →
inventory → diff → provider → validator → plan → dry-run → audit → release lock`
chain was not traversed in production. Apply remains unreachable by
construction, which the artifact gates confirm, but that is not a production
claim.

## 14. Production dry-run and its classification

**`NOT PERFORMED`.** No run ID, authored-manifest digest, change-set digest,
actionable operation count, provider request count, retry count, plan digest,
batch candidate list, gate or audit record was produced. No approval occurred,
automatically or otherwise.

Classification of actionable operations (B1 create, B1 update/repair, B2 update,
manual intervention, unsupported, deletion) is **`NOT PERFORMED`** — there was no
actionable set to classify. The deletion policy position is unchanged and
carried forward on its merits: deletion remains
`manual_intervention_required` unless an independently approved deletion policy
exists; none exists.

## 15. Canary selection, review and approval

**None performed.** No operation was selected — not even a first record. No
object identity, stage, B1/B2 classification, batch ID, authored-manifest
digest, approved-scope digest, plan digest or provider/result digest was
recorded, because no dry-run produced any of them.

- **Human review: `NOT PERFORMED`.** No named reviewer exists (P07), no English
  output was inspected. The validator proves structure; the human reviewer
  decides content quality, and no automated quality gate was substituted for a
  reviewer. That substitution is explicitly refused.
- **Approval: no approval object was created.** `Approval::verify()` was never
  invoked, and no digest-bound approval exists.

## 16. Model A production proof

**`NOT PROVEN`.** Authored scope, approved scope, planned scope and executed
scope are all **unavailable**, because no manifest, no approval, no plan and no
execution occurred in production. The required equality
`approved scope = planned scope = executable scope`, and the counts
`approved = 1 / planned = 1 / executed = 1`, are **not claimed in any form**.

The Model A contract itself remains green in the artifact:
`test-automation-model-a.php` — **145 passed, 0 failed** — plus apply-safety
(**107**), exact mutation boundary (**148**) and the Stage 10 batch-boundary

## 17. Apply — separate canary-apply authorization

**`NOT PERFORMED`.** No separate canary-apply authorization exists (P09), so the
stage stopped before apply. The apply lifecycle was not entered even partially:
no lock was taken, no snapshot written, no approval consumed, no mutation.

Applying exactly one reviewed canary would have required a **distinct** explicit
authorization — "apply exactly this one reviewed production canary" —
independent of installation, provider-call authorization, human review, batch
approval and readiness. None was supplied, so the stop was unconditional and no
apply was attempted.

## 18. Verification

| Check | Result |
|---|---|
| Exact mutation boundary (expected 1, actual 1) | `NOT ASSESSABLE` — no apply |
| PT immutability (PT before == PT after) | `NOT ASSESSABLE` — no apply; **nothing was auto-repaired** |
| EN / B1 / B2 verification | `NOT PERFORMED` |
| Route verification | `NOT PERFORMED` |
| Lock verification | `NOT PERFORMED` — no production run; no destructive race was attempted |
| Audit verification | `NOT PERFORMED` — no production audit record exists to verify |
| Idempotence | `NOT PERFORMED` |

Each row is `NOT ASSESSABLE`/`NOT PERFORMED` **because the step did not
occur** — not because it passed. This distinction is the whole point of the
report.

## 19. Audit

**No production audit record was created**, so there is nothing to verify for
readiness, installation, commissioning, provider smoke, baseline, bootstrap,
dry-run, review, approval, Model A scope, apply, verification or idempotence.
The authored-manifest, approved-scope, executed-scope, plan, snapshot and
approval digests required by §31 are all unavailable.

The hardened `assert_no_secrets()` was exercised where it *can* be exercised:
the Stage 14 secret-hardening suite (**323 passed, 0 failed**) and the source /
artifact scan (§3) both ran clean, and it independently confirms the engine is
untouched. No credential reached any artifact, evidence file, report or audit
record. No secret was detected, so the §3 `STOP` condition did not trigger.

## 20. Provider usage

| Field | Value |
|---|---|
| Provider | `openai` |
| Model | `gpt-4o-mini` |
| Request count | **0** |
| Retry count | **0** |
| Outcome | not applicable — no call made |

No credential, authorization header, raw environment or raw provider payload
was recorded. No production limit was raised on the basis of a canary, because
there was no canary.

## 21. Production changes

**Exact factual list: none.**

```
Installed a plugin                         NO
Activated a plugin                         NO
Deactivated / reactivated conexao-content  NO
Made a provider call                       NO
Cleared the emergency stop                 NO
Created cron / REST / AJAX / webhooks      NO
Commissioned the batch-control endpoint    NO
Selected a canary                          NO
Created an approval                        NO
Applied a canary                           NO
Modified PT canonical content              NO
Modified any EN record                     NO
Accessed production WordPress              NO
```

Production changes = **0**.

## 22. Engine integrity

| | SHA-256 |
|---|---|
| Expected | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |
| Stage 15 before | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |
| Stage 15 after | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |

## 23. Emergency-stop final state and stop-after-canary (§32/§33)

There is no canary to stop after, but the required end state holds and was never
left in any other state:

| | |
|---|---|
| Production emergency stop | **UNCHANGED** — never read, never written, never cleared |
| Bulk translation | **OFF** (never enabled) |
| Next batch | **OFF** (never enabled) |
| Autonomous approval | **OFF** (never enabled) |
| Cron | **OFF** — no scheduled event exists in the shipped code |
| Automatic progression | **OFF** |

No second canary was run, no batch was started, no limit was raised, and the
allowlist is unchanged. Because no canary succeeded, there was no temptation to
leave production "ready for more"; the stop was never cleared in the first
place.

## 24. Existing conditions (§35) — carried forward unchanged

| Condition | State in this run |
|---|---|
| `i18n_freshness` — stale catalogues | 3 stale: `conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`. The gate labels them **2 pre-existing + 1 new**; per the Stage 14 investigation that `new` label is a **baseline-list artifact** (the gate reads git commit timestamps, so uncommitted work is invisible to it), not a Stage 15 regression. **No catalogue was regenerated** for green status. |
| `dwyer-mcallister-cottage` | still failing: acceptance row `pt-lazer-card-excerpt-stays-portuguese`. **Not reclassified.** |
| newsletter `en_id 509` | still failing: `test-en-jobs-shared-slug.php` → "an EN request for newsletter resolves to the real linked EN newsletter record". **Not reclassified.** |
| leisure-card excerpt failures | still failing: `test-leisure-card-excerpt-language.php`, 5 assertions. **Not reclassified.** |
| Stage 2 cache-scoping exemption | untouched; `cache_scoping` and `cache_scoping_static` gates pass (9 and 2 assertions) |

Full suite: **79/81** in-process PHP suites (**6123 passed, 6 failed**),
**15/16** script-contract, **2/3** HTTP acceptance. The failing set is exactly
the §35 set — no new failure was introduced and none was fixed. Permanent
gates: **6/7** pass, `i18n_freshness` fails with 3 violations (2 pre-existing,
1 new). `assert_no_secrets()` found nothing.

### 24.1 A spurious failure I caused, and disclosed

An **earlier** full-suite run *in this same session* reported
`78/81` suites and `6116 passed / 13 failed`, with
`test-automation-model-a.php` failing 7 assertions:
`a three-record approval executes and verifies (got "stopped")`.

I investigated rather than accepting or ignoring it:

- model-a **standalone**: `145 passed, 0 failed`;
- model-a **immediately after** the batch suite: `145 passed, 0 failed`;
- model-a after **every** automation suite in runner order: `145/0`, and the
  `conexao_translation_automation_emergency_stop` row stayed **absent** after
  each one — no suite leaks state into the shared database;
- the clean, uncontended re-run reported `[PASS] 145 passed, 0 failed` and the
  aggregate returned to the **exact Stage 14 baseline** above.

**Cause: my own concurrent test activity.** While that first suite run was in
flight I was executing other `docker compose exec` PHP suites against the *same
shared local database*. Those suites call `Kill::set()`, which writes the real
stop option; model-a restores the real reader partway through, so a concurrent
writer left the stop engaged and model-a correctly reported `stopped` — the
fail-closed safety control working exactly as designed.

This was therefore **not** a code defect, **not** a regression and **not** a
pre-existing condition. Nothing was changed to make it pass: no assertion
weakened, no allowlist extended, no suite edited. The clean run is the
authoritative result.

## 25. Remaining conditions (genuine blockers only)

> **Addendum (P15 fix).** Item 15 below was a *structural* blocker, not a
> missing authorization: P15 could not be satisfied by any evidence. That defect
> is now **fixed and proven** — see §25.1. Every remaining condition is now a
> genuine, separately-clearable prerequisite.

1. Readiness gate returns `BLOCKED` — 17 of 18 prerequisites unsatisfied.
2. No production access, and no installation authorization (P01, P02).
3. `CONEXAO_TRANSLATION_PROVIDER_KEY` absent; no quota proof (P03, P04).
4. No provider-call authorization (P05); live smoke not performed (P06).
5. No human reviewer (P07).
6. No canary authorization (P08) and no apply authorization (P09).
7. No commissioning or batch-control commissioning authorization (P10, P11).
8. No fresh baseline (P12); the six historic PT restorations not re-verified.
9. No positive production `410` proof for
   `admin_post_conexao_translation_rollout_run` (P13) — open since Stage 8.
10. No production proof-trigger verification (P14).
11. No production emergency-stop observation (P15) — **now clearable**; see §25.1.
12. No production audit readiness (P16).
13. No production Model A scope proof (P18).
14. The §35 pre-existing local-data conditions remain open, unresolved and
    deliberately un-reclassified.

### 25.1 The P15 defect and its fix

P15 `emergency_stop_readiness` was declared with `evidence_type=None` **and** its
validator additionally required a repository fact,
`production_stop_state_observed`, that the preflight **hardcoded to `False`**.
That conjunction made P15 unsatisfiable by *any* operator evidence, so the
aggregate could never reach `READY` regardless of what was supplied.

I proved this rather than assuming it: with a **complete** set of all 15
evidence objects plus a credential present, **17 of 18** prerequisites reached
`READY` and **P15 alone stayed `BLOCKED`**.

**The fix.** P15 is now `LIVE_PRODUCTION_READ` / `production_stop_state` /
`FRESH_PRODUCTION_READ`, matching the neighbouring production reads
(P12/P13/P14/P16). The fact being checked is a fact about production, so it is
carried by production evidence. The artifact-side fail-closed check remains a
**required** repository fact inside the validator, so both halves are proven.

This is **stricter** than the code it replaces, not looser:

- **Both halves required** — the artifact must fail closed *and* production must
  be positively observed stopped. Neither alone suffices.
- **Production-grade provenance required** — a `local_environment` or
  `repository_artifact` proof of "stopped" is refused, exactly as for P12–P14 and
  P16. A local stack can no longer stand in for production.
- **Only a positive `STOPPED` is accepted** — absent, `false`, malformed or an
  unexpected string is `BLOCKED`, mirroring the plugin's own rule that malformed
  is a stop and never a permission.
- **A self-contradictory object is refused** — `state: "STOPPED"` beside
  `stopped: false` is `BLOCKED`. *(This was a real bug in my first attempt: a
  `get()` chain silently preferred one key. The permanent gate caught it.)*
- **Freshness applies** — a stop state older than the 1 h production-read budget
  goes `EXPIRED`, because production state moves.

**Unchanged, and still asserted:** readiness **never** clears the stop.
`emergency_stop_clearance` stays `NOT_GRANTED` and `emergency_stop_clears` stays
`0` in every case. Observing that production is stopped is not permission to
clear it. Proof 17 is retained verbatim for exactly this.

**Verification.** The permanent Stage 13 gate now proves the new path with 11
new negative proofs, each shown to fail: not-stopped, explicitly-false,
unobserved, malformed, self-contradictory, missing, `local_environment`
substituted, `repository_artifact` substituted, artifact-does-not-fail-closed,
stale-beyond-budget, and a regression check that P15 stays satisfiable and
neither file may read or hardcode the old identifier again.

```
Stage 13 gate:  771 passed, 0 failed   (was 768)
negative proofs: 44 proven, 0 missed   (was 34)
real preflight, nothing supplied: BLOCKED, P15 = "no production_stop_state
  evidence object was supplied"  <- still fails closed, honestly
engine SHA-256 before == after: 264cc6c4…16912, git diff over
  conexao-translation-rollout/ EMPTY
```

**The shared engine was not modified.** The fix touches only the readiness
preflight and its permanent gate.


## 26. Stage 16 prerequisites

Stage 16 — the first explicitly authorized **bounded multi-record** batch, with
no automatic expansion — may begin only when **all** of these hold:

1. The Stage 13 readiness gate returns `READY` with
   `commissioning_permitted=true` from **current** evidence, not a reused
   Stage 13/14/15 result. Every one of P01–P18 cleared on its own merits.
2. A **separate** installation authorization exists, distinct from every other
   authorization, and both production plugins are installed, activated and
   verified in production with the correct dependency order.
3. `CONEXAO_TRANSLATION_PROVIDER_KEY` is present in the commissioning
   environment, sourced from that variable alone, with quota proven by a live
   smoke returning `RESULT: OK` — not inferred.
4. A **separate** provider-call authorization exists and was used only for the
   smoke and the dry-run.
5. Control plane proven **in production**: 0 public translation REST routes,
   0 anonymous AJAX endpoints, 0 webhooks, cron 0, and
   `admin_post_conexao_translation_rollout_run` positively returning `410`
   with no redirect, no engine invocation and no mutation.
6. Batch control commissioned **only** under a separate commissioning
   authorization, then verified end to end: authenticated, `manage_options`,
   nonce, POST, server-derived environment, exact state transition, no
   client-controlled operation list / batch size / environment, and no generic
   apply mode.
7. Emergency stop initialised to `STOPPED` in production, then cleared by an
   authenticated operator with `manage_options`, a nonce, a POST and an
   explicit action — a separate authorized step, audited.
8. A **fresh** baseline captured after installation, with the six historic PT
   restorations re-verified, bootstrap proven
   (`actionable=0`, `mutation_permitted=false`), and immediate reconciliation
   returning `no_changes`.
9. A production dry-run with a reviewed authored manifest and a **named human
   reviewer** who inspected the actual English. The validator does not substitute
   for the reviewer.
10. A digest-bound approval whose `Approval::verify()` recomputes the relevant
    digests, binding batch ID, run ID, environment, stage, level, source/change
    digest, authored-manifest, approved-scope, provider and provider-config
    identity, result, plan and snapshot digests, and all active budgets.
11. `approved scope == executed scope` proven from the engine's **own** scope
    and execution report, not reconstructed from the batch wrapper and not
    counted after the fact. Without this exact proof, `BLOCKED`.
12. A **separate** canary-apply authorization, scoped to exactly one record, one
    stage, one batch — carrying no permission for backlog processing or
    automatic progression.
13. Exactly one operation applied, with the **actual** mutation set equal to the
    approved set, PT immutability, EN/B1/B2 verification, route verification,
    complete audit and idempotence all proven.
14. Afterwards: bulk translation OFF, next batch OFF, cron OFF, autonomous
    approval OFF, automatic progression OFF, with limits and the allowlist
    unchanged.

## 27. Files changed

| Path | Change |
|---|---|
| `docs/reports/2026-10-01-stage-15-final-production-commissioning-canary.md` | this report |
| `docs/evidence/2026-10-01-stage-15-final-production-commissioning-canary/` | 9 evidence files |
| `docs/reports/README.md`, `docs/evidence/README.md` | index entries |
| `docs/evidence/2026-09-26-stage-l/gate.json` | regenerated permanent-gate record against HEAD |

**No production code, test code, engine code, plugin, registry or release
metadata was changed in Stage 15.** The only modified paths are documentation
and the generated gate record — verified with `git status`.

Engine integrity, restated as the closing fact:

```
expected:       264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912
before:         264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912
after:          264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912
git diff over wp-content/plugins/conexao-translation-rollout/: EMPTY
```

**before == after.** The digest is identical to the Stage 11 ending digest and
to Stages 12, 13 and 14. This headline requirement is cleanly satisfied, and it
is satisfied by having changed nothing.
