# IVVCC Stage D — Recovery / Production Meta Completion Report

> **Date (UTC):** 2026-10-09
> **Status:** BLOCKED — meta transfer could not be completed (see §B and status block).
> **Scope:** Production (https://conexaobr.ie) event recovery. No IVVCC event was
> created or deleted during this recovery session, and no non-IVVCC event was modified.

---

## Status block

```
IVVCC STAGE D — RECOVERY STATUS

Production baseline:                           32
Current production count:                      43
Intended IVVCC events:                          7
Canonical IVVCC events with complete meta:      0
Duplicates positively identified:               4
Duplicates safely removed:                      0
XML-RPC meta transfer:                        BLOCKED  (HTTP 429 on xmlrpc.php)
Frontend QA:                                  BLOCKED  (events still have no meta)
Filter QA:                                    BLOCKED  (same root cause)
Mobile QA:                                    BLOCKED  (same root cause)
Non-IVVCC regression:                           PASS    (no non-IVVCC event touched; spot-checked)
Overall Stage D:                              BLOCKED
```

**Per the STATUS RULE, Stage D is NOT PASS.** The 7 canonical IVVCC events exist
on production with correct title/slug/content but **zero** `_event_*` meta, and
the meta-transfer channel (XML-RPC) is rate-limited while the alternative
(REST) is blocked by a plugin defect fixed in this repo but not yet deployed.

---

## A. Production inventory (Phase A)

### A.1 The 7 intended IVVCC events on production

All 7 exist on production with the exact exported title/slug/content. **None of
them carries any `_event_*` meta** (verified via REST `context=edit` reads).

| # | Post ID | Export UUID | Title | Slug | IVVCC source URL | Export source_id |
|---|---------|-------------|-------|------|------------------|------------------|
| 1 | **11673** | b58c1155-3229-4be6-b511-d82e8e8e977e | IVVCC 11th Brass Brigade Run | `ivvcc-11th-brass-brigade-run` | https://www.ivvcc.ie/events/ivvcc-11th-brass-brigade-run/ | 555047 |
| 2 | **11675** | bc2b057b-38c4-45e4-9f10-3f1ebacc84cb | Muskerry Vintage Club | `muskerry-vintage-club` | https://www.ivvcc.ie/events/muskerry-vintage-club-27/ | 558190 |
| 3 | **11677** | 8c5a9c49-406c-41e5-b5f0-8a38e22b10ca | Blessington Vintage Car and Motorcycle Club (Autumn) | `blessington-vintage-car-and-motorcycle-club` | https://www.ivvcc.ie/events/blessington-vintage-car-and-motorcycle-club-5/ | 559757 |
| 4 | **11679** | dc88e1f9-38b1-4d7e-baf9-dd90f2dba583 | RIAC/IVVCC Cars and Breakfast | `riac-ivvcc-cars-and-breakfast` | https://www.ivvcc.ie/events/riac-ivvcc-cars-and-breakfast-4/ | 559933 |
| 5 | **11681** | 847e7e02-a208-4b85-b1ad-aec1182c4d1e | Connacht Veteran & Vintage Motor Club | `connacht-veteran-vintage-motor-club` | https://www.ivvcc.ie/events/connacht-veteran-vintage-motor-club-2/ | 555174 |
| 6 | **11683** | d7cd55a6-c89a-4edf-b1c6-803f271be6b2 | Kingdom Veteran Vintage and Classic Car Club | `kingdom-veteran-vintage-and-classic-car-club` | https://www.ivvcc.ie/events/kingdom-veteran-vintage-and-classic-car-club-6/ | 559382 |
| 7 | **11685** | 10ae4f6b-2053-47ca-b427-bf1d709846ec | Blessington Vintage Car and Motorcycle Club (Dec Mince Pie) | `blessington-vintage-car-and-motorcycle-club-2` | https://www.ivvcc.ie/events/blessington-vintage-car-and-motorcycle-club-6/ | 559758 |

Identity proof: the exported `post.slug` matches the production slug **and**
the rendered content matches the exported content on every one of the 7
(`/tmp/ivvcc-stage-d-prod-snapshot.json`, captured 2026-10-09). `_event_export_uuid`
is empty on all 7, so UUID matching could never have worked on production.

### A.2 All event posts without `_event_*` meta (REST inventory, 2026-10-09)

| Post ID | Slug | Title | Role |
|---------|------|-------|------|
| 11673, 11675, 11677, 11679, 11681, 11683, 11685 | (see A.1) | (see A.1) | **Canonical intended events (7)** |
| **11692** | `blessington-vintage-car-and-motorcycle-club-3` | Blessington Vintage Car and Motorcycle Club | **Duplicate of 11677** |
| **11693** | `riac-ivvcc-cars-and-breakfast-2` | RIAC/IVVCC Cars and Breakfast | **Duplicate of 11679** |
| **11695** | `kingdom-veteran-vintage-and-classic-car-club-2` | Kingdom Veteran Vintage and Classic Car Club | **Duplicate of 11683** |
| **11696** | `blessington-vintage-car-and-motorcycle-club-2-2` | Blessington Vintage Car and Motorcycle Club | **Duplicate of 11685** |

Duplicate proof is **exact content + slug-prefix match**, never slug-suffix
alone: e.g. 11692 has the same rendered content as 11677
("…BVCMC Autumn Run…"), 11696 the same content as 11685 ("…December, Mince Pie
Run"), and each duplicate's canonical counterpart owns the clean export slug.

### A.3 Reconciliation of 43 against 32 + 7

```
32  baseline events (Eventbrite, all with full meta — untouched)
+ 7  intended IVVCC events (correct titles/slugs/content, zero meta)
+ 4  duplicate IVVCC events (created by failed dedup, zero meta)
= 43 production total ✓
```

**Where the extra 4 came from:** the production import tooling could never
persist event meta (REST 403, see §B.2). Its dedup order is
UUID → source+source_id → URL → content(title+date); with every matcher
dependent on meta fields that were silently rejected, **every import pass looked
like a fresh event** and WordPress minted new posts with auto-suffixed slugs
(`-2`, `-3`, `-2-2`). The 4 extra posts are the visible residue of that failure.
## B. XML-RPC capability test (Phase B)

### B.1 Result: BLOCKED (HTTP 429 persistent rate limit)

Per the agreed policy (**one write, wait 60–120 s, verify; never parallelize**),
a single-event capability test was prepared
(`scripts/ivvcc-stage-d-phase-b.py`, test post **11673**) and conservative
probes were spaced across the whole recovery window:

| # | UTC (approx.) | Call | Result |
|---|---------------|------|--------|
| 1 | 14:0x | `wp.getPost(11673)` | **HTTP 429 Too Many Requests** |
| 2 | 15:1x | `wp.getPost(11673)` (fixed probe) | **HTTP 429** |
| 3 | 15:2x | `wp.getPost(11673)` (pre-cooldown attempt) | **HTTP 429** |
| 4 | 15:3x | `wp.getPost(11673)` | **HTTP 429** |
| 5 | 15:5x | `wp.getPost(11673)` | **HTTP 429** |
| 6 | 16:0x | `wp.getPost(11673)` (final probe) | **HTTP 429** (one run surfaced a transient `Fault`; re-probe → 429) |

No `wp.editPost` was ever attempted — the read probe itself is rate-limited, so
a write could not be validated safely. Slug suffixes were never used to infer
the identity of any post (Rule 4).

### B.2 Why the alternative (REST) also fails — root cause found

The prompt assumed `show_in_rest: false`. The live REST schema shows the
opposite: every `_event_*` key **is** REST-visible on production (they appear in
`meta` responses). The real blocker is that all `_event_*` keys are
**protected** (underscore-prefixed) and `conexao-event-runtime` (≤ 1.1.0)
registers them with `show_in_rest => true` but **no `auth_callback`**. WordPress
core defaults protected-meta REST writes to `__return_false`, so every write is
rejected even for administrators:

```
POST /wp-json/wp/v2/event/11673  {"meta":{"_event_source":"ivvcc",...}}
→ 403 rest_cannot_update  "Sorry, you are not allowed to edit the _event_source custom field."
(GET/POST on title works with the same credentials — the lock is meta-only.)
```

This is the documented, known pattern the codebase already solves for
`_agency_*`/`_employer_*` meta in the data-model plugin
(docs/plugins/conexao-data-model.md § "Agency Meta & REST"): protected meta needs
an explicit `auth_callback` → `current_user_can('edit_post', $object_id)`.
No full importer has been re-run during this recovery session.
### B.3 Remediation prepared in this repo (code only, not yet deployed)

1. **`conexao-event-runtime` 1.2.0** — added the missing `auth_callback`
   (same convention as data-model) to both the string and integer meta loops,
   and registered `_event_export_uuid` (the export-format identity key the
   import tooling writes for cross-instance dedup).
   `wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php`
   (+ `docs/plugins/conexao-event-runtime.md` section "REST writes (v1.2.0)").
   Verified: `php -l` clean; runtime tests — recurrence **90/90**, query
   **40/40**; plugin-separation shows only 6 pre-existing environment-mode
   failures (importer still active in local container) identical on pristine
   code.
2. **Rebuilt `dist/conexao-event-runtime.zip`** (verified to contain the fix).
3. **`scripts/ivvcc-stage-d-phase-c.py`** — REST **update-only** meta transfer
   for exactly the 7 canonical post IDs (allowlisted), values straight from
   `dist/ivvcc-only-export.json`, dry-run by default, per-post BEFORE/AFTER
   verification, no create/no delete. **Dry-run executed and PASSING** against
   live production (before-state read OK, slug guard OK on all 7, planned writes
   exactly match the export).

### B.4 Operator steps to unblock (deploy → run → verify)

```bash
# 1. Upload dist/conexao-event-runtime.zip on production, activate (replaces 1.1.0).
# 2. Spot-check REST write capability on one event: POST
#    {"meta":{"_event_source":"ivvcc"}} to /wp-json/wp/v2/event/11673
#    -> expect 200, not 403.
# 3. Apply full meta for the 7 canonical events (this is Phase C):
#      python3 scripts/ivvcc-stage-d-phase-c.py --apply
#    (dry-run first: python3 scripts/ivvcc-stage-d-phase-c.py)
# 4. Re-run the verification matrix (§D) using the Phase C audit log.
```

---

## C. Required meta set (Phase C) — validated, ready, NOT applied

The complete per-event write set (only fields present in the validated local
export; nothing invented; absent fields stay absent) was validated by the Phase
C dry-run. Summary of the applicable keys per event:

| Field | 11673 | 11675 | 11677 | 11679 | 11681 | 11683 | 11685 |
|-------|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|
| `_event_date` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_end_date` | ✓ | – | – | – | – | ✓ | – |
| `_event_start_time` | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| `_event_end_time` | ✓ | – | – | ✓ | – | – | – |
| `_event_time` | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ |
| `_event_venue` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_location` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_address` | – | ✓ | – | ✓ | – | – | ✓ |
| `_event_map_url` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_url` + `_event_source_url` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_organizer` | ✓ | ✓ | – | ✓ | ✓ | ✓ | – |
| `_event_price` | – | – | – | ✓ | – | – | – |
| `_event_status` (published) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_source`/`_event_source_id` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_import_date`/`_event_last_checked` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_imported` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `_event_export_uuid` (derived) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Rows intentionally `–` match the export exactly (e.g. no address for the Brass
Brigade, no price except RIAC, Blessington Dec carries no organizer). **No
guessed source URLs** — `_event_url` values are the exported IVVCC URLs verbatim.

## D. Post-write verification (Phase D) — template, not yet executable

**Current matrix (as of 2026-10-09, before transfer):**

| Post ID | Title | Slug correct | Meta present | Meta required | Public URL |
|---|---|---|---|---|---|
| 11673 | IVVCC 11th Brass Brigade Run | ✓ | 0/18 | 18 | 200 |
| 11675 | Muskerry Vintage Club | ✓ | 0/17 | 17 | 200 |
| 11677 | Blessington Vintage Car and Motorcycle Club | ✓ | 0/15 | 15 | 200 |
| 11679 | RIAC/IVVCC Cars and Breakfast | ✓ | 0/19 | 19 | 200 |
| 11681 | Connacht Veteran & Vintage Motor Club | ✓ | 0/16 | 16 | 200 |
| 11683 | Kingdom Veteran Vintage and Classic Car Club | ✓ | 0/15 | 15 | 200 |
| 11685 | Blessington Vintage Car and Motorcycle Club | ✓ | 0/16 | 16 | 200 |

Each row's required meta set matches the export exactly (keys present in the
export + `_event_export_uuid`). After §B.4, `python3 scripts/ivvcc-stage-d-phase-c.py --apply`
turns the `0/N` cells into per-field PASS checks recorded in its JSON audit log.

Expected checks per canonical event (post ID, title, slug, every meta field,
no duplicate created, values == export, public URL `200`). Public-singles
baseline today: **all 7 return HTTP 200** at `/eventos/{slug}/`. The full
PASS/FAIL matrix will be produced by `ivvcc-stage-d-phase-c.py --apply` (after
§B.4) — it records before/after state and a per-field verification for every
event in its JSON audit log. **No event currently has complete meta, so the
matrix is pending.**

## E. Duplicate cleanup (Phase E) — mapped but deferred

Identity for the 4 duplicates is **positive** (content + slug mapping, §A.2),
so they are safe to delete **after** the 7 canonical events have complete
verified meta — the Phase E preamble condition. Nothing was deleted.

| Deleted-ID (pending) | Slug | Title | Duplicate-of | Reason |
|---|---|---|---|---|
| 11692 (pending) | `blessington-vintage-car-and-motorcycle-club-3` | Blessington Vintage Car and Motorcycle Club | 11677 | identical content (Autumn Run) |
| 11693 (pending) | `riac-ivvcc-cars-and-breakfast-2` | RIAC/IVVCC Cars and Breakfast | 11679 | identical content |
| 11695 (pending) | `kingdom-veteran-vintage-and-classic-car-club-2` | Kingdom Veteran Vintage and Classic Car Club | 11683 | identical content |
| 11696 (pending) | `blessington-vintage-car-and-motorcycle-club-2-2` | Blessington Vintage Car and Motorcycle Club | 11685 | identical content (Mince Pie Run) |

Never used slug suffixes alone (`-2`, `-3`) as proof — decided by exact content
equality against the canonical counterpart and by the canonical owning the
clean export slug.

## F. Final production verification (Phase F) — BLOCKED

- Total event count: **43** (unchanged; no create/delete this session).
- Non-IVVCC regression: **PASS** — no non-IVVCC event modified; spot inventory
  before/after identical (all 32 Eventbrite IDs present, meta untouched).
- IVVCC meta completeness: **0/7**.
- Frontend/filter/mobile QA: **BLOCKED** — cannot be meaningfully QAed while the
  7 intended events have no dates/status meta (the `_event_status` public gate
  and the archive date filter have nothing to operate on).
- Importer install/deactivate status: production stays on the documented set
  (data-model + content + admin-ux + event-runtime); no change made this session.

## Mutation log (this session, production)

| # | UTC (approx.) | Action | Target | Before | After | Status |
|---|-----|--------|--------|--------|-------|--------|
| 1 | 14:0x | REST title set test + immediate revert | post 11673 | `IVVCC 11th Brass Brigade Run` | same (reverted) | Reverted; verified identical in every later read |
| 2–10 | 14:0x–16:0x | REST reads (inventory, snapshot, dry-run) + 7× XML-RPC probes (6× 429, 1 interrupted) | all 11 IVVCC posts | — | — | Read-only |

No other mutation was performed. The Phase C dry-run performed **no writes**.

## Files/artifacts in this repo

- `scripts/ivvcc-stage-d-phase-b.py` — single-event XML-RPC capability test.
- `scripts/ivvcc-stage-d-phase-c.py` — REST update-only meta transfer + verify.
- `wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php` — v1.2.0
  auth_callback + `_event_export_uuid`.
- `docs/plugins/conexao-event-runtime.md` — "REST writes (v1.2.0)" section.
- `dist/conexao-event-runtime.zip` — rebuilt with the fix (14.4 KB).

---
*Report prepared by the recovery session. Stage D remains BLOCKED until the
event-runtime 1.2.0 ZIP is deployed on production and Phase C–F can run.*