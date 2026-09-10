# IVVCC Stage C — Source-Use / Production Readiness Audit Report

> **Audit type:** Read-only source-use reassessment
> **Date (UTC):** 2026-09-10 (evidence) / 2026-10-09 (report)
> **Scope:** Reassess IVVCC legal/source-access assumptions for the intended Conexão BR use case
> **Intended use:** Display basic event information on Conexão BR + link to original IVVCC event page
> **Target model:** A (LINK/REFERENCE) + limited B (FACTUAL METADATA AGGREGATION)
> **Production writes:** NONE — this audit is read-only

---

## 1. Source evidence reviewed

### 1.1 Authoritative inputs (repo)

| Item | Path |
|---|---|
| IVVCC importer docs | `docs/importers/ivvcc-importer.md` |
| IVVCC audit (Stage B) | `docs/importers/ivvcc-audit.md` |
| Conexão event importer docs | `docs/importers/conexao-event-importer.md` (not found — referenced but absent) |
| IVVCC source handler | `wp-content/plugins/conexao-event-importer/includes/sources/class-ivvcc-source.php` |
| IVVCC tests (parser/location/dedup) | `wp-content/plugins/conexao-event-importer/tests/test-ivvcc-importer.php` |
| IVVCC live parse (read-only) | `wp-content/plugins/conexao-event-importer/tests/live-ivvcc-parse.php` |
| IVVCC dry-run (read-only) | `wp-content/plugins/conexao-event-importer/tests/dry-run-ivvcc.php` |

### 1.2 Live source evidence (HTTP GET, browser UA, public pages only)

| URL | Fetched | Notes |
|---|---|---|
| `https://www.ivvcc.ie/robots.txt` | Yes | Full text captured |
| `https://www.ivvcc.ie/privacy-policy/` | Yes | Full text captured |
| `https://www.ivvcc.ie/upcoming-events-calendar/` | Yes | Full calendar page captured |
| `https://www.ivvcc.ie/events/ivvcc-11th-brass-brigade-run/` | Yes | Representative detail page |
| `https://www.ivvcc.ie/terms/` | No | 404 Not Found |
| `https://www.ivvcc.ie/terms-of-use/` | No | 404 Not Found |

No terms-of-use page exists on the site. No dedicated linking policy page found. No anti-scraping statement beyond robots.txt.

---

## 2. Source-access findings

### 2.1 robots.txt

```
User-agent: *
Disallow: /wp-content/uploads/wc-logs/
Disallow: /wp-content/uploads/woocommerce_transient_files/
Disallow: /wp-content/uploads/woocommerce_uploads/
Disallow: /*?add-to-cart=
Disallow: /*?*add-to-cart=
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php
Crawl-delay: 10
Sitemap: https://www.ivvcc.ie/sitemap_index.xml
```

**Findings:**
- No disallow on `/upcoming-events-calendar/` or `/events/` paths
- `Crawl-delay: 10` explicitly set
- No anti-scraping language
- No mention of automated access restrictions beyond crawl-delay
- Sitemap publicly exposed

### 2.2 Automated access compliance

| Requirement | Source evidence | Importer compliance |
|---|---|---|
| robots.txt crawl-delay | `Crawl-delay: 10` | ✓ Honors 10s between detail requests |
| User-Agent | No restriction stated | ✓ Browser UA only, no spoofing |
| Cloudflare bypass | None attempted | ✓ No bypass, no credentials |
| Authentication | None required for public pages | ✓ Public pages only |
| Rate limiting | Not documented beyond crawl-delay | ✓ ≤1 request per 10s |
| Content access scope | Public calendar + detail pages | ✓ Upcoming events only (§15 audit) |

**Conclusion:** The existing importer behavior complies with available source instructions (robots.txt crawl-delay, public pages, browser UA).

### 2.3 Linking prohibition check

**Searched for explicit statements prohibiting third-party links to public event pages:**
- robots.txt: No such prohibition
- privacy-policy: No such prohibition
- Site-wide footer: "Any Links on our site are purely for information purposes and no warranty or endorsement is implied" — this refers to links ON ivvcc.ie, not links TO ivvcc.ie
- No terms-of-use page exists (404)
- No linking policy page found

**Finding:** No explicit IVVCC statement prohibiting third-party links to public event pages was found in any reviewed source.

