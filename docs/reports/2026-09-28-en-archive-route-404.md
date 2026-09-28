# Report — EN archive route 404 investigation: `/en/apoiadores/`, `/en/eventos/`, `/en/guias/`

| | |
|---|---|
| **Stage / task name** | `en_archive_route_404` — regression investigation of the three EN CPT-archive routes that return 404 |
| **Date** | 2026-09-28 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `e7303ba47d0080e3778ba7e70dd5b9c29f44e170` (recovery closeout; working tree clean) |
| **Final SHA** | one documentation-only commit on top of `e7303ba` (see §3) |
| **Working tree at finish** | clean after the commit; only the five intended documentation paths changed |
| **Plan** | [`2026-09-28-en-archive-route-404-plan.md`](2026-09-28-en-archive-route-404-plan.md) |

## 1. Scope completed

1. **Reproduced the failure classification from repository + recorded evidence.** This
   sandbox has **no runtime at all** (no Docker, no PHP, no MySQL, no Chromium — the
   port-8080 listener is the sandbox router stub, not WordPress; evidence `00`), so the
   three 404s could not be re-executed live. Every runtime claim below is therefore
   either **static proof** (git + source) or **recorded evidence** (a previous verified
   run, cited) — labelled as such.
2. **Classified each route's mechanism** (§7.1) from repository evidence — all three are
   Polylang-prefixed CPT archives, but with different EN content policies (B2 / B2 /
   real EN archive).
3. **Located the last known-good state and the regression** (§7.2–7.3): the theme at HEAD
   is **byte-identical** to `b47d098` (only the event-importer POT repair differs in
   `wp-content/`); the regression entered at `597b04e`, which deleted `inc/i18n/`
   (including the `pll_get_post_types` declaration) **and** poisoned the local DB
   `rewrite_rules`, which a code restore cannot repair.
4. **Proved the root cause** against the task's five candidate scenarios (§7.5):
   scenario 2 — **the registration code is correct, the local rewrite state is stale**.
5. **Determined the minimal fix** (§7.6): the documented **local rewrite flush** — a
   local DB/runtime operation, not a code fix — plus a documentation change recording
   the mechanism and the flush requirement (§3, §15).
6. **Verified everything this environment can verify** (§9, §11): script-contract gates
   before/after (failing-suite diff empty), governance gates, registry-scope audit,
   cleanliness checks.
7. **Determined the production answer** (§8, §7.7): production receives equivalent
   rewrite registration through the documented release sequence; no production action
   was taken or is needed for this regression.

## 2. Scope NOT completed

1. **The routes were not restored live.** The required remediation is a local DB flush
   inside the local Docker stack; this environment has no Docker/PHP/MySQL, so it could
   not be executed or verified here. The complete runbook is §7.6; the final status is
   **BLOCKED** on exactly this (§17).
2. **No live HTTP, browser, in-process or permanent-gate run.** All of those verifiers
   require the local WordPress runtime; each is listed as blocked in §13 with the exact
   command to run in a complete environment.
3. **No code change was made — deliberately.** Nothing was missing, disconnected, or
   broken in the registration chain (proved in evidence `01`–`03`), so every candidate
   code fix would have been either a no-op or a new routing architecture, which the
   task forbids. This is a finding, not an omission: see §4.
4. **No EN content was created** (e.g. no EN guides for the local empty EN guide
   archive) and **no acceptance expectation was altered** — the three routes were
   already permanent 200 rows in `routing.json` (evidence `04`).
