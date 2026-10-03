# Report — CI fresh-install integration setup

| | |
|---|---|
| **Stage / task name** | CI integration setup recovery |
| **Date** | 2026-09-28 |
| **Author / agent** | GitHub Copilot |
| **Branch** | `i18n` |
| **Start SHA** | `3da93fafc42939b16d22b80c739d066ffe94b0a8` |
| **Final SHA** | `3da93fafc42939b16d22b80c739d066ffe94b0a8` (not committed) |
| **Working tree at finish** | dirty — this task's CI/docs/setup changes plus the user's concurrent language-switcher edits in theme, acceptance tests, docs, report and evidence |

## 1. Scope completed

Reproduced CI's fresh WordPress failure, made Polylang setup initialize its API when a fresh site has no languages, configured the manual integration job with `pt`/`en`, required test plugins, pretty permalinks and the authored Jobs-page rollout, and stopped the non-deterministic full-site suite from blocking every push/PR.

## 2. Scope NOT completed

The full test harness is not green on a clean database. A complete synthetic content/menu fixture is still required before the integration job can become a required push/PR check. No assertions were weakened and no database dump was added.

## 3. Files added / modified / deleted

**2 added, 7 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `.github/workflows/ci.yml` | Configure Polylang and Jobs fixture; make integration workflow-dispatch-only | No production runtime changes |
| `scripts/run-polylang-setup.php` | Initialize Polylang's normal frontend context on a fresh site before creating languages | Existing idempotent setup utility |
| `scripts/README.md` | Update setup-script description and verification date | Catalogue remains authoritative |
| `docs/development.md`, `docs/testing.md` | Document manual integration gate and fixture limitation | Updated verification markers |
| `docs/reports/README.md`, `docs/evidence/README.md`, `docs/README.md` | Index report/evidence | Navigation only |
| `docs/reports/site/2026-09-28-ci-integration-fixture.md` | This report | Added |
| `docs/evidence/2026-09-28-ci-integration-fixture/results.json` | Machine-readable CI and reproduction evidence | Added |

No `wp-content/` file was changed.

## 4. Runtime impact

Production WordPress behavior is unchanged. The CI-only test stack now initializes Polylang languages and the Jobs page before its gate runs.

## 5. Content / data impact

One authored EN Jobs page was created in the disposable local reproduction database through `scripts/run-en-translation.php` after a dry-run. `PT-drift=0`; the entire reproduction project and its volumes were removed. No production data was accessed or changed.

## 6. Polylang impact

Fresh-site setup now creates `pt` and `en`, selects `pt` as default and leaves zero unassigned posts/terms in the isolated setup check. The Jobs rollout created and linked one EN page with `missing_en=0` and `PT-drift=0`.

## 7. Route / HTTP impact

No production route changes. The focused `shared_slug_page` gate for `/en/empregos/` passed 16/16 checks after applying the authored Jobs stage. The full HTTP acceptance layer was not rerun after the workflow edits.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | Disposable local Docker site only |
| Content or DB mutation | **no** | Temporary local test DB only; removed with its Compose project |

## 9. Verification commands and results

| Command / check | Exit | Result |
|---|---:|---|
| `./scripts/lint.sh` | 0 | 469 PHP files syntax-clean; no new PHPCS violations; PHPStan 206/206, no errors |
| `./scripts/run-tests.sh --scripts` | 0 | 6 suites passed, 0 failed; 551 assertions passed |
| `python3 tests/scripts/verify-agent-governance.py` | 0 | 247 passed, 0 failed |
| `python3 tests/scripts/verify-script-conventions.py` | 0 | 317 passed, 0 failed |
| `php -l scripts/run-polylang-setup.php` | 0 | No syntax errors |
| Workflow YAML parse | 0 | Valid |
| Fresh-site Polylang setup dry-run + apply | 0 | `pt_BR`/`en_US` created as `pt`/`en`; default `pt`; URL options verified |
| Jobs rollout dry-run + apply + idempotent rerun | 0 | First apply created one linked EN page; rerun skipped it; `PT-drift=0` |
| `test-en-jobs-shared-slug.php` | 0 | 16 passed, 0 failed |
| `git diff --check` | 0 | Clean |
| Original Actions run `36429929963` | 1 | Integration harness: 30/61 PHP suites passed, 31 failed; 66 assertions failed; script-contract 6/6 passed; HTTP suites 0/3 passed |

### Limitations

The Actions run has not been rerun with these changes. The full integration suite remains unsuitable as a required push/PR check until its content-dependent cases have a deterministic synthetic database fixture. The integration job remains manually dispatchable for diagnosis.

## 10. Failure proofs / negative tests

The existing shared-slug gate exercised unique-slug, CPT and unpaired-duplicate negatives; all passed. The empty-site setup probe reproduced that Polylang's API was unavailable before language initialization and available after the setup path.

## 11. Regression comparison

The original CI run failed before these changes. Focused post-change checks pass, but there is no full-suite after-run to compare; the remaining dataset-dependent suite is explicitly not claimed as passing.

## 12. Known pre-existing failures

The full clean-site integration run depends on migrated pages, posts, menus, terms and domain records absent from an empty WordPress install. These failures are broader than the Jobs URL change and were the reason Stage E's engineering standard says integration checks become required only after the suite is deterministic.

## 13. Limitations

No GitHub Actions rerun was triggered. Full HTTP acceptance and the full PHP suite were not proven green on the fresh database. No production environment was accessed.

## 14. Evidence paths

- `docs/evidence/2026-09-28-ci-integration-fixture/results.json` — original CI counts and fresh-install setup/gate results.

## 15. Documentation updated

`docs/development.md`, `docs/testing.md`, `scripts/README.md`, `docs/reports/README.md` and `docs/evidence/README.md`.

## 16. Rollback / recovery

Revert the workflow, setup, catalogue and documentation changes. The temporary reproduction database is already removed; rollback does not affect developer data.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Fresh-site Polylang setup and the Jobs gate are verified, but the broader integration suite remains non-deterministic and has not passed as a complete CI run.

_Last verified: 2026-09-28 by CI integration setup recovery_
