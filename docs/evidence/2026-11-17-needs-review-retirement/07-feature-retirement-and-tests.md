# Stage 7 — Feature retirement and tests

**Date:** 2026-11-18

## 6. Needs Review feature retirement

### 6.1 Inventory of references (before removal)

| Reference | Type | Action |
|---|---|---|
| `includes/class-conexao-event-review-queue.php` | Implementation (queue class) | DELETED |
| `require_once ... class-conexao-event-review-queue.php` in `conexao-event-importer.php` | Loader wiring | REMOVED |
| `add_submenu_page( 'conexao-event-needs-review', ... )` in `class-event-sources.php` | Menu registration | REMOVED |
| `render_needs_review_page()` in `class-event-sources.php` | Page renderer | REMOVED |
| `render_needs_review_drilldown()` in `class-event-sources.php` | Drill-down renderer | REMOVED |
| `tests/test-event-review-queue.php` | Test (solely for retired queue) | DELETED |
| `tests/test-needs-review-retirement.php` | New regression test | CREATED |
| Stage 4 report references to `test-event-review-queue.php` | Historical documentation | PRESERVED (historical) |

### 6.2 Files removed

| File | Action |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-conexao-event-review-queue.php` | Deleted (349 lines) |
| `wp-content/plugins/conexao-event-importer/tests/test-event-review-queue.php` | Deleted (335 lines) |

### 6.3 Files modified

| File | Change |
|---|---|
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | Removed `require_once` for `class-conexao-event-review-queue.php` (2 lines) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | Removed `add_submenu_page` for Needs Review (10 lines); removed `render_needs_review_page()` and `render_needs_review_drilldown()` methods (203 lines) |

### 6.4 New test

| File | Purpose |
|---|---|
| `wp-content/plugins/conexao-event-importer/tests/test-needs-review-retirement.php` | Verifies: (A) queue class not loaded, (B) Needs Review submenu absent, Event Sources present, (C) queue renderers removed, (D) no admin_menu callback renders Needs Review |

### 6.5 Post-removal verification

| Check | Result |
|---|---|
| `Conexao_Event_Review_Queue` class exists | NO (correct) |
| `conexao-event-needs-review` submenu registered | NO (correct) |
| `render_needs_review_page()` method exists | NO (correct) |
| `render_needs_review_drilldown()` method exists | NO (correct) |
| Needs Review admin_menu callback | NONE (correct) |
| Event Import top-level menu | PRESENT |
| Event Sources submenu | PRESENT |
| `Conexao_Event_Sources` class | PRESENT |
| `render_sources_page()` method | PRESENT |
| PHP syntax errors | NONE |
| Remaining executable Needs Review references | NONE |

### 6.6 D2 and menu evidence disposition

- **D2:** `RETIRED — FEATURE REMOVED BY OWNER DECISION`. The old count/list and pagination defect is no longer an active queue requirement because the queue is removed. Historical diagnosis and test outcomes preserved in Stage 4 reports.
- **Menu placement:** `RESOLVED BY FEATURE RETIREMENT — NEEDS REVIEW MENU NO LONGER REQUIRED`. No new top-level Event Sources menu was created.

## 12. Tests and quality gates

### 12.1 Static checks

| Check | Result |
|---|---|
| `php -l` on `conexao-event-importer.php` | No syntax errors |
| `php -l` on `class-event-sources.php` | No syntax errors |
| `php -l` on `test-needs-review-retirement.php` | No syntax errors |
| Executable Needs Review/queue references | 0 |

### 12.2 Automated tests

**Suite:** `plugin/conexao-event-importer` (45 suites total)

**Test database:** `conexao_stage05_test_5bd9ece5c032` (disposable test schema, NOT the local development database)

**$wpdb identity:** `conexao_test@172.18.0.3`, database `conexao_stage05_test_5bd9ece5c032`

| Suite | Result |
|---|---|
| `test-needs-review-retirement.php` | **10 passed, 0 failed** (NEW) |
| `test-import-continuation.php` | 229.79s — pre-existing failure (unrelated to Needs Review) |
| `test-town-sanitization.php` | 82 passed, 1 failed — pre-existing failure (unrelated to Needs Review) |
| All other 42 suites | PASS |

**Overall:** 45 suites, 43 passed, 2 failed (both pre-existing, unrelated to this change).

**Pre-existing failures verified on base commit:** The 2 failures (`test-import-continuation.php`, `test-town-sanitization.php`) also fail on the base commit `19238f9` without this task's changes. This task does not introduce any new test failures.

### 12.3 Manual local smoke checks

| Check | Result |
|---|---|
| Event Import admin page loads | YES (admin_menu hooks present, class loaded) |
| Event Sources admin page loads | YES (`render_sources_page()` exists, submenu registered) |
| Needs Review menu item absent | YES |
| Location-based event query | 2941 events with usable county |
| No importer/provider action triggered | Confirmed (read-only queries only) |