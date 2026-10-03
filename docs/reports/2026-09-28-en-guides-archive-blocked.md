# English Guides archive `/en/guias/` — root cause and blocked content rollout

**Date:** 2026-09-28 · **Repository:** `abalzan/wordpress-website` · **Branch:** `i18n`
**Starting SHA:** `726004a2d1abca6dd7ebe35c8a1ca468a24e825d`
**Evidence:** [`docs/evidence/2026-09-28-en-guides-archive/`](../evidence/2026-09-28-en-guides-archive/)

## Final status

**BLOCKED — EN guide content is required but absent.**

`/en/guias/` is **not** a query bug, not a template bug, not a routing bug and
not a taxonomy bug. The route, the EN shell, the canonical, the hreflang set and
the query all work exactly as the documented content model requires. The archive
is empty because **the local database contains 0 EN `guide` records**, and
Guides are a **B1** type that requires real, authored EN translations — PT
records are never allowed to appear under `/en/guias/`.

This task was therefore stopped at the content boundary. No EN guides were
fabricated, no query was widened, no gate was weakened, and **no code-only
commit was created** (there is no code defect to fix).

## Root cause

**Missing EN data** (Case B in the diagnostic taxonomy), and it is *correct*
behaviour under the established model — not a defect in itself.

Proven at `pre_get_posts` priority 999 with a temporary probe (since removed):

```
/guias/    pll_current_language=pt  is_b2_post_type(guide)=false  lang='pt'  FOUND=48  post_count=10
/en/guias/ pll_current_language=en  is_b2_post_type(guide)=false  lang='en'  FOUND=0   post_count=0
```

The EN request asks Polylang for `lang='en'` and receives 0 rows because 0 rows
have that language. The same `post_type='guide'` query, the same template
(`archive.php`), an empty `tax_query` and a working EN shell — only the data is
missing. There is no filter to remove: `conexao_is_b2_post_type('guide')` is
`false` **by design**, so `conexao_b2_widen_query_args()` is (correctly) never
reached for guides and no widening is permitted.

## Content model

**Guides are B1 — real EN translations are required.** Evidence, three
independent sources, all in the repository:

| Source | Statement |
|---|---|
| `inc/i18n/fallback.php:271` | "B1 = Guides, Blog posts, key narrative static pages: keep the approved 302 policy until a real translation exists (**never render fallback**)." |
| `inc/i18n/fallback.php:276` | `conexao_b2_post_types()` returns `event, leisure, sponsor, course_provider, job` — `guide` is **absent** |
| `docs/routing.md:436` | "B1 types (**guide**, post) get real EN records only" |
| `docs/routing.md:49` | `/en/guias/` is a real English archive **since Stage 9**: one linked EN `guide` per public PT guide |

So the answer to the critical question is unambiguous: **the archive is empty
because the local database has no EN guide records, and that is the documented,
enforced contract.** Applying `conexao_b2_widen_query_args()` here would be
precisely the "B1 becomes B2" policy change the task forbids.

## Data inventory

Measured against the local Docker WordPress (not assumed):

| Metric | Count |
|---|---:|
| Published PT guides | **48** |
| Published EN guides | **0** |
| PT guides with EN translation | **0** |
| PT guides without EN translation | **48** |
| Orphan EN guides | **0** |

Corroborating evidence: `post_translations` relationships touching a guide = 0;
`conexao_category` EN terms = 0 (PT: 40 existing, 13 actually used). The
permanent translation-completeness gate independently reports
`post_type:guide:missing_en: 48` with `translated: 0, allowlisted: 0`.

The previously noted "48 PT / 0 EN" state is still current.

## Individual EN guide URLs

- `/en/guias/<pt-slug>/` → **302 → `/guias/<pt-slug>/`** — the documented B1
  replaced-master policy, working as designed.
- `/guias/<pt-slug>/` → **200**.
- There is **no** guide that has an EN translation, so the second required
  sample ("one guide that has an EN translation") could not be taken. This is
  explicitly documented rather than substituted.

## Implementation

**None. No source file was modified.** The proven fix for an empty
`/en/guias/` is a content rollout, which this task explicitly forbids without
separate authorisation.

The content rollout that is required is **already implemented and dormant**:
`conexao-guide-translation` (registry class `rollout`, status `retired`,
`production: no`) with the authored English already committed
(`includes/guides-a…d.php` manifest + `includes/body-*.php` bodies +
`includes/terms.php`). It failed its own in-process suite here with
`insufficient data: activate-plugin:conexao-guide-translation` — the plugin is
inactive because the rollout has not been executed. Re-running it is a data
operation through the **existing** engine, not a new translation engine.

## PT regression

Not applicable — no code changed. Verified unchanged as-is:

- `/guias/` 200, 10 cards/page, IDs 5001, 5000, 10007, 10001… in the same order
- `/guias/?categoria=financas` 200, 3 cards
- `/guias/page/2/` 200 (48 guides, 5 pages)
- 0 console errors, 0 horizontal overflow, desktop + mobile

## HTTP

