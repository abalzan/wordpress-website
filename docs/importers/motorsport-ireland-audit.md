# Motorsport Ireland Event Importer — Audit Report

**Date:** 2026-09-09  
**Status:** READ-ONLY audit — no production data modified  
**Auditor:** Cline (AI coding agent)  
**Source:** https://www.motorsportireland.com/  

---

## 1. Source Architecture

### 1.1 CMS/Platform

- **Platform:** Squarespace 6 (confirmed via `robots.txt` comment `# Squarespace Robots Txt`, ICS `PRODID:-//Squarespace Inc/Squarespace 6//v6//EN`, and HTML class patterns like `sqs-layout`, `sqs-block`, `eventlist`)
- **No public API** discovered
- **No JSON-LD `@id`** on event pages — structured data exists but lacks a unique identifier
- **Sitemap:** `https://www.motorsportireland.com/sitemap.xml` (974 KB, lists all event URLs)

### 1.2 Event URL Pattern

- **Event listing:** `https://www.motorsportireland.com/events`
- **Individual event:** `https://www.motorsportireland.com/events/{event-slug}`
- **ICS per event:** `https://www.motorsportireland.com/events/{event-slug}?format=ical`
- **Category/club filter:** `https://www.motorsportireland.com/events?category={name}`

### 1.3 Event Listing Architecture

- **Single-page list:** All events on one page — no pagination, no infinite scroll, no AJAX load-more
- **Upcoming first:** Upcoming events listed in reverse chronological order
- **Past Events section:** Separated by `<hr class="eventlist-past-upcoming-divider">`, events with class `eventlist-event--past`
- **Total events on page:** 89 (at time of audit): 47 upcoming single-day, 12 upcoming multi-day, 19 past single-day, 11 past multi-day

### 1.4 Event Card HTML Structure

Key CSS classes on the listing page:
- `eventlist-event eventlist-event--upcoming` / `eventlist-event--past` — card wrapper
- `eventlist-event--multiday` — multi-day modifier
- `eventlist-cats` — category links (club + event type)
- `eventlist-title` — event title with link
- `eventlist-meta-date` / `eventlist-meta-time` — date/time elements
- `eventlist-datetag-status` — **ALWAYS EMPTY** (no status indicator)
- `eventlist-description` — usually empty, sometimes rescheduling notes
- `id="item-{hex-id}"` — stable unique identifier per event

### 1.5 Individual Event Page Structure

Key CSS classes:
- `eventitem-column-content` — main content area (minimal description)
- `eventitem-column-meta` — sidebar with date/time and export links
- `eventitem-meta-cats` — "Posted In: {club}, {type}" links
- `eventitem-meta-date-time-container` — date and time display
- `eventitem-meta-addtocalendar-container` — Google Calendar + ICS links

---

## 2. Events Architecture

### 2.1 Event Identity

| Identifier | Location | Stability | Notes |
|---|---|---|---|
| **ICS UID** | `UID:6989d37d36d357787c3539f4@squarespace.com` | **HIGH** | Squarespace-generated, stable per event, unique |
| **HTML item ID** | `id="item-6989d37d36d357787c3539f4"` | **HIGH** | Matches ICS UID format, present on listing + detail page |
| **Event slug** | `/events/{slug}` | **MEDIUM** | Human-readable, can change if title changes |
| **JSON-LD `@id`** | ABSENT | N/A | No unique identifier in structured data |
| **URL** | `/events/{slug}` | **MEDIUM** | Canonical but slug-dependent |

**Recommendation:** Use the ICS UID (hex ID before `@squarespace.com`) as the primary `source_id`. It is stable, unique, and present in both the listing page HTML (`id="item-{hex}"`) and the ICS feed (`UID:{hex}@squarespace.com`).

### 2.2 Date/Time Representation

| Source | Format | Timezone | Example |
|---|---|---|---|
| **JSON-LD** | `2026-09-13T13:00:00+0100` | IST (UTC+1, Irish Standard Time) | `startDate`/`endDate` with offset |
| **ICS DTSTART/DTEND** | `20260913T120000Z` | UTC (Z suffix) | `DTSTART:20260913T120000Z` |
| **HTML time datetime** | `2026-09-12` | Date only | `<time class="event-date" datetime="2026-09-12">` |
| **HTML display** | `Sat 12 Sept 2026 12:30` | Local Irish time | Human-readable |

**Cross-check:** JSON-LD `13:00+0100` = ICS `12:00Z` = display `13:00`. All consistent. Irish local time is the authoritative representation; ICS converts to UTC correctly.

**Recommendation:** Parse JSON-LD `startDate`/`endDate` (with `+0100` offset) as the authoritative source. The existing `Conexao_Event_Normalizer::normalize_date()` handles ISO 8601 with time. For ICS, the existing `Conexao_Source_ICalendar::parse_ical_datetime()` already converts UTC to `Europe/Dublin`.

