# Report — Production Polylang Configuration Audit (`i18n`, read-only)

> **AUDIT ONLY. Nothing was configured.** No Polylang setting was saved, no
> language was added, no plugin was activated/deactivated, no content, taxonomy,
> menu, option or theme mod was written. Every production request was a `GET`.

| | |
|---|---|
| **Stage / task name** | Production Polylang Configuration Audit — `i18n` branch |
| **Date** | 2026-09-29 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `33114c6dec671df6d07f097a0bb570454af0a8cd` |
| **Final SHA** | `33114c6dec671df6d07f097a0bb570454af0a8cd` (unchanged) |
| **Working tree at finish** | Only this report + `docs/evidence/2026-09-29-polylang-production-audit/` added; no `wp-content/`, no source change |

**Headline:** Polylang **3.8.10 is now installed and active on production** — this is
new since the previous baseline. It was first activated **today at 16:00:15 UTC** and
is **completely unconfigured: zero languages, zero translated post types, zero
translated taxonomies, no default language.** Runtime behaviour is therefore
unchanged: production still serves **zero bilingual behaviour**.

Two independent blockers were found (§Findings). The second one is new and was not in
the previous baseline.

---

## Access

| Item | Result |
|---|---|
| production access | **PASS** |
| endpoint reachable | yes — `https://conexaobr.ie` 200, `server: nginx`, WordPress.com CDN (`x-ac: *.lhr _atomic_ams`) |
| authentication source | repository `.env` → `WP_USERNAME` / `WP_APPLICATION_PASSWORD`, read via the repository's shared client `scripts/lib/rest.py` |
| authenticated identity | user id `283039558`, slug `conexaobradmin` |
| credentials exposed | **NO** — no password, token, cookie or auth-header value appears in this report or in the evidence |
| `.env` modified | **NO** |
| read access sufficient | **YES** — `wp/v2/plugins`, `wp/v2/themes`, `pll/v1/*` all readable |
| site identity | `Conexão BR`, `https://conexaobr.ie`, `Europe/Dublin`, `gmt_offset 1`, `page_on_front 9`, `page_for_posts 10` |
| active theme | `conexao-br-irlanda` **1.0.0** |
| WordPress version | **not obtainable** read-only (no REST field exposes it; `?rest_route=/wp/v2/root` → 404) |
| PHP version | **not obtainable** read-only |

> `GET /wp-json/wp/v2/users/me` returns an **empty `capabilities` map** on
> WordPress.com, so admin capability could not be read directly. This did not limit
> the audit: `/wp-json/wp/v2/plugins` and `/wp-json/wp/v2/themes` are readable and
> are the authoritative sources for plugin state, version and activation.

---

## Polylang

| Property | Value | Source |
|---|---|---|
| installed | **YES** | `GET /wp-json/wp/v2/plugins` |
| active | **YES** (`status: active`) | same |
| exact version | **3.8.10** | same |
| `requires_wp` / `requires_php` | `6.5` / `7.4` | same |
| `previous_version` | `""` → **first install, never upgraded** | `GET /wp-json/pll/v1/settings` |
| first activation | `1790697615` = **2026-09-29T16:00:15Z** | same |
| multiple Polylang plugins | **NO** — exactly one (`polylang/polylang`) | plugin inventory |
| Polylang Pro / add-on | **NO** | plugin inventory |
| other multilingual plugin active | **NO** — no WPML, qTranslate, TranslatePress, Loco, Weglot or GTranslate among 18 plugins | plugin inventory |
| initialisation / configuration error | **NO** — the REST controller is registered and answers 200 on every route | `GET /wp-json/pll/v1` |
| **configured** | **NO** | `default_lang: ""`, `post_types: []`, `taxonomies: []`, languages `[]` |
| **healthy** | **YES (mechanically)** — active, no error, REST surface intact | — |

`installed` and `active` are **not** the same as `configured`. Polylang is installed,
active and mechanically healthy, but holds **no languages at all**.

### The decisive response

`GET /wp-json/pll/v1/settings` → **200**

