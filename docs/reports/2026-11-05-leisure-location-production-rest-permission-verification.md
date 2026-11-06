# Report — Stage 7: production REST permission verification (Lazer coordinates)

| | |
|---|---|
| **Stage / task name** | Stage 7 — verify the deployed `conexao-data-model` 1.6.3, verify the production leisure REST endpoint, and execute the authorised controlled, reversible REST meta write (7C) |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `25749f7af8b63fe3ab12204964aa73a2b0db9d94` (`Add REST permission tests for leisure coordinate meta keys`) |
| **Final SHA** | uncommitted — committing and pushing are out of scope for this stage |
| **Working tree at finish** | dirty by design: this stage's evidence directory (`docs/evidence/2026-11-05-leisure-location-stage-7/`, 15 files), this report and its two index entries. Nothing else. |
| **Production writes** | **2** — one authorized test write and one restore, on `leisure` 10551, keys `_leisure_lat` / `_leisure_lng` |
| **Skills followed** | `wp-production-operations`, `wp-release-deploy` (verification only), `wp-security-review`, `wp-testing`, `wp-update-docs`, `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §2.1, §4.2, §5.2, §7, §9), `docs/deployment.md`, `docs/releases.md` (G19), `docs/testing.md`, `docs/content-model.md` §"Leisure coordinates", `docs/plans/2026-11-05-leisure-location-production-migration.md`, `docs/reports/2026-11-05-leisure-location-production-audit.md`, `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md`, `plugins.json`, `docs/templates/report.md`, `docs/evidence/README.md` |

## 0. Final verdict

**`PASS — controlled REST permission test succeeded and original metadata restored`** (Stage 7C, 2026-11-05T15:45:55Z–15:46:00Z).

Stage 7A, 7B and 7C are now complete. The deployed plugin was measured as
**`conexao-data-model` 1.6.3, `active`**; the authorized two-key write on
`leisure` **10551** returned **200** (not `403 rest_cannot_update`), persisted on an
independent authenticated re-read, and the original `""`/`""` was restored and then
verified three ways — authenticated REST, anonymous REST, and the public
`/wp-json/conexao/v1/leisure-coordinates?lang=pt` dataset (item `lat:null, lng:null`).

The only production writes in the whole stage were the two this task authorized:
one test write and one restore, on one record, on two meta keys. A full
289-record sweep after the test shows **exactly one** record with a modified stamp
from that day — the target.

Two side effects are permanent and were accepted in advance: `post_modified` moved
`2026-08-21T21:48:44` → `2026-11-05T15:46:00`, and the REST post update created a
revision. Neither is revertible over REST and neither was attempted to be reverted.

Three things are disclosed rather than smoothed over:

1. **The documented plugin URL does not work as documented.** A literal
   `GET /wp-json/wp/v2/plugins/conexao-data-model` answers **404
   `rest_plugin_not_found`** because the core route's plugin segment excludes `.`
   while this plugin's REST identity is `conexao-data-model/conexao-data-model`.
   The version was therefore verified through the plugin **collection** (200) and
   the **correct single-item identity** (200). Same fact, different address.
2. **The documented credential file did not exist.** `.local/production-rest.env` is
   absent, so the credentials came from the repository-root **git-ignored** `.env`
   (which itself carries `CONEXAO_SITE_URL=https://conexaobr.ie` and an
   Application-Password-shaped value). They authenticated as an administrator
   (`conexaobradmin`, id 283039558). `.env` was not modified; no value was printed
   or recorded. See `12-stage7c-deployment-and-credential-preflight.txt` §1.
3. **One `--apply` attempt was killed by the harness' 30 s wall clock before any
   request was sent**; production was re-read to prove `modified` and both values
   were untouched, then the cycle was re-run unbuffered. **0** writes from that
   attempt — the stage total is 2, not 3 or 4.

**Stage 8 has NOT been started.** The authorization covered Stage 7C only.

## 1. Scope completed

