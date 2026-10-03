# Stage 15 — Personal-plan production commissioning, Model A canary

| | |
|---|---|
| **Stage / task name** | Stage 15 — First operational production commissioning attempt on WordPress.com **Personal**, Model A exact-scope proof and first canary |
| **Date** | 2026-10-01 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `7c993463c64fd18be9e1e20fbc9358bce75ec7de` (Stage 14 secret-hardening) |
| **Architecture** | `MODEL_A — TRUE SUBSET EXECUTION` (unchanged; engine untouched) |
| **Production changes** | **0** — no install, no activation, no provider call, no mutation |

## Stage 15 status

**`BLOCKED`**

Three independent prerequisites are absent, each of which is individually
sufficient for `BLOCKED` under §43:

1. **Readiness is not `READY`.** The Stage 13 gate, re-run in this
   environment, returns `BLOCKED` (exit 1) — **1 READY / 17 BLOCKED**.
2. **No installation authorization (P02).** Nothing was uploaded.
3. **No Personal-compatible provider credential path exists (Path C).** This
   is the stage's substantive new finding, and it was reached by real
   investigation rather than assumption.

This is deliberately **not** `PASS WITH CONDITIONS`. §43 reserves that status
for a stage where commissioning, baseline, dry-run and the Model A production
scope proof were *genuinely performed* and the only omission is the separately
authorized canary apply. Here commissioning never began, so that claim would
be false.

Stage 14 and the earlier Stage 15 attempt were also `BLOCKED`, but **for a
different reason**, and the difference matters: previously the provider path
had simply not been investigated. Section 4 required investigating Path A
before declaring the provider impossible. **That investigation was performed
and Path A was rejected on evidence.** The blocker is now *understood*, not
merely unmet, and §21 below records what resolving it would require.

## 1. WordPress.com Personal constraints — what was actually available

Capability boundary confirmed from vendor documentation fetched during this
run, not assumed:

| Capability | Personal | Source |
|---|---|---|
| Install marketplace plugins | **available** | pricing: "Install any of over 60,000 plugins … or upload a plugin directly" |
| **Upload a plugin ZIP via wp-admin** | **available** | same — this is exactly §2's deployment model |
| wp-admin plugin management | **available** | — |
| Settings → Connectors (incl. OpenAI) | **available** | wordpress.com/support/connectors |
| SFTP / SSH | **Business/Commerce** | pricing FAQ: "developer tools like SFTP/SSH, WP-CLI, Git commands, and GitHub Deployments" |
| WP-CLI | **Business/Commerce** | same |
| Git / GitHub Deployments | **Business/Commerce** | same |
| Real-time backups / one-click restore | **Business/Commerce/Enterprise** | pricing FAQ |
| Staging, DB/phpMyAdmin, server env vars | **not Personal** | not offered as used here |

**Conclusion: §2's deployment model is fully supported on Personal.** The
stage is *not* blocked by the plan. `WP_USERNAME` /
`WP_APPLICATION_PASSWORD` were correctly **not** treated as a deployment
prerequisite (§3); their absence is recorded, not treated as the blocker.

### Required proofs that are NOT observable through Personal

| Required proof | Status | Why |
|---|---|---|
| Production `410` on `admin_post_conexao_translation_rollout_run` (§12) | **NOT POSITIVELY VERIFIED** | Needs an authenticated admin POST. A manual admin POST *is* a Personal capability, so this is **not** a plan limitation — it is blocked only by the absent authorization/session. |
| Authenticated control-plane state (§13) | **NOT OBSERVABLE without an authenticated session** | Same reason. The anonymously observable half *is* verified clean. |
| **P15 production stop state (§15)** | **`NOT OBSERVABLE THROUGH PERSONAL PLAN`** | **A genuine repository gap, independent of the plan.** The shipped artifact exposes **no read surface** for the emergency stop — see §6. Even a successful manual admin login could not read it today. |

No attempt was made to obtain any of these through SFTP, SSH, WP-CLI,
phpMyAdmin, direct database access, or server filesystem access, and the plan
was not upgraded.

## 2. Readiness — current P01–P18 status

