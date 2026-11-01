# Report — Halloween witch animation

| | |
|---|---|
| **Stage / task name** | Rare witch-on-a-broom Halloween navigation effect |
| **Date** | 2026-10-27 |
| **Author / agent** | Copilot |
| **Branch** | `holidays-dates` |
| **Start SHA** | `82f5a28` |
| **Final SHA** | `82f5a28` (uncommitted worktree) |
| **Working tree at finish** | Dirty: 8 modified, 4 added, 0 deleted; all listed below |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/frontend.md`, `docs/testing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/templates/plan.md`, `docs/templates/report.md`; `wp-security-review` applicability checked |

## 1. Scope completed

Added a locally stored SVG witch riding a broom to the existing Halloween
navigation effect selector. It flies from beyond the left viewport edge to
beyond the right edge in 3,000 ms, with a gentle vertical curve and four
bounded amber sparkles. Selection is uncommon (one-in-eight eligible rolls),
and the existing one-effect-at-a-time guard, cooldown, cleanup, and same-origin
navigation handoff remain in use. The handoff now checks the selected effect's
own duration, allowing a valid witch flight to resume for its full remaining
time rather than applying the older one-second limit.

The existing occasion engine remains the only Halloween eligibility
authority. The asset URL is emitted only in its active-Halloween marker and is
escaped with `esc_url()`. The artwork is requested only if selected. Reduced
motion disables initialization and hides the witch; navigation remains native
and immediate.

## 2. Scope NOT completed

- Browser screenshot inspection was possible, but the integrated browser's
  screenshot-to-file path returned clipped/scaled images. No screenshot is
  archived; live browser screenshots were inspected.
- Cross-document replay could not be fully exercised in this browser context
  because direct `sessionStorage` access was restricted. The site's existing
  storage-failure path preserves ordinary navigation, and the in-process
  contract suite passed.
- Repository-wide test and lint gates did not pass; see §9 and §13.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/images/halloween/witch-broom.svg` | Added | Original local 420×230 decorative witch, cape, and broom illustration |
| `docs/reports/2026-10-27-halloween-witch-animation-plan.md` | Added | Plan |
| `docs/reports/2026-10-27-halloween-witch-animation-report.md` | Added | This implementation and verification report |
| `docs/evidence/2026-10-27-halloween-witch/verification-notes.md` | Added | Browser checks and measured responsive results; no screenshot claimed |
| `wp-content/themes/conexao-br-irlanda/header.php` | Modified | Exposes escaped local SVG URL through the existing Halloween-only marker |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Modified | Rare selector, bounded decorative DOM, per-effect timing and continuation |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Modified | Three-second flight, responsive sizing, sparkles, reduced-motion handling |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | Modified | Regression assertions for selection, asset, duration, path, cleanup and gating |
| `docs/frontend.md` | Modified | Canonical frontend behavior and navigation safeguards |
| `docs/themes/conexao-br-irlanda.md` | Modified | Theme-level implementation reference |
| `docs/README.md` | Modified | Added plan and report links |
| `docs/reports/README.md` | Modified | Added plan and report index entries |

**Counts: 4 added, 8 modified, 0 deleted.** The `wp-content/` changes are the
theme asset, marker, JS/CSS behavior, and its regression test. No out-of-scope
application was accessed.

## 4. Runtime impact

During the active Halloween occasion, an eligible, unmodified, same-origin
primary-navigation click may select the witch. It does not replace the bat,
pumpkin, or ghost effects, block controls, or delay navigation. Existing
cross-page handoff validation and expiry safeguards remain in place; the
selected effect duration is now used when deciding whether to resume it.
Outside the occasion marker or under reduced motion, the effect is not
initialized. No page layout dimensions are changed by the fixed,
overflow-clipped, pointer-transparent layer.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **Confirmed** |
| No production content, Polylang or database change | **Confirmed** |
| No Flutter/mobile repository accessed | **Confirmed** |
| No `.env` or secret change | **Confirmed** |
| Behavior change | **Yes, local theme only**; see §4 |

`wp-security-review` was consulted and judged not applicable: this is a
presentation-only change with no request input, data access, endpoint, admin
path, or mutation. The only new PHP output is the local SVG URL, escaped with
`esc_url()`.

## 5. Content / data impact

Records created, updated, or deleted: **none**. Confirmed by the changed-file
list; no content write or database mutation was performed.

## 6. Polylang impact

No translation records, taxonomy policy, language switching, or language-aware
content behavior changed. PT content remains untouched; EN records created:
**0**; translation completeness gate was not changed.

## 7. Route / HTTP impact

