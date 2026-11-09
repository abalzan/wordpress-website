# Commemorative Event Creation

## Purpose

Plan, implement, test, review and document a seasonal/commemorative event in the Conexão BR WordPress theme/engine — reusing the existing `conexao-commemorative` engine and the theme-owned presentation registry, without inventing a second engine, resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path or timer.

Use when adding a new event record, enhancing an existing event/package, adding a landing page, debugging preview/modal/dispatch behaviour, or preparing a commemorative event for release.

## When to use

A task asks for a new commemorative/seasonal event, a change to an existing event's schedule/package/placement/assets, a commemorative landing page, or a review/fix of commemorative presentation (popup/modal, landing dispatch, ambient decoration, preview, cache boundary).

## When not to use

- A new post type, taxonomy or meta field — `wp-add-content-type`.
- A maintainer capability in wp-admin — `wp-add-admin-screen`.
- A content/term/media write that is not an event — `wp-content-change`.
- English coverage for an existing type — `wp-translation-rollout`.
- A release, deploy or production activation — `wp-release-deploy` / `wp-production-operations`.
- Anything that would add a new engine, resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path or timer merely to implement a visual event — that is an architecture expansion requiring separate, explicit approval.

## Required reading

- `AGENTS.md` — orientation, scope, safety rules, authoritative-source map.
- `docs/engineering-standard.md` — the authoritative engineering standard; where it disagrees with this skill, the standard wins.
- `docs/commemorative-events.md` — the commemorative presentation system: event vs package vs placement vs component, the theme-owned registry and dispatcher, route kinds, conditional assets, preview/cache boundaries, and the "how to add an event" runbook.
- `docs/plugins/conexao-commemorative.md` — the commemorative events engine: scheduling rules, occurrence-year identity, priority + manual annual precedence, one-active-event resolution with reasons, signed administrator preview, targeted activation-boundary cache invalidation, the enforced Polylang single-owner rule, the deliberate no-REST deviation.
- `docs/commemorative-content.md` — the commemorative content playbooks: one seven-field brief per package, the content calendar, the manual overlap watchlist.
- `docs/themes/conexao-br-irlanda.md` — theme internals: templates, template parts, assets, `inc/` modules, the welcome controller contract.
- `docs/routing.md` — URLs, archives, filters, redirects, sitemap and the `/en/` layer.
- `docs/content-model.md` — content types, taxonomies, meta, language policy.
- `docs/testing.md` — how to run and write tests, the three-layer model, the permanent invariant gates.
- `docs/templates/plan.md` — the plan template (copy it to `docs/plans/<date>-<topic>.md`; never fill it in place).
- `docs/templates/report.md` — the completion report template (copy it to `docs/reports/<date>-<stage>-<topic>.md`).
- `docs/development.md` — local setup and the documented local commands for the Docker stack.

## Authoritative sources

| Fact | Read it from |
|---|---|
| Event record, schedule, selection, resolution, eligibility, precedence, signed preview, cache boundary (the engine contract) | `docs/plugins/conexao-commemorative.md` |
| Package registration, package-to-event mapping, placements, route kinds, dispatcher (the theme presentation registry) | `docs/commemorative-events.md` + `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` |
| Package briefs (the seven fields), content calendar, manual overlap watchlist | `docs/commemorative-content.md` |
| Theme templates, components, CSS, welcome controller | `docs/themes/conexao-br-irlanda.md` |
| Plugin-level facts only (load order, class, status, build, mount, production) | `plugins.json` |
| Routes, redirects, sitemap, `/en/` layer | `docs/routing.md` |
| Test model, permanent gates, browser-layer contract | `docs/testing.md` |
| Local environment setup and safe local commands | `docs/development.md` |

These responsibilities are disjoint. `plugins.json` carries **plugin-level facts
only** — never theme package or presentation facts — and the theme presentation
registry never carries plugin facts. Never copy a fact from one registry into
the other.

## Preconditions

- Current branch, HEAD, worktree status and stash count are recorded (do not
  change or disturb them).
- The work stays inside the existing engine plus theme registry: no new engine,
  resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path or
  timer. Any such expansion is a separate, explicitly approved change.
