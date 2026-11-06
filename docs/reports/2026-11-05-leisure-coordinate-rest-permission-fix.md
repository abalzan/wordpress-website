# Report — Lazer coordinate REST write permission (`auth_callback` fix)

| | |
|---|---|
| **Stage / task name** | Stages 1–5 of the leisure-location sequence: the narrow `auth_callback` fix, the permission/registration regression suite, local verification, the full gates, and release preparation |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `b2ea4e6226e31cb42bb47921dfe2d8616911e44e` (`test: add release-preparation evidence for the Lazer proximity branch`) |
| **HEAD during the task** | advanced to `dc79f26` (`Add mock API validation for leisure location migration script`) by an **external commit** at 14:18 UTC — **not made by this task** (see §2) |
| **Final SHA** | uncommitted (committing and pushing are explicitly out of scope for this task) |
| **Working tree at finish** | dirty — this task's 2 modified plugin files, 1 new untracked test directory, and the new documentation/evidence files |
| **Skills followed** | `wp-add-content-type`, `wp-security-review`, `wp-write-in-process-test`, `wp-testing`, `wp-release-deploy` (preparation only), `wp-update-docs`, `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §2.1, §4.2, §5.2, §6.3, §7, §8, §9, §11), `docs/testing.md`, `docs/releases.md`, `docs/content-model.md` §"Leisure coordinates", `docs/routing.md`, `docs/plans/2026-11-05-leisure-location-production-migration.md`, `docs/reports/2026-11-05-leisure-location-production-audit.md`, `plugins.json`, `docs/templates/report.md` |

## 1. Scope completed

Mapped to the ten stages of the request. **This report covers Stages 0–5 only**; Stages 6–10
(production deployment, controlled production write test, dry-run, apply, post-migration
verification) each require explicit user authorisation at their checkpoint and were **not**
performed — see §2.

| Stage | Status | What was delivered |
|---|---|---|
| 0 — Preflight | **done** | Read `AGENTS.md`, the engineering standard, `docs/testing.md`, the audit report and the migration plan; inspected the metadata registrations, the migration script and its mock-API suite; recorded the exact git state; confirmed all pre-existing work is preserved |
| 1 — Narrow `auth_callback` fix | **done** | `auth_callback` on `_leisure_lat` and `_leisure_lng` only, using the repository's established `current_user_can( 'edit_post', $object_id )` pattern; plugin bumped 1.6.2 → 1.6.3 |
| 2 — Permission + registration tests | **done** | New in-process suite, 74 assertions: registration, authorised write, anonymous/unprivileged denial, REST-time sanitisation and narrowness regressions. **Proven to discriminate** (45 passed / 29 failed with the fix reverted) |
| 3 — Local metadata/permission verification | **done** | In-process suite green, plus a **real loopback HTTP** proof with a WordPress Application Password (200 authorised / 401 anonymous / persisted / cleared) |
| 4 — Full suite + permanent gates | **done, with one pre-existing failure** | `./scripts/run-tests.sh` and `php scripts/generate-registry-docs.php --check` green; `./scripts/lint.sh` is **red at HEAD on a pre-existing PHPCS baseline drift**, proven identical on a pristine `git archive HEAD` (§12) |
| 5 — Release preparation | **done** | `conexao-data-model` 1.6.3 built, `dist/release.json` regenerated and verified, release record `G19` added to `docs/releases.md`, rollback documented (§16) |
| 6 — Production deployment | **NOT PERFORMED — awaiting explicit authorisation** | §2 |
| 7 — Controlled production REST write test | **NOT PERFORMED — awaiting explicit authorisation** | §2 |
| 8 — Production dry-run | **NOT PERFORMED** (needs the deployed fix first) | §2 |
| 9 — Production apply | **NOT PERFORMED — awaiting explicit authorisation** | §2 |
| 10 — Post-migration verification | **NOT PERFORMED** (nothing was migrated) | §2 |

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| **No production deployment** | `AGENTS.md` and the task's own governance section state that no production action is authorised merely because the prompt exists. Stage 6 requires an explicit authorisation against the exact release contents; that checkpoint is presented at the end of this report. |
| **No controlled production REST write test** | Same reason (Stage 7 checkpoint). It also **must not** run before the fix is deployed — that was the point of the audit finding. |
| **No production dry-run, apply or post-migration verification** | Stages 8–10 are downstream of the deployment and each carries its own checkpoint. |
| **No production request of any kind** | This task issued **no** request at all — not even a read — against `https://conexaobr.ie`. |
| **No commit, no push** | Explicitly prohibited. HEAD therefore still points at `dc79f26` with this work uncommitted. |
| **The PHPCS baseline was not regenerated** | Regenerating it would hide a pre-existing regression, which the task forbids. The drift is reported, proven pre-existing, and left alone (§12). |
| **No change to `_conexao_featured` / `_event_date` / `_sponsor_link` / `_job_company`** | They share the "protected meta without an `auth_callback`" shape but are outside this task's scope. The suite **asserts they still refuse REST writes**, so this change cannot silently widen them. |

