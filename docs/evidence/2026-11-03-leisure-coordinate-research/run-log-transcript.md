# Run-log transcript

One authoritative run log for the Lazer coordinate work, so the evidence
directory holds a single record instead of ~80 near-identical stdout snippets.

The `dry-run-*.txt` / `apply-run-*.txt` / `verify-after-*.txt` / `verify-final*.txt`
captures are reproduced **byte-for-byte** in the consolidated section below (each
block is headed by the filename it came from). Those individual files were
removed once this consolidation existed; nothing else changed.

Four early runs (the two-source pipeline batch, the post-correction run and the
first owner batch) predate the first saved capture — their raw files were lost
when the untracked workspace was restored between sessions, so they are
transcribed from the session output in the first section.


## Earlier runs — raw captures lost (transcribed from session output)

These four runs predate the first saved capture; their exact `summary:` lines
were transcribed from the session output. Every later run has a raw capture and
is reproduced verbatim in the consolidated run log below.

## Pipeline batch (two-source research)

```
dry run            : manifest_entries=294, populate_entries=76, preserve_entries=0, review_entries=218, planned=76, written=0, unchanged=0, conflicts=0, invalid=0, unresolved=0, match_stable_id=76, with_before=0, with_after=0, missing_after=294, gate=0, errors=0
apply 1            : manifest_entries=294, populate_entries=76, preserve_entries=0, review_entries=218, planned=0, written=75, unchanged=1, conflicts=0, invalid=0, unresolved=0, match_stable_id=76, with_before=0, with_after=75, missing_after=219, gate=0, errors=0
apply 2 (idempot.) : manifest_entries=294, populate_entries=76, preserve_entries=0, review_entries=218, planned=0, written=0, unchanged=76, conflicts=0, invalid=0, unresolved=0, match_stable_id=76, with_before=75, with_after=75, missing_after=219, gate=0, errors=0
verify             : published_leisure=294, with_coordinates=75, verified_flag_set=0, missing_coordinates=219, invalid_coordinates=0, errors=0
```

A direct `SELECT COUNT(*)` on `_leisure_lat`/`_leisure_lng` returned **0**
immediately after the dry run.

## After the site-level classification correction (8 posts cleared)

```
dry run            : manifest_entries=294, populate_entries=67, preserve_entries=0, review_entries=227, planned=0, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=67, with_before=67, with_after=67, missing_after=227, gate=0, errors=0
apply 3            : manifest_entries=294, populate_entries=67, preserve_entries=0, review_entries=227, planned=0, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=67, with_before=67, with_after=67, missing_after=227, gate=0, errors=0
verify             : published_leisure=294, with_coordinates=67, verified_flag_set=0, missing_coordinates=227, invalid_coordinates=0, errors=0
```

## Owner-supplied batch

```
dry run            : manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=76, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, with_before=67, with_after=67, missing_after=227, gate=0, errors=0
apply 4 (final)    : manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=0, written=76, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, with_before=67, with_after=143, missing_after=151, gate=0, errors=0
apply 5 (idempot.) : manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=0, written=0, unchanged=143, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, with_before=143, with_after=143, missing_after=151, gate=0, errors=0
verify             : published_leisure=294, with_coordinates=143, verified_flag_set=0, missing_coordinates=151, invalid_coordinates=0, errors=0
```

## Failure proofs

```
$ apply-leisure-coordinates.php --manifest=… --apply            (no --confirm-production)
ERROR: refusing to apply against target "http://localhost:8080" (classified as local) without --confirm-production.   exit 1

$ apply-leisure-coordinates.php --manifest=scripts/data/leisure-coordinates-selftest.json --dry-run
  #10147 dublin-castle: WOULD SET (53.3429, -6.2672) [stable-id]
  does-not-exist: UNRESOLVED — no leisure record for uuid= slug=does-not-exist title=Nope
  (dry run — nothing written)
summary: manifest_entries=3, populate_entries=2, preserve_entries=0, review_entries=1, planned=1, written=0, unchanged=0, conflicts=0, invalid=0, unresolved=1, match_stable_id=1, with_before=0, with_after=0, missing_after=293, gate=0, errors=1   exit 1
```

