#!/usr/bin/env python3
"""Featured Apoiadores carousel — mobile compactness + reachability regression.

## Why this suite exists

The homepage Hero renders the Featured Apoiadores carousel inside a two-column
composition: on a phone the carousel's own column is only ~135px wide (the hero
copy keeps the larger share). Each Apoiador gets a pagination dot that is a
24x24px hit target (WCAG 2.5.8) laid out with `gap: 0`, so ten dots need 240px
plus padding — nearly twice the column. Measured at 360x800 with ten featured
Apoiadores, the control block was 258x34px: it overhung the carousel by ~60px
on each side, crossed into the hero copy column, and pushed its last two dots
past the viewport edge, where `.hero-section { overflow: hidden }` clipped them
— dot 10 no longer hit-tested to itself, i.e. its slide could not be reached by
tapping its own control.

That defect is invisible to a static check (the markup is correct), to an HTTP
request (the bytes are correct) and to the in-process layer (the DOM is
correct). Only a real layout engine can see it, so it is proven here in real
Chromium.

## What this suite proves

  Mobile (360x800, 375x812, 390x844, 412x915, 430x932), for the server-rendered
  carousel AND for a production-like ten-dot carousel:

    1. one dot per slide, every dot a real `<button>`;
    2. every target is at least 24x24px and same-row centres are >= 24px apart
       (targets never overlap — the two wrapped rows are offset by one grid
       step, so only the same-row horizontal gap is meaningful);
    3. the dot block stays INSIDE the carousel stage (it never overhangs);
    4. the dot block stays inside the phone budget (<= 56px tall — the wrapped
       two-row layout measures 50px with ten dots);
    5. the controls add no layout height (carousel height <= tile height + slack);
    6. every dot is inside the viewport and hit-tests to itself (reachable);
    7. no page-level horizontal overflow, and the block never meets the hero
       copy column.

  Accessibility (390x844 and 1440x1000):

    8. the group keeps `role="group"` + an accessible name; every dot keeps a
       unique `aria-label` naming its Apoiador;
    9. exactly one dot is `aria-current="true"`, and it is the `.is-active` one;
   10. Tab reaches the dots and the focus ring is really painted;
   11. ArrowRight/ArrowLeft/Home/End move the active dot (carousel keyboard nav);
   12. the polite live region keeps announcing "Apoiador N de M";
   13. the LAST dot is clickable and selects the last slide (direct navigation to
       every slide still works — nothing became decorative);
   14. exactly two arrows and no duplicated/extra interactive carousel controls.

  Desktop (1440x1000): the dots keep their documented desktop presentation
  (38px tall block, `6px 10px` padding, one row, 8px visual dot, 20x8px active
  pill, 10px inset from the tile bottom, 24px centres) — the mobile fix must not
  be a desktop regression, and this check is deliberately excluded from the
  negative control so it proves the mutation is scoped to mobile.

## Negative control (why this is not a vacuous test)

`--negative-control` injects a `<style>` block inside `@media (max-width: 768px)`
that restores the PRE-FIX mobile rules (single flex row, `padding: 4px 8px`,
`left: 50%`, `translateX(-50%)`, 6px/16px visual dots). The very same checks are
then run and MUST FAIL — the suite reports the failure and exits 0 only if the
expected checks went red. If they still passed, the suite would be measuring
nothing.

## Deployment / conventions

Like the other suites in this directory this one is an OPT-IN browser layer: it
is not discovered by `./scripts/run-tests.sh` (that runner never launches a
browser). It is executed explicitly:

    python3 tests/browser/verify-sponsor-carousel-mobile-browser.py
    python3 tests/browser/verify-sponsor-carousel-mobile-browser.py --negative-control

It is read-only: it only ever talks to the LOCAL stack (`CONEXAO_TEST_BASE_URL`,
default http://localhost:8080), refuses a non-local host before opening a socket,
creates no WordPress content and writes nothing outside stdout. Where the local
database has fewer featured Apoiadores than production it clones the existing dot
buttons in the DOM (`--dots`, default 10) purely to reach the reported count; the
clones carry the same markup contract, and the interaction checks run on the
server-rendered DOM.

Exit codes: 0 pass, 1 fail, 2 environment unavailable (Playwright or the local
site). A 2 is never reported as a pass.
"""

from __future__ import annotations

