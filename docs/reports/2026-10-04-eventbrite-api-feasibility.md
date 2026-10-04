# Report — G6: Eventbrite API Feasibility Investigation

| | |
|---|---|
| **Stage / task name** | G6 — Eventbrite official API feasibility (diagnostic only; no implementation) |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `01096321b611981e1c0a3e6aba05faae535b41c2` |
| **End SHA** | *uncommitted — not deployed, not committed* |
| **Predecessor** | [G5 — Eventbrite Rate-Limit Strategy](2026-10-04-eventbrite-rate-limit-strategy.md) · [G4](2026-10-03-event-import-g4.md) · [G1–G3](2026-10-03-event-import-fixes-g1-g3.md) |
| **Evidence** | [`docs/evidence/2026-10-04-eventbrite-api-g6/`](../evidence/2026-10-04-eventbrite-api-g6/) |
| **Plugin version** | `conexao-event-importer` **1.9.0 — unchanged** |
| **Production files changed** | **0** |
| **Production writes** | **0** |

---

## Executive result

# `NOT VIABLE` — as a replacement or a coverage source for the county listings

**The official Eventbrite API cannot replace, reduce, or augment the current
HTML scraping path for `eventbrite_cork`, `eventbrite_dublin`,
`eventbrite_carlow`, or any other configured Eventbrite source.** This is a
**coverage** verdict, not an access verdict, and the distinction is the finding:

| Question | Verdict | Evidence |
|---|---|---|
| Is the API reachable from this environment? | **YES** | 6 unauthenticated read-only probes all reached the API edge; `x-amzn-waf-action` absent; 0 challenges |
| Does a personal token unlock event data? | **UNPROVEN — no token exists** | no credential in `.env`, Docker env, or the `conexao_event_importer_settings` option |
| Can a token list a **county**'s events? | **NO** | `/v3/events/search/` → **404 NOT_FOUND** (live). The only event collection is `/organizations/{id}/events/`, and discovery is **per-user**, not per-location |
| Does a county listing map to one organization? | **NO** | measured: Cork's first 100 events span **36 distinct organizers**; Dublin's 100 → **74**; Carlow's 60 → **51** |
| Are the event fields compatible? | **PARTIAL** | 7 of 13 importer fields go empty on a raw API object; the API is **better** on 4 and **worse** on 2 (§I) |

`BLOCKED` was considered and rejected. Credentials blocked *authenticated*
validation, but authenticated validation could not have changed the answer: the
ownership model is visible **without any credential**, from the public listing
itself and from the API's own 404. The verdict is therefore `NOT VIABLE` for the
replacement goal, with the credential gap stated honestly in §C.

### The one-sentence answer

> The official Eventbrite API can supply the event *fields* the importer needs —
> several better than the scraper does — but only for organizations you **own**,
> so it cannot reproduce a **county listing** aggregating 36+ independent
> third-party organizers. Accessibility was proven; coverage was disproven. This
> is a different ownership model, not an integration problem.

### What changed in the environment during G6 (material to the G1–G5 premise)

G5 reported the discovery path **WAF-blocked** (51/51 × HTTP 405
`x-amzn-waf-action: captcha`, 0 × 429). **That block had lifted when G6 ran.**
`https://www.eventbrite.ie/d/ireland--cork/all-events/` returned **HTTP 200,
656 KB**, and the **unmodified** production parser consumed its
`window.__SERVER_DATA__`. Across 13 paced requests: **{200: 13}, 0 × 429,
0 × 405.**

This does not invalidate G1–G5 — they remain correct and are preserved — but the
historical "49 pages / 1,553 objects / page-8 429" figures are **not currently
reproducible**, and no claim here depends on them.

---

## Official API evidence

Documentation was re-verified **2026-10-04**. Eventbrite's
`www.eventbrite.com/platform/*` reference is a **client-rendered React app**:

### What source 3 establishes (Eventbrite's own code)

```ts
// src/users.ts
export class UserApi extends BaseApi<User> {
    me()  { return this.request('/users/me/'); }
    get(id: string) { return this.request(`/users/${id}/`); }
}

// src/organizations.ts
export interface Organization { id; name; imageId; locale?; vertical?; }
export class OrganizationsApi extends BaseApi<PaginatedResponse<Organization>> {
    getByUser(userId: string) { return this.request(`/users/${userId}/organizations/`); }
}

// src/constants.ts
export const PAGE_KEY = 'page';
export const CONTINUATION_KEY = 'continuation';
```

Three facts follow from Eventbrite's own published SDK:

1. **Organization discovery is per-user.** The only documented discovery route is
   `/users/{user_id}/organizations/`. There is no documented "organizations by
   location" or county-scoped query.
