# Add a translation rollout

## Purpose

Add English coverage for a content type through the **shared rollout engine**:
a versioned manifest keyed by stable identifiers, a dry-run preview, a
pre-apply PT snapshot, an idempotent apply, and a numeric completeness gate —
with PT provably untouched and the `/en/` layer resolving per
`docs/routing.md`'s B1/B2 policy.

## When to use

Adding English coverage for a content type, re-running an existing rollout, or
extending the `/en/` layer. This is a **content-writing** change: it creates and
updates real records, so the six-step content-change contract (§5.2) is
mandatory and dry-run is not optional.

## When not to use

- Non-EN content writes (menus, terms, meta, imports) — the same six-step
  contract, but under `wp-content-change`.
- Declaring or changing the content type or its language policy —
  `wp-add-content-type` (this skill consumes the declared policy).
- A rollout engine capability change — extend the shared engine
  (`docs/plugins/conexao-translation-rollout.md` §"Adding the next rollout");
  never fork the lifecycle into a stage plugin.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §5.2 (the six-step content-change contract),
  §6.1 (bilingual non-negotiables), §6.2 (procedure), §6.3 (permanent
  invariant tests) and §0 principles 1, 2, 4, 5, 6, 7.
- `docs/routing.md` §English rollout state — the current B1/B2 policy and the
  taxonomy translated/shared decision.
- `docs/plugins/conexao-translation-rollout.md` — **the shared engine**. Stage
  config + data manifest, no per-stage apply/audit script copies.
- `docs/content-model.md` — identity meta and the shared/taxonomy policy.
- `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php`
  and the engine's admin class.

## Authoritative sources

- `docs/content-model.md` owns the content types, identity meta and the
  taxonomy translated/shared policy; `docs/routing.md` §English rollout state
  owns the current B1/B2 policy and approved translated post types — read them,
  never restate their lists.
- `docs/plugins/conexao-translation-rollout.md` owns the **shared engine**
  contract: stage config + data manifest, the numeric gate, the
  remove/rollback contract, and how to add the next rollout. No per-stage
  apply/audit script copies.
- `plugins.json` owns the plugin lifecycle facts (the engine and the retired
  rollout plugins are `production: false`, `build: false`).
- `tests/baseline/permanent-gates.json` records pre-existing completeness
  findings so a gate result can be classified as pre-existing — it never
  suppresses one.

## Preconditions

- The post type's translation policy is already declared in code through
  Polylang in `wp-content/themes/conexao-br-irlanda/inc/i18n/` (a translated
  type with no EN coverage fails the completeness gate by design).
- The stage's manifest data is authored and versioned, keyed by stable
  identifiers.
- The engine and stage plugin are active on the local Compose site for the
  dry-run/apply cycle.

## Steps

1. **Decide the strategy and record it.** Linked EN records (preferred for
   CPTs/posts/pages) or an authored EN field on the same record (the Lazer card
   description precedent, `_leisure_excerpt_en`). State the choice and its
   rationale in the report; do not mix strategies within one stage.
2. **Never touch PT.** PT URLs, slugs, identity, titles and meta are immutable
   during an EN change. A change that must alter PT content is a different change
   with its own plan. An EN record is a **linked translation of the same
   identity**, never a second identity record — identity meta stays
   language-neutral.
3. **Author the data in a versioned manifest** — one entry per PT record keyed by
   a **stable identifier** plus slug. Never match by local post/attachment ID;
   slug/title matching is only a reported fallback, printed with counts. The
   engine's `validate_manifest()` fails closed on a missing key, a malformed
   row, a language mismatch or a duplicate stable identifier.
4. **Register the translation policy** in code through Polylang in
   `wp-content/themes/conexao-br-irlanda/inc/i18n/` — the translated post type
   and its translated taxonomies. `conexao_category` and `conexao_tag` are
   translated; `conexao_county` and `conexao_town` stay **shared** (one term,
   both languages, identical `?county=`/`?cidade=` filters). Filter slugs are
   language-specific for translated taxonomies only.
5. **Build the stage on the shared engine**, with an admin screen
   (Preview → Apply → Remove), an idempotent apply, a PT-drift guard
   (`assert_no_pt_drift()`) and a `--remove` rollback path. Do not re-implement
   the lifecycle; extend the engine when a capability is missing.
6. **Run the six steps and keep the evidence.**
   - *Inventory* — read-only, machine-readable, with a stable identifier.
   - *Dry-run* — `create[]`, `update[]`, `skip[]` (with a reason), `conflicts[]`;
     zero writes. Identical input ⇒ identical plan.
   - *Snapshot* — the affected PT records captured **before** any write.
   - *Apply* — idempotent; re-running produces zero changes; every write reports
     its match strategy (`stable-id` / `slug` / `title`) with per-strategy counts.
   - *Verify + gate* — a numeric gate stored as `gate.json` under
     `docs/evidence/<date>-<stage>/`.
7. **Wire the theme so EN resolves correctly** — URL resolvers, archive filters,
   breadcrumbs, sitemap, hreflang, language switcher. Decide B1 vs B2 per
   destination and follow `docs/routing.md`: B1 is a 302 to PT, B2 renders the PT
   body under the EN shell with a notice, canonical → PT, and is excluded from the
   sitemap. Any fallback rendering must be explicit, canonical-pointing and
   out of the sitemap.
8. **Scope caches by language.** Every transient/object-cache key derived from
   content goes through `conexao_lang_cache_key()`, and invalidation clears all
   languages. A rollout that changes what a cached page returns must invalidate it.
