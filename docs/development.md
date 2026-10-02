# Development

## Local Setup

1. Ensure Docker and Docker Compose are installed.
2. Clone the repository.
3. Run `docker compose up -d` from the project root.
4. Access WordPress at http://localhost:8080.
5. Complete the WordPress installation wizard (if first run).

## Quality tooling (Stage C — static analysis)

Composer is **development tooling only**: it exists for local/CI static
analysis and is **never deployed** to production (production is
WordPress.com; nothing from `vendor/` ships). Install it once after cloning:

```bash
composer install          # creates vendor/ (git-ignored)
```

The commands (definitions in `composer.json`, ruleset in `phpcs.xml.dist`,
static analysis in `phpstan.neon.dist`):

| Command | What it runs |
|---|---|
| `composer lint` | PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP). **Raw debt view** — exits non-zero while any legacy violation exists. |
| `composer lint:fix` | PHPCBF (auto-fixes what PHPCS can fix). Run it on files you touched, then re-check the baseline. |
| `composer analyse` | PHPStan level 5 with WordPress support (`phpstan-wordpress`) — exits 0 today because legacy errors are in the baseline. |
| `composer check` | `composer lint` + `composer analyse` (green only when the PHPCS debt is fully paid down). |
| `./scripts/lint.sh` | **The Stage C quality gate**: PHP syntax sweep (`php -l`) + PHPCS baseline check + `composer analyse`. Fails on any *new* violation. |

- **PHPCS is the PHP coding standard gate**; **PHPStan is the static type
  analyser**. `scripts/lint.sh` is the single entry point that fails a change
  when it makes things worse.
- **Legacy baselines.** The repository's pre-existing debt is recorded, not
  fixed, by Stage C:
  - `phpcs-baseline.json` — per-sniff legacy ERROR/WARNING counts. It **must
    not grow**: any increased or new sniff count fails `./scripts/lint.sh`
    (`NEW VIOLATIONS` output). After *deliberately* paying down debt,
    regenerate it with
    `php scripts/phpcs-baseline.php update <report.json> phpcs-baseline.json`
    (generate the report with
    `vendor/bin/phpcs -q --report=json > <report.json>`).
  - `phpstan-baseline.neon` — the 298 legacy level-5 errors (generated
    2026-09-25). It **must not grow**: a new error fails `composer analyse`.
    After *deliberately* fixing legacy errors, regenerate it with
    `vendor/bin/phpstan analyse --generate-baseline`.
  - New violations introduced after Stage C are always visible (the gates
    fail); shrinking the baselines is the expected direction.
- **PHP floor**: PHPCS `testVersion` is `8.0-` (engineering standard §2.1).
  The toolchain runs on PHP ≥ 8.0 (verified on 8.5). Runtime headers still
  declare 7.4/8.0 in places — aligning them is later-stage work; Stage C
  does not change runtime compatibility claims.