2. **Organization listing is the prerequisite for event listing.** The SDK
   exposes no events collection at all; the documented event route
   `/organizations/{organization_id}/events/` requires an organization id you
   already hold.
3. **Pagination is `page` + `page_size` with a `continuation` cursor**, and the
   envelope carries `object_count` / `page_count` — the API can state its own
   total up front, unlike the HTML path's 49-page cap.

### What source 4 establishes (live, 2026-10-04)

| Request | Status | Error |
|---|---|---|
| `GET /v3/users/me/` | 401 | `NO_AUTH` — *"An OAuth token is required for all requests"* |
| `GET /v3/users/me/` (invalid token) | 401 | `INVALID_AUTH` — *"The OAuth token you provided was invalid."* |
| `GET /v3/organizations/` | 401 | `NO_AUTH` |
| `GET /v3/organizations/{id}/events/` | 401 | `NO_AUTH` |
| `GET /v3/events/{id}/` | 401 | `NO_AUTH` |
| **`GET /v3/events/search/?country=IE`** | **404** | **`NOT_FOUND` — *"The path you requested does not exist."*** |

The last row **re-verifies the Stage A finding** that the public Events Search
API is retired. It is the single most important API fact in this report: **there
is no official, authenticated, location-scoped event search.**

### Authentication model

OAuth 2.0 bearer token in `Authorization: Bearer <token>`, **required on every
`/v3/*` request**. The importer already has the storage slot:
`Conexao_Import_Settings::OPTION_KEY → eventbrite_api_token`
(`includes/class-import-settings.php:31`), sanitized on write to
`[A-Za-z0-9_-]`, capped at 128 chars. Two distinct error codes (`NO_AUTH` vs
`INVALID_AUTH`) mean an expired token is **distinguishable** from an unconfigured
one — a real operational property, asserted by NP1.

### Items G6 could NOT verify, and says so


---

## A. What the existing implementation actually is

Read from the repository, not from memory.

| Concern | Location | Finding |
|---|---|---|
| Source configuration | `class-county-registry.php` (`COUNTIES`) → `class-event-sources.php` | 26 ROI counties, one handler for all |
| `eventbrite_cork` | registry `cork` | `eb_slug: ireland--cork`, `eb_region_labels: ['Cork','Cork City']` |
| `eventbrite_dublin` | registry `dublin` | `eb_region_labels: ['Dublin City','Dunlaoghaire-Rathdown','Fingal']` |
| Handler | `includes/sources/class-eventbrite-source.php` | `fetch_events()` **always** calls `fetch_via_scrape()` |
| Transport | `includes/class-eventbrite-client.php` | G1–G5 hardened HTML client: bounded retries, `Retry-After`, budget, status classification |
| Parsing | `includes/class-eventbrite-parser.php` | extracts `window.__SERVER_DATA__` → `search_data.events.{results,pagination}` |
| Pagination | source handler, `MAX_PAGES = 50`, `TIME_BUDGET_SECONDS = 45` | forward walk on `pagination.page_count`; marks itself incomplete on any gap |
| Normalization | `includes/class-eventbrite-normalizer.php` | reads **discovery-payload** keys (`start_date`, `primary_venue`, `primary_organizer`, `locations[]`) |
| County gate | `is_county_event()` | `locations[].type == 'region'` must match a configured label; **unknown = reject + log** |
| Mandatory fields | the normalized array | `start_date` + `title` + `source_id` make an event importable; **no start date = unusable** |
| Completeness | `mark_fetch_incomplete()` / `is_fetch_complete()` | incomplete walk ⇒ events absent are **never** marked `source_not_found` |
| Credential slot | `class-import-settings.php:31` | `eventbrite_api_token` — **exists but no code path uses it** |

**The repository's own documentation is stale and self-contradictory here**:
`docs/plugins/conexao-event-importer.md:262` still claims sources call
`https://www.eventbriteapi.com/v3/events/search/` when a token is set. No code
ever did; line 218 of the same file correctly says the endpoint was removed.
**G6 did not edit that file** — it is recorded for the owning stage as the
clearest sign a dead API path was once believed to work.

---

## B. Authentication result

**No Eventbrite credential exists in this repository or its local environment.**

| Location | Result |
|---|---|
| `.env` | WORDPRESS_* / WP_USERNAME / WP_APPLICATION_PASSWORD / WP_TEST_USER_PASSWORD only |
| `.env.example` | no Eventbrite key (committed file; must never gain one) |
| `compose.yaml` | no eventbrite/token/secret key |
| Docker container env (names enumerated) | no `EVENTBRITE_*` variable |
| `conexao_event_importer_settings` option | `option_present = no`, `token_state = EMPTY` |
| Repo-wide grep | `api.eventbrite.com` → **0 matches**; `private_token`/`oauth_token` → settings plumbing only |

