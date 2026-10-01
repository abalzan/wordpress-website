# Stage 7 — Production Commissioning Gate

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `889200338200170bee1d6039ef60b647dc855753`
**Status: `BLOCKED`**
**Production writes: `0`.** Nothing was installed, uploaded, activated, applied or
mutated. No production credential was used, requested or printed. No provider
request was made. No plugin runtime file was changed.

Stage 6 ended `PASS WITH CONDITIONS` with two environmental blockers
(provider quota, production access) left deliberately in place. Stage 7 is the
**production commissioning gate**, and its objective was to determine whether
the system is ready for a first controlled production translation. It is not:
**three of the four §4 environmental prerequisites are missing**, and §4
forbids partial commissioning.

What this stage *did* deliver is the part that does not depend on credentials:
the **§2 supersession contract**, turned from an informal note in three test
files into one named, counted, per-action permanent gate — plus a rebuilt,
twice-proven-deterministic release artifact set.

---

## Stage 7 status

**`BLOCKED`**

Three independent prerequisites are absent: an authorized production
deployment/admin channel, the provider credential, and operator deployment
authority. Provider quota is **not testable** without the credential. Per §4,
no production mutation occurred and no commissioning was attempted.

### Supersession contract

The repository no longer asserts `no admin_post_* anywhere`. That absolute is
replaced by a single contract, enforced by a new permanent gate,
`tests/scripts/verify-stage7-commissioning.py` (discovered by convention, so
CI runs it with no workflow change):

| Rule | Enforcement |
|---|---|
| Exactly **1** approved endpoint, **by exact action name** | `admin_post_conexao_translation_automation_proof`, resolved from `add_action( 'admin_post_' . self::ACTION )` → `const ACTION` |
| Registered **exactly once** | registration count = 1 |
| In **exactly one approved file** | `includes/class-conexao-translation-automation-admin-trigger.php` |
| **0** forbidden entry surfaces, **every** file | `admin_post_nopriv_`, `wp_ajax_`, `wp_ajax_nopriv_`, `rest_api_init`, `register_rest_route`, `__return_true`, all `wp_schedule_*`/`wp_next_scheduled`/`cron_schedules` |
| **0** webhook entry points | substring scan for `webhook`, `wphook`, `inbound_hook` |
| Endpoint is not apply-capable | `MODE_APPLY` absent; action constant name-checked; capability + nonce present |

The gate asserts the endpoint **by resolved action name**, not by the mere
presence of the substring `admin_post_`. Substring matching cannot distinguish
the approved endpoint from a second, differently-named one — which is exactly
the generalization §2 forbids. A second endpoint is caught by name *and* by
count; an endpoint relocated to another file is caught by owner.

