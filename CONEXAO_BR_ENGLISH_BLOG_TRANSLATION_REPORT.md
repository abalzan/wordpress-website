# CONEXAO_BR — ENGLISH BLOG: FROM B2 FALLBACK TO REAL ENGLISH CONTENT

**Stage:** 5 (Blog translation) — WordPress website/theme only.
**Status:** **PASS** — every eligible public Portuguese blog post has a real, linked English
translation; `/en/blog/` is a genuine English archive (no B2 fallback); Portuguese
originals verified byte-identical; local HTTP acceptance matrix 60/60; theme
in-process suite 27/27.
**Production:** `https://conexaobr.ie/` — **not modified, not deployed** by this stage
(the repo produces the artifacts; the rollout is an operator step, see §Production rollout).
**Flutter / mobile app:** untouched (no file, contract or REST change in this stage).

---

## 0. Scope and environment

Task: translate the entire public Blog into English, convert the Blog from the
approved **B2 fallback** (`/en/blog/` rendering Portuguese posts under the English URL)
into a **real English translation**, and retire the B2 treatment for the Blog only —
without touching Flutter, the REST contract, the event/leisure architecture, or any
other CPT's content.

| In scope | Out of scope (untouched) |
|---|---|
| `post` (Blog) records, the posts page (`page_for_posts`), the `category`/`post_tag` terms actually used by them | every CPT (guide, event, course_provider, job, sponsor, leisure), their archives and content |
| Blog archive routing / canonical / hreflang / pagination / search scoping / sitemap entries | REST contracts, Flutter app, event runtime, leisure runtime, OAuth/hosting config |
| Theme language logic that decides Blog B2 vs real EN | the approved B2 architecture for the remaining destinations |

### Verification environment (important)

This sandbox has **no Docker and no PHP** preinstalled and no network access to the
production database, so the project's local Docker site could not be started here.
To verify the work end to end a **faithful sandbox WordPress clone** was built
(PHP 8.4.23 static build + WordPress + SQLite drop-in + **Polylang 3.8.9**, the same
version as the project) and seeded with the **real production Blog** read through the
public REST API (`https://conexaobr.ie/wp-json/wp/v2/posts`, 36 published posts,
their categories and the posts page). Every claim below is either
(a) an HTTP response from that clone, (b) an in-process WordPress check, or
(c) a comparison against the production REST payloads.

Nothing in this report was executed against production.

---

## 1. Executive summary

| Phase | Deliverable | Result |
|---|---|---|
| 1 | Dynamic inventory of the real Blog data | **PASS** — 36 public posts, 5 categories used, 0 tags used, 3 featured images, 0 drafts/private/excluded |
| 2 | Posts page / Blog page inventory + real EN posts page | **PASS** — PT `page_for_posts` #5 ↔ EN posts page, linked both ways, `/en/blog/` |
| 3 | Blog page content translated | **PASS** — EN posts-page record (title/body/meta) + English archive chrome via the theme `.mo` |
| 4 | Every eligible post translated | **PASS** — 36/36, human translations, structure-preserving |
| 5 | English slugs | **PASS** — 36 natural EN slugs, no `-en`/`-2`, PT slugs untouched |
| 6 | Polylang relationships | **PASS** — 36/36 verified in both directions |
| 7 | Blog categories | **PASS** — 5/5 used terms have linked EN terms, PT slugs preserved |
| 8 | Blog tags | **PASS** — no tag is used by any published post → nothing translated (no clutter created) |
| 9 | Internal links | **PASS** — localized through the Polylang relationship (2 rules), external URLs untouched |
| 10 | B2 fallback removed for the Blog | **PASS** — automatic once the EN posts page is published; B2 architecture intact elsewhere and re-verified in both states |
| 11 | EN Blog archive | **PASS** — 200, `lang=en-US`, EN posts/UI, self-canonical, hreflang pair, **no notice** |
| 12 | Pagination | **PASS** — `/blog/page/1-4/` and `/en/blog/page/1-4/` (36 posts, 10/page), out-of-range 404, EN pages show EN posts |
| 13 | Category archives | **PASS** — EN category archives resolve under `/en/`, PT archives unchanged |
| 14 | Search | **PASS** — EN search returns the EN translation, PT search returns the PT original |
| 15 | SEO | **PASS** — self-canonical per language, correct `og:locale`, hreflang pair, no noindex, no 302 to PT |
| 16 | Sitemap | **PASS** — PT + EN blog URLs listed, alternates only for real pairs, no duplicates |
| 17 | Language switcher | **PASS** — archive and single, both directions |
| 18 | Header navigation | **PASS** — EN `Blog` → `/en/blog/`, PT `Blog` → `/blog/`, no 302, no duplicate menu item |
| 19 | Portuguese content preserved | **PASS** — 36/36 posts byte-identical to the production payloads |
| 20 | Completeness audit | **PASS** — `eligible public PT posts missing EN = 0` |
| 21 | Regression testing | **PASS** — see §13 (no new failures; two tests updated to the new approved behaviour) |

---

## 2. Phase 1 — inventory of the real Blog data

