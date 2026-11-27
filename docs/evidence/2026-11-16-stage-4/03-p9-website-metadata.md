# Stage 4 — P9 provider-specific website metadata

**Assessment date:** 2026-11-16
**Retained HTML only.** No live provider was fetched. No browser was run
against a live provider.

## 1. Retained fixture inventory

| Provider | Fixture | Path | SHA-256 | Capture provenance |
|---|---|---|---|---|
| Motorsport Ireland | `mi-listing.html` | `wp-content/plugins/conexao-event-importer/tests/fixtures/mi-listing.html` | `0c1d71b87806f77e38ae505ed0425c79232e9c0714248df097ce2d1b8ac98376` | Saved live snapshot (Squarespace listing page), committed 2026-09-10 |
| Mondello Park | `mp-rest.json` | `wp-content/plugins/conexao-event-importer/tests/fixtures/mp-rest.json` | `490b8083a77a45cf6b91a05628d39089d6ad30472e4b316efe7d31b2f9680a7b` | Saved live REST response (29 events), committed 2026-09-11 |
| IVVCC | none | — | — | No retained HTML fixture exists |

**IVVCC:** no retained HTML fixture (calendar or detail page) exists in the
repository. The only IVVCC-specific test fixtures are inline deterministic
card/detail snippets in `test-ivvcc-importer.php`, which are synthetic
minimal cards, not captured live HTML. Per the task rules, no new HTML was
fetched to fill the gap.

**Mondello Park:** the retained fixture is a REST JSON response (29 events),
not detail-page HTML. The detail-page HTML (which carries the
`event-location` / `event-date` paragraphs the handler parses) is not
retained.

## 2. Per-provider field analysis

### 2.1 Motorsport Ireland

The retained `mi-listing.html` is the Squarespace listing page (59 upcoming
event cards). Field-by-field:

| Field | Extractable from retained source? | Current behaviour | Change |
|---|---|---|---|
| Venue | **No.** No venue/address/location element exists in the listing card. The audit (`docs/importers/motorsport-ireland-audit.md` §2.7) confirms no location data at the source (listing, detail and JSON-LD all lack it). | `location` left empty (`location_optional => true`). | None — genuinely absent. |
| Town / County | **No.** No per-event location text. | Empty. | None — genuinely absent. |
| End date | **Yes (already extracted).** The JSON-LD `endDate` on the detail page is parsed by `parse_jsonld_dates()`. | Extracted. | None — already correct. |
| Description | **Yes (already extracted).** The `eventlist-description` container is parsed by `parse_listing_card()` / `parse_detail_description()`. | Extracted. | None — already correct. |
| Featured image | **No.** The listing card's `eventlist-column-thumbnail` anchor is empty for all 59 events in the retained fixture. No per-event image exists at the source. | `image` always empty. | None — genuinely absent. |

**Prior claim (superseded):** The original Stage 4 execution claimed
`extract_card_image()` extracted a per-event image from the listing card's
thumbnail column, rejecting site chrome by filename marker. The retained
fixture measurement showed 59 events, 0 images.

**Correction:** The speculative image-extraction code was removed. The
retained `mi-listing.html` has 59 upcoming cards, all with empty thumbnail
anchors (no per-event images at the capture date). The parser now matches the
baseline: 59 events, 0 images, 21 descriptions, 0 end dates (from listing), 0
locations. No provider-side improvement was demonstrated.

**Post-removal re-verification:** The baseline parser was rerun against the
unchanged retained fixture after removal. Result: 59 events, 0 images, 21
descriptions, 0 end dates (from listing), 0 locations. This is the final
evidence of what the baseline parser extracts.

**Status:** `ASSESSED — RETAINED FIELDS ALREADY EXTRACTED; NO NEW IMPROVEMENT
DEMONSTRATED`.

### 2.2 Mondello Park

The retained `mp-rest.json` is a REST response (29 events). Field-by-field:

| Field | Extractable from retained source? | Current behaviour | Change |
|---|---|---|---|
| Venue | **No (from retained REST JSON).** The REST response has no venue/address field (`acf` is empty on all 29 events). The handler's `extract_location_paragraph()` reads an `event-location` paragraph from the DETAIL page HTML, which is not retained. | `location` from detail page. | None — detail HTML not retained. |
| Town / County | **Partial.** The source config supplies `county => 'Kildare'` as a hint (applied by the normalizer when the location string yields no county). | County hint applied. | None — already correct. |
| End date | **No (from retained REST JSON).** The REST response has no end-date field; the handler's `parse_date_range()` reads the detail page's `event-date` paragraph, which is not retained. | `end_date` from detail page. | None — detail HTML not retained. |
| Description | **No (from retained REST JSON).** The REST `content.rendered` is empty on all 29 events. | `description` from detail page. | None — detail HTML not retained. |
| Featured image | **Yes (already extracted).** The REST `featured_media` is non-zero on all 29 events. | Extracted. | None — already correct. |
| Category | **Yes (already extracted).** The REST `event_category` term IDs are mapped by `map_categories()`. | Extracted. | None — already correct. |

**Assessment:** the retained Mondello Park fixture is a REST JSON response,
not detail-page HTML. The fields that the handler extracts from the detail
page (venue, end date, description) cannot be assessed for extractability
from the retained evidence because the detail HTML is not retained. The
REST-level fields (featured image, category) are already extracted correctly.

**Status:** `ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` for the
detail-page fields (venue, end date, description); the REST-level fields
(image, category) are already extracted correctly. No parser change is made
because the retained evidence does not contain the detail-page HTML the
parser would act on, and inventing a fixture or fetching a page is prohibited.

### 2.3 IVVCC

No retained HTML fixture exists. The only IVVCC-specific test fixtures are
inline deterministic card/detail snippets in `test-ivvcc-importer.php`, which
are synthetic minimal cards, not captured live HTML.

**Status:** `NOT VERIFIED — NO RETAINED SOURCE HTML`.

The IVVCC handler already extracts venue/location (from the `evo_location_name`
element), description (from `evcal_event_subtitle`), image (from the detail
page `<img>`), and dates (from EventON `data-time`). The audit
(`docs/importers/ivvcc-audit.md`) documents that the source DOES carry
location data (venue + town + county + street address + Eircode across the
sample events). Whether the current parser extracts all of it correctly
cannot be verified without retained HTML, and no new HTML was fetched.

## 3. P5 partial-enrichment semantics

The changes respect the P5 model: a failed/unavailable extraction is not
treated as an empty/clear value. The MI image extraction returns `''` when
the thumbnail column is empty (no per-event image), which leaves the stored
value untouched (the normalizer's `banner` field is only written when
non-empty). No existing good metadata is regressed.

## 4. Tests

`test-motorsport-ireland-importer.php` — reverted to HEAD (no new image
assertions). The speculative image-extraction test cases were removed.

Result: **89 passed, 0 failed** (baseline).

## 5. Per-provider completion status

| Provider | Status |
|---|---|
| IVVCC | `NOT VERIFIED — NO RETAINED SOURCE HTML` |
| Motorsport Ireland | `ASSESSED — RETAINED FIELDS ALREADY EXTRACTED; NO NEW IMPROVEMENT DEMONSTRATED` |
| Mondello Park | `ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` (detail-page fields not assessable without retained detail HTML; REST-level fields already correct) |

## 6. Limitations

- IVVCC: no retained HTML; the source is documented to carry location data
  (venue + town + county + street address + Eircode), but the current parser's
  completeness cannot be verified without a fixture.
- Mondello Park: the retained fixture is REST JSON, not detail HTML; the
  detail-page fields (venue, end date, description) cannot be assessed.
- Motorsport Ireland: venue, town and county are genuinely absent from the
  source (listing, detail and JSON-LD all lack location data — audit §2.7).
  These fields are left empty, not invented. The featured image is also
  absent from the retained fixture (all 59 thumbnail anchors are empty).cation data,
  but the current parser's completeness cannot be verified without a fixture.
- Mondello Park: the retained fixture is REST JSON, not detail HTML; the
  detail-page fields (venue, end date, description) cannot be assessed.
- Motorsport Ireland: only one field (featured image) was extractable-but-
  not-extracted; the other five are already correct or genuinely absent.