- **CI**: see [Continuous integration (Stage D)](#continuous-integration-stage-d)
  below — `.github/workflows/ci.yml` runs these same checks on every
  push/PR. Locally, run `./scripts/lint.sh` before committing PHP changes
  and `shellcheck scripts/*.sh` before committing shell changes.
- No Node/JS tooling is used or required (plain JS/CSS assets; adding a JS
  build stack is a separate, explicit decision — engineering standard §2.2).

The PHPStan bootstrap `docs/dev/phpstan-bootstrap.php` is development/static
analysis only — it is **never loaded by WordPress** and never deployed; it
only declares constants/types the analyser cannot discover (Polylang API
stubs, theme/plugin constants).

## Continuous integration (Stage D)

CI is defined by a single workflow: **`.github/workflows/ci.yml`** (name
`WordPress CI`, engineering standard §1.7). It automates the Stage C quality
system — it does not reimplement it. The authoritative policy stays in
[engineering-standard.md](engineering-standard.md); this section only
documents the contract.

**Location:** `.github/workflows/ci.yml` (one canonical CI entrypoint).

**Triggers:** every `push` (any branch, including feature branches),
every `pull_request`, plus `workflow_dispatch` for a manual re-run. No path
filters — a regression is caught regardless of which file changed. Obsolete
runs for the same ref are cancelled via `concurrency` keyed on the workflow
name + `github.ref`.

**Runner / PHP:** `ubuntu-latest`, PHP **8.5** via `shivammathur/setup-php@v2`
(`tools: composer:v2`). 8.5 is the version the Stage C toolchain was verified
on; the declared compatibility **floor stays 8.0** (`phpcs.xml.dist`
`testVersion: 8.0-`) — CI does not change that policy, and no `Requires PHP:`
header is touched by this stage.

**Permissions:** `contents: read` — nothing else. The workflow requires **no
repository secrets** and no external service.

**Cache:** only the Composer *download* cache (`~/.cache/composer`), keyed on
`hashFiles('composer.lock')` so a changed lockfile invalidates it. `vendor/`,
WordPress runtime state, credentials and data are never cached, and the
install always runs from the lockfile, so a cache hit can never replace it.

**Action runtime (Node 24).** Every GitHub Action the workflow references runs
on **Node 24**: `actions/checkout@v5`, `actions/cache@v5` and
`actions/upload-artifact@v6`. GitHub removed Node 20 from GitHub-hosted
runners (final removal 2026-09-23), so a `node20` action can no longer run
natively — the runner would previously force it onto Node 24 and log a
deprecation warning. Each version above is the lowest major of that action that
declares `runs.using: node24` while keeping the previous major's input names
and defaults, so checkout depth, artifact names/paths/retention, cache keys and
every workflow input are unchanged. The approved set is normative in
[engineering-standard.md](engineering-standard.md) §1.7; moving to a higher
major is a separate change, not routine upkeep.

### Blocking gates

| Command | Role |
|---|---|
| `composer validate --strict --no-interaction` | `composer.json` is well-formed and **`composer.lock` is in sync** with it. |
| `composer install --no-interaction --prefer-dist --no-progress` | Deterministic install **from the committed lockfile**. CI never runs `composer update`. |
| *(lockfile guard)* | Asserts `git diff -- composer.json composer.lock` is empty after the install — CI can never mutate the lockfile. |
| `./scripts/lint.sh` | **The primary quality gate.** `[1/3]` PHP syntax sweep (`php -l` over every Git-known PHP file) · `[2/3]` PHPCS vs `phpcs-baseline.json` · `[3/3]` PHPStan level 5 via `composer analyse`. Fails on **new** syntax/style/type defects. |
| `shellcheck scripts/*.sh` | Repository shell scripts. ShellCheck is installed explicitly from apt in the workflow (the runner's copy is not a documented guarantee). |

A non-zero exit from any of these **fails the job**. There is no
`continue-on-error` and no `|| true` on any of them.

### Legacy PHPCS debt semantics

The pre-Stage-C debt (3290 errors + 2672 warnings across 151 files, recorded
in `phpcs-baseline.json`) **remains, and CI does not require it to be zero**:

- `./scripts/lint.sh` is the blocking gate. It compares the fresh PHPCS report
  against the baseline and fails on any *new* sniff or any sniff whose counts
  grew — i.e. **debt must not grow**.
- `composer lint` is run as a **non-blocking, clearly-labelled telemetry
  step** (`continue-on-error: true`). It is the raw debt view and is red by
  design. It never writes the baseline, never commits and never mutates
  source. Its non-zero result cannot fail an otherwise-valid run.
- A future debt-reduction stage is what will turn the raw command green.

**PHPStan semantics:** level **5**, with the 331 legacy errors isolated in
`phpstan-baseline.neon`. The baseline is part of the blocking gate and must
not grow: a new PHPStan error fails `./scripts/lint.sh`. CI neither
regenerates the baseline nor changes the level, and does not upgrade
`phpstan/phpstan`.

**Version floor.** `phpstan/phpstan` is pinned at `^2.2` and
`szepeviktor/phpstan-wordpress` at `^2.0`. The two MUST be upgraded together:
the 1.x line of the WordPress extension hard-requires `phpstan/phpstan:
^1.10.31`, so raising PHPStan alone is unsatisfiable. The 1.12.x "old
version" notice is gone as of this upgrade. The baseline was re-generated
once for the 2.x rule set (the stricter always-true/impossible-type checks
report additional pre-existing debt); it is **frozen again at 331 from this
point** and must not grow.

**No production contact.** CI is read-only with respect to production: it
never connects to conexaobr.ie or WordPress.com and deploys nothing. The
Docker integration job is a **required blocking push/PR check**: it
provisions an isolated WordPress database, configures Polylang through the
repository setup script, builds the deterministic synthetic site from the
committed fixtures, and runs the shared harness. It used to be
`workflow_dispatch`-only while the content-dependent suites still lacked a
deterministic synthetic site; that requirement is now satisfied. It remains
an ordinary blocking integration job, so normal CI failure semantics —
including infrastructure failures — still fail the run.

## Restoring a Production UpdraftPlus Backup (Local Only)

Production UpdraftPlus database dumps declare `SET NAMES latin1` and
`DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`, but the text payload inside
them is valid UTF-8 (e.g. "Capacitação" as raw UTF-8 bytes). Importing such a
dump as-is produces mojibake ("CapacitaÃ§Ã£o") because MySQL misinterprets the
UTF-8 bytes as latin1.

`scripts/restore-updraft-db.sh` handles this safely:

```bash
# Fresh restore into a recreated local database (drops existing local data!)
./scripts/restore-updraft-db.sh /path/to/backup_YYYY-MM-DD-...-db_

# Same, but keep the production siteurl/home instead of http://localhost:8080
./scripts/restore-updraft-db.sh /path/to/backup_...-db_ --keep-urls
```

What it does (never modifies the original backup, local database only):

1. Verifies the dump payload is valid UTF-8 before touching anything.
2. Verifies every `latin1` occurrence is structural (SET NAMES / table DDL) and
   that none sits inside an `INSERT INTO` data row; aborts otherwise.
3. On a temp copy only, rewrites `SET NAMES latin1` → `SET NAMES utf8mb4` and
   the table `DEFAULT CHARSET`/`COLLATE` to `utf8mb4`/`utf8mb4_unicode_ci`.
4. Drops and recreates the local database (`utf8mb4_unicode_ci`) and imports
   with `mysql --default-character-set=utf8mb4`, so UTF-8 payload bytes are
   stored unchanged.
5. Points `siteurl`/`home` at `http://localhost:8080` (unless `--keep-urls`).
6. Runs raw-byte verification (HEX checks + binary-safe mojibake scan) against
   posts, terms, termmeta, postmeta, options and usermeta.

`compose.yaml` pins `WORDPRESS_DB_CHARSET=utf8mb4` and
`WORDPRESS_DB_COLLATION=utf8mb4_unicode_ci` so the generated `wp-config.php`
matches the restored database. Do not change these to `latin1`.

Notes:

- This is a DB-only restore: uploads/images referenced from production URLs are
  not included (import the UpdraftPlus uploads archive separately if needed).
- The dump contains production URLs in post content; only `siteurl`/`home` are
  rewritten. Run a WP-CLI search-replace locally if you need the rest rewritten.
- Never run this script against production.

## PHP Upload Limits (Local)

- The official `wordpress` image defaults to `upload_max_filesize = 2M` and `post_max_size = 8M`, which is too small for large event/Lazer export files.
- `docker/php/uploads.ini` is mounted into the container at `/usr/local/etc/php/conf.d/zz-conexao-uploads.ini` and raises the limits to 300M (plus memory/time limits for long imports).
- Changes to that file require a container restart: `docker compose restart wordpress`.
- Production equivalents are managed by the hosting platform (WordPress.com) and cannot be changed from this repo.
- Both import screens (Events → Import Events, Lazer → Importar Lazer) display the effective maximum upload size, validate file size client-side before submitting, and show a clear error notice if a POST is rejected by `post_max_size`.

## Common Commands

```bash
# Start environment
docker compose up -d

# Stop environment
docker compose down

# View logs
docker compose logs -f wordpress

# WP-CLI
docker compose exec wordpress wp --info
docker compose exec wordpress wp plugin list
docker compose exec wordpress wp user list

# Build plugins (creates ZIPs in dist/)
./scripts/build-plugins-zip.sh

# Build theme (creates ZIP in dist/)
./scripts/build-theme-zip.sh
```

## Debugging

- `WORDPRESS_DEBUG=1` is set by default in `.env.example` / `compose.yaml`.
- WP_DEBUG is enabled.
- PHP error logs: `docker compose logs wordpress`

## Cache Clearing

- WordPress object cache (transients) can be cleared via WP-CLI:
  ```bash
  docker compose exec wordpress wp transient delete-all
  ```
- Homepage transients auto-invalidate on save (`conexao_homepage_cache_invalidate()`).
- Filter term caches auto-invalidate on save.
- `.htaccess` sets long-lived browser cache (1 year) for fingerprinted CSS/JS assets; clear browser cache or append a new query string to force updates.

## Modifying Templates Safely

1. All templates are in `wp-content/themes/conexao-br-irlanda/`.
2. Template parts are in `template-parts/`.
3. Reuse shared components instead of duplicating markup.
4. Use the design-system CSS variables (`--color-*`) instead of hard-coded colors.
5. When adding a new CSS file, add it to `conexao_enqueue_scripts()` in `functions.php`.
6. Always add `loading="lazy"` to below-fold images.

## Modifying Plugins Safely

1. All custom plugins are in `wp-content/plugins/conexao-*`.
2. When adding meta fields, register them via `register_post_meta()`.
3. When adding CPTs/taxonomies, add them to `conexao-data-model`.
4. Follow the plugin load order in `plugins.json` (the authoritative registry).

## Testing Responsive Behavior

- Resize the browser window to test breakpoints (768px mobile/tablet boundary).
- Use browser DevTools device emulation.
- Test dark mode toggle in both themes.

## Multilingual (EN) development — Stage 2

English support (`/en/`) is provided by **Polylang 3.8.9 (Free)** and the theme's
single integration layer `wp-content/themes/conexao-br-irlanda/inc/i18n/`.
See `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` for the full gate results.

```bash
# 1. Install + activate Polylang (local only, never production)
docker compose exec -T wordpress wp plugin install polylang --version=3.8.9 --allow-root
docker compose exec -T wordpress wp plugin activate polylang --allow-root

# 2. Configure languages + assign existing content to pt_BR (idempotent)
docker compose exec -T wordpress wp eval-file - --allow-root < scripts/run-polylang-setup.php
#    dry run:  ... < scripts/run-polylang-setup.php dry-run

# 3. Verify the URL/SEO/cache/REST matrix (HTTP level)
./scripts/verify-polylang-http.sh http://localhost:8080

# 4. Stage 2 gates (language, identity, status, UUID, taxonomy)
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-language-identity.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php
```

## Translation rollouts — the shared engine (Stage H)

Every one-shot EN translation stage runs on the shared engine
`conexao-translation-rollout`, which owns the whole content-change contract:
inventory → manifest → dry-run plan → snapshot → apply → verify + numeric gate,
plus a remove/rollback path for stages that declare it safe. A stage supplies
only a versioned data manifest and a small configuration; it never copies
`apply.php` / `audit.php` / an admin class.

```bash
# Preview (dry run) — zero writes
cat scripts/run-job-translation.php | docker compose exec -T wordpress \
  wp eval-file - --allow-root -- dry-run
cat scripts/run-job-translation.php | docker compose exec -T wordpress \
  wp eval-file - --allow-root -- dry-run json

# Apply (LOCAL/STAGING ONLY)
cat scripts/run-job-translation.php | docker compose exec -T wordpress \
  wp eval-file - --allow-root

# Engine + migrated-stage suites
```

Production (WordPress.com, no WP-CLI) uses the shared admin screen: **Tools →
Translation Rollouts** (Preview, then Apply). The contract, the stage-config
and data-manifest shapes, the gate, and the recipe for adding the next rollout
are documented in
[`docs/plugins/conexao-translation-rollout.md`](plugins/conexao-translation-rollout.md).

**Never run a rollout against production casually.** Dry-run is mandatory
before apply, snapshots precede writes, the gate must be numeric, PT stays
canonical, EN is a linked translation, and local post IDs are not portable
identifiers.

## Multilingual (EN) development — Stage 9 (Guides)

The Guide CPT is a **real English translation** since Stage 9: one linked EN
`guide` per public PT guide + the linked EN `conexao_category` terms, so
`/en/guias/` is a genuine English archive.

```bash
# 1. Local runner (LOCAL ONLY; loads the plugin itself)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php dry-run
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php

# 2. In-process suite (completeness gate, pairs, identity, filter, PT regression)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php

# 3. HTTP contract (archive, singles, canonical, hreflang, filter, pagination, sitemap)
./scripts/verify-guides-http.sh
```

The Stage 9 guide rollout now runs as the `en-guide` stage of
`conexao-en-translation` on the shared engine, so there is no longer a
guide-specific admin screen or runner. The historical Stage 9 record is
`CONEXAO_BR_ENGLISH_GUIDES_TRANSLATION_REPORT.md`; the supported procedure is
the [manual translation operator runbook](translation-manual-operator-runbook.md).

Notes:

- Polylang is installed in the container volume (`/var/www/html/wp-content/plugins/polylang`),
  **not** committed to this repository. Deactivating it restores single-language
  behaviour (every integration helper is capability-guarded).
- Translated post types/taxonomies are declared in code (`pll_get_post_types` /
  `pll_get_taxonomies` in `inc/i18n/guard.php`), so local/staging/production cannot drift.
- Local PHP uses opcache with `validate_timestamps=On` and
  `revalidate_freq=2`; when editing theme PHP during a request-heavy loop, allow
  ~2 s or the previous bytecode may still be served.

## Running Scripts

Utility scripts are in `scripts/`. **`scripts/README.md` is the authoritative
catalogue**: it lists every current runnable script with its purpose, safety
level, arguments, default mode, target and last-verified date, and it is the
place to look for the correct invocation of anything below.

Most PHP scripts are WP-CLI eval files:

```bash
docker compose exec wordpress wp eval-file scripts/seed-course-providers.php
docker compose exec wordpress wp eval-file scripts/run-event-import.php
docker compose exec wordpress wp eval-file scripts/run-leisure-migration.php
```

### Script contract (Stage I)

Every current script follows one entry contract:

| Flag | Meaning |
|---|---|
| `--help` | Purpose, target, scope, safety, modes, arguments, environment. Exit 0. |
| `--dry-run` | Plan only. **The default for a write-capable script.** Zero writes. |
| `--apply` | The only write mode. Mutually exclusive with `--dry-run`. |
| `--confirm-production` | Required before a write against a production target. |
| `--json` | Machine-readable result on stdout (the run header moves to stderr). |

Every run prints what/where/mode/result — `script:`, `target:`, `mode:`,
`scope:` at the start and a `summary:` line at the end:

```bash
docker compose exec -T wordpress wp eval-file scripts/run-job-translation.php \
  --allow-root -- --dry-run
```

**No script defaults to a production write.** The target comes from
`CONEXAO_SITE_URL` (PHP and Python) or `--base-url` / `--site`, and defaults to
the local stack; production must be named explicitly and confirmed.

Shared helpers, used by new scripts rather than re-implemented per script:

- `scripts/lib/bootstrap.php` — the canonical PHP bootstrap. The only place in
  `scripts/` allowed to locate and load WordPress, plus the CLI parser, run
  header, target classification and production write guard.
- `scripts/lib/rest.py` — the shared Python REST client (base URL,
  `WP_USERNAME`/`WP_APPLICATION_PASSWORD` auth, retries, pagination, errors).
- `scripts/lib/plan.py` — the shared machine-readable plan document.

`scripts/historical/` holds one-shot stage scripts kept for provenance only —
they are **not** supported tooling. `scripts/diagnostics/` holds ad-hoc
investigation helpers that are not part of the supported workflow.

## Tests (Stage E — unified test harness)

The repository has **one** test command. It runs both layers and returns one
aggregate exit code:

```bash
docker compose up -d                 # local WordPress must be running first

./scripts/run-tests.sh               # all layers
./scripts/run-tests.sh --only theme  # one component
./scripts/run-tests.sh --scripts     # the Stage I script-contract gate only
./scripts/run-tests.sh --acceptance  # HTTP acceptance only
./scripts/run-tests.sh --list        # discovered suites + manual suites
```

- **Three layers, one command, one exit code:** in-process PHP suites, the
  Stage I **script-contract** layer (`tests/scripts/verify-*.py` — static,
  no WordPress, no network, so it runs anywhere) and HTTP acceptance.
  There is no second test runner.
- The script-contract gate is **blocking** in CI. It fails when a current
  runnable script is missing from `scripts/README.md`, when a current script
  hard-codes a production target or contains a credential, when a PHP script
  resolves `wp-load.php` itself instead of using `scripts/lib/bootstrap.php`,
  or when a current document still references a renamed/removed script path.

- **Local Docker is a requirement.** The in-process suites need a real
  WordPress (they run inside the `wordpress` container, where `wp-load.php`
  lives) and the acceptance suites need the site answering on
  `http://localhost:8080`. Without them the runner reports
  `BLOCKED: HTTP acceptance environment unavailable` and exits non-zero — it
  never silently downgrades to a partial run.
- **Production is never a target.** The acceptance base URL defaults to
  `http://localhost:8080`; a `conexaobr.ie` base URL is refused.
- Full reference: **[docs/testing.md](testing.md)**. The authoritative policy is
  still [engineering-standard.md](engineering-standard.md) §8.

## Repository Hygiene

Generated work trees and local tooling are **not** committed (engineering
standard §1.3, enforced by the root `.gitignore`):

- `stage*-work/` / `*-work/`, `*.body`, `*.log` — disposable probe output and
  run logs. Regenerate on demand; distil findings into `docs/reports/` and
  keep only curated machine-readable proof under `docs/evidence/<date>-<stage>/`
  (see `docs/evidence/README.md`).
- `.local/` — local toolchains. The repository previously shipped a 24 MB
  PHP CLI under `.local/php/`; it was removed in Stage B. To run PHP outside
  the container, use `docker compose exec wordpress php ...` (or `wp ...`
  for WP-CLI scripts), install a system PHP CLI, or point the scripts that
  accept it at your binary via `PHP_BIN=/path/to/php`.

Historical stage reports live in `docs/reports/` (index in
`docs/reports/README.md`); never add reports or evidence files at the
repository root.

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_

_Last verified: 2026-09-28 by CI integration setup recovery_
