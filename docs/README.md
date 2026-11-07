# Documentation index

The **human** documentation index for this repository: what each document is for
and which one is authoritative. It is navigation only — the standards live in the
linked documents themselves. New docs belong under `docs/` and must be added here
in the same change.

This is a documentation index, not a workflow manual. If you are an agent, start
at [`AGENTS.md`](../AGENTS.md) and select a skill.

## How this repository splits its knowledge

| | What it holds | Where |
|---|---|---|
| **Reusable agent procedure** | *how to do a kind of work* — steps, guardrails, verification, failure handling | [`.agents/skills/`](../.agents/skills/) — index in [`skills/README.md`](../.agents/skills/README.md) |
| **Canonical fact** | *what is true about this project* — architecture, content model, routes, policy, contracts | `docs/` (this index) |
| **History** | *what happened and what was measured* | [`reports/`](reports/), [`evidence/`](evidence/), [`audit/`](audit/) |

**A procedure has one operational home.** A canonical document that needs to
mention a procedure links to the skill rather than restating the steps, and a
historical report is never the source of a current instruction.

## Agent workflows live in `.agents/skills/`

There are **14 active WordPress skills**, each an executable workflow with a
standard format (Purpose → When to use → When not to use → Required reading →
Authoritative sources → Preconditions → Steps → Guardrails → Verification →
Failure handling → Evidence and reporting → Definition of done). One sentence
each; the full workflow is in the skill.

| Skill | One sentence |
|---|---|
| `wp-repository` | Orient in the repository, plan the work, hold the scope, prefer a shared engine, and review before merge. |
| `wp-add-content-type` | Add or change a post type, taxonomy, meta field, archive or single route. |
| `wp-add-admin-screen` | Make a maintainer capability operable through wp-admin — required because production has no CLI. |
| `wp-content-change` | Write content, meta or terms safely via the six-step contract (dry-run, snapshot, apply, verify, rollback). |
| `wp-translation-rollout` | Add English coverage for a content type on the shared rollout engine, with PT provably unchanged. |
| `wp-testing` | Run, diagnose and compare the test contract; add layers, fixtures and permanent fail-closed gates. |
| `wp-write-in-process-test` | Author an in-process PHP suite against a real WordPress, discovered by convention. |
| `wp-http-acceptance-matrix` | Assert what a real request returns — status, redirect, canonical, hreflang, sitemap, filters. |
| `wp-security-review` | Prove capability, nonce, sanitisation, escaping and prepared statements before shipping. |
| `wp-frontend-perf` | Keep cache keys language-scoped, invalidation complete, and any improvement measurable. |
| `wp-plugin-registry` | Keep `plugins.json` the only plugin registry and regenerate every derived region. |
| `wp-release-deploy` | Build, record, verify and deploy a release deterministically, with a working rollback. |
| `wp-production-operations` | Audit and safely update the live WordPress.com site; update in place, never deactivate to update. |
| `wp-update-docs` | Keep documentation truthful: one owner per fact, one home per procedure, no drift. |

The index of record is [`.agents/skills/README.md`](../.agents/skills/README.md);
`tests/scripts/verify-agent-governance.py` fails if it drifts from the directory.
The retired Dart/Flutter skill set that predates these is kept for provenance
under [`.agents/legacy-flutter-skills/`](../.agents/legacy-flutter-skills/) and
is **not** an active skill set.

## Standards, audits and engineering

