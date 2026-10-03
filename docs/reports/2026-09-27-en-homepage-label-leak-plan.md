# Plan — EN homepage content-type label leak (`Guia Prático`)

| | |
|---|---|
| **Task title** | Fix the English homepage "Guia Prático" label leak |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `849624574451c1aa771f2fac0bd66b66fd2aadd8` |
| **Plan status** | approved |

## 1. Scope

Fix the confirmed i18n defect where the Portuguese label **`Guia Prático`** is
rendered verbatim on the English homepage `/en/`. The fix is presentation-layer
only: the homepage card chips read the post type's registered `singular_name`,
which the data model registers as a raw Portuguese literal, so it is never
passed through gettext and therefore never translated.

Deliver: a narrowly additive theme presentation helper that returns a
translated content-type label, the two call sites switched to it, focused
regression coverage, HTTP acceptance rows, and this plan + report + evidence.

## 2. Explicit non-goals

Deliberately **not** done, so a reviewer does not read the omissions as
oversights:

- **No change to CPT registration in `conexao-data-model`.** The brief forbids
  changing post-type registration semantics for a presentation concern. The
  registration literals are also the pt_BR source of truth and are shared with
  wp-admin; wrapping them in `__()` there would change plugin behaviour and
  require a plugin catalogue.
- **No new translation lifecycle / engine.** The existing gettext workflow
  (`languages/*.po` → `.mo`, `scripts/i18n-make-pot.sh`, `scripts/i18n-check.sh`)
  is reused as-is.
- **No arbitrary allowlist and no gate weakening.** Every gate keeps its
  fail-closed behaviour; the pre-existing i18n-freshness failure is recorded as
  pre-existing, not suppressed.
- **No redesign of homepage labels**, no new chip, no CSS/HTML/class change.
- **No unrelated fixes**, explicitly: the accessibility defects, leisure
  performance, Jetpack sitemap/robots, unrelated SEO cleanup, taxonomy policy,
  metadata migration, and the `post`→`Artigo` chip question (recorded as a
  finding, out of scope — see §4).
- **No `plugins.json` change** — the plugin set, load order, mounts and versions
  are unaffected by a theme presentation fix.
- **No content/data writes, no production writes, no deploy, no Flutter/mobile
  repository access.**

## 3. Relevant authoritative documents

Read before implementation: `AGENTS.md`;
`docs/engineering-standard.md` (§0 change-safety, §6.1 bilingual
non-negotiables, §8 testing, §9 documentation, §14 definition of done);
`docs/content-model.md`; `docs/routing.md` (§English rollout state);
`docs/releases.md`; `docs/testing.md`; `docs/frontend.md`;
`docs/themes/conexao-br-irlanda.md`; `docs/templates/plan.md`;
`docs/templates/report.md`; `.agents/skills/wp-translation-rollout/SKILL.md`
(§"Locale is read only through `conexao_current_locale()`");
`.agents/skills/wp-update-docs/SKILL.md`; `scripts/README.md`.

## 4. Current-state findings

Verified by reading code and by live HTTP against local WordPress
(`http://localhost:8080`), after bootstrapping the documented local stack
(`docs/development.md`): WordPress core install, theme activation, Polylang
3.8.9 install/activation, and `scripts/run-polylang-setup.php`.

**The defect is reproduced.** `/en/` returns **200** and contains:

```html
<span class="featured-article-category">Guia Prático</span>
```

Evidence: `docs/evidence/2026-09-27-en-homepage-label-leak/http-en-homepage-before.html`.
The Portuguese `/` returns **200** with the same chip reading `Guia Prático`,
which is correct there.

**Root cause.** `front-page.php:269` and `front-page.php:309` echo
`$content_type->labels->singular_name` directly:

```php
$content_type = get_post_type_object( get_post_type() );
...
echo esc_html( $content_type->labels->singular_name );
```

`conexao-data-model.php:50` registers the post type with a **raw Portuguese
literal**, not a gettext call:

```php
'guide' => array( 'plural' => 'Guias Práticos', 'singular' => 'Guia Prático', ... ),
```

`register_post_type()` labels are literal strings; WordPress does not translate
them. So the value reaching the template is always Portuguese regardless of the
requested locale, and the `esc_html()` call is correct but cannot help.

**The fix is presentation-layer.** The homepage already renders through gettext
for every other string, and the theme text domain is loaded by
`inc/setup.php:48` (`load_theme_textdomain`). Passing the label through `__()`
is the project's established mechanism.

**The translation already exists.** `Guia Prático` → `Practical Guide` is
already present in both `languages/en_US.po` (line 119) and the compiled
`languages/en_US.mo` (verified with `msgunfmt`). It is referenced from
`inc/seo/titles.php:37`. Therefore **no new translatable string and no catalogue
change is required** — the leak is a *missing gettext call*, not a missing
translation.

