# CONEXÃO BR IRLANDA — STAGE 9 REPORT: ENGLISH GUIDES

**Scope:** the WordPress website only (`/guias/` → `/en/guias/`). Flutter, the
mobile app, REST contracts, Events, Jobs, Blog, Leisure, Sponsors, Courses and
unrelated pages/CPTs were not touched. The Blog (Stage 5) and Jobs (Stage 6)
translations were used as the architectural reference and left unchanged.

---

## 1. Inventory (Phase 1) — measured, not assumed

| | |
|---|---|
| CPT | `guide` (registered by `conexao-data-model`, `has_archive => 'guias'`, `rewrite.slug => 'guias'`) |
| Public published guides | **51** Portuguese + 2 pre-existing EN editorial fixtures |
| Records with a linked EN translation, before Stage 9 | 1 (`stage32-editorial-source` ↔ `stage42-contract-fixture-en`) |
| `eligible public PT Guides missing EN`, before | **51** |
| Taxonomies | `conexao_category` only (no county/tag on any guide) |
| Internal links inside guide bodies | **0** (422 `href`s, all 196 unique targets external: gov.ie, HSE, Revenue, Irish Immigration, WRC, RTB, CCPC, FSPO, Central Bank, courts.ie, …) |
| Featured images | 0 on every guide (image/alt-text handling is therefore not exercised by this stage; the engine still copies a thumbnail if one ever exists) |

Classification: 51 PT-only guides requiring translation; 1 pre-existing EN
source-less fixture pair; 0 drafts/private; 0 technical exclusions. The
`stage32-editorial-source` / `stage32-editorial-translation` / `stage42-contract-fixture-en`
records are editorial fixtures from Stages 3.2/4.2 — they are **reported**, never
created or modified.

## 2. Architecture (Phase 2) — what `/guias/` actually is

- **Archive**: the CPT `has_archive`, not a Page. `archive.php` renders it; the
  `guide` branch of `conexao_content_archive_query()` (theme `functions.php`)
  applies `?categoria=`. `single.php` renders the singles. There is no
  page-backed archive and no `archive-guide.php`.
- **Filter bar**: `template-parts/guide-filters.php` → `?categoria=<slug>` over
  the `conexao_category` terms used by published guides
  (`conexao_get_terms_for_post_type()`).
- **B2 state**: `guide` is **not** in `conexao_b2_post_types()`. Guides were
  **B1**: `/en/guias/` rendered an EN shell with no guide content, and every
  `/en/guias/{pt-slug}/` 302'd to the PT guide.

## 3. State before the change (Phase 3) — measured over HTTP

| Request | Before |
|---|---|
| `/guias/` | 200, PT cards (10/page) |
| `/en/guias/` | 200, **2 cards** (only the two fixtures) — an effectively empty EN archive |
| `/en/guias/{pt-slug}/` | **302 →** `/guias/{pt-slug}/` (B1) |

## 4. What was delivered (Phases 4–8)

1. **Plugin `conexao-guide-translation`** (admin screen *Tools → EN Guide
   Translations*, plus a local runner). One-shot, auditable, idempotent,
   frontend-inert, safe to deactivate after the rollout. Docs:
   `docs/plugins/conexao-guide-translation.md`.
2. **51 linked EN guides** — authored English title, body, excerpt, meta
   description, English slug. The PT publication date, author and menu order are
   preserved; the featured image and `_guide_status` are copied when present.
3. **15 linked EN `conexao_category` terms** (the terms actually used by public
   PT guides), EN slugs following the Stage 3.2/3.3 contract
   (`financas → finances`, `negocios → businesses`, `empregos → jobs`, …). The
   `conexao_county`/town taxonomies are shared by architecture and were not
   touched.
4. **Archive UI (Phase 4)** — no template change was needed: the archive
   eyebrow, h1, description, "Guias" filter label, "Todos", empty state and
   pagination are all gettext strings already present and translated in
   `languages/en_US.po`. The rendered EN filter bar shows the English term names
   (All, Benefits, Business, Documents, Education, Finance, Health, Health &
   Wellbeing, Housing, Immigration & Visas, Jobs, Justice & Safety, Public
   Services, Safety & Rights, Tax & Revenue, Transport).
