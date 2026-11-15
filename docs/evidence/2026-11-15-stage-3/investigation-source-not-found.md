# Read-only investigation — the current `source_not_found` population

**Task:** Stage 3 of the Event Importer roadmap, §3.9.
**Nature:** read-only investigation. No status was changed, no record was
modified, no upstream request was made.
**Investigation timestamp (fixed):** `2026-11-15T18:20:00+00:00`
**Effective site timezone:** `Europe/Dublin` (offset +00:00 on this date).

---

## 1. The capture, and its verified digest

The population investigated is the 106-event capture taken by the Stage 2
post-implementation verification:

```
docs/evidence/2026-11-15-stage-2-verification/01a-source-not-found-inventory.tsv
```

| Property | Value |
|---|---|
| Rows (no header row) | 106 |
| Columns | `post_id`, `title`, `_event_source`, `_event_source_id`, `URL`, `_event_status` |
| Stored status in the capture | `source_not_found` for all 106 rows |
| **Full SHA-256** | `f1d3eda8e4f1f0f6fbb1e10ca0a237bb715246282adc0f8d3c18475fdd3c74c4` |
| Method | `sha256sum` over the exact bytes of the committed file |

### 1.1 Digest verification result — EXACT MATCH, full digest available

The Stage 2 verification evidence does contain a **full 64-character digest**,
not only an abbreviated prefix. `docs/evidence/2026-11-15-stage-2-verification/01-readonly-transaction-and-preservation.md`
line 80 records it in full:

```
f1d3eda8e4f1f0f6fbb1e10ca0a237bb715246282adc0f8d3c18475fdd3c74c4
```

The Stage 3 computation is **byte-for-byte identical**. The abbreviated prefix
`f1d3eda8…` quoted in the Stage 2 verification report (lines 249 and 413) and in
`01c-cohort-fingerprints.txt` line 220 is the first 8 hex characters of the same
value.

**Conclusion:** the capture under investigation is exactly the capture the
Stage 2 verification recorded. No prefix-only limitation applies.

---

## 2. Fixed investigation timestamp and site timezone

| Item | Value | How it was established |
|---|---|---|
| Investigation timestamp | `2026-11-15T18:20:00+00:00` | Fixed before any classification query and used for every event, so classifications are reproducible and cannot drift mid-investigation. |
| `wp_options.timezone_string` | `Europe/Dublin` | `SELECT option_name, option_value FROM wordpress.wp_options WHERE option_name IN ('timezone_string','gmt_offset')` inside the read-only transaction. |
| `wp_options.gmt_offset` | present, empty string | Same query. Empty means WordPress derives the offset from `timezone_string`. |
| Offset in force on the investigation date | **+00:00 (GMT)** | Ireland observes IST (UTC+1) only in summer time; 15 November is GMT. |

**How the comparison uses it.** `_event_date` and `_event_end_date` store
date-only values (§3), which carry no offset, so they are compared as **local
calendar dates in `Europe/Dublin`**. The fixed timestamp's local calendar date is
also `2026-11-15`, and because the offset on that date is exactly +00:00 there is
no local-vs-UTC ambiguity in this comparison.

No WordPress bootstrap was performed against the shared database; the timezone
was read with `SELECT` only.

---

## 3. The verified date-storage contract

Read from the code, not assumed:

* `includes/class-event-importer.php:2168` writes `'_event_date' => $normalized['start_date']`.
* `includes/class-event-importer.php:2171` writes `'_event_end_date' => $normalized['end_date']`.
* `includes/class-event-normalizer.php:39-40` produces both through `normalize_date()`.
* `includes/class-event-normalizer.php:245-284` documents and implements
  `normalize_date()` as **`Y-m-d` only** — an already-`Y-m-d` value is returned
  unchanged, anything else is parsed and re-emitted with `date( 'Y-m-d', $ts )`.
* `wp-content/plugins/conexao-data-model/includes/class-meta.php:10` registers
  `_event_date` as meta type `date`.

**Contract:** both keys hold **date-only, local, timezone-less calendar dates**.
No datetime-with-offset semantics exist.

