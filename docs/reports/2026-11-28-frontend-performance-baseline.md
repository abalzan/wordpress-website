# Report — frontend performance baseline (local, measured)

| | |
|---|---|
| **Stage / task name** | Frontend performance baseline + one evidence-backed optimisation |
| **Date** | 2026-11-28 |
| **Author / agent** | Cline (frontend/performance engineer) |
| **Branch** | `roadmap/performance-bilingual-security-phpstan` |
| **Start SHA** | `f97a8fe` |
| **Skills followed** | `wp-frontend-perf`, `wp-repository`, `wp-testing` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §3.2, `docs/frontend.md`, `docs/testing.md` |

## 1. Scope completed

A **reproducible, local-only measurement procedure** and the baseline it
produced, plus the one optimisation the measurements justified.

| Delivered | Where |
|---|---|
| Collector (Playwright/Chromium, local only) | `tests/browser/verify-frontend-performance-baseline.py` |
| Machine-readable baseline (10 routes × 2 viewports × 5 repeats) | `docs/evidence/2026-11-28-frontend-performance/perf-baseline.json` |
| Before/after evidence for the change | `docs/evidence/2026-11-28-frontend-performance/events-collapse-change.json` |
| One measured optimisation | `wp-content/themes/conexao-br-irlanda/inc/events.php` |

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| Production measurement | Not authorised, and a production number is not a baseline for a local stack |
| Lighthouse score / performance budget | Not measured → not claimed. No budget was invented; nothing here justifies one yet |
| Network/CPU throttling | The local stack has no shaping, so a throttle would be a fabricated profile rather than the environment |
| Fixing the two remaining bottlenecks | Both need semantics decisions (pagination/ordering, candidate-list narrowing) with their own invalidation matrix — documented in §6 instead of improvised |

## 3. Procedure (how to reproduce)

```bash
# local stack must be running
docker compose up -d

# 5 cold-context loads per route per viewport, writing the JSON evidence
python3 tests/browser/verify-frontend-performance-baseline.py --repeats 5 \
    --out docs/evidence/<date>-frontend-performance/perf-baseline.json
```

Per repetition the collector opens a **fresh browser context** (empty HTTP
cache), installs `PerformanceObserver` observers before navigation, loads the
route, waits for the layout to settle, drives **one real interaction** (the
header theme toggle, present at every breakpoint), and reads:

| Metric | Source |
|---|---|
| TTFB | Navigation Timing `responseStart` |
| LCP | `PerformanceObserver('largest-contentful-paint')`, final entry |
| CLS | `PerformanceObserver('layout-shift')`, sum of non-input shifts |
| INP **proxy** | longest `PerformanceObserver('event')` duration for the scripted activation |
| Requests / transfer | Resource Timing entries + `transferSize` |
| Largest assets | top five resources by `transferSize` |

`domcontentloaded` (not `load`) is the navigation wait: the page pulls a
third-party stylesheet, and `load` waits for it — a hung external subresource
must never turn into an invented latency number.

## 4. Baseline (medians of 5, local, unthrottled)

| Route | Viewport | TTFB | LCP | CLS | INP proxy | Requests | Transfer |
|---|---|---|---|---|---|---|---|
| `/` | desktop | 1706 ms | 1840 ms | 0.0 | 56 ms | 12 | 270 KB |
| `/` | mobile | 2077 ms | 2180 ms | 0.0 | 40 ms | 12 | 231 KB |
| `/en/` | desktop | 1433 ms | 1564 ms | 0.0 | 64 ms | 12 | 270 KB |
| `/en/` | mobile | 1296 ms | 1436 ms | 0.0 | 40 ms | 12 | 231 KB |
| `/guias/` | desktop | 117 ms | 208 ms | 0.1 | 24 ms | 11 | 145 KB |
| `/guias/` | mobile | 89 ms | 172 ms | 0.1 | 24 ms | 10 | 126 KB |
| `/en/guias/` | desktop | 119 ms | 220 ms | 0.1 | 24 ms | 11 | 145 KB |
| `/en/guias/` | mobile | 98 ms | 184 ms | 0.0 | 16 ms | 10 | 126 KB |
| `/empregos/` | desktop | 89 ms | 208 ms | 0.0 | 32 ms | 10 | 132 KB |
| `/empregos/` | mobile | 85 ms | 176 ms | 0.0 | 24 ms | 10 | 132 KB |
| `/en/empregos/` | desktop | 91 ms | 188 ms | 0.0 | 32 ms | 10 | 131 KB |
| `/en/empregos/` | mobile | 100 ms | 204 ms | 0.0 | 24 ms | 10 | 131 KB |
| `/eventos/` | desktop | 1734 ms | 1876 ms | 0.0 | 32 ms | 19 | 415 KB |
| `/eventos/` | mobile | 1475 ms | 1596 ms | 0.0 | 24 ms | 16 | 261 KB |
| `/en/eventos/` | desktop | 4313 ms | 4428 ms | 0.0 | 24 ms | 19 | 415 KB |
| `/en/eventos/` | mobile | 4244 ms | 4356 ms | 0.0 | 24 ms | 16 | 260 KB |
| `/lazer/` | desktop | 99 ms | 192 ms | 0.0 | 32 ms | 13 | 161 KB |
| `/lazer/` | mobile | 95 ms | 180 ms | 0.0 | 24 ms | 12 | 142 KB |
| `/en/lazer/` | desktop | 108 ms | 200 ms | 0.0 | 32 ms | 13 | 160 KB |
| `/en/lazer/` | mobile | 107 ms | 184 ms | 0.0 | 24 ms | 12 | 141 KB |

