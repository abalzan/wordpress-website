# Report — UI convergence Phase 1 (T1.1–T1.5)

| | |
|---|---|
| **Stage / task name** | UI convergence Phase 1 — Converged Minimal (audit T1.1–T1.5) |
| **Date** | 2026-10-03 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `master` |
| **Start SHA** | `6ec382bba663e21c5dc90b90fac01f61afe82d36` (i18n merge) |
| **Final SHA** | `6ec382b` (changes uncommitted in the working tree at time of writing) |
| **Working tree at finish** | dirty — only the files in §3, **plus two files that are NOT mine** (see the note below) |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `docs/routing.md`, `docs/audit/2026-10-03-ui-ux-audit.md`, `plugins.json` |

## 1. Scope completed

All five items of the first controlled convergence phase were implemented and
verified. The site's existing visual identity is intact.

| Item | Audit defect | Result |
|---|---|---|
| **T1.1** | D1 `/empregos/` orphan column | **Fixed.** Dead space **860px → 0px** (1440) and **636px → 0px** (1024); content column **340px → 748px** |
| **T1.2** | D2 duplicate event cards | **Fixed.** `/eventos/` went from **10 cards / 6 distinct titles** to **10 cards / 10 distinct titles**, PT and EN, every width |
| **T1.3** | I1 section-heading divergence | **Structural fix.** Root cause (a bare `h3` rule overriding the component scale) removed; heading roles now distinct and intentional |
| **T1.4** | I2 card-title divergence | **Fixed.** **4 treatments → 1** (`18px/600/23.4px`) across all 13 measured pages |
| **T1.5** | D3 orange contrast failure | **Fixed for light theme.** All **240** failing light-mode orange-text occurrences eliminated; hero accent text lifted to ≥4.27:1 |

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| T2 shared-card component (audit T2.1) | Explicitly out of scope for this phase. T1.3/T1.4 were solved with a shared **selector list + tokens**, which is the small reusable abstraction, not a component rewrite |
| T1.6 heading jumps (D4), touch targets (D5) | Separate Tier-1 items needing their own evidence. Measured to confirm they were not made worse (§9) |
| T1.7 card padding/radius, I5 image ratios, T2.7 breakpoints | Explicitly out of scope |
| T1.12 leisure placeholders (D6), T1.8 county row (D7), T1.9 date badge | Explicitly out of scope |
| **Root-cause fix for the duplicate event records** | The duplicates are real duplicate DB rows created by the importer. Fixing the data requires the §5.2 six-step content contract and importer changes; this phase fixed the **presentation** only, non-destructively. See §5. |
| Deduplication of `Conexao_Event_Query::upcoming_event_ids()` itself | Deliberately left alone: it is the runtime's shared output contract consumed by 6+ callers, and altering it would change cache semantics site-wide. The collapse is applied at the archive boundary instead |


## 7. The T1.2 finding: the audit's diagnosis was wrong

This is the most important result of the phase, and it changes what Phase 2
should be.

The audit (D2) attributes the duplicate cards to *"recurring occurrences each
emit their own card"*. **That is not what is happening.** Verified against the
live data:

| Test | Result |
|---|---|
| Are the duplicate cards one post rendered twice? | **No** — distinct post IDs (1196/1197, 1200/1201, 1204/1205, 1208/1209) |
| Do they carry recurrence meta? | **No** — `_event_recurrence*` / rrule / occurrence keys return **zero rows** for all of them |
| Do they share one identity? | **Yes** — identical `_event_source` (`laois_tourism`) **and** `_event_source_id` (e.g. `10015791-1791021600-1791043200@laoistourism.ie`), identical `_event_url`, `_event_date`, start/end time |
| How many groups? | **13** duplicate-identity groups among published events |
| Is it a PT/EN translation pair? | **No** — both members are language `pt` (the legitimate pair `154` pt / `155` en shares `pilot-eb-0001` and is preserved) |
| Is recurrence broken? | **No** — a recurring event is ONE post; `inc/archive-query.php:32` already documents "a recurring event appears exactly once". All 5 Laois weekly series carry recurrence meta, not multiple posts |
| Was the archive query fanning out? | **No** — it returns exactly the IDs the runtime handed it |

