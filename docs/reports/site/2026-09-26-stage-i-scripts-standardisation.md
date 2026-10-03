# Stage I — Scripts Standardisation

**Scope:** the WordPress website repository only. Flutter/mobile is frozen and
untouched. This stage standardises the script estate; it performs no migration
and changes no WordPress production behaviour.

**Status:** see §18.

---

## 1. Baseline

| Item | Value |
|---|---|
| Pre-Stage-I checkpoint SHA | `1068a7b145f1f0b4d13b3b0212415c169a3331da` |
| Stage H starting SHA (as briefed) | `7144d79bbbc8c4615c961966d86527b8eb0f9361` |
| Branch | `i18n` |
| Working tree at Stage I start | clean |

**Correction to the brief.** The brief stated Stage H was an uncommitted
working-tree diff requiring a checkpoint commit. It was not: `1068a7b` already
existed as a single commit whose parent is exactly the stated Stage H starting
SHA `7144d79`, and the working tree was clean. Phase 0A therefore had nothing to
isolate — Stage H was already committed and separate. No Stage I change is in
that commit, and no extra checkpoint commit was created. The verified Stage H
delivery (44 files, the shared rollout engine, the job-stage migration, the
tests, docs and evidence) is untouched by Stage I.

All counts below were **re-measured in this checkout**, not copied from the
historical audit. The audit's figures (115 scripts, 67 bootstrapping
`wp-load.php`, 34 Python REST duplicates, 27 inconsistent dry-runs, 42
production URL references) are historical baseline evidence and are labelled as
such wherever they appear.

### Re-measured baseline (evidence: `inventory-baseline.txt`, `inventory-baseline-2.txt`)

| Metric | Value |
|---|---|
| Files under `scripts/` | 123 |
| PHP (top level) | 73 |
| PHP scripts with an inline `wp-load.php` resolution block | 40 |
| …of those, the hand-rolled "walk up from `__FILE__`" loop | 30 |
| PHP scripts hard-coding `/var/www/html/wp-load.php` | 28 |
| PHP scripts defining `WP_USE_THEMES` | 23 |
| Hard-coded production URL literals in executable scripts | 44 hits across 22 files |
| Python scripts | 34 |
| …using `urllib.request` directly | 24 |
| …implementing their own Basic auth | 11 |
| …defining a `WpRest` class | 5 |
| …with their own retry loop | 10 |
| …with their own `X-WP-TotalPages` pagination | 2 |
| Files using `--dry-run` | 12 |
| Files using `--apply` | 2 |
| PHP scripts using a bare positional `'dry-run'` token | 8 |
| PHP scripts using a `DRY_RUN` environment variable | 1 |

## 2. Classification

Classification was evidence-based: a script was moved to `historical/` only when
it was a one-shot stage implementation **and** was referenced solely by
historical records (`docs/reports`, `docs/audit`, `docs/evidence`), never when
current CI, tests or current docs genuinely depended on it.

| Category | Count | Examples | Treatment |
|---|---|---|---|
| build | 2 | `build-plugins-zip.sh`, `build-theme-zip.sh` | current, catalogued |
| run (rollout/import) | 16 | `run-job-translation.php`, `run-polylang-setup.php`, `run-leisure-migration.php` | current, catalogued |
| seed | 12 | `seed-permit-employers-rest.py`, `seed-leisure-*.php` | current, catalogued |
| inventory | 1 | `job-translation-inventory.php` | current, catalogued |
| verify | 14 | `verify-guides-http.sh`, `verify-rest-english.py`, `nav-regression-http-verify.py` | current, catalogued |
| restore | 2 | `restore-updraft-db.sh`, `restore-deleted-town-terms.php` | current, catalogued |
| quality/tooling | 5 | `run-tests.sh`, `lint.sh`, `generate-registry-docs.php`, `phpcs-baseline.php` | current, catalogued |
| local activation / menu / content ops | 21 | `activate-admin-ux.php`, `assign-polylang-nav-menus.php`, `cleanup-sponsor-fields.php` | current, catalogued |
| manual test scripts | 8 | `test-event-crud.php`, `test-admin-ux-e2e-http.sh` | current, catalogued as not-harness-run |
| library | 3 | `lib/bootstrap.php`, `lib/rest.py`, `lib/plan.py` | shared, not runnable commands |
| historical | 43 | `stage32-*`, `stage45-*`, `ivvcc-*`, `c3-production-*`, lazer stage C/D builders | provenance only |
| diagnostic | 3 | `heritage-images-debug.php`, `event-location-coverage.py`, `mondello-phase1-diagnostics.py` | ad-hoc helpers |