`python3 scripts/verify-commissioning-readiness.py` → **exit 1**. Re-run in
this environment; no prior evidence reused; nothing hand-marked.

| # | Prerequisite | Status | Reason recorded by the gate |
|---|---|---|---|
| P01 | `production_access` | BLOCKED | no `production_access` evidence object supplied |
| P02 | `install_authorization` | BLOCKED | no `install_authorization` evidence object supplied |
| P03 | `provider_credential` | BLOCKED | `CONEXAO_TRANSLATION_PROVIDER_KEY` absent; the unrelated `OPENAI_API_KEY` is present and was **not** used or aliased |
| P04 | `provider_quota` | BLOCKED | no evidence object supplied |
| P05 | `live_smoke_authorization` | BLOCKED | no evidence object supplied |
| P06 | `provider_smoke_success` | BLOCKED | no evidence object supplied |
| P07 | `human_reviewer` | BLOCKED | no evidence object supplied |
| P08 | `canary_authorization` | BLOCKED | no evidence object supplied |
| P09 | `apply_authorization` | BLOCKED | no evidence object supplied |
| P10 | `commissioning_authorization` | BLOCKED | no evidence object supplied |
| P11 | `batch_control_commissioning_authorization` | BLOCKED | no evidence object supplied |
| P12 | `fresh_baseline_readiness` | BLOCKED | no evidence object supplied |
| P13 | `legacy_endpoint_verification` | BLOCKED | no evidence object supplied |
| P14 | `proof_trigger_verification` | BLOCKED | no evidence object supplied |
| P15 | `emergency_stop_readiness` | BLOCKED | no `production_stop_state` evidence object supplied |
| P16 | `audit_readiness` | BLOCKED | no evidence object supplied |
| P17 | `release_readiness` | **READY** | release record, registry and determinism all verified |
| P18 | `model_a_production_proof` | BLOCKED | no evidence object supplied |

**1 READY / 17 BLOCKED.** The single green prerequisite is a property of this
repository, not of production. Gate output: `commissioning_permitted=false`,
`production_mutation_permitted=false`, `canary_selection_allowed=false`,
`emergency_stop_clearance=NOT_GRANTED`, `model_a_production_proof=NOT_PROVEN`,
`provider.key_present=false`, `provider.quota=NOT_PROVEN`,
`provider.requests_from_this_gate=0`.

**The post-installation re-run required by §16 was NOT performed**, because
no installation occurred. The pre-install result above was not reused or
presented as a post-install result.

## 3. Authorization — independently evidenced, none inferred

| Authorization | Prerequisite | Result |
|---|---|---|
| Installation | P02 | **ABSENT** |
| Provider call | P05 | **ABSENT** |
| Canary | P08 | **ABSENT** |
| Canary **apply** | P09 | **ABSENT** |
| Commissioning | P10 | **ABSENT** |
| **Batch-control commissioning** | P11 | **ABSENT** (kept distinct from P08/P09, per §7) |
| Human reviewer | P07 | **ABSENT** |

Independence is **structural, not promised**: the Stage 13 permanent gate
injects each authorization separately and asserts the aggregate fails closed
for every single omission. Supplying one can never satisfy another.

Credentials were observed **by NAME only** — no value, length, prefix, suffix
or hash was read or stored, and the local Docker `.env` was never opened as a
production channel:

```
CONEXAO_TRANSLATION_PROVIDER_KEY: ABSENT   <- the only approved variable
WP_USERNAME:                      ABSENT
WP_APPLICATION_PASSWORD:          ABSENT
OPENAI_API_KEY:  PRESENT, NOT used, NOT aliased
```

Gate counters: `installs 0, activations 0, content_mutations 0,
provider_calls 0, canary_selections 0, emergency_stop_clears 0`.

## 4. Provider path — the investigation §4 required, and its result

§4 required investigating Path A (native Connectors / WordPress AI Client)
**before** declaring the provider impossible. That investigation was carried
out against vendor sources fetched during this run. **Path A was rejected on
evidence.** Full quotations are in
`evidence/…/02-personal-plan-and-provider-path.txt`.

### Path A — Native Connectors / AI Client: REJECTED

