# G14 — Controlled plugin activation and importer run

| | |
|---|---|
| **Stage** | G14 — activate the supported source, run the importer, verify end to end |
| **Date** | 2026-10-05 |
| **Branch** | `master` |
| **Start SHA** | `30b2b3a` (clean tree) |
| **Predecessor** | [G13](2026-10-04-eventbrite-g13-operationalization.md) · [G12](2026-10-04-eventbrite-g12-volatility.md) |
| **Evidence** | [`evidence/eventbrite-g12/`](evidence/eventbrite-g12/) + `tests/experiments/observations/g14-*.json` |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |

---

## Status

# `PASS WITH LIMITATION`

G14 performed the write-capable execution the brief required, in a **positively
verified local environment**, and verified on live Eventbrite data that the whole
chain behaves: **activation → import → checkpoint → resume → completeness →
reconciliation safety → deduplication → truthful history**.

The limitation is the same one G12 inherited and G13 carried forward: the
volatility window was **114.6 minutes, not seven days**, so no *scheduled*
cadence could be validated — and per G13 none was invented. G14 therefore
exercised the importer **on demand**, which is the operating model G13 selected.
A second, smaller limitation is recorded honestly in §9: the first attempt at
run 1 was executed **twice concurrently** through a harness timeout, and that
race — my operational error, not an importer defect — produced **19 duplicate
local events**, which I then detected, removed and re-ran cleanly.

---

## §1 — Environment verification (§23)

The write boundary was proven **before** any source was touched, and the proof
is in every captured payload (`environment` block).

| Signal | Value |
|---|---|
| `home_url()` | `http://localhost:8080` |
| `wp_get_environment_type()` | **`local`** |
| `conexao_script_classify_target(home_url)` | **`local`** |
| `conexao_script_classify_target(CONEXAO_SITE_URL)` | **`local`** |
| Production host reached | **never** |

The harness fails closed on three independent signals (`home_url`, the
`CONEXAO_SITE_URL` override, and `wp_get_environment_type()`), using the
repository's **own** classifier rather than a re-implementation. There is no
`--force` bypass.

**The guard was proven non-vacuous**, not merely present:

| Simulated target | Result |
|---|---|
| `CONEXAO_SITE_URL=https://conexaobr.ie` | **exit 3** — *"REFUSING TO RUN: target is the PRODUCTION host."* |
| `CONEXAO_SITE_URL=http://some-random-host.com` | **exit 3** — *"classifies as `unknown`"* |

## §2 — Plugin activation verification (§23–§24)

"Plugin activation" in this repository means the **WordPress Eventbrite importer
plugin**, `conexao-event-importer`. Preconditions checked before activation:

| Precondition | Evidence |
|---|---|
| Intended code/version | `conexao-event-importer` **v1.9.0**, loaded from the Compose bind-mount of the repository working tree |
| Registry valid | `php scripts/generate-registry-docs.php --check` → *"registry OK: 10 plugins validated, 19 generated regions current (zero writes)"* |
| Dependencies available | data-model, content, admin-ux, event-runtime present and active; `Conexao_County_Registry` loads |
| Database non-production | local `db` service; `home_url` = `localhost:8080` |
| No production URLs/credentials targeted | only `https://www.eventbrite.ie/d/ireland--cork/…` was contacted |
| **G7 API-token settings not expected** | local DB contains **zero** `eventbrite%` options; no executable file references `eventbrite_api_token` |
| Sources configured as intended | registry self-heals to **136** sources; `events-today`/`events-tomorrow` remained `inactive` |

Post-activation verification:

| Check | Result |
|---|---|
| Plugin active | **yes** (`conexao-event-importer/conexao-event-importer.php` in `active_plugins`) |
| Expected hooks registered | **yes** — `conexao_event_importer_run_source` filter present |
| Source registry loads | **yes** — 136 sources |
| Importer classes load | **yes** — `Conexao_Source_Eventbrite`, `Conexao_Eventbrite_Client`, `Conexao_Eventbrite_Parser`, `Conexao_Source_Enumeration_Checkpoint`, `Conexao_Source_Cooldown`, `Conexao_Import_History` |
| Dead API-token config | **none** |
| Fatal PHP errors | **none** |