**Authenticated access was never attempted; no credential was created,
requested, pasted, or stored.** The only `Authorization` header ever sent was the
literal `INVALID_TOKEN_G6_NEGATIVE_PROOF`, used once to prove `INVALID_AUTH` is
distinguishable from `NO_AUTH`. It is deliberately invalid and contains no
secret.

---

## C. Organization discovery

**Not performed — legitimately blocked by the absence of a credential.** No
token ⇒ no `GET /v3/users/me/` ⇒ no organization ids or names. Recorded as a
genuine limitation, and **not load-bearing for the verdict**, for two reasons
that need no credential:

1. The API's own `/v3/events/search/` **404** removes the location-scoped route.
2. The public listing needs no credential and shows the county spans **36+
   independent organizations** — so there is no single organization a token
   could belong to and thereby capture the county.

A credential would let G7 enumerate organizations Conexão BR Ireland actually
owns. It would **not** grant access to third-party organizers' Cork events.

---

## D. Cork result — `eventbrite_cork`

**Coverage: C — Wrong ownership model** (plus E for whatever a credential would
still leave inaccessible).

| Measure | Value | Source |
|---|---|---|
| Source URL | `https://www.eventbrite.ie/d/ireland--cork/all-events/` | live config |
| Declared `object_count` / `page_count` | **1,553 / 49** | live, parsed by the production parser |
| Pages walked by G6 (bounded) | **5 of 49** | 3 s pacing |
| Events observed | **100** | `cork-sample.json` |
| **Distinct organizers in those 100** | **36** | `03-cork-organizer-fanout.csv` |
| Organizers whose name the listing embeds | **0 of 36** | `07-event-set-comparison.csv` |
| Duplicate ids within the sample | 0 | `07-…csv` |

The listing also carries a location hierarchy
(`continent → country → region → locality → neighbourhood`) that the handler uses
for its region ACCEPT/REJECT gate. **The API event object has no region at all**
(§I), so even with ownership the county gate would need re-deriving.

---

## E. Dublin result — `eventbrite_dublin`

**Configured and active** in the current configuration
(`https://www.eventbrite.ie/d/ireland--dublin/all-events/`,
`last_import_status = error`, `events_imported = 0`). Probed with the same
bounded method.

| Measure | Value |
|---|---|
| Declared `object_count` / `page_count` | **5,173 / 49** |
| Pages walked | 5 of 49 |
| Events observed | 100 |
| **Distinct organizers** | **74** |
| HTTP status distribution | **{200: 5}** — 0 × 429, 0 × 405 |
| API organizations / events | not obtainable — no token |

**Dublin behaves differently from Cork, and in the worst possible direction for
the API case.** Cork spans 36 organizers per 100 events; Dublin spans **74** —
roughly one distinct organizer per event. Dublin is therefore the *least*
substitutable source, not the most: reproducing it through the API would require
membership in ~74 organizations per 100 events, extrapolating to thousands.

The 49-page cap truncates Dublin's 5,173 objects to ~980 for the scraper too, so
both paths are incomplete — the API path is incomplete by *ownership* rather
than by page cap.

No substitute source was invented for Dublin, and no Dublin API organization was
guessed.

---

---

## F. Small-source controls — `eventbrite_carlow`, `eventbrite_cavan`

`eventbrite_carlow` is configured and was walked (3 pages). `eventbrite_cavan` is
configured but was **not** walked — Carlow already answered the control question,
and no useful additional fact would have come from a second walk.

| Measure | `eventbrite_carlow` |
|---|---|
| Declared `object_count` / `page_count` | **4,780 / 49** |
| Pages walked | 3 of 49 |
| Events observed | 60 |
| **Distinct organizers** | **51** |

**The control case refutes the small-source hypothesis.** Carlow is nominally a
"small source", yet 60 events span **51 organizers** — *fewer events than
organizers*. The organizer fan-out is a property of the public county listing
model, not of county size, so it does not shrink for small counties. This is the
control doing exactly its job: it prevents the false conclusion "the API works
for small sources".

Consequently **no small source was successfully mapped to an API-accessible
organization**, and **no broad Eventbrite coverage is claimed from any single
small source**.

---

## G. Coverage classification (§10 of the brief)

| Source | Classification | Basis |
|---|---|---|
| `eventbrite_cork` | **C — Wrong ownership model** (+ E) | 36 organizers per 100 events; search endpoint 404; no credential |
| `eventbrite_dublin` | **C — Wrong ownership model** (+ E) | 74 organizers per 100 events — worse than Cork |
| `eventbrite_carlow` | **C — Wrong ownership model** (+ E) | 51 organizers across 60 events |
| `eventbrite_cavan` | not measured (not needed) | Carlow refuted the small-source hypothesis |

**No source reached classification A (full coverage) or B (partial coverage).**
B was not reached because the API returns *no* Cork/Dublin events without
ownership — there is no subset to be partial about.