**A finding this stage deliberately did not hide.** The shared engine
`conexao-translation-rollout` ships its **own** `admin_post_` endpoint,
`admin_post_conexao_translation_rollout_run`, which **is apply-capable**
(`handle_run()` maps `mode=apply`). It predates this program (Stage H) and has
its own `current_user_can()` + `check_admin_referer()` checks, but it is a real
apply-capable production surface that anyone grepping for `admin_post_` will
find. It is **declared explicitly** in `DEFERRED_ENGINE_SURFACES` rather than
silently ignored (which would hide it) or silently removed (an unauthorized
behaviour change to a component outside Stage 7's scope). The gate fails closed
if that endpoint is renamed or a new one appears, so it cannot drift unnoticed.
**It is an operator decision for Stage 8, not a Stage 7 repair.**

### Production access

**No authorized production deployment/admin channel exists.**

| Check | Result |
|---|---|
| `WP_USERNAME` (process env) | UNSET |
| `WP_APPLICATION_PASSWORD` (process env) | UNSET |
| `CONEXAO_SITE_URL` | UNSET → `scripts/lib/rest.py` defaults to `http://localhost:8080` |
| `.env` credentials | present, but classify as the **local Docker** target, not production |
| `https://conexaobr.ie` reachability | HTTP 200 (read-only `HEAD`, no credentials) |

The site is **reachable**; reachability is **not authorization**. Production is
WordPress.com — no SSH, no WP-CLI, no filesystem — so installation is a manual
wp-admin ZIP upload, and a credential is the only way to automate it. The `.env`
values were deliberately **not** reused as a production credential, and their
contents appear nowhere in this report or the evidence.

### Deployment

**Nothing was installed. Nothing was activated.**

Artifacts were built and verified, but no upload occurred:

| Artifact | Version | SHA-256 |
|---|---|---|
| `conexao-translation-rollout.zip` | 1.1.0 | `5b87fbc533c352197e6bf6be14b49d27537323a8370862ec7bb8031bb3d15d5b` |
| `conexao-translation-automation.zip` | 0.3.0 | `b828b642323740b225e74c66a62f6b2d4ddbb642b86c57cfa3bd6f86d4b0cb9f` |

Release `v2026.10.01`, 10/10 artifacts built, 226 files, 6,364,169 bytes.
`conexao-translation-rollout` v1.1.0 declares `Requires PHP: 8.0`;
`conexao-translation-automation` v0.3.0 declares `Requires PHP: 7.4` and
`Requires Plugins: conexao-translation-rollout`. Dependency order comes from
`plugins.json` via the generated activation order: `conexao-data-model →
conexao-content → conexao-admin-ux → conexao-event-runtime →
conexao-translation-rollout → conexao-translation-automation`.

**Determinism: proven.** Two independent builds produced **10/10 byte-identical**
artifacts. `release-manifest.py --verify` → exit 0;
`verify-release-integrity.py` → **229 passed, 0 failed**.

**A stale artifact was found and rebuilt.** The committed `dist/release.json`
recorded the automation plugin at **0.2.0**; the Stage 6 header is **0.3.0**.
The old ZIP hashed `2b4857ae…`, the rebuilt one `b828b642…`. Uploading the stale
artifact would have shipped Stage 6 code labelled 0.2.0.

No `tests/`, fixture or local-only file ships in either promoted ZIP; the
automation ZIP contains exactly the main file + 19 includes. A secret scan over
all 10 artifacts (`sk-` key, assigned `WP_APPLICATION_PASSWORD`/`WP_USERNAME`,
literal `Bearer <token>`, credentials-in-URL, literal `PROVIDER_KEY`) found
**0 real credentials**. The only `Bearer` occurrence is the header *name* plus a
runtime variable (`'Authorization' => 'Bearer ' . $credential`).

### Provider

**Live smoke test: NOT RUN — `BLOCKED`.**

Proven through the real code path, not just environment inspection, by running
the gated smoke test inside the WordPress container:

```
$ php .../tests/live-provider-smoke.php
SKIPPED: not explicitly authorised (set CONEXAO_LIVE_PROVIDER_SMOKE=1 …)      exit=0

$ CONEXAO_LIVE_PROVIDER_SMOKE=1 CONEXAO_TEST_LIVE_PROVIDER=1 php …/live-provider-smoke.php
SKIPPED: no provider credential in CONEXAO_TRANSLATION_PROVIDER_KEY           exit=0
```

With **both** human acknowledgements set, the only remaining blocker is the
absent credential. `Config::credentials_available()` is therefore **FALSE**, and
the smoke test **cannot** return `RESULT: OK`.

`CONEXAO_TRANSLATION_PROVIDER_KEY` is unset in the process environment and
absent from `.env`. It is read via `getenv()` only — never from `wp_options` —
so there is no option row to fall back to, by design.

**Provider quota: NOT TESTABLE.** No credential → no authenticated call → quota
state unknown. §10 requires the smoke test to stop the stage on
`insufficient_quota`; it was never reached. **`insufficient_quota` was not
reinterpreted as application success, and no request accounting (§11) exists
because no request was made.** No limit was raised on the basis of an
unsuccessful or absent smoke test.

### Baseline

**NOT CAPTURED.** A production baseline requires production state; none was
read. No earlier baseline was reused as current truth. The six historically
authorized PT restorations are **not** asserted as live — this stage verified
nothing about their current production state.

Consequently §9 (`bootstrap_required → no mutation → reconcile → no_changes`)
was **not run**. The claim "a missing or corrupted state must never turn into
translate everything" remains covered only by the existing local behavioural
suites, **not** re-proven against production in this stage.

### Dry-run

**NOT RUN.** No production inventory, diff, provider call, plan, plan digest or
gate exists for production. Actionable record count: **unknown / not measured**.
No plan was generated, so no plan is `REVIEW_REQUIRED` — and equally, none was
approved. Reconciliation classification (§14) could not be performed.

### Canary

**No canary was selected.** §15 forbids automatically choosing the first
actionable record, and §15's preconditions (valid PT projection, no PT drift,
unambiguous B1/B2 classification, successful provider result, valid plan, PASS
gate, valid snapshot, no exception conflict) cannot be evaluated without a
production dry-run.

