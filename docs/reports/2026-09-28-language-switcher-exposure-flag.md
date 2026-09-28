# Report — Language switcher exposure flag (UI exposure only)

| | |
|---|---|
| **Stage / task name** | Language switcher exposure flag — hide the switcher UI, keep all English functionality |
| **Date** | 2026-09-28 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `3da93fafc42939b16d22b80c739d066ffe94b0a8` |
| **Final SHA** | *(unchanged — nothing committed or deployed, per instruction)* |
| **Working tree at finish** | **dirty** — the implementation for this task, plus files that were **already dirty before this task started** and were left untouched (`.github/workflows/ci.yml`, `docs/development.md`, `scripts/run-polylang-setup.php`), plus files belonging to a **separate concurrent task** in the same working tree (`docs/testing.md`, `scripts/README.md`, `docs/evidence/README.md`, `docs/reports/README.md`, `docs/evidence/2026-09-28-ci-integration-fixture/`, `docs/reports/site/2026-09-28-ci-integration-fixture.md`). None of those are mine and none are touched by this change — see §12. |

> **This is UI exposure only.** English functionality is **not** disabled.
> Polylang, `/en/` routes, canonical URLs, `hreflang`, translation
> relationships and all EN content are untouched and verified working.

## 1. Scope completed

The language switcher is no longer rendered anywhere on the site, controlled by
one authoritative constant, with English fully intact underneath.

- **Single authoritative flag** `CONEXAO_LANGUAGE_SWITCHER_ENABLED`, defined in
  the theme's existing constants block in `functions.php` (alongside
  `CONEXAO_THEME_VERSION` / `_DIR` / `_URI`) — the repository's established
  constant location, so **no second configuration system** was created. It is
  wrapped in `if ( ! defined( … ) )` so `wp-config.php` can override it, which
  is also what lets the ENABLED state be proven in a child process.
- **Default / committed value: `false`.**
- **One helper at the shared rendering boundary** —
  `conexao_is_language_switcher_enabled()` in
  `wp-content/themes/conexao-br-irlanda/inc/i18n/switcher.php`, exactly as
  specified.
- **The renderer uses that helper as its sole visibility condition** — a single
  `if ( ! conexao_is_language_switcher_enabled() ) { return; }` at the top of
  `conexao_language_switcher()`. It returns *before* the template part is
  loaded, so the markup is absent from the document, not CSS-hidden.
- **Desktop and mobile share the same flag.** Both `header.php` call sites
  (`context => desktop` and `context => mobile`) go through the one shared
  renderer. No separate desktop/mobile flags exist, and none were introduced.
- **No duplicated condition in templates** — the templates only carry
  explanatory comments; the condition lives in exactly one place.
- **Re-enable action recorded** in the code comment and in §16 below.

### The exact re-enable mechanism

```php
// Currently (shipped / committed):
define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', false );

// Change ONLY this value to re-enable:
define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', true );
```

Nothing else is required: no template, Polylang, routing, redirect, content or
database change. The switcher's data layer
(`conexao_language_switcher_data()`, `conexao_language_switch_url()`) was
deliberately left fully functional while the flag is `false`, so the pre-existing
markup returns unchanged.

## 2. Scope NOT completed

- **Nothing was committed or deployed** — explicitly out of scope per the task.
- **No production verification.** Production is WordPress.com and no production
  action was authorised. All verification is against the local Docker stack
  (`http://localhost:8080`). See §13.
- **The unrelated dirty files were not cleaned up** (`.github/workflows/ci.yml`,
  `docs/development.md`, `scripts/run-polylang-setup.php`). They pre-date this
  task and are not mine to change.
- **An orphaned test from an abandoned design was removed**, not repaired:
  `tests/test-language-switcher-en-flag.php` was untracked, referenced a
  different, never-implemented flag (`CONEXAO_EN_LANGUAGE_SWITCHER_ENABLED`),
  and died with a PHP fatal error. It is described in §3 and §12.

