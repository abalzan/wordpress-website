# Report — Christmas landing-page active-event dispatch fix

| | |
|---|---|
| **Stage / task name** | Christmas landing-page active-event dispatch fix (`/natal/`, `/en/christmas/`) |
| **Date** | 2026-11-06 |
| **Author / agent** | Implementation agent (Cline) |
| **Branch** | `commemorative-events` |
| **Start SHA** | `411d16706bfe2821f0962451c638cf17fccf0eae` |
| **Final SHA** | `411d16706bfe2821f0962451c638cf17fccf0eae` (unchanged — no commit was made) |
| **Working tree at finish** | dirty with **exactly** the 4 modified + 3 untracked paths listed in §3 (browser verifier, report, evidence directory); the 9 pre-existing stashes are untouched |
| **Skills followed** | `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-security-review`, `wp-update-docs`, `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/commemorative-events.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, the Christmas welcome-modal report (§12.1, which reported this defect) |
| **Evidence** | [`docs/evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/`](../evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/) |

## 1. Scope completed

With the Christmas event active, `/natal/` (PT) and `/en/christmas/` (EN) render the
full Christmas landing experience again instead of an empty `<main>`.

1. **Root cause fixed at the dispatch layer.** `conexao_commemorative_placement_context()`
   in `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` now
   resolves the page-owned `landing_page` placement **before** the event-owned copy of
   the same placement id. No template, package, event record, placement, route, meta
   key, scheduler, resolver, cache or activation path was added or changed.
2. **Nothing else in the experience moved.** The hero, journey, star, modules, CTA,
   ambient layer, welcome modal, preview banner and archival behaviour are byte-for-byte
   the code that already shipped; the only production change is the precedence of two
   `if` blocks plus the docblock that explains it.
3. **Regression tests** in the two existing commemorative suites, PT + EN, that fail
   against the old precedence (6 + 16 assertions) and pass against the fix.
4. **Real-browser verification** of both routes in the active state at 320×568,
   390×844, 768×1024 and 1366×768: 165 assertions, 0 failures, 8 screenshots.
5. **Real-HTTP before/after** on the local site through the engine's own signed preview,
   proving `<main>` went from empty to a 22,828-byte (PT) / 22,408-byte (EN) landing
   composition.

## 2. Scope NOT completed

| Item | Why |
|---|---|
| Production verification | Not authorised; production is untouched (§12) |
| Commit, push, tag, release, deploy | Explicitly out of scope for this task |
| WebKit / iOS Safari browser run | Playwright WebKit is not installed in this environment (same limitation as the welcome-modal report) |
| Physical-device testing | Browser emulation only |
| The second `ce-active-marker` row on an active landing route | Pre-existing plan-composition wart, no rendering effect; see §11.1 — left untouched deliberately |
| `/empregos/` 302, EN translation completeness, POT freshness, `dist/` version drift | Pre-existing local/content conditions, explicitly out of scope |

## 3. Files added / modified / deleted

**Modified (production code — 1 file)**

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` | `conexao_commemorative_placement_context()`: the resolved page opt-in (`$plan['landing']`) is evaluated before the event-driven placement set; the docblock states the ownership rule. Two `if` blocks reordered, +25/−5 lines, no other hunk |

**Modified (tests — 2 files)**

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-presentation-dispatch.php` | New section “The landing placement owns its context while its own event is active” (+20 assertions) |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-landing-page.php` | New sections: PT/EN active-state rendering (+34 assertions), unrelated page out of season (+6), preview authorisation (+2); three suite-local helpers |

**Modified (docs — 1 file)**

| File | Change |
|---|---|
| `docs/README.md` | One row added to the report index (§16) |

**Added**

| File | Purpose |
|---|---|
| `tests/browser/verify-christmas-landing-dispatch-browser.py` | Focused real-browser verifier for the two landing routes in the active state (existing Playwright stack, local-only, production-refusing) |
| `docs/reports/2026-11-06-christmas-landing-page-active-dispatch-fix.md` | This report |
| `docs/evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/` | Evidence: JSON + logs + 8 screenshots |

**Deleted:** none.

## 4. Runtime impact

- **One extra `if` evaluation per placement-context lookup** (the landing branch is now
  tested first). No query, option read, meta read, cache key, transient or HTTP call is
  added.
