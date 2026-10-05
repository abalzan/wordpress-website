# Evidence — G18, automatic continuation of a resumable enumeration

Date: 2026-10-05 · Branch: `master` · Start SHA: `a22b4c6`

Narrative: [`docs/reports/2026-10-05-eventbrite-g18-continuation.md`](../../reports/2026-10-05-eventbrite-g18-continuation.md)
Plan: [`docs/reports/2026-10-05-import-continuation-scheduler-plan.md`](../../reports/2026-10-05-import-continuation-scheduler-plan.md)

| File | What it is |
|---|---|
| `01-g18-suite.txt` | the new suite's full output: `265 passed, 0 failed`, including the deterministic trace and every phase of the Import History truth table |
| `02-mutations.txt` | `tests/experiments/g18-mutations.sh` — one mutation per mechanism; each must make the suite fail |
| `03-baseline-and-full-run.txt` | the full `./scripts/run-tests.sh` run, with the pre-existing `test-export-language.php` memory failure separated from anything new |
| `04-static-quality.txt` | `./scripts/lint.sh` (PHP syntax, PHPCS, PHPStan) |
| `05-permanent-gates.txt` | registry drift, permanent invariant gates, i18n freshness, script conventions |
| `06-browser-continuation.txt` | the Chromium/Playwright proof: the operator surface in a real browser, then the continuation executing with the browser closed — plus the `--negative-control` run where the cell correctly reads `NOT scheduled` |
| `07-cron-environment.txt` | the measured WP-Cron spawn failure that motivates the `shutdown` fail-safe |
| `08-failure-attribution.txt` | every failing suite in the full run, attributed, with the direct A/B that rules this stage out as their cause |

## How each claim maps to a command

| Claim | Command |
|---|---|
| a resumable job continues on its own | `docker compose exec wordpress php wp-content/plugins/conexao-event-importer/tests/test-import-continuation.php` |
| the continuation is not vacuous | `bash tests/experiments/g18-mutations.sh` |
| nothing else regressed | `./scripts/run-tests.sh` |
| the UI tells the truth | `python3 tests/browser/verify-import-continuation-browser.py` |
| …and does so non-vacuously | `python3 tests/browser/verify-import-continuation-browser.py --negative-control` |

## Verification at a glance

```
test-import-continuation.php                  265 passed, 0 failed
g18-mutations.sh                              15 / 15 proven, 0 vacuous
verify-import-continuation-browser.py          20 passed, 0 failed
verify-import-continuation-browser.py --neg     4 passed, 0 failed
./scripts/run-tests.sh       100 suites / 7712 assertions passed, 6 failed
                             (all 7 failures attributed, 0 caused here)
./scripts/lint.sh                             OK (PHPCS + PHPStan clean)
verify-permanent-gates.py                     6 / 7 gates, 0 new violations
```

## Safety

Every command is local-only. `conexao-event-importer` is `production: false` in
`plugins.json`; no production host, credential, database or configuration was
touched, and no deployment ran.
