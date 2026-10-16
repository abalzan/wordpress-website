# Plan - /eventos/ uncached filter queries

| | |
|---|---|---|
| Task title | Investigate and optimize uncached filter queries on /eventos/ |
| Date | 2026-10-16 |
| Author | Cline |
| Branch | master |
| Baseline SHA | 6f55f1a |
| Skills | wp-frontend-perf + wp-write-in-process-test + wp-testing |
| Plan status | draft |

## 1. Scope
Verify county/town/category filter queries on /eventos/, measure cost, apply smallest effective fix using existing filter-term caching conventions. No production writes.

## 2. Explicit non-goals
- No archive rewrite, recurrence-engine or importer changes.
- No second cache lifecycle; reuse conexao_filters keys + conexao_homepage_cache_invalidate().
- No unrelated archive changes; preserve dirty functions.php flag and untracked debug files.
- No production action or deploy.

## 3. Authoritative docs
AGENTS.md; engineering-standard ss3,6,8,14; docs/frontend.md; docs/themes/conexao-br-irlanda.md; routing.md; content-model.md; testing.md; plugins.json (untouched).

## 3a. Skill workflow
Skill wp-frontend-perf. Preconditions: site up, before-measurement, known keys. Guardrails: logic in inc/*.php; 3+ query pages use language-scoped caches; reuse existing caches; scope via conexao_lang_cache_key(); invalidate all languages; update docs same change. Verification: run-tests.sh --php, --acceptance, lint.sh, verify-release.sh. Evidence: docs/evidence/2026-10-16-eventos-filter-cache/.

## 4. Current-state findings (code-verified)
- template-parts/event-filters.php calls on every unfiltered render: conexao_get_terms_for_post_type(county,event), conexao_get_event_towns(''), conexao_get_terms_for_post_type(category,event).
- conexao_get_terms_for_post_type (inc/queries.php:292): get_posts(post_type=event, posts_per_page=500, fields=ids, no_found_rows) + wp_get_object_terms per 100-ID chunk. NOT -1 (hypothesis REFUTED; -1 only in tooling/tests).
- conexao_get_event_towns('') (inc/queries.php:343): same shape, posts_per_page=500, orderby title, meta/term cache off + wp_get_object_terms.
- conexao_get_event_towns(county): extra term lookup + second get_posts(posts_per_page=500, tax_query county) + terms.
- Existing cache: wp_cache_get/set(conexao_filters, 300) via conexao_lang_cache_key(). No object-cache.php locally, so non-persistent: dedupes within request only, every HTTP request re-runs ID queries + term lookups.
- Same-request reuse exists for county branch via county_term_ids; unfiltered page still does 3 derivations + main query + Conexao_Event_Query candidate SQL.
- Invalidation gap: conexao_homepage_cache_invalidate() clears category_event, town_event (base only), guide + provider keys; never clears county_event nor any conexao_event_towns* county variant; no term-edit hooks.
- Scale: 930 published events, 32 counties, 249 towns. 500-ID cap = latent truncation, out of scope except preserve semantics.
- Baseline: curl /eventos/ 200 ~7.5s (3 runs 7.63/7.51/7.52s today; earlier evidence 7.8-8.3s). Focused suite test-event-location-filters.php: 62 passed, 1 failed (FAIL: unfiltered archive returns all four test events).

## 5. Proposed approach
Chosen: persistent transient L2 behind existing object-cache L1 (same keys via conexao_lang_cache_key(), 300s TTL, plain arrays rehydrated to WP_Term), extending existing conexao_homepage_cache_invalidate() (no new lifecycle).
- Helpers: object cache -> transient -> compute -> write both.
- Invalidation: delete county key + ALL conexao_event_towns* variants (enumerate county slugs) in every language + term hooks for 3 taxonomies.
- Rejected: get_terms() replacement (changes used-by-published + B2 semantics); new cache group (violates constraint 8); cap change (behavior change); do-nothing (per-request work repeats, std s3.2 mandates cache for 3+ query pages).

## 6. Files expected to change
- inc/queries.php: two-level cache in two helpers.
- inc/cache.php: extend invalidation + term hooks.
- theme tests/test-event-filter-cache.php: new suite (miss/hit/invalidation/language/empty/regression).
- docs/frontend.md: key/invalidation docs.
- docs/evidence/2026-10-16-eventos-filter-cache/: evidence.
- docs/reports/2026-10-16-eventos-filter-cache.md: final report.

## 7. Files NOT to change
plugins.json + generated regions; functions.php (preserve dirty flag); event-filters.php; archive-query.php; events.php; runtime/importer/i18n; docs/evidence/2026-10-16-eventos-perf/; docker/mu-plugins/cx-sql.php.

## 8. Content/data impact
Records: none (tests own ev-* fixtures only). Portable IDs: ev-* slugs. No content writes; s5.2 not triggered. PT unchanged: yes.

## 9. Route/HTTP impact
Routes: none. Redirects/sitemap: none. Re-run existing routing.json eventos rows (pt-200-eventos, en-200-en-eventos, filters-present x2, english-copy, filter-links-stay-en, county/town-filter-works, a11y rows). No new rows.

## 10. Polylang/EN impact
category/tag translated, county/town shared: preserved. Keys suffixed _pt/_en; invalidation clears all. B2 lang=en,pt widening untouched. PT immutable: yes. Completeness gate unaffected.

## 11. Security impact
Capabilities/nonces/REST/secrets: none. No new SQL (get_posts/wp_get_object_terms). Sanitisation/escaping unchanged.

## 12. Performance impact
Removes per-request event-ID + term derivations (3 derivations) on warm hits; main query + candidate SQL unchanged. Keys in s10, TTL 300s. Measure SAVEQUERIES delta cold vs warm + wall time.

## 13. Production impact
Written to production: no. Deploy/activation: no. Verification: local only.

## 14. Test plan
New test-event-filter-cache.php: correct options; miss populates; hit avoids recompute; save/delete + term hooks invalidate; PT!=EN; empty sets; filtered URLs unchanged; date rules (upcoming/expired) preserved via regression snapshot. Existing: test-event-location-filters, test-cache-language-scoping, runtime test-event-query, routing.json eventos rows, scoping static gate, generate-registry-docs --check. Pass = new green + no new failures + vacuity proof (revert -> hit test fails).

## 15. Acceptance matrix plan
No new rows. Re-run listed s9 rows.

## 16. Rollback plan
Revert queries+cache hunks (one commit); delete touched transients per language or let 300s TTL expire. Pre-change state: this plan + baseline logs/timings. No content to roll back.

## 17. Documentation plan
- docs/frontend.md: persistent keys + invalidation.
- docs/themes/conexao-br-irlanda.md: only if helper section stale.
- docs/reports/2026-10-16-eventos-filter-cache.md: new report.

## 18. Release implications
Version bumps: none. Allowlist: unaffected (plugins.json untouched, tests/ excluded). Release in scope: no.

## 19. Risks
Stale options on taxonomy-only change (Medium): term hooks + test. Per-county key missed (Medium): enumerate slugs + test. Serialized WP_Term breakage (Low): store plain arrays. Scope creep (Medium): diff limited to 2 helpers + invalidation. Doing too much via get_terms() (Low): rejected, regression pins semantics.

## 20. Verification gates
1. run-tests.sh --only theme green (numbers recorded). 2. Full run-tests.sh exit code + failing list vs baseline. 3. lint.sh + scoping gate pass. 4. Acceptance eventos rows pass + cold/warm evidence.

## 21. Completion criteria
Per standard s14: scope held; sources followed; no second truth; PT unchanged; numeric gates; no secrets/localhost; caches scoped + fully invalidated; one change per commit; report + evidence; plugins.json untouched; no production action.
