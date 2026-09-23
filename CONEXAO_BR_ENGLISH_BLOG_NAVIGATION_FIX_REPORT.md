# CONEXAO_BR — ENGLISH BLOG NAVIGATION FIX REPORT

**Scope:** WordPress website/theme only (no Flutter, no REST contract change, no Polylang configuration change, no content change).

**Symptom:** on any English page, clicking **Blog** in the header navigation switched the
site back to Portuguese. Expected: the click stays in English (`/en/blog/`) and the
Portuguese navigation keeps linking to `/blog/`.

---

## 1. Root cause

The Blog navigation item is rebuilt at render time from `conexao_lang_url( '/blog/' )`
(`conexao_primary_nav_sections()` → `conexao_bind_section_object()` in `functions.php`).
Two defects made that call return the Portuguese URL in an English request:

1. **`post` archive exclusion.** `conexao_lang_url()` skipped the language-aware
   archive branch for the `post` type (`'post' !== $post_type`), and — crucially —
   the posts page is **not** a `post` post-type archive at all: WordPress registers
   `post` with `has_archive = false`, so `conexao_lang_url_archive_post_type('/blog/')`
   returns `''`. The path then fell through to `get_page_by_path('blog')` (the
   `page_for_posts` page, no EN translation) and finally to
   `home_url( '/blog/' )` — **always Portuguese**.

2. **Blog was classified B1, not B2.** The site's bilingual architecture redirects an
   untranslated EN URL to its Portuguese counterpart (B1). For the posts page that
   meant `/en/blog/` → `302` → `/blog/`, i.e. an English visitor was sent back to
   Portuguese even when the link itself was language-aware.

A third, subtler defect only appeared once the link pointed at `/en/blog/`: Polylang
flips its own `curlang` to the resolved object's language while it computes the
canonical redirect, so on `/en/blog/` the render used Portuguese `curlang` — the
per-language menu, the bare `home_url()` and every `pll_*` shell string resolved to
Portuguese (mixed-language header) even though the request was English.

## 2. Fix

Blog is made a **B2 destination** (Portuguese posts rendered under the English URL),
which is the only option that satisfies "stay in English" without creating a second
Blog page or translating the posts page.

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | New `conexao_posts_page_url( $target_slug )` — language-aware URL of the posts page: a real linked translation wins, otherwise language home + the posts page's own path (`/blog/` → `/en/blog/`), fully derived (no hard-coded slug or `/en/`). `conexao_language_archive_url()` and `conexao_lang_url()` (new step **2b**) resolve `/blog/` through it. `conexao_current_language_slug()` returns the **shell** language during a B2 fallback. `conexao_is_language_fallback()`, `conexao_language_switch_url()` and `conexao_polylang_canonical_url()` now cover the posts page (`is_home() + is_posts_page`), not only `is_singular()`. New `conexao_restore_b2_shell_language()` (`template_redirect`, priority 5) restores Polylang's `curlang` to the requested language for B2 renders. `'blog'` added to `conexao_b2_page_allowlist()`. |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | `conexao_seo_missing_translation_redirect()` also early-returns for the B2 posts page, so the posts page is never 302-redirected to `/blog/`. |
| `wp-content/themes/conexao-br-irlanda/home.php` | Renders the standard B2 notice (`conexao_b2_fallback_notice()`) above the Blog archive, like the other B2 templates. |
| `wp-content/themes/conexao-br-irlanda/functions.php` | `conexao_b2_posts_page_pre_query()` (`posts_pre_query`) serves the Portuguese post set on `/en/blog/` (Polylang scopes the posts-page main query to the page's own language, so the EN URL would otherwise be empty), preserving pagination and the `?categoria=` filter. |
| `docs/routing.md`, `docs/themes/conexao-br-irlanda.md` | Blog documented as an approved B2 destination; new helper row; EN-nav note. |

`conexao_lang_url()` remains **byte-identical to `home_url( $path )` on Portuguese**:
the shell language equals the default language, so the Blog item still resolves to
`/blog/` for Portuguese visitors, and the whole change is a no-op when Polylang is
inactive.

## 3. Validation (local WordPress + Polylang, `http://localhost:8080`)

Rendered HTTP checks:

| Check | Result |
|---|---|
| `/en/`, `/en/guias/`, `/en/eventos/`, `/en/jobs/`, `/en/lazer/`, `/en/blog/`, `/en/contact/` → Blog link | **`/en/blog/`** |
| `/`, `/guias/`, `/eventos/`, `/empregos/`, `/lazer/`, `/blog/`, `/contato/` → Blog link | **`/blog/`** |
| `/en/blog/` | 200, `<html lang="en-US">`, canonical → `/blog/`, B2 notice present |
| `/blog/` | 200, `<html lang="pt-BR">`, no notice |
| `/en/blog/` nav | all nine items in `/en/`, `aria-current="page"` on Blog |
| EN nav leak audit (`/en/blog/`) | no item leaves `/en/` |

Automated suites (before = `git stash` baseline, after = this change):

| Suite | Before | After |
|---|---|---|
| `tests/test-nav-language-context-logic.php` (no WP) | — | **49 passed, 0 failed** |
| `tests/test-nav-language-context.php` (live) | — | **15 passed, 0 failed, 1 skipped** |
| `tests/test-nav-menu-regression.php` | — | **31 passed, 0 failed** |
| `tests/test-i18n-foundation.php` | — | **29 passed, 0 failed** |
| `tests/test-polylang-foundation.php` | 60 / 1 failed | **60 / 1 failed (identical, pre-existing)** |
| `tests/test-stage32-bilingual.php` | 31 / 11 failed | **31 / 11 failed (identical, pre-existing)** |
| `tests/test-stage33-bilingual.php` | 60 / 19 failed | **60 / 19 failed (identical, pre-existing)** |
| `scripts/nav-regression-http-verify.py` | — | **63 passed, 0 failed** |

`php stage43-work/lint-all.php .` → 287 files, **0 parse errors**.

Tests updated to the new approved behaviour (they encoded the old B1 Blog redirect):
`test-nav-language-context.php`, `test-nav-language-context-logic.php`,
`test-stage32-bilingual.php` (allowlist), `test-stage33-bilingual.php` (B1 → B2),
`scripts/nav-regression-http-verify.py`, `scripts/stage33-http-verify.py`,
`scripts/stage43-verify-english.py`.

## 4. Acceptance

- Clicking Blog from any English page keeps the user in English — **PASS**
- Clicking Blog from any Portuguese page keeps the user in Portuguese — **PASS**
- English Blog destination is `/en/blog/` (the site's English posts-archive URL) — **PASS**
- Portuguese Blog destination unchanged (`/blog/`) — **PASS**
- Existing Blog menu item kept, no duplicate item — **PASS**
- No CSS/JavaScript workaround, no hard-coded page ID or `/en/` URL — **PASS**
- Fix applied at the source of the incorrect URL — **PASS**
- Other navigation items unchanged — **PASS** (PT unchanged, EN still `/en/...`)
- Desktop and mobile share the same `primary` menu — **PASS**

