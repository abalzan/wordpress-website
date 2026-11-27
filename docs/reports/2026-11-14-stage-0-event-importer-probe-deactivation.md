# Report — Event Importer Stage 0 probe-source deactivation

| | |
|---|---|
| **Stage / task name** | Event Importer Stage 0 probe-source deactivation |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `274fc10ab9c4bc4e26ee15ed077c80182e9e739a` |
| **Final SHA** | `274fc10ab9c4bc4e26ee15ed077c80182e9e739a` (no commit made) |
| **Working tree at finish** | dirty — this report, the plan and the new evidence files only |
| **Skills followed** | `wp-repository`, `wp-content-change` |
| **Authoritative docs consulted** | `AGENTS.md`; `docs/engineering-standard.md` §§0, 5.2, 12, 14; `docs/content-model.md`; `scripts/README.md`; `docs/plugins/conexao-event-importer.md` |

> **Starting-HEAD note.** The brief expected `464a353c8dc425d34cbf226668ffd889f8d4fec0`.
> HEAD was `274fc10ab9c4bc4e26ee15ed077c80182e9e739a`, a direct child of that
> commit, whose only change is the earlier blocked-preflight plan/evidence/report.
> The branch was correct (`event-import-improvements`) and the working tree was
> clean, so the preflight continued from the actual HEAD and no history was touched.

## 1. Scope completed

**Stage 0 completed.** The 18 existing source records matching the anchored pattern
`^g1_probe_` in the local `conexao_event_sources` option were set to `inactive`
using only `scripts/set-event-source-status.php` and its explicit `--ids` allowlist
carrying a frozen literal manifest. Exactly 18 source records changed; nothing
else changed. The two named fixture IDs (`g2_cavan_probe`, `g4c_budget_probe`)
were absent before and remain absent; they received no write and no code was
adapted to accommodate them.

## 2. Scope NOT completed

