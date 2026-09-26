# Stage F — Theme Modularisation (behaviour-preserving structural refactor)

**Scope:** WordPress website repository only. No production, no content/database
changes, no Polylang/REST behaviour changes, no Flutter/mobile work.

---

## 1. Baseline

| Item | Value |
|---|---|
| Branch | `i18n` |
| Starting HEAD | `ff7ddf4421c698ba607441f5995f99a6baabdd9a` (stage E: record the final Stage E commit hash…) |
| Final HEAD | see §19 |
| Tracked files (start) | 631 |
| Tracked files (end) | 645 |
| Theme PHP files (start → end) | 76 → 107 |
| `functions.php` | 4 765 lines |
| `inc/polylang.php` | 2 072 lines |
| `inc/seo.php` | 1 747 lines |
| PHP | 8.5.4 |
| WordPress | 7.0.2 |
| Polylang | 3.8.9 (unchanged) |

The working tree was clean at the starting SHA; no unrelated changes were present.

---

## 2. Pre-refactor Test Baseline

Re-measured on the pristine checkout (not copied from Stage E's numbers):

| Run | Suites | Assertions | Acceptance |
|---|---|---|---|
| `./scripts/run-tests.sh` | 46 total, 31 passed, **15 failed** | 3 244 passed, **59 failed** | 2 suites, 2 passed, 0 failed (77 assertions) |
| `./scripts/run-tests.sh --only theme` | 25 total, 13 passed, 12 failed | 1 805 passed, 54 failed | (included) |
| `./scripts/run-tests.sh --acceptance` | — | — | 2 suites, 2 passed, 0 failed |

The 15 failures are the known pre-existing content/data findings Stage E
classified (missing EN guide/blog coverage, shared county/town language
assignments, missing Stage 4.1/4.5 fixtures, Eircode-contaminated town terms,
PT menu-current assertion, registry/seed mismatches). **Stage F did not fix,
touch or mask any of them.**

Raw baseline output: `docs/evidence/2026-09-26-stage-f/baseline-full-keep.txt`.

---

## 3. Architecture Before

| File | Lines | Functions | Concern |
|---|---|---|---|
| `functions.php` | 4 765 | 99 | everything: setup, enqueue, performance, caching, REST policy, content helpers, queries, sponsors, leisure, events, navigation, admin, shortcodes |
| `inc/polylang.php` | 2 072 | 50 | everything language: guard, translated types, locale, cache scoping, URLs, terms, B2 fallback, leisure card copy, hreflang, switcher, canonical bridge |
| `inc/seo.php` | 1 747 | 27 | everything SEO: titles, meta, canonical, hreflang, OG, robots, schema, sitemap, redirects, breadcrumbs, related |

---

## 4. Architecture After

`functions.php` is **112 lines**: three constants, the ABSPATH guard, and 43
`require_once` statements in a documented order. No application logic.

### `inc/i18n/` (7 modules — was `inc/polylang.php`)

| Module | Lines | Concern |
|---|---|---|
| `guard.php` | 221 | Polylang capability, translated CPT/taxonomy declaration, EN language-home declaration + self-heal |
| `locale.php` | 195 | locale/language-slug resolution, B2 shell locale |
| `urls.php` | 579 | language-aware URLs, archives, posts page, language-scoped cache key |
| `terms.php` | 200 | translated category/tag resolution, per-language content probe |
| `fallback.php` | 677 | B2 (PT fallback) policy, fallback notice, leisure card description source |
| `hreflang.php` | 206 | hreflang alternate data + canonical bridge |
| `switcher.php` | 73 | language switcher data + renderer |

### `inc/seo/` (10 modules — was `inc/seo.php`)

| Module | Lines | Concern |
|---|---|---|
| `titles.php` | 166 | `<title>` + archive headings |
| `meta.php` | 99 | meta description + inLanguage |
| `canonical.php` | 79 | canonical link + `rel_canonical` removal |
| `hreflang.php` | 36 | hreflang `<link>` output |
| `open-graph.php` | 132 | og:/twitter: + social image alt |
| `robots.php` | 86 | per-page noindex + robots.txt |
| `schema.php` | 471 | JSON-LD + breadcrumbs |
| `sitemap.php` | 272 | XML sitemap |
| `redirects.php` | 387 | legacy/EN→PT/missing-translation/Lazer redirects |
| `related.php` | 156 | SEO related-content queries |

### Theme modules extracted from `functions.php` (17)

`admin.php` (102) · `queries.php` (614) · `setup.php` (153) · `assets.php` (181) ·
`performance.php` (185) · `cache.php` (122) · `content.php` (117) ·
`sponsors.php` (521) · `customizer.php` (120) · `archive-filters.php` (180) ·
`leisure.php` (474) · `events.php` (233) · `archive-query.php` (479) ·
`b2-fallback.php` (302) · `shortcodes.php` (117) · `ui.php` (52) ·
`navigation.php` (972)

Untouched (Phase 8): `inc/i18n.php`, `inc/rest-language.php`,
`inc/search.php`, `inc/post-views.php`, `inc/empregos-landing.php`,
`inc/job-resources.php`, `inc/recruitment-agencies.php`,
`inc/permit-employers.php`, `inc/employment-opportunities.php`.

---

## 5. Function Migration Map

Full machine-readable map: `docs/evidence/2026-09-26-stage-f/function-migration-map.md`.

| Metric | Value |
|---|---|
| functions migrated | **176** (99 + 50 + 27) |
| renamed | **0** |
| missing | **0** |
| duplicated | **0** |
| signature changes | **0** |

Verified by a symbol differential that scans the three original monoliths at
`HEAD` and the whole refactored module tree, comparing **name + full signature**
(`docs/evidence/2026-09-26-stage-f/verify-symbols.py`):

```text
before: 265 unique functions, 95 hook calls, 0 duplicated defs
after:  265 unique functions, 95 hook calls, 0 duplicated defs
=== FUNCTION NAME+SIG DIFF ===
IDENTICAL: zero renamed/changed functions
=== DUPS AFTER ===
NONE
```

(265 = 176 from the three monoliths + 89 that already lived in the untouched
`inc/*.php` modules; both sides scan the same file set, so the comparison is
like-for-like.)

**Byte-exactness proof.** Every moved block was extracted by explicit line
range from the original file and re-inserted verbatim; the tool refuses to
write if any line is dropped or duplicated. Re-verified after the refactor:

```text
ranges checked=58 mismatches=0   (23 functions.php + 17 polylang + 18 seo)
```

---

## 6. Hook Surface

| Metric | Before | After |
|---|---|---|
| `add_action` | 50 | 50 |
| `add_filter` | 44 | 44 |
| `remove_action` | 1 | 1 |
| `do_action` / `apply_filters` call sites | 0 | 0 |
| **total hook registrations** | **95** | **95** |
| new hooks introduced | — | **0** |

The `(kind, hook, callback, priority, accepted-args)` multiset is **identical**:

```text
HOOK MULTISET: IDENTICAL (95 registrations)
```

**Execution order.** WordPress only orders callbacks of the *same hook at the
same priority*, so that is the criterion that matters:

```text
=== SAME-HOOK / SAME-PRIORITY EXECUTION ORDER ===
IDENTICAL: every hook fires its callbacks in the same order
```

Ten registrations appear at a different position in the *flat* load sequence,
but always across **different** hooks (e.g. `robots_txt` now loads before
`template_redirect`), which WordPress never orders against each other. The
`template_redirect` chain that does share a hook keeps its exact order and
priorities: sitemap (0) → seo_redirects (2) → missing_translation (6) →
leisure_redirect (6). See `docs/evidence/2026-09-26-stage-f/verify-hook-order.py`.

---

## 7. Loader

`functions.php` = **112 lines**: opening tag, docblock, ABSPATH guard, 3
constants (`CONEXAO_THEME_VERSION`, `CONEXAO_THEME_DIR`, `CONEXAO_THEME_URI`),
43 `require_once` statements and grouped comments. **No application logic.**

Load order and its rationale:

1. `inc/i18n.php` — the locale correction must apply to everything after it.
2. `inc/i18n/*` — Polylang policy, before REST/SEO which call its helpers.
3. `inc/rest-language.php` — bilingual REST contract (single owner).
4. `inc/seo/*` — original `inc/seo.php` source order, so `wp_head` emission
   order is unchanged.
5. Empregos / post-views / search modules — loaded where the old loader had them.
6. The 17 theme modules — original `functions.php` **body** source order, so
   `init` / `delete_post` callback order is unchanged.

---

## 8. Path Safety

- `__FILE__` / `__DIR__` appear **nowhere** in the three monoliths, so no
  runtime path could shift. Verified by grep before and after.
- All path construction already used `CONEXAO_THEME_DIR` /
  `get_template_directory()` / `get_template_directory_uri()`, which are
  theme-root based and unaffected by the move.
- New modules resolve nothing relative to their own location; the
  `i18n/urls.php` + `seo/*` nesting therefore changes no URL.
- Confirmed empirically: the 18-route HTTP capture (§11) is byte-identical,
  including every canonical, hreflang and asset URL.

---

## 9. Test Results

| Run | Suites | Assertions | Acceptance |
|---|---|---|---|
| `./scripts/run-tests.sh` (after) | 46 total, 31 passed, **15 failed** | 3 244 passed, **59 failed** | 2 passed, 0 failed (77) |
| `./scripts/run-tests.sh --only theme` | 25 total, 13 passed, 12 failed | 1 805 passed, 54 failed | 2 passed, 0 failed |
| `./scripts/run-tests.sh --acceptance` | — | — | 2 passed, 0 failed |

Every number equals the pre-refactor baseline in §2.

---

## 10. Before/After Regression Comparison

Suite-by-suite diff of the result lines (`[PASS]/[FAIL] <suite> — N passed, M failed`):

```text
$ diff <baseline result lines> <after result lines>
ALL 46 SUITE RESULT LINES IDENTICAL TO BASELINE
```

| Metric | Baseline | After | Delta |
|---|---|---|---|
| suites total | 46 | 46 | 0 |
| suites passed | 31 | 31 | 0 |
| suites failed | 15 | 15 | 0 |
| assertions passed | 3 244 | 3 244 | 0 |
| assertions failed | 59 | 59 | 0 |
| acceptance suites | 2/2 | 2/2 | 0 |
| acceptance assertions | 77 | 77 | 0 |
| **newly failing suites** | — | **0** | — |
| **fixed suites** | — | **0** | — |

- Suites whose result changed: **0 of 46**.
- The 15 known content failures are the **same 15 suites with the same failure
  messages** (e.g. `test-guide-en-translation`: `missing: ["outono-irlanda-alimentacao-bemestar"]`;
  `test-polylang-foundation`: `expected 0, got 282`; `test-stage41-rest-language`).
  None was fixed, masked or re-worded.

One suite required a narrow, honest path update — see §11 note.

---

## 11. HTTP Regression Comparison

| Metric | Baseline | After |
|---|---|---|
| acceptance suites | 2 | 2 |
| matrix rows / assertions | 77 (18 + 59) | 77 (18 + 59) |
| passing | 77 | 77 |
| failing | 0 | 0 |

Independent route-level capture (18 routes: `/`, `/en/`, `/guias/`, `/en/guias/`,
`/empregos/`, `/en/jobs/`, `/eventos/`, `/en/eventos/`, `/lazer/`, `/en/lazer/`,
`/apoiadores/`, `/en/sponsors/`, `/blog/`, `/en/blog/`, `/cursos/`,
`/en/courses/`, `/sitemap.xml`, `/robots.txt`), comparing status, redirect
headers, `Location`, title, meta description, canonical, hreflang, `og:url`,
`og:locale`, `html lang`, robots, JSON-LD `@type` set and `h1`:

```text
$ diff http-before.txt http-after.txt
HTTP SEMANTIC OUTPUT IDENTICAL ACROSS ALL 18 ROUTES
```

Captures: `docs/evidence/2026-09-26-stage-f/http-{before,after}.txt`
(script: `capture-http.py`). No production request was sent — the base URL is
`http://localhost:8080` only.

**Test-discovered architecture assumption.** `tests/test-nav-language-context-logic.php`
is the standalone static-analysis suite that `token_get_all`-extracts real theme
functions and runs them against its own stubs. Its file list was updated to the
new modules (`inc/navigation.php`, all of `inc/i18n/*`, `inc/queries.php` — the
real cross-module callees of the nav code). The assertion was **not** weakened:
the same three functions are still extracted from real theme source and
executed unmodified, and the suite still reports 49 passed / 0 failed. No test
expected URL, status, count or translation state was changed.

---

## 12. Runtime Safety

Confirmed unchanged:

- No production runtime feature change; **0 production HTTP requests**; no
  production database or content change; no production migration.
- No plugin source change, no plugin activation/dependency change.
- No Polylang change (still 3.8.9, configuration untouched).
- No REST change — `inc/rest-language.php` is byte-identical.
- No template change — `git status` on `*.php` + `template-parts/` shows only
  `functions.php`; no CSS/JS change (`assets/` untouched).
- No Flutter/mobile change.
- No new `do_action`/`apply_filters`; no new `conexao_*` filter introduced.
- Cache keys, transients, invalidation hooks and expiry values unchanged.

---

## 13. Static Quality

| Gate | Result | Notes |
|---|---|---|
| `php -l` (424 PHP files, `scripts/lint.sh` step 1) | **PASS** | no parse errors |
| PHPStan level 5 (`vendor/bin/phpstan analyse`) | **PASS — `[OK] No errors`, exit 0** | matches pristine-HEAD (measured: HEAD = 0 errors) |
| `phpcs-baseline.php check` | **NOT RUNNABLE here** | PHPCS aborts: *requires the tokenizer, xmlwriter and SimpleXML extensions*; `php -m` shows them absent. Pre-existing host limitation, not a Stage F effect. `phpcs.xml.dist`, `phpcs-baseline.json` and the PHPCS scope were **not modified**. |
| `composer analyse` | **NOT RUNNABLE here** | the `composer` binary is not installed on this host; PHPStan was invoked directly via `vendor/bin/phpstan`, the same command `composer analyse` wraps. |
| `shellcheck scripts/*.sh` | **NOT RUNNABLE here** | `shellcheck` is not installed. No shell script was modified in Stage F, so no new issue can exist. |
| `git diff --check` | **PASS** | no whitespace errors |

**PHPStan baseline.** PHPStan was clean at HEAD, so Stage F had to keep it clean.
`phpstan-baseline.neon` pins each suppressed issue to a **file path**, so moving
code re-surfaced 30 pre-existing issues at their new paths. Two genuinely new
findings also appeared (the monolith had masked them) and were **fixed in code,
not baselined**:

1. `conexao_leisure_normalize_environment_attributes()` — a split boundary had
   detached its docblock; corrected so function + docblock live together in
   `inc/leisure.php`.
2. `conexao_leisure_multi_slugs()` — a boundary-straddling docblock had left the
   `@return` annotation off the function; restored.

The remaining 30 were repointed to their new module paths. The **suppression
total is unchanged (297 → 297)** and the old monolith paths are gone; PHPStan
then reports `No errors` again.

---

## 14. Build Verification

`./scripts/build-theme-zip.sh`:

| Check | Value |
|---|---|
| ZIP produced | `dist/conexao-br-irlanda.zip` (5.7 M, 127 files) |
| `tests/` entries | **0** |
| `test-*.php` entries | **0** |
| root `tests/bootstrap.php` | **0** |
| `inc/polylang.php` (old monolith) | **0** |
| `inc/seo.php` (old monolith) | **0** |
| `inc/i18n/` modules shipped | 7 (+ `inc/i18n.php`) |
| `inc/seo/` modules shipped | 10 |
| development-only files | 0 |

---

## 15. Failure Proofs

Each proof injected a defect, observed the failure, and was reverted. None is
committed.

| Proof | Defect | Observed failure | Cleanup | Re-run |
|---|---|---|---|---|
| Harness is live (P59) | `t2_strict( 0, … )` → `t2_strict( 999, … )` in `test-polylang-foundation.php` | `FAIL: … — expected 999, got 282` | file restored from backup | `expected 0, got 282` (the known baseline failure) |
| Loader really loads modules (P60) | removed `require_once …/inc/i18n/urls.php` | `Fatal error: Call to undefined function conexao_lang_cache_key()` | loader restored (43 requires) | suite back to 61 passed / 1 known fail |
| No duplicate definitions (P61) | temp `inc/zz-dup-proof.php` redeclaring `conexao_lang_url()`, wired into the loader | `Fatal error: Cannot redeclare conexao_lang_url() (previously declared in …/inc/i18n/urls.php:375)` | file deleted, loader restored | loader clean, PHPStan `No errors` |
| Repeatability (P85) | — | two consecutive clean `./scripts/run-tests.sh` runs | — | identical result lines, 46 suites / 3 244 / 59 / 2-of-2 acceptance, both times |

**Honest note on the repeatability run.** An intermediate "repeat" run reported
3 extra failures (`test-eventbrite-importer`, `test-event-image-sync`,
`test-export-language`, all `exit 255` on an undefined
`conexao_flush_language_cache()`). The timestamps (`08:59:02–04`) fall inside
the window in which the **Phase 60 loader-defect proof** had temporarily removed
`inc/i18n/urls.php` — the run overlapped a deliberate proof. It was a harness
race caused by Stage F's own verification procedure, not a code regression.
Both clean runs quoted above ran with the loader intact and match the baseline.

---

## 16. Documentation

Updated (current architecture only; historical reports left untouched):

- `docs/themes/conexao-br-irlanda.md` — new §Runtime Architecture (module map,
  ownership table, load order, conventions); §Files to Inspect First; helper
  ownership paths throughout.
- `docs/architecture.md` — Theme Architecture + SEO Architecture sections.
- `docs/frontend.md` — helper→module paths for assets/leisure/filters.
- `docs/routing.md`, `docs/content-model.md`, `docs/development.md` — paths.
- `AGENTS.md` — rule 11 updated, new rule 12 (loader + modules), task table.
- `phpstan-baseline.neon` — paths only (suppression total unchanged).
- Code comments in `page.php`, `home.php`,
  `tests/test-polylang-foundation.php`, four `scripts/stage4x-*` scripts and two
  translation-plugin docblocks that named the old files.
- New: this report + `docs/evidence/2026-09-26-stage-f/`.

`docs/README.md` needed no change: no new evergreen document was introduced.

---

## 17. Known Limitations

1. **Eight cohesive modules exceed the ~400-line target**: `inc/navigation.php`
   (972), `inc/i18n/fallback.php` (677), `inc/queries.php` (614),
   `inc/i18n/urls.php` (579), `inc/sponsors.php` (521), `inc/archive-query.php`
   (479), `inc/leisure.php` (474), `inc/seo/schema.php` (471). Each is a single
   concern (nav section model / B2 policy / shared term+post queries / language
   URLs / sponsor+contact data / archive query shaping / leisure record helpers /
   JSON-LD+breadcrumbs). Splitting further would fragment cohesive units, so they
   are documented rather than split (the standard treats ~400 lines as a SHOULD).
2. **Three pre-existing malformed docblocks** in the original `functions.php`
   (unterminated `/**` at old line 1096, duplicated block at 2531) were carried
   across verbatim. One silently disables
   `add_action( 'wp_enqueue_scripts', 'conexao_dequeue_block_library', 100 )`
   because the statement sits inside an unterminated comment. **Stage F preserved
   this behaviour exactly** — fixing it would be a behaviour change. Recorded as
   a finding for a future stage.
3. **PHPCS and ShellCheck could not be executed** on this host (missing PHP
   extensions / missing binary), so those gates are unverified locally. No shell
   script and no PHPCS/PHPStan configuration or scope was changed.
4. **GitHub Actions was not observed** — see §18.

The 15 pre-existing content failures are **not** Stage F regressions: their set
and messages are unchanged.

---

## 18. Next Stage

**Stage G — Plugin registry + lifecycle.**

---

## 19. Local vs hosted verification

- **Local verification** (all results above): full harness, theme subset, HTTP
  acceptance, HTTP output capture, symbol/hook differentials, PHPStan, syntax
  sweep, theme ZIP build, failure proofs, repeatability.
- **Hosted verification**: **GitHub Actions run not observed.** `gh` is not
  installed and `git ls-remote` fails with
  `could not read Username for 'https://github.com'`, so no push was performed
  and no CI run exists to report. No run ID is invented. The Stage D static and
  integration jobs remain unverified for this change; the local integration
  signal is the two identical clean harness runs.

---

## 20. Final state

| Item | Value |
|---|---|
| Branch | `i18n` |
| Starting HEAD | `ff7ddf4421c698ba607441f5995f99a6baabdd9a` |
| `functions.php` | 4 765 → **112** lines (loader only) |
| `inc/polylang.php` | 2 072 → 7 modules in `inc/i18n/` |
| `inc/seo.php` | 1 747 → 10 modules in `inc/seo/` |
| Public functions renamed | **0** |
| Missing / duplicated functions | **0 / 0** |
| Hook registrations changed | **0** (95 before, 95 after) |
| New hooks | **0** |
| Production requests | **0** |
| Production DB / content changes | **NONE** |
| Template / CSS / JS changes | **NONE** |
| REST / Polylang changes | **NONE** |
| Flutter / mobile changes | **NONE** |