- The event brief and the user-facing decisions — copy, artwork, schedule,
  routes and placements, modal and animation behaviour — are confirmed with the
  user, not assumed.
- The local environment is inspected before it is used: check the current stack
  (`docker compose ps`; setup documented in `docs/development.md`) and reuse an
  already-running environment. Do not automatically start, recreate or rebuild
  containers, reset volumes, seed data or mint an administrator session — follow
  the documented local procedure and make those side effects deliberate. If the
  needed environment is unavailable, report the exact blocker and fall back to
  non-mutating checks; ask before any material environment, database or session
  change. Verification never targets production: `scripts/run-tests.sh`, the
  acceptance client and the browser scripts refuse a production host, and that
  refusal must be preserved.
- The HTTP acceptance or browser layer runs only against the running local
  stack; a signed preview additionally needs a local administrator session,
  minted locally and never committed.
- A task-specific plan exists at `docs/plans/<date>-<topic>.md` — copied from
  `docs/templates/plan.md`, which states that destination — for multi-file work
  or anything touching content, routes or English/Polylang (engineering standard
  §13.2; a substantive event implementation crosses theme, content, assets and
  tests and usually routes or languages, so it meets that condition by nature).
  The canonical templates are edited only when the task itself is to improve a
  template.

## Steps

### Phase 1 — Understand and plan

1. Read `AGENTS.md`, `docs/commemorative-events.md`, `docs/plugins/conexao-commemorative.md`, `docs/commemorative-content.md` and the relevant event reports (Halloween and Christmas reports under `docs/reports/`).
2. Identify whether this is a new event or an enhancement of an existing event/package.
3. Prefer enhancing an existing event/package where appropriate; do not duplicate event records/packages without justification.
4. Establish requirements: event name/slug, schedule, languages, target routes/placements, content, artwork, popup/modal expectations, animations, preview, CTA, accessibility.
5. Ask the user only for genuinely missing decisions; do not assume artistic/content choices that affect the user-facing experience.
6. Complete or update the canonical **seven-field package brief** for this package in `docs/commemorative-content.md` (occasion summary, signature motif, palette/tokens, motion/energy, copy voice, modules, do-not) and record the approved copy, artwork and user decisions there. Do not create a competing brief anywhere else.
7. Record the event in the canonical content calendar and check the manual overlap watchlist in `docs/commemorative-content.md` §4 — the runbook requires both (step 6 of `docs/commemorative-events.md` §5). Capture schedule, timezone (`wp_timezone()`), occurrence year, lead-in and any priority or manual annual precedence (`_ce_year_precedence`) that applies under the engine contract; the engine never detects an overlap, so an overlap winner is an editor's annual-precedence decision.
8. Prepare a concise task-specific plan at `docs/plans/<date>-<topic>.md` (from `docs/templates/plan.md`) whenever the task is multi-file work or anything touching content, routes or English/Polylang — a substantive event implementation meets this condition by nature — and identify scope boundaries.
9. Confirm current branch/HEAD/worktree/stashes before any code work.

### Phase 2 — Architecture mapping

1. Trace the event through existing engine data → schedule/selection → presentation registry → package → placement context → template → assets/scripts/styles.
2. Verify actual APIs, hooks, registries, naming, file conventions, and package loading conditions before coding.
3. Identify canonical/shared vs translated metadata and enforce existing Polylang ownership rules.
4. Confirm event selection and package registration; avoid introducing redundant dispatch logic.
5. For landing pages, trace both page-owned and event-owned context and test precedence explicitly (the page-owned `landing_page` placement must resolve before the event-driven copy of the same placement id — see `docs/reports/2026-11-06-christmas-landing-page-active-dispatch-fix.md`).
6. For popups, trace the entire render path, JS controller, CSS, preview state, fallback markup, and dismissal lifecycle. Do not treat `dialog open` as equivalent to a modal.
7. Keep any newly proposed mechanism out of scope unless separately approved.

### Phase 3 — Assets and creative experience