Confirmed live in the local EN context:

```
locale: en_US
raw guide label:     Guia Prático
via __():           Practical Guide
```

**Existing pattern check (Phase 4).** `conexao_cpt_label()` exists in
`inc/seo/schema.php:428` and is the closest existing helper, but it is **not a
drop-in**: it returns **plural** labels (`Guias Práticos`), it has **no
`course_provider` key**, it is SEO/breadcrumb-scoped, and it falls back to
`->labels->name`. Reusing it would change the chip from singular to plural and
alter PT output. It is therefore left untouched, and the fix is a separate,
narrowly additive presentation helper.

**`course_provider` / other CPTs.** Every registered CPT leaks the same way
(`Evento`, `Vaga de Emprego`, `Apoiador`, `Provedor de Cursos`, `Local de
Lazer`, …). The homepage featured query is `post_type => array( 'guide', 'post' )`
(`front-page.php:246`), so only `guide` and `post` can reach these two chips
today. The helper is written to be correct for any post type (translate the
registered label, fall back to it when no translation exists), so other CPTs
are fixed by construction where they are rendered, without special-casing.

**Related finding, out of scope.** The `post` chip renders `Artigo` — WordPress
core's own `post` type label, registered by core (not by this project) and
localised by core's catalogue, not the theme's. It is a distinct mechanism from
the project CPT leak and is **not** fixed here; it is recorded in the report so
it is not mistaken for a regression of this change.

**Baseline gate state (pre-change, at `8496245`).**
`./scripts/run-tests.sh --scripts` → **6 suites, 5 passed, 1 failed**; the
failure is `tests/scripts/verify-i18n-freshness.py` (theme `.pot` older than
`inc/i18n/fallback.php` by 87215 s). It is recorded in
`tests/baseline/permanent-gates.json` as pre-existing. The remaining 5 suites
pass: 2 + 14 + 210 + 313 assertions green.

## 5. Proposed approach

**Chosen:** a narrowly additive theme presentation helper,
`conexao_content_type_label( $post_type )`, in `inc/content.php` (the theme's
existing presentation-helpers module), that returns the post type's
`singular_name` passed through `__()` with the theme text domain, and falls back
to the untranslated label when no translation exists. `front-page.php:269` and
`:309` call it instead of reading `->labels->singular_name` directly.

Why this is correct and minimal:

- It reuses gettext — the project's established, documented i18n mechanism — and
  introduces no new engine, lifecycle or allowlist.
- It requires **no new translatable string** (`Practical Guide` already exists),
  so the `.pot`, `.po` and `.mo` are untouched and catalogue freshness cannot
  regress.
- PT is provably unaffected: `pt_BR` is an identity catalogue
  (`msgid "Guia Prático"` → `msgstr "Guia Prático"`), so on PT requests
  `__()` returns the identical string and the rendered bytes are unchanged.
- The fallback keeps unknown/untranslated post types rendering exactly as today,
  so no CPT can lose its label.
- The CPT registration is not touched, so no plugin, admin or REST behaviour
  changes.

**Rejected alternatives:**