```json
{"force_lang":1,"domains":[],"hide_default":true,"rewrite":true,"redirect_lang":false,
 "browser":false,"media_support":false,"post_types":[],"taxonomies":[],"sync":[],
 "default_lang":"","nav_menus":[],"first_activation":1790697615,"previous_version":"",
 "version":"3.8.10"}
```

This response is **not** a CDN artefact: the request carried a random query
nonce and returned `cache-control: no-cache, must-revalidate, max-age=0, no-store,
private` with `x-ac: … BYPASS`.

---


## Languages

| Property | Production value | Repository expectation | Status |
|---|---|---|---|
| PT language | **not defined** — `default_lang: ""`, languages `[]` | PT `pt`, default; `pt-BR` locale | **BLOCKER** |
| EN language | **not defined** — `/wp-json/pll/v1/languages/en` → `404 rest_invalid_slug` | EN `en`, secondary | **BLOCKER** |
| Default language | **`""` (empty)** | PT is default (`docs/routing.md` §"English (`/en/`)" — Portuguese URLs unchanged, English adds only the `/en/` prefix) | **BLOCKER** |
| URL language mode | `rewrite: true` (directory mode) — but inert with no language | `/en/` directory prefix, PT slugs never renamed (`docs/routing.md`:62-64) | **WARNING** — correct flag, unusable state |
| Default-language URL handling | `hide_default: true` — intended shape (PT unprefixed), but no default language exists so nothing is hidden | PT is not prefixed | **WARNING** — intended value, no effect |
| Browser negotiation | **`browser: false`** — confirmed at runtime: `Accept-Language: en-GB,en;q=0.9` on `/` → **200, no redirect** | must stay **off** (no redirect-based detection; EN reached by direct URL and PT fallback) | **PASS** |
| REST language support | **inactive** — `?lang=pt` and `?lang=en` return **identical** `X-WP-Total` for all 10 post types, and `?lang=en` returns Portuguese slugs | `conexao_language` per-record language + `?lang=` filtering (`inc/rest-language.php`) | **BLOCKER** |
| translated post types | **`[]`** | `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` (`inc/i18n/guard.php`:55) | **BLOCKER** |
| translated taxonomies | **`[]`** | `conexao_category`, `conexao_tag` translated; `conexao_county`, `conexao_town` shared (`inc/i18n/guard.php`:99, `docs/content-model.md`:119) | **BLOCKER** |
| force language | `force_lang: 1` | **expected value not established by repository documentation** | **WARNING** — no repo expectation; not changed |
| media per language | `media_support: false` | media is shared (`inc/i18n/guard.php`:44) | **PASS** |
| language nav menus | `[]` | **expected value not established by repository documentation** | **WARNING** — no repo expectation; not changed |
| `redirect_lang` | `false` | repository requires its **own** 302 (`conexao_seo_missing_translation_redirect()`), not Polylang's 301 — `docs/routing.md`:67-71 | **PASS** — Polylang's own redirect is off, as the architecture requires |
| domains | `[]` | single host, no per-language domain | **PASS** |

---

## Runtime

| Check | Result |
|---|---|
| Polylang REST namespace | **EXISTS but is not advertised.** `/wp-json/pll/v1` → 200 with 5 routes. The namespace is **absent from the `/wp-json/` index** (22 namespaces, `pll/v1` not among them). Control proves the probe is sound: `/wp-json/zzz/v1/languages` → `404 rest_no_route`. |
| Language records returned | **NONE** — `/wp-json/pll/v1/languages` → `[]` |
| PT vs EN distinguishable | **NO** — `en` and `pt` both → `404 rest_invalid_slug` |
| REST language filtering | **NOT WORKING** — identical totals for all 10 post types |
| Language metadata on content | **ABSENT** — no `language` taxonomy registered (10 taxonomies, none named `language`); no post carries a `lang` value; no Polylang meta key on any record |
| `conexao_language` REST field | **ABSENT** on `pages`, `posts`, `guide`, `event`, `leisure` — because the deployed theme is the `master` build (§B2) |
| Runtime activation of the repo's i18n guard | **PROVEN FALSE-BY-CONFIGURATION.** `conexao_polylang_active()` (`inc/i18n/guard.php`:28-30) tests `function_exists('pll_current_language') && … 'pll_languages_list' && … 'pll_home_url'`. Those three functions **do** exist now, so the guard *would* pass — but every consumer then operates on an **empty** language set. This was **not** inferred from files: the empty `pll/v1/settings`, empty `/languages`, missing `language` taxonomy and `404` on `/languages/pt` are the runtime proof. |

