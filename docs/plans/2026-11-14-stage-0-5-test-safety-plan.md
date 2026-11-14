# Plan — Stage 0.5: ordinary test execution must never destroy operator logs

> ## **Draft — revised after review; awaiting separate implementation approval. Nothing implemented.**
>
> This revision incorporates five required safeguards into the implementation
> instructions and into the acceptance criteria themselves, rather than adding
> them as a preface. No PHP, test, script or helper file was changed while
> producing it; no test suite, importer operation or database write was run.

| | |
|---|---|
| **Task title** | Stage 0.5 — test isolation for Event Importer operator logs |
| **Date** | 2026-11-14 (revised 2026-11-14, same day) |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `cda8b7b10b1e8112afc09222b70472bfc5d59ad7` (`HEAD` at revision time; `git status --short` shows only this plan and the audit as untracked) |
| **Applicable skill(s)** | `wp-testing`, `wp-write-in-process-test`, `wp-repository`; `wp-security-review` for the two admin handlers (unchanged) |
| **Plan status** | **draft — revised after review; awaiting separate implementation approval. Nothing implemented.** |
| **Audit that produced it** | [`docs/reports/2026-11-14-stage-0-5-test-safety-audit.md`](../reports/2026-11-14-stage-0-5-test-safety-audit.md) |
| **Authorisation state** | Plan revision only. Implementation, tests, importer runs, database writes, deploys and commits are **not authorised**. |

## Revision record — where each required safeguard is now enforced

| # | Required revision | Primary plan sections | Primary acceptance rows |
|---|---|---|---|
| 1 | Test isolation fails closed (bootstrap order, child processes, no silent fallback) | §4.1–§4.7, §5.1–§5.3 | §11 rows 3–5, 10, 11 |
| 2 | Restrictive reset helper (allowlist, refuse production keys) | §6.1–§6.7 | §11 rows 5, 6, 12 |
| 3 | Controlled non-test logging smoke test instead of a manual import | §12.1–§12.5 | §11 row 8 |
| 4 | Corrected assertion-count baseline requirement | §13.1–§13.5 | §11 row 7 |
| 5 | Shutdown, fatal and orphan-store guarantees (no SIGKILL claim) | §14.1–§14.5 | §11 rows 4, 9, 11 |

### Facts corrected during this revision (source inspection, not preference)

| # | Draft said | Source inspection shows | Consequence |
|---|---|---|---|
| C1 | Define `CONEXAO_TEST_OPTION_SUFFIX` **after** `wp-load.php` (`tests/bootstrap.php:120-129`) | The importer writes operator state **during** `wp-load.php`: `conexao-event-importer.php:345-350` hooks `wp_loaded` → `Conexao_Import_Continuation::rearm_missing()` (`class-conexao-import-continuation.php:964-1005`), which logs through `self::log()` (`:564-568`) → `Conexao_Import_Log::add()` → `persist_entries()` → `update_option( OPTION_KEY, … )` (`class-import-log.php:226`) | The suffix **must** be defined before the `require` of `wp-load.php`. See §4.1/§4.2. |
| C2 | "Move every redirected key out of `test_options_isolate()`" | Four suites write the option **directly** with `OPTION_KEY` (8 call sites: `test-import-log.php:274,312`; `test-import-history-logs.php:248`; `test-import-log-run-correlation.php:117`; `test-import-history-dashboard.php:507,536,863,908`). After redirection those writes would hit the **live** option and destroy the operator log | New mandatory step: retarget every direct write through a validated test-store helper (§5.4). |
| C3 | `test_options_isolate()` stays as-is for other options | After redirection, `test_options_isolate( [ OPTION_KEY ] )` would snapshot the **production** row and seed it empty — the original defect, restored | `test_options_isolate()` must **refuse** the four redirected keys and any test-prefixed name (§6.5). |
| C4 | "the 20 isolating suites" | 20 files call `test_options_isolate()`; only **18 discovered importer suites + 1 experiment** reference a redirected store (`g10-convergence-experiment.php`). `tests/experiments/g11-eventbrite-volatility.php` and the theme suite isolate **other** options | The migration inventory is enumerated exactly (§7.3); the other two files are untouched. |
| C5 | Eight `PARALLEL` entries at `:435,441,444,447,450,468,471,474` | Line numbers still match, but `test-eventbrite-importer.php` does not touch the log; it mutates **content** via reflection `upsert_event()` at `:696` | Demotion is retained for all eight, each with its own stated reason (§8.1). |
| C6 | Assertion totals must be identical before and after | No safe runtime baseline exists (the audit deliberately did not run the unsafe suite) | Replaced by a measured **static call-site inventory** plus real post-change totals; no claim of equality (§13). |

---

## 1. Scope and approval status

### 1.1 What this stage does

Make it **technically impossible** for the ordinary test run to erase, overwrite,
truncate, reset or delete a real operator log — while keeping importer logging
fully testable — and make the failure mode of a mis-configured harness a **loud
non-zero exit**, never a silent write to the operator's row.

Six deliverables, in dependency order:

1. **Storage seam.** The four operator log/history stores resolve their option
   name through a single accessor: production name in ordinary execution, a
   **per-process test name** whenever the test harness is loaded, and a hard
   failure when the harness is loaded but isolation is not established (§5).
2. **Fail-closed bootstrap.** `tests/bootstrap.php` establishes the isolation
   suffix **before** `wp-load.php` (because plugin loading can write — §4.1),
   refuses to continue when it cannot, and registers a store cleaner that can
   only ever name test rows (§4, §14).
3. **Restrictive reset/write helpers.** `tests/lib/option-isolation.php` gains a
   validated store resolver, a writer, and a reset helper that validates **every**
   requested key before deleting **anything**; `test_options_isolate()` and
   `test_options_restore()` start refusing the redirected keys (§6).
4. **Removal of the destructive test cleanup.** No suite deletes the cleanup
   history/status; no suite runs the global cleanup engine (§7).
5. **Correct classification.** Eight shared-state suites move to `SERIAL`; no
   store-touching suite may be `PARALLEL` (§8).
6. **Permanent proof.** A falsifiable acceptance suite (§10, §11, §12) plus a
   permanent static gate (§9) that fails on regression *and* on a missing
   prerequisite.

### 1.2 Authorisation state

| Action | Authorised now? |
|---|---|
| Reading the repository, the audit, the sources | **yes** |
| Revising this plan | **yes — this document is the result** |
| Editing PHP production code, tests, helpers, scripts | **no** |
| Creating the acceptance suite or the static gate | **no** (specified here, built in the implementation stage) |
| Running PHPUnit / `./scripts/run-tests.sh` / any suite | **no** |
| Running the importer, cleanup scripts or any content operation | **no** |
| Writing WordPress options, DB rows, logs or event content | **no** |
| Docker start/rebuild/reset, volume changes | **no** |
| Commit, push, merge, deploy, activate, release | **no** |
| Editing the Stage 0 plan/report/evidence | **no** |

Implementation begins only after a separate, explicit approval of **this revised
plan**. That approval must name the branch/commit it starts from.

### 1.3 Authoritative documents

| Fact | Owner |
|---|---|
| Engineering rules, testing standard §8, definition of done §14 | [`docs/engineering-standard.md`](../engineering-standard.md) |
| Test model, layers, classification, shared bootstrap | [`docs/testing.md`](../testing.md) |
| Operator log/history stores, lifecycle, retention | [`docs/plugins/conexao-event-importer.md`](../plugins/conexao-event-importer.md) |
| Option keys, call sites, autoload, caps | the code, cited line by line below and in audit §3 |
| Suite discovery and classification | [`scripts/run-tests.sh`](../../scripts/run-tests.sh) |
| Script inventory | [`scripts/README.md`](../../scripts/README.md) |
| Evidence conventions | [`docs/evidence/README.md`](../evidence/README.md) |
| Content model (no content change here) | [`docs/content-model.md`](../content-model.md) |

### 1.4 Skill workflow

| | |
|---|---|
| **Skill** | `wp-testing` (with `wp-write-in-process-test` for the new suite) |
| **Preconditions** | reuse the running local Docker stack (`docker compose ps` first); capture a **before** result for anything changed; `CONEXAO_TEST_BASE_URL` local; no rebuild, no volume reset, no seeding without explicit approval |
| **Binding guardrails** | never weaken an assertion to obtain a pass; a new gate must be proven to fail on a violation **and** on a missing prerequisite; a blocked/not-run verifier is never a pass; no production host |
| **Verification commands** | `./scripts/run-tests.sh --scripts`, `./scripts/run-tests.sh --only conexao-event-importer`, the full `./scripts/run-tests.sh` in both worker modes, then `./scripts/lint.sh` |
| **Evidence location** | `docs/evidence/2026-11-14-stage-0-5/` |

---

## 2. Existing audit findings (verified, retained)

### 2.1 Confirmed destructive paths

| # | Path | What it destroys | Source |
|---|---|---|---|
| A1 | `test-event-cleanup-purge.php:536-537` | the live `conexao_event_cleanup_status` / `conexao_event_cleanup_history` rows, unconditionally, on every ordinary run | audit §4.1 |
| A2 | `scripts/test-event-cleanup.php:241-242` | the same two live rows | audit §4.1 |
| A3 | `test-event-cleanup-purge.php:508` → `Conexao_Event_Cleanup::run_cleanup()` (`class-event-cleanup.php:66-93`, `find_past_events()` `:140`) | **every** past event in the live database, both languages; the suite asserts only "at least the fixtures" (`:510-518`) | audit §4.1 |
| B | 18 discovered importer suites + `g10-convergence-experiment.php` call `test_options_isolate()` on a redirected store | empties the live log/history for the duration of the suite and restores at shutdown — destruction on abnormal termination, and it has already happened once (599 → 91 → 0) | audit §4.2 |
| C | 6 `PARALLEL` suites sharing the log/history row (CI runs `CONEXAO_TEST_WORKERS=3`) | cross-worker restores can write a stale emptied value over another worker's data | audit §4.3 |
| D | 6 suites append to the live log on failure branches (`upsert_event()` paths) | log contamination whenever an assertion path fails | audit §4.4 |
| D7–D9 | 4 suites replace `conexao_event_sources` in place with no shutdown net | operator source configuration survives only if the suite reaches its restore | audit §4.4 |

### 2.2 Environment boundary (audit §5, unchanged)

- No dedicated test database, no `wp-tests-config.php`, no `WP_TESTS_*`.
- `tests/bootstrap.php` loads the **same** local WordPress install the operator
  UI uses (`:120`), so every option row is shared, byte for byte.
- The only environment guards today live in unreachable `tests/experiments/`
  scripts. Nothing in the bootstrap or the isolation helper checks the
  environment type.
- CI's ephemeral container is protected only because it is destroyed afterwards;
  a developer workstation has no isolation at all.

### 2.3 Bootstrap-order finding (new; settles the "define it after `wp-load`" question)

The draft proposed defining the suffix after `wp-load.php`. **That is too late.**
The verified chain:

1. `tests/bootstrap.php:35` defines `CONEXAO_TESTS_ROOT`; `:120` requires
   `wp-load.php`.
