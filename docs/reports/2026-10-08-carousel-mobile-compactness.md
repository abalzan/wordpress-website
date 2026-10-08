# Report — Featured Apoiadores carousel mobile compactness

| | |
|---|---|
| **Stage / task name** | Featured Apoiadores carousel — mobile compactness without losing access |
| **Date** | 2026-10-08 |
| **Author / agent** | Cline |
| **Branch** | master |
| **Start SHA** | 47d3f2a (sponsors single-page work by a concurrent task was in flight; committed as 419c912 during this task) |
| **Final SHA** | 419c912 + uncommitted hunk in `main.css` (`1dcb7364`) + untracked browser suite |
| **Working tree at finish** | dirty, deliberately: `M main.css` (the fix), `M docs/testing.md` (browser layer documented), `?? tests/browser/verify-sponsor-carousel-mobile-browser.py` (the regression), `?? docs/evidence/2026-10-08-carousel-mobile/` (machine evidence) |
| **Skills followed** | `wp-frontend-perf`, `wp-update-docs`, `wp-testing` |
| **Authoritative docs consulted** | `docs/frontend.md`, `docs/testing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/templates/report.md`, `docs/audit/2026-10-03-ui-ux-audit.md` |

## 1. Scope completed

The mobile Featured Apoiadores (hero sponsors) carousel is compact on phones
without sacrificing access to any Apoiador or the existing accessibility model:

- One isolated CSS hunk in `wp-content/themes/conexao-br-irlanda/assets/css/main.css`,
  inside `@media (max-width: 768px)` (~line 928): on the narrow portrait panel the
  pagination dots wrap into a centred grid whose column count follows the
  available width (one row while they fit, two/three rows when they cannot).
- Every dot stays a real `<button>`, a full 24×24px target (WCAG 2.5.8, never
  overlapping), directly tappable — no dot hidden, truncated or decorative.
- Desktop presentation is byte-identical (38px block, `6px 10px` padding, 8px
  visual dot, 20×8px active pill, 10px inset, one row, 24px centres).
- New opt-in browser regression `tests/browser/verify-sponsor-carousel-mobile-browser.py`
  (mobile geometry, a11y, keyboard nav, live region, last-dot direct navigation,
  desktop-unchanged, `--negative-control`) and a `docs/testing.md` subsection.

## 2. Scope NOT completed

- The 8 failing `run-tests.sh` suites were **not** fixed: every one fails
  identically with the change stashed (§11/§12), so they are out of scope unless
  unavoidable. (§12 names each with its provenance.)
- A same-run full `run-tests.sh` pass was reported, but the aggregate is
  `TESTS FAILED` because of those pre-existing suites — hence
  `PASS WITH LIMITATION`, not `PASS` (§17).

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | modified (1 hunk, +26/−6) | the only `wp-content/` change; mobile dots only |
| `tests/browser/verify-sponsor-carousel-mobile-browser.py` | added (untracked) | regression suite + negative control |
| `docs/testing.md` | modified | new subsection + `_Last verified` line |
| `docs/reports/2026-10-08-carousel-mobile-compactness.md` | added (this file) | |
| `docs/evidence/2026-10-08-carousel-mobile/` | added (8 files) | machine evidence |

**2 modified, 3 added (incl. the evidence dir), 0 deleted.** Nothing outside the
areas the plan named. The concurrent sponsors-single-page work is committed
upstream (`e3f0ba5`, `91f8ca6`, `419c912`) and is **not** this task's change.

## 4. Runtime impact

WordPress renders the same markup and serves the same slides; only the mobile
presentation of the dots block changes (wraps instead of overflowing). No JS
modified, no second carousel, no route/redirect/sitemap effect. Verified by the
browser suite (§9) and the blocking theme suite
`test-stage7-hero-sponsors.php` (10 passed, 0 failed).

## 4a. Scope integrity

Not documentation-only (`wp-content/` diff is non-empty), so this table is N/A
except: no production write, deploy, upload or activation; no production
content/Polylang/DB change; no Flutter/mobile repository accessed; no `.env` or
secret change.

## 5. Content / data impact

None. No records created/updated/deleted; the harness clones dot buttons in the
DOM only, never in WordPress.

## 6. Polylang impact

None. PT content immutable; no EN record created; locale/cache untouched.

## 7. Route / HTTP impact

