# Stage 10 — Bounded Multi-Record Expansion Controls

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `b1fc13352fbddd295576ff3f2e1aa2e5b30742ba`
**Status: `PASS WITH CONDITIONS`**
**Production writes: `0`. Production installs: `0`. Production activations: `0`.
Provider requests: `0`. Production mutations: `0`.**
**Engine SHA-256: `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`
before and after — unchanged.**

Stage 10 is credential-independent by design. It implemented and locally proved
the controls that make it **impossible** for the system to expand from one
proven canary into an unrestricted translation run, whether or not production
is commissioned. No production credential was used, requested or inferred; no
provider request was made; nothing in production was read or written. The
default production end state remains **bulk translation = OFF**.

---

## Stage 10 status

**`PASS WITH CONDITIONS`**

The bounded multi-record architecture is implemented, is proven locally by
**243 assertions in a new in-process suite** and **20 injected-negative
structural proofs**, and production remains intentionally disabled because the
Stage 9 environmental blockers are unchanged.

`PASS` was not returned, for one substantive reason, which is not an
operational preference but a **contract gap in the shared engine**:

> **A caller cannot make one shared-engine run cover fewer rows than the
> stage's own authored manifest.**

Every real stage's `run_callback` rebuilds its own stage configuration before
handing it to `Conexao_Translation_Rollout_Engine::run()`
(`conexao-en-translation/includes/stage-config.php:195`, `:670`, `:721`), and the
engine has no filter through which a narrowing could be injected. So the
manifest scope Stage 10 passes into the orchestrator cannot be *relied upon* to
reach the engine.

Stage 10 therefore does **not** rely on it. It asserts the bound: after the dry
run the executor reads the engine's own plan and refuses the whole batch if any
record outside the approved set is planned for creation, update or conflict.
The safety property — *an approved batch can never apply an unapproved
record* — is fully enforced and proven. The efficiency property — *one engine
run per approved operation* — is not, and is the condition carried forward.

This is exactly the situation §36 anticipates: a genuine contract gap, found
during implementation, reported rather than worked around. The engine was
**not** modified.

---

## Preconditions carried forward

| Requirement | State |
|---|---|
| Production access | **ABSENT** (Stage 9, unchanged) |
| Installation authorization | **ABSENT** |
| Provider credential | **ABSENT** |
| Production baseline / canary / commissioning | **NOT PERFORMED** |
| Shared engine integrity | **VERIFIED** — SHA-256 unchanged |
| Stage 7/8 control-plane gates | **PASSING** — extended, not weakened |
| Stage 9 blockers | **UNCHANGED** and carried forward, not masked |

---

## 1. The batch model

### 1.1 What a batch is

A batch is a **named, digest-bound, ordered set of planned operations** that a
human approved as one unit. It is pure data: an identity, an operation set, a
partition position, budgets and a state. It is not a queue, not a worker, not
a second engine, and it holds no translated text.

### 1.2 Exact limits

Every number lives in one file,
`Conexao_Translation_Automation_Batch_Limits`, and is server-side.

| Constant | Value | Reasoning |
|---|---|---|
| `MAX_PRODUCTION_BATCHES_PER_INVOCATION` | **1** | Not configurable — no filter, option or request field can raise it. After one batch the system STOPS. |
| `MAX_STAGES_PER_RUN` | **1** | A batch is single-stage. |
| `SERVER_CEILING_BATCH_RECORDS` | **5** | The hard ceiling. A caller may request fewer; it may never raise this. |
| `MAX_RECORDS_PER_STAGE` | **5** | Per-stage record ceiling. |
| `MAX_OPERATIONS_PER_BATCH` | **5** | The mutation budget. |
| `MAX_PROVIDER_REQUESTS_PER_BATCH` | **20** | Independent of the operation budget. |
| `MAX_RETRIES_PER_RECORD` | **1** | Two attempts per record — below the provider's own `MAX_ATTEMPTS` of 4. |
| `MAX_ATTEMPTS_PER_RECORD` | **2** | `1 + MAX_RETRIES_PER_RECORD`, never configured separately. |
| `MAX_TOTAL_PROVIDER_CALLS` | **20** | Initial, retries, stale re-requests. Still below the provider's own 50. |
| `MAX_EXECUTION_SECONDS` | **300** | Half the provider's 600 s budget, inside the 900 s lock TTL. |

