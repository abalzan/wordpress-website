# Stage 13 — The Commissioning-Readiness Gate

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `777d576e4ea9c9fd99f853211eabeffefcde7609`
**Status: `PASS`**
**Production commissioning = `NOT PERFORMED`. Production writes: `0`. Production installs: `0`.
Production activations: `0`. Provider requests: `0`. Production content reads: `0`. Production mutations: `0`.**
**Commissioning readiness: `BLOCKED` (17 of 18 prerequisites). Model A production proof: `NOT PROVEN`.**

Stage 12 was blocked for want of credentials, quota, authorizations, a reviewer and
canary authorization. Stage 13 did not try again. It built the one thing that was
missing: **a single, machine-checkable gate that decides whether production
commissioning may begin, and fails closed unless every prerequisite is positively
evidenced.**

That gate is implemented, tested and fail-closed. Its verdict in this environment
is `BLOCKED` — which is the gate working, not the gate failing. Section 37 makes
`PASS` conditional on the *gate* being complete, and expressly not on production
being commissioned.

---

## Stage 13 status

### `PASS`

The gate is complete and independently proves it can tell six things apart:
credential presence, authorization, provider success, production proof, canary
approval and apply authorization. Each is exercised by a negative proof that is
shown to fail. **34 negative proofs, 34 proven, 0 missed. 768 assertions, 0 failed.**

`PASS` is not claimed for commissioning. `production commissioning = NOT PERFORMED`
is stated in the header and is true.

### Why not `BLOCKED`

Section 37 reserves `BLOCKED` for a gate that **cannot** make those distinctions.
This one makes all six, and proves each by injection. Section 37 also forbids
returning `BLOCKED` merely because production credentials are absent — the purpose
of Stage 13 is to test readiness, not to require it. The positive control
demonstrates this directly: a fully-evidenced environment reaches `READY` with
`commissioning_permitted=true`, and it is asserted to do so *before* any negative
is injected. The gate is not hard-wired to say no.

### Why not `PASS WITH CONDITIONS`

That status is for a complete gate with a non-safety documentation or operational
detail outstanding. There is none outstanding: the registry, the runbook, the
permanent gate, both indexes and the evidence are all in place and green.

---

## Required model outcome

### `MODEL_A — TRUE SUBSET EXECUTION`

Unchanged from Stage 11, and **not** promoted. The Stage 11 proof is a
*repository* proof. `P18 model_a_production_proof = NOT PROVEN` because a
production canary has not demonstrated `approved scope == executed scope` in
production. The gate refuses to accept a local proof for a production
prerequisite, and proof 15 shows it failing when the production evidence is
absent.

---

## Commissioning readiness

```
COMMISSIONING READINESS: BLOCKED

P01 production_access                          BLOCKED
P02 install_authorization                     BLOCKED
P03 provider_credential                       BLOCKED
P04 provider_quota                            BLOCKED
P05 live_smoke_authorization                  BLOCKED
P06 provider_smoke_success                    BLOCKED
P07 human_reviewer                            BLOCKED
P08 canary_authorization                      BLOCKED
P09 apply_authorization                       BLOCKED
P10 commissioning_authorization               BLOCKED
P11 batch_control_commissioning_authorization BLOCKED
P12 fresh_baseline_readiness                  BLOCKED
P13 legacy_endpoint_verification              BLOCKED
P14 proof_trigger_verification                BLOCKED
P15 emergency_stop_readiness                  BLOCKED
P16 audit_readiness                           BLOCKED
P17 release_readiness                         READY
P18 model_a_production_proof                  BLOCKED

commissioning_permitted=false
production_mutation_permitted=false
canary_selection_allowed=false
emergency_stop_clearance=NOT_GRANTED
control_plane=conexao_translation_automation_batch DECLARED
model_a_production_proof=NOT_PROVEN
provider.key_present=false
provider.quota=NOT_PROVEN
provider.requests_from_this_gate=0
```

**`READY 1 / BLOCKED 17 / EXPIRED 0 / INVALID 0` of 18 required.**

Evidence: `01-commissioning-readiness-report.txt`,
`02-commissioning-readiness-report.json`.

---

## Prerequisite registry

One registry, in `scripts/lib/commissioning.py`. Nothing re-declares it. Each entry
carries a stable ID, description, required/optional status, evidence source,
validator, freshness rule, secret-safety rule, failure reason and status.

