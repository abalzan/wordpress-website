# Report — Stage L: Permanent Invariant Gates

| | |
|---|---|
| **Stage / task name** | Stage L — Permanent Invariant Gates |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `7bc2c3528b9e1346b220ee8e42a804296ce0553e` |
| **Final SHA** | the single `stage-l: add permanent invariant gates` commit on `i18n` (§30) |
| **Working tree at finish** | clean |
| **Final status** | **PASS WITH LIMITATION** — see §30 |

> The reporting rule that matters: a claim is only as good as the command that
> produced its number. Every number below came from an executed command whose
> output is in [`docs/evidence/2026-09-26-stage-l/`](.). "Fully verified" is
> **not** claimed: ShellCheck and `composer`/PHPStan could not run in this
> environment (§13), and the GitHub-hosted CI run was not observed (§13).

## 1. Scope completed

Stage L turned the repository's critical safety rules into standing, fail-closed
gates that run in the default test suite and block CI.

Six gate domains now exist, each independently named, each discovered by the
existing runner conventions (no second runner, no hand-maintained suite list):

| # | Domain | Suite | Layer |
|---|---|---|---|
| 1 | Taxonomy policy | `wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php` | in-process PHP |
| 2 | Translation completeness | `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | in-process PHP |
| 3 | Language-scoped caching (runtime) | `wp-content/themes/conexao-br-irlanda/tests/test-cache-language-scoping.php` | in-process PHP |
| 3 | Language-scoped caching (static) | `tests/scripts/verify-cache-key-scoping.py` | script contract |
| 4 | Legacy EN→PT redirect precedence | `wp-content/themes/conexao-br-irlanda/tests/test-redirect-precedence.php` | in-process PHP |
| 5 | Documentation drift | `tests/scripts/verify-documentation-drift.py` | script contract |
| 6 | i18n catalogue freshness | `tests/scripts/verify-i18n-freshness.py` + `scripts/i18n-check.sh` | script contract |

Supporting additions: `tests/lib/permanent-gates.php` (the single gate
contract), `tests/baseline/permanent-gates.json` (pre-existing-debt labels),
`scripts/verify-permanent-gates.py` (the additive aggregate that writes
`gate.json`), and CI wiring in `.github/workflows/ci.yml`.

## 2. Scope NOT completed

- **Existing content/data debt was NOT repaired.** 282 language-tagged shared
  terms and 72 missing EN translations remain, and the aggregate is therefore
  red. Fixing them is content work Stage L is explicitly forbidden to do.
- **Stale i18n catalogues were NOT regenerated.** The standard says a catalogue
  MUST be regenerated and never hand-edited; regenerating six catalogues is a
  content/asset change with a real diff, not part of "add gates". They are
  reported as pre-existing debt instead.
- **The `_Last verified:` marker was not added to 71 historical report/audit
  documents.** Those are immutable records of past stages, not living reference.
  The gate enforces the rule on the 15-document living set and reports the
  historical corpus as an explicit count.
- **No production verification of any kind.** Out of scope by the hard rules.

## 3. Files added / modified / deleted

**16 added, 13 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php` | added | §6.3 taxonomy policy gate |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | added | §6.1 completeness gate |
| `wp-content/themes/conexao-br-irlanda/tests/test-cache-language-scoping.php` | added | §6.1 cache gate (runtime) |
| `wp-content/themes/conexao-br-irlanda/tests/test-redirect-precedence.php` | added | §6.3 redirect gate |
| `tests/lib/permanent-gates.php` | added | shared gate contract (open/violation/allowlist/close) |
| `tests/baseline/permanent-gates.json` | added | pre-existing-debt labels — **reporting only** |
| `tests/scripts/verify-cache-key-scoping.py` | added | §6.3 cache gate (static, token scan) |
| `tests/scripts/verify-documentation-drift.py` | added | §9 documentation drift |
| `tests/scripts/verify-i18n-freshness.py` | added | §9.3 i18n freshness |
| `scripts/i18n-check.sh` | added | §9.3's documented entry point (delegates to the gate) |
| `scripts/verify-permanent-gates.py` | added | additive aggregate → `gate.json` |
| `docs/evidence/2026-09-26-stage-l/*` | added | 7 evidence files |
| `.github/workflows/ci.yml` | modified | aggregate step + `gate.json` artifact (+41/−1) |
| `scripts/README.md` | modified | catalogues the two new scripts (+2) |
| `docs/testing.md` | modified | gate documentation (+51) |
| `docs/frontend.md` | modified | cache-keying rules (+28) |
| `docs/routing.md` | modified | redirect-precedence rule (+21) |
| `docs/README.md` | modified | index entry (+28) |
| `AGENTS.md` | modified | short pointer (+8) |
| `README.md`, `docs/architecture.md`, `docs/content-model.md`, `docs/deployment.md`, `docs/templates/plan.md`, `docs/templates/report.md` | modified | `_Last verified:` markers; `report.md` template placeholder replaced with a real marker (+2 each) |


