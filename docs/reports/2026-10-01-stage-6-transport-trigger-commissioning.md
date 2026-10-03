# Stage 6 — Transport Contract Fix and Protected Production Trigger

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `27c06c956a38543d63180574caddc80221ab2c9c`
**Status:** `PASS WITH CONDITIONS`
**Production writes:** `0` — no plugin was installed, uploaded or activated; no production route, cron job, option, content record or Polylang relationship was created or modified. **Production was never contacted.**

Stage 5 ended `BLOCKED` with four distinct problems: a provider transport
defect (A), provider quota (B), production access (C) and a missing production
entry point (D). This stage fixes **A** and **D** in the repository, and
leaves **B** and **C** exactly where they were, because both are external.

---

## 1. First gate — integrity captured before any change

Recorded in `docs/evidence/2026-10-01-stage-6-transport-trigger-commissioning/01-integrity-before.txt`.

| Fact | Value |
|---|---|
| Working tree before | clean (no uncommitted changes) |
| Branch / HEAD | `i18n` / `27c06c956a38543d63180574caddc80221ab2c9c` |
| Engine SHA-256 | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| Expected baseline | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| Verdict | **EXACT EQUALITY** — no drift, nothing to repair |

---

## 2. The transport defect, and exactly how it was corrected

### What was wrong

`wp_remote_post()` does not return a flat `{ code, body }`. It returns:

```
headers, body, response: { code, message }, cookies, filename, http_response
```

The HTTP status lives at **`response.code`**. Stage 4/5's
`Provider_OpenAI::normalise()` read a **top-level** `code`, so on the real
WordPress transport path `isset( $response['code'] )` was always false and
every live call returned `conexao_automation_provider_bad_transport`. The
provider could not have succeeded in production, ever.

The reason this survived four stages is the more interesting finding: **every
Stage 4 test double returned the flat shape**, so the suite agreed with the
defect instead of contradicting it. The tests were internally consistent and
externally wrong.

### What was corrected

`normalise()` now implements the actual WordPress contract:

| Field | Used | Reason |
|---|---|---|
| `response.code` | yes | the only trustworthy HTTP status |
| `body` | yes | the JSON envelope the validator judges |
| `response.message` | no | human status line; risks echoing an upstream error page |
| `headers` | no | nothing in this contract needs one; `set-cookie`/`authorization` must never be retained |
| `cookies`, `filename`, `http_response` | no | transport bookkeeping with no use here |

Each malformed shape now has its **own** failure category, so an operator can
tell a DNS failure from a truncated response from a missing status:

| Input | Category |
|---|---|
| not an array / not a `WP_Error` | `conexao_automation_provider_bad_transport` |
| `response` absent or not an array | `conexao_automation_provider_missing_response` |
| `response.code` absent or non-numeric | `conexao_automation_provider_missing_status` |
| `body` absent or non-string | `conexao_automation_provider_missing_body` |
| `WP_Error` | passed through untouched (retryability preserved) |
| body present but empty / invalid JSON | `invalid_response` envelope (unchanged Stage 4 behaviour) |

**The fix was made in the provider's transport normalisation boundary only.**
The shared engine was not touched, and no validation was weakened: a real
response shape carrying a wrong payload is still refused by the Stage 4
validator (incomplete fields, wrong source identity, wrong source digest and
extra keys are all still refusals).

### Quota is now distinguished from rate limiting

`429` is classified by the **vendor's own signal**, not guessed:

| Case | Classification | Retried? |
|---|---|---|
| `429` + `error.code/type = insufficient_quota` | `conexao_automation_provider_insufficient_quota` | **No** — returns immediately, costing exactly ONE provider call |
| `429` + any other error code | retryable rate limit | Yes, up to the ceiling |
| `401` / `403` / `400` | permanent | **No** — a wrong key stays wrong |
| `500` / `503` | retryable upstream | Yes |
| unknown status | hard failure | **No** |

The quota check runs **before** the status classification, which is what makes
it non-retryable.

---

## 3. Regression proof — the test fails against Stage 5 and passes against Stage 6

The dedicated suite `tests/test-automation-transport-shape.php` was run twice
against the same code, changing only the provider file
(`docs/evidence/.../03-transport-regression-proof.txt`):

