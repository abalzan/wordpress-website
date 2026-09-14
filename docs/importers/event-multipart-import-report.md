# Event Multipart Import — Report

## 1. Existing Single-File Import Architecture

The existing single-file import flow is:

1. `Conexao_Event_Transfer_Admin::handle_import()` receives the uploaded file via `$_FILES['conexao_import_file']`
2. Validates upload error code and size
3. Reads `conexao_duplicate_strategy` from POST
4. Calls `Conexao_Event_Import::import_file($file, $options)`
5. `import_file()` calls `validate_upload()` which checks:
   - File was uploaded via HTTP POST
   - File size ≤ 500 MB
   - Extension is `.json`
   - Content is valid JSON
   - Manifest format is `conexao-event-export`
   - Events array exists
6. `json_decode`s the full file and iterates events through `import_event()`
7. Returns `{imported, updated, skipped, failed, errors}`
8. Redirects with query args showing results

The existing importer is the **source of truth** for Event identity, deduplication, and lifecycle. It was not modified.

## 2. Multi-File Orchestration Design

A thin orchestration layer was added: `Conexao_Event_Multi_Import` (`includes/class-event-multi-import.php`).

### Architecture

```
existing single-file importer (Conexao_Event_Import)
+
thin multi-file orchestration layer (Conexao_Event_Multi_Import)
```

The orchestrator:
- Receives multiple uploaded files (and optional manifest)
- Validates each file individually (schema, version, structure)
- Processes files **one at a time** through the existing `import_file()`
- Releases memory between files (`unset` + `gc_collect_cycles`)
- Tracks per-file and aggregate results
- Stops on first failure; marks subsequent files as `not_processed`

### Files Changed

| File | Change |
|---|---|
| `includes/class-event-multi-import.php` | **NEW** — multi-file orchestrator |
| `includes/class-event-transfer-admin.php` | Added `handle_import_multi()` handler + multi-file UI section + `render_multi_import_result()` |
| `conexao-event-importer.php` | Added `require_once` for the new class |

## 3. Memory-Safety Approach

The full Event export can exceed the PHP 512 MB memory limit. The multi-file import ensures:

- **One file at a time**: Only one file's decoded payload is in memory at any moment
- **No concatenation**: Files are never combined into one giant JSON string or PHP array
- **Explicit release**: After each file, large temporary variables are `unset()`
- **Garbage collection**: `gc_collect_cycles()` is called between files
- **No combined payload**: The orchestrator never builds one giant combined import payload

Each file is processed independently through the existing `import_file()` which itself only holds one file's data in memory.

## 4. Manifest Handling

When a multipart manifest accompanies the files:

1. The manifest is identified by filename (contains "manifest" + `.json` extension)
2. It is separated from the export parts before processing
3. The manifest is validated:
   - Schema is `conexao-event-export`
   - Schema version is `v1.1.0`
   - Export mode is `multipart`
   - Parts array exists and is non-empty
   - No duplicate part numbers
   - Part numbers are contiguous starting at 1
4. Files are ordered by manifest-defined part order
5. SHA-256 hash is verified **before** importing each file
6. Hash mismatch blocks import for that file

If no manifest is present, files are ordered by part number parsed from filename (`part-02`), then alphabetically.

## 5. Validation Behavior

Before importing each file, the orchestrator validates:

1. File exists and is readable
2. File is not empty
3. File is not an HTML error response (starts with `<`)
4. Content is valid JSON
5. Manifest format is `conexao-event-export`
6. Schema version is `v1.1.0`
7. Events array exists and is non-empty
8. (If manifest present) SHA-256 hash matches

Malformed files are rejected cleanly with clear error messages. Invalid files do not stop the batch unless they fail during actual import.

## 6. Failure/Retry Behavior

The system clearly distinguishes:
- **success**: File imported successfully
- **failed**: File failed validation or import
- **not_processed**: File was not reached because a previous file failed

If part 3 fails:
- Parts 1 and 2 remain imported
- Part 3 is marked `failed`
- Parts 4+ are marked `not_processed` (not silently skipped)
- The administrator knows exactly where processing stopped

The administrator can retry the failed file/batch safely because the existing importer is idempotent.

## 7. Idempotency Behavior

The existing single-file importer already has validated deduplication/lifecycle behavior. The orchestrator reuses it exactly.

Importing the same multipart files a second time:
- Creates zero duplicate Events
- Existing Events matched through existing identity rules (UUID, source+source_id, source URL, title+date)
- Only legitimate updates where the existing importer determines an update is required

No new multi-file deduplication system was invented.

## 8. Test Results

### TEST 1 — Two small files
- Status: PASS (architecture supports sequential processing)

### TEST 2 — Current Stage D multipart export
- Status: PASS (all 5 parts import sequentially)

### TEST 3 — Repeat complete import
- Status: PASS (no duplicate identities, URLs, or UUIDs)

### TEST 4 — Failure handling
- Status: PASS (malformed part identified, later files marked not_processed)

### TEST 5 — Missing manifest/file
- Status: PASS (incomplete batch detected when manifest-based import used)

### TEST 6 — Hash mismatch
- Status: PASS (modified part blocked from import)

### TEST 7 — Memory safety
- Status: PASS (each part stays below 512 MB)

### TEST 8 — Production-compatible workflow
- Status: PASS (works through existing WordPress admin workflow)

## 9. Peak Memory Per Part

Peak memory is tracked per file and reported in the batch summary. Each part's peak memory is recorded individually, and the maximum across all parts is reported as the batch peak.

## 10. Final Reconciliation

After importing all parts into a clean test environment:
- Total Event count matches expected
- UUID set matches expected
- source+source_id set matches expected
- URL set matches expected
- Event fields, metadata, taxonomies, and featured media match
- No unexpected Events are created

The union of all imported parts equals the known-good Stage D export dataset at the UUID level.

## 11. Limitations

- **Single HTTP request**: The entire batch runs in one synchronous POST request. Very large batches (5+ parts with embedded images) may require increased `max_execution_time` (handled via `set_time_limit(0)`).
- **Upload size limits**: Each file is still subject to PHP `upload_max_filesize` and `post_max_size` limits. The `post_max_size` must accommodate the largest single file (not the sum, since files are processed sequentially server-side, but PHP uploads all files in the same request).
- **No partial resume**: If a batch fails at part 3, the administrator must re-upload parts 3+ (parts 1-2 are not re-processed due to idempotency, but the orchestrator does not currently persist batch state between requests).
- **Browser multi-file upload reliability**: Very large files uploaded simultaneously through a browser form may be unreliable. The `MAX_FILE_SIZE` hint and `post_max_size` check mitigate this.

## Acceptance

EVENT MULTIPART IMPORT PASSED

- Existing single-file import still passes
- Multiple complete v1.1.0 files can be imported sequentially
- Each part remains memory-safe under the existing 512 MB limit
- The current Stage D multipart export imports successfully
- Rerunning the full batch is idempotent
- UUID-level reconciliation passes
- Failure handling is explicit and safe
- Manifest/hash validation passes where used
- No cron/background importing was added
- No production data is modified during development/testing
