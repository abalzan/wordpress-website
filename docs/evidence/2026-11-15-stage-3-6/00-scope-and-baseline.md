# Stage 3.6 — scope, baseline, source resolution and session record

**Task:** Stage 3.6 — fresh provider fetch for the 76 HIGH-priority suppressed
(`source_not_found`) events: resolve each target source to its configured
Eventbrite county discovery URL, build a page-level evidence manifest, and
compare each target `_event_source_id` against the fetched provider IDs.

**Nature:** read-only forensic evidence gathering, plus the first live provider
HTTP GET requests in this chain (explicitly authorised, discovery listings only).

**Verdict: BLOCKED — CANARY MECHANISM FAILED.** The Offaly canary URL was never
served as a discovery listing: AWS WAF returned a JavaScript CAPTCHA challenge
(`HTTP 405`, `x-amzn-waf-action: captcha`). No listing page existed to parse, so
the parser, the G8 truncation guard and the G10 moving-pagination safeguard
could not be validated, and §5 forbids proceeding to the other seven URLs. See
`01-canary-offaly.md`.

---

## 1. Git baseline — verified, not assumed

Inspected before any other work, and **not** taken from the Stage 3.5 report:

```
$ git rev-parse --abbrev-ref HEAD
event-import-improvements

$ git rev-parse HEAD
d41253bb8df30371c4a7dca18f21450ae41fccad

$ git log -1 --format='%H%n%ad%n%s' --date=iso-strict
d41253bb8df30371c4a7dca18f21450ae41fccad
2026-11-15T20:03:05Z
Add evidence files for Stage 3.5 analysis and restoration planning

$ git status --short
(no output — clean worktree)

$ git log --oneline -3
d41253b Add evidence files for Stage 3.5 analysis and restoration planning
77f09a3 Refactor code structure for improved readability and maintainability
2704169 Add Stage 2 verification evidence and reclassifications
```

| Item | Value |
|---|---|
| Branch | `event-import-improvements` |
| HEAD at Stage 3.6 start | **`d41253bb8df30371c4a7dca18f21450ae41fccad`** (2026-11-15 20:03:05 +0000) |
| Worktree at start | **clean** |
| Stage 3.5 evidence committed? | **YES** — see §2 |

## 2. Stage 3.5 commit verification (precondition §2.1 item 1)

```
$ git ls-files docs/evidence/2026-11-15-stage-3-5/
docs/evidence/2026-11-15-stage-3-5/00-scope-and-baseline.md
docs/evidence/2026-11-15-stage-3-5/01-attribution-evidence.md
docs/evidence/2026-11-15-stage-3-5/01a-cron-continuation-entries.tsv
docs/evidence/2026-11-15-stage-3-5/01b-continuation-store-records.tsv
docs/evidence/2026-11-15-stage-3-5/01c-history-store-window.tsv
docs/evidence/2026-11-15-stage-3-5/01d-log-store-window.tsv
docs/evidence/2026-11-15-stage-3-5/01e-source-registry-snapshot.tsv
docs/evidence/2026-11-15-stage-3-5/02-suppressed-event-inventory.md
docs/evidence/2026-11-15-stage-3-5/02-suppressed-event-inventory.tsv
docs/evidence/2026-11-15-stage-3-5/03-connected-failure-analysis.md
docs/evidence/2026-11-15-stage-3-5/04-datetime-contract-violation.md
docs/evidence/2026-11-15-stage-3-5/04-datetime-violations.tsv
docs/evidence/2026-11-15-stage-3-5/05-restoration-plan.md
docs/evidence/2026-11-15-stage-3-5/07-shared-db-access-evidence.md
docs/evidence/2026-11-15-stage-3-5/08-preservation-confirmation.md

$ git status --short -- docs/evidence/2026-11-15-stage-3-5/
(no output)
```

15 files tracked, worktree clean for those paths, all inside HEAD `d41253b`.
The precondition **passed**; nothing was committed as part of this task.

## 3. Target inventory (precondition §2.1 item 2)

Authoritative source: `docs/evidence/2026-11-15-stage-3-5/02-suppressed-event-inventory.tsv`

```
$ sha256sum docs/evidence/2026-11-15-stage-3-5/02-suppressed-event-inventory.tsv
7683a5c8998927214eeeb7dc67dd337aa81faa903e26b54266b750ce42e0a364
```

