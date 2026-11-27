# Plan — Move the light/dark theme toggle from the hamburger menu into the main header

> Copy of `docs/templates/plan.md` filled in for this task. The template itself
> is not edited.

| | |
|---|---|
| **Task title** | Move the light/dark theme toggle from the hamburger menu into the main header |
| **Date** | 20/11/2026 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `238c1b74583ea2ba8b3829e1ae367d88f6af05f8` |
| **Applicable skill(s)** | `wp-repository` (orientation, scope, review); no other skill applies — no new content type, taxonomy, admin screen, content write, Polylang change, or release |
| **Plan status** | draft |

## 1. Scope

Move the existing `.theme-toggle` button out of the `.mobile-menu-theme` utility row inside `#mobile-menu` (the hamburger drawer) into `.header-actions` in the main header, immediately before the mobile language switcher (`.language-switcher--mobile`, rendered by `conexao_language_switcher( array( 'context' => 'mobile' ) )`). Remove the `.mobile-menu-theme` element from the drawer. Remove the now-obsolete `initThemeToggleRelocation()` JS (the button is rendered once, in the header, at every breakpoint, so no drawer ↔ header shuffle is needed). Adjust CSS so the mobile row is hamburger → logo → theme toggle → PT | EN → Anuncie Aqui while the desktop header appearance is byte-identical to today (theme toggle remains at the end of the desktop actions row via `order`, matching where `main.js` currently relocates it).

Theme behaviour is untouched: the same button element, the same `initThemeToggle()` click handler, localStorage persistence, `aria-pressed`/`aria-label`, moon/sun icon visibility via `data-theme`, and dark-mode.css tokens.
## 3. Relevant authoritative documents

- `AGENTS.md` — scope, safety rules, workflow (plan → build → verify → report).
- `docs/engineering-standard.md` — mandatory engineering standard (§13.2 plan requirement).
- `docs/content-model.md` — language policy (the header switcher stays; PT immutable).
- `docs/routing.md` — Polylang URL/hreflang behaviour untouched.
- `docs/themes/conexao-br-irlanda.md` — theme internals (CSS asset map).

## 3a. Skill workflow

| Skill | Its preconditions | Its guardrails that bind this task | Its verification commands | Evidence location |
|---|---|---|---|---|
| `wp-repository` | plan doc at `docs/plans/<date>-<topic>.md`; report at `docs/reports/<date>-<stage>-<topic>.md` | smallest safe diff; real-number verification; plan/build/verify/report | `php -l`; `node --check` (JS syntax); `./scripts/run-tests.sh --scripts`; HTTP assertions on `http://localhost:8080` | `docs/evidence/2026-11-20/` |

## 4. Current-state findings

- `wp-content/themes/conexao-br-irlanda/header.php` renders `.theme-toggle` only inside `.mobile-menu-theme` in the drawer (after `mobile-menu-header`, before `mobile-menu-search`).
- `header.php` renders two language switchers in `.header-actions`: context "desktop" and context "mobile"; the mobile one is the PT | EN control shown `<=1024px`.
- `main.js`: `initThemeToggle()` binds `document.querySelector('.theme-toggle')` once at DOMContentLoaded; `initThemeToggleRelocation()` moves the same node into `.header-actions` on desktop and back into `.mobile-menu-theme` on mobile/tablet.
- `header-nav.css`: `.mobile-menu-theme` drawer rule; `@media (max-width: 1024px)` sets `.header-actions { gap: 8px; margin-left: auto; }` and reveals `.header-actions .language-switcher--mobile` + `.header-cta-compact`.
- `dark-mode.css`: `.theme-toggle` 44×44 styling + icon visibility keyed on `[data-theme="dark"]` — location-independent.
- `halloween.js` line 178: `'.theme-toggle, ' +` inside `EXCLUDED_SELECTORS` — class-based, unaffected by relocation.
- No PHP test references `mobile-menu-theme` or `.theme-toggle`.

## 5. Proposed approach

**Chosen option — render once in the header, CSS order for desktop.**

1. `header.php`: render the existing `.theme-toggle` button markup in `.header-actions` immediately before the `conexao_language_switcher( array( 'context' => 'mobile' ) )` call; delete the `.mobile-menu-theme` div (and its documentation comment) from the drawer.
2. `main.js`: delete `initThemeToggleRelocation()` and its call; keep `initThemeToggle()` unchanged (single node, listener persists, no relocation).
3. `header-nav.css`:
   - Remove the `.mobile-menu-theme` rule and its comment.
   - Add `.header-actions .theme-toggle { order: 1; }` in the base (desktop) stylesheet so the toggle stays at the end of the desktop actions row — identical to today's JS-relocated position.
   - In the `@media (max-width: 1024px)` block, reset `.header-actions .theme-toggle { order: initial; }` so DOM order governs the mobile row: theme toggle → PT | EN → Anuncie Aqui.
4. `dark-mode.css`: untouched.

