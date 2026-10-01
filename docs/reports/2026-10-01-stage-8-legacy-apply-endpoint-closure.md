# Stage 8 — Legacy Apply Endpoint Closure

**Date:** 2026-10-01
**Branch:** `i18n` · **Base commit:** `71438ca639642222e718f636395cdf2ac803b4b0`
**Status: `PASS`**
**Production writes: `0`. Production content writes: `0`. Production installs: `0`.
Production translations: `OFF`. Production apply: `OFF`.** Nothing was uploaded,
activated or applied. No production credential was used, requested or printed.
No provider request was made.

Stage 7 ended `BLOCKED` and handed forward one open operator decision: the shared
engine `conexao-translation-rollout` ships its own apply-capable
`admin_post_conexao_translation_rollout_run` endpoint, which Stage 7 declared in
`DEFERRED_ENGINE_SURFACES` rather than hiding or removing. Stage 8 resolves that
decision, and eliminates the stale `dist/release.json` artifact mismatch Stage 7
found.

The objective — *exactly one safe production control plane for translation
automation, with no legacy apply endpoint capable of bypassing the Stage 2–7
safety chain* — is met in code and in the release artifacts.

---

## Stage 8 status

**`PASS`**

Stage 7 was blocked on the **environment** (no authorized production channel, no
provider credential, no operator authority). Stage 8 is a **code and release
safety** stage, and everything it was asked to deliver is credential-independent,
so none of Stage 7's environmental blockers apply to it. The two defects Stage 7
recorded — the legacy apply endpoint and the stale release metadata — are both
closed here, proven by permanent gates with negative proofs, and the failing test
set is **identical** to Stage 7's.

`PASS`, not `PASS WITH CONDITIONS`, because no genuine unresolved blocker remains
*inside the repository*. The Stage 7 environmental items are carried forward
unchanged as prerequisites for a later commissioning stage; none of them is a
Stage 8 defect and none was worsened.

---

## Legacy endpoint

### The exact call graph (traced from source, not inferred)

`admin-post.php` → `Conexao_Translation_Rollout_Admin::handle_run()` →
`call_user_func($config['run_callback'], ['dry_run' => false])` →
`Conexao_Translation_Rollout_Engine::run()` → `apply_plan()` → **real writes**.

| # | Step | Location | Protection present |
|---|---|---|---|
| 1 | `$_REQUEST['action']` — fires for **GET as well as POST** | `wp-admin/admin-post.php:29` | none (verified in-container against WP 7.1.2) |
| 2 | `admin_post_conexao_translation_rollout_run` | `admin.php:48` | authenticated only (no `admin_post_nopriv_`) |
| 3 | `handle_run()` | `admin.php:73` | — |
| 4 | `current_user_can('manage_options')` | `admin.php:74` | ✅ capability |
| 5 | `check_admin_referer(ACTION, NONCE)` | `admin.php:78` | ✅ nonce |
| 6 | `$mode = 'apply' === $_POST['mode'] ? …` | `admin.php:82` | ❌ **caller-controlled** |
| 7 | `Engine::get_stage($stage)` | `admin.php:83` | ❌ no allowlist |
| 8 | `call_user_func($config['run_callback'], ['dry_run' => false])` | `admin.php:89` | ❌ **direct apply** |
| 9 | stage `run_callback` → `Engine::run(…, ['dry_run' => false])` | `stage-config.php:195` | ❌ |
| 10 | `apply_plan()` — real writes | `engine.php:950` | ❌ |

### What it bypassed