None. `verify-routing-http.py — 118 passed, 0 failed` in the final full run.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `php scripts/generate-registry-docs.php --check` | 0 | 10 plugins, 19 regions current |
| `./scripts/lint.sh` | 0 | syntax clean, no new PHPCS violations, PHPStan clean |
| `docker compose exec -T wordpress php …/test-stage7-hero-sponsors.php` | 0 | 10 passed, 0 failed |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | 14 passed, 0 failed |
| `python3 tests/browser/verify-sponsor-carousel-mobile-browser.py` | 0 | **236 passed, 0 failed** |
| `… --negative-control` | 0 (control OK) | **211 passed, 25 mobile failures, 0 desktop failures** |
| `./scripts/run-tests.sh` (final, this tree) | non-zero | PHP 101/96/5, assertions 7758/7; scripts 19/17/2; acceptance 4/3/1 — the 8 failures are all §12 pre-existing |

### Numeric test results

In-process PHP: **101 suites total, 96 passed, 5 failed**; **7758 assertions
passed, 7 failed**. Script-contract: **19 suites, 17 passed, 2 failed**. HTTP
acceptance: **4 suites, 3 passed, 1 failed**.

### HTTP acceptance

**4 suites**, matrices `verify-event-occurrence-http` (failing, pre-existing),
`verify-guides-en-http` (20/0), `verify-release-http` (44/0),
`verify-routing-http` (118/0).

### Static analysis

`./scripts/lint.sh` exit 0 (evidence `lint.out`).

### Script-contract results

`--scripts` layer inside the full run: 17 passed, 2 failed — both pre-existing
(`verify-eventbrite-token-retirement`, `verify-i18n-freshness`; §12).

### Release / build results

Not in scope.

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Browser `--negative-control` (pre-fix mobile CSS re-injected in `@media (max-width: 768px)`) | 25 mobile checks red (stage overhang 258px vs ~133–161px, 2 clipped dots, unreachable dot 10 on all 5 phones, compactness budget blown), **0 desktop failures** → `NEGATIVE CONTROL OK` |
| Browser gap metric: consecutive-DOM `|dx|` vs same-row `|dx|` | probe bug found during verification (wrapped rows offset by one grid step measure ~24px diagonally but never touch); corrected to same-row adjacency, re-run green 236/0 |
| `measure-before.json` first capture at HEAD looked fixed | exposed a mid-run tree restore by the concurrent task; forensic sequence discarded and re-run with hash-verified states (§11) |
## 11. Regression comparison

BEFORE = tree at B-original (`e98f7764`, verified by `git show HEAD:` hash
equality, scratch `carousel-work/gates/main.css.head`). AFTER = tree at C-fixed
(`1dcb7364`). Same environment (local Docker WordPress, real Chromium): revert →
measure → restore → measure, hashes logged at every swap.

| | Before (B-original) | After (C-fixed) |
|---|---|---|
| Failing in-process suites | 5 (export-language, ics-occurrence-dates, town-sanitization, event-location-filters, stage41-rest-language) | same 5 |
| Failing assertion count (PHP) | 7 | 7 (`7758 passed, 7 failed` final) |
| Failing script-contract | 2 (eventbrite-token-retirement, i18n-freshness) | same 2 |
| Failing acceptance assertions | 4 (event-occurrence-http) | same 4 |

The diff of the failing lists is **empty**, except one non-deterministic
assertion inside the already-failing `test-stage41-rest-language.php`: the first
full run measured 218/4, a same-tree replay 217/5, the final run 218/4 again.
Same tree, different result ⇒ flaky, not attributable to this change (§12).
Exit codes of all 8 suites are identical at HEAD and with the change.

Before/after carousel geometry (10-dot, dots block W×H, overhang L/R vs tile,
clipped, unreachable):

| Viewport | Before block | Before L/R | clip/unreach | After block | After L/R | clip/unreach |
|---|---|---|---|---|---|---|
| 360×800 | 258×34 | −60.5/−60.5 | 2/dot 10 | 130×50 | +3.5/+3.5 | 0/none |
| 375×812 | 258×34 | −60.2/−60.2 | 2/dot 10 | 130×50 | +3.8/+3.8 | 0/none |
| 390×844 | 258×34 | −56.9/−56.9 | 2/dot 10 | 130×50 | +7.1/+7.1 | 0/none |
| 412×915 | 258×34 | −52.2/−52.2 | 2/dot 10 | 130×50 | +11.8/+11.8 | 0/none |
| 430×932 | 258×34 | −48.5/−48.5 | 1/dot 10 | 154×50 | +3.5/+3.6 | 0/none |
| 768×1024 | 258×34 | +25.9/+25.9 | 0/none | 250×26 | +29.9/+29.9 | 0/none |
| 1440×1000 | 262×38 | +35.3/+35.3 | 0/none | 262×38 | +35.3/+35.3 | 0/none |

