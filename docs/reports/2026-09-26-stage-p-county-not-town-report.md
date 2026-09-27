# Stage P — A county was being copied into the `conexao_town` taxonomy

| | |
|---|---|
| **Subject** | Imported Laois Events carrying `conexao_town-laois` (a county stored as a town) |
| **Date** | 2026-09-26 |
| **Branch** | `i18n` |
| **Scope** | Taxonomy/data assignment only. No CSS, image, layout or frontend change. |
| **Verdict** | **PASS WITH LIMITATION** (see §7) |

## 1. Summary

Laois is a county; its county town is Portlaoise. There is no town named
Laois. Yet `conexao_town-laois` existed and was attached to 4 events, so the
`/eventos` "cidade" filter offered a locality that does not exist.

The county was not invented by the theme or hidden in the UI. It reached the
town taxonomy because **the importer accepted any string as a town**, and these
sources hand it a value that is only a county.

- The **fix** is in the mapping layer: `Conexao_Event_Location::sanitize_town()`
  now rejects a locality that is only a county name, and `ensure_town()` calls
  it, so **no source can create the term again**.
- The **cleanup** removed exactly 4 erroneous `conexao_town-laois`
  relationships and then deleted the emptied term. The 4 events, their county,
  dates, venue, address, images, URLs and import metadata are untouched.

## 2. Root cause (measured, not assumed)

`Conexao_Event_Location::sanitize_town()` is the single choke point every
source's locality passes through before `ensure_town()` creates a
`conexao_town` term. It stripped Eircodes but accepted **any other string
verbatim**, so a county in the locality field became a town.

The value that triggers it, read from the live database:

| Event | `_event_source` | `_event_address` | resulting `town` |
|---|---|---|---|
| 255 | `eventbrite_laois` | `Gorteenameale Eco Trail, Laois, Laois` | `Laois` |
| 11146 | `eventbrite_laois` | `Killabban, Maganey, Laois, Laois, R93 VW80` | `Laois` |
| 13300 | `eventbrite_laois` | `Glenbarrow, Boleybeg, Laois, Laois` | `Laois` |
| 13310 | `eventbrite_laois` | `Strand road, Laois, Carlow, R93 E6F4` | `Laois` |

`Conexao_Eventbrite_Normalizer::get_town()` returns
`locations[].locality` / `primary_venue.address.city` with only `trim()`. For
these events Eventbrite's `city` is literally the county, so the county was
copied into the town field and then into `conexao_town`.

### Correction to the reported symptom

The brief attributes the bad assignments to the **Laois Tourism** source.
Measured, the `conexao_tourism` source (an iCalendar feed) had **0**
`conexao_town-laois` relationships — it resolves town from
`Conexao_Event_Location::normalize()`, which matches only known town names and
never matches `laois`. All 4 bad relationships belong to `eventbrite_laois`.

The defect is nonetheless shared: it lives in the common mapping layer, so the
fix is placed there and protects **every** source, Laois Tourism included.


## 3. Why the fix is narrow (the over-correction trap)

The tempting rule — "a county name is never a town" — is **false in Ireland**
and would have destroyed real data. Measured on this install, 18 county names
also exist as legitimate `conexao_town` terms:

| Term | Posts | Term | Posts | Term | Posts |
|---|---|---|---|---|---|
| Cork | 406 | Cavan | 23 | Clare | 4 |
| Dublin | 352 | Wicklow | 9 | Kildare | 3 |
| Galway | 145 | Kerry | 7 | Limerick | 3 |
| Kilkenny | 65 | Monaghan | 6 | Offaly | 2 |
| Sligo | 70 | Longford | 5 | Meath | 1 |
| Wexford | 57 | Donegal | 5 | | |
| Waterford | 41 | | | | |
| Carlow | 34 | | | | |

Cavan, Wicklow, Kildare, Carlow, Longford, Monaghan, Donegal and Leitrim are
real towns sharing their county's name; Cork, Dublin, Galway, Kilkenny, Sligo,
Waterford and Wexford are cities that do the same.

So the guard rejects **only counties with no town of the same name** — Laois,
Clare, Kerry, Meath, Offaly — held in
`Conexao_Event_Location::$counties_without_town`. Laois is verified: its county
town is Portlaoise, and no Irish town is named Laois.

## 4. Files changed

