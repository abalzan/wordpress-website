# Events Copy Broadening Report

## Overview

This report documents the changes made to broaden the Eventos section copy so that it no longer presents the Events section as being exclusively or specifically for the Brazilian community in Ireland.

## Background

The Eventos section originally focused primarily on Brazilian community events. It now contains a broader Ireland-wide event dataset, including events sourced from:
- Eventbrite
- National Heritage Week
- Motorsport Ireland
- Mondello Park
- Other Irish event sources already integrated into the Event Importer

The public-facing wording needed to be updated to reflect this broader scope.

## Copy Audit

### Files Reviewed

| File | Purpose |
|------|---------|
| `wp-content/themes/conexao-br-irlanda/archive.php` | Eventos archive header configuration |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | SEO titles, meta descriptions, OG metadata, archive descriptions |
| `wp-content/themes/conexao-br-irlanda/template-parts/archive-header.php` | Shared archive header template |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php` | Eventos filter widget |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-preview.php` | Homepage event preview |
| `wp-content/themes/conexao-br-irlanda/template-parts/content-none.php` | Empty state copy |

### Brazilian-Specific Wording Identified

The following section-level references to the Brazilian community were identified in the Eventos section:

#### 1. Archive Header (archive.php)

**Location:** `archive.php`, lines 36-38

**Original copy:**
```php
'eyebrow'     => _x( 'Agenda da Comunidade', 'archive eyebrow', 'conexao-br-irlanda' ),
'title'       => _x( 'Eventos', 'archive page title', 'conexao-br-irlanda' ),
'description' => _x( 'Encontre eventos, encontros e atividades da comunidade brasileira na Irlanda.', 'archive description', 'conexao-br-irlanda' ),
```

**Issue:** The eyebrow "Agenda da Comunidade" and description explicitly positioned the entire Events archive as being for the Brazilian community.

---

#### 2. Single Event Meta Description (inc/seo.php)

**Location:** `inc/seo.php`, line 131

**Original copy:**
```php
$description = 'Evento: ' . get_the_title() . '. Participe e fortaleça a comunidade brasileira na Irlanda.';
```

**Issue:** This template-level copy positioned all single event pages as being about strengthening the Brazilian community, regardless of the actual event content.

---

#### 3. Event Archive Meta Description (inc/seo.php)

**Location:** `inc/seo.php`, line 141

**Original copy:**
```php
$description = 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda. Agenda cultural e networking.';
```

**Issue:** The SEO meta description for the `/eventos/` archive page explicitly targeted the Brazilian community.

---

#### 4. Archive Description Fallback (inc/seo.php)

**Location:** `inc/seo.php`, line 648 (in `conexao_archive_description()`)

**Original copy:**
```php
return 'Eventos, encontros e atividades para a comunidade brasileira na Irlanda.';
```

**Issue:** This function provides the description used in `<meta name="description">` and Open Graph metadata when no manual SEO description is set.

---

#### 5. Empty State Copy (content-none.php)

**Location:** `template-parts/content-none.php`, line 33

**Original copy:**
```php
<p><?php esc_html_e( 'Novos eventos da comunidade serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
```

**Issue:** The empty state for the events archive referenced "da comunidade" (of the community), implying Brazilian-community-only events.

---

## What Was NOT Changed

### Preserved Event-Specific Content

The following were intentionally NOT modified as they are event-specific content, not section-level positioning:

- Event titles (e.g., "Festival Brasileiro" remains unchanged)
- Event descriptions imported from external sources
- Venue names
- Organizer names
- Event metadata (`_event_*` meta fields)
- Source data
- Taxonomy terms (categories, tags, counties, towns)
- Importer data and fixtures

### Structural Elements Preserved

The following structural components were NOT modified:

- Event CPT registration
- Event taxonomies (`conexao_town`, `conexao_category`, etc.)
- Event filters (county, cidade, categoria)
- Event Importer plugin
- Event Runtime plugin
- REST API endpoints
- Production event data
- Filter logic and URL structure
- Pagination
- Card presentation

### Other Sections Preserved

Copy for other sections that legitimately reference the Brazilian community was NOT changed:
- Guides section (legitimately serves Brazilian community needs)
- Jobs section (legitimately targets Brazilian job seekers)
- Sponsors section (legitimately showcases businesses supporting the community)
- County pages (legitimately include community-focused descriptions)

## SEO Changes

### Document Title (inc/seo.php)

**NO CHANGE** — The document title for the events archive remains:
```php
'Eventos na Irlanda | ' . $site_name
```

This was already appropriately broad and did not need modification.

### Meta Description (inc/seo.php)

**CHANGED** — Line 141 updated from:
```php
'Eventos, encontros e atividades para a comunidade brasileira na Irlanda. Agenda cultural e networking.'
```
to:
```php
'Eventos, encontros e atividades na Irlanda. Agenda cultural e networking.'
```

### Open Graph Description (inc/seo.php)

**CHANGED** — The OG description uses `conexao_archive_description()`, which was updated to the broader wording. The OG meta tag at line 236 calls this function.

### Canonical URL