* Data rows in the file: **80** (24 columns).
* Filtered on `provisional_classification` — the file's classification column —
  equal to `HIGH — upstream verification required`: **76 rows**.
* Distinct `post_id`: 76. Distinct `current_event_source_id`: 76. No duplicates.
* All 76 carry `current_event_status = source_not_found`.

| Source | Target rows | Expected | Match |
|---|---|---|---|
| `eventbrite_meath` | 22 | 22 | ✔ |
| `eventbrite_laois` | 17 | 17 | ✔ |
| `eventbrite_mayo` | 14 | 14 | ✔ |
| `eventbrite_kerry` | 7 | 7 | ✔ |
| `eventbrite_clare` | 5 | 5 | ✔ |
| `eventbrite_westmeath` | 5 | 5 | ✔ |
| `eventbrite_leitrim` | 4 | 4 | ✔ |
| `eventbrite_offaly` | 2 | 2 | ✔ |
| **Total** | **76** | **76** | ✔ |

The other four rows of the 80 are the Cork `CONDITIONAL` events and were **not**
loaded into the target set. Cork, the 25 past events, post `115352`, the stranded
continuations, the fixture pollution and the unconsumed cron event are all out of
scope and untouched.

**Precondition result: TARGET LIST MATCHES** (76 rows, expected breakdown).

## 4. Source resolution and URL grouping (precondition §2.1 item 3)

Resolved read-only from the `conexao_event_sources` option (`HEX(option_value)`,
decoded locally; 85,494 bytes, 154 entries, 154 distinct ids — the store also
contains `g1_probe_*` fixture entries, which were **not** used and did not expand
the scope). Each target source id matched **exactly one** entry.

| Source id | Status | Discovery URL | Targets |
|---|---|---|---|
| `eventbrite_meath` | active | `https://www.eventbrite.ie/d/ireland--meath/all-events/` | 22 |
| `eventbrite_laois` | active | `https://www.eventbrite.ie/d/ireland--laois/all-events/` | 17 |
| `eventbrite_mayo` | active | `https://www.eventbrite.ie/d/ireland--mayo/all-events/` | 14 |
| `eventbrite_kerry` | active | `https://www.eventbrite.ie/d/ireland--kerry/all-events/` | 7 |
| `eventbrite_westmeath` | active | `https://www.eventbrite.ie/d/ireland--westmeath/all-events/` | 5 |
| `eventbrite_clare` | active | `https://www.eventbrite.ie/d/ireland--clare/all-events/` | 5 |
| `eventbrite_leitrim` | active | `https://www.eventbrite.ie/d/ireland--leitrim/all-events/` | 4 |
| `eventbrite_offaly` | active | `https://www.eventbrite.ie/d/ireland--offaly/all-events/` | 2 |

**URL grouping: 8 distinct source ids → 8 distinct URLs. No URL is shared, so no
multi-source budget grouping applies.** Budget is therefore tracked per URL, and
because the mapping is 1:1 the per-URL and per-source ledgers coincide.

Source resolution: **all 8 resolved; none inactive; none unresolved.**
(`eventbrite_mayo` carries `last_import_status = error` / `HTTP 429` from
2026-11-14, but its registry `status` is `active` and its URL resolves, so it is
a valid target.)

Per-source registry snapshot corroboration: `01e-source-registry-snapshot.tsv` in
the Stage 3.5 folder, captured 2026-11-15T19:22:20Z.

## 5. Target metadata re-checked against the live database

Before any fetch, the 76 post IDs were re-read read-only from
`wp_postmeta` (`_event_source`, `_event_source_id`, `_event_url`,
`_event_status`, `_event_date`, `_event_end_date`): 456 rows = 76 posts × 6 keys.
**0 mismatches** against the inventory, and all 76 still `_event_status =
source_not_found`. The inventory was therefore still current when the first
provider request was made.

## 6. Environment identity (precondition §2.1 item 5)

Identity only — the plugin was **not** bootstrapped.

