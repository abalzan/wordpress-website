# Polylang configuration — actual returned state vs the task's required state

Source: `GET /wp-json/pll/v1/settings` and `GET /wp-json/pll/v1/languages`
(authenticated, GET only). Raw: `02-polylang-raw.json`.

## Languages

`GET /wp-json/pll/v1/languages` returns a list of **length 1**:

| Field | Actual value |
|---|---|
| `slug` | **`en`** |
| `name` | English |
| `locale` | **`en_US`** |
| `is_default` | **`true`** |
| `home_url` | `https://conexaobr.ie/` (unprefixed root) |
| `term_id` | 1744 |
| `active` | true |

**There is exactly one language. `pt` does not exist.**

## Settings vs requirement

| Property | Required | **Actual** | Verdict |
|---|---|---|---|
| language count | **2** | **1** | ❌ |
| `pt` present | yes | **absent** | ❌ |
| `en` present | yes | yes (`en`) | ✅ |
| `default_lang` | **`"pt"`** | **`"en"`** | ❌ **inverted** |
| rewrite / directory mode | on | `true` | ✅ |
| `hide_default` | on | `true` | ✅ |
| browser detection | off | `false` | ✅ |
| Polylang redirect | off | `false` | ✅ |
| media support | off | `false` | ✅ |
| `force_lang` | preserved | `1` | ✅ |
| `nav_menus` | preserved | `[]` | ✅ |
| translated post types | `guide, event, leisure, sponsor, job, course_provider` | **`[]`** | ❌ |
| translated taxonomies | `conexao_category, conexao_tag` | **`[]`** | ❌ |

`domains: []`, `sync: []`, `version: 3.8.10`, `first_activation: 1790697615`
(2026-09-29T16:00:15Z) — the plugin itself is unchanged and healthy.

## The two settings that must be empty are empty for the wrong reason

`post_types: []` and `taxonomies: []` are the **stored option values**. The theme
*does* supply the lists at runtime through the `pll_get_post_types` /
`pll_get_taxonomies` filters (`inc/i18n/guard.php:50-61` and `:94-105`, which add
exactly the 6 required post types and exactly `conexao_category` + `conexao_tag`).
So the required lists are correct **in code**, while the Polylang admin option
remains unset. This is a discrepancy to report, not something this task changed.

## Consequence — the default language is inverted

Because `default_lang` is `en` and `en` is the only language, **every** record on
the site resolves to `en`. `conexao_language.lang` reads `en` for Portuguese
guides, events, leisure records and pages, and `pages?lang=en` returns **43 of 43**
pages. The repository model requires PT to be the default and unprefixed, with EN
under `/en/`.

## Language filtering is distinguishable, and `pt` is now an error

| Request | PRE baseline | **POST** |
|---|---|---|
| `guide?lang=` (none) | 200, 56 | 200, 56 |
| `guide?lang=pt` | **200, 56** (ignored) | **400** `conexao_rest_invalid_lang` |
| `guide?lang=en` | 200, 56 (ignored) | 200, **0** |
| `guide?lang=xx` (unknown) | 200, 56 | 400 `Invalid lang parameter. Supported values: en.` |

Filtering is now genuinely distinguishable, which is progress — but it is
distinguishable **only because `pt` ceased to exist**. The theme's own error
message states the contract: `Supported values: en.`

## Taxonomy registration

`GET /wp-json/wp/v2/taxonomies` returns **10** taxonomies and `language` is **not**
among them (`GET /wp-json/wp/v2/taxonomies/language` → 403). The `language` term
itself exists (term_id 1744, per `pll/v1/languages`), so the taxonomy is registered
in the database but not exposed on the REST index.

## Conclusion

**Polylang is NOT correctly configured.** One language instead of two, `pt` absent,
`default_lang` inverted to `en`, and no translated post types or taxonomies stored.
The i18n guards are correct in code, but the Polylang side of the foundation is
incomplete and, worse, actively mis-configured.