```
--- provider reverted to Stage 5 (git show HEAD:...) ---
  FAIL: a real wp_remote_post() shape is NOT refused as a transport defect (got conexao_automation_provider_bad_transport)
  FAIL: the regression case returned a usable provider response — The provider transport returned neither a response nor an error.
  FAIL: 200 in the real shape normalises to a success
  FAIL: a 429 insufficient_quota is classified as quota exhaustion, not a transport defect — expected '...insufficient_quota', got '...bad_transport'

--- provider restored to Stage 6 ---
stage 6 transport regression: 64 passed, 0 failed
```

The regression test asserts the **actual HTTP code, body, parsed translations
and final validated result** — not merely the absence of `bad_transport`.

---

## 4. The protected production trigger

New file:
`wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-admin-trigger.php`

Registered as `admin_post_{ACTION}` plus a Tools screen
(**Tools → Translation Automation**), following the repository's existing
admin-operator convention (`conexao-blog-translation`, `conexao-event-importer`,
`conexao-admin-ux`).

### The chain, in order

```
admin request
  -> is_post()            1. POST only
  -> is_authenticated()   2. authentication
  -> has_capability()     3. manage_options
  -> verify_nonce()       4. nonce (own action, own field)
  -> validate_request()   5. mode / stage / field allow-list / registry
  -> Trigger::fire()      6. the EXISTING orchestrator, PROOF only
       -> lock -> inventory -> diff -> provider -> validator
       -> plan -> dry-run -> gate -> result -> audit
  -> safe_result()        7. secret-scrubbed projection
```

Steps 1–5 complete **before** the orchestrator is reached. Every
authorisation-failure case is paired with an **orchestrator-invocation counter**
asserting it stays at zero, so "refused" and "never reached the engine" are
proven separately.

### Apply is unreachable, structurally

The mode vocabulary has exactly **one** member, `proof`. `apply` is not a
disabled flag — it is *not a mode*, so it is rejected by the same
unknown-value branch as any other string. `dry_run` is not an accepted request
field, so supplying it is itself a refusal. There is no `?mode=apply` escape
hatch and no alternate spelling.

### Environment is server-derived

`environment` is read from `wp_get_environment_type()`, never from the
request, and it is not in the accepted field allow-list at all — supplying it
is a refusal. The `bootstrap` flag is hard-coded `false`, so this entry point
cannot adopt a baseline.

---

## 5. Safety — why the trigger cannot reach apply

| Property | How it is enforced |
|---|---|
| No `MODE_APPLY` branch | one-value mode vocabulary; `MODE_APPLY` never appears in the file |
| No direct mutation | no `wp_insert_post` / `wp_update_post` / `update_post_meta` / `pll_*` |
| No provider call | the provider is reachable only via the orchestrator's plan stage |
| No second engine | `Conexao_Translation_Rollout_Engine` is never referenced here |
| One orchestration path | `Trigger::fire()` is called exactly once |
| Uses the existing lock | the Stage 2 site-wide lock; ownership is preserved, released in `finally` |
| Uses the existing audit | `Audit::build_record()` → `assert_no_secrets()` → persisted trail |
| No public route | no `rest_api_init`, `register_rest_route` |
| No anonymous surface | no `admin_post_nopriv_*`, no `wp_ajax_*`, no `__return_true` |
| No cron | no `wp_schedule_*`; B2 remains an explicit-invocation architecture |
| Stage allowlist | delegates to the orchestrator; registry drift fails closed |

### The trigger response

Exposes: run id, mode, stage, status, `mutation_permitted`,
`mutation_occurred`, gate verdict, failure category, bounded counts and
digests. Does **not** expose: any credential, authorization header, raw
environment, provider payload, `$_POST`, nonce value or engine report body.
`assert_no_secrets()` runs on the result boundary, and inventory/change-set
listings are reduced to counts, because a full listing on a proof screen is a
bulk content dump nobody needs to verify a dry run.

---

## 6. Audit

Every trigger invocation produces an audit record through the **existing**
`Audit::build_record()` → `assert_no_secrets()` → `Audit::record()` path. The
record carries: run id, invocation source (`admin_proof`, a new
`Audit::TRIGGER_ADMIN_PROOF` constant), stage, mode, environment, source
inventory digest, change-set digest, manifest/plan/snapshot digests, gate,
dry-run status, provider status, `mutation_permitted`, `mutation_occurred` and
the final status/failure.