## 4. Runtime impact

**None. WordPress behaves exactly as before.** Verified structurally:
`git diff --stat -- wp-content/` is empty, and the four new files under
`wp-content/` live in the theme's `tests/` directory, which
`build-theme-zip.sh` already excludes and which never loads on a request.

No caching, redirect, routing, Polylang or query behaviour was touched. The
gates **observe** the runtime; none of them modify it. The only runtime
interaction is the redirect gate, which observes the real `conexao_seo_redirects()`
decision through the `wp_redirect` filter and then aborts the redirect with a
thrown exception so the suite survives the function's `exit()`. The redirect
logic itself is untouched and still makes the decision.

## 5. Content / data impact

**None.** No content was created, updated or deleted by the stage.

Two negative proofs briefly created local fixtures and removed them; both
reverts were re-verified:

- county term `dublin-en` (id 4608) — deleted; re-run reported 282 terms, 0 new.
- post `stage-l-completeness-proof` (id 28235) — deleted; re-run reported 42/73.

The redirect gate's own fixture is created and deleted inside the suite, and the
gate asserts its own removal (`redirect:fixture_left_behind: 0`).

## 6. Polylang impact

**No Polylang mutation.** `pll_set_post_language()` / `pll_set_term_language()`
were used only inside two throwaway negative proofs, and both were reverted.

- PT immutability: no PT record, URL, slug or identity was touched.
  Confirmed by `git status` (no content change) and by the two fixture reverts.
- EN records created: **0**.
- Completeness gate number: see §9 — `eligible public PT <type> missing EN = 0`
  is **not** met for `guide` (1), `page` (29) and `post` (42). Those are the
  pre-existing debts §12 documents. The B2 allowlist (1821 records) is derived
  from the existing `conexao_b2_post_types()` / `conexao_b2_page_allowlist()`
  policy functions, and every allowlisted record is reported.

## 7. Route / HTTP impact

**None.** No route, redirect, canonical, hreflang or sitemap behaviour changed
(`wp-content/` diff empty; the redirect file was patched only for a negative
proof and restored via `git checkout --`, proven clean afterwards).

