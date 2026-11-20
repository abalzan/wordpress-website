# Plan — Remove the language selector from the mobile hamburger menu drawer

> Copy of `docs/templates/plan.md` filled in for this task. The template itself
> is not edited.

| | |
|---|---|
| **Task title** | Remove the language selector from the mobile hamburger menu drawer |
| **Date** | 20/11/2026 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `10a01f5898af2ad39359293dcb1fc0fee96e2168` |
| **Applicable skill(s)** | `wp-repository` (orientation, scope, review); no other skill applies — no new content type, taxonomy, admin screen, content write, or release |
| **Plan status** | draft |

## 1. Scope

Remove the `.mobile-menu-language` block (the PT | EN language switcher rendered by `conexao_language_switcher( array( 'context' => 'mobile' ) )`) from the mobile menu drawer markup in `header.php`. The PT | EN selector remains in the main mobile header (`.header-actions`, context "mobile") exactly as it is today — ahead of the Anuncie Aqui controls in source order, controlled by the same shared `CONEXAO_LANGUAGE_SWITCHER_ENABLED` flag and rendered by the same shared engine. Drop the CSS that only styled the drawer instance (`.mobile-menu-language` rule and the drawer `.language-switcher--mobile .language-switcher-item` rule) since that markup no longer exists. No behaviour, route, Polylang, or layout change to the drawer itself.

## 2. Explicit non-goals

- Do **not** touch the desktop language switcher (`.language-switcher--desktop` / context "desktop").
- Do **not** touch the main-header mobile switcher (`.header-actions .language-switcher--mobile`): requirement 2 is a keep, not a move — the selector stays where it is, ahead of the Anuncie Aqui button in source order; the deliberately measured hamburger + logo + PT|EN + search + CTA row is not reordered.
- Do **not** modify `inc/i18n/switcher.php`, `functions.php` (the flag), or any theme JS — none depends on `.mobile-menu-language`.
- Do **not** change drawer layout, spacing, search, theme toggle, CTA, or open/close behaviour.
- No content writes, no production access, no release.

## 3. Relevant authoritative documents

- `AGENTS.md` — scope, safety rules, workflow.
- `docs/engineering-standard.md` — mandatory engineering standard (§13.2 plan requirement).
- `docs/content-model.md` — language policy (switcher is presentation only; PT immutable).
- `docs/routing.md` — Polylang URL/hreflang behaviour is owned by the shared switcher engine, untouched here.

## 3a. Skill workflow

| Skill | Its preconditions | Its guardrails that bind this task | Its verification commands | Evidence location |
|---|---|---|---|---|
| `wp-repository` | plan doc at `docs/plans/<date>-<topic>.md`; report at `docs/reports/<date>-<stage>-<topic>.md` | reuse shared helpers; real-number verification; plan/build/verify/report | `php -l`; `./scripts/run-tests.sh --scripts`; re-run `tests/test-language-switcher-flag.php`; HTTP checks against `localhost:8080` | `docs/evidence/2026-11-20/` |

## 4. Current-state findings

- `wp-content/themes/conexao-br-irlanda/header.php` lines 208-215 render `.mobile-menu-language` inside `#mobile-menu .mobile-menu-content`, after `mobile-menu-header` and before `mobile-menu-theme`, via `conexao_language_switcher( array( 'context' => 'mobile' ) )`.
- `header.php` also renders two switchers in `.header-actions`: context "desktop" (line 139) and context "mobile" (line 150) — the latter is the main mobile-header PT | EN control, shown `<=1024px` by CSS.
- `header-nav.css`: `.mobile-menu-language` rule (lines 319-321) and `.language-switcher--mobile .language-switcher-item` drawer rule (lines 324-328) style only the drawer instance; two comments claim the drawer "keeps its own larger instance" and that below 1024px "the desktop instance is replaced by the drawer instance" — both will be stale after removal.
- No theme JS references `.mobile-menu-language` (grep over `assets/js/` clean). Only scratch reference: `carousel-work/langbar-measure.py` (untracked analysis tooling, not runtime, defensively guarded).
- Baseline test `tests/test-language-switcher-flag.php`: **54 passed, 1 failed — L60 "both header contexts go through the one shared renderer" fails because header.php has 3 `conexao_language_switcher(` call sites but the gate expects 2**. Removing the drawer call site makes it 2 → L60 passes. This is a pre-existing failure the change fixes.

## 5. Proposed approach

Option A (chosen): delete the drawer block wholesale from `header.php` (markup + PHP comment + renderer call), delete the now-unused CSS rules and stale comment clauses, leave everything else byte-identical.

