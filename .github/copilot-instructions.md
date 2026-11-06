# Copilot Instructions — Conexão BR Irlanda (WordPress)

This repository is a bilingual Portuguese/English WordPress community portal for Brazilians in Ireland. Local development runs in Docker; production is WordPress.com. The authoritative engineering standard is [`docs/engineering-standard.md`](docs/engineering-standard.md) — where this file and the standard disagree, the standard wins.

## Build, test, and lint commands

### Local environment
```bash
docker compose up -d          # start the local WordPress + MySQL stack (http://localhost:8080)
```

### Test runner (single entrypoint, one aggregate exit code)
```bash
./scripts/run-tests.sh                    # all three layers (in-process PHP + script contract + HTTP acceptance)
./scripts/run-tests.sh --scripts          # script-contract gate only (static, no WordPress, no network)
./scripts/run-tests.sh --acceptance       # HTTP acceptance layer only
./scripts/run-tests.sh --php              # in-process PHP layer only
./scripts/run-tests.sh --only theme       # only the theme's maintained suites
./scripts/run-tests.sh --only conexao-event-runtime   # only that plugin's suites
./scripts/run-tests.sh --only scripts     # the Stage I script gate
./scripts/run-tests.sh --list             # list discovered suites and components
./scripts/run-tests.sh --help             # usage
```

To run a single suite, use `--only <component>` where `<component>` is a theme/plugin slug (e.g. `conexao-event-runtime`) or a layer name (`theme`, `plugin`, `scripts`, `acceptance`). Suites are discovered by convention — `wp-content/themes/<theme>/tests/test-*.php` and `wp-content/plugins/<plugin>/tests/test-*.php` — there is no hardcoded list. An unknown `--only` value exits with code 2.

### Quality tooling (Stage C)
```bash
./scripts/lint.sh                         # PHP syntax sweep + PHPCS baseline check + PHPStan level 5 (the Stage C gate)
composer lint                             # PHPCS (raw debt view)
composer lint:fix                         # PHPCBF auto-fix
composer analyse                          # PHPStan level 5
composer check                            # lint + analyse
shellcheck scripts/*.sh                   # shell lint
```

### Registry and release
```bash
php scripts/generate-registry-docs.php --check   # registry drift gate (zero writes; CI blocks on stale output)
php scripts/generate-registry-docs.php --write   # regenerate generated regions from plugins.json
python3 scripts/verify-permanent-gates.py        # Stage L permanent invariant gates -> gate.json
./scripts/verify-release.sh                      # whole release workflow locally (never touches production)
```

### Environment variables
- `CONEXAO_TEST_BASE_URL` — acceptance base URL, defaults to `http://localhost:8080`. Production hosts (`conexaobr.ie`) are refused.
- `CONEXAO_TEST_WP_ROOT` — directory containing `wp-load.php` for the in-process layer.
- `WP_USERNAME` / `WP_APPLICATION_PASSWORD` — credentials for Python REST/HTTP scripts (create an Application Password in wp-admin).

## High-level architecture

### What this is
Conexão BR Irlanda: a WordPress community portal (guides, events, courses, jobs, sponsors, leisure/tourism directory, blog). Bilingual PT/EN served by Polylang. PT is canonical; English is an additional `/en/` layer, never a fork.

### Tech stack
- WordPress, PHP 8.5, Apache, MySQL (local Docker); WordPress.com in production
- Vanilla JavaScript (no framework, no bundler), custom CSS design system
- Composer is dev tooling only — never deployed to production

### Plugin architecture (load order matters)
All custom plugins live in `wp-content/plugins/`. The single authoritative registry is [`plugins.json`](plugins.json), which owns load order, class, status, production flag, build flag, Compose mount, dependencies, version source, and docs path. `scripts/generate-registry-docs.php` derives the AGENTS.md table, README table, `docs/plugins/README.md`, `build-plugins-zip.sh`'s slug list, and `compose.yaml` mounts from it. Never hand-edit a generated block — edit `plugins.json` and re-run `--write`.

Plugin classes:
- **platform** — production runtime (data-model, content, admin-ux, event-runtime, commemorative, translation-rollout, translation-automation)
- **tooling** — local-only operational tooling (event-importer, leisure-migration, sponsor-migration, en-translation)
- **rollout** — one-shot translation rollout (none active today; retired plugins were removed in Stage 19)

Production steady-state activation order: `conexao-data-model` → `conexao-content` → `conexao-admin-ux` → `conexao-event-runtime` → `conexao-commemorative` → `conexao-translation-rollout` → `conexao-translation-automation`.

### Content model
Custom post types are registered only in `conexao-data-model`: `guide` (`/guias/`), `event` (`/eventos/`), `job` (`/empregos/` — archive disabled, landing page), `sponsor` (`/apoiadores/`), `course_provider` (`/cursos/`), `leisure` (`/lazer/`), `recruitment_agency` (admin-only, no URL), `permit_employer` (admin-only, no URL), `commemorative_event` (private, no front-end URL, no sitemap).

### Theme
`wp-content/themes/conexao-br-irlanda/`. `functions.php` is a loader only (constants + `require_once` of `inc/*.php` in documented order). `inc/i18n/` owns all language/URL decisions (`conexao_current_locale()`, `conexao_lang_url()`, etc.). `inc/seo/` owns title/meta/canonical/OG/schema/sitemap/robots/redirects. Reusable markup lives in `template-parts/`.

### Testing model (three layers)
1. **In-process PHP** — real WordPress bootstrap, one OS process per suite. `tests/bootstrap.php` is the only place allowed to locate `wp-load.php`.
2. **Script contract** — static checks over `scripts/` (Stage I). No WordPress, no network, no production.
3. **HTTP acceptance** — real requests against the local WordPress. Matrices live in `tests/acceptance/matrices/*.json`.