Note: the `UNRESOLVED` line gained its bracketed error code (`UNRESOLVED [uuid_ambiguous] — …`) when the resolver learned to disambiguate a shared
UUID. The line above is the pre-repair format; the current format is in
`docs/evidence/2026-11-03-leisure-coordinate-research/` → the consolidated run
log's `dry-run-uuid-ambiguity-proof.txt` block.


## Consolidated run log — verbatim stdout of every run

Every `dry-run-*.txt`, `apply-run-*.txt`, `verify-after-*.txt` and
`verify-final*.txt` capture from this task, reproduced byte-for-byte and in run
order. These 75 one-line captures were consolidated into this single file so the
evidence directory holds one authoritative run log instead of 75 near-identical
snippets. Each block is the exact `summary:` (or plan) output of the named run.

### `dry-run-batch3.txt`

```text
summary: manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=1, written=0, unchanged=161, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, match_slug=0, match_title=0, with_before=161, with_after=161, missing_after=133, gate=0, errors=0
```

### `verify-after-batch3.txt`

```text
summary: published_leisure=294, with_coordinates=162, verified_flag_set=0, missing_coordinates=132, invalid_coordinates=0, errors=0
```

### `dry-run-batch4.txt`

```text
summary: manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=21, written=0, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, match_slug=0, match_title=0, with_before=162, with_after=162, missing_after=132, gate=0, errors=0
```

### `apply-run-4-owner-batch.txt`

```text
summary: manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=0, written=76, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, match_slug=0, match_title=0, with_before=67, with_after=143, missing_after=151, gate=0, errors=0
```

### `verify-after-batch4.txt`

```text
summary: published_leisure=294, with_coordinates=183, verified_flag_set=0, missing_coordinates=111, invalid_coordinates=0, errors=0
```

### `dry-run-batch5.txt`

```text
summary: manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=20, written=0, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, match_slug=0, match_title=0, with_before=183, with_after=183, missing_after=111, gate=0, errors=0
```

