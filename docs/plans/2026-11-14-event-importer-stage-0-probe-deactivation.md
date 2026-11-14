# Plan — Event Importer Stage 0 probe-source deactivation

| | |
|---|---|
| **Task title** | Event Importer Stage 0 probe-source deactivation |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `274fc10ab9c4bc4e26ee15ed077c80182e9e739a` — one commit after the brief's expected `464a353c8dc425d34cbf226668ffd889f8d4fec0`; the only extra commit is the earlier blocked-preflight plan/evidence/report (`274fc10`) |
| **Applicable skill(s)** | `wp-repository`, `wp-content-change` |
| **Plan status** | approved — **revised scope**, executed |

## 0. Revision history (why this plan changed)

The first version of this plan required a **20-record** manifest: the 18 existing
anchored `^g1_probe_` sources **plus** `g2_cavan_probe` and `g4c_budget_probe`.
That preflight ran and **correctly stopped with zero writes**: the local registry
contained the 18 G1 records but neither named ID. The earlier evidence
(`preflight.json`, `preflight-recheck.json`, `gate.json`) is preserved unchanged;
it is not rewritten to look as though the first preflight passed.

The owner then clarified the interpretation: `g2_cavan_probe` and
`g4c_budget_probe` are **synthetic runtime test fixtures**, created temporarily
inside test code (`tests/test-source-pagination-completeness.php:470-485` and
tests/test-request-budget.php:276-289). They are not configured source records and
are not Run All targets, so they were never write targets. The authorised
operation is therefore **the 18 existing `g1_probe_*` source records only**, and
their status becomes `inactive`. The two absent IDs stay absent.

## 1. Scope

Deactivate **exactly the 18 existing source records matching the anchored pattern
`^g1_probe_`** in the local `conexao_event_sources` registry option, by setting
that record's `status` field to `inactive`. The write uses only the existing
local-only tool `scripts/set-event-source-status.php` with the frozen literal ID
list passed through its `--ids` allowlist. Every other field of every record, and
every record outside the 18, is preserved byte-for-byte. The two named fixture IDs
receive **no write**. This completes Event Importer Stage 0 and nothing else.

## 2. Explicit non-goals

This task does **not**: create, register, seed or deactivate `g2_cavan_probe` or
`g4c_budget_probe`; create/delete/rename any source; change any other source
field (`name`, `url`, `type`, `county`, `region_labels`, `last_import`,
`last_import_status`, `events_imported`, `last_error`); deactivate `eventbrite`,
`eventbrite_cavan`, `eventbrite_laois`, `heritage_week` or any other legitimate
source; delete or modify any imported event, its post status, `_event_status`,
meta, taxonomies, attachments, featured images or Polylang links; touch the 275
`stage32_export_test` events or the probe-associated events; run an import, Run
All, Import Now, or any importer/test suite that could mutate operator logs;
contact an upstream provider; access production; or touch the Flutter/mobile
repository. Stage 0.5 and Stages 1–5 are out of scope.

## 3. Relevant authoritative documents

`AGENTS.md` owns repository scope and safety; `docs/engineering-standard.md` §§0,
5.2, 12 and 14 own the change-safety, content-change, git and definition-of-done
rules; `docs/content-model.md` owns event/source identity and the
`conexao_county`/`conexao_category`/`conexao_tag` policy; `scripts/README.md` owns
the supported status-tool contract; `docs/plugins/conexao-event-importer.md` owns
importer behaviour. The current task brief owns the revised 18-record scope.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`; `wp-content-change` |
| **Its preconditions** | Clean worktree recorded; local Docker stack inspected and reused; registry inventoried read-only; declared set of records with printed counts; rollback thought through before the write. |
| **Its guardrails that bind this task** | Portable source IDs only; dry-run first; snapshot before write; explicit `--apply`; idempotence proof; numeric gate; no production write; no live wildcard as the write selector. |
| **Its verification commands** | Read-only inventory; `set-event-source-status.php --dry-run`; `--apply`; second `--apply`; read-only registry comparison. |
| **Its evidence location** | `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` |

## 4. Current-state findings (fresh preflight)

Verified read-only against the running local stack on `http://localhost:8080`,
reading the **raw** option (plugins skipped, `Conexao_Event_Sources` never
instantiated):

