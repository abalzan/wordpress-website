# Report — STAGE 9: `/en/cursos/` provider category filter and English labels

| | |
|---|---|
| **Stage / task name** | STAGE 9 — `/en/cursos/` category filter + English provider-category presentation |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `3cc6564666be9d64d883b653bd550555ee52ef3c` ("Add English course-provider descriptions and rollout stage") |
| **Final SHA** | *(working tree; not committed)* |
| **Working tree at finish** | dirty — only the files in §3, all of them this stage's own work |

## 1. Scope completed

Both reported `/en/cursos/` defects are fixed, with B2, the PT canonical data
and all previously completed work preserved.

1. **The filter bar now renders on `/en/cursos/`.** Root cause confirmed by
   reading the code and reproducing over real HTTP: the main archive query was
   already widened for B2, but `conexao_get_provider_categories()` issues a
   **secondary** `get_posts()` that was not. Polylang narrowed it to English, it
   matched **zero** PT providers, and the template's
   `if ( empty( $provider_categories ) ) return;` guard rendered nothing. The fix
   routes that query through the **existing shared abstraction**
   `conexao_b2_widen_query_args()` — the same boundary
   `conexao_get_event_towns()` and `conexao_get_terms_for_post_type()` already
   use. No new widening mechanism, no copy of the event implementation.
2. **Provider category labels are English on `/en/cursos/`.** `_provider_category`
   is free-text post meta, so unlike `conexao_category` it has no linked EN term
   to resolve. `conexao_provider_category_label()` supplies a **presentation**
   value only, mirroring the theme's existing precedent
   (`conexao_permit_employer_label_display()`), reusing the same normalisation
   rule and the ordinary `en_US` gettext catalogue.

The English archive now serves 5 filter options, 10 provider cards with English
category labels and zero Portuguese leakage, and a working `?categoria=` filter
that keeps the visitor in English.

## 2. Scope NOT completed

- **Browser (desktop/mobile) verification was not performed** — no browser
  automation tooling exists in this environment. Real HTTP verification was done
  instead (§7, §9). This is why the final status is `PASS WITH LIMITATION`.
- **No production action.** Deliberately out of scope (§8).
- **No UI translation cleanup.** Only the 5 msgids this change needs were added
  to `en_US.po`; pre-existing untranslated strings elsewhere (e.g. the PT
  `/eventos/` "County" label) were left alone as out of scope.
- **`conexao-br-irlanda.pot` was not regenerated** — see §12 for the proof that
  the resulting freshness failure is pre-existing at HEAD.

## 3. Files added / modified / deleted

**2 added, 10 modified, 0 deleted.** Every `wp-content/` change is called out.

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-en-course-provider-category-filter.php` | **added** | `wp-content/`. Focused in-process suite: 60 assertions (§9). |
| `docs/evidence/2026-09-27-en-cursos-category-filter/` | **added** | Machine evidence (§14). |
| `wp-content/themes/conexao-br-irlanda/inc/queries.php` | modified | `wp-content/`. `conexao_get_provider_categories()`: query args go through `conexao_b2_widen_query_args( $args, 'course_provider' )`; each row gains a `label`; sorting is on `label` (identical to `name` on PT). |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` | modified | `wp-content/`. **Additive only** — adds `conexao_provider_category_label()` and `conexao_provider_category_key()`. No existing function touched. |
| `wp-content/themes/conexao-br-irlanda/template-parts/course-filters.php` | modified | `wp-content/`. **One expression**: `esc_html( $cat['name'] )` → `esc_html( $cat['label'] )`. |
| `wp-content/themes/conexao-br-irlanda/template-parts/provider-card.php` | modified | `wp-content/`. **One expression**: the category span renders `$provider_category_label`, still inside `esc_html()`. |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.po` | modified | `wp-content/`. +5 msgids (the English category labels). `msgfmt --check` passes. |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.mo` | modified | `wp-content/`. Recompiled from the `.po` with `msgfmt` (the binary the theme loads). |
| `tests/acceptance/matrices/routing.json` | modified | +5 rows (append-only; the existing 72 are untouched). |
| `docs/content-model.md` | modified | `_provider_category` presentation layer. |
| `docs/routing.md` | modified | `/en/cursos/` `?categoria=` + the B2 widening note. |
| `docs/themes/conexao-br-irlanda.md` | modified | `course-filters.php`, `provider-card.php`, `conexao_get_provider_categories()`. |

## 4. Runtime impact

WordPress behaves differently in exactly two places, both English-only:

- `/en/cursos/` renders a filter bar it did not render before (5 options).
- Category **display** text on `/en/cursos/` is English instead of Portuguese.