The plugin was **already active** locally; G14 verified rather than re-activated
it. **No unrelated plugin was touched, and `conexao-content` activation state was
never modified.**

### 2.1 Supported invocation — and one honest substitution

The documented operator command is `wp conexao-events import --source=<id>`.
**WP-CLI is not installed in this container** (`command -v wp` is empty), so that
exact command cannot execute locally. G14 therefore invoked the **same code path**
through its own filter:

```text
apply_filters( 'conexao_event_importer_run_source', $source_id )
```

which is the filter the WP-CLI command ultimately calls, and is what the G9
report documents as the manual operator command. `Conexao_Event_Importer::
run_source()` runs inside — this is **not** a reimplementation. The substitution
is recorded as a limitation rather than hidden.

## §3 — Source activation (§25)

Exactly **one** source was activated, the one G13 classified `ACTIVATE`:

| Field | Value |
|---|---|
| Source id | `eventbrite_cork_events_this_week` |
| Why activated | **97 incremental events** (78.2 % of its unique set), **9/9 complete** enumerations, 6 requests/observation, 0 duplicates |
| Measured value | 2.07 events per request; G8 corroborated 193 incremental in 16 requests |
| Cadence | **UNRESOLVED** (G13 §4) — run on demand |
| Source identity | `Conexao_County_Registry::get_eventbrite_window_source()`, canonical, unmodified |
| URL | `https://www.eventbrite.ie/d/ireland--cork/events-this-week/` |
| Expected request cost | ~6 requests; 15 declared pages (measured 13–15) |
| Completeness semantics | window listing declares a page count it can serve; incomplete when time/request budget stops the walk |

Activated through `Conexao_Event_Sources::set_status()` — the same code path as
the admin **Enable** button. It never created, deleted or re-pointed a source.

**`events_today` and `events_tomorrow` were NOT activated.** They remained
`inactive` throughout.

`eventbrite_cork` (all-events) was activated **temporarily and solely as a
§29/§30 verification probe** (§5) and was returned to `inactive` afterwards.

## §4 — First controlled run (§27)

Clean baseline: `stored=0`, `checkpoint=none`, `cooling_down=no`.

| Measure | Value |
|---|---|
| Sources in registry | 136 |
| Sources attempted | **1** |
| Sources skipped (inactive) | 135 |
| Sources cooling down | **0** |
| **Events found** | **107** |
| **Created** | **107** |
| **Updated** | **0** |
| **Unchanged** | **0** |
| **Duplicates** | **0** |
| **Failures / errors** | **0** |
| Rate limits | **0** |
| WAF responses | **0** |
| Incomplete sources | **1** (this run) |
| Complete sources | **0** |
| Checkpoint after | **page 13 of 15**, `last_failure = time_budget` |
| Status | `warning` (not `success`) |

### 4.1 Reconciliation safety on an incomplete source (§30)

```text
run reported complete = false   →   mark_missing_events() NOT called
source_not_found before = 0  →  after = 0  →  marked_missing_while_incomplete = 0
```

The run reached page 13 of 15 and was cut by the **time budget**, so it was
incomplete — and every one of the 107 created events remained
`status = published`, with **zero** events flipped to `source_not_found`.

## §5 — Truncated all-events verification (§30) and cross-source dedup (§29)

A third run exercised the structurally-truncated all-events listing, which is
the one case where completeness must remain false *even when the walk reaches
its declared end*.

| Measure | Value |
|---|---|
| Declared pages / objects | **49 pages, ~1,534 objects** → 49 × 20 = **980 reachable** |
| Checkpoint before → after | 4 → **14** (bounded by `time_budget`) |
| Events found | **260** |
| Created | **222** |
| **Unchanged** | **38** |
| Duplicates | **0** |
| Run reported `complete` | **false** — `upstream_truncated` held |