| ID | Key | Evidence source | Freshness | Stage 12 item | Status |
|---|---|---|---|---|---|
| P01 | `production_access` | operator_authorization | 24 h | 1 | BLOCKED |
| P02 | `install_authorization` | operator_authorization | 24 h | 1 | BLOCKED |
| P03 | `provider_credential` | local_environment | live | 7 | BLOCKED |
| P04 | `provider_quota` | live_provider_smoke | 6 h | 7 | BLOCKED |
| P05 | `live_smoke_authorization` | operator_authorization | 24 h | 7 | BLOCKED |
| P06 | `provider_smoke_success` | live_provider_smoke | 6 h | 7 | BLOCKED |
| P07 | `human_reviewer` | human_review | 7 d | 12 | BLOCKED |
| P08 | `canary_authorization` | operator_authorization | 24 h | 12 | BLOCKED |
| P09 | `apply_authorization` | operator_authorization | 24 h | 12 | BLOCKED |
| P10 | `commissioning_authorization` | operator_authorization | 24 h | 2, 3 | BLOCKED |
| P11 | `batch_control_commissioning_authorization` | operator_authorization | 24 h | 5, 15 | BLOCKED |
| P12 | `fresh_baseline_readiness` | live_production_read | 1 h | 8, 18 | BLOCKED |
| P13 | `legacy_endpoint_verification` | live_production_read | 1 h | 4 | BLOCKED |
| P14 | `proof_trigger_verification` | live_production_read | 1 h | 9, 10, 11 | BLOCKED |
| P15 | `emergency_stop_readiness` | repository_artifact | pinned | 6 | BLOCKED |
| P16 | `audit_readiness` | live_production_read | 1 h | 14, 17 | BLOCKED |
| P17 | `release_readiness` | repository_artifact | pinned | 3, 16 | **READY** |
| P18 | `model_a_production_proof` | live_production_read | 1 h | 13 | BLOCKED |

**The Stage 12 mapping is total**: the union of every entry's `stage12_ref` equals
exactly Stage 12 items 1–18, asserted by the permanent gate. No count was invented
and no entry is orphaned.

**Freshness is per volatility class, not one invented TTL.** An operator decision
goes stale in a day; production state in an hour; a provider result in six hours; a
reviewer identity in a week; repository facts are pinned to the commit rather than
to a clock. A stale record yields `EXPIRED`, never success.

**P17 is READY and that is honest.** It is decidable entirely from this repository,
and it proves the *artifacts*, not the deployment. **P15 is BLOCKED even though its
artifact half is proven**, because its production half is not observable from a
credential-independent check. "The code is right" is never "production is ready".

**No boolean-only readiness.** There is no `ready = true` and no `auto_ready`. The
aggregate is a pure function of the eighteen results plus four integrity
invariants, and every prerequisite stays independently visible in the report.

Evidence: `09-prerequisite-registry.txt`.

---

## Authorization separation

| Permission | Evidence type | Never implies |
|---|---|---|
| `INSTALL_AUTHORIZED` | `install_authorization` | provider-call, apply |
| `PROVIDER_CALL_AUTHORIZED` | `live_smoke_authorization` | install, apply |
| `CANARY_APPLY_AUTHORIZED` | `apply_authorization` | install, provider-call |

Independence is **structural, not a convention**. Each permission is bound to its
own evidence *type*, and the binding is one-to-one in a single table. An
`install_authorization` document is looked up only under
`install_authorization`, so it can never satisfy another flag. An object whose
`type` does not match the prerequisite is `INVALID` — **refused, not coerced** —
which is precisely what makes installation authorization and apply authorization
impossible to confuse.

Each authorization object must carry `type`, `scope`, `granted_at`, `expires_at`,
`operator` and `operator_confirmed`. **No credential is ever stored in one**: an
object carrying a secret is refused by `assert_no_secrets()` *before any field is
read*, so it is never partially consumed.

**Expiry uses the server-side runtime clock**, never a client timestamp. An expired
authorization is `EXPIRED` and yields **NO PRODUCTION ACTION**.

Proven by testing all nine ordered pairs: an A object grants A and leaves B false;
a B object relabelled as A is refused. The same separation is proven for
commissioning vs installation and canary vs apply. All three flags are `false` in
this environment.

