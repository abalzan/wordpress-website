# Run-log transcript

The per-run `apply-run-*.txt` / `dry-run*.txt` files written during the first
part of this task were lost from the working tree (they were untracked and the
workspace was restored between sessions). Their exact `summary:` lines are
transcribed here from the session output; the re-runnable evidence
(`full-test-run.txt`, `lint.txt`, `script-contract.txt`, `dry-run-final.txt`,
`verify-final.txt`, the snapshots) is present as files.

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

## Owner-supplied batch 3

```
dry run            : manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=1, written=0, unchanged=161, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, with_before=161, with_after=161, missing_after=133, gate=0, errors=0
apply 6 (final)    : manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=0, written=1, unchanged=161, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, with_before=161, with_after=162, missing_after=132, gate=0, errors=0
apply 7 (idempot.) : manifest_entries=294, populate_entries=162, preserve_entries=0, review_entries=132, planned=0, written=0, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=162, with_before=162, with_after=162, missing_after=132, gate=0, errors=0
verify             : published_leisure=294, with_coordinates=162, verified_flag_set=0, missing_coordinates=132, invalid_coordinates=0, errors=0
```

(The batch-3 apply wrote 1 post — Athlone Castle — because the 18 other accepted
rows had already been written by an intermediate apply of the same manifest;
the dry run reported `planned=1` and the apply wrote exactly that 1.)

## Owner-supplied batch 4

```
dry run            : manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=21, written=0, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, with_before=162, with_after=162, missing_after=132, gate=0, errors=0
apply 8 (final)    : manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=0, written=21, unchanged=162, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, with_before=162, with_after=183, missing_after=111, gate=0, errors=0
apply 9 (idempot.) : manifest_entries=294, populate_entries=183, preserve_entries=0, review_entries=111, planned=0, written=0, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=183, with_before=183, with_after=183, missing_after=111, gate=0, errors=0
verify             : published_leisure=294, with_coordinates=183, verified_flag_set=0, missing_coordinates=111, invalid_coordinates=0, errors=0
```

### Independent corroboration of the Croagh Patrick car park

The Murrisk Development Association publishes the Croagh Patrick trailhead as
`53.77935, -9.63700`. The owner's Sport Ireland car-park coordinate
(`53.778110, -9.639078`) is **194 m** from it — two independent sources inside
the 250 m rule, and the point visitors actually park at (the earlier pipeline
point was the summit, 2.6 km away).

## Owner-supplied batch 5 (Dublin-focused)

```
dry run             : manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=20, written=0, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, with_before=183, with_after=183, missing_after=111, gate=0, errors=0
apply 10 (final)    : manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=0, written=20, unchanged=183, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, with_before=183, with_after=203, missing_after=91, gate=0, errors=0
apply 11 (idempot.) : manifest_entries=294, populate_entries=203, preserve_entries=0, review_entries=91, planned=0, written=0, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=203, with_before=203, with_after=203, missing_after=91, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=203, verified_flag_set=0, missing_coordinates=91, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 6 (Cork)

```
dry run             : manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=3, written=0, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, with_before=203, with_after=203, missing_after=91, gate=0, errors=0
apply 12 (final)    : manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=0, written=3, unchanged=203, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, with_before=203, with_after=206, missing_after=88, gate=0, errors=0
apply 13 (idempot.) : manifest_entries=294, populate_entries=206, preserve_entries=0, review_entries=88, planned=0, written=0, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=206, with_before=206, with_after=206, missing_after=88, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=206, verified_flag_set=0, missing_coordinates=88, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 7 (Donegal + Monaghan)

```
dry run             : manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=4, written=0, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, with_before=206, with_after=206, missing_after=88, gate=0, errors=0
apply 14 (final)    : manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=0, written=4, unchanged=206, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, with_before=206, with_after=210, missing_after=84, gate=0, errors=0
apply 15 (idempot.) : manifest_entries=294, populate_entries=210, preserve_entries=0, review_entries=84, planned=0, written=0, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=210, with_before=210, with_after=210, missing_after=84, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=210, verified_flag_set=0, missing_coordinates=84, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 8 (Dublin + Wexford)

```
dry run             : manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=10, written=0, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, with_before=210, with_after=210, missing_after=84, gate=0, errors=0
apply 16 (final)    : manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=0, written=10, unchanged=210, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, with_before=210, with_after=220, missing_after=74, gate=0, errors=0
apply 17 (idempot.) : manifest_entries=294, populate_entries=220, preserve_entries=0, review_entries=74, planned=0, written=0, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=220, with_before=220, with_after=220, missing_after=74, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=220, verified_flag_set=0, missing_coordinates=74, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 9 (Sligo + Wicklow)

