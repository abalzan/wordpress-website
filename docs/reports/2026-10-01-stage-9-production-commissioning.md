# Stage 9 — Production Commissioning

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `d5f5f6cb9650ac7beca2390696b1ff8e08b033c2`
**Status: `BLOCKED`**
**Production writes: `0`. Production installs: `0`. Production activations: `0`.
Provider requests: `0`. Production mutations: `0`.**

Nothing was installed, uploaded, activated or applied in production. No
production credential was used, requested or printed. No provider request was
made. No production content was read or written. The local Docker credentials
in `.env` were deliberately **not** reused as production credentials.

Stage 9 was the first stage permitted to commission infrastructure in
production. It could not, because the environment prerequisites that
authorise production action are absent.

---

## Stage 9 status

**`BLOCKED`**

Section 38 lists ten conditions that force `BLOCKED`. **Six** of them are
absent, and they are the six that define this stage:

| §38 blocker                              | State     |
|------------------------------------------|-----------|
| production access                        | **MISSING** |
| installation authorization               | **MISSING** |
| provider credential                      | **MISSING** |
| provider quota                           | **NOT TESTABLE** |
| successful live provider smoke           | **NOT PERFORMED** |
| fresh baseline                           | **NOT CAPTURED** |
| proof trigger verification (production)  | **NOT PERFORMED** |
| legacy 410 verification (production)     | **NOT PERFORMED** |
| safe dry-run (production)                | **NOT PERFORMED** |
| required gate                            | **PRESENT — all passing** |

`PASS WITH CONDITIONS` is explicitly forbidden here: Section 38 states it must
not be used "to imply that an unperformed commissioning step was successful".
Commissioning is precisely what did not happen, so `BLOCKED` is the only
honest status.

This is the same structural blocker Stage 7 hit, one stage later. Stage 7 was
blocked on the environment; Stage 8 closed the remaining code defect; Stage 9
returns to the environment and finds it unchanged.

---

## Preconditions

Which external prerequisites were **actually** satisfied:

| Requirement                         | Required state            | Actual                          |
|-------------------------------------|---------------------------|---------------------------------|
| Production deployment/admin access  | available                  | **ABSENT**                      |
| Explicit installation authorization | granted                    | **ABSENT**                      |
| Provider runtime credential         | via env var                | **ABSENT**                      |
| Provider credential persistence     | none                       | **HOLDS** — nothing persisted  |
| Provider quota                      | available                  | **NOT TESTABLE**                |
| Live provider smoke authorization   | granted                    | **ABSENT**                      |
| Current production artifacts        | built/verified             | **PRESENT** (built, §4)        |
| Engine digest                       | verified                   | **VERIFIED** (§3, §34)          |
| Fresh-baseline procedure            | ready                      | **PRESENT** (code)             |
| Protected proof trigger             | present in artifact        | **PRESENT**                     |
| Legacy endpoint closure             | present in artifact        | **PRESENT** (§7, not §7-prod)   |
| Canary apply authorization          | **not yet implied**        | **NOT GRANTED** — correct       |

**The three permissions are all absent and none was inferred from another.**
No installation authorization, no provider authorization, no apply
authorization. Apply authorization was not inferred from deployment, from
provider, or from dry-run authorization — none of which exist either.

Evidence: `05-preconditions-and-credentials.txt`.

### Why production access is absent

```
WP_USERNAME                      = ABSENT
WP_APPLICATION_PASSWORD          = ABSENT
CONEXAO_TRANSLATION_PROVIDER_KEY = ABSENT
CONEXAO_SITE_URL                 = UNSET
```

`https://conexaobr.ie` answers **HTTP 200** to a read-only GET and its DNS
resolves. **Reachable is not authorized.** No authenticated call was made.

