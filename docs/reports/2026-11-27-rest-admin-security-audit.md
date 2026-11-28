# Report — REST API and administrative write security audit

| | |
|---|---|
| **Stage / task name** | REST + admin write security audit |
| **Date** | 2026-11-27 |
| **Author / agent** | Cline (application security engineer) |
| **Branch** | `audit-rest-admin-security` |
| **Start SHA** | `ceaa1c0` |
| **Skills followed** | `wp-security-review`, `wp-repository`, `wp-testing` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §2.1/§4.2/§7/§8, `docs/routing.md`, `docs/content-model.md`, `docs/testing.md` |

## 1. Scope completed

A **complete** enumeration and read of every custom write-capable surface in
`wp-content/` — 34 WordPress write hooks, 2 custom REST routes and 21 meta
registrations — plus the durability work that keeps the result true:

| Delivered | Detail |
|---|---|
| Full write-surface inventory | 34 `admin_post_*`/`wp_ajax_*` hooks (32 + 2), including the two registered as `'admin_post_' . self::ACTION`, which a naive literal scan misses |
| Per-handler review | capability, nonce, sanitisation, object-level authorisation, and whether the guard precedes the write |
| Permanent gate | `tests/scripts/verify-write-endpoint-authorization.py`, self-proving, fail-closed, with coverage floors |
| Regression suite | `wp-content/themes/conexao-br-irlanda/tests/test-public-rest-write-rejection.php` — closes the "public route silently becomes writable" gap |
| Findings report | this document |

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| WordPress core / Jetpack / Polylang / any third-party plugin | Out of scope by the repository's `AGENTS.md` boundary: this repository owns only `wp-content/` custom code |
| Production endpoint probing | Forbidden without written authorisation (`AGENTS.md`, "No production write unless the task explicitly authorises it"). A production probe is also not a regression test |
| Penetration testing, fuzzing, load testing | Not engineering work that can be made repeatable in CI here |
| The Flutter/mobile REST client | Explicitly out of scope in `AGENTS.md` |
| 13 meta registrations whose key is computed | Not statically decidable; covered instead by the existing in-process REST permission suites (see §3.3) |
| Front-end non-REST request handling in the theme (form posts to `wp-login.php`/core endpoints) | Core-owned; the theme declares no custom form target of its own |

## 3. Method

### 3.1 Enumeration

1. Static scan of `wp-content/plugins/**` and `wp-content/themes/**` for
   `add_action('admin_post_*'|'wp_ajax_*')`, resolving `'admin_post_' . CONST`
   and `'admin_post_' . self::CONST` through the declaring `const`.
2. Static scan for `register_rest_route(`, `register_post_meta(`, `register_meta(`.
3. For each hook, the handler body was extracted (brace-balanced, comments
   stripped) and read, including one to three levels of same-file delegation,
   because the repository deliberately puts the guard chain behind a delegating
   method (`handle_http()` → `handle_request()`).

### 3.2 The ten questions, asked of every write path

| # | Question | Result across all 34 handlers |
|---|---|---|
| 1 | Authenticated where necessary? | Yes — `admin_post_{action}` is authenticated by construction; `wp_ajax_*` uses the authenticated-only hook (no `nopriv` variant exists anywhere) |
| 2 | Capability checked? | **Yes, 34/34.** Mostly `manage_options`; the leisure image handlers additionally require `edit_post` on the target record and `upload_files` for the import |
| 3 | Nonce checked where required? | **Yes, 33/33 that can change state.** The single exception is a write-free 410 stub (finding I-1) |
| 4 | Is the nonce treated as authorisation? | No — every handler that has both checks performs the capability check first (or in the same guard chain); the nonce is never the only gate |
| 5 | Parameters validated? | Yes — `absint`/`sanitize_key`/`sanitize_text_field`/`wp_unslash` at the boundary; transfer handlers additionally validate a run name and a part filename with a `realpath()` containment check |
| 6 | Input sanitised appropriately? | Yes — see above; the two download handlers sanitise **then** validate **then** confirm the resolved path stays inside the export root |
| 7 | Protected metadata correctly permission-gated? | Yes for every literal key; 13 computed-key registrations are covered by in-process tests (§3.3) |
| 8 | Can an unauthorised user change protected data? | **No path found.** The remaining defence is the fail-closed gate, so this stays true |
| 9 | Unexpected methods rejected? | Yes — `admin-post.php` is reachable by GET, and the automation endpoint enforces POST explicitly (`is_post()`), which is exactly the check most code forgets |
| 10 | Errors returned safely? | Yes — `wp_die()`/`wp_send_json_error()` with a fixed message; one admin-only diagnostic passes a WP_Error message through (finding I-4) |