**Two things this proves, live:**

1. **§30 — checkpoint completion does not override truncation.** The checkpoint
   reached its declared page count in run 2 of the window source
   (`complete = true` as *progress*), yet the run still reported
   `Listing = incomplete`. The truncation guard and the reconciliation gate are
   independent, exactly as designed.
2. **§29 — one stable identity, one WordPress event, across sources.** The **38
   unchanged** events were served by the *all-events* listing but were already
   imported by the *window* source. Spot-checking the matched posts:

   ```text
   9471 source=eventbrite_cork_events_this_week src_id=1988930502674   (Cork Card Show)
   9472 source=eventbrite_cork_events_this_week src_id=1998482317409   (CLUB 99 @ VOODOO ROOMS)
   9473 source=eventbrite_cork_events_this_week src_id=1990054995061
   ```

   The deduplicator matched them by native Eventbrite ID and reported
   **unchanged**, so **no second WordPress event was created**.

   Global verification after all runs: **658** posts carry a source id,
   **650 distinct** Eventbrite ids, **8 ids mapped to >1 post** — and all 8 are
   pre-existing `laois_tourism` fixtures unrelated to Eventbrite. **G14
   introduced 0 duplicates.**

`eventbrite_cork` was then returned to `inactive`, its checkpoint reset, and its
222 verification-only events removed, so the local database reflects the G13
operating model exactly.

## §6 — Second run / resume verification (§28)

The §28 chain was verified live:

```text
first run → checkpoint (page 13) → second run → resume → reached page 15
```

| Measure | Run 1 | **Run 2 (resume)** |
|---|---:|---:|
| Starting checkpoint | none | **13 of 15** |
| Ending checkpoint | 13 of 15 | **15 of 15** (`complete=true`, progress only) |
| Events found | 107 | **20** |
| Created | 107 | **0** |
| Updated | 0 | **0** |
| Unchanged | 0 | **20** |
| **Duplicates** | 0 | **0** |
| Stored events after | 107 | **107** |
| Run `complete` | false | **false** |

**The decisive numbers:** run 2 re-walked the one-page overlap plus the two
outstanding pages, saw **20** events, and created **0**. Stored events stayed at
**107**, and the post-run check confirmed **107 stored = 107 distinct Eventbrite
ids, 0 duplicate ids, 0 `source_not_found`**.

This is the overlap invariant working exactly as G10/G11 predicted: the resumed
run re-validated the boundary and **did not duplicate anything**.

**Note on `complete = false` in run 2 despite reaching the last page.** That is
the designed behaviour, not a defect: a resumed enumeration is complete-but-
*subset*, so `is_reconciliation_safe()` is false and the run is recorded
incomplete. That is precisely the G10 rule *"checkpoint ≠ deletion authority"*
working end to end on live data.

## §7 — Import History verification (§31)

Every controlled run produced a history row that is **internally consistent** —
no stale `found=0 success` anywhere.

| Run | `found` | `new` | `updated` | `unchanged` | `dupes` | `errors` | `status` | `complete` |
|---|---:|---:|---:|---:|---:|---:|---|---|
| Run 1 (`this-week`) | 107 | 107 | 0 | 0 | 0 | 0 | `warning` | **false** |
| Run 2 (`this-week`) | 20 | 0 | 0 | 20 | 0 | 0 | `warning` | **false** |
| Run 3 (all-events) | 260 | 222 | 0 | 38 | 0 | 0 | `warning` | **false** |

Consistency checks that hold in every row:

- `new == created`, and `found == new + updated + unchanged` (107 = 107+0+0;
  20 = 0+0+20; 260 = 222+0+38);
- **`status = warning`, never `success`**, because each run was incomplete;
- **`complete = false`** on every row, matching the walk;
- **checkpoint** present and truthful — runs 1 and 3 report `page 13`/`page 14`,
  run 2 reports `page 15`, each with its `last_failure`;