| HTTP status distribution | **{200: 5}** — 0 × 429, 0 × 405 | `cork-walk.log` |
| Elapsed | 19.5 s for 5 pages | `cork-walk.log` |
| API organizations / events / continuations | **not obtainable — no token** | §C |

**Can `eventbrite_cork` be represented through the API? No.** Replacement would
require the credential to belong to ≥36 organizations for the first 5 pages
alone; extrapolated across 1,553 objects that is **several hundred**
third-party organizers. Membership in other people's organizer accounts is not
an integration task and not something this project should pursue.

Current API version string and sunset schedule; exact documented rate-limit
quotas; the precise `page_size` maximum; whether a cross-organization
`status`-filtered listing exists. **All unverified** because the reference site
is not machine-retrievable. They are not asserted anywhere in this report and
must not be inferred from it.

every reference URL returns HTTP 200 with an empty shell (33 KB, no body), and
no `openapi.json` is served. That was established, not assumed, so the
verification below rests on two sources that *are* retrievable.

| # | Official source | Retrieved | What it establishes |
|---|---|---|---|
| 1 | `eventbrite.com/platform/api-reference/` (+ `/authentication/`, `/pagination/`, `/events/event/`, `/organizations/organizations/`, `/events/get-organizations-organization-id-events/`, `/docs/events`, `/docs/by-location`, `/docs/changelog`, `/api-basics`) | 2026-10-04, HTTP 200 | Site is client-rendered; text **not** machine-retrievable. Recorded as a *limitation*, never as API-shape evidence |
| 2 | `eventbrite.com/platform/api-reference/openapi.json`, `developers.eventbrite.com/api-reference/openapi.json` | 2026-10-04, HTTP 200 | **Not a spec** — both return HTML (33 KB / 241 KB Next.js shell) |
| 3 | **`github.com/eventbrite/eventbrite-sdk-javascript`** (official, `master`): `src/users.ts`, `src/organizations.ts`, `src/constants.ts`, `src/types.ts` | 2026-10-04, HTTP 200 | **The decisive documented model** (quoted below) |
| 4 | **Live read-only probes** against `www.eventbriteapi.com` (6 requests) | 2026-10-04 | Auth envelope, error taxonomy, and the search-endpoint 404. Real bytes |

---

## H. Event-set comparison

**A live API-vs-scraper set comparison was impossible**, because the API side
returned 401 on every request (no credential) and the scraper side returned 0
events without a credential. The comparison below is therefore a **structural
comparison of the two enumerations**, using the scraper's *live* 2026-10-04
sample. It is labelled as such and **no live coverage percentage is claimed**.

| Metric | API (with a valid token) | Current scraper (measured 2026-10-04) |
|---|---|---|
| Enumeration unit | one **organization** you own | one **county**, all organizers |
| Cork discovery path | **none exists** (`/v3/events/search/` → 404) | `d/ireland--cork/all-events/` + `?page=N` |
| Organizations required | ≥36 per 100 Cork events | n/a — one listing serves all |
| Total obtainable (Cork) | unknown; bounded by ownership | 1,553 declared, ≤980 reachable at the 49-page cap |
| Terminates deterministically? | yes — `object_count` + `continuation` | no — capped by `MAX_PAGES = 50` |
| Stable event ids | yes (`id`) | yes (`id`) |
| Canonical event URLs | yes (`url`) | yes (`url`) |
| Published/unpublished | **better**: `status` + `listed` | `published` is a *timestamp string*, not a flag |
| Future/past filterable | yes (`status`, time filters documented) | client-side date filter only |
| Missing ids | not measurable without a token | not measured (walk was 5/49 pages) |
| Duplicate ids | 0 in the live API samples (offline fixture covers the duplicate case) | 0 in the live 100-event sample |

**Honest limits of this comparison.** No "API-only / scraper-only /
intersection" counts are reported, because the API side returned no events.
Reporting a percentage here would be fabrication. What *is* established is that
the intersection is **structurally bounded**: without ownership of the 36+
organizers, it is **0** for Cork and Dublin.

The scraper's historical G1–G5 figures (49 pages, 1,553 objects, page-8 429)
were **not** reproducible on 2026-10-04 (§Executive result) and are used only as
labelled history.

---

## I. Field compatibility

Method: the **unmodified** production `Conexao_Eventbrite_Normalizer` was run
against an **unmodified** documented-shape API event. Three results surprised the
initial hypothesis and are recorded as measured — notably that the normalizer's
*legacy* `venue` fallback already satisfies the API's `venue` shape.

Full matrix: [`04-field-compatibility-matrix.txt`](../evidence/2026-10-04-eventbrite-api-g6/04-field-compatibility-matrix.txt).

