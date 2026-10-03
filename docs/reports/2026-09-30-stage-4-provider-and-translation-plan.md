# Report — Stage 4: translation provider and translation-plan adapter

> **This stage changed no production state.**
> `Production writes: 0`. Production was not contacted at all. No plugin was
> uploaded, installed, activated or scheduled. No production route, cron job,
> webhook or option was created. No PT or EN content, taxonomy term, menu or
> Polylang relationship was changed. **No production provider call was made and
> no production translation was produced.** The Flutter/mobile repository was
> not accessed.
>
> **Production autonomous mutation remains OFF.** There is still no cron, no
> REST route, no admin screen and no unauthenticated route. The provider is
> reachable only from an in-process PHP call, and everything it produces lands
> in a plan whose review state is `REVIEW_REQUIRED` and can never become an
> apply.

| | |
|---|---|
| **Stage / task name** | Stage 4 — translation provider implementation and translation-plan adapter |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `078b2d8cf111a4b5d83da83cf9bee47caa158621` |
| **Predecessor** | Stage 3 — PASS WITH CONDITIONS |
| **Status** | **PASS WITH CONDITIONS** — see §15 |

---

## 1. Stage 4 status

**PASS WITH CONDITIONS.**

A real provider implementation exists, its credential is environment-only, its
requests are bound to the PT source digest, its responses are strictly
validated by the unchanged Stage 3 validator, retries and cost are bounded, a
changed PT source invalidates a result, B1 and B2 plans are represented
correctly, and the plan layer cannot reach WordPress at all. The adapter feeds
the **existing** engine through its injected `manifest_callback`; the engine
file is byte-identical to its Stage 1 baseline.

The conditions are the same operator-dependent facts as Stage 3, plus one
genuine Stage 4 addition. None is a defect in this stage's work:

1. **Production installation is still unperformed and was not authorised.**
2. **The trigger still has no production entry point** — deliberately; Stage 4
   did not create one.
3. **The Stage 2 admin-scope cache exemption remains open**, untouched.
4. **A live provider call was never made.** No credential exists in this
   environment, so the provider's real HTTP path is unexercised. The live smoke
   test exists, is gated, and skips cleanly (§10).
5. **`i18n_freshness` and three local-data suites still fail**, unchanged and
   proven pre-existing by a controlled experiment (§17).

---

## 2. Provider

| | |
|---|---|
| **Provider** | OpenAI |
| **Service** | Chat Completions (`POST /v1/chat/completions`) |
| **Model** | `gpt-4o-mini` |
| **Implementation** | `Conexao_Translation_Automation_Provider_OpenAI` — `wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-provider-openai.php` |
| **Contract** | `Conexao_Translation_Automation_Provider_Interface`, unchanged since Stage 3 |
| **Configuration** | `Conexao_Translation_Automation_Provider_Config` — code constants, never options |

### Why this provider, on this repository's actual requirements

The selection was made against the Stage 3 projection, not against what was
convenient:

| Requirement | How `gpt-4o-mini` meets it | Repository evidence |
|---|---|---|
| **Structured field translation** | `response_format: {type: json_object}` makes the model *return* the exact field map `Provider_Result::validate()` demands, rather than prose that must be scraped | The Stage 3 validator requires a `translations` map keyed by the requested field names and **refuses any extra key** — prose output could not satisfy it |
| **Content length** | 128k-token context; the largest PT body in the B1 guide dataset is measured in the low thousands of tokens | `MAX_REQUEST_BYTES` (240 000) is the hard ceiling, enforced *before* sending |
| **HTML preservation** | The instruction names tags, attributes, URLs, entities and Gutenberg block comments as preserved-verbatim, text nodes as translatable | This repository's PT bodies are Gutenberg-serialised (`<!-- wp:paragraph -->`), so a dropped block comment is a *structural* failure, not a style one |
| **Slug handling** | Not delegated to the provider at all | The provider is never asked for `post_name`; `Plan_Adapter::FORBIDDEN_FIELDS` refuses it |
| **Taxonomy treatment** | Not delegated to the provider at all | The provider receives no term vocabulary; terms remain the stage's own `taxonomy_callback` business |
| **Metadata handling** | Only the requested projection fields are sent | The Stage 3 projection already excludes lock state, audit ids, run ids, the EN meta key a B2 stage owns, and the language-neutral fields the engine copies |
| **Retry behaviour** | The HTTP status is a contract: `408/409/425/429/5xx` retryable, other `4xx` permanent, everything else unknown | Stage 3's `RETRYABLE_STATUSES` + `unknown → hard failure` |
| **Deterministic request identity** | Computed by *this repository* from the Stage 3 digest — never read from a vendor field | A provider that echoes nothing back is still bindable; identity is a property of the work, not of the moment |

