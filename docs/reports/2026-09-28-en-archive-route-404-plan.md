# Plan — EN archive route 404 investigation (`/en/apoiadores/`, `/en/eventos/`, `/en/guias/`)

> Filled in from [`templates/plan.md`](../templates/plan.md) before any change, per
> engineering standard §13.2 (route work). The task text itself is the commissioning
> brief; this plan is the repository-side reading of it.

| | |
|---|---|
| **Task title** | Investigate and fix the EN archive routes that return 404 (`/en/apoiadores/`, `/en/eventos/`, `/en/guias/`) |
| **Date** | 2026-09-28 |
| **Author / agent** | Cline |
| **Branch** | `i18n` (start SHA `e7303ba47d0080e3778ba7e70dd5b9c29f44e170`, recovery closeout) |
| **Repository baseline (starting SHA)** | `e7303ba` — theme byte-identical to the verified baseline `b47d098` (only the event-importer POT repair differs in `wp-content/`) |
| **Plan status** | approved (investigation-first; implementation only if a code defect is proven) |

## 1. Scope

A regression investigation of the three EN routes that currently return 404, and the
smallest forward-only change that restores them **without** a new routing architecture,
content invention, or redirect masking. Classify each route's mechanism from repository
evidence, locate the last known-good state, prove what broke it, and fix exactly that.

## 2. Explicit non-goals

* **No new routing code, route allowlist, hardcoded exception, or redirect** to make a
  404 look like a 200 — the objective is the historically intended routing architecture.
* **No EN content creation.** `/en/guias/` is a real EN archive but the local dataset
  has 0 EN guide records (documented limitation at the closeout); that gap is a content
  decision, not a routing fix.
* **No rewrite-rule code change.** WordPress stores rewrite rules in the DB and
  regenerates them only on flush; an auto-flush at theme load would be a new mechanism
  (a documented performance anti-pattern), not the existing architecture.
* **No release-smoke-matrix growth.** `scripts/data/release-smoke-matrix.json` is
  deliberately fixed at 18 rows ("does not grow between releases",
  [`docs/releases.md`](../releases.md)); the `/en/apoiadores/` coverage gap is recorded
  as a recommendation only.
* **No production action of any kind** (no deploy, write, activation), and **no
  Flutter/mobile access**.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§0, §6.1, §8, §9, §11, §13.2);
`docs/routing.md`; `docs/content-model.md`; `docs/releases.md` + `docs/deployment.md`;
`plugins.json`; `scripts/README.md`; `tests/acceptance/matrices/routing.json` +
`guides-en.json`; `scripts/data/release-smoke-matrix.json`; the baseline-recovery
assessment/implementation/closeout reports and their evidence.

## 4. Current-state findings (verified, not assumed)

* Start state: branch `i18n`, clean tree, HEAD `e7303ba`; tag
  `recovery-snapshot-before-restore` **absent** locally and on origin (it existed at the
  closeout, pointing at `bd81ba5`).
* `git diff b47d098 HEAD -- wp-content/themes/ wp-content/plugins/` → only
  `conexao-event-importer.pot` (the legitimate POT repair the recovery preserved).
  The runtime PHP is the verified known-good implementation.
* All three routes are **CPT archives**: `sponsor` → `apoiadores`, `event` → `eventos`,
  `guide` → `guias` (`wp-content/plugins/conexao-data-model/conexao-data-model.php`,
  `has_archive` = the PT slug for each).
* EN archive URLs resolve through **DB-stored rewrite rules generated at flush time**:
  the theme has no `add_rewrite_rule`; `inc/i18n/guard.php` declares the six CPTs
  Polylang-translated (`pll_get_post_types`), which is what makes Polylang emit the
  `en/`-prefixed archive rules when rules are (re)generated.
* The acceptance contract already requires all three routes at **200**
  (`en-200-en-apoiadores`, `en-200-en-eventos`, `en-200-en-guias` in
  `tests/acceptance/matrices/routing.json`; `en-guides-200` in `guides-en.json`).
* Recorded history: all three routes were **200 at Stage N (2026-09-26)**;
  `/en/guias/` + `/en/eventos/` verified **200 at the recovery closeout** (same code as
  HEAD); `/en/apoiadores/` was **not probed** at the closeout (the routing HTTP suite
  timed out on the known cold-cache event-archive latency).
