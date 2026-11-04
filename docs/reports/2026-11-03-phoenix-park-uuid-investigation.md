# Investigation — `_leisure_export_uuid` identity and the Phoenix Park pair (read-only)

| | |
|---|---|
| **Task** | Read-only investigation of the shared `_leisure_export_uuid` on `phoenix-park` (10150) / `phoenix-park-en` (115357) |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `80340da8eb3ea9b96f37d6bf3b264bce28fc04c3` |
| **Writes performed** | **none** — no code, content, meta, option or schema change; this document is the only artefact |
| **Production actions** | none |

## Headline

**The shared UUID is correct by design, not a defect.** The earlier Lazer
coordinate report called it "a genuine identity defect"; that was **wrong**, and
this investigation corrects it. `_leisure_export_uuid` identifies the
**attraction**, not the WordPress post, and the PT master plus its linked EN
translation are *required* to carry the same value — there is a hard-gate test
asserting exactly that.

The real gap is narrower: **the coordinate apply tool** cannot address the EN
sibling, because it matches UUID-first and both records share one UUID. That is a
matching-order limitation in a tool I wrote, not a data-model problem.

---

## 1. All writers and consumers of `_leisure_export_uuid`

The meta key is defined once, as a class constant:
`Conexao_Lazer_Exporter::UUID_META_KEY = '_leisure_export_uuid'`
(`wp-content/plugins/conexao-leisure-migration/includes/class-leisure-exporter.php:32`).

### Writers

| Where | What it does |
|---|---|
| `class-leisure-exporter.php:143-147` | On export, if the record has no UUID it **generates one and stores it** (`generate_uuid()`, line 411). This is the canonical generator. |
| `class-leisure-importer.php:764` | On create/update, writes the item's `uuid` onto the resolved **import target**. |
| `scripts/bootstrap-ci-fixtures.php:1021` | Writes the committed fixture UUID (`$pair['uuid']`) for CI fixtures — **the origin of the Phoenix Park duplicate**. |
| `scripts/historical/stage32-translate-content.php:76` | Historical: copies the UUID from the PT master to its EN translation — the origin of the shared-UUID convention. |
| `scripts/historical/stage7-clone-seed-leisure.php:140-141` | Historical seeder: writes both `_leisure_uuid` and `_leisure_export_uuid` (same deterministic value). |
| `scripts/historical/stage32-seed-pilot.php:470-471` | Historical: generates a UUID only when absent. |

### Consumers (readers)

| Where | What it does |
|---|---|
| `class-leisure-importer.php:603` `find_by_uuid()` | Primary dedup lookup on import. |
| `class-leisure-importer.php:581` `find_existing_item()` | Matching order **UUID → slug → title**. |
| `class-leisure-importer.php:626` `pick_import_language()` | Disambiguates when UUID matching returns more than one record. |
| `class-leisure-importer.php:1174-1210` `verify_import()` | Counts `with_uuid`, surfaced in wp-admin as **"With stable ID: N"** (`class-leisure-transfer-admin.php:238,429`). |
| `class-leisure-exporter.php:170-171` | Export payload keys `uuid` **and** `id`. |
| `class-language-guard.php:8` | Documents that the guard **never** touches identity data (`_leisure_uuid`). |
| `inc/i18n/fallback.php:515`, `inc/i18n/guard.php:41` (theme) | Comments asserting the UUID stays language-neutral. |
| `tests/test-language-uuid.php` (plugin) | HARD GATE on the shared-UUID contract. |
| `tests/test-stage32-bilingual.php` (theme) | Asserts the PT/EN pair shares one UUID and **no third record** claims it. |
| `scripts/apply-leisure-coordinates.php` (this task) | Matches UUID-first — the tool that surfaced the issue. |
| `scripts/historical/stage7-clone-inventory.php`, `verify-lazer-expansion-final.php`, `stage7-leisure-inventory.php` | Reporting only. |

**Not a consumer:** the theme's two read-only REST routes
(`/conexao/v1/leisure-coordinates`, `/conexao/v1/leisure-cards`) expose the local
**post ID**, never the UUID (`inc/leisure-location.php:328` `'id' => (int) $id`).

---

## 2. Is uniqueness enforced per post or per attraction?

**Per attraction.** One attraction = **one UUID = one or two rows** (PT master +
optional linked EN translation). The design is explicit and test-enforced:

| Evidence | Statement |
|---|---|
| `test-language-uuid.php:93` | "the translation shares the UUID verbatim (no new UUID)" |
| `test-language-uuid.php:128` | "across all languages the UUID exists exactly twice: master + translation" |
| `test-stage32-bilingual.php:213-233` | The pilot PT lazer record and its linked EN translation "must share one `_leisure_export_uuid`, and no third record may claim it" |
| `bootstrap-ci-fixtures.php:1012-1020` | "The SHARED export identity … the PT master and its linked EN translation store the SAME value, and **no third record may claim it**" |
| `stage32-translate-content.php:22` | "Leisure translations keep the SAME `_leisure_export_uuid`" |
| `docs/engineering-standard.md` §0.4 | Names `_leisure_uuid` / `_leisure_export_uuid` as the portable cross-environment identity |