**Conclusion:** the duplication is **duplicate persisted records sharing one
source identity** — an importer identity/dedup concern. Occurrence expansion,
archive querying and rendering were each investigated and each exonerated.

The implemented fix is therefore presentation-level and non-destructive. Had the
audit's hypothesis been taken at face value, the "fix" would have been built in
the recurrence engine — the wrong layer — and would have risked the recurrence
semantics the brief explicitly required preserving.


## 3. Files added / modified / deleted

**1 added, 7 modified, 0 deleted.**

## 8. Per-item verification (real measurements)

All figures measured in Chromium against the local site via
`ui-convergence-work/verify.py` (15 pages × 1440/1024/768/390 × light/dark).
Machine data: `docs/evidence/2026-10-03-ui-convergence-t1/`.

### T1.1 — `/empregos/`

| Width | Before | After |
|---|---|---|
| 1440 | 2 columns, media absent, **860px dead**, body 340px | **1 column, 0px dead**, body 1200px, content 748px |
| 1024 | 2 columns, media absent, **636px dead**, body 340px | **1 column, 0px dead**, content 748px |
| 768 | 1 column, 0px dead | unchanged, 0px dead |
| 390 | 1 column, 0px dead | unchanged, 0px dead |

Identical for `/empregos/` and `/en/empregos/` (one template). No placeholder
image introduced. The two-column layout still exists — it is simply reached only
when the portrait is genuinely rendered.

### T1.2 — `/eventos/`

| Surface | Before | After |
|---|---|---|
| `/eventos/` 1440/1024/768/390 | 10 cards / **6 distinct** titles / **4 duplicates** | 10 cards / **10 distinct** / **0 duplicates** |
| `/en/eventos/` (same 4 widths) | 10 cards / 6 distinct / 4 duplicates | 10 cards / 10 distinct / 0 duplicates |
| `/eventos/page/2/`, `/page/3/` | duplicates present | 10/10 distinct, 0 duplicates |
| `/eventos/?county=laois` (all pages) | duplicates present | **30 cards, 30 distinct titles** |
| `/en/eventos/?county=dublin` | — | 1 card, 0 duplicates |

Card count per page is unchanged at 10 — duplicates were replaced by genuinely
distinct events, so no content was hidden.

### T1.3 / T1.4 — heading and card-title convergence

The **structural** cause of the divergence was found and is worth recording,
because it was not visible by reading the source:

`main.css` loads **after** `design-system.css`, and it carried bare element
selectors (`h3 { font-size: clamp(1.25rem, 3vw, 1.75rem) }` → 28px). With equal
specificity, **source order wins**, so those element defaults silently overrode
the class-based card-title rule. Confirmed with the DevTools protocol: the only
rule matching `.leisure-card-title` for `font-size` was the bare `h3`. Scoping
the element defaults to `:not([class])` removes the override mechanism at its
root, rather than adding more per-card overrides on top.

### T1.5 — orange restraint and contrast

**Light theme — the measured defect is gone.**

| Element | Before | After |
|---|---|---|
| `.event-card-category` | `#F68B1F` (2.31:1 on its tint) | `--conexao-accent-text` **4.50:1** |
| `.events-page .event-card-date-hint` | `#F68B1F` | `--conexao-accent-text` |
| `.events-page .event-card-date-badge.is-today .event-card-date-day` | `#F68B1F` | `--conexao-accent-text` |
| Failing light-mode orange text occurrences | **240** | **0** |

**The hero accent word** was verified by pixel-sampling the rendered page rather
than trusting the CSS ancestor chain (the hero is a photo; the reported
background was misleadingly `rgb(255,255,255)`):

| Width | Size | Contrast before | Contrast after | Required |
|---|---|---|---|---|
| 1440 | 44px | 4.12:1 | **4.92:1** | 3.0 |
| 1024 | 34.8px | 3.75:1 | **6.63:1** | 3.0 |
| 768 | 24px | 5.40:1 | **7.65:1** | 3.0 |
| 390 | 17.9px | **3.38:1 (FAIL)** | **6.56:1** | 4.5 |

Fixed with a tight `text-shadow` of the same brand green on `.hero-title span`
(mobile), which darkens only the pixels behind those glyphs and leaves the rest
of the artwork untouched.

