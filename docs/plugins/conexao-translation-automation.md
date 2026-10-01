# conexao-translation-automation

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | platform |
| **Production** | yes |
| **Build** | yes |
| **Compose mount** | yes |
| **Dependencies** | `conexao-translation-rollout` |
| **Version** | 0.3.0 (authoritative source: `wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Production platform plugin.** Part of the production steady state.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

## Purpose

The **permanent** plugin for the automatic PT→EN translation program. Stage 1
establishes only the *invocation boundary*: a thin orchestrator that validates
its invocation context, resolves the **existing** shared translation engine and
delegates to that engine in a non-mutating **proof** mode, returning a
structured result.

It is deliberately **not** the automation program. Stage 1 contains no
translation logic, no provider, no cron, no change detection, no locking and no
apply path.

Owner: project maintainer. Introduced by Stage 1
(`docs/reports/2026-09-30-stage-1-auto-translation-plugin-boundary.md`).

## The ownership boundary (the whole point of this plugin)

| Concern | Owner |
|---|---|
| inventory, manifest validation, dry-run plan | `Conexao_Translation_Rollout_Engine` |
| snapshot, apply, PT-drift guard, verify, numeric gate, remove | `Conexao_Translation_Rollout_Engine` |
| stage configurations (manifest, adapter, `run_callback`) | the stage plugin (`conexao-en-translation`) |
| **authorisation, mode gate, stage allowlist, result contract** | **this plugin** |

This plugin calls exactly one lifecycle function — the stage's own
`run_callback`, which is the engine's supported entry point. It never reads a
manifest, never builds an adapter, never interprets a plan and never recomputes
a gate, so a second mutation authority cannot exist here.

## Files

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-translation-automation/` |
| Entry point | `conexao-translation-automation.php` |
| Orchestrator | `includes/class-conexao-translation-automation-orchestrator.php` |
| Result contract | `includes/class-conexao-translation-automation-result.php` |
| Frontend effect | **none** |
| Admin screen | **none** in Stage 1 |
| REST routes | **none** in Stage 1 |
| Cron hooks | **none** in Stage 1 |
| Tests | `tests/test-automation-boundary.php` |

## Invocation modes

| Mode | Stage 1 behaviour |
|---|---|
| `proof` | The only mode honoured. Forces `dry_run => true`; zero writes by construction. |
| `apply` | **Recognised, hard-disabled.** Always fails closed (`apply_disabled`). |
| *(missing)* | Fails closed (`missing_mode`). **There is no default mode.** |
| *(anything else)* | Fails closed (`unknown_mode`). Never coerced to apply or proof. |

There is deliberately no `missing mode => apply` and no `unknown mode => apply`
behaviour, and no fallback that could reach a mutating path.

## Stage allowlist (Stage 0 control S6)

An explicit, code-level allowlist — not "whatever is registered":

`en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page`,
`en-leisure-description`, `en-course-provider-description`

A stage absent from this list cannot be reached even when another plugin
registers it with the engine.

## Security boundary

| Control | Stage 6 implementation |
|---|---|
| Authorisation | Required and checked **first**, before the engine is resolved |
| Capability | `manage_options`; a mismatched assertion is refused, not downgraded |
| Nonce | **Stage 6 added the admin entry point, so a nonce is now required.** `Conexao_Translation_Automation_Admin_Trigger` verifies a dedicated nonce action and refuses a request without it, following `Conexao_Translation_Rollout_Admin` |
| Public endpoints | **One authenticated admin endpoint only:** `admin_post_conexao_translation_automation_proof`, plus the **Tools → Translation Automation** screen that carries its nonce. No `register_rest_route`, no `admin_post_nopriv_*`, no `wp_ajax_*`, no `__return_true` |
| Method | POST only. `admin-post.php` is GET-reachable, so the method is enforced explicitly rather than assumed |
| Entry-point count | Exactly **one** production automation entry point, asserted structurally by count *and* filename |
| Secrets | `Conexao_Translation_Automation_Result::assert_no_secrets()` refuses credential-shaped keys and values, and runs on the trigger's **result boundary**; the provider credential is read from `CONEXAO_TRANSLATION_PROVIDER_KEY` in the environment and never written to an option |
| Fail-closed | Every failure path returns `mutation_permitted = false` and `mutation_occurred = false` |
| Cron | None. `wp_schedule_*` is asserted absent; the model remains explicit, operator-driven invocation |

Controls deferred to later stages and **not** claimed here: transport-level
authentication, rate limiting, and audit retention.

## The production proof trigger (Stage 6)

`Conexao_Translation_Automation_Admin_Trigger` is the single production entry
point. Its chain, in order:

```
admin request -> POST -> authenticated -> manage_options -> nonce
             -> input validation -> Trigger::fire() (PROOF only) -> safe result
```

Steps 1–5 all complete **before** the orchestrator is reached, so an
unauthorised or malformed caller can never cause a provider call. Every
authorisation failure is paired with an orchestrator-invocation counter that
must remain at zero.

**Apply is structurally unreachable.** The accepted mode vocabulary has exactly
one member, `proof`; `apply` is *not a mode* rather than a disabled flag, so it
is refused by the same unknown-value branch as any unrecognised string.
`dry_run` is not an accepted request field, `environment` is derived
server-side, and `bootstrap` is hard-coded `false` — this entry point cannot
adopt a baseline either.

Installation and activation in production remain a manual operator action.

## Dependency

Declared as a real WordPress plugin dependency
(`Requires Plugins: conexao-translation-rollout`), so WordPress itself refuses
to activate this plugin without the engine. At run time the boundary also
checks `class_exists()` and fails closed with `missing_engine` when the engine
is absent — no mutation, structured failure.

## Activation safety

The plugin registers **no** activation, deactivation or uninstall hook, and
contains **no** call to `wp_insert_post`, `wp_update_post`, `wp_delete_post`,
`wp_insert_term`, `wp_update_term`, `update_option`, `add_option`,
`wp_update_nav_menu` or any `pll_*` write. Activation is inert. This is
asserted by the test suite rather than merely documented.

## Promotion (Stage 2)

Stage 1 left B1 unresolved: this plugin was truthfully registered as
`tooling` / `production: false` / `build: false` because its dependency
(`conexao-translation-rollout`) was itself tooling. **Stage 2 resolved it** by
promoting the engine first:

| Plugin | Class | Production | Build |
|---|---|---|---|
| `conexao-translation-rollout` | `platform` | yes | yes |
| `conexao-translation-automation` | `platform` | yes | yes |

The promotion was accepted by the existing `validate_lifecycle_rules()` with
**no rule weakened** — `platform` + `production: true` + `build: true` is
already a legal combination. The shared engine's source is byte-identical
before and after: SHA-256
`baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`, asserted by
the test suite and by `tests/scripts/verify-stage2-promotion.py`.

## Stage 2: the safety foundation

### The lock (B3, C1-C6)

`Conexao_Translation_Automation_Lock` — site-wide (not per-stage), atomic,
fail-closed. The primitive is a conditional `INSERT` relying on the UNIQUE key
on `wp_options.option_name`, so **the database** decides the winner. Every
mutation is a compare-and-swap on the exact stored bytes.

`add_option()` was rejected: it is check-then-insert (a TOCTOU race) **and** its
INSERT is `ON DUPLICATE KEY UPDATE`, so a losing racer would silently overwrite
the winner's lock. `wp_cache_add()` was rejected: it is only durable with a
persistent object cache, which this repository does not assume.

| Concern | Rule |
|---|---|
| Ownership | Every acquisition carries a unique `run_id`, propagated into the audit record |
| Stale policy | Reclaimable only when `now - acquired_at > ttl`; TTL default 900s, max 86400s |
| Malformed state | Never reclaimed, never overwritten, never deleted — fails closed |
| Release | Only the owner; enforced by a value-scoped `DELETE`, not a stale read |
| Held lock | Second run gets `locked`, performs no mutation, is never queued |
| Audit | Every outcome returns a structured, secret-free event |

### The apply gate (F7)

`Conexao_Translation_Automation_Apply_Gate` enforces **no apply without a
preceding PASS dry-run**. `FAIL`, `ERROR`, invalid, missing, stale, mismatched
and unavailable are all "not PASS".

The approval is a **digest bound to the exact work**, never a boolean:
manifest digest, plan digest, snapshot digest, stage, run id, environment and
configuration identity. A dry run publishes the approval for its own plan, so
"review plan A, apply plan B" fails closed.

### Snapshot sequencing

`validated PASS → persist plan/snapshot identity → apply`. If persistence
fails there is **NO APPLY** — never "apply and record afterwards". There is
deliberately no rollback automation: rollback remains the wp-admin/operator
procedure.

### The environment guard

`Conexao_Translation_Automation_Environment` refuses apply unless the declared
environment **and** `wp_get_environment_type()` agree, plus explicit production
authorisation. Missing or contradictory metadata fails closed.

### The prerequisite chain

```
lock → dry-run → environment guard → F7 PASS → approval binding
     → snapshot persisted → APPLY → verify → release lock
```

Every link fails closed, and the lock is released in a `finally` block on every
path.

## Tests

| Suite | Proves |
|---|---|
| `test-automation-boundary.php` | Loading, activation safety, no cron/REST/provider, fail-closed paths, secret containment |
| `test-automation-lock.php` | Acquisition, contention, ownership, stale reclaim, malformed handling, deterministic race simulation |
| `test-automation-apply-safety.php` | F7 gate, approval binding, snapshot sequencing, environment guard, the full chain |
| `test-automation-promotion.php` | Engine digest, promotion classification, separation, no credentials |
| `tests/scripts/verify-stage2-promotion.py` | Registry promotion, lifecycle invariants, dependency graph, deterministic artifacts, engine byte-identity |

Run with `./scripts/run-tests.sh --only conexao-translation-automation`.

## Stage 3: change detection, provider boundary, trigger, audit

Stage 3 adds the four pieces between the Stage 2 boundary and a future real
translation, and deliberately stops short of performing one.

### The ownership rule, unchanged

`Conexao_Translation_Rollout_Engine` is still the ONLY mutation authority.
Stage 3 adds **zero** WordPress content writes to this plugin — asserted
structurally, not documented.

| Concern | Owner |
|---|---|
| inventory, manifest, plan, snapshot, apply, verify, gate | **the shared engine** |
| source projection + digest, change set, reconciliation | `…_Digest`, `…_Inventory`, `…_Change_Detector` |
| accepted PT baseline, its version and its fail-closed rules | `…_Source_State` |
| provider contract and its validator | `…_Provider_Interface`, `…_Provider_Result` |
| run trail and its retention | `…_Audit` |
| wake-up hints | `…_Hooks` |
| when to reconcile, and in which mode | `…_Trigger` |

### Change detection

A translatable change is *one* thing: the record's translation-relevant
projection changed. Each stage declares its own field list, cross-checked
against the live engine registry on every run, so a renamed post type cannot
silently change what is hashed.

| Profile | Stages | Digested |
|---|---|---|
| `b1` | `en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page` | title, content, excerpt, slug, meta description + the translated taxonomies (by slug) |
| `b2` | `en-leisure-description`, `en-course-provider-description` | title, excerpt, slug — the field the B2 stage's own drift guard compares |

Deliberately **excluded**, each on repository evidence: the EN meta key a B2
stage owns, the shared `conexao_county` / `conexao_town` taxonomies, and the
language-neutral fields (`post_date`, `post_author`, `menu_order`, thumbnail)
the engine merely copies. EN records are refused outright.

### Persisted state

One `wp_options` row, `conexao_translation_automation_source_state`,
`autoload = false`, holding **digests only** — never PT content.

Every abnormal state is a distinct, named status and every one of them is a
hard failure except `missing`:

`missing` · `corrupt` · `incompatible` · `unexpected_stage` · `invalid`

**`missing` never means "translate everything".** It yields
`bootstrap_required`, proposes zero work, and writes no baseline as a side
effect. Adopting a first baseline is a separate, explicit `bootstrap` act that
still mutates nothing.

### Provider

An **interface and a validator only**. No implementation, no HTTP client, no
vendor. Proven three ways: the contract is an `interface`, no class implements
it, and a static scan finds zero `wp_remote_*` / `curl_*` / vendor references.

`unknown response → hard record failure`. Missing field, wrong type, missing or
mismatched identity, mismatched source digest, wrong target language, any extra
key, any **mutation instruction**, malformed JSON, partial response and unknown
status are all refused with no partial acceptance. The result digest is computed
by the validator, so a provider cannot grade its own homework.

Request identity is deterministic over (source digest, stage, field set) and
excludes the run id, so "nothing changed" is recognisable without demanding
deterministic provider wording.

### Trigger

**Explicit invocation plus the hook marker. No cron.** B2 is resolved by
*removing* the pv-cron dependency: reconciliation truth is the full inventory
diff, so a missed wake-up is recovered by the next reconciliation and a
duplicate one finds no work. Nothing is scheduled, and no admin screen, REST
route or AJAX handler exists.

A hook may set **one boolean**. Nothing else.

### Audit

`wp_options`, `autoload = false`, **20 records**, oldest pruned first,
successful and failed runs retained identically. `assert_no_secrets()` runs on
the write path, so a record carrying a credential is refused rather than
written.

### New persistence

Five `wp_options` rows, all namespaced to `conexao_translation_automation_*`,
all infrastructure: lock, apply state, source state, audit, wake-up marker.
**No transient or object-cache key is introduced**, so the Stage 2
admin-user-scope cache exemption is untouched.

### Tests

| Suite | Assertions |
|---|---|
| `test-automation-change-detection.php` | 108 |
| `test-automation-provider.php` | 222 |
| `test-automation-trigger.php` | 98 |
| `test-automation-audit.php` | 80 |
| `test-automation-hooks.php` | 50 |
| `tests/scripts/verify-stage3-automation.py` | 116 |

Full detail:
`docs/reports/2026-09-30-stage-3-change-detection-provider-trigger.md`.

_Last verified: 2026-09-30 by Stage 3 — change detection, provider boundary, trigger and audit_