WordPress 7.0 ships a Connectors API and a provider-agnostic AI Client
(`wp_ai_client_prompt()`), and WordPress.com does expose **Settings →
Connectors** with an OpenAI card on Personal. Superficially this looks like a
perfect fit: the plugin would reference a provider and never touch the key.

**It fails our secret boundary.** The decisive fact is where core stores the
key. `_wp_connectors_get_api_key_source()` checks, in order, *environment
variable → PHP constant → database*, falling back to `get_option( $setting_name )`.
The Connectors UI flow documented by WordPress.com is "paste your API key …
click Save" — which writes the **`database`** branch. The option name is
`connectors_ai_{provider}_api_key`, as stated in
[WordPress/plugin-check#1342](https://github.com/WordPress/plugin-check/issues/1342),
which also flags *reading* those options as an error and instructs plugins to
route through the AI Client instead.

So the connector key **is a `wp_options` row** — in the database, in every
backup, in every replica and every export. That is exactly what Stage 0
control S7 forbids, restated in our own shipped source:

> "the CREDENTIAL | the process environment ONLY | Stage 0 control S7: a
> credential in `wp_options` is in the database, in every backup, in every
> replica and in every export"
> — `class-conexao-translation-automation-provider-config.php`

This is precisely the trap §5 warned about: *"do not assume that 'Connector'
automatically satisfies our existing 'never store our provider key in our own
database' rule."* It does not. The `env` branch that *would* satisfy S7 is not
reachable through the Connectors UI, and Personal offers no server-level
environment-variable facility.

**Two further independent blockers:**

- **Our contract is not expressible through the AI Client.** The shipped
  transport is a direct `api.openai.com/v1/chat/completions` call with a fixed
  `gpt-4o-mini`, a 240,000-byte request ceiling, 4 attempts with
  500 ms–4000 ms backoff, a stable request-identity digest over
  `{provider, model, source_lang, target_lang}`, and a hard
  `MAX_TOTAL_PROVIDER_CALLS` cost guard. The AI Client returns whichever model
  the site owner happens to have connected and exposes none of those ceilings,
  retry bounds or identity digest. Adopting it would mean **rewriting the
  provider transport and its identity contract** — new architecture, not a
  credential path — which §41 and the no-second-engine rule put out of scope.
- **The enabling version is not observable.** Path A needs WordPress 7.0+.
  Production's version could not be established through any Personal-accessible
  anonymous channel: no `generator` meta, no `ver=` in the feed, and no
  AI/client/connector REST namespace (only `wp-abilities/v1` is
  AI-adjacent). Adopting it would require *assuming* the version supports it,
  which §1 forbids.

### Path B — External provider runner: NOT AVAILABLE

No external runner exists in this repository and none is authorized. §4
permits this path only if it is "already safely available within the current
architecture"; it is not, and building one would be a new component rather
than a credential path.

### Path C — No safe Personal-compatible path: **THIS IS THE STATE OF THE WORLD**

⇒ **`BLOCKED`.** And per §4 Path C, the key was **not** placed into plugin
source, ZIP artifacts, `wp_options` / `connectors_ai_*`, plugin settings, post
meta, audit records, or any database table. No unapproved workaround was used.

**Important consequence:** exporting `CONEXAO_TRANSLATION_PROVIDER_KEY` would
**not** unblock this stage on Personal, because WordPress.com provides no
server-level environment-variable facility for a plugin's PHP process to read.
Supplying the variable fixes P03's *absence* but not the *path*. That
distinction is the single most useful output of this stage.

## 5. Secret boundary — confirmed intact

The provider key was **not** copied into our plugin option, artifact, log,
audit, manifest, plugin configuration or source. Verified by:

- Stage 14 hardened secret gate: **323 passed, 0 failed**; 1581 source files
  scanned, **0** undeclared credential-shaped values; 17 declared fixtures all
  confined to `tests/`; 10 ZIPs and the release record scanned.
- No connector was ever configured, and no `connectors_ai_*` option was ever
  read or written.
- No credential value was read, hashed, masked or logged at any point.

The existing secret-safety boundary is unchanged and was not weakened.

## 6. P15 — production stop state: `NOT OBSERVABLE THROUGH PERSONAL PLAN`

**This is the stage's second substantive finding, and it is a repository gap,
not a hosting gap.**

The emergency stop's only consumer in the shipped runtime code is a single
internal assertion — `batch-executor.php:315`,
`Emergency_Stop::assert_may_start()`. There is therefore:

- **no** admin screen that displays the stop state (the Tools → Translation
  Automation screen renders a proof/dry-run button and the last result — it
  never renders the flag);
- **no** REST route, **no** AJAX action, **no** cron trigger, **no** read-only
  diagnostics endpoint.

So even with a successful manual wp-admin login, an operator currently has
**no Personal-plan-accessible way to read the production stop state**. P15
cannot be positively evidenced until a read surface exists. It was **not**
created by this stage: adding a production read surface is a behaviour change,
and Stage 15 is an operational execution stage.

The **artifact half** is proven and fails closed: absent ⇒ `STOPPED`;
`stopped=true` ⇒ `STOPPED`; malformed or non-boolean ⇒ `STOPPED`
(`FAILURE_MALFORMED`); clearing requires explicit authorization **and**
capability (`FAILURE_UNAUTHORIZED`); no request parameter is ever read as the
flag; no activation hook can clear it.

**Provenance and clearance:** `production_stop_state` = **NOT OBTAINED**;
`LIVE_PRODUCTION_READ` = **NOT PERFORMED**; no server-side timestamp was
obtained; `emergency_stop_clearance` = **NOT GRANTED**;
`emergency_stop_clears` = **0**. No substitute or fabricated stop-state object
was created — that would have recreated exactly the defect Stage 14 closed.

## 7. Legacy endpoint — production `410` NOT positively verified

Required (§12): in an authenticated wp-admin context,
`admin_post_conexao_translation_rollout_run` → **HTTP 410**, no redirect, no
engine invocation, no mutation, never submitting `mode=apply`.

Observed anonymously: **HTTP 400**. This is **not** the required proof and is
not offered as a substitute — a 400 is WordPress's generic rejection of an
unauthenticated/malformed admin-post, returned before plugin code runs, and it
says nothing about what the stub answers to an authenticated caller.

**No POST was issued. No `mode=apply` was submitted. The legacy engine path was
not attempted.** Status: **NOT POSITIVELY VERIFIED** — open since Stage 8.

This blocker is **not** a Personal-plan limitation: a manual admin POST would
settle it and *is* available on Personal. It is blocked purely by the absent
authorization/session.

**Repository-side structural fact (not a production proof):** the shipped stub
registers only `admin_menu` and `admin_post_conexao_translation_rollout_run`,
and its own docblock states *"There is no branch here that reaches the engine,
a stage callback or a write."* Covered by
`test-legacy-apply-endpoint-closure.php` (36 assertions, PASS),
`verify-stage8-control-plane.py` (149, PASS) and
`verify-stage8-release-consistency.py` (52, PASS). These are real results about
the **artifact** and are deliberately not presented as the production proof.

## 8. Control plane — exact production state

| Surface | Production observation | Verdict |
|---|---|---|
| `/wp-json/conexao/v1` | **404** | no translation REST route |
| REST namespaces | 23 advertised; **none** contains `conexao` or `translat` | **clean** |
| Anonymous `admin_post_*` (all three actions) | **400** each | not reachable anonymously |
| Anonymous AJAX | none declared in source | **clean** |
| Webhooks | none declared; no inbound route | **0** |
| Cron translation trigger | no `wp_schedule_event`/`_single_event` in runtime code | **none exists by construction** |
| Authenticated approve/closed status | — | **NOT OBSERVABLE without an authenticated session** |

The **anonymously observable** half of the control plane is clean and matches
expectations. The **authenticated** half was not verified and is recorded as
not observable without a wp-admin session.

**Batch control: NOT COMMISSIONED** (P11 absent). Re-verified from source:
`is_commissioned()` returns `false`, and the `Batch_Control::register()` call
site in the plugin bootstrap is commented out. The gate independently reports
`control_plane=conexao_translation_automation_batch DECLARED` and fails closed
if that call site ever becomes live code.

## 9. Emergency stop — final state

Never read, never written, **never cleared**. `clearance = NOT_GRANTED`,
`clears = 0`. After the stage: bulk translation **OFF**, next batch **OFF**,
autonomous approval **OFF**, cron **OFF**, automatic progression **OFF**, batch
limits and allowlist **unchanged**, second canary **none executed**. No
automatic clearance was prepared or performed.

## 10. Baseline, bootstrap and reconciliation — all NOT PERFORMED

Fresh production baseline, the six historic PT restorations, bootstrap
(`missing state → bootstrap_required → actionable=0 →
mutation_permitted=false → mutation_occurred=false`), and the immediate
no-change reconciliation (`no_changes`) were **not performed**: there is no
installed plugin, no production write and no authenticated read. **No earlier
baseline was reused and none was invented.**

## 11. Live provider smoke, dry-run and canary — all NOT PERFORMED

- **Live provider smoke:** **NOT PERFORMED. `RESULT: OK` is not claimed.** No
  provider call was made, no quota was spent, quota is `NOT_PROVEN`, and
  provider-call authorization (P05) is absent. No provider limit was changed.
- **Proof trigger / production dry-run:** NOT PERFORMED (no authenticated
  admin session, no installed plugin). No run ID, plan digest, actionable set
  or batch candidate exists.
- **Canary selection, human review, digest-bound approval, Model A production
  proof, apply:** all NOT PERFORMED. No human reviewer exists (P07), and the
  structural validator was **not** substituted for one. No separate apply
  authorization exists (P09), and apply was not inferred from installation,
  provider access, review or readiness.
- **Post-apply verification** (mutation-set equality, PT immutability, EN/B1/B2,
  routes, lock, audit, idempotence): NOT PERFORMED — there was no apply.

## 12. Artifact, deployment and engine integrity

| Item | Value |
|---|---|
| Git state | branch `i18n`, HEAD `7c993463c64fd18be9e1e20fbc9358bce75ec7de` |
| `conexao-translation-rollout` | header **1.2.0** = registry = release record |
| `conexao-translation-automation` | header **0.4.0** = registry = release record |
| Dependency order | #8 rollout → #10 automation; `Requires Plugins` satisfied |
| `conexao-translation-rollout.zip` | `73b3aee368712397e4ddbb496d8915bb6021443ab7e75bedf345e9fa4ad35215` |
| `conexao-translation-automation.zip` | `1e5a1cf228ee9950ed584400dd029846fb170389331762ec42e1e7abcd644b21` |
| Double build | **byte-identical**, 9/9 plugin artifacts, 0 differing |
| Exclusions | 0 `tests/` and 0 Docker/`.env` entries in both translation ZIPs |
| Registry | OK, 15 plugins, 24 regions current, **zero writes** |
| Release record | `v2026.10.01`, 10 artifacts / 10 built, `--verify` exit 0 |
| Lint | exit 0 — syntax clean, no new PHPCS violations, PHPStan clean |

**Deployment: NOT PERFORMED.** No ZIP was uploaded; no plugin was installed or
activated. `conexao-content` was not deactivated or reactivated. No Plugin File
Editor was used as a deployment mechanism, and no SFTP/SSH/WP-CLI/phpMyAdmin was
used or required.

**Engine integrity: BEFORE == AFTER == EXPECTED.**
`264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912`, with
`git diff HEAD` over the whole `conexao-translation-rollout/` directory empty.
The engine was **not** modified.

## 13. Tests

`./scripts/run-tests.sh` → in-process **79/81** (6123 assertions passed, 6
failed), script-contract **15/16**, HTTP acceptance **2/3**. Permanent gates
**6/7** with `i18n_freshness` red. Every failure maps 1:1 to a §40
carried-forward condition; **nothing was changed or reclassified to obtain
green.** Full breakdown in `evidence/…/08-preproduction-tests.txt`.

## 14. Production changes

**None. Exactly zero.** No install, no activation, no deactivation, no
provider call, no content mutation, no option write, no menu/taxonomy/Polylang
change, no emergency-stop change, no audit record written to production, no
limit or allowlist change.

## 15. Existing conditions (carried forward unchanged, §40)

| Condition | State |
|---|---|
| `i18n_freshness` — 3 stale catalogues | unchanged; **no catalogue regenerated** (`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`; gate labels them 2 pre-existing + 1 new) |
| `dwyer-mcallister-cottage` | unchanged |
| newsletter `en_id 509` | unchanged |
| leisure-card excerpt failures | unchanged (5 assertions + 1 HTTP row) |
| Stage 2 cache-scoping exemption | unchanged |

## 16. Remaining conditions — genuine blockers only

1. **No Personal-compatible provider credential path** (Path C). Requires an
   architecture decision, not a credential (§4 above).
2. **No installation authorization** (P02) — nothing was uploaded.
3. **No provider-call, canary, apply, commissioning or batch-control
   authorization** (P05, P08, P09, P10, P11).
4. **No named human reviewer** (P07).
5. **P15 has no read surface** — `NOT OBSERVABLE THROUGH PERSONAL PLAN`; a
   repository gap that must be closed before P15 can ever be evidenced.
6. **Production `410` not positively verified** (open since Stage 8).
7. **No production admin session** — blocks the 410, the authenticated control
   plane, and the post-install re-run.
8. **No fresh baseline, bootstrap, reconciliation, dry-run, Model A production
   proof or canary.**

Note: an upgrade to Business/Commerce would **not** be a fix for items 1 and 5,
and this report does not present it as one. Item 1 is an architecture
question; item 5 is missing code.

## 17. Stage 16 prerequisites

Bounded multi-record production rollout may begin only when **all** hold:

1. **A resolved provider credential path** — either an explicit architecture
   decision on the AI-Client/transport rewrite (with its own review, its own
   tests, and an accepted position on S7), or an authorized external runner
   that reuses this repository's existing validator, digests and approval
   chain. The key must never enter `wp_options`, an artifact, a log or an
   audit record.
2. **A P15 read surface** shipped and deployed, so the stop state is readable
   through a Personal-accessible admin surface.
3. Readiness gate returns **`READY`** with `commissioning_permitted=true`, from
   current evidence — not a reused Stage 13/14/15 result.
4. **Separate** installation, provider-call, commissioning, batch-control,
   canary and canary-**apply** authorizations, each independently evidenced.
5. A named human reviewer.
6. Production installation via wp-admin ZIP upload, with the control plane
   proven — including a positive production **`410`** for
   `admin_post_conexao_translation_rollout_run` — and cron `0`.
7. A **fresh** baseline after installation, six PT restorations re-verified,
   bootstrap proven, reconciliation `no_changes`.
8. One live provider smoke returning `RESULT: OK`.
9. A reviewed production dry-run, a digest-bound approval, and
   `approved scope == planned scope == executed scope` proven from the engine's
   own scope report — not reconstructed from the batch wrapper.
10. Exactly one operation applied, with actual mutation set == approved set, PT
    immutability, EN/B1/B2 and route verification, complete audit and
    idempotence.
11. Afterwards: bulk OFF, next batch OFF, cron OFF, autonomous approval OFF,
    automatic progression OFF, limits and allowlist unchanged.

## 18. Files created / modified by this stage

| Path | Change |
|---|---|
| `docs/reports/2026-10-01-stage-15-personal-production-commissioning-model-a-canary.md` | added (this report) |
| `docs/evidence/2026-10-01-stage-15-personal-production-commissioning-model-a-canary/` | added (8 evidence files) |
| `docs/reports/README.md` | modified — index entry |
| `docs/evidence/README.md` | modified — index entry |
| `docs/evidence/2026-09-26-stage-l/gate.json` | regenerated by `verify-permanent-gates.py` (the Stage L record) |

**No production, plugin, engine, test, registry or release-metadata file was
modified.** The pre-existing staged changes under
`docs/evidence/2026-10-01-stage-15-final-production-commissioning-canary/` were
already in the working tree before this stage began and were not authored or
altered here.

---

_Last verified: 2026-10-01 by Stage 15 — Personal production commissioning
(status `BLOCKED`; production changes 0; engine integrity before == after)._