# Report — EN homepage "Guia Prático" label leak

| | |
|---|---|
| **Stage / task name** | Fix the English homepage content-type label leak (`Guia Prático`) |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `849624574451c1aa771f2fac0bd66b66fd2aadd8` |
| **Final SHA** | *(uncommitted — task changes in the working tree)* |
| **Working tree at finish** | dirty **by design** — only the intentional changes in §3; no unrelated modifications |
| **Plan** | [`docs/reports/2026-09-27-en-homepage-label-leak-plan.md`](2026-09-27-en-homepage-label-leak-plan.md) |
| **Evidence** | [`docs/evidence/2026-09-27-en-homepage-label-leak/`](../evidence/2026-09-27-en-homepage-label-leak/) |

## 1. Scope completed

The Portuguese label **`Guia Prático`** was rendered verbatim on the English
homepage `/en/`. It is now rendered as **`Practical Guide`**, while the
Portuguese `/` is **byte-identical** to before the change.

Delivered:

- Reproduced the defect on live HTTP (`/en/` → 200, chip reads `Guia Prático`)
  and captured the rendered HTML as evidence.
- Root-caused it to a **missing gettext call**, not a missing translation
  (`Practical Guide` already existed in `en_US.po`/`.mo`).
- Added a narrowly additive, presentation-layer theme helper
  `conexao_content_type_label()` and switched the two leaking call sites.
- Added a focused in-process regression suite (**29 assertions, 0 failures**)
  and **2 HTTP acceptance rows** (both passing).
- Verified PT byte-identity, the four required regression URLs, static
  analysis, the permanent gates, and **real desktop + mobile browser
  rendering with zero console errors**.
- Updated `docs/themes/conexao-br-irlanda.md` in the same change.

## 2. Scope NOT completed

- **The `post` → `Artigo` chip is still Portuguese on `/en/`.** It is a
  *different* mechanism: `post` is registered by **WordPress core**, and core
  localises that label through core's own catalogue, not the theme's. Fixing it
  is a separate decision about core-label localisation and is out of this
  task's scope. It is **pre-existing** (visible in the pre-change evidence) and
  is **not** a regression of this change.
- **Other CPT singular labels remain untranslated where they are rendered**
  (`Evento`, `Vaga de Emprego`, `Apoiador`, `Provedor de Cursos`,
  `Local de Lazer`, …) because those msgids have no `en_US` entry yet. The
  helper is correct for them by construction (it translates whatever the CPT
  registered and falls back safely); adding the English strings is a separate
  translation decision, not part of this defect. **Only `guide` can reach the
  homepage chips today** (the featured query is `post_type => array('guide',
  'post')`), so this does not affect the reported defect.
- **No release, build, manifest or deploy** — explicitly out of scope.
- **No production verification.** No production action was authorised, so the
  fix is verified on the local stack only.

## 3. Files added / modified / deleted