### `apply-run-5-owner-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=0, written=0, unchanged=143, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, match_slug=0, match_title=0, with_before=143, with_after=143, missing_after=151, gate=0, errors=0
```

### `verify-after-batch5.txt`

```text
summary: published_leisure=294, with_coordinates=203, verified_flag_set=0, missing_coordinates=91, invalid_coordinates=0, errors=0
```

### `dry-run-batch6.txt`

```text
summary: manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=3, written=0, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, match_slug=0, match_title=0, with_before=203, with_after=203, missing_after=91, gate=0, errors=0
```

### `apply-run-6-batch3.txt`

```text
summary: manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=0, written=1, unchanged=161, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, match_slug=0, match_title=0, with_before=161, with_after=162, missing_after=132, gate=0, errors=0
```

### `verify-after-batch6.txt`

```text
summary: published_leisure=294, with_coordinates=206, verified_flag_set=0, missing_coordinates=88, invalid_coordinates=0, errors=0
```

### `dry-run-batch7.txt`

```text
summary: manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=4, written=0, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, match_slug=0, match_title=0, with_before=206, with_after=206, missing_after=88, gate=0, errors=0
```

### `apply-run-7-batch3-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=0, written=0, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, match_slug=0, match_title=0, with_before=162, with_after=162, missing_after=132, gate=0, errors=0
```

### `verify-after-batch7.txt`

```text
summary: published_leisure=294, with_coordinates=210, verified_flag_set=0, missing_coordinates=84, invalid_coordinates=0, errors=0
```

### `dry-run-batch8.txt`

```text
summary: manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=10, written=0, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, match_slug=0, match_title=0, with_before=210, with_after=210, missing_after=84, gate=0, errors=0
```

### `apply-run-8-batch4.txt`

```text
summary: manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=0, written=21, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, match_slug=0, match_title=0, with_before=162, with_after=183, missing_after=111, gate=0, errors=0
```

### `verify-after-batch8.txt`

```text
summary: published_leisure=294, with_coordinates=220, verified_flag_set=0, missing_coordinates=74, invalid_coordinates=0, errors=0
```

### `dry-run-batch9.txt`

```text
summary: manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=10, written=0, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, match_slug=0, match_title=0, with_before=220, with_after=220, missing_after=74, gate=0, errors=0
```

### `apply-run-9-batch4-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=0, written=0, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, match_slug=0, match_title=0, with_before=183, with_after=183, missing_after=111, gate=0, errors=0
```

### `verify-after-batch9.txt`

```text
summary: published_leisure=294, with_coordinates=230, verified_flag_set=0, missing_coordinates=64, invalid_coordinates=0, errors=0
```

### `dry-run-batch10.txt`

```text
summary: manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=4, written=0, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, match_slug=0, match_title=0, with_before=230, with_after=230, missing_after=64, gate=0, errors=0
```

### `apply-run-10-batch5.txt`

```text
summary: manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=0, written=20, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, match_slug=0, match_title=0, with_before=183, with_after=203, missing_after=91, gate=0, errors=0
```

### `verify-after-batch10.txt`

```text
summary: published_leisure=294, with_coordinates=234, verified_flag_set=0, missing_coordinates=60, invalid_coordinates=0, errors=0
```

### `dry-run-batch11.txt`

```text
summary: manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=7, written=0, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, match_slug=0, match_title=0, with_before=234, with_after=234, missing_after=60, gate=0, errors=0
```

### `apply-run-11-batch5-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=0, written=0, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, match_slug=0, match_title=0, with_before=203, with_after=203, missing_after=91, gate=0, errors=0
```

### `verify-after-batch11.txt`

```text
summary: published_leisure=294, with_coordinates=241, verified_flag_set=0, missing_coordinates=53, invalid_coordinates=0, errors=0
```

### `dry-run-batch12.txt`

```text
summary: manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=8, written=0, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, match_slug=0, match_title=0, with_before=241, with_after=241, missing_after=53, gate=0, errors=0
```

### `apply-run-12-batch6.txt`

```text
summary: manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=0, written=3, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, match_slug=0, match_title=0, with_before=203, with_after=206, missing_after=88, gate=0, errors=0
```

### `verify-after-batch12.txt`

```text
summary: published_leisure=294, with_coordinates=249, verified_flag_set=0, missing_coordinates=45, invalid_coordinates=0, errors=0
```

### `dry-run-batch13.txt`

```text
summary: manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=4, written=0, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, match_slug=0, match_title=0, with_before=249, with_after=249, missing_after=45, gate=0, errors=0
```

### `apply-run-13-batch6-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=0, written=0, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, match_slug=0, match_title=0, with_before=206, with_after=206, missing_after=88, gate=0, errors=0
```

### `verify-after-batch13.txt`

```text
summary: published_leisure=294, with_coordinates=253, verified_flag_set=0, missing_coordinates=41, invalid_coordinates=0, errors=0
```

### `dry-run-batch14.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=6, written=0, unchanged=253, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, match_slug=0, match_title=0, with_before=253, with_after=253, missing_after=41, gate=0, errors=0
```

### `apply-run-14-batch7.txt`

```text
summary: manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=0, written=4, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, match_slug=0, match_title=0, with_before=206, with_after=210, missing_after=84, gate=0, errors=0
```

### `verify-after-batch14.txt`

```text
summary: published_leisure=294, with_coordinates=259, verified_flag_set=0, missing_coordinates=35, invalid_coordinates=0, errors=0
```

### `dry-run-batch15.txt`

```text
summary: manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=6, written=0, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, match_slug=0, match_title=0, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
```

### `apply-run-15-batch7-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=0, written=0, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, match_slug=0, match_title=0, with_before=210, with_after=210, missing_after=84, gate=0, errors=0
```

### `verify-after-batch15.txt`

```text
summary: published_leisure=294, with_coordinates=265, verified_flag_set=0, missing_coordinates=29, invalid_coordinates=0, errors=0
```

### `dry-run-batch16.txt`

```text
summary: manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=16, written=0, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, match_slug=0, match_title=0, with_before=265, with_after=265, missing_after=29, gate=0, errors=0
```

### `apply-run-16-batch8.txt`

```text
summary: manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=0, written=10, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, match_slug=0, match_title=0, with_before=210, with_after=220, missing_after=74, gate=0, errors=0
```

### `verify-after-batch16.txt`

```text
summary: published_leisure=294, with_coordinates=281, verified_flag_set=0, missing_coordinates=13, invalid_coordinates=0, errors=0
```

### `dry-run-batch17.txt`

```text
summary: manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=5, written=0, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, match_slug=0, match_title=0, with_before=281, with_after=281, missing_after=13, gate=0, errors=0
```

### `apply-run-17-batch8-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=0, written=0, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, match_slug=0, match_title=0, with_before=220, with_after=220, missing_after=74, gate=0, errors=0
```

### `verify-after-batch17.txt`

```text
summary: published_leisure=294, with_coordinates=286, verified_flag_set=0, missing_coordinates=8, invalid_coordinates=0, errors=0
```

### `apply-run-18-batch9.txt`

```text
summary: manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=0, written=10, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, match_slug=0, match_title=0, with_before=220, with_after=230, missing_after=64, gate=0, errors=0
```

### `apply-run-19-batch9-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=0, written=0, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, match_slug=0, match_title=0, with_before=230, with_after=230, missing_after=64, gate=0, errors=0
```

### `apply-run-20-batch10.txt`

```text
summary: manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=0, written=4, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, match_slug=0, match_title=0, with_before=230, with_after=234, missing_after=60, gate=0, errors=0
```

### `apply-run-21-batch10-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=0, written=0, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, match_slug=0, match_title=0, with_before=234, with_after=234, missing_after=60, gate=0, errors=0
```

### `apply-run-22-batch11.txt`

```text
summary: manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=0, written=7, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, match_slug=0, match_title=0, with_before=234, with_after=241, missing_after=53, gate=0, errors=0
```

### `apply-run-23-batch11-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=0, written=0, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, match_slug=0, match_title=0, with_before=241, with_after=241, missing_after=53, gate=0, errors=0
```

### `apply-run-24-batch12.txt`

```text
summary: manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=0, written=8, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, match_slug=0, match_title=0, with_before=241, with_after=249, missing_after=45, gate=0, errors=0
```

### `apply-run-25-batch12-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=0, written=0, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, match_slug=0, match_title=0, with_before=249, with_after=249, missing_after=45, gate=0, errors=0
```

### `apply-run-26-batch13.txt`

```text
summary: manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=0, written=4, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, match_slug=0, match_title=0, with_before=249, with_after=253, missing_after=41, gate=0, errors=0
```

### `apply-run-27-batch13-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=0, written=0, unchanged=253, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, match_slug=0, match_title=0, with_before=253, with_after=253, missing_after=41, gate=0, errors=0
```

### `apply-run-28-batch14.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=6, unchanged=253, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, match_slug=0, match_title=0, with_before=253, with_after=259, missing_after=35, gate=0, errors=0
```

### `apply-run-29-batch14-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=0, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, match_slug=0, match_title=0, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
```

### `apply-run-30-provenance-repair.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, owner_approved=1, provenance_repaired=192, provenance_planned=0, match_stable_id=259, match_slug=0, match_title=0, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
```

### `apply-run-31-provenance-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=0, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=1, provenance_repaired=0, provenance_planned=0, match_stable_id=259, match_slug=0, match_title=0, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
```

### `apply-run-32-batch15.txt`

```text
summary: manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=0, written=6, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, match_slug=0, match_title=0, with_before=259, with_after=265, missing_after=29, gate=0, errors=0
```

### `apply-run-33-batch15-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=0, written=0, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, match_slug=0, match_title=0, with_before=265, with_after=265, missing_after=29, gate=0, errors=0
```

### `apply-run-34-batch16.txt`

```text
summary: manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=0, written=16, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, match_slug=0, match_title=0, with_before=265, with_after=281, missing_after=13, gate=0, errors=0
```

### `apply-run-35-batch16-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=0, written=0, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, match_slug=0, match_title=0, with_before=281, with_after=281, missing_after=13, gate=0, errors=0
```

### `apply-run-36-batch17.txt`

```text
summary: manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=0, written=5, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, match_slug=0, match_title=0, with_before=281, with_after=286, missing_after=8, gate=0, errors=0
```

### `apply-run-37-batch17-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=0, written=0, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, match_slug=0, match_title=0, with_before=286, with_after=286, missing_after=8, gate=0, errors=0
```

### `apply-run-38-final.txt`

```text
summary: manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=3, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, match_slug=0, match_title=0, with_before=286, with_after=289, missing_after=5, gate=0, errors=0
```

### `apply-run-39-final-idempotence.txt`

```text
summary: manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=0, unchanged=289, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, match_slug=0, match_title=0, with_before=289, with_after=289, missing_after=5, gate=0, errors=0
```

### `apply-run-40-uuid-repair.txt`

```text
summary: manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=0, unchanged=289, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=288, match_stable_id_slug=1, match_stable_id_lang=0, match_slug=0, match_title=0, with_before=289, with_after=289, missing_after=5, gate=0, errors=0
```

### `dry-run-final.txt`

```text
summary: manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=3, written=0, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, match_slug=0, match_title=0, with_before=286, with_after=286, missing_after=8, gate=0, errors=0
```

### `dry-run-owner-batch.txt`

```text
summary: manifest_entries=294, populate_entries=143, preserve_entries=0, review_entries=151, planned=76, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, match_slug=0, match_title=0, with_before=67, with_after=67, missing_after=227, gate=0, errors=0
```

### `dry-run-provenance-repair.txt`

```text
summary: manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=0, unchanged=67, conflicts=0, invalid=0, unresolved=0, owner_approved=1, provenance_repaired=0, provenance_planned=192, match_stable_id=259, match_slug=0, match_title=0, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
```

### `dry-run-uuid-ambiguity-proof.txt`

```text
  does-not-exist: UNRESOLVED [unresolved] — no leisure record for uuid= slug=does-not-exist title=Nope
  Phoenix Park (ambiguous UUID, no slug): UNRESOLVED [uuid_ambiguous] — uuid=ci-fixture-0000-0000-4000-8000-000000000002 matches 2 leisure records (10150,115357) and the entry (slug=- lang=-) does not disambiguate them; nothing was written.
