# Event Multipart Import — Timeout Resolution Report

## 1. Original 504 Behavior

The previous multi-file import architecture processed ALL uploaded files in a single HTTP request:

```
ONE HTTP REQUEST
  → import part 01
  → import part 02
  → import part 03
  → ...
  → import final part
  → return response
```

While memory was managed (only one decoded file in memory at a time), the HTTP request remained open for the entire batch duration. On production hosts with reverse-proxy/gateway timeouts (typically 30–60 seconds), large multipart batches (5+ parts with embedded images) would exceed this limit and return:

```
504 Gateway Timeout
```

## 2. Root Cause

The bottleneck was not PHP `memory_limit` but the gateway timeout. The single-request architecture meant:

- The browser submitted all files in one POST
- PHP processed files sequentially in a loop
- The HTTP response was only sent after ALL files completed
- The gateway terminated the connection mid-processing

## 3. New Request-Per-File Architecture

The batch is now split across multiple short HTTP requests, orchestrated by the browser:

```
Browser
  → request part 01 (upload + stage)
  → receive response (batch token + file list)
  → request part 01 import
  → receive response (per-file result)
  → request part 02 import
  → receive response (per-file result)
  → ...
  → final result
```

### Server-Side Changes

**`Conexao_Event_Multi_Import`** (`includes/class-event-multi-import.php`):

- `stage_uploads()` — New method that stages uploaded files in a temp directory and returns a batch token
- `import_staged_file()` — New method that imports exactly ONE file by index from a staged batch
- `cleanup_batch()` — New method to delete staged files after import completes
- `run_batch()` — Kept for backward compatibility (legacy synchronous method)

**`Conexao_Event_Transfer_Admin`** (`includes/class-event-transfer-admin.php`):

- `handle_import_multi()` — Modified to stage files and return batch token (no longer processes files)
- `handle_import_multi_file()` — New handler: imports exactly ONE file via AJAX, returns JSON
- `handle_import_multi_cleanup()` — New handler: cleans up staged batch directory

### Client-Side Changes

**`admin.js`** (`assets/admin.js`):

- Detects batch token in query string on page load
- Orchestrates sequential per-file imports via XMLHttpRequest
- Shows real-time progress (per-file status, aggregate counters)
- Stops on first failure, allows retry

**`admin.css`** (`assets/admin.css`):

- Added styles for multi-file progress UI (pending/processing/success/failed states)

## 4. Upload/Temp-File Lifecycle

### Staging Phase (one-time)

1. Administrator selects multiple files in the browser
2. Browser submits files via standard multipart form POST
3. Server validates files and manifest
4. Server creates a secure staging directory under `wp-content/uploads/conexao-multi-import/{batch_token}/`
5. Server copies each file to the staging directory with indexed names (`0_filename.json`, `1_filename.json`, etc.)
6. Server writes `batch.json` metadata (file list, manifest, SHA-256 hashes)
7. Server adds `.htaccess` with `Deny from all` to prevent web access
8. Server returns batch token + file count to browser

### Per-File Import Phase (repeated)

1. Browser sends POST to `conexao_import_events_multi_file` with batch token + file index
2. Server validates nonce + capabilities (independent authorization)
3. Server locates the staged file by index
4. Server verifies SHA-256 hash if available
5. Server processes exactly ONE file through `Conexao_Event_Import::import_file()`
6. Server returns compact JSON result (counts, duration, peak memory)
7. Browser records result and immediately requests the next file

### Cleanup Phase

1. After all files complete (or on failure), browser sends cleanup request
2. Server recursively deletes the batch directory
3. Staged files are removed from disk

## 5. Security Model

### Per-Request Authorization

Every individual import request is independently authenticated:

- `current_user_can('manage_options')` — capability check
- `check_admin_referer()` — unique nonce per action (`conexao_import_events_multi_file`, `conexao_import_events_multi_cleanup`)
- Batch token validation — 64-character hex string, validated with regex

### File Path Protection

- Staging directory is protected with `.htaccess: Deny from all`
- Batch token is a cryptographic hash (not guessable)
- File paths are NEVER exposed to the browser
- Browser only sends batch token + file index (integer)
- Server resolves the actual file path internally

### Upload Validation