**The ceiling refuses, it does not clamp.** `batch_size=100000` returns
`batch_size_above_server_ceiling` and the run does not start. A clamp would be
silent, and the audit trail would then record 5 — no longer showing what the
caller actually asked for. Proven by injection in the suite and structurally
by the control-plane gate.

**Documented first production batch: `LEVEL_0_CANARY` = 1 record.** A lower
bound is appropriate for the first production batch because it is the first
time the chain is exercised against live PT content; the operator may raise it
to 3 only on the evidence an expansion gate accepts, and only by a new human
decision.

### 1.3 Rollout levels

| Level | Records | Operation budget | Provider budget |
|---|---|---|---|
| `LEVEL_0_CANARY` | 1 | 1 | 4 |
| `LEVEL_1_SMALL_BATCH` | 3 | 3 | 12 |
| `LEVEL_2_LARGER_BATCH` | 5 | 5 | 20 |

These are **operational states**, not judgements: no level is labelled safe,
approved or recommended. Each is reachable only by a human typing its number.
`level_for_size()` returns the *smallest* level that accommodates a size, so a
one-record batch can never inherit the largest level's budget.

---

## 2. Budget semantics

### 2.1 Operation count

A batch's operation count is **one per plan row**, and the plan row is the
engine's own unit of work. The count is derived from the plan model
(`Conexao_Translation_Automation_Translation_Plan::operation_for`), which
distinguishes a B1 creation, a B1 update, a B1 repair/reconcile and a B2
single-owned-field write. The batch layer never accepts a caller-supplied
count.

### 2.2 Provider-request count

Counted **pessimistically**, as §6 requires:

```
provider_request_cost(records) = records x MAX_ATTEMPTS_PER_RECORD x 2
                               = records x 4
```

The doubling covers the re-request a record needs when its first result is
rejected as stale. A one-record batch therefore costs 4 provider calls, not 1,
and a three-record batch costs 12. `assert_budget()` fails **closed, before any
apply**, when `planned_operations > operation_budget` or
`planned_provider_requests > provider_budget`. No partial execution begins.

The budget is checked **twice**: once as a pure gate on the real numbers, and
once inside the executor against the batch's own recorded budgets.

### 2.3 Time budget

`MAX_EXECUTION_SECONDS = 300`. The safe design is `preflight check -> process
the bounded unit -> verify -> continue only if budget remains`; when
continuing would exceed the budget the result is
`stop_after_current_safe_unit`. No process is ever killed mid-mutation, no
verification is bypassed, and no lock owned by another run is released.

### 2.4 Batch-level dry run

Before any apply, `batch_dry_run()` produces the exact operation count, the
exact operation identities, the exact provider-request cost, the exact batch
partition, the exact batch digest, the exact operation-set digest, the exact
snapshot identity and the resource budget. `PASS` requires every check to hold.
A single invalid operation **invalidates the whole batch**; it is never
silently removed, because that would silently change what the approval means.

---

## 3. The state machine

Defined as an explicit transition table in
`Conexao_Translation_Automation_Batch_State::TRANSITIONS`. An unknown state on
either side of a transition is a **hard failure**, never a permissive yes, and
no request parameter is ever read as a state.

```
REVIEW_REQUIRED    -> REVIEWED | EXPIRED
REVIEWED           -> APPROVED_FOR_BATCH | REVIEW_REQUIRED | EXPIRED
APPROVED_FOR_BATCH -> EXECUTING | ABORTED | EXPIRED
EXECUTING          -> VERIFIED | FAILED | STOPPED | ABORTED
VERIFIED           -> EXPIRED
FAILED             -> REVIEW_REQUIRED | EXPIRED
STOPPED            -> REVIEW_REQUIRED | EXPIRED
ABORTED            -> REVIEW_REQUIRED | EXPIRED
EXPIRED            -> (terminal)
```

