# Theme fingerprint — i18n 1.0.1 proven live

Captured 2026-09-29, post-admin, read-only (GET only).

The reported version alone is **not** accepted as proof. Each file was fetched over
HTTP and hashed at the byte level.

## Positive discrimination

| File | prod status | prod bytes | prod md5 | i18n HEAD md5 | match |
|---|---|---|---|---|---|
| `assets/css/main.css` | **200** | **196327** | **`a367b7950e0511958a3768d284a4d35b`** | `a367b7950e0511958a3768d284a4d35b` | **YES** |
| `inc/i18n/guard.php` | **200** (was 404) | — | — | `0f982c38686ed287185f37ed8f00d1fe` | present |
| `inc/rest-language.php` | **200** (was 404) | — | — | `0b1f05d24cca8b4da4914a304aa86b71` | present |

`main.css` is **byte-exact** against the `i18n` working tree and the value required by
the task (196,327 bytes / `a367b7950e0511958a3768d284a4d35b`).

## No longer byte-identical to master

| Build | `main.css` bytes | `main.css` md5 |
|---|---|---|
| production **now** | 196327 | `a367b7950e0511958a3768d284a4d35b` |
| `master` (`git show master:…`) | 192920 | `a4bab9a49b7dd0bdcce665f2380ce423` |
| `i18n` HEAD | 196327 | `a367b7950e0511958a3768d284a4d35b` |

Production moved off `master` (`a4bab9a4…`) and onto `i18n` (`a367b795…`).
The PRE baseline recorded `a4bab9a4…` / 192920 — so this is a real, measured change.

## All required i18n-only files

Every one of these returned **HTTP 200** (all were 404 in the PRE baseline):

| File | status |
|---|---|
| `inc/i18n/guard.php` | 200 |
| `inc/i18n/hreflang.php` | 200 |
| `inc/i18n/locale.php` | 200 |
| `inc/i18n/switcher.php` | 200 |
| `inc/i18n/terms.php` | 200 |
| `inc/i18n/urls.php` | 200 |
| `inc/i18n/fallback.php` | 200 |
| `inc/rest-language.php` | 200 |

> The `.php` files serve **0 bytes** with HTTP 200: PHP is executed, and these
> files emit no output. Presence is proven by the 404 → 200 transition, not by a
> body hash. A negative control confirms the method discriminates:
> `inc/rest-language.php.bak` returns **404**.

## Declared version

`GET /wp-json/wp/v2/themes?status=active` → `conexao-br-irlanda` **1.0.1**, active.
`style.css` served over HTTP declares `Version: 1.0.1`.

**Conclusion: the `i18n` theme build 1.0.1 is live and proven by fingerprint, not by
the reported version string.**