2. `wp-load.php` → `wp-settings.php` loads active plugins and then fires `init`
   and `wp_loaded` **before returning** (WordPress load contract; core is not part
   of this checkout, so this step is stated as a documented contract, not as a
   repository line — recorded as a limitation in §9.5).
3. `conexao-event-importer.php:345-350` registers
   `wp_loaded` → `Conexao_Import_Continuation::rearm_missing()`.
4. `rearm_missing()` (`class-conexao-import-continuation.php:964-1005`) calls
   `self::log( … )` when it re-arms a lost continuation.
5. `self::log()` (`:564-568`) → `Conexao_Import_Log::add()`.
6. No managed run is active at `wp_loaded`, so `add()` persists immediately:
   `persist_entries()` → `update_option( self::OPTION_KEY, $log, false )`
   (`class-import-log.php:226`).

**Consequence.** A test process today can append to the **live** operator import
log *inside* `wp-load.php`, before any statement after the `require` in the
bootstrap or in a suite can run. Two further consequences:

- The suffix constant must be defined **before** line 120. That is the only
  insertion point that also protects plugin-load-time writes (including any
  future one).
- A suite-level guard is **necessary but not sufficient**: it cannot protect a
  write that happened during plugin load. It stays as the secondary safeguard
  required by revision 1, item 5.

### 2.4 Residual risks this design cannot remove

| Residual | Why it remains | Mitigation |
|---|---|---|
| A hand-run PHP file that requires `wp-load.php` directly and then exercises importer logging resolves production storage | The accessor cannot know it is "a test" when no harness marker is present | Every discovered entry point already requires `tests/bootstrap.php` and the gate enforces that it keeps doing so (§9.2 check 6); non-discovered diagnostics are enumerated and asserted by the gate |
| `SIGKILL` / uncatchable termination leaves a test row behind | PHP cannot run shutdown handlers on `SIGKILL` | The orphan is an isolated test key by construction and can never be an operator key (§14); a documented manual sweep exists (§14.4) |
| The production row is still writable by a **non-test** process | That is production behaviour and out of scope here | Unchanged by this stage; proven intact by §12 |

---

## 3. The chosen architecture

### 3.1 The design, in one paragraph

Give each operator **log/history** store a single accessor that resolves to the
production option name in ordinary execution and to a **per-process test option**
(`…__test__p<pid>_<token>`) when — and only when — the test harness is loaded.
Tests read and write a row that can never coincide with the operator's, every
production code path (`add`, buffer, flush, cap, prune, clear, export, dashboard
read) stays the same code executing against a different row, and a harness that
loads without a valid suffix **dies non-zero instead of writing production**.

### 3.2 Options evaluated

| # | Option | Reliability | Change size | Hides defects? | Compatible with existing tests | Local dev | Maintenance | Parallel | Provable |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Separate test database / second WP install | highest | **largest** (compose, CI, dev docs, acceptance layer, every `run-tests.sh` path) | no | yes, but acceptance suites need the live site anyway | heavy (two stacks) | high | excellent | yes |
| 2 | **Storage redirection in the store (chosen)** | high | **small, surgical** (3 classes, 11 call sites) | no — the same code runs, against a test row | yes; assertions become *more* deterministic | none | low | excellent with a per-process suffix | yes |
| 3 | Dependency injection / instance logger | medium | largest (~45 call sites + constructor threading) | no | partly (doubles replace real code → hides defects) | none | high | good | weakly — tests stop exercising the real class |
| 4 | Test-only logger subclass | medium | medium | **yes** — the real `add()`/flush/cap/clear path leaves the test surface | no | none | n/a | no |
| 5 | Narrow allowlisted option cleanup (status quo) | **insufficient** | none | no | yes | none | low | no — audit §4.2 failure modes 1–3 |
| 6 | Snapshot + restore (status quo) | **insufficient** | none | no | yes | none | low | no — cannot survive termination or concurrency |
| 7 | Guards that block `clear()` in tests | medium | small | **yes** — `clear()` coverage disappears | no | none | n/a | no |
| 8 | Disable logging in tests | — | small | **yes** | no | none | low | no |

**Rejected:** 1 (right long-term, too large, and it does not by itself fix the
shared-option habit), 3, 4, 7, 8 (they hide real behaviour or stop testing the
real class), 5 and 6 (proven insufficient by the audit). **Chosen: 2**, combined
with the existing helper for the *non-log* options it can still serve.

Rejected for **this** stage and kept as follow-ups: the isolated database
(option 1) and re-promotion of any importer suite to `PARALLEL`.

### 3.3 Why a production-code change is genuinely necessary

The option key is a `const` inside the store (`class-import-log.php:25`,
`class-import-history.php:15`, `class-event-cleanup.php:25`, `:32`). Nothing
outside the store can redirect it: WordPress exposes no "skip the write but keep
`updated_option` firing" filter, so a `pre_option_*` / `pre_update_option_*` fake
would either still write the real row or break the write-count assertions in
`test-import-log.php:49-60`. The only smaller-than-a-database alternatives are
(3), (4), (7) and (8), all rejected above. So **yes**: three small production
files change, inert outside a test process, with **no** production behaviour,
constant, default, cap or autoload flag altered.

### 3.4 What stays production-inert, and why

- every read/write still goes through `get_option()`/`update_option()` with the
  same shape, autoload flag and cap;
- `OPTION_KEY`, `STATUS_OPTION` and `HISTORY_OPTION` keep their values and their
  public visibility (docs, tests and the acceptance suite all compare against
  them);
- when neither harness marker is defined the accessor returns `OPTION_KEY` — one
  `defined()` check, no branch on request type, no new hook, no new filter;
- the only new behaviour in production-adjacent code is a **throw** that can fire
  only when a test harness marker is defined, which no production file may do
  (gate check 11).

### 3.5 Design deltas against the reviewed draft

| Area | Draft | Revised |
|---|---|---|
| Where the suffix is defined | after `wp-load.php` | **before** `wp-load.php` (§4.2), because plugin load writes (§2.3) |
| Fallback behaviour | silently returns `OPTION_KEY` when the suffix is missing or invalid | throws when a harness marker is present; **never** falls back (§4.3) |
| Suffix uniqueness | `p<pid>` | `p<pid>_<random token>` (§14.2) |
| Cleanup registration | `register_shutdown_function()` | `add_action( 'shutdown', …, 999 )`, after the store's own `flush` (priority 5) (§14.3) |
| Reset helper | deletes whatever keys it is given | validate-all-then-delete against a four-store allowlist, returns a structured result (§6) |
| Direct option writes in suites | not addressed | 8 call sites retargeted through a validated helper (§5.4) |
| `test_options_isolate()` | unchanged | refuses the four redirected keys (§6.5) |
| Proof of production path | one manual import | controlled non-test-process smoke test, no import (§12) |
| Assertion baseline | "identical counts" | static inventory + real post-change totals, no equality claim (§13) |

---

## 4. Fail-closed bootstrap and child-process isolation

*(Required revision 1 — highest priority.)*

### 4.1 The insertion point (investigated, not assumed)

| Question | Answer, with the evidence |
|---|---|
| When does WordPress load the plugin? | during `wp-load.php` → `wp-settings.php` (active plugins), triggered by `tests/bootstrap.php:120` |
| When do plugin initialisation hooks run? | `init` and `wp_loaded` fire **inside** `wp-load.php`, before it returns |
| When do the logging classes become available? | at plugin include: `conexao-event-importer.php:22` requires `class-import-log.php`, `:24` `class-import-history.php`, `:65` `class-event-cleanup.php`. `Conexao_Event_Importer::instance()` runs at include time (`:353`) |
| When does the test bootstrap define its constant today? | never — the constant does not exist yet |
| **Can code write to an operator store before the suite body runs?** | **Yes.** `wp_loaded` → `rearm_missing()` → `Conexao_Import_Log::add()` → `update_option( OPTION_KEY, … )` (§2.3) |
| **Safest insertion point** | **before** `require_once CONEXAO_TESTS_WP_ROOT . '/wp-load.php'` (line 120), using only plain PHP (`defined`, `getmypid`, `random_bytes`, `preg_match`, `define`) — no WordPress function is needed or allowed there |

Defining the constant **before** `wp-load.php` is therefore not a stylistic
choice; it is the only point that covers plugin-load writes:

```
bootstrap start
  └─ CONEXAO_TESTS_ROOT (35)
  └─ [NEW] suffix established + validated          ← before any plugin code runs
  └─ wp-load.php (120)
        ├─ plugins loaded        (writes → test row)
        ├─ init
        └─ wp_loaded             (rearm_missing → add → test row)
  └─ usable-WP check (122-124)
  └─ [NEW] fail-closed prerequisites
  └─ assertions + option-isolation libs (128-129)
  └─ [NEW] register store cleaner
  └─ suite body                      (guard call = secondary safeguard)
```

### 4.2 The revised `tests/bootstrap.php` contract

Exact order; every new step **fails closed** via `conexao_tests_bootstrap_fail()`
(`:46-49`, which writes `conexao-test-bootstrap: …` to `STDERR` and `exit( 1 )`).
The runner already classifies that line as `[BLOCKED]`, never as a pass
(`run-tests.sh:810`, `:908`).

| Step | Location | Behaviour |
|---|---|---|
| 1 | existing `:35` | `define( 'CONEXAO_TESTS_ROOT', __DIR__ )` — the harness marker the accessors key off |
| 2 | existing `:46-90` | fail helper, `wp-load` discovery, failure when absent |
| 3 | existing `:106-118` | CLI request context |
| 4 | **NEW — before `:120`** | `conexao_tests_establish_isolation()`: refuse if `CONEXAO_TEST_OPTION_SUFFIX` is **already defined** (no predefinition, no inheritance); `$pid = getmypid()` must be a positive int; `$token = bin2hex( random_bytes( 8 ) )` inside `try/catch ( Throwable )`; `$suffix = 'p' . $pid . '_' . $token`; must match `/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/`; only then `define( 'CONEXAO_TEST_OPTION_SUFFIX', $suffix )`. Any failure → `conexao_tests_bootstrap_fail()` |
| 5 | existing `:120` | `require_once …/wp-load.php` — every plugin-load write now lands in the test row |
| 6 | existing `:122-124` | usable-WordPress check |
| 7 | **NEW — after `:124`, before the libs** | fail-closed prerequisites, wrapped in `try { … } catch ( Throwable $e ) { conexao_tests_bootstrap_fail( $e->getMessage() ); }`: if `class_exists( 'Conexao_Import_Log' )` (plugin active) then each of `Conexao_Import_Log::option_key()`, `Conexao_Import_History::option_key()`, `Conexao_Event_Cleanup::status_option()`, `Conexao_Event_Cleanup::history_option()` must (a) differ from its production constant, (b) start with its `TEST_OPTION_PREFIX`, (c) end with the current suffix. If the plugin is **not** active only the suffix assertion applies (no store can write) — theme suites keep working unchanged |
| 8 | existing `:128-129` | require `tests/lib/assertions.php`, `tests/lib/option-isolation.php` |
| 9 | **NEW — end of file** | `add_action( 'shutdown', 'conexao_tests_clean_option_stores', 999 )` (§14.3) |
| 10 | **NEW — same file** | `conexao_tests_clean_option_stores()`: resolves the four keys through the accessors; deletes a key only when it is test-prefixed **and** carries the current suffix; if any resolved key fails validation it deletes **nothing**, prints one `conexao-test-cleanup: …` line to `STDERR`, and never touches production |

