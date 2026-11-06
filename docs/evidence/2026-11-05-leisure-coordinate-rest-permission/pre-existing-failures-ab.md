# Proof — every failing suite fails **identically without** this change

`./scripts/run-tests.sh` reports failures in 12 in-process suites and 3 HTTP acceptance
suites. **None of them is caused by this change.** This file is the evidence: each
failing suite was re-run with the fix **removed** and produced **exactly the same
counts**.

## Method

1. Backed up the two modified files outside the repository
   (`/tmp/lcperm-backup/`), recording their SHA-256.
2. Replaced them with the **`HEAD` (`dc79f26`) content** using
   `git show HEAD:<path> > <path>` — no `git checkout`, no `git reset`, no stash.
3. Ran every failing suite (and the new permission suite as a **control**).
4. Restored the files from the backup and verified the SHA-256 matched the backup
   byte-for-byte:
   - `class-meta.php` → `6293849372d76c4f4f5973625c5bed01f226923fba78c8c4121f6aeddc47ad0c`
   - `conexao-data-model.php` → `8c8c1073121032eb8de6883c66a14c58f49d08f3bda8ddb3b155ae8a8409e266`

## In-process suites

| Suite | With the fix | Without the fix | Verdict |
|---|---|---|---|
| `plugin/conexao-event-importer/test-town-sanitization.php` | 82 passed, **1 failed** | 82 passed, **1 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-blog-en-translation.php` | 25 passed, **3 failed** | 25 passed, **3 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` | 35 passed, **4 failed** | 35 passed, **4 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-event-filter-cache.php` | 17 passed, **5 failed** | 17 passed, **5 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-guide-en-translation.php` | 667 passed, **7 failed** | 667 passed, **7 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-job-en-translation.php` | 28 passed, **4 failed** | 28 passed, **4 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-jobs-en-language.php` | 98 passed, **6 failed** | 98 passed, **6 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-seasonal-halloween-nav-effects.php` | 102 passed, **5 failed** | 102 passed, **5 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-stage33-bilingual.php` | 196 passed, **2 failed** | 196 passed, **2 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-stage41-rest-language.php` | 220 passed, **2 failed** | 220 passed, **2 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-stage45-pages.php` | 364 passed, **2 failed** | 364 passed, **2 failed** | pre-existing |
| `theme/conexao-br-irlanda/test-translation-completeness.php` | 21 passed, **2 failed** | 21 passed, **2 failed** | pre-existing |
| **CONTROL** — `plugin/conexao-data-model/test-leisure-coordinate-rest-permissions.php` | **74 passed, 0 failed** | **45 passed, 29 failed** | **this change** |

The control line is the point: the A/B run really did execute the unfixed code, so the
twelve identical results above are meaningful and not a stale-cache artefact.

## HTTP acceptance suites

| Suite | With the fix | Without the fix | Verdict |
|---|---|---|---|
| `tests/acceptance/verify-release-http.py` | 41 assertions passed, **1 matrix row failed** | 41 passed, **1 matrix row failed** | pre-existing |
| `tests/acceptance/verify-routing-http.py` | 120 passed, **6 matrix rows failed** | 120 passed, **6 matrix rows failed** | pre-existing |
| `tests/acceptance/verify-event-occurrence-http.py` | 21 passed, **2 failed** | 21 passed, **2 failed** | pre-existing |

## Script-contract suite (the one failure that WAS ours)

| Suite | Before the release build | After the release build |
|---|---|---|
| `tests/scripts/verify-stage8-release-consistency.py` | **4 failures** — `header=1.6.3 release.json=1.6.2`, `zip=1.6.2 source=1.6.3` | **47 passed, 0 failed** |

This is not a defect: the gate requires the built artifact to match the source version.
The version bump is intentional and the artifacts had not been rebuilt yet. After
`./scripts/build-plugins-zip.sh` + `./scripts/build-theme-zip.sh` the gate passes
(`scripts-layer-after-release-build.txt`: **20 suites, 20 passed, 0 failed**).

## Root cause of the pre-existing failures (local database state)

They are **Polylang / content-state** assertions, and the local database is not in the
bilingual state the suites expect. Direct evidence from the running local site:

```
$ curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' http://localhost:8080/empregos/
302 -> http://localhost:8080/en/empregos/     # every other archive returns 200

$ docker compose exec -T wordpress php (post/page probe)
22200  | empregos | publish | lang=en | http://localhost:8080/en/empregos/
115442 | empregos | publish | lang=en | http://localhost:8080/en/empregos/
# → TWO 'empregos' pages, BOTH English, and no Portuguese page at all.
```

That single data condition explains the `/empregos/` 302 (hence the release and routing
matrix rows) and the `test-en-jobs-shared-slug` failures (`pt_of_en: 11086` no longer
exists). The other suites fail on the same class of drift: unlinked `conexao_category`
EN terms (`linked=1 used=6`), EN blog posts whose PT dates are not copied, Eircode
contaminated `conexao_town` terms, and Polylang term lookups returning `null`.

None of these assertions reads `_leisure_lat` / `_leisure_lng`, and the change registers
only those two keys' permission callback — it cannot reach a Polylang page, term or
redirect. `test-leisure-location-services.php` (**102 passed**) and
`plugin/conexao-admin-ux/test-leisure-coordinates.php` (**29 passed**) are green in the
same run, which is the coverage that *does* touch this feature.

`verify-event-occurrence-http.py` was already failing in the committed evidence of
`2026-11-03` and `2026-11-04`
(`docs/evidence/2026-11-03-leisure-coordinate-research/full-test-run.txt`,
`docs/evidence/2026-11-04-leisure-distance-optin/full-test-run.txt`).

_Local WordPress at `http://localhost:8080` only. No production request was made._
