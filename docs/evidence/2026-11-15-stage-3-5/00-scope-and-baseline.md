# Stage 3.5 — scope, baseline and session record

**Task:** Stage 3.5 — read-only investigation of the 80-event `source_not_found`
cohort, attribution evidence capture, connected-failure hypothesis test, datetime
contract check, and a *non-executed* restoration plan.

**Nature:** read-only. No event post, postmeta, `_event_status`, source registry
entry, continuation record, log or history entry was written. No importer,
continuation, cron event, provider request or retention/prune was executed. No
commit, push, deploy, activation or release.

---

## 1. Git baseline — verified, not assumed

Inspected before any other work:

```
$ git log -1 --format='%H%n%ad%n%s' --date=iso-strict
77f09a3e4ad84e0ea191123b287f76fbd7bd554c
2026-11-15T18:28:35Z
Refactor code structure for improved readability and maintainability

$ git branch --show-current
event-import-improvements

$ git status --short
(no output — clean worktree)

$ git log --oneline -3
77f09a3 Refactor code structure for improved readability and maintainability
2704169 Add Stage 2 verification evidence and reclassifications
3f6141a Add comprehensive tests for source fetch contract and handler completeness
```

| Item | Value |
|---|---|
| Branch | `event-import-improvements` |
| HEAD at Stage 3.5 start | **`77f09a3e4ad84e0ea191123b287f76fbd7bd554c`** (2026-11-15 18:28:35 +0000) |
| Worktree at start | **clean** (`git status --short` produced no output) |
| Stage 3's own baseline HEAD | `270416911f918a849e34f77cacfc4d7d19925ad6` — the **parent** of `77f09a3` |
| Prompt's stated known HEAD | `77f09a3…` — **matches** |

The prompt's warning that the Stage 3 report's "uncommitted" closing statement is
stale relative to the pushed branch is correct, and §9 of
`01-attribution-evidence.md` records the reconciliation with timestamps rather
than a guess.

## 2. Session structure

This task was executed in **one session**, covering Tasks A–E. The prompt's
optional two-session split (Session A = A + D, Session B = B + C + E) is a
provision for a smaller-budget implementer; it was not used here, so no
deferral of Task E's Priority 2/3 analysis was required.

**Disclosure:** the implementer was **not** GLM-5.3-Flash, so the mandatory
split described in the prompt did not apply. No part of Tasks A–E was deferred
to a third session.

## 3. Evidence capture timestamps

Every read in this task was performed in a single read-only session on
2026-11-15:

| Capture | Timestamp (UTC) |
|---|---|
| Session read-only probe (`SELECT @@transaction_read_only`) | 2026-11-15T19:21Z |
| Option stores dumped byte-exact (`HEX(option_value)`) | **2026-11-15T19:22:20Z** |
| `wp_posts` / `wp_postmeta` exports (80-event cohort, datetime class) | 2026-11-15T19:24Z–19:31Z |
| Local parsing / TSV generation | 2026-11-15T19:32Z–19:45Z |

All TSVs carry the capture timestamp `2026-11-15T19:22:20Z` in a
`capture_ts_utc` / `observed_at` column where that column exists. The 80-event
inventory is a snapshot at that instant; the shared site was demonstrably still
active during the session (its most recent history row is `2026-11-15
18:41:12`, ~41 minutes before the capture), so the snapshot is fixed in time and
not a live query.

## 4. Deployment identity — pushed ≠ deployed, and what could actually be proved

Three separate facts are recorded and must not be conflated.

### (a) Branch code / Git HEAD — verified
`77f09a3e4ad84e0ea191123b287f76fbd7bd554c`, clean worktree. The Event Importer
plugin version declared in the branch source is **1.10.0**
(`wp-content/plugins/conexao-event-importer/conexao-event-importer.php:5,15`).

### (b) Deployed code on the shared site — VERIFIED for the local shared stack
The shared database used throughout Stages 1–3.5 is the `wordpress` database of
the local Compose stack (`docker compose ps`: `wordpress-website-db-1`,
`wordpress-website-wordpress-1`). That stack **bind-mounts the repository's
plugin directories**, so the code executing against the shared database is the
working tree. Verified by hashing the files *inside the running container* and
comparing them with the repository:

```
$ sha256sum wp-content/plugins/conexao-event-importer/{conexao-event-importer.php,includes/class-import-history.php,includes/class-import-log.php}
92fdc30b1fd2d0eb51d0853bb289a7605b9faf870f4fa6876d936ef2f60da9ed  conexao-event-importer.php
51429861bf05bcc14a562fca64d2a883d9784ccb5cefdf7ee2e32e6607e40bad  includes/class-import-history.php
e131e57036f1ee22cd616fba7064bedd0e0b8d2c50526aeb8c8b78d6bad92596  includes/class-import-log.php

$ docker compose exec -T wordpress sha256sum /var/www/html/wp-content/plugins/conexao-event-importer/…
92fdc30b1fd2d0eb51d0853bb289a7605b9faf870f4fa6876d936ef2f60da9ed  …/conexao-event-importer.php
51429861bf05bcc14a562fca64d2a883d9784ccb5cefdf7ee2e32e6607e40bad  …/includes/class-import-history.php
e131e57036f1ee22cd616fba7064bedd0e0b8d2c50526aeb8c8b78d6bad92596  …/includes/class-import-log.php
```

**Identical.** `conexao-event-importer/conexao-event-importer.php` is present in
`wp_options.active_plugins`, so the plugin is **active** in this stack.
Consequently, on *this* shared site the P12/P13 code is loaded and running —
established by direct file-hash evidence, **not** inferred from the fact that
the branch was pushed. (Corroborating behavioural evidence: history rows written
at 18:39–18:41 carry P12 statuses `partial` / `rate_limited`, where the
14:01–14:10 rows carry the pre-P12 `warning`.)

