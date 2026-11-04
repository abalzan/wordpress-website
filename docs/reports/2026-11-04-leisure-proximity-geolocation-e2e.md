# Report — Lazer proximity: browser geolocation simulation and end-to-end verification

| | |
|---|---|
| **Stage / task name** | Leisure proximity — simulated browser geolocation + end-to-end distance verification (read-only) |
| **Date** | 2026-11-04 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `5fb0bb3537d3ec2cccbd741691560ad0ff1a6935` |
| **Final SHA** | uncommitted (working tree intentionally left dirty; nothing committed, pushed or deployed) |
| **Working tree at finish** | dirty — pre-existing uncommitted feature work is preserved; this task adds one test file and two documentation/evidence files (see §3) |
| **Skills followed** | `wp-testing`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-repository`, `wp-security-review` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/plans/2026-11-04-leisure-distance-optin.md`, `docs/reports/2026-11-04-leisure-distance-optin.md`, `docs/templates/report.md` |

## 1. Scope completed

The single unresolved question was: **does browser geolocation successfully
provide coordinates to the `/lazer/` front end, and does the feature calculate,
display and sort distances correctly?** It is now answered **yes**, proven in a
real Chromium with a **simulated** visitor position and an **independent**
distance calculation.

Delivered:

1. A new **read-only** browser acceptance script,
   `tests/browser/verify-lazer-proximity-geolocation-readonly.py`, that drives
   the existing Playwright infrastructure against `http://localhost:8080`
   (the canonical URL — the obsolete `--connect 127.0.0.1:8081` workaround is
   **not** used). It seeds, mutates and deletes **nothing**: it consumes the
   existing published leisure records through the public REST endpoints and the
   rendered archive only.
2. Five scenarios, all executed and green: geolocation **granted** (simulated
   Portlaoise), **denied**, **POSITION_UNAVAILABLE (code 2)**, **TIMEOUT
   (code 3)**, and the Geolocation **API unsupported**.
3. An independent Haversine + display-table implementation in the test, so the
   expected nearest-first order and every expected distance string are recomputed
   in Python and never taken from the front end.
4. Browser diagnostics that separate **expected/ignorable** local image 404s
   from **relevant** JavaScript/network failures, and assert the relevant set is
   empty.

Result: **88 assertions passed, 0 failed** (`docs/evidence/2026-11-04-leisure-proximity-geolocation/readonly-browser-run.txt`).

## 2. Scope NOT completed

Nothing in the brief was skipped. Two items were deliberately **not** done, by
design:

- **No database mutation.** The brief forbade seeding/deleting posts for this
  investigation, so the read-only path was written instead of running the
  existing seeding suite (`tests/browser/verify-lazer-proximity-browser.py`),
  which creates and deletes four temporary posts. That suite was **not executed**.
- **No implementation change.** No feature behaviour was touched to make any
  check pass; the feature was already correct. No follow-up change was found
  necessary, so nothing is proposed here.

## 3. Implementation inspected (derived, not guessed)

Everything below was read from the current working tree; no selector or label was
assumed.