**Observed deviations in the 106-record set (read-only `SELECT`):**

| Format observed | Count | Treatment |
|---|---|---|
| `YYYY-MM-DD` (canonical) | 210 of 211 date values | Used as-is. |
| `YYYY-MM-DD HH:MM:SS` | **1** — post `115352`, `_event_date = 2026-11-13 00:00:00` | Storage format is **inconsistent with the verified contract**, so it is classified **Indeterminate** rather than guessed at. Post `115352` carries **no** `_event_end_date`, so there is no canonical value to fall back to. Note: the date part `2026-11-13` is before the investigation date under either reading, so this record is **not** a current/future candidate whichever way it is interpreted. |

---

## 4. Current status of every captured record

For each of the 106 post IDs, a read-only `SELECT` of `wp_posts.post_status` and
of `wp_postmeta` for `_event_status`, `_event_date`, `_event_end_date`,
`_event_source`, `_event_source_id`.

| Current status | Count |
|---|---|
| **POST MISSING** — the post ID no longer exists in `wp_posts` | **0** |
| **STILL SUPPRESSED** — post exists and `_event_status` is exactly `source_not_found` | **106** |
| **NO LONGER SUPPRESSED** — post exists, metadata is another value or absent | **0** |
| **INDETERMINATE STATUS** — metadata unreadable or uninterpretable | **0** |

All 106 post IDs still exist and all 106 still carry
`_event_status = source_not_found`. **Nothing in the captured population changed
status since the Stage 2 capture**, consistent with the fact that the last import
activity on the shared site was at `2026-11-15 14:10:57` (before the capture) and
the overdue continuation events in `wp_options.cron` never fired (see the
Stage 3 evidence file, §"attribution").

`wp_posts.post_status` is a different fact from `_event_status` and is reported
separately:

| `wp_posts.post_status` | Count |
|---|---|
| `publish` | 106 |

Every record is therefore a *published* post row whose event-status metadata says
`source_not_found`. Suppression is enforced by `_event_status` (read by the Event
Runtime visibility rules), not by moving the post to draft.

---

## 5. Per-source current-status counts

| `_event_source` | Still suppressed | No longer suppressed | Post missing | Indeterminate |
|---|---:|---:|---:|---:|
| `eventbrite_meath` | 22 | 0 | 0 | 0 |
| `eventbrite_laois` | 21 | 0 | 0 | 0 |
| `eventbrite_kerry` | 15 | 0 | 0 | 0 |
| `eventbrite_mayo` | 14 | 0 | 0 | 0 |
| `eventbrite_westmeath` | 7 | 0 | 0 | 0 |
| `eventbrite_clare` | 6 | 0 | 0 | 0 |
| `eventbrite_cork` | 5 | 0 | 0 | 0 |
| `eventbrite_leitrim` | 5 | 0 | 0 | 0 |
| `eventbrite_cork_events_tomorrow` | 5 | 0 | 0 | 0 |
| `eventbrite_offaly` | 4 | 0 | 0 | 0 |
| `eventbrite_mayo_events_tomorrow` | 1 | 0 | 0 | 0 |
| *(empty `_event_source`)* | 1 | 0 | 0 | 0 |
| **Total** | **106** | **0** | **0** | **0** |

---

## 6. Date categorisation of the records that are STILL suppressed

Rule: use `_event_end_date` when it holds a valid canonical value, otherwise
`_event_date`. **Past** = earlier than `2026-11-15` (local); **current or
future** = on or after `2026-11-15`; **Indeterminate** = missing, invalid,
unparseable, or stored in a format inconsistent with the contract.

| `_event_source` | Past | Current/future | Indeterminate |
|---|---:|---:|---:|
| `eventbrite_meath` | 0 | **22** | 0 |
| `eventbrite_laois` | 4 | **17** | 0 |
| `eventbrite_kerry` | 8 | **7** | 0 |
| `eventbrite_mayo` | 0 | **14** | 0 |
| `eventbrite_westmeath` | 2 | **5** | 0 |
| `eventbrite_clare` | 1 | **5** | 0 |
| `eventbrite_cork` | 1 | **4** | 0 |
| `eventbrite_leitrim` | 1 | **4** | 0 |
| `eventbrite_offaly` | 2 | **2** | 0 |
| `eventbrite_cork_events_tomorrow` | **5** | 0 | 0 |
| `eventbrite_mayo_events_tomorrow` | 1 | 0 | 0 |
| *(empty `_event_source`)* | 0 | 0 | **1** |
| **Total** | **25** | **80** | **1** |

