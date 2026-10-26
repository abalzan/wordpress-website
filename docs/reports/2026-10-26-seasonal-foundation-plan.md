# Plan — Seasonal and Commemorative Screen Foundation

| | |
|---|---|
| **Task title** | Seasonal and Commemorative Screen Foundation |
| **Date** | 2026-10-26 |
| **Author / agent** | Copilot AI |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `d469813137bdf3386ba27e029ce10bfabb6d8b4f` |
| **Applicable skill(s)** | `wp-repository` |
| **Plan status** | approved |

## 1. Scope

Establish a reusable, request-time seasonal/commemorative occasion foundation for the theme without activating a live screen or adding new production content. The implementation defines predictable date rules, shared eligibility logic, deterministic selection behavior, and a regression suite for fixed annual, variable annual, and one-off occasions.

## 2. Explicit non-goals

- No live visual seasonal screen on the homepage or any route.
- No new plugin, dependency, or database table.
- No production writes, deploys, plugin activation, or Polylang content changes.
- No hard-coded holiday catalogue in the core rendering logic.
- No attempt to reuse a normal event model for a commemorative display pattern.

## 3. Relevant authoritative documents

- `AGENTS.md`
- `docs/engineering-standard.md`
- `docs/content-model.md`
- `docs/routing.md`
- `docs/themes/conexao-br-irlanda.md`

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` |
| **Its preconditions** | Check repository architecture, theme boundaries, timezone conventions, and test harness requirements before writing code. |
| **Its guardrails that bind this task** | No production writes; no new plugin or dependency without a direct repo need; no parallel translation lifecycle; no feature screen activation. |
| **Its verification commands** | `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php` and `./scripts/run-tests.sh --php --only theme` |
| **Its evidence location** | `docs/evidence/2026-10-26-seasonal-foundation/` |

## 4. Current-state findings

The repository already contains the theme-style module split pattern and the canonical in-process test harness (`tests/bootstrap.php`). A reusable occasion model did not exist in the active theme codebase; the task therefore adds a lightweight configuration-driven foundation inside the existing theme loader rather than introducing a new plugin or scheduling service.

The repository’s theme uses WordPress date handling and expects the site to remain behaviour-neutral unless a seasonal condition is active, which matches the requested “foundation only” scope.

## 5. Proposed approach

Chosen option: a configuration-driven, request-time occasion model.

This was preferred over a content-driven model because it keeps the initial foundation lightweight, deterministic, and safe for annual recurrence without introducing admin complexity or a parallel translation lifecycle. It also avoids overloading the normal event content type or scheduling layer.

The selected design supports:

- `fixed_annual` rules (e.g. Christmas, Halloween)
- `variable_annual` rules (e.g. Easter)
- `one_off` rules (explicit date-based commemorations)
- a default 15-day lead-in window and inclusive occasion-day boundary
- a final selection function that returns the primary active occasion only when one is valid

Rejected options:

- Content-driven occasion registry: too heavy for a foundation-only iteration and creates admin content work before the lifecycle is proven.
- Reusing the event runtime as a commemorative model: not appropriate because normal public events already carry a different meaning and scheduling contract.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/occasion.php` | Added | Core date rules, timezone-aware eligibility logic, and selection helpers. |
| `wp-content/themes/conexao-br-irlanda/functions.php` | Modified | Loads the occasion module in the existing theme bootstrap ordering. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php` | Added / updated | Regression coverage for date resolution, recurrence, and selection behavior. |

## 7. Files explicitly expected NOT to change

- `plugins.json` — no plugin registry change
- `compose.yaml` — no environment change
- `wp-content/plugins/*` — no plugin-level architecture added
- production content / database fixtures — not part of this work

## 8. Content / data impact

- Records created / updated / deleted: none
- Portable identifier strategy: stable occasion IDs only (not local post IDs)
- Content writes: none
- PT content changed: no

## 9. Route / HTTP impact

- Routes added, removed or changed: none
- Redirects needed: none
- Sitemap coverage: unchanged
- HTTP matrix rows: none added

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none
- PT immutable in this change: yes
- B1/B2 handling: unchanged
- Canonical, hreflang and language-switcher behaviour: unchanged
- Language-scoped cache keys: not introduced by this foundation
- Completeness gate: n/a

## 11. Security impact

- Capabilities checked: none
- Nonces added/changed: none
- Sanitisation / escaping: no user input touched
- SQL: n/a
- REST surface change: none
- Secrets involved: none

## 12. Performance impact

- No new external requests or database queries introduced.
- Eligibility checks are computed per request and remain lightweight.
- No cache layer added; stale output is avoided by deterministic request-time evaluation.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | n/a |
| What is the verification of the production result? | n/a |

## 14. Test plan

- In-process suite(s): `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php`
- Acceptance area / matrix rows: none added
- Script contract impact: none
- Registry drift check: not applicable
- Pass condition: the occasion suite exits 0 and the theme-level PHP suite does not introduce new failures beyond existing unrelated baseline issues

## 15. Acceptance matrix plan

Not needed for the foundation-only scope; the model is validated through deterministic PHP tests.

## 16. Rollback plan

- Remove the new `inc/occasion.php` load from `functions.php`.
- Delete or restore the added occasion module and its regression suite.
- Because no production content or migration is involved, rollback is local and reversible without a site data restore.

## 17. Documentation plan

- This implementation is documented in the seasonal foundation report.
- No change to the content model or routing docs was required for this scope.

## 18. Release implications

- Not in scope for a release artifact or deployment step.
- No plugin registry or build manifest changes.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into full visual screen work | medium | Keep the foundation limited to lifecycle logic and selection only. |
| Overloading the normal event system | low | Reuse a separate, configuration-based model. |
| Hidden timezone drift | low | Resolve all dates through WordPress timezone when available and fall back to Europe/Lisbon. |

## 20. Verification gates

1. `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php`
2. `./scripts/run-tests.sh --php --only theme`

## 21. Completion criteria

The task is complete when the foundation is present in the theme, the regression test passes, and the repository-level theme PHP gate shows no new seasonal-specific regressions beyond known unrelated failures.

_Last verified: 2026-10-26 by seasonal foundation implementation_