```
dry run             : manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=10, written=0, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, with_before=220, with_after=220, missing_after=74, gate=0, errors=0
apply 18 (final)    : manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=0, written=10, unchanged=220, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, with_before=220, with_after=230, missing_after=64, gate=0, errors=0
apply 19 (idempot.) : manifest_entries=294, populate_entries=230, preserve_entries=0, review_entries=64, planned=0, written=0, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=230, with_before=230, with_after=230, missing_after=64, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=230, verified_flag_set=0, missing_coordinates=64, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 10 (Clare + Cork)

```
dry run             : manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=4, written=0, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, with_before=230, with_after=230, missing_after=64, gate=0, errors=0
apply 20 (final)    : manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=0, written=4, unchanged=230, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, with_before=230, with_after=234, missing_after=60, gate=0, errors=0
apply 21 (idempot.) : manifest_entries=294, populate_entries=234, preserve_entries=0, review_entries=60, planned=0, written=0, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=234, with_before=234, with_after=234, missing_after=60, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=234, verified_flag_set=0, missing_coordinates=60, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 11 (Limerick + Longford + Monaghan)

```
dry run             : manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=7, written=0, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, with_before=234, with_after=234, missing_after=60, gate=0, errors=0
apply 22 (final)    : manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=0, written=7, unchanged=234, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, with_before=234, with_after=241, missing_after=53, gate=0, errors=0
apply 23 (idempot.) : manifest_entries=294, populate_entries=241, preserve_entries=0, review_entries=53, planned=0, written=0, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=241, with_before=241, with_after=241, missing_after=53, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=241, verified_flag_set=0, missing_coordinates=53, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 12 (Tipperary + Waterford)

```
dry run             : manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=8, written=0, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, with_before=241, with_after=241, missing_after=53, gate=0, errors=0
apply 24 (final)    : manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=0, written=8, unchanged=241, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, with_before=241, with_after=249, missing_after=45, gate=0, errors=0
apply 25 (idempot.) : manifest_entries=294, populate_entries=249, preserve_entries=0, review_entries=45, planned=0, written=0, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=249, with_before=249, with_after=249, missing_after=45, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=249, verified_flag_set=0, missing_coordinates=45, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 13 (Clare + Cork)

```
dry run             : manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=4, written=0, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, with_before=249, with_after=249, missing_after=45, gate=0, errors=0
apply 26 (final)    : manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=0, written=4, unchanged=249, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, with_before=249, with_after=253, missing_after=41, gate=0, errors=0
apply 27 (idempot.) : manifest_entries=294, populate_entries=253, preserve_entries=0, review_entries=41, planned=0, written=0, unchanged=253, conflicts=0, invalid=0, unresolved=0, match_stable_id=253, with_before=253, with_after=253, missing_after=41, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=253, verified_flag_set=0, missing_coordinates=41, invalid_coordinates=0, errors=0
```

## Owner-supplied batch 14 (Donegal + Kildare)

```
dry run             : manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=6, written=0, unchanged=253, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, with_before=253, with_after=253, missing_after=41, gate=0, errors=0
apply 28 (final)    : manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=6, unchanged=253, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, with_before=253, with_after=259, missing_after=35, gate=0, errors=0
apply 29 (idempot.) : manifest_entries=294, populate_entries=259, preserve_entries=0, review_entries=35, planned=0, written=0, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=1, match_stable_id=259, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=259, verified_flag_set=0, missing_coordinates=35, invalid_coordinates=0, errors=0
```

## Provenance-text repair (defect found during batch-14 verification)

```
dry run             : manifest_entries=294, populate_entries=259, review_entries=35, planned=0, written=0, unchanged=67, owner_approved=1, provenance_repaired=0, provenance_planned=192, with_before=259, with_after=259, gate=0, errors=0
apply 30 (repair)   : manifest_entries=294, populate_entries=259, review_entries=35, planned=0, written=0, unchanged=67, owner_approved=1, provenance_repaired=192, provenance_planned=0, with_before=259, with_after=259, gate=0, errors=0
apply 31 (idempot.) : manifest_entries=294, populate_entries=259, review_entries=35, planned=0, written=0, unchanged=259, owner_approved=1, provenance_repaired=0, provenance_planned=0, with_before=259, with_after=259, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=259, verified_flag_set=0, missing_coordinates=35, invalid_coordinates=0, errors=0
```

