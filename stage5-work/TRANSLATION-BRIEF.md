# Blog EN translation brief (Conexão BR Irlanda)

You are translating **real, published Portuguese blog posts** of the Conexão BR
Irlanda community portal (Brazilians in Ireland) into **natural, publication-ready
English**. The English text becomes the live `/en/blog/` content, linked to the
Portuguese original through Polylang. It is **not** a machine-translation
placeholder exercise: write English a native editor would publish.

## Input

One file per post: `/workspace/stage5-work/source/<pt_slug>.json`

Relevant fields (the rest of the REST payload is metadata you can ignore):

- `title.rendered` — Portuguese title (may contain HTML entities, e.g. `&#8230;`)
- `content.rendered` — Portuguese body HTML (**the thing you translate**)
- `excerpt.rendered` — Portuguese auto-excerpt (ignore; you write a new one)
- `categories`, `tags`, `featured_media` — informational only, do not touch

## Output

One file per post: `/workspace/stage5-work/en/<pt_slug>.json`
(the filename keeps the **Portuguese** slug — it is the key the importer uses).

```json
{
  "pt_slug": "quem-voce-se-tornou-longe-de-casa",
  "en_slug": "who-have-you-become-away-from-home",
  "en_title": "Who Have You Become Away From Home?",
  "en_excerpt": "A silent trap hides inside the immigration experience — and it has nothing to do with the airport.",
  "en_meta_description": "A systemic therapist on what emigration does to your emotional support network, and how to rebuild it in Ireland.",
  "en_content": "<p><u><em><strong>Text written by: Aria Milioni</strong></em></u></p>\n<p>…</p>",
  "link_map": [],
  "translator_notes": ""
}
```

## Hard rules

1. **Translate every human-readable text node. Nothing may be summarised,
   shortened, skipped or invented.** Paragraph counts and sentence content must
   correspond 1:1 to the source. No "coming soon", no placeholder sentences.
2. **Keep the HTML structure exactly**: same tags, same order, same nesting, same
   attributes (`href`, `src`, `rel`, `target`, `class`, `style`, `id`, `width`,
   `height`), and even the source's stray/misplaced closing tags. Only text
   changes. If the source has `<p>A</p><p>B</p>`, the English has two paragraphs.
3. **Never translate / never alter:**
   - URLs, e-mail addresses, phone numbers, Eircodes, postal addresses
   - Instagram/Facebook handles and `@mentions`
   - people's names, brand, business, organisation, programme and course names
   - Irish place names (use the official English form: `Co. Laois`, `Portlaoise`,
     `Dublin`, `Abbeyleix`, `Portarlington`, `Mountmellick`, …)
   - numbers, measurements, prices, dates, statistics, percentages
   - numeric HTML entities (`&#8230;`, `&#8211;`, `&#038;`) — keep them as-is;
     `&#038;` inside `href` is just `&`
4. **Do translate** the surrounding prose, headings, list items, captions,
   button/CTA labels, quotes and the author-attribution sentence
   ("Texto escrito por:" → "Text written by:", "Terapeuta Sistêmica" →
   "Systemic Therapist").
5. **Emphasis mapping:** `<strong>`/`<em>`/`<u>` must stay on the corresponding
   translated words (same tag, same place).
6. `en_title`: natural English title. Keep ALL-CAPS only when the source used
   caps deliberately; keep original question marks. No trailing period added.
7. `en_excerpt`: 1–2 plain-text sentences (no HTML) — this becomes the archive
   card text and meta description source.
8. `en_meta_description`: ≤ 155 characters, plain text, no HTML, natural English,
   unique per post (do not reuse the excerpt verbatim).
9. `en_slug`: ASCII, lowercase, hyphen-separated, derived from `en_title`, no
   `-en`/`-2` suffix, max ~60 chars. Must not be one of the reserved slugs:
   `blog, guias, eventos, cursos, empregos, apoiadores, lazer, inicio, sobre-nos,
   contato, newsletter, revista, anuncie, politica-de-privacidade, termos-de-uso,
   cookies, search, moradia, saude, familia, financas, educacao, negocios,
   servicos, compras, transporte, turismo, voluntariado, onde-comer, beneficios,
   documentos, dublin, cork, galway, limerick, kildare, meath, wicklow,
   waterford, laois, irlanda`.
10. **`link_map`** — only for content links that point at *another blog post*
    (e.g. the legacy `https://tdcriativo.wixsite.com/...` links). Format:
    `{"from": "<exact href in the source>", "to_pt_post_slug": "<pt slug of that post>"}`.
    The importer swaps it for the English post URL. Never invent a URL. Leave
    `[]` when there are none (all external links — job boards, Instagram,
    courses, mental-health orgs — stay byte-identical).
11. Recipe posts: translate cooking terms naturally ("1 xícara de chá" → "1 cup",
    "farinha de trigo" → "plain flour", "fermento em pó" → "baking powder",
    "ponto de bico" → "thick ribbon stage") and **keep every number/weight**.
    Ingredient/instruction line breaks must stay in the same paragraphs.
12. Tone: warm, clear, community-oriented, British/Irish English spelling where
    it matters (`organisation`, `recognise`, `practise` as verb, `programme` for
    a course/programme). Address the reader as "you".
13. Line breaks inside paragraphs may be normalised into normal spaces.

## Deliverable checklist (self-verify before you finish)

- same number of `<p>` tags as the source (unless the source's stray tags make
  that ambiguous — then keep every source tag, only text replaced)
- every `href`/`src` from the source present, unchanged, except `link_map` entries
- no Portuguese words left in the English text (grep for `ção`, `você`, `não`,
  `como`, `seu/sua`, `nós`); the brand "Conexão BR" in an attribution may stay
- `en_meta_description` ≤155 chars, `en_slug` matches `^[a-z0-9-]+$`
- file written to `/workspace/stage5-work/en/<pt_slug>.json` and is
  valid JSON (`python3 -m json.tool file`)

Report back: the list of `pt_slug → en_slug` you produced, plus any post where
you had to make a judgement call.