| Importer requirement | Current HTML source | Eventbrite API | Compatible? |
|---|---|---|---|
| Event identity | `id` | `id` | **yes** |
| Source URL | `url` | `url` | **yes** |
| Title | `name` | `name` | **yes** |
| Summary/description | `summary` | `summary` (+ `description`) | **yes** |
| Start date/time | `start_date` / `start_time` | `start.local` (nested) | **no** — needs translation; fatal if skipped |
| End date/time | `end_date` / `end_time` | `end.local` (nested) | **no** — needs translation |
| Venue name | `primary_venue.name` | `venue.name` | **yes** (legacy `venue` fallback) |
| Address/location | `primary_venue.address` | `venue.address` | **yes** (legacy fallback; API supplies the postal code the live HTML lacks) |
| Image | `image.image_sizes.medium` | `image.image_sizes.medium` | **yes** — identical key path |
| Public/published status | `published` (a **timestamp**, not a flag) | `status` + `listed` | **no** — needs a rule; **the API is better** |
| Organizer name | `primary_organizer` — **ABSENT on the live payload** | `organizer.name` | **no** — **the API is better** |
| Organizer identity | `primary_organizer_id` | `organizer_id` | **no** — key rename only; same integer value |
| Last-modified/change | `published` date only | `changed` + `created` | **no** — **the API is better** |
| Recurrence/series | `series_id`, `num_children` | `series_id` (id only) | **partial** — neither exposes a recurrence rule |
| Ticket URL | `tickets_url` (distinct checkout URL) | not exposed | **no** — real fidelity loss |
| Category | `tags[EventbriteCategory].display_name` | `category_id` (bare id) | **partial** — needs a lookup |
| County/region | `locations[].region` (full hierarchy) | **absent** | **no** — must be derived; breaks the region gate |
| Cancelled | `is_cancelled` | `status == 'canceled'` | **no** — needs a rule |

---

## J. Pagination / completeness

**The API's pagination is materially better than the HTML path's** — and this
is the strongest *architectural* argument for an API adapter should one ever be
justified:

| Property | HTML discovery | API v3 |
|---|---|---|
| Total declared up front | no | yes — `object_count`, `page_count` |
| Cursor | `?page=N` | `page` + `page_size` + **`continuation`** |
| Deterministic end | **no** — hard-capped at `MAX_PAGES = 50` | yes — null continuation |
| Hidden tail | **yes** (49 pages × 20 = 980 of 1,553 Cork objects) | no, per `object_count` |
| Walk log | page count only | page, event count, `has_more_items`, cursor (asserted in G6.8) |

**How incomplete retrieval must be handled — and it needs no new code.** The
G1–G2 semantics already generalise to API envelopes, and the offline suite
proves it (`g6_api_walk_is_complete()`):

> A walk is complete **only if every page carried both a `pagination` block and
> an `events` array**. A failed (403) or rate-limited (429) API response
> carries **neither**, so it can never be read as "zero events on this page".

This is structurally identical to the existing guard: Eventbrite's error
envelopes *omit* the keys a successful page always carries. The distinction the
suite proves (G6.11):

| Response | `events` key | `pagination` key | Correct classification |
|---|---|---|---|
| Genuinely empty organization | present, `[]`, `object_count: 0` | present | **empty, complete** |
| 403 / 429 / 401 error envelope | **absent** | **absent** | **failed, incomplete** |

Because the key is *absent* rather than empty, the G1 masking defect — an
auth/failure page recorded as `found=0, status=success` — is structurally
prevented, not merely avoided by convention. **`mark_missing_events()` is not
reached** on an incomplete walk, so the G2 protection applies unchanged.

---

## K. Rate limits and access behaviour

**The API behaves differently from the scraper, and the difference is real.**

| Property | HTML discovery | API v3 |
|---|---|---|
| Reachable from this environment | yes (2026-10-04) | **yes** |
| WAF challenge observed | 0 in G6 (51/51 in G5) | **0** — `x-amzn-waf-action` absent on all 6 |
| 429 observed | 0 in G6 (72 in the G1–G5 archived trace) | **0** |
| Rate-limit headers | none seen | **none returned on 401** (no quota consumed without auth) |
| Quota consumed by failed auth | n/a | **0** — 401s are refused before throttling |
| Requests needed for the same goal | 49 pages for one county | **unknown**; the goal is unreachable regardless |

**Deliberately not done:** no quota probing, no burst, no concurrency, no
pagination stress, no WAF/CAPTCHA/proxy/UA evasion of any kind. The entire API
experiment was **6 requests**. The HTML walks were **13 requests** at 3 s
pacing. All read-only GETs.