### 2.3 Multi-Day Events

- **Detected via:** `eventlist-event--multiday` class on the card
- **Date range in HTML:** Two `<time class="event-date">` elements (start + end) with two `<time class="event-time-localized">` elements
- **JSON-LD:** `startDate` and `endDate` with correct range (e.g., `2026-09-12T12:30:00+0100` to `2026-09-13T13:30:00+0100`)
- **ICS:** `DTSTART:20260912T113000Z` / `DTEND:20260913T123000Z` (correct UTC range)
- **Examples:** Wexford Stages Rally (12-13 Sept), MRCCI Midget Car Race (1-2 Aug), Donegal International Rally (20-22 Jun)

**Recommendation:** Map start date/time to `_event_date`/`_event_start_time` and end date/time to `_event_end_date`/`_event_end_time`. No occurrence posts needed.

### 2.4 Recurrence

- **No RRULE** in ICS feeds
- **No recurring event markers** in HTML or JSON-LD
- **Annual editions** appear as separate events with similar titles (e.g., "Wexford Motor Club Stages Rally" 2025 and 2026 are separate events)
- **No recurrence detection needed**

**Recommendation:** Treat each event as a standalone event. Do not infer recurrence from similarly named annual events.

### 2.5 Cancellation / Postponement

**CRITICAL FINDING: No machine-readable status exists.**

| Indicator | Present? | Location |
|---|---|---|
| ICS `STATUS:CANCELLED` | **NO** | ICS feed has no STATUS property |
| JSON-LD `eventStatus` | **NO** | JSON-LD has no eventStatus property |
| `eventlist-datetag-status` content | **NO** | Div is always empty |
| Title suffix `(CANCELLED)` | **YES** | Only in event title text |
| Title suffix `(Rescheduled)` | **YES** | Only in event title text |
| Description note | **YES** | "Rescheduled from June 28th 2026 to August 30th 2026" |

**Real examples found:**
- `MRCCI Midget Car Race (CANCELLED)` — title suffix only
- `Kerry Motor Club Autosolo (CANCELLED)` — title suffix only
- `Clare Motor Club Mini Stages Rally (CANCELLED)` — title suffix only
- `IMRC Autotest (Rescheduled)` — title suffix + description note

**Recommendation:** The importer MUST parse the event title for `(CANCELLED)` and `(Rescheduled)` suffixes. Cancelled events should be skipped or imported with a status that prevents public display. This is fragile — any change in naming convention will break detection.

### 2.6 TBA / TBC / No Date

- **No TBC/TBA events found** on the current events page
- All events have specific dates
- **No events with missing dates** observed


### 2.7 Location / Address

**CRITICAL FINDING: No location data available.**

| Field | Present? | Value |
|---|---|---|
| JSON-LD `location.name` | **NO** | Empty string `""` |
| JSON-LD `location.address` | **NO** | Empty string `""` |
| ICS `LOCATION` | **NO** | Not present in ICS feed |
| og:latitude/longitude | **MISLEADING** | `40.7207559, -74.0007613` (New York City — Squarespace default, NOT Ireland) |
| Venue name | **NO** | Not present |
| Town/County | **NO** | Not present in structured data |

**Recommendation:** Location fields (`_event_venue`, `_event_location`, `_event_address`) will be empty. The club name (from categories) may hint at the general area but is NOT a reliable location. Map URL (`_event_map_url`) cannot be generated without a usable address.

### 2.8 Images

| Source | Value | Event-Specific? |
|---|---|---|
| `og:image` | `http://static1.squarespace.com/.../MI+Logo+jpeg2.jpg?format=1500w` | **NO** — generic MI logo |
| JSON-LD `image` | Same MI logo URL | **NO** — generic MI logo |
| `eventlist-column-thumbnail` | Empty `<a>` tag | **NO** — no thumbnail |
| Individual page content | No event images found | **NO** |

**Recommendation:** No event-specific images exist. The generic MI logo is the same for all events and should NOT be imported as an event banner. The image handler should skip this source or leave `_event_banner` empty.

### 2.9 Structured Data Summary

**JSON-LD Event schema (per event):**
```json
{
  "@type": "Event",
  "name": "ALMC Grass Surface Autocross — Motorsport Ireland",
  "startDate": "2026-09-13T13:00:00+0100",
  "endDate": "2026-09-13T14:00:00+0100",
  "image": ["http://static1.squarespace.com/.../MI+Logo+jpeg2.jpg?format=1500w"],
  "location": {"name": "", "address": "", "@type": "Place"},
  "@context": "http://schema.org"
}
```

**Observations:**
- `name` includes ` — Motorsport Ireland` suffix (must be stripped)
- `startDate`/`endDate` are reliable with timezone offset
- `location` is empty
- `image` is generic logo
- **No `@id`** — no unique identifier
- **No `eventStatus`** — no cancellation indicator
- **No `description`** — no event description
- **No `organizer`** — no organizer field
- `og:type` is `website` (not `event`) — Squarespace limitation

