# Report — Commemorative Events System: Architecture Audit & Redesign Proposal

> **READ-ONLY architecture task.** No PHP/JS/CSS/template, database, CPT, setting,
> event record, production or deploy change was made. The only files written are
> this report and its two index entries. No commit was created.

| | |
|---|---|
| **Stage / task name** | Commemorative Events System — architecture audit, redesign & implementation plan |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (architecture audit) |
| **Branch** | `leisure-location` (working tree already dirty with unrelated Lazer work) |
| **Start SHA** | `92e42aa150de8861f8841c822198d2735fa19ca6` |
| **Final SHA** | `92e42aa150de8861f8841c822198d2735fa19ca6` (no commit created) |
| **Working tree at finish** | dirty — pre-existing unrelated Lazer/location changes plus this report and its index entries |
| **Skills followed** | `wp-repository` (orientation, scope control, reuse-first, review before merge) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/README.md`, `docs/content-model.md`, `docs/routing.md`, `docs/testing.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `plugins.json`, `docs/templates/report.md` |

## Revision history

| Revision | Date | Change |
|---|---|---|
| r1 | 2026-11-03 | Initial audit + redesign proposal. |
| **r2** | **2026-11-03** | **Focused correction pass** addressing the reviewer's required revisions: (1) presentation **scope** is no longer locked to the overlay — four scopes are now first-class; (2) **caching / activation-boundary** design added (§4.6); (3) **Polylang** shared-vs-translated data model + a language-neutral `event_key` added (§3.6); (4) the **enabled-flag contradiction** is resolved to a single source of truth (§3.3); (5) **ownership** clarified — the **presentation registry moves to the theme**, the plugin stays theme-independent (§2); (6) **temporary public override deferred** out of scope, preview hardened (§5.5/§5.6); (7) **concurrency removed** from the initial release (§6); (8) the "byte-for-byte Halloween" requirement is **dropped** (§7.1); (9) roadmap simplified from nine stages to **seven** (§10); (10) **W13 corrected**: the failing Halloween timing assertions are **not** stale — the approved spec is 20–30 s / 25–35 s and the shipped code regressed to 5–10 s / 10–15 s (§1.5 W13, §12.1). |
| **r5** | **2026-11-03** | **Final clarifications** per the fourth review: (1) **Polylang `_ce_event_key` exception** stated explicitly — the EN record **must** carry `_ce_event_key` (identical to PT) and must **not** carry any *other* shared `_ce_*` meta; the meta-copy exclusion is per key group (§3.6, §3.2); (2) preview identifier is **`event_key`** everywhere (§5.1); (3) **`_ce_assets` removed from the v1 editor and field set** (presentations use theme-managed assets), and `_ce_force_until` is a **future design note** only (§3.2, §3.4, §3.5, §5.3); (4) **`_ce_description` given one purpose** — internal, administrator-only, **not translated** (§3.2, §3.6); (5) cache scheduler **recalculates on every relevant change, after persistence**, with an **atomic concurrency lock**, and Option B uses the **effective maximum** cache lifetime across all layers (§4.6, §9.6). |
| **r6** | **2026-11-03** | **Three targeted corrections** per the fifth review: (1) **§9.7 gate wording** — the Polylang gate now **explicitly allows `_ce_event_key`** (requires it identical on PT and EN) and **rejects every other** duplicated shared `_ce_*` key; (2) **deletion hook corrected** — permanent deletion uses **`deleted_post`** (fires *after* removal), **not** `before_delete_post`, and **`deleted_post_meta`** covers clearing a scheduling field; trash/unpublish stays separate via `transition_post_status`, with tests for permanent deletion and field clearing (§4.6, §9.6); (3) **preview security summary restored** — the §11.1 answer and the Stage 3 scope now carry the **full conjunctive check** (`is_user_logged_in` **AND** `manage_options` **AND** `edit_post` **AND** nonce), matching §5.4. |
| **r7** | **2026-11-03** | **One required clarification** per the sixth review: §5.4's **first** authorisation statement now includes `current_user_can( 'edit_post', $post_id )` (it had omitted it while the later paragraph in the same section already required it), so **both statements in §5.4 agree**; and §9.3 adds an **explicit permission negative** — a logged-in user with `manage_options` but **without** `edit_post` for the specific event **cannot** preview it. |

---

## 0. How to read this report

This is the **audit + redesign proposal** requested as an explicit gate before any
implementation. It answers the ten deliverables (current assessment, target
architecture, data model, scheduling design, admin UX, presentation architecture,
migration, testing plan, roadmap, final recommendation) and closes with the
governance/status section. **Implementation must not begin until this proposal is
reviewed and approved.**

Every claim about the current code is backed by an inspected file or an executed
read-only command; §12 records the commands and their real output. Nothing here
claims a browser or full-suite result that was not actually produced.

---

## 1. Current Architecture Assessment

### 1.1 What exists today

There are exactly **three** artifacts of the commemorative capability, plus the
Halloween presentation built on top of them:

| Artifact | Path | Role |
|---|---|---|
| Occasion engine | `wp-content/themes/conexao-br-irlanda/inc/occasion.php` (318 lines) | date rules + eligibility + a hard-coded registry |
| Load point | `wp-content/themes/conexao-br-irlanda/functions.php:118-119` | `require_once …/inc/occasion.php` |
| Focused suite | `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php` | 14 assertions on the core date math |
| Halloween presentation | `template-parts/halloween-hero.php`; the Halloween block in `assets/css/main.css`; `initHalloweenWelcome()` + `initHalloweenNavEffects()` in `assets/js/main.js`; the marker in `header.php`; the call in `front-page.php` | the shipped Halloween experience |
| Halloween suites | `tests/test-seasonal-halloween-presentation.php` (26), `tests/test-seasonal-halloween-nav-effects.php` | presentation + navigation-effect regression |

### 1.2 How the engine actually works

- `Occasion_Date_Rule` is a PHP `enum` (`fixed_annual`, `variable_annual`, `one_off`).
- `Conexao_Seasonal_Occasion` holds public scalar properties (`id`, `name`,
  `date_rule`, `fixed_month_day`, `variable_rule`, `one_off_date`, `lead_in_days`,
  `enabled`, `destination`, `variant`) and computes, at **request time**:
  - `resolve_occurrence_date()` — the occurrence for the **current calendar year**
    (`$year = (int) (new DateTime('now'))->format('Y')`), supporting a fixed
    `MM-DD`, the Gregorian Easter algorithm for `variable_annual`, or a fixed
    `Y-m-d` for `one_off`;
  - `resolve_start_date()` — occurrence **minus `lead_in_days`**, at `00:00:00`;
  - `resolve_end_date()` — occurrence at `23:59:59` (inclusive);
  - `is_active()` — `start <= now <= end`, all in `wp_timezone()`.
- `ocasons_register()` returns a **hard-coded array of six occasions**
  (christmas, halloween, new_year, st_patricks_day, valentines_day, easter), each
  `enabled => true`, each `lead_in_days => 15`.
- Accessors: `ocasons_get_all()` (per-request static cache), `ocasons_get_active()`
  (`is_active() && enabled`), `ocasons_get_active_no_filter()` (ignores `enabled`),
  `ocons_get_primary_active()` (returns `$active[0]`), plus aliases
  `ocasons_get_primary_active()`, `ocacons_get_primary_display_content()`,
  `ocasons_get_primary_display_content()`, `conexao_seasonal_is_active()` and
  `conexao_seasonal_variant()`.
- `get_display_content()` returns a generic, developer-facing heading
  (`sprintf( __( 'Seasonal Occasion: %s' ), $name )`) that **no template uses**.

### 1.3 How Halloween is wired on top of it

- `front-page.php:145-152` calls `ocons_get_primary_active()` and then
  **hard-codes** `'halloween' === $active_occasion->id && $active_occasion->enabled`
  before calling `get_template_part( 'template-parts/halloween-hero', … )`.
- `template-parts/halloween-hero.php` re-checks `'halloween' !== $seasonal_occasion->id`
  and `! $seasonal_occasion->enabled`, then renders a hidden, non-modal, centered
  overlay with inline SVG bats/pumpkin, a session-scoped dismissal key namespaced by
  occasion **and occurrence year**, and PT/EN copy chosen with `__()` against
  `conexao_current_locale()`.
- `header.php:46-53` **hard-codes** `'halloween' === conexao_seasonal_variant()`
  to emit `<span class="seasonal-halloween-active-marker" data-witch-asset="…">`.
- `assets/js/main.js` (3,196 lines, one deferred bundle, enqueued site-wide)
  contains `initHalloweenWelcome()` and the ~600-line `initHalloweenNavEffects()`
  (bat/pumpkin/ghost/witch SVG strings, click effects, spontaneous mobile
  scheduler). It no-ops when the marker is absent, but the code always ships.
- `assets/css/main.css` (8,985 lines) contains the whole Halloween block site-wide;
  `assets/css/dark-mode.css` adds the dark tokens.

### 1.4 Strengths (keep these)

1. **Deterministic, request-time eligibility — no cron.** Matches the requirement.
2. **Timezone-aware** through `wp_timezone()`; verified as `Europe/Dublin` on the
   local stack (§12).
3. **Safe failure.** An invalid/ambiguous config returns `null` from the resolver
   and the occasion is never active.
4. **Three rule types including a real Easter computation** (Anonymous Gregorian
   algorithm), already exercised by a green suite.
5. **A genuine separation attempt**: the engine knows nothing about bats or
   pumpkins; the partial reads the occasion. This instinct is correct and is the
   seed of the target architecture.
6. **The rendered experience is already accessibility-minded**: non-modal, a real
   `<button>` dismiss, `aria-labelledby`, `inert` on the underlying hero, focus
   return, reduced-motion guards, session-only dismissal.

### 1.5 Weaknesses, coupling and technical debt (the redesign case)

