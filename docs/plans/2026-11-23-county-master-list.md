# Plan — County master list: one 26-county source of truth, three protection layers

| | |
|---|---|
| **Task title** | Permanently fix and lock the County filter to the 26 Republic of Ireland counties |
| **Date** | 2026-11-23 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `592a96f05d6a295eb88afeacf65ea8dde437d1d1` |
| **Applicable skill(s)** | `wp-add-content-type` (taxonomy ownership), `wp-content-change` (data repair), `wp-security-review`, `wp-update-docs` |
| **Plan status** | approved (implementation in progress) |

## 1. Scope

Replace the county taxonomy-as-source-of-truth with one fixed, code-owned list of
26 counties (`Conexao_Data_Model_Counties`), enforced at three independent layers:

1. **Master list** — one alphabetical slug ⇄ name map in `conexao-data-model`
   (the schema owner). Nothing is derived from terms, imports or user input.
2. **Write validation** — `pre_insert_term` / `wp_insert_term_data` /
   `wp_update_term_data` guards on `conexao_county`, county meta write guards and
   admin-editor validation, so no entry point (admin, REST, importer, transfer
   import, code) can create, rename or assign a 27th county.
3. **Public filter** — `/eventos/` (and `/lazer/`) county dropdowns render the
   master list, keyed by canonical slug, so the filter shows exactly 26 unique
   counties even when invalid terms exist in the database.

Plus a wp-admin repair screen (production has no WP-CLI) that inventories
out-of-list county terms, shows the records attached to them, and lets an
operator re-assign them to a canonical county or leave them unassigned before
the invalid term is removed.

## 2. Explicit non-goals

- No WordPress core or third-party plugin file is modified.
- No change to the event CPT, the town taxonomy, the category/tag filters, the
  filter markup/CSS/JS, or the event URLs.
- No new plugin, route, REST endpoint, meta key or CPT.
- No production write, deploy or activation from this task.
- No change to Polylang policy: `conexao_county` stays **shared**
  (language-neutral, one term for both languages).
- Northern Ireland counties are not added and are actively rejected.

## 3. Relevant authoritative documents

- `AGENTS.md` (scope, Polylang, content-write rules).
- `docs/engineering-standard.md` §5.1/§5.2 (content writes), §2.1/§4.2/§7
  (security), §9 (docs).
- `docs/content-model.md` (taxonomy ownership, shared county/town policy).
- `docs/plugins/conexao-data-model.md` (schema owner).
- `docs/plugins/conexao-admin-ux.md` (production-operable admin capability).
- `docs/plugins/conexao-event-importer.md` (import county derivation).

## 4. Current-state findings

- **Production filter** (`https://conexaobr.ie/eventos/`, fetched 2026-11-23)
  renders **38** county options: the 26 real counties plus **12 numeric
  options** — `6724, 6726, 6727, 6729, 6730, 6731, 6768, 6771, 7353, 7354,
  7751, 7752`. Each numeric option links to `?county=<number>` and selects a term
  whose **name is also the number**; every one returns 0 events.
- **Local stack** reproduces the same class of defect differently: the dropdown
  renders duplicate counties because leftover `ev-*` fixture/import terms
  (`ev-carlow`, `ev-clare`, `ev-cork`, `ev-galway`, `ev-laois`, `ev-wicklow`)
  sit beside the canonical terms with the same display name.
- **Root cause (code, verified):** `template-parts/event-filters.php` builds the
  county list from `conexao_get_terms_for_post_type( 'conexao_county', 'event' )`
  — i.e. from *whatever terms exist* — and every write path that creates county
  terms did so unvalidated: `Conexao_Event_Location::ensure_county()` inserts any
  string, `conexao-admin-ux` inserts a county from submitted meta with no
  whitelist, the event-transfer importer creates terms from exported names, and
  the taxonomy is registered publicly so wp-admin/REST term creation is open.
  A county value that arrives as an **identifier** (numeric) is therefore turned
  into a term whose name *is* the number — the exact production signature.
- **Root cause (data, verified):** `docs/evidence/2026-09-30-en-translation-dry-run/01-production-revalidation.json`
  records the production `conexao_county` inventory on 2026-09-30 as **exactly
  26 terms** (ids 1372–1450, all correct, `lang: null`). The numeric terms
  therefore postdate that snapshot — they are a regression of the recent
  multi-county import work, not legacy content.
- **Second hole:** `Conexao_Event_Location::$known_counties` contains six
  Northern Ireland counties (Antrim, Armagh, Derry, Down, Fermanagh, Tyrone), so
  a source value of "Antrim" created a 27th county.