### `<html lang>` and hreflang

| Surface | Observed | Expected |
|---|---|---|
| `<html lang>` on 10 PT pages | **`en-US`** on all of them (`/`, `/guias/`, `/eventos/`, `/lazer/`, `/empregos/`, `/blog/`, `/irlanda/`, `/dublin/`, `/cursos/`, `/apoiadores/`) | `pt-BR` — a bilingual theme emits the per-language locale |
| `hreflang` attributes | **0 total** across all 10 PT pages | `pt-BR` / `en` / `x-default` set (`docs/routing.md`:49, 151-155) |

The `en-US` value is WordPress's `WPLANG` default: with **no** language defined, no
locale filter has anything to switch on.

### Representative routes

| PT route | Status | | EN route | Status | Correct? |
|---|---|---|---|---|---|
| `/` | **200** (`en-US`) | | `/en/` | **301** → `/eventos/encore-halloween-edition-special-guest-1926/` | **NO** |
| `/guias/` | **200** | | `/en/guias/` | **404** | **NO** |
| `/eventos/` | **200** | | `/en/eventos/` | **404** | **NO** |
| `/lazer/` | **200** | | `/en/lazer/` | **301** → `/lazer/` | **NO** |
| `/empregos/` | **200** | | `/en/empregos/` | **301** → `/empregos/` | **NO** |

## Content / translations

Counts are live `X-WP-Total` values from the public REST API, sampled read-only.

| Post type | Records | `?lang=pt` | `?lang=en` | EN records |
|---|---|---|---|---|
| pages | 43 | 43 | 43 | **0** |
| posts | 37 | 37 | 37 | **0** |
| guide | 56 | 56 | 56 | **0** |
| event | 1808 | 1808 | 1808 | **0** |
| leisure | 289 | 289 | 289 | **0** |
| job | 1 | 1 | 1 | **0** |
| sponsor | 10 | 10 | 10 | **0** |
| course_provider | 11 | 11 | 11 | **0** |
| recruitment_agency | 27 | 27 | 27 | **0** |
| permit_employer | 13 | 13 | 13 | **0** |

| Metric | Value |
|---|---|
| PT records | **2285** (sum of the ten published post types) |
| EN records | **0** |
| translation-linked pairs | **0** |
| orphan EN records | **0** (there are no EN records at all) |
| untranslated PT records | **2285 — 100%** |
| PT still canonical | **YES** — all slugs are Portuguese; `?lang=en` returns PT slugs; no EN identity was created |
| unexpected content mutations | **NONE** — counts identical at the start and the end of the audit |

**Taxonomy state**

| Taxonomy | Repository model | Production terms | Production language state | Status |
|---|---|---|---|---|
| `conexao_category` | **translated** | 58 | not translated | **MISMATCH** |
| `conexao_tag` | **translated** | 0 | not translated | **MISMATCH** (empty regardless) |
| `conexao_county` | **shared** | 26 | no language — matches the shared model | **PASS** |
| `conexao_town` | **shared** | 251 | no language — matches the shared model | **PASS** |
| `conexao_leisure_attribute` | shared (outside the four) | 13 | no language | **PASS** |

No term was altered. `docs/content-model.md`:119 documents `conexao_category` and
`conexao_tag` as Polylang-translated; production's `taxonomies: []` contradicts that.

---

## Safety

