# Report — Stage 3: change detection, provider boundary, trigger and audit

> **This stage changed no production state.**
> `Production writes: 0`. Production was not contacted at all. No plugin was
> uploaded, installed, activated or scheduled. No production route, cron job or
> option was created. No PT or EN content, taxonomy term, menu or Polylang
> relationship was changed. No translation was performed. No provider was
> chosen, configured or called. The Flutter/mobile repository was not accessed.
>
> **The production system remains structurally unable to translate anything.**
> There is no provider implementation, no HTTP entry point and no cron; the
> only way to start a run is an in-process PHP call, and that call reaches only
> the orchestrator's PROOF mode.

| | |
|---|---|
| **Stage / task name** | Stage 3 — change detection, provider interface, trigger, audit |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `5a75417018c79ebbc6b9ac0ae976e1469c002db8` |
| **Predecessor** | Stage 2 — PASS WITH CONDITIONS |
| **Status** | **PASS WITH CONDITIONS** — see §13 |

---

## 1. Stage 3 status

**PASS WITH CONDITIONS.**

The change-detection contract, the persisted state, the provider boundary, the
trigger and the audit trail are implemented, integrated with the existing
orchestrator, and locally proven. Every fail-closed rule the brief demands is
enforced in code and asserted by a test, including the rule that matters most:
**a missing baseline can never become mass translation**.

The conditions are not defects in this stage's work. They are the same three
facts that were true at the end of Stage 2, none of which Stage 3 was
authorised to change:

1. **Production installation has not been performed** and was not authorised.
   Stage 3 built a production plugin; it did not install it.
2. **The trigger has no production entry point.** The trigger model is proven
   *architecturally* and exercised in-process; there is deliberately no admin
   screen, REST route or cron to reach it in production, because every one of
   those would be a new reachable surface that Stage 3 was told not to create.
3. **The Stage 2 admin-scope cache exemption remains open**, unchanged.

`i18n_freshness` remains the single pre-existing test failure (§11.1).

---

## 2. What was built

Nine new files, all inside `conexao-translation-automation`. The shared engine
was not touched.

| File | Role |
|---|---|
| `class-conexao-translation-automation-digest.php` | the projection profiles and the canonical digest |
| `class-conexao-translation-automation-source-state.php` | the versioned, fail-closed persisted state |
| `class-conexao-translation-automation-inventory.php` | the current, read-only PT inventory |
| `class-conexao-translation-automation-change-detector.php` | the reconciliation and the change set |
| `class-conexao-translation-automation-provider.php` | the provider **interface** only |
| `class-conexao-translation-automation-provider-result.php` | the fail-closed validator |
| `class-conexao-translation-automation-audit.php` | the bounded audit trail |
| `class-conexao-translation-automation-hooks.php` | the wake-up hints |
| `class-conexao-translation-automation-trigger.php` | the trigger and the integration |

Five new suites plus one new script gate:

| Suite | Proves |
|---|---|
| `test-automation-change-detection.php` | the digest contract, the change set, the state rules |
| `test-automation-hooks.php` | hooks set one boolean and nothing else |
| `test-automation-provider.php` | the interface exists and nothing implements it; every malformed answer is refused |
| `test-automation-audit.php` | persistence, completeness, the secret rule, bounded retention |
| `test-automation-trigger.php` | the full path, plus the structural "no second engine" proof |
| `tests/scripts/verify-stage3-automation.py` | static proof: engine bytes, no second engine, no provider, no cron, no secrets |

---

## 3. Change detection

### 3.1 The contract

A translatable PT change is defined by **one** rule: *the record's
translation-relevant projection changed*. "Translation-relevant" is not a
guess — each stage declares its own field list, and that list is cross-checked
against the live engine registry on every run.

| Profile | Stages | PT fields in the digest | Derived from |
|---|---|---|---|
| `b1` | `en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page` | `post_name`, `post_title`, `post_content`, `post_excerpt`, `conexao_meta_description`, plus the **translated** taxonomies `conexao_category` / `conexao_tag` (by SLUG) | exactly the PT values the authored EN row mirrors (`en_slug` / `en_title` / `en_content` / `en_excerpt` / `en_meta_description`) |
| `b2` | `en-leisure-description`, `en-course-provider-description` | `post_name`, `post_title`, `post_excerpt` | the B2 stage's **own** drift guard compares `post_excerpt` against the authored `pt_source` (`leisure-description-stage.php:211-221`) |

