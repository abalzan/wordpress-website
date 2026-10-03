# Manual translation operator runbook

> **If you only remember one sentence:**
>
> *"Translate these Portuguese records to English using the existing manual
> rollout workflow."*
>
> Then follow §2 → §10 below. You do not need to know anything about how
> automatic translation used to work, and you do not need to read any stage
> history to do this correctly.

**Stage / task name** | Stage 18 — manual translation operator workflow
**Date** | 2026-10-02
**Owner** | project maintainer (a human, or a coding agent acting for one)

This is the **one operational home** for performing an occasional Portuguese →
English translation. It documents a *procedure*, not a fact about the
architecture, so nothing here is a second source of truth: every claim points
at the code or the canonical document that owns it.

| Concern | Single source of truth |
|---|---|
| The translation lifecycle itself | [`conexao-translation-rollout`](plugins/conexao-translation-rollout.md) — the shared engine |
| The manual control plane | [`conexao-translation-automation`](plugins/conexao-translation-automation.md) |
| Which stages may be driven | `Conexao_Translation_Automation_Orchestrator::allowed_stages()` |
| B1 / B2 policy | [`docs/routing.md`](routing.md) §English rollout state |
| Identities and meta | [`docs/content-model.md`](content-model.md) |
| The six-step content-change contract | [`docs/engineering-standard.md`](engineering-standard.md) §5.2 |
| The generated readiness runbook | `./scripts/verify-commissioning-readiness.py --runbook` |

## 1. What this workflow is, and what it is not

The site is translated **on demand**. When English is needed an operator runs a
translation deliberately; when it is not needed, nothing runs.

```text
identify PT scope → inventory → manifest → dry-run → snapshot → review
→ explicit approval → apply → verify → idempotence → stop
```

| | |
|---|---|
| **Is** | A deliberate, human-initiated run of the **existing** shared engine. |
| **Is not** | Automatic, scheduled, unattended or continuous. |
| **Is not** | A second translation engine. `conexao-translation-rollout` remains the sole mutation authority. |
| **Never** | Changes Portuguese content. PT is canonical and immutable. |

There is **no cron, no webhook, no automatic trigger, no external broker and no
permanent provider credential**. Automatic translation was retired in Stage 17
([report](../reports/2026-10-02-stage-17-retire-automatic-translation.md)) and
the machine-checkable statement of that is
`tests/scripts/verify-stage17-retirement.py`, which fails closed if any of it
returns.

## 2. What the operator must provide when requesting a translation

A request is complete when it names these five things. If one is missing, ask
for it — do not infer it.

| # | Required input | Why it cannot be inferred |
|---|---|---|
| 1 | **Content type** (e.g. Blog posts, Guides, Pages, Leisure descriptions) | It selects the stage, and the stage selects the manifest. |
| 2 | **The records, or an explicit scope rule** | Scope must be explicit. There is no automatic scope discovery. |
| 3 | **B1 or B2**, where the type can be either | B1 creates a *linked EN record*; B2 authors an *EN field on the same PT record*. Different identity, different rollback. |
| 4 | **Explicit exclusions**, if any | A record not translated is a *documented decision*, never an oversight. |
| 5 | **Confirmation that PT remains canonical** | PT immutability is the repository's central safety rule. |

A worked example is in §5.

### Choosing a stage

The stage is chosen by content type and must be on the explicit allowlist in
`Conexao_Translation_Automation_Orchestrator::allowed_stages()`. A stage that is
not on that list is **refused**, not silently substituted.

| Content | Stage | Policy |
|---|---|---|
| Blog posts (`post`) | `en-post` | **B1** — a linked EN post |
| Guides (`guide`) | `en-guide` | **B1** |
| Pages (`page`) | `en-page` | **B1** |
| Blog posts page | `en-blog-page` | **B1**, shared slug by design |
| Jobs page | `en-jobs-page` | **B1** |
| Leisure card descriptions | `en-leisure-description` | **B2** — an EN field on the same PT record |
| Course provider descriptions | `en-course-provider-description` | **B2** — an EN field on the same PT record |

**B1 vs B2, in one line.** B1 = a real English record that is a *linked
translation* of the same identity, reachable at its own `/en/` URL. B2 = no
second record; an authored English field on the **same PT record**, rendered
under the English shell with a notice, canonical pointing to PT and out of the
sitemap. The full policy is [`docs/routing.md`](routing.md) §English rollout
state.

