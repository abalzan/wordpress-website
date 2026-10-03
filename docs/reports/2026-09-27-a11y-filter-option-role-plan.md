# Plan — Filter link ARIA semantics (remove invalid `role="option"` on filter `<a>`)

| | |
|---|---|
| **Task title** | Fix invalid accessibility roles on filter links (`role="option"` on `<a>`) |
| **Date** | 2026-09-27 |
| **Author / agent** | Codex agent |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `849624574451c1aa771f2fac0bd66b66fd2aadd8` |
| **Plan status** | approved |

## 1. Scope

Correct the accessibility semantics of the desktop filter dropdown option links
rendered by the three shared filter widgets — `leisure-filters.php` (/lazer/),
`event-filters.php` (/eventos/) and `employment-opportunities.php` (/empregos/).

Each option is a **real navigational hyperlink** (`<a href="…">`) that navigates
to a filtered URL. Today each one also carries `role="option"` + `aria-selected`,
inside a `role="listbox"` panel opened by an `aria-haspopup="listbox"` trigger.
`role="option"` overrides the native `link` role of an `<a>`, so these controls
announce as "option" instead of "link", and the widget claims a listbox
interaction contract (arrow-key roving focus, `Home`/`End`, single tab stop) that
the implementation never provides — the JS only toggles `aria-expanded`, and the
links stay in the normal Tab order.

The fix makes the markup describe what the widget actually is: a disclosure
button controlling a **group of hyperlinks**, where the active filter is
communicated with the native, already-used-in-this-theme `aria-current="true"`.

## 2. Explicit non-goals

Explicitly refused, so a reviewer does not read them as oversights:

- **No filter redesign.** No new component, no restyling, no restructure of the
  dropdown/panel/mobile-sheet hierarchy.
- **No URL, query-parameter or filter-semantics change.** Every `href` is
  produced by the existing helpers (`conexao_leisure_filter_url()`,
  `conexao_event_filter_url()`, `opportunity_filter_url()`) and is untouched.
- **No CSS change.** All `*-dropdown-link` / `*-dropdown-list` classes and the
  `is-active` state class are kept exactly. The visual state is driven by
  `.is-active` + the `✓` checkmark, never by `[role]`/`[aria-selected]`
  (verified: the only ARIA selectors in CSS are `[aria-expanded="true"]` and
  `[aria-disabled="true"]`).
- **No replacement of links by JS controls.** The options stay `<a href>`.
- **No new accessibility framework, no JS dependency.**
- **No taxonomy, Polylang, B1/B2, translation-engine or i18n change.**
- Not doing: the `Artigo` content-type label leak, homepage/SEO/schema work,
  leisure pagination/performance work, Jetpack sitemap/robots changes, any
  WordPress core behaviour change, any Flutter/mobile work, any deploy or
  production write.