5. **The release smoke matrix was not grown** (fixed 18-row gate; the
   `/en/apoiadores/` coverage gap is recorded as a recommendation in §12.3).

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-28-en-archive-route-404-plan.md` | added | the plan (route work requires it; standard §13.2) |
| `docs/reports/2026-09-28-en-archive-route-404.md` | added | this report |
| `docs/evidence/2026-09-28-en-archive-route-404/` | added | 8 curated evidence files (§14) |
| `docs/routing.md` | modified | new subsection §"EN archive route resolution (rewrite rules and the local flush)" + `_Last verified_` line; no existing route row changed |
| `docs/reports/README.md` | modified | registers this report (+ plan) under a new investigation section |

**0 added under `wp-content/`, 0 modified under `wp-content/`, 0 deleted** — the
runtime PHP is untouched (byte-identical to the verified baseline; evidence `01`).

## 4. Runtime impact

**None from the repository change** (documentation only). WordPress behaves
differently only after the **runtime remediation** (§7.6): the three EN CPT-archive
routes resolve again (200), and every other route keeps its documented state — no
route, redirect, canonical, hreflang, sitemap, cache-key or B1/B2 behaviour changes,
because the flush regenerates the rules from the *current, verified* registration
state, which is exactly what produced the green closeout matrix. Verified that no
code could have been responsible: `git diff b47d098 HEAD -- wp-content/themes/ wp-content/plugins/`
= the event-importer POT only (evidence `01`).

## 5. Content / data impact

**None.** No record, term, option, menu or upload is created, updated or deleted by
this task's repository change — confirmed by `git status --short` (docs only). The
remediation flush rewrites only the derived `rewrite_rules` option (a cache of the
registration state, not content); the recovery measured it changing **no** content
(`docs/reports/2026-09-27-baseline-recovery-implementation.md` §6).

## 6. Polylang impact

- **PT immutability: held.** Nothing in this task writes any runtime state, and the
  repository change touches no `wp-content/` file. PT routes and records are
  byte-identical by construction.
- Translated post types/taxonomies: unchanged — `guide`, `event`, `leisure`,
  `sponsor`, `job`, `course_provider` via `inc/i18n/guard.php` (§6.1 of the standard,
  verified in evidence `02`).
- B1/B2 policy: unchanged — `/en/empregos/` stays B1 (302 → PT); sponsor/event
  archives stay B2-widened; `/en/guias/` stays the real EN archive. No Polylang
  setting, language, or translation relationship is touched by a rewrite flush.
- Completeness gate: not applicable to a routing fix; the pre-existing
  translation-completeness limitation (EN event `11553` without a PT master) is
  documented pre-existing at the closeout and is untouched by this task.

## 7. Route / HTTP impact — root cause and route model

### 7.1 Route model (per URL, from repository evidence)

| Route | Intended route type | PT route | EN mechanism | EN content required? | Current failure |
|---|---|---|---|---|---|
| `/en/apoiadores/` | CPT archive (`sponsor`) — B2 | `/apoiadores/` (200, recorded) | Polylang-prefixed rewrite rule for the translated post type `sponsor` → `post_type=sponsor` in an EN request; B2 archive widening (`conexao_b2_archive_widen_query`) keeps PT sponsor records visible under the EN shell | **No** — B2 archive: PT records render under the EN shell (recorded 200 at Stage N with the same dataset) | 404 |
| `/en/eventos/` | CPT archive (`event`) — B2 | `/eventos/` (200, recorded) | Same rewrite-rule mechanism for `event`; the events archive curates a language-widened ID list inside `conexao_content_archive_query()`; county/town filters use the shared taxonomies | **No** — B2 archive (recorded 200 at Stage N and at the recovery closeout) | 404 |
| `/en/guias/` | CPT archive (`guide`) — **real EN archive since Stage 9** | `/guias/` (200, recorded) | Same rewrite-rule mechanism for `guide`; the archive then lists linked EN guide records with EN `conexao_category` terms | EN guide **records** are the Stage 9 architecture; locally the dataset has **0 EN guides** (documented closeout limitation), so the archive serves 200 with an empty listing and `/en/guias/page/2/` legitimately 404s — that is content state, not routing | 404 |

All three share **one resolution mechanism** — a DB-stored, flush-generated,
Polylang-prefixed CPT-archive rewrite rule — which is why they fail together and why
they were green together (Stage N, the recovery flush, the closeout). The EN content
policy differs (B2 / B2 / real archive), which is why the *rendered* behaviour
differs once the route resolves.

### 7.2 Why each route should work

- The contract: `tests/acceptance/matrices/routing.json` rows `en-200-en-apoiadores`,
  `en-200-en-eventos`, `en-200-en-guias` (expect 200, `<html lang="en-US">`, no
  `pt-BR` shell) and `guides-en.json` row `en-guides-200` (self-canonical, full
  hreflang set, no B2 notice); `docs/routing.md` documents all three; the release
  smoke matrix asserts `/en/guias/` + `/en/eventos/` = 200 after every release
  (evidence `04`).
- The recorded green history: **all three routes 200 at Stage N (2026-09-26)**
  (`docs/evidence/2026-09-26-stage-n/http-routing-matrix.txt`); `/en/guias/` +
  `/en/eventos/` **200 at the recovery closeout** with the same DB and the same code
  as HEAD (`docs/evidence/2026-09-27-recovery-closeout/06-http-acceptance-matrix.txt`).
  `/en/apoiadores/` has no closeout probe (the routing HTTP suite timed out there on
  the known cold-cache event-archive latency — closeout evidence `04`), so its last
  recorded 200 is Stage N.

### 7.3 Where the regression entered

Two halves, both pinned to commits (evidence `03`, `07`):

1. **Code half — `597b04e`** ("Refactor code structure…") deleted the theme's entire
   `inc/i18n/` tree, including `inc/i18n/guard.php`, the **only** place that declares
   the six CPTs Polylang-translated (`pll_get_post_types`; standard §6.1). Its
   3,937-line monolithic `functions.php` has **0** occurrences of `pll_get_post_types`,
   and `inc/` was cut from 43 modules to 8. `cd800be` restored the tree byte-identically
   to `b47d098`.
2. **Data half — a rewrite flush during the destructive window.** WordPress stores
   rewrite rules in the DB `rewrite_rules` option and regenerates them **only** on
   flush. The recovery measured the local DB's stored set: **84 `en/`-prefixed rules,
   of which 0 for a CPT archive** — i.e. it had been generated while the `597b04e`
   theme (no `pll_get_post_types`) was active, so Polylang emitted no `en/`-prefixed
   CPT-archive rule at all (`guias/?$`, `eventos/?$`, `lazer/?$` existed for PT only).
   A source restore cannot touch that option — which is why the routes stayed 404
   after the byte-perfect restore until the recovery's local flush.

**Why the routes are 404 again now (the conclusion of the investigation):** the code at
HEAD is byte-identical to the state that produced the green closeout matrix
(evidence `01`), so nothing in the repository can be the cause. The observable state
matches the recorded stale-rules signature exactly (three EN CPT-archive routes 404).
Therefore the **local `rewrite_rules` in the task's runtime is stale again** — the DB
was re-provisioned/restored from a state whose rules predate the current registration
(the recovery's own documented limitation: "a fresh deployment must flush permalinks
as part of its normal install step"). A corroborating signal that this environment
was re-provisioned after the closeout: the rollback tag `recovery-snapshot-before-restore`
(existent at the closeout, pointing at `bd81ba5`) is **absent from origin** now
(evidence `00`).

### 7.4 Request path — where it becomes invalid

```text
GET /en/guias/ (same for /en/eventos/, /en/apoiadores/)
→ Apache/.htaccess → WordPress
→ WP_Rewrite matches against the DB-stored rewrite_rules
   ✗ no `en/`-prefixed CPT-archive rule present (stale set)