## 3. Shared libraries

### `scripts/lib/bootstrap.php` — the canonical PHP bootstrap

The only place in `scripts/` allowed to locate and load WordPress. It resolves
`wp-load.php` from `CONEXAO_WP_ROOT`, the repository root, or `/var/www/html`;
sets `WP_USE_THEMES = false` before the load; never depends on the caller's
working directory; and fails loudly (non-zero) when WordPress cannot be found. It
additionally provides the standard CLI parser, the run header/summary, target
classification and the production write guard. It contains no application logic.

API: `conexao_script_boot( array $spec ) : array`, `conexao_script_load_wordpress()`,
`conexao_script_summary( array $ctx, array $summary ) : int`,
`conexao_script_summary_line()`, `conexao_script_classify_target()`,
`conexao_script_site_url()`, `conexao_script_cli_tokens()`, `conexao_script_fail()`.

### `scripts/lib/rest.py` — the shared Python REST client

Centralises base-URL resolution (`CONEXAO_SITE_URL`, else the documented local
`http://localhost:8080`; **never** production), Basic auth from `WP_USERNAME` /
`WP_APPLICATION_PASSWORD`, retries with linear backoff, `X-WP-TotalPages`
pagination, timeouts, JSON decoding and error handling. Standard library only —
no third-party dependency was introduced. Retry, timeout and pagination are
constructor parameters so a migrated script keeps exactly its previous policy.

API: `RestClient`, `classify_target()`, `resolve_base_url()`, `default_headers()`,
`basic_auth_header()`, `require_credentials()`, `add_common_arguments()`,
`print_header()`, `print_summary()`, `RestError`, `ProductionTargetError`.

### `scripts/lib/plan.py` — the shared machine-readable plan helper

Justified: several current write-capable scripts and the Stage H engine each
built their own plan shape. One fixed bucket order (`create`/`update`/`skip`/
`conflicts`), deterministic ordering, and secret-bearing keys stripped, so a
plan can never leak an `Authorization` value into evidence.

API: `PlanBuilder`, `build_plan()`, `write_plan()`.

`lib/` is imported by path insertion, the same way the acceptance suites import
`tests/acceptance/lib`; there is no `__init__.py` and nothing to install.

## 4. CLI contract

| Flag | Behaviour |
|---|---|
| `--help` | Prints purpose, target, scope, safety, modes, arguments, environment. Exits 0. |
| `--dry-run` | Plan only. **Default for a write-capable script.** Zero writes. |
| `--apply` | The only write mode. Mutually exclusive with `--dry-run`. |
| `--confirm-production` | Required before a write when the resolved target is production. |
| `--json` | Machine-readable result on stdout; the run header moves to stderr. |

Mutual exclusion and unknown-argument failure both exit non-zero (2). A blocked
production target exits 3. Exit 0 means success, including "nothing to do".

## 5. Naming changes

