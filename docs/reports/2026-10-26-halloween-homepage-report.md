# Report — Halloween Homepage Experience

| | |
|---|---|
| **Stage / task name** | Halloween homepage presentation refinement |
| **Date** | 2026-10-26 |
| **Author / agent** | Copilot AI |
| **Branch** | `holidays-dates` |
| **Start SHA** | `98af8fd52fb93baa4cad3fba95c112e3fa89f5dd` |
| **Final SHA** | `98af8fd52fb93baa4cad3fba95c112e3fa89f5dd` (no commit created) |
| **Working tree at finish** | dirty; task changes listed below, plus the pre-existing modification to `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php`, which this task did not edit |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/README.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `docs/templates/report.md` |

## 1. Scope completed

- Replaced the lower-right Halloween card with a centered, non-modal
  presentation layer inside the existing homepage hero. A darkened twilight
  backdrop de-emphasizes the sponsor carousel while the greeting is visible;
  the card has centered PT/EN copy, a restrained amber accent, and the existing
  language-aware Events destination.
- Added original inline SVG bat silhouettes with articulated wings and a
  shaded, segmented jack-o'-lantern. CSS supplies the moon halo, stars, mist,
  bat flight, and wing movement; reduced-motion mode disables the motion.
- Added a 44×44 localized close button, session-scoped dismissal keyed by
  occasion and occurrence year, inertness for the underlying hero while open,
  and focus return to the first hero CTA after keyboard dismissal.
- Kept seasonal activation exclusively with the existing occasion engine.
  The front page passes the selected enabled Halloween occasion to the
  presentation; inactive, disabled, or other selected occasions render no
  Halloween wrapper.
- Added an in-process rendering suite and regenerated the theme `.pot` through
  the repository's catalogue generator. No production content or data was
  changed.

## 2. Scope NOT completed

- No production deployment or verification against the production WordPress.com
  site was performed.
- At 320 CSS px, the overall page retains 11 px of horizontal overflow in
  `.featured-main`, outside the hero presentation. It remains after dismissal
  and was deliberately left unchanged as unrelated homepage content. The
  Halloween card itself stays within the hero at that width.
- Automated numeric color-contrast measurement was not run. The card and CTA
  contrast were visually inspected in browser screenshots.

## 3. Files added / modified / deleted

**8 added, 8 modified, 0 deleted by this refinement.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/front-page.php` | Pass the selected enabled Halloween occasion into the seasonal template. | Existing homepage and sponsor data remain intact. |
| `wp-content/themes/conexao-br-irlanda/template-parts/halloween-hero.php` | Centered localized presentation, close control, and inline SVG scene artwork. | Replaces the prior untracked seasonal partial. |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Reworked only the seasonal component styles. | Responsive layout, visual depth, focus, and reduced-motion behavior. |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Session dismissal, inertness, and focus restoration. | Uses the existing deferred bundle. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-presentation.php` | Added presentation rendering and occasion-selection tests. | 17 assertions passed. |
| `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | Regenerated with the repository generator. | Includes the new greeting, support copy, CTA, and close-label strings. |
| `docs/reports/2026-10-26-halloween-homepage-plan.md` | Recorded the approved implementation plan and pre-edit baseline. | Updated with the catalogue regeneration step. |
| `docs/reports/2026-10-26-halloween-homepage-report.md` | This implementation and verification record. |  |
| `docs/evidence/2026-10-26-halloween-homepage/halloween-pt-desktop.png` | Captured active Portuguese presentation at desktop size. | Actual browser screenshot. |
| `docs/evidence/2026-10-26-halloween-homepage/homepage-en-mobile-390.png` | Captured English presentation at 390 CSS px. | Actual browser screenshot. |
| `docs/evidence/2026-10-26-halloween-homepage/homepage-pt-desktop.png` | Captured the ordinary Portuguese homepage. | Actual browser screenshot. |
| `docs/evidence/2026-10-26-halloween-homepage/homepage-pt-dismissed.png` | Captured the homepage after keyboard dismissal. | Actual browser screenshot; focus returned to the hero CTA. |
| `docs/frontend.md` | Documented the seasonal presentation contract. | Includes storage and reduced-motion behavior. |
| `docs/themes/conexao-br-irlanda.md` | Documented integration with the hero and sponsor carousel. | Added seasonal component reference. |
| `docs/README.md` | Linked the plan and report. | History index. |
| `docs/reports/README.md` | Added the seasonal homepage report index entries. | Updated verification marker. |

`tests/test-seasonal-occasion.php` was already modified before this task began
and was not edited as part of this refinement. The pre-existing worktree edits
to the theme CSS, front page, and Halloween template were reviewed and
integrated rather than discarded.

## 4. Runtime impact

When the existing occasion engine selects enabled Halloween, the front page
shows a centered welcome presentation over the hero. The complete hero content
is inert while that presentation is open, preventing accidental access to
sponsor controls through the overlay. Dismissal immediately hides the
presentation and restores hero interaction without reloading. The overlay
starts hidden in markup, so without the deferred JavaScript the ordinary hero
remains usable.

When Halloween is inactive, disabled, or not the selected occasion, the
presentation partial returns no markup. The occasion engine and its date rules
are unchanged.

## 5. Content / data impact

Production records created, updated, or deleted: **none**. No content
migration or production database operation was performed. The local test
harness/browser ran against `localhost`; the local theme translation catalogue
was regenerated.

## 6. Polylang impact

No Polylang configuration, content records, language relationships, or PT
slugs were changed. Browser checks confirmed Portuguese copy and controls at
`http://localhost:8080/` and English copy and controls at
`http://localhost:8080/en/`. The Events CTA continues to use the existing
language-aware URL helper. The theme `.pot` now contains the new seasonal
strings; no `.po` or `.mo` files were edited.

