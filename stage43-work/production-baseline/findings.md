# Stage 4.3 — Production Baseline Findings: https://conexaobr.ie

**Method:** read-only audit. `probe.py` (this directory) issues **GET/HEAD only** with a
browser-like User-Agent (`Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 … Chrome/124`)
and `Accept-Language: pt-BR,pt;q=0.9,en;q=0.8`, follows redirects manually (max 5 hops),
20 s timeout, and captures raw bodies/headers into `./raw/`. No POST/PUT/PATCH/DELETE, no
login, no credentials, no admin access. All statements below are measured; where a fact
could not be measured it is labelled **NOT_MEASURABLE**.

**Automated summary artifacts:** `baseline.json` (machine-readable, with `analysis` section)
and `baseline.md` (row-by-row table). This file is the narrative.

---

## 0. OVERRIDING MEASUREMENT CONSTRAINT: the edge rate-limits uncached requests (HTTP 429)

This dominates the whole baseline and must be read first.

The WordPress.com Atomic edge serves **cached** responses normally but answers
**uncached** (cache `MISS`) requests with `429 Too Many Requests`, intermittently.

Exact captured evidence (`raw/home_en.headers.json`, `raw/home_en.html`):

```
HTTP/2 429
content-type: text/html
server-timing: a8c-cdn, dc;desc=dca, cache;desc=MISS;dur=6.0
x-ac: 2.dca _atomic_dca MISS
content-length: 1168
```

429 body (verbatim, tags stripped):

> `429 Too Many Requests` — *“You have been rate-limited for making too many requests in a
> short time frame. Website owner? If you think you have reached this message in error,
> please contact support.”*

Controlled diagnosis (independent of the harness, `curl`, distinct edge nodes):

| probe | path | result | `x-ac` |
|---|---|---|---|
| quiet 200 s, then GET | `/en/` | **429** | `2.dca _atomic_dca MISS` |
| quiet 90 s, then GET | `/nonexistent-path-xyz-12345/` | **429** | `5.dca _atomic_dca MISS` |
| quiet 90 s, then GET | `/en/` | **429** | `2.dca _atomic_dca MISS` |
| quiet 90 s, then GET | `/` | **200** | `2.dca _atomic_dca STALE` |
| quiet 90 s, then GET | `/eventos/` (earlier) | **200** | `STALE` |
| quit 20/15 s spacing | `/` then `/en/` then `/eventos/` then `/guias/` | `200, 429, 200, 429` | STALE / MISS alternating |

---

## 1. Does `/en/` serve an English homepage?

**Measured: NOT_MEASURABLE (edge 429) — `/en/` never returned a 2xx/3xx in any pass.**

| probe | path | status chain | `x-ac` | note |
|---|---|---|---|---|
| `home_en` | `/en/` | `[429]` | `2.dca _atomic_dca MISS` | raw/home_en.{html,headers.json} |
| `en_home` | `/en/home/` | `[429]` | MISS | raw/en_home.* |
| `pll_route` | `/pll/` | `[429]` | MISS | raw/pll_route.* |
| `head_home_en` | `/en/` (HEAD) | `[429]` | MISS | raw/head_home_en.* |

Indirect measured evidence **against** a deployed English layer:

* The production `/` (200, `x-ac STALE`) contains **zero** `hreflang` attributes
  (`grep -o hreflang raw/home_pt.html | wc -l` → `0`).
* It contains **zero** occurrences of the string `polylang` or `pll_`
  (`grep -c` → `0`), and **no** language-switcher markup (`language-switch`, `lang-switch`,
  `English` → all `0`; `mobile-menu-language` → `0`).
* Its primary navigation is the **Portuguese** nine-item menu (see §8).

**Answer: `/en/` cannot be confirmed live; and the only readable deployment (the PT
homepage) shows no trace of the English layer. On the evidence available, `/en/` is NOT
demonstrated to serve an English homepage.**

## 2. Is Polylang installed/active on production?

**No positive evidence of Polylang was found; every Polylang-specific signal is either
absent (on the readable 200 pages) or blocked by 429 (on the paths that would prove it).**