### A note on the moving HEAD

At the start of this task `git status` showed the working tree carrying the previous task's uncommitted
work (the audit report, the migration plan, `scripts/migrate-leisure-location-rest.py`,
`tests/scripts/verify-leisure-location-migration.py`, the audit evidence directory, three modified index
files, and one odd `deleted: ''` entry). **That work was committed externally at 14:18 UTC as `dc79f26`**
while this task was running. This task did not commit, did not push, did not reset, did not clean, did not
touch any stash entry, and did not modify or revert any of those files. All nine stash entries
(`stash@{0}` … `stash@{8}`) are still present and unchanged.

## 3. Files added / modified / deleted

**3 added, 8 modified, 0 deleted** (plus the evidence files listed in §14).

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-data-model/includes/class-meta.php` | **modified** | The fix: `auth_callback` added to the `_leisure_lat` and `_leisure_lng` registrations, plus the docblock explaining why protected meta needs one. No other registration touched. |
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | **modified** | Version bump only: header `Version: 1.6.3` + `const VERSION = '1.6.3'`. |
| `wp-content/plugins/conexao-data-model/tests/test-leisure-coordinate-rest-permissions.php` | **added** | The 74-assertion suite. This is a new `tests/` directory for the plugin; `./scripts/run-tests.sh` discovers it by convention, so no runner list was edited. |
| `docs/content-model.md` | **modified** | The REST column for both coordinate keys now reads "readable **and writable** over REST for a user who can edit the leisure record", plus a paragraph on the permission rule and on what the fix does **not** widen. |
| `docs/releases.md` | **modified** | New `G19` release entry (artifact, version, what ships, base commit, verification, production actions = none, content dependency) and a `_Last verified_` marker. |
| `docs/plugins/conexao-data-model.md`, `docs/plugins/README.md`, `docs/architecture.md`, `docs/project-inventory.md` | **modified (generated)** | Regenerated by `php scripts/generate-registry-docs.php --write`. The only change in each is `1.6.2` → `1.6.3`. Never hand-edited. |
| `docs/README.md`, `docs/reports/README.md` | **modified** | Index entry for this report plus `_Last verified_` markers. |
| `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md` | **added** | This report. |
| `docs/evidence/2026-11-05-leisure-coordinate-rest-permission/*` | **added** | Machine evidence (§14). |

Nothing outside `wp-content/plugins/conexao-data-model/` changed in application code. No file under
`wp-content/themes/`, `wp-content/plugins/conexao-admin-ux/` or any other plugin changed.


## 4. Runtime impact

**Exactly one behaviour changes, by design: an authorised REST write to `_leisure_lat` / `_leisure_lng`
now succeeds instead of returning `403 rest_cannot_update`.** Everything else is unchanged:

| Surface | Before | After |
|---|---|---|
| REST read of both keys | allowed | allowed (unchanged) |
| REST write, user who can edit the leisure record | **403 `rest_cannot_update`** | **200**, sanitised, stored and returned |
| REST write, anonymous | 401 | 401 (unchanged) |
| REST write, authenticated without the capability | 403 | 403 (unchanged) |
| REST visibility of `_leisure_coord_source` / `_leisure_coord_verified` | not exposed | not exposed (unchanged) |
| Coordinate validation (`sanitize_callback`) | range-validated on every write path | identical — an out-of-range value still sanitises to empty |
| Front-end behaviour, coordinate format, data semantics | — | unchanged: values stay canonical dot-separated decimal **strings**; `get_coordinates()` and `coordinate_status()` behave identically |
| `_leisure_image_*` writes, `_leisure_town` writes | allowed for an editor | allowed (asserted) |
| Other protected meta without an `auth_callback` | refused over REST | refused (asserted — no global weakening) |

## 5. Content / data impact

**None in any environment.** No production record was created, updated, read or deleted. Locally the
database only ever held the suite's own temporary `leisure`/`guide` posts and one temporary subscriber,
all created and deleted inside the run (the suite asserts no leftovers). No taxonomy term was created and
no Polylang relationship was touched.

## 6. Polylang impact

**None.** The change is a metadata permission declaration: it does not read or write locales, does not
create or link translations, and adds no cache key. `conexao_current_locale()` is not involved. PT records,
PT slugs and PT identity are untouched because no content was written at all.

## 7. Route / HTTP impact

**No route is added, removed or changed.** The REST route shape is unchanged
(`POST /wp-json/wp/v2/leisure/<id>` with a `meta` body); only its permission decision changed. The theme's
`GET /conexao/v1/leisure-coordinates` endpoint is untouched and returns the same payload; it is covered by
the existing theme suite in the same `./scripts/run-tests.sh` run. No redirect, canonical, hreflang or
sitemap behaviour is affected.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | No POST/PUT/PATCH/DELETE was ever issued to `https://conexaobr.ie` |
| Production read | **no** | This task issued **no** request at all to production |
| Deploy / upload | **no** | No artifact uploaded, no theme/plugin installed |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | No migration, no admin-screen save, no SQL |
| Flutter/mobile repository | **not accessed** | |
| `.env` / secrets | **unchanged** | The local HTTP proof created and revoked its own Application Password in-process and never printed it |


## 9. Verification commands and results

Every command below was actually executed; the numbers are the real output.

| Command | Exit | Result |
|---|---|---|
| `php -l` on both modified files and the new suite | 0 | no parse errors |
| permission suite, **fix reverted** | 1 | **45 passed, 29 failed** (the discriminating red run) |
| permission suite, **with the fix** | 0 | **74 passed, 0 failed** |
| local loopback HTTP proof (Application Password created + revoked per run) | 0 | GET 200 (empty) → POST 200 (`53.3498` / `-6.2603`) → POST anonymous **401 `rest_cannot_edit`** → GET 200 (persisted) → POST clear 200 (empty) |
| `./scripts/run-tests.sh` (all three layers, before the release rebuild) | 1 | in-process **118 suites: 106 passed, 12 failed**; assertions **9 157 passed, 43 failed**. Script-contract **20 suites: 19 passed, 1 failed**. Acceptance **4 suites: 1 passed, 3 failed**. All 15 failing suites proven pre-existing (§10/§12) |
| `./scripts/run-tests.sh --scripts` (after the release rebuild) | 0 | **20 suites, 20 passed, 0 failed**, including `verify-stage8-release-consistency.py` (47 assertions) |
| `./scripts/lint.sh` | 1 | syntax **OK**; PHPStan **OK (no errors)**; **PHPCS legacy-baseline gate FAILED** — pre-existing, proven identical on a pristine `git archive HEAD` (§12) |
| `php scripts/generate-registry-docs.php --write` then `--check` | 0 / 0 | 10 plugins, 19 generated regions; `--check` reports zero writes (no drift) |
| `./scripts/build-plugins-zip.sh` + `./scripts/build-theme-zip.sh` | 0 | 10/10 artifacts built, `release=v2026.11.05` |
| `python3 scripts/release-manifest.py --verify` and `--check` | 0 / 0 | allowlist 10, built 10/10, 249 files, 6 563 978 bytes; `conexao-data-model` **1.6.3**, 6 files, `sha256 c1a834406c39cdac…` |
| `./scripts/verify-release.sh` | 1 | stages **1–6 pass** (build, manifest, allowlist+hash, determinism byte-for-byte, exclusion proof); stage 7 (read-only HTTP deployment verification) fails on the **same pre-existing `/empregos/` 302 row** (§12) |

### Numeric test results

- **New suite: 74 assertions passed, 0 failed.** With the fix reverted: **45 passed, 29 failed**.
- **Whole in-process layer: 118 suites, 106 passed, 12 failed; 9 157 assertions passed, 43 failed.**
  Every one of the 12 failures reproduces with identical counts on the unfixed code.
- Targeted suites that cover this feature, all green in the same run:
  `theme/conexao-br-irlanda/test-leisure-location-services.php` **102 passed**,
  `plugin/conexao-admin-ux/test-leisure-coordinates.php` **29 passed**,
  `theme/conexao-br-irlanda/test-leisure-attribute-normalization.php` **22 passed**.

### HTTP acceptance

4 suites, **1 passed** (`verify-guides-en-http.py`, 20 assertions), 3 failed
(`verify-release-http.py` 1 matrix row, `verify-routing-http.py` 6 matrix rows,
`verify-event-occurrence-http.py` 2 rows) — all three proven pre-existing (§12).

### Static analysis

PHP syntax clean; **PHPStan level 5 clean (`[OK] No errors`, 215 files)**; PHPCS reports the
same legacy profile before and after this change on both touched files
(`class-meta.php` 15 errors / 1 warning; `conexao-data-model.php` 26 errors / 67 warnings) —
the aggregate baseline gate is red for **pre-existing** reasons, not because of this change.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **20 suites, 20 passed, 0 failed** after the release rebuild.

### Release / build results

10 artifacts, `dist/release.json` verified against `plugins.json` and re-hashed (**10/10**),
determinism proven **byte-for-byte**, exclusion proof passed (no `tests/`, fixtures, reports or
junk inside any artifact — verified independently with `unzip -l dist/conexao-data-model.zip`:
9 entries, no `tests/`). Release tag **`v2026.11.05`**, git SHA `dc79f26d1739`,
`dirty: true` (this work is uncommitted). Deployment verification: **not performed** —
nothing was deployed.


## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| The new suite fails without the fix | **45 passed, 29 failed**, with exactly the expected failures: `_leisure_lat` falls back to the core default, the authorised REST update returns **403 `rest_cannot_update`**, and the value does not persist |
| The A/B itself was real (control) | The permission suite went **74/0 → 45/29** inside the same experiment that produced the twelve identical pre-existing results (§14) |
| The PHPCS baseline failure is not ours | The same gate fails on a pristine `git archive HEAD` with the **identical** six-sniff list and identical counts |
| Anonymous writes are refused | 401 `rest_cannot_edit`, stored values unchanged (asserted twice: in-process and over real HTTP) |
| Unprivileged writes are refused | 403 `rest_cannot_edit`, stored values unchanged |
| The fix did not weaken protected meta globally | An unrelated protected key (`_conexao_featured`, `guide`) **without** its own `auth_callback` still returns **403 `rest_cannot_update`** |
| The fix did not bypass validation | `_leisure_lat = 999` over REST is accepted by the schema (200) and **sanitised to empty** — the range rule still owns the value |

## 11. Regression comparison

| | Before (fix reverted, `HEAD` content) | After (fix applied) |
|---|---|---|
| New permission suite | 45 passed, **29 failed** | **74 passed, 0 failed** |
| Twelve pre-existing failing in-process suites | 12 failed | 12 failed (identical counts) |
| Script-contract layer | 19/20 (artifact version stale) | **20/20** |
| Acceptance suites | 3 failed | 3 failed (identical counts) |

The diff of the failing lists is **empty except for the new suite**, which moved from red to
green. No suite that passed before this change fails after it.

## 12. Known pre-existing failures

| Failure | What it actually is | Proof it is not ours |
|---|---|---|
| `plugin/conexao-event-importer/test-town-sanitization.php` (1) | Eircode-contaminated terms in the local `conexao_town` taxonomy | identical counts with the fix reverted |
| 11 theme EN/Polylang suites (3+4+5+7+4+6+5+2+2+2+2 assertions) | The local database is not in the expected bilingual state: two EN `empregos` pages and no PT one, unlinked `conexao_category` EN terms (`linked=1 used=6`), EN blog posts without the PT dates, Polylang term lookups returning `null` | identical counts with the fix reverted; the assertions name post/term IDs, never a coordinate |
| `tests/acceptance/verify-release-http.py` (1 row), `verify-routing-http.py` (6 rows) | `/empregos/` answers **302 → `/en/empregos/`** because the local DB has no PT `empregos` page; every other archive returns 200 | identical rows fail with the fix reverted; `git status` shows no theme or redirect change in this task |
| `tests/acceptance/verify-event-occurrence-http.py` (2) | Laois archive content conditions | identical, and already failing in the committed `2026-11-03` / `2026-11-04` evidence |
| `./scripts/lint.sh` — PHPCS baseline | The baseline was generated 2026-09-25 over **156** files; the repository now scans **214**, so six sniffs exceed it | the same gate fails on a pristine `git archive HEAD` (§14, `phpcs-baseline-drift-proof.txt`) |

**None of these were fixed, baselined away or skipped.** They are reported, counted, and left
to their own change.

## 13. Limitations

| Could not be verified | Why |
|---|---|
| **Production behaviour** | Nothing was deployed (no authorisation). Everything here is local. |
| **A production Application-Password write** | Stage 7 checkpoint — needs explicit authorisation and is only meaningful after the fix is deployed. |
| **Real-world load behaviour of the REST write path** | Out of scope: this is a permission declaration, not a hot path. |
| **The 15 pre-existing failing suites** | They belong to a drifted local database and to a stale PHPCS baseline; fixing them here would be scope creep and, for the baseline, a forbidden way to make a gate green. |
| **`verify-release.sh` stage 7** | Fails on the same `/empregos/` local condition; stages 1–6 — the part that proves the *artifact* — pass. |


## 14. Evidence paths

`docs/evidence/2026-11-05-leisure-coordinate-rest-permission/`

| File | What it proves |
|---|---|
| `rest-permission-suite-before-fix.txt` | The new suite is **red** on `HEAD` code: 45 passed, **29 failed**, with `403 rest_cannot_update` on both keys |
| `rest-permission-suite-after-fix.txt` | The same suite is **green** with the fix: 74 passed, 0 failed |
| `local-http-permission-proof.txt` | A **real loopback HTTP** write with a WordPress Application Password: 200 authorised, 401 anonymous, value persisted, then cleared; the temporary post and the temporary Application Password are removed |
| `pre-existing-failures-ab.md` | Every failing suite re-run with the fix removed → **identical counts**; the control suite flips 74/0 → 45/29, proving the experiment was live; the local Polylang/data root cause |
| `full-test-run.txt` | The complete `./scripts/run-tests.sh` output (all three layers, before the release rebuild) |
| `scripts-layer-after-release-build.txt` | `./scripts/run-tests.sh --scripts` after the rebuild: **20/20**, including the release-consistency gate |
| `lint.txt` | `./scripts/lint.sh`: syntax OK, PHPStan OK, PHPCS baseline gate red |
| `phpcs-baseline-drift-proof.txt` | The same PHPCS gate, same counts, on a pristine `git archive HEAD` — the drift is pre-existing |
| `release-workflow.txt` | `./scripts/verify-release.sh`: stages 1–6 pass (incl. byte-for-byte determinism and the exclusion proof), stage 7 fails on the pre-existing `/empregos/` row |
| `release-record-summary.txt` | `dist/release.json`: tag `v2026.11.05`, SHA `dc79f26d1739`, branch `leisure-location`, `dirty: true`, 10/10 artifacts |

## 15. Documentation updated

| Document | What changed | Owner rule |
|---|---|---|
| `docs/content-model.md` | Coordinate meta table: the REST column now says "readable **and writable** … for a user who can edit the leisure record"; new paragraph on the `auth_callback` rule, the `__return_false` default, and what the fix does not widen | the content model is the single source for meta keys and their REST surface |
| `docs/releases.md` | New `G19` version-bump record (artifact, old → new, what ships, base commit, verification, production actions = none, content dependency) | §11 version-bump record |
| `docs/plugins/conexao-data-model.md`, `docs/plugins/README.md`, `docs/architecture.md`, `docs/project-inventory.md` | Regenerated by `scripts/generate-registry-docs.php --write` — the version moved to 1.6.3 | generated regions, never hand-edited; `--check` proves 0 drift |
| `docs/README.md`, `docs/reports/README.md` | Index entry for this report | §9 documentation index |
| `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md` | This report | §9.2 |

No procedure moved between a document and a skill, so §15's "what moved" table is empty.

## 16. Rollback / recovery

| Step | Action |
|---|---|
| 1 | **Code:** re-upload the previous `conexao-data-model` 1.6.2 ZIP (or reactivate the previously installed 1.6.2) in wp-admin → Plugins → Add New → Upload Plugin, replacing 1.6.3. That restores the previous behaviour: REST writes to the two coordinate keys return `403 rest_cannot_update` again. |
| 2 | **Verify the rollback** with the same read-only gate: `python3 scripts/verify-deploy.py --site https://<host>`. An unverified rollback is an unverified state. |
| 3 | **Record** the rollback in `docs/releases.md` (what, to which SHA, verification result). |
| 4 | **Local:** `git diff` shows the whole change; reverting means restoring the two `HEAD` versions of `class-meta.php` and `conexao-data-model.php` (or deleting the untracked `tests/` directory). Nothing else under `wp-content/` is touched. |

What rollback does **not** cover: **content**. Once the coordinate migration runs, rolling the
plugin back does not remove the coordinates already written — that is the migration's own
journal-based reversal (`docs/plans/2026-11-05-leisure-location-production-migration.md` §9).
Rolling the plugin back also makes future REST coordinate writes fail again, so a rollback
must not be combined with a migration run.


## 17. Final status

**Final status: `PASS WITH LIMITATION`.**

Stages 0–5 are complete and verified with real numbers, but two required verifiers could not
reach green in this environment for reasons **proven to be outside this change**: the PHPCS
legacy-baseline gate (pre-existing drift, 156 → 214 scanned files) and 15 failing test suites
(12 in-process + 3 HTTP acceptance) caused by local content state (`/empregos/` 302 because the
local database has no PT `empregos` page, plus the associated Polylang term/page drift). Every
one of them reproduces identically with the fix removed. Production behaviour is **unverified by
design** — nothing was deployed, and Stages 6–10 require the authorisations below.

### Authorisation checkpoints (nothing below has been executed)

| Stage | Command / action | Status |
|---|---|---|
| **6 — Deploy** | Upload `dist/conexao-data-model.zip` (1.6.3) via wp-admin → Plugins → Add New → Upload Plugin → **Replace current version**, then confirm the plugin stays active. Only this one artifact changes; the other nine artifacts in `dist/` are unchanged in content. | **awaiting explicit authorisation** |
| **7 — Controlled write test** | After deploying: read-only confirmation that the deployed version is 1.6.3, then one documented reversible `POST /wp-json/wp/v2/leisure/<id>` (write a value, read it back, restore, re-read) using an Application Password. | **awaiting explicit authorisation** |
| **8 — Dry run** | `WP_USERNAME=… WP_APPLICATION_PASSWORD=… python3 scripts/migrate-leisure-location-rest.py --target-url https://conexaobr.ie --dry-run --include-attributes` (read-only; expect 289 coordinate rows planned, 0 writes) | **not run** |
| **9 — Apply** | `… --apply --confirm-production --include-attributes`, only after the fresh dry run is reviewed and separately authorised | **not run** |
| **10 — Post-migration verification** | Independent read-only verification of 289 coordinate pairs and 592 attribute assignments, plus `/wp-json/conexao/v1/leisure-coordinates` | **not run** |

_Last verified: 2026-11-05 by the Lazer coordinate REST permission fix (Stages 1–5; no production action)_

