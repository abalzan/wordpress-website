# English Stage 4.3 — production deployment runbook

**Scope:** WordPress website only. The Flutter app (`abalzan/conexaobr_app`) is frozen and is
not part of this runbook.

**Status of this document:** the *plan* produced by Stage 4.3 together with the measured
pre-deployment baseline of `https://conexaobr.ie`. Stage 4.3 could **not** execute the steps
below from its own environment (no wp-admin, no SSH/SFTP, no ZIP-upload path — see
`CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md` §24/§29). Every step is written to be run by a site
administrator with wp-admin access, in the stated order, verifying after each step.

---

## 0. Prerequisites (hard requirements)

| # | Requirement | Why |
|---|---|---|
| 0.1 | wp-admin access as an **administrator** (`manage_options`, `install_plugins`, `activate_plugins`, `edit_theme_options`) | Polylang install, Polylang settings, menus, reading settings |
| 0.2 | Ability to **upload a theme ZIP** (Appearance → Themes → Add New → Upload Theme) and plugin ZIPs | production is WordPress.com; there is no SSH/WP-CLI. This is the only code-deployment path |
| 0.3 | **UpdraftPlus 1.26.8** (already active on production) with enough storage for one files+database backup | DB checkpoint (Phase 22) before any content/settings change |
| 0.4 | A quiet window: `/en/` does not exist yet, and every legacy English URL currently 301s to Portuguese | a half-deployed English layer must never be left in place |
| 0.5 | The Stage 4.3 acceptance harness: `python3 scripts/stage43-verify-english.py` | objective pass/fail per URL class |

**Do not** install Polylang **Pro**. Polylang **Free 3.8.9** only (see §4).

---

## 1. Measured pre-deployment baseline (Stage 4.3, 2026-09-23)

Recorded read-only over HTTPS + authenticated REST (`GET` only). Reproduce with
`python3 scripts/stage43-verify-english.py --phase before --out /tmp/s43-before`
raw captures in `stage43-work/production-baseline/`.