Source of truth: the live public REST API of the site (read-only), captured into
`stage5-work/source/` (one JSON per post) and summarised in
`stage5-work/inventory-after.json`.

| Metric | Value |
|---|---|
| Public `post` records | **36** (`status=publish`, `type=post`) |
| Already translated before this stage | **0** (the Blog had no EN content at all) |
| Draft / private / protected posts | **0** |
| Posts in a non-default language | **0** |
| Technically excluded posts | **0** |
| Categories actually used | **5** — Saúde e Bem-estar (24), Empreendedor (6), Receitas (3), Capacitação (2), Lazer (1) |
| Tags actually used | **0** (the site has one empty, unused `test` tag — deliberately not translated) |
| Featured images | 3 posts (shared verbatim with the EN translation) |
| Inline images / galleries / embeds | none (the source bodies contain no media beyond the featured images) |
| Internal links inside bodies | 2 (both pointing at the legacy Wix URL of another post in this set — localised, see §7) |
| External links | 34 (Instagram, job boards, training providers, mental-health organisations) — preserved byte-identical |

Classification of every post (Phase 1 requirement):

- **PT only, requires EN translation:** 36 — the complete public Blog.
- **Already has a real EN translation / EN source / inherited:** 0.
- **Draft / private / protected:** 0.
- **Technically excluded:** 0. No post uses B1/B2 as a substitute for translation.

---

## 3. Phase 2 + 3 — the Blog page (posts page)

The Blog archive is served by the WordPress **posts page** (`page_for_posts`, PT page
`#5`, `/blog/`) — *not* by a `post` post type archive (WordPress registers `post`
with `has_archive = false`). The English archive therefore needs a **real, linked EN
page record**; without one the theme kept the approved B2 fallback in force.

