# Report — Phoenix Park UUID repair (Option A)

| | |
|---|---|
| **Stage / task name** | Fix the coordinate resolver's shared-UUID matching order |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `80340da8eb3ea9b96f37d6bf3b264bce28fc04c3` |
| **Final SHA** | uncommitted (working tree intentionally dirty) |
| **Working tree at finish** | dirty — this task's files, plus the earlier coordinate-research work and one pre-existing untracked report that is **not ours** |
| **Skills followed** | `wp-content-change`, `wp-repository`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/reports/2026-11-03-phoenix-park-uuid-investigation.md`, `scripts/README.md`, `wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php` |

## 1. Scope completed

1. **Resolver fix.** `conexao_leisure_coord_resolve()` now collects every post
   carrying `_leisure_export_uuid` and disambiguates a duplicate by the manifest
   entry's **slug**, then its **language**. Still ambiguous → `WP_Error`
   (`uuid_ambiguous`) and nothing written.
2. **Fail-safe logging.** The `UNRESOLVED` line now prints the error **code** as
   well as the message, so `uuid_ambiguous` is greppable in operator output.
3. **Two new match strategies** reported in every summary:
   `match_stable_id_slug` and `match_stable_id_lang`.
4. **`lang` carried into the portable manifest** so the language fallback has an
   input.
5. **Regression suite** — `test-leisure-coordinate-resolver.php`, 29 assertions.
6. **Shared-UUID ambiguity fixture** added to `leisure-coordinates-selftest.json`.
7. **Documentation drift fixed** — `docs/content-model.md` now says
   `_leisure_export_uuid` and states the per-attraction semantics.
8. **Corrected the earlier report's framing** — the shared UUID is *by design*.

## 2. Scope NOT completed

- **The EN sibling still holds no coordinate.** That is deliberate: the owner's
  manifest marks 115357 **REVIEW** ("not a separate attraction"), so there is
  nothing to write. The repair makes the write *possible and correct*; it does
  not assert a decision the owner has not made.
- **Mobile UUID consumption was NOT verified.** The Flutter/mobile repository is
  explicitly out of scope for every task here (`AGENTS.md`), so I did not inspect
  it and cannot state whether it consumes `_leisure_export_uuid`. If it does, its
  owners must confirm they expect **one value per attraction** (the current,
  documented contract) rather than one per record. This is a stated limitation,
  not a silent gap.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `scripts/apply-leisure-coordinates.php` | modified | resolver, `uuid_ambiguous`, error code in the log, 2 new strategy counters |
| `wp-content/plugins/conexao-leisure-migration/tests/test-leisure-coordinate-resolver.php` | added | the regression suite |
| `scripts/data/leisure-coordinates-selftest.json` | modified | shared-UUID ambiguity fixture (dry-run only) |
| `scripts/data/leisure-coordinates-2026-11-03.json` | regenerated | now carries `lang` per entry |
| `docs/content-model.md` | modified | the drift fix + per-attraction semantics |
| `scripts/README.md` | modified | the resolver's shared-UUID behaviour |
| `docs/plans/2026-11-03-phoenix-park-uuid-repair.md` | added | the plan |
| `docs/reports/2026-11-03-phoenix-park-uuid-repair.md` | added | this report |
| `docs/reports/2026-11-03-phoenix-park-uuid-investigation.md` | added | the read-only investigation |
| `docs/README.md`, `docs/reports/README.md` | modified | index the new documents |

**7 added, 6 modified, 0 deleted.** No file under `wp-content/plugins/conexao-leisure-migration/includes/` was touched.

## 4. Runtime impact

WordPress is unchanged: no plugin, theme, template, REST or admin behaviour moved.
The only runtime difference is in one offline CLI script's matching order, plus
one new test suite. `git status` shows **no `wp-content/` change outside the new
test file** — the Lazer plugin's source is byte-identical.

## 5. Content / data impact

**Records created / updated / deleted: 0.** Proven by a 294-record before/after
snapshot diff taken immediately before and after the repair run:

```text
records before/after:            294 / 294
records changed:                 NONE
verified flags set:              0
distinct _leisure_export_uuid:   289   (unchanged)
uuid owners 10150/115357:        ci-fixture-0000-0000-4000-8000-000000000002  (both, unchanged)
post_translations term:          a:2:{s:2:"pt";i:10150;s:2:"en";i:115357;}  (unchanged)
```

Match strategies on the real manifest: **`match_stable_id=288,
`match_stable_id_slug=1`**, `match_stable_id_lang=0`, `match_slug=0`,
`match_title=0`. The single `stable-id+slug` is the Phoenix Park entry, whose
UUID legitimately matches two records — the new path is exercised by real data.