1. Keep artwork and copy supplied/approved by the user unchanged unless asked to edit them.
2. For new assets, document source, dimensions, formats, alt text, responsive variants, and optimization.
3. Prefer optimized WebP/AVIF derivatives when supported by project conventions; retain source/master assets according to repository practice.
4. Avoid large or blocking assets and unnecessary third-party dependencies.
5. For animation: prefer CSS/SVG and small JS triggers; use a coherent visual motif, limited frequency, short duration, and a `prefers-reduced-motion` fallback. Motion must never block reading, navigation or interaction.
6. Timing has three distinct sources; never conflate them. **Ambient decoration defaults** derive from the package's declared energy through the shared energy table in `docs/commemorative-events.md` §3 — that table is the designed default path, not a value borrowed from another event. An **approved package-specific ambient override** (the Halloween navigation-surprise cadence is the historical example) is recorded explicitly in that package's seven-field brief in `docs/commemorative-content.md`; it overrides the shared energy default, is never replaced by it, and is never copied into another package. **Interaction-specific/local effects** — their triggers, cadence, frequency, delay and duration — must be defined and approved for the event in its brief; never copy a value from Halloween, Christmas or any other package. The Halloween cadence remains a Halloween-approved requirement and regression lesson, never a default for new packages.
7. Ambient decoration runs on the existing shared ambient scheduler; do not create a second ambient scheduler, a new global animation system or an architectural timer. Small local CSS/SVG interaction effects may use the existing package pattern where appropriate.
8. Define how animations trigger, where they are allowed, and how they stop/avoid repeated distraction.

### Phase 4 — Implementation

1. Inspect the implemented packages as **examples, not templates to copy** — `halloween` and `christmas` under `template-parts/commemorative/`. Read the current package registry (`docs/commemorative-events.md` + `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php`) and the package's brief first; reuse the shared components and controllers and follow current conventions. Add a package-specific folder/file only where the current architecture requires it, and never carry another event's schedule, storage key, modal state machine, copy, visuals, animation cadence, route assumptions or asset-loading behaviour across without validating that they apply to this event.
2. Keep event-specific styles/scripts scoped to package selectors and enqueue/load only where the event/placement is eligible.
3. Use existing shared star/ornament/motif or design primitives where suitable.
4. Keep engine logic separate from theme presentation.
5. Preserve no-JS usability.
6. Keep HTML semantics valid, avoid duplicate IDs, and ensure dialog markup is valid.
7. Include close and focus behaviour, scroll-lock/restoration, keyboard operation, accessible names, and session behaviour for modals.
8. Ensure CTA paths are language-aware and valid.
9. Avoid changing unrelated templates, global styling, event engine internals, or unrelated data.
10. Do not add a new event engine, resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path, or timer.

### Phase 5 — Tests and verification

**Scope the run to the change.** Run the suites and browser checks that cover
the feature being changed. Run the Christmas-specific checks when shared
controllers, shared dispatch, shared modal/animation behaviour or Christmas
itself changes. For any other event, add or run equivalent event-specific
checks — Christmas-only tests never prove another event works. The shared
engine/theme invariant gates documented in `docs/testing.md` remain mandatory
for every event. If a command named below is missing, renamed or no longer
relevant, take the current command from `docs/testing.md` or `scripts/README.md`
and cite that source.

**PHP/unit/integration**
- Existing event registry/package contracts.
- Event active/inactive and schedule boundaries.
- Correct route/placement dispatch and no duplicate rendering.
- Landing page eligibility and PT/EN context.
- Shared metadata ownership and translation isolation.
- Preview authorization and preview does not trigger live visitor behaviour unexpectedly.
- CPT/public exposure and privacy checks where relevant.
- Cache invalidation and event transition behaviour where relevant.

**Browser**
- Homepage, event landing page, intended routes, unrelated pages, inactive/out-of-window state, and signed preview.
- Portuguese and English; ensure copy does not cross languages.
- Viewports including small mobile (e.g. 320px), typical mobile, tablet, and desktop.
- Modal top-layer/centering, no inline fallback card when modal is active, close/Escape, CTA, focus management, scroll lock/restoration, no overflow, no layout shifts, no broken images, console/page errors, failed requests.
- No-JS fallback.
- Reduced motion.
- If available, Chromium and WebKit/Safari. If unavailable, state limitation clearly; do not claim tested.

