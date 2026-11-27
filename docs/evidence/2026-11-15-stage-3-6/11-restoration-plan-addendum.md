# Restoration plan - Stage 3.6 addendum

**This file is a new addendum.** It does not modify, supersede or replace
Stage 3.5's `docs/evidence/2026-11-15-stage-3-5/05-restoration-plan.md`, which
remains exactly as committed.

## 1. What this addendum does and does not do

It updates only the **decision evidence** for the 76 HIGH-priority targets,
using the Stage 3.6 provider-fetch attempt.

* It does **not** recommend restoration, and it does **not** perform restoration.
* No event was restored, re-imported, re-published or re-statused. Every target is
  still `_event_status = source_not_found` (verified read-only at the end of the
  stage: 76 of 76).
* It does not authorise anything. Any restoration decision requires a separate,
  user-authorised task with fresh verification immediately before the action.

## 2. Result of the Stage 3.6 verification attempt

The verification could not be performed. The Offaly canary URL returned an AWS
WAF JavaScript-CAPTCHA challenge (`HTTP 405`, `x-amzn-waf-action: captcha`) on
all three requests, so no discovery listing was served; per section 5 the
remaining seven URLs were not requested. **All 76 targets therefore remain
VERIFICATION-INCOMPLETE** - the same decision state as after Stage 3.5, but now
supported by evidence for *why* they are still unverified rather than an
assumption.

| Classification | Count |
|---|---|
| VERIFIED-PRESENT | **0** |
| VERIFIED-ABSENT | **0** |
| VERIFICATION-INCOMPLETE | **76** |

| Source | Targets | VERIFIED-PRESENT | VERIFIED-ABSENT | VERIFICATION-INCOMPLETE |
|---|---|---|---|---|
| `eventbrite_meath` | 22 | 0 | 0 | **22** |
| `eventbrite_laois` | 17 | 0 | 0 | **17** |
| `eventbrite_mayo` | 14 | 0 | 0 | **14** |
| `eventbrite_kerry` | 7 | 0 | 0 | **7** |
| `eventbrite_clare` | 5 | 0 | 0 | **5** |
| `eventbrite_westmeath` | 5 | 0 | 0 | **5** |
| `eventbrite_leitrim` | 4 | 0 | 0 | **4** |
| `eventbrite_offaly` | 2 | 0 | 0 | **2** |
| **Total** | **76** | **0** | **0** | **76** |

## 3. Per-event classification (all 76 targets)

