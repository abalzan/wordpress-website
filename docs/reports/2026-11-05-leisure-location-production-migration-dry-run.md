# Report — Stage 8: Lazer location migration **production dry-run** (read-only)

> Written from [`docs/templates/report.md`](../templates/report.md). Machine
> evidence lives in
> [`docs/evidence/2026-11-05-leisure-location-stage-8/`](../evidence/2026-11-05-leisure-location-stage-8/);
> this document is the narrative that cites it.

| | |
|---|---|
| **Stage / task name** | Stage 8 — fresh, comprehensive, **read-only** production dry-run of the existing Lazer location migration script after the `conexao-data-model` 1.6.3 REST permission fix |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `25749f7af8b63fe3ab12204964aa73a2b0db9d94` (`Add REST permission tests for leisure coordinate meta keys`) |
| **HEAD at preflight** | `2aef58f8f5a52b0cbe4b805c81ad5bf100eb1006` (`Add report for Stage 7: …`) — see §1.1: the operator committed the Stage 7 work during this session |
| **Final SHA** | uncommitted — committing and pushing are out of scope for this stage |
| **Working tree at finish** | dirty by design: this evidence directory, this report and its two index rows. Nothing else. |
| **Production writes** | **0** — every production request in this stage was a `GET` |
| **Migration applied** | **no** — `--apply` was never passed; `--confirm-production` was never passed |
| **Skills followed** | `wp-production-operations`, `wp-testing`, `wp-repository`, `wp-security-review`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §2, §5.2, §7, §8, §9), `docs/testing.md`, `docs/plans/2026-11-05-leisure-location-production-migration.md`, `docs/reports/2026-11-05-leisure-location-production-audit.md`, `docs/reports/2026-11-05-leisure-location-production-rest-permission-verification.md`, `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md`, `docs/releases.md`, `plugins.json`, `scripts/README.md`, `docs/content-model.md`, `docs/templates/report.md`, `docs/evidence/README.md` |

## 0. Final verdict

**`PASS — dry-run complete, read-only, and migration plan fully reconciled`.**

Every number the plan predicted was re-measured on production and on the local
source, **record by record**, and matched:

| Measure | Expected (prior audit) | Measured 2026-11-05 | Match |
|---|---:|---:|---|
| Published production leisure records | 289 | **289** | yes |
| Local records matching production by slug | 289 | **289** | yes |
| Production records carrying coordinates | 0 | **0** | yes |
| Coordinate pairs the plan would write | 289 | **289 `POPULATE`** | yes |
| Local attribute assignments (record↔term pairs) | 592 | **592** | yes |
| Production attribute assignments | 173 | **173** | yes |
| Missing assignments | 419 | **419** | yes |
| Records that actually change | 232 | **232** | yes |
| Removals | 0 | **0** (0 production-only terms) | yes |
| Deletions | 0 | **0** (the script has no delete path) | yes |

The dry-run was proven read-only three independent ways: the script's mode
contract, a loopback mock API that records every request (**58/58 assertions,
"dry run sent ZERO write requests at all"**), and a **before/after production
sweep** whose 289 records are identical on `modified_gmt`, `_leisure_lat`,
`_leisure_lng` and the attribute terms (**0 changed**).

Three things are disclosed rather than smoothed over — the first two matter for
the Stage 9 authorisation in §10:

1. **The attribute phase writes 289 records, not 232.** The dry-run reports
   `attributes_planned=289`: that is *records touched by the full-set write*,
   not assignments and not "records that change". Only 232 records actually
   change their set. The migration plan's §11 gate 5 says
   `attributes_written = 232`; that gate is **wrong about what the script
   does**, and §5 records the corrected expectation.
2. **Every one of those writes moves `post_modified` and creates a revision** —
   on 289 records for coordinates and 289 for attributes. Stage 7 measured this
   on one record; at scale it is permanent and not revertible over REST. It is
   the cost of a REST content write and must be accepted explicitly before apply.
3. **"Add-only" is a property of the DATA, not of the code.** The taxonomy write
   replaces the term set; `plan_attributes()` never compares against the
   target's current terms. Today the local set is a superset of the production
   set on **289/289** records (**0** production-only assignments), so the write
   cannot remove anything. That guarantee must be re-verified immediately before
   apply — the verifier that does it ships in the evidence directory.

