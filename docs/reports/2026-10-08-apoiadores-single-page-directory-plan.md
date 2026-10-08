# Plan — /apoiadores/ renders every Apoiador on one page (no pagination)

| | |
|---|---|
| **Task title** | Remove pagination from the Apoiadores directory: `/apoiadores/` renders the whole set on one page |
| **Date** | 2026-10-08 |
| **Author / agent** | Cline (agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `47d3f2a1843d539204e59f30b52dbb39e76c0e66` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-security-review` |
| **Plan status** | approved (inline; this task is a single-behaviour change) |

## 1. Scope

Make the Apoiadores directory (`/apoiadores/`, the `sponsor` post-type archive, and
its English view `/en/apoiadores/`) a **single page**: the main query for the
archive stops slicing the editor-curated set, so every published Apoiador is
rendered on page 1 and no pagination markup is generated. Ordering
(`_sponsor_display_order` ascending, then unordered newest first), card markup,
links, images, content, taxonomy and Polylang relationships are untouched.

The change is confined to the Apoiadores listing:

1. the `sponsor` branch of `conexao_content_archive_query()` (the archive's own
   `pre_get_posts` layer) gains the WordPress "show all" configuration
   (`posts_per_page = -1` + `nopaging = true`) for non-feed requests;
2. a small guard keeps an out-of-range paged URL (`/apoiadores/page/2/`) from
   becoming a *new* duplicate 200 URL — it keeps the 404 it has today.

## 2. Explicit non-goals

- **Not** changing the shared pagination component
  (`template-parts/pagination.php`), `paginate_links()` usage anywhere else, the
  shared infinite-scroll / load-more JS contract, or any other archive
  (Guias, Eventos, Cursos, Lazer, Blog, Empregos, search).
- **Not** changing global `posts_per_page`, WordPress reading settings, or the
  feeds' `posts_per_rss` contract.
- **Not** redesigning the Apoiadores page, card UI, grid CSS, filters, hero
  carousel, sort controls or empty state.
- **Not** touching Apoiador content, `_sponsor_display_order` values, taxonomy,
  slugs, URLs, translations or Polylang configuration; no EN content is created.
- **Not** introducing JS/AJAX/lazy loading, a new frontend framework, a new
  helper engine, or an arbitrary magic page size (`9999`).
- **Not** touching production, and not touching the Flutter/mobile repository.

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Scope / safety rules / what must not be touched | `AGENTS.md` |
| Engineering rules (§0, §8, §9, §12, §14) | `docs/engineering-standard.md` |
| `sponsor` CPT, `_sponsor_display_order`, ordering policy | `docs/content-model.md` |
| `/apoiadores/` + `/en/apoiadores/` routes | `docs/routing.md` |
| Theme internals: archive template, Apoiadores directory section, pagination component | `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md` |
| Test layers, matrix schema, permanent gates | `docs/testing.md`, `tests/acceptance/lib/matrix.py`, `tests/lib/permanent-gates.php` |
| Plugin registry (unchanged by this task) | `plugins.json` |

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (orientation) → `wp-frontend-perf` (query/template layer) → `wp-write-in-process-test` + `wp-http-acceptance-matrix` (regression proof) → `wp-update-docs` |
| **Its preconditions** | clean tree at the recorded SHA; local Docker stack up; baseline numbers captured before editing |
| **Its guardrails that bind this task** | smallest change at the correct layer; reuse the existing acceptance matrix and in-process bootstrap; no second testing mechanism; no production write; no PT/Polylang change |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/run-tests.sh --scripts`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check`, `python3 tests/scripts/verify-documentation-drift.py` |
| **Its evidence location** | `docs/evidence/2026-10-08-apoiadores-single-page/` |

## 4. Current-state findings (measured, not assumed)

- `/apoiadores/` is the **`sponsor` post-type archive** rendered by `archive.php`
  (`is_post_type_archive( 'sponsor' )`), not a custom template and not a static
  page.
- The listing is the **main `WP_Query`**, shaped by
  `conexao_content_archive_query()` (`inc/archive-query.php`, `pre_get_posts`
  priority 20): the sponsor branch sets `post__in` (the ordered ID list from
  `conexao_sponsor_archive_ordered_ids()`) + `orderby => post__in`,
  `order => ASC`. It does **not** set a page size.
- The page size therefore falls through to the WordPress option
  `posts_per_page` = **10**
  (`docker compose exec wordpress php -r … get_option('posts_per_page')`).
- Pagination markup comes from `archive.php` line 201:
  `get_template_part( 'template-parts/pagination' )` → `paginate_links()` on the
  main query. It renders only when the main query has > 1 page.
- Measured baseline on the local stack (HTTP, `http://localhost:8080`):

| Metric | Baseline |
|---|---|
| Published `sponsor` records | **5** (4 PT: 3 with `_sponsor_display_order`, 1 without; 1 linked EN translation) |
| PT archive set (`conexao_sponsor_archive_ordered_ids()`) | **4** |
| `/apoiadores/` rendered cards | **4** (status 200, 49 955 bytes) |
| `/en/apoiadores/` rendered cards | **4** (status 200, 49 880 bytes) |
| Pagination controls on `/apoiadores/` | **0** — but only because 4 < 10 |
| `max_num_pages` with the default page size | 1; with a page size of 3 → 2 (proven in the probe) |
| `/apoiadores/page/2/` | **404** (no content on page 2 today) |
| `/apoiadores/feed/` | 200, 4 `<item>`s (`posts_per_rss` contract) |

**Root cause.** The Apoiadores listing is a *paginated main query* with the site
default page size. Today the fitted set (4) is smaller than the page size (10), so
the pagination component happens to render nothing — the pagination is **latent,
not absent**: the 5th Apoiador (the next record an editor publishes) makes
`max_num_pages` 2 and immediately introduces "Próximo → / Anterior" plus numbered
links and hides the rest behind `/apoiadores/page/2/`. There is no
Apoiadores-specific page-size configuration anywhere, and no shared "show all"
query helper in the theme.

## 5. Proposed approach

**Chosen option — set the WordPress "show all" configuration on the archive's own
query, in the sponsor branch of `conexao_content_archive_query()`:**

```php
if ( ! $query->is_feed() ) {
	$query->set( 'posts_per_page', -1 ); // WordPress' documented "all records"
	$query->set( 'nopaging', true );     // …and no LIMIT at all
}
```

Why this layer and this mechanism:

- The Apoiadores listing **is** the main query for that archive, so its
  `pre_get_posts` branch is the correct layer: no template rewrite, no second
  query, no duplicated ordering logic, and the existing ordering
  (`post__in` + `orderby => post__in`) is preserved byte-for-byte.
- `-1` is the WordPress-supported unlimited result mechanism (`nopaging`), not an
  arbitrary large number. It is also the convention already used in this theme
  (`conexao_sponsor_archive_ordered_ids()`, `inc/queries.php`,
  `inc/i18n/fallback.php`, the sitemap). **Both keys are required**:
  `parse_query()` computes `nopaging` *before* `pre_get_posts` fires, so setting
  only `posts_per_page = -1` would leave the paging block active and build
  `LIMIT 0, -1` (invalid SQL). This was verified against
  `wp-includes/class-wp-query.php` (lines 2013–2045, 2810–2823) and by probe.
- With `nopaging`, `max_num_pages` is not recomputed (stays `0`), so the shared
  pagination component (`count( $links ) < 2`) returns early: **zero pagination
  markup**. The load-more / infinite-scroll enhancements are not attached to this
  grid in any case (`archive.php` tags only Blog/Guias/Lazer and
  Eventos/Cursos).
- The guard for paged URLs keeps `/apoiadores/page/N/` (N > 1) a 404 (its current
  status) instead of letting the unlimited query turn it into a new duplicate 200
  URL. It is a two-line `template_redirect` guard using core's own pattern
  (`set_404()` + `status_header( 404 )` + `nocache_headers()`), documented inline.
- The feed is excluded from the unlimited configuration: `/apoiadores/feed/` is
  not the archive listing and keeps the `posts_per_rss` contract.

**Options rejected**

| Option | Why rejected |
|---|---|
| Raise `posts_per_page` / `posts_per_archive_page` to a big number | An arbitrary limit is exactly what the task forbids; it also stays a paginating query. |
| Set `posts_per_page` to `count( $sponsor_ids )` | Derives a page size from data to *simulate* "all"; fragile if the set changes between the ID list and the query, and not the WordPress-supported unlimited mechanism. |
| Remove/hide the pagination part for the sponsor archive in `archive.php` | Hides the symptom and silently drops Apoiadores from view on page 2+; also touches the shared template. |
| A separate custom `WP_Query` in the template | Duplicates the archive query + ordering logic and adds queries; the main query already is the listing. |
| Global pagination change | Out of scope: it would alter every unrelated archive. |
| Add JS/AJAX "load all" | Adds runtime cost and a second mechanism for no gain. |

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` | sponsor branch: unlimited page size + `nopaging` (non-feed); new paged-view guard | The Apoiadores listing query + the single-page contract |
| `wp-content/themes/conexao-br-irlanda/inc/sponsors.php` | docblock wording only (`every /apoiadores page` → single page) | Keep the ordering helper's comment truthful; no logic change |
| `wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php` | **new** in-process regression suite | Proves rendered == available, no pagination, ordering preserved, negative control |
| `tests/acceptance/matrices/routing.json` | new `/apoiadores/` + `/apoiadores/page/2/` rows | Extends the existing HTTP acceptance matrix for `/apoiadores/` (no second testing mechanism) |
| `tests/acceptance/verify-routing-http.py` | one dynamic card-count/render check for `/apoiadores/` | "rendered == available" at the HTTP layer using the existing suite |
| `docs/themes/conexao-br-irlanda.md`, `docs/content-model.md`, `docs/routing.md`, `docs/frontend.md` | prose + `_Last verified_` markers | Documentation stays the single source of truth |
| `docs/reports/2026-10-08-apoiadores-single-page-directory*.md`, `docs/evidence/2026-10-08-apoiadores-single-page/*` | plan + report + machine evidence | §9 / §13.2 |

## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/template-parts/pagination.php` — the
  shared component is reused unchanged (it already renders nothing when the query
  has one page).
- `wp-content/themes/conexao-br-irlanda/archive.php` — same template renders the
  same card markup; `single-sponsor.php`, `featured-sponsors.php`,
  `template-parts/*`, `assets/css/*`, `assets/js/*` — untouched.
- Every other archive branch of `inc/archive-query.php` (event, course, guide,
  leisure, job, blog) — unrelated pagination must not change.
- `plugins.json`, `compose.yaml`, `scripts/**`, `wp-content/plugins/**`,
  `.github/**` — no plugin, script, build or registry change.
- No `.env`, no credential, no production asset.

## 8. Content / data impact

- Records created / updated / deleted: **none** (the only writes are the temporary
  sponsors a test creates and deletes inside its own run, following the existing
  `test-sponsor-archive-ordering.php` pattern).
- Portable identifier strategy: not applicable (no content write).
- Does this write content? **No** → the six-step contract does not apply.
- PT content changed? **No.**

## 9. Route / HTTP impact

- Routes added/removed: **none**. `/apoiadores/` and `/en/apoiadores/` keep
  status 200; `/apoiadores/page/2/` keeps its pre-change **404** (guard).
- Redirects needed: **none** (no new redirect rule; the redirect-precedence gate
  is untouched).
- Sitemap coverage: unchanged (`/apoiadores/` in, no `/page/N/` entry ever).
- HTTP matrix rows to add: `/apoiadores/` no-pagination row,
  `/apoiadores/page/2/` 404 row, `/en/apoiadores/` no-pagination row.

## 10. Polylang / English impact

- Translated post types affected: `sponsor` (already translated by Polylang) —
  **no configuration change**.
- Is PT immutable in this change? **Yes.**
- B1/B2: unchanged (`sponsor` stays B2; the EN archive keeps its EN + B2 PT set).
- Canonical/hreflang/language switcher: unchanged (already asserted by the
  existing matrix rows).
- Language-scoped cache keys: unchanged (no caching added).
- Completeness gate: unchanged (`sponsor` is a B2 type, exempt by documented
  policy).

## 11. Security impact

- Capabilities: none (front-end read-only query configuration).
- Nonces: none. Sanitisation/escaping: none added (no new input, no new output).
- SQL: WP_Query APIs only; no raw SQL, no interpolation.
- REST surface: unchanged. Secrets: none.

## 12. Performance impact

- Queries: **one fewer** per `/apoiadores/` request — with `nopaging` the
  `SELECT FOUND_ROWS()` probe is skipped (`set_found_posts()` only runs it when a
  `LIMIT` exists); page 1 now fetches the whole set in the same single query.
- No new query, no JS, no AJAX, no lazy fetch, no extra API call, no new
  transient.
- Measured where practical: query count, wall time, HTML bytes, rendered cards and
  image count before/after (documented in the report). Reverting to a limit later
  is explicitly **not** a silent performance reaction; a limit would need its own
  measured justification.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | nobody — out of scope |
| What is the verification of the production result? | not applicable; production is untouched |

## 14. Test plan

- **In-process suite (new)**:
  `wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php`.
  Creates enough temporary sponsors to exceed the site page size, drives the
  **real** `pre_get_posts` path (main-query swap, as
  `test-leisure-multiselect-filters.php` does) and asserts:
  (1) the archive query returns every available Apoiador (no missing ID, no
  duplicate); (2) `paginate_links()` output is empty — *pagination is not
  generated*; (3) the rendered order equals
  `conexao_sponsor_archive_ordered_ids()`; (4) the same query **with a page size
  reintroduced** renders fewer records and *does* produce pagination links
  (negative control — the suite fails if pagination silently returns);
  (5) another archive (leisure) keeps its page size and still paginates (no
  unrelated archive pagination change). Cleans up its fixtures.
- **Acceptance** (extend, not duplicate): new rows in
  `tests/acceptance/matrices/routing.json` plus one dynamic render check in
  `tests/acceptance/verify-routing-http.py`.
- Script contract / registry drift: no change expected (0 writes,
  `generate-registry-docs.php --check`).
- Pass numerically: 0 failing in-process suites relative to baseline, 0 failing
  acceptance assertions, all permanent gates green.

## 15. Acceptance matrix plan

| id | url | status | contains | absent |
|---|---|---|---|---|
| `pt-apoiadores-single-page-no-pagination` | `/apoiadores/` | 200 | `archive-card`, an Apoiador permalink | `conexao-pagination`, `page-numbers`, `Próximo`, `Anterior`, `data-load-more` |
| `pt-apoiadores-page-2-is-not-a-view` | `/apoiadores/page/2/` | 404 | — | an Apoiador permalink |
| `en-apoiadores-single-page-no-pagination` | `/en/apoiadores/` | 200 | `archive-card` | `conexao-pagination`, `page-numbers` |

Plus the dynamic check in `verify-routing-http.py`: the number of rendered
Apoiador cards on `/apoiadores/` equals the number of distinct Apoiador
permalinks on that page, is > 0, and no `/apoiadores/page/` URL is linked.

## 16. Rollback plan

Revert the theme commit (`git revert <sha>`, or restore
`inc/archive-query.php` + `inc/sponsors.php` from the start SHA): the archive
returns to the default page size and latent pagination. The test suite and matrix
rows added here are the regression proof and must be reverted with it (they would
fail against the reverted behaviour). Rollback does **not** cover anything else:
no content, database, Polylang or plugin state is touched by this change
(pre-change counts captured in §4: 5 published sponsors / 4 rendered per view).

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/themes/conexao-br-irlanda.md` | Apoiadores directory section + helper-table row: single-page directory, no pagination, paged URLs 404 | — |
| `docs/content-model.md` | `_sponsor_display_order` paragraph: the ordering now renders on one page | — |
| `docs/frontend.md` | Archive pagination note: Apoiadores renders no pagination | — |
| `docs/routing.md` | Archive route note: `/apoiadores/` is single-page, `/apoiadores/page/N/` 404 | — |
| `docs/reports/2026-10-08-apoiadores-single-page-directory.md` | report with the measured numbers | — |

## 18. Release implications

- Component version bump: **not applicable, no release in scope** — the theme
  version (`CONEXAO_THEME_VERSION` 1.0.1) is bumped by the release process, not
  per behaviour change.
- Artifact allowlist: unchanged (derived from `plugins.json`, untouched).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Setting only `posts_per_page = -1` (without `nopaging`) builds invalid SQL `LIMIT 0, -1` | Medium (easy to "simplify" later) | Both keys set together, inline comment states why; the in-process suite asserts the real query returns the full set. |
| Paged URLs become new duplicate 200 pages | Medium without the guard | Explicit `template_redirect` guard keeping 404; matrix row asserts it. |
| The feed silently inherits the unlimited page size | Low | `! $query->is_feed()` guard; feeds keep `posts_per_rss`. |
| Ancillary scope creep (redesigning cards, adding filters, "improving" other archives) | Medium | §2 non-goals; the diff is limited to §6. |
| A large future Apoiador set makes the page heavy | Low today (4–5 records) | Measured payload/time documented in the report; no artificial limit introduced. |
| Test fixtures (temporary sponsors) leaking into the dataset | Low | Create/delete inside the suite like `test-sponsor-archive-ordering.php`; verified with counts after the run. |

## 20. Verification gates

1. `./scripts/run-tests.sh` — one aggregate exit code (in-process + script
   contract + HTTP acceptance + permanent gates).
2. `./scripts/run-tests.sh --scripts` — script-contract layer alone.
3. `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan.
4. `php scripts/generate-registry-docs.php --check` — 0 writes, no registry drift.
5. `python3 tests/scripts/verify-documentation-drift.py` — §9.2 rules.
6. `curl` measurements on `/apoiadores/`, `/en/apoiadores/`,
   `/apoiadores/page/2/`, `/apoiadores/feed/` (desktop + mobile payload).

## 21. Completion criteria

Engineering standard §14, applied: the change is complete when every acceptance
criterion in the task statement holds with real numbers (rendered == total,
pagination pages 0, pagination controls 0, missing 0, ordering identical),
`./scripts/run-tests.sh` is green relative to the recorded baseline, static
analysis and the permanent gates pass, and the report + evidence carry the
measured values and any limitation.

_Last verified: 2026-10-08 by the Apoiadores single-page directory change_
