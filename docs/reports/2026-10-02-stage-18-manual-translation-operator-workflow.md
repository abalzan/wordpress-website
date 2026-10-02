# Report — Stage 18: manual translation operator workflow

> **The point of this stage, stated once.**
>
> A human can now ask for an occasional PT→EN translation in one sentence and
> follow a documented sequence to a verified result — without understanding
> anything about the retired automatic-translation architecture, and without
> weakening a single control to get there.

| | |
|---|---|
| **Stage / task name** | Stage 18 — manual translation operator workflow |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `d4af7d28020e76d379befa997798bc14a2fb7d33` (plus uncommitted Stage 17) |
| **Production writes** | **0** — nothing deployed, no provider call, no mutation |
| **New automation** | **none** — no cron, webhook, trigger, broker, engine or commissioning surface |
| **Skills followed** | `wp-repository`, `wp-translation-rollout`, `wp-update-docs`, `wp-testing`, `wp-write-in-process-test`, `wp-security-review` |

## Stage 18 status

| Concern | Status |
|---|---|
| Manual operator runbook | **WRITTEN AND EXERCISED** |
| Existing safety controls | **UNCHANGED — all 12 retained and documented** |
| Runbook accuracy gate | **111 assertions, 15 mutations, all fail closed** |
| Automatic execution | **still OFF; nothing can turn it on** |
| Permanent provider credential in WordPress | **none; none introduced** |
| Production translation performed | **0** |

## 1. Scope completed

1. **The operator runbook** — `docs/translation-manual-operator-runbook.md`,
   the single operational home for an occasional manual translation. Eleven
   sections: the lifecycle, the five required request inputs, the twelve
   retained controls, the credential procedure, a worked example, the ten
   expected metrics, local vs production, the recorded execution, recovery, and
   the operator checklist.
2. **A gate that keeps it true** — `tests/scripts/verify-stage18-operator-runbook.py`
   (111 assertions) checks the runbook against the *code it describes*.
3. **Proof the promised numbers are real** — the manual-workflow suite gained a
   fifth section (17 assertions) proving every metric the runbook promises is a
   genuine field of a real engine report.
4. **The documented procedure was executed locally**, and the real output is
   recorded — including a genuine failure that was reported rather than hidden.

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| **Commissioning apply** | Still declared-and-uncommissioned since Stage 11. Commissioning it is a separate, separately authorized production action. The runbook states this plainly rather than implying a production apply is available. |
| **A live OpenAI call** | No provider credential is supplied for this stage and the brief sets production writes to 0. Nothing is fabricated. |
| **A new production API or auth mechanism** | Explicitly forbidden by the brief, and unnecessary — the existing admin screen is the supported path. |
| **Fixing the `dwyer-mcallister-cottage` stale translation** | A content decision (re-author the description), not a documentation or gate change. |
| **Committing** | No commit was made; the change set is in the working tree for review. |

## 3. Files added / modified

| Path | Change | Note |
|---|---|---|
| `docs/translation-manual-operator-runbook.md` | **added** | The runbook. 368 lines, 11 sections. |
| `tests/scripts/verify-stage18-operator-runbook.py` | **added** | The runbook gate. 111 assertions, 15 injected mutations. |
| `…/tests/test-manual-translation-workflow.php` | modified | Fifth section: 17 assertions that the promised metrics are real fields. 49 → 66. |
| `docs/README.md` | modified | The runbook added to the documentation index. |
| `docs/plugins/conexao-translation-automation.md` | modified | Points at the runbook as the operator's procedure; the plugin doc stays the architecture. |
| `docs/testing.md` | modified | The new gate and the extended suite registered. |
| `docs/evidence/README.md` | modified | The Stage 18 evidence directory listed. |
| `docs/evidence/2026-10-02-stage-18-operator-runbook/` | **added** | The recorded execution. |

**No plugin source changed.** The shared engine, the rollout plugin and the
automation plugin are byte-identical; this stage adds documentation and two
test/gate layers around behaviour that already existed. The engine digest is
unchanged at `264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912`.


## 4. The runbook is a contract, not prose

The central design decision: **a runbook that drifts from its code is worse than
no runbook**, because an operator would confidently report a guarantee the system
no longer makes. So the gate reads facts out of the source rather than trusting
the document.