## 1. Scope completed

| Requested | Done | Evidence |
|---|---|---|
| Read the governance docs, the Stage 7 report/evidence, the plan, the script and its tests | yes | header; `01-preflight.txt` |
| Confirm the dry-run contract **before** touching production | yes | `01-preflight.txt` §6, `07-dryrun-safety.txt` |
| Fresh read-only production inventory with pagination verification | yes | `02-inventory-pre.txt`, `production-inventory-pre.json`, `11-endpoint-probe.txt` |
| Match every production record to the local source by slug | yes | `verification-pre.json` → `matching` |
| Run the existing script in dry-run (coordinates) | yes | `03-dryrun-01-coordinates.log`, `dryrun-01-coordinates.json` |
| Run it again with the opt-in attribute phase | yes | `04-dryrun-02-attributes.log`, `dryrun-02-coordinates-attributes.json` |
| Validate the coordinate plan **record by record** | yes | `coordinate-plan-pre.json` (289 rows), `verification-post.json` |
| Validate the attribute plan (assignments, not records) | yes | `attribute-plan-pre.json` (289 rows), §5 |
| Idempotency / determinism review (second dry-run) | yes | `08-determinism.txt` (byte-identical reports) |
| Prove no production write occurred | yes | `07-dryrun-safety.txt` §6, `verification-post.json` → `write_proof` |
| Produce this report + evidence | yes | this file, `docs/evidence/2026-11-05-leisure-location-stage-8/` |

### 1.1 Repository discrepancy recorded (not caused by this stage)

At the first command of this session `git status` showed **HEAD `25749f7`** with
the Stage 7 report and evidence directory **uncommitted**. By the time the
preflight was captured, HEAD was **`2aef58f`** and the worktree was clean — the
operator had committed the Stage 7 work in between. This stage made **no
commit, no push, no reset, no clean, no checkout/restore and no stash
operation** (the stash list stayed at 9 entries throughout). The discrepancy is
recorded because the task asked for it; the Stage 7 content is identical before
and after that commit.

### 1.2 The script was not modified

`scripts/migrate-leisure-location-rest.py` is byte-identical to `HEAD`
(sha256 `f07e5cca…ded9c`, `01-preflight.txt` §2), as are `scripts/lib/rest.py`
and the manifest. No behaviour was changed to flatter this dry-run. The only new
code in this stage is the read-only verifier
(`stage8-dry-run-verify.py`, GET-only, no write method exists in it).

## 2. Stage 8A — fresh read-only production inventory

**Captured 2026-11-05T16:05:53Z (pre), 16:08:34Z (post), 16:14:28Z (probe).**

| Endpoint | Status | Result |
|---|---|---|
| `GET /wp-json/wp/v2/leisure?per_page=100&page=1..3&status=publish&_fields=id,slug,status,modified_gmt,meta,conexao_leisure_attribute` | 200 ×3 | **289 records**, `X-WP-Total: 289`, `X-WP-TotalPages: 3` |
| `GET /wp-json/wp/v2/conexao_leisure_attribute?per_page=100&_fields=id,slug,name,count` | 200 | **13 terms**, `X-WP-Total: 13` |
| `GET /wp-json/wp/v2/leisure/10551?context=edit&_fields=…` | 200 | the Stage 7C target, `_leisure_lat`/`_leisure_lng` still `''` |
| `GET /wp-json/conexao/v1/leisure-coordinates?lang=pt` (anonymous) | 200 | `count 289`, `items 289`, **0** with any non-null lat/lng |
| `GET /wp-json/wp/v2/plugins/conexao-data-model/conexao-data-model` | 200 | `version 1.6.3`, `status active` — the 7C prerequisite still holds |

**Pagination verified, not assumed.** Page sizes were **100 + 100 + 89 = 289**,
each page reported `X-WP-Total: 289` / `X-WP-TotalPages: 3`, and the retrieved
record count equals both the header total and the distinct-id count. A
`per_page=1` probe independently reported `X-WP-TotalPages: 289`, consistent
with `per_page=100` → 3.