The repository `.env` does contain `WP_USERNAME` and `WP_APPLICATION_PASSWORD`,
but that file documents its own scope in comments: *"Local development test
account (created in the local Docker stack only) … These authenticate against
`http://localhost:8080` … They are NOT production credentials."* They classify
as the local Docker target and were **not** repurposed. Reusing local
credentials against production would be exactly the "infer one permission from
another" failure Section 1 forbids.

`scripts/lib/rest.py` reinforces this structurally: there is deliberately **no
production default**, and a write to a production host is refused unless
`--confirm-production` is passed explicitly.


---

## Deployment

**Exact production installation state: NOTHING INSTALLED.**

No ZIP was uploaded. No plugin was installed or activated. Production still
runs whatever it ran before this stage.

The artifacts that *would* be installed were built and verified:

| Artifact                         | Version | Bytes  | sha256 |
|----------------------------------|---------|--------|--------|
| `conexao-translation-rollout`    | 1.2.0   | 12268  | `c6c7a91a024c7b5af2365154c65e4cacf8d1a9b0ffd6fd91cd0886077bbe65d8` |
| `conexao-translation-automation` | 0.3.0   | 96059  | `f8cf8ec2b9a7d2f55f63f3d1f6d4fca0e7d15854897fb8b18faa02cb5badbe4a` |

Release tag `v2026.10.01`, built at `2026-10-01T10:26:20Z` from a **clean**
tree at `d5f5f6c`. Dependency order is correct: `conexao-translation-rollout`
precedes `conexao-translation-automation`, and the automation plugin's
`Requires Plugins` header asserts it (gate-checked against `plugins.json`).

Nothing in `conexao-content` was touched. No unrelated plugin was touched. No
translation state was initialised, because activation never happened.

Evidence: `07-source-and-artifact-preflight.txt`, `03-release.json`.

### Artifact integrity (Section 4) — PASS

Two consecutive builds: **all 10 ZIP hashes byte-identical**. 10/10 expected
artifacts. Zero `tests/`, `.env`, docker or compose entries in any ZIP. Zero
credential-shaped strings in any ZIP. Header == registry == release.json ==
in-ZIP metadata for all 9 comparable artifacts.

**The Stage 8 stale-`dist/release.json` defect cannot silently return.** The
pre-existing `dist/release.json` was stale (it recorded `71438ca` + `dirty:
true` while the tree held uncommitted Stage 8 work). Rebuilding corrected it to
`d5f5f6c` + `dirty: false`. `verify-stage8-release-consistency.py` (52/52) now
asserts, for every artifact, that the file exists **and** the version inside it
equals the source version, plus explicit regressions against the stale `0.2.0`
header and against a release.json/header mismatch.

---

## Legacy endpoint

### The production `410` proof was NOT performed

**`legacy apply endpoint closure positively verified in production` is NOT
claimed.** Section 7 makes a *positive production* verification mandatory and
treats it as the Stage 8 remaining condition. It could not be run: there is no
authorized production admin channel, and the endpoint is capability-gated
(`current_user_can( self::CAP )` → `403` for anyone else). Producing a `410`
requires an authenticated `manage_options` session in production.

**Reachability was not substituted for verification, and the check was not
simulated.** No apply request was constructed. No request parameter was
manipulated to try to reach the old implementation. Production `writes = 0`.

### What IS proven: the closure is present in the promoted artifact

The closure ships in `conexao-translation-rollout` **1.2.0**, whose own header
states: *"the former `admin_post_conexao_translation_rollout_run` endpoint is a
closed deprecation stub, and the single production control plane is
`conexao-translation-automation`'s
`admin_post_conexao_translation_automation_proof`."*

The closed handler
(`includes/class-conexao-translation-rollout-admin.php`, ~line 140) is
unconditional:

```php
public static function handle_run(): void {
    if ( ! current_user_can( self::CAP ) ) {
        wp_die( 'forbidden', '', array( 'response' => 403 ) );
    }
    wp_die( esc_html( sprintf( 'The %1$s endpoint was retired: … Use %2$s …',
            self::ACTION, self::REPLACEMENT_ACTION ) ),
        'Endpoint retired',
        array( 'response' => 410 )          // literal 410
    );
}
```