### Known limits relevant to this repository

- **HTML fidelity is instruction-based, not mechanical.** A model can still drop
  a tag. The mitigation is layered: an explicit instruction, a structural
  validator, and **every plan requiring human review**. A structural HTML differ
  is deliberately *not* implemented in Stage 4 — it would be a quality gate
  wearing a structural costume, and the human gate is the honest place for it.
- **Non-determinism.** The contract requires a deterministic *request identity*
  and a deterministic *result digest*, not identical wording. `temperature` is
  `0` to reduce variance, not to promise it.
- **Token-based billing.** Bounded by `MAX_REQUESTS_PER_RUN` and
  `MAX_REQUEST_BYTES`; a run that would exceed either fails closed.
- **No vendor SDK.** `wp_remote_post` only: adding a Composer dependency to a
  WordPress.com plugin is a new supply-chain surface this stage was not
  authorised to create.
- **Single-provider registry.** A second provider is a documented choice with
  its own limits, not a fallback. There is no default substitution.

### Timeout, retry and error policy

| Policy | Value |
|---|---|
| Per-request timeout | 60 s |
| Max attempts per record | 4 (i.e. 3 retries) |
| Backoff | 500 ms base, doubled, capped at 4 000 ms |
| Total run budget | 600 s |
| Max requests per run | 50 |
| Max request size | 240 000 bytes |

**Never retried:** malformed responses, invalid identity, wrong language, schema
violations, unknown statuses, and **authentication failures** — the provider's
own contract does not classify re-authentication as safe, so neither does this
repository. A wrong key stays wrong.

---

## 3. Credentials

**Environment only. Never persisted. Never printed.**

| Property | Evidence |
|---|---|
| Storage model | `getenv( 'CONEXAO_TRANSLATION_PROVIDER_KEY' )`, read in `Provider_Config::credentials()` and nowhere else |
| In `wp_options`? | **No.** The plugin writes no option; the new files contain no `update_option`/`add_option` (gate + test) |
| In the database? | **No** |
| In plugin source? | **No.** The variable is *named*; no value is embedded. The gate asserts no `sk-` literal in any file |
| In `plugins.json` / manifests / snapshots? | **No** — `plugins.json` unchanged |
| In audit records, plans, logs, fixtures, docs? | **No** — `assert_no_secrets()` runs on the plan artifact; the provider suite asserts the credential appears in *no* observable output |
| Missing credential | **Fails closed**: `conexao_automation_provider_no_credential`, before any request is assembled |
| Blank credential | **Fails closed** — never trimmed into an empty bearer |

The credential *is* sent, as a bearer header, because that is the vendor
contract. The suite proves it reaches the header and **never** the request body
and **never** any return value.

One naming note: the configuration key is `required_env_var`, not
`credential_env`, because the repository's own `assert_no_secrets()` refuses any
key containing `credential`. The first draft tripped the secret scanner on the
configuration boundary itself — the scanner was right to.

---

## 4. Translation plan

**Exact transformation: validated provider result → reviewable plan row.**

The plan is **data**. It does not build a manifest, does not execute, does not
write, and does not decide lifecycle semantics.

