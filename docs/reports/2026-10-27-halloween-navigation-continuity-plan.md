# Plan — Halloween navigation animation continuity

| | |
|---|---|
| **Task title** | Halloween navigation animation continuity |
| **Date** | 2026-10-27 |
| **Author / agent** | Copilot |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `064324e` |
| **Applicable skill(s)** | `wp-frontend-perf`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Keep the existing bat, pumpkin, and ghost navigation effects visible for a total
of approximately one second across eligible same-origin full-page navigation.
Use the existing Halloween occasion marker as the sole eligibility gate.

## 2. Explicit non-goals

Do not change routes, menus, the seasonal date lifecycle, the welcome-card
dismissal, production state, or mobile-app code. Do not intercept or delay
navigation, add a framework, or alter unrelated animations.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/frontend.md`,
`docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, and the
`wp-frontend-perf` / `wp-update-docs` workflows.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-frontend-perf` with `wp-update-docs` |
| **Its preconditions** | Local WordPress responds; no query/cache change is planned. |
| **Its guardrails that bind this task** | Reuse the current bundle and styles; no new dependencies or transient keys; record limitations. |
| **Its verification commands** | Focused theme suite, `./scripts/lint.sh`, and `./scripts/run-tests.sh` where supported. |
| **Its evidence location** | `docs/evidence/2026-10-27-halloween-navigation-continuity/` only if machine evidence is produced. |

## 4. Current-state findings

At `064324e`, `main.js` listens passively for eligible primary-button
`a.nav-link` clicks and creates one of three decorative animations. It does not
prevent default, but the outgoing document owns the animation and is destroyed
by full-page navigation. CSS durations are 0.9, 1.1, and 1.2 seconds; current
cleanup timers are 0.95, 1.15, and 1.25 seconds. The header marker is emitted
only when `conexao_seasonal_variant()` returns `halloween`. The local site
responds with HTTP 200.

## 5. Proposed approach

Use a versioned Halloween-specific `sessionStorage` record containing only an
allowlisted effect, start time, target path, and bounded viewport coordinates.
Use `performance.timeOrigin + performance.now()` where available, with a
validated wall-clock fallback. The destination consumes and removes the record
before validating a fresh Navigation Timing `navigate`, same-origin
referrer/target, active Halloween marker, reduced motion, expiry, and numeric
bounds; it resumes the CSS animation with a negative delay. The record expires
after 1.5 seconds and the visual lifetime is 1 second total. The source click
remains passive and native.

Reject View Transitions because it would add a broader cross-document
transition dependency without preserving the current CSS effect as simply.
No existing persistent-animation mechanism was found.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Versioned one-shot handoff and remaining-time resume | Preserve the effect across reload. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | Regression assertions for eligibility, timing, validation, and no interception | Keep the behavior contract executable. |
| `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` | Document continuity and accessibility | Keep canonical frontend facts current. |
| `docs/reports/2026-10-27-halloween-navigation-continuity-{plan,report}.md` | Plan and measured outcome | Dated history. |
| `docs/README.md`, `docs/reports/README.md` | Index plan and report | Documentation index contract. |

## 7. Files explicitly expected NOT to change

`inc/occasion.php`, `header.php`, navigation markup, CSS asset files,
`inc/assets.php`, routes, content, plugins, and release/deploy files.

## 8. Content / data impact

- Records created / updated / deleted: none.
- Portable identifier strategy: not applicable.
- Content write: no.
- PT content changed: no.

## 9. Route / HTTP impact

No route, redirect, canonical, hreflang, or sitemap change. The local homepage
is used only to establish runtime availability.

## 10. Polylang / English impact

None. The marker remains sourced from the existing primary active occasion;
language, translations, and cache keys are unchanged.

## 11. Security impact

No endpoint, request, database, or secret change. Client state is untrusted:
strict JSON shape, version, effect allowlist, same-origin destination/referrer,
finite timestamp and coordinates, expiration, and immediate one-shot removal.
SVG markup remains static and allowlisted.

## 12. Performance impact

No query, image, stylesheet, or new asset. One short-lived session-storage
write/read per eligible click; no new transient or cache invalidation.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **No** |
| Will a deploy, upload or activation happen? | **No** |
| Who performs it, and how? | Not applicable. |
| What is the verification of the production result? | No production action is in scope. |

## 14. Test plan

Extend the discovered in-process theme suite to check eligibility marker
gating, effect allowlisting, one-second total timing, remaining-time resume,
expiry and one-shot cleanup, refresh/back-forward non-replay, same-origin/native
navigation constraints, reduced motion, and storage-failure resilience. Run
focused and repository checks; report any environment limitations without
weakening gates.

_Last verified: 2026-10-27 by Halloween navigation continuity_