Production refusal (`wp_get_environment_type()`, `home`/`siteurl` host) stays —
moved to after step 6, because `wp_get_environment_type()` needs WordPress.
**It is no longer what protects the operator row**; the suffix does. Saying so
explicitly stops a future reader from treating the environment check as the
safety property.

### 4.3 The accessor's fail-closed contract (all four stores)

```php
if ( ! defined( 'CONEXAO_TESTS_ROOT' ) && ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
	return self::OPTION_KEY;                       // ordinary production execution
}

$suffix = self::test_option_suffix();              // '' when absent/invalid
if ( '' === $suffix ) {
	throw new RuntimeException(                    // loud, non-zero, before any write
		'conexao: operator-store isolation was not established for the import log store.'
	);
}
return self::TEST_OPTION_PREFIX . $suffix;

/**
 * @return string Validated suffix, or '' when missing/malformed.
 */
protected static function test_option_suffix() {
	if ( ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
		return '';
	}
	$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );
	return preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ? $suffix : '';
}
```

Requirement → enforcement map (revision 1, items 1–9):

| Requirement | Enforced at (exact location) | Proved by |
|---|---|---|
| 1. Production resolves the existing names | `class-import-log.php::option_key()`, `class-import-history.php::option_key()`, `class-event-cleanup.php::status_option()` / `history_option()` — first branch | §12 smoke test; gate check 3 |
| 2. Bootstrap establishes the suffix before any write | `tests/bootstrap.php`, new step 4, line before the `wp-load.php` require (`:120`) | gate check 4 (order by line number); suite A1 |
| 3. Every entry point isolates before suite setup | `wp-content/plugins/conexao-event-importer/tests/test-*.php` line 1 `require` of `tests/bootstrap.php` (37/37 verified) | gate check 6; suite A1 |
| 4. Bootstrap refuses when prerequisites are missing | `tests/bootstrap.php` step 4 (`conexao_tests_establish_isolation()`) and step 7 (post-load accessor verification) | suite A3 child variants; gate check 5 |
| 5. Suite guard stays as a secondary safeguard | `tests/lib/option-isolation.php::test_require_operator_store_isolation()`, called at the top of each store-touching suite | gate check 6; §11 row 10 |
| 6. Every child process establishes its own store | child code generated at `test-bulk-actions.php:262` and the `--child-invalid-run` branch at `test-import-history-logs.php:42-47` both require the bootstrap | suite E, F, G, H |
| 7. A child must not assume the parent's suffix | `tests/bootstrap.php` step 4 refuses an already-defined suffix; no other file may define it | gate check 11; suite H |
| 8. Missing/invalid/empty config fails non-zero, never falls back | the throw in the three accessors + `tests/bootstrap.php` steps 4/7 | suite A3 |
| 9. Production unchanged without the harness | neither marker exists in production; gate forbids defining it anywhere else | §12 smoke test; gate checks 3, 11 |

**Why `RuntimeException` and not a fallback or a `WP_Error`:** `update_option()`
cannot return an error to a caller that ignores it, so the only honest signal is
an exception that terminates a CLI process non-zero *before* the write. `SPL` is
always available: no new class, file or dependency.

### 4.4 Entry-point inventory and guard placement

| Set | Count | Fact | Required change |
|---|---|---|---|
| Discovered importer suites (`wp-content/plugins/conexao-event-importer/tests/test-*.php`) | 37 | **all 37** already require `tests/bootstrap.php` | covered by the bootstrap; each store-touching suite also calls the guard |
| Discovered importer suites that reference a redirected store | **18** | 17 isolate via `test_options_isolate()`, 1 (`test-event-cleanup-purge.php`) deletes | enumerated in §7.3 |
| Non-discovered importer experiment referencing a store | **1** (`g10-convergence-experiment.php`) | already requires the bootstrap | add the guard; the gate enumerates it |
| Files calling `test_options_isolate()` for **non-store** options | **2** (`tests/experiments/g11-eventbrite-volatility.php`, theme `test-empregos-job-resources.php`) | isolate `conexao_event_sources` / `conexao_job_resources`, not a redirected store | **no change**; they only meet the new refusal rule in `test_options_isolate()`, which they never trigger |
| Non-discovered diagnostics loading `wp-load.php` directly (`c2-*.php`, `dry-run-*.php`, …) | remaining files | none references a redirected store (grep-verified during this revision) | out of scope; the gate asserts the set stays free of store references |

**Guard placement rule (mechanical, gate-checked):** in every store-touching
suite the call

```php
test_require_operator_store_isolation();
```

appears **after** the bootstrap + plugin `require` and **before** the first line
that touches a store (the first `test_options_isolate()` call, the first
`Conexao_Import_Log` / `Conexao_Import_History` / `Conexao_Event_Cleanup`
reference, or the first test-store helper call). The gate enforces the ordering
by line number, so a guard appended at the end of a file fails the build.

### 4.5 Child processes and workers

| Spawn site | How it starts | Isolation consequence | Required action |
|---|---|---|---|
| `test-bulk-actions.php:249-296` (`bulk_run_handler()`) | writes a temp PHP file, then `exec( PHP_BINARY … )` (`:290`); the generated code requires `tests/bootstrap.php` (`:262`) and the plugin (`:263`) | the child's own `require` defines a **fresh** suffix → a distinct row | none beyond the parent-side retargets; the gate asserts the generated child code keeps requiring the bootstrap |
| `test-import-history-logs.php` | child mode: re-executes the same file with `--child-invalid-run` (`:744-747`); the file requires the bootstrap at `:42` **before** the child branch at `:47` | the child gets its own suffix automatically | assert the child branch stays **after** the bootstrap require (gate ordering check) |
| Parallel pool (`run-tests.sh:869-879`, `run_php_captured`) | one OS process per suite, `timeout` per suite (`:48`, `:583-598`) | one suffix per process; no two workers share a key | none; §8 removes the shared **non-log** state instead |
| Future children | unknown | — | rule: child code must require `tests/bootstrap.php`; **never** copy `CONEXAO_TEST_OPTION_SUFFIX` into child code or the environment (gate check 7) |

The bootstrap's refusal of a pre-defined suffix is what makes "a child inherits
the parent's assumption" impossible: inheritance would arrive as a defined
constant, and the bootstrap then exits non-zero instead of silently sharing the
parent's row.

### 4.6 What fails closed, and how loudly

| Condition | Result |
|---|---|
| `CONEXAO_TEST_OPTION_SUFFIX` already defined when the bootstrap runs | `conexao-test-bootstrap:` line, `exit 1` |
| `getmypid()` unavailable / non-positive | same |
| `random_bytes()` throws | same |
| suffix fails the pattern | same |
| suffix defined but an accessor resolves to the production name | same (step 7) |
| harness marker defined, suffix absent (a file loading `tests/lib/*` without the bootstrap) | `RuntimeException` on the first store resolution, before the write |
| the current process's test row is missing at cleanup time | nothing deleted, one `conexao-test-cleanup:` line; **not** a reason to weaken isolation (§14.3) |

### 4.7 What this design deliberately does not claim

A file that loads `wp-load.php` itself and never touches the harness resolves
production storage. That is not "isolation failing"; it is *not being a test
process*. The gate keeps such files from appearing inside the discovered suite
tree (§9.2 check 6), and the audit's F1 caveat (not every one of 137 suites was
read in full) is carried forward as a scanning rule rather than as a memory.

---

## 5. Store accessors and production fallback

### 5.1 `includes/class-import-log.php`

```php
const OPTION_KEY = 'conexao_event_import_log';

/** Prefix of the per-process test store. Never equal to OPTION_KEY. */
const TEST_OPTION_PREFIX = 'conexao_event_import_log__test__';
```

`option_key()` per §4.3. Replace **four** `self::OPTION_KEY` uses:
`persist_entries()` `:226`, `get_all()` `:235`, `clear()` `:357`,
`prune_older_than_90_days()` `:565`. Nothing else changes: the buffer
(`:147-160`, `:170-173`), cap (`MAX_ENTRIES`), flush, sanitisation and the
shutdown flush registration (`add_action( 'shutdown', …, 5 )`) are untouched.

### 5.2 `includes/class-import-history.php`

Same shape (`TEST_OPTION_PREFIX = 'conexao_event_import_history__test__'`),
replacing three uses: `record()` `:100`, `get_all()` `:111`, `clear()` `:169`.

### 5.3 `includes/class-event-cleanup.php`

`status_option()` / `history_option()` with prefixes
`conexao_event_cleanup_status__test__` and
`conexao_event_cleanup_history__test__`, replacing four uses: `save_status()`
`:536`, `get_status()` `:545`, `save_history()` `:572`, `get_history()` `:581`.
`STATUS_OPTION` (`:25`) / `HISTORY_OPTION` (`:32`) keep their values.

No production reader outside these classes names the four options (verified by
grep in this revision): the dashboard reads through `get_all()`. That is why the
seam can be three files.

### 5.4 Direct option writes inside suites (new mandatory step)

After redirection, a suite that writes `update_option( …OPTION_KEY… )` writes the
**live** row. These eight call sites must be retargeted through the test-store
writer (§6.6), never left as they are:

| File | Lines | Intent | Required change |
|---|---|---|---|
| `test-import-log.php` | `274`, `312` | seed 2,000 entries / pre-existing run entries | `test_options_write_test_store( 'import_log', $value )` |
| `test-import-history-logs.php` | `248` | seed the log for the run's entries | same, `'import_log'` |
| `test-import-log-run-correlation.php` | `117` | seed "operator" entries | same, `'import_log'` |
| `test-import-history-dashboard.php` | `507`, `536`, `863`, `908` | empty the history / restore seeded history around render assertions | `test_options_write_test_store( 'import_history', $value )` |

Two further targets change meaning rather than target:

| File | Lines | Current | Required |
|---|---|---|---|
| `test-import-log.php` | `49-60` | counts `updated_option` for `OPTION_KEY` | count for `Conexao_Import_Log::option_key()` **and** add one assertion that the live key received zero writes during the suite |
| `test-import-history-logs.php` / `test-import-history-dashboard.php` | `893-895`, `848-850` | "render did not change `OPTION_KEY`" | keep the live-key assertion **and** add the same assertion for `option_key()` (the row the render actually reads) — a strict strengthening |

Assertions that compare `OPTION_KEY` with the literal production name
(`test-import-history-logs.php:817`, `test-import-history-dashboard.php:652`,
`:833`) stay **unchanged**: they assert the production constant is stable, which
this stage must keep true.

---

## 6. Strict reset-helper allowlist

*(Required revision 2.)*

### 6.1 The four authorised stores

| Symbolic store key | Production constant | Test prefix (owner) | Owning class |
|---|---|---|---|
| `import_log` | `conexao_event_import_log` | `conexao_event_import_log__test__` | `Conexao_Import_Log` |
| `import_history` | `conexao_event_import_history` | `conexao_event_import_history__test__` | `Conexao_Import_History` |
| `cleanup_status` | `conexao_event_cleanup_status` | `conexao_event_cleanup_status__test__` | `Conexao_Event_Cleanup` |
| `cleanup_history` | `conexao_event_cleanup_history` | `conexao_event_cleanup_history__test__` | `Conexao_Event_Cleanup` |