Rejected options:
- CSS `display: none` on `.mobile-menu-language` — violates requirement 1 (must be removed from markup, not hidden).
- Moving the header mobile switcher to sit immediately before `.header-cta` — violates "make only the necessary changes" and requirement 6 (don't change the measured header row); "keep" means preserve current position.
- Deleting `conexao_language_switcher` or the flag — violates requirement 4 (engine preserved).
## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | Remove lines 208-215: `.mobile-menu-language` div, its comment, and the drawer `conexao_language_switcher( array( 'context' => 'mobile' ) )` call | Core requirement: selector out of the drawer markup |
| `wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` | Remove `.mobile-menu-language` rule + comment; remove `.language-switcher--mobile .language-switcher-item` drawer rule; fix two stale comments that described the drawer instance | Obsolete styling for markup that no longer exists; keep docs truthful |

## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/inc/i18n/switcher.php` — shared renderer and flag boundary; requirement 4.
- `wp-content/themes/conexao-br-irlanda/functions.php` — `CONEXAO_LANGUAGE_SWITCHER_ENABLED` unchanged.
- `wp-content/themes/conexao-br-irlanda/assets/js/main.js` — no dependency on `.mobile-menu-language`.
- `tests/test-language-switcher-flag.php` — unchanged; L60 should flip from FAIL to PASS after the markup change (2 call sites).
- `plugins.json` / any plugin — no registry change.

## 8. Content / data impact

- No records created/updated/deleted. No portable identifiers involved.
- Does not write content; the six-step contract does not apply.
- PT content is immutable here: the switcher is presentation-only; no PT/EN URLs, slugs, or record identity change.

## 9. Route / HTTP impact

- No routes added/removed/changed; no redirects; no sitemap change.
- No acceptance matrix rows added: the routing matrix already covers /en/ + hreflang/canonical; the switcher engine is untouched.

## 10. Polylang / English impact

- No translated post types/taxonomies affected. PT is immutable.
- Canonical/hreflang/language-switcher behaviour: unchanged (renderer untouched; same flag governs the remaining header call sites).
- No language-scoped cache keys affected.

## 11. Security impact

- No capabilities, nonces, SQL, REST endpoints, or secrets involved. The removed block rendered only an escaped URL via the existing shared renderer.

## 12. Performance impact

- No queries added/removed. One fewer rendered control in the drawer (negligible). No asset changes beyond deleting unused CSS rules.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a — production has no SSH/WP-CLI; no production action in scope |
| What is the verification of the production result? | n/a — no production action |

## 14. Test plan

- In-process: re-run `tests/test-language-switcher-flag.php` against the local stack — expect 55/55 pass (L60 3→2 call sites).
- Script contract: `./scripts/run-tests.sh --scripts` must stay green.
- HTTP acceptance: fetch `http://localhost:8080/` — assert no `mobile-menu-language`, exactly one `language-switcher--mobile` (header), PT/EN link with `hreflang="en"` present.
- No acceptance matrix rows added.

## 15. Acceptance matrix plan

None — no rows added; existing routing matrix unchanged.

## 16. Rollback plan

Restore the two edited files from git (working tree has no unrelated changes; `git status` clean at baseline). Rollback does not cover content because none is written.

## 17. Documentation plan

No document changes: this plan + a report in `docs/reports/`; the plan describes behaviour already covered by `docs/content-model.md` and `docs/routing.md` (unchanged). No procedure moves.

## 18. Release implications

No component version bumps; no plugin registry change; no release in scope (theme-only change; theme is built separately from plugin ZIPs).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Doing too much: reordering the header row or touching the renderer/flag | Medium | Strictly minimal diff; header and engine left byte-identical |
| Stale CSS comments misleading future maintainers | Low | Update the two comment clauses describing the removed drawer instance |
| L60 baseline failure mistaken for a regression | Low | Baseline captured pre-change: 54/55, L60 failing |

## 20. Verification gates

1. `php -l wp-content/themes/conexao-br-irlanda/header.php` — no syntax errors.
2. `grep -c 'mobile-menu-language' header.php` == 0; `grep -c 'conexao_language_switcher(' header.php` == 2.
3. Re-run `tests/test-language-switcher-flag.php` — 55 passed, 0 failed.
4. `./scripts/run-tests.sh --scripts` — exit 0.
5. HTTP fetch of `http://localhost:8080/` — absent: `mobile-menu-language`; present: `language-switcher--mobile`, `hreflang="en"`, PT/EN items.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: only the necessary files changed, no behaviour change outside scope, verification gates pass with real numbers (captured in the report), and the report lives in `docs/reports/` with evidence in `docs/evidence/2026-11-20/`.