Evidence: `06-authorization-separation.txt`.

---

## Credential safety

**Presence is verified; the value is never read.** The CLI collects
`{name for name in os.environ if name.strip()}` — variable *names* only. A value
cannot reach the report because it is never read, so no value, prefix, suffix,
length, hash, masked form or environment dump is possible. A negative proof places
a provider-key-shaped canary value in the environment and asserts it is absent
from the report.

**Only the designated variable counts.** `Provider_Config::CREDENTIAL_ENV` is
`CONEXAO_TRANSLATION_PROVIDER_KEY`. An unrelated `OPENAI_API_KEY` **is present in
this shell** and the report says so explicitly: it was not read, not used, not
aliased, not exported. Proof 3 shows the gate blocking when only that variable
exists.

**Local Docker credentials were not repurposed.** The readiness script never opens
`.env` at all, so it cannot repurpose those credentials even by mistake.

**The report is checked on the way out.** `evaluate()` runs `assert_no_secrets()`
before returning, so no caller can bypass it. This is not theoretical: the boundary
caught **three genuine mistakes in the readiness report's own key names** during
development (`authorizations`, `provider.credential_present`,
`site.is_authorization`), each of which is a credential-key shape. That is the
evidence that it runs on the way OUT.

**The boundary is stricter than the PHP mirror, deliberately.** PHP
`looks_like_a_credential()` recognises only the four-group word shape and would
pass an `sk-` key, a PEM block or an `Authorization` value. The Python boundary
tests those too. The PHP gap is recorded as Stage 14 work rather than fixed here,
because tightening shipped plugin code mid-stage would change the very release
artifacts this stage is verifying.

Evidence: `05-environment-and-credentials.txt`, `08-negative-proofs.txt`.

---

## Artifact readiness

Built **twice** from the same tree; hashes identical.

| Plugin | Version | SHA-256 (build 1 = build 2) |
|---|---|---|
| `conexao-translation-rollout` | `1.2.0` | `73b3aee368712397e4ddbb496d8915bb6021443ab7e75bedf345e9fa4ad35215` |
| `conexao-translation-automation` | `0.4.0` | `f4e62bc59aa509fc0bd51037709f788f024579afc80c7851d0c1e8c5cb7045f1` |

| Check | Result |
|---|---|
| Two builds byte-identical | **YES** |
| Header == registry == `dist/release.json` | **YES** |
| Registry check (`generate-registry-docs --check`) | **OK**, 15 plugins, 24 regions, zero writes |
| Zero secrets / zero tests / zero local-Docker files | **0 / 0 / 0** |

**Batch ceilings — the ACTUAL configured limits, parsed from `Batch_Limits`:**
`SERVER_CEILING_BATCH_RECORDS 5/5`, `MAX_RECORDS_PER_STAGE 5/5`,
`MAX_OPERATIONS_PER_BATCH 5/5`, `MAX_TOTAL_PROVIDER_CALLS 20/20`,
`MAX_RETRIES_PER_RECORD 1/1`, `MAX_ATTEMPTS_PER_RECORD 2/2`,
`MAX_EXECUTION_SECONDS 300/300`, `MAX_PRODUCTION_BATCHES_PER_INVOCATION 1/1` — all
within. A missing or malformed limit **fails closed** (proof 16c), and no request
parameter can raise one (proof 22c).

**Engine integrity:** `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912`
before = after, recomputed directly from the checked-out tree. **Stage 13 modified
no file under `wp-content/`.** Proof 22 shows the aggregate forced to `BLOCKED` if
that digest ever drifts.

Evidence: `07-artifact-and-engine-integrity.txt`.

---

## Safety

Why readiness evaluation cannot mutate production or invoke the provider:

1. **The boundary is an object, not a promise.** The engine has no network client,
   no provider transport, no WordPress loader, no subprocess and no writer.
   Validators receive an `Observation` and nothing else. There is no client to
   point at production even if someone wanted to.
2. **No provider call, ever.** "Credential present" (P03) and "live smoke passed"
   (P06) are separate prerequisites with separate evidence, so a readiness probe
   can never spend budget. Reported as `provider.requests_from_this_gate = 0`.
