# Stage 7.x — Leisure description inventory (Phase 0 measurement)

- **Captured:** 2026-09-24 (UTC), read-only public REST of `https://conexaobr.ie` (`/wp-json/wp/v2/leisure`, pages 1–3, `x-wp-total: 289`) — raw payloads in `stage7-work/source/leisure-page-{1,2,3}.json`
- **Population:** **289 leisure records** — the complete Leisure archive population, no sampling, no estimates.
- **Render path under test:** `template-parts/leisure-card.php:154` → `get_the_excerpt()` → `wp_trim_words(…, 18, '…')` → `<p class="leisure-card-excerpt">` — source field: **`post_excerpt`** (admin alias «Descrição curta» / `_leisure_short_description`, a virtual field that edits `post_excerpt`).

## Summary

| Metric | Value |
|---|---|
| Total Leisure records | 289 |
| Records with a card excerpt (`post_excerpt`) | 289 |
| Excerpt language | **PT: 289 / 289** |
| Content language | PT: 288/289; 1 “tie” = `gap-of-dunloe` (PT sentence with English proper nouns: “O Gap of Dunloe é um desfiladeiro glaciar entre as montanhas MacGillycuddy’s Reeks e Purple Mountain…” — Portuguese) |
| Practical-notes language (`_leisure_practical_notes`) | PT: 56/56 records that have it (233 absent) |
| `advanced_seo_description` non-empty | 0 / 289 |
| `jetpack_seo_html_title` / `footnotes` non-empty | 0 / 289 |
| **Records with an original English description in the data** | **0 / 289** |
| Records linking an upstream English page (OPW/heritageireland.ie, Discover Ireland — URL only, no prose) | 242 / 289 |

## Finding

**No original English description exists anywhere in the Leisure data.**

- Every one of the 289 card excerpts (`post_excerpt` — the exact field rendered into `.leisure-card-excerpt`) is Portuguese.
- Every full description (`post_content`) is Portuguese.
- Every practical-notes value is Portuguese.
- The complete `_leisure_*` field inventory (code + REST meta census across all 289 records) contains **no English-description field**. The only English-flagged values anywhere in Leisure meta are URLs, postal addresses and proper-noun image alt texts of the form «X, County, Irlanda» — none is a description.
- The SEO description field (`advanced_seo_description`) is empty on all 289 records.
- The Leisure descriptions were **authored in Portuguese by the project from day one** — verified in every authoring artifact in the repository: `scripts/seed-leisure-locations.php` + `scripts/data/leisure-expansion-data-1/2/3.php` author **245 unique slugs, and production contains exactly those 245** (REST publication dates: 55 on 2026-08-19, 178 on 2026-08-21, 12 on 2026-09-10 = the Stage C batch), plus a **44-record OPW/Heritage Ireland batch dated 2026-09-15** with no authoring artifact in this repository — those 44 were measured directly in production (all PT). 0 English entries in any authored excerpt/content pair.
- The importer datasets (`docs/importers/lazer-expansion-stage-b-dataset.json`, `-c-final-dataset.json`) carry `description` / `practical_notes` values in Portuguese only — no English prose was ever captured, by design: the project’s own Stage A audit rules state that **upstream source descriptions/prose are copyrighted content that must not be copied** (“Existing records keep their own authored Portuguese excerpts/posts”).
- The original Wix migration inventory (`content-inventory/lazer-existing-inventory.csv`) has no description column at all — the Wix Lazer pages carried no descriptions.
- Upstream English pages (Heritage Ireland/OPW, Discover Ireland) are stored as **URLs only** (`_leisure_official_website`: 203 records, `_leisure_discover_ireland`: 89). Their English prose was never part of the Leisure data model, and copying it now would both invent content the project never had and violate the project’s own copyright rule.

## Evidence table (representative records — full list in the JSON)

| Record | Current card excerpt | Source field | Source language | Candidate EN value in data |
|---|---|---|---|---|
| `dwyer-mcallister-cottage` | “Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje pequeno museu de época.” | `post_excerpt` | Portuguese | none |
| `fore-abbey` | “Ruínas do mosteiro fundado por São Feichin no século VII, num vale tranquilo do condado de Westmeath…” | `post_excerpt` | Portuguese | none |
| `the-main-guard` | “Antigo tribunal do século XVII no centro de Clonmel, com arcada de colunas de arenito restaurada…” | `post_excerpt` | Portuguese | none |
| `altamont-gardens` | “Jardins românticos do século XVIII com lago, arboreto e o ‘corredor de azaleias’ — entrada gratuita.” | `post_excerpt` | Portuguese | none |
| `gap-of-dunloe` | “Desfiladeiro glaciar entre montanhas, percorrido por carruagens e trilhas.” | `post_excerpt` | Portuguese | none |
| `queen-maeves-trail-knocknarea` | (Stage C batch — authored PT in `leisure-expansion-data-3.php`) | `post_excerpt` | Portuguese | none |

All 289 rows carry the same result — see `leisure-description-inventory.json` (`items[]`, per-record `excerpt_language` / `content_language` / `original_english_description_in_data: false`).

## Where each text-bearing field actually is

| Field (admin label) | Storage | Language measured |
|---|---|---|
| Card excerpt — «Descrição curta» (`_leisure_short_description`) | `post_excerpt` (virtual alias, no separate meta) | PT (289/289) |
| Full description — «Descrição completa» (`_leisure_description`) | `post_content` (virtual alias) | PT (289/289) |
| Practical notes | `_leisure_practical_notes` | PT (56/56 present) |
| SEO description | `advanced_seo_description` | empty (289/289) |
| Image alt text | `_leisure_image_alt_text` | PT-formatted («X, County, Irlanda»); English-flagged values are proper-noun alt texts, not descriptions |
| Upstream links | `_leisure_official_website` / `_leisure_discover_ireland` | URLs only — no prose stored |
| Identity | `_leisure_export_uuid` (exporter `UUID_META_KEY`) | identity meta, no text, untouched by this stage |
