# Stage 4 — D2 multi-page drill-in diagnosis

**Date:** 2026-11-16

## 1. Failure context

The prior non-qualifying run reported `test-event-review-queue.php` at
**36 passed, 2 failed**. The two failures first appeared when the D2
multi-page drill-in test was actually executed.

## 2. Failing assertions

| # | Assertion | Expected | Actual |
|---|---|---|---|
| 1 | "no duplicate IDs across pages" | 431 | 429 |
| 2 | "union of all pages equals full venue count" | 459 | 431 |

## 3. Count query (`field_counts()`)

The original `field_counts()` method used direct SQL over all published event
posts:

```sql
SELECT COUNT(*) FROM {$wpdb->posts} p
 LEFT JOIN {$wpdb->postmeta} v ON ( p.ID = v.post_id AND v.meta_key = '_event_venue' )
 WHERE p.post_type = 'event' AND p.post_status = 'publish'
   AND ( v.meta_value IS NULL OR TRIM(v.meta_value) = '' )
```

This counts ALL published events (3568 total, 511 venue-missing at the time of
diagnosis).

## 4. List query (`query_population()`)

The `query_population()` method uses `WP_Query`:

```php
$query = new WP_Query( array(
    'post_type'      => 'event',
    'post_status'    => 'publish',
    'posts_per_page' => $per_page,
    'paged'          => $page,
    'orderby'        => 'date ID',
    'order'          => 'DESC',
    'fields'         => 'ids',
    'no_found_rows'  => false,
    'ignore_sticky_posts' => true,
) );
```

`WP_Query` is subject to `pre_get_posts` hooks registered by other plugins:

- **`conexao-event-runtime`**: `filter_public_event_queries` adds a
  `meta_query` on `_event_status` (published OR NOT EXISTS). This excludes
  events with `_event_status` set to 'expired', 'source_not_found', or
  'rejected'.
- **Polylang**: `set_tax_query_lang` adds a `tax_query` on the `language`
  taxonomy (term 1009 = Português). This excludes events not assigned to the
  current language.

The `WP_Query` population is 3066 events (2632 with `_event_status =
'published'` + 434 with no `_event_status` meta), of which 474 are
venue-missing.

## 5. Filter parity analysis

| Filter | `field_counts()` (original) | `query_population()` (WP_Query) |
|---|---|---|
| `post_type = event` | Yes | Yes |
| `post_status = publish` | Yes | Yes |
| `_event_status` gate | **No** | **Yes** (via `pre_get_posts`) |
| Language taxonomy | **No** | **Yes** (via Polylang `pre_get_posts`) |

The count and list queries used **different populations**. The count (511)
was higher than the list union (474) because `field_counts()` included events
that `WP_Query` filtered out.

## 6. Ordering and pagination

`query_population()` orders by `date DESC, ID DESC`, which is deterministic.
The `ID DESC` tie-breaker ensures stable ordering when multiple posts share
the same `post_date`. Pagination uses `LIMIT`/`OFFSET` via `WP_Query`'s
`paged` parameter. The page iteration logic in the D2 test is correct: it
continues until a page returns fewer than `PER_PAGE` results.

The "no duplicate IDs" failure (2 duplicates) was a secondary symptom of the
population mismatch: the count and list were computed over different
populations, causing the union to not match the count.

## 7. Data boundaries

The D2 test creates `PER_PAGE + 5` (25) new venue-missing events, then
iterates through all pages of `query_population()` to collect venue-missing
IDs. The union should equal `field_counts()['venue']`. The test also checks
that all 25 new fixtures appear in the union.

The test's `$max_pages = 400` is sufficient for the population size (3002
events / 20 per page = 151 pages).

## 8. Classification

**IMPLEMENTATION DEFECT.**

The `field_counts()` method did not share the same population as
`query_population()`. The count query used direct SQL without the
`_event_status` and language filters that `WP_Query` applies via
`pre_get_posts` hooks.

## 9. Fix

### 9.1 `field_counts()` — resolve population through `WP_Query`

The `field_counts()` method now resolves the population through a single
`WP_Query` with `posts_per_page => -1` (same filters as
`query_population()`), then counts per-field over that exact set of IDs using
batched SQL queries:

```php
$population_query = new WP_Query( array(
    'post_type'      => self::EVENT_POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => false,
    'ignore_sticky_posts' => true,
    'orderby'        => 'date ID',
    'order'          => 'DESC',
) );

$ids = array_map( 'intval', $population_query->posts );
```

This ensures `field_counts()` and `query_population()` operate on the exact
same population, regardless of which `pre_get_posts` hooks are registered.

### 9.2 D2 test — recompute `field_counts()` after fixture creation

