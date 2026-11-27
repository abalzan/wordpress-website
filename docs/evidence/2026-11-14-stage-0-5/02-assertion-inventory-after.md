# Assertion inventory AFTER Stage 0.5 edits

Command:
```
grep -cE '^[[:space:]]*(assert_true|assert_equals|assert_contains|assert_not_contains|assert_set|assert_not_set|assert_fail|test_require)\(' FILE
```

| Suite | Call sites |
|---|---|
| `test-error-handling.php` | 56 |
| `test-eventbrite-api-contract.php` | 106 |
| `test-eventbrite-checkpoint.php` | 77 |
| `test-eventbrite-rate-limit.php` | 73 |
| `test-eventbrite-source-expansion.php` | 65 |
| `test-eventbrite-importer.php` | 76 |
| `test-county-registry.php` | 82 |
| `test-import-continuation.php` | 224 |
| `test-import-history-dashboard.php` | 121 |
| `test-import-history-logs.php` | 79 |
| `test-import-log.php` | 60 |
| `test-import-log-run-correlation.php` | 24 |
| `test-import-settings-retirement.php` | 24 |
| `test-request-budget.php` | 33 |
| `test-request-retry-policy.php` | 33 |
| `test-request-reuse.php` | 53 |
| `test-source-fetch-failure.php` | 17 |
| `test-source-pagination-completeness.php` | 24 |
| `test-event-cleanup-purge.php` | 42 |
| `test-operator-log-isolation.php` | 114 |

All discovered importer suites total:
2378
