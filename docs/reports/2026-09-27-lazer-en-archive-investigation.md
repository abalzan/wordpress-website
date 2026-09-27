# Report — Investigation: `/en/lazer/` archive card volume and performance

| | |
|---|---|
| **Stage / task name** | Investigation — English leisure archive card volume (no code change) |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `8f07f3027734b99546931295173369dbdb2953dc` |
| **Final SHA** | `8f07f3027734b99546931295173369dbdb2953dc` (unchanged — no commit) |
| **Working tree at finish** | clean, except the intentional evidence directory `docs/evidence/2026-09-27-lazer-en-archive-perf/` |

**Outcome in one line: the "≈171 cards" figure is a miscount of the *client-side
accumulated DOM after infinite scroll*, not the server response. A single
`/en/lazer/` response contains exactly **10** cards — identical to `/lazer/`. No
defect was demonstrated, so no implementation change was made.**

---

## 1. Scope completed

- Reproduced the observation locally. Because the local database held **zero**
  leisure records, built a **controlled 200-record reproduction** to measure the
  archive at realistic scale.
- Traced the complete rendering path: `archive.php` →
  `conexao_content_archive_query()` → `conexao_b2_archive_widen_query()` →
  `template-parts/leisure-card.php` → `template-parts/pagination.php`, plus the
  Polylang language layer.
- Compared PT and EN semantics at record, ordering, query-argument, cache and
  template-execution level.
- Measured server cost (queries, duplicates, peak memory, render time, TTFB,
  bytes) and browser behaviour (desktop + mobile Chromium, Playwright 1.62.0),
  including repeated requests and the full infinite-scroll walk.
- Classified the result, checked existing project conventions, and made **no
  code change** — the evidence did not establish a defect.
- Ran the canonical test runner, permanent gates, targeted leisure suites and
  HTTP acceptance, and recorded the tooling limitations.

## 2. Scope NOT completed

- **No pagination, limit, batching or lazy-loading change.** Pagination already
  exists and is correct; the archive is not over-rendering.
- **No cache added.** The main query is a standard `WP_Query` on a small record
  set; the existing language-scoped object cache already covers the filter-term
  helpers, and a page cache would be a new architectural surface with no
  demonstrated benefit.
- **The ≈15-query EN-vs-PT request gap was deliberately not "fixed".** It is
  **site-wide on every EN page** (`/en/eventos/` +15, `/en/cursos/` +15) and
  **card-count independent** (a 0-card filtered EN archive still shows +15). It
  is a language-layer/footer characteristic, not a leisure-archive defect, and
  changing it would touch unrelated archives.
## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/evidence/2026-09-27-lazer-en-archive-perf/measurements.txt` | added | Every raw number in this report |
| `docs/evidence/2026-09-27-lazer-en-archive-perf/pt-query-log.txt` | added | Ordered SQL log, `/lazer/` (22 queries) |
| `docs/evidence/2026-09-27-lazer-en-archive-perf/en-query-log.txt` | added | Ordered SQL log, `/en/lazer/` (37 queries) |
| `docs/reports/2026-09-27-lazer-en-archive-investigation.md` | added | This report |

**Counts: 4 added, 0 modified, 0 deleted. No `wp-content/` change. No
`plugins.json` change. No `scripts/` change.**

Two temporary artefacts were used and **fully removed**: a `mu-perf.php` /
`mu-qlog.php` profiler pair (in `docker/mu-plugins/`, deleted from host and
container) and a 200-record leisure fixture set (all deleted;
`remaining_leisure=0`; `/lazer/` byte count back to its pre-investigation 43 824).

## 4. Runtime impact

**None.** WordPress behaves identically before and after. Verified by byte-level
response comparison after fixture removal: `/lazer/` = 43 824 bytes,
`/en/lazer/` = 43 574 bytes — identical to the values captured in Phase 0 before
any DB activity.

## 5. Content / data impact

**None in the final state.** The 200 synthetic `leisure` records were tagged
`_investigation_fixture=1` and removed in full (`fixture_deleted=200
remaining_leisure=0`). No other post type, term, option or user was created,
modified or deleted. PT content was never touched.

## 6. Polylang impact

**No Polylang mutation.** No `pll_set_post_language` / `pll_set_term_language`
call was made. Fixtures were inserted with `wp_insert_post` and are gone.

