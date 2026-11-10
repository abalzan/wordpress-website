# CI run 89 — root cause and fixes

Four integration suites failed on `1716d432` (CI run 42694694101). They looked
independent. Three of them were one defect: a test that left the process in the
`en` Polylang context.

| Failure | Actual cause | Owning component | Correction |
|---|---|---|---|
| Christmas welcome: `_ce_presentation` / `_ce_date` read back empty | The suite located the canonical record with `get_post_meta(..., '_ce_rule')`, but `_ce_rule` is a **registered** meta key with the default `fixed_annual`. The identity-only EN record therefore reads back non-empty and can be selected; its `_ce_presentation` and `_ce_date` then read the registered `''` defaults. Production was correct all along. | the suite's canonical-record lookup | Ask the storage layer: `metadata_exists()` instead of a non-empty `get_post_meta()` |
| `ce-module--agenda` missing on `/natal/` and `/en/christmas/` | Not a renderer defect. The `agenda` module comes from `Conexao_Event_Query::upcoming_events()` filtered by an editorial keyword list (`christmas`, `natal`, …). The synthetic site had **no upcoming event whose title matched**, so the module legitimately rendered nothing and the page still met the ≥3-module rule. | the CI fixture population (not the renderer) | Add one upcoming Christmas fixture (`festa-natal-dublin-2026` + EN sibling) with a new `event_offset` field so the EVENT is in the future while `post_date` stays today (a future `post_date` is derived to `post_status = future`) |
| Event-filter cache: wrong results in 5 assertions | Three separate fixture defects, all "tests assuming an empty database": (a) the empty-result test deleted events to force emptiness — impossible and destructive on a populated site; (b) the rename test created a county with **no event**, so it could never appear in an event-derived filter list; (c) the town-scoping test created terms only, and passed a **slug** to `wp_set_object_terms()`, which expects an object ID. | the suite's fixtures | (a) exercise emptiness on a scoped query that provably matches nothing, and assert the empty array is CACHED; (b)(c) attach the terms to controlled events; add `ev_cleanup()` |
| `conexao_category has no per-language suffixed terms` (only under the full run, not in isolation) | `ev_test_language_isolation()` set `PLL()->curlang = en` and never restored it. Every later test in that process ran in the EN context, so the category term created by `ev_test_unrelated_cache_preservation()` was translated by Polylang into an `ev-uncat-pt` duplicate — a genuine taxonomy-policy violation, correctly reported by `test-polylang-foundation.php`. | the suite's language handling | Save the original context and restore it (a real language object, never `NULL`, so the previous null-language warning stays fixed) |

## Which failures shared a cause

The agenda failure and the three filter-cache symptoms share one root cause:
**the fixture tree never supplied the state the assertions required, and the
suites compensated by assuming an empty database.** The Polylang failure shares
that cause through suite order: a leaked `en` context turned a later fixture
into a per-language duplicate.

## Changes

- `scripts/data/ci-fixture-events.php` — one upcoming Christmas event pair.
- `scripts/bootstrap-ci-fixtures.php` — `event_offset` support.
- `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php` — the
  placeholder-slug exclusion now filters the LIST once, so the mobile sheet
  (which iterated the unfiltered array) and the desktop dropdown agree.
- `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php`
- `wp-content/themes/conexao-br-irlanda/tests/test-event-filter-cache.php`

No production code was changed to satisfy an assertion. Cache behaviour,
invalidation, Polylang filtering and the shared-taxonomy policy are unchanged.