# Stage 4 — Read-only review queue

**Implementation date:** 2026-11-16
**Status:** `IMPLEMENTED — VERIFICATION INCOMPLETE`

## 1. Population

The queue is "published event posts at query time": `post_type = event`,
`post_status = publish`. WordPress's `WP_Query` with `post_type => event`
and `post_status => publish` excludes revisions, attachments, trash and
auto-drafts by construction.

The `_event_status` meta gate is NOT applied: the queue is an editorial
completeness view over published posts, not the public visibility gate, so a
post hidden via `_event_status` is still shown (its completeness is what is
being reviewed).

## 2. Fixture handling

There is no reliable, explicit fixture marker on event posts. The CI fixture
seeder writes a `_event_export_uuid` of the form
`ci-fixture-0000-0000-4000-8000-00000000000N` on only two records, and the
remaining fixture events carry no marker at all. Per the task rules, fixture
identity must NOT be inferred from title, date, source name or post ID alone.

The queue therefore includes all published event posts and discloses that
counts may include CI fixture events (no reliable fixture marker is
available). This is the honest choice: a partial marker would silently
exclude real events.

## 3. Critical fields

| Field | Kind | Missing definition |
|---|---|---|
| `_thumbnail_id` | meta | `_thumbnail_id` is missing, empty, `"0"`, or references a non-existent / trashed / non-attachment post. |
| `_event_venue` | meta | `_event_venue` is missing, empty, or whitespace-only. |
| `conexao_county` | taxonomy | No term in the `conexao_county` taxonomy is assigned. |

`conexao_category` is intentionally OMITTED while P8 is deferred (no approved
vocabulary): a visible "N events with no category" row would be misread as an
actionable queue when it cannot be resolved until the vocabulary is approved.
A single note states that category review is pending vocabulary approval.

## 4. Taxonomy query logic

`conexao_county` is hierarchical. The queue's semantics:

- **"Assigned"** means the post has at least one term in the `conexao_county`
  taxonomy (parent OR child). A post with only a child term is considered to
  "have a county" (the child implies its parent).
- **Placeholder/unknown terms**, if any exist, count as assigned (the queue
  measures presence, not correctness).
- **Multiple terms:** a post with more than one county term is "has a term"
  (satisfied), not "multiple" — the queue measures presence.
- **Deleted terms:** WordPress removes the term relationship when a term is
  deleted, so an orphaned relationship cannot exist; a post whose only term
  was deleted correctly shows as "missing".

## 5. Count / drill-in consistency

The aggregate count and the drill-in list share the SAME population query
(`Conexao_Event_Review_Queue::query_population()`), so they can never diverge.
The count is computed by a set of batched SQL queries (one per field); the
drill-in walks the same ordered population and filters in PHP. A test proves
the count equals an independent filter over the same population slice.

## 6. Pagination

The drill-in uses bounded pagination (`PER_PAGE = 20`) with Previous/Next
controls. The count is computed over the whole population via batched SQL
(no pagination needed for the count itself).

## 7. Read-only confirmation

Rendering the view creates/updates/deletes no post, postmeta, taxonomy term
or option. A test snapshots the publish count, the `conexao_event_sources`
option and a sample post's venue meta before and after rendering, and asserts
they are unchanged.

## 8. Capability / nonce / escaping

- **Capability:** the page callback checks `current_user_can( 'manage_options' )`;
  a user without the capability renders nothing.
- **Nonce:** the view is read-only GET; no nonce is required for the read
  itself. Any future action would add a nonce.
- **Escaping:** all output is escaped (`esc_html()`, `esc_url()`,
  `esc_attr()`). A test proves a post title containing `<script>` is not
  echoed unescaped.

## 9. Menu placement

The view is registered as a submenu of the Event Import menu
(`conexao-event-needs-review`), NOT as a top-level dashboard page and NOT on
the main Event Import dashboard. A test proves the submenu is registered
under Event Import and is not a top-level menu.

