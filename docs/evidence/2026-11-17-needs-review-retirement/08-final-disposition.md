# Stage 8 — Final disposition

**Date:** 2026-11-18
**Task status:** `COMPLETE`

## Summary

### 1. Repository baseline

| Field | Value |
|---|---|
| Branch | `event-import-improvements` |
| HEAD at task start | `19238f9` (Enable language switcher by default and update related documentation) |
| Worktree at task start | Clean (no pre-existing modifications) |
| Pre-existing changes preserved | None to preserve |

### 2. Local database proof

| Fact | Value |
|---|---|
| Compose project | `wordpress-website` |
| DB service | `db` (image `mysql:latest`) |
| DB container | `wordpress-website-db-1` (ID `831e69426461dbcb...`) |
| Compose network | `wordpress-website_default` |
| WordPress container | `wordpress-website-wordpress-1` |
| `$wpdb->dbname` | `wordpress` |
| `$wpdb->dbhost` | `db:3306` |
| `SELECT DATABASE()` | `wordpress` |
| `SELECT CURRENT_USER()` | `wordpress@%` |
| `SELECT @@hostname` | `831e69426461` (matches DB container ID prefix) |
| `SELECT @@port` | `3306` |
| Disposable test schema | `conexao_stage05_test_5bd9ece5c032` (NOT the target) |

**Local database proven:** YES — `@@hostname` matches the DB container ID, Compose project label confirms local ownership.

### 3. Canonical data contract

| Field | Authoritative representation | Usable rule |
|---|---|---|
| County | `conexao_county` taxonomy (hierarchical) | >= 1 assigned term (parent or child) |
| Event image | `_thumbnail_id` post meta → attachment | Positive integer → existing non-trashed attachment with `post_mime_type LIKE 'image/%'` |

Missing: absent meta, NULL, empty string, '0', non-existent attachment, trashed attachment, non-image attachment. Stale `_event_banner` URLs are NOT counted as usable images.

### 4. Needs Review retirement

| Item | Action |
|---|---|
| `class-conexao-event-review-queue.php` | DELETED |
| `require_once` in main plugin file | REMOVED |
| `add_submenu_page` for Needs Review | REMOVED |
| `render_needs_review_page()` | REMOVED |
| `render_needs_review_drilldown()` | REMOVED |
| `test-event-review-queue.php` | DELETED |
| `test-needs-review-retirement.php` | CREATED (10 assertions, all pass) |
| Event Sources page | PRESERVED |
| Event Import menu | PRESERVED |
| Importer, source registry, location queries | PRESERVED |

### 5. Pre-deletion inventory

| Metric | Value |
|---|---|
| Total events inspected | 3337 |
| All statuses | publish (3337) |
| Both county + image | 2656 (KEEP) |
| County only | 285 (KEEP) |
| Image only | 0 (KEEP) |
| Neither (DELETE) | 396 |
| Manifest path | `/tmp/ce_work/manifest.json` (mode 0600, dir 0700) |
| Manifest SHA-256 | `c41b1c49d2807b192c9e96fa09f4168364b8f0007e9050c6566970ae46c5f744` |
| Reconciliation | Two independent methods produced identical 396-ID set |

### 6. Backup

| Field | Value |
|---|---|
| Path | `/tmp/ce_work/backup.sql` (outside repository) |
| Directory permissions | `0700` |
| File permissions | `0600` |
| Database identity | `wordpress` on `db:3306` |
| Timestamp | 2026-11-17 19:06:02 UTC |
| Size | 22,022,017 bytes (22 MB) |
| SHA-256 | `b761c5bb51a55b56f08b56adad1a44e2bf1228bf5c583b4335578df6607fb248` |
| Dump completed | Yes |
| Verification | Non-empty, contains event data, footer confirms completion |

### 7. Deletion