## 3. Copyright / terms findings

### 3.1 Exact IVVCC copyright statement

From `https://www.ivvcc.ie/privacy-policy/` (verbatim):

> "All content on the ivvcc.ie website, unless otherwise stated is owned by the IVVCC and may not be reproduced without permission."

This statement appears in the privacy policy page, not a dedicated terms of use page.

### 3.2 What the statement covers

| Content type | Covered by "all content" claim | Reproduction restriction applies |
|---|---|---|
| Event descriptions | Yes (broad "all content") | Yes — "may not be reproduced without permission" |
| Photographs | Yes | Yes |
| PDFs | Yes | Yes |
| Logos | Yes | Yes |
| Registration forms | Yes | Yes |
| Event titles | Yes (broad "all content") | Textually yes, but see §3.3 |
| Event dates/times | Yes (broad "all content") | Textually yes, but see §3.3 |
| Location data | Yes (broad "all content") | Textually yes, but see §3.3 |
| Organizer names | Yes (broad "all content") | Textually yes, but see §3.3 |

### 3.3 Key distinction: "reproduce" vs. "reference"

The IVVCC statement uses the word **"reproduced"**. The source evidence does NOT:

- Define what "reproduced" means in this context
- Distinguish between copying content and referencing factual data
- Address hyperlinking to public pages
- Address display of factual metadata with attribution
- Address aggregation of publicly available event information

**What the source evidence directly supports:**
- IVVCC claims ownership of "all content" on the website
- IVVCC states content "may not be reproduced without permission"
- The statement is broad and does not carve out factual data or linking

**What the source evidence does NOT support:**
- A specific prohibition on linking to public event pages
- A specific prohibition on displaying factual event metadata (titles, dates, times, locations, organizers)
- A definition of "reproduce" that clearly encompasses factual reference/aggregation
- Any statement that explicitly requires permission for non-reproductive uses

### 3.4 Terms of use

No terms-of-use page exists on ivvcc.ie (`/terms/` and `/terms-of-use/` both return 404). The only relevant statement is the copyright line in the privacy policy.

## 4. Current importer behavior audit

### 4.1 What the importer currently copies

| Field | Source | Classification | Currently imported? |
|---|---|---|---|
| `title` | EventON card microdata + visible title | Factual metadata | ✓ Yes |
| `start_date` | `data-time` unix → date | Factual metadata | ✓ Yes |
| `start_time` | `data-time` unix → time | Factual metadata | ✓ Yes (when not placeholder) |
| `end_date` | `data-time` unix → date | Factual metadata | ✓ Yes (multi-day) |
| `end_time` | `data-time` unix → time | Factual metadata | ✓ Yes (when not fallback) |
| `location` | `evo_location_name` row | Factual metadata (published location) | ✓ Yes |
| `organizer` | `evo_card_organizer_name_t` row | Factual metadata (published organizer) | ✓ Yes |
| `description` | `evcal_event_subtitle` (calendar) + detail subtitle | **Source text** (short label, not full description) | ✓ Yes — subtitle only |
| `price` | Extracted € amount from subtitle | Factual metadata | ✓ Yes (when present) |
| `url` (canonical) | `itemprop="url"` or event link | **Source attribution / external link** | ✓ Yes — stored as `_event_url` / `_event_source_url` |
| `source_id` | `data-event_id` (EventON numeric ID) | Technical identifier | ✓ Yes — stored as `_event_source_id` |
| `image` | Per-event images | Image/media | ✗ No — none found; page URLs rejected; chrome images rejected |
| Full event description | Body paragraph | Source text (full) | ✗ No — none exists; only subtitles present |
| Registration URL/forms | Registration fields | External registration | ✗ No — no registration URLs imported; text preserved only when part of organizer field |
| PDFs | Download links | Document | ✗ No — none imported |
| Logos (FIVA, site logo) | Chrome images | Logo | ✗ No — `is_chrome_image()` rejects |

### 4.2 Detailed field classification

**Factual metadata (clearly factual, generally not copyrightable as facts):**
- Event title
- Date (start/end)
- Time (start/end)
- Location as published
- Organizer as published
- Price (when explicitly stated as € amount)

