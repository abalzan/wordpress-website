# Report — Commemorative events: documented standard + reusable agent skill

| | |
|---|---|
| **Stage / task name** | Document the commemorative-events standard and add a reusable event-creation skill |
| **Date** | 2026-11-08 |
| **Author / agent** | Cline (coding agent), on request of the repository maintainer |
| **Branch** | `commemorative-events` |
| **Start SHA** | `d7f7ca526e8ca508026964d9c5e6a6d19eaa3463` |
| **Final SHA** | `d7f7ca526e8ca508026964d9c5e6a6d19eaa3463` (no commit, no push — not authorised) |
| **Working tree at finish** | dirty: the edited documentation files, the new skill directory and this report's evidence directory. **No `wp-content/` file touched** |
| **Skills followed** | `wp-update-docs` (where the change belongs, drift prevention), `wp-repository` (plan → build → verify → report, scope control) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §9/§13, `docs/commemorative-events.md`, `docs/plugins/conexao-commemorative.md`, `docs/commemorative-content.md`, `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md`, `docs/testing.md`, `docs/README.md`, `.agents/skills/README.md`, `plugins.json` |

## 1. Scope completed

A documentation-and-skills change. **No event was implemented and no behaviour
changed.**

1. **`AGENTS.md` — the `## Commemorative events` section rewritten** (not
   deleted, not duplicated). It is a routing-and-guardrail section: it points at
   the `wp-commemorative-event` skill and `docs/commemorative-events.md`,
   requires the Halloween/Christmas reports to be read first (as history, not
   instruction), summarises the architecture invariants and critical foot-guns,
   and states that event work ships tests, a dated report and a before/after git
   state — and that local green tests never prove release or production
   readiness. A `wp-commemorative-event` row was also added to the
   task → skill → docs table.
2. **New skill `.agents/skills/wp-commemorative-event/SKILL.md`** —
   *Commemorative Event Creation*. It carries the repository's standard skill
   format (the twelve sections, in order) and folds the two extra blocks into
   them, so there is exactly one checklist and one lessons list:
   - **Steps** run the seven phases the request specified: understand/plan →
     architecture mapping → assets and creative experience → implementation →
     tests and verification → review and report → git and production safeguards.
   - **Guardrails** carry the engine/theme separation, privacy, Polylang
     ownership, preview, modal, dispatch, no-arbitrary-mechanism, reduced-motion,
     no-JS and scope rules, plus a `### Lessons that must not be repeated
     (Halloween, Christmas)` subsection recording the real defects: the
     `<dialog open>` non-modal band, preview writing the dismissal key, the empty
     `<main>` landing-dispatch regression, the evaluation-row value-object bug,
     the cadence regression, the forbidden second ambient scheduler, public
     activation, and the zero-bytes-without-an-event invariant.
   - **Definition of done is the per-event checklist**: it names
     `docs/templates/plan.md` and `docs/templates/report.md` as the artefacts to
     copy it into, and explicitly refuses to restate the seven-field package
     brief (`docs/commemorative-content.md`) or the plugin facts
     (`plugins.json`) — no second source of truth.
3. **Indexes updated** — `.agents/skills/README.md` (skill row + `_Last
   verified_`), `docs/README.md` (skill row, count 14 → 15, report row, `_Last
   verified_`) and `docs/evidence/README.md` (the date row for this folder, which
   also fixes the pre-existing omission of `christmas-micro-animations`).
4. **Report and evidence** — this document plus
   `docs/evidence/2026-11-08-commemorative-event-skill/`.