Structural properties, read from source:

- literal `410` on the unconditional `wp_die()` (line 154);
- **no `wp_safe_redirect()`** in the handler → no redirect to an apply path;
- **no branch reaches the engine**, a stage callback, or any write;
- the capability check is retained deliberately so an anonymous caller learns
  nothing (the refusal is not a public oracle), and the refusal itself is
  unconditional for anyone who clears it.

---

## Production safety

### Route inventory (Section 8) — production NOT verified

The production route inventory was **not** captured, because reading it
requires authenticated admin access. What the artifact and gates guarantee:

- exactly **one** approved automation proof endpoint —
  `admin_post_conexao_translation_automation_proof`, matched by **exact
  resolved action name**, with **count == 1**, asserted with its **owning
  file**;
- **0** `wp_ajax_nopriv_` (no anonymous AJAX);
- **0** `register_rest_route` / `rest_api_init` (no public REST);
- **0** `cron_schedules` (no cron);
- **0** webhooks;
- **0** apply mode constants; the approved endpoint never reads a superglobal
  for its mode;
- the legacy apply endpoint is closed.

Matching is by exact resolved action name and owning file — Stage 7's
structural logic — **not** by substring scan alone. The Stage 8 webhook scan
uses a substring comparison precisely because a `\b`-anchored regex cannot
match an underscore-separated WordPress action name; that negative proof is
retained.

### Cron (Section 9) — production NOT verified

Production `translation cron = 0` was **not** observed live. No cron was
created by this stage, and the gates prove no component registers a schedule.
Nothing to roll back.

### Activation side effects (Section 6) — not applicable

No activation occurred, so there were no side effects to check. The
no-unintended-activation-effects guarantee is covered by
`test-automation-hooks.php` (**50 passed, 0 failed**), which passes.

### Plugin state (Section 6) — production NOT read

Production plugin versions were not read. The artifact versions that would be
installed are recorded above. Registry consistency is proven locally:
`generate-registry-docs.php --check` reports *"15 plugins validated, 24
generated regions current (zero writes)"*.

---

## Provider

### Live smoke test (Section 11) — NOT PERFORMED

**Zero provider requests were made.** Section 11 permits exactly one
controlled live smoke test, and only once the credential and quota are
genuinely available. The credential is absent, so the smoke test could not
run. It was **not** simulated, and `RESULT: OK` is **not** claimed.

Quota was **not** probed. Calling the provider repeatedly to discover whether
quota has returned is forbidden by Section 2, and quota is untestable without a
credential. It is recorded as **NOT TESTABLE** rather than reinterpreted as
success — the same judgement Stage 7 recorded.

### Provider runtime configuration (Section 10) — safe metadata only

Verified against the **artifact** through the real plugin code path (the
actual `Conexao_Translation_Automation_Provider_Config` class, loaded inside
the local Docker WordPress; no re-implementation):

| Property        | Value |
|-----------------|-------|
| provider        | `openai` |
| model           | `gpt-4o-mini` |
| endpoint        | `https://api.openai.com/v1/chat/completions` |
| timeout         | `60` |
| max attempts    | `4` |
| backoff base/max| `500` / `4000` ms |
| max request bytes | `240000` |
| max run seconds | `600` |
| source / target | `pt` / `en` |
| **credential-present flag** | **`FALSE`** |

`credentials_available()` — the real production code path, not a substitute —
returned **`FALSE`**.

### Credential safety

- **credential present: NO.** No value was read, printed, echoed or stored.
- The credential is read from the environment only
  (`getenv( self::CREDENTIAL_ENV )`, `provider-config.php:204`) — never from
  `wp_options`, the database, audit records, plans, snapshots or logs.
- `assert_no_secrets()` runs on the result boundary's **write path**; the
  persisted configuration was probed for secret-shaped values and
  **`config_secret_shaped_values = 0`**.
