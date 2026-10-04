# Report — G7: Retire the Dead Eventbrite API Plumbing

| | |
|---|---|
| **Stage / task name** | G7 — retire the dead `eventbrite_api_token` plumbing; correct the importer documentation |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `01096321b611981e1c0a3e6aba05faae535b41c2` |
| **End SHA** | *uncommitted — not deployed, not committed* |
| **Predecessor** | [G6 — Eventbrite API Feasibility](2026-10-04-eventbrite-api-feasibility.md) · [G5](2026-10-04-eventbrite-rate-limit-strategy.md) · [G4](2026-10-03-event-import-g4.md) · [G1–G3](2026-10-03-event-import-fixes-g1-g3.md) |
| **Evidence** | [`docs/evidence/2026-10-04-eventbrite-api-retirement/`](../evidence/2026-10-04-eventbrite-api-retirement/) |
| **Plugin version** | `conexao-event-importer` **1.9.0 — unchanged** |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| **Commits** | **0** |

---

## Status

# `PASS`

Every claim below is backed by a command in this repository, and every negative
proof was mutation-verified. The one limitation is stated honestly in
[§ Remaining limitations](#remaining-limitations) and does not qualify the
result: no **production** Eventbrite import was performed, because G7 must not
write to production.

---

## G6 decision carried forward

G6 concluded, and G7 **carries forward without reopening**:

> **`NOT VIABLE`** — as a replacement or a coverage source for the current public
> county Eventbrite sources.

G7 found **no contradictory repository evidence**. The three load-bearing G6
facts were re-read from the code, not assumed:

| G6 claim | Verified in G7 against the repository |
|---|---|
| `/v3/events/search/` is retired (404) | Consistent: `fetch_via_api()` was removed in Stage B and `test-county-registry.php:184–186` asserts it stays removed |
| Discovery is per-user, not per-location | Consistent: no API client exists in the tree; `grep 'api.eventbrite.com'` → **0 matches** |
| No credential exists in the project | Re-confirmed in [§ Configuration proof](#configuration-proof) |

G6's verdict was about **coverage**, not accessibility. G7 does not claim
Eventbrite API access is impossible in all circumstances — see
[§ Reopening condition](#reopening-condition).

---

## Baseline

**Working tree.** G7 began with uncommitted **G6 work already present**: the G6
evidence directory, the G6 report, the G6 contract fixture and the G6 offline
contract suite (`test-eventbrite-api-contract.php`). G7 created, edited and
deleted **none** of them. Full listing:
[`01-baseline-git-status.txt`](../evidence/2026-10-04-eventbrite-api-retirement/01-baseline-git-status.txt).

**Tests.** `./scripts/run-tests.sh`, run before any G7 edit:

| Layer | Baseline result |
|---|---|
| In-process PHP | 92 total, **63 passed, 29 failed** |
| Assertions | **3750 passed, 4 failed** |
| Script-contract | 18 total, 16 passed, 2 failed |
| HTTP acceptance | 4 total, 3 passed, 1 failed |

**The PHP-layer failure set is identical to G6's recorded baseline** (29
suites; verified by diff — the only textual difference is leading whitespace
between the two evidence formats). G7 therefore inherited G6's exact starting
point rather than a re-derived approximation.

**Permanent gates (baseline).** 7 total, **6 passed, 1 failed** — `i18n_freshness`
with **1 pre-existing violation, 0 new**. This failure was **not** fixed,
suppressed, or allowlisted by G7.

---

## Token inventory

The complete inventory, classified **before** editing. Nothing was deleted on
appearance alone.

| # | Location | Type | Live? | G7 action |
|---|---|---|---|---|
| 1 | `class-import-settings.php:31` — `'eventbrite_api_token' => ''` | runtime config | **no** — never read | **removed** |
| 2 | `class-import-settings.php:73–77` — OAuth sanitiser in `update()` | runtime config | **no** — nothing wrote it | **removed** |
| 3 | `class-import-settings.php:113–116` — `$_POST['eventbrite_api_token']` | runtime config | **no** — the form was its only caller | **removed** |
| 4 | `class-import-settings.php:134–138` — password field, label, "create a token" help | admin UI | **no** — wrote a value nobody read | **removed** (screen retained) |
| 5 | `class-import-settings.php:130–131` — nonce + `conexao_settings_action` | admin UI | **no** — only guarded the removed save | **removed** |
| 6 | `class-import-settings.php:5` — "Storing only the Eventbrite API token" | comment | **false statement** | **corrected** |
| 7 | `class-import-settings.php:28–30` — "When set, sources use the official API" | comment | **false statement** | **corrected** |
| 8 | `class-eventbrite-source.php:102–103` — "Always uses the HTML … verified 404" | comment | true, but understates *why* | **corrected** (docblock only) |
| 9 | `conexao-event-importer.pot` — `"Eventbrite API token"`, `"Leave empty to keep HTML-scraping mode…"` | i18n catalogue | mirrored the removed field | **regenerated** via `scripts/i18n-make-pot.sh` |
| 10 | `docs/plugins/conexao-event-importer.md:262` | **current maintainer doc** | **actively wrong** | **corrected** |
| 11 | `docs/plugins/conexao-event-importer.md:122` | current maintainer doc | wrong | **corrected** |
| 12 | `docs/plugins/conexao-event-importer.md:218` | current maintainer doc | incomplete | **corrected** |
| 13 | `docs/plugins/conexao-event-importer.md:1030` | current maintainer doc | wrong | **corrected** |
| 14 | `docs/reports/2026-10-04-eventbrite-api-feasibility.md` (121, 149, 499, 627) | **G6 historical report** | no | **retained verbatim** |
| 15 | `docs/evidence/2026-10-04-eventbrite-api-g6/06-credential-handling.txt` (29–31) | **G6 historical evidence** | no | **retained verbatim** |
| 16 | `docs/importers/events-expansion-stage-{a,b}-*.md` | historical audits | no | **retained** |
| 17 | `.env`, `.env.example`, `compose.yaml` | configuration | — | **verified empty of Eventbrite keys; nothing removed** |
| 18 | `test-eventbrite-api-contract.php:416–417` (`$out['eventbrite_api_status']`) | G6 test local array key | no — a feasibility fixture field | **retained** (G6 evidence) |
| 19 | `test-county-registry.php:184–186` | regression test | **yes** | **retained unchanged** (Class C) |
| 20 | `phpstan-baseline.neon:495–499` — `booleanAnd.rightAlwaysTrue` for `class-import-settings.php` | static-analysis baseline | orphaned by G7's removal | **entry removed** (see [§ Regression verification](#regression-verification)) |

### What was deliberately NOT removed

- **`Conexao_Import_Settings` itself.** `Conexao_Event_Cleanup:146` reads
  `cleanup_scope` from this option:
  `Conexao_Import_Settings::get( 'cleanup_scope', 'all' )`. Deleting the class
  would have broken a **live, non-Eventbrite consumer**. The class and its
  option surface are retained; only the dead key left it. `cleanup_scope`
  round-tripping is asserted by NP5.
- **The Settings admin screen.** It is the documented landing page for importer
  configuration. It now states the truth ("Nothing to configure") instead of
  offering a field that never did anything.
- **The HTML scraper, the G5 pacing policy, request budgets, diagnostics and
  every G1–G5 completeness mechanism.** G7 is cleanup, not a rewrite.


---

## Runtime proof

**Why the active importer never depended on an Eventbrite API credential.**

Deadness was established by tracing the execution path, not by reading the
variable name:

| Question | Answer | Evidence |
|---|---|---|
| Does the importer read an API token? | **No.** No code path ever read `eventbrite_api_token`. | `grep` across `wp-content/` found the key only in `class-import-settings.php` — in a default, a sanitiser, a POST read and a form field. **Zero readers.** |
| Is there an API mode? | **No.** | `fetch_events()` is a single unconditional `return $this->fetch_via_scrape();` |
| Does it select between HTML and API clients? | **No.** | No selection exists; `use_api()` / `fetch_via_api()` were removed in Stage B and `test-county-registry.php:184–186` asserts they stay removed |
| Is an Authorization header constructed? | **No.** | `Conexao_Eventbrite_Client::request_page():440–447` sends exactly `Accept` and `Accept-Language` |
| Are API endpoints called? | **No.** | `grep -r 'eventbriteapi.com'` → **0 matches** anywhere in the repository |
| Does behaviour change when a token exists? | **No.** | NP1: with `eventbrite_api_token` stored, the walk still parses the HTML page and imports the event |
| Does a token-setting affect execution? | **No.** | NP1: the key is absent from the defaults and from every executable file in `includes/` |

**The supported path, end to end.** `Conexao_Event_Sources` → `Conexao_Source_Eventbrite`
→ `fetch_events()` → `fetch_via_scrape()` → `Conexao_Eventbrite_Client` (HTML
GET of the public county discovery page) → `Conexao_Eventbrite_Parser` →
`Conexao_Eventbrite_Normalizer` → `Conexao_Event_Importer_Engine`. **No
credential appears anywhere in that chain.**

**Why stale stored data is harmless.** An older local install may still hold an
`eventbrite_api_token` key inside `conexao_event_importer_settings`. G7 removes
the **consumer**, not the data: the key is inert because nothing reads it. No
destructive option-rewrite migration was introduced — this plugin does not
migrate its own options, and G7 will not add a write path that deletes operator
state. A maintainer who wants the key gone can delete it by hand. NP1 exercises
exactly this state and proves it changes nothing.

---

## Configuration proof

Every surface where a credential could have lived was inspected. **None
required a change, because none ever held an Eventbrite key.**

| Surface | Finding | G7 action |
|---|---|---|
| `.env` | No Eventbrite key | none needed |
| `.env.example` | No Eventbrite key | none needed — **and no placeholder was added** |
| `compose.yaml` | No eventbrite/token/oauth key | none needed |
| Docker container env | No `EVENTBRITE_*` variable | none needed |
| `conexao_event_importer_settings` option | Token default removed | default removed; class retained for `cleanup_scope` |
| REST / admin endpoints | No token endpoint | none |
| Validation / capability checks | The only capability check (`manage_options`) is unchanged | none |
| Serialization / migration | None existed | none added |
| `.pot` catalogue | Mirrored the removed field | regenerated |

**Security conclusions.**

- **No real Eventbrite credential exists** — before or after G7.
- **No credential was created, requested, pasted or stored** during G7.
- **No token is committed**: the gate greps the whole repository for a
  token-shaped value and asserts zero hits.
- **The retired path can no longer become a secret-storage path**: with the
  admin field, its POST handler and its nonce gone, there is no longer any way
  to write an Eventbrite token through wp-admin at all.
- **Unrelated credentials were not touched** — the WordPress application
  password configuration (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`) and the
  translation-provider secrets are untouched.

---

## Documentation correction

**The named defect — `docs/plugins/conexao-event-importer.md:262`:**

Before, it instructed a maintainer:

> Two fetch modes, chosen automatically:
> 1. **Official API**: set a personal OAuth token in Event Import → Settings →
>    Eventbrite API token. Sources then call `https://www.eventbriteapi.com/v3/events/search/`.
> 2. **HTML scraping** (fallback, no token)…
> Create a free token at developers.eventbrite.com.

Three claims, all false: that a token-selectable API mode existed, that sources
called that endpoint, and that a token was needed. Note it also **contradicted
line 218 of the same file**, which correctly recorded the endpoint as removed.

**After**, the section states one supported path, the reason no API mode exists,
and the reopening condition. Three related current-doc locations were corrected
in the same pass so no stale instruction survives:

| Line | Was | Now |
|---|---|---|
| **262** | "Two fetch modes… Official API… Create a free token at…" | One supported HTML path; no credential of any kind; why the API cannot enumerate a county; G6 `NOT VIABLE`; reopening condition |
| **122** | "Settings storage (Eventbrite API token) + admin page" | "Settings storage (`cleanup_scope`)… **No Eventbrite API token**" |
| **218** | "Eventbrite API removal … The HTML discovery path is the only supported mechanism." | Adds that the path **requires no credential** and that the token setting was dead and retired |
| **1030** | "Settings — Eventbrite API token" | "Settings — importer settings storage (there is nothing to save; no Eventbrite API token)" |

The corrected section **does not** claim the API is impossible in all
circumstances: it states the specific external facts (search endpoint retired,
discovery per-user), records that future external changes could justify
reopening, and names the measurement to repeat first.

The wp-admin screen itself now says the same thing, so a maintainer who reaches
the UI is not told anything the documentation does not say.


---

## Historical references

Retained **intentionally and verbatim**. G7 found no reason to alter any of them;
each records a true finding about a state that existed when it was written.

| Reference | Why it stays |
|---|---|
| `docs/reports/2026-10-04-eventbrite-api-feasibility.md` lines 121, 149, 499, 627 | G6's evidence: the credential slot "exists but no code path uses it", and its recommendation to retire it — which is exactly what G7 did |
| `docs/evidence/2026-10-04-eventbrite-api-g6/06-credential-handling.txt` lines 29–31 | The original record that the importer *had* a token slot, and that G6 did not modify or populate it |
| `docs/evidence/2026-10-04-eventbrite-api-g6/` (all 20 files) | The investigation record, including "no credential exists" |
| `docs/importers/events-expansion-stage-b-report.md:98–100` | Records the Stage B removal of `fetch_via_api()`, `use_api()`, `map_api_event()` |
| `docs/importers/events-expansion-stage-a-audit.md` (§3.9, 152, 309, 788, 844) | The audit that first identified the dead API path |
| `docs/reports/2026-10-03-event-import-diagnosis.md:225` | The pre-G6 finding that no authentication to Eventbrite existed |
| `test-eventbrite-api-contract.php` (`eventbrite_api_status`/`_changed` locals) | G6's executable feasibility evidence; its array keys describe a *hypothetical* API payload, not importer configuration |

**The requirement these satisfy:** no *current operational documentation* or
*runtime implementation* treats `eventbrite_api_token` as part of the supported
importer contract. Verified mechanically by the new gate, which **fails if G6's
report is deleted** (mutation MG3) — a clean grep obtained by destroying history
is a failure, not a pass.

---

## Negative proofs

Suite: `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-token-retirement.php`
— **42 assertions, 0 failed**, auto-discovered by `./scripts/run-tests.sh` (no
second runner; no hardcoded list).

| Proof | Establishes | Assertions |
|---|---|---|
| **NP1** | A token-like configuration value **cannot** activate an API mode | 10 |
| **NP2** | The supported path runs with **no token present at all** | 5 |
| **NP3** | The HTML importer **never** constructs an Authorization header | 7 |
| **NP4** | A clean configuration never reports the token as a prerequisite | 4 (PHP) + 26 (repository gate) |
| **NP5** | G1–G5 completeness/failure semantics are **unchanged** | 16 |

**NP1** stores `eventbrite_api_token`, runs a full walk, and asserts the HTML
page is still parsed and the event still imported; then proves structurally that
`use_api()`, `fetch_via_api()` and `get_api_token()` do not exist, that no
request targets `eventbriteapi.com` or `/v3/`, that **no executable file in
`includes/`** mentions the key (comments stripped via `token_get_all`, so the
removal rationale is not mistaken for a dependency), and that the client
constructor accepts no credential parameter.

**NP2** deletes the settings option entirely and imports successfully — a clean
install needs nothing.

**NP3** records every request's **headers** (not just its URL, which is what
makes the proof non-vacuous) and asserts no `Authorization` header, no `Bearer`
value, and that the client source names neither. The request set is asserted
non-empty, and the reuse store is reset first so the walk cannot pass by
replaying a cached response.

**NP5** re-proves the G1 masking defect: a first page that cannot be parsed
**aborts by throwing** rather than returning an empty list, and
`mark_fetch_incomplete()` still forces an incomplete verdict.


### Mutation verification — every proof is non-vacuous

Full output: [`04-mutation-proofs.txt`](../evidence/2026-10-04-eventbrite-api-retirement/04-mutation-proofs.txt).
Each mutation reintroduced a retired guard, re-ran the suite, and was restored
(verified: **0** `G7 MUTATION` matches remain).

| Mutation | Reintroduced | Result | Intended assertions killed |
|---|---|---|---|
| **M1** | token-activated API mode + `get_api_token()` | 37 passed, **4 failed** | NP1 ×4 |
| **M2** | `Authorization: Bearer …` header in the client | 40 passed, **3 failed** | NP3 ×3 |
| **M3** | token field + save action + "create a free token" | 38 passed, **4 failed** | NP1 ×1, NP4 ×3 |
| **M4** | G1 masking defect (swallow a failed first page) | 39 passed, **3 failed** | NP5 ×3 |
| **MG1** | token instruction in the current doc | 56 passed, **3 failed** | doc-instruction ×3 |
| **MG2** | bare prerequisite sentence naming the key | 58 passed, **1 failed** | live-reference ×1 |
| **MG3** | **delete the G6 report** | 56 passed, **3 failed** | history-preservation ×3 |

**7 of 7 mutations produced non-zero failures on the intended assertions.** No
mutation was satisfied by an unrelated assertion failing. Notably **M4** proves
the G1–G5 protections are genuinely under test rather than incidentally
untouched, and **MG3** proves the retirement cannot be made to look clean by
deleting evidence.

### Three defects these proofs caught in G7's own work

Mutation-driven testing was not a formality; it found three real problems:

1. **A vacuous proof.** The HTTP double originally cleared its recorded requests
   on removal — *before* the assertions read them. NP1's URL loop and NP3's
   header loop were passing on an **empty set**. Fixed by separating
   `g7_remove_http_double()` (detach) from `g7_reset_requests()` (forget).
2. **A passing-for-the-wrong-reason proof.** NP5's failing walk replayed NP3's
   memoised successful page, so it could never observe a failure. Fixed with
   `Conexao_Request_Reuse::begin_run()` before the walk, plus an explicit
   assertion that the walk really issued a request.
3. **A wrong-target assertion.** The original NP5 assertion checked
   `is_fetch_complete()` on a handler that is **discarded** when the exception
   propagates. That flag is not the G1 protection — the protection is that the
   engine catches the throw and returns *before* `mark_missing_events()`. The
   assertion now targets the real invariant: the walk **aborts** instead of
   returning an empty list.

---

## Regression verification

### Failure set: G6 baseline vs. G7

| Measure | G6 baseline | G7 result | Verdict |
|---|---|---|---|
| In-process PHP suites | 92 total, 63 passed, **29 failed** | 93 total, 64 passed, **29 failed** | **identical failure set; +1 new passing suite (G7's own)** |
| Assertions | 3750 passed, 4 failed | **3792 passed, 4 failed** | **+42 (the new suite); 0 removed** |
| Script-contract | 18 total, 16 passed, 2 failed | 19 total, 17 passed, 2 failed | **+1 new passing gate; same 2 pre-existing failures** |
| HTTP acceptance | 4 total, 3 passed, 1 failed | 4 total, 3 passed, 1 failed | **unchanged** |

**0 new failures. 0 suppressed. 0 assertions removed.** Every importer suite
passes, including all 23 pre-existing ones with unchanged counts
(`test-county-registry` 482, `test-source-pagination-completeness` 24,
`test-eventbrite-rate-limit` 73, `test-source-fetch-failure` 138, and so on).

### Gates

| Gate | Baseline | G7 | Classification |
|---|---|---|---|
| `./scripts/lint.sh` | OK | **OK** — syntax clean, no new PHPCS violations, PHPStan clean | clean |
| `generate-registry-docs.php --check` | green | **green** | clean |
| Permanent gates aggregate | 6/7, 1 pre-existing violation | **6/7, `i18n_freshness` 1 pre-existing violation, 0 new** | **pre-existing, correctly unsuppressed** |
| New `verify-eventbrite-token-retirement.py` | — | **59 passed, 0 failed; 0 live refs, 5 historical retained** | new, green |
| Agent governance / script conventions | green | **green** | clean |

### One failure G7 *did* introduce, and fixed at source

Removing the `&& current_user_can(...)`-shaped condition left a **PHPStan
baseline entry orphaned** (`booleanAnd.rightAlwaysTrue` for
`class-import-settings.php`), and `lint.sh` failed with `ignore.unmatched`.

The correct fix was to **delete the orphaned baseline entry** — the code it
described no longer exists, so keeping it would be dead configuration. This is
classified as *legitimately changed because dead behaviour was removed*, not
suppressed: no baseline was widened, and no other entry was touched.
`lint.sh` now reports `OK`.

`i18n_freshness` remains failing for the **pre-existing** reason recorded by G6
(the `.pot` is older than the plugin's PHP in git commit time). G7 regenerated
the catalogue for the component it changed — the honest, documented action — and
did **not** suppress the gate or allowlist the violation.


---

## Final repository search

The search ends in an **explicit classified state**, not "no matches".

```
$ python3 tests/scripts/verify-eventbrite-token-retirement.py
   files scanned:                     1792
   historical references retained:    5
   LIVE references:                   0
  59 passed, 0 failed
```

| Category | Count | Verdict |
|---|---:|---|
| **Live runtime references** (executable code reading the token) | **0** | removed |
| **Live configuration** (`.env`, `.env.example`, `compose.yaml`, option schema) | **0** | none existed; none added |
| **Operational documentation** (current instructions to configure a token) | **0** | corrected |
| **Test dependencies** on the token path | **0** | G6's suite and G7's negative-proof suite retained deliberately |
| **Historical only** (G6 record, prior audits, this stage's own record) | **5 files** | **retained, truthful** |
| Committed Eventbrite credentials | **0** | none, before or after |

The five retained files, and why each is legitimately historical:

| File | Why it is historical, not live |
|---|---|
| `docs/reports/2026-10-04-eventbrite-api-feasibility.md` | G6's verdict record; it describes the token as dead and recommends retiring it |
| `docs/evidence/2026-10-04-eventbrite-api-g6/06-credential-handling.txt` | G6's measurement that the credential slot existed and was empty |
| `docs/reports/2026-10-04-eventbrite-api-retirement.md` | **this report** — it must name the key to document its retirement |
| `docs/evidence/2026-10-04-eventbrite-api-retirement/04-mutation-proofs.txt` | **this stage's mutation evidence**, which quotes the reintroduced code |
| `docs/reports/README.md` | the **index row** describing this stage |

None of them instructs a maintainer to configure anything, and **deleting G6's
report fails the gate** (mutation MG3). The count rose from 3 to 5 as this
stage's own record was written — that is the correct behaviour: the gate
classifies by *kind*, not by a fixed number, and a report that failed to name
the key it retired would be a worse report.

The gate is discoverable by convention: it runs automatically inside
`./scripts/run-tests.sh` and in CI. There is no second runner and no hardcoded
suite list.

---

## Architecture preserved

G7 succeeds only if the working importer is untouched. Every G1–G5 mechanism is
verified still present and still exercised by its existing suite:

| Mechanism | Where | Status |
|---|---|---|
| Source discovery | `Conexao_Source_Eventbrite::fetch_via_scrape()` | intact |
| HTML Eventbrite fetching | `Conexao_Eventbrite_Client` | intact |
| Pagination + region validation | handler loop, `MAX_PAGES = 50` | intact |
| Response reuse | `Conexao_Request_Reuse` | intact (NP3 asserts it still applies) |
| Bounded request budget | `Conexao_Request_Budget` | intact |
| Time budget | `TIME_BUDGET_SECONDS = 45` | intact |
| `Retry-After` handling | client | intact |
| Retry classification | client | intact |
| Repeated-429 fail-fast | G5 pacing policy | intact |
| Rate-limit diagnostics | log payloads | intact |
| Completeness tracking | `mark_fetch_incomplete()` / `is_fetch_complete()` | intact (NP5) |
| Protection against false `found=0 success` | G1 structured failure | intact (NP5, mutation-verified by M4) |
| Protection against `mark_missing_events()` on incomplete enumeration | engine returns before marking | intact (NP5) |
| County region gate | `is_county_event()` | intact (NP5) |

**Nothing was "simplified" while removing the token.** No API feature flag was
added, no empty adapter skeleton was left behind, no speculative abstraction was
introduced, and no fallback hierarchy involving the official API exists.


---

## Production

- **Production writes: 0**
- **Deployments: 0**
- **Commits: 0** (nothing was committed or pushed, as instructed)
- Production WordPress, configuration, credentials, plugin activation and
  Eventbrite settings were **not touched**.
- No production Eventbrite import was performed — and none was needed: the
  supported path is proven locally with a scripted HTTP double.

**Flutter/mobile repository: not accessed, inspected, built, tested, modified or
referenced.**

---

## Remaining limitations

Stated explicitly, so `PASS` is not read as more than it is.

1. **No production verification.** G7 proves the supported path locally. Whether
   Eventbrite's WAF permits a *residential-IP* production walk today is an
   external condition G7 neither controls nor tested — and G6 already observed
   the historical 51/51 × 405 block had lifted on 2026-10-04 (13/13 × 200). No
   claim here depends on reproducing it.
2. **No live authenticated API validation.** G6 established there is no
   credential and that authenticated validation could not change the verdict
   (the ownership model is visible without one). G7 inherits that gap unchanged
   and did not attempt to close it.
3. **Stale option keys are left in place.** A local install may still store
   `eventbrite_api_token` inside `conexao_event_importer_settings`. It is inert.
   G7 deliberately shipped **no** destructive option-rewrite migration; adding
   one would be an architecture change, not cleanup.
4. **`i18n_freshness` remains failing** — pre-existing, unrelated to G7, and
   deliberately not suppressed.
5. **32 pre-existing suite failures remain** — all in translation-automation,
   translation-rollout and theme language suites, all present at baseline. G7
   neither caused nor fixed them.
6. **Mutation proofs were run in the local working tree** and restored after
   each; no mutation was ever committed.

---

## Reopening condition

G6's reopening condition, carried forward:

> Reconsider the official Eventbrite API **only if an external fact materially
> changes** — such as Eventbrite restoring a suitable location-scoped/public
> discovery capability, or a legitimate partnership/access model that covers
> third-party events.
>
> **When reopened, repeat the cheap ownership/discovery measurement first**: how
> many distinct organizers does a county's first 100 events actually span? If
> that number is materially smaller than G6's 36 / 74 / 51, the coverage
> objection weakens and the API becomes worth re-evaluating. Do not build an
> adapter before that measurement is repeated.

Recorded in the maintainer documentation at
[`docs/plugins/conexao-event-importer.md`](../plugins/conexao-event-importer.md)
so the condition outlives this report, and enforced by the new gate, which fails
if the documentation stops stating it.

---

## Definition of done

> **Eventbrite API integration is not part of the supported importer
> architecture. The dead API-token plumbing has been removed, current maintainer
> documentation no longer instructs its configuration, the working Eventbrite
> importer and all G1–G5 protections remain intact, and the G6 conclusion
> remains the evidence-backed basis for not pursuing the API unless Eventbrite's
> external capabilities materially change.**

**Status: `PASS`.** The Eventbrite API question should remain **closed**.

