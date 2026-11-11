# Report — Christmas micro-animations (“One Christmas, Two Skies” shared-star motion)

| | |
|---|---|
| **Stage / task name** | Christmas micro-animations |
| **Date** | 2026-11-08 |
| **Author / agent** | Cline |
| **Branch** | `commemorative-events` |
| **Start SHA** | `d7602d7556caabf59d00b70ef1aaaed2fb8cd267` |
| **Final SHA** | `d7602d7556caabf59d00b70ef1aaaed2fb8cd267` (unchanged — no commit) |
| **Working tree at finish** | dirty with **exactly** the 4 modified + 5 new paths in §3; 9 pre-existing stashes untouched |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-security-review`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, the 2026-11-06 Christmas reports |
| **Plan** | [`2026-11-08-christmas-micro-animations.md`](../plans/2026-11-08-christmas-micro-animations.md) |
| **Evidence** | [`docs/evidence/2026-11-08-christmas-micro-animations/`](../evidence/2026-11-08-christmas-micro-animations/) (41 files: 3 JSON reports, 35 screenshots, 2 harness HTML, 1 README) |

## 1. Verdict

**PASS WITH LIMITATION.**

Five micro-animation ideas were assessed against the real Christmas markup; four were
implemented and one (sparse ambient star glints) was found to be **already implemented**
by the existing `site_wide_ambient` placement + shared scheduler, so no duplicate was
added (a second ambient scheduler is architecturally forbidden). The change is **purely
additive** — 427 insertions, **0 deletions** across the two asset files — so no existing
behaviour (modal, journey, ambient, copy, layout) was modified.

The limitation is environmental and stated in §13: only **Chromium** (Playwright) was
available — **no WebKit/Safari and no physical device** — and no production verification
was performed (out of scope). Every effect and every regression check that could run
here ran and passed with real numbers (§7).

## 2. Scope completed / not completed

**Completed**

- **Animation 1 — welcome star twinkle**: CSS-only, fires once when the welcome modal
  opens (1.2 s, two gentle twinkles, `iteration-count: 1`), no JS timer, no delay to
  `showModal()`, suppressed under reduced motion.
- **Animation 2 — selected CTA sparkle**: 3–5 golden particles near the welcome CTA and
  the landing closing CTA, pointer **and** keyboard, bounded (≤ 6), never intercepts
  navigation, suppressed under reduced motion.
- **Animation 3 — gentle content reveal**: `IntersectionObserver` reveals landing
  journey/modules/closing once; content stays fully visible without JS and under
  reduced motion.
- **Animation 4 — sparse ambient star glints**: **retained as-is** (already implemented
  by the existing ambient placement; no second scheduler added).
- **Animation 5 — shared-star journey connection**: one-time glow pulse of the journey's
  shared star on first visibility (no transform, so it cannot fight the user-controlled
  star travel).
- Tests: a new Node runtime suite (14 cases) + a new opt-in real-browser verifier;
  registered in the existing runtime gate.

**Not completed**

| Not done | Why |
|---|---|
| A second ambient glint system | Architecturally forbidden (one ambient scheduler); the existing one already satisfies the brief — documented as Animation 4 retained |
| Auto-replaying the full “star travels between two skies” | It would compete with the interactive journey control; a restrained one-time glow was used instead (§4) |
| Production deploy / release / activation | Explicitly out of scope; nothing was written to production |
| WebKit / iOS Safari browser run | Playwright WebKit not installed (same limitation as the 2026-11-06 reports) |
| Physical-device testing | Browser emulation only |

## 3. Files added / modified / deleted

**Modified (4)**

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` | +116 lines: §5 micro-animation keyframes + component styles + reduced-motion overrides | Visual motion for animations 1, 2, 3, 5 |
| `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` | +310 lines: `prefersReducedMotion()`, `createCtaSparkle()`, `createContentReveal()`, `pulseJourneyStar()`, `bootMicroAnimations()`, exports, one call in `boot()` | Triggers/orchestration |
| `tests/scripts/verify-commemorative-runtime.py` | +1 line: register the new test file in `TEST_FILES` | The gate runs the new suite |
| `docs/README.md` | Report + evidence index rows, one `_Last verified` line | Documentation convention |