3. **No production-write capability.** 25 production-write tokens and 17
   provider/mutation tokens are absent from both files, scanned on *code* with
   comments and string literals stripped — so the scan tests capability, not
   spelling. No `--apply`, no `mode=apply`, no production write confirmation.
4. **The only write is a temp dir.** The double build is confined to a
   `TemporaryDirectory` that is removed; every subprocess is allow-listed and none
   uses `shell=True`.
5. **The emergency stop cannot be moved.** `emergency_stop_clearance` is a constant
   `NOT_GRANTED`. Proof 17 injects a forged clearance; the report is unchanged.
6. **The control plane stays `DECLARED`.** `is_commissioned()` returns `false` and
   the bootstrap call site is still commented out. Stage 13 did not commission it
   and cannot: that is a production change requiring P11.
7. **Neither `COMMISSIONING` nor `COMMISSIONED` can be emitted** by this stage.

Evidence: `10-safety-properties.txt`.

---

## Negative proofs

**34 proven, 0 missed.** The positive control (a fully-evidenced environment) is
asserted `READY` *first*, because a proof that passes against an already-red
baseline proves nothing.

| # | Injected failure | Result | `production_mutation_permitted` |
|---|---|---|---|
| 1 | missing production credential | BLOCKED | false |
| 2 | local Docker credential substituted | **INVALID** | false |
| 3 | unrelated `OPENAI_API_KEY` substituted | BLOCKED | false |
| 4 | missing provider credential | BLOCKED | false |
| 5 | missing provider quota proof | BLOCKED | false |
| 6 | missing installation authorization | BLOCKED | false |
| 7 | missing provider-call authorization | BLOCKED | false |
| 8 | missing human reviewer | BLOCKED | false |
| 9 | missing canary authorization | BLOCKED | false |
| 10 | missing apply authorization | BLOCKED | false |
| 11 | stale authorization | EXPIRED | false |
| 12 | expired authorization | EXPIRED | false |
| 13 | stale production baseline | EXPIRED | false |
| 14 | missing legacy 410 production proof | BLOCKED | false |
| 15 | missing production Model A proof | BLOCKED | false |
| 16 | batch limit over ceiling | BLOCKED | false |
| 17 | emergency stop cleared by client input | clearance `NOT_GRANTED`, 0 clears | false |
| 16b | `SERVER_CEILING_BATCH_RECORDS` 5 → 500 | REJECTED | false |
| 16c | a batch limit constant deleted | REJECTED (fails closed) | false |
| 18 | `is_commissioned()` → `true` | REJECTED | false |
| 18b | bootstrap `register()` uncommented | REJECTED | false |
| 19 | a real `wp_insert_post` call in the engine | REJECTED | false |
| 20 | a real `wp_remote_post` call in the engine | REJECTED | false |
| 21 | a real `pll_set_post_language` call in the preflight | REJECTED | false |
| 22 | engine digest drift | BLOCKED | false |
| 22b | a real `Emergency_Stop.set` call in the engine | REJECTED | false |
| 22c | batch size raisable via `$_REQUEST` | REJECTED | false |
| — | 7 injected secrets (sk- key, app password, nonce, cookie, auth header, PEM, secret-named key) | all REFUSED | false |

Two details worth stating. **#2 is asserted as `INVALID`, not `BLOCKED`**: a
repository artifact presented as a production channel is evidence that *conflicts*
with what is required, and asserting the precise status stops a gate that merely
went red for the wrong reason from passing. **The structural proofs inject real
CALLS, not quoted strings**, because the capability scan runs on code with literals
stripped — injecting `return 'wp_insert_post'` would have tested the stripper, not
the gate.

Four gate bugs were found and fixed by these proofs, each a real defect rather than
a test artefact: a `SERVER_CEILING_BATCH_RECORDS` gap that let the server ceiling go
unchecked; a `structural-only` mode that inherited the parent's failures; a
capability scan that a rename could evade; and a secret scanner that fired on
legitimate code (`CREDENTIAL_ENV`, `FAILURE_NONCE`).

Evidence: `08-negative-proofs.txt`.

---

## Tests