## 7. Route / HTTP impact

No routes, redirects, canonical metadata, hreflang, or sitemap behavior
changed. The CTA uses the existing localized `/eventos/` destination. The
canonical HTTP acceptance suite still has its baseline event-occurrence
failure, unrelated to this presentation.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | No production state was accessed or changed. |
| Deploy / upload | **no** | Not in scope. |
| Plugin activation | **no** | No plugin changes. |
| Production content or DB mutation | **no** | Only local test and browser verification were performed. |

## 9. Verification commands and results

| Command / check | Exit | Result |
|---|---:|---|
| Pre-edit `./scripts/run-tests.sh` | 1 | Baseline: PHP 112 suites (106 passed, 6 failed; 8,758 assertions passed, 19 failed), script contracts 18/19, HTTP acceptance 3/4. |
| `./scripts/run-tests.sh --php --only theme` | 1 | 51 suites (49 passed, 2 failed), 3,112 assertions passed, 8 failed. The new Halloween presentation suite passed 17/17. |
| Final `./scripts/run-tests.sh` | 1 | PHP 113 suites (109 passed, 4 failed; 8,787 assertions passed, 9 failed), script contracts 18/19, HTTP acceptance 3/4. No new failing suite relative to baseline. |
| `./scripts/run-tests.sh --scripts` after catalogue regeneration | 1 | 19 script-contract suites, 18 passed and 1 failed: the pre-existing commit-time i18n freshness condition described below. |
| `./scripts/i18n-make-pot.sh --dry-run conexao-br-irlanda` | 0 | Previewed regeneration of the theme catalogue only. |
| `./scripts/i18n-make-pot.sh --apply conexao-br-irlanda` | 0 | Regenerated `conexao-br-irlanda.pot`; new Halloween strings are present. |
| `./scripts/i18n-make-pot.sh --check conexao-br-irlanda` | 0 | Check mode is plan-only and reported `would regenerate` for the theme catalogue; it is not an idempotence/content comparison. |
| `./scripts/i18n-check.sh` | 1 | 3 checks passed, 1 pre-existing stale-catalogue result. The gate uses Git commit times, so it continues to compare the uncommitted regenerated `.pot` against the older committed timestamp of unchanged `inc/occasion.php`. |
| `./scripts/lint.sh` | 1 | PHP syntax clean (525 files); PHPStan clean (0 errors); PHPCS baseline gate failed. Reported deltas: one `NoDoubleNegative`, `VariableComment.Missing` 5→15, `SeparateFunctionsFromOO.Mixed` 5→6, `InvalidClassFileName` 74→75, and `MissingTranslatorsComment` 101→102. Findings include unchanged `inc/occasion.php`; the Halloween template itself passes targeted PHPCS with 0 errors and 0 warnings. |
| `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js` | 0 | JavaScript syntax check passed. |
| Browser checks at `http://localhost:8080/` and `/en/` | — | PT/EN copy, close labels, localized CTA, dismissal, storage failure, focus return, reduced motion, carousel control state, and requested viewport widths were checked in the browser. |