9. **Gate.** `eligible public PT <type> missing EN = 0`, or an explicit,
   documented allowlist. Every rollout ends with a number; a gate that cannot be
   expressed as a number must be re-framed until it can.
10. **Test and document.** Add HTTP matrix rows (archive, single, filter,
    pagination, canonical, hreflang, sitemap) and, where a permanent invariant
    is introduced (§6.3), an in-process test. Update `docs/routing.md`
    §English rollout state, the plugin/stage doc, `docs/content-model.md` if meta
    changed, and the `AGENTS.md`/registry facts only through the generator.

## Guardrails

- **No direct production writes.** Production is WordPress.com: no SSH, no
  WP-CLI, no filesystem, no database. A rollout is executed by a maintainer
  through the admin screen; the repository ships the plan, not a production
  mutation.
- **PT immutability is absolute.** A PT record whose title, slug, status, date or
  meta differs after the rollout is a failure, not a side effect.
- English is a **layer, never a fork**: one identity, a linked translation.
  Never a second record for the same thing; never a second taxonomy term per
  language for county/town.
- Never match records by local WordPress post/attachment ID. Use a stable
  authored key or the domain UUID (`_leisure_uuid`, event source + source_id +
  export UUID). Slug/title matches are a printed fallback with counts.
- Never hand-write an `apply.php` / `audit.php` copy of the engine lifecycle.
- Never write a fallback that is served as if it were EN: `is_fallback = true`,
  canonical → PT, out of the sitemap, with the notice.
- Locale is read only through `conexao_current_locale()`; no ad-hoc language
  branching elsewhere.
- Never ship an unrunnable rollout: it needs an admin screen (capability + nonce),
  a dry-run preview, and a remove/rollback path.
- Never mutate content outside the stage's declared scope, and never accept a
  production write without an explicit target and confirmation.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --only conexao-translation-rollout   # engine + stage
./scripts/run-tests.sh --acceptance                          # /en/ routes
php scripts/generate-registry-docs.php --check               # registry consistency
```

- The **gate is 0** (or an explicit allowlist) and the number is recorded.
- The **PT-unchanged assertion** passed, listing the fields compared.
- Apply is **idempotent**: a second run reports zero changes.
- HTTP rows for archive, single, filter, canonical, hreflang and sitemap pass,
  with exact assertion counts.
- The dry-run output is saved as evidence; it must show zero writes performed.
- Report the pre-existing failures separately from this rollout's results.

## Failure handling

- **PT drift is non-zero after apply:** stop — restore from the pre-apply
  snapshot, treat it as a defect, and re-run the whole verification; PT
  immutability is absolute, not a threshold.
- **The dry run reports conflicts:** resolve the identity conflict in the
  manifest (duplicate stable key, wrong master, language mismatch) — never
  apply over a conflict and "fix up after".
- **The completeness gate will not reach 0:** either the manifest is missing
  records (author them) or the record is a documented B2/exclusion case
  (`conexao_b2_post_types()`, `conexao_b2_page_allowlist()`, deleted-source
  exclusions) — widen the *documented* exclusion policy deliberately, never
  the gate. A pre-existing finding reproduces on the base commit and is
  recorded as pre-existing, never as fixed by your rollout.
- **A second run of apply is not a no-op:** the engine's idempotence is
  broken — fix the engine before proceeding.
- **Orphaned EN fixtures appear (an EN record with no PT master):** use the
  documented orphan-removal path (`scripts/remove-en-orphan-fixtures.php`,
  dry-run first); a *genuine* orphan is a maintainer decision, never an
  automatic delete.
- **The rollout must run on production:** it is executed by a maintainer
  through the admin screen (Preview → Apply) — the repository ships the plan,
  never a production mutation; see `wp-production-operations`.

## Evidence and reporting

- Under `docs/evidence/<date>-<stage>/`: the inventory, the dry-run plan
  (proving zero writes), the pre-apply PT snapshot, the apply output with
  per-strategy match counts, the idempotence re-run, the PT-unchanged proof
  (fields compared), and `gate.json`.
- The report separates this rollout's results from pre-existing failures,
  states the gate number (0 or the explicit allowlist), and links the engine
  doc and `docs/routing.md` §English rollout state as updated.

## Definition of done

- [ ] The strategy (linked EN record vs authored EN field) is recorded with a
      rationale, and is not mixed within the stage.
- [ ] PT records are provably unchanged (title, slug, status, date, meta).
- [ ] The manifest is versioned, keyed by a stable identifier, and validated
      (fails closed on a duplicate key, missing field or language mismatch).
- [ ] The stage uses the shared engine; no copied lifecycle.
- [ ] Inventory, dry-run, snapshot, apply and verify/gate evidence is stored under
      `docs/evidence/<date>-<stage>/`.
- [ ] Apply is idempotent and reports per-strategy match counts.
- [ ] A remove/rollback path exists and is documented.
- [ ] The taxonomy translated/shared policy is respected; no per-language county
      or town duplicates were created.
- [ ] B1/B2 destinations match `docs/routing.md`; fallbacks are canonical →
      PT, noticed, and out of the sitemap; hreflang only where a real relation exists.
- [ ] Cache keys are language-scoped and invalidation clears all languages.
- [ ] The completeness gate is `0` or an explicit allowlist, with the number
      recorded.
- [ ] Documentation updated; no production write occurred.
