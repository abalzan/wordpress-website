## 21. Completion criteria

Standard §14: blocking semantics preserved, the new gate green, real numbers in
the report, no production action.

## 22. Operational findings observed while delivering this change

Recorded here because they are properties of the workflow being changed, and this
plan is the record of that change.

1. **`conexao-event-importer/tests/test-import-continuation.php` is load
   sensitive.** On commit `e586e5b` the `push` run failed in *Run in-process PHP
   suites* while the `pull_request` run of the **same commit** passed, and a
   later `workflow_dispatch` run of the same commit passed both the PHP and the
   HTTP layers. The suite drives a continuation cooldown and a wall-clock budget,
   so it degrades when the runner is busy. It is not caused by this change (this
   change touches no PHP suite) and it is not masked by it — the harness still
   fails closed.
2. **A `workflow_dispatch` on a branch cancels an in-flight `push` run for the
   same ref**, because `concurrency.group` is keyed on `github.ref`. The cancelled
   run's checks are left `pending`, which makes the PR report `UNSTABLE` even
   though every completed check is green. Prefer letting the in-flight run finish,
   or re-run it afterwards, rather than dispatching a second one while it is live.
3. **A failed run's artifact can be absent even when the upload step reports
   success**, because `if-no-files-found: warn` treats "no files" as success. When
   diagnosing, confirm the artifact exists before concluding an upload path is
   correct — the green run is the positive control.

_Last verified: 2026-11-28 by the CI reporting workstream_
