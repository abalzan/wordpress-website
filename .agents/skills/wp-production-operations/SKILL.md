# Operate the production site

## Purpose

How to audit and safely change the **live** WordPress.com site. Production has no
CLI, so every capability operated there must have an admin screen; this skill
covers the audit, the safe plugin/theme update, the verification, and the
roll-back-forward when the live site is not healthy.

This is the operational companion to `docs/releases.md`, which remains
authoritative for the release/deploy/rollback **contract**. Building and
releasing an artifact is `wp-release-deploy`; this skill is about the deployed
system.

## When to use

- Auditing what is actually installed, active or enabled on production.
- Updating a plugin or the theme on the live site, or activating/deactivating to
  reach the steady state.
- Operating a production capability that only exists as an admin screen (a
  rollout preview/apply/remove, a bulk edit, a repair).
- Verifying the live site behaves after a change.
- Deciding between rollback and roll-forward when production is unhealthy.

## When not to use

- Building artifacts, writing the release manifest, or recording a release —
  `wp-release-deploy`.
- Changing the registry facts themselves (build, mount, production, class) —
  `wp-plugin-registry`.
- Any content or English change — `wp-content-change` / `wp-translation-rollout`.
  Those define the six-step contract; this skill says the same rules apply when
  the target happens to be production, and adds nothing to them.

## Required reading

- `AGENTS.md`.
- `docs/releases.md` §Production constraints, §Rollback, §Deployment
  verification — the authoritative contract.
- `docs/deployment.md` — the generated production activation order.
- `plugins.json` — what *should* be active in production (`production: true`).
- `docs/plugins/README.md` and the individual `docs/plugins/<slug>.md` — each
  plugin's lifecycle hazard.
- `scripts/README.md` — the read-only verification scripts and their arguments.

## Authoritative sources

| Fact | Read it from |
|---|---|
| What ships / what is production / load order | `plugins.json` (never a list in this skill) |
| Production activation order | `docs/deployment.md` (generated) |
| Production constraints, rollback semantics | `docs/releases.md` |
| A plugin's lifecycle hazard | that plugin's `docs/plugins/<slug>.md` |
| Verification script flags and safety level | `scripts/README.md` |
| Live behaviour of a route | `docs/routing.md` |

## Preconditions

- **The task explicitly authorises a production action.** Without that

## Steps

1. **Establish the production model, do not rediscover it.** Production is
   **WordPress.com**: no SSH/SFTP, no WP-CLI, no filesystem, no database. If a
   plan needs a shell on production, the plan is wrong — expose an admin screen
   instead. Deployment is a manual ZIP upload through wp-admin; activation is an
   admin action.
2. **Audit before you change.** Capture, read-only: the installed and active
   plugin set with versions, the active theme version, and the live behaviour of
   the key routes. `scripts/verify-deploy.py --site <url>` is GET-only, needs no
   credentials, and refuses to run without an explicit `--site`.
3. **Know the target state.** The steady state is exactly the `production: true`
   subset of `plugins.json`, in registry load order, plus the current theme. Read
   the generated order in `docs/deployment.md`; do not work from memory or from a
   list in a report.
4. **Check the plugin's lifecycle hazard** before touching it. Two examples that
   are specifications, not advice: `conexao-content` activation is destructive to
   menus, and `conexao-event-runtime` must remain active because its public
   behaviour depends on it. Read the plugin's own doc.
5. **Update in place; do not deactivate to update.** A new ZIP replaces the
   existing plugin and WordPress reactivates it. *Update in place* is **not** the
   same operation as *deactivate then reactivate*, and a needless deactivate
   destroys the activation order and any transient state. Only deactivate when
   the registry says the plugin is not part of the steady state, or when it is the
   single suspected cause and you are choosing the fastest partial rollback.
6. **Upload and activate in order.** Theme first, then plugins in
   `plugins.json` order (platform first), reaching the steady state. Verify
   activation did not silently drop a plugin.
7. **Verify the live result over HTTP.**
   ```bash
   python3 scripts/verify-deploy.py --site https://<host> \
       --out docs/evidence/<date>-<stage>/deploy-<tag>.json
   ```
   It runs the fixed smoke matrix (homepage, every archive, one single per post
   type, `/en/` pairs, canonical, hreflang, sitemap, 404) plus the PT/EN
   language-layer checks. An unverified change is an unverified state.
8. **Operate admin-only capabilities through the admin screen.** A rollout runs
   Preview → Apply → Remove, with the dry run performing zero writes, a numeric
   gate, and the PT-drift check. Do not look for a CLI substitute; there is none.