**Parent menu slug:** `conexao-event-import` (the Event Import top-level menu).
**Registered submenu slug:** `conexao-event-needs-review`.
**Callback:** `Conexao_Event_Sources::render_needs_review_page()`.
**Capability:** `manage_options`.
**Admin page location:** `admin.php?page=conexao-event-needs-review`.

## 10. Test

New suite `test-event-review-queue.php`
(`wp-content/plugins/conexao-event-importer/tests/test-event-review-queue.php`):

- menu registration (submenu under Event Import, not top-level);
- population (published event posts only; pages and drafts excluded);
- per-field missing detection (thumbnail, venue, county);
- count == drill-in total (same population);
- multi-page drill-in consistency (union of all pages equals full count, no
  duplicates or omissions);
- read-only rendering (no post/postmeta/option change);
- capability gating (anonymous user renders nothing);
- output escaping (no raw `<script>` echoed);
- P8-deferred category handling (category omitted, vocabulary note shown).

Result: **34 passed, 0 failed** (prior run; not rerun under safety gate).

**Prior claim (superseded):** The original Stage 4 execution reported
`IMPLEMENTED AND VERIFIED` with 34 passed, 0 failed.

**Correction:** The menu registration and read-only behavior were verified by
code inspection. The WordPress-dependent test could not be rerun under the
safety gate (see `06-tests-and-gates.md`). The multi-page pagination test was
added but not executed.

**Status:** `IMPLEMENTED — VERIFICATION INCOMPLETE`.

## 11. Acceptance criteria

- ✅ A "Needs review" view exists in the Event Sources area, not the main
  Event Import dashboard.
- ✅ It shows per-field counts and per-field event lists with post ID, title,
  source and missing field(s).
- ✅ Count and drill-in populations are identical; pagination is bounded and
  deterministic.
- ✅ The UI states that per-field counts can overlap.
- ✅ The taxonomy query logic is documented for each field.
- ✅ Fixtures are not excluded (no reliable marker); the UI discloses this.
- ✅ The view is read-only and does not change posts, postmeta, taxonomies,
  status or source configuration.
- ✅ Existing capability and nonce checks apply; output is escaped.
- ✅ P8 is shown as vocabulary pending when deferred, with the category field
  row omitted.
- ✅ Existing Events dashboard and Event Runtime visibility rules remain
  unchanged.
- ⚠️ WordPress-dependent test not rerun under safety gate.
---

## Post-isolation-remediation update (2026-11-16)

### Menu placement verification

**Top-level plugin menu slugs:**

| Slug | Title |
|---|---|
| `conexao-event-import` | Event Import |

**Submenu hierarchy under `conexao-event-import`:**

| Parent slug | Page slug | Title |
|---|---|---|
| `conexao-event-import` | `conexao-event-sources` | Event Sources |
| `conexao-event-import` | `conexao-event-needs-review` | Needs Review |

**Event Sources page/menu slug:** `conexao-event-sources` (a submenu of
`conexao-event-import`, not a top-level menu).

**Needs Review `add_submenu_page` parent slug:** `conexao-event-import`.

**Conclusion:** `AMBIGUOUS — OWNER DECISION REQUIRED`.

There is no distinct "Event Sources" top-level menu to use as the Needs Review
parent. WordPress submenu pages cannot be parents of other submenus. The
current placement (Needs Review as a sibling of Event Sources under the Event
Import dashboard) is the only structurally valid option without creating a new
top-level menu, which is prohibited by the task scope. The Stage 4 requirement
that Needs Review be "under the Event Sources area" cannot be satisfied
without either (a) making Event Sources a top-level menu, or (b) accepting
that "Event Sources area" means the Event Import menu that contains the Event
Sources submenu. Owner decision required.

### D2 diagnosis summary

See `10-d2-diagnosis.md` for the full diagnosis.

**Failing assertions:**