| Check | Observed | Required | Result |
|---|---:|---:|---|
| Registry records | 154 | — | — |
| Statuses before | `active` 154 | — | — |
| IDs matching anchored `^g1_probe_` | 18 | 18 | PASS |
| IDs containing `g1_probe` anywhere | 18 | 18 (no other matches) | PASS |
| `g2_cavan_probe` | absent | absent | PASS |
| `g4c_budget_probe` | absent | absent | PASS |
| Distinct existing targets | 18 | 18 | PASS |
| Other source IDs selected | 0 | 0 | PASS |
| `g18_probe_*` records | 0 | 0 | PASS |
| `eventbrite` / `eventbrite_cavan` / `eventbrite_laois` | present, `active` | unchanged | PASS |

Tool-contract verification of `scripts/set-event-source-status.php`: local-only
(`production_capable => false`), dry-run by default, `--apply` required to write,
`--ids` is an explicit allowlist that wins over every other filter, changes only
`status` through `Conexao_Event_Sources::set_status()`, is idempotent, never runs
an import, never creates/deletes/renames a source, and touches no content.

**Self-heal guard.** `set_status()` → `get()` → `get_all()`, and `get_all()` can
self-heal (add missing default/county sources, repair a drifted `county`). A
write-blocked probe (`pre_update_option_conexao_event_sources` returning the old
value) proved `get_all()` is currently a **no-op**: 0 write attempts blocked, and
the in-memory result equals the stored option. Therefore the tool cannot change a
non-status field in this dataset.

## 5. Proposed approach

1. **Inventory (read-only)** — raw option, anchored selection, counts, hashes.
2. **Frozen manifest** — the 18 literal IDs, sorted, from the live registry; no
   wildcard is used at write time.
3. **Pre-write snapshot** — full sanitized record + original status + non-status
   hash per target, plus a non-status hash for every one of the 154 records.
4. **Dry-run** — documented default mode with the literal `--ids`.
5. **Apply** — identical arguments plus `--apply`.
6. **Verify + gate** — fresh read, objective before/after comparison excluding
   only the 18 authorised `status` fields; all eight gates must be 0.
7. **Idempotence** — repeat the identical apply; it must report `changed = 0`.

