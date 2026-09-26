# conexao-guide-translation

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | retired |
| **Class** | rollout |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-guide-translation/conexao-guide-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Lifecycle: activate → apply → remove.** This is a retired one-shot rollout plugin.
> It is **not** a production steady-state dependency and is **not** included in a
> release plugin ZIP. It is kept in the repository (and locally mounted) only so the
> historical importer stays reproducible.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

**Purpose:** turn the Guide CPT from the approved **B1 302 policy** (`/en/guias/`
did not have an English archive; every guide detail URL 302'd to Portuguese)
into a **real English translation**: one linked EN translation per eligible
public Portuguese `guide`, plus the linked English `conexao_category` terms
used by those guides.

Owner: project maintainer. Introduced by Stage 9.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-guide-translation/` |
| Admin screen | Tools → **EN Guide Translations** |
| WP-CLI | `wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php` (`php <file> [dry-run] [json]`) |
| In-process test | `wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php` |
| HTTP verification | `scripts/verify-guides-http.sh [base-url]` |
| Frontend effect | **none** (importer only — the rendering contract lives in the theme) |
| Safe to deactivate | yes, after the rollout |
| Depends on | Polylang (the English language layer must exist first) |

## What it does

1. **Category terms** — creates/links the English `conexao_category` term for
   every PT term actually used by a public guide (15 in the measured dataset).
   A re-run repairs manifest-owned drift (slug/name/description) instead of
   creating a second term.
2. **Guides** — for every manifest entry (keyed by the **Portuguese slug**,
   never by a local ID), creates or refreshes exactly ONE linked English
   `guide` with the authored English title, body, excerpt and meta description,
   preserving the PT publication date, author and menu order.
3. **Relationships** — `pll_set_post_language` + `pll_save_post_translations`,
   verified in BOTH directions before a row is reported successful.
4. **Taxonomy** — the EN guide is filed under the **EN counterpart** of the PT
   category term (resolved through `pll_get_term()`), never under the PT term.
5. **Shared field layer** — the featured image (when present) and the editorial
   `_guide_status` meta are copied; the PT record is never touched.
6. **Duplicate gate** — refuses to create a second EN guide when the EN slug is
   already used by an unlinked record; a drifted slug is repaired.
7. **PT regression gate** — every PT guide is snapshotted before the run
   (slug, title, body, excerpt, status, date, author, menu order, thumbnail,
   terms, `_guide_status`, language, meta description) and byte-compared after
   it (`pt_changed` must be 0; the only permitted write is a Polylang language
   backfill for a language-less record).
8. **Idempotent + deterministic** — `g_content()` normalises the authored HTML
   to the exact form WordPress stores (KSES rewrites `attr="v"/>` to
   `attr="v" />`), so a no-op re-run reports `created 0, updated 0` and never
   churns `post_modified`.
9. **Audit** — the completeness inventory is printed at the end of every run and
   on the admin screen.
10. **Cache/route refresh** — after the run the Polylang language cache and the

## Content sources

| File | Contents |
|---|---|
| `includes/terms.php` | The EN `conexao_category` term map (PT slug → EN name/slug/description). EN slugs follow the Stage 3.2/3.3 contract (`financas → finances`, `negocios → businesses`, `empregos → jobs`, …). |
| `includes/builder-1.php` | The block helpers. They emit exactly the PT block structure (`wp:heading`, `wp:paragraph`, `wp:list`, `wp:table`, `wp:quote`, `wp:separator`, …) — only the text is English. |
| `includes/body-*.php` | The authored English bodies, one function per guide (split across files only for editor size). |
| `includes/guides-a…d.php` | The manifest: one row per PT slug with `en_slug`, `en_title`, `en_excerpt`, `en_meta_description` and the body function. |
| `includes/apply*.php` | The apply engine (snapshot gate, term linking, create/refresh, PT regression gate). |
| `includes/audit.php` | The completeness inventory / gate. |

## Guarantees

- **The Portuguese originals are never modified.** Only a Polylang language
  backfill (a record with no language at all) is ever written on the PT side.
- **Genuinely English, structurally equivalent.** Every EN body is a real
  English translation that keeps the PT heading hierarchy, lists, tables,
  callouts, block attributes and the "Official links" / "Official sources" layer.
  Official organisation names, URLs, phone numbers, form numbers and identifiers
  are preserved verbatim; nothing is machine-substituted and no placeholder or
  "coming soon" text is produced. The test suite asserts the EN body is not a
  copy of the PT body and leaks no Portuguese stop-words.
- **No invented URLs** — the EN permalinks and the official-source URLs come
  from the authored manifest, never from string replacement. External links
  (gov.ie, HSE, Revenue, Courts Service, WRC, RTB, …) keep their original
  destination in both languages.
- **Idempotent** and safe to re-run at any time.

## Completeness gate

```
eligible public PT guides missing EN  = 0
taxonomy terms used by PT guides missing EN = 0
EN guides missing PT translation = 1 (documented: the pre-existing
    stage42-contract-fixture-en / stage32-editorial-translation editorial
    fixtures from earlier stages — reported, never created or modified here)
PT sources changed (must be 0)
```

A manifest entry whose Portuguese guide is absent from the target site is
reported as a **documented exclusion** (the translation is portable: it is keyed
by slug), never as a silent pass.

## Running it

```bash
# Local, in-process runner (LOCAL ONLY)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php dry-run
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php

# Production (WordPress.com, no WP-CLI): activate the plugin, then
# Tools → EN Guide Translations → Preview → Apply.
```

## Frontend contract (theme)

The importer is inert on the front end. The visible behaviour it unlocks lives
in the theme:

- `/guias/` and `/en/guias/` are two language-scoped views of the same CPT
  archive. Polylang scopes the query per language, so the EN archive lists the
  EN guides and never the PT siblings.
- EN singles are self-canonical, carry the PT↔EN↔x-default hreflang set, the
  authored EN SEO title/description, the EN social metadata and a language
  switcher pointing at the real PT translation.
- The PT slug under `/en/` is a replaced master: it answers **302 → the PT
  guide** (never Portuguese content under an English URL, and never a 301).
- `conexao_guide_category_filter_term_id()` (functions.php) makes the
  `?categoria=` filter language-neutral: the filter bar links with the current
  language's slugs, and a slug from the other language is resolved through the
  real Polylang term relationship. An unknown slug yields an empty result set
  instead of a cross-language redirect.

    rewrite rules are refreshed, so the new `/en/guias/…` routes work in the
    same request cycle.

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