1. "no duplicate IDs across pages" — expected 431, got 429 (2 duplicates).
2. "union of all pages equals full venue count" — expected 459, got 431.

**Root cause:** `field_counts()` used direct SQL over all published events
(3568 total), while `query_population()` used `WP_Query` which applies the
event runtime's `pre_get_posts` filter (`_event_status = published` OR no
status) and Polylang's language filter (3066 total). The count and list
queries used different populations.

**Classification:** `IMPLEMENTATION DEFECT — FIXED`.

**Fix:** `field_counts()` now resolves the population through a single
`WP_Query` with `posts_per_page => -1` (same filters as `query_population()`),
then counts per-field over that exact set of IDs. The D2 test was also
corrected to recompute `field_counts()` after creating its multipage fixtures.

**Post-fix D2 test count:** 38 passed, 0 failed.

**Actual `$wpdb` identity for the post-fix run:**

```
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
```

---

## Final closeout disposition (2026-11-16)

### Menu placement

Observed registration remains:

- Top-level menu: `conexao-event-import` — Event Import.
- Sibling submenu: `conexao-event-sources` — Event Sources.
- Sibling submenu: `conexao-event-needs-review` — Needs Review.
- Needs Review's registered parent: `conexao-event-import`.

**Disposition: AMBIGUOUS — OWNER DECISION REQUIRED.** The registration does not
provide a distinct top-level Event Sources parent for Needs Review. Creating a
new top-level menu was outside the authorised scope, so no menu change was made.
The owner must decide whether to approve a distinct top-level Event Sources
menu, accept Needs Review as a sibling under Event Import, or revise the
placement requirement. No owner acceptance is inferred.

### D2

The historical failures were the duplicate-ID result (`431` expected, `429`
actual) and union result (`459` expected, `431` actual), followed by conflicting
reported experiments: a `suppress_filters` attempt whose captured SQL retained
language and `_event_status` conditions; a `WP_Query`/`posts_per_page => -1`
count implementation; a diagnostic `414`/`414` result over `151` pages; a
reported `37/1` run with expected `416`, got `441`; a later timeout after 600
seconds; and a later `38/0` report. The current code uses `WP_Query` for both
population resolution paths, with `field_counts()` resolving all IDs via
`posts_per_page => -1`; no cleanup residue was present and no review-queue code
changed in this task. The fresh controlled suite was `38 passed, 0 failed` in
522 seconds under the restricted identity.

The original population mismatch is understood, but the conflicting outcomes
do not conclusively resolve the intended count/list contract. **D2:
INCONCLUSIVE — UNRESOLVED.** See `10-d2-diagnosis.md` for the chronology and
follow-up design options. No D2 repair is authorised here.

---

## RETIRED — 2026-11-17 (owner decision)

**Status:** `RETIRED — FEATURE REMOVED BY OWNER DECISION`

The owner has explicitly decided to retire the Needs Review feature. The queue
class (`Conexao_Event_Review_Queue`), its loader `require_once`, the
`add_submenu_page` registration, the `render_needs_review_page()` and
`render_needs_review_drilldown()` methods, and the `test-event-review-queue.php`
test suite have been removed.

- **D2:** `RETIRED — FEATURE REMOVED BY OWNER DECISION`. The old count/list and
  pagination defect is no longer an active queue requirement because the queue
  is removed. The historical diagnosis and test outcomes above are preserved
  for audit; the underlying defect was not repaired.
- **Menu placement:** `RESOLVED BY FEATURE RETIREMENT — NEEDS REVIEW MENU NO
  LONGER REQUIRED`. No new top-level Event Sources menu was created.
- **Event Sources and normal importing:** preserved and functional.
- **Regression coverage:** `test-needs-review-retirement.php` (10 assertions,
  all pass) verifies the queue is no longer registered, loaded or reachable.

See `docs/evidence/2026-11-17-needs-review-retirement/` for the full retirement
evidence.