| Command | Result |
|---|---|
| `python3 tests/scripts/verify-stage13-commissioning-readiness.py` | **768 passed, 0 failed**; 34 proofs, 0 missed |
| `./scripts/verify-commissioning-readiness.py` | `BLOCKED`, exit 1, mutation `false` |
| `verify-stage7-commissioning.py` | 73 passed, 0 failed |
| `verify-stage8-control-plane.py` | 149 passed, 0 failed |
| `verify-stage8-release-consistency.py` | 52 passed, 0 failed |
| `verify-stage10-batch-boundary.py` | 20 negative proofs, 0 missed |
| `./scripts/run-tests.sh` | in-process **79/77/2**, assertions **5993/6**, script **15/14/1**, acceptance **3/2/1** |
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| `php scripts/generate-registry-docs.php --check` | **OK** — 15 plugins, 24 regions, zero writes |
| `tests/scripts/verify-release-integrity.py` | 229 passed, 0 failed |
| `tests/scripts/verify-documentation-drift.py` | 14 passed, 0 failed |
| `tests/scripts/verify-agent-governance.py` | 357 passed, 0 failed |
| `python3 scripts/verify-permanent-gates.py` | 6 of 7 pass; `i18n_freshness` FAIL (pre-existing) |

The new gate is discovered by convention (`tests/scripts/verify-*.py`), so it is
blocking in CI with no workflow change. The script-contract layer grew from 14 to
15 suites and its failure count stayed at 1.

**No new failures.** The failing set is byte-identical to Stage 11's and Stage 12's.

Evidence: `11-tests-and-baseline.txt`.

---

## Engine integrity

| | SHA-256 |
|---|---|
| **Stage 13 starting digest** | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |
| **Stage 13 ending digest** | `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912` |
| **Identical** | **YES** |

Recomputed directly from the checked-out repository, not copied from the Stage 12
report. **Stage 13 modified no file under `wp-content/`**, which the unchanged
release-artifact hashes independently confirm.

---

## Production

### No production changes

| Category | Count |
|---|---|
| Plugins installed / promoted | **0** |
| Plugins activated / deactivated | **0** |
| Content records created / updated / deleted | **0** |
| PT records touched | **0** |
| EN records created or updated | **0** |
| Taxonomy terms changed | **0** |
| Polylang relationships changed | **0** |
| Options written (including the emergency stop) | **0** |
| Menus changed | **0** |
| Routes / endpoints added or removed | **0** |
| Cron jobs registered | **0** |
| Provider requests | **0** |
| Deployments | **0** |

No production credential was used, requested, inferred or printed. The local Docker
credentials in `.env` were not reused. An unrelated `OPENAI_API_KEY` was not
aliased. Site reachability was not treated as authorization. The Flutter/mobile
repository was not accessed.

---

## Existing conditions

Carried forward **without masking and without reclassification**:

1. **`i18n_freshness` — 3 stale catalogues** (`conexao-br-irlanda` lag 92483 s,
   `conexao-content` 76025 s, `conexao-event-runtime` 76025 s). **Not regenerated**
   to clear the condition. `conexao-translation-automation` is fresh.
2. **Newsletter `en_id 509`** — `pt_id=8 → en_id=509` does not resolve to the real
   linked EN newsletter (38 passed / 1 failed).
3. **Leisure-card excerpt failures** — 5 assertions
   (`test-leisure-card-excerpt-language.php`).
4. **HTTP acceptance** — `pt-lazer-card-excerpt-stays-portuguese /lazer/` is missing
   the *"Casa rural restaurada aos pés da montanha Keadeen"* fragment, the
   `dwyer-mcallister-cottage` item.
5. **Stage 2 cache-scoping exemption** — unchanged; `cache_scoping` 9/0 and
   `cache_scoping_static` 2/0 remain green.
6. **Stage 12 production commissioning blockers** — all still absent; now tracked
   as named prerequisites rather than prose.
7. **Six historic PT restorations** — still pending live production verification.
   Not re-verified, and not assumed to still hold.

Nothing was regenerated, relaxed, reclassified or weakened to obtain this status.

---

## Remaining conditions

Only genuine **external** prerequisites — the things Stage 13 cannot supply:

1. Production deployment credential and an authorized admin channel (**P01**).
2. Installation authorization (**P02**) and commissioning authorization (**P10**).
3. `CONEXAO_TRANSLATION_PROVIDER_KEY` through the approved env-only mechanism
   (**P03**).