- **cooldown** block present and consistent — `cooling_down: false`,
  `last_reason: ''` (no throttle was recorded, so none was fabricated);
- `fatal_errors: []` and `errors: 0` throughout — nothing was hidden.

## §8 — Negative proofs and mutations (§32)

All ten critical regression proofs were run against the real code paths
([`08-negative-proofs.txt`](evidence/eventbrite-g12/08-negative-proofs.txt)):

| # | Mutation | Result |
|---|---|---|
| N1 | a failing source becomes a successful empty | **PROVEN** (16 passed, **122 failed**) |
| N2 | an incomplete enumeration is called complete | **PROVEN** (75 / 2) |
| N3 | the checkpoint is ignored (resume restarts) | **PROVEN** (72 / 5) |
| N4 | a rate-limited walk is reported complete | **PROVEN** (75 / 2) |
| N5 | duplicate overlapping event not deduplicated | **PROVEN** (76 / 1) |
| N6 | a truncated listing is treated as complete | **PROVEN** (76 / 1) |
| N7 | the checkpoint usability guard removed | **PROVEN** (71 / 6) |
| N8 | the reconciliation-safety gate removed | **PROVEN** (74 / 3) |
| N9 | source isolation removed (shared checkpoint) | **PROVEN** (65 / **12**) |
| N10 | duplicate identity protection removed | **PROVEN** (76 / 1) |

**Proven: 10 | vacuous: 0.** Every mutation was genuine, non-vacuous, and the
working tree was restored afterwards (verified: `git status -- wp-content/` clean).

**Two proof failures were found and fixed during G14** — the value of doing this
properly rather than accepting a green script:

1. **N1 was initially VACUOUS** (twice). The first mutation was ineffective (it
   assigned to a variable the `catch` block never reads). The second *was*
   effective, but the harness misreported it: `test-source-fetch-failure.php`
   exits **early** on a failed assertion and therefore prints **no summary line**,
   so summary-parsing read it as "0 failed". The harness was corrected to treat
   the **suite exit code** as authoritative. N1 then proved itself at
   **122 failing assertions**.
2. **N5 was initially VACUOUS.** The mutation removed the guard *inside* the
   `if`, leaving `if (isset(...)) { ++$duplicates; }` — the `continue` survived,
   so behaviour was unchanged. It was rewritten to remove the whole block.

Neither was hidden, and neither was papered over by weakening an assertion.

## §9 — A real incident, recorded in full (§35)

**What happened.** The first attempt to execute run 1 was issued through a
30-second command timeout. The client was killed, but the **container-side
process kept running**. A second invocation was then issued for the same source.
For roughly a minute, **two importer runs executed concurrently against the same
source**.

**Observable effect.** The two runs raced: MySQL reported
`Duplicate entry '9278-54' for key 'wp_term_relationships.PRIMARY'`, and the
resulting state held **124 posts for 105 distinct Eventbrite ids — 19 duplicated
ids**.

**What it is, and what it is not.** This is **not** an importer correctness
defect in the sense §32 tests: sequential and resumed runs produced **zero**
duplicates (§6). It is a **concurrency** hazard — the importer holds **no lock**
against two simultaneous runs of the same source, so identity deduplication
(per-run, in-memory) cannot see the other run's writes.

**What I did.** Detected it, **stopped**, removed all 124 events for that source,
reset the checkpoint, and re-ran cleanly and **serially**, waiting for each run
to finish. The clean results in §4 and §6 are that re-run. The incident is
reported here rather than quietly removed.

**Operational consequence.** The importer is **single-run-at-a-time per
environment**. There is no locking, so a second concurrent invocation is
operator error and must be avoided — this belongs in the operator guidance.

## §10 — Tests versus the G11/G10 baseline (§33–§34)

The baseline was captured **before** any G14 write, on a verified-clean tree.

