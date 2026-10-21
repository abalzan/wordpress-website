# Plan — Homepage Shortcut Consolidation (UI/UX Improvement 2)

| | |
|---|---|
| **Task title** | Implement UI/UX Improvement 2 — Homepage Shortcut Consolidation |
| **Date** | 2026-10-21 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | master |
| **Repository baseline (starting SHA)** | e81bf4e40b29d5e7664706c86cfefd7738c104ae |
| **Applicable skill(s)** | `wp-repository` |
| **Plan status** | draft |

## 1. Scope

Consolidate the overlapping homepage shortcut surfaces on `/` and `/en/` so the same destination is never rendered as two tiles:

- The "Acesso Rápido" primary grid keeps only the shortcuts that are NOT in the "Precisa de ajuda?" subset (general navigation).
- The "Precisa de ajuda?" section stays as a clearly labeled subset of the same canonical card definitions (problem-solving / essential services).
- No duplicate destination tiles across the consolidated shortcut experience; every unique existing resource remains discoverable.

This is an incremental template-level change. No redesign, no new component, no new registry, no route/translation change.

## 5. Proposed approach

**Chosen option — `$help_shortcut_keys` as the single source of truth for help membership.**

- Define `$help_shortcut_keys` once, before the Quick Access grid loop, with a comment stating that listed keys belong to the "Precisa de ajuda?" subset and are excluded from the primary grid.
- In the grid `foreach`, `continue` for any card whose `key` is in `$help_shortcut_keys`.
- Leave the help section unchanged (it already builds `$help_shortcuts` from `$quick_access_cards` keyed by `$help_shortcut_keys`).
- No card-definition changes. No template-part changes. No CSS changes required (grid/column conventions preserved).

**Rejected options:**
- Add a per-card `help => true` flag: creates a second membership source alongside `$help_shortcut_keys` ("two places is a bug"); the keys array already expresses membership.
- Merge into one visual section with nested subgroups: would be a homepage redesign and add visual nesting; rejected per "avoid complicated visual nesting."
- Keep duplicates and just relabel: violates the core "no duplicate destination tiles" acceptance criterion.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/front-page.php` | Add `$help_shortcut_keys` before grid loop; skip help keys in grid `foreach` | Remove duplicate tiles; single source of truth |
| `wp-content/themes/conexao-br-irlanda/tests/test-homepage-shortcut-consolidation.php` (new) | Consolidation assertions (no duplicate destinations, all retained, localized headings, PT/EN, no warnings) | Regression coverage |
| `tests/browser/verify-homepage-shortcut-consolidation-browser.py` (new) | Real-browser no-duplicate + destinations + overflow + focus checks at required viewports | Real-browser verification |
| `docs/themes/conexao-br-irlanda.md` | Update shortcut contract section (group membership via `$help_shortcut_keys`) | Keep theme docs accurate |
| `docs/reports/2026-10-21-homepage-shortcut-consolidation-report.md` (new) | Implementation report | Documentation convention |
| `docs/evidence/2026-10-21-homepage-shortcut-consolidation/` (new) | Before/after screenshots, test output, baseline comparison | Evidence convention |

## 7. Files explicitly expected NOT to change

- `template-parts/quick-access-card.php`, `template-parts/help-shortcut-card.php`: reusable; no change needed.
- `assets/css/main.css`: design system preserved; no new tokens.
- `languages/*.po`, `languages/*.pot`: no label/translation change.
- `plugins.json`, `compose.yaml`, `wp-content/plugins/`: untouched.
- Flutter/mobile repository: never accessed.
- Production (conexaobr.ie): no writes.

## 8. Content / data impact

- Records created/updated/deleted: none.
- Portable identifier strategy: card `key` slugs (already stable) used for grouping; no local post/attachment IDs.
- Does this write content? No. The six-step content-change contract does not apply.
- PT content changed? No. PT immutable.

## 9. Route / HTTP impact

- Routes added/removed/changed: none.
- Redirects: none.
- Sitemap coverage: unchanged.
- HTTP matrix rows: none new (existing homepage `/` and `/en/` rows already covered).

## 10. Polylang / English impact

- Translated post types/taxonomies affected: none.
- PT immutable in this change: Yes.
- B1/B2 for each destination: unchanged (destinations and `conexao_lang_url` resolution untouched).
- Canonical/hreflang/language-switcher: unchanged.
- Language-scoped cache keys: none affected.
- Completeness gate: n/a (no content written).

## 11. Verification

- PHP syntax check on `front-page.php`.
- New PHP consolidation test (PT + EN).
- Existing `test-stage33-bilingual.php` (template-level destination resolution, unchanged).
- New browser test at 1440x1000, 1024x1024, 768x1024, 390x844, 360x800 for `/` and `/en/` (no duplicate `href`s, all unique destinations present, localized headings, no horizontal overflow, focus visible).
- `./scripts/run-tests.sh` aggregate (PHP + scripts + acceptance).
- `./scripts/lint.sh` on modified files.
- Before/after screenshots captured and compared.