No PT object ID, stage, source digest, result digest, plan digest, operation or
expected-changed-field set is recorded, because none exists.

### Apply

**NOT PERFORMED. No apply authorization was given, and none was inferred.**

§18 requires a **separate** explicit authorization — *"Apply exactly this one
reviewed canary plan."* Installation authorization, provider authorization and
dry-run authorization were **not** treated as apply authorization. No apply
parameter was supplied, and apply was **not** probed through URL manipulation.

The apply sequence (§19) was not entered. Independently of authorization, apply
remains **structurally unreachable** from the production trigger: the mode
vocabulary has exactly one member (`proof`), so `apply` is not a mode at all.

### Verification

| Check | Result |
|---|---|
| PT immutability (§21) | **NOT TESTED** — no apply occurred; PT untouched by construction |
| EN B1 / B2 (§22) | **NOT TESTED** — no EN record created or modified |
| Route verification (§23) | **NOT TESTED** |
| Audit verification (§25) | **NOT TESTED** — no production audit record exists |
| `assert_no_secrets()` on a persisted production record | **NOT TESTED** |
| Retention bounded at 20 records | **NOT TESTED** |
| Lock verification (§26) | **NOT TESTED** in production; the deterministic concurrency proof remains the local evidence |
| Idempotence (§24) | **NOT TESTED** — no mutation, so no idempotence to observe |
| §7 immediate production safety reads | **NOT TESTED** — recorded as *not tested*, never as verified |

The repository-level boundary **was** verified statically: 1 approved endpoint,
0 forbidden surfaces, no cron, no anonymous AJAX, no public REST, no
apply-capable automation endpoint.

### Production changes

**None.** Exact factual list of production state changes:

- Production content records created / updated / deleted: **0**
- Polylang relationships created / modified: **0**
- Taxonomy terms changed: **0**
- Menus changed: **0**
- Options written: **0**
- Cron jobs created: **0**
- Routes registered: **0**
- Plugins installed / activated / deactivated: **0**
- Provider requests made: **0**
- Local repository changes: **1 new file** (`tests/scripts/verify-stage7-commissioning.py`), **0 tracked files modified** (`git diff --stat` empty)

`conexao-content` was **never** deactivated or reactivated — it was not touched.

### Engine integrity

| | SHA-256 |
|---|---|
| Required | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| Before deployment preparation | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| After all Stage 7 work | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |

**Exact equality.** `git diff --stat -- wp-content/plugins/conexao-translation-rollout`
is **empty**: the engine was not touched. No automatic repair was needed or
performed.

### Existing conditions

Carried forward, **not masked, not "fixed"**:

| Condition | State |
|---|---|
| `i18n_freshness` | **still failing** - 3 stale catalogues (theme `.pot`, `conexao-content`, `conexao-event-runtime`). **Deliberately not regenerated** to buy a green run |
| `dwyer-mcallister-cottage` | still the failing PT-drift routing row |
| newsletter `en_id 509` | `test-en-jobs-shared-slug.php` - 38 passed, **1 failed** |
| leisure-card excerpt | `test-leisure-card-excerpt-language.php` failed; routing row `pt-lazer-card-excerpt-stays-portuguese` failed |
| Stage 2 cache-scoping exemption | unchanged; gate green |

**Stage 7-specific findings:**

1. **The pre-existing apply-capable engine endpoint**
   `admin_post_conexao_translation_rollout_run` - declared, not hidden, not
   modified. See *Supersession contract* above.
2. **Stale release artifact**: committed `dist/release.json` recorded the
   automation plugin at 0.2.0 against a 0.3.0 header. Rebuilt.
3. **A silent false pass in this stage's own new gate.** Negative proof 4
   revealed the webhook scan used a `\b`-anchored regex, which cannot match
   `conexao_translation_webhook` (`_` is a word character). The injected webhook
   endpoint was **not detected**. This is precisely the failure mode a gate must
   never have - green while the thing it checks for is present. Fixed to a
   substring scan and **re-proven**; recorded rather than quietly patched.

### Remaining conditions

Genuine unresolved items, all external to the repository:

1. **Production deployment/admin credential** -> `REQUIRES OPERATOR ACTION`.
   `WP_USERNAME` / `WP_APPLICATION_PASSWORD` unset; `.env` is local-only.
2. **Provider credential** -> `REQUIRES OPERATOR ACTION`.
   `CONEXAO_TRANSLATION_PROVIDER_KEY` absent; must be a runtime/environment
   secret, never a `wp_options` row.
3. **Provider quota** -> `REQUIRES EXTERNAL PROVIDER ACCOUNT ACTION`. Unknown;
   only measurable once a credential exists.
4. **Plugin installation + activation in production** -> `REQUIRES OPERATOR ACTION`.
5. **Fresh production baseline capture** -> blocked until 1 and 4.
6. **Protected trigger verification in production** -> blocked until 1 and 4.
7. **Operator decision on `admin_post_conexao_translation_rollout_run`** - a
   new, genuinely open question this stage surfaced. It is an apply-capable
   endpoint in a component that is `production: true`. Stage 8 must decide
   whether it stays, is narrowed, or is scoped away - deliberately, not by
   accident.

No repository code change can resolve 1-6. They are authorization and account
actions.

### Stage 8 prerequisites

For bounded multi-record production execution, **all** of the following must
hold:

1. Production deployment/admin credential provided via the env-only mechanism.
2. Operator installs and activates `conexao-translation-rollout` then
   `conexao-translation-automation`, in `plugins.json` order.
3. `CONEXAO_TRANSLATION_PROVIDER_KEY` supplied as a runtime secret.
4. Live provider smoke test returns `RESULT: OK` - real transport, real
   validator, no credential emitted, no WordPress write.
5. Section 7 immediate safety reads verified on production (plugins, routes,
   cron, AJAX, activation effects).
6. Fresh production baseline captured, proving `bootstrap_required` with
   `actionable = 0`, `mutation_permitted = false`, `mutation_occurred = false`.
7. Protected dry-run trigger verified end-to-end with `apply unreachable`.
8. Complete production dry-run captured with an exact actionable count and a
   per-record B1/B2 classification; every plan `REVIEW_REQUIRED`.
9. **Human quality review** completed on actual English output (meaning,
   completeness, mistranslation, HTML/block integrity, terminology, title,
   excerpt, metadata, taxonomy wording). The structural validator is not a
   quality score.
10. **Explicit canary apply authorization** binding run ID, source digest,
    result digest, manifest/change digest, plan digest, snapshot digest,
    stage, environment and configuration identity.
11. A decision on `admin_post_conexao_translation_rollout_run`.
12. The pre-existing failures resolved or explicitly accepted - particularly
    `i18n_freshness`, which will keep `run-tests.sh` red and would otherwise
    mask a real regression.