import argparse
import os
import re
import sys
import urllib.error
import urllib.request

BASE = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
ALLOWED_HOSTS = {"localhost", "127.0.0.1"}

# The three bands the component has to survive.
PHONE_VIEWPORTS = [(360, 800), (375, 812), (390, 844), (412, 915), (430, 932)]
BLOCK_EDGE_VIEWPORT = (768, 1024)  # last width where the <=768px block applies
DESKTOP_VIEWPORT = (1440, 1000)

# Measured design bounds. MAX_DOT_BLOCK_HEIGHT is the compactness budget: the
# wrapped layout measures 50px with ten dots and 26px with four, so 56px allows
# one extra wrapped row while still failing if the controls grow back into a
# large navigation block. STAGE_SLACK absorbs sub-pixel rounding of the stage.
MAX_DOT_BLOCK_HEIGHT_PHONE = 56.0
MAX_DOT_BLOCK_HEIGHT_BLOCK_EDGE = 56.0
STAGE_SLACK = 0.5
CAROUSEL_SLACK = 12.0
MIN_TARGET = 24.0
MIN_CENTER_DISTANCE = 24.0

# The documented desktop presentation, asserted unchanged.
DESKTOP_BLOCK_HEIGHT = 38.0
DESKTOP_BLOCK_PADDING = "6px 10px"
DESKTOP_DOT_VISUAL = (8.0, 8.0)
DESKTOP_ACTIVE_VISUAL = (20.0, 8.0)
DESKTOP_TILE_INSET = 10.0

# The pre-fix mobile rules (B-original shape, verified numerically at git HEAD
# on 2026-10-08: at 360x800 the 10-dot block measures 258x34px, overhangs the
# ~135px carousel column by ~60px per side, and pushes dot 10 off-viewport
# where .hero-section{overflow:hidden} clips it), injected by
# --negative-control. Scoped to the same media query the fix lives in, so a
# desktop check must stay green.
REGRESSION_CSS = """
@media (max-width: 768px) {
  /* Pre-fix mobile rules (B-original, git HEAD): one flex row of 24px targets
     (`gap: 0`) centred with left:50% + translateX(-50%). At 360x800 the 10-dot
     block measured 258x34px, overhanging the ~135px carousel column by ~60px
     on each side and pushing the last 2 dots past the viewport, where
     .hero-section{overflow:hidden} clipped them (dot 10 no longer hit-tested
     to itself -> unreachable). */
  .hero-sponsors .sponsors-carousel-dots {
    left: 50%;
    right: auto;
    width: auto;
    max-width: none;
    margin: 0;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 0;
    padding: 4px 8px;
    bottom: 8px;
  }
  .hero-sponsors .sponsors-carousel-dot::before { width: 6px; height: 6px; }
  .hero-sponsors .sponsors-carousel-dot.is-active::before { width: 16px; }
}
"""

PASSED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
        print(f"  PASS: {message}")
    else:
        FAILURES.append(message)
        print(f"  FAIL: {message}")
    return bool(condition)


def refuse_non_local() -> None:
    """Refuse a non-local host before any socket is opened."""
    host = re.sub(r"^[a-z]+://", "", BASE).split("/")[0].split(":")[0]
    if host not in ALLOWED_HOSTS:
        print(f"refusing to run against a non-local host: {host}", file=sys.stderr)
        sys.exit(2)


def local_site_available() -> bool:
    try:
        with urllib.request.urlopen(BASE + "/", timeout=15) as resp:
            return resp.status < 500
    except (urllib.error.URLError, OSError):
        return False


def load_playwright():
    """Return sync_playwright, or exit 2 when the environment cannot provide it."""
    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        print("environment unavailable: Playwright is not installed", file=sys.stderr)
        sys.exit(2)
    return sync_playwright



