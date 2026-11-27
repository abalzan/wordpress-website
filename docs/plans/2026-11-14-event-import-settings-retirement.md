# Plan: Remove Empty Import Settings Page

## Objective
Retire the `conexao-import-settings` admin page from Event Import, keeping `Conexao_Import_Settings` as a storage/helper class consumed by `Conexao_Event_Cleanup`.

## Files to change
1. `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` — remove settings instantiation + `admin_menu` hook (keep `require_once`)
2. `wp-content/plugins/conexao-event-importer/includes/class-import-settings.php` — remove `register_admin_menu()` and `render_page()`; keep storage methods
3. `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` — add `retired_settings_redirect_url()` + `maybe_redirect_retired_settings_page()`
4. `wp-content/plugins/conexao-event-importer/includes/class-import-dashboard.php` — hook settings redirect to `admin_page_access_denied`
5. `docs/plugins/conexao-event-importer.md` — update Key Components + submenu list + retired-route docs
6. Tests — add admin menu/page retirement tests; verify settings storage & cleanup tests still pass

## Constraints (non-negotiable)
- Do not delete `Conexao_Import_Settings`
- Do not delete/reset `conexao_event_importer_settings` option
- Do not change `cleanup_scope` default fallback `'all'`
- Keep `require_once class-import-settings.php` in bootstrap (Cleanup depends on it)
- Redirect uses `wp_safe_redirect()` + `exit`, only for `manage_options` users
- Reuse the existing `admin_page_access_denied` retired-page pattern

## Verification
- `./scripts/run-tests.sh --php --only conexao-event-importer`
- `./scripts/lint.sh`
- `php scripts/generate-registry-docs.php --check`