**Uniqueness verified.** 289 records → **289 distinct ids**, **289 distinct
slugs**, **0** duplicate ids, **0** duplicate slugs (the verifier fails closed on
either), all `status = publish`, **0** half-filled coordinate pairs.

### 2.1 Local source inventory and slug matching

**Captured 2026-11-05T16:05:53Z** (`local-inventory.json`), `GET
http://localhost:8080/wp-json/wp/v2/leisure` (same fields, same pagination
logic, 100 + 100 + 94):

| Measure | Value |
|---|---:|
| Published local records | **294** |
| Locally with both coordinates | **289** |
| Locally with attributes | **289** (592 assignments) |
| Local attribute terms | 14 (one unused test term, `lls-test-attribute`) |
| Matched to production **by slug** | **289** |
| Local-only records | **5** |
| Production-only records | **0** |

The 5 local-only records are `ph3c-test-lazer-alfa`, `ph3c-test-lazer-externo`,
`ph3c-test-lazer-sem-condado`, `ph3c-test-lazer-zeta` (the CI fixtures created by
`scripts/bootstrap-ci-fixtures.php`) and `phoenix-park-en` (the local EN sibling
of the Phoenix Park PT record). **None of the five appears in the coordinate
manifest**, so none can enter the plan: `manifest ∩ local-only = ∅`
(`verification-pre.json` → `coordinates`). They are test doubles and a localised
translation relationship — not attractions — and they stay local.

**Unexpected records: none.** Every production record has a local counterpart and
every planned manifest slug has a production record (`unresolved = 0`).

## 3. Stage 8B/8C — the coordinate plan, record by record

**Invocation** (exactly as documented; no write flag anywhere):

```bash
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --dry-run \
  --report docs/evidence/2026-11-05-leisure-location-stage-8/dryrun-01-coordinates.json
```

Script summary (`03-dryrun-01-coordinates.log`; the log prints it on one line,
wrapped here for width):

```
summary: manifest_entries=289, eligible_entries=289, target_records=289,
populate=289, update=0, already_set=0, conflicts=0, unresolved=0, resumed=0,
planned_writes=289, written=0, attributes_planned=0, attributes_written=0,
with_before=0, with_after=0, gate=0, api_requests=6, api_retries=0,
manifest_skipped={'OWNER_INPUT_REQUIRED:NOT_FOUND': 4, 'REVIEW:MEDIUM': 1}
(dry run — nothing written)
```

| Plan measure | Observed |
|---|---:|
| Manifest entries | 294 |
| **Eligible** (`POPULATE` + HIGH, or owner-approved) | **289** (272 HIGH + 17 approved MEDIUM) |
| Skipped, with reason | **5**: 4 × `OWNER_INPUT_REQUIRED:NOT_FOUND` (`ph3c-test-lazer-alfa`, `-externo`, `-sem-condado`, `-zeta`), 1 × `REVIEW:MEDIUM` (`phoenix-park-en`) |
| `POPULATE` (coordinate pairs to write) | **289** |
| `UPDATE` | 0 |
| `ALREADY_SET` | 0 |
| `CONFLICT` | **0** |
| `UNRESOLVED` (manifest slug with no target record) | **0** |
| Written / attributes written | **0 / 0** |
| Numeric gate (`with_after − expected_after`) | **0** |
| Deletions | **0** — the script issues only `GET` (line 471) and `POST` on an existing id (787, 989); there is no `DELETE`/`PUT`/`PATCH` in the file |

**Per-record verification (289/289, not an aggregate).**
`coordinate-plan-pre.json` holds one row per eligible entry with the manifest
value, the local meta value, the production id, the production current value and
the classification. Independent checks over those 289 rows:

| Check | Result |
|---|---|
| Rows | 289 |
| Rows whose planned **latitude/longitude ≠ the local database value** | **0** |
| Rows whose **target id is not the production record's id** | **0** |
| Rows with a non-empty production coordinate (would be CONFLICT) | **0** |
| Rows whose action ≠ `POPULATE` | **0** |
| Rows whose match strategy ≠ `slug` | **0** |
| Distinct target ids in the plan | **289** (no record written twice) |
| Rows missing or with an invalid coordinate pair | **0** |

