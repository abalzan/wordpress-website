# Plan — UI/UX Improvement 1: Simplify Event Date Badges

| | |
|---|---|
| **Task title** | Simplify event-card date badges on `/eventos/` and `/en/eventos/` (audit T1.9) |
| **Date** | 2026-10-19 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `6588c78` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf` |
| **Plan status** | draft |

## 1. Scope

Implement the first prioritized improvement from `docs/audit/2026-10-19-ui-ux-audit-live-site.md`: simplify the event-card date presentation on `/eventos/` and `/en/eventos/`. Presentation-only, inside `template-parts/event-card.php`, `assets/css/main.css`, `assets/css/dark-mode.css`:

1. **Compact date badge**: day number + localized abbreviated month only (e.g. `19 OUT`). Remove weekday ("SEG"), "Hoje"/"Amanhã" hint, recurrence label, series-end chip ("até 26 OUT") and cross-month range chip from the badge.
2. **One compact date/time metadata line**: single line in card details, e.g. `19 Out · 19:00 — 20:00`, at existing metadata size (`--text-sm`), reusing `event-card-time`.
3. **Location line**: preserved unchanged (T1.8).

Badge stays `aria-hidden="true"`; the accessible full date stays in `.event-card-date-iso.screen-reader-text <time>`. This resolves the T1.9 blocker (see §4).

## 2. Explicit non-goals

- No change to event queries, eligibility, ordering, filtering, pagination, caching, timezone conversion, recurrence calculations, or multi-day handling. Runtime untouched.
- No content/translation data writes. PT content immutable.
- No new component framework, no decorative elements, no unrelated spacing.
- No production writes/deploys. Local Docker only.
- No Flutter/mobile repository access. Not a redesign (banner, title, category, excerpt, CTA, actions untouched).

## 3. Relevant authoritative documents

- `AGENTS.md` — scope, safety rules, plugins.json authority, Flutter freeze.
- `docs/engineering-standard.md` — mandatory standard (§5.2, §8 tests, §9 docs, §13.2 plan).
- `docs/audit/2026-10-19-ui-ux-audit-live-site.md` — source audit finding (T1.9).
- `docs/reports/2026-10-03-ui-convergence-master-report.md` §14 — why T1.9 was blocked.
- `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` — design tokens/theme contract.
- `docs/content-model.md` — event meta fields. `docs/routing.md` — `/eventos/` and `/en/eventos/` routes.
- `plugins.json` — authoritative plugin registry (no plugin changes here).

## 3a. Skill workflow

Follows `wp-repository` orientation plus `wp-frontend-perf` presentation patterns (token-based CSS, no new framework). Verification: `./scripts/run-tests.sh`, `./scripts/lint.sh`, focused PHP and HTTP acceptance checks. Evidence: `docs/evidence/2026-10-19-event-date-badge/`.

## 4. Current-state findings

Badge (aria-hidden) contains day, month, weekday, recurrence label, cross-month range, series-end chip, today/tomorrow hint — four+ treatments for one date. A separate `.event-card-date-iso.screen-reader-text <time datetime>` node carries the full accessible date sentence. Details render a clock row with `event-card-time` when `_event_time` is set, then merged location row (T1.8). Badge month/weekday maps are hardcoded Portuguese (`JAN–DEZ`, `SEG`) regardless of language; on `/en/eventos/` badge shows "OUT"/"SEG" while recurrence label and hint are translated — pre-existing localization gap. Local stack returns 200 for `/eventos/` and `/en/eventos/`; site date is 2026-10-19.

**T1.9 blocker (verified, master report §14):** badge is `aria-hidden="true"` decorative markup alongside a separate screen-reader `<time datetime>` node; collapsing treatments risks degrading the accessible date. Resolution: badge purely decorative (day+month only), `<time>` node intact, recurrence chips moved to the visible metadata line.

**Test constraints:** `verify-event-occurrence-http.py` needs `event-card-recurrence`, `event-card-recurrence-end`, `event-card-time` chips present; `test-ui-convergence-t1.php`/`t2.php` pin template source and heading level.

## 5. Proposed approach

**Option A (chosen):** badge = day + month only; consolidated metadata line; localize month per `conexao_current_locale()` (PT keeps `JAN–DEZ`, EN renders `Jan–Dec`, fixing EN "OUT"/"SEG"). Multi-day same-month keeps `12–15 OUT`; cross-month shows full range `12 Out – 15 Nov` in the line. Today: "Hoje"/"Amanhã" text removed, `is-today` class retained for border emphasis.

**Rejected:** keeping chips in badge (violates treatment A); dropping recurrence chips (breaks occurrence test); `date_i18n('M')` (lowercase, diverges from uppercase PT convention).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/event-card.php` | Badge day+month only; add date/time metadata line; move recurrence chips to line; drop hint text; localize month | Core implementation |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Style `.event-card-detail--datetime`, `.event-card-date-text`, `.event-card-datetime-sep`; inline recurrence in line | Presentation |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | Mirror new line colors for dark theme | Contrast parity |
| `docs/reports/2026-10-19-event-date-badge-report.md` + `docs/evidence/2026-10-19-event-date-badge/` | Report + before/after evidence | Docs convention |

