# Source trace — where `Artigo` on `/en/` actually comes from

Baseline: `597b04e` (working tree clean at start of task).

## 1. The rendered occurrence

```
$ grep -n 'Artigo' http-en-homepage-AT-HEAD-597b04e.html
299:  <span class="section-eyebrow">Artigos e Notícias</span>
```

Exactly **1** occurrence of `Artigo` in the whole EN homepage, and it is the
blog-archive section eyebrow. It is **not** a post-type chip and **not** the
string `Artigo` on its own.

## 2. The template

```
$ grep -rn 'Artigos e Notícias' wp-content/themes/conexao-br-irlanda/
wp-content/themes/conexao-br-irlanda/home.php:18:
    'eyebrow' => __( 'Artigos e Notícias', 'conexao-br-irlanda' ),
```

It is a **theme literal** passed through `__()` with the theme text domain.
The project's post-type chips (`conexao_content_type_label()` /
`$content_type->labels->singular_name`) do **not** appear on `/en/` at all,
because `/en/` does not render `front-page.php` at this baseline.

## 3. Why `__()` returns the Portuguese msgid

`load_theme_textdomain()` is called with a path that does not exist:

```
functions.php:499  load_theme_textdomain( 'conexao-br-irlanda', CONEXAO_THEME_DIR . '/languages' );
$ ls wp-content/themes/conexao-br-irlanda/languages
ls: cannot access '...': No such file or directory
```

Runtime confirmation inside WordPress:

```
theme textdomain loaded: NO
theme en_US.mo: MISSING
__('Artigos e Notícias') => Artigos e Notícias
```

So **every** theme string on `/en/` falls through to its Portuguese msgid.
This is a whole-catalogue outage, not a single label.

## 4. The catalogue was deleted by the baseline commit

`597b04e` "Refactor code structure for improved readability and
maintainability" removed the entire theme translation catalogue:

```
$ git show --stat 597b04e -- wp-content/themes/conexao-br-irlanda/languages
 .../languages/conexao-br-irlanda.pot               | 1684 ------
 .../themes/conexao-br-irlanda/languages/en_US.mo   | Bin 33123 -> 0
 .../themes/conexao-br-irlanda/languages/en_US.po   | 1987 ------
 .../themes/conexao-br-irlanda/languages/pt_BR.mo   | Bin 34091 -> 0
 .../themes/conexao-br-irlanda/languages/pt_BR.po   | 1973 ------
 5 files changed, 5644 deletions(-)
```

The same commit also deleted `inc/content.php`, `inc/i18n/**`, `inc/seo/**`
and the previous task's `conexao_content_type_label()` helper:

```
$ grep -rn 'conexao_content_type_label' wp-content/     # (no matches)
$ git grep -c conexao_content_type_label 597b04e        # (no matches)
```

and reverted `front-page.php` back to the raw, untranslated read:

```diff
-<?php echo esc_html( conexao_content_type_label( get_post_type() ) ); ?>
+<?php echo esc_html( $content_type->labels->singular_name ); ?>
```

## 5. Scale of the structural change in 597b04e

| Path | `b47d098` (last known good) | `597b04e` (HEAD) |
|---|---|---|
| `wp-content/themes/.../inc/**` files | 43 | 8 |
| `wp-content/themes/.../tests/**` files | 33 | 7 |
| `languages/**` files | 5 | 0 |
| `functions.php` | loader only | 3937 lines, +3914 |

## 6. The previous task's `Guia Prático` fix is no longer present

The prior evidence file `docs/evidence/2026-09-27-en-homepage-label-leak/
http-en-homepage-before.html` (captured at the known-good baseline) shows:

```
<body class="home ... front-page">
<span class="section-eyebrow">Featured Content</span>
<span class="featured-article-category">Guia Prático</span>   <-- still PT there
```

That file is the *pre-fix* capture. The fix landed in `8f07f30`; `597b04e`
reverted it. So the already-closed `Guia Prático` defect is **reopened** at
this baseline.
