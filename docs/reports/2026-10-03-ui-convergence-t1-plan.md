# Plan — UI Convergence Phase 1 (T1.1–T1.5), Direction A "Converged Minimal"

| | |
|---|---|
| **Task title** | UI convergence Phase 1: fix the `/empregos/` orphan grid, duplicate event cards, section-heading / card-title divergence and the orange contrast defect |
| **Date** | 2026-10-03 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `6ec382bba663e21c5dc90b90fac01f61afe82d36` (merge of `i18n`; identical to the audit SHA, **re-verified, not assumed**) |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Implement the **first controlled convergence phase** of
[`docs/audit/2026-10-03-ui-ux-audit.md`](../audit/2026-10-03-ui-ux-audit.md),
following its Direction A ("Converged Minimal"): enforce the design system the
site already has and fix the two genuine defects, without redesigning anything.
Concretely **T1.1–T1.5** only:

| Item | Defect | Change |
|---|---|---|
| **T1.1** | D1 — `/empregos/` orphan 340px column, ~812px dead space | Make `.empregos-landing` a two-column grid **only when the portrait media is present**; collapse to a readable single column when absent. CSS + one template class. |
| **T1.2** | D2 — `/eventos/` renders 10 cards for 6 logical events | Stop one logical event occupying more than one archive slot, surfacing the next occurrence as metadata. Existing event model and query helpers only. **No new recurrence engine.** |
| **T1.3** | I1 — section `h2` ranges 16.8 → 40px (2.4× spread) | Converge onto the existing `.section-title` token scale, **removing** per-page overrides rather than layering more. Hero/title treatments preserved. |
| **T1.4** | I2 — card titles at 17/600, 18/600, 18/700, 18.4/700 | One token-based card-title rule; delete the off-scale `1.0625rem` declarations causing the 17px treatment. |
| **T1.5** | D3 — `#F68B1F` = **2.43:1** on white, used as text in 17 rules | Restrict orange to accents/surfaces; move orange **text** onto an existing sufficiently-dark token. No brand recolour, no new arbitrary colour. |

Visual identity, palette, Poppins+Inter, the 1200px container, navigation,
filter mechanics + ARIA, dark mode and the theme architecture are **preserved**.

## 2. Explicit non-goals

Named so a reviewer does not read them as oversights:

- **No T2 shared-card refactor** (audit T2.1). Out of scope unless T1.3/T1.4
  cannot be expressed without a tiny shared abstraction — and even then only at
  utility-class level.
- **No homepage redesign** (T1.14), **no navigation change**, **no filter
  redesign** (the audit itself calls the filter UX a genuine strength).
- **No image-ratio unification** (I5), **no card padding/radius unification**
  (I3/I4 = T1.7), **no breakpoint rationalisation** (I12/T2.7).
- **No `h1 → h4` heading-jump fixes** (D4/T1.6) and **no touch-target work**
  (D5/T1.6): separate Tier-1 items needing their own evidence. This phase must

## 3. Relevant authoritative documents

| Fact | Single authoritative source |
|---|---|
| Design tokens, typography, component classes | `assets/css/design-system.css` + the `assets/css/main.css` palette block |
| Documented frontend contract (container, breakpoints, CSS load order) | [`docs/frontend.md`](../frontend.md) |
| Theme internals, templates, event runtime relationship | [`docs/themes/conexao-br-irlanda.md`](../themes/conexao-br-irlanda.md) |
| Engineering rules, change safety, definition of done | [`docs/engineering-standard.md`](../engineering-standard.md) |
| Test model, permanent gates, commands | [`docs/testing.md`](../testing.md) |
| Routes, `/en/` layer, B1/B2 policy | [`docs/routing.md`](../routing.md) |
| Plugin registry | [`plugins.json`](../../plugins.json) |
| **The findings being fixed** | [`docs/audit/2026-10-03-ui-ux-audit.md`](../audit/2026-10-03-ui-ux-audit.md) |

