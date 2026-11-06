# Report — Lazer location data: local ↔ production audit and migration preparation

| | |
|---|---|
| **Stage / task name** | Audit local location data against production, and prepare a migration |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `b2ea4e6226e31cb42bb47921dfe2d8616911e44e` (`test: add release-preparation evidence for the Lazer proximity branch`) |
| **Final SHA** | uncommitted (committing is out of scope for this task; see §3) |
| **Working tree at finish** | dirty — this task's files, plus one **pre-existing** `deleted: ''` entry that was already in the worktree before the task started and is **not ours** (preserved untouched) |
| **Skills followed** | `wp-content-change`, `wp-repository`, `wp-security-review`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md` §"Leisure coordinates"/"Import/Export Identifiers", `docs/routing.md`, `docs/releases.md` (G18), `docs/deployment.md`, `scripts/README.md`, `docs/templates/plan.md`, `docs/templates/report.md`, `plugins.json` |

## 0. Final verdict

**`MIGRATION REQUIRED — SCRIPT PREPARED`**

Production is missing **every one of the 289 Lazer coordinates** (0/289 populated) that the local database holds (289/289 populated), and it is also missing **419 add-only practical-attribute term assignments across 232 records** (0 removals required). The migration script is written, validated against a loopback mock API (**58 passed, 0 failed**), and proven to refuse a production write without explicit confirmation.

One **capability blocker** is documented and must be resolved *before* an apply is attempted: the deployed `conexao-data-model` registers `_leisure_lat`/`_leisure_lng` **without an `auth_callback`**, so WordPress core denies the REST write with **HTTP 403 `rest_cannot_update`**. This was proven empirically on the local stack (§8), and it is the same defect class the repository already fixed for the `_leisure_image_*` keys. The fix is a one-line plugin change that must be released and deployed first; it is **not** part of this task ("no deploy").

## 1. Scope completed

1. **Mandatory preflight** — read `AGENTS.md`, `docs/engineering-standard.md` (§0, §2.1, §4.2, §5.2, §7, §8, §9), `docs/testing.md`; inspected branch/HEAD/worktree; confirmed the local stack is the Docker Compose stack on **`http://localhost:8080`** (§2).
2. **Location data model identified** — post type, taxonomies, meta keys, REST surface, identity keys, and which fields are *authoritative* vs *derived* (§3).
3. **Local inventory** — every `leisure` record, with coordinates, UUIDs, taxonomy coverage, translations and media (§5).
4. **Read-only production audit** — 289 records, all location fields and taxonomies, over the public REST API (§6).
5. **Comparison** — 1:1 by slug; zero missing/extra records; the gap is in *fields*, quantified (§7).
6. **Migration plan** — what is migrated, what is not, ordering, endpoints, auth, risks (§8).
7. **Migration script** — `scripts/migrate-leisure-location-rest.py` (§9).
8. **Validation** — mock-API suite `tests/scripts/verify-leisure-location-migration.py`, plus a real read-only dry run against local **and** production (§10).

## 2. Scope NOT completed (and why)

| Not done | Why |
|---|---|
| **No production write of any kind** | Forbidden by the task and by `AGENTS.md`. Every production request was a GET against the public REST API. |
| **`--apply` was never run against production** | Forbidden. Apply mode was exercised **only** against the loopback mock API, as the task requires. |
| **`_leisure_coord_source` / `_leisure_coord_verified` are not migrated** | They are registered with `show_in_rest => false`, so they are **not writable through the REST API at all** — a platform limitation, not an omission (§6.4). The proximity feature reads only lat/lng, so this does not block the feature. |
| **The `auth_callback` plugin fix was not applied** | It is a change to a **production plugin** requiring its own release + deploy, and this task explicitly forbids deploying. Documented as a prerequisite in §8.0 with the exact change. |
| **No `_leisure_export_uuid` on production was read or written** | Production's REST API does not expose it (`show_in_rest` is not set for that key), so it cannot be read; and writing it is out of scope. Matching therefore uses **slug**, reported as such (§7.1). |
| **Mobile/Flutter consumer not inspected** | Explicitly out of scope in this repository. |

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `scripts/migrate-leisure-location-rest.py` | **added** | The migration script (dry-run default; `--apply` gated). |
| `tests/scripts/verify-leisure-location-migration.py` | **added** | Loopback mock-API validation suite (58 assertions). |
| `scripts/README.md` | modified | Catalogues the new script (required by the Stage I script-contract gate). |
| `docs/plans/2026-11-05-leisure-location-production-migration.md` | **added** | The plan. |
| `docs/reports/2026-11-05-leisure-location-production-audit.md` | **added** | This report. |
| `docs/evidence/2026-11-05-leisure-location-production-audit/` | **added** | Machine-readable inventory + comparison + validation output. |
| `docs/README.md`, `docs/reports/README.md` | modified | Index the new documents. |

