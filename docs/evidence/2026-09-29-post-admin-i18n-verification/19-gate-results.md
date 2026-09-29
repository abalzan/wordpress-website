# Repository gate results — post-admin verification

All commands run on the `i18n` branch at SHA `33114c6dec671df6d07f097a0bb570454af0a8cd`.
No assertion was weakened and no gate was converted to PASS.

## Canonical suite — `./scripts/run-tests.sh` (exit 1)

| Layer | Result |
|---|---|
| In-process PHP suites | **62 total, 52 passed, 10 failed** |
| Assertions | **2910 passed, 2 failed** |
| Script-contract suites | **6 total, 5 passed, 1 failed** |
| HTTP acceptance suites | **3 total, 3 passed, 0 failed** (142 assertions) |

**Identical to the PRE-admin baseline recorded in
`docs/evidence/2026-09-29-i18n-production-foundation/12-run-tests.log` and in
`docs/reports/2026-09-29-release-identity.md` (62/52/10, 2910/2).**

### Failing suites (all pre-existing)

| Suite | Cause |
|---|---|
| `plugin/conexao-job-translation/test-job-engine-migration.php` | retired plugin files absent |
| `plugin/conexao-translation-rollout/test-future-stage-smoke.php` | retired plugin files absent |
| `plugin/conexao-translation-rollout/test-rollout-failure-proofs.php` | retired plugin files absent |
| `plugin/conexao-translation-rollout/test-translation-rollout-engine.php` | retired plugin files absent |
| `theme/conexao-br-irlanda/test-course-provider-card-excerpt-language.php` | `conexao-page-translation/includes/translation-map.php` missing |
| `theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` | same |
| `theme/conexao-br-irlanda/test-guide-en-translation.php` | same |
| `theme/conexao-br-irlanda/test-jobs-en-language.php` | same |
| `theme/conexao-br-irlanda/test-leisure-card-excerpt-language.php` | same |
| `theme/conexao-br-irlanda/test-stage45-pages.php` | same |
| `tests/scripts/verify-i18n-freshness.py` (script-contract) | stale `.pot` catalogues |

**New failures introduced by this task: 0.** The failing list is identical to the
baseline list.

## `./scripts/lint.sh` — exit 0

`syntax clean, no new PHPCS violations, PHPStan clean` (206 files, no errors).

## `python3 scripts/verify-permanent-gates.py` — AGGREGATE FAIL

```
Gates: 7 total, 6 passed, 1 failed, 0 blocked
Assertions: 89 passed, 2 failed
Violations: 2 (1 pre-existing, 1 new)
```

| Gate | Result |
|---|---|
| `taxonomy_policy` | PASS (18/18) |
| `translation_completeness` | PASS (23/23, 289 allowlisted) |
| `cache_scoping` | PASS (9/9) |
| `cache_scoping_static` | PASS (2/2) |
| `redirect_precedence` | PASS (15/15) |
| `documentation_drift` | PASS (14/14) |
| **`i18n_freshness`** | **FAIL — 8 passed, 2 failed** |

### The one NEW violation — `i18n_freshness`, and it is repository-side, not production

| Component | Catalogue | Stale by | Classification |
|---|---|---|---|
| `conexao-event-runtime` | `languages/conexao-event-runtime.pot` | 76,025 s | **pre-existing** |
| `conexao-content` | `languages/conexao-content.pot` | 76,025 s | **NEW** |

Both catalogues are dated `1790623531`; the newest source in each plugin is
`1790699556` = **2026-09-29T16:32:36Z**, which is exactly the commit time of
`33114c6` — the release-identity commit that bumped the plugin version headers
without regenerating the `.pot` files. This is a **repository working-tree
condition created by a commit made before this verification**, entirely independent
of production. It is classified as a hard gate failure and was **not** suppressed;
the fix (`./scripts/i18n-make-pot.sh`) is outside the scope of a read-only
verification and was not applied.

`gate.json` is written by the gate runner and now records `status: fail`. The
committed version recorded `pass` at SHA `dbf8b13`.

## `python3 scripts/release-manifest.py --verify` — exit 0

`release=v2026.09.29 artifacts=8 built=8`; theme `conexao-br-irlanda 1.0.1`,
115 files, sha256 `b70d6376c9c50083…`. Manifest matches the artifacts and
`plugins.json`.

## `php scripts/generate-registry-docs.php --check` — exit 0

`registry OK: 14 plugins validated, 23 generated regions current (zero writes).`

## `scripts/verify-deploy.py` — NOT RUN

Deliberately not run. Its `/en/` rows assert a working bilingual deployment, and
this verification already established that no `/en/` route resolves to English
content; running it would produce a red result that adds no information beyond the
per-route evidence in `12-route-verification.md`.

## Environment-only limitations

| Item | Note |
|---|---|
| WordPress version | not obtainable read-only (no REST field) |
| PHP version | not obtainable read-only |
| `users/me` capabilities | empty map on WordPress.com; plugin/theme routes used instead |
| CDN cache | some anonymous reads returned `STALE`; every finding was re-read with a random cache-busting nonce |
| rate limiting | production returned `429` during the census; retried with backoff until 200 |