The audit is the **source analysis**; this plan and the report that follows are
the **implementation record**. The audit is not rewritten.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (owns the loop), `wp-frontend-perf` (CSS/token discipline), `wp-testing` + `wp-write-in-process-test` (regression tests), `wp-update-docs` |
| **Its preconditions** | Read `AGENTS.md` + engineering standard before editing; confirm branch and `git status`; record uncommitted work that is not mine |
| **Its guardrails that bind this task** | No second source of truth; no generated region hand-edited; tokens are the only source for visual values; no JS for purely visual behaviour; no production writes; never touch the Flutter/mobile repository |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check` |
| **Its evidence location** | `docs/evidence/2026-10-03-ui-convergence-t1/` |

## 4. Current-state findings (verified, not assumed)

Baseline `6ec382b`. The working tree carried three uncommitted audit artefacts
(`docs/audit/2026-10-03-ui-ux-audit.md`, `docs/evidence/2026-10-03-ui-audit/`,

**T1.2 — the audit's stated cause is WRONG, and this materially changes the
fix.** The audit (D2) attributes the duplication to "recurring occurrences each
emit their own card". The evidence contradicts it:

1. The duplicate cards are **distinct post IDs** (1196/1197, 1200/1201,
   1204/1205, 1208/1209) — not one post rendered twice by a loop bug.
2. Those posts carry **no recurrence meta whatsoever**
   (`_event_recurrence_*` / rrule / occurrence keys return zero rows).
3. Duplicates share an **identical `_event_source` + `_event_source_id`**
   (e.g. `laois_tourism` / `10015791-1791021600-1791043200@laoistourism.ie`),
   and identical `_event_url`, `_event_date`, start and end time.
4. **13** such duplicate-identity groups exist among published events.
5. Both members of each pair are language `pt`, so this is **not** a PT/EN
   translation pair — `154` pt / `155` en legitimately share `pilot-eb-0001`
   and must never be collapsed.
6. Recurrence is already correct and collapses to ONE post:
   `inc/archive-query.php:32` documents "a recurring event appears exactly once
   (it is ONE post — no occurrence posts exist)", and every Laois weekly-series
   post here carries recurrence meta instead of multiple posts.
7. **An existing acceptance suite already asserts the desired behaviour and
   fails at baseline**: `tests/acceptance/verify-event-occurrence-http.py`
   reports `exactly one weekly series is listed in the Laois archive -- found 5`,
   `exactly one multi-day event ... found 2`, `exactly one all-day event ...
   found 3`, and `multi-day detail page is 200 -- status=404`.

**Conclusion.** The duplication is *duplicate persisted records sharing one
source identity* — an importer identity/deduplication concern — **not**
occurrence expansion, **not** archive-query fan-out and **not** a rendering-loop
bug. The archive query faithfully returns the IDs the runtime hands it.

The correct, minimal, non-destructive Phase-1 fix is therefore to make the
**archive presentation** render one card per logical source identity — keeping
the richest / most-recently-checked record and surfacing the next occurrence as
metadata — leaving the data, the importer and the recurrence engine untouched.
Deduplicating the stored records instead is an importer/content change that
belongs to a different phase and would need the §5.2 six-step content contract.

**T1.3/T1.4/T1.5 confirmed** by grep: `.section-title` is
`clamp(1.5rem, 3vw, 2rem)` at `design-system.css:98-108` and is overridden or
ignored elsewhere; `1.0625rem` appears **14×** in `main.css`; `--conexao-accent`
`#F68B1F` is used as a text colour in many rules.

### Baseline test run (real numbers, before any edit)

## 5. Proposed approach

**T1.1 — layout logic, not a fake image.** Rather than hard-coding a media
query on the assumption the portrait is missing, the template adds a modifier
class (`.empregos-landing--with-media`) **only when `has_post_thumbnail()` is
true**, and the two-column track list moves onto that modifier. The base rule
becomes a single column constrained to the shared reading measure. This makes
the layout correct *by construction* whether or not the optional media exists,
and it automatically stays correct on PT and EN (same template) and on mobile
(the existing single-column rule already wins there). **No placeholder or fake
image is introduced.**

*Rejected:* `:has(.empregos-landing-media)` (broad browser-support risk for a
core layout); leaving the grid and adding a spacer (preserves the defect);
inventing art (forbidden by the brief).