- **No new asset, handle, class, template part, hook or filter.**
- **Fail-closed behaviour preserved:** an un-opted page has no `$plan['landing']` entry,
  so the event branch resolves exactly as before; a page without the opt-in still
  resolves nothing at all (§13, “unrelated page” assertions).
- `conexao_commemorative_render_placement()` now returns `true` from the real landing
  context, so the landing template's graceful “render the page body instead” fallback is
  no longer reached on an active opted-in route — which is the point of the fix.

## 4a. Scope integrity

| Boundary | Status |
|---|---|
| `conexao-commemorative` remains the theme-independent scheduling/data engine | untouched |
| The theme-owned registry/dispatcher owns presentation | the fix lives only in the theme dispatcher |
| Event record, identity, placements, package, rules, precedence | untouched |
| Scheduler / resolver / occurrence windows / cache policy | untouched |
| Homepage welcome modal (behaviour, styling, `sessionStorage` key) | untouched; still renders on `/` and `/en/`, absent on landing routes |
| Polylang ownership/copy rules | untouched; the language enters only through the pre-existing slug match |
| CPT privacy, preview authorisation, activation controls | untouched |
| No new event/package/placement/CPT/meta/route/endpoint/timer/activation path | confirmed by the diff (1 production file, 2 `if` blocks reordered) |


## 5. Root cause, minimal change, rationale

### 5.1 Root cause (confirmed by inspection *and* by reproduction)

`conexao_commemorative_build_plan()` (`inc/commemorative-presentations.php`) composes
two independent sources:

| Source | What it contributes | Carries page identity? |
|---|---|---|
| `$plan['placements']` — placements of the **active event's package** whose `routes` intersect `conexao_commemorative_route_kinds()` | while Christmas is active, `landing_page` is in this set because the `christmas` package declares it on the `landing` route and the landing route adds `landing` to the route kinds | **no** — the context built from this source hard-coded `'page_id' => 0` |
| `$plan['landing']` — the **page opt-in** (`landing_page_package()` + `landing_slug_matches()`), resolved independently of any event so the page survives its window (r10 §7.13) | the package, the placement definition and `'page_id' => get_queried_object_id()` | **yes** |

`conexao_commemorative_placement_context()` tested the event source **first**:

```php
if ( isset( $plan['placements'][ $placement_id ] ) && null !== $plan['package'] ) {
    return array( ..., 'source' => 'event', 'page_id' => 0 );   // ← won
}
if ( ! empty( $plan['landing'] ) && 'landing_page' === $placement_id ) {
    return array( ..., 'source' => 'landing', 'page_id' => (int) $plan['landing']['page_id'] );
}
```

While the event was *inactive* the first branch never matched for `landing_page`, so the
page-owned branch ran and the page rendered (this is why every existing test passed: they
only exercised the March/February archival clock). While the event was *active* both
sources matched, the event branch won, `page_id` became `0`, and:

1. `template-parts/commemorative/landing-page.php` line 19 —
   `if ( empty( $ce['package'] ) || empty( $ce['placement'] ) || empty( $ce['page_id'] ) ) { return; }`
   returned before emitting anything;
2. `conexao_commemorative_render_placement()` had already entered
   `get_template_part()`, so it returned **`true`**;
3. `page-commemorative-landing.php` therefore took the “rendered” path and **skipped its
   own graceful fallback** (page title + page content), leaving `<main id="primary" class="site-main">` empty.

Reproduced against the real local site with the engine's signed preview (evidence
`http-active-route-before.txt`): `banner=1 landing_hero=0 modules=0`, `<main>` empty, on
both `/natal/` and `/en/christmas/`.

### 5.2 The minimal change

`conexao_commemorative_placement_context()` now evaluates the page-owned landing entry
**first**, and the docblock records why:

```php
if ( ! empty( $plan['landing'] ) && 'landing_page' === $placement_id ) {
    return array( ..., 'source' => 'landing', 'page_id' => (int) $plan['landing']['page_id'] );
}
if ( isset( $plan['placements'][ $placement_id ] ) && null !== $plan['package'] ) {
    return array( ..., 'source' => 'event', 'page_id' => 0 );
}
```

Nothing else in the file changed. `build_plan()`, `asset_plan()`, `body_classes()`,
`body_marker()`, `enqueue_assets()` and every template are untouched.