| | PT | EN |
|---|---|---|
| Page | `#5` `blog` | `#174` `blog` (linked translation of #5) |
| URL | `/blog/` | `/en/blog/` |
| Title | Blog | Blog |
| Content | (archive template; page body unused by `home.php`) | English intro paragraph (manifest `posts_page.content`) |
| Meta description | — | `The Conexão BR Irlanda blog — articles, news and information for Brazilians in Ireland.` |
| Polylang pair | ✔ both directions (`pll_get_post(5,'en')=174`, `pll_get_post(174,'pt')=5`) | ✔ |

The visible archive chrome is produced by `home.php` through the theme's own
`en_US` catalog and was verified in the browser response (Phase 3):

| Element | PT | EN (rendered) |
|---|---|---|
| Eyebrow | Artigos e Notícias | **Articles & News** |
| Title | Blog | **Blog** |
| Description | Informações, dicas e notícias para a comunidade brasileira na Irlanda. | **Information, tips and news for the Brazilian community in Ireland.** |
| Filter labels | Categorias / Todos | **Categories / All** |
| Category chips | Capacitação, Empreendedor, Lazer, Receitas, Saúde e Bem-estar | **Training, Entrepreneurship, Leisure, Recipes, Health & Wellbeing** |
| Empty state | Nenhum artigo publicado ainda. | **No articles published yet.** |

No Gutenberg block, attribute, template, custom field or media relationship of the
posts page was altered, and the page template stays the theme default.

---

## 4. Phase 4 — every eligible post translated

36 of 36 public posts received **one** linked English translation each. Content is
human-authored (translation workflow in `stage5-work/TRANSLATION-BRIEF.md`, per-post
sources in `stage5-work/source/`, per-post outputs in `stage5-work/en/`, generated
manifest `wp-content/plugins/conexao-blog-translation/includes/translation-map.php`).

What is preserved per post (checked by `stage5-work/validate-en.py`, 36/36 pass):

- **HTML structure parity** — identical tag multiset and heading-level counts
  (`<p>`, `<h2>`–`<h5>`, `<ul>`, `<li>`, `<strong>`, `<em>`, `<u>`, anchors), including
  the source's stray closing tags (Wix-era markup) so the rendering is unchanged.
- **Every `href`/`src` byte-identical** except the 2 intentional internal-link rules
  (§7); no URL, e-mail, Eircode, phone number, handle, name, statistic, measurement or
  code snippet was translated.
- Body is a genuine translation (never a copy of the PT body, no Portuguese prose),
  plus a newly authored English excerpt and meta description per post.
- The featured image, publication date, author, comment/ping status and taxonomy of
  the PT sibling are reused (shared media — no duplicate attachments).

One post (`informacoes-para-as-mulheres-na-irlanda`) consists of a single external
flip-book link with no prose at all: its body is necessarily identical, while its
title, excerpt and meta description are translated. This is registered as a content
property, not an exclusion (`test-blog-en-translation.php` allows it explicitly).

---

## 5. Phase 5 — English slugs

Derived from the English title, lowercase ASCII, no `-en`/`-2` suffixes, no collisions
(`stage5-work/validate-en.py` fails the build otherwise). Portuguese slugs were never
touched. Full mapping (36 rows):

| PT slug | EN slug | EN title |
|---|---|---|
| `1-cuidando-de-si-em-terra-estrangeira` | `1-caring-for-yourself-in-a-foreign-land` | 1. Caring for Yourself in a Foreign Land |
| `2-saude-mental-e-imigracao-o-que-ninguem-conta` | `2-mental-health-and-immigration-what-nobody-tells-you` | 2. Mental Health and Immigration: What Nobody Tells You |
| `a-caneta-emagrece-e-o-espirito-corre-atras` | `the-pen-sheds-weight-does-the-spirit-catch-up` | The Pen Sheds Weight… Does the Spirit Catch Up? |
| `a-medicacao-pode-ajudar-a-abrir-a-porta-mas-o-treino-e-a-mudanca-de-habitos-que-vao-manter-os-resul` | `medication-can-open-the-door-training-habits-keep-results` | Medication Can Help Open the Door, but Training and Habit Change Keep the Results |
| `alinhar-o-invisivel-para-harmonizar-o-cotidiano` | `aligning-the-invisible-to-harmonise-daily-life` | Aligning the Invisible to Harmonise Daily Life |
| `as-relacoes-de-casal-na-nova-era` | `couple-relationships-in-the-new-era` | Couple Relationships in the New Era |
| `atividade-fisica-nao-deve-ser-um-projeto-de-verao-deve-ser-um-projeto-de-vida` | `physical-activity-a-project-for-life` | Physical Activity Should Not Be a Summer Project, It Should Be a Project for Life. |
| `bolo-cocada-molhadinho` | `moist-coconut-cake` | Moist Coconut Cake (Cocada Molhadinho) |
| `canetas-emagrecedoras-emagrecer-ficou-mais-facil-mas-e-a-saude` | `weight-loss-pens-but-what-about-your-health` | Weight-Loss Pens: Losing Weight Got Easier… But What About Your Health? |
| `capacitacao-em-laois-irlanda-capacitacao-em-laois-irlanda` | `training-and-upskilling-in-laois` | Training and Upskilling in Laois |
| `carne-refogada-ao-estilo-korean-bbq` | `korean-bbq-style-stir-fried-beef` | Korean BBQ-Style Stir-Fried Beef |
| `como-voce-se-sente-nessa-epoca-do-ano` | `how-do-you-feel-at-this-time-of-year` | How Do You Feel at This Time of Year? |
| `construindo-rede-de-apoio-longe-de-casa` | `building-a-support-network-far-from-home` | Building a Support Network Far From Home |
| `coragem-a-estrela-que-ilumina-o-caminho-do-empreendedor-especial-de-natal` | `courage-the-star-that-lights-the-entrepreneurs-path` | Courage! The Star That Lights the Entrepreneur's Path — Christmas Special |
| `cupcake-da-granny` | `grannys-cupcake` | GRÁNNY'S CUPCAKE |
| `dois-anos-novos-um-medido-pelo-homem-outro-sentido-pela-terra` | `two-new-years-measured-by-man-felt-by-the-earth` | Two New Years: One Measured by Man, Another Felt by the Earth |
| `dois-fatores-que-fazem-o-seu-negocio-ter-consistencia-nas-vendas` | `two-factors-that-give-your-business-sales-consistency` | TWO FACTORS THAT GIVE YOUR BUSINESS SALES CONSISTENCY. |
| `entre-o-silencio-do-inverno-e-o-primeiro-sopro-da-primavera` | `between-the-silence-of-winter-and-the-first-breath-of-spring` | Between the Silence of Winter and the First Breath of Spring |
| `escalar-e-necessario-crescer-com-consistencia-e-o-proximo-nivel-do-seu-negocio` | `scaling-is-essential-growing-consistently` | SCALING IS ESSENTIAL! Growing Consistently Is the Next Level for Your Business! |
| `gestao-financeira-o-alicerce-invisivel-de-todo-negocio-que-prospera` | `financial-management-the-invisible-foundation` | FINANCIAL MANAGEMENT: the invisible foundation of every business that thrives |
| `informacoes-para-as-mulheres-na-irlanda` | `information-for-women-in-ireland` | Information for Women in Ireland |
| `movimento-e-medicina` | `movement-is-medicine` | Movement Is Medicine! |
| `o-calor-que-nasce-do-inverno` | `the-warmth-that-is-born-of-winter` | The Warmth That Is Born of Winter |
| `o-inverno-do-ego-um-renascimento-poetico` | `the-winter-of-the-ego-a-poetic-rebirth` | The Winter of the Ego — A Poetic Rebirth |
| `o-que-todo-pequeno-empreendedor-precisa-aprender-para-fazer-o-negocio-crescer-por-que-entender-toda` | `what-every-small-business-owner-needs-to-learn` | What Every Small Business Owner Needs to Learn to Grow Their Business. Why understanding every area of the company can be the difference between growing and closing your doors? |
| `o-sol-que-habita-em-nos` | `the-sun-that-dwells-within-us` | The Sun That Dwells Within Us |
| `papo-de-empreendedor-com-um-toque-criativo-leve-e-motivador-para-inspirar-quem-pensa-em-empreende-13` | `entrepreneur-talk-inspiring-future-entrepreneurs` | Entrepreneur Talk: with a creative, light and motivating touch, to inspire anyone thinking of starting a business — and also to spark some good laughs and deep reflections 😉 |
| `preparando-o-corpo-para-o-inverno` | `getting-the-body-ready-for-winter` | Getting the Body Ready for Winter |
| `primavera-chegou-um-convite-para-renovar-tambem-a-sua-alimentacao` | `spring-has-arrived-an-invitation-to-renew-your-nutrition` | 🌿 Spring Has Arrived: An Invitation to Renew Your Nutrition Too |
| `primavera-o-chamado-silencioso-para-florescer-com-consciencia` | `spring-the-silent-call-to-bloom-with-awareness` | Spring: The Silent Call to Bloom with Awareness |
| `proposito-o-sentido-que-nos-move-por-dentro` | `purpose-the-meaning-that-moves-us-from-within` | Purpose: The Meaning That Moves Us From Within |
| `quando-a-terra-desperta-a-primavera-e-o-novo-ano-astrologico-1` | `when-the-earth-awakens-spring-and-the-new-astrological-year` | When the Earth Awakens: Spring and the New Astrological Year |
| `quem-voce-se-tornou-longe-de-casa` | `who-have-you-become-away-from-home` | Who Have You Become Away From Home? |
| `sol-e-lua-equilibrando-consciencia-e-emocoesdurante-o-inverno-norte-europeu` | `sun-and-moon-balancing-awareness-and-emotions` | Sun and Moon: Balancing Awareness and Emotions Through the Northern European Winter |
| `trabalho-em-laoistrabalho-em-laois` | `work-in-laois` | Work in Laois |
| `turismo-e-lazer-em-co-laois-na-irlanda` | `tourism-and-leisure-in-co-laois-ireland` | Tourism and Leisure in Co. Laois, Ireland |

---

## 6. Phase 6/7/8 — Polylang relationships, categories, tags

**Post relationships.** Each EN post is the linked translation of exactly one PT
original, verified in both directions before the row is reported (`pll_get_post(pt,'en')`
and `pll_get_post(en,'pt')`). No EN post exists without a PT sibling and no PT post
gained a second identity: PT records keep their ID, dates, author, media and taxonomies.

**Categories (5/5).** Only terms actually used by public posts were translated; every
PT term keeps its slug and its name, and every EN term is linked through Polylang:

| PT term (slug) | PT name | posts | EN term (slug) | EN name | posts |
|---|---|---|---|---|---|
| `saude-e-bem-estar` | Saúde e Bem-estar | 24 | `health-and-wellbeing` | Health & Wellbeing | 24 |
| `empreendedor` | Empreendedor | 6 | `entrepreneurship` | Entrepreneurship | 6 |
| `receitas` | Receitas | 3 | `recipes` | Recipes | 3 |
| `capacitacao` | Capacitação | 2 | `training` | Training | 2 |
| `lazer` | Lazer | 1 | `leisure` | Leisure | 1 |

No suffixed duplicates were created (`technology-en`, `-2`, …): the EN terms carry
natural English slugs, the PT terms are untouched, and the counts mirror exactly.
Unused categories that exist in the database (Benefícios, Documentos, Educação, …,
all with `count = 0`) were deliberately **not** translated.

**Tags (0).** No published blog post uses a tag (the only tag in the database, `test`,
has `count = 0` and is not user-facing). Nothing was created, so the taxonomy stays
clutter-free — the audit reports `tags used by Blog posts = 0`, `tags translated = 0`.

---

## 7. Phase 9 — internal links

Two links inside the Blog point at the legacy Wix URL of another post in this corpus
(`https://tdcriativo.wixsite.com/...`). They are resolved through the **real Polylang
relationship** of the destination post, never by string replacement:

| EN post | source href (PT) | resolved EN destination |
|---|---|---|
| `what-every-small-business-owner-needs-to-learn` | `…/post/papo-de-empreendedor-…-13` | `/en/entrepreneur-talk-inspiring-future-entrepreneurs/` |
| `what-every-small-business-owner-needs-to-learn` | `…/post/gest%C3%A3o-financeira-…` | `/en/financial-management-the-invisible-foundation/` |

Implementation: `link_map` in the manifest (`from` → `to_pt_post_slug`) applied by
`conexao_blog_translation_localize_links()` **after** every EN sibling exists, using
`pll_get_post()` + `get_permalink()`. A rule whose destination has no published EN
translation is skipped and the valid existing destination is preserved — no fake
English URL is ever invented. All 34 external links (Instagram, job boards, training
providers, mental-health organisations) are byte-identical to the source; the visible
anchor text that literally spells an old URL was kept as the source wrote it.

---

## 8. Phase 10 — removing the Blog B2 fallback

The existing B2 infrastructure is **kept**: only the Blog's participation in it changed,
through the smallest possible change, with no new routing system and no one-off redirect.

| # | File | Change |
|---|---|---|
| 1 | `functions.php` | `conexao_b2_posts_page_is_en_request()` (the gate of the `posts_pre_query` substitution that served the PT post set on `/en/blog/`) now returns `false` as soon as the posts page has a **published, linked** EN translation. Installs without one keep the exact previous behaviour. |
| 2 | `inc/polylang.php` | `conexao_resolve_posts_page_request()` (`request`, 20) + `conexao_mark_posts_page_query()` (`pre_get_posts`, 1): resolve `/en/blog/` to the posts page **of the requested language** and give the query posts-archive semantics. Needed because the posts page path is shared by both languages and WordPress' page lookup is language-blind (it returned the PT page, which looked like a language mismatch and produced a canonical redirect to `/blog/`). Default-language requests are untouched by construction (a `lang` query var that differs from the default is required, and only a `page_id` equal to the current language's posts page is rewritten). |
| 3 | `inc/polylang.php` | Documentation of the two Blog states in `conexao_b2_page_allowlist()` (the `blog` entry stays — it is the B2 eligibility for the *untranslated* state; `conexao_should_render_b2_fallback()` already returns false once a published linked EN translation exists, so it becomes inert exactly when it should). |
| 4 | `inc/seo.php` | The posts page is now **self-canonical per language** (`is_home()` branch split from `is_front_page()`): `/blog/` → `/blog/`, `/en/blog/` → `/en/blog/`, instead of the site home. Required by Phase 15 and by sitemap/canonical agreement; a bare language home serving the posts index (no static front page) still canonicalises to the home URL. |
| 5 | `inc/seo.php` | The sitemap (`conexao_seo_sitemap()`) lists blog posts: one PT pass + one EN pass, exactly mirroring the existing CPT pattern (self-canonical entries, alternates only for real pairs, no fallback/redirect URLs, no duplicates). |

