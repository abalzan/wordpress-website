# Stage 1 isolation and scope evidence

- Initial branch: `event-import-improvements`.
- Initial HEAD: `c76d836a6ada1948924562b8dcea53ee2f9dd974`.
- Initial worktree was clean; it contained the pre-existing Stage 0/0.5
  records and reports.
- The local HTTP server remained attached to the shared `wordpress` database.
- The WordPress-dependent Stage 1 suite and the focused Eventbrite regression
  used only the disposable clone `conexao_stage05_test_5bd9ece5c032`.
- The Stage 1 suite's preflight reported `ISOLATED_DB_PREFLIGHT=PASS` and its
  provider transport was mocked.
- The Flutter/mobile repository was not accessed.
- No production endpoint, WordPress.com state, provider credential, upload,
  activation, deploy, release or commit was used.
- The 275-row shared `stage32_export_test` cohort and the 285 clone-only
  leftovers remain reconciled as separate environments; the latter were not
  counted as shared protected content.