Portuguese behaviour is byte-identical: `/cursos/` before and after share the
SHA-256 `ac1dcbc4ce6e98f7…` (§14). The category meta, the `?categoria=` slug,
the `meta_query`, the canonical, the hreflang pair and every card class are
unchanged.

## 5. Content / data impact

**None.** No record was created, updated or deleted, confirmed by a
before/after snapshot of every `course_provider` record (title, content, excerpt,
status, slug, language, translations, **all** post meta, and the
`conexao_category` / `conexao_tag` / `conexao_county` / `conexao_town`
relationships) — **byte-identical**, still exactly 10 records, 0 of them EN.

The only writes in the whole change are to the **source tree** (theme PHP, the
test, the `.po`/`.mo` catalogues, the acceptance matrix, docs).

## 6. Polylang impact

| Property | Result |
|---|---|
| PT provider data drift | **0** (snapshot byte-identical) |
| EN `course_provider` records created | **0** (asserted in the suite and in the snapshot) |
| Polylang configuration changed | **no** — no language, URL or assignment touched |
| `_provider_category` values changed | **no** — the 5 values are identical |
| Taxonomy migration / new terms | **no** — zero terms created |
| `course_provider` B2 status | **unchanged** — still in `conexao_b2_post_types()` (asserted) |
| Translation-completeness gate | `20 passed, 0 failed, 0 violation(s)`, 1850 allowlisted (unchanged) |

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. `/en/cursos/` keeps
its self-canonical EN URL and its `pt-BR ↔ en` hreflang pair (asserted by the

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |
| Polylang / taxonomy migration | **no** | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database).
This change needs no production content action at all: it is theme source plus
one compiled catalogue. Nothing was written outside this local repository.

## 9. Verification commands and results

Every command executed, with its real result.

| Command | Result |
|---|---|
| `docker compose exec wordpress php …/tests/test-en-course-provider-category-filter.php` | **60 passed, 0 failed** (exit 0) |
| `python3 tests/acceptance/verify-routing-http.py` | **80 passed, 0 failed** (exit 0) |
| `php scripts/generate-registry-docs.php --check` | `registry OK: 14 plugins validated, 23 generated regions current (zero writes)` |
| `python3 scripts/verify-permanent-gates.py` | **Gates 7 total, 6 passed, 1 failed; assertions 86 passed, 1 failed; violations 1 (1 pre-existing, 0 new)** |
| `./scripts/i18n-check.sh` | `8 passed, 1 failed`, `1 pre-existing, 0 new` (see §12) |
| `./scripts/lint.sh` step 1 (PHP syntax, 459 files) | `OK: no PHP parse errors.` |
| PHPCS on the 4 changed files (container) | **58 → 55 violations: 0 introduced, 3 pre-existing removed** (§9 static analysis) |
| PHPStan level 5 | **not completed** — environmental, see §13. No result claimed. |
| Live HTTP probe of 10 archives + 5 EN filter URLs | see `regression-matrix.md` |

### Numeric test results