| File | Change |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-location.php` | New `is_county_only_value()` + `$counties_without_town`; `sanitize_town()` returns `''` for a county-only value. |
| `scripts/repair-event-town-terms.php` | **New.** Removes the erroneous relationship; delegates the county decision to the importer so there is no second list. |
| `wp-content/plugins/conexao-event-importer/tests/test-town-sanitization.php` | +50 assertions for the rule (32 → 82 passing). |
| `scripts/README.md` | Registers the new script in the inventory. |
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | Regenerated via `scripts/i18n-make-pot.sh` (required by the i18n freshness gate; never hand-edited). |

## 5. Existing events corrected

```bash
php scripts/repair-event-town-terms.php --dry-run --county=laois   # 4 planned, 0 writes
php scripts/repair-event-town-terms.php --apply   --county=laois   # 4 removed
php scripts/repair-event-town-terms.php --apply   --county=laois   # 0 — idempotent
```

The only writes were `wp_remove_object_terms( ..., 'conexao_town' )` on 4
events and `wp_delete_term()` on the emptied term. `conexao_county`,
`conexao_category` and `conexao_tag` were never touched, and no event was
deleted. Every plan row records `kept_county` plus the identity fields
(`_event_source`, `_event_source_id`, `_event_export_uuid`) so the change is
reversible.

## 6. Verification against the acceptance criteria

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 1 | Events have `conexao_county-laois` | **PASS** — 126 events, unchanged from 126 before | `apply-laois.json` |
| 2 | No `conexao_town-laois` unless a real town named Laois | **PASS** — term deleted; verified no Irish town is named Laois (county town is Portlaoise) | `apply-laois.json`, `future-import-proof.log` |
| 3 | Real town data still imported | **PASS** — `LOCATION:Durrow, Co. Laois` → `town=Durrow`; 25 events keep `portlaoise` | `future-import-proof.log` |
| 4 | No town when the source has none | **PASS** — `LOCATION:Co. Laois` → `town=''` | `future-import-proof.log` |
| 5 | County → Laois filter still works | **PASS** — `/eventos/?county=laois` → HTTP 200, 10 cards/page | `http-after.md` |
| 6 | No unrelated assignments changed | **PASS** — ran `--county=laois`; Cork 406, Dublin 352, Galway 145, Cavan 23, Wicklow 9, portlaoise 25 all unchanged | `dry-run-all-counties.json` |
| 7 | Future imports cannot recreate it | **PASS** — Eventbrite `city=Laois` and ICS `LOCATION:Co. Laois` both yield no town term | `future-import-proof.log` |


## 7. Limitations and follow-ups

1. **PASS WITH LIMITATION — no production action.** No production write was
   authorised, and none was made. A `conexao_town-laois` term on production, if
   it exists, is still there. Production has no CLI, so the fix ships by
   deploying the plugin and removing the term through an admin screen.
2. **The same defect exists in 3 other counties and was deliberately NOT
   changed.** An unscoped dry run finds 19 erroneous relationships: `clare` 4,
   `kerry` 7, `meath` 1, `offaly` 2, plus a pre-existing `Co Clare Ireland`
   term (1) on event 21475. This is outside the stated scope (criterion 6), so
   the repair was scoped to Laois. Clearing them is one command, already proven
   by dry run:
   ```bash
   php scripts/repair-event-town-terms.php --apply
   ```
   That would also clear the one long-standing failure in
   `test-town-sanitization.php` ("no Eircode-contaminated or non-town terms in
   `conexao_town`"), which is caused by the `Co Clare Ireland` term.
3. **Lint could not be fully run here.** `scripts/lint.sh` step 1 passed (451
   files, no parse errors). Steps 2–3 are blocked by the workstation, not the
   code: PHPCS needs the `xmlwriter`/`SimpleXML` PHP extensions (absent) and
   `composer` is not on `PATH`. PHPStan was run directly on the changed files
   instead: **No errors**.
4. **`verify-i18n-freshness.py` is red, and it is not from this change.** It is
   caused by the *previous* commit `89c5889`, which modified
   `class-event-importer.php` without regenerating the `.pot`. The gate reads
   git commit time, so it passed while that edit was uncommitted and went red
   once it landed. This stage regenerated the catalogue, which clears it on
   commit. The gate reports `pre_existing: 1, new: 0`.

### Test status

`./scripts/run-tests.sh` — in-process 43/55 suites, 3464 assertions passed.
The 12 failing suites are the **identical set** recorded in the Stage 7.x
baseline. `test-town-sanitization.php` went from 32 passed/1 failed to
**82 passed/1 failed**: the single failure is the same pre-existing
`Co Clare Ireland` term, not a regression.
