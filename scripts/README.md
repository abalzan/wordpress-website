# scripts/ — the authoritative catalogue of repository scripts

This file is the **single source of truth** for the script estate. If a script
is not listed here as a current runnable script, it is either a shared library
(in `lib/`), a historical artefact (`historical/`) or a diagnostic
(`diagnostics/`).

It implements engineering standard §10.2 and the Stage I script contract. The
`tests/scripts/verify-script-conventions.py` gate (run by
`./scripts/run-tests.sh --scripts`) fails when a current runnable script is
missing from this file, when a current script hard-codes a production target, or
when a renamed path is referenced from nowhere.

## Safety levels (controlled vocabulary)

Every current script declares exactly one of these. The vocabulary is
deliberately small:

| Level | Meaning |
|---|---|
| `read-only` | Writes nothing anywhere. Safe to run against any reachable target. |
| `local-write` | Writes, but only to the local/staging install it is executed against. Dry-run is the default. |
| `production-capable-write` | Can write to production. Requires an explicit target and `--confirm-production`. |
| `destructive-local` | Destroys local state (e.g. drops and recreates a local database). Local-only, loudly warned, explicit confirmation. |
| `historical` | Preserved for provenance. NOT supported tooling; never run as normal maintenance. |
| `diagnostic` | Ad-hoc investigation helper. Not part of the supported workflow; may change or be removed without notice. |

## The standard contract

Every **current write-capable** script supports:

| Flag | Meaning |
|---|---|
| `--help` | Prints purpose, target, scope, safety level, modes, arguments and environment variables. Exits 0. |
| `--dry-run` | Plans only. **This is the default.** Performs zero writes. |
| `--apply` | The only mode that writes. Mutually exclusive with `--dry-run`. |
| `--confirm-production` | Required before a write when the resolved target is production. |
| `--json` | Emits the machine-readable result on stdout (the run header moves to stderr). |

Rules the gate enforces:

- `--dry-run` and `--apply` are mutually exclusive; combining them exits non-zero.
- An unknown argument exits non-zero — a typo never silently changes the operation.
- A write-capable script defaults to dry-run, so there is no accidental write mode.
- The resolved target is printed in the run header, and production is visibly
  distinguished from local/staging.
- Exit 0 means success (including "nothing to do"); non-zero means failure,
  including a blocked production target, a validation failure and partial results.
- No current operational script hard-codes a production URL. The target comes
  from `CONEXAO_SITE_URL` / `--base-url`, and there is no production default.

## Standard run header

```
script: run-job-translation.php
target: http://localhost:8080 (local, local)
mode:   dry-run
scope:  Creates/updates English translation records for the job stage only. ...
```

...closed by a single `summary:` line, or by a JSON document with `--json`.

## Shared libraries

| Path | Purpose |
|---|---|
| `scripts/lib/bootstrap.php` | **The canonical PHP bootstrap.** The only place in `scripts/` allowed to locate and load WordPress. Resolves `wp-load.php` from `CONEXAO_WP_ROOT`, the repository root or `/var/www/html`; sets `WP_USE_THEMES=false`; parses the standard CLI flags; prints the run header and summary; classifies the target and enforces the production write guard. |
| `scripts/lib/rest.py` | **The shared Python REST client.** Centralises base-URL resolution (`CONEXAO_SITE_URL`, else the local site — never production), Basic auth from `WP_USERNAME` / `WP_APPLICATION_PASSWORD`, retries with backoff, `X-WP-TotalPages` pagination, timeouts, JSON decoding and error handling. Standard library only. |
| `scripts/lib/plan.py` | Shared machine-readable plan helper. Fixed bucket order (`create`/`update`/`skip`/`conflicts`), deterministic output, secret-bearing keys stripped. Justified: several current write scripts and the Stage H engine each needed a plan shape. |
| `scripts/lib/zip-build.sh` | **The one release-packaging implementation** (Stage J), shared by `build-plugins-zip.sh` and `build-theme-zip.sh`. Owns the §11 exclusion rules (no `tests/`, `fixtures/`, `*.json` reports, junk) and the determinism guarantee (sorted entries, mtime/permission normalisation on a staging copy, `zip -X`). Sourced, never executed. |
| `scripts/lib/release.py` | **The one release-record implementation** (Stage J). Derives the artifact allowlist from `plugins.json`, reads component versions from their headers, resolves git identity, and builds/validates/verifies `dist/release.json`. Standard library only. |