**Brand orange is retained** for borders, section rules, focus rings, decorative
fills and CTA backgrounds — `--conexao-accent` is untouched at `#F68B1F` in the
light theme.

**The token cannot regress into a failure.** `conexao_accent_text_color()`
derives it from the *configured* accent, darkening along its own hue until it
clears 4.5:1, so a maintainer changing the Customizer colour cannot silently
reintroduce the defect. Verified against several accents:

| Configured accent | Derived accent-text | Ratio |
|---|---|---|
| `#F68B1F` (default) | `#aa6115` | 4.74:1 |
| `#E65100` | `#cf4900` | 4.56:1 |
| `#FF0000` | `#e60000` | 4.81:1 |
| `#00BFFF` | `#0077a1` | 5.06:1 |

**Dark theme.** On dark surfaces the accent is already legible, so the token is
*lightened* rather than darkened: `#f59e42` = **8.47:1** on the dark body,
**7.72:1** on the dark card, **6.46:1** on the accent tint. AA passes in both
themes.

**A pre-existing dark-theme cascade bug was found and fixed.** With
`data-theme="dark"` set, the dark token block **never applied**: the Customizer's
`conexao_customizer_css()` emits a plain `:root` block in `wp_head`, i.e. *after*
`dark-mode.css`, and `[data-theme="dark"]` ties with `:root` on specificity

## 10. Numeric test results and regression comparison

Baseline was captured **before any edit** (§4 of the plan). The pass criterion is
that the **set of failing suites is identical** — not that the run is green,
because the baseline was not green.

| Layer | Baseline (before) | Final (after) | Delta |
|---|---|---|---|
| In-process PHP suites | 81 total, 52 passed, **29 failed** | **83 total, 54 passed, 29 failed** | +2 suites (mine + 1), **same 29 failures** |
| Assertions | 3097 passed, **4 failed** | **3208 passed, 4 failed** | **+111 passing**, 0 new failures |
| Script-contract suites | 18 total, 17 passed, **1 failed** | 18 total, 17 passed, **1 failed** | identical |
| HTTP acceptance suites | 4 total, 3 passed, **1 failed** | 4 total, 3 passed, **1 failed** | identical |
| **New suite** `test-ui-convergence-t1.php` | — | **82 assertions, 0 failed** | added |
| `./scripts/run-tests.sh` aggregate exit | 1 | 1 | unchanged |

**The diff of the failing-suite lists is empty — 0 bytes.** Both runs report the
same **31** distinct failing entries (29 in-process suites + 1 script-contract +
1 HTTP acceptance), proven by `diff` in
`evidence/2026-10-03-ui-convergence-t1/failing-suites-diff.txt`. Every suite that
failed at baseline still fails for the same pre-existing reason, and no suite
that passed at baseline now fails. **No test was weakened, skipped or deleted to
achieve this.**

## 11. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate.

| Proof | Result |
|---|---|
| **Disable the T1.2 collapse** (temporary early `return $ids;` in the helper) and re-run the new suite | **22 assertions FAIL**, e.g. `duplicate records sharing source identity laois_tourism\|10015791-… collapse to ONE card (2 posts -> 2) — expected 1, got 2`. The suite is proven to detect the exact defect. The file was restored and re-verified clean (`grep -c 'NEGATIVE PROOF'` = 0, 0 failures) |
| **Stash all four T1 source files** and re-run `verify-event-occurrence-http.py` | **Identical failures reproduced** (`found 5` / `found 2` / `found 3` / the same 404), proving those 4 are pre-existing and not caused by this change |
| **Stash only `dark-mode.css`** and set `data-theme="dark"` | `--conexao-primary` resolved to the **light** `#0E6B3A`, proving the dark token block did not apply before this change |

## 12. Known pre-existing failures

Separated explicitly, as the brief requires.

**a) `verify-stage14-secret-scan.py`** — the environmental failure the UI audit
already recorded: *"the PHP runtime scanner could not be executed (no local
WordPress); conformance is UNPROVEN, not assumed"*. It fails identically at
baseline and after and is **not** attributable to this work.

**b) 29 in-process PHP suites** — dominated by the translation-automation
(`conexao-translation-automation`) and translation-rollout suites, which fail
identically before and after. No suite in this list was touched by this change.

