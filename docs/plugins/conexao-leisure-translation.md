# Conexão Leisure Translation

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | retired |
| **Class** | rollout |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-leisure-translation/conexao-leisure-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Lifecycle: activate → apply → remove.** This is a retired one-shot rollout plugin.
> It is **not** a production steady-state dependency and is **not** included in a
> release plugin ZIP. It is kept in the repository (and locally mounted) only so the
> historical importer stays reproducible.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

## Lifecycle status: superseded by the shared engine

**The lifecycle in this plugin is no longer the executed path.** It is a
hand-copied `apply.php` + `audit.php` pair written before the shared
`conexao-translation-rollout` engine existed, and re-activating it to run the
rollout would introduce a **second translation lifecycle** — the exact thing
engineering standard §4.1 and the task's constraints forbid.

| Concern | Owner now |
|---|---|
| Authored English descriptions (289 rows) | **`conexao-en-translation` → `includes/leisure-description-data.php`** (moved here verbatim) |
| Inventory, dry-run, snapshot, apply, PT-drift guard, numeric gate, remove | **`Conexao_Translation_Rollout_Engine`** via stage `en-leisure-description` |
| The field it writes | `_leisure_excerpt_en` — unchanged; the theme still reads it through `conexao_leisure_card_excerpt()` |

```bash
# the executed path (shared engine, shared runner)
php scripts/run-en-translation.php --dry-run --only=leisure-description
php scripts/run-en-translation.php --apply  --only=leisure-description
php scripts/run-en-translation.php --remove --apply --only=leisure-description
```

The plugin **stays in the repository, retired and inactive**, for the reason the
registry records for every retired rollout plugin: historical reproducibility.
Nothing in it is loaded, required or called at runtime any more, and
`plugins.json` still classifies it `retired` / `production: no` / `build: no`.

See [`conexao-en-translation.md`](conexao-en-translation.md) §"Stage 7 — the
`en-leisure-description` stage" for the stage, its gate and its rollback.

- **Path**: `wp-content/plugins/conexao-leisure-translation/`
- **Version**: 1.0.0
- **Purpose**: Stage 7 rollout — author the English card description of every published Portuguese `leisure` record (`_leisure_excerpt_en` post meta) so `/en/lazer/` renders English `.leisure-card-excerpt` text. The English layer is a **description-level translation on the same records**: no linked EN leisure posts, no duplicate records, no UUID changes.

## Responsibilities

- Write the human-authored English description from `data/stage7-leisure-descriptions.json` onto the existing PT leisure records (matched by slug, title verified).
- Refuse to apply a translation whose Portuguese source (`post_excerpt`) no longer matches the authored source (PT-drift guard) — stale translations never silently land.
- Never create posts, never write `_leisure_uuid` / `_leisure_export_uuid`, `post_excerpt`, `post_title`, `post_content`, taxonomies or the language assignment.
- Report per-record actions (applied / skip / refused / error) and the PT + UUID invariant counts.
- Provide a `remove` mode that deletes only the `_leisure_excerpt_en` values (rollback: the approved B2 fallback re-engages automatically).

## Key Components

| File | Purpose |
|------|---------|
| `conexao-leisure-translation.php` | Plugin bootstrap, admin screen (Tools → *EN Leisure Descriptions*), nonce-gated admin-post handler |
| `includes/apply.php` | Rollout engine: manifest loading, slug+title matching, PT-drift guard, apply/preview/remove |
| `includes/audit.php` | Completeness audit; gate = *published PT leisure records missing EN description* = 0 |
| `data/stage7-leisure-descriptions.json` | The authored manifest (289 entries: slug, title, pt_excerpt, en_excerpt). Built from the Stage 7 inventory + authored translations; the `pt_excerpt` is the drift guard's reference. |

## Rendering (theme side)

The theme owns the presentation: `conexao_leisure_card_excerpt()` (theme `inc/polylang.php`) returns the EN meta on EN requests when present and the exact existing `get_the_excerpt()` pipeline otherwise; `template-parts/leisure-card.php` applies the same 18-word `wp_trim_words()` + `esc_html()` to both languages. Records without an EN description keep the approved B2 fallback (PT description under the EN shell).

## Hooks

- `admin_menu` — registers the Tools page.
- `admin_post_conexao_leisure_translation_run` — executes preview / apply / remove.

No frontend hooks: the plugin is rollout tooling and can be deactivated (or removed) after the migration.

## Usage

### WP-CLI (local / staging)

```bash
cat scripts/run-leisure-translation.php | docker compose exec -T wordpress wp eval-file - --allow-root
# preview (default) / apply / remove / audit, append `json` for machine-readable output
```

### Admin (production, WordPress.com)

Tools → **EN Leisure Descriptions** → *Preview (dry run)* → *Apply* → verify the audit gate → *Remove (rollback)* only if a full rollback is intended.

## Rollback

`remove` deletes every `_leisure_excerpt_en` value written by the manifest. The Portuguese records are never touched, and `/en/lazer/` immediately returns to the approved B2 fallback. A full DB restore is the coarse fallback.

## Portability

The new meta key is part of the leisure migration contract: `conexao-leisure-migration`'s exporter and importer both carry `_leisure_excerpt_en` (sanitized as plain text), so a leisure export/import ZIP round-trips the English descriptions together with the Portuguese dataset.

## Important Rules

- The manifest's `pt_excerpt` is the authoritative translation source captured from production; if a PT description changes, the entry is refused until the translation is re-authored.
- Slug matching is language-unfiltered (`lang => ''`): the records are Portuguese and Polylang would otherwise scope the lookup.
- This stage does not translate Leisure titles, detail pages or taxonomies, and does not create EN Leisure records.

_Last verified: 2026-09-27 by the EN Leisure description rollout — lifecycle superseded by the shared engine_
