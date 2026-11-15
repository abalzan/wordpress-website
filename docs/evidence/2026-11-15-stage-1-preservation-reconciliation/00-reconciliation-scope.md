# Stage 1 preservation reconciliation — scope and safety record

Read-only post-implementation reconciliation of the two outstanding Stage 1
evidence questions. This task did not modify application code, database content
or protected records.

## Repository state at start

- Branch: `event-import-improvements`
- HEAD: `c76d836a6ada1948924562b8dcea53ee2f9dd974`
- Worktree: dirty by design — the Stage 1 implementation, its tests, its
  evidence and its report were all uncommitted, exactly as Stage 1 left them.

`git status --short` at the start of this reconciliation:

```
 M docs/README.md
 M docs/plugins/conexao-event-importer.md
 M docs/reports/README.md
 M docs/testing.md
 M wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php
 M wp-content/plugins/conexao-event-importer/includes/class-event-importer.php
 M wp-content/plugins/conexao-event-importer/includes/class-event-sources.php
 M wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php
 M wp-content/plugins/conexao-event-importer/includes/class-eventbrite-pacing.php
 M wp-content/plugins/conexao-event-importer/includes/class-import-history.php
 M wp-content/plugins/conexao-event-importer/includes/class-import-result.php
 M wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-source.php
 M wp-content/plugins/conexao-event-importer/includes/sources/class-heritage-week-source.php
 M wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot
?? docs/evidence/2026-11-15-stage-1/
?? docs/reports/2026-11-15-stage-1-event-importer-run-coordination.md
?? wp-content/plugins/conexao-event-importer/tests/test-stage1-run-coordination.php
```

This state is unchanged at the end of the reconciliation. No file outside
`docs/evidence/2026-11-15-stage-1-preservation-reconciliation/` and the Stage 1
report was created or modified.

## Files inspected

Stage 1 evidence:

- `docs/evidence/2026-11-15-stage-1/00-before-edit-preservation-baseline.md`
- `docs/evidence/2026-11-15-stage-1/03-shared-preservation-after.txt`
- `docs/evidence/2026-11-15-stage-1/04-isolation-and-scope.md`
- `docs/evidence/2026-11-15-stage-1/01-targeted-tests.txt`
- `docs/evidence/2026-11-15-stage-1/02-quality-gates.txt`
- `docs/evidence/2026-11-15-stage-1/05-status-model.md`

Stage 0 / 0.5 evidence:

- `docs/evidence/2026-11-14-stage-0-5/11-shared-preservation-baseline.txt`
- `docs/evidence/2026-11-14-stage-0-5/12-isolated-clone.txt`
- `docs/evidence/2026-11-14-stage-0-5/14-final-verification.txt`
- `docs/evidence/2026-11-14-stage-0-5/01-event-content-before.tsv`
- `docs/evidence/2026-11-14-stage-0-5/09-event-content-after-unexpected-change.tsv`
- `docs/evidence/2026-11-14-stage-0-5/09-content-change.md`
- `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/10-content-untouched-verification.csv`
- `docs/evidence/2026-11-14-import-history-consolidation/10-export-language-diagnostic-4g.log`
- `docs/evidence/2026-11-14-import-history-consolidation/01-importer-suite-aggregate.log`

Reports and plans:

- `docs/reports/2026-11-15-stage-1-event-importer-run-coordination.md`
- `docs/reports/2026-11-14-stage-0-5-test-safety.md`
- `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md`
- `docs/reports/2026-10-05-eventbrite-g17-reconciliation-fix.md`
- `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md`

Source (read-only, to understand how the `stage32_export_test` fixtures are
created):

- `wp-content/plugins/conexao-event-importer/tests/test-export-language.php`

## Environments and databases

| Environment | Database | Role |
|---|---|---|
| Shared local WordPress | `wordpress` | The database the running local site uses; the target of both Stage 1 captures |
| Disposable Stage 0.5 clone | `conexao_stage05_test_5bd9ece5c032` | The clone used for WordPress-dependent Stage 0.5 and Stage 1 test runs |

MySQL server identity: host `831e69426461`, port `3306`, datadir
`/var/lib/mysql/`, server version `9.7.1`.

Both databases still exist and were available read-only at reconciliation time.

## Read-only access method and verification

The Stage 1 captures were taken with the `wordpress` application account, which
holds `ALL PRIVILEGES` and is **not** read-only. Using it for this
reconciliation would not have satisfied the read-only requirement, so a
dedicated account was created for this task:

```sql
CREATE USER 'conexao_ro'@'%' IDENTIFIED BY '<redacted>';
GRANT SELECT ON `wordpress`.* TO 'conexao_ro'@'%';
GRANT SELECT ON `conexao_stage05_test_5bd9ece5c032`.* TO 'conexao_ro'@'%';
```

Resulting grants:

```
GRANT USAGE ON *.* TO `conexao_ro`@`%`
GRANT SELECT ON `wordpress`.* TO `conexao_ro`@`%`
GRANT SELECT ON `conexao_stage05_test_5bd9ece5c032`.* TO `conexao_ro`@`%`
```

Enforcement was verified empirically **before** any data query ran, by
attempting writes that had to fail:

| Probe | Result |
|---|---|
| `SELECT 1` | succeeded |
| `CREATE TABLE wordpress.zz_ro_probe (id INT)` | `ERROR 1142: CREATE command denied` |
| `UPDATE wordpress.wp_posts SET post_title='x' WHERE ID=1` | `ERROR 1142: UPDATE command denied` |

Both write attempts were rejected by privilege enforcement, so the account is
read-only by grant, not merely by convention. No `AUTOCOMMIT=0` or other
session-level setting was relied on as the guarantee.

All queries were plain `SELECT` / `SHOW` against base tables. No stored
procedures, user-defined functions, `LOCK TABLES`, `SELECT ... FOR UPDATE`,
`INTO OUTFILE` or `INTO DUMPFILE` were used. WordPress was never bootstrapped;
no HTTP request, importer, continuation, cron or scheduler operation was
triggered.

## Statement of no modification

No database write, update, insert or delete was performed against either
database. No protected post, postmeta row, option or source-registry entry was
changed. No fixture was created, restored or deleted. No application PHP,
JavaScript, CSS, test or plugin configuration was modified. No commit, push,
merge, deploy, activation or release was performed.

The only database objects created were the `conexao_ro` account and its `SELECT`
grants, which are access-control metadata and not application data.

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_