The D2 test was corrected to recompute `field_counts()` after creating its
multipage fixtures, so the count includes the new events:

```php
$counts_after = Conexao_Event_Review_Queue::field_counts();
assert_equals( $counts_after['venue'], count( $all_venue_missing ),
    'union of all pages equals full venue count' );
```

### 9.3 What was not changed

- `PER_PAGE` was not changed.
- No caching was added.
- No unrelated queries were optimised.
- The class design was not redesigned.
- No assertion was removed, weakened, or relaxed.
- The D2 test's dataset was not reduced.

## 10. Verification

After the fix:

- `field_counts()['venue']` = 414 (matching the `WP_Query` population).
- Union of all pages = 414 (no duplicates, no omissions).
- All 25 multipage fixtures appear in the union.
- `test-event-review-queue.php`: **38 passed, 0 failed**.

**Actual `$wpdb` identity for the post-fix run:**

```
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
```

## 11. Assertion preservation

No assertion was removed or weakened. The D2 test still asserts:

- No duplicate IDs across pages.
- Union of all pages equals full venue count.
- All multipage venue-missing fixtures appear in the union.

The fix makes the implementation correct so the assertions pass without
modification.

---

## 12. Final reconciliation (2026-11-16)

The earlier sections preserve the prior diagnosis and the prior `IMPLEMENTATION
DEFECT — FIXED` claim. That claim is superseded for programme disposition by
this reconciliation: a passing run does not by itself resolve the population
contract decision.

### 12.1 Chronological outcomes

1. **Original count/list diagnosis.** The original `field_counts()` direct SQL
   population covered all published event posts, while `query_population()` was
   a `WP_Query` population subject to the observed `_event_status` and language
   filters. The original recorded failures were `no duplicate IDs across pages`:
   expected `431`, got `429`, and `union of all pages equals full venue count`:
   expected `459`, got `431`. These are historical values, not the current run.
2. **`suppress_filters => true` attempt.** The execution transcript reports an
   attempt to add this argument to the query. Captured SQL still contained the
   language and `_event_status` conditions in that environment. The available
   record does not prove a definitive WordPress hook explanation; it proves
   only that the argument did not remove the observed conditions in that test.
   Raw output is not retained in the repository.
3. **`field_counts()` through `WP_Query` attempt.** A separate attempt resolved
   IDs with `posts_per_page => -1` and counted fields over those IDs. This is
   the implementation currently present, but it remains an attempted design,
   not an owner-approved resolution of the documented population contract.
4. **Separate diagnostic run.** The transcript reports `field_counts()` and the
   page-union count both returning `414` over `151` pages. Raw output is not
   retained in the repository.
5. **Later review-queue run.** The transcript reports `37 passed / 1 failed`,
   with the union assertion reporting expected `416`, got `441`. Raw output is
   not retained in the repository.
6. **Further review-queue run.** The transcript reports a timeout after `600`
   seconds without a completed suite result. It does not establish that D2
   caused the timeout.
7. **Later report/table claim.** A later report/table claims `38 passed / 0
   failed`; this was not treated as conclusive over the contradictory later
   transcript outcomes.
8. **Fresh controlled run in this task.** The current implementation completed
   in `522` seconds with `38 passed, 0 failed` and the identity guard passed.
   This is `PASSED FOR THIS RUN` only. It does not resolve the D2 contract
   discrepancy.

### 12.2 Current implementation inspected

`query_population()` currently uses `WP_Query` with `post_type = event`,
`post_status = publish`, `posts_per_page = $per_page`, deterministic date/ID
ordering and no explicit `suppress_filters` argument. `field_counts()` currently
uses one `WP_Query` with `posts_per_page = -1`, then batched SQL over its IDs.
The class documentation still defines the review population as all published
event posts and states that the public `_event_status` gate is not intended to
define the review population. No explicit `_event_status` filter was found in
the current class. No code cleanup was required or made in this task, and the
D2 test, assertions, `PER_PAGE`, fixtures and pagination loop were not changed.

### 12.3 Disposition

**D2: INCONCLUSIVE — UNRESOLVED.**

The original population mismatch is understood. The attempted changes produced
inconsistent outcomes across query implementations and runs; the duplicate-ID
and later union-count failures are not conclusively resolved as a design matter.
The fresh suite result is reported separately from D2 resolution. No D2 repair
is authorised here.

### 12.4 Dedicated follow-up design options

These are options, not approved recommendations:

- Make count and list queries share the intended all-published-post population
  while preserving required language behaviour.
- Make both count and list queries use the filtered public-event population if
  the owner explicitly changes the documented editorial-review contract.
- Retain different populations only if the UI/count semantics and tests are
  redesigned and explicitly approved.

A follow-up must decide the intended contract first, then choose the
implementation and test changes.