`lib/` is imported by path insertion, the same way the acceptance suites import
`tests/acceptance/lib` — there is no `__init__.py` and no package to install.

### PHP usage

```php
require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot( array(
    'script'             => 'my-script.php',
    'purpose'            => 'One line.',
    'scope'              => "What it writes.\nWhat it never writes.",
    'safety'             => 'local-write; dry-run is the default.',
    'safety_level'       => 'local-write',
    'target_description' => 'The local/staging WordPress.',
    'modes_description'  => '--dry-run plans. --apply writes.',
    'arguments'          => array( '--slug S   Restrict to one record.' ),
    'writes'             => true,
    'production_capable' => false,
    'json'               => true,
) );

// ... work ...

exit( conexao_script_summary( $ctx, array( 'create' => 0, 'errors' => 0 ) ) );
```

`conexao_script_load_wordpress()` can be used on its own when a script only needs
WordPress loaded and not the full contract.

### Python usage

```python
import os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib"))
import rest
from plan import PlanBuilder

client = rest.RestClient(user_agent="my-script/1.0")
client.assert_write_allowed(args.confirm_production)   # production guard
```

## Naming conventions

| Pattern | Meaning |
|---|---|
| `build-<artifact>.sh` | Produces a release artifact |
| `seed-<dataset>.php` | Creates local fixture/demo data (idempotent) |
| `run-<rollout>.php` | Executes a documented rollout/importer |
| `<area>-inventory.php` | Read-only inventory → machine-readable output |
| `<area>-verify.*`, `verify-<area>-http.*` | Verification (assertions + exit code) |
| `restore-<source>.sh` | Destructive local restore (warns loudly) |
| `historical/*` | One-shot stage scripts kept for provenance — not runnable tooling |
| `diagnostics/*` | Temporary debugging helpers |

## Evidence

Curated evidence for each stage lives in `docs/evidence/<date>-<stage>/` (for
Stage I: `docs/evidence/2026-09-26-stage-i/`). No script writes evidence into the
repository root; `dist/`, `*-work/` and `*.log` are git-ignored.

---

## Current runnable scripts

Every entry states: path, purpose, safety level, arguments, default mode,
target/source, writes? and last-verified date.

### Quality gates and build

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/run-tests.sh` | The one test entrypoint: in-process PHP, script-contract and HTTP acceptance layers | read-only | `--php`, `--scripts`, `--acceptance`, `--only <component>`, `--list`, `--help` | runs all layers | local WordPress; acceptance base URL defaults to `http://localhost:8080` and refuses a production host | No | 2026-09-26 |
| `scripts/lint.sh` | Static quality gate: PHP syntax + PHPCS no-new-violations + PHPStan | read-only | none | — | local working tree; needs `composer install` | Writes only to a temp dir | 2026-09-26 |
| `scripts/build-plugins-zip.sh` | Package each `build: true` plugin into `dist/<slug>.zip` from the registry, then emit the release manifest | read-only | none; `BUILD_OUTPUT_DIR` overrides the output | — | `plugins.json` + `wp-content/plugins` | Writes `dist/` (git-ignored) | 2026-09-26 |
| `scripts/build-theme-zip.sh` | Package the active theme into `dist/conexao-br-irlanda.zip`, then emit the release manifest | read-only | none; `BUILD_OUTPUT_DIR` overrides the output | — | theme directory | Writes `dist/` (git-ignored) | 2026-09-26 |
| `scripts/release-manifest.py` | Emit and verify `dist/release.json` — the record of what was actually built (version, git SHA, built-at, file count, bytes, SHA-256) and the allowlist `plugins.json` permits | local-write (`--write`); read-only otherwise | `--write`, `--verify` (default), `--check`, `--print`, `--dist`, `--root`, `--tag`, `--json` | `--verify` (read-only) | `plugins.json` + the build output directory | `--write` only, and only `dist/release.json` | 2026-09-26 |
| `scripts/verify-deploy.py` | HTTP deployment verification: the fixed release smoke matrix + one single per post type + PT/EN language layer. GET only, no credentials | read-only | `--site` (**required, no default**), `--matrix`, `--only`, `--skip-singles`, `--timeout`, `--out`, `--json`, `--list` | read-only | the deployment named by `--site`; there is deliberately no default target | No | 2026-09-26 |
| `scripts/verify-release.sh` | Local end-to-end proof of the whole release workflow: registry gate → build → manifest → allowlist/hash verify → determinism → exclusion proof → local HTTP verification | local-write | `--skip-http`, `--site <url>`, `--keep`, `--help` | runs all seven steps | registry + sources + the local site | Writes only a scratch build dir, removed on exit | 2026-09-26 |
| `scripts/generate-registry-docs.php` | Validate `plugins.json` and generate the derived doc/compose/build regions | read-only (`--check`); `--write` regenerates markers only | `--check`, `--write`, `--build-count`, `--help` | `--check` (zero writes) | `plugins.json` + working tree | Only inside `BEGIN/END` generated markers | 2026-09-26 |
| `scripts/phpcs-baseline.php` | Compare a PHPCS JSON report against `phpcs-baseline.json` | read-only | `check <report> <baseline>`, `update <report> <baseline>` | `check` | local files | `update` rewrites the baseline only on request | 2026-09-26 |