| | G11/G10 baseline | G12/G14 final | Delta |
|---|---:|---:|---|
| In-process PHP suites | 96 | 96 | 0 |
| Suites passed / failed | 67 / 29 | **67 / 29** | **0** |
| Assertions passed | 4011 | **4011** | **0** |
| Assertions failed | 4 | **4** | **0** |
| Script-contract | 18 / 1 | 18 / 1 | 0 |
| HTTP acceptance | 3 / 1 | 3 / 1 | 0 |
| Event-importer suite failures | 0 | **0** | 0 |

**The failure set is unchanged.** The 29 failing suites and 4 failing assertions
are the pre-existing translation/automation baseline documented in G8 and G11
(four prerequisite-blocked suites plus unrelated content fixtures), and **none**
is in `conexao-event-importer`.

**No assertion was deleted, no tolerance widened, no failure suppressed, no old
test removed, no source failure hidden.** The identical counts are the *result*,
not the target.

| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan level 5 clean |
| `python3 scripts/verify-permanent-gates.py` | **7/7 PASS**, 84 assertions, 0 violations |
| `php scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 regions, zero writes |

**New assertions added: 0.** G12–G14 added **no** test file. The measurement
library and the G10 checkpoint suite already asserted every invariant G14 then
verified live; the work was to *run* them, not to extend them.

## §11 — Production safety verification (§35)

| | |
|---|---|
| Production files changed | **0** |
| Production database changed | **0** — all writes went to the local Compose `db` |
| Deployments | **0** |
| Production HTTP writes | **0** |
| Production importer execution | **0** |
| Hosts contacted | `www.eventbrite.ie` (public listing) and `localhost:8080` only |

Independently re-verified after every run: the harness refuses a production
target with **exit 3**, and `git status -- wp-content/` is **clean**.

**Flutter/mobile repository: untouched** — never accessed, inspected, built,
tested, modified, deployed or referenced.

## §12 — Files added

| Path | Purpose |
|---|---|
| `tests/experiments/g14-controlled-import.php` | Non-production-guarded controlled import harness |
| `tests/experiments/g14-mutations.sh` | The ten §32 negative proofs |
| `tests/experiments/observations/g14-*.json` | Run payloads (environment proof, before/after, history) |

**No file under `wp-content/` was modified.** `OVERLAP_PAGES`, cooldown, request
budgets, completeness, reconciliation safety and the G7 token retirement are all
untouched by G12–G14.

## §13 — Limitations

1. **No validated cadence.** The volatility window was 114.6 minutes. G13 left
   cadence unresolved and G14 therefore ran on demand. Validating a *schedule*
   requires the multi-day series the harness is preserved to collect.
2. **No live 429 in the controlled runs.** All three stopped on `time_budget`,
   so the rate-limit path was exercised by fixture proofs (N4, N10) rather than
   by a live throttle. G12's 200 requests produced zero 429s by design.
3. **WP-CLI absent**, so the documented `wp conexao-events import` command was
   invoked through its own filter (§2.1) — same code path, different shell.
4. **Concurrency hazard** (§9): no lock against simultaneous runs of one source.
5. **Dublin was not observed**; all G12/G14 evidence is Cork only.
6. **`events-today`/`events-tomorrow` remain unverified as importers** — they
   were deliberately not activated, so no live import evidence exists for them.
   That is the correct consequence of their `REJECT`/`DEFER` status.

## §14 — Recommendation

The Eventbrite ingestion path is **operationally complete and verified on live
data** for the one source the evidence justifies: checkpoint → resume → one-page
overlap → completeness → reconciliation safety → cross-source deduplication →
truthful history, all proven end to end with ten non-vacuous negative proofs.

The single remaining action is **measurement, not code**: continue
`./tests/experiments/g11-run-round.sh <label>` on the 15-minute protocol
interval until a multi-day series exists, then derive a per-source cadence in
G15. Nothing else is blocking.