The dry-run's printed rows were then compared to that independent re-derivation
one row at a time (`verification-post.json` → `report_cross_check`):
**289 plan rows, 0 coordinate mismatches, 0 missing rows**. Three illustrative
rows (the full 289 are in the JSON evidence):

| slug | manifest = local lat/lng | production id | production lat/lng | action |
|---|---|---|---|---|
| `trinity-college-book-of-kells` | `53.34394 / -6.2568` | 10146 | `'' / ''` | `POPULATE` |
| `russborough-house` (the 7C target) | `53.141559 / -6.569674` | 10551 | `'' / ''` | `POPULATE` |
| `dwyer-mcallister-cottage` | `52.961976 / -6.564829` | 21197 | `'' / ''` | `POPULATE` |

## 4. The attribute plan — assignments, not records

**Invocation** (the opt-in phase; the local site is the source, so it is named
explicitly rather than resolved from `CONEXAO_SITE_URL`, which points at
production in the local `.env`):

```bash
python3 scripts/migrate-leisure-location-rest.py \
  --target-url https://conexaobr.ie --source-url http://localhost:8080 \
  --dry-run --include-attributes \
  --report docs/evidence/2026-11-05-leisure-location-stage-8/dryrun-02-coordinates-attributes.json
```

Script summary (`04-dryrun-02-attributes.log`):
`populate=289, conflicts=0, unresolved=0, written=0, attributes_planned=289,
attributes_written=0, gate=0, api_requests=7`.

**What "592" counts.** It is the number of **assignment relationships** — one
`(record, term)` pair each — computed as the sum of per-record term counts on
both sides, with terms resolved **by slug** (ids are environment-local). It is
**not** 592 records and **not** 592 terms: 13 distinct terms are used across
289 records.

| Measure | Local | Production | Missing | Production-only (removal risk) |
|---|---:|---:|---:|---:|
| Assignment relationships (record↔term) | **592** | **173** | **419** | **0** |
| Records carrying ≥ 1 term | **289** | **57** | — | — |
| Records whose set would change | — | — | **232** | — |
| Distinct terms | 13 used (14 registered) | 13 | — | — |

| Term slug | Local | Production | Δ |
|---|---:|---:|---:|
| `exterior` | 194 | 25 | +169 |
| `gratuito` | 115 | 35 | +80 |
| `familias` | 94 | 19 | +75 |
| `interior` | 59 | 15 | +44 |
| `estacionamento` | 45 | 28 | +17 |
| `interior-exterior` | 35 | 17 | +18 |
| `necessita-reserva` | 22 | 6 | +16 |
| `pago` | 13 | 13 | 0 |
| `gratuito-em-determinadas-condicoes` | 6 | 6 | 0 |
| `acesso-de-transporte-publico` | 3 | 3 | 0 |
| `pet-friendly` | 3 | 3 | 0 |
| `acessivel` | 2 | 2 | 0 |
| `bicicleta` | 1 | 1 | 0 |
| **Total** | **592** | **173** | **+419** |

**Per-record checks over all 289 matched records** (`attribute-plan-pre.json`):

| Check | Result |
|---|---|
| Records where the production term set is **not** a subset of the local set | **0** — the superset property holds 289/289 |
| Production-only assignments (the only way a full-set write can remove a term) | **0** |
| Local attribute slugs that do **not** resolve to a production term (the script drops these silently) | **0** — all 13 resolve |
| Records in the script's attribute plan | **289** |
| Planned assignment relationships | **592** — exactly the local total |
| Duplicate/invalid term assignments | 0 (sets, resolved by slug) |
| Records skipped, and why | 0 skipped: every record with ≥ 1 local term maps to a production record |

**Expected final production state: 592 assignments across 289 records, −0
removals** — the historical expectation, re-measured rather than assumed.

## 5. Two precision points about the plan's numbers

The plan's §11 gate 5 says: *"`--apply --include-attributes` →
`attributes_written = 232`"*. That is **not** what the script does, and the
dry-run proves it:

| The script reports | Meaning | Value |
|---|---|---:|
| `attributes_planned` | **records** that will receive a full-set `POST` | **289** |
| `attributes_written` (on apply) | records actually posted to | **289** |
| (not reported by the script) | records whose set genuinely changes | **232** |
| (not reported by the script) | assignment relationships written | **592** |

So the correct apply-time gate is: `attributes_written = 289`,
`attributes_planned = 289`, and the *change* count (232) and the *assignment*
count (592) are measured by the independent verifier, not by the script. This
matters for review: Stage 9 issues **289 attribute POSTs**, not 232, and 57 of
them re-send a set that is already correct (idempotent, but a real request, a
real `post_modified` bump and a real revision).

A second precision point: `plan_attributes()` builds a row for every record with
at least one resolvable local term and **never compares against the target's
current terms**. The "add-only" guarantee is therefore data-derived. It holds
today (0 production-only assignments) and it is exactly what a pre-apply
re-verification must re-establish.

## 6. Safety

| Check | Result | Evidence |
|---|---|---|
| **Dry-run invocation** | `--dry-run` passed explicitly on all three runs; `mode: dry-run` and `(dry run — nothing written)` in every log | `03`–`05` logs |
| **No `--apply`** | never passed; `--apply` and `--dry-run` are mutually exclusive in the parser | `07-dryrun-safety.txt` §2 |
| **No `--confirm-production`** | never passed (it is only consulted for `mode == "apply"` on a production target) | `07-dryrun-safety.txt` §2 |
| **No `--overwrite`, no `--no-resume`** | never passed; `UPDATE` would otherwise be possible | logs |
| **Write code unreachable** | the only two write call sites are inside `if mode == "apply"` (lines 962, 967); the file issues only `GET` and `POST` on an existing id | `07-dryrun-safety.txt` §3 |
| **Resume journal untouched** | `.local/` was never created — no write path ran, and no journal state was fabricated | `07-dryrun-safety.txt` §4 |
| **Mock API records every request** | `verify-leisure-location-migration.py`: **58 passed, 0 failed**, including *"dry run performed ZERO writes"* and *"dry run sent ZERO write requests at all"* | `09-mock-suite.log` |
| **Production unchanged (measured)** | before/after sweeps: 289 → 289 records, **0 changed, 0 added, 0 removed**, compared on `id`, `slug`, `status`, `modified_gmt`, `_leisure_lat`, `_leisure_lng`, `attribute_ids`, `attribute_terms`; `identical = true` | `verification-post.json` → `write_proof` |
| **Stage 7C target still clean** | `leisure` 10551 → `russborough-house`, `_leisure_lat`/`_leisure_lng` `''`, `modified_gmt 2026-11-05T15:46:00` (the accepted Stage 7 side effect, unchanged by this stage) | `11-endpoint-probe.txt` |
| **No other record disturbed** | exactly **1** of 289 production records carries a 2026-11-05 `modified_gmt` — the Stage 7C target | `production-inventory-pre.json` |
| **No deploy, activation, DB or CLI access** | none attempted; every production touch was `GET /wp-json/…` over HTTPS | `01-preflight.txt`, `11-endpoint-probe.txt` |
| **No commit or push** | `git status` at finish = this stage's files only; no `git add`, `commit`, `push`, `reset`, `clean`, `checkout` or stash operation | §12 |

**Credential handling.** `WP_USERNAME` / `WP_APPLICATION_PASSWORD` were loaded
from the repository-root `.env` — **git-ignored** (`.gitignore:2:.env`) and **not
tracked** (`git ls-files .env` → 0 files) — into the process environment for each
command. The values were never printed, never passed on a command line, never
written to a log, report or evidence file, and `.env` was not modified. A scan of
**every** evidence file and of the two edited indexes for each credential value,
and for a rendered `Authorization` header, found **none** — the only place the
header is even named is this section, as the thing that was searched for.
`01-preflight.txt` §3 records variable **names** only. No credential was created,
rotated or requested, and none was pasted into chat.

**Rate limiting.** Production answered the first inventory request with `HTTP
429`. It was honoured by waiting and retrying (the verifier prints the wait,
never the credential), and the migration script's own client honours
`Retry-After` with bounded backoff. No request was abandoned or forced.