**There is no `VERIFIED -> EXECUTING` edge.** A verified batch cannot begin
another execution, so it can never become the "previous batch" of an automatic
second one. The only route to more work is for a human to compose a NEW batch,
which starts again at `REVIEW_REQUIRED` and needs its own review, approval and
digests.

The human promotion model is therefore exactly: `REVIEW_REQUIRED` ->
`REVIEWED` -> `APPROVED_FOR_BATCH` -> `EXECUTING` -> `VERIFIED`, with `STOPPED`,
`ABORTED`, `FAILED` and `EXPIRED` as the other terminal outcomes.

---

## 4. Batch identity and approval binding

### 4.1 Identity

`batch_digest()` binds: run id, environment, stage, level, batch size,
partition index, change-set digest, plan digest, approval digest, provider
configuration identity, operation budget, provider budget, operation count and
the operation-set digest. `batch_id` is `batch_` plus the first 16 hex of that
digest, so it is stable for one composition and different for any other.

Each of those fields is proven to change the digest. A batch is therefore
**not replayable against a different plan, environment, provider, budget or
partition**.

### 4.2 Deterministic partitioning

`Batch::stable_order()` is ascending **byte** order of the *portable source
identity*, via `strcmp`. Never database query order, never PHP hash iteration
order, never `mt_rand()`, never the clock. `Composer::preview()` returns every
partition as read-only data, so an operator can see batch 1, batch 2 and batch
3 before visiting any of them. Proven: the same plan at the same size
partitions identically, and inserting the same rows in a different order
produces the identical partition.

### 4.3 The operation-set digest — what makes approval non-substitutable

`operation_set_digest()` hashes, for every identity in order, the identity, the
operation kind, the PT source digest and the provider result digest. So:

| Change | Result |
|---|---|
| record set `A+B+C` -> `A+B+D` | different digest -> **refused** |
| a record's PT source moved | different digest -> **refused** |
| a record's provider result changed | different digest -> **refused** |
| an operation kind changed | different digest -> **refused** |
| a limit, environment or provider changed | different digest -> **refused** |

There is no `approve_batch_type` and no `approve_current_queue`. Both were
rejected because each makes an approval survive a change in *what* is approved.

### 4.4 Review and approval are two actions, never conflated

`record_review()` stores a **review digest** over the batch. `record_approval()`
refuses a batch with no review digest, refuses when the review digest no longer
matches the batch's current composition, and stores an **approval digest** that
additionally binds the review digest. So an approval can never exist for a batch
nobody reviewed, and a review can never authorise execution on its own.

`Approval::verify()` runs **before every execution** and, critically,
**recomputes the composition's own digests from the record's contents** rather
than trusting the stored digest strings. Trusting those strings would have been
exactly the substitution the gate exists to prevent: a caller editing
`identities` or `operation_detail` in place would leave every stored digest
untouched. A stored record whose composition no longer hashes to itself is
refused as tampered.

---

## 5. Safety: batch safety AROUND per-operation safety

The batch layer performs no mutation and holds no bypass. The engine remains the
sole mutation authority and its core file is **byte-identical**.

For the batch's safe unit the executor calls the **existing** orchestrator,
which performs its complete, unmodified chain:

```
site-wide lock
  -> environment guard (two independent signals must agree)
  -> F7 dry run (exactly PASS; errors or PT drift are not PASS)
  -> digest-bound approval verification (constant-time)
  -> snapshot/plan identity PERSISTED BEFORE the write
  -> apply
  -> engine verification and numeric gate
```

Structural proofs that the batch layer cannot shortcut any of it:

| Absent from every batch file | Why it matters |
|---|---|
| `Conexao_Translation_Rollout_Engine::` | the batch layer never reaches the engine directly |
| `wp_insert_post` / `wp_update_post` | the batch layer writes no content |
| `wp_delete_post` / `delete_post_meta` | there is no deletion path at all |
| `pll_save_post_translations` | no Polylang mutation |
| `wp_remote_*` | the batch layer makes no network call |

The executor additionally refuses to approve or review anything: it contains no
`Batch_Approval::record_approval` and no `record_review`.

### 5.1 The plan-scope assertion — the bound, enforced rather than trusted