Nothing in the authorised scope was left undone. Stage 0.5 and Stages 1–5 were
**not** started (not authorised). No importer run, Run All, Import Now or test
suite was executed, by instruction.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/01-preflight-registry-inventory.json` | added | fresh raw read-only inventory (154 records, hashes) |
| `…/01b-getall-guard.json` | added | write-blocked proof that `get_all()` is a no-op |
| `…/02-manifest-18-ids.json` | added | frozen literal manifest |
| `…/03-pre-write-snapshot.json` | added | pre-write snapshot = rollback source of truth |
| `…/04-dry-run.txt`, `04b-dry-run.json`, `04c-dry-run-validation.json` | added | raw dry-run + machine plan + validation |
| `…/05-apply.txt` | added | raw apply output |
| `…/06-post-apply-registry.json`, `06-post-apply-verification.json`, `06-post-apply-verification-summary.txt` | added | fresh read + objective comparison |
| `…/07-idempotence-apply.txt` | added | identical second apply (`changed = 0`) |
| `…/08-post-idempotence-registry.json`, `08-post-idempotence-verification.json` | added | post-idempotence read + comparison |
| `…/09-final-gates.json`, `09-final-gates-summary.txt` | added | final numeric gates vs pre-write state |
| `…/10-content-untouched-verification.csv`, `.stderr` | added | post counts proving no content write |
| `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` | modified | revised 18-record interpretation, executed |
| `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` | modified | rewritten with actual results |

**18 added, 2 modified, 0 deleted** in the working tree (`preflight.json`,
`preflight-recheck.json` and `gate.json` are preserved untouched). There is **no**
`wp-content/`, `scripts/`, `tests/`, `plugins.json`, `compose.yaml`, `.env` or
`dist/` change.

## 4. Runtime impact

No application code changed, so no runtime behaviour changed. What changed is
**local data**: 18 source records now report `status = inactive`, so the importer's
`get_active()` will no longer select them. This was verified through the registry
itself, not by running an import.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** |
| No production content, Polylang or database change | **confirmed** |
| No application behaviour change (`wp-content/` diff is empty) | **confirmed** |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |

## 5. Content / data impact

| Object | Created | Updated | Deleted |
|---|---:|---:|---:|
| Event sources (`conexao_event_sources` records) | 0 | **18** (`status` only) | 0 |
| Posts / events | 0 | 0 | 0 |
| Post meta, terms, attachments, Polylang links | 0 | 0 | 0 |

The identifier strategy is the registry's own **source slug** (portable), never a
local post ID. Before/after comparison excludes only the 18 authorised `status`
fields; every other field of every record is compared through a SHA-256 of the
canonicalised non-status record, and the whole-registry non-status hash is
identical before and after (`1075c8a8be8933922d511aae4b4905d5fb07625ee7598c0ea6abd67183591fda`).

### The 18 target IDs, original and final status

| # | Source ID | Original | Final | Non-status fields identical |
|---:|---|---|---|---|
| 1 | `g1_probe_eventbrite_12a0be98` | active | **inactive** | yes |
| 2 | `g1_probe_eventbrite_1d540378` | active | **inactive** | yes |
| 3 | `g1_probe_eventbrite_217b99b8` | active | **inactive** | yes |
| 4 | `g1_probe_eventbrite_255d8e5d` | active | **inactive** | yes |
| 5 | `g1_probe_eventbrite_26089591` | active | **inactive** | yes |
| 6 | `g1_probe_eventbrite_337a55a7` | active | **inactive** | yes |
| 7 | `g1_probe_eventbrite_63e32fa4` | active | **inactive** | yes |
| 8 | `g1_probe_eventbrite_9695905a` | active | **inactive** | yes |
| 9 | `g1_probe_eventbrite_a7200d61` | active | **inactive** | yes |
| 10 | `g1_probe_eventbrite_f513d699` | active | **inactive** | yes |
| 11 | `g1_probe_heritage_week_0b81b474` | active | **inactive** | yes |
| 12 | `g1_probe_heritage_week_1a8e51f2` | active | **inactive** | yes |
| 13 | `g1_probe_heritage_week_3959e340` | active | **inactive** | yes |
| 14 | `g1_probe_heritage_week_502e0f5d` | active | **inactive** | yes |
| 15 | `g1_probe_heritage_week_5b8fe650` | active | **inactive** | yes |
| 16 | `g1_probe_heritage_week_79452265` | active | **inactive** | yes |
| 17 | `g1_probe_heritage_week_9f3deb1a` | active | **inactive** | yes |
| 18 | `g1_probe_heritage_week_e38a7b47` | active | **inactive** | yes |

**Records actually changed: 18.** All 18 were `active` before, so all 18 required
a change; 0 were already inactive.

### The two named fixture IDs

| ID | Before | After | Treatment |
|---|---|---|---|
| `g2_cavan_probe` | absent | **absent** | not a source record; synthetic runtime test fixture — no write, not created, no code changed |
| `g4c_budget_probe` | absent | **absent** | same |

## 6. Polylang impact

None. No translated type, taxonomy, language relationship, PT record, EN record,
cache key or completeness gate was written or read for writing. `conexao_county`
remains a shared taxonomy and was not touched.

## 7. Route / HTTP impact

None. No route, redirect, canonical, hreflang, sitemap entry or HTTP acceptance row
changed. No external HTTP request was made; no provider was contacted.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | local WordPress only (`http://localhost:8080`) |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | only the local source-status option was written |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| `git rev-parse HEAD` / `git status --short` | 0 | HEAD `274fc10`; branch `event-import-improvements`; tree clean at start |
| `docker compose ps` | 0 | `wordpress` and `db` already running and healthy — reused, not restarted |
| read-only registry reader (`wp eval-file`, `--skip-plugins --skip-themes`) | 0 | site `http://localhost:8080`; **154** records; all `active`; **18** anchored `^g1_probe_` (also 18 substring matches); both named fixtures absent; 0 `g18_probe_*` |
| `01b` `get_all()` write-blocked probe | 0 | 0 blocked write attempts; in-memory `get_all()` equals stored option → no self-heal |
| `set-event-source-status.php --status=inactive --ids=<18> --dry-run` | 0 | `selected=18, changed=0, unchanged=0, excluded=136, errors=0`; all `active → inactive` |
| `… --status=inactive --ids=<18> --json --dry-run` | 0 | plan count 18, plan == manifest, excluded 136, 0 excluded in manifest |
| `… --status=inactive --ids=<18> --apply` | 0 | `selected=18, changed=18, unchanged=0, excluded=136, errors=0` |
| post-apply read-only registry read | 0 | **154** records; `active=136, inactive=18`; whole-registry non-status hash **identical** |
| `… --status=inactive --ids=<18> --apply` (repeat) | 0 | `selected=0, changed=0, unchanged=18, excluded=136, errors=0` |
| post-idempotence read-only registry read | 0 | identical to post-apply (154; 136/18) |
| `mysql` read-only content metrics | 0 | 3638 events (all `publish`); 275 `stage32_export_test` events; 12 `g1_probe_` events; 2 G2/G4C probe events; **0** posts created or modified after `2026-11-14 20:33:00`; max `post_modified_gmt` `2026-11-14 20:13:51` |

