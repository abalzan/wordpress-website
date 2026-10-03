# Raw sanitized production responses — Polylang audit (2026-09-29)

Every body below is a verbatim read-only `GET` response, truncated where marked.
No credential, token, cookie or auth-header value appears in this file.

User-Agent on every request: `ConexaoBR-Audit/1.0 (read-only polylang audit)`
Target: `https://conexaobr.ie`

---

## 1. `GET /wp-json/` — REST index (authenticated)

```json
{"name":"Conexão BR","description":"","url":"https://conexaobr.ie","home":"https://conexaobr.ie",
 "gmt_offset":1,"timezone_string":"Europe/Dublin","page_for_posts":10,"page_on_front":9,
 "show_on_front":"page","namespaces":["akismet/v1","help-center","jetpack-boost/v1",
 "jetpack-global-styles/v1","jetpack/v4","jetpack/v4/blaze","jetpack/v4/blaze-app",
 "jetpack/v4/explat","jetpack/v4/import","jetpack/v4/stats-app","jetpack/v4/videopress",
 "my-jetpack/v1","newspack-blocks/v1","oembed/1.0","videopress/v1","wp-abilities/v1",
 "wp-block-editor/v1","wp-site-health/v1","wp/v2","wpcom/v2","wpcom/v3","wpcomsh/v1"]}
```

`namespace_count: 22` — `pll/v1` is **not** listed here. See §3: it exists anyway.

---

## 2. `GET /wp-json/wp/v2/users/me` (authenticated) — identity

```json
{"id":283039558,"name":"conexaobradmin",
 "url":"http://andreibalzan4968c42e1c-yoglw.wordpress.com","description":"",
 "link":"https://conexaobr.ie/author/conexaobradmin","slug":"conexaobradmin","capabilities":{}}
```

`capabilities` is empty on WordPress.com (capabilities are not exposed through this
endpoint); `wp/v2/plugins` and `wp/v2/themes` remain readable, which is the access this
audit required. Read access: **sufficient**. Admin/write access: **not established**.

---

## 3. Polylang REST namespace — EXISTS, but returns an empty language list

### 3a. Control (proves the audit can detect a missing namespace)

```
GET /wp-json/zzz/v1/languages   ->  404
{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}
```

### 3b. Polylang namespace route index

```
GET /wp-json/pll/v1   ->  200
```
```json
{"namespace":"pll/v1","routes":{
  "/pll/v1":{...},"/pll/v1/languages":{...},
  "/pll/v1/languages/(?P<slug>[a-z][a-z0-9_-]*)":{...},
  "/pll/v1/languages/(?P<term_id>[\\d]+)":{...},
  "/pll/v1/settings":{...}}}
```
`route_count: 5`

### 3c. Languages list — EMPTY

```
GET /wp-json/pll/v1/languages?nonce=<random>   ->  200
[]
```
Response headers prove this is not a CDN-cached artefact:
```
cache-control: no-cache, must-revalidate, max-age=0, no-store, private
x-ac: 15.lhr _atomic_ams BYPASS
```

### 3d. Language lookup — both `pt` and `en` are invalid

```
GET /wp-json/pll/v1/languages/en  ->  404  {"code":"rest_invalid_slug","message":"Invalid language slug","data":{"status":404}}
GET /wp-json/pll/v1/languages/pt  ->  404  {"code":"rest_invalid_slug","message":"Invalid language slug","data":{"status":404}}
```

---

## 4. `GET /wp-json/pll/v1/settings` (authenticated) — THE decisive response

```json
{"force_lang":1,"domains":[],"hide_default":true,"rewrite":true,"redirect_lang":false,
 "browser":false,"media_support":false,"post_types":[],"taxonomies":[],"sync":[],
 "default_lang":"","nav_menus":[],"first_activation":1790697615,"previous_version":"",
 "version":"3.8.10"}
```

