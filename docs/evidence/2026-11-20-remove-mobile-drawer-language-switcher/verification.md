# Evidence — Remove the language selector from the mobile hamburger menu drawer

Date: 2026-11-20. Environment: local Docker stack (`wordpress` service on `http://localhost:8080`), theme bind-mounted from `wp-content/themes/conexao-br-irlanda`. Test DB: `conexao_stage05_test_5bd9ece5c032` (isolated, pre-existing).

## Baseline (pre-change, commit 10a01f5898af2ad39359293dcb1fc0fee96e2168)

Command: `docker compose exec -T ... wordpress bash /var/www/html/scripts/conexao-test-exec.sh php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-language-switcher-flag.php`

Result: **language-switcher-flag: 54 passed, 1 failed**

| Check | Result |
|---|---|
| L60 both header contexts go through the one shared renderer | **FAIL** — header.php had 3 anchored `conexao_language_switcher(` call sites (desktop + mobile header + mobile drawer), the gate expects 2 |
| L72–L80 disable path (flag flip removes switcher, data layer intact) | PASS |
| L43–L59 rendered switcher markup (desktop + mobile contexts) | PASS |
| All remaining assertions | PASS |

`grep -Pc '^\s*conexao_language_switcher\(' header.php` → **3** (baseline; gate expects 2).
`grep -c 'mobile-menu-language' header.php` → **1** (baseline).

## After change

Command: same suite, against the edited tree (files re-synced into the running container).

Result: **language-switcher-flag: 55 passed, 0 failed** — L60 now passes because header.php has exactly 2 anchored call sites (desktop context + main-header mobile context).

Command: `php -l wp-content/themes/conexao-br-irlanda/header.php` → **No syntax errors detected**.

Command: `grep -c 'mobile-menu-language' wp-content/themes/conexao-br-irlanda/header.php` → **0**

Command: `grep -Pc '^\s*conexao_language_switcher\(' wp-content/themes/conexao-br-irlanda/header.php` → **2**

Command: `grep -c 'mobile-menu-language' wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` → **0**; `.language-switcher--mobile .language-switcher-item` drawer rule removed; `.header-actions .language-switcher--mobile` rules retained.

Command: `curl -s http://localhost:8080/` (live local site, edited files served):

| Assertion | Expected | Actual |
|---|---|---|
| `.mobile-menu-language` in rendered HTML | 0 | 0 |
| `language-switcher--mobile` instances in rendered HTML | 1 (main header only) | 1 |
| `language-switcher--desktop` instances in rendered HTML | 1 (untouched) | 1 |
| EN link: `href="http://localhost:8080/en/" hreflang="en" lang="en"` | present | present |
| PT item: `aria-current="true" lang="pt-BR"` | present | present |
| EN item: `aria-label="Ver em English"` | present | present |

Rendered drawer markup after `mobile-menu-header`: HTML comment → `.mobile-menu-theme` (theme toggle) → ... — no language switcher block anywhere in `#mobile-menu`.

Rendered main-header switcher (live HTML):
```html
<div class="language-switcher language-switcher--mobile" role="group" aria-label="Idioma do site">
    <span class="language-switcher-item is-current" aria-current="true" lang="pt-BR">PT</span>
    <a class="language-switcher-item" href="http://localhost:8080/en/" hreflang="en" lang="en" aria-label="Ver em English">EN</a>
</div>
```

Theme-wide references to `mobile-menu-language` in `*.php`/`*.js`/`*.css` under `wp-content/themes/conexao-br-irlanda/`: **0** (no JS dependencies exist; the only repo reference was scratch tooling `carousel-work/langbar-measure.py`, untracked analysis tooling, not runtime).

Command: `./scripts/run-tests.sh --scripts` (static script-contract layer, no PHP/Docker/DB) → **18 static suites, 16 passed, 2 failed**. Both failures are pre-existing and unrelated to this theme-only diff:
- `conexao-event-importer`: `.pot` catalogue older than plugin source by 72255s (plugin untouched).
- `verify-script-conventions.py`: `scripts/conexao-test-exec.sh` not catalogued in `scripts/README.md` (script untouched).

## Viewport validation note

The removed/added markup is server-rendered and viewport-independent: `mobile-menu-language` is absent from the HTML at every width, and `.header-actions .language-switcher--mobile` is governed by `@media (max-width: 1024px)`, which covers 320px, 360px, 390px and 430px. Live pixel-perfect rendering at those exact widths requires a browser viewport emulator, which is not available in this environment; the markup/CSS contracts above are verified instead.