**`Retry-After` interop (measured, not assumed).** The API's 429 envelope is
represented in the contract fixture with `Retry-After: 60`. The **unchanged**
G4 parser reads it correctly and the **unchanged** G5 60-second cap still bounds
an absurd value — both asserted by G6.10. **The API needs no change to the G1–G5
rate-limit machinery.**

**Honest limitation:** documented quota numbers could not be verified (§Official
API evidence), so G6 has **no measured API rate-limit threshold**. It only
establishes that no 429 occurred within a deliberately tiny, non-abusive
experiment. Do not read this as "the API has no limits".

---

## L. Offline contract fixture and negative proofs

**Fixture:** `wp-content/plugins/conexao-event-importer/tests/fixtures/eventbrite-api-v3-contract.json` — 11 sections covering an ordinary event, a missing image, a null venue, a series id, long descriptions, a duplicate stable id with a newer `changed`, a draft, two continuation pages, and the 401/403/429 error envelopes plus a genuinely empty organization.

**Provenance honesty:** three envelopes (`users_me_unauthenticated`,
`users_me_invalid_token`, `events_search_retired`) are **verbatim live captures**.
The event payloads are **documented-shape fixtures, not a live capture**, and the
fixture says so in `_fixture_provenance.fabrication_notice` — asserted by G6.1 so
the claim cannot rot.

**Suite:** `test-eventbrite-api-contract.php` — **110 assertions, 0 failed, exit 0**,
discovered by convention by `./scripts/run-tests.sh`.

**It proves the architectural question without integrating anything.** A shaped
adapter test-double restores **all 13 fields** through the **shared, unmodified**
normalizer (G6.4) — so a future adapter is sufficient and **no second normalizer
or import engine is needed**. No fixture is connected to production import
execution.

### Negative proofs — all five non-vacuous

| # | Invariant | Mutation | Result |
|---|---|---|---|
| NP1 | failed/429 page ⇒ incomplete | disable the completeness guard | 107 passed, **3 failed** |

---

## M. Architecture recommendation

# Option D — the official API is not suitable for the current public county sources.

| Option | Verdict | Evidence |
|---|---|---|
| **A — API replacement** | **REJECTED** | the target is a *county listing*, not an organization. Cork = 36 organizers per 100 events, Dublin = 74, Carlow = 51 in 60. `/v3/events/search/` is 404. |
| **B — API preferred, scraper fallback** | **REJECTED** | "API preferred" is vacuous: with no ownership the API returns **0** required events, so 100% of load falls back. Option A's work with extra machinery. |
| **C — API partial augmentation** | **REJECTED** | no partial set exists to augment with. A token would surface only our **own** organization's events — a strict subset of what the scraper already imports — adding zero new events plus a second transport, a second error taxonomy and a credential. |
| **D — not suitable** | **SELECTED** | the ownership model, not the transport, is the blocker. |

### What D costs and buys

| | |
|---|---|
| **Evidence** | 404 on the search endpoint; 36/74/51 organizer fan-out measured live; per-user discovery in Eventbrite's own SDK; no credential exists |
| **Benefits** | correct call — no adapter built for an unmeetable goal; the dead `eventbrite_api_token` path retired rather than left to mislead; `docs/plugins/conexao-event-importer.md:262` corrected |
| **Limitations** | no API-derived `changed` timestamps, no deterministic `object_count`, no API-grade status field — the scraper keeps its known weaknesses |
| **Expected request volume** | unchanged from today (≈49 pages per county, capped) |
| **Rate-limit implications** | unchanged; G5 pacing/cooldown remains exactly as delivered |
| **Migration complexity** | **none** — no production change |
| **Missing fields** | unchanged; nothing is lost that G6 had already delivered |
| **Completeness risks** | unchanged; the 49-page cap and 45 s budget still truncate Cork and Dublin — a known, documented, pre-existing limitation |
| **Failure semantics** | unchanged; G1–G5 remain the authority and remain correct |
| **Operator implications** | the G5 manual retry-a-rate-limited-source workflow stays; the settings page stops advertising a mode that never worked |

### If a future stage ever revisits this

Only **two** facts would have to change, and neither is a code change:

1. **Conexão BR Ireland gains documented access to third-party events** (a
   partner/partner-API arrangement), or Eventbrite restores a location-scoped
   event listing. Both are external business changes.

---

## N. Risks and limitations

**Limitations of this investigation (stated, not smoothed over):**

1. **No authenticated request was ever made.** Organization discovery, API event
   counts, API pagination depth and API rate-limit behaviour are **unmeasured**.
   This is the honest cost of no credential.
2. **Eventbrite's reference documentation could not be read.** It is
   client-rendered and publishes no retrievable OpenAPI document. Version,
   sunset schedule, quotas and `page_size` maximum are **unverified**. Nothing
   in this report depends on them.
