# Plan — Research and populate coordinates for every `/lazer` attraction

| | |
|---|---|
| **Task title** | Populate HIGH-confidence visitor coordinates for every published `leisure` attraction |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Repository baseline (starting SHA)** | `80340da8eb3ea9b96f37d6bf3b264bce28fc04c3` |
| **Applicable skill(s)** | `wp-content-change`, `wp-repository`, `wp-update-docs`, `wp-testing` |
| **Plan status** | draft |

## 1. Scope

Inventory every published `leisure` record shown by `/lazer`, research the
visitor location of each physical attraction against two independent sources,
produce a reviewable manifest, and populate ONLY the HIGH-confidence
coordinates into the local development database through the repository's
six-step content-change contract. Deliver a report, a CSV/JSON manifest and a
separate owner-input list for everything that could not be proven.

## 2. Explicit non-goals

- No production write, deploy, upload, activation or migration.
- No change to the coordinate data model (`_leisure_lat` / `_leisure_lng`
  already exist and are registered by `conexao-data-model`).
- No new REST endpoint, no new admin screen, no theme change.
- No change to any field other than the four coordinate meta keys.
- No touch of the Flutter/mobile repository.
- No `_leisure_coord_verified = 1`: a two-source machine research result is not
  an editor's on-map confirmation, and claiming it would be false.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md` (§0, §5.2, §8, §9),
`docs/content-model.md` ("Leisure coordinates (Lazer location services)"),
`docs/testing.md`, `scripts/README.md`, `docs/templates/plan.md`,
`docs/templates/report.md`, `docs/plans/2026-11-01-lazer-location-services.md`,
`docs/reports/2026-11-02-lazer-location-services.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-content-change` |
| **Its preconditions** | A written plan (this file) with the content/data impact section answered, including the portable-identifier strategy |
| **Its guardrails that bind this task** | Inventory first; versioned manifest keyed on a portable identifier; dry-run before apply; snapshot before write; idempotent apply; numeric gate; never key on a local post ID; never write on a GET; no production write |
| **Its verification commands** | `apply-leisure-coordinates.php --dry-run` / `--apply` / `--verify`, `python3 scripts/verify-permanent-gates.py`, `./scripts/run-tests.sh`, `./scripts/lint.sh` |
| **Its evidence location** | `docs/evidence/2026-11-03-leisure-coordinate-research/` |

## 4. Current-state findings

Verified against the local Compose stack (`wordpress` container, `db` container):

- `leisure` is a published CPT; the archive `/lazer/` renders every published
  `leisure` post. The location-services endpoint reports `count = 293` for `pt`.
- The local database holds **294 published `leisure` posts**: 293 PT + 1 EN.
  The EN record is the Polylang sibling of post 10150 (`phoenix-park`).
- 4 published posts are CI test fixtures (`[PH3C TEST] Lazer …`), not
  attractions. 3 curated records carry a fixture-modified `(sintético)` title
  (`phoenix-park`, `cliffs-of-moher`, `fota-wildlife-park`) but ARE real
  attractions owned by `seed-leisure-locations.php`.
- Coordinate meta exists and is registered
  (`Conexao_Data_Model_Meta::LEISURE_LAT_KEY` = `_leisure_lat`,
  `LEISURE_LNG_KEY` = `_leisure_lng`, plus `_leisure_coord_source`,
  `_leisure_coord_verified`). The sanitizer is the single write-path validator.
- **Zero** records currently store a coordinate: `_leisure_lat` and
  `_leisure_lng` have no rows. So there is nothing to preserve and nothing to
  overwrite — the "do not silently replace" rule is a safety rail, not a
  conflict to resolve.
- 290 of 294 records carry `_leisure_export_uuid` — the portable identifier.
- No existing script or importer writes coordinates; the Lazer importer
  (`conexao-leisure-migration`) does not know the coordinate keys.

## 5. Proposed approach

1. **Inventory (read-only).** A SQL projection of every published `leisure`
   record: id, slug, title, language, county, town, category, address, official
   website, Discover Ireland URL, existing coordinates, portable UUID.
2. **Research.** Two independent, citable sources per attraction:
   - Wikipedia (en) article coordinate, resolved in a few batched
     `prop=coordinates` calls over title variants, with a per-attraction
     `generator=search` fallback for the remainder;
   - OpenStreetMap Nominatim, one request per attraction at the documented
     1 request/second rate.
   Both sources must resolve the SAME named attraction and agree within 250 m.
   A feature that is inherently areal or linear (island, peninsula, mountain
   range, scenic route, national park, beach, greenway) can never be HIGH — no
   single point can be proven to be its visitor access point.
3. **Manifest.** A portable JSON manifest keyed on `_leisure_export_uuid`
   (slug/title as reported fallbacks) plus a CSV projection for review.
4. **Apply.** A new script `scripts/apply-leisure-coordinates.php` following the
   shared bootstrap contract: dry-run by default, `--apply` to write, `--verify`
   read-only inventory, `--manifest=<path>`. It writes only `POPULATE` + `HIGH`
   entries, only the four coordinate meta keys, and refuses to replace an
   existing differing value.

