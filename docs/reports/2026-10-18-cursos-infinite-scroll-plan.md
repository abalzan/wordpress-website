# Plan — Implement Infinite Scroll on /cursos/ by Reusing /lazer/

> Task-date: 2026-10-18 · Branch: master · Baseline SHA: 18a31f52f34ca4cad3e345cc77c269b5b9522d84

| | |
|---|---|
| **Task title** | Implement Infinite Scroll on /cursos/ by Reusing /lazer/ |
| **Date** | 2026-10-18 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | master (clean tree at baseline SHA) |
| **Applicable skill(s)** | `wp-repository` (orient, plan, hold scope, prefer shared engine) |
| **Plan status** | planned |

## 1. Scope

Convert `/cursos/` from the manual "Carregar mais" Load More (`data-load-more`)
to the same automatic Infinite Scroll (`data-infinite-scroll`) already used by
`/lazer/`, `/eventos/`, `/blog/` and `/guias/`. Implemented as a single
configuration-level retag in `archive.php`: move `$is_course_archive` from
`$load_more` into `$infinite_scroll`. No new endpoint, no new query, no new JS
subsystem — `initInfiniteScroll()` in `assets/js/main.js` is already registered,
content-type agnostic, and reads the next-page URL from the server-rendered
`.conexao-pagination`, so filters (`?categoria=`), language (`/en/cursos/`),
and course ordering are all preserved by construction.

## 2. Explicit non-goals

- No separate course-specific infinite-scroll implementation or custom AJAX/REST endpoint.
- No change to the course query, eligibility rules, ordering, cache lifecycle, or filter semantics.
- `/eventos/` stays infinite scroll; `/lazer/` stays infinite scroll; other archives unchanged.
- No Flutter/mobile repository work; no production writes or deploy.
- No History-API change beyond what `initInfiniteScroll` already does for all infinite-scroll archives.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/README.md`,
`docs/routing.md`, `docs/content-model.md`,
`docs/themes/conexao-br-irlanda.md`, `docs/frontend.md`, `docs/testing.md`,
`plugins.json` (unchanged — registry drift gate must pass).

## 4. Current-state findings (verified)

- `archive.php` L121-122: `$infinite_scroll = $is_guide_archive || $is_leisure_archive || $is_event_archive || is_category();` and `$load_more = $is_course_archive;`. Cursos grid is `data-load-more`.
- `assets/js/main.js` `initInfiniteScroll()` (L1667-1854) is generic:
  `data-infinite-scroll` + IntersectionObserver sentinel (rootMargin 480px) +
  `fetch` of the real `/page/N/` + `id="post-{ID}"` dedupe + append-only +
  `pushState` + `aria-live="polite"` status + manual retry + end-of-results
  (`finish()` disconnects observer) + `pageshow` bfcache guard.
  `initLoadMore()` (L1868-2010) is the manual-button variant for Cursos.
- `template-parts/provider-card.php` L49: `<article id="post-<?php the_ID(); ?>"` — the JS dedup guard works for course cards.
- `template-parts/pagination.php`: renders `.conexao-pagination` with `a.page-numbers.next` (CSS class order `next page-numbers`), the infinite-scroll next-URL source.
- Live stack: ONE Compose stack (`wordpress-website-wordpress-1` + `-db-1`) at `localhost:8080`. Polylang active.

## 5. Change specification

**File:** `wp-content/themes/conexao-br-irlanda/archive.php`

L121: `$infinite_scroll = $is_guide_archive || $is_leisure_archive || $is_event_archive || is_category();`
→ `$infinite_scroll = $is_guide_archive || $is_leisure_archive || $is_event_archive || $is_course_archive || is_category();`

L122: `$load_more = $is_course_archive;`
→ `$load_more = false;`

Update the comment block (L105-120) to reflect that Cursos now uses infinite scroll.

No JS, query, filter, cache, or plugin changes.

## 6. Regression guards (explicit verification)

- `/cursos/` grid: `data-infinite-scroll` present, `data-load-more` absent.
- `/eventos/` grid: `data-infinite-scroll` present, `data-load-more` absent (unchanged).
- `/lazer/` grid: `data-infinite-scroll` present, `data-load-more` absent (unchanged).
- Other archives (guides, sponsors, jobs, category): unchanged pagination behavior.
- No archive receives both `data-infinite-scroll` and `data-load-more`.
- Course cards carry `id="post-{ID}"` for duplicate detection.
- `.conexao-pagination` with `a.page-numbers.next` renders on `/cursos/`.

## 7. Tests

- **PHP in-process:** Extend/update `test-eventos-infinite-scroll.php` (cursos assertions flip from load-more to infinite-scroll) and add `test-cursos-infinite-scroll.php` focused on cursos tagging + pagination source + regression guards for eventos/lazer/other archives.
- **Real-browser:** `tests/browser/verify-cursos-infinite-scroll-browser.py` — desktop + mobile, page-2/3/4 each fired once, cards appended without duplicates, end-of-results, filter + language preservation, failure/retry, console/network clean.
- **Canonical suite:** `./scripts/run-tests.sh` — no new failures vs baseline.

## 8. Documentation

- `docs/themes/conexao-br-irlanda.md`: Move Cursos from Load More section to Infinite Scroll section.
- `docs/reports/2026-10-18-cursos-infinite-scroll-report.md`: Results + evidence.
- `docs/evidence/2026-10-18-cursos-infinite-scroll/browser-acceptance.log`: Full browser run.

## 9. Acceptance criteria

- `/cursos/` loads further course batches through infinite scrolling without a full-page refresh.
- The existing `/lazer/` and `/eventos/` implementations are reused (single `initInfiniteScroll`, no second pagination lifecycle).
- No duplicate requests or course cards during successive scrolling (`id="post-{ID}"` dedupe).
- Filtering, language, course ordering, and cache correctness preserved (no query/filter/cache code changed).
- Reliable end-of-results state and conventional no-JS pagination fallback remain.
- Real-browser verification on the intended local stack (`localhost:8080`).
- Focused tests, syntax checks, and checks executed and reported accurately.
- Documentation and evidence recorded.
- No production changes, no Flutter/mobile changes, no unrelated user changes.
