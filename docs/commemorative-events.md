# Commemorative events

> **Owner:** theme + `conexao-commemorative` plugin · **Standard:**
> [`engineering-standard.md`](engineering-standard.md) ·
> **Content playbooks:** [`commemorative-content.md`](commemorative-content.md) ·
> **Runtime reference:** [`plugins/conexao-commemorative.md`](plugins/conexao-commemorative.md)

The commemorative system turns an annual occasion into a bounded, bilingual,
theme-owned presentation. It is composed of four separate things that must never be
merged back together:

| Concept | What it is | Owner |
|---|---|---|
| **Event** | One occurrence of an occasion: its window, enablement, priority and manual annual preference | `commemorative_event` CPT + `conexao-commemorative` plugin |
| **Experience package** | A visual identity: motif, palette, motion, copy voice, asset manifest and the placements that belong to it | the **theme** |
| **Placement** | Where a package appears: `homepage_hero`, `site_wide_ambient`, `landing_page` | the theme dispatcher |
| **Component** | A reusable piece a placement is built from: hero shell, ambient layer, module, card | the theme framework |

## 1. The dispatch model

```text
request ──▶ engine (plugin): "which ONE event is selected?"
              │
              ▼
          theme registry: event.presentation ──▶ validated package
              │
              ▼
          route kinds (homepage / internal / landing)
              │
              ▼
          the package's placements that apply to this route
              │
              ├── homepage_hero     → template-parts/commemorative/homepage-hero.php
              ├── site_wide_ambient → template-parts/commemorative/ambient-layer.php
              └── landing_page      → template-parts/commemorative/landing-page.php
```

The theme **asks** the engine; it never recomputes a date, a priority, an
enablement flag or the annual preference. `inc/commemorative-presentations.php`
holds the only registry and the only dispatcher:

| Function | Purpose |
|---|---|
| `conexao_commemorative_packages()` | the validated registry (memoised) |
| `conexao_commemorative_package( $slug )` | one validated package or `null` |
| `conexao_commemorative_request_plan()` | this request's event, package and placements |
| `conexao_commemorative_has_placement( $id )` | whether a placement renders here |
| `conexao_commemorative_render_placement( $id )` | render it (fail-closed) |
| `conexao_commemorative_asset_plan( $plan )` | which CSS/JS handles that plan needs |
| `conexao_commemorative_body_marker()` | the generic client marker |

### Route kinds

| Kind | When | Placements that may render |
|---|---|---|
| `homepage` | the front page | `homepage_hero`, `site_wide_ambient` |
| `internal` | singular, archive, blog home, search — never the front page, 404, feed, admin, AJAX or REST | `site_wide_ambient` |
| `landing` | a page that opted in **and** whose slug matches the package's declared slug for the current language | `landing_page` |

### Fail-closed validation

A package definition is validated before use (`conexao_commemorative_validate_package`):

- an unknown **package** key never resolves;
- an unknown **placement** identifier is dropped;
- a missing **template** or **content template** drops the placement;
- a missing **asset** file drops that asset only;
- an invalid **token** value is dropped (the CSS fallback applies).

Nothing is echoed, warned about or fatal: an incomplete package simply renders
less. Two packages are implemented (`halloween`, `christmas`); the other four are
**registered identities with no placements** — a valid `_ce_presentation` value that
renders nothing until its placement map ships.

## 2. Presentation rules

- **One selected event, one package, many placements.** The engine selects at most
  one event per request; the theme may render several placements of that package.
  Two packages never render at once (gate `commemorative_single_package`).
- **Tokens, not palettes.** The registry declares `primary`, `secondary`, `accent`
  and `surface`; the dispatcher emits them as `--ce-*` custom properties on the
  placement wrapper. Package CSS consumes them; the package stylesheet mirrors the
  four values because the ambient decorations are appended to `<body>` outside any
  wrapper. `test-commemorative-packages.php` asserts registry ↔ CSS parity.
- **Conditional assets.** `assets/css/commemorative.css` and
  `assets/js/commemorative.js` load only when at least one placement renders; each
  package file is a dependency of the shared framework and loads only for its own
  placement. With no active event: **zero commemorative bytes on every ordinary
  route** (gate `commemorative_no_asset_without_event`).