| Metric | Value |
|---|---|
| production writes performed | **0** |
| content mutations | **0** |
| taxonomy mutations | **0** |
| menu mutations | **0** |
| option / theme-mod mutations | **0** |
| plugin activation / deactivation | **0** |
| Polylang settings saved | **0** |
| rewrite rules modified | **0** (by this audit) |
| translation relationships changed | **0** |
| HTTP methods used | **`GET` only** — no POST/PUT/PATCH/DELETE was ever issued |

Verified by re-reading state at the end of the audit: `pll/v1/settings` byte-identical
to the first read; plugin inventory 18 with identical statuses and versions; every
`X-WP-Total` identical; active theme unchanged.

**One read-side side effect was assessed and ruled out.** The deployed (`master`)
theme's `inc/post-views.php` increments a `_conexao_view_count` post meta row via
`conexao_maybe_count_view()` on `wp` priority 20. It fires only when
`is_singular('post','guide')` **and** the visitor is logged-out **and** the User-Agent
is not a known bot. This audit issued **no singular post or guide request** — only the
home page, archives, pages, 404s, unfollowed redirects and REST endpoints — so no view
counter was incremented. Documented precisely as required.

---

## Language switcher

The repository keeps the switcher disabled through
`CONEXAO_LANGUAGE_SWITCHER_ENABLED = false` (`functions.php`:44; documented at
`docs/themes/conexao-br-irlanda.md`:510-525). Production state:

- **0** switcher markup matches on `/`, `/guias/` and `/empregos/`.
- The constant does not exist in the deployed `master` build (0 occurrences there vs 3
  in `i18n`), so **nothing on production can currently override it** — the disabling
  is vacuously true.
- **PASS**: the switcher is not enabled and was not touched. Recorded so the
  configuration task knows the invariant is preserved by absence of the code, not yet

## Findings

### PASS

| # | Finding | Evidence |
|---|---|---|
| P1 | Production access via the existing project mechanism works | `wp-json` 200; `users/me` 200 |
| P2 | Polylang 3.8.10 is installed and active | `wp/v2/plugins` |
| P3 | Polylang reports no initialisation or configuration error; its REST controller answers 200 on all 5 routes | `/wp-json/pll/v1` |
| P4 | Exactly one Polylang-family plugin; **no Pro and no add-on** | 18-plugin inventory |
| P5 | **No competing multilingual plugin** is active | 18-plugin inventory |
| P6 | Browser negotiation is off, matching the architecture | `browser: false`; `Accept-Language: en-GB` → 200 |
| P7 | Polylang's own 301 language redirect is off, so the repository's own 302 rule stays authoritative | `redirect_lang: false`; `docs/routing.md`:67-71 |
| P8 | `conexao_county` and `conexao_town` carry **no** language — the shared proper-noun model holds | no `language` taxonomy; `docs/content-model.md`:93 |
| P9 | Media is not duplicated per language | `media_support: false` |
| P10 | PT content remains the canonical source; no second identity record was created | `?lang=en` returns PT slugs; 2285 PT records, 0 EN |
| P11 | The language switcher is **not** rendered and cannot be overridden | 0 switcher matches; constant absent from the deployed build |
| P12 | Zero production writes | §Safety |

### WARNING

| # | Finding | Detail |
|---|---|---|
| W1 | `pll/v1` is **not advertised** in the `/wp-json/` index although its routes work | 22 namespaces listed, `pll/v1` absent; `/wp-json/pll/v1` → 200. Benign on its own, but a client enumerating the index will not discover Polylang. Recorded, not fixed. |

### BLOCKER

#### B1 — Polylang is active but has **zero languages**; it cannot create the language layer

**Observed:** `default_lang: ""`, `post_types: []`, `taxonomies: []`, `nav_menus: []`,
`/wp-json/pll/v1/languages` → `[]`, `/languages/pt` and `/languages/en` → `404
rest_invalid_slug`, `language` taxonomy not registered, `?lang=pt` == `?lang=en` for
all ten post types, **0** hreflang site-wide, `/en/guias/` **404**, `/en/eventos/`
**404**, `/en/lazer/` `/en/empregos/` `/en/blog/` **301 → PT**, `/en/` **301 → an
event**.