**It is a convention, not a database constraint.** There is no unique index on
`wp_postmeta`, and `update_post_meta()` has no uniqueness guard; `generate_uuid()`
is the only thing preventing collisions. The enforceable invariant is therefore
"**1 or 2 rows, never 3+**" — asserted by tests, not by the schema.

**Measured state of the local database (read-only):**

```text
published leisure posts                       294
posts carrying _leisure_export_uuid           290   (all non-empty)
distinct UUID values                          289
UUID values carried by more than one post       1   <- the Phoenix Park pair
posts with NO uuid                              4   (the [PH3C TEST] CI fixtures)
```

So the dataset is in the state the design intends: **exactly one** duplicated
value, and it is the single PT+EN pair.

---

## 3. Are Polylang translation pairs expected to share or differ in UUID?

**Share.** Unambiguously, and this is the documented contract rather than an
accident:

- a translation carries the **same** UUID "verbatim (no new UUID)";
- `find_by_uuid()` is expected to return **two** records, which is exactly why
  `pick_import_language()` exists — its docblock says: *"A Polylang translation
  carries the same `_leisure_export_uuid` as its Portuguese master, so UUID
  matching alone can return two records."*;
- the importer must then pick the **Portuguese master** and **refuse** to write
  to the translation (`is_import_target()`), so the pair can never become two
  competing UUID targets;
- the language guard never reassigns a language and never touches identity data.

**Two different UUIDs for one attraction would be the defect**, because it would
create a second identity for the same thing — forbidden by
`engineering-standard.md` §0.2 ("Two identities for the same thing is a defect").

---

## 4. Why the English sibling cannot hold coordinates

**Not WordPress. Not Polylang. Not the UUID design. It is the coordinate apply
tool's matching order.**

Mechanism, precisely:

1. The manifest contains one entry per published `leisure` record, so 10150 and
   115357 each get an entry.
2. Both entries carry the same `uuid`, because that is what is stored.
3. `conexao_leisure_coord_resolve()` matches **UUID → slug → title**. The UUID
   match returns the **first** post carrying that value — always 10150 — so the
   second entry resolves to the *same* post.
4. The second write therefore finds an identical value already stored and reports
   `unchanged`. Net effect: 115357 never receives a coordinate, and the run
   reports `populate_entries=76, written=75, unchanged=1` (the observed batch-2
   behaviour).

`_leisure_lat` / `_leisure_lng` are ordinary per-post meta with no
attraction-level sharing, so **writing them to 115357 directly is entirely
possible** — nothing in WordPress, Polylang or the Lazer plugin prevents it.

The manifest even records the situation correctly on that row: *"shares
`_leisure_export_uuid` with its PT master, so the UUID-first match resolves both
to 10150; repairing that duplicate identifier is a separate data change"* — the
diagnosis was right about the mechanism, wrong about it being a defect.

---

## 5. Impact on exports, imports and mobile consumers

### Export (ZIP package)

`get_export_post_ids()` (`class-leisure-exporter.php:96-108`) enumerates
`post_type=leisure, post_status=any` with **no language filter**. An export
therefore emits **two items carrying the same `uuid`** (and the same `id`, since
`export_item()` sets both to the UUID).

**Consequence:** `data.json` contains a duplicated identity key. That is
tolerable *only* because the importer resolves duplicates deterministically — but
it means the export is not self-consistently keyed.

### Import

`find_by_uuid()` -> `pick_import_language()` returns the record in the **import
language** (the PT master); `is_import_target()` returns `false` for the EN
sibling, so the second item is **refused** rather than duplicating the UUID or
overwriting the translation. This is deliberate and covered by
`test-language-uuid.php:130-158`.

**Consequence:** the EN sibling's own fields are **not round-trippable**. The
English layer that *is* round-trippable is the **field-level
`_leisure_excerpt_en` on the PT record** (`export_meta_keys`, exporter line ~74).

**In production this is moot.** Leisure's English layer is field-level by design,
and this database contains exactly **one** EN sibling — the CI fixture created by
`ci-fixture-events.php` specifically to exercise the "real EN translation" branch
of the Stage 4.1 REST suite. There is no second identity to lose.

### REST / mobile consumers

- This repository exposes **no** UUID over REST. Both theme routes key on the
  local post ID; the coordinates route returns `{"id": <post id>, "lat", "lng",
  "index"}` and the cards route returns rendered HTML.