* The recovery already proved this exact failure mode once: with byte-perfect source,
  `/en/guias/` + `/en/eventos/` still 404'd because the DB `rewrite_rules` had been
  generated while the destructive `597b04e` theme (which has **no** `pll_get_post_types`
  declaration) was active — `en/`-prefixed CPT-archive rules: **0 → 92 after a local
  flush**, and the routes went 404 → 200 with **no code or content change**.
* **This sandbox cannot run any of it**: no `docker`, no `php`, no MySQL, no Chromium
  (the port-8080 listener is the sandbox router stub, not WordPress). The three 404s
  therefore cannot be reproduced live here; the runtime state of the task's local
  environment can only be reasoned about from repository + recorded evidence.

## 5. Proposed approach

Investigation first; classify the 404 cause against the five candidate scenarios the
task names (wrong registration code / correct code + stale DB rewrite state / Polylang
language-route gap / missing EN content / other query-template problem), using static
code proof, git history, and recorded evidence. Expected (to be proven, not assumed):
scenario 2 — registration code correct, local DB `rewrite_rules` stale. If proven:

* **Repository change = documentation only**: the route-resolution mechanism note in
  `docs/routing.md` (the flush requirement after a DB restore / pre-flush snapshot),
  the investigation report, and curated evidence. **No code change** — every candidate
  code fix would be either a no-op (nothing is missing or disconnected) or a new
  routing architecture (forbidden).
* **Runtime fix = the documented local flush** (`scripts/flush-blog-rewrite-rules.php`
  or a Permalinks save in local wp-admin; NOT `flush-navigation-rules.php`, which also
  deletes pages and rebuilds menus), recorded as a **local DB operation, not a code
  fix**, exactly as the recovery did.
* Options rejected: restoring files from `b47d098` (nothing to restore — the tree is
  byte-identical); reconnecting a helper (none disconnected); auto-flush/guard code
  (new architecture + performance anti-pattern); redirecting the 404s to PT (masking).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `docs/reports/2026-09-28-en-archive-route-404-plan.md` | add | this plan (route work requires it) |
| `docs/reports/2026-09-28-en-archive-route-404.md` | add | the investigation report (from `templates/report.md`) |
| `docs/evidence/2026-09-28-en-archive-route-404/` | add | curated proof (start state, code identity, registration chain, destructive diff, contract rows, recorded history, runnable gates) |
| `docs/routing.md` | add subsection + `_Last verified_` | record the EN archive rewrite-rule dependency and the documented local flush (route knowledge; the change → document map) |
| `docs/reports/README.md` | register report + plan | the reports index links every report |

## 7. Files explicitly expected NOT to change

`wp-content/**` (all plugins + theme — byte-identical to the verified baseline);
`plugins.json`; `compose.yaml`; `tests/**` (the acceptance rows already encode the
three routes at 200 — no expectation is altered); `scripts/**` (no new script; the
flush script exists); `AGENTS.md`; every `GENERATED` region; `.htaccess`.

## 8. Content / data impact

None. No record, term, option, upload or menu is created, updated or deleted by this
task's repository change. The remediation flush regenerates the `rewrite_rules` option
only (a derived cache of the registration state, not content). No PT content may
change — verified by diff scope (`docs/` only) and the fact that no runtime here can
write anything.

## 9. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap behaviour is changed by the
repository change. The **runtime** remediation restores exactly the three routes'
documented 200s (and, if the DB carries the older stale set, the other EN routes'
documented states — `/en/lazer/`, `/en/cursos/`, `/en/blog/` 200; `/en/empregos/` 302
B1). No HTTP matrix row is added or changed: `routing.json` and `guides-en.json`
already assert all three routes.

## 10. Polylang / English impact

Translated post types: unchanged (`guide`, `event`, `leisure`, `sponsor`, `job`,
`course_provider` via `inc/i18n/guard.php`). PT immutable: yes (nothing writes). B1/B2:
unchanged — sponsor/event archives stay B2-widened (`conexao_b2_archive_widen_query`),
`/en/guias/` stays the real EN archive, `/en/empregos/` stays B1 (302). Canonical,
hreflang, sitemap: emitted by the existing modules; no change. No cache key changes.
Completeness gate: not applicable to a routing fix; the pre-existing
translation-completeness limitation (event `11553`) stays untouched.