## 6. Polylang impact

- The PT/EN pair keeps **one shared** UUID (asserted).
- The translation relationship is unchanged (asserted, and verified in the DB).
- No EN record created; no second identity introduced.
- No language-scoped cache key touched.

## 7. Route / HTTP impact

None. The two read-only REST routes are untouched and still key on the local post
ID.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | the only DB writes were the test suite's own throwaway fixtures, deleted at the end |

The repair apply ran against the local Compose stack only, with
`--confirm-production` used purely because the tool is `production_capable` and
the bootstrap requires the acknowledgement for any apply.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `php -l scripts/apply-leisure-coordinates.php` | 0 | no syntax errors |
| new suite `test-leisure-coordinate-resolver.php` | 0 | **29 passed, 0 failed** |
| `test-leisure-coordinates.php` (admin-ux) | 0 | 29 passed, 0 failed |
| `test-leisure-location-services.php` (theme) | 0 | 102 passed, 0 failed |
| real manifest `--dry-run` | 0 | `planned=0, written=0, unchanged=289, match_stable_id=288, match_stable_id_slug=1, gate=0` |
| real manifest `--apply --confirm-production` | 0 | `written=0, unchanged=289, conflicts=0, unresolved=0, gate=0` |
| real manifest `--apply` (second run) | 0 | `written=0, unchanged=289` — idempotent |
| `--verify` | 0 | `published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0` |
| self-test manifest `--dry-run` (ambiguity proof) | 1 | `UNRESOLVED [uuid_ambiguous] — uuid=ci-fixture-… matches 2 leisure records (10150,115357) … nothing was written.`; `written=0, errors=2` |
| snapshot diff before/after the repair | — | 294 compared, **0 changed** |
| `./scripts/run-tests.sh --scripts` | 1 | 19 suites, 18 passed, 1 failed — pre-existing (§12) |

### The seven regression tests

| # | Proposed test | Delivered as | Result |
|---|---|---|---|
| 1 | Shared-UUID manifest resolves two different posts | new suite, assertions 7–12 (`planned=2`, `match_stable_id_slug=2`, both fixtures untouched by the dry run) | PASS |
| 2 | Ambiguity fails safely | new suite, assertions 13–16 (non-zero exit, `uuid_ambiguous` named, nothing written to either fixture) | PASS |
| 3 | Apply + idempotence | new suite, assertions 17–24 (both fixtures written, each holds its OWN latitude, second run `written=0, unchanged=2`) | PASS |
| 4 | `test-language-uuid.php` unchanged and green | existing suite, unmodified | PASS |
| 5 | `test-stage32-bilingual.php` unchanged and green | existing suite, unmodified | PASS |
| 6 | Coordinate consumers green | `test-leisure-location-services.php` 102/0, `test-leisure-coordinates.php` 29/0 | PASS |
| 7 | Full suite unchanged | `./scripts/run-tests.sh` — see §11 | PASS (same pre-existing failures) |

Plus the shared-UUID **self-test manifest** in
`scripts/data/leisure-coordinates-selftest.json`, whose real-data ambiguity run is
recorded in the `dry-run-uuid-ambiguity-proof.txt` block of
`docs/evidence/2026-11-03-leisure-coordinate-research/run-log-transcript.md`.

### Static analysis

`php -l` clean on the changed script and the new suite. `./scripts/lint.sh`
reports the same two pre-existing failures as before this change (the PHPCS
baseline growth and the `leisure-location.php` PHPStan error, both introduced by
the earlier commit `80340da`; `scripts/` is outside the PHPCS ruleset).

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| The dry run writes nothing | real manifest `--dry-run` → `planned=0, written=0`; the new suite asserts both fixtures are untouched after the shared-UUID dry run |
| The ambiguity path fails safely | self-test manifest and the new suite both produce `UNRESOLVED [uuid_ambiguous]`, a non-zero exit, `written=0`, and **no** meta written to either fixture |
| The old behaviour is gone | before the fix the shared UUID resolved twice to post 10150 (`written=75, unchanged=1` in the coordinate batch); now it reports `match_stable_id_slug=1` and the second entry resolves to the EN record |
| Idempotence | the real manifest's second apply reports `written=0, unchanged=289`; the suite's second apply reports `written=0, unchanged=2` |
| Identity is not rewritten | the suite asserts the UUID stays byte-identical on **both** fixtures and that the translation group still maps pt↔en |