- No `.env` is included in any artifact. The `.env` values were redacted to
  key names only in the evidence file.
- No nonce, cookie or authorization header appears in any Stage 9 artifact.

Evidence: `05-preconditions-and-credentials.txt`.

---

## Fresh baseline

**NOT CAPTURED.** No fresh production baseline exists, because the automation
plugin is not installed in production and there is no authorized channel to
read production state.

---

## Bootstrap

**NOT PERFORMED in production.** The Section 13 chain

```
missing state → bootstrap_required → actionable=0
             → mutation_permitted=false → mutation_occurred=false
```

was **not** observed in production, because no production run occurred.

No production baseline was written, and bootstrap was **not** used as an
excuse to create translations. In production, `mutation_occurred` is trivially
`false` for the whole stage: nothing ran.

The behaviour itself is covered by passing repository tests
(bootstrap/status semantics are exercised in the automation and change-detection
suites, all green), but a passing unit test is **not** a production
observation and is not presented as one.

## Reconciliation

**NOT PERFORMED.** No reconciliation ran against production, so no
`outcome=no_changes` is claimed.

Section 14's instruction — if any actionable record appears immediately after
baseline initialization, stop and investigate — could not arise, because no
baseline was initialized. No provider-backed plan was generated; there was no
provider to generate one.

---

## Production dry-run

**NOT PERFORMED.** No production dry-run ran. No run ID, no inventory digest,
no changed-record count, no actionable count, no provider request count, no
retry count, no plan digest, no gate result and no audit result exist for
production.

The dry-run **did** stop before apply — trivially, because it never started.
That is not a PASS gate; it is an absent step.

## Dry-run scope inspection

**NOT PERFORMED.** No operation was classified as B1 create, B1 update/repair,
B2 update, manual intervention, unsupported or deletion, because no dry-run
produced operations. The rule that `manual_intervention_required` is not
actionable apply work, and that PT deletion stays manual absent a separate
deletion policy, remains in force unchanged and untested-in-production.

## Review artifact

**NOT GENERATED.** No production review artifact exists, because there is no
production plan. No English translation was proposed, and therefore no human
review of a canary output took place.

No artifact in this stage contains a provider key, an application password, a
nonce or an authorization header.

---

## Review

**NOT PERFORMED.** No human inspected any proposed canary output, because no
output was proposed.

The status is neither `REVIEWED` nor `APPLY_AUTHORIZED`. Recording it as
`REVIEWED` would be false.

## Canary

**NO CANARY SELECTED.** No operation was selected, automatically or otherwise.

Every Section 20 precondition — allowlist membership, valid source digest, a
validated provider result, no PT drift, no deletion state, unambiguous B1/B2
interpretation, a valid plan, a `PASS` dry-run gate and valid snapshot identity
— requires a production dry-run that does not exist. Selecting a canary from a
non-existent plan would be fabrication.

## Apply

**NOT PERFORMED, AND NOT AUTHORIZED.**

No apply authorization was requested, granted, or inferred. Section 22 requires
the operator to explicitly authorize *"Apply exactly this single reviewed
production canary."* That authorization was never given, and it would not have
been honoured even if a dry-run had existed, because Section 22 also forbids
inferring apply authorization from deployment, provider or dry-run
authorization.

No approval record was created. `approve_latest`, `approve_all`, a bare
`approved=true` and stage-only approval were all unused — and would each have
been a violation.

Production mutations: **0**.


---

## Production changes

**The exact factual list of production changes is empty.**

- Production writes: **0**
- Production installs / activations / deactivations: **0**
- Production option changes: **0**
- Production content mutations: **0**
- Production EN records created: **0**
- Production taxonomy / menu / Polylang changes: **0**
- Provider requests: **0**
- Cron events created: **0**
- REST routes / AJAX actions / webhooks added: **0**
- Production rollback needed: **NONE**