PROBE = r"""
(args) => {
  const px = (v) => Math.round((parseFloat(v) || 0) * 100) / 100;
  const rect = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: px(r.x), y: px(r.y), w: px(r.width), h: px(r.height),
             top: px(r.top), bottom: px(r.bottom), left: px(r.left), right: px(r.right) };
  };
  const root = document.querySelector('.sponsors-carousel--hero');
  if (!root) return { present: false };

  const dots = root.querySelector('.sponsors-carousel-dots');
  const dotEls = dots ? Array.from(dots.querySelectorAll('.sponsors-carousel-dot')) : [];
  if (args.synthesize > 0 && dots && dotEls.length && dotEls.length < args.synthesize) {
    while (dots.querySelectorAll('.sponsors-carousel-dot').length < args.synthesize) {
      const n = dots.querySelectorAll('.sponsors-carousel-dot').length;
      const clone = dotEls[n % dotEls.length].cloneNode(true);
      clone.classList.remove('is-active');
      clone.removeAttribute('aria-current');
      clone.setAttribute('data-sponsors-dot', String(n));
      clone.setAttribute('aria-label', 'Ir para Apoiador ' + (n + 1));
      dots.appendChild(clone);
    }
  }

  const all = dots ? Array.from(dots.querySelectorAll('.sponsors-carousel-dot')) : [];
  const rects = all.map(rect);
  // Same-row gaps, not diagonal adjacency: the two wrapped rows are offset
  // by one grid step, so the last dot of row 0 sits ~24px diagonally from the
  // first dot of row 1 while the 24x24px targets never touch. WCAG 2.5.8 only
  // forbids overlapping targets, so |dx| between centres that share a row is
  // the honest number here.
  const rows = {};
  rects.forEach((r) => {
    const key = Math.round(r.y / 4);
    (rows[key] = rows[key] || []).push(r);
  });
  const rowGaps = [];
  Object.values(rows).forEach((row) => {
    const xs = row.map((r) => r.x + r.w / 2).sort((a, b) => a - b);
    for (let i = 1; i < xs.length; i++) rowGaps.push(px(xs[i] - xs[i - 1]));
  });

  const stage = root.querySelector('.sponsors-carousel-stage');
  const tile = root.querySelector('.sponsor-tile');
  const viewport = root.querySelector('.sponsors-carousel-viewport');
  const copy = document.querySelector('.hero-copy');
  const status = root.querySelector('[data-sponsors-status]');
  const cs = (el, prop) => (el ? getComputedStyle(el)[prop] : null);
  // The inactive dot's visual is what a plain dot looks like: dot 0 starts
  // active, so measuring `dots[0]` alone would report the ACTIVE pill twice.
  const inactiveEl = all.find((d) => !d.classList.contains('is-active')) || all[0];
  const dotBefore = inactiveEl ? getComputedStyle(inactiveEl, '::before') : null;
  const activeEl = all.find((d) => d.classList.contains('is-active'));
  const activeBefore = activeEl ? getComputedStyle(activeEl, '::before') : null;
  const group = rect(dots);
  const tileBox = rect(tile);
  const stageBox = rect(stage);
  const viewportBox = rect(viewport);

  return {
    present: true,
    viewport: { w: window.innerWidth, h: window.innerHeight },
    overflowPx: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    stage: stageBox,
    viewportBox: viewportBox,
    tile: tileBox,
    carousel: rect(root),
    group: group,
    groupPadding: dots ? cs(dots, 'padding') : null,
    groupDisplay: dots ? cs(dots, 'display') : null,
    dotCount: all.length,
    slideCount: root.querySelectorAll('.sponsors-slide').length,
    dotBox: rects[0] || null,
    dotBoxes: rects,
    dotTag: all.length ? all[0].tagName : null,
    dotMinWidth: rects.length ? Math.min.apply(null, rects.map((r) => r.w)) : 0,
    dotMinHeight: rects.length ? Math.min.apply(null, rects.map((r) => r.h)) : 0,
    dotGapMin: rowGaps.length ? Math.min.apply(null, rowGaps) : null,
    dotVisual: dotBefore ? { w: px(dotBefore.width), h: px(dotBefore.height) } : null,
    activeVisual: activeBefore ? { w: px(activeBefore.width), h: px(activeBefore.height) } : null,
    activeCount: all.filter((d) => d.classList.contains('is-active')).length,
    ariaCurrentCount: all.filter((d) => d.getAttribute('aria-current') === 'true').length,
    ariaCurrentIsActive: (() => {
      const cur = all.filter((d) => d.getAttribute('aria-current') === 'true');
      return cur.length === 1 && cur[0].classList.contains('is-active');
    })(),
    activeIndex: all.findIndex((d) => d.getAttribute('aria-current') === 'true'),
    labels: all.map((d) => d.getAttribute('aria-label')),
    labelsUnique: new Set(all.map((d) => d.getAttribute('aria-label'))).size === all.length,
    groupRole: dots ? dots.getAttribute('role') : null,
    groupLabel: dots ? (dots.getAttribute('aria-label') || '') : '',
    arrowCount: root.querySelectorAll('.sponsors-carousel-arrow').length,
    arrowLabels: Array.from(root.querySelectorAll('.sponsors-carousel-arrow'))
      .map((a) => a.getAttribute('aria-label') || ''),
    dotAttrCount: root.querySelectorAll('[data-sponsors-dot]').length,
    copyRight: copy ? rect(copy).right : null,
    copyLeft: copy ? rect(copy).left : null,
    reach: all.map((el, i) => {
      const r = rects[i];
      const cx = r.x + r.w / 2;
      const cy = r.y + r.h / 2;
      const inViewport = cx >= 0 && cx <= window.innerWidth && cy >= 0 && cy <= window.innerHeight;
      const hit = document.elementFromPoint(cx, cy);
      return { i: i, inViewport: inViewport, self: hit === el || (!!hit && el.contains(hit)) };
    }),
    clippedCount: rects.filter((r) => r.left < 0 || r.right > window.innerWidth).length,
    statusText: status ? status.textContent : null,
    statusLive: status ? status.getAttribute('aria-live') : null,
    scrollLeft: viewport ? px(viewport.scrollLeft) : null,
    maxScroll: viewport ? px(viewport.scrollWidth - viewport.clientWidth) : null,
  };
}
"""


