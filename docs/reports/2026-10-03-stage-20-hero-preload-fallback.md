# Report — Stage 20: Optimize Homepage Hero Preload Fallback

| | |
|---|---|
| **Stage / task name** | Stage 20 — Optimize Homepage Hero Preload Fallback |
| **Date** | 2026-10-03 |
| **Author / agent** | Cline (implementation agent) |
| **Branch** | `master` |
| **Start SHA** | `7f8dea73462ee09fa5827954ce3e7ddcb6b9def5` |
| **Final SHA** | the single commit titled "Stage 20: bound the homepage hero preload fallback cost" (a commit cannot contain its own SHA; resolve with `git log --grep="Stage 20: bound the homepage"`) |
| **Working tree at finish** | dirty only with the pre-existing unrelated modifications listed in §3 |
| **Skills followed** | `wp-repository` (plan/build/verify/report loop), `wp-frontend-perf` scope, `wp-write-in-process-test` conventions, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §1.4–1.6/§8/§9, `docs/testing.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` |

> **The change does not fix Firefox's speculative-loader defect.** It reduces
> the cost of the defective fallback path by changing only the desktop preload's
> plain `href` from the 2057w master to the existing 1600w derivative, while
> preserving responsive `imagesrcset` selection and desktop preload behaviour.
> **No LCP improvement is claimed**; only measured results are reported.

## 1. Scope completed

One functional attribute changed, plus the test and documentation the standard
requires for it:

* `wp-content/themes/conexao-br-irlanda/header.php` — the desktop hero
  preload's plain `href` now points at `conexaobr_Hero_image-1600.webp`
  (139,882 B) instead of `conexaobr_Hero_image.webp` (225,488 B).
  `media="(min-width: 769px)"`, `imagesrcset` (both candidates, same order),
  `imagesizes="100vw"`, `fetchpriority="high"`, `as` and `type` are
  byte-identical. The comment above the hints was corrected in place (same line
  count — see §9).
* The mobile preload, the hero `<picture>`, `front-page.php`, `inc/assets.php`
  and the inert `conexao_hero_preload()` were **not** modified.
* New in-process suite `test-hero-preload-fallback.php` (33 assertions), which
  also serves as the §13 negative proof.
* `docs/frontend.md` and `docs/themes/conexao-br-irlanda.md` now record the
  trade-off, its rationale, and what was deliberately *not* done.

## 2. Scope NOT completed

* **The Firefox speculative-loader behaviour itself was not addressed**, by
  design: no JavaScript viewport detection, no server-side viewport detection,
  no `Vary`, no device-specific cache key, no `.htaccess` or cache-header
  change, no WordPress.com caching change, no image recompression, no
  threshold/baseline edits.
* **The defect did not reproduce in this environment** (§13), so the
  ~220 KiB → ~137 KiB reduction is demonstrated statically, not as an observed
  saving.
* No production action of any kind (see §8).
* The pre-existing suite failures in §12 were **not** fixed — they are
  unrelated to this stage and out of scope.

## 3. Files added / modified / deleted

**Stage 20 files: 4 added, 2 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | modified | **The only runtime change**: desktop preload `href` → 1600w (+ the 2 comment lines describing it) |
| `wp-content/themes/conexao-br-irlanda/tests/test-hero-preload-fallback.php` | added | 33-assertion gate + negative proof; auto-discovered by `run-tests.sh` |
| `docs/frontend.md` | modified | New §"Firefox speculative-loader fallback" + corrected fallback clause |
| `docs/themes/conexao-br-irlanda.md` | modified | Corrected the preload Performance bullet (it still described `conexao_hero_preload()` as the emitter) |
| `docs/evidence/2026-10-03-stage-20-hero-preload-fallback/**` | added | Machine evidence (raw measurements, logs, captures, probes) |
| `docs/reports/2026-10-03-stage-20-hero-preload-fallback.md` | added | This report |

Not one report or evidence file was added at the repository root.