**Repository**
- Run relevant tests first, then broader suite when feasible.
- Lint/PHPCS/PHPStan, registry checks, documentation drift checks, and translation/POT checks according to project conventions.
- Compare full-suite failures against a recorded baseline; do not claim pre-existing failures as newly introduced or claim a green suite when it is not green.
- Do not edit baseline files just to make checks pass.

### Phase 6 — Review and report

1. Perform a code review of the diff, including architecture, security, performance, accessibility, i18n, cache, and regressions.
2. Report: what changed and what did not; root cause for bug fixes; files changed; tests with exact pass/fail counts; baseline failures and comparison; browser/device coverage and limitations; preview behaviour and URLs used (non-sensitive); git preservation status; deferred items and release/production status.
3. Create/update a dated report following repository conventions and index it if reports are indexed.
4. Clearly separate local verification from production/release verification.

### Phase 7 — Git and production safeguards

1. Never use destructive git operations (`reset --hard`, `clean`, forced checkout, stash deletion, or restoring user changes) without explicit approval.
2. Preserve worktree modifications and stashes. Do not assume all existing changes belong to this task.
3. Do not commit, push, deploy, release, activate plugins/events, or modify production without explicit authorization.
4. Capture branch, HEAD, worktree, and stash state before and after.
5. If unrelated changes or unexpected HEAD movement occurs, stop and report it; do not overwrite or "clean up."

## Guardrails

- **No new engine, resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path, or timer** merely to implement a visual event. Reuse existing registries, packages, placements, templates, and helpers whenever possible.
- **The commemorative engine/plugin owns event data, scheduling, resolution, eligibility, and the shared engine contract.** The theme owns visual presentation registry/dispatcher and presentation packages.
- **A selected event maps to a theme-owned package and only the placements/routes configured for that package.**
- **Commemorative CPT remains private** and must not leak through public routes, archives, REST, sitemap, or unintended query surfaces.
- **Respect Polylang's canonical/shared metadata ownership.** Event-shared metadata must not be copied/edited independently in translated records; language-specific text remains translated.
- **Preserve package slug, language, template, and opt-in eligibility checks.**
- **Preview follows the signed contract and persists nothing.** The engine's preview (`?conexao_preview=` + `_wpnonce` + `_ce_ts`, capability- and nonce-gated) must not alter live scheduling or normal visitor behaviour, and the documented contract is that it persists nothing. A package's preview behaviour — auto-open or manual trigger, close behaviour, whether a fallback displays, and whether dismissal persists — must follow the current shared controller contract and the approved event brief; verify that contract in `docs/plugins/conexao-commemorative.md`, `docs/commemorative-events.md` and `docs/themes/conexao-br-irlanda.md` before wording or coding it any other way. A preview must not write a normal visitor's dismissal/session key unless the current documented contract explicitly says otherwise, and another event's key names, storage duration or modal cadence are never copied in without an explicit requirement.
- **Do not hard-code event IDs, page IDs, slugs, languages, dates, or production-specific URLs** when the architecture provides a registry or contextual API.
- **Keep cache invalidation and event-boundary behaviour correct**; avoid stale event display around activation/deactivation boundaries.
- **No production write** — no production access, activation, DB or content change, release or deployment — unless the task explicitly authorises it.
- **Event output should appear only in intended placements and routes.** Preserve homepage and unrelated-page content/layout when an event is inactive or not eligible.
- **For landing-page dispatch, page-owned landing context must not be shadowed by event-driven context.**
- **Avoid empty `<main>` elements, duplicate event rendering, unexpected fallback cards, orphaned layout space, and background/inline rendering when a modal is intended.**
- **Modal/popups must be real accessible dialogs** (proper top-layer `showModal()` behavior where supported), not merely elements with an `open` attribute styled like a popup.
- **Existing event artwork, copy, and design must not be redesigned** unless the user explicitly requests it.
- **Do not let an event's CSS/JS affect unrelated pages or other event packages.**
- **Use progressive enhancement; no-JS fallback must remain usable.**
- **Respect reduced-motion preferences.** Motion should be subtle, short, nonessential, and non-disruptive, and must never block reading, navigation or interaction.
- **Animation timing has three distinct sources — never conflate them.** *Ambient decoration defaults* derive from the package's declared energy through the shared energy table in `docs/commemorative-events.md` §3; that table is the designed default path, never a value borrowed from Halloween, Christmas or any other event. An *approved package-specific ambient override* (the Halloween navigation-surprise cadence is the historical example) is recorded explicitly in that package's seven-field brief in `docs/commemorative-content.md`; it overrides the shared energy default, is never replaced by it, and is never copied into another package. *Interaction-specific/local effects* — their triggers, cadence, frequency, delay and duration — must be defined and approved for the event in its brief; never copy them from Halloween, Christmas or any other package. The Halloween cadence is a Halloween-approved requirement and regression lesson, never a default for new packages. Ambient decoration runs on the existing shared scheduler; a second ambient scheduler, a new global animation system or a new architectural timer is forbidden. Small local CSS/SVG interaction effects may use the existing package pattern where appropriate.
- **Maintain accessibility, responsive layouts, performance, and image loading priorities.**
- **Never touch the Flutter/mobile repository.**