No redirect rule was added or removed, `conexao_lang_url()`, the nav layer, the archive
widen rules, the search-widen rules and every B2 destination for the CPTs
(event / leisure / sponsor / course_provider / job) behave exactly as before.

### Both Blog states verified

| State | `/en/blog/` | PT `/blog/` | Evidence |
|---|---|---|---|
| **Before** (no EN posts page — the state of any install that has not run this migration) | 200, `lang=en-US`, B2 notice, PT posts, canonical `/blog/`, pagination 1–4 | 200 pt-BR, self-canonical, 404 on page 5 | measured after the theme changes with the EN posts page unlinked |
| **After** (EN posts page published + EN posts, i.e. the real translation) | 200, `lang=en-US`, **no notice**, EN posts, canonical `/en/blog/`, hreflang pair, pagination 1–4 | unchanged (200 pt-BR, self-canonical, 404 on page 5) | `stage5-work/http-matrix.json` (60/60) |

---

## 9. Phases 11–18 — rendering, pagination, archives, search, SEO, sitemap, switcher, navigation

All rows below are machine-checked by `stage5-work/verify-blog-en.py`
(re-runnable against any local/staging base URL) and recorded in
`stage5-work/http-matrix.json` + `stage5-work/http-matrix-after.md`.

### Phase 11 — the English Blog archive

