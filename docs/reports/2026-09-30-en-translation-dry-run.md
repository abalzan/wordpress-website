# Report — EN Translation Rollout, Phase 2: Dry-Run + Snapshot (read-only)

> **Phase 2 only. Nothing was created, linked, updated, applied or deployed.**
> Every production request this task issued was a **`GET`**. The rollout stops
> before the apply boundary; apply, verify, idempotence and the post-apply gate
> are **Phase 3**.
>
> **The dry-run is complete, deterministic, fully classified and internally
> safe, and the snapshot reconciles to it exactly. But it carries two unresolved
> findings, so the phase is BLOCKED, not ready for apply.**

| | |
|---|---|
| **Stage / task name** | EN Translation Rollout — Phase 2: Dry-Run + Snapshot |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **HEAD** | `63e1a389e75848335f15ad66a0e0f3e803b24d14` (unchanged) |
| **Working tree at finish** | only this report, `docs/evidence/2026-09-30-en-translation-dry-run/` and one line in `docs/reports/README.md`. No `wp-content/`, `scripts/` or `tests/` change. |
| **Production writes** | **0** — GET only |
| **Status** | **BLOCKED — EN DRY-RUN NOT READY FOR APPLY** |

---

## 1. What was done

1. **Verified the Phase 1 baseline** (§1): re-hashed the committed manifest
   (`7d7b066f…`), confirmed the PT identity digest (`cfe4b7a6…`), and confirmed
   `git diff` over `wp-content/`, `scripts/` and `tests/` since the Phase 1
   commit is **empty** — the engine and stage definitions are the same code the
   inventory was built from.
2. **Re-read the current shared translation engine at HEAD** (§2): the
   `conexao-translation-rollout` engine, the `conexao-en-translation` stage
   configs, adapters, manifest loader, dry-run, snapshot, verification,
   idempotence and gate. **No engine or stage file was altered.**
3. **Validated the B1/B2 classification** (§3) against HEAD, re-deriving the
   seven stage ids from `conexao_en_translation_stage_ids()`. It matches the
   Phase 1 manifest exactly.
4. **Revalidated production read-only** (§4) with a fresh, uncached read.
5. **Executed the canonical dry-run** (§5) by driving the repository's **own
   unmodified engine**.
6. **Captured the read-only snapshot** (§14) and verified it (§15).
7. **Repeated the dry-run and compared the actual outputs** (§16).

**The plan is the repository's own engine's output.** `en-dry-run.py` loads
`Conexao_Translation_Rollout_Engine` **unmodified** (sha256
`baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`) and calls
its public static `validate_manifest()`, `build_plan()` and `calculate_gate()`.
`Engine::run()` is **never** called, so no apply, remove or writer is reachable.
This is not a second translation lifecycle: the planning, the counting and the
gate are the engine's code; the tool only supplies the read-only state a
production run's adapter would have observed.

## 2. Baseline (live, GET only)

| Measure | Phase 1 | Phase 2 re-read | Equal |
|---|---|---|---|
| PT translated-CPT scope | 2,175 | **2,175** | ✅ |
| PT total scope (incl. `post` 37 + `page` 43) | 2,255 | **2,255** | ✅ |
| EN records, all 8 post types | 0 | **0** | ✅ |
| EN translation links | 0 | **0** | ✅ |
| PT identity digest | `cfe4b7a670266da3…` | `cfe4b7a670266da3…` | ✅ |
| Phase 1 manifest digest | `7d7b066f0eb5aaa8…` | re-hashed, identical | ✅ |
| Polylang | `pt` default, 2 languages, EN assigned 0 | unchanged | ✅ |

The Phase 1 manifest was **not** regenerated and **not** refreshed. The Phase 2
tool re-reads the committed repository manifests from
`wp-content/plugins/conexao-en-translation` by executing the plugin's own data
files in PHP; the result is **byte-identical** to the Phase 1 dump for
`b1_types`, `blog_page`, `jobs_page`, `guide_terms`, `leisure_desc` and
`course_desc` (the B2 files differ only in that the engine-facing manifest adds
the `en_slug` identity key the engine requires; **0 content differences**).

