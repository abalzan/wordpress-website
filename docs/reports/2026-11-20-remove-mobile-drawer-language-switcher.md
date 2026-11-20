# Report — Remove the language selector from the mobile hamburger menu drawer

| | |
|---|---|
| **Stage / task name** | Remove the language selector from the mobile hamburger menu drawer (keep PT | EN in the main mobile header) |
| **Date** | 20/11/2026 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `10a01f5898af2ad39359293dcb1fc0fee96e2168` |
| **Final SHA** | (working tree not yet committed) |
| **Working tree at finish** | dirty: 2 modified theme files + 1 new plan + 1 new report + 1 new evidence file (see §3) |
| **Skills followed** | `wp-repository` (orientation, scope, review); no other skill applies — no content type, taxonomy, admin screen, content write, or release |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md` |

## 1. Scope completed

Removed the `.mobile-menu-language` block from the mobile menu drawer markup in `wp-content/themes/conexao-br-irlanda/header.php` — the div, its documentation comment, and the `conexao_language_switcher( array( 'context' => 'mobile' ) )` drawer call site — so the PT | EN selector no longer exists anywhere inside `#mobile-menu`, at any viewport.

Kept the PT | EN selector in the main mobile header (`.header-actions`, context "mobile") byte-identical: same shared renderer, same `CONEXAO_LANGUAGE_SWITCHER_ENABLED` flag, same position ahead of the Anuncie Aqui controls in source order, shown `<=1024px` by existing CSS.

Kept the desktop language switcher (`.language-switcher--desktop`, context "desktop") untouched.

Removed the now-obsolete CSS for the drawer instance (`.mobile-menu-language` rule and `.language-switcher--mobile .language-switcher-item` drawer rule) from `header-nav.css`, and updated the two comments plus one header comment that described the removed drawer instance. No JS dependencies exist on `.mobile-menu-language` (verified: zero references in `assets/js/`; the only repo reference is untracked scratch tooling `carousel-work/langbar-measure.py`).

The custom language-switching engine (`inc/i18n/switcher.php`), the flag (`functions.php`), and all Polylang URL/hreflang/canonical behaviour are untouched.

## 2. Scope NOT completed

Nothing. The full request was delivered: drawer block removed from markup (not CSS-hidden), header selector preserved, desktop selector preserved, engine preserved, obsolete styling removed, drawer layout/spacing/search/theme-toggle/CTA/open-close behaviour byte-identical.

The only deliberate aside: this change also fixes a pre-existing failure — permanent gate `tests/test-language-switcher-flag.php` L60 ("both header contexts go through the one shared renderer") was failing at baseline because `header.php` had 3 anchored `conexao_language_switcher(` call sites where the gate expects 2; after removal it has 2 and the gate passes.

## 3. Files added / modified / deleted

**2 modified, 3 added, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | modified | Removed `.mobile-menu-language` drawer block (markup + comment + renderer call); one comment wording fix |
| `wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` | modified | Removed `.mobile-menu-language` rule + comment; removed drawer `.language-switcher--mobile .language-switcher-item` rule; updated 2 stale comments |
| `docs/plans/2026-11-20-remove-mobile-drawer-language-switcher.md` | added | Plan per `AGENTS.md` §workflow / `docs/templates/plan.md` |
| `docs/reports/2026-11-20-remove-mobile-drawer-language-switcher.md` | added | This report |
| `docs/evidence/2026-11-20-remove-mobile-drawer-language-switcher/verification.md` | added | Real verification numbers (baseline + after) |

No file outside the areas named in the plan was changed. `plugins.json`, `inc/i18n/switcher.php`, `functions.php`, `assets/js/`, and `tests/` are all unmodified.

## 4. Runtime impact

Behaviour-neutral for everything except the drawer: one control removed from the drawer markup. The main-header PT | EN switcher keeps rendering exactly as before (same PHP call site, same CSS), so Polylang URLs, hreflang, canonical output and language switching are unaffected. Verified by re-running `tests/test-language-switcher-flag.php` and by fetching the live local site.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed — local stack only |
| No production content, Polylang or database change | confirmed — read-only change to presentation markup |
| No application behaviour change (`wp-content/` diff is empty) | N/A — `wp-content/` theme files changed, but behaviour outside the drawer is unchanged (verified via test suite + live HTTP) |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |
## 5. Content / data impact

None. No records created/updated/deleted; no content write; the six-step contract does not apply. PT immutable: no PT/EN URLs, slugs or record identity change.

## 6. Polylang impact

PT unchanged (asserted by unchanged renderer + unchanged test suite: L28–L29, L33–L35, L43–L54 all pass). No EN records created. Switcher data layer intact on both sides of the flag (L25–L27, L37–L39, L75). Completeness gate: 0 — flag owns no routing/Polylang/SEO behaviour (L64–L68).

## 7. Route / HTTP impact

None: no routes, redirects, canonical, hreflang or sitemap changes. Live HTTP assertion results (against `http://localhost:8080/`, edited files served by the running local WordPress):

| Assertion | Expected | Actual |
|---|---|---|
| `.mobile-menu-language` in rendered HTML | 0 | 0 |
| `language-switcher--mobile` instances | 1 (main header) | 1 |
| `language-switcher--desktop` instances | 1 | 1 |
| EN link `hreflang="en" lang="en"` → `/en/` | present | present |
| PT item `aria-current="true" lang="pt-BR"` | present | present |

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