**80 of 106** currently suppressed events carry a current-or-future end date.
The 25 past ones are expected behaviour under the current reconciliation rule (a
genuinely past event marked `source_not_found` is not a defect); the single
indeterminate value is the inconsistent stored format disclosed in §3.

---

## 7. Events still suppressed with a CURRENT OR FUTURE date

80 records. `wp_posts.post_status` is `publish` for every one of them, and
`_event_status` is `source_not_found` for every one of them (stated here once
rather than repeated in all 80 rows). These are **investigation candidates
only**: a local future date is not proof the event is still listed upstream.

| post_id | source | `_event_source_id` | date used | field |
|---|---|---|---|---|
| 13317 | `eventbrite_laois` | 1999760457360 | 2026-12-30 | `_event_end_date` |
| 13538 | `eventbrite_laois` | 2000231885414 | 2026-11-18 | `_event_end_date` |
| 16652 | `eventbrite_cork` | 1991047895854 | 2026-11-21 | `_event_end_date` |
| 16661 | `eventbrite_cork` | 1999724885965 | 2026-12-18 | `_event_end_date` |
| 16693 | `eventbrite_cork` | 1985055080181 | 2026-11-28 | `_event_end_date` |
| 16763 | `eventbrite_cork` | 1999630911886 | 2027-04-29 | `_event_end_date` |
| 18544 | `eventbrite_clare` | 1999094730152 | 2026-11-17 | `_event_end_date` |
| 19530 | `eventbrite_leitrim` | 1994510412334 | 2027-02-06 | `_event_end_date` |
| 19566 | `eventbrite_leitrim` | 1993153044410 | 2026-11-20 | `_event_end_date` |
| 19836 | `eventbrite_mayo` | 1996531470373 | 2027-04-18 | `_event_end_date` |
| 19936 | `eventbrite_meath` | 1978736470048 | 2026-12-04 | `_event_end_date` |
| 19958 | `eventbrite_meath` | 1989844122335 | 2026-12-05 | `_event_end_date` |
| 19962 | `eventbrite_meath` | 1992561732781 | 2026-12-18 | `_event_end_date` |
| 19977 | `eventbrite_meath` | 1994939462635 | 2026-12-29 | `_event_end_date` |
| 19993 | `eventbrite_meath` | 1994940122609 | 2027-01-09 | `_event_end_date` |
| 19997 | `eventbrite_meath` | 1999386148793 | 2026-12-13 | `_event_end_date` |
| 20041 | `eventbrite_meath` | 1997685572323 | 2026-11-21 | `_event_end_date` |
| 20043 | `eventbrite_meath` | 1997682121000 | 2026-11-27 | `_event_end_date` |
| 20151 | `eventbrite_offaly` | 1998485272247 | 2026-11-25 | `_event_end_date` |
| 20486 | `eventbrite_westmeath` | 1998491474799 | 2026-12-06 | `_event_end_date` |
| 20521 | `eventbrite_westmeath` | 1985907314236 | 2026-11-21 | `_event_end_date` |
| 21501 | `eventbrite_clare` | 2001780806278 | 2026-11-28 | `_event_end_date` |
| 133785 | `eventbrite_clare` | 2003790514369 | 2026-11-22 | `_event_end_date` |
| 133795 | `eventbrite_clare` | 2002934997495 | 2026-11-27 | `_event_end_date` |
| 133801 | `eventbrite_clare` | 2004472759984 | 2026-11-28 | `_event_end_date` |
| 136466 | `eventbrite_kerry` | 2001270964327 | 2026-11-15 | `_event_end_date` |
| 136478 | `eventbrite_kerry` | 2002996626830 | 2026-11-28 | `_event_end_date` |
| 136480 | `eventbrite_kerry` | 2002076121574 | 2026-11-21 | `_event_end_date` |
| 136484 | `eventbrite_kerry` | 2003610215089 | 2026-11-29 | `_event_end_date` |
| 136486 | `eventbrite_kerry` | 2002513229977 | 2026-11-21 | `_event_end_date` |
| 136521 | `eventbrite_kerry` | 2003156681558 | 2027-03-20 | `_event_end_date` |
| 136789 | `eventbrite_laois` | 2003140467060 | 2026-12-05 | `_event_end_date` |
| 136791 | `eventbrite_laois` | 2002429941860 | 2026-11-24 | `_event_end_date` |
| 136793 | `eventbrite_laois` | 2002865264923 | 2026-11-15 | `_event_end_date` |
| 136795 | `eventbrite_laois` | 2003764309991 | 2026-11-18 | `_event_end_date` |
| 136797 | `eventbrite_laois` | 2003334341945 | 2026-12-06 | `_event_end_date` |
| 136799 | `eventbrite_laois` | 2000005927568 | 2026-11-17 | `_event_end_date` |
| 136804 | `eventbrite_laois` | 2003142971551 | 2026-12-05 | `_event_end_date` |
| 136805 | `eventbrite_laois` | 2003706340603 | 2027-01-30 | `_event_end_date` |
| 136809 | `eventbrite_laois` | 2003359695779 | 2026-12-20 | `_event_end_date` |
| 136815 | `eventbrite_laois` | 2003675331855 | 2026-11-22 | `_event_end_date` |
| 136817 | `eventbrite_laois` | 2002551223617 | 2027-02-12 | `_event_end_date` |
| 136819 | `eventbrite_laois` | 2004480175163 | 2027-05-02 | `_event_end_date` |
| 136823 | `eventbrite_laois` | 2004124551483 | 2026-12-19 | `_event_end_date` |
| 136832 | `eventbrite_laois` | 2004529116548 | 2026-12-16 | `_event_end_date` |
| 136876 | `eventbrite_leitrim` | 2003016427053 | 2026-11-15 | `_event_end_date` |
| 137144 | `eventbrite_mayo` | 2004550943834 | 2026-12-06 | `_event_end_date` |
| 137146 | `eventbrite_mayo` | 2004543287935 | 2026-11-27 | `_event_end_date` |
| 137150 | `eventbrite_mayo` | 2004572571523 | 2026-12-17 | `_event_end_date` |
| 137152 | `eventbrite_mayo` | 2004550189578 | 2026-12-30 | `_event_end_date` |
| 137154 | `eventbrite_mayo` | 2001616412572 | 2027-04-04 | `_event_end_date` |
| 137156 | `eventbrite_mayo` | 2004472832200 | 2026-11-19 | `_event_end_date` |
| 137158 | `eventbrite_mayo` | 2003707632467 | 2026-11-27 | `_event_end_date` |
| 137160 | `eventbrite_mayo` | 2003396516912 | 2026-12-04 | `_event_end_date` |
| 137164 | `eventbrite_mayo` | 2004378487011 | 2026-11-19 | `_event_end_date` |
| 137170 | `eventbrite_mayo` | 2003960578034 | 2026-11-18 | `_event_end_date` |
| 137186 | `eventbrite_mayo` | 2003402071526 | 2026-11-18 | `_event_end_date` |
| 137275 | `eventbrite_mayo` | 2004436886686 | 2027-01-07 | `_event_end_date` |
| 137279 | `eventbrite_mayo` | 2004437759296 | 2027-01-07 | `_event_end_date` |
| 138444 | `eventbrite_kerry` | 2002996640872 | 2026-11-29 | `_event_end_date` |
| 138480 | `eventbrite_laois` | 2004593283473 | 2026-11-25 | `_event_end_date` |
| 138533 | `eventbrite_meath` | 2002290394470 | 2026-11-28 | `_event_end_date` |
| 138535 | `eventbrite_meath` | 2003605993462 | 2026-11-26 | `_event_end_date` |
| 138537 | `eventbrite_meath` | 1999086585792 | 2027-08-22 | `_event_end_date` |
| 138539 | `eventbrite_meath` | 2003651224750 | 2027-01-30 | `_event_end_date` |
| 138541 | `eventbrite_meath` | 2002419249880 | 2026-11-27 | `_event_end_date` |
| 138543 | `eventbrite_meath` | 2003684653737 | 2026-11-29 | `_event_end_date` |
| 138546 | `eventbrite_meath` | 2002457533387 | 2026-12-11 | `_event_end_date` |
| 138556 | `eventbrite_meath` | 2003617424653 | 2026-11-27 | `_event_end_date` |
| 138558 | `eventbrite_meath` | 2003684511311 | 2026-11-29 | `_event_end_date` |
| 138560 | `eventbrite_meath` | 1997686248345 | 2026-11-27 | `_event_end_date` |
| 138619 | `eventbrite_meath` | 2004129960662 | 2026-11-20 | `_event_end_date` |
| 138629 | `eventbrite_meath` | 2004549794396 | 2026-11-21 | `_event_end_date` |
| 138744 | `eventbrite_offaly` | 2003670834403 | 2026-11-18 | `_event_end_date` |
| 139040 | `eventbrite_westmeath` | 2003392268204 | 2026-12-04 | `_event_end_date` |
| 139042 | `eventbrite_westmeath` | 2004501666444 | 2026-11-28 | `_event_end_date` |
| 139082 | `eventbrite_westmeath` | 2003809042788 | 2026-11-25 | `_event_end_date` |
| 153701 | `eventbrite_leitrim` | 2004645103468 | 2026-11-20 | `_event_end_date` |
| 153980 | `eventbrite_meath` | 2004573696889 | 2026-11-29 | `_event_end_date` |
| 153984 | `eventbrite_meath` | 2002519530823 | 2026-12-06 | `_event_end_date` |
---