## 3. The dry-run

### 3.1 Per stage — the engine's own plan

| Stage | B1/B2 | Manifest rows | create | update | skip | conflicts | eligible PT | with EN |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| `en-guide` | B1 | 52 | 49 | 0 | 3 | 0 | 49 | 0 |
| `en-page` | B1 | 31 | 31 | 0 | 0 | 0 | 31 | 0 |
| `en-post` | B1 | 42 | 36 | 0 | 6 | 0 | 36 | 0 |
| `en-blog-page` | B1 | 1 | 1 | 0 | 0 | 0 | 1 | 0 |
| `en-jobs-page` | B1 | 1 | 1 | 0 | 0 | 0 | 1 | 0 |
| `en-leisure-description` | B2 | 289 | 289 | 0 | 0 | 0 | 289 | 0 |
| `en-course-provider-description` | B2 | 10 | 10 | 0 | 0 | 0 | 10 | 0 |
| **Total** | | **426** | **417** | **0** | **9** | **0** | **417** | **0** |

Every per-stage gate reads **FAIL**, and that is the correct pre-apply reading:
`with_en = 0` everywhere, so `missing_en` equals the eligible count. The gate is
the **post-apply target**, not a dry-run verdict.

### 3.2 B1 totals

| Metric | Count |
|---|---:|
| EN record creates | **118** (49 guides + 31 pages + 36 posts + 1 blog page + 1 jobs page) |
| Translation links to be created | **118** |
| Updates / repairs | **0** |
| Existing verified matches | **0** |
| Conflicts | **0** |
| Invalid records | **0** |
| **Missing PT source records** | **9** |
| Taxonomy term creates (`conexao_category`) | **13** |

### 3.3 B2 totals

| Metric | Count |
|---|---:|
| Field-level updates (`_leisure_excerpt_en` 289 + `_provider_excerpt_en` 10) | **299** |
| Already-correct fields | **0** |
| Missing source fields | **0** |
| Conflicts (PT source drifted from the authored English) | **0** |
| **EN records created by B2** | **0** |

B2 is proven field-only: no B2 row produces a post, a slug, a term or a pair,
and every B2 write target in the snapshot is an EN-namespaced `_meta` key.

### 3.4 Taxonomy

| | `conexao_category` | `conexao_tag` | `conexao_county` | `conexao_town` |
|---|---:|---:|---:|---:|
| PT terms | 58 | 0 | 0 | 0 |
| EN terms | 0 | 0 | — | — |
| Planned creates | **13** | 0 | **0** | **0** |
| Conflicts / duplicate targets | 0 | 0 | 0 | 0 |

`conexao_county` (26) and `conexao_town` (251) remain **shared**: 0 carry a
language, 0 have a `-en`/`-pt` suffixed duplicate, and neither is touched.

## 4. Specific findings

These are **not** smoothed into the aggregate totals.

### 4.1 Nine authored rows have no PT source — `MISSING_SOURCE` (3 guides, 6 posts)