### 5.3 Architectural rationale

- **Ownership, not a bypass.** `landing_page` is page-owned: its `<h1>`, intro, modules
  and archival value come from the page (r10 §7.5/§7.13). The event-owned copy of the
  same id exists only because the package declares the placement; it never had page
  identity to offer. Precedence belongs to the source that *has* the identity.
- **The narrowest possible lever.** The alternative — removing `landing_page` from
  `$plan['placements']` in `build_plan()` — changes the plan's composition, which
  `asset_plan()`, `body_classes()`, `body_marker()` and the `commemorative_placement_assets`
  gate all consume. Reordering two branches inside one resolver changes no data structure
  and no other contract.
- **No eligibility loosening.** `$plan['landing']` is populated only when the page carries
  BOTH the landing template and a registered `_ce_landing_package` **and** its slug equals
  the package's slug for the current language. A page that loses its opt-in resolves
  nothing (proved in §9).
- **No hard-coded id, slug or language.** The context takes `$plan['landing']['page_id']`,
  i.e. the queried page, in whichever language the route is.
- **The event keeps its own placements.** `site_wide_ambient` still renders on the landing
  route from the event branch (unchanged behaviour), and the homepage hero still never
  appears there.

### 5.4 PT/EN before and after

| Route (Christmas active) | Before | After |
|---|---|---|
| `/natal/` | `<main>` empty — 0 `.ce-landing__title`, 0 modules, 63,885 bytes | 1 hero (`Natal`), 5 modules, journey + closing, 86,713 bytes |
| `/en/christmas/` | `<main>` empty — 0 `.ce-landing__title`, 0 modules, 63,740 bytes | 1 hero (`Christmas`), 5 modules, journey + closing (`Two skies, one star`), 86,148 bytes |
| Context | `source=event page_id=0` | `source=landing page_id=<queried page>` (125982 PT / 125983 EN locally) |
| Page without the opt-in, out of season | no placement, no asset | unchanged: no placement, no asset |
| Landing page in February (archival) | rendered | unchanged: still renders |


## 6. Content / data impact

**No content, option, meta or user data was written.** The suites only read. The one
database interaction was an *ephemeral authentication artefact* required to exercise the
signed preview in a real browser: `WP_Session_Tokens::create()` for one local
`administrator` (`test`, id 283039560) and its destruction afterwards — the account's
session list returned to the exact pre-task count (**24 sessions**). No post, page,
`_ce_*` meta, term, menu, option or transient was created, updated or deleted. No cache
was purged and no plugin/theme activation state was changed.

## 7. Polylang impact

- `conexao_current_locale()` / `conexao_current_language_slug()` remain the only language
  reads; the fix consumes their effect indirectly through the pre-existing
  `conexao_commemorative_landing_slug_matches()` (`pt => natal`, `en => christmas`).
- The PT and EN pages are still one translation pair; the EN route resolves the **EN**
  page id (`125983`) and the PT route the **PT** page id (`125982`) — asserted in both
  suites and in the HTTP matrix.
- No ownership/copy rule changed: `conexao_category`/`conexao_tag` stay translated,
  `conexao_county`/`conexao_town` stay shared (untouched files).
- PT content immutability: no PT slug, title, URL, record or meta was touched.

## 8. Route / HTTP impact

No route, redirect, rewrite rule, endpoint or cache rule was added or changed. Behaviour
on the local site (`http-route-matrix.txt`, 14 rows, read-only GETs; “active” = signed
preview of `christmas`, “public” = no preview):

| Mode | Route | `data-ce-welcome` | landing hero | modules | ambient | preview banner |
|---|---|---|---|---|---|---|
| active | `/` | 6 | 0 | 0 | 1 | 1 |
| active | `/guias/` | 0 | 0 | 0 | 1 | 1 |
| active | `/lazer/` | 0 | 0 | 0 | 1 | 1 |
| active | `/empregos/` | 0 | 0 | 0 | 0 | 0 (pre-existing 302 → `/en/empregos/`) |
| active | `/en/` | 6 | 0 | 0 | 1 | 1 |
| active | `/natal/` | 0 | **1** | **5** | 1 | 1 |
| active | `/en/christmas/` | 0 | **1** | **5** | 1 | 1 |
| public | `/`, `/guias/`, `/lazer/`, `/empregos/`, `/en/` | 0 | 0 | 0 | 0 | 0 |
| public | `/natal/`, `/en/christmas/` | 0 | 1 | 5 | 0 | 0 (archival) |