**Repository-side changes made by this stage** (these are *not* production
changes, and are listed for completeness):

| Path | Change |
|------|--------|
| `docs/reports/2026-10-01-stage-9-production-commissioning.md` | this report (new) |
| `docs/evidence/2026-10-01-stage-9-production-commissioning/` | evidence (new, 8 files) |
| `docs/reports/README.md` | Stage 9 index row |
| `docs/evidence/README.md` | Stage 9 evidence index row |
| `dist/*.zip`, `dist/release.json` | rebuilt (the pre-existing `dist/release.json` was stale) |

**No engine source, no automation plugin source and no theme source was
modified.** `git diff` against `d5f5f6c` touches documentation and `dist/`
only. The engine digest is unchanged, and the safety chain is unchanged.

---

## Engine integrity

Section 34 requires the digest to be recorded before and after, with exact
equality.

```
BEFORE (preflight, before any build):
  baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4

AFTER  (both builds, and after the Stage 8 commit):
  baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
```

File: `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php`

**Exact equality. `baf85283…a6ce4` before and after.**

This matches the historical digest, as expected, because Stage 8 intentionally
left the engine source unchanged. No repair was performed and none was needed.

Note on the surrounding plugin: the engine *plugin* contains other files
(admin class `d3109bc9…`, entry point `945611ce…`) and its version is 1.2.0.
Those are the Stage 8 closure files and are stable across this stage too. The
digest Stage 9 pins is the **engine class file**, which is the mutation
authority.

---

## Existing conditions

All Section 33 conditions were reproduced and **not** masked, **not**
regenerated and **not** reclassified:

| Condition                          | Status |
|------------------------------------|--------|
| `i18n_freshness` — 3 stale catalogues | **REPRODUCED.** `conexao-br-irlanda` (lag 92483s), `conexao-content` (76025s), `conexao-event-runtime` (76025s). None is a Stage 9 component. Not regenerated. |
| `dwyer-mcallister-cottage`         | **REPRODUCED** via the `/lazer/` HTTP acceptance row. |
| newsletter `en_id 509`             | **REPRODUCED** — `test-en-jobs-shared-slug.php`, 38 passed / 1 failed. |
| leisure-card excerpt failures      | **REPRODUCED** — `test-leisure-card-excerpt-language.php`, 40 passed / 5 failed. |
| Stage 2 cache-scoping exemption    | **STILL PASSING** — `cache_scoping` 9/9 and `cache_scoping_static` 2/2, zero violations. |

**The failing set is identical to Stage 8's, with identical counts:**

| Section | Stage 8 | Stage 9 |
|---------|---------|---------|
| In-process PHP suites | 77 total, 75 passed, 2 failed | 77 total, 75 passed, 2 failed |
| Assertions            | 5600 passed, 6 failed    | 5600 passed, 6 failed    |
| Script-contract suites| 13 total, 12 passed, 1 failed | 13 total, 12 passed, 1 failed |
| HTTP acceptance suites| 3 total, 2 passed, 1 failed  | 3 total, 2 passed, 1 failed |

**No regression was introduced, and no gate was weakened to obtain a green
result.** The baseline classification was not rewritten to improve aggregate
numbers; no catalogue was regenerated solely to obtain a green suite.

---

## Remaining conditions

Only genuine, unresolved blockers. Every item is environmental or
authorization-related — none is a repository defect, and none was introduced or

---

## Stage 10 prerequisites

Stage 10 must **not** be scheduled until every item below is satisfied. These
are the requirements for bounded multi-record production execution, and each is
currently unmet.

### Gate A — authorization (all three, separately, explicitly)

1. An authorized production deployment/admin channel is available
   (`WP_USERNAME`, `WP_APPLICATION_PASSWORD`, `CONEXAO_SITE_URL` set to the
   production host), **with the local Docker credentials still reserved for
   local use**.
2. **Explicit installation authorization** for the shared engine and the
   automation plugin, at versions `1.2.0` and `0.3.0`, with the exact recorded
   SHA-256 digests.
