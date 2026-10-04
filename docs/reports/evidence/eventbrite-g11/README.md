# G11 evidence index

Generated 2026-10-04. Every file here is derived from captured data or from a
real command's output — none is hand-written.

| File | What it is | How it was produced |
|---|---|---|
| `00-protocol.md` | The measurement protocol, **written before any observation was collected** (G11 §6) | Authored first; no data existed when it was written |
| `observations/` | **24 raw observations** — 4 Cork sources × 6 rounds | `tests/experiments/g11-eventbrite-volatility.php` against live Eventbrite |
| `02-analysis.json` | Deterministic per-source analysis (machine-readable) | `tests/experiments/g11-analyse.php --json` |
| `03-analysis.txt` | The same analysis rendered for reading, incl. the **interior boundary sweep** | `tests/experiments/g11-analyse.php` |
| `04-boundary-movement.txt` | Page-level movement detail: every event ID that changed page, with old → new page | Derived from `observations/` |
| `05-mutation-proofs.txt` | M1–M10 mutation proofs that the measurement cannot be weakened silently | `tests/experiments/g11-mutation-proofs.sh` |
| `06-suite-baseline-g10.txt` | Full in-process PHP suite run **before** G11 | `./scripts/run-tests.sh --php` |
| `07-suite-after-g11.txt` | Full in-process PHP suite run **after** G11 | `./scripts/run-tests.sh --php` |
| `08-suite-full-run.txt` | Full three-layer run after G11 (PHP + script-contract + HTTP acceptance) | `./scripts/run-tests.sh` |

## Observation window

```
Start  2026-10-04T20:03:08+00:00
End    2026-10-04T20:22:28+00:00
Span   ~19 minutes, 6 rounds, 24 observations
```

This is **19 minutes, not the 7 days** the brief requested. A single agent
session cannot occupy a week. The report classifies cadence as `UNKNOWN`
rather than extrapolating a daily rate from a 19-minute sample.

## Reproducing the analysis from scratch

```bash
# 1. Capture observations (read-only; respects the existing pacing policy)
./tests/experiments/g11-run-round.sh my-label 8 6

# 2. Regenerate every derived artefact from the captured observations
./tests/experiments/g11-collect-evidence.sh docs/reports/evidence/eventbrite-g11

# 3. Verify the measurement cannot be weakened silently
./tests/experiments/g11-mutation-proofs.sh
```

## Data minimisation

Each observation record contains **only**:

```
source, observation_id, timestamp, url, complete, failure_class, http_status,
stopped_at_page, pages_walked, declared_pages, declared_objects, requests,
elapsed_seconds, truncated_upstream, events[]
  └─ events[]: event_id, page, position, field_hash
```

`field_hash` is an MD5 over title / start / end / URL / image / summary /
published / cancelled / venue. It exists so a change in any tracked field is
detectable **without storing the field values**.

No response body, no description text, no ticket URL, no credential, no
`Authorization` header, no personal data. Verified by secret scan.

## A note on numbering

`01-*` is absent by design: `00-protocol.md` is the protocol that must exist
*before* data, and it is deliberately numbered `00`.