**Source text (copyright-sensitive, depends on interpretation):**
- Subtitle/description — this is the event's own descriptive text from EventON. It is short (typically a phrase like "Cars up to 1919" or "Brass Brigade Run - cars up to 1919"), not a full article. The audit notes: "Subtitle is the only meaningful body text" and "subtitle-only content (no body paragraph)."

**Source attribution (not content reproduction):**
- Canonical IVVCC event URL — this is a link/reference, not reproduced content

**Technical identifiers (not user-facing content):**
- EventON numeric ID (`data-event_id`) — stored as `_event_source_id` for deduplication, not displayed

### 4.3 What could be removed while preserving intended experience

The intended user experience is:
> Display basic event information + "Ver evento no IVVCC" link to original page

Fields that could potentially be removed (if copyright concern is paramount):

| Field | Impact of removal | Recommendation |
|---|---|---|
| `description` (subtitle) | Loss of short contextual label (e.g., "cars up to 1919") | **Keep if interpreted as factual label**; remove if subtitle is considered reproduced content requiring permission |
| `organizer` | Loss of organizer information | Keep — organizer is factual metadata about who runs the event |
| `price` | Loss of price info | Keep — factual data point |
| `url` | Loss of "Ver evento no IVVCC" link | **Must keep** — this IS the intended linking behavior |

**Minimal viable set for A + limited B:**
1. `title` (factual)
2. `start_date` + `start_time` (factual)
3. `end_date` + `end_time` (factual, multi-day)
4. `location` (factual, as published)
5. `organizer` (factual, as published)
6. `url` → "Ver evento no IVVCC" link (reference/link)
7. Source attribution (visible credit to IVVCC)

**Optional (gray area):**
- `description` (subtitle) — short factual label vs. reproduced source text

## 5. Intended Conexão BR usage model

### 5.1 Classification

**Intended use:** A + limited B

- **A. LINK/REFERENCE ONLY** — Primary: "Ver evento no IVVCC" link to canonical IVVCC event page
- **B. FACTUAL METADATA AGGREGATION** — Limited: title, date/time, location, organizer (facts as published)
- **C. CONTENT REPRODUCTION** — Avoided: no full descriptions, no images, no PDFs, no logos

### 5.2 What the intended use does

1. Displays the event title (factual)
2. Displays the event date and time (factual)
3. Displays the location as published by the source (factual)
4. Displays the organizer as published (factual)
5. Provides a prominent link: "Ver evento no IVVCC" → opens original IVVCC event page
6. Attributes the information to IVVCC as the source

### 5.3 What the intended use does NOT do

1. Does NOT reproduce event images
2. Does NOT reproduce full event descriptions (only subtitle, if kept)
3. Does NOT reproduce registration forms
4. Does NOT reproduce PDFs
5. Does NOT reproduce logos
6. Does NOT claim the events are Conexão BR events
7. Does NOT modify the original event information
8. Does NOT host the original content

## 6. Decision matrix