### Responsive and interaction results

| CSS viewport | Card geometry | Close target | Document overflow |
|---:|---|---:|---|
| 320px | 285px wide; left 10px, right 295px, within the 305px hero | 44×44px | 11px (`scrollWidth 316`, `clientWidth 305`), from `.featured-main` outside the hero |
| 375px | 340px wide; left 10px, right 350px, within the 360px hero | 44×44px | none (`360 / 360`) |
| 390px | 355px wide; left 10px, right 365px, within the 375px hero | 44×44px | none (`375 / 375`) |
| 768px | 600px wide; centered in the 753px hero | 44×44px | none (`753 / 753`) |
| 1024px | 600px wide; centered in the 1009px hero | 44×44px | none (`1009 / 1009`) |
| 1440px | 600px wide; centered in the 1425px hero | 44×44px | none (`1425 / 1425`) |

Keyboard activation of the close button hid the layer, removed `inert` and
`aria-hidden` from the hero, and returned focus to “Explorar Guias”. The
`conexao-seasonal-halloween-2026` value survived a same-tab reload. With the
`sessionStorage` getter forced to throw, the greeting still appeared and
keyboard dismissal still restored the hero. Under emulated
`prefers-reduced-motion: reduce`, both bat flight and wing animation computed
to `none`. After dismissal, activating the sponsor carousel's Next button
advanced its current indicator from sponsor 2 to sponsor 3 and updated its
polite live-region status; the sponsor controls remained enabled.

### Numeric test results

In-process PHP, final: **113 suites total, 109 passed, 4 failed; 8,787
assertions passed, 9 failed**.

Focused theme layer: **51 suites total, 49 passed, 2 failed; 3,112 assertions
passed, 8 failed**. The seasonal presentation suite passed **17 assertions,
0 failed**.

### HTTP acceptance

**4 suites; 184 assertions passed, 2 failed.** The failing matrix is
`tests/acceptance/verify-event-occurrence-http.py`; the two failed checks are
the Laois archive's weekly-series and multi-day event counts. The same
acceptance suite failed before implementation.

### Static analysis

- PHP syntax: **525 files, no parse errors**.
- PHPStan level 5: **no errors**.
- PHPCS baseline gate: **failed**. It reports an existing baseline delta,
including the double-negative and missing member-variable/translators
documentation findings in unchanged `wp-content/themes/conexao-br-irlanda/inc/occasion.php`.
No PHP coding-standard issues remain in the Halloween template.
- JavaScript syntax: `node --check` passed.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **19 suites, 18 passed, 1 failed**. The
remaining `verify-i18n-freshness.py` condition is based on Git commit timestamps
and still marks the theme catalogue stale while the regenerated `.pot` is
uncommitted; it reported **1 pre-existing, 0 new** stale catalogues.

### Release / build results

Not in scope.

## 10. Failure proofs / negative tests

The presentation suite proves the no-output cases for an inactive occasion, a
disabled Halloween occasion, and a different selected occasion. Browser
verification also exercised dismissal when `sessionStorage` access throws:
the presentation still appeared, then keyboard dismissal hid it and restored
the hero. No mutation-testing experiment was introduced.

## 11. Regression comparison

| | Before | After |
|---|---:|---:|
| In-process PHP suites | 112 total; 106 passed, 6 failed | 113 total; 109 passed, 4 failed |
| Failed in-process assertions | 19 | 9 |
| Failing script-contract suites | 1 (`verify-i18n-freshness.py`) | 1 (`verify-i18n-freshness.py`) |
| Failing acceptance assertions | 2 in `verify-event-occurrence-http.py` | 2 in `verify-event-occurrence-http.py` |

The post-change failing in-process set is a strict subset of the pre-edit set.
All four final failing suites were already failing before this work:
`test-export-language.php`, `test-town-sanitization.php`,
`test-event-filter-cache.php`, and `test-stage41-rest-language.php`.
`test-event-query.php` and `test-stage7-hero-sponsors.php` failed at baseline
but passed in the final run. The new Halloween suite passed; no new failing
suite was introduced.

