# Frontend performance

## Purpose

Keep an improvement measurable and prevent an accidental regression: language-scoped
cache keys, complete invalidation, disciplined asset loading, and a stated
query/weight budget for every template and query change.

## When to use

A change touches templates, queries, assets, images or caching — anything that
can alter how many queries a request makes, what it loads, or what it stores.
The theme is the only theme in this repository, so a "small" template change is
still a performance change.

Use this skill to keep an improvement measurable and to avoid an accidental
regression. It is a review-and-verify discipline, not a licence to refactor.

## When not to use

- Security-relevant front-end work (request input, output escaping, endpoints) —
  `wp-security-review`.
- A back-end/plugin query change with no caching or asset impact — judge it in
  the plugin that owns it.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §3.2 (performance contract) and §3.1 (theme
  structure), §0 principles 9 and 10 (language-scoped caches; two places is a bug).
- `docs/frontend.md` — including the **transient invalidation matrix** (post type →
  transient keys → languages cleared) that §3.2 requires you to update.
- `docs/themes/conexao-br-irlanda.md` — the theme's runtime architecture.
- `wp-content/themes/conexao-br-irlanda/inc/cache.php` (homepage/404 transients
  and invalidation) and `wp-content/themes/conexao-br-irlanda/inc/queries.php` (shared query helpers).
- `wp-content/themes/conexao-br-irlanda/inc/assets.php` (enqueue order and
  `conexao_asset_version()`).

## Authoritative sources

| Fact | Read it from |
|---|---|
| The performance contract and theme structure rules | `docs/engineering-standard.md` §3.1/§3.2 |
| The transient invalidation matrix and CSS/JS rules | `docs/frontend.md` |
| Theme runtime architecture and module ownership | `docs/themes/conexao-br-irlanda.md` |
| The language-scoped cache key helper | `wp-content/themes/conexao-br-irlanda/inc/cache.php` |
| The gate that enforces cache scoping | `docs/testing.md` §permanent invariant gates |
| How the current page actually performs | a real request against the local site |

## Preconditions

- The local site is up, so query counts and cache behaviour can be observed
  rather than assumed.
- You have a **before** measurement (queries per request, or page weight) for the
  template or route you are changing.
- You know which transient keys the change affects, so the invalidation matrix
  can be updated in the same change.


## Steps

1. **Own it in the right file.** `wp-content/themes/conexao-br-irlanda/functions.php` is loader-only; logic lives in
   `wp-content/themes/conexao-br-irlanda/inc/*.php`, one concern per file (a file over ~400 lines should be split).
   Language decisions belong to `wp-content/themes/conexao-br-irlanda/inc/i18n/`, SEO output to `wp-content/themes/conexao-br-irlanda/inc/seo/`, bilingual
   REST to `wp-content/themes/conexao-br-irlanda/inc/rest-language.php`. Query helpers belong in `wp-content/themes/conexao-br-irlanda/inc/queries.php` or
   the domain module — not inline in a template.
2. **Count the queries before and after.** A page that runs **3 or more**
   `WP_Query` calls must cache its results in language-scoped transients and
   register invalidation for every post type it renders. Record the before/after
   query count in the report; an improvement with no number is not verified.
3. **Reuse existing cached queries.** The homepage already caches
   `conexao_home_*` and 404 caches `conexao_404_*`. Before adding a new query on
   a cached page, check whether an existing helper already returns the data.
4. **Scope every cache key by language.** A transient or object-cache key derived
   from content **must** go through `conexao_lang_cache_key()` (owned by
   `wp-content/themes/conexao-br-irlanda/inc/i18n/`), and invalidation must clear **every** language
   (`conexao_flush_language_cache()` / `conexao_flush_language_object_cache()`).
   An unscoped `conexao_*` key is a defect that leaks the wrong language.
5. **Update the invalidation matrix.** When a cached query is added, changed or
   removed, update the matrix in `docs/frontend.md` in the same commit. Invalidate
   on `save_post` / `delete_post` / `wp_insert_post` as appropriate — a cache that
   is never invalidated is a correctness bug, not a performance win.
6. **Assets.** Enqueue via `wp_enqueue_script()` / `wp_enqueue_style()` in the
   documented order in `wp-content/themes/conexao-br-irlanda/inc/assets.php`. Cache-bust with
   `conexao_asset_version()` (filemtime) — never a manual `?v=` string. One
   deferred bundle per concern; no new inline `<script>`; no framework, no
   bundler, no transpiler.
