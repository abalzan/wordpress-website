# Evidence — enable all event sources (local)

Date: 2026-10-05 · Branch: `master` · Local Docker stack only

## What was asked

> enable all event sources

## What "an event source" is here

The importer's source registry (`conexao_event_sources`) holds one record per
source, and each record carries a `status` of `active` or `inactive`. The import
engine refuses to fetch any source that is not `active`
(`class-event-importer.php`), so that field **is** the Enable/Disable switch an
operator flips on the Event Sources screen — one source at a time, through a
form.

## What was done

| | |
|---|---|
| Mechanism | `scripts/set-event-source-status.php` (new, maintained, dry-run first) |
| Command | `--status=active --apply` |
| Changed | **133** sources `inactive → active` |
| Already active | **28** (reported `unchanged`, not rewritten) |
| Now active | **161 / 161** |
| Errors | 0 |
| Content touched | **none** — no post, meta, term, attachment or language relationship |
| Imports triggered | **none** |

Breakdown of the 133 changed, by type:

| Type | Changed |
|---|---|
| `eventbrite` | 104 (31 counties × `all-events` + `this_week` + `tomorrow` + `today` for the window sources) |
| `heritage_week` | 26 (county listings) |
| `website` | 3 (`ivvcc`, `motorsport_ireland`, `mondello_park`) |

Idempotence, measured: re-running the identical command reports
`selected=0, changed=0, unchanged=161`.

## Reversal recipe

`01-dry-run.json` and `02-apply.json` both carry a `previous` array — one
`{id, status}` per selected source — so the change is reversible exactly:

```bash
docker compose exec -T wordpress php /var/www/html/scripts/set-event-source-status.php \
    --status=inactive --ids=<comma-separated ids from the `previous` array> --apply
```

Every one of the 133 was `inactive` before, so the reversal is
`--status=inactive` over that id list.

## Two things the operator must know

### 1. Enabling is not running

Nothing was fetched. A run is still an explicit operator action, or the
continuation the importer schedules for an enumeration that stopped mid-way.

### 2. Enabling 161 sources does NOT make a single "Run all" able to fetch them all

This is a measured property of the providers, not an opinion:

- G5 measured Eventbrite answering **429 after roughly 30-36 requests** in one
  walk, and G5E then skips every remaining Eventbrite source *in that run* once
  the shared rate-limit latch trips. With 121 Eventbrite sources active, one
  "Run all" will therefore rate-limit early and skip the rest of the class.
- G18 (this same session) now **schedules a continuation** for every source left
  resumable, and that continuation fires automatically after its ~30-minute
  cooldown. So a rate-limited run is no longer a dead end: it retries itself,
  source by source, on the 30-minute ladder, until each source completes or is
  declared stalled (visible in Import History).

That is the designed behaviour, and it is bounded: one attempt per source per
cooldown, each attempt bounded by the existing 45-second time budget and the
400-request budget. It is still a lot of automated traffic, so expect the first
passes to be partial and to complete gradually rather than in one go.

## A latent defect this work uncovered, and fixed

Enabling the sources did not cause it, but verifying "no state was corrupted"
did find it: the shared test harness was corrupting **autoloaded** options.

| | |
|---|---|
| Symptom | `conexao_event_import_continuations` stored as the string `a:0:{}` instead of an array (`s:6:"a:0:{}";` on disk) |
| Root cause | `test_options_isolate()` snapshotted the **raw** value from `wp_load_alloptions()`, which returns the storage form (a serialised string), and `test_options_restore()` wrote it back verbatim — so `update_option()` serialised it a **second** time |
| Measured proof | an autoloaded `array( 'a' => 1 )` came back from `wp_load_alloptions()` as `'a:1:{s:1:"a";i:1;}'`; writing that back left the option holding `s:19:"a:1:{s:1:"a";i:1;}";` |
| Why it surfaced now | only **autoloaded** options are affected, and the continuation map is autoloaded on purpose (it is read on every request to decide whether anything is due) |
| Impact | fail-safe but silent: `get_all()` guarded the non-array, so the scheduler simply saw no continuations — it could quietly discard pending ones |
| Fix 1 (root cause) | `tests/lib/option-isolation.php` now applies `maybe_unserialize()` to the snapshot, so it holds the option's **value**, not its storage form |
| Fix 2 (defence) | `Conexao_Import_Continuation::get_all()` repairs a malformed row in place, documented as the only write in a read path |
| Regression guard | six assertions (`G18.0b`) in `test-import-continuation.php`: an isolate/restore round trip must return an **array**, a non-empty array must survive it with its contents, and a deliberately malformed row must fail safe **and** be repaired |

The already-corrupted row was repaired by the fix: the option now reads
`len=6, autoload=on, a:0:{}`.

## Files

| File | What it is |
|---|---|
| `01-dry-run.json` | `--status=active --json` in dry-run: the plan (133) and the reversal recipe, zero writes |
| `02-apply.json` | the same command with `--apply`: `changed=133, errors=0` |
| `03-verification.txt` | post-state, idempotence re-run, and the option-shape measurements |