| Polylang signal | What was requested | Measured result |
|---|---|---|
| `pll_language` cookie | any response | never present (`raw/*.headers.json` — no `Set-Cookie` with `pll_language`) |
| body mentions of `pll_language` / `Polylang` | `/` (200) | `0` / `0` |
| `/pll/` route | `/pll/` | **429** (NOT_MEASURABLE) |
| Polylang REST namespace | `/wp-json/pll/v1` | **429** (NOT_MEASURABLE) |
| `hreflang` alternates (Polylang or theme emitter) | `/` (200) | **0 tags** |
| `/wp-json/wp/v2/pages?lang=en` | REST | **429** in all passes (NOT_MEASURABLE) |
| `/wp-json/wp/v2/posts?lang=en` | REST | **200**, `X-WP-Total: 36`, items carry **no** `lang` field (§5) |
| Language switcher markup | `/` (200) | absent — and the repo's `header.php` renders the
| | | `<div class="mobile-menu-language">` wrapper **unconditionally** |

That last row is decisive for the *build*, not just for Polylang: the deployed theme is
**older than the Stage 2 header** (it has `mobile-menu-nav`, `mobile-menu-search-field`,
`mobile-menu-title`, but **not** `mobile-menu-language`). Consistent with that, the
production theme emits **no hreflang** at all.

**Answer: Polylang does not appear active on production (no cookie, no hreflang, no
switcher, no `pll_` marks), and the deployed theme predates the Stage 2 English work. The
definitive Polylang probes (`/pll/`, `/wp-json/pll/v1`, `pages?lang=en`) are blocked by the
edge 429 and are recorded as NOT_MEASURABLE.**

## 4. Does production expose the `conexao/v1` REST namespace?

**Answer: NO — measured directly.**

| request | status | body |
|---|---|---|
| `/wp-json/conexao/v1/events` | **404** | `{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}` |
| `/wp-json/conexao/v1` | 429 | NOT_MEASURABLE in the passes run (retried) |
| `/wp-json/conexao/v1/` | 429 | NOT_MEASURABLE |
| `/wp-json/conexao/v1/languages` | 429 | NOT_MEASURABLE |

The 404 is generated by the **WordPress REST API itself** (`code: rest_no_route`,
`content-type: application/json; charset=UTF-8`), i.e. the REST router answered and there is
**no `conexao/v1` route registered** on production. Evidence: `raw/conexao_v1_events.{json,headers.json}`.
The full namespace list from `/wp-json/` is recorded in `baseline.json`
(`analysis.rest_namespaces.namespaces`); it was 429 in all passes, so the definitive
namespace enumeration is NOT_MEASURABLE — but the `rest_no_route` 404 above is sufficient to
state that `conexao/v1` is **not deployed**.


Conclusions (measured, not inferred):


## 6. Site identity / versions (measured)

Readable pages: `/`, `/guias/`, `/lazer/` (all 200, `x-ac STALE`/`MISS`).

| item | measured | source |
|---|---|---|
| Hosting | **WordPress.com Atomic** | `host-header: WordPress.com`; `x-hacker: Want root?  Visit join.a8c.com and mention this header.`; `x-ac: 2.dca _atomic_dca`; `server: nginx`; `server-timing: a8c-cdn, dc;desc=dca, …` |
| WordPress version | **NOT_MEASURABLE** | no `<meta name="generator">` on `/`, `/guias/`, `/lazer/` (`[]`); WP.com strips it |
| PHP version | **NOT_MEASURABLE** | no `x-powered-by`, no PHP leak in any captured header |
| Jetpack | **PRESENT** | sitemap generator comment `<!--generator='jetpack-16.3-a.3'-->`; Jetpack REST fields on `posts?lang=en` (`jetpack-related-posts`, `jetpack_shortlink`, `jetpack_likes_enabled`, `jetpack_sharing_enabled`, `jetpack_featured_media_url`); Jetpack `robots.txt`; second `og:locale` (Jetpack Open Graph) |
| Theme (slug) | `conexao-br-irlanda` | asset URLs `…/themes/conexao-br-irlanda/assets/…` |
| Theme version | **NOT_MEASURABLE** (repo `style.css` declares `Version: 1.0.0`) | WP.com concatenates CSS into `/_static/??-eJ…` (`id='all-css-05b8917a…'`); no direct `style.css` link on the page |
| Theme build timestamp | `assets/js/main.js?ver=1789573003` → **2026-09-16T15:36:43Z** | homepage HTML |
| `<html lang>` | **`en-US` on `/`, `/guias/`, `/lazer/`** (Portuguese content) | `language_attributes()` → site locale is **en_US** |
| `og:locale` | **two emitters**: `pt_BR` **and** `en_US` | `raw/home_pt.html` |
| `gmt_offset` / `timezone_string` | **NOT_MEASURABLE** | `/wp-json/` returned 429 in every pass |
| REST root (`/wp-json/`) | 429 → namespace list NOT_MEASURABLE | exception: the `conexao/v1` probe answered with a 404 `rest_no_route` (§4) |
| Deployed theme build | **pre-Stage-2**: has `mobile-menu-nav`, `mobile-menu-search-field`, `mobile-menu-title`, `site-navigation`, `primary-navigation`, `help-shortcuts`; **missing** `mobile-menu-language` (an unconditional wrapper in the current repo `header.php`) | `grep -o` over `raw/home_pt.html` |