summary: manifest_entries=2, populate_entries=2, preserve_entries=0, review_entries=0, planned=0, written=0, unchanged=0, conflicts=0, invalid=0, unresolved=2, owner_approved=0, provenance_repaired=0, provenance_planned=0, match_stable_id=0, match_stable_id_slug=0, match_stable_id_lang=0, match_slug=0, match_title=0, with_before=289, with_after=289, missing_after=5, gate=0, errors=2
```

### `dry-run-uuid-repair.txt`

```text
summary: manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=0, unchanged=289, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=288, match_stable_id_slug=1, match_stable_id_lang=0, match_slug=0, match_title=0, with_before=289, with_after=289, missing_after=5, gate=0, errors=0
```

### `verify-after-owner-batch.txt`

```text
summary: published_leisure=294, with_coordinates=143, verified_flag_set=0, missing_coordinates=151, invalid_coordinates=0, errors=0
```

### `verify-after-uuid-repair.txt`

```text
summary: published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0, errors=0
```

### `verify-final.txt`

```text
summary: published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0, errors=0
```

## Owner decisions recorded verbatim in provenance

Carlingford Lough Greenway (10873):

```
OWNER DECISION (resolved): Carlingford Lough Greenway uses CARLINGFORD MARINA as
its representative entry point (Carlingford-side trailhead, Sport Ireland
greenway listing). OMEATH PIER (54.088969, -6.257057) is deliberately NOT used.
```

Rock of Dunamase (10623):

```
OWNER DECISION (resolved): Rock of Dunamase uses OPTION A - the visitor car park
/ arrival point. OPTION B (the monument itself, 53.031783, -7.210743) is
explicitly REJECTED and is not used; the alternative archaeological point
53.031263, -7.210757 was also not used.
```

## FINAL STATE — 289 / 289 unique attractions populated (100%)

Posts without coordinates (none is a real attraction):

```
22734  ph3c-test-lazer-alfa        CI fixture
22737  ph3c-test-lazer-sem-condado CI fixture
22738  ph3c-test-lazer-zeta        CI fixture
22739  ph3c-test-lazer-externo     CI fixture
115357 phoenix-park-en             EN sibling of 10150 (shares _leisure_export_uuid)
```

Invariants held at every step: `_leisure_coord_verified = 0` on all records; no
non-coordinate field changed in the 294-record snapshot diff; no production
write, deploy, upload or activation at any point; the duplicate Phoenix Park
`_leisure_export_uuid` was never modified.
