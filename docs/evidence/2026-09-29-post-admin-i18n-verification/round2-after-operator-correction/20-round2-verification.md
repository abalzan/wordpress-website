# Round 2 — re-verification after the operator corrected Polylang

Captured 2026-09-29, ~18:56–20:10 UTC. GET only, 0 writes.

**Production changed between round 1 and round 2.** The operator corrected the
Polylang language configuration. This file records the new state; the round-1
files in the parent directory record the earlier state and are retained as the
PRE/POST record.

## What changed (round 1 → round 2)

| Property | Round 1 | **Round 2** | Verdict |
|---|---|---|---|
| language count | 1 | **2** | ✅ **fixed** |
| `pt` present | absent | **present** — `Português`, `pt_BR`, `is_default: true` | ✅ **fixed** |
| `en` | default, unprefixed | **secondary**, `en_US`, `home_url` `/en/` | ✅ **fixed** |
| `default_lang` | `en` | **`pt`** | ✅ **fixed** |
| `/en/` | 301 → unrelated event | **200**, self-canonical `/en/` | ✅ **fixed** |
| `/en/guias/`, `/en/eventos/` | 404 | **200**, English heading, self-canonical | ✅ **fixed** |
| `/en/lazer/`, `/en/empregos/`, `/en/blog/` | 301 → PT | **200** | ✅ **fixed** |
| PT `<html lang>` | `en-US` (English content) | **`pt-BR`**, Portuguese content | ✅ **fixed** |
| hreflang | 0 everywhere | **emitted** | ✅ **fixed** |
| `?lang=pt` | 400 error | **200, but 0 records** | ⚠️ still wrong |

## Theme — unchanged and still proven

| File | status | bytes | md5 |
|---|---|---|---|
| `assets/css/main.css` | 200 | **196327** | **`a367b7950e0511958a3768d284a4d35b`** |
| `inc/i18n/guard.php` | 200 | — | — |
| `inc/rest-language.php` | 200 | — | — |

## Polylang settings (actual)

```
force_lang 1 · hide_default true · rewrite true · redirect_lang false
browser false · media_support false · domains [] · nav_menus []
default_lang "pt" · post_types [] · taxonomies [] · version 3.8.10
```

| Requirement | Status |
|---|---|
| exactly two languages | ✅ 2 |
| `pt` / `en` | ✅ |
| `default_lang = "pt"` | ✅ |
| rewrite / directory mode | ✅ `true` |
| default language hidden from URL | ✅ `true` |
| browser detection off | ✅ `false` |
| Polylang redirect off | ✅ `false` |
| media support off | ✅ `false` |
| **expected translated post types configured** | ❌ **`post_types: []`** |
| **expected translated taxonomies configured** | ❌ **`taxonomies: []`** |

The two lists are still absent from the stored Polylang option. They exist in
code via `inc/i18n/guard.php:50-61` / `:94-105`, but the admin setting the task
explicitly requires remains unset.

## REMAINING GAP — no content is assigned to any language

| Probe | Result |
|---|---|
| `language` term for `pt` (term_id 1747) | **count 0** |
| `language` term for `en` (term_id 1744) | **count 0** |
| `guide?lang=pt` | **`[]`** (0) |
| `guide?lang=en` | **`[]`** (0) |
| `event?lang=pt` / `=en` | 0 / 0 |
| `leisure?lang=pt` / `=en` | 0 / 0 |
| `guide` unfiltered | 56 |
| `language` in `wp/v2/taxonomies` | **absent** (10 taxonomies) |

`conexao_language.lang` reports `pt` for a PT guide, but that is the
**default-language fallback**, not a language assignment — the guide carries no
`language` term and `_links` exposes no Polylang translation pointer.

**This violates the repository's own REST contract.** `scripts/verify-rest-english.py`
asserts `legacy {name} = PT ∪ EN memberships (nothing added or removed)`.
Measured: legacy = 56, PT = 0, EN = 0, so the union is 0 ≠ 56.

Language filtering is therefore **not** distinguishable, and PT filtering is
wrong. The Portuguese front end renders correctly only because `force_lang = 1`
forces the default language; the content itself is untagged.


## Route matrix — all 12 rows resolve

| Path | status | `<html lang>` | canonical |
|---|---|---|---|
| `/` | 200 | `pt-BR` | `https://conexaobr.ie/` |
| `/en/` | 200 | `en-US` | `https://conexaobr.ie/en/` |
| `/guias/` | 200 | `pt-BR` | `https://conexaobr.ie/guias/` |
| `/en/guias/` | 200 | `en-US` | `https://conexaobr.ie/en/guias/` |
| `/eventos/` | 200 | `pt-BR` | `https://conexaobr.ie/eventos/` |
| `/en/eventos/` | 200 | `en-US` | `https://conexaobr.ie/en/eventos/` |
| `/lazer/` | 200 | `pt-BR` | `https://conexaobr.ie/lazer/` |
| `/en/lazer/` | 200 | `en-US` | `https://conexaobr.ie/en/lazer/` |
| `/empregos/` | 200 | `pt-BR` | `https://conexaobr.ie/empregos/` |
| `/en/empregos/` | 200 | `en-US` | **`https://conexaobr.ie/empregos/`** (PT) |
| `/blog/` | 200 | `pt-BR` | `https://conexaobr.ie/blog/` |
| `/en/blog/` | 200 | `en-US` | **`https://conexaobr.ie/blog/`** (PT) |

