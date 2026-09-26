# Plan — Stage N: Complete the Remaining English Blog Translations

| | |
|---|---|
| **Task title** | Stage N — complete the remaining 34 EN blog translations |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `43a7ed8d5c919259a089a6551906ef25a37b14d3` |
| **Plan status** | approved |

## 1. Scope

Author, link and verify the 34 eligible public Portuguese blog posts that the
permanent `translation_completeness` gate still reports as
`post_type:post:missing_en: 34`. Each becomes one linked EN translation created
through the existing shared rollout engine
(`conexao-translation-rollout`) by the existing stage
(`conexao-en-translation`) run through `scripts/run-en-translation.php`, until
the permanent-gate aggregate is 7/7 with 0 violations.

## 2. Explicit non-goals

- No production write, deploy, upload or activation (production has no CLI and
  is not reachable from here).
- No change to the Flutter/mobile repository — not read, not written.
- No change to `tests/lib/permanent-gates.php`,
  `tests/baseline/permanent-gates.json`, `scripts/verify-permanent-gates.py`,
  `conexao_b2_post_types()`, `conexao_b2_page_allowlist()`, the
  Polylang/cache/redirect policy, the gate thresholds, the CI aggregation or
  the baseline.
- No new EN allowlist, no synthetic EN records, no re-authoring of the 8 posts
  Stage M already translated.
- No new translation engine, no resurrection of the retired
  `conexao-blog-translation` lifecycle.
- No refactor of the shared engine, and no unrelated runtime file changes.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md` (§0, §4.1, §5.2, §6.1, §8.2, §9.2,
§13.2, §14), `docs/routing.md`, `docs/content-model.md`, `docs/testing.md`,
`docs/releases.md`, `docs/plugins/conexao-en-translation.md`,
`docs/plugins/conexao-translation-rollout.md`, `scripts/README.md`,
`plugins.json`, and the Stage M report plus evidence in
`docs/evidence/2026-09-26-stage-m/`.

## 4. Current-state findings


## 5. Proposed approach

**Chosen:** extend the authored Blog translation data only. Add a new
versioned data file `includes/blog-translation-data.php` exposing
`conexao_en_translation_manifest_data_blog_v1()`, and merge it into
`conexao_en_translation_map()` for the `post` stage. The structure therefore
remains exactly:

```
conexao-translation-rollout (shared engine)
    -> conexao-en-translation (stage config + adapter)
        -> includes/blog-translation-data.php (versioned Blog EN data)
            -> scripts/run-en-translation.php
```

Stage-specific data and editorial copy stay separate from generic
orchestration, and no engine or gate code is touched.

**Rejected:** (a) editing the 34 rows into `manifest-data.php` — that file is
the Stage M record and mixing a second stage's 140k-character payload into it
destroys the per-stage data boundary; (b) a per-batch PHP manifest that the
runner selects — that would introduce a second manifest-validation path in the
stage; (c) writing the EN records through `wp_insert_post` from a bespoke
script — that is exactly the second engine this repository forbids.

**Batching:** six batches of 4–6 posts, appended cumulatively to the versioned
data file. Because the engine is idempotent, each apply reports the earlier
batches as `skipped` and only the new batch as `created`, which is itself part
of the idempotence proof. Each batch is dry-run → snapshot → apply → gate, and
the next batch does not start until the previous one is clean.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/blog-translation-data.php` | add | versioned Stage N Blog EN translation data, keyed by PT slug |
| `wp-content/plugins/conexao-en-translation/includes/translation-map.php` | modify | merge the versioned Blog data into the `post` manifest |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modify | load the new data file; bump plugin version |

## 8. Content / data impact

- Records created: **34 EN `post` records**; updated: 0; deleted: 0.
- Portable identity strategy: the **PT post slug** is the manifest key. No local
  post/attachment ID is used as cross-environment identity.
- This writes content, so the six-step content-change contract applies and the
  dry-run output is captured as evidence for every batch.
- PT content is not changed. The engine re-captures a full PT snapshot after
  each apply and reports any difference as `PT drift`, which fails the gate.

## 9. Route / HTTP impact

- No route is added, removed or changed. Each EN post is a new record under the
  existing `/en/blog/<slug>/` route the theme already serves.
- No redirect is added. B1 semantics are unchanged: a real EN record resolves in
  the EN context, canonical is self-referencing, hreflang carries the PT/EN
  pair, and the PT URL is untouched.
- Sitemap coverage: automatic, through the existing theme/Polylang wiring.
- HTTP matrix rows: no new row is added. Post 1 of the Blog set is already
  covered by the existing acceptance matrix; the remaining 33 are verified
  programmatically (link, canonical, hreflang, body language) rather than as 33
  duplicated hand-written rows, which the existing acceptance architecture does
  not require.

## 10. Polylang / English impact

- Post type affected: `post` (B1). Taxonomies: none are assigned on these
  records; the shared county/town terms and the linked `conexao_category`
  counterparts are handled by the existing adapter unchanged.
- PT is immutable in this change: yes.
- B1 for every destination: a real linked EN record, no B2 fallback, no
  allowlist, no `conexao_b2_post_types()` / `conexao_b2_page_allowlist()`
  change.
- Canonical, hreflang and the language switcher behave exactly as for the 8
  posts Stage M already created.
- Cache keys: none added or changed.
- Completeness gate: `post_type:post:missing_en` must reach **0**.

## 11. Security impact

- No capability, nonce or REST surface is added. The runner is a local CLI
  behind the existing `scripts/lib/bootstrap.php` production guard.
- Output escaping is the runner's existing responsibility, unchanged.
- No SQL is written by this change.
- No secret is involved; none is read or written.

## 12. Performance impact

- No query, template or asset changes. 34 more EN posts appear on the EN blog
  archive, which is the intended result of the translation contract.