**2 added, 4 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/content.php` | **modified** | `wp-content/`: added `conexao_content_type_label()` (+41 lines) |
| `wp-content/themes/conexao-br-irlanda/front-page.php` | **modified** | `wp-content/`: 2 call sites, 2 lines changed |
| `wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php` | **added** | `wp-content/`: new in-process suite |
| `tests/acceptance/matrices/routing.json` | **modified** | +2 rows, **22 insertions / 0 deletions** (pure addition) |
| `docs/themes/conexao-br-irlanda.md` | **modified** | +1 row documenting the helper |
| `docs/reports/2026-09-27-en-homepage-label-leak-plan.md` | **added** | the plan (written before implementation) |
| `docs/reports/2026-09-27-en-homepage-label-leak.md` | **added** | this report |
| `docs/evidence/2026-09-27-en-homepage-label-leak/` | **added** | 17 evidence files |

`git diff --stat` = **66 insertions, 2 deletions across 4 tracked files.**

**Explicitly NOT touched** (per plan §7): `conexao-data-model.php`,
`plugins.json`, every `languages/*` catalogue, `inc/seo/schema.php`,
`inc/i18n/**`, `tests/baseline/permanent-gates.json`, `phpcs-baseline.json`,
`phpstan-baseline.neon`, and the retired `conexao-*-translation` plugins.

## 4. Runtime impact

WordPress behaves differently in exactly one way: **on English requests, a
content-type chip whose post type has a translated label now shows the English
label.** Before, every such chip showed the Portuguese label on every language.

Proven surgical — the complete rendered-HTML diff of `/en/` against the same
fixture is **one line**:

```
< <span class="post-card-category">Guia Prático</span> …
> <span class="post-card-category">Practical Guide</span> …
```

`diff` of the before/after EN snapshots = **4 lines** (1 changed line).
No HTML structure, class, link, style or behaviour changed.

## 5. Content / data impact

**None.** No record was created, updated or deleted by the change, and no
content/data write of any kind was performed, so the §5.2 six-step
content-change contract does not apply. Confirmed by `git status`: no fixture,
seed, manifest or data file is part of the change.

A **local, throwaway reproduction fixture** was created in the local Docker
database only, to make `/en/` render `front-page.php` at all (a fresh
WordPress install has no content): WordPress core install, Polylang 3.8.9,
`scripts/run-polylang-setup.php`, one PT guide + its linked EN translation, one
EN blog post, and the linked EN translation of the PT front page. It is **not
committed**, touches no production, and is discarded with `docker compose down
-v`.

## 6. Polylang impact

- **PT immutability:** PT `/` response is **byte-identical** (`cmp` reports no
  difference; 70511 bytes before and after). `pt_BR` is an identity catalogue,
  so `__()` returns the identical string on PT requests.
- **EN records created by the change:** **0.** (The fixture's EN records are
  local-only.)
- **No `pll_*` filter, no `inc/i18n/guard.php` declaration, no Polylang
  option, and no `plugins.json` entry was changed.** No second translation
  lifecycle or engine was introduced — the existing gettext workflow was reused.
- **No taxonomy policy change**; `conexao_category`/`conexao_tag` stay
  translated and `conexao_county`/`conexao_town` stay shared.
- **No allowlist entry added**, and no gate was weakened to obtain a green run.
- **Cache scoping:** the helper introduces no query and no cache key, so no
  language-scoping obligation arises.
- **Completeness gate:** unaffected by the change (see §9 for the gate's own
  pre-existing state).

## 7. HTTP acceptance

Two rows added to `tests/acceptance/matrices/routing.json` (77 → **79 rows**),
both **PASS**:

| Row | URL | Status | contains | absent |
|---|---|---|---|---|
| `en-home-no-pt-content-type-label` | `/en/` | 200 ✅ | `Practical Guide` ✅ | `Guia Prático` ✅ |
| `pt-home-keeps-pt-content-type-label` | `/` | 200 ✅ | `Guia Prático` ✅ | — |

Verbatim runner output (`evidence/http-acceptance-routing.txt`):

```
PASS  en-home-no-pt-content-type-label /en/ (status 200)
PASS  pt-home-keeps-pt-content-type-label / (status 200)
acceptance/routing-http: 62 passed, 20 failed
```

**The 20 failures are pre-existing.** Proven by re-running the same suite
against the **pristine `8496245` code** (my two source files stashed):

| | Baseline (pristine) | With the fix |
|---|---|---|
| routing acceptance | 61 passed, **21 failed** | 62 passed, **20 failed** |

The single baseline failure that disappears is exactly the new EN row, failing
with the defect itself:

```
FAIL  en-home-no-pt-content-type-label /en/  -- missing fragment: 'Practical Guide';
                                            unexpected fragment: 'Guia Prático'
```

So the change **fixes 1 row and introduces 0 new failures**. The remaining 20
are local-fixture gaps (the bootstrapped site has 0 events, 0 leisure, 0
course providers, 0 sponsors, 0 jobs).

Full acceptance layer (`./scripts/run-tests.sh --acceptance`): **3 suites, 1
passed, 2 failed**; the 2 failures are `verify-guides-en-http.py` and
`verify-routing-http.py`, both failing only on rows that require the absent
fixture content listed above.

## 8. Test results (numeric)

**New in-process suite** — `theme/conexao-br-irlanda/test-homepage-content-type-label.php`:

```
[PASS] theme/conexao-br-irlanda/test-homepage-content-type-label.php — 29 passed, 0 failed
```

**Full in-process layer**, with a **baseline comparison against the pristine
`8496245` code** (my two source files stashed and the new suite removed):

| Metric | Baseline (pristine) | With the change | Delta |
|---|---|---|---|
| Suites total | 57 | 58 | **+1** (the new suite) |
| Suites passed | 33 | 34 | **+1** |
| Suites failed | 24 | 24 | **0** |
| Assertions passed | 2579 | 2608 | **+29** |
| Assertions failed | 137 | 137 | **0** |

The set of failing suites is **byte-identical** before and after
(`diff` of the sorted FAIL lists is empty): **no new failing suite, none
accidentally "fixed"**. Both raw logs are in `evidence/`
(`inprocess-test-BASELINE-pristine-8496245.txt`, `inprocess-test.txt`).

The 24 failing suites are all fixture-content dependent (leisure, course
providers, jobs, EN guide/blog translation completeness, nav menus) in a site
bootstrapped with 2 guides and 1 EN post. Spot-check: the only failure in
`test-i18n-foundation.php` is `dates render Portuguese month names
("setembro")` — a date assertion, unrelated to labels.

## 9. Permanent gate results

`python3 scripts/verify-permanent-gates.py` (run in isolation, no concurrent
DB mutation) — `evidence/permanent-gates.txt`:

```
Gates: 7 total, 5 passed, 2 failed, 0 blocked
Assertions: 81 passed, 6 failed
Violations: 36 (35 pre-existing, 1 new)
AGGREGATE: FAIL
```

| Gate | Result |
|---|---|
| `taxonomy_policy` | **PASS** — 18 passed, 0 failed, 0 violations |
| `cache_scoping` | **PASS** — 9 passed, 0 failed, 0 violations |
| `cache_scoping_static` | **PASS** — 2 passed, 0 failed, 0 violations |
| `redirect_precedence` | **PASS** — 15 passed, 0 failed, 0 violations |
| `documentation_drift` | **PASS** — 14 passed, 0 failed, 0 violations |
| `translation_completeness` | FAIL — 35 violations (34 pre-existing, **1 new**) |
| `i18n_freshness` | FAIL — 1 violation (**1 pre-existing, 0 new**) |

**Fail-closed behaviour is intact**: `AGGREGATE: FAIL`, exit non-zero, and both
failing gates still fail exactly as they did before. **No gate was weakened, no
allowlist was added, and no baseline file was edited.**

**The 1 "new" completeness violation is a fixture artifact, not code.** It is
`post_type:post:malformed_relationships: 1`, and the offending record is the
throwaway local fixture post (ID 66, `Local EN Post`), which has no PT master:

```
66 [post] Local EN Post -> tr=[]
```

A presentation-layer gettext call cannot create a malformed translation
relationship; the gate is purely database-driven, and the change adds no
content. It disappears with the fixture (`docker compose down -v`).

**`i18n_freshness` is unchanged: 1 pre-existing, 0 new** — the theme `.pot` is
87215 s older than `inc/i18n/fallback.php`, recorded in
`tests/baseline/permanent-gates.json` and present at the start SHA. The fix
**introduces no new translatable string**, so the `.pot`/`.po`/`.mo` were left
untouched and catalogue freshness could not regress.

## 10. Static analysis

| Check | Result |
|---|---|
| `php -l` (syntax) | **PASS** — no parse errors in any of the 3 changed/added PHP files |
| **PHPStan** (level 5 + baseline) | **2 errors, identical before and after** — both pre-existing in `inc/i18n/fallback.php:623,666` ("Else branch is unreachable"). **0 errors in the files I touched.** Compared against a pristine-baseline PHPStan run: same 2 errors, same lines. |
| **PHPCS** | **NOT RUN — environmental.** `vendor/bin/phpcs` aborts: *"PHP_CodeSniffer requires the tokenizer, xmlwriter and SimpleXML extensions"*. The host PHP has `tokenizer` but not `xmlwriter`/`SimpleXML`, and `composer` is not installed. **This is a pre-existing toolchain limitation, not a skip, and I am not claiming PHPCS passed.** |
| `./scripts/lint.sh` | Cannot run green: PHPCS blocked (above) and it shells out to `composer analyse`, which is not installed. Step 1 (PHP syntax) passed. |
| Script-contract layer | **identical to baseline** — 6 suites, **5 passed, 1 failed**; the failure is the pre-existing `verify-i18n-freshness` (same 87215 s lag). Assertions: 2 + 14 + 210 + 313 pass, 0 fail. |
| Registry drift (`generate-registry-docs.php --check`) | **PASS** — `registry OK: 14 plugins validated, 23 generated regions current (zero writes)` |
| `i18n-check.sh` / `i18n-make-pot.sh` | The `.pot` was **regenerated once into a temp copy** to measure impact, then **restored byte-for-byte** — the fix needs no new msgid, so no catalogue change is committed. |

## 11. Browser verification — **completed** (tooling available)

Real browser verification was performed with **Playwright + Chromium 151** at
**desktop 1440×900** and **mobile 390×844** (`evidence/browser-verification.json`,
4 screenshots). Result: **BROWSER RESULT: PASS**.

| Viewport | Page | Status | `lang` | Chips rendered | PT label leaks |
|---|---|---|---|---|---|
| desktop | `/en/` | 200 | `en-US` | `Artigo`, **`Practical Guide`** | **no** |
| desktop | `/` | 200 | `pt-BR` | **`Guia Prático`**, `Artigo` | yes (correct for PT) |
| mobile | `/en/` | 200 | `en-US` | `Artigo`, **`Practical Guide`** | **no** |
| mobile | `/` | 200 | `pt-BR` | **`Guia Prático`**, `Artigo` | yes (correct for PT) |

- **No console errors and no page errors** on any page/viewport
  (`"console_errors": []`).
- **No PHP warnings/notices** visible in the rendered output on any page.
- **No layout shift**: the chip is a text-only change inside an existing
  `<span>` with unchanged classes; screenshots confirm identical layout and
  styling.
- **Navigation/link behaviour unchanged** — the diff is one text node inside an
  existing anchor; the surrounding markup is untouched.
- Screenshots reviewed visually: the EN homepage renders fully in English
  ("Everything Brazilians need to live better in Ireland", "Explore Guides",
  "Housing/Jobs/Healthcare…") with the chip reading **Practical Guide**; the PT
  homepage is visually unchanged.

**No Flutter/mobile tooling was used or substituted.**

## 12. Security and performance review

**Security — no concerns introduced:**

- **No unsanitised output.** Both call sites keep their existing `esc_html()`;
  the helper returns a plain string and escapes nothing itself, so the escaping
  contract is unchanged. No `echo` of raw user input was added.
- **No escaping bypass** and no change to the `if ( $content_type )` guard: the
  helper returns `''` for an unregistered type, matching the previous behaviour
  in which `$content_type` was `null`.
- **No arbitrary input handling**: the only input is a post-type name already
  resolved by the caller.
- **No SQL**: no query, and no direct database access.
- **No capability, nonce, permission or REST surface change.**
- **No secrets**; no localhost URLs and no hotlinked media added.

**Performance — no regression:**

- **0 queries added.** The helper calls `get_post_type_object()`, which returns
  the already-registered in-memory object the template had already resolved —
  no database round-trip.
- **No caching change**: no new transient, no new object-cache key, so the
  language-scoped cache rule is untouched.
- **No asset/CSS change**, so no render-blocking or payload impact.
- **No performance refactor was bundled in** with this correctness fix.

## 13. Pre-existing failures (not caused by this change)

| # | Failure | Status |
|---|---|---|
| 1 | `verify-i18n-freshness` — theme `.pot` 87215 s older than `inc/i18n/fallback.php` | **Pre-existing at `8496245`**, recorded in `tests/baseline/permanent-gates.json`. Still fails, 0 new. Left failing rather than "fixed" by editing unrelated files. |
| 2 | `translation_completeness` — 34 pre-existing violations (31 `page:missing_en`, etc.) | Pre-existing; recorded in the baseline. |
| 3 | PHPStan — 2 "Else branch is unreachable" errors in `inc/i18n/fallback.php:623,666` | **Pre-existing at `8496245`** (verified by a pristine-baseline PHPStan run). Not in files I touched; baseline not edited. |
| 4 | 24 in-process suites / 137 assertions | **Pre-existing and byte-identical** before and after (§8). All are local-fixture content gaps. |
| 5 | 20 routing acceptance rows + `verify-guides-en-http.py` | **Pre-existing**; proven by the pristine-baseline run (21 → 20). All require absent fixture content. |

**One non-obvious environmental trap worth recording:** the first
`verify-permanent-gates.py` run showed inflated numbers (39 violations, 11
allowlisted) because the in-process suite was **running concurrently and
mutating the database**. Re-run in isolation it returned 35 violations / 11
allowlisted, matching the pristine baseline. Permanent-gate numbers are only
meaningful when nothing else is writing to the database.

## 14. Environmental limitations

1. **Docker context was stale.** The `desktop-linux` context pointed at a
   non-existent socket; the real daemon was reachable at
   `unix:///var/run/docker.sock`. The site was started with that `DOCKER_HOST`.
2. **The local stack had to be bootstrapped from scratch.** The Docker volume
   was empty: no WordPress install, no Polylang, no content. Bootstrapped per
   `docs/development.md` (core install, theme activation, Polylang 3.8.9,
   `scripts/run-polylang-setup.php`) plus a throwaway fixture, because `/en/`
   only renders `front-page.php` when the EN front page is the linked
   translation of the PT front page.
3. **No database dump was available** to restore realistic content
   (`scripts/restore-updraft-db.sh` needs an UpdraftPlus backup; none is in the
   repository). Hence the content-dependent failures in §13.
4. **PHPCS could not run** (missing `xmlwriter`/`SimpleXML`) and **`composer` is
   not installed**, so `./scripts/lint.sh` cannot complete. PHPStan was run
   directly from `vendor/bin/` instead, with the repository's own
   `phpstan.neon.dist` + baseline.
5. **`wp eval-file -` (stdin) is broken in the bundled WP-CLI phar** on PHP 8.5
   (a phar path bug). Worked around by copying scripts into the container and
   using the file form — the documented commands in `docs/development.md` use
   the stdin form and would hit this on this toolchain.
6. **PHPStan 1.12.x is old** and prints an upgrade notice; the repository
   documents this as expected.

## 15. Evidence paths

All under
[`docs/evidence/2026-09-27-en-homepage-label-leak/`](../evidence/2026-09-27-en-homepage-label-leak/):

| File | What it proves |
|---|---|
| `http-en-homepage-before.html` | The defect: `/en/` 200 with `Guia Prático` |
| `http-pt-homepage-before.html` | PT baseline (70511 bytes) |
| `http-en-homepage-both-chips-before.html` | Pre-change EN snapshot with the **identical fixture** used for the diff |
| `http-en-homepage-after.html` | Post-fix EN: 0 × `Guia Prático`, 1 × `Practical Guide` |
| `http-pt-homepage-after.html` | Post-fix PT — `cmp`-identical to the baseline |
| `inprocess-test-BASELINE-pristine-8496245.txt` | Pristine-code in-process run (57/33/24) |
| `inprocess-test.txt` | In-process run with the fix (58/34/24) |
| `http-acceptance-routing.txt` | HTTP acceptance output incl. both new rows |
| `permanent-gates.txt` | Permanent gate output (7 gates, 5 pass, 2 fail) |
| `script-contract-before.txt` / `script-contract-after.txt` | Script-contract layer before/after (identical) |
| `phpstan-after.txt` | PHPStan output (2 pre-existing errors only) |
| `regression-matrix.txt` | 14 URLs: status, `lang`, label counts, B2 notice |
| `browser-verification.json` | Desktop + mobile browser results, `console_errors: []` |
| `screenshot-{desktop,mobile}-{en,pt}.png` | Visual proof at both viewports |

**Regression URLs** (`regression-matrix.txt`) — all four required EN routes
serve 200 and carry no Portuguese label leak:

| Route | Status | `lang` |
|---|---|---|
| `/` | 200 | `pt-BR` |
| `/en/` | 200 | `en-US` |
| `/en/cursos/` | 200 | `en-US` |
| `/en/lazer/` | 200 | `en-US` |
| `/en/eventos/` | 200 | `en-US` |
| `/en/empregos/` | **302 → `/empregos/`** | B1 policy |

`/en/empregos/` returning 302 → PT is the documented **B1** behaviour
(`docs/routing.md` §Bilingual) and was verified **identical on the pristine
baseline code**, so it is not a regression.

## 16. Rollback / recovery

The change is **code-only** — no content, data, database or configuration was
written — so rollback is a single step:

```bash
git checkout -- \
  wp-content/themes/conexao-br-irlanda/inc/content.php \
  wp-content/themes/conexao-br-irlanda/front-page.php \
  tests/acceptance/matrices/routing.json \
  docs/themes/conexao-br-irlanda.md
rm wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php
rm -r docs/reports/2026-09-27-en-homepage-label-leak-plan.md \
      docs/reports/2026-09-27-en-homepage-label-leak.md \
      docs/evidence/2026-09-27-en-homepage-label-leak
```

(Or `git revert <commit>` once committed.) There is **no content reversal and
no snapshot to restore**, because nothing was written. To discard the local
reproduction fixture: `docker compose down -v`.

**Note on evidence hygiene:** `verify-permanent-gates.py` writes to
`docs/evidence/2026-09-26-stage-l/gate.json`, a **tracked** file belonging to a
previous stage. It was overwritten by each run and **restored with
`git checkout --`** each time, so that file is unmodified in the final tree.

## 17. Documentation updates

| Document | Change |
|---|---|
| `docs/reports/2026-09-27-en-homepage-label-leak-plan.md` | **added** — the plan, written before implementation |
| `docs/reports/2026-09-27-en-homepage-label-leak.md` | **added** — this report |
| `docs/evidence/2026-09-27-en-homepage-label-leak/` | **added** — 17 evidence files |
| `docs/themes/conexao-br-irlanda.md` | **modified** — one row documenting `conexao_content_type_label()` and distinguishing it from `conexao_cpt_label()` |
| `AGENTS.md` | **not changed** — no plugin, route, count or gate change |
| `docs/routing.md`, `docs/content-model.md` | **not changed** — no route and no content-model change |
| `docs/README.md` | **not changed** — no new evergreen document |

`verify-documentation-drift` re-run after the doc edit: **14 passed, 0 failed**.

## 18. Production actions

**None.** No production write, deploy, upload or activation was performed or
authorised. Production has no CLI, and this repository performs no production
mutation (§13.2 / AGENTS.md safety rules). The local-only reproduction fixture
never touched production.

## 19. Flutter/mobile actions

**None.** The Flutter/mobile repository was never accessed, inspected, built,
tested or modified from this task, as required by `AGENTS.md` §Scope. No mobile
tooling was used as a substitute for browser verification.

## 20. Final status

# `PASS WITH LIMITATION`

**Justification.** Every acceptance criterion is demonstrated and the defect is
conclusively verified fixed: `/en/` returns 200 with `Practical Guide` and **0**
occurrences of `Guia Prático`; `/` returns 200 and is **byte-identical** to the
pre-change baseline; the new in-process suite is **29 passed / 0 failed**; both
new HTTP acceptance rows pass and the pristine-baseline comparison shows
**+1 row fixed, 0 new failures**; the in-process layer shows **+1 suite, +29
assertions, 0 new failures** with a byte-identical failing-suite set; PHPStan
shows **0 new errors**; the permanent gates remain **fail-closed with the same
pre-existing failures**; the registry-drift gate is clean; and real browser
verification passed on desktop and mobile with zero console errors.

It is **not a plain `PASS`** because of limitations outside the change that I
will not paper over:

1. **PHPCS and `./scripts/lint.sh` could not be run** (missing
   `xmlwriter`/`SimpleXML`; `composer` not installed). The coding-standard gate
   is therefore only *partially* verified — PHP syntax and PHPStan ran, PHPCS
   did not. This is a pre-existing toolchain gap, and I am explicitly **not**
   claiming PHPCS passed.
2. **The permanent gates still report `AGGREGATE: FAIL`** — correctly, for
   pre-existing reasons (stale theme catalogue, missing EN page/guide
   coverage) plus one violation caused by my own local fixture post. I did not
   weaken any gate to make them green.
3. **24 in-process suites and 20 acceptance rows still fail** because the local
   database was bootstrapped without production content (no UpdraftPlus dump
   exists in the repository). These are proven pre-existing by direct
   comparison against the pristine `8496245` code, but the local site is not a
   faithful production-content replica.
4. **No production verification**, as none was authorised.
5. The out-of-scope `post` → `Artigo` chip and the untranslated singular labels
   of the other CPTs remain (§2), and are **pre-existing**.

_Last verified: 2026-09-27 by the EN homepage label task_
