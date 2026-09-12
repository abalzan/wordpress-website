# Events Expansion — Stage B Implementation Report

**Scope:** Implementation of multi-county source architecture for Eventbrite
and National Heritage Week, using ONE reusable provider implementation per
provider + ONE independently identifiable source registration per county.

**Target:** 26 Republic of Ireland counties. Northern Ireland OUT OF SCOPE.

**Status date:** 12 September 2026

---

## 1. Production Safety Statement

Stage B remained **READ-ONLY** with respect to Event records.

- **No Event records were created or modified.**
- **No live imports were run.**
- **No production writes occurred.**
- **All 52 new source registrations are \`inactive\`.
- **No export package was built.**
- **Event Runtime was untouched.**

---

## 2. Implementation Summary

### 2.1 Authoritative County Registry

Created \`Conexao_County_Registry\` (\`includes/class-county-registry.php\`) as the
single source of truth for the 26 ROI counties.

### 2.2 Eventbrite Parameterization

Refactored \`Conexao_Source_Eventbrite\`: config-driven ID, county, region labels,
discovery URL. Dead API path removed.

### 2.3 Eventbrite Client Logging

\`Conexao_Eventbrite_Client\` accepts source ID for independent log streams.

### 2.4 Eventbrite Venue Regression Fix

\`primary_venue\` read first, \`venue\` as fallback. Description prefers \`summary\`.

### 2.5 Heritage Week Parameterization

Config-driven county, multiple \`where[]\`, time budget added.

### 2.6 Handler Routing

\`heritage_week\` type-based routing added. All county variants resolve correctly.

### 2.7 Source Registry Seeding

\`seed_county_sources()\` generates 52 inactive sources, idempotent.

---

## 3. Files Changed

| File | Change |
|---|---|
| \`conexao-event-importer.php\` | Version bump 1.6.0 to 1.7.0; include county registry |
| \`includes/class-county-registry.php\` | **NEW** - authoritative 26-county registry |
| \`includes/sources/class-eventbrite-source.php\` | Rewritten - config-driven, dead API removed |
| \`includes/class-eventbrite-client.php\` | Source ID for log attribution |
| \`includes/class-eventbrite-normalizer.php\` | \`primary_venue\` fix, summary preference |
| \`includes/sources/class-heritage-week-source.php\` | Config-driven county, multiple \`where[]\` |
| \`includes/class-event-importer.php\` | \`heritage_week\` type routing |
| \`includes/class-event-sources.php\` | \`seed_county_sources()\`, admin UI |
| \`tests/test-county-registry.php\` | **NEW** - 14 test sections |
| \`tests/probe-county-sources.php\` | **NEW** - live verification probe |
| \`tests/dry-run-county-sources.php\` | **NEW** - dry-run for all 52 sources |

---

## 4. County Registry (26 ROI Counties)

All 26 counties configured with EB slug, EB region labels, and HW where[] values.
Cork: EB regions [Cork, Cork City], HW [cork-county].
Galway: EB regions [Galway, Galway City], HW [galway-county, galway-city].
Dublin: EB regions [Dublin], HW [dublin-city, dublin-dunlaoghaire-rathdown, dublin-fingal, dublin-south].

---

## 5. Handler Routing

| Source ID / Type | Routes To |
|---|---|
| \`type = eventbrite\` (any ID) | \`Conexao_Source_Eventbrite\` |
| \`type = heritage_week\` (any ID) | \`Conexao_Source_Heritage_Week\` |
| \`id = eventbrite\` (legacy) | \`Conexao_Source_Eventbrite\` |
| \`id = heritage_week\` (legacy) | \`Conexao_Source_Heritage_Week\` |

---

## 6. Eventbrite API Removal

Removed: \`fetch_via_api()\`, \`use_api()\`, \`map_api_event()\`,
\`API_SEARCH_URL\`, \`API_LOCATION\`, \`API_RADIUS_KM\`.
HTML discovery is the only supported mechanism.

---

## 7. primary_venue Fix

\`get_venue_name()\` and \`get_address()\` read \`primary_venue\` first,
\`venue\` as fallback. Fixes Stage A venue regression.

---

## 8. Legacy Source Handling

**Approach: Option A** - keep legacy Laois source IDs.
Refactored handlers support both config-driven and legacy IDs via fallback.

---

## 9. Limitations

1. **Eventbrite pagination cap:** page_count capped at 49 (~980 events).
2. **Heritage Week seasonality:** one edition per year.
3. **Heritage Week detail-page cost:** one request per event.
4. **Region-label drift:** re-verifiable per activation.
5. **Datacenter blocking:** imports remain local-only.

---

**FINAL CLASSIFICATION: EVENTS EXPANSION STAGE B PASSED**