5. **Structure preserved (Phase 6)** — the EN bodies emit the same Gutenberg
   blocks as the PT source (`wp:heading` `h2`+`h3`, `wp:paragraph`, `wp:list`
   `ul`+`ol`, `wp:table`, `wp:quote`, `wp:separator`), the same section order and
   the same "Official links" / "Official sources" layer. No guide was redesigned
   and no content was truncated to a placeholder.
6. **Official/factual content (Phase 7)** — official organisation names keep
   their established English wording (An Garda Síochána, Garda National
   Immigration Bureau, Residential Tenancies Board, Department of Social
   Protection, Citizens Information, Revenue, HSE, Courts Service, WRC, FSPO,
   Central Bank, NDLS, RSA, NARIC/QQI, CAO, SUSI, HAP, RTB, NCT…). URLs, emails,
   phone numbers (112/999, 116 123, 1800 341 900, …), form numbers (MC1, REG1,
   Form 8/11, A1), eircodes, rates, dates and the "Last checked" stamps are
   preserved verbatim; **no internal link required re-pointing** (the 422 body
   links are all external and keep their original destination).
7. **SEO / canonical / hreflang / switcher / search / pagination / sitemap**
   were verified, not assumed:
   - EN single: self-canonical `/en/guias/{en-slug}/`, `hreflang` pt-BR + en +
     x-default, EN `<title>`, EN `meta description`
     (`conexao_meta_description`), EN `og:title`, language switcher → the real
     PT guide.
   - PT slug under `/en/` → **302 → PT** (replaced master, never 301).
   - EN search returns the EN guides; pagination `/en/guias/page/2/…` works.
   - Theme sitemap: 53 EN guide entries (51 translations + 2 fixtures) and 52 PT
     entries, each with the correct alternates.

## 5. One theme fix this stage required

`conexao_guide_category_filter_term_id()` (new, theme `functions.php`) makes the
Guide `?categoria=` filter language-neutral. Before it, `/en/guias/?categoria=saude`
(a Portuguese slug — e.g. from a homepage Quick Access card or a shared link) was
read as "content that exists only in Portuguese" and the visitor was 302'd to
`/guias/?categoria=saude`. Now the slug is resolved to the term of the same
concept **in the current language** (current-language slug first, otherwise the
counterpart through `pll_get_term()`; unknown slug → empty result set). The
Portuguese request path is byte-identical to before, and it is the same rule the
Events archive already used.

## 6. Gates

| Gate | Result |
|---|---|
| `eligible public PT guides missing EN` | **0** |
| `taxonomy terms used by PT guides missing EN` | **0** |
| `translated guide pairs verified` (both directions) | **51 / 51** |
| `EN guides missing PT translation` | 1 — documented, pre-existing Stage 3.2/4.2 editorial fixture |
| `PT sources changed` (regression gate) | **0** |
| `guides excluded (documented)` | 0 |
| Idempotent re-run | `created 0, updated 0, skipped 51, errors 0` |

Verification commands:

```bash
# in-process suite (632 assertions)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php
# HTTP contract
./scripts/stage9-guide-http-verify.sh
# audit only (dry run)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php dry-run
```

## 7. Regression status of the untouched areas

`test-i18n-foundation` (29/0), `test-job-en-translation` (29/0) and
`test-guide-en-translation` (632/0) are green. `test-stage33-bilingual` reports
169 passed / 14 failed: the three Guide-card assertions Stage 9 fixed now pass;
the remaining 14 fail for a pre-existing reason in this local dataset (the
Stage 3.2/4.5 event, course and EN-page fixtures are not present here). The
Stage 2 / 3.2 / 4.1 / 4.5 / Blog suites keep their **pre-existing** failures for
the same missing-fixture reason, unchanged by this stage.

## 8. Production rollout (WordPress.com, no WP-CLI)

1. Deploy the theme change (`functions.php`) — the `?categoria=` resolver is a
   pure addition, capability-guarded, and a no-op without Polylang.
2. Upload and activate `conexao-guide-translation`.
3. **Tools → EN Guide Translations → Preview**, then **Apply**.
4. Check `eligible public PT guides missing EN = 0`, then deactivate/remove the
   plugin.

| `/en/guias/` head | self-canonical `/en/guias/`, hreflang pt-BR + en + x-default (archive-level only) |