This table is the allowlist. It lives in `tests/lib/option-isolation.php`, is the
**only** place that enumerates the four stores for test purposes, and the gate
asserts it matches the production constants and prefixes exactly (checks 1 and 8).

### 6.2 The resolver (pure, non-fatal)

```php
/**
 * @param mixed $request A symbolic store key or an option name.
 * @return array{ok:bool,key:string,store:string,reason:string}
 */
function test_operator_store_resolve( $request );
```

Rules, in order:

1. not a string, or `''` → `invalid_type` / `empty`
2. matches a symbolic key → resolve via the owning class accessor
3. equals one of the four production constants → `production_key`
4. equals a four-prefix base name (e.g. `…_log__test__`, no suffix) → `malformed`
5. starts with one of the four prefixes without the current process suffix →
   `foreign_process`
6. starts with one of the four prefixes and carries the current suffix → `ok`
7. anything else → `arbitrary_option`

Never deletes, never writes, never exits. The reason strings are a closed
vocabulary so the suite and the gate can assert them.

### 6.3 `test_options_reset_test_store( array $requests )`

**Validate everything, then delete.** Never delete while validating.

```
1. $requests must be a non-empty array of strings            → else empty / invalid_type
2. resolve every entry with test_operator_store_resolve()
3. reject duplicates                                         → duplicate
4. if ANY entry failed → delete NOTHING, return ok=false + rejected[]
5. otherwise delete exactly the resolved keys                → return ok=true + deleted[]
```

Return shape (both branches):

```php
array(
	'ok'       => true|false,
	'deleted'  => array( 'conexao_event_import_log__test__p1234_ab…' ), // what was removed
	'rejected' => array( array( 'key' => '…', 'reason' => 'production_key' ) ),
)
```

The helper never reports a deletion it did not perform, and never performs a
deletion it did not report — which is what makes "exactly which keys were
affected" provable rather than asserted.

### 6.4 The ten required safeguards, mapped

| # | Safeguard | Enforcement |
|---|---|---|
| 1 | only the four authorised stores | §6.1 allowlist; unknown key → `unknown_store` (rule 7) |
| 2 | resolve each key through the owning store's accessor | rule 2 |
| 3 | reject the production `OPTION_KEY` | rule 3 |
| 4 | reject arbitrary option names | rule 7 |
| 5 | reject unknown store keys | rule 7 |
| 6 | reject another process's test key | rule 5 |
| 7 | reject malformed/mismatched suffixes | rules 4–5 (`^…__test__p<digits>_<hex16>$`) |
| 8 | reject any key not belonging to this process | rules 2 + 5 (suffix from `CONEXAO_TEST_OPTION_SUFFIX`) |
| 9 | no deletion if any key fails | step 4 of §6.3 |
| 10 | report exactly what was affected | step 5 + the return shape |

**Callers are not trusted.** The helper does not rely on a suite passing the
right strings: a caller that passes `Conexao_Import_Log::OPTION_KEY` gets
`production_key` and zero deletions.

### 6.5 `test_options_isolate()` and `test_options_restore()` must refuse the stores

The redirected stores are no longer isolated by snapshot: the seam makes that
unnecessary *and* dangerous (a snapshot of the production row would seed it
empty — the original defect). So:

- `test_options_isolate()` (`option-isolation.php:51`) validates its whole
  argument list with `test_operator_store_resolve()` **first**; if any entry is a
  redirected store, a production constant or a test-prefixed name, it refuses
  **before** `wp_load_alloptions()` (`:59`) and before the seed write at `:101`,
  and exits non-zero naming the store. It continues to serve the non-redirected
  options (sources, health, cooldown, checkpoints, continuations, settings)
  exactly as today.
- `test_options_restore()` (`:126`) refuses a snapshot whose key resolves to one
  of the four stores: it skips it, prints one line, and never calls
  `update_option()` (`:140`) or `delete_option()` (`:163`) for it — defence in
  depth against a stale external caller.

### 6.6 `test_options_write_test_store( $store, $value )`

The writer used by §5.4 and by the migration in §7.3:

```php
$key = test_operator_store_option_name( $store );   // resolves + validates, exits on failure
update_option( $key, $value, false );
return $key;
```

`test_operator_store_option_name()` wraps the resolver with `test_require()`, so
an unknown store or a production key is a **hard failure**, never a silent write.
Autoload stays `false`, matching every production write for these four options.

### 6.7 `test_require_operator_store_isolation()` (secondary safeguard)

Four `test_require()` calls (each already `exit( 1 )`s — `tests/lib/assertions.php:371-388`):

1. `CONEXAO_TESTS_ROOT` is defined (the harness is loaded);
2. `CONEXAO_TEST_OPTION_SUFFIX` is defined, non-empty and matching the pattern;
3. each of the four accessors resolves to a value starting with its
   `TEST_OPTION_PREFIX` and ending with the suffix;
4. none of the four resolved values equals its production constant.

Hint text: `role:isolated-test-store`, so a blocked suite prints
`insufficient data: role:isolated-test-store` and the runner reports it as a
prerequisite failure, never as a pass.

---

## 7. Removal of destructive test cleanup

*(Retained from the draft; §7.3 now enumerates the exact migration.)*

### 7.1 `test-event-cleanup-purge.php`

| Line | Today | Required |
|---|---|---|
| `:8` | requires the bootstrap | unchanged |
| `:508` | `$cleanup->run_cleanup()` over **every** past event | replaced by the per-fixture path the suite already uses (`cleanup_test_apply_one()`, asserted at `:304`, `:361`, `:382`, `:395`); assertions at `:510-518` rewritten to assert **each fixture id** individually (`get_post( $id ) === null`) |
| new, after the fixture block | — | **new scope assertion**: create one *sentinel* past event (own fixture, `_event_imported` + test marker) and assert it is **still present**, proving the suite no longer deletes beyond its own fixtures |
| `:536-537` | `delete_option( 'conexao_event_cleanup_status' )`, `delete_option( 'conexao_event_cleanup_history' )` | **deleted**; replaced by `test_options_reset_test_store( array( 'cleanup_status', 'cleanup_history' ) )`, whose `ok` flag and `deleted` list are asserted |
| top of file | no guard | `test_require_operator_store_isolation();` |
| `:539` | `test_finish( 'Event Cleanup Purge' )` | unchanged |

The end-to-end `run_cleanup()` proof moves to the future isolated-database stage
and is named as a **coverage reduction** in the report (§13.4) — not silently
dropped, and not replaced by a weaker global assertion.

### 7.2 `scripts/test-event-cleanup.php`

- Delete `:241-242` (`delete_option` of the status and history).
- Add a header paragraph: "this script writes local operator state; run it only
  on a site whose record you do not need."
- Update the `scripts/README.md` row: it no longer clears operator history
  (`scripts/README.md:282`).

The script stays a **local-write manual** tool, never run by the runner. After
this stage it no longer *deletes* operator history; it still writes it — an
explicit exclusion (§19.1), not a defect this stage claims to close.

### 7.3 The migration inventory (exact, 18 discovered + 1 experiment)

| File | Today | Change |
|---|---|---|
| `test-import-log.php` | isolates `OPTION_KEY` at `:41`; seeds at `:274`, `:312`; counts writes for `OPTION_KEY` (`:49`) | guard; drop the isolate/restore; write/read the test store; retarget the write counter; keep the live before/after assertions (§13.3) |
| `test-import-log-run-correlation.php` | isolates log+history; seeds `OPTION_KEY` at `:117` | guard; drop log/history from the isolate list; retarget `:117` |
| `test-import-history-dashboard.php` | isolates log+history; 4 direct history writes | guard; drop log/history from the isolate list; retarget `:507`, `:536`, `:863`, `:908`; add test-row render assertions |
| `test-import-history-logs.php` | isolates log+history; seeds `OPTION_KEY` at `:248`; live compare `:893-895` | guard; drop the two keys; retarget `:248`; keep **and** extend the compare |
| `test-import-settings-retirement.php` | isolates the log (not history) | guard; drop the log key; **keep** settings isolation (settings are not redirected) |
| `test-eventbrite-checkpoint.php` | isolates 7 options incl. both stores | guard; remove 2 keys, keep the other 5 |
| `test-error-handling.php`, `test-eventbrite-api-contract.php`, `test-eventbrite-rate-limit.php`, `test-eventbrite-source-expansion.php`, `test-eventbrite-token-retirement.php`, `test-import-continuation.php`, `test-request-budget.php`, `test-request-retry-policy.php`, `test-request-reuse.php`, `test-source-fetch-failure.php`, `test-source-pagination-completeness.php` | isolate log and/or history plus non-redirected options | guard; remove only the redirected keys from the isolate array; **keep** every other key exactly as it is |
| `test-event-cleanup-purge.php` | deletes two live options, runs the global engine | §7.1 |
| `g10-convergence-experiment.php` (not discovered) | isolates log+history | guard; drop the two keys; leave the rest |

Non-redirected options keep the snapshot discipline in this stage. No suite may
add a new isolation key for a redirected store, and none may keep one.

### 7.4 What is deliberately *not* changed here

- The four suites that replace `conexao_event_sources` (audit D7–D9) keep their
  manual snapshot/restore and move to `SERIAL` (§8); redirecting the registry is
  a future stage (§19.1).
- No suite's write-count, cap, prune or format assertion is removed.

---

## 8. Test runner classification

### 8.1 The eight demotions (each with its own reason)

Remove the `PARALLEL` entry for each, so the suite falls through to the `SERIAL`
default (`scripts/run-tests.sh:541`):

| Line | Suite | Why it cannot be `PARALLEL` |
|---|---|---|
| `:435` | `test-county-registry.php` | replaces `conexao_event_sources` in place (4 `update_option`) with no shutdown net |
| `:441` | `test-eventbrite-api-contract.php` | isolates log+history (a shared row today) |
| `:444` | `test-eventbrite-importer.php` | invokes `upsert_event()` by reflection (`:696`): content writes **and** failure-branch log appends |
| `:447` | `test-eventbrite-rate-limit.php` | isolates log+history |
| `:450` | `test-eventbrite-source-expansion.php` | isolates log+history |
| `:468` | `test-request-budget.php` | isolates log+history+registry |
| `:471` | `test-request-retry-policy.php` | isolates log+history |
| `:474` | `test-request-reuse.php` | isolates log+history |

### 8.2 The stale invariant comment

`run-tests.sh:285` claims `PARALLEL` suites have "no `update_option`". That has
not been true since the isolation helper existed. Replace it with the rule this
stage actually enforces:

> A suite may be `PARALLEL` only if it neither references a redirected operator
> store nor writes any importer option, and has been reviewed for content and
> cache writes. Everything else is `SERIAL`.

### 8.3 The standing rule (gate-enforced)

The gate (check 14) fails if any suite in the store-touching inventory of §7.3 is
listed as `PARALLEL`. Re-promotion therefore requires an edit to the gate as well
as to the runner — a visible, reviewable decision rather than a one-line change.

### 8.4 What classification does and does not buy