Before the repair, owner-supplied rows carried a pipeline provenance string that
contradicted the stored point (e.g. Cliffs of Moher: "two-source agreement;
4370 m apart" while the stored point was the owner's visitor centre). After the
repair, pipeline rows keep the two-source text and owner rows carry
`owner: ...` (with `[owner-approved at MEDIUM confidence]` for Mondello Park).

## Owner-supplied batch 15 (Kilkenny + Monaghan)

```
dry run             : manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=6, written=0, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, with_before=259, with_after=259, missing_after=35, gate=0, errors=0
apply 32 (final)    : manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=0, written=6, unchanged=259, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, with_before=259, with_after=265, missing_after=29, gate=0, errors=0
apply 33 (idempot.) : manifest_entries=294, populate_entries=265, preserve_entries=0, review_entries=29, planned=0, written=0, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=5, provenance_repaired=0, provenance_planned=0, match_stable_id=265, with_before=265, with_after=265, missing_after=29, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=265, verified_flag_set=0, missing_coordinates=29, invalid_coordinates=0, errors=0
```

`owner_approved=5` = Mondello Park (batch 14) + Kells Priory, Carrickmacross
Lace Gallery, Lough Muckno and Monaghan County Museum (batch 15). Each was
accepted by the owner at MEDIUM or MEDIUM-HIGH rather than HIGH, so MEDIUM is
recorded and the owner's approval is what permits the write.

## Owner-supplied batch 16 (nine 2-attraction counties)

```
dry run             : manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=16, written=0, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, with_before=265, with_after=265, missing_after=29, gate=0, errors=0
apply 34 (final)    : manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=0, written=16, unchanged=265, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, with_before=265, with_after=281, missing_after=13, gate=0, errors=0
apply 35 (idempot.) : manifest_entries=294, populate_entries=281, preserve_entries=0, review_entries=13, planned=0, written=0, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=12, provenance_repaired=0, provenance_planned=0, match_stable_id=281, with_before=281, with_after=281, missing_after=13, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=281, verified_flag_set=0, missing_coordinates=13, invalid_coordinates=0, errors=0
```

`owner_approved=12` = Mondello Park (batch 14) + the four batch-15 rows + the
seven batch-16 rows the owner accepted at MEDIUM / MEDIUM-HIGH. The Carlingford
Lough Greenway entry carries the owner decision verbatim:

```
OWNER DECISION (resolved): Carlingford Lough Greenway uses CARLINGFORD MARINA as
its representative entry point (Carlingford-side trailhead, Sport Ireland
greenway listing). OMEATH PIER (54.088969, -6.257057) is deliberately NOT used.
```

Rock of Dunamase (10623) and Achill Island (10748) were left at REVIEW and hold
no coordinate, per the owner's explicit review markers.

## Final owner batch (the 8 remaining attractions)

```
dry run             : manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=5, written=0, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, with_before=281, with_after=281, missing_after=13, gate=0, errors=0
apply 36 (final)    : manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=0, written=5, unchanged=281, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, with_before=281, with_after=286, missing_after=8, gate=0, errors=0
apply 37 (idempot.) : manifest_entries=294, populate_entries=286, preserve_entries=0, review_entries=8, planned=0, written=0, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=14, provenance_repaired=0, provenance_planned=0, match_stable_id=286, with_before=286, with_after=286, missing_after=8, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=286, verified_flag_set=0, missing_coordinates=8, invalid_coordinates=0, errors=0
```

Rock of Dunamase (10623) provenance, stored verbatim:

```
OWNER DECISION (resolved): Rock of Dunamase uses OPTION A - the visitor car park
/ arrival point. OPTION B (the monument itself, 53.031783, -7.210743) is
explicitly REJECTED and is not used; the alternative archaeological point
53.031263, -7.210757 was also not used.
```

Still at REVIEW, with candidates recorded and no coordinate written:
Altamont Gardens (10879), Annes Grove Gardens (20905), Achill Island (10748).

## FINAL reconciliation — the three confirmed owner decisions

```
dry run             : manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=3, written=0, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, with_before=286, with_after=286, missing_after=8, gate=0, errors=0
apply 38 (FINAL)    : manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=3, unchanged=286, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, with_before=286, with_after=289, missing_after=5, gate=0, errors=0
apply 39 (idempot.) : manifest_entries=294, populate_entries=289, preserve_entries=0, review_entries=5, planned=0, written=0, unchanged=289, conflicts=0, invalid=0, unresolved=0, owner_approved=17, provenance_repaired=0, provenance_planned=0, match_stable_id=289, with_before=289, with_after=289, missing_after=5, gate=0, errors=0
verify              : published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0, errors=0
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