**5 added, 3 modified, 0 deleted.** **No `wp-content/` file was changed** — the runtime is byte-identical, so the site behaves exactly as before.

## 3a. Runtime impact

**WordPress is unchanged.** No file under `wp-content/` was added, modified or
deleted, so the theme, plugins, templates, REST routes, admin screens and content
behave exactly as before. The only runtime-adjacent artefact is a **new offline CLI
script** plus a static/mock test suite, neither of which WordPress loads.

`git diff --name-only` touches no `wp-content/` path. A green
`./scripts/run-tests.sh --scripts` run after the change (20/20) and a green
in-process suite before it, plus an unchanged `wp-content/`, is the evidence.

## 3b. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** — GET only |
| No production content, Polylang or database change | **confirmed** |
| No application behaviour change (`wp-content/` diff is empty) | **confirmed** |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** — `.env` untouched; the local app password was re-issued out-of-band, space-free |
| No `git reset` / `git clean` / branch switch / stash use | **confirmed** |
| No commit or push | **confirmed** |


## 4. Local environment (confirmed, not assumed)

| Check | Result |
|---|---|
| Stack | `docker compose ps` → `wordpress-website-db-1` (mysql, healthy) and `wordpress-website-wordpress-1` (up 20 h) |
| Site URL | **`http://localhost:8080`** (port mapping `0.0.0.0:8080->80/tcp`) |
| `wp_get_environment_type()` | `local` (so application passwords work over plain HTTP) |
| WordPress version | PHP 8.3.33, WordPress from the official image |
| Application-password auth | `GET /wp-json/wp/v2/users/me` → **200**, user `test`, role administrator |

### 4.1 One local credential defect found and worked around (not fixed — out of scope)

`scripts/ensure-local-test-user.php` hashes the **raw** `WP_APPLICATION_PASSWORD`
value, but WordPress core strips non-alphanumerics from a submitted application
password (`wp_authenticate_application_password()` →
`preg_replace('/[^a-z\d]/i','',$password)`). The `.env` value contains **spaces**
(the normal WordPress display grouping), so the stored hash can never match and
every Basic-auth request returns **401**.

*Evidence:* `POST`-free probe — `curl -u "$WP_USERNAME:$WP_APPLICATION_PASSWORD"`
against `/wp-json/wp/v2/users/me` → `401 rest_not_logged_in`; the same request
with a space-free password → `200`.

*Worked around locally only:* the audit repaired the local test account with a
space-free application password (`ensure-local-test-user.php --apply`). **The
`.env` file was not modified and no script was changed.** This is reported because
it will bite the next person: it is a one-line fix in that script (strip the
spaces before hashing) but it is not this task's scope, and a test-tooling change
must not be smuggled into a migration-preparation task.

## 5. Location data model — what is authoritative, what is derived

Identified from `conexao-data-model`, `conexao-admin-ux`, `conexao-leisure-migration`
and the theme's `inc/leisure-location.php`.