## 3. The safety controls — none of them are optional

Every one of these is still active. **None was weakened to retire automation**,
and a gate fails closed if one is removed.

| Control | What it does |
|---|---|
| **PT is immutable** | PT URLs, slugs, identity, titles and meta are never modified. PT drift is detected by a snapshot diff and **fails the gate**. |
| **Scope is explicit** | The scope can only *narrow* the stage's own manifest. An identity the stage does not author is a **refusal**, not a filter. |
| **Dry-run before apply** | There is no apply without a preceding `PASS` dry-run. `FAIL`, `ERROR`, invalid, missing, stale and mismatched are all "not PASS". |
| **Approval is digest-bound** | The approval names the exact plan, not a boolean: manifest, plan and snapshot digests, stage, run id, environment and configuration identity. *Review plan A, apply plan B* fails closed. |
| **Apply is explicit** | Apply is a separate, deliberate action. It never happens as a side effect of a dry run. |
| **Model A exact scope** | After the dry run the executor re-reads the engine's own plan and refuses the whole batch if any record outside the approved set is planned. Approved scope == executed scope, proven from the engine's plan, not from a caller-supplied answer. |
| **Collisions fail closed** | A colliding `en_slug` is a refusal with zero writes. |
| **PT drift fails closed** | A PT record that changed since the English was authored is refused as a **stale translation**, not written. |
| **Malformed relationships fail closed** | A one-way or broken Polylang link is an error, not a success. |
| **Duplicate execution is idempotent** | A second identical run creates nothing and updates nothing. |
| **Lock and audit remain active** | A site-wide atomic lock admits one run at a time (a second run gets `locked` and performs no mutation); every outcome is an audit event carrying a `run_id`. |
| **Automatic execution stays OFF** | There is no mechanism in the repository that can turn it on. |

## 4. Supplying the provider credential — for an occasional manual run

The optional OpenAI provider reads its key from the **process environment of
that run only**, as `CONEXAO_TRANSLATION_PROVIDER_KEY`
(`Provider_Config::CREDENTIAL_ENV`), via `getenv()`.

```bash
# The supported shape: exported into the run, used by that run, gone after it.
CONEXAO_TRANSLATION_PROVIDER_KEY='<YOUR-KEY-HERE>' \
  docker compose exec -T wordpress php /var/www/html/scripts/run-en-translation.php --dry-run --only=post
```

> **Placeholders only.** `<YOUR-KEY-HERE>` is a placeholder. Never write a real
> key into documentation, a commit, a log, a report or a chat message.

### Never store the key in any of these

A provider key in the database is in the database, in every backup, in every
replica and in every export. Do **not** put it in:

* WordPress options or any settings screen;
* **Connectors** (Settings → Connectors), or any other built-in integration;
* post meta, options, transients or any database row;
* source code, a constant, or a config file committed to the repository;
* Git — commits, branches, tags, or `.env` files;
* release artifacts or ZIPs;
* logs, screenshots, evidence files or audit records.

The key is read by exactly one method in the repository, is never persisted, and
`Conexao_Translation_Automation_Result::assert_no_secrets()` refuses any payload
that carries credential-shaped material — the audit trail is structurally
incapable of holding a key.

### What this means on WordPress.com Personal

**WordPress.com Personal does not support server-side environment variables**,
server files, SSH, SFTP or WP-CLI. So on Personal the plugin's production screen
can only reach a provider if the hosting environment supplies that variable; it
cannot, and this repository does not pretend otherwise.

That is precisely why automatic translation was retired. **A manual run does not
require a permanent credential in WordPress.** In practice this means one of:

1. **Run the workflow locally or in CI**, where the operator's shell genuinely
   has an environment. This is the normal path and the one exercised in §9.
2. **Translate by hand** in wp-admin and let the engine's own verification
   establish the EN/PT relationship — see §8 and §10 for the limits of this.

Do **not** invent a production API, a token endpoint, a proxy, or an
authentication mechanism to work around the plan limitation. There is no
supported one, and adding one is out of scope.

> **Note on the `job` stage.** `job` is registered with the shared engine but is
> deliberately **non-automatable** — it is named in `NON_AUTOMATABLE_STAGES`
> and is not on the allowlist, so the manual control plane refuses it by design.

## 5. A concrete example

