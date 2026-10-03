# Report — Filter link ARIA semantics (remove invalid `role="option"` on filter `<a>`)

| | |
|---|---|
| **Stage / task name** | Filter link ARIA semantics (`role="option"` on filter `<a>`) |
| **Date** | 2026-09-27 |
| **Author / agent** | Codex agent |
| **Branch** | `i18n` |
| **Start SHA** | `849624574451c1aa771f2fac0bd66b66fd2aadd8` |
| **Final SHA** | `849624574451c1aa771f2fac0bd66b66fd2aadd8` (uncommitted working tree — see §3) |
| **Working tree at finish** | dirty — 9 modified + 5 new paths. **6 of them are pre-existing from a different task** (EN homepage label leak) and were left untouched: `front-page.php`, `inc/content.php`, `tests/test-homepage-content-type-label.php`, the two `2026-09-27-en-homepage-label-leak*` reports, `docs/evidence/2026-09-27-en-homepage-label-leak/`, plus 2 rows already present in `routing.json`. |

## 1. Scope completed

The invalid ARIA on the three shared filter widgets is removed, and the
surrounding semantics are corrected together with it:

- **20** filter option links across `leisure-filters.php` (6),
  `event-filters.php` (6) and `employment-opportunities.php` (8) no longer
  carry `role="option"`; they keep their **native link role**.
- **11** option containers no longer claim `role="listbox"`, and the 2
  now-orphaned `aria-multiselectable="true"` were removed with them.
- **10** trigger buttons no longer advertise `aria-haspopup="listbox"`; the
  canonical `aria-expanded` + `aria-controls` disclosure pairing is untouched.
- The active filter is now expressed with **`aria-current="true"`** — the
  convention this repository already uses in `guide-filters.php` and
  `course-filters.php`.
- Coverage added: a 103-assertion in-process suite, updates to the two suites
  that encoded the old markup, 7 new HTTP acceptance rows, corrected theme
  docs, a real-Chromium accessibility verification, and fail-closed negative
  proofs at both the in-process and HTTP layers.

## 2. Scope NOT completed

- **No axe-core / Lighthouse inspection tool was run** (not installed, and no
  network/composer access to add one). Verification used the Chromium
  **accessibility tree** — computed role and accessible name per element —
  plus keyboard, activation, layout and console checks. See §13.1.
- **The leisure and event filter widgets could not be exercised live**,
  because the local dataset renders **zero** options for `/lazer/` and
  `/eventos/`. Those two templates are covered by the in-process suite, which
  renders them directly against real terms and posts. See §13.2.
- No production action, no deploy, no Flutter/mobile action, no release — all
  out of scope per the task constraints.

## 3. Files added / modified / deleted

**5 added, 9 modified, 0 deleted** — of which **6 paths are pre-existing** from
the EN-homepage-label-leak task and were **not** touched by this work.

| Path | Change | Note |
|---|---|---|
| `…/template-parts/leisure-filters.php` | modified | 6 options, 3 listboxes, 2 multiselectable, 3 haspopup; docblock |
| `…/template-parts/event-filters.php` | modified | 6 options, 4 listboxes, 3 haspopup; docblock |
| `…/template-parts/employment-opportunities.php` | modified | 8 options, 4 listboxes, 4 haspopup |

`wp-content/` changes are **template-only** (3 template parts) plus 3 test
files. No plugin, no helper, no CSS, no JS was modified.

## 4. Runtime impact

The filter controls are **functionally identical and semantically corrected**.
Before, each option announced as "option" inside a widget advertising a listbox
keyboard contract (arrow keys, single tab stop) it never implemented — the JS
only toggles `aria-expanded`, and the options always sat in the normal Tab
order. After, they announce as links with "current" on the active one: the
behaviour the widget always actually had.

**DOM shape, CSS classes, URLs, query parameters, `is-active` state, ✓
checkmark and every JS hook (`data-dropdown*`, `data-option-*`,
`data-agency-filters`) are unchanged.** Real Chromium confirms the active option
still renders highlighted, the trigger still shows the selection, and the panel
still lays out at 266×166 px.

