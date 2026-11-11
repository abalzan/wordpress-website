# Evidence — Christmas micro-animations (2026-11-08)

Machine evidence for `docs/reports/2026-11-08-christmas-micro-animations.md`.
Every claim in the report that carries a number points at a file here.

## Browser verifiers (opt-in, read-only, local site only)

| Directory / file | What it proves |
|---|---|
| `welcome/browser-verification.json` + 25 screenshots | The homepage welcome modal is unchanged: 546 assertions, 0 failures across 8 viewport profiles (320×568 → 1440×900 + landscape 844×390), PT + EN, public/preview/harness, reduced motion, no-JS, dismissal leaves nothing inline. Proves the twinkle added no popup regression. |
| `signature/browser-verification.json` + 8 screenshots | The `/natal/` + `/en/christmas/` landing pages are healthy: journey tabs, no horizontal overflow at 320/390/768/1366, reduced motion, **no console error / page error / failed request** from the new animation script. |
| `micro/micro-animations-verification.json` + 4 screenshots | The five animation effects themselves: welcome star twinkle applied once (`iteration-count=1`); landing content reveals on scroll; journey shared star pulses once; CTA sparkle spawns 3–5 aria-hidden particles by pointer AND keyboard; reduced motion hides nothing and suppresses the sparkle; no console/page error on either route. 14 assertions, 0 failures. |

## Screenshots (micro)

| File | Shows |
|---|---|
| `micro/twinkle-home-390x844.png` | Homepage welcome modal open with the twinkle-active star. |
| `micro/landing-normal.png` | Landing page, normal motion, sections revealed. |
| `micro/landing-sparkle-normal.png` | Golden sparkle cluster after CTA activation. |
| `micro/landing-reduce.png` | Landing page under `prefers-reduced-motion` — content fully visible, no motion. |

## Reproduce

```bash
python3 tests/browser/verify-christmas-welcome-browser.py --out /tmp/ev/welcome
python3 tests/browser/verify-christmas-signature-browser.py --out /tmp/ev/signature
python3 tests/browser/verify-christmas-micro-animations-browser.py --out /tmp/ev/micro
```

All three refuse a production host and write nothing to WordPress.

## Limitations

Chromium (Playwright) only — WebKit/Safari and physical devices were not exercised
(same limitation as the 2026-11-06 Christmas reports). The Christmas event window is
10–25 December; the landing composition was exercised on the local site where it
renders (verified present: journey/modules/closing markup on `/natal/`), and the
welcome modal through the engine's own signed preview.