| Bucket | What the gate proves |
|---|---|
| **PRESENT** | The runbook exists, is linked from `docs/README.md` *and* from the plugin that owns the workflow, and carries the `_Last verified:` marker. |
| **LIFECYCLE** | All eleven stages and all eight required sections are present; the broker, cron and webhook are not offered as live options. |
| **SCOPE** | **Both directions against `allowed_stages()`, read from the source**: every allowlisted stage must be documented, and every `` `en-*` `` the runbook names must really be allowlisted. The non-automatable `job` stage must be called out. |
| **CONTROLS** | All twelve retained controls are documented. |
| **METRICS** | **Every promised number has a real producer** in the engine, the scope report, the batch executor or the result contract. |
| **SECRET** | No credential shape; the real `CREDENTIAL_ENV` name is present; all eight forbidden storage locations must appear **inside** the prohibition block. |
| **HONESTY** | Personal's lack of server-side env vars is stated; the disproven workarounds are absent; apply-not-commissioned is asserted positively. |

### The gate found two real defects in itself

Attacking the gate was worthwhile, because two of my first checks were weaker
than they looked:

1. **The forbidden-location check was satisfied by an unrelated sentence.** It
   tested whether the block contained "not" or "never" — but the block also says
   the key "is never persisted", so deleting the actual instruction
   (*"Do **not** put it in"* → *"Put it in"*) still passed. Fixed to match the
   instruction itself.
2. **The apply-status check was satisfied by a different sentence.** Rewriting
   the §10 statement to "fully commissioned" passed, because §8 still said "not
   commissioned" somewhere else. Fixed to require the positive claim at its
   source.

Also worth recording: **the first mutation run was entirely vacuous.** The
`sed` invocations had no file argument, so nothing changed and every "pass" was
meaningless. The harness now diffs before and after and reports `VACUOUS` — a
no-op edit can never be counted as a pass again.

## 5. Regression comparison

| Metric | Before | After |
|---|---|---|
| In-process suites | 81 | 81 |
| Manual-workflow assertions | 49 | **66** (+17) |
| Script-contract suites | 17 | 17 |
| Script-contract assertions | — | **+111** (new gate) |
| Engine digest | `264cc6c4…6912` | `264cc6c4…6912` (unchanged) |
| New failures | — | **0** |

## 6. Known pre-existing failures

Unchanged and **not** reclassified to obtain green:

| Failure | Condition |
|---|---|
| `test-en-jobs-shared-slug.php` (1) | newsletter `en_id 509` |
| `test-leisure-card-excerpt-language.php` (5) | leisure-card excerpts |
| `verify-i18n-freshness.py` (3 catalogues) | stale `.pot` files — no catalogue regenerated here |
| `verify-routing-http.py` (1 row) | the same leisure-card excerpt condition, over HTTP |

**One pre-existing condition is newly *visible*** through the documented
workflow: running every stage locally surfaces
`en-leisure-description` **GATE FAIL** (`missing EN=1 conflicts=1`) on
`dwyer-mcallister-cottage` — a stale translation whose PT source changed. It
was already failing in earlier stages' evidence; executing the documented
procedure simply shows it, and the runbook reports it rather than hiding it. It
is the PT-drift guard working correctly, and the remedy is to re-author the
description.

## 7. Limitations

1. **No live provider call**, and none fabricated. The provider is proven at its
   fail-closed boundary; a live call needs a credential and an authorization
   this stage does not have.
2. **Apply remains uncommissioned**, so "apply" in this runbook is proven
   through the in-process suites and the local runner, not through a production
   apply. Stated in the runbook rather than glossed.
3. **The local execution was a dry run.** `records executed` and `mutations`
   are legitimately `0` in the recorded run; the apply path is proven by the
   in-process suite against the real engine.
4. **The evidence is one machine's local Docker state.** The stage-level
   numbers will differ on another dataset; what transfers is the procedure and
   the fact that the drift guard fires.

## 8. Evidence

`docs/evidence/2026-10-02-stage-18-operator-runbook/` — see the table in
`docs/evidence/README.md`.

## 9. Rollback / recovery

```bash
git checkout -- docs/ wp-content/plugins/conexao-translation-automation/tests/
rm -rf docs/evidence/2026-10-02-stage-18-operator-runbook
rm -f tests/scripts/verify-stage18-operator-runbook.py
```

No migration, no data change, nothing in production. Rolling forward is equally
safe: the runbook and its gate are additive, and the gate fails closed if the
runbook drifts from the code.

## 10. Final status

**`PASS` for the scope of this stage: the manual translation operator workflow is
documented, gated against drift, and exercised with real recorded results.**

```text
manual operator runbook:        WRITTEN AND EXERCISED
existing safety controls:       UNCHANGED (all 12 retained)
runbook accuracy gate:          111 assertions / 15 mutations, all fail closed
promised metrics:               17 assertions proving they are real fields
automatic execution:            still OFF; nothing can turn it on
provider credential in WP:      none; none introduced
production translations:        0
```

---

_Last verified: 2026-10-02 by Stage 18 — manual translation operator workflow
(gate 111 assertions / 15 mutations; manual-workflow suite 66; production
writes 0)_
