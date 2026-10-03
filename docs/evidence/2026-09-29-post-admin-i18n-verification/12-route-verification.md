# Route matrix — POST admin actions

Captured 2026-09-29, GET only, redirects not followed. Raw: `05-route-matrix.json`.

| Path | status | `<html lang>` | `og:locale` | Location | vs PRE baseline |
|---|---|---|---|---|---|
| `/` | **200** | `en-US` | `en_US` | — | status same, **body language changed** |
| `/en/` | **301** | — | — | `/eventos/encore-halloween-edition-special-guest-1926/` | **identical** |
| `/guias/` | **200** | `en-US` | `pt_BR` | — | status same, **now empty + English** |
| `/en/guias/` | **404** | `en-US` | `en_US` | — | **identical** |
| `/eventos/` | **200** | `en-US` | `pt_BR` | — | status same, **now empty + English** |
| `/en/eventos/` | **404** | `en-US` | `en_US` | — | **identical** |
| `/lazer/` | **200** | `en-US` | `pt_BR` | — | status same, **now empty + English** |
| `/en/lazer/` | **301** | — | — | `/lazer/` | **identical** |
| `/empregos/` | **200** | `en-US` | `pt_BR` | — | status same, **now empty + English** |
| `/en/empregos/` | **301** | — | — | `/empregos/` | **identical** |
| `/blog/` | **200** | `en-US` | `pt_BR` | — | status same, **now empty + English** |
| `/en/blog/` | **301** | — | — | `/blog/` | **identical** |

Every status code and every redirect target is **byte-identical to the PRE
baseline**. The 12-row status matrix has delta **0**.

## Classification of each `/en/` failure

| Route | Classification | Reason |
|---|---|---|
| `/en/` → `/eventos/encore-halloween-edition-special-guest-1926/` | **incorrect redirect** (pre-existing) | 301 to a single unrelated PT **event** record, `X-Redirect-By: WordPress`. Not a Polylang redirect. Reproduced identically in the PRE baseline and in the 2026-09-29 audit — pre-existing, not introduced here. |
| `/en/guias/` | **404 from missing EN data** | 404, not a redirect. `guide?lang=en` → 0 records: no EN guide exists. |
| `/en/eventos/` | **404 from missing EN data** | 404, not a redirect. `event?lang=en` → 0 records. |
| `/en/lazer/` → `/lazer/` | **incorrect redirect** | 301 straight to the Portuguese URL. `X-Redirect-By: WordPress`. |
| `/en/empregos/` → `/empregos/` | **incorrect redirect** | 301 to the Portuguese URL. |
| `/en/blog/` → `/blog/` | **incorrect redirect** | 301 to the Portuguese URL. |

**No `/en/` route resolves to English content.** Not one. The `pt` language does not
exist, so there is no English language in the system at all — the `/en/` prefix is
inert.

## Distinguishing the four failure classes

- **Language routing working** — **0 of 6.** No `/en/` URL serves English content.
- **Missing EN content** — applies to `/en/guias/`, `/en/eventos/` (404 with 0 EN
  records). Expected at this stage *if* the language layer worked; it does not.
- **Incorrect fallback** — **0.** The approved B2 fallback (PT body under the EN
  shell + notice) never engages, because no `en` translation pair exists and
  `conexao_language.is_fallback` is `false` everywhere.
- **Incorrect redirect** — **4** (`/en/lazer/`, `/en/empregos/`, `/en/blog/`, and
  the anomalous `/en/` → event). All carry `X-Redirect-By: WordPress`; none is
  issued by Polylang.
- **404 caused by missing rollout data** — **2** (`/en/guias/`, `/en/eventos/`).

Browser detection remains off: `Accept-Language: pt-BR` on `/` → 200, no redirect;
`Accept-Language: en-GB` on `/en/` → 301 to the same target as a neutral request.
No browser negotiation was introduced.

**Conclusion: routing does not behave according to the repository model. The route
*status* matrix is unchanged from the baseline, but the pages behind the PT routes
now render English and empty.**