| Check | Result |
|---|---|
| `/en/blog/` HTTP status | **200** (no redirect to `/blog/`) |
| `<html lang>` | **en-US** |
| Archive heading/labels | English (`Articles & News`, `Blog`, `Categories`, `All`) |
| Posts rendered | English translations (e.g. *Who Have You Become Away From Home?*) |
| Portuguese posts appearing as primary EN content | **none** — the EN card set is disjoint from the PT card set |
| Canonical | `https://<site>/en/blog/` (self) |
| hreflang | `en` → `/en/blog/`, `pt-BR` → `/blog/`, `x-default` → `/blog/` |
| B2 fallback notice | **absent** |

### Phase 12 — pagination

36 posts / 10 per page → 4 pages in each language.

| URL | Status | Content | Canonical |
|---|---|---|---|
| `/blog/` … `/blog/page/4/` | 200 | PT posts (10/10/10/6) | `/blog/` |
| `/blog/page/5/` | **404** | — | — |
| `/en/blog/` … `/en/blog/page/4/` | 200 | **EN translations** (10/10/10/6), no PT titles | `/en/blog/` |
| `/en/blog/page/5/` | **404** | — | — |

Pagination never substitutes a Portuguese post for an existing English translation,
and no intermediate mixed-language state remains (all 36 posts are translated).

### Phase 13 — category archives

| Check | PT | EN |
|---|---|---|
| Archive URL | `/category/saude-e-bem-estar/` (200) | `/en/category/health-and-wellbeing/` (200) |
| Language | pt-BR | **en-US** |
| Posts | PT posts | **EN translations** |
| Canonical | PT term URL | EN term URL (language-correct) |
| hreflang | PT↔EN pair emitted from the linked terms | ✔ |
| B2 redirect | none (no fallback involved) | none |
| Blog filter bar | `/blog/?categoria=saude-e-bem-estar` → PT set | `/en/blog/?categoria=health-and-wellbeing` → EN set (different set from the unfiltered archive) |

The architecture rule that filter slugs are language-specific still holds: a PT slug
under `/en/blog/` returns **no** EN results (verified), and county/town filters stay
language-neutral (untouched by this stage).