- The UUID is therefore not a public API contract *from this repository*.
- **The Flutter/mobile repository is explicitly out of scope** for every task in
  this repository (`AGENTS.md`, "Scope: what you may and may not touch"), so I
  have **not** inspected it and cannot state whether it consumes
  `_leisure_export_uuid`. If the mobile app keys on it, the shared-value
  semantics matter to it and should be confirmed by the mobile owners.
- Nothing in this repository's admin surface exposes the UUID as data; the Lazer
  transfer screen only shows the **count** ("With stable ID: N").

---

## 6. Proposed minimal repair and regression tests

### Options considered

| Option | Verdict |
|---|---|
| **A. No data change — fix the tool's matching order** | **RECOMMENDED.** The duplicate is intended; the defect is in my apply tool. No identity change, no test change, no migration. |
| B. Give the EN sibling its own UUID | **Rejected.** Directly contradicts `test-language-uuid.php` ("shares the UUID verbatim"), `test-stage32-bilingual.php` ("no third record may claim it") and the exporter/importer contract; creates two identities for one attraction (standard §0.2). |
| C. Delete the EN sibling | **Rejected / out of scope.** It exists to cover the "real EN translation" branch of the Stage 4.1 REST suite; removing it removes coverage. |

### Recommended repair (Option A)

Change `conexao_leisure_coord_resolve()` in `scripts/apply-leisure-coordinates.php`
so that a UUID match returning **more than one** record is disambiguated by the
manifest entry's own identity before falling back:

1. collect every post carrying the UUID;
2. if there is exactly one -> use it (`stable-id`);
3. if there are several -> prefer the one whose **slug** matches the entry
   (`stable-id+slug`), then the one whose **Polylang language** matches the
   entry's language (`stable-id+lang`);
4. if still ambiguous -> **do not write**; report the entry as
   `unresolved`/`ambiguous` and fail the run, rather than silently writing twice
   to the PT master.

This keeps the tool's guarantees (dry-run first, preserve existing differing
values, idempotent, numeric gate) and adds **no** new data model, no schema
change and no identity change. It also reports the new strategy counts so a
silent fallback is impossible.

### Regression tests

| # | Test | Proves |
|---|---|---|
| 1 | Extend `scripts/data/leisure-coordinates-selftest.json` with two entries sharing one UUID (PT + EN slugs) and run `--dry-run` | Both entries resolve to **two different** posts; the plan lists two writes, not one write + one `unchanged` |
| 2 | Same manifest with the EN entry's slug removed | The tool reports the entry as **ambiguous** and exits non-zero instead of writing twice to the PT master |
| 3 | Run the real manifest `--apply` twice | The EN sibling receives its coordinate on run 1; run 2 reports `written=0, unchanged=289` (idempotent) |
| 4 | `conexao-leisure-migration/tests/test-language-uuid.php` | Unchanged and still green — the shared-UUID contract is untouched |
| 5 | `theme/tests/test-stage32-bilingual.php` | Unchanged and still green — "no third record may claim it" still holds |
| 6 | `theme/tests/test-leisure-location-services.php`, `plugin/conexao-admin-ux/tests/test-leisure-coordinates.php` | The coordinate consumers still pass with 290 populated records |
| 7 | `./scripts/run-tests.sh` | Full suite unchanged against the recorded baseline |

### Decision needed from the owner

1. **Approve Option A** (tool fix, no data change) — my recommendation.
2. **Confirm the intended semantics** for the record: the UUID identifies the
   attraction, so `10150` and `115357` *should* share it. If you would rather the
   EN sibling hold its own identity, that is Option B and it requires changing
   the Lazer plugin contract and three tests — a much larger, separately
   authorised change.
3. **If the mobile app consumes `_leisure_export_uuid`**, the mobile owners must
   confirm whether they expect one value per attraction (current design) or one
   per record. I cannot check that repository from here.

---

## 7. Documentation drift found (not fixed here)

`docs/content-model.md:405` states the Lazer identifier meta key is
`_lazer_uuid`. **That key does not exist in the code or the database** — the real
key is `_leisure_export_uuid` (290 rows), and the only other historical key is
`_leisure_uuid` (written only by the retired `stage7-clone-seed-leisure.php`; no
rows in this database). The engineering standard §0.4 names
`_leisure_uuid` / `_leisure_export_uuid`.

This is a genuine single-source-of-truth drift and per
`engineering-standard.md` §9.2 it must be corrected — but it is a documentation
change, so it belongs in whichever change the owner authorises next, not in this
read-only investigation.

---

## 8. What this report did and did not do

**Did:** read the Lazer migration plugin, the language guard, the theme's REST
layer, the CI fixture tree, the engineering standard and the content model; ran
read-only SQL against the local database; queried the two REST routes; and
counted UUID rows, distinct values and duplicates.

**Did not:** write any code, content, meta, option or schema change; run any
apply; touch the Flutter/mobile repository; perform any production action.

_Last verified: 2026-11-03 by the Phoenix Park UUID investigation_
