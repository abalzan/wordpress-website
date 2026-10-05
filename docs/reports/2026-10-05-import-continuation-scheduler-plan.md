# Plan — make a resumable import actually resume

| | |
|---|---|
| **Task title** | Automatic continuation for a resumable Eventbrite enumeration (G18) |
| **Date** | 2026-10-05 |
| **Author / agent** | coding agent |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `a22b4c6afaa00251f3eb6329d7e418e32da3f355` |
| **Applicable skill(s)** | `wp-add-content-type` (importer behaviour), `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Give the existing Eventbrite enumeration checkpoint a **server-side owner**:
a per-source continuation that is scheduled when a run becomes resumable,
fires after a bounded, server-enforced cooldown, resumes from the persisted
checkpoint, repeats until the enumeration completes, and clears itself.

Concretely:

1. A new `Conexao_Import_Continuation` class holding the scheduling state
   (one record per source), the cooldown, the per-source lock and the runner.
2. The importer engine reconciles a source's resumable state at the end of
   every real run (schedule / keep / clear / declare exhausted).
3. Import History renders the **truthful** continuation state, so
   "resumable" can never be displayed while no continuation exists.
4. Import Logs gain the continuation lifecycle entries.
5. A regression suite plus mutation-based negative controls.

## 2. Explicit non-goals

- **No new scheduler.** WP-Cron is the mechanism the repository already
  supports (WordPress core); the importer creates one `wp_schedule_single_event`
  per source and nothing else. No Action Scheduler dependency, no queue table,
  no polling loop, no per-second cron.
- **No replacement of the safety model.** The G5 in-walk cooldown ladder
  (20 / 40 / 60 s), the request budget, the request-reuse store, the overlap
  rule, the truncation guard, the completeness contract, the reconciliation
  gate, the source_not_found restoration path (G17) and the G7 token retirement
  are all preserved unchanged.
- **No time-budget increase.** `TIME_BUDGET_SECONDS` stays 45; `MAX_PAGES`
  stays 50; the request budget stays 400.
- **No production surface.** `conexao-event-importer` is `production: false`
  in `plugins.json`; nothing here ships to or operates production.
- **No UI redesign.** Import History gains no new page and no new column; the
  existing Progress cell becomes truthful.
- **No browser dependency.** The continuation is never driven by admin JS,
  by the Import History page, or by an AJAX call.

## 3. Relevant authoritative documents

| Document | Fact it owns |
|---|---|
| `AGENTS.md` | orientation, scope, safety rules |
| `docs/engineering-standard.md` | §4.2, §5.2, §8, §9.3 |
| `plugins.json` | the importer is tooling / `production: false` |
| `docs/plugins/conexao-event-importer.md` | importer behaviour and the checkpoint contract |
| `docs/reports/2026-10-04-eventbrite-g10-checkpoint.md` | checkpoint + resume invariants |
| `docs/reports/2026-10-04-eventbrite-g13-operationalization.md` | the operator model (Import History is the single surface) |
| `docs/reports/2026-10-05-eventbrite-g17-reconciliation-fix.md` | the reappearance restoration path that must not regress |
| `docs/testing.md` | the test layers and the single command |
| `scripts/README.md` | the script inventory |

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-write-in-process-test` (primary) |
| **Its preconditions** | read the plugin doc + the G9/G10/G13 reports before editing |
| **Its guardrails that bind this task** | no production write; checkpoint never authorises absence reconciliation; completeness is never inferred from a checkpoint |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/run-tests.sh --only conexao-event-importer`, `./scripts/lint.sh` |
| **Its evidence location** | `docs/evidence/2026-10-05-import-continuation/` |

## 4. Current-state findings

Traced by reading the code and by running the suites in the local Compose stack.

| # | Question | Answer found |
|---|---|---|
| 1 | Is the checkpoint written? | **Yes.** `Conexao_Source_Enumeration_Checkpoint::record()` on every confirmed page, and on every stop with the failure class. |
| 2 | Is the source marked resumable? | **Yes, implicitly.** A record with `last_failure` set and `last_page < page_count` is what the UI calls "resumable". |
| 3 | Is a next-run time / cooldown recorded? | **Only per request-window.** `Conexao_Source_Cooldown` records `next_eligible` at 20/40/60 s. There is no run-level next-run time. |
| 4 | Is a continuation job scheduled? | **NO.** This is the break. |
| 5 | Is it persisted? | n/a — nothing is created. |
| 6 | Is it picked up by an execution mechanism? | n/a. |
| 7 | Does a scheduler run here? | **No scheduler exists.** No `wp_schedule_event`, no `wp_schedule_single_event`, no queue. `docs/plugins/conexao-event-importer.md` states "there is no cron, polling, background sync", and `Conexao_Source_Cooldown`'s docblock states "It never schedules or performs retries. There is no automatic retry loop. Cooldown only reports; the operator decides when to try again." |
| 8 | Does it invoke the correct source? | n/a. |
| 9–13 | Resume / cooldown / completion | **Correct, but only if a run happens.** `resolve_resume_page()` resumes at `last_page - OVERLAP_PAGES`, the cooldown is honoured by the run gate, and a full walk persists `complete = true`. |

**The missing transition is `resumable → scheduled`.** Everything downstream of
it already works and is already tested (77 assertions in
`test-eventbrite-checkpoint.php`); nothing upstream of it exists.

Verbatim evidence of the promise being made and not kept — the current
Progress cell, `class-event-sources.php:1379-1385`:

```php
__( 'page %1$d of %2$d (%3$s) — resumable', 'conexao-event-importer' )
```

## 5. Approach

One new class, one derived predicate, three call sites.

```
run starts
  → checkpoint saved                          (existing)
  → run becomes incomplete/resumable          (existing)
  → reconcile() reads the resumable predicate
  → continuation record persisted + ONE wp_schedule_single_event
  → cooldown expires                          (cron fires; server clock)
  → run_due() takes the per-source lock
  → run_source() resumes from the checkpoint  (existing)
  → reconcile() again  →  repeat
  → enumeration complete  →  continuation cleared, checkpoint marked complete
