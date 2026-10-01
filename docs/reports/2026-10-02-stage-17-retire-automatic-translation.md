# Report — Stage 17: retire automatic translation automation, preserve the manual rollout

> **The architectural decision, stated once.**
>
> **Permanent automatic translation has been intentionally retired because the
> site requires translation only occasionally. The safe manual translation
> workflow is retained as the supported operating model.**
>
> When English translation is needed, run it deliberately. When it is not
> needed, nothing runs.

| | |
|---|---|
| **Stage / task name** | Stage 17 — retire permanent/automatic translation automation; retain the manual rollout |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `d4af7d28020e76d379befa997798bc14a2fb7d33` |
| **Final SHA** | unchanged — this stage made no commit; the change set is in the working tree for review |
| **Working tree at finish** | dirty — this stage's own changes only; nothing unrelated was touched |
| **Skills followed** | `wp-repository`, `wp-security-review`, `wp-testing`, `wp-write-in-process-test`, `wp-plugin-registry`, `wp-update-docs` |
| **Authoritative docs consulted** | `docs/engineering-standard.md`, `docs/plugins/conexao-translation-automation.md`, `plugins.json`, `docs/testing.md`, `scripts/README.md` |

## Stage 17 status

| Concern | Status |
|---|---|
| automatic translation automation | **RETIRED / NOT COMMISSIONED** |
| manual translation workflow | **RETAINED / TESTED** |
| production translation writes | **0 during this stage** |

This stage **deliberately does not** convert the Stage 15/16 commissioning
status into `PASS`. The purpose of Stage 17 was to retire the *requirement*, and
the readiness gate now reports `RETIRED` — a terminal state no amount of evidence
can reach — rather than a `BLOCKED` one an operator might try to satisfy.

## 1. Scope completed

1. **Stage 16 broker architecture retired.** The `Provider_Broker` class, its
   branch in `Provider_OpenAI`, the broker-token field and arming in the admin
   endpoint, its 89-assertion suite and its structural gate are all gone. The
   provider resolves its credential directly from `Provider_Config` again — the
   Stage 4 shape.
2. **Permanent automatic execution removed.** The `Hooks` class (which set a
   wake-up marker on `save_post`, `before_delete_post`, `wp_trash_post`,
   `untrashed_post` and `set_object_terms`), its registration, its 50-assertion
   suite and the `MARKER_OPTION` entry in the exhaustive option allow-list are
   gone. The `hook` and `scheduled` audit trigger kinds are gone too, so the
   audit trail can no longer *name* an unattended run even by accident.
3. **Automatic commissioning retired.**
   `commissioning.AUTOMATIC_TRANSLATION_RETIRED` is the single source of truth.
   `evaluate()` still evaluates all eighteen prerequisites and every integrity
   invariant and *then* forces the aggregate to `RETIRED`, so `READY` is
   unreachable and `commissioning_permitted` is permanently false. The
   nineteen-step automatic-commissioning runbook was replaced by the ten-step
   manual workflow.
4. **Manual workflow preserved and proven end to end** (§9).
5. **Two new gates** make the retirement unable to silently return (§10).

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| **Commissioning apply** (`Batch_Control::register()`) | Already declared-and-uncommissioned since Stage 11. Commissioning it is a *separate, separately authorised production action*, and §12 of the brief sets `PRODUCTION WRITES = 0`. Left exactly as Stage 11 left it. |
| **A live OpenAI call** | No provider credential exists in this environment and the brief sets `PRODUCTION WRITES = 0`. No provider result was fabricated. The provider contract was exercised through the repository's existing credential seam instead (§9). |
| **A new P15 production stop-state surface** | §8 of the brief: do not fabricate one. See §8 below. |
| **Deleting the Stage 13 readiness engine** | Refactored instead of deleted — see the justification in §8 and §13. |
| **Committing** | No commit was made; the tree carries the change set for review. |

## 3. Files added / modified / deleted

**2 added, 12 modified, 2 deleted.**

