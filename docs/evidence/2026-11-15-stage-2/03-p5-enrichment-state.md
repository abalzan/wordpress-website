# P5 — partial-enrichment state and field-level preservation

## 1. Defect and fix

Heritage Week enriches each listing event from its detail page. When that detail
request failed, `enrich_event()` returned the listing-only event — an empty
`description`, `venue` and `address` — and the engine wrote those empty values
over the stored record, erasing data a previous successful run had imported.

Stage 2 introduces an explicit, additive per-field enrichment state on the
normalized payload and makes the write path preserve stored values for fields
whose retrieval failed.

## 2. The state model

New class `includes/class-conexao-event-enrichment-state.php`
(`Conexao_Event_Enrichment_State`):

| State | Meaning | Write path |
|---|---|---|
| `populated` | source retrieved the field with a value | write the incoming value (a correction is applied) |
| `empty` | source retrieved the field and its documented contract declares it legitimately empty | write the empty value (a genuine clear is applied) |
| `unavailable` | retrieval failed / the value is unknown | **preserve** the stored value |
| `not_evaluated` | enrichment was not attempted for this field | **preserve** the stored value |
| *(field absent from the map)* | legacy / no enrichment contract | write the incoming value exactly as before |

Fields covered: `description`, `venue`, `address`, `location`, `organizer`,
`banner`. Configuration-driven taxonomy is excluded: an empty category is
already a no-op for term assignment and therefore cannot erase.

**Additivity.** The state lives only in the in-memory normalized payload
(`$normalized['enrichment']`). No post meta key was added, so no reader of event
meta changes and no new persistent store exists (therefore no new store needed
the Stage 0.5 isolation protections). A payload that declares nothing — every
Eventbrite and listing-only event — carries no `enrichment` key at all.

## 3. Heritage Week declaration

`Conexao_Source_Heritage_Week::enrich_event()` now sets `_enrichment` on every
raw event:

| Detail outcome | Declared state |
|---|---|
| no detail URL | all fields `not_evaluated` |
| transport failure / HTTP failure / empty body | all fields `unavailable` |
| invalid HTML | all fields `unavailable` |
| success, JSON-LD `Event.location.name` present | `venue` `populated` (+ `address` `populated` when a street/postal component exists) |
| success, JSON-LD `Event.location` absent | `venue` / `address` `unavailable` |
| success, `div.text-block` present with text | `description` `populated` |
| success, `div.text-block` present and **blank** | `description` `empty` (genuine clear) |
| success, `div.text-block` **absent** | `description` `unavailable` |
| success, `ul.event-dates` items found | `location` `populated`, otherwise `unavailable` |
| success, "Further Information" paragraph with text | `organizer` `populated`, otherwise `unavailable` |
| success, event carries an image after merging | `banner` `populated`, otherwise `unavailable` |

**Documented emptiness contract (the only "genuine empty" in Heritage Week):**
the description container must be **present** and blank. An absent element,
absent JSON-LD key, empty body, failed request or invalid document is treated as
*unavailable*, never as a clear. That is the narrow, source-specific rule P5
requires, and it is what the `absent element` test asserts.

## 4. Write path

`Conexao_Event_Importer_Engine::apply_enrichment_preservation()` runs inside
`upsert_event()` for an **existing** record, before change detection and before
any write. For each declared field whose state is `unavailable` or
`not_evaluated` it reads the stored value and substitutes it into the write view:

| Field | Stored source |
|---|---|
| description | `post_content` |
| venue | `_event_venue` |
| address | `_event_address` |
| location | `_event_location` (written through the derived `event_location`) |
| organizer | `_event_organizer` |
| banner | `_event_banner` |

`_event_location` is derived from venue/town/county, so it is preserved **only
when the venue is preserved too**; a corrected venue is never paired with a
stale location label.

Two supporting changes, both scoped so no unrelated handler changes:

1. **Change detection.** The stored description never participated in change
   detection, so a corrected or cleared description was ignored unless another
   field also changed. It now participates **only for a payload that declares a
   description enrichment state**. Eventbrite and listing-only sources declare
   nothing and keep the historical comparison exactly.
2. **Logging.** A preservation writes an `info` entry naming the preserved
   fields (`Partial enrichment for "<title>": could not retrieve <fields> …`), so
   an operator can distinguish a partial enrichment from a clean one.

Preservation is **field-level**: a run that retrieved the description but not
the venue writes the description and preserves the venue in the same pass.

## 5. Other enriching handlers (audit)

| Handler | Enrichment | Effect of Stage 2 |
|---|---|---|
| Heritage Week | detail page supplies description, venue, address, location, organiser, image | **fixed** (declares the state) |
| IVVCC | detail page fills only *empty* listing gaps (`merge_detail` never overwrites with an empty value) | unchanged; cannot erase stored data |
| Motorsport Ireland | detail page overrides a field only when the parsed value is non-empty | unchanged; cannot erase stored data |
| Mondello Park | detail page overwrites only with non-empty parsed values | unchanged; cannot erase stored data |
| Eventbrite | no detail fetch | unchanged (no `enrichment` key is ever produced) |

## 6. Tests

New suite
`wp-content/plugins/conexao-event-importer/tests/test-partial-enrichment-preservation.php`
(42 assertions), engine-level through `run_source()` with HTTP doubles:

| Case | Assertion |
|---|---|
| failed detail fetch | description, venue, address, organiser and location label all preserved; event reported `unchanged` (no churn write) |
| failed detail fetch | the preservation is logged and names the preserved fields |
| successful detail fetch | corrected description, venue and address are written (a correction still applies) |
| description container present but blank | description **cleared** (genuine clear), venue preserved in the same run |
| description container absent | description, venue and address preserved (omission is not a clear) |
| field-level partial enrichment | description written, venue and address preserved in the **same** run |
| new event with failed detail fetch | created with the listing values (creation still writes incoming values) |
| payload with no enrichment contract | empty description still clears — Eventbrite's write path is unchanged |

Eventbrite non-regression is additionally covered by the existing Eventbrite
suites in the full run (`test-eventbrite-*`), which were not modified.

