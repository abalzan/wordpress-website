# Why no implementation was made

## The task premise is factually wrong

The task states the `Artigo` on `/en/` "comes from **WordPress core's
localized post-type label**". Measured, this is false on both halves:

| Claim | Measurement | Verdict |
|---|---|---|
| It is a core post-type label | The only `Artigo` is in `home.php:18`, `__( 'Artigos e Notícias' )` — a **theme literal** | **false** |
| Core has no English for it | Core `Post → Artigo` (pt_BR) / `Post` (en_US) both resolve correctly | **false** |
| The project bypasses core gettext | `__()` is called correctly; the **`.mo` file is missing**, so the textdomain is not loaded | **false** (the boundary is intact; the file is gone) |
| Only one label leaks | **Every** theme string leaks; and `/en/` also 404s on `/en/guias/`, and `/en/` is not even the front page | **false** (site-wide outage) |

## The actual cause is the baseline commit, not a boundary bug

`597b04e` deleted the theme's whole translation catalogue
(`languages/**`, 5644 lines incl. `en_US.po/.mo`) plus `inc/i18n/**`,
`inc/seo/**`, `inc/content.php` and 26 theme test files, reverting the
previously-merged `Guia Prático` fix. It is local-only (`origin/i18n` is at
`3cc6564`).

## Why a "minimal label fix" is the wrong move

Restoring translation of this one string would require re-creating the
deleted catalogue — explicitly out of scope here, and it would paper over a
site-wide EN outage while leaving the regression in place. Per the task's
own constraints:

- *"Do not absorb or revert concurrent/unrelated theme changes"* — `597b04e`
  is exactly that; reverting it is not mine to do.
- *"Do not fabricate or hardcode a translation merely to remove the visible
  word"* — this is the only way to make `/en/` show English **without**
  restoring `languages/`, so doing it would violate a hard constraint.

The correct, safe project-level correction is to **restore the
translation catalogue and the theme module structure** — i.e. to resolve the
`597b04e` regression. That is a different, larger task with its own plan, and
it is a decision for the maintainer, since `597b04e` is a committed change I
was told not to absorb or revert.

## What the state at HEAD actually looks like

`/en/` is not the front page; it is the blog archive:

```
b47d098 (known good): class="home ... front-page"   section-eyebrows: Featured Content / Agenda / Opportunities
597b04e (HEAD):       class="blog ... blog-page"    section-eyebrow:  Artigos e Notícias
```

Supporting HTTP facts at `597b04e`:

| Route | Status | Note |
|---|---|---|
| `/` | 200 | PT front page renders |
| `/en/` | 200 | renders **blog**, not the front page |
| `/en/guias/` | **404** | expected 200 |
| `/guias/` | 200 | |
| `/en/lazer/` | **301** | expected 200 |
| `/en/blog/` | **301** | expected 200 |

And `./scripts/run-tests.sh` at `597b04e`: **24 suites PASS, 17 FAIL**.
Permanent gates: **0 of 7 passed**, aggregate **FAIL**
(`taxonomy`, `translation_completeness`, `cache_scoping`,
`cache_scoping_static`, `redirect_precedence`, `documentation_drift`,
`i18n_freshness`).
