# Add a content type

## Purpose

Register or change a content type the safe way: prove a new CPT is actually
needed, keep schema in the one owning plugin, decide taxonomy and language
policy deliberately, and ship registration + routes + tests + docs in one
change.

## When to use

A task asks for a new or changed post type, taxonomy, meta field, archive or
single route. **First prove a new CPT is actually needed** — most "add a CPT"
requests are a taxonomy term, a meta field, or a filter on an existing type.

## When not to use

- Admin screens — `wp-add-admin-screen`.
- Tests — `wp-write-in-process-test`, `wp-http-acceptance-matrix`,
  `wp-run-tests`.
- English coverage for the new type — `wp-translation-rollout` (this skill
  only decides and declares the language policy).
- Bulk content writes into an existing type — `wp-content-change`.
- Building or deploying a release — `wp-release-deploy`.

## Required reading

- `AGENTS.md` — orientation and the non-negotiable architecture rules.
- `docs/engineering-standard.md` §5.1 (declaring or changing content) and §5
  (the content-change contract). This is the authority; where another document
  disagrees, it is corrected in the same change.
- `docs/content-model.md` — the current types, taxonomies and meta.
- `docs/routing.md` — the URL the new type will occupy.
- `docs/plugins/conexao-data-model.md` — the only place schema is registered.
- `plugins.json` — the authoritative plugin registry (see `wp-plugin-registry`).

## Authoritative sources

- `docs/content-model.md` owns the current types, taxonomies, meta and
  language policy — update it in the same change; never restate its tables.
- `plugins.json` owns the plugin set and lifecycle facts; a schema change
  inside `conexao-data-model` needs no registry entry, but the plugin version
  header is the authoritative version.
- `docs/routing.md` owns the URL the type occupies; the rewrite slug is part
  of the public URL contract.

## Preconditions

- The local Docker Compose site is running for the in-process and HTTP
  verification.
- The CPT-needed decision is recorded (step 1) before any code is written.

## Steps

1. **Decide whether a CPT is required.** Check, in order: does an existing CPT
   already model this content with a term or meta field? A new CPT adds an
   archive, a single, a sitemap entry, a language decision and a tax question —
   a term or meta field usually costs far less. Record the decision and its
   rationale in the report either way.
2. **Review `conexao-data-model` before writing.** The CPT/taxonomy/meta
   registration lives in
   `wp-content/plugins/conexao-data-model/includes/` (and the main plugin file
   for the registration entry point). Schema is registered **only** there — a
   second registration in the theme or another plugin is a defect, not a
   variation. The `leisure` CPT is the worked example of this rule.
3. **Taxonomy and meta ownership.** A new taxonomy is registered in
   `conexao-data-model`; reusable *filter* taxonomies
   (`conexao_category`, `conexao_tag`, `conexao_county`, `conexao_town`) are
   shared across types rather than duplicated per type. Meta is registered with
   `register_post_meta()`, and **must** declare an `auth_callback` when it is
   writable over REST. Decide explicitly whether each new taxonomy is
   Polylang-translated or shared — see step 7.
4. **Archive and single behaviour.** The rewrite slug is part of the public URL
   contract: a slug change is a redirect, not an edit. The archive URL must be
   reachable at the documented path, and the single must be served by the
   template hierarchy (`archive-<type>.php` / `single-<type>.php` in
   `wp-content/themes/conexao-br-irlanda/`, with reusable markup in
   `wp-content/themes/conexao-br-irlanda/template-parts/`). Note the `leisure` precedent: it is a directory whose
   singles intentionally redirect off-site.

5. **Registry implications.** A new *plugin* means editing `plugins.json` and
   running `php scripts/generate-registry-docs.php --write` (never hand-editing
   a generated region). A new CPT inside `conexao-data-model` is a schema change
   and needs no registry entry — but the data-model plugin version header is the
   authoritative version and must be bumped for a shipped change.
6. **Tests.** Add an in-process suite at
   `wp-content/plugins/conexao-data-model/tests/test-<area>-<behaviour>.php`
   using `tests/bootstrap.php` and `tests/lib/assertions.php` (see
   `wp-write-in-process-test`). It is discovered by convention — no list to
   edit. The suite must assert registration (the type/taxonomy exists with the
   expected labels and rewrite base) **and** behaviour (the archive query
   returns the expected records).