In-process PHP (this stage's suite): **1 suite, 1 passed, 0 failed; 60 assertions
passed, 0 failed**.

`./scripts/run-tests.sh` (the full canonical runner) was **not executed** — see
§13. The stage's own suite and the HTTP acceptance matrix were both run
directly, and both passed.

### HTTP acceptance

**1 suite, 77 matrix rows, 80 assertions passed, 0 failed** (matrix
`tests/acceptance/matrices/routing.json`). The 5 added rows:

| Row | Asserts |
|---|---|
| `en-cursos-category-filter-bar-renders` | 200; the `providers-filter-bar` nav, the English `aria-label`, all 5 `?categoria=` links, and **no** Portuguese label |
| `en-cursos-category-labels-are-english` | 200; all 5 English card labels present and all 5 Portuguese ones absent |
| `en-cursos-category-filter-selection-works` | `?categoria=educacao` → 200, `<html lang="en-US">`, the active `aria-current` marker, English card labels |
| `en-cursos-unknown-category-yields-no-results` | an unknown slug yields zero cards (proves the widening did not make the filter permissive) |
| `pt-cursos-category-labels-stay-portuguese` | `/cursos/` keeps the Portuguese labels and the Portuguese `aria-label` |

### Static analysis

- **PHP syntax** — `OK: no PHP parse errors` across 459 files.
- **PHPCS** — the host PHP lacks `SimpleXML`/`xmlwriter`, so `./scripts/lint.sh`
  step 2 fails for **every** file, including untouched ones. PHPCS was
  therefore run inside the container (PHP 8.3, which has the extensions) with
  the repository ruleset, against the 4 changed files **and** against the same
  4 files at `HEAD`:

  | | violations |
  |---|---|
  | baseline (HEAD) | 58 |
  | after this change | 55 |

  **Zero PHPCS violations introduced; 3 pre-existing ones removed** as a
  side-effect of rewriting the `get_posts()` call. Raw CSVs in §14.
- **PHPStan** — **not completed** (environmental). See §13 item 3; no result
  is claimed in either direction.

### Script contracts

`./scripts/run-tests.sh --scripts` was **not run** (see §13). The two

## 10. Failure proofs / negative tests

A gate that has never been seen to fail is not a gate. Each row below was
observed **failing** during this work and then fixed — none by relaxing an
assertion.

| Proof | What was broken | Observed result |
|---|---|---|
| The suite catches a wrong lookup key | The first implementation keyed the label map with hyphens (`cursos-online`) while `conexao_provider_category_key()` normalises dashes to **spaces** (the shared `conexao_permit_employer_label_key()` rule). Live HTTP then rendered only 2 of 5 labels — `Education` and `Business` (single-word keys) worked, the 3 multi-word ones silently fell through to Portuguese. | HTTP: `provider-card-category">Cursos Online / Diretórios de Cursos / Formação Profissional` still present on `/en/cursos/`. Fixed → all 5 English. |
| The HTTP row catches a stray PT label | The `>Todos` fragment in the first PT row did not match because the template emits whitespace around the label. | `FAIL pt-cursos-category-labels-stay-portuguese — missing fragment: '>Todos'` → suite `79 passed, 1 failed`. Row made whitespace-tolerant. |
| Unknown values are safe, not invented | A category value containing markup is returned verbatim by the helper and must be escaped by the template. | Suite asserts `Teste <script>alert(1)</script>` renders as `Teste &lt;script&gt;` and that no raw `<script>` tag is emitted. |
| An unknown EN category yields no results | Proves the B2 widening did not make the filter permissive. | Row `en-cursos-unknown-category-yields-no-results`: `?categoria=nao-existe` → 0 cards. |
| PT is genuinely unchanged | Byte comparison of the whole page. | `/cursos/` before/after SHA-256 both `ac1dcbc4ce6e98f7…`; the JSON snapshot comparison prints `DRIFT = NONE (byte-identical)`. |

## 11. Regression comparison

Before vs after, same environment, live HTTP.

| Check | Before | After |
|---|---|---|
| `/en/cursos/` filter bar | **absent** (0 `providers-filter-bar`) | present, 5 options + "All" |
| `/en/cursos/` card category labels | PT: `Cursos Online`, `Diretórios de Cursos`, `Educação`, `Formação Profissional`, `Negócios` | EN: `Online Courses`, `Course Directories`, `Education`, `Vocational Training`, `Business` |
| `/cursos/` (whole page) | SHA-256 `ac1dcbc4ce6e98f7…` | SHA-256 `ac1dcbc4ce6e98f7…` — **identical** |
| EN filter URLs | n/a | all under `/en/cursos/?categoria=…` (5/5) |
| EN filter selection | n/a | all 5 slugs → 200, `lang="en-US"`, card counts 1+1+1+3+4 = **10** (= the dataset) |
| PT/EN filter parity | n/a | `?categoria=negocios` → 1 card and `?categoria=educacao` → 3 cards on **both** archives |
| `/en/eventos/` filter UI | present | present (`event-filters-*`, dropdowns `County`/`Town`/`Category`) |
| `/en/lazer/` filter UI | present | present |
| `/en/guias/`, `/en/blog/`, and the 6 PT routes | 200, correct `lang` | 200, correct `lang` (unchanged) |
| In-process suites | — | 60 passed, 0 failed |
| Acceptance assertions | 75 passed, 0 failed | **80 passed, 0 failed** |
| Permanent gates | 6/7 (1 pre-existing) | **6/7 (1 pre-existing, 0 new)** |

The full route matrix is in `regression-matrix.md`. The event and leisure B2
fixes are asserted in the suite itself (`widen_event` = `en,pt`,
`widen_leisure` = `en,pt` on an EN request; both **empty** on a PT request), so
a future change to the shared helper fails the gate rather than silently
regressing them.

repository-level script gates that touch this change were run directly and
passed: `generate-registry-docs.php --check` (zero drift) and
`verify-permanent-gates.py` (6/7, the 1 failure pre-existing).

### Release / build results

**not in scope** — no artifact was built and nothing was deployed.

pre-existing row `en-cursos-canonical-and-hreflang`, still passing).