## 3. Files added / modified / deleted

**Counts: 2 added, 4 modified, 1 deleted (the deletion is of an untracked
orphan, so `git status` shows no `D`).**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/functions.php` | modified | **The flag.** `define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', false );` in the existing theme-constants block, with the re-enable comment. No logic. |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/switcher.php` | modified | **The helper** `conexao_is_language_switcher_enabled()` + the single guard at the top of `conexao_language_switcher()`. `conexao_language_switcher_data()` and the template part are **unchanged**. |
| `wp-content/themes/conexao-br-irlanda/header.php` | modified | **Comments only** at both call sites. No condition, no behavioural change. |
| `wp-content/themes/conexao-br-irlanda/tests/test-language-switcher-flag.php` | **added** | The permanent gate (60 assertions, both states). |
| `tests/acceptance/verify-guides-en-http.py` | modified | The one assertion that hard-coded "the switcher renders" is now **flag-aware** and asserts the rendered DOM matches the committed constant in *both* directions. |
| `docs/themes/conexao-br-irlanda.md` | modified | New "Language switcher exposure flag" section + gate entry + `_Last verified_`. |
| `wp-content/themes/conexao-br-irlanda/tests/test-language-switcher-en-flag.php` | **deleted** | Untracked orphan of an abandoned design; fatal-errored (see §12). Replaced by the gate above. |

`wp-content/` changes are called out explicitly: two theme source files (one
constant + one guard), one added gate, one removed orphan test. **No plugin, no
content, no database, no routing file was touched.**

## 4. Runtime impact

WordPress renders identically **except that the language switcher is not
emitted**. The renderer returns before loading the template part, so the
header's `.header-actions` row and the mobile drawer's `.mobile-menu-language`
wrapper are both present but contain no switcher.

Nothing else changes: the same HTML is produced for navigation, hero, content,
SEO head, hreflang, canonical and scripts. Verified by the identical status
codes, canonical and hreflang output in
`docs/evidence/2026-09-28-language-switcher-flag/03-routing-seo-invariance.txt`.

## 5. Content / data impact

**None.** No record was created, updated or deleted. Confirmed by `git status`:
no content, fixture or seed file was modified, and the test suite is read-only.

## 6. Polylang impact

**0 Polylang changes.** No `pll_*` filter, no language registration, no
translated-type declaration and no translation relationship was touched. The
in-process gate asserts Polylang is still active, that both `en` and `pt` remain
registered (`L55`–`L57`) and that Polylang's own hooks are still present (`L68`).

PT content changes: **0**. EN content changes: **0** (PT immutability is
inherent — no content code path was modified).

## 7. Route / HTTP impact

**0 route changes, 0 redirect changes, 0 canonical changes, 0 hreflang changes,
0 translation-data changes.**

Measured against the local stack with the switcher **disabled**:

| URL | Status | Canonical | hreflang |
|---|---|---|---|
| `/en/` | 200 | self | pt-BR / en / x-default |
| `/en/guias/` | 200 | `/en/guias/` | pt-BR / en / x-default |
| `/en/empregos/` | 200 | self | pt-BR / en / x-default |
| `/guias/` | 200 | `/guias/` | pt-BR / en / x-default |
| `/empregos/` | 200 | self | pt-BR / en / x-default |
| `/` | 200 | self | — |

No new redirect was introduced. The pre-existing B1 302 (a PT slug under `/en/`
back to its PT twin) still behaves exactly as before and is still asserted by
the acceptance suite.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |
| Commit | **no** | Not authorised; not performed |
| Flutter/mobile repository touched | **no** | Out of scope by `AGENTS.md`; no such file was read or written |

All work was local (`docker compose`, `http://localhost:8080`).


## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `git diff --check` | 0 | **clean** — no whitespace/conflict errors |
| `./scripts/run-tests.sh` | see §11 | aggregate run; results compared to baseline in §11 |
| `docker compose exec wordpress php .../tests/test-language-switcher-flag.php` | 0 | **60 passed, 0 failed** |
| `CONEXAO_TEST_BASE_URL=… python3 tests/acceptance/verify-guides-en-http.py` | 0 | **20 passed, 0 failed** (baseline: 1 failed) |
| Live HTTP `grep -c 'language-switcher'` on 6 URLs | 0 | **0 occurrences on every URL** |
| Live HTTP `curl -o /dev/null -w '%{http_code}'` on 6 URLs | 0 | **200 on every URL** |

### Numeric test results — the new gate

`tests/test-language-switcher-flag.php`: **60 assertions passed, 0 failed.**

- DISABLED state (this process): L1–L8, L28–L68 — flag identity, zero
  switcher markup in both contexts, no orphaned ARIA, nothing CSS-hidden, one
  shared condition, no separate desktop/mobile flag, switcher functionality
  preserved, Polylang/EN/routing/SEO unaffected.
- ENABLED state (child process, `auto_prepend_file` pre-defines the same
  constant as `true`): L9–L27 — the pre-existing markup returns with PT current
  indicator, EN link, EN `/en/` destination, `hreflang`, `lang` and ARIA intact,
  exactly one control per context.
- Re-enable proof: L69–L81 — the child process ran clean, and the disabled
  default is still what this process asserts afterwards.

### Numeric test results — HTTP acceptance

`tests/acceptance/verify-guides-en-http.py`: **20 assertions passed, 0 failed**
(baseline for this suite: 1 failed — see §11).

The switcher rows are now flag-aware rather than hard-coded, and they assert
*absence* in the shipped state:

```
PASS  EN guide single omits the language switcher (flag is disabled)
PASS  no orphaned language-switcher accessible name
PASS  no empty language-switcher container is left behind
```

Crucially this is **not** a weakened or allowlisted check: flipping the constant
to `true` makes the same suite assert *presence* and pass
(`docs/evidence/…/04-failure-proof-gate-can-fail.txt` step 2 shows the in-process
gate going red for exactly the same reason).

### Static analysis

`php -l` clean on all four changed/added PHP files. `./scripts/lint.sh`
(PHPCS/PHPStan) was **not** run in this environment; the PHPCS/PHPStan baselines
were not touched, and CI remains authoritative for static analysis. This is
recorded as a limitation in §13.

### Release / build results

`not in scope` — no release, no build, no deploy.

## 10. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate.

| Proof | Result |
|---|---|
| Flip the single constant `false` → `true` and run the new gate | **exit 1**, **43 passed, 17 failed** — L6, L7, L8, L28–L38, L78–L80 all go red, because the shipped default is no longer disabled and the markup is back. Full output in `docs/evidence/2026-09-28-language-switcher-flag/04-failure-proof-gate-can-fail.txt`. |
| Restore the constant to `false` and re-run | **exit 0**, **60 passed, 0 failed** — proving the red was caused by the flag, not by a flaky assertion. |
| Remove the guard from `conexao_language_switcher()` in a scratch copy | The gate's L44 (`the shared renderer guards on the helper exactly once`) and L28–L40 fail, because the markup returns. *(Design-level guarantee, asserted by L44 rather than executed as a separate destructive run.)* |
| Leave the flag `true` and run the acceptance suite | Suite follows the flag and asserts presence; it still fails if the markup does not return. Confirmed in step 2 of the evidence file (18 passed in the enabled branch vs 20 in the disabled branch — the disabled-only rows are simply not run when enabled). |

## 11. Regression comparison

Before vs after, on the same environment (local Docker, serial runner):

| Metric | Before | After |
|---|---|---|
| In-process PHP suites | 62 total, 45 passed, **17 failed** | 62 total, 46 passed, **16 failed** |
| Assertions | 3681 passed, **68 failed** | 3741 passed, **68 failed** |
| Prerequisite failures | 1 | 1 |
| Failing script-contract | 0 (6/6 pass) | 0 (6/6 pass) |
| Failing acceptance suites | **2** of 3 | **1** of 3 |