7. **English / Polylang implications.** Decide and record: translated post type
   (linked EN records) or an authored EN field on the same record, and whether
   each taxonomy is translated or shared. The post type and its taxonomies are
   declared through Polylang in
   `wp-content/themes/conexao-br-irlanda/inc/i18n/` — not in the plugin. A
   translated type needs the completeness gate from §6.1
   (`eligible public PT <type> missing EN = 0`, or a documented allowlist). Any
   EN coverage work is the `wp-translation-rollout` skill, not this one.
8. **HTTP acceptance row.** Add rows to the appropriate matrix in
   `tests/acceptance/matrices/` for the archive, one single, the filters and
   the language pair (see `wp-http-acceptance-matrix`).
9. **Documentation, in the same change:** `docs/content-model.md`,
   `docs/routing.md`, `docs/plugins/conexao-data-model.md`, and the plugin
   header version. Each doc ends with its `_Last verified_` line.

## Guardrails

- **No direct production edits.** Production is WordPress.com: no SSH, no
  WP-CLI, no filesystem, no database access. Schema ships as a plugin update.
- **Portuguese is canonical.** A new type's PT slug and URL never change as a
  side effect of later English work.
- Schema is registered in `conexao-data-model` **only** — never re-registered by
  the theme, and never by a second plugin (declare `Requires Plugins:` instead).
- A content-model change is additive and backwards compatible. A rename requires
  a redirect in `wp-content/themes/conexao-br-irlanda/inc/seo/redirects.php` and
  a migration note.
- No localhost URLs in production data. No hotlinked media — images are local
  Media Library attachments with attribution/license meta preserved.
- A behaviour-preserving move (module split, rename) must not be mixed with a
  behaviour change in the same commit.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
php scripts/generate-registry-docs.php --check        # no registry drift
./scripts/run-tests.sh --only conexao-data-model      # in-process suite
./scripts/run-tests.sh --acceptance                   # HTTP rows
./scripts/lint.sh                                     # PHPCS + PHPStan, no new violations
```

- The in-process suite must print `N passed, 0 failed` and **exit 0**.
- Every new HTTP matrix row must pass; report the exact assertion totals from
  `tests/acceptance/verify-<area>-http.py`.
- `git status --short` must show no change under `wp-content/themes/` that was
  not planned, and any theme change must be explained.
- Record the real numbers in the report (from `docs/templates/report.md`). If a
  verifier could not run, say so — never write "should work".

## Failure handling

- **The CPT decision is wrong halfway:** stop and re-decide — a taxonomy term
  or meta field introduced "temporarily" alongside a CPT is the duplication
  this skill exists to prevent.
- **A second registration already exists in the theme or another plugin:**
  that is a defect — remove it and declare `Requires Plugins:` instead;
  never leave both.
- **A matrix row fails over HTTP:** the archive/single contract in
  `docs/routing.md` wins over the implementation — fix the code or, if the
  route contract genuinely changed, update `docs/routing.md` deliberately.
- **The completeness gate is non-zero after declaring a translated type:**
  that is expected until EN coverage exists — record the number and hand the
  coverage work to `wp-translation-rollout`; never mark the type untranslated
  to silence the gate.

## Evidence and reporting

- Real numbers from `--only conexao-data-model`, the acceptance suite and the
  registry drift check go in the report (from `docs/templates/report.md`), with
  evidence under `docs/evidence/<date>-<stage>/`.
- The report records the CPT decision and its rationale either way — a
  rejected CPT is a finding, not an omission.

## Definition of done

- [ ] The CPT decision (needed or not) is recorded with its rationale.
- [ ] Registration exists only in `conexao-data-model`; no duplicate schema.
- [ ] Meta declares `auth_callback` where REST-writable.
- [ ] Taxonomy ownership and the Polylang translated/shared decision are recorded.
- [ ] The archive and single routes are documented and verified over HTTP.
- [ ] A new in-process suite exists, is discovered by convention and passes.
- [ ] HTTP matrix rows exist for the archive, a single and the language pair.
- [ ] `docs/content-model.md`, `docs/routing.md`, the plugin doc and the header
      version are updated in the same change.
- [ ] `plugins.json` is still the single source of truth; the drift gate passes.
- [ ] The completeness gate is `0` (or an explicit allowlist) if EN coverage exists.
- [ ] No production write occurred; no `wp-content` change outside the plan.
- [ ] The report carries real verification numbers and stated limitations.
