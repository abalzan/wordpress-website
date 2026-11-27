# Plan — Strengthen core regression coverage

| | |
|---|---|
| **Task title** | Strengthen core regression coverage for `conexao-data-model` and `conexao-content` |
| **Date** | 2026-11-27 |
| **Author / agent** | AI assistant using Copilot SDK in VS Code |
| **Branch** | `strengthen-regression-coverage` |
| **Repository baseline (starting SHA)** | `ceaa1c0` (master) |
| **Applicable skill(s)** | `wp-repository`, `wp-write-in-process-test` |
| **Plan status** | approved |

## 1. Scope

Add focused in-process PHP regression tests that close previously uncovered
high-risk behavioural gaps in two foundation plugins:

1. `wp-content/plugins/conexao-data-model/tests/test-content-type-registration.php` — new suite covering CPT registration flags (public/archive/rewrite/show_in_rest/exclude_from_search), admin-only directory CPTs staying non-public, the taxonomy shared-vs-translated policy, the 26-county master list, and meta-key registration (show_in_rest, sanitizer, auth_callback).
2. `wp-content/plugins/conexao-content/tests/test-shortcodes.php` — new suite covering `[conexao_grid]` (empty result, matching posts, hidden-event exclusion, external URL links) and `[conexao_blog_categories]` (category pills, "Todos", blog link).

No production code changes. No content, route, or English/Polylang changes.

## 2. Explicit non-goals

- No new frameworks (no PHPUnit, no Playwright/Cypress).
- No changes to `plugins.json`, the plugin registry, or generated docs.
- No changes to production code except reverting a temporary negative-proof edit.
- No HTTP acceptance matrix rows (the new coverage is behavioural, in-process).
- No coverage of `conexao-admin-ux`, `conexao-event-runtime`, `conexao-commemorative`, or other plugins — those already have suites; the gap is in the two foundation plugins above.

## 3. Relevant authoritative documents

- `AGENTS.md` — orientation, safety rules, plugin registry, workflow.
- `docs/engineering-standard.md` — §0 change-safety, §8 testing, §14 compliance checklist.
- `docs/testing.md` — three-layer model, shared bootstrap, assertion API, suite naming.
- `docs/content-model.md` — CPT and taxonomy definitions owned by `conexao-data-model`.
- `docs/routing.md` — canonical URL structure, `/empregos/` as a static page.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-write-in-process-test` |
| **Its preconditions** | `tests/bootstrap.php` is the only wp-load.php location; suites end with `test_finish()`; read-only by default; fixtures cleaned up. |
| **Its guardrails** | No production writes, no arbitrary DB IDs, deterministic, use `tests/lib/assertions.php`. |
| **Its verification commands** | `./scripts/run-tests.sh --only <component>` |
| **Its evidence location** | `docs/evidence/<date>-<stage>/` |

## 4. Current-state findings

- `conexao-data-model` has exactly 1 existing test (`test-leisure-coordinate-rest-permissions.php`, 74 assertions). No coverage for CPT registration flags, taxonomy policy, county master list, or meta registration.
- `conexao-content` has zero tests. Its shortcodes (`conexao_grid`, `conexao_blog_categories`) are runtime behaviour with no regression coverage.
- Baseline: `./scripts/run-tests.sh --scripts-static` passes (19 suites, 0 failed). `./scripts/lint.sh` passes (syntax clean, no new PHPCS violations, PHPStan clean).
- The `job` CPT has `has_archive => false` so `/empregos/` stays the static Jobs Landing page; admin-only CPTs (`recruitment_agency`, `permit_employer`) are non-public with no archive/rewrite.

## 5. Proposed approach

Add two new standard-compliant in-process suites following the existing
`test-<area>-<behaviour>.php` convention, using `tests/bootstrap.php` and
`tests/lib/assertions.php`. Each suite is read-only, creates its own
fixtures, cleans up, and ends with `test_finish()`.

Options considered:
- Add to an existing suite file: rejected — would mix concerns and make the diff less reviewable.
- Add HTTP acceptance rows: rejected — the protected behaviour (CPT flags, taxonomy policy, shortcode output) is best proven in-process where the actual PHP objects and functions are directly inspectable.
- Introduce PHPUnit: rejected — the in-process harness is the established contract (engineering standard §8).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-data-model/tests/test-content-type-registration.php` | New file | CPT/taxonomy/meta registration regression coverage |
| `wp-content/plugins/conexao-content/tests/test-shortcodes.php` | New file | Shortcode behavioural regression coverage |
| `docs/plans/2026-11-27-strengthen-regression-coverage.md` | New file | This plan (required for multi-file work) |