Because the manifest scope cannot be guaranteed to reach the engine (see
*Stage 10 status*), the executor does not trust it. After the dry run it reads
the engine's **own plan** and requires that every record it intends to
`create`, `update` or treat as a `conflict` is inside the approved identity
set. Anything outside is `batch_invalidated` with **no apply**.

So whether or not a stage honours the scope:

* an approved batch **can never apply an unapproved record** — proven;
* a sub-manifest batch is **refused outright**, not silently widened and not
  silently shrunk — proven.

### 5.2 Partial failure semantics

| Situation | Behaviour | Proven by |
|---|---|---|
| provider failure before apply | `NO MUTATION` | suite §12, §13 |
| provider failure during plan generation | `NO MUTATION for unapproved operations` | suite §12 |
| validation failure | `NO MUTATION for the affected operation` | suite §8, §21 |
| lock loss | stop safely; **another run's lock is not released** | suite §17 |
| source digest drift | the affected result is rejected | suite §21 |
| apply failure | **stop the batch** | suite §15 |
| verification failure | **stop the batch** | suite §15, §21 |
| unexpected mutation | **stop the whole Stage 10 path**, require operator investigation | suite §24, gate |

The executor reads the engine's **own** post-apply `summary.errors`,
`summary.pt_changed` and numeric gate. A write call returning is not a
verification; the engine's verdict is.

### 5.3 No shrinking of a failed batch

A batch of three whose second operation failed is **not** quietly reduced to one
and applied. The batch stops, and the record keeps all three of its approved
identities. The operator may later create a **new, explicitly reviewed** batch
with the problematic record removed or corrected. An approval must never change
meaning silently.

---

## 6. Resume, abort and the emergency stop

### 6.1 Resume

The per-operation ledger records `pending`, `completed`, `failed`,
`verification_failed` or `manual_intervention_required` for **every** approved
identity, so "unattempted" can never be confused with "completed". An identity
with no recorded status reads as `pending`.

The ledger is written **from the engine's own result rows**, not from the batch
layer's opinion. This is explicitly **not a second idempotence mechanism**: it
records what the engine observed, while whether a record actually needs
writing remains the engine's plan, its PT-drift guard and its numeric gate.

`resume_plan()` returns `completed`, `retry` and `manual` separately. A resume
never includes a completed operation. A `STOPPED` batch cannot simply be
re-executed: the approval no longer verifies in that state, so the attempt is
refused and writes nothing.

### 6.2 Abort

`Batch_State::request_abort()` records a per-batch flag. The executor checks it
**before the unit starts**, never during one, so an abort can never interrupt a
mutation in progress. The semantic is `finish current safe unit -> stop`. The
abort never deletes or corrupts audit state, never releases a lock it does not
own, and has no public endpoint.

### 6.3 Emergency stop

A single server-side record in `wp_options`. It is **not** dependent on provider
behaviour — it is read from the database and works when the provider is
unreachable, which is when an operator most needs it.

| Stored value | Reading |
|---|---|
| **absent** | **STOPPED** — the default is that new work does not start |
| well formed, `stopped = false` | enabled |
| well formed, `stopped = true` | **STOPPED** |
| malformed / wrong schema / non-boolean `stopped` | **STOPPED** — fail closed |

A corrupted row, a truncated write or an unexpected shape can never be read as
"the switch is off". A malformed safety control is a stop, never a permission.

The browser cannot fabricate it: `set()` requires an explicit authorisation
context in code, and **no request parameter is read anywhere in the class**. No
admin screen, REST route, AJAX handler or webhook is registered by Stage 10.

---

## 7. Expansion gates

`Batch_Expansion::evaluate()` answers exactly one question: *does the evidence
from the preceding batch support a larger one?* It requires:

| Requirement | Why |
|---|---|
| the previous batch is `VERIFIED` | an unverified predecessor proves nothing |
| zero unexpected PT mutations | PT is canonical; an EN change that moved PT is a policy breach |
| zero unexpected EN mutations | an unapproved mutation is the failure this program exists to prevent |
| route verification succeeded where relevant | an EN record that 404s is not a translation |
| idempotence succeeded | a re-run must be a no-op |
| audit complete | an unrecorded batch cannot be reviewed |
| provider error rate within bounds (25%) | a high error rate means the next batch fails the same way |
| no unresolved verification failure | an unresolved failure is not evidence |

