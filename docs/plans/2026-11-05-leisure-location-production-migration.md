# Plan — Lazer location data: production migration

> Written from [`docs/templates/plan.md`](../templates/plan.md).
> The audit that produced these numbers is
> [`reports/2026-11-05-leisure-location-production-audit.md`](../reports/2026-11-05-leisure-location-production-audit.md);
> machine evidence is in
> [`evidence/2026-11-05-leisure-location-production-audit/`](../evidence/2026-11-05-leisure-location-production-audit/).

## 1. Objective

Populate the **Lazer location dataset** on the production site (`https://conexaobr.ie`)
from the reviewed local source of truth, without touching anything else and
without a single destructive operation.

Measured gap (see the audit §7):

| Data | Local | Production | Action |
|---|---|---|---|
| `leisure` records | 289 real (+4 CI fixtures) | 289 | **none** — already 1:1 by slug |
| `_leisure_lat` / `_leisure_lng` | 289 / 289 populated | **0 / 289** | **MIGRATE** (289 pairs) |
| `conexao_leisure_attribute` terms | 289 records carry terms | 57 records carry terms | **MIGRATE add-only**: 419 term assignments across 232 records; **0 removals** |
| `_leisure_coord_source` / `_leisure_coord_verified` | 289 present / 0 verified | 0 / 0 | **cannot migrate over REST** (§8.2) |
| `conexao_county`, `_leisure_county`, `_leisure_town`, address, official site | — | — | **none** — already identical on all 289 records |

## 2. Why the data is missing

Not a bug and not a partial import: the code was released (production runs the
branch's theme 1.0.2 and the branch's `conexao-admin-ux`), but the **coordinate
dataset is a separate content change that was never applied to production**.
`docs/releases.md` G18 says exactly that:

> **Content dependency** — the coordinate dataset (`scripts/apply-leisure-coordinates.php`)
> is a **separate, separately authorised** content change and is **not** part of
> this code release.

This plan is that separate content change, done over the REST API instead of WP-CLI
because production has no CLI.

The attribute-term gap has the same origin: `scripts/backfill-leisure-attributes.php`
is a **local-write** script that derived `conexao_leisure_attribute` terms from the
legacy booleans. It ran locally and never ran on production.

## 3. What is authoritative, and what is derived

| Field | Authoritative in | Note |
|---|---|---|
| `_leisure_lat`, `_leisure_lng` | **the reviewed manifest** (`scripts/data/leisure-coordinates-2026-11-03.json`) | 289 `POPULATE` entries; 272 HIGH + 17 owner-approved MEDIUM; each row carries its own `owner_source` provenance. Verified byte-for-byte equal to the local database (0 mismatches). |
| `conexao_leisure_attribute` | **the local database** | Derived by `backfill-leisure-attributes.php` from `_leisure_family`, `_leisure_indoor`, `_leisure_outdoor`, `_leisure_booking`, `_leisure_accessibility`, `_leisure_pet_friendly`, `_leisure_parking`, `_leisure_free`. Independently re-derived from **production's own** legacy meta: the derivation is a **subset of the local terms on 289/289 records** (exact on 279), so the local set is a faithful superset and the write is provably consistent with production's own data. |
| `_leisure_export_uuid` | local (manifest) | **Not readable on production** — the key is not `show_in_rest`. See §8.1. |

## 4. Identity strategy

Production's REST API exposes the record `id` and `slug`, and the `meta` object
(which contains `_leisure_lat`/`_leisure_lng` but **not** `_leisure_export_uuid`).

Identity order used by the script:

1. **slug** — the portable, human-stable key. Verified unique on both sides
   (0 duplicate slugs in either environment) and **289/289 matched**.
2. Local WordPress post IDs are **never** used as identity and are never sent;
   the target `id` is used only as the write address *within* the target.
3. The manifest UUID is carried into the report for traceability, never used to
   match (it cannot be read from the target).

The duplicate-UUID case is handled and documented: `_leisure_export_uuid`
identifies the *attraction*, so the Phoenix Park PT master and its EN sibling
share one value by design. Because matching is by slug, that pair resolves to
exactly one record each and cannot double-write.

## 5. Records and fields in scope

**Written (coordinates):** `_leisure_lat`, `_leisure_lng` on 289 existing records.

**Written (opt-in, `--include-attributes`):** `conexao_leisure_attribute` —
**ADD-ONLY in effect.** For each record the script writes the **full local term
set** (the taxonomy API replaces the set, so a full set is what makes the write
idempotent). Add-only is guaranteed by *data*, verified per record: the local set
is a **superset of the target set on all 289 records**, so the replace can never
drop a target-only term — measured **−0 removals**. Measured totals:

| Measure | Value |
|---|---|
| Records touched (full-set write) | **289** |
| Records that actually change | **232** |
| Local term assignments (the written set) | **592** |
| Production assignments already present | **173** |
| **Net additions** | **419** |
| **Removals** | **0** |

**Never written:** title, slug, content, excerpt, date, status, author, featured
media, `_leisure_*` other keys, `conexao_category`, `conexao_county`,
`conexao_town`, `conexao_tag`, and every Polylang translation relationship.