| Plan row field | Source |
|---|---|
| `source_id` | the context `identity` — the portable PT slug, never a post ID |
| `source_title` | the context title, for the reviewer |
| `stage`, `mode`, `post_type` | the context |
| `operation` | derived from the Stage 3 change type (`new`→create, `modified`→update, `restored`→reconcile; **B2 always** `write_owned_en_field`) |
| `changed_fields` | the exact keys of the validated translation map |
| `translations` | the validated strings |
| `source_digest` / `result_digest` / `request_identity` | Stage 3's meta |
| `provider` / `model` / `provider_request_id` | Stage 3's meta |
| `validation_status` | `validated` |
| `review_status` | `REVIEW_REQUIRED` |
| `quality_status` | `human_review_required` |

**`summary()` carries no translated content** — it is safe for a log or a commit
message. **`to_review_artifact()` carries the translations** — because a
reviewer cannot judge English from field names — and is refused outright if it
carries anything secret-shaped.


---

## 5. B1 / B2 handling

| | B1 (`en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page`) | B2 (`en-leisure-description`, `en-course-provider-description`) |
|---|---|---|
| Provider supplies | `post_title`, `post_content`, `post_excerpt`, `conexao_meta_description` | `post_excerpt` only |
| Lands in manifest as | `en_title`, `en_content`, `en_excerpt`, `en_meta_description` | `en_description` |
| Identity | a linked EN record; the engine creates or updates it | **the same PT record** — no second identity |
| Operation stated | create / update / repair | `write_owned_en_field`, always |
| EN slug | the **authored** one; the provider never mints it | the authored PT key; this stage mints no URL |
| Relationship | created by the engine's adapter | none — a deliberate no-op in the stage |
| Scope guard | a field the stage's manifest row does not declare is **refused** | **any** field other than `post_excerpt` is **refused** |

The B2 guard was added because the first implementation accepted `post_title` on
a B2 stage — it would have written `en_title` into a row whose B2 adapter never
reads it, silently changing a record with no visible effect. That is now a hard
refusal naming the one field the stage owns.

### A real discovery: the whole-stage gate is FAIL on local data, and that is correct

Running the dry run over the **whole** B2 stage returns `gate: FAIL` with
`missing_en: 1, conflicts: 1`. The cause is one local record,
`dwyer-mcallister-cottage`, whose PT excerpt reads *"Descrição alterada depois de
a tradução ter sido escrita"* — it was deliberately edited after its English was
authored, precisely to exercise the stage's own PT-drift guard. The engine
correctly refuses to write a stale translation and fails the numeric gate.

This is the **engine and the stage working as designed**, not a Stage 4 defect.
It is reported rather than hidden, and the test suite handles it honestly: the
whole-stage run is kept and its gate is surfaced verbatim without interpretation,
while a second run **scoped to a single non-drifted record** demonstrates the
clean `PASS` path. Scoping changes what the engine is asked about, never how it
answers.

---

## 6. Review gate

```
validated provider result  →  REVIEW_REQUIRED  →  [a human]  →  (future stage) APPROVED → apply
```

- **Every** plan begins `REVIEW_REQUIRED`. There is no code path to any other
  value.
- The plan class declares **no** `APPROVED`/`PUBLISHED`/`APPLY` constant, and
  offers **no** `approve()` or `set_review_status()` method — asserted by
  reflection *and* by a source scan.
- A valid provider response means **structurally acceptable**, not *publication
  approved*. `quality_status` says so on every row.
- The plan and adapter name no apply mode (`MODE_APPLY`, `'apply'`,
  `Apply_Gate` are absent) — asserted structurally.

---

## 7. Safety — why provider output cannot mutate WordPress

Five independent properties, each asserted:

1. **The provider is data-only.** It returns an array. No `wp_insert_post`,
   `wp_update_post`, `update_post_meta`, `wp_insert_term`, `pll_*` — asserted
   for the file by the gate and by the test suite.
2. **The plan layer writes nothing.** No option, no transient, no meta, no
   Polylang relationship, and it does not even call `register_stage()`.
3. **The provider cannot self-certify.** It never calls
   `Provider_Result::validate()` — the Stage 3 validator remains the trust
   boundary, and a forged result digest is caught (asserted).
4. **The plan is inert.** No apply mode, no approval method, no engine
   vocabulary. It names what *would* change and stops.