- **Not touching the wp-admin town suggester** (`conexao-admin-ux`
  `class-fields.php` / `admin.js`, which pair `role="listbox"` with
  `role="option"` on `<div>`s). That is a genuine ARIA-autocomplete widget, not
  an `<a>`, and it is not part of the reported defect.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md` (§0 principles, §13.2 agent rules,
§14 definition of done), `docs/content-model.md`, `docs/routing.md` (§ filters,
B1/B2), `docs/releases.md`, `docs/testing.md`, `docs/themes/conexao-br-irlanda.md`
(the theme rows that currently *document* the listbox pattern and must be
corrected in the same change), `docs/templates/plan.md`,
`docs/templates/report.md`, `tests/acceptance/matrices/routing.json`.

## 4. Current-state findings

Verified by reading the code and by requesting the live local pages.

- `grep -rc 'role="option"'` over the theme returns exactly three files:
  `template-parts/leisure-filters.php` (6), `template-parts/event-filters.php`
  (6), `template-parts/employment-opportunities.php` (8) — **20 occurrences**.
- Their containers are `role="listbox"` (3 + 4 + 4 = 11) and the triggers
  `aria-haspopup="listbox"` (3 + 3 + 4 = 10). `leisure-filters.php` adds
  `aria-multiselectable="true"` on 2 of its 3 listboxes.
- **No JavaScript depends on any of it.** `grep -rn 'aria-selected|role=.option.|listbox'`
  over the theme and plugin assets matches only
  `wp-content/plugins/conexao-admin-ux/assets/admin.js:125` (the unrelated
  admin town suggester, a `<div>`). `assets/js/main.js` never reads a role or
  `aria-selected`; it only toggles `aria-expanded`/`aria-hidden` and `hidden`.
- **No CSS depends on it.** The only attribute selectors in the theme CSS are
  `[aria-expanded="true"]` and `[aria-disabled="true"]`.
- Surrounding structure: the options live in a plain
  `<div class="…-dropdown-list">` inside `<div class="…-dropdown-panel">` —
  **not** a `<ul>`, `<nav>`, `<select>` or any native widget. The parent
  genuinely does **not** require `option` children; `role="listbox"` is the only
  reason `role="option"` is there.
- The controls are normal navigation: each `href` is a real filtered URL, they
  are shareable, refresh-safe and back/forward-safe; the mobile counterpart of
  the very same filter already uses **native** `type="radio"` /
  `type="checkbox"` inputs in a `role="dialog"` sheet, which is why the desktop
  listbox is the outlier.
- **The repository already has the correct convention.**
  `template-parts/guide-filters.php:38,47` and
  `template-parts/course-filters.php:52,61` render the same kind of filter as
  plain `<a class="events-filter-link …" href="…">` with the active one carrying
  `aria-current="true"` — no `role`, no listbox. This change aligns the three
  widgets with that existing, in-repo convention.
- Live before-state captured in `docs/evidence/…/before/`: on the current local
  dataset `/empregos/` renders 4 `role="option"` links and 1 `role="listbox"`;
  `/lazer/` and `/eventos/` render **0** of each, because the local DB has no
  published leisure records and only one non-taxonomised event, so those two
  widgets correctly return early. This is a **local data** condition, not a
  defect, and it is why the HTTP layer alone cannot prove the leisure/events
  half of the fix — the in-process template tests must cover it.
- Baseline `./scripts/run-tests.sh` recorded in
  `docs/evidence/2026-09-27-a11y-filter-option-role/baseline-run-tests.txt`
  before any edit, so pre-existing failures can be separated from new ones.

## 5. Proposed approach

**Chosen: demote the desktop filter dropdowns from a fake listbox to a
disclosure + group-of-links, and express the active filter with `aria-current`.**

Per option link (`<a>`), in all three templates:

- remove `role="option"` → the element keeps its **native link** role;
- replace `aria-selected="true|false"` with `aria-current="true"` emitted **only
  when the option is active** (the exact pattern already used by
  `guide-filters.php` / `course-filters.php`). `aria-selected` is not a valid
  attribute on `role="link"` and is dropped rather than left behind as an
  orphan; the active/inactive distinction survives through `aria-current`, the
  unchanged `is-active` class and the unchanged `✓` checkmark.

Per listbox container (`<div class="…-dropdown-list">`):

- `role="listbox"` → removed, and `aria-multiselectable="true"` removed with it
  (it is only valid on a listbox, and would itself become an orphan). The
  `aria-label` ("Condado", "Tipo", "Características", "County", "Cidade",
  "Categoria", "Tipo de oportunidade", "Área de trabalho", "Localização",
  "Tipo de contrato") is **kept** so the group is still named for assistive
  technology.

Per trigger `<button>`:

- `aria-haspopup="listbox"` → removed. `aria-expanded` + `aria-controls` already
  express the disclosure relationship correctly and are untouched, and JS keys
  off `aria-expanded` for both the visual state and the CSS.

Result: the widget keeps its exact DOM shape, classes, URLs, JS hooks
(`data-dropdown*`, `data-option-*`) and behaviour; only the four incorrect ARIA
declarations change. Every option remains a keyboard-reachable link in the
normal Tab order (which is what the widget actually implemented), and now
announces as a link with "current" marking the active filter.

### Options considered and rejected

| Option | Why rejected |
|---|---|
| Keep `role="listbox"`, change `<a>` → `<div role="option">` + JS navigation | Breaks the hard constraint against replacing links with JS controls, destroys the real shareable `href`/back-forward guarantee, and requires a roving-tabindex keyboard model that does not exist. |
| Keep `role="listbox"` + `role="option"` and *implement* the full APG listbox keyboard contract (arrow keys, `Home`/`End`, single tab stop) | A behavioural redesign of the filter widget, explicitly out of scope; also contradicts the mobile sheet, which already uses native radio/checkbox. |
| Replace the whole widget with a native `<select>` | Filter redesign; loses the multi-select URL-toggling model and the visual design. |
| `aria-pressed` toggle buttons | Still not a link; loses navigation semantics and the "open in a new tab" affordance a real `href` gives. |
| Keep `aria-selected` on the link without `role="option"` | `aria-selected` is not supported on `role="link"`; leaves an invalid orphan attribute and an active state screen readers may not announce. |
| Demote to `role="menu"`/`role="menuitem"` | Still a widget contract (arrow-key menu navigation) the JS does not implement, and menu items that navigate are better expressed as plain links. |

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-filters.php` | remove `role="option"` (6), `role="listbox"` (3), `aria-multiselectable` (2), `aria-haspopup="listbox"` (3); add `aria-current="true"`; update the file docblock | the defect |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-filters.php` | same for 6 options / 4 listboxes / 3 triggers; docblock | the defect |
| `wp-content/themes/conexao-br-irlanda/template-parts/employment-opportunities.php` | same for 8 options / 4 listboxes / 4 triggers | the defect |
| `wp-content/themes/conexao-br-irlanda/tests/test-filter-link-aria-semantics.php` | **new** focused regression suite | Phase 6 |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-multiselect-filters.php` | update the assertions that encode `aria-selected` / `aria-multiselectable` to the new semantics | existing suite asserts the old, now-incorrect markup |
| `wp-content/themes/conexao-br-irlanda/tests/test-event-location-filters.php` | update the `role="listbox"` + `aria-selected` assertion | same |
| `tests/acceptance/matrices/routing.json` | add `expect_absent` rows proving `role="option"` is gone on the live filter pages | §14: request-visible change needs matrix rows |
| `docs/themes/conexao-br-irlanda.md` | correct the three rows that document the "listbox pattern" | engineering standard: docs must not disagree with the code |
| `docs/reports/2026-09-27-a11y-filter-option-role*.md` | plan + report | standard |
| `docs/evidence/2026-09-27-a11y-filter-option-role/` | before/after HTML, test output, a11y tree, gates | standard |

