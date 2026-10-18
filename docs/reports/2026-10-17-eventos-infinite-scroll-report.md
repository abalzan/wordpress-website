# Report — Implement Infinite Scroll on /eventos/ by Reusing /lazer/

**Date:** 2026-10-17 · **Branch:** master (baseline 7c356f76) · **Status: PASS**

## Summary

Implemented automatic infinite scroll on `/eventos/` by reusing the existing
`/lazer/` implementation. The change is a single configuration-level retag in
`archive.php`: moved `$is_event_archive` from `$load_more` into
`$infinite_scroll`, so the Eventos grid is now tagged `data-infinite-scroll`
and the shared `initInfiniteScroll()` in `assets/js/main.js` binds to it. No
new endpoint, query, filter, cache, JS subsystem, or plugin changes.

## What was reused (and why)

The shared `initInfiniteScroll()` is content-type agnostic: it reads the next
page URL from the server-rendered `.conexao-pagination` (`a.page-numbers.next`),
fetches the real `/page/N/`, dedupes by `id="post-{ID}"`, appends cards
append-only, uses an IntersectionObserver sentinel (rootMargin 480px, no scroll
listeners), `pushState` per page, `aria-live="polite"` status, manual retry,
end-of-results disconnect, and a `pageshow` bfcache guard. Because it is
already generic and registered on `DOMContentLoaded`, retagging Eventos into
it gives `/eventos/` all of this behavior with zero new code. A separate
event-specific implementation would create a second pagination lifecycle —
expressly forbidden — and was unnecessary.

## Files changed

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/archive.php` | Retag Eventos into `$infinite_scroll`; remove from `$load_more`; update comment |
| `docs/themes/conexao-br-irlanda.md` | Infinite-scroll section now lists `/eventos/`; Load More section covers only `/cursos/` |
| `wp-content/themes/conexao-br-irlanda/tests/test-eventos-infinite-scroll.php` | NEW — PHP in-process suite (9 assertions) |
| `tests/browser/verify-eventos-infinite-scroll-browser.py` | NEW — real-browser acceptance (36 checks) |
| `docs/reports/2026-10-17-eventos-infinite-scroll-plan.md` | Implementation plan + results |
| `docs/evidence/2026-10-17-eventos-infinite-scroll/browser-acceptance.log` | Full browser run evidence |

## Files intentionally NOT changed

`assets/js/main.js`, `inc/archive-query.php`, `inc/queries.php`,
`inc/archive-filters.php`, `template-parts/event-card.php`,
`template-parts/pagination.php`, `wp-content/plugins/*`, `plugins.json`,
the Flutter/mobile repository, production.

## Verification results

**PHP in-process suite:** `test-eventos-infinite-scroll.php` — **9 passed, 0
failed**. Auto-discovered by `run-tests.sh`; asserts grid tagging for
Eventos/Lazer/Cursos, pagination next-link source, no server-rendered button.

**Real-browser acceptance** (Playwright/Chromium vs the serving stack at
`localhost:8080`, the `default` Compose context):
**36 passed, 0 failed** — covering desktop + mobile initial render; page-2/3/4
each fired exactly once (no request loops); cards appended 10→20→30→40 with
no duplicates; no JS console or network errors; county (`?county=laois`)
filter preserved across pages; `/en/eventos/` paginates within English; failed
page-2 request shows a recoverable error state and retry recovers; `/lazer/`
regression still infinite-scrolls.

**Existing suites unchanged:**
- `test-event-location-filters.php`: 62 passed / 1 failed
  (pre-existing data-dependent failure, unrelated)
- `test-i18n-foundation.php`: 29 passed / 0 failed

**Static checks:**
- `php -l` on `archive.php` and the new test: **No syntax errors** (both).
- `phpstan analyse` on `archive.php` alone: **No errors**.
- `./scripts/lint.sh`: PHPStan exit 1 — **pre-existing** (baseline also exits
  1; zero errors reference archive.php/eventos/infinite).
- `php scripts/generate-registry-docs.php --check`: exit 1 — **pre-existing**
  (untracked orphan plugin dirs; baseline also exits 1; `plugins.json`
  untouched by this change).

## Acceptance criteria — all met

- `/eventos/` loads further event batches through infinite scrolling without a
  full-page refresh (real-browser verified: 10→20→30→40 cards).
- The existing `/lazer/` implementation is reused (single `initInfiniteScroll`,
  no second pagination lifecycle).
- No duplicate requests or event cards during successive scrolling (page-2/3/4
  each requested exactly once; `id="post-{ID}"` dedupe).
- Filtering, language, date logic, event ordering, and cache correctness
  preserved (no query/filter/cache code changed).
- Reliable end-of-results state (observer disconnects + "Você chegou ao fim.")
  and conventional no-JS pagination fallback remain (pagination markup only
  hidden, never removed).
- Real-browser verification on the intended local stack (`localhost:8080`).
- Focused tests, syntax checks, and checks executed and reported accurately.
- Documentation and evidence recorded.
- No production changes, no Flutter/mobile changes, no unrelated user changes.

## Known limitation (shared, pre-existing, not introduced)

On small mobile viewports, once parked at the exact page bottom the shared
`initInfiniteScroll` re-arm delivers only on intersection *change*, so the
next batch fires after a further scroll-away-and-back (real user behavior).
Manually verified: mobile page-3 loads (20→30 cards) after scroll-away-and-back.
This is shared `/lazer/` behavior, not event-specific.

## Conclusion

PASS — all acceptance criteria satisfied and verified on the intended local
stack. No production writes, no Flutter/mobile changes, no unrelated user
changes touched.
