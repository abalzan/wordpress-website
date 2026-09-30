# Report — Stage 0: Automatic PT→EN Translation Architecture (read-only feasibility gate)

> **This stage changed no code and touched no production state.**
> `Production writes: 0`. HTTP verbs used against `https://conexaobr.ie`: **`GET` only**.
> The translation engine was never invoked in `apply` mode. No plugin was
> installed, activated or deactivated. No content, option, menu, cron job or
> endpoint was created. No existing translation data was changed. The
> Flutter/mobile repository was not accessed.

|||
|---|---|
| **Stage / task name** | Stage 0 — Automatic PT→EN translation workflow: architecture and feasibility investigation |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `5820edec54624affdabf56a3dd011b67b60e0c71` |
| **Working tree at finish** | clean (`git status --porcelain` → 0 entries) |
| **Production target** | `https://conexaobr.ie` (WordPress.com), read-only |
| **Status** | **PASS WITH CONDITIONS** — see §17 |

---

## 1. Executive conclusion

The question Stage 0 was asked — *how can a future automated workflow detect PT
changes and safely invoke the existing translation lifecycle in production
without bypassing the repository's safety model?* — has a clean answer, and it
is not a new design.

**The shared engine is already programmatically invocable, and the repository
already contains a working proof of exactly this.** `Conexao_Translation_Rollout_Engine::run()`
(`class-conexao-translation-rollout-engine.php:819`) is a **static function over
injected arrays of callables**. It reads no superglobal, calls no `is_admin()`,
no `current_user_can()` and no nonce function. Everything WordPress-bound —
`find_pt`, `find_en_for_pt`, `slug_collision`, `create_en`, `repair_en`,
`link_pair`, `pair_ok`, `remove_en` — arrives as an `$adapter` array. So an
automation entry point does not need to *become* the engine; it only needs to
**call** it. The engine file's SHA-256 (`baf85283…a6ce4`) is byte-identical to
the Phase 2 baseline, confirming this investigation altered nothing.

**Option A is feasible and recommended: a permanent production plugin whose
automation entry point is a thin orchestrator over `run()`.** Option B
(GitHub Actions → REST) is **rejected**, and the reason is not preference — it
is that B **cannot reach the engine at all**. There is no REST route in either
rollout plugin (0 in source; 0 of 665 live production routes match), and the
one real entry point requires the wp-admin **cookie** session that an
Application Password does not provide. I verified this live: `GET
/wp-admin/tools.php` → **302** to `wp-login.php?…&reauth=1`. B could therefore
only *reimplement* `create_en` / `link_pair` / the B2 field write / the taxonomy
step over core REST — which is precisely the second translation engine that
`AGENTS.md` and the `wp-translation-rollout` skill forbid, and it would lose the
PT snapshot, `diff_snapshots()`, `assert_no_pt_drift()` and the taxonomy
capability that exist only inside the engine.

Two findings materially change the starting position and are recorded as
blockers rather than assumed away:


## 2. Current translation architecture

### 2.1 Where the engine lives, and what it owns

| Concern | Location |
|---|---|
| Engine (sole owner of the lifecycle) | `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php` (966 lines, SHA-256 `baf85283…a6ce4`) |
| Admin screen + the only apply entry point | `includes/class-conexao-translation-rollout-admin.php` (211 lines) |
| Plugin bootstrap | `conexao-translation-rollout.php:18-21` (requires engine + admin, calls `Admin::init()`) |
| EN stage/data layer | `wp-content/plugins/conexao-en-translation/` (7 stages, 15 files) |
| Stage registration | `conexao-en-translation.php:58-82`, on `plugins_loaded` |
| Local CLI runner (thin wrapper) | `scripts/run-en-translation.php` |
| Read-only production dry-run tool | `docs/evidence/2026-09-30-*/en-dry-run.py` |

The engine "contains no translated copy and no stage record list" and "never
writes on plugin bootstrap or activation" (its own header, lines 18-21). Both
properties must be preserved by any Stage 1 entry point.

### 2.2 The lifecycle, mapped to real line numbers

| Step | Engine method | Line | Writes |
|---|---|---|---|
| 1. Inventory | `run()` → `collect_states()` | 819 / 505 | none |
| 2. Manifest validation | `validate_manifest()` | 154 | none |
| 3. Dry-run plan | `build_plan()` | 228 | none |
| 4. Snapshot | `capture_snapshots()` | 548 | none |
| 5. Apply | `apply_plan()` / `apply_removals()` | 576 / 675 | explicit only |
| PT-drift guard | `assert_no_pt_drift()` | 770 | none |
| 6. Verify + numeric gate | `collect_verify()` / `calculate_gate()` | 399 / 369 | none |

`run()` validates the config (820), calls `manifest_callback` and validates the
manifest (826-831), resolves the mode and the dry-run flag (833-834), checks
every required adapter callable (836-843) and refuses `remove` unless the stage
declares `allow_remove` (845-847) — **all before any write**.

### 2.3 The decisive property for automation

```php
public static function run( array $config, array $adapter, array $args = array() )
```

`$config` and `$adapter` are plain arrays of callables; `$args` is
`array( 'dry_run' => bool, 'mode' => 'run'|'remove' )`. There is no global
state, no request context and no ambient authority. **The same `run()` call is
reachable from `admin-post.php`, from a REST route, from a WP-Cron callback or
from WP-CLI, with no change to the engine.** That is what makes Option A a thin
orchestration layer rather than a rewrite.