**c) `tests/acceptance/verify-event-occurrence-http.py` — 4 failures.** These
are **pre-existing**, confirmed by stashing this change and re-running (identical
output):

| Assertion | Baseline | After | Assessment |
|---|---|---|---|
| `exactly one all-day event is listed in the Laois archive` | found **3** | found **2** | **Improved by this change** (one duplicate removed) |
| `exactly one weekly series …` | found **5** | found **5** | **Not a duplicate.** All 5 have distinct `_event_source_id`s and are genuinely different weekly events in the local dataset |
| `exactly one multi-day event …` | found **2** | found **2** | **Not a duplicate.** `Transmitter At Kavanaghs Bar` and `R.E.M. by Stipe` have distinct source IDs |
| `multi-day detail page is 200` | **404** | **404** | Unrelated: the suite derives a slug from the title (`&` → `-038-`), while the real slug is `transmitter-at-kavanaghs-bar-venue` |

The suite was written against a **3-event CI fixture** ("exactly one"). The local
database additionally contains real imported Laois Tourism content, so "exactly
one" is the wrong assertion for this dataset. **Collapsing these to satisfy it
would mean deleting genuinely distinct events** — explicitly forbidden by the
brief, and it would destroy real content. They are recorded, not "fixed". The
suite is expected to pass unchanged on the deterministic CI fixture site, where
only the 3 fixture events exist.

## 13. Limitations

- **No production verification.** Nothing was deployed or tested against
  production; production has no CLI and no action was authorised. All numbers are
  local, against the deterministic local site with real imported Laois content
  plus the CI fixtures.
- **Fixture imagery.** The leisure archive is placeholder-heavy, so image-crop
  and image-quality judgements remain weak — the audit's own limitation.
- **Chromium only.** No Firefox, WebKit or real-device pass; no automated
  axe/Lighthouse run. Accessibility evidence is computed-style/DOM inspection,
  pixel sampling and WCAG ratio maths.
- **Dark-mode cascade fix is verified locally only.** Making the dark token block
  actually apply changes more than the accent token. It was verified across 15
  pages × 4 widths for overflow, `h1`/`main` count and contrast, but a human
  visual pass on production dark mode is still advisable before release.
- **The duplicate event records remain in the database.** The visible defect is
  fixed; the data-level cause is not (§5).
- **Hero contrast is measured by pixel sampling** of the glyph against its
  immediate backdrop. That is the correct basis for WCAG text contrast, but it is
  sensitive to the photograph: a materially different hero image must be
  re-measured.

## 14. Evidence paths

`docs/evidence/2026-10-03-ui-convergence-t1/`

| File | Proves |
|---|---|
| `verify-before.json` / `verify-after.json` | Raw computed-style, layout and a11y data for 15 pages × 4 widths × 2 themes, before and after |
| `verify-before.txt` / `verify-after.txt` | The T1 acceptance summary tables quoted in §8 |
| `tests-before.log` / `tests-final.log` | Full `./scripts/run-tests.sh` output, baseline and final |
| `lint.log`, `registry-check.log` | Static analysis and registry-drift results |

