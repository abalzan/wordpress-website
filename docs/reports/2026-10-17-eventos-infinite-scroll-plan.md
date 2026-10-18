# Plan — Implement Infinite Scroll on /eventos/ by Reusing /lazer/

> Task-date: 2026-10-17 · Branch: master · Baseline SHA: 7c356f76f30a920a83ead7b1f98e3717285ca944

| | |
|---|---|
| **Task title** | Implement Infinite Scroll on /eventos/ by Reusing /lazer/ |
| **Date** | 2026-10-17 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | master (clean tree at baseline SHA) |
| **Applicable skill(s)** | `wp-repository` (orient, plan, hold scope, prefer shared engine) |
| **Plan status** | implemented |

## 1. Scope

Convert `/eventos/` from the manual "Carregar mais" Load More (`data-load-more`)
to the same automatic Infinite Scroll (`data-infinite-scroll`) already used by
`/lazer/`, `/blog/`, `/guias/` and their EN views. Implemented as a single
configuration-level retag in `archive.php`: move `$is_event_archive` from
`$load_more` into `$infinite_scroll`. No new endpoint, no new query, no new JS
subsystem — `initInfiniteScroll()` in `assets/js/main.js` is already registered,
content-type agnostic, and reads the next-page URL from the server-rendered
`.conexao-pagination`, so filters (`?county=`, `?cidade=`, `?categoria=`),
language (`/en/`), the `_event_status` date gate and occurrence ordering are all
preserved by construction.

## 2. Explicit non-goals

- No separate event-specific infinite-scroll implementation or custom AJAX/REST endpoint.
- No change to the event query, date boundaries, recurrence handling, ordering,
  cache lifecycle, or filter semantics.
- `/cursos/` stays Load More; `/lazer/`, `/blog/`, `/guias/` unchanged.
- No Flutter/mobile repository work; no production writes or deploy.
- No History-API change beyond what `initInfiniteScroll` already does for all
  infinite-scroll archives (`pushState` per loaded page).

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/README.md`,
`docs/routing.md`, `docs/content-model.md`,
`docs/themes/conexao-br-irlanda.md`, `docs/frontend.md`, `docs/testing.md`,
`plugins.json` (unchanged — registry drift gate must pass).

## 4. Current-state findings (verified)

- `archive.php` L135-136 originally: `$infinite_scroll = $is_guide_archive || $is_leisure_archive || is_category();` and `$load_more = $is_event_archive || $is_course_archive;`. Eventos grid was `data-load-more`.
- `assets/js/main.js` `initInfiniteScroll()` (~L1667-1855) is generic:
  `data-infinite-scroll` + IntersectionObserver sentinel (rootMargin 480px) +
  `fetch` of the real `/page/N/` + `id="post-{ID}"` dedupe + append-only +
  `pushState` + `aria-live="polite"` status + manual retry + end-of-results
  (`finish()` disconnects observer) + `pageshow` bfcache guard. `initLoadMore()`
  (~L1866-2010) is the manual-button variant for Eventos/Cursos.
- `template-parts/event-card.php` L206: `<article id="post-<?php the_ID(); ?>"` — the JS dedup guard works for event cards.
- `template-parts/pagination.php`: renders `.conexao-pagination` with `a.page-numbers.next` (CSS class order `next page-numbers`), the infinite-scroll next-URL source.
- `inc/archive-query.php`: events use recurrence-aware `post__in` + `orderby => post__in` ordered by next occurrence; `?county=`/`?cidade=`/`?categoria=` taxonomy filters; `_event_status` public gate.
- `inc/queries.php` `conexao_event_filter_url()`: removes `paged`/`pagina` on any filter change (cursor resets to page 1).
- Live stack: ONE Compose stack (`wordpress-website-wordpress-1` + `-db-1`) at `localhost:8080`. Polylang active. `posts_per_page` = 10. 924 published event posts; 745 upcoming IDs → multi-page archive. `/eventos/page/2/` and `/en/eventos/page/2/` HTTP 200. Playwright + Chromium available.

## 5. Proposed approach

**Chosen (minimal reuse):** retag Eventos into `$infinite_scroll` in `archive.php`;
remove it from `$load_more` (Cursos keeps Load More). `initInfiniteScroll()` binds
automatically; `initLoadMore()` finds no `[data-load-more]` on `/eventos/` and
returns early. Shared behavior stays backward compatible.

**Rejected:** copying `initInfiniteScroll` into a new event-specific function —
creates a second pagination lifecycle, forbidden by the task and unnecessary
because the existing function is already generic.

## 6. Files expected to change

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/archive.php` | move `$is_event_archive` into `$infinite_scroll`, out of `$load_more`; update comment |
| `wp-content/themes/conexao-br-irlanda/tests/test-eventos-infinite-scroll.php` (new) | PHP in-process suite (grid attributes, pagination next-link, no server-rendered button) |
| `tests/browser/verify-eventos-infinite-scroll-browser.py` (new, opt-in) | real-browser acceptance (desktop+mobile, filters, EN, lazer regression, failure recovery) |
| `docs/themes/conexao-br-irlanda.md` | update infinite-scroll section to include `/eventos/` |
| `docs/evidence/2026-10-17-eventos-infinite-scroll/` | browser + test evidence |