| Old path | New path |
|---|---|
| `scripts/stage6-job-translate.php` | `scripts/run-job-translation.php` |
| `scripts/stage6-job-inventory.php` | `scripts/job-translation-inventory.php` |
| `scripts/stage2-polylang-setup.php` | `scripts/run-polylang-setup.php` |
| `scripts/stage2-http-verify.sh` | `scripts/verify-polylang-http.sh` |
| `scripts/stage9-guide-http-verify.sh` | `scripts/verify-guides-http.sh` |
| `scripts/stage41-rest-verify.py` | `scripts/verify-rest-english.py` |
| `scripts/stage43-verify-english.py` | `scripts/verify-english-http.py` |

Each was renamed only where the new name describes a genuinely current reusable
capability and the old name encoded obsolete stage numbering. Scripts whose
names already described their capability were not renamed for aesthetics.
`stage6-job-translate.php` continues to drive the Stage H engine unchanged.

## 6. Historical moves

43 files moved to `scripts/historical/` with `git mv`, contents preserved except
where a path-only update was required. Groups: `stage2-*` probe; `stage32-*`;
`stage33-*`; `stage45-*` page-translation tooling; `stage5-*`; the `stage6-job-*`
verification/scanning helpers (the runner itself stayed current); `stage7-*`;
`ivvcc-*`; `mondello-*` / `mp-*` importers; `c3-production-*`; `apply-lazer-stage-c-*`;
`build-lazer-stage-d-zip.*`; lazer expansion report/verifiers.

No historical script is invoked by CI, imported by a current script, or
documented as a runnable command.

## 7. Diagnostics

`scripts/diagnostics/` contains three ad-hoc investigation helpers that are not
part of the supported workflow: `heritage-images-debug.php`,
`event-location-coverage.py`, `mondello-phase1-diagnostics.py`. They are
classified as `diagnostic` and may change or be removed without notice. No
directory was created for symmetry, and no active verification script was moved
there.

## 8. Catalogue

| Metric | Count |
|---|---|
| Current runnable scripts | 71 (PHP 51, Python 12, Bash 8) |
| Historical scripts | 43 |
| Diagnostic scripts | 3 |
| Library/support files | 4 |
| Catalogue completeness | 71/71 (gate: 275 assertions, 0 failures) |

`scripts/README.md` is the current catalogue. It is the only script list; no
`scripts.json` or other competing registry was introduced, and `plugins.json`
remains the plugin source of truth.

## 9. Production URL cleanup

| | Before | After |
|---|---|---|
| Hard-coded production URL literals in executable scripts | 44 hits / 22 files | **0** in current operational code |

Remaining occurrences are all legitimate and deliberately retained: the shared
library and `run-tests.sh` must know the production host in order to *refuse*
it; historical scripts keep the literal they had; and one REST User-Agent names
the project URL for contact, which is an identity string, not a target.

## 10. Bootstrap cleanup

| | Before | After |
|---|---|---|
| PHP scripts resolving `wp-load.php` themselves | 40 | 0 |
| …hand-rolled walk-up loops | 30 | 0 |
| …hard-coded `/var/www/html/wp-load.php` | 28 | 0 |
| Current PHP scripts using the shared bootstrap | 0 | 46 |
| Documented exceptions (reason stated in the file) | — | 4 |

## 11. REST duplication cleanup

The shared client now exists and the REST seeder (`seed-permit-employers-rest.py`)
was migrated onto it with its endpoint paths, retry count, timeout and pagination
rule preserved exactly. Duplication across the remaining Python scripts is
**partially** reduced (see §17) — the count below is therefore reported honestly
rather than claimed as complete.

| | Before | After |
|---|---|---|
| Scripts with their own Basic auth | 11 | 10 |
| `WpRest` class definitions | 5 | 4 |
| Scripts with their own retry loop | 10 | 9 |

## 12. Dry-run standardisation

| | Before | After |
|---|---|---|
| Files using `--dry-run` | 12 | 14 |
| Files using `--apply` | 2 | 4 |
| PHP scripts using a bare positional `'dry-run'` | 8 | 7 |
| PHP scripts using a `DRY_RUN` env var | 1 | 1 (documented legacy) |

