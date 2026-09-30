# Deployment

## Local Environment

- **Docker Compose**: WordPress 7.0.2 + MySQL in containers.
- **URL**: http://localhost:8080
- **Data**: Persistent via Docker volumes (`db_data`, `wordpress_data`).
- **Theme/Plugins**: Bind-mounted from the host filesystem; changes are live-reloaded.
- **Permissions**: Entrypoint script (`docker/entrypoint-fix-permissions.sh`) ensures Apache can write to bind-mounted theme/plugin directories.

## Production Environment

- **Platform**: WordPress.com
- **Domain**: https://conexaobr.ie
- **Deployment method**: Manual ZIP upload via WordPress admin

### Production Differences from Local

| Aspect | Local | Production |
|--------|-------|------------|
| Web server | Apache (Docker) | WordPress.com managed |
| Database | MySQL in Docker | WordPress.com managed |
| File system | Bind-mounted | WordPress.com managed |
| HTTPS | HTTP only | HTTPS (managed) |
| Rewrite rules | `.htaccess` active | WordPress.com supports .htaccess |
| Cron | Manual / WP-CLI | WordPress.com wp-cron |

## Build Process

### Plugins

<!-- BEGIN GENERATED PLUGIN REGISTRY: docs/deployment.md activation order -->
Generated from [`plugins.json`](../../plugins.json) by
`scripts/generate-registry-docs.php`. Do not hand-edit this section.

### Production activation order

The filtered subset of the registry load order where `production: true`. It contains no
`production: false` entry:

1. `conexao-data-model`
2. `conexao-content`
3. `conexao-admin-ux`
4. `conexao-event-runtime`
5. `conexao-translation-rollout`
6. `conexao-translation-automation`

Production needs exactly these 6 platform plugins. They are the only
plugins that must be installed **and active** on production.

### Release build output

```bash
./scripts/build-plugins-zip.sh
```

Produces one ZIP per `build: true` entry (9 files), in registry order:

- `dist/conexao-data-model.zip`
- `dist/conexao-content.zip`
- `dist/conexao-admin-ux.zip`
- `dist/conexao-event-runtime.zip`
- `dist/conexao-event-importer.zip`
- `dist/conexao-leisure-migration.zip`
- `dist/conexao-sponsor-migration.zip`
- `dist/conexao-translation-rollout.zip`
- `dist/conexao-translation-automation.zip`

Import via WordPress Admin -> Plugins -> Add New -> Upload Plugin, then activate in
the production order above.

### Local-only tooling (not production)

Built and mounted so a developer can run them locally, but **not** production
dependencies and never left active on production:

- `conexao-event-importer`
- `conexao-leisure-migration`
- `conexao-sponsor-migration`
- `conexao-en-translation`

### Retired rollout plugins (historical tooling)

These one-shot English-rollout plugins have already been applied. They are **not** part
of a normal production release: each is `build: false` (no ZIP is produced) and none is
activated in the production steady state. They remain in the repository, and are locally
mounted only so the historical importer stays reproducible.

**Lifecycle: activate → apply → remove.**

- `conexao-page-translation` - activate → apply → remove
- `conexao-blog-translation` - activate → apply → remove
- `conexao-job-translation` - activate → apply → remove
- `conexao-leisure-translation` - activate → apply → remove
- `conexao-guide-translation` - activate → apply → remove
<!-- END GENERATED PLUGIN REGISTRY: docs/deployment.md activation order -->

## The release process

