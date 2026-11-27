# Stage 1 status and correlation model

## Correlation

`parent_run_group_id` identifies one logical Run All. Each source attempt gets
one unique `source_run_id`. Admin runs, child history rows, continuations and
parent refresh all carry the same parent ID; the server derives it from the
run context rather than trusting a client-provided display value.

## Parent statuses

The implementation accepts `queued`, `running`, `completed`,
`completed_with_errors`, `partial`, `rate_limited` and `failed`. Terminal
precedence is:

`failed` > `rate_limited` > `partial` > `completed_with_errors` > `completed`.

Legacy `success` and `warning` rows remain readable. Child rows preserve their
legacy status; parent aggregation maps warning-only completion to
`completed_with_errors` and retains the warning message/count. Parent rows are
replaced by the same run-group ID on repeat finalisation, preventing duplicate
aggregate rows.

## Distinct counters

`event_skipped_count` counts event-level eligibility/normalization skips.
`source_skipped_count` counts sources not attempted or deferred. Failed-source
disclosure stores the complete list and a total count; it is not silently capped
at twenty.