Not stored, by construction: passwords, application passwords, API keys,
nonces, cookies, authorization headers, raw request payloads and operator
identity. Operator identity is **not** retained — the trail records *that* a
protected admin proof ran and its verdicts, which is what an audit needs,
without keeping a per-operator behavioural record.

---

## 7. Tests

| Suite | Result |
|---|---|
| `test-automation-transport-shape.php` (new) | **64 passed, 0 failed** |
| `test-automation-admin-trigger.php` (new) | **109 passed, 0 failed** |
| `test-automation-provider-implementation.php` (doubles → real shape) | **144 passed, 0 failed** |
| `test-automation-boundary.php` | **144 passed, 0 failed** |
| `test-automation-trigger.php` | **101 passed, 0 failed** |
| `test-automation-promotion.php` | **16 passed, 0 failed** |
| `verify-stage6-transport-trigger.py` (new gate) | **48 passed, 0 failed** |
| `verify-stage3-automation.py` | **129 passed, 0 failed** |
| `verify-stage4-provider.py` | **115 passed, 0 failed** |
| `./scripts/lint.sh` | **OK** (syntax clean, no new PHPCS, PHPStan clean) |

### The Stage 4 transport matrix, in the real shape

| Input | Expected | Result |
|---|---|---|
| Valid shape, 200 | normalised success | pass |
| Valid shape, 429 `insufficient_quota` | quota classification, 1 call | pass |
| Valid shape, 429 rate limit | retryable, attempts exhausted | pass |
| Valid shape, 400 | permanent, 1 call | pass |
| Valid shape, 401 | permanent, 1 call | pass |
| Valid shape, 403 | permanent, 1 call | pass |
| Valid shape, 500 | retryable | pass |
| Valid shape, 503 | retryable | pass |
| `WP_Error` retryable | retried, then exhausted | pass |
| `WP_Error` non-retryable | passed through, 1 call | pass |
| Missing `response` | `missing_response` | pass |
| Missing `response.code` | `missing_status` | pass |
| Non-numeric `response.code` | `missing_status` | pass |
| Missing body | `missing_body` | pass |
| Invalid JSON body | `invalid_response` | pass |
| Truncated body | `invalid_response` | pass |
| Empty body | `invalid_response` | pass |
| Flat `{code, body}` double | refused | pass |

### Superseeded assertions (narrowed, never dropped)

Three Stage 3/4 invariants asserted "no `admin_post_` at all". Stage 6 adds
exactly one authenticated endpoint, so each was **narrowed** to preserve the
property it protected:

- `admin_post_nopriv_*`, `wp_ajax_*`, `register_rest_route`, `rest_api_init`
  and all cron calls remain **forbidden in every file**;
- `admin_post_` / `admin_menu` are permitted in **exactly one file**, asserted
  by count *and* by filename.

This follows the repository's existing `STAGE 4 SUPERSESSION` precedent.

---

## 8. Engine integrity

| | SHA-256 |
|---|---|
| Before | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| After | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |

`git diff` over `wp-content/plugins/conexao-translation-rollout` is **empty**.
The defect was corrected in the provider's transport boundary, exactly where it
belonged; the engine was not modified to accommodate it.

---

## 9. Existing conditions carried forward (not masked, not "fixed")

| Condition | State |
|---|---|
| `i18n_freshness` | **still failing** — 3 stale catalogues, deliberately **not** regenerated to buy a green run |
| `dwyer-mcallister-cottage` | still failing routing row (PT-drift) |
| newsletter `en_id 509` | `test-en-jobs-shared-slug.php` still 1 failure |
| leisure-card excerpt failures | `test-leisure-card-excerpt-language.php` still 5 failures, `missing_en = 1` |
| cache-scoping exemption | unchanged; gate still green |

No unrelated code was changed to make any of these pass.

---

## 10. Environmental blockers — unchanged, and not convertible to code