- **The landing page is the one deliberate exception to that zero-asset rule.**
  An explicitly opted-in commemorative landing page keeps loading its package
  assets outside the event window, because it is an **archival / seasonal page**
  (§7.13): it must still look like itself in July, for a visitor following a
  link, a bookmark or a search result. Landing resolution is a separate path —
  it reads `_ce_landing_package` plus the package's declared slug for the current
  language and never asks the active-event resolver, so the page exists with no
  event, with a disabled event and after the window has passed. This is a
  **constrained** exception, not a relaxation of conditional loading:
  - it requires *both* opt-ins (the page template **and** `_ce_landing_package`)
    **and** a slug match in the package registry;
  - only that package's own assets load — never another package's;
  - the gate's zero-asset assertion stays scoped to ordinary routes and is not
    weakened; a landing page is never treated as proof that ordinary routes may
    load commemorative assets.
- **The ambient layer is decoration.** `aria-hidden="true"`, `pointer-events: none`,
  no focusable node, no audio, one decoration at a time, one timer, node removed
  after its duration, paused on a hidden tab, disabled under reduced motion (gates
  `commemorative_ambient_decorative` + the Node runtime gate).
- **A landing page is a page.** It is not a modal, not a CPT route. It carries the
  template *and* `_ce_landing_package`, renders from the PAGE's copy (never the event
  schedule, so it survives the window), and is excluded from the sitemap.