> Translate these three Portuguese blog posts to English:
>
> - `<PT record 1>`
> - `<PT record 2>`
> - `<PT record 3>`
>
> Use the existing B1 blog translation policy.
> Do not modify PT content.
> Run the normal inventory → manifest → dry-run → review → apply → verify →
> idempotence workflow.

Note what the request supplies: content type (blog posts), the exact records, the
policy (**B1**), an implicit exclusion set (everything else), and PT
canonicality. All five inputs from §2 are present.

### What the agent reports back at each stage

| Stage | Report |
|---|---|
| **Identify scope** | The three PT identities resolved by slug, and confirmation each is PT, published, and untranslated. |
| **Inventory / manifest** | How many records the stage declares, how many matched the requested scope, and any record in scope that the stage does **not** author (a refusal, not a silent skip). |
| **Dry-run** | The full plan, row by row, with each row's action. `created` / `updated` / `skipped` counts. **Zero writes.** |
| **Snapshot** | The PT snapshot digest that the approval will be bound to. |
| **Review** | The plan **digest**, presented for approval. The operator compares it to what they intended. |
| **Approval** | The operator approves that **exact digest**. A changed digest invalidates the approval automatically. |
| **Apply** | The mutation set actually written, and the confirmation that executed scope == approved scope. |
| **Verify** | The engine's own numeric gate and the PT-drift count. |
| **Idempotence** | A second identical run, and its counts — all zero. |
| **Stop** | Confirmation that nothing further runs, and that automatic execution remains OFF. |

## 6. The expected result — real numbers, always

A run is not complete until these are reported. **Never report success without
executed evidence.**

| Metric | Where it comes from |
|---|---|
| `records considered` | the stage manifest's record count |
| `records eligible` | `gate.eligible_public_pt` |
| `records planned` | the plan's `create` + `update` + `conflicts` rows |
| `provider calls` | the batch executor's `provider_calls` (0 when no provider is used) |
| `records approved` | `scope.approved_count` |
| `records executed` | `scope.executed_count` |
| `mutations` | `mutation_occurred`, and the `created` + `updated` counts |
| `verification result` | `gate.gate` — literally `PASS` or `FAIL` |
| `idempotence result` | the second run's `created` + `updated`, which must be `0` |
| `PT drift` | `gate.pt_drift` — must be `0` |

These are **fields of the real result contract**, not aspirational labels;
`tests/scripts/verify-stage18-operator-runbook.py` asserts that each one is
genuinely produced by the code.

## 7. Local / test run — validating the implementation

Use this to prove behaviour before anything else. It needs no production
authorization.

```bash
docker compose up -d
# 1. Inventory + manifest + dry-run. Writes nothing.
docker compose exec -T wordpress php /var/www/html/scripts/run-en-translation.php --dry-run --only=post
# 2. Every stage.
docker compose exec -T wordpress php /var/www/html/scripts/run-en-translation.php --dry-run
# 3. The safety properties.
./scripts/run-tests.sh --only conexao-translation-automation
```

`run-en-translation.php` is **dry-run by default**, is declared
`production_capable => false`, and refuses an unknown stage. Its exit code is
non-zero when any stage's numeric gate fails.

A real, recorded run of exactly these commands is in
[`docs/evidence/2026-10-02-stage-18-operator-runbook/`](../evidence/2026-10-02-stage-18-operator-runbook/)
and reported in §9 below.

## 8. Manual production translation

A production run uses the **existing** protected manual workflow. Nothing new is
invented, and no new authentication mechanism is introduced.

| | |
|---|---|
| **Entry point** | `Tools → Translation Automation` in wp-admin — the authenticated admin screen, because **production has no CLI**. |
| **Method** | POST only, `manage_options`, nonce-verified, server-derived environment. |
| **Scope** | One stage per invocation, bounded by the batch ceilings. |
| **Apply** | **Not commissioned.** The screen performs dry-run planning only. Commissioning apply is a separate, separately authorized production action. |
| **Authorization** | The existing `wp-production-operations` / `wp-release-deploy` requirements. Nothing in this runbook grants production access. |
| **Not available on Personal** | SSH, SFTP, WP-CLI, a database, a server filesystem, server environment variables. |

The supported production path is therefore: **produce a reviewed plan in
production, and let a human perform the authoring** — or commission apply as its
own authorized change. The honest statement is better than a workflow that
sounds complete and is not.

## 9. This runbook, actually executed