def probe(page, synthesize=0):
    return page.evaluate(PROBE, {"synthesize": synthesize})



def mobile_checks(page, width, height, synthesize, budget, label):
    """Assert the compact + reachable contract for one mobile viewport."""
    d = probe(page, synthesize)
    tag = f"{label} {width}x{height}"
    if not check(d.get("present"), f"{tag}: the Featured Apoiadores carousel renders"):
        return d

    expected = max(synthesize, d["slideCount"]) if synthesize else d["slideCount"]
    check(d["dotCount"] == expected,
          f"{tag}: one dot per Apoiador ({d['dotCount']} buttons for {d['slideCount']} slides)")
    check(d["dotCount"] >= 2,
          f"{tag}: the carousel has navigation dots at all ({d['dotCount']})")
    check(d["dotTag"] == "BUTTON",
          f"{tag}: every dot is a real <button> (tag={d['dotTag']})")
    check(d["dotAttrCount"] == d["dotCount"],
          f"{tag}: no duplicate/extra dot controls ({d['dotAttrCount']} data-sponsors-dot)")
    check(d["dotMinWidth"] >= MIN_TARGET and d["dotMinHeight"] >= MIN_TARGET,
          f"{tag}: every touch target is at least 24x24px "
          f"(min {d['dotMinWidth']}x{d['dotMinHeight']})")
    check(d["dotGapMin"] is not None and d["dotGapMin"] >= MIN_CENTER_DISTANCE,
          f"{tag}: targets never overlap — centres are at least 24px apart "
          f"horizontally in their row (min {d['dotGapMin']})")

    g, s, t, c = d["group"], d["stage"], d["tile"], d["carousel"]
    check(g["left"] >= s["left"] - STAGE_SLACK and g["right"] <= s["right"] + STAGE_SLACK,
          f"{tag}: the dot block stays inside the carousel stage "
          f"(block {g['left']}..{g['right']} vs stage {s['left']}..{s['right']})")
    check(g["w"] <= s["w"] + STAGE_SLACK,
          f"{tag}: the dot block is no wider than the carousel "
          f"({g['w']} <= {s['w']})")
    check(g["h"] <= budget,
          f"{tag}: the dot block stays compact ({g['h']}px <= {budget}px)")
    check(c["h"] <= t["h"] + CAROUSEL_SLACK,
          f"{tag}: the controls add no layout height "
          f"(carousel {c['h']}px <= tile {t['h']}px + {CAROUSEL_SLACK})")
    check(d["overflowPx"] == 0,
          f"{tag}: no horizontal page overflow (scrollWidth-clientWidth={d['overflowPx']})")
    check(d["clippedCount"] == 0,
          f"{tag}: no dot is pushed outside the viewport ({d['clippedCount']} clipped)")
    unreachable = [r["i"] + 1 for r in d["reach"] if not (r["inViewport"] and r["self"])]
    check(not unreachable,
          f"{tag}: every dot is on screen and hit-tests to itself "
          f"(unreachable: {unreachable or 'none'})")
    if d["copyRight"] is not None:
        check(g["left"] >= d["copyRight"],
              f"{tag}: the controls never reach into the hero copy column "
              f"(block left {g['left']} >= copy right {d['copyRight']})")
    return d