| Step | Status | Result |
|---|---|---|
| **7A** — read-only deployment verification | **partial (documented limitation)** | Plugin version **not confirmable anonymously** (see §0/§13); the artifact that was uploaded is provably built at 1.6.3 (`dist/release.json`: `version 1.6.3`, 6 files, 18,340 bytes, `sha256 c1a83440…63052`). |
| **7A** — production leisure endpoint | **done** | `GET /wp-json/conexao/v1/leisure-coordinates?lang=pt` → **200**, `count = 289`, `items = 289`, **0** with a coordinate, **289** with `lat:null, lng:null`. The endpoint is not paginated (the whole filtered set is returned in one response); the inventory sweep on the paginated post collection honoured `X-WP-Total=289` / `X-WP-TotalPages=3`. |
| **7A** — record inventory | **done** | 289 published `leisure` records, 289 distinct ids, 289 distinct slugs, `_leisure_lat`/`_leisure_lng` = `""` on **all 289**. `modified` range `2026-08-21T21:48:43` → `2026-09-15T19:38:06`. |
| **7B** — target record selection | **done** | `russborough-house`, ID **10551**, `publish`, page 200, `_leisure_feature: false`, oldest `modified` batch. Criteria and screening table in `05-target-record-selection.txt`. |
| **7B** — reversible test prepared | **done** | `stage7-rest-permission-test.py` (read-only unless `--apply`; reuses `scripts/lib/rest.py` for auth, URLs and the production write gate). Exact write and restore commands in `07-authorization-checkpoint.txt` §4. |
| **7B** — rehearsal (local, not production) | **done** | Positive: local fixture 22734 → write 200, persisted, restored, verified auth + anonymous + public dataset → `exit 0`. Negative: local record 10551 (real coordinates present) → **REFUSED, exit 4, zero writes**. |
| **7C** — controlled production write | **done — PASS** | One `POST` of `{"meta":{"_leisure_lat":"1.2345","_leisure_lng":"-2.3456"}}` to `/wp-json/wp/v2/leisure/10551` → **200**, echoed and persisted. Immediately followed by one `POST` of `{"meta":{"_leisure_lat":"","_leisure_lng":""}}` → **200**. 2 writes total, one record, two meta keys (`13-stage7c-run-log.txt`, `11-stage7c-production-test.json`). |
| **7D** — post-test independent verification | **done** | Authenticated read, anonymous read and the public dataset all report `""`/`""` and `lat:null`; a 289-record sweep finds **exactly one** record modified that day — the target (`14-stage7c-independent-verification.txt`). |
| Stage 8 dry run / Stage 9 apply | **NOT PERFORMED** | Out of scope for this stage; explicitly prohibited. The authorization covered Stage 7C only. |

## 2. Scope NOT completed (and why)

| Not done | Why |
|---|---|
| **Stage 8 dry run, Stage 9 apply** | Not authorized. The user's authorization explicitly excluded the migration; nothing resembling it was run. |
| **Coordinate population on any other record** | Not authorized — only record 10551, only for the test window. |
| **Attribute-term migration** | Not authorized and not attempted. |
| **Any deploy, upload or activation** | Prohibited and unnecessary — 1.6.3 was already installed and active. |
| **Reverting `post_modified` or the new revision** | Impossible over REST (`modified` is read-only, revisions are not deletable) and deliberately accepted in advance by the user. Doing it another way would require the database access this repository is forbidden to use. |


## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/evidence/2026-11-05-leisure-location-stage-7/01-governance-and-git-preflight.txt` | added | Branch/HEAD/worktree/stash state, documents read, destructive commands not run |
| `docs/evidence/2026-11-05-leisure-location-stage-7/02-plugin-version-verification.txt` | added | Every anonymous version probe and its result |
| `docs/evidence/2026-11-05-leisure-location-stage-7/03-production-leisure-endpoint.txt` | added | Endpoint status, counts, first/last items, target item |
| `docs/evidence/2026-11-05-leisure-location-stage-7/04-production-leisure-inventory-slim.json` | added | The 289-record inventory (id, slug, status, dates, lat, lng) |
| `docs/evidence/2026-11-05-leisure-location-stage-7/05-target-record-selection.txt` | added | Criteria, screening table, the selection, schema facts, disclosed side effects |
| `docs/evidence/2026-11-05-leisure-location-stage-7/06-target-record-pre-test.json` | added | Full pre-test record snapshot (37 meta keys) |
| `docs/evidence/2026-11-05-leisure-location-stage-7/07-authorization-checkpoint.txt` | added | The approval package: target, values, endpoint, auth, restore, impact, exact commands |
| `docs/evidence/2026-11-05-leisure-location-stage-7/08-local-rehearsal.txt` | added | Local end-to-end rehearsal (positive, `exit 0`) |
| `docs/evidence/2026-11-05-leisure-location-stage-7/09-local-refusal-proof.txt` | added | Local negative proof (`REFUSED, exit 4`, zero writes) |
| `docs/evidence/2026-11-05-leisure-location-stage-7/10-post-stage-production-state.txt` | added | Post-stage read-only re-verification that production is unchanged |
| `docs/evidence/2026-11-05-leisure-location-stage-7/stage7-rest-permission-test.py` | added | The operator script for 7C (read-only by default) |
| `docs/evidence/2026-11-05-leisure-location-stage-7/11-stage7c-production-test.json` | added | The script's own machine-readable 7C record: 7 steps, 9 assertions all `ok`, side effects, `result: PASS` |
| `docs/evidence/2026-11-05-leisure-location-stage-7/12-stage7c-deployment-and-credential-preflight.txt` | added | Credential provenance (names only), authenticated identity, the 404-vs-200 plugin-identity finding, 1.6.3 verification, pre-write target revalidation |
| `docs/evidence/2026-11-05-leisure-location-stage-7/13-stage7c-run-log.txt` | added | Verbatim execution log of the authorized write cycle |
| `docs/evidence/2026-11-05-leisure-location-stage-7/14-stage7c-independent-verification.txt` | added | Independent post-test verification: auth + anon reads, dataset, 289-record scope sweep, accepted side effects |
| `docs/reports/2026-11-05-leisure-location-production-rest-permission-verification.md` | added | This report |
| `docs/README.md`, `docs/reports/README.md` | modified | Index entry + `_Last verified_` line |

**16 added, 2 modified, 0 deleted.** No file under `wp-content/` changed. No script
under `scripts/` changed. The operator script lives in the evidence directory
because it is a one-run operator instrument for this stage, not a repository
capability with its own contract.

## 4. Runtime impact

**None in this repository.** No application code, template, asset, option or
database row here changed; `git status` shows only documentation and evidence. The
local Docker stack was exercised earlier (a rehearsal write to a disposable CI
fixture, restored), which is a local-only consequence disclosed in §8.

**On production,** exactly one record was written and restored: `leisure` 10551,
keys `_leisure_lat` / `_leisure_lng`, for a five-second window. Its `post_modified`
moved and one revision exists (accepted in advance; see `14-stage7c-independent-verification.txt` §5).

## 4a. Scope integrity

| Check | Result |
|---|---|
| Production writes | **2 total** — one test write, one restore; one record; two meta keys. No other write of any kind |
| Production posts/terms/users/options created or deleted | **0** |
| Production deploy, upload or activation | **0** |
| Other production records modified | **0** — proven by a 289-record sweep: exactly one record carries that day's `modified` stamp |
| No application behaviour change (`wp-content/` diff empty) | **confirmed** |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** — `.env` was read (git-ignored, never modified); no value appears in any file here |
| Migration run (Stage 8 dry run / Stage 9 apply) | **no** |
| SQL / WP-CLI / direct database / filesystem access | **none used** |
| Commit or push | **no** — `HEAD` unchanged at `25749f7`, 9 stash entries untouched |

## 5. Content / data impact

