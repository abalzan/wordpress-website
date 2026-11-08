# Copilot Instructions — Conexão BR Irlanda (WordPress)

Audience-specific entry point for GitHub Copilot. **Start at
[`AGENTS.md`](../AGENTS.md)** — it holds the project orientation, scope, safety
rules, the authoritative-source map and skill selection. The authoritative
engineering standard is [`docs/engineering-standard.md`](../docs/engineering-standard.md);
where this file and the standard disagree, the standard wins.

This file does not restate safety policy, architecture or workflows: one owner
per fact, so nothing here can silently drift from `AGENTS.md`, the canonical
`docs/` references or the skills.

## Quick commands

### Local environment
```bash
docker compose up -d          # start the local WordPress + MySQL stack
```

### Test runner (single entrypoint, one aggregate exit code)
```bash
./scripts/run-tests.sh                    # all three layers (in-process PHP + script contract + HTTP acceptance)
./scripts/run-tests.sh --scripts          # script-contract gate only (static, no WordPress, no network)
./scripts/run-tests.sh --acceptance       # HTTP acceptance layer only
./scripts/run-tests.sh --php              # in-process PHP layer only
./scripts/run-tests.sh --only <component> # one theme/plugin slug (e.g. `conexao-event-runtime`), or a layer name (theme, plugin, scripts, acceptance)
./scripts/run-tests.sh --list             # list discovered suites and components
./scripts/run-tests.sh --help             # usage
```
Suites are discovered by convention (`wp-content/<themes|plugins>/<slug>/tests/test-*.php`,
`tests/scripts/verify-*.py`, `tests/acceptance/verify-*-http.py`) — there is no
hardcoded suite list, and an unknown `--only` value exits with code 2. Full
catalogue: `scripts/README.md`.

### Quality, registry and release gates
```bash
./scripts/lint.sh                               # PHP syntax + PHPCS baseline + PHPStan level 5
composer lint / lint:fix / analyse / check      # the individual Stage C tools
shellcheck scripts/*.sh                         # shell lint
php scripts/generate-registry-docs.php --check  # registry drift gate (zero writes; CI blocks on stale output)
php scripts/generate-registry-docs.php --write  # regenerate generated regions from plugins.json
python3 scripts/verify-permanent-gates.py       # Stage L permanent invariants -> gate.json
./scripts/verify-release.sh                     # whole release workflow, proven locally (never touches production)
```

### Environment variables
- `CONEXAO_TEST_BASE_URL` — acceptance base URL; defaults to the local stack and a **production host is refused** by the runner (see `docs/testing.md`).
- `CONEXAO_TEST_WP_ROOT` — directory containing `wp-load.php` for the in-process layer.
- `WP_USERNAME` / `WP_APPLICATION_PASSWORD` — credentials for Python REST/HTTP scripts (create an Application Password in wp-admin). Credentials come from the environment only.

## Where each fact lives

| Question | Read |
|---|---|
| Orientation, scope, safety rules, the plan/build/verify/report workflow | [`AGENTS.md`](../AGENTS.md) |
| Engineering rules (MUST/SHOULD) and the §0 change-safety principles | [`docs/engineering-standard.md`](../docs/engineering-standard.md) |
| Architecture, stack, plugin load order | [`docs/architecture.md`](../docs/architecture.md) + the generated plugin table in `AGENTS.md` |
| Content types, taxonomies, meta, language policy | [`docs/content-model.md`](../docs/content-model.md) |
| Routes, redirects, archives, the `/en/` layer | [`docs/routing.md`](../docs/routing.md) |
| Testing model, layers, permanent gates | [`docs/testing.md`](../docs/testing.md) |
| Release contract, manifest, deployment verification, rollback | [`docs/releases.md`](../docs/releases.md) |
| Script inventory (purpose, safety level, flags, default mode) | [`scripts/README.md`](../scripts/README.md) |
| Per-plugin purpose and verification | [`docs/plugins/`](../docs/plugins/) |
| Theme internals | [`docs/themes/conexao-br-irlanda.md`](../docs/themes/conexao-br-irlanda.md) |

## Rules that bind every change

Read `AGENTS.md` §"Safety rules that are not negotiable" and standard §0 before
editing. They cover, and this file never restates: PT immutability in EN work;
the Polylang taxonomy/cache rules; no production writes without explicit
authorisation; production has no CLI (admin screen for every production
capability); dry-run-first content writes; portable identifiers only; no secrets
or localhost URLs in production data; behaviour-preserving refactors carry no
feature change; one source of truth for every list that must stay in sync; and
the Flutter/mobile repository is permanently out of scope.

The coding conventions (PHP, JavaScript, CSS, shell/Python) are normative in
[`docs/engineering-standard.md`](../docs/engineering-standard.md) §2; the theme
conventions in §3; the plugin file contract in §4.

## Workflow in one paragraph

Follow the plan → build → verify → report loop owned by `wp-repository`
(`AGENTS.md` §"Workflow: plan, build, verify, report"): plan multi-file work —
and anything touching content, routes or English/Polylang — with
[`docs/templates/plan.md`](../docs/templates/plan.md); prefer the shared
engines and helpers over new copies (copying requires a written justification);
verify with `./scripts/run-tests.sh` and paste **real** numbers; then report
from [`docs/templates/report.md`](../docs/templates/report.md) with machine
evidence under `docs/evidence/<date>-<stage>/`. Never claim "fully verified"
when a verifier could not run — use `PASS WITH LIMITATION` and say why.

## Agent skills

Match the task to a skill through `AGENTS.md` or the index of record at
[`.agents/skills/README.md`](../.agents/skills/README.md) — it lists every
active skill with its use conditions, and `tests/scripts/verify-agent-governance.py`
fails if the index drifts from the directory. Read the skill's **Required
reading** and **Authoritative sources**, execute its **Steps**, then satisfy its
**Verification** and **Definition of done**.

## MCP servers

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

They require `pip install playwright && playwright install chromium` and the local WordPress stack running (`CONEXAO_TEST_BASE_URL`, default documented in `docs/testing.md`).