`assert_profile_matches_config()` refuses a stage whose registered
`source_post_type`, `source_lang` or `target_lang` disagrees with its profile,
so a renamed post type cannot silently change what is hashed.

### 3.2 What does **not** trigger translation, and why

Every exclusion is repository evidence, and each is asserted by a test.

| Excluded | Evidence |
|---|---|
| the EN meta key a B2 stage owns (`_leisure_excerpt_en`, `_provider_excerpt_en`) | the stage's own snapshot omits it deliberately; feeding generated EN back into the PT digest would make every apply look like PT drift |
| `conexao_county` / `conexao_town` | SHARED proper-name taxonomies (`stage-config.php:126-133`): the same physical term serves both languages |
| `post_date`, `post_author`, `menu_order`, featured image | language-neutral values the engine COPIES; they are not translation inputs |
| modified timestamps, audit ids, lock state, run ids | volatile or internal; they would make the digest move on every run |
| EN records entirely | `assert_digestable()` requires a provable `pt` language, so an EN record never reaches the digest at all |
| EN-only changes | same rule: refused at the inventory boundary, not discovered by comparing equal digests |
| automation / audit metadata | the marker, the source state and the audit trail are not fields in any profile |
| lock state changes | the lock is a separate option, never a digested field |
| unrelated PT fields | excluded by the closed field list above; the tests assert `post_date`, `post_author`, `menu_order`, `thumbnail` and `post_modified` are absent from the payload |

### 3.3 Canonical serialization, before hashing