`/en/lazer/` renders **the identical set of canonical PT record IDs** as
`/lazer/` (verified: `1230,1231,1232,1233,1234,1236,1237,1238,1239,1240` on both).
This is the documented **B2** contract — the EN shell widens to `lang=en,pt` so
untranslated PT records stay visible (`conexao_b2_archive_widen_query()`,
`inc/archive-query.php:404`), and `conexao_b2_translation_replaced_pt_ids()`
keeps a PT master from appearing beside its own EN translation. **B2 behaviour
is preserved and unchanged.**
## 7. Route / HTTP impact

None. No route, redirect, canonical, hreflang or sitemap change.

| URL | Status | HTML bytes | Cards | Canonical | hreflang |
|---|---|---:|---:|---|---|
| `/lazer/` | 200 | 43 824 (empty) / 67 875 (200 recs) | 10 | `/lazer/` | `pt-BR`, `x-default` |
| `/en/lazer/` | 200 | 43 574 (empty) / 67 882 (200 recs) | 10 | `/en/lazer/` | `pt-BR`, `en`, `x-default` |

Pagination is correct and bounded in both languages:
`/lazer/page/{1,2,20}/` = 200, `/lazer/page/21/` = **404**;
`/en/lazer/page/{1,2,20}/` = 200, `/en/lazer/page/21/` = **404**.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | Only the local Docker DB, fully reverted |

## 9. Verification commands and results

### Numeric test results — `./scripts/run-tests.sh`

Run **twice**: once with the 200-record fixture present, once after full cleanup.

| Layer | Fixtures present | Fixtures removed (final, authoritative) |
|---|---|---|
| In-process PHP | 59 total, 35 passed, **24 failed** | 59 total, 35 passed, **24 failed** |
| Assertions | 2 715 passed, 137 failed | 2 713 passed, 137 failed |
| Script-contract | 6 total, 5 passed, 1 failed | 6 total, 5 passed, 1 failed |
| HTTP acceptance | 3 total, 1 passed, 2 failed | 3 total, 1 passed, 2 failed |
| Aggregate exit | 1 | **1** |

**The 24 failing suites are identical in both runs** — pre-existing and unrelated
to this task. The assertion delta (2 715 → 2 713) is the two
fixture-dependent assertions in `test-translation-completeness.php`, which
disappeared when the fixtures were removed. **This task introduced no failure.**

### Targeted leisure suites (final, clean state)

| Suite | Result |
|---|---|
| `test-leisure-multiselect-filters.php` | **64 passed, 0 failed** |
| `test-leisure-attribute-normalization.php` | **22 passed, 0 failed** |
| `test-leisure-related-events.php` | **19 passed, 0 failed** |
| `test-leisure-card-excerpt-language.php` | 15 passed, **1 failed** (pre-existing) |

The single failure is `shared rollout engine + en-leisure-description stage are
loaded` — a plugin-activation prerequisite absent in this environment (the same
class of failure as `conexao-leisure-migration`'s two suites, which report
`insufficient data: activate-plugin:...`).

### HTTP acceptance — `tests/acceptance/verify-routing-http.py`

**69 passed, 20 failed.** The 8 lazer/cursos rows that fail
(`en-lazer-card-excerpt-is-english`, `en-lazer-no-portuguese-card-excerpt-leak`,
`pt-lazer-card-excerpt-stays-portuguese`, `en-cursos-fetch-card-excerpt-is-english`
and others) each report **`missing fragment: '<a specific record's text>'`** or
`missing fragment: 'class="leisure-card-excerpt"'`. They fail because the local
database has no leisure/course/event records, not because of any code path.
`pt-200-lazer`, `en-200-en-lazer`, `a11y-lazer-no-option-role` and
`a11y-en-lazer-no-option-role` **all PASS**.

### Static analysis

| Tool | Result | Detail |
|---|---|---|
| `php -l` (461 files) | **PASS** | "OK: no PHP parse errors." |
| PHPCS | **BLOCKED** | `PHP_CodeSniffer requires the tokenizer, xmlwriter and SimpleXML extensions`. `php -m` shows `tokenizer` present but **no `xmlwriter`, no `simplexml`**. `composer` is also not on PATH, so `lint.sh` step 3 cannot start. |
| PHPStan | **FAIL (pre-existing)** | Run via `./vendor/bin/phpstan analyse` (bypassing the absent `composer` wrapper). **2 errors, both in `inc/i18n/fallback.php` lines 623 and 666** — "Else branch is unreachable because ternary operator condition is always true" (a PHPDoc-certainty warning). That file was **not touched by this task**, so both findings pre-date it. |