Routes, redirects, canonical, hreflang, and sitemap behavior are unchanged.
No HTTP acceptance row was added because the change introduces no route or
request contract. The existing HTTP suite did run; its event-occurrence matrix
had two failures unrelated to a changed route or file (see §9).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | Local repository implementation only |
| Deploy / upload | **no** | Not in scope |
| Plugin activation | **no** | No plugin added or changed |
| Content or DB mutation | **no** | None |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| `./scripts/run-tests.sh` | 1 | In-process PHP: 114 suites, 109 passed, 5 failed; 8,861 assertions passed, 10 failed. Script contract: 19 suites, 18 passed, 1 failed. HTTP acceptance: 4 suites, 3 passed, 1 failed. |
| `./scripts/run-tests.sh --scripts` | 1 | 19 script-contract suites, 18 passed, 1 failed (`verify-i18n-freshness.py`); documentation drift passed (14 checks). |
| `./scripts/lint.sh` | 1 | PHP syntax passed across 526 files; PHPStan level 5 passed (214 files, no errors); PHPCS baseline gate failed. It reported 2,834 errors / 2,231 warnings versus baseline 3,290 / 2,672, plus unbaselined/growing sniff counts. |
| `php -l wp-content/themes/conexao-br-irlanda/header.php` | 0 | No syntax errors |
| `php -l wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | 0 | No syntax errors |
| `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js` | 0 | No syntax errors |
| `git diff --check` | 0 | No whitespace errors |
| `php scripts/generate-registry-docs.php --check` | 0 | 10 plugins validated; 19 generated regions current; zero writes |

The focused Halloween navigation suite ran inside the aggregate command:
**63 passed, 0 failed**.

PHPCS still reports an unbaselined `Universal.CodeAnalysis.NoDoubleNegative`
finding and growth in `Squiz.Commenting.VariableComment.Missing` (5→15),
`Universal.Files.SeparateFunctionsFromOO.Mixed` (5→6),
`WordPress.Files.FileName.InvalidClassFileName` (74→75), and
`WordPress.WP.I18n.MissingTranslatorsComment` (101→102). The newly introduced
header indentation finding was corrected. A targeted scan of the two changed
PHP files did not report the remaining growing/unbaselined sniff categories;
the aggregate gate nevertheless remains red.

The aggregate test failures were:

- `test-eventbrite-rate-limit.php`: G5 time-budget assertion failed.
- `test-export-language.php`: PHP exhausted its 512 MiB memory limit in the
  event-exporter.
- `test-town-sanitization.php`: existing `conexao_town` term-integrity
  assertion failed.
- `test-event-filter-cache.php`: four cache assertions failed alongside
  Polylang locale-null warnings.
- `test-stage41-rest-language.php`: four language/event assertions failed
  alongside a Polylang locale-null warning.
- `verify-i18n-freshness.py`: the theme `.pot` predates `header.php` by 1,612
  seconds.
- `verify-event-occurrence-http.py`: expected weekly and multi-day Laois
  archive counts differed from the local response (0 vs 1 and 2 vs 1).

All named failing test sources are outside the changed-file set. No clean
pre-change full-suite run was recorded, so these failures are **not claimed as
proven pre-existing**.

### Numeric test results

In-process PHP: **114 suites total, 109 passed, 5 failed**; **8,861 assertions
passed, 10 failed**. The PHP memory-exhaustion suite terminated without a
normal assertion total.

### HTTP acceptance

**4 suites**, **3 passed, 1 failed**. The failing matrix was
`verify-event-occurrence-http.py`, with two local archive-count assertions
failing.

### Static analysis

PHP syntax passed (526 files) and PHPStan level 5 passed (214 files, zero
errors). PHPCS and its legacy-baseline gate failed as detailed above.

### Script-contract results

`./scripts/run-tests.sh` script-contract layer: **19 suites, 18 passed,
1 failed** (`verify-i18n-freshness.py`).

### Release / build results

Not in scope; no release was built or deployed.

## 10. Failure proofs / negative tests

No deliberate failure-inducing fixture was introduced or run. The existing
negative-validation assertions in the Halloween navigation suite passed; this
report does not claim a new failure-proof experiment.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | Not measured | 5 |
| Failing assertion count | Not measured | 10 |
| Failing script-contract suites | Not measured | 1 |
| Failing acceptance suites | Not measured | 1 |

A same-environment before/after failure-list comparison was not performed, so
the diff of failing lists is **unknown**. All five failing in-process suites and
the failing acceptance suite are outside this change's files, but are not
declared pre-existing without baseline evidence.

## 12. Known pre-existing failures

None proven. The failing importer, event-filter, REST-language, i18n freshness,
and event-occurrence checks are outside the changed-file set, but a clean
baseline run was not captured.

## 13. Limitations

- The full repository test runner and static-quality gate ran but returned
  failures listed in §9; therefore this change is not reported as fully green.
- Direct browser `sessionStorage` access was restricted, so replay through a
  full cross-document navigation was not independently verified in this
  browser context.
- The integrated-browser screenshot was visually inspected, but its file
  export produced clipped/scaled captures. No screenshot is archived.
- No production verification was performed, by design.

## 14. Evidence paths

- `docs/evidence/2026-10-27-halloween-witch/verification-notes.md` — local SVG
  response, handler behavior, computed three-second duration, bounded sparkles,
  reduced-motion/focus check, and measured viewport geometry.
- Browser screenshot was inspected live but not saved; no screenshot path is
  asserted.

## 15. Documentation updated

Updated [frontend.md](../frontend.md), the theme reference
[conexao-br-irlanda.md](../themes/conexao-br-irlanda.md), and the documentation
and report indexes. Their `_Last verified` entries identify this change. No
procedure was moved to a skill.

## 16. Rollback / recovery

Remove the witch selection/creation path and CSS, remove the SVG URL attribute
from the existing Halloween marker, delete the local SVG, and revert the
corresponding test and documentation/index changes. No database, content,
production, or deployment rollback is required.

## 17. Final status

**Final status: FAIL**

The implementation and focused Halloween suite pass, but the applicable
repository-wide test, i18n, and PHPCS gates returned failures, so an overall
PASS is not justified.

_Last verified: 2026-10-27 by the Halloween witch animation implementation_
