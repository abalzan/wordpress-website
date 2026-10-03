# Report — Stage 5: Production commissioning and canary

| | |
|---|---|
| **Stage / task name** | Stage 5 — production commissioning + single-canary translation |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `078b2d8cf111a4b5d83da83cf9bee47caa158621` |
| **Final SHA** | `078b2d8cf111a4b5d83da83cf9bee47caa158621` (no commit made) |
| **Working tree at finish** | dirty — the uncommitted Stage 4 work only, plus this report and its evidence. No production file touched. |

## Stage 5 status

**`BLOCKED`**

Neither gate could be executed. Stage 5 requires crossing the production
boundary, and this environment cannot cross it. The blocking facts are
structural, not procedural, so no amount of care in sequencing would have
changed the outcome.

Three independent blockers, any one of which is sufficient:

1. **No production access channel.** Production is WordPress.com; deployment is
   a manual ZIP upload through wp-admin, with no SSH, WP-CLI, filesystem or
   database. `WP_USERNAME` and `WP_APPLICATION_PASSWORD` are **unset**, and the
   `.env` credentials are documented as local-Docker-only. An agent cannot
   install, activate, baseline, dry-run or apply.
2. **No provider credit.** A real live call reached OpenAI and was refused with
   **HTTP 429 `insufficient_quota`**. Connectivity and credential transport are
   proven; a *successful* smoke test is impossible until the account is funded.
3. **No production entry point exists.** The automation plugin has zero HTTP,
   REST, admin or cron surface — `Trigger::fire()` is an in-process PHP API
   only. Section 11 trigger commissioning is therefore **new code**, not a
   configuration step, and it could not be installed even if written.

A fourth finding, discovered while proving blocker 2, is a **real defect in the
provider transport** (§3 below) that must be fixed before any canary.

**Production writes: 0.** No plugin was installed, uploaded, activated or
scheduled. No production content, route, cron job, option, Polylang relationship
or translation was created or changed. No Flutter/mobile repository was accessed.

---

## 1. What was completed

Only work that is safe and genuinely useful without a production channel:

- **Pre-deployment source integrity (§3)** — engine digest recorded, exact match.
- **Release artifact build and verification (§4)** — deterministic, clean.
- **Precondition audit (§2)** — all twelve assessed with evidence.
- **Live provider smoke test attempt (§9)** — reached the provider; blocked on credit.
- **Full regression suite, lint, registry and release-integrity gates (§28)**.
- **A genuine provider-transport defect, proven empirically** (below).

## 2. What was NOT completed, and why

| Section | Status | Reason |
|---|---|---|
| §5 Production installation | **not tested** | No credential; manual wp-admin upload only |
| §6 Post-install verification | **not tested** | Nothing installed |
| §7 Production baseline | **not tested** | Needs authenticated production read |
| §8 Baseline init safety | **not tested** | Needs production; proven in-process only in Stage 3 |
| §9 Live provider smoke test | **failed** | HTTP 429 `insufficient_quota`; also hit the transport defect |
| §10 Provider config verification | **partial** | Constants verified locally; no production configuration exists |
| §11 Trigger commissioning | **not started** | No entry point exists; would be new code |
| §12 Production dry-run | **not tested** | Requires the trigger and a production install |
| §13 Canary selection | **not started** | Requires a production baseline |
| §14 Human review | **not started** | Requires a canary and a plan |
| §15–§18 Apply, scope, PT immutability, EN verification | **not started** | All downstream of the above |
| §19 Route verification | **not tested** | No canary |
| §20 Idempotence | **not tested** | No canary |
| §21 Stale-source concurrency | **not tested** | Needs a live provider result |
| §22 Lock verification in production | **not tested** | Needs a production invocation |
| §23 Audit verification | **not tested** | `RETENTION = 20` never exercised in production |
| §24 Provider usage accounting | **failed** | The one request returned 429; zero successful calls |
| §25 Failure behaviour | **partial** | Provider 429 observed live; the rest remain in-process only |
| §26 Rollback procedure | **required, not rehearsed** | No mutation occurred |
| §27 Bulk translation | **HOLDS — never enabled** | No cron, no public endpoint, no autonomous path exists |

