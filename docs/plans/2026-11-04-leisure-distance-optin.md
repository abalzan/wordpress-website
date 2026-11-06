# Plan — Lazer distance indicator: explicit opt-in, friendly failure, approved display table

| | |
|---|---|
| **Task title** | Make the `/lazer/` distance indicator visitor-activated, add a friendly failure message with retry, and apply the approved distance display table |
| **Date** | 2026-11-04 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Repository baseline (starting SHA)** | `5fb0bb3537d3ec2cccbd741691560ad0ff1a6935` |
| **Applicable skill(s)** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-repository`, `wp-security-review` |
| **Plan status** | approved (owner request; see §1) |

## 1. Scope

The feature already exists on this branch — it was planned in
`docs/plans/2026-11-01-lazer-location-services.md`, implemented in `80340da` and
reported in `docs/reports/2026-11-02-lazer-location-services.md`. It provides a
read-only coordinate dataset, global nearest-first proximity ordering and a
distance label on geolocated leisure cards.

This task closes the gaps between that implementation and the owner's brief. It
does **not** rebuild the feature. Four changes:

1. **Explicit opt-in.** Today `initLeisureProximity()` calls
   `navigator.geolocation.getCurrentPosition()` automatically on page load. The
   brief requires an optional control the visitor activates
   (`Mostrar distância de mim`) and states "Do not automatically request
   geolocation permission on page load". Add a real `<button type="button">`
   and request location **only** on its activation.
2. **Friendly failure + retry.** Today every geolocation failure is a silent
   no-op. The brief requires a friendly, non-technical message and a retry.
3. **Approved display table.** Today every distance ≥ 1 km shows one decimal.
   The brief's table wants one decimal only from 1–10 km and whole kilometres
   from 10 km on.
4. **Card wording.** `A aproximadamente %s de si` becomes `%s de você` (the
   brief's format, and the site's established informal "você" voice — e.g.
   `Você chegou ao fim.`).

## 2. Explicit non-goals

- **No data model change.** `_leisure_lat`, `_leisure_lng`,
  `_leisure_coord_source` and `_leisure_coord_verified` are untouched, as are
  all coordinates, provenance, UUIDs and Polylang relationships.
- **No coordinate change.** Nothing is geocoded, inferred or rewritten.
- **No new architecture.** The existing REST endpoints, the pure helper module,
  the card template, the infinite-scroll indirection and the `leisure.css`
  token reuse are all kept. No second card renderer, no new endpoint, no new
  cache.
- **No new visual style.** The control reuses the design-system
  `.btn`/`.btn-outline-dark` (and therefore its `:focus-visible` ring, disabled
  state and 44 px touch target) rather than inventing one.
- **No routing change**, no new template, no SEO/hreflang change, no sitemap
  change.
- **No unrelated UI change.** The distance indicator's placement
  (`.leisure-card-details`) is unchanged.
- **No production action.** No write, deploy, upload or activation.

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Engineering rules, §9.3 catalogue freshness | `docs/engineering-standard.md` |
| Leisure coordinates contract (meta keys, REST visibility, no geocoding) | `docs/content-model.md` |
| Theme internals — the "Lazer location services" section | `docs/themes/conexao-br-irlanda.md` |
| Routes and the `/en/` layer | `docs/routing.md` |
| Test model, the permanent invariant gates | `docs/testing.md` |
| Script inventory, the i18n generator | `scripts/README.md` |
| Plugin registry (unaffected) | `plugins.json` |

The front-end procedure lives in the `wp-frontend-perf` and
`wp-http-acceptance-matrix` skills; the test-writing procedure lives in
`wp-write-in-process-test`. No procedure is restated here.

## 4. Impact

| Area | Impact |
|---|---|
| Content / data | **None.** No post, term, meta value, UUID or translation relationship changes. |
| Routes / URLs | **None.** |
| Database | **None.** |
| `wp-content/` | Behaviour change in `assets/js/main.js`, `assets/js/leisure-proximity.js`, `inc/i18n.php`, `assets/css/leisure.css` and the compiled catalogues. No PHP behaviour change beyond the string catalogue. |
| English / Polylang | The four changed/added JS strings are translated in `en_US.po`/`.mo`; the `.pot` is regenerated with the documented generator. Locale is still read only through `conexao_current_locale()`; the distance number format follows the active locale. |
| Performance | Neutral-to-better: the automatic request on page load is removed, so no geolocation prompt and no coordinate/card round-trip happens unless the visitor opts in. The added DOM is one `<p>` wrapper and one `<button>`. |
| Privacy | Improved: the request is now gated on an explicit visitor action. No coordinate is stored, sent or logged (unchanged). |
| Accessibility | Improved: a labelled, keyboard-operable control with a visible focus ring and a 44 px target; `aria-busy` loading state; `role="status"` failure message. |

## 5. Verification

- `node tests/js/test-leisure-proximity.mjs` — the pure display table.
- `docker compose exec wordpress php …/tests/test-leisure-location-services.php`
  — the endpoint suite (unchanged expectations).
- `python3 tests/browser/verify-lazer-proximity-browser.py --base-url
  http://localhost:8080 --connect 127.0.0.1:8081` — end-to-end: no automatic
  request, opt-in, loading, distances, denial/timeout/unsupported, retry.
- `./scripts/run-tests.sh` — the whole suite, compared with the recorded
  baseline.
- `./scripts/lint.sh` — PHP syntax, PHPCS, PHPStan.
- `./scripts/i18n-make-pot.sh --check` / `scripts/i18n-check.sh`.

_Plan approved 2026-11-04 by the owner's task brief; executed the same day._