| Path | Change | Note |
|---|---|---|
| `tests/scripts/verify-stage17-retirement.py` | **added** | The retirement gate. 110 assertions, 9 injected mutations. |
| `…/tests/test-manual-translation-workflow.php` | **added** | The retained manual workflow, end to end. 49 assertions. |
| `…/includes/class-conexao-translation-automation-hooks.php` | **deleted** | The automatic wake-up marker. |
| `…/tests/test-automation-hooks.php` | **deleted** | Its 50 assertions validated the deleted wake-up mechanism. |
| `…/conexao-translation-automation.php` | modified | Broker require removed; `Hooks::register()` removed; version `0.5.0` → `0.6.0`; header and surface docblock rewritten to the manual model. |
| `…/includes/class-conexao-translation-automation-trigger.php` | modified | Docblock records the Stage 17 retirement of the wake-up layer. No logic change. |
| `…/includes/class-conexao-translation-automation-audit.php` | modified | `TRIGGER_HOOK` and `TRIGGER_SCHEDULED` removed. |
| `…/tests/test-automation-boundary.php` | modified | `Hooks::MARKER_OPTION` removed from the exhaustive option allow-list (148 → 147). |
| `…/tests/test-automation-trigger.php` | modified | `Hooks` import and the two hook-file scan loops retired (102 → 99). |
| `…/tests/test-automation-audit.php` | modified | Uses `TRIGGER_ADMIN_PROOF` where it used the deleted `TRIGGER_HOOK`. Count unchanged (80). |
| `scripts/lib/commissioning.py` | modified | `RETIRED` status, `AUTOMATIC_TRANSLATION_RETIRED`, per-prerequisite `disposition`, retired aggregate, manual runbook. |
| `scripts/verify-commissioning-readiness.py` | modified | Leads with the retirement; exits 0 on `RETIRED`; version pin `0.4.0` → `0.6.0`. |
| `tests/scripts/verify-stage13-commissioning-readiness.py` | modified | Aggregate assertions retargeted to `RETIRED`; two proofs rewritten to assert the underlying property. 771 → 808 assertions. |
| `docs/plugins/conexao-translation-automation.md` | modified | Operating model, credential provenance, operator runbook, apply-not-commissioned note. |
| `docs/testing.md`, `scripts/README.md` | modified | The two new gates and the retired readiness engine. |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | modified | **Generated** by `generate-registry-docs.php --write` (version only). Not hand-edited. |

**`conexao-translation-rollout` was not modified at all.** Engine SHA-256 before
== after == `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912`,
and `git diff --stat` over the plugin directory is empty
(`03-engine-integrity.txt`).

### Stage 16 was never committed

An important scoping fact: the Stage 16 broker implementation existed only as
**uncommitted working-tree changes** on top of `d4af7d2`. Retiring it therefore
meant reverting those changes and deleting its new files, not unwinding a
shipped feature. `0.5.0` was reserved for the broker and never released, so the
version number is deliberately **not** reused: this stage ships `0.6.0`.

## 4. Runtime impact

- Loading the plugin now registers **exactly one** surface: the authenticated
  admin endpoint and its Tools screen. Previously it registered two.
- Editing a PT post no longer writes a wake-up marker option. One option write
  per PT save is gone.
- Nothing on the site can start a translation run on its own.

## 4a. Scope integrity

- `plugins.json` load order, production set, build and mount flags: **unchanged**.
- `dist/release.json` rebuilt from source (10 artifacts, deterministic, secret-free).
- `generate-registry-docs.php --check`: 15 plugins, 24 regions, zero drift.
- `.htaccess`, `compose.yaml`, `.github/workflows/ci.yml`: **untouched**.
- The Flutter/mobile repository: **not accessed at any point**.

## 5. Content / data impact

**None.** No PT or EN record was created, modified or deleted. The manual
workflow suite uses the repository's own in-memory adapter, so "applied" means
the engine drove the adapter — not that WordPress content changed.

## 6. Polylang impact

**None.** Polylang remains the language/relationship layer and is untouched. PT
remains canonical; `/en/empregos/` and the newsletter and Jobs routing policy are
unchanged; B1 and B2 policy is unchanged.

## 7. Route / HTTP impact

**None.** No route, filter, redirect or `/en/` layer was touched. The retired
`Hooks` class registered no route.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | No ZIP was uploaded. |
| Plugin install / activation | **no** | |
| Content or DB mutation | **no** | |
| Polylang change | **no** | |
| Provider call | **no** | No credential is present; no request was issued. |
| Translation run | **no** | |
| SFTP / SSH / WP-CLI / phpMyAdmin | **no** | Not available on WordPress.com Personal and not used. |