Reading: the homepage keeps its welcome; internal pages keep only the ambient layer; the
landing routes now render their own composition with no welcome; out of season, nothing
commemorative loads anywhere except the archival landing pages.

## 9. Failure proofs / negative tests

| Proof | Evidence |
|---|---|
| The new assertions fail against the old precedence | `before-fix-failing-tests.txt`: 6 failures in the dispatch suite (`expected 'landing', got 'event'`, `expected 125982, got 0`, blank markup, 0 hero, 0 modules) and 16 in the landing suite (PT and EN: blank, no hero, no title, no modules, no route-language copy) |
| The live route was genuinely blank before the fix | `http-active-route-before.txt`: signed preview on `/natal/` and `/en/christmas/` → `banner=1 landing_hero=0 modules=0`, `<main id="primary" class="site-main">` with no children |
| The page that loses the opt-in still resolves nothing | both suites: a normal page has no landing package, `$plan['landing']` empty, `render_placement('landing_page')` returns `false` **and** prints nothing |
| Unrelated page out of season stays commemorative-free | landing suite: no placement, no landing entry, `has_dispatch()` false, empty style/script lists |
| No welcome on the landing routes | dispatch suite (`data-ce-welcome` absent, `ce-christmas-hero` absent), both landing-suite routes, and the browser verifier (welcome locators = 0, `dialog[open]` = 0) at all four viewports |
| No duplicated landing output | exactly one `ce-landing__title`, one `data-ce-landing`, unique module ids, one journey — in all three layers |
| Preview authorisation unchanged | landing suite: `conexao_commemorative_is_preview()` false and `conexao_commemorative_preview_banner()` prints nothing without authorisation; the browser verifier shows exactly one banner **only** on the signed-preview requests |

## 10. Regression comparison

Full harness at the end of the task: `./scripts/run-tests.sh` → **TESTS FAILED** (exit 1)
with the **identical failure set the repository already had** (evidence
`12-full-suite.txt`).

| Layer | Suites | Result |
|---|---|---|
| In-process PHP | 137 | 126 passed, 11 failed — **10,037 assertions passed, 38 failed** |
| Script contract | 21 | 19 passed, 2 failed |
| HTTP acceptance | 5 | 1 passed, 4 failed |

**The diff of the failing suite lists is empty.** The 11 red PHP suites are exactly:

| Red suite | Now | Recorded baseline |
|---|---|---|
| `theme/…/test-blog-en-translation.php` | 25 / 3 | 25 / 3 |
| `theme/…/test-en-jobs-shared-slug.php` | 35 / 4 | 35 / 4 |
| `theme/…/test-event-filter-cache.php` | 17 / 5 | 17 / 5 |
| `theme/…/test-guide-en-translation.php` | 667 / 7 | 667 / 7 |
| `theme/…/test-job-en-translation.php` | 28 / 4 | 28 / 4 |
| `theme/…/test-jobs-en-language.php` | 98 / 6 | 98 / 6 |
| `theme/…/test-stage33-bilingual.php` | 196 / 2 | 196 / 2 |
| `theme/…/test-stage41-rest-language.php` | 220 / 2 | 220 / 2 |
| `theme/…/test-stage45-pages.php` | 364 / 2 | 364 / 2 |
| `theme/…/test-translation-completeness.php` | 21 / 2 | 21 / 2 |
| `plugin/conexao-event-importer/test-town-sanitization.php` | 82 / 1 | 82 / 1 (`docs/evidence/2026-09-26-stage-p/town-sanitization-test.log`) |

Baseline for the ten theme suites:
`docs/evidence/2026-11-06-christmas-homepage-welcome/php-theme-suites.txt`
(“66 total, 56 passed, 10 failed”, 37 failing assertions). 37 + the one pre-existing
plugin assertion = the 38 above. **No baseline file was edited.**

Green where this task lives:

| Suite | Result |
|---|---|
| `theme/…/test-commemorative-presentation-dispatch.php` | **120 passed, 0 failed** (HEAD: 100/0) |
| `theme/…/test-commemorative-landing-page.php` | **87 passed, 0 failed** (HEAD: 45/0) |
| All seven commemorative gates (`gate-ambient-decoration`, `gate-assets`, `gate-cpt-privacy`, `gate-placement-assets`, `gate-public-activation`, `gate-single-package`, `gate-translation-ownership`) | pass |
| `theme/…/test-commemorative-christmas-welcome.php` (the modal suite) | pass |
| `plugin/conexao-commemorative/*` (6 suites) | pass |
| `tests/scripts/verify-commemorative-runtime.py` | 66 passed |
| `tests/scripts/verify-documentation-drift.py` | 14 passed (the `docs/README.md` row is consistent) |
| `tests/scripts/verify-script-conventions.py` | 779 passed (the new verifier conforms) |
| `./scripts/lint.sh` | **OK** — syntax clean over 584 files, no new PHPCS violations vs the baseline, PHPStan level 5 clean |
| `php scripts/generate-registry-docs.php --check` | **OK** — 11 plugins, 20 generated regions current, 0 writes |

Failure accounting:

| Category | Count | Detail |
|---|---|---|
| **New failures attributable to this task** | **0** | — |
| Pre-existing failures, unchanged | 11 PHP suites + 2 script suites + 4 acceptance suites | §11 |
| Tests not run | WebKit browser run (not installed); production verification (not authorised) | §14 |
| Environment limitations | the event-importer suites are slow locally (`test-import-continuation`, `test-source-fetch-failure` ≈ 4 min each) but all completed inside the 300 s per-suite timeout and passed | — |

## 11. Known pre-existing failures (unchanged by this task)

Recorded by the welcome-modal report (§12) and re-confirmed by the run in §10; none of
them touches commemorative dispatch, the landing template or the two suites this task
changed.

1. **Ten theme suites** — local EN translation / Polylang relationship data
   (`missing EN translation`, `en_without_pt:empregos`, orphan EN pages): same suites, same
   37 failing assertions as the recorded baseline (`php-theme-suites.txt`).
2. **`plugin/conexao-event-importer/test-town-sanitization.php`** — “no Eircode-contaminated
   or non-town terms in `conexao_town`”, 82 passed / 1 failed, byte-identical to
   `docs/evidence/2026-09-26-stage-p/town-sanitization-test.log`. A local term-data
   condition.