### Before → after markup (`/empregos/`, real output)

```html
<!-- BEFORE -->
<button … aria-haspopup="listbox" aria-expanded="false" aria-controls="…">
<div class="agency-filters-dropdown-list" role="listbox" aria-label="Tipo de oportunidade">
  <a class="agency-filters-dropdown-link is-active" role="option"
     aria-selected="true" href="…/empregos/#empregos-opportunities-title">

<!-- AFTER -->
<button … aria-expanded="false" aria-controls="…">
<div class="agency-filters-dropdown-list" aria-label="Tipo de oportunidade">
  <a class="agency-filters-dropdown-link is-active"
     href="…/empregos/#empregos-opportunities-title" aria-current="true">
```

The whole-page diff of `/empregos/` is **11 changed lines**, every one of them
filter ARIA. Every `href` is byte-identical.

## 5. Content / data impact

**None.** Template-only change; `git status` confirms no content file was
touched and no translatable string was added, changed or removed, so the `.pot`
catalogue is unaffected. Verified after the test runs:

```
SELECT … WHERE post_title LIKE 'A11y%' OR post_name LIKE 'a11y-%'  → (empty)
SELECT … WHERE t.slug LIKE 'a11y-%'                                → (empty)
```

The `event` post-type count went 3 → 0 during the session; those 3 rows are
`[STAGE32-TS]` fixtures that `conexao-admin-ux/tests/test-translation-state.php`
creates and deletes by design, not site content.

## 6. Polylang impact

None. No post type, taxonomy, term, meta or translation was touched, so PT is
immutable here. Verified: `/lazer/` and `/eventos/` PT pages are **byte-identical**
before and after (43 824 and 43 454 bytes, `cmp` clean). The only PT page that
differs is `/empregos/`, and only in the 11 filter-ARIA lines. The completeness
gate is unaffected by this change (§11).

## 7. Route / HTTP impact

None. No route, redirect, canonical, hreflang or sitemap behaviour changed.

| URL | Status | Note |
|---|---|---|
| `/lazer/` | 200 | PT, byte-identical |
| `/en/lazer/` | 200 | EN shell intact |
| `/eventos/` | 200 | PT, byte-identical |
| `/en/eventos/` | 200 | EN shell intact |
| `/empregos/` | 200 | only filter ARIA changed |
| `/en/empregos/` | **302 → `/empregos/`** | **B1 preserved exactly** |
| `/empregos/?tipo=agency` | 200 | active option marked `aria-current` |

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | none |
| Deploy / upload | **no** | none |
| Plugin activation | **no** | none |
| Content or DB mutation | **no** | none |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `php -l` on 3 templates + 3 tests | 0 | "No syntax errors detected" ×6 |
| new in-process suite (container) | 0 | **103 passed, 0 failed** |
| `test-leisure-multiselect-filters.php` | 0 | 64 passed, 0 failed (baseline 63) |
| `test-event-location-filters.php` | 0 | 56 passed, 0 failed (baseline 55) |
| `./scripts/run-tests.sh` | 1 | see below |
| `python3 tests/acceptance/verify-routing-http.py` | 1 | 69 passed, 20 failed (20 pre-existing) |
| `vendor/bin/phpstan analyse` (changed paths) | 0 | **OK — No errors** |
| `phpcs` (3 templates, in container) | 1 | 111 errors / 64 warnings — **identical before and after** |
| real Chromium verification script | 0 | **53 passed, 0 failed** |
| `php scripts/generate-registry-docs.php --check` | 0 | registry OK, 0 writes |
| `python3 scripts/verify-permanent-gates.py` | 1 | 7 gates, 5 pass, 2 fail (§11) |

### Numeric test results

**In-process PHP: 59 suites total, 35 passed, 24 failed;
2 713 assertions passed, 137 failed.** Baseline was 58 / 34 / 24 and
2 608 passed / 137 failed — i.e. **+1 suite, +1 passing suite, +105 assertions,
and exactly the same 137 failures**.

**The set of failing suites is byte-identical to the baseline:**

