# Hero preload fallback — Firefox measurements (Stage 20)

Local site `http://localhost:8080`, Firefox **156.0.1** driven through
geckodriver (WebDriver, `measurements/ffprobe.py`). Every run is a **fresh
session = fresh profile**, launched `-headless -private`, with
`browser.cache.disk.enable=false`, `browser.cache.memory.enable=false` and
`network.http.use-cache=false` — i.e. the cold/private condition from the
investigation. LCP comes from a buffered `PerformanceObserver`
(`largest-contentful-paint`); hero requests come from
`performance.getEntriesByType('resource')`, whose `initiatorType` shows whether
the `<link>` preload (`link`) or the `<img>`/`picture` (`img`) started the fetch.

Raw files: `measurements/ff-<viewport>-<state>.json` (sequential batches) and
`measurements/pair*-<viewport>-<state>.json` (interleaved A/B).

## Interleaved A/B — the comparison to trust (n = 3 per state)

`before` and `after` alternate run-by-run, so container warm-up cannot explain
a difference.

| Viewport | State | LCP samples (ms) | median | Hero asset(s) requested | Hero bytes | Initiator | Whole-page transfer |
|---|---|---|---|---|---|---|---|
| 390×844 (mobile) | before | 667, 515, 597 | 597 | `conexaobr_Hero_image_mobile.webp` | 100,080 | `link` | 436,639 |
| 390×844 (mobile) | after | 576, 521, 569 | 569 | `conexaobr_Hero_image_mobile.webp` | 100,080 | `link` | 436,639 |
| 1440×900 (desktop) | before | 564, 657, 541 | 564 | `conexaobr_Hero_image-1600.webp` | 139,882 | `link` | 476,184 |
| 1440×900 (desktop) | after | 627, 1044, 577 | 627 | `conexaobr_Hero_image-1600.webp` | 139,882 | `link` | 476,184 |

**Hero bytes and whole-page transfer are byte-identical before and after at
both viewports.** The LCP medians move by tens of milliseconds in both
directions (mobile 597→569, desktop 564→627), which is inside the run-to-run
spread of the individual samples — there is no systematic direction, and this
report claims **no** LCP improvement.

## Sequential batches (3 runs per viewport per state)

Same picture: hero bytes identical per state (mobile 100,080; desktop and
769px tablet 139,882), one hero request per load, `link` initiator throughout.
The `BEFORE` batch ran first against a colder container, so its absolute LCPs
are higher; that is why the interleaved A/B above is the cited comparison.

## What did NOT reproduce here

**Firefox 156.0.1 headless/private did not perform the speculative fetch of the
non-matching desktop preload on the mobile viewport in any of the 6 mobile runs
(before or after).** Only `conexaobr_Hero_image_mobile.webp` was ever requested
at 390px, in both states. So:

* the mobile accidental-request measurement is **0 bytes before and 0 bytes
  after** in this environment — the change is byte-neutral here;
* the ~220 KiB → ~137 KiB reduction is a **bound that applies if the defect
  occurs**, demonstrated statically (the fallback now names the 139,882 B asset
  instead of the 225,488 B one), not as an observed saving;
* **no claim is made that the Firefox speculative-loader defect is fixed.**

## Independent engine — Chromium (Playwright), `chromium-BEFORE/AFTER.json`

`currentSrc` is the browser's own resolution result, so this is the selection
decision itself, not an inference. Identical before and after:

| Viewport | `currentSrc` (selected) | `naturalWidth` | Hero requests |
|---|---|---|---|
| 375 | `conexaobr_Hero_image_mobile.webp` | 900 | 1 (`link`, 100,080 B) |
| 768 | `conexaobr_Hero_image_mobile.webp` | 900 | 1 (`link`, 100,080 B) |
| **769** | `conexaobr_Hero_image-1600.webp` | 769 | 1 (`link`, 139,882 B) |
| 1024 | `conexaobr_Hero_image-1600.webp` | 1024 | 1 (`link`, 139,882 B) |
| 1440 | `conexaobr_Hero_image-1600.webp` | 1440 | 1 (`link`, 139,882 B) |