**T1.2 — one logical event per archive slot.** A small theme-side helper
collapses a list of event IDs to one representative ID per
(`_event_source`, `_event_source_id`) identity, **scoped to the current
language** so a PT record and its EN translation are never collapsed into one
another. Where a logical event has more than one upcoming date, the existing
recurrence/next-occurrence helpers (`conexao_event_display_date()`,
`conexao_event_is_recurring()`) supply the next occurrence as card metadata —
the information the duplicates were carrying, without repeating the card. It
consumes `conexao_event_upcoming_ids()`; it does **not** re-query, re-sort or
re-implement recurrence.

*Rejected:* deleting or merging database rows (a content change needing the
six-step contract, and destructive); a second recurrence engine (explicitly
forbidden); collapsing by *title* (titles are not identity and would merge
genuinely distinct events); de-duplicating inside `Conexao_Event_Query`
(that would alter the runtime's output contract and its cache semantics for
every consumer).

**T1.3 — one section-heading scale, hero preserved.** Promote the existing
`.section-title` token scale to the single authority for *section* headings and
remove the per-page `font-size` overrides that produced 17/18/16.8/28px. The
deliberately expressive 40px treatments that are part of a **hero** (courses,
events, home) are reclassified onto an explicit `.hero`-scoped/hero class so
they remain expressive without competing with generic section headings. Page
`h1` remains its own clamp.

**T1.4 — one card-title treatment.** Delete the off-scale `1.0625rem`
declarations on card titles and let the existing `.card-title`-family rule in
`design-system.css` (Poppins, weight 600, line-height 1.3) be the single
source, giving it an explicit token-based `font-size`. Long PT/EN titles keep
existing line clamping; `-webkit-line-clamp` behaviour is preserved and
overflow is re-measured in the browser.

**T1.5 — orange restraint, not recolour.** Introduce **one** semantic token for
"accent-coloured text on light surfaces" that reuses an existing palette entry
(`--conexao-accent-dark` / a darkened accent already present) which reaches
≥4.5:1 on white and on the muted surface. Orange stays exactly as it is for
borders, rules, focus rings, decorative fills, and CTA backgrounds where the
text on top is contrast-checked separately. Every orange **text** declaration is
moved to that token, and the result is verified in **both** light and dark.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/page-empregos.php` | Add the `--with-media` modifier class conditionally | T1.1 needs the DOM to state whether optional media exists |
| `wp-content/themes/conexao-br-irlanda/inc/events.php` | Add the language-scoped logical-identity collapse helper | T1.2 |
| `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` | Apply the collapse to the event archive ID list | T1.2 |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-card.php` | Show next-occurrence metadata when an event has several | T1.2 (metadata, not a repeated card) |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Grid fix; heading override removal; card-title off-scale removal; orange text → accessible token | T1.1, T1.3, T1.4, T1.5 |
| `wp-content/themes/conexao-br-irlanda/assets/css/design-system.css` | Section-title / card-title token values; accent-text token | T1.3, T1.4, T1.5 |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | Dark-mode counterpart for the new/changed tokens only | T1.5 |
| `wp-content/themes/conexao-br-irlanda/tests/test-*.php` (new) | Regression tests for the duplication mechanism and the layout/heading/card tokens | T1.2 + guard rails |
| `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/README.md` | Record the materially changed design-system rules | §17 |

## 8. Content / data impact

- Records created / updated / deleted: **none**.
- Portable identifier strategy: not applicable — no cross-environment identity is
  introduced. Logical-event grouping uses the **portable source UID**
  (`_event_source` + `_event_source_id`), never a local post ID.
- Content write: **no**. The six-step §5.2 contract does not apply.
- PT content changed: **no**.

## 9. Route / HTTP impact

- Routes added / removed / changed: **none**.
- Redirects needed: **none**.
- Sitemap coverage: unchanged (same URLs; the duplicate records were never
  individually sitemapped as distinct logical events).
- HTTP matrix rows: one new row asserting a **single** logical event per slot on
  `/eventos/`, plus `/empregos/` and `/en/empregos/` 200 assertions.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- Is PT immutable in this change: **Yes.**
- B1/B2 per destination: unchanged. The event archive keeps its existing B2
  widening (`lang=en,pt`) and its "never show a PT record beside its own EN
  translation" rule.
- Canonical / hreflang / language-switcher: unchanged.
- Language-scoped cache keys affected: **none**. The helper post-processes an
  already language-curated ID list; it adds no transient and no cache of its own,
  so it cannot collide across languages.