7. **Images.** Use a registered image size; set `loading`/`fetchpriority`
   deliberately above the fold. Do not introduce a larger default size to
   "improve" appearance.
8. **CSS and dark mode.** Use the design-system variables in
   `wp-content/themes/conexao-br-irlanda/assets/css/design-system.css`; dark mode extends
   `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css`.
   No page-specific CSS hacks, no new `!important` outside documented shims, and
   the `conexao-` class prefix. One component per file for new components.
9. **Verify routes before and after.** Every touched route is checked over HTTP
   before and after (see `wp-http-acceptance-matrix`): status, content, canonical
   and hreflang must be unchanged unless the change intended otherwise.
10. **Prove the improvement.** For a query or cache change, record the concrete
    before/after numbers (query count, cache hit/miss, response time if measured).
    If a profiler was unavailable, say so and report what *was* measured.

## Guardrails

- **No accidental behaviour change.** A performance change must not alter output,
  routes, language behaviour, canonical/hreflang or the sitemap. If it does, that
  is a behaviour change and needs its own plan and report.
- Never cache a query without a registered invalidation path.
- Never introduce an unscoped cache key; never clear only the current language.
- `tests/` never ships in the theme ZIP — a performance change must not pull test
  files into the artifact.
- No framework, no bundler, no transpiler, no jQuery in new code; pages must work
  without JS.
- No manual asset-version query strings; no page-specific CSS variables.
- Don't "fix" a pre-existing performance problem outside the task scope —
  record it as a follow-up instead.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --php                       # in-process behaviour
./scripts/run-tests.sh --acceptance                # routes before/after
./scripts/lint.sh                                  # PHPCS + PHPStan
./scripts/verify-release.sh                        # proves no test file ships
```

- HTTP rows for every touched route pass, with identical status/canonical/hreflang
  unless intentionally changed.

## Failure handling

- *The cache-scoping gate fails.* An unscoped `conexao_*` cache key was
  introduced, so PT and EN can collide. Route every derived key through
  `conexao_lang_cache_key()` and register an invalidation that clears **all**
  languages. Do not suppress the gate.
- *PT and EN return the same cached content.* A key is not language-scoped, or
  invalidation clears only one language. Verify at runtime in both languages, not
  by reading the code.
- *Query count went up.* Revert the N+1 or the missing cache; record the
  before/after numbers. An unmeasured "optimisation" is not an optimisation.
- *An asset is loaded on every page.* Narrow the enqueue to the templates that
  need it and keep the documented order and versioning.
- *The invalidation matrix is out of date.* Update `docs/frontend.md` in the same
  commit; a stale matrix is how a stale cache survives a rollout.
- *The improvement cannot be measured in this environment.* Report it as
  **not measured** with the reason. Do not claim a gain you did not observe.

## Evidence and reporting

Record: the before/after query counts or page weight, the transient keys added or
changed and the languages invalidated, the acceptance rows for every touched
route, the cache-scoping gate result, and the updated invalidation matrix.
Evidence goes to `docs/evidence/<date>-<stage>/`.

- The before/after query or cache numbers are recorded.
- The transient invalidation matrix in `docs/frontend.md` is current.
- `docs/frontend.md` and `docs/themes/conexao-br-irlanda.md` are updated when
  structure, assets or caching changed.

## Definition of done

- [ ] The change lives in the correct `wp-content/themes/conexao-br-irlanda/inc/`
      module; `wp-content/themes/conexao-br-irlanda/functions.php` is
      loader-only and templates hold presentation only.
- [ ] Before/after query counts (or another real measurement) are recorded.
- [ ] Any page with 3+ `WP_Query` calls caches in language-scoped transients.
- [ ] Every cache key goes through `conexao_lang_cache_key()`; invalidation
      clears all languages and is registered.
- [ ] The invalidation matrix in `docs/frontend.md` was updated in the same commit.
- [ ] Assets are enqueued in the documented order and versioned by
      `conexao_asset_version()`.
- [ ] Images use registered sizes with deliberate loading hints.
- [ ] CSS uses design-system and dark-mode variables; no page-specific hacks.
- [ ] Every touched route was verified over HTTP before and after, with no
      unintended behaviour change.
- [ ] Limitations (e.g. no profiler available) are stated in the report.
