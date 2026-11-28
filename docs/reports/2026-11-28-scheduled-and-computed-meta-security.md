# Report — scheduled write paths and computed metadata keys (write-security audit extension)

| | |
|---|---|
| **Stage / task name** | Write-security audit extension: scheduled write paths + computed meta keys |
| **Date** | 2026-11-28 |
| **Author / agent** | Cline (application security engineer) |
| **Branch** | `roadmap/performance-bilingual-security-phpstan` |
| **Start SHA** | `f97a8fe` |
| **Skills followed** | `wp-security-review`, `wp-repository`, `wp-testing` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §2.1/§4.2/§7/§8, `docs/routing.md`, `docs/testing.md`, `docs/reports/2026-11-27-rest-admin-security-audit.md`, `docs/plugins/conexao-commemorative.md` |

## 1. Scope completed

The two gaps the previous audit recorded as open (its finding **I-3** and the
unreviewed scheduled surface):

| Gap | Before | Now |
|---|---|---|
| Computed meta keys | 13 registrations "statically undecidable", covered only by in-process suites | **0 undecided.** 116 keys resolved from source; every REST-exposed protected key is judged identically to a literal one |
| Scheduled write paths | not audited at all | **2 hooks enumerated**, traced to their callbacks, and permanently gated |

Delivered:

1. **Detector D extended** — resolves `self::CONST` / `Class::CONST` (including a
   constant declared in another file), `foreach` over an array literal, a local
   array variable, a static property array, the nested
   `foreach ( $fields as $key => $type )` shape, and `array_merge()` of class
   constants. A key that still cannot be resolved stays a reported coverage note
   (there are none today).
2. **Detector E added** — scheduled write-path audit (registration, callback
   resolution, HTTP-surface crossover, request-data reads).
3. **Two production registrations made explicit** — the fail-closed
   `auth_callback` is now declared rather than inherited (see §4).
4. **Negative proofs** — detector-level snippets *and* real-file mutation proofs
   (§5).

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| WordPress core cron, Jetpack, Polylang, hosting | Outside this repository (`AGENTS.md` boundary) |
| Production cron inspection | No production access is authorised, and a production probe is not a regression test |
| Dynamic taint analysis | Not available in a standard-library static gate; sanitisation was verified by reading each path |
| Locks implemented inside third-party code | Not ours to change |

## 3. Scheduled write-path inventory

Two custom hooks can change persistent state on a timer. Both were traced from
scheduling → registration → callback → every state-changing call.

### 3.1 `conexao_commemorative_boundary`