def accessibility_checks(page, width, height, synthesize, label):
    """Assert the carousel keeps its whole accessibility model."""
    d = probe(page, synthesize)
    tag = f"{label} {width}x{height}"
    if not check(d.get("present"), f"{tag}: carousel present for a11y checks"):
        return d

    check(d["groupRole"] == "group" and bool(d["groupLabel"]),
          f"{tag}: the dots keep role=group and an accessible name "
          f"(role={d['groupRole']}, label={d['groupLabel']!r})")
    check(all(d["labels"]) and d["labelsUnique"],
          f"{tag}: every dot keeps its own accessible name "
          f"({d['dotCount']} labels, unique={d['labelsUnique']})")
    named = all(re.match(r"^.+ \d+$", lab or "") for lab in d["labels"])
    check(named, f"{tag}: each dot label names its Apoiador (e.g. {d['labels'][0]!r})")
    check(d["ariaCurrentCount"] == 1 and d["activeCount"] == 1,
          f"{tag}: exactly one dot is active (aria-current={d['ariaCurrentCount']}, "
          f"is-active={d['activeCount']})")
    check(d["ariaCurrentIsActive"],
          f"{tag}: aria-current and .is-active point at the same dot")
    check(d["arrowCount"] == 2 and all(d["arrowLabels"]),
          f"{tag}: the two arrow controls keep their accessible names {d['arrowLabels']}")
    check(d["statusLive"] == "polite",
          f"{tag}: the carousel status stays a polite live region "
          f"(aria-live={d['statusLive']!r})")
    return d


FOCUS_CSS = """
() => {
  const el = document.activeElement;
  if (!el) return { tag: 'none', cls: '', outlineWidth: '0px', outlineStyle: 'none', outlineColor: '' };
  const s = getComputedStyle(el);
  const r = el.getBoundingClientRect();
  return {
    tag: el.tagName,
    cls: (el.className || '').toString(),
    outlineWidth: s.outlineWidth,
    outlineStyle: s.outlineStyle,
    outlineColor: s.outlineColor,
    insideViewport: r.left >= -0.5 && r.right <= window.innerWidth + 0.5,
  };
}
"""


