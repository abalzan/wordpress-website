# Stage 6 — Post-deletion verification

**Date:** 2026-11-18
**Database:** `wordpress` on `db:3306` (local Compose container `wordpress-website-db-1`).

## 11. Post-deletion verification

### 11.1 Deleted IDs absent

All 396 candidate IDs from the manifest were confirmed absent from `wp_posts`:

- Eligible IDs still present (any type): **0**
- Eligible IDs still as events: **0**

### 11.2 No event with county or image was deleted

The pre-deletion truth table showed:

| Category | Count | Action |
|---|---|---|
| Both county + image | 2656 | KEEP |
| County only | 285 | KEEP |
| Image only | 0 | KEEP |
| Neither | 396 | DELETE |

Post-deletion truth table:

| Category | Count |
|---|---|
| Both county + image | 2656 |
| County only | 285 |
| Image only | 0 |
| Neither | **0** |
| **Total events** | **2941** |

The KEEP counts (2656 + 285 = 2941) are identical before and after deletion. No event with a usable county or usable image was deleted.

### 11.3 Event population

| Metric | Before | After |
|---|---|---|
| Total events (all statuses) | 3337 | 2943 |
| Events (excluding trash/revision/autodraft) | 3337 | 2941 |
| Deleted | — | 396 |

The 2-status difference (2943 vs 2941) is the 2 trashed/autodraft event records that were excluded from the inventory population.

### 11.4 No remaining eligible events

Post-deletion truth table confirms **0 events** satisfy both missing conditions (no county AND no image) within the defined population.

### 11.5 Media attachments preserved

| Metric | Count |
|---|---|
| Media attachments (`post_type=attachment`) | 3217 |

No media-library attachment posts were deleted. Attachments remain in the database.

### 11.6 Event Sources and menu

| Check | Result |
|---|---|
| `Conexao_Event_Sources` class exists | YES |
| `render_sources_page()` method exists | YES |
| Event Sources submenu registered under Event Import | YES |
| Event Import top-level menu present | YES |
| Needs Review menu/page absent | YES |
| `Conexao_Event_Review_Queue` class loaded | NO (correct) |

### 11.7 Location-based event queries

| Check | Result |
|---|---|
| Events with usable county (`conexao_county` term) | 2941 |
| Location query returns events | YES |

Location-based event querying remains functional. All 2941 remaining events have a usable county assignment.

### 11.8 Normal import/source code intact

The importer, source registry, county extraction, image extraction, import history, operator logs and continuation coordination were not altered. Only the Needs Review queue class, its loader `require_once`, the `render_needs_review_page()` method, the `add_submenu_page` registration and the old test file were removed.