## 8. Menus (measured, from the 200 `/` capture)

Primary navigation (`<nav id="site-navigation" class="primary-navigation">`), 9 items, in order:

| # | label | href |
|---|---|---|
| 1 | Início | `https://conexaobr.ie/` |
| 2 | Apoiadores | `https://conexaobr.ie/apoiadores/` |
| 3 | Guias | `https://conexaobr.ie/guias/` |
| 4 | Eventos | `https://conexaobr.ie/eventos/` |
| 5 | Cursos | `https://conexaobr.ie/cursos/` |
| 6 | Lazer e turismo | `https://conexaobr.ie/lazer/` |
| 7 | Empregos | `https://conexaobr.ie/empregos/` |
| 8 | Blog | `https://conexaobr.ie/blog/` |
| 9 | Contato | `https://conexaobr.ie/contato/` |

The mobile drawer (`<nav class="mobile-menu-nav">`) repeats the same 9 labels/hrefs. The
homepage shortcut nav (`<nav class="help-shortcuts">`), 6 items:

| label | href |
|---|---|
| Moradia | `https://conexaobr.ie/guias/?categoria=moradia` |
| Empregos | `https://conexaobr.ie/empregos/` |
| Documentos | `https://conexaobr.ie/guias/?categoria=documentos` |
| Saúde | `https://conexaobr.ie/guias/?categoria=saude` |
| Benefícios | `https://conexaobr.ie/guias/?categoria=beneficios` |
| Finanças | `https://conexaobr.ie/guias/?categoria=financas` |

No language switcher item is present (see §1/§2). `/wp-json/wp/v2/menus`, `/menu-locations`
and `/menu-items` were 429 in every pass → **NOT_MEASURABLE** (they also require
authentication in core WordPress, so a 401 would be expected even if reachable).

## 10. Caching (measured)

Every captured 200 carries the WordPress.com edge cache contract:

```
Cache-Control: max-age=300, must-revalidate
Vary: accept, content-type, cookie
X-ac: 2.dca _atomic_dca STALE        (or … MISS on a cache miss)
Server-Timing: a8c-cdn, dc;desc=dca, cache;desc=STALE;dur=2.0
Host-Header: WordPress.com
```

* `x-ac` values observed: `STALE` (served from edge cache) and `MISS` (origin-bound, usually 429).
* **No** `age`, `x-cache`, `cf-cache-status`, `expires`, `etag`* on HTML 200s (`etag` only on the 429 page).
* Homepage and `/en/`: the homepage is cached (`STALE`, 200); `/en/` is **not** cached (`MISS`) and is 429 → `/en/` output is not observable.
* CSS/JS are served through WP.com asset optimisation (`fonts-api.wp.com` and
  `/_static/??-eJ…` concatenation).

1. A non-existent path also returns **429**, so this is a limiter — not a per-URL rule.
2. A 200 s quiet period did **not** clear it → the window is longer than 200 s and/or the
   limiter is applied to the shared egress IP rather than a per-client cadence.
3. Cached (`STALE`/`HIT`) responses are unaffected and are the only reliably readable
   production output from this vantage point.
4. Consequently, every uncached URL was retried across multiple passes (`--keep-best` keeps
   the best capture per URL and never overwrites a 200 with a 429). Rows that remained 429
   after all passes are reported as such, with their raw captures retained as evidence.

**Rows still 429 after all passes are marked NOT_MEASURABLE (edge 429), and that is stated
explicitly rather than guessed.**
