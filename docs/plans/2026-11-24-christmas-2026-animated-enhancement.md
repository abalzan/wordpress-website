# Plan: Christmas 2026 Animated Presentation Enhancement

**Date:** 2026-11-24  
**Author:** AI Assistant  
**Status:** In Progress

## Objective

Enhance the existing Christmas presentation by integrating the Christmas animations and visual assets already generated for this project. This is an enhancement of the existing implementation, NOT a new Christmas website or a complete redesign.

## Asset Inventory

### Existing Assets (in repository)

| Filename | Location | Format | Dimensions | Size | Animated | Purpose | Platform |
|----------|----------|--------|------------|------|----------|---------|----------|
| star.svg | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/ | SVG | Vector | 1.5 KB | No | Shared star motif for ambient decorations | Both |
| two-skies-hero-840.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 840×472 | 97 KB | No | Hero artwork (small) | Both |
| two-skies-hero-1200.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 1200×675 | 169 KB | No | Hero artwork (large) | Both |
| two-skies-postcard-760.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 760×427 | 94 KB | No | Landing page postcard (small) | Both |
| two-skies-postcard-1120.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 1120×630 | 175 KB | No | Landing page postcard (large) | Both |
| star-bridge-780.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 780×438 | 94 KB | No | Journey star bridge (small) | Both |
| star-bridge-1280.webp | wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/two-skies/ | WebP | 1280×720 | 212 KB | No | Journey star bridge (large) | Both |

### Generated Assets (in dist/conexao-br-christmas-assets.zip)

| Filename | Location | Format | Dimensions | Size | Animated | Purpose | Platform |
|----------|----------|--------|------------|------|----------|---------|----------|
| christmas-star.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 212 B | No | Christmas star decoration | Both |
| snowflake.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 333 B | No | Snowflake decoration | Both |
| christmas-bauble.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 392 B | No | Christmas bauble decoration | Both |
| holly.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 456 B | No | Holly decoration | Both |
| christmas-bell.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 390 B | No | Christmas bell decoration | Both |
| christmas-gift.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 370 B | No | Christmas gift decoration | Both |
| christmas-icon-sprite.svg | dist/conexao-br-christmas-assets/svg/ | SVG | Vector | 2.2 KB | No | SVG symbol sheet for all icons | Both |
| santa-sleigh-reindeer.gif | dist/conexao-br-christmas-assets/animations/ | GIF | N/A | 588 KB | Yes (8-frame, ~180ms/frame) | Santa sleigh flying animation | Both |
| santa-sleigh-preview.png | dist/conexao-br-christmas-assets/animations/ | PNG | N/A | 259 KB | No | Santa sleigh first frame preview | Both |
| christmas-winter-village.webp | dist/conexao-br-christmas-assets/images/ | WebP | N/A | 355 KB | No | Winter village hero background | Both |
| christmas-winter-village.png | dist/conexao-br-christmas-assets/images/ | PNG | N/A | 2.5 MB | No | Winter village hero background (lossless) | Both |

### Optional Effects Code (in generated assets)

- `code/christmas-effects.css` - CSS for snowfall and Santa fly-by
- `code/christmas-snowfall.js` - Canvas-based snowfall animation
- `code/christmas-santa-flyby.js` - Santa sleigh fly-by animation

## Current Implementation Analysis

### Existing Christmas Templates
1. **Hero** (`template-parts/commemorative/christmas/hero.php`) - Homepage welcome dialog with "One Christmas, Two Skies" artwork
2. **Ambient** (`template-parts/commemorative/christmas/ambient.php`) - Site-wide ambient star decoration
3. **Journey** (`template-parts/commemorative/christmas/journey.php`) - "Two skies, one star" interactive tabs on landing page
4. **Landing** (`template-parts/commemorative/christmas/landing.php`) - Full Christmas landing page with threads, modules, and closing
5. **Star** (`template-parts/commemorative/christmas/star.php`) - Reusable SVG star component (star and bridge variants)

### Existing CSS
- `assets/css/presentations/christmas.css` - Complete Christmas package styling with tokens, hero, journey, threads, closing, modules, ambient, responsive, reduced motion

### Existing JavaScript
- `assets/js/presentations/christmas/christmas.js` - Package behavior: welcome dialog, journey tabs, ambient decorations, micro-animations (CTA sparkle, content reveal, journey star pulse)

### Current Tests
- `test-commemorative-christmas-journey.php` - Signature experience contract tests
- `test-commemorative-christmas-welcome.php` - Homepage welcome contract tests
- Browser tests for signature, micro-animations, welcome, and landing dispatch

## Enhancement Plan

### Phase 1: Asset Integration
1. Copy generated SVG assets to theme's christmas decorations directory
2. Copy generated animation assets to theme's christmas directory
3. Copy generated hero background to theme's christmas directory
4. Update asset references in templates and CSS

### Phase 2: Enhanced Homepage Hero
1. Add subtle animated snowfall to hero background (CSS-based, respects reduced motion)
2. Add Santa sleigh fly-by as optional ambient decoration (respects reduced motion)
3. Enhance hero with subtle twinkling star effects
4. Ensure all animations are performant and accessible

### Phase 3: Enhanced Ambient Decorations
1. Expand ambient decoration pool with new SVG assets (snowflakes, baubles, bells, holly, gifts)
2. Implement varied ambient decorations using the shared scheduler
3. Add gentle falling snow as ambient option (CSS animation)
4. Ensure all ambient effects respect reduced motion and performance budgets

### Phase 4: Enhanced Landing Page
1. Add animated hero background with subtle snowfall
2. Enhance journey section with additional visual polish
3. Add decorative SVG elements to threads and modules sections
4. Enhance closing section with subtle animation

### Phase 5: Animation Engineering
1. Use CSS animations for simple decorative effects (preferred)
2. Use existing JavaScript for coordinated animations
3. Use generated animation assets (GIF) for complex illustrations
4. Implement proper lazy-loading for non-critical assets
5. Ensure all animations respect `prefers-reduced-motion: reduce`

### Phase 6: Testing
1. Run existing PHP tests
2. Run browser tests
3. Test reduced motion behavior
4. Test responsive behavior
5. Test activation/deactivation
6. Verify Halloween and New Year still work

## Files to Modify

### New Files
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/snowflake.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/christmas-bauble.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/holly.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/christmas-bell.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/christmas-gift.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/decorations/christmas-icon-sprite.svg`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/animations/santa-sleigh-reindeer.gif`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/animations/santa-sleigh-preview.png`
- `wp-content/themes/conexao-br-irlanda/assets/images/presentations/christmas/backgrounds/christmas-winter-village.webp`

### Modified Files
- `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` - Add new animations and effects
- `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` - Add new ambient decorations and micro-animations
- `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/ambient.php` - Expand ambient decoration pool
- `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/hero.php` - Add snowfall to hero
- `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/landing.php` - Enhance landing page

## Success Criteria

1. All existing tests pass
2. New animations respect `prefers-reduced-motion: reduce`
3. No layout shifts or performance regressions
4. Christmas presentation activates/deactivates correctly
5. Halloween and New Year presentations unaffected
6. Portuguese and English versions both work correctly
7. Responsive design works on all target viewports

## Timeline

- Phase 1-2: Asset integration and hero enhancement (Day 1)
- Phase 3: Ambient decorations (Day 1-2)
- Phase 4: Landing page enhancement (Day 2)
- Phase 5: Animation engineering and optimization (Day 2-3)
- Phase 6: Testing and verification (Day 3)