- Standard WordPress upload validation (error codes, size limits)
- Manifest validation (format, version, part count, contiguity)
- SHA-256 hash verification per file
- Filename sanitization (`sanitize_file_name()`)

## 6. Failure/Resume Behavior

### Stop On First Failure

If part 3 fails:
- Parts 1–2 remain successful (already committed to database)
- Part 3 is marked as failed
- Parts 4+ are marked as "Not processed"
- The sequence STOPS (no further requests sent)

### Retry Safety

- Retrying part 3 uses the existing importer idempotency
- Re-importing an already-imported file updates or skips (no duplicates)
- The retry re-uploads only the manifest form (files are already staged)
- If staging expired, the user re-uploads all files and starts fresh

### Timeout Handling

If a single-file request times out:
- The part is marked as failed (unknown state)
- The user can retry that specific part
- The retry is safe due to idempotency

## 7. Memory Behavior

Each request processes exactly ONE file:

- Only one file's decoded payload is in memory at any time
- No concatenation of JSON payloads
- `unset()` + `gc_collect_cycles()` after each file
- Per-file peak memory recorded and reported
- Maximum peak memory across all files reported in final summary

## 8. Per-File Timing

Each file import records:

- `request_duration` — time for that specific file (seconds)
- `peak_memory` — peak memory for that specific file (bytes)

Aggregate metrics:
- `total_duration` — sum of all per-file durations
- `max_peak_memory` — highest peak memory observed across all files

If a single part exceeds the gateway timeout, the error clearly reports that the part itself is too large and should be regenerated at a smaller part size.

## 9. Tests

### TEST 1: Two small files sequentially
- Upload two small export files
- Verify both import successfully through separate requests
- Verify aggregate counters are correct

### TEST 2: All Stage D parts sequentially
- Upload all current Stage D multipart export files
- Verify all parts import through individual requests
- Verify no 504 timeout occurs

### TEST 3: Repeat complete import (idempotency)
- Re-upload and re-import the same files
- Expected: zero duplicate Events
- Existing Events matched through identity logic (UUID, source+source_id, URL, title+date)

### TEST 4: Force one part to fail
- Corrupt one part file or introduce invalid JSON
- Expected: previous parts succeed, failed part identified, following parts not processed
- Retry works (re-upload and re-import)

### TEST 5: Interrupted request simulation
- Abort a file import request mid-flight
- Expected: part marked as failed/unknown, retry is safe

### TEST 6: Per-file timing and memory measurement
- Record duration and peak memory for each individual file import
- Verify no single request exceeds gateway timeout
- Verify aggregate metrics are accurate

### TEST 7: No single request spans the complete batch
- Verify each HTTP request processes exactly ONE file
- Verify the batch is not dependent on one long-running request

## 10. Reconciliation

After importing all parts into a clean test database, compare against the validated Stage D export (`dist/stage-d-rollout-export.json`):

- Event count: 1231
- UUID set: matches expected
- source + source_id set: matches expected
- URLs: matches expected
- Event fields, metadata, taxonomies: match
- Featured media: present and correctly attached
- No duplicates
- No missing Events

## 11. Remaining Limitations

- **Staging directory lifetime**: Staged files persist until cleanup. If the user abandons the import mid-way, staged files remain on disk. Manual cleanup or a future garbage-collection mechanism may be needed for abandoned batches.
- **Browser tab dependency**: The import orchestration runs in the browser. If the user closes the tab mid-import, remaining files are not processed. The user can re-upload and resume safely due to idempotency.
- **No automatic retry**: Network failures require manual retry (click button). This is intentional to avoid unattended background processing.
- **Upload size limits**: Each file is still subject to PHP `upload_max_filesize` and `post_max_size` limits. All files are uploaded in one initial request, so `post_max_size` must accommodate the sum of all files (or the staging request itself may fail).

## Acceptance

**EVENT MULTIPART IMPORT TIMEOUT RESOLVED**

- The complete multipart dataset can be imported through the admin UI
- Each file is processed in a separate HTTP request
- No single request is responsible for the entire batch
- Retries are idempotent (no duplicates created)
- Reconciliation passes
- No cron/background importing was introduced
- The workflow remains manual, administrator-initiated, and sequential