| Surface | Measured production state |
|---|---|
| WordPress core | **7.1.2** (`/wp-login.php` asset `?ver=`); site language `WPLANG` empty → effective locale `en_US` |
| PHP | NOT_EXPOSED (WordPress.com does not send `X-Powered-By`) |
| Theme active | `conexao-br-irlanda` v1.0.0, **pre Stage-1 i18n build** (proof: PT homepage emits `<html lang="en-US">`; the repo theme's `inc/i18n.php` normalises the unconfigured locale to `pt_BR`) |
| Plugins | akismet 5.7.2, conexao-content 1.0.0, conexao-data-model 1.6.0, conexao-admin-ux 1.0.6, **conexao-event-importer 1.7.1 (active — should be local-only)**, conexao-event-runtime 1.2.1, conexao-leisure-migration 2.1.0, gravatar-enhanced 0.13.1, gutenberg 24.0.0, jetpack 16.3-a.3, page-optimize 0.6.3, updraftplus 1.26.8, wordpress-importer 0.9.6 (inactive: classic-editor, polldaddy, crowdsignal-forms, layout-grid) |
| **Polylang** | **not installed** (no `polylang` entry in `/wp-json/wp/v2/plugins`; `/wp-json/pll/v1/languages` → 404) |
| **Stage 4.1 REST module** | **not deployed**: `?lang=xx` returns **200** (must be 400), `?lang=en` returns the same totals as unfiltered, no `conexao_language` field in payloads |
| Counts (public REST) | pages 43 · posts 36 · media 9285 · guide 54 · event 1779 · course_provider 11 · job 1 · sponsor 10 · leisure 289 · categories 26 · tags 1 · conexao_category 56 · conexao_tag 0 · conexao_county 26 · conexao_town 251 |
| Front page / posts page | `show_on_front=page`, `page_on_front=**9**`, `page_for_posts=**10**` (never assume local IDs) |
| Timezone | `Europe/Dublin`; `start_of_week=1` |
| Nav menus | `primary` → menu **1381** “Menu Principal”; `footer` → menu **1382** “Menu Rodapé”; `social` → 0. **No English menu exists.** |
| Primary menu items (PT) | Home(1) `/`, Apoiadores(2), Guias(3), Eventos(4), Cursos(5), Empregos(6), Blog(7), Sobre Nós(8), Contato(9) — “Lazer e turismo” is injected at render time and is not a stored item |
| Footer menu items (PT) | Home(0) `/`, Sobre Nós(2), Política de Privacidade(3), Termos de Uso(4), Cookies(5), Anuncie(6), Contato(7), Newsletter(8) |
| Sitemap | `/sitemap.xml` = **Jetpack 16.3-a.3 index** (→ `/sitemap-1.xml`, `/image-sitemap-index-1.xml`); `/sitemap_index.xml` = **theme producer** (`application/xml`, 2006 `<loc>`, no hreflang alternates); `/robots.txt` declares `/sitemap.xml` + `/news-sitemap.xml` |
| `/en/` | **301 → `/eventos/encore-halloween-edition-special-guest-1926/`** — not the English homepage. Cause: `/en/` is a 404 and WordPress core `redirect_guess_404_permalink()` matches `post_name LIKE 'en%'` |
| `/en/home/` | 301 → `/eventos/home-of-halloween-storytelling-food-tour/` (same 404-guess) |
| `/inicio/` | 301 → `/` (existing PT consolidation — must stay) |
| EN static pages | `/en/about-us/`, `/en/contact/`, `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/` → **404** |
| EN archives | `/en/guias/`, `/en/eventos/`, `/en/apoiadores/`, `/en/cursos/` → **404**; `/en/lazer/` → 301 → `/lazer/` |
| B1-style EN URLs | `/en/blog/`, `/en/moradia/`, `/en/europa/` → **301** → PT (today: core 404-guess; after deployment: the theme's **302** B1 policy) |
| Legacy redirects | all 13 tested URLs 301 → correct PT destination, no loop (`/jobs/`, `/about-us/`, `/contact/`, `/guides/`, `/events/`, `/courses/`, `/sponsors/`, `/ireland/`, `/about/`, `/privacidade/`, `/termos/`, `/sobre/`, `/counties/dublin/`) |
| Edge cache | HTML 200s: `Cache-Control: max-age=300, must-revalidate`, `X-Ac: … STALE/HIT/MISS`; redirects: `no-store, private` |
| WAF/edge notes | authenticated REST GETs with a **browser User-Agent** → `403`; browser-looking uncached HTML requests from datacenter IPs → intermittent `429`. The harness sends a plain UA on purpose |

**Baseline conclusion (hard gate #2):** `/en/` does **not** serve an English homepage today and
**no part of the English layer is deployed**. Nothing in the current production state contradicts
the architecture; the English work simply has not shipped.

---

## 2. Change plan — exact order

| Step | Change | Reversible by | Gate before moving on |
|---|---|---|---|
| S0 | UpdraftPlus backup (files + DB) + record Activity Log entry | restore from the backup | backup downloaded, timestamp recorded |
| S1 | Deploy **theme ZIP** (`./scripts/build-theme-zip.sh` → `dist/conexao-br-irlanda.zip`) | re-upload the previous ZIP | PT homepage/archives/singles unchanged **except** `<html lang>` becoming `pt-BR`; legacy redirects still green |
| S2 | Install + activate **Polylang Free 3.8.9** | deactivate + delete the plugin | wizard completed (§4); PT URLs unchanged; no `/pt/` prefix appears |
| S3 | Create the **English front page** as a linked translation of the PT front page (slug `home`, title `Home`) | delete the EN page / unlink | `/en/` 200; `/en/home/` 301 → `/en/`; `/inicio/` 301 → `/` |
| S4 | Deploy **EN static pages** with the Stage 3.2 contract (§6) | delete the created EN pages | §6 verification table fully green |
| S5 | Establish **linked EN taxonomy terms** (§7) | delete the EN terms / unlink | representative filters (§7) green |
| S6 | **Content rollout** per explicit manifest (§8) | delete only the records in the manifest | §8 release checks green |
| S7 | **Menus** — EN primary + EN footer, translate the PT menus (§9) | delete the EN menus / re-assign PT | EN header + drawer show EN destinations |
| S8 | **hreflang policy** decision + (if needed) one-line patch (§10) | revert the patch | exactly one non-contradictory alternate set |
| S9 | **Meta-description gaps** for the records in §11 | edit the meta back | description present, language-appropriate |
| S10 | **Sitemap/Jetpack reconciliation** (§12) | re-enable Jetpack sitemaps | EN URLs present, B2/B1 absent, no duplicates |
| S11 | Full **acceptance run** (§14) + rollback record (§13) | — | 0 failed checks |

Never skip a gate. If a gate fails, stop, roll back the last step and report — do not “fix forward”
on production.

---

## 3. S0 — backup / checkpoint

1. wp-admin → **UpdraftPlus → Backup/Restore → Backup Now** with **both** “database” and “files”
   selected. Wait for both to finish.
2. Download both archive parts outside the host; record the exact timestamp.
3. Record in the deployment log: WP core 7.1.2, theme `conexao-br-irlanda` (previous build),
   plugin list/versions (§1), `page_on_front=9`, `page_for_posts=10`, menu IDs 1381/1382,
   Polylang *not installed*.
4. Save the current public output as a rollback reference:

```bash
mkdir -p /tmp/s43-pre && for u in / /guias/ /eventos/ /lazer/ /apoiadores/ /cursos/ /blog/ /sobre-nos/ /contato/ /politica-de-privacidade/; do
  curl -sS -A 'conexao-backup' -o "/tmp/s43-pre/$(echo "$u" | tr '/' '_').html" "https://conexaobr.ie$u"
done
sha256sum /tmp/s43-pre/*.html > /tmp/s43-pre/SHA256SUMS
```

---

## 4. S1 — theme ZIP, then S2 — Polylang Free 3.8.9

```bash
./scripts/build-theme-zip.sh          # → dist/conexao-br-irlanda.zip
```

wp-admin → **Appearance → Themes → Add New → Upload Theme** → choose the ZIP → **Replace current
with uploaded** for `conexao-br-irlanda` (do **not** activate a different theme).

**Why the theme goes first:** every English code path in the theme is guarded by
`conexao_polylang_active()` (`wp-content/themes/conexao-br-irlanda/inc/polylang.php:53`), so with
Polylang absent the new theme behaves like the old one; the only observable difference is the
Stage 1 i18n corrector (`inc/i18n.php:99`).

Verify immediately after S1 (record, do not assert):

```bash
python3 scripts/stage43-verify-english.py --phase before --group home,archives,legacy --out /tmp/s43-after-theme
```

Expected: `/` 200 self-canonical with **`lang="pt-BR"`** (was `en-US`); `/guias/ /eventos/ /lazer/`
200; all 13 legacy URLs 301 → PT; `/en/` still 301 (Polylang absent).

### S2 — Polylang Free 3.8.9

1. wp-admin → **Plugins → Add New Plugin** → search **Polylang** → **Install Now** → **Activate**.
2. Confirm **Plugins** lists `Polylang` **Version 3.8.9**, `status: active`. Do **not** install
   Polylang Pro; do not install any other multilingual plugin.
3. Run the **setup wizard** with exactly these values (the values
   `scripts/stage2-polylang-setup.php:99-106` asserts locally):

| Wizard step | Exact value |
|---|---|
| Languages | `Português` → locale **`pt_BR`**, slug **`pt`**, **default language** ✔ |
| | `English` → locale **`en_US`**, slug **`en`** |
| URL modifications | directory name in pretty URLs = **on**; hide URL language information for the default language = **on**; detect browser language = **off**; automatically redirect to preferred language = **off**; media → translate media = **off** (media shared) |
| Content | “Assign untranslated contents to the default language” = **yes** (`PLL_Model::set_language_in_mass()`, the same routine the local script uses) |

4. **Do not** use the “Custom post types” / “Custom taxonomies” screens: translated post types
   (`guide, event, leisure, sponsor, job, course_provider` + core `post`/`page`) and translated
   taxonomies (`conexao_category`, `conexao_tag`, `category`, `post_tag`) are declared **in code**
   (`inc/polylang.php:155-166` and `:199-210`) so local/staging/production cannot drift.
   `conexao_county` and `conexao_town` must stay **shared/untranslated** (Stage 3.2 policy — the
   `dublin-en` fix).
5. Verification:

```bash
python3 scripts/stage43-verify-english.py --phase before --group home,archives,filters --out /tmp/s43-after-pll
```

Expected: `/` 200 canonical `/`; `/guias/` 200; **no** URL gains a `/pt/` prefix; `/en/` still 301
until S3. Confirm the shared location terms were not duplicated:
`GET /wp-json/wp/v2/conexao_county?per_page=1` → `X-WP-Total: 26` and
`GET /wp-json/wp/v2/conexao_town?per_page=1` → `X-WP-Total: 251` (no `dublin-en`-style terms).

---

## 5. S3 — English front page at `/en/`

The mechanism is **configuration through Polylang's own API**, not a redirect or rewrite:

* Polylang derives each language's `page_on_front` from the translation set of the site's
  `page_on_front` option (`PLL_Static_Pages`). The English record is the **linked translation** of
  the Portuguese front page (`page_on_front = 9` on production).
* `inc/polylang.php:250-262` (`pll_additional_language_data`) declares each language's home URL as
  the **directory home** (`/en/`), so Polylang's own canonical redirect consolidates
  `/en/home/` → `/en/` instead of the reverse.
* `inc/polylang.php:302-326` self-heals the cached language list at theme load.

Steps in wp-admin:

1. **Pages → All Pages → filter “Português”**, find the front page (ID **9**; it is the page
   assigned to “Front page” in Settings → Reading).
2. In the Polylang language box, click **“+”** to add the **English** translation.
3. Set: title `Home`, slug **`home`**, status **Published**, same page template as the PT front
   page, and publish. Do **not** create a second EN homepage and do **not** make it a separate
   “Homepage” in Settings → Reading (Polylang maps it automatically from the PT `page_on_front`).
4. **Do not translate the posts page** (`page_for_posts = 10`) in this stage. Blog is approved as
   **B1**: `/en/blog/` must answer **302 → `/blog/`** (never a second EN posts page). Creating an EN
   Blog page would silently convert an approved B1 destination into real content and is a product
   decision outside this stage’s scope (hard gate #4).

Verification (this is hard gate #2 — stop if it fails):

```bash
python3 scripts/stage43-verify-english.py --phase before --group home --out /tmp/s43-after-frontpage
```

Expected: `/en/` **200** (not a redirect), canonical `https://conexaobr.ie/en/`, `lang="en-US"`,
hreflang `en`, `pt-BR`, `x-default`; `/en/home/` **301 → `/en/`**; `/inicio/` **301 → `/`**;
`/` unchanged 200 self-canonical; no redirect loop (≤ 1 hop).

---

## 6. S4 — English static pages (the approved 7)

| EN page (slug) | PT source slug | PT source role |
|---|---|---|
| `home` | `inicio` | static front page (`page_on_front`) — created in S3 |
| `about-us` | `sobre-nos` | About Us |
| `contact` | `contato` | Contact |
| `jobs` | `empregos` | Jobs (page-backed section) |
| `privacy-policy` | `politica-de-privacidade` | Privacy Policy |
| `terms-of-use` | `termos-de-uso` | Terms of Use |
| `cookie-policy` | `cookies` | Cookie Policy |

Contract (Stage 3.2, `scripts/stage32-translate-pages.php`):

* Each EN page is created **as the Polylang translation of its PT source** (Language box → “+” →
  English). Never create an EN page and link it afterwards by hand-editing IDs.
* Content is **human-authored English**: the same block structure and the same images/attachments
  as the PT source (media is shared), translated copy — **no machine translation**.
* The EN slug is the English slug in the table above. The PT slug is never touched.
* Author EN SEO metadata at the same time (title/description) — see §11.
* Do not translate any page that is not in this table.

Per-page release checks (all seven, manual + automated):

| Check | Expected |
|---|---|
| Polylang link | EN page shows the PT source as its translation and vice-versa |
| EN slug | exactly the slug in the table |
| PT source | unchanged (same ID, slug, status, content hash) |
| canonical | self (`https://conexaobr.ie/en/<slug>/`) |
| hreflang | `en` → EN URL, `pt-BR` → PT URL, `x-default` → PT URL |
| language switcher | present in header + mobile drawer, points back to the PT page |
| indexability | indexable (no `noindex`; not in the `noindex` set) |
| internal links | EN links resolve to EN targets (or approved B1 PT targets) |

```bash
python3 scripts/stage43-verify-english.py --phase after --group en_pages --out /tmp/s43-en-pages
```

---

## 7. S5 — linked English taxonomy terms (do this BEFORE EN content that needs filters)

Order matters: create/pair the **terms first**, then attach them to EN records — that ordering is
what prevents Polylang Free ≥3.5 from auto-creating a suffixed duplicate term.

1. For each vocabulary term that EN content will use, edit the **PT term** in
   `conexao_category` / `conexao_tag` (and core `category` / `post_tag`) and add its **English
   translation**. The Stage 3.2 pilot map (`scripts/stage32-translate-terms.php:51-70`, human
   authored) is the approved starting set — PT slug → (EN name, EN slug):

   | Taxonomy | PT slug → EN name / EN slug |
   |---|---|
   | `conexao_category` | `documentos` → Documents/`documents` · `trabalho` → Work/`work` · `financas` → Finances/`finances` · `festivais` → Festivals/`festivals` · `musica` → Music/`music` · `cultura` → Culture/`culture` · `natureza` → Nature/`nature` · `cidades` → Cities/`cities` · `negocios` → Businesses/`businesses` · `gastronomia` → Gastronomy/`gastronomy` · `empregos` → Jobs/`jobs` |
   | `category` | `comunidade` → Community/`community` |

   Rules: the PT slug is never changed; the EN slug is a natural English slug (never `-en`
   suffixed); if the script reports `EN slug already exists … refusing to collide`, stop and resolve
   the clash by hand.
2. **Never** create a location term in a language: `conexao_county` and `conexao_town` are shared
   proper nouns with **one** term per county/town. Verify after every term edit:

```bash
curl -sS -A 'conexao-check' -u "$WP_USERNAME:$WP_APPLICATION_PASSWORD" \
  'https://conexaobr.ie/wp-json/wp/v2/conexao_county?per_page=100&_fields=slug' | python3 -c \
  'import json,sys; s=[t["slug"] for t in json.load(sys.stdin)]; print(len(s)); print([x for x in s if x.endswith("-en")])'
```

Expected: `26` terms and an **empty** `-en` list. Repeat for `conexao_town` → `251`, empty `-en`
list. Any suffixed term is hard gate #10/#11 — stop and remove it.

3. Representative filter checks:

| Filter URL | Expected |
|---|---|
| `/en/guias/?categoria=documents` | 200, EN records with the EN term |
| `/guias/?categoria=documentos` | 200, PT records (unchanged) |
| `/en/lazer/?county=dublin` | 200, English view of the shared county term |
| `/lazer/?county=dublin` | 200, same records in the PT view |
| dropdowns | no duplicate options for the same county/town in either language |

```bash
python3 scripts/stage43-verify-english.py --phase after --group filters --out /tmp/s43-filters
```

---

## 8. S6 — English content rollout (explicit manifest, never a blanket migration)

Pilot classes approved for Stage 3.x English content: **Guides, Blog, Events, Lazer, Sponsors,
Course providers, Jobs**. The Stage 3.2 contract applies to every record:

* the EN record is a **linked translation** of the PT master (`pll_get_post( $pt_id, 'en' )` returns
  it) — never a new identity;
* **Events**: the EN translation keeps the identity meta of the PT master
  (`_event_source`, `_event_source_id`, `_event_export_uuid`, `_event_source_language`) — it must
  **not** be an importer target and must not create a second export identity row. Only the EN
  *language* attribute (`pll` language term) differs;
* **Lazer**: one `_leisure_export_uuid` per identity — the EN translation reuses the UUID of the PT
  master (never a new UUID);
* media: reuse the PT attachments (media is shared, `media_support=false`);
* taxonomy: attach the **EN term** of the linked pair for translated taxonomies; attach the **same
  shared term** for county/town;
* SEO: author EN title + meta description (§11);
* no automatic/machine translation; and **do not migrate a record that is not in the manifest**.

### Production manifest (fill in before starting, one row per record)

| # | PT ID | PT type | PT slug | EN translation ID | EN slug | EN author | EN meta desc | EN taxonomy | Linked ✔ | Archive shows EN ✔ |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | | | | | | | | | | |

Rules for the manifest:

1. **Production IDs are not local IDs.** Resolve every record on production by **slug** (or by
   source/source_id for events) and paste the production IDs into the manifest.
2. Start with the same record classes as the local pilot (7 pages + a small set of guides, blog
   posts, events, Lazer, sponsors, courses, jobs) — the local pilot list is the approved shape, its
   *IDs* are not portable.
3. Events: prefer records whose PT source is stable. Never create an EN record for an event whose
   `_event_status` is not `published` (the EN view of a hidden event must not exist).

Per-record release checks:

| Check | Expected |
|---|---|
| PT master | unchanged (ID, slug, status, identity meta byte-identical) |
| EN translation | linked, published, appears in the EN archive |
| EN master replacement | the PT master no longer appears **in addition** to the EN record in `/en/<archive>/` |
| duplicates | the record appears exactly once per language view |
| search | `/en/?s=<term>` returns the EN record, not the PT master |
| canonical / hreflang | EN record: self-canonical + `en`/`pt-BR`/`x-default` |
| language switcher | EN record ↔ PT master |

```bash
python3 scripts/stage43-verify-english.py --phase after --group archives,search --out /tmp/s43-rollout
```

### Event & Lazer hard gates (run before AND after the rollout)

```bash
# one row per Event identity; no duplicates
curl -sS -A 'conexao-check' -u "$WP_USERNAME:$WP_APPLICATION_PASSWORD" \
 'https://conexaobr.ie/wp-json/wp/v2/event?lang=pt&per_page=100&_fields=id,slug,meta' > /tmp/s43-events-pt.json
```

Expected on production: for every Event identity exactly one row per language, `_event_status`
unchanged, `_event_export_uuid` unique, and no PT master duplicated by its EN translation. Hidden
events (`expired`, `rejected`, `source_not_found`, or any non-`published` status) must be:

* absent from `/eventos/` and `/en/eventos/`,
* absent from search (`/?s=` and `/en/?s=`),
* `404` on REST detail (`/wp-json/wp/v2/event/<id>`) in both languages,
* unreachable from navigation.

Lazer: `_leisure_export_uuid` unique across `/wp-json/wp/v2/leisure?lang=pt|en`; EN collections show
the EN translation **instead of** the PT master; externally classified Lazer records keep their
existing external destination (no destination was changed for language support).

---

## 9. S7 — production menus (English primary + English footer)

Measured baseline: `primary` = menu **1381** “Menu Principal”, `footer` = menu **1382**
“Menu Rodapé”, `social` = none. There is **no** English menu; when Polylang is active and the
`primary` location has no menu for the current language, Polylang nullifies the location and the
theme’s safe fallback renders **no** items (`functions.php:3634`). A missing EN menu therefore
breaks the EN header — it must be created as **editorial data**, never with a navigation hack.

Steps:

1. **Appearance → Menus** → create **“Menu Principal (EN)”** and set its Polylang language to
   **English**. Add, in this order (labels are the EN UI strings):

   | # | Label | Destination |
   |---|---|---|
   | 1 | Home | EN home (`/en/`, language-home link) |
   | 2 | Sponsors | `/en/apoiadores/` |
   | 3 | Guides | `/en/guias/` |
   | 4 | Events | `/en/eventos/` |
   | 5 | Courses | `/en/cursos/` |
   | 6 | Tourism & Leisure | `/en/lazer/` |
   | 7 | Jobs | `/en/jobs/` (EN Jobs page — the theme resolves the section at render time) |
   | 8 | Blog | `/blog/` (approved B1) |
   | 9 | Contact | `/en/contact/` |

   Use **page/archive items where a real EN destination exists**; do not hand-type `/en/` URLs that
   the theme resolves itself, and never point an EN item at a PT URL unless it is an approved B1
   destination (Blog, Anuncie).
2. Create **“Menu Rodapé (EN)”** (English) with: Home, About Us, Privacy Policy, Terms of Use,
   Cookies, Contact — mirroring the PT footer items 1382 (Sobre Nós, Política de Privacidade,
   Termos de Uso, Cookies, Contato). `Anuncie` / `Newsletter` stay PT (approved B1).
3. In **Polylang → Languages → Settings**, assign the language of each menu (or translate the PT
   menus into EN from the Menus screen), then assign the locations:

   | Location | PT menu | EN menu |
   |---|---|---|
   | `primary` | 1381 Menu Principal | Menu Principal (EN) |
   | `footer` | 1382 Menu Rodapé | Menu Rodapé (EN) |

4. Do **not** change the PT menu structure.

Verification (desktop + mobile drawer are the same `primary` location, `header.php:103` and
`:213`):

* `/` still renders the 9 PT items with PT destinations;
* `/en/` renders the EN items with EN destinations — **no** PT `/empregos/`, `/guias/`, `/eventos/`
  links in the EN header;
* `/en/apoiadores/` etc. appear as `/en/apoiadores/`;
* the language switcher appears in the desktop header actions and in the mobile drawer;
* ordering matches the table; no page-list fallback (a page-list fallback is a hard failure — see
  `CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md`).

```bash
python3 scripts/stage43-verify-english.py --phase before --group home --out /tmp/s43-menus
# then inspect the captured HTML for the EN menu hrefs:
grep -o 'href="/en/[a-z-]*/"' /tmp/s43-menus-raw/home_en.body | sort -u
```

---

## 10. S8 — hreflang: one documented policy

**Measured code facts** (Polylang Free 3.8.9 source, `src/frontend/frontend-filters-links.php`):

| Producer | Output |
|---|---|
| Theme (`inc/seo.php:242-252`, `wp_head` priority 5, data from `inc/polylang.php:1504`) | real EN pages/posts: `en`, `pt-BR`, `x-default`; B2: **`x-default` only**; untranslated PT single: `x-default` only; B1: nothing (never renders) |
| Polylang (`wp_head`, `frontend-filters-links.php:199-252`) | only when **more than one** language URL resolves for the request: one `<link rel="alternate">` per language with the **country code stripped** (`pt`, `en`), and **no `x-default`** (the `x-default` branch requires `hide_default = false`, which our configuration sets to `true`) |

So the two sets are **not byte-identical**: on a real EN page the theme emits `en`, `pt-BR`,
`x-default` while Polylang additionally emits `en`, `pt` (same destinations, redundant `pt` alias,
different granularity). The architecture decision §14 requires a **single owner** (“the theme’s
`inc/seo.php` is the sole owner of SEO output; Polylang is configured to defer
canonical/hreflang/sitemap output”).

**Policy for production: B — the theme remains the sole hreflang producer; Polylang’s redundant
emitter is suppressed.** The suppression is a one-line, filter-based change using Polylang’s own
documented filter (`pll_rel_hreflang_attributes`, applied at
`frontend-filters-links.php:246`):

```php
add_filter( 'pll_rel_hreflang_attributes', '__return_empty_array' );
```

Placed once in `inc/polylang.php` (next to the other Polylang bridges). It changes **no** URL and
**no** ownership: the theme set is unchanged.

> Stage 4.3 did **not** apply this one-liner, because this environment has no WordPress runtime in
> which it could be validated (see the Stage 4.3 report §24/§29). Until the patch ships, /policy
> A/ applies **only if** the rendered sets are verified to agree on destinations with no
> contradictory `x-default` — otherwise stop (hard gate #13) and deploy the one-liner first.

Inspect the rendered output of the deployed site before and after:

```bash
for u in / /en/ /en/about-us/ /en/irlanda/ /guias/; do
  echo "== $u"; curl -sS -A 'conexao-check' "https://conexaobr.ie$u" | grep -Eo '<link rel="alternate"[^>]*>';
done
```

Expected with policy B: exactly the theme’s set per page — real EN page: `en`, `pt-BR`,
`x-default`; B2 page: `x-default` only; PT single without translation: `x-default` only; B1 URL:
no output (it redirects). No alternate may point at a URL that redirects.

---

## 11. S9 — meta descriptions (the three known gaps + the EN pages)

Stage 3.3 §8 identified **three EN records without a meta description**, because their Portuguese
sources have none either (`conexao_meta_description` empty on the PT records, and the imported event
has none). Source-inherited event **#108**, sponsor **#138**, course provider **#139** — those are
**local** IDs: locate the production equivalents **by slug/source**, never by ID.

Action (only these records, no bulk generation):

1. Open the **Portuguese** source record; author a real PT meta description
   (`conexao_meta_description`).
2. Open the linked **English** record; author a genuine EN meta description (never a copy of the PT
   one, never a placeholder).
3. Verify the rendered `<meta name="description">` on the PT URL (PT copy) and on the EN URL (EN
   copy) — see `conexao_seo_meta_description()` / `inc/seo.php:114-177`.
4. While the seven EN static pages are authored, give each of them an EN description too (`home`,
   `about-us`, `contact`, `jobs`, `privacy-policy`, `terms-of-use`, `cookie-policy`).

The site tagline (`Settings → General → Tagline`) is currently **empty** on production; if the SEO
layer falls back to it anywhere, set a short tagline (optional, PT/EN aware via the translation
catalogue).

---

## 12. S10 — sitemap / Jetpack reconciliation

**Measured production state:** `/sitemap.xml` is owned by **Jetpack 16.3-a.3** (index →
`/sitemap-1.xml`, `/image-sitemap-index-1.xml`); the theme’s own producer answers at
`/sitemap_index.xml` (`application/xml`, 2006 `<loc>`, no alternates today); `/robots.txt` declares
`/sitemap.xml` and `/news-sitemap.xml` (Jetpack’s), not the theme’s.

Decision §15 requires a **single owner: the theme’s custom sitemap** (it is the EN-aware producer).
The smallest controlled change is therefore:

1. First **measure** what the deployed + Polylang-active site produces at both paths:
   ```bash
   python3 scripts/stage43-verify-english.py --phase after --group sitemap --out /tmp/s43-sitemap
   ```
2. If Jetpack’s `/sitemap.xml` already matches the approved architecture (real EN URLs present, B2
   fallback URLs absent, B1 redirect-only URLs absent, PT URLs once, no duplicates, no redirecting
   URLs, no duplicate hreflang alternates) → **keep the current configuration** (policy: Jetpack
   owns, verified green) and stop.
3. Otherwise disable **only** the Jetpack sitemap module (wp-admin → **Jetpack → Settings →
   Performance → Sitemap: off**; do not deactivate Jetpack, do not disable other Jetpack features) so
   the theme producer answers at `/sitemap.xml`, then re-run step 1.
4. Verify `/robots.txt` still points at the serving sitemap (the theme’s
   `conexao_seo_robots_txt()` emits `Sitemap: https://conexaobr.ie/sitemap.xml`, `inc/seo.php:1140`)
   and record what happened to the image sitemap.

Sitemap acceptance list (same list for either producer): `/` and `/en/` present once · real EN URLs
present · B2 URLs (`/en/irlanda/`, `/en/dublin/`, … the ten allowlisted slugs) **absent** · B1
redirect-only URLs absent · PT URLs present once · no duplicates · no URL that redirects · no stale
`conexaobrirlanda.com` host · no duplicate hreflang alternates inside an entry.

---

## 13. S11 — cache, invalidation and rollback

**Measured cache behaviour:** the WordPress.com edge sets `Cache-Control: max-age=300,
must-revalidate` on HTML with `X-Ac: … STALE/HIT`; redirects are `no-store, private`. The theme’s
transients are language-scoped (`conexao_lang_cache_key()` / `conexao_flush_language_cache()`,
`inc/polylang.php:440-505`) and are invalidated on save/trash of translated content, so:

1. warm `/` and `/en/`; confirm neither page renders the other language’s content;
2. create/update a translation → reload `/en/` → the EN record appears (transient refreshed);
3. trash **one reversible test translation** (never live content) → `/en/<that URL>` falls back per
   policy (B2 render for a B2 type, 302 for B1) → restore → the EN record returns;
4. after the rollout, flush the WordPress.com edge cache once and re-run the acceptance run.

**Rollback (ordered, explicit):**

1. Revert or disable only newly deployed code if it is at fault: re-upload the previous theme ZIP, or
   deactivate Polylang (PT single-language behaviour returns — every theme English path is guarded by
   `conexao_polylang_active()`).
2. Delete **only** the EN records created in the manifest (they are translations, so the PT masters
   survive) and/or unlink them from their PT sources.
3. Restore menu/configuration changes: re-assign `primary` → 1381, `footer` → 1382; revert any
   Polylang wizard value that was altered.
4. Restore cache state: flush the edge cache and the `conexao_home_*` / `conexao_404_*` transients.
5. Re-verify PT behaviour (`--group home,archives,legacy`).
6. Re-run the production matrix (§14).

A full UpdraftPlus restore is the **last** resort — only for an incident that requires it, and only
from the S0 checkpoint verified to be complete.

---

## 14. Acceptance run (Phase 19/21 gates)

```bash
python3 scripts/stage43-verify-english.py --phase after --out /tmp/s43-after --include-rest
```

Exit code `0` = every check green; non-zero = at least one gate failing (do **not** declare the
stage complete). The run covers `/`, `/en/`, the seven EN pages, B1 URLs, the B2 pages, the five EN
archives, EN/PT search, translated + shared filters, the 13 legacy redirects, `/sitemap.xml` and
`/robots.txt`, and the REST contract (`lang=pt|en`, invalid language → 400, `conexao_language`,
translated + shared taxonomies). Raw captures land in `/tmp/s43-after-raw/`.

Hard gates (stop and classify BLOCKED): `CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md` §21. The gates this
runbook can trip directly are #2 (`/en/` must be 200), #4 (B1 must stay 302), #10/#11 (county/town
duplicates), #13 (canonical/hreflang contradiction) and #14 (B2 URLs in the sitemap).

---

## 15. Production hygiene items found by Stage 4.3

| Item | Measured | Recommended action (separately approved) |
|---|---|---|
| `conexao-event-importer` 1.7.1 is **active** on production | plugin list | deactivate it: `docs/deployment.md` and `docs/plugins/conexao-event-importer.md` define it as local-only tooling, and an active importer is a second write path to Event identity data (Phase 6 risk) |
| Site tagline empty; `WPLANG` empty → PT pages render `lang="en-US"` | `/wp-json/wp/v2/settings`, PT homepage HTML | fixed by the theme deploy (i18n corrector) and by Polylang activation (per-language locale); optionally set the site language to `pt_BR` in Settings → General |
| A commit-visible production **Application Password** exists in `scripts/ivvcc-import-robust.py:20` | source review | rotate that password in wp-admin (Users → Profile → Application Passwords) and remove the hard-coded fallback; do not commit credentials |
| `.htaccess` is **not** executed by production (WordPress.com serves via nginx) | measured: legacy redirects are emitted by PHP (`x-redirect-by: WordPress`), which is `conexao_seo_redirects()` | keep `.htaccess` as portability/defence-in-depth only; never treat it as the production redirect engine (Phase 15 is NOT_TESTABLE on production) |