| Environment | Records read | Records written | Fields written |
|---|---|---|---|
| **Production** | 289 `leisure` records (inventory + post-test sweep) + proximity dataset reads + single-record reads | **1** (`10551`, twice) | `_leisure_lat`, `_leisure_lng` — test values written, then restored to `""`; verified three ways |
| **Local Docker** (rehearsal only) | 294 | 1 CI fixture (`22734`) | `_leisure_lat`, `_leisure_lng` — written, then restored to `""`; verified |

## 6. Polylang impact

None. No language, translation or term relation was touched on production. Locale was
read only through the endpoint's own `lang=pt` filter. `leisure` is a B2 type (English
is a field on the same record), so `Conexao_Admin_Ux_Translation_State::sync_on_save()`
iterates a translation map containing only the default-language id and writes no
`_conexao_translation_outdated` flag. PT identity (id, slug, date, status) was not sent
by the test and did not change — confirmed by the post-test reads.

## 7. Route / HTTP impact

| Route | Result |
|---|---|
| `GET /wp-json/conexao/v1/leisure-coordinates?lang=pt` | **200**, `count 289`, `items 289`, `0` with coordinates — identical before and after the test |
| `GET /wp-json/wp/v2/leisure?per_page=100&page=1..3` | **200**, `X-WP-Total: 289`, `X-WP-TotalPages: 3` (289 distinct ids in the post-test sweep) |
| `GET /wp-json/wp/v2/leisure/10551` | **200**, `_leisure_lat: ""`, `_leisure_lng: ""`, `status: publish` |
| `POST /wp-json/wp/v2/leisure/10551` | **200** twice (test values, then originals) — the permission fact this stage exists to prove |
| `GET /lazer/russborough-house/` | **200** (85,418 bytes) |
| `GET /wp-json/wp/v2/plugins` | **401** `rest_cannot_view_plugins` (anonymous) / **200** authenticated (the version inventory) |
| `GET /wp-json/wp/v2/plugins/conexao-data-model/conexao-data-model` | **200**, `version: 1.6.3`, `status: active` |
| `GET /wp-json/wp/v2/plugins/conexao-data-model` | **404** `rest_plugin_not_found` — a route-identity artefact, not a missing plugin (§0; evidence `12-…` §3a) |
| `GET /wp-content/plugins/conexao-data-model/` | **403** (no listing, no version leakage) |
| `GET /wp-content/plugins/conexao-data-model/conexao-data-model.php` | **200** with a **0-byte** body (PHP executed, source not served) |

No redirect, canonical, hreflang or sitemap behaviour changed — no content changed.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **yes — 2, as authorized** | One `POST` of the temporary coordinates and one `POST` of the originals, both to `/wp-json/wp/v2/leisure/10551`, both **200**. No `PUT`/`PATCH`/`DELETE` anywhere |
| Deploy / upload | **no** | 1.6.3 was already installed and active |
| Plugin activation | **no** | — |
| Content or DB mutation beyond the two keys | **no** | A 289-record sweep confirms no other record changed |
| Read-only probes | **yes** | `GET` only, with courtesy spacing; `429` honoured with `Retry-After` by the shared client |
| Migration | **no** | Not authorized; not run |