- **A hero may be presented as a welcome, not as a second experience.** The
  `homepage_hero` placement of a package may be rendered once inside a native
  `<dialog>` that ships **closed**, which the package script promotes to a modal
  (`showModal()`) on the first eligible homepage view of a **tab session**, capped
  by a `sessionStorage` dismissal. This is still the SAME placement, template,
  record and asset plan: no new event, package, placement, scheduler or route.
  Without JavaScript — or if `showModal()` is unavailable or throws — a **compact
  fallback card** in the flow under the hero content carries the heading and the
  same CTA; the dialog is never left open as a non-modal inline card, and after
  dismissal nothing is rendered inline at all (no band, no reserved hero height).
  The signed private preview sets a package attribute that **suppresses** the
  auto-open and never touches the dismissal key, so the non-dismissible preview
  banner stays visible; the fallback card then exposes a preview trigger that
  opens the same modal on demand. Only `christmas` does this today
  ([theme internals](../themes/conexao-br-irlanda.md#the-homepage-welcome--display-frequency-and-failure-behaviour)).

## 3. Timing and caps

| Energy | Interval | Duration | Simultaneous | Session cap |
|---|---|---|---|---|
| `calm` | 60–120 s | 2–4 s | 1 | 3 |
| `moderate` | 45–90 s | 3–6 s | 1 | 5 |
| `playful` | 30–60 s | 3–5 s | 1 | 7 |

A package may narrow a row; the hard bounds (one simultaneous decoration, no
inverted range, cap ≥ 1) are enforced in
`conexao_commemorative_ambient_options()` and in `normaliseConfig()`.

**Halloween exception.** The approved mobile navigation-surprise cadence — **20–30 s
for the first effect, 25–35 s for later ones** — is an explicit, approved override
(`2026-10-27-halloween-mobile-experience-plan.md` §1/§5). It lives in
`assets/js/presentations/halloween/halloween.js` and is asserted by
`test-seasonal-halloween-nav-effects.php`. It must never be replaced by the energy
defaults, and it must never be copied into another package.

## 4. Preview and caching

- **Preview** is the existing signed capability: `?conexao_preview=<key>` plus
  `_wpnonce` and `_ce_ts`, honoured only for a logged-in user with `manage_options`,
  `edit_post` on the canonical record and a valid per-key nonce. It works for
  published, disabled, draft and out-of-window events, on the homepage **and** on any
  internal page, renders the non-dismissible banner and persists nothing.
- **There is no public activation parameter.** No query string enables an event,
  selects a package or forces a placement (gate
  `commemorative_no_public_activation`).
- **Caching** is Option B: one armed boundary, a per-request due check and a targeted
  purge. It is documented in [`plugins/conexao-commemorative.md`](plugins/conexao-commemorative.md);
  verification against WordPress.com is a separately authorised activity.


## 5. How to add an event — the runbook

1. **Choose an existing package or write a new one.**
   - *Same look, new occasion* → create an event record and select the package. No
     code changes.
   - *New visual identity* → §5.1 below.
2. **Create the event record** in wp-admin → *Eventos Comemorativos*:
   - `_ce_event_key` — a stable, unique key; it is identity, not a slug;
   - the rule (`fixed_annual` + `MM-DD`, `variable_annual` + `easter`, `window` +
     `MM-DD → MM-DD`, or `one_off` + `Y-m-d`) and the lead-in days;
   - `_ce_presentation` — the package;
   - enabled, priority, and (only if needed) the annual preference.
3. **Add the EN translation.** Translate the title and the `_ce_heading` /
   `_ce_subheading` / `_ce_cta_*` copy. **Keep the shared configuration on the PT
   record only** — this is now enforced, not just asked for (see §6): Polylang
   does not copy the shared keys, the EN editor renders them read-only, and the
   save paths refuse them on a non-canonical record.
4. **Preview it.** Open the event editor and use the signed preview link on the
   homepage and on an internal page; check PT/EN, desktop/mobile, light/dark and
   reduced motion.
5. **Add a landing page only if you have three real modules** (§5.2).
6. **Record it in the calendar** ([`commemorative-content.md`](commemorative-content.md) §4)
   and add the watchlist row if it can overlap another occasion.
7. **Run the gates:** `./scripts/run-tests.sh --only conexao-br-irlanda --php`.

### 5.1 Adding a visual identity (developer)

1. Copy the shape of an implemented package in
   `inc/commemorative-presentations.php`: `label`, `status => 'implemented'`,
   `design` (motif, energy, tokens, motion, voice, do-not list) and `placements`.
2. Add `template-parts/commemorative/<package>/` content templates, one per
   placement.
3. Add `assets/css/presentations/<package>.css` (mirroring the four `--ce-*` tokens)
   and `assets/js/presentations/<package>/<package>.js`.
4. Add the art under `assets/images/presentations/<package>/`.
5. Run `test-commemorative-packages.php` — it fails on a missing token mirror, a
   missing brief field or a missing template.
6. **Do not** touch the plugin: no package may need an engine change.

### 5.2 Adding a landing page

1. Create the PT page with the template **Commemorative Landing Page** and set
   `_ce_landing_package` to the package slug (`scripts/seed-commemorative-landing-pages.php`
   shows the idempotent, dry-run-first pattern).
2. Declare the slug in the package's `landing_page` → `options.page_slugs` for each
   language; a page whose slug does not match renders nothing.
3. Compose at least **three** modules from **existing** records (see
   `template-parts/commemorative/christmas/landing.php` for the reuse map).
4. Translate the page and link it with Polylang.
5. Confirm: canonical, hreflang, HTTP 200, absent from `/sitemap.xml`, present after
   the event window.

## 6. Invariants and where they are proven

| Invariant | Gate |
|---|---|
| No commemorative asset without a displayed event (ordinary routes; the opted-in landing page is the documented exception in §2) | `commemorative_no_asset_without_event` |
| No public activation through a query parameter | `commemorative_no_public_activation` |
| The CPT stays private (route, archive, query var, REST, search) and neither it nor the landing pages are emitted by the **theme sitemap producer** | `commemorative_cpt_privacy` |
| One package, many placements | `commemorative_single_package` |
| The ambient layer is decorative only | `commemorative_ambient_decorative` |
| Placement assets are conditional | `commemorative_placement_assets` |
| The canonical PT record is the single owner of the shared `_ce_*` configuration (Polylang copies none of it; the EN editor is read-only; the save paths refuse it there) | `commemorative_translation_ownership` |
| The scheduler's runtime behaviour | `commemorative_ambient_runtime` (Node) |

All eight run inside `./scripts/run-tests.sh` and are aggregated by
`python3 scripts/verify-permanent-gates.py`.

**What the sitemap gate does and does not prove.** `commemorative_cpt_privacy`
renders the **theme-owned** sitemap producer in-process, so it proves that
producer's exclusion decision. It does **not** prove that a public
`GET /sitemap.xml` is served by that producer: in the local stack (and therefore
in CI) Jetpack answers with a sitemap **index**, so the theme producer never
runs and the HTTP acceptance row passes vacuously. Whether production serves the
landing pages in its sitemap is **NOT VERIFIED** — see
[`testing.md`](testing.md) §"Sitemap evidence semantics".

_Last verified: 2026-11-06 by the commemorative-events local remediation_
_Last verified: 2026-11-06 by the Christmas homepage popup UI fix (the welcome dialog ships closed; the compact fallback and preview trigger replace the inline band)_