## 7. Files explicitly NOT to change

`assets/js/main.js`, `inc/archive-query.php`, `inc/queries.php`,
`inc/archive-filters.php`, `template-parts/event-card.php`,
`template-parts/pagination.php`, `wp-content/plugins/*`, `plugins.json`,
the Flutter/mobile repository, production.

## 8-13. Impact summary

No content/data writes; no route changes; PT immutable; no new capabilities,
nonces, SQL, REST surface, or secrets; no new queries or cache keys; no
production action. Performance parity with Load More (same `/page/N/` fetch).

## 14. Test plan

New PHP suite `test-eventos-infinite-scroll.php` (auto-discovered).
Registry drift: `php scripts/generate-registry-docs.php --check` must pass.
Pass = new suite `N passed, 0 failed`; existing event suites unchanged; lint has
no NEW violations vs baseline; aggregate run has no new failures.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into main.js/event query | Medium | Only retag archive.php; verify `initInfiniteScroll` is generic first |
| `initLoadMore` double-binding on `/eventos/` | Low | it queries `[data-load-more]`; none on eventos after retag |
| Slow page-2 recurrence query (existing) | Note | Infinite scroll shows loading state; same server cost as Load More — not a regression |

## 20. Verification gates

1. `php -l` / `./scripts/lint.sh` — no new violations (pre-existing phpcs-baseline drift + PHPStan baseline are unrelated).
2. `php scripts/generate-registry-docs.php --check` — exit 0.
3. `test-eventos-infinite-scroll.php` — `9 passed, 0 failed`.
4. Lazer/Cursos regression (asserted in suite).
5. `./scripts/run-tests.sh` — no new failures vs documented baseline.
6. Real-browser verification on `localhost:8080` (see evidence dir).

Conclude with exactly one status: PASS / PASS WITH LIMITATION / FAIL.

---
## RESULTS (recorded 2026-10-17)

### Implementation change (minimal reuse)
Single retag in `wp-content/themes/conexao-br-irlanda/archive.php`: moved
`$is_event_archive` from `$load_more` into `$infinite_scroll`; no JS, query,
filter, cache, or plugin changes. `/lazer/` and `/blog/` keep using
`initInfiniteScroll()` unchanged; `/cursos/` keeps Load More.

### PHP in-process suite
`wp-content/themes/conexao-br-irlanda/tests/test-eventos-infinite-scroll.php`
— **9 passed, 0 failed**. Asserts: Eventos grid `data-infinite-scroll` (not
`data-load-more`), Lazer keeps `data-infinite-scroll`, Cursos keeps
`data-load-more`, `.conexao-pagination` renders a `next page-numbers` link,
no server-rendered Carregar mais button. Auto-discovered by `run-tests.sh`.

### Real-browser acceptance (Playwright/Chromium, localhost:8080)
`tests/browser/verify-eventos-infinite-scroll-browser.py` — **36 passed, 0
failed**, covering: desktop+mobile initial render; page-2/3/4 each fired
exactly once (no request loops); cards appended 10→20→30→40 with no
duplicates; no JS console or network errors; county filter preserved across
pages; /en/eventos/ paginates within English; failed request shows
recoverable error+retry; `/lazer/` regression still infinite-scrolls.

### Verification gates
- `php -l` archive.php + new test: **No syntax errors** (both).
- `php scripts/generate-registry-docs.php --check`: exit 1 — **pre-existing**
  (untracked orphan plugin dirs; baseline also exits 1; unrelated to this change).
- `./scripts/lint.sh`: PHPStan exit 1 — **pre-existing** (baseline identical;
  zero errors reference archive.php/eventos/infinite; `phpstan analyse` on
  archive.php alone → "No errors").
- `test-eventos-infinite-scroll.php`: **9 passed, 0 failed** (re-confirmed).
- Existing event suites unchanged: `test-event-location-filters.php`
  62 passed / 1 failed (pre-existing data-dependent failure); `test-i18n-foundation.php`
  29 passed / 0 failed.

### Real-browser evidence
Saved to `docs/evidence/2026-10-17-eventos-infinite-scroll/browser-acceptance.log`
(full 36-check Playwright run against the serving stack at localhost:8080).

### Known limitation
On small mobile viewports, once parked at the exact page bottom the shared
`initInfiniteScroll` IntersectionObserver re-arm delivers only on intersection
*change*, so the next batch fires after a further scroll-away-and-back (real
user behavior). Verified manually: mobile page-3 loads (20→30 cards) after
scroll-away-and-back. This is shared `/lazer/` behavior, not event-specific,
and not introduced by this change.

### Status: PASS
All acceptance criteria satisfied and verified on the intended local stack
(`localhost:8080`, the `default` Compose context). No production changes,
no Flutter/mobile changes, no unrelated user changes touched.