**Production translation writes: 0.**

### P15 (Stage 15's open finding)

Stage 15 identified that P15 — "production must be positively observed STOPPED"
— had no production read surface. **Stage 16 added no P15 implementation**, and
Stage 17 added none either.

The resolution is the one §8 of the brief anticipates: **the permanent
automatic-commissioning gate became unnecessary because automatic commissioning
itself is retired.** P15 existed solely to clear a prerequisite on the way to
commissioning unattended translation. With no commissioning to perform, the
prerequisite is classified `RETIRED` in the registry with its reason recorded
inline. **No new production diagnostics surface was fabricated**, and the
permanent safety model was not weakened: P15's code and proofs are untouched, and
the emergency stop — a generic control that remains useful to a manual run — is
fully retained and still asserted fail-closed and immovable from a request.

### Why the readiness engine was refactored, not deleted

`scripts/lib/commissioning.py` also owns three things other gates depend on: the
pinned engine digest (consumed by `verify-stage14-secret-scan.py`), the
secret-boundary helpers, and the batch-ceiling definitions. Deleting it would
have destroyed real safety coverage to express a decision that one constant
expresses exactly — and would have created a second source of truth for the
engine digest. It was therefore refactored: every prerequisite and every negative
proof is retained and still runs; only the *aggregate decision* changed.

## 9. Verification commands and results

### The real manual workflow, end to end (local Docker WordPress)

`…/tests/test-manual-translation-workflow.php` → **49 passed, 0 failed**
(`07-manual-workflow-e2e.txt`). One throwaway target: record `a`, a PT record
with no EN counterpart. Record `b` already has one and proves the second apply is
a genuine no-op.

| Step | Measured result |
|---|---|
| Manual request, unauthenticated | refused, control plane reached **0** times |
| Manual request, no `manage_options` | refused, control plane reached **0** times |
| Manual request, invalid nonce | refused, control plane reached **0** times |
| Manual request, all gates satisfied | **accepted**, control plane reached exactly **1** time, recorded as `admin_proof`, `bootstrap` forced false |
| `mode=apply` on the manual endpoint | **refused**, control plane reached **0** further times |
| Inventory + manifest | config valid, manifest valid |
| Dry run | **1** create planned, **1** skip, **0** updates, **0 writes** |
| Snapshot | unchanged PT → empty diff |
| Scoped dry run (approved scope = `['a']`) | **1** create, still **0 writes** |
| Explicit apply | **1** created; `en_id` 0 → >0 |
| **approved == planned == executed** | **1 == 1 == 1** |
| Actual mutation set vs approved set | **1** row touched, **0** extras |
| Verify / idempotence | **0** created, **0** updated |
| PT immutability | title, language and identity of **both** PT records unchanged |
| Colliding `en_slug` | refused, zero writes |
| PT drift | detected by snapshot diff |
| Unknown mode / unknown stage | both refused, never guessed |
| Provider with no credential | refuses; **0** outbound requests |
| Credential provenance | `CONEXAO_TRANSLATION_PROVIDER_KEY`, environment-only |
| Classes declaring a credential-storing option | **0** |

**No real provider call was made, and none is claimed.** The provider contract is
proven at its fail-closed boundary; a live smoke remains a separately authorised
action (`tests/live-provider-smoke.php`).

### `./scripts/run-tests.sh`

| Layer | Baseline (`d4af7d2` + Stage 16) | After Stage 17 |
|---|---|---|
| In-process PHP | **82** suites, **80** passed, **2** failed | **81** suites, **79** passed, **2** failed |
| Assertions | **6,212** passed, **6** failed | **6,118** passed, **6** failed |
| Script-contract | **17** suites, **16** passed, **1** failed | **17** suites, **16** passed, **1** failed |
| HTTP acceptance | **3** suites, **2** passed, **1** failed | **3** suites, **2** passed, **1** failed |

**The −94 assertions are fully accounted for**, per suite:

```text
-89  test-automation-credential-path.php   (Stage 16 broker suite, deleted)
-50  test-automation-hooks.php             (wake-up hook suite, deleted)
+49  test-manual-translation-workflow.php  (new manual workflow suite)
 -1  test-automation-boundary.php          (MARKER_OPTION allow-list entry)
 -3  test-automation-trigger.php           (two hook-file scan loops retired)
----
-94
```