### Numeric test results

In-process PHP suites: **not run** — prohibited in Stage 0 (Stage 0.5 log protection
is not yet authorised).

### HTTP acceptance

**Not run** — no request-visible change, and suite execution is prohibited here.

### Static analysis

**Not run** — no PHP/JS/CSS file changed. `git status` shows documentation and
evidence only.

### Script-contract results

**Not run** — prohibited here; no script was modified.

### Release / build results

**Not in scope.** No artifact, manifest or deployment change.

### Final numeric gates (vs pre-write inventory)

| Gate | Required | Actual |
|---|---:|---:|
| Target manifest records missing | 0 | **0** |
| Target records not `inactive` | 0 | **0** |
| Unauthorised source records changed | 0 | **0** |
| Non-status fields changed on target records | 0 | **0** |
| Source records created | 0 | **0** |
| Source records deleted | 0 | **0** |
| Total registry count delta | 0 | **0** |
| Named fixture IDs created | 0 | **0** |
| Idempotence (`changed` on second identical apply) | 0 | **0** |

Additional observed states after the operation: total 154; `active=136`,
`inactive=18`; `eventbrite`, `eventbrite_cavan`, `eventbrite_laois` and
`heritage_week` all still `active`; `g2_cavan_probe` and `g4c_budget_probe` still
absent; `g1_probe_` count still 18.

## 10. Failure proofs / negative tests

The preserved earlier preflight **is** the failure proof for the preflight rule: it
compared expected 20 against actual 18, returned `BLOCKED`, recorded the two
missing IDs and performed zero writes (`preflight.json`, `preflight-recheck.json`,
`gate.json` — unchanged). That output was never rewritten.

No deliberate breaking test was executed against the live registry, because the
task restricts test runs and forbids expanding scope; the discriminating power of
the gate is therefore demonstrated by that earlier fail-closed preflight rather
than by corrupting data here.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Registry records | 154 | 154 |
| `active` | 154 | 136 |
| `inactive` | 0 | 18 |
| Whole-registry non-status hash | `1075c8a8…91fda` | `1075c8a8…91fda` (identical) |
| Unauthorised source changes | — | 0 |
| Events (any status change) | 3638 `publish` | 3638 `publish`; 0 modified/created since 20:33 |

No code, route, template or asset changed, so there is no functional regression
surface. The pre-existing Polylang CLI notice (see §12) is unchanged.

## 12. Known pre-existing failures

- **Polylang CLI notice** — `$_SERVER['HTTP_HOST'] or $_SERVER['REQUEST_URI'] are required but not set` on stderr when the status script runs outside a web request. Pre-existing, CLI-only, does not affect the exit code or the registry; recorded verbatim in `04-dry-run.txt`, `05-apply.txt` and `07-idempotence-apply.txt`.
- **The two fixture IDs are not registry records** — structural, not drift: they are created inside test code. No amount of re-inventorying produces them, and Stage 0 correctly does not create them.

