# Release and deploy

## Purpose

Build release artifacts deterministically, record exactly what was built,
verify the record, deploy in the documented order and verify the result — with
the allowlist derived from the registry and rollback always possible.

## When to use

Building release artifacts, recording a release, verifying a deployment, or
rolling one back. Two invariants define this repository's release contract:

1. **`plugins.json` determines what may ship** — the artifact allowlist is
   *derived*, never hand-maintained.
2. **the release manifest `dist/release.json` records exactly what was actually built** — version,
   git SHA, file count, byte size and SHA-256 per artifact.

The third question — *does the deployed site behave?* — is answered separately
by `scripts/verify-deploy.py`, which observes without mutating.

## When not to use

- Operating or auditing the live site (upload, activate, steady state, deciding
  between rollback and roll-forward) — `wp-production-operations`.
- Changing *what* may ship (adding, retiring, re-classifying a plugin) —
  `wp-plugin-registry`.
- Any content or English change — `wp-content-change` / `wp-translation-rollout`.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §11 (deployment and release standard) and §4.3
  (steady-state activation).
- `docs/releases.md` — **the** release contract: sequence, manifest, tag
  convention, deployment verification, rollback, production constraints.
- `docs/deployment.md` — the generated production activation order.
- `plugins.json` — the authoritative registry (see `wp-plugin-registry`).
- `scripts/README.md` — the build and verification script entries.

## Authoritative sources

| Fact | Read it from |
|---|---|
| What may ship (the derived allowlist) | `plugins.json` |
| The release contract, sequence, manifest, tag, rollback | `docs/releases.md` |
| The production activation order | `docs/deployment.md` (generated) |
| The single packager and its exclusion rules | `scripts/lib/zip-build.sh` |
| The single release-record implementation | `scripts/lib/release.py` |
| Build/verify script flags and safety levels | `scripts/README.md` |
| The release gate that must be green first | `docs/testing.md` |

**This skill declares no artifact list, version or activation order.** Those come
from `plugins.json`, the component headers and the generated regions.

## Preconditions

- `./scripts/lint.sh` and `./scripts/run-tests.sh` were green. A release does not
  fix tests.
- The working tree is in a known state; the git SHA recorded in the manifest is
  the one you intend to ship.
- The previous release record and artifacts are preserved, so a rollback target
  exists **before** deploying.
- Any content or translation change in the release has its own rollback.

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
- **No production activation, upload or content write happens from a script in
  this repository.** Preparing a release performs **no production write**; the
  upload and activation are a maintainer's admin actions, and the report must
  say so explicitly.
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

## Failure handling

- *A build is not reproducible (two hashes differ).* Something non-deterministic
  entered the archive: an mtime, a permission bit, a uid/gid, or a file that
  differs between builds. The recorded hash is then a false claim. Fix the
  packager input before releasing.
- *`release-manifest.py --verify` fails.* An artifact does not match the record —
  it was rebuilt, edited or replaced. Re-derive the record with `--write`, or
  discard the artifact; never edit the manifest to make it match.
- *The allowlist contains a retired rollout.* The registry and the release
  integration disagree. Fix `plugins.json` (`build: false` for a retired entry);
  do not add an exclusion list to the packager.
- *A test was failing before the build.* Stop. A release does not fix tests; go
  back to `wp-testing` and classify the failure.
- *`verify-deploy.py` reports a route failing after deployment.* Determine whether
  the fault is code or content; code rolls back, content has its own recovery.
  Never mix the two reversals.
- *A step could not run (no Docker, no network, no deployed site).* Record it as
  **blocked** or **not tested** and use `PASS WITH LIMITATION`. Never record an
  unrun step as verified.

## Evidence and reporting

Record: the per-step output of `./scripts/verify-release.sh`, the manifest table
(version, SHA, file count, size, SHA-256 per artifact), the determinism proof
(two identical hashes), the `release-manifest.py --verify` result, the
`verify-release-integrity.py` result, the activation order actually used, the
`verify-deploy.py` result, the release-log row and the rollback note including
what rollback does **not** cover. The build's per-step output belongs in
`docs/evidence/<date>-<stage>/`. `dist/` stays git-ignored.

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