- It removes the *non-log* shared-option collisions (sources, health, cooldown,
  checkpoints, continuations, settings) that remain after the log seam.
- It does **not** claim the suites are parallel-safe. Re-promotion is a future
  stage with its own evidence (§19.1).
- Timeouts are unchanged: `CONEXAO_TEST_TIMEOUT` (default 300 s,
  `run-tests.sh:48`) applies per suite via `timeout` (`:583-598`). `timeout` sends
  `SIGTERM` first, which PHP does **not** turn into a shutdown-handler run. This
  is precisely why nothing in §14 depends on termination mode.

---

## 9. Static regression gate

*(Retained from the draft; its contract is widened to the five safeguards.)*

### 9.1 Identity

| | |
|---|---|
| Path | `tests/scripts/verify-operator-log-isolation.py` |
| Discovery | by convention (`tests/scripts/verify-*.py`, `run-tests.sh:140-148`); **no hand-maintained list** |
| Environment | `static` — plain `python3`, no WordPress, no socket, no database (`script_suite_environment()` default) |
| Repo root | `CONEXAO_REPO_ROOT` override, the pattern every gate already uses (`verify-eventbrite-token-retirement.py:50`) — also what makes the negative controls safe |
| Output | one `FAIL <check-id>: <detail>` line per failure, exit 1; `GATE PASS` with the number of checks performed, exit 0 |

### 9.2 The checks

| # | Check | Kind |
|---|---|---|
| 1 | The four production option names are the exact literals, declared as `const` in their owning class, and appear as an option key nowhere else in production code | static |
| 2 | Each class declares a `TEST_OPTION_PREFIX` starting with its production name + `__test__`, and not equal to it | static |
| 3 | Each accessor keeps the production branch (`return self::OPTION_KEY` / `STATUS_OPTION` / `HISTORY_OPTION` when the harness markers are absent) | static (presence + structure) |
| 4 | `tests/bootstrap.php` defines `CONEXAO_TEST_OPTION_SUFFIX` at a **line number lower than** its `require_once … wp-load.php` line | static, order-sensitive |
| 5 | `tests/bootstrap.php` fails closed: the pre-define refusal, the pid/token validation and the post-load prerequisite check all exist and route to `conexao_tests_bootstrap_fail` | static |
| 6 | Every store-touching entry point (a) requires `tests/bootstrap.php`, (b) calls `test_require_operator_store_isolation()`, (c) calls it **before** its first store reference; and the set contains the sentinels (`test-import-log.php`, `test-event-cleanup-purge.php`) so a renamed/moved tree fails loudly | static, order-sensitive |
| 7 | Child-process code paths require `tests/bootstrap.php`; no test hands the suffix to a child (no `CONEXAO_TEST_OPTION_SUFFIX` in a generated child string, no `-d`/env assignment) | static |
| 8 | `tests/lib/option-isolation.php` contains the four-store allowlist, the production-key rejection, the foreign-suffix rejection, and deletes only through the validated path | static (structure) + runtime rows 5–6 |
| 9 | No file deletes a production operator option: no `delete_option()` whose argument resolves to one of the four names or their class constants, outside the owning classes | static |
| 10 | `test-event-cleanup-purge.php` contains no `run_cleanup()` call | static |
| 11 | No production file defines `CONEXAO_TEST_OPTION_SUFFIX` or `CONEXAO_TESTS_ROOT` (only `tests/bootstrap.php` may) | static |
| 12 | No broad wildcard cleanup: no `$wpdb`/SQL statement deletes options by `LIKE` pattern in tests, scripts or production | static |
| 13 | Test cleanup is restricted to the current process's keys: the cleaner derives its key set from the four accessors, never from a query or a name literal | static (structure) + runtime rows 3, 11 |
| 14 | No store-touching suite of §7.3 is `PARALLEL` in `run-tests.sh` | static |

### 9.3 Missing prerequisites fail the gate

The gate asserts existence with **named** errors rather than skipping:

- each referenced file exists (bootstrap, the three classes, the helper, the 19
  store-touching test files, the purge suite);
- each referenced class, method and constant exists in the file claimed;
- the discovered store-touching set is non-empty **and** contains the sentinels;
- `suite_classification()` and its importer block exist before check 14 runs.

A renamed file or a deleted method produces `FAIL … missing: <path>`, never a
vacuous pass.

### 9.4 Negative controls (scratch copies only)

Violations are injected into a **temporary copy**, never the working tree:

```bash
tmp="$(mktemp -d)"
cp -a tests scripts wp-content "$tmp"/
# inject ONE violation into $tmp, then:
CONEXAO_REPO_ROOT="$tmp" python3 tests/scripts/verify-operator-log-isolation.py; echo "exit=$?"
rm -rf "$tmp"
```

| # | Injected violation | Required result |
|---|---|---|
| N1 | `delete_option( 'conexao_event_cleanup_history' )` added to a suite in the copy | exit 1, check 9 |
| N2 | `test_require_operator_store_isolation();` removed from one suite | exit 1, check 6 |
| N3 | a child code string in `test-bulk-actions.php` without the bootstrap require | exit 1, check 7 |
| N4 | the reset helper's production-key rejection branch deleted | exit 1, check 8 |
| N5 | `define( 'CONEXAO_TEST_OPTION_SUFFIX', … )` added to a production file | exit 1, check 11 |
| N6 | the `define` moved **below** the `wp-load.php` require in the bootstrap copy | exit 1, check 4 |
| N7 | a store-touching suite re-added to the `PARALLEL` block | exit 1, check 14 |
| N8 | a referenced file deleted (e.g. `test-import-log.php`) | exit 1, missing-prerequisite error |

Each result goes into the implementation report with the command, the copy path
and the exit code.

### 9.5 Limitations of static verification (assigned to runtime)

| Property | Why static cannot prove it | Assigned to |
|---|---|---|
| A test process **actually** writes the test row and not the production row | requires executing WordPress | rows 2, 3 (runtime) |
| `test_options_reset_test_store()` deletes **only** the resolved keys | requires a database | rows 5, 6 (runtime) |
| `SIGKILL` leaves only a test-prefixed orphan | requires process control | row 11 (runtime, safety-only) |
| The production path still writes the production option | requires a non-test process | §12 (runtime) |
| WordPress core's own load order (`init`/`wp_loaded` inside `wp-load.php`) | core is not in this checkout | documented contract (§2.3); the *consequence* is proven by row 3's live-hash check across a run that re-arms a continuation |

**The gate is never described as proving more than these 14 static properties.**

---

## 10. The acceptance suite

### 10.1 File and structure

