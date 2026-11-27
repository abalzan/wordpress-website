# Stage 5 — Backup and deletion

**Date:** 2026-11-17 (backup), 2026-11-18 (verification)
**Database:** `wordpress` on `db:3306` (local Compose container `wordpress-website-db-1`, proven local in 02-local-database-identity.md).

## 8. Backup

| Field | Value |
|---|---|
| Backup path | `/tmp/ce_work/backup.sql` (outside repository) |
| Backup directory permissions | `0700` (`/tmp/ce_work/`) |
| Backup file permissions | `0600` |
| Database identity | `wordpress` on `db:3306` (`@@hostname` = `831e69426461`) |
| Timestamp | 2026-11-17 19:06:02 UTC |
| File size | 22,022,017 bytes (22 MB) |
| SHA-256 | `b761c5bb51a55b56f08b56adad1a44e2bf1228bf5c583b4335578df6607fb248` |
| Dump tool | `mysqldump` (MySQL 9.7.1) |
| Dump completed | Yes (footer: `-- Dump completed on 2026-11-17 19:06:02`) |

### Backup verification

- File is non-empty (22 MB).
- Contains `INSERT INTO wp_posts` statements (event posts present).
- Contains `'event'` post_type rows.
- Dump footer confirms completion.
- GTID and masking-policy messages in stderr are non-fatal warnings (the `wordpress` user lacks `PROCESS` privilege and `column_masking_policy` table access; neither affects data completeness).
- No credentials, passwords or secrets in the backup or this evidence file.

## 9. Deletion preflight

Before deletion, the following were confirmed:

1. **Database identity re-verified:** `DATABASE()` = `wordpress`, `@@hostname` = `831e69426461` — matches §4.
2. **Candidate manifest:** `/tmp/ce_work/manifest.json` (SHA-256 `c41b1c49d2807b192c9e96fa09f4168364b8f0007e9050c6566970ae46c5f744`), 396 eligible IDs.
3. **Candidate set reconciled:** `remaining_ids.txt` (396 IDs) is identical to the manifest's `eligible_ids`.
4. **Deletion hooks inspected:** `wp_delete_post( $id, true )` uses WordPress core deletion. No custom hooks on `before_delete_post` or `delete_post` trigger provider calls, remote services or shared-resource mutations for the `event` post type.
5. **Backup checksum recorded:** SHA-256 above.

## 10. Deletion

| Field | Value |
|---|---|
| Method | `wp_delete_post( $id, true )` (WordPress core API, force-delete) |
| Script | `/tmp/ce_work/delete.php` (re-verifies identity + eligibility per candidate before deletion) |
| Candidates in manifest | 396 |
| Deleted | 396 |
| Retained (eligibility changed) | 0 |
| Failed | 0 |
| Not found | 0 |

### Deletion result

All 396 candidate events were permanently deleted. Each candidate was re-checked immediately before deletion (county absent AND image absent). No candidate had gained a county or image between the inventory and deletion.

### Post-deletion candidate check

All 396 manifest eligible IDs were confirmed absent from `wp_posts` (any post type):

```
ELIGIBLE IDs STILL PRESENT (any type): 0
ELIGIBLE IDs STILL AS EVENTS: 0
```

## Identity re-verification (post-deletion)

| Query | Value |
|---|---|
| `DATABASE()` | `wordpress` |
| `@@hostname` | `831e69426461` |
| `@@port` | `3306` |
| `CURRENT_USER()` | `wordpress@%` |

Identity unchanged — same local Compose container.