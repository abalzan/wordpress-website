# Report — EN Translation Rollout, Phase 3: Apply + Immediate Verification

> **PHASE 3 DID NOT APPLY. Production writes: 0. HTTP verbs used: GET only.**
>
> Round 2 re-ran after the authoriser resolved both open questions from round 1
> (the 13 `conexao_category` creates are **in scope**; the invariant is **0 PT
> canonical-content mutations + 0 PT authored-content drift**). **Both decisions are
> satisfied by the live plan** — the 438-object scope is confirmed exactly and every
> required-zero gate is 0. See §14.
>
> The apply still requires a human to press the button: the shared engine's only apply
> entrypoint is a `manage_options` + nonce `admin_post` form, and neither rollout plugin
> registers a single REST route.
>
> **New since round 1:** the two temporary rollout plugins are now **installed and ACTIVE
> in production**. Step 1 is satisfied; only step 4 remains.

|||
|---|---|
| **Stage / task name** | EN Translation Rollout — Phase 3: Apply + Immediate Verification (round 2) |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `6bc4a6be8654cc083a162445491227a278ce0a47` |
| **Final SHA** | `6bc4a6be8654cc083a162445491227a278ce0a47` (unchanged — no commit) |
| **Production target** | `https://conexaobr.ie` (WordPress.com) |
| **Production writes** | **0** |
| **EN records created** | **0** |
| **Status** | **BLOCKED — apply requires a human in a browser session** |

---

## 1. Scope completed

The pre-apply verification the authorisation requires (step 2) was completed in full,
against **live production**, using the repository's **own unmodified engine**:

1. Re-ran the Phase 3 dry-run via the committed read-only tool (`en-dry-run.py`,
   `GET` only, no `--apply`, no writer reachable).
2. Proved the operation set is exactly the authorised **438 objects**
   (126 B1 + 299 B2 + 13 `conexao_category`).
3. Proved **0 PT drift** by re-reading and comparing PT identity digests per post type.
4. Proved the plan and snapshot are **reproducible** (identical digests; snapshot
   byte-for-byte).
5. Ran the canonical test runner and the permanent gates; compared to the baseline.
6. Confirmed both authoriser decisions are satisfied by the plan (§14).
7. Re-verified, with command-level proof, that the apply cannot be executed from
   here — and that the temporary tooling is now installed and active in production.

## 2. Scope NOT completed

Steps **4, 5, 6, 7 and 9** of the required sequence were not performed. Step 1
(**tooling install**) is now **satisfied** — the two temporary plugins are
installed and active in production. Step 4 (applying through the engine) has no
agent-reachable path.

| Required step | Performed? | Why not |
|---|---|---|
| 1. Temporary tooling install/activate | **YES (done externally)** | Verified this round: `GET /wp-json/wp/v2/plugins` → 200, `conexao-translation-rollout` **active**, `conexao-en-translation` **active**. Round 1 recorded these as absent; they are now present. |
| 2. Pre-apply verification | **YES** | See §4, §5. |
| 3. Production snapshot | **YES** (read-only) | 438 objects, digest `d679f1c1…`, **byte-identical** to the committed baseline. |
| 4. Apply (126 B1 + 299 B2 + 13 taxonomy) | **no** | `Engine::run()` has **zero** production callers reachable over HTTP. Only entrypoint is `admin_post_…::handle_run()`, gated by `current_user_can('manage_options')` **and** `check_admin_referer()`. Production has no WP-CLI, and the wp-admin cookie session is not obtainable with an application password (`tools.php` → **302** to `wp-login.php`). |
| 5. Immediate post-apply verification | **no** | Nothing was applied. |
| 6. Route/HTTP verification | **partial (pre-apply only)** | Pre-apply state captured (§6). Post-apply expectations cannot exist. |
| 7. Idempotence (second dry-run) | **no** | Requires a completed apply. |
| 8. Permanent gates + regression | **YES** | §7, §8, §9. |
| 9. Temporary tooling removal | **not applicable** | Authorised only after a successful apply. Nothing was applied, so the tooling **stays installed** — that is the correct terminal state. |

**The only compliant path to the apply is a human maintainer in a browser**:
*Tools → Translation Rollouts* → select stage → **Preview**, then **Apply**.
The plugins are already installed, so this is now a single screen. Doing it any
other way would require hand-writing REST calls that reproduce engine logic —
which this task explicitly prohibits, and which the repository's own skill
forbids ("the repository ships the plan, not a production mutation").


## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-30-en-rollout-phase-3-apply.md` | updated | this report (round 2) |
| `docs/evidence/2026-09-30-en-rollout-phase-3-apply/` | updated | +6 round-2 evidence files, README and safety ledger updated |

**No source file changed.** No `wp-content/`, `scripts/` or `tests/` change.
`plugins.json` untouched. `docs/evidence/2026-09-26-stage-l/gate.json` is rewritten
as a side effect of running the gate runner and was **restored with
`git checkout`**; `git status --porcelain` is back to the pre-round state (17
entries, all pre-existing prior-phase work — none introduced here).

## 4. Baseline revalidation — every value matches

| Measure | Authorised | Re-measured | Result |
|---|---:|---:|---|
| **Total mutation objects** | **438** | **438** | MATCH |
| Manifest objects | 425 | **425** | MATCH |
| B1 creates / links | 126 | **126** | MATCH |
| B2 field updates | 299 | **299** | MATCH |
| `conexao_category` taxonomy creates | 13 | **13** | MATCH |
| `missing_source` | 0 | **0** | MATCH |
| Conflicts | 0 | **0** | MATCH |
| Duplicate targets | 0 | **0** | MATCH |
| PT overwrite attempts | 0 | **0** | MATCH |
| Unexpected PT mutations | 0 | **0** | MATCH |
| Unclassified | 0 | **0** | MATCH |
| Invalid / orphan | 0 | **0** | MATCH |
| Unresolved slug conflicts | 0 | **0** | MATCH |
| Unexpected EN existing | 0 | **0** | MATCH |
| `en-guide` authored rows | 50 | **50** | MATCH |
| `newsletter` resolves to | `newsletter` | **`newsletter`** | MATCH |
| `newsletter-2` occurrences in plan | 0 | **0** | MATCH |

Per-stage plan: `en-guide` 50, `en-page` 31, `en-post` 43, `en-blog-page` 1,
`en-jobs-page` 1 (B1 = 126); `en-leisure-description` 289,
`en-course-provider-description` 10 (B2 = 299). All `conflicts = 0`.

**Reproducibility** — plan digest `7dc4f56e…`, snapshot digest `d679f1c1…`, 438
snapshot objects: **identical to the committed baseline**. `snapshot.json` is
**byte-for-byte identical** (sha256 `82d66b41…`). `plan.json` differs in exactly
**one field**, proven by a recursive structural diff:
`report/generated_at_utc` (`13:27:28Z` vs `13:16:26Z`). No plan content changed.

**PT integrity** — live PT identity digest `76620b66…`, identical to the committed
baseline; **8/8 post types identical** (course_provider, event, guide, job, leisure,
page, post, sponsor). **Zero PT drift.**

> The tool prints `matches PT baseline: False`. This is **not** drift: it compares against
> a hardcoded *pre-restoration* constant (`cfe4b7a6…`) in the read-only tool. The
> authorised six trash restorations legitimately moved PT to `76620b66…`, which is what
> the committed Phase 3 baseline already records.

## 5. Apply results

| Measure | Planned | Applied | Verified |
|---|---:|---:|---:|
| B1 creates + translation links | 126 | **0** | **0** |
| B2 field updates | 299 | **0** | **0** |
| `conexao_category` term creates | 13 | **0** | **0** |
| **Total mutation objects** | **438** | **0** | **0** |
| Successful production writes | - | **0** | - |
| Failed writes | - | **0** | - |
| Skipped | - | **0** | - |
| Unexpected writes | - | **0** | - |
| EN records created / links created | - | **0 / 0** | - |
| EN field updates | - | **0** | - |
| EN taxonomy terms created | - | **0** | - |
| Duplicate targets / conflicts / PT overwrites / unclassified | - | 0 / 0 / 0 / 0 | - |

**B2 EN-field writes on PT records: 0 of 299 applied.** The 299
`_leisure_excerpt_en` / `_provider_excerpt_en` writes are authorised and planned,
but nothing was written. **PT canonical-content mutations: 0.**

## 6. Route / HTTP impact

**None.** Production behaviour is unchanged. Pre-apply state (GET only):

| Route | HTTP | Canonical | State |
|---|---:|---|---|
| `/en/` | 200 | `/en/` | self-canonical EN |
| `/en/guias/` | 200 | `/en/guias/` | self-canonical EN |
| `/en/blog/` | 200 | `/blog/` | B2 fallback → PT (expected pre-apply) |
| `/en/empregos/` | 200 | `/empregos/` | B2 fallback → PT (expected pre-apply) |
| `/en/newsletter/` | 200 | `/newsletter/` | B2 fallback → PT (expected pre-apply) |
| `/blog/`, `/empregos/`, `/newsletter/`, `/guias/`, `/lazer/` | 200 | self | unchanged |

