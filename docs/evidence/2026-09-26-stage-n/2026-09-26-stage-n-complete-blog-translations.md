# Report — Stage N: Complete the Remaining English Blog Translations

| | |
|---|---|
| **Stage / task name** | Stage N — complete the remaining 34 EN blog translations |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `43a7ed8d5c919259a089a6551906ef25a37b14d3` |
| **Final SHA** | `0f80931` (data/tooling: `30a1ed5`) |
| **Working tree at finish** | clean |
| **Final status** | **PASS** — §30 |

> Every number below came from an executed command. The machine output is in
> [`docs/evidence/2026-09-26-stage-n/`](.). No gate, baseline, allowlist, policy
> or CI tolerance was weakened. Nothing was written to production.

## 1. Scope completed

The last permanent-invariant debt from Stage M —
`post_type:post:missing_en: 34` — is closed. All 34 remaining eligible public
Portuguese blog posts were translated into English and applied as linked Polylang
translations through the existing shared rollout engine.

| Metric | Baseline | Final |
|---|---|---|
| Eligible public PT blog posts | 42 | 42 |
| Translated | 8 | **42** |
| **`post_type:post:missing_en`** | **34** | **0** |
| Malformed relationships | 0 | **0** |
| EN body byte-identical to PT | 0 | **0** |
| Publication-date mismatches | 0 | **0** |
| Blog allowlist entries | 0 | **0** |
| Aggregate allowlisted count | 1821 | **1821** |
| PT drift | 0 | **0** |
| Permanent-gate aggregate | 6/7, 34 violations, exit 1 | **7/7, 0 violations, exit 0** |

Authored English: **18,735 words** across 34 articles, from 143,470 characters
of Portuguese source (20,007 PT words).

## 2. Scope NOT completed

Two things are deliberately **not** done, both with reasons:

1. **The EN blog archive at `/en/blog/` still serves the PT posts with the B2
   fallback notice.** This is a page-allowlist policy fact, not translation debt
   (§8). Fixing it requires creating a linked EN translation of the `blog`
   **page record**, which would move `blog` out of
   `conexao_b2_page_allowlist()` and change the aggregate allowlist count from
   **1821 to 1820** — forbidden by this stage's acceptance criteria. Reported,
   not silently "fixed".
2. **`shellcheck` and `composer` are not installed** in this environment, so
   `./scripts/lint.sh` cannot complete end-to-end. The individual static stages
   were run directly instead (§9, §13).

## 3. Files added / modified / deleted

**2 commits, 12 files, +124/−22 in the tracked diff plus 2 new files.**

Added:
- `wp-content/plugins/conexao-en-translation/includes/blog-translation-data.php`
  — the versioned Stage N Blog data (`blog-v1`): 34 authored EN rows keyed by PT
  slug, plus `conexao_en_translation_blog_batches()`.
- `docs/evidence/2026-09-26-stage-n/` — 22 evidence files.

Modified:
- `wp-content/plugins/conexao-en-translation/includes/translation-map.php` —
  merges the versioned Blog data into the `post` manifest as a **union keyed by
  PT slug**, failing closed on a duplicate slug.
- `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` — version
  1.1.0, the new data file in its `require_once` list, and the header
  description.
- `docs/plugins/conexao-en-translation.md` — the new data file, the editorial
  rules, the batches, the result, and the B2-archive finding.
- `docs/routing.md`, `docs/testing.md`, `docs/evidence/README.md` — the real
  changes only.
- `docs/architecture.md`, `docs/content-model.md`, `docs/plugins/README.md`,
  `docs/project-inventory.md` — one-line `_Last verified_` stamp, plus the
  generated registry version block.

Deleted: none. The four scratch validators used during the stage
(`scripts/zz-stage-n-*`, `scripts/data/stage-n-*.json`) were **never committed**
and were deleted before the commit.

## 4. Runtime impact

**None.** The plugin is `production: false` and `build: false`. It creates EN
content records only; it adds no template, route, query or runtime hook. The
shared engine, the stage adapter and `scripts/run-en-translation.php` are
byte-identical (`git diff` empty for `conexao-translation-rollout/**`).

## 5. Content / data impact

- Created: **34** EN `post` records. Updated: 0 (as authored). Deleted: 0.

## 6. Polylang impact

- Post type `post` (B1). All 42 eligible public PT posts now have exactly one
  linked EN translation, each verified **bidirectionally**
  (`pll_get_post(pt,'en') === en` **and** `pll_get_post(en,'pt') === pt`).
- **PT immutability: proven.** 34 pre-apply PT records re-read and compared on
  8 core fields (title, content, excerpt, date, status, author, menu order,
  featured image) + 4 taxonomies + Polylang language + the bidirectional link.
  **PT drift = 0.** PT workset fingerprint:
  `95d38b71ec99ad2592a5b3e40a0e8d42adb325acec171461e4e20cdf85550932`.