**Repository expectation:** `docs/routing.md`:42-58 requires a `/en/` layer produced by
Polylang over 48+48 linked EN guides, real EN archives, a shared-slug `/en/empregos/`
and `/en/blog/`; `docs/content-model.md`:119 requires `conexao_category` /
`conexao_tag` to be translated; the i18n guard (`inc/i18n/guard.php`:28-30) gates the
whole bilingual layer on a working Polylang.

**Why this blocks:** an active-but-empty Polylang is the **worst** state to deploy into.
`conexao_polylang_active()` now returns `true` (the three functions exist), so the
theme's bilingual code paths would be live while Polylang has nothing to route with.
Every `/en/` URL is currently either a 404 or a hard 301 to Portuguese — a **301, not
the documented 302** (`docs/routing.md`:67-68).

**What must happen next (NOT done here, requires a separate authorised task):**
1. In wp-admin → Settings → Languages, add **Portuguese (pt)** as the default and
   **English (en)** as a secondary language. This is the only step that changes
   `default_lang` and populates `/pll/v1/languages`.
2. Confirm the URL mode stays **directory / "different languages in directories"** with
   the default language **not** hidden behind a `/pt/` prefix (`rewrite: true` and
   `hide_default: true` are already the intended shape).
3. Confirm **"Translate websites"** stays on (`force_lang: 1`) and **browser detection
   stays off** (`browser: false`) — both already match the architecture.
4. Save. Then re-verify: `/wp-json/pll/v1/languages` returns two records,
   `default_lang` is `pt`, the `language` taxonomy is registered.
5. **Then, and only then**, deal with B2.

#### B2 — the `i18n` bilingual theme is **not deployed**: production runs the `master` build

**New finding** — not identified in the previous production baseline.

**Observed — the deployed theme is byte-identical to `master`, not `i18n`:**

```
GET /wp-content/themes/conexao-br-irlanda/assets/css/main.css
  production : 192920 bytes  md5 a4bab9a49b7dd0bdcce665f2380ce423
  master     : 192920 bytes  md5 a4bab9a49b7dd0bdcce665f2380ce423
  i18n (HEAD): 196327 bytes  md5 a367b7950e0511958a3768d284a4d35b
```

Confirmed by file-presence probes — the master-only `inc/seo.php` is **200**, while
every i18n-only file 404s: `inc/events.php`, `inc/queries.php`, `inc/navigation.php`,
`inc/leisure.php`, `languages/en_US.mo`. Theme file counts: `master` 81, `i18n` 151.
`git ls-tree` confirms `master` has **no** `inc/i18n/` directory and **no**
`inc/rest-language.php` at all.


## Repository expectation vs production reality

| # | Repository expectation | Production reality | Reference |
|---|---|---|---|
| 1 | Polylang supplies the `/en/` layer | Polylang active but **zero languages** | `docs/routing.md`:42-58 |
| 2 | PT default, EN secondary | `default_lang: ""` | `docs/routing.md`:49-57 |
| 3 | `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` translated | `post_types: []` | `inc/i18n/guard.php`:55 |
| 4 | `conexao_category` + `conexao_tag` translated; county/town shared | `taxonomies: []` (county/town shared by absence, which is correct) | `inc/i18n/guard.php`:99; `docs/content-model.md`:119 |
| 5 | `conexao_language` on every REST record | **absent** — deployed theme has no `inc/rest-language.php` | `inc/rest-language.php`:465-560 |
| 6 | `<html lang="pt-BR">` on PT pages | **`en-US`** on all 10 sampled | `docs/routing.md`:151-155 |
| 7 | `hreflang` `pt-BR`/`en`/`x-default` | **0 tags** | `docs/routing.md`:49 |
| 8 | `/en/guias/`, `/en/eventos/` → 200 EN archives | **404** | `docs/routing.md`:49-51 |
| 9 | Untranslated content → **302** to PT | **301** to PT (4 routes) / 404 (2 routes) | `docs/routing.md`:67-68 |
| 10 | switcher off via `CONEXAO_LANGUAGE_SWITCHER_ENABLED = false` | **no switcher rendered**; the constant does not exist in the deployed `master` build | `functions.php`:44; `docs/themes/conexao-br-irlanda.md`:510-525 |
| 11 | Browser detection off | `browser: false`, confirmed at runtime | PASS |

