# Stage 3 — Canonical county / event-image data contract

**Date:** 2026-11-17

## 5.1 County (authoritative representation)

| Item | Value |
|---|---|
| Event post type | `event` (registered by `conexao-data-model`) |
| County taxonomy | `conexao_county` (hierarchical, public) |
| Registered on post types | `guide`, `event`, `job`, `sponsor`, `course_provider`, `leisure` |
| Usable county rule | Post has >= 1 assigned term in `conexao_county` (parent OR child term both count) |
| Missing county | No term relationship; absent/NULL/empty |
| Read path | `wp_term_relationships` → `wp_term_taxonomy` (taxonomy = `conexao_county`) |
| Source | `wp-content/plugins/conexao-data-model/includes/class-meta.php` / `register_taxonomies()`; `class-conexao-event-review-queue.php` `COUNTY_TAXONOMY = 'conexao_county'` |

No alternative county representation (no county meta key) was found in the event model. `conexao_county` is authoritative.

## 5.2 Event image (usable-image representations)

| Item | Value |
|---|---|
| Featured image meta | `_thumbnail_id` (post meta, references attachment post ID) |
| Usable image rule | `_thumbnail_id` is a positive integer referencing an existing post with `post_type='attachment'`, `post_status <> 'trash'`, and `post_mime_type LIKE 'image/%'` |
| Missing image | `_thumbnail_id` absent/NULL/empty/'0', or references a non-existent/trashed/non-image attachment |
| Additional image handling | `Conexao_Event_Image_Handler` / `Conexao_Event_Image_Sync_Admin` manage banner/download of external images into Media Library and set `_thumbnail_id`; `_event_banner` meta records external URLs but is not itself a usable local image resource |
| Read path | `get_post_meta($id, '_thumbnail_id', true)` → `wp_posts` attachment row |
| Source | `class-conexao-event-review-queue.php` `field_counts()` thumbnail check; `class-event-image-sync-admin.php` |

## 5.3 Classification summary

The county/image contract is unambiguous:
- County = `conexao_county` term presence (taxonomy).
- Image = valid local featured-image attachment via `_thumbnail_id`.
- Stale URLs (`_event_banner`) are not counted as usable images.
- Attachment posts themselves are never deleted by this task.

## Truth table applied (per event)

| Usable county | Usable image | Action |
|---|---|---|
| Yes | Yes | KEEP |
| Yes | No | KEEP |
| No | Yes | KEEP |
| No | No | DELETE |

Deletion requires BOTH missing (AND condition), per the owner's rule.
