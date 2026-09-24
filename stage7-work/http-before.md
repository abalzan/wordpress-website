# Stage 7.x — HTTP evidence: BEFORE (no change yet)

Captured **2026-09-24 (UTC)**, read-only `GET` requests (UA `conexao-stage7-leisure-verify/1.0`), redirects **not** followed.
Raw payloads: `http-before.json`; full page HTML in `stage7-work/http-cache/`.

## Production state (conexaobr.ie)

| Path | Status | Redirect | Cards | Result |
|---|---|---|---|---|
| `/lazer/` | **200** | — | 10 | PT Leisure archive renders normally |
| `/lazer/?county=dublin` | **200** | — | 10 | shared county filter behaves normally |
| `/en/lazer/` | **301** | → `https://conexaobr.ie/lazer/` | 0 | production has **no English layer yet** (Stage 4.3 Polylang + theme deployment still pending, as documented in the Stage 6 report §0) |

## Rendered card excerpts on `/lazer/` (raw HTML, `.leisure-card-excerpt`)

```
class="leisure-card-excerpt">Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje...
class="leisure-card-excerpt">Jardim botânico no condado de Wicklow, parte dos Jardins Botânicos Nacionais, famoso pelos rododendros e pelas árvores raras...
class="leisure-card-excerpt">Ruínas do mosteiro fundado por São Feichin no século VII, num vale tranquilo do condado de Westmeath, com...
```

**Excerpt source chain proven byte-level:** all 10 card excerpts on `/lazer/` page 1 are exactly the REST `post_excerpt` values of the same records, trimmed to 18 words + `...` (`leisure-card.php:154`, `wp_trim_words( get_the_excerpt(), 18, '...' )`):

```json
"excerpt_vs_rest": { "identical": 0, "trim_18_words_then_dots": 10, "mismatched": [] }
```

(0 “identical” only because every production excerpt is longer than 18 words; the trim is the only difference.)

## Where the defect itself lives (the EN archive showing PT excerpts)

The EN Leisure archive (`/en/lazer/`) exists only where the Stage 4.3 English layer is deployed — the validated sandbox environment of Stages 3.x (production deployment still pending). There, `/en/lazer/` renders **200 with B2 fallback**: PT Leisure records under the EN URL + EN chrome, so every `.leisure-card-excerpt` is Portuguese. Measured and documented in the Stage 3.2 report (`CONEXAO_BR_ENGLISH_STAGE_3_2_REPORT.md`):

- §matrix: `/en/lazer/` → **200** — “real EN + PT B2 fallbacks, no duplicates”; `/en/lazer/?county=dublin` → 200 — “shared county matches both languages’ records”
- B2 contract: EN archive = PT record set + EN shell (architecture decision §B2 table: “Lazer → B2 — PT content under EN shell + notice”)

Code-level trace confirms the same: `archive.php:131` renders `template-parts/leisure-card.php` for the `leisure` archive in **both** languages; the card’s excerpt line (154) contains **no language handling at all** — `get_the_excerpt()` returns the PT `post_excerpt` on `/en/lazer/` unchanged. That is the defect this stage was asked to fix **by rendering the original English source description** — which the Phase 0 measurement (`leisure-description-inventory.md`) proves does not exist anywhere in the Leisure data (0/289 records).

## Limitations (honest note)

- No browser was used; all evidence is raw-HTTP/HTML-level (curl + urllib, no redirect-following).
- No sandbox WordPress clone was rebuilt for this stage: the decisive question (does an English source description exist?) is answered by the production data itself, and a clone could not add English text that the data does not contain.