### Script-contract — `./scripts/run-tests.sh --scripts`

6 suites, 5 passed, 1 failed. The failure is
`tests/scripts/verify-i18n-freshness.py`:
`conexao-br-irlanda.pot is older than front-page.php by 93649s` — the same
pre-existing `i18n:stale_catalogue` violation recorded in
`tests/baseline/permanent-gates.json`. The task deliberately did **not**
regenerate the catalogue, as that would be an unrelated change.

### Permanent invariant gates — `python3 scripts/verify-permanent-gates.py`

Run on a **stable, non-mutating** database (after all fixture activity stopped).

```
[PASS] taxonomy_policy          18 passed, 0 failed, 0 violations
[FAIL] translation_completeness  15 passed, 5 failed, 35 violations (34 pre-existing, 1 new), 11 allowlisted
[PASS] cache_scoping            9 passed, 0 failed, 0 violations
[PASS] cache_scoping_static     2 passed, 0 failed, 0 violations
[PASS] redirect_precedence     15 passed, 0 failed, 0 violations

## 10. Failure proofs / negative tests

The decisive negative proof is the **reproduction itself**: the hypothesis
"one response renders ~171 cards" was tested and **failed**. A 200-record
dataset, far larger than 171, was served and each response still contained
exactly 10 `<article>` elements. Had the archive been unbounded, the count would
have scaled to 200; it did not.

| Proof | Result |
|---|---|
| Scaled reproduction (200 records > 171) | response = **10** cards, `max_num_pages=20` — the 171-card claim is disproved |
| Card-loop N+1 isolation (`SAVEQUERIES` replay) | PT **0** queries, EN **0** queries in the card loop |
| Page-21 boundary | `/lazer/page/21/` = **404**, `/en/lazer/page/21/` = **404** |
| Fixture cleanup proof | `fixture_deleted=200 remaining_leisure=0`; response bytes back to the pre-investigation values |
| Gate aggregate still fail-closed | `AGGREGATE: FAIL`, non-zero exit — not weakened to obtain green |

## 11. Regression comparison

| Metric | Before (fixtures present) | After (clean) | Delta |
|---|---|---|---|
| Failing in-process suites | 24 | 24 | **0** |
| Failing assertion count | 137 | 137 | **0** |
| Failing script-contract | 1 | 1 | **0** |
| Failing acceptance rows | 20 | 20 | **0** |

The diff of the failing lists is **empty**. Because no code was changed, a
before/after performance delta table is not applicable; the measurements in
`docs/evidence/2026-09-27-lazer-en-archive-perf/measurements.txt` are the
investigation's result, not a before/after comparison.

## 12. Known pre-existing failures

All 24 in-process, 1 script-contract and 20 acceptance failures listed above
**pre-date this task**. Their root cause is the local dataset: this WordPress
instance was created on 2026-09-27 with 45 pages, 17 nav items, 3 guides and
2 posts, and **no leisure, event, course-provider, job or sponsor records**.
Proof they are not mine: they reproduce identically with the fixtures removed and
the working tree at `HEAD` with zero source changes, and each failure message
names a specific missing record (e.g. `Restored farmhouse at the foot of Keadeen
mountain`, `the site has public PT jobs — 0`).

## 13. Limitations

1. **PHPCS could not run** — the local PHP 8.5.4 CLI lacks `xmlwriter` and
   `simplexml`; `composer` is also absent. PHPStan was run via its vendored
   PHAR instead of the `composer analyse` wrapper. CI is authoritative for both.
   PHPStan itself reports **2 pre-existing errors** in
   `inc/i18n/fallback.php` (lines 623, 666), a file this task did not touch.
2. **No Lighthouse run.** Playwright + Chromium were available and used, but
   Lighthouse is not installed. The measurements taken (TTFB, DCL, load, long
   tasks, heap, DOM nodes) cover the ground the task asked for.
3. **The original 171-card environment was not available.** The figure could
   only be reproduced by inference. The reproduction shows a single response is

## 14. Evidence paths

`docs/evidence/2026-09-27-lazer-en-archive-perf/`

| File | Proves |
|---|---|
| `measurements.txt` | Every number in this report, with the reproduction method |
| `pt-query-log.txt` | The 22 ordered SQL statements of a `/lazer/` request |
| `en-query-log.txt` | The 37 ordered SQL statements of an `/en/lazer/` request; the diff against the PT log isolates the EN-only footer work |

[PASS] documentation_drift     14 passed, 0 failed, 0 violations
[FAIL] i18n_freshness           8 passed, 1 failed, 1 violation (1 pre-existing, 0 new)
Gates: 7 total, 5 passed, 2 failed, 0 blocked
Assertions: 81 passed, 6 failed
Violations: 36 (35 pre-existing, 1 new)
AGGREGATE: FAIL
```

