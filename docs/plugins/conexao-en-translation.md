# conexao-en-translation

## Purpose

The Stage M EN translation rollout. It closes the **pre-existing EN translation
completeness debt** that the permanent `translation_completeness` gate reports
for the three B1 content types — `guide`, `page` and `post` — by creating one
linked EN translation per eligible public PT record.

It is a **DATA + CONFIG consumer** of the shared
[`conexao-translation-rollout`](conexao-translation-rollout.md) engine, exactly
like the earlier `job` / `guide` / `blog` / `page` rollouts. It owns no
orchestration, no planning and no gate calculation: the engine implements the
content-change contract (inventory → manifest validation → dry-run plan →
snapshot → apply → verify + numeric gate → remove) and this plugin supplies only
the authored English and the WordPress-bound adapter.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-en-translation/` |
| Authored copy | `includes/manifest-data.php` (Stage M: guide + page + the first 8 Blog rows) and `includes/blog-translation-data.php` (Stage N: the remaining 34 Blog rows), each keyed by PT slug |
| Adapter + config | `includes/stage-fields.php`, `includes/stage-config.php` |
| Manifest shape | `includes/translation-map.php` |
| Runner | `scripts/run-en-translation.php` |
| Admin screen | none (the rollout is operated through the shared engine's screen and the runner script) |
| Frontend effect | **none** — it creates EN content records only; no template, route or runtime hook |
| Safe to deactivate | yes, after the rollout has been applied and verified |

## Why this stage exists

Stage L installed a permanent, fail-closed completeness gate
(`test-translation-completeness.php`) that measures
`eligible public PT <type> missing EN = 0`. Against the dataset it correctly
reported **72 missing EN translations** as pre-existing debt:

| Post type | Eligible PT | Translated | Missing EN | Policy |
|---|---|---|---|---|
| `guide` | 53 | 52 | 1 | B1 |
| `page` | 44 | 4 | 29 | B1 (11 are B2-allowlisted by policy) |
| `post` | 42 | 0 | 42 | B1 |

The B2 directory types (`event`, `leisure`, `sponsor`, `course_provider`,
`job`) are **not** in this stage: they are already covered by the documented B2
policy (`conexao_b2_post_types()`), which the gate reports explicitly. Adding
them here would create a second, competing policy — exactly what
`docs/routing.md` forbids.

## The rules this stage must not break

- **Portuguese is immutable.** A PT record is only ever read. The engine
  re-captures a full PT snapshot after an apply and reports any difference as
  `PT drift`, which fails the numeric gate.
- **English is a layer, never a fork.** Every EN record is created as a
  *linked translation* of the PT master and the pair is verified in **both**
  directions (`pll_get_post(pt,'en') === en` and `pll_get_post(en,'pt') === pt`).
- **The PT slug is the only portable identity.** The manifest is keyed by PT
  slug; local post IDs are never used as cross-environment identity.
- **The EN body is authored English**, never a copy of the PT body.
- **Shared proper-name terms stay shared.** `conexao_county` and `conexao_town`
  are copied as the *same physical term*; `conexao_category` is linked to its EN
  counterpart through Polylang, never duplicated.
- **No second engine.** Everything lifecycle-related is the shared engine's.

## The numeric gate

```
eligible public PT <type> = N
with EN                  = N
missing EN               = 0     <-- the invariant
conflicts                = 0
PT drift                 = 0
GATE                     = PASS
```

A conflict is a *hard stop*, never a silent overwrite: an EN slug already used
by an unlinked record, or an existing EN record whose pair link is broken, is
reported and left alone for a maintainer to resolve.

## Stage N — the remaining 34 Blog translations

Stage M closed the `post` debt only partially (8 of 42). Stage N authored the
remaining **34** PT blog posts in a separate, versioned data file
(`includes/blog-translation-data.php`, data version `blog-v1`) and merged it
into the `post` manifest in `includes/translation-map.php`. The merge is a
**union keyed by PT slug** and fails closed on a duplicate slug, so no PT slug
can ever reach the engine twice.

`conexao_en_translation_blog_batches()` records the batch each row was applied
in — six batches of 4–6 posts, applied cumulatively, dry-run → snapshot →
`--apply` → gate after each one. The manifest is cumulative precisely so that a
re-run reports the earlier batches as `skipped`: the idempotence proof and the
batch record are the same artefact.

The shared engine, the stage adapter and the runner were **not** changed. The
only non-data edits are the plugin header (version 1.1.0 plus the new file in
its `require_once` list) and the merge in `translation-map.php`.

### Editorial rules applied to every row

- the third-party byline is preserved; a **name** is a proper noun and is never
  translated, while the **role** ("Terapeuta Sistônica" → "Systemic Therapist")
  is;
- every Instagram handle and every external URL is preserved byte-for-byte,
  including the advertising parameters on the support-organisation links;
- headings, lists, emphasis and block structure mirror the PT source;
- a preserved Portuguese **bibliography** is cited, not translated — a citation
  is not prose;
- no internal `conexaobr.ie` link is invented. None of the 34 PT sources contains
  a single `href`, so there was no Blog internal link to localise and none was
  fabricated.

### Result

`post_type:post:missing_en` went **34 → 0**, and the permanent-gate aggregate is
**7/7 passing, 0 violations**. The 42 eligible public PT blog posts now each have
exactly one linked EN translation. No allowlist was added, and
`conexao_b2_post_types()` / `conexao_b2_page_allowlist()` are untouched.

### The EN blog archive is still B2 — a policy fact, not debt

`/en/blog/` continues to serve the Portuguese posts with the B2 fallback notice.
That is **not** a translation gap, and this stage must not "fix" it without
changing the allowlist count:

- the Blog **archive** is the static posts page (`page_for_posts`, slug `blog`),
  and `blog` is on `conexao_b2_page_allowlist()` by policy;
- the documented retirement condition (`inc/b2-fallback.php`, Stage 5 comment) is
  *"a real, published EN posts page"* — a linked EN translation of the `blog`
  **page record**;
- creating that page record would move `blog` out of the allowlist and change the
  aggregate allowlist count from **1821 to 1820**, which this stage's acceptance
  criteria forbid.

The correct action is therefore to leave the policy alone and report the finding.
The B1 obligation this stage *does* own — each translated post resolving in the EN
context — is met: all 42 EN posts return 200 under `/en/…`, self-canonical, with
a correct PT/en hreflang pair and **no** B2 notice (see the HTTP evidence).

## Rollback

`allow_remove: true`, so the rollback is a first-class operation:

```bash
php scripts/run-en-translation.php --remove --apply --only=guide
```

It deletes only the EN records this manifest owns and re-asserts that the PT
originals are unchanged. It can never touch a PT record.

## Verification

```bash
php scripts/run-en-translation.php --dry-run          # plan, zero writes
php wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php
```

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | `conexao-translation-rollout` |
| **Version** | 1.1.0 (authoritative source: `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_