3. **Explicit provider/smoke authorization** plus a runtime
   `CONEXAO_TRANSLATION_PROVIDER_KEY` with sufficient quota.

### Gate B — commissioning must be completed and re-proven in production

4. Installation and activation completed, with plugin state and dependency
   verified, and **no unintended activation side effects** observed.
5. **Legacy `410` positively verified in production** — the Section 7
   remaining condition, still open.
6. Exactly one live provider smoke test returning **`RESULT: OK`**, with no
   credential emitted and no content written.
7. A fresh production baseline captured, **including the explicit
   re-verification of the six historic PT restorations**.
8. `missing state → bootstrap_required → actionable=0 →
   mutation_permitted=false → mutation_occurred=false` observed live.
9. An immediate reconciliation returning **`outcome=no_changes`** with
   `actionable = 0`.
10. A real production **dry-run** completing the full chain and stopping before
    apply, with a reviewable plan and recorded run ID, digests, request counts,
    plan digest, gate and audit result.
11. Human quality review of **every** proposed output.

### Gate C — canary first, always

12. **One** canary applied first, under a **separate explicit** authorization
    bound to run ID, stage, source digest, provider/result digest,
    change/manifest digest, plan digest, snapshot digest, environment and
    configuration identity. No `approve_latest`, no `approve_all`, no bare
    `approved=true`, no stage-only approval.
13. Full post-apply verification passed: exact mutation-set equality, PT
    immutability, EN/B1/B2 correctness, route success, idempotence, lock
    lifecycle, and an audit record passing `assert_no_secrets()`.
14. Rollback readiness confirmed **before** any apply: exact object, run ID,
    plan, snapshot, audit entry and a written manual procedure.

### Gate D — the expansion limits Stage 10 must respect

15. Bounded record count agreed in advance, drawn only from the explicit
    automation allowlist.
16. `manual_intervention_required` and PT deletion remain **non-actionable**.
17. Every additional record requires its **own** digest-bound approval. One
    canary approval never carries over.
18. **Bulk apply, scheduled execution, unattended approval and cron all remain
    OFF.** They require their own separately authorized stage; a successful
    Stage 10 does not imply them.
19. The stage allowlist is **not** expanded.
20. Production bulk/autonomous translation stays **OFF**.

### Standing constraints

21. Never extrapolate from a successful canary to full automation.
22. Never infer one authorization from another.
23. Never weaken an existing gate to obtain a green run.
24. Never regenerate the stale catalogues solely to obtain a green suite.

---

## Stop policy

This stage stopped at the authorization boundary and did not proceed past it.
No scope expansion was attempted; the missing preconditions were discovered,
recorded and reported rather than worked around.

The architecture Stage 8 closed is intact and unchanged. The engine digest
still matches. All gates still pass. The safety chain was not bypassed, and
production was not written to.

**Do not extrapolate from this stage to full automation.**

---

## Evidence index

| File | Contents |
|------|----------|
| `01-artifact-hashes-build2.txt` | sha256 of all 10 ZIPs from the second build |
| `02-permanent-gates.txt` | `verify-permanent-gates.py` full output (7 gates) |
| `03-release.json` | release manifest as built |
| `04-run-tests.txt` | full `./scripts/run-tests.sh` output |
| `05-preconditions-and-credentials.txt` | environment probe, credential-safe provider metadata |
| `06-legacy-endpoint-closure.txt` | 410 closure evidence and what was NOT proven |
| `07-source-and-artifact-preflight.txt` | git state, engine digest, reproducibility, hygiene |
| `08-gates-and-tests.txt` | Section 36/37 gate and test matrix |

**No file in this directory contains a credential, an application password, a
nonce, a cookie, an authorization header, or a `.env` file.**

worsened by this stage.

