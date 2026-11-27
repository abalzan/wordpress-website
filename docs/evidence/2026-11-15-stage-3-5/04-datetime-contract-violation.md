# Task D — the `_event_date` datetime contract violation

**Evidence:** `04-datetime-violations.tsv` (20 rows), read-only `SELECT` at
`2026-11-15T19:24Z`, plus code inspection at HEAD `77f09a3`.
**Writes:** none. Post `115352` was read, not modified. No data migration.
**Provider requests:** none.

---

## 1. The documented contract

* `class-event-normalizer.php:245-284` implements `normalize_date()` and returns
  **`Y-m-d` only**. The function accepts `Y-m-d`, `d/m/Y`, `d.m.Y`, textual dates
  and ISO 8601/RFC 3339 **with time** — and for every one of them it emits
  `date( 'Y-m-d', … )` or `$m[1]`. The time component is discarded, never stored.
* `class-event-importer.php:2266` writes `'_event_date' => $normalized['start_date']`
  and `:2269` `'_event_end_date' => $normalized['end_date']` — i.e. only values
  that came out of `normalize_date()`.
* `conexao-data-model/includes/class-meta.php:10` registers `_event_date` with
  meta type `date`.

The contract is therefore **date-only, local, timezone-less `Y-m-d`**, and the
importer has no code path that can violate it.

## 2. Is it one instance or a class? — **A class of 20**

Query (read-only):

```sql
SELECT post_id, meta_key, meta_value FROM wp_postmeta
 WHERE meta_key IN ('_event_date','_event_end_date')
   AND (meta_value LIKE '% %' OR meta_value LIKE '%T%')
 ORDER BY post_id, meta_key;
```

Denominators, measured in the same session:

| Store | Total values | Values with a time component | Share |
|---|---:|---:|---:|
| `_event_date` | **3,406** | **20** | 0.59 % |
| `_event_end_date` | **3,134** | **0** | 0 % |

`LIKE '% %'` is sufficient here (a `Y-m-d H:i:s` value always contains a space; a
`T`-separated ISO value is caught by the second pattern), and these are plain
string values, not serialised data, so the pattern cannot produce the false
matches that the warning about `LIKE` on serialised options is about.

**The 20 affected posts:**

| Group | Post IDs | Stored `_event_date` |
|---|---|---|
| A (6) | `115349`, `115350`, `115351`, `115352`, `115353`, `115354` | `2026-11-13 00:00:00` |
| A (1) | `115355` | `2026-10-14 00:00:00` |
| B (12) | `115370`–`115381` inclusive, plus `115382` | `2026-11-13 06:00:00` |

None of the 20 has an `_event_end_date` at all, so there is no canonical value to
fall back on for any of them.

## 3. When was post `115352` written?

| Field | Value | Note |
|---|---|---|
| `wp_posts.post_modified` | **2026-11-13 08:31:56** | This is the **post row** timestamp |
| `_event_imported` | **absent** | The key the importer would write does not exist on this post |
| `_event_date` write time | **UNAVAILABLE** | See the caveat below |

**`post_modified` is not proof of when `_event_date` was written.** It records the
last write to the `wp_posts` row and is not updated by a postmeta write. The
postmeta write time is not recorded anywhere (WordPress keeps no per-meta
timestamp), so no `_event_date` write timestamp is claimed.

What *can* be said is narrower and stronger: the **whole 20-post group shares a
single bulk-creation window**, `2026-11-13 08:31:56`–`08:31:59` (`post_modified`),
consistent with one seeder pass creating them together — and the seeder that
creates them is identified in §5.

## 4. Which source wrote it?

| Field | Post `115352` |
|---|---|
| `_event_source` | **absent** (the key does not exist) |
| `_event_source_id` | **absent** |
| `_event_url` | **absent** |
| `_event_end_date` | **absent** |
| `_event_status` | `source_not_found` |
| `_event_export_uuid` | `d965aeb3-bf14-401e-98b7-3fc080666fdd` |
| `conexao_meta_description` | `Registo sintético da CI para "evento-rem…` |
| `post_title` | **Evento sintético removido na fonte** |