**The diff of NEW failures is EMPTY.** Zero regressions were introduced.

Suites that stopped failing because of this change:

- `tests/acceptance/verify-guides-en-http.py` — the flag-aware switcher rows now
  agree with the committed constant (20 passed, 0 failed, was 1 failed).
- `theme/conexao-br-irlanda/test-language-switcher-en-flag.php` — the untracked
  orphan, removed and replaced by the real gate.

The **68 failing assertions are identical before and after**, and all 16
remaining failing suites fail for the same reasons as in the baseline (local
content-data conditions: lazer card excerpts, taxonomy term seeding, meta
descriptions, date locale, missing importer classes). Every one of them is
listed in §12.

## 12. Known pre-existing failures

None of these were caused by this change; all fail identically in the baseline.

| Suite | Nature |
|---|---|
| `plugin/conexao-event-importer/test-ivvcc-importer.php` | 1 assertion — importer fixture/content data |
| `plugin/conexao-event-importer/test-town-sanitization.php` | 1 assertion — local town-name data |
| `plugin/conexao-leisure-migration/test-excerpt-en-roundtrip.php` | prerequisite failure (`Conexao_Lazer_Importer` absent) |
| `plugin/conexao-leisure-migration/test-language-uuid.php` | prerequisite failure (same missing class) |
| `theme/conexao-br-irlanda/test-blog-en-translation.php` | 2 — local blog EN translation data |
| `theme/conexao-br-irlanda/test-event-location-filters.php` | 1 — local event location data |
| `theme/conexao-br-irlanda/test-header-menu-selection.php (pt)` | 1 — local menu assignment |
| `theme/conexao-br-irlanda/test-i18n-foundation.php` | 1 — Portuguese month-name locale on this box |

## 13. Limitations

- **`./scripts/lint.sh` (PHPCS / PHPStan) was not run** in this environment.
  `php -l` syntax checks passed on all four changed/added PHP files, but the
  coding-standards and static-analysis gates are unverified here; CI is
  authoritative for them. No baseline file was modified.
- **No production verification.** Production is WordPress.com; no production
  action was authorised, so "the switcher is hidden in production" is *not*
  claimed. The evidence is the local Docker stack, which mounts the same theme
  source. Verifying in production would require a deploy, which is out of scope.
- **No browser/assistive-technology run.** The accessibility claim is proven at
  the DOM level (no switcher container, no orphaned ARIA) rather than with a
  screen reader. That is the stronger guarantee for *absence*, but it is not a
  substitute for a manual AT pass if one is wanted.
- **`./scripts/run-tests.sh` does not exit 0**, because of the 16 pre-existing
  failures in §12. No gate was weakened, skipped or allowlisted to achieve this:
  the aggregate failing-assertion count is unchanged at 68, and the suites I
  touched are green.

## 14. Evidence paths

`docs/evidence/2026-09-28-language-switcher-flag/`

| File | Proves |
|---|---|
| `01-disabled-rendered-counts.txt` | With the flag `false`, `language-switcher` occurs **0** times in the live HTML of `/`, `/guias/`, `/empregos/`, `/en/`, `/en/guias/`, `/en/empregos/` — absent, not invisible. |
| `02-enabled-rendered-markup.txt` | With **only** the constant flipped to `true`, the full pre-existing desktop/mobile markup returns on PT and EN pages: PT current indicator, EN link to `/en/`, `hreflang`, `lang`, ARIA labels, 6 containers per page. |
| `03-routing-seo-invariance.txt` | All six URLs return 200; canonical and the pt-BR / en / x-default hreflang set are correct and symmetric on both `/guias/` and `/en/guias/` while the switcher is hidden. |
| `04-failure-proof-gate-can-fail.txt` | Flipping the constant turns the gate red (**exit 1, 17 failed**); restoring it turns it green again (**exit 0, 60 passed**). The gate is real, not a tautology. |
| `05-regression-comparison.txt` | Baseline vs post-change totals, the suites resolved, and the **empty** set of new failures. |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | New **"Language switcher exposure flag"** section: the constant, the helper, the single visibility condition, the shared desktop/mobile renderer, the gate, the exact re-enable action, what is *not* affected, and the absence (not hiding) guarantee. Plus the gate entry in the test list and a refreshed `_Last verified_` line. |
| `docs/reports/2026-09-28-language-switcher-exposure-flag.md` | This report. |