**Pre-existing modifications present before this stage and left untouched**
(`git status` at start, still dirty at finish):
`docs/frontend.md` and `docs/themes/conexao-br-irlanda.md` were already modified
(§10 edits are additive to those), plus
`assets/css/{dark-mode,design-system,leisure,main}.css`,
`inc/{archive-query,customizer,events}.php`, `page-empregos.php`, and untracked
`docs/reports/2026-10-03-ui-convergence-t1-plan.md` and
`tests/test-ui-convergence-t1.php`. None was reset, staged or edited by this
stage; §11 proves the failing-suite list is identical with the Stage 20 change
removed.

**Naming note.** `docs/evidence/2026-10-02-stage-20/` and
`docs/reports/2026-10-02-stage-20-*` already exist for a *different* Stage 20
(Laois ICS occurrence dates). This stage's evidence/report use the
`stage-20-hero-preload-fallback` suffix so the two are never confused.

## 4. Runtime impact

WordPress serves the same page with one attribute changed: the desktop hero
preload's fallback URL. Consequences:

* **Normal path, every browser:** unchanged. `imagesrcset` remains
  authoritative, so the candidate actually downloaded is identical.
* **Defective path (Firefox speculative loader on a non-matching media):** the
  wasted download is 139,882 B instead of 225,488 B.
* Everything else — markup, CSS, JavaScript, routes, content, Polylang — is
  untouched. Verified by the §11 before/after full-suite comparison and by
  byte-comparing the rendered `<picture>` block in both captures.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | Confirmed — every command ran against `localhost:8080`; `verify-release.sh` step 7 is read-only HTTP |
| No production content, Polylang or database change | Confirmed — no content operation of any kind; PT content untouched |
| No Flutter/mobile repository accessed | Confirmed — no path outside this repository was read or written |
| No `.env` or secret change | Confirmed — `git status` shows no `.env`; the secret-scan gate is classified in §12 |
| No server-side viewport detection / `Vary` / cache-key change introduced | Confirmed — the `header.php` diff is 3 lines; no other runtime file touched |
| No performance threshold, baseline or allowlist altered | Confirmed — see §9 (the PHPCS baseline was *not* regenerated) |

## 5. Content / data impact

**None.** No post, term, option, meta or file was created, updated or deleted.
Confirmed by `git status` and by the suite run.

## 6. Polylang impact

**0.** No translation record, language field, URL or slug was touched. The
change is inside the homepage `<head>` and is identical on PT and EN.

## 7. Route / HTTP impact

None. No route, redirect, canonical, hreflang or sitemap change. The HTTP
acceptance result is in §9 and is identical to the baseline.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | **0 production writes** |
| Deploy / upload | **no** | Theme never uploaded |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | — |
| Cache purge / production settings | **no** | — |

Nothing was deployed to WordPress.com. A deployment, if wanted, is a separate
explicit action.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/lint.sh` | **0** | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` — 446 files syntax-checked; PHPCS legacy baseline 2841 E/2189 W (below the recorded 3290/2672); PHPStan level 5: 0 errors |
| `./scripts/run-tests.sh` | 1 | **83 in-process suites, 54 passed, 29 failed; assertions 3208 passed, 4 failed; 18 script-contract (17 passed / 1 failed); 4 acceptance (3 passed / 1 failed)**. All failures pre-existing — §11/§12 |
| `./scripts/run-tests.sh` with Stage 20 removed | 1 | 82 in-process suites, 53 passed, **29 failed**; assertions 3175 passed, 4 failed — the failing-suite **lists are identical** |
| `python3 scripts/verify-permanent-gates.py` | **0** | `Gates: 7 total, 7 passed, 0 failed, 0 blocked; Assertions: 84 passed, 0 failed; Violations: 0 (0 pre-existing, 0 new); AGGREGATE: PASS` |
| `php scripts/generate-registry-docs.php --check` | **0** | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `python3 scripts/release-manifest.py --verify` | **0** | `summary: release=v2026.10.03 artifacts=10 built=9` |
| `./scripts/verify-release.sh` | **0** | `RELEASE VERIFICATION: OK` — allowlist/hashes, byte-for-byte determinism, exclusion proof, read-only HTTP deployment (`rows=18 passed=44 failed=0`), "no production step was performed" |
| `docker compose exec wordpress php …/test-hero-preload-fallback.php` | **0** | `hero-preload-fallback: 33 passed, 0 failed` |