`wp-content/plugins/conexao-event-importer/tests/test-operator-log-isolation.php`
(discovered by convention — `test-*.php` under a plugin's `tests/`), requiring the
shared bootstrap (`:1-40` pattern of every sibling suite) and starting with
`test_require_operator_store_isolation();`.

| Section | Content | Rows (§11) |
|---|---|---|
| **A — bootstrap contract** | A1: markers defined, suffix matches `^p<digits>_<hex16>$`, embeds `getmypid()`, is not shared with any other process observed in this run. A2: the four accessors each start with their prefix, end with the suffix, differ from the constants. A3: three child variants that must exit non-zero before writing — (i) suffix already defined, (ii) suffix defined empty, (iii) suffix defined malformed | 3, 4, 10 |
| **B — real logging writes to test storage** | `add()`, `begin_run()`/`end_run()`, `Conexao_Import_History::record()`, `save_status()`, `save_history()` each produce entries in the test row and none in the live row (raw `md5` compare) | 2, 6 |
| **C — reset helper, positive** | reset all four → `ok=true`, `deleted` lists exactly four keys, all four test rows gone, live hashes unchanged | 5 |
| **D — reset helper, negative (five cases)** | production key literal; unrelated option (`blogname`); another process's key (`…__test__p999999_deadbeef…`); unknown store (`import_logs`); mixed valid+invalid → every case `ok=false` with the expected `reason`, **and** every pre-existing test row still present with identical bytes | 5, 12 |
| **E — `test_options_isolate()` refusal** | passing any of the four (constant or literal) exits non-zero **before** seeding; the live row is untouched | 3, 5 |
| **F — fatal termination** | child writes to its test row, then triggers a fatal; parent asserts live unchanged **and** the child's test row was removed where PHP ran shutdown handlers | 4, 11 |
| **G — concurrency** | three children in parallel, each writing N entries and reporting its key; parent asserts three **distinct** keys, all test-prefixed, all cleaned; live unchanged | 9 |
| **H — parent/child identity** | a child reports its resolved key; parent asserts it differs from the parent's key and from every other observed key | 9, 10 |
| **I — orphan classification** | list every option name matching each of the four test prefixes; assert each starts with a prefix, none equals a production name; report the count (may be non-zero after a forced kill) | 11 |
| **J — production-path smoke test** | the controlled non-test-process write/restore defined in §12 | 8 |

### 10.2 Child-harness contract (used by A3, F, G, H, J)

- child code is written to `tempnam( sys_get_temp_dir(), 'conexao-stage05-' )`,
  run with `exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', … )`
  (the existing pattern at `test-bulk-actions.php:290`), then unlinked;
- every child prints machine-readable markers (`KEY=…`, `WROTE=…`, `CLEANED=…`,
  `FATAL=…`) and its exit code is asserted;
- a child that must **not** be isolated is started **without** the bootstrap and
  asserts `! defined( 'CONEXAO_TESTS_ROOT' )` before touching anything;
- `test_require()` guards the prerequisites: `exec()` available (mirrors
  `test-bulk-actions.php:670-674`), `PHP_BINARY` non-empty, and a quiet local site
  for section J.

### 10.3 What the suite must never assert

- That `SIGKILL` cleans up. Forced termination is proven only as a **safety**
  property: the live hashes are unchanged and any surviving row is test-prefixed.
- That the gate's static properties hold at runtime beyond the rows it maps to.
- That assertion totals equal a baseline (§13).

---

## 11. Revised acceptance criteria and negative controls

Twelve criteria. Every row states **how** it is proven, **which** artefact proves
it (suite section or gate check), the **expected** result, the **negative
control** that must fail, whether the proof is **static or runtime**, and any
**prerequisite** required to run it safely. No row depends on importing an event
or on contacting a provider.

| # | Criterion | How it is proven / responsible artefact | Expected result | Negative control (must fail) | Proof | Prerequisite |
|---|---|---|---|---|---|---|
| 1 | Pre-existing operator logs survive an ordinary run **unchanged** | raw `md5` of the four options captured before and after each run mode (§15.2 steps 4, 7, 9, 11); suite §B/C cross-checks inside the process | hashes identical in all three run modes | point `option_key()` at `OPTION_KEY` in a scratch copy → the after-hash changes → criterion fails | runtime | local stack; export captured before any run |
| 2 | Test-created entries stay isolated | suite §B: `add()`/`record()`/`save_*` write the test row; live row raw bytes unchanged | test row grows, live row byte-identical | remove the redirect in a scratch copy → the live row changes | runtime | — |
| 3 | Reset and teardown cannot delete or overwrite production stores | suite §C/§E + I; the cleaner's derived key set is asserted to contain no production name | live rows untouched; cleaner key set ⊆ four test keys | pass `OPTION_KEY` to `test_options_reset_test_store()` → `ok=false`, nothing deleted | runtime (+ gate 9, 13) | — |
| 4 | A test failure cannot leave production logs cleared or overwritten | suite §F: child writes then fatals; live hash compared before/after | live hash unchanged; the child's test row removed | disable the redirect in the child → it writes the live row → assertion fails | runtime | PHP shutdown handlers run on fatal (PHP ≥ 7) |
| 5 | Reset affects only explicitly permitted current-process test keys | suite §C (positive) and §D (five negatives) | `deleted` lists exactly the permitted keys; every negative case leaves every row intact | mixed valid+invalid request → zero deletions | runtime (+ gate 8) | — |
| 6 | Real importer logging behaviour remains testable | `test-import-log.php`'s format/cap/prune/write-count assertions run against the test row; suite §B | all existing behavioural assertions still pass, targeting the test row | revert `option_key()` → §B fails and the write counter stays at 0 | runtime | — |
| 7 | Existing assertions and intended coverage are preserved | §13 inventory: pre-change static inventory vs post-change measured totals; every change listed with intent | no unintended removal; every intentional change named in the report | drop an assertion without recording it → the report/inventory mismatch is a review defect (not machine-detected — stated as a limitation) | static + report | inventory captured before editing |
| 8 | Normal non-test logging still resolves the production option, **proved without an import** | §12 controlled smoke test (suite §J) | production key written and restored; `RAW1 ≠ RAW0`; `FINAL == RAW0`; no `__test__` row used | start the smoke child **with** the harness → it resolves a test key and `KEY == OPTION_KEY` fails | runtime | quiet local site (no import, no continuation lock) |
| 9 | Parallel workers and children never share a test-store key | suite §G/§H: three parallel children report distinct keys; the pool runs with `CONEXAO_TEST_WORKERS=3` (§15.2 step 10) | 3 distinct keys; live hashes unchanged | pin the suffix to `p<pid>` only and run two workers in the same PID namespace → duplicate key detected | runtime | `exec()` available |
| 10 | The harness cannot silently fall back to production storage | suite §A3 (three child variants) + gate 5 | each child exits non-zero before any write; live hash unchanged | remove the pre-define refusal from a scratch copy → the child exits 0 → criterion fails | runtime (+ gate 5) | — |
| 11 | Shutdown cleanup and orphaned test stores are safe | suite §F (fatal), §I (orphan classification), §G (children), plus a forced-kill child proven **safety-only** | no production key affected in any termination mode; every orphan is test-prefixed | replace the derived key set with a wildcard delete in a scratch copy → gate 12 fails | runtime (+ gate 12) | — |
| 12 | The static gate rejects the defined regression scenarios | §9.4 N1–N8, each on a scratch copy with `CONEXAO_REPO_ROOT` | exit 1 for every injected violation, exit 0 unmodified | run the gate against a copy with **no** violations → must exit 0 (else the gate is over-broad) | static | python3 only |

### 11.1 Mapping to the ten criteria of the draft

| Draft row | Fate |
|---|---|
| 1–6 | retained (rows 1–6 here), now with named artefacts and prerequisites |
| 7 | **replaced** — assertion "counts identical" is withdrawn; superseded by §13 (revision 4) |
| 8 | **replaced** — the manual import is withdrawn and replaced by §12 (revision 3) |
| 9 | retained and strengthened (rows 9, 10, 11) |
| 10 | retained (row 10) |

No criterion was removed without a replacement; no assertion was weakened to fit
the new storage target.

---

## 12. Controlled non-test logging smoke test

*(Required revision 3 — replaces the manual importer run entirely.)*

### 12.1 Why the manual import is withdrawn

A local importer run is unnecessary for a storage-isolation task and causes
unrelated, hard-to-bound side effects: provider requests, real event imports and
updates, source-health changes, checkpoint/continuation changes, and log/history
changes far beyond the controlled test. Stage 0.5 must prove a **storage
property**, not that an import works. **No import is required, performed or
referenced by any acceptance criterion.**

### 12.2 The design: compare-and-swap write, byte-exact restore

Purpose: prove that ordinary (non-test) execution still resolves the **production**
option and that the real logging class writes through its ordinary production
path — in milliseconds, with no importer involved.

```
PARENT (isolated test process, suite §J)
  R0 = raw option_value row for 'conexao_event_import_log'   ($wpdb, read-only)
  H0 = md5(R0);  EXISTED0 = (R0 !== null)

  CHILD (plain `php`, NO tests/bootstrap.php, NO harness constants)
    require '<wp-root>/wp-load.php'
    assert !defined('CONEXAO_TESTS_ROOT') && !defined('CONEXAO_TEST_OPTION_SUFFIX')
    print 'KEY=' . Conexao_Import_Log::option_key()            // must equal OPTION_KEY
    r0 = raw row; print 'RAW0=' . md5(r0)
    Conexao_Import_Log::add( '__stage05_smoke__', 'info', '…' ) // the REAL production path
    r1 = raw row; print 'RAW1=' . md5(r1)                      // must differ from RAW0
    now = raw row
    if (now === r1) { write r0 back byte-exact; wp_cache_delete(OPTION_KEY,'options'); print 'RESTORED=1' }
    else            { print 'RESTORED=0' }                     // a concurrent writer won — do NOT clobber
    print 'FINAL=' . md5(raw row)
    print 'TESTROW=' . (any option_name with prefix ? 1 : 0)

  PARENT asserts
    child exit code 0
    KEY == Conexao_Import_Log::OPTION_KEY                      (production resolution)
    RAW1 != RAW0                                               (the class really wrote production)
    TESTROW == 0                                               (no test row was used)
    RESTORED == 1 and FINAL == H0                              (state restored byte-exactly)
    when EXISTED0 == false: the row is absent again after restore
```

### 12.3 Safety prerequisites and failure handling

| Prerequisite | Checked by | Behaviour when unmet |
|---|---|---|
| No import is running | no `conexao_continuation_lock_*` option present; no continuation in a running state | `test_require()` with hint `quiet-local-site:no-active-import` → the suite fails as **insufficient data**, never passes |
| No concurrent writer during the window | the CAS compare (`now === r1`) inside the child | `RESTORED=0` → the parent fails the criterion and the report records that the smoke did not restore; **no data is lost**, because the child only ever prepended one entry and declined to overwrite |
| Raw restore is byte-exact | `md5(FINAL) == H0` | fail, with both hashes printed |
| No cache residue | `wp_cache_delete( OPTION_KEY, 'options' )` after the raw write | the final read re-reads the row (asserted by the final hash) |

The only lasting effect of a successful run is: one log entry added and then
removed, with the option row byte-identical to its previous value — the smallest
observable proof that the production path is intact.

### 12.4 What is recorded

`docs/evidence/2026-11-14-stage-0-5/`:

| File | Content |
|---|---|
| `05-production-log-before.json` / `-after.json` | the four operator options, exported read-only (`wp option get --format=json`, or a read-only PHP one-liner through `scripts/lib/bootstrap.php` when `wp` is unavailable) |
| `05-production-log-hashes.txt` | `md5` of each option before and after every run mode |
| `06-smoke-test.txt` | the child's full stdout (`KEY`, `RAW0`, `RAW1`, `RESTORED`, `FINAL`, `TESTROW`) and the parent's assertions |
| `07-isolation-child-matrix.txt` | the A3/F/G/H child variants: command, expected exit, observed exit |

### 12.5 If the smoke test cannot be made reliable

The fallback is **explicitly not a live import**. It is, in order:

1. the child's **read-only** half (`KEY == OPTION_KEY`, `TESTROW == 0`, no harness
   constants) — production resolution without any write;
2. the static gate's accessor check (check 3) — the production branch still exists;
3. the write half recorded as `BLOCKED` with the observed CAS failure, re-attempted
   once the site is quiet.

A blocked write half is reported as **PASS WITH LIMITATION** at best, never as a
pass.

---

## 13. Assertion inventory and baseline limitations

*(Required revision 4 — corrects the "identical counts" requirement.)*

### 13.1 What exists today, measured statically (no suite executed)

The audit did **not** run the unsafe suite, so no runtime baseline exists — and
inventing one is forbidden. A reproducible static inventory was measured during
this revision with:

```bash
grep -cE '^[[:space:]]*(assert_true|assert_equals|assert_contains|assert_not_contains|assert_set|assert_not_set|assert_fail|test_require)\(' <file>
```

| Suite | Call sites | Suite | Call sites |
|---|---|---|---|
| `test-import-continuation.php` | 224 | `test-eventbrite-source-expansion.php` | 65 |
| `test-import-history-dashboard.php` | 120 | `test-error-handling.php` | 56 |
| `test-eventbrite-api-contract.php` | 106 | `test-import-log.php` | 55 |
| `test-import-history-logs.php` | 77 | `test-request-reuse.php` | 53 |
| `test-eventbrite-checkpoint.php` | 77 | `test-eventbrite-token-retirement.php` | 40 |
| `test-eventbrite-rate-limit.php` | 73 | `test-event-cleanup-purge.php` | 34 |
| `test-request-budget.php` | 33 | `test-request-retry-policy.php` | 33 |
| `test-source-pagination-completeness.php` | 24 | `test-import-settings-retirement.php` | 24 |
| `test-import-log-run-correlation.php` | 21 | `test-source-fetch-failure.php` | 17 |
| **Subtotal — the 18 store-touching suites** | **1,132** | All 37 discovered importer suites | **2,245** |

**These are call sites, not runtime assertions.** One call site inside a loop
executes many times; `test_require()` records one assertion per call. They are a
**lower bound and a completeness check**, never a substitute for a runtime count,
and never a number to "match".

### 13.2 Procedure before editing any test (implementation stage)

1. Inventory the files of §7.3 plus the new suite; record **every**
   `test_section` heading and the assertion call sites per file (the grep above).
2. Mark each assertion: (a) stays identical, (b) moves to the test store,
   (c) replaced, (d) deliberately deferred.
3. Store it as
   `docs/evidence/2026-11-14-stage-0-5/02-assertion-inventory-before.md`
   **before** the first edit, with the command and its raw output.
4. Only then start editing.

### 13.3 Every intended assertion change, named

| File | Assertion | Change | Intent preserved? |
|---|---|---|---|
| `test-import-log.php` | `updated_option` counter for `OPTION_KEY` (`:49-60`) | target → `option_key()`; one **new** assertion that the live key received 0 writes | yes — "one write per flush" is still measured exactly, now where the write lands |
| `test-import-log.php` | live-count preservation (`:367-390`) | reads the live option explicitly; adds "the test row was cleared by the suite's own `clear()` calls" | **strengthened** — the live read is no longer the row the suite writes |
| `test-import-history-logs.php` | render-does-not-mutate (`:893-895`) | keep the live compare; add the test-row compare | strengthened |
| `test-import-history-dashboard.php` | render-does-not-mutate (`:848-850`) | same | strengthened |
| `test-event-cleanup-purge.php` | `run_cleanup()` finds/deletes ≥ fixtures (`:510-518`) | replaced by per-fixture id assertions + the sentinel-not-deleted assertion | **reduced in scope, intentionally** — §13.4 |
| `test-event-cleanup-purge.php` | no assertion covered the two `delete_option()` calls | new assertion on `test_options_reset_test_store()`'s `ok`/`deleted` | added |
| all suites of §7.3 | nothing removed for storage reasons | — | — |