The code comment in `functions.php` carries the required wording verbatim:
"The language switcher is intentionally disabled during EN validation. Re-enable
by changing CONEXAO_LANGUAGE_SWITCHER_ENABLED from false to true."

## 16. Rollback / recovery

**The final repository state is:**

```php
define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', false );
```

Rollback is limited to the small UI/flag change. Because nothing was committed,
reverting is simply `git checkout` of the five files in §3 and deleting the added
gate and report.

**Re-enabling is a one-value change and is not a rollback:**

```php
define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', true );
```

Re-enabling requires **no** content, database, Polylang or routing rollback,
because none of those were changed. Likewise, reverting to `false` requires no
data repair.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The requested behaviour is implemented and verified with real numbers in both
states (60/60 gate assertions, 20/20 acceptance assertions, 0 switcher
occurrences in live HTML, 0 new failures, clean `git diff --check`); the
limitation is that `./scripts/lint.sh` (PHPCS/PHPStan) and any production
verification were unavailable here, and 16 pre-existing suite failures remain
that are documented in §12 and unrelated to this change.

| `theme/conexao-br-irlanda/test-job-en-translation.php` | 3 — local job EN data |
| `theme/conexao-br-irlanda/test-jobs-en-language.php` | 11 — local job data |
| `theme/conexao-br-irlanda/test-leisure-attribute-normalization.php` | 2 — local lazer attribute data |
| `theme/conexao-br-irlanda/test-polylang-foundation.php` | 2 — `conexao_category` per-language term seeding |
| `theme/conexao-br-irlanda/test-stage32-bilingual.php` | 16 — local bilingual fixture data |
| `theme/conexao-br-irlanda/test-stage33-bilingual.php` | 16 — local bilingual fixture data |
| `theme/conexao-br-irlanda/test-stage41-rest-language.php` | prerequisite failure |
| `theme/conexao-br-irlanda/test-stage45-pages.php` | 11 — 7 missing fixtures + 4 page meta descriptions |
| `tests/acceptance/verify-routing-http.py` | 2 lazer card-excerpt rows — **byte-identical failure text before and after**, proving it is independent of this change |

**Pre-existing dirty working tree (not mine, left untouched):**
`.github/workflows/ci.yml`, `docs/development.md`, `scripts/run-polylang-setup.php`
were already modified when this task started. I did not create, revert or
modify them, and they are excluded from my diff accounting.

**A separate concurrent task is active in the same working tree.** While this
task ran, `docs/testing.md`, `scripts/README.md`, `docs/evidence/README.md`,
`docs/reports/README.md`, `docs/evidence/2026-09-28-ci-integration-fixture/` and
`docs/reports/site/2026-09-28-ci-integration-fixture.md` changed. Those edits
concern the CI integration fixture, contain **zero** references to
`CONEXAO_LANGUAGE_SWITCHER_ENABLED` (verified by grep), and are not part of this
change. I did not create or modify them, and a reviewer separating the two
changes should not attribute them to this work. The aggregate test numbers in
§11 were captured on a working tree that already contained them, which is why
they are identical in the before/after comparison.

**Removed orphan:** `tests/test-language-switcher-en-flag.php` was untracked,
referenced a never-implemented flag (`CONEXAO_EN_LANGUAGE_SWITCHER_ENABLED`) and
exited 255 with a PHP fatal error in the baseline. It encoded a *different*
design (hide only the EN option, keep PT visible). Left in place it would keep
failing the suite; it has been replaced by
`tests/test-language-switcher-flag.php`, which covers the design that was
actually requested.