| # | Finding | Evidence | Severity |
|---|---|---|---|
| W1 | **Adding an event requires a code change.** The registry is a PHP literal; there is no persistence, no admin, no configuration. | `inc/occasion.php:193-257` | **blocker** vs the stated goal |
| W2 | **New Year is not implementable and is currently wrong.** `resolve_occurrence_date()` pins the occurrence to `now.year`, so for a `01-01` event the computed window is always `Dec 17 (Y-1) → Jan 1 (Y)`. On **Dec 26 of year Y** the window is already in the past, so the experience can never appear Dec 26–31. | PHP probe (§12): `occurrence 2026-01-01, start 2025-12-17, end 2026-01-01`; requirement is **Dec 26 → Jan 1** | **blocker** |
| W3 | **No year-boundary support at all.** The engine has no concept of an annual window that crosses 31 Dec; W2 is one symptom. | `resolve_occurrence_date()` year pinning | high |
| W4 | **`lead_in_days` cannot express "Dec 26 → Jan 1".** The only knob is "N days before the occurrence", which is structurally wrong for New Year. | `resolve_start_date()` | high |
| W5 | **Conflict resolution is implicit file order.** `ocons_get_primary_active()` returns `$active[0]`; there is no priority field and no documented rule. | `inc/occasion.php:290-293` | high |
| W6 | **Eligibility is not injectable ⇒ tests are date-dependent.** `is_active()` calls `new DateTime('now')` internally; no clock can be passed. | `is_active()`; the nav-effects suite asserts `is_active()` and is **red today** (§12.1) | high |
| W7 | **Event-specific conditions are scattered.** The literal `'halloween'` appears in `front-page.php`, `header.php`, the partial, `main.js` and `main.css`. Adding Christmas means editing all of them. | §1.3 | **blocker** vs the stated goal |
| W8 | **Site-wide frontend cost with no active event.** The Halloween JS + CSS ship to every visitor, every page, all year; the code is only *guarded*, not *withheld*. | `assets/js/main.js`, `assets/css/main.css` enqueued unconditionally (`inc/assets.php`) | medium |
| W9 | **The engine lives in the theme, not a plugin.** Every other cross-cutting capability (CPTs, meta, admin UX, event runtime) lives in a production plugin; theme `inc/` is presentation. Admin screens in a theme also conflict with the "production has no CLI ⇒ admin screen" model. | `functions.php:118`, `plugins.json`, `docs/themes/conexao-br-irlanda.md` | medium |
| W10 | **Naming chaos + dead API.** `ocasons_*` / `ocons_*` / `ocacons_*` (three misspellings), duplicated aliases, and `get_display_content()` that nothing calls. | `inc/occasion.php:259-318` | medium |
| W11 | **No enable/disable/preview/override surface.** `enabled` is hard-coded `true` for all six events and cannot be changed without code. | registry | **blocker** vs the stated goal |
| W12 | **Event content is PHP strings, not a translatable content model.** Titles/copy live in `__()` calls in the partial; an admin cannot author per-event PT/EN content, and each new event means editing PHP + regenerating the `.pot`. | `halloween-hero.php:26-33` | medium |
| W13 | **A shipped regression that the tests correctly catch.** The approved spec (`2026-10-27-halloween-mobile-experience-plan.md` §1 and §5, and the matching report §"Scope") is a randomised **20–30 s** first delay and **25–35 s** subsequent delays. Commit `59c2db9` shipped **5–10 s / 10–15 s**. `test-seasonal-halloween-nav-effects.php` asserts the **approved** values, so it **fails** — the tests are right and the **implementation is the regression**. *(This corrects the r1 report, which wrongly described the failing assertions as stale.)* | `git log -p -- main.js`; plan §1/§5; report §19–20 | **high** |
| W14 | **A latent wrong timezone default.** `get_timezone()` falls back to `Europe/Lisbon`, not `Europe/Dublin`. Harmless here (`wp_timezone()` wins) but incorrect. | `inc/occasion.php:66-77` | low |
| W15 | **Feb-29 events silently never fire.** A `fixed_month_day` of `02-29` resolves to `null` in non-leap years (the guard rejects PHP's roll-forward) with no documented "nearest day" policy. | `resolve_occurrence_date():123-126` | low |

### 1.6 Answers to the audit questions (as asked)

- **Responsibilities belonging to the engine:** date rules, window resolution,
  eligibility, "primary" selection. **Responsibilities that are really Halloween's:**
  all visual/UX behaviour, copy, assets, nav effects — currently **not** separated
  from the engine's consumers (W7).
- **Tightly coupled / hard-coded:** the event **identity** (`'halloween'`) is the
  coupling point across PHP templates, the header, JS and CSS (W7).
- **Settings that need code to change:** *all of them* — dates, lead-in, enablement,
  copy, presentation (W1, W11, W12).
- **Can it support multiple events?** Mechanically it holds six, but only one is ever
  *rendered*, by an `id` string check, so "support" is nominal (W5, W7).
- **Events crossing calendar years?** **No** (W2, W3, W4).
- **Unnecessary frontend work when no event is active?** **Yes** — the Halloween
  JS/CSS always ship (W8).
- **Failure conditions / overlapping events?** Overlaps are resolved by silent array
  order (W5); failure is safe (null ⇒ inactive) but silent — an administrator is
  never told *why* nothing shows.
- **Configured vs enabled vs eligible vs displayed:** the code collapses these into
  two booleans (`enabled`, `is_active()`), and there is **no** notion of "displayed"
  or of *why not* — the exact distinction the request asks for.

### 1.7 Verdict

The engine is a **good first draft with three hard defects** — the year-boundary /
New Year bug (W2), the absence of any configuration surface (W1/W11), and the shipped
Halloween cadence regression (W13) — plus a **structural limitation** (event identity
hard-coded into presentation, W7). It should be **retained in spirit and refactored, not
thrown away**: the date math, the request-time model and the accessibility-minded
presentation are all worth keeping. What must change is *where configuration lives*,
*how the window is computed*, *how presentations are registered*, and *how the active
event is dispatched*.

---

## 2. Recommended Target Architecture

### 2.1 The five responsibilities, and where each lives

The request asks for a clean separation between management, scheduling,
activation, presentation and configuration. The repository's own layering gives
each a home:

```
                    ┌───────────────────────────────────────────────┐
                    │  DATA (conexao-data-model, platform plugin)   │
                    │  CPT commemorative_event + registered meta    │
                    │  (declarative only: fields, sanitizers)       │
                    └───────────────┬───────────────────────────────┘
                                    │ reads/writes
                    ┌───────────────▼───────────────────────────────┐
                    │  ENGINE (conexao-commemorative, platform)     │
                    │  • window resolution (fixed / variable /      │
                    │    one-off / explicit, year-boundary aware)   │
                    │  • injected clock  • priority + conflict rule │
                    │  • enabled → eligible → displayed resolution  │
                    │  • preview authorisation (no override)        │
                    │  PUBLIC CONTRACT (THEME-INDEPENDENT):         │
                    │    conexao_commemorative_active()   (object)  │
                    │    filter: conexao_commemorative_presentations│
                    └───────┬───────────────────────┬───────────────┘
                            │                       │
    ┌───────────────────────▼──────┐   ┌────────────▼──────────────────────┐
    │ ADMIN (conexao-admin-ux)     │   │ PRESENTATION (theme)              │
    │ config entry → list + editor │   │ REGISTRY (slug → renderer)        │
    │ columns: state/window/reason │   │ template-parts/presentations/*    │
    │ preview link (no override)   │   │ assets/css/presentations/*.css    │
    │ nonces + capability checks   │   │ assets/js/presentations/*.js      │
    │                              │   │ enqueued ONLY when displayed      │
    └──────────────────────────────┘   └───────────────────────────────────┘
```

**Design rules (stated once, applied everywhere):**

1. The **engine never mentions a holiday**; a **presentation never mentions a date**.
   They meet only at the `presentation` slug stored on the event.
2. **The plugin must not know the theme.** The plugin exposes data, scheduling,
   eligibility, priority and preview authorisation through a **theme-independent
   interface**; it must never reference a template path, a CSS/JS handle or an asset
   file. The **theme** owns the presentation registry and registers what it can render.
   This survives a future redesign of the theme.

### 2.2 Component responsibilities

| Component | Owns | Must never |
|---|---|---|
| `commemorative_event` CPT + meta (`conexao-data-model`) | the field schema, sanitizers, defaults | contain scheduling or rendering logic |
| Engine (`conexao-commemorative`, **theme-independent**) | window math, clock injection, enabled→eligible→displayed, priority + reason, preview **authorisation**, the `conexao_commemorative_presentations` filter | render markup; know template paths, asset files, CSS/JS handles, or a holiday name |
| Admin UX (`conexao-admin-ux`) | the wp-admin list + editor + diagnostics + preview link | compute eligibility (it *asks* the engine) |
| **Theme presentation layer** | the **presentation registry** (which presentations exist), templates, visual assets, CSS/JS, rendering, and **conditional asset enqueue** | compute dates, decide enablement/priority |
| `conexao_current_locale()` / Polylang | locale + linked translation resolution | be bypassed |

### 2.3 Why the engine becomes a plugin (and the alternative)

The engine is **cross-cutting** (front end + admin + tests), must be **deployable in
a release ZIP**, and must be **admin-operable** (production has no CLI). The
repository's established pattern is that runtime logic for a capability lives in a
**platform plugin that depends on `conexao-data-model`** — exactly how
`conexao-event-runtime` is built (see `plugins.json`). The theme's `inc/` is
documented as presentation. Therefore the recommended home is a **new platform
plugin `conexao-commemorative`** (registry entry: `class: platform`, `production:
true`, `build: true`, `mount: true`, `dependencies: ["conexao-data-model"]`).

**Alternative (lower operational cost, same design):** if the team prefers not to
add an 11th plugin, the identical engine can be a single class inside
`conexao-data-model`. The trade-off is only *ownership purity* (a data-model plugin
gaining behaviour) versus *one fewer registry/build/activation entry*. The design in
§3–§7 is unchanged either way; **Stage 0 (§10) is the explicit decision gate.**

---

## 3. Event Data Model

### 3.1 Storage decision: a CPT, not an options array

**Recommendation: a dedicated `commemorative_event` custom post type** with
registered post meta, registered in `conexao-data-model` exactly like every other
content type, and managed through `conexao-admin-ux`'s existing config-driven editor.

| Option | Verdict |
|---|---|
| **A. Dedicated CPT** (recommended) | Reuses WP-native list table, editor, revisions, capabilities, REST, and **Polylang linked translations** for free. The **editorial lifecycle** (draft / published / trashed) maps onto post status; the **scheduling switch** is a *separate* field — the two are deliberately distinct and are defined in §3.3. It is the repository's established pattern for every managed entity. |
| B. Single `options` array (settings screen) | Simplest storage, but loses revisions, per-record capabilities, list-table UX, and Polylang translation of *content*. Every field must be hand-rolled; validation is bespoke. Rejected as the primary model. |
| C. Structured PHP registry | **This is the current design** (W1). Requires a code change per event. Rejected outright. |
| **D. Hybrid (recommended shape)** | CPT holds **content + config**; a small **read-only code registry** holds **presentation definitions** (slug → renderer/assets), because a presentation *is* code (a template + CSS + JS). This keeps content data-driven and presentations code-registered — the correct split. |

So the recommendation is **A + D**: `commemorative_event` CPT for events, and a
**theme-owned** presentation registry exposed through the plugin's
`conexao_commemorative_presentations` filter (§7.3) for presentations.

### 3.2 The event record (CPT fields)

`public => false`, `publicly_queryable => false`, `has_archive => false`,
`rewrite => false`, `show_ui => true`, `show_in_rest => true`, `menu_icon` a
dashicon — a pure **admin configuration container**, precedented by
`recruitment_agency` / `permit_employer` (`docs/content-model.md`). It has **no**
front-end URL, **no** archive and **no** sitemap entry, so it cannot leak or create
routing/SEO surface.

| Field (meta key) | Type | Default | Validation | Notes |
|---|---|---|---|---|
| `post_title` | string | — | required | the **PT event name** (canonical) |
| `post_name` (slug) | string | from title | required, unique | a human-readable slug; **not** the identity (see `_ce_event_key`) |
| `_ce_event_key` | string | `''` | `[a-z0-9_]+`; **unique across logical events** (see the rule below) | **the stable logical identity**. Used in logs, preview tokens, tests and cross-language resolution. Never a local post ID, never a translated slug. |
| `_ce_description` | text | `''` | `sanitize_textarea_field` | **internal, administrator-only** description — **not user-facing, not translated** (language-neutral; stored on the PT record) |
| `_ce_enabled` | bool | `true` | bool | **state A** — the *scheduling* switch. See §3.3 for its exact relationship to post status. |
| `_ce_priority` | int | `10` | int, `0..100` | higher wins on overlap (§7) |
| `_ce_presentation` | string | `''` | must be a **registered** presentation slug | connects event → renderer; unknown slug ⇒ never displayed (safe failure) |
| `_ce_rule` | enum string | `fixed_annual` | one of `fixed_annual`,`variable_annual`,`one_off`,`window` | the scheduling rule (§4) |
| `_ce_date` | `MM-DD` | `''` | valid month-day | for `fixed_annual` |
| `_ce_variable` | enum string | `easter` | known variable key | for `variable_annual` |
| `_ce_one_off_date` | `Y-m-d` | `''` | valid date | for `one_off` |
| `_ce_window_start` | `MM-DD` | `''` | valid month-day | for `window` (e.g. New Year `12-26`) |
| `_ce_window_end` | `MM-DD` | `''` | valid month-day | for `window` (e.g. New Year `01-01`) |
| `_ce_lead_in_days` | int | `15` | `0..120` | for the three "occurrence" rules |
| `_ce_allow_concurrent` | bool | `false` | bool | **DEFERRED (r2)** — opt-in coexistence; not in the initial release (§6) |
| `_ce_force_until` | `Y-m-d H:i:s` (site tz) | `''` | valid future datetime | **DEFERRED (r2)** — temporary public override; not in the initial release (§5.5) |
| `_ce_heading` / `_ce_subheading` / `_ce_cta_label` / `_ce_cta_url` | string | `''` | text / `esc_url_raw` | optional **per-event content overrides**; empty ⇒ the presentation's default (localized) copy |
| `_ce_assets` | int (attachment IDs) / string | `''` | attachment id or URL | **DEFERRED (r5)** — event-specific media; v1 presentations use their own theme-managed assets |
| `_ce_presentation_options` | json | `{}` | per-presentation schema | reserved for per-presentation knobs (see §6.4); validated against the presentation's declared schema |

**`_ce_event_key` uniqueness (defined precisely in r4).** The r2/r3 wording ("unique across
all languages") contradicted "identical on both language records". The correct rule is:

1. **Unique across *logical* commemorative events** — no two distinct events share a key.
2. **Identical across the translations of one event** — the PT and EN records carry the
   *same* key.
3. **A repeated value is permitted only among records linked as translations of that
   event** — repetition is legal *exactly* when Polylang links the records.
4. **Exactly one canonical Portuguese record per logical event.**

Enforced by a save-time check (reject a key already used by a **non-linked** record) plus a
permanent gate (no two **unlinked** records share a key). The key is present on **both** the
PT and EN records — it is the identity — and it is the **only** shared `_ce_*` value an EN
record carries (§3.6).

**What stays internal implementation detail (never admin fields):** the computed
window (`start`/`end`), the `enabled/eligible/displayed` verdicts, the
"reason not displayed" string, the preview token, the clock, the registry, and the
asset handles. These are **derived** and must be recomputed, never stored — so they
can never go stale.

### 3.3 Enabled vs post status — one source of truth each (resolves the r1 contradiction)

The r1 draft said both "enable/disable maps onto post status" and "`_ce_enabled` is a
field". That is a contradiction; r2 resolves it explicitly:

| Concern | Owner | Meaning |
|---|---|---|
| **Editorial lifecycle** | **post status** (`draft` / `publish` / `trash`) | whether the record exists as an editable, published piece of configuration. A `draft` or trashed record is **never** eligible. |
| **Scheduling participation** | **`_ce_enabled`** | whether a *published* event takes part in automatic scheduling. |

**Single rule:** an event is eligible only when **`post_status === 'publish'` AND
`_ce_enabled === true`** (and its window contains now). Neither value is derived from
the other, and neither is a second copy of the other. The admin list shows both
("Estado" = published/draft + enabled/disabled) so the distinction is visible. There is
**no** "enable/disable by changing status" shortcut.

*(Alternative considered and rejected: use post status alone as the activation switch.
It works, but it conflates "I am still editing this" with "this should not run this
year", and it makes a future bulk "disable for this season without unpublishing"
operation impossible.)*

### 3.4 Fields to implement now vs defer

| Now | Defer |
|---|---|
| name, slug, `event_key`, description, `enabled`, `priority`, `presentation`, all scheduling fields, heading/subheading/CTA overrides | `_ce_assets` uploads (the presentations ship their own art initially), `_ce_presentation_options` json, event **categories/types**, mobile-specific presentation flags, per-event CTA animation toggles, **`allow_concurrent` (concurrency)**, **`force_until` (public override)** |
| **Reason:** every "now" field is required to satisfy §4–§7 of the request. The deferred set is cosmetic or speculative; adding it later needs **no engine change** because it is either a presentation option or an additive field. | |

### 3.5 Validation & sanitisation rules

- Every field is registered with `register_post_meta(..., 'sanitize_callback' => …)`
  so **every** write path (admin, REST, importer, WP-CLI) is sanitised — the same
  approach already used for `_leisure_lat` / `_leisure_lng` in
  `conexao-data-model/includes/class-meta.php`.
- `MM-DD` and `Y-m-d` are parsed with `DateTime::createFromFormat('!…')` and the
  round-trip format is compared; a mismatch ⇒ store `''` (⇒ never eligible), never a
  coerced date.
- `_ce_presentation` is validated against the **live** registry; an unknown slug is
  rejected at save time **and** treated as non-displayable at read time (defence in
  depth).
- `_ce_priority` is clamped to `0..100`; `_ce_lead_in_days` to `0..120`.
- `_ce_force_until` — **future design note, not a v1 validation requirement** (r5): when the
  deferred public override is introduced, it must parse in the **site timezone**
  (`wp_timezone()`).

### 3.6 Polylang data model — shared configuration vs translated content

An event is **one logical identity with two language records**, linked as Polylang
translations — never two independent identities. The `_ce_event_key` is the identity
that is **shared** across the language pair.

| Field | Shared or translated? | Why |
|---|---|---|
| `_ce_event_key` | **Shared** (identical in both records) | it *is* the identity; resolution and preview key off it |
| `_ce_rule`, `_ce_date`, `_ce_variable`, `_ce_one_off_date`, `_ce_window_start/_end`, `_ce_lead_in_days` | **Shared** | scheduling must not differ by language — otherwise the two languages would activate on different days |
| `_ce_enabled` | **Shared** | enablement is operational, not editorial |
| `_ce_priority` | **Shared** | conflict resolution must be identical in both languages |
| `_ce_presentation` | **Shared** | the same experience must render in both languages |
| `post_title` | **Translated** | the user-visible event name |
| `_ce_description` | **Shared** (language-neutral) | internal admin note; not user-facing, so translation adds no value |
| `_ce_heading`, `_ce_subheading`, `_ce_cta_label` | **Translated** | user-visible copy |
| `_ce_cta_url` | **Translated** (may be identical) | the EN CTA may point at an EN destination |
| `_ce_assets` | **DEFERRED (r5)** | event-specific media is a future extension; presentations ship their own assets in v1 |

**How "shared" is enforced — single source of truth, not mirrored copies (revised in r4):**

- **`_ce_event_key` is the ONE shared `_ce_*` value that the EN record DOES carry.** It is
  the identity, so it must be present and **identical** on both records (§3.2). It is
  explicitly **not** a "scheduling/presentation" key and is **exempt** from the
  no-duplication rule below.
- **Shared configuration is stored ONLY on the canonical Portuguese record.** The engine
  reads it from the PT record whichever language is rendering. It is **not** copied onto the
  EN record.
- **The EN record stores only translated content** (`post_title`, `_ce_heading`,
  `_ce_subheading`, `_ce_cta_label`, `_ce_cta_url`) **plus `_ce_event_key`**. It carries **no
  other** shared scheduling/presentation meta.
- **Polylang's meta-copying is handled per key group (clarified in r5).** Polylang copies
  post meta when a translation is created. The **scheduling/presentation** `_ce_*` keys are
  **excluded** from that copy, while **`_ce_event_key` is explicitly assigned/copied** to the
  linked translation, because it is **required** there. The exact hook name must be
  **verified against the installed Polylang version** during Stage 2.
- The **EN editor renders the shared values read-only** ("inherited from the Portuguese
  event") and **cannot write them**; the PT record is where they are edited.
- **A permanent gate asserts the exact split** — an EN record **must** have `_ce_event_key`
  **equal to** its PT counterpart, and **must not** carry any **other** shared `_ce_*` meta.
  This is a stronger and simpler check than comparing two copies, and it **removes the drift
  class entirely rather than detecting it** (the r2/r3 "equality" gate is superseded).
- Resolution order for rendering: `event_key` → PT record (shared config) →
  `pll_get_post( pt_id, current_lang )` for the **content**, falling back to PT content if no
  translation exists (canonical → PT fallback, consistent with the existing B2 policy). The
  engine reads locale **only** through `conexao_current_locale()`.
- **If** the repository's Polylang architecture turns out to *require* mirrored metadata,
  the fallback is to mirror **and** document the write-through synchronisation (PT save
  propagates to the linked EN record) **and** keep the equality gate. That is the documented
  alternative, **not** the plan; the plan is no duplication.

**The same event never renders twice for one visitor**, because the displayed set is
computed from the **shared config** and the content is resolved *per language* — a
language switch changes the copy, not the number of experiences.

### 3.7 REST exposure of a private CPT (security, not assumption)

The CPT uses `show_in_rest => true` so the editor works. `public => false` is **not**
sufficient evidence that the records are private over REST, so the design requires an
**explicit test**:

- an **unauthenticated** `GET /wp-json/wp/v2/commemorative_event` (and the single-record
  route) must **not** return records or private meta;
- only a user with the CPT capability may read them;
- scheduling/private meta (`_ce_*`) is `show_in_rest => false` unless a consumer
  genuinely needs it (default: **not** exposed);
- asserted by an in-process capability test **and** an HTTP acceptance row.

---

## 4. Scheduling Design

### 4.1 The four rule types

| Rule | Resolves an occurrence? | Input | Example |
|---|---|---|---|
| `fixed_annual` | yes, `MM-DD` | `_ce_date` + `_ce_lead_in_days` | Halloween `10-31`, lead 15 → **Oct 16–31** |
| `variable_annual` | yes, computed | `_ce_variable` (e.g. `easter`) + `_ce_lead_in_days` | Easter + 15 |
| `one_off` | yes, fixed | `_ce_one_off_date` (+ optional lead-in) | a single 2027 anniversary |
| **`window`** | **no** | `_ce_window_start` + `_ce_window_end` | **New Year — seven calendar days, `12-26` → `01-01`** |

### 4.2 Boundary semantics (explicit, testable)

- Start is **inclusive at `00:00:00`** of the start day, in **`wp_timezone()`**.
- End is **inclusive at `23:59:59`** of the end day, in **`wp_timezone()`**.
- Eligibility is `start <= now <= end`.
- The clock is **injected**: `resolve_window( ?DateTimeInterface $now = null )`, where
  `null` means "now". Tests pass a fixed `$now` in `Europe/Dublin`; production passes
  `null`. This single change removes the date-dependent-test defect (W6) and makes
  every scheduling claim reproducible.

### 4.3 Year-boundary resolution (fixes W2/W3/W4)

Two mechanisms, in order:

1. **Nearest-occurrence resolution — annual rules only (corrected in r3).** For the two
   **annual** occurrence rules (`fixed_annual`, `variable_annual`), evaluate the candidate
   occurrence in the **current year and the adjacent years** and select the occurrence
   whose window actually contains `now`. This makes any early-January event whose lead-in
   reaches into the previous December work — generically, with no special-casing.
   **`one_off` is excluded from this search:** it resolves to its **exact stored date
   only**, never recurs and never searches neighbouring years (its window is that date's
   window, or not eligible at all once the date has passed).
2. **Explicit `window` rule (the exact expression of New Year).** When
   `_ce_window_start` / `_ce_window_end` are set, the window is used verbatim and may
   cross 31 December. The resolver handles the wrap: if `start > end` in month-day
   terms, the window spans two calendar years, and `now` matches when it is
   `>= start` **or** `<= end`.

**The New Year requirement, expressed without any current-year comparison:**

```
_ce_rule           = window
_ce_window_start   = 12-26
_ce_window_end     = 01-01
```

### 4.4 Worked examples (the acceptance table from §5 of the request)

New Year, `window 12-26 → 01-01`, site tz `Europe/Dublin`:

| `now` | In window? | Why |
|---|---|---|
| 2026-12-25 12:00 | **no** | before `12-26` |
| 2026-12-26 00:00 | **yes** | start boundary, inclusive |
| 2026-12-31 23:59 | **yes** | inside the wrap |
| 2027-01-01 00:00 | **yes** | `<= end` |
| 2027-01-01 23:59:59 | **yes** | end boundary, inclusive |
| 2027-01-02 00:00 | **no** | after `01-01` |

Halloween, `fixed_annual 10-31`, lead 15:

| `now` | In window? |
|---|---|
| 2026-10-15 23:59 | no |
| 2026-10-16 00:00 | yes (start inclusive) |
| 2026-10-31 23:59:59 | yes (end inclusive) |
| 2026-11-01 00:00 | no |

Christmas, `fixed_annual 12-25`, lead 15 → **Dec 10–25** (current behaviour preserved).

Easter (variable), lead 15 → **15 days before the computed Easter Sunday through
Easter Sunday**. Leap years are handled by the Gregorian computation already present.

### 4.5 Additional date-handling decisions

- **Timezone:** always `wp_timezone()` (verified `Europe/Dublin` locally, §12). The
  `Europe/Lisbon` fallback (W14) is corrected to `Europe/Dublin` in Stage 1.
- **Date-only vs date-time:** scheduling is **date-only** (day granularity) in v1.
  `_ce_force_until` (the deferred public override) is a **date-time** — recorded here as a
  **future design note**, **not** a v1 implementation requirement (r4).
- **Leap years:** a `02-29` `fixed_annual` date is **rejected at save time** with a
  clear admin message ("29 February only exists in leap years") rather than silently
  never firing (W15). A future "nearest day" policy can be an additive rule.
- **One-off events:** resolve to the single date; if the year has passed, the event
  is simply never eligible again (no cleanup needed).
- **Overlaps:** resolved by priority (§7), never by array order.
- **Cron is used for cache invalidation only, never for eligibility.** Eligibility stays
  request-time and authoritative (§4.6). A scheduled purge at a window boundary does not
  *decide* whether an event is active — it only ensures a cached page is refreshed at the
  moment the request-time decision changes.
- The **temporary public override is deferred out of the initial release** (r2, §5.5), so
  the only time-boxed value in v1 is none; when it is introduced later it is a date-time
  evaluated at read time, still with no cron dependency.

### 4.6 Caching and the activation boundary (rewritten in r3)

**The problem.** Request-time PHP eligibility is correct **only when PHP runs**. If the
homepage is served from a full-page cache (or a CDN/edge cache), a page rendered on
**Dec 25** can keep being served on **Dec 26**, so the New Year experience would not
appear at its boundary — and an expired experience could linger past its end (Jan 2).
The repository has language-scoped **transient** caches (`inc/cache.php`, 300 s TTL);
production is **WordPress.com**, whose **edge/page cache sits outside this repository**
and cannot be inspected or configured from it.

**The r2 contradiction — removed in r3.** r2 proposed a client-side "re-check the marker
and reveal at the boundary" fallback. That is **impossible as specified**: §7.5 enqueues
a presentation's JS **only when it is already displayed**, so a page cached *before*
activation contains **neither the markup nor the initialiser**. There is nothing on the
page that could reveal the experience later. r3 **deletes that fallback entirely.** The
server-rendered state is the only state; a boundary is crossed by **refreshing the
cached HTML**, never by client-side revelation.

**Two explicit guarantees — the plan must choose one and state it truthfully:**

| | **Option A — Reliable boundary activation** *(preferred)* | **Option B — Bounded activation delay** |
|---|---|---|
| **Requires (1)** | a **confirmed scheduler** — the job actually executes **at the required time, independently of ordinary (cached) page requests** | — |
| **Requires (2)** | a **confirmed purge** — the executed job can purge the relevant WordPress.com page/CDN cache | — |
| Fallback | — | the **effective maximum** page-cache lifetime across **all** layers (or no full-page cache) |
| Guarantee | activation/deactivation **at** the boundary | activation/deactivation **within the verified TTL** of the boundary |
| Claim | "active from 26 Dec 00:00 (site tz)" | "active **within ≤ TTL** of 26 Dec 00:00 (site tz)" |
| Status | **not verifiable from this repo** — **both (1) and (2)** must be confirmed against production | the honest default until Option A is confirmed |

**Execution guarantee — the half of Option A that was missing (added in r4).** WP-Cron is
**request-driven**: a scheduled event fires when a request reaches PHP (or via WP-CLI /
`wp cron event run`). On a **fully cached** site, ordinary front-end requests may **never**
reach PHP, so a scheduled event can sit **unexecuted** — and the `shutdown` fail-safe
**cannot help**, because it only runs *if* PHP is already executing. Therefore **Option A
requires two independent confirmations**, and the report previously covered only the
second:

1. **A confirmed scheduler** — a real system-level cron (WP-CLI `wp cron event run`, a host
   cron, or an equivalent), **or** a documented WordPress.com mechanism that runs due
   events **independently of cached page requests**.
2. **A confirmed purge** — that the executed job can purge the relevant WordPress.com
   page/CDN cache (via `conexao_commemorative_purge_caches` or a host-specific mechanism).

**Until both (1) and (2) are confirmed, Option B is the honest assumption.** Option B's
guarantee is *"at the first PHP request after the boundary, or within the verified TTL —
whichever is sooner"*, because a **due-boundary check runs on every PHP request** (step 2)
and a missed check self-heals within the TTL. The plan must not claim exact boundary
activation on the strength of the purge alone.

**Option B must use the *effective maximum* cache lifetime (added in r5)** — the longest of
every relevant layer (object/transient cache, page cache, CDN/edge cache, and any
stale-serving behaviour). A five-minute **origin** cache does **not** prove a five-minute
maximum if another layer can keep serving older HTML; the documented bound must reflect the
**slowest** layer. (Production verification of this remains separately authorised.)

**The plan MUST NOT claim exact boundary activation unless Option A is confirmed.** If
only Option B holds, the documented guarantee is the bounded delay (e.g. "within ~5
minutes", from a verified TTL), and both the runbook and any user-facing statement say so.

**Design (Option A path; Option B is the documented fallback):**

1. **Authoritative request-time state (unchanged).** The engine computes the displayed
   set per request — the single source of truth for *what should be shown*.
2. **Boundary scheduling + a per-request fallback.** On `save_post_commemorative_event`
   (and on plugin/CPT activation) compute the **next boundary** (next window start **or**
   end, in `wp_timezone()`) and schedule **one** `wp_schedule_single_event()`. Reuse the
   `conexao-event-importer` continuation pattern (single scheduled event **plus a
   `shutdown` fail-safe**). **Additionally, run a cheap "is a boundary due?" check on every
   PHP request** — the scheduled event is the *timely* trigger; the per-request check is the
   *fallback* when no independent scheduler executes. The `shutdown` fail-safe is retained
   but is **explicitly not sufficient** on a fully cached site (see the execution guarantee
   above).
3. **Targeted invalidation — never a global flush (corrected in r3).** The purge deletes
   **only** the system's own commemorative keys: the language-scoped commemorative
   transients (every language, via the existing `conexao_flush_language_cache()`-style
   helper) and the plugin's own object-cache group — then fires the documented
   **`conexao_commemorative_purge_caches`** action so a host/CDN integration can purge its
   page cache. It does **NOT** call `wp_cache_flush()` and does **NOT** call
   `delete_expired_transients()`: a global object-cache flush or a global transient sweep
   for one commemorative event is disproportionate and would evict unrelated caches.
4. **Re-arm the next boundary (added in r3).** After a purge runs, the handler
   **immediately schedules the next** boundary — e.g. after the 26 Dec activation purge
   it schedules the 2 Jan deactivation purge — so the chain of boundaries is never left
   unarmed. Asserted by a test.
5. **Recalculate on every relevant change, *after* the change is persisted (added in r5).**
   The next boundary is recomputed after: **creating or editing** an event; **changing its
   scheduling configuration**; **enabling/disabling** it; **publishing / unpublishing /
   trashing** it; and **deleting** it. The recalculation must run **after the metadata has
   been persisted** — never before — otherwise the scheduler would compute boundaries from
   the *old* configuration. It therefore hangs off the post-persist hooks:
   `save_post_commemorative_event` (after the meta write) and `added_post_meta` /
   `updated_post_meta` / **`deleted_post_meta`** for the scheduling keys;
   `transition_post_status` for publish / unpublish / trash; and **`deleted_post`** — which
   fires **after** the post is removed — for **permanent deletion**. It must **not** use
   `before_delete_post` (corrected in r6): that hook fires **before** the metadata and
   associated data are removed, so it would recalculate from the record that is about to
   disappear rather than from the final persisted state. Trash/unpublish is handled by
   `transition_post_status` and is kept **separate** from permanent deletion. Each hook
   **re-arms** (never stacks) a single boundary event.
6. **Concurrency lock around the per-request check (added in r5).** Because the due-boundary
   check runs on **every PHP request**, simultaneous requests could otherwise all conclude a
   boundary is due and run **multiple purges**. A **non-blocking atomic lock** (a short-lived
   object-cache/option lock — the first request wins, the others skip) guarantees **at most
   one** purge per boundary. The lock is released on completion and **expires
   automatically**, so a crashed request cannot wedge it.
7. **Operable from wp-admin.** Because production has **no CLI**, a **"Purge
   commemorative caches" admin action** (nonce + capability) lets a maintainer force a
   purge; the admin list shows the **next scheduled boundary** and the **last purge
   result**.
8. **Verification is a separate, authorised activity (clarified in r3).** Host/CDN
   behaviour **cannot be verified from this repository**. Implementation and testing are
   **local only — no production write, no deploy, no production content change**. Any
   production verification (confirming the purge mechanism for Option A, or measuring the
   real TTL for Option B) is a **separately authorised activity** (see
   `wp-production-operations`), and is recorded as a limitation until performed.

**Explicit requirements this adds to the plan:**

- an event must appear at its activation boundary and disappear at its deactivation
  boundary **to the guarantee actually chosen** (Option A: exact; Option B: within the
  verified TTL) — and the guarantee must be stated, not implied;
- both **transitions** (activation **and** deactivation) are tested, not just "is active";
- the design must **not** rely exclusively on request-time PHP when cached HTML can
  bypass PHP, and must **not** rely on client-side revelation (removed in r3);
- **Option A requires confirming BOTH the scheduler and the purge** (added in r4) — a
  confirmed purge alone does not guarantee timely activation on a cached site;
- the purge is **targeted** and **re-arms the next boundary**;
- the purge path is operable **from wp-admin** (production has no CLI).

---

## 5. Administration UX Proposal

### 5.1 The three states, kept strictly separate

This is the core of §6 of the request. They are **three different controls**, never
one checkbox:

| State | Control | Persisted? | Who sees it | Purpose |
|---|---|---|---|---|
| **A. Enabled** | `_ce_enabled` toggle on the event | **yes** | nobody (it is a config flag) | "this event may participate in scheduling" |
| **B. Preview** | a **signed preview link** (`?conexao_preview=<event_key>&_wpnonce=…`) | **no** | only the requesting logged-in admin, for that one request | test any event, any date, any language, on desktop/mobile, on the **real site** |
| **C. Temporary public override** | `_ce_force_until` (date-time) | **yes, but self-expiring** | **everyone** until expiry | deliberately show an event to the public for a short, bounded window — **DEFERRED out of the initial release (r2, §5.5)** |

Distinguishing rule: **A is configuration, B is private and request-scoped, C is
public and time-boxed.** They never share a value.

### 5.2 The event list screen (satisfies "clear indication" requirements)

Registered through `conexao-admin-ux`'s config (`Conexao_Admin_Ux_Config` →
`Conexao_Admin_Ux_List`), so it inherits the existing look/behaviour:

| Column | Shows |
|---|---|
| **Estado** | Enabled / Disabled (badge) |
| **Janela calculada** | the engine's computed `start → end` for the **next** occurrence, e.g. `2026-10-16 → 2026-10-31` |
| **Elegível agora?** | Yes / No |
| **Motivo** | when not displayed: `disabled`, `outside window (next 2026-12-10)`, `unknown presentation`, `superseded by "<higher-priority event>"` |
| **Prioridade** | the integer |
| **Pré-visualizar** | a **Preview** row action that generates the signed link |

A **dashboard summary** ("Commemorative events": active now / next to start) mirrors the
existing `summary` pattern in the admin UX config.

### 5.3 The event editor (sections, not a wall of meta boxes)

Reusing the existing section/field renderer (`Conexao_Admin_Ux_Editor`,
`Conexao_Admin_Ux_Fields`), the editor is four collapsible sections:

1. **Informação** — name, slug, admin description, enabled, priority.
2. **Agendamento** — rule type; the fields that rule needs (date / variable / one-off
   / window); lead-in days; a **read-only computed-window preview** that updates as
   the admin types, plus a **"Test this date"** box that shows eligible/not for an
   arbitrary date (this is where "preview Christmas in July" is *evaluated* without
   touching the schedule).
3. **Apresentação** — presentation slug (a `<select>` of registered presentations) and the
   heading/subheading/CTA overrides. **No asset management in v1** (§3.4): each presentation
   uses its own theme-managed assets.
4. **Administração** — the **Preview** link, and the read-only **date-simulation**
   inspector (§5.6). There is **no public-override control in v1** (§5.5): the deferred
   `_ce_force_until` field is **not rendered in the editor** until that feature is
   separately approved and designed.

### 5.4 Preview mechanism (state B) — the recommended implementation

- The admin clicks **Preview**; the server mints
  `?conexao_preview=<event_key>&_wpnonce=<nonce-for-this-key>` (the **`event_key`**, not
  a translated slug, so the link works in both languages).
- On any front-end request, the engine honours the flag **only if all hold**:
  `is_user_logged_in()` **and** `current_user_can( 'manage_options' )` **and**
  `current_user_can( 'edit_post', $post_id )` **and** `wp_verify_nonce()` **and** the
  `event_key` names an existing event. Any failure ⇒ the flag is ignored silently (no error
  oracle). *(`edit_post` was missing from this first statement until r7 — it is the same
  conjunctive check stated in the r4 paragraph below; both must agree.)*
- While honoured, the engine returns the previewed event as **displayed**, and the
  renderer emits a visible, non-dismissible **PREVIEW banner** (and a `data-preview`
  attribute) so it can never be mistaken for public activation.
- **Preview must actively bypass page caches, not merely ask not to be cached.** Marking
  a response non-cacheable (or excluding the query parameter from the cache key) is
  **insufficient**, because an upstream page cache / CDN may ignore the headers and
  serve a cached copy. The preview request therefore: sets the WP-standard
  `DONOTCACHEPAGE` **and** sends `Cache-Control: no-store, private` + `nocache_headers()`,
  **and** is excluded from the cache key, **and** carries a unique cache-busting token so
  no shared cache can ever satisfy a different request with a previewed page. If a host
  cache cannot be bypassed, the preview must **fail closed** (no preview) rather than
  risk serving a previewed page publicly.
- The flag **changes nothing persisted** — no schedule edit, no status edit.
- **Preview covers every editorial/scheduling state (added in r3; capability made explicit
  in r4).** It works for a **published but disabled** event, an **unpublished draft**, and an
  event **outside its scheduled window** — so an administrator can evaluate an unfinished
  experience **without publishing it and without touching its scheduling configuration**.
  The authorisation check is **explicit and conjunctive**:
  `is_user_logged_in()` **AND** `current_user_can( 'manage_options' )` **AND**
  `current_user_can( 'edit_post', $post_id )` **AND** a valid nonce for the `event_key`.
  A user who cannot **edit** the record cannot preview it — **reading is not enough**.
  Draft, disabled and out-of-window events are permitted **only after** those checks. The
  preview is **never** available to unauthenticated visitors, and remains private (banner +
  cache bypass + no persistence).
- It works for **PT and EN** because it is orthogonal to locale (the admin can add
  `&lang=en`), and for **desktop and mobile** because it is the real site.

### 5.5 Temporary override (state C) — DEFERRED out of the initial release (r2)

**Decision changed in r2: this is *not* in the first release.**

Rationale: the original requirement was to **test an event before its scheduled
activation**, and **private preview (state B) fully solves that**. A public override is
a *second* way to accidentally expose an unfinished experience to every visitor, so it
is deferred until there is a real need, and then introduced as a **separate, explicitly
approved feature** with a **strict maximum duration** (a hard cap enforced by the
engine, not merely a UI hint).

When it *is* introduced, it must satisfy: separate field (`_ce_force_until`),
self-expiring, admin-only, never settable via a query parameter, surfaced as a red
notice + list badge with one-click clear, and it must **not** resurrect a disabled event.

**Initial-release controls:** **A (Enabled)** and **B (Preview)** only.

### 5.6 Preview semantics — force-display vs date simulation (clarified in r2)

The r1 wording "preview any event, any date" conflated two different capabilities.
r2 separates them explicitly:

| Capability | What it does | In the initial release? |
|---|---|---|
| **Force-display preview** | Renders a chosen **event's presentation** on the real site for the requesting admin, regardless of whether it is currently eligible. It does **not** change any date and does **not** simulate time. | **Yes** |
| **Date simulation (scheduling inspector)** | Evaluates "would this event be eligible at date X?" and shows the computed window/reason **without rendering the public experience**. This is the tool for verifying scheduling and date-dependent behaviour (e.g. New Year on Dec 26/31/Jan 1/2). | **Yes — as a read-only admin diagnostic**, not a public render |

So: **preview = force the presentation to appear**; **date simulation = inspect
eligibility at an arbitrary date**. Neither modifies the stored schedule.

---

## 6. Multiple Events and Conflicts

### 6.1 The resolution algorithm (explicit, deterministic, documented)

Given the events whose **window contains `now`** (the *eligible* set), the engine
computes the *displayed* set as:

1. Filter to `enabled === true` (a disabled event is **never** displayed, even inside
   its window; the admin must enable it explicitly).
2. **Sort deterministically:** by `_ce_priority` **descending**, then by a **stable,
   documented tiebreaker** — **`_ce_event_key` ascending** (never the translated slug,
   which differs per language, and never file/DB order).
3. **Primary presentation** = the first element. **In the initial release it is the
   only one rendered** — no stacking, no secondary slot, no randomness.
4. **Explain the rest:** every other eligible event is recorded with a reason
   (`superseded by "<event_key>"`), which the admin list shows. This is the whole of
   v1's conflict handling — deliberately simple and predictable.

### 6.2 The requested scenarios

| Scenario | Behaviour |
|---|---|
| Only one eligible | it is primary and displayed |
| Two eligible, different priority | **higher priority wins**; the other is recorded as `superseded by "<x>"` |
| Two eligible, same priority | **`_ce_event_key` ascending** decides (documented, stable, language-neutral) |
| Two explicitly configured to coexist | **DEFERRED (r2)** — not in the initial release; only one presentation renders |
| Enabled but not yet eligible | **not displayed**, reason `outside window (next …)` |
| Disabled but inside its window | **never displayed**, reason `disabled` |
| Unknown/removed presentation | **never displayed**, reason `unknown presentation` |
| Override live | **DEFERRED (r2)** — the public override is out of the initial release |

### 6.3 Why not "first in array order"

The current engine returns `$active[0]` (W5). That couples behaviour to the order of a
PHP literal — invisible, untestable and unchangeable by an administrator. The
priority field makes the decision **explicit, editable and testable**, and the
`reason` string makes it **explainable** in wp-admin.

### 6.4 Future presentation types are decoupled from event identity

Because the engine dispatches on the **`presentation` slug** (not on `'halloween'`),
a new event with an existing presentation is pure configuration; a genuinely new
visual is a new *presentation* (template + CSS + JS) registered once and then
selectable by **any** event. The engine needs no change for either.

---

## 7. Presentation Architecture and UX

### 7.1 Should the Halloween presentation be kept? — Extract the *functionality*, not the *design*

The Halloween **functionality and interactions** are worth preserving: non-modal,
dismissible, keyboard-accessible, reduced-motion aware, no layout shift, JS-optional
(markup renders server-side and starts hidden). What must change is that it stops
being *the theme's Halloween code* and becomes **one registered presentation** among
several.

**Corrected in r2:** the migration does **not** require the Halloween visuals to be
preserved byte-for-byte, and the current visual design is **not** the reference
standard for the new framework. The whole reason for this work is that the Halloween
implementation was built in a hurry, so the migration must **allow its visual design to
be improved within the new framework**. The acceptance criterion is therefore
*functional* (the same interactions, accessibility, reduced-motion and no-JS behaviour),
**not** *pixel-identical output*. (No browser/visual audit was performed in r1, so a
byte-for-byte requirement could not have been justified anyway.)

### 7.2 The four-layer structure

| Layer | Contains | Where |
|---|---|---|
| **Framework (shared capabilities)** | **reusable, opt-in** capabilities: an overlay/hero shell, a decoration layer, a dismiss + `inert`/focus helper, `prefers-reduced-motion` handling, a session-dismissal key helper, i18n plumbing. A presentation opts into what it needs — this is **not** a mandatory overlay structure (r4). | theme: `inc/commemorative-presentation.php` (presentation only) |
| **Reusable components** | tokens/variables (`--ce-*`), the decorative layer, the shared entrance/exit animation utilities, the shared "no-JS fallback" pattern | theme: `assets/css/commemorative.css`, `assets/js/commemorative.js` |
| **Event-specific presentations** | one template part + one CSS + one optional JS per presentation | theme: `template-parts/presentations/<slug>.php`, `assets/css/presentations/<slug>.css`, `assets/js/presentations/<slug>.js` |
| **Event-specific assets** | art (SVG/WebP), loaded only when that presentation is displayed | theme: `assets/images/presentations/<slug>/…` |

### 7.3 Presentation registration API — the **theme** owns the registry

Presentations are **code**, so they are registered in code — but by the **theme**, not
by the platform plugin (corrected in r2, §2). The plugin provides the seam; the theme
declares what it can render:

```php
// THEME (inc/commemorative-presentation.php) — the theme's registry.
add_filter( 'conexao_commemorative_presentations', function ( $presentations ) {
    $presentations['halloween'] = array(
        'label'   => __( 'Halloween (bats, pumpkin, witch)', 'conexao-br-irlanda' ),
        'scope'   => 'homepage',   // homepage | site_wide | page | combined  (§7.4)
        'template'=> 'template-parts/presentations/halloween',
        'styles'  => array( 'assets/css/presentations/halloween.css' ),
        'scripts' => array( 'assets/js/presentations/halloween.js' ),
        'defaults'=> array(
            'heading' => __( 'Feliz Halloween!', 'conexao-br-irlanda' ),
            'cta'     => __( 'Explorar eventos', 'conexao-br-irlanda' ),
            'cta_url' => '/eventos/',
        ),
    );
    return $presentations;
} );
```

The **plugin** only ever sees the returned array's **keys** (so it can validate that a
stored `_ce_presentation` names a registered presentation, and so the admin `<select>`
can be populated). It never resolves a template path or an asset file — preserving the
plugin's theme independence (§2).

**Explicit limitation (added in r2):** an administrator can create and configure
**events** using **existing** presentation types without any development work, but
**introducing a completely new visual experience normally requires development work**
(a new registered presentation: a template + CSS + optional JS). The system makes new
events free; it does not make new *visual designs* free. This is stated so it is not
mistaken for a no-code guarantee.

### 7.4 Presentation scope — four first-class options (corrected in r2)

**r1 wrongly locked the architecture to the homepage overlay** and rejected a
dedicated page on the basis of a constraint that was not actually given. r2 makes
**scope** an explicit, registered property of a presentation, so the architecture
**does not prevent** any of the directions the request asked to evaluate:

| Scope | Example | What it renders |
|---|---|---|
| `homepage` | a large Christmas or Halloween hero | a presentation slot on the front page only |
| `site_wide` | subtle snow, festive navigation effects, Halloween decorations | a decoration layer injected on every page (bounded, non-blocking) |
| `page` | a complete New Year experience | a **dedicated commemorative page** (see the routing note below) |
| `combined` | homepage hero **plus** site-wide decorations | both of the above for one event |

The registry entry carries `scope` (§7.3); the theme dispatcher renders the
appropriate slot(s). **Not all scopes need to ship in v1**, but the model must
**support** them so a later event can choose a scope without re-architecting.

**On the dedicated-page scope (the r1 concern, restated honestly):** a `page` scope
*does* introduce a route, which interacts with `docs/routing.md`, canonical URLs,
hreflang, the sitemap and the redirect precedence gate. That is a **cost to manage**,
not a reason to forbid the option. If the `page` scope is implemented it must:

- be an **opt-in route** per event (never automatic), off by default;
- resolve through the **existing** routing/redirect layer (no new routing mechanism);
- be **excluded from the sitemap unless deliberately included**, and carry correct
  canonical/hreflang for PT/EN;
- be covered by the HTTP acceptance matrix (status, canonical, hreflang, sitemap).

**Recommendation:** ship `homepage` and `site_wide` first (they reuse the proven,
verified model and need no routing change); implement `page`/`combined` **only when an
event actually needs them**, behind the same registry, with the routing work above.

**Accessibility and quality requirements are scope-specific (corrected in r4).** r3 applied
one overlay-shaped rule set to every scope, which is wrong for a dedicated page — a normal
landing page needs no dismiss button. The shared framework provides **reusable
capabilities**, and each scope applies what fits:

| Requirement | `homepage` overlay | `site_wide` decoration | `page` (dedicated) | `combined` |
|---|---|---|---|---|
| Never blocks content / navigation | **required** | **required** | **required** | **required** |
| Keyboard accessible | **required** | **required** | **required** | **required** |
| Reduced-motion honoured | **required** | **required** | **required** | **required** |
| No layout instability | **required** | **required** | **required** | **required** |
| JS-optional (content visible without JS) | **required** | n/a (decoration only) | **required** | **required** |
| Dismissible + focus return | **required** | **where applicable** | **not required** | per sub-scope |
| CPU-bounded | **required** | **required** | **required** | **required** |

A `site_wide` decoration is purely decorative: it must never intercept pointer events, never
move content, and may be omitted entirely under reduced motion. A `page` scope is a normal,
fully accessible page — it is **not** wrapped in an overlay and does **not** require a
dismiss control.

### 7.5 Conditional asset loading (fixes W8)

- Each presentation's CSS/JS is enqueued **only when the engine reports that
  presentation as displayed** (hooked on `wp_enqueue_scripts`).
- When **no** event is active (the overwhelmingly common case, ~11 months a year),
  **zero** commemorative CSS/JS/images are emitted — the site returns to its
  pre-commemorative weight.
- The **shared** framework CSS/JS is tiny and also conditional on "some presentation
  is displayed".
- The current `main.js`/`main.css` Halloween block is **removed** from the global
  bundle and moved to the presentation files, so the global bundle shrinks too.

---

## 8. Migration Strategy

### 8.1 Disposition of every existing artifact

| Artifact | Disposition | Detail |
|---|---|---|
| `inc/occasion.php` date math (timezone, Easter algorithm, inclusive boundaries) | **Reuse** | moved into the engine class, unchanged in behaviour |
| `inc/occasion.php` `ocasons_register()` hard-coded array | **Replace** | becomes the **seed** for six `commemorative_event` records (§8.2) |
| `inc/occasion.php` public API (`ocons_get_primary_active()` etc.) | **Refactor → shim** | the engine exposes a clean API; the old names become **thin deprecated wrappers** for one stage, then removed |
| `inc/occasion.php` `is_active()` (implicit clock) | **Refactor** | becomes clock-injected `resolve_window($now = null)` |
| `inc/occasion.php` `Europe/Lisbon` fallback (W14) | **Fix** | `Europe/Dublin` |
| `template-parts/halloween-hero.php` | **Refactor** | split into the shared shell + `template-parts/presentations/halloween.php`; **functional behaviour preserved** — markup and visuals **may change** (§7.1, corrected in r4) |
| Halloween block in `assets/css/main.css` + `dark-mode.css` | **Move** | to `assets/css/presentations/halloween.css` (+ dark section) |
| `initHalloweenWelcome()` + `initHalloweenNavEffects()` in `main.js` | **Move** | to `assets/js/presentations/halloween.js`; the shared dismiss/inert/focus helper stays in `commemorative.js` |
| `front-page.php` `'halloween' === …` check | **Replace** | one call to the presentation dispatcher (no event identity in the template) |
| `header.php` `'halloween' === conexao_seasonal_variant()` marker | **Replace** | the dispatcher emits the marker for whichever presentation requests it |
| `witch-broom.svg` | **Move** | to `assets/images/presentations/halloween/` |
| `tests/test-seasonal-occasion.php` | **Refactor** | deterministic, clock-injected; adds window/priority cases |
| `tests/test-seasonal-halloween-*` | **Repair + refactor** | remove the date-dependent assertion (clock injection); **restore the code to the approved cadence** so the existing assertions pass — do **not** edit the tests to match the regressed code (W13); add a **negative** proof (no commemorative bytes when inactive) |

### 8.2 Seeding the six existing events (behaviour-preserving)

A one-shot, **idempotent, dry-run-first** content change (engineering standard §5.2 /
`wp-content-change`) creates one `commemorative_event` per existing registry entry,
mapping `id → _ce_event_key` (plus a slug and the PT/EN translation link) and carrying
the current dates/lead-in/presentation, so the **observable behaviour is identical**
(Halloween still Oct 16–31, Christmas Dec 10–25, etc.). The seed prints
created/skipped counts and is re-runnable. **New Year is seeded with the corrected
`window 12-26 → 01-01`** — the one intentional behaviour change, which is the *point* of
the redesign and is called out explicitly in the plan.

**No runtime fallback to a built-in list (corrected in r2).** The engine reads **only**
the CPT records. There is **no** "if the CPT is empty, use the hard-coded six" fallback:
such a fallback would make a deliberately deleted or removed record **reappear**, which
is a data-integrity defect. The six records are created by the **controlled one-time
migration** (dry-run → apply → verify), and after that the CPT is the only source of
truth. A brand-new install simply has zero events until an administrator creates one.

### 8.3 What is explicitly removed (and when)

- The scattered `'halloween'` literals (Stage 3).
- The duplicated `ocasons_*` / `ocons_*` / `ocacons_*` aliases (Stage 1 keeps shims;
  a later stage deletes them).
- `get_display_content()` (dead code).
- The Halloween block from the global `main.js` / `main.css` (Stage 3).

### 8.4 Compatibility guarantees

- **Halloween's functional behaviour is preserved** for its window throughout the
  migration — the interactions, accessibility, dismissal, navigation effects and
  reduced-motion support — asserted by the Halloween suites plus an HTTP acceptance row
  that checks **presence and behaviour, not pixel-identical markup**. The **visual design
  may change** (§7.1). *(Corrected in r3: r2 still required "renders identically".)*
- **PT content, routes, canonical, hreflang and sitemap are untouched** — the CPT has
  no public route.
- **No production action** at any stage; the migration is a local, tested,
  releaseable change.

---

## 9. Testing and Verification Plan

### 9.1 Principle: deterministic by construction

Every scheduling test **injects the clock** (`resolve_window($now)`), so no test
depends on the real date. This directly repairs the defect that makes
`test-seasonal-halloween-nav-effects.php` red today (W6/W13). Each suite follows the
existing conventions: `require tests/bootstrap.php`, the shared assertion library,
discovered by convention and run by `./scripts/run-tests.sh`.

### 9.2 Scheduling suite (in-process PHP)

| Case | Injected `now` | Expected |
|---|---|---|
| Fixed annual, default lead-in | `2026-10-16 00:00:00` / `10-31 23:59:59` / `11-01 00:00` | eligible / eligible / not |
| Fixed annual, custom lead-in (e.g. 30) | boundary ±1 day | eligible / not |
| Variable annual (Easter) | Easter Sunday and −15 / −16 days | eligible / not |
| One-off (exact date only) | on date / day after / ±1 year | eligible / not / not — **never searches adjacent years** (r3) |
| **New Year window** | Dec 25 / Dec 26 / Dec 31 / Jan 1 / Jan 2 | no / yes / yes / yes / no |
| Calendar-year transition | Dec 31 → Jan 1 across two years | correct wrap |
| Leap year | `2028-02-29` contexts; `02-29` fixed annual rejected | no silent failure |
| Timezone | same instant in `Europe/Dublin` | correct day boundaries |
| Overlap, distinct priority | two eligible events | higher priority wins; loser reason recorded |
| Overlap, equal priority | two eligible events | **`_ce_event_key` ascending** wins (stable, language-neutral) — corrected in r3 |
| Coexistence | `allow_concurrent` on one | **DEFERRED (r2)** — not in the initial release |
| Disabled inside window | disabled event on its date | never displayed |
| Override live / expired | — | **DEFERRED (r3)** — the public override is not in v1; no test |

### 9.3 Administration suite

- Create/edit/enable/disable an event (capability + nonce enforced).
- Invalid configuration (bad `MM-DD`, unknown presentation slug, `02-29`, negative
  priority) is rejected/normalised and **never** produces an eligible event.
- Missing required information (no title/slug) is refused.
- Preview: a non-admin request **cannot** activate preview; an admin **can**; the
  previewed request renders the event **without changing** the stored schedule or
  status (assert the meta is byte-identical before/after).
- **Preview states (added in r3; capability tightened in r4):** preview works for a
  **published-but-disabled** event, an **unpublished draft**, and an event **outside its
  window**; the check is `manage_options` **AND** `current_user_can( 'edit_post', $post_id )`
  — a user who cannot **edit** the record **cannot** preview it (reading is not enough); no
  preview of a record the user cannot edit.
- **Explicit permission negative (added in r7):** a test asserts that a **logged-in user who
  has `manage_options` but lacks `edit_post` for that specific event** (e.g. a role denied
  editing that record) **cannot** preview it — proving the record-level capability is
  enforced and not merely documented, and that `manage_options` alone is insufficient.
- The **date-simulation inspector** reports eligible/not for an arbitrary injected date
  **without rendering** the experience (no markup, no asset enqueue).

### 9.4 Frontend suite

- **No active event:** homepage contains **no** commemorative markup and the
  commemorative CSS/JS handles are **not enqueued** (the negative proof for W8).
- **One active event:** correct presentation markup, correct localized copy.
- **Multiple eligible:** only the priority winner's presentation renders.
- **PT and EN:** the same event renders once per language with the right copy; no
  duplicate.
- **JS unavailable:** the server-rendered content is present and readable.
- **Reduced motion:** motion is disabled (CSS assertion + the existing guards).
- **Navigation intact:** header/footer/nav and normal content remain reachable.
- **No regression:** the release HTTP smoke matrix (`scripts/verify-deploy.py`) and the
  existing acceptance matrices still pass.

### 9.5 Repository gates to satisfy / add

- `./scripts/run-tests.sh` — the single aggregate command must exit `0` (in-process
  PHP + script contract + HTTP acceptance). Note: the repo currently has a **red**
  seasonal suite (W13) and other pre-existing failures (§12) that must be baselined,
  not hidden.
- `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan.
- `php scripts/generate-registry-docs.php --check` — **required if a new plugin is
  added** (registry drift).
- `python3 scripts/verify-permanent-gates.py` — the Stage L fail-closed gates
  (taxonomy policy, translation completeness, language-scoped caching, redirect
  precedence, documentation drift, i18n freshness).
- **New permanent gates to add** (fail-closed): (a) *no commemorative asset is
  enqueued when no event is displayed*; (b) *a public, unauthenticated request can
  never activate an event via a query parameter*; (c) *the `commemorative_event` CPT
  has no public route and is absent from the sitemap*.

### 9.6 Caching / activation-boundary tests (added in r2; corrected in r3)

These test the §4.6 design directly:

- **Activation transition:** with a simulated full-page cache, a page rendered at
  `now = window_start − 1s` renders **without** the experience, and after the boundary
  purge renders **with** it at `now = window_start`.
- **Deactivation transition:** a page rendered inside the window renders **with** the
  experience, and after the boundary purge renders **without** it at `now = window_end + 1s`.
- **Boundary scheduling:** the next boundary (start **or** end) is **armed** on save, and
  the `shutdown` fail-safe runs a due purge when WP-Cron did not fire.
- **Recalculation triggers (added in r5; corrected in r6):** create / edit / schedule-change /
  enable / disable / publish / unpublish / trash each **re-arm** the next boundary from the
  **persisted** values — a test asserts the boundary is computed **after** the meta write, not
  before.
- **Permanent deletion & field clearing (added in r6):** a test asserts the next boundary is
  recalculated from the **final persisted state** — on **permanent deletion** (`deleted_post`)
  and on **clearing a scheduling field** (`deleted_post_meta`) — and that the recalculation
  never reads a record or metadata that is about to be removed.
- **Concurrency lock (added in r5):** two simulated concurrent due-boundary checks produce
  **one** purge, not two; the lock expires so a crashed request cannot wedge it.
- **Re-arm chain (added in r3):** after the activation purge runs, the **next**
  (deactivation) boundary is scheduled — e.g. 26 Dec purge → 2 Jan armed.
- **Targeted only (added in r3):** the purge deletes **only** the commemorative keys and
  fires the host hook; it does **not** call `wp_cache_flush()` or
  `delete_expired_transients()` — asserted by proving an **unrelated** transient/object-cache
  entry survives a purge.
- **Idempotence:** running the purge twice changes nothing and does not error.
- **Language scoping:** the purge clears **every** language's commemorative transients.
- **Host integration:** the `conexao_commemorative_purge_caches` action fires once per purge.
- **Admin action:** the "Purge commemorative caches" action requires the capability and a
  valid nonce (and is refused without either).
- **Guarantee honesty (added in r3):** with no purge mechanism available (Option B), the
  system documents and reports the **bounded delay** and does **not** claim exact boundary
  activation.
- **Execution guarantee (added in r4):** the test suite asserts the **per-request
  due-boundary check** fires the purge on the first PHP request after a boundary even when
  the scheduled event did not run (simulating "WP-Cron never fired because the site was
  fully cached"), and that the system reports Option B's bounded delay rather than claiming
  exact activation in that condition.

### 9.7 Polylang tests (added in r2)

- The `event_key` is **identical** on the PT and EN records.
- **`_ce_event_key` must exist on both records and be identical** — it is the identity, so
  the gate **explicitly allows** it on the EN record (corrected in r6; the previous wording
  would have rejected the key itself).
- The EN record must contain **no other** shared scheduling/presentation `_ce_*` metadata.
- The **permanent gate** therefore asserts the **exact split**: it **allows `_ce_event_key`**
  (and requires it to equal the PT value) and **rejects every other** duplicated shared
  configuration key.
- A save on the **EN** record cannot write a shared field.
- The **same event renders once** in each language, with translated content and a
  shared schedule (no double render on a language switch).
- **Fallback:** an event with no EN translation renders the **PT** content (canonical →
  PT fallback, noticed), and never renders twice.

### 9.8 REST exposure tests (added in r2)

- Unauthenticated `GET /wp-json/wp/v2/commemorative_event` returns **no** records.
- Unauthenticated access to a single record returns **no** record / `401`/`403`.
- `_ce_*` meta is **not** present in any REST response by default.
- A user with the CPT capability **can** read what the editor needs.
- Covered by an in-process capability test **and** an HTTP acceptance row.

### 9.9 Halloween timing regression — reassessment, not test-editing (corrected in r2)

`test-seasonal-halloween-nav-effects.php` fails 4 assertions that encode the
**approved** cadence (20–30 s initial, 25–35 s subsequent). Per §1.5 W13 and §12.1, the
required action is:

1. **Compare both** the tests **and** the JavaScript against the approved requirement
   (`2026-10-27-halloween-mobile-experience-plan.md` §1/§5).
2. **Restore the implementation** to the approved cadence so the tests pass — **do not**
   edit the tests to match the regressed code.
3. If a cadence change is genuinely wanted, it is a **new, explicitly approved change**
   with its own plan/report and an updated single source of truth for the values (the
   tests read the values from that source, so tests and code can never drift again).

### 9.10 What this audit did **not** verify (see §13)

Browser/visual verification, full-suite green, and lint were **not** run for this
audit; the scheduling and front-end cases above are the **plan**, not results. No
claim of browser or full-suite success is made anywhere in this report.

---

## 10. Implementation Roadmap

Simplified to **seven stages (0–6)** in r2 (the reviewer's sequencing). Each stage is
small, independently reviewable, and **behaviour-preserving for Halloween** until the
presentation framework lands (Stage 4). Dependencies are explicit; a stage must not
start before its dependencies are green. **Public activation overrides and simultaneous
presentations are explicitly out of scope for the initial release.**

### Stage 0 — Architecture decisions & event data model (no behaviour change)

- **Objective:** lock every decision this report leaves open and freeze the interfaces.
- **Scope:** decide plugin-vs-data-model placement (§2.3); freeze the public API
  (`conexao_commemorative_active()`, the `conexao_commemorative_presentations` filter),
  the field keys (§3.2) including `_ce_event_key`, the shared-vs-translated split (§3.6),
  the enabled/status rule (§3.3), the conflict rule (§6.1) and the **presentation scopes**
  (§7.4).
- **Files:** none (recorded as a signed-off `docs/templates/plan.md`).
- **Depends on:** this report (r2).
- **Acceptance:** a signed-off plan naming every Stage-1+ file and the one intentional
  behaviour change (New Year).
- **Tests:** none. **Risk:** none.

### Stage 1 — Scheduling engine refactor (incl. New Year + year-boundary)

- **Objective:** a deterministic, clock-injected engine with year-boundary support,
  priority and an explicit `window` rule; the old API kept as shims.
- **Scope:** window resolver (4 rule types, inclusive boundaries, year wrap); injected
  clock; `Europe/Dublin` fallback fix; priority + reason; **restore the approved Halloween
  cadence** (W13). No admin, no CPT yet.
- **Files:** the engine class (per Stage 0); `functions.php` (load change); the occasion
  test suite (rewritten, deterministic); `assets/js/main.js` (cadence restore).
- **Depends on:** Stage 0.
- **Acceptance:** every §9.2 row passes with injected dates; Halloween Oct 16–31 and
  Christmas Dec 10–25 unchanged; New Year is the **seven-day** `12-26 → 01-01` window;
  the Halloween nav-effects suite passes **without editing its assertions**.
- **Tests:** §9.2 in full; §9.9 (timing reassessment).
- **Risk:** the New Year correction is an intentional behaviour change — state it in the
  plan and release notes.

### Stage 2 — Event records, administration and migration of existing occasions

- **Objective:** events become data; administrators manage them in wp-admin.
- **Scope:** register `commemorative_event` + meta (declarative, sanitised) in
  `conexao-data-model`; the `conexao-admin-ux` config entry (list + editor + diagnostics
  incl. the **date-simulation inspector**); the **idempotent, dry-run-first** one-time
  migration of the six existing occasions (no runtime fallback — §8.2); Polylang PT/EN
  records with read-only shared fields; REST exposure locked down (§3.7).
- **Files:** `conexao-data-model/*`, `conexao-admin-ux/*`, the migration, docs, tests.
- **Depends on:** Stage 1.
- **Acceptance:** an admin can create/edit/enable/disable an event with no code change;
  the six events exist as records and render identically to Stage 1; a second migration
  run reports `created = 0`; a deleted record does **not** reappear; unauthenticated REST
  exposes nothing.
- **Tests:** §9.3, §9.7 (Polylang), §9.8 (REST), the seed-idempotence test, and §9.2 run
  against CPT-backed events.
- **Risk:** Polylang drift — mitigated by the read-only shared fields + the drift gate.

### Stage 3 — Secure administrator preview + cache handling

- **Objective:** preview any event on the real site without changing anything, and make
  the activation boundary cache-safe.
- **Scope:** the signed `event_key` preview link with the **full conjunctive check** —
  `is_user_logged_in()` **AND** `current_user_can( 'manage_options' )` **AND**
  `current_user_can( 'edit_post', $post_id )` **AND** a valid nonce (covering **published,
  disabled, draft and out-of-window** events); the PREVIEW banner; **explicit
  cache bypass** (§5.4); the **boundary cache invalidation** (scheduled purge + `shutdown`
  fail-safe + **targeted** invalidation + **re-arm of the next boundary** +
  `conexao_commemorative_purge_caches` + the admin "Purge commemorative caches" action) and
  the **Option A / Option B guarantee decision** (§4.6). **No public override.**
- **Files:** the engine (preview authorisation + boundary scheduling), the admin
  (Preview action + purge action), the presentation shell (banner).
- **Depends on:** Stage 2.
- **Acceptance:** an admin previews Christmas in July and New Year in October on the
  **local** site (PT and EN), including a **draft** and a **disabled** event; a logged-out
  visitor **cannot**; the stored schedule/status is unchanged; both **activation and
  deactivation** transitions are cache-correct under a simulated full-page cache; the next
  boundary is re-armed after a purge.
- **Tests:** §9.3 (preview), §9.6 (cache transitions, re-arm, targeted-only), and a
  security proof that a crafted `?conexao_preview=` request from an unauthenticated user
  does nothing.
- **Risk:** the host/CDN cache cannot be verified from this repo (§4.6 point 6) — recorded
  as a limitation; production verification is a **separately authorised activity**.

### Stage 4 — Presentation framework + migrate Halloween

- **Objective:** one registered presentation renders Halloween through the framework; no
  event identity in templates; assets load only when displayed.
- **Scope:** the shared framework (`commemorative.css`, `commemorative.js`); the theme's
  presentation registry (`conexao_commemorative_presentations`); the `scope` property;
  move the Halloween CSS/JS/art into `assets/{css,js}/presentations/halloween.*` and
  `assets/images/presentations/halloween/`; split `halloween-hero.php` into the shell +
  `template-parts/presentations/halloween.php`; replace the `'halloween'` checks in
  `front-page.php` and `header.php` with the dispatcher; register the `halloween`
  presentation (`scope: homepage`). **The visual design may be improved here** (§7.1).
- **Files:** `functions.php`, `inc/commemorative-presentation.php`, `front-page.php`,
  `header.php`, `inc/assets.php`, the CSS/JS/art moves, the presentation template.
- **Depends on:** Stage 3.
- **Acceptance:** the Halloween interactions, accessibility, reduced-motion and no-JS
  behaviour are preserved (functional, **not** pixel-identical); with **no** event active,
  **no** commemorative CSS/JS is enqueued and the global bundle is smaller.
- **Tests:** §9.4 (no-active-event negative proof, one-active-event, PT/EN, no-JS,
  reduced-motion); the Halloween suites.
- **Risk:** the asset split could change cascade order — mitigated by explicit enqueue
  dependencies and a visual check during implementation (not claimed here).

### Stage 5 — Second presentation (proves reusability)

- **Objective:** prove a **new** commemorative experience needs **no engine change**.
- **Scope:** implement one additional presentation (Christmas or New Year) as a registered
  presentation + an event record, choosing an appropriate `scope`; configure New Year with
  its **seven-day** `12-26 → 01-01` window.
- **Files:** `template-parts/presentations/<slug>.php`,
  `assets/{css,js}/presentations/<slug>.*`, one event record, its tests.
- **Depends on:** Stage 4.
- **Acceptance:** the new event appears on schedule and via preview with **zero** edits to
  the engine or any other presentation's files; the New Year Dec 25/26/31/Jan 1/2 table
  holds.
- **Tests:** §9.2/§9.4 for the new event.
- **Risk:** none architectural; this stage is the design's proof.

### Stage 6 — Testing, accessibility review and documentation

- **Objective:** close the work with real verification and a maintainable record.
- **Scope:** the full §9 test matrix (incl. §9.6–§9.9); an accessibility review (keyboard,
  focus, reduced-motion, contrast) of the migrated + new presentations; the architecture
  doc + the "how to add a commemorative event" runbook; the new permanent gates; the
  production cache verification step (§4.6 point 6) — **separately authorised; no
  production action in this stage**.
- **Files:** `docs/` (new `docs/commemorative-events.md` + index entries),
  `tests/scripts/verify-*` or in-process gates.
- **Depends on:** Stage 5.
- **Acceptance:** `./scripts/run-tests.sh` green; a new developer can add an event from the
  runbook alone; the gates fail on the invariants they guard.
- **Tests:** the new gates, each with an injected negative proof; the accessibility review
  recorded with real evidence.
- **Risk:** documentation drift — mitigated by the existing documentation-drift gate.

---

## 11. Final Recommendation

### 11.1 Direct answers to the six required questions

| Question | Answer |
|---|---|
| **Retain or redesign the occasion engine?** | **Retain and refactor — not a rewrite.** The request-time model, the timezone handling and the Easter computation are correct and worth keeping. The **year-boundary bug (W2)**, the **absence of a configuration surface (W1/W11)** and the **shipped Halloween cadence regression (W13)** must be fixed; the coupling of event identity into presentation (W7) must be broken. |
| **CPT, settings screen, or another approach?** | **A dedicated `commemorative_event` CPT** (registered in `conexao-data-model`, edited through `conexao-admin-ux`) with a language-neutral **`_ce_event_key`**, a defined **shared-vs-translated** Polylang model (§3.6), and a **theme-owned presentation registry** (§7.3). Content and configuration are data; a presentation is code. |
| **Best way to preview in wp-admin?** | A **signed, capability-gated, request-scoped preview link** (`?conexao_preview=<event_key>&_wpnonce=…`) honoured only when **all** hold: `is_user_logged_in()` **AND** `current_user_can( 'manage_options' )` **AND** `current_user_can( 'edit_post', $post_id )` **AND** a valid nonce (§5.4). It **actively bypasses page caches** and shows a PREVIEW banner — distinct from a read-only **date-simulation inspector** (§5.6). Private, changes nothing, works on the real site in both languages. |
| **Support multiple active events?** | **In v1: no simultaneous presentations.** The engine selects the **single highest-priority** eligible event and **explains why the others were not selected** in the admin. Ties break on **`_ce_event_key`** ascending (language-neutral). Concurrency/secondary slots are **deferred** (§6). |
| **How are presentation templates registered?** | **By the theme**, via the plugin-provided `conexao_commemorative_presentations` filter (template + scope + styles + scripts + defaults). The plugin only sees presentation **keys**, never template/asset paths — so it stays theme-independent (§2, §7.3). Each presentation's assets are enqueued **only when it is displayed**. |
| **Simplest architecture that meets the requirements without overengineering?** | **CPT + one theme-independent engine + one admin config entry + a theme-owned presentation registry + a dispatcher, plus boundary cache invalidation.** No new table, no custom framework, no external dependency, no public override and no concurrency in v1. Each piece is forced by a hard requirement; the cache layer is forced by the fact that request-time PHP cannot see cached HTML (§4.6). |

### 11.2 Key decisions and trade-offs

- **New plugin vs existing plugin (the one open decision).** Recommended: a new
  `conexao-commemorative` platform plugin (matches the `conexao-event-runtime`
  precedent). Trade-off: one more registry/build/activation entry. **Cheaper
  alternative:** the same engine as a class inside `conexao-data-model`. **Stage 0
  decides.**
- **CPT vs options.** CPT costs a few meta keys but buys revisions, capabilities,
  list-table UX and Polylang translation for content. Chosen.
- **Presentation scope (corrected in r2).** Scope is a registered property
  (`homepage` / `site_wide` / `page` / `combined`), so the architecture supports a
  dedicated page and combinations **without committing to them now**. `homepage` and
  `site_wide` ship first (no routing change); `page`/`combined` are opt-in and carry the
  routing/canonical/hreflang work if an event needs them (§7.4).
- **Preview as a URL token vs a separate preview page.** URL token chosen: it previews
  the *real* page (header, footer, nav, both languages, all viewports) with no persisted
  change — but it must **actively bypass caches** and **fail closed** if it cannot (§5.4).
- **One primary presentation (v1).** Keeps the site calm and the engine simple; the
  secondary slot and `allow_concurrent` are **deferred** until there is a real need (§6).
- **Cron for cache invalidation only (added in r2, refined in r3).** Request-time
  eligibility stays the authority; a scheduled **targeted** boundary purge (which
  **re-arms the next boundary**) exists solely so a cached page cannot outlive the
  boundary. No client-side revelation is used (§4.6).

### 11.3 The intentional behaviour changes

Exactly **two** intentional, documented behaviour changes, and nothing else:

1. **New Year** — currently broken (W2): the window becomes the required **seven
   calendar days, `12-26 → 01-01`**. This is the redesign's whole point.
2. **Halloween mobile cadence** — restored from the regressed `5–10 s / 10–15 s` (commit
   `59c2db9`) to the **approved** `20–30 s / 25–35 s` (`2026-10-27-halloween-mobile-
   experience-plan.md` §1/§5). This is a **bug fix to a shipped regression**, not a
   redesign, and it is what makes the existing tests pass **without editing them** (§9.9).

Everything else in the migration is behaviour-preserving (functionally — see §7.1 for the
Halloween *visual* latitude). Both changes must be stated in the plan and release notes.

---

## 12. Verification Commands and Results (read-only)

All commands below were executed in this session and made **no** writes to the
working tree, the database, production, or the Flutter/mobile repository. The
`docker compose` stack was already running.

> **Concurrent working-tree changes (not mine).** This repository was being edited
> by an unrelated process/session during this audit on the `leisure-location` branch.
> `docs/content-model.md`, `docs/themes/conexao-br-irlanda.md` and the new
> `wp-content/plugins/conexao-admin-ux/tests/test-leisure-coordinates.php` were
> created/modified by that work (Lazer coordinates / proximity ordering) **after**
> this session's first `git status`. They are **not** part of this audit and were not
> touched or reverted here. The only files this audit wrote are this report and its
> two index entries (`docs/README.md`, `docs/reports/README.md`).

| # | Command | Exit | Real result |
|---|---|---|---|
| 1 | `git status --porcelain && git branch --show-current && git log --oneline -5` | 0 | branch `leisure-location`; HEAD `92e42aa`; working tree dirty with **pre-existing** Lazer/location changes (not from this task) |
| 2 | `docker compose exec -T wordpress php -r 'echo date(...)'` | 0 | container clock `2026-11-03 08:24:49 UTC` |
| 3 | `docker compose exec -T wordpress php …/tests/test-seasonal-occasion.php` | 0 | **`seasonal-occasion: 14 passed, 0 failed`** |
| 4 | `docker compose exec -T wordpress php …/tests/test-seasonal-halloween-presentation.php` | 0 | **`seasonal-halloween-presentation: 26 passed, 0 failed`** |
| 5 | `docker compose exec -T wordpress php …/tests/test-seasonal-halloween-nav-effects.php` | 1 | **`seasonal-halloween-nav-effects: 102 passed, 5 failed`** (see §12.1) |
| 6 | `grep -n 'FAIL'` on command 5's output | 0 | the 5 failing assertions (see §12.1) |
| 7 | read-only PHP probe: construct New Year + Halloween, print `resolve_*` and `is_active()` | 0 | `wp_timezone(): Europe/Dublin`; New Year `occurrence 2026-01-01, start 2025-12-17, end 2026-01-01, is_active false`; Halloween `start 2026-10-16, end 2026-10-31, is_active false, primary null, variant NULL` |
| 8 | `python3 tests/scripts/verify-documentation-drift.py` | 0 | **`14 passed, 0 failed`** — `GATE-RESULT {"gate": "documentation_drift", "status": "pass", …}`; registry drift `exit=0` (10 plugins, 19 generated regions), script conventions `744 passed`, agent governance `357 passed` |
| 9 | `git --no-pager show HEAD:…/assets/js/main.js \| grep -n 'INITIAL_DELAY…'` | 0 | HEAD already contains `INITIAL_DELAY_MIN_MS = 5000` … so the failures are **pre-existing at HEAD** (not caused by the uncommitted working tree) |
| 10 | `grep` the **approved** cadence in `docs/reports/2026-10-27-halloween-mobile-experience-plan.md` | 0 | plan §1 and §5 specify **20,000–30,000 ms** initial and **25,000–35,000 ms** subsequent ⇒ the approved spec is 20–30 s / 25–35 s |
| 11 | `grep` the cadence in `…2026-10-27-halloween-mobile-experience-report.md` | 0 | the report states `INITIAL_DELAY_MIN_MS: 20000 … INTERVAL_MAX_MS: 35000` — matching the plan |
| 12 | `git --no-pager log -p --follow -- assets/js/main.js` (constant lines) | 0 | commit `59c2db9` **introduced** `5000/10000/10000/15000` — the shipped value contradicts the approved plan/report, so **the code regressed and the tests are correct** (W13, corrected) |
| 13 | `grep -rniE 'cache\|purge\|DONOTCACHEPAGE' docs/deployment.md inc/cache.php inc/performance.php` | 0 | the repo has **language-scoped transients** (300 s TTL) and **no** in-repo full-page cache or `DONOTCACHEPAGE` usage; the production (WordPress.com) cache is outside the repo ⇒ the §4.6 boundary design must be verified in production |

### 12.1 The five failures in `test-seasonal-halloween-nav-effects.php` (real output)

```
FAIL: Halloween occasion reports itself active                              (date-dependent)
FAIL: The first surprise waits at least twenty seconds  — 'var INITIAL_DELAY_MIN_MS = 20000;' not found
FAIL: The first surprise waits no longer than thirty seconds — 'var INITIAL_DELAY_MAX_MS = 30000;' not found
FAIL: Subsequent surprises wait at least twenty-five seconds — 'var INTERVAL_MIN_MS = 25000;' not found
FAIL: Subsequent surprises wait no longer than thirty-five seconds — 'var INTERVAL_MAX_MS = 35000;' not found
```

Interpretation (corrected in r2): **1** failure is because the suite runs outside the
Halloween window (W6, the non-injectable clock). The other **4 are not stale tests** —
they encode the **approved** mobile cadence (20–30 s initial, 25–35 s subsequent,
`2026-10-27-halloween-mobile-experience-plan.md` §1/§5) while the shipped commit
`59c2db9` uses 5–10 s / 10–15 s. The tests are therefore **correct** and the
**JavaScript is the regression**. The fix is to **restore the implementation to the
approved cadence (or explicitly re-approve and document a new cadence)** — **not** to
edit the tests to match the code. Both failures are resolved by the redesign
(deterministic clock + tests that read the cadence from one source).

### 12.2 Aggregate suite

A bounded `./scripts/run-tests.sh` run was started in the background
(`/tmp/conexao-full-tests.log`) to record a headline number. **It did not finish
within the session's bounded window**, so **no aggregate pass/fail figure is claimed
here.** Individual plugin suites that completed in the log all reported `0 failed`
(e.g. `test-county-registry.php — 482 passed, 0 failed`). The aggregate result must be
re-run and recorded by the implementing stage.

### 12.3 Analytical findings (no command) — recorded for honesty

Two r3 conclusions come from **inspection and reasoning**, not from an executed command,
and are labelled as such:

1. **The r2 caching contradiction.** §7.5 enqueues a presentation's CSS/JS **only when it
   is displayed**; therefore a page cached **before** activation contains neither the
   markup nor the JS, so the r2 "client-side reveal at the boundary" fallback **cannot
   work**. This is a logical consequence of two documented requirements, verified by
   reading §4.6 and §7.5 together — no command proves it.
2. **`one_off` must not search adjacent years.** The r2 wording said "the three occurrence
   rules"; `one_off` is, by definition, a single stored date. Corrected by definition, not
   by a test run (the test is listed in §9.2 and will be written in Stage 1).

---

## 13. Limitations

- **No browser/visual verification was performed.** No screenshot, viewport
  measurement, contrast measurement, keyboard walk-through or reduced-motion
  rendering was produced in this session. Every accessibility, responsive and
  light/dark statement about the *current* Halloween experience is a **code
  inspection** of what exists (and is already asserted by the green presentation
  suite); no new visual claim is made.
- **No full-suite / lint run completed.** `./scripts/run-tests.sh` did not finish in
  the session bound (§12.2); `./scripts/lint.sh` (PHPCS/PHPStan) was **not** run. The
  repository therefore has **no recorded aggregate green** for this session.
- **The repository has red tests.** `test-seasonal-halloween-nav-effects.php` fails 5
  assertions today (§12.1): 1 is date-dependent (W6) and **4 are a genuine shipped
  regression** that the tests correctly catch (W13) — not stale tests. This is **not**
  caused by this read-only audit.
- **Production caching cannot be verified from this repository.** The §4.6
  boundary-cache design depends on host/CDN behaviour (WordPress.com) that is outside the
  repo. The design therefore offers **Option A (confirmed purge → exact activation)** or
  **Option B (bounded delay within a verified TTL)**, and any production verification is a
  **separately authorised activity**. It remains **unverified** here.
- **No production, database or Flutter/mobile access was used or needed.**
- **The plugin-vs-data-model placement (§2.3) is a decision, not a verified outcome** —
  it is deliberately deferred to Stage 0.
- **The roadmap's test/acceptance numbers are a plan**, not results; no stage was
  implemented.

## 14. Evidence Paths

This is an audit report; the machine-readable evidence is the command output recorded
in §12. No `docs/evidence/<date>-<stage>/` directory was created because no build,
matrix or `gate.json` was produced. The executed read-only commands are the evidence,
and the two failing-suite transcripts are reproduced verbatim in §12.1. A raw log of
the bounded aggregate run is at `/tmp/conexao-full-tests.log` (git-ignored, not part of
the record).

## 15. Documentation Updated

| Document | Update |
|---|---|
| `docs/reports/2026-11-03-commemorative-events-architecture-audit.md` | **this report** — r1 (initial audit) through **r5 (final clarifications)**; the revision history at the top lists every change |
| `docs/reports/README.md` | index entry under a new "Commemorative events" heading |
| `docs/README.md` | index entry in the reports list |

No canonical/evergreen document (`architecture.md`, `content-model.md`, `routing.md`,
`frontend.md`, theme docs) was changed, because nothing was implemented — the proposal
does not yet alter any fact those documents describe. They will be updated by the
implementing stages (Stage 2 → content-model; Stage 4 → frontend + theme; Stage 6 → a
new `docs/commemorative-events.md`).

| What moved | From (document) | To (skill) |
|---|---|---|
| (nothing — no procedure moved) | — | — |

## 16. Rollback / Recovery

This task wrote **two documentation files only** (this report + its two index
entries). Rollback is deleting this report and reverting the two index edits. No
code, database, content, Polylang, setting, production or deploy state was touched, so
no data restore is required.

## 17. Final Status

**Final status: PASS WITH LIMITATION — revised per review (r2–r7)**

The reviewer returned **r1** as *PASS WITH REQUIRED REVISIONS*, **r2** as *PASS WITH A FEW
REQUIRED CORRECTIONS*, **r3** as *PASS WITH A FEW FINAL CORRECTIONS*, **r4** as *PASS WITH
MINOR REQUIRED CLARIFICATIONS*, **r5** as *PASS WITH THREE REQUIRED CORRECTIONS* and **r6**
as *PASS WITH ONE REQUIRED CLARIFICATION*. Each round is addressed in turn; **r5** closed the
r4 clarifications, **r6** closed the r5 corrections, and **r7** closes the r6 clarification
(see the revision history at the top):

1. **Polylang `_ce_event_key` exception (§3.6, §3.2).** The EN record **must** carry
   `_ce_event_key` (identical to PT) — it is the identity — and **must not** carry any
   *other* shared `_ce_*` meta; the Polylang meta-copy exclusion is defined **per key group**.
2. **Preview identifier (§5.1).** `?conexao_preview=<event_key>` everywhere — never a
   translated slug.
3. **Deferred fields kept out of v1 (§3.2, §3.4, §3.5, §5.3).** `_ce_assets` is removed from
   the v1 field set and editor (presentations use theme-managed assets); `_ce_force_until` is
   a **future design note**, not a v1 validation requirement.
4. **`_ce_description` has one purpose (§3.2, §3.6).** Internal, administrator-only,
   **language-neutral and not translated**.
5. **Cache-scheduler operational precision (§4.6, §9.6).** It recalculates the next boundary
   on **every** relevant change **after** the change is persisted; an **atomic concurrency
   lock** prevents duplicate purges; and Option B's bound uses the **effective maximum** cache
   lifetime across **all** layers (origin, page cache, CDN/edge, stale-serving).
6. **Polylang gate wording (§9.7).** The gate now **explicitly allows `_ce_event_key`**
   (required identical on PT and EN) and **rejects every other** duplicated shared `_ce_*` key.
7. **Deletion hook corrected (§4.6, §9.6).** Permanent deletion uses **`deleted_post`** (fires
   *after* removal), **not** `before_delete_post`; **`deleted_post_meta`** covers clearing a
   scheduling field; trash/unpublish stays separate via `transition_post_status`; tests cover
   permanent deletion and field clearing from the **final persisted state**.
8. **Preview security summary restored (§11.1, Stage 3).** The executive summary and the
   Stage 3 scope carry the **full conjunctive check** — `is_user_logged_in()` **AND**
   `manage_options` **AND** `edit_post` **AND** nonce — matching §5.4.
9. **§5.4 internal consistency (§5.4, §9.3).** The **first** authorisation statement in §5.4
   now includes `current_user_can( 'edit_post', $post_id )`, so **both** statements in the
   section agree; §9.3 adds an explicit negative test that a user with `manage_options` but
   **without** `edit_post` for the specific event cannot preview it.

The architecture itself is unchanged from r4: **one platform plugin** (scheduling + event
state, theme-independent) + **`conexao-data-model`** (the event data structure) +
**`conexao-admin-ux`** (the administration interface) + a **theme-owned presentation registry**
and rendering + **Polylang** linked translations + **cache invalidation separate from event
eligibility**.

The remaining limitation is unchanged and honest: this read-only task did not run the full
aggregate suite, the linter, or any browser/visual verification, and production cache
behaviour cannot be verified from this repository (hence the Option A / Option B decision and
the separately authorised production verification).

_Last verified: 2026-11-03 by the Commemorative Events System architecture audit (r7, read-only)_