**Added (5, all untracked)**

| Path | Change |
|---|---|
| `tests/node/christmas-micro-animations.test.js` | New Node runtime suite (14 cases) |
| `tests/browser/verify-christmas-micro-animations-browser.py` | New opt-in real-browser verifier |
| `docs/plans/2026-11-08-christmas-micro-animations.md` | Plan |
| `docs/reports/2026-11-08-christmas-micro-animations.md` | This report |
| `docs/evidence/2026-11-08-christmas-micro-animations/` | Evidence (3 JSON + 37 screenshots + README) |

**Deleted: none.** Net: **0 deleted**. Every production change is an addition to the two
Christmas asset files; no template, plugin, placement, event, route or asset file was
added or altered.

## 4. The five effects: implemented / omitted / adjusted

| # | Effect | Decision | Reason |
|---|---|---|---|
| 1 | Welcome star twinkle | **Implemented (CSS only)** | The dialog already has the shared star and `open()` already adds `.is-welcome-open`; a CSS keyframe is the smallest, safest trigger — no JS timer, so the welcome's “no scheduler” guarantee is untouched. |
| 2 | Selected CTA sparkle | **Implemented** | Clear attachment points (`.ce-christmas-hero__cta`, `.ce-christmas-closing__cta`); delegated click, bounded particles, navigation untouched. |
| 3 | Gentle content reveal | **Implemented** | Landing-only markup (`.ce-christmas-journey`, `.ce-module`, `.ce-christmas-closing`); `IntersectionObserver` with a no-JS-safe ready class. |
| 4 | Sparse ambient star glints | **Retained as-is (no new code)** | Already implemented by the `site_wide_ambient` placement + shared scheduler (`createStarDecoration()`): one shared star at the edges, max 1 at a time, calm 2–4 s, generous randomised pause, pauses on `document.hidden`/reduced motion, removes nodes. The framework **forbids a second ambient scheduler**, so a parallel glint system would violate the architecture. Documented rather than duplicated. |
| 5 | Shared-star journey connection | **Implemented (adjusted to a glow pulse)** | The journey already has the interactive star travel between the two skies (user-controlled). An automatic *travel* would compete with that control, so a one-time **glow pulse** (no `transform`) was used: it reuses the shared star, is one-shot, and cannot fight the tab travel. |

## 5. Exact trigger, duration, replay and reduced-motion per effect

| Effect | Trigger | Duration | Replay rule | Reduced motion |
|---|---|---|---|---|
| Welcome star twinkle | Welcome modal opens (`is-welcome-open` added by `open()`) | 1.2 s, two twinkles | Once per open (welcome opens once per tab session) | `animation: none !important` |
| CTA sparkle | `click` on welcome/landing CTA (pointer or Enter/Space) | 600 ms per particle | Once per activation; 1 s per-button cooldown; ≤ 6 particles total | JS skips burst; CSS `display: none` |
| Content reveal | Target enters viewport (`IntersectionObserver`, threshold 0.1) | 0.45 s transition | Once per target (unobserved after reveal); already-visible reveals immediately | JS does not add ready class; content stays visible |
| Ambient glints (existing) | Existing scheduler (calm cadence) | 2–4 s (existing) | Existing session cap | Existing scheduler stops |
| Journey star pulse | Journey first becomes visible | 1.0 s | Once per page view (guard + removed after `animationend`) | `animation: none !important` |

## 6. Preservation confirmation

- **Popup**: welcome modal, image, headline, paragraph, star, CTA and modal behaviour are
  the existing code — the change is purely additive (0 deletions). The welcome browser
  verifier passed **546 assertions, 0 failures** across 8 viewports, PT + EN,
  public/preview/harness, reduced motion and no-JS: modal still opens in the top layer,
  closes, restores focus/scroll, session dismissal intact, nothing left inline.
- **Landing page**: journey, modules, closing copy, CTA destination and artwork unchanged
  (no template edits). The signature verifier passed **0 failures**: journey tabs, no
  overflow, reduced motion, no console error.