5. **The only route to content is the existing engine**, behind the Stage 2 F7
   chain, and Stage 4 does not reach it: the only engine call in the tests is
   `dry_run => true`.

A deletion policy was **not** invented: a PT deletion remains
`manual_intervention_required` from Stage 3, untouched.

---

## 8. Engine integration

**Entry point: `Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) )`,
reached with a stage configuration whose `manifest_callback` the adapter
composed.**

The engine takes its manifest from an **injected callable**. That is the whole
integration, and it required **no engine change**:

```
provider result (validated)
  → Plan row (REVIEW_REQUIRED)
  → Plan_Adapter::compose()        stage's OWN manifest + provider English
  → Plan_Adapter::engine_config()  engine config with that manifest injected
  → Engine::run( …, dry_run => true )   ← the engine plans, gates, verifies
```

The adapter composes a manifest and delegates. It does **not** build a plan,
count, snapshot or compute a gate — the `create/update/skip/conflicts`
categories and the numeric verdict all come from the engine, proven by asserting
they exist in the engine's own report.

**Stale-source protection** sits at the boundary: before a row is allowed into
the composed manifest, the live PT digest is re-read and compared with the
digest the plan was built from. A mismatch, *or a digest that cannot be read at
all*, is refused — unreadable is not "unchanged". The stale translation never
reaches the manifest, so the engine never sees it.

---

## 9. Engine integrity