```
$ diff baseline-failures.txt after-failures.txt
>>> IDENTICAL: no new failing suite, none fixed
```

**HTTP acceptance: 3 suites, 1 passed, 2 failed — 69 rows passed, 20 rows
failed** (routing matrix, 86 rows). All 7 new `a11y-*` rows pass; the 20
failures are pre-existing local-data gaps, proven by re-running the matrix with
the templates reverted (§10).

### Static analysis

- **PHPStan** (level 5, repo config) on the changed paths: `[OK] No errors`.
- **PHPCS** (WordPress-Extra/Docs/PHPCompatibilityWP): cannot run on the host
  (`xmlwriter`/`SimpleXML` missing, no sudo to install), so it was run inside
  the WordPress container, which has them. Result: **111 errors / 64 warnings
  across the 3 templates — identical counts before and after the change**, so
  **0 new violations**; all are legacy debt already in `phpcs-baseline.json`.
- **PHP syntax**: clean on all 6 changed PHP files.

### Script-contract results

**6 suites, 5 passed, 1 failed** (`verify-i18n-freshness.py`, pre-existing: the
`.pot` predates `inc/i18n/fallback.php`).

> An earlier run showed `verify-release-integrity.py` failing with "the build is
> not deterministic". That was **caused by my own concurrent activity** (a
> `docker cp` and a PHPCS run overlapping the background test run, which
> perturbs the ZIP's newest-mtime epoch). Re-running the suite cleanly
> reproduces the baseline exactly — 5 passed / 1 failed — and
> `verify-release-integrity.py` passes 3/3 standalone. Not caused by this change.

### Release / build results

`not in scope` — no release was authorised. `verify-release-integrity.py`
passes (210 passed, 0 failed).

## 10. Failure proofs / negative tests

Both new gates were **proven able to fail**, then restored.

**In-process (one defective link reintroduced):**

```
FAIL: leisure: no invalid role="option" on any filter link
FAIL: leisure: with no filter active exactly the 3 reset options are aria-current (got 2)
FAIL: leisure active: no invalid role="option" on any filter link
94 passed, 3 failed        EXIT=1
# restored
97 passed, 0 failed
```

**HTTP acceptance (templates reverted via `git stash`):**