### 3.3 What the existing suites already prove

| Suite | Proves |
|---|---|
| `conexao-data-model/tests/test-leisure-coordinate-rest-permissions.php` | anonymous REST meta write → **401 `rest_cannot_edit`**; authenticated without capability → **403**; authorised → **200** and the value persists; a refused request leaves the stored value untouched; the provenance keys stay out of REST |
| `themes/conexao-br-irlanda/tests/test-leisure-location-services.php` | 102 assertions over the public read contract, including limit enforcement and that job/draft records are never exposed |
| `conexao-translation-rollout/tests/test-legacy-apply-endpoint-closure.php` | the retired apply endpoint cannot mutate |
| `conexao-event-importer` suites | import/cleanup entry points, including operator-store isolation |

## 4. Findings

**No Critical, High or Medium finding was identified in the reviewed surface.**
The findings below are Informational: they record decisions a future reviewer
would otherwise have to re-derive, and each is now either enforced by the gate or
explained in the code.

| ID | Severity | Affected handler | Behaviour | Exploitability | Impact | Remediation | Regression test |
|---|---|---|---|---|---|---|---|
| I-1 | **Informational** | `conexao-translation-rollout/includes/class-conexao-translation-rollout-admin.php::handle_run()` on `admin_post_conexao_translation_rollout_run` | A **closed deprecation stub**: it checks `current_user_can( self::CAP )` then unconditionally `wp_die( …, 410 Gone )`. It performs **no state change** — the only write-hook handler with no nonce check | Not exploitable as a privilege/state issue: the worst case is a cross-site request showing a 410 page to the victim, which changes nothing and leaks only a static message | None | None required. The correct rule is now encoded: the gate requires a nonce only where a handler can reach a state change, so this stub is compliant *by rule*, not by exemption | `verify-write-endpoint-authorization.py` self-proof "a capability-gated handler that cannot write must NOT require a nonce" |
| I-2 | **Informational** | 26 of the 34 handlers live in `conexao-event-importer` (class `tooling`, `production: false`) | Those handlers are capability-gated (`manage_options`) and nonce-gated, but the plugin is **not deployed to production** (`plugins.json`), so they are not part of the production attack surface | n/a in production | None | None. Recorded so this audit is not read as "34 production endpoints were reviewed" | the gate audits the tree (the superset); `plugins.json` owns the production subset |
| I-3 | **Informational** | 13 `register_post_meta`/`register_meta` sites use a **computed** meta key | The key is a loop variable, so "is this key protected?" cannot be answered from source text alone | n/a | None — the keys are declared by `conexao-data-model`'s meta class, whose permission behaviour is proven in-process | The gate prints the count as a **coverage note** instead of silently passing | `conexao-data-model/tests/test-leisure-coordinate-rest-permissions.php` |
| I-4 | **Low** | `conexao-admin-ux/includes/class-leisure-image-admin.php::handle_wiki_import()` | On a failed media sideload it returns `$att_id->get_error_message()` to the browser with HTTP 500 | Requires `upload_files` **and** `edit_post` on the target leisure record — an authenticated, capability-checked administrator | Minor internal-detail disclosure to an already-privileged operator | Acceptable as-is: it is the actionable diagnosis for the only audience that can reach it. If that audience ever widens, return a fixed string and log the detail | — (behaviour deliberately preserved) |

### 4.1 Why the two public REST routes are NOT a finding