Permanent invariant gates (Stage L) run inside `./scripts/run-tests.sh` and fail closed: taxonomy policy, translation completeness, language-scoped caching, legacy redirect precedence, documentation drift, i18n freshness, plus eight commemorative gates.

### CI
`.github/workflows/ci.yml` runs three blocking jobs on every push/PR: static quality, release integrity, and integration (Docker WordPress + `run-tests.sh`). No repository secrets, no production contact.

## Key conventions

### Language / Polylang (non-negotiable)
- PT is canonical: PT URLs, slugs, and record identity never change as a side effect of an EN change.
- An EN record is a linked translation of the same identity — never a second identity record.
- `conexao_category` and `conexao_tag` are translated; `conexao_county` and `conexao_town` are shared (one term, both languages).
- Locale is read only through `conexao_current_locale()`.
- Cache keys derived from content are language-scoped (`conexao_lang_cache_key()`); invalidation clears every language.
- B2 fallback (PT body under EN URL + notice) is canonical → PT, noticed, and out of the sitemap.

### PHP coding standard
- WordPress Coding Standards (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP) via `phpcs.xml.dist`.
- Hard tabs for indentation; no trailing whitespace; LF endings; final newline.
- Every loaded PHP file begins with the `ABSPATH` guard (`defined( 'ABSPATH' )` then `exit;`).
- Prefixes: `conexao_` (functions), `Conexao_` (classes), `CONEXAO_` (constants).
- New public functions carry a WordPress-style docblock (`@param`, `@return`).
- No `var_dump`/`print_r`/`error_log` in shipped code (allowed in `tests/`).
- Direct `$wpdb` only with `$wpdb->prepare()`; prefer WP APIs.
- Sanitize on input, escape on output (`sanitize_*`, `esc_html*`, `esc_url`, `esc_attr`, `wp_kses_post`).
- No `@` error suppression, no `extract()`, no dynamic includes from user input, no `eval`.
- Use `wp_remote_*` for HTTP (never raw `curl`/`file_get_contents` on URLs).
- PHPStan level 5; no new baseline entries.

### Shell / Python tooling
- Bash: `#!/usr/bin/env bash` + `set -euo pipefail`; all expansions quoted; shellcheck-clean.
- Python: standard library only (no pip dependencies, no `requests`).
- Python credentials strictly from environment (`WP_USERNAME`, `WP_APPLICATION_PASSWORD`).
- Python target URL from `CONEXAO_SITE_URL` and printed in the run header.
- Write-capable scripts default to plan-only (`--dry-run`) and require `--apply` to write.

### Content-change contract (six steps, mandatory for anything that writes content)
1. Inventory — read-only, machine-readable list of target records with stable identifiers.
2. Manifest / data file — authored data in a versioned file, never inline in the engine.
3. Dry-run plan — prints JSON (`create[]`, `update[]`, `skip[]` with reason, `conflicts[]`). No writes.
4. Snapshot — capture affected records before applying.
5. Apply — idempotent; re-running produces zero changes. Report match strategy (stable ID → slug → title) and counts per strategy.
6. Verify + gate — numeric gate; PT-unchanged assertion for EN work; result in `docs/evidence/<date>-<stage>/gate.json`.

- Slug/title matches are printed with counts; matching by local post ID is forbidden.
- Every rollout is reversible (`--remove` or documented rollback).
- Production-capable rollouts provide an admin screen with Preview → Apply (+ Remove when reversible).

### Safety rules
- No production writes unless the task explicitly authorises them.
- Production has no CLI (WordPress.com: no SSH, no WP-CLI, no filesystem, no database). Anything operated in production must have an admin screen.
- No secrets, no localhost URLs in production data, no hotlinked media. Credentials come from environment variables.
- Behaviour-preserving refactors carry no feature change and are never mixed with a behaviour change in one commit.
- Never touch the Flutter/mobile repository.
- No second source of truth: any list that must stay in sync is generated from or checked against one authoritative file (`plugins.json`, `scripts/README.md`, `docs/README.md`, etc.).

### Workflow for changes
1. Plan: for multi-file work or anything touching content, routes, or English/Polylang, fill in `docs/templates/plan.md`.
2. Reuse: prefer shared engines/helpers (`scripts/lib/bootstrap.php`, `scripts/lib/rest.py`, `scripts/lib/zip-build.sh`, `scripts/lib/release.py`, `scripts/lib/plan.py`, `conexao-translation-rollout` engine, `tests/bootstrap.php`, `tests/lib/assertions.php`) over new copies.
3. Verify: run the checks and paste real numbers. `./scripts/run-tests.sh` is the single test command.
4. Report: fill in `docs/templates/report.md` and put machine evidence in `docs/evidence/<date>-<stage>/`. Never add a report or evidence file at the repository root.

### MCP servers

This project uses Playwright for browser-based regression tests in `tests/browser/`. Configure the Playwright MCP server so Copilot can drive real Chromium for those suites:

```json
{
  "servers": {
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest"]
    }
  }
}
```

Browser tests are opt-in layers (not run by `./scripts/run-tests.sh` by default). Run them directly:

```bash
python3 tests/browser/verify-multi-file-import-browser.py
python3 tests/browser/verify-multi-file-import-browser.py --negative-control
```

They require `pip install playwright && playwright install chromium` and WordPress running locally at `http://localhost:8080`.

### Agent skills
Agent skills live in `.agents/skills/`. Match the task to the skill: `wp-add-content-type`, `wp-add-admin-screen`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-release-deploy`, `wp-update-docs`, `wp-security-review`, `wp-frontend-perf`, `wp-translation-rollout`, `wp-plugin-registry`, `wp-production-operations`, `wp-testing`.