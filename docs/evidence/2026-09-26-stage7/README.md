# Stage 7.x evidence — Laois Events visibility

Machine evidence for
[`CONEXAO_BR_LAOIS_EVENTS_VISIBILITY_REPORT.md`](../../reports/CONEXAO_BR_LAOIS_EVENTS_VISIBILITY_REPORT.md).
Captured 2026-09-26 against the local Docker stack
(`http://localhost:8080`, `Europe/Dublin`, WordPress "today" = 2026-09-26).

| File | What it proves |
|---|---|
| `laois-events-visibility-audit.json` / `.md` | Per-event, stage-by-stage PASS/FAIL verdicts for all 30 events plus every identity field, and the measured exclusion-reason tally. |
| `laois-events-before.json` | Full pre-change snapshot (20 fields × 30 events). `targets_in_county_filter: 0`. |
| `laois-events-after.json` | Full post-change snapshot. `targets_in_county_filter: 30`. Diffing the two shows **only** `county` changed. |
| `http-before.md` | BEFORE HTTP: `/eventos/?county=laois` returns 0 of the 30 targets. |
| `http-after.md` / `.json` | AFTER HTTP: `/eventos/?county=laois` returns **30/30**; other counties unchanged; the one non-200 is the expected out-of-range page 6 (404). |
| `repair-plan.json` | The dry-run plan: 31 records, the shared `conexao_county/laois` term (1373), with each record's identity fields so the change is reversible. |
| `terms-backup-before-repair.sql` | Pre-change `wp_term_relationships` / `wp_term_taxonomy` dump for rollback. |
| `regression-suite.log` | `tests/test-source-county-hint.php` — 18 passed, 0 failed. Asserts the **rule**, references no record ID. |
| `future-event-proof.log` | Phase 17: a future Laois Tourism ICS event reaches the county query automatically; no new term created. |
| `repair-dry-run-after.log` | Idempotency: a second `--apply` plans 0 further assignments. |
| `script-contract.log` | `./scripts/run-tests.sh --scripts` — 6/6 suites, 0 failed. |
| `permanent-gates.log` | `verify-permanent-gates.py` — 7/7 gates, 86 assertions, 0 violations. |
| `run-tests-full.log` | Full in-process run, including the 3 failures proven pre-existing on the reverted baseline. |
| `phpstan-changed-files.log` | PHPStan scoped to the two changed files — **No errors**. (A full-repo run OOMs on `jetpack`, unrelated to this change.) |

## Reproducing

```bash
docker compose up -d
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-source-county-hint.php
curl -s 'http://localhost:8080/eventos/?county=laois' | grep -c 'id="post-1000'
```

_Last verified: 2026-09-26 by Stage 7.x — Laois Events Visibility_