**HTTP acceptance: 1 suite (`acceptance/routing-http`), 80 assertions passed,
0 failed** — 77 matrix rows + 3 redirect-target checks, up from 75. The 5 new
rows are listed in §9.

## 12. Known pre-existing failures

### The one failing permanent gate: `i18n_freshness` — PRE-EXISTING, not mine

`python3 scripts/verify-permanent-gates.py` reports **1 violation, classified
`1 pre-existing, 0 new`**. The gate itself says so; it was confirmed
independently by stashing every change and re-running it on a clean `HEAD`:

```
=== BASELINE (HEAD, no changes) ===
   conexao-br-irlanda  catalogue=1790431901 newest-source=1790505983
                      (…/inc/i18n/fallback.php) STALE
  stale catalogues: 1
  stale catalogues: 1 pre-existing, 0 new
```

**Identical numbers before and after this change.** The cause is that the gate
compares **git commit time**: `conexao-br-irlanda.pot` was committed before
`inc/i18n/fallback.php` was last committed, so the catalogue is already stale at
`HEAD`. No working-tree edit can change a commit-time comparison, so this
failure is structural to the current commit history rather than to this stage.

I still attempted the documented remedy, `./scripts/i18n-make-pot.sh --apply
conexao-br-irlanda`. It reported `regenerated=0, failed=1` and left the `.pot`
**byte-unchanged** (`git status` clean for that file), so nothing was corrupted.
Running the same generator on the stashed (unmodified) sources **succeeds**,
which localises the problem to the generator's own post-processing rather than
to my PHP — but diagnosing and fixing that generator is a separate concern from
this task and was deliberately left alone rather than half-fixed.

The functional consequence is nil: the theme's EN strings come from the
committed `en_US.mo`, which **was** recompiled and **is** served correctly
(verified live in §7 and §9). The `.pot` is a translator-facing template, not a
runtime artefact.

**Not suppressed.** The gate was left red and is reported as red.

### A stale committed `gate.json` was masking that failure

`scripts/verify-permanent-gates.py` writes its result to
`docs/evidence/2026-09-26-stage-l/gate.json`. The **committed** copy of that file
was generated at commit `7778d76` and records `"status": "pass"` — a historical
snapshot, not the current truth. Regenerating it at the real HEAD (`3cc6564`)
shows `"status": "fail"` with `1 pre-existing, 0 new`.

So the pre-existing `i18n_freshness` failure is real and current; the committed
artifact simply predates it. No gate **logic, threshold, baseline, allowlist or
B2 policy** was touched — only the generated result file changed, and the
per-gate key set is byte-for-byte the same shape.

Because that file belongs to a *different* stage's evidence directory, it was
**restored to its committed state** rather than left modified, to keep this
change's footprint scoped. This stage's own gate result is preserved verbatim at
`docs/evidence/2026-09-27-en-cursos-category-filter/gate.json`
(`repository_sha: 3cc6564…`, `status: fail`, `86 passed / 1 failed /
1 violation / 1 pre-existing / 0 new`).

## 13. Limitations

Everything that could **not** be verified here, and why.

1. **Browser (desktop + mobile) verification was not performed.** No browser
   automation tooling is available in this environment — the only UI tools
   exposed are the Flutter MCP toolkit, which targets the **separate**
   Flutter/mobile repository and is out of scope. This was **not** claimed as
   passed. Real HTTP verification was performed instead: it proves the served
   markup, the filter URLs, the language attribute, the result sets and the
   canonical/hreflang, but it does **not** prove rendering, wrapping, the
   responsive dropdown behaviour, or the absence of JavaScript errors on a real
   device. The course filter bar is a plain server-rendered `<nav>` of
   hyperlinks (it has no JS of its own), so the JavaScript-specific risk is low,
   but that is reasoning, not a measurement.
2. **`./scripts/run-tests.sh` (the full canonical runner) was not executed.**
   It runs every in-process suite, including DB-writing rollout suites, and
   takes far longer than the environment's per-command budget allows. The two
   suites that cover this change **were** run directly and passed (60/0 and
   80/0), and the permanent gates were run directly. Suites that were not run
   are **not** claimed as passed.
3. **PHPStan** — **not completed** (environmental); no result is claimed in
   either direction. The host PHP lacks `SimpleXML`/`xmlwriter` and `composer`
   is not installed, so `./scripts/lint.sh` step 3 cannot run on the host.
   PHPStan was run inside the container against the repository configuration
   (`phpstan.neon.dist`, level 5, with the committed baseline). The first
   attempt crashed on the container's default `memory_limit=512M`; the retry
   with `--memory-limit=2G` exhausted the host and **stopped the Docker
   daemon**, so the run could not finish. Because the two functions added here
   are pure string helpers with no dynamic input, and PHPCS (which did run,
   §9) reports zero new violations, the risk is low — but that is reasoning,
   not a measurement. **CI is authoritative for PHPStan.**