### Lessons that must not be repeated (Halloween, Christmas)

These are defects the two shipped packages actually produced. They are why the
guardrails above exist — re-read them before touching a welcome modal, a landing
page or the ambient scheduler. They are **history, not instruction**: each
package's specifics (key names, cadences, timing) belong to that package's
approved brief, and the general rules live in the guardrails above.

- **A modal is not an `open` attribute.** *(Christmas history; the current
  contract is `docs/commemorative-events.md` §2 and `docs/themes/conexao-br-irlanda.md`
  §"The homepage welcome — display, frequency and failure behaviour".)* The
  Christmas welcome first shipped as
  a visible inline band straddling the hero, then as a server-rendered
  `<dialog open>` — which is a non-modal in-flow card, not a modal. It now ships
  **closed** and opens only through `showModal()`, with a compact fallback card
  for no-JS / no-`showModal()` / a throwing `showModal()`, and nothing left
  inline after dismissal. The theme reference documents the state machine. For
  any popup that is supposed to auto-open, test that it truly opens as a modal
  through the supported `showModal()` dialog API — never as an inline
  `<dialog open>` element — that the no-JS/no-dialog fallback stays usable, and
  that no duplicate inline card or empty layout space is left behind.
- **Preview must never write the visitor's dismissal key.** *(Christmas example;
  the general rule is the engine's "nothing is persisted" preview contract.)*
  The signed preview
  auto-opens the same modal, and its close leaves
  `ce_christmas_welcome_dismissed_v1` untouched, so a reload shows the event
  again. That key name and its tab-session duration are Christmas-specific —
  only `christmas` auto-opens a welcome today — and must not be copied into
  another package.
- **Landing dispatch is page-owned first.** `/natal/` and `/en/christmas/`
  rendered an empty `<main>` while the event was active because the event-driven
  copy of the `landing_page` placement shadowed the page-owned context. The fix
  reordered two `if` blocks in `conexao_commemorative_placement_context()` — no
  new route, package, placement, meta key, scheduler, resolver, cache or
  activation path.
- **Read the normalised event, not the evaluation row.**
  `conexao_commemorative_active()` once built its value object from the
  resolution's evaluation row instead of the normalised event, which lost the
  localised `_ce_heading` / `_ce_subheading` / `_ce_cta_*` content and returned
  an empty event under preview.
- **An approved cadence is a requirement, not a default.** *(Halloween-specific
  approved requirement — `docs/reports/2026-10-27-halloween-mobile-experience-plan.md`
  §1/§5, asserted by `test-seasonal-halloween-nav-effects.php`; not a default
  for any other package.)* The Halloween mobile
  navigation-surprise cadence (20–30 s first, 25–35 s later) was once shipped as
  5–10 s / 10–15 s. The tests were right; the implementation regressed. Never
  swap an approved cadence for the energy table's values, and never copy one
  package's cadence into another.