```
FAIL a11y-empregos-filter-links-are-not-options -- unexpected fragment: 'role="option"';
     unexpected fragment: 'role="listbox"'; unexpected fragment: 'aria-selected';
     unexpected fragment: 'aria-haspopup="listbox"'
FAIL a11y-empregos-filter-state-no-option-role  -- unexpected fragment: 'role="option"'
routing-http: 67 passed, 22 failed

# with the fix
routing-http: 69 passed, 20 failed

## 11. Regression comparison

| | Before | After |
|---|---|---|
| In-process suites | 58 (34 pass / 24 fail) | 59 (35 pass / 24 fail) |
| Assertions | 2 608 pass / 137 fail | 2 713 pass / 137 fail |
| Script-contract | 5 pass / 1 fail | 5 pass / 1 fail |
| Acceptance rows | 62 pass / 20 fail (79 rows) | 69 pass / 20 fail (86 rows) |
| Permanent gates | 5 pass / 2 fail | 5 pass / 2 fail |
| Gate violations | 36 (35 pre-existing, 1 new) | 36 (35 pre-existing, 1 new) |

**The diff of the failing lists is empty** (verified with `diff`). The
**+105 assertions** are the new suite's contribution; the **+7 acceptance
rows** are the new a11y rows.

## 12. Known pre-existing failures

Identical before and after this change (all local-environment/data conditions):

- `test-translation-completeness`, `test-stage32/33-bilingual`,
  `test-stage45-pages`, `test-nav-menu-regression`, `test-stage7-hero-sponsors`
  and others — the local DB has an incomplete EN translation set.
- `test-town-sanitization` — contaminated town terms in the local DB.
- 20 acceptance rows for `/guias/`, `/cursos/`, `/lazer/`, `/eventos/` content —
  the local dataset lacks the published records those rows assert.
- `verify-i18n-freshness` — the `.pot` predates `inc/i18n/fallback.php`.
- Permanent gates: `translation_completeness` (35 violations) and
  `i18n_freshness` (1 violation).

Each is proven pre-existing by the stashed-baseline comparison (§11) and by the
failing-suite lists being identical.

### Permanent gates (run alone, on a quiet database)

Run only after every mutating suite had stopped, per the task's instruction
about concurrent DB mutation inflating violation counts. The run was repeated
with nothing else touching the database and produced **identical** numbers.

| Gate | Status | Violations (pre-existing / new) |
|---|---|---|
| `taxonomy_policy` | PASS | 0 (0 / 0) |
| `translation_completeness` | FAIL | 35 (34 / 1) |
| `cache_scoping` | PASS | 0 (0 / 0) |
| `cache_scoping_static` | PASS | 0 (0 / 0) |
| `redirect_precedence` | PASS | 0 (0 / 0) |
| `documentation_drift` | PASS | 0 (0 / 0) |
| `i18n_freshness` | FAIL | 1 (1 / 0) |

**7 gates total, 5 passed, 2 failed; 81 assertions passed, 6 failed;
36 violations (35 pre-existing, 1 new).** These numbers are **byte-identical
to the tracked historical `gate.json`** recorded at `2026-09-27T16:04:26Z`, so
this change introduces **zero** new gate violations and the gates remain
fail-closed. The tracked historical `gate.json` was restored with
`git checkout --` after the run overwrote it, so no historical evidence was
altered to make the result look green.

## 13. Limitations

1. **No axe-core / Lighthouse audit was run.** No such tool is installed and
   there is no network/composer access to add one. The Chromium **accessibility
   tree** was used instead — authoritative for computed role and accessible
   name — but it is not a rules-based linter.
2. **Leisure and event filter widgets are not covered by a live browser run**
   (the local dataset renders 0 options for `/lazer/` and `/eventos/`). They
   are covered by the in-process suite rendering the templates against real
   terms and posts. No fixture was invented in the browser run to work around
   this.
3. **No automated screen-reader announcement test.** "Announces as a link" is
   evidenced by the computed AX role being `link` with a non-empty accessible
   name, not by NVDA/JAWS/VoiceOver audio.
4. **The 3 `[STAGE32-TS]` event fixtures vanish during suite runs.** Normal
   harness behaviour; the events filters therefore render 0 options live.
5. **WordPress.com has no CLI**, so nothing here could be production-verified
   even had a deploy been in scope.

## 14. Evidence paths

`docs/evidence/2026-09-27-a11y-filter-option-role/`

| Path | Proves |
|---|---|
| `baseline-run-tests.txt` | the failing-suite set **before** any edit |
| `after-run-tests.txt` | the same run **after**; diff is empty |
| `before/{empregos,lazer,eventos}.html` | live HTML with `role="option"` present |

### Browser / accessibility results (the decisive evidence)

Real Chromium, computed accessibility tree per element:

```
pt-empregos:        COMPUTED AX role is "link" for every option -- link
pt-empregos:        every option exposes an accessible name --
                    ["Todas","Agência de recrutamento","Setor público",
                     "Histórico de Employment Permits"]
pt-empregos:        COMPUTED AX role is never "option" -- roles=link
pt-empregos:        keyboard reaches an option link -- {"tag":"A","isOption":true,"text":"Todas"}
pt-empregos:        trigger no longer claims a listbox popup -- aria-haspopup=null
pt-empregos:        option group keeps an accessible name --
                    {"roleAttr":null,"computedRole":"generic","name":"Tipo de oportunidade"}