| Route | Status | Cards | Note |
|---|---|---:|---|
| `/guias/` | 200 | 10 | canonical, 5 pages |
| `/en/guias/` | 200 | **0** | self-canonical, full en/pt-BR/x-default hreflang, EN shell |
| `/en/guias/page/2/` | **404** | — | correct: 0 EN records exist, so page 2 legitimately does not |
| `/en/guias/?categoria=documents` | 200 | 0 | EN term absent (content) |
| `/en/guias/<pt-slug>/` | 302 | — | → PT guide (B1 policy) |

Acceptance expectations were **not** changed.
`tests/acceptance/matrices/guides-en.json` still expects `/en/guias/page/2/` =
200 and an EN guide link; that suite correctly fails on missing content and is
left red on purpose.

## Browser

Chromium via Playwright, desktop 1440×900 and mobile 390×844, PT and EN archive
plus one filter each: **0 console errors, 0 PHP warnings, 0 horizontal
overflow**, correct `lang` attribute and self-canonical on EN. The EN page is
not blank — it renders the shell and the "No guides found" empty state, which
is the truthful rendering of an archive with no records.

have that language. The same `post_type='guide'` query, the same template
(`archive.php`), an empty `tax_query` and a working EN shell — only the data is
missing. There is no filter to remove: `conexao_is_b2_post_type('guide')` is
`false` **by design**, so `conexao_b2_widen_query_args()` is (correctly) never
reached for guides and no widening is permitted.

## Tests

`./scripts/run-tests.sh` (unchanged baseline, no new failures introduced):

| Suite group | Total | Passed | Failed |
|---|---:|---:|---:|
| In-process PHP | 60 | 42 | 18 (2 prerequisite) |
| Assertions | — | 2949 | 71 |
| Script-contract | 6 | 6 | 0 |
| HTTP acceptance (`verify-guides-en-http`) | — | — | 2 rows (content-driven) |
| HTTP acceptance (`verify-release-http`) | — | 42 | 0 |
| HTTP acceptance (`verify-routing-http`, re-run standalone) | — | 86 | 3 |

The 18 failing in-process suites are **pre-existing** and unrelated to guides
(event importer, leisure migration prerequisites, blog/i18n, jobs, stage41/45
fixtures). `verify-guides-en-http` fails on exactly the two content-driven
rows: `/en/guias/page/2/` (404 vs 200) and "an EN guide link is discoverable".

All three **guide** routing rows pass: `pt-200-guias`, `en-200-en-guias` and
`legacy-guides`. The 3 routing failures are the pre-existing lazer card-excerpt
fragments (one PT, one EN) and `en-home-no-pt-content-type-label` — no guide row
is among them.

`test-guide-en-translation.php` fails on its prerequisite
(`activate-plugin:conexao-guide-translation`) — the rollout was never run.

**0 new unrelated failures.**

## Gates

All seven permanent gates, `python3 scripts/verify-permanent-gates.py`:

| # | Gate | Result |
|---|---|---|
| 1 | taxonomy policy | **PASS** — 18 passed, 0 failed, 0 violations |
| 2 | translation completeness | **FAIL** — 48 violations, **48 pre-existing, 0 new** (`post_type:guide:missing_en`) |
| 3 | cache scoping (runtime) | **PASS** — 9 passed, 0 failed |
| 4 | cache scoping (static) | **PASS** — 2 passed, 0 failed |
| 5 | redirect precedence | **PASS** — 15 passed, 0 failed |
| 6 | documentation drift | **PASS** — 14 passed, 0 failed |
| 7 | i18n catalogue freshness | **PASS** — 8 passed, 0 failed |

Aggregate: 6 passed, 1 failed · assertions 85 passed, 1 failed · violations 48
(48 pre-existing, **0 new**). No gate, threshold, baseline or allowlist was
edited. The missing-EN-guide finding remains visible, as required.

## Negative tests

Not applicable — no query boundary was changed, so there is nothing new to
prove. The existing `conexao_b2_widen_query_args()` safeguards were verified by
reading and are intact: it returns early on an explicit `lang`, on inactive
Polylang, on a non-`en` request, and on any post type that is not B2 (so
`guide` can never be widened). All temporary diagnostic files were removed
(`mu-plugins/diag-mu.php`, `/tmp/diag*.php`); the two pre-existing mu-plugins
are untouched.

## Required follow-up (separate, authorised content rollout)

1. Activate `conexao-guide-translation` and run its documented admin screen /
   `tests/run-guide-translation.php` (dry run → apply → verify).
2. That creates **48 linked EN guides** + **13 linked EN `conexao_category`
   terms** using the already-authored English. PT is read-only and the engine's
   own `pt_changed` gate must stay 0.
3. Re-run the gates: translation-completeness should move 48 → 0, and
   `verify-guides-en-http` should go green including `/en/guias/page/2/`.
4. Only then may the retired plugin be deactivated/removed per its lifecycle.

## Production

**No production writes or deployment were performed.** All work was local
Docker/WordPress, read-only queries plus temporary local diagnostics.

## Flutter

**Flutter/mobile repository was not accessed.**

enforced contract.** Applying `conexao_b2_widen_query_args()` here would be
precisely the "B1 becomes B2" policy change the task forbids.