| | SHA-256 |
|---|---|
| **Expected (Stage 1 baseline)** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **Before Stage 4** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |
| **After Stage 4** | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` |

**Exact equality.** `git diff --stat wp-content/plugins/conexao-translation-rollout/`
is **empty**: no file in the engine plugin was added, modified or deleted.


---

## 10. Validation, and what it does NOT prove

The **Stage 3 validator is unchanged and remains mandatory**. The provider
implementation returns only through `Provider_Result::validate()`; it never
calls the validator itself, so a provider can never grade its own homework.

Validated on every response: schema, source identity, source digest, source and
target language, requested field set, per-field type, completeness (a partial
response is refused **as a whole**), allowed keys, absence of mutation
instructions, and the result digest — **computed by the validator**, and a
provider-asserted digest that disagrees is refused.

**What validation deliberately does NOT do:** judge English quality. A schema
validator does not prove good English, and pretending otherwise would be worse
than not checking. `structurally acceptable` is recorded as exactly that, and
`quality_status: human_review_required` travels on every row.

### Failure semantics

| Category | Trigger | Reaction |
|---|---|---|
| **retryable** | `408/409/425/429/5xx`, or a transport timeout | retried up to the ceiling; exhaustion is a hard failure |
| **permanent** | other `4xx`, including `401`/`403` | one attempt, no retry, needs a human |
| **invalid response** | a `2xx` that is not a usable payload (empty, malformed, truncated, prose, no choices) | no retry, no partial translation |
| **unknown** | any unrecognised status | **hard failure**, never a retry |

**No provider failure ever produces a partial translation plan.** A record
either has a validated translation or has none.

---

## 11. Dry-run only

Every provider-backed integration test runs the documented path and stops:

```
lock → inventory → diff → provider → validate → translation plan → dry-run → gate
```

No production apply. The plan is never treated as approval. The dry run is
proven non-mutating against **live state**, not by assertion: the PT record's
meta, title, content, slug and status are captured before the engine runs and
compared after.

---

## 12. Files added / modified

**6 added, 6 modified, 0 deleted.** All `wp-content/` changes are inside
`conexao-translation-automation`; the engine plugin is untouched.

| Path | Change | Note |
|---|---|---|
| `…-provider-config.php` | **added** | Non-secret configuration + env-only credential; fails closed |
| `…-provider-openai.php` | **added** | The provider implementation; the only file that makes a request |
| `…-translation-plan.php` | **added** | The reviewable plan; `REVIEW_REQUIRED`, no approval state |
| `…-plan-adapter.php` | **added** | Provider data → engine-compatible manifest; no writes |
| `tests/test-automation-provider-implementation.php` | **added** | 144 assertions: config, request, credentials, errors, retries, cost |
| `tests/test-automation-translation-plan.php` | **added** | 145 assertions: plan, adapter, review, stale source, engine, safety |
| `tests/live-provider-smoke.php` | **added** | Optional, gated, skipped cleanly; not suite-discovered |
| `tests/scripts/verify-stage4-provider.py` | **added** | 110-check structural gate |
| `conexao-translation-automation.php` | modified | Load order, description, version 0.1.0 → 0.2.0 |
| `tests/test-automation-provider.php` | modified | "nobody implements the contract" → "exactly one does" |
| `tests/test-automation-promotion.php` | modified | Outbound call + vendor confined to the designated file |
| `tests/test-automation-trigger.php` | modified | Same narrowing; the trigger itself still makes no call |
| `tests/test-automation-boundary.php` | modified | Version constant 0.1.0 → 0.2.0 |
| `tests/scripts/verify-stage3-automation.py` | modified | The one superseded assertion, narrowed (see §14) |

**No change to:** `conexao-translation-rollout` (any file), `plugins.json`,
`dist/release.json`, themes, or any Flutter/mobile file.


---

## 13. Runtime, content and Polylang impact

| Question | Answer | How it was verified |
|---|---|---|
| Does WordPress behave differently? | **No.** The four new files only *declare* classes. No hook, no cron, no route, no option write. The only load-time side effect remains `Hooks::register()` from Stage 3 | Gate asserts no `wp_schedule_*`, `register_rest_route`, `rest_api_init`, `admin_post_`, `wp_ajax_`, `admin_menu`, `update_option` in the new files |
| Content created / updated / deleted | **None** | `git status` shows no content change; the dry run is proven zero-write against live state |
| PT canonical content | **Unchanged** | Title, content, excerpt, slug and status compared before/after the dry run |
| EN records created | **None** | B1 plans describe the operation; the engine's dry-run plan contains no applied create |
| Polylang relationships | **Unchanged** | The adapter contains no `pll_*` call; the engine's `link_pair` is only reached on apply, which Stage 4 never performs |
| Taxonomy | **Unchanged** | No `wp_insert_term` / `wp_set_object_terms`; translated terms remain the stage's own `taxonomy_callback` |
| Completeness gate | Engine-reported and surfaced verbatim; **no** new content, so the number is unchanged | The engine's `PASS`/`FAIL` is passed through, not recomputed |

---

## 14. The one superseded Stage 3 assertion

Stage 3 proved, among other things, that **no provider implementation existed**:
no outbound call, no vendor reference, no class implementing the interface.
Stage 4 was explicitly authorised to add exactly one provider, so that specific
premise became false by design.

It was replaced by a **narrower and stronger** assertion, not deleted:

| Was (Stage 3) | Is (Stage 4) |
|---|---|
| *no file makes an outbound request* | *exactly one file makes an outbound request, and it is the designated provider* |
| *no vendor reference anywhere* | *vendor references confined to the provider and its configuration* |
| *nobody implements the interface* | *exactly one class implements it, and it is the Stage 4 provider* |

**Nothing else was relaxed.** Both gates still assert, for every file including
the new ones: no WordPress content write, no cron, no public route, no second
engine, no plan-builder vocabulary, no credential constant, and the engine's
byte-identical digest. The Stage 3 gate went from 116 checks to **123**; the new
Stage 4 gate adds **110** more.

---

## 15. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh` | 1 | **74 in-process suites, 72 passed, 2 failed**; **5 383 assertions passed, 6 failed**. Script-contract: **9 suites, 8 passed, 1 failed**. HTTP acceptance: **3 suites, 2 passed, 1 failed**. **Every failure is pre-existing and proven so by moving all Stage 4 code aside (§17).** |
| `python3 tests/scripts/verify-stage4-provider.py` | 0 | **110 passed, 0 failed** |
| `python3 tests/scripts/verify-stage3-automation.py` | 0 | **123 passed, 0 failed** |
| `php -l` on all new/changed PHP | 0 | clean |
| `sha256sum …class-conexao-translation-rollout-engine.php` | 0 | `baf85283…a6ce4`, unchanged |
| `git diff --stat wp-content/plugins/conexao-translation-rollout/` | 0 | **empty** |
| `php scripts/generate-registry-docs.php --check` | 0 | **registry OK: 15 plugins validated, 24 generated regions current (zero writes)** |
| `./scripts/lint.sh` | **0** | **lint: OK** — syntax clean, **no new PHPCS violations**, PHPStan level 5 **no errors** |

