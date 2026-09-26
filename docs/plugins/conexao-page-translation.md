# Conexão BR Irlanda — EN Page Translation (Stage 4.5)

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | retired |
| **Class** | rollout |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-page-translation/conexao-page-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Lifecycle: activate → apply → remove.** This is a retired one-shot rollout plugin.
> It is **not** a production steady-state dependency and is **not** included in a
> release plugin ZIP. It is kept in the repository (and locally mounted) only so the
> historical importer stays reproducible.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

**Plugin:** `conexao-page-translation` · **Version:** 1.0.0 · **Scope:** WordPress website only (the Flutter app is frozen and out of scope).

One-shot, auditable migration that creates the **linked English translation of every eligible public WordPress Page** through Polylang — the Stage 4.5 page rollout of the English architecture (`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, Stages 1–4.3).

## Why a plugin (not a REST script, not WP-CLI)

- Production is **WordPress.com**: no SSH, no WP-CLI. The established production content-migration pattern in this project is an **admin-screen importer** (`conexao-leisure-migration`, `conexao-sponsor-migration`); this plugin follows it exactly (dry-run preview → confirm → apply → per-page report).
- **Polylang Free 3.8.9 cannot link translations over the public REST API** (verified against the 3.8.9 sources: the `lang` parameter is honoured on write, but the `translations` relationship is only written from the admin forms). Linking needs Polylang's PHP API (`pll_set_post_language` + `pll_save_post_translations`), which this plugin runs.

## What it does

1. Loads the **human-authored** translation data from `includes/translation-map.php` (single source of truth — no machine translation; the Stage 3.2 approved wording is reused for the 7 previously translated pages).
2. Creates each EN page **in dependency order**: front page → top-level pages → legal/utility (Phase 2 of the stage; a child is never created before its parent).
3. Links PT ⇄ EN through Polylang and **verifies the link from both directions** before reporting success (`pll_get_post` pt→en and en→pt).
4. Copies from the PT source: page template (`_wp_page_template`), menu order, featured media (media is shared), parent (translated parent when one exists) and pass-through custom fields (`_empregos_link`).
5. Sets `conexao_meta_description` to the authored EN description.
6. **Resolves internal links through the Polylang relationship** (`includes/apply.php` → `conexao_page_translation_resolve_path`): page links become the EN permalink path, archive links (`/guias/`, `/eventos/`, `/lazer/`…) become the EN archive URL, unresolved links stay PT (approved B1). Never string-prefix mangling.
7. Takes a **PT snapshot before and after** the run and fails loudly if any PT field changed (Phase 23 gate: zero unintended PT changes).

## Guarantees

- **Never modifies a Portuguese page** (no update call ever targets a PT id).
- **Idempotent**: existing EN translations are skipped (re-runs are safe; `update_existing` mode exists for repairs but is opt-in).
- **Refuses slug collisions** instead of colliding.
- **No cron, no frontend effect, no REST changes** — deactivate (or delete) after the rollout.

## Shared slugs (`blog`, `newsletter`)

Polylang Free has no shared-slug support (Pro feature). `blog` and `newsletter` are identical words in English, so their EN pages intentionally keep the PT `post_name` through a **scoped `wp_unique_post_slug` filter** (a one-shot transient names the exact permitted slug during that single `wp_insert_post` call). This keeps the documented URLs `/en/blog/` and `/en/newsletter/` (no `blog-2`). Polylang's pagename auto-translate disambiguates PT/EN requests by language, and `conexao_lang_url_object()` (theme `inc/polylang.php`) normalises lookups to the default-language source.

## Admin screen

Tools → **EN Page Translations**: current state table (PT page / planned EN slug / EN status), **Preview (dry run)** and **Apply** buttons (nonce + `manage_options`), and the full per-page report (created/exists/error + resolved link map).

## Run paths

| Environment | How | Notes |
|---|---|---|
| Local Docker / staging | `wp eval-file scripts/historical/stage45-translate-pages.php` (dry run) or `... apply` | Loads the same map + engine; prints the relationship table. |
| Local Docker / staging (repair) | `wp eval-file scripts/historical/stage45-translate-pages.php refresh [slug[,slug]]` | Re-applies the authored EN copy from the manifest to EN pages that ALREADY exist — the path used when the manifest copy is corrected or completed after a first run (e.g. the Jobs landing page). Never creates a second translation; the PT before/after gate still runs and must report `pt_changed = 0`. |
| Production (WordPress.com) | Activate the plugin → Tools → EN Page Translations → Preview → Apply | Requires the Stage 4.3 English layer (Polylang Free 3.8.9 + the current theme ZIP) deployed first. |
| Production (repair) | Activate the plugin → Tools → EN Page Translations → **Refresh existing EN pages** | Same contract as the local `refresh` mode; production has no WP-CLI, so the admin button is the production path. |

## Verification after the rollout

```bash
python3 scripts/historical/stage45-verify-pages.py --phase after --out /tmp/s45-after   # HTTP matrix (123 rows)
python3 scripts/historical/stage45-pt-snapshot.py --compare stage45-work/pt-snapshot.json <fresh-snapshot>.json
php wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php       # relationship table + invariance
python3 scripts/jobs-en-language-verify.py --base http://localhost:8080     # EN Jobs rendered language audit
php wp-content/themes/conexao-br-irlanda/tests/test-jobs-en-language.php    # EN Jobs source-level contract
```

See `CONEXAO_BR_ENGLISH_STAGE_4_5_REPORT.md` for the full acceptance matrix.

## Related files

| File | Role |
|---|---|
| `includes/translation-map.php` | The human-authored EN data (37 pages) — single source of truth |
| `includes/apply.php` | Creation/linking/verification engine + link localizer |
| `scripts/historical/stage45-translate-pages.php` | WP-CLI runner (local/staging) — same data, same engine |
| `scripts/historical/stage45-page-inventory.py` | Read-only page inventory builder (Phase 0) |
| `scripts/historical/stage45-validate-manifest.py` | Static manifest validation + JSON manifest render |
| `scripts/historical/stage45-pt-snapshot.py` | PT regression checkpoint (Phase 23) |
| `scripts/historical/stage45-verify-pages.py` | HTTP acceptance matrix (Phase 28) |
| `scripts/historical/stage45-translation-completeness-scan.py` | Untranslated-content review scanner (Phase 22) |

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