| Path | File | Today |
|---|---|---|
| Importer county assignment | `conexao-event-importer/includes/class-event-location.php` | `wp_insert_term()` for any string |
| Importer transfer import | `conexao-event-importer/includes/class-event-import.php` | creates terms from exported names |
| Importer source config | `conexao-event-importer/includes/class-event-sources.php` | free-text county field |
| Event/leisure/guide editor | `conexao-admin-ux/includes/class-fields.php` | `wp_insert_term()` fallback, no whitelist |
## 5. Proposed approach

**Chosen:** a `Conexao_Data_Model_Counties` class that owns the only county list
and the only county guard callbacks; every other component calls it.

- **Layer 1.** `const COUNTIES` — 26 entries, alphabetical by name, slug ⇄ Name.
  `normalize()` accepts a slug, a name, `Co. X`, `County X`, case/accents and a
  trailing `, Ireland`; it **never** accepts an integer, an unknown name, or a
  Northern Ireland county. Term id and title are resolved *from* the list, never
  the other way round.
- **Layer 2.** `pre_insert_term` rejects any out-of-list `conexao_county` value
  (returns `WP_Error`); `wp_insert_term_data` forces the canonical name+slug;
  `wp_update_term_data` refuses to rename a county to anything off the list.
  `update_post_metadata`/`add_post_metadata` reject invalid values for
  `_event_county`, `_leisure_county`, `_guide_county`. The admin editor fields
  render only the master list and validate server-side. The importer resolves
  through `normalize()` + `ensure_term()` (returns `0` and logs for invalid).
  The county registry derives its names from the master list.
- **Layer 3.** A theme helper `conexao_get_county_filter_terms()` returns exactly
  one entry per master county (canonical slug + canonical name), resolving the
  live `WP_Term` when it exists and a synthetic `WP_Term` when it does not; both
  filter templates consume it. Dropdown design, search, "Todas", URL parameters
  and query behaviour are unchanged.
- **Repair.** `Tools → Counties` in `conexao-admin-ux`: inventory (counts),
  per-record reassignment plan (the dry-run/preview), nonce + `manage_categories`,
  idempotent apply (re-assign, then delete the invalid term), on-screen result
  and a stored last-run audit record.

