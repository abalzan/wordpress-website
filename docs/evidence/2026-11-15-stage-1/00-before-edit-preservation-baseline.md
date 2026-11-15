# Stage 1 before-edit preservation baseline

Captured 2026-11-15 before source edits. All database observations below were
read-only; no WordPress bootstrap, import run, provider request, content write,
or production action was performed.

## Repository and stack identity

- Branch: `event-import-improvements`
- HEAD: `c76d836a6ada1948924562b8dcea53ee2f9dd9745`
- Worktree: clean before this stage
- Compose services: `db` healthy; `wordpress` running on the existing local
  stack
- Running WordPress database: `wordpress` (the shared local database)
- Test database for WordPress-dependent checks: not selected for this baseline;
  no WordPress-dependent test was run against the shared database

## Protected content inventory

The shared database contains 3,705 `event` posts and 72,527 related postmeta
rows. The exact protected Stage 0/0.5 incident records and their source IDs
remain enumerated in
[`11-shared-preservation-baseline.txt`](../2026-11-14-stage-0-5/11-shared-preservation-baseline.txt).
The complete before/after event-row inventories remain in
[`01-event-content-before.tsv`](../2026-11-14-stage-0-5/01-event-content-before.tsv)
and
[`09-event-content-after-unexpected-change.tsv`](../2026-11-14-stage-0-5/09-event-content-after-unexpected-change.tsv).

The 275-row `stage32_export_test` protected cohort is defined exactly by the
read-only selector below; its post ID/source-ID list fingerprint is recorded so
the same set can be compared after verification. The 285 rows mentioned in the
Stage 0.5 report were clone-only leftovers and are not in this shared cohort.

```sql
SELECT p.ID, COALESCE(srcid.meta_value, '')
FROM wp_posts p
LEFT JOIN wp_postmeta src
  ON src.post_id = p.ID AND src.meta_key = '_event_source'
LEFT JOIN wp_postmeta srcid
  ON srcid.post_id = p.ID AND srcid.meta_key = '_event_source_id'
WHERE p.post_type = 'event'
  AND src.meta_value = 'stage32_export_test'
ORDER BY p.ID;
```

| Source ID | Rows |
|---|---:|
| `stage32-exp-0001` | 55 |
| `stage32-exp-0002` | 110 |
| `stage32-exp-0003` | 55 |
| `stage32-exp-0004` | 55 |
| **Total** | **275** |

## Fingerprints

These hashes are SHA-256 over deterministic, sorted raw MySQL output.

| Dataset | Rows | SHA-256 |
|---|---:|---|
| All `event` posts (`ID,status,date,modified,slug,title`) | 3,705 | `86a39a84e474e52bb05f75ccea4d35716024e2586be757f4c315af8b70eb4c97` |
| All `event` postmeta (`post_id,meta_key,meta_value`) | 72,527 | `ad87518f40a959e6f951bda9394fa1df6e3738d15430fc73c74328aed7142c13` |
| `stage32_export_test` `(post_id,source_id)` list | 275 | `a77b0943883db818466e283d3783ba529b9d7aa695bb18cd8384ed8d3acacd3e` |
| `conexao_event_sources` option output | — | `d6225636e8b84b8a0ab2d57f4fc12b3a73adc3dd25d3e2326bf328dfd1af2073` |
| `conexao_event_import_history` option output | — | `2968eb942c5b8302b4b7940b0c4f38c53e4fc07bb272ebbb4b97652deaf1adeb` |
| `conexao_event_import_continuations` option output | — | `e73384237f4d3a4d5d6f35eed34760add6ad4fd69735daf69e4efdcf5968ed23` |
| `cron` option output | — | `eb5c121b924b0deb7c6b65d77e4ae20aee06204fcab7e3679bf1a1f01d923eb3` |

This stage preserves the Stage 0/0.5 records, including the 47 incident-created
and 13 incident-updated events, plus the six external-import events recorded by
Stage 0.5. The existing Stage 0/0.5 evidence is historical and is not edited.

_Last verified: 2026-11-15 by Stage 1 before-edit read-only baseline_