### 13.4 Deliberate coverage deferral (named, not hidden)

**Deferred:** the end-to-end `Conexao_Event_Cleanup::run_cleanup()` proof over the
real event population. **Why:** it deletes every past event in the database (audit
A3) and cannot be made safe without a dedicated test database. **Replacement now:**
per-fixture deletion proofs plus the sentinel-not-deleted proof. **Owner of the
deferred proof:** the future isolated-database stage. The report must state this
as a coverage reduction with these three facts.

### 13.5 The numerical-comparison rule (binding on the report)

| Situation | Permitted claim |
|---|---|
| No safe runtime baseline — **the case here** | **"Direct count comparison is unavailable."** Report the static inventory (§13.1), the real post-change totals and the list of intentional changes. **Never** claim counts are unchanged |
| A safe baseline was captured (future stages) | counts may be compared, with the command and both numbers recorded |

A missing baseline is **never** presented as a passing comparison. The report's
scorecard separates: baseline measured / baseline inventoried statically /
post-change measured / intentionally changed / deferred to the isolated-DB stage.

---

## 14. Shutdown, fatal and orphan-store handling

*(Required revision 5.)*

### 14.1 Termination matrix

| Mode | Do shutdown handlers run? | What must hold | How it is proven |
|---|---|---|---|
| Normal exit | yes | only this process's four test keys are deleted | suite §C, §G |
| PHP fatal error | yes (PHP ≥ 7 runs shutdown functions on fatal) | the live row is untouched; the test row is deleted where handlers ran | suite §F |
| Child process success | yes | the child deletes only its own keys | suite §G, §H |
| Child process failure | yes (unless it dies by signal) | production keys untouched | suite §F, §A3 |
| Child with missing/invalid suffix | n/a — it never starts | non-zero **before** any store write | suite §A3 |
| Timeout (`timeout`, `SIGTERM`) | **not guaranteed** | production keys untouched; any residue is a test key | §14.3 reasoning + row 1 hashes; **not** claimed as a cleanup proof |
| Forced termination (`SIGKILL`) | **no** | production keys untouched; any residue is a test-prefixed orphan | suite §I (safety-only), §11 row 11 |
| Failure to establish isolation | n/a | non-zero exit | suite §A3 |

**The design does not claim that a shutdown function handles `SIGKILL`, and no
criterion makes forced termination a prerequisite for the production-log
preservation property.**

### 14.2 Why the suffix is `p<pid>_<16 hex>`, not `p<pid>`

| Failure mode | `p<pid>` alone | `p<pid>_<token>` |
|---|---|---|
| Two workers in the same PID namespace (two containers sharing one DB, both with pid 1234) | **collision** | distinct |
| Two sequential runs after a `SIGKILL` reuse the same PID | the new run inherits a stale row's entries → "empty store at start" assertions become false | distinct |
| A parent and its child | distinct (different pids) | distinct |
| A future code path that copies the constant into a child | would silently share | the bootstrap refuses a pre-defined suffix → loud failure |
| A test row resolving to production | impossible either way (prefix differs) | impossible |

A random token is therefore not decoration: it removes a real flake (stale rows)
and a real collision (shared DB), at the cost of one `random_bytes( 8 )`.

### 14.3 The cleaner's contract

- registered in the bootstrap as `add_action( 'shutdown', 'conexao_tests_clean_option_stores', 999 )`;
- runs **after** the store's own `shutdown` flush at priority 5
  (`class-import-log.php:170-173`), so buffered entries are persisted to the test
  row first and removed with it — never "flushed" into a deleted row;
- the key set is derived **only** from the four accessors, never from a query, a
  `LIKE` pattern or a name literal;
- each key must be test-prefixed **and** carry the current suffix, else nothing is
  deleted and one `conexao-test-cleanup:` line goes to `STDERR`;
- **no fallback path may delete a production option** — no code path in the
  cleaner can name one;
- a process deletes only its own keys, so it cannot remove the parent's or a
  sibling worker's store, and it does not need the parent's store to exist.

### 14.4 Orphaned test rows (documented, scoped, manual)

- An orphan is **by construction** a test key: `…__test__p<digits>_<hex16>`.
- The acceptance suite (§10 section I) **classifies** every row matching a test
  prefix and asserts none equals a production name; it reports the count.
- Removal is a **manual, explicit** procedure documented in `docs/testing.md`:
  `wp option delete <exact name>`, one row at a time, taken from that report —
  never a wildcard, never a script that deletes by prefix pattern.
- Failure to clean an orphan is a storage-hygiene issue. It is **not** a reason to
  weaken isolation, to fall back to a production key, or to broaden a delete.

### 14.5 What a surviving orphan can and cannot do