**Rejected options:** (a) CSS/JS hiding of numeric options — explicitly excluded
by the request and does not fix the data; (b) `get_terms()` with a hardcoded
name filter in the template — still trusts the database and duplicates the list;
(c) building the list from `Conexao_County_Registry` (importer, local-only
tooling) — the master list must live in the production schema owner and survive
without the importer; (d) MySQL-side term deletion — production has no DB access
and the records must be re-assigned first.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-data-model/includes/class-counties.php` | **new** | Layer 1 + Layer 2 guards |
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | require, `init` wiring, seed delegation, version 1.8.0 | single owner |
| `wp-content/plugins/conexao-data-model/includes/class-relationships.php` | seed through the master list | one source |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | `counties()` delegates | one source |
| `wp-content/plugins/conexao-admin-ux/includes/class-fields.php` | master-list select, canonical save, validation | Layer 2 |
| `wp-content/plugins/conexao-admin-ux/includes/class-counties-admin.php` | **new** repair screen | production data cleanup |
| `wp-content/plugins/conexao-admin-ux/conexao-admin-ux.php` | require + version 1.1.0 | wiring |
| `wp-content/themes/conexao-br-irlanda/inc/queries.php` | county filter helper (master list) | Layer 3 |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php` | use helper | Layer 3 |
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-filters.php` | use helper | Layer 3 |
| `wp-content/plugins/conexao-event-importer/includes/class-event-location.php` | master-list county resolution, NI removed | Layer 2 |
| `wp-content/plugins/conexao-event-importer/includes/class-county-registry.php` | names/slugs derived | one source |
| `wp-content/plugins/conexao-event-importer/includes/class-event-import.php` | transfer import validated | Layer 2 |
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | source county validated | Layer 2 |
| plugin version headers | bump | release contract |
| tests: new `conexao-data-model/tests/test-county-master-list.php`; updates to affected suites | tests | prove the layers |
| `docs/content-model.md`, `docs/plugins/*.md`, `docs/README.md`, plan + report | docs | §9 |

## 7. Files explicitly expected NOT to change

- `plugins.json` (no plugin added/removed/re-classified).
- `wp-content/themes/**/assets/**` (no CSS/JS change).
- `wp-content/themes/**/inc/archive-query.php` (the query layer keeps accepting
  any slug and already forces zero results for unknown slugs).
- WordPress core, Polylang, Jetpack, any third-party plugin.
- `docs/routing.md` (no route added or changed).

| Leisure filter | `template-parts/leisure-filters.php` | built from used terms |
## 8. Content / data impact

- **This repository:** no production content write. Local test fixtures only.
- The repair screen is the production path: inventory → preview → apply →
  verify. It re-assigns relationships before removing a term and never deletes a
  post.
- Portable identity only (canonical county slug); no local IDs as identity.

## 9. Route / HTTP impact

- Routes: **none** added/removed. `?county=<slug>` keeps working for the 26
  canonical slugs; unknown slugs keep returning zero results.
- Redirects: none (no slug changes). `/counties/<slug>/` pages are untouched.
- Sitemap: unchanged.

## 10. Polylang / English impact

- `conexao_county` stays **shared** and language-neutral; no per-language term.
- PT records/URLs are immutable. No EN record is created.
- The filter list is language-independent (proper names), identical on `/en/`.
- No cache-key change; the helper is a pure master-list read.

## 11. Security impact

- Capability: `manage_categories` on the repair screen; `edit_post` unchanged for
  the editor.
- Nonce: a single `conexao_counties_repair` action nonce, verified before any
  write.
- Sanitisation: assignment input is `sanitize_title` + `normalize()`; output is
  escaped (`esc_html`, `esc_url`, `esc_attr`).
- SQL: none added (WP APIs / `get_terms`).
- REST: no new endpoint. Term creation via the existing taxonomy route is
  blocked by `pre_insert_term`; invalid county meta writes fail.
- Secrets: none.

## 12. Performance impact

- Removes one cached `get_posts(id)` + `wp_get_object_terms()` per archive page
  (the helper reads 26 terms from WordPress's term cache). No new transient, no
  new invalidation obligation.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** (from this task) |
| Will a deploy, upload or activation happen? | **no** |
| Who performs a production cleanup? | Maintainer, later, via `Tools → Counties` (WordPress.com has no SSH/WP-CLI) |
| Verification of the production result | Filter shows exactly 26 options; `Tools → Counties` reports 0 out-of-list terms |

## 14. Test plan

- New in-process suite `conexao-data-model/tests/test-county-master-list.php`:
  26 unique alphabetical counties, no NI, normalisation accept/reject matrix,
  insert guard, canonical insert data, rename guard, meta write guard, filter
  helper output (exactly 26, invalid term injected and excluded).
- Update affected suites to the new contract (county fixtures use canonical
  counties; the cache suite keeps term-lifecycle coverage by removing the guard
  around its fixture section only).
- Registry parity assertion: importer registry slugs == master slugs.

## 15. Acceptance matrix plan

Not applicable in this repository layer: the acceptance harness runs against the
local stack only and the county contract is fully expressible in-process. The
existing `/eventos/` acceptance rows are unchanged.

## 16. Rollback plan

Revert the commit(s). The guards are additive; no schema migration and no stored
option is required for correctness. A repair that already moved records must be
reversed from its stored last-run record (per-record source term + target).

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/content-model.md` | counties section: master list, guards, repair | — |
| `docs/plugins/conexao-data-model.md` | component + guard table | — |
| `docs/plugins/conexao-admin-ux.md` | repair screen | — |
| `docs/plugins/conexao-event-importer.md` | county resolution contract | — |
| `docs/README.md` | plan + report index entries | — |

## 18. Release implications

Component header bumps: `conexao-data-model` 1.7.0 → 1.8.0,
`conexao-admin-ux` 1.0.9 → 1.1.0, `conexao-event-importer` (patch). No release is
built or deployed by this task. `plugins.json` unchanged; registry drift gate
must still pass.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Guard breaks a test fixture that needs an out-of-list term | high | fixture-scoped `remove_filter`/`add_filter` around the fixture only |
| A legitimate record has a county meta value in a loose form (`Co. X`) | medium | `normalize()` canonicalises the loose forms instead of rejecting them |
| Repair deletes a term still referenced | low | re-assign first, re-check counts, only delete when empty |
| Scope creep into category/tag/town validation | medium | explicitly out of scope; only `conexao_county` is guarded |

## 20. Verification gates

1. `./scripts/run-tests.sh --php` — new + updated suites green (real numbers in the report).
2. `./scripts/lint.sh` — no new PHPCS/PHPStan violations.
3. `php scripts/generate-registry-docs.php --check` — no registry drift.
4. Rendered `/eventos/` HTML — exactly 26 `data-option-item` county links.

## 21. Completion criteria

All three layers proven by tests, docs updated, no production write, report with
real numbers under `docs/reports/`.

| Events filter | `template-parts/event-filters.php` | built from used terms |