Dot buttons `10→10`, active dots `1→1`, labelled `10→10`, `aria-current` 1→1,
page horizontal overflow `0→0` on every width; desktop row identical down to
padding (`6px 10px`), visual (8px), active pill (20×8) and inset (10px).

## 12. Known pre-existing failures

Each fails identically with the change stashed (tracked tree at HEAD `47d3f2a`,
same commands, exit codes `255,1,1,1,1,1,1,1` in both columns; evidence
`subset-current-tree.log` / `subset-head.log`):

| Suite | What it is | Proof it is not this change |
|---|---|---|
| `conexao-event-importer/test-export-language.php` (255) | memory exhaustion | identical exit at HEAD; a CSS hunk cannot OOM PHP |
| `conexao-event-importer/test-ics-occurrence-dates.php` (1) | date-sensitive "next occurrence" assertion (`2026-10-06`) | identical at HEAD; wall-clock |
| `conexao-event-importer/test-town-sanitization.php` (1) | `conexao_town` contaminated-term local-data condition | identical at HEAD |
| `theme/…/test-event-location-filters.php` (1) | event location-filter assertion | identical at HEAD |
| `theme/…/test-stage41-rest-language.php` (4; flaky 5th, §11) | REST language assertions incl. a non-deterministic EN-lifecycle one | identical exit at HEAD; varies run-to-run on the same tree |
| `tests/scripts/verify-eventbrite-token-retirement.py` | `tests/experiments/g16-source-eligibility.php` references the retired token key | identical at HEAD; static repo-content check |
| `tests/scripts/verify-i18n-freshness.py` | two `.pot` catalogues older than their sources | identical at HEAD |
| `tests/acceptance/verify-event-occurrence-http.py` (4) | time-dependent Laois archive occurrence series | identical at HEAD |

Out of scope unless unavoidable. A CSS-only mobile-dots change touches none of
their code paths (importer, REST language, token scan, catalogues, archive
dates).

## 13. Limitations

- The 8 pre-existing failures above mean the aggregate `run-tests.sh` exit is
  non-zero; nothing in this task fixes them.
- `measure.py` is gitignored scratch, not a committed suite — the durable
  regression is the committed browser suite; the scratch before/after numbers
  live on as `before-after.json` evidence only.
- The local database carries 4 featured Apoiadores, so the 10-dot case is a
  DOM-cloned production shape (same markup contract, no carousel JS for clones);
  interaction checks run on the server-rendered DOM. No production access, no
  profiler, no device lab (Chromium viewports only).
- A concurrent task committed sponsors-single-page work mid-verification
  (`e3f0ba5`, `91f8ca6`, `419c912`); the one contaminated `measure-before.json`
  was discarded and the forensic sequence re-run with hash-verified states.

## 14. Evidence paths

`docs/evidence/2026-10-08-carousel-mobile/`: `before-after.json` (all 7
viewports × real/dots10, both states), `browser-suite.out` (236/0),
`browser-negative.out` (211 + 25 mobile red + 0 desktop),
`run-tests-final.log` (full aggregate), `subset-current-tree.log` /
`subset-head.log` (8-suite HEAD-vs-tree provenance), `lint.out`, `stage7.out`.

## 15. Documentation updated

`docs/testing.md`: new subsection "The Featured Apoiadores carousel
mobile-compactness browser regression" + `_Last verified: 2026-10-08 …` line.
Drift gate re-run green (14/0). No procedure was moved into a skill.

## 16. Rollback / recovery

Revert the single `main.css` hunk (`git checkout --` the file, or apply
`carousel-work/gates/main.css.head` — gitignored scratch copy of B-original):
dots return to the one-row layout and the defect returns with them (proven by
the negative control). Rollback does not touch content, routes or translations.
The browser suite file itself is additive; deleting it removes the regression
coverage.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The carousel fix is complete and proven (236/0 browser assertions, 25-failure
negative control with 0 desktop fallout, truthful before/after table, desktop
byte-identical), but the aggregate `run-tests.sh` gate is red on 8 pre-existing,
HEAD-identical failures this task does not own.

_Last verified: 2026-10-08 by the Featured Apoiadores carousel mobile-compactness fix_