## 11. Regression comparison

| | Before | After |
|---|---|---|
| In-process suites discovered | 116 | **117** (+1: the new suite) |
| In-process assertions passed | 9 056 | **9 085** (+29, all from the new suite) |
| Failing in-process suites | 11 (baseline a+b, 2026-11-02) | 11 — **identical list** |
| Failing assertion count | 41 | 41 — **identical** |
| Failing script-contract | `verify-i18n-freshness.py` | same |
| Failing acceptance assertions | `verify-event-occurrence-http.py` against the unrelated `:8080` site | same |

Full-suite output: `docs/evidence/2026-11-03-leisure-coordinate-research/full-test-run-after-uuid-repair.txt`:

```text
In-process PHP suites: 117 total, 106 passed, 11 failed
Assertions: 9085 passed, 41 failed
Script-contract suites: 19 total, 18 passed, 1 failed
HTTP acceptance suites: 4 total, 3 passed, 1 failed
```

The new suite adds 29 passing assertions and removes none. No existing test file
was modified, and the failing-set diff attributable to this change is **empty**.

## 12. Known pre-existing failures

1. `verify-i18n-freshness.py` — `conexao-admin-ux.pot` older than `class-fields.php` (both from the earlier commit `80340da`).
2. PHPCS baseline growth — the ruleset covers `wp-content/` only, which this change did not modify outside the new test file.
3. PHPStan 1 error — `leisure-location.php:79`, untouched here.
4. The 11 in-process failures and the acceptance failure — local content-data conditions and the pre-existing `:8080` port/DB split, all recorded in the 2026-11-02 baselines.

## 13. Limitations

- **Mobile UUID consumption was NOT verified** (§2). The Flutter/mobile repository is out of scope for this repository; if the app keys on `_leisure_export_uuid`, its owners must confirm the one-value-per-attraction expectation.
- **The EN sibling still has no coordinate**, because the owner's manifest marks it REVIEW. The repair removes the *technical* blocker only.
- **HTTP acceptance still targets the unrelated `:8080` site**, so it is not a verification of this change; the equivalent checks ran against the Compose stack directly.
- The resolver's language fallback (`stable-id+lang`) is exercised by the suite's fixture but **not** by real data (the real pair is disambiguated at the slug step), so its real-world coverage is indirect.

## 14. Evidence paths

`docs/evidence/2026-11-03-leisure-coordinate-research/`:

| File | Proves |
|---|---|
| `run-log-transcript.md` | **The consolidated run log**: the byte-for-byte stdout of every run in this task — the two-source pipeline batch, the classification correction, all 18 owner batches, the provenance repair, the UUID repair and every idempotence and `--verify` re-run — each block headed by the filename it came from. This single file replaced ~80 individual `dry-run-*.txt` / `apply-run-*.txt` / `verify-after-*.txt` captures, whose one-line contents it reproduces exactly |
| `post-apply-snapshot.json` | the 294-record state used for the before/after diff (0 changed) |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/content-model.md` | `_lazer_uuid` → `_leisure_export_uuid`; the per-attraction semantics; the consumer obligation to disambiguate; the retired `_leisure_uuid` key |
| `scripts/README.md` | the resolver's shared-UUID behaviour, the two new strategy counts, the fail-safe ambiguity |
| `docs/plans/2026-11-03-phoenix-park-uuid-repair.md` | new plan |
| `docs/reports/2026-11-03-phoenix-park-uuid-repair.md` | this report |
| `docs/reports/2026-11-03-phoenix-park-uuid-investigation.md` | the read-only investigation |
| `docs/README.md`, `docs/reports/README.md` | index entries |

## 16. Rollback / recovery

Revert `scripts/apply-leisure-coordinates.php` and delete the new test file and
the self-test fixture entry. **Nothing in the database needs reversing** — the
before/after snapshot diff is empty, no coordinate, provenance, verification flag,
UUID or translation relationship changed. Rollback does not cover: the
documentation corrections, which are independently correct and should stay.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The resolver now disambiguates a shared `_leisure_export_uuid` by slug then
language and fails safely when it cannot, all seven regression tests exist and
pass (29 new assertions), the documentation drift is fixed, and the repair
provably changed **zero** records — but one required verification capability was
unavailable: **mobile UUID consumption could not be checked**, because the
Flutter/mobile repository is out of scope for every task in this repository.

_Last verified: 2026-11-03 by the Phoenix Park UUID repair_

