# Plugins

<!-- BEGIN GENERATED PLUGIN REGISTRY: docs/plugins/README.md load order -->
This project contains **15 custom WordPress plugins**, all in
`wp-content/plugins/`. The authoritative registry is [`plugins.json`](../../plugins.json);
the tables below are generated from it by `scripts/generate-registry-docs.php` and must
not be hand-edited.

## Load order and lifecycle

Load order is the row order. Dependencies always precede their dependents.

| # | Plugin | Class | Status | Production | Build | Mount | Dependencies | Version | Docs |
|---|--------|-------|--------|------------|-------|-------|--------------|---------|------|
| 1 | `conexao-data-model` | platform | active | yes | yes | yes | - | 1.6.1 | [conexao-data-model](./conexao-data-model.md) |
| 2 | `conexao-content` | platform | active | yes | yes | yes | - | 1.0.1 | [conexao-content](./conexao-content.md) |
| 3 | `conexao-admin-ux` | platform | active | yes | yes | yes | - | 1.0.7 | [conexao-admin-ux](./conexao-admin-ux.md) |
| 4 | `conexao-event-runtime` | platform | active | yes | yes | yes | `conexao-data-model` | 1.2.2 | [conexao-event-runtime](./conexao-event-runtime.md) |
| 5 | `conexao-event-importer` | tooling | active | no | yes | yes | `conexao-data-model`, `conexao-event-runtime` | 1.7.1 | [conexao-event-importer](./conexao-event-importer.md) |
| 6 | `conexao-leisure-migration` | tooling | active | no | yes | yes | - | 2.1.0 | [conexao-leisure-migration](./conexao-leisure-migration.md) |
| 7 | `conexao-sponsor-migration` | tooling | active | no | yes | yes | - | 1.1.0 | [conexao-sponsor-migration](./conexao-sponsor-migration.md) |
| 8 | `conexao-translation-rollout` | platform | active | yes | yes | yes | - | 1.2.0 | [conexao-translation-rollout](./conexao-translation-rollout.md) |
| 9 | `conexao-en-translation` | tooling | active | no | no | yes | `conexao-translation-rollout` | 1.5.0 | [conexao-en-translation](./conexao-en-translation.md) |
| 10 | `conexao-translation-automation` | platform | active | yes | yes | yes | `conexao-translation-rollout` | 0.6.0 | [conexao-translation-automation](./conexao-translation-automation.md) |
| 11 | `conexao-page-translation` | rollout | retired | no | no | yes | - | 1.0.0 | [conexao-page-translation](./conexao-page-translation.md) |
| 12 | `conexao-blog-translation` | rollout | retired | no | no | yes | - | 1.0.0 | [conexao-blog-translation](./conexao-blog-translation.md) |
| 13 | `conexao-job-translation` | rollout | retired | no | no | yes | - | 1.0.0 | [conexao-job-translation](./conexao-job-translation.md) |
| 14 | `conexao-leisure-translation` | rollout | retired | no | no | yes | - | 1.0.0 | [conexao-leisure-translation](./conexao-leisure-translation.md) |
| 15 | `conexao-guide-translation` | rollout | retired | no | no | yes | - | 1.0.0 | [conexao-guide-translation](./conexao-guide-translation.md) |

**Production steady state** (platform, `production: true`) - activate in this order: conexao-data-model -> conexao-content -> conexao-admin-ux -> conexao-event-runtime -> conexao-translation-rollout -> conexao-translation-automation.

**Local-only tooling** (never production): conexao-event-importer, conexao-leisure-migration, conexao-sponsor-migration, conexao-en-translation.

**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a production dependency and not in any release ZIP): conexao-page-translation, conexao-blog-translation, conexao-job-translation, conexao-leisure-translation, conexao-guide-translation.

### Dependency evidence

Dependencies are **not** inferred. They are read from the plugins' own
`Requires Plugins:` headers, and the generator fails when the registry and the headers
disagree. Only these headers declare a dependency today:

- `conexao-event-runtime` -> `Requires Plugins: conexao-data-model`
- `conexao-event-importer` -> `Requires Plugins: conexao-data-model, conexao-event-runtime`

WordPress therefore refuses to activate `conexao-event-importer` (and keeps it from
running) without the event runtime plugin.

### Lifecycle vocabulary

| Term | Meaning |
|------|---------|
| `class: platform` | Production runtime the site depends on. |
| `class: tooling` | Local-only operational tooling. Never a production dependency. |
| `class: rollout` | One-shot English-rollout plugin. **Retired** - documented as *activate → apply → remove*. Not a production dependency, never in a release ZIP. |
| `status: active` | In the current lifecycle. |
| `status: retired` | Rollout already applied; kept for history only. |
| `production: true` | Part of the production steady state (activation order). |
| `build: true` | Included in release plugin ZIPs by `scripts/build-plugins-zip.sh`. |
| `mount: true` | Bind-mounted locally by `compose.yaml`. Local development only; it never implies production activation. |

## Third-Party Plugins

No third-party plugins are bundled in this repository. The project relies only on these
15 custom plugins and core WordPress functionality (plus Polylang Free 3.8.9 for the
English layer).

## Regenerating this page

```bash
php scripts/generate-registry-docs.php --check   # validate + drift gate (zero writes)
php scripts/generate-registry-docs.php --write   # write generated regions
```
<!-- END GENERATED PLUGIN REGISTRY: docs/plugins/README.md load order -->

## Building

```bash
./scripts/build-plugins-zip.sh
```

Output: `dist/*.zip` — one ZIP per registry entry with `build: true`, each
structured for WordPress plugin upload. The list is generated from
`plugins.json`; retired rollout plugins are never packaged.