| Field | Value |
|---|---|
| Method | `wp_delete_post( $id, true )` (WordPress core API) |
| Candidates | 396 |
| Deleted | 396 |
| Retained (eligibility changed) | 0 |
| Failed | 0 |
| Only events with neither county nor image deleted | YES (each candidate re-checked before deletion) |
| Media attachments deleted | 0 (preserved) |
| Source registry / logs / history / continuations / cron | Unchanged |

### 8. Post-deletion verification

| Check | Result |
|---|---|
| Deleted IDs absent | 396/396 absent |
| Events with county or image deleted | 0 |
| Remaining eligible (neither) | 0 |
| Before/after KEEP counts | 2656 + 285 = 2941 (identical) |
| Media attachments | 3217 (preserved) |
| Event Sources page | Available |
| Needs Review absent | Confirmed |
| Location queries | 2941 events with usable county |
| Importer/source code | Unchanged beyond queue removal |

### 9. Tests

| Suite | Result |
|---|---|
| `test-needs-review-retirement.php` | 10 passed, 0 failed |
| `test-import-continuation.php` | Pre-existing failure (unrelated) |
| `test-town-sanitization.php` | Pre-existing failure (unrelated) |
| All other 42 suites | PASS |
| **Overall** | 43/45 passed, 2 pre-existing failures |

**Test database:** `conexao_stage05_test_5bd9ece5c032` (disposable, NOT local dev DB)
**$wpdb identity:** `conexao_test@172.18.0.3`

### 10. Evidence files

| File | Path |
|---|---|
| 01-repository-baseline.md | `docs/evidence/2026-11-17-needs-review-retirement/01-repository-baseline.md` |
| 02-local-database-identity.md | `docs/evidence/2026-11-17-needs-review-retirement/02-local-database-identity.md` |
| 03-county-image-data-contract.md | `docs/evidence/2026-11-17-needs-review-retirement/03-county-image-data-contract.md` |
| 04-event-eligibility-inventory.md | `docs/evidence/2026-11-17-needs-review-retirement/04-event-eligibility-inventory.md` |
| 05-backup-and-deletion.md | `docs/evidence/2026-11-17-needs-review-retirement/05-backup-and-deletion.md` |
| 06-post-deletion-verification.md | `docs/evidence/2026-11-17-needs-review-retirement/06-post-deletion-verification.md` |
| 07-feature-retirement-and-tests.md | `docs/evidence/2026-11-17-needs-review-retirement/07-feature-retirement-and-tests.md` |
| 08-final-disposition.md | `docs/evidence/2026-11-17-needs-review-retirement/08-final-disposition.md` |

### 11. Git state

| Field | Value |
|---|---|
| Branch | `event-import-improvements` |
| Modified files | `conexao-event-importer.php`, `class-event-sources.php` |
| Deleted files | `class-conexao-event-review-queue.php`, `test-event-review-queue.php` |
| New files | `test-needs-review-retirement.php`, `docs/evidence/2026-11-17-needs-review-retirement/` |
| Commits made | 0 (no commit, push, deploy or stash) |

### 12. Preservation

- No production/shared/remote database access or mutation.
- No provider calls, importer runs, Run All, retries, cron jobs or scheduled imports.
- No source registry, log, history, continuation or cron changes.
- No commit, push, merge, deploy, activate, release, stash, reset or clean.
- No credentials in argv, shell history, transcripts, reports, logs or tool output.
- Media-library attachments preserved.
- County/location taxonomies and shared terms preserved.

### 13. Final task status

**COMPLETE**

All completion criteria met:
- Needs Review admin page, menu entry and queue runtime retired.
- Event Sources and normal importing/location querying remain intact.
- Local development database positively identified.
- Candidate population fully inventoried using canonical county/image contract.
- Verified backup exists.
- Only events with neither usable county nor usable image permanently deleted (396).
- Post-deletion verification passes.
- Relevant tests and evidence updates complete.
- No unauthorised database or repository actions occurred.