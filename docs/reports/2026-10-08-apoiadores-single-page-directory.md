# Report — /apoiadores/ becomes a single-page Apoiadores directory

| | |
|---|---|
| **Stage / task name** | Apoiadores single-page directory — remove pagination from `/apoiadores/` |
| **Date** | 2026-10-08 |
| **Author / agent** | Cline (agent) |
| **Branch** | `master` |
| **Start SHA** | `47d3f2a1843d539204e59f30b52dbb39e76c0e66` |
| **Final SHA** | uncommitted (see §3 — the working tree also carries another session's changes) |
| **Working tree at finish** | dirty — this change (`inc/archive-query.php`, `inc/sponsors.php`, the new suite, the matrix rows, docs) **plus** files owned by a concurrent session in the same workspace: `wp-content/themes/conexao-br-irlanda/assets/css/main.css` (hero carousel dots), untracked `tests/browser/verify-sponsor-carousel-mobile-browser.py` and `carousel-work/`. None of those are part of this change. |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-security-review` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md`, `docs/testing.md`, `plugins.json` |

## 1. Scope completed

The Apoiadores directory (`/apoiadores/`, the `sponsor` post-type archive, plus its
English view `/en/apoiadores/`) is now a **single page**:

- the `sponsor` branch of `conexao_content_archive_query()`
  (`wp-content/themes/conexao-br-irlanda/inc/archive-query.php`, `pre_get_posts`
  priority 20) now sets WordPress' unlimited-result configuration on the archive's
  own main query (`posts_per_page = -1` **and** `nopaging = true`) for every
  non-feed request, so the whole editor-curated set renders at once;
- the ordering is untouched (`post__in` from
  `conexao_sponsor_archive_ordered_ids()` + `orderby => post__in`, `ASC`);
- no pagination markup can be produced: with no `LIMIT`, `max_num_pages` stays `0`
  and the shared `template-parts/pagination.php` component early-returns, so there
  is no Next/Previous, numbered control or load-more/infinite-scroll hook;
- `/apoiadores/page/N/` (N > 1) keeps the **404** it already had (a new, scoped
  `template_redirect` guard, `conexao_sponsor_archive_no_paged_view()`), so the
  unlimited query cannot turn a paged URL into a new duplicate 200 page;
- `/apoiadores/feed/` keeps the WordPress `posts_per_rss` contract (a feed is not
  the archive listing and is explicitly excluded);
- nothing else changed: card markup, CSS, links, images, taxonomy, content,
  Polylang configuration, canonical/hreflang and every other archive are
  byte-identical (§9 — the PT/EN HTML diff is empty apart from an unrelated
  cache-buster owned by the concurrent session).

Regression proof shipped in three layers (two blocking, one opt-in):

| Layer | File |
|---|---|
| In-process PHP (blocking) | `wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php` |
| HTTP acceptance (blocking) | `/apoiadores/` and `/apoiadores/page/2/` rows in `tests/acceptance/matrices/routing.json` plus the dynamic `check_apoiaadores_single_page()` in `tests/acceptance/verify-routing-http.py` |
| Browser (opt-in, documented) | `tests/browser/verify-sponsors-archive-browser.py` |

## 2. Scope NOT completed

- **No production verification and no production change.** WordPress.com has no
  SSH/WP-CLI, and the task forbids production writes. Everything here is local.
- **No release artifact.** The task asked for a behaviour change, not a release; no
  version bump and no ZIP were produced.
- **The concurrent session's changes were not touched, reviewed or reverted** — they
  are not part of this task (§3, §13).
- **Card images could not be exercised with real Apoiador artwork**: every local
  synthetic Apoiador fixture has no image, so the archive cards render no `<img>`
  (the template's documented no-image fallback). The image assertions therefore
  cover the images the page really renders (theme logos): all loaded, all with alt
  text. Recorded in §13.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` | modified | Sponsor branch: `posts_per_page = -1` + `nopaging = true` for non-feed requests; new scoped `conexao_sponsor_archive_no_paged_view()` guard (`template_redirect`) |
| `wp-content/themes/conexao-br-irlanda/inc/sponsors.php` | modified (docblock only) | The ordering helper's comment no longer claims a page-local slice; no logic change |
| `wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php` | **added** | In-process regression suite (28 assertions) with its own negative control |
| `tests/acceptance/matrices/routing.json` | modified | 4 new rows: PT single-page no-pagination, PT page-2 404, EN single-page no-pagination, EN page-2 404 |
| `tests/acceptance/verify-routing-http.py` | modified | Dynamic `check_apoiaadores_single_page()`: rendered set == REST set per language, one render each, no pagination, links resolve, page 2 is 404 |
| `tests/browser/verify-sponsors-archive-browser.py` | **added** | Opt-in browser acceptance at 1440×1000 / 390×844 with a DOM-injecting negative control |
| `docs/themes/conexao-br-irlanda.md`, `docs/content-model.md`, `docs/frontend.md`, `docs/routing.md`, `docs/testing.md` | modified | Single-page contract documented; `_Last verified_` markers refreshed |
| `docs/reports/2026-10-08-apoiadores-single-page-directory-plan.md`, `docs/reports/2026-10-08-apoiadores-single-page-directory.md`, `docs/evidence/2026-10-08-apoiadores-single-page/*` | **added** | Plan, this report, machine evidence |
| `docker/mu-plugins/zz-probe-apoiadores.php` | added **temporarily, then removed** | Read-only measurement probe (query counts/bytes). Deleted before the final verification run; never shipped |

Counts: **5 added** (suite, browser suite, plan, report, evidence directory), **8
modified**, **0 deleted** — of which the shipped behaviour change is exactly **two
theme files** (`inc/archive-query.php` carrying the logic, `inc/sponsors.php` a
comment).

Explicitly **not** touched: `template-parts/pagination.php`, `archive.php`,
`single-sponsor.php`, `featured-sponsors.php`, any `assets/css/*` or `assets/js/*`
(in particular **not** `main.css`, which the concurrent session is editing),
`plugins.json`, `compose.yaml`, `scripts/**`, `wp-content/plugins/**`,
`.github/**`.

## 4. Runtime impact

WordPress behaves differently **only** for the `sponsor` post-type archive:

| Context | Before | After |
|---|---|---|
| `/apoiadores/` (page 1) | main query page size = 10, `max_num_pages` 1 (the 4 fitted records happened to fit) | page size `-1` + `nopaging`, `max_num_pages` 0, every record on one page |
| `/en/apoiadores/` | same | same |
| `/apoiadores/page/2/` | 404 (page 2 empty) | 404 (explicit guard) |
| `/apoiadores/feed/` | `posts_per_rss` (10) | unchanged (`is_feed()` excluded) |
| Every other archive | unchanged | unchanged (asserted in-process and by the acceptance matrix) |

Rendered output for the current dataset is byte-identical (§9: the only diff line is
the `main.css?ver=` cache-buster changed by the concurrent session's CSS edit).

The change is **not** behaviour-neutral, so it is not a behaviour-preserving
refactor: the diff is exactly one behaviour change plus one comment-only edit.

## 4a. Scope integrity

Not a documentation-only change, so the §4a checklist is answered in the runtime
terms of this task:

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** — local Docker only (§8) |
| No production content, Polylang or database change | **confirmed** — no content write of any kind (§5, §6) |
| Application behaviour change documented | **yes** — the single-page directory itself (§4); nothing else changed |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |

## 5. Content / data impact

- Records created / updated / deleted by the change: **none**. No `wp_insert_post`,
  no meta write, no term change, no migration, no import.
- The only records this task ever creates are the **temporary sponsors inside its
  own test suite** (`[SINGLE PAGE TEST] Apoiador 1…8`), created by the suite and
  deleted by the same suite (`wp_delete_post( …, true )`), exactly like the
  existing `test-sponsor-archive-ordering.php`. Verified after the run: the
  published sponsor count returns to its pre-suite value (§9).
- Portable identifier strategy: not applicable (no content write).
- PT content changed? **No.**

## 6. Polylang impact

- `sponsor` keeps its existing Polylang configuration (it is a **B2** post type);
  no language, relationship, translation or `pll_*` setting was touched.
- PT immutability: PT URLs, slugs, titles, bodies, meta and record identity are
  untouched — the change shapes a **query**, it does not rename or re-link
  anything. The in-process suite asserts the PT archive set and order are exactly
  `conexao_sponsor_archive_ordered_ids()`.
- Canonical / hreflang / switcher: unchanged (self-canonical PT and EN archive
  URLs; the matrix keeps asserting them).
- Language-scoped cache keys: no new transient, no cache write.
- Completeness gate: unchanged. `sponsor` is a B2 type and exempt by documented
  policy; `test-translation-completeness.php` passes (23/0, §9).

## 7. Route / HTTP impact

- No route added or removed. No redirect rule added (the legacy redirect table and
  its precedence are untouched — `test-redirect-precedence.php` passes).
- `/apoiadores/` and `/en/apoiadores/` → **200**, single page, no pagination.
- `/apoiadores/page/N/` (N > 1) and `/en/apoiadores/page/2/` → **404** (the status
  they already had), and `?paged=2` on the archive likewise.
- `/apoiadores/feed/` → **200** with the `posts_per_rss` item count (unchanged).
- Sitemap coverage unchanged (`/apoiadores/` stays in; `/page/N/` was never in it).
- HTTP matrix rows added: 4 (§3). The dynamic checker adds the data assertion.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | not attempted |
| Deploy / upload | **no** | no artifact built |
| Plugin activation | **no** | no plugin change |
| Content or DB mutation | **no** | no content write of any kind |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database), so the
only production follow-up is a future deployment of the theme by the maintainer —
outside this task, and stated as a limitation in §13.

## 9. Verification commands and results

Every command below was executed on the local stack (`http://localhost:8080`,
PHP 8.5.4 host / 8.3.35 container, MySQL in Docker). Numbers are the real output.

### The Apoiadores count, before and after

| Metric | Before | After |
|---|---|---|
| Published `sponsor` records | 5 (4 PT + 1 linked EN translation) | **5** (unchanged) |
| PT archive set (`conexao_sponsor_archive_ordered_ids()`) | 4 | **4** |
| Ordering (`ordered ids`) | `150,171,151,152` | `150,171,151,152` (**identical**) |
| Rendered cards on `/apoiadores/` | 4 | **4** |
| Rendered cards on `/en/apoiadores/` | 4 | **4** |
| Rendered Apoiadores vs available | — (fitted by luck: 4 < page size 10) | **rendered == available == 4** |
| Site page size (`posts_per_page`) | 10 | 10 (global setting untouched) |
| Archive query page size | 10 (implied) | **-1 + `nopaging`** |
| Archive `max_num_pages` | 1 | **0** (single page) |
| Pagination pages | 0 (latent: 1 page because 4 ≤ 10) | **0 (structural)** |
| Pagination controls on the page | 0 | **0** |
| Missing Apoiadores | 0 | **0** |
| HTML bytes PT / EN | 49 955 / 49 880 | 49 955 / 49 880 (**byte-identical**) |

With the temporary fixture set the suite creates (12 available Apoiadores, i.e.
beyond the page size), the pre-change code renders only **10** and emits
`page-numbers` + `Próximo →`; the post-change code renders **12** and emits
nothing — see §10.

### Commands

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh --only conexao-br-irlanda` (before) | 1 | 39 suites, 37 passed, **2 pre-existing failures**; 2 767 assertions passed, 5 failed |
| `./scripts/run-tests.sh --scripts` (before) | 1 | 19 suites, 17 passed, **2 pre-existing failures** |
| `./scripts/run-tests.sh --acceptance` (before) | 1 | 4 suites, 3 passed, 1 failed; `verify-routing-http.py` **92 passed, 0 failed** |
| `docker compose exec wordpress php …/test-sponsor-archive-single-page.php` (before) | **1** | **19 passed, 9 failed** — the regression suite fails on the unmodified code |
| `docker compose exec wordpress php …/test-sponsor-archive-single-page.php` (after) | **0** | **28 passed, 0 failed** |
| `python3 tests/acceptance/verify-routing-http.py` (after) | **0** | **118 passed, 0 failed** (was 92: +4 new matrix rows and +22 dynamic Apoiadores checks — status, REST set non-empty, rendered==available, exactly-once, no pagination markup, no `rel=next/prev`, 4 detail links resolving, page 2 = 404, per language) |
| `python3 tests/browser/verify-sponsors-archive-browser.py` (after) | **0** | **94 passed, 0 failed** |
| `python3 tests/browser/verify-sponsors-archive-browser.py --negative-control` | **0** | **18 passed, 0 failed** (injected pagination + overflow caught by the same predicates) |
| `./scripts/lint.sh` (after) | **0** | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `php scripts/generate-registry-docs.php --check` | 0 | 0 writes, no registry drift (no registry change) |
| `python3 tests/scripts/verify-documentation-drift.py` | **0** | 14 passed, 0 failed (ran as part of `--scripts`, after the documentation edits) |
| `./scripts/run-tests.sh` (after, full) | *see §11* | the whole contract in one aggregate exit code |

### Independent corroboration (a second, serialized full run)

The concurrent session's own `./scripts/run-tests.sh` (started *after* this change
was in the working tree, and therefore exercising it) reported:

```
In-process PHP suites: 101 total, 96 passed, 5 failed      (100 suites before this task + the new one)
Assertions:              7749 passed, 7 failed
Script-contract suites:   19 total, 17 passed, 2 failed
HTTP acceptance suites:    4 total,  3 passed, 1 failed
[PASS] theme/conexao-br-irlanda/test-sponsor-archive-single-page.php — 28 passed, 0 failed
[PASS] tests/acceptance/verify-routing-http.py — 118 passed, 0 failed
```

Its five failing in-process suites are the pre-existing ones named in §12
(`test-export-language`, `test-ics-occurrence-dates`, `test-town-sanitization`,
`test-event-location-filters`, `test-stage41-rest-language`) — none related to
Apoiadores. That run is independent evidence that the same tree passes the same
suites, and that the new suite and the new matrix rows are green outside this
session.


### HTTP status matrix (after)

| URL | Status | Bytes |
|---|---|---|
| `/apoiadores/` | 200 | 49 955 |
| `/en/apoiadores/` | 200 | 49 880 |
| `/apoiadores/page/2/` | **404** | 47 263 |
| `/en/apoiadores/page/2/` | **404** | 47 050 |
| `/apoiadores/page/3/` | **404** | 47 263 |
| `/apoiadores/?paged=2` | **404** | 47 263 |
| `/apoiadores/feed/` | 200 | 3 559 (same as before) |
| `/guias/page/2/` (other archive) | 200 | 64 409 |
| `/blog/page/2/` (other archive) | 200 | 62 321 |

### Performance observations (measured, not estimated)

| Metric | Before | After |
|---|---|---|
| DB queries — the archive query itself (fresh process, deterministic) | 7 (includes WordPress' `SELECT FOUND_ROWS()` probe) | **6** (the probe is skipped with no `LIMIT`) |
| DB queries — whole `/apoiadores/` request | 32 (observed range 32–36 across cold/warm requests) | 32 (same range) — **no query added** |
| Wall time `/apoiadores/` | 0.118 s | 0.068–0.119 s |
| Wall time `/en/apoiadores/` | 0.162 s | 0.124 s |
| HTML bytes | 49 955 / 49 880 | identical |
| Rendered cards / images | 4 / 3 (theme logos) | 4 / 3 |
| JS / AJAX / lazy loading / extra API calls added | — | **none** |

The directory is 4–5 records; rendering it in full is trivially safe (one query,
~50 KB, one HTTP request, no new asset). No artificial limit was introduced, and
the numbers above are the evidence that none is needed at this size.

## 10. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate. Four independent proofs:

| Proof | Result |
|---|---|
| **The in-process suite fails on the pre-change code.** Before the implementation, the suite was run against the unmodified theme with its fixture set (12 available Apoiadores vs the site page size 10): `test-sponsor-archive-single-page: 19 passed, 9 failed`, exit 1 — `expected 12, got 10`, `max_num_pages=2`, and the shared pagination component rendered `<nav class="conexao-pagination">… <a class="page-numbers">2</a> … <a class="next page-numbers">Próximo →</a>` (evidence file `10-…`). After the fix the same suite is `28 passed, 0 failed`, exit 0. | **PASS** (the suite is non-vacuous) |
| **The in-process negative control.** The suite also queries the same fixture set WITH a page size reintroduced and asserts the symptoms appear: fewer records than available, `max_num_pages > 1`, the pagination container and numbered/next links rendered, and page size 3 returning exactly 3. All five control assertions pass — and they are exactly what breaks if the unlimited configuration is removed. | **PASS** |
| **The browser negative control.** `--negative-control` injects a real `.conexao-pagination` nav and a 3 000 px element into the rendered page and requires the *same predicates* the happy path asserts (`pagination_is_absent`, `has_no_horizontal_overflow`) to be violated: `18 passed, 0 failed` (e.g. `3000 > 390`, `1 node(s)`). | **PASS** |
| **The HTTP matrix row can go red.** The `pt-apoiadores-single-page-no-pagination` row asserts the absence of `conexao-pagination`/`page-numbers` — the very markup the pre-change render emitted (§9's fixture evidence), so the row fails on the pre-change code. | **PASS** |

## 12. Known pre-existing failures

All of the following were observed **before** this change was applied (targeted
before-run, `00-before-targeted-layers.txt`) and are unchanged by it — none of them
touches Apoiadores, the archive query layer or any file this task modified:

| Suite | Failure | Cause (pre-existing) |
|---|---|---|
| `theme/test-event-location-filters.php` | 45 passed, 1 failed | local dataset: the unfiltered archive is expected to return 4 test events; the local DB has more. Documented as pre-existing in earlier repo reports. |
| `theme/test-stage41-rest-language.php` | 218 passed, 4 failed | local dataset: the shared-county Dublin event expectations (PT/EN Dublin event, B2 warm-up) depend on event records the local DB does not carry. |
| `tests/acceptance/verify-event-occurrence-http.py` | 4 failed | local dataset: the Laois archive holds 5 series / 3 multi-day / 3 all-day events instead of the single fixtures the rows expect. |
| `tests/scripts/verify-eventbrite-token-retirement.py` | 1 failed | `tests/experiments/g16-source-eligibility.php` still references the retired token key (experiments tree, unrelated). |
| `tests/scripts/verify-i18n-freshness.py` | 2 failed | `.pot` catalogues older than their sources (`conexao-event-runtime`, `conexao-event-importer`) — mtime-based, unrelated (no plugin file was touched). |
| `plugin/conexao-event-importer/test-export-language.php` | exit 255, PHP memory exhausted (512 MB) | observed in the partial full-suite baseline; heavy export suite, unrelated. |
| `plugin/conexao-event-importer/test-ics-occurrence-dates.php` | 171 passed, 1 failed | date-dependent expectation (`2026-10-06` next occurrence), partial full-suite baseline; unrelated. |
| `plugin/conexao-event-importer/test-town-sanitization.php` | failed in the independent full run | local datum/town registry condition; unrelated to Apoiadores (not reached by this session's partial baseline, observed as failing in the concurrent session's complete run and unchanged by this task). |

## 13. Limitations

1. **A concurrent session was editing this workspace.** `git status` was clean when
   this task started, but during the verification window a second session modified
   `wp-content/themes/conexao-br-irlanda/assets/css/main.css` (hero carousel dots),
   added the untracked `tests/browser/verify-sponsor-carousel-mobile-browser.py`
   and `carousel-work/`, ran its own browser proof and launched its own
   `./scripts/run-tests.sh`. Consequences, stated honestly:
   - the whole-file HTML comparison for `/apoiadores/` shows exactly one differing
     line — the `main.css?ver=` cache-buster that the CSS edit produced; the markup
     this task owns is byte-identical before/after;
   - one acceptance request logged a transiently larger `/apoiadores/` body
     (50 432 bytes) while that session was writing files; with `WP_DEBUG` on,
     warnings can render into the page. Two measurements taken after the edits
     settled are identical to the pre-change body (49 955), so this is recorded as
     an observation, not a regression;
   - PHP suites from two sessions cannot run concurrently by design (shared fixture
     data), so the final full-suite run was **serialized behind** the other
     session's run; nothing in this report depends on a concurrent result;
   - the "before" numbers for the plugin layer come from a partial full-suite run
     that was stopped when the concurrent session began editing; the layers this
     change can affect (theme, script contract, HTTP acceptance) were baselined
     completely and separately.
2. **No production verification.** The change was verified locally only; deploying
   the theme to WordPress.com is the maintainer's separate release step.
3. **Apoiador card images could not be exercised with real artwork.** Every local
   synthetic Apoiador fixture carries no image, so no card renders an `<img>`
   (the documented no-image fallback). The image assertions therefore cover the
   images the page really renders (three theme logos — all loaded, all with alt
   text). The card-image code path (`conexao_sponsor_carousel_image()`) is
   unchanged by this task and is already covered by the sponsor suites.
4. **The slow 404 path is pre-existing.** `/apoiadores/page/2/` answered 404 in
   5.7–6.5 s before the change and 404 in the same range after; the cost lives in
   the 404 template, not in the guard, and it was measured under a concurrent test
   run.
5. **The browser layer is opt-in.** It is not discovered by
   `./scripts/run-tests.sh` (that layer never launches a browser) and must be run
   explicitly, as documented in `docs/testing.md`.
6. **Pre-existing documentation staleness left untouched on purpose:**
   `docs/content-model.md`'s `_sponsor_link` paragraph still claims archive cards
   link to the official website, while the archive has long linked to the Apoiador
   detail page. Recorded here instead of being silently "fixed" (unrelated scope).

## 14. Evidence paths

`docs/evidence/2026-10-08-apoiadores-single-page/`:

| File | Proves |
|---|---|
| `00-before-targeted-layers.txt` | The complete pre-change baseline for the theme, script-contract and HTTP acceptance layers (39 theme suites / 2 767 assertions; 19 script suites; 4 acceptance suites) with the pre-existing failure list |
| `01-before-pt-apoiadores.html`, `02-before-en-apoiadores.html` | The pre-change rendered PT/EN Apoiadores pages (4 cards, no pagination) |
| `02-before-probe.txt` | The pre-change server-side probe output |
| `10-negative-control-suite-fails-on-pre-change-code.txt` | The regression suite failing against the unmodified code (19/9, with the rendered `Próximo →` pagination) |
| `11-after-pt-apoiadores.html`, `12-after-en-apoiadores.html` | The post-change rendered pages — byte-identical to the before files apart from the unrelated `main.css?ver=` cache-buster |
| `13-after-inprocess-suite.txt` | The suite passing after the change (28/0) |
| `20-after-acceptance-routing-http.txt` | The HTTP acceptance suite after the change (118/0), including the 4 new rows and the dynamic rendered==available checks |
| `21-after-lint.txt` | `./scripts/lint.sh` clean (syntax + PHPCS + PHPStan) |
| `22-after-browser-acceptance.txt` | Browser acceptance at 1440×1000 and 390×844 plus the DOM-injecting negative control (94/0 and 18/0) |

## 15. Documentation updated

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/themes/conexao-br-irlanda.md` | Apoiadores directory: "single page (no pagination)" bullet, the guard, the feed exclusion, the ordering sentence, the helper-table row, and pagination added to the deliberate out-of-scope list | — |
| `docs/content-model.md` | `_sponsor_display_order`: the whole ordered set renders on one page | — |
| `docs/frontend.md` | `pagination.php` row: renders nothing when the query has one page (single-page Apoiadores) | — |
| `docs/routing.md` | Sponsors route row: single-page directory in both languages; paged URLs keep their 404 | — |
| `docs/testing.md` | New "Apoiadores single-page browser acceptance" subsection documenting the opt-in suite and its blocking halves | — |
| `docs/reports/2026-10-08-apoiadores-single-page-directory-plan.md`, `docs/reports/2026-10-08-apoiadores-single-page-directory.md` | Plan and this report | — |

All five living documents carry a refreshed `_Last verified: 2026-10-08 …` line, and
`verify-documentation-drift.py` passes (14/0) with the documentation edits in place.

## 16. Rollback / recovery

- **Revert:** `git checkout 47d3f2a1 -- wp-content/themes/conexao-br-irlanda/inc/archive-query.php wp-content/themes/conexao-br-irlanda/inc/sponsors.php`
  (or `git revert <commit>` once committed). The archive returns to the default page
  size and latent pagination: `/apoiadores/` shows what fits on page 1 and page 2+
  returns for the rest.
- **Revert with it:** `tests/acceptance/matrices/routing.json` (4 rows),
  `tests/acceptance/verify-routing-http.py` (dynamic checker) and the new suites
  (`test-sponsor-archive-single-page.php`, `verify-sponsors-archive-browser.py`) —
  they are the regression proof and fail against the reverted behaviour.
- **What rollback does not cover:** nothing else — no content, database, Polylang,
  plugin or deploy state is touched. Pre-change state captured for comparison:
  5 published sponsors (4 PT + 1 EN), 4 rendered per language view, ordering
  `150,171,151,152`, PT 49 955 / EN 49 880 bytes.