The three shared-slug EN routes are exactly the destinations the 126 B1 creates will turn
into real EN records; they are B2 fallbacks today, as expected.


## 7. Tests / gates — real numbers

`./scripts/run-tests.sh`:

| Suite group | Result |
|---|---|
| In-process PHP | **63 total, 63 passed, 0 failed** |
| Assertions | **4,216 passed, 0 failed** |
| Script-contract | **6 total, 5 passed, 1 failed** |
| HTTP acceptance | **3 total, 3 passed, 0 failed** (20 + 44 + 92 rows) |

`python3 scripts/verify-permanent-gates.py`: **7 gates, 6 passed, 1 failed**,
**89 assertions passed, 3 failed**. Passing: taxonomy (18), translation_completeness (23),
cache_scoping (9), cache_scoping_static (2), redirect_precedence (15),
documentation_drift (14).

`php scripts/generate-registry-docs.php --check`: **registry OK, 14 plugins validated,
23 generated regions current, zero writes.**

**New failures: 0.**

## 8. Regression comparison

| Measure | Baseline | This run | Delta |
|---|---:|---:|---|
| In-process suites failing | 0 | **0** | none |
| Assertions failing | 0 | **0** | none |
| Script-contract failing | 1 | **1** | none |
| Acceptance assertions failing | 0 | **0** | none |
| Permanent gates failing | 1 | **1** | none |

The diff of the failing lists is **empty**. No assertion was weakened, skipped or
baselined.

## 9. Known pre-existing failure (explicitly unchanged)

`tests/scripts/verify-i18n-freshness.py` fails on the **same three stale `.pot`
catalogues** — `conexao-br-irlanda` (92,483s), `conexao-content` (76,025s),
`conexao-event-runtime` (76,025s). This is the documented pre-existing debt, identical to
the baseline recorded in the trash-restoration phase. **It was not suppressed, rewritten
or repaired**, exactly as instructed. Its "1 new" label compares against a stale committed
baseline of an older commit.

## 10. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | 0 writes, `GET` only |
| Deploy / upload | **no** | |
| Temporary plugin install | **no** (done externally, before this round) | Verified **active** in production |
| Temporary plugin activation | **no** (done externally, before this round) | Verified **active** in production |
| Temporary plugin removal | **no — not authorised** | Permitted only after a successful apply |
| Content or DB mutation | **no** | |
| Polylang change | **no** | |
| Rollback | **no** | nothing to roll back |

## 11. Tool cleanup

**Deliberately NOT done, and correctly so.** The temporary tooling
(`conexao-translation-rollout`, `conexao-en-translation`) is installed and active
in production. The authorisation permits its removal **only after all verification
succeeds**, and no apply occurred, so removal is not authorised. Removing it now
would destroy the ability to apply.

The permanent production plugin set (`conexao-data-model`, `conexao-content`,
`conexao-admin-ux`, `conexao-event-runtime`) is **unchanged and verified
unchanged**. `plugins.json` untouched, no theme change, no Polylang change.

## 12. Rollback / recovery

Not required — no write occurred. If the human-maintainer apply later fails mid-way, the
existing recovery is unchanged: the stage's documented **Remove** path (`--remove` / the
admin Remove action) plus the committed pre-apply snapshot (438 objects, `d679f1c1…`,
now reproduced byte-identically a second time). A pre-apply snapshot exists and is
verified reproducible, so recovery is available. No database replacement or broad
restore is needed or permitted.


## 13. Limitations

1. **The apply could not be executed here.** No WP-CLI in production, no REST route in
   either rollout plugin (re-verified: 0 `register_rest_route`, 0 `wp_ajax_`, 0 cron),
   and the engine's only entrypoint requires a browser `admin_post` with
   `manage_options` + nonce. A WordPress application password authenticates the REST
   API but **not** the wp-admin cookie session: `GET /wp-admin/tools.php` returns
   **302 → `wp-login.php?…&reauth=1`**, so the nonce cannot be minted. This is a
   platform/architecture limitation, stated rather than worked around.
2. ~~Plugin installation could not be performed here.~~ **RESOLVED** — the plugins
   are installed and active; that prerequisite no longer blocks anything.