→ falls through to the page permastruct (pagename lookup, e.g. "en/guias")
   ✗ no such page (Stage 4.3 recorded: "404 — no matching post_name")
→ 404.php rendered
```

The request becomes invalid at the **rewrite-rule match step** — before any Polylang
language context, CPT archive resolution, `pre_get_posts` B2 widening, or template
resolution can run. That is why PT routes keep serving (their PT-root rules exist even
in the stale set) and why the B2/real-archive content layer is irrelevant to the 404.

### 7.5 Classification against the task's five candidate scenarios

| # | Candidate scenario | Verdict | Proof |
|---|---|---|---|
| 1 | the registration code is wrong | **ruled out** | CPT registration (`guide`/`event`/`sponsor` → `guias`/`eventos`/`apoiadores`, `has_archive`) and the `pll_get_post_types` declaration are byte-identical to `b47d098`, the state that produced recorded 200s (evidence `01`, `02`) |
| 2 | registration code correct, local rewrite state stale | **PROVEN the cause** | recorded identical failure+fix at the recovery (0 → 92 `en/` CPT-archive rules on flush; 404 → 200 with no code/data change); code identity rules out every code cause; the observable signature matches |
| 3 | Polylang not registering the language route | **ruled out as a code cause** (it is the *mechanism* of scenario 2) | Polylang registers language routes at flush time; with the restored declaration active, the recorded flush produced the full `en/` set; no Polylang setting changed since |
| 4 | the route depends on a translated page/CPT/archive that is missing | **ruled out** | all three are CPT archives, not pages; `/en/guias/` recorded 200 with **0** EN guide records locally (empty real archive); sponsor/event archives are B2 and never required EN records (Stage N + closeout matrices) |
| 5 | another query/template problem | **ruled out** | the request dies at the rewrite match before any query/template code runs (§7.4); with the rules present, the same code rendered the full green matrix (recorded) |

### 7.6 The minimal fix (and what was deliberately NOT done)

**The repository needs no code change** — checked against the task's preferred order:
restore missing routing code (nothing missing — byte-identical), reconnect a
disconnected helper (none — the loader chain is intact, evidence `02`), fix a broken
registration/query boundary (none — the boundary is the verified `b47d098` one), new
minimal fix (would be a new routing architecture — forbidden). A runtime
auto-flush/rewrite-guard was explicitly rejected: it is a new mechanism and a
documented performance anti-pattern, not the historical architecture.

**The runtime remediation — a local DB operation, recorded as such, never a code fix:**

```bash
# In an environment with the local Docker stack (see docs/development.md):
docker compose up -d
docker compose exec -T wordpress php /var/www/html/scripts/flush-blog-rewrite-rules.php
#   (a pure flush_rewrite_rules(); catalogued in scripts/README.md as local-write)
# Alternative without any script: local wp-admin → Settings → Permalinks → Save.
# Do NOT use scripts/flush-navigation-rules.php for this: it also deletes pages
# and rebuilds menus (content mutation, out of scope for a route fix).