### Restore

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/restore-updraft-db.sh` | Restore a production UpdraftPlus SQL dump into the **local** MySQL container, fixing latin1→utf8mb4 | destructive-local | `<backup-file>`, `--keep-urls`, `--help` | destructive (no dry-run) | local Docker DB only; refuses to be pointed at production | Drops and recreates the local database | 2026-09-26 |

### Seeds (local fixture / demo data, idempotent)

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/seed-course-providers.php` | Create/update the curated `course_provider` directory | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/seed-leisure-locations.php` | Seed `leisure` records from the location dataset | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/seed-leisure-expansion.php` | Seed the Lazer expansion dataset (duplicate-checked) | local-write | none | apply | local WordPress + `scripts/data/` | Yes | 2026-09-26 |
| `scripts/seed-leisure-expansion-images.php` | Attach expansion images | local-write | `--dry-run`, `--slug`, `--shard`, `--shards` | dry-run | local WordPress | Yes (apply) | 2026-09-26 |
| `scripts/seed-leisure-images.php` | Attach leisure images | local-write | `--dry-run` | dry-run | local WordPress | Yes (apply) | 2026-09-26 |
| `scripts/seed-leisure-wikimedia-images.php` | Import Wikimedia images for leisure records | local-write | `--dry-run` | dry-run | local WordPress + Wikimedia | Yes (apply) | 2026-09-26 |
| `scripts/seed-permit-employers.php` | Seed the `permit_employer` records (local/PHP equivalent of the REST seeder) | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/seed-permit-employers-rest.py` | Seed `permit_employer` records over REST (WordPress.com compatible) | production-capable-write | `--dry-run`, `--apply`, `--base-url`, `--confirm-production`, `--json`, `--json-out`, `--help` | dry-run | `CONEXAO_SITE_URL` / `--base-url`; defaults to local, never production | Yes (apply) | 2026-09-26 |
| `scripts/seed-recruitment-agencies.php` | Seed the recruitment-agency directory | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/seed-recruitment-agencies-rest.py` | Seed recruitment agencies over REST | production-capable-write | `--dry-run`, `--base-url` | dry-run | `CONEXAO_SITE_URL` / `--base-url`; defaults to local | Yes (no `--dry-run`) | 2026-09-26 |
| `scripts/seed-recurrence-test-events.php` | Create/remove the recurrence test fixtures | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/update-lazer-images-rest.py` | Replace leisure images over REST (upload + attribution meta) | production-capable-write | `--dry-run`, `--slug`, `--width`, `--force`, `--delete-old`, `--list`, `--base-url` | dry-run | `CONEXAO_SITE_URL` / `--base-url`; defaults to local | Yes (apply) | 2026-09-26 |

### Rollout / import runners

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/run-job-translation.php` | Run the shared rollout engine for the `job` stage (Stage H engine driver) | local-write | `--dry-run`, `--apply`, `--json`, `--help` | dry-run | local/staging WordPress | Yes (apply) | 2026-09-26 |
| `scripts/job-translation-inventory.php` | Machine-readable EN Job translation inventory (read-only) | read-only | `--out=<file>`, `--help` | read-only | local/staging WordPress | No | 2026-09-26 |
| `scripts/run-blog-translation.php` | Run the Blog EN translation rollout | local-write | `--dry-run`, `--json` | dry-run | local/staging WordPress | Yes (apply) | 2026-09-26 |
| `scripts/run-leisure-translation.php` | Run the Leisure EN description rollout | local-write | `preview` (default), `apply`, `remove`, `audit`, `json` | preview (dry-run) | local/staging WordPress | Yes (`apply`/`remove`) | 2026-09-26 |
| `scripts/run-leisure-migration.php` | Run the Lazer content migration | local-write | `dry-run` (default), `apply` | dry-run | local/staging WordPress | Yes (apply) | 2026-09-26 |
| `scripts/run-polylang-setup.php` | Create the pt/en Polylang languages, assert the URL config, assign default language (idempotent) | local-write | `--dry-run` | dry-run | local/staging WordPress | Yes (apply) | 2026-09-26 |
| `scripts/run-event-import.php` | Run the event importer and report results | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/run-eventbrite-import.php` | One-off Eventbrite Laois import | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/run-guide-updates.php` | Apply the guide content update script | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/import-wix-export.php` | Import a Wix export directory into WordPress | local-write | none | apply | `WIX_EXPORT_DIR`; requires loaded WordPress | Yes | 2026-09-26 |
| `scripts/backfill-leisure-attributes.php` | Backfill the leisure attribute taxonomy | local-write | `--dry-run` | dry-run | local WordPress | Yes (apply) | 2026-09-26 |
| `scripts/fix-wikimedia-source-urls.php` | Repair Wikimedia source URLs on leisure records | local-write | `--dry-run` | dry-run | local WordPress | Yes (apply) | 2026-09-26 |
| `scripts/cleanup-event-town-terms.php` | Clean up contaminated `conexao_town` terms | local-write | `CLEANUP_DRY_RUN=true` (legacy env var; prefer `--dry-run`) | dry-run | local WordPress | Yes (apply) | 2026-09-26 |
| `scripts/cleanup-sponsor-fields.php` | Remove obsolete sponsor meta fields | local-write | none | apply | local WordPress (admin capability required) | Yes | 2026-09-26 |
| `scripts/migrate-business-to-sponsor.php` | Migrate `business` posts to `sponsor` | local-write | `--all` | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/remove-noticias.php` | Remove the retired `news` content type and its data (scoped) | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/restore-deleted-town-terms.php` | Restore deleted `conexao_town` terms | local-write | none | apply | local WordPress | Yes | 2026-09-26 |

### Navigation / menus

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/assign-polylang-nav-menus.php` | Assign the per-language navigation menus | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/create-en-primary-menu.php` | Create the English primary menu | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/reorder-primary-menu-blog-apoiadores.php` | Reorder the primary menu (Blog/Apoiadores placement) | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/reorder-primary-menu-rest.py` | REST equivalent of the menu reorder, for hosts without WP-CLI | production-capable-write | `--dry-run`, `--base-url` | dry-run | `CONEXAO_SITE_URL` / `--base-url`; defaults to local | Yes (apply) | 2026-09-26 |
| `scripts/remove-irlanda-menu-item.php` | Remove a menu item | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/remove-sobre-nos-menu-item.php` | Remove a menu item | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/create-empregos-landing-page.php` | Create/update the Empregos landing page | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/flush-navigation-rules.php` | Flush navigation rewrite rules | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/flush-blog-rewrite-rules.php` | Flush the Blog archive rewrite rules | local-write | none | apply | local WordPress | Yes | 2026-09-26 |
| `scripts/generate-logo-derivatives.php` | Generate responsive logo derivatives in the Media Library | local-write | none | apply | local WordPress (WP-CLI) | Yes | 2026-09-26 |

### Activation helpers (local only)

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/activate-admin-ux.php` | Activate the Admin UX plugin and its dependencies, locally | local-write | none | apply | local WordPress | Yes (options only) | 2026-09-26 |
| `scripts/activate-event-importer.php` | Activate the event importer, locally | local-write | none | apply | local WordPress | Yes (options only) | 2026-09-26 |