The Playwright harness (`verify.py`) and the 240 screenshots live in the
gitignored `ui-convergence-work/`. Regenerate with
`python3 ui-convergence-work/verify.py after`.

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/frontend.md` | Recorded the authoritative heading and card-title scales, the accent-text token and its derivation, and the `:not([class])` rule that must not be un-scoped |
| `docs/themes/conexao-br-irlanda.md` | Recorded the `/empregos/` media-conditional layout, the event archive's logical-identity presentation, the dark token cascade constraint and the new test |
| `docs/reports/2026-10-03-ui-convergence-t1-plan.md` | This phase's plan |
| `docs/reports/2026-10-03-ui-convergence-t1-report.md` | This report |
| `docs/audit/2026-10-03-ui-ux-audit.md` | **Deliberately unchanged** — the source analysis |

## 16. Rollback / recovery

Every change is CSS or theme-template logic, so rollback is:

```bash
git checkout -- wp-content/themes/conexao-br-irlanda/
```

**Rollback covers everything.** There is no data migration, no content change, no
database write, no cache invalidation and no production state to undo. The
browser cache-buster is `filemtime()`, so reverted files are re-served
immediately.

To roll back a single item rather than the whole phase:

| Item | Revert |
|---|---|
| T1.1 | `main.css` `.empregos-landing` + `page-empregos.php` |
| T1.2 | `inc/events.php` helper + the 1-line call in `inc/archive-query.php` |
| T1.3/T1.4 | `design-system.css` card-title rule + the `h1..h6:not([class])` scoping |
| T1.5 | the accent-text token in `main.css` / `design-system.css` / `dark-mode.css` / `customizer.php`, and the `:root[data-theme="dark"]` scoping |

## 17. Final status

**Final status: `PASS WITH LIMITATION`**

Every gate that could run, ran and passed, and the failing-suite set is identical
to the baseline. The limitation is that no production verification was possible
or authorised (§13), and the dark-theme cascade fix deserves a human visual
confirmation before release.

---

_Report: 2026-10-03 — UI convergence Phase 1 (T1.1–T1.5), Direction A_


(0-1-0) — so source order decided it and the **light** tokens won. Measured
before the fix: `--conexao-primary` resolved to the light `#0E6B3A` in dark
mode. Re-scoping the token block to `:root[data-theme="dark"]` wins the tie.
This is a genuine latent defect, not a cosmetic tweak.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/lint.sh` | **0** | PHP syntax clean (445 files); **no new PHPCS violations**; **PHPStan level 5: no errors** |
| `php scripts/generate-registry-docs.php --check` | **0** | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `tests/test-ui-convergence-t1.php` (new) | **0** | **82 assertions passed, 0 failed** |
| `tests/acceptance/verify-event-occurrence-http.py` | 1 | **41 passed, 4 failed** (was 41/4 — see §12) |
| `python3 ui-convergence-work/verify.py after` | **0** | 15 pages × 4 widths × 2 themes; T1.1 dead space 0; T1.2 duplicates 0; card titles 1 treatment; 0 a11y invariant issues |
| `./scripts/run-tests.sh` | see §10 | Full aggregate run |

### Browser verification coverage

Rendered in Chromium at **1440×1000, 1024×1000, 768×1000, 390×844**, in
**light and dark**, for: `/`, `/empregos/`, `/en/empregos/`, `/eventos/`,
`/en/eventos/`, `/guias/`, `/en/guias/`, `/lazer/`, `/en/lazer/`, `/cursos/`,
`/en/cursos/`, `/blog/`, `/apoiadores/`, `/contato/`, and one event single —
**120 screenshots** per run.

### Accessibility verification (no regression)

| Invariant | Before | After |
|---|---|---|
| Horizontal overflow (`scrollWidth − clientWidth`) | **0** on all 120 renders | **0** on all 120 renders |
| Exactly one `h1` | 120/120 | 120/120 |
| Exactly one `<main>` | 120/120 | 120/120 |
| Images missing `alt` | 0 | 0 |
| Skip link present | yes | yes |
| Invariant issues detected by the harness | **0** | **0** |
| Heading jumps (D4) | 0 on the measured archive/single set | unchanged — **not worsened**, not fixed (T1.6) |
| Small touch targets (D5) | 0 regressions introduced | unchanged — not fixed (T1.6) |
| Focus ring, filter ARIA, keyboard, reduced motion | untouched | untouched |
| Failing light-mode orange text | **240 occurrences** | **0** |


| Measure | Before | After |
|---|---|---|
| Distinct card-title treatments | **4** (17/600, 18/600, 18/700, 18.4/700) | **1** (18px/600/23.4px) on all 13 pages |
| Off-scale `1.0625rem` declarations | 14 | 4 (all non-card: hero, breadcrumb, page description, no-results) |
| Card titles overflowing their card | 0 | 0 (checked at all 4 widths, PT + EN) |
| Heading roles | 6–7 `h2` treatments | Page `h1`, section title, card title and component heading are now distinct and token-owned |

Hero/expressive treatments were **preserved**, not flattened: the hero title
remains `clamp(2rem, 5vw, 3.25rem)` at 44px, and the white-on-brand newsletter
band keeps its own scale. The audit's 16.8px "leisure-single" and 28px "jobs"
headings were re-checked and are legitimately distinct roles
(`leisure-single-block-title`, `empregos-opportunities-title`).


| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/events.php` | +`conexao_event_collapse_logical_ids()` | T1.2. Pure helper; consumes the runtime list, does not re-query |
| `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` | apply the collapse to the event archive `post__in` | T1.2. 14 lines |
| `wp-content/themes/conexao-br-irlanda/page-empregos.php` | emit `--with-media` from `has_post_thumbnail()` | T1.1 |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | grid fix, accent-text token, heading defaults scoped to `:not([class])`, per-card type removed, hero accent text-shadow | T1.1/T1.3/T1.4/T1.5 |
| `wp-content/themes/conexao-br-irlanda/assets/css/design-system.css` | one card-title rule (`--text-lg`/600/1.3) + `--color-accent-text` alias | T1.4/T1.5 |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | dark `--conexao-accent-text`; token block re-scoped to `:root[data-theme="dark"]` | T1.5 |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | removed the divergent `.leisure-card-title` size/weight | T1.4 |
| `wp-content/themes/conexao-br-irlanda/inc/customizer.php` | +`conexao_accent_text_color()`, +`conexao_contrast_ratio_hex()`, emit the token | T1.5 |
| `wp-content/themes/conexao-br-irlanda/tests/test-ui-convergence-t1.php` | **new** — 78 assertions | T1.1/T1.2 regression |
| `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/reports/2026-10-03-ui-convergence-t1-{plan,report}.md` | documentation | §17 of the plan |