## 8. Active sources holding currently suppressed current/future events

Registry fields read from the `conexao_event_sources` option: `status`,
`last_import`, `last_import_status`, `last_error`, `events_imported`. All five
keys are **present** in every record listed below (none was absent), so the
"absent vs empty" distinction did not need to be applied here.

| `_event_source` | `status` | `last_import` | `last_import_status` | current/future suppressed |
|---|---|---|---|---:|
| `eventbrite_meath` | **active** | 2026-11-14 14:23:45 | `success` | 22 |
| `eventbrite_laois` | **active** | 2026-11-14 14:17:00 | `success` | 17 |
| `eventbrite_mayo` | **active** | 2026-11-14 14:23:24 | `error` | 14 |
| `eventbrite_kerry` | **active** | 2026-11-14 14:11:46 | `success` | 7 |
| `eventbrite_westmeath` | **active** | 2026-11-14 14:31:21 | `success` | 5 |
| `eventbrite_clare` | **active** | 2026-11-14 14:02:10 | `success` | 5 |
| `eventbrite_cork` | **active** | 2026-11-15 14:01:21 | `success` | 4 |
| `eventbrite_leitrim` | **active** | 2026-11-14 14:17:10 | `success` | 4 |
| `eventbrite_offaly` | **active** | 2026-11-14 14:24:51 | `success` | 2 |