### Phase 14 — search

| Check | Result |
|---|---|
| EN search (`/en/?s=cupcake`) | returns the **EN** translation of the recipe posts |
| EN search shows the PT title | **no** (`CUPCAKE DA GRÁNNY` absent) |
| PT search (`/?s=cupcake`) | still returns the PT original |

No change to the global search architecture: Polylang's existing language scoping plus
the theme's unchanged B2 search rules produce this behaviour.

### Phase 15 — SEO

| Check | Result |
|---|---|
| EN single canonical | the EN URL (self) |
| EN single hreflang | `en` self + `pt-BR` → PT post + `x-default` → PT post |
| `<html lang>`, `og:locale` | `en-US` / `en_US` on EN, `pt-BR` / `pt_BR` on PT |
| Accidental canonical → PT on EN pages | none |
| Accidental 302 EN → PT | none for archive or singles |
| Duplicate/conflicting canonical emitters | none (the theme remains the single owner; core `rel_canonical` is removed) |
| `noindex` introduced | none |
| EN archive canonical | `/en/blog/` (self) |
| PT archive canonical | `/blog/` (self, unchanged apart from the pre-existing posts-page bug fixed in §8/§16) |

### Phase 16 — sitemap

`/sitemap.xml` (theme sitemap owner) after the change:

| Check | Result |
|---|---|
| PT blog URLs present | ✔ (`/quem-voce-se-tornou-longe-de-casa/`, …) |
| EN blog URLs present | ✔ (`/en/who-have-you-become-away-from-home/`, …) |
| Posts pages present | ✔ `/blog/` and `/en/blog/` |
| Duplicate `<loc>` entries | 0 |
| Alternates | `hreflang` emitted only for real PT↔EN pairs |
| Fallback/redirect-only URLs listed | none |
| Canonical ≙ sitemap URL | ✔ (both lists are built from `get_permalink()` of the same records) |

No second sitemap system was introduced; the pass mirrors the existing CPT passes and
uses the same emitter (`conexao_seo_sitemap_url()`), so the ownership architecture is
preserved. (Production currently answers `/sitemap.xml` from Jetpack — a hosting-level
behaviour documented in §14; this change only concerns the theme sitemap.)

### Phase 17 — language switcher

| Context | PT row | EN row |
|---|---|---|
| `/blog/` | self (current) | `/en/blog/` |
| `/en/blog/` | `/blog/` | self (current) |
| PT single | self | `/en/<en-slug>/` |
| EN single | `/blog/<pt-slug>/` | self |

Published pairs resolve to their direct counterpart; the homepage fallback is never used
while a translation exists (all 36 have one).

### Phase 18 — header navigation

| Check | Result |
|---|---|
| EN pages: Blog destination | `/en/blog/` (no `/blog/` leak in the EN nav) |
| PT pages: Blog destination | `/blog/` |
| `/en/blog/` → 302 → `/blog/` | **not reintroduced** (HTTP 200 verified) |
| `menu-item-0` / duplicate Blog entry | none (the navigation fix is untouched: `conexao_posts_page_url()` already prefers a real linked translation, so the item resolves to the EN page with no code change) |

Note: `conexao_posts_page_url()` (Stage 4.5/3.x behaviour, kept) already resolved the
Blog item to a real linked translation when one exists — which is exactly why the
navigation needed no change in this stage.

---

## 10. Phase 19 — Portuguese content preserved (hard gate)

Independent verification (`stage5-work/verify-pt-untouched.py`, artifact
`stage5-work/pt-integrity.json`): every public Portuguese post in the target
WordPress was compared field by field with the production REST payload it came from.

| Field | Result |
|---|---|
| `post_title` | 36/36 identical |
| `post_content` (body markup) | 36/36 identical |
| `post_name` (slug) | 36/36 identical |
| `post_date` | 36/36 identical (REST ISO-8601 vs DB format normalised) |
| `post_status` | 36/36 `publish` |
| language assignment | 36/36 still `pt` |
| **Gate** | **PT ORIGINALS UNCHANGED** |

The importer enforces the same rule at run time: it snapshots every PT post (title,
body, excerpt, status, date, author, thumbnail, taxonomies, language, meta description)
before the run and re-checks it afterwards, reporting `PT sources changed (must be 0): 0`
and tripping an error row otherwise. The only Portuguese write it can ever perform is a
Polylang language **backfill** on a record that had no language at all (none was needed
here). PT featured images, taxonomy relationships, dates and authors are untouched.

---

## 11. Phase 20 — completeness audit

Deterministic inventory (`scripts/stage5-blog-inventory.php` →
`stage5-work/inventory-after.json`, also printed on the admin screen):

| Metric | Value |
|---|---|
| total public Blog posts (PT) | **36** |
| total public Blog posts (EN) | **36** |
| translated post pairs verified | **36** |
| **eligible public PT posts missing EN** | **0** |
| EN posts missing PT translation | **0** |
| posts excluded (documented) | **0** |
| categories used by Blog posts | 5 |
| categories translated (linked EN) | **5** |
| categories missing EN term | **0** |
| tags used by Blog posts | 0 |
| tags translated (linked EN) | 0 |
| Posts Page PT ID | 5 |
| Posts Page EN ID | 174 |
| posts page translation verified | **yes** |
| **GATE** | **PASS** |