## 7. Files explicitly expected NOT to change

- `wp-content/plugins/conexao-data-model/conexao-data-model.php` — production code stays untouched (a temporary negative-proof edit was made and reverted).
- `plugins.json` — plugin set and load order unchanged.
- `docs/engineering-standard.md`, `docs/testing.md` — no architecture or test-model change.
- All existing test files — none weakened or removed.

## 8. Content / data impact

- Records created / updated / deleted: temporary posts/terms created and deleted within each suite run.
- Portable identifier strategy: N/A (no cross-environment identity used).
- Does this write content? Yes, but only temporary fixtures inside test processes, cleaned up at suite end.
- PT content changed? No.

## 9. Route / HTTP impact

- Routes added, removed or changed: none.
- Redirects needed: none.
- Sitemap coverage: none.
- HTTP matrix rows to add or update: none.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none (the test only *asserts* the existing classification).
- Is PT immutable in this change? Yes.
- B1/B2: N/A.
- Canonical, hreflang, language-switcher: unaffected.
- Language-scoped cache keys: unaffected.
- Completeness gate: unaffected.

## 11. Security impact

- Capabilities checked: the test asserts protected meta keys declare a callable `auth_callback` (not `__return_true`/`__return_false`).
- Nonces added/changed: none.
- Sanitisation / escaping: the test asserts sanitize_callbacks are registered.
- SQL: none.
- REST surface change: none (the test asserts the existing surface).
- Secrets: none.

## 12. Performance impact

- Queries added or removed: none (test-only).
- Caching: none.
- Assets or images: none.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | no |
| Will a deploy, upload or activation happen? | no |
| Who performs it, and how? | N/A |
| What is the verification of the production result? | N/A |

## 14. Test plan

- In-process suite(s) to add: `conexao-data-model/tests/test-content-type-registration.php`, `conexao-content/tests/test-shortcodes.php`.
- Acceptance area / matrix rows: none.
- Script contract impact: none.
- Registry drift check: not applicable (`plugins.json` unchanged).
- What constitutes a pass, numerically: all assertions pass, `test_finish()` exits 0.

## 15. Acceptance matrix plan

N/A — no HTTP acceptance rows added.

## 16. Rollback plan

Delete the two new test files and the plan file. No production state is touched.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| None | No documentation changes required | N/A |

## 18. Release implications

- Component version bump(s): none (no production code changes).
- Effect on artifact allowlist: none (`plugins.json` unchanged).
- Is a release in scope? No.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into unrelated refactoring | Medium | Keep to two test files; no production code changes. |
| Test assertions drift from implementation | Low | Verified by running suites; negative proof performed. |

## 20. Verification gates

1. `./scripts/run-tests.sh --only conexao-data-model` — 2 suites, 0 failed.
2. `./scripts/run-tests.sh --only conexao-content` — 1 suite, 0 failed.
3. `./scripts/lint.sh` — syntax clean, no new PHPCS violations, PHPStan clean.
4. Negative proof: temporarily break `job` CPT `has_archive` → test fails; revert → test passes.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: existing suites inspected and baseline recorded; at least one previously uncovered high-risk behaviour has additional automated regression coverage; new tests use the existing shared harness and conventions; new tests fail when their protected behaviour is deliberately broken; existing permanent invariant gates intact; no existing test weakened or removed; CI jobs continue to execute on push/PR/dispatch; build and release verification intact; documentation reflects the changes; change is focused and reviewable.
