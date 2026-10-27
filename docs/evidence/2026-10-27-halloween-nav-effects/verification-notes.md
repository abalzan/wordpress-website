# Evidence — Halloween Navigation Click Effects

**Date:** 2026-10-27
**Branch:** `holidays-dates`
**SHA:** 675c3dd70cde40a49813c333cbf8ebca62ddf5f9

## Verification performed

### In-process PHP tests (inside WordPress container)

| Suite | Result | Details |
|---|---|---|
| `test-seasonal-halloween-nav-effects.php` | 7 passed, 0 failed | Eligibility marker: Halloween active → marker present; disabled → absent; other occasion → absent; dismissal independent of eligibility |
| `test-seasonal-halloween-presentation.php` | 17 passed, 0 failed | Unchanged existing suite; confirms no regression |
| `test-seasonal-occasion.php` | 17 passed, 0 failed | Unchanged existing suite; confirms no regression |

### Syntax checks

| File | Result |
|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | No syntax errors |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | No syntax errors |

## Implementation summary

### Files changed
- `wp-content/themes/conexao-br-irlanda/header.php` — Added Halloween-active marker element (read by JS, no date logic duplicated)
- `wp-content/themes/conexao-br-irlanda/assets/css/main.css` — Added CSS animations for bat, pumpkin, ghost, particles + reduced-motion overrides
- `wp-content/themes/conexao-br-irlanda/assets/js/main.js` — Added `initHalloweenNavEffects()` with event delegation, cooldown, cleanup; registered in DOMContentLoaded
- `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` — New PHP test suite (7 assertions)

### Effects implemented
- **Effect A — Flying bat:** SVG bat silhouette with articulated wings, curved swooping flight path across the screen, 3 amber trail particles. Duration ~950ms.
- **Effect B — Laughing pumpkin:** Jack-o'-lantern SVG with carved face, amber glow, bobbing/wobbing laugh animation. Duration ~1150ms.
- **Effect C — Little ghost:** Friendly ghost SVG with pop-in, float-away motion. Duration ~1250ms.
- **Effect D — Trail particles:** 3 glowing amber particles trailing behind the bat effect.

### Trigger behavior
- Activates on clicks of `a.nav-link` within `#site-navigation` (desktop) and `.mobile-menu-nav` (mobile drawer)
- Excluded: mobile-menu-toggle, mobile-menu-close, theme-toggle, search controls, Halloween close button, external links, modified clicks (Ctrl/Cmd/Meta/Shift/Alt), middle-click
- No `preventDefault()`, no navigation delay

### Seasonal integration
- Server-side marker in `header.php` reads `conexao_seasonal_variant() === 'halloween'`
- The occasion engine remains the sole eligibility authority; no date calculation in JS
- JS checks for the marker before enabling effects

### Dismissal behavior
- Dismissing the welcome greeting (`sessionStorage` key) does NOT affect nav effects
- The close button only closes the presentation; occasion eligibility is unchanged

### Accessibility
- `prefers-reduced-motion: reduce` disables all animations (elements render at 55% opacity, static)
- Decorative SVGs use `aria-hidden="true"` and `focusable="false"`
- Effect layer uses `aria-hidden="true"`, `pointer-events: none`
- No focus movement, no live region announcements
- Navigation fully usable without animations

### Performance
- Single delegated click listener on `document`
- Cooldown: 1500ms between effects
- One effect at a time; DOM elements removed after animation completes
- Transform/opacity only; no layout-property animation
- No external dependencies, no framework, no hotlinked assets

### Limitations
- Browser-based visual verification could not be performed (no browser automation tooling in this environment)
- Full `./scripts/run-tests.sh` could not be executed (requires Docker WordPress with the test runner's discovery mechanism)
- PHPCS/PHPStan static checks not run in this session
- The 1500ms cooldown value is heuristic and may be tuned based on visitor feedback