| Property | Detail |
|---|---|
| Owner | `conexao-commemorative` (`Conexao_Commemorative_Cache`, `platform`, production) |
| Scheduled | `arm()` → `wp_clear_scheduled_hook( self::HOOK )` then `wp_schedule_single_event( $boundary['timestamp'], self::HOOK )` — **no arguments**, deliberately, so `wp_clear_scheduled_hook()` can always clear it and boundaries cannot stack |
| Registered | `add_action( self::HOOK, array( __CLASS__, 'handle_scheduled_boundary' ) )` in `register()` |
| Callback | `handle_scheduled_boundary()` → `invalidate( 'boundary' )` + `arm()` — cache flush and re-arm only |
| Cancelled | `clear()` → `wp_clear_scheduled_hook()` + `delete_option()` |
| Argument validation | none needed: the hook takes no arguments, so there is no input to validate or forge |
| Source/object identity | the persisted `NEXT_OPTION` boundary record; the callback re-derives it |
| Idempotency | `arm()` is clear-then-schedule, so re-running converges on exactly one event |
| Concurrency | the *per-request fallback* (`maybe_purge_due()`) takes `acquire_lock()` (object cache `wp_cache_add`, else a TTL'd option) and releases it in `finally` |
| Request context | the callback reads no superglobal; a separate, capability+nonce-gated admin path (`admin_post_conexao_commemorative_purge`) is a **different hook name**, so it cannot trigger the scheduled one |
| Verdict | **No finding.** Trusted internal scheduled execution; no user context is required or faked |

### 3.2 `conexao_event_import_continuation`

| Property | Detail |
|---|---|
| Owner | `conexao-event-importer` (`tooling`, `production: false`) |
| Scheduled | `arm_cron( $source_id, $run_at )` → `wp_schedule_single_event( $run_at, self::HOOK, array( $source_id ) )`, after clearing any pending event for the same args so exactly one exists |
| Registered | plugin bootstrap: `add_action( Conexao_Import_Continuation::HOOK, static function ( $source_id = '' ) { Conexao_Import_Continuation::handle_cron( (string) $source_id ); }, 10, 1 )` |
| Callback | `handle_cron( $source_id )` → `run_due( $source_id )` |
| Cancelled | `disarm_cron( $source_id )` → `wp_clear_scheduled_hook( self::HOOK, array( $source_id ) )` |
| Argument validation | the slug is only a *lookup key*: `run_due()` re-reads the persisted record and refuses unless `state === scheduled`, so an unknown or forged slug resolves to "not-scheduled" and does nothing |
| Source/object identity | the persisted continuation record (source slug + run_id + last_page); identifiers never come from a request |
| Idempotency | server-clock cooldown check, then a state transition to `running` persisted **before** the work; a completed/removed record cannot re-run |
| Concurrency | `acquire_lock( $id )` per source; on refusal the run is a no-op (`'locked'`) |
| Request context | callback and `run_due()` read no superglobal (the gate enforces this; the `shutdown` fail-safe is a **different hook**) |
| Verdict | **No finding.** Trusted internal scheduled execution. It is `production: false`, so it is not part of the production attack surface at all |

### 3.3 Why no nonce / `current_user_can()` is required here

A cron callback runs with no authenticated user and no request. Requiring a
nonce would either always fail (breaking the feature) or push an author to
invent a "system" nonce — a fake check that hides the real question. The
applicable properties are different, and they are the ones the gate enforces:

1. the hook is resolvable and actually registered (nothing silently dead);
2. the hook is **not** also an `admin_post_*`/`wp_ajax_*` surface (a request can
   never trigger a scheduled write);
3. the callback (and its same-file / class-scoped delegation) reads **no**
   request data (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`,
   `$_SERVER`, `filter_input()`), so nothing from a request can be smuggled into
   a scheduled write.


## 4. Computed metadata keys

### 4.1 Inventory

| Registration site | Key expression | Keys | REST-exposed | auth_callback |
|---|---|---|---|---|
| `conexao-data-model.php::register_provider_meta()` | `foreach ( $meta as $key => $type )` | 6 `_provider_*` | yes | **was inherited** `__return_false`, now declared |
| `conexao-data-model/includes/class-meta.php::register_meta()` | nested `foreach ( self::$fields … )` | 11 legacy editorial keys | yes | **was inherited** `__return_false`, now declared |
| `class-meta.php::register_leisure_coordinates()` | `self::LEISURE_*` | 4 | 2 yes / 2 no | yes on the writable pair |
| `conexao-data-model-commemorative.php` | `foreach ( $all as $key )`, `array_merge()` of 3 class constants | 17 | **no** | yes (and unreachable — `show_in_rest => false`) |
| `conexao-event-runtime.php` | `foreach ( $string_meta … )` / `foreach ( $int_meta … )` | 13 | yes | yes (`current_user_can( 'edit_post', $object_id )`) |
| `empregos-landing.php` | literal `_empregos_link` | 1 | yes | yes |
| `conexao-data-model.php::register_sponsor_contacts_meta()` | `Conexao_Data_Model_Contacts::META_KEY` (declared in another file) | 1 | no | yes |

**116 keys resolved, 0 registrations undecidable** (was 13 undecidable).

### 4.2 Finding and remediation

Two loops registered **protected** (`_`-prefixed) keys as `show_in_rest => true`
without an `auth_callback`. WordPress falls back to `__return_false` for a
protected key (`wp-includes/meta.php:1512-1516`), so the runtime behaviour was
already "REST reads yes, REST writes never". That is the intended behaviour for
both groups (they are saved by the classic editor), but it was *implicit*: a
future reader could not tell "locked down on purpose" from "someone forgot".

Remediation (`conexao-data-model` patch, `1.8.0 → 1.8.1`): declare
`'auth_callback' => '__return_false'` explicitly on both registrations, with a
comment stating why. This is **provably behaviour-identical** — it is the exact
callback WordPress would have installed — and it makes the decision statically
auditable, so the gate can now hold every registration to the same rule.

Risk accepted: none. No REST client gains or loses a capability. A future
ticket that *wants* REST writes for these fields changes one line, and the gate
then requires a real capability check.

## 5. Verification

### 5.1 The gate

```
$ python3 tests/scripts/verify-write-endpoint-authorization.py
audited: 34 admin_post/wp_ajax handler(s), 2 REST route registration(s), 21 meta registration(s), 2 scheduled write path(s)
meta key resolution: 116 key(s) resolved from source (literal, constant, loop or array_merge), 0 registration(s) undecided
29 passed, 0 failed
```

Was `17 passed` with a 13-registration coverage note; now `29 passed`, 0
undecided. No existing detector, floor or proof was removed or relaxed. New
floors: `schedules >= 2`, `resolved_meta_keys >= 10`.

### 5.2 Detector-level negative proofs (inside the gate)

| Proof | Injected defect | Result |
|---|---|---|
| D-computed | nested static-property loop, protected REST meta, no `auth_callback` | caught |
| D-resolve | that shape's key set must resolve to `{'_nested_key'}` | resolves (an unresolved key would have hidden the violation) |
| D-resolve (list) | local-array loop key set must resolve to `{'_loop_key'}` | resolves |
| E-unregistered | `wp_schedule_single_event()` with no `add_action()` | caught |
| E-superglobal | scheduled callback reading `$_GET` | caught |
| E-http-surface | scheduled hook also registered as `admin_post_*` | caught |
| Green controls | guarded computed meta, clean scheduled callback | clean |

### 5.3 Real-file mutation proofs

| Mutation | Gate result |
|---|---|
| removed `'auth_callback' => '__return_false'` from `class-meta.php` | **exit 1** — `protected, REST-exposed meta '_conexao_featured', '_event_date', … '_sponsor_link' declares no auth_callback` (all 11 computed keys named) |
| added `$_GET['conexao_probe']` inside `handle_scheduled_boundary()` | **exit 1** — `scheduled hook 'conexao_commemorative_boundary' -> handle_scheduled_boundary() reads request data …` |
| both reverted | **exit 0**, `29 passed, 0 failed` |

## 6. Limitations

1. **Static + source-reading only.** No production request or cron run was made
   or observed. Host-level cron behaviour (e.g. an event fired by an external
   ping) is outside this evidence.
2. **Structure, not intent.** The gate proves the properties above exist; it
   cannot prove a capability *choice* is the right one, nor that a scheduled
   callback's business logic is correct.
3. **Delegation depth is bounded** (3 hops; class-scoped cross-file resolution
   only for a `Class::method()` call). A deeper chain would be reported
   unguarded — a false positive, which fails closed rather than open.
4. **No taint tracking.** Sanitisation was verified by reading each write path.
5. **Not all of WordPress.** Core meta registration, core cron, Jetpack,
   Polylang and the hosting platform were not reviewed.

## 7. Status

**PASS WITH LIMITATION.** Both previously open gaps are closed and permanently
enforced, including computed-key resolution that removes the old blind spot
entirely. The limitation is §6: source-level evidence for `wp-content/` only —
no production probing, no coverage of core or third-party code, no taint
analysis.

_Last verified: 2026-11-28 by the scheduled/computed-meta security audit extension_

