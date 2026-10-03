# Runtime language handling

Captured 2026-09-29, post-admin, GET only. Raw: `05-route-matrix.json`,
`10-pt-render-language.json`.

## `language` taxonomy and `conexao_language`

| Check | Result |
|---|---|
| `language` in `wp/v2/taxonomies` index | **NO** (10 taxonomies) |
| `GET wp/v2/taxonomies/language` | **403** |
| `language` term in DB | **YES** — term_id 1744 (`pll/v1/languages`) |
| `conexao_language` in REST records | **YES — present** (was absent on `master`) |

`conexao_language` is now emitted, which is the positive proof that
`inc/rest-language.php` from the `i18n` build is executing. Sample:

| Type | id | slug | lang | is_fallback | translations |
|---|---|---|---|---|---|
| guide | 25034 | `profissoes-na-irlanda` | `en` | false | `[]` |
| guide | 25031 | `carteira-motorista-brasileiros` | `en` | false | `[]` |
| event | 25019 | `art-class-for-beginners` | `en` | false | `[]` |
| leisure | 21197 | `dwyer-mcallister-cottage` | `en` | false | `[]` |

The field works, but it reports **`en`** for Portuguese records — a direct
consequence of `default_lang = en` with no `pt` language. The repository expects
these to read `pt`.

## Polylang runtime functions

| Function | Observed behaviour | Expected |
|---|---|---|
| `pll_current_language()` | returns `en` for every PT URL | `pt` |
| `pll_languages_list()` | **1** language (`en`) | **2** (`pt`, `en`) |
| `pll_home_url('en')` | `https://conexaobr.ie/` (unprefixed) | `https://conexaobr.ie/en/` |
| `pll_home_url('pt')` | **n/a — no `pt` language** | `https://conexaobr.ie/` |

`en` is the default and is therefore served from the unprefixed root, which is the
mirror image of the approved model (PT unprefixed, EN under `/en/`).

## `<html lang>` and hreflang

| Path | `<html lang>` | `og:locale` | hreflang |
|---|---|---|---|
| `/` | `en-US` | `en_US` | **0** |
| `/guias/` | `en-US` | `pt_BR` | **0** |
| `/eventos/` | `en-US` | `pt_BR` | **0** |
| `/lazer/` | `en-US` | `pt_BR` | **0** |
| `/empregos/` | `en-US` | `pt_BR` | **0** |
| `/blog/` | `en-US` | `pt_BR` | **0** |
| `/en/guias/` | `en-US` | `en_US` | **0** |

`<html lang="en-US">` on every Portuguese page. The repository model
(`docs/routing.md:72-73`, `:216-217`) requires the `pt-BR` / `en` / `x-default`
hreflang set emitted by `inc/seo/hreflang.php`. **hreflang count is 0 everywhere**,
exactly as in the PRE baseline — the deployed build has the code, but it emits
nothing because no translated pair exists.

The mixed `og:locale` (`pt_BR` on PT archives, `en_US` on `/`) is itself an
inconsistency: the homepage and the archives disagree about their language.

## Portuguese pages render ENGLISH chrome — a hard regression

Comparing the committed PRE baseline HTML
(`2026-09-29-production-deployment-audit/production-home-baseline.html`, captured
before the admin actions) with the current homepage:

| Signal | PRE | POST | Delta |
|---|---|---|---|
| internal links | 38 | 16 | **−22** |
| headings | 21 | 11 | **−10** |
| PT headings | 21 | **0** | **−21** |
| EN headings | 0 | **8** | **+8** |
| `<title>` | `Conexão BR \| ` | `Conexão BR \| ` | 0 |

Headings that existed in Portuguese before and are **gone now**:

- `Tudo que o brasileiro precisa para viver melhor na Irlanda`
- `Onde procurar emprego`
- `Próximos Eventos`
- `Últimas Publicações`
- `Onde procurar emprego` / `Precisa de ajuda?`
- 6 PT guide links, 9 sponsor links, 3 PT post links

Headings that are **new** (English, on a Portuguese URL):

- `Everything Brazilians need to live better in Ireland`
- `Where to look for jobs`
- `Upcoming Events`
- `Latest Posts`
- `Need help?` · `Quick Access` · `Subscribe to our newsletter` · `Stay on top of everything!`

Portuguese archive pages now render English section headings and **empty result
sets**:

| Path | status | first headings |
|---|---|---|
| `/guias/` | 200 | `Nothing found` · `Practical Guides` |
| `/eventos/` | 200 | `Events` · `Nothing found` |
| `/lazer/` | 200 | `Leisure` · `Nothing found` |
| `/cursos/` | 200 | `Courses` · `Nothing found` |
| `/apoiadores/` | 200 | `Nothing found` |
| `/blog/` | 200 | `No articles published yet.` |

Individual PT **singles** still render Portuguese body content
(`/guias/profissoes-na-irlanda/` → 105 PT headings, `/guias/saude-da-mulher-…/` →
4 PT headings), so the stored PT content is intact. What changed is the
**language context every page is rendered in**, because Polylang now reports the
only language, `en`, as the current one.

The probe is deterministic: two consecutive reads produced identical link and
heading sets.

**Conclusion: runtime language handling does not operate correctly. The site is
being served as a single-language English site, and the Portuguese front-end
experience has regressed against the pre-admin baseline.**