## 3. Defect found: the real transport response shape is unhandled

This is the most valuable outcome of the stage, and it is a **new finding, not a
carried-forward condition**.

`Provider_OpenAI::normalise()` accepts a transport result only when
`is_array( $response ) && isset( $response['code'] )`.

A real `wp_remote_post()` return has top-level keys
`headers, body, response, cookies, filename, http_response`. The HTTP status
lives at `$response['response']['code']`. **There is no top-level `code` key.**

Verified in the container against a real return value:

## 4. Deployment (artifacts built and verified — not installed)

Built with `./scripts/build-plugins-zip.sh`; `release-manifest.py --verify`
confirms `release=v2026.09.30 artifacts=10 built=10`.

| Artifact | Version | SHA-256 | Determinism |
|---|---|---|---|
| `conexao-translation-rollout.zip` | 1.1.0 | `5b87fbc533c352197e6bf6be14b49d27537323a8370862ec7bb8031bb3d15d5b` | identical across 2 builds |
| `conexao-translation-automation.zip` | 0.2.0 | `2b4857ae372b385c00f76e19a8f1a2294da6f04efca7480e47a75906f3dd26ef` | identical across 2 builds |

Verified: `Requires Plugins: conexao-translation-rollout` present; dependency
order matches `plugins.json`; production steady state is the 6-platform-plugin
subset with the automation plugin last; **0** test files in the package; **0**
credential-shaped strings; no local development artifacts.

**Installation order when performed:** `conexao-translation-rollout` →
`conexao-translation-automation`, in place, no deactivate/reactivate cycle,
`conexao-content` untouched.

## 5. Engine integrity

| | SHA-256 |
|---|---|
| **Expected** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **Before Stage 5 work** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **After all Stage 5 work** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |

**Exact equality.** `git diff --stat wp-content/plugins/conexao-translation-rollout/`
is empty; 966 lines. The engine was not touched, merged, regenerated or repaired.

## 6. Provider

Live smoke test attempted inside the local container with the env-only
credential, a fixed non-production fixture, and no WordPress content read or
written.

| Fact | Value |
|---|---|
| Provider / model | `openai` / `gpt-4o-mini` |
| Endpoint | `https://api.openai.com/v1/chat/completions` |
| Transport reached provider | **yes** |
| Result | **HTTP 429 `insufficient_quota`** — "You have no credits remaining" |
| Smoke test verdict | `conexao_automation_provider_bad_transport` |
| Successful requests | **0** |
| Credential in WordPress state | **no** (never stored; env-only by construction) |

Proofs that *did* succeed: credentials resolve from the environment, the
request reaches the provider, and the response is parsed into an HTTP status.
Proofs that *could not* be established: validator compatibility on a real
response, digest binding over a real result, and retry behaviour under a real
429 (the 429 here was a quota refusal, not a rate limit).

## 7. Baseline, dry-run, canary, verification

## 8. Safety evidence

- **Production writes: 0.** The only production contact was anonymous,
  read-only `GET` reachability checks (`/`, `/wp-login.php`, `/wp-json/` — all 200).
- No credential was used against production, printed, logged or stored.
- No cron created; `wp_schedule_*` appears only in *test* files asserting absence.
- No public endpoint created; no `register_rest_route` in runtime code.
- No PT content, menu, taxonomy, Polylang relationship or option changed.
- No activation or deactivation performed on any plugin.
- Bulk/autonomous translation: **OFF**, and structurally unable to be ON.
- Deletion policy remains `manual_intervention_required`.
- Engine byte-identical.
- Flutter/mobile repository: **not accessed**.

## 9. Existing conditions (carried forward unchanged)

