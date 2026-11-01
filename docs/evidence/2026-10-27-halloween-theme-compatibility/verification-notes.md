# Halloween theme compatibility evidence

Date: 2026-10-27
Branch: `holidays-dates`
Starting revision: `f378c2f4d3fbea4a320e0197f8949d5f4cff23fe`

## Theme and browser checks

The site uses its existing `html[data-theme]` state. The header initializes
that state before paint from `localStorage['conexao-theme']` or the OS
preference; the existing toggle changes the attribute and attempts to persist
the same key. Halloween styles resolve through that attribute and scoped
custom properties. No Halloween-specific theme state or reload was added.

The live toggle changed the open greeting from light to dark and back without
hiding or replacing it. The greeting remained centered at 320, 375, 390, 768,
1024 and 1440 CSS-pixel viewport widths in both themes; its content stayed
inside the card, the close control remained 44×44px, and its layout did not
change. Supporting-copy contrast against the card surface measured 7.52:1
(light) and 14.42:1 (dark); heading contrast measured 12.91:1 (light) and
17.01:1 (dark). The close icon measured 13.62:1 (light) and 14.88:1 (dark).
Keyboard focus on the close button produced a 3px solid `#713e78` outline.

Closing the greeting hid it; changing the theme afterward left it hidden and
did not create another presentation. The existing dismissal key remains
occurrence-year scoped and session-only. The browser harness blocks both
`sessionStorage` and `localStorage` with `SecurityError`, so persistence across
a page reload and preference storage could not be independently exercised.
Reloading for a separate check therefore reopened the greeting; this is a
test-environment limitation, not a change to the dismissal code.

The bat, pumpkin, ghost and witch effects were triggered through the existing
eligible-navigation handler. The bat and ghost retained the same DOM node and
their animation timelines continued across a live theme toggle; their computed
color/glow values changed with the palette. The witch retained the same node
and its 3,000ms flight timeline continued across the toggle (about 1.10s to
1.35s elapsed in the check). The pumpkin was visually captured in both
palettes. Reduced-motion emulation was checked under both themes: a synthetic
navigation click inserted no effect layer and the bat animation computed to
`none`.

At 320px, the whole homepage still has 11px of horizontal overflow
(`scrollWidth` 316px vs `clientWidth` 305px); the Halloween card itself has no
overflow. This is the known pre-existing `.featured-main` issue documented in
the 2026-10-26 homepage report, not introduced by the theme palette.

## Captures

- `homepage-light-mobile-320.png` and `homepage-dark-mobile-320.png`: open
  Halloween greeting at the actual 320×568 browser viewport.
- `nav-pumpkin-light-320.png` and `nav-pumpkin-dark-320.png`: the pumpkin
  navigation effect in each theme at 320×568.

The screenshot exporter did not produce trustworthy desktop captures: when
requested at desktop widths, the PNG canvas and page layout disagreed. Those
exports were removed. The six viewport-width checks above are DOM/computed
style measurements, not desktop screenshot evidence. No screenshot evidence
is claimed for the bat, ghost or witch; those effects were checked through
their live DOM, computed styles and animation timelines.

## Test and gate results

`./scripts/run-tests.sh` exited 1:

- In-process PHP: 114 suites, 110 passed, 4 failed; 8,879 assertions passed,
  9 failed. Both Halloween suites passed: navigation effects 69/69 and
  presentation 26/26 assertions.
- Script-contract: 19 suites, 18 passed, 1 failed. The i18n freshness check
  reported the theme `.pot` 8,526 seconds older than `header.php`.
- HTTP acceptance: 4 suites, 3 passed, 1 failed. Event-occurrence acceptance
  found zero expected weekly series and two multi-day events in the Laois
  archive.

The PHP failures were:

- `test-export-language.php`: PHP exhausted its 512MiB memory limit in
  `class-event-export.php:937`.
- `test-town-sanitization.php`: 82 passed, 1 failed; contaminated/non-town
  terms were present.
- `test-event-filter-cache.php`: 15 passed, 4 failed; cache, county rename and
  town-scope assertions failed, with Polylang `locale`-on-null warnings.
- `test-stage41-rest-language.php`: 218 passed, 4 failed; county-filter and
  translation-fallback assertions failed, with Polylang `locale`-on-null
  warnings.

The HTTP failure was `verify-event-occurrence-http.py`: expected one weekly
series but found zero, and expected one multi-day event but found two. These
failures are outside the Halloween changes, but no pre-change full-suite
baseline was captured, so they are not labelled as proven pre-existing.

`./scripts/lint.sh` exited 1. PHP syntax passed for 526 files and PHPStan
level 5 passed with no errors. PHPCS failed the repository baseline gate:
one unbaselined `Universal.CodeAnalysis.NoDoubleNegative.FoundDouble` finding
and increases in four repository-wide sniff counts. Direct PHPCS on the
changed Halloween template passed. No pre-change lint baseline was captured.
`git diff --check` passed.