| # | post_id | Source | `_event_source_id` | `_event_date` | Classification |
|---|---|---|---|---|---|
| 1 | 13317 | `eventbrite_laois` | 1999760457360 | 2026-12-30 | **VERIFICATION-INCOMPLETE** |
| 2 | 13538 | `eventbrite_laois` | 2000231885414 | 2026-11-18 | **VERIFICATION-INCOMPLETE** |
| 3 | 18544 | `eventbrite_clare` | 1999094730152 | 2026-11-17 | **VERIFICATION-INCOMPLETE** |
| 4 | 19530 | `eventbrite_leitrim` | 1994510412334 | 2027-02-06 | **VERIFICATION-INCOMPLETE** |
| 5 | 19566 | `eventbrite_leitrim` | 1993153044410 | 2026-11-20 | **VERIFICATION-INCOMPLETE** |
| 6 | 19836 | `eventbrite_mayo` | 1996531470373 | 2027-04-16 | **VERIFICATION-INCOMPLETE** |
| 7 | 19936 | `eventbrite_meath` | 1978736470048 | 2026-12-04 | **VERIFICATION-INCOMPLETE** |
| 8 | 19958 | `eventbrite_meath` | 1989844122335 | 2026-12-05 | **VERIFICATION-INCOMPLETE** |
| 9 | 19962 | `eventbrite_meath` | 1992561732781 | 2026-12-18 | **VERIFICATION-INCOMPLETE** |
| 10 | 19977 | `eventbrite_meath` | 1994939462635 | 2026-12-29 | **VERIFICATION-INCOMPLETE** |
| 11 | 19993 | `eventbrite_meath` | 1994940122609 | 2027-01-09 | **VERIFICATION-INCOMPLETE** |
| 12 | 19997 | `eventbrite_meath` | 1999386148793 | 2026-12-13 | **VERIFICATION-INCOMPLETE** |
| 13 | 20041 | `eventbrite_meath` | 1997685572323 | 2026-11-21 | **VERIFICATION-INCOMPLETE** |
| 14 | 20043 | `eventbrite_meath` | 1997682121000 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 15 | 20151 | `eventbrite_offaly` | 1998485272247 | 2026-11-25 | **VERIFICATION-INCOMPLETE** |
| 16 | 20486 | `eventbrite_westmeath` | 1998491474799 | 2026-12-05 | **VERIFICATION-INCOMPLETE** |
| 17 | 20521 | `eventbrite_westmeath` | 1985907314236 | 2026-11-21 | **VERIFICATION-INCOMPLETE** |
| 18 | 21501 | `eventbrite_clare` | 2001780806278 | 2026-11-28 | **VERIFICATION-INCOMPLETE** |
| 19 | 133785 | `eventbrite_clare` | 2003790514369 | 2026-11-22 | **VERIFICATION-INCOMPLETE** |
| 20 | 133795 | `eventbrite_clare` | 2002934997495 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 21 | 133801 | `eventbrite_clare` | 2004472759984 | 2026-11-28 | **VERIFICATION-INCOMPLETE** |
| 22 | 136466 | `eventbrite_kerry` | 2001270964327 | 2026-11-15 | **VERIFICATION-INCOMPLETE** |
| 23 | 136478 | `eventbrite_kerry` | 2002996626830 | 2026-11-28 | **VERIFICATION-INCOMPLETE** |
| 24 | 136480 | `eventbrite_kerry` | 2002076121574 | 2026-11-21 | **VERIFICATION-INCOMPLETE** |
| 25 | 136484 | `eventbrite_kerry` | 2003610215089 | 2026-11-29 | **VERIFICATION-INCOMPLETE** |
| 26 | 136486 | `eventbrite_kerry` | 2002513229977 | 2026-11-21 | **VERIFICATION-INCOMPLETE** |
| 27 | 136521 | `eventbrite_kerry` | 2003156681558 | 2027-03-18 | **VERIFICATION-INCOMPLETE** |
| 28 | 136789 | `eventbrite_laois` | 2003140467060 | 2026-12-05 | **VERIFICATION-INCOMPLETE** |
| 29 | 136791 | `eventbrite_laois` | 2002429941860 | 2026-11-24 | **VERIFICATION-INCOMPLETE** |
| 30 | 136793 | `eventbrite_laois` | 2002865264923 | 2026-11-15 | **VERIFICATION-INCOMPLETE** |
| 31 | 136795 | `eventbrite_laois` | 2003764309991 | 2026-11-18 | **VERIFICATION-INCOMPLETE** |
| 32 | 136797 | `eventbrite_laois` | 2003334341945 | 2026-12-06 | **VERIFICATION-INCOMPLETE** |
| 33 | 136799 | `eventbrite_laois` | 2000005927568 | 2026-11-17 | **VERIFICATION-INCOMPLETE** |
| 34 | 136804 | `eventbrite_laois` | 2003142971551 | 2026-12-05 | **VERIFICATION-INCOMPLETE** |
| 35 | 136805 | `eventbrite_laois` | 2003706340603 | 2027-01-30 | **VERIFICATION-INCOMPLETE** |
| 36 | 136809 | `eventbrite_laois` | 2003359695779 | 2026-12-20 | **VERIFICATION-INCOMPLETE** |
| 37 | 136815 | `eventbrite_laois` | 2003675331855 | 2026-11-22 | **VERIFICATION-INCOMPLETE** |
| 38 | 136817 | `eventbrite_laois` | 2002551223617 | 2027-02-12 | **VERIFICATION-INCOMPLETE** |
| 39 | 136819 | `eventbrite_laois` | 2004480175163 | 2027-05-01 | **VERIFICATION-INCOMPLETE** |
| 40 | 136823 | `eventbrite_laois` | 2004124551483 | 2026-12-19 | **VERIFICATION-INCOMPLETE** |
| 41 | 136832 | `eventbrite_laois` | 2004529116548 | 2026-12-16 | **VERIFICATION-INCOMPLETE** |
| 42 | 136876 | `eventbrite_leitrim` | 2003016427053 | 2026-11-15 | **VERIFICATION-INCOMPLETE** |
| 43 | 137144 | `eventbrite_mayo` | 2004550943834 | 2026-12-06 | **VERIFICATION-INCOMPLETE** |
| 44 | 137146 | `eventbrite_mayo` | 2004543287935 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 45 | 137150 | `eventbrite_mayo` | 2004572571523 | 2026-12-16 | **VERIFICATION-INCOMPLETE** |
| 46 | 137152 | `eventbrite_mayo` | 2004550189578 | 2026-12-30 | **VERIFICATION-INCOMPLETE** |
| 47 | 137154 | `eventbrite_mayo` | 2001616412572 | 2027-04-01 | **VERIFICATION-INCOMPLETE** |
| 48 | 137156 | `eventbrite_mayo` | 2004472832200 | 2026-11-19 | **VERIFICATION-INCOMPLETE** |
| 49 | 137158 | `eventbrite_mayo` | 2003707632467 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 50 | 137160 | `eventbrite_mayo` | 2003396516912 | 2026-12-04 | **VERIFICATION-INCOMPLETE** |
| 51 | 137164 | `eventbrite_mayo` | 2004378487011 | 2026-11-16 | **VERIFICATION-INCOMPLETE** |
| 52 | 137170 | `eventbrite_mayo` | 2003960578034 | 2026-11-18 | **VERIFICATION-INCOMPLETE** |
| 53 | 137186 | `eventbrite_mayo` | 2003402071526 | 2026-11-18 | **VERIFICATION-INCOMPLETE** |
| 54 | 137275 | `eventbrite_mayo` | 2004436886686 | 2027-01-07 | **VERIFICATION-INCOMPLETE** |
| 55 | 137279 | `eventbrite_mayo` | 2004437759296 | 2027-01-07 | **VERIFICATION-INCOMPLETE** |
| 56 | 138444 | `eventbrite_kerry` | 2002996640872 | 2026-11-29 | **VERIFICATION-INCOMPLETE** |
| 57 | 138480 | `eventbrite_laois` | 2004593283473 | 2026-11-25 | **VERIFICATION-INCOMPLETE** |
| 58 | 138533 | `eventbrite_meath` | 2002290394470 | 2026-11-28 | **VERIFICATION-INCOMPLETE** |
| 59 | 138535 | `eventbrite_meath` | 2003605993462 | 2026-11-26 | **VERIFICATION-INCOMPLETE** |
| 60 | 138537 | `eventbrite_meath` | 1999086585792 | 2027-08-20 | **VERIFICATION-INCOMPLETE** |
| 61 | 138539 | `eventbrite_meath` | 2003651224750 | 2027-01-30 | **VERIFICATION-INCOMPLETE** |
| 62 | 138541 | `eventbrite_meath` | 2002419249880 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 63 | 138543 | `eventbrite_meath` | 2003684653737 | 2026-11-29 | **VERIFICATION-INCOMPLETE** |
| 64 | 138546 | `eventbrite_meath` | 2002457533387 | 2026-12-11 | **VERIFICATION-INCOMPLETE** |
| 65 | 138556 | `eventbrite_meath` | 2003617424653 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 66 | 138558 | `eventbrite_meath` | 2003684511311 | 2026-11-29 | **VERIFICATION-INCOMPLETE** |
| 67 | 138560 | `eventbrite_meath` | 1997686248345 | 2026-11-27 | **VERIFICATION-INCOMPLETE** |
| 68 | 138619 | `eventbrite_meath` | 2004129960662 | 2026-11-20 | **VERIFICATION-INCOMPLETE** |
| 69 | 138629 | `eventbrite_meath` | 2004549794396 | 2026-11-21 | **VERIFICATION-INCOMPLETE** |
| 70 | 138744 | `eventbrite_offaly` | 2003670834403 | 2026-11-18 | **VERIFICATION-INCOMPLETE** |
| 71 | 139040 | `eventbrite_westmeath` | 2003392268204 | 2026-12-04 | **VERIFICATION-INCOMPLETE** |
| 72 | 139042 | `eventbrite_westmeath` | 2004501666444 | 2026-11-28 | **VERIFICATION-INCOMPLETE** |
| 73 | 139082 | `eventbrite_westmeath` | 2003809042788 | 2026-11-25 | **VERIFICATION-INCOMPLETE** |
| 74 | 153701 | `eventbrite_leitrim` | 2004645103468 | 2026-11-20 | **VERIFICATION-INCOMPLETE** |
| 75 | 153980 | `eventbrite_meath` | 2004573696889 | 2026-11-29 | **VERIFICATION-INCOMPLETE** |
| 76 | 153984 | `eventbrite_meath` | 2002519530823 | 2026-12-06 | **VERIFICATION-INCOMPLETE** |

## 4. Why presence would still not be sufficient

Even if a provider listing had been served and contained an ID, that would prove
only listing membership at one instant. It would **not** establish:

* provider-side publication validity (the event may be a draft, cancelled or postponed);
* ticket availability or on-sale state;
* that the local record fields still match the provider record;
* suitability for restoration - local drift and the datetime-contract /
  deduplication concerns of Stages 3 and 3.5 still apply.

Presence is **necessary but not sufficient** for restoration. Absence from a
listing is likewise not proof that an event never existed or cannot reappear.

## 5. What a future verification run must satisfy

1. A fetch path that the provider's bot control accepts **without circumvention**.
2. The unchanged harness in `harness/`, so the isolation proof, the G8/G10
   guards, the request ledger and the classification rules are reused rather
   than re-implemented.
3. Fresh timestamps: every result is point-in-time, so verification must be
   repeated immediately before any restoration decision.
4. A separately authorised restoration task applying the six-step
   content-change contract (inventory, manifest, dry-run, snapshot, idempotent
   apply, verify + numeric gate) to whatever is being changed.

