# Plan — Stage M: Permanent Invariant Debt Remediation

> Working plan for Stage M. The template at
> [`docs/templates/plan.md`](../../templates/plan.md) is NOT edited; this is the
> filled-in copy required by `docs/engineering-standard.md` §13.2 before work
> that changes **content and Polylang data**.

| | |
|---|---|
| **Task title** | Stage M — Permanent Invariant Debt Remediation |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `ed9470517aabd9cf27939729d9cb19a855d4af45` |
| **Plan status** | approved |

## 1. Scope

Stage L installed seven fail-closed permanent invariant gates and deliberately
left the pre-existing data/documentation debt red (361 violations). Stage M
makes the repository and its authoritative dataset actually satisfy those
invariants, by remediating the debt at its origin and repairing the data — never
by weakening a gate, broadening an allowlist, adding `continue-on-error`,
downgrading a failure to a warning, or relabelling debt as pre-existing.

Four debt categories, measured fresh at the start SHA (see
[`baseline-inventory.txt`](baseline-inventory.txt)):

| # | Category | Baseline | Origin proven in Phase 0 |
|---|---|---|---|
| 1 | `conexao_county` / `conexao_town` language tags | 282 (32 + 250) | **Historical residue.** Current seed already correct; a fresh `wp_insert_term` returns no language. No current code regenerates it. |
| 2 | Missing EN translations | 72 (guide 1, page 29, post 42) | Real untranslated PT editorial content. |
| 3 | Malformed guide relationship | 1 | EN `stage32-editorial-translation` (id 22184) is a **published Stage 3.2 test fixture** with no PT master. |
| 4 | Stale i18n catalogues | 6 | **Missing generator.** The repo has a freshness *check* (`i18n-check.sh`) but no generation script — audit F-04's `i18n-make-pot.sh` was never built. |

## 2. Explicit non-goals

- **Not** fixing the 15 pre-Stage-L failing suites. They are pre-existing
  failures recorded in the Stage L report; Stage M compares against them and
  must not regress them, but repairing them is out of scope.
- **Not** touching the Flutter/mobile repository (scope freeze).
- **Not** weakening, reordering or reinterpreting any gate, baseline entry,
  allowlist or aggregate. `tests/lib/permanent-gates.php`,
  `tests/baseline/permanent-gates.json`, `scripts/verify-permanent-gates.py`
  and every `test-*.php` gate are **read-only** for Stage M.
- **Not** adding an EN allowlist to absorb the 29 pages or 42 posts.
- **Not** creating a second translation engine, a second baseline, or a second
  debt registry.
- **Not** performing any production write, upload, activation or REST call.
- **Not** renaming, re-slugging or duplicating county/town terms.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§0.2 identity, §0.5 PT
immutability, §5.2 content-change contract, §6.1/§6.2/§6.3 EN rules and
permanent invariants, §9.3 i18n, §10 scripts, §11 release, §14 definition of
done); `docs/content-model.md`; `docs/routing.md` (B1/B2 policy);
`docs/testing.md` (the permanent-gate table); `docs/releases.md`;
`docs/templates/plan.md`; `docs/templates/report.md`; `plugins.json`
(authoritative registry); `scripts/README.md`; `scripts/run-tests.sh`;
`scripts/verify-permanent-gates.py`; `tests/lib/permanent-gates.php`;
`tests/baseline/permanent-gates.json`; the full Stage L report and evidence;
`wp-content/plugins/conexao-translation-rollout/` (the shared engine);
`Conexao_Data_Model_Relationships::seed_terms()`; the retired
`conexao-guide-translation` rollout (as the reference implementation).

## 4. Current-state findings

Verified at `ed94705`, not assumed:

- Aggregate: **7 gates, 4 passed, 3 failed, 0 blocked; 79 passed / 12 failed
  assertions; 361 violations (361 pre-existing, 0 new); exit 1.** Identical to
  the Stage L baseline — the debt has not moved.
- Taxonomy: 32 county + 250 town terms carry a `pll_pt` term-language
  relationship. `conexao_category` is 58 PT / 16 EN and is **not** affected.
  Every county/town term sits in a `term_translations` group of size 1, so no
  translation relationship depends on the tag. The theme queries these
  taxonomies by slug (`?county=`, `?cidade=`), never by language.