| Concern | Finding | Source |
|---|---|---|
| Opt-in control | `<button type="button" class="btn btn-outline-dark leisure-proximity-toggle">` inside `[data-leisure-proximity='leisure-proximity-control']` | `assets/js/main.js` `createControl()` |
| Control accessible name | `Mostrar distância de mim` (idle), `Obtendo sua localização…` (busy), `Tentar novamente` (retry) — always visible text, never icon-only | `setControlState()` |
| Geolocation API | `navigator.geolocation.getCurrentPosition(onPosition, onPositionError, { enableHighAccuracy:false, timeout:10000, maximumAge:0 })` — one-shot, **only** on button activation; never `watchPosition` | `requestPosition()` |
| Feature detection | `classifyGeolocationSupport()` → `unsupported`/`insecure`/`available`; when not `available` the control is never offered | `initLeisureProximity()` |
| Permission / failure handling | `onPositionError()` shows ONE friendly message `[data-leisure-proximity='leisure-proximity-error']` ("Não foi possível obter sua localização. Você pode continuar explorando as atrações normalmente.") and leaves the control as a retry | `onPositionError()` |
| Coordinates REST consumption | `fetch(config.coordinatesUrl)` with `X-Requested-With: conexao-leisure-proximity`, expects `{items:[{id,index,lat,lng}]}` | `fetchCoordinates()` |
| Card REST consumption | `fetch(config.cardsUrl + '?ids=…')`, expects `{html}` rendered from `template-parts/leisure-card.php` | `fetchCards()` |
| Distance maths | `haversineMeters()` (R = 6 371 000 m), null on any invalid input | `assets/js/leisure-proximity.js` |
| Formatting | `formatDistanceValue()`: metres (< 1 km, nearest 10), one decimal (1–10 km), whole km (≥ 10 km), locale-aware | same |
| Sorting | `sortByProximity()`: global nearest-first, ties by original archive `index`, coordinate-less records appended last in original order | same |
| Distance display | `<span class="leisure-card-detail leisure-card-distance" data-leisure-distance="<id>">` → text `"%s de você"` | `applyDistance()` |
| Proximity-enabled state | `[data-leisure-proximity='leisure-proximity-note']` = `Ordenado por proximidade`; control + explanation removed | `announceOrderedByProximity()` / `onActivated()` |
| Test infrastructure | `tests/browser/*.py` drive Chromium via `playwright.sync_api` (`sync_playwright()`, `chromium.launch(headless=True)`); the sibling suite's `--connect` maps `localhost:8080` via `--host-resolver-rules` and is **optional** | `tests/browser/verify-lazer-proximity-browser.py` |

## 4. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `tests/browser/verify-lazer-proximity-geolocation-readonly.py` | **added** | Read-only, simulated-geolocation browser acceptance (the deliverable) |
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/environment.txt` | **added** | Environment facts |
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/readonly-browser-run.txt` | **added** | Full 88-assertion run output |
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/independent-calculation.txt` | **added** | Independent Haversine ground truth |
| `docs/reports/2026-11-04-leisure-proximity-geolocation-e2e.md` | **added** | This report |

**Counts: 5 added, 0 modified, 0 deleted.** No `wp-content/` file was changed.
The pre-existing uncommitted working-tree changes (theme JS/CSS/PHP, translations,
the sibling test, and the `2026-11-04-leisure-distance-optin` plan/report/evidence)
were **preserved untouched**.

## 5. Runtime impact

**Behaviour-neutral.** No application code was changed. The only executable
addition is a test that runs on demand and is **not** wired into
`./scripts/run-tests.sh` (the browser layer is opt-in by design — see
`docs/testing.md` §"The optional browser layer"). `wp-content/` is byte-for-byte
unchanged by this task.

## 5a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** |
| No production content, Polylang or database change | **confirmed** (§7) |
| No application behaviour change (`wp-content/` diff empty) | **confirmed** |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |
| Permanent test gates not weakened | **confirmed** (no gate file touched) |

## 6. Content / data impact

**None.** The new test performs no `wp_insert_post`, `wp_update_post`,
`wp_delete_post`, `update_post_meta` or `delete_post_meta`
(`grep` returns none). Published `leisure` post count was **293 before and 293
after** the run, read directly from the container:

```
docker compose exec -T wordpress php -r '… WP_Query( post_type=leisure, post_status=publish ) …'
published leisure posts: 293
```

## 7. Route / HTTP impact

**None.** No route, redirect, canonical, hreflang or sitemap change. The
endpoints exercised are the pre-existing read-only
`GET /wp-json/conexao/v1/leisure-coordinates` and
`GET /wp-json/conexao/v1/leisure-cards`; both returned HTTP 200 and were only
read.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | Local Docker stack only |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | 293 → 293 published leisure posts |

## 9. Test scenarios and results

Visitor position simulated through Playwright context geolocation — **Portlaoise,
Co. Laois: `{ latitude: 53.0344, longitude: -7.2994 }`**, permission granted for
`http://localhost:8080`. The real user's location was never requested.

