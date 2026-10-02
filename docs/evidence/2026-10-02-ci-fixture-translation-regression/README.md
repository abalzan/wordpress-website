# Evidence — CI fixture translation regression (`scripts/run-job-translation.php`)

Machine evidence for
[`docs/reports/2026-10-02-ci-fixture-translation-regression.md`](../../../reports/2026-10-02-ci-fixture-translation-regression.md),
investigating workflow run `36978530253` at checkout
`848e00ee29173dda1ab1c41c1181cec798641197`.

## Scripts

| File | Purpose |
|---|---|
| `ci-env-bootstrap.sh` | Local replica of the two CI steps *Prepare WordPress* and *Activate the repository theme and plugins* (`.github/workflows/ci.yml`), so a wiped local volume can be brought to the same state CI builds. Uses the same pinned `POLYLANG_VERSION=3.8.9`, `Europe/Dublin`, `pt_BR` and the same activation order. |
| `fresh-ci-run.sh` | Reproduces ONE clean integration run end to end: `down -v` → `up -d` → `ci-env-bootstrap.sh` → `bootstrap-ci-fixtures.php --apply --verify` → `./scripts/run-tests.sh`. It runs from a **single process on purpose**: the fixture corpus must have exactly one writer, and an earlier attempt that let a timed-out `docker compose exec` keep running produced a concurrent second writer and a spurious `wp_term_relationships` duplicate-entry (documented in the report, §11). |

Usage: `./fresh-ci-run.sh <label>` writes `/tmp/<label>.log` and prints `DONE`.

## Runs

Six clean runs were executed. `runs 1` and `runs 2` are the two runs of the
**delivered** change set; `runs 4` is the attribution run for §11a.

| Run | Change set under test | Bootstrap | In-process | Assertions | Notes |
|---|---|---|---|---|---|
| 1 | delivered (minimal) | exit 0, 150 created, 0 errors | 81/81 | 6305 | all job suites pass |
| 2 | delivered (minimal) | exit 0, 150 created, 0 errors | 81/81 | 6305 | identical to run 1 |
| 3 | + guide-pair `date` experiment | exit 0, 150 created, 0 errors | 80/81 | 6305 | experiment: fixed guide assertion, broke change-detection |
| 4 | fixture data file reverted to pristine upstream | exit 0, 150 created, 0 errors | 80/81 | 6304 | proves the guide clock race is pre-existing |
| 5 | + guide-pair `date` experiment (repeat) | exit 0, 150 created, 0 errors | 80/81 | 6305 | confirms the experiment's regression is reproducible |
| 6 | delivered (minimal, final) | exit 0, 150 created, 0 errors | 81/81 | 6305 | final confirmation of the delivered state |

The §11a guide-date experiment was present only in runs 3 and 5 and is **not**
part of the delivered change set; see the report for why it was reverted.

## The failure being fixed

In every run after the repair:

```text
$ grep -c 'expected script is missing' /tmp/run<N>.log
0
```

Before the repair, on the same script and database:

```text
ERROR: expected script is missing: scripts/run-job-translation.php
summary: created=0, updated=0, errors=1, skipped=0, conflicts=0, pt_changed=0
EXIT=1
```

## Regression protection

`tests/scripts/verify-script-conventions.py` §10 is the executable form of the
architecture contract. It was proven to fail on the original defect:

```text
FAIL: scripts/bootstrap-ci-fixtures.php invokes retired translation runner
      'run-job-translation.php'; use scripts/run-en-translation.php (the one
      lifecycle) or provision fixture data instead
726 passed, 1 failed
```

and passes on the delivered tree (`727 passed, 0 failed`).