**It never authorises anything.** The gate file contains no reference to the
orchestrator, the composer or the engine, all three asserted structurally. It
returns `eligible` plus the list of unmet requirements, and stops. An absent
measurement is treated as **failing**, never as passing. Nothing anywhere in
the plugin turns eligibility into execution.

---

## 8. Audit impact

### 8.1 What is persisted

The **existing** audit store and the **existing** `build_record()` shape, with
Stage 10 batch metadata added. No second audit engine, no new retention policy,
no new secret rule. The following are recorded:

`batch_id`, `batch_digest`, `batch_level`, `batch_state`, `batch_outcome`,
`operation_count`, `operation_set_digest`, `operation_budget`,
`provider_request_budget`, `provider_request_cost`, `provider_identity`,
`time_budget_seconds`, `previous_batch_id`, `resumed_from_batch_id`,
`stop_reason`, `executed_records`, `skipped_records`.

Only **digests and counts** are persisted. The batch record carries no
translated text and no provider payload, so a batch can never become a second
copy of the content. `Result::assert_no_secrets()` runs on the write path, so a
record carrying a credential-shaped key or value is **refused** and the refusal
becomes the run's failure category.

### 8.2 Retention: is 20 still enough?

**Determination: 20 records remains sufficient under the Stage 10 model, and no
increase is proposed.** The calculation:

| Scenario | Audit records |
|---|---|
| one canary (1 record batch) | 1 |
| one small batch (3 records) | 1 |
| repeated failures (3 attempts) | 3 |
| retries within a batch | 0 additional (one record per execution) |
| resumptions (2) | 2 |
| **total** | **7** |

The decisive design choice is **one audit record per batch EXECUTION, not one
per operation**. A three-record batch is one record carrying three operation
counts, not three records. Had the audit been written per operation, a single
`LEVEL_2_LARGER_BATCH` (5 records) would have consumed 5 of 20 records, and a
canary-plus-batch-plus-failure sequence would have exceeded the bound.

**This is a determination, not a production change.** `AUDIT_RETENTION` remains
**20** and no production retention was altered. If an operator later decides
that per-operation audit granularity is required, the minimum justified
increase is **40** — enough for 7 batch executions at the current granularity
with a factor-of-two margin, and it is an **operator decision, not a Stage 10
one**.

---

## 9. Control-plane gate

The **existing** Stage 8 control-plane gate was **extended**, not duplicated
(`tests/scripts/verify-stage8-control-plane.py`, 90 -> **141 assertions**, 0
failed). The endpoint inventory is still one statement in one file:

* exactly one approved automation endpoint, `admin_post_conexao_translation_automation_proof`;
* the legacy endpoint stays registered and closed;
* zero forbidden anonymous/AJAX/REST/cron/webhook surfaces in every shipped file;
* a new **`BATCH_CONTROL_SURFACES` registry**, explicitly empty, so a future
  batch-control endpoint must be declared *here* or the gate fails on
  "undeclared batch endpoint".

The Stage 10 section asserts: every batch file exists and is named; no batch
file can reach a mutation path; the batch layer registers **no** admin-post
endpoint; `MAX_PRODUCTION_BATCHES_PER_INVOCATION` is exactly `1`; the executor
contains no loop construct and exactly one orchestrator call site; the
executor re-asserts the one-batch guard, re-verifies the approval, checks the
emergency stop and the abort, asserts the plan scope, and reads the engine's
post-apply errors and gate; and the expansion gate still requires a verified
predecessor, still derives eligibility from the unmet list, and still requires
all seven evidence keys.

The Stage 3 gate was extended the same way: it previously asserted the
orchestrator was invoked from **exactly one** place. It now **names** the batch
executor as the second approved caller and asserts there are exactly those two,
each invoking it once. A third caller still fails.

---

## 10. Negative proofs