def interaction_checks(page, width, height, label):
    """Assert keyboard navigation, focus rings, the live region and dot clicks."""
    tag = f"{label} {width}x{height}"
    page.evaluate("document.querySelector('.sponsors-carousel-viewport').focus()")
    state = probe(page)
    dots_total = state["dotCount"]
    slides_total = state["slideCount"]

    page.keyboard.press("ArrowRight")
    page.wait_for_timeout(300)
    d = probe(page)
    check(d["activeIndex"] == 1,
          f"{tag}: ArrowRight advances to the next Apoiador (active index {d['activeIndex']})")
    check(d["ariaCurrentCount"] == 1 and d["ariaCurrentIsActive"],
          f"{tag}: ArrowRight keeps exactly one synced active dot")

    page.keyboard.press("End")
    page.wait_for_timeout(300)
    d = probe(page)
    check(d["activeIndex"] == d["dotCount"] - 1,
          f"{tag}: End jumps to the last Apoiador (active index {d['activeIndex']})")
    check(bool(re.match(r"^.+\s\d+\s.+\s\d+$", d["statusText"] or "")),
          f"{tag}: the live region announces the position ({d['statusText']!r})")

    page.keyboard.press("Home")
    page.wait_for_timeout(300)
    d = probe(page)
    check(d["activeIndex"] == 0,
          f"{tag}: Home returns to the first Apoiador (active index {d['activeIndex']})")

    # Direct navigation: the LAST dot must select the LAST slide. Selected by
    # position, never by a hard-coded index, so the local featured count can
    # differ from production without weakening the check.
    page.locator(".sponsors-carousel-dot").last.click()
    page.wait_for_timeout(400)
    d = probe(page)
    check(d["activeIndex"] == d["dotCount"] - 1,
          f"{tag}: clicking the last dot selects the last Apoiador "
          f"(active index {d['activeIndex']} of {d['dotCount']})")
    check(d["maxScroll"] is not None and abs(d["scrollLeft"] - d["maxScroll"]) <= 2,
          f"{tag}: the track really scrolled to the last slide "
          f"(scrollLeft {d['scrollLeft']} of {d['maxScroll']})")

    # Keyboard reachability + a painted focus ring, walking forward from the
    # viewport so every focus step is keyboard-initiated (:focus-visible). The
    # budget covers the slide links, the two arrows and every dot.
    page.evaluate("document.querySelector('.sponsors-carousel-viewport').focus()")
    seen = []
    first_dot = None
    for _ in range(dots_total + slides_total + 6):
        page.keyboard.press("Tab")
        focused = page.evaluate(FOCUS_CSS)
        if "sponsors-carousel-dot" in focused["cls"]:
            seen.append(focused)
            if first_dot is None:
                first_dot = focused
    check(len(seen) >= dots_total,
          f"{tag}: keyboard Tab reaches every dot ({len(seen)} of {dots_total})")
    ring_width = 0.0
    if first_dot:
        try:
            ring_width = float((first_dot["outlineWidth"] or "0px").replace("px", ""))
        except ValueError:
            ring_width = 0.0
    check(first_dot is not None and first_dot["outlineStyle"] != "none" and ring_width >= 2,
          f"{tag}: the keyboard focus ring is painted on a dot "
          f"({(first_dot or {}).get('outlineWidth')} {(first_dot or {}).get('outlineStyle')} "
          f"{(first_dot or {}).get('outlineColor')})")
    check(all(f["insideViewport"] for f in seen),
          f"{tag}: every keyboard-focusable dot sits inside the viewport")


def desktop_checks(page, synthesize):
    """Assert the documented desktop presentation is unchanged."""
    d = probe(page, synthesize)
    tag = "desktop 1440x1000"
    if not check(d.get("present"), f"{tag}: carousel present"):
        return d
    check(d["groupPadding"] == DESKTOP_BLOCK_PADDING,
          f"{tag}: the dots keep their desktop padding {DESKTOP_BLOCK_PADDING!r} "
          f"(got {d['groupPadding']!r})")
    check(d["dotVisual"] == {"w": DESKTOP_DOT_VISUAL[0], "h": DESKTOP_DOT_VISUAL[1]},
          f"{tag}: the visual dot stays {DESKTOP_DOT_VISUAL} (got {d['dotVisual']})")
    check(d["activeVisual"] == {"w": DESKTOP_ACTIVE_VISUAL[0], "h": DESKTOP_ACTIVE_VISUAL[1]},
          f"{tag}: the active pill stays {DESKTOP_ACTIVE_VISUAL} (got {d['activeVisual']})")
    check(d["group"]["h"] == DESKTOP_BLOCK_HEIGHT,
          f"{tag}: the dot block stays {DESKTOP_BLOCK_HEIGHT}px tall "
          f"(got {d['group']['h']}px — one row, never wrapped)")
    expected_width = d["dotCount"] * MIN_TARGET + 20 + 2
    check(d["group"]["w"] == expected_width,
          f"{tag}: the row stays {d['dotCount']}x24px + 6px 10px padding + border "
          f"= {expected_width}px wide (got {d['group']['w']}px)")
    inset = d["tile"]["bottom"] - d["group"]["bottom"]
    check(inset == DESKTOP_TILE_INSET,
          f"{tag}: the block keeps its {DESKTOP_TILE_INSET}px inset from the tile bottom "
          f"(got {inset}px)")
    check(d["group"]["left"] >= d["tile"]["left"] and d["group"]["right"] <= d["tile"]["right"],
          f"{tag}: the block stays inside the tile")
    check(d["dotGapMin"] >= MIN_CENTER_DISTANCE,
          f"{tag}: dot centres stay {MIN_CENTER_DISTANCE}px apart in their row "
          f"(got {d['dotGapMin']})")
    check(d["overflowPx"] == 0, f"{tag}: no horizontal overflow")
    return d