### Numeric test results

In-process PHP: **83 suites total, 54 passed, 29 failed**; **assertions 3208
passed, 4 failed**. The new suite contributes exactly the +33 assertions over the
baseline (3175 → 3208) and **0 new failures**.

### HTTP acceptance

**4 suites** (routing, guides-EN, release, event-occurrence): **3 passed, 1
failed** — `verify-event-occurrence-http.py`, identical to the baseline run.

### Static analysis

PHPCS and PHPStan both ran; both passed. A **first `lint.sh` run failed** with
`+7 Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed` and
`+6 Universal.WhiteSpace.PrecisionAlignment.Found`: my first version of the
header comment added 7 space-indented lines. Rather than regenerate the PHPCS
baseline (§11 forbids it), the comment was rewritten to the **same number of
space-indented lines as `HEAD`**. `header.php` now scores **13 errors / 8
warnings — identical to its `HEAD` revision**, verified by running PHPCS against
both revisions side by side, and `lint.sh` is green. No baseline, threshold or
allowlist was touched.

### Script-contract results

**18 suites, 17 passed, 1 failed** (`verify-stage14-secret-scan.py` — §12).

### Release / build results

`verify-release.sh` passed end-to-end including determinism and read-only
deployment verification. No release artifact was deployed.

## 10. Failure proofs / negative tests

`negative-proof.txt` / `negative-proof.sh`. The desktop preload markup was
mutated five ways, the suite re-run each time, and the file restored
(`RESTORED_OK`, and the final `git diff` is exactly the intended change):

| Mutation | Exit | Failing assertions | First failure |
|---|---|---|---|
| Desktop preload **deleted** | 1 | 10 | `the desktop hero preload is STILL emitted by header.php` |
| `media` attribute **removed** | 1 | 10 | `the desktop hero preload is STILL emitted by header.php` |
| `imagesrcset` **removed** | 1 | 2 | `the desktop hint still carries imagesrcset` |
| `imagesizes` **removed** | 1 | 1 | `the desktop hint keeps imagesizes="100vw"` |
| Fallback **reverted to the 2057w master** | 1 | 2 | `the desktop fallback href points at the existing 1600w derivative` |

This is exactly the §13 objective: a future "performance optimization" cannot
trade mobile savings for a desktop regression without a red suite.

A second proof surfaced accidentally and is worth recording: an **early draft of
the suite's own HTML parser** mis-parsed the `?>` inside the PHP-built attribute
values and reported 14 failures against perfectly correct markup. A gate that
cannot read the template is worthless, and this one demonstrated it by failing.
The parser now resolves PHP blocks first, and the suite passes against real
markup.

## 11. Regression comparison

Same environment, full suite, Stage 20 applied vs Stage 20 removed:

| | Before (Stage 20 removed) | After (Stage 20 applied) |
|---|---|---|
| In-process suites | 82 total, 53 passed, 29 failed | 83 total, 54 passed, 29 failed |
| Assertions | 3175 passed, 4 failed | 3208 passed, 4 failed |
| Failing script-contract | 1 | 1 |
| Failing acceptance | 1 | 1 |
| Failing-suite list | 29 entries | **the same 29 entries** |

**Diff of the failing lists: empty.** New failures introduced: **NONE**.
Failures fixed by the change: **NONE** (nothing was broken to begin with).

Browser behaviour — interleaved A/B, n=3 per state, cold private Firefox 156
(full numbers in `measurements.md`):

| Metric | Before | After |
|---|---|---|
| Mobile hero request @390px | mobile WebP, 100,080 B, initiator `link` | mobile WebP, 100,080 B, initiator `link` |
| **Mobile accidental desktop request** | **0 B — not reproduced** | **0 B — not reproduced** |
| Mobile LCP (median of 3) | 597 ms | 569 ms |
| Desktop hero request @1440px | 1600w, 139,882 B, initiator `link` | 1600w, 139,882 B, initiator `link` |
| Desktop LCP (median of 3) | 564 ms | 627 ms |
| Tablet @769px | 1600w, 139,882 B, initiator `link` | 1600w, 139,882 B, initiator `link` |
| Whole-page transfer (mobile / desktop) | 436,639 / 476,184 B | **identical** |