---

## Final readiness decision

# BLOCKED — POLYLANG CONFIGURATION NOT READY

This decision is made **only** from observed production state and documented
repository requirements, not from opinion.

Polylang's *installation* prerequisite is now satisfied — but **configuration** is not,
and configuring it now would not leave the architecture working, because a second
independent blocker (B2) means the consuming code is not deployed. The minimum
required next actions, in dependency order:

1. **Authorise and deploy the `i18n` theme build** (`conexao-br-irlanda` 1.0.1) —
   in place, without deactivating `conexao-content`. *Required first: it is what makes
   any Polylang configuration meaningful.*
2. **Add PT (default) + EN (secondary) in Polylang's Languages settings**, keeping
   directory URL mode, default language unprefixed, browser detection off and
   "translate websites" on.
3. **Re-run this audit's checks** to prove: `/wp-json/pll/v1/languages` returns two
   records, `default_lang` is `pt`, the `language` taxonomy exists, `conexao_language`
   appears on REST records, and `?lang=pt` != `?lang=en`.
4. **Only then** run the EN translation rollout stages
   (`scripts/run-en-translation.php`, six-step content contract, dry-run first).

Because the decision is **BLOCKED**, there is **no** "next step is a separate
authorised configuration task" to announce: configuration must not start yet. If B2
were resolved and B1 re-verified, the next step would be a **separate, explicitly

## 1. Scope completed

Production access verified; Polylang installed/active/version/health determined;
Polylang configuration read in full; runtime language behaviour tested through the
REST index, Polylang REST endpoints and public HTTP; frontend `/`, `/en/` and the
five required PT/EN archive pairs probed; content and translation state counted;
taxonomy configuration compared with the repository model; the language-switcher state
confirmed; configuration conflicts swept; repository expectations compared with
production; write protection proven. Zero writes.

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| WordPress / PHP version | not exposed on the read-only REST surface |
| Whether the authenticated account holds admin capabilities | `users/me` returns an empty capabilities map on WordPress.com; plugin/theme endpoints proved sufficient read access instead |
| The exact expected values for Polylang `force_lang` and `nav_menus` | **expected value not established by repository documentation** — recorded as observed, not guessed |
| Any configuration, save, activation, translation or write | the task is audit-only |

## 3. Files added / modified / deleted

**3 added**, **0 modified**, **0 deleted**. No `wp-content/` change, no source change,
no commit. HEAD unmoved at `33114c6`.

| Path | Change |
|---|---|
| `docs/reports/2026-09-29-polylang-production-audit.md` | added (this report) |
| `docs/evidence/2026-09-29-polylang-production-audit/00-polylang-production-audit.json` | added (machine evidence) |
| `docs/evidence/2026-09-29-polylang-production-audit/01-raw-responses.md` | added (raw sanitized responses) |

## 4. Runtime impact

**None.** Nothing was deployed or configured. Production behaves exactly as before this
audit — and that behaviour is documented above as the finding.

## 5. Content / data impact

**None.** 2285 PT records, 0 EN records, counts identical at the start and the end of
the audit. No content, taxonomy, menu, option or theme mod was written.

## 6. Polylang impact

**None.** No Polylang setting was saved; `pll/v1/settings` is byte-identical to the
first read. PT immutability: **PT drift = 0**. EN records created: **0**.
Completeness gate: **not applicable** (no language layer exists to gate).

## 7. Route / HTTP impact

**None** — no routing, rewrite, redirect or Polylang setting was touched. The HTTP
matrix in §Runtime is a **baseline observation**, not a change.

## 8. Production actions


## 9. Verification commands and results