`run-job-translation.php` still accepts the bare `dry-run` / `json` tokens
because WP-CLI `eval-file` forwards positional tokens; it maps them onto the
standard flags and prints a deprecation notice. The `CLEANUP_DRY_RUN` env var in
`cleanup-event-town-terms.php` is retained and documented as a legacy
compatibility path with a documented reason.

## 13. Failure proofs

Every defect was injected, observed and reverted. Full detail in
`docs/evidence/2026-09-26-stage-i/failure-proofs.txt`.

| Proof | Injected defect | Observed |
|---|---|---|
| A | unlisted current script | gate exit 1, "not catalogued" |
| B | current doc referencing a renamed path | gate exit 1, "still references the removed path" |
| C | catalogue entry removed | gate exit 1, "not catalogued" |
| D | PHP script resolving `wp-load.php` itself | gate exit 1, "must require scripts/lib/bootstrap.php" |
| E | production URL as a REST script default | gate exit 1, "hard-codes the production host" |
| F | historical script referenced without the prefix | gate exit 1, "without the historical/ prefix" |
| G | `--dry-run --apply` accepted together | argparse exclusivity is the only defence for that script; detected on review, not by exit code |
| H | production apply without confirmation (PHP) | exit 3, no write; with confirmation the guard passes and prints the exact target; unknown target fails closed |
| I | production apply without confirmation (Python) | exit 1 before any HTTP request, after the warning banner |
| J | representative `--dry-run` run | md5 of all job/post/page rows and the row count unchanged after two runs |
| K | representative `--apply` run, twice | `pt_changed=0`, gate PASS, md5 unchanged — verified idempotent no-op |

## 14. Tests

Measured in this checkout (not copied):

| Run | Result |
|---|---|
| `./scripts/run-tests.sh` | 50 in-process suites, 35 passed, **15 failed**; 3306 assertions passed, 59 failed; 1 script-contract suite passed; 2 acceptance suites passed |
| `./scripts/run-tests.sh --only theme` | 25 in-process suites, 14 passed, **11 failed**; 1805 assertions passed, 54 failed |
| `./scripts/run-tests.sh --acceptance` | 2 suites, 2 passed, 0 failed |
| script-contract layer | 1 suite, 275 assertions passed, 0 failed |
| Stage H regression (`--only conexao-translation-rollout`) | 3 suites, 46 assertions passed, 0 failed |
| Stage H regression (`--only conexao-job-translation`) | 1 suite, 16 assertions passed, 0 failed |

The 15 failures are **identical to the Stage H baseline**: the same 50/35/15
split, the same 3306/59 assertion counts, the same 25/14/11 theme split with
1805/54, and a failing-suite set that was diffed and is byte-identical before
and after the remaining Stage I commits. They are pre-existing
content/data failures, left visible and untouched: no suite was removed, no
assertion weakened, and no unrelated data failure was "fixed".

## 15. Static verification

Reported per component; `lint.sh` is **not** claimed green.

| Check | Result |
|---|---|
| `php -l` (432 repo PHP files) | PASS, 0 parse errors |
| `python3 -m compileall scripts tests` | PASS |
| ShellCheck 0.11.0 (Docker) on `scripts/*.sh` | PASS, 0 findings (host shellcheck absent) |
| PHPStan level 5, configured scope | PASS, no errors |
| PHPStan level 5 on `scripts/lib/bootstrap.php` | PASS (one real defect found and fixed) |
| PHPCS, repository scope (2661 files) | PASS, 0 errors / 0 warnings (container-compensated) |
| `./scripts/lint.sh` | **NOT RUNNABLE to completion on this host** |

`lint.sh` fails for two independent environment reasons: the host PHP lacks the
`xmlwriter` and `SimpleXML` extensions PHPCS requires, and `composer` is absent.
Each component was therefore run individually with a compensating check; none is
reported as green through `lint.sh` itself. No PHPCS or PHPStan baseline was
changed to hide anything.