## 13. Limitations

- **No test suite was run**, by explicit instruction (Stage 0.5 not authorised); verification therefore rests on the inventory, snapshot, tool output, before/after comparison and numeric gates above.
- **Read-only helper scripts were used transiently** — a registry reader/hasher (`--skip-plugins --skip-themes`, raw `get_option`) and a comparison script. They were placed in the container `/tmp` and the host `/tmp`, **were never added to the repository**, and performed **zero writes**. The only write path that touched the registry was the mandated `scripts/set-event-source-status.php`.
- The `get_all()` self-heal no-op was proven for **this** dataset. A future dataset with a missing default/county source could make `get_all()` write; this task does not change that code and does not claim otherwise.
- `set_status()` writes the option once per changed source, so the apply performed 18 option writes rather than one batched write. This is the tool's documented behaviour; it is atomic per record and reported per record.
- Only the local registry was observed. Production is WordPress.com and was neither queried nor modified.

## 14. Evidence paths

All under `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/`:

| File | What it proves |
|---|---|
| `preflight.json`, `preflight-recheck.json`, `gate.json` | the earlier 20-record preflight failed closed with 0 writes (preserved, unmodified) |
| `01-preflight-registry-inventory.json` | fresh raw read-only inventory: 154 records, 18 G1, fixtures absent, per-record non-status hashes |
| `01b-getall-guard.json` | `get_all()` is a no-op in this dataset (0 write attempts blocked) |
| `02-manifest-18-ids.json` | frozen literal 18-ID manifest, uniqueness and exclusion checks |
| `03-pre-write-snapshot.json` | **rollback source of truth**: full sanitized record, original status and non-status hash per target; whole-registry hash map |
| `04-dry-run.txt` / `04b-dry-run.json` / `04c-dry-run-validation.json` | raw dry-run, machine plan and programmatic plan==manifest validation |
| `05-apply.txt` | raw apply output: `changed=18`, `errors=0` |
| `06-post-apply-registry.json` / `06-post-apply-verification.json` | fresh read and objective comparison against the pre-write inventory |
| `07-idempotence-apply.txt` | identical second apply reports `changed=0`, `unchanged=18` |
| `08-post-idempotence-registry.json` / `08-post-idempotence-verification.json` | the second apply changed nothing at all |
| `09-final-gates.json` | final 8 numeric gates = 0 (`PASS`) |
| `10-content-untouched-verification.csv` | event/post counts unchanged; 0 posts created or modified during the operation |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` | rewritten to record the revised interpretation: 18 existing G1 records are the write targets, the two absent fixture IDs require no write, the operation is 18 source-status changes |
| `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` | this report, rewritten with actual numbers |
| `docs/evidence/…/` | new evidence files listed in §14 |

No permanent product documentation changed; the tool and its contract already exist.

## 16. Rollback / recovery

Rollback restores **only** the previous `status` values (all 18 were `active`) and
uses the same local-only tool with explicit literal IDs:

```bash
php scripts/set-event-source-status.php --status=active \
  --ids=<only the manifest IDs whose current status differs from the snapshot> --dry-run
php scripts/set-event-source-status.php --status=active \
  --ids=<same list> --apply
```

`03-pre-write-snapshot.json` holds each original status and non-status hash; compare
before rolling back so that an unrelated change made by another actor after the
snapshot is detected and reported, not blindly overwritten. Rollback changes no
event, no other source field and no unrelated source. It does not restore anything
that was never changed by this operation.

## 17. Final status

**Final status: PASS**

All eight required numeric gates are 0, the second identical apply reported
`changed = 0`, both fixture IDs remain absent, and no unrelated source or event
record changed; the only limitation is that test suites were deliberately not run,
as Stage 0 requires.

_Last verified: 2026-11-14 by Event Importer Stage 0 probe-source deactivation (revised 18-record scope, executed)_