- **One ambient scheduler.** A second glint/decoration system is architecturally
  forbidden; the existing `site_wide_ambient` placement and its shared scheduler
  already satisfy the brief.
- **No public activation.** No query string may enable an event, choose a package
  or force a placement — `commemorative_no_public_activation` fails the build on
  it. The signed preview query var is the single authorised exception.
- **Zero commemorative bytes without an event.** Ordinary routes ship no
  commemorative CSS, JS or art when no event is active; the opted-in landing page
  is the one documented exception.

## Verification

```bash
# Record git state before
git status --short && git branch --show-current && git rev-parse HEAD && git stash list

# Which components and suites the runner discovered
./scripts/run-tests.sh --list

# In-process PHP suites (the engine, then the theme presentation)
./scripts/run-tests.sh --only conexao-commemorative
./scripts/run-tests.sh --only conexao-br-irlanda

# Script-contract layer — includes the Node runtime suite (`node --test`) for the
# shared ambient scheduler and the Christmas journey/welcome/micro-animation
# controllers
./scripts/run-tests.sh --scripts

# HTTP acceptance layer — the commemorative matrix rows
./scripts/run-tests.sh --acceptance

# The whole contract, one aggregate exit code
./scripts/run-tests.sh

# Permanent invariant gates, aggregated into gate.json
python3 scripts/verify-permanent-gates.py

# Registry drift (zero writes)
php scripts/generate-registry-docs.php --check

# Lint
./scripts/lint.sh

# Agent-governance gate (validates this skill's format and references)
python3 tests/scripts/verify-agent-governance.py

# Record git state after
git status --short && git branch --show-current && git rev-parse HEAD && git stash list
```

**Scope the verification to the change.** `--only conexao-commemorative` runs
the engine suites, `--only conexao-br-irlanda` the theme presentation suites;
the permanent invariant gates (`python3 scripts/verify-permanent-gates.py`,
documented in `docs/testing.md`) are mandatory for every event. Run the browser
checks that cover the feature being changed: the `verify-christmas-*` scripts
below are the regression net for the shared controllers, shared dispatch, shared
modal/animation behaviour and Christmas itself — for another event, add or run
equivalent event-specific checks, and never claim Christmas-only tests prove
another event works. If a command here is missing, renamed or no longer
relevant, take the current command from `docs/testing.md` or
`scripts/README.md` and cite that source instead of guessing.

**Browser layer (opt-in, local only).** The `tests/browser/verify-*` scripts are
not part of `./scripts/run-tests.sh`. They are explicitly invoked, read-only,
evidence-producing checks: they load the local stack (`CONEXAO_TEST_BASE_URL`,
default the Compose site on port 8080), refuse a production host outright, write
screenshots plus one JSON report into `--out DIR`, and write nothing to
WordPress. Start the local stack only as permitted by Preconditions — reuse a
running environment, keep every startup side effect deliberate. The
landing-dispatch check additionally needs a signed-preview cookie
and nonce in the environment — mint them locally and never commit them.

```bash
python3 tests/browser/verify-christmas-welcome-browser.py --out DIR
python3 tests/browser/verify-christmas-landing-dispatch-browser.py --out DIR
python3 tests/browser/verify-christmas-micro-animations-browser.py --out DIR
python3 tests/browser/verify-christmas-signature-browser.py --out DIR
```

- The in-process suite must print `N passed, 0 failed` and exit 0.
- Every new HTTP matrix row must pass; report exact assertion totals.
- `git status --short` must show no change under `wp-content/` that was not planned.
- Record real numbers in the report; if a verifier could not run, say so — never write "should work".
- Do not edit baseline files just to make checks pass.

## Failure handling

- *A gate reports drift.* The generated region and the source disagree; fix the source and regenerate. Never hand-edit a generated region.
- *Lint reports new violations.* Fix them; do not raise the baseline to hide them.
- *A test fails only in the acceptance layer.* Usually a route/canonical/hreflang contract issue; check `docs/routing.md` and the matrix row before the schema.
- *The completeness gate is non-zero.* EN coverage is incomplete; either finish coverage (`wp-translation-rollout`) or record an explicit allowlist with a reason. Never lower the gate to make it pass.
- *A pre-existing failure appears.* Compare against the recorded baseline; do not claim it as newly introduced.
- *WebKit/Safari is unavailable.* State the limitation clearly in the report; do not claim tested.