Nothing was silently lost: every removed assertion validated retired architecture.

### Other gates

| Command | Result |
|---|---|
| `tests/scripts/verify-stage17-retirement.py` | **110 passed, 0 failed** |
| `tests/scripts/verify-stage13-commissioning-readiness.py` | **808 passed, 0 failed**, **44** negative proofs, **0 missed** (was 771) |
| `tests/scripts/verify-stage14-secret-scan.py` | **322 passed, 0 failed** |
| `verify-stage3` / `4` / `6` / `7` / `8` / `8-release` | 172 / 148 / 49 / 71 / 147 / 52, all **0 failed** |
| `verify-release-integrity.py` | **229 passed, 0 failed** |
| `verify-documentation-drift` / `agent-governance` / `script-conventions` / `cache-key-scoping` | 14 / 357 / 327 / 2, all **0 failed** |
| `scripts/verify-commissioning-readiness.py` | reports **`RETIRED`**, exits **0** |
| `generate-registry-docs.php --check` | 15 plugins, 24 regions, zero drift |
| `./scripts/lint.sh` | **`lint: OK`** — syntax clean, **no new** PHPCS violations, PHPStan level 5 clean |
| PHPCS debt | 3,231 / 2,668 against a baseline of 3,290 / 2,672 — **18 sniffs improved**, baseline **not** regenerated |

## 10. Failure proofs / negative tests

The new retirement gate was proven against **9 injected mutations**. Every one
failed closed, and the source was restored and re-verified green after each
(`06-retirement-gate-mutations.txt`).

| Mutation | Caught by | Failed closed |
|---|---|---|
| Re-add a single-quoted `add_action('save_post', …)` | wake-up hook scan | ✅ |
| Re-add a **double-quoted** `add_action( "save_post", … )` | wake-up hook scan | ✅ **only after a gate fix — see below** |
| Re-add `wp_schedule_event()` | scheduler scan | ✅ |
| Re-add the `scheduled` audit kind | audit vocabulary | ✅ |
| Re-declare a credential `wp_options` constant | credential-option scan | ✅ |
| Re-add an anonymous `wp_ajax_nopriv_` endpoint | anonymous-surface scan | ✅ |
| Flip `AUTOMATIC_TRANSLATION_RETIRED` back to `False` | readiness section (both gates) | ✅ |
| Re-classify `P09` (apply authorization) as `RETIRED` | **both** gates | ✅ |
| Re-add the broker class and its token | broker section | ✅ |

### Two mutations exposed real defects in the new gate

This is the part worth reading. A gate that has never been attacked is a
hypothesis, not a check.

1. **The hook pattern was single-quote-only.** `add_action( "save_post", … )`
   sailed straight past it. Both quote styles are now matched.
2. **The scanner blanked string literals — so it could not see a hook name at
   all.** The banned thing (`add_action('<hook>')`) *is* a string. The fix was to
   scan shipped, non-test files with comments stripped but **string literals
   kept**: comments are what carry prose, strings are what carry hooks.

Both were fixed rather than documented away, and the mutations that found them
now assert the fixes.

Two further defects in this stage's own work were found and fixed the same way:
the retirement initially made two Stage 13 negative proofs vacuous (they asserted
a specific aggregate status), and the readiness preflight initially exited 1 on
`RETIRED` despite documenting 0. Both are corrected above and in §9.

## 11. Regression comparison

| Metric | Before | After | New failures | Resolved |
|---|---|---|---|---|
| In-process suites | 82 (80 ✅ / 2 ❌) | 81 (79 ✅ / 2 ❌) | **0** | 0 |
| Assertions | 6,212 ✅ / 6 ❌ | 6,118 ✅ / 6 ❌ | **0** | 0 |
| Script-contract suites | 17 (16 ✅ / 1 ❌) | 17 (16 ✅ / 1 ❌) | **0** | 0 |
| HTTP acceptance | 3 (2 ✅ / 1 ❌) | 3 (2 ✅ / 1 ❌) | **0** | 0 |
| Engine digest | `264cc6c4…6912` | `264cc6c4…6912` | — | unchanged |
| Lint | OK | OK | 0 | 0 |

**The failing set is identical before and after.** The two failing in-process
suites, the failing script-contract gate and the failing HTTP row are the same
conditions, reproduced.