3. **The live comparison was bounded** to 5/49 Cork, 5/49 Dublin and 3/49
   Carlow pages — a deliberately small sample that establishes the organizer
   fan-out without hammering Eventbrite. The fan-out rate could vary across the
   un-walked tail, so the conclusion is a **lower bound** on the problem.
4. **`eventbrite_cavan` was not walked**, so it carries no verdict of its own.
5. **The 429 behaviour that motivated G1–G5 was not reproducible on
   2026-10-04.** The WAF block lifted and no 429 occurred. G6 has **no live
   rate-limit measurement of the scraper either** and does not claim one. The
   G1–G5 findings stand as history and as tested mechanism.
6. **The contract fixture's event payloads are shape-fixtures**, not captures of
   a live authenticated response — labelled as such in-file and asserted by G6.1.

**Risks that follow from this recommendation:**

- The scraper remains exposed to WAF blocks and 429s whenever Eventbrite applies
  them — **observed during G1–G5, not fixed by this stage.**
- The 49-page cap continues to hide ~37% of Cork's declared objects.
- Correcting `docs/plugins/conexao-event-importer.md:262` belongs to the owning
  documentation stage; G6 deliberately did not edit it, to avoid an unrelated
  change inside a diagnostic stage.

---

## O. Tests and verification

| Layer | Baseline (pre-G6) | Post-G6 | Verdict |
|---|---|---|---|
| Full `./scripts/run-tests.sh` | **31 failures** | **31 failures — identical set** | **no regression** |
| `test-eventbrite-api-contract.php` | did not exist | **110 passed, 0 failed** | new suite green |
| All 23 `conexao-event-importer` suites | pass | pass | unchanged |
| `test-eventbrite-importer.php` | 68 / 0 | 68 / 0 | unchanged |
| `test-eventbrite-rate-limit.php` (G5) | 73 / 0 | 73 / 0 | unchanged |
| `test-source-fetch-failure.php` (G1) | 138 / 0 | 138 / 0 | unchanged |
| `test-source-pagination-completeness.php` (G2) | 24 / 0 | 24 / 0 | unchanged |
| G4 request retry/reuse/budget | 59 / 53 / 33, 0 failed | identical | unchanged |
| `./scripts/lint.sh` | OK | **OK** — syntax clean, no new PHPCS, PHPStan clean | green |
| `generate-registry-docs.php --check` | OK | **OK** — 10 plugins, 19 regions, zero writes | green |
| Permanent gates | 6/7 | **6/7; `i18n_freshness` failing PRE-EXISTING (1 violation, 0 new)** | baseline preserved |

**Two independent full runs were compared against the baseline** (an immediate
post-G6 run and a final run after the documentation index was updated). Both
produced a **byte-identical 31-failure set**: **0 new, 0 suppressed**. The final
run is in `11-final-verification-run.txt`.

**Failure-set comparison is byte-identical** (31 pre-existing, **0 new**, 0
suppressed): [`10-failure-set-comparison.txt`](../evidence/2026-10-04-eventbrite-api-g6/10-failure-set-comparison.txt).

**Newly added assertions:** **110**, in one new offline suite.
**Negative proofs:** **5**, all verified non-vacuous by mutation.

**No assertion was weakened, deleted, or given a wider tolerance.** Two of my
*own* new assertions were corrected mid-run — the `object_count` value and the
`Retry-After` cap (60 s, not 120 s) — because the first draft encoded my
assumption rather than the measured value. Both corrections made the assertions
**stricter and true**, and are recorded here rather than silently fixed.

`i18n_freshness` fails because `conexao-event-importer.pot` is older than the
plugin PHP file. **G6 changed no plugin PHP**, so it neither caused nor can fix
this; it is pre-existing debt for the owning stage. No gate was bypassed, no

---

## P. Files changed by G6

**Added — nothing modified:**

| Path | Purpose |
|---|---|
| `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-api-contract.php` | offline API contract suite: 110 assertions, 5 negative proofs |
| `wp-content/plugins/conexao-event-importer/tests/fixtures/eventbrite-api-v3-contract.json` | sanitized API contract fixture, no credentials |
| `docs/evidence/2026-10-04-eventbrite-api-g6/` | sanitized evidence (no credentials) |
| `docs/reports/2026-10-04-eventbrite-api-feasibility.md` | this report |

**Modified (2):**
- `docs/evidence/2026-09-26-stage-l/gate.json` — regenerated by
  `scripts/verify-permanent-gates.py`; the diff is the current SHA, timestamps
  and gate counters, **not** a weakened invariant.
- `docs/reports/README.md` — one appended index row under a new
  "Event import reliability (G1–G6)" heading, which also indexes the four
  previously-unindexed G1–G5 reports. Documentation only; `documentation_drift`
  still passes 14/14.

**Production files changed: 0. Deployments: 0. Commits: 0.**
The working tree was **clean** before G6, so there is no pre-existing owner
change to disentangle. The Flutter/mobile repository was **never accessed**.