| Can | Cannot |
|---|---|
| occupy one non-autoloaded row until swept | be read by the dashboard (`get_all()` resolves the current process's key, which no longer exists) |
| appear in the §10-I report | be mistaken for an operator option, be resolved by any accessor, or enter the cleaner's key set |

---

## 15. Validation sequence

### 15.1 Preconditions — all of them, before the first suite runs

| # | Requirement | How it is confirmed | If unmet |
|---|---|---|---|
| P1 | bootstrap fail-closed check implemented (§4.2 steps 4, 7) | read the diff + `php -l` + gate checks 4/5 | **stop**: do not run a suite |
| P2 | static gate present and passing on the unmodified tree | `./scripts/run-tests.sh --scripts` | stop |
| P3 | every store-touching entry point and every child path guarded | gate checks 6/7 green | stop |
| P4 | production operator state captured (four options + `conexao_event_sources`) with hashes | §15.2 step 4 | stop |
| P5 | environment identity confirmed local (`docker compose ps`; `home`/`siteurl` host is not `conexaobr.ie`/`www.conexaobr.ie`; `wp_get_environment_type()` ≠ production) | step 5 | stop |
| P6 | no import running (no continuation lock) | no `conexao_continuation_lock_*` option present | wait / stop |

**The first full test run must never be the experiment that discovers whether
isolation works.** P1–P6 exist so that a failure is a test failure, not an incident.

### 15.2 The ordered sequence

| # | Command | Pass condition |
|---|---|---|
| 1 | `php -l` on every changed PHP file | 0 syntax errors |
| 2 | `./scripts/run-tests.sh --scripts` | every script-contract suite passes **including** the new gate |
| 3 | hash the changed files for the report | recorded |
| 4 | capture the five options read-only → `docs/evidence/2026-11-14-stage-0-5/05-*-before.json` | files exist; hashes recorded |
| 5 | environment identity (`docker compose ps`, `wp option get home`, a read-only environment-type one-liner) | local only |
| 6 | `./scripts/run-tests.sh --only conexao-event-importer` (isolated suites) | 0 failed; new suite passes |
| 7 | re-capture + compare | identical hashes |
| 8 | `CONEXAO_TEST_WORKERS=1 ./scripts/run-tests.sh` | `ALL TESTS PASSED`, exit 0 |
| 9 | re-capture + compare | identical hashes |
| 10 | `CONEXAO_TEST_WORKERS=3 ./scripts/run-tests.sh --php` | exit 0 |
| 11 | re-capture + compare | identical hashes |
| 12 | `./scripts/run-tests.sh --only conexao-event-importer` **twice** | identical assertion totals in both runs (repeatability) |
| 13 | count `wp_options` names matching each test prefix | `0` after a clean run; residue, if any, listed from §10-I |
| 14 | negative controls N1–N8 (§9.4) on scratch copies | exit 1 per violation; exit 0 unmodified |
| 15 | `./scripts/lint.sh` | PHPCS/PHPStan clean for changed files, or the pre-existing baseline stated explicitly |
| 16 | capture the smoke-test stdout (§12.4) | every §12.2 assertion true |

Steps 6–11 are never merged: serial and parallel modes are proven independently,
each with its own before/after hash pair. There is no manual import step anywhere
in this sequence.

### 15.3 What a green run does *not* prove

It proves the four operator options did not change **during that run**. It does
not prove production behaviour (that is §12), release readiness, or that the
suites are parallel-safe (a future stage).

### 15.4 Blocked is not a pass

A suite that prints `conexao-test-bootstrap:` or `insufficient data:` is reported
as `BLOCKED`/`FAIL`, never as a pass (`run-tests.sh:805-812` already handles the
bootstrap). A verifier that could not run is named in the report with the reason.

---

## 16. Files and change boundaries

### 16.1 Production files (three)

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-import-log.php` | `TEST_OPTION_PREFIX`, `option_key()`, `test_option_suffix()`; 4 call sites (`:226`, `:235`, `:357`, `:565`) | log storage target + fail-closed resolution |
| `wp-content/plugins/conexao-event-importer/includes/class-import-history.php` | same shape; 3 call sites (`:100`, `:111`, `:169`) | history storage target |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php` | `status_option()` / `history_option()` + prefixes; 4 call sites (`:536`, `:545`, `:572`, `:581`) | cleanup status/history target |

### 16.2 Harness

| Path | Change |
|---|---|
| `tests/bootstrap.php` | suffix establishment **before** `wp-load.php`; pre-define refusal; post-load prerequisite verification; production/environment refusal; store-cleaner registration + implementation |
| `tests/lib/option-isolation.php` | store allowlist; `test_operator_store_resolve()`; `test_operator_store_option_name()`; `test_options_write_test_store()`; `test_options_reset_test_store()`; `test_require_operator_store_isolation()`; refusal rules inside `test_options_isolate()` / `test_options_restore()` |

### 16.3 Suites (19 existing + 1 new)

- the 18 discovered suites of §7.3 plus `g10-convergence-experiment.php` — guard
  call, redirected keys out of the isolate array, direct writes retargeted;
- `test-event-cleanup-purge.php` — §7.1 (also counted in the 18);
- **new** `test-operator-log-isolation.php` — §10.

### 16.4 Scripts

| Path | Change |
|---|---|
| `scripts/test-event-cleanup.php` | remove `:241-242`; header safety paragraph |
| `scripts/run-tests.sh` | 8 `PARALLEL` entries removed (§8.1); invariant comment at `:285` corrected |

### 16.5 New gate

`tests/scripts/verify-operator-log-isolation.py` — §9. No registration edit: the
script-contract layer discovers `verify-*.py` by convention.

### 16.6 Documentation, evidence and report

| Path | Change |
|---|---|
| `docs/plugins/conexao-event-importer.md` | storage-seam paragraph (`option_key()`), corrected test-safety section, removal of any implication that snapshot/restore is isolation |
| `docs/testing.md` | "Test isolation": the helper, the guard, the fail-closed rule, the orphan-sweep procedure |
| `scripts/README.md` | `scripts/test-event-cleanup.php` row: no longer clears operator history |
| `docs/reports/2026-11-14-stage-0-5-test-safety.md` | **new** implementation report (§13.5 scorecard, §9.4 negative controls, §15 numbers) |
| `docs/evidence/2026-11-14-stage-0-5/` | **new**: preflight, `02-assertion-inventory-before.md`, option exports + hashes, `06-smoke-test.txt`, `07-isolation-child-matrix.txt`, `08-gate-negative-controls.txt` |
| `docs/reports/README.md`, `docs/README.md` | index entries for the new report |

### 16.7 Files explicitly expected NOT to change

| Path | Why |
|---|---|
| `includes/class-import-log-admin.php` | the operator clear/download handlers stay byte-identical |
| `includes/class-event-sources.php` | the dashboard already reads through `Conexao_Import_Log::get_all()` |
| any retention constant or `prune_older_than_90_days()` | P13 |
| `conexao_event_sources` production state | the Stage 0 registry must not move |
| `plugins.json`, `compose.yaml`, `.github/workflows/ci.yml` | no plugin-set, mount or CI-contract change |
| `wp-content/themes/**` | out of scope |
| `scripts/lib/bootstrap.php` | the script layer is not a test entry point; changing it widens scope |
| Stage 0 plan / report / evidence | immutable historical record |
| `docs/templates/*` | never edited for an individual task |

### 16.8 Revised effort estimate

| Area | Lines (changed/added) |
|---|---|
| 3 production files | ~120 |
| `tests/bootstrap.php` | ~90 |
| `tests/lib/option-isolation.php` | ~230 |
| 19 existing suites | ~300 (guards + retargets + assertion rewrites) |
| new acceptance suite | ~520 (10 sections, 6 child harnesses) |
| new static gate | ~450 (14 checks + 8 negative-control fixtures) |
| docs + report + evidence | ~220 |

**~1,900 lines**, roughly 2.5× the draft's estimate. The increase is the price of
fail-closed bootstrap ordering, the strict helper, the child-process matrix, the
smoke test and the gate — none of which can be dropped without dropping one of the
five required safeguards. The *surface* remains three production files.

### 16.9 Impact

| Dimension | Result |
|---|---|
| **Content / data** | zero real records created, updated or deleted. The only rows written are `…__test__p<pid>_<token>` options, deleted at shutdown (or swept manually after a kill). The one place that *currently* deletes content (`run_cleanup()` in a suite) is removed — a reduction in writes. PT/EN content untouched; the §5.2 content-change contract is not triggered |
| **Routes / HTTP** | none added or changed; no redirect, no sitemap change; no acceptance matrix row (the property is not request-observable without a write) |
| **Polylang / English** | unaffected: no post type, taxonomy, language assignment, hreflang, canonical or language-scoped cache-key change; completeness gate unchanged |
| **Security** | no capability, nonce or escaping change; the suffix is validated by a fixed regex, so no request, env var or file can influence an option name; no new SQL (only `get_option`/`update_option`/`delete_option`, plus raw `$wpdb` **reads** in the harness); no secret involved. New risk reviewed: the test constants must never exist in production (gate check 11) |
| **Performance** | one `defined()` check per store resolution; the same number of queries; the option stays non-autoloaded; no new cache key |
| **Production** | nothing deployed, uploaded or activated; the change is inert because neither marker is ever defined there. Verification of a production result is **not applicable** — and §12 proves the production path still works, without touching production |

---

## 17. Risk assessment

| Risk | Likelihood | Mitigation |
|---|---|---|
| The accessor's throw fires in a legitimate non-production command | **very low** | it needs a harness marker; no production file may define one (gate 11), and the acceptance suite proves the production branch |
| A suite writes the **live** row through a direct `update_option()` that the migration missed | **medium if not enumerated** | §5.4 lists all 8 sites found by grep; gate check 9 + the live-hash rows catch a missed one; the new suite asserts the live hash inside the process |
| The suffix leaks into production and operator logs land in `…__test__…` | very low | constants only in `tests/bootstrap.php`; the plugin `tests/` tree is outside the release allowlist; gate 11 |
| Orphan test rows accumulate in `wp_options` | low | per-process key + shutdown delete; §10-I reports the count; §14.4 manual sweep; a non-zero count after a **clean** run fails step 13 |
| Redirecting the store weakens a real assertion | **medium** | §13.3 names every change; the `updated_option` counter and the live-render comparisons are strengthened; no assertion is deleted for storage reasons |
| `run_cleanup()` removal reduces end-to-end coverage | **certain** | named as a coverage reduction (§13.4) and re-allocated to the isolated-DB stage; per-fixture + sentinel proofs added |
| The smoke test's write window disturbs the log | low | CAS restore, byte-exact hash gate, quiet-site prerequisite, and a refusal (not a clobber) if anyone else wrote |
| The gate passes while a referenced file silently disappears | low | §9.3 asserts existence with named errors; N8 proves it |
| A reviewer reads this as authorisation to redirect `conexao_event_sources` | medium | §16.7 and §19 exclude it by name; §7.4 repeats it |
| Scope creep ("while we're here" retention/P13 work) | **medium-high** | §19 excludes it; §16 is a closed file list; a new file needs written justification |
| Someone re-promotes a suite to `PARALLEL` without evidence | medium | gate 14 + §8.3 |
| A blocked verifier is reported as a pass | low | §15.4; the report must name every verifier that could not run |

---

## 18. Rollback

1. `git revert` the Stage 0.5 commit. The production diff is accessors only, so a
   revert restores the previous behaviour exactly.
2. Sweep any orphaned `…__test__p*` rows with the **manual, per-key** procedure of
   §14.4 (never a wildcard).
3. Restore the operator options from the step-4 export **only if** a hash
   comparison shows they changed — the failure this stage exists to prevent.
4. Rollback does **not** recover content deleted by a pre-Stage-0.5 run of
   `test-event-cleanup-purge.php`; that loss is not recoverable from git and must
   be assessed against a database backup.
5. Rollback does not change the Stage 0 source registry; nothing in this stage
   writes it.

---

## 19. Explicit exclusions and immutable records

### 19.1 Not doing, and why

| Not doing | Why |
|---|---|
| **P13 — production retention** (2,000-entry cap, 90-day prune, their trigger) | out of scope by instruction. This plan touches no retention constant and adds no production prune call. The audit's note that `prune_older_than_90_days()` has **no production caller** (`class-import-log.php:546` vs `docs/plugins/conexao-event-importer.md:113-115`) is recorded for P13, **not fixed here** |
| P1 – P12 | separate roadmap items; none is a prerequisite |
| Redirecting `conexao_event_sources` (and health, cooldown, checkpoints, continuations, settings) to test storage | future stage; this stage keeps snapshot/restore + `SERIAL` |
| Provisioning a second WordPress database / install | larger change (compose, CI, dev docs, acceptance layer) and it would not by itself fix the shared-option habit. Evaluated and rejected for Stage 0.5 (option 1 in §3.2); remains the right long-term answer |
| Re-promoting any importer suite to `PARALLEL` | future stage, with its own evidence |
| Restoring end-to-end `run_cleanup()` coverage on the shared database | future stage, on the isolated DB |
| Disabling logging in tests | removes exactly the coverage criterion 6 must preserve |
| Refactoring `Conexao_Import_Log` into an instance/DI logger | ~45 static call sites; large, behaviour-risking, unnecessary |
| Strengthening `test-event-cleanup-purge.php`'s `events_found >= count(fixtures)` into a global assertion | would require touching real content; the end-to-end proof moves to the isolated-DB stage |
| Redirecting the manual `scripts/test-event-cleanup.php` to test storage | it is a local-write operator tool, not a test entry point; this stage only removes its two deletes |
| Repairing the general `docs/testing.md` harness gap for every suite | only the importer/operator-store story is documented here |
| Any content, source-configuration, Polylang, deploy, activation, release or commit action | requires separate authorisation |

### 19.2 Immutable historical records (must remain byte-identical)

| Path | Status |
|---|---|
| `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` | completed Stage 0 plan |
| `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` | completed Stage 0 report |
| `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` | completed Stage 0 evidence (18 files) |

The Stage 0 source registry must remain unchanged, and the **18 deactivated G1
probe sources must remain inactive** throughout implementation.

### 19.3 Authorisation still required for each of these

commit · push · merge · deploy · activate · release · any production write · any
database migration · any content operation · provisioning an isolated test DB.

---

## 20. Approval and completion criteria

### 20.1 Gates (numbers, not adjectives)

| # | Command / check | Pass condition |
|---|---|---|
| 1 | `php -l` on every changed PHP file | 0 syntax errors |
| 2 | `./scripts/run-tests.sh --scripts` | all script-contract suites pass, **including** the new gate |
| 3 | `./scripts/run-tests.sh --only conexao-event-importer` | 0 failed suites; the new suite passes; totals recorded |
| 4 | `CONEXAO_TEST_WORKERS=1 ./scripts/run-tests.sh` | `ALL TESTS PASSED`, exit 0 |
| 5 | `CONEXAO_TEST_WORKERS=3 ./scripts/run-tests.sh --php` | exit 0 |
| 6 | operator option hashes before/after each of the three run modes | identical in all three |
| 7 | count of `wp_options` rows matching each test prefix after a clean run | `0` |
| 8 | gate negative controls N1–N8 on scratch copies | exit 1 each; exit 0 unmodified |
| 9 | child variants A3 (three of them) | non-zero exit before any store write |
| 10 | §12 smoke test | every §12.2 assertion true, with hashes recorded |
| 11 | `./scripts/lint.sh` | clean for changed files, or the pre-existing baseline stated explicitly |

### 20.2 Completion checklist (`docs/engineering-standard.md` §14, restated)

- [ ] Every gate in §20.1 has a **real number** in the report; none is asserted.
- [ ] All twelve acceptance criteria (§11) pass, each with its negative control
      demonstrated.
- [ ] No assertion was weakened to obtain a pass; every intentional change is in
      §13.3 and in the report.
- [ ] The assertion scorecard states the baseline limitation honestly (§13.5) and
      **does not** claim unchanged counts.
- [ ] No acceptance criterion depends on an import or a provider request.
- [ ] Nothing in the plan claims a shutdown handler handles `SIGKILL`.
- [ ] `git diff --name-only` contains **only** the files of §16.
- [ ] The four operator options are byte-identical before and after every run
      mode, with the hashes pasted into the report.
- [ ] The Stage 0 plan/report/evidence are untouched; the 18 probe sources remain
      inactive; the source registry is unchanged.
- [ ] No production write, deploy, activation, content change or Polylang change.
- [ ] The report states `PASS`, `PASS WITH LIMITATION` or `BLOCKED` honestly,
      naming every verifier that could not run.
- [ ] Retention (P13) and every other roadmap item remain untouched.

### 20.3 Approval decisions needed before implementation

| Decision | Status |
|---|---|
| Architecture (storage seam + per-process test store) | approved in principle; restated here |
| Define the suffix **before** `wp-load.php` | **requires explicit approval** — it changes the shared bootstrap that every in-process suite loads |
| The accessor throwing (fail-closed) instead of returning a fallback | **requires explicit approval** — the one behaviour change visible to a mis-used harness |
| Retargeting 8 direct option writes in 4 suites | requires approval as part of the suite migration |
| `test_options_isolate()` refusing the four keys | requires approval — it changes an existing helper's contract |
| The controlled production-option write in §12 | **requires explicit approval** — the only step that writes (and restores) a real operator option, locally |

_Last verified: 2026-11-14 by the Stage 0.5 plan revision — plan only, nothing
implemented._






