This finding is *not* extended to production. The WordPress.com production site
has no CLI, no SSH, no filesystem and no database, and there is no read-only
channel from this repository to it. Therefore:

> **PRODUCTION DEPLOYMENT VERSION UNVERIFIED.** No claim is made that
> `77f09a3` (or any Stage 3 P12/P13 code) is deployed to WordPress.com.

### (c) Observed runtime retention state — measured, not inferred
The caps in the mounted code are `MAX_ENTRIES = 8000` (log) and
`MAX_ENTRIES = 1200` / `MAX_GROUPS = 50` (history) — P13. What the **store
actually holds** is reported separately in `01-attribution-evidence.md` §5. The
observed counts (2,104 log entries; 56 history rows) are below both the old and
the new bound, so **counts alone cannot prove which cap is in force**. The one
thing that can be stated from the counts is that a 2,000-entry store cannot hold
2,104 entries, so Stage 3's 2,000-entry bound was **not** binding at capture
time.

## 5. Authoritative baseline inputs

Per the prompt, Stage 3's investigation report is the authoritative baseline. It
was read in full before any query:

| Input | Value |
|---|---|
| Report | `docs/evidence/2026-11-15-stage-3/investigation-source-not-found.md` |
| Fixed Stage 3 capture timestamp | `2026-11-15T18:20:00+00:00` (Europe/Dublin, +00:00 that day) |
| Stage 3 population | 106 records still `source_not_found` |
| Stage 3 split | **80** current/future · 25 past (out of scope) · 1 indeterminate (post `115352`) |
| Per-source current/future counts | meath 22 · laois 17 · mayo 14 · kerry 7 · westmeath 5 · clare 5 · cork 4 · leitrim 4 · offaly 2 = **80** |
| Underlying capture | `docs/evidence/2026-11-15-stage-2-verification/01a-source-not-found-inventory.tsv`, SHA-256 `f1d3eda8e4f1f0f6fbb1e10ca0a237bb715246282adc0f8d3c18475fdd3c74c4` |

Stage 3's verdict, its 106-event count and its date-based classification are
**retained unchanged** and are used as a baseline field. Nothing in this task
re-derives them. The 80 cohort IDs were extracted mechanically from Stage 3
report §7 (80 table rows) rather than re-queried, and the extraction reproduced
Stage 3's per-source totals exactly.

## 6. Scope, and what was deliberately excluded

| In scope | Not touched in this task |
|---|---|
| The 80 Stage 3 current/future cohort IDs (fixed set, one row each) | The 25 past events — legitimately past; **not enumerated anywhere** in this folder |
| The nine affected sources' current registry snapshots | Post `115352` as a *cohort* member — it is Task D's separate item only |
| Retained log / history / continuation evidence and the cron option snapshot | Stage 1's unresolved findings (285-post membership, before-edit digest method, post `170981` timing) |
| Post `115352` and the wider datetime-format class | Stage 2 and Stage 3 verdicts; Stage 3's P12/P13 code; any test |
| A restoration **plan** (not executed) | Any restoration, import, continuation, cron, provider request, retention/prune |

**No upstream verification was performed.** No request of any kind was made to
Eventbrite or any other provider. Every row of
`02-suppressed-event-inventory.tsv` carries `upstream_presence_verified = no`.

## 7. Preservation cohorts — still authoritative

| Cohort | Count | Touched by this task |
|---|---|---|
| Incident-created events | 47 | No |
| Incident-updated events | 13 | No |
| Externally imported events (`133818`, `171003`, `171004`, `171006`, `171007`, `171008`) | 6 | No |
| Probe-associated imported events | 14 | No |
| Protected `stage32_export_test` posts | 275 | No |

The 285-post clone-cleanup population remains **UNRESOLVED — INSUFFICIENT
IDENTITY EVIDENCE**. It was not investigated, as instructed.

## 8. Read-only establishment

`SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY;` returned
`SELECT @@transaction_read_only = 1` before the first data query. The full
transcript, every query text, and the no-write confirmation are in
`07-shared-db-access-evidence.md`. The Stage 2 `ERROR 1792 (25006)` write-probe
was **referenced, not repeated**.

## 9. Files in this folder

| File | Task | Contents |
|---|---|---|
| `00-scope-and-baseline.md` | — | This file |
| `01-attribution-evidence.md` | A | Five evidence targets, storage mapping, attribution classification |
| `01a-cron-continuation-entries.tsv` | A | 22 cron-option continuation entries present at capture |
| `01b-continuation-store-records.tsv` | A | All 22 continuation records, every stored field |
| `01c-history-store-window.tsv` | A | The 9 history rows in 13:55–14:15 |
| `01d-log-store-window.tsv` | A | All 239 log entries in 13:55–14:15 |
| `01e-source-registry-snapshot.tsv` | A | Current registry snapshot for the nine sources |
| `02-suppressed-event-inventory.md` | B | Cohort reconciliation and per-event classification |
| `02-suppressed-event-inventory.tsv` | B | **Exactly 80 rows**, one per Stage 3 cohort ID |
| `03-connected-failure-analysis.md` | C | The five-link hypothesis test and its verdict |
| `04-datetime-contract-violation.md` | D | Post `115352` and the wider class |
| `04-datetime-violations.tsv` | D | The 20 affected `_event_date` values |
| `05-restoration-plan.md` | E | The conditional restoration plan (**not executed**) |
| `07-shared-db-access-evidence.md` | — | Read-only proof, query inventory, no-write confirmation |
| `08-preservation-confirmation.md` | — | Preservation and prohibition confirmation |