---

## 12. Deliverables (files)

### New

| Path | Purpose |
|---|---|
| `wp-content/plugins/conexao-blog-translation/` | Admin importer + WP-CLI runner engine, completeness audit and the authored EN manifest (36 posts, 5 terms, EN posts page) |
| `scripts/run-blog-translation.php` | WP-CLI runner (`wp eval-file - dry-run json`) |
| `scripts/stage5-blog-inventory.php` | Deterministic inventory dump (audit + PT/EN records) |
| `wp-content/themes/conexao-br-irlanda/tests/test-blog-en-translation.php` | In-process Blog translation suite (27 assertions) |
| `stage5-work/` | Translation workflow + evidence: brief, per-post PT sources, per-post EN translations, manifest generator, validator, HTTP matrix, inventory, PT-integrity report |
| `docs/plugins/conexao-blog-translation.md` | Plugin documentation |
| `CONEXAO_BR_ENGLISH_BLOG_TRANSLATION_REPORT.md` | This report |

### Modified

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | Posts-page request resolution + query semantics (Stage 5), B2 doc/comment updates |
| `wp-content/themes/conexao-br-irlanda/functions.php` | B2 post-set substitution retired when a published linked EN posts page exists; comment updates |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | Self-canonical posts page per language; blog posts added to the theme sitemap |
| `wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context.php` | Blog assertion made state-aware (real EN archive **or** B2 fallback) |
| `wp-content/plugins/conexao-page-translation/conexao-page-translation.php` | **Pre-existing defect fixed**: the class had a missing `}` (and a stray trailing `}`), so the plugin could not even be loaded (`php -l` failed). 2 insertions / 1 deletion, no behaviour change |
| `AGENTS.md`, `docs/routing.md`, `docs/plugins/README.md`, `docs/project-inventory.md`, `docs/themes/conexao-br-irlanda.md` | Documentation of the new plugin, the Blog EN state and the new theme helpers |
| `.gitignore` | Ignores the sandbox-only `.local/` PHP runtime |

---

## 13. Phase 21 — regression testing

### Theme suite (in-process WordPress, sandbox clone)

| Suite | Before this stage | After this stage |
|---|---|---|
| `test-i18n-foundation.php` | 28 / 1 failed | 28 / 1 failed (identical, pre-existing) |
| `test-polylang-foundation.php` | 51 / 0 | 51 / 0 |
| `test-stage32-bilingual.php` | 23 / 19 (pilot data absent locally) | 26 / 16 (EN front page + terms present; remaining failures are absent event/leisure/sponsor pilot fixtures) |
| `test-stage33-bilingual.php` | 51 / 26 | 51 / 26 (identical) |
| `test-nav-language-context.php` | 13 / 1 / 1 skipped | 14 / 1 / 1 skipped (Blog assertion now state-aware; the remaining failure is the absent EN `Empregos` page from the Stage 4.5 layer) |
| `test-nav-language-context-logic.php` (no WP) | 49 / 0 | 49 / 0 |
| `test-nav-menu-regression.php` | 22 / 9 (no menus seeded locally) | 22 / 9 (identical) |
| **`test-blog-en-translation.php` (new)** | — | **27 / 0** |

No test regressed because of this stage. Two tests were intentionally updated to the new
approved behaviour: `test-nav-language-context.php` (Blog: real EN archive *or* B2
fallback, never a mismatch) — the same maintenance step the previous Blog navigation fix
documented.

### HTTP acceptance matrix

`stage5-work/verify-blog-en.py` → **60 passed, 0 failed** (recorded in
`stage5-work/http-matrix.json`).

### Static checks

| Check | Result |
|---|---|
| `php -l` over all repository PHP files | 0 parse errors introduced (one **pre-existing** parse error remains, §14) |
| `git diff --check` | clean (no whitespace errors) |
| `stage5-work/validate-en.py` (36 translations) | STRUCTURE/TAG/ATTRIBUTE/SLUG/LEAK checks: **36/36 pass** |

---

## 14. Findings (pre-existing issues found while working, none introduced here)

1. **`wp-content/plugins/conexao-page-translation/conexao-page-translation.php` did not
   parse** — `class Conexao_Page_Translation_Admin` was missing the closing brace of
   `handle_run()` (plus a stray `}` at EOF). `php -l` failed and activating the plugin
   was fatal. This blocked the Stage 4.5 page-layer importer entirely. **Fixed here**
   (2 insertions / 1 deletion) because the Blog posts page is part of that map.
2. **`wp-content/plugins/conexao-page-translation/includes/translation-map.php` still
   does not parse** (`unexpected token ";", expecting ")"` on line 291 — an unbalanced
   entry in the authored map). **Not fixed**: it belongs to the Stage 4.5 scope, it is
   unrelated to the Blog, and the Stage 5 importer does not depend on it (the EN posts
   page is created by `conexao-blog-translation`). It is reported here so the Stage 4.5
   rollout is not attempted on a broken artifact.