- **Preview**: preview banner/semantics untouched (no preview code changed); the twinkle
  runs on the same modal in preview as in public.

## 7. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `node --test tests/node/christmas-{welcome,journey,micro-animations}.test.js tests/node/commemorative-ambient.test.js` | 0 | **52 pass, 0 fail** (38 pre-existing + 14 new) |
| `python3 tests/scripts/verify-commemorative-runtime.py` | 0 | **104 passed, 0 failed** — `GATE-RESULT status: pass` |
| `docker compose exec … php …/test-commemorative-christmas-welcome.php` | 0 | **145 passed, 0 failed** |
| `docker compose exec … php …/test-commemorative-christmas-journey.php` | 0 | **88 passed, 0 failed** |
| `docker compose exec … php …/test-commemorative-landing-page.php` | 0 | **87 passed, 0 failed** |
| `./scripts/lint.sh` | 0 | PHP syntax clean (584 files), no new PHPCS violations vs baseline, PHPStan level 5 clean |
| `./scripts/run-tests.sh --scripts` | 1 | **21 suites: 19 passed, 2 failed** — the 2 failures are **pre-existing** (§12) |
| `python3 tests/browser/verify-christmas-welcome-browser.py --out …/welcome` | 0 | **546 assertions, 0 failures** (8 viewport profiles × PT/EN) |
| `python3 tests/browser/verify-christmas-signature-browser.py --out …/signature` | 0 | **0 failures** (journey, overflow, reduced motion, no console error) |
| `python3 tests/browser/verify-christmas-micro-animations-browser.py --out …/micro` | 0 | **14 assertions, 0 failures** (twinkle, reveal, pulse, pointer+keyboard sparkle, reduced motion) |

### Numeric test results

- **In-process PHP**: Christmas welcome **145/0**, journey **88/0**, landing **87/0** —
  3 suites, 0 failures.
- **Node runtime**: **52 cases, 52 pass, 0 fail**; the runtime gate aggregates **104
  passed, 0 failed** across its four suites.
- **Static analysis**: lint **OK** (syntax, PHPCS-vs-baseline, PHPStan).
- **Script-contract**: **19/21** — the two red suites are pre-existing (§12); the
  `commemorative_runtime` suite I touched is green (**104/0**).

### Browser acceptance

- Welcome verifier: **546 assertions, 0 failures**, 26 evidence files.
- Signature verifier: **0 failures**, 9 evidence files.
- Micro-animation verifier: **14 assertions, 0 failures**, 5 evidence files.
- Combined: **560 browser assertions, 0 failures**, 41 evidence files.

## 8. Browser profiles, languages and routes tested

| Route / state | Profiles | Languages |
|---|---|---|
| Homepage welcome (signed preview + generated harness) | 320×568, 375×667, 390×844, 768×1024, 1024×768, 1366×768, 1440×900, landscape 844×390 | PT + EN |
| Public `/` and `/en/` (no welcome) | 390×844 | PT + EN |
| Landing `/natal/` and `/en/christmas/` | 320, 390, 768, 1366 | PT + EN |
| Micro-animation effects (twinkle, reveal, pulse, sparkle, reduced motion) | 390×844, 1366×768 | PT (`/natal/`) + preview |

Screenshots/JSON: `docs/evidence/2026-11-08-christmas-micro-animations/{welcome,signature,micro}/`.

## 9. Performance observations

- **No new asset request**: the animations reuse `christmas.js` / `christmas.css`
  (already enqueued only when a Christmas placement renders). No preloaded artwork, no
  external font, no network dependency.
- **Bounded DOM**: sparkle particles are capped at **6** and removed on `animationend`
  (no timer); the ambient layer keeps its existing one-node-at-a-time scheduler.