3. Consequently **no post-apply verification, no post-apply HTTP acceptance, no
   idempotence run and no cleanup verification** could be produced. These are
   `not tested`, not `passed`.
4. The `.pot` freshness failure prevents a fully green run; it is pre-existing and out of
   scope.

## 14. The two scope items — now RESOLVED by the authoriser

**Note 1 — 13 taxonomy creates: RESOLVED — IN SCOPE, and verified in scope.**
The authoriser has explicitly placed the 13 `conexao_category` creates inside the
authorised mutation set, giving **438 = 425 manifest objects + 13 taxonomy
creates**. Confirmed against the live plan this round:

| Measure | Value |
|---|---:|
| `taxonomy_creates` | **13** |
| taxonomy of those 13 | `conexao_category` ×13 (**not** tag/county/town) |
| `planned_operation` | `CREATE` ×13 |
| `taxonomy_conflicts` | **0** |
| `taxonomy_already_linked` | 0 |
| `taxonomy_missing_source` | 0 |
| `conexao_tag` / `conexao_county` / `conexao_town` creates | **0 / 0 / 0** |

They are neither silently skipped nor treated as an unexpected operation class.

**Note 2 — the PT invariant: RESOLVED — "0 PT canonical-content mutations and 0
PT authored-content drift".** The authoriser correctly rejected "PT writes = 0" as
the invariant, because the B2 strategy deliberately writes EN-prefixed fields onto
PT records. Measured on the live plan:

| Measure | Value |
|---|---:|
| `pt_overwrite_attempts` | **0** |
| `unexpected_pt_mutation_operations` | **0** |
| `b2_field_writes_on_pt_record` | **299** (reported separately, by design) |
| B1 `pt_mutation` | `none — the PT record is only read` (126/126) |
| B2 `pt_mutation` | `one post-meta field on the existing PT record; no post row change` (299/299) |
| B2 write targets | `_leisure_excerpt_en` ×289, `_provider_excerpt_en` ×10 |

No planned object touches title, content, excerpt, slug, status, date, author,
featured media, canonical taxonomy assignments, canonical PT custom fields or
Polylang PT identity/relationship state.

## 15. Evidence

`docs/evidence/2026-09-30-en-rollout-phase-3-apply/` —

Round 2: `09-dry-run-plan-revalidated-phase3r2.json`,
`10-mutation-snapshot-phase3r2.json`, `11-dry-run-summary-phase3r2.txt`,
`12-run-tests-phase3r2.log`, `13-permanent-gates-phase3r2.log`,
`14-apply-reachability-reverified-phase3r2.txt`, `08-safety-ledger.txt`
(round-2 ledger at the top, round-1 record retained below as history).

Round 1 (retained): `01-dry-run-plan-revalidated.json`, `02-mutation-snapshot.json`,
`03-dry-run-summary.txt`, `04-run-tests-output.log`, `05-permanent-gates.log`,
`06-apply-reachability-audit.txt`, `07-pre-apply-http-state.txt`.

## 16. Documentation updated

This report and its evidence set only. `docs/engineering-standard.md`, `docs/routing.md`,
`plugins.json` and the registry-generated regions are **unchanged** —
`generate-registry-docs.php --check` reports zero writes.

## 17. Final status

**Final status: BLOCKED (unchanged) — but materially closer than round 1.**

The plan, the 438-object scope, both authoriser decisions and the PT invariant were
all re-verified against live production this round and reproduce exactly. What
remains is a **single human action**: press Apply in *Tools → Translation Rollouts*.

| Round-1 blocker | Round-2 status |
|---|---|
| Temporary plugins not installed | **RESOLVED** — both installed and active |
| Apply unreachable from an agent environment | **UNCHANGED** — still requires a browser session |

This is an **execution-context blocker, not a plan or safety blocker**. The
authorisation is now unambiguous and fully satisfiable; the repository is in the
correct terminal state for an aborted apply (tooling installed, ready, no writes).

**This report does not declare PASS.** Per the authorisation, PASS requires the
complete 438-object scope applied *and* verified, post-apply PT integrity,
post-apply HTTP/hreflang acceptance, an idempotence dry-run and the tooling
cleanup. None of those can exist before a human performs the apply. They are
**not tested**, not passed.

---

_Last verified: 2026-09-30 by Phase 3 round 2 (0 production writes; 438 = 126 B1
+ 299 B2 + 13 taxonomy re-measured and identical to baseline; both authoriser
notes resolved and verified; snapshot byte-identical; 8/8 PT types identical)_