| Fact | Value |
|---|---|
| Plugin version (header) | `1.10.0` — *Conexão BR Irlanda — Event Importer (Local Tools)* |
| `conexao-event-importer.php` sha256 (repo) | `92fdc30b1fd2d0eb51d0853bb289a7605b9faf870f4fa6876d936ef2f60da9ed` |
| same file, inside the `wordpress` container | `92fdc30b...f60da9ed` — **identical** |
| `class-eventbrite-client.php` (repo / container) | `580e9ab2...79425a70` / **identical** |
| `sources/class-eventbrite-source.php` (repo / container) | `e6386f2b...e3e47a` / **identical** |
| Stack | `wordpress-website-db-1` (mysql:latest, healthy), `wordpress-website-wordpress-1` (port 8080), both already running and reused |
| DB engine / account | MySQL `9.7.1`, `root@localhost` (inside the `db` container) |

Running code is byte-identical to the branch, so **environment identity is
established**: the target/source mapping used here is the branch's own mapping.
No container was started, recreated or rebuilt.

## 7. Session boundaries (precondition §2.1 / §6.4)

* Implementer is **not** GLM-5.3-Flash, so the mandatory two-session split did not
  apply.
* In practice the run **ended inside Session A at the canary gate**, so Session B
  (Mayo, Kerry, Westmeath, Clare, Leitrim) never started and never will as part of
  this task. The request ledger was therefore never carried across sessions.
* Global budget consumed: **3 of 200 requests**. Per-URL budget for Offaly:
  **3 of 30**.

## 8. Site timezone

**Not queried.** §2.1 permits it only for interpreting fetch timestamps in the
evidence, and states it is not used in the presence/absence comparison (which is
on provider event IDs, not dates). Every timestamp in this folder is UTC
(`…Z`), so the query was unnecessary and was not issued.

## 9. What this task deliberately did not do

| Not done | Why |
|---|---|
| Fetch the other 7 discovery URLs | §5: canary mechanism failure ⇒ stop, do not fetch the others |
| Restore / reclassify any event | Out of scope; not a restoration task |
| Change `_event_status`, `post_status`, postmeta | Not authorised |
| Run `run_source()`, `run_all()`, `fetch_events()`, importer/engine paths | Not authorised |
| Reconciliation, `mark_missing_events()` | Not authorised |
| Continuations, WP-Cron, retention/prune | Not authorised |
| Bootstrap WordPress or the plugin | Prohibited (§4.1); isolation proved |
| Fetch event detail pages, images, robots.txt, other counties, Cork | Prohibited (§6.2) |
| Shared-DB writes | Prohibited (§6.1); session `@@transaction_read_only = 1` |
| Commit, push, merge, deploy, activate, release | Prohibited |
| Modify Stage 3.5 evidence or 3.5's restoration plan | Prohibited; addendum is a new file |

## 10. Files in this folder

| File | Contents |
|---|---|
| `00-scope-and-baseline.md` | This file |
| `01-canary-offaly.md` | Isolation proof, canary transcript, response validation, verdict |
| `03-page-index-offaly.tsv` | The three attempted page requests for the canary URL: status, hash, timestamp, 0 parsed results, parse/validation outcome `NO` |
| `04-request-log.md` | Every outbound request and retry, with budget accounting |
| `05-target-comparison.tsv` | Exactly 76 rows, one per validated target |
| `06-per-url-summary.md` | Per unique URL: coverage, state, counts |
| `06b-per-source-summary.md` | Per source id aggregation |
| `07-comparison-report.md` | Findings, what the evidence does and does not establish |
| `08-rate-limit-and-budget.md` | Budget ledger, retry/cooldown decisions |
| `09-preservation-confirmation.md` | No-write / no-side-effect confirmation |
| `10-shared-db-access-evidence.md` | Read-only query text and returned values |
| `11-restoration-plan-addendum.md` | New addendum (Stage 3.5's plan untouched) |
| `harness/` | The standalone harness, its isolation proof, and the raw request ledger |

`02-manifest-<url-id>.tsv` and the sanitised page payload files are **absent by
design**: no discovery listing page was ever served, so there is no parsed-event
manifest and no `search_data.events` payload to store. `03-page-index-offaly.tsv`
exists but records three attempted requests and **0 parsed results**, which is why
no manifest can be derived from it. Fabricating an empty manifest would
misrepresent coverage as "listing contained zero events". `01-canary-offaly.md` §4
records the consequence.
