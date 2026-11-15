# Item 4 — are the three "additive-only" files actually additive?

Method: read `git diff b5a3ee9..HEAD` for each file (the pre-Stage-2 SHA versus
HEAD), classify every hunk, and use the Stage 2 component-run evidence for the
Eventbrite regression suites. Reading the diff is the required standard for
`class-heritage-week-source.php`; the other two were also read line by line.

**Overall: two of the three are additive-only. The third is additive *in effect*
but is not literally "only state declarations" — one payload edge case changed
inside `enrich_event()`. Disclosed below; it does not affect any other handler
and does not change any stored value under the P5 write path.**

## 1. `includes/class-event-normalizer.php` — CONFIRMED additive

The whole diff is one inserted block (+19 lines, no deletions, no other hunk):

```php
$enrichment = ( isset( $raw['_enrichment'] ) && class_exists( 'Conexao_Event_Enrichment_State' ) )
    ? Conexao_Event_Enrichment_State::sanitize_map( $raw['_enrichment'] )
    : array();

if ( ! empty( $enrichment ) ) {
    $result['enrichment'] = $enrichment;
}
```

Checks:

| Check | Result |
|---|---|
| Any pre-existing `$result[...]` assignment changed? | **No** — the diff adds lines only, immediately before the `_ticket_show_ids` block, which is untouched. |
| Is the new key conditional? | **Yes** — `$result['enrichment']` exists only when the handler declared `_enrichment` **and** the sanitized map is non-empty. Every other handler produces a byte-identical payload. |
| Can a handler typo suppress a write? | **No** — `sanitize_map()` iterates only the canonical field list, drops unknown fields and unknown states, and `should_preserve()` returns `false` for any field absent from the map ("legacy / unspecified: write as before"). |
| Does the normalizer persist anything? | **No** — the state stays in the in-memory payload; no post meta key is added, so no existing reader of event meta changes. |
| Eventbrite regression suites (they exercise the normalizer without enrichment declarations) | **All pass** in the Stage 2 component run: `test-eventbrite-importer` 68/0, `test-eventbrite-rate-limit` 87/0, `test-eventbrite-api-contract` 124/0, `test-eventbrite-checkpoint` 91/0, `test-eventbrite-volatility` 77/0, `test-eventbrite-source-expansion` 65/0, `test-eventbrite-token-retirement` 57/0 (`docs/evidence/2026-11-15-stage-2/05-tests.txt`) |

The Stage 2 claim for this file is accurate.

## 2. `conexao-event-importer.php` — CONFIRMED additive (require only)

The entire diff is three added lines:

```php
// Stage 2 / P5 — per-field partial-enrichment state (additive; read by the
// normalizer and the importer's write path).
require_once CONEXAO_EVENT_IMPORTER_DIR . 'includes/class-conexao-event-enrichment-state.php';
```

No other hunk, no deleted line, no changed hook, constant, version or define. The
Stage 2 claim ("likely just the require statement") is accurate — and it is
exactly just the require statement.

## 3. `includes/sources/class-heritage-week-source.php` — CONFIRMED confined to `enrich_event()`, with ONE non-declaration change

The Stage 2 claim is "only `enrich_event()` state declarations". The diff is
indeed confined to `enrich_event()` — but it is not *only* declarations.

### 3.1 Scope check (all hunks inside `enrich_event()`)

`git diff -U0 b5a3ee9..HEAD -- <file> | grep '^@@'` yields 13 hunk headers at old
lines 862–981. `enrich_event()` spans lines 864–1058 at HEAD; the next function,
`fetch_detail_html()`, starts at 1059. **Every hunk is inside `enrich_event()`.**
Verified absent from the diff (no added or removed line matches):

| Protected area | Changed? |
|---|---|
| Stage 1 date eligibility (`is_definitely_past`, past-filter logic) | **No** |
| Pagination anchor selection (`extract_page_number`, `normalize_pagination_url`, page/anchor/checkpoint/offset/per_page lines) | **No** — the only diff lines containing the word "page" are comments about the *detail page* |
| `fetch_detail_html()` and the detail-fetch flow | **No** |
| `collect_img_candidates()`, `extract_srcset_urls()` | **No** |
| Listing parse / `fetch_events()` | **No** — separate methods, untouched |

### 3.2 What the diff actually does inside `enrich_event()`

Additive state declarations, exactly as claimed:

- every field starts `all_not_evaluated()`;
- a failed/empty detail fetch and an unparsable page set `all_unavailable()`;
- `venue`, `address`, `description`, `location`, `organizer`, `banner` each get
  `populated` / `empty` / `unavailable` as documented;
- the final map is `sanitize_map()`ed onto `$event['_enrichment']`;
- all of it is guarded by `class_exists( 'Conexao_Event_Enrichment_State' )`, so
  a missing class degrades to `array()` (no enrichment key) rather than erroring.

### 3.3 The one change that is not a state declaration

Organizer extraction was restructured:

```diff
-$org = $xpath->query( '//h3[contains(normalize-space(.), "Further Information")]/following-sibling::p[1]' );
-if ( $org && $org->length > 0 ) {
-    $event['organizer'] = trim( $org->item( 0 )->textContent );
+$org     = $xpath->query( '…same…' );
+$org_val = ( $org && $org->length > 0 ) ? trim( $org->item( 0 )->textContent ) : '';
+if ( '' !== $org_val ) {
+    $event['organizer'] = $org_val;
 }
```

Effect: when the "Further Information" `<p>` **exists but is empty/whitespace**,
the old code set `organizer => ''` (an explicit clear); the new code leaves the
key unset. `venue`, `address`, `description` and `location` keep their exact old
assignment behaviour (verified line by line, including the description case where
a present-but-blank `div.text-block` still writes `''`).

Assessment:

- **Payload change:** yes, in that one edge case (`organizer` key absent instead
  of `''`).
- **Stored-data change:** none under the P5 write path — the accompanying state
  for that case is `UNAVAILABLE`, and `should_preserve()` returns `true` for
  `UNAVAILABLE`, so the stored organizer is preserved either way.
- **Effect on other handlers:** none — the change is inside Heritage Week's own
  `enrich_event()`.
- **Consistency:** the new behaviour is what the P5 model intends ("absence of
  evidence is not a clear"). It is a deliberate improvement, not an accident.

### 3.4 Verdict on the claim

The Stage 2 statement "only `enrich_event()` state declarations" is **imprecise
but not misleading in effect**: the diff is confined to `enrich_event()`, the
protected Stage 1 behaviour (date filter, pagination anchor, detail-fetch flow)
is untouched, and no stored value changes because of the one payload nuance. It
is recorded here rather than escalated, because escalation is reserved for a
change that is "not obviously additive" — this one is obvious once the two lines
are read, and it is bounded to a single handler.

## 4. Summary table

| File | Claim | Verified | Note |
|---|---|---|---|
| `class-event-normalizer.php` | additive (declares enrichment state) | **YES** | +19 lines, conditional; non-declaring handlers byte-identical |
| `conexao-event-importer.php` | require only | **YES** | 3 added lines, nothing else |
| `class-heritage-week-source.php` | only `enrich_event()` state declarations | **YES for scope, NO for "only"** | all hunks inside `enrich_event()`; one organizer payload nuance in the empty-`<p>` edge case; date filter / pagination / detail flow untouched |

_Last verified: 2026-11-15 by the Stage 2 post-implementation verification_