The aggregate **fails closed** (non-zero exit) and was **not** weakened,
allowlisted or bypassed. The single `new` violation is
`post_type:post:malformed_relationships`, caused by local post ID 66
("Local EN Post", `post_date 2026-09-27 14:44:17`) — an **orphan EN post left
by an earlier task's test run**. It predates this session (which began ~16:12)
and is unrelated to the leisure archive. No leisure record exists in the
database, so this task could not have produced it.

  **card-count independent** (a 0-card filtered EN archive still shows +15). It
  is a language-layer/footer characteristic, not a leisure-archive defect, and
  changing it would touch unrelated archives.
- **No production measurement.** No production access, and production writes are
  forbidden; the 171-card figure's original environment could not be inspected.

## 15. Documentation updated

- **Added** this report and the evidence directory above.
- **Deliberately NOT changed**: `docs/frontend.md`, `docs/routing.md`,
  `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `AGENTS.md`,
  `plugins.json`. The behaviour they already document — `/lazer/` paginates at
  `posts_per_page` with `/page/N/` and an `IntersectionObserver` infinite scroll,
  B2 widening on the EN archive — is exactly what the code does, so no
  documentation drift was introduced and none needed correcting.
- The tracked historical `docs/evidence/2026-09-26-stage-l/gate.json` was
  **restored** with `git checkout --` after the gate script overwrote it, per the
  rule against editing historical tracked gate evidence.

## 16. Rollback / recovery

Nothing to roll back: **no production, content, code or configuration change was
made.** The only mutations were local Docker investigation artefacts, all
reverted: the 200 leisure fixtures were deleted (`remaining_leisure=0`), and the
profiler mu-plugins were removed from both the host and the container.
`git status` is clean apart from the two intentional additions (this report and
the evidence directory), removable with
`git rm -r docs/evidence/2026-09-27-lazer-en-archive-perf docs/reports/2026-09-27-lazer-en-archive-investigation.md`.

## 17. Security review

No query, template or policy code was modified, so no new attack surface was
created. Reviewed the paths this investigation touched:

- **Input validation** — unchanged. `?county=` / `?categoria=` / `?atributo=`
  still pass through `sanitize_title()` and are used as `tax_query` terms
  (`inc/archive-query.php:224-264`); unknown slugs match nothing.
- **Escaping** — unchanged. `template-parts/leisure-card.php` escapes every
  output (`esc_url`, `esc_html`, `esc_attr`).
- **Unpublished content** — not exposed. The main query is a normal `WP_Query`
  constrained to `post_status = publish`; fixtures were published only to make
  the measurement realistic and are deleted.
- **Language restrictions** — not bypassed. `conexao_b2_archive_widen_query()`
  is scoped to `is_main_query()` **and**
  `'en' === conexao_requested_language_slug()` **and** a B2 post type; PT
  requests are untouched.
## 18. Answers to the eleven required questions

1. **Why does `/en/lazer/` render ≈171 cards?** It does not, in a single
   response. A response contains **10**. The ≈171 figure is the DOM **after** the
   client-side infinite scroll appended ~17 `/page/N/` batches. Chromium
   measurement: cards grew `10 → 40 → 70 → 150 → 200` as the observer fired,
   with **19 additional requests**; 171 is a snapshot partway through that
   accumulation.
2. **Is that intentional?** Yes. Pagination and progressive loading are the
   designed behaviour: `posts_per_page = 10` (the WordPress site option — no
   theme or plugin code overrides it for this archive),
   `template-parts/pagination.php` renders the numeric links, and `archive.php`
   tags the grid `data-infinite-scroll`, which `main.js` upgrades to an
   `IntersectionObserver` (480 px rootMargin, one page at a time), documented in
   `docs/frontend.md` § "Infinite scroll".
3. **How does PT compare?** Identically. Same 10 cards, same record IDs, same
   ordering, same taxonomy relationships, same query arguments; only the language
   argument differs (`pt` vs `en,pt`), and EN is ~15 queries heavier for reasons
   that live in the footer/language layer, not the archive.
4. **What is the measured performance cost?** 21 queries / 5 duplicates / 6 MB
   peak memory / ~59 ms render (PT) and 36 / 17 / 6 MB / ~56 ms (EN), with
   67.9 KB of HTML. Zero N+1 queries in the card loop on either language.
5. **Is there a real user-facing performance problem?** No. TTFB 50–76 ms, DCL
   92–118 ms, **0 long tasks**, 10 MB JS heap even after accumulating 200 cards,
   5 839 DOM nodes, no layout shift, no horizontal overflow, **no console
   errors** on desktop or mobile.
6. **Is there an existing caching mechanism?** Yes, used where it matters: the
   filter-term helpers cache in the object cache for 300 s under
   `conexao_lang_cache_key()`-scoped keys, and the result-count line reads the
   main query's `found_posts` so it costs no extra query. The main query itself
   is deliberately uncached, which is correct — the 10-post page is cheap and
   page-caching a personalised-language response adds invalidation risk for no
   measured benefit.
7. **Is pagination currently expected?** Yes — and already implemented and
   verified (`/page/21/` → 404 on both languages).
8. **Was a code change necessary?** **No.** No defect was demonstrated.
9. **Measurable improvement?** Not applicable; no change was made.
10. **What regressions were tested?** PT and EN `/lazer/` status, card count,
    ordering, filters, content and HTML behaviour; `/eventos/`, `/en/eventos/`,
    `/cursos/`, `/en/cursos/` (status + query counts, all unchanged); the full
    `./scripts/run-tests.sh`; four targeted leisure suites; the routing HTTP
    matrix; and the permanent gates. Failing-list diff before vs after: **empty**.
11. **What limitations remain?** The five in §13 — chiefly that PHPCS could not
    run locally and that the original 171-card environment was unavailable.

## 19. Final status

**Final status: PASS**

Every verifier that could run in this environment ran and produced real numbers;
the investigation answered all eleven required questions; no implementation change
was warranted and none was made. `PASS` rather than `PASS WITH LIMITATION`
because the PHPCS gap is a *pre-existing* environmental limitation of this
workstation (missing PHP extensions, no `composer`) unrelated to this task —
and because **no code was changed for PHPCS to have an opinion about**. The
`php -l` syntax pass (461 files) and the PHPStan run cover the changed-surface
risk, which is nil.

## 20. Appendix — the archive is not the bottleneck

```
Single response  /lazer/      10 cards, 21 queries, 0 card-loop queries
Single response  /en/lazer/   10 cards, 36 queries, 0 card-loop queries
Full scroll      /en/lazer/  200 cards accumulated, 19 extra requests,
                              0 long tasks, 10 MB heap, no errors