| # | Scenario | How it was produced | Result |
|---|---|---|---|
| 1 | **Granted** | `new_context(geolocation=VISITOR, permissions=["geolocation"])` | **PASS** — position delivered, grid re-ordered, distances shown |
| 2 | **Denied** | `new_context(geolocation=VISITOR, permissions=[])` (Chromium returns code 1) | **PASS** — friendly message, order unchanged, retry offered |
| 3 | **Unavailable** | `getCurrentPosition` mocked to error `code 2` | **PASS** — same graceful handling |
| 4 | **Timeout** | `getCurrentPosition` mocked to error `code 3` | **PASS** — same graceful handling |
| 5 | **Unsupported** | `navigator.geolocation` removed via own-property getter | **PASS** — no control offered, nothing requested |

### Scenario 1 — geolocation granted (the core question)

| Check | Result |
|---|---|
| `/lazer/` navigation | HTTP 200 |
| Proximity JS loaded (`leisure-proximity.js` + `window.ConexaoLeisureProximity`) | present |
| Coordinates endpoint | HTTP 200, **293 records, 289 with a valid pair**, keys `id/index/lat/lng` |
| No request on load (opt-in only) | `__geoRequests == 0` before the click |
| Click → geolocation request | exactly **1** request; simulated position accepted |
| Proximity-enabled state | note `Ordenado por proximidade` present; control + explanation removed; no error note |
| Nearest attraction | `post-10623` — the **independently computed** nearest |
| Visible order (10 cards) | matches the independent nearest-first order **exactly** |
| Distances shown | 10 / 10 geolocated cards |
| Distance values | 10 / 10 **exact** match to the independent calculation |
| Nearest distance | `6,1 km de você` (independent: 6115.7 m → 6.1 km) |
| Sorted ascending | `[6100, 10000, 11000, 13000, 14000, 17000, 26000, 28000, 29000, 29000]` m |
| Coordinate-less records | 4 records (ids 22734, 22737, 22738, 22739) — sorted last by the independent calculation, no distance, no JS error |
| JavaScript / network | **0** relevant errors (40 ignorable `/wp-content/uploads/` image 404s) |

Independent ground truth (`docs/evidence/.../independent-calculation.txt`):

| rank | id | metres | formatted |
|---|---|---|---|
| 1 | 10623 | 6115.7 | 6,1 km |
| 2 | 10629 | 10440.9 | 10 km |
| 3 | 10625 | 10603.1 | 11 km |
| 4 | 10631 | 13392.8 | 13 km |
| 5 | 10633 | 14071.0 | 14 km |
| 6 | 10627 | 16583.2 | 17 km |
| 7 | 10778 | 26104.3 | 26 km |
| 8 | 10643 | 27807.4 | 28 km |
| 9 | 10849 | 29157.6 | 29 km |
| 10 | 10641 | 29395.8 | 29 km |

### Scenarios 2–4 — denied / unavailable / timeout

Each produced: page loads (HTTP 200); control offered; **exactly one**
geolocation request; **exactly one** friendly message
`Não foi possível obter sua localização. Você pode continuar explorando as atrações normalmente.`;
no proximity note; **no distances**; the default server order **unchanged** (no
fake location substituted); control stays as `Tentar novamente`; a retry issues a
**second** request and never stacks a second message; no JavaScript error and no
relevant failed request.

### Scenario 5 — unsupported

No control offered, explanation removed, no distances, no requests, no errors.