`naturalWidth` equalling the CSS width confirms `sizes="100vw"` and DPR-1
selection of the 1600w candidate. The 768/769 boundary is intact, exactly one
hero request is made per viewport, and no viewport downloads an asset it will
not display.


Measured on the committed assets, nothing regenerated or recompressed
(`stat -c%s` + WebP header parse), 2026-10-03, local checkout.

| Asset | Bytes | Dimensions | Role | Referenced by |
|---|---|---|---|---|
| `conexaobr_Hero_image-1600.webp` | 139,882 | 1600×595 | desktop DPR-1 candidate | `<picture>` desktop `srcset` (1600w) AND, after Stage 20, the desktop preload plain `href` |
| `conexaobr_Hero_image.webp` | 225,488 | 2057×764 | desktop DPR-2/wide candidate | `<picture>` desktop `srcset` (2057w) AND the desktop preload `imagesrcset` |
| `conexaobr_Hero_image_mobile.webp` | 100,080 | 900×500 | mobile LCP image | mobile preload `href` + `<picture>` mobile WebP source |
| `conexaobr_Hero_image.png` | 2,134,205 | 2057×764 | `<img>` fallback (desktop) | `<picture>` `<img src>` |
| `conexaobr_Hero_image_mobile.png` | — | 1683×935 | `<picture>` mobile PNG source | `<picture>` mobile PNG source |

Path: `wp-content/themes/conexao-br-irlanda/assets/images/`.

The Stage 20 fallback change therefore moves the defective-path cost from
225,488 B (220.2 KiB) to 139,882 B (136.6 KiB) — an 85,606 B (83.6 KiB) reduction
— **only if** a browser takes the fallback. It changes nothing on the normal
path, where `imagesrcset` is authoritative.

---

# Hero asset facts (Stage 20)

Measured on the committed assets; nothing regenerated, replaced or recompressed
(`stat -c%s` plus a WebP header parse), 2026-10-03, local checkout.

Path: `wp-content/themes/conexao-br-irlanda/assets/images/`.

| Asset | Bytes | Dimensions | Role | Referenced by |
|---|---|---|---|---|
| `conexaobr_Hero_image-1600.webp` | 139,882 | 1600×595 | desktop DPR-1 candidate | `<picture>` desktop `srcset` (1600w) AND, after Stage 20, the desktop preload plain `href` |
| `conexaobr_Hero_image.webp` | 225,488 | 2057×764 | desktop DPR-2/wide candidate | `<picture>` desktop `srcset` (2057w) AND the desktop preload `imagesrcset` |
| `conexaobr_Hero_image_mobile.webp` | 100,080 | 900×500 | mobile LCP image | mobile preload `href` + `<picture>` mobile WebP source |
| `conexaobr_Hero_image.png` | 2,134,205 | 2057×764 | `<img>` fallback (desktop) | `<picture>` `<img src>` |
| `conexaobr_Hero_image_mobile.png` | (unchanged) | 1683×935 | `<picture>` mobile PNG source | `<picture>` mobile PNG source |

The 1600w derivative named by the new fallback **already existed** and was
already in the `imagesrcset` ladder, so the change adds no asset and re-encodes
nothing. `test-hero-preload-fallback.php` asserts the hero asset set is exactly
these five files, and that the fallback asset is genuinely the smaller one
(139,882 B < 225,488 B) — "smaller" is a measured fact, not a filename claim.

Defective-path cost, should Firefox take the fallback: **225,488 B (220.2 KiB)
→ 139,882 B (136.6 KiB)**, a reduction of 85,606 B (83.6 KiB). Nothing on the
normal path changes, because `imagesrcset` stays authoritative.