| Key | Value | Meaning |
|---|---|---|
| `default_lang` | `""` | **no default language defined** |
| `post_types` | `[]` | **no post type is translated** |
| `taxonomies` | `[]` | **no taxonomy is translated** |
| `nav_menus` | `[]` | no per-language menus |
| `rewrite` | `true` | directory language mode (unusable with no language) |
| `hide_default` | `true` | (unusable with no default language) |
| `force_lang` | `1` | force the language |
| `browser` | `false` | **no browser negotiation** |
| `redirect_lang` | `false` | no 302 to an existing translation |
| `media_support` | `false` | media not duplicated per language |
| `version` | `"3.8.10"` | plugin version |
| `previous_version` | `""` | **first install** (no prior version recorded) |
| `first_activation` | `1790697615` | 2026-09-29T16:00:15Z — activated today |

---


## 5. `GET /wp-json/wp/v2/plugins?per_page=100` — full inventory (18 plugins)

| plugin | status | version |
|---|---|---|
| akismet/akismet | active | 5.7.2 |
| classic-editor/classic-editor | inactive | 1.7.0 |
| conexao-admin-ux/conexao-admin-ux | active | 1.0.6 |
| conexao-content/conexao-content | active | 1.0.0 |
| conexao-data-model/conexao-data-model | active | 1.6.0 |
| conexao-event-importer/conexao-event-importer | active | 1.7.1 |
| conexao-event-runtime/conexao-event-runtime | active | 1.2.1 |
| conexao-leisure-migration/conexao-leisure-migration | active | 2.1.0 |
| crowdsignal-forms/crowdsignal-forms | inactive | 1.8.2 |
| gravatar-enhanced/gravatar-enhanced | active | 0.13.1 |
| gutenberg/gutenberg | active | 24.0.0 |
| jetpack/jetpack | active | 16.3-a.3 |
| layout-grid/index | inactive | 1.8.5 |
| page-optimize/page-optimize | active | 0.6.3 |
| polldaddy/polldaddy | inactive | 3.1.8 |
| **polylang/polylang** | **active** | **3.8.10** (requires_wp 6.5, requires_php 7.4) |
| updraftplus/updraftplus | active | 1.26.8 |
| wordpress-importer/wordpress-importer | active | 0.9.6 |

Polylang-family plugins: exactly one (`polylang/polylang`). No Pro, no add-on.
No WPML / qTranslate / TranslatePress / Loco / Weglot / GTranslate detected.

---

## 6. `GET /wp-json/wp/v2/themes` — active theme

```
assembler            0.0.124        inactive
conexao-br-irlanda   1.0.0           ACTIVE
twentytwentytwo     2.1-wpcom       inactive
```

---

## 7. REST language filtering is INACTIVE — `X-WP-Total`

| post type | no `lang` | `lang=pt` | `lang=en` | identical? |
|---|---|---|---|---|
| pages | 43 | 43 | 43 | YES |
| posts | 37 | 37 | 37 | YES |
| guide | 56 | 56 | 56 | YES |
| event | 1808 | 1808 | 1808 | YES |
| leisure | 289 | 289 | 289 | YES |
| job | 1 | 1 | 1 | YES |
| sponsor | 10 | 10 | 10 | YES |
| course_provider | 11 | 11 | 11 | YES |
| recruitment_agency | 27 | 27 | 27 | YES |
| permit_employer | 13 | 13 | 13 | YES |

`?lang=en` returns **Portuguese slugs**:
`?lang=en&per_page=5` on `guide` → `profissoes-na-irlanda, carteira-motorista-br, …`
`?lang=en&per_page=5` on `pages` → `categorias, voluntariado, compras, turismo, …`

```
GET /wp-json/wp/v2/posts?per_page=3&_fields=lang  ->  200
[{"slug":"outono-irlanda-alimentacao-bem-estar"},{"slug":"quem-voce-se-tornou-longe-de-casa"},
 {"slug":"dois-fatores-que-fazem-o-seu-negocio-ter-consistencia-nas-vendas"}]
```
No record carries a `lang` value.

---

## 8. Taxonomies — no language anywhere

`GET /wp-json/wp/v2/taxonomies` → 10 taxonomies:
`category, conexao_category, conexao_county, conexao_leisure_attribute, conexao_tag, conexao_town, nav_menu, post_tag, wp_knowledge_type, wp_pattern_category`