## 10. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3.14 tests/browser/verify-lazer-proximity-geolocation-readonly.py --base-url http://localhost:8080` | **0** | **88 passed, 0 failed** |
| `python3.14 -m py_compile tests/browser/verify-lazer-proximity-geolocation-readonly.py` | 0 | syntax OK |
| `curl -o /dev/null -w '%{http_code}' http://localhost:8080/lazer/` | 0 | `200` |
| `curl -o /dev/null -w '%{http_code}' '…/wp-json/conexao/v1/leisure-coordinates?lang=pt'` | 0 | `200` |
| `docker compose exec -T wordpress php -r '… WP_Query …'` | 0 | `published leisure posts: 293` |

The browser layer is **opt-in** and not part of `./scripts/run-tests.sh`, so no
aggregate in-process/HTTP/script numbers are reported here; the full repository
suite was not run for a test-only, behaviour-neutral addition.

## 11. Failure proofs / negative tests

The failure branch is proven non-vacuous: scenarios 2–4 exercise the real
`onPositionError()` path (permission denial, `code 2`, `code 3`) and assert the
message appears, distances stay absent and the default order is preserved. A
`getCurrentPosition` wrapper counts every call, so "nothing requested before the
click" and "the retry issues a second request" are measured, not assumed. The
granted scenario's expected order and distances come from an independent Python
Haversine, so the test would go red if the front end's maths or ordering drifted.

| Proof | Result |
|---|---|
| Denied / unavailable / timeout → message + retry + no distances | PASS |
| Mocked error codes reach the UI (not swallowed) | PASS |
| Independent order ≠ front-end order would fail the exact comparison | guard present |
| Independent distance ≠ UI distance would fail the exact comparison | guard present |

## 12. Regression comparison

No application code changed, so there is no before/after behaviour delta. Before
this task the granted path was unverified in a browser (the seeding suite was not
run); after, it is proven end-to-end against an independent calculation.

| | Before | After |
|---|---|---|
| Failing browser assertions (read-only path) | n/a (path did not exist) | **0 / 88** |
| Failing in-process / HTTP / script suites | not run (out of scope) | not run (out of scope) |

## 13. Known pre-existing failures

Missing image assets under `/wp-content/uploads/` return HTTP 404 in this local
stack (40 observed on the granted page, 18 on the lighter pages). These are
**expected local-development issues**, are classified `ignorable` by the test's
diagnostics, and are excluded from the "relevant" assertion. No other failure was
observed.

## 14. Limitations

- The local stack is `http://localhost:8080` (a **secure context** by the
  localhost exception, so `window.isSecureContext === true`). A real `http://`
  non-localhost origin would be `insecure` and the control would be withheld —
  that branch is unit-covered (`classifyGeolocationSupport`) and was not re-driven
  here.
- The run reflects the **current local dataset** (293 leisure records). The
  independent ground truth is recomputed from the live endpoint on every run, so
  it stays correct if the data changes.
- Production (WordPress.com) was not contacted and is out of scope.

## 15. Evidence paths

| File | Proves |
|---|---|
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/environment.txt` | Docker context, Playwright/Chromium/Python versions, branch, HEAD |
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/readonly-browser-run.txt` | Full 88-assertion output (exit 0) |
| `docs/evidence/2026-11-04-leisure-proximity-geolocation/independent-calculation.txt` | Independent Haversine ground truth + dataset summary |

## 16. Rollback / recovery

Delete `tests/browser/verify-lazer-proximity-geolocation-readonly.py`, the
`docs/evidence/2026-11-04-leisure-proximity-geolocation/` directory and this
report. Nothing else changed — no application code, no data, no configuration.
There is no state to unwind.

## 17. Final status

**Final status: PASS**

Every applicable check ran with real numbers: **88 assertions passed, 0 failed**
in a real Chromium with a simulated location, the distance/order results match an
**independent** Haversine calculation exactly, no relevant JavaScript or network
error was observed, and the database was provably unchanged (293 → 293). No
required verifier was unavailable.

_Last verified: 2026-11-04 by the Lazer proximity geolocation end-to-end verification_