| Option | Rejected because |
|---|---|
| Wrap the CPT labels in `__()` inside `conexao-data-model.php` | The brief forbids changing registration semantics for a presentation concern; it alters plugin/admin behaviour, needs a plugin catalogue, and the labels are the pt_BR source of truth. |
| Reuse `conexao_cpt_label()` | It returns **plural** labels, lacks `course_provider`, and is SEO-scoped; using it would change the chip to plural and alter PT output. |
| Hardcode an EN label map in the template | Duplicates the catalogue as a second source of truth and is exactly the "arbitrary allowlist" the brief forbids. |
| Add a new msgid / new catalogue entry | Unnecessary — `Practical Guide` already exists; a new string would churn the `.pot`/`.mo` and risk the freshness gate. |

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/content.php` | Add `conexao_content_type_label()` presentation helper | The missing gettext call lives here, next to the other presentation helpers |
| `wp-content/themes/conexao-br-irlanda/front-page.php` | Lines 269 and 309 call the helper instead of `->labels->singular_name` | The two leak sites |
| `wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php` | New focused in-process regression suite | Prove PT/EN behaviour of the helper and the no-leak property |
| `tests/acceptance/matrices/routing.json` | Add EN + PT homepage rows | Prove the leak is gone and PT stays correct over HTTP |
| `docs/reports/2026-09-27-en-homepage-label-leak-plan.md` | This plan | Required by §13.2 before EN-affecting work |
| `docs/reports/2026-09-27-en-homepage-label-leak.md` | This task's report | Required by §9.2 |
| `docs/evidence/2026-09-27-en-homepage-label-leak/` | Machine evidence | Required by §9.1 |
| `docs/themes/conexao-br-irlanda.md` | Document the helper | §9.2 same-commit doc rule |

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | CPT registration semantics are out of scope (brief + §2 above) |
| `plugins.json` | Plugin set, order, mounts, versions unchanged |
| `wp-content/themes/conexao-br-irlanda/languages/*` (`.po`/`.mo`/`.pot`) | No new translatable string; the translation already exists |
| `wp-content/themes/conexao-br-irlanda/inc/seo/schema.php` | `conexao_cpt_label()` is plural/SEO-scoped; touching it would change breadcrumbs/schema |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/**` | Polylang policy, locale, B2 fallback untouched |
| `tests/baseline/permanent-gates.json` | Never edited to make a gate green |
| `phpcs-baseline.json`, `phpstan-baseline.neon` | Never edited to absorb new violations |
| `wp-content/plugins/conexao-*-translation/` | Retired rollout plugins — not modified or revived |

## 8. Content / data impact

- Records created / updated / deleted: **none in the repository sense.**
- **Local reproduction fixture only** (throwaway, not committed, not part of the
  change): WordPress core install, Polylang config, one PT guide + its linked EN
  translation, one EN blog post, and the linked EN translation of the PT front
  page. These exist so `/en/` renders `front-page.php`; they live only in the
  local Docker database.
- Portable identifier strategy: not applicable — no portable identifier is
  introduced; the helper takes a post-type **name**, not an ID.
- Does this write content? **No** (no repository content write, no production
  write), so the six-step content-change contract (§5.2) does not apply.
- PT content changed? **No.** `pt_BR` is an identity catalogue, so PT output is
  byte-identical; verified by HTTP before/after.

## 9. Route / HTTP impact

- Routes added / removed / changed: **none.** `/` and `/en/` keep their
  current status, canonical, hreflang and redirect behaviour.
- Redirects needed: **none.**
- Sitemap coverage: **unchanged** (no URL is added or removed).
- HTTP matrix rows to add: two rows in `tests/acceptance/matrices/routing.json`
  — `en-home-no-pt-label` (`/en/`, 200, contains `Practical Guide`, **absent**
  `Guia Prático`) and `pt-home-keeps-pt-label` (`/`, 200, contains
  `Guia Prático`).

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none.** No `pll_*` filter, no
  declaration in `inc/i18n/guard.php` is touched.
- Is PT immutable in this change? **Yes** — and the helper is deliberately
  PT-neutral (identity catalogue ⇒ identical output).
- B1/B2 for each destination: **unchanged.** The homepage is not a B2 fallback
  surface; `conexao_is_language_fallback()` is false on `/en/` (verified: no
  `language-fallback-notice` in the EN HTML).
- Canonical / hreflang / language-switcher: **unchanged.**
- Language-scoped cache keys affected: **none.** The helper adds no query and no
  cache; it is a pure string mapping over an already-fetched post-type object.
- Completeness gate: unaffected — this change creates no EN coverage and adds
  no allowlist entry.

## 11. Security impact

- Capabilities checked: **unchanged** (public read-only rendering).
- Nonces added/changed: **none.**
- Sanitisation / escaping: output stays wrapped in the existing `esc_html()` at
  both call sites; the helper returns a plain string and escapes nothing itself,
  so the escaping contract is unchanged. No raw echo is introduced.
- SQL: **no** query is added; the helper only reads the post-type object the
  template already fetched.
- REST surface change: **none.**
- Secrets: **none.**

## 12. Performance impact

- Queries added or removed: **0.** The helper receives the post-type name and
  calls `get_post_type_object()`, which is already in-memory (the object is
  already resolved by the caller) — no additional DB round-trip.
- Caching: **unchanged** — no new transient, no new object-cache key, so no
  language-scoping obligation is introduced.
- Assets/images: **none.**
- Expected measurable improvement: none claimed; this is a correctness fix.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | N/A — nothing is performed |
| What is the verification of the production result? | N/A — no production action |

## 14. Test plan

- In-process suite to add:
  `wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php`
  — asserts (a) the helper exists, (b) it returns the translated EN label for
  `guide` under `en_US`, (c) it returns the identical PT label under `pt_BR`
  (PT byte-preservation), (d) an untranslated post type falls back to its
  registered label rather than returning empty, (e) `front-page.php` contains no
  remaining raw `->labels->singular_name` read in the featured loop, and
  (f) `conexao_cpt_label()` is unchanged (plural, SEO-scoped).
- Acceptance rows: the two rows in §9, run by
  `./scripts/run-tests.sh --acceptance`.
- Script-contract impact: none expected; `./scripts/run-tests.sh --scripts`
  must stay at the same 5-pass/1-pre-existing-fail.
- Registry drift check: not applicable — `plugins.json` unchanged.
- Numeric pass condition: the new suite 0 failures; acceptance 0 failures;
  `/`, `/en/`, `/en/cursos/`, `/en/lazer/`, `/en/eventos/`, `/en/empregos/`
  all 200.

## 15. Acceptance matrix plan

Added to `tests/acceptance/matrices/routing.json`:

```json
{
  "id": "en-home-no-pt-content-type-label",
  "url": "/en/",
  "expect_status": 200,
  "expect_contains": ["Practical Guide"],
  "expect_absent": ["Guia Prático"],
  "note": "The EN homepage content-type chip renders the English label; the Portuguese literal must not leak onto /en/."
}
{
  "id": "pt-home-keeps-pt-content-type-label",
  "url": "/",
  "expect_status": 200,
  "expect_contains": ["Guia Prático"],
  "expect_absent": [],
  "note": "The PT homepage is canonical and keeps its Portuguese label; PT output is unchanged by the EN label fix."
}
```

Expected assertion delta: **+2 rows** (EN: status + contains + absent; PT:
status + contains).

## 16. Rollback plan

The change is code-only and reversible in one step:

```bash
git revert <commit>          # or
git checkout HEAD~1 -- wp-content/themes/conexao-br-irlanda/inc/content.php \
                         wp-content/themes/conexao-br-irlanda/front-page.php \
                         tests/acceptance/matrices/routing.json
```

Because **no content, data, database or configuration is written**, rollback
covers the entire change: there is no content reversal to perform and no
snapshot to restore. To roll back the *local reproduction fixture* only, drop
the local Docker volume (`docker compose down -v`); it never affected the
repository or production.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | Document `conexao_content_type_label()` in the helper/module reference |
| `docs/reports/2026-09-27-en-homepage-label-leak.md` | The task report (scope, root cause, numbers, limitations) |
| `docs/evidence/2026-09-27-en-homepage-label-leak/` | Before/after HTML, gate output, acceptance output, regression matrix |
| `AGENTS.md` | **No change** — no count, route or plugin-set change |

## 18. Release implications

- Component version bump: **none.** This is a behaviour fix inside an existing
  theme release, not a plugin-registry change; `plugins.json` is untouched.
- Effect on the artifact allowlist (derived from `plugins.json`): **none.**
- Is a release in scope? **No** — explicitly out of scope. No build, manifest,
  ZIP, deploy or verification run is part of this task.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| PT output changes (identity-catalogue assumption wrong) | Low | `pt_BR` verified as an identity catalogue; PT byte-comparison captured before/after over HTTP |
| The fix reads as "special-casing `guide`" | Low | The helper is post-type agnostic — it translates whatever label the CPT registered and falls back safely |
| Scope creep into unrelated homepage/SEO/a11y work | Medium | Explicit non-goals in §2; no file outside §6 is touched |
| Unrelated pre-existing gate failures misattributed | Medium | Baseline captured at `8496245` before editing and reported separately |
| Local environment cannot boot WordPress, so the defect is unverifiable | Medium | Stack was bootstrapped per `docs/development.md`; live 200s and rendered HTML captured as evidence |
| New PHPCS/PHPStan violation | Low | Helper follows the existing code style; `./scripts/lint.sh` gate run and compared to baseline |

## 20. Verification gates

1. `./scripts/run-tests.sh --only theme` — new + existing theme suites, numeric.
2. `./scripts/run-tests.sh --acceptance` — HTTP rows incl. the 2 new ones.
3. `./scripts/run-tests.sh --scripts` — must remain 5 pass / 1 pre-existing fail.
4. `python3 scripts/verify-permanent-gates.py` — permanent gates, fail-closed.
5. `./scripts/lint.sh` — PHP syntax + PHPCS baseline + PHPStan, no new violations.
6. `php scripts/generate-registry-docs.php --check` — registry drift, 0 writes.
7. Live HTTP: `/` and `/en/` 200, `Guia Prático` absent on `/en/`, present on
   `/`, plus `/en/cursos/`, `/en/lazer/`, `/en/eventos/`, `/en/empregos/` 200.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: scope matches the request; lint adds no
new violations; an in-process test plus HTTP matrix rows cover the
request-visible change; **no content write** (so §5.2 does not apply); §6.1
bilingual rules hold (PT canonical and unchanged, no policy edit, no second
lifecycle, no cache key, no allowlist); docs updated in the same commit with no
root-level report; the report carries real numbers and stated limitations; the
Flutter/mobile repository is untouched; and the working tree is clean apart from
the intentional changes listed in §6.

_Last verified: 2026-09-27 by the EN homepage label task_