## 11. Security impact

No capability, nonce, escaping, SQL, REST or secret surface is touched
(documentation-only repository change). The remediation script is the existing
catalogued local-write script; no credentials are introduced.

## 12. Performance impact

None. No query, asset or cache-key change. (Documented, pre-existing and out of scope:
the 10–17s cold-cache event-archive latency.)

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | n/a — nothing is deployed by this task |
| What is the verification of the production result? | n/a — no production action; production registration is determined from the documented release sequence (§11: theme active **before** plugin activation, whose `activate()` flush runs with the declarations live) |

## 14. Test plan

* Run what this environment can run: `./scripts/run-tests.sh --scripts` **before and
  after** the documentation change (failing-suite diff must be empty; failures are
  php-absent environment gaps, classified as such).
* Every other verifier (in-process PHP, HTTP acceptance, permanent gates, browser) is
  **unavailable here** (no `php`, no Docker runtime) and must be reported as blocked,
  not skipped silently, with the exact commands to run in a complete environment.
* No new in-process suite: there is no code change to test; the existing
  `routing.json`/`guides-en.json` rows are the acceptance contract for the routes.

## 15. Acceptance matrix plan

No row added or changed. The three routes are already permanent acceptance rows
(`en-200-en-apoiadores`, `en-200-en-eventos`, `en-200-en-guias` → `expect_status: 200`,
EN shell present, PT shell absent; plus `en-guides-200` with canonical/hreflang/no-B2
assertions). Adding rows would duplicate the contract; changing expectations would be
gate-tampering. Release-smoke `/en/apoiadores/` coverage is documented as a
recommendation only (fixed 18-row gate).

## 16. Rollback plan

Repository change: `git revert` of the single documentation commit (report + evidence +
routing note are self-contained; no generated region or code touched). What rollback
does **not** cover: the local DB flush (a runtime operation in the local Docker volume
— harmless: rules are a derived cache regenerated by the same flush) and any PT
content (none touched).

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/routing.md` | new subsection under §English: EN archive route resolution via flush-generated rewrite rules + the documented local flush after a DB restore; `_Last verified_` line |
| `docs/reports/README.md` | register the investigation report (+ plan) |
| this plan + the report + evidence | as listed in §6 |

## 18. Release implications

No component version bump (no `wp-content/` change), no artifact allowlist effect, no
release in scope. `dist/` is untouched.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into "improving" routing code that is not broken | medium | byte-identity proof vs `b47d098` first; code changes require a proven code defect |
| Mis-reading recorded evidence as current runtime fact (no live runtime here) | medium | every runtime claim is labelled recorded-vs-live; the report's limitations section lists every unavailable verifier |
| Growth of the release smoke matrix mid-task | low | fixed-shape gate rule quoted; recommendation only |
| The flush being mistaken for a code fix | medium | the task itself and the routing note both classify it as a local DB operation |

## 20. Verification gates

1. `./scripts/run-tests.sh --scripts` — before/after failing-suite diff empty
   (this environment's runnable subset).
2. `git status --short` — only the five intended documentation paths.
3. `git diff --check` — no whitespace/junk artefacts.
4. In a complete environment (blocked here, commands recorded): flush →
   `./scripts/run-tests.sh --acceptance` → the three `en-200-en-*` rows green; PT rows
   unchanged; `python3 scripts/verify-permanent-gates.py` unchanged vs the closeout
   (6/7 pass, translation-completeness pre-existing).

## 21. Completion criteria

Per §14 of the standard: scope matches the request; docs updated in the same commit;
report written with real numbers and stated limitations; no secrets/localhost URLs in
production data; Flutter untouched. The route restoration itself is complete only when
the documented flush has been executed in an environment that can run it — if that
cannot happen here, the final status is **BLOCKED** with the runbook attached, never a
silent PASS.

_Last verified: 2026-09-28 by the EN archive route 404 investigation plan_