| Condition | Status in Stage 5 |
|---|---|
| `i18n_freshness` — 3 stale catalogues | **Reproduced**, unchanged. Not regenerated; unrelated to Stage 5 |
| Local-data `dwyer-mcallister-cottage` PT-drift fixture | **Reproduced** via `verify-routing-http.py` (`/lazer/` missing PT fragment) |
| Local-data newsletter `en_id 509` shared-slug | **Reproduced** via `test-en-jobs-shared-slug.php` (`pt_id 8, en_id 509`) |
| Local-data `test-leisure-card-excerpt-language.php` | **Reproduced**, 5 failures, `missing_en = 1` |
| Stage 2 cache-scoping exemption | Open; belongs to a stage permitted to change engine bytes |
| No production trigger entry point | **Still open** — now the confirmed primary implementation gap |

No unrelated code was changed to make any of these green.

## 10. Remaining conditions

Only genuine unresolved items:

1. **Provider transport defect** (§3) — blocks every live provider call.
2. **No provider billing credit** — HTTP 429 `insufficient_quota`.
3. **No production credential / access channel** — install, baseline, dry-run
   and apply are all impossible for an agent.
4. **No production entry point** — the trigger needs a new, nonce-protected,
   `manage_options`, dry-run-only admin surface that does not yet exist.
5. **Production install and activation unverified** — always operator action.
6. **Audit retention (20) unvalidated at production volume** — operator check.
7. **Six prior PT restorations unverified against live state** — needs a
   production read.

## 11. Stage 6 prerequisites

Before any bounded multi-record production translation:

1. **Fix the transport normalisation** and add a test using the real
   `wp_remote_post()` shape. This is the hard prerequisite.
2. **Fund the provider account** and re-run the gated live smoke test to a
   `RESULT: OK`, proving validator compatibility on a real response.
3. **Deliver the provider credential by the approved env-only mechanism**
   (`CONEXAO_TRANSLATION_PROVIDER_KEY`) — never an option, never a database row.
4. **Build the production trigger**: `manage_options` + nonce, explicit
   invocation, proof-mode only, no independent translation logic, no public
   route, no cron — covered by new structural tests.
5. **Operator installs and activates** engine then automation plugin in place.
6. **Capture a fresh production baseline** and prove `missing state →
   bootstrap_required → no mutation`, then a no-op reconciliation returning
   `no_changes`, `actionable = 0`.
7. **Prove the canary path on a single record** end to end, including PT
   immutability and idempotence, before widening the record set.
8. **Keep bulk/autonomous execution OFF** until multi-record behaviour is proven
   under explicit per-run authorisation.

---

## 12. Verdict

`BLOCKED` is required when production installation, provider authentication,
review/approval binding, F7 sequencing or canary verification cannot be
established safely. **All five are unestablished here** — provider
authentication reached a real endpoint but could not be completed, and the other
four were never reachable.

The stage nonetheless produced its intended safety result: **no production
mutation, engine byte-identical, bulk translation still off** — plus one
high-value defect that would otherwise have surfaced during a production canary.


**Production baseline: not captured.** Requires an authenticated production
read, which is unavailable. The Stage 0/1/2/3/4 baselines were **not** reused
as current truth, and the six prior authorised PT restorations were **not**
re-verified against live state — that verification is an operator action and is
carried forward as an open condition.

**Production dry-run: not run.** No trigger, no install, no production inventory.

**Canary: none selected and none applied.** Selection requires a fresh
production baseline; there is no candidate to review.

**PT immutability / EN B1-B2 / route / idempotence / lock / audit
verification: not tested.** All are defined relative to an applied canary that
does not exist.


```
real shape passes normalise()? NO -> bad_transport
flat double shape passes?      YES
```

Every Stage 4 test injects `Provider::set_transport( … )` returning the flat
`{ code, body }` shape, so the full suite passes — including the 110-check
`verify-stage4-provider.py` structural gate — while the real `wp_remote_post()`
path can only ever produce `conexao_automation_provider_bad_transport`.

**Impact:** with billing credit available, the first live production provider
call would still fail closed. This is exactly the gap that only a live smoke
test can expose, and it validates performing Gate A before Gate B.

Per the section 9 rule, validation was **not** relaxed to accommodate an
unexpected response. The fix belongs in the transport normalisation, with a
test that exercises the real `wp_remote_post()` shape rather than a double.