The procedure above was run locally on 2026-10-02. Real output, not an
illustration.

**Step 1–4 — dry run, `en-post` (the Blog B1 policy):**

```text
=== stage: en-post (mode: dry-run, operation: run) ===
  GATE PASS: eligible PT=34 with EN=34 missing EN=0 conflicts=0 PT drift=0
summary: created=0, updated=0, skipped=43, removed=0, errors=0,
         conflicts=0, pt_changed=0, gate_failures=0
```

**Step 1–4 — dry run, every stage:**

| Stage | eligible PT | with EN | missing EN | conflicts | PT drift | gate |
|---|---|---|---|---|---|---|
| `en-guide` | 49 | 49 | 0 | 0 | 0 | PASS |
| `en-page` | 30 | 30 | 0 | 0 | 0 | PASS |
| `en-post` | 34 | 34 | 0 | 0 | 0 | PASS |
| `en-blog-page` | 1 | 1 | 0 | 0 | 0 | PASS |
| `en-jobs-page` | 1 | 1 | 0 | 0 | 0 | PASS |
| `en-leisure-description` | 246 | 245 | **1** | **1** | 0 | **FAIL** |
| `en-course-provider-description` | 8 | 8 | 0 | 0 | 0 | PASS |

**Reported honestly.** The leisure-description stage fails, and the run exits
non-zero. The single failing row is:

```text
error   dwyer-mcallister-cottage
        PT source changed since the English was authored —
        re-author the description (refusing to write a stale translation)
```

This is the **PT-drift guard working exactly as documented in §3**: a stale
translation is refused rather than written. It is a pre-existing local data
condition, not something this stage introduced and not something to be hidden by
widening an allow-list. The remedy is the documented one — re-author the
description for that record — and it is a content decision, not a gate change.

**Zero mutations, zero production writes.** Both runs were `--dry-run`. No
`--apply` was executed, so `records executed` and `mutations` are legitimately
`0`; the apply path is proven by the in-process suites instead, which exercise
the real engine and report `created`/`updated` against an in-memory adapter.

**The safety suite, executed:**

```text
docker compose exec -T wordpress php \
  /var/www/html/wp-content/plugins/conexao-translation-automation/tests/test-manual-translation-workflow.php
→ stage 17 retained manual workflow: 49 passed, 0 failed
```

covering: the real engine driven end to end; apply touching exactly the approved
record; executed set == approved set; a second run creating and updating
nothing; PT unchanged; a colliding slug failing closed with zero writes; PT
drift detected; unknown mode and unknown stage refused; and the credential
boundary refusing when no key is supplied for the run.

## 10. Rollback / recovery

Recovery uses the mechanisms **this repository already supports**. WordPress.com
Personal has no database access, so no SQL-level rollback is documented — and
none is invented.

| Situation | The supported recovery |
|---|---|
| An apply produced a wrong EN record | The engine's own **`--remove`** operation: `php scripts/run-en-translation.php --remove --apply --only=<stage>`. It deletes the EN records that stage owns and leaves PT untouched. |
| A partially applied batch | The batch **stops** and is never shrunk. Re-run against a corrected, re-approved scope. |
| A stale-source refusal | Re-author the English for that record; the drift guard then admits it. |
| A wrong scope was approved | Approve nothing, re-dry-run, re-approve the corrected digest. Nothing was written. |
| A bad release reached production | The release rollback procedure in [`docs/releases.md`](releases.md). |

A PT record is never the thing being rolled back: it is immutable, so recovery
only ever removes or corrects **EN** artifacts.

**Two honest limits.** Apply is **not commissioned**, so a production run stops
at a reviewed plan. And there is **no automated rollback** — deliberately:
rollback stays an operator procedure in wp-admin, not a second mutation path.

## 11. Operator checklist

```text
[ ] PT scope confirmed
[ ] correct B1/B2 policy confirmed
[ ] inventory complete
[ ] dry-run completed
[ ] plan reviewed
[ ] exact digest approved
[ ] explicit apply authorized
[ ] Model A scope = exact intended scope
[ ] verification passed
[ ] idempotence passed
[ ] PT drift = 0
[ ] automatic execution remains OFF
```

If any box cannot be ticked, **stop and report** — do not continue and do not
relax a gate to make a box tickable.

---

_Last verified: 2026-10-02 by Stage 18 — manual translation operator workflow_
