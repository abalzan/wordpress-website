# Stage 4 — Event eligibility inventory (read-only)

**Date:** 2026-11-17
**Database:** `wordpress` on `db:3306` (local Compose container `wordpress-website-db-1`, proven local in 02-local-database-identity.md).
**Manifest:** `/tmp/ce_work/manifest.json` (directory `0700`, file `0600`), outside the repository.
**Manifest SHA-256:** `c41b1c49d2807b192c9e96fa09f4168364b8f0007e9050c6566970ae46c5f744`

## 7.1 Event population

Post type: `event`. Population query: `post_type='event' AND post_status NOT IN ('revision','auto-draft','trash','autodraft')`.

Revisions, autosaves (auto-draft), attachments, and trashed records are excluded as non-genuine event records.

| Metric | Value |
|---|---|
| Total events inspected | 3337 |
| All remaining statuses | publish (3337) |

## 7.2 Truth-table evaluation (canonical county + usable image)

County = assigned term in `conexao_county` taxonomy. Image = valid non-trashed image attachment referenced by `_thumbnail_id`.

| Usable county | Usable image | Count | Action |
|---|---|---|---|
| Yes | Yes | 2656 | KEEP |
| Yes | No | 285 | KEEP |
| No | Yes | 0 | KEEP |
| No | No | 396 | DELETE (eligible) |

## 7.3 Protected candidate manifest

The manifest at `/tmp/ce_work/manifest.json` contains, per event: post ID, post type/status, county-present boolean + canonical field, image-present boolean + representation, eligibility/reason. No credentials, full content, or personal details. File mode `0600`, directory `0700`.

## 7.4 Candidate set verification (reconciliation)

Two independent calculations:
- Method A: per-event `get_post_meta` + taxonomy lookups.
- Method B: single set-based SQL (NOT EXISTS county term; LEFT JOIN thumbnail → attachment image).

Result: **reconciled = true**. Both methods produce the identical eligible ID set of 396 IDs.

Confirmed:
- Candidate set contains no event with a usable county or usable image.
- All non-candidates (2941 events) have a documented retention reason (county and/or image present).
- No event was excluded from the population without reason.

**Eligible candidate count: 396 events (all `publish` status).**
