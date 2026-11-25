# Remove City Filter from Events Listing — UX Experiment

**Date:** 2026-11-25
**Status:** Implemented (local only, not deployed)
**Type:** UX Experiment

## Summary

Removed the visible **City (Cidade)** filter from the events listing page (`/eventos/`) as an intentional UX experiment. The underlying city/town taxonomy and query handling remain intact for backward compatibility.

## Reason for Change

Events are primarily organized and discovered by county. However, some events do not have a city or town associated with them. This makes the City filter confusing and potentially misleading for visitors:

- Visitors may select a city and not find an event that actually takes place in the county they are interested in simply because the event record does not contain a city value.
- The intended user experience is: visitors browse events → use the County filter to narrow down → open an event to see its complete location details → decide whether the event location is suitable.

## Changes Made

### 1. Template: `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php`

- Removed the City filter dropdown from the desktop toolbar
- Removed the City filter search input
- Removed the City filter options list
- Removed the City filter from the active filter chips
- Removed the City filter section from the mobile bottom sheet
- Added explanatory comments documenting the UX experiment

### 2. URL Builder: `wp-content/themes/conexao-br-irlanda/inc/queries.php`

- Updated `conexao_event_filter_url()` documentation to note that the `cidade` parameter is preserved for backward compatibility but the UI no longer generates new cidade filter URLs

### 3. Query Layer: `wp-content/themes/conexao-br-irlanda/inc/archive-query.php`

- Added documentation comment explaining that the `?cidade=` query parameter handling is preserved for backward compatibility with existing bookmarked URLs and event detail pages

## What Was Preserved

- **County filter** — fully functional with all options, search, selection, URL parameters, filtering logic, and reset behavior
- **Category filter** — fully functional
- **Date filters** — fully functional
- **Search functionality** — fully functional
- **Event location data** — all city metadata, town metadata, county metadata, event addresses, location descriptions, event location taxonomies, imported location information, and location-related database records remain intact
- **Backward compatibility** — existing direct URLs using `?cidade=` parameter continue to work (e.g., `/eventos/?cidade=dublin`)
- **Event detail pages** — still display all available location information including city/town when available

## What Was Removed from Visible UI

- City filter button
- City dropdown panel
- City search input
- City options list
- City active filter chip
- City filter from mobile bottom sheet

## Testing

### Verified Working

- ✅ Events listing loads
- ✅ City filter is NOT visible in the UI
- ✅ County filter is visible and works
- ✅ Category filter works
- ✅ Search works
- ✅ Events without a city remain listed
- ✅ Events with a city remain listed
- ✅ Event detail displays available location information
- ✅ Existing bookmarked `?cidade=` URLs remain functional
- ✅ Mobile layout works correctly
- ✅ No JavaScript errors in browser console
- ✅ Both Portuguese and English event listings work

### Test Results

The City filter is completely removed from the visible interface while all other filters remain functional. The County filter continues to work as the primary geographical filter.

## Reversibility

This change is fully reversible. To reintroduce the City filter:

1. Remove the `$has_town = false;` line in `event-filters.php`
2. Uncomment the City filter sections in the template
3. Remove the explanatory comments

The underlying implementation (taxonomy, query handling, URL builder) was never removed — only the visible UI was hidden.

## Related Files

- `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php`
- `wp-content/themes/conexao-br-irlanda/inc/queries.php`
- `wp-content/themes/conexao-br-irlanda/inc/archive-query.php`

## Next Steps

Monitor user behavior and event discovery metrics during the experiment period. If the location data becomes more consistent in the future, the City filter can be reintroduced by reversing the template changes.