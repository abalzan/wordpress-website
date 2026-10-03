# Rendered homepage preload markup — BEFORE vs AFTER

Captured with `curl -sS http://localhost:8080/` from the local Docker site
(`http-home-BEFORE.html`, `http-home-AFTER.html` in this directory). Only the
desktop preload's plain `href` differs; nothing else in either line changed.

## BEFORE (`header.php` at HEAD `7f8dea7`)

```html
<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(max-width: 768px)" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image_mobile.webp">
<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(min-width: 769px)" imagesrcset="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp 1600w, http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp 2057w" imagesizes="100vw" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp">
```

## AFTER (Stage 20 applied)

```html
<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(max-width: 768px)" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image_mobile.webp">
<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(min-width: 769px)" imagesrcset="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp 1600w, http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp 2057w" imagesizes="100vw" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp">
```

## Diff

```diff
-<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(min-width: 769px)" imagesrcset="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp 1600w, http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp 2057w" imagesizes="100vw" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp">
+<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(min-width: 769px)" imagesrcset="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp 1600w, http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image.webp 2057w" imagesizes="100vw" href="http://localhost:8080/wp-content/themes/conexao-br-irlanda/assets/images/conexaobr_Hero_image-1600.webp">
```

The `media`, `imagesrcset`, `imagesizes`, `fetchpriority`, `as` and `type`
attributes are byte-identical on the desktop line, and the mobile preload line
is byte-identical. The hero `<picture>` block is unchanged in both captures.