**NO CHANGE** — The `/eventos/` slug remains unchanged.

### Schema Markup

**NO CHANGE** — Event schema markup (JSON-LD) on single event pages contains event-specific data (name, date, location, organizer) and does not include section-level positioning descriptions.

---

## Files Modified

| File | Lines Changed | Purpose |
|------|---------------|---------|
| `wp-content/themes/conexao-br-irlanda/archive.php` | 36-38 | Archive header eyebrow and description |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | 131 | Single event meta description |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | 141 | Event archive meta description |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | 648 | Archive description function |
| `wp-content/themes/conexao-br-irlanda/template-parts/content-none.php` | 33 | Empty state copy |

---

## Summary of Changes

### Original Copy → New Copy

| Context | Original | New |
|---------|----------|-----|
| Archive eyebrow | "Agenda da Comunidade" | "Agenda Irlandesa" |
| Archive description (header) | "Encontre eventos, encontros e atividades da comunidade brasileira na Irlanda." | "Encontre eventos, encontros e atividades na Irlanda." |
| Archive description (SEO function) | "Eventos, encontros e atividades para a comunidade brasileira na Irlanda." | "Encontre eventos, encontros e atividades na Irlanda." |
| Archive meta description | "Eventos, encontros e atividades para a comunidade brasileira na Irlanda. Agenda cultural e networking." | "Eventos, encontros e atividades na Irlanda. Agenda cultural e networking." |
| Single event meta description | "Evento: {title}. Participe e fortaleça a comunidade brasileira na Irlanda." | "Evento: {title}. Participe de eventos e atividades na Irlanda." |
| Empty state | "Novos eventos da comunidade serão publicados aqui em breve." | "Novos eventos serão publicados aqui em breve." |

---

## Verification Checklist

### Section-Level Copy

| Check | Status |
|-------|--------|
| Main introductory text is "Encontre eventos, encontros e atividades na Irlanda." | ✅ Changed |
| No nearby section-level copy says Events archive is specifically for Brazilian community | ✅ Verified |
| Eyebrow changed from "Agenda da Comunidade" to "Agenda Irlandesa" | ✅ Changed |

### Event Content Preservation

| Check | Status |
|-------|--------|
| Event titles containing Brazilian references are untouched | ✅ Verified (no changes to event titles) |
| Event descriptions from external sources are untouched | ✅ Verified (no changes to importer data) |
| Venue names are untouched | ✅ Verified |
| Organizer names are untouched | ✅ Verified |
| Event metadata is untouched | ✅ Verified |
| Taxonomy terms are untouched | ✅ Verified |

### Filters and Functionality

| Check | Status |
|-------|--------|
| County filter (`?county=`) unchanged | ✅ Verified (no changes to filter logic) |
| Cidade filter (`?cidade=`) unchanged | ✅ Verified (no changes to filter logic) |
| Category filter (`?categoria=`) unchanged | ✅ Verified (no changes to filter logic) |
| Combined filters unchanged | ✅ Verified |
| Pagination unchanged | ✅ Verified |
| Event detail pages unchanged (except meta description template) | ✅ Verified |

### SEO

| Check | Status |
|-------|--------|
| `<title>` remains "Eventos na Irlanda \| [Site Name]" | ✅ Verified (unchanged) |
| Meta description updated to broader positioning | ✅ Changed |
| Open Graph description updated to broader positioning | ✅ Changed (via archive description) |
| Canonical URL unchanged (`/eventos/`) | ✅ Verified |
| Schema markup unchanged | ✅ Verified |

---

## Testing Performed

### Copy Verification

1. ✅ Confirmed main introductory wording is now "Encontre eventos, encontros e atividades na Irlanda."
2. ✅ Confirmed no nearby section-level copy still says the Events archive is specifically for the Brazilian community
3. ✅ Confirmed event titles/descriptions containing Brazilian references are untouched (verified via codebase search)
4. ✅ Confirmed filters remain unchanged (no changes to filter logic or URLs)
5. ✅ Confirmed pagination remains unchanged
6. ✅ Confirmed Event detail pages are unchanged except for the meta description template

### SEO Verification

7. ✅ Confirmed SEO metadata uses the new broader positioning
8. ✅ Confirmed canonical URLs unchanged
9. ✅ Confirmed schema markup unchanged

### Code Integrity

10. ✅ Verified no changes to Event CPT, taxonomies, filters, importer, or runtime
11. ✅ Verified no changes to production event data
12. ✅ Verified no changes to mobile app

---

## Acceptance Criteria Status

| Criterion | Status |
|-----------|--------|
| Eventos no longer uses Brazilian-community-specific wording to describe the overall Events section | ✅ PASSED |
| Main text is "Encontre eventos, encontros e atividades na Irlanda." | ✅ PASSED |
| Legitimate Brazilian references inside individual Events remain untouched | ✅ PASSED |
| No filters, URLs, data, or importer behavior changed | ✅ PASSED |
| Desktop/mobile rendering remains correct (no layout changes) | ✅ PASSED (copy only, no structural changes) |

---

**EVENTS COPY BROADENING PASSED**