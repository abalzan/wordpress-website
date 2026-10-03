# Plan — Stage O: Enable the Real English Blog Archive

| | |
|---|---|
| **Task title** | Stage O — transition `/en/blog/` from the B2 fallback to a real English archive |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `a188fb3ff60488c872635dc65dc5dc72888cfb5b` |
| **Plan status** | approved |

## 1. Scope

Resolve the single Stage N finding. The English Blog archive at `/en/blog/`
exists only through the B2 fallback path (Portuguese posts under the English
URL plus the `language-fallback-notice`), because the Portuguese `blog`
posts-page record (ID 10, `page_for_posts`) has no linked English page record.
Stage O creates that one linked, published English translation of the PT
`blog` **page** — and nothing else — so the existing route renders the real
English archive from the 42 already-translated EN `post` records, the B2
allowlist loses its `blog` record, and the aggregate permanent-gate allowlist
count transitions **1821 → 1820** as a *consequence* of the data change, not
as a gate edit.

## 2. Explicit non-goals

- **Do not** retranslate, re-edit or re-slug any of the 42 EN `post` records.
  `post_type:post:missing_en` is already `0` and stays `0`.
- **Do not** touch the two pre-existing drifted page slugs (`jobs-2`,
  `newsletter`). The shared `page` stage plans `would-update` for both
  (verified: `created=0 updated=2 skipped=27`). Repairing them is a different
  change; Stage O must not smuggle it in.
- **Do not** modify the shared engine (`conexao-translation-rollout/**`), the
  permanent-gate implementation, the gate baseline, `conexao_b2_post_types()`,
  `conexao_b2_page_allowlist()`, the cache policy, the redirect policy, the

| Fact | Value | How established |
|---|---|---|
| PT `blog` page | ID `10`, slug `blog`, `publish`, lang `pt` | runtime query |
| `page_for_posts` | `10` (equals the PT `blog` page) | `get_option()` |
| `show_on_front` | `page` | `get_option()` |
| PT `blog` body | one paragraph, 110 chars | runtime query |
| PT `blog` meta description | present (Portuguese) | runtime query |
| PT `blog` EN translation | **none** (`pll_get_post(10,'en') === 0`) | runtime query |
| `conexao_b2_page_allowlist()` | `dublin, cork, galway, limerick, kildare, meath, wicklow, waterford, laois, irlanda, blog` | runtime call |
| `conexao_b2_post_types()` | `event, leisure, sponsor, course_provider, job` (**`post` not included**) | runtime call |
| `conexao_is_b2_page(10)` | `true` | runtime call |
| Public blog posts | PT `42`, EN `42`, missing `0`, one-way `0`, orphans `0` | runtime query |
| `posts_per_page` | `10` → 5 archive pages per language | runtime query |
| Permanent-gate aggregate | **7 total, 7 passed, 0 failed, 0 blocked, 86/0 assertions, 0 violations, exit 0, 1821 allowlisted** | `verify-permanent-gates.py` |
| Pre-Stage-O test baseline | 13 failing suites, incl. `test-blog-en-translation.php` (20 passed / 8 failed) | `./scripts/run-tests.sh` |
| `en-page` stage dry-run | `created=0 updated=2 skipped=27 conflicts=0`, gate PASS | `run-en-translation.php --dry-run --only=page` |
| `en-page` manifest | 29 rows, **no `blog` row** | runtime call |
| `/en/blog/` today | 200, `<html lang="en-US">`, canonical → `/blog/`, B2 notice **present**, hreflang only `pt-BR` + `x-default` | `curl` |
| `/en/blog/page/2/` today | 200, same B2 shape | `curl` |
| `/en/blog/page/99/` today | 404 | `curl` |
| `/blog/` today | 200, `pt-BR`, self-canonical | `curl` |

The two runtime facts that shape the design:

1. **The `page` stage is not the right vehicle.** It already plans two
   unrelated `would-update` repairs (`jobs-2`, `newsletter`). Adding the
   `blog` row to it and applying would perform those two writes too. Stage O
   therefore registers its **own stage** in the same plugin, reusing the same
   shared engine and the same adapter, with a manifest containing exactly one
   record. This is not a second engine and not a second runner: it is one more
   stage declaration in `conexao-en-translation`, run by the same
   `scripts/run-en-translation.php`.
