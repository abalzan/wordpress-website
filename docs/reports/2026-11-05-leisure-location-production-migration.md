# Report — Stage 9: Lazer location **production migration** (controlled write)

> Written from [`docs/templates/report.md`](../templates/report.md). Machine
> evidence lives in
> [`docs/evidence/2026-11-05-leisure-location-stage-9/`](../evidence/2026-11-05-leisure-location-stage-9/);
> this document is the narrative that cites it.

| | |
|---|---|
| **Stage / task name** | Stage 9 — controlled production migration of the Lazer location dataset (coordinates + attribute taxonomy) with the existing `scripts/migrate-leisure-location-rest.py` |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `2aef58f8f5a52b0cbe4b805c81ad5bf100eb1006` (Stage 9A preflight); HEAD moved to `83082edb3ff0a79616ee0b83e1e12ea234774965` at 18:25:30Z by an **operator commit** — see §1.3 |
| **Final SHA** | uncommitted — committing and pushing are explicitly out of scope for this stage |
| **Working tree at finish** | dirty by design: this evidence directory, this report and its two index rows. No other file. |
| **Production writes** | **yes — 578 POSTs on 289 existing records, explicitly authorised at the Stage 9D checkpoint** (see §5 and `18-approval-checkpoint.txt`) |
| **Migration applied** | **yes** — `--apply --confirm-production` for the coordinate phase, then `--apply --confirm-production --include-attributes` for the attribute phase |
| **Skills followed** | `wp-production-operations`, `wp-testing`, `wp-repository`, `wp-security-review`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §2, §5.2, §7, §8, §9, §14), `docs/testing.md`, `docs/releases.md` (§Production constraints, §Rollback), `docs/plans/2026-11-05-leisure-location-production-migration.md`, `docs/reports/2026-11-05-leisure-location-production-migration-dry-run.md` (Stage 8), `docs/reports/2026-11-05-leisure-location-production-rest-permission-verification.md` (Stage 7), `docs/reports/2026-11-05-leisure-location-production-audit.md`, `docs/content-model.md`, `plugins.json`, `scripts/README.md`, `docs/templates/report.md`, `docs/evidence/README.md` |

## 0. Final verdict

**`PASS — migration applied to production, independently verified, no deletion, no
conflict, no record outside the verified set touched.`**