- Dates preserved: 0 mismatches. Authors preserved: 0 mismatches. Featured
  images preserved by the existing adapter: 0 mismatches.
- **No allowlist, no policy change.** `post` is not in
  `conexao_b2_post_types()`; no `post:<slug>` allowlist key exists; the aggregate
  allowlist is **1821 before and 1821 after** (acceptance N5).

## 7. Route / HTTP impact

No route, redirect or sitemap rule was added or changed. Each EN post is a new
record under the `/en/…` permalink structure the theme already serves. All 42
EN singles verified: **200, self-canonical, `en` hreflang → itself, `pt`/`pt-BR`
hreflang → the PT URL, no B2 notice, English body** — 504 assertions, 0 failures.

## 8. The EN blog archive — a reported finding, not debt

`/en/blog/` still lists the PT posts with the B2 notice. This is correct under
the current policy and must not be changed by this stage:

- the Blog **archive** is the static posts page (`page_for_posts`, slug `blog`),
  and `blog` is on `conexao_b2_page_allowlist()` (verified: the allowlist is
  `dublin, cork, galway, limerick, kildare, meath, wicklow, waterford, laois,
  irlanda, blog`);
- the documented retirement condition (`inc/b2-fallback.php`, Stage 5 comment) is
  *"a real, published EN posts page"* — a linked EN translation of the `blog`
  **page record**;
- `conexao_b2_posts_page_pre_query()` therefore still serves a PT-scoped query,
  which is exactly the designed B2 path;
- creating the EN page record would move `blog` out of the allowlist and change
  the aggregate allowlist from **1821 → 1820**, which acceptance criterion N5
  forbids.