### Event town terms (REST)

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/wp_rest_backup_towns.py` | Back up all `conexao_town` terms to JSON | read-only | `--site` (required), credentials from env | read-only | explicit `--site` | No | 2026-09-26 |
| `scripts/wp_rest_cleanup_event_towns.py` | Clean up contaminated `conexao_town` terms | production-capable-write | `--site`, `--execute` | dry-run | explicit `--site` | Yes (`--execute`) | 2026-09-26 |
| `scripts/wp_rest_verify_event_towns.py` | Verify no contaminated `conexao_town` terms remain | read-only | `--site` | read-only | explicit `--site` | No | 2026-09-26 |

### Verification

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/verify-polylang-http.sh` | HTTP verification of the Polylang `/en/` routing | read-only | `[base-url]` | read-only | first argument; defaults to `http://localhost:8080` | No | 2026-09-26 |
| `scripts/verify-guides-http.sh` | HTTP verification of the EN guide routes | read-only | `[base-url]` | read-only | first argument; defaults to local | No | 2026-09-26 |
| `scripts/nav-regression-http-verify.py` | HTTP regression matrix for navigation | read-only | `[base-url]` | read-only | first argument; defaults to local | No | 2026-09-26 |
| `scripts/jobs-en-language-verify.py` | Verify EN job language separation over HTTP | read-only | `[base-url]` | read-only | first argument; defaults to local | No | 2026-09-26 |
| `scripts/verify-rest-english.py` | Bilingual REST contract verification matrix (wire-level) | read-only | `[base-url] [host-header]` | read-only | first argument | No | 2026-09-26 |
| `scripts/verify-english-http.py` | English acceptance matrix (GET-only, production-capable) | read-only | `--base-url`, `--phase`, `--group`, `--out`, `--include-rest`, `--help` | read-only | `--base-url` / `CONEXAO_SITE_URL`; defaults to local | No | 2026-09-26 |
| `scripts/verify-lazer-urls.php` | Validate the Lazer dataset URLs and discover Ireland links | read-only | none | read-only | local dataset | No | 2026-09-26 |
| `scripts/verify-admin-ux.php` | Verify the Admin UX plugin classes load and work | read-only | none | read-only | local WordPress | No | 2026-09-26 |
| `scripts/verify-event-transfer.php` | Verify the event export/import functionality | read-only | none | read-only | local WordPress | No | 2026-09-26 |
| `scripts/verify-import.php` | Verify the import result | read-only | none | read-only | local WordPress | No | 2026-09-26 |
| `scripts/verify-recurrence-presentation.php` | Verify recurrence presentation output | read-only | none | read-only | local WordPress | No | 2026-09-26 |
| `scripts/mp-live-ticket-check.php` | Probe whether linked ticket pages still respond | read-only | none | read-only | local WordPress + third-party ticket sites | No | 2026-09-26 |
| `scripts/i18n-check.sh` | i18n catalogue freshness: fail when a `.pot` is older than the PHP defining its strings (standard §9.3) | read-only | none | read-only | local repository (no WordPress, no network) | No | 2026-09-26 |
| `scripts/i18n-make-pot.sh` | Regenerate the i18n catalogues with GNU gettext — the counterpart of `i18n-check.sh` and the §9.3 remedy. Discovers components from the freshness gate's own logic, so the generator and the check cannot disagree. Fails closed if `xgettext` is missing; never hand-edits a `.pot` | write (generated files only) | `--dry-run` (default), `--apply`, `--check`, `<component-slug>` | dry-run | local repository (no WordPress, no network) | Yes — `<component>/languages/<component-slug>.pot` only, never a `.po`/`.mo` | 2026-09-26 |
| `scripts/remediate-shared-taxonomy-language.php` | Remove the stale Polylang language tag from SHARED proper-name taxonomy terms (`conexao_county` / `conexao_town`) so the taxonomy policy holds in the data, not only in policy. Reads the shared/translated split from `PLL()->model->get_translated_taxonomies()`; refuses a term with a real cross-language counterpart; emits a restorable JSON snapshot | write (one taxonomy field) | `--dry-run` (default), `--apply`, `--json` | dry-run | local WordPress | Yes — clears the term language assignment only; never creates, renames, re-slugs, merges or deletes a term | 2026-09-26 |
| `scripts/verify-permanent-gates.py` | Stage L aggregate: run every permanent invariant gate and write `docs/evidence/<date>-stage-l/gate.json` | read-only | `--out`, `--list` | read-only | local repository + local WordPress | No | 2026-09-26 |