**Never created, never deleted.** The script only fills fields on records that
already exist in the target; a manifest slug with no target record is reported
`UNRESOLVED` and skipped.

## 6. Ordering and dependencies

0. **Prerequisite (blocking)** — fix `register_leisure_coordinates()` (§8.1), release
   and deploy `conexao-data-model`. Until then every coordinate write returns `403`.
1. `--verify` against production → record the "before" coverage (expect
   `with_coordinate=0`).
2. `--dry-run` against production → review the 289-row plan (expect
   `populate=289, conflicts=0, unresolved=0`).
3. `--apply --confirm-production` (coordinates) → expect `written=289`, `gate=0`.
4. Re-run `--apply` → expect `already_set=289`, `written=0` (idempotence gate).
5. `--apply --include-attributes --confirm-production` → expect the 232-record
   add-only attribute plan.
6. Re-run `--apply --include-attributes` → expect no changes.
7. `--verify` → expect `with_coordinate=289`.
8. HTTP spot-check `/lazer/` and a few singles; then the release smoke matrix.

Order matters in one direction only: **coordinates before attributes** is not
required by the data, but it is required by *review* — the coordinate write is
the change that can be blocked by the capability gate, so it fails first and
cheapest.

## 7. API endpoints and authentication

| Purpose | Endpoint | Method |
|---|---|---|
| Read the target dataset | `/wp-json/wp/v2/leisure?per_page=100&page=N&_fields=id,slug,status,meta` | GET |
| Write a coordinate | `/wp-json/wp/v2/leisure/{id}` with `{"meta":{"_leisure_lat":…,"_leisure_lng":…}}` | POST |
| Read/write the attribute taxonomy | `/wp-json/wp/v2/conexao_leisure_attribute` | GET / POST |

* **Base URL:** `https://conexaobr.ie` (the site's own `wp-json`, verified
  reachable and API-enabled over HTTP). The `public-api.wordpress.com/wp/v2/sites/conexaobr.ie`
  proxy exists and works read-only, but the site's own endpoint is simpler and is
  what the script uses.
* **Auth:** HTTP Basic with **WordPress Application Password** from
  `WP_USERNAME` / `WP_APPLICATION_PASSWORD`. Verified: an administrator application
  password authenticates against production (`GET /users/me` → 200, role
  `administrator`, `manage_options: true`).
* **Pagination:** `X-WP-TotalPages` (289 records → 3 pages at 100/page).
* **Rate limits:** production returns `HTTP 429` under sustained polling
  (observed and confirmed during the audit). The script honours `Retry-After`
  with bounded exponential backoff and a `--request-delay` between writes.

### 7.1 Limitations and the one blocking prerequisite

#### 7.1.1 BLOCKING — coordinate meta is not writable over REST (deployed plugin)

`wp-content/plugins/conexao-data-model/includes/class-meta.php`
`register_leisure_coordinates()` registers `_leisure_lat` / `_leisure_lng` with
`show_in_rest => true` but **no `auth_callback`**. WordPress core's
`register_meta()` then falls back to `is_protected_meta()`; both keys are
underscore-prefixed, so the effective `auth_callback` becomes `__return_false`
and **every REST write is refused with `403 rest_cannot_update`** — even for an
administrator.

**Proven, not assumed** (local stack, real administrator application password):

```text
POST /wp-json/wp/v2/leisure/22734  {"meta":{"_leisure_lat":"53.1234","_leisure_lng":"-6.5678"}}
  -> HTTP 403  rest_cannot_update  "Sem permissão para editar o campo personalizado _leisure_lat."

POST /wp-json/wp/v2/leisure/22734  {"meta":{"_leisure_county":"Wicklow"}}
  -> HTTP 200                       (control: that key HAS an auth_callback)
```

The control proves the credential and the endpoint are fine, isolating the cause
to the missing `auth_callback` on the coordinate keys.

Production runs the branch build (`style.css` sha256 identical to the branch;
`conexao-admin-ux` `.pot` sha256 identical to the branch), so production carries
the same registration and the REST write will fail there too until it is updated.

**The fix** mirrors the pattern already used by `register_leisure_meta()` and
`register_agency_meta()` in the same plugin (the `_leisure_image_*` precedent):

```php
'auth_callback' => function ( $allowed, $meta_key, $object_id ) {
    return current_user_can( 'edit_post', $object_id );
},
```

added to each of the two coordinate `register_post_meta()` calls. This is a
**plugin change requiring its own release + ZIP deploy**, which this task does
not perform (no deploy). It is the single prerequisite for step 6.0.

#### 7.1.2 `_leisure_coord_source` / `_leisure_coord_verified` cannot cross