| Stage | Authored PT slug | Authored EN slug |
|---|---|---|
| `en-guide` | `outono-irlanda-alimentacao-bem-estar` | `autumn-in-ireland-eating-and-wellbeing` |
| `en-guide` | `carteira-de-motorista-2` | `driving-licence-in-ireland-how-to-exchange-your-cnh` |
| `en-guide` | `learner-permit-theory-test-irlanda-cnh-brasileira` | `brazilian-driving-licence-in-ireland-theory-test-and-learner-permit` |
| `en-post` | `auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | `health-and-wellbeing-support-in-ireland` |
| `en-post` | `auxilios-para-familias-atipicas-na-irlanda` | `support-for-extraordinary-families-in-ireland` |
| `en-post` | `cursos-e-apoio-para-empreendedores` | `courses-and-support-for-entrepreneurs` |
| `en-post` | `dica-de-saude-para-quem-viaja` | `a-health-tip-for-travellers` |
| `en-post` | `guia-para-quem-esta-com-dificuldades-financeiras` | `guide-for-those-facing-financial-difficulties` |
| `en-post` | `guia-pratico-para-brasileiros-em-laois` | `practical-guide-for-brazilians-in-laois` |

All nine are classified `MISSING_SOURCE`. The engine classifies them as `skip`
with its own documented reason — *"PT record absent in this site (documented
exclusion)"* — which is **not** a conflict, so the stage can still run.

**Consequence, stated honestly:** applying the current datasets as-is yields
**49/52 EN guides** and **36/42 EN blog posts**, not 52/52 and 42/42. No PT
source was manufactured, no placeholder was created, and none was dropped. The
full authored count cannot honestly be claimed until these nine are re-keyed to
real PT records — an editorial data change, not a rollout action.

### 4.2 `en-page::newsletter` → `newsletter-2`: **conflict requiring resolution**

**Why the collision exists.** The `en-page` manifest authors the EN slug
`newsletter`, which is the *same* `post_name` the PT page already holds (PT
`newsletter`, id 13, `publish`). WordPress uniquifies a slug across the whole
post-type namespace, so a second `page` cannot also be `newsletter`.

**What the repository's slug policy says.** Exactly two stages deliberately reuse
a PT `post_name`, and both arm
`conexao_en_translation_with_shared_page_slug()`: `en-blog-page` (`blog`) and
`en-jobs-page` (`empregos`). That permit pins `wp_unique_post_slug()` to the
authored slug for the whole apply. **`en-page` does not arm it** — and the
filter's own comment names this exact situation: it exists *"because [of] the
same problem … the pre-existing `newsletter` EN record drifted to
`newsletter-2`, which Stage O deliberately does not repair."*

**Resolution: `newsletter` → `newsletter-2`, classified
`conflict requiring resolution`.** Verified live:

| Check | Result |
|---|---|
| Pages holding `newsletter` | id 13, `publish` (the PT record) |
| Pages holding `newsletter-2` | **none** — so the stage would **create**, not repair |
| `/en/newsletter/` today | **200**, `lang=en-US`, canonical → `/newsletter/`, **Portuguese body** |
| `/en/newsletter-2/` today | **404** |

The two permitted rows behave correctly: `/en/blog/` and `/en/empregos/` both
return 200 and are served by the shape their permit guarantees.

**Why this is a conflict and not merely a warning.** `/en/newsletter/` currently
answers 200 with the **Portuguese** body and a canonical pointing at PT — the
B2 shape. But `newsletter` is **not** in `conexao_b2_page_allowlist()`; the
theme's own comment says the remaining narrative/utility pages (*"category hubs,
newsletter, revista, anuncie, search"*) **keep B1**. So today a page the
repository classifies as B1 is being served as B2, and the `en-page` apply would
not fix it: it would create the EN record as `newsletter-2`, leaving
`/en/newsletter/` still resolving to the PT body under a shared slug. Accepting
`newsletter-2` silently would produce exactly the URL debt the repository has
already documented and Stage O deliberately declined to create.

Per §7, an unresolved target collision makes the overall dry-run status
**BLOCKED**. Resolution requires a decision outside this phase: arm the permit
for this one row, author a distinct EN slug, or accept `newsletter-2`
explicitly. **None of these was done here** — the slug policy is unchanged, PT
was not renamed, and no existing EN page was overwritten (none exists).

### 4.3 Every other conflict or exception

There are **none**. Conflicts 0, invalid 0, orphans 0, duplicate target
identities 0, unexpected EN objects 0, and no shared-slug row other than the
three analysed above. The B2 PT-drift guard found no stale translation.

**The guards were proven to fire, not merely observed to be quiet.** Injecting a
changed PT excerpt makes `en-leisure-description` raise its `stage_conflict`;
injecting a foreign record on an EN slug flips `slug_collision` to true; a
one-way Polylang link makes `pair_ok` false. Each then classifies exactly as the
engine would. A guard that cannot fail is not a guard.

## 5. Acceptance gates (§13)

| Gate | Value | Required |
|---|---:|---:|
| Total manifest objects | 426 | — |
| B1 objects / B2 objects | 127 / 299 | — |
| B1 creates | 118 | — |
| B2 field updates | 299 | — |
| Taxonomy creates | 13 | — |
| Already-complete | 0 | — |
| Conflicts | 0 | 0 |
| Invalid | 0 | 0 |
| Missing source | **9** | reported |
| Orphans | 0 | 0 |
| Duplicate target identities | **0** | **0** |
| **PT overwrite attempts** | **0** | **0** |
| **Unexpected PT mutation operations** | **0** | **0** |
| **Unexpected EN existing objects** | **0** | 0 |
| Unclassified objects | **0** | **0** |
| **Unresolved slug conflicts** | **1** | **0** |

The four required-zero gates pass. The one non-zero gate — **unresolved slug
conflicts = 1** — is what blocks the next phase.

*Note on "PT overwrite attempts": a shared slug is **not** a PT overwrite. Each
of the three shared-slug rows still creates a **new EN-language record** and only
reads the PT record. The 299 B2 rows write one EN-prefixed field on a PT record
by design, which is the documented B2 strategy and is reported separately as
`b2_field_writes_on_pt_record = 299`. No operation writes a PT slug, status,
title, body, taxonomy or media.*

## 6. Snapshot

| | |
|---|---|
| Path | `docs/evidence/2026-09-30-en-translation-dry-run/03-mutation-snapshot.json` |
| Objects | **430** = 417 record entries + 13 taxonomy terms |
| Digest | `f1f3c3bbef985986f53c08da7588f0c8f43d5dde149016505ff0cfdd6807bc18` |

**Verified immediately after generation:** readable; 430 objects; digest recorded
by the plan; **every entry maps 1:1 to a planned operation** (417 ↔ 417, zero
orphans either way); every required field present on every entry; the same PT
identity baseline as Phase 1; EN still 0; PT translated scope still 2,175. The
snapshot **reconciles exactly** to the dry-run manifest.

Each entry carries the source PT ID, post type, slug, status, language, modified
date and taxonomy terms; the target type, slug and language expectation; the
current target existence state; the current translation relationship; the B1/B2
class and the planned operation. B2 entries additionally carry the meta key, the
current value and the expected EN value; taxonomy terms carry their stable
identifiers on both sides. This is a **capture, not an application** — it is
read-only and it modified nothing.

## 7. Determinism (§16)

Two executions of the same tool were compared **field by field**; the second
performed its own full uncached production read.

| Comparison | Result |
|---|---|
| Plan digest | `048543cf21140a7a…` = `048543cf21140a7a…` ✅ |
| Snapshot digest | `f1f3c3bbef985986…` = `f1f3c3bbef985986…` ✅ |
| Snapshot object count | 430 = 430 ✅ |
| Snapshot body byte-identical | ✅ |
| Operation list (417 ops) byte-identical | ✅ |
| Acceptance gates byte-identical | ✅ |
| Per-stage plans byte-identical | ✅ |
| Missing sources / slug collisions / taxonomy | identical ✅ |
| PT identity digest | equal ✅ |

The only differing report fields are the wall-clock timestamp and the output
filename — run metadata, not plan content. **All checks pass.**

*An intermediate run was discarded and is not counted: it had been started
before the PT-overwrite gate was corrected, so it executed older logic and
legitimately differed on four gate fields. Determinism is claimed only from the
like-for-like comparison above, not from "the command succeeded twice".*

## 8. Safety

| | |
|---|---|
| **Production writes** | **0** (GET only; the tool defines no other verb) |
| **EN records created** | **0** |
| **Translation links created** | **0** |
| **Taxonomy changes** | **0** |
| **B2 field values written** | **0** |
| **PT drift** | **0** (identity digest identical to Phase 1) |
| **`.env` changed** | **NO** (md5 `e0e2ff20b47ebfee98838c976a8e331f`) |
| **Secrets exposed** | **NO** (credentials read from the environment only) |
| `run-en-translation.php` invoked | **NO** — no `--apply`, no `--remove` |
| `Engine::run()` invoked | **NO** — only the pure planning/gate methods ran |
| Plugins deployed | **0** |
| Menus / options / theme mods | **0** |
| Source changes | none — `git diff` over `wp-content/`, `scripts/`, `tests/` is empty |

### Pre-existing repository gate failure (not this phase's)

`python3 scripts/verify-permanent-gates.py` reports **6 of 7** permanent gates
passing and `i18n_freshness` **failing**. That failure **predates this work**:
re-running the gate on a clean `HEAD` with this phase's files stashed produces
the identical result (`8 passed, 3 failed`, `2 pre-existing, 1 new`). It flags
`wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` as older
than the theme sources — a translation-catalogue freshness debt introduced by
commit `4ab8678`, in a file this phase does not touch. `./scripts/lint.sh` is
**OK** (syntax clean, no new PHPCS violations, PHPStan clean) and
`php scripts/generate-registry-docs.php --check` reports **zero writes**. The
failing gate was **not** weakened to obtain a green run.

## 9. Acceptance criteria

| # | Required | Result |
|---|---|---|
| 1 | Inventory baseline verified, not assumed | ✅ digests re-hashed; no source drift since Phase 1 |
| 2 | Current engine re-read; files unaltered | ✅ engine sha256 recorded; `git diff` empty |
| 3 | B1/B2 classification validated from HEAD | ✅ 7 stages, identical to Phase 1 |
| 4 | Production revalidated before the dry-run | ✅ 2,175 / EN 0 / links 0 / PT digest equal |
| 5 | Canonical dry-run executed, no apply | ✅ the repository's own `build_plan()` |
| 6 | B1 and B2 totals separated | ✅ 118 creates · 299 field updates |
| 7 | 9 missing sources explicit as `MISSING_SOURCE` | ✅ named individually in §4.1 |
| 8 | `newsletter` collision explained and classified | ✅ conflict requiring resolution, §4.2 |
| 9 | Existing EN state re-checked | ✅ 0 unexpected EN objects |
| 10 | PT overwrite protection proven | ✅ 0 attempts; target language asserted `en` |
| 11 | Taxonomy scope respected | ✅ 13 creates; county/town shared and untouched |
| 12 | Acceptance gates produced numerically | ✅ §5 |
| 13 | Snapshot read-only and complete | ✅ 430 objects, 1:1 with the plan |
| 14 | Snapshot reconciles to the dry-run | ✅ verified field by field |
| 15 | Dry-run deterministic on repetition | ✅ every comparison identical |
| 16 | No apply of any kind | ✅ production writes 0 |

## 10. Status

### BLOCKED — EN DRY-RUN NOT READY FOR APPLY

The dry-run itself is sound: it is the repository's own engine, it is complete,
every object is classified, the required zero-gates all read zero, the snapshot
reconciles exactly, and the plan is deterministic across independent reads.
**No apply was performed and none may be performed on this state.**

It is **BLOCKED** on two findings that must be decided before Phase 3:

1. **Unresolved target collision** — `en-page::newsletter` would create
   `newsletter-2`, leaving `/en/newsletter/` still served as a B2-shaped
   Portuguese response for a page the repository classifies as B1.
2. **Nine authored rows have no PT source** — the apply would deliver 49/52
   guides and 36/42 posts, not the full authored count.

Neither is fixable by a dry-run. Both need an explicit decision (an editorial
re-key, and a slug-policy decision), each of which is its own change with its
own plan. Resolving them is **not** authorised by this phase and was **not**
attempted.

**Not started, by design:** apply · EN creation · translation linking · B2 field
update · taxonomy creation. The next explicit task, once the two findings above
are resolved, is:

**EN Translation Rollout — Phase 3: Apply + Immediate Verification**