So the B1 obligation Stage N owns — each translated post resolving in the EN
context — is met, and the archive policy is left untouched and documented in
`docs/routing.md` and `docs/plugins/conexao-en-translation.md` for a maintainer
decision that explicitly accepts the allowlist-count change.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3 scripts/verify-permanent-gates.py` (baseline) | 1 | 7 gates, 6 passed, 1 failed; 85 passed / 1 failed; 34 violations; 1821 allowlisted |
| `python3 scripts/verify-permanent-gates.py` (**final**) | **0** | **7 gates, 7 passed, 0 failed, 0 blocked; 86 passed / 0 failed; 0 violations; AGGREGATE: PASS** |
| `run-en-translation.php --dry-run --only=post` (final) | 0 | `created=0 updated=0 skipped=42 conflicts=0 errors=0 PT-drift=0`, GATE PASS |
| `./scripts/run-tests.sh` (before) | 1 | 54 suites, 40 passed, 14 failed; 3385 passed / 56 failed; 6 script suites (4/2); 3 HTTP suites (3/3) |
| `./scripts/run-tests.sh` (after) | 1 | 54 suites, **41 passed, 13 failed**; **3388 passed / 53 failed**; **6 script suites, 6 passed, 0 failed**; 3 HTTP suites, 3 passed |
| `verify-script-conventions.py` | 0 | 305 passed, 0 failed |
| `verify-release-integrity.py` | 0 | 210 passed, 0 failed |
| `verify-agent-governance.py` | 0 | 247 passed, 0 failed |
| `generate-registry-docs.php --check` | 0 | 14 plugins, 23 regions current, zero writes |
| `verify-documentation-drift.py` | 0 | 14 passed, 0 failed |
| `verify-i18n-freshness.py` | 0 | 8 passed, 0 failed |
| `verify-cache-key-scoping.py` | 0 | 2 passed, 0 failed |

### Numeric test results

In-process PHP: **54 suites total, 41 passed, 13 failed**; **3388 assertions
passed, 53 failed** (was 40/14 and 3385/56).

### HTTP acceptance

Existing matrices: **3 suites, 3 passed, 0 failed** — `verify-guides-en-http.py`
(18), `verify-release-http.py` (42), `verify-routing-http.py` (59).

Plus a Stage N programmatic pass over **all 42 EN blog posts: 504 assertions,
0 failures** (12 per record: status, EN route, `lang`, no B2 notice, canonical
present, canonical == final, `en` hreflang, `pt`/`pt-BR` hreflang, `en` hreflang
== final, PT hreflang not in the EN layer, English body).

No new matrix row was added: `en-b2-fallback-en-blog` already covers `/en/blog/`,
and its own note says it must become the real-EN-archive row only once the EN
**page** exists — which it does not, and which this stage must not create (§8).

### Static analysis

| Tool | Result |
|---|---|
| PHP syntax (`php -l`, all 6 plugin files) | **no syntax errors** |
| **PHPCS** (`phpcs.xml.dist`: WordPress-Extra/Docs + PHPCompatibilityWP) | **0 errors, 0 warnings, 6/6 files** |
| **PHPStan** | **[OK] No errors** |
| `shellcheck` | **NOT AVAILABLE** in this environment |
| `composer` | **NOT AVAILABLE** (so `./scripts/lint.sh` cannot run end-to-end) |

PHPCS initially could not run because the host PHP lacks `xmlwriter`/`SimpleXML`;
it was run inside the WordPress container, which has them, against the
repository's own ruleset. PHPCBF auto-fixed 18 alignment findings in the new data
file; the file was re-verified afterwards (syntax, 34 rows, 0 unresolved slugs,
dry-run GATE PASS).

## 10. Failure proofs / negative tests

**7 negative proofs, every one failing closed and every one reverted** —
[`negative-proofs.txt`](negative-proofs.txt).

| Proof | Break | Result while broken | After revert |
|---|---|---|---|
| A | unlink a translated post | `missing_en: 1`, `malformed: 1`, exit 1 | 0 violations |
| B | mutate a PT title after the snapshot | `PT drift: 1` | `PT drift: 0` |
| C | ambiguous/unknown mode flag | **exit 2**, never falls through to apply | dry-run 42 → 42 posts (write-free) |
| D | point a manifest row at another record's EN slug | engine **refuses**, exit 1, `(zero writes)`, 42 posts unchanged | manifest restored |
| E | throwaway EN record with no PT master | `malformed_relationships: 1` | throwaway deleted, 0 violations |
| F | attempt policy/baseline suppression | all 5 policy files **UNCHANGED** | allowlist 1821 |
| G | overwrite an EN body with the PT body | `PT drift: 1` | repaired, `PT drift: 0` |

**Finding from proof G (reported, not hidden):** the shared engine's plan marks a
record `skipped` when a valid bidirectional Polylang link exists, so its repair
trigger is **link-based, not content-based** — a drifted EN body is *not*
self-healing by re-running the engine. This is a pre-existing property of
`conexao-translation-rollout`, not something Stage N introduced, and it is why
this stage verifies EN bodies against the authored manifest rather than trusting
the link.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 14 | **13** |
| Failing assertion count | 56 | **53** |
| Failing script-contract | 2 | **0** |
| Failing acceptance assertions | 0 | **0** |

**Diff of the failing lists: three suites left the list and none entered it.**


## 14. Evidence paths

`docs/evidence/2026-09-26-stage-n/`: `2026-09-26-stage-n-plan.md`,
`2026-09-26-stage-n-complete-blog-translations.md`,
`baseline-gate-output.txt`, `baseline-gate.json`, `pt-snapshot-pre-apply.json`,
`dry-run-guard-proof.txt`, `batch-1-dry-run.txt` … `batch-6.txt`,
`translation-validation.txt`, `translation-quality-record.md`,
`pt-immutability-proof.txt`, `idempotence-proof.txt`, `negative-proofs.txt`,
`http-acceptance-summary.txt`, `http-routing-matrix.txt`, `en-posts-rest.json`,
`test-summary-before.txt`, `test-summary-after.txt`, `final-gate-output.txt`,
`final-inventory.txt`.

## 15. Documentation updated

`docs/plugins/conexao-en-translation.md` (the stage's own doc: data file,
editorial rules, batches, result, B2 finding), `docs/routing.md` (Blog B2 end
state), `docs/testing.md` (remedy pointer), `docs/evidence/README.md` (index),
plus generated registry regions in `docs/architecture.md`,
`docs/plugins/README.md`, `docs/project-inventory.md` and
`docs/content-model.md` (version + `_Last verified_` only).

## 16. Rollback / recovery

| Change | Rollback |
|---|---|
| 34 EN post records | `php scripts/run-en-translation.php --remove --apply --only=post` (engine `allow_remove: true`; removes only records this manifest owns, never a PT original) |
| A single bad batch | remove the affected rows from `blog-translation-data.php`, restore the previous commit, re-run `--apply`; the engine skips the rest |
| Authored data | `git revert 30a1ed5` |
| Docs/evidence | `git revert 0f80931` |
| Any EN body drift | write the manifest body back — **the engine will not self-repair** (§10, proof G) |

The pre-change PT state is preserved in `pt-snapshot-pre-apply.json`.

## 17. Acceptance criteria

| # | Criterion | Result |
|---|---|---|
| N1 | Fresh baseline confirms the debt | 42/8/34/0/0 confirmed by runtime inventory, not prose |
| N2 | All 34 translated | **34** |
| N3 | `post_type:post:missing_en = 0` | **0** |
| N4 | No Blog EN allowlist added | none; `post` not a B2 type |
| N5 | `allowlisted_before == allowlisted_after == 1821` | **1821 == 1821** |
| N6 | Every new EN linked bidirectionally | 34/34, 0 malformed |
| N7 | No unrelated EN collision overwritten | 0 collisions; proof D proves refusal |
| N8 | PT source content unchanged | 34/34 identical |
| N9 | `PT drift = 0` | **0** |
| N10 | EN content is not a PT copy | 0 byte-identical bodies |
| N11 | Author/byline/Instagram preserved | 24 bylines, 26 handle occurrences, 0 lost |
| N12 | Media and taxonomies preserved | 0 thumbnail mismatches; adapter unchanged |
| N13 | Links satisfy the localisation rules | 45 external URLs preserved; 0 invented internal links |
| N14 | Dry-run proven write-free | 42 → 42 posts around a dry-run |
| N15 | Apply explicit and fail-closed | unknown flag → exit 2 |
| N16 | Stage idempotent | `created=0 updated=0 skipped=42` |
| N17 | Negative proofs fail closed | 7/7, all reverted |
| N18 | Permanent gate 7/7, 0 violations | **7 passed, 86/0 assertions, 0 violations, exit 0** |
| N19 | No new regression | 3 suites left the failing list, 0 entered |
| N20 | HTTP acceptance passing | 3/3 matrices; 504/504 programmatic |
| N21 | No gate/baseline/policy/allowlist/CI weakened | 5 policy files `UNCHANGED` |
| N22 | No production write | 0 |
| N23 | Flutter/mobile untouched | never read, written or accessed |
| N24 | Documentation and evidence complete | §14, §15 |
| N25 | Working tree clean | clean at `0f80931` |

## 18. Flutter / mobile confirmation

The Flutter/mobile repository was **not touched, not read and not accessed**. Every
path in this stage is inside `/home/andrei/IdeaProjects/wordpress-website`. The
scope freeze was respected in full.

## 19. Final status

**PASS.**

The authoritative dataset satisfies every invariant it is measured against: the
permanent-gate aggregate is **7 gates, 7 passed, 0 failed, 0 blocked, 86/86
assertions, 0 violations, `AGGREGATE: PASS`, exit 0** — the aggregate this stage
was defined by, run unmodified, with the allowlist unchanged at 1821 and no gate,
baseline, policy or CI tolerance weakened. Every required verifier ran; the two
tools that could not run (`shellcheck`, `composer`) are recorded as unavailable
rather than as passing, and neither gates the Blog invariant. The one substantive
finding — the EN blog archive's B2 policy — is reported with its cause and its
blocked-by-design remedy rather than quietly resolved against the acceptance
criteria.

_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_

- `theme/…/test-translation-completeness.php` — **now passing** (the target).
- `tests/scripts/verify-script-conventions.py` — **now passing**.
- `tests/scripts/verify-documentation-drift.py` — **now passing**.
- `theme/…/test-blog-en-translation.php` — still failing, but **improved from 10
  to 8** failed assertions.

## 12. Known pre-existing failures (13 suites, not mine)

`conexao-event-importer/test-county-registry`, `…/test-error-handling`,
`…/test-ivvcc-importer`, `…/test-town-sanitization`,
`theme/…/test-blog-en-translation`, `…/test-event-location-filters`,
`…/test-header-menu-selection`, `…/test-leisure-card-excerpt-language`,
`…/test-leisure-related-events`, `…/test-stage32-bilingual`,
`…/test-stage33-bilingual`, `…/test-stage41-rest-language`,
`…/test-stage45-pages`.

`test-blog-en-translation.php` is the only Blog-related one. Its 8 remaining
assertions are the EN **posts page** assertions (`en_id=0` — §8) and two
category-term-link assertions. No suite that passed before fails now.

## 13. Limitations

1. **`shellcheck` unavailable** — not run; not claimed as passed.
2. **`composer` unavailable** — `./scripts/lint.sh` cannot run end-to-end; the
   PHPCS and PHPStan stages were invoked directly (both clean).
3. **13 pre-existing failing suites** were left alone as out of scope (§12).
4. **No production verification of any kind** — no production write, deploy,
   upload or activation was in scope or attempted.
5. **The EN blog archive finding (§8) is reported, not resolved**, because
   resolving it would violate acceptance criterion N5.


- Identity: the **PT post slug** is the manifest key throughout. No local
  post/attachment ID is used as cross-environment identity.
- Per-record, all 34 were verified against the live database: EN slug, EN title,
  EN body, bidirectional link, EN language, date, author, featured image and
  "not a PT copy" — **0 problems**.