Both are `show_in_rest => false`, so the REST API neither returns nor accepts
them. Consequence: production records will carry lat/lng **without** provenance,
and the admin "Coordenadas" column will show **"por verificar"** — truthful,
since the local set has `_leisure_coord_verified = 0` on all 289 records too (a
machine two-source result is not an editor's on-map confirmation).

The **front end needs neither**: `Conexao_Data_Model_Meta::get_coordinates()`
reads only `_leisure_lat`/`_leisure_lng`, and the proximity feature calls that.
The feature is therefore complete without provenance.

#### 7.1.3 Slug matching, not UUID matching

`_leisure_export_uuid` is not REST-visible, so the strongest key is unavailable.
Slug matching is used and **reported** — the standard permits slug matching only
as a reported fallback (count and print them). The script reports `match=slug`
per row and fails closed on duplicate target slugs.

#### 7.1.4 Local data is not uniformly authoritative

Four local records are **CI-fixture contaminated** by
`scripts/bootstrap-ci-fixtures.php` (`phoenix-park`, `cliffs-of-moher`,
`fota-wildlife-park`, `dwyer-mcallister-cottage`): local titles read
`(sintético)`, and `fota-wildlife-park` carries
`_leisure_official_website = https://example.invalid/fota`. Production holds the
**real** titles and URLs for those four.

This does not block the coordinates — each manifest coordinate was researched

## 8. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Coordinate write returns 403** (deployed plugin lacks `auth_callback`) | **high** until §7.1.1 ships | The script fails closed on the first `403` with an actionable message; the mock suite proves that path. Prerequisite step 6.0. |
| Rate limiting (`429`) mid-run | medium | `Retry-After` + bounded backoff; `--request-delay`; resumable journal. |
| Wrong record matched | low | Slug unique on both sides (0 duplicates, verified); fails closed on target duplicates; match strategy reported. |
| An existing production coordinate is overwritten | none by default | `CONFLICT` is preserved unless `--overwrite` is passed; asserted by a dedicated mock test. |
| An attribute write removes a production-only term | none | The local set is a **superset of the target set on all 289 records** (verified per record), so the full-set write removes nothing; −0 removals measured. |
| A partial run leaves half the dataset | low | Resume journal + idempotent re-plan; verified by an interruption/resume assertion. |
| **Scope creep** — "while we are here, fix the image source URLs / titles" | medium | Explicitly out of scope (§10). Those are separate content changes with their own plan. This plan writes two meta keys and one add-only taxonomy. |
| Credential leak | low | Environment-only; never printed, logged or written to the report; asserted by a dedicated test. |

## 9. Rollback

The migration is **additive on empty fields**, so rollback reverses the same fields:

1. **Journal-based:** `.local/leisure-location-migration-state.json` records every
   `slug → {target_id, lat, lng}` written, in order. Revert by re-issuing each with
   an empty string (or through the wp-admin editor).
2. **Pre-change snapshot:** the audit's `inventory-summary.json` records
   `with_coordinate = 0` on production before the change, so "revert to the prior
   state" means clearing `_leisure_lat`/`_leisure_lng` on those 289 IDs.
3. **Attributes:** capture the target's pre-change term set per record (the plan
   merges it and reports it) before applying, and re-set that set to revert.

Rollback does **not** cover an operator using `--overwrite` (which destroys a
pre-existing differing value), nor manual edits made between snapshot and apply.

## 10. Explicitly out of scope

| Item | Why excluded |
|---|---|
| Titles with typographic differences (14) | 10 are `’` vs `'` only; 4 are the CI fixtures. Production is correct; not location data. |
| `_leisure_image_source_url` format (243 differ: production uses `/w/api.php/File:`, local `/wiki/File:`) | Image attribution, not location. Belongs to `scripts/fix-wikimedia-source-urls.php`, a separate change. |
| 13 records where production has a featured image and local does not | Media, environment-local attachment IDs; not location. |
| `_leisure_practical_notes` on one record | Editorial copy, not location. |
| `_leisure_excerpt_en` (289 local) | English layer; not exposed by production REST; a translation change. |
| Creating the 4 CI fixtures on production | They are test doubles, not attractions. |
| Any deletion or cleanup | Forbidden by the task; the script has no delete path at all. |

## 11. Verification gates

Each is a number that must reach the stated value:

1. `--verify` before → `with_coordinate = 0` on production.
2. `--dry-run` → `populate = 289`, `conflicts = 0`, `unresolved = 0`, `written = 0`.
3. `--apply` → `written = 289`, `gate = 0`, `api_retries` reported.
4. Second `--apply` → `already_set = 289`, `written = 0`.
5. `--apply --include-attributes` → `attributes_written = 232`, `−0 removals`.
6. `--verify` after → `with_coordinate = 289`.
7. `./scripts/run-tests.sh` → all green (the script-contract layer now includes
   `tests/scripts/verify-leisure-location-migration.py`: 58 assertions).

## 12. Completion criteria

The migration is done when every gate in §11 reaches its number, the write is
idempotent on a re-run, and the `/lazer/` archive returns the proximity data
(289 items with non-null lat/lng from `/wp-json/conexao/v1/leisure-coordinates`).
Until §7.1.1 ships, the correct status is **blocked on a plugin release**, not
"done".


against the real attraction and is correct for production. It is precisely why
the migration writes *only* lat/lng (plus add-only attributes) and never titles,
excerpts or websites: a naive "copy the whole record" would push `sintético`
titles into production.

_Last verified: 2026-11-05 by the Lazer location production migration plan_