**Rejected options.** (a) Geocoding the postal address in code — explicitly
forbidden by the content model. (b) A Google Maps API key — no secrets in the
repo, no new external dependency. (c) Extending the Lazer importer ZIP format —
heavier than the task needs and would mix two datasets. (d) Writing a second
importer — the standard forbids a parallel mechanism.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/apply-leisure-coordinates.php` | new | the apply/verify tool |
| `scripts/README.md` | modified | catalogue the new script (script-contract gate) |
| `scripts/data/leisure-coordinates-2026-11-03.json` | new | the portable manifest the tool consumes |
| `docs/reports/2026-11-03-leisure-coordinate-research.md` | new | the report |
| `docs/reports/2026-11-03-leisure-coordinate-research.csv` | new | the full manifest, CSV |
| `docs/reports/2026-11-03-leisure-coordinate-owner-input.md` | new | the owner-input list |
| `docs/reports/README.md`, `docs/README.md` | modified | index the new documents |
| `docs/evidence/2026-11-03-leisure-coordinate-research/` | new | research tooling, inventory, dry-run, snapshot, apply output |

## 7. Files explicitly expected NOT to change

`wp-content/**` (no plugin, theme, template, CSS or JS change),
`plugins.json`, `compose.yaml`, `.github/**`, `tests/**` (no test is weakened),
`wp-content/plugins/conexao-data-model/includes/class-meta.php` (the model is
correct and unchanged), every other post type's data.

## 8. Content / data impact

- Records updated: only published `leisure` records matched by
  `_leisure_export_uuid` and carrying a HIGH-confidence manifest entry.
- Records created / deleted: **none**.
- Portable identifier strategy: `_leisure_export_uuid` → slug → title
  (reported per-strategy counts). Local post IDs are never used as identity.
- Six-step contract applies: inventory, manifest, dry-run, snapshot, idempotent
  apply, verify + numeric gate.
- PT content changed? Titles, slugs, content, excerpts, taxonomies and all
  other meta are untouched. Only the four coordinate keys are written, and only
  on the PT master record (the EN sibling is never written).

## 9. Route / HTTP impact

None. No route, redirect, canonical, hreflang or sitemap change. The archive
and single pages render the same markup; only the optional distance label can
now appear for attractions that previously had no coordinate.

## 10. Polylang / English impact

- Translated post types affected: `leisure` (PT canonical, EN is a field-level
  `_leisure_excerpt_en` on the same record, except the single fixture pair
  10150 ↔ 115357).
- PT immutable: **yes** — only coordinate meta is added.
- Canonical / hreflang / language switcher: unchanged.
- Language-scoped cache keys: unchanged; no cache key is derived from
  coordinates (the proximity endpoint computes them per request from the
  language-scoped archive query).
- Completeness gate: not applicable (no EN content change).

## 11. Security impact

- Capabilities / nonces: none added (a CLI script).
- Sanitisation: values pass through `Conexao_Data_Model_Meta::sanitize_latitude()`
  and `sanitize_longitude()` — the registered `sanitize_callback` that runs on
  every write path.
- SQL: `$wpdb->prepare()` for the UUID and title lookups; WP APIs elsewhere.
- REST surface: unchanged.
- Secrets: none.

## 12. Performance impact

None. The apply script runs offline; no query is added to a page request.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody — the manifest is prepared for a later, separately authorised production content change through the existing admin/import path |
| What is the verification of the production result? | out of scope; not performed |

## 14. Test plan

- The new script is exercised in `--dry-run`, `--apply` and `--verify` against
  the local WordPress; the dry-run must write nothing and the apply must be
  idempotent (second run reports zero changes).
- `./scripts/run-tests.sh` (all layers) and `./scripts/lint.sh`.
- No test is weakened; no permanent gate is bypassed.

## 15. Acceptance matrix plan

No new HTTP acceptance row: the change adds data, not behaviour, and the
`/lazer` surface is already covered by the existing theme suite
(`test-leisure-location-services.php`).

## 16. Rollback plan

Before applying, snapshot every `leisure` record's coordinate meta (which is
empty) and the full id list to
`docs/evidence/2026-11-03-leisure-coordinate-research/`. Rollback = delete the
four coordinate meta keys from the affected records (they were absent before),
or restore the snapshot. Rollback does **not** cover anything outside the
coordinate keys, and does not revert a manifest that was never applied.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-11-03-leisure-coordinate-research.md` | new report | — |
| `docs/reports/2026-11-03-leisure-coordinate-owner-input.md` | new | — |
| `docs/reports/2026-11-03-leisure-coordinate-research.csv` | new manifest | — |
| `docs/reports/README.md` | index the three new documents | — |
| `docs/README.md` | index the new report | — |
| `scripts/README.md` | catalogue `apply-leisure-coordinates.php` | — |

`docs/content-model.md` already documents the coordinate contract accurately;
it needs no change (the storage model is unchanged).

## 18. Release implications

No component version bump: no plugin or theme file changes, so no artifact is
affected. No release in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A wrong location is populated as HIGH | low | two independent sources must agree within 250 m AND the feature must be a discrete POI; areal features are excluded from HIGH |
| A local post ID leaks into the manifest as identity | low | the tool matches on `_leisure_export_uuid` first and reports the strategy counts |
| Scope creep into the data model or the theme | medium | §7 names the files that must not change; the plan is the guard |
| Rate-limited research APIs degrade coverage | high | retries with backoff; anything not proven HIGH goes to the owner-input list rather than being guessed |

## 20. Verification gates

1. `docker compose exec -T wordpress php /var/www/html/scripts/apply-leisure-coordinates.php --manifest=… --dry-run` — plan printed, **zero writes**.
2. The same with `--apply`; the numeric gate `gate = with_after − (with_before + written)` must be `0`.
3. A second `--apply` must report `written=0` (idempotence).
4. `--verify` reports the coordinate inventory.
5. `./scripts/run-tests.sh` and `./scripts/lint.sh`.

## 21. Completion criteria

Every published attraction is either populated with a HIGH-confidence
coordinate or explicitly listed in the owner-input file; the manifest traces
every proposed coordinate to two sources; the dry-run and apply behave as
documented; PT content and translation relationships are unchanged; no test or
permanent gate is weakened; the report carries real numbers.

_Last verified: 2026-11-03 by the Lazer coordinate research task_