```

### 5.1 The resumable predicate (single source of truth)

`Conexao_Source_Enumeration_Checkpoint::is_resumable()` — declared once, used
by the scheduler and by Import History, so the words and the behaviour can
never disagree:

```
last_page >= 1  AND  complete === false  AND  ( page_count === 0 OR last_page < page_count )
```

The `complete` term is what stops an upstream-truncated listing (which reaches
page 49 of 49 and is legitimately incomplete) from being rescheduled forever.

### 5.2 Cooldown

`COOLDOWN_SECONDS = 1800` (30 minutes), filterable, server-side only.
Escalation on repeated *no-progress* attempts: 30 → 60 → 120 min, capped at
120 min. The in-walk G5 ladder is untouched.

### 5.3 Concurrency

Per-source lock (`add_option` on `conexao_event_import_lock_<source>`, atomic,
autoload-off, 15-minute TTL so a crashed worker self-heals). Never a global
lock: Cork's lock does not block Wicklow.

### 5.4 Duplicate scheduling protection

`schedule()` is idempotent: an existing pending continuation is neither
duplicated nor postponed. Exactly one pending cron event per source
(`wp_clear_scheduled_hook` before arming).

## 6. Impact

| Area | Impact |
|---|---|
| Content | none — no post, term or meta is written by the scheduler |
| Routes | none |
| English / Polylang | none; the scheduler is language-blind |
| Database / options | two new options: `conexao_event_import_continuations` (autoloaded, bounded) and one lock key per active source (autoload off) |
| Production | none — `production: false`, local-only plugin |
| Requests | ≤1 extra import attempt per source per 30 minutes, each bounded by the existing request budget |

## 7. Verification plan

| Check | Command |
|---|---|
| all layers | `./scripts/run-tests.sh` |
| importer only | `./scripts/run-tests.sh --only conexao-event-importer` |
| static quality | `./scripts/lint.sh` |
| registry drift | `php scripts/generate-registry-docs.php --check` |
| permanent gates | `python3 scripts/verify-permanent-gates.py` |
| i18n catalogue | `python3 tests/scripts/verify-i18n-freshness.py` |
| browser | `python3 tests/browser/verify-import-continuation-browser.py` (Playwright/Chromium, the repository opt-in browser layer) |