HTTP acceptance is unchanged and still green: **3 suites, 3 passed, 0 failed**
(18 + 42 + 12 assertions). The existing `legacy-301-*` and
`cache-separation-{pt,en}` matrix rows in `tests/acceptance/matrices/routing.json`
were **not** modified — Stage L added an in-process permanent gate rather than
duplicating them, because the in-process gate proves the precedence property
deterministically without needing a live request.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | WordPress.com was never contacted. |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | Only the LOCAL Docker database, for two reverted proofs. |
| **Total production writes** | **0** | |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh` (default, after) | 1 | 54 PHP suites (37 pass / 17 fail); 3362 assertions passed / 65 failed; 6 script-contract (5 pass / 1 fail); 3 HTTP acceptance (3 pass / 0 fail) |
| `python3 scripts/verify-permanent-gates.py` | 1 | **7 gates, 4 passed, 3 failed, 0 blocked; 79 assertions passed / 12 failed; 361 violations (361 pre-existing, 0 new); AGGREGATE: FAIL** |
| `php scripts/generate-registry-docs.php --check` (Stage G) | 0 | 13 plugins validated, 22 generated regions current, zero writes |
| `python3 tests/scripts/verify-script-conventions.py` (Stage I) | 0 | **290 passed, 0 failed** |
| `python3 tests/scripts/verify-release-integrity.py` (Stage J) | 0 | **209 passed, 0 failed** |
| `python3 tests/scripts/verify-agent-governance.py` (Stage K) | 0 | **247 passed, 0 failed** |
| `./scripts/i18n-check.sh` | 1 | 7 passed, 6 failed (6 pre-existing) |
| `php -l` on all changed PHP | 0 | No syntax errors (438 files repo-wide) |
| `python3 -m py_compile` on all new Python | 0 | All compile |
| `bash -n scripts/i18n-check.sh` | 0 | Syntax OK |
| `./scripts/lint.sh` | 1 | PHPCS JSON error + `composer: command not found` — **identical at baseline** (§13) |

### Per-gate results

| Domain | Suite | Exit | Passed | Failed | Violations | Pre-existing | New | Allowlisted |
|---|---|---|---|---|---|---|---|---|
| Taxonomy | `test-taxonomy-policy.php` | 1 | 16 | 2 | 282 | 282 | 0 | 0 |
| Translation completeness | `test-translation-completeness.php` | 1 | 16 | 4 | 73 | 73 | 0 | 1821 |
| Cache scoping (runtime) | `test-cache-language-scoping.php` | 0 | 9 | 0 | 0 | 0 | 0 | 0 |
| Cache scoping (static) | `verify-cache-key-scoping.py` | 0 | 2 | 0 | 0 | 0 | 0 | 0 |
| Redirect precedence | `test-redirect-precedence.php` | 0 | 15 | 0 | 0 | 0 | 0 | 0 |
| Documentation drift | `verify-documentation-drift.py` | 0 | 14 | 0 | 0 | 0 | 0 | 0 |
| i18n freshness | `verify-i18n-freshness.py` | 1 | 7 | 6 | 6 | 6 | 0 | 0 |
| **Total** | | **1** | **79** | **12** | **361** | **361** | **0** | **1821** |

### Translation completeness detail (from the gate's own output)

| Content type | eligible PT | translated | allowlisted | **missing EN** | malformed |
|---|---|---|---|---|---|
| `course_provider` | 10 | 0 | 10 | 0 | 0 |
| `event` | 1504 | 0 | 1504 | 0 | 0 |
| `guide` | 53 | 52 | 0 | **1** | **1** |
| `job` | 1 | 1 | 0 | 0 | 0 |
| `leisure` | 293 | 0 | 293 | 0 | 0 |
| `page` | 44 | 4 | 11 | **29** | 0 |
| `post` | 42 | 0 | 0 | **42** | 0 |
| `sponsor` | 3 | 0 | 3 | 0 | 0 |

Content types are discovered from the runtime (public ∩ Polylang-translated,
minus `attachment`/`wp_block`) — never hard-coded. All 8 were evaluated; none
skipped. The B2 types are exempt by the *existing documented policy*
(`conexao_b2_post_types()`), not by a new Stage L allowlist.

### Taxonomy detail

282 shared terms inspected (32 county + 250 town), 16 translated term pairs
checked. 0 suffixed duplicates, 0 duplicate slugs, 0 broken bidirectional
links; **282 terms carry a language tag** (pre-existing — the identical count
the Stage E report already recorded as "got 282 language-assigned shared terms").

### i18n detail

6 of 8 catalogues are older than their newest gettext-bearing source:
`conexao-br-irlanda` (78573s), `conexao-data-model` (349262s),
`conexao-admin-ux` (31040s), `conexao-event-runtime` (31040s),
`conexao-event-importer` (22732s), `conexao-leisure-migration` (330944s).
`conexao-content` and `conexao-sponsor-migration` are fresh.

### Documentation drift detail

Orchestrated: Stage G (exit 0, 22 regions current), Stage I (exit 0, 290/0),
Stage K (exit 0, 247/0). Owned: 15 living documents checked for the
`_Last verified:` marker, 0 missing; 16 root files scanned, 0 report/evidence
offenders; AGENTS.md 186 lines; 7 authoritative sources present; 71 historical
documents reported as lacking the marker (counted, not suppressed).

## 10. Failure proofs / negative tests

Every gate was deliberately made to fail, in throwaway material, with the real
exit code recorded. Full output: [`failure-proofs.txt`](failure-proofs.txt).

| Proof | What was broken | Result |
|---|---|---|
| A. Taxonomy | Injected a forbidden suffixed county term `dublin-en` | **exit 1** — `suffixed_duplicate_terms: 1`, reported as **1 new**; reverted, re-run 282/0 new |
| B. Completeness | Created a synthetic PT `post` with no EN translation | **exit 1** — eligible 42→43, missing EN 42→43; reverted, re-run 42/73 |
| C. Cache scoping (static) | Unscoped `get/set_transient( 'conexao_gate_unscoped_proof' )` in a throwaway copy | **exit 1** — 2 violations, both located by file+line |
| D. Redirect precedence | Regressed the hook priority 2 → 9 (after Polylang's 4) | **exit 1** — `hook_priority_regression: 1`; file restored, `git status` clean |
| E. Documentation drift | Hand-edited a generated registry region in a throwaway copy | **exit 1** — the orchestrated Stage G gate reported `docs/deployment.md activation order` |
| F. i18n freshness | Committed a gettext source newer than its `.pot` in a throwaway repo | **exit 1** — named the stale catalogue and the triggering source |
| G. Aggregation | Aggregate run with failing constituent gates | **exit 1** — `AGGREGATE: FAIL`, accurate counts, no passing gate hid a failing one |

Positive proofs (same file, second half): valid shared terms pass; allowlisted
records counted (1821); static scan 106 files / 0 unscoped keys; normal legacy
redirect wins 15/0; generated docs current 14/0; a fresh catalogue passes 7/0.

## 11. Regression comparison

Before and after on the same environment, same command
(`./scripts/run-tests.sh`). Full data: [`regression-comparison.txt`](regression-comparison.txt).

| | Before | After | Delta |
|---|---|---|---|
| In-process suites | 50 total, 35 pass, **15 fail** | 54 total, 37 pass, **17 fail** | +4 suites, +2 failing |
| Assertions | 3306 passed, **59 failed** | 3362 passed, **65 failed** | +56 passed, +6 failed |
| Script-contract | 3 total, 3 pass, 0 fail | 6 total, 5 pass, **1 fail** | +3 suites, +1 failing |
| HTTP acceptance | 3 total, 3 pass, 0 fail | 3 total, 3 pass, 0 fail | unchanged |

Diff of the failing-suite lists:

- **Newly failing (2):** `theme/conexao-br-irlanda/test-taxonomy-policy.php`,
  `theme/conexao-br-irlanda/test-translation-completeness.php` — these ARE the
  new gates, correctly reporting pre-existing data debt.
- **Newly passing (0):** none.
- The 15 pre-existing failing suites are **unchanged** — identical names,
  identical counts.

Stage L introduced **no new failure in any existing unrelated suite**, changed
no HTTP acceptance behaviour, changed no release verification, and changed no
plugin registry data.

## 12. Known pre-existing failures

All 17 failing suites were failing before Stage L except the 2 new gates. Proof
that the shared 15 are not ours: the baseline run (`baseline-inventory.txt`) was
executed at the start SHA with the change absent and produced the identical
15-suite list.

What the new gates added is **visibility**, not new debt:

| Finding | Count | What it is | Proof it is pre-existing |
|---|---|---|---|
| Shared county/town terms carrying a language | 282 | 32 county + 250 town all tagged `pt`, contradicting `inc/i18n/guard.php` ("county/town terms have no language") | Stage E already recorded this exact figure: "got 282 language-assigned shared terms" |
| `post` missing EN | 42 | Blog posts never received the Blog translation rollout | matches the pre-existing `test-blog-en-translation.php` failure (0 EN posts) |
| `page` missing EN | 29 | Non-allowlisted PT pages with no EN record | matches the pre-existing `test-stage45-pages.php` failures |
| `guide` missing EN | 1 | `outono-irlanda-alimentacao-bem-estar` | matches the pre-existing `test-guide-en-translation.php` failure naming the same slug |
| `guide` malformed link | 1 | `stage32-editorial-translation` — an EN guide with no PT master | already documented as a known editorial fixture in `test-guide-en-translation.php:50` |
| Stale `.pot` catalogues | 6 | `.pot` older than its newest gettext source | derived purely from committed Git timestamps at the start SHA |

None of these was repaired, because repairing them is content work this stage
is forbidden to perform.

## 13. Limitations

1. **ShellCheck was not available** (`command not found` on this host). CI runs
   it on `scripts/*.sh`. The one new shell script, `scripts/i18n-check.sh`, was
   verified with `bash -n` and follows the same `set -euo pipefail` /
   explicit-`command -v` shape as the existing scripts, but **ShellCheck has
   not actually been run against it** — do not claim otherwise.
2. **PHPCS and PHPStan did not run.** `./scripts/lint.sh` fails here with
   `ERROR: PHPCS report is not valid PHPCS JSON` and
   `composer: command not found`. Both reproduce **identically at the baseline
   SHA with the change stashed** (433 files then, 438 now — the same two
   failures), so they are environment limitations, not Stage L regressions. CI
   runs the full `lint.sh` and is authoritative. The new PHP lives in `tests/`,
   which `phpcs.xml.dist` and `phpstan.neon.dist` both exclude.
3. **The GitHub-hosted CI run was not observed.** The workflow is edited and
   YAML-valid, and the local path it calls was executed, but it has not been run
   by GitHub. First-run CI issues may surface and are expected to be fixed as CI
   issues, not by weakening tests.
4. **CI starts from a clean WordPress**, so the data-dependent gates will report
   their prerequisites there rather than the numbers above. That is correct
   fail-closed behaviour, not a defect.
5. **The static cache gate's "record-scoped" exemption is a judgement**, made
   per key and documented in the gate's docstring with the reasoning from the
   real call sites. A new unrecognised key shape **fails closed** rather than
   being assumed safe, so the exemption cannot silently absorb new debt.
6. **The i18n freshness signal is git commit time.** An uncommitted source
   change cannot be detected, and a squashed history can shift the signal. It
   was chosen over mtime because mtime is meaningless in a fresh clone, which
   is exactly what CI performs.

## 14. Evidence paths

All under `docs/evidence/2026-09-26-stage-l/`:

| File | Proves |
|---|---|
| `gate.json` | The machine-readable aggregate, **generated by `scripts/verify-permanent-gates.py` from real execution output** — stage, timestamp, repository SHA, overall status, per-gate status/passed/failed/violations/pre_existing/new/allowlisted/prerequisites/details, and the totals. Never hand-edited. |
| `gate-output.txt` | The full human-readable aggregate run (exit 1). |
| `failure-proofs.txt` | All 7 negative proofs with real exit codes, and all 7 positive proofs. |
| `test-summary.txt` | Before/after default-runner totals. |
| `regression-comparison.txt` | The failing-suite diff, proving only the 2 new gates are new. |
| `git-scope-audit.txt` | Phase 16 audit: the `wp-content/` diff is empty; only test files added under it. |
| `baseline-inventory.txt` | Phase 0: Git checkpoint resolution and the existing-coverage inventory Stage L built on. |
| `2026-09-26-stage-l-permanent-invariant-gates.md` | This report. |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/testing.md` | New §"The permanent invariant gates (Stage L)": the gate inventory, how to run them, and how to read a red gate. Suite-classification table extended. |
| `docs/README.md` | New index section exposing the six gate domains and pointing at `gate.json`. |
| `docs/frontend.md` | New §"Server-side cache keys are language-scoped" — the mechanism an author must follow (required by the standard's change→document map for "Transient cache / invalidation"). |
| `docs/routing.md` | The redirect-precedence invariant documented where `conexao_seo_redirects()`'s priority-2 registration is described (required by the map for "redirect precedence verified"). |
| `AGENTS.md` | Short pointer: the aggregate command + one paragraph. Still 186 lines (< 250). |
| `scripts/README.md` | The two new scripts catalogued (Stage I MUST). |
| `README.md`, `docs/architecture.md`, `docs/content-model.md`, `docs/deployment.md`, `docs/templates/plan.md`, `docs/templates/report.md` | `_Last verified:` markers added; `report.md`'s literal `YYYY-MM-DD by <area>` placeholder replaced with a real marker. |
| `docs/engineering-standard.md` | **Deliberately NOT edited.** Implementation revealed no genuine wording mismatch: §6.3, §8.2, §9.2, §9.3 and §10 already describe exactly what Stage L implemented, and the §15 roadmap row L already names this stage. |

Every touched document ends with a `_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_` line.

## 16. Rollback / recovery

**Rollback:** revert the single Stage L commit.

```bash
git revert <stage-l-commit>
```

This is fully reversible because **nothing outside the repository changed**: no
production write, no content mutation, no Polylang change, no database schema
change, no `wp-content/` runtime edit, no new dependency, and no committed build
output. Reverting removes the gates and restores CI to the Stage K state; the
pre-existing debt disappears from the report but returns to being invisible,
which is exactly the condition Stage L exists to end.

**What rollback does NOT cover:** nothing. There is no data to restore, no
migration to reverse, and no external system to reconcile. The two negative
proofs that touched the local Docker database were reverted during the stage and
their reverts were verified (§5).

## 17. CI wiring result

The permanent invariants are enforced through the **existing** blocking path:

- `./scripts/run-tests.sh` (already blocking in the `integration` job) discovers
  all 7 new suites by convention. Verified with `./scripts/run-tests.sh --list`.
- A new **additive** step `python3 scripts/verify-permanent-gates.py` produces
  the machine-readable summary. It has **no `continue-on-error`**, so a gate
  failure fails the job.
- `gate.json` is uploaded with `actions/upload-artifact@v4`
  (`if: always()`, 30-day retention) so a red run is diagnosable.
- `permissions: contents: read` is unchanged; no secrets were added; no
  production endpoint is targeted.
- The existing registry (Stage G), release-integrity (Stage J), script-contract
  (Stage I) and static-quality jobs are untouched.

## 18. Final status

**Final status: PASS WITH LIMITATION**

The invariant machinery, its failure semantics, its CI enforcement and all
positive/negative proofs are correct and proven; the classification is *not*
`PASS` for two honest reasons: the new blocking gates are demonstrably **red**
against the current authoritative dataset (361 violations, all pre-existing,
`new = 0`), and ShellCheck / PHPCS / PHPStan could not be executed in this
environment (they fail identically at the baseline SHA, so this is an
environment limitation, not a regression).

Per the acceptance rules, CI is left **red** on the pre-existing data debt. It
was **not** made green by a broad allowlist, a `continue-on-error`, a skipped
discovery, an informational downgrade, a broadened baseline, or by changing the
dataset.

### Stage L definition-of-done

| # | Item | Status |
|---|---|---|
| L1 | Six gate domains exist | ✅ taxonomy, translation completeness, cache scoping, redirect precedence, documentation drift, i18n freshness |
| L2 | Permanent invariants run in the default suite | ✅ 7 suites, auto-discovered by `run-tests.sh` |
| L3 | Tests fail loudly, no vacuous passes | ✅ every gate has an anti-vacuity assertion; every gate proven to fail |
| L4 | Completeness uses `missing EN = 0` or a documented allowlist | ✅ 73 missing, 1821 allowlisted by the **existing** B2 policy |
| L5 | Taxonomy policy permanently enforced | ✅ 282 pre-existing violations now visible and blocking |
| L6 | Cache scoping checked against real repository code | ✅ 106 production files token-scanned + runtime probes |
| L7 | Legacy redirect precedence permanently verified | ✅ 15 assertions, conflicting-EN-page fixture |
| L8 | Documentation drift continuously checked | ✅ 14 assertions, orchestrates Stage G/I/K |
| L9 | i18n freshness continuously checked | ✅ 6 stale catalogues reported |
| L10 | CI runs the gates and blocks on failure | ✅ blocking step, no continue-on-error |
| L11 | Aggregate machine-readable evidence produced | ✅ `gate.json`, generated from real output |
| L12 | Each gate has a negative proof with exit 1 | ✅ A–G, all exit 1 |
| L13 | Each gate has a positive proof | ✅ all 7 domains pass on valid data |
| L14 | Stage G registry gate still passes | ✅ 13 plugins, 22 regions, 0 writes |
| L15 | Stage I script-contract gate still passes | ✅ 290 passed, 0 failed |
| L16 | Stage J release-integrity gate still passes | ✅ 209 passed, 0 failed |
| L17 | Stage K governance gate still passes | ✅ 247 passed, 0 failed |
| L18 | No new unrelated runtime failures | ✅ only the 2 new gates are newly failing |
| L19 | No production writes | ✅ 0 |
| L20 | No content or Polylang mutation | ✅ 0 (2 fixtures created and reverted) |
| L21 | No Flutter/mobile repository touched | ✅ outside this repository, never accessed |
| L22 | No false allowlist or warning-only path | ✅ baseline labels only; it never suppresses |
| L23 | Documentation updated where required | ✅ §15 |
| L24 | Working tree clean at the end | ✅ verified by `git status` |

### Flutter / mobile confirmation

**The Flutter/mobile repository was not touched, not read, and not accessed.**
Every path in this stage is inside `/home/andrei/IdeaProjects/wordpress-website`.
No REST client, mobile test, or mobile workflow was modified. The scope freeze
was respected in full.

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_