### Manual / ad-hoc test scripts

These are not part of the automated harness; they are run by hand during
development and are not covered by `./scripts/run-tests.sh`.

| Path | Purpose | Safety | Arguments | Default mode | Target/source | Writes? | Last verified |
|---|---|---|---|---|---|---|---|
| `scripts/test-admin-ux-e2e-http.sh` | End-to-end wp-admin HTTP regression (creates a temp admin, cleans up) | local-write | none | apply | local WordPress | Yes (temp fixtures, self-cleaning) | 2026-09-26 |
| `scripts/test-admin-ux-save-regression.php` | Simulate the wp-admin save flow and assert field persistence | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/test-sponsor-contacts.php` | Apoiador multi-contact sanitizer/save/export/import coverage | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/test-event-crud.php` | Event CRUD workflow coverage | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/test-event-cleanup.php` | Event cleanup system coverage | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/test-event-imported-workflow.php` | Imported-event workflow coverage | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/test-event-transfer.php` | Event export/import coverage | local-write | none | apply | local WordPress | Yes (temporary posts) | 2026-09-26 |
| `scripts/add-wiki-import-method.py` | One-off source patcher (adds an AJAX method) | local-write | none | apply | a local checkout | Yes (edits a source file) | 2026-09-26 |

---

## Shared libraries (not runnable commands)

`scripts/lib/bootstrap.php`, `scripts/lib/rest.py`, `scripts/lib/plan.py` —
see "Shared libraries" above. Also
`scripts/data/` holds static dataset files consumed by the seeders
(`leisure-expansion-data-*.php`, image retry overrides, URL verification
report). They are data, not scripts.

## Historical scripts

`scripts/historical/` holds 42 one-shot stage implementations kept **for
provenance only**. They are not supported tooling, are not invoked by CI or the
test harness, and must not be presented as normal runnable commands. They were
moved there in Stage I from stage-numbered names (`stage2-*`, `stage32-*`,
`stage33-*`, `stage5-*`, `stage6-job-*` verification/scanning, `stage7-*`,
`stage45-*`, `ivvcc-*`, `mp-*`, `c3-production-*`, lazer stage C/D builders) once
they were superseded and referenced only by historical reports.

To reconstruct past work, run them from that directory. They are not
maintained, are not covered by the script-contract gate, and their behaviour
reflects the state of the repository when the stage ran.

## Diagnostics

`scripts/diagnostics/` holds ad-hoc investigation helpers, classified as
`diagnostic` safety level. They are not part of the supported operational
workflow and may change or be removed without notice:

- `heritage-images-debug.php` — debug/fix Heritage Week event images.
- `event-location-coverage.py` — read-only event location-filter coverage audit.
- `mondello-phase1-diagnostics.py` — Mondello Park phase-1 production diagnostics.

---

## New script contract

A new script is not finished until it satisfies all of the following.

### A new PHP script must

- use `scripts/lib/bootstrap.php` (or be an explicitly documented exception
  with a stated reason, listed in the gate's exception list);
- declare `Purpose`, `Scope` and `Safety` in its source header, and a scope
  statement in `--help` that matches actual behaviour;
- use the standard CLI flags (`--help`, `--dry-run`, `--apply`,
  `--confirm-production`, `--json`) — never a bespoke mode switch;
- default a write-capable operation to dry-run, and perform zero writes in
  dry-run;
- print the run header (script, target, mode, scope) and a closing `summary:`;
- produce machine-readable output (`--json`) where the operation has structured
  results;
- return non-zero on failure, including validation failures and partial results;
- avoid secrets and hard-coded production targets (credentials come from the
  environment, the target from `CONEXAO_SITE_URL`);
- be listed in this file with its safety level, arguments, default mode,
  target, write behaviour and last-verified date.

### A new Python REST script must

- use `scripts/lib/rest.py` for base-URL resolution, credentials, retries,
  pagination and error handling;
- read credentials from `WP_USERNAME` / `WP_APPLICATION_PASSWORD` only, and
  never print or log them;
- resolve its target through `CONEXAO_SITE_URL` / `--base-url`, with a local
  default and no production default;
- enforce the production write guard before any write;
- declare scope and safety, and print target + mode + summary.

### A new historical script must

- live under `scripts/historical/`;
- not be documented as normal runnable tooling, not be invoked by CI, and not
  be imported by current scripts;
- retain its provenance (the original name and behaviour are preserved).

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