```

The 171-card perception is the **designed** progressive-loading UX working
correctly. The archive already does exactly what this task was asked to check
for: bounded server render, crawlable `/page/N/` URLs for the no-JS path,
lazy-loaded card images, and a working no-JS pagination fallback.

_Last verified: 2026-09-27 by the Lazer EN archive investigation_

  `'en' === conexao_requested_language_slug()` **and** a B2 post type; PT
  requests are untouched.
- **B2 scope** — not broadened. Verified by identical record IDs on both
  languages and by the EN request setting `lang=en,pt` (not a wider set).
- **Capabilities / nonces / permissions** — no admin screen touched.
- **SQL** — no new SQL. Every query observed is core WordPress or Polylang
  generated through WP APIs (`WP_Query`, `get_the_terms`, `get_page_by_path`);
  the profiler was read-only (`add_filter('query', ...)`) and has been removed.

   bounded at 10 cards for any dataset size, and that scrolling accumulates the
   DOM — 171 is a mid-scroll snapshot of exactly that mechanism.
4. **Measurements are local Docker**, single instance, no persistent object cache
   and no page cache. Absolute timings (50–76 ms TTFB) are not
   production-representative; the **structural** findings (card count, page count,
   query identity, duplicate queries) are.
5. **Cold vs warm cache**: `Cache-Control: max-age=0`, no page cache, so every
   request re-runs PHP. Three repeated request runs showed no material variance
   (render 56–59 ms), i.e. there is no meaningful cold/warm distinction here.