| Document | What it is |
|---|---|
| [engineering-standard.md](engineering-standard.md) | The authoritative engineering standard (WP-ES) — read this before changing code |
| [audit/](audit/) | Engineering standardisation audits |
| [audit/2026-10-03-ui-ux-audit.md](audit/2026-10-03-ui-ux-audit.md) | **UI / UX audit and design direction** — the current visual system measured across 24 pages × 2 viewports, the inconsistency inventory, three design directions, the recommended direction, the Tier 1–3 backlog and the implementation map. Evaluation only; no implementation. |
| [reports/2026-10-03-ui-convergence-t1-plan.md](reports/2026-10-03-ui-convergence-t1-plan.md) | Plan for **UI convergence Phase 1** (audit T1.1–T1.5, Direction A "Converged Minimal"), including the corrected T1.2 diagnosis and the explicit non-goals |
| [reports/2026-10-03-ui-convergence-t1-report.md](reports/2026-10-03-ui-convergence-t1-report.md) | **UI convergence Phase 1 implementation record** — the `/empregos/` orphan grid fix, the duplicate-event-card fix (and why the audit's recurrence hypothesis was wrong), section-heading and card-title convergence, orange contrast remediation, and the dark-theme token cascade bug. Evidence in [evidence/2026-10-03-ui-convergence-t1/](evidence/2026-10-03-ui-convergence-t1/) |
| [reports/2026-10-03-ui-convergence-master-report.md](reports/2026-10-03-ui-convergence-master-report.md) | **UI convergence Phase 2 implementation record** (audit T1.6–T1.14) — heading-level contract, one merged event location row, muted-text contrast remediation at both the rule *and* the token layer, card-surface reconciliation, the comment submit button, the default-category label, and text-led image-less leisure cards; plus T1.9/T1.14 recorded as deliberately NOT IMPLEMENTED with evidence. Evidence in [evidence/2026-10-03-ui-convergence-t2/](evidence/2026-10-03-ui-convergence-t2/) |
| [reports/2026-10-10-ui-polish.md](reports/2026-10-10-ui-polish.md) | **UI polish Phase 3** — the Tier-2 convergence remainder, re-validated against live renders: component-heading tokens, the card-CTA 44px target floor and the 720px single editorial measure, with the shared-card / breakpoint / filter items recorded as deliberately deferred. Evidence in [evidence/2026-10-10-ui-polish/](evidence/2026-10-10-ui-polish/) |
| [reports/README.md](reports/README.md) | Index of dated stage/feature reports + the no-root-reports rule |
| [reports/site/](reports/site/) | Repository-engineering stage reports (Stage B repository hygiene, Stage C static quality tooling, …) |
| [evidence/README.md](evidence/README.md) | Standing rule for machine-readable evidence: naming, a complete index of every evidence folder, the A–E retention policy, what must never be committed, and how to add new evidence |
| [project-inventory.md](project-inventory.md) | Project/plugin inventory (versions generated from plugin headers) |

### Permanent invariant gates (Stage L)

Six standing, fail-closed gates turn the engineering standard's safety rules
into CI-enforced checks. They run in the **default** suite
(`./scripts/run-tests.sh`) and are discovered by convention — no separate
runner, no hand-maintained list.

| Domain | Enforces | Where it is documented |
|---|---|---|
| Taxonomy policy | shared `conexao_county`/`conexao_town`; translated `conexao_category`/`conexao_tag` linked both ways | [testing.md](testing.md) §"The permanent invariant gates" |
| Translation completeness | `eligible public PT <type> missing EN = 0` | [testing.md](testing.md) |
| Language-scoped caching | no unscoped `conexao_*` cache key; PT ≠ EN at runtime | [testing.md](testing.md), [frontend.md](frontend.md) |
| Legacy EN→PT redirect precedence | the legacy 301 to PT still wins over a colliding EN page | [testing.md](testing.md), [routing.md](routing.md) |
| Documentation drift | generated regions, script catalogue, `_Last verified:` markers, no root reports | [testing.md](testing.md) |
| i18n catalogue freshness | a `.pot` is never older than the PHP defining its strings | [testing.md](testing.md), §9.3 of the standard |

```bash
python3 scripts/verify-permanent-gates.py   # run every gate, write gate.json
./scripts/i18n-check.sh                     # §9.3's documented entry point
```

A failed gate means a real repository/data violation. Gates are never
downgraded to warnings; the recorded baseline only labels a violation
`pre_existing` vs `new` for reporting, and never suppresses it. Machine-readable
evidence: [evidence/2026-09-26-stage-l/gate.json](evidence/2026-09-26-stage-l/gate.json).

## The plugin registry (single source of truth)

**[`plugins.json`](../plugins.json) is the authoritative plugin list.** It owns
plugin load order, dependencies, lifecycle class/status, the production
activation order, release build inclusion and local Compose mounts.

Every derived list is generated from it by
[`scripts/generate-registry-docs.php`](../scripts/generate-registry-docs.php)
and is marked in the file it lives in, so no list is maintained twice:

```bash
php scripts/generate-registry-docs.php --check   # validate + drift gate (zero writes)
php scripts/generate-registry-docs.php --write   # regenerate the marked regions
```

| Generated region | File |
|---|---|
| Plugin inventory table | `AGENTS.md` |
| Plugin registry summary | `README.md` |
| Load order + lifecycle table | `docs/plugins/README.md` |
| Per-plugin lifecycle metadata | `docs/plugins/conexao-*.md` |
| Production activation order | `docs/deployment.md` |
| Plugin inventory (versions) | `docs/project-inventory.md` |
| Plugin load order | `docs/architecture.md` |
| Release build list | `scripts/build-plugins-zip.sh` |
| Local Compose mounts | `compose.yaml` |

The release **artifact allowlist** is likewise derived from `plugins.json` (the
`build: true` entries in load order, plus the theme) by
`scripts/lib/release.py`, and is recorded — together with the version, git SHA,
file count and SHA-256 of every artifact that was actually built — in
`dist/release.json`. See [`docs/releases.md`](releases.md).

`php scripts/generate-registry-docs.php --check` is a **blocking** CI gate.
To add, remove or re-classify a plugin: edit `plugins.json`, add its doc under
`docs/plugins/`, then run `--write`. Never hand-edit a generated block.

## Architecture and content model

| Document | What it is |
|---|---|
| [architecture.md](architecture.md) | System architecture overview |
| [content-model.md](content-model.md) | Content types, taxonomies, meta and language policy |
| [commemorative-events.md](commemorative-events.md) | **The commemorative presentation system** — event vs package vs placement vs component, the theme-owned registry and dispatcher, route kinds, conditional assets, preview/cache boundaries, and the *how to add an event* runbook |
| [commemorative-content.md](commemorative-content.md) | **The commemorative content playbooks** — one seven-field brief per package (implemented and planned), the content calendar, the manual overlap watchlist and the content-operations policy |
| [routing.md](routing.md) | URLs, archives, filters, redirects, sitemap and the `/en/` layer |
| [translation-manual-operator-runbook.md](translation-manual-operator-runbook.md) | **The operator runbook** — how a human or coding agent performs an occasional manual PT→EN translation run: the lifecycle, the required inputs, the retained safety controls, the provider-credential procedure, a worked example, the expected result numbers, local vs production, recovery, and the final checklist |

## Development and deployment

| Document | What it is |
|---|---|
| [development.md](development.md) | Local setup, Docker, the dev-only quality toolchain (`composer lint` / `analyse` / `./scripts/lint.sh`), and the one test command |
| [testing.md](testing.md) | **How to run and write tests** — the three-layer model, `./scripts/run-tests.sh`, the shared bootstrap/assertions, prerequisites, fixtures, acceptance base URL, CI |
| [../scripts/README.md](../scripts/README.md) | **The authoritative script catalogue** — every current script with safety level, arguments, default mode, target and last-verified date, plus the new-script contract |
| [deployment.md](deployment.md) | Build ZIPs, deployment to WordPress.com, verification |
| [releases.md](releases.md) | **The release contract** — artifact allowlist, `dist/release.json`, tag convention, deployment verification, rollback procedure and production constraints |

## Frontend

| Document | What it is |
|---|---|
| [frontend.md](frontend.md) | UI/design conventions, CSS and JS rules |
| [themes/conexao-br-irlanda.md](themes/conexao-br-irlanda.md) | The active theme — templates, template parts, assets, `inc/` modules |
| [ui/](ui/) | UI change reports (mobile CTA, county filter labels, …) |

## Plugins

| Document | What it is |
|---|---|
| [plugins/README.md](plugins/README.md) | Plugin inventory, load order, lifecycle statuses |
| [plugins/conexao-*.md](plugins/) | One doc per custom plugin (purpose, data, verification) |
| [plugins/conexao-commemorative.md](plugins/conexao-commemorative.md) | **The commemorative events engine** — window rules, occurrence-year identity, priority + manual annual precedence, one-active-event resolution with reasons, signed administrator preview, targeted activation-boundary cache invalidation, the enforced Polylang single-owner rule, and the deliberate no-REST deviation |
| [plugins/conexao-translation-rollout.md](plugins/conexao-translation-rollout.md) | **The shared translation-rollout engine** — the six-step lifecycle, the stage-config and data-manifest contracts, the numeric gate, the remove/rollback contract, and how to add the next rollout |

## History and reference

| Document | What it is |
|---|---|
| [reports/](reports/) | English rollout (Stages 0–9) + navigation-fix reports |
| [reports/english-stage43-production-deployment.md](reports/english-stage43-production-deployment.md) | Stage 4.3 production deployment record |
| [reports/2026-10-02-event-source-restoration.md](reports/2026-10-02-event-source-restoration.md) | Why the event-source registry reported 6 sources, and how the full 58 (6 legacy + 52 county) were recovered |
| [reports/2026-10-26-halloween-homepage-plan.md](reports/2026-10-26-halloween-homepage-plan.md) | Plan for the centered, dismissible Halloween homepage refinement |
| [reports/2026-10-26-halloween-homepage-report.md](reports/2026-10-26-halloween-homepage-report.md) | Implementation and verification record for the Halloween homepage experience |
| [reports/2026-10-27-halloween-navigation-continuity-plan.md](reports/2026-10-27-halloween-navigation-continuity-plan.md) | Plan for one-second Halloween navigation-effect continuity |
| [reports/2026-10-27-halloween-navigation-continuity-report.md](reports/2026-10-27-halloween-navigation-continuity-report.md) | Implementation and verification record for cross-navigation Halloween effects |
| [reports/2026-10-27-halloween-witch-animation-plan.md](reports/2026-10-27-halloween-witch-animation-plan.md) | Plan for the rare, three-second witch-on-a-broom navigation effect |
| [reports/2026-10-27-halloween-witch-animation-report.md](reports/2026-10-27-halloween-witch-animation-report.md) | Implementation, responsive browser checks, and test results for the witch animation |
| [reports/2026-10-27-halloween-witch-animation-report.md](reports/2026-10-27-halloween-witch-animation-report.md) | Implementation, responsive browser checks, and test results for the witch animation |
| [reports/2026-10-27-halloween-theme-compatibility-plan.md](reports/2026-10-27-halloween-theme-compatibility-plan.md) | Plan for adapting Halloween visuals to the existing light and dark themes |
| [reports/2026-10-27-halloween-theme-compatibility-report.md](reports/2026-10-27-halloween-theme-compatibility-report.md) | Light/dark implementation, browser verification, tests, and limitations |
| [reports/2026-10-27-halloween-mobile-experience-plan.md](reports/2026-10-27-halloween-mobile-experience-plan.md) | Plan for safe, occasional mobile Halloween appearances |
| [reports/2026-10-27-halloween-mobile-experience-report.md](reports/2026-10-27-halloween-mobile-experience-report.md) | Implementation, timing, responsive browser evidence, tests, and limitations |
| [reports/2026-11-02-lazer-location-services.md](reports/2026-11-02-lazer-location-services.md) | **Lazer location services** — the approved plan implemented: leisure coordinate meta + validation + provenance + admin inventory column, visitor-activated browser geolocation on `/lazer/`, client-side Haversine distances, two read-only REST endpoints reusing the archive filter builder, nearest-first ordering with proximity-aware infinite scroll, and PT/EN copy |
| [reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md](reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md) | **Commemorative Events System — read-only architecture audit & r10 redesign proposal** (CPT + engine + admin UX + presentation registry; 9-stage roadmap; reproduces the New Year year-boundary defect). No implementation performed |
| [reports/2026-11-03-leisure-coordinate-research.md](reports/2026-11-03-leisure-coordinate-research.md) | **Lazer coordinate research and population** — the full `/lazer` inventory, the two-source (Wikipedia + OpenStreetMap) research method, the HIGH-confidence coordinates populated, the verification and the limitations |
| [reports/2026-11-03-leisure-coordinate-research.csv](reports/2026-11-03-leisure-coordinate-research.csv) | The complete coordinate research manifest (one row per published attraction, with both sources and the action) |
| [reports/2026-11-03-leisure-coordinate-owner-input.md](reports/2026-11-03-leisure-coordinate-owner-input.md) | The owner-input list: every attraction whose coordinates could not be established with HIGH confidence |
| [plans/2026-11-01-lazer-location-services.md](plans/2026-11-01-lazer-location-services.md) | The plan for the Lazer location services (coordinate meta, geolocation, proximity ordering, REST endpoints) |
| [plans/2026-11-03-leisure-coordinate-research.md](plans/2026-11-03-leisure-coordinate-research.md) | The plan for the Lazer coordinate research and population task |
| [reports/2026-11-03-phoenix-park-uuid-investigation.md](reports/2026-11-03-phoenix-park-uuid-investigation.md) | **Read-only investigation** of the shared `_leisure_export_uuid` on the Phoenix Park PT/EN pair — writers, consumers, the per-attraction uniqueness contract, export/import and REST impact, and the recommended minimal repair. Finds the shared UUID is correct by design |
| [reports/2026-11-03-phoenix-park-uuid-repair.md](reports/2026-11-03-phoenix-park-uuid-repair.md) | Implementation of the recommended repair: the coordinate resolver disambiguates a shared UUID by slug then language and fails safely; 29-assertion regression suite; `_lazer_uuid` drift fixed; zero database records changed |
| [plans/2026-11-03-phoenix-park-uuid-repair.md](plans/2026-11-03-phoenix-park-uuid-repair.md) | The plan for the Phoenix Park UUID repair |
| [reports/2026-11-03-leisure-location-cleanup.md](reports/2026-11-03-leisure-location-cleanup.md) | Cleanup and consolidation of the `leisure-location` branch: 75 per-run captures merged byte-for-byte into one run log, documentation-accuracy fixes, and a stated reason for every retained artefact. Maintenance only — no code or data change |
| [plans/2026-11-04-leisure-distance-optin.md](plans/2026-11-04-leisure-distance-optin.md) | The plan for making the `/lazer/` distance indicator visitor-activated, adding the friendly failure message + retry, and applying the approved distance display table |
| [plans/2026-11-05-leisure-location-production-migration.md](plans/2026-11-05-leisure-location-production-migration.md) | The migration plan for moving the Lazer coordinate and add-only attribute dataset to production over the REST API: scope, identity by slug, ordering, the blocking `auth_callback` prerequisite, risks and rollback |
| [reports/2026-11-04-leisure-distance-optin.md](reports/2026-11-04-leisure-distance-optin.md) | **Lazer distance indicator: explicit opt-in, friendly failure, approved display table.** The audit that found the feature already existed, the four gaps closed, the privacy confirmation, the three verification findings (stale browser expectations, a no-op `delete navigator.geolocation`, a PHPCS regression fixed rather than baselined) and the limitations |
| [reports/2026-11-04-leisure-proximity-geolocation-e2e.md](reports/2026-11-04-leisure-proximity-geolocation-e2e.md) | **Lazer proximity: browser geolocation simulation and end-to-end verification (read-only).** Five scenarios in real Chromium against a simulated visitor position, with the expected nearest-first order and every distance recomputed independently in Python; 88 assertions passed and the database proven unchanged |
| [reports/2026-11-05-leisure-location-production-audit.md](reports/2026-11-05-leisure-location-production-audit.md) | **Lazer location data: local ↔ production audit and migration preparation (read-only audit).** Production holds 0/289 coordinates where local holds 289/289 and is 1:1 by slug; 419 add-only attribute term assignments missing across 232 records. Documents the blocking `auth_callback` finding (`403 rest_cannot_update`), the migration script and its 58-assertion mock-API suite. **No production write.** |
| [reports/2026-11-05-leisure-coordinate-rest-permission-fix.md](reports/2026-11-05-leisure-coordinate-rest-permission-fix.md) | **Lazer coordinate REST write permission: the narrow `auth_callback` fix and its regression proof.** `conexao-data-model` 1.6.2 → 1.6.3; authorised administrator writes `_leisure_lat`/`_leisure_lng` over REST (200, persisted, re-read), anonymous 401 / unprivileged 403 with values unchanged. New 74-assertion in-process suite proven to discriminate (45/29 with the fix reverted), plus a real HTTP Application-Password proof on the local stack. Records the **pre-existing** `./scripts/lint.sh` PHPCS baseline drift. **No production deployment or migration.** |
| [reports/2026-11-05-leisure-location-production-rest-permission-verification.md](reports/2026-11-05-leisure-location-production-rest-permission-verification.md) | **Stage 7C: production REST permission test — PASS.** The deployed `conexao-data-model` measures as **1.6.3, active** (verified through the plugin collection and the `conexao-data-model/conexao-data-model` identity; the documented single-segment URL 404s on a route-identity artefact). The authorized write to `leisure` **10551** (`russborough-house`) returned **200**, persisted, and the original `""`/`""` was restored and independently verified authenticated, anonymously and through the Lazer dataset. **2 production writes**, one record, two meta keys; a 289-record sweep finds no other record modified; `post_modified` moved and one revision exists (accepted). Migration **not run**; no commit or push. Verdict `PASS` |
| [reports/2026-11-05-leisure-location-production-migration.md](reports/2026-11-05-leisure-location-production-migration.md) | **Stage 9: Lazer location production migration — PASS, applied.** Fresh inventory (289 records, 0 coordinates, 173 assignments) → three zero-write dry-runs → explicit operator authorisation → **578 POSTs** with the unchanged script: **289** coordinate writes (`written=289, gate=0`) and **289** attribute writes (`attributes_written=289`, not the plan's 232). **289/289** records now carry `_leisure_lat`/`_leisure_lng`, **592** assignments over all 13 terms, **0** conflicts / **0** removals / **0** records created or deleted. Independently verified after the fact: 14/14 checks (every coordinate equals the local source, every term set an exact match, `modified_gmt` moved on exactly the 289 in scope) plus the public dataset (289 items with `lat`+`lng`). Limitations: provenance keys are not REST-writable, `post_modified`/revisions are permanent |
| [reports/2026-11-06-commemorative-events-stage-0-3-plan.md](reports/2026-11-06-commemorative-events-stage-0-3-plan.md) | The Stage 0–3 plan for the commemorative events engine: the decisions, the files, the security/perf/Polylang impact and the explicit non-goals (Stages 4–6) |
| [reports/2026-11-06-commemorative-events-stage-0-3-implementation.md](reports/2026-11-06-commemorative-events-stage-0-3-implementation.md) | **Commemorative Events architecture r10, Stages 0–3 — implemented (commit `cfea1fa`).** New `conexao-commemorative` platform plugin (clock-injected windows incl. the 12-26 → 01-01 wrap, occurrence-year identity, priority + manual annual precedence, one-active-event resolution with reasons, signed capability-gated preview, targeted boundary invalidation with re-arm), `commemorative_event` CPT + `_ce_*` schema, wp-admin list/editor/diagnostics, and the idempotent seed of the six legacy occasions. **6 suites / 209 assertions**; records the PHPCS baseline drift present in its own evidence and the new-POT freshness limitation |
| [reports/2026-11-06-commemorative-events-r10-final-review.md](reports/2026-11-06-commemorative-events-r10-final-review.md) | **Review report (read-only, no implementation).** The consolidated, evidence-based review of the r10 Stages 0–6 implementation: requirements matrix, two High findings (H1 sitemap evidence, H2 Polylang single-owner not enforced), medium/low findings, exact per-layer test numbers and the release-readiness checklist. Verdict **NEEDS WORK** — it is a review, not an approval or a release |
| [reports/2026-11-06-christmas-homepage-welcome-modal.md](reports/2026-11-06-christmas-homepage-welcome-modal.md) | **Christmas homepage welcome modal** — the existing Christmas `homepage_hero` presented once per tab session as an accessible native `<dialog>` (immediate open, `sessionStorage` dismissal, scroll/focus restore, inline band without JavaScript, preview banner preserved), with a 109-assertion in-process suite, 14 Node runtime cases, and 408 real-browser checks over the eight required viewport profiles in PT-BR and English. Evidence in [evidence/2026-11-06-christmas-homepage-welcome/](evidence/2026-11-06-christmas-homepage-welcome/) |
| [reports/2026-11-06-christmas-homepage-popup-ui-fix.md](reports/2026-11-06-christmas-homepage-popup-ui-fix.md) | **Christmas homepage popup UI fix** — the welcome dialog now ships **closed** (no server-rendered `open`), opens only through `showModal()`, and every non-modal state is a compact fallback card instead of the broken inline band: the 286 px hero reservation is gone, dismissal leaves zero residue, and the signed preview exposes a hidden-until-wired **preview trigger** that never touches the visitor dismissal key. Root cause proven in Chromium before the fix (`state=inline`, `open` true, `:modal` false, hero 850 px vs 563 px); **132 + 88** in-process assertions, **18** Node cases (gate **74**), **501** real-browser checks at eight viewports incl. 1024×768/1440×900, PT + EN, no-JS, lint/registry/governance green. Evidence in [evidence/2026-11-06-christmas-homepage-popup-fix/](evidence/2026-11-06-christmas-homepage-popup-fix/) |
| [reports/2026-11-07-christmas-welcome-top-popup.md](reports/2026-11-07-christmas-welcome-top-popup.md) | **Christmas welcome top-of-screen popup** — the welcome now auto-opens as a real top-layer modal on the initial homepage view in BOTH the public homepage and the signed private preview (no click, no trigger), horizontally centred and pinned below the safe area, admin bar, preview notice and sticky header through one measured `--ce-xmas-welcome-obstruction-top` (re-measured on `load`/`fonts.ready`/`resize`, cleared on settle); the preview-only lighter backdrop keeps the notice readable, closing leaves state `preview`/`dismissed` with zero residue, and the dismissal stays session-scoped on the Christmas-only key. Root cause proven in Chromium before the fix (`state=preview`, fallback + trigger visible, dialog `y=218` of 768 = vertically centred); **145 + 88** in-process assertions (**440** focused total), **19** Node cases (gate **76**), **546** real-browser checks over the eight required viewports (320×568 → 1440×900 + 844×390 landscape) in PT-BR and English including the signed preview and no-JS, plus vacuity proofs (new tests × old code: node 2 / PHP 12 / browser 134 failures) and an empty full-harness failing-suite diff vs the `af91ed8` reference. Evidence in [evidence/2026-11-07-christmas-welcome-top-popup/](evidence/2026-11-07-christmas-welcome-top-popup/) |
| [reports/2026-11-06-commemorative-events-local-remediation.md](reports/2026-11-06-commemorative-events-local-remediation.md) | **Local remediation of the r10 final review (no production access).** Enforces H2 (Polylang meta-copy exclusion + read-only translation editor + server-side save guard + the new permanent gate `commemorative_translation_ownership` with injected negative proofs), corrects the H1 sitemap evidence semantics (the Jetpack index row is vacuous; production **NOT VERIFIED**), resolves the PHPCS pre-sniff drift, documents the landing-page asset exception (M2) and the deliberate `show_in_rest => false` deviation (M4), and records M5 as **NOT VERIFIED** |
| [reports/2026-11-05-leisure-location-production-migration-dry-run.md](reports/2026-11-05-leisure-location-production-migration-dry-run.md) | **Stage 8: production migration dry-run — PASS, read-only.** The existing script's dry-run reconciles exactly with the audit, record by record: **289** production records (289 distinct ids/slugs, 3 pages), **0** coordinates, **173** attribute assignments over **57** records; local **289** coordinates, **592** assignments; **289** slug matches, **0** production-only records. Plan: `populate=289, conflicts=0, unresolved=0, written=0, gate=0`, `attributes_planned=289`, **0 removals / 0 deletions**; superset property **289/289**. Read-only proven by the mode contract, a **58/58** mock ("ZERO write requests at all") and an identical before/after production sweep. Corrects plan gate 5 (289 attribute records, not 232). **0 production writes**; Stage 9 not authorised |
| [reports/2026-11-06-christmas-landing-page-active-dispatch-fix.md](reports/2026-11-06-christmas-landing-page-active-dispatch-fix.md) | **Christmas landing-page active-event dispatch fix** — `/natal/` and `/en/christmas/` render the full landing experience again while the Christmas event is active. Root cause: `conexao_commemorative_placement_context()` returned the event-driven copy of `landing_page` (`page_id = 0`) instead of the page-owned entry, so the shared landing shell returned early and the route stayed blank. Two `if` blocks reordered in one file; **+22 assertions** across the two existing commemorative suites (6 + 16 of them fail against the old precedence), **165 real-browser assertions** at 320×568 / 390×844 / 768×1024 / 1366×768 in the active state, and a live before/after capture. **No production, release, commit or push.** Evidence in [evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/](evidence/2026-11-06-christmas-landing-page-active-dispatch-fix/) |
| [importers/](importers/) | Event/Lazer/IVVCC/Mondello import pipeline reports and plans |
| [research/](research/) | Topic research notes (e.g. recruitment agencies, employment permits) |
| Root-level `*-report.md` files in `docs/` | Older single-stage feature reports (breadcrumb filter, Lazer cards, homepage hero, …) kept in place for citation stability |

## Testing

One command runs every maintained test — in-process PHP, the script-contract
gate and HTTP acceptance:

```bash
./scripts/run-tests.sh            # all layers
./scripts/run-tests.sh --scripts   # script-contract gate only
```

The **model** — layers, suite classification, permanent fail-closed gates, the
baseline/pre-existing-failure policy, the shared bootstrap and assertion library,
fixtures, the acceptance base URL and the CI contract — is
**[testing.md](testing.md)**; the normative policy is
[engineering-standard.md §8](engineering-standard.md). The **procedure** — what
to run, in what order, how to classify a failure and how to compare regressions —
is [`wp-testing`](../.agents/skills/wp-testing/SKILL.md).

## Templates

| Template | Use it for |
|---|---|
| [templates/plan.md](templates/plan.md) | Before multi-file work, and before any content, route or English/Polylang change |
| [templates/report.md](templates/report.md) | After the work, to close a stage with real verification numbers and stated limitations |

_Last verified: 2026-09-30 by the agent skills / documentation migration_

_Last verified: 2026-10-03 — added the UI / UX audit to the index_

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_

_Last verified: 2026-10-10 by UI polish Phase 3 — component-heading tokens, card-CTA 44px floor, single editorial measure_

_Last verified: 2026-10-27 by Halloween navigation continuity_
_Last verified: 2026-10-27 by Halloween witch animation_
_Last verified: 2026-10-27 by Halloween theme compatibility_
_Last verified: 2026-10-27 by Halloween mobile spontaneous appearances_
_Last verified: 2026-11-03 by the Lazer coordinate research and population task_
_Last verified: 2026-11-03 by the leisure-location cleanup and consolidation task_
_Last verified: 2026-11-04 by the Lazer proximity feature (the unindexed 2026-11-02 location-services report and the 2026-11-04 proximity-verification report added to the index)_
_Last verified: 2026-11-05 by the Lazer location production audit and migration preparation (the 2026-11-05 audit report and migration plan added to the index)_
_Last verified: 2026-11-05 by the Lazer coordinate REST permission fix (the 1.6.3 permission-fix report added to the index)_
_Last verified: 2026-11-05 by Stage 7 (the 2026-11-05 production REST permission verification report added to the index; blocked at its write checkpoint, 0 production writes)_
_Last verified: 2026-11-05 by Stage 8 (the 2026-11-05 production migration dry-run report added to the index; read-only, 0 production writes, migration not applied)_
_Last verified: 2026-11-05 by Stage 9 (the 2026-11-05 production migration report added to the index; 578 authorised production writes, independently verified, PASS)_
_Last verified: 2026-11-06 by the evidence directory audit and cleanup (the evidence rule now carries a complete folder index, the A–E retention policy, the commit prohibitions and the add-evidence procedure; the 2026-11-06 audit report added to the reports index)_

_Last verified: 2026-11-06 by the Christmas homepage popup UI fix (the report and evidence directory added to the index)_
_Last verified: 2026-11-07 by the Christmas welcome top-of-screen popup fix (the report and evidence directory added to the index)_