5. **Hardening review pass (same request, same day).** The completed skill was
   then reviewed against the repository's authoritative sources and corrected in
   place — structure, lessons, commands and safety boundaries preserved:
   - **(A) Registry responsibilities made explicit.** `plugins.json` is labelled
     plugin-level facts only; package registration, package-to-event mapping,
     placements and route kinds are attributed to the theme presentation registry
     (`docs/commemorative-events.md` +
     `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php`);
     the engine contract owns event record/schedule/selection/resolution/
     eligibility; `docs/commemorative-content.md` owns brief/calendar/watchlist.
     The old checklist line that implied `plugins.json` also covered event/package
     mapping was replaced.
   - **(B) Seven-field brief and calendar workflow made explicit.** Phase 1 now
     requires completing/updating the canonical seven-field brief with the
     approved copy/artwork/user decisions, recording the event in the content
     calendar, checking the manual overlap watchlist, and capturing schedule/
     timezone (`wp_timezone()`)/occurrence-year/lead-in/priority and manual annual
     precedence (`_ce_year_precedence`) details under the engine contract; the
     checklist gained matching, verifiable items.
   - **(C) Plan/report template handling fixed.** The skill now copies checklist
     items into a task-specific plan (`docs/plans/<date>-<topic>.md`) and report
     (`docs/reports/<date>-<stage>-<topic>.md`), never into the canonical
     templates, which change only on explicit request or documented process.
   - **(D) Preview/dismissal rules made contract-driven.** Preview follows the
     signed, capability/nonce-gated engine contract that persists nothing;
     auto-open/close/fallback/dismissal follow the current shared controller
     contract and the approved brief; the Christmas key name and storage duration
     are labelled Christmas-specific, and auto-opening popups must be tested as
     real `showModal()` dialogs with a usable fallback and no leftover inline card.
   - **(E) Historical cadence separated from reusable standards.** The Halloween
     navigation cadence is labelled a Halloween-specific approved requirement
     (plan §1/§5, asserted by `test-seasonal-halloween-nav-effects.php`); a
     general rule now requires every cadence from the approved brief, the existing
     shared ambient scheduler, no second scheduler/system/timer, and reduced-motion
     plus non-blocking motion.
   - **(F) Package examples marked illustrative.** `halloween`/`christmas` are
     examples to inspect, not folders to copy; carrying another event's schedule,
     storage key, modal state machine, copy, visuals, cadence, route assumptions
     or asset-loading behaviour requires validation against this event first.
   - **(G) Tests scoped to the change.** The `verify-christmas-*` scripts are kept
     as the regression net for shared controllers/dispatch/modal/animation and
     Christmas itself; equivalent event-specific checks are required for other
     events; Christmas-only tests are never claimed as proof for another event;
     shared invariant gates remain mandatory; missing/renamed commands must be
     taken from `docs/testing.md`/`scripts/README.md`.
   - **(H) Local environment startup made deliberate.** Inspect first
     (`docker compose ps`, `docs/development.md`), reuse a running stack, no
     automatic start/recreate/rebuild, volume reset, seeding or admin-session
     minting; report blockers, use non-mutating checks, and preserve the
     production-host refusal of every verifier.
   - **(I) Format and discoverability verified.** Twelve standard sections in
     order (governance note), title format correct, no frontmatter (none is used
     by any skill in this repository), and an `AGENTS.md` top skill-table row was
     added so the skill is discoverable from all three routes (top table,
     task→skill table, `## Commemorative events`).
   - **(J) Safety/scope boundaries preserved.** Every safety rule kept; the only
     guardrail merge was two duplicate production-write bullets.

### What the worktree already contained

At start the worktree was **not** clean: `AGENTS.md` and
`.agents/skills/README.md` were modified and an uncommitted
`.agents/skills/wp-commemorative-event/SKILL.md` draft existed — a partial,
earlier run of this same request. It was **preserved, verified and completed**,
never reverted:

- its `AGENTS.md` edit pushed the file to **250 lines**, which the
  `documentation_drift` gate rejects (`AGENTS.md` must stay `< 250`); the section
  is 22 lines and the file now measures **248** lines (`wc -l`) / **249** (gate)
  after the hardening pass added the top skill-table row;
- its skill draft carried two `##` sections after `Definition of done` and a
  checklist that duplicated the definition of done; both were folded into the
  standard sections;
- its claims were checked against the code and the reports (§9) and the wrong
  ones corrected: the "documentation-only or presentation-only" precondition (an
  event task is neither) and the unlinked landing-dispatch reference.

## 2. Scope NOT completed

- **No event was created, enhanced or previewed.** Explicitly out of scope.
- **No `wp i18n make-pot` regeneration.** The `i18n_freshness` failures in §9 are
  pre-existing and unrelated (§12); regenerating catalogues is a runtime artefact
  change this documentation task is not authorised to make.
- **No browser or HTTP run of the commemorative routes.** The skill documents how
  to run that matrix; this task changed no route, template or asset, so running it
  could not observe a behaviour change.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `AGENTS.md` | modified | `## Commemorative events` section rewritten as routing + guardrails; skill row added to the task → skill → docs table; top skill-table row added by the hardening pass (248 lines `wc -l`, 249 by the gate — both under the 250 limit) |
| `.agents/skills/wp-commemorative-event/SKILL.md` | added (pre-existing draft completed, then hardened in the same request) | The reusable event-creation workflow |
| `.agents/skills/README.md` | modified (pre-existing edit, verified) | Skill index row + `_Last verified_` |
| `docs/README.md` | modified | Skill row, `14 → 15` skills, report row, `_Last verified_` |
| `docs/evidence/README.md` | modified | Evidence date row (also indexes the previously-omitted `christmas-micro-animations` folder) |
| `docs/reports/2026-11-08-commemorative-event-skill.md` | added | This report |
| `docs/evidence/2026-11-08-commemorative-event-skill/` | added | 7 evidence files + `README.md` |

Counts: **1 skill directory added, 2 artefacts added (report, evidence folder),
4 files modified**. **`wp-content/` diff: empty.**