The release contract — the release record, the tag convention, deployment
verification and the rollback procedure — is documented in
[`docs/releases.md`](releases.md) and normative in
[`docs/engineering-standard.md` §11](engineering-standard.md#11-deployment-and-release-standard).
The executable workflow — gates, build, record, manual upload, verification,
rollback — is the **`wp-release-deploy`** skill
(`.agents/skills/wp-release-deploy/SKILL.md`). One command proves the whole
machinery locally before relying on it:

```bash
./scripts/verify-release.sh   # registry gate, build, manifest, hashes,
                              # determinism, exclusion proof, HTTP — local only
```

Nothing here uploads, activates or mutates: the release tooling is deliberately
build-and-verify only.

## Production constraints

| Constraint | Consequence |
|---|---|
| No SSH / SFTP | Deployment is a manual ZIP upload through wp-admin |
| No WP-CLI | Activation is an admin action; no scripted migrations |
| No filesystem or database access | `dist/` on a maintainer machine is the only place artifacts exist |

Because production has no CLI, every production capability must also have an
admin screen (engineering standard §0.12). The release tooling in this repository
is deliberately build-and-verify only: it never uploads, never activates and
never writes content.

## Media thumbnails for new image sizes

The theme registers two additive sizes used by the homepage responsive
images: `conexao-sponsor-tile` (512px, Hero Apoiador carousel) and
`conexao-event-preview` (240×135 crop, homepage event previews). Attachments
uploaded *before* these sizes existed must be regenerated once on production
so the srcset ladders include the small derivatives:

```bash
# Local (Docker): regenerate the sponsor + event banner attachments
docker compose exec wordpress php wp-cli.phar media regenerate <IDs...> --skip-delete --yes --allow-root
```

On WordPress.com production, regenerate thumbnails for the same attachments
via WP-CLI (`wp media regenerate`) or a plugin equivalent after deploying the
theme update. The `scripts/generate-logo-derivatives.php` script creates the
240px header-logo derivatives from the theme assets (already committed; run
it only if the logo masters are ever replaced).

## Media Handling

- **Local development**: Media uploaded via WordPress admin goes into the Docker volume.
- **Production**: Media uploaded via WordPress.com goes into the WordPress.com Media Library.
- **Migration**: Lazer export/import ZIPs carry actual image files; production always uses local Media Library attachments.
- **No hotlinking**: Production leisure images are never served from Wikimedia Commons or external CDNs. Wikimedia metadata is attribution-only.

## Data migration and rollout procedures

The import/export screens and their procedures are documented where they are
owned; every content write follows the six-step content-change contract
(engineering standard §5.2; executable workflow: the **`wp-content-change`**
skill, `.agents/skills/wp-content-change/SKILL.md` — dry-run first, stable
identifiers only, idempotent apply, numeric gate).

| Operation | Screen / script | Owned by |
|---|---|---|
| Lazer (leisure) dataset transfer | Lazer → *Exportar Lazer* / *Importar Lazer* (dry-run preview + confirm) | [`plugins/conexao-leisure-migration.md`](plugins/conexao-leisure-migration.md) |
| Sponsors transfer | Apoiadores Migration → *Exportar Apoiadores* / *Importar Apoiadores* | [`plugins/conexao-sponsor-migration.md`](plugins/conexao-sponsor-migration.md) |
| Events import/export | Event Import → Export / Import (JSON; matching `source + source_id` → UUID) | [`plugins/conexao-event-importer.md`](plugins/conexao-event-importer.md) |
| Production leisure image corrections (no PHP/CLI on WordPress.com) | `scripts/update-lazer-images-rest.py` (`--dry-run` first; REST + application password from the environment) | [`../scripts/README.md`](../scripts/README.md) |

Matching across environments is always stable UUID → slug → title (never
WordPress post IDs). The REST image refresh requires the `conexao-data-model`
update that adds an `auth_callback` to `register_leisure_meta()` — without it
the attribution meta is read-only over REST; deploy that plugin update first.

## Environment Differences

- **Local**: Full admin access, WP_DEBUG enabled, `WORDPRESS_DEBUG=1`
- **Production**: WordPress.com managed, WP_DEBUG disabled, caching enabled
- **Domain-specific configuration**: None required — all paths are relative

## Translation rollouts

The registry is authoritative for the engine's lifecycle: `conexao-translation-rollout`
is a `platform` plugin and part of the production steady state (see the generated
activation order above). The retired one-shot rollout plugins are historical
tooling and never in a release ZIP.

Running an actual rollout on production is an **admin workflow** — production
is WordPress.com and has **no WP-CLI**. The executable workflow is the
**`wp-translation-rollout`** skill (`.agents/skills/wp-translation-rollout/SKILL.md`);
the screen contract (Preview → Apply → Remove) is owned by
[`docs/plugins/conexao-translation-rollout.md`](plugins/conexao-translation-rollout.md)
§"Admin workflow". In short: Tools → **Translation Rollouts** → select the
registered stage → **Preview** (zero writes; prints the
`create`/`update`/`skip`/`conflicts` plan plus the numeric gate) → **Apply**
(writes, then re-verifies PT-unchanged — `pt_drift` must be 0 — and
recalculates the gate) → **Remove** only for a stage that declares
`allow_remove`, otherwise that stage's documented recovery.

The engine registers its admin action with `manage_options` and a nonce, never
writes on page load or on GET, and performs no content write on activation.

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_

_Last verified: 2026-09-30 by the agent-skills documentation migration (release/migration/rollout procedures moved to the skills; stale engine lifecycle facts corrected against plugins.json — the engine was promoted to platform on 2026-09-30)_
