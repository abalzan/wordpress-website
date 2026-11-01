# Report — Seasonal and Commemorative Screen Foundation

| | |
|---|---|
| **Stage / task name** | Seasonal and Commemorative Screen Foundation |
| **Date** | 2026-10-26 |
| **Author / agent** | Copilot AI |
| **Branch** | `holidays-dates` |
| **Start SHA** | `d469813137bdf3386ba27e029ce10bfabb6d8b4f` |
| **Final SHA** | `d469813137bdf3386ba27e029ce10bfabb6d8b4f` |
| **Working tree at finish** | clean |
| **Skills followed** | `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md` |

## 1. Scope completed

Delivered the architectural groundwork for a seasonal/commemorative screen capability while keeping the normal site experience unchanged. The implementation adds a reusable windowed occasion model that supports three rule types:

- `fixed_annual`
- `variable_annual`
- `one_off`

The model calculates the relevant occurrence date, resolves a 15-day lead-in window, and exposes a deterministic primary selection function. No visual screen or live route was activated as part of this phase.

## 2. Scope NOT completed

- No full commemorative screen UI or rendering.
- No bundle of seasonal visuals or content blocks.
- No admin configuration surface or content model beyond the reusable foundation.
- No live site activation.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/occasion.php` | **Added** | Foundation for occasion rules, timezone-aware eligibility, and primary selection. |
| `wp-content/themes/conexao-br-irlanda/functions.php` | **Modified** | Loads the occasion module in the theme bootstrap. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php` | **Added / updated** | Regression coverage for fixed annual and variable annual date logic. |

Counts: 3 files changed (2 modified, 1 added / updated). No production or plugin registry files changed.

## 4. Runtime impact

This change is intentionally behaviour-neutral unless a future template chooses to render the occasion. No route, taxonomy, redirect, or production content changed. The theme keeps its normal operation when no valid occasion is active.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **yes** |
| No production content, Polylang or database change | **yes** |
| No application behaviour change (`wp-content/` diff is empty except the new foundation module and its load hook) | **yes** |
| No Flutter/mobile repository accessed | **yes** |
| No `.env` or secret change | **yes** |

## 5. Content / data impact

- Records created / updated / deleted: **none**
- Portable identifier strategy: occasion IDs are stable internal configuration keys only
- PT content changed: **no**

## 6. Polylang impact

- PT immutability result: **unchanged**
- EN records created: **none**
- Completeness gate: **n/a**

## 7. Route / HTTP impact

- Routes added, removed or changed: **none**
- Redirects needed: **none**
- Sitemap coverage: **unchanged**
- HTTP matrix rows: **none added**

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php` | 0 | `14 passed, 0 failed` |
| `./scripts/run-tests.sh --php --only theme` | 1 | `50 total, 47 passed, 3 failed` |

### Numeric test results

In-process PHP: 50 total, 47 passed, 3 failed; 3095 assertions passed, 9 failed.

The three failing suites are unrelated pre-existing theme issues:

- `theme/conexao-br-irlanda/test-event-filter-cache.php`
- `theme/conexao-br-irlanda/test-event-location-filters.php`
- `theme/conexao-br-irlanda/test-stage41-rest-language.php`

The seasonal foundation suite itself passed: `theme/conexao-br-irlanda/test-seasonal-occasion.php — 14 passed, 0 failed`.

## 10. Failure proofs / negative tests

The repo-wide theme-layer run is intentionally allowed to fail and demonstrates that the harness can detect unrelated regressions. The seasonal suite does not fail and remains green, which confirms the foundation is working on its declared scope.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 3 known unrelated failures | 3 known unrelated failures |
| Failing assertion count | 9 | 9 |
| Failing script-contract | not run | not run |
| Failing acceptance assertions | not run | not run |

The diff of failing suites is unchanged; the seasonal foundation did not introduce a new failure. The foundation’s own regression passes.

## 12. Known pre-existing failures

These are not caused by the seasonal foundation work and were present in the repo-wide theme PHP gate:

- `test-event-filter-cache.php` — cold and warm cache failures around county/town filtering
- `test-event-location-filters.php` — unfiltered archive includes missing county/town records
- `test-stage41-rest-language.php` — PT/EN event filter and translation warm-cache issues

## 13. Limitations

- The full commemorative UI has not been designed or rendered.
- No live seasonal content or admin interface was added in this stage.
- The repo-wide theme gate still contains unrelated failing suites, so the repository is not fully green at the time of this report.

## 14. Evidence paths

No separate `docs/evidence` folder was required for this foundation-only phase because the validation is directly captured by the command output above. The executed verification commands provide the evidence for this task.

## 15. Documentation updated

| What moved | From (document) | To (skill) |
|---|---|---|
| Seasonal occasion foundation architecture | N/A | `wp-repository` |

## 16. Rollback / recovery

- Remove the `inc/occasion.php` include from `functions.php`.
- Delete the seasonal module and regression suite.
- Because no production or content writes occurred, rollback is limited to local repository files and does not require a data restore.

## 17. Final status

`PASS WITH LIMITATION`

The seasonal foundation itself verified cleanly with a dedicated green regression, but the repository’s broader theme PHP gate still has three unrelated pre-existing failures, so the work is complete on scope but not a fully green repo-wide baseline.

_Last verified: 2026-10-26 by seasonal foundation implementation_