## 4. Runtime impact

**None — behaviour-neutral.** No file under `wp-content/` changed
(`git status --porcelain` lists only `AGENTS.md`, `.agents/skills/` and `docs/`),
no plugin or theme PHP/CSS/JS was edited, no registry entry changed
(`plugins.json` untouched; `generate-registry-docs.php --check` reports 20
generated regions current with zero writes), and no hook, route, option, meta key
or template was added.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed — none attempted |
| No production content, Polylang or database change | confirmed — no content command was issued |
| No application behaviour change (`wp-content/` diff is empty) | confirmed |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |

## 5. Content / data impact

**None.** No record, term, meta value or attachment was created, updated or
deleted; no importer, migration, seed or repair ran. `plugins.json` is unchanged,
so the plugin registry is unchanged.

## 6. Polylang impact

**None.** The change documents the existing single-owner rule (shared `_ce_*`
configuration on the canonical PT record only; language-specific text stays
translated) and touches no Polylang setting, translation record or completeness
gate.

## 7. Route / HTTP impact

**None.** No route, template, redirect, canonical, hreflang or sitemap producer
changed. The skill documents the current contract and the landing-dispatch
precedence rule the Christmas fix established; it alters neither.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | no production host contacted, no credential used |
| Deploy / upload | **no** | no build, manifest or deploy ran |
| Plugin activation | **no** | `plugins.json` and the active plugin set unchanged |
| Content or DB mutation | **no** | no content command was issued |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database), so
anything not operable in wp-admin is a documented limitation, not a silent gap.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3 tests/scripts/verify-agent-governance.py` | 0 | **372 passed, 0 failed** — skill format/order, index, dangling-reference and single-source-of-truth checks all green |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | **14 passed, 0 failed**; `AGENTS.md` measured at **249** lines (gate; 248 by `wc -l`), 15 living documents with `_Last verified_`, 0 root-level report offenders |
| `php scripts/generate-registry-docs.php --check` | 0 | `registry OK: 11 plugins validated, 20 generated regions current (zero writes)` |
| `./scripts/run-tests.sh --scripts` | 1 | **21 script-contract suites, 19 passed, 2 failed** — both failures pre-existing (§12), identical to the pre-hardening run |
| link/path check (this task) | 0 | 169 markdown link targets across the seven edited/added files, **0 dangling**; 16 backticked repository paths in the skill, **0 missing** |
| `git status --porcelain \| grep -c wp-content` | 0 → 1 | **0 files** under `wp-content/` changed |

### Numeric test results

In-process PHP: **not run** (0 suites, by design — no runtime file changed; the
full runner reports `In-process PHP suites: 0 total` in `--scripts` mode).

### HTTP acceptance

**Not run** — no route, template, asset or engine file changed, so no observable
behaviour exists to assert.

### Static analysis

PHPCS/PHPStan **not run**: the change contains no PHP. `./scripts/lint.sh` lints
PHP only; running it could not observe a documentation change.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **21 suites, 19 passed, 2 failed** —
`verify-i18n-freshness.py` and `verify-stage8-release-consistency.py` (§12).

### Release / build results

**Not in scope.**

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| The agent-governance gate **did fail** on the pre-existing draft `AGENTS.md` (250 lines) | `Stage K governance verification: 371 passed, 1 failed`, exit 1, message `AGENTS.md is 250 lines; it must stay a short orientation document`. The gate was right: the section was compressed to 22 lines and the file now measures 249 (gate) / 248 (`wc -l`) |
| The same gate rejected the draft skill's heading order/duplication before it was restructured | the gate's `note: skill sections:` line listed the two trailing `##` sections; after folding them into `Guardrails` and `Definition of done` the note lists exactly the twelve standard headings |
| The dangling-reference check covers the new skill | the gate reports `note: every repository path referenced by an active skill exists`; the same check would have failed on any invented path in the rewritten skill |

## 11. Regression comparison

| | Before (start of task) | After |
|---|---|---|
| Failing in-process suites | not run (no runtime change) | not run |
| Failing assertion count | n/a | n/a |
| Failing script-contract suites | 2 (`i18n_freshness`, `stage8_release_consistency`) | 2 (**the same two**) |
| Failing acceptance assertions | not run | not run |

The diff of the failing lists is **empty**. Both failures are input-data
conditions of the local checkout (§12), not consequences of this change: the
`wp-content/` diff is empty, which is the definitive proof that neither gate's
inputs were touched.

## 12. Known pre-existing failures