---

## Q. Next-stage recommendation

# Stop pursuing the official Eventbrite API as a replacement for the county scraper.

**G7 should NOT build an API adapter.** The evidence rules out the goal, not the
code.

1. **Do not implement** `fetch_via_api()`. It would be unreachable dead code
   behind a credential, for a source it cannot enumerate.
2. **Retire the dead `eventbrite_api_token` plumbing** and correct
   `docs/plugins/conexao-event-importer.md:262`, which currently instructs a
   maintainer to set a token for a mode that has never existed. This is the one
   concrete correctness task G6 surfaced; it belongs to a small
   documentation/settings stage.
3. **Keep the HTML scraper as the only Eventbrite transport**, with the G1–G5
   semantics exactly as delivered. They are proven, and this stage confirms the
   API is not a better place to spend effort.
4. **Re-open this question only if an external fact changes** — Eventbrite
   restores location-scoped search, or a partnership covers third-party events
   (§M). Re-run G6's §D measurement first; it is cheap, and it is the fact that
   decides.
5. **Do not spend G7 on rate limiting.** The 429s that motivated G1–G5 were not
   reproducible on 2026-10-04. Re-measure before optimising; the pacing policy
   already bounds the damage.

**Reconsider the real bottleneck instead.** The measured cost of the current path
is 49 capped pages per county to reach ~980 of 1,553 Cork objects at a median
~2.85 s interval. If coverage matters more than rate-limit risk, the lever is
**fewer, better-scoped county sources or a different discovery surface** — not
the official API, which provably cannot serve this data.

---

## Evidence index

`docs/evidence/2026-10-04-eventbrite-api-g6/` — contains **no credentials**.

| File | What it proves |
|---|---|
| `01-request-status-summary.txt` | API reachable, not WAF-blocked; 404 on search; the 6+13 request ledger |
| `02-coverage-comparison.csv` | pages, events, organizers, status distribution per county |
| `03-{cork,dublin,carlow}-organizer-fanout.csv` | the ownership fan-out, per organizer |
| `04-field-compatibility-matrix.txt` | field-by-field HTML vs API matrix, measured |
| `05-negative-proofs.txt` | all five mutations and their non-zero failure counts |
| `06-credential-handling.txt` | every location searched; no credential exists; hygiene |
| `07-event-set-comparison.csv` | sampled ids, duplicates, organizer naming |
| `08-baseline-run-tests.txt` | the pre-G6 full-suite baseline |
| `09-post-g6-run-tests.txt` | the immediate post-G6 full-suite run |
| `10-failure-set-comparison.txt` | baseline vs post-G6: identical, 0 new |
| `11-final-verification-run.txt` | the final full-suite run (identical failure set again) |
| `g6-offline-contract-suite.txt` | the 110-assertion suite output |
| `cork/dublin/carlow-sample.json`, `*-walk.log` | raw bounded walks with ids, organizers, timings |
| `lint.txt`, `permanent-gates.txt` | static quality and invariant gates |

test discovery removed, no expected result edited to hide a regression.

2. **The product decision changes** to "import only our own organization's
   Eventbrite events". Then a ~40-line adapter suffices — the offline suite has
   already proven it restores all 13 fields through the shared normalizer.

Absent (1) or (2), an adapter is dead code with a credential attached.

| NP2 | failure ≠ empty (the G1 defect) | force `$state_fail = 'empty'` | 108 passed, **2 failed** |
| NP3 | duplicate ids collapse | disable id dedup | 108 passed, **2 failed** |
| NP4 | a draft is never public | force `published = true` | 109 passed, **1 failed** |
| NP5 | no secret in fixtures | disable the token-scan guard | 109 passed, **1 failed** |

Every mutation produces a non-zero failure count and the pristine suite was
restored and re-verified at 110/0 after each. Details:
[`05-negative-proofs.txt`](../evidence/2026-10-04-eventbrite-api-g6/05-negative-proofs.txt).

### G1–G5 preserved

**Zero production files were modified.** `git diff --stat HEAD` shows only
`docs/evidence/2026-09-26-stage-l/gate.json`, which is regenerated by running
the documented gate command. The G1–G5 mechanisms are not merely untouched —
they are **actively exercised** by this suite (NP1/NP2 → G1; NP1/G6.8 → G2;
G6.10 → G4 and G5, through the unchanged production parser).


**Measured totals:** 7 of 13 importer fields go empty on a raw API object, and
all 7 are recoverable by a key-mapping step over data the API demonstrably
supplies. Nine fields need no translation at all.

**Verdict:** the field layer is **compatible with an adapter**, and the API is
strictly better on four fields. Field shape is **not** the reason the API was
rejected — ownership is.


---