---

## Test results

| Layer | Result |
|---|---|
| In-process PHP | **76 suites, 74 passed, 2 failed**; **5,563 assertions passed, 6 failed** |
| Script contract | **11 suites, 10 passed, 1 failed** (the new Stage 7 gate: **49 passed, 0 failed**) |
| HTTP acceptance | **3 suites, 2 passed, 1 failed** |
| `scripts/lint.sh` | **OK** - syntax clean (513 files), no new PHPCS violations, PHPStan level 5 clean |
| `php scripts/generate-registry-docs.php --check` | **registry OK: 15 plugins validated, 24 generated regions current (zero writes)** |

Failing set is **identical** to Stage 6 (script-contract failing 1->1,
in-process failing suites 2->2, failing assertions 6->6, acceptance failing
1->1). Stage 7 added one suite and changed **no tracked file**
(`git diff --stat` empty), so it introduced no regression.

### Failure proofs for the new gate

A gate never shown to fail is not a gate. Five negative proofs, each on a
**temporary copy**, never the repository:

| Proof | Injected | Result |
|---|---|---|
| 1 | a second, differently-named `admin_post_..._apply` endpoint | **FAIL** - 3 assertions, caught by name *and* count |
| 2 | the endpoint moved to an unapproved file | **FAIL** - caught by owner |
| 3 | `admin_post_nopriv_` + `wp_ajax_` + `rest_api_init` | **FAIL** - 5 assertions |
| 4 | cron scheduling + webhook action | **FAIL** - *after* fixing the gate's own regex defect |
| 5 | the declared engine endpoint renamed | **FAIL** - declaration goes stale, fails closed |

---

## Evidence

| File | Proves |
|---|---|
| `01-preflight-integrity.txt` | git state, HEAD, engine/automation/provider SHA-256 at stage start |
| `02-environmental-prerequisite-gate.txt` | Section 4 gate: credential absence via the real code path; no secret values |
| `03-artifact-verification.txt` | double-build determinism, headers, dependency order, secret scan, release gates |
| `04-supersession-contract-gate.txt` | the Section 2 contract gate plus all five failure proofs and the self-defect |
| `05-test-suite-results.txt` | full suite numbers and the before/after regression comparison |
| `06-production-safety-verification.txt` | what was not done, section by section, and Section 35 end-state switches |

## Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-10-01-stage-7-production-commissioning-canary.md` | this report |
| `docs/evidence/2026-10-01-stage-7-production-commissioning-canary/` | six evidence files |
| `docs/reports/README.md` | index row for this stage |
| `docs/testing.md` | the new permanent gate in the blocking-gate table |

## Rollback / recovery

Delete `tests/scripts/verify-stage7-commissioning.py`. Nothing else changed:
`git diff --stat` is empty, no `wp-content` file was touched, the engine SHA-256
is unchanged, and no production state was created. Rollback covers **everything**
this stage did, because everything this stage did is in the repository.

---

## Final safety invariant

| Property | State |
|---|---|
| Production content / options / routes / cron | **unchanged - nothing installed or applied** |
| PT canonical content | **unchanged** |
| EN production content | **unchanged** |
| Production translation | **OFF** |
| Bulk translation | **OFF** |
| Cron | **OFF** |
| Autonomous approval | **OFF** |
| Unattended apply | **OFF** |
| Apply from the production trigger | **unreachable** |
| Public REST / anonymous AJAX / webhooks | **none** |
| Approved admin-post endpoints | **exactly 1** (by name, by count, by file) |
| Pre-existing engine endpoint | **declared**, decision deferred to Stage 8 |
| Engine SHA-256 | **`baf85283...a6ce4`, identical before and after** |
| Flutter/mobile repository | **untouched** |

**The first production run must prove the architecture, not consume the
backlog.** Nothing in this stage authorizes translating the remaining
catalogue.

_Last verified: 2026-10-01 by Stage 7 - production commissioning gate_