**Local environment repair, disclosed.** To rehearse 7C against a real WordPress,
the local Docker stack's test account was recreated with
`scripts/ensure-local-test-user.php --apply` (local-write, documented post-restore
step) because the `.env` application password still carries the known space defect
from the audit (§4.1 of `2026-11-05-leisure-location-production-audit.md`) and every
local Basic-auth call returned 401. Consequences are local-only: one local user
created (`created=1`, `app_password_added=1`), and one local CI fixture written and
restored during the rehearsal. **`.env` was not modified; no production credential
was created, requested or used.**

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `curl -sS -m 45 https://conexaobr.ie/wp-json/` | 0 | 200, 24 namespaces, `conexao/v1` present |
| `curl -sS … /wp-json/wp/v2/plugins` (anonymous) | 0 | 401 `rest_cannot_view_plugins` |
| `curl -sS … /wp-content/plugins/conexao-data-model/conexao-data-model.php` | 0 | 200, 0 bytes |
| `curl -sS … /wp-json/conexao/v1/leisure-coordinates?lang=pt` | 0 | 200, 289 items, 0 with coordinates |
| `python3` inventory sweep (`per_page=100`, 3 pages) | 0 | 289 records, 289 unique slugs, lat/lng `""` on all |
| `python3 …/stage7-rest-permission-test.py --plan` | 0 | Plan printed; no request that changes anything |
| `python3 -m py_compile …/stage7-rest-permission-test.py` | 0 | Script compiles |
| Local rehearsal (`--apply`, localhost, fixture 22734) | **0** | Write 200 → persisted → restore 200 → verified auth + anon + dataset |
| Local refusal rehearsal (localhost, record 10551) | **4** | REFUSED before any write; coordinates unchanged |
| `python3 …/stage7-rest-permission-test.py --apply --confirm-production` (production) | **0** | 7/7 steps 200; test write accepted and persisted, originals restored, auth + anon + dataset re-verified → `RESULT: PASS` (`13-stage7c-run-log.txt`) |
| `python3` plugin inventory (authenticated) | 0 | `conexao-data-model/conexao-data-model` **1.6.3**, `active` |
| `python3` independent post-test verification | 0 | auth `""`/`""`, anon `""`/`""`, dataset item `null`/`null`, 289 records swept, **1** modified that day (`14-stage7c-independent-verification.txt`) |

### Test suites / lint / gates

Not re-run in this stage: **no application code, test, script or build input
changed** (`git status` shows documentation and evidence only). Re-running
`./scripts/run-tests.sh` would re-establish the same pre-existing local-condition
failures recorded in `2026-11-05-leisure-coordinate-rest-permission-fix.md` §12 and
would not exercise anything this stage changed. Stated explicitly rather than
implied: **the full repository test suite (`./scripts/run-tests.sh`) was not executed
for this stage.**

The two permanent gates whose subject matter this stage actually touched **were**
run after the documentation update, and both pass:

| Gate | Command | Result |
|---|---|---|
| Documentation drift | `python3 tests/scripts/verify-documentation-drift.py` | **exit 0** — `14 passed, 0 failed`, `violations 0` (registry, script conventions, agent governance, `_Last verified_` markers, no root-level report/evidence) |
| Hardened secret scan | `python3 tests/scripts/verify-stage14-secret-scan.py` | **exit 0** — `336 passed, 0 failed`; 2,655 files scanned, **0** undeclared credential-shaped values; 17 declared fixtures all confined to `tests/` |

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| **Precondition guard refuses** — the exact 7C script run against a record whose coordinates are not the pinned original (local record 10551, which holds `53.141559 / -6.569674`) | `REFUSED: preconditions not met; nothing was written`, **exit 4**, coordinates unchanged on re-read (`09-local-refusal-proof.txt`) |
| **Write path end-to-end on a real WordPress** — same script, local fixture with empty coordinates | write 200 → persisted → restore 200 → authenticated + anonymous re-reads `""`/`""` → dataset `null` → **exit 0** (`08-local-rehearsal.txt`) |
| **Version-probe negative** — anonymous plugin inventory | `401 rest_cannot_view_plugins`: the endpoint cannot be used to claim a version either way |
| **Source-not-served** — direct request for the plugin PHP file | 200 with 0 bytes: no version can be scraped from the file |
| **Router-identity negative** — `GET /wp-json/wp/v2/plugins/conexao-data-model` (authenticated) | `404 rest_plugin_not_found`: the documented single-segment URL can never match this plugin's two-segment REST identity. The collection read is what proves the version |
| **Interrupted attempt proves no partial write** — the first `--apply` was killed at the harness' 30 s limit | target re-read immediately: `modified` still `2026-08-21T21:48:44`, both values still `""` → **0** writes from that attempt (`12-…` §7) |