### New tests

| Suite | Assertions |
|---|---|
| `test-automation-provider-implementation.php` | **144** |
| `test-automation-translation-plan.php` | **157** |

Together **301 new assertions** (144 + 157), all passing. The provider suite is fully
deterministic: the transport is replaced in every case, so it needs **no
network and no credential**.

### Failure proofs — proving the gate can fail

A gate never shown to fail is not a gate. Three real defects were found by these
tests and fixed, each caught by a failing assertion rather than by inspection:

| Defect | Caught by |
|---|---|
| The B2 adapter accepted a B1 field (`post_title` → `en_title`), which would have written a key the B2 stage never reads — a silent no-effect change | the B2 scope-refusal assertion |
| A configuration key named `credential_env` tripped the repository's own `assert_no_secrets()` on the configuration boundary | the configuration secret-check assertion |
| A persistent timeout reported only `attempts_exhausted`, hiding the cause from the operator | the timeout assertion; the message now names the underlying outcome |
| **The plan suite's own PT-immutability assertions were VACUOUS.** They used `get_post( $id, ARRAY_A )` and then read `$array->post_title` — an array property access that yields `null`, so `null === null` passed no matter what the engine did | caught when a full run failed the adjacent `errors` assertion, forcing the real cause to be investigated. The assertions now use the object accessor and additionally cover the excerpt, taxonomy terms and Polylang translations |

The fourth is the most important: an assertion that cannot fail is worse than no
assertion, because it looks like proof. It is now a real one.

The live smoke test was also verified to **skip cleanly** both without
authorisation and without a credential, exiting 0 and making no call.


---

## 16. Production

**Nothing happened in production, and production was never contacted.**

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |
| Provider call against production content | **no** | |
| Production cron / REST / webhook | **no** | none created |
| Credential in production options | **no** | the credential is environment-only by construction |

**Production installation was not authorised for this stage and was not
performed.** The provider implementation is therefore, in practice,
**local/test-only today**. Even once installed, the provider could not translate
anything in production without an explicit in-process invocation, and its output
would still land in a `REVIEW_REQUIRED` plan.

---

## 17. Existing conditions — unchanged and re-confirmed