## 16. Runtime safety

| Item | Count |
|---|---|
| Production HTTP requests | 0 |
| Production DB writes | 0 |
| Production content changes | NONE |
| Production plugin activation/deactivation | NONE |
| Polylang production changes | NONE |
| REST production changes | NONE |
| Theme runtime changes | NONE |
| Plugin runtime changes | NONE |
| Flutter/mobile changes | NONE |

`git diff -- wp-content/` is **empty**: no theme or plugin runtime file was
modified. The only production-shaped operation attempted was a guard probe that
was refused before any request.

## 17. Limitations

- **Host tooling.** `composer` and `shellcheck` are absent and the host PHP
  lacks `xmlwriter`/`SimpleXML`. `./scripts/lint.sh` therefore cannot complete
  here. Compensating checks ran in Docker and are reported separately in §15.
- **Shared REST migration is partial.** `scripts/lib/rest.py` exists and is
  proven, and the REST seeder uses it, but the remaining Python REST scripts
  still carry their own auth/retry/pagination code. Migrating them is behaviour-
  preserving but individually verifiable work that was not completed here; §11
  reports the measured, unflattering numbers rather than claiming completion.
- **Shared CLI contract is partial.** `run-job-translation.php` and
  `job-translation-inventory.php` fully implement the contract. Other current
  scripts use the shared bootstrap and are catalogued with their declared safety
  level, but have not all been converted to `conexao_script_boot()`.
- **Legacy compatibility paths retained**, each with a documented reason: the
  bare `dry-run`/`json` tokens in `run-job-translation.php`, and the
  `CLEANUP_DRY_RUN` environment variable in `cleanup-event-town-terms.php`.
- **Documented bootstrap exceptions** (reason stated in each file):
  `generate-registry-docs.php` and `phpcs-baseline.php` are static tooling that
  never loads WordPress; `generate-logo-derivatives.php` runs only through
  WP-CLI; `import-wix-export.php` requires an already-loaded WordPress.
- **Ambiguous scripts were preserved in place** rather than guessed at, and the
  ambiguity is recorded in the catalogue.
- **Hosted CI was not run.** No hosted run exists for these commits; the local
  harness and the gate results above are what was actually measured.

## 18. Final state

| Item | Value |
|---|---|
| Stage H checkpoint SHA | `1068a7b145f1f0b4d13b3b0212415c169a3331da` |
| Stage I final SHA | `c6b01093333b8d9ce9037a20844dc91591ed479f` |
| Stage I commits | 7 (helpers · classification · standardisation · catalogue · tests · docs · evidence) |
| Current runnable scripts | 71 (PHP 51, Python 12, Bash 8) |
| Historical / diagnostic / library | 43 / 3 / 3 |
| Shared libraries | `scripts/lib/bootstrap.php`, `scripts/lib/rest.py`, `scripts/lib/plan.py` |
| Catalogue completeness | 71/71 — gate 275/275 |
| Test baseline | identical to Stage H; no new failures |
| CI | script-contract gate blocking via the existing `run-tests.sh` job; no second workflow |
| Working tree | clean |
| `wp-content/` changes | none |

**Status: PASS WITH LIMITATION.** Stage H was cleanly separated, the shared
bootstrap, REST client and plan helper exist, the current script contract,
catalogue and blocking gate are in place, renames and classification are
complete, and the safety contract is proven with real injected defects. The
limitation is environmental and partial, and is stated explicitly rather than
hidden: `./scripts/lint.sh` cannot complete on this host (compensated
component-by-component), the shared REST client is adopted by one script rather
than all of them, and the full CLI contract is adopted by the two Stage H
drivers rather than every current script. Documented exceptions and legacy
compatibility paths remain, so the repository is **not** claimed to be fully
standardised.

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