## 7. Files explicitly expected NOT to change

`wp-content/plugins/**` (no plugin code is implicated — the admin town suggester
is a valid autocomplete and out of scope), `plugins.json`, `functions.php` and
`inc/**` (no helper or data layer changes), `assets/css/**` (no visual change),
`assets/js/main.js` (no JS depends on the removed attributes), `.htaccess`,
`dist/**`, anything under the Flutter/mobile repository, and every content,
Polylang or taxonomy surface.

## 8. Content / data impact

- Records created / updated / deleted: **none** — confirmed by `git status`; the
  change is template-only.
- This task writes **no** content, so the six-step content-change contract
  (§5.2) does not apply.
- **PT content is not touched.** Not a single translated string is added,
  changed or removed, so the `.pot` catalogue is unaffected and PT identity,
  slugs and URLs are untouched.

## 9. Route / HTTP impact

- Routes added / removed / changed: **none**. No `href` is regenerated.
- Redirects needed: **none**; redirect precedence untouched.
- Sitemap coverage: unchanged.
- HTTP matrix rows: add rows asserting the filter pages still serve 200, still
  contain their filter controls, and now **do not** contain `role="option"`.
  Because the local dataset renders no leisure/event terms, the *absence* rows
  are anchored on `/empregos/`, which does render; the leisure/events half is
  covered by the in-process template suite (documented as a limitation).

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- PT immutable: **yes** — no content, meta, term or translation change.
- B1 / B2: unchanged. `/en/empregos/` keeps its documented **B1** 302 →
  `/empregos/`; `/en/lazer/` and `/en/eventos/` keep serving their real EN
  archives.
- Canonical / hreflang / language switcher: untouched.
- Language-scoped cache keys: untouched (no query or data change).
- Completeness gate: unaffected by this change; any violations are pre-existing
  and recorded as such.

## 11. Security impact

- Capabilities: unchanged (template-only, no new entry point).
- Nonces: none added or changed.
- Sanitisation / escaping: **unchanged** — every `href` keeps its existing
  `esc_url()` and every label its `esc_html()`/`esc_attr()`. No new user input
  is read or echoed; `$_GET` handling is untouched.
- SQL: none.
- REST surface: none.
- Secrets: none.

## 12. Performance impact

- Queries added or removed: **none**. The templates execute the same queries in
  the same order; only attribute strings in the markup change.
- Caching: untouched — no transient key changes.
- Assets/images: none.
- Expected measurable improvement: none claimed. This is explicitly **not** a
  performance refactor; the only delta is a few bytes of attributes per option.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a — nothing is deployed from this task |
| What is the verification of the production result? | n/a — local verification only; no production action is authorised |

## 14. Test plan

- New in-process suite `test-filter-link-aria-semantics.php` covering all three
  templates: options are still `<a>` with the expected `href`s; the expected
  filter controls still render; `role="option"`, `role="listbox"`,
  `aria-selected`, `aria-multiselectable` and `aria-haspopup="listbox"` are all
  **absent**; the active option carries `aria-current="true"` while inactive
  ones do not; filter URLs and query parameters are byte-identical to the
  baseline; the PT/EN variants of the URL helpers still produce `/en/…` targets.
- Update `test-leisure-multiselect-filters.php` and
  `test-event-location-filters.php`, which currently assert the old markup.