| Command | Result |
|---|---|
| `curl GET /wp-json/` | 200, 22 namespaces, `pll/v1` absent |
| `curl GET /wp-json/wp/v2/users/me` (auth) | 200, id `283039558`, `conexaobradmin` |
| `curl GET /wp-json/zzz/v1/languages` | **404 `rest_no_route`** — control |
| `curl GET /wp-json/pll/v1` | 200, **5 routes** |
| `curl GET /wp-json/pll/v1/languages?nonce=…` | **200 `[]`** (`no-store`, `BYPASS`) |
| `curl GET /wp-json/pll/v1/languages/{en,pt}` | **404 `rest_invalid_slug`** |
| `curl GET /wp-json/pll/v1/settings` (auth) | 200, `default_lang:""`, `post_types:[]`, `taxonomies:[]` |
| `curl GET /wp-json/wp/v2/plugins?per_page=100` (auth) | 200, **18 plugins**, Polylang `active 3.8.10` |
| `curl GET /wp-json/wp/v2/themes` (auth) | 200, `conexao-br-irlanda` **active** |
| 12-path frontend matrix (redirects not followed) | 12/12 probed; `/en/guias/` 404, `/en/eventos/` 404, 4× 301, `/en/` 301 → event |
| hreflang count on 10 PT pages | **0** |
| `Accept-Language: en-GB` on `/` | 200, no redirect |
| REST `X-WP-Total` × 10 post types × 3 variants | identical for `lang=pt` / `lang=en` |
| theme `main.css` md5 vs `master` / `i18n` | production == **master** |
| 6 theme file-presence probes | `inc/seo.php` 200; all 5 i18n-only files 404 |
| End-of-audit state re-read | settings, plugins, totals, theme **all unchanged** |

### Numeric results

- Polylang languages configured: **0** (architecture requires 2).
- Translated post types: **0** (requires 6). Translated taxonomies: **0** (requires 2).
- PT/EN translation-linked pairs: **0**. EN records: **0**. Untranslated PT: **2285 (100%)**.
- `hreflang` attributes on 10 PT pages: **0**.
- `/en/` routes behaving as English: **0 of 6**.
- Production writes: **0**.

Static analysis, in-process tests, script contracts and HTTP acceptance suites: **not
run** — this was a production read-only audit with no code change, so those gates have
no bearing on the findings and were deliberately not executed.

## 10. Failure proofs / negative tests

The **bogus-namespace control** (`/wp-json/zzz/v1/languages` → `404 rest_no_route`)
proves the audit's namespace probe can and does report absence; without it, "the
namespace exists" could have been an artefact of a permissive router. The
**cache-busting nonce + `no-store`/`BYPASS` headers** prove `[]` is live state and not a
stale CDN edge. The **md5 comparison against both branches** proves the theme
fingerprint actually discriminates `master` from `i18n` rather than merely reporting a
number.

## 11. Regression comparison

Not applicable — no change was made. The end-of-audit state re-read is the proof that
nothing moved.

## 12. Known pre-existing failures

- The `/en/` → event 301 and `/blog/` canonical-to-home were present in the previous
  production baseline and are **not** caused by this audit or by Polylang.
- Production runs the `master` theme build; that predates this audit.

## 13. Limitations

- WordPress and PHP versions could not be read, so Polylang's `requires_wp 6.5` /
  `requires_php 7.4` is supported by behavioural evidence (routes register, no error)
  but not by a version proof.
- Admin capability could not be read directly (`users/me` capabilities are empty on
  WordPress.com); this blocked no check.
- **Expected value not established by repository documentation** for Polylang's
  `force_lang` and `nav_menus` — reported as observed, not guessed.
- Findings rest on point-in-time reads of a CDN-fronted production site; each
  conclusion was re-read with a cache-busting nonce.

## 14. Evidence paths

- `docs/evidence/2026-09-29-polylang-production-audit/00-polylang-production-audit.json`
  — machine-readable audit result (access, Polylang, runtime, content, frontend
  matrix, artifact fingerprint, write protection).
- `docs/evidence/2026-09-29-polylang-production-audit/01-raw-responses.md`
  — verbatim sanitized `GET` responses backing every number in this report.

## 15. Documentation updated

