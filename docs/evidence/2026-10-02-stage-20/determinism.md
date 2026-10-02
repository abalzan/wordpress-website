# Stage 20 deterministic-run comparison (local Docker only)

## Run A (import twice, after purge)
  "found":30,"created":30,"updated":0,"unchanged":0,"duplicates":0,"skipped":0,"skipped_past":0,"skipped_invalid_date":0,"failed":0,"new":30,"errors":0
  "found":30,"created":0,"updated":0,"unchanged":30,"duplicates":0,"skipped":0,"skipped_past":0,"skipped_invalid_date":0,"failed":0,"new":0,"errors":0

## Baseline digest (before any Laois import, run A and run B)
  9af92ef8e2ea6c08157bddf242c9f710708fe7d1e5fbbcc2fa36e551793145b3  (identical both times)

## Post-import snapshot digest
  run A: 4310939d482347712c5e26329872835b689dd22b2143742f2f8cb72e03536108  (54 events)
  run B: differs only by auto-increment post IDs (1246..1376 vs 1342..1472)
  run B id-stripped digest: 63977f45a62ae8a9cda840c40cb684aa9b72fbe5a2ba5463fe694ef3b3b27662

## Laois event CONTENT digest (title + date + end + recurrence + days + window + source UID, ID-free)
  e920568d736417dabefcf97a5789a6a7  docs/evidence/2026-10-02-stage-20/runB-laois-content.tsv
  30 rows; 5 weekly series; identical across both runs
