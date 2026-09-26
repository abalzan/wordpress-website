# Release and deploy

## When to use

Building release artifacts, recording a release, verifying a deployment, or
rolling one back. Two invariants define this repository's release contract:

1. **`plugins.json` determines what may ship** — the artifact allowlist is
   *derived*, never hand-maintained.
2. **the release manifest `dist/release.json` records exactly what was actually built** — version,
   git SHA, file count, byte size and SHA-256 per artifact.

The third question — *does the deployed site behave?* — is answered separately
by `scripts/verify-deploy.py`, which observes without mutating.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §11 (deployment and release standard) and §4.3
  (steady-state activation).
- `docs/releases.md` — **the** release contract: sequence, manifest, tag
  convention, deployment verification, rollback, production constraints.
- `docs/deployment.md` — the generated production activation order.
- `plugins.json` — the authoritative registry (see `wp-plugin-registry`).
- `scripts/README.md` — the build and verification script entries.

## Steps

1. **Pass the gates first.** `./scripts/lint.sh` and `./scripts/run-tests.sh`
   must be green before an artifact is built. A release does not fix tests.
2. **Build deterministically.**
   ```bash
   ./scripts/build-plugins-zip.sh     # ZIPs + the release manifest
   ./scripts/build-theme-zip.sh
   ```
   Both source the single packager `scripts/lib/zip-build.sh`, which owns the
   exclusion rules (no test directories, fixture directories, `*.json` reports, OS junk) and the
   determinism guarantee. Never add a second packaging implementation.
3. **Record the release.** `scripts/release-manifest.py --write` emits
   the manifest under `dist/`; `scripts/lib/release.py` is the one record
   implementation. It derives the allowlist from `plugins.json` (`build: true`,
   in load order, plus the theme), reads each version from its component header
   (versions are never duplicated into the registry), and refuses to package a
   `retired` rollout. `dist/` is git-ignored: **never commit a build artifact**.
4. **Verify the artifacts.** `scripts/release-manifest.py --verify` re-hashes
   every artifact against the record; `--check` validates without writing. Prove
   reproducibility: two builds of the same tree are byte-identical.
5. **Run the whole workflow locally.** `./scripts/verify-release.sh` executes
   the seven-step end-to-end proof (registry gate → build → manifest →
   allowlist/hash → determinism → exclusion → HTTP verification) against the
   **local** site. This is the single command to run before relying on the
   machinery. It never uploads anything.

6. **Deploy manually, in the documented order.** Production is WordPress.com:
   there is **no SSH, no WP-CLI, no filesystem, no database access**. A human
   uploads the theme ZIP, then the plugin ZIPs in `plugins.json` order
   (platform plugins first), through wp-admin, and activates/deactivates to
   reach the steady state in `docs/deployment.md`. The tooling is
   build-and-verify only: it contains no upload, activation or mutating path.
7. **Verify the deployment over HTTP.**
   ```bash
   python3 scripts/verify-deploy.py --site https://conexaobr.ie
   ```
   GET-only, no credentials, and `--site` is **required with no default**. It
   runs the fixed smoke matrix: homepage, every archive, one single per CPT,
   `/en/` pairs, canonical, hreflang, sitemap and 404.
8. **Record and tag.** Add a row to the release log in `docs/releases.md` with
   versions, git SHA, verification result and the rollback note, using the tag
   convention there.
9. **Rollback.** Follow `docs/releases.md` §Rollback: previous ZIPs, previous
   plugin/theme state, re-verify over HTTP. Record what rollback does **not**
   cover (content changes need their own reversal).

## Guardrails

- **Never assume SSH or WP-CLI access.** If a plan needs a shell on production,
  the plan is wrong: expose an admin screen instead.
- `scripts/verify-deploy.py` is read-only and must stay so; `--site` is
  mandatory and a production URL is never a default.
- No production activation, upload or content write happens from a script in
  this repository.
- The allowlist is derived from `plugins.json`; a hand-maintained artifact list
  is a defect. A `retired` rollout must never be build-enabled.
- `dist/` stays git-ignored; never commit a ZIP, a manifest or build output.
- Bump the version **in the component header**, never in a side table.
- No hard-coded production URL in a script; the target comes from
  `CONEXAO_SITE_URL` / `--site` / `--base-url`.
- Never force-push a shared branch; a history rewrite needs a recorded decision.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
php scripts/generate-registry-docs.php --check    # registry drift: 0 writes
./scripts/verify-release.sh                       # the 7-step local workflow
python3 scripts/release-manifest.py --verify      # hashes match the record
python3 tests/scripts/verify-release-integrity.py # 0 writes, no network
python3 tests/acceptance/verify-release-http.py   # against the local site
python3 scripts/verify-deploy.py --site <url>     # read-only, explicit target
```

- `git status --short` shows no `dist/` and no unexpected `wp-content` change.
- Paste the real per-step output. If a step could not run (no Docker, no
  network, no deployed site), record it as **blocked** or **not tested** — never
  as verified.
- `scripts/verify-deploy.py` without `--site` must exit non-zero (a negative
  proof worth repeating).

## Definition of done

- [ ] `./scripts/lint.sh` and `./scripts/run-tests.sh` were green before building.
- [ ] Artifacts were built by `scripts/build-plugins-zip.sh` /
      `scripts/build-theme-zip.sh` through the shared `scripts/lib/zip-build.sh`.
- [ ] The allowlist came from `plugins.json`; no retired rollout shipped.
- [ ] the release manifest records version, SHA, file count, size and SHA-256.
- [ ] A rebuild reproduced identical hashes; `--verify` re-hashed every artifact.
- [ ] `./scripts/verify-release.sh` completed locally; step results are recorded.
- [ ] Deployment followed the documented manual order; activation reached the
      steady state in `docs/deployment.md`.
- [ ] `scripts/verify-deploy.py --site <url>` was run and its result recorded.
- [ ] Versions were bumped in component headers and logged in `docs/releases.md`.
- [ ] A rollback note exists and states what rollback does not cover.
- [ ] No build artifact, secret or localhost URL is committed.
