# Missing / unconfirmed leisure coordinates — owner input required (2026-11-03)

Every published `/lazer` attraction whose coordinates could NOT be
established with HIGH confidence. Nothing in this file was populated.

`REVIEW` means a candidate location was found but the precise visitor
access point is not proven (both sources agree but the feature is areal,
or the two sources disagree, or only one source resolved a location).
`OWNER_INPUT_REQUIRED` means no usable location was found at all.

Total rows: **5**.

| # | Attraction | County | Post ID | Existing coordinates | Candidate coordinates | Confidence | Action | Portuguese URL | Sources investigated | Reason | Information needed |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | [PH3C TEST] Lazer sem condado | — | 22737 | None | — | NOT_FOUND | OWNER_INPUT_REQUIRED | `—` | Wikipedia article-title search + OSM Nominatim search (no match) | CI test fixture, not a real attraction: No source resolved a coordinate for this name | GPS coordinates or a Google Maps / OSM pin for the main visitor entrance |
| 2 | Phoenix Park | Dublin | 115357 | None | — | MEDIUM | REVIEW | `/lazer/phoenix-park-en/` | owner: not a separate attraction | owner: not a separate attraction / owner deferred - EN sibling of the Phoenix Park record (10150): it is not a separate attraction and must not get an independent coordinate decision. It shares `_leisure_export_uuid` with its PT master, so the UUID-first match resolves both to 10150; repairing that duplicate identifier is a separate data change | Confirm the preferred visitor access point (which entrance/car park) |
| 3 | [PH3C TEST] Lazer Alfa | Teste Alfa (PH3C) | 22734 | None | — | NOT_FOUND | OWNER_INPUT_REQUIRED | `—` | Wikipedia article-title search + OSM Nominatim search (no match) | CI test fixture, not a real attraction: No source resolved a coordinate for this name | GPS coordinates or a Google Maps / OSM pin for the main visitor entrance |
| 4 | [PH3C TEST] Lazer externo | Teste Alfa (PH3C) | 22739 | None | — | NOT_FOUND | OWNER_INPUT_REQUIRED | `—` | Wikipedia article-title search + OSM Nominatim search (no match) | CI test fixture, not a real attraction: No source resolved a coordinate for this name | GPS coordinates or a Google Maps / OSM pin for the main visitor entrance |
| 5 | [PH3C TEST] Lazer Zeta | Teste Zeta (PH3C) | 22738 | None | — | NOT_FOUND | OWNER_INPUT_REQUIRED | `—` | Wikipedia article-title search + OSM Nominatim search (no match) | CI test fixture, not a real attraction: No source resolved a coordinate for this name | GPS coordinates or a Google Maps / OSM pin for the main visitor entrance |
