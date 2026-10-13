# Environment notes — Stage 23 (how the verification environment was provisioned)

This sandbox has **no Docker, no MySQL/MariaDB and no WordPress**, and port 8080 is
already occupied by the sandbox itself. The canonical runner
(`./scripts/run-tests.sh`) is written for the Compose stack (or CI's Docker
WordPress), so the layers that need WordPress were run against a locally
provisioned, CI-equivalent substitute. This file records exactly what that
environment was, so every number in the report can be reproduced or discounted.

## What was provisioned (no repository file is affected)

| Component | How |
|---|---|
| PHP CLI 8.2.32 | Debian `php8.2-cli`/`php8.2-common`/`php8.2-sqlite3` and their runtime libraries, extracted from `deb.debian.org` into a private prefix (extensions: `tokenizer`, `ctype`, `iconv`, `fileinfo`, `gettext`, `phar`, `posix`, `pdo_sqlite`, `sqlite3`; `memory_limit=1024M`; `date.timezone=UTC`) |
| WordPress 7.1.3 | `https://wordpress.org/latest.tar.gz`, installed with WP-CLI 2.12.0 |
| Database | the official `sqlite-database-integration` drop-in (`wp-content/db.php`) — no MySQL needed |
| Site configuration | theme `conexao-br-irlanda` active; plugins `conexao-data-model`, `conexao-content`, `conexao-event-runtime`, `conexao-event-importer` active; `timezone_string = Europe/Dublin` — the same values CI sets |
| GNU gettext 0.21 | extracted from `deb.debian.org` and exposed to `./scripts/i18n-make-pot.sh` through its documented `XGETTEXT_CMD` hook, to regenerate the two catalogues |

## What that environment does NOT have (and how it shows up)

- **Polylang** (a pinned third-party plugin CI installs): 25 suites fail their
  declared `polylang` prerequisite — they fail *loudly*, exactly as designed.
- **The `pt_BR` core language pack** and **the committed CI fixtures**
  (`scripts/bootstrap-ci-fixtures.php`, including ICS step 4c): suites that assert
  over the synthetic site fail on missing content.
- **The `dom` extension**: two importer suites fatal on `DOMDocument`.
- **Composer**: `./scripts/lint.sh` refuses to run without `vendor/` (PHPCS and
  PHPStan are therefore CI-authoritative for this change).
- **A reachable WordPress at `http://localhost:8080`**: the runner reports
  `BLOCKED: HTTP acceptance environment unavailable` and executes **zero**
  acceptance rows.

## Reproducing the numbers

```sh
# 1. PHP + WordPress as described above (see this file's table).
# 2. Sync the working tree's plugin code into that WordPress, then run:
CONEXAO_TEST_WP_ROOT=/tmp/wp php wp-content/plugins/conexao-event-runtime/tests/test-event-date-transitions.php
CONEXAO_TEST_WP_ROOT=/tmp/wp php wp-content/plugins/conexao-event-runtime/tests/test-event-query.php
CONEXAO_TEST_WP_ROOT=/tmp/wp php wp-content/plugins/conexao-event-importer/tests/test-event-schedule-refresh.php
# 3. The canonical runner against that WordPress (the touched components):
CONEXAO_TEST_WP_ROOT=/tmp/wp ./scripts/run-tests.sh --php --only conexao-event-runtime
CONEXAO_TEST_WP_ROOT=/tmp/wp ./scripts/run-tests.sh --php --only conexao-event-importer
# 4. The whole canonical runner (all three layers), as run for aggregate-run.txt
#    and aggregate-pristine.txt:
CONEXAO_TEST_WP_ROOT=/tmp/wp ./scripts/run-tests.sh
```

`event-date-harness.php` needs no WordPress at all — only a PHP with `pdo_sqlite`:

```sh
php docs/evidence/2026-10-12-stage-23/event-date-harness.php /path/to/repo
```
