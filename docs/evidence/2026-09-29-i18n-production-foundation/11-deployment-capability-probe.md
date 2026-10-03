# Deployment capability probe — why Phase 3 cannot be executed from this repository

Captured 2026-09-29. Read-only (`GET` / `OPTIONS`). No production write performed.

## 1. There is no REST route to install or activate a theme

`GET /wp-json/` advertises **22 namespaces** and **all** routes. Enumerating every
route for a `POST`/`PUT`/`PATCH`/`DELETE` method yields **328 writable routes**.
Filtering that set for anything theme-shaped:

| Route | Methods |
|---|---|
| `/wp/v2/themes` | **GET only** |
| `/wp/v2/themes/(?P<stylesheet>...)` | **GET only** |
| `/wp/v2/global-styles/themes/(?P<stylesheet>...)` | **GET only** |
| `/wp/v2/global-styles/themes/(?P<stylesheet>...)/variations` | **GET only** |

**No writable theme route exists anywhere on the site.** Confirmed by
`OPTIONS /wp-json/wp/v2/themes` → `{"methods":["GET"]}` (evidence `09-themes-OPTIONS.json`).

The WordPress REST API has no theme-install endpoint at all. Theme installation is
an `Appearance → Themes → Add New → Upload Theme` screen operation.

## 2. The repository's own tooling cannot deploy

`docs/releases.md:9-12` states this normatively:

> "No step described here has been performed against production by this
> repository's tooling, and **none of the scripts can deploy**: they package,
> record and verify."

`./scripts/build-theme-zip.sh` terminates by printing the manual steps:

```
To import:
  1. Go to WordPress Admin → Appearance → Themes → Add New
  2. Click 'Upload Theme'
  3. Select .../dist/conexao-br-irlanda.zip and click 'Install Now'
  4. After installation, click 'Activate'
```

`docs/releases.md` §Rollback repeats it: production "has no CLI and no shell",
and both deploy and rollback are "an admin operation using artifacts you kept".

AGENTS.md reinforces this: *"Production has no CLI. WordPress.com: no SSH, no
WP-CLI, no filesystem, no database."*

## 3. Phase 2 backup verification is likewise not possible read-only

Phase 2 requires verifying that the Sunday UpdraftPlus backup *exists*, *when*,
and that it is *restorable*. The backup inventory is rendered only by the
UpdraftPlus wp-admin screen. Enumerating every route for `updraft`/`backup`
returns **no UpdraftPlus route at all** — only unrelated Jetpack site-backup and
`wpcomsh/v1/backup-import/status` routes, none of which expose the plugin's
backup catalogue, its timestamps, or a restore-verification result.

## 4. What IS writable (for completeness)

| Route | Methods | Note |
|---|---|---|
| `/pll/v1/settings` | GET, POST, PUT, PATCH | Polylang settings are writable |
| `/pll/v1/languages` | GET, POST | Polylang language creation is writable |
| `/wp/v2/plugins` | POST | plugin install/activate is writable |
| `/wp/v2/settings` | GET, POST, PUT, PATCH | site options |

So Phase 5 (Polylang configuration) is technically reachable over REST. It is
**not** safe to perform it here, because the task orders it strictly *after* the
`i18n` theme is proven live ("Only after the `i18n` theme is confirmed live").
Configuring languages on top of the still-deployed `master` build would ship a
half-applied bilingual configuration to 2,285 PT records, with no verified
rollback and no verified recovery point.