## 12. Known pre-existing failures

Unchanged, carried forward, and **not** reclassified to obtain green:

| Failure | Condition |
|---|---|
| `test-en-jobs-shared-slug.php` (1 assertion) | newsletter `en_id 509` |
| `test-leisure-card-excerpt-language.php` (5 assertions) | leisure-card excerpts |
| `verify-i18n-freshness.py` (3 catalogues) | stale `.pot` files — **no catalogue regenerated here** |
| `verify-routing-http.py` (1 row) | the same leisure-card excerpt condition, over HTTP |

No gate was weakened, no allowlist expanded, no threshold altered, no test
suppressed, and no genuine failure reclassified as baseline debt.

## 13. Limitations

1. **Apply is not commissioned**, so a production manual run stops at a reviewed
   plan. That is the Stage 11 posture, unchanged — not a regression — but it
   means §19.12's "apply remains enforced" holds in the sense that the approval
   and apply controls are intact and asserted, **not** in the sense that apply is
   newly reachable.
2. **No live provider call.** Stated plainly rather than papered over.
3. The Stage 13 readiness engine still contains machinery for a commissioning
   decision that no longer applies (see §8). Its cost is documentation debt, not
   capability.
4. `dist/` is gitignored; the release record was rebuilt locally so the readiness
   and release gates stay honest.

## 14. Evidence paths

`docs/evidence/2026-10-02-stage-17-retirement/`

| File | Contents |
|---|---|
| `01-baseline-full-test-run.txt` | the pre-change full suite |
| `02-final-full-test-run.txt` | the post-change full suite |
| `03-engine-integrity.txt` | engine digest + empty engine diff |
| `04-retirement-gate.txt` | the 110-assertion retirement gate |
| `05-stage13-readiness-gate.txt` | 808 assertions, 44 proofs, 0 missed |
| `06-retirement-gate-mutations.txt` | the 9 injected mutations |
| `07-manual-workflow-e2e.txt` | the manual workflow, end to end |
| `08-readiness-preflight-retired.txt` | the preflight reporting `RETIRED` |
| `09-manual-operator-runbook.md` | the generated manual runbook |
| `10-lint.txt` | `lint: OK` |

## 15. Documentation updated

- `docs/plugins/conexao-translation-automation.md` — the operating model, where
  the credential comes from, the operator runbook, and the explicit note that
  apply is not commissioned.
- `docs/testing.md` — both new gates registered; the Stage 13 row updated.
- `scripts/README.md` — the retired readiness engine and preflight.
- Generated registry regions (version only): `docs/architecture.md`,
  `docs/plugins/README.md`, `docs/project-inventory.md`.

The documentation states, in the terms the brief required: the site does not run
permanent automatic OpenAI translation; English translation is on demand through
the existing shared engine; PT remains canonical; Polylang manages bilingual
relationships and routing but does not translate; OpenAI is an optional provider
for a manually requested run; no permanent provider credential is required in
WordPress.com; no broker is part of the supported architecture. It does **not**
claim a credential mechanism exists on WordPress.com Personal.

## 16. Rollback / recovery

A single revertible change set: no migration, no data change, nothing in
production.

```bash
git checkout -- wp-content/ scripts/ tests/ docs/
git clean -fd docs/evidence/2026-10-02-stage-17-retirement
php scripts/generate-registry-docs.php --write
```

Rolling forward again is equally safe: the retirement is expressed by two things
(the deleted `Hooks` class and `AUTOMATIC_TRANSLATION_RETIRED`), and both are
covered by gates that fail closed if only half is applied.

## 17. Final status

**`PASS` for the scope of this stage: retirement of automatic translation
automation, with the manual workflow retained and proven.**

```text
automatic translation automation:   RETIRED / NOT COMMISSIONED
manual translation workflow:        RETAINED / TESTED
production translation writes:      0 during this stage
```

Permanent automatic translation has been intentionally retired because the site
requires translation only occasionally. The safe manual translation workflow is
retained as the supported operating model.

---

_Last verified: 2026-10-02 by Stage 17 — retirement of automatic translation
automation (81 in-process suites / 6,118 assertions; 17 script-contract;
3 HTTP acceptance; lint OK; engine integrity before == after;
**production changes 0**; 9 injected mutations all failed closed)._