3. **Script contract (2 red)** — `verify-i18n-freshness.py` (the three stale `.pot`
   catalogues: theme, `conexao-admin-ux`, `conexao-commemorative`; POT regeneration is a
   release step and this task adds no string) and `verify-stage8-release-consistency.py`
   (`conexao-data-model` header 1.7.0 vs `dist/release.json` 1.6.3; `conexao-admin-ux`
   1.0.9 vs 1.0.8 — no plugin or `dist/` file is in this task's diff).
4. **HTTP acceptance (4 red)** — `verify-commemorative-http.py`
   (the only failing row is `pt-landing-page /natal/` expecting the retired
   `data-ce-motif="bauble_ornament"`; the route itself answers 200 and renders),
   `verify-routing-http.py` / `verify-release-http.py` (`/empregos/` 302 instead of 200 in
   this local content fixture), `verify-event-occurrence-http.py` (Laois weekly/all-day
   fixtures). All are local content conditions recorded by earlier reports.
5. Every other gate is green, including all seven commemorative gates and
   `verify-commemorative-runtime.py`.

**§11.1 The second `ce-active-marker` row.** On an active landing route the page emits two
hidden markers (`data-ce-placements="site_wide_ambient landing_page"` and
`"landing_page"`) because `build_plan()` still lists the event-driven copy of
`landing_page` alongside the page-owned entry. It is diagnostic metadata with no rendering
effect (the ambient scheduler is driven by `data-ce-ambient-config`, which appears once).
It predates this task and is left untouched: removing it would change the plan's
composition and the `/empregos/`-style “opted-in page whose slug does not match” fallback,
which is a separate behaviour decision.

## 12. Production actions

**None.** No production host was contacted (`conexaobr.ie` is refused by the runner and by
the new browser verifier), no production data mutated, no plugin/theme activated, no
cache purged, no deployment, no release, no tag, no commit, no push.

## 13. Verification commands and results

All commands ran locally: the PHP suites inside the `wordpress` container
(`docker compose exec -T wordpress php …`), the browser verifier on the host.

| # | Command | Result |
|---|---|---|
| 1 | `./scripts/run-tests.sh` (whole harness: in-process PHP + script contract + HTTP acceptance + permanent gates) | see §10 — identical failure set to the recorded baseline |
| 2 | `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-commemorative-presentation-dispatch.php` | **120 passed, 0 failed** (HEAD baseline: 100 passed, 0 failed) |
| 3 | `… tests/test-commemorative-landing-page.php` | **87 passed, 0 failed** (HEAD baseline: 45 passed, 0 failed) |
| 4 | same two suites against the temporary pre-fix precedence | **114/6 failed** and **71/16 failed** — the new assertions catch the defect |
| 5 | `python3 tests/browser/verify-christmas-landing-dispatch-browser.py --out docs/evidence/2026-11-06-christmas-landing-page-active-dispatch-fix` | **165 assertions, 0 failures**, chromium 151.0.7922.34, 8 screenshots |
| 6 | `./scripts/lint.sh` (PHP syntax over all tracked+untracked PHP, PHPCS + baseline gate, PHPStan level 5) | see §10 |
| 7 | `php scripts/generate-registry-docs.php --check` | no registry change was made; the gate is reported in §10 |
| 8 | HTTP matrix + before/after preview GETs (curl, local only) | §5.4 and §8 |

### 13.1 How to reproduce the active state locally

The Christmas occurrence is `12-25` with a 15-day lead-in, so outside 12-10 → 01-01 the
calendar window is closed and `/natal/` is only reachable in its archival form. The
engine's own signed preview is the sanctioned way to request the active state without
touching data or the clock. Mint an **ephemeral** local admin session, pass its cookie and
the preview nonce to the verifier, and destroy the session afterwards:

```bash
# 1) ephemeral session + signed-preview nonce (one process; no content written)
docker compose exec -T wordpress php <<'PHP' > /tmp/ce-preview-session.json
<?php
require '/var/www/html/tests/bootstrap.php';
$user = get_user_by( 'login', 'test' );                 // any local administrator
wp_set_current_user( $user->ID );
$expires = time() + 1800;
$token   = WP_Session_Tokens::get_instance( $user->ID )->create( $expires );
$cookie  = wp_generate_auth_cookie( $user->ID, $expires, 'logged_in', $token );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;                 // so the nonce uses the same token
echo wp_json_encode( array(
	'cookie_name'  => LOGGED_IN_COOKIE,
	'cookie_value' => $cookie,
	'nonce'        => wp_create_nonce( Conexao_Commemorative_Preview::nonce_action( 'christmas' ) ),
	'token'        => $token,
	'user_id'      => (int) $user->ID,
) );
PHP

# 2) run the verifier with those three values (local only; production is refused)
export CONEXAO_PREVIEW_COOKIE_NAME=$(python3 -c "import json;print(json.load(open('/tmp/ce-preview-session.json'))['cookie_name'])")
export CONEXAO_PREVIEW_COOKIE_VALUE=$(python3 -c "import json;print(json.load(open('/tmp/ce-preview-session.json'))['cookie_value'])")
export CONEXAO_PREVIEW_NONCE=$(python3 -c "import json;print(json.load(open('/tmp/ce-preview-session.json'))['nonce'])")
python3 tests/browser/verify-christmas-landing-dispatch-browser.py --out docs/evidence/2026-11-06-christmas-landing-page-active-dispatch-fix

# 3) destroy the ephemeral session (leaves the account's session list unchanged)
docker compose exec -T -e CE_UID=$(python3 -c "import json;print(json.load(open('/tmp/ce-preview-session.json'))['user_id'])") \
  -e CE_TOKEN=$(python3 -c "import json;print(json.load(open('/tmp/ce-preview-session.json'))['token'])") wordpress php <<'PHP'
<?php
require '/var/www/html/tests/bootstrap.php';
WP_Session_Tokens::get_instance( (int) getenv( 'CE_UID' ) )->destroy( (string) getenv( 'CE_TOKEN' ) );
echo "session destroyed\n";
PHP
```

### 13.2 Browser coverage (active state, signed preview)

| Route | 320×568 | 390×844 | 768×1024 | 1366×768 | No-JS 390×844 | CTA navigation |
|---|---|---|---|---|---|---|
| `/natal/` | pass | pass | pass | pass | pass | 200 |
| `/en/christmas/` | pass | pass | pass | pass | pass | 200 |

Per profile the verifier proves: 200 + exactly one preview banner (the event is genuinely
active), exactly one `<h1>`, exactly one landing hero with the language's page title, one
attributed shell (`data-ce-landing`), ≥ 3 landing modules, exactly one journey, no welcome
component, no auto-opened dialog, no horizontal overflow, no broken image, no console
error / uncaught exception / failed request, route-language copy present and the other
language's copy absent, and every CTA pointing at a real absolute URL. Screenshots:
`landing-active-{pt,en}-{320x568,390x844,768x1024,1366x768}.png`.


## 14. Limitations

- **Browser emulation only.** Chromium 151.0.7922.34 through Playwright; the four
  viewport sizes are viewports, not devices. **No WebKit / iOS Safari run** — Playwright
  WebKit is not installed here, so Safari-specific behaviour is unverified in this task
  (unchanged from the welcome-modal report's limitation).
- **The active state is requested, not waited for.** The Christmas occurrence is
  `12-25` with a 15-day lead-in, so on 2026-11-06 the calendar window is closed. The
  active state was exercised through the engine's own signed, capability-gated,
  request-scoped preview — the sanctioned mechanism (`conexao_preview=<event_key>` +
  nonce + `manage_options` + `edit_post`) that bypasses the window without touching data
  or the clock. The preview banner is present in every browser screenshot, so the state is
  visible and cannot be mistaken for public activation.
- **The active state is not the same request as a public December visit** in one respect:
  an admin is logged in, so the admin bar renders. The landing composition itself is
  identical (the plan, the shell and the modules do not read the current user).
- **No production verification.** Out of scope for this task; production remains untouched.

## 15. Evidence paths

`docs/evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/`

| File | What it proves |
|---|---|
| `before-fix-failing-tests.txt` | the 22 assertions that fail against the old precedence |
| `http-active-route-before.txt` / `http-active-route-after.txt` | the live before/after of `/natal/` and `/en/christmas/` with the event active |
| `http-route-matrix.txt` | 14-row route matrix, active and public |
| `landing-active-browser-verification.json` / `.txt` | the 165 browser assertions, per-route, per-profile, plus CTA and no-JS results |
| `landing-active-{pt,en}-{320x568,390x844,768x1024,1366x768}.png` | the eight screenshots |
| `12-full-suite.txt` / `13-lint.txt` / `14-registry-drift.txt` | the whole harness, the static quality gate and the registry drift gate, as run at the end |
| `git-state-start.txt` / `git-state-end.txt` | branch, HEAD, stash count and worktree at both ends |

## 16. Documentation updated

- `docs/README.md` — one row added to the report index for this report (the index is the
  documented place where every report is listed).
- No content-model, routing, plugin or theme document needed a change: no route, content
  type, taxonomy, meta key, placement, package or asset was added or altered. This report
  is the record of a behaviour fix inside an existing contract.

## 17. Rollback / recovery

The change is one self-contained hunk in one file. To roll back:

```bash
git checkout -- wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php
```

(Equivalently: move the `! empty( $plan['landing'] ) && 'landing_page' === $placement_id`
block back below the `$plan['placements']` block.) Rolling back restores the previous
behaviour exactly — including the blank active-state landing page — and requires no data,
cache or activation step. The tests and the browser verifier can be kept (they then fail,
which is the point) or reverted with them.

## 18. Final status

**PASS WITH LIMITATIONS — local only.**

- `/natal/` and `/en/christmas/` render the full Christmas landing experience while the
  event is active, in both languages, at every required viewport, with no duplicate hero,
  no popup on the landing routes and no console error.
- The dispatch defect is fixed at its source (page-owned context precedence), not
  papered over in a template.
- The regression tests fail against the old code and pass against the new code.
- No pre-existing failure changed status (§10/§11); no baseline was edited.
- Nothing was committed, pushed, tagged, merged, deployed or released; production was not
  contacted; the worktree and all nine stashes are preserved.
- Limitations are the ones listed in §14 (browser emulation, no WebKit, active state via
  the signed preview, no production verification). **This is not a release-readiness
  claim.**