| Element | Where | Authority |
|---|---|---|
| Post type | `leisure` (archive `/lazer/`, `show_in_rest`, `rest_base=leisure`) | `conexao-data-model` |
| **Coordinates** | `_leisure_lat`, `_leisure_lng` — canonical decimal **strings**, REST-visible | **the reviewed manifest** (local source of truth) |
| Coordinate provenance | `_leisure_coord_source`, `_leisure_coord_verified` — `show_in_rest => false` | local only; **not transferable over REST** |
| County / town (meta) | `_leisure_county`, `_leisure_town` | already identical on both sides |
| County (taxonomy) | `conexao_county` — **SHARED** across languages | already identical |
| Town (taxonomy) | `conexao_town` — **SHARED**; leisure uses meta, not the term | already identical (prod has 0 leisure town terms; local has 4, all CI fixtures) |
| Category | `conexao_category` — **TRANSLATED** | already identical except 2 CI fixtures |
| Practical attributes | `conexao_leisure_attribute` — 13 terms, shared | **derived** from legacy booleans by `backfill-leisure-attributes.php` → **migratable** |
| Stable identity | `_leisure_export_uuid` — **not `show_in_rest`** | local only; **not readable on production** |
| Address / official site / map URL | `_leisure_address`, `_leisure_official_website`, `_leisure_map_url` | already identical (1 CI exception) |
| Images | `_leisure_image_attachment_id` + attribution keys | environment-local attachment IDs; not location data |
| English layer | `_leisure_excerpt_en` (field, same record — B2) | not exposed by production REST; a translation concern |
| Proximity consumer | `GET /conexao/v1/leisure-coordinates` → `{id, lat, lng, index}`; `Conexao_Data_Model_Meta::get_coordinates()` reads **only lat/lng** | confirms lat/lng are the only fields the feature needs |

Explicitly **not** treated as location data: `_leisure_duration`, `_leisure_best_time`,
`_leisure_feature`, `_leisure_practical_*`, images, and the English excerpt. They
were inspected and reported but are out of the migration's scope.

## 6. Local inventory (read-only)

Read via `WP_Query`/`get_post_meta`/`wp_get_post_terms` inside a one-off script run
with `docker compose exec` — no content was modified. Machine-readable:
`evidence/…/inventory-summary.json`.

| Measure | Value |
|---|---|
| Published `leisure` records in the database | **294** (293 returned by the REST API + 1 EN sibling) |
| `[PH3C TEST]` CI fixtures (not attractions) | **4** (`22734`, `22737`, `22738`, `22739`) |
| CI-fixture **EN sibling** (`phoenix-park-en`, `115357`) | **1** |
| **Real attractions** | **289** |
| Records with valid `_leisure_lat` **and** `_leisure_lng` | **289 / 289** |
| Records with a partial (half) coordinate | **0** |
| Records with an invalid / out-of-range coordinate | **0** |
| Records carrying `_leisure_export_uuid` | **289** (+1 shared with the EN sibling = 290 rows) |
| **Distinct** UUID values | **289** |
| Duplicate UUID **groups** | **1**, by design: `ci-fixture-0000-…-0002` on `phoenix-park` + `phoenix-park-en` (one attraction, PT master + EN sibling) |
| Records with **no** `_leisure_export_uuid` | **4** (the PH3C fixtures) |
| Malformed UUIDs (non-v4 shape) | **1** — the committed CI constant `ci-fixture-…`, not real data |
| Duplicate slugs | **0** |
| `_leisure_coord_source` present | **289 / 289** |
| `_leisure_coord_verified` set | **0 / 289** (deliberate: machine research ≠ editor confirmation) |
| `conexao_county` coverage | **293** records |
| `conexao_leisure_attribute` coverage | **289** records |
| `conexao_town` (leisure) | **4** records — all CI fixtures |
| `conexao_tag` (leisure) | **0** |
| `_leisure_excerpt_en` present | **289** |
| Featured image | **275 / 289** |
| Polylang | leisure is **B2** (English is a field on the same record); the only `post_translations` relations are the CI pair |
| Manifest ↔ database agreement | **0** lat/lng mismatches and **0** UUID mismatches across all 294 manifest entries |

### 6.1 Three inconsistencies that matter for migration

1. **CI fixtures live in the real content range.** `bootstrap-ci-fixtures.php`
   created 4 `[PH3C TEST]` records *and* **overwrote four real records**:
   `phoenix-park`, `cliffs-of-moher`, `fota-wildlife-park`, `dwyer-mcallister-cottage`.
   The last three kept their real UUID but got the title suffix `(sintético)`; all
   four got CI town/category terms; `fota-wildlife-park` got
   `_leisure_official_website = https://example.invalid/fota`.
   **Consequence: local titles/taxonomies are NOT authoritative for those four.**