| Condition | Status | Note |
|---|---|---|
| `i18n_freshness` — 3 pre-existing stale catalogues | **still failing, not masked** | Not regenerated; Stage 4 adds no translatable string |
| `test-en-jobs-shared-slug.php` | **still failing** | Pre-existing local-data condition: a local EN newsletter record resolves to `en_id 509`. Reproduces **identically with every Stage 4 file moved aside** |
| `test-leisure-card-excerpt-language.php` | **still failing** | Pre-existing local-data condition in the same leisure dataset (`dwyer-mcallister-cottage` was deliberately PT-drifted after authoring, so the stage's own PT-drift guard reports `missing_en=1`). Reproduces **identically with all Stage 4 code removed** |
| `verify-routing-http` (acceptance) | **still failing** | The same shared-slug newsletter condition as `test-en-jobs-shared-slug` |
| `test-stage7-hero-sponsors.php` | **intermittent; passed in the final run** | Local-data/order dependent; failed in one baseline run and passed in another. Not masked and not claimed as a stable pass |
| Stage 2 admin-scope cache exemption | **still open, untouched** | Stage 4 introduces **no cache key at all** |
| Production installation / trigger verification | **still requires operator action** | Not simulated |

The pre-change baseline was captured before any edit: **72 suites, 71 passed,
1 failed** (`test-en-jobs-shared-slug.php`), with
`test-stage7-hero-sponsors.php` intermittent.

The post-change run: **74 suites, 72 passed, 2 failed.** Stage 4 added two
passing suites and broke nothing.

### The attribution experiment

To avoid attributing someone else's breakage, **every Stage 4 file was moved
aside** — not stashed — returning the tree to the exact Stage 3 state, and the
three failing suites were re-run:

| Suite | With Stage 4 | At Stage 3 |
|---|---|---|
| `test-en-jobs-shared-slug.php` | 38 pass, 1 fail | 38 pass, 1 fail |
| `test-leisure-card-excerpt-language.php` | 40 pass, 5 fail | 40 pass, 5 fail |
| `verify-routing-http` | 91 pass, 1 fail | 91 pass, 1 fail |

**Identical.** All three are pre-existing local-data conditions. The two
in-process suites read the same leisure dataset, in which one record was
deliberately PT-drifted after its English was authored — so the stage's own
PT-drift guard is *correctly* refusing it. That is the engine and the stage
working exactly as designed, and it is precisely the behaviour the brief
forbids Stage 4 from weakening.

---

## 18. Remaining conditions

Only genuine blockers:

1. **Production installation is unperformed** — requires explicit operator
   authorisation. The repository tooling deliberately cannot deploy.
2. **No production trigger entry point** — proven architecturally and
   in-process; production reachability needs a deliberate, nonce-carrying admin
   path that Stage 4 was not authorised to build.
3. **No live provider call has been made** — no credential exists in this
   environment, so the real `wp_remote_post` path is unexercised. The gated smoke
   test is ready for an operator who has a credential.
4. **Stage 2 admin-scope cache exemption** — open; belongs to a stage permitted
   to change the engine's bytes.
5. **`i18n_freshness`** — 3 stale catalogues, deliberately not regenerated.
6. **Two pre-existing local-data suite failures** — not Stage 4's to fix, not
   masked.

---

## 19. Still prohibited after Stage 4

- applying provider-generated translations anywhere;
- creating or updating EN records through this plugin;
- mutating PT content, menus, Polylang settings or production taxonomy;
- a production cron job, public REST endpoint or webhook;
- storing a provider credential in options, the database, a manifest or source;
- bypassing the Stage 3 validator, or the existing engine;
- a second plan/apply engine;
- an automated deletion policy;
- any change to the Flutter/mobile repository.

---

## 20. Stage 5 prerequisites

Concrete requirements for the first controlled production translation:

1. **Operator authorisation to install** the automation plugin in production.
   Without it, the provider stays local/test-only — the current state.
2. **A production credential delivery mechanism** that is *not* a WordPress
   option. WordPress.com must be able to expose the credential to the runtime
   environment, or the run must be performed from an authenticated external
   context. Storing the key in `wp_options` is **not** an acceptable answer.
3. **A successful live smoke test** on a non-production fixture, proving the real
   `wp_remote_post` path, the structured-output contract and the HTML policy
   against the real vendor.
4. **A re-baselined production state** before any comparison is trusted: a fresh
   PT inventory digest, a fresh EN/B1/B2 baseline, and current plugin, route,
   cron and Polylang state.
5. **A deliberate production trigger** with `manage_options` **and** a nonce,
   following `Conexao_Translation_Rollout_Admin`, configured as
   **observation / dry-run only**.
6. **An approval mechanism** — a human reviewing the plan and recording the
   decision. The `REVIEW_REQUIRED` → approved transition does not exist yet and
   must be a deliberate, gated step, bound to the plan's source and result
   digests so an approved plan cannot be swapped for another.
7. **An authorised apply trigger** built on the Stage 2 F7 chain, unreachable
   until the operator approves that specific plan.
8. **A decision on the audit retention bound** (20 records) at production
   volume, and confirmation that option size stays acceptable.
9. **A deletion policy decision** for PT deletions, or an explicit, permanent
   `manual_intervention_required`.

---

_Evidence: `docs/evidence/2026-09-30-stage-4-provider-and-translation-plan/`_

_Last verified: 2026-09-30 by Stage 4 — translation provider and translation-plan adapter_

| **API authentication** | `Authorization: Bearer`, from the environment only | Stage 0 control S7 |
