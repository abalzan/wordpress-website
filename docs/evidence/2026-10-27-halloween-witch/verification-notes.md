# Halloween witch browser evidence

Local page: `http://localhost:8080/apoiadores/` (local WordPress only).

## Asset and mid-flight checks

- Active Halloween marker was present; reduced motion was off.
- Local SVG request returned HTTP 200, `image/svg+xml`, 2,277 response bytes.
- The navigation handler produced a decorative, `aria-hidden` effect layer with
  an empty-alt image and exactly four sparkles. Computed animation name:
  `halloween-nav-witch-flight`; duration: `3s`; sampled progress: `1,500 ms`.
- The synthetic click was not cancelled (`defaultPrevented: false`). A prior
  natural anchor activation also completed same-origin navigation without
  `preventDefault()`.
- The interactive handler was exercised separately and produced the matching
  temporary DOM; the midpoint screenshot was inspected live in the browser.

## Responsive measurements

All samples were taken with the witch at the 1,500 ms midpoint. `documentScrollWidth`
is 15px below viewport width because the local browser reserves a scrollbar;
there was no horizontal page overflow at any width.

| Viewport | Witch width | Midpoint bounds (left–right, top) | Document scroll width | Overflow |
|---|---:|---|---:|---|
| 320×568 | 105.0px | 134.9–240.0px, 171.6px | 305px | no |
| 375×812 | 117.4px | 158.2–275.6px, 222.0px | 360px | no |
| 390×844 | 122.1px | 164.5–286.6px, 222.1px | 375px | no |
| 768×1024 | 143.4px | 324.4–467.8px, 222.6px | 753px | no |
| 1024×768 | 165.5px | 432.7–598.1px, 231.8px | 1009px | no |
| 1440×900 | 212.1px | 608.5–820.6px, 271.5px | 1425px | no |

## Accessibility

With `prefers-reduced-motion: reduce` emulated, the witch computed to
`display: none` and `animation-name: none`; the active element did not change.
The reduced-motion emulation was reset after the check.

## Visual inspection

The integrated browser screenshot was inspected with the witch paused at
1,500 ms. The screenshot tool displayed the live 485×303 browser viewport, but
its file-export path returned incorrectly clipped/scaled images, so no image is
archived. The responsive measurements above were recorded separately; reduced
motion was also checked in the browser.