One development failure is recorded honestly: the first draft of the operator script
built `/wp/v2/...` without the `/wp-json` prefix. The **local** rehearsal caught it
immediately (a `JSONDecodeError` on an HTML 404) and it was fixed before any
production call. That is exactly why the rehearsal ran first.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Production records with a coordinate | 0 / 289 | **0 / 289** (the temporary pair existed for 5 s and was restored) |
| Production `leisure` records | 289 published | 289 published |
| Public dataset item count | 289 | 289 |
| `_leisure_lat` / `_leisure_lng` on record 10551 | `""` / `""` | **`""` / `""`** (verified authenticated, anonymously and through the dataset) |
| Records whose `modified` stamp is the test date | 0 | **1** — record 10551 only (accepted side effect) |
| Repository application files changed | — | **none** |

## 12. Known pre-existing failures

Carried forward from `2026-11-05-leisure-coordinate-rest-permission-fix.md` §12 and
not re-run here: `./scripts/lint.sh` is red at `HEAD` on a **pre-existing** PHPCS
baseline drift (proven identical on a pristine `git archive HEAD`), and the local
content state (`/empregos/` 302 plus Polylang term/page drift) fails 15 suites.
Neither is touched or caused by this stage, and neither is a production signal.

## 13. Limitations

1. **Row-level meta absence is unobservable.** Production offers no database access,
   so whether `postmeta` rows exist for the two keys before/after the restore cannot
   be proven; only the REST-visible value can be, and it is restored exactly (`""`).
   An empty-string row may therefore remain, which is indistinguishable to every
   consumer (`get_coordinates()` → `null`, `coordinate_status()` → `missing`,
   dataset → `null`). Disclosed, not "cleaned up" — cleaning it would need exactly
   the database access this repository forbids.
2. **`post_modified` and one revision cannot be reverted.** Measured, not assumed:
   `2026-08-21T21:48:44` → `2026-11-05T15:46:00`. Accepted in advance by the user;
   no attempt was made to reverse it.
3. **"No other record was modified" is proven by `modified` stamps, not by a full
   content digest.** All 289 records were re-read and only the target carries the
   test date's stamp, and the coordinate dataset is back to `0 / 289`. A change that
   did not move `modified` would be invisible to this sweep; no such mechanism was
   used (2 `POST`s, both to the same path, both recorded).
4. **The plugin version was verified through the collection and the two-segment
   identity, not through the URL written in the task.** The 404 on the single-segment
   form is a route-regex artefact, documented verbatim so a future reader does not
   mistake it for a missing deployment.
5. **Credentials came from the repository-root `.env`, not the documented
   `.local/production-rest.env`** (which does not exist). Provenance is recorded in
   `12-…` §1; no value was printed, logged or written into evidence.
6. **The local rehearsal is not the production environment.** It proved the script
   and the plugin's behaviour; production then confirmed the deployed version.
7. **The anonymous REST surface is CDN-cached.** A late anonymous read without a
   cache-buster returned the *pre-test* `modified` stamp (`2026-08-21T21:48:44`) from
   the WordPress.com Atomic CDN (`x-ac: … MISS`), while a cache-busted read and the
   authenticated `context=edit` read both report `2026-11-05T15:46:00`. The metadata
   values were identical in all three; only the timestamp was stale. Anyone verifying
   this dataset must cache-bust anonymous reads or use `context=edit`
   (`14-stage7c-independent-verification.txt` §7).
8. **The repository test suite was not executed** — nothing it covers changed. See §9.


## 14. Evidence paths

`docs/evidence/2026-11-05-leisure-location-stage-7/`:

| File | Proves |
|---|---|
| `01-governance-and-git-preflight.txt` | Clean worktree, branch/SHA, untouched stashes, destructive commands not run, documents read |
| `02-plugin-version-verification.txt` | Every anonymous version probe; that no version is obtainable without credentials; the 1.6.3 build provenance |
| `03-production-leisure-endpoint.txt` | Endpoint 200 + 289 items + 0 coordinates; the target item at index 228 |
| `04-production-leisure-inventory-slim.json` | The 289-record inventory used for selection |
| `05-target-record-selection.txt` | Criteria, screening, the chosen record, schema facts, disclosed side effects |
| `06-target-record-pre-test.json` | Full pre-test record snapshot (37 meta keys) |
| `07-authorization-checkpoint.txt` | The approval package and the exact commands |
| `08-local-rehearsal.txt`, `09-local-refusal-proof.txt` | The script works, and its guard fails closed |
| `10-post-stage-production-state.txt` | Record 10551 and the dataset were unchanged after the read-only stages (0 writes) |
| `11-stage7c-production-test.json` | The 7C machine record: 7 steps, 9/9 assertions `ok`, side effects, `result: "PASS"` |
| `12-stage7c-deployment-and-credential-preflight.txt` | Credential provenance and identity, the 1.6.3 measurement, the 404 route-identity finding, the pre-write revalidation, the interrupted-attempt proof |
| `13-stage7c-run-log.txt` | Verbatim run log of the authorized write cycle |
| `14-stage7c-independent-verification.txt` | Independent post-test verification plus the 289-record scope sweep and the accepted side effects |
| `stage7-rest-permission-test.py` | The operator script itself (read-only by default) |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-11-05-leisure-location-production-rest-permission-verification.md` | This report |
| `docs/reports/README.md` | Index row for this report + `_Last verified_` line |
| `docs/README.md` | Index row for this report + `_Last verified_` line |

No evergreen document changed, because nothing about product behaviour, routes, the
content model or the release contract changed in this stage.

## 16. Rollback / recovery

| Situation | Action |
|---|---|
| A documentation change needs undoing | Revert the three documentation paths; no runtime effect. |
| A rehearsal artefact on the local stack needs undoing | The rehearsal already restored `""`/`""` on local fixture 22734 (verified). Its `post_modified`/revision are local-only and irrelevant. |
| **If 7C is later authorised and the restore fails** | Treat as a production incident: the script exits 3 and records the exact state. Recovery is a second authenticated `POST` of `{"meta":{"_leisure_lat":"","_leisure_lng":""}}` to `/wp-json/wp/v2/leisure/10551`, or clearing the two fields in the wp-admin Lazer editor, then re-reading over REST to prove `""`/`""`. Do **not** run the migration until that is verified. |
| **7C as executed — nothing to recover** | The restore succeeded (200) and the original values were independently verified authenticated, anonymously and through the public dataset. The only residuals are the two accepted side effects (`post_modified`, one revision), which are explicitly **not** to be reversed by any out-of-band means. |
| A later legitimate rollback of the *migration* (Stage 9) | Out of scope here. It is the plan's own journal-based reversal (`docs/plans/2026-11-05-leisure-location-production-migration.md` §9), and it has not been authorised or started. |

## 17. Final status

**Final status: `PASS`.**

Stage 7 is complete. 7A verified the deployed deployment surface and the production
dataset; 7B selected and prepared the target and rehearsed the reversible test; 7C
executed the authorized one-record, two-key write, restored the original values, and
7D verified the restoration independently. The deployed plugin measures as
`conexao-data-model` **1.6.3, active**, and the write it exists to permit returned
**200** rather than `403 rest_cannot_update` — the G19 `auth_callback` fix is live in
production.

### Verdict against the task's required choices

**`PASS — controlled REST permission test succeeded and original metadata restored`.**

* Write accepted: `200`, coordinates echoed and persisted.
* Restoration verified: authenticated `""`/`""`, anonymous `""`/`""`, dataset
  `lat:null, lng:null`.
* 2 production writes total, one record, two meta keys; **0** other records modified
  (289-record sweep).
* Migration **not run**; no commit, no push; `HEAD` unchanged.

Not `PASS WITH LIMITATIONS`: every activation condition of the task's PASS verdict was
met and independently measured. The limitations in §13 are disclosures about what REST
cannot observe, not failures of a required check.

### Readiness for Stage 8

**The 7C dependency on Stage 8 is now satisfied: write permission to the two coordinate
keys is proven on production.** What 7C does *not* authorize is Stage 8 itself. The
dry run (Stage 8) and the apply (Stage 9) remain unstarted and require their own
authorization; this task's authorization explicitly excluded the migration. Nothing
about Stage 8 was run, planned, or partially executed here.

## 18. Stage 7C execution record (the task's required report skeleton)

### Deployment

| Item | Value |
|---|---|
| Plugin verified | `conexao-data-model` **1.6.3**, `status: active`, `network_only: false` |
| Verification endpoint (as documented) | `GET /wp-json/wp/v2/plugins/conexao-data-model` → **404** `rest_plugin_not_found` (route identity cannot match; see §0 item 1 and `12-…` §3a) |
| Verification endpoint (that works) | `GET /wp-json/wp/v2/plugins` → **200** (collection entry) and `GET /wp-json/wp/v2/plugins/conexao-data-model/conexao-data-model` → **200** |
| Authenticated identity | administrator `conexaobradmin`, id `283039558`, `manage_options: true` |
| Leisure endpoint | `GET /wp-json/conexao/v1/leisure-coordinates?lang=pt` → **200**, `count 289`, `items 289`, `0` with a coordinate |

### Test

| Item | Value |
|---|---|
| Target | post type `leisure`, **ID 10551**, slug `russborough-house`, status `publish` |
| Original values (measured before the write) | `_leisure_lat = ""`, `_leisure_lng = ""` |
| Temporary values | `_leisure_lat = "1.2345"`, `_leisure_lng = "-2.3456"` |
| Write | `POST /wp-json/wp/v2/leisure/10551` → **200**; response echoed `1.2345` / `-2.3456` |
| Read-after-write | `GET …?context=edit` → **200**, persisted `1.2345` / `-2.3456`, `modified 2026-11-05T15:45:55` |
| Restoration | `POST /wp-json/wp/v2/leisure/10551` with `""`/`""` → **200**, echoed `""`/`""` |
| Authenticated restoration verification | `GET …?context=edit` → **200**, `""`/`""`, `status: publish`, `modified 2026-11-05T15:46:00` |
| Anonymous restoration verification | `GET /wp-json/wp/v2/leisure/10551` → **200**, `""`/`""` (anonymous reads are CDN-cached on WordPress.com — a cache-busted read confirmed the same values) |
| Revision side effect | **exactly one** revision: `{"id": 54275, "modified": "2026-11-05T15:45:55"}` (read-only check) |
| Leisure endpoint restoration verification | **200**, item `{"id":10551,"lat":null,"lng":null,"index":228}`, dataset `with_coordinate = 0` |

### Safety

| Item | Value |
|---|---|
| Production write requests | **2** (one test write, one restore). A killed first attempt sent **0**. |
| Record and fields modified | post **10551** only, meta `_leisure_lat` + `_leisure_lng` only |
| Restoration verified | yes — authenticated, anonymous and via the public dataset |
| Other records modified | **none** — 289 records re-read; exactly **1** carries that day's `modified` stamp (the target) |
| Migration run | **no** — no Stage 8 dry run, no Stage 9 apply, no coordinate/attribute population |
| Commit or push | **no** — `HEAD` still `25749f7af8b63fe3ab12204964aa73a2b0db9d94`; 9 stash entries untouched |
| Credentials | repository-root git-ignored `.env`, loaded into the process environment, never printed or persisted; `.env` unmodified; no new credential created |
| Out-of-band access (SQL / WP-CLI / filesystem) | **none** |

### Verdict

**`PASS — controlled REST permission test succeeded and original metadata restored`**

_Last verified: 2026-11-05 by Stage 7C — controlled production REST permission test (deployed `conexao-data-model` 1.6.3 measured; write accepted `200`; originals restored and independently verified; 2 production writes, 1 record, 2 meta keys; migration not run)_



