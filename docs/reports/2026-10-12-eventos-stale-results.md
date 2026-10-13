# Report — Eventos stale-results investigation

| | |
|---|---|
| **Stage / task name** | `/eventos/` stale results investigation and verification |
| **Date** | 2026-10-12 |
| **Author / agent** | GitHub Copilot |
| **Branch** | `master` |
| **Start SHA** | `898d3ebd7c6c792046d89b5a83834c609ae6e02b` |
| **Final SHA** | `898d3ebd7c6c792046d89b5a83834c609ae6e02b` |
| **Working tree at finish** | clean |
| **Skills followed** | `wp-write-in-process-test`, `wp-http-acceptance-matrix` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-event-importer.md` |

## 1. Problem and reproduction

The reported symptom was that `/eventos/` could continue to show events that should have fallen out of “today” results after the local calendar moved on. The investigation was carried out against the repository’s current event query/runtime path rather than by assuming a cache-only cause.

Observed conditions:
- Event archive uses the ordered upcoming-event ID set as the source of truth.
- The list is keyed by site-local date and language.
- Events are kept or excluded based on their scheduled date window; ongoing multi-day events remain valid until their end date passes.

Reproduction path examined:
- event archive query in [wp-content/themes/conexao-br-irlanda/inc/archive-query.php](../../wp-content/themes/conexao-br-irlanda/inc/archive-query.php)
- runtime query helper in [wp-content/plugins/conexao-event-runtime/includes/class-event-query.php](../../wp-content/plugins/conexao-event-runtime/includes/class-event-query.php)
- recurrence evaluator in [wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php](../../wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php)
- cache invalidation in [wp-content/themes/conexao-br-irlanda/inc/cache.php](../../wp-content/themes/conexao-br-irlanda/inc/cache.php)

## 2. Root cause

The repository’s current implementation already contains the corrected root-cause logic:

- “today” is derived from `DateTimeImmutable( 'today', wp_timezone() )`.
- the upcoming-event transient is keyed by the local calendar date and language slug.
- multi-day events use an inclusive start/end window and are treated as ongoing until their end date is passed.
- the cached date-keyed event list is explicitly flushed on event save/delete/update.

This prevents stale event results from carrying forward into the next local day. The definitive mechanism is not a generic WordPress cache leak or a broad archive refactor; it is the date-keyed runtime query + cache lifecycle operating in the site timezone. The event runtime is therefore intentionally designed to rebuild the result set when the local day changes.

## 3. Implementation

No new runtime code change was required in this worktree because the fix is already present in the checked-out repository.

Relevant files:
- [wp-content/plugins/conexao-event-runtime/includes/class-event-query.php](../../wp-content/plugins/conexao-event-runtime/includes/class-event-query.php)
- [wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php](../../wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php)
- [wp-content/themes/conexao-br-irlanda/inc/archive-query.php](../../wp-content/themes/conexao-br-irlanda/inc/archive-query.php)
- [wp-content/themes/conexao-br-irlanda/inc/cache.php](../../wp-content/themes/conexao-br-irlanda/inc/cache.php)

Why this addresses the issue:
- site-local date handling avoids UTC/local mismatch at midnight;
- date-keyed transient invalidation avoids stale caches carrying outdated results forward;
- ongoing multi-day events remain visible until their true end date has passed;
- expired one-time events and ended-weekly series are excluded correctly.

## 4. Regression coverage

The event-query regression suite in [wp-content/plugins/conexao-event-runtime/tests/test-event-query.php](../../wp-content/plugins/conexao-event-runtime/tests/test-event-query.php) covers:
- event transitions from today to yesterday;
- expired event exclusion;
- tomorrow becoming today;
- ongoing multi-day event visibility;
- cache behavior and flushing;
- language-scoped cache separation;
- preservation of archive ordering and filter behavior.

The tested assertions include:
- one-time past events are not in the list;
- multi-day events in progress remain visible;
- expired weekly events remain excluded;
- cache read matches fresh evaluation;
- flush_cache removes the transient.

## 5. Verification

### Focused verification

Command executed:

```bash
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-query.php
```

Result:
- 41 passed
- 0 failed
- exit 0

### Aggregate repository test runner

Command executed:

```bash
./scripts/run-tests.sh
```

Result:
- the command started normally and entered the in-process PHP suites
- the terminal was terminated with exit code 143 before the aggregate suite completed
- therefore this run is not a green repository-wide pass and is reported as incomplete, not as a successful full verification

## 6. Operational follow-up

No production writes were performed, and no production DB or filesystem changes were made.

If an operator still needs to validate a live production instance, the required check is to confirm that the live site is still serving the expected local-date event set, but the local runtime path and the deterministic regression suite already establish the underlying logic.

## 7. Final status

**PASS WITH LIMITATION**

The code-level runtime behavior is validated by the deterministic in-process event-query suite, but the full repository aggregate verification did not complete in this session because `./scripts/run-tests.sh` was terminated with exit code 143. That limitation is explicitly recorded here and is not described as a full green suite.

_Last verified: 2026-10-12 by GitHub Copilot investigation of the /eventos/ stale-results path_
