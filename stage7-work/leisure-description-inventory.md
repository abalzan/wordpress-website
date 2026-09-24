# Stage 7 — Leisure card-description inventory (dynamic, measured)

Generated from the production read-only captures in `stage7-work/source/`
(REST `wp/v2/leisure` — all 289 published records — plus the rendered `/lazer/`
HTML, all 29 archive pages, captured 2026-09-24). Machine-readable form:
`leisure-description-inventory.json`.

## 1. Population

| Metric | Value |
|---|---|
| rest_records | 289 |
| unique_slugs | 289 |
| html_cards_total | 289 |
| html_pages | 29 |
| pipeline_matches | 289 |
| pipeline_mismatches | 0 |
| cards_without_rest | 0 |
| rest_without_card | 0 |
| duplicate_cards | 0 |
| excerpts_truncated | 82 |
| excerpts_not_truncated | 207 |
| empty_descriptions | 0 |
| rest_texturize_undone | 15 |
| card_order_date_desc | yes |

## 2. Source of the card description (Phase 1 trace, measured)

- `template-parts/leisure-card.php` line 154 renders
  `esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) )`.
- Every one of the 289 published records carries a **non-empty manual
  `post_excerpt`** (REST `excerpt.rendered`; zero empty descriptions) —
  `get_the_excerpt()` therefore always returns the manual excerpt, never the
  content-derived fallback.
- The 18-word trim is applied **inside the template**, on the plain-text
  excerpt; 82 of 289 excerpts exceed 18 words and are
  truncated with the literal `...` suffix; 207 fit within 18 words.
- The pipeline replication (raw excerpt → 18-word trim) matches the captured
  production card HTML for **289/289 cards**
  (mismatches: 0).

## 3. Language / identity state

- All records are Portuguese (`pt`) with **no EN translation** (production has
  not yet received the Stage 4.3 English layer; `/en/lazer/` currently 301s to
  `/lazer/` there). On any install with the EN layer active, `/en/lazer/` renders
  these same PT records as the approved B2 fallback (PT card descriptions under
  the EN shell).
- `_leisure_uuid` / `_leisure_export_uuid` exist per the leisure migration
  architecture but are **not REST-exposed** (not in `meta`); this stage never
  reads or writes them — identity fields are untouched by design.

## 4. Sample (first 5, card order)

| # | ID | slug | card excerpt (PT, as rendered) |
|---|---|---|---|
| 1 | 21197 | `dwyer-mcallister-cottage` | Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje... |
| 2 | 21196 | `national-botanic-gardens-kilmacurragh` | Jardim botânico no condado de Wicklow, parte dos Jardins Botânicos Nacionais, famoso pelos rododendros e pelas árvores raras... |
| 3 | 21195 | `fore-abbey` | Ruínas do mosteiro fundado por São Feichin no século VII, num vale tranquilo do condado de Westmeath, com... |
| 4 | 21194 | `roscrea-heritage-centre-roscrea-castle-and-damer-house` | Conjunto no centro de Roscrea com um castelo do século XIII, a casa pré-paladiana Damer House e jardins... |
| 5 | 21193 | `the-main-guard` | Antigo tribunal do século XVII no centro de Clonmel, com arcada de colunas de arenito restaurada e espaço... |