Reading it: guides, jobs and leisure are server-fast (≤120 ms TTFB) and their
LCP is dominated by the images they load. The two slow surfaces are **the
homepage** and **the event archive** — each dominated by one server-side query
(§5, §6). CLS is 0.0–0.1 everywhere, so no layout-shift work is justified. The
INP proxy is 16–64 ms, so no interaction work is justified.

The run straddled the optimisation in §5. That is safe to state precisely
because `conexao_event_collapse_logical_ids()` has exactly one caller
(`inc/archive-query.php`, the event archive `pre_get_posts` layer), so the
homepage, guides, jobs and leisure numbers cannot have been affected by it.


## 5. Optimisation implemented (evidence-backed)

**Target:** `conexao_event_collapse_logical_ids()` (`inc/events.php`), which the
event archive calls to keep one card per logical event.

**Measured cause:** the helper read three post-meta keys *per event* and called
`pll_get_post_language()` *per event*, without priming either cache. On the
local dataset (1,266 upcoming events) that was **2,533 queries / 0.82 s** — the
second-largest share of `/eventos/` server time after the main query.

**Change:** one `update_meta_cache( 'post', $ids )` and one
`wp_get_object_terms( $ids, 'language', array( 'fields' => 'all_with_object_id' ) )`
before the loops; the per-post reads become object-cache hits. On any
`WP_Error`/non-array result the code falls back to the previous per-post
`pll_get_post_language()` calls, so two languages can never be silently merged.

| Metric | Before | After |
|---|---|---|
| `collapse_logical_ids()` wall time | 0.821 s | **0.135 s** |
| Queries issued by the helper | 2,533 | **4** |
| Surviving ordered ID list | 1,266 ids | **1,266 ids — identical, element by element** |
| `/eventos/` TTFB (curl, same machine) | 2.09–2.70 s | **1.42–1.73 s** |

Trade-offs: `update_meta_cache()` pulls the listed posts' meta rows into the
object cache — for 1,266 events that is a few MB of request-local memory in
exchange for ~630× fewer round trips. `wp_get_object_terms()` reads Polylang's
`language` taxonomy, which is Polylang's public data model and is guarded by
`taxonomy_exists()`; if that storage ever changes, the fallback keeps the
previous behaviour.

Behaviour is proven unchanged by serialising the returned ID list before and
after the change and comparing it (`identical`, 1,266 ids).

## 6. Remaining bottlenecks (measured, deliberately not changed)

| Surface | Measured cause | Numbers | Why it was left |
|---|---|---|---|
| Event archive main query (`/eventos/`, `/en/eventos/`) | `WP_Query` with `post__in` over the upcoming ID list, joined with the Polylang term relationship and the `_event_status` meta gate | PT 0.99–1.35 s; EN TTFB 4.2–4.3 s (B2 widening makes the ID set ~3.2k) | Narrowing the ID set or caching the paginated result changes published ordering/pagination semantics and needs its own invalidation matrix — its own change, not a reviewable patch |
| Homepage (`/`, `/en/`) | **one** `WP_Query` takes ~1.0 s of the ~1.1 s render: `post__in` over ~1,300 event IDs joined with `wp_term_relationships` and `wp_postmeta` (`_event_status`), `LIMIT 2` (the hero/featured events slot) | TTFB 1.4–2.1 s | The candidate list must stay whole: the query itself applies the status/language filters, so slicing it before the query would change which events are selected |

Recommended follow-up (not implemented here): give the homepage's
featured-events slot and the event archive their own language-scoped result
cache, registered in the `docs/frontend.md` invalidation matrix, or replace the
`post__in`-over-thousands pattern with an indexed date/status lookup. Either is
a design change with its own review surface.

## 7. Limitations

1. **Local only.** No production request was made; the numbers describe this
   machine, this Docker stack and an unthrottled network. They are comparable
   between runs on this machine and not transferable to a phone on 4G.
2. **The dataset is a restored production snapshot** (3,803 published events,
   1,266 upcoming) — which is why the archive is the slow surface. A smaller
   dataset would understate it.
3. **INP is a proxy**, not field INP: one scripted activation per load, not a
   distribution of real interactions.
4. **CLS has no session-window segmentation**; the sum is enough to show there is
   nothing to fix here, but it is not the Core Web Vitals metric verbatim.
5. **No Lighthouse, no budget.** Neither was run nor set.
6. The homepage attribution names *the* dominant query; it does not claim the
   other 105–130 queries are free.

## 8. Status

**PASS WITH LIMITATION.** A reproducible baseline exists with real numbers, one
change is backed by a measured before/after and a proven-identical output, and
the two bottlenecks that need design decisions are documented instead of guessed
at. The limitation is §7: local, unthrottled, single-machine measurements.

_Last verified: 2026-11-28 by the frontend performance baseline_