1. Build a payload with a fixed key order (the profile's own field order).
2. Every scalar is cast to a string; every value is exactly what WordPress
   returned, never a normalised variant.
3. `canonical()` recursively sorts **map** keys, so key order cannot change the
   digest. **Lists keep their order**, because order is meaningful data.
4. Taxonomy term slugs are sorted, and hashed by SLUG rather than term ID
   (a term ID is environment-local; the slug is the portable identity).
5. `hash( 'sha256', wp_json_encode( canonical( $payload ) ) )`.

### 3.4 The digest examples the brief asks for

All are asserted in `test-automation-change-detection.php` against a **real
live PT record**, read-only:

| Situation | Result |
|---|---|
| same PT source state read twice | **same digest** |
| `post_excerpt` edited on a B2 record | **different digest** |
| `post_content` edited on a B1 record | **different digest** |
| the stage's own EN meta value changed | **same digest** (generated output is not a source) |
| `conexao_county` / `conexao_town` assignment changed | **same digest** (shared taxonomy) |
| an EN record passed to the projection | **refused** — never digested |
| array keys reordered | **same digest** |

### 3.5 Reconciliation and the change set

`current inventory → compare with persisted source state → deterministic change
set`. The result is sorted before hashing, so the change-set digest depends on
content and never on build order.

| Change | Detection | Disposition |
|---|---|---|
| `new` | in current, not in persisted | `translation_operation` |
| `modified` | in both, digest differs | `update_existing_en` |
| `restored` | previously tombstoned, now present | `update_existing_en` |
| `deleted` | in persisted, not in current | **`manual_intervention_required`** |
| `unchanged` | identical | `audit_only` |
| `invalid` | impossible digest / no profile | **`manual_intervention_required`** |

Two ordering rules are load-bearing and were **found by the tests**, not
predicted:

- **`restored` is decided before `unchanged`.** A tombstoned record that comes
  back is a restoration even when its digest is byte-identical to the baseline;
  checking the digest first reported it as `unchanged` and silently swallowed
  the restoration.
- **An `absent` current row is not a change at all.** The stage manifest naming
  a record this site does not have is a documented absence (the engine's own
  `skip` bucket), not corruption and not a new translation.

### 3.6 Deletion policy: deliberately none

`deleted` is `manual_intervention_required` and the detector performs **no
mutation**. The repository has no safe automatic policy for a PT deletion:
`allow_remove` exists on every stage, but the engine's `remove` mode is a
documented **operator** rollback reached only through an explicit
`mode => remove`, and Stage 0 control S8 forbids the automated path from
defaulting to it. Removing published EN because its PT source vanished could
destroy human-authored English with no dry run, no gate and no snapshot. A
deletion is reported **once** and then tombstoned, so it is not re-reported on
every later reconciliation.

### 3.7 B1 / B2 stay distinct

Three mechanisms, all asserted:

1. the change identity is `stage + stable key`, so `en-guide:x` and
   `en-leisure-description:x` are different objects in different maps;
2. the `mode` (`b1` / `b2`) travels **with every row**, so a consumer never
   re-derives it;
3. the B2 digest is taken over `post_excerpt`, the exact field the B2 stage
   writes as its English — so a B2 edit is `modified`, never a B1 object
   creation.

---

## 4. Persisted state and persistence safety

### 4.1 Storage

**One `wp_options` row**, `conexao_translation_automation_source_state`,
`autoload = false`.

| Candidate | Decision |
|---|---|
| `wp_options` | **CHOSEN.** The only writable store guaranteed on WordPress.com (no SSH, no WP-CLI, no filesystem). Stage 2's lock already proved this plugin can use it atomically. One row makes "the state" atomic; a per-stage row set would let a partial write desynchronise stages. |
| a custom table | Rejected. `dbDelta` on WordPress.com is heavier, needs its own lifecycle and undo, and buys nothing for a digest index. |
| a file | Rejected. WordPress.com has no filesystem. |

The row holds **digests and identities only** — never PT content. A digest is
sufficient to decide "changed or not", and it cannot leak authored content into
a log. The static gate asserts this.

### 4.2 What is stored

`{v, projection, inventory_digest, stages, last_run_id, status, updated_at}`.

`stages` is `stage → identity → {digest, status}`. `status` is `present` (with
a 64-hex digest) or `absent` (with **no** digest — a manifest entry naming a
record this site does not have). Both are legal; everything else is invalid.

### 4.3 Fail-closed reading

Every abnormal state is a **distinct, named** status. None is "no changes",
and none is "everything changed".

| Persisted state | Status | Consequence |
|---|---|---|
| absent | `missing` | **bootstrap required** — refuses to diff, writes no baseline |
| not an array | `corrupt` | hard failure |
| unknown `v` | `incompatible` | hard failure |
| different `projection` | `incompatible` | hard failure |
| non-allowlisted stage | `unexpected_stage` | hard failure |
| impossible digest / unknown row status | `invalid` | hard failure |

`write()` is the mirror image: a record this class could not later read is
refused **before** it reaches the database. A storage write failure is a hard
error, never a silent success. Corrupt state is left **intact** for an operator
to inspect rather than silently replaced.

### 4.4 Missing state never means "translate everything"

This is the critical rule and it is directly tested:

```
persisted state = missing, bootstrap not requested
   -> outcome    = bootstrap_required
   -> actionable = 0            (NOT ONE record proposed)
   -> baseline written? NO      (absence is not a side effect)
   -> audited as a refusal
```

Adopting a first baseline is a **separate, explicit** `bootstrap => true` act,
and it still translates nothing and mutates nothing.

---

## 5. Provider boundary

### 5.1 No provider/network implementation

**There is no provider implementation in this repository.** This is proven, not
promised, three ways:

- `Conexao_Translation_Automation_Provider_Interface` is declared as an
  **interface**, and a test asserts **no declared class implements it**;
- a static scan asserts **zero** occurrences of `wp_remote_post`,
  `wp_remote_get`, `wp_remote_request`, `curl_exec`, `fsockopen`, and zero
  vendor references (OpenAI, Anthropic, Claude, Gemini, DeepL, AWS, Azure);
- there is no HTTP client, so a network call is not merely avoided — it is
  impossible.

### 5.2 The interface

```
translate( source payload, context ) -> provider result
```

The **context** carries everything a result must be checked against: source
identity, stage, source digest, language pair, requested fields, content type,
B1/B2 mode, run id, and a `request_identity`.

The **result** distinguishes `success`, `retryable_failure`,
`permanent_failure`, and — the headline rule — **anything else is
`unknown_response`, a hard record failure**.

### 5.3 Fail-closed validation

Every one of these is refused, with no partial acceptance, each asserted
individually:

| Refused | Why it matters |
|---|---|
| missing translated field | a partial response would publish a record whose other fields silently kept stale English |
| wrong field type | a translation must be a string |
| missing / mismatched source identity | a result that cannot be tied to its request is junk |
| mismatched source digest | the single most important check |
| unexpected target language | including a target equal to the source |
| any extra key | the allowlist is an allowlist |
| any **mutation instruction** (`wp_insert_post`, `update_post_meta`, `pll_set_post_language`, `post_status`, `trash`, `delete`, `mode`, `dry_run`…) | a provider that describes a WRITE is not a translation provider; honouring it would be an injection |
| malformed JSON / non-array / empty response | not a translation |
| unknown status | no default status, no "maybe later" coercion |
| a self-asserted `result_digest` that disagrees | the validator computes the result digest itself, so a provider cannot grade its own homework |

### 5.4 Determinism and idempotence

The contract deliberately does **not** require identical provider wording; a
future provider may be non-deterministic. What it requires is a deterministic
**request identity**, derived from the source state and the translation
configuration with the run id excluded — so it is a property of the *work*, not
of the moment it was asked.

Tested consequences:

| Situation | Request identity | Result digest |
|---|---|---|
| same source, different run id | **same** | same if translations match |
| same source, provider reworded | **same** | **different** |
| changed source digest | different | different |
| changed stage / changed field set | different | different |

That is precisely how the layer can later recognise "nothing changed, do
nothing" and avoid an unnecessary WordPress write.

### 5.5 A provider result is never a mutation

There is always: `provider result → validation → translation plan → existing
engine lifecycle → dry-run → gate → snapshot → approval → apply`. No code path
in this plugin turns a provider result into a write; the structural gate asserts
the plugin contains **no** WordPress content write at all.

---

## 6. Trigger model

### 6.1 What was chosen, and why

**Explicit in-process invocation, plus the hook marker. No cron.**

Stage 2 blocker B2 was "WordPress.com cron behaviour under pv is unverified".
Stage 3 resolved it by **removing the dependency**, not by trusting the
scheduler: because reconciliation truth is the full inventory diff, pv-cron
reliability is no longer on the critical path.

| Layer | Decides |
|---|---|
| **Wake-up trigger** | only *whether to attempt a reconciliation* |
| **Reconciliation truth** (full inventory diff) | *whether anything actually changed* |

Consequences, all tested:

- a **missed** wake-up is recovered by the next reconciliation, because the
  diff reads the whole current inventory;
- a **duplicate** wake-up is harmless, because a second diff of an unchanged
  inventory yields an empty change set, and the site-wide lock refuses an
  overlapping run regardless.

**Nothing is scheduled.** `wp_schedule_event`, `wp_schedule_single_event`,
`wp_next_scheduled`, `wp_unschedule_event` and `cron_schedules` are all absent,
asserted in the PHP suite, the promotion suite, the trigger suite and the static
gate.

### 6.2 Hooks are wake-up hints, nothing more

A hook may set **one boolean**. It may not generate a translation, mutate EN,
call the provider, enter apply, read or bypass the inventory, or touch the
lock. Registered on `save_post`, `before_delete_post`, `wp_trash_post`,
`untrashed_post` and `set_object_terms`; filtered down to watched post types
with a **provable `pt`** language, so an EN save — including one this program
itself performs — never marks.

Tested: fires once, twice, rapidly, unrelated post type, EN mutation, shared
county/town taxonomy, B2 taxonomy, during an active lock, and a hook writing no
audit record (it never starts a run).

### 6.3 What the trigger cannot do

| Refused | Asserted |
|---|---|
| a stage that is not allowlisted (incl. `job`, wrong case, wrong separator) | `unknown_stage` |
| an unrecognised or missing environment | `invalid_request` |
| firing while the lock is held | `locked` — refused, not queued, and the holder's lock is untouched |
| corrupt / incompatible / unexpected-stage / invalid persisted state | hard `source_state_unusable` |
| asking for apply (`mode => apply`, `apply => true`, `dry_run => false`, `production_authorized => true`) | still `mutation_permitted = false` |

The orchestrator is invoked from **exactly one place** in the whole plugin — the
trigger — and always in `MODE_PROOF`. The trigger's source does not contain the
string `MODE_APPLY` at all. The lock is released in a `finally` block on every
path.

### 6.4 What remains unproven about the trigger

The trigger model is proven **architecturally and in-process**. What is NOT
proven is production behaviour, because Stage 3 deliberately created **no
production entry point**: no admin screen, no REST route, no cron. Reaching the
trigger in production requires a deliberate operator-authorised stage that adds
one, with `manage_options` **and** a nonce, following
`Conexao_Translation_Rollout_Admin`.

---

## 7. Audit persistence

### 7.1 Storage and the existing contract

`wp_options`, `conexao_translation_automation_audit`, `autoload = false`. No
logging plugin, no external service, no file: production is WordPress.com, and a
third-party logger would add a vendor, a schema and a second retention policy
to a program that deliberately has one of each.

It persists Stage 2's existing
`Conexao_Translation_Automation_Result::to_array()` contract — no parallel log
format was invented — plus the Stage 3 facts.

Recorded: `run_id`, `trigger`, `stage`, `environment`, `status`, `failure`,
`start_state`, `end_state`, `source_inventory_digest`, `change_set_digest`,
`manual_intervention_rows`, `manifest_digest`, `plan_digest`,
`snapshot_digest`, `config_identity`, `approval`, `lock_status`,
`dry_run_status`, `gate`, `apply_permitted`, `mutation_permitted`,
`mutation_occurred`, `provider_status`, `started_at`, `recorded_at`.

### 7.2 No secrets, enforced

`Conexao_Translation_Automation_Result::assert_no_secrets()` is called **on the
write path**. A record carrying a credential-shaped key *or* a credential-shaped
value is **refused**, and the refusal is visible rather than the record being
silently dropped. Tested with six credential keys and an application-password
shaped value under a benign key.

### 7.3 Retention

| Rule | Value | Reasoning |
|---|---|---|
| source-state revisions | **1** | only the last accepted state; there is nothing to accumulate |
| audit records | **20**, oldest pruned first | enough to cover a review cycle without unbounded growth |
| successful vs failed | **treated identically** | a failure record is at least as useful as a success; pruning failures to make room would hide the interesting case |
| compaction | none | records are already small and fixed-shape; compaction would add a way to lose information |
| engine report body / provider payload | never retained | only digests and statuses, so no authored content enters the log |

Tested: writing 35 records leaves exactly 20, the oldest are dropped, the
newest survives, and the bound holds across a storage round-trip.

### 7.4 Corruption

A corrupt trail reads as **empty** rather than throwing — an unreadable log
must not be able to stop a run — but `read_state()` still **reports** it as not
ok with a reason. A malformed entry is dropped, not fatal.

---

## 8. Safety invariants

**The shared engine remains the only mutation authority.** No component can
bypass `lock → dry-run → gate → snapshot → approval → apply`:

| Bypass attempt | Why it cannot work |
|---|---|
| hook → translation | a hook's only write is one boolean in one option; the hooks file contains no orchestrator call, no engine call and no WordPress write |
| trigger → apply | the trigger invokes the orchestrator in `MODE_PROOF` only, and its source never names `MODE_APPLY`; `Orchestrator::run()` itself forces `dry_run => true` on that branch |
| provider → WordPress | the plugin contains **zero** WordPress content writes; a provider result is data, never a plan or a mutation |
| corrupt state → mass translation | four distinct hard-failure statuses, each with `actionable = 0` and `mutation_occurred = false` |
| missing state → mass translation | `bootstrap_required`, `actionable = 0`, and **no baseline is written as a side effect** |
| concurrent run | the site-wide Stage 2 lock is acquired before the inventory is read; a held lock is refused, not queued, and a refused run never releases another's lock |
| registered-but-unapproved stage | the explicit allowlist plus the registry-consistency assertion; persisted state naming a non-allowlisted stage is a hard failure |

### 8.1 No second translation engine

Asserted in three places (PHP suite, promotion suite, static gate):

- no manifest builder and no plan builder outside the engine;
- no redeclaration of the engine class and no reuse of its plan vocabulary;
- the orchestrator is invoked from exactly **one** place;
- every class the plugin declares is in a closed, asserted list of 14.

---

## 9. Evidence

| Question | Finding | Evidence | Status |
|---|---|---|---|
| Is the PT change-detection contract documented and deterministic? | Yes; per-stage profiles cross-checked against the live registry | `test-automation-change-detection.php` | VERIFIED (local) |
| Is the field list derived from the real stages? | Yes; B1 from the authored EN row's mirrored fields, B2 from the stage's own drift-guard field | `assert_profile_matches_config()` | VERIFIED (local) |
| Does a non-translation change leave the digest alone? | Yes; EN meta value, county/town, excluded PT fields | change-detection suite, live PT record | VERIFIED (local) |
| Can missing state trigger mass translation? | No; `bootstrap_required`, `actionable = 0`, no baseline written | trigger suite | VERIFIED (local) |
| Does corrupt state escalate to translation? | No; four distinct hard failures | trigger suite | VERIFIED (local) |
| Can a hook reach translation or apply? | No; one boolean, nothing else | hooks suite | VERIFIED (local) |
| Is a missed hook recoverable? | Yes; the diff finds the change with no marker set | hooks suite | VERIFIED (local) |
| Is a duplicate trigger harmless? | Yes; identical change set, no work | trigger suite | VERIFIED (local) |
| Is there a provider implementation? | **No**; interface only, implemented by nobody | provider + promotion + static gate | VERIFIED (local) |
| Does an unknown provider answer fail closed? | Yes; `unknown_response`, no translations | provider suite | VERIFIED (local) |
| Can a provider issue a WordPress write? | No; zero content writes in the plugin | trigger + promotion + static gate | VERIFIED (local) |
| Is the request identity deterministic? | Yes; stable across runs, changes with source/stage/fields | provider suite | VERIFIED (local) |
| Does the trigger bypass the lock? | No; refused, holder's lock untouched | trigger suite | VERIFIED (local) |
| Can the trigger reach apply? | No; PROOF only, `MODE_APPLY` absent from its source | trigger suite | VERIFIED (local) |
| Is the audit persisted safely? | Yes; required fields preserved, secrets refused on write | audit suite | VERIFIED (local) |
| Is retention bounded? | Yes; 20 records, oldest first, success and failure alike | audit suite | VERIFIED (local) |
| Does the plugin introduce an unscoped cache key? | No; it uses **options**, never transients | cache-scoping gate, source grep | VERIFIED (local) |
| Is there still no cron? | Yes | promotion, trigger, static gate | VERIFIED (local) |
| Is there still no second engine? | Yes | trigger, promotion, static gate | VERIFIED (local) |
| Is the engine byte-identical? | Yes | SHA-256, 4 assertion sites | VERIFIED (local) |
| Are production artifacts installed? | No | §12 | **REQUIRES OPERATOR ACTION** |
| Was production verified? | Not attempted; unauthorised | §12 | NOT PERFORMED |
| Does the trigger work in production? | Unknown; no production entry point exists | §6.4, §12 | **REQUIRES OPERATOR ACTION** |

---

## 10. Tests

```
$ ./scripts/run-tests.sh
In-process PHP suites: 72 total, 71 passed, 1 failed
Assertions: 5084 passed, 1 failed
Script-contract suites: 8 total, 7 passed, 1 failed
HTTP acceptance suites: 3 total, 3 passed, 0 failed
```

Every Stage 3 suite passes. The **two** failures are both pre-existing and
neither is a Stage 3 regression:

- `tests/scripts/verify-i18n-freshness.py` — the 3 stale catalogues (§11.1),
  unchanged from Stage 1 and Stage 2;
- `theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` — local install data
  (§11.3), proven reproducible with all Stage 3 changes stashed.

Stage 3 suites (all passing):

| Suite | Assertions |
|---|---|
| `test-automation-change-detection.php` | 108 |
| `test-automation-provider.php` | 222 |
| `test-automation-trigger.php` | 98 |
| `test-automation-audit.php` | 80 |
| `test-automation-hooks.php` | 50 |
| `tests/scripts/verify-stage3-automation.py` | 116 |

Updated Stage 2 suites (still passing):

| Suite | Assertions |
|---|---|
| `test-automation-boundary.php` | 141 (option allowlist extended to 5 namespaced options) |
| `test-automation-promotion.php` | 12 (class list 5 → 14; provider assertion narrowed) |

```
$ ./scripts/lint.sh
OK: no PHP parse errors.
OK: no new PHPCS violations against the baseline.
PHPStan: [OK] No errors
lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
```

```
$ php scripts/generate-registry-docs.php --check
registry OK: 15 plugins validated, 24 generated regions current (zero writes).

$ python3 tests/scripts/verify-release-integrity.py
229 passed, 0 failed
$ python3 tests/scripts/verify-stage2-promotion.py
28 passed, 0 failed
$ python3 tests/scripts/verify-stage3-automation.py
116 passed, 0 failed
$ python3 tests/scripts/verify-cache-key-scoping.py
2 passed, 0 failed
```

### 10.1 Two defects the tests found, and fixed

Recorded because a green suite that never failed is not evidence:

1. **A restored record was reported `unchanged`.** The digest comparison ran
   before the tombstone check, so a record that was absent and came back
   byte-identical was silently swallowed. Fixed by deciding `restored` on
   lifecycle first.
2. **A baseline containing an `absent` row could never be written.** The state
   writer required a 64-hex digest on every row, but an absent row correctly has
   none — so `bootstrap` failed on any site where a manifest names a missing
   record. Fixed with an explicit `present`/`absent` row contract.

Both were caught by the local suites, not by inspection.

## 11. Existing baseline conditions

### 11.1 `i18n_freshness` — still the one failing gate

Unchanged from Stage 1 and Stage 2: 3 stale catalogues
(`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`). The
catalogues were **deliberately not regenerated** to make the run green; the
correct fix is `./scripts/i18n-make-pot.sh`, which is out of scope.

**Stage 3 did not alter catalogue-generation behaviour.** The Stage 3 plugin
adds no translatable strings: it declares no `__()`, no `esc_html__()` and no
text-domain usage, and it is absent from the `i18n_freshness` scan's scope. The
failure count is identical before and after.

### 11.2 The cache-scoping admin exemption — unchanged, and Stage 3 does not interact with it

The approved conditional exemption for
`set_transient( 'conexao_rollout_report_' . get_current_user_id(), … )` is
**untouched**. The exemption, the gate and the engine bytes are all as Stage 2
left them.

Stage 3 introduces **no cache key at all**: every new piece of state is a
`wp_options` row, and the plugin contains zero occurrences of `set_transient`,
`get_transient`, `wp_cache_set` or `wp_cache_get`. This was a deliberate
choice, not an accident — options are outside the gate's key space, but
choosing them also keeps the new state out of the exemption's neighbourhood
entirely. The gate still passes (`2 passed, 0 failed`), and a bare
`conexao_rollout_report_` key would still fail it.

Recorded for a later, dedicated engine-change stage.

### 11.3 A pre-existing local-data test failure, **not** a Stage 3 regression

`theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` reports
`38 passed, 1 failed` in the current local database: the paired-EN branch
expects the real linked EN newsletter record (id 509) to hold the shared slug
`newsletter`, but it holds `newsletter-2`, so the resolver correctly does not
bind.

This was verified **not** to be caused by Stage 3: with all Stage 3 changes
stashed (`git stash push -u`) and the container restarted, the suite reproduces
the identical failure. It is local install state, and it was recorded rather
than "fixed" by mutating the database or editing the test.

### 11.4 Production installation and trigger verification

**Not performed and not authorised.** Stage 3 built and locally verified a
production plugin; it did not install it, activate it, or create any production
option, route or cron.

---

## 12. Production

**No production state exists from this stage.** Production was never contacted.

Nothing was uploaded, installed, activated or scheduled. No production option,
route, cron job, content, term, menu or Polylang relationship was created or
changed. No translation was performed and no provider was configured.

At the end of Stage 3 the production system, once installed, remains **unable
to translate anything autonomously**, because:

1. there is **no provider implementation** at all;
2. there is **no HTTP entry point** — no admin screen, no REST route, no AJAX;
3. there is **no cron** — nothing fires unattended;
4. the only caller of the orchestrator is the trigger, which uses `MODE_PROOF`.

`conexao-translation-rollout` and `conexao-translation-automation` are already
classified `platform` / `production: true` / `build: true` in `plugins.json`,
unchanged by Stage 3, so no registry change was required and
`generate-registry-docs.php --check` reports zero drift.

If a production installation is separately authorised, it may verify **only**
plugin activation, persistence initialisation, read-only detector operation,
read-only inventory/digest comparison, trigger registration/state, audit storage
initialisation, absence of unexpected cron/routes, and that no PT/EN record
changed.

---

## 13. Remaining conditions

Only genuine blockers, none of them a defect in this stage:

1. **Production installation is unperformed.** Requires explicit operator
   authorisation. The repository tooling deliberately cannot deploy.
2. **The trigger has no production entry point.** The model is proven
   architecturally and in-process, but production reachability requires a
   deliberate, nonce-carrying admin path that Stage 3 was not authorised to
   build.
3. **The Stage 2 admin-scope cache exemption is still open** (§11.2). It
   belongs to a stage permitted to change the engine's bytes.
4. **`i18n_freshness` still fails** on 3 pre-existing stale catalogues (§11.1).
5. **The pre-existing local-data failure in
   `test-en-jobs-shared-slug.php`** (§11.3) is unresolved; it is not Stage 3's
   to fix and was not masked.

---

## 14. Prohibited until Stage 4

Explicitly **not** done, and not reachable by configuration alone:

- introducing a real provider implementation, vendor, model or API key;
- making a real translation call of any kind;
- performing an autonomous production `apply`;
- creating or updating production EN records through this plugin;
- modifying PT canonical content, menus, Polylang settings or production
  taxonomy through this plugin;
- creating a production cron job;
- adding an admin/REST entry point that could reach the trigger;
- bypassing or re-implementing the shared engine's lifecycle.

---

## 15. Stage 4 prerequisites

Concrete requirements to enable a real provider and controlled translation:

1. **A provider implementation** satisfying
   `Conexao_Translation_Automation_Provider_Interface`, with the credential
   sourced from the environment only — never from the database, an option or a
   manifest (Stage 0 control S7).
2. **A re-baselined production state** before any comparison is trusted
   (§20 of the brief): a fresh PT inventory digest, a fresh EN/B1/B2 baseline,
   current plugin, route, cron/event and Polylang state, with the known
   six-authorised-restoration difference documented rather than silently
   absorbed.
3. **A deliberate production trigger**, with `manage_options` **and** a nonce,
   following `Conexao_Translation_Rollout_Admin`, configured as
   **observation / dry-run only**.
4. **A translation-plan stage** between a validated provider result and the
   engine: converting validated translations into manifest rows, with its own
   review surface. Today a provider result goes no further than the validator.
5. **A deletion policy decision** for PT deletions, or an explicit, permanent
   `manual_intervention_required`. Stage 3 deliberately has none.
6. **Quality review placement** — a human reading the dry-run plan between the
   gate and the approval. The validator checks shape and identity; it
   deliberately does not judge English.
7. **An authorised apply trigger** built on the Stage 2 F7 chain, which must
   remain unreachable until the operator approves that specific plan.
8. **Verification that the audit retention bound suits production volume**, and
   that option size stays acceptable with a real baseline.

---

_Evidence: `docs/evidence/2026-09-30-stage-3-change-detection-provider-trigger/`_

_Last verified: 2026-09-30 by Stage 3 — change detection, provider boundary, trigger and audit_