- The collapse is **language-scoped by construction**, so a PT record and its EN
  translation (`154`/`155`) can never collapse into one another.

## 11. Security impact

- Capabilities checked: unchanged — public archive presentation.
- Nonces added/changed: **none**.
- Sanitisation / escaping: the helper yields only existing values, printed
  through the template's existing escaping functions.
- SQL: WP APIs only; **no new direct SQL**.
- REST surface change: **none**.
- Secrets: **none**.

## 12. Performance impact

- Queries: **no new query.** The collapse operates on the in-memory ID list the
  runtime already produced, reading identity keys for the current page only.
- Caching: **no new transient**, so no new key and no new invalidation surface.
- Assets/images: no new asset, no new image, no JS, no inline `<style>` block.
  CSS-only and template-logic changes, per the audit's finding that no visual
  work justifies JavaScript.
- Expected improvement: fewer cards per page (DOM reduction), neutral-to-positive
  for paint. Measured as card/DOM counts in the before/after browser runs.


## 15. Acceptance matrix plan

| id | url | expect_status | expect_contains | note |
|---|---|---|---|---|
| `event-archive-single-logical-event` | `/eventos/` | 200 | `event-card` | each logical source identity renders at most one card |
| `event-archive-no-duplicate-titles` | `/eventos/` | 200 | — | no title appears twice in one page of results |
| `event-archive-en-single-logical-event` | `/en/eventos/` | 200 | `event-card` | same rule under the EN shell |
| `jobs-landing-no-orphan-column` | `/empregos/` | 200 | `empregos-landing` | grid collapses when the portrait is absent |
| `jobs-landing-en-no-orphan-column` | `/en/empregos/` | 200 | `empregos-landing` | same under EN |

## 16. Rollback plan

Every change is CSS or theme-template logic on `master`, so rollback is a single
`git checkout --` of the touched paths (or reverting the one commit).
**No data migration, no content change, no production state and no cache
invalidation to undo.** Pre-change state captured: baseline test log,
before-run browser JSON/screenshots, and the `git rev-parse HEAD` value — all
stored under `docs/evidence/`.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| [`docs/frontend.md`](../frontend.md) | Record the converged section-heading and card-title scales and the accent-text token | — |
| [`docs/themes/conexao-br-irlanda.md`](../themes/conexao-br-irlanda.md) | Record the `/empregos/` media-conditional layout and the event archive's logical-identity presentation | — |
| `docs/reports/2026-10-03-ui-convergence-t1-report.md` | This phase's implementation record | — |
| `docs/README.md` | Index the new report/plan per the index convention | — |
| `docs/audit/2026-10-03-ui-ux-audit.md` | **Unchanged** — source analysis | — |

## 18. Release implications

- Component version bumps: none — **no plugin changed**, so `plugins.json` and
  plugin versions are untouched.
- Effect on the artifact allowlist (derived from `plugins.json`): none.
- Is a release in scope: **no**. Not requested, not authorised.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Doing too much** — drifting into a general redesign or T2 card refactor | Medium | Scope control in §2; only the named files change; cleanup limited to code directly related to the implemented changes |
| Collapsing two genuinely distinct events that share a source UID | Low | Group only on the **full** (`_event_source`,`_event_source_id`) pair and only within one language; never group by title or date |
| Removing an expressive hero heading during heading convergence | Medium | Hero treatments explicitly reclassified/preserved; verified at 1440 and 390 |
| The orange fix making the brand look different | Medium | Orange retained for accents/borders/CTAs; only **text** usage moves; delta shown before/after |
| Dark mode regressing from a light-theme-only token change | Medium | Every colour change re-measured with `data-theme="dark"` in the browser run |
| Breaking pagination / result counts by filtering the ID list | Medium | Verify `/eventos/` pagination and county filters in the browser; collapse is order-preserving |
| Long EN titles overflowing after the card-title change | Medium | Re-measure title overflow/wrapping at 1440/1024/768/390 in both languages |

## 20. Verification gates

