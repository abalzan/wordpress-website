# Conexão BR Irlanda — Commemorative Events

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | platform |
| **Production** | yes |
| **Build** | yes |
| **Compose mount** | yes |
| **Dependencies** | `conexao-data-model` |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-commemorative/conexao-commemorative.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Production platform plugin.** Part of the production steady state.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

The theme-independent scheduling and activation engine for commemorative
experiences (Halloween, Christmas, New Year, …). It owns **when** an event is
displayed and **which** event wins; it never renders anything and never knows a
template, an asset or a holiday name.

Authoritative specification:
[`docs/reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](../reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md)
(r10). This page records what exists; the proposal owns the requirements.

## Responsibilities

| Owns | Never does |
|---|---|
| window resolution (four rule types, inclusive boundaries, 31 December wrap), the injected clock and the occurrence-year identity (§4) | render markup, templates, CSS or JS |
| eligibility (published → enabled → in window → known package), ranking (`_ce_year_precedence`, `_ce_priority`, `_ce_event_key`) and the reason for every non-display (§6) | compute or detect date collisions between events (§5.7) |
| signed, capability-gated administrator preview and its cache bypass (§5.4) | activate anything for the public |
| targeted cache invalidation, the armed activation boundary and its re-arm chain (§4.6) | flush a global object cache or sweep expired transients |
| the event record reader (canonical PT configuration + per-language content, §3.6) | own the field schema (that is `conexao-data-model`) |

## Public contract (theme-independent)

| Symbol | Purpose |
|---|---|
| `conexao_commemorative_active( $now = null )` | the selected event as a `Conexao_Commemorative_Event`, or `null` |
| `conexao_commemorative_is_active( $now = null )` | whether an experience is displayed for this request |
| `conexao_commemorative_resolution( $now = null )` | winner, superseded rows, occurrence year, preferred-flag conflicts |
| `conexao_commemorative_preview_event()` / `conexao_commemorative_is_preview()` | the authorised preview record / flag |
| `conexao_commemorative_next_boundary()` | the armed boundary (`timestamp`, `datetime`, `event_key`, `type`) |
| `conexao_commemorative_presentations()` | the registered experience packages |
| `conexao_commemorative_admin_overview( $post_id )` | computed window/eligibility/reason for one record |
| `conexao_commemorative_reason_label()` | the human-readable reason |
| filter `conexao_commemorative_presentations` | the theme-owned package registry (§7.4) |
| action `conexao_commemorative_purge_caches` | host/CDN purge hook, fired once per targeted purge |

The engine reads locale only through `conexao_current_locale()` when the theme
provides it, and falls back to `get_locale()` otherwise.

## Scheduling rules (§4)

| Rule | Resolves | Input |
|---|---|---|
| `fixed_annual` | an annual `MM-DD` occurrence minus the lead-in | `_ce_date`, `_ce_lead_in_days` |
| `variable_annual` | a computed occurrence (Gregorian Easter) minus the lead-in | `_ce_variable`, `_ce_lead_in_days` |
| `one_off` | exactly the stored date; never adjacent years | `_ce_one_off_date`, `_ce_lead_in_days` |
| `window` | an explicit `MM-DD → MM-DD` window, which may wrap across 31 December | `_ce_window_start`, `_ce_window_end` |

Windows are inclusive at `00:00:00` and `23:59:59` in `wp_timezone()`. The clock
is injected in every public method, so no scheduling test depends on the real
date. `02-29` is rejected at save time because it only exists in leap years.

**Occurrence year.** The key for the manual preference is the occurrence year,
never `$now->format('Y')`: the occurrence's calendar year for the annual rules,
the stored date's year for `one_off`, the window year for a non-wrapping
`window`, and the **year in which the window ends** for a wrapping window. New
Year `12-26 → 01-01` is therefore occurrence year 2027 for the whole window
2026-12-26 → 2027-01-01.

## Resolution order (§6)

1. `post_status === 'publish'`;
2. `_ce_enabled === true`;
3. the resolved window contains the clock;
4. `_ce_presentation` names a registered package (when a registry is loaded);
5. `_ce_year_precedence[occurrence_year].preferred` ranks ahead of unmarked events;
6. `_ce_priority` descending;
7. `_ce_event_key` ascending.

Each non-selected eligible event is recorded as `superseded by "<event_key>"`
(with `manual preference` when that flag decided the ordering). The engine
selects **at most one** event package per request.

## Cache and the activation boundary (§4.6)

- One single event is armed at the earliest upcoming window start or end;
  arming never stacks.
- A per-request due check purges when the stored boundary has passed. This is
  the **honest Option B** guarantee: "at the first PHP request after the
  boundary, or within the verified cache TTL — whichever is sooner". Exact
  boundary activation (Option A) requires a confirmed independent scheduler
  **and** a confirmed host purge, neither of which can be verified from this
  repository.
- A purge is **targeted**: this plugin's own transients (in every language), its
  object-cache group, and the `conexao_commemorative_purge_caches` action. It
  never calls `wp_cache_flush()` or `delete_expired_transients()`.
- Every purge re-arms the next boundary, so the chain is never left unarmed.
- A short-lived lock (object-cache `add()` when a persistent cache exists,
  otherwise an auto-expiring option) guarantees at most one purge per boundary.

| Change | Invalidates the cache | Re-arms the boundary |
|---|---|---|
| `_ce_priority` | yes | **no** |
| `_ce_year_precedence` | yes | **no** |
| `_ce_enabled` | yes | **yes** |
| any scheduling key (`_ce_rule`, `_ce_date`, `_ce_variable`, `_ce_one_off_date`, `_ce_window_start`, `_ce_window_end`, `_ce_lead_in_days`, cleared values) | yes | **yes** |
| `save_post_commemorative_event` (priority 999, after every other writer) | yes | yes |
| `transition_post_status` (publish / unpublish / trash) | yes | yes |
| `deleted_post` (permanent deletion, after removal) | yes | yes |

## Administrator preview (§5.4)

`?conexao_preview=<event_key>&_wpnonce=…&_ce_ts=…` is honoured only when **all**
hold: the user is logged in, has `manage_options`, has `edit_post` on the
canonical record, and presents a valid nonce for that `event_key`. Failures are
silent. An honoured preview defines `DONOTCACHEPAGE`, sends
`Cache-Control: no-store, private` plus `nocache_headers()`, and the theme prints
a non-dismissible `data-preview` banner. Nothing is persisted. The link works for
published, disabled, draft and out-of-window events and in both languages.

## Tests

`wp-content/plugins/conexao-commemorative/tests/test-*.php`, discovered by
convention and executed by `./scripts/run-tests.sh`:

| Suite | Proves |
|---|---|
| `test-commemorative-schedule.php` | the §9.2 scheduling rows with an injected clock, including New Year, the wrap, one-off and Easter |
| `test-commemorative-resolution.php` | eligibility, priority, `_ce_event_key` tie-breaks, manual-precedence scope and ties, reasons |
| `test-commemorative-cache-boundary.php` | targeted invalidation, re-arm rules, lifecycle hooks, re-arm chain, lock, idempotence, language scoping |
| `test-commemorative-admin.php` | the columns/overview for the next occurrence year, the precedence write/clear path, warnings |
| `test-commemorative-preview.php` | the conjunctive authorisation matrix and the no-persistence guarantee |
| `test-commemorative-migration.php` | idempotent dry-run-first seed, EN identity split, no resurrection of a deleted event |

## Stage scope

Stages 0–3 of the r10 roadmap are implemented: data model, scheduling engine,
event records + administration + seed migration, and secure preview + cache
handling.

**Stages 4–6 (2026-11-06) completed the presentation half without changing the
engine's scheduling rules.** The plugin still renders no markup and knows no
holiday; the theme owns the registry, the dispatcher, the placements and the assets
(see [`docs/commemorative-events.md`](../commemorative-events.md)). Two plugin
changes were required by real defects found while wiring the presentation up, and
both are minimal and covered by regression tests:

1. **`conexao_commemorative_active()` built the value object from the wrong array.**
   A live resolution returns the winner's *evaluation row* while an authorised
   preview returns the *normalised event itself*. Passing the raw array through
   meant (a) a live event emitted `Undefined array key "id"` warnings and silently
   lost every localised `_ce_heading` / `_ce_subheading` / `_ce_cta_*` value, and
   (b) a preview produced an EMPTY event object (`presentation === ''`), so no
   placement could ever render. The accessor now unwraps the row, resolves the
   content from the normalised event and rebuilds the evaluation row for the value
   object (including the occurrence year, for the dismissal key).
   *Proof:* `test-commemorative-presentation-dispatch.php` plus the authenticated
   preview rows of `tests/acceptance/verify-commemorative-http.py`.
2. **The CPT was readable through REST.** `register_post_type()` declared
   `show_in_rest => true`, so `GET /wp-json/wp/v2/commemorative_event` returned
   every record to an unauthenticated caller — a breach of r10 §3.7/§9.8. It is now
   `show_in_rest => false`, the route no longer exists (404), and the permanent gate
   `commemorative_cpt_privacy` asserts it. The wp-admin surface is unaffected.

The theme's legacy `inc/occasion.php` keeps working for non-presentation callers;
the commemorative presentation no longer reads it. Its date methods gained an
optional injected clock (`is_active( $now = null )`) so the Halloween regression
suite is date-independent, as r10 §9.1 requires.