### 8.1 Was each source's last import complete, incomplete or failed?

This is the crux of the finding, and it is the P12 defect Stage 3 fixes going
forward.

The registry records `success` for seven of the nine sources **even though their
last run left between 2 and 22 currently-live events suppressed**. The locally
retained evidence explains why:

* the 14:00–14:10 activity on 2026-11-15 was a chain of automatic
  continuations, each logged as
  `Continuation scheduled for … resumable at page N of 49 (rate_limited)` or
  `(time_budget)` — i.e. each source's enumeration stopped early;
* the corresponding Import History rows carry status `warning`, which is what
  pre-Stage-3 code wrote for an incomplete enumeration;
* the registry nevertheless stored `success`, because pre-Stage-3 code mapped
  every non-`failed` result to `success`.

| Source | Last-run verdict, from retained evidence |
|---|---|
| `eventbrite_mayo` | **failed** — registry `last_import_status=error`, `last_error` names `HTTP status: 429`. |
| `eventbrite_meath` | **incomplete** (recorded as `success`) — history `warning`, no `last_error`. |
| `eventbrite_laois` | **incomplete** (recorded as `success`) — history `warning`. |
| `eventbrite_kerry` | **incomplete** (recorded as `success`) — history `warning`. |
| `eventbrite_westmeath` | **incomplete** (recorded as `success`) — history `warning`. |
| `eventbrite_clare` | **incomplete** (recorded as `success`) — history `warning`. |
| `eventbrite_cork` | **incomplete** (recorded as `success`) — continuation resumed at page 38 of 49. |
| `eventbrite_leitrim` | **incomplete** (recorded as `success`) — same class. |
| `eventbrite_offaly` | **incomplete** (recorded as `success`) — same class. |