The full canonical run preceded the local `.pot` regeneration; afterward, the
script-contract layer was rerun and still reported the theme catalogue against
unchanged `inc/occasion.php`. The standalone gate uses Git commit times, so an
uncommitted regenerated catalogue is not seen as newer.

## 12. Known pre-existing failures

- `plugin/conexao-event-importer/test-export-language.php`: PHP memory
  exhaustion at the 512 MB limit in `class-event-export.php:935`; failed before
  this change.
- `plugin/conexao-event-importer/test-town-sanitization.php`: the local
  `conexao_town` fixture contains an Eircode-contaminated/non-town term; failed
  before this change.
- `theme/conexao-br-irlanda/test-event-filter-cache.php`: four cache/filter
  assertions fail in the current local fixture environment; failed before
  this change.
- `theme/conexao-br-irlanda/test-stage41-rest-language.php`: four bilingual
  REST/cache assertions fail with the existing local Polylang test setup;
  failed before this change.
- `tests/scripts/verify-i18n-freshness.py`: commit-time catalogue/source
  comparison marks the theme `.pot` stale against unchanged `inc/occasion.php`.
  The catalogue was regenerated with the prescribed tool, but the gate's
  signal is commit history rather than working-tree timestamps.
- `tests/acceptance/verify-event-occurrence-http.py`: two Laois archive
  occurrence-count assertions fail; failed before this change.

The pre-edit suite also failed `test-event-query.php` and
`test-stage7-hero-sponsors.php`; both passed in the final full run.

## 13. Limitations

- The 320px page has 11px of horizontal overflow in `.featured-main`, which
  remains after dismissal and is outside this change's scope. At 375px and
  wider, measured document scroll width equals its client width.
- The repository-wide PHPStan and PHP syntax checks passed, but the PHPCS
  baseline gate did not. The new Halloween template passed targeted PHPCS;
  existing findings in unchanged source files were not repaired as unrelated
  work.
- The i18n freshness gate compares Git commit timestamps. After regeneration,
  the working-tree `.pot` still appears stale until represented by a commit
  newer than the existing source commit. No commit was created for this task.
- Browser verification was local only. Contrast was visually inspected, not
  measured with a dedicated WCAG contrast tool.
- The full suite and the post-regeneration script-contract run retain the
  pre-existing failures itemized above.

## 14. Evidence paths

All screenshots are actual captures from `http://localhost:8080/` and live
under `docs/evidence/2026-10-26-halloween-homepage/`:

- `halloween-pt-desktop.png` — centered Portuguese greeting, scene, backdrop,
  and close control.
- `homepage-en-mobile-390.png` — localized English greeting and CTA at the
  requested 390px mobile width.
- `homepage-pt-desktop.png` — ordinary homepage presentation.
- `homepage-pt-dismissed.png` — ordinary hero after dismissal, with focus
  returned to its first CTA.

The viewport measurements and interaction results are recorded below; no
evidence directory was left empty.

## 15. Documentation updated

| Document | Update |
|---|---|
| `docs/frontend.md` | Presentation, session key, inertness, focus, and reduced motion. |
| `docs/themes/conexao-br-irlanda.md` | Occasion gating, hero/carousel layering, art, and localization. |
| `docs/README.md` | Links to the dated plan and report. |
| `docs/reports/README.md` | Seasonal homepage history section and verification marker. |
| `docs/reports/2026-10-26-halloween-homepage-plan.md` | Actual pre-edit baseline and catalogue regeneration in the expected file list. |

No procedure was moved from documentation into a skill.

## 16. Rollback / recovery

Remove the Halloween partial call from `front-page.php`, remove the seasonal
CSS block and `initHalloweenWelcome()` initializer, and remove the Halloween
template and rendering test. Restore the theme `.pot` from the pre-change
revision if rolling back the localized strings. Remove this report, its plan
entry/index links, and the four screenshots. Do not revert
`tests/test-seasonal-occasion.php`; its worktree modification pre-dated this
task. No database or production rollback is required.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The centered PT/EN presentation, keyboard dismissal, session behavior,
reduced-motion handling, and responsive card geometry are verified, but the
repository-wide lint/test baseline and unrelated 320px page overflow prevent
an unqualified pass.

_Last verified: 2026-10-26 by Halloween homepage presentation refinement_