### 2.4 B1 and B2 both fit the same engine

- **B1 (linked EN record).** `stage-config.php:19-168` — `create_en` inserts the
  EN post (98), `link_pair` calls `pll_set_post_language` +
  `pll_save_post_translations` (139-160), `pair_ok` verifies the link in **both**
  directions (161-163), `remove_en` deletes only the EN record (164-166). The PT
  record is only ever read.
- **B2 (authored EN field on the same PT record).**
  `leisure-description-stage.php:418-472` — `create_en`/`repair_en` both write
  `_leisure_excerpt_en`, `link_pair` is a **deliberate no-op** (447-454, "this
  stage creates NO second identity"), `slug_collision` is always `false`
  (434-438, "this stage never mints a slug"), and `remove_en` deletes the field
  (467-471). The B2 vocabulary mapping is documented at lines 18-33.

**An automated workflow therefore does not need two paths.** The engine
abstracts the strategy difference behind the adapter, and both are already
proven idempotent by `test-future-stage-smoke.php:123-129`.


### 2.5 There is currently no second engine — and no lock

Greps over `wp-content/plugins/`: `register_rest_route` / `rest_api_init` /
`WP_REST_Controller` / `wp_ajax_` → **0 hits**; `wp_schedule_event` /
`wp_schedule_single_event` → **0 hits**; `WP_CLI` in the rollout plugins →
**0 hits**. `test-plugin-separation.php:221-235` actively asserts that **no
`conexao` cron hook is scheduled**, so introducing cron is a deliberate,
test-visible behaviour change that this stage does not authorise.

The only WordPress-bound state the engine uses is the shared-slug permit
transient (`stage-config.php:482`, `conexao_en_translation_shared_page_slug`,
5-minute TTL) — a *scoped slug exception*, **not** a concurrency lock. There is
no `flock`, no lock option and no atomic `wp_cache_add` anywhere in the two
rollout plugins. **Concurrency control does not exist and must be built.**

## 3. Production execution constraints

From `docs/releases.md:212-226` and `docs/deployment.md:13-30`:

| Constraint | Consequence for an automation design |
|---|---|
| **No SSH / SFTP** | Code can only reach production as a ZIP uploaded through wp-admin. |
| **No WP-CLI** | No `wp plugin activate` from a shell; no scripted migrations. A cron/CLI-only design is not available. |
| **No filesystem access** | `dist/` on a maintainer's machine is the only place artifacts exist. |
| **No database access** | Content changes are admin screens or REST only — never SQL. |
| **No cookie session for API clients** | An Application Password authenticates **REST only**. Verified: `GET /wp-admin/tools.php` → **302** → `wp-login.php?redirect_to=…&reauth=1`; `GET /wp-admin/admin-post.php` → **200 with a 0-byte body**. |
| Managed caching | Verification must assert behaviour, never cache-rewritten headers. |
| Third-party plugins installed, not vendored | Polylang is pinned (`polylang/polylang` 3.8.10, active) — the engine's `pll_*` calls depend on it at run time. |

Because there is no CLI, `docs/releases.md:224` states the governing rule:
**"every production capability must also have an admin screen"** (standard
§0.12). An automation entry point that cannot also be operated by a maintainer
in wp-admin would violate this.

### 3.1 Measured live production state (read-only, GET)

| Measurement | Value | Method |
|---|---|---|
| Installed plugins | **18** | `GET /wp-json/wp/v2/plugins` |
| `conexao-translation-rollout` | **ABSENT** | same |
| `conexao-en-translation` | **ABSENT** | same |
| Platform plugins active | 4/4 (`data-model` 1.6.1, `content` 1.0.0, `admin-ux` 1.0.6, `event-runtime` 1.2.1) | same |
| REST routes | **665**; **0** matching rollout/translation | `GET /wp-json/` |
| EN guide records | present and served — e.g. `25116` = EN `driving-licence-in-ireland-how-to-exchange-your-cnh`; `/en/guias/…` → **200** | `GET /wp/v2/guide/25116`, `GET /en/guias/…` |

This **updates** the prior record. `docs/reports/2026-09-30-phase-3-translation-verification.md:663-676`
reported both temporary plugins still installed and active; as of today they
are gone. The rollout they applied is nonetheless present. Any Stage 1 plan must
start from *tooling absent, content applied* — not from the older state.

## 4. Option A feasibility — permanent production plugin / controlled endpoint

Investigated first, as required.

| Question | Finding | Repository evidence |
|---|---|---|
| Where does the engine live? | `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php` | engine header, lines 1-35 |
| Can it already be invoked programmatically without duplicating logic? | **Yes.** `run()` is static and takes `$config`, `$adapter`, `$args`; every WordPress primitive is an injected callable | engine 819-843; `required_adapter_keys()` 482-494 |
| Are its methods/lifecycle suitable for a production entry point? | **Yes.** All eight lifecycle steps are public or private static methods with no request-context coupling | `04-engine-lifecycle-surface.txt` |
| Does it assume manual admin execution? | **No.** The engine never touches `$_POST`, `is_admin()`, `current_user_can()` or nonces — those live only in the **admin class** | grep over the engine file: 0 hits; `class-…-admin.php:74-78` holds both gates |
| What auth model is available on WP.com? | `manage_options` + nonce for interactive runs; Application Password + capability for machine-triggered runs | `class-…-admin.php:24,74,78`; REST 200 with Basic auth |
| Can a REST route / admin action / cron hook trigger it? | **Yes, all three are technically possible.** The repository has used the admin-action pattern 8+ times | `conexao-page-translation.php:38`; `class-event-image-sync-admin.php:31`; `class-import-log-admin.php:31-33` |
| Does production permit the mechanism? | A custom plugin can be uploaded and activated via wp-admin, and may register REST routes and cron events. **Not yet verified for a tooling plugin** (B1) | `docs/deployment.md` upload procedure; `docs/releases.md:212-226` |
| How is an unrestricted public endpoint prevented? | Not yet implemented — **this is Stage 1 work**. The required controls are listed in §8 | no route exists today, so nothing is exposed yet |
| How is concurrency prevented? | **No primitive exists.** Must be built (B3) | 0 lock primitives in either plugin |
| How does an interrupted run resume? | The engine is idempotent: `test-future-stage-smoke.php:123-129` proves create=0/update=0 on re-run | also `test-rollout-failure-proofs.php:65-66` |
| How do snapshots and dry-run gates fit automated execution? | They are already *inside* `run()`: `capture_snapshots()` precedes any write, and `dry_run => true` never enters the apply branch | engine 904-945 vs 947-951 |
| How are audit logs retained without secrets? | Not yet implemented — Stage 1 work (§8, §12). The admin screen already renders a full report including the gate | `class-…-admin.php:174-209` |
| Can the entry point reuse the trusted planner/apply/verify code? | **Yes, by construction** — it calls the same `run_callback` the admin screen calls | `class-…-admin.php:89-95` |
| What extra structure is required? | A new plugin, a `plugins.json` entry, a provider interface, a lock, a change-detection contract (§12-14) | `plugins.json` is the single registry |

**Verdict: feasible.** The only genuinely open question is B1 — the release and
activation path for a *new permanent* plugin, which this repository has never
exercised for tooling.


## 5. Option B feasibility — external GitHub Actions / REST orchestration

Assessed only. **Not implemented.**

| Question | Finding |
|---|---|
| Which APIs are available? | Core `wp/v2` REST: posts, pages, guides, terms, media, and (for an administrator) `wp/v2/plugins`. Verified live: 665 routes. |
| Can all required information be read via REST? | **Mostly.** The Phase 1-3 tooling already reads PT state, EN state and digests this way (`en-translation-inventory.py`, `en-dry-run.py`). Blocked: `_leisure_excerpt_en` / `_provider_excerpt_en` are protected meta and are **not** readable over REST — the verification report documents this as the reason its B2 counts were unverifiable (`2026-09-30-phase-3-translation-verification.md`: "the tool cannot read `_leisure_excerpt_en`"). |
| Can all required mutations be performed via REST? | Posts/terms/links, yes. Polylang relationships (`pll_save_post_translations`) have **no** equivalent in core REST. |
| Can B1 relationships and B2 field updates be done safely? | **No.** B2 protected-meta writes have no REST surface, and the Polylang relationship write is not exposed. |
| Can the complete lifecycle be reproduced? | **No.** The PT snapshot, `diff_snapshots()`, `assert_no_pt_drift()` (engine 770-805) and the optional `taxonomy_callback` are in-process behaviour with no external equivalent. |
| Would it duplicate or weaken the engine? | **Both.** It would duplicate planning/apply/verify and lose every fail-closed guard. |
| How would secrets be managed? | `WP_USERNAME` / `WP_APPLICATION_PASSWORD` as Actions secrets — a **full administrator REST credential**, a large blast radius for a translation job. |
| Retries and partial failures? | Would need external retry logic; the engine's idempotence would be unavailable. |
| Snapshots and verification? | Would have to be reimplemented outside WordPress. |
| What must be reimplemented? | `collect_states`, `build_plan`, `capture_snapshots`+`diff_snapshots`, `apply_plan`, `assert_no_pt_drift`, `collect_verify`, `calculate_gate`, the taxonomy step and the manifest format. **Essentially the whole engine.** |

### 5.1 The decisive objection

Option B is not merely weaker — **it cannot reach the engine at all.** The
engine's only apply entry point is `handle_run()`, gated by
`current_user_can( 'manage_options' )` (admin:74) **and**
`check_admin_referer()` (admin:78), evaluated against the wp-admin **cookie
session**. I verified live that an Application Password does not establish that
session: `GET /wp-admin/tools.php` → **302** to
`wp-login.php?redirect_to=…&reauth=1`, and `GET /wp-admin/admin-post.php` →
**200 with a 0-byte body**. There is no REST route to fall back to (0 in source,
0 of 665 live routes).

So B's only workable form is: **reimplement the engine in Python and call core
REST.** That is a second translation engine, forbidden twice over — `AGENTS.md`
("Reuse, do not reinvent") and the `wp-translation-rollout` skill ("Never
hand-write an `apply.php` / `audit.php` copy of the engine lifecycle"; "the
repository ships the plan, not a production mutation"). It is also the
configuration with the **highest** risk of PT data loss, because generic core
REST writes to PT records have no engine-side PT-drift guard at all.

## 6. Recommended architecture

**Proceed with Option A: a permanent production plugin whose automation entry
point is a thin orchestration layer over `Conexao_Translation_Rollout_Engine::run()`,
which remains the sole mutation authority.**

The entry point's entire job is: authenticate → acquire a lock → resolve a stage
**from a fixed allowlist** → require an explicit `apply` intent → call that
stage's own `run_callback` → record the report → release the lock. It must not
plan, count, match, snapshot, create, link, write meta or evaluate the gate; every

### 6.1 The entry point can remain thin — the decisive proof

The three existing thin wrappers over `run()` are the precedent, and each is
deliberately non-authoritative:

- `scripts/run-en-translation.php:139-145` — selects a stage, reads the manifest
  **from the registered config** ("never recomputed here, so the runner can never
  disagree with the engine", lines 128-129), calls `run_callback`, prints the
  engine's own summary and gate, and exits on the engine's verdict (191-193).
- `class-…-admin.php:89-95` — capability + nonce, then
  `call_user_func( $config['run_callback'], … )`, then stashes the report and
  redirects.
- `en-dry-run.py` — reads production over REST, dumps the repository's **own**
  manifests out of PHP, and drives the **unmodified** engine's `build_plan()` /
  `calculate_gate()` / `diff_snapshots()`.

All three already establish the exact boundary a Stage 1 entry point must keep.
**No engine change is required, and none is recommended.**

## 7. Why the recommendation preserves the existing engine

1. **The mutation authority is unchanged and singular.** `run()` remains the only
   code path that calls `create_en`, `repair_en`, `link_pair` or `remove_en`. The
   entry point holds no WordPress-write code at all.
2. **Every gate keeps its force.** `validate_manifest()` still fails closed on a
   missing key, a language mismatch, a malformed row or a duplicate `en_slug`;
   `build_plan()` still turns a slug collision or a broken pair into a **hard
   conflict**; `calculate_gate()` still requires `missing_en`, `conflicts`,
   `pt_drift` and `extra_failures` to be zero; `assert_no_pt_drift()` still
   re-compares every PT snapshot after the last write.
3. **The lifecycle is preserved in order, not reconstructed.** inventory →
   manifest → dry-run → snapshot → apply → verify → idempotence → gate is
   `run()`'s own control flow (engine 819-965). An entry point that adds,
   reorders or short-circuits a step is a second engine by definition.
4. **The stage allowlist is the engine's own registry.** The entry point resolves
   a stage through `Engine::get_stage()` (engine 80) or `registered_stages()`
   (70), exactly as the admin screen does (admin:83, 131). A stage not registered

## 8. Required security controls

Each row is a risk from the Stage 0 brief, the path by which it becomes real,
and the control that must exist. **All of these are Stage 1 work; none exists
today.** The last column marks what already exists.

| # | Risk | Path by which it becomes real | Required control | Exists today |
|---|---|---|---|---|
| S1 | Unrestricted public translation endpoint | A future REST route registered without auth | Register under a private namespace; `permission_callback` requires `manage_options`; **never** a `__return_true` callback; reject when the capability is absent. Verify with an unauthenticated GET returning 401/403. | no (no route exists) |
| S2 | Credential or secret exposure | A machine-triggered run needs a shared secret | Store the secret as a WordPress **constant/option written through wp-admin**, never in the repository, a manifest, a report or a log. Redact `Authorization` headers in every error path — the precedent is `rest.py`, whose docstring states the Authorization header "is never rendered into an error". | pattern exists in `scripts/lib/rest.py:65-77` |
| S3 | Nonce/capability bypass | An entry point calls `run_callback` without re-checking authority | Re-verify `current_user_can( 'manage_options' )` **and** a nonce (admin path) or a constant-time secret comparison (machine path) on **every** invocation, before resolving the stage. | yes, in `handle_run()` (74, 78) |
| S4 | CSRF on an interactive run | An admin-post endpoint triggered by a third-party page | `check_admin_referer()` on every state-changing request; the endpoint is POST-only. | yes (admin:78) |
| S5 | Least privilege | A plugin runs with administrator rights | Scope the plugin: no `WP_CLI`, no theme edits, no option writes beyond its own namespaced keys, no outbound requests except to the configured provider host. | pattern: engine writes nothing on bootstrap (engine header, 20-21) |
| S6 | Stage injection | A caller supplies an arbitrary stage id | Resolve the stage **only** through `Engine::get_stage()` / `registered_stages()`; additionally restrict to a hardcoded allowlist of the 7 ids from `conexao_en_translation_stage_ids()`. Reject anything else with 400. | partly — engine registry exists; allowlist does not |
| S7 | Provider secret/content leakage | A provider error or prompt is echoed into an EN field | Sanitise and truncate provider output; never write a raw provider payload; never store a provider key in a manifest. | no |
| S8 | Unauthorised removal | `mode => 'remove'` deletes EN records | `remove` is refused unless the stage declares `allow_remove` (engine 845-847), and the automated path must never default to it. | yes (engine 845) |

## 9. Required concurrency controls

**There is no lock anywhere in the two rollout plugins today** (grep: no
`flock`, no lock option, no `wp_cache_add`). The only transient in play is the
shared-slug permit, which is *not* a lock. So this is entirely Stage 1 work.

| # | Risk | Required control |
|---|---|---|
| C1 | Two runs execute concurrently and interleave writes | An **atomic** run lock acquired before any write and released in a `finally` block. Use `wp_cache_add()` / `add_option()` semantics (atomic) — never a read-then-write check. |
| C2 | A crashed run leaves a permanent lock | Store `acquired_at` + a run id; treat a lock older than a hard TTL as stale and reclaimable, but **log the reclaim** as a run anomaly. |
| C3 | A lock silently blocks the pipeline | The entry point must **fail closed with a clear, non-mutating busy error** (HTTP 409) — never queue, never wait-and-retry blindly. |
| C4 | Cron overlap | Any scheduled trigger must guard on the same lock, so a pv-triggered run overlapping an interactive run is refused rather than interleaved. |
| C5 | Interleaved B1 creates producing EN duplicates | Even with a lock, `build_plan()`'s `slug_collision` check (stage-config:60-90) is the second line of defence: a slug held by *any* other record is a hard conflict, not a duplicate. |
| C6 | Two runs on different WordPress.com containers | The lock must live in a site-wide atomic store (options table or object cache), not in a per-request or per-container resource. |

   with the engine is not runnable.
5. **PT stays canonical and read-only.** For B1 the PT record is never written
   (`stage-config.php:91-115` only reads it); for B2 the only permitted write is

## 10. Required failure / retry controls

The engine already fails closed **15 times before any write** (every `return new
WP_Error` site precedes the apply branch; see `04-engine-lifecycle-surface.txt`).
Stage 1 must preserve that and add the run-level controls.

| # | Condition | Required behaviour | Exists today |
|---|---|---|---|
| F1 | Missing configuration / unknown stage | `WP_Error` → **no mutation** | yes (engine 820-824, 100-142) |
| F2 | Invalid manifest (missing key, language mismatch, malformed row, duplicate `en_slug`) | `WP_Error` → **no mutation** | yes (engine 154-216, 826-831) |
| F3 | Missing adapter callable | `WP_Error` → **no mutation** | yes (engine 836-843) |
| F4 | Missing credentials | Refuse before resolving the stage; never fall back to an unauthenticated or partial run | no (Stage 1) |
| F5 | Concurrent run | Refuse with a busy status; **no mutation** | no (Stage 1, C1) |
| F6 | Unknown / malformed provider response | Treat as a **hard failure of that record**; do not write a partial or empty EN value; fail the numeric gate | no (Stage 1) |
| F7 | Dry-run gate is not PASS | **Never** enter apply. Require `gate.gate === 'PASS'` from an immediately preceding dry-run of the same manifest | engine computes the gate; the *sequencing rule* is Stage 1 |
| F8 | Partial apply (interrupted mid-loop) | Re-run the same stage: `apply_plan()` is idempotent, so completed records become `skip` and only the remainder is written | yes (engine 278-288; `test-future-stage-smoke.php:127-129`) |
| F9 | PT drift detected | Reported as `PT SOURCE CHANGED`, counted in `pt_changed`, fails the gate | yes (engine 770-805) |
| F10 | Stale lock after an interrupted run | Reclaim the lock, then **re-run dry-run first**; the idempotent apply converges and the gate re-proves the final state | idempotence yes; reclaim + sequencing Stage 1 |
| F11 | Automatic retry storms | A bounded retry policy with backoff and a hard cap on attempts per record per window; after the cap the record is escalated to a human, not retried forever | no (Stage 1) |

**Fail-closed invariant:** *any* of F1-F7 must result in **zero writes**. A
missing configuration, missing source record, invalid manifest, failed
verification, missing credential, unknown provider response, concurrent run or
unexpected production state must never leave production half-written.

## 11. Required snapshot / rollback controls

| # | Risk | Required control | Exists today |
|---|---|---|---|
| R1 | PT overwritten by an automated run | The pre-apply PT snapshot is captured for **every** affected record *before* the first write, and re-compared after the last one. Any changed field is reported as `PT SOURCE CHANGED` and fails the gate. The only tolerated difference is a Polylang language backfill `'' → 'pt'` on a record that had no language. | yes — `capture_snapshots()` (548) + `assert_no_pt_drift()` (770-805) |
| R2 | Snapshot excludes a field it should protect | The B2 snapshot deliberately omits `_leisure_excerpt_en` — the field the stage owns — so an apply is not misreported as drift. Any new field added to a snapshot must be justified against the stage's own writes. | yes — `leisure-description-stage.php:240-247` |
| R3 | EN records created but wrong / unresolvable | `remove` mode deletes exactly the EN records the manifest owns, and re-runs the same PT-drift comparison after removal. It is refused unless the stage sets `allow_remove`. | yes — `apply_removals()` (675-709), gate at 845-847 |
| R4 | No durable pre-apply snapshot outside WordPress | A run log must record the plan and the snapshot **digest** per run, so a specific pre-apply state can be identified and reproduced. The repository already has the pattern: `docs/evidence/2026-09-30-en-rollout-phase-3-apply/10-mutation-snapshot-phase3r2.json` (438 objects, digest `d679f1c1…`, byte-identical across runs). | pattern exists; automation must adopt it |
| R5 | Rollback is impossible in production | Rollback must remain an **admin operation** (upload the previous plugin ZIP, or press Remove), because production has no CLI. Any automated entry point must document its rollback as a wp-admin procedure. | required by `docs/releases.md:232-256` |
| R6 | A code rollback is assumed to undo content | Stated explicitly: a code rollback does **not** undo a content change (`docs/releases.md:250-256`). An automated rollout needs its own documented content rollback. | documented |

**Snapshot placement in automated execution:** because `run()` already orders
inventory → validate → plan → snapshot → apply, a single `apply` call is
snapshot-safe by construction. The only additional requirement is that the
**plan, the dry-run verdict and the snapshot digest are persisted to the run
log before the apply branch is entered** — so that a failed run can always be
diagnosed against the exact pre-apply state.

   the documented EN-prefixed meta field, and the snapshot deliberately
   **excludes** that field so an apply is not misreported as drift
   (`leisure-description-stage.php:240-247`).
6. **It adds no second list of records.** The manifest stays authored data in the
   stage plugin; the shared-slug permit is still derived from the manifest
   (`stage-config.php:385-398`), and retired rows stay in the exclusions registry
   (`exclusions-data.php:51-62`), which is *not* fed to the engine.
7. **The dry-run-before-apply invariant is preserved by construction.** The

## 12. Required change-detection contract

**Not implemented in Stage 0.** This is the contract the future trigger must
satisfy. Note first what the repository already has: the B2 stage carries the
authored **Portuguese source** in each manifest row (`pt_source`) and refuses a
stale translation — `leisure-description-stage.php:211-221` sets
`stage_conflict = 'PT source changed since the English was authored…'`, which
becomes a hard conflict and fails the gate. That is a *per-record staleness
detector that already exists*; it is a safety net, not a trigger.

### 12.1 Information the detector needs

| Change type | Signal needed | Why |
|---|---|---|
| Newly created PT content | post type, `post_status`, PT language, slug, `post_modified_gmt` | a new eligible PT record with no EN counterpart is the primary trigger |
| Modified PT content | `post_modified_gmt` + a **content digest** of the translatable fields | `_leisure_excerpt_en` is a function of `post_excerpt`; a changed excerpt must re-trigger |
| Deleted PT content | the record's absence from a full eligible inventory | today handled as a `skip` ("PT record absent in this site", engine 248) plus the exclusions registry |
| Restored PT content | record reappearing in the inventory with a *newer* `post_modified_gmt` | the six trash restorations on 2026-09-30 prove this is a real event class |
| Taxonomy changes | term assignments on both the record and the taxonomy itself | `conexao_category` is translated and its EN terms are stage-owned; `conexao_county`/`conexao_town` are **shared** and must never be translated |
| Metadata / field changes | the same content digest, extended to the stage's snapshot field list | reusing the snapshot field list avoids a second field inventory |
| Changes that must **not** trigger translation | PT→EN relations that already exist; EN-side edits; the stage's own B2 field writes; a record excluded by policy | otherwise every run re-translates and the pipeline never converges |

### 12.2 Trigger-model trade-off

| Model | What it detects | Strength | Weakness here |
|---|---|---|---|
| WordPress lifecycle hooks (`save_post`, `transition_post_status`, `updated_postmeta`, `set_object_terms`) | every change, immediately | no polling, no missed writes | fires **inside** the save that caused it, on whatever request made it; a translation write would re-trigger itself; a post saved by an importer or a restore fires the same as a human edit; the engine's own apply would re-enter the hook. **Unusable as the sole trigger without a re-entrancy guard.** |
| Polling / inventory diff | drift since the last successful run | reconciles anything missed, including changes made while the system was down, and direct edits and restores | needs stored state; must be robust to a partial previous run |
| Scheduled reconciliation | same as polling, on a cadence | bounded, predictable, auditable | depends on a scheduler — and WordPress.com pv-cron is **not** verified here (B2) |
| **Hybrid (recommended)** | hooks as a wake-up hint + a full diff as the source of truth | self-healing, re-entrancy-free, covers deletions/restores/taxonomy | needs persisted state; two moving parts |

**The safest future model is a hybrid, with a diff as the source of truth and
hooks used only as a cheap wake-up hint.** A hook firing should at most *request*
a run; it must never translate inline. The run then performs a **full inventory
diff** against persisted state, which is inherently self-healing and covers

## 13. Required translation-provider interface

**No provider is chosen, integrated or configured.** This records the boundary
Stage 1 must design.

The interface must sit **between the trigger and the manifest**, never inside
the lifecycle:

```
PT source  ──►  PROVIDER INTERFACE  ──►  EN result  ──►  manifest row
             (translate)                             (en_slug, en_title,
                                                      en_content, en_excerpt,
                                                      en_meta, pt_source)
```

| Property | Requirement |
|---|---|
| **Provider-agnostic** | The lifecycle must accept an EN result from any source. Swapping providers must change **only** the implementation behind the interface, never a lifecycle step, a manifest row shape or a gate. |
| **Deterministic and inspectable** | Every EN result must carry the PT source it was produced from (`pt_source` — the field the B2 stage already compares), so a stale translation is detectable exactly as it is today (`leisure-description-stage.php:211-221`). |
| **Stateless with respect to WordPress** | The provider returns data; it does not write. **Only `run()` writes.** This is the rule that keeps the engine the sole mutation authority. |
| **Fail-closed on anything unknown** | A timeout, a malformed response, a missing field, a language mismatch or an unexpected schema must produce a **hard record failure** (F6) — never a partial or empty EN value, and never a skipped gate. |
| **No secret in the manifest or the log** | Credentials come from the environment / wp-admin configuration; a provider key must never reach a manifest row, a report or the run log. |
| **Rate- and budget-bounded** | Bounded retries with backoff, a per-run record cap and a per-window attempt cap (F11), so a failing provider cannot burn the site or loop forever. |

**Invoked from WordPress, or externally?** Conceptually **abstracted behind the
interface**, so the execution site can be chosen later without touching the
lifecycle. Concretely:

- **In-process, inside WordPress** is the simplest and keeps one trust boundary —
  but it puts an outbound network call inside a production request, so a slow
  provider becomes a production availability and timeout problem.
- **Out-of-process** (a worker calls the provider, then submits the EN result
  back) keeps WordPress fast and allows proper timeouts and concurrency — but it
  needs a result-submission channel, which re-introduces an authentication
  surface (S1-S3).

**Recommendation for Stage 1: define the interface and make the provider call an
injectable, out-of-process step whose result is fed in as a manifest.** This
gives the fail-closed semantics for free — no provider response can exist in the
system without having been validated into a manifest row, and a manifest is
exactly what `validate_manifest()` already rejects when it is malformed.

## 14. Audit logging and reporting

- Every run writes one **append-only** record: run id, timestamp, trigger
  (hook / schedule / manual), stage id, manifest version, plan counts, snapshot
  digest, gate verdict, per-record actions and the error list.
- **No secret is ever written**: no credentials, no provider key, no
  `Authorization` header. The redaction precedent is `scripts/lib/rest.py:65-77`.
- Retention must be long enough to be evidence and bounded enough not to grow
  without limit.

## 15. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-30-stage-0-auto-translation-architecture.md` | added | this report |
| `docs/evidence/2026-09-30-stage-0-auto-translation-architecture/` | added | 5 evidence files + README |
| `docs/evidence/README.md`, `docs/reports/README.md` | updated | documentation index entries, per `docs/README.md` ("must be added here in the same change") |

**N added, 0 modified, 0 deleted in `wp-content/`.** No plugin, theme, script,
test, `plugins.json` entry or generated registry region was modified. The engine
SHA-256 is unchanged (`baf85283…a6ce4`). `docs/evidence/2026-09-26-stage-l/gate.json`
was rewritten as a side effect of running the gate runner and was **restored
with `git checkout`**, exactly as the Phase 3 report did.

## 16. Runtime / content / Polylang / route impact

- **Runtime impact:** none. No code changed; WordPress behaves exactly as before.
- **Content / data impact:** **none**, confirmed by `git status --porcelain` → 0
  entries.
- **Polylang impact:** **none.** No Polylang setting, language assignment or
  translation relationship was created, changed or removed. PT remains canonical.
- **Route / HTTP impact:** **none.** No route, redirect, canonical, hreflang or
  sitemap behaviour changed. The only production HTTP was read-only `GET`.

## 17. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | 0 writes. Verbs used: `GET` only. |
| Deploy / upload | **no** | no ZIP built or uploaded |
| Plugin install / activation | **no** | read-only census only (`GET /wp-json/wp/v2/plugins`) |
| Plugin deactivation / removal | **no** | |
| Content or DB mutation | **no** | no post, term, meta or option written |
| Translation applied | **no** | the engine was never called, in any mode |
| Cron job or endpoint created | **no** | |
| Polylang setting changed | **no** | |
| Credentials changed or exposed | **no** | read from `.env`, used only for `GET`; never printed or written |
| Flutter / mobile repository | **not accessed** | out of scope by `AGENTS.md` |

## 18. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh` | 1 | In-process PHP: **63 total, 63 passed, 0 failed**; **4,216 assertions passed, 0 failed**. Script-contract: **6 total, 5 passed, 1 failed** (`verify-i18n-freshness.py`). HTTP acceptance: **3 total, 3 passed, 0 failed** (20 + 44 + 92 rows). |
| `python3 scripts/verify-permanent-gates.py` | 1 | **7 gates, 6 passed, 1 failed**; 89 assertions passed, 3 failed. `translation_completeness` PASSED (23 assertions, 319 allowlisted by documented policy). `i18n_freshness` FAILED (8 passed, 3 failed). |
| `php scripts/generate-registry-docs.php --check` | 0 | `registry OK: 14 plugins validated, 23 generated regions current (zero writes)` |
| `git status --porcelain` | 0 | 0 entries (clean) after restoring `gate.json` |
| `sha256sum …/class-conexao-translation-rollout-engine.php` | 0 | `baf85283…a6ce4` — **identical to the Phase 2 baseline** |

### The three translation-rollout suites specifically

| Suite | Result |
|---|---|
| `test-translation-rollout-engine.php` | **36 passed, 0 failed** |
| `test-rollout-failure-proofs.php` | **12 passed, 0 failed** |
| `test-future-stage-smoke.php` | **13 passed, 0 failed** |

## 19. Known pre-existing failures

Neither failure is caused by this stage, which changed no code.

1. **`i18n_freshness` (script-contract + permanent gate).** Three `.pot`
   catalogues are older than their sources: `conexao-br-irlanda` (by 92,483s),
   `conexao-content` (76,025s) and `conexao-event-runtime` (76,025s). Verified to
   be file-mtime staleness, not a content defect.
   `tests/baseline/permanent-gates.json` already records `i18n:*:stale_catalogue`
   for these domains as pre-existing debt. The gate prints "2 pre-existing, 1
   new"; the three reported failures are exactly the three stale catalogues
   above, and the count difference reflects a baseline captured at an older SHA
   rather than a regression from this stage. The correct fix is
   `./scripts/i18n-make-pot.sh`, which is **out of scope** for Stage 0 and was
   deliberately not run.
2. Everything else passes: 63/63 in-process suites, 4,216/4,216 assertions, 3/3
   acceptance suites, 6/7 permanent gates.

## 20. Limitations

1. **WordPress.com cron behaviour was not verified.** I did not create a
   production cron job (prohibited). Whether a scheduled trigger fires reliably
   under pv wp-cron is therefore **unknown** and is blocker B2.
2. **A new permanent production plugin was not deployed or activated**
   (prohibited). The B1 release/activation path is unproven in this repository.
3. **No REST route, lock, provider interface or change-detection contract was
   implemented** — Stage 0 is a gate and the brief forbids implementation. The
   feasibility of each is argued from source, not demonstrated at runtime.
4. **The B2 field values in production could not be read** — `_leisure_excerpt_en`
   / `_provider_excerpt_en` are protected meta, invisible to REST even with an
   administrator Application Password. B2 verification must therefore run
   **inside** WordPress in Stage 1, a further argument for Option A.
5. **Production plugin state differs from the last report.** The rollout plugins
   are now absent (§3.1). Who removed them, and when, is not established here.
6. **No concurrency behaviour was tested**, because no lock exists to test.
7. This is a **read-only investigation**: no claim here rests on executing an
   automated apply, since Stage 0 forbids it.

## 21. Open blockers

| ID | Blocker | Impact |
|---|---|---|
| **B1** | The production release/activation path for a **new permanent** plugin is unproven — no `production: true` tooling plugin has ever been produced by this repository (all four are platform plugins) | blocks the deploy step of Stage 1 |
| **B2** | WordPress.com cron behaviour under pv is unverified | blocks any *scheduled* trigger; a hybrid requiring an external or manual kick is unaffected |
| **B3** | No lock primitive exists; concurrency control must be designed and proven | blocks any unattended apply |
| **B4** | No translation-provider interface exists anywhere in the repository | blocks the generation step |
| **B5** | No change-detection contract or persisted diff state exists | blocks automatic triggering |
| **B6** | B2 protected-meta values are unreadable over REST | B2 verification must run inside WordPress |


## 22. Stage 1 prerequisites

1. **Resolve B1** — decide and document how a new permanent production plugin is
   released and activated: a `plugins.json` entry with `production: true` +
   `build: true`, a `dist/release.json` artifact, and the wp-admin upload
   procedure, following `docs/releases.md`.
2. **Resolve B2** — verify the scheduling model, or choose an external/manual
   trigger so the pipeline does not depend on pv-cron.
3. **Design and prove the lock** (C1-C6) with a concurrency test that fails
   closed.
4. **Define the provider interface** (B4, §13) — interface only, no provider.
5. **Define the change-detection contract** (B5, §12) — including the persisted
   diff state and the digest definition.
6. **Add the stage allowlist** (S6) and the fail-closed sequencing rule "no apply
   without a preceding PASS dry-run" (F7).
7. **Decide audit-log storage and retention** (§14) with secret redaction.
8. **Re-baseline production** — Stage 1 must re-measure the inventory, because
   the tooling is now absent and the committed baselines predate the Phase 3
   apply.
9. **Extend the permanent invariant gates** to assert the new controls: no public
   route, a lock present, no cron hook without an explicit allowlist — noting
   `test-plugin-separation.php:221-235` currently asserts **no** cron hook exists
   and will need a deliberate, documented update.
10. **Obtain explicit human authorisation for any production write.** Stage 0
    grants none.

## 23. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-09-30-stage-0-auto-translation-architecture.md` | this report |
| `docs/evidence/2026-09-30-stage-0-auto-translation-architecture/` | new evidence set + README |
| `docs/evidence/README.md` | index entry for the new evidence set |

`docs/engineering-standard.md`, `docs/routing.md`, `docs/releases.md`,
`docs/plugins/*.md`, `plugins.json` and every generated registry region are
**unchanged** — `generate-registry-docs.php --check` reports zero writes. No
plugin behaviour changed, so `docs/plugins/conexao-translation-rollout.md` and
`docs/plugins/conexao-en-translation.md` correctly remain as they are.

## 24. Rollback / recovery

Nothing to roll back: no code, content, configuration or production state was
changed. To discard this stage's output entirely, delete the new report and the
new evidence directory. The one side effect — `gate.json` — was already restored
with `git checkout`.

## 25. Final status

**Final status: PASS WITH CONDITIONS**

The investigation is complete and every required question is answered from the
actual repository and read-only production evidence, with real numbers. The
conditions are the six open blockers (§21) and the seven limitations (§20) —
chiefly that **no required verifier could be run for the things Stage 0 was
forbidden to build**: no cron, no REST route, no lock and no provider interface
were exercised, and the B1 production deployment path remains unproven. The
architecture decision itself is evidence-backed, not assumed.

---

_Last verified: 2026-09-30 by Stage 0 — Automatic PT→EN translation architecture (read-only; 0 production writes; engine SHA-256 unchanged)_