- Acceptance rows in `routing.json` for the six required URLs plus a filtered URL.
- Script-contract: unaffected (no `scripts/` change).
- A pass is: 0 failed in-process suites beyond the recorded pre-existing set,
  0 new failed assertions, 0 failed acceptance assertions, 0 new permanent-gate
  violations.

## 15. Acceptance matrix plan

Rows added to `tests/acceptance/matrices/routing.json` (schema
`id`,`url`,`expect_status`,`expect_contains`,`expect_absent`,`note`):

1. `a11y-filter-links-no-option-role` — `/empregos/`, 200,
   contains `class="agency-filters-dropdown-link"`,
   absent `role="option"`, `role="listbox"`, `aria-selected=`,
   `aria-haspopup="listbox"`.
2. `a11y-lazer-filter-links-no-option-role` — `/lazer/` + `/en/lazer/`, 200,
   absent `role="option"` (valid even when the widget renders 0 options locally).
3. `a11y-eventos-filter-links-no-option-role` — `/eventos/` + `/en/eventos/`,
   200, absent `role="option"`.
4. `a11y-empregos-filter-urls-unchanged` — `/empregos/?tipo=agency`, 200,
   contains the `?tipo=` link family and `aria-current="true"`, absent
   `role="option"`.

Expected new assertion count: one extra `expect_absent` group per row as
listed; the exact totals are reported from the actual run.

## 16. Rollback plan

Pure template attribute change, no data, no migration. Rollback is
`git checkout -- <the three templates> <the two test files> routing.json
docs/themes/conexao-br-irlanda.md` (or revert the single commit). Nothing to
snapshot beyond the captured before-state HTML and the baseline test log, both

of which are in `docs/evidence/2026-09-27-a11y-filter-option-role/`. Rollback
does **not** need to cover any content, because no content changes.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | the three filter-widget rows currently document the "listbox pattern"; correct them to "disclosure + group of hyperlinks with `aria-current`", and update the `_Last verified_` line |
| `docs/routing.md` | no behavioural change → no edit expected (re-check the filter paragraph) |
| `docs/reports/…-plan.md`, `docs/reports/…-report.md` | this plan + the final report |

## 18. Release implications

- Theme version bump: **no**. Behaviour-neutral markup correction; the task does
  not authorise a release.
- Artifact allowlist: unaffected (no `plugins.json` change).
- A release is **not** in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Removing `aria-haspopup` weakens the trigger's relationship to its panel | medium | `aria-expanded` + `aria-controls` is the canonical disclosure pairing and is untouched; verified with a real browser accessibility tree |
| An existing test encoded the old markup and now fails | **certain** | identified in Phase 3 (`test-leisure-multiselect-filters.php`, `test-event-location-filters.php`); updated deliberately in the same change, with the new semantics asserted |
| Local dataset renders no leisure/event options, so HTTP alone cannot prove those two widgets | **certain** | in-process template tests render the templates directly; stated as a limitation in the report |
| Losing the multi-select announcement (`aria-multiselectable`) | medium | the multi-select behaviour is already announced through the trigger `aria-label` ("N selecionados") and the active-filter chip row, both untouched |
| **Scope creep** into redesigning the filter widget or its CSS | low | non-goals listed in §2; CSS and JS are explicitly out of the changed-file list |

## 20. Verification gates

1. `./scripts/run-tests.sh` — full aggregate; failing suites/assertions compared
   against the recorded baseline, and the diff of the failing lists must be empty.
2. `php -l` on every changed PHP file; `./scripts/lint.sh` (PHPCS + PHPStan) with
   the exact numbers recorded, or an explicit statement of why a tool could not run.
3. `python3 scripts/verify-permanent-gates.py` — run **alone**, after every
   mutating suite has stopped, so no concurrent DB mutation can inflate the
   violation counts; violations split into pre-existing vs new.
4. Playwright/Chromium: real computed accessibility tree on the filter controls
   (role is `link`, not `option`), keyboard reachability, activation, active
   state, focus visibility, console errors, before/after screenshots desktop +
   mobile.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14 and the task's own acceptance list:
invalid `role="option"` removed **and** the surrounding relationship correctly
semantic; PT and EN leisure/events/employment filters still work; filter URLs and
query parameters unchanged; active state still accessible; keyboard navigation
works; no new JS errors; no visual regression; no PT content, Polylang, B1/B2 or
taxonomy change; no new translation lifecycle or database record; focused tests
and HTTP acceptance pass with no new failures; permanent gates still fail-closed
and verified on a stable, non-mutating database; working tree contains only
intentional changes; evidence and report complete.

_Last verified: 2026-09-27 by the filter-link ARIA semantics task_

of which are in `docs/evidence/2026-09-27-a11y-filter-option-role/`. Rollback
does **not** need to cover any content, because no content changes.