**`language` taxonomy: NOT registered.** (Polylang registers it only when languages exist.)

| taxonomy | terms |
|---|---|
| conexao_category | 58 |
| conexao_tag | 0 |
| conexao_county | 26 |
| conexao_town | 251 |
| conexao_leisure_attribute | 13 |

No term response carries `conexao_language`.

---

## 9. Frontend HTTP matrix (redirects NOT followed)

| path | status | `<html lang>` | canonical | hreflang | Location |
|---|---|---|---|---|---|
| `/` | 200 | `en-US` | `https://conexaobr.ie/` | 0 | — |
| `/en/` | 301 | — | — | 0 | `https://conexaobr.ie/eventos/encore-halloween-edition-special-guest-1926/` (`X-Redirect-By: WordPress`) |
| `/guias/` | 200 | `en-US` | `https://conexaobr.ie/guias/` | 0 | — |
| `/en/guias/` | 404 | `en-US` | — | 0 | — |
| `/eventos/` | 200 | `en-US` | `https://conexaobr.ie/eventos/` | 0 | — |
| `/en/eventos/` | 404 | `en-US` | — | 0 | — |
| `/lazer/` | 200 | `en-US` | `https://conexaobr.ie/lazer/` | 0 | — |
| `/en/lazer/` | 301 | — | — | 0 | `https://conexaobr.ie/lazer/` (`X-Redirect-By: WordPress`) |
| `/empregos/` | 200 | `en-US` | `https://conexaobr.ie/empregos/` | 0 | — |
| `/en/empregos/` | 301 | — | — | 0 | `https://conexaobr.ie/empregos/` (`X-Redirect-By: WordPress`) |
| `/blog/` | 200 | `en-US` | `https://conexaobr.ie/` | 0 | — |
| `/en/blog/` | 301 | — | — | 0 | `https://conexaobr.ie/blog/` (`X-Redirect-By: WordPress`) |

`/en/guias/` 404 body: `<title>Página não encontrada | Conexão BR</title>`

hreflang attribute count across `/`, `/guias/`, `/lazer/`, `/empregos/`, `/blog/`,
`/eventos/`, `/irlanda/`, `/dublin/`, `/cursos/`, `/apoiadores/` → **0 total**.

Browser negotiation:
```
GET /  (Accept-Language: en-GB,en;q=0.9)  ->  200   (no Location header)
```

Language-switcher markup on `/`, `/guias/`, `/empregos/` → **0 matches**.

---

## 10. Deployed-artifact fingerprint — production runs the `master` build

```
GET /wp-content/themes/conexao-br-irlanda/assets/css/main.css
  production : 192920 bytes  md5 a4bab9a49b7dd0bdcce665f2380ce423
  master     : 192920 bytes  md5 a4bab9a49b7dd0bdcce665f2380ce423
  i18n (HEAD): 196327 bytes  md5 a367b7950e0511958a3768d284a4d35b
```

**Production is byte-identical to `master` and differs from `i18n`.**

File-presence probes:

| path | in `master` | in `i18n` | production |
|---|---|---|---|
| `inc/seo.php` | yes | no | **200** |
| `inc/events.php` | no | yes | 404 |
| `inc/queries.php` | no | yes | 404 |
| `inc/navigation.php` | no | yes | 404 |
| `inc/leisure.php` | no | yes | 404 |
| `languages/en_US.mo` | no | yes | 404 |

Theme file counts: `master` 81, `i18n` 151.

REST consequence — no `conexao_language` field on any record (that field is declared
in `inc/rest-language.php`, which does not exist in the deployed build):
`pages`, `posts`, `guide`, `event`, `leisure` → **ABSENT**.

---

## 11. Write-protection re-check (end of audit)

`/wp-json/pll/v1/settings` re-read at the end of the audit is **byte-identical** to §4.
Plugin inventory re-read: 18 plugins, same statuses, same versions, Polylang still
`active 3.8.10`. All `X-WP-Total` values re-read identical to §7. Active theme
re-read: `conexao-br-irlanda 1.0.0`.

**Every request in this audit was `GET`.** No POST/PUT/PATCH/DELETE was issued.
