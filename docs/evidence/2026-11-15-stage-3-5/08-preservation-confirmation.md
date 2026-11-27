# Preservation confirmation

**Task:** Stage 3.5. **Nature:** read-only investigation and planning.
**Captured:** 2026-11-15T19:22:20Z (data) / 19:50Z (this confirmation).

---

## 1. What was read, and what was left untouched

| Protected record | Action taken |
|---|---|
| Event posts (`wp_posts` rows) | **Read only.** No `INSERT`/`UPDATE`/`DELETE`. The 80 cohort posts are unchanged: all still exist, all still `post_status = publish` |
| Event postmeta (`_event_status`, `_event_date`, `_event_end_date`, `_event_source`, `_event_source_id`, `_event_url`, `_event_imported`, …) | **Read only.** No `_event_status` value was changed — not for the 80, not for post `115352`, not for any other post |
| Source registry (`conexao_event_sources`) | **Read only.** No source was activated, deactivated, edited or re-run. The 18 inactive sources stayed inactive; no probe source was reactivated |
| Continuation store (`conexao_event_import_continuations`) | **Read only.** No record was reset, retried, rescheduled, cleared or abandoned |
| Import history (`conexao_event_import_history`) | **Read only.** No row added or removed |
| Import log (`conexao_event_import_log`) | **Read only.** No entry added or removed |
| Source health (`conexao_event_source_health`) | **Read only** |
| WP-Cron option (`cron`) | **Read only.** No event scheduled or cleared; the 16 overdue entries are untouched |
| Operator history / logs | Not cleared, not pruned |

## 2. Preservation cohorts

| Cohort | Count | Verified unchanged |
|---|---|---|
| Incident-created events | 47 | Yes — no write of any kind |
| Incident-updated events | 13 | Yes |
| Externally imported events (`133818`, `171003`, `171004`, `171006`, `171007`, `171008`) | 6 | Yes |
| Probe-associated imported events | 14 | Yes |
| Protected `stage32_export_test` posts | 275 | Yes |

The 285-post clone-cleanup population stays **UNRESOLVED — INSUFFICIENT IDENTITY
EVIDENCE**. It was neither investigated nor touched.

## 3. Stage 2 / Stage 3 work

| Item | State |
|---|---|
| Stage 2 verdict | **Unchanged** — this task did not edit the Stage 2 report |
| Stage 3 verdict | **Unchanged** — only an appended reconciliation note was added to the Stage 3 report; no finding or verdict was rewritten |
| Stage 3 P12 / P13 code paths | **Unchanged** — no file under `wp-content/plugins/` was modified |
| Stage 3 tests | **Unchanged** — no test was edited, added or run |
| Stage 1 unresolved findings | Not attempted (285-post membership, before-edit digest method, post `170981` timing) |

## 4. Working-tree state of this task

```
$ git status --short
?? docs/evidence/2026-11-15-stage-3-5/
 M docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md
 M docs/evidence/README.md
```

Only documentation changed:

* the new folder `docs/evidence/2026-11-15-stage-3-5/`;
* an **append-only** section + reconciliation note in the Stage 3 report;
* the evidence index row and a `_Last verified_` line in `docs/evidence/README.md`.

**No file under `wp-content/`, `scripts/`, `tests/` or `composer`/build tooling was
modified.** `git diff` over `wp-content/` is empty.

## 5. Explicit prohibitions — confirmed not done

| Prohibition | Confirmed |
|---|---|
| No restoration executed | ✔ none |
| No `_event_status` change (including the 80 candidates) | ✔ none |
| No event post or postmeta modification | ✔ none |
| No source-registry entry change | ✔ none |
| No live import, Run All, continuation, scheduled task, cron event or provider request | ✔ none |
| No production retention/pruning, no operator history/log clearing | ✔ none |
| No probe source reactivated | ✔ none |
| No Event Runtime visibility rule change | ✔ none |
| No change to Stage 3 P12/P13 code or any test | ✔ none |
| No database account / grant / privilege change | ✔ none |
| No shared-database write, schema change or account change | ✔ none (see `07-`) |
| No commit, push, merge, deploy, activation or release | ✔ none |
| No unrelated roadmap work | ✔ none — no 2026-11-14 roadmap report was created or updated |

## 6. Live-status note

The shared site kept importing during the session window (its newest history row
is `2026-11-15 18:41:12`). Those writes are the **plugin's own** activity, not this
task's: this task issued `SELECT` statements only, and every one of them ran inside
a `READ ONLY` transaction whose `@@transaction_read_only` was verified as `1`.