## 7. Files explicitly expected NOT to change

`wp-content/plugins/*` (incl. `plugins.json`), `archive.php`, `single.php`, `front-page.php`, `template-parts/event-preview.php`, `inc/events.php`, `inc/archive-query.php`, `conexao-event-runtime/*`, all `docs/audit/*` and previous convergence reports, untracked non-owned working-tree changes.

## 8. Content / data impact

Records created/updated/deleted: none. No content write (six-step contract N/A). PT content unchanged.

## 9. Route / HTTP impact

No routes added/removed/changed; no redirects; sitemap unaffected; existing `/eventos/`/`/en/eventos/` rows re-verified.

## 10. Polylang / English impact

Presentation layer only; PT immutable; no translation records added; completeness gate unchanged.

## 11. Security impact

Frontend template, read-only; all echo values remain escaped (`esc_html`/`esc_url`/`esc_attr`); no capabilities, nonces, SQL, REST, or secrets.

## 12. Performance impact

No queries added/removed; no cache/transient change; CSS additions only.

## 13. Production impact

No production write/deploy/upload/activation; production is WordPress.com (no SSH/WP-CLI); local-only verification.

## 14. Test plan

- In-process: `test-ui-convergence-t1.php`, `test-ui-convergence-t2.php` (stay green); add scoped assertions proving badge = day+month only and date/time line rendered once.
- Acceptance: `verify-event-occurrence-http.py` (stay green; its 4 pre-existing failures documented/unrelated).
- Script contract: none. Registry drift: `plugins.json` unchanged.
- Pass numerically: baseline vs after on `./scripts/run-tests.sh`; failure lists differ only by pre-existing failures.

## 15. Acceptance matrix plan

Rendered-markup rows for `/eventos/` + `/en/eventos/` (PT+EN): badge = day+month only (no weekday/hint/recurrence/range); one date/time metadata line; `event-card-time` iff time set; location once; PT month `OUT`, EN month `Oct`; multi-day duration visible; recurring chips still present in card.

## 16. Rollback plan

`git checkout --` the three theme files. No data/cache to reverse (`filemtime()` cache-buster).

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/frontend.md` | Add event-card date-badge + metadata-line contract |
| `docs/themes/conexao-br-irlanda.md` | Note date simplification if theme doc documents card date treatment |
| `docs/reports/2026-10-19-event-date-badge-report.md` | New additive implementation report |

## 18. Release implications

No version bumps (theme change); `plugins.json` unchanged; no release in scope (local verification only).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into card structure/queries | Medium | Strict diff scope |
| Breaking occurrence-test recurrence-chip assertions | Low | Keep chip classes in card; run suite |
| EN month localization unexpected | Low | PT unchanged; EN `Jan–Dec`; verify rendered output |
| Visual regression at 360–390px | Low | Reuse existing badge tokens/size; check viewports |

## 20. Verification gates

1. `./scripts/lint.sh` on modified files.
2. `./scripts/run-tests.sh --only conexao-br-irlanda` — t1/t2 green.
3. `verify-event-occurrence-http.py` via `--acceptance` — green except pre-existing failures.
4. Full `./scripts/run-tests.sh` — baseline comparison, no new failures.
5. Rendered-markup checks at 1440/1024/768/390/360 viewports, light + dark.

## 21. Completion criteria

- Badge shows only day + localized month abbreviation.
- One date/time metadata line + one location line per card where applicable.
- Date/time preserved (multi-day, recurring, missing-time, today).
- PT and EN localization correct.
- No query/eligibility/ordering/filtering/pagination/caching/production change.
- Responsive + light/dark verified.
- Focused + canonical tests run and reported; baseline compared; pre-existing failures listed separately.
- Documentation + evidence saved under `docs/evidence/2026-10-19-event-date-badge/`.
- Unrelated user changes untouched.

**Final status: pending verification.** PASS / PASS WITH LIMITATION / FAIL.