Also noted: `eventbrite_cork_events_tomorrow` has `last_import_status=error` with
`Failed to fetch events from Eventbrite — Cork (Tomorrow). HTTP status: 429.` but has
**no** current/future suppressed events, so it is not one of the nine above.

**No historical registry value was rewritten by this investigation.** From
Stage 3 onwards the same outcome is recorded as `partial` or `rate_limited` with
a named cause, and such a run is no longer presented as the "last successful
import".

**Bounded by retention:** the shared site retains 100 history rows, so per-source
last-run verdicts below that horizon cannot be reconstructed, and are not claimed.

---

## 9. What this investigation deliberately did not do

* **No event status was changed.** No post was restored to `published`, no
  `_event_status` value was written, no suppression was lifted.
* **No record was modified.** Every statement above comes from `SELECT` inside a
  `SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY;` session.
* **No upstream verification was performed.** No provider request was made to
  Eventbrite or any other source. Whether a candidate event is still listed
  upstream is explicitly out of scope.
* **No run-by-run reconciliation** between the 2026-10-03 population (18 events)
  and this one (106) was attempted. As Stage 2 established, the two are not
  like-for-like, and the retained history/logs cannot span the interval.
* **The historical Cavan events were not investigated.** They are not in the 106
  capture; their current state is a historical matter.
* **No claim is made that any suppression is a defect.** A past event marked
  `source_not_found` is expected behaviour under the current reconciliation rule.
  Only current/future events in `source_not_found` are candidates for concern.

---

## 10. Follow-up items that require separate authorisation

None of these is authorised by Stage 3, and Stage 3 implements no fix for any of
them.

1. **Restoration.** Decide whether any of the 80 current/future suppressed
   records should be restored, and by what mechanism. That is a content write on
   the shared database and needs its own task and authorisation.
2. **Upstream verification.** Confirm, per candidate, whether the event is still
   listed on Eventbrite. This requires live provider requests, which are
   forbidden in this task.
3. **Source-specific debugging.** Establish *why* each source's enumeration stops
   early. The continuations record a 49-page listing and repeated stalls between
   pages 4 and 40. Hypotheses that can only be tested with authorisation: per
   source page caps, the shared breaker cooling a 154-source walk, and the
   49-page ceiling the listing itself declares.
4. **Continuation recovery.** Fifteen continuation records on the shared site are
   `running` with `run_at = 0` and a `last_run_at` older than the documented stale
   threshold. Stage 3 makes them **visible** (Event Sources → Continuation
   diagnostics) but deliberately does not reset, retry or reschedule them.
5. **A proper population reconciliation.** If a run-by-run explanation of the
   18 → 106 growth is ever required, the importer's retention bounds must first
   be large enough to span the interval. Stage 3's group-aware retention is a
   prerequisite, but it does not reconstruct what has already been evicted.

---

## 11. Sign-off

* Read-only enforcement: `SET SESSION TRANSACTION READ ONLY; START TRANSACTION
  READ ONLY; SELECT @@transaction_read_only` returned **1** before every query
  batch in this investigation. The engine-level enforcement of the same session
  type was proven by the Stage 2 `ERROR 1792 (25006)` probe, which is referenced
  here and was **not** repeated.
* No write, no schema change, no account or privilege change.
* No WordPress bootstrap against the shared database.
* No event status change. **No restoration is authorised or performed.**