- **No continuous rAF / no setInterval**: the file contains **zero**
  `setTimeout`/`setInterval`/`requestAnimationFrame` calls (proved by the welcome
  suite's source grep, which still passes). Particle/pulse removal is tied to the CSS
  animation lifecycle (`animationend`).
- **One `IntersectionObserver`**, unobserving each target after its single reveal.
- **No layout-affecting properties**: only `transform`, `opacity`, `filter`. No height,
  margin or grid animation; the welcome browser verifier confirms hero geometry is
  identical at open/close/reload (no gap, no shift).

## 10. Failure proofs / negative tests

The Node suite proves the verifier can fail: it drives the **real** controllers with a
fake DOM and asserts the negative paths — no sparkle on a non-CTA, no sparkle under
reduced motion, no reveal without `IntersectionObserver`, no reveal under reduced motion,
no controller on a non-landing route, particle cleanup after `animationend`, per-button
cooldown, the global ≤ 6 cap, and idempotent boot. Each was observed failing while the
implementation was being debugged (e.g. a `var`-in-loop closure that left particles
behind, and a method-shorthand `IntersectionObserver` that is not a constructor), then
passing after the fix.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Node runtime cases | 38 pass / 0 fail | **52 pass / 0 fail** (+14 new) |
| Runtime gate | 88 passed / 0 failed | **104 passed / 0 failed** (+16) |
| Christmas in-process suites | green | **green** (145/88/87) |
| Lint | green | **green** |
| Script-contract failing suites | `i18n_freshness`, `stage8_release_consistency` | **same two** (no change) |
| Browser welcome | 0 failures | **0 failures** |

The diff of the failing-suite lists is **empty** — no pre-existing failure changed
status and no baseline was edited.

## 12. Known pre-existing failures

Both are pre-existing repository/data conditions this task did not create and was not
authorised to resolve (both were already recorded in the 2026-11-06 reports):

1. **`verify-i18n-freshness.py`** — 3 stale `.pot` catalogues older than PHP sources
   (`hero.php`, `class-editor.php`, `class-conexao-commemorative-admin.php`). This task
   changed **no PHP and no `.pot`**, so the staleness is independent of this change.
2. **`verify-stage8-release-consistency.py`** — 4 plugin-header vs `dist/release.json`
   version mismatches (`conexao-data-model`, `conexao-admin-ux`). This task changed no
   plugin header and no `dist/` artifact.

Neither gate was silenced; no baseline was edited.

## 13. Limitations

- **Chromium only.** Playwright WebKit/Safari is not installed and no physical device
  was available — same limitation as the 2026-11-06 Christmas reports. The animations
  use widely-supported CSS (`animation`, `filter`, `transform`, custom properties) and
  `IntersectionObserver`, but non-Chromium engines were **not** exercised here.
- **No production verification** (out of scope; production is untouched).
- **Landing composition state**: the local site currently renders the Christmas landing
  composition (verified present on `/natal/`), and the welcome modal was exercised
  through the engine's own signed preview, because the public Christmas window is
  10–25 December.
- The micro-animation browser verifier is PT (`/natal/`) for the landing effects; the
  equivalent EN landing (`/en/christmas/`) is covered by the signature verifier's
  structural/no-console checks but not re-run through the sparkle/reveal assertions.

## 14. Evidence paths

`docs/evidence/2026-11-08-christmas-micro-animations/` — see its `README.md` for the
file→claim map (3 JSON reports, 35 screenshots, 2 harness HTML, 1 README = 41 files).

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/README.md` | Report + evidence index rows |
| `docs/evidence/2026-11-08-christmas-micro-animations/README.md` | Evidence index |
| `docs/plans/2026-11-08-christmas-micro-animations.md` | Plan |

No procedure moved out of a document.

## 16. Rollback / recovery

Revert the two asset files, delete the new test/verifier/plan/report/evidence and revert
`verify-commemorative-runtime.py`. No content, option, schema, cache or storage state is
created by this change (the dismissal key, ambient cap and journey state are all
client-side and per-tab), so no data reversal is needed.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Everything this task could verify locally passed with real numbers: 52 Node cases, 104
runtime-gate assertions, 320 in-process Christmas assertions, clean lint, 560 browser
assertions with 0 failures, and an empty diff in the failing-suite lists. The limitation
is environmental (§13): Chromium only — **no WebKit/Safari and no physical device** —
and no production verification, which was out of scope. This is not a release-readiness
claim.