**12/12 resolve; zero redirects.** `/en/empregos/` canonicalising to the PT URL
is the approved shared-slug shape (`docs/routing.md:54`); `/en/blog/` is expected
self-canonical (`docs/routing.md:336`) but has no EN blog record to be
self-canonical *to*, so it falls back to PT.

## PT front end — RESTORED

| Path | `<h1>` | language |
|---|---|---|
| `/` | `Tudo que o brasileiro precisa para viver melhor na Irlanda` | **PT** |
| `/guias/` | `Guias Práticos` | PT |
| `/eventos/` | `Eventos` | PT |
| `/lazer/` | `Lazer` | PT |
| `/empregos/` | `Empregos` | PT |
| `/cursos/` | `Cursos` | PT |
| `/apoiadores/` | `Apoiadores` | PT |
| `/blog/` | `Blog` | PT |

> **Measurement correction.** Round 1 reported English headings on these pages.
> On re-measurement **serially** with `Cache-Control: no-cache` the pages read
> Portuguese. The round-1 English readings were either the pre-correction
> `default_lang=en` state or a stale CDN object; they are not the current state.
> The serial read is the one to rely on.

## EN side — routes work, content is absent (as expected pre-rollout)

| Path | `<h1>` | note |
|---|---|---|
| `/en/` | `Blog` | serves the **posts template**, not the EN homepage (`page_on_front` for `en` is `0`) |
| `/en/guias/` | `Practical Guides` | English heading, **0 guides listed, no B2 notice** |
| `/en/eventos/` | `Events` | English heading, empty |
| `/en/empregos/` | `Empregos` | **Portuguese** heading, canonical → PT |
| `/en/blog/` | `Blog` | canonical → PT |

**EN records: 0.** The B2 fallback notice does not render, because nothing is
language-tagged for the fallback resolver to match. This is the "missing EN
content / no rollout data" class, not a routing failure — and per the task, no
content was created to change it.

## hreflang — emitted, but asymmetric on archives

| Path | hreflang set | expected per `docs/routing.md:72-73` |
|---|---|---|
| `/` | `pt`, `en`, `x-default` | ✅ complete |
| `/en/` | `pt-BR`, `en`, `x-default` | ✅ complete |
| `/guias/` | `pt-BR`, `x-default` | ⚠️ **missing `en`** |
| `/en/guias/` | `en` | ⚠️ **missing `pt-BR` and `x-default`** |
| `/empregos/`, `/blog/` | `x-default` only | ⚠️ incomplete |
| `/en/empregos/`, `/en/blog/` | `x-default` only | ⚠️ incomplete |

The homepage pair is correct. Archive pairs are **not reciprocal** — a PT archive
does not advertise its EN counterpart.

## PT immutability — still 0 drift

Full paginated re-census after the second round of admin actions. Every count
identical to the pre-admin baseline, and every `(id, slug, status)` digest
**byte-identical to the round-1 census**:

| Type | PRE | NOW | delta | slug digest stable |
|---|---|---|---|---|
| post / page / guide / event / leisure | 37 / 43 / 56 / 1808 / 289 | same | **0** | **YES** |
| job / sponsor / course_provider | 1 / 10 / 11 | same | **0** | **YES** |
| recruitment_agency / permit_employer | 27 / 13 | same | **0** | **YES** |
| **TOTAL** | **2295** | **2295** | **0** | |
| terms | 58 / 0 / 26 / 251 / 13 | same | **0** | **YES** |

**PT drift = 0.** No rollout: EN records 0, translation links 0,
`run-en-translation.php` not run, 0 non-GET requests.

## Gates — identical to the pre-admin baseline

| Command | Result |
|---|---|
| `./scripts/run-tests.sh` | 62 suites **52 pass / 10 fail**; **2910 assertions pass / 2 fail**; script-contract **5/6**; HTTP acceptance **3/3** |
| `./scripts/lint.sh` | **0** — syntax clean, no new PHPCS, PHPStan clean |
| `verify-permanent-gates.py` | **6/7 gates**; 89 assertions pass / 2 fail; `i18n_freshness` fails (1 pre-existing, **1 new** — repository-side `.pot`, unchanged) |
| `release-manifest.py --verify` | **0** — 8 artifacts, 8 built, theme 1.0.1 |
| `generate-registry-docs.php --check` | **0** — 14 plugins, 23 regions |

**New failures: 0.** The `i18n_freshness` new violation is the same
repository-side `.pot` staleness against commit `33114c6`, not a production
condition.