9. **If production is unhealthy, choose deliberately.** A code fault rolls back to
   the previous artifacts (see `docs/releases.md` §Rollback). Deactivating a single
   suspect plugin is the fastest *partial* rollback, and "all platform plugins
   active in order" is always a known-good fallback state. Content is never
   rolled back with code and is never improvised in SQL.
10. **Record the outcome.** Add the release/log row with versions, SHA, the
    verification result and the rollback note. State plainly what was and was not
    verified.

## Guardrails

- **No production write without explicit authorisation** for that action. No
  production content mutation, Polylang change, DB migration, upload or activation
  is performed by this repository on its own initiative.
- **No SSH, no WP-CLI, no filesystem, no database access to production.** A plan
  that needs any of them is the wrong plan.
- **Update in place, not deactivate/reactivate.** Preserve the activation order.
- **Never roll back content with code**, and never improvise SQL against
  production. Content and code are independent.
- **`.htaccess` and Polylang configuration are never modified by a release** and
  never "rolled back" as part of one.
- **Verification is read-only and explicit.** `scripts/verify-deploy.py` needs an
  explicit `--site` with no default; a production URL is never a hard-coded
  default in any script.

## Verification

```bash
python3 scripts/verify-deploy.py --site https://<host>   # GET-only, explicit target
php scripts/generate-registry-docs.php --check           # no registry drift
./scripts/verify-release.sh                             # the workflow, proven locally
python3 scripts/release-manifest.py --verify            # artifacts match the record
python3 tests/scripts/verify-release-integrity.py       # 0 writes, no network
```

- The live smoke matrix ran and its result is stored as evidence.
- The active plugin set equals the `production: true` subset, in load order.
- No plugin was deactivated in order to update it.
- `verify-deploy.py` without `--site` exits non-zero (the negative proof).
- The release log carries versions, SHA, verification result and rollback note.
- `git status --short` shows no `dist/` and no unintended `wp-content` change.

## Failure handling

- *A plan requires a shell on production.* Stop and redesign: the capability needs
  an admin screen. Say so in the report rather than proposing an unsupported path.
- *Activation order is broken* (a plugin is active but out of order, or one is
  missing). Re-activate in `plugins.json` order to reach the steady state; do not
  re-derive the order from a report or from memory.
- *`verify-deploy.py` reports a route failing.* Decide whether the fault is code or
  content. Code → roll back or roll forward deliberately; content → its own
  snapshot/recovery. Never mix the two reversals.
- *A plugin deactivation was needed.* Record it explicitly as a deviation from the
  steady state, with the reason and the verification performed in that state.
- *The deployment verification could not run* (no network, no site). Record it as
  **not tested** and use `PASS WITH LIMITATION` in the report. Never describe an
  unverified deployment as verified.

## Evidence and reporting

Store the pre-change audit, the post-change `deploy-<tag>.json`, the verified
active-plugin set and the rollback note under `docs/evidence/<date>-<stage>/`.
`docs/templates/report.md` must state the production actions taken (or explicitly
none), the verification result, what rollback covers and what it does not, and
any deviation from the steady state.

## Definition of done

- [ ] The production action was explicitly authorised before it was taken.
- [ ] A read-only pre-change audit was captured.
- [ ] The target state was read from `plugins.json` / the generated activation
      order, not from a report or from memory.
- [ ] Each touched plugin's lifecycle hazard was checked.
- [ ] Plugins were updated in place; none was deactivated merely to be updated.
- [ ] The steady state was reached and the active set recorded.
- [ ] `verify-deploy.py --site <url>` ran and its result is stored as evidence.
- [ ] The rollback target was known *before* the change.
- [ ] Content was not rolled back with code; no SQL was improvised.
- [ ] The release log row and the rollback note are recorded.
- [ ] Unverifiable steps are reported as blocked/not-tested, never as verified.

- **No secrets, no localhost URLs, no hotlinked media** in production data.
- **Local restore** is only ever `scripts/restore-updraft-db.sh`, and only ever
  local and destructive.
- Never touch the Flutter/mobile repository from this task.

  authorisation, stop: the correct outcome is a plan and an artifact, not a
  change.
- The previous release record is known, so a rollback target exists *before* the
  change (see `docs/releases.md` §Before deploying, record this).
- `WP_USERNAME` / `WP_APPLICATION_PASSWORD` are available **in the environment
  only** if a REST-based read is required — never in code, docs or evidence.
- A read-only verification of current state has been captured first.