A post with no `_event_source`, no `_event_source_id` and a title that literally
reads *"synthetic event removed at source"* was **not written by the importer and
is not a real-world event**. It is a CI fixture. This also explains the one
"*(empty `_event_source`)*" row in Stage 3's per-source table, and why Stage 3
classified it Indeterminate: there was no source to attribute the value to.

## 5. The code path that could have written it

`scripts/bootstrap-ci-fixtures.php` — the deterministic CI fixture seeder:

```php
// scripts/bootstrap-ci-fixtures.php:996-1001
// A FULL datetime. A bare `Y-m-d` here made WordPress store
// `00:00:00` and then report the record differently on read, so the
// upsert comparison never matched and these events were "repaired" on
// every single run.
$date                   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d' ) . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
$pt_meta['_event_date'] = $date;
```

The seeder writes a **full datetime** into `_event_date` deliberately, with a
documented rationale (a bare `Y-m-d` made the upsert comparison mismatch on every
run). A second branch ten lines below writes the canonical form:

```php
// :1011-1020 — "…`_event_date` is contractually `Y-m-d` — the runtime's
// recurrence evaluator validates exactly that shape — so this branch
// writes a date, never a datetime."
if ( isset( $pair['event_offset'] ) ) {
    $pt_meta['_event_date'] = gmdate( 'Y-m-d', strtotime( … ) );
}
```

The two branches disagree with each other, and the first wins whenever a fixture
declares no `event_offset`. The fixture that produces post `115352` —

```php
// scripts/data/ci-fixture-events.php:551-556
array(
    'pt_slug' => 'evento-removido-na-fonte',
    'type'    => 'event',
    'title'   => 'Evento sintético removido na fonte',
    'county'  => 'Cork',
    'town'    => 'Cork',
    'offset'  => 0,
    'hidden'  => 'source_not_found',
),
```

— declares `offset` but **no `event_offset`**, so it takes the datetime branch.
That is the path that produced `2026-11-13 00:00:00`.

**It is neither a Stage 1 nor a Stage 2 regression.** Nothing in the importer
writes this shape, and the seeder's datetime branch predates both stages. The
violation has always been possible from this script.

## 6. Are other fields affected?

| Field | Observed | Verdict |
|---|---|---|
| `_event_end_date` | 0 of 3,134 values carry a time component; absent on all 20 affected posts | **clean** |
| `_event_start_time` | `H:i` values (`09:00`, `19:30`, …, some empty) | by design — a time field, not a violation |
| `_event_end_time` | `H:i` values (`00:30` … `23:59`, some empty) | by design — not a violation |

## 7. Consequence in the runtime, and its practical reach

`conexao-event-runtime/includes/class-event-recurrence.php:189,239,263` and
`class-event-query.php:313` read `_event_date`; the recurrence evaluator validates
the `Y-m-d` shape, exactly as the seeder's own comment warns. Whether any of the
20 renders as upcoming, past or invalid in a public query was **not** measured
here — that is a P9/quality item, not a Stage 3.5 item.

Practical reach at capture: **20 posts, all synthetic CI fixtures, 0 real
imported events.** The real import path is clean.

## 8. Follow-up recommendation (for whichever stage owns P9 / quality cleanup)

1. **Fix the source, not the data.** Change `scripts/bootstrap-ci-fixtures.php`
   lines 996-1001 so `_event_date` is always `Y-m-d`, and preserve the
   deterministic upsert property it was introduced for by making the *comparison*
   tolerant rather than the *stored value* malformed. Do not "repair" the 20
   existing posts as part of this task.
2. **Make the contract enforceable.** A gate asserting
   `_event_date` / `_event_end_date` match `^\d{4}-\d{2}-\d{2}$` on every `event`
   post would have caught this class at fixture-bootstrap time.
3. **Reclassify post `115352` as a fixture artefact, not an event-status
   question.** Its `source_not_found` value is the fixture's intent
   (`'hidden' => 'source_not_found'`), not evidence of a reconciliation defect.
   It needs no restoration decision and it is correctly outside the 80-event
   cohort.
4. **Exclude the 20 from any future "suppressed events" inventory.** Their
   `_event_status` values (`published`, `rejected`, `expired`, `source_not_found`)
   are fixture design, not operational outcome.

## 9. What this task did not do

No violation was fixed. No code was changed. Post `115352` was not modified. No
data-migration-style correction was performed.