4. **No production verification**, by design (§8). No deploy, no upload, no
   plugin activation, no production content change.
5. **PHPCS had to be run in a non-standard way** — inside the container with a
   copy of `vendor/`, because the host PHP cannot load the tool at all. The
   ruleset, the changed files and the HEAD baseline were the same, and the
   before/after counts are directly comparable, but the local `vendor/` copy is
   a local-only convenience that is not part of the repository.

## 14. Evidence paths

`docs/evidence/2026-09-27-en-cursos-category-filter/`

| File | Proves |
|---|---|
| `http-en-cursos-before.html` | the defect: `/en/cursos/` with **no** filter bar and PT category labels (SHA-256 `59400ee23f570d83…`) |
| `http-en-cursos-after.html` | the fix: the English filter bar, English card labels, EN canonical/hreflang (SHA-256 `375b42a847a4bfd3…`) |
| `http-cursos-before.html` / `http-cursos-after.html` | PT immutability at the page level — **byte-identical**, both `ac1dcbc4ce6e98f7…` |
| `pt-snapshot-before.json` / `pt-snapshot-after.json` | PT immutability at the data level — 10 records, all fields + all meta + all taxonomy relationships + language/translation links, byte-identical; 0 EN records |
| `inprocess-test.txt` | the focused suite: **60 passed, 0 failed** |
| `http-acceptance-routing.txt` | `acceptance/routing-http`: **80 passed, 0 failed** (incl. the 5 new rows) |
| `permanent-gates.txt` / `gate.json` | 7 gates, 6 passed, 1 failed; 86 assertions passed, 1 failed; 1 violation, **1 pre-existing, 0 new** |
| `regression-matrix.md` | the 10-archive before/after matrix (status, `html lang`, filter-UI markers) |
| `phpcs-baseline.csv` / `phpcs-after.csv` | PHPCS on the 4 changed files at HEAD (58) and after (55) — raw, so the comparison is auditable |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | `course-filters.php` renders `label`; `provider-card.php` category is language-aware; `conexao_get_provider_categories()` goes through the shared B2 widening and returns `name`/`slug`/`label`. |
| `docs/content-model.md` | §Course Provider — `_provider_category` is free-text, not a taxonomy; documents the presentation layer, the never-rewrite rule, the unknown-value contract and the shared slug. |
| `docs/routing.md` | `/en/cursos/` listed with `?categoria=`; the filters paragraph explains the B2 widening and the language-aware label. |
| `docs/reports/2026-09-27-en-cursos-category-filter.md` | this report. |

`AGENTS.md` and `plugins.json` were **not** hand-edited: the plugin set, load
order, mounts and class did not change, so `generate-registry-docs.php --check`
reports zero drift (23 generated regions current).

## 16. Rollback / recovery

```bash
git checkout -- \
  wp-content/themes/conexao-br-irlanda/inc/queries.php \
  wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php \
  wp-content/themes/conexao-br-irlanda/template-parts/course-filters.php \
  wp-content/themes/conexao-br-irlanda/template-parts/provider-card.php \
  wp-content/themes/conexao-br-irlanda/languages/en_US.po \
  wp-content/themes/conexao-br-irlanda/languages/en_US.mo
```

**There is nothing to roll back in the data.** No record, meta value, term or
Polylang assignment was written, so `/cursos/` and `/en/cursos/` return to the
exact pre-change behaviour the moment the theme source is reverted. The
reverted state is the recorded "before" state in this evidence directory.

**What rollback does not cover:** the 5 new acceptance rows in
`tests/acceptance/matrices/routing.json` must be removed at the same time, or
they will fail against the reverted theme — which is the correct, loud
behaviour.

**No production rollback is needed**, because nothing was applied there.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Every verifier that ran passed with real numbers (60/0 in-process, 80/0 HTTP,
6/7 gates with the single failure proven pre-existing, 0 new PHPCS violations,
PT data drift 0, PT page byte-identical), but **browser verification and the
full `./scripts/run-tests.sh` run could not be completed in this
environment**, so `PASS` would be a claim I cannot back. Nothing was weakened or
suppressed to obtain the result.

_Last verified: 2026-09-27 by STAGE 9 — the `/en/cursos/` category filter and
English provider-category presentation_