| Blocker | Class | Status |
|---|---|---|
| Provider quota (`429 insufficient_quota`) | `REQUIRES EXTERNAL PROVIDER ACCOUNT ACTION` | Unchanged. Correctly classified as **non-retryable**, and explicitly **not** reported as an application defect. |
| Production deployment credential | `REQUIRES OPERATOR ACTION` | Unchanged. `WP_USERNAME` / `WP_APPLICATION_PASSWORD` unset. No deployment channel was invented. |
| Production installation | `REQUIRES OPERATOR ACTION` | Unchanged. Both plugins remain uninstalled in production. |
| Production trigger verification | `REQUIRES OPERATOR ACTION` | Unchanged. The trigger exists in code but is not deployed. |

No live provider call was made in this stage: `CONEXAO_TRANSLATION_PROVIDER_KEY`
is absent from the environment and from `.env`. The gated smoke test skips
cleanly and exits 0.

---

## 11. Production actions (operator-controlled — NOT performed here)

1. Build artifacts (`./scripts/build-plugins-zip.sh`).
2. Upload `conexao-translation-rollout` and activate it.
3. Upload `conexao-translation-automation` and activate it.
4. Provide `CONEXAO_TRANSLATION_PROVIDER_KEY` as a runtime/environment secret.
   **Never** as a `wp_options` row.
5. Verify the admin trigger exists at **Tools → Translation Automation**.
6. Capture a **fresh** production baseline (see below). Do not reuse an old one.
7. Run the dry-run proof only. Expect `mutation_occurred=false`.
8. Perform the live provider proof. Expect `RESULT: OK` or a factual failure
   classification.
9. **STOP.**

### Fresh production baseline procedure (read-only, once installed)

Capture: PT inventory digest; per-object translation digests; EN/B1 state; B2
field state; taxonomy/relationship state; plugin state; route inventory; cron
state; audit state; persisted source state.

Then prove, in order:

```
bootstrap_required  -> no mutation   (missing baseline must NOT translate anything)
baseline established
no_changes / actionable = 0          (for an unchanged state)
```

The six authorised PT restorations from earlier stages are a **historical
note, not current truth** — the fresh baseline must re-derive them.

### Dry-run production proof (once deployed and explicitly authorised)

The first trigger test must verify authorisation, nonce, lock, inventory, diff,
provider path, validator, plan, dry-run, gate and audit. Expected:
`mutation_occurred=false`, no content changes.

A provider failure must result in **no WordPress mutation** plus an audit entry.

### Stop at REVIEW_REQUIRED

A validated provider response is **not** human approval. The dry-run stops at
`REVIEW_REQUIRED`; no default automatic approval exists, and the plan carries
no default-approve path.

---

## 12. Remaining conditions (genuine blockers only)

1. Provider quota exhausted → `REQUIRES EXTERNAL PROVIDER ACCOUNT ACTION`.
2. Production credential absent → `REQUIRES OPERATOR ACTION`.
3. Plugins not installed in production → `REQUIRES OPERATOR ACTION`.
4. Fresh baseline not captured → `REQUIRES OPERATOR ACTION`.
5. Trigger not deployed or verified → `REQUIRES OPERATOR ACTION`.

---

## 13. Stage 7 prerequisites

Before the first real production canary, **all** of the following must hold:

1. Provider quota available; a live call returns `RESULT: OK`.
2. Production deployment credential intentionally provided via the env-only
   mechanism.
3. Operator has installed and activated both plugins.
4. `CONEXAO_TRANSLATION_PROVIDER_KEY` provided as a runtime secret.
5. Fresh production baseline captured, proving `bootstrap_required → no
   mutation`.
6. Protected dry-run trigger verified, `mutation_occurred=false`.
7. Human review of the dry-run plan completed.
8. **Separate, explicit authorisation for the canary itself.**

**Production translation remains `OFF` until every item above is satisfied.**
None of them is combined into a single automatic execution.

---

## 14. Final safety invariant

| Property | State |
|---|---|
| PT canonical content | **unchanged** |
| Production EN content | **unchanged** |
| Provider | capable, **not autonomous** |
| Trigger | **proof / dry-run only** |
| Apply | **inaccessible** from the production trigger |
| Bulk translation | **OFF** |
| Cron | **none** |
| Public REST | **none** |
| Flutter/mobile repository | **untouched** |

### Evidence

- `01-integrity-before.txt` — the first gate
- `02-test-baseline-before.txt` — full baseline test run
- `03-transport-regression-proof.txt` — Stage 5 fails / Stage 6 passes
- `04-final-verification.txt` — final suite, lint, registry, engine digest