# Production operations

## Purpose

Operate against production **WordPress.com** safely: read-only audits and
verification of the live site, and explicitly authorised production changes
executed by a maintainer through wp-admin. Building the artifacts that get
deployed is `wp-release-deploy`; this skill is about the production state
itself.

## When to use

- Verifying the live site after a deployment, an admin action, or an incident
  (read-only).
- Auditing what is installed and active on production against the registry's
  production steady state.
- Executing a production change that the task **explicitly authorises**
  (plugin update, theme update, admin-screen rollout, content correction).
- Planning the safest response to a production fault (including rollback).

## When not to use

- Building, recording or verifying a *release* end-to-end — that is
  `wp-release-deploy` (the two skills pair: it ships, this one verifies and
  operates).
- Local development, local Docker work, or content authoring — no production
  involvement.
- Any production write the task did not explicitly authorise — there is no
  such thing as an implied production write.

## Required reading

- `AGENTS.md` — scope and the non-negotiable safety rules.
- `docs/releases.md` — production constraints, deployment verification and
  **rollback semantics** (what a rollback covers and does not).
- `docs/deployment.md` — the production environment facts and the generated
  production activation order.
- `docs/engineering-standard.md` §0.12 (production has no CLI) and §11
  (deployment and release standard).
- `plugins.json` — the authoritative registry; `production: true` in load
  order defines the steady state.

## Authoritative sources

- `plugins.json` owns the plugin set and the production steady state; the
  activation order in `docs/deployment.md` is generated from it.
- `docs/releases.md` owns the deployment-verification contract and rollback
  semantics; `scripts/verify-deploy.py` owns the fixed smoke matrix.
- A production fact is read from the live site only through read-only tooling;
  never from memory or from a historical report.

## Preconditions

- For read-only verification: the target URL, and `python3` — nothing else.
  No credentials are used or needed.
- For any authorised production write: the task's explicit authorisation, a
  maintainer with wp-admin access, and the artifact/plan the repository
  shipped (built and verified through `wp-release-deploy`).
- A rollback plan recorded **before** the change (see `docs/releases.md`
  §Rollback) and the previous artifacts kept.

## Steps

1. **Establish what you may do.** Read-only observation
   (`scripts/verify-deploy.py`) needs no authorisation and mutates nothing.
   Everything else — upload, activation, deactivation, admin-screen apply,
   REST content write — requires the task to authorise it **explicitly**, by
   name. When in doubt: read-only, and ask.
2. **Verify the live site read-only.**
   ```bash
   python3 scripts/verify-deploy.py --site https://<host>
   ```
   `--site` is required with no default; the run is GET-only, no credentials.
   It runs the fixed release smoke matrix (homepage, every archive, one
   single per post type, the `/en/` pairs, canonical, hreflang, sitemap,
   404). Save the JSON result as evidence with `--out`.
3. **Audit the installed plugin state.** In wp-admin → Plugins, compare the
   active set with the generated production activation order in
   `docs/deployment.md` (the `production: true` subset of `plugins.json`, in
   load order). Deviations are either an incident or a recorded temporary
   decision — either way they are written down, never left silent.
4. **Update an already-active plugin correctly.** Upload the new ZIP over it
   (wp-admin → Plugins → Add New → Upload Plugin) so WordPress **replaces it
   in place** and it stays active. **Updating is never deactivate +
   reactivate:** activation hooks are not re-runnable as a refresh.
   `conexao-content` is the standing example — its activation re-creates the
   static pages and rebuilds the `Menu Principal` / `Menu Rodapé`
   navigation menus, which would overwrite production customisations.
5. **Execute an authorised change as the maintainer's admin operation.**
   The repository ships the plan, artifact or admin screen; the maintainer
   performs it in wp-admin (upload, activate, admin-screen Preview → Apply).
   A rollout stage follows its own screen contract — see
   `wp-translation-rollout` and `docs/plugins/conexao-translation-rollout.md`.
6. **Verify after every production action.** Re-run
   `python3 scripts/verify-deploy.py --site https://<host>`; an unverified
   production state is an unfinished operation. Record the result.
7. **Record what happened.** Production actions go into the report's
   production-actions table (performed / not performed), the release log in
   `docs/releases.md` when a release was involved, and incident reports under
   `docs/reports/` for anything that went wrong.

## Guardrails

- **Production is WordPress.com: no SSH, no WP-CLI, no filesystem, no
  database.** If a plan needs a production shell, the plan is wrong — expose
  an admin screen instead.
- No production write without explicit task authorisation; no scripted
  upload, activation or content mutation from this repository's release
  tooling.
- Never deactivate `conexao-event-runtime` to "fix" event rendering — public
  event behaviour (status gate, meta) lives there; verify first, change
  deliberately.
- `update in place ≠ deactivate/reactivate` (step 4). Deactivation is the
  documented fastest *partial rollback* for a faulty plugin — never an
  update method.
- Local-only tooling plugins are never left active on production; any
  temporary deviation from the steady state is a recorded decision.
- Read-only verification uses no credentials; REST content scripts read
  credentials only from `WP_USERNAME` / `WP_APPLICATION_PASSWORD` and need an
  explicit target plus `--confirm-production` for any write.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
python3 scripts/verify-deploy.py --site https://<host>   # read-only smoke matrix
```

- The command exits 0 and every matrix row passes; the output JSON is saved
  under `docs/evidence/<date>-<stage>/` and cited in the report.
- Without `--site` the command must exit non-zero (the no-default guard) —
  worth re-proving after any change to it.
- The active plugin set matches the generated production activation order,
  or every deviation is recorded with its reason.

## Failure handling

- **Verification fails after an authorised change:** roll back — see
  `wp-release-deploy` and `docs/releases.md` §Rollback. Decide code fault vs
  content fault first; they roll back differently.
- **A single plugin is the fault:** deactivate it in wp-admin (fastest partial
  rollback), re-verify, and decide whether the site is acceptable in that
  state; the steady state (all platform plugins active in order) is the
  known-good fallback.
- **A content fault:** restore from that change's own snapshot or documented
  recovery — never improvise SQL; a code rollback does not undo content.
- Record every incident and its resolution in `docs/reports/`; the next
  operation must know what happened.

## Evidence and reporting

- `--out` JSON from each verification run, the before/after active-plugin
  lists for any change, and the rollback note — all under
  `docs/evidence/<date>-<stage>/`, cited by the report.
- The report's production-actions table states every action performed or
  **not** performed, and who performed it (a maintainer, through wp-admin).

## Definition of done

- [ ] Read-only verification ran and its exact result is recorded as evidence.
- [ ] Every production action was explicitly authorised by the task and
      performed by a maintainer through wp-admin.
- [ ] Plugin updates were applied in place — no deactivate/reactivate.
- [ ] The active plugin set matches `plugins.json`'s production steady state,
      or every deviation is recorded.
- [ ] Every authorised action was verified after the fact over HTTP.
- [ ] A rollback plan existed before the change; incidents are recorded.
- [ ] No credential, no production URL default and no scripted production
      write was introduced.
