# Plan: Commemorative Events Plugin Theme Independence

## Objective
Make the `conexao-commemorative` plugin fully independent of the `conexao-br-irlanda` theme, so it can operate with any WordPress theme.

## Current State Analysis

### Plugin Architecture (Already Theme-Independent)
The plugin already follows good separation:
- `conexao-commemorative.php` - bootstrap only
- `class-conexao-commemorative.php` - engine bootstrap
- `class-conexao-commemorative-repository.php` - event record access (uses `conexao_current_locale()` when available)
- `class-conexao-commemorative-resolver.php` - eligibility/ranking (reads packages via filter)
- `class-conexao-commemorative-schedule.php` - window resolution
- `class-conexao-commemorative-cache.php` - cache/boundary management
- `class-conexao-commemorative-preview.php` - signed preview
- `class-conexao-commemorative-admin.php` - admin UX
- `class-conexao-commemorative-migration.php` - one-time seed
- `class-conexao-commemorative-event.php` - value object
- `functions.php` - public API (theme-independent)

### Theme Dependencies Found
1. **`conexao_current_locale()`** - Used in `Conexao_Commemorative_Repository::current_locale()` - falls back to `get_locale()` ✓ (already has fallback)
2. **Filter `conexao_commemorative_presentations`** - Theme registers packages via this filter ✓ (correct architecture)
3. **Theme template parts** - Theme owns presentation templates ✓ (correct architecture)
4. **Theme assets** - Theme owns CSS/JS ✓ (correct architecture)

### What Needs to be Verified/Implemented
1. ✅ Plugin initializes without theme functions
2. ✅ Event records retrievable without theme
3. ✅ Scheduling works without theme
4. ✅ Admin UX works without theme
5. ✅ Preview works without theme
6. ✅ Public API works without theme
7. ❓ Need to test with alternative theme (Twenty Twenty-Five)
8. ❓ Need automated architectural regression tests
9. ❓ Need to document public API for theme consumers

## Implementation Phases

### Phase 1: Dependency Audit (Complete)
- [x] No direct theme function calls in plugin
- [x] No theme template paths in plugin
- [x] No theme asset paths in plugin
- [x] No theme-specific includes/requires
- [x] Plugin uses filter for package registry (correct)
- [x] Plugin uses `conexao_current_locale()` with fallback (correct)

### Phase 2: Remove Theme Dependencies (Already Done)
The plugin is already theme-independent. No changes needed.

### Phase 3: Public Integration Interface (Document Existing)
Document the existing public API in `docs/commemorative-events.md` and `docs/plugins/conexao-commemorative.md`.

### Phase 4: Theme as Consumer (Verify)
Verify theme uses plugin's public API correctly:
- Uses `conexao_commemorative_active()` ✓
- Uses `conexao_commemorative_resolution()` ✓
- Uses `conexao_commemorative_presentations()` filter ✓
- Handles missing plugin gracefully ✓

### Phase 5: Alternative Theme Test
Test with Twenty Twenty-Five theme:
1. Switch to Twenty Twenty-Five
2. Verify plugin initializes
3. Verify event admin works
4. Verify event records accessible
5. Verify scheduling works
6. Verify translations work
7. Verify preview works
8. Switch back to conexao-br-irlanda
9. Verify everything restored

### Phase 6: Automated Architectural Regression Tests
Add tests that detect:
- Direct references to `conexao-br-irlanda` in plugin code
- Theme-specific function calls in plugin
- Theme template paths in plugin
- Theme asset paths in plugin

### Phase 7: Preserve Functionality
Ensure all existing tests pass.

### Phase 8: Documentation
Update:
- `docs/commemorative-events.md` - separation of responsibilities, public API
- `docs/plugins/conexao-commemorative.md` - public contract
- `docs/themes/conexao-br-irlanda.md` - theme as consumer

### Phase 9: Complete Test Run
Run all test suites and report results.

## Test Strategy

### Test A: Custom Theme Active (Current)
- Run existing commemorative plugin tests ✓ (210 passing)
- Run commemorative runtime tests ✓ (104 passing)

### Test B: Alternative Theme Active (Twenty Twenty-Five)
- Switch theme in test environment
- Run commemorative plugin tests
- Run commemorative runtime tests
- Verify no fatal errors

### Test C: Plugin Inactive
- Deactivate plugin
- Verify theme works without fatal errors
- Reactivate plugin

### Test D: Restore Original
- Switch back to conexao-br-irlanda
- Verify all functionality restored

## Files to Modify
1. `docs/commemorative-events.md` - Add public API documentation
2. `docs/plugins/conexao-commemorative.md` - Enhance public contract
3. `docs/themes/conexao-br-irlanda.md` - Document theme as consumer
4. New test file: `tests/architectural/test-commemorative-theme-independence.php`
5. Possibly: `wp-content/plugins/conexao-commemorative/tests/test-commemorative-theme-independence.php`

## Success Criteria
1. Plugin initializes without conexao-br-irlanda theme
2. Event administration works with alternative theme
3. Existing event records and translations accessible
4. Scheduling, resolution, activation work with alternative theme
5. Plugin exposes documented public interface
6. Theme consumes interface without duplicating event management
7. Theme handles plugin deactivation without fatal errors
8. Existing presentations work with original theme
8. Automated architectural regression tests protect separation
9. Original environment restored after testing