1. **No authorized production channel.** `WP_USERNAME` /
   `WP_APPLICATION_PASSWORD` / `CONEXAO_SITE_URL` unset; the `.env`
   credentials are documented as local-only and were not repurposed. Blocks:
   installation, route inventory, cron check, plugin state, baseline, legacy
   `410`, trigger proof, dry-run.

2. **No installation authorization.** Must be granted explicitly and
   separately. Not inferable from anything else.

3. **No provider credential.** `CONEXAO_TRANSLATION_PROVIDER_KEY` unset;
   `credentials_available()` = `FALSE`. Blocks: the live smoke test and the
   provider step of the dry-run.

4. **Provider quota unknown.** Genuinely **untestable** without a credential,
   and deliberately not probed. Must not be re-attempted repeatedly to
   discover quota.

5. **No live provider smoke.** Zero provider requests made; `RESULT: OK` is
   not claimed.

6. **No fresh production baseline.** Section 12's digests unrecorded.

7. **The six historic PT restorations are unverified against current
   production.** They were restored on 2026-09-30; production state since then
   has not been read. **They must be re-verified explicitly, not assumed.**

8. **Legacy `410` unverified in production.** Proven in artifact and gates
   only. Section 7's positive production proof is outstanding.

9. **No production dry-run**, therefore no plan, no review artifact, no
   review, no canary selection and no approval binding.

10. **Pre-existing, unrelated to Stage 9:** the 3 stale i18n catalogues, the
    leisure-card excerpt failures, `dwyer-mcallister-cottage` and newsletter
    `en_id 509`. Out of Stage 9's scope and untouched.

---

## Verification

| Verification (§§24–30)             | Result |
|------------------------------------|--------|
| Exact mutation boundary            | **N/A — zero mutations; expected and actual mutation sets are both empty and trivially equal** |
| PT immutability (§25)              | **N/A — no mutation; nothing to compare. PT was not written, so `PT before == PT after` holds vacuously** |
| EN verification (§26), B1 and B2   | **N/A — no EN record was created or updated** |
| Route verification (§27)           | **N/A — no B1 canary; no route was fetched or altered** |
| Idempotence (§28)                  | **N/A — no mutation to be idempotent about. Invariant `same PT state + same translation state → no mutation` holds vacuously: production was never written** |
| Lock verification (§29)            | **N/A — no production lock was acquired. No destructive production race was manufactured** |
| Audit verification (§30)           | **N/A — no production run produced an audit record. `assert_no_secrets()` behaviour and the 20-record retention policy remain covered by the passing audit suite (80 assertions) locally** |

Every row is **N/A because the step did not occur**, not because it passed.
The safety chain — lock, inventory, provider validation, translation plan, F7,
digest-bound approval, snapshot, apply, verify, idempotence, audit — is
**unmodified and unweakened**, proven by passing tests in the repository.


No old production inventory was reused, and no baseline was invented from
local or historical data. None of the following was recorded, and none is
claimed: PT inventory digest, per-object source projection digests, B1 EN
inventory, B2 field state, taxonomy state, Polylang relationships, plugin
state, route inventory, cron state, audit state, persisted automation state.

**The six historic PT restorations were NOT re-verified against production.**
Section 12 requires an explicit check rather than an assumption that they
still match. That check is outstanding and is listed under Remaining
conditions. Nothing in this report suggests those restorations are still
correct in production.


Enforced permanently, failing closed:

| Proof                                                    | Result |
|----------------------------------------------------------|--------|
| `verify-stage8-control-plane.py` — literal `410` in handler, no `wp_safe_redirect` (line 284) | **80 passed, 0 failed** |
| `verify-stage7-commissioning.py` — engine endpoints cannot silently disappear | **49 passed, 0 failed** |
| `test-legacy-apply-endpoint-closure.php` — behavioural | **36 passed, 0 failed** |
| evidence string `legacy-endpoint engine-invocations=0 refusal-code=410 anonymous-code=403` | recorded |

Evidence: `06-legacy-endpoint-closure.txt`.
