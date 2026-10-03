# Stage P evidence — a county copied into the `conexao_town` taxonomy

Machine evidence for
[`2026-09-26-stage-p-county-not-town-report.md`](../../reports/2026-09-26-stage-p-county-not-town-report.md).
Captured 2026-09-26 against the local Docker stack (`http://localhost:8080`).

| File | What it proves |
|---|---|
| `terms-backup-before-repair.sql` | Pre-change `wp_terms` / `wp_term_taxonomy` / `wp_term_relationships` dump for rollback. |
| `dry-run-laois.json` | The scoped dry-run plan: 4 Laois events, each with `kept_county: "Laois"` and its identity fields, so the change is reversible. Zero writes. |
| `dry-run-all-counties.json` | Unscoped dry run: **19** erroneous relationships across `laois` 4, `clare` 4, `kerry` 7, `meath` 1, `offaly` 2, `co-clare-ireland` 1. Evidence for the follow-up in §7.2 — deliberately NOT applied. |
| `apply-laois.json` | The apply result: `removed: 4`, `terms_deleted: ["Laois"]`, `terms_kept: []`. |
| `town-sanitization-test.log` | `test-town-sanitization.php` — **82 passed, 1 failed**. The 1 failure is the pre-existing `Co Clare Ireland` term (baseline was 32 passed, 1 failed). |
| `future-import-proof.log` | AC7. Three end-to-end cases through the real source classes: Eventbrite `city=Laois` → no town term created; ICS `LOCATION:Co. Laois` → `town=''`; ICS `LOCATION:Durrow, Co. Laois` → `town=Durrow` still imported. |
| `http-after.md` | `/eventos/?county=laois` → HTTP 200, 10 cards (county filter intact). `/eventos/?cidade=laois` → 0 cards (erroneous option gone). `county=cork` and `cidade=portlaoise` unchanged. |
| `run-tests-full.log` | Full `./scripts/run-tests.sh`: 43/55 in-process suites, 3464 assertions passed, 3/3 HTTP acceptance. The 12 failing suites are the identical set in the Stage 7.x baseline. |

## Reproducing

```bash
docker compose up -d
docker compose exec -T wordpress php /var/www/html/scripts/repair-event-town-terms.php --dry-run --county=laois
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-town-sanitization.php
curl -s 'http://localhost:8080/eventos/?county=laois' | grep -c 'id="post-'
```

_Last verified: 2026-09-26 by Stage P — County-not-town repair_