- No transient or object-cache key is added or changed.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/plugins/conexao-en-translation.md` | the new versioned data file, the six batches, the completed Blog set |
| `docs/testing.md` | only if the `post_type:*:missing_en` remedy text changes |
| `docs/routing.md` | only if B1 blog behaviour is restated differently |
| `docs/content-model.md` | only if the Blog EN record description changes |
| `docs/README.md`, `scripts/README.md` | evidence index / script inventory only if they actually change |
| `docs/evidence/2026-09-26-stage-n/` | the Stage N evidence set and the final report |

## 18. Release implications

- Plugin version bump in `conexao-en-translation.php` only.
- The plugin stays `production: false` and `build: false` in `plugins.json`, so
  the artifact allowlist does not change and **no release is in scope**.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Translating 140k characters produces a PT copy rather than English | medium | deterministic per-record check: EN body must differ from PT and carry no PT stop-words; a dedicated validation script fails the batch |
| An EN slug collides with an existing record | low | the engine's `slug_collision` adapter runs before every create and reports it as a conflict; conflict must be 0 |
| Byline, Instagram handle or external URL is lost in translation | medium | the validation script checks that every Instagram handle and every external URL present in the PT source is present in the EN body |
| A batch writes a PT record | low | the engine re-captures the PT snapshot after apply and reports PT drift; any drift stops the stage |
| Doing too much: refactoring the shared engine | low | the engine diff must be empty; it is listed in §7 |

## 20. Verification gates

1. `python3 scripts/verify-permanent-gates.py` → `Gates: 7 total, 7 passed`,
   `Violations: 0`, `AGGREGATE: PASS`, exit 0.
2. `./scripts/run-tests.sh` → no newly failing suite versus the Stage M baseline.
3. `php scripts/run-en-translation.php --dry-run --only=post` after the final
   apply → `created=0 updated=0 skipped=42 conflicts=0 errors=0 PT-drift=0`.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14, restricted to what this change touches:
content writes are dry-run first, apply is idempotent, the PT-unchanged
assertion is proven, the numeric gate is 0, rollback is documented, docs are
updated in the same change, no gate/baseline/policy/allowlist was weakened, no
production write occurred, and the Flutter/mobile repository is untouched.

_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_


## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody from this stage |
| What is the verification of the production result? | not applicable; no production action is in scope |

## 14. Test plan

- In-process suites: `./scripts/run-tests.sh` before the first Stage N write and
  after completion, comparing the exact failing-suite names before and after.
- Permanent-gate aggregate: `python3 scripts/verify-permanent-gates.py` must be
  7/7 with 0 violations and exit 0.
- Script contract, release integrity, agent governance, registry drift,
  documentation drift and i18n freshness: all re-run unchanged.
- Pass is numeric: `missing EN = 0`, `PT drift = 0`, `conflicts = 0`,
  `errors = 0`, `malformed = 0`, `allowlisted == 1821`, gate 7/7.

## 15. Acceptance matrix plan

No new matrix row. The representative sample already covered by the existing
`en-blog-*` rows is re-run, and the full 34-record set is verified
programmatically for status, canonical, hreflang pair, body language and
content integrity. Expected assertion counts are taken from the executed run.

## 16. Rollback plan

- EN records: `php scripts/run-en-translation.php --remove --apply --only=post`
  removes exactly the EN records this manifest owns (the stage declares
  `allow_remove: true`) and never touches a PT original.
- Authored data: revert the commit through Git.
- Engine/adapter code: Git revert is the primary rollback.
- Pre-change state: the PT snapshot the engine captures per batch, plus the
  fresh baseline inventory archived in the evidence directory.

| `docs/plugins/conexao-en-translation.md` | modify | document the new data file and the completed Blog set |
| `docs/testing.md`, `docs/routing.md`, `docs/content-model.md`, `docs/README.md`, `scripts/README.md` | modify | only where the real change requires it |
| `docs/evidence/2026-09-26-stage-n/*` | add | machine evidence + the final report |

## 7. Files explicitly expected NOT to change

`tests/lib/permanent-gates.php`, `tests/baseline/permanent-gates.json`,
`scripts/verify-permanent-gates.py`, `tests/bootstrap.php`, the theme
`inc/i18n/*` policy files, `wp-content/themes/conexao-br-irlanda/**` other than
documentation, `plugins.json` (the plugin set does not change),
`wp-content/plugins/conexao-translation-rollout/**` (shared engine untouched),
and the retired `conexao-*-translation` rollout plugins.

Verified by command, not assumed, at `43a7ed8` on a clean tree:

- `python3 scripts/verify-permanent-gates.py` → **7 gates, 6 passed, 1 failed;
  85 assertions passed, 1 failed; 34 violations (34 pre-existing, 0 new);
  1821 allowlisted; exit 1.** The single failure is
  `post_type:post:missing_en: 34`.
- The `post` detail block in `gate.json` reports
  `eligible: 42, translated: 8, allowlisted: 0, missing: 34, malformed: 0`.
- A fresh read-only runtime inventory of the live install reproduces exactly
  the same numbers: **eligible PT blog posts 42, already translated 8, missing
  34, malformed relationships 0, allowlisted 0**, totalling
  **143,470 PT characters** of source.
- `post` is a B1 type: it is not in `conexao_b2_post_types()` and none of its
  records is on the B2 page allowlist, so every eligible PT post genuinely owes
  an EN record. The 8 already-translated posts carry a bidirectional Polylang
  pair and must not be re-authored.
- The 34 workset posts carry **no `href` attributes and no internal
  `conexaobr.ie` links at all** — every link in the set is external
  (Instagram, reference URLs, partner sites), so the Blog internal-link
  localisation question does not arise and no EN link may be invented.
