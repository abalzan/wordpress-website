# Plan — performance baseline, bilingual browser acceptance, scheduled/computed-meta security, PHPStan

| | |
|---|---|
| **Task title** | Complete the remaining engineering roadmap in one branch |
| **Date** | 2026-11-28 |
| **Author / agent** | Cline (senior WordPress/PHP + frontend + security engineer) |
| **Branch** | `roadmap/performance-bilingual-security-phpstan` |
| **Repository baseline (starting SHA)** | `f97a8fe` (merge of PR #14, `master`) |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-http-acceptance-matrix`, `wp-security-review`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Four roadmap areas plus the documentation they require, in **one** branch and one
PR against `master`:

1. A reproducible, local-only **frontend performance baseline** with real
   measurements, and only evidence-backed optimisations.
2. A **bilingual browser acceptance suite** on the existing opt-in
   Playwright/Chromium layer.
3. An **extension of the write-security audit and permanent gates**: scheduled
   write paths and computed metadata keys.
4. **PHPStan baseline reduction** at level 5, starting with the deferred
   DOM/XML importer findings.

## 2. Explicit non-goals

- **No branch protection / required status checks.** That is repository
  configuration owned by the maintainer, not a code change.
- **No push to `master`, no merge, no deploy, no production write**, and no claim
  about production behaviour.
- **No new dependency.** The browser layer reuses Playwright/Chromium already
  present; the security gate stays standard-library Python.
- **No speculative performance work.** A change is made only if a measurement
  supports it; otherwise the baseline is documented and no theme asset changes.
- **No PHPStan level change**, no `--generate-baseline`, no new suppression.
- **No PT content, translation, URL or Polylang change.**

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §0, §2.1, §3.2, §7, §8, §9.2;
`docs/testing.md`; `docs/frontend.md`; `docs/development.md`;
`docs/reports/2026-11-27-rest-admin-security-audit.md`;
`docs/plans/2026-11-27-reduce-phpstan-baseline.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (loop), `wp-frontend-perf` (measurement), `wp-http-acceptance-matrix` (browser layer), `wp-security-review` (scheduled + meta), `wp-testing` (verification) |
| **Preconditions** | Local Docker stack running; Playwright + Chromium available; WordPress-dependent suites need an isolated test database |
| **Guardrails that bind this task** | One concern per commit; no gate weakened; baseline never grows; real numbers only; fail closed on an unavailable environment |
| **Verification commands** | `./scripts/lint.sh`, `./scripts/run-tests.sh`, `python3 tests/scripts/verify-write-endpoint-authorization.py`, the browser suites, `vendor/bin/phpstan analyse` |
| **Evidence location** | `docs/evidence/2026-11-28-frontend-performance/` |

## 4. Current-state findings

- Theme performance work is already substantial (hero LCP preload + responsive
  ladder, non-blocking Google Fonts, `conexao_asset_version()` cache busting,
  language-scoped transients, event-filter invalidation matrix). No repeat
  optimisation is assumed.
- `tests/browser/` already provides twelve opt-in Playwright suites with a
  shared shape: refuse production host → exit `2` when the environment is
  unavailable → probe the real rendered DOM → `--negative-control`.
- `tests/scripts/verify-write-endpoint-authorization.py` audits 34
  `admin_post_*`/`wp_ajax_*` handlers, 2 REST routes and 21 meta registrations,
  and reports **13 computed-key meta registrations as statically undecidable**
  (finding I-3 of the previous audit).
- Two scheduled hooks exist in custom code:
  `conexao_commemorative_boundary` and
  `conexao_event_import_continuation`. Neither is audited by any gate today.
- `phpstan-baseline.neon` holds **148 entries / 213 suppressed messages**. The
  DOM/XML importer findings the previous plan deferred are still baselined:
  24 `method.notFound` / `argument.type` findings across six source parsers,
  plus one `identical.alwaysTrue`, one `array.duplicateKey`, one
  `empty.variable` and four `isset.offset` findings in the same area.


## 5. Proposed approach

### Area 1 — performance baseline

A new opt-in collector (`tests/browser/verify-frontend-performance-baseline.py`)
drives real Chromium against the **local** stack only, and for each route ×
viewport × language × repetition records TTFB, LCP, CLS, INP (only where a real
interaction exists), transfer size and request count from the browser's own
`PerformanceObserver` / Navigation Timing / Resource Timing entries. It writes a
machine-readable JSON per run plus a median summary. Measurements are taken
before any change; an optimisation is implemented only if the data names a
bottleneck that is ours to fix and is not already addressed.

### Area 2 — bilingual browser acceptance

One new suite (`tests/browser/verify-bilingual-journeys-browser.py`) covering
PT/EN home, guides archive + category filter, jobs landing + `?tipo=` filter,
canonical + hreflang, the language switcher (gated on the real
`CONEXAO_LANGUAGE_SWITCHER_ENABLED` policy), desktop and mobile viewports,
keyboard focus visibility, no horizontal overflow, and zero uncaught page
errors — with a negative control.

### Area 3 — security gates

Extend the existing gate (never fork it) with two detectors:

- **E — scheduled write paths.** Resolve every `wp_schedule_event()` /
  `wp_schedule_single_event()` hook to a registered callback in the tree; assert
  the callback resolves; assert the hook is not an `admin_post_*`/`wp_ajax_*`
  surface (a user-facing endpoint must never be a scheduling target) and has no
  `nopriv` registration; assert the scheduled callback does not read request
  superglobals (a cron callback has no request context, so a superglobal read is
  argument injection); and do **not** demand a nonce/`current_user_can()` inside
  a cron callback.
- **F — computed meta keys.** Resolve `self::CONST`, `Class::CONST`, array-literal
  loop keys and static-property loops so a computed registration is judged like
  a literal one. Keep literal behaviour identical, keep the coverage floors, and
  add negative proofs for both new detectors.

Two production registrations are REST-exposed, protected and declare no
`auth_callback` (the `_provider_*` loop and the legacy editorial-fields loop).
WordPress already fails those closed (`wp-includes/meta.php` falls back to
`__return_false` for protected keys), so the fix is to **declare that intent
explicitly** (`'auth_callback' => '__return_false'`) — provably identical
runtime behaviour, now auditable.

### Area 4 — PHPStan

`instanceof DOMElement` guards (one shared `as_element()` narrowing helper on the
source base class) at every element-API call site; a null-safe `extract_text()`
signature instead of four call-site casts; removal of the unreachable
`DOMElement::querySelector()` branch; truthful `@param mixed` on decoded JSON;
the redundant `'Z' ===` comparison simplified to `isset()`; the duplicated
`'may'` map key removed; the provably-dead `empty( $url )` removed. Then delete
**only** the baseline entries PHPStan reports as `ignore.unmatched`.


## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `tests/browser/verify-frontend-performance-baseline.py` | new | area 1 |
| `tests/browser/verify-bilingual-journeys-browser.py` | new | area 2 |
| `docs/evidence/2026-11-28-frontend-performance/*` | new | measurement evidence |
| `docs/reports/2026-11-28-frontend-performance-baseline.md` | new | area 1 report |
| `docs/reports/2026-11-28-scheduled-and-computed-meta-security.md` | new | area 3 report |
| `docs/reports/2026-11-28-phpstan-baseline-reduction.md` | new | area 4 report |
| `docs/reports/2026-11-27-rest-admin-security-audit.md` | extend scope | area 3 |
| `tests/scripts/verify-write-endpoint-authorization.py` | detectors E/F | area 3 |
| `wp-content/plugins/conexao-data-model/*` | explicit fail-closed `auth_callback` | area 3 |
| `wp-content/plugins/conexao-event-importer/includes/sources/*.php` | DOM guards | area 4 |
| `wp-content/plugins/conexao-event-importer/tests/test-event-source-dom-hardening.php` | new | area 4 |
| `phpstan-baseline.neon` | delete only unmatched entries | area 4 |
| `docs/frontend.md`, `docs/testing.md`, `docs/development.md` | keep accurate | §9.2 |

## 7. Files explicitly expected NOT to change

`plugins.json` (no plugin set change), `composer.json`/`composer.lock`,
`phpstan.neon.dist`, `phpcs.xml.dist`, `.github/workflows/ci.yml`,
`compose.yaml`, `docs/routing.md`, any PT/EN content or Polylang configuration,
and `.htaccess`.

## 8. Content / data impact

- Records created / updated / deleted: **none in production**; the browser
  suites write no content.
- Does this write content? **No.**
- PT content changed? **No.**

## 9. Route / HTTP impact

None. No route, redirect, sitemap or matrix row changes; no output changes.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none.
- PT immutable: **yes** — no content write path is touched.
- Language-scoped cache keys affected: none.
- Completeness gate: unaffected.
- The language switcher is **read**, not modified; the suite follows its flag.

## 11. Security impact

- Capabilities checked: unchanged.
- Nonces added/changed: **none**.
- Sanitisation / escaping: unchanged.
- REST surface: the two `__return_false` declarations are the WordPress default
  for a protected key, so no REST client gains or loses a capability.
- SQL: unchanged.
- Secrets: none.
- Gates: two detectors **added**; no existing detector, floor or proof removed.

## 12. Performance impact

No theme asset, query or cache change unless a measurement justifies one. The
`empty( $url )` removal is inside an already-filtered loop.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a |
| What is the verification of the production result? | n/a — local only |

## 14. Test plan

- `python3 tests/scripts/verify-write-endpoint-authorization.py` (gate +
  new negative proofs).
- `vendor/bin/phpstan analyse` (0 errors, fewer baseline entries) and
  `./scripts/lint.sh`.
- New in-process parser suite; existing importer suites.
- `./scripts/run-tests.sh --scripts` and the full `./scripts/run-tests.sh`.
- New browser suites, happy path and `--negative-control`, when the local
  environment is available.

## 15. Acceptance matrix plan

None. The browser suites are opt-in and not part of the default runner, which is
unchanged.

## 16. Rollback plan

`git revert` the PR: baseline file, source guards, gate detectors and the two
`auth_callback` declarations return to their previous form. No data, option,
transient or cron entry is created by this change.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/frontend.md` | record the measured baseline + how to reproduce it |
| `docs/testing.md` | document the new browser suites and the two new security detectors |
| `docs/development.md` | new PHPStan entry/message counts and commands |

## 18. Release implications

`conexao-data-model` and `conexao-event-importer` change source, so both take one
PATCH bump in this PR. No release is built or deployed.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Resolving computed meta keys turns a latent gap into a red gate | Medium | Declare the fail-closed default explicitly; prove via the existing REST permission suite that writes still 403 |
| A DOM guard changes parser output | Low | Guards only convert "attribute absent" cases that currently raise on a non-element; existing importer suites re-run |
| Local measurements are not production numbers | High | Stated as a limitation everywhere; no budget is derived from them |
| Browser suite is flaky | Medium | Median of repetitions, generous timeouts, exit `2` (never a pass) when the environment is missing |

## 20. Verification gates

1. `vendor/bin/phpstan analyse` → exit 0, no `ignore.unmatched`, baseline
   entries strictly < 148.
2. `python3 tests/scripts/verify-write-endpoint-authorization.py` → exit 0 with
   the new detectors and new negative proofs.
3. `./scripts/lint.sh` → exit 0.
4. `./scripts/run-tests.sh --scripts` → exit 0.
5. Importer suites → same result as before.

## 21. Completion criteria

§14 of the engineering standard holds: static quality green, the PHP suite green
at the same numbers, the baseline demonstrably smaller, the reports carrying real
numbers and stated limitations, and no production action.

_Last verified: 2026-11-28 by the roadmap completion plan_