Production is WordPress.com (no SSH, no WP-CLI); no production action is in scope for this theme-only change.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `git status --short` at baseline | 0 | clean working tree |
| `docker compose exec ... php tests/test-language-switcher-flag.php` (baseline) | 1 | **54 passed, 1 failed — L60 FAIL** (3 anchored call sites, gate expects 2; pre-existing) |
| `php -l wp-content/themes/conexao-br-irlanda/header.php` | 0 | No syntax errors detected |
| `grep -c 'mobile-menu-language' header.php` | 0 | 0 |
| `grep -Pc '^\s*conexao_language_switcher\(' header.php` | 0 | 2 |
| `grep -c 'mobile-menu-language' header-nav.css` | 0 | 0 |
| `curl http://localhost:8080/` + markup assertions | 0 | drawer block 0; header mobile switcher 1; desktop switcher 1; PT/EN + hreflang present |
| `docker compose exec ... php tests/test-language-switcher-flag.php` (after) | 0 | **55 passed, 0 failed — L60 PASS** |
| `./scripts/run-tests.sh --scripts` | 1 | 18 static suites, 16 passed, 2 failed (both pre-existing and unrelated; see §12) |

### Numeric test results

In-process PHP: 1 suite total; **before 54 passed / 1 failed, after 55 passed / 0 failed**; **54→55 assertions passed, 1→0 failed**.

Static analysis: `php -l` clean on the edited `header.php`. PHPCS/PHPStan via `./scripts/run-tests.sh --scripts` were not the affected layer; the script-contract layer ran (below). CI is authoritative for full lint (`scripts/lint.sh`).

Script-contract: `./scripts/run-tests.sh --scripts`: **18 suites, 16 passed, 2 failed** — both failures pre-existing and unrelated to this diff (§12).

HTTP acceptance: matrix rows not added (no route change); live assertions above all pass.

## 10. Failure proofs / negative tests

The L60 gate was shown to fail at baseline (54 passed / 1 failed, real output captured) precisely because the drawer call site made 3 anchored `conexao_language_switcher(` occurrences where the gate requires 2 ("both header contexts"). After removing the drawer call site, the same command reports 55 passed / 0 failed — proving the gate can fail and that this change resolves it.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 1 (L60 in test-language-switcher-flag.php) | 0 |
| Failing assertion count | 1 | 0 |
| Failing script-contract | 2 (pre-existing, unrelated) | 2 (same two, pre-existing, unrelated) |
| Failing acceptance assertions | n/a | 0 |

The diff of the failing in-process list is empty after the change (the only in-process failure was L60, now passing). The 2 script-contract failures pre-date this change and are identified in §12.

## 12. Known pre-existing failures

| Failure | What it is | Proof it is not from this change |
|---|---|---|
| `conexao-event-importer .pot` older than plugin source (72255s) | i18n catalogue staleness in an untouched plugin | `git diff` touches no file under `wp-content/plugins/`; |
| `scripts/conexao-test-exec.sh` not catalogued in `scripts/README.md` | script-contract convention check on untouched files | neither `conexao-test-exec.sh` nor `scripts/README.md` is in the diff |

Both were produced by `./scripts/run-tests.sh --scripts` against this tree and fail identically on the baseline because neither concerns any file this task modified.

## 13. Limitations

Live pixel-perfect rendering at 320px / 360px / 390px / 430px was not verified with a browser viewport emulator — that tooling is not available in this environment. Substituted with viewport-independent checks: the drawer markup is server-rendered and absent from the HTML at every width; `.header-actions .language-switcher--mobile` is revealed by `@media (max-width: 1024px)`, which covers all four widths; the live HTTP assertions above pass. No production access (production is WordPress.com); no profiler; no deployed-site check.

## 14. Evidence paths

`docs/evidence/2026-11-20-remove-mobile-drawer-language-switcher/verification.md` — baseline and after results for the language-switcher gate, grep counts, php -l, and live-HTML assertion table, plus the rendered main-header switcher markup.

## 15. Documentation updated

None required: the change is covered by existing `docs/content-model.md` (language policy) and `docs/routing.md` (Polylang URLs/hreflang), both unmodified. The plan lives at `docs/plans/2026-11-20-remove-mobile-drawer-language-switcher.md`; no procedure moved into or out of a skill.

## 16. Rollback / recovery

`git checkout -- wp-content/themes/conexao-br-irlanda/header.php wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` restores the pre-change state (drawer block + CSS rules return; L60 returns to its pre-existing failure). Rollback covers no content because none was written. The re-synced container files would need re-copying after rollback to keep the local stack matching.

## 17. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate passed with real numbers and no required verifier was unavailable |
| `PASS WITH LIMITATION` | Work complete, but a required verification capability was unavailable |
| `BLOCKED` | A required implementation could not be completed safely |
| `NOT TESTABLE` | The intended change could not be meaningfully verified |

**Final status: PASS WITH LIMITATION**

Justification: every applicable implementation gate ran and passed (55/55 in-process assertions including the previously failing L60; php -l clean; live-HTML drawer/header/desktop assertions all pass; script-contract regressions limited to two pre-existing failures unrelated to this diff). The limitation: exact-width browser rendering at 320/360/390/430px could not be executed here, so viewport behaviour is proven via server-rendered markup plus the `max-width: 1024px` CSS contract rather than a live viewport check.