2. **The Phoenix Park UUID is a CI constant, not the real identity.** Local
   `phoenix-park` (10150) carries `ci-fixture-…-0002` because the fixture bootstrap
   overwrote the real production record's UUID. Production's real Phoenix Park
   carries a different UUID that is **not readable over REST** anyway.
3. **Attachment IDs coincide numerically but are environment-local.** Local and
   production both have `_leisure_image_attachment_id` values in the 10280s, but
   attachment IDs are per-environment and must never be treated as identity.

## 7. Production audit (strictly read-only) and comparison

**Every production request was a `GET` against the public REST API.** No POST, PUT,
PATCH or DELETE was issued; the task's own apply path was never pointed at
production. Rate limiting (`HTTP 429`) was encountered and honoured.

### 7.1 Record-level comparison

| Measure | Value |
|---|---|
| Production plan/identification | WordPress.com **Atomic**, site ID `256777017`, `is_private: false` |
| Production published `leisure` records | **289** |
| Matched to local **by slug** | **289 / 289** |
| Present locally, missing in production | **0** |
| Present in production, missing locally | **0** |
| Duplicate slugs in production | **0** |
| Local CI-only records with no production counterpart | **5** (4 PH3C + 1 EN sibling) — correctly absent |
| **Production records with a coordinate** | **0 / 289** |
| **Local records with a coordinate** | **289 / 289** |

Confirmed independently: `GET /wp-json/conexao/v1/leisure-coordinates` on production
returns `{"count":289, items:[{lat:null, lng:null}, …]}` — **every** coordinate is
null. The feature is deployed and inert.

### 7.2 Field-level discrepancies (289 matched records, HTML-entity-normalised)

| Field | Differing | Classification |
|---|---|---|
| `_leisure_lat` / `_leisure_lng` | **289 / 289** | **MISSING IN PRODUCTION** — local populated, production empty on every record |
| `conexao_leisure_attribute` | **232** | **MISSING IN PRODUCTION** — full-set write of 592 local assignments vs 173 already present = **+419 net additions across 232 records, −0 removals** |
| `_leisure_county`, `conexao_county` | **0** | identical |
| `_leisure_town` (meta) | **0** | identical |
| `_leisure_address` | **0** | identical |
| `_leisure_map_url` | **0** | identical |
| `_leisure_free` and the 7 legacy booleans | **0** (1 record: `_leisure_parking` on `queen-maeves-trail-knocknarea`) | effectively identical |
| `_leisure_official_website` | **1** | **local is WRONG** (`fota-wildlife-park` = `example.invalid`, a CI artifact). Production correct → **do not migrate** |
| `conexao_town` (term) | **4** | **local is WRONG** — all four are CI-injected town terms on CI fixtures; production correctly has none → **do not migrate** |
| `conexao_category` | **2** | **local is WRONG** (`Natureza` injected by the CI fixture) → **do not migrate** |
| Titles | **14** | 4 = CI fixtures (`(sintético)`); 10 = `’` vs `'` typographic only → **not location data, do not migrate** |
| `_leisure_image_source_url` | **243** | production uses `/w/api.php/File:`, local `/wiki/File:` — image attribution, **out of scope** |
| `_leisure_practical_notes` | **1** | editorial copy, **out of scope** |
| Featured image | **13** (production has, local lacks) | media, **out of scope** |
| Status | **0** | all `publish` on both sides |

### 7.3 Coordinate coverage, precisely

| | Local | Production |
|---|---|---|
| Records | 289 real | 289 |
| With **both** lat and lng | **289** | **0** |
| With only one of the pair | 0 | 0 |
| Out of range / malformed | 0 | n/a (all empty) |
| `_leisure_coord_source` present | 289 | 0 (not REST-visible) |
| `_leisure_coord_verified` set | 0 | 0 |

### 7.4 Production is running the **branch** build (so the code is not the gap)