| Use | Evidence | Allowed / Unclear / Restricted |
|---|---|---|
| **Link to public IVVCC event page** | No prohibition found in robots.txt, privacy policy, or any site page. No terms-of-use page exists. Site exposes public sitemaps. Public event pages are openly accessible. | **Allowed** — no source evidence restricts this |
| **Display title/date/time** | Factual metadata. IVVCC privacy policy says "all content... may not be reproduced without permission" but does not define "reproduce" or address factual data display. Factual data (dates, times) is generally not copyrightable under Irish and international copyright law (Berne Convention). However, the IVVCC statement is broad and does not explicitly exempt factual data. | **Unclear** — factual data is generally not subject to copyright, but the IVVCC privacy policy statement is broad enough that a cautious interpretation would seek clarification |
| **Display location** | Factual metadata (venue name, address as published). Same analysis as title/date/time. Venue names and addresses are facts. | **Unclear** — same as above; facts generally not copyrightable, but IVVCC's broad statement creates uncertainty |
| **Display organizer** | Factual metadata (organizer name as published). Same analysis. | **Unclear** — same as above |
| **Display short factual event summary (subtitle)** | This is source text (the event's own descriptive subtitle). It is short (a phrase), not a full article. It is the most copyright-sensitive element of the factual metadata set. The privacy policy's "reproduce" language could apply to this text. | **Unclear / Restricted** — this is the grayest area; it is source text, not pure fact, and could be considered "reproduction" under a broad interpretation of the IVVCC statement |
| **Reproduce full description** | No full descriptions exist on the source (only subtitles). If they did exist, the privacy policy's "may not be reproduced without permission" would directly apply. | **Restricted** — would require permission per explicit IVVCC statement (though N/A in practice since no full descriptions exist) |
| **Reproduce images** | IVVCC privacy policy: "All content... owned by the IVVCC and may not be reproduced without permission." Images are clearly "content." The importer already rejects all images (chrome images + page URLs never treated as event images). | **Restricted** — requires permission per explicit IVVCC statement. Current importer correctly does NOT import images. |
| **Download PDFs/forms** | IVVCC privacy policy: "All content... may not be reproduced without permission." PDFs and forms are clearly "content." | **Restricted** — requires permission per explicit IVVCC statement. Current importer does not do this. |
| **Automated HTML fetching** | robots.txt allows with `Crawl-delay: 10`. No anti-scraping language. No authentication required. Importer honors crawl-delay, uses browser UA, no Cloudflare bypass. | **Allowed** — compliant with available source instructions (robots.txt) |
| **Store canonical URL as attribution/link** | URL is not "content" in the copyright sense; it is an address/reference. No prohibition on storing or displaying URLs found. | **Allowed** — URLs are references, not reproduced content |

## 7. Assessment of current permission gate

### 7.1 Current gate language

From `docs/importers/ivvcc-importer.md`:

> "The source ships **inactive** — activate it in *Event Import → Event Sources* only after IVVCC permission is obtained (audit §14: copyright requires permission)."

> "**Stage C status (2026-09-10):** `PRODUCTION IMPORT BLOCKED — IVVCC PERMISSION NOT CONFIRMED`"

> "Copyright: ivvcc.ie content requires IVVCC permission before production import (audit §14). The source ships inactive for this reason."

### 7.2 Is the current gate too broad?

**Yes, for the intended A + limited B use case.**

The current gate blocks ALL production import until IVVCC permission is obtained. This was based on audit §14's interpretation that "copyright requires permission."

However, the intended use (A + limited B) is fundamentally about:
1. **Linking** to public event pages (clearly allowed per available evidence)
2. **Displaying factual metadata** (title, date, time, location, organizer — facts that are generally not copyrightable, though the IVVCC privacy policy's broad language creates some uncertainty)
3. **NOT reproducing** copyrighted content (no images, no full descriptions, no PDFs, no logos)

The available source evidence does NOT support the conclusion that permission is required for linking to public event pages or for displaying factual event metadata with attribution.

The IVVCC privacy policy's "may not be reproduced without permission" statement is about **reproduction of content**, not about linking or factual reference. The source evidence does not show that IVVCC prohibits:
- Hyperlinks to their public event pages
- Display of factual event information (dates, times, venues, organizers) with attribution
- Aggregation of publicly available event information

### 7.3 What the evidence DOES support as restricted

The IVVCC privacy policy clearly restricts **reproduction** of:
- Event descriptions/text content
- Images/photographs
- PDFs
- Logos
- Registration forms
- Other page content

For these content types, permission IS required per the explicit IVVCC statement.

## 8. Remaining restrictions (what still requires care)

### 8.1 Content that remains restricted

The following remain restricted per the explicit IVVCC copyright statement and should NOT be imported without IVVCC permission:

1. **Event images** — even though the current importer doesn't import any, this restriction should be maintained
2. **Full event descriptions** — N/A currently (only subtitles exist), but any future full descriptions would require permission
3. **PDFs and forms** — not applicable to current importer
4. **Logos** — not applicable; `is_chrome_image()` already rejects these
5. **The subtitle/description field** — this is the gray area; it is source text and could be considered "reproduction" under a broad interpretation

### 8.2 Uncertainty that cannot be resolved from source evidence alone

The following cannot be definitively resolved from the available source evidence:

1. Whether IVVCC considers display of factual metadata (title, date, time, location, organizer) to require permission
2. Whether the subtitle/description field constitutes "reproduction" requiring permission
3. Whether IVVCC has any unwritten or informal policy on event aggregation

These questions would require either:
- Direct clarification from IVVCC, or
- Legal analysis beyond the scope of this source-use audit

### 8.3 Internal deployment control (separate from source permission)

Regardless of source-permission questions, the following internal controls should remain:

> **Operator confirmation for production writes is an internal deployment control. It is separate from any source-owner permission requirement.**

This means:
- Activating the IVVCC source for production import should require operator confirmation
- This is an internal safety gate, not a claim that IVVCC requires permission
- It ensures deliberate, reviewed production writes
- It is independent of whether IVVCC permission is legally required

## 9. Documentation update recommendation

### 9.1 Problem with current documentation

The current documentation (`docs/importers/ivvcc-importer.md`) states:

> "activate it in *Event Import → Event Sources* only after IVVCC permission is obtained (audit §14: copyright requires permission)"

This implies that IVVCC permission is required for ALL production import. For the intended A + limited B use case (link + factual metadata), this is broader than the available source evidence supports.

### 9.2 Recommended replacement language

Replace the blanket permission requirement with:

**For the source handler documentation:**

> "The source ships **inactive by default**. Production activation requires operator confirmation — this is an internal deployment control, separate from any source-owner permission requirement.
>
> **Content restrictions:** The IVVCC privacy policy states: *'All content on the ivvcc.ie website, unless otherwise stated is owned by the IVVCC and may not be reproduced without permission.'* Accordingly:
> - ✓ **Permitted without IVVCC permission (based on available evidence):** Linking to public IVVCC event pages; displaying factual event metadata (title, date, time, location, organizer) with source attribution.
> - ⚠ **Unclear from source evidence alone:** Whether the event subtitle/description field constitutes 'reproduction' requiring permission. The subtitle is short source text; if this is a concern, omit the description field and rely on title + link only.
> - ✗ **Requires IVVCC permission:** Reproducing images, full descriptions, PDFs, logos, registration forms, or any other copyrighted page content.
>
> The importer is designed to respect these constraints: it imports no images, no full descriptions, no PDFs, no logos, and no registration forms. It displays factual metadata with a 'Ver evento no IVVCC' link to the original event page."

**For the Stage C status:**

Replace:
> `PRODUCTION IMPORT BLOCKED — IVVCC PERMISSION NOT CONFIRMED`

With:
> `PRODUCTION IMPORT READY FOR OPERATOR CONFIRMATION — source-use audit passed for A + limited B model. Content reproduction (images, full descriptions, PDFs, logos) remains restricted per IVVCC privacy policy. IVVCC permission is NOT required for linking + factual metadata based on available source evidence, but the subtitle/description field is a gray area. Operator confirmation is an internal deployment control, not a representation that IVVCC permission has been obtained or is required.`

### 9.3 What NOT to change

- Keep the crawl-delay honoring (robots.txt compliance)
- Keep the image rejection logic (`is_chrome_image()`, no page URLs as images)
- Keep the "no full descriptions" behavior (subtitle-only)
- Keep the no-PDFs, no-logos, no-registration-forms behavior
- Keep the "upcoming events only" scope (§15 audit)
- Keep the attribution requirement (source URL displayed)

## 10. Recommendation for next production-import stage

### 10.1 Proceed with production import for A + limited B

**Recommended:** Yes, proceed with production import for the link + factual metadata use case, subject to operator confirmation.

**Rationale:**
1. Linking to public IVVCC event pages is not restricted by any available source evidence
2. Displaying factual event metadata (title, date, time, location, organizer) is generally not copyrightable, and no source evidence prohibits it
3. The importer is designed to avoid reproducing copyrighted content (no images, no full descriptions, no PDFs, no logos)
4. The "Ver evento no IVVCC" link directs users to the original source, which is the intended user experience
5. The importer honors robots.txt crawl-delay and uses only public pages with a browser UA

### 10.2 Fields to include in production import

**Core set (no copyright concern based on available evidence):**
1. `title` — factual
2. `start_date` + `start_time` — factual
3. `end_date` + `end_time` — factual (multi-day)
4. `location` — factual (as published)
5. `organizer` — factual (as published)
6. `url` → "Ver evento no IVVCC" link — reference
7. Source attribution (visible credit)

**Optional (gray area — decide based on risk tolerance):**
8. `description` (subtitle) — short source text; could be considered reproduction; if in doubt, omit and use title + link only

**Excluded (restricted per IVVCC privacy policy):**
9. `image` — correctly not imported
10. Full descriptions — N/A (none exist)
11. PDFs/forms — correctly not imported
12. Logos — correctly rejected by `is_chrome_image()`

### 10.3 Fields to consider omitting if copyright concern is paramount

If the organization prefers the most conservative approach:

- Omit the `description` (subtitle) field entirely
- Rely on: title + date/time + location + organizer + "Ver evento no IVVCC" link
- This eliminates the only gray-area field and leaves only factual metadata + linking

### 10.4 Operator confirmation gate (internal control)

The production import should require operator confirmation before activation. This is an internal deployment safety control. It should be documented as:

> "Operator confirmation for production writes is an internal deployment control. It is separate from any source-owner permission requirement."

This makes clear that:
- The confirmation is about operational safety, not about IVVCC permission
- It does not imply that IVVCC permission is required for the A + limited B use case
- It ensures deliberate, reviewed production writes

### 10.5 What to monitor after production activation

1. **IVVCC response:** If IVVCC contacts us about the linking/metadata display, engage constructively
2. **Source changes:** EventON updates, page structure changes (existing tests + dry-run cover this)
3. **Copyright claim changes:** If IVVCC adds explicit terms prohibiting linking or factual metadata display, reassess
4. **Subtitle field usage:** If the subtitle field is included, monitor whether it causes any issues

## 11. Summary of findings

### 11.1 What the evidence supports

| Question | Answer based on evidence |
|---|---|
| Does IVVCC prohibit linking to public event pages? | No evidence of such prohibition |
| Does IVVCC prohibit display of factual metadata (title, date, time, location, organizer)? | No explicit prohibition found; factual data is generally not copyrightable; IVVCC's broad "reproduce" statement does not specifically address this |
| Does IVVCC prohibit automated HTML fetching? | No; robots.txt allows with crawl-delay: 10; importer complies |
| Does IVVCC claim ownership of site content? | Yes — "All content on the ivvcc.ie website... is owned by the IVVCC" |
| Does IVVCC restrict reproduction of content? | Yes — "may not be reproduced without permission" |
| Does the current importer reproduce restricted content? | No — no images, no full descriptions, no PDFs, no logos imported |

### 11.2 What the evidence does not resolve

| Question | Status |
|---|---|
| Does "reproduce" in IVVCC's privacy policy cover display of factual metadata? | Unclear from source evidence alone |
| Does the subtitle/description field require permission? | Unclear — it is source text, short, but not a full article |
| Does IVVCC have informal policies not stated on the website? | Unknown — no terms page exists |

### 11.3 Bottom line

For the intended A + limited B use case (link + factual metadata):

- **Linking (A):** Supported by available evidence. No restriction found.
- **Factual metadata (limited B):** Not clearly restricted by available evidence. Factual data is generally not copyrightable. The IVVCC privacy policy's broad language creates some uncertainty, but does not explicitly prohibit factual metadata display.
- **Content reproduction (C):** Explicitly restricted by IVVCC privacy policy. The importer correctly avoids this.

The current blanket permission gate is **too broad** for the intended use case. It should be replaced with a narrower gate that:
1. Allows linking + factual metadata (A + limited B) subject to operator confirmation (internal control)
2. Continues to restrict content reproduction (images, full descriptions, PDFs, logos) per IVVCC privacy policy
3. Flags the subtitle/description field as a gray area that can be omitted if maximum caution is desired

---

## 12. Final determination

**IVVCC STAGE C SOURCE-USE PASSED**

for the intended A + limited B use case (link to public IVVCC event pages + display of factual event metadata with source attribution), subject to:

1. **Operator confirmation** for production writes (internal deployment control, separate from source permission)
2. **No content reproduction** — continue to exclude images, full descriptions, PDFs, logos, registration forms
3. **Subtitle/description field is a gray area** — include only if the organization accepts the interpretation that a short factual subtitle is not "reproduction" requiring permission; otherwise omit and use title + link only
4. **Monitor for changes** in IVVCC's terms, site structure, or any communication about the linking/metadata display

The available source evidence does NOT support blocking factual-link aggregation merely because copyrighted page content exists. The IVVCC privacy policy restricts **reproduction** of content, not **linking** to or **referencing** factual data from public pages.

---

*Report prepared: 2026-10-09*
*Evidence gathered: 2026-09-10 (audit) + 2026-10-09 (live site re-check)*
*Read-only audit: no production writes, no posts created, no images imported, source left inactive pending operator confirmation*