## 7. Comparison with the prior audit — every difference stated

| Historical measure | Prior audit | Stage 8 | Explanation |
|---|---:|---:|---|
| Published production leisure records | 289 | **289** | unchanged |
| Local records matching production by slug | 289 | **289** | unchanged |
| Production records with coordinates | 0 | **0** | unchanged |
| Coordinate pairs planned | 289 | **289** | unchanged |
| Local attribute assignments | 592 | **592** | unchanged |
| Production attribute assignments | 173 | **173** | unchanged |
| Missing assignments | 419 | **419** | unchanged |
| Records that change | 232 | **232** | unchanged |
| Removals | 0 | **0** | unchanged |
| **Local published leisure records** | **293** | **294** | **+1**: `phoenix-park-en`, a local-only EN sibling record created by the Phoenix Park UUID work. It is **not in the manifest**, has no production counterpart, and cannot enter the plan. The audit's "289 real + 4 CI fixtures" is therefore now "289 real + 4 CI fixtures + 1 local EN sibling". |
| Production `/wp-json/conexao/v1/leisure-coordinates` payload | `{count, items, with_coordinate, target}` as quoted in the Stage 7 evidence | `{count, items, lang}`; **0** items carry a non-null lat/lng | The endpoint's current response shape does not include `with_coordinate`/`target`. The measured fact is unchanged (0 coordinates); the shape difference is recorded rather than silently reworded. |
| `api_requests` in the dry-run summary | 6 | 6 (coordinates only) / **7** (with attributes: one extra request reads the target's term list) | expected, explained by the opt-in phase |
| `modified_gmt` on 10551 | `2026-11-05T15:46:00` | `2026-11-05T15:46:00` | the Stage 7C side effect, unchanged |

Nothing else differs. No number was reconciled by assumption: the new local
record was identified by slug and shown to be outside the manifest.

## 8. Conflicts, limitations, and what the dry-run cannot tell you

| # | Item | Status |
|---|---|---|
| 1 | **No conflicts.** 0 `CONFLICT`, 0 half-filled target pairs, 0 invalid manifest coordinates, 0 unresolved slugs, 0 duplicate ids/slugs, 0 removed/created records. | clean |
| 2 | **`_leisure_coord_source` / `_leisure_coord_verified` cannot cross the REST boundary** (`show_in_rest => false`). Production will carry coordinates **without provenance**, and the admin "Coordenadas" column will read "por verificar". The front end reads only `_leisure_lat`/`_leisure_lng`, so the proximity feature is complete; this is a documented limitation, not a silent gap. | accepted limitation |
| 3 | **"Add-only" is data-derived, not code-enforced.** If a maintainer adds a production-only term before apply, the full-set write would remove it. Today the risk is **0** (0 production-only assignments, 289/289 superset). | must be re-verified before apply |
| 4 | **289 attribute POSTs, not 232** (§5). | corrected expectation |
| 5 | **Irreversible REST side effects at scale**: each successful `POST` moves `post_modified` and creates a revision. Expected on all 289 coordinate records and all 289 attribute records (the 57 already-correct sets included). Not revertible over REST. | must be authorised explicitly |
| 6 | **The local source is not uniformly authoritative** for four CI-fixture records (`(sintético)` titles, `fota-wildlife-park`'s `example.invalid` URL). The migration never writes titles/URLs, so this cannot leak into production — but it is why "copy the whole record" was never an option. | out of scope, safe |
| 7 | **The dry-run is a snapshot.** Any production content change between this report and Stage 9 invalidates these counts; the pre-apply gate is to re-run the dry-run **and** the verifier. | operational rule |
| 8 | **WordPress.com rate limiting** produced one `429` while reading. Reads are cheap and retried; the apply phase (578 POSTs at `--request-delay 0.25`) should expect throttling and rely on `Retry-After` + the resume journal. | operational note |

## 9. What Stage 9 would do — exact expected changes

If and only if authorised, an apply would issue, against `https://conexaobr.ie`:

| Phase | Requests | Effect |
|---|---:|---|
| Coordinates (`--apply --confirm-production`) | **289 POSTs** | sets `_leisure_lat` + `_leisure_lng` on 289 existing `leisure` records; 0 creations, 0 deletions, 0 overwrites |
| Attributes (`--apply --include-attributes --confirm-production`) | **289 POSTs** | replaces the `conexao_leisure_attribute` set on 289 records with the local set (592 assignments); 419 net additions, **0 removals** |
| **Total** | **578 writes** | 289 records × 2 keys + 289 taxonomy writes; `post_modified` moves on all 289 records; ~578 revisions |

Post-conditions that would then be checked (the plan's gates, with the gate-5
correction from §5): `written = 289`, `gate = 0`; a second apply `already_set =
289, written = 0`; `attributes_written = 289`; `--verify` → `with_coordinate =
289`; the public dataset → 289 items with non-null lat/lng; production attribute
assignments = **592**; **0** records removed; `modified_gmt` changed on exactly
the 289 records in scope and on nothing else.

Rollback for Stage 9 (not executed here): the resume journal
`.local/leisure-location-migration-state.json` records every `slug → {target_id,
lat, lng}`; reverting means re-issuing empty strings and the pre-change term set,
which restores content but **cannot** restore `post_modified` or delete the
revisions.

## 10. Authorization checkpoint — Stage 9

**This report is not an authorisation.** The migration has **not** been applied,
and the dry-run is not a mandate to apply it.

1. **Findings presented:** production is exactly as the plan predicted — 289
   records, 0 coordinates, 592 local attribute assignments of which 173 exist —
   and the existing script's dry-run reproduces that plan with 0 conflicts.
2. **Expected production changes:** 289 coordinate pairs + 419 attribute
   assignment additions across 232 records, delivered as 578 POSTs on 289
   existing records; 0 removals, 0 creations, 0 deletions.
3. **Conflicts/limitations:** none blocking. Two must be accepted explicitly:
   provenance keys cannot be migrated (§8.2), and 289 records will carry a new
   `post_modified` and a revision (§8.5).
4. **Is Stage 9 safe to consider?** **Yes — with the corrected gate from §5 and a
   fresh pre-apply dry-run + re-verification** (superset property and 0 conflicts
   re-established at apply time, not taken from this report).
5. **Waiting** for explicit user authorisation before any `--apply`.

## 11. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3 scripts/migrate-leisure-location-rest.py --help` | 0 | dry-run is the documented default; `--apply` is the only write mode; `--confirm-production` is required for a production apply |
| `python3 …/stage8-dry-run-verify.py --phase pre` | **0** | `PASS: every Stage 8 check holds`; 289 prod / 294 local; 289 matched; 289 `POPULATE`; 592 / 173 / 419 / 0 |
| `… --target-url https://conexaobr.ie --dry-run --report …` | **0** | `populate=289, conflicts=0, unresolved=0, written=0, gate=0` |
| `… --source-url http://localhost:8080 --dry-run --include-attributes --report …` | **0** | `attributes_planned=289, attributes_written=0, written=0` |
| the same command again (repeat) | **0** | byte-identical report: `cmp` exit 0, sha256 `83de2f1c…b3242` both times |
| `python3 …/stage8-dry-run-verify.py --phase post --baseline … --report …` | **0** | `plan rows 289, 0 coordinate mismatches, 0 attribute mismatches, 0 missing rows`; `write proof: identical=True` |
| `… migrate-leisure-location-rest.py --target-url https://conexaobr.ie --verify` | **0** | `records=289 with_coordinate=0 without_coordinate=289 half_filled=0 distinct_slugs=289` |
| `python3 tests/scripts/verify-leisure-location-migration.py` | **0** | **58 passed, 0 failed** (loopback stub, no network, no production) |
| `./scripts/run-tests.sh --scripts` | **0** | **20 script-contract suites, 20 passed, 0 failed** — `ALL TESTS PASSED` (`10-script-contract-suite.log`) |
| credential scan of every evidence file | 0 | no credential value, no `Authorization: Basic` header in any file |

Static analysis, in-process PHP suites, HTTP acceptance and release gates are
**not re-run here**: this stage changed no PHP, no theme asset, no plugin and no
test (only new documentation, evidence and a read-only verifier), so those layers
are unchanged from `HEAD`. The one layer that owns this migration — the
script-contract suite — is green above.

## 12. Files added / modified / deleted

**4 added, 2 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-11-05-leisure-location-production-migration-dry-run.md` | added | this report |
| `docs/evidence/2026-11-05-leisure-location-stage-8/` (22 files) | added | preflight, inventories, three dry-run logs, two dry-run reports, verifier, verification JSON, per-record plans, determinism, safety, mock + script-contract logs, endpoint probe, verify report |
| `docs/reports/README.md` | modified | one index row + `_Last verified_` line |
| `docs/README.md` | modified | one index row + `_Last verified_` line |

No file under `wp-content/` was touched; no script, test, plugin, theme, manifest
or `.env` change.

## 13. Evidence paths

`docs/evidence/2026-11-05-leisure-location-stage-8/`:

| File | Proves |
|---|---|
| `01-preflight.txt` | repository state, artifact digests, credential **availability** (names only), journal absence, local stack, the script's `--help` contract |
| `02-inventory-pre.txt` | the pre-run verifier output (289 / 294 / 289 matched / 289 `POPULATE` / 592-173-419) |
| `03-dryrun-01-coordinates.log` | full dry-run stdout, coordinates only |
| `04-dryrun-02-attributes.log` | full dry-run stdout incl. the 289 attribute rows |
| `05-dryrun-03-repeat.log` | the repeat run's stdout |
| `06-inventory-post.log` | the post-run verifier: report cross-check + `identical=True` write proof |
| `07-dryrun-safety.txt` | the exact invocations, the mode contract, the two apply-gated write call sites, the journal proof, the mock assertions, the post-run state |
| `08-determinism.txt` | byte-identical reports, per-record equality counters, assignment arithmetic |
| `09-mock-suite.log` | `verify-leisure-location-migration.py` — 58/58, including "ZERO write requests at all" |
| `10-script-contract-suite.log` | `./scripts/run-tests.sh --scripts` — 20/20 |
| `11-endpoint-probe.txt` | live endpoint shapes/statuses: 289 records, 13 terms, 0 coordinates in the public dataset, plugin 1.6.3, 10551 still empty |
| `12-migration-verify.json` | the script's own read-only `--verify` coverage report |
| `dryrun-01-coordinates.json`, `dryrun-02-coordinates-attributes.json` | the machine-readable plans the script printed |
| `production-inventory-pre.json`, `production-inventory-post.json` | the two 289-record sweeps behind the zero-write proof |
| `local-inventory.json` | the 294-record local source inventory |
| `coordinate-plan-pre.json`, `attribute-plan-pre.json` | the per-record re-derivation (289 + 289 rows) this report's tables summarise |
| `verification-pre.json`, `verification-post.json` | the verifier's assertions, matching, cross-check and write proof |
| `stage8-dry-run-verify.py` | the read-only verifier itself (GET-only) |

## 14. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-11-05-leisure-location-production-migration-dry-run.md` | new (this report) |
| `docs/reports/README.md` | index row in the Lazer section + `_Last verified_` line |
| `docs/README.md` | index row next to the Stage 7 report + `_Last verified_` line |

The migration **plan** was deliberately not edited: its §11 gate 5 is wrong, and
that correction belongs to the authorisation decision (§5), not to a silent
rewrite of an approved plan. If Stage 9 is authorised, the plan's gate should be
corrected in the same change that applies the migration.

## 15. Rollback / recovery

Nothing to roll back: **this stage changed no production data, no code and no
content.** Its repository footprint is additive documentation plus two index
rows; reverting it means reverting those two files and deleting the new report
and evidence directory (not performed here — commits are out of scope and the
work is the deliverable).

## 16. Final status

**Final status: `PASS`.**

The complete production inventory was retrieved, all source and target records
were matched by slug, the coordinate and attribute plans were reconciled record
by record, the dry-run was proven read-only by construction, by a mock that
records every request, and by measurement of production before and after, and no
unresolved conflict remains. Stage 9 has **not** been started and has **not**
been authorised.

_Last verified: 2026-11-05 by Stage 8 — read-only production migration dry-run (0 production writes)_