3. **Production answers `/sitemap.xml` from Jetpack** (sitemap index → `sitemap-1.xml`,
   which already lists the Portuguese blog posts and the posts page). The theme sitemap
   is the owner in the local/staging environment; this stage added the blog entries
   there and did not touch Jetpack or add a second sitemap system. If Jetpack remains the
   production owner, the EN blog URLs will appear in the Jetpack sitemap automatically
   once the EN posts are published (they are ordinary published posts tagged `lang=en`).
4. **Pre-existing quirk (unchanged):** a *draft* EN translation of a B2 record disables
   the B2 fallback for it (`conexao_should_render_b2_fallback()` checks for a
   translation, not for a published one). This affects the Blog only during the
   rollout window (EN posts page created but not published → the EN URL 302s to
   `/blog/`), exactly as it did before this stage for every B2 type. The importer
   publishes the posts page, so the steady state is not affected.
5. **Shared-slug posts page**: because `/blog/` and `/en/blog/` are the same path, the
   EN posts page reuses the `blog` post_name. WordPress' duplicate-slug fallback renamed
   it to `blog-2` on a re-run of the migration in the sandbox; the importer now keeps
   the shared-slug filter active for updates and repairs a drifted slug (verified by
   running the migration three times).

---

## 15. Production rollout (operator runbook)

Nothing below has been executed. The site content in this repository is additive: no
existing record is edited except by the importer, which only creates/updates the EN
records it owns.

1. **Back up**: UpdraftPlus database + uploads snapshot; export the current post list.
2. **Deploy the theme** (`dist/conexao-br-irlanda.zip`) — it contains the routing,
   canonical and sitemap changes; they are inert while no EN posts page exists (the B2
   fallback keeps working exactly as today).
3. **Deploy and activate** the `conexao-blog-translation` plugin
   (`dist/conexao-blog-translation.zip`).
4. **Dry run**: Tools → *EN Blog Translations* → **Preview (dry run)**. Expect
   `posts would-create 36`, `category terms created 5`.
5. **Apply**: **Apply**. Expect `created 36` (first run), `posts page: created`,
   `category terms linked 5`, `PT sources changed 0`, and the audit table ending in
   **GATE: PASS** (`eligible public PT posts missing EN = 0`).
6. **Verify** (production):
   - `/en/blog/` → 200, English UI + English posts, **no** Portuguese fallback notice,
     canonical `/en/blog/`;
   - `/en/blog/page/2/` … `/page/4/` → 200 English posts;
   - `/blog/` → unchanged Portuguese archive (canonical `/blog/`);
   - an EN single, e.g. `/en/who-have-you-become-away-from-home/` → 200, `lang=en-US`,
     canonical self, PT alternate in the switcher;
   - an EN category archive, e.g. `/en/category/health-and-wellbeing/`;
   - the EN header nav **Blog** item resolves to `/en/blog/` with no redirect.
7. **Deactivate** the plugin (it has no frontend role). The content stays.
8. **Rollback**: trash the EN posts + the EN posts page (or restore the DB backup).
   The theme automatically returns the Blog to the approved B2 fallback state because
   `conexao_b2_posts_page_is_en_request()` and `conexao_resolve_posts_page_request()`
   both re-engage as soon as the published EN posts page is gone. No live content is
   deleted by this stage's code, so no Portuguese content is at risk at any point.

---

## 16. Limitations / not executed

- **Production was not modified or verified live.** The environment here has no access
  to the production database and no WP-CLI on WordPress.com; every execution happened in
  the sandbox clone described in §0. The production rollout is the operator runbook above.
- **The local Docker environment could not be started** in this sandbox (no Docker daemon,
  no preinstalled PHP). The clone used the same WordPress + Polylang 3.8.9 versions and
  the real production Blog data, but it does not contain the rest of the pilot dataset
  (events, leisure, sponsors, courses, guides), which is why those unrelated test suites
  keep their pre-existing local failures (§13).
- **Menus were not seeded** in the clone, so the header-menu regression suite keeps its
  pre-existing failures (unrelated to this stage: the Blog item logic is asserted by the
  no-WP logic suite, 49/0, and the HTTP matrix).
- Images: the sandbox clone has no Media Library attachments, so the featured-image
  *inheritance* is asserted by ID equality and by the in-process suite rather than by an
  image HTTP request.

---

## 17. Acceptance statement

- The Blog is translated into **real English content** (36/36 posts, 5/5 categories,
  posts page) — **not** a B2 fallback and not a placeholder. ✔
- `/en/blog/` is a genuine English archive; `/blog/` is unchanged Portuguese. ✔
- The approved B2 architecture is preserved and still governs every other destination,
  and the Blog itself returns to B2 automatically wherever no EN posts page exists. ✔
- Portuguese originals are byte-identical; the English layer is purely additive. ✔
- No Flutter, REST, event, leisure, guide, job, sponsor or course artefact was touched. ✔