- `docs/reports/2026-09-29-polylang-production-audit.md` — this report.
- `docs/reports/README.md` — index row added.
- `docs/evidence/2026-09-29-polylang-production-audit/` — new evidence directory.
- No `wp-content/` documentation changed: this audit changed no behaviour, so no
  architecture document is restated.

## 16. Rollback / recovery

Nothing to roll back — zero production changes. Removing this report and its evidence
directory fully reverts the task.

## 17. Final status

**BLOCKED** — Polylang is installed and active but has zero languages, and the `i18n`
theme build that consumes Polylang is not deployed; neither blocker can be resolved
without an explicitly authorised configuration/deployment task.

_Last verified: 2026-09-29 by the production Polylang configuration audit (read-only)_

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | GET only, 0 writes |
| Deploy / upload | **no** | |
| Plugin activation / deactivation | **no** | |
| Polylang configuration or save | **no** | audit only |
| Content or DB mutation | **no** | |
| Taxonomy / menu / option mutation | **no** | |
| Staging→production DB sync | **no** | forbidden and not done |
| Local commit | **no** | HEAD unchanged |
| Flutter/mobile repository | **no** | out of scope, not accessed |

---

authorised Polylang configuration task** — never an automatic configuration during an
audit.

---

**Runtime consequence:** the `conexao_language` REST field — declared in
`inc/rest-language.php`:465-560, which returns early unless
`conexao_polylang_active()` — is **absent from every record**. That absence is fully
explained by the missing file, independent of B1.

**Why this blocks:** configuring Polylang languages (B1 step 1) **without** deploying
the `i18n` theme would leave `/en/` 404ing and would produce a site where Polylang is
configured but no repository code consumes it — the `pll_get_post_types` /
`pll_get_taxonomies` declarations, hreflang, the 302 rule, the shared-slug resolver,
the rewrite self-heal and the switcher flag all live in the undeployed build. **B1 and
B2 must be sequenced deliberately, not independently.**

**What must happen next (NOT done here):**
1. Decide and authorise the theme release for the `i18n` build (`conexao-br-irlanda`
   **1.0.1**, per `docs/reports/2026-09-29-release-identity.md`) as its own
   explicitly authorised deployment task.
2. Deploy it **in place** — do **not** deactivate/activate `conexao-content` in the
   same change: its activation hook is destructive (`wp_delete_post()` on every menu
   item — see the activation audit in the 2026-09-29 readiness report).
3. Re-verify that `conexao_language` appears on REST records; that is the positive
   proof the `i18n` build is live.

---

| W2 | Several Polylang settings have **no repository-defined expected value** | `force_lang: 1`, `nav_menus: []` — **expected value not established by repository documentation**. Not changed; flagged so the configuration task does not assume them. |
| W3 | WordPress and PHP versions are **not obtainable** read-only | Cannot confirm Polylang 3.8.10's `requires_wp 6.5` / `requires_php 7.4` is satisfied. The plugin loads and its REST routes register, which is behavioural evidence of compatibility, but not a version proof. |
| W4 | The `/en/` → a single *event* record 301 is anomalous | `X-Redirect-By: WordPress`, target `…/eventos/encore-halloween-edition-special-guest-1926/`. Pre-dates this audit (the 2026-09-29 baseline recorded the same target). Not a Polylang behaviour. |
| W5 | `/blog/` serves canonical `https://conexaobr.ie/` | Observed while probing; pre-existing and unrelated to Polylang. |
| W6 | CDN returns `STALE` for some anonymous HTML reads | `x-ac: … STALE`. Every finding resting on a response was therefore re-read with a random cache-busting nonce and a `BYPASS`/`no-store` response header. |

---

  by the flag itself; once the `i18n` theme is deployed the constant takes over.

---

| `/blog/` | **200** | | `/en/blog/` | **301** → `/blog/` | **NO** |

**No `/en/…` route is interpreted as English.** All six fail: two 404, four 301 to a
Portuguese URL, and `/en/` 301s to a single unrelated *event* record. Every redirect
carries `X-Redirect-By: WordPress` — none is issued by Polylang.

---