**Rejected option — keep JS relocation:** moving the node at the 1024px breakpoint risks state/listener sync issues and contradicts "one functional toggle, no relocation" guidance; CSS `order` achieves desktop preservation with zero DOM mutation.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | modified | Move theme-toggle markup into `.header-actions`; remove `.mobile-menu-theme` from drawer |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | modified | Remove `initThemeToggleRelocation()` + call; keep `initThemeToggle()` |
| `wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` | modified | Remove `.mobile-menu-theme` rule; add desktop `order: 1` + mobile `order: initial` for `.theme-toggle`; tidy stale comments |
| `docs/plans/2026-11-20-move-theme-toggle-to-main-header.md` | added | This plan |
| `docs/reports/2026-11-20-move-theme-toggle-to-main-header.md` | added | Completion report |
| `docs/evidence/2026-11-20-move-theme-toggle-to-main-header/` | added | Verification numbers |

## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` — theme tokens and `:root[data-theme="dark"]` selectors stay; `.theme-toggle` styling is class-based.
- `wp-content/themes/conexao-br-irlanda/assets/js/presentations/halloween.js` — `.theme-toggle` reference is class-based exclusion; unaffected.
- `wp-content/plugins/*`, `plugins.json` — no plugin/registry change.
- Any translated content / Polylang tables — PT immutable; no language records touched.

## 8. Content / data impact

- Records created / updated / deleted: none.
- Portable identifier strategy: n/a (no content write).
- Does this write content? No.
- PT content changed? No.

## 9. Route / HTTP impact

- Routes added, removed or changed: none.
- Redirects: none. Sitemap: none.
- HTTP matrix rows: none added (routing matrix unchanged; presentation-only).

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none.
- PT immutable: Yes.
- Canonical / hreflang / language-switcher behaviour: unchanged (renderer and both call sites untouched).
- Language-scoped cache keys affected: none.

## 11. Security impact

- Capabilities checked: none needed (presentation-only).
- Nonces added/changed: none.
- Sanitisation / escaping: the moved button markup keeps its existing `esc_attr_e()` aria-label.
- SQL: none. REST surface: none. Secrets: none.

## 12. Performance impact

- Queries: none added or removed.
- Assets: no new assets; ~one rule block removed from CSS; no JS payload change beyond deleting the relocation function.
- No measurable runtime impact; not instrumented.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | n/a — no production action in scope |
| What is the verification of the production result? | n/a — no production action |

## 14. Test plan

- In-process: no suite asserts on `.mobile-menu-theme`/`.theme-toggle`; `test-language-switcher-flag.php` unaffected (still exactly 2 `conexao_language_switcher(` call sites). Re-run `./scripts/run-tests.sh --php` against the local stack if a test DB is available; otherwise script-contract + HTTP acceptance are the verification.
- Script contract: `./scripts/run-tests.sh --scripts` must stay green (modulo the two pre-existing, unrelated failures).
- HTTP acceptance: fetch `http://localhost:8080/` — assert `.mobile-menu-theme` absent (0), exactly one `.theme-toggle` in rendered HTML located in `.header-actions` before `.language-switcher--mobile`, zero `.theme-toggle`/`.mobile-menu-theme` inside `#mobile-menu`, PT/EN + CTA present, desktop (`>1024px` simulation via CSS contract) unchanged.
- No acceptance matrix rows added.

## 15. Acceptance matrix plan

None — no rows added; existing routing matrix unchanged.

## 16. Rollback plan

`git checkout -- wp-content/themes/conexao-br-irlanda/header.php wp-content/themes/conexao-br-irlanda/assets/js/main.js wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` restores the pre-change state (drawer theme toggle + JS relocation return). Rollback covers no content because none was written.

## 17. Documentation plan

No canonical document changes: behaviour is covered by existing docs (`docs/content-model.md`, `docs/routing.md`, theme internals map) which remain accurate. Plan + report + evidence per `AGENTS.md` §workflow. No procedure moves into or out of a skill.

## 18. Release implications

No component version bumps; no plugin registry change; no release in scope (theme-only change).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Doing too much: reordering the header row or touching the theme engine | Medium | Strictly minimal diff; `initThemeToggle()` untouched; dark-mode.css untouched |
| Desktop layout shift (toggle moving from row-end to row-start) | Low | CSS `order: 1` on desktop preserves the current end-of-row position; verified by curl + visual-position assertion |
| Stale CSS comments misleading future maintainers | Low | Remove `.mobile-menu-theme` rule + comment; update header comment |
| Two toggles briefly in DOM during transition | Low | PHP renders one button; JS relocation removed atomically with the markup move |

## 20. Verification gates

1. `php -l wp-content/themes/conexao-br-irlanda/header.php` — no syntax errors.
2. `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js` — JS syntax OK.
3. `grep -c 'mobile-menu-theme' header.php` == 0; `grep -c 'theme-toggle' header.php` == 1 (header-actions only).
4. `grep -c 'initThemeToggleRelocation' assets/js/main.js` == 0.
5. `./scripts/run-tests.sh --scripts` — exit 0 (two pre-existing unrelated failures allowed, same as baseline).
6. HTTP fetch of `http://localhost:8080/` — absent: `mobile-menu-theme`; present exactly once: `theme-toggle`, positioned after `mobile-menu-toggle`+`logo` and before `language-switcher--mobile`; zero `theme-toggle` inside `#mobile-menu`; PT/EN items and `header-cta-compact` present.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: only the necessary files changed, no behaviour change outside scope, verification gates pass with real numbers (captured in the report), report in `docs/reports/` with evidence in `docs/evidence/2026-11-20/`.