Rejected options: a `--exclude-prefix`/prefix selector for the write (a wildcard
could sweep a future record, contrary to the freeze requirement); creating the two
fixture IDs so a 20-record manifest becomes satisfiable (explicitly forbidden);
direct database writes (forbidden); running an import to "prove" the effect
(forbidden); removing the probe sources or their events (forbidden).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/*` | new evidence files | inventory, manifest, snapshot, dry-run, apply, verification, idempotence, gates, content proof |
| `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` | revised + executed | record the corrected 18-record interpretation |
| `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` | rewritten | record actual results and numbers |

No repository code, test, script, plugin registry, configuration or content file
changes.

## 7. Files explicitly expected NOT to change

`wp-content/**` (no application behaviour change), `scripts/**` (the existing tool
is used, not modified), `tests/**` (no suite is run), `plugins.json`,
`compose.yaml`, `.env`, `dist/**`. The earlier evidence files `preflight.json`,
`preflight-recheck.json` and `gate.json` are preserved unchanged.

## 8. Content / data impact

- Records created / updated / deleted: **0 created, 18 updated (status only), 0 deleted**; **0 posts** touched.
- Portable identifier strategy: the WordPress source **slug** (`g1_probe_*`), the registry's own stable key — never a local post/attachment ID.
- Does this write content? It writes **one option** (`conexao_event_sources`), not content. The §5.2 six-step contract is applied in full anyway (inventory → manifest → dry-run → snapshot → idempotent apply → verify + gate).
- PT content changed? **No.** No post, term, meta or language relationship is written.

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**.
- Redirects: **none**.
- Sitemap coverage: **unchanged**.
- HTTP matrix rows: **none** — no request-visible behaviour changes; no external request is made.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- PT immutable: **yes** — no PT or EN record is read for writing or modified.
- B1/B2: **not applicable**.
- Canonical, hreflang, language switcher: **unchanged**.
- Language-scoped cache keys: **none touched**.
- Completeness gate: **not applicable** (not an English rollout).

## 11. Security impact

- Capabilities checked: **none** — this is a CLI script run locally, not a request.
- Nonces added/changed: **none**.
- Sanitisation / escaping: input is a frozen literal allowlist of source slugs; the tool lowercases/trims `--ids` and compares against existing keys, so only existing records can match.
- SQL: **no new SQL**; the tool uses WordPress option APIs and the plugin's `set_status()`.
- REST surface change: **none**.
- Secrets involved: **none**. The snapshot redaction pass found 0 sensitive fields (`redacted_fields: []`); no credential is printed or stored.

## 12. Performance impact

- Queries added/removed in production requests: **none**.
- Caching: no transient or asset change.
- Expected effect: the next local Run All selects **18 fewer** active sources (10 Eventbrite Laois probes + 8 Heritage Week probes), removing 18 duplicate feed walks. This is a consequence of the data change; it is **not** measured by running an import, which is out of scope.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | Nobody; the operation is local-only. |
| What is the verification of the production result? | Not applicable. |

## 14. Test plan

No test suite is run. The task restriction forbids the importer suite, the full
repository suite, the PHP integration suite, the browser suite, and any test
script that could mutate operator logs, because Stage 0.5 log protection is not
yet authorised. Verification is: read-only inventory, frozen explicit manifest,
pre-write snapshot, the existing tool's dry-run, its apply output, read-only
registry comparison, idempotence proof and numeric gates. Pass is numerically
defined: all eight gates = 0 and the second apply reports `changed = 0`.

## 15. Acceptance matrix plan

No request-visible behaviour changes, so no HTTP acceptance rows apply.

## 16. Rollback plan

The pre-write snapshot
(`03-pre-write-snapshot.json`) records each target's original `status` (all 18
were `active`) and a non-status hash. Rollback restores **only** those statuses,
with the same local-only tool and the explicit literal IDs, for records whose
current status differs from the recorded original:

```bash
php scripts/set-event-source-status.php --status=active \
  --ids=<only the IDs whose status differs from the snapshot> --dry-run
php scripts/set-event-source-status.php --status=active \
  --ids=<same list> --apply
```

Rollback changes no event, no other source field and no unrelated source. It
**cannot** undo an unrelated registry change made by another actor after the
snapshot; detect and report such drift before rolling back.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| Plan, evidence and report named above | Record this one local operation | `wp-repository`, `wp-content-change` |

No permanent product documentation changes: the tool and its contract already
exist.

## 18. Release implications

No component version, artifact, manifest or deployment change. **No release is in
scope.**

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Registry differs from the authorised 18 (drift, extra probe) | Medium | Fail closed before any write; report the exact discrepancy. |
| Scope expands through prefix selection | Low | Freeze literal IDs; the write uses `--ids` only. |
| A non-status field changes through `get_all()` self-heal | Low | Write-blocked `get_all()` probe proved a no-op; post-write non-status hashes compared. |
| An unrelated source changes | Low | Objective whole-registry before/after comparison; gate must be 0. |
| Scope creeps into Stage 0.5 / 1–5 or test runs | Medium | Explicit non-goals; no suite is run; only the status tool writes. |

## 20. Verification gates

| # | Gate | Command / source | Required |
|---|---|---|---:|
| 1 | Target manifest records missing | `09-final-gates.json` | 0 |
| 2 | Target records not `inactive` | `09-final-gates.json` | 0 |
| 3 | Unauthorised source records changed | `09-final-gates.json` | 0 |
| 4 | Non-status fields changed on target records | `09-final-gates.json` | 0 |
| 5 | Source records created | `09-final-gates.json` | 0 |
| 6 | Source records deleted | `09-final-gates.json` | 0 |
| 7 | Total registry count delta | `09-final-gates.json` | 0 |
| 8 | Named fixture IDs created | `09-final-gates.json` | 0 |
| 9 | Idempotence: identical second apply | `07-idempotence-apply.txt` | `changed = 0` |

## 21. Completion criteria

Every gate in §20 is 0; the manifest is frozen and literal; the snapshot and its
hashes exist and make rollback possible; only the 18 authorised `status` values
changed; no source or event was created, deleted or otherwise modified; both named
fixture IDs remain absent; the report states real numbers and limitations; no
production, importer, test-suite, publication or mobile action occurred.

_Last verified: 2026-11-14 by Event Importer Stage 0 probe-source deactivation (revised 18-record scope, executed)_