No plugin, route, translation, Polylang, fixture or CI file was touched. No
`.htaccess` change. **No production change.**

> **Not my work — left untouched.** While this phase was running, two other
> files changed in the working tree that are **unrelated to T1.1–T1.5** and are
> **not part of this change**:
> `wp-content/themes/conexao-br-irlanda/header.php` (an LCP hero-preload
> `href`/`imagesrcset` adjustment) and a new
> `wp-content/themes/conexao-br-irlanda/tests/test-hero-preload-fallback.php`.
> Both concern the hero preload ladder, none of the T1 items, and both were
> modified *after* my last edit. Per `wp-repository`, uncommitted work that is not
> mine must be recorded, never reverted and never swept into this diff. They are
> excluded from every number in this report. A reviewer committing this phase
> should commit only the files listed in the table above.

## 4. Runtime impact

WordPress behaves differently in exactly three user-visible ways, all of them
the intended fixes:

1. `/empregos/` and `/en/empregos/` render the body copy at the full reading
   measure instead of a 340px column.
2. The `/eventos/` archive renders one card per logical event instead of
   repeating the same event.
3. Accent-coloured text uses an AA-compliant token; card titles use one
   treatment; the dark theme's token block now actually applies.

Everything else is presentation-neutral. Verified by: `git diff --name-only`
touching only files under `wp-content/themes/` plus docs; a green
`./scripts/lint.sh`; and a browser run over 15 pages × 4 widths × 2 themes
showing zero horizontal overflow and exactly one `h1` / one `<main>` on every
render (§9).

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **Confirmed** — local Docker only |
| No production content, Polylang or database change | **Confirmed** — no DB write of any kind |
| No event record deleted or modified | **Confirmed** — the collapse is read-only |
| No Flutter/mobile repository accessed | **Confirmed** |
| No `.env` or secret change | **Confirmed** |

## 5. Content / data impact

**None. Zero records created, updated or deleted.**

The T1.2 defect has a genuine root cause in the **data**: 13 groups of published
events share one source identity. This phase deliberately did **not** touch
them, because deduplicating stored records is a content change requiring the
§5.2 six-step contract (inventory → manifest → dry-run → snapshot → idempotent
apply → verify), plus importer changes. The presentation-level collapse removes
the visible defect without destroying evidence or changing any record.

## 6. Polylang impact

- PT immutable: **yes** — no PT record, field or translation was modified.
- EN records created: **none**.
- Translation-completeness gate: unchanged and unaffected.
- The collapse is **language-scoped by construction**, so a Portuguese record and
  its own English translation — which legitimately share a source identity
  (`154` pt / `155` en, `pilot-eb-0001`) — are never collapsed into one another.
  This is asserted in the new test suite.