`GET /conexao/v1/leisure-coordinates` and `GET /conexao/v1/leisure-cards` use
`permission_callback => __return_true` and `WP_REST_Server::READABLE`. They are
**public read-only by design**: the Lazer archive offers a "near me" filter that a
visitor who grants browser geolocation calls anonymously. Adding authentication
would break the feature, so the security requirement is that they stay
read-only — now asserted from the live REST registry rather than assumed. They
expose only published leisure identities, coordinates and card HTML: the same
data the archive renders.


## 5. Verification evidence

| Command | Exit | Result |
|---|---|---|
| `python3 tests/scripts/verify-write-endpoint-authorization.py` | **0** | `audited: 34 admin_post/wp_ajax handler(s), 2 REST route registration(s), 21 meta registration(s)`; `17 passed, 0 failed`; `coverage note: 13 meta registration(s) use a computed key` |
| same gate after injecting `add_action( 'wp_ajax_nopriv_conexao_leisure_wiki_search', … )` into a real plugin file | **1** | `FAIL: … anonymous write surface registered: wp_ajax_nopriv_conexao_leisure_wiki_search` — **negative proof** |
| same gate, mutation reverted | **0** | `17 passed, 0 failed` |
| `wp-content/themes/conexao-br-irlanda/tests/test-public-rest-write-rejection.php` | **0** | `43 passed, 0 failed` |

The mutation proof matters: the gate fails on a **real repository file**, not only
on its own internal fixtures.

### 5.1 Pre-existing local failure (not caused by this change)

`theme/conexao-br-irlanda/test-stage41-rest-language.php` reports **185 passed,
37 failed** in this local environment. It was re-run with this branch's only theme
change being one added, untracked *test* file (confirmed by `git status`), and it
fails identically — a per-suite PHP process cannot be affected by another suite
file merely existing. The failures are the local test database's content state
(`FAIL: detail ?lang=xx -> 400`, `FAIL: PT master carries conexao_language`), not
this audit. CI builds its own deterministic synthetic fixtures, where the
integration job is green.

## 6. Limitations

1. **Static + in-process only.** No production request was made and none may be
   made without explicit authorisation. A production-only weakness (e.g. a
   host-level rule bypassing a nonce) cannot be found this way.
2. **Not all of WordPress is reviewed.** Core REST routes (`/wp/v2/*`), Jetpack,
   Polylang and the hosting platform are outside this repository and this audit.
3. **13 meta registrations are statically undecidable** and are covered only by
   the in-process suites, which exercise the registration outcome rather than the
   source of every key.
4. **The gate understands structure, not intent.** A handler checking
   `current_user_can( 'read' )` where `manage_options` was intended would pass. The
   gate guarantees a check exists and precedes the write; the *choice* of
   capability remains a review responsibility.
5. **The delegated-body scan is bounded at three calls.** A deeper guard chain
   inside one file would be reported as unguarded — a false positive, which fails
   closed rather than open.
6. **No dynamic taint analysis.** Sanitisation was verified by reading each write
   path, not by automated data-flow tracking.

## 7. What this audit does NOT claim

It does not claim the application is secure. It claims that every custom
write-capable path in `wp-content/` was enumerated and read, that no genuine
vulnerability was found in it, and that the properties reviewed are now
mechanically enforced so a later change cannot silently undo them.

## 8. Status

**Final status: PASS WITH LIMITATION.**

The reviewed surface is clean and now mechanically enforced, with a negative proof
that the enforcement works. The limitation is §6: static and in-process evidence
for `wp-content/` only — no production probing and no coverage of WordPress core
or third-party code. That is stated rather than implied, because "the reviewed
paths pass" is not "the application is secure".

## 9. Extension — 2026-11-28: scheduled write paths and computed metadata keys

This section extends the audit above. It does not revise §1–§8; it closes the
two gaps §2 and finding I-3 recorded as open.

### 9.1 Scope added

| Added surface | Method |
|---|---|
| **Scheduled write paths** — `wp_schedule_event()` / `wp_schedule_single_event()` | enumerated, traced to their callbacks, and permanently gated (detector E) |
| **Computed metadata keys** — 13 registrations previously "statically undecidable" | resolved from source and judged exactly like a literal key (detector D extended) |

### 9.2 Scheduled write-path inventory (2 hooks)