2. **A shared slug needs an explicit WordPress hook.** Page slugs are unique
   per tree (`wp_unique_post_slug()`), so a naive `wp_insert_post()` with
   `post_name = 'blog'` silently becomes `blog-2`, which would break the route
   `/en/blog/`. The retired `conexao-blog-translation` plugin solved this with
   a narrow `wp_unique_post_slug` filter. Stage O reintroduces the *minimum*
   equivalent inside the `conexao-en-translation` adapter, scoped to the
   single `blog` record. (The `newsletter` page in the existing manifest is
   already drifted to `newsletter-2` in the live data — evidence that this
   problem is real and is exactly why Stage O must not touch that row.)

  REST language policy, or any theme runtime file.
- **Do not** edit historical stage reports. Only living documents change.
- **Do not** touch the Flutter/mobile repository (out of scope, frozen).
- **Do not** write to production in any form.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md` (§0 change-safety, §4.1 shared
engine, §5.2 content-change contract, §6.1 EN rules, §6.3 permanent gates),

## 5. Proposed approach

### 5.1 Chosen: one new versioned data file + one new stage, same engine

Add `wp-content/plugins/conexao-en-translation/includes/blog-page-data.php`
holding `conexao_en_translation_blog_page_data()` — a **versioned, single-record
data structure** (`blog-page-v1`) for the Blog **posts page**, deliberately
kept out of both `manifest-data.php` (Stage M: guide/page) and
`blog-translation-data.php` (Stage N: the 42 `post` rows). The 42 post rows
are never mixed into it.

Add a stage `en-blog-page` in `conexao-en-translation` that:
- uses the **existing** `Conexao_Translation_Rollout_Engine` (no second engine);
- uses the **existing** WordPress-bound adapter primitives
  (`conexao_en_translation_snapshot`, `conexao_en_translation_pair_ok`,
  `conexao_en_translation_copy_fields`) plus a narrow shared-slug filter for
  the single `blog` record;
- declares `manifest_callback`, `snapshot_callback`, `build_en_args_callback`,
  `copy_fields_callback` and `run_callback` exactly as the other stages do.

`scripts/run-en-translation.php` gains the ability to select that stage
(`--only=blog-page`) through the **existing** stage registry, so there is still
exactly one runner.

### 5.2 Options rejected

| Option | Rejected because |
|---|---|
| Add the `blog` row to the existing `page` manifest and apply `--only=page` | would also write the two unrelated slug repairs (`jobs-2`, `newsletter`) — out of scope, and it would change `/en/newsletter-2/` → `/en/newsletter/`, a route change this stage does not own. |
| Write bespoke `wp_insert_post()` logic in a new script | duplicates the content-change contract (dry-run/snapshot/gate/rollback) that §4.1/§5.2 require to exist exactly once, in the shared engine. |
| Resurrect `conexao-blog-translation` (retired) | it is retired, not in any release ZIP, and its lifecycle would become a second orchestration path. |
| Rename the EN page to `blog-2` and redirect | `/en/blog/` must be the real archive URL; a redirect contradicts the routing contract in `docs/routing.md`. |
| Remove `blog` from `conexao_b2_page_allowlist()` by hand | the count drops **automatically** once the EN link exists (the gate stops counting it as allowlisted and counts it as translated). Editing the theme allowlist would be an unnecessary runtime edit AND would make `test-stage32-bilingual.php`'s exact-allowlist assertion wrong. **The allowlist function is not edited.** |

### 5.3 The allowlist transition, precisely

`test-translation-completeness.php` counts a PT record as *allowlisted* only
when it has **no** EN translation **and** its slug is on the B2 page allowlist
(lines 169–197). Creating the EN `blog` page therefore moves that one record
from the `allowlisted` bucket to the `translated` bucket:

```
before:  page/blog → allowlisted  (aggregate 1821)
after:   page/blog → translated   (aggregate 1820)
```

`conexao_b2_page_allowlist()` keeps the `blog` string (it is the *policy

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/blog-page-data.php` | **new** | the versioned `blog-page-v1` data: EN slug/title/excerpt/body/meta description for the posts page |
| `wp-content/plugins/conexao-en-translation/includes/stage-config.php` | edit | register the `en-blog-page` stage + its adapter (incl. the narrow shared-slug filter) and expose it to the runner |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | edit | `require_once` the new data file; version `1.1.0` → `1.2.0`; header description |
| `scripts/run-en-translation.php` | edit | let `--only` select the new stage from the registered-stage list (no new runner) |
| `scripts/README.md` | edit | catalogue row for `run-en-translation.php` gains the `blog-page` scope |
| `tests/acceptance/matrices/routing.json` | edit | Phase 12: replace the stale `en-b2-fallback-en-blog` row with the real-EN-archive row |
| `docs/routing.md` | edit | `/en/blog/` end state: real EN archive, not B2 |
| `docs/content-model.md` | edit | the Blog page record's EN counterpart; the 1821 → 1820 allowlist transition |
| `docs/plugins/conexao-en-translation.md` | edit | the Stage O stage, the data file, the result, the allowlist transition |
| `docs/testing.md` | edit | the updated acceptance row and the Stage O gate evidence pointer |
| `docs/evidence/README.md` | edit | index the Stage O evidence directory |
| `docs/evidence/2026-09-26-stage-o/**` | **new** | plan, baseline, snapshot, dry-run, apply, proofs, report |
| generated registry regions (`docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md`, `docs/plugins/conexao-en-translation.md`) | regenerate | `php scripts/generate-registry-docs.php --write` after the version bump |

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `wp-content/plugins/conexao-translation-rollout/**` | the shared engine is reused, never modified (Phase 2/22) |
| `tests/lib/permanent-gates.php`, `tests/baseline/permanent-gates.json` | the gate contract and its reporting-only baseline |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | the completeness gate itself |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` (`conexao_b2_page_allowlist()`, `conexao_b2_post_types()`) | B2 policy; the count transition is a consequence of the data |
| `wp-content/themes/conexao-br-irlanda/inc/b2-fallback.php` | the B2 query layer self-retires via the existing `pll_get_post($posts_page_id,'en')` guard |
| any theme template (`home.php`, `inc/seo/*`, `inc/i18n/hreflang.php`) | Phase 9: prefer data/config resolution over runtime-code changes |
| `wp-content/plugins/conexao-en-translation/includes/blog-translation-data.php` | the Stage N 42-post dataset |
| `wp-content/plugins/conexao-en-translation/includes/manifest-data.php` | the Stage M guide/page dataset |
| `wp-content/plugins/conexao-blog-translation/**` | retired |
| `plugins.json` | the plugin set, order and flags do not change |
| every `docs/evidence/2026-09-26-stage-[a-n]/**` | historical reports are immutable |

## 8. Content / data impact

- Records created: **1** (the linked EN `blog` page). Updated: **0**.
  Deleted: **0**. PT records modified: **0**.
- Portable identity: the **PT page slug `blog`** (never a local post ID).
- This writes content, so the six-step contract applies: inventory → manifest
  validation → dry-run plan → snapshot → idempotent apply → verify + gate.
- PT immutable: **yes**, enforced by the engine's `assert_no_pt_drift()` over
  the existing `conexao_en_translation_snapshot()` (title, content, excerpt,
  slug, status, date, author, menu order, thumbnail, 4 taxonomies, Polylang
  language, meta description). The pre-apply PT snapshot is also captured
  independently as immutable evidence.
- EN body: authored English (`Blog` archive intro), **not** a copy of the PT
  body. The PT body is one short paragraph; the EN body is a short English
  archive intro in the same block style.
- EN meta description: authored English, no untranslated Portuguese.

definition*, and the theme's own comment says the entry is retained and becomes
inert), while `conexao_should_render_b2_fallback(10)` — the *runtime
decision* — already returns `false` as soon as a published linked EN
translation exists (`inc/i18n/fallback.php:402-406`). **Phase 8's "blog is no
longer in the B2 page allowlist" is therefore satisfied at the level the
aggregate gate measures: the record is no longer an allowlisted B2 record.**
The theme file is not edited (Phase 22 forbids it).

`docs/routing.md`, `docs/content-model.md`, `docs/testing.md`,
`docs/releases.md`, `docs/templates/plan.md`, `docs/templates/report.md`,
`plugins.json`, `scripts/README.md`, `scripts/run-tests.sh`,
`scripts/verify-permanent-gates.py`, `tests/lib/permanent-gates.php`,

## 9. Route / HTTP impact

- **No route is added or removed.** `/en/blog/` already exists; only its data
  source changes from the B2 PT-scoped query to the real EN posts page.
- Redirects: **none added**. `/en/empregos/` 302 and every other redirect are
  untouched. `/blog/` does not change.
- The B2 posts-page query (`conexao_b2_posts_page_pre_query`) self-retires via
  its own existing guard: `conexao_b2_posts_page_is_en_request()` returns
  `false` as soon as a published linked EN posts page exists.
- Sitemap: unchanged — the posts page is not a sitemap entry.
- Matrix row: `en-b2-fallback-en-blog` is **replaced** (not duplicated) by
  `en-archive-en-blog` (§15).

## 10. Polylang / English impact

- Post type affected: `page` (one record: the Blog posts page). No taxonomy is
  touched — the PT `blog` page has zero terms in all four `conexao_*`
  taxonomies (verified), and the adapter copies nothing for it.
- Polylang: one bidirectional pair, `pt ↔ en`, EN assigned `en`, `publish`.
- B1/B2 classification of `/en/blog/`: **B2 → B1** (a real EN archive; no
  fallback, no notice).
- Canonical: `/en/blog/` becomes **self-canonical** (it is the EN page's own
  permalink once `conexao_should_render_b2_fallback()` is false and
  `conexao_seo_canonical_url()` resolves the queried EN posts page).
- hreflang: EN → `/en/blog/`, PT/PT-BR → `/blog/`, `x-default` → `/blog/`
  (unchanged site policy: `x-default` always points at the default language).
- Language-scoped cache keys: **none added or changed**. No new transient, no
  new cache group; the B2 path simply stops being taken.
- Completeness gate that must reach `0`: `post_type:post:missing_en` is
  already `0` and must stay `0`; `post_type:page:missing_en` must stay `0`
  (it passes today only because `blog` is allowlisted — after the change it
  passes because `blog` is genuinely **translated**).

## 11. Security impact

- Capabilities checked: none — the runner is CLI-only and local, exactly like
  every other `conexao-en-translation` run.
- Nonces: none added or changed. Sanitisation/escaping: the data file is
  returned verbatim; the adapter uses `wp_insert_post()` with
  `$wp_error = true` and the standard `*_post()` APIs. SQL: no raw SQL.
- REST surface: **unchanged**.
- Secrets: none.

## 12. Performance impact

- Queries: **none added** on the request path. The B2 `posts_pre_query`

## 14. Test plan

- In-process: `theme/conexao-br-irlanda/test-blog-en-translation.php` must go
  from **8 failed** to **0 failed** on its posts-page assertions (its 2
  category-term assertions are a *separate* pre-existing data issue and are
  out of scope — they are counted, not fixed).
- No new suite is required: the existing Blog suite already asserts the
  posts-page contract (`en_id > 0`, bidirectional, `publish`, `en`,
  `/en/blog/`, not B2). If a genuinely new invariant needs a home, it goes
  into that suite, never into a new ad-hoc file.
- Acceptance: `tests/acceptance/matrices/routing.json` — replace
  `en-b2-fallback-en-blog` with `en-archive-en-blog` (6 assertions) and add the
  minimal pagination rows §15 lists.
- Script contract: `tests/scripts/verify-script-conventions.py` must stay green
  after the `run-en-translation.php` + `scripts/README.md` edits.
- Registry drift: `php scripts/generate-registry-docs.php --check` must report
  no drift after the version bump + regeneration.
- Pass, numerically: permanent gates **7/7, 0 violations, exit 0**;
  `post_type:post:missing_en = 0`; aggregate allowlisted **exactly 1820**;
  `PT drift = 0`; idempotent rerun `created=0 updated=0 conflicts=0 errors=0`;
  `/en/blog/` and every valid EN pagination page HTTP 200; first invalid page
  404.

## 15. Acceptance matrix plan

Fixed schema `{id,url,expect_status,expect_contains,expect_absent,note}`.

Replace (same route, no duplicate row):

| id | url | status | contains | absent | note |
|---|---|---|---|---|---|
| `en-archive-en-blog` | `/en/blog/` | 200 | `<html lang="en-US">`, `rel="canonical" href="`, `/en/blog/`, `hreflang="en"`, `hreflang="pt-BR"`, `hreflang="x-default"` | `language-fallback-notice` | Stage O: `/en/blog/` is the real English archive — English shell, no B2 notice, self-canonical, full hreflang set. |

Add (minimal, only the coverage the matrix conventions already use for
archives — one valid paginated page and the first invalid one):

| id | url | status | contains | absent | note |
|---|---|---|---|---|---|
| `en-archive-en-blog-page-2` | `/en/blog/page/2/` | 200 | `<html lang="en-US">` | `language-fallback-notice` | EN Blog pagination page 2 serves the English archive. |
| `en-archive-en-blog-page-99` | `/en/blog/page/99/` | 404 | — | `language-fallback-notice` | The first invalid EN Blog pagination page is a 404, not a silent empty archive. |

The number of valid pages is **not** hard-coded in the matrix: it is derived
from the runtime (`posts_per_page = 10`, 42 EN posts → 5 pages) and proven
exhaustively in the evidence by a scripted sweep of pages 1..N plus N+1.

## 16. Rollback plan

Order:

1. `php scripts/run-en-translation.php --remove --apply --only=blog-page`
   (engine `allow_remove: true`; deletes only the EN record this manifest
   owns, never a PT original).
2. `git revert <stage-o-commit>` for the data/adapter/matrix/docs changes.
3. Re-run `python3 scripts/verify-permanent-gates.py` → expect the aggregate
   allowlist count back at **1821** and 7/7 green (the pre-change state).

Pre-change state captured first: `pt-snapshot-pre-apply.json`,
`baseline-inventory.json`, `test-summary-before.txt`, and the **SHA-256 of the
PT `blog` record's 12 snapshot fields** — the PT record itself is never
modified, so a PT rollback is not required; the EN record is a pure addition.

Rollback does **not** cover: nothing else. No PT record, no taxonomy, no
option, no rewrite-rule flush and no theme file is written by this stage.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/routing.md` | `/en/blog/` is the real EN archive (Stage O), not a B2 fallback; the `blog` allowlist entry is now inert; the 1821 → 1820 transition |
| `docs/content-model.md` | the Blog posts page's EN counterpart; Blog `post` records stay B1 and un-allowlisted |
| `docs/plugins/conexao-en-translation.md` | the Stage O data file, the `en-blog-page` stage, the result, the allowlist transition |
| `docs/testing.md` | the updated acceptance row id and the Stage O gate evidence pointer |
| `docs/evidence/README.md` | index the Stage O evidence directory |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | generated registry regions only (version `1.2.0`) |

Historical stage reports are **not** edited.

## 18. Release implications

- `dist/release.json` is unaffected: no production plugin changed
  (`conexao-en-translation` is `build: false`, `production: false`), no theme
  file changed, no manifest-declared build input changed.
- `scripts/verify-release.sh` / `tests/scripts/verify-release-integrity.py`
  must stay green; `docs/releases.md` needs no new entry because nothing
  ships.

## 19. Roll-forward (what "done" means)

1. One and only one published, linked EN `blog` page; PT ↔ EN bidirectional.
2. `blog` no longer an allowlisted B2 record; aggregate allowlist exactly 1820.
3. `post_type:post:missing_en = 0`; no `post:<slug>` allowlist entry anywhere.
4. Permanent aggregate 7/7, 0 violations, exit 0.
5. `/en/blog/` 200, English, no B2 notice, self-canonical, correct hreflang.
6. All valid EN pagination pages 200 with English posts; first invalid 404.
7. PT drift `0`; idempotent rerun creates 0 records.
8. All negative proofs fail closed; every temporary change reverted.
9. `Production writes: 0`. Flutter/mobile untouched.
10. Working tree clean, focused single commit.

---

_Plan written before any data write. Every number in §4 was produced by an
executed command at the starting SHA, not by prose._

_Last verified: 2026-09-26 by Stage O — Enable the Real English Blog Archive_

  short-circuit stops firing, so `/en/blog/` performs *one fewer* extra
  `WP_Query` per request than today.
- Caching: no transient key added/changed; no invalidation change.
- Assets/images: none.
- Measurable improvement: the `/en/blog/` response no longer runs the B2
  pre-query; no other measurable performance delta is claimed.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody in this stage — `conexao-en-translation` is `production: false` and is never activated on production |
| What is the verification of the production result? | n/a — all verification is against the local Docker install |

`Production writes: 0`.

`tests/baseline/permanent-gates.json`,
`wp-content/plugins/conexao-translation-rollout/`,
`wp-content/plugins/conexao-en-translation/`,
`docs/plugins/conexao-en-translation.md`, and the whole Stage N evidence set
under `docs/evidence/2026-09-26-stage-n/`.

## 4. Current-state findings (verified, not assumed)

All numbers below come from `docs/evidence/2026-09-26-stage-o/baseline-inventory.json`
and `docs/evidence/2026-09-26-stage-o/test-summary-before.txt`, produced by
executed commands at SHA `a188fb3`.