## Evidence and reporting

Record in the report: the seven-field brief status and approvals, the content-calendar and overlap-watchlist updates, the event-to-package mapping and the registry it was confirmed against (the theme presentation registry — never `plugins.json`), schedule/timezone (`wp_timezone()`)/occurrence-year/precedence details, activation boundaries, routes and placements, PT/EN content and translations, shared metadata ownership, artwork and alt text, modal/popup requirements, animation and reduced-motion behaviour, CTA/landing route verification, preview/security, performance/accessibility, tests with exact pass/fail counts, browser matrix and baseline comparison, documentation/report, and production/release approval status.

Machine evidence goes to `docs/evidence/<date>-<stage>/`. The narrative report goes to `docs/reports/<date>-<stage>-<topic>.md` and, when one is required, the task plan to `docs/plans/<date>-<topic>.md`, each copied from its canonical template (`docs/templates/report.md`, `docs/templates/plan.md`). The canonical templates are never filled in per event. No report or evidence file is added at the repository root.

## Definition of done

**This list is the per-event checklist.** Copy the items into the task-specific
plan (`docs/plans/<date>-<topic>.md`, from `docs/templates/plan.md`) when one is
required — for multi-file work or anything touching content, routes or
English/Polylang, which a substantive event implementation meets by nature — and
tick them in the task-specific report
(`docs/reports/<date>-<stage>-<topic>.md`, from `docs/templates/report.md`)
when it ships. The canonical templates are never edited for a single event —
change a template only when the user explicitly asks to improve it or the
documented process requires it. Two canonical inputs this checklist deliberately
does not restate: the seven-field package brief, content calendar and overlap
watchlist in `docs/commemorative-content.md` (the event's content contract) and
the plugin facts in `plugins.json` (never copied into a skill or an event
registry).

- [ ] Seven-field package brief completed or updated in `docs/commemorative-content.md`, with approved copy/artwork/user decisions recorded there.
- [ ] Event added/updated in the canonical content calendar; manual overlap watchlist checked and updated where the window can overlap another occasion.
- [ ] Event-to-package mapping confirmed against the theme presentation registry (`docs/commemorative-events.md` + `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php`); plugin-level facts confirmed against `plugins.json` only — the two registries are never conflated.
- [ ] Schedule/timezone (`wp_timezone()`)/occurrence-year/activation boundaries verified with an injected clock; priority and manual annual precedence (`_ce_year_precedence`) applied per the engine contract where applicable.
- [ ] Routes and placements verified; landing-page dispatch precedence tested (page-owned before event-driven).
- [ ] PT/EN content and translations verified; shared metadata ownership enforced.
- [ ] Artwork and alt text documented.
- [ ] Modal/popup requirements verified against the approved brief and the current shared controller contract: real `showModal()` dialog (never an inline `<dialog open>` for an auto-opening popup), close/Escape, focus, scroll restore, usable no-JS/no-dialog fallback with no duplicate inline card or empty layout space, preview behaviour per contract, dismissal state per the brief.
- [ ] Animation and reduced-motion behaviour verified: cadence from the approved brief, no borrowed values, reading/navigation/interaction unblocked.
- [ ] CTA/landing route verification passed (language-aware, valid destinations).
- [ ] Preview/security verified (signed, capability-gated, nonce-verified, persists nothing — no visitor dismissal/session key written from preview).
- [ ] Performance/accessibility verified.
- [ ] Tests run with exact pass/fail counts; event-relevant scope stated (Christmas-only checks never claimed as proof for another event); baseline comparison recorded.
- [ ] Browser matrix coverage and limitations stated.
- [ ] Documentation/report created and indexed; task plan (when required) and report created from the canonical templates, not in place of them.
- [ ] Production/release approval status recorded; no production write occurred.
- [ ] Git state captured before and after; no destructive operations; no commit/push/deploy without authorization.

_Last verified: 2026-11-09 by the agent skills review and hardening_