| Hook | Owner | Registered | Callback | Arguments | Concurrency | Verdict |
|---|---|---|---|---|---|---|
| `conexao_commemorative_boundary` | `conexao-commemorative` (production) | `add_action( self::HOOK, array( __CLASS__, 'handle_scheduled_boundary' ) )` | cache flush + re-arm | **none** (deliberate: `wp_clear_scheduled_hook()` can then always clear it) | `acquire_lock()`/`release_lock()` around the per-request fallback | no finding |
| `conexao_event_import_continuation` | `conexao-event-importer` (`production: false`) | plugin bootstrap closure → `handle_cron()` → `run_due()` | resumes one source's enumeration | source slug, re-read from the persisted record and refused unless `state === scheduled` | per-source `acquire_lock( $id )` | no finding |

Neither callback reads a request superglobal, neither hook is also an
`admin_post_*`/`wp_ajax_*` surface, and the two `shutdown`/`wp_loaded` fail-safes
are **different hooks** — so no request can trigger a scheduled write.

**Deliberate non-requirement:** a cron callback is *not* required to carry a
nonce or `current_user_can()`. There is no authenticated user at cron time;
demanding one would make the feature unreachable or push an author to fake a
system nonce. The applicable properties are registration, resolvability,
request-independence and argument identity, and those are what the gate now
enforces.

### 9.3 Computed metadata keys (0 undecided, 116 keys resolved)

The detector now resolves `self::CONST` / `Class::CONST` (including a constant
declared in another file), `foreach` over an array literal, a local array
variable, a static property array, the nested
`foreach ( $fields as $key => $type )` shape, and `array_merge()` of class
constants. Coverage went from **13 undecided** to **0**, with **116 keys**
resolved.

Two loops registered **protected** keys as `show_in_rest => true` without an
declared `auth_callback` (the `_provider_*` loop and the legacy editorial-fields
loop). WordPress already fails those closed — `wp-includes/meta.php:1512-1516`
falls back to `__return_false` for a protected key — so the runtime behaviour was
already "REST reads yes, REST writes never". The remediation declares
`'auth_callback' => '__return_false'` explicitly, which is **provably
behaviour-identical** (it is the callback WordPress would have installed) and
makes the decision statically auditable. `conexao-data-model` `1.8.0 → 1.8.1`.

**Accepted residual risk: none.** No REST client gained or lost a capability. A
future change that *wants* REST writes for those fields must now declare a real
capability check, and the gate will require it.

### 9.4 Tests and proofs added

| Evidence | Result |
|---|---|
| `python3 tests/scripts/verify-write-endpoint-authorization.py` | `audited: 34 handlers, 2 REST routes, 21 meta registrations, 2 scheduled write paths`; `116 keys resolved, 0 undecided`; **29 passed, 0 failed** (was 17) |
| Detector negative proofs (in-gate) | computed-meta violation caught; nested/loop key sets resolve; unregistered scheduled hook caught; `$_GET` in a cron callback caught; scheduled hook doubling as `admin_post_*` caught; both green controls clean |
| Real-file mutation: remove the declared `auth_callback` from `class-meta.php` | **exit 1**, naming all 11 computed keys |
| Real-file mutation: read `$_GET` inside `handle_scheduled_boundary()` | **exit 1**, naming the hook and callback |
| Both mutations reverted | **exit 0**, `29 passed, 0 failed` |

### 9.5 Limitations of the extension

Carried over from §6, plus: scheduled-path evidence is **static only** (no
production cron was run or observed); delegation is followed 3 hops and
class-scoped across files; and the gate proves structure, not business-logic
correctness. WordPress core, Jetpack, Polylang and the hosting platform remain
out of scope.

### 9.6 Status of the extension

**PASS WITH LIMITATION.** Both previously open gaps are closed and permanently
enforced. The full narrative, inventory and proofs are in
[`2026-11-28-scheduled-and-computed-meta-security.md`](2026-11-28-scheduled-and-computed-meta-security.md).

_Last verified: 2026-11-28 by the REST/admin write security audit (extended with the 2026-11-28 scheduled-path and computed-meta sections; §1–§8 preserved as the 2026-11-27 record)_