# Then verify (the three routes + PT counterparts + neighbours):
./scripts/run-tests.sh --acceptance        # routing.json en-200-en-{guias,eventos,apoiadores} + PT rows
python3 scripts/verify-permanent-gates.py  # expect the closeout state: 6/7 pass
./scripts/run-tests.sh                     # full runner, compare vs the closeout baseline
```

The recovery performed this exact operation (`flush_rewrite_rules(true)` in the local
Docker DB) and recorded the routes going 404 → 200 with **no code or data change**
(evidence `05`). Whether a flush is *required* in the task runtime: **yes** — until the
rules regenerate, the three routes cannot resolve; that is the definition of the
stale-set failure mode.

### 7.7 Rewrite rules — before/after state

| State | `en/`-prefixed rules | of which CPT-archive rules | Recorded source |
|---|---:|---:|---|
| local DB during the destructive window (stale set) | 84 | **0** | recovery implementation §7 / evidence `06-phase11` |
| after the recovery's local flush (correct set) | — | **92** | same |
| HEAD code today | byte-identical to the state that generated the correct set | — | evidence `01` |
| task runtime today | not inspectable from this sandbox (no runtime) — must be stale (§7.3) | — | §13 |

**Does the final fix require a rewrite flush?** Yes — that *is* the fix; no code change
accompanies it. Production would receive equivalent registration **without any extra
step** (§8), so the flush is strictly a local-DB concern.

### 7.8 HTTP results — route by route

**Recorded green references** (previous verified runs, cited in evidence `05`):

| Route | Stage N (2026-09-26) | Recovery flush (2026-09-27) | Closeout (2026-09-27, HEAD code) |
|---|---|---|---|
| `/en/apoiadores/` | **200** (recorded) | not in that probe list | not probed (routing suite timed out) |
| `/en/eventos/` | **200** (recorded) | **200** (recorded) | **200** (recorded) |
| `/en/guias/` | **200** (recorded) | **200** (recorded) | **200** (recorded) |

**This task (live verification): BLOCKED** — no runtime exists in this environment
(§13). Expected after the §7.6 flush, from the routing contract: all three routes
200, `<html lang="en-US">`, self-canonical (`/en/<route>/`), hreflang `en`+`pt-BR`(+`x-default`
on guides), no PT redirect, no B2 notice on `/en/guias/`, PT counterparts unchanged
(`/apoiadores/`, `/eventos/`, `/guias/` all 200 `pt-BR`, recorded at the closeout and
unaffected by a flush because their PT-root rules exist even in the stale set).

**Regression scope (nearby routes)** — expected after the flush, per the recorded
closeout matrix: `/` 200 `pt-BR`; `/en/` 200 `en-US`; `/en/cursos/`, `/en/lazer/`,
`/en/blog/` 200 `en-US` (in the stale destructive-window set these were 301→PT, the
same flush heals them); `/en/empregos/` **stays 302 → PT (B1, by design)** — a 200
there would require creating EN page translations (content, out of scope). PT
counterparts (`/cursos/`, `/lazer/`, `/blog/`, `/empregos/`) 200 `pt-BR` unchanged.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | nothing was written, deployed, activated or mutated |
| Deploy / upload | **no** | no release, no ZIP, no upload |
| Plugin activation | **no** | no activation anywhere (no runtime) |
| Content or DB mutation | **no** | not even locally — no runtime exists here |

**Would production receive equivalent rewrite registration?** Yes, from the documented
release sequence alone: [`docs/releases.md`](../releases.md) §4 — upload the **theme
first**, then activate the platform plugins in `plugins.json` order; the
`conexao-data-model` activation hook calls `flush_rewrite_rules()` with the theme's
`pll_get_post_types` declaration already loaded (theme functions load on every request,
including plugin activation), so Polylang regenerates the **full** rule set —
including every `en/`-prefixed CPT-archive rule — on activation. Every release is then
verified over HTTP by `scripts/verify-deploy.py`, whose fixed smoke matrix asserts
`/en/guias/` and `/en/eventos/` return 200 — a production rewrite gap on those two
routes fails the release gate. **Caveat, recorded honestly:** `/en/apoiadores/` is not
a release-smoke row (§12.3), and **no production release has ever been performed or
verified through this repository's tooling** (release log in `docs/releases.md` is
empty) — so the production route state is asserted from the documented sequence and
source, not from a recorded production verification.

## 9. Verification commands and results (what actually ran here)

| Command | Exit | Result |
|---|---|---|
| `git status --short` / `git rev-parse HEAD` / `git log -3` | 0 | clean tree at `e7303ba` on `i18n` (evidence `00`) |
| `git tag --list 'recovery-snapshot-before-restore'` + `git ls-remote --tags origin` | 0/128 | tag absent locally and on origin (evidence `00`) |
| `git diff --stat b47d098 HEAD -- wp-content/themes/ wp-content/plugins/` | 0 | only `conexao-event-importer.pot` (evidence `01`) |
| `git diff --stat cd800be HEAD -- wp-content/` | 0 | empty — zero runtime changes since the recovery commit |
| `git show 597b04e:…/functions.php \| grep -c pll_get_post_types` | 1 | **0** occurrences (evidence `03`) |
| `git show 597b04e:…/inc/i18n/guard.php` | 128 | "exists on disk, but not in '597b04e'" (evidence `03`) |
| `./scripts/run-tests.sh --scripts` (before change) | 1 | 6 suites: **3 passed, 3 failed** — failures are php-absent environment gaps (§12.1); identical after the change (§11) |
| `python3 tests/scripts/verify-agent-governance.py` | 0 | pass (inside `--scripts`, re-run after the change) |
| `python3 tests/scripts/verify-i18n-freshness.py` | 0 | pass (inside `--scripts`) |
| `python3 tests/scripts/verify-cache-key-scoping.py` | 1 | **blocked by environment**: `FileNotFoundError: 'php'` (§12.1) |
| `python3 tests/scripts/verify-documentation-drift.py` | 1 | **blocked by environment**: its subprocess `php scripts/generate-registry-docs.php --check` needs `php` (§12.1); no GENERATED region or `plugins.json` was touched |
| `python3 tests/scripts/verify-release-integrity.py` | 1 | **blocked by environment**: the build step needs `php`; `dist/` is not part of this task |
| `php scripts/generate-registry-docs.php --check` | — | **not runnable** (no `php`); mitigated: no registry input changed (§13) |
| `./scripts/lint.sh` | — | **not runnable** (needs `php` + composer; also absent at the closeout on its host) |
| `git diff --check` | 0 | clean |

### Numeric test results

In-process PHP: **blocked** — no `php` binary in this environment (0 suites could
run). Recorded reference at the closeout (same code as HEAD): 59 suites, 39 passed,
20 failed (all pre-existing, data/environment), 2900/102 assertions.

### HTTP acceptance

**Blocked** — no local WordPress (the acceptance harness needs the Docker stack).
Recorded reference at the closeout: release matrix 42/0 PASS, guides-en FAIL
(documented 0-EN-guides content limitation), routing suite TIMEOUT (documented
cold-cache latency) — identical before/after the recovery.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **6 suites, 3 passed, 3 failed**, before **and**
after this change (identical failing set — §11). The 3 failures are environment gaps
(`php` absent), not repository regressions; with `php` present the recorded closeout
result is 6/6.

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| **Code-absence negative (static, isolated):** the routing capability genuinely depends on the theme declaration — `597b04e`'s tree (declaration absent) is exactly the state that generated the recorded 0-of-84 stale rule set and the recorded 404s; `b47d098`/HEAD (declaration present) is the state that generated the recorded 92-rule set and 200s | proven from git history + recorded evidence (evidence `03`, `05`) — the regression itself is the negative test, so no live sabotage was needed (and none is possible here: no runtime) |
| **Rewrite-state negative (recorded, disposable):** the recovery ran the full suite in a disposable container at `597b04e` (routes 404), after the byte-perfect restore (still 404), and after the flush (200) | recorded: `docs/evidence/2026-09-27-baseline-recovery-implementation/09-run-tests-BEFORE-recovery-597b04e.txt`, `09-run-tests-AFTER-recovery.txt`, `09b-run-tests-AFTER-recovery-with-flush.txt` |
| **Gate fail-closed (recorded):** the closeout ran four negative proofs on the permanent gates (fail → restore → pass, clean tree afterwards) | recorded in `docs/evidence/2026-09-27-recovery-closeout/08-negative-proofs.txt` |
| **This change's gates:** the failing script-contract set is identical before/after — this change cannot have weakened any gate | §11, evidence `06` |

## 11. Regression comparison (same environment)

| | Before (this task, `e7303ba`) | After (docs change) |
|---|---|---|
| Failing in-process suites | blocked (no `php`) — n/a | identical (blocked) |
| Failing script-contract suites | `verify-cache-key-scoping`, `verify-documentation-drift`, `verify-release-integrity` (all `php`-absent environment gaps) | **identical set — the sorted diff is empty** |
| Failing acceptance assertions | blocked (no runtime) — n/a | identical (blocked) |

**0 new failures introduced.** Every failure that ran here pre-existed this change and
is classified in §12. The pre/post `--scripts` outputs are in evidence `06`.

## 12. Known pre-existing failures

1. **Script-contract suites failing on the missing `php` binary** (this sandbox):
   `verify-cache-key-scoping.py` (tokenizes theme PHP), `verify-documentation-drift.py`
   (subprocess `php scripts/generate-registry-docs.php --check`), and
   `verify-release-integrity.py` (build step needs `php`). All three fail with
   `php: command not found` / `FileNotFoundError: 'php'` on this host **before any
   change by this task** (evidence `06` baseline), and the recorded closeout result on
   a PHP-capable host is 6/6 — the failures are environmental, not repository,
   regressions. No gate, threshold or baseline was touched.
2. **Pre-existing documented local-data limitations** (from the closeout, unchanged by
   this task): 0 EN guide records locally, so `/en/guias/page/2/` legitimately 404s and
   the guides-en suite legitimately fails — a content gap, explicitly not papered over
   by this routing task; the `translation_completeness` permanent gate still fails on
   the EN event `11553` (no PT master) **proven pre-existing at the closeout** and 52
   further documented content-debt violations.
3. **Release-smoke `/en/apoiadores/` coverage gap** (observation, deliberately not
   changed): the fixed 18-row release matrix asserts `/en/guias/` + `/en/eventos/`
   `/en/`-pairs but no `/en/apoiadores/` pair (nor `/en/cursos/`). Recommendation for a
   separate, deliberate gate decision: add `smoke-en-pair-apoiadores` (200,
   `<html lang="en-US">`, hreflang) once a runtime can verify it, in a change that
   consciously evolves the fixed gate shape (and updates the §11 category table in
   `docs/releases.md`). This task changed no gate.

## 13. Limitations

1. **No runtime in this environment** — no Docker, no `php`, no MySQL, no Chromium
   (evidence `00`; the port-8080 listener is the sandbox router stub). Therefore the
   three 404s were **not reproduced live**, the **flush was not executed**, and the
   routes are **not verified green by this task**. The complete remediation runbook is
   §7.6; final status is BLOCKED on exactly this.
2. **Every runtime claim is static proof or recorded evidence** — labelled as such in
   §7 and evidence `05`. The stale-DB conclusion for the task runtime is an inference
   from: code byte-identity (rules out every code cause), the exact observable
   signature match, and the recorded identical failure+fix at the recovery. It cannot
   be confirmed by inspecting the DB from here.
3. **PHPCS / PHPStan / PHP syntax check not run** — the toolchain is absent on this
   host (same environment gap the closeout recorded). Mitigated: this task's change
   contains **no PHP at all** (`git diff --stat` shows docs only).
4. **Registry drift gate not run** (`php` absent). Mitigated: no `plugins.json`, no
   GENERATED region, no `compose.yaml` mount was touched; the risk of registry drift
   from a docs-only change is nil, and the check must be run in a complete environment
   before the next release (it is part of `run-tests.sh`).
5. **Browser verification not performed** (no Chromium, no site). Recorded reference:
   the closeout's 22-navigation desktop+mobile audit on the recovered baseline.
6. **Production state not verified** — no production release has ever been run through
   this tooling; the production registration determination (§8) is from the documented
   release sequence and source, not a recorded production run.

## 14. Evidence paths

`docs/evidence/2026-09-28-en-archive-route-404/`

| File | Proves |
|---|---|
| `00-frozen-starting-state.txt` | clean start at `e7303ba` on `i18n`; the rollback tag absent locally and on origin; this environment has no Docker/PHP/MySQL (the 8080 listener is the sandbox router) — the live reproduction boundary |
| `01-code-identity-proof.txt` | HEAD runtime PHP byte-identical to `b47d098` (only the event-importer POT repair differs) — no code cause is possible |
| `02-registration-chain-proof.txt` | the registration chain: CPT slugs + `has_archive` (data-model), the `pll_get_post_types` declaration (theme `inc/i18n/guard.php`), the loader chain, no theme `add_rewrite_rule`, the flush points in `conexao-data-model::activate()` |
| `03-destructive-regression-proof.txt` | `597b04e` deleted `inc/i18n/` (guard.php gone, 0 `pll_get_post_types` in its monolith, inc/ 43 → 8) and `cd800be` restored it |
| `04-route-contract-rows.txt` | the three routes are already permanent 200 acceptance rows; the release smoke matrix EN-pair rows and the `/en/apoiadores/` gap |
| `05-recorded-history-evidence.txt` | the recorded statuses: Stage N all-three 200; the 597b04e stale signature; the recovery 0 → 92 flush proof; the closeout 200s; the timeline |
| `06-script-contract-gates.txt` | `--scripts` before/after: 3 passed / 3 failed both, identical failing set (php-absent environment gaps) — 0 new failures from this change |
| `07-history-searches.txt` | `git log --grep` + bounded `git log -S` for the three slugs and `pll_get_post_types`: the capability lifecycle `8ac39b1` → `e674add` → **`597b04e` (removed)** → **`cd800be` (restored)** |

## 15. Documentation updated

| Document | Change |
|---|---|
| [`docs/routing.md`](../routing.md) | new subsection §"EN archive route resolution (rewrite rules and the local flush)" under §English — the resolution mechanism, the stale-set failure mode, the documented local flush, and the production registration path; no route row changed; `_Last verified_` line added |
| [`docs/reports/README.md`](README.md) | registers this report and its plan under a new "EN archive route 404 investigation (2026-09-28)" section |
| this report + plan + evidence | §3 |

Every touched living document ends with an updated `_Last verified_` line (this
report and plan included).

## 16. Rollback / recovery

- **Repository change**: `git revert` of the single documentation commit (report +
  plan + evidence are additive; the routing subsection and index rows are
  self-contained). No code, registry input, GENERATED region or gate is touched, so
  the revert carries no runtime effect.
- **Runtime remediation** (once executed per §7.6): a rewrite flush is idempotent and
  harmless to re-run; it cannot regress PT (PT-root rules regenerate identically) and
  regenerates every `en/` rule from the current registration.
- **What rollback does NOT cover**: PT content (nothing was touched — PT immutability
  held by construction), the pre-existing translation-completeness limitation
  (event `11553`), and the historical `597b04e` regression (fully documented in the
  baseline-recovery reports).
- **Reference**: rollback tag `recovery-snapshot-before-restore` → `bd81ba5` (recorded
  at the closeout; **absent from origin in this clone now** — see §7.3). The recovery
  itself was forward-only; this task likewise performed no reset, revert, cherry-pick,
  merge, rebase or force-push, and `597b04e` remains fully auditable.

## 17. Final status

# BLOCKED

**The investigation is complete and the root cause is proven** — scenario 2: the
registration code at HEAD is byte-identical to the verified `b47d098` baseline that
produced recorded 200s for all three routes; the 404s are the previously-proven stale
local `rewrite_rules` failure mode (a rules set generated without the theme's
`pll_get_post_types` declaration carries no `en/`-prefixed CPT-archive rule); and the
minimal, architecture-preserving fix is the **documented local rewrite flush**
(§7.6, now recorded in `docs/routing.md`), not a code change.

The status is **BLOCKED**, not PASS / PASS WITH LIMITATION, because the required
implementation — executing that flush in the local Docker runtime and re-verifying the
three routes — **could not be performed in this environment at all** (no Docker, no
PHP, no MySQL: §13.1). The routes therefore remain unrestored in the task runtime until
the §7.6 runbook is executed there. Per this task's own rule, a status may not be PASS
while any required route remains broken. Nothing was masked: no redirect, no gate
change, no expectation change, no content invention.

## 18. Explicit statements (task-required)

- **PT immutability: held.** No PT route, record, slug, term, language or translation
  relationship was changed; the repository change touches no `wp-content/` file, and
  no runtime in this environment wrote anything.
- **No production writes or deployment were performed.**
- **The Flutter/mobile repository was not accessed.**
- **No gate was weakened**: no route allowlist, no suppressed 404, no threshold change,
  no altered acceptance expectation, no manufactured EN content.
- **No history rewrite**: work is a forward documentation commit on `i18n`; the
  recovery history (`b47d098`, `597b04e`, `cd800be`, `e7303ba`) is intact and auditable.

_Last verified: 2026-09-28 by the EN archive route 404 investigation_