- Translation (runtime-discovered): `course_provider` 10/0, `event` 1503/0,
  `guide` 53/**1**, `job` 1/0, `leisure` 293/0, `page` 44/**29**,
  `post` 42/**42**, `sponsor` 3/0. The 1821 allowlisted records are entirely
  policy-derived from `conexao_b2_post_types()` and
  `conexao_b2_page_allowlist()`.
- Malformed: EN guide id 22184 `stage32-editorial-translation`, title
  `[STAGE32-EDITORIAL] translation`, body `Stage 3.2 editorial state fixture.`,
  `PT=0`.
- i18n: 6 of 8 catalogues stale; the 2 fresh ones are untouched.


## 5. Proposed approach

**Taxonomy (Phase 2).** One reusable remediation script
`scripts/remediate-shared-taxonomy-language.php` that, for every term of a
taxonomy that the *runtime* reports as **not** Polylang-translated
(`PLL()->model->get_translated_taxonomies()`), removes the stale term-language
assignment via Polylang's own model API. The policy is read from the runtime,
never hard-coded. **Rejected:** raw SQL deletes (they bypass Polylang's caches
and its own bookkeeping) and a seed change (the seed is already correct, so
changing it would be the "modify the source to fit the data" anti-pattern).

**Malformed relationship (Phase 3).** Investigate, then remove the EN fixture
22184 as an orphan: it is not editorial content, is referenced by nothing, and
has no PT master to link to. The snapshot captures its full prior state so the
removal is reversible.

**Missing EN (Phases 4–6).** Use the shared
`conexao-translation-rollout` engine and its declarative config + authored
manifest, following the six-step contract: inventory → manifest → dry-run →
snapshot → apply → verify. PT records are never modified. A fixture or
historical record found in the population (`about` id 1 is the untouched
WordPress sample page; `jobs-2` id 22201 duplicates the real `/empregos/`
page) is classified and handled explicitly rather than blindly translated.

**i18n (Phase 8).** Build the missing generator `scripts/i18n-make-pot.sh`
(audit F-04's remedy — this fixes the *source* of repeated staleness) using
WP-CLI `i18n make-pot`, then regenerate the 6 stale catalogues with it. Never
hand-edit a `.pot`.

**B2 (Phase 7).** Audit-only: confirm every allowlisted record is genuinely
covered by the documented policy. No new allowlist entries.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/remediate-shared-taxonomy-language.php` | added | The 282 stale term-language tags have no existing tooling; the Stage I contract applies |
| `scripts/i18n-make-pot.sh` | added | Root cause of the i18n debt: no generator exists (audit F-04) |
| `wp-content/plugins/conexao-*/languages/*.pot` (6) | regenerated | Phase 8 |
| `docs/evidence/2026-09-26-stage-m/*` | added | Evidence |
| `docs/…` (content-model / testing / frontend / routing / README) | updated | Phase 16 |
| `scripts/README.md` | updated | Stage I MUST: every script is listed |

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `tests/lib/permanent-gates.php` | The gate contract. Editing it weakens every gate. |
| `tests/baseline/permanent-gates.json` | Relabelling remaining debt as pre-existing is forbidden. |
| `scripts/verify-permanent-gates.py` | Changing the aggregate to obtain numbers is forbidden. |
| `wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php` | The gate itself. |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | The gate itself. |

## 9. Route / HTTP impact

- Routes added: only the EN URLs of genuinely created EN translations
  (`/en/guias/{slug}/`, `/en/{page-slug}/`, `/en/{post-slug}/`).
- Routes removed: the fixture guide's EN route disappears with the fixture.
- Redirects: none added or changed;
  `conexao_b2_translation_replaced_pt_ids()` and the legacy EN→PT table decide
  precedence, both untouched.
- Sitemap: a created EN record enters the EN sitemap; the PT record stays in the
  PT sitemap. A B2 fallback stays canonical → PT and out of the sitemap.
- HTTP matrix rows: reuse the existing matrices; a new row is added only if a
  genuinely new request-visible behaviour appears.

## 10. Polylang / English impact

- Translated post types: `guide`, `post`, `page` (B1). Translated taxonomies:
  `conexao_category`, `conexao_tag` (untouched). Shared taxonomies:
  `conexao_county`, `conexao_town` (made genuinely language-neutral).
- **PT immutable: yes.**
- B1 vs B2: unchanged. `conexao_b2_post_types()` and
  `conexao_b2_page_allowlist()` are read, never widened.
- Canonical / hreflang / language switcher: verified per created record.
- Language-scoped cache keys: untouched; the cache gates must stay green.
- Completeness gate that must reach 0: `guide`, `page`, `post` `missing_en`,
  and `guide:malformed_relationships`.

## 11. Security impact

- Capabilities: n/a (CLI tooling, local only).
- Nonces: none.
- Escaping/sanitisation: all output escaped; term and post creation goes through
  WordPress APIs, never raw SQL.
- SQL: WordPress APIs (`wp_insert_term`, `pll_*`) only.
- REST surface: **no change.**
- Secrets: none. WP-CLI is a build tool in `/tmp`; no credentials in the repo.

## 14. Test plan

- In-process suites: the two failing gates must go green. No gate file is edited.
- Acceptance: the existing HTTP acceptance matrices are re-run unchanged.
- Script contract: the new `scripts/i18n-make-pot.sh` and
  `remediate-shared-taxonomy-language.php` must satisfy the Stage I conventions
  gate (dry-run default, `--apply`, run header/summary,
  `scripts/lib/bootstrap.php`).
- Registry drift: `php scripts/generate-registry-docs.php --check` (no change
  expected, since `plugins.json` is untouched).
- Pass = numeric: **361 → 0 violations, 7/7 gates, exit 0.**

## 15. Acceptance matrix plan

No new row is planned by default. The existing `cache-separation-*`,
`legacy-301-*` and guide/blog/page rows already cover the behaviour. A row is
added only if a created EN record introduces a request-visible behaviour with
no existing coverage; the decision and its justification go in the report.

## 16. Rollback plan

Pre-change state captured first:

1. **Taxonomy:** a JSON snapshot of every affected term (taxonomy, slug, name,
   term_id, current language, translation group) written to
   `docs/evidence/2026-09-26-stage-m/`. Restoration = re-apply the recorded
   language with `pll_set_term_language()`. Sufficiency is proven by re-running
   the taxonomy gate and re-measuring 282 language-tagged terms.
2. **Fixture deletion:** a JSON snapshot of the full record (title, content,
   excerpt, status, dates, meta, taxonomy assignments) and the recorded
   restoration mechanism (`wp_insert_post` + `pll_set_post_language`).
   Sufficiency proven by re-creating it and re-measuring the malformed count.
3. **Created EN records:** a manifest of every created post (PT source slug →
   EN slug) and the removal command that reverts them.
4. **Catalogues:** they are generated files under version control, so
   `git checkout --` restores them exactly.

Rollback does **not** cover production (nothing is written there) and does not
cover a partial batch: apply is idempotent and batch-verified, so a failed
batch is reverted by removing the created EN records and re-running the gate.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/content-model.md` | Record that shared proper-name terms are language-neutral in **data**, not only in policy, plus the one-time cleanup contract |
| `docs/routing.md` | Record the EN coverage outcome for the translated set and the B1/B2 decision, as policy (not counts) |
| `docs/testing.md` | Add the remediation history for the permanent gates and the i18n generator to the gate table |
| `docs/frontend.md` | Only if shared-term language neutrality changes a frontend assumption (to be verified) |
| `docs/README.md` | Index the new evidence if the doc index enumerates it |
| `AGENTS.md` | Only if an enduring agent rule actually changes (expected: no) |
| `docs/releases.md` | Only if a releasable runtime change occurs (expected: no) |

## 20. Verification gates

1. `python3 scripts/verify-permanent-gates.py` → **7 passed / 0 failed /
   0 blocked, 0 violations, exit 0**.
2. `./scripts/run-tests.sh` → no previously-passing suite regresses; the two
   gates are newly passing; the 15 pre-existing failures are unchanged.
3. `python3 tests/scripts/verify-i18n-freshness.py` → 0 stale catalogues.
4. `php scripts/generate-registry-docs.php --check` → 0 writes.
5. `./scripts/lint.sh`, phpcs, phpstan, shellcheck, `bash -n`,
   `python3 -m py_compile` → all run, all reported honestly.
6. Negative proofs A–G re-run → each gate still exits 1 when violated.

## 21. Completion criteria

Restated from `docs/engineering-standard.md` §14, applied to this stage: scope
matches the request with nothing unrelated refactored; static analysis passes
with no new violations; content writes have captured dry-run output, idempotent
apply, a PT-unchanged assertion, a numeric gate of 0 and documented rollback;
EN work satisfies the §6.1 linked-translation / canonical / hreflang / sitemap /
cache rules; documentation is updated in the same commit; no secrets, no
localhost URLs, no hotlinked media, no committed build output; the report
carries real numbers and stated limitations; the Flutter/mobile repository is
untouched.

_Last verified: 2026-09-26 by Stage M — Permanent Invariant Debt Remediation_

| `scripts/README.md` | MUST list the new scripts |

No raw telemetry goes into permanent documentation.

## 18. Release implications

- No component version bump is planned: Stage M changes **data and tooling**,
  not shipped runtime PHP. The regenerated `.pot` files are shipped assets of
  their components, so their content changes, but no behaviour changes, and a
  data-only change must not be version-bumped.
- Effect on the artifact allowlist: derived from `plugins.json`, which is
  unchanged.
- **A release is NOT in scope for Stage M.** Production remediation of the same
  debt is a separate, explicitly authorized task.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Removing a term language breaks a filter or a translation | Low | Proven safe first: all groups are size 1, no duplicates, the taxonomy is queried by slug; the taxonomy gate and the HTTP acceptance run after apply |
| Translating 42 blog posts degrades editorial quality | Medium | Batch with verification after each batch; stop on any PT drift or relationship conflict; the authored manifest is reviewable |
| The `about` / `jobs-2` anomalies are actually intentional | Medium | Classified explicitly in the plan and report before any EN record is created for them |
| **Scope creep into the 15 pre-existing failing suites** | Medium | They are named as out of scope in §2 and compared, not fixed |
| A new allowlist is added to make the gate green | Low | Hard rule; the aggregate result is checked against the violation count and the allowlist count is proven unchanged |
| Regenerating `.pot` files loses real translations | Low | `.mo`/`.po` are never touched by `make-pot`; the diff is inspected and message counts compared |


## 12. Performance impact

- Queries: one extra read pass in the remediation script (one-shot tooling, not
  request-path code). **Zero** per-page query change: no runtime PHP changes.
- Caching: no new or changed transient keys. The taxonomy cleanup is data-only.
- Assets/images: none.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | Nobody in Stage M. The remediation script and the regenerated catalogues are prepared and dry-run capable only. Any production remediation is a later, separately authorized task and must be driven from wp-admin, because production has no CLI. |
| What is the verification of the production result? | Not applicable in Stage M. The script refuses a production target and requires an explicit confirmation flag that Stage M never passes. |

| `wp-content/themes/conexao-br-irlanda/inc/i18n/*.php` | Runtime B1/B2 + taxonomy policy. Changing it to fit data is forbidden. |
| `wp-content/plugins/conexao-data-model/includes/class-relationships.php` | Already correct; a change here would be the anti-pattern. |
| `plugins.json` | No plugin is added, removed or reclassified. |
| `.github/workflows/ci.yml` | No `continue-on-error`; no tolerance change. |
| The Flutter/mobile repository | Out of scope by the scope freeze. |

## 8. Content / data impact

- **Records updated:** 282 shared terms (language tag removed only).
- **Records deleted:** 1 EN fixture guide (id 22184), justified and snapshotted.
- **Records created:** EN translations for the eligible missing set (guides,
  pages, posts), each a linked translation — never a second identity.
- **Portable identifier strategy:** terms are addressed by (taxonomy, slug),
  never by local term ID as cross-environment identity. Posts are addressed by
  `post_type + post_name`, with the local ID reported for operator convenience
  only.
- **Writes content?** Yes → the six-step contract (§5.2) applies and the
  dry-run output is captured as evidence.
- **PT content changed?** **No.** PT titles, slugs, bodies, dates, meta and
  taxonomy assignments are asserted unchanged after every apply.