def run(args) -> int:
    refuse_non_local()
    if not local_site_available():
        print(f"environment unavailable: no local site at {BASE}", file=sys.stderr)
        return 2
    sync_playwright = load_playwright()

    failures_before_desktop = 0
    mobile_viewports = PHONE_VIEWPORTS + [BLOCK_EDGE_VIEWPORT]
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for (width, height) in mobile_viewports:
            budget = (MAX_DOT_BLOCK_HEIGHT_PHONE if (width, height) in PHONE_VIEWPORTS
                      else MAX_DOT_BLOCK_HEIGHT_BLOCK_EDGE)
            # Reduced motion keeps the hero autoplay out of the assertions (the
            # component disables its own cycle and manual navigation still works),
            # so every measurement here is deterministic.
            context = browser.new_context(viewport={"width": width, "height": height},
                                          reduced_motion="reduce", device_scale_factor=1)
            page = context.new_page()
            page.set_default_timeout(60000)
            page.goto(BASE + "/", wait_until="domcontentloaded", timeout=90000)
            page.wait_for_selector(".sponsors-carousel--hero", timeout=60000)
            page.wait_for_timeout(700)
            if args.negative_control:
                page.add_style_tag(content=REGRESSION_CSS)
                page.wait_for_timeout(200)
            print(f"\n-- mobile {width}x{height}")
            mobile_checks(page, width, height, 0, budget, "as rendered")
            mobile_checks(page, width, height, args.dots,
                          budget, f"synthetic {args.dots}-dot")
            context.close()

        # Interaction + accessibility proof, on one phone viewport and desktop.
        for (width, height) in [(390, 844), DESKTOP_VIEWPORT]:
            context = browser.new_context(viewport={"width": width, "height": height},
                                          reduced_motion="reduce", device_scale_factor=1)
            page = context.new_page()
            page.set_default_timeout(60000)
            page.goto(BASE + "/", wait_until="domcontentloaded", timeout=90000)
            page.wait_for_selector(".sponsors-carousel--hero", timeout=60000)
            page.wait_for_timeout(700)
            if args.negative_control and (width, height) != DESKTOP_VIEWPORT:
                page.add_style_tag(content=REGRESSION_CSS)
                page.wait_for_timeout(200)
            print(f"\n-- accessibility + interaction {width}x{height}")
            accessibility_checks(page, width, height, 0, "a11y")
            interaction_checks(page, width, height, "keyboard")
            context.close()

        failures_before_desktop = len(FAILURES)

        context = browser.new_context(viewport={"width": DESKTOP_VIEWPORT[0],
                                               "height": DESKTOP_VIEWPORT[1]},
                                      reduced_motion="reduce", device_scale_factor=1)
        page = context.new_page()
        page.set_default_timeout(60000)
        page.goto(BASE + "/", wait_until="domcontentloaded", timeout=90000)
        page.wait_for_selector(".sponsors-carousel--hero", timeout=60000)
        page.wait_for_timeout(700)
        print(f"\n-- desktop {DESKTOP_VIEWPORT[0]}x{DESKTOP_VIEWPORT[1]}")
        desktop_checks(page, 0)
        desktop_checks(page, args.dots)
        context.close()
        browser.close()

    desktop_failures = len(FAILURES) - failures_before_desktop

    print("\n== summary ==")
    print(f"assertions passed: {PASSED}")
    print(f"assertions failed: {len(FAILURES)}")
    if args.negative_control:
        print("negative control: the pre-fix mobile CSS was re-injected")
        print(f"  mobile/accessibility failures (expected > 0): {failures_before_desktop}")
        print(f"  desktop failures (expected 0): {desktop_failures}")
        if failures_before_desktop > 0 and desktop_failures == 0:
            print("NEGATIVE CONTROL OK: the regression is detected, scoped to mobile")
            return 0
        print("NEGATIVE CONTROL FAILED: the mutated mobile CSS was not detected")
        return 1
    if FAILURES:
        print("FAIL: the mobile carousel regression suite has failures")
        return 1
    print("PASS: mobile carousel is compact, contained and fully reachable")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--negative-control", "--self-test", action="store_true",
                        dest="negative_control",
                        help="re-inject the pre-fix mobile CSS and require the checks to fail")
    parser.add_argument("--dots", type=int, default=10,
                        help="production-like dot count measured on mobile/desktop (default 10)")
    args = parser.parse_args()
    return run(args)


if __name__ == "__main__":
    sys.exit(main())