| Measure | Expected | Measured 2026-11-05 |
|---|---:|---:|
| Published production leisure records | 289 | **289** (0 created, 0 deleted) |
| Records now carrying `_leisure_lat` + `_leisure_lng` | 289 | **289** |
| Coordinate POSTs issued | 289 | **289** |
| Attribute POSTs issued | 289 (corrected from the plan's 232) | **289** |
| Attribute assignment relationships | 592 | **592** (all 13 terms, no term created or deleted) |
| Records whose attribute set changed | 232 | **232** |
| Conflicts / unresolved | 0 / 0 | **0 / 0** |
| Removals | 0 | **0** |
| Public dataset items with `lat`+`lng` | 289 | **289** |
| Independently verified coordinate mismatches | 0 | **0** |
| Independently verified attribute mismatches | 0 | **0** |
| Records whose `modified_gmt` moved | the 289 in scope | **289, and nothing else** |

The write went through the existing
`scripts/migrate-leisure-location-rest.py` — unchanged, with its own journal,
gates, rate-limit backoff and refusal flags — after a fresh inventory, three
dry-runs that wrote nothing (`identical=True` before/after), and an explicit
operator authorisation at the Stage 9D checkpoint.

Two limitations are disclosed rather than smoothed over, and both were accepted
before the write: **provenance keys cannot cross the REST boundary** (the admin
will keep showing the coordinates as *"por verificar"*), and the 578 POSTs
**permanently moved `post_modified` and created revisions** on all 289 records.

## 1. Scope completed

| Requested by the task (Stage 9A–9F) | Done | Evidence |
|---|---|---|
| 9A — record branch/HEAD/worktree, staged/unstaged/untracked, stash, Stage 7/8 artefacts, script/test integrity, script `--help` and implementation paths | yes | `01-preflight.txt`, `16-stage8-evidence-unchanged.txt`, `17-repository-state-change.txt` |
| 9B — **fresh** read-only production inventory and per-record match against the current local source | yes | `02-verification-pre.txt`, `production-inventory-pre.json`, `local-inventory.json` |
| 9B — coordinate and attribute validation, record by record, independently | yes | `03-independent-reconciliation-pre.txt` (35/35 checks), `coordinate-plan-pre.json`, `attribute-plan-pre.json` |
| 9C — final dry-run (coordinates, then attributes via `--include-attributes --source-url`), repeat for determinism | yes | `04`–`06`, `dryrun-01/02/03*.json`, `13-dryrun-safety.txt` |
| 9C — zero-write proof and row-for-row cross-check of the printed plan | yes | `07-verification-post.txt` (`identical=True`) |
| 9D — approval checkpoint, explicit authorisation | yes | `18-approval-checkpoint.txt`, §5 |
| 9E — controlled apply through the existing script and its journal | yes | `19-apply-preflight.txt`, `22-apply-01-coordinates.txt`, `23-apply-02-attributes.txt` |
| 9F — fresh post-migration verification, all 289 records, independently | yes | `24-verification-post-migration.txt`, `25-independent-post-reconcile.txt` |
| Tests and governance gates | yes | `09-mock-suite.txt`, `10-script-contract-suite.txt`, `11`/`12` failure proofs |

### 1.1 The migration script was not modified

`scripts/migrate-leisure-location-rest.py` is byte-identical to `HEAD` and to the
Stage 8 report: sha256 `f07e5cca6656eed1efb19bf3ecb44a20f61f483dd301b9d49521cbbfd29ded9c`.
Same for `scripts/lib/rest.py` (`ef67c35f…`), the mock suite
(`26ceedbb…`), the coordinate manifest (`9214e030…`) and the approved plan
(`dcc5a8e8…`). No safeguard was bypassed, no test weakened, no gate relaxed.

### 1.2 The same script and the same flags

Every production request in this stage came from
`scripts/migrate-leisure-location-rest.py`: `GET` for reads and `POST` on an
existing record id for the two write phases. No substitute REST request was
issued by hand, and the file contains **no** `DELETE`/`PUT`/`PATCH`
(`13-dryrun-safety.txt` §3).

### 1.3 Repository state changed during Stage 9A (recorded, not caused by this stage)

At the first Stage 9A command HEAD was `2aef58f`. At 18:25:30Z — while Stage 9A
was still capturing evidence — the operator committed the Stage 8 work as
`83082ed` (*Add report for Stage 8: Lazer location migration production dry-run*).
That commit is **docs only** (21 files, all under `docs/`), and it also swept in
the two Stage 9 files that already existed at that instant
(`01-preflight.txt`, `stage9-pre-migration-verify.py`); their content is
unchanged.

This stage made **no** commit, push, reset, clean, checkout/restore, amend or
stash operation (9 stash entries before and after). The change was assessed
against the task's abort list — it touches **no** script, plan, source data,
test or safeguard — so it is recorded as a discrepancy, not a blocker. Full
capture: `17-repository-state-change.txt`.

### 1.4 A note on evidence file extensions

`.gitignore:19` ignores `*.log`, so six of the Stage 8 evidence log files are not
tracked by its commit. Stage 9 therefore stores its evidence as `.txt` so that
every file this report cites is trackable (`17-repository-state-change.txt`,
last block).


## 2. Scope NOT completed

Nothing requested was skipped. Four things are **out of scope by design** of the
approved plan and remain undone, deliberately:

| Not done | Why |
|---|---|
| `_leisure_coord_source` / `_leisure_coord_verified` | Registered `show_in_rest => false`; they cannot cross the REST boundary. Confirmed live: the authenticated record's exposed `meta` keys do not include either (`14-endpoint-probe.txt` §6). The admin "Coordenadas" column will therefore keep reporting *"por verificar"*. Not worked around — there is no supported way to write them from this repository. |
| Titles, slugs, excerpts, featured images, image source URLs, `_leisure_excerpt_en` | Not location data. Each is a separate content change with its own plan (`docs/plans/…` §10). |
| Creating the 4 CI-fixture records or `phoenix-park-en` on production | Test doubles, not attractions. They are the 5 expected `local_only` slugs. |
| Any deletion or cleanup | Forbidden by the task; the script has no delete path at all. |

## 3. Files added / modified / deleted

**1 added (this report), 49 added (evidence), 2 modified (indexes), 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-11-05-leisure-location-production-migration.md` | added | this report |
| `docs/evidence/2026-11-05-leisure-location-stage-9/` | added | 49 files: preflight, fresh inventories, per-record plans, four dry-run reports, apply reports + logs, post-migration verification, two read-only verifiers, failure proofs, gate accounting — see §14 |
| `docs/reports/README.md` | modified | one index row + `_Last verified_` line |
| `docs/README.md` | modified | one index row + `_Last verified_` line |

**No file under `wp-content/` was created or modified** — no plugin, no theme, no
PHP. No script, test, manifest, plan or `.env` change. `.local/` (the migration
journal) is git-ignored by design and is not part of this change.

## 4. Runtime impact

| Check | Result |
|---|---|
| Application behaviour change (`wp-content/` diff) | **empty** — no code ships in this stage |
| Code release / deploy / upload / activation | **none** |
| Content behaviour change | **yes, by design**: the Lazer proximity feature now has latitude/longitude for all 289 published attractions and the practical-attribute taxonomy is complete |

The front end reads `_leisure_lat`/`_leisure_lng` through
`Conexao_Data_Model_Meta::get_coordinates()` and the public dataset
`/wp-json/conexao/v1/leisure-coordinates`; both were measured before and after
(§9, §10). Any change in the public payload is therefore **data**, not code.

### 4a. Scope integrity

| Check | Result |
|---|---|
| Production write | **yes — 578 POSTs, explicitly authorised** (the only authorised production action in this stage) |
| Deploy / upload / activation | no |
| Production content change outside the migration | **none** — verified record by record (§10) |

## 5. The approval checkpoint and the authorisation actually received

Stage 9D was executed in full before any write: verification results and dry-run
reports were saved, a proposed-execution summary was produced
(`18-approval-checkpoint.txt`), and the run **stopped** and asked the operator.

The authorisation received was:

> **"Yes — authorise the full Stage 9 migration (289 coordinate POSTs + 289
> attribute POSTs on https://conexaobr.ie/)"**

That is an explicit, migration-specific authorisation for a production write on
`https://conexaobr.ie/`, covering **both** the coordinate meta and the attribute
taxonomy. Nothing else was treated as authorisation: not the Stage 8 completion,
not the dry-run, not the report's "safe to consider" finding, and not silence.

Re-confirmed immediately before applying (`19-apply-preflight.txt`):

| Re-confirmation | Result |
|---|---|
| Production identity | `name: Conexão BR`, `home: https://conexaobr.ie`, `conexao/v1` present |
| Authenticated identity | user id `283039558`, `conexaobradmin`, `administrator: true`, `edit_others_posts: true`, `publish_posts: true` |
| Inventory still as verified | 289 records, 0 coordinates, 173 assignments, 13 terms |
| Delay since the dry-run | 18 minutes → the dry-run **and** the independent reconciler were re-run (`20`, `21`) |
| Journal / recovery ready | `.local/` absent and git-ignored; the script creates the journal on the first write |
| Abort conditions | all false (§12) |

### 5.1 Corrected migration accounting (the plan's gate 5 is wrong)

The approved plan's §11 gate 5 says `--apply --include-attributes` →
`attributes_written = 232`. **That is not what the script does**, and this stage
does not edit the approved plan to hide it. Stage 8 §5 found the defect; Stage 9
re-measured it (`15-corrected-gate-accounting.txt`):

| Attribute metric | Correct value |
|---|---:|
| Records receiving a full-set attribute POST | **289** |
| Records whose assignment set actually changes | 232 |
| Total intended assignments | 592 |
| Missing assignments | 419 |
| Existing assignments | 173 |
| Removals | 0 |

`plan_attributes()` builds a row for every record with at least one resolvable
local term and **never compares it to the target's current terms**; the write
sends the full local set. So 57 of the 289 POSTs re-send a set that is already
correct — idempotent in data, but a real request, a real `post_modified` bump and
a real revision each. The apply-time gate used here is therefore
`attributes_written = 289`, not 232.

## 6. Content / data impact

| Item | Before | After |
|---|---:|---:|
| Published production `leisure` records | 289 | 289 (0 created, 0 deleted) |
| Records with `_leisure_lat` **and** `_leisure_lng` | 0 | **289** |
| Half-filled coordinate pairs | 0 | 0 |
| Attribute assignment relationships | 173 | **592** |
| Records carrying ≥ 1 attribute | 57 | **289** |
| Distinct attribute terms in use | 13 | 13 (no term created or deleted) |
| Removals | — | **0** |

**Match strategy:** slug, 289/289. Slug is the only portable key production
exposes for these records; it was verified unique on both sides (289 distinct
slugs in each environment, 0 duplicates), and the target's own post id is used
only as the write address *within* production. No local post id was used as
cross-environment identity.

**Written fields, and only these:** `_leisure_lat`, `_leisure_lng` and the
`conexao_leisure_attribute` term set. No title, slug, status, date, author,
excerpt, content, featured image, other meta key, other taxonomy or Polylang
relationship was written.

## 6a. Polylang impact

**0.** No `lang`, no translation group, no term language and no taxonomy
translation was written — the migration has no code path for them. PT records
keep their identity: the apply changed meta and one taxonomy's assignments only,
and every record kept its slug (`25-independent-post-reconcile.txt`:
`every production id is unchanged`).

## 7. Route / HTTP impact

No route, rewrite, redirect, canonical, hreflang or sitemap change: the theme and
the plugins are untouched (§4). The observable HTTP effect is **data in an
existing endpoint**:

| Endpoint | Before | After |
|---|---|---|
| `GET /wp-json/conexao/v1/leisure-coordinates?lang=pt` | `count: 289`, **0** items with a non-null `lat`/`lng`, body sha256 `a394b7b8…5aa4` | `count: 289`, **289** items with `lat`+`lng`, 0 neither, 0 half-filled, body sha256 `a16f81b9…c994` (cache-busted) |
| `GET /wp-json/wp/v2/leisure?per_page=1` | `X-WP-Total: 289` | 289 (unchanged) |
| `GET /wp-json/wp/v2/conexao_leisure_attribute` | 13 terms, 173 assignments | 13 terms (ids 1465–1477, unchanged), **592** assignments |
| `GET /wp-json/wp/v2/leisure/10551` | `modified_gmt 2026-11-05T15:46:00`, `_leisure_lat ''` | `modified_gmt 2026-11-05T19:19:40`, `_leisure_lat '53.141559'`, `_leisure_lng '-6.569674'` |

**A note on field names, so this number is not misread:** the dataset's items
carry `id`, `index`, `lat`, `lng`
(`wp-content/themes/conexao-br-irlanda/inc/leisure-location.php:331-333`), *not*
`latitude`/`longitude`. A probe that looks for `latitude` reports "0 items with
coordinates" on a fully migrated site. The first Stage 9 post-migration probe did
exactly that; it was corrected against the theme source and re-measured, and the
corrected reading is the one above (`27-post-migration-endpoint.txt` §1).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **yes** | `POST /wp-json/wp/v2/leisure/<id>` on the 289 verified records only: coordinate phase (`meta: {_leisure_lat, _leisure_lng}`), then attribute phase (`conexao_leisure_attribute: [term_ids]`) |
| Deploy / upload | **no** | — |
| Plugin activation / deactivation | **no** | `conexao-data-model` 1.6.3 is already active; it is what makes the write permitted |
| Theme change | **no** | — |
| Content or DB mutation outside the migration | **no** | no record created, deleted, renamed or re-statused; no term created or deleted |
| Polylang change | **no** | — |
| SQL / WP-CLI / filesystem on production | **no** | production is WordPress.com: no SSH, no WP-CLI, no filesystem, no database. Everything went through the REST API |
| Rollback performed | **no** | not needed; the apply completed and verified |

**Requests issued to production in this stage:** reads (`GET`) for the
inventories, the per-record plans, three dry-runs and the post-migration
verification; writes (`POST`) only in §9. Every request was made by
`scripts/migrate-leisure-location-rest.py`; the one exception is the read-only
endpoint probe (`14-endpoint-probe.txt`), which issues `GET` only.

### 8.1 Credential handling

`WP_USERNAME` / `WP_APPLICATION_PASSWORD` were loaded from the repository-root
`.env` — git-ignored (`.gitignore:2`) and untracked (`git ls-files .env` → 0
files) — into the process environment for each command. The values were never
printed, never placed on a command line, never written to a log, report or
evidence file, and `.env` was not modified or created. `01-preflight.txt` §8
records variable **names** only. A scan of every Stage 9 evidence file for each
credential value and for a rendered `Authorization: Basic` header found **0**
occurrences. No credential was created, rotated or requested.

### 8.2 Rate limiting


## 9. Verification commands and results

### 9.1 Stage 9A — preflight

| Command | Exit | Result |
|---|---|---|
| `git branch --show-current`, `git rev-parse HEAD`, `git status --porcelain=v1`, `git stash list` | 0 | `leisure-location`, `2aef58f…`, only the Stage 8 artefacts dirty, 9 stash entries (`01-preflight.txt`) |
| `sha256sum scripts/migrate-leisure-location-rest.py …` | 0 | `f07e5cca…ded9c` — identical to the Stage 8 report |
| `git diff HEAD --stat -- scripts/ tests/` | 0 | empty — no script or test change since HEAD |
| `python3 scripts/migrate-leisure-location-rest.py --help` | 0 | dry-run is the documented default; `--apply` is the only write mode; `--confirm-production` is required for a production apply |
| `sha256sum docs/evidence/2026-11-05-leisure-location-stage-8/*` (before/after) | 0 | 22 files, **identical** — no Stage 8 evidence file was added, modified or deleted (`16-stage8-evidence-unchanged.txt`) |

### 9.2 Stage 9B — fresh production inventory and reconciliation

| Command | Exit | Result |
|---|---|---|
| `stage9-pre-migration-verify.py --phase pre` | **0** | `production: 289 records, 289 distinct ids, 289 distinct slugs, 0 with coordinates, 57 with attributes (173 assignments, 13 terms)`; `local: 294 records, 289 with coordinates, 592 assignments`; `matched 289`, `production_only=[]`; `coordinates: manifest=294 eligible=289 actions={'POPULATE': 289} mismatches=0`; `attributes: local=592 production=173 missing=419 production_only=0 records_changing=232 plan_records=289 planned_assignments=592 unresolved=[]` (`02-verification-pre.txt`) |
| `stage9-independent-reconcile.py --phase pre` | **0** | **35 checks, 0 failed** (`03-independent-reconciliation-pre.txt`) |

The independent reconciler is deliberately *not* the Stage 8 verifier: it
re-derives the expectation from the local source and the manifest and compares it
to the plan the script printed, one record at a time. Its 35 checks include:
289 records / 289 distinct ids / 289 distinct slugs / all `publish`; 0
production-only records; the 5 expected `local_only` slugs and nothing else; 289
coordinate rows all `POPULATE`/`slug`; every planned value equal to the local
database **and** to the manifest row; every planned target id equal to the
production id; 289 distinct target ids; 0 `UPDATE`, 0 `CONFLICT`, 0 `UNRESOLVED`;
289 attribute rows; production term set a **subset** of the local set on 289/289
records; 0 production-only assignments; 0 unresolved term slugs; 592 planned
assignments; 232 changing records; 289 full-set POSTs; 13 distinct terms; all 13
resolving to production ids 1465–1477; and a per-record check that every planned
term id resolves back to exactly the local term-slug set.

### 9.3 Stage 9C — final dry-run with the existing script

```bash
# coordinates
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --dry-run \
  --report docs/evidence/2026-11-05-leisure-location-stage-9/dryrun-01-coordinates.json

# attributes (the opt-in phase; the local site is the source, so it is named explicitly)
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --source-url http://localhost:8080 \
  --dry-run --include-attributes \
  --report docs/evidence/2026-11-05-leisure-location-stage-9/dryrun-02-coordinates-attributes.json
```

| Command | Exit | Result |
|---|---|---|
| coordinates dry-run | **0** | `populate=289, conflicts=0, unresolved=0, written=0, gate=0, api_requests=6` |
| attributes dry-run | **0** | `populate=289, conflicts=0, unresolved=0, written=0, attributes_planned=289, attributes_written=0, gate=0, api_requests=7` |
| the same attributes dry-run again (repeat) | **0** | `cmp` exit 0 — **byte-identical**, sha256 `83de2f1c…b3242`, the same digest Stage 8 produced (`13-dryrun-safety.txt` §7) |
| `stage9-pre-migration-verify.py --phase post --baseline production-inventory-pre.json --report dryrun-02…json` | **0** | `plan rows 289, attribute rows 289, coordinate mismatches 0, attribute mismatches 0, missing rows 0`; **`write proof: before=289 after=289 changed=0 added=0 removed=0 identical=True`** (`07-verification-post.txt`) |

**No write flag was ever passed in a dry-run** (`13-dryrun-safety.txt` §2): no
`--apply`, no `--confirm-production`, no `--overwrite`, no `--no-resume`. The two
write helpers are called only inside `if mode == "apply"`, the file contains no
`DELETE`/`PUT`/`PATCH`, and `.local/` was never created by a dry-run.

### 9.4 Local source environment

The dry-run's source is the Docker WordPress at `http://localhost:8080`
(`name: Conexão BR`, `home: http://localhost:8080`), 294 published `leisure`
records: the 289 that match production plus the 4 CI fixtures and
`phoenix-park-en`, which the manifest excludes
(`manifest_skipped={'OWNER_INPUT_REQUIRED:NOT_FOUND': 4, 'REVIEW:MEDIUM': 1}`).
The compose stack was verified up before every run (`01-preflight.txt` §6).

WordPress.com returned `HTTP 429` while reading (the verifier logs the wait and
retries) and while writing (the script's client honours `Retry-After` with
bounded backoff and journals each successful write, so a throttle can never
corrupt the run). Retries are recorded in the apply reports as `api_retries`.
No request was abandoned, forced or retried around a failure.

### 9.5 Stage 9E — the controlled apply

```bash
# phase 1 — coordinates
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --apply --confirm-production \
  --report docs/evidence/2026-11-05-leisure-location-stage-9/apply-01-coordinates.json

# phase 2 — the same coordinates (now idempotent) plus the attribute taxonomy
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --source-url http://localhost:8080 \
  --apply --confirm-production --include-attributes \
  --report docs/evidence/2026-11-05-leisure-location-stage-9/apply-02-coordinates-attributes.json
```

| Phase | Window (UTC) | Exit | Script summary |
|---|---|---|---|
| coordinates | 18:45:39 → 19:10:08 | **0** | `populate=289, written=289, conflicts=0, unresolved=0, with_before=0, with_after=289, gate=0, api_requests=297, api_retries=2` |
| attributes | 19:10:08 → 19:22:18 | **0** | `populate=0, already_set=289, written=0, attributes_planned=289, attributes_written=289, conflicts=0, unresolved=0, with_before=289, with_after=289, gate=0, api_requests=296, api_retries=0` |

**Total: 578 POSTs** — 289 coordinate writes and 289 attribute writes on 289
distinct target ids. The `already_set=289, written=0` in phase 2 is the
coordinate phase proving itself idempotent: it did **not** re-write a single
coordinate, so no record received a second coordinate POST.

`gate=0` in both phases is the script's numeric gate
(`with_after − (with_before + written)`); `written = planned_writes` in phase 1.

The journal was written after every POST (atomic write-then-rename): 289
coordinate entries with 289 distinct target ids, 289 attribute entries whose term
id counts sum to 592. No run was interrupted, nothing was resumed, no write
failed, and no scope was expanded after an error.


## 10. Post-migration verification (Stage 9F)

Performed **after** the apply, as a fresh read-only sweep — not a reuse of the
apply's own numbers.

### 10.1 Independent reconciler (`26-independent-post-reconcile.txt`) — 14/14 checks

| Check | Result |
|---|---|
| production records == 289 | PASS — 289 |
| no production record was created or removed | PASS — added `[]`, removed `[]` |
| records in scope == 289 | PASS — 289 matched by slug |
| production coordinate pairs == 289 | PASS — 289 |
| missing coordinates == 0 | PASS — 0 |
| half-filled pairs == 0 | PASS — 0 |
| **every coordinate equals the local source** | PASS — 0 mismatches |
| every production id is unchanged | PASS — 0 id changes |
| production assignment relationships == 592 | PASS — 592 |
| **every record's attribute set equals the local source set** | PASS — 0 differing records |
| no production-only term survived (nothing was removed) | PASS — 0 records |
| every production term slug is a local term slug | PASS — production-only slugs `[]` |
| attribute taxonomy unchanged (no term created or deleted) | PASS — 13 slugs before and after |
| `modified_gmt` moved on exactly the records in scope | PASS — moved 289, in scope 289, outside scope moved `[]` |

### 10.2 Verifier sweep (`25-verification-post-migration.txt`)

```
production:  289 records, 289 distinct ids, 289 distinct slugs, 289 with coordinates,
             289 with attributes (592 assignments, 13 terms)
local:       294 records, 289 with coordinates, 289 with attributes (592 assignments, 14 terms)
matching:    289 matched by slug, local_only=[4 CI fixtures + phoenix-park-en], production_only=[]
coordinates: actions={'ALREADY_SET': 289}  manifest/local mismatches=0
attributes:  local=592 production=592 missing=0 production_only=0 records_changing=0
PASS: every Stage 8 check holds
```
### 10.3 Live endpoints, anonymous, cache-busted (`27-post-migration-endpoint.txt`)

| Check | Result |
|---|---|
| `/wp-json/conexao/v1/leisure-coordinates?lang=pt&_cb=…` | `count 289`, `items 289`, **289 with `lat`+`lng`**, 0 with neither, 0 half-filled |
| Public payload sample | `{"id": 21197, "index": 0, "lat": 52.961976, "lng": -6.564829}` |
| `trinity-college-book-of-kells` (id 10146) | `_leisure_lat '53.34394'`, `_leisure_lng '-6.2568'`, 2 terms, `modified_gmt 2026-11-05T19:22:08` |
| `russborough-house` (id 10551) | `_leisure_lat '53.141559'`, `_leisure_lng '-6.569674'`, 2 terms, `modified_gmt 2026-11-05T19:19:40` |
| `dwyer-mcallister-cottage` (id 21197) | `_leisure_lat '52.961976'`, `_leisure_lng '-6.564829'`, 4 terms, `modified_gmt 2026-11-05T19:10:21` |
| Attribute term counts | 13 terms, sum of `count` = **592**, and the per-term counts equal the local source exactly (`exterior` 194, `gratuito` 115, `familias` 94, `interior` 59, `estacionamento` 45, `interior-exterior` 35, `necessita-reserva` 22, `pago` 13, `gratuito-em-determinadas-condicoes` 6, `acesso-de-transporte-publico` 3, `pet-friendly` 3, `acessivel` 2, `bicicleta` 1) |
| `_leisure_coord_source` / `_leisure_coord_verified` exposed | `False` / `False` — the provenance limitation is confirmed, not assumed |

### 10.4 Operational verification

| Item | Result |
|---|---|
| Journal completed | **yes** — `.local/leisure-location-migration-state.json`: 289 coordinate entries, 289 distinct target ids, 289 attribute entries, 592 term ids |
| Actual write counts vs expected | coordinate POSTs **289/289**, attribute POSTs **289/289**, target records **289/289** |
| Errors | none; **0** failed requests, **0** aborted writes |
| Retries / rate limiting | coordinate phase `api_requests=297, api_retries=2`; attribute phase `api_requests=296, api_retries=0`; `HTTP 429` honoured with `Retry-After` |
| Deletes / writes outside scope | **0** — `added=[] removed=[]`, nothing outside the 289 moved |
| `post_modified` | moved on **all 289** records and on nothing else; not restored |
| Revisions | **measured, not the estimated ~578**: 1 revision per record in 4 of 5 sampled records, 2 for `dwyer-mcallister-cottage` — WordPress reuses the last revision when the post fields are unchanged, so the two POSTs usually collapse into one revision (`27-post-migration-endpoint.txt` §5). Neither deleted nor altered. |
| Anonymous reads | verified anonymously with cache-busting, so no CDN copy is trusted |

## 11. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate. Both of these ran against
**copies** of the Stage 9 plan JSON in `/tmp`, with production read-only.

| Proof | Tampering | Result |
|---|---|---|
| `11-failure-proof-a.txt` | one coordinate row's planned latitude changed to `99.0` | **exit 1**, 3 checks FAIL: *value equals the local database* (1), *value equals the manifest* (1), *valid in-range decimal* (1) |
| `12-failure-proof-b.txt` | one record given a production-only term (`pago`) absent from its local set | **exit 1**, 2 checks FAIL: *production term set is a subset of the local set* (1), *production-only assignments == 0* (1) |
| `tests/scripts/verify-leisure-location-migration.py` | the suite's own negative assertions | 58 passed, 0 failed — includes *"dry run sent ZERO write requests at all"* |
| `./scripts/run-tests.sh --scripts` | — | 20 suites, 20 passed, 0 failed |

The mock suite never touches production (loopback stub); both failure proofs are
GET-only against production and write only into `/tmp`.

### 11.1 Regression comparison

| | Before (HEAD `83082ed`) | After (this stage, uncommitted) |
|---|---|---|
| Failing script-contract suites | 0 / 20 | 0 / 20 |
| Failing mock assertions | 0 / 58 | 0 / 58 |
| Registry drift gate | clean (19 regions, 0 writes) | clean |
| PHP / theme / plugin change | — | none, so the PHPCS/PHPStan/in-process/acceptance layers are unchanged from HEAD |

The delta of the failing lists is **empty**.



`ALREADY_SET: 289` is the **idempotency proof**: re-planning the same manifest
against the migrated target now classifies every record as already correct.

## 12. Abort conditions — checked before every write, all false

| Abort condition | Measured | Verdict |
|---|---|---|
| Production record count differs unexpectedly | 289, and 289 distinct ids/slugs | false |
| Duplicate ids or slugs | 0 duplicates on either side | false |
| Local/production slug mismatch | 289 matched, 5 expected exclusions | false |
| Coordinate conflict | `CONFLICT = 0`, 0 non-empty target coordinates | false |
| Unresolved coordinate | `UNRESOLVED = 0`, 0 invalid pairs, 0 manifest↔local mismatches | false |
| Source coordinates missing or invalid | 289/289 valid, in range, equal to the local database | false |
| Production-only taxonomy term | `production_only_assignments = 0` | false |
| Taxonomy superset check fails | holds 289/289 | false |
| Missing term mapping | 0 unresolved; all 13 slugs resolve to ids 1465–1477 | false |
| Unexpected migration-script change | sha256 `f07e5cca…ded9c`, identical to Stage 8 | false |
| Dry-run results differ unexpectedly | identical to Stage 8, including the report digest | false |
| Verification or test failure | 35/35 reconciler checks, 58/58 mock, 20/20 script suites | false |
| Wrong local WordPress environment | `http://localhost:8080`, `name: Conexão BR`, 294 records | false |
| Credentials or production identity unverifiable | administrator capability confirmed (`19-apply-preflight.txt`) | false |
| Journal / recovery mechanism unavailable | `.local/` writable and git-ignored; journal written after every POST | false |
| Repository state unexpectedly altered | HEAD moved by an **operator docs-only commit**; no script/plan/test/source change → recorded, not blocking (§1.3) | false |
| Production behaviour differs from the Stage 8/9 assumptions | public dataset digest identical to Stage 8; endpoint probe identical | false |

No abort condition was bypassed, relaxed or reinterpreted. The single
discrepancy that did occur (the operator's commit) was assessed against this list
and found not to touch anything that governs the migration.

## 13. Limitations and known pre-existing conditions

1. **Provenance keys cannot be migrated.** `_leisure_coord_source` and
   `_leisure_coord_verified` are `show_in_rest => false`; production now carries
   the coordinates without provenance, so the wp-admin "Coordenadas" column will
   continue to report them as **"por verificar"**. The front end reads only
   `_leisure_lat`/`_leisure_lng`, so the proximity feature is complete. No
   workaround was invented — there is no supported path from this repository.
2. **The REST writes are permanent.** `post_modified` moved and a revision was
   created on all 289 records (twice). Neither can be restored over REST, and
   this stage deliberately did **not** attempt to.
3. **"Add-only" is a property of the data, not of the code.** The taxonomy write
   replaces the term set; the guarantee that nothing is removed rests on the
   pre-apply superset proof (289/289, 0 production-only). It was re-established
   immediately before apply, not inherited from Stage 8.
4. **The apply is a snapshot in time.** A maintainer editing one of these 289
   records between the dry-run and the apply would not have been detected by the
   script — it re-reads the target at apply time, which is why the reconciler was
   re-run 18 minutes later, immediately before the write.
5. **WordPress.com rate limiting** produced `HTTP 429` during both reading and
   writing; retries are counted in the apply reports (`api_retries`) and every
   write is journaled, so throttling cannot produce a partial silent state.
6. **Pre-existing, not caused here:** `./scripts/lint.sh` is red at HEAD on a
   PHPCS baseline drift (documented in
   [`2026-11-05-leisure-coordinate-rest-permission-fix.md`](2026-11-05-leisure-coordinate-rest-permission-fix.md)).
   This stage changed no PHP, so lint was not re-run as a gate; the
   script-contract layer, which owns this migration, is green.
7. **Six Stage 8 evidence log files are untracked** because `.gitignore:19`
   ignores `*.log`. Stage 9 stores every evidence file as `.txt` so that nothing
   this report cites can be silently dropped by the ignore rules (§1.4).

| Polylang / translation relationship change | none; no language, term-language or translation field was written |
| Database migration / SQL | none |
| Flutter/mobile repository accessed | no |
| `.env` or secret change | no (credentials read from the environment; values never printed, never in evidence) |


## 14. Evidence paths

`docs/evidence/2026-11-05-leisure-location-stage-9/`:

| File | Proves |
|---|---|
| `01-preflight.txt` | branch/HEAD/worktree/index/untracked, the 9 stash entries (unmodified), artefact digests, journal absence, local stack identity, credential **names** only |
| `16-stage8-evidence-unchanged.txt` | the Stage 8 evidence directory is byte-identical before and after this stage (22 files) |
| `17-repository-state-change.txt` | the operator's docs-only commit during Stage 9A, with proof that nothing governing the migration changed |
| `02-verification-pre.txt` | the fresh production + local inventory and the per-record plan |
| `03-independent-reconciliation-pre.txt` | the 35 independent pre-apply checks (0 failed) |
| `production-inventory-pre.json`, `local-inventory.json` | the 289-record production sweep and the 294-record local inventory |
| `coordinate-plan-pre.json`, `attribute-plan-pre.json` | the 289 + 289 per-record re-derivation |
| `production-terms-pre.json` | the production term map before the write (13 slugs → ids 1465–1477) |
| `04-dryrun-01-coordinates.txt`, `dryrun-01-coordinates.json` | the coordinate dry-run and its machine report |
| `05-dryrun-02-attributes.txt`, `dryrun-02-coordinates-attributes.json` | the attribute dry-run and its machine report |
| `06-dryrun-03-repeat.txt`, `dryrun-03-repeat.json` | determinism (byte-identical to `05`) |
| `07-verification-post.txt`, `verification-post.json`, `production-inventory-post.json`, `coordinate-plan-post.json`, `attribute-plan-post.json` | the zero-write proof (`identical=True`) and the row-for-row cross-check of the printed plan |
| `13-dryrun-safety.txt` | the exact invocations, the mode contract, the two write call sites under `if mode == "apply"`, the absence of `DELETE`/`PUT`/`PATCH`, the untouched journal |
| `08-migration-verify.json` | the script's read-only `--verify` coverage report before the apply |
| `09-mock-suite.txt` | `tests/scripts/verify-leisure-location-migration.py` — 58 passed, 0 failed |
| `10-script-contract-suite.txt` | `./scripts/run-tests.sh --scripts` — 20 suites, 20 passed, 0 failed |
| `11-failure-proof-a.txt`, `12-failure-proof-b.txt` | the reconciler **fails** on a tampered coordinate value (3 checks) and a tampered attribute set (2 checks) |
| `14-endpoint-probe.txt` | live endpoint shapes: 289 records, 13 terms with counts, 0 coordinates in the public dataset, plugin 1.6.3 active, provenance keys absent from the exposed meta |
| `15-corrected-gate-accounting.txt` | the corrected attribute accounting (289 POSTs, not 232) |
| `18-approval-checkpoint.txt` | the Stage 9D proposal the operator authorised |
| `19-apply-preflight.txt` | production + administrator identity, journal readiness, and the post-delay re-check |
| `20-preapply-recheck-dryrun.txt`, `21-preapply-recheck-reconcile.txt` | the dry-run and reconciler re-run immediately before the write |
| `22-apply-01-coordinates.txt`, `apply-01-coordinates.json` | the coordinate apply: `written=289, gate=0, api_requests=297, api_retries=2` |
| `23-apply-02-attributes.txt`, `apply-02-coordinates-attributes.json` | the attribute apply: `already_set=289, written=0, attributes_written=289, gate=0` |
| `24-apply-result.txt` | the expected-vs-actual write counts (289/289/289) and the journal totals |
| `25-verification-post-migration.txt`, `post-migration/verification-post.json`, `post-migration/production-inventory-post.json`, `post-migration/coordinate-plan-post.json`, `post-migration/attribute-plan-post.json` | the fresh post-migration sweep: 289/289 coordinates, 592/592 assignments, `ALREADY_SET: 289` |
| `26-independent-post-reconcile.txt` | the 14 independent post-migration checks (0 failed) |
| `27-post-migration-endpoint.txt` | cache-busted anonymous reads: 289/289 dataset items with `lat`+`lng`, per-term counts summing to 592, `post_modified` and revision side effects, provenance keys still absent |
| `dryrun-04-preapply-recheck.json` | the dry-run re-run 18 minutes after the first, immediately before the write |
| `stage9-pre-migration-verify.py` | the Stage 8 verifier, copied byte-for-byte so the Stage 8 directory stays untouched (justification: the task forbids modifying historical evidence; the file is a verbatim copy, sha256 `f23af0d1…`) |
| `stage9-independent-reconcile.py` | the independent reconciler (read-only by construction: no write method exists in it) |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-11-05-leisure-location-production-migration.md` | new (this report) |
| `docs/reports/README.md` | index row in the Lazer section + `_Last verified_` line |
| `docs/README.md` | index row next to the Stage 8 report + `_Last verified_` line |

**The approved plan was not edited.** Its §11 gate 5
(`attributes_written = 232`) is wrong about what the script does; the correction
is documented in §5.1 of this report and in `15-corrected-gate-accounting.txt`
rather than silently rewritten into the plan. Correcting the plan itself is a
separate editorial change for the operator to authorise.

No historical Stage 7/8 report or evidence file was rewritten or deleted
(`16-stage8-evidence-unchanged.txt`).

## 16. Rollback / recovery

| Mechanism | Detail |
|---|---|
| Journal | `.local/leisure-location-migration-state.json` — `applied[slug] = {target_id, lat, lng, at}` for the 289 coordinate writes and `attributes[slug] = {target_id, term_ids, at}` for the 289 attribute writes, written atomically after every `POST`. Git-ignored; **not** committed. |
| Pre-change snapshot | `production-inventory-pre.json` in the evidence directory: the full 289-record state before the write (`with_coordinate = 0`, the pre-change term ids per record, `modified_gmt` per record). |
| To revert the coordinates | re-issue `_leisure_lat` / `_leisure_lng` as empty strings on the 289 ids named by the journal. |
| To revert the attributes | re-set each record's pre-change term set from `production-inventory-pre.json`. |
| **Rollback does NOT cover** | `post_modified` and the revisions created by the 578 POSTs — permanently changed, not revertible over REST (and deliberately not touched by this stage); and `_leisure_coord_source` / `_leisure_coord_verified`, which were never written. |
| Manual edits between the snapshot and the apply | not covered: the journal records what *this* run wrote, not what a maintainer changed independently. |

Rollback was **not executed**: the migration completed and verified. No `--overwrite`
was ever used, so no pre-existing value was destroyed and there is nothing to
"restore" beyond clearing the two fields.

## 17. Final status

| Gate (plan §11, with gate 5 corrected) | Expected | Actual | Verdict |
|---|---:|---:|---|
| 1. `--verify` before → `with_coordinate` | 0 | 0 | PASS |
| 2. dry-run → `populate` / `conflicts` / `unresolved` / `written` | 289 / 0 / 0 / 0 | 289 / 0 / 0 / 0 | PASS |
| 3. `--apply` → `written` / `gate` / retries reported | 289 / 0 | 289 / 0, `api_retries=2` | PASS |
| 4. second apply → `already_set` / `written` | 289 / 0 | 289 / 0 | PASS |
| 5. `--apply --include-attributes` → `attributes_written` (**corrected from 232**) | 289 | 289 | PASS |
| 6. `--verify` after → `with_coordinate` | 289 | 289 (verifier + reconciler + public dataset) | PASS |
| 7. `./scripts/run-tests.sh --scripts` | all green | 20/20 | PASS |
| Removals | 0 | 0 | PASS |
| Independent post-migration verification | 14/14 | 14/14 | PASS |

**Final status: `PASS`.**

Every gate in the approved plan reached its number (with gate 5 corrected and the
correction documented rather than applied silently), the apply is idempotent on a
re-run, nothing was created, deleted, renamed or removed, an independent verifier
re-derived all 289 coordinates and all 592 assignments from the local source and
found zero mismatches, and the public dataset now serves 289 `lat`/`lng` pairs.

The two limitations that make this "complete but not free" are stated, not hidden:
provenance keys cannot be written over REST, and `post_modified` / revisions on
289 records are permanent.

Stage 10 has not been started, and nothing beyond Stage 9 was performed.

_Last verified: 2026-11-05 by Stage 9 — Lazer location production migration (578 authorised production writes, independently verified)_

