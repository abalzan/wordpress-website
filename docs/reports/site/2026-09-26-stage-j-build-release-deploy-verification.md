# Stage J — Build, Release & Deploy Verification

**Scope:** the WordPress website repository only. Flutter/mobile was not
touched. This stage implements the release contract of
[`docs/engineering-standard.md` §11](../../engineering-standard.md#11-deployment-and-release-standard).

**Status:** complete and verified locally. No production deployment, upload,
activation or content change was performed — see §8.

---

## 1. Scope and boundary

| | |
|---|---|
| Baseline SHA | `b421e0a664bde28d526f15edaddc1536786e6d2c` |
| Branch | `i18n` |
| Files added | 9 |
| Files modified | 9 |
| PHP files touched | **0** |
| `wp-content/` files touched | **0** |
| Production writes | **0** |

The two invariants this stage makes executable:

1. **`plugins.json` determines what can be released** — the artifact allowlist is
   *derived*, never hand-maintained.
2. **`release.json` records exactly what was actually built** — version, git
   SHA, file count, byte size and SHA-256 per artifact, with an allowlisted but
   unbuilt artifact recorded as `"built": false` rather than invented.

`scripts/verify-deploy.py` answers a third question independently: does the
deployed site behave as expected — **without mutating it**.

## 2. What was built

| File | Role |
|---|---|
| `scripts/lib/zip-build.sh` | The **one** release-packaging implementation, shared by both build scripts. §11 exclusion rules + determinism. |
| `scripts/lib/release.py` | The **one** release-record implementation: allowlist derivation, version reading, git identity, manifest build/validate/verify. |
| `scripts/release-manifest.py` | CLI: `--write` emits `dist/release.json`; `--verify` re-hashes every artifact; `--check`; `--print`. |
| `scripts/verify-deploy.py` | Read-only HTTP deployment verification (GET only, no credentials, `--site` required with **no default**). |
| `scripts/verify-release.sh` | Local end-to-end proof of the whole workflow in 7 steps. |
| `scripts/data/release-smoke-matrix.json` | The **fixed** release smoke matrix (18 rows). |
| `tests/scripts/verify-release-integrity.py` | Release-integrity gate: 209 assertions, no WordPress, no network. |
| `tests/acceptance/verify-release-http.py` | Runs the same matrix + same check functions against the local site. |
| `docs/releases.md` | The release contract: sequence, manifest, tag convention, verification, rollback, production constraints. |

Modified: both `build-*.sh` (now use the shared packager + emit the manifest),
`.github/workflows/ci.yml` (new `release-integrity` job; `shellcheck
scripts/lib/*.sh`), and 6 documentation files.

## 3. Design decisions worth recording

**The allowlist is derived, not declared.** There is no second list of
releasable artifacts. `expected_allowlist()` reads `plugins.json`
(`build: true`, in load order) and appends the theme. This satisfies the
standard's "two places is a bug" rule *and* still gives explicit allowlisting:
the manifest records the allowlist, and `verify_manifest()` fails on drift
between the recorded allowlist and what the registry permits.

**A retired rollout is refused, not filtered.** `build_slugs()` raises
`ReleaseError` if a `build: true` entry is `retired`, so a hand-edited registry
cannot ship historical rollout code. Tested negatively.

**Determinism required normalising mtimes.** Measured before implementing: two
builds of an identical tree produced *different* SHA-256s, because `zip` embeds
the checkout mtime. The recorded hash would have been meaningless as a
reproducibility claim. The fix normalises mtime and permission bits on a
**staging copy**, so a developer's working tree is never mutated. Verified:
two builds of the same source are now byte-identical.

**Exclusions are centralised and stricter than before.** The old build scripts
each carried their own `-x` list and neither excluded `*.json` reports. Both now
use `scripts/lib/zip-build.sh`, which also excludes `*.log`, `__pycache__`,
`*.orig/.rej/.bak` while deliberately **keeping** `languages/*.pot`
(translation catalogues are release content, not reports).

**Version bumps were deliberately not made.** §11 requires versions to be bumped
and recorded per release. Stage J changes no component behaviour, so bumping a
version would misrepresent an infrastructure change as a product change. The
mechanism that *reads* and *records* versions is implemented and proven; the
first real bump belongs to whoever ships the first release.

## 4. Two real defects the new gate found

The deployment verifier was run against the local site and failed on two rows.
Both were **gate bugs, not product defects** — the value of writing the gate
before trusting it:

1. `/sitemap.xml` — the row asserted `<urlset>`, but Jetpack serves a
   `<sitemapindex>` pointing at per-post-type sitemaps. Asserting `<urlset>`
   would have made the gate permanently red on a healthy site. Now asserts the
   XML prolog plus the schema namespace.
2. `leisure` singles — the gate expected HTTP 200, but a Lazer listing with an
   Official Website is **intentionally** redirected off-site by
   `conexao_leisure_external_url()` (theme `inc/seo/redirects.php`). Leisure is a
   directory, not an article type. The gate now accepts a 3xx to an **external**
   host for leisure, and additionally asserts the redirect actually leaves the
   site. An unexpected 3xx on any other post type is still a failure.

## 5. Verification (real numbers, this checkout)

### 5.1 Local end-to-end release workflow

`./scripts/verify-release.sh` — **exit 0**, all 7 steps:

| Step | Result |
|---|---|
| 1. Registry drift gate | OK — 13 plugins validated, 22 generated regions current |
| 2. Build plugins + theme | OK — 7 plugin artifacts + 1 theme artifact |
| 3. `dist/release.json` | OK — versions, git SHA, file counts, SHA-256 |
| 4. Allowlist + hash verify | OK — every artifact matches size, file count and hash |
| 5. Determinism proof | OK — all artifacts reproduced **byte-for-byte** |
| 6. Exclusion proof | OK — no test/fixture/report/junk file in any artifact |
| 7. HTTP deployment verification | OK — 18 matrix rows, 42 assertions, 0 failed |

### 5.2 Release record

8 artifacts, 8 built, 202 files, 6,238,852 bytes. Every version read from its
component header:

| Artifact | Kind | Version | Files |
|---|---|---|---|
| `conexao-data-model` | plugin | 1.6.0 | 6 |
| `conexao-content` | plugin | 1.0.0 | 5 |
| `conexao-admin-ux` | plugin | 1.0.6 | 14 |
| `conexao-event-runtime` | plugin | 1.2.1 | 7 |
| `conexao-event-importer` | plugin | 1.7.1 | 42 |
| `conexao-leisure-migration` | plugin | 2.1.0 | 7 |
| `conexao-sponsor-migration` | plugin | 1.1.0 | 6 |
| `conexao-br-irlanda` | theme | 1.0.0 | 115 |

Sample manifest: `docs/evidence/2026-09-26-stage-j/release-manifest.json`.

### 5.3 Gates

| Gate | Command | Result |
|---|---|---|
| Release integrity | `python3 tests/scripts/verify-release-integrity.py` | **209 passed, 0 failed** |
| Script contract (Stage I, must not regress) | `python3 tests/scripts/verify-script-conventions.py` | **284 passed, 0 failed** |
| Release HTTP acceptance | `python3 tests/acceptance/verify-release-http.py` | **42 passed, 0 failed** |
| Registry drift | `php scripts/generate-registry-docs.php --check` | OK, zero writes |

### 5.4 Negative proofs (a verifier that cannot fail is not a verifier)

| Proof | Result |
|---|---|
| Tamper with a built artifact → `--verify` | **FAILS** (sha256 + byte-size mismatch) |
| Manifest naming a non-allowlisted (`build:false` tooling) artifact | **REJECTED** ("does not permit") |
| Registry with a `build:true` **retired** entry | **ReleaseError raised** |
| `verify-deploy.py` with no `--site` | **exits non-zero**, explains `--site` is required |
| `verify-deploy.py --site ftp://…` / `not-a-url` / `https://` | **all rejected** |
| Two builds of the same source | **byte-identical** |

### 5.5 Regression check — no behaviour change

`git status` confirms **no `wp-content/` file was modified**: Stage J touches
only build scripts, release tooling, tests, CI and docs.

`./scripts/run-tests.sh` was run before and after, on the same local stack:

| | Failing in-process suites | Failing script-contract | Failing acceptance |
|---|---|---|---|
| Pristine `b421e0a` (stashed) | **15** | 0 | — |
| With Stage J | **15** | 0 | 0 |

`diff` of the two failing-suite lists is **empty**: Stage J introduced **zero
regressions**.

The 15 pre-existing failures are **local content-data conditions**, not code
defects — untranslated blog posts (`pt=42 en=0`), Eircode-contaminated
`conexao_town` terms, Portuguese meta descriptions on EN pages, and importer
fixtures left over from earlier seed runs. They fail identically with Stage J
fully stashed, which is the proof. They are **out of Stage J scope** (fixing them
requires a content rollout) and are recorded here rather than silently ignored.

### 5.6 Static quality

- `php -l` over 433 PHP files: **no parse errors**.
- `scripts/lint.sh` **cannot complete in this environment**: `composer` is not
  installed, so PHPStan cannot run and PHPCS produces no report. This is an
  environment limitation, not a regression: `phpcs.xml.dist` and `phpstan.neon.dist`
  scope only `wp-content/`, and **Stage J adds zero PHP files**. CI installs
  Composer and runs the full gate.

## 6. Definition of done (§14)

| Item | Result |
|---|---|
| Scope matches the request; nothing unrelated refactored | Yes — build/release/verification only |
| PHPCS + PHPStan pass | N/A in this environment (§5.6); CI is authoritative; 0 PHP files added |
| PHP syntax checked | Yes — 433 files clean |
| In-process test added/updated | `tests/scripts/verify-release-integrity.py` (209 assertions) |
| HTTP matrix addition | `tests/acceptance/verify-release-http.py` on the fixed 18-row release matrix |
| **Releases: `dist/release.json` emitted, versions recorded, deployment verification run, rollback noted** | **Yes — §11 fully implemented** |
| Docs updated in the same commit; docs end with `_Last verified_` | Yes — `docs/releases.md` (new), `deployment.md`, `testing.md`, `README.md`, `AGENTS.md`, `scripts/README.md` |
| No secrets, no localhost URLs in production data, no committed build output | Yes — `dist/` stays git-ignored; no production host hard-coded in any script |
| Report with real verification numbers and stated limitations | This document |
| Flutter/mobile untouched | Yes |

## 7. CI coverage

New blocking job `release-integrity` in `.github/workflows/ci.yml`:

- `generate-registry-docs.php --check` (allowlist source of truth);
- the release-integrity gate (209 assertions, including the tamper and
  non-allowlisted negative proofs);
- a real build + `--verify` of `dist/release.json`;
- the manifest uploaded as a CI artifact for evidence;
- `fetch-depth: 0`, because the manifest records the commit SHA and a shallow
  clone would record a grafted one.

`shellcheck` was extended to `scripts/lib/*.sh`, since the shared shell library
is sourced and `scripts/*.sh` alone would leave it unchecked.

The HTTP half of the gate runs in the existing `integration` job via the
acceptance layer. The job needs no WordPress, no database and no network, and
never contacts a deployed site.

## 8. Production constraints and what was NOT done

Production is **WordPress.com**: no SSH, no WP-CLI, no filesystem, no database
access. Deployment is therefore a manual ZIP upload through wp-admin, and
activation is an admin action. These constraints are now documented explicitly
in `docs/releases.md` and `docs/deployment.md`.

Explicitly **not** performed, by design:

- no artifact was uploaded anywhere;
- no production plugin was activated or deactivated;
- no production content or database was written;
- no Polylang configuration, REST contract or CPT/taxonomy change;
- no production deployment was run, and `dist/release.json` was never verified
  against a live site.

The tooling is deliberately **build-and-verify only**: it packages, records and
observes. It contains no upload, no activation and no mutating code path — a
property the release-integrity gate asserts (GET-only, no credentials, no
default production target).

The release log in `docs/releases.md` is therefore intentionally empty: the
first row is added by whoever performs a real release, together with its
verified evidence and rollback note.

## 9. Rollback

This stage is itself reversible with no production involvement: every change is
in Git on branch `i18n`, and `git checkout . && git clean -fd wp-content` is not
even required — nothing under `wp-content/` was modified. Reverting the commit
removes the release tooling entirely and restores the previous build scripts.

The **documented rollback procedure for actual releases** (previous manifest,
previous plugin/theme set, re-upload, re-verify, content-failure path) is in
[`docs/releases.md`](../../releases.md#rollback).

## 10. Limitations and follow-ups

1. **No production release has been exercised.** The machinery is proven locally
   end to end, but the first real release will be its first production run. The
   manifest and the deploy verifier are the tools that make that run auditable.
2. **15 pre-existing in-process suite failures** in the local environment (local
   content data, not code). Left untouched as out of scope; they should be
   resolved by a content/seed pass, and are the obvious next candidate.
3. **`scripts/lint.sh` is unverifiable locally** here (no `composer`). CI is the
   authoritative gate; no PHP was added, so the risk is nil, but the local
   developer experience is degraded by the missing tool.
4. **The smoke matrix is deliberately fixed** at 18 rows. It will not detect a
   regression in a route the matrix does not name; deeper coverage lives in the
   routing and guides-en acceptance matrices, which CI runs continuously.
5. **Version bumps are implemented but unused.** The first release should bump
   whatever it actually changes and record it in `docs/releases.md`.

## 11. Evidence

`docs/evidence/2026-09-26-stage-j/`

| File | Proof |
|---|---|
| `verify-release-local.txt` | The 7-step local release workflow, exit 0 |
| `release-integrity-gate.txt` | 209 passed, 0 failed |
| `script-convention-gate.txt` | 284 passed, 0 failed (Stage I not regressed) |
| `release-http-acceptance.txt` | 42 passed, 0 failed (smoke matrix, locals) |
| `release-manifest.json` | A real `dist/release.json` from this checkout |

_Last verified: 2026-09-26 by Stage J — Build, Release & Deploy Verification_