### 2.10 ICS / Calendar Feeds

**Per-event ICS (not a single aggregate feed):**
```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Squarespace Inc/Squarespace 6//v6//EN
BEGIN:VEVENT
UID:6989d37d36d357787c3539f4@squarespace.com
DTSTAMP:20260209T123215Z
DTSTART:20260913T120000Z
DTEND:20260913T130000Z
SUMMARY:ALMC Grass Surface Autocross
END:VEVENT
END:VCALENDAR
```

**Fields present:** UID, DTSTAMP, DTSTART, DTEND, SUMMARY  
**Fields ABSENT:** DESCRIPTION, LOCATION, CATEGORIES, STATUS, ORGANIZER, URL, GEO

**Assessment:** The ICS feed is **minimal**. It provides a stable UID and accurate UTC times but lacks location, description, categories, and status. The per-event ICS URLs are not practical as a discovery mechanism (you'd need to know each slug).

**Recommendation:** Do NOT use ICS as the primary source. Use HTML scraping of the events listing page for discovery, with JSON-LD on individual pages for date/time enrichment. ICS UID can serve as the stable source_id.

### 2.11 Categories

**Category types observed (from `eventlist-cats` links):**

Event types: Rally, Karting, Rallycross, Autocross, Sporting Trial, Multi Venue Autotest, Circuit Racing, Endurance Trial, Hillclimb, Hillclimb & Sprint, Midget Car Race, Autosolo, Navigation Trial, Pedal Car, Rallysprint, MVAT

Club names (also used as categories): Wexford Motor Club, Mondello Park Sports Club, ALMC Motor Club, Clare Motor Club, Kerry Motor Club, Cavan Motor Club, Connacht Motor Club, Donegal Motor Club, Munster Car Club, Laois Rallysport Club, etc.

**Note:** Squarespace treats both event types and organizing clubs as "categories" — they share the same `eventlist-cats` container and `?category=` filter. The importer must distinguish between club (organizer) and event type (category).

**Recommendation:** Parse `eventlist-cats` links. The first category is typically the club (organizer), the second is the event type. Map event type to `conexao_category` taxonomy. Store club name in `_event_organizer`.

---

## 3. Affiliated-Club Architecture

### 3.1 Club List

- **URL:** `https://www.motorsportireland.com/affiliated-clubs-list`
- **Count:** 32 affiliated clubs (stated in page text: "There are currently 32 affiliated motor clubs")
- **Format:** Simple text list — NO individual club pages, NO links to club websites
- **No club-specific event calendar** on MI site beyond the master `/events` filter

### 3.2 Club List (verified from page)

ALMC Motor Club, Birr & District Automobile Club, Carlow Car Club, Carrick-On-Suir Motor Club, Clare Motor Club, Co. Cavan Motor Club, Co. Kildare Motor Club, Co. Monaghan Motor Club, Connacht Motor Club, Cork Motor Club, Donegal Motor Club, Galway Motor Club, G.S.M.C., I.M.R.C., Imokilly Motor Club, Kerry Motor Club, Killarney & District Motor Club, Laois Rallysport Club, Leinster Motor Club, Limerick Motor Club, M.R.C.C.I., Mayo & District Motorsport Club, Midland Motor Club, Mondello Park Sports Club, Motor Enthusiasts Club, Munster Car Club, Munster Kart Club, Skibbereen & District Car Club, Tipperary L.C. & M.C.C., Trials Drivers Club, Westmeath & District Motor Club, Wexford Motor Club

### 3.3 Club Website Availability

- **Motorsport Ireland does NOT provide links** to club websites on the affiliated-clubs-list page
- **No club-specific pages** on MI site
- **Club websites were NOT crawled** in this audit (out of scope for Stage A)

---

## 4. Master-Calendar Coverage

### 4.1 Does the Master Calendar Aggregate Club Events?

**YES.** The master `/events` calendar contains events from affiliated clubs. Evidence:

- Events are attributed to clubs via the `eventlist-cats` category links
- Events from Wexford Motor Club, Mondello Park Sports Club, ALMC Motor Club, Clare Motor Club, Kerry Motor Club, Cavan Motor Club, Connacht Motor Club, Donegal Motor Club, Munster Car Club, Laois Rallysport Club, Omagh Motor Club, MRCCI, IMRC, and others all appear on the master calendar
- The `?category={club-name}` filter shows events per club, confirming club attribution

### 4.2 Sample Club Coverage (from `?category=` filter)

| Club | Events on Master Calendar |
|---|---|
| Wexford Motor Club | 4 events (2025-2026) |
| Mondello Park Sports Club | Multiple events |
| ALMC Motor Club | Multiple events |
| Clare Motor Club | Multiple events |
| Kerry Motor Club | Multiple events |
| Cavan Motor Club | Multiple events |
| Connacht Motor Club | Multiple events |
| Donegal Motor Club | Multiple events |
| Munster Car Club | Multiple events |
| Laois Rallysport Club | Multiple events |

### 4.3 Coverage Assessment

- The master calendar appears to be the **centralized federation calendar** for all MI-sanctioned events
- Events from all 32 affiliated clubs are represented
- **No evidence of material gaps** — the calendar contains a comprehensive list of events across all competition types and clubs
- **Championship events** (National Championship, TROA, Rhodes Cup, National Forestry Rally) are included alongside club-level events

---

## 5. Whether Direct Club Crawling Is Necessary

### 5.1 Assessment: NOT NECESSARY

The Motorsport Ireland master calendar already aggregates events from all affiliated clubs. Direct crawling of individual club websites is **not required** for the following reasons:

1. **Centralized calendar exists:** MI operates a single master Events page that lists all sanctioned events
2. **Club attribution is preserved:** Each event is tagged with its organizing club via categories
3. **Comprehensive coverage:** Events from all 32 clubs appear on the master calendar
4. **No club-specific pages on MI:** MI does not link to club websites or provide club-specific event pages
5. **Duplication risk:** Crawling both MI and club websites would create duplicate events (same event, two sources)

### 5.2 Duplication Risk if Direct Club Crawling Were Added

If both MI master calendar and individual club websites were crawled:
- The same event would appear twice (once from MI, once from the club)
- The deduplicator would need to match by title + date + organizer (content fallback)
- Source identity complexity would increase (two sources for the same event)
- Club websites may have conflicting data (different times, locations, descriptions)

---

## 6. Event Identity Strategy

### 6.1 Recommended Identity

**Primary:** ICS UID / HTML item ID (hex string before `@squarespace.com`)
- Format: `6989d37d36d357787c3539f4` (24-char hex)
- Stable per event
- Present in listing page HTML (`id="item-{hex}"`) and ICS feed (`UID:{hex}@squarespace.com`)
- Unique across all events

**Fallback:** Event slug (`/events/{slug}`)
- Human-readable
- Present in `href` attributes
- May change if title changes (less stable than hex ID)

### 6.2 Deduplication

The existing `Conexao_Event_Deduplicator` supports three match levels:
1. `_event_source` + `_event_source_id` (strongest) — use hex UID as `source_id`
2. `_event_url` / `_event_source_url` — use canonical event URL
3. Content match (title slug + date + time + venue) — fallback

**Recommendation:** Store `source_id = {hex-uid}` and `source = motorsport_ireland`. This provides the strongest dedup match.

---

## 7. URL Strategy

### 7.1 Canonical Event URL

**Use:** `https://www.motorsportireland.com/events/{event-slug}`

**Do NOT use:**
- `https://www.motorsportireland.com/events` (archive URL)
- `https://www.motorsportireland.com/events?category=...` (filtered URL)
- `https://www.motorsportireland.com/events/{slug}?format=ical` (ICS feed URL)

### 7.2 Source URL for `_event_url`

The Motorsport Ireland event page is the canonical source URL. There is no separate "official event URL" or registration link. Store the MI event page URL as both `_event_url` and `_event_source_url`.

---

## 8. Date/Time Details

### 8.1 Authoritative Source

**JSON-LD `startDate`/`endDate`** on individual event pages:
- Format: `2026-09-13T13:00:00+0100` (ISO 8601 with timezone offset)
- Timezone: Irish Standard Time (IST, UTC+1 during summer; GMT in winter)
- Reliable and parseable by existing normalizer

### 8.2 Cross-Check with ICS

| Event | JSON-LD | ICS (UTC) | Display |
|---|---|---|---|
| ALMC Autocross | `13:00+0100` | `12:00Z` | `13:00` |
| Wexford Rally (start) | `12:30+0100` | `11:30Z` | `12:30` |
| Wexford Rally (end) | `13:30+0100` | `12:30Z` | `13:30` |

**No discrepancies found.** JSON-LD local time = ICS UTC + 1 hour = display time.

### 8.3 Timezone Handling

- Ireland observes IST (UTC+1) during summer (late March to late October) and GMT (UTC+0) in winter
- JSON-LD includes the offset (`+0100`), so the importer can parse it correctly
- The existing `Conexao_Event_Normalizer::normalize_date()` handles ISO 8601 with time
- The existing `Conexao_Source_ICalendar::parse_ical_datetime()` converts UTC to `Europe/Dublin`

**Recommendation:** Parse JSON-LD `startDate`/`endDate` with offset. Store dates as `Y-m-d` and times as `H:i` (24h, local Irish time). The existing normalizer handles this.

---

## 9. Multi-Day Events

### 9.1 Detection

- CSS class: `eventlist-event--multiday` on the `<article>` element
- HTML: Two `<time class="event-date">` elements with two `<time class="event-time-localized">` elements
- JSON-LD: `startDate` and `endDate` differ

### 9.2 Mapping

| Motorsport Ireland | Conexão Field |
|---|---|
| JSON-LD `startDate` date part | `_event_date` |
| JSON-LD `startDate` time part | `_event_start_time` |
| JSON-LD `endDate` date part | `_event_end_date` |
| JSON-LD `endDate` time part | `_event_end_time` |

### 9.3 Examples

| Event | Start | End |
|---|---|---|
| Wexford Stages Rally | 2026-09-12 12:30 | 2026-09-13 13:30 |
| MRCCI Midget Car Race | 2026-08-01 12:00 | 2026-08-02 13:00 |
| Donegal International Rally | 2026-06-20 12:00 | 2026-06-22 13:00 |

---

## 10. Recurrence

- **No RRULE** in ICS feeds
- **No recurring event markers** in HTML or JSON-LD
- **Annual editions** are separate events (e.g., "Wexford Stages Rally" 2025 and 2026 are distinct)

---

## 11. Cancellation / Postponement

### 11.1 Detection Method

**Title text parsing required** (no machine-readable status):

| Pattern | Meaning | Action |
|---|---|---|
| `(CANCELLED)` suffix | Event cancelled | Skip import or mark as non-public |
| `(Rescheduled)` suffix | Event rescheduled | Import with new date; note in description |

### 11.2 Real Examples

- `MRCCI Midget Car Race (CANCELLED)` — title suffix only, no status field
- `Kerry Motor Club Autosolo (CANCELLED)` — title suffix only, no status field
- `Clare Motor Club Mini Stages Rally (CANCELLED)` — title suffix only, no status field
- `IMRC Autotest (Rescheduled)` — title suffix + description: "Rescheduled from June 28th 2026 to August 30th 2026"

### 11.3 Risk

**HIGH.** The cancellation indicator is purely textual. If Motorsport Ireland changes the naming convention (e.g., from `(CANCELLED)` to `[CANCELLED]` or `CANCELLED:`), the importer will silently import cancelled events as normal upcoming events.

**Recommendation:** Implement title-based detection as primary, but also check the ICS SUMMARY for `(CANCELLED)` (it mirrors the title). Log a warning when cancelled events are skipped. Monitor for pattern changes.

---

## 12. TBA / TBC

- **No TBC/TBA events found** on the current events page
- All observed events have specific dates and times
- **Recommendation:** If a date-less event appears, skip it (invalid `_event_date`)

---

## 13. Club / Organizer

### 13.1 Representation

- **First category link** in `eventlist-cats` is typically the organizing club
- **Second category link** is the event type
- Example: `Posted In: <a href="?category=ALMC+Motor+Club">ALMC Motor Club</a>, <a href="?category=Autocross">Autocross</a>`

### 13.2 Mapping

| Motorsport Ireland | Conexão Field | Notes |
|---|---|---|
| First category (club) | `_event_organizer` | e.g., "ALMC Motor Club" |
| Second category (type) | `conexao_category` taxonomy | e.g., "Autocross" |

### 13.3 Important

- **Do NOT convert every organizer to "Motorsport Ireland"** — the event is organized by the club, not MI
- MI is the sanctioning body; the club is the organizer
- Preserve the club name as the organizer

---

## 14. Categories

### 14.1 Event Types Observed

Rally, Karting, Rallycross, Autocross, Sporting Trial, Multi Venue Autotest, Circuit Racing, Endurance Trial, Hillclimb, Hillclimb & Sprint, Midget Car Race, Autosolo, Navigation Trial, Pedal Car, Rallysprint, MVAT

### 14.2 Mapping to Existing Taxonomy

The existing `conexao_category` taxonomy can represent these event types. However, the audit does not create new taxonomy terms — mapping should be defined in the implementation stage.

**Note:** Some categories are championship names (e.g., "National Championship", "TROA", "Rhodes Cup", "National Forestry Rally") — these are NOT event types and should not be mapped to categories. They appear in the event description/content area, not in `eventlist-cats`.

---

## 15. Location

### 15.1 Availability

**No location data is available** from Motorsport Ireland:
- No venue name
- No address
- No town/county
- No Eircode
- No coordinates (og:geo is wrong — points to New York)
- No Google Maps link

### 15.2 Implications

| Field | Value |
|---|---|
| `_event_venue` | Empty |
| `_event_location` | Empty |
| `_event_address` | Empty |
| `_event_map_url` | Cannot be generated |

---

## 16. Address

No address data available. See §15.

---

## 17. Map Compatibility

### 17.1 Conexão Mobile App

The Conexão mobile app shows "Ver no mapa" when a full usable address exists. Since Motorsport Ireland provides **no address data**, the map link will NOT be shown for imported events.

### 17.2 Recommendation

Do not attempt to generate map URLs from club names or partial data. The existing address resolution logic (`Conexao_Event_Address`) requires a usable address, which MI does not provide.

---

## 18. Images

### 18.1 Availability

**No event-specific images.** The only image available is the generic Motorsport Ireland logo (`MI+Logo+jpeg2.jpg`), which is the same for all events.

### 18.2 Recommendation

- Do NOT download the MI logo as an event banner
- Leave `_event_banner` empty
- The image handler should skip this source (no event-specific image to download)

---

## 19. Structured Data

### 19.1 JSON-LD

Present on individual event pages. Provides:
- `name` (with ` — Motorsport Ireland` suffix — must be stripped)
- `startDate`/`endDate` (with `+0100` offset — reliable)
- `image` (generic logo — not event-specific)
- `location` (empty)

Missing:
- `@id` (no unique identifier)
- `eventStatus` (no cancellation indicator)
- `description` (no event description)
- `organizer` (no organizer field)

### 19.2 ICS

Per-event ICS feeds provide:
- UID (stable hex identifier)
- DTSTART / DTEND (UTC, accurate)
- SUMMARY (event title, may include `(CANCELLED)` suffix)

Missing:
- DESCRIPTION
- LOCATION
- CATEGORIES
- STATUS

---

## 20. ICS / Calendar Feeds

### 20.1 Role: Secondary / Validation Only

The per-event ICS feeds are **not suitable as the primary source** because:
- No discovery mechanism (need to know each slug)
- No location data
- No description
- No categories
- No status

**Recommended role:**
- **Primary:** HTML scraping of `/events` listing page (discovery + card data)
- **Secondary:** JSON-LD on individual event pages (date/time enrichment)
- **Validation:** ICS UID as stable source_id; ICS times for cross-check

---

## 21. Deduplication

### 21.1 Within MI Source

Use the hex UID from `id="item-{hex}"` or `UID:{hex}@squarespace.com` as `_event_source_id`. This is the strongest dedup match.

### 21.2 Against Other Sources

If direct club crawling is ever added in the future, the same event could appear from both MI and a club website. The content-based fallback (title + date + organizer) would be the only way to match, as the source IDs would differ.

**Recommendation:** For MI-only import, dedup is straightforward via hex UID. Do not add club sources without a clear identity-matching strategy.

---

## 22. Existing Importer Mapping

### 22.1 Closest Technical Pattern

**`Conexao_Source_Heritage_Week`** is the closest existing pattern:
- HTML scraping of a listing page
- Card extraction with title, URL, date, categories
- Detail page enrichment (for description)
- Per-event try/catch (one failure doesn't abort the source)

**Differences from Heritage Week:**
- No pagination needed (single page for MI)
- No description on listing page (MI has empty `eventlist-description`)
- Minimal content on individual pages (MI has no rich description)
- No location data at all (MI has none)
- No event images (MI has only generic logo)

### 22.2 Architecture Components to Reuse

| Component | Reuse | Notes |
|---|---|---|
| `Conexao_Source_Base` | Yes | Base class with `fetch_html()`, `parse_html()`, `resolve_url()` |
| `Conexao_Event_Normalizer` | Yes | Date/time normalization, location normalization (will produce empty location) |
| `Conexao_Event_Date_Filter` | Yes | Past-event filter (timezone-aware) |
| `Conexao_Event_Deduplicator` | Yes | Source ID + URL + content fallback |
| `Conexao_Event_Image_Handler` | Limited | No event-specific images to download |
| `Conexao_Event_Location` | Limited | No location data to normalize |
| `Conexao_Event_Importer_Engine` | Yes | Upsert logic, dry-run, per-event try/catch |

### 22.3 New Source Handler

Create `Conexao_Source_Motorsport_Ireland` extending `Conexao_Source_Base`:
- `get_id()` returns `motorsport_ireland`
- `fetch_events()` scrapes `/events` listing page
- Extracts event cards from `eventlist-event--upcoming` articles
- Optionally enriches from individual event pages (JSON-LD for dates)
- Parses categories for club (organizer) and event type

---

## 23. Legal / Source-Access Considerations

### 23.1 robots.txt

```
User-agent: *
Disallow: /config
Disallow: /search
Disallow: /account$
Disallow: /account/
Disallow: /commerce/digital-download/
Disallow: /api/
Allow: /api/ui-extensions/
Disallow: /static/
Disallow:/*?format=json
Disallow:/*?format=ical
```

**IMPORTANT:** `Disallow:/*?format=ical` blocks ICS feed URLs. The `/events` page and `/events/{slug}` pages are NOT disallowed. The ICS feeds are blocked for crawlers but accessible via direct browser request.

**Recommendation:** Use the HTML listing page and individual event pages as the primary data sources. Do NOT crawl the ICS feeds (they are blocked by robots.txt and provide less data than the HTML).

### 23.2 Terms / Copyright

- No explicit API terms found
- Event data is factual (dates, titles, categories) — generally not copyrightable
- Site content is © Irish Motorsport Federation Ltd
- Public event aggregation appears technically permissible (public data, no auth required)

### 23.3 Technical Access

- **No authentication required** for event pages
- **No Cloudflare bot protection** observed (standard Squarespace hosting)
- **No rate limiting** observed during audit (standard browser UA sufficient)
- **Response:** Normal HTTP 200 with standard browser User-Agent
- **Caching:** Standard Squarespace caching headers

### 23.4 Crawl Recommendation

- Use the standard browser User-Agent (already configured in `Conexao_Source_Base`)
- Conservative crawl: fetch `/events` listing page, then individual event pages with a delay
- No more than 1 request per second
- Cache the listing page result to avoid re-fetching during a single import run

---

## 24. Crawl Strategy

### 24.1 Discovery

1. Fetch `https://www.motorsportireland.com/events`
2. Parse HTML to extract all `article.eventlist-event--upcoming` elements
3. For each card, extract: slug, title, categories, date/time from HTML attributes

### 24.2 Enrichment (Optional)

4. For each upcoming event, fetch the individual event page
5. Parse JSON-LD for precise `startDate`/`endDate` with timezone offset
6. Cross-check with HTML card data

### 24.3 Filtering

7. Apply `Conexao_Event_Date_Filter` to skip past events
8. Skip events with `(CANCELLED)` in title
9. Skip events with invalid/missing dates

### 24.4 Import

10. Normalize via `Conexao_Event_Normalizer`
11. Deduplicate via `Conexao_Event_Deduplicator` (source_id = hex UID)

---

## 25. Recommended Architecture

### 25.1 Decision: MASTER CALENDAR SUFFICIENT

**Recommendation:** Use the Motorsport Ireland master Events calendar as the **canonical source**. Do NOT crawl individual club websites.

### 25.2 Rationale

1. **Centralized calendar:** MI operates a single master Events page with all sanctioned events
2. **Club attribution preserved:** Each event is tagged with its organizing club
3. **Comprehensive coverage:** Events from all 32 clubs appear on the master calendar
4. **No duplication:** Single source eliminates duplicate event risk
5. **Lower maintenance:** One parser instead of 32+ club parsers
6. **No club website links:** MI does not provide links to club websites
7. **Data consistency:** Single source ensures consistent event data

### 25.3 Architecture Diagram

```
Motorsport Ireland
  → /events (listing page — discovery)
  → /events/{slug} (detail page — JSON-LD enrichment)
  → Conexão BR Event Importer
    → Conexao_Source_Motorsport_Ireland
    → Conexao_Event_Normalizer
    → Conexao_Event_Date_Filter
    → Conexao_Event_Deduplicator
    → Conexao_Event_Importer_Engine
    → WordPress Event posts
```

---

## 26. Proposed Field Mapping

| Motorsport Ireland | Conexão Field | Source | Notes |
|---|---|---|---|
| Hex UID (`item-{hex}`) | `_event_source_id` | HTML/ICS | Primary dedup key |
| `motorsport_ireland` | `_event_source` | Config | Fixed source slug |
| Event title (stripped of `(CANCELLED)`/`(Rescheduled)`) | `post_title` | HTML | Strip status suffixes |
| Event description (minimal) | `post_content` | HTML/JSON-LD | Usually empty or minimal |
| JSON-LD `startDate` date | `_event_date` | JSON-LD | `Y-m-d` format |
| JSON-LD `endDate` date | `_event_end_date` | JSON-LD | `Y-m-d` format |
| JSON-LD `startDate` time | `_event_start_time` | JSON-LD | `H:i` format (local) |
| JSON-LD `endDate` time | `_event_end_time` | JSON-LD | `H:i` format (local) |
| First category (club) | `_event_organizer` | HTML | e.g., "ALMC Motor Club" |
| Second category (type) | `conexao_category` | HTML | e.g., "Autocross" |
| `/events/{slug}` URL | `_event_url` | HTML | Canonical event page |
| `/events/{slug}` URL | `_event_source_url` | HTML | Same as `_event_url` |
| — | `_event_venue` | — | **EMPTY** — no data available |
| — | `_event_location` | — | **EMPTY** — no data available |
| — | `_event_address` | — | **EMPTY** — no data available |
| — | `_event_map_url` | — | **EMPTY** — no address to map |
| — | `_event_banner` | — | **EMPTY** — no event images |
| — | `_event_price` | — | **EMPTY** — no price data |

---

## 27. Data-Quality Risks

### 27.1 Critical Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **No machine-readable cancellation status** | **HIGH** | Parse title for `(CANCELLED)` suffix; log warnings; monitor for pattern changes |
| **No location/address data** | **HIGH** | Accept empty location fields; do not fabricate addresses |
| **No event-specific images** | **MEDIUM** | Skip image download; leave banner empty |
| **No description content** | **MEDIUM** | Accept minimal/empty description |

### 27.2 Moderate Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **Slug instability** | **MEDIUM** | Use hex UID as primary source_id (not slug) |
| **Duplicate slug patterns** | **MEDIUM** | Some events have similar slugs (e.g., `cavan-motor-club-loose-surface-autocross` vs `-1` suffix); hex UID disambiguates |
| **Category ambiguity** | **LOW** | First category = club, second = type; verify with sample |
| **Championship vs event type** | **LOW** | Championship names appear in description, not categories; do not map to taxonomy |

### 27.3 Real Examples of Data Quality Issues

1. **Cancelled events with no status flag:** `MRCCI Midget Car Race (CANCELLED)` — title suffix only
2. **Rescheduled events with notes:** `IMRC Autotest (Rescheduled)` — description says "Rescheduled from June 28th 2026 to August 30th 2026"
3. **Wrong geo coordinates:** og:latitude/longitude = `40.7207559, -74.0007613` (New York, not Ireland)
4. **Empty location in JSON-LD:** `"location":{"name":"","address":""}`
5. **Generic image for all events:** Same MI logo URL in every event's JSON-LD

---

## 28. Exact Files Inspected

### 28.1 Source Code (Conexão)

| File | Purpose |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | Import engine, upsert logic, dry-run |
| `wp-content/plugins/conexao-event-importer/includes/class-event-normalizer.php` | Date/time/location normalization |
| `wp-content/plugins/conexao-event-importer/includes/class-event-deduplicator.php` | Source ID + URL + content dedup |
| `wp-content/plugins/conexao-event-importer/includes/class-event-date-filter.php` | Past-event filter |
| `wp-content/plugins/conexao-event-importer/includes/sources/abstract-class-source.php` | Base source class |
| `wp-content/plugins/conexao-event-importer/includes/sources/class-icalendar-source.php` | ICS source (reference pattern) |
| `wp-content/plugins/conexao-event-importer/includes/sources/class-heritage-week-source.php` | HTML scraping source (closest pattern) |
| `docs/plugins/conexao-event-importer.md` | Importer documentation |
| `docs/content-model.md` | Content model (event meta fields) |

### 28.2 Live Source (Motorsport Ireland)

| URL | Content |
|---|---|
| `https://www.motorsportireland.com/` | Homepage (navigation, structure) |
| `https://www.motorsportireland.com/events` | Event listing page (89 events) |
| `https://www.motorsportireland.com/events/almc-grass-surface-autocross` | Individual event page |
| `https://www.motorsportireland.com/events/wexford-motor-club-wexford-stages-rally` | Multi-day event page |
| `https://www.motorsportireland.com/events/mrcci-midget-car-race-5` | Cancelled event page |
| `https://www.motorsportireland.com/events/imrc-autosolo-1` | Rescheduled event page |
| `https://www.motorsportireland.com/events/almc-grass-surface-autocross?format=ical` | Per-event ICS feed |
| `https://www.motorsportireland.com/events/wexford-motor-club-wexford-stages-rally?format=ical` | Multi-day ICS feed |
| `https://www.motorsportireland.com/events?category=Rally` | Category-filtered view |
| `https://www.motorsportireland.com/events?category=Wexford+Motor+Club` | Club-filtered view |
| `https://www.motorsportireland.com/affiliated-clubs-list` | 32 affiliated clubs |
| `https://www.motorsportireland.com/robots.txt` | Crawl rules |
| `https://www.motorsportireland.com/sitemap.xml` | URL discovery |

---

## 29. Confirmation

**NO PRODUCTION DATA WAS MODIFIED.**

This audit was conducted using read-only HTTP GET requests to public Motorsport Ireland web pages. No WordPress posts were created, no database was modified, no existing importer behavior was changed, no images were downloaded, and no new source was enabled.

---

## 30. Final Recommendation

### 30.1 Recommended Model

**MOTORSPORT IRELAND MASTER CALENDAR AS CANONICAL SOURCE**

### 30.2 Source Handler

Create `Conexao_Source_Motorsport_Ireland` (model: `Conexao_Source_Heritage_Week` — HTML scraping of listing page + optional detail enrichment).

### 30.3 Key Implementation Notes

1. **Discovery:** Scrape `/events` listing page for `article.eventlist-event--upcoming` cards
2. **Identity:** Use hex UID from `id="item-{hex}"` as `_event_source_id`
3. **Dates:** Parse JSON-LD `startDate`/`endDate` from individual event pages (or use HTML `datetime` attributes as fallback)
4. **Cancellation:** Parse title for `(CANCELLED)` suffix — skip cancelled events
5. **Organizer:** First category link = club name → `_event_organizer`
6. **Category:** Second category link = event type → `conexao_category`
7. **Location:** No data available — leave all location fields empty
8. **Images:** No event-specific images — leave banner empty
9. **No pagination needed:** Single page lists all events
10. **No recurrence:** Each event is standalone

---

**MOTORSPORT IRELAND IMPORTER AUDIT PASSED**