# Stuck / abandoned continuation diagnostic rule (P12)

## 1. The state fields that actually exist

`Conexao_Import_Continuation::defaults()` defines exactly these state fields:

| Field | Meaning |
|---|---|
| `state` | `''` (none) \| `scheduled` \| `running` \| `exhausted` \| `failed` |
| `run_at` | UNIX time of the next attempt; `0` when nothing is scheduled |
| `scheduled_at` | when the continuation was last scheduled |
| `last_run_at` | stamped by `run_due()` **immediately before** the runner is called |
| `attempts`, `stalled` | attempt and consecutive-no-progress counters |
| `last_page`, `page_count` | enumeration position |
| `reason`, `message` | failure class / terminal cause |

There is no lease or heartbeat field. `last_run_at` is therefore the only progress
signal, and the rule says so rather than inventing one.

## 2. The rule

`Conexao_Import_Continuation::classify()` (read-only) assigns one class:

| Class | Condition |
|---|---|
| `none` | no record |
| `queued` | `state=scheduled` and `run_at > now` |
| `deferred` | `state=scheduled` and `run_at <= now` (the trigger has not fired) |
| `running` | `state=running`, `last_run_at > 0`, age ≤ threshold |
| **`running_stale`** | `state=running`, `last_run_at > 0`, age > `STALE_RUNNING_SECONDS`, and no lock is held |
| **`running_indeterminate`** | `state=running` and `last_run_at <= 0`: no timestamp can date the attempt |
| `exhausted` | terminal stall |
| `failed` | the continuation could not be armed |

`running_without_run_at` is reported as a separate FACT whenever
`state=running && run_at === 0`.

## 3. Why the threshold is 3,600 seconds

`STALE_RUNNING_SECONDS = 3600`, i.e. 4 × `LOCK_TTL` (900 s). The reasoning is
evidence-based:

1. `run_due()` takes the per-source lock (`add_option`, TTL 900 s) before it sets
   `state=running`, so a live worker always holds a lock;
2. a single run is bounded by the Eventbrite time budget and the in-walk pacing
   ladder, both well under 15 minutes;
3. `last_run_at` is stamped immediately before the runner call, so
   `now - last_run_at` is a real elapsed-progress measurement, not a guess;
4. therefore a record with no progress for 3,600 s **and no lock** cannot
   correspond to a live process.

A held lock is never called stale, whatever the age.

## 4. `running` with `run_at = 0`

The historical shape `state=running, run_at=0` is produced by `run_due()`
itself (`$record['state'] = running; $record['run_at'] = 0;`) while the worker
holds the source. It is therefore **not** evidence of a stall on its own. The rule
reports it as a fact and then lets the timestamp decide:

* with a recent `last_run_at` → `running` (healthy);
* with an old `last_run_at` and no lock → `running_stale`;
* with no `last_run_at` at all → `running_indeterminate` (needs inspection).

## 5. Indeterminate handling

A record with no usable timestamp is **never** declared stale. It is reported as
`running_indeterminate` with the label “running — no timestamp, needs
inspection”, which is the honest answer and the answer that does not tempt an
operator into a destructive action.

## 6. Read-only proof

`test-stage3-diagnostics-and-retention` asserts, in section C6, that reading the
diagnostics leaves **both** stores byte-identical:

```php
$before = wp_json_encode( get_option( Conexao_Import_Continuation::OPTION_KEY ) );
$before_ck = wp_json_encode( get_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY ) );
Conexao_Import_Continuation::get_diagnostics();
Conexao_Import_Continuation::get_diagnostic_counts();
assertEquals( $before, wp_json_encode( get_option( Conexao_Import_Continuation::OPTION_KEY ) ) );
assertEquals( $before_ck, wp_json_encode( get_option( Conexao_Source_Enumeration_Checkpoint::OPTION_KEY ) ) );
```

and in C7 that a stale record still reads `state=running` afterwards — the
diagnostic does not reset, retry, reschedule or delete it.

## 7. What the shared site actually holds (read-only observation)

22 continuation records; **15 in `state=running` with `run_at=0`**, 7 `scheduled`.
All 22 have an empty `run_id` (pre-Stage-3 shape). Their `last_run_at` values are
between 1 and 1.2 days old at capture, i.e. **beyond** the 3,600 s threshold, so
under this rule they are `running_stale` (or `running_indeterminate` where no
timestamp exists) and are now visible on the Event Sources screen.

**No recovery was performed.** Resetting, retrying or rescheduling them is
explicitly out of scope (see the investigation report, follow-up item 4).
