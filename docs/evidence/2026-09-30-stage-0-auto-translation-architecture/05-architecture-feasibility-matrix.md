# Stage 0 — automatic PT→EN translation architecture feasibility matrix

Machine-readable form of the decision table in
`docs/reports/2026-09-30-stage-0-auto-translation-architecture.md`.
Read-only investigation; `production_writes: 0`, HTTP verbs used: `GET` only.

## Verified facts

| Fact | Value | Source |
|---|---|---|
| Engine entry path | `Conexao_Translation_Rollout_Engine::run(array $config, array $adapter, array $args)` | `class-conexao-translation-rollout-engine.php:819` |
| Engine SHA-256 | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` (identical to the Phase 2 baseline) | `04-engine-lifecycle-surface.txt` |
| Engine is static + injection-based | yes | no `$_POST`, no `is_admin()`, no `current_user_can()`, no nonce call in the engine file |
| Registered EN stages | 7 | `conexao-en-translation.php:58-82` |
| REST routes in the rollout plugins (source) | **0** | `02-entrypoint-reachability.txt` §A |
| Cron scheduling calls in all plugins (source) | **0** | `02-entrypoint-reachability.txt` §A |
| WP-CLI commands in the rollout plugins (source) | **0** | `02-entrypoint-reachability.txt` §A |
| Live production REST routes | 665 total, **0** matching rollout/translation | `02-entrypoint-reachability.txt` §C |
| Production plugin inventory | 18 plugins; **both rollout plugins ABSENT**; 4 platform plugins active | `01-production-plugin-state-readonly.json` |
| `GET /wp-admin/tools.php` with the application password | **302** → `wp-login.php?...&reauth=1` | `02-entrypoint-reachability.txt` §D |
| `GET /wp-admin/admin-post.php` | 200, 0-byte body | `02-entrypoint-reachability.txt` §D |
| B1 EN rollout state in production | **applied** — guide 25116 is the EN `driving-licence-in-ireland-how-to-exchange-your-cnh`, served 200 at `/en/guias/…` | read-only REST + GET |

## Option matrix

| Dimension | A — permanent production plugin / controlled endpoint | B — external GitHub Actions / REST |
|---|---|---|
| Technically feasible | **yes** | **no** |
| Reuses the existing engine | yes, unchanged | no |
| Creates a second engine | no | **yes** |
| Preserves the 8-step lifecycle | yes | **no** |
| Production compatibility | plugin ZIP upload via wp-admin; REST + cron permitted | core REST available, but the engine is unreachable |
### Recommended shape

- **Mutation authority:** `Conexao_Translation_Rollout_Engine::run()` — unchanged, sole writer.
- **Automation entry point:** a *thin* orchestrator that authenticates, takes a
  lock, resolves an **allowlisted** stage, calls that stage's **own**
  `run_callback`, records the report, releases the lock. It performs no
  planning, counting, matching or writing.
- **Forbidden:** a second planner/apply/verify implementation; a public
  unauthenticated translation endpoint; any stage not in the allowlist; PT
  writes other than the documented B2 EN-prefixed meta fields.

## Open blockers carried into Stage 1

| ID | Blocker |
|---|---|
| B1 | The production activation path for a **new permanent** plugin is unproven in this repository — no `production: true` tooling plugin exists today (all four are platform plugins). |
| B2 | WordPress.com cron behaviour under pv (wp-cron) is **not** verified from this repository; a traffic-dependent schedule is not acceptable for a translation pipeline. |
| B3 | No lock primitive exists in the repository; a concurrency control must be designed and proven. |
| B4 | No translation-provider interface exists anywhere in the repository. |
| B5 | No change-detection contract exists; the only related signal today is the manual B2 `pt_source` drift guard in `leisure-description-stage.php:215-221`. |

## Verification actually run

| Command | Result |
|---|---|
| `./scripts/run-tests.sh` | 63 in-process suites, 63 passed, 0 failed; **4216 assertions passed, 0 failed**; script-contract 6 total, 5 passed, 1 failed; HTTP acceptance 3 total, 3 passed, 0 failed |
| `python3 scripts/verify-permanent-gates.py` | 7 gates, 6 passed, 1 failed — `i18n_freshness` (pre-existing POT staleness, recorded in `tests/baseline/permanent-gates.json`); 89 assertions passed, 3 failed |
| `php scripts/generate-registry-docs.php --check` | registry OK: 14 plugins validated, 23 generated regions current (zero writes) |
| `git status --porcelain` | **0 entries** (clean) |

_Last verified: 2026-09-30 by Stage 0 — Automatic PT→EN translation architecture_

| Security model | in-WordPress: `manage_options` + nonce, or a secret + capability check | GitHub secret + Application Password (a full admin REST credential) |
| Authorization | WordPress capability model, per-user, revocable | one shared credential, no per-user attribution |
| Failure recovery | in-process `WP_Error` before the apply path ⇒ zero writes; re-run resumes | external retry logic; engine guarantee unavailable |
| Idempotence | the engine's own (proven by `test-future-stage-smoke.php`) | must be reimplemented |
| Auditability | in-WordPress append-only run log | CI logs + a commit; record split across two systems |
| Concurrency | needs a **new** lock (none exists today) | GitHub Actions does not serialise two applies |
| Rollback / snapshot | `apply_removals()` + the pre-apply PT snapshot, both implemented and tested | must be reimplemented |
| WP.com constraints | no SSH/WP-CLI/DB ⇒ the plugin must be `production: true` and ship in a release ZIP | core REST only |
| Maintenance complexity | medium — one small, testable surface | high — two drifting implementations |
| Risk of PT data loss | **low** — PT is read-only; `assert_no_pt_drift()` re-compares every snapshot | **high** — generic core REST writes have no engine-side PT-drift guard |
| Verdict | **RECOMMENDED** | **REJECTED** |

### Why B is rejected on evidence, not convenience

1. It cannot call the engine. There is no REST route in either rollout plugin
   (0 in source, 0 of 665 live routes) and the one real entry point needs the
   wp-admin **cookie** session, which an Application Password does not provide
   (302 to `wp-login.php?reauth=1`).
2. So B could only reimplement `create_en` / `link_pair` / the B2 field write /
   the taxonomy step over core REST. That is a **second translation engine**,
   which `AGENTS.md` ("Reuse, do not reinvent") and the `wp-translation-rollout`
   skill both forbid.
3. Even setting duplication aside, the lifecycle would be lost: the PT snapshot,
   `diff_snapshots()`, `assert_no_pt_drift()` and the optional `taxonomy_callback`
   are in-process engine behaviour with no external equivalent.