The LCP medians move by tens of milliseconds **in both directions** and sit
inside the spread of the individual samples — that is run-to-run noise, not an
effect. **Hero bytes and whole-page transfer are byte-identical before and
after**, which is the honest headline: in this environment the change saves
nothing, because the defect it bounds never fired here.

Independent engine (Chromium, `currentSrc` = the browser's own resolution
result) is identical before and after: 375 → mobile WebP, 768 → mobile WebP,
**769 → 1600w**, 1024 → 1600w, 1440 → 1600w; exactly **one** hero request per
viewport; `naturalWidth` equals the CSS width, confirming `sizes="100vw"`.

## 12. Known pre-existing failures

All of these fail identically with the Stage 20 change removed — proven by
re-running the whole suite and diffing the failing lists (§11, logs
`run-tests-AFTER.log` vs `run-tests-BASELINE-no-stage20.log`):

1. **26 in-process suites** under `plugin/conexao-en-translation/`,
   `plugin/conexao-translation-automation/` and
   `plugin/conexao-translation-rollout/`, plus 5 theme suites
   (`test-guide-en-translation`, `test-jobs-en-language`,
   `test-en-jobs-shared-slug`, `test-course-provider-card-excerpt-language`,
   `test-leisure-card-excerpt-language`). These are the fallout of retiring the
   EN translation automation layer (Stage 17/19): they fail with
   `insufficient data: polylang` or assert an EN stage layer that is
   intentionally gone.
2. `theme/…/test-stage41-rest-language.php` — 220 passed, **2 failed** (the only
   4 failing assertions in the entire run).
3. `theme/…/test-stage45-pages.php` — `insufficient data: polylang`.
4. `tests/scripts/verify-stage14-secret-scan.py` — *"the PHP runtime scanner
   could not be executed (no local WordPress); conformance is UNPROVEN, not
   assumed"*. An **environment** condition: the gate wants a local WordPress
   reachable from the host PHP, which this checkout does not provide. It fails
   closed by design, and was **not** reclassified or allowlisted here.
5. `tests/acceptance/verify-event-occurrence-http.py` — exit 1.

None of these was touched, suppressed, allowlisted or reclassified by this stage.

## 13. Limitations

* **The Firefox defect did not reproduce.** Across 6 cold/private Firefox
  156.0.1 runs at 390px (3 sequential per state, plus 3 interleaved per state),
  only the mobile WebP was ever requested. The headline saving
  (220.2 KiB → 136.6 KiB) is therefore a **static bound**, proven by the asset
  sizes and the markup — not an observed measurement. Whether Firefox still
  performs the speculative fetch on real user devices is **not established
  here**.
* Measurements come from a **local Docker site on loopback with no network
  latency and no throttling**. Absolute LCP values are not representative of
  production; only before/after *deltas* and byte counts are cited, and the
  deltas are zero.
* Chromium was used for the responsive-selection cross-check; **no real Firefox
  GUI/profiler run** (no Firefox Profiler trace, no HAR from a desktop install).
* n=3 per state per viewport. Enough to show byte-identical transfer and
  noise-level LCP; **not** enough to characterise an LCP distribution.
* The suite parses template **source** (PHP blocks resolved textually) rather
  than rendered HTML. The rendered HTML is verified separately by the Chromium
  probe and the HTTP captures.
* No production verification was possible or attempted.

## 14. Evidence paths

`docs/evidence/2026-10-03-stage-20-hero-preload-fallback/`:

| File | Proves |
|---|---|
| `preload-markup-diff.md` | The rendered BEFORE/AFTER `<link>` markup; exactly one attribute differs |
| `http-home-BEFORE.html`, `http-home-AFTER.html` | Full local captures of the homepage in both states |
| `measurements.md` | All LCP/byte/initiator numbers, the interleaved A/B, the hero asset facts, and the explicit statement that the defect did not reproduce |
| `chromium-BEFORE.json`, `chromium-AFTER.json` | `currentSrc` selection at 375/768/769/1024/1440, both states |
| `measurements/ff-*.json` | Raw Firefox resource timings + LCP per viewport per state |
| `measurements/pair*.json` | The 12 interleaved A/B loads |
| `measurements/ffprobe.py`, `ab.sh`, `chromeprobe.py` | The probe harnesses (stdlib WebDriver / Playwright), re-runnable |
| `suite-hero-preload-fallback-AFTER.txt` | 33 passed, 0 failed |
| `negative-proof.txt`, `negative-proof.sh` | The five mutations, their exit codes and failing assertions |
| `lint-AFTER.txt` | `lint: OK` |
| `run-tests-AFTER.log`, `run-tests-BASELINE-no-stage20.log` | The full before/after suite comparison |
| `permanent-gates.log` | 7/7 gates, 84 assertions, 0 violations, AGGREGATE: PASS |
| `verify-release.log` | `RELEASE VERIFICATION: OK` |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/frontend.md` | New §"Firefox speculative-loader fallback (deliberate trade-off)": the preload stays, `media` is correct, Firefox may speculatively fetch the non-matching preload, the fallback deliberately targets the smaller 1600w asset, server-side/client-side viewport detection was avoided because of `Vary`/cache-key risk, `<picture>` stays the source of responsive selection, and the gate is named. The stale clause "the plain `href` fallback … fetching the master" was corrected. `_Last verified:` line added |
| `docs/themes/conexao-br-irlanda.md` | Performance bullet corrected: it claimed `conexao_hero_preload()` emits the hints, but they are hardcoded in `header.php` (the function is unhooked, kept for reference) — this stage did not cause that drift, but the bullet had to be corrected for it to describe the fallback truthfully. `_Last verified:` line added |

No other frontend documentation was rewritten. `documentation_drift` passes
(14 assertions) and the registry check reports zero writes.

## 16. Rollback / recovery

```bash
git revert <Stage-20-commit>
```

That is the whole procedure. The change is three lines in one template file
(one functional attribute, two comment lines). Rollback does **not** require
content rollback, database restoration, Polylang work, cache purging, asset
re-encoding or any cache-policy change — no asset, record, route or setting was
touched. Had the theme been deployed, redeploying the previous theme version
would restore the previous `href`, with no migration involved.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The implementation, the deterministic gate, the negative proof, lint, the
permanent gates, the registry check and release verification all ran and passed,
and the before/after full-suite comparison is clean (identical failing lists).
The limitation in §13 is that the Firefox speculative-loader defect did not
reproduce in this environment, so the saving is a proven static bound rather
than a measured one — and no LCP improvement is claimed.

## 18. Commit and scope audit

Committed as a single commit titled "Stage 20: bound the homepage hero preload
fallback cost" on `master`, containing exactly four non-evidence files —
`header.php`, the new test, `docs/frontend.md` and
`docs/themes/conexao-br-irlanda.md` — plus this report and the evidence
directory. The two documentation files carried **unrelated uncommitted work from
another workstream**, so only this stage's hunks were staged (verified with
`git diff --cached`); that other work remains untouched in the working tree,
along with `docs/README.md` and the regenerated `gate.json`.

`git show --name-only HEAD` outside `docs/evidence/` and `docs/reports/` lists
exactly:

```
docs/frontend.md
docs/themes/conexao-br-irlanda.md
wp-content/themes/conexao-br-irlanda/header.php
wp-content/themes/conexao-br-irlanda/tests/test-hero-preload-fallback.php
```

That is the authoritative scope audit: no route, no content, no plugin, no
image asset, no `.htaccess`, no cache configuration, and nothing outside this
repository. Rollback is `git revert $(git log --grep="Stage 20: bound the homepage" --format=%H -1)`.

_Last verified: 2026-10-03 by Stage 20 — hero preload fallback (Firefox speculative-loader cost bound)_