`tests/scripts/verify-stage10-batch-boundary.py` — **20 proven, 0 missed.**

Every case injects a **real violation into a throwaway copy** of the repository
and asserts the real gate exits non-zero. The clean state is asserted to pass
first, because a proof that passes because the baseline is already red proves
nothing — a defect the Stage 8 negative proofs had to correct, and one this
script guards against by copying both engine files the gate reads.

| # | Injected violation | Caught by |
|---|---|---|
| 1–2 | the one-batch default raised to 2, then to 1000 | ceiling is exactly 1 |
| 3–8 | `wp_insert_post` / `Rollout_Engine::` / `wp_update_post` / `wp_delete_post` added to batch files | mutation-path scan |
| 7 | the executor gains a `while` that pulls a second batch | no loop construct |
| 8 | the executor composes batches of its own | no `Batch_Composer::compose` |
| 9 | the executor's one-batch guard removed | guard re-asserted |
| 10 | a second, unscoped orchestrator call site | exactly one call site |
| 11 | the per-execution approval check removed | re-verification asserted |
| 12 | the emergency stop bypassed | stop check asserted |
| 13 | the plan-scope assertion removed | the `if` guard asserted, not just the helper name |
| 14 | the expansion gate stops requiring a verified predecessor | requirement still required |
| 15 | the expansion gate returns `eligible` unconditionally | eligibility still derived from `$unmet` |
| 16 | an unnamed batch file that writes content | every shipped batch file is named |
| 17 | the batch layer registers an admin-post endpoint | no batch endpoint |

Four of these proofs (9, 11, 13, 15) were **MISSED on the first run** and each
one exposed a genuinely missing gate assertion, which was then added. A proof
that passes proves the gate; a proof that is missed proves the gate had a hole.

The **behavioural** negative counterparts live in the in-process suite, which
uses an adapter that **THROWS** on any write it is not explicitly permitted, so
an unintended mutation fails the suite loudly rather than quietly mutating the
local site.

---

## 11. Local simulation coverage

The 17 required fixtures are all present in
`test-automation-batch.php` (**243 assertions, 0 failed**):

| Fixture | Section |
|---|---|
| one canary | §8 |
| three-record batch | §7 |
| batch-budget exceeded | §12 |
| provider-budget exceeded | §13 |
| operation-count exceeded | §13, §14 |
| one failed operation | §15 |
| stale source | §21 |
| changed plan | §4 (digest) |
| changed approval | §6 |
| lock contention | §17 |
| resume | §16 |
| abort | §20 |
| emergency stop | §18, §19 |
| new PT change during execution | §22 |
| deleted PT record | §23 |
| verification failure | §15, §21, §24 |
| unexpected mutation | §24 |

---

## 12. Production status

**No production changes.**

Nothing was installed, uploaded, activated or applied in production. No
production credential was used, requested, inferred or printed. The local
Docker credentials in `.env` were **not** repurposed — the file documents its
own scope, and reusing them against production would be the "infer one
permission from another" failure §1 forbids. No provider request was made, so
no provider credential was needed. No production content was read or written.
No production cron, route, menu, taxonomy or Polylang configuration was
created or altered. The Flutter/mobile repository was not accessed.

Stage 10 registers **no endpoint of any kind**. The Stage 6 proof trigger
remains the only admin-post action in the plugin, and it remains proof-only.
The batch layer is a capability with no surface, available to an authorised
in-process caller and to nothing else, until it is explicitly commissioned.

---

## 13. Engine integrity

```
before  baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
after   baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
```

**Unchanged.** The pinned digest is asserted by the Stage 8 control-plane gate
on every run, so a future accidental edit fails the suite rather than passing
quietly.

The only change to an existing production file was **additive** to
`class-conexao-translation-automation-orchestrator.php`: an optional
`manifest_scope` that narrows the stage configuration, a `scope_identities()`
helper, and the scope folded into `config_identity()`. Every existing assertion
about the orchestrator's chain order still passes. No engine file, no stage
file and no existing control was modified.

---

## 14. Existing conditions carried forward

Recorded, not masked, not regenerated, not reclassified:

| Condition | State |
|---|---|
| `i18n_freshness` — 3 stale catalogues | **UNCHANGED** — catalogues were not regenerated to make the suite green |
| `dwyer-mcallister-cottage` | **UNCHANGED** |
| newsletter `en_id 509` (`test-en-jobs-shared-slug`) | **UNCHANGED** |
| leisure-card excerpt failures (`test-leisure-card-excerpt-language`, `verify-routing-http`) | **UNCHANGED** |
| Stage 2 cache-scoping exemption | **UNCHANGED** |
| production commissioning blockers | **UNCHANGED** |
| six historic PT restorations | still subject to live re-verification |

All four are **pre-existing failures present on the baseline run before any
Stage 10 change** and are identical after. No new failure was introduced.

---

## 15. Remaining conditions

1. **The engine/stage manifest-scope contract gap.** A caller cannot bound one
   shared-engine run below the stage's authored manifest. The safety bound is
   enforced by the plan-scope assertion and is proven; per-operation execution
   granularity is not available until this is resolved. **This is a decision,
   not a defect to be worked around**: either the engine grows a documented
   scope hook, or each stage's `run_callback` is made to honour the config it
   is given. Both are out of Stage 10's scope, and the first is an engine
   change that §36 requires be reported, not taken.
2. **Final production batch limits.** The ceilings are explicit, conservative
   and configurable in code, but the *production* values — whether the first
   production batch is 1, 3 or 5 — are an operator decision requiring the
   Stage 9 commissioning to have succeeded first.
3. **Audit retention.** Determined sufficient at 20 for the Stage 10 model
   (§8.2), with 40 as the minimum justified increase if an operator later wants
   per-operation granularity. **No production retention was changed.**
4. **Commissioning of the batch executor.** It is deliberately unexposed. A
   future admin capability would have to be declared in the same control-plane
   registry with its own capability, nonce and POST assertions.

---

## 16. Stage 11 prerequisites

To use the first bounded multi-record batch **after** the production canary is
proven:

1. **Stage 9 commissioning must have succeeded**: production access, an
   installation authorization, a real provider credential, quota, a successful
   live provider smoke, a fresh baseline, a verified proof trigger, a verified
   legacy `410`, a safe production dry-run.
2. **The canary must be `VERIFIED`**, with zero unexpected PT mutations, zero
   unexpected EN mutations, successful route verification, successful
   idempotence, complete audit and no unresolved verification failure.
3. **The engine/stage manifest-scope contract gap must be resolved** (above),
   or the first production batch must be accepted at whole-stage granularity
   with the plan-scope assertion as the only bound.
4. **The emergency stop must be explicitly cleared** by an authorised operator.
   Its default is `STOPPED`, and that default is intentional.
5. **A batch-control admin capability must be commissioned and declared** in
   `BATCH_CONTROL_SURFACES` with its own `manage_options`, nonce and
   POST-only assertions. Stage 10 deliberately registers none.
6. **The operator must choose the production batch limits** and the first
   batch's level explicitly, with the expansion evidence attached.
7. **The full test suite, lint, registry, release integrity, documentation
   drift, governance and the permanent gates must all be green** at the moment
   of commissioning, with the known baseline failures unchanged.

Until every one of those is true, the correct production state remains:
**bulk translation = OFF**, one reviewed canary, and an explicit human decision
at every expansion boundary.

---

## Evidence

`docs/evidence/2026-10-01-stage-10-bounded-expansion/`

| File | Content |
|---|---|
| `01-baseline-full-test-run.txt` | the full suite BEFORE any Stage 10 change |
| `02-stage10-batch-suite.txt` | the 243-assertion Stage 10 suite |
| `03-negative-proofs.txt` | 20 injected-negative structural proofs |
| `04-control-plane-and-stage-gates.txt` | Stage 2/3/4/6/7/8 gates, extended |
| `05-engine-integrity.txt` | SHA-256 before and after |
| `06-full-test-run.txt` | the full suite AFTER the change |
| `07-permanent-gates.txt` | the Stage L permanent-invariant aggregate |

---

_Last verified: 2026-10-01 by Stage 10 — Bounded Multi-Record Expansion Controls_