1. `./scripts/run-tests.sh` — full suite; failing-suite set must equal baseline.
2. `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan.
3. `php scripts/generate-registry-docs.php --check` — zero writes.
4. `python3 ui-convergence-work/verify.py after` — T1.1–T1.5 measured at
   1440/1024/768/390, PT + EN, light + dark; zero horizontal overflow; exactly one
   `h1` and one `<main>`; zero duplicate event titles.
5. `python3 tests/acceptance/verify-event-occurrence-http.py` — the pre-existing
   "exactly one … in the Laois archive" assertions must go from failing to passing.

## 21. Completion criteria

Per [`docs/engineering-standard.md`](../engineering-standard.md) §14: the working
tree contains only the files §6 named; every applicable gate above was run and its
real numbers are in the report; pre-existing failures are separated from new ones;
scope was not silently widened; no production write occurred; and the
Flutter/mobile repository was never touched.

---

_Planned: 2026-10-03 — UI convergence Phase 1 (T1.1–T1.5)_

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | Not applicable — no production action authorised or taken |
| Verification of the production result? | Not applicable; verification is local-only |

## 14. Test plan

- In-process suites to add: a new theme suite proving (a) the logical-identity
  collapse returns one ID per source identity, (b) it is language-scoped so a
  PT/EN translation pair is never collapsed, (c) distinct source identities are
  all preserved, (d) recurring date semantics are unchanged (next occurrence
  still resolves), (e) the `/empregos/` template emits the modifier class only
  when a featured image exists.
- Acceptance area / matrix rows: single-card-per-logical-event on `/eventos/`
  and `/en/eventos/`; 200 assertions on `/empregos/`, `/en/empregos/`.
- Script contract impact: none expected; the full runner is still executed.
- Registry drift: `php scripts/generate-registry-docs.php --check` must report
  zero writes.
- Pass criterion, numerically: the **set of failing suites at the end equals the
  baseline set**; failed assertions ≤ baseline (4); all new suites and new
  acceptance assertions pass.


## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `wp-content/plugins/conexao-event-runtime/**` | Owns recurrence evaluation and the upcoming-event contract. T1.2 must consume it, never alter it |
| `wp-content/plugins/conexao-event-importer/**` | Importer/identity/dedup is the root cause but belongs to a separate, content-touching phase |
| `wp-content/plugins/conexao-translation-*/**`, `conexao-en-translation/**` | No translation or Polylang change |
| `wp-content/plugins/conexao-data-model/**` | Jobs/event data model unchanged |
| `.htaccess`, `inc/seo/redirects.php` | No route or redirect change |
| `plugins.json` | Plugin set unchanged |
| `tests/fixtures/**`, `docker/**` | No fixture or CI change |
| Any `docs/audit/2026-10-03-ui-ux-audit.md` line | The audit is the source analysis, not this report |
| The Flutter/mobile repository | Out of scope entirely |


`./scripts/run-tests.sh` → **exit 1**:

| Layer | Total | Passed | Failed |
|---|---|---|---|
| In-process PHP suites | 81 | 52 | 29 (4 counted as "prerequisite failures") |
| Assertions | — | 3097 | 4 |
| Script-contract suites | 18 | 17 | 1 |
| HTTP acceptance suites | 4 | 3 | 1 |

This is the **baseline**, kept verbatim. The 29 in-process failures are dominated
by translation/automation suites plus the known `verify-stage14-secret-scan.py`
**environmental** failure (the PHP runtime scanner cannot reach local WordPress
from the host shell) that the audit already recorded as pre-existing debt. The
**diff of failing lists at the end must be empty** — that, not a green run, is
the pass criterion.

plus two `docs/*README.md` edits) — **not mine, left untouched**.

Local site reachable at `http://localhost:8080` (Docker Compose up).

**T1.1 confirmed.** `curl /empregos/` shows `.empregos-landing` rendered with
**no** `.empregos-landing-media` child, while `main.css:6891` declares
`grid-template-columns: minmax(220px,340px) minmax(0,1fr)`. The body therefore
lands in the 340px column. Browser-measured `deadSpacePx` is captured in the
before-run.

  not make them worse, but does not fix them.
- **No leisure placeholder removal** (D6/T1.12), **no event date-badge
  simplification** (T1.9), **no duplicate county row merge** (D7/T1.8).
- **No removal of database records**, no collapse of distinct events, no change
  to the jobs data model, routes, importer behaviour or translation data.
- **No production change of any kind.**