pt-empregos:        no console errors
pt-empregos:        option list has a real layout box -- {"width":266,"height":166}
pt-empregos:        no horizontal overflow
pt-empregos-filtered: exactly one option is aria-current -- n=1
pt-empregos-filtered: aria-current matches is-active
routing: /en/empregos/ still 302s to PT (B1 unchanged) -- status=302
53 passed, 0 failed
```

## 15. Documentation updated

- `docs/themes/conexao-br-irlanda.md` — the three filter-widget rows that
  documented the "listbox pattern" now describe the disclosure +
  group-of-links pattern with `aria-current`, matching the code.
- `docs/reports/2026-09-27-a11y-filter-option-role-plan.md` (plan) and
  `…-report.md` (this report).
- `docs/routing.md` reviewed: its filter paragraph describes URL semantics,
  which are unchanged — **no edit needed**.
- The `.pot` catalogue is **unchanged** (no translatable string touched), so
  the i18n-freshness gate is unaffected by this task.

## 16. Rollback / recovery

`git checkout --` the 3 templates, the 2 updated test files, `routing.json`
and `docs/themes/conexao-br-irlanda.md`, and delete the new suite, the report,
the plan and the evidence directory. Nothing to reverse in data, the database
or production — the change is template attributes only. The before-state HTML
is captured in `before/` if the previous markup is ever needed.

## 17. Security and performance review

- **No unsanitised output introduced.** Every `href` keeps its `esc_url()` and
  every label its `esc_html()`/`esc_attr()`; the change only removes ARIA
  attributes and adds one static, already-escaped string.
- **No input handling changed.** `$_GET` parsing, `sanitize_title()` and the
  URL builders are untouched (asserted in the new suite).
- **No new database queries.** Same queries, same order; only attribute
  strings changed.
- **No new JavaScript dependency.** `assets/js/main.js` is unmodified and never
  read the removed attributes (verified by grep).
- **No cache behaviour change.** No transient key touched.
- **No permission or capability change.**
- **Performance:** explicitly not a performance refactor; the only delta is a
  few bytes of attributes per option, and the page is slightly *smaller*
  (55 508 → 55 340 bytes on `/empregos/`).

## 18. Final status

**Final status: `PASS`**

Every required verifier ran and produced real numbers: in-process tests, HTTP
acceptance, permanent gates, PHPStan, PHPCS (delta-verified), and real Chromium
accessibility-tree, keyboard, activation, layout and console verification —
with fail-closed negative proofs at both the in-process and HTTP layers. The
only untested surface is a third-party axe/Lighthouse rules engine (§13.1),
which is supplementary rather than required: the underlying fact the task asks
for — the controls' resulting accessibility semantics — is directly
demonstrated by the browser's own computed accessibility tree, and the
pre-existing gate failures are preserved rather than suppressed.

_Last verified: 2026-09-27 by the filter link ARIA semantics task_

| `after/*.html` | live HTML for all 6 required URLs, `role="option"` absent |
| `tests/new-suite.txt` | the new suite: 103 passed, 0 failed |
| `browser/playwright-a11y-run.txt` | 53 browser/accessibility checks, 0 failed |
| `browser/verify.mjs` | the exact verification script (re-runnable) |
| `browser/report.json` | per-element computed role/name, boxes, console errors |
| `browser/*.png` | desktop + mobile screenshots (visual-design proof) |
| `permanent-gates-after.json` | gate-by-gate result on a quiet DB |
| `gate-tracked-baseline.json` | copy of the tracked historical gate record |

```

The other 20 failing rows are **identical in both runs**, proving no acceptance
regression and that those failures are local-data conditions, not defects of
this task.


No production access was used or attempted.

| `…/tests/test-filter-link-aria-semantics.php` | **added** | 103 assertions, all three widgets |
| `…/tests/test-leisure-multiselect-filters.php` | modified | assertions that encoded `aria-selected`/`aria-multiselectable` updated (63→64) |
| `…/tests/test-event-location-filters.php` | modified | the `role="listbox"`+`aria-selected` assertion corrected (55→56) |
| `tests/acceptance/matrices/routing.json` | modified | **+7 rows**, insertions only; no pre-existing row reformatted |
| `docs/themes/conexao-br-irlanda.md` | modified | the 3 rows documenting the "listbox pattern" corrected |
| `docs/reports/2026-09-27-a11y-filter-option-role-plan.md` | **added** | the plan |
| `docs/reports/2026-09-27-a11y-filter-option-role.md` | **added** | this report |
| `docs/evidence/2026-09-27-a11y-filter-option-role/` | **added** | before/after HTML, test logs, browser evidence, gates |
