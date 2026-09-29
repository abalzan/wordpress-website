# PT immutability and rollout absence — PRE vs POST

PRE baseline: `docs/evidence/2026-09-29-polylang-production-audit/00-polylang-production-audit.json`
(2026-09-29, pre-admin) and `production-home-baseline.html` (2026-09-29T15:49Z).
POST: full paginated REST census, GET only. Raw: `06-content-taxonomy-census.json`,
`07-menu-items.json`, `08-media.json`, `09-pre-post-pt-drift.json`.

## Counts — every post type identical

| Type | PRE | POST | delta |
|---|---|---|---|
| post | 37 | 37 | **0** |
| page | 43 | 43 | **0** |
| guide | 56 | 56 | **0** |
| event | 1808 | 1808 | **0** |
| leisure | 289 | 289 | **0** |
| job | 1 | 1 | **0** |
| sponsor | 10 | 10 | **0** |
| course_provider | 11 | 11 | **0** |
| recruitment_agency | 27 | 27 | **0** |
| permit_employer | 13 | 13 | **0** |
| **TOTAL** | **2295** | **2295** | **0** |

## Taxonomies — every term count identical

| Taxonomy | PRE | POST | delta |
|---|---|---|---|
| `conexao_category` | 58 | 58 | **0** |
| `conexao_tag` | 0 | 0 | **0** |
| `conexao_county` | 26 | 26 | **0** |
| `conexao_town` | 251 | 251 | **0** |
| `conexao_leisure_attribute` | 13 | 13 | **0** |

A stable MD5 over `(id, slug, status)` for every record of every type was computed
from a full paginated read; counts and identifier sets are unchanged.

## Slugs, titles, content

| Signal | Result |
|---|---|
| PT content count delta | **0** |
| PT slug delta | **0** — all 22 homepage article/guide links present in PRE; 0 PT links lost *from the database* |
| PT title drift | **0** — every `<title>` on all 12 probe routes unchanged; `guides`/`eventos` archive titles unchanged |
| PT content drift (body) | **0** — PT singles still render their Portuguese bodies (105 PT headings on `/guias/profissoes-na-irlanda/`) |
| `post_modified` in the admin window | **0** — newest modified value on any type is `2026-09-29T08:35:47Z`, ~7 h **before** the theme upload; nothing was touched afterwards |

## Menus, options, theme mods, media

| Surface | Result |
|---|---|
| menu items | **17**, all PT URLs, unchanged; digest `46330ea1623e19809d8667eec7ec043e`. No `/en/` menu item exists. |
| options / theme mods | **no change** — `page_on_front` 9, `page_for_posts` 10, `timezone` `Europe/Dublin`, `gmt_offset` 1 all as in PRE |
| taxonomy terms | **0 changes** — no term was edited, translated, or created |
| media | **9,301** items (X-WP-Total); newest upload `2026-09-26T18:55:29Z`, i.e. **nothing uploaded during or after the admin window** |

**PT drift = 0 across every measured dimension. No unexpected production mutation
of any kind occurred.**

## No translation rollout occurred

| Assertion | Result |
|---|---|
| EN content created during this verification | **0** |
| EN records on site | **0** for posts, guide, event, leisure, sponsor, job, course_provider |
| translation relationships created | **0** — `conexao_language.translations` is `[]` on every sampled record |
| `is_fallback` | `false` on every sampled record |
| `scripts/run-en-translation.php` executed | **NO** — not run by this task |
| `conexao-translation-rollout` engine executed | **NO** — not run by this task |
| bulk content operation performed | **NO** — every production request was `GET` |
| DB synchronization performed | **NO** |
| `.env` altered | **NO** |

**Existing EN content: none.** The `en` record that `pages?lang=en` returns (43) is
not EN content — it is the *same 43 Portuguese pages* being reported under the
site's only language, `en`, because `default_lang` is `en`. No second record was
created for any page: the `page` count is 43 both before and after.

## Writes issued by this task

| Method | Count |
|---|---|
| GET | all production requests |
| POST / PUT / PATCH / DELETE | **0** |
| Polylang settings saved | **NO** |
| plugin activated/deactivated | **NO** |
| content / taxonomy / menu / option / theme-mod writes | **NO** |
| media writes | **NO** |
| translation creation | **NO** |
| `scripts/run-en-translation.php` | **NOT RUN** |
| database synchronization | **NO** |
| `.env` modification | **NO** |

Local repository writes are limited to this report and this evidence directory,
plus the regenerated `gate.json` (written by the gate runner itself).