Present: authentication, capability, nonce (and, below the entry point, the
engine's own PT-drift guard, verify counters and numeric gate).

**Absent:** the Stage 0 stage allowlist, Stage 2 F7 ("PASS dry run before
apply"), the digest-bound approval, the concurrency lock, the audit trail, the
environment guard, Stage 3 change detection, provider validation, plan/review
state, and human review.

**A capability and a nonce were the entire authorisation story.** It could
directly cause production mutation.

### The decisive repository evidence

Only two plugins register a stage with the engine, and **both are
`production: false`**:

| Plugin | production | build | status |
|---|---|---|---|
| `conexao-en-translation` | false | false | active |
| `conexao-job-translation` | false | false | retired |

The production steady state is `conexao-data-model → conexao-content →
conexao-admin-ux → conexao-event-runtime → conexao-translation-rollout →
conexao-translation-automation`. **No production plugin registers a stage**, so
in production `Engine::get_stage()` returned null for every input and
`handle_run()` could only ever have reached its `unknown stage` 400.

**The bypass was latent, not load-bearing** — which is precisely why declaring it
was not enough and why it had to be closed.

---

## Architectural decision

**Option A — remove the legacy apply capability**, implemented as a **hard-fail
deprecation stub** rather than a silent redirect or a flag.

| Option | Assessment |
|---|---|
| **A — remove** | ✅ **Chosen.** The capability is redundant: the automation orchestrator already drives stages through `run_callback` with the full Stage 2–7 chain, and the production plugin set registers no stage at all, so nothing legitimate is lost. |
| B — compatibility shim | Rejected. A redirect would preserve the old POST contract as a **live apply alias** — the exact bypass being closed. A shim re-routing into the orchestrator would add a second request contract for the same mutation, i.e. a second entry point. |
| C — retain under exception | Rejected. No repository evidence proves a legitimate operational dependency: no production plugin registers a stage, and the Tools screen is not referenced by any script, test or documented workflow as an apply path. |

### Why this preserves exactly one mutation control plane

`handle_run()` is now: capability check, then `wp_die(…, 410)`. The file
contains **no** `call_user_func`, **no** `run_callback`, **no** `Engine::`
reference, and reads **no** superglobal — so there is no request value that can
become a mode, and no code path from that file to the engine.

The hook is **still registered**, deliberately. Deleting the `add_action()` would
send an old bookmarked URL to WordPress's generic "0 handlers" response,
indistinguishable from a broken install. A named refusal tells an operator what
happened and where to go. Keeping the registration also keeps the action name
watched: if it is renamed or removed, both gates fail closed.

**The chain to apply is now single and unbroken:**

```
admin_post_conexao_translation_automation_proof   (the ONLY entry)
  -> is_post -> authenticated -> manage_options -> nonce -> validate_request
  -> Orchestrator::run
      -> authorise -> validate_mode -> stage allowlist -> registry consistency
      -> LOCK
      -> dry-run (dry_run forced true; cannot be overridden)
      -> [mode=apply only] environment -> F7 PASS -> digest-bound approval
      -> snapshot identity persisted -> ENGINE WRITES -> verify
  -> safe_result (secret-scrubbed)
```

`mode=apply` alone is **not** sufficient anywhere. The approved production
endpoint's mode vocabulary has exactly one member (`proof`), so `apply` is not a
mode there at all: it is rejected by the same unknown-mode branch as any other
string. In the orchestrator, `MODE_APPLY` is recognised but reachable only after
the whole chain has succeeded.

### Approval replay protection (unchanged, re-verified)

The Stage 2 digest binding is untouched and remains the only approval mechanism.
The digest covers `manifest_digest`, `plan_digest`, `snapshot_digest`, `stage`,
`run_id`, `environment` and `config_identity`, compared with `hash_equals`. An
approval granted for run A / plan A / source A therefore cannot be replayed
against run B, a different plan, a changed PT source, a different environment, a
reconfigured stage, or an altered provider result — any drift changes the digest
and fails closed before the engine is asked to write. **No boolean approval was
introduced or simplified.**

---

## Engine changes

**The engine's mutation authority was NOT modified.** `Stage 8 may modify the
shared engine if required`; on the evidence it was not required — the bypass was
entirely in the entry point, and the endpoint lived in a separate file from the
engine.

| File | Change | Why |
|---|---|---|
| `includes/class-conexao-translation-rollout-admin.php` | `handle_run()` reduced to capability check + `wp_die(410)`; `render()` made read-only; `NONCE` constant, stage resolution, mode parsing, `call_user_func`, the transient, the redirect and the report renderer all removed | **This is the entire closure.** The apply path is deleted, not flagged |
| `conexao-translation-rollout.php` | `Version:` 1.1.0 → **1.2.0**; `CONEXAO_TRANSLATION_ROLLOUT_VERSION` likewise; header description records the closure | The version header is the authoritative release source; a behaviour change must be visible in the artifact |
| `tests/test-automation-promotion.php` | Replaced a hardcoded `'Version: 1.1.0'` literal with a **header-vs-constant agreement** assertion | A literal would make any correct future bump look like a regression. The replacement is strictly stronger: it also catches a header bumped without the constant, which the old check could not |
| `docs/plugins/conexao-translation-rollout.md` | Endpoint deprecation documented; table updated | Maintainer-facing record |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | Regenerated by `generate-registry-docs.php --write` | Single source of truth; **not hand-edited** |
| `tests/scripts/verify-stage7-commissioning.py` | Declaration retained, note updated to record the closure | Keeps the action name watched |

No provider file, no change-detection file, no automation **shipped** include, no
stage config and no `plugins.json` entry was modified.

### Endpoint deprecation record

| | |
|---|---|
| Original action | `conexao_translation_rollout_run` |
| Original purpose | Preview / Apply / Remove one authored EN stage (Stage H) |
| Reason | It could apply EN translations with only a capability + nonce, bypassing the stage allowlist, F7, digest-bound approval, lock, audit and review |
| Disposition | **Removed.** Hard-fail deprecation stub (HTTP 410). No compatibility concern: no production plugin registers a stage |
| Replacement | `conexao-translation-automation` → `admin_post_conexao_translation_automation_proof` (Tools → Translation Automation) |
| Migration | None required. No production operator workflow used it |
| Implementation secrets | None exposed. The refusal names only its own action and its replacement |

---

## Safety gates

### New: `tests/scripts/verify-stage8-control-plane.py` — **80 assertions, 0 failed**

| Bucket | Rule |
|---|---|
| **Approved** | exactly **1** production control plane, by exact resolved action name, registered exactly once, in exactly one approved file |
| **Proof-only** | the approved endpoint exposes no `MODE_APPLY`, keeps its POST/capability/nonce chain in order (`is_post` → capability → nonce → validation → invoke), and reaches the engine only through the orchestrator |
| **Closed** | the legacy endpoint is still registered, lives in the expected file, has an auditable `handle_run`, contains **no** `call_user_func` / `run_callback` / `Engine::` token **anywhere in the file**, reads **no** superglobal, carries **no** apply/remove vocabulary, and refuses with `wp_die` rather than redirecting |
| **Forbidden** | `admin_post_nopriv_`, `wp_ajax_`, `wp_ajax_nopriv_`, `rest_api_init`, `register_rest_route`, `__return_true`, every `wp_schedule_*` / `wp_next_scheduled` / `cron_schedules`, and webhook tokens — checked in **every file of both plugins** |
| **Orchestrator integrity** | lock precedes **every real** `run_callback` invocation; F7 verdict, digest-bound approval and snapshot persistence all precede `'dry_run' => false`; the environment guard precedes it too |
| **Authority** | the engine core digest is re-pinned to `baf85283…a6ce4` |

### New: `tests/scripts/verify-stage8-release-consistency.py` — **52 assertions, 0 failed**

Asserts `plugin header == dist/release.json == the version read from INSIDE the
built ZIP` for every buildable component, plus `Requires Plugins` ==
`plugins.json` dependencies, and carries the Stage 7 finding as a permanent
regression assertion.

### Extended: `verify-stage7-commissioning.py` — **49 assertions, 0 failed**

The `DEFERRED_ENGINE_SURFACES` declaration is **retained** (so renaming or
removing the action fails closed) and its note now records the closure.

### New behavioural suite: `test-legacy-apply-endpoint-closure.php` — **36 assertions, 0 failed**

Every case is paired with an **engine-invocation counter** on a real registered
stage. Total across all 36: **engine invocations = 0**. It registers the stage
first, so a regression would be genuinely *reachable* and would fail loudly.

### Not substring-only

Per §9, endpoints are resolved to their **real action names** by following
`'admin_post_' . self::CONST` to the constant's value; ownership is asserted by
exact file; counts are exact. The webhook scan is a deliberate **substring** scan,
because a `\b` anchor cannot match `conexao_translation_webhook` (`_` is a word
character) — Stage 7's own self-defect, carried forward and re-proven.

### Negative proofs — **20/20 proven, 0 missed**

Each injects a real violation into a temporary copy and requires the gate to exit
non-zero:

| # | Injected | Gate that caught it |
|---|---|---|
| 1 | **the Stage 7 defect** — legacy apply endpoint reintroduced verbatim | control-plane (×3) |
| 2 | second admin endpoint, different action name | control-plane (name + count) |
| 3 | closed endpoint **moved to another file** | control-plane (owner) |
| 4 | anonymous `admin_post_nopriv_` | control-plane (name + count) |
| 5 | anonymous AJAX | control-plane (forbidden surface) |
| 6 | REST route | control-plane (forbidden surface) |
| 7 | cron callback | control-plane (forbidden surface) |
| 8 | webhook — `conexao_translation_webhook` (underscored token) | control-plane (substring scan) |
| 9 | direct `Engine::run(…, dry_run=false)` | control-plane |
| 10 | caller-controlled `mode=apply` via `$_REQUEST` | control-plane (×2) |
| 11 | orchestrator bypass from the entry file | control-plane (×2) |
| 12 | digest-bound approval replaced by a rubber stamp | control-plane (×2) |
| 13 | concurrency lock neutered | control-plane (lock ordering) |
| 14 | engine core digest moved | control-plane (authority pin) |
| 15 | **stale `release.json` (Stage 7 finding)** | release-consistency (×2) |
| 16 | header bumped without a rebuild | release-consistency (×2) |
| 17 | dependency metadata mismatch | release-consistency |
| 18 | recorded artifact missing from `dist/` | release-consistency |
| — | clean implementations | **both gates exit 0 (correct)** |

### Two defects the negative proofs found in my own work

Recorded rather than quietly patched:

1. **A real hole.** Negative proof 11 initially **passed the gate** when a
   `const BYPASS` closure was added to the *approved entry file* invoking
   `call_user_func($config['run_callback'], ['dry_run' => false])`. The gate only
   inspected that file's *method bodies*. Fixed with a whole-file reachability
   scan — and the fix was then **too broad**: it flagged the entry file's own
   legitimate `call_user_func(self::$orchestrator, …)` seam, a false positive
   caught by re-running the clean baseline. Resolved with a narrower token set
   (`run_callback`, `Engine::`) that permits the approved seam while still
   forbidding a stage-callback or engine call.
2. **A harness defect that faked 4 proofs.** The first negative-proof run copied
   only two plugins, so the release gate failed on *missing files* and proofs
   15–18 "passed" for entirely the wrong reason. Fixed to copy every registry
   `version_source`; all four now fail for their intended reason.

Three further assertions in the first draft were **imprecise about where the code
actually is** (the POST guard is in `handle_request()`, not `validate_request()`;
the orchestrator is invoked via `self::invoke()`; the first `run_callback`
occurrence is prose, not a call). Each was corrected to assert against the real
call site — stricter, not weaker.

---

## Release consistency

**The Stage 7 defect:** `dist/release.json` recorded
`conexao-translation-automation` at **0.2.0** while the plugin header — the
authoritative version source — said **0.3.0**. The artifact had been rebuilt but
the record had not, and nothing failed.

**Fix:** regenerated through the repository's authoritative generators
(`scripts/build-plugins-zip.sh` + `scripts/build-theme-zip.sh`, which emit
`dist/release.json` via `scripts/lib/release.py`). **No generated artifact was
hand-edited.**

**Prevention:** the new permanent gate asserts `header == release.json ==
version-inside-the-ZIP` for every buildable component, plus
`Requires Plugins` == `plugins.json` dependencies, and carries the Stage 7
mismatch as negative proof 15.

| Component | Header | release.json | Inside ZIP |
|---|---|---|---|
| `conexao-translation-rollout` | **1.2.0** | 1.2.0 | 1.2.0 |
| `conexao-translation-automation` | 0.3.0 | 0.3.0 | 0.3.0 |
| `conexao-data-model` / `content` / `admin-ux` / `event-runtime` | 1.6.1 / 1.0.1 / 1.0.7 / 1.2.2 | identical | identical |
| `conexao-br-irlanda` (theme, `style.css`) | 1.0.1 | 1.0.1 | 1.0.1 |

Release `v2026.10.01`, 10/10 artifacts, 226 files, 6,364,545 bytes.
**Determinism proven:** two independent builds → **10/10 byte-identical**.
`release-manifest.py --verify` → exit 0.

**One nuance recorded, not hidden.** `zip-build.sh` stamps each archive with the
newest source mtime, so a ZIP's sha256 legitimately changes when a file is
touched even if its content does not. Content equality was therefore verified
directly — same 22-entry file list, **0 files with differing content**. The
automation artifact hash moved because its *test* file changed; its shipped-file
tree digest is **unchanged** at `38ff2c3f29fed3d5b85a3d9f17be4942882f2d9c0827dd280931f469908383a8`,
and `tests/` is excluded from release builds.

### Production install safety (§17)

| Requirement | State |
|---|---|
| No unintended apply endpoint | ✅ the only apply-capable surface is closed; no endpoint maps to `mode=apply` |
| Exactly one approved proof entry | ✅ `admin_post_conexao_translation_automation_proof`, count 1, one file |
| No cron | ✅ asserted absent in every file of both plugins |
| No public REST | ✅ asserted absent |
| No anonymous AJAX | ✅ asserted absent |
| No webhook | ✅ asserted absent (substring scan) |
| No embedded credential | ✅ provider credential is environment-only; artifacts carry none |
| Correct versions | ✅ header == manifest == artifact |
| Correct dependency ordering | ✅ rollout → automation, via `Requires Plugins` + registry load order |

**Production installation is eligible for a later commissioning stage. Stage 8
did not install, activate or deploy anything.**

---

## Tests

All commands run from the repository root; the in-process layer executes inside
the local WordPress container. **No production contact anywhere.**

| Command | Result |
|---|---|
| `./scripts/run-tests.sh` | In-process PHP **77 suites, 75 passed, 2 failed**; **5,600 assertions passed, 6 failed**. Script contract **13 suites, 12 passed, 1 failed**. HTTP acceptance **3 suites, 2 passed, 1 failed** |
| `tests/scripts/verify-stage8-control-plane.py` | **80 passed, 0 failed** (new) |
| `tests/scripts/verify-stage8-release-consistency.py` | **52 passed, 0 failed** (new) |
| `tests/scripts/verify-stage7-commissioning.py` | **49 passed, 0 failed** (extended) |
| `test-legacy-apply-endpoint-closure.php` | **36 passed, 0 failed**; `EVIDENCE legacy-endpoint engine-invocations=0 refusal-code=410 anonymous-code=403` (new) |
| `bash stage8-work/stage8-negative-proofs.sh` | **20 proven, 0 missed** — `ALL NEGATIVE PROOODS HELD` (exit 0) |
| `./scripts/lint.sh` | **OK** — syntax clean (513 files), **no new PHPCS violations**, PHPStan level 5 clean |
| `php scripts/generate-registry-docs.php --check` | **registry OK: 15 plugins validated, 24 generated regions current (zero writes)** |
| `python3 scripts/release-manifest.py --verify` | exit **0** |
| `python3 tests/scripts/verify-release-integrity.py` | **229 passed, 0 failed** |
| `python3 tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed** |
| `python3 tests/scripts/verify-agent-governance.py` | **357 passed, 0 failed** |
| `python3 scripts/verify-permanent-gates.py` | aggregate written to `gate.json`; no new violation |

**Failing set is identical to Stage 7's** — `i18n_freshness`, `dwyer-mcallister-cottage`
(acceptance), newsletter `en_id 509`, leisure-card excerpt. Stage 8 added 1 suite
and 2 gates, both green, and introduced **no regression**. No catalogue file was
regenerated to obtain a green status.

### One failure investigated rather than waved through

A first full run showed `test-automation-trigger` and `test-automation-apply-safety`
failing with `locked`. Cause: an **earlier run of mine was killed by the tool
timeout**, leaving a stale `conexao_translation_automation_lock` option row behind.
Verified rather than assumed: pristine `HEAD` passed both; after deleting the
stale option, both passed **3/3 consecutively**; the final full run is clean. This
was harness contamination, not a code defect.

### A lint defect found and fixed

`scripts/lint.sh` correctly **failed** on my first draft: `__()` with a
`conexao-translation-rollout` text domain introduced 5 `TextDomainMismatch` and 1
`OutputNotEscaped` error. The engine's own `languages/README.md` states it
"intentionally ships no translatable runtime strings", and the sibling
`conexao-translation-automation` uses `esc_html()` with **zero** `__()` calls. The
refusal strings were changed to plain escaped text, matching that established
convention. The baseline was **not** regenerated and no gate was weakened.

---

## Engine digest

| | |
|---|---|
| **Historical baseline** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **After Stage 8** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **Delta** | **NONE — the engine core is byte-identical** |
| Engine plugin tree before | `f6085433b7ab4c3af9f264796a0c3a6b07ae86334f75a41b961e236f29b756c5` |
| Engine plugin tree after | `f7f0abc1755efd49331256fe61845b7552405194e35f2d69749ff4852fcc3c15` |
| Automation shipped-tree before/after | `38ff2c3f…908383a8` / **unchanged** |
| Provider implementation before/after | `9f4dcaf6…667a9c7df` / **unchanged** |

The tree digest moved **only** because the *admin class* and the *version header*
changed. The engine class — the mutation authority — did not move, and the
control-plane gate re-pins that digest on every run. **No changed engine file is
unrelated to the endpoint closure**, because the engine file itself was not
changed at all.

---

## Production

Exact factual state at the end of Stage 8:

| Property | State |
|---|---|
| Production content / options / routes / cron | **unchanged — nothing installed or applied** |
| PT canonical content | **unchanged** |
| EN production content | **unchanged** |
| Production installations / activations | **0** |
| Production uploads | **0** |
| Production content writes | **0** |
| Production translation | **OFF** |
| Production apply | **OFF** |
| Bulk translation | **OFF** |
| Autonomous approval | **OFF** |
| Unattended apply | **OFF** |
| Cron | **OFF** |
| Public REST / anonymous AJAX / webhooks | **none** |
| Production credentials used or printed | **none** (`WP_USERNAME` / `WP_APPLICATION_PASSWORD` unset; `.env` is the LOCAL Docker target and was deliberately not reused) |
| Provider requests | **0** |
| Approved automation admin-post endpoints | **exactly 1** (by name, count, file) |
| Legacy apply endpoint | **closed** — HTTP 410, no engine reachable |
| Engine core SHA-256 | **`baf85283…a6ce4`, unchanged** |
| Provider implementation SHA-256 | **`9f4dcaf6…667a9c7df`, unchanged** |
| Flutter/mobile repository | **untouched** |

Nothing in this stage contacted production.

---

## Existing conditions

Carried forward unchanged from Stage 7 and **not masked**:

| Condition | State |
|---|---|
| `i18n_freshness` — 3 stale catalogues (theme, `conexao-content`, `conexao-event-runtime`) | **pre-existing, deliberately NOT regenerated.** No `.pot` was touched to obtain green |
| `dwyer-mcallister-cottage` (HTTP acceptance row) | **pre-existing** |
| newsletter `en_id 509` (`test-en-jobs-shared-slug.php`) | **pre-existing** |
| leisure-card excerpt failures | **pre-existing** |
| Provider quota | **NOT TESTABLE** — no credential; never reinterpreted as success |
| Production baseline / dry-run / canary / apply | **NOT PERFORMED** — no production credential, no production read |

One condition *was* introduced during this stage and then removed, recorded
rather than hidden: a docblock naming a gettext function made
`verify-i18n-freshness.py` demand a catalogue for `conexao-translation-rollout`
that its own policy says must not exist. The comment was reworded; **the gate was
not touched**. The failing count is back to exactly 3.

**A nuance in the aggregate, reported rather than smoothed over.**
`verify-permanent-gates.py` labels the i18n failure as "2 pre-existing, **1
new**". The "new" one is `conexao-content`, and it is **not** a Stage 8
regression:

- `tests/baseline/permanent-gates.json` lists 6 components for this gate and does
  **not** include `conexao-content`, so the aggregate classifies that stale
  catalogue as new against the Stage L baseline;
- Stage 8 modified **no** file in `conexao-content` or the theme —
  `git diff --stat` over both is **empty**;
- the identical `conexao-content … by 76025s` staleness was present before any
  Stage 8 edit.

The baseline is explicitly **reporting-only** ("It NEVER SUPPRESSES A FAILURE…
It exists for reporting only"). It was deliberately **not** edited to reclassify
the violation, since that is precisely the "weaken a gate to obtain green" move
this stage forbids. The three stale catalogues remain red.

---

## Remaining conditions

Only genuine unresolved items, all **external to the repository** — none can be
fixed by any code change:

1. **Production deployment/admin credential** → `REQUIRES OPERATOR ACTION`.
2. **Provider credential** (`CONEXAO_TRANSLATION_PROVIDER_KEY`, runtime only) →
   `REQUIRES OPERATOR ACTION`.
3. **Provider quota state** → unknown until 1–2; `REQUIRES EXTERNAL PROVIDER ACCOUNT`.
4. **Plugin installation + activation in production** → `REQUIRES OPERATOR ACTION`.
5. **Fresh production baseline** → blocked by 1 and 4.
6. **Protected-trigger verification in production** → blocked by 1 and 4.
7. **Resolution of the pre-existing failures** — especially `i18n_freshness`,
   which keeps `run-tests.sh` red and could mask a real regression.

**No unresolved repository condition remains.** Stage 7's open item 7 — the
operator decision on `admin_post_conexao_translation_rollout_run` — is **closed**.

---

## Stage 9 prerequisites

For production commissioning and the first approved canary, **all** must hold:

1. Production deployment/admin credential supplied through the **env-only**
   mechanism (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`).
2. **Explicit authorization to install.** Stage 8 did not install and does not
   authorize installation. Install `conexao-translation-rollout` (1.2.0) then
   `conexao-translation-automation` (0.3.0), in `plugins.json` order.
3. `CONEXAO_TRANSLATION_PROVIDER_KEY` supplied as a **runtime secret** — never a
   `wp_options` row.
4. Live provider smoke test returns `RESULT: OK` — real transport, real
   validator, no credential emitted, no WordPress write.
5. Immediate production safety read verified: plugins, routes, cron, AJAX and
   activation effects. Expected — **no unintended apply endpoint**, **1** approved
   proof endpoint, no cron, no public REST, no anonymous AJAX.
6. **Positive confirmation that the closed endpoint refuses in production**:
   `POST admin-post.php?action=conexao_translation_rollout_run` must return
   **410 Gone**, not 200 and not a redirect. This is the one production behaviour
   Stage 8 could not observe without installing.
7. Fresh production baseline proving `bootstrap_required`, `actionable = 0`,
   `mutation_permitted = false`, `mutation_occurred = false`.
8. Protected dry-run trigger verified end-to-end with **apply unreachable** —
   `?mode=apply` must be rejected as an unknown mode.
9. Complete production dry-run with an exact actionable count and a per-record
   B1/B2 classification; every plan `REVIEW_REQUIRED`.
10. **Human quality review** of actual English output. The structural validator
    is not a quality score.
11. **Explicit canary apply authorization**, separately given, binding run ID,
    source digest, result digest, manifest/change digest, plan digest, snapshot
    digest, stage, environment and configuration identity. Installation,
    provider and dry-run authorization are **not** apply authorization.
12. Stage 9 must run the Stage 8 gates as part of its own verification — the
    control-plane and release-consistency gates must be green against the
    artifacts actually uploaded.

**The first production run must prove the architecture, not consume the backlog.**
Nothing in Stage 8 authorizes translating the remaining backlog.

---

## Evidence

| File | Proves |
|---|---|
| `01-preflight-integrity.txt` | git state, HEAD, engine/automation/provider digests at stage start |
| `02-legacy-endpoint-audit.txt` | the exact call graph, what it bypassed, the decisive `production: false` evidence, and every attack shape exercised |
| `03-engine-integrity.txt` | historical baseline vs new digest, tree digests, exact files changed |
| `04-negative-proofs.txt` | all 20 negative proofs with the failing assertion each produced |
| `05-release-artifacts.txt` | artifact table, determinism, the ZIP-timestamp nuance, the Stage 7 finding eliminated |

## Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-10-01-stage-8-legacy-apply-endpoint-closure.md` | this report |
| `docs/reports/README.md` | index row |
| `docs/testing.md` | two new blocking gates + the new suite in the gate table |
| `docs/plugins/conexao-translation-rollout.md` | endpoint deprecation record |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | **regenerated**, not hand-edited |

---

## Final safety invariant

| Property | State |
|---|---|
| Production state | **untouched** — 0 writes, 0 installs, 0 applies |
| PT canonical content | **unchanged** |
| Production translation / apply / bulk | **OFF / OFF / OFF** |
| Cron / public REST / anonymous AJAX / webhooks | **OFF / none / none / none** |
| Production mutation control planes | **exactly 1** (`admin_post_conexao_translation_automation_proof`, proof-only) |
| Legacy apply endpoint | **closed**, HTTP 410, structurally unable to reach the engine |
| Engine core | **byte-identical** to `baf85283…a6ce4` |
| Provider | **byte-identical** — no regression |
| Change detection | **unchanged** — `hooks = wake-up hint`, `inventory diff = source of truth`, no cron |
| Release metadata | **header == registry == manifest == artifact**, double-build deterministic |
| Flutter/mobile | **untouched** |

## Rollback

Revert the two engine files (`conexao-translation-rollout.php`,
`class-conexao-translation-rollout-admin.php`) and delete the two new gates plus
the new test. No production state was created and the engine core digest is
unchanged, so rollback is complete and local. **Do not roll back in production:**
the closed endpoint is the safe state.

_Last verified: 2026-10-01 by Stage 8 — legacy apply endpoint closure_