4. Provider quota proven by a live authorized smoke (**P04**).
5. Live provider smoke authorization (**P05**) and one `RESULT: OK` (**P06**).
6. A named human reviewer (**P07**).
7. Canary authorization (**P08**) and separate apply authorization (**P09**).
8. Batch-control commissioning authorization (**P11**).
9. A fresh production baseline including the six PT restorations (**P12**).
10. Production `410` verification (**P13**) and proof-trigger verification
    (**P14**).
11. Production initial stop state observed (**P15**) and audit storage/retention
    verified (**P16**).
12. A production canary demonstrating `approved scope == executed scope`
    (**P18**).

**P17 is already `READY`** — it needs nothing external.

---

## Stage 14 prerequisites

Requirements for the actual production commissioning run, **once the gate reports
`READY`**:

1. **An explicit, separate authorization to act on this report.** `READY` is a
   statement about evidence, not permission to begin. Nothing in this repository
   converts one into the other.
2. **Re-run `./scripts/verify-commissioning-readiness.py` in the commissioning
   environment**, with the evidence directory supplied (`--evidence <dir>`) and the
   production credentials actually present. A `READY` verdict obtained in a
   different environment does not carry over.
3. **Confirm every authorization is still current at that moment.** Authorizations
   expire (24 h). A `READY` report older than the shortest budget is `EXPIRED`, not
   `READY`.
4. **Acquire the existing site-wide production lock** before any mutation. Stage 13
   took no lock because it mutates nothing; Stage 14 must.
5. **Execute the generated runbook in order** (`03-operator-runbook.md`, 19 steps),
   as nineteen separate actions. Do not combine them into one command.
6. **Verify the production `410` and the control plane in a valid authenticated
   context**, with no engine call and no mutation, before anything else runs.
7. **Run exactly one live provider smoke**, authorized separately, and record
   `RESULT: OK` with its result digest.
8. **Capture a fresh baseline after deployment**, re-verifying the six historical PT
   restorations explicitly rather than assuming they still hold.
9. **Select the canary explicitly** — the gate only reports
   `canary_selection_allowed`; selection remains a human action.
10. **Obtain a separate apply authorization** for the reviewed canary, with a
    digest-bound `Approval::verify()` recomputation.
11. **Leave the emergency stop `STOPPED` and bulk automation OFF.** Clearing the
    stop is a separate authenticated operator action with an audit record; cron
    stays off; autonomous approval stays off; limits are not raised automatically.
12. **Commission the batch-control surface only as a reviewed change** to
    `is_commissioned()` and the bootstrap call site, with the registry updated from
    the one source.
13. **Record the Model A production proof** (`approved scope == executed scope`,
    `approved = planned = executed = 1`) or report `NOT PROVEN` and stop.
14. **Fix the PHP `assert_no_secrets()` gap identified in Stage 13** — it recognises
    only the four-group word shape. This is a plugin change, so it needs its own
    version bump and a fresh deterministic build.

---

## Evidence

`docs/evidence/2026-10-01-stage-13-commissioning-readiness/`

| File | Content |
|---|---|
| `01-commissioning-readiness-report.txt` | The aggregate readiness report |
| `02-commissioning-readiness-report.json` | The same, machine-readable |
| `03-operator-runbook.md` | The 19-step runbook, generated from the registry |
| `04-permanent-gate-stage13.txt` | §32 the permanent gate, 768 assertions and 34 proofs |
| `05-environment-and-credentials.txt` | Environment presence, the `OPENAI_API_KEY` refusal, the unopened `.env` |
| `06-authorization-separation.txt` | §7 the separation table and its proofs |
| `07-artifact-and-engine-integrity.txt` | §16/§17 both builds, hashes, scans, ceilings, control plane |
| `08-negative-proofs.txt` | §33 every injected failure and its result |
| `09-prerequisite-registry.txt` | §5/§6 the registry, the Stage 12 mapping, current status |
| `10-safety-properties.txt` | Why readiness cannot mutate production or call the provider |
| `11-tests-and-baseline.txt` | §35/§34 every command, result and known condition |

No file in this directory contains a credential, an application password, a nonce,
a cookie, an authorization header, a `.env` file or an environment dump. Every
file was scanned for secret shapes.

_Method note: the four gate defects listed under "Negative proofs" were found by
running these proofs, not by inspection. Each is recorded because a gate that has
never rejected anything has not been shown to work._