| Failure | What it is | Why it is not this change |
|---|---|---|
| `verify-i18n-freshness.py`: `conexao-br-irlanda`, `conexao-admin-ux`, `conexao-commemorative` `.pot` files older than their sources | catalogue staleness from earlier local work; the remedy is `wp i18n make-pot` (a runtime artefact) | it reads `.pot` files and PHP sources; **no `wp-content/` file changed** in this task and no `.pot` was touched |
| `verify-stage8-release-consistency.py`: `conexao-data-model` header `1.7.0` vs `dist/release.json` `1.6.3`; `conexao-admin-ux` header `1.0.9` vs release `1.0.8` | a stale local `dist/release.json` from an earlier build | it compares plugin headers with `dist/`; neither was touched here, and `dist/` is git-ignored local state |

Out of scope for this documentation task; recorded so the next agent does not
mistake them for regressions.



## 13. Limitations

- **No browser or device matrix run.** Nothing rendered changed; the skill
  documents the matrix (320 px up, PT/EN, no-JS, reduced motion, Chromium and
  WebKit) for the *next* event task instead. WebKit/Safari is therefore untested
  here by design, not by failure.
- **No in-process PHP or HTTP acceptance run.** No runtime file changed; the
  aggregate script layer itself reports `In-process PHP suites: 0 total` in
  `--scripts` mode.
- **The skill's guidance is prose.** Its structure and references are machine-
  checked (governance + drift + link/path gates); whether a future agent follows
  it cannot be verified by a test.
- **Two pre-existing script-contract failures remain** (§12); they are not fixed
  here because doing so would mean regenerating catalogues and a release record —
  runtime artefacts this documentation task is not authorised to change.

## 14. Evidence paths

`docs/evidence/2026-11-08-commemorative-event-skill/`:

| File | Proves |
|---|---|
| `verify-agent-governance.txt` | 372 passed, 0 failed — skill format/order, index, dangling references, `AGENTS.md` under the limit |
| `verify-documentation-drift.txt` | 14 passed, 0 failed; `AGENTS.md` 249 lines (gate); `_Last verified_` markers present |
| `registry-check.txt` | 11 plugins, 20 generated regions current, zero writes |
| `git-state-before.txt` | branch, HEAD, the three pre-existing worktree entries, 9 stashes |
| `git-state-after.txt` | identical branch, HEAD and 9 stashes after the edits (refreshed after the hardening pass) |
| `scripts-gate.txt` | the full `--scripts` run: 21 suites, 19 passed, 2 pre-existing failures (identical failing list before and after hardening) |
| `link-check.txt` | 169 link targets, 0 dangling; 16 skill path references, 0 missing |

## 15. Documentation updated

| Document | What changed | `_Last verified_` |
|---|---|---|
| `AGENTS.md` | `## Commemorative events` routes to the skill, states the foot-guns, requires the reports/tests/report/git-state discipline and forbids claiming production readiness from local green tests; top skill-table row added by the hardening pass | pre-existing orientation `_Last verified_` entries retained |
| `.agents/skills/wp-commemorative-event/SKILL.md` | new skill (Commemorative Event Creation), then hardened in the same request: registry split, brief/calendar/watchlist workflow, template-as-template handling, contract-driven preview rules, brief-driven cadence rules, event-scoped tests, deliberate local startup | n/a — skills carry no marker |
| `.agents/skills/README.md` | index row | added — `2026-11-08 by the commemorative-event skill addition` |
| `docs/README.md` | skill row, `14 → 15`, report row | added — `2026-11-08 by the commemorative-event skill` |
| `docs/evidence/README.md` | evidence date row (plus the missing `christmas-micro-animations` entry) | table row only |

**No procedure was moved out of a canonical document.** The "how to add an event /
package / landing page" runbook stays canonical in `docs/commemorative-events.md`
§5; the skill orchestrates around it and links to it rather than restating it.
That is deliberate: a second copy of the runbook would be a second source of
truth.

## 16. Rollback / recovery

- Delete the added files: `.agents/skills/wp-commemorative-event/`,
  `docs/reports/2026-11-08-commemorative-event-skill.md`,
  `docs/evidence/2026-11-08-commemorative-event-skill/`.
- Revert the modified files: `AGENTS.md`, `docs/README.md`,
  `.agents/skills/README.md`, `docs/evidence/README.md`.

**Caution:** `AGENTS.md` and `.agents/skills/README.md` were *already modified*
before this task began (a partial earlier run of the same request). A blanket
`git checkout --` on them discards that earlier work as well as this completion.
Rollback does not cover, and must not touch, the 9 stashes or any unrelated
worktree change.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Every gate that applies to a documentation-and-skills change passed with real
numbers (governance 372/0, documentation drift 14/0, registry 20 regions/0
writes, 169 links/0 dangling), and the only red results in the workspace are two
pre-existing script-contract failures whose inputs no file in this change touches
— but the aggregate script layer is not fully green and no runtime or browser
layer was exercised, so this is not reported as an unqualified `PASS`.

_Last verified: 2026-11-08 by the commemorative-event skill task_