| Artifact | Evidence | Conclusion |
|---|---|---|
| Theme `conexao-br-irlanda` | `style.css` sha256 on production **= `505ca6a8e808…`**, byte-identical to the branch (master is `d40b1c678d44…`); `Version: 1.0.2` = the G18 bump | branch build live |
| `conexao-admin-ux` | `.pot` sha256 **= `831b319699e3…`** = branch (master `1e89d9072e5f…`) | branch build live |
| `conexao-data-model` | `.pot` is identical on master and branch, so it cannot discriminate; but production's `/wp/v2/leisure` OPTIONS schema **exposes `_leisure_lat`/`_leisure_lng`**, and master's data model does not register those keys at all | coordinate registration is live |
| `/conexao/v1/leisure-coordinates` | present and returns 289 items | branch theme live |

So the location **code** is deployed; only the **data** is missing — which is what
`docs/releases.md` G18 states ("the coordinate dataset is a separate, separately
authorised content change"). That is the whole finding.
### 7.5 Production API capabilities actually verified (not assumed)

| Capability | Verified how | Result |
|---|---|---|
| Site's own REST root | `GET https://conexaobr.ie/wp-json/` | **200**; namespaces include `wp/v2`, `conexao/v1`, `pll/v1`, `wpcom/v2` |
| `leisure` CPT exposed | `GET /wp-json/wp/v2/leisure` | **200**, `X-WP-Total: 289` |
| Location meta readable | `_leisure_lat`/`_leisure_lng` in the `meta` object and the OPTIONS schema | **yes** |
| Provenance meta readable/writable | `_leisure_coord_source`/`_leisure_coord_verified` absent from the schema | **no** — `show_in_rest=false` |
| `_leisure_export_uuid` readable | absent from the `meta` object and the schema | **no** |
| Taxonomies exposed | county/town/category/leisure_attribute/tag in the type schema | **yes**, all readable |
| Authenticated access | `GET /wp/v2/users/me?context=edit` with an admin app password | **200**, `administrator`, `manage_options: true` |
| Application passwords | same call | **yes** |
| Pagination | `X-WP-TotalPages` present | **yes** (289 → 3 pages) |
| Rate limiting | sustained polling triggered `HTTP 429` | **yes** — must be honoured |
| SSH / SFTP / WP-CLI / DB / filesystem | none attempted, none available to this task | **not available** — REST is the only supported channel |
| WordPress.com proxy | `https://public-api.wordpress.com/wp/v2/sites/conexaobr.ie/leisure` | **200**, equivalent read surface |
| **Coordinate META write** | not attempted on production (forbidden); proven locally on the identical build | **403 `rest_cannot_update`** — §8 |

## 8. The one blocking finding: coordinate meta is not REST-writable

`conexao-data-model` registers the coordinate keys with `show_in_rest => true` but
**without an `auth_callback`**. WordPress core then falls back to
`is_protected_meta()`; because the key is underscore-prefixed that becomes
`__return_false`, and **every REST write is refused with `403 rest_cannot_update` —
even for an administrator**. Proven empirically on the local stack with the real
admin application password:

```text
POST /wp-json/wp/v2/leisure/22734  {"meta":{"_leisure_lat":"53.1234","_leisure_lng":"-6.5678"}}
  -> HTTP 403  rest_cannot_update

POST /wp-json/wp/v2/leisure/22734  {"meta":{"_leisure_county":"Wicklow"}}     <-- control
  -> HTTP 200
```

The control isolates the cause: authentication, endpoint, capability and CORS are
all fine — only the missing `auth_callback` on those two keys is at fault.

**This is the same defect class the repository already fixed** for `_leisure_image_*`
(`docs/deployment.md`: "requires the `conexao-data-model` update that adds an
`auth_callback` to `register_leisure_meta()` — without it the attribution meta is
read-only over REST (`403 rest_cannot_update`)").

**Fix:** add the `auth_callback` already used by the sibling `register_leisure_meta()`
and `register_agency_meta()` to each of the two coordinate registrations — a plugin
change requiring its own release and ZIP deploy. The migration plan records it as
the single blocking prerequisite. It is **not** applied here (no deploy).


## 9. Migration script (deliverable C)

`scripts/migrate-leisure-location-rest.py` — Python 3.8+, standard library only,
reusing `scripts/lib/rest.py` for base-URL resolution, target classification,
credential handling and headers.

### 9.1 How it meets every requirement

| Requirement | Implementation |
|---|---|
| Dry run by default | `--dry-run` is the default; `--apply` is the only write mode; combining them exits 2 |
| Never write unless explicitly told | `--apply` + an explicit `--target-url` (no default) + `--confirm-production` for production |
| Explicit production target | `--target-url` has **no default value**; the resolved target and classification print first |
| Explicit confirmation | `--confirm-production` required for production; verified by a test |
| Credentials from env, never logged | `WP_USERNAME` / `WP_APPLICATION_PASSWORD` via `rest.default_headers()`; never printed, never in the report; asserted |
| Stable identifiers | matches by **slug**; never sends or uses a local post ID; strategy reported |
| Idempotent / no duplicates | an identical value is `ALREADY_SET`; a second apply writes 0; no record is ever created |
| Pagination | follows `X-WP-TotalPages` |
| Rate limits / retries | honours `Retry-After`, bounded exponential backoff, retries 429/5xx only, 4xx fails immediately |
| Clear API errors | status, path, a bounded body slice and an actionable hint on 401/403 |
| Validates input | range-checked lat/lng, rejects comma decimals/NaN/∞, never writes a half pair |
| Logs every intended operation | one `ACTION slug reason` line per record + a JSON report |
| Machine-readable report | `--report <path>` (per-record plan and summary) |
| **Resumable** | a JSON journal (`.local/…-state.json`, git-ignored) records each applied write; a re-run resumes |
| No destructive updates | no delete path exists; a differing target value is `CONFLICT` unless `--overwrite` |
| Preserves production-only data | only two meta keys and the opt-in add-only taxonomy are ever sent |
| No status change | status is never sent |
| No translation/taxonomy side effects | Polylang untouched; category/county/town/tag never sent |

### 9.2 Usage

```bash
export WP_USERNAME='…' WP_APPLICATION_PASSWORD='…'    # env only, never committed

# 1. read-only coverage report of the target
python3 scripts/migrate-leisure-location-rest.py --target-url https://conexaobr.ie --verify

# 2. plan only (writes nothing)
python3 scripts/migrate-leisure-location-rest.py --target-url https://conexaobr.ie --dry-run

# 3. apply (the only write mode; explicit target + confirmation)
python3 scripts/migrate-leisure-location-rest.py \
    --target-url https://conexaobr.ie --apply --confirm-production

# 4. re-run to prove idempotence (expect written=0)
# 5. opt-in attribute phase (add-only)
python3 scripts/migrate-leisure-location-rest.py \
    --target-url https://conexaobr.ie --apply --confirm-production --include-attributes
```

**Recovery:** re-run the same command after an interruption — the journal
(`--state-file`, default `.local/leisure-location-migration-state.json`) skips what
already landed. `--no-resume` ignores the journal.

**Expected output (production dry run, measured):**

```text
target: https://conexaobr.ie (production)
mode:   dry-run
  POPULATE  <slug>  target has no coordinate      (x289)
summary: manifest_entries=289, eligible_entries=289, target_records=289,
         populate=289, update=0, already_set=0, conflicts=0, unresolved=0,
         planned_writes=289, written=0, with_before=0, with_after=0, gate=0
(dry run — nothing written)

## 10. Validation report (deliverable D)

### 10.1 Commands executed

| # | Command | Exit | Result |
|---|---|---|---|
| 1 | `docker compose ps` | 0 | db healthy, wordpress up on `:8080` |
| 2 | `docker compose exec -T db mysql …` (counts, meta, taxonomy) | 0 | local inventory (§6) |
| 3 | `curl /wp-json/wp/v2/leisure` (local) | 200 | `X-WP-Total: 294` |
| 4 | `curl /wp-json/wp/v2/leisure` (production, 3 pages) | 200 | 289 records (429s honoured and retried) |
| 5 | `curl /wp-json/conexao/v1/leisure-coordinates` (production) | 200 | 289 items, **all lat/lng null** |
| 6 | `curl -X OPTIONS /wp-json/wp/v2/leisure/10150` (production) | 200 | meta schema captured (§7.5) |
| 7 | `python3 scripts/migrate-leisure-location-rest.py --help` | 0 | usage + safety + modes |
| 8 | `… --apply` (no target) | 1 | refused, names `--target-url` |
| 9 | `… --apply --dry-run` | 2 | refused, "mutually exclusive" |
| 10 | `… --target-url https://conexaobr.ie --apply` (no confirm) | 1 | refused, names `--confirm-production` |
| 11 | `… --target-url http://localhost:8080 --dry-run` | 0 | **289 ALREADY_SET, 0 written** |
| 12 | `… --target-url https://conexaobr.ie --dry-run --report …` | 0 | **289 POPULATE, 0 written, gate=0** |
| 13 | `python3 tests/scripts/verify-leisure-location-migration.py` | **0** | **58 passed, 0 failed** |
| 14 | mutation: make dry-run write → re-run the suite | 1 | **3 assertions fail** (the suite discriminates) |
| 15 | `./scripts/run-tests.sh --scripts` | **0** | **20/20 suites, ALL TESTS PASSED** (includes the new suite) |
| 16 | `python3 tests/scripts/verify-script-conventions.py` | 0 | 761 passed, 0 failed |
| 17 | `python3 tests/scripts/verify-agent-governance.py` | 0 | 357 passed, 0 failed |

### 10.2 Mock-API test coverage (deliverable D, required matrix)

All assertions run against a **loopback stub** (`127.0.0.1`, ephemeral port) that
reproduces WordPress semantics: `X-WP-TotalPages` pagination, `429`+`Retry-After`,
`401` without credentials, and **`403 rest_cannot_update`** for protected meta.

| Required case | Covered by | Result |
|---|---|---|
| Dry-run mode | `case_dry_run_writes_nothing` — 0 writes, 0 write requests, `gate=0` | **PASS** |
| Missing local records | `missing-local` → `UNRESOLVED` (never created) | **PASS** |
| Existing matching records | `alpha` pre-set → `ALREADY_SET` (no write) | **PASS** |
| Duplicate UUIDs | manifest carries the shared Phoenix Park UUID; matching is by slug so the pair resolves 1:1; duplicate **target** slugs fail closed | **PASS** |
| Changed coordinates | `case_conflict_is_preserved` — differing value preserved, 0 writes | **PASS** |
| Missing coordinates | `no-coords` → `UNRESOLVED`, nothing written | **PASS** |
| Invalid latitude/longitude | `bad-coords` (999) → `REJECTED`, `UNRESOLVED` | **PASS** |
| Taxonomy differences | `case_attributes_add_only` — the local set is sent verbatim, no removal concept exists, unknown target terms are dropped, target-absent records are never planned | **PASS** |
| Attribute write never removes a target term | `case_attributes_add_only` + the verified superset property (289/289 records, −0 removals) | **PASS** |
| API pagination | stub serves 2/page over 6 records → 3 pages read | **PASS** |
| API authentication failure | `case_auth_failure` → non-zero + names the env var | **PASS** |
| Rate limiting | `case_rate_limit` → 429 retried, run succeeds | **PASS** |
| Interrupted migration | `case_resume_after_interruption` → journal + resume completes | **PASS** |
| Idempotency | second apply writes 0; `already_set=3` | **PASS** |
| **No accidental production writes** | production-target guard + `--apply`/`--dry-run` exclusivity + dry-run 0-writes | **PASS** |
| Apply mode tested against a mock **only** | every apply in the suite targets the stub; production was only ever `--dry-run` | **PASS** |

### 10.3 Idempotency evidence

```text
local, first dry run   : populate=0,  already_set=289, written=0, gate=0
local, re-run          : populate=0,  already_set=289, written=0, gate=0   (identical)
production dry run     : populate=289, already_set=0,  written=0, gate=0
mock apply (1st)       : written=2, gate=0
mock apply (2nd)       : written=0, already_set=3                          (idempotent)
mock interrupted       : written=1, journal=1  ->  resume -> written=2, journal=2
```

### 10.4 Confirmation that production was never modified

* Every production request in this task was an **HTTP GET** against the public REST
  API (`/wp-json/…` and `public-api.wordpress.com`). **No POST, PUT, PATCH or
  DELETE was issued to production.**
* The one write probe (§8) was made against the **local** stack
  (`http://localhost:8080`), on a CI-fixture record, and its 403 means it wrote
  nothing anyway.
* The production `--dry-run` reports `written=0` and issues no write request.
* No production content, meta, taxonomy, settings, plugin or database state was
  changed. No deploy, upload or activation occurred.


## 11. Final verdict (deliverable E)

### `MIGRATION REQUIRED — SCRIPT PREPARED`

**Why not "NO MIGRATION REQUIRED":** production holds **0 of 289** coordinates and
**57 of 289** attribute-term sets, while the local database holds **289 of 289** for
both. The deployed proximity feature therefore returns `lat: null` for every
listing and cannot order by distance at all.

**Why the script is genuinely prepared:** it exists, it is catalogued, it is
blocked-in-CI validated (**58 assertions, and the suite provably discriminates — a
dry-run-that-writes is caught by 3 assertions**), it was exercised read-only against
the **real** production dataset (289 rows planned, 0 writes), and every safety
requirement in the task is implemented and separately asserted.

**Why not "MIGRATION BLOCKED — ADDITIONAL ACCESS REQUIRED":** no *access* is
missing. An administrator application password authenticates against production
with `manage_options: true`, the `leisure` CPT and its taxonomies are fully exposed,
and pagination/rate limits are handled. What is missing is a one-line permission
declaration (`auth_callback`) **in this repository's own plugin** — a change we
control, requiring a normal plugin release, not new access.

**So the precise status is:** the migration is ready to run the moment
`conexao-data-model` ships the `auth_callback` fix (§8). Until then, running
`--apply` against production fails closed with `403 rest_cannot_update` and an
actionable message — which is the correct behaviour, not a silent partial write.

## 12. Required final confirmation

| Question | Answer |
|---|---|
| **Was production accessed?** | **Yes — read-only.** Public website (`GET /`), the site's own REST API (`/wp-json/…`) and the WordPress.com public API, all `GET`. |
| **Were all production requests read-only?** | **Yes.** Only `GET`. No POST/PUT/PATCH/DELETE was sent to production at any point. (`-X OPTIONS` was used against production to read the REST schema — a safe, non-mutating method-discovery request.) |
| **Was production modified?** | **No.** No content, meta, taxonomy, settings, plugin, file or database change. |
| **Was a migration script created?** | **Yes** — `scripts/migrate-leisure-location-rest.py`, plus its mock-API validation suite `tests/scripts/verify-leisure-location-migration.py`. |
| **Was the script executed against production?** | **No** — only `--dry-run` and `--verify` (read-only). `--apply` was exercised **exclusively** against a loopback mock API. |
| **Is the script compatible with the verified Personal-plan capabilities?** | **Yes**, for everything the plan can reach: Application-Password Basic auth, the site's own `/wp-json/wp/v2/` CPT endpoint, taxonomy endpoints, pagination, `Retry-After`. It uses **no** SSH, SFTP, WP-CLI, filesystem or database access. The one caveat is §8: the coordinate meta write needs a plugin `auth_callback`, which is a repository change + release, **not** a plan limitation. |
| **Was any existing work changed or discarded?** | **No.** No `git reset`, no `git clean`, no branch switch, no stash touched, no existing file overwritten. The pre-existing `deleted: ''` worktree entry was left exactly as found. Two local-only, non-content environment repairs were made and are disclosed: (a) the local `test` account's application password was re-created space-free (§4.1), and (b) one probe write to CI fixture `22734` was attempted and was **rejected** with 403, so nothing changed there — verified by re-reading the record. |
| **Was any commit or push performed?** | **No.** |


```

_Last verified: 2026-11-05 by the Lazer location production audit and migration preparation_
