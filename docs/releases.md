# Releases

The release contract for this repository is engineering standard
[§11](engineering-standard.md#11-deployment-and-release-standard). This document
is its operational companion: the sequence, the record, the tag convention, the
verification and the rollback procedure. The executable end-to-end workflow —
including the deploy and rollback steps — is the **`wp-release-deploy`** skill
(`.agents/skills/wp-release-deploy/SKILL.md`); operating on production itself is
the `wp-production-operations` skill.

Everything here is proven locally by `./scripts/verify-release.sh`, which runs the
whole workflow — build, manifest, allowlist/hash verification, determinism,
exclusion proof and HTTP deployment verification — against the **local** site.
No step described here has been performed against production by this repository's
tooling, and none of the scripts can deploy: they package, record and verify.

---

## The two invariants

| Invariant | Meaning | Enforced by |
|---|---|---|
| **`plugins.json` determines what can be released** | The artifact allowlist is *derived* from the registry (`build: true`, in load order, plus the theme). Nothing else may enter a release. | `scripts/lib/release.py::expected_allowlist`; `scripts/generate-registry-docs.php --check` |
| **`release.json` records exactly what was actually built** | The manifest states the version, git SHA, file count, byte size and SHA-256 of each artifact that exists on disk — not what ought to exist. | `scripts/release-manifest.py --verify`; `tests/scripts/verify-release-integrity.py` |

The deployment verifier answers a third, separate question: *does the deployed
site behave the way this release expects?* — and it answers it **without
mutating the site**.

---

## The release sequence

```bash
# 1. Gates — nothing is packaged until these pass.
./scripts/lint.sh && ./scripts/run-tests.sh

# 2. Build the artifacts. Each build emits dist/release.json.
./scripts/build-plugins-zip.sh
./scripts/build-theme-zip.sh

# 3. Verify the record against the artifacts and the registry.
python3 scripts/release-manifest.py --verify

# 4. Upload the theme, then the plugins in plugins.json order (platform first),
#    and activate to the documented steady state.
#    See docs/deployment.md — production has no SSH and no WP-CLI.

# 5. Verify the deployment over HTTP (read-only).
python3 scripts/verify-deploy.py --site https://<host>

# 6. Record the release below, and keep the previous artifacts for rollback.
```

To prove the entire workflow locally in one command:

```bash
./scripts/verify-release.sh
```

---

## Artifacts and the allowlist

| Artifact | Source of truth | Ships `tests/`? |
|---|---|---|
| `dist/<plugin>.zip` — one per `build: true` registry entry, in load order | `plugins.json` `build` flag | No |
| `dist/conexao-br-irlanda.zip` | the single active theme | No |
| `dist/release.json` | emitted by the build | n/a |

Never in a release ZIP: `tests/`, `fixtures/`, `node_modules/`, `*.json` reports,
`*.log`, `__pycache__/`, and OS/editor junk (`.DS_Store`, `Thumbs.db`, `*.swp`,
`*~`, `*.orig`, `*.rej`, `*.bak`). The `languages/*.pot` catalogues **do** ship —
they are release content, not reports.

The rules live in exactly one place, `scripts/lib/zip-build.sh`, so the plugin and
theme builds cannot drift apart.

### Determinism

Artifacts are built reproducibly: entries are added in sorted order, mtimes and
permission bits are normalised on a **staging copy** (never on your working tree),
and `zip -X` drops the uid/gid/extended-timestamp fields that would otherwise
make an archive machine-dependent.

Two builds of the same source therefore produce byte-identical ZIPs, which is
what makes the recorded SHA-256 a meaningful claim. Set `SOURCE_DATE_EPOCH` to
pin the timestamp across machines.

`verify-release.sh` proves this by building twice and comparing every archive
byte-for-byte.

---

## `dist/release.json`

```jsonc
{
  "schema_version": 1,
  "release": {
    "tag": "v2026.09.26",          // conforms to the tag convention below
    "built_at": "2026-09-26T12:14:46Z",
    "git": { "sha": "<full 40-char SHA>", "short_sha": "b421e0a664bd",
             "branch": "i18n", "dirty": false, "tag": null, "available": true }
  },
  "allowlist": {                   // what the registry PERMITS
    "source": "plugins.json",
    "artifacts": ["conexao-data-model", "…", "conexao-br-irlanda"],
    "production_order": ["conexao-data-model", "conexao-content",
                         "conexao-admin-ux", "conexao-event-runtime"]
  },
  "artifacts": [                   // what was ACTUALLY built
    {
      "slug": "conexao-data-model",
      "kind": "plugin",            // plugin | theme
      "version": "1.6.0",          // read from the component header, never typed twice
      "file": "conexao-data-model.zip",
      "built": true,
      "file_count": 6,             // file entries in the archive
      "bytes": 15827,
      "sha256": "9908771d923a5ec4…"
    }
  ],
  "totals": { "artifacts": 8, "built": 8, "files": 202, "bytes": 6238852 }
}
```

An allowlisted artifact that was not built is recorded with `"built": false` and
null hashes, so an **incomplete build is visible in the record** instead of
looking complete.

### Verifying a manifest

```bash
python3 scripts/release-manifest.py --verify     # allowlist + re-hash every artifact
python3 scripts/release-manifest.py --check      # schema only (no artifact reads)
python3 scripts/release-manifest.py --print      # print the record
```

`--verify` fails on: allowlist drift against `plugins.json`, a missing
allowlisted artifact, a non-permitted artifact, a version that no longer matches
its component header, and any sha256 / size / file-count mismatch.

---

## Release tag convention

```
vYYYY.MM.DD          first release on a given day
vYYYY.MM.DD-N        Nth release on the same day (2, 3, …)
```

Examples: `v2026.09.26`, `v2026.09.26-2`.

The convention is enforced, not merely documented: `release.RELEASE_TAG_RE`
validates it, `validate_manifest()` rejects a manifest whose `release.tag` does
not match, and `tests/scripts/verify-release-integrity.py` asserts that the
documentation and the enforcing code still agree.

When the checkout is already on a conforming release tag, that tag *is* the
release and is recorded as such.

---

## Deployment verification

`scripts/verify-deploy.py` is read-only: GET requests only, no credentials, no
mutating code path. `--site` is **required and has no default**, so a mistyped or
forgotten invocation can never silently probe a live site.

```bash
python3 scripts/verify-deploy.py --list                              # show the matrix
python3 scripts/verify-deploy.py --site http://localhost:8080        # local
python3 scripts/verify-deploy.py --site https://<host> --out docs/evidence/<date>-<stage>/deploy-<tag>.json
```

The smoke matrix is **fixed** (`scripts/data/release-smoke-matrix.json`, 18 rows).
It deliberately does not grow between releases — only its recorded result
changes — so two release verifications are directly comparable. It covers every
category §11 requires:

| Category | Rows |
|---|---|
| Homepage | `/`, `/en/` |
| Every archive | `/guias/`, `/eventos/`, `/cursos/`, `/empregos/`, `/apoiadores/`, `/lazer/`, `/blog/` |
| `/en/` pairs | `/en/guias/`, `/en/eventos/`, `/en/lazer/`, `/en/blog/` |
| Canonical + hreflang | asserted on every archive and `/en/` pair row, and on each discovered single |
| Sitemap | `/sitemap.xml`, `/robots.txt` |
| 404 | `/nao-existe-xyz-stage-j/`, `/en/nao-existe-xyz-stage-j/` |
| Legacy redirect | `/guides/` → `/guias/` (301) |

On top of the static rows the verifier adds:

- **One single per post type**, discovered live from the public REST API (one
  record, no credentials) and then fetched and checked for canonical + hreflang.
  Slugs are discovered rather than hard-coded so the gate cannot rot when content
  is renamed.
  *Leisure is the one documented exception:* a listing with an Official Website
  is intentionally redirected off-site by `conexao_leisure_external_url()`
  (theme `inc/seo/redirects.php`), so for leisure a 3xx to an **external** host is
  a pass and the redirect is verified to actually leave the site.
- **Language-layer integrity**: Portuguese stays canonical, `/en/` is the English
  document, and a legacy English slug still 301s to its Portuguese URL.

Exit code 0 means every check passed.

The same matrix also runs as a local acceptance suite
(`tests/acceptance/verify-release-http.py`), so CI proves the release gate itself
works on every push instead of only at release time.

---

## Production constraints

Production is **WordPress.com**. This is not a preference; it determines the
whole deployment model:

| Constraint | Consequence |
|---|---|
| **No SSH / SFTP** | There is no way to push a file. Deployment is a manual ZIP upload through wp-admin. |
| **No WP-CLI** | No `wp plugin activate` from a shell. Activation is an admin action. No scripted migrations. |
| **No filesystem access** | `dist/` on a maintainer's machine is the only place artifacts exist. |
| **No database access** | Content changes are admin screens or REST only, never SQL. |
| Managed caching + HTTPS | Verification must tolerate caching; the verifier asserts behaviour, never headers that a cache rewrites. |
| Third-party plugins are installed, not vendored | Polylang is pinned and installed by version, never committed. |

Because there is no CLI, **every production capability must also have an admin
screen** (standard §0.12). The release tooling here is deliberately build- and
verify-only: it never uploads, never activates and never writes content.

---

## Rollback

Production has no CLI and no shell, so a rollback is an **admin operation using
artifacts you kept**. Prepare it *before* you deploy; the executable steps are
in the `wp-release-deploy` skill (`.agents/skills/wp-release-deploy/SKILL.md`,
step "Rollback"). This section owns the **semantics**:

### Before deploying, record this

1. **Previous `dist/release.json`** — copy it somewhere durable outside `dist/`
   (it is git-ignored) or paste its table into the release entry below. It names
   the exact previous version + SHA-256 of every artifact.
2. **Previous plugin set** — installed plugin versions and which were active.
   From the previous manifest plus the `production_order` block.
3. **Previous theme version** — the theme's `Version:` header, same source.
4. **Content state** — if the release included a content change or a translation
   rollout, record its own rollback (see §0.7: snapshots before writes). A code
   rollback does **not** undo a content change.

### Rollback rules

- **Decide code fault vs content fault first** — they roll back differently
  (re-upload previous artifacts in `plugins.json` order vs restore that
  change's own snapshot or documented recovery; never improvised SQL).
- **Update in place, never deactivate/reactivate to "refresh"** — activation
  hooks are not re-runnable (e.g. `conexao-content` activation rebuilds pages
  and the navigation menus).
- **Deactivating a single faulty plugin** is the fastest partial rollback;
  the steady state (`production: true` subset, all active in order) is always
  a known-good fallback.
- **Every rollback is verified** with the same gate used for the release:
  `python3 scripts/verify-deploy.py --site https://<host>`.
- **Record the rollback** in this file: what was rolled back, to which SHA, and
  the verification result.

### What a rollback does **not** cover

- **Database or content changes** made through an admin screen or REST. These
  need their own snapshot (standard §0.7) and their own recovery.
- **`.htaccess` / Polylang configuration.** Never modified by a release; never
  "rolled back" as part of one.
- **Local restore.** `scripts/restore-updraft-db.sh` is the only sanctioned local
  database restore, and it is destructive and local-only.

---

## Version bump record (release identity)

Required by engineering standard §11: *"Versions are bumped in the component
header and recorded in `CHANGELOG.md` / `docs/releases.md` with the git
SHA."* This repository has no per-component `CHANGELOG.md`, so the record lives
here. It records the **artifact versions only** — it is **not** a release log
row, because **no artifact was deployed** and no production action was
performed.

The bump below is the next **patch** version of each production artifact
(`master`→`i18n` changed all five, with no interface or schema break). Only the
authoritative version declarations changed: each plugin's WordPress header
`Version:` field plus the matching version constant it ships
(`Conexao_Data_Model::VERSION`, `CONEXAO_CONTENT_VERSION`,
`CONEXAO_ADMIN_UX_VERSION`, `CONEXAO_EVENT_RUNTIME_VERSION`,
`CONEXAO_THEME_VERSION`), and the theme's `style.css` `Version:`. No
plugin name, text domain, dependency, `Requires at least`/`Requires PHP`
declaration, asset or code was touched. Local-only tooling and retired rollout
plugins keep their versions.

| Artifact | Kind | Old | New | Authoritative source |
|---|---|---|---|---|
| `conexao-data-model` | plugin (production) | 1.6.0 | **1.6.1** | `wp-content/plugins/conexao-data-model/conexao-data-model.php` header |
| `conexao-content` | plugin (production) | 1.0.0 | **1.0.1** | `wp-content/plugins/conexao-content/conexao-content.php` header |
| `conexao-admin-ux` | plugin (production) | 1.0.6 | **1.0.7** | `wp-content/plugins/conexao-admin-ux/conexao-admin-ux.php` header |
| `conexao-event-runtime` | plugin (production) | 1.2.1 | **1.2.2** | `wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php` header |
| `conexao-br-irlanda` | theme (production) | 1.0.0 | **1.0.1** | `wp-content/themes/conexao-br-irlanda/style.css` |

Unchanged (local-only / retired, deliberately **not** bumped):
`conexao-event-importer` 1.7.1, `conexao-leisure-migration` 2.1.0,
`conexao-sponsor-migration` 1.1.0, `conexao-translation-rollout` 1.1.0,
`conexao-en-translation` 1.5.0, and the five retired rollout plugins.

| Field | Value |
|---|---|
| Base commit | `a4cd5f65e35c3f6f564e733bc746a07f350cceeb` (`i18n`) |
| Release record | `dist/release.json`, regenerated by `scripts/release-manifest.py --write` |
| Local verification | `./scripts/verify-release.sh` (7/7 stages) and `release-manifest.py --verify` — see [`reports/2026-09-29-release-identity.md`](reports/2026-09-29-release-identity.md) |
| Production actions | **none** — nothing uploaded, activated or migrated |
| Deploy verification | **not performed** (no deployment is authorised by this change) |

> The **release log** below stays empty until someone actually deploys. The
> first row there is added by whoever performs a release, together with the
> verified `deploy-<tag>.json` evidence and the rollback note from the steps
> above.

---

## Release log

Newest first. One row per release: the tag, the commit, and the verification
result. Add a row **in the same change** that prepares the release.

| Tag | Commit | Theme | Plugins | Deploy verification | Rolled back |
|---|---|---|---|---|---|
| _(no release performed by this tooling yet)_ | — | — | — | — | — |

> No production release has been performed through this repository's tooling.
> The first release row is added by whoever performs a release, together with the
> verified `deploy-<tag>.json` evidence and the rollback note from the steps
> above.

---

## Related documents

| Document | What it covers |
|---|---|
| [engineering-standard.md](engineering-standard.md) §11 | The normative release contract |
| [deployment.md](deployment.md) | Upload/activation procedure, production differences |
| [testing.md](testing.md) | The three test layers and the release suites |
| [../scripts/README.md](../scripts/README.md) | Catalogue of every release script |
| [../plugins.json](../plugins.json) | The artifact allowlist source of truth |

_Last verified: 2026-09-26 by Stage J — Build, Release & Deploy Verification_
_Last verified: 2026-09-29 by the production artifact version bump (5 artifacts bumped to the next patch version; release record regenerated; no deployment)_
_Last verified: 2026-09-30 by the agent-skills documentation migration (rollback steps moved to the wp-release-deploy skill; this document keeps the rollback semantics)_
