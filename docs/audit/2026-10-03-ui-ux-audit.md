# UI / UX audit — Conexão BR Irlanda

> **Status: evaluation only.** No production code, route, content, translation or
> design change was made by this audit. The working tree was clean before and
> after (`git rev-parse --short HEAD` = `6ec382b`).
>
> **Method:** 24 real pages × 2 viewports (1440×1000 desktop, 390×844 mobile)
> rendered in Chromium against the local Docker site, plus computed-style
> extraction and CSS source inventory. Machine data:
> [`docs/evidence/2026-10-03-ui-audit/`](../evidence/2026-10-03-ui-audit/).

| | |
|---|---|
| **Task** | UI / UX evaluation and design-direction recommendation |
| **Date** | 2026-10-03 |
| **Branch** | `master` |
| **SHA** | `6ec382b` (unchanged) |
| **Skills followed** | `wp-repository` (orientation, scope, evidence), `wp-update-docs` |
| **Authoritative docs read** | `AGENTS.md`, `plugins.json`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/routing.md`, `docs/content-model.md`, `docs/engineering-standard.md` |

---

## 1. The short answer

The site is **not** visually broken and does **not** need a redesign. It has a
real, tokenised design system (`design-system.css`, 684 lines of `:root` custom
properties), a coherent green/orange/navy brand, a genuinely good mobile menu, an
excellent filter system with real ARIA semantics, dark mode, and responsive text
that respects both languages.

What it lacks is **discipline in applying that system**. Measured across 24 real
pages, the same *concept* is rendered **up to 7 different ways**. That is the
whole diagnosis. The site reads as "unfinished" not because of any one style, but
because nothing is enforced.

**The highest-value work is Tier 1 — convergence, not invention.** Most of the
perceived "dated" quality comes from five small, evidence-backed inconsistencies
(§5) plus two genuine bugs (§4.2) that no amount of aesthetic restyling fixes.

---

## 2. What is already working well

These constrain any future direction and must be preserved.

| Area | Evidence |
|---|---|
| **Design tokens exist and are correct** | `design-system.css` defines colour aliases, a 9-step spacing scale, 5 radii, 5 shadows, 3 transitions and a focus ring; `main.css:6-65` holds the raw palette. This satisfies the engineering-standard §3.1 "tokens are the only source" MUST. |
| **Type pairing is right** | Poppins (display) + Inter (body) is a correct editorial pairing. Body is a **single** treatment across all 24 pages (measured `Inter 16px/25.6px` — 1 distinct value, not 24). |
| **Container and grid are consistent** | `.site-container` = `min(1200px, 100% − 48px)`, 16px below 768px, declared once and honoured everywhere. |
| **Mobile navigation is excellent** | Full-screen overlay, 9 links at **61px** tall rows (far above the 44px target), plus search, CTA and social. Verified by measurement. |
| **Filter UX is a genuine strength** | Real `aria-multiselectable` listboxes, focus trap, Escape/backdrop close, focus return, abortable in-place requests, result counts doubling as `aria-live`. `/lazer/` filters 246 items with no page reload. |
| **Dark mode is complete** | `dark-mode.css` is 2 569 lines — as large as the main stylesheet. Verified: body `#0f1720`, card `#16202b`, footer `#0a1118`. No per-page hacks. |
| **Responsive text is fluid** | `clamp()` throughout; `h1` moves 44px → 28px on mobile. No fixed pixel page heights. |
| **Zero horizontal overflow** | Measured `scrollWidth − clientWidth = 0` on **all 48 renders**. |
| **LCP is well engineered** | Hero preloaded, `fetchpriority="high"`, non-render-blocking fonts, `srcset`/`sizes` ladder. |
| **Reduced motion handled** | Dedicated `prefers-reduced-motion` blocks in `design-system.css:676` and `main.css`. |
| **Filter chips are real links** | Mid-size targets (36px) are the one deliberate exception, documented against WCAG 2.5.8. |

---

## 3. The visual system as actually implemented

### 3.1 Tokens (`main.css:6-65`, `design-system.css:10-81`)

- **Colour:** primary `#0E6B3A`, accent `#F68B1F`, ink `#1a1a2e`, neutrals 100–900,
  surface `#fff`, muted `#F5F7F8`, border `#e5e7eb`.
- **Type scale:** 9 steps, 0.75rem → 3rem.
- **Spacing:** 9 steps on a 4px base.
- **Radius:** 8 / 12 / 16 / 24 / full. **Shadow:** 5 soft levels.
- **Conflict:** `main.css:2932` gives `.post-card` a `box-shadow`, while
  `design-system.css:350` gives `.card` a border and none — the same conceptual
  card with two surface treatments.

### 3.2 Where the implicit system breaks

The tokens are sound; **bypasses** are the problem.

| Bypass | Count | Example |
|---|---|---|
| Off-scale font sizes | `1.0625rem` ×12, `0.625rem` ×4, `1.05rem`, `1.15rem` | `main.css:2998` `.post-card-title { font-size: 1.0625rem }` — 17px, on no step of the scale |
| Hardcoded padding | `4px 10px` ×8, `8px 10px` ×4, `18px 20px` ×3 … | `main.css:2986` `.post-card-body { padding: 20px }` vs `.card-body { padding: var(--space-5) }` = 24px |
| Hardcoded radii | `2px` ×10, `3px` ×1 | Bypasses the `--radius-*` tokens entirely |
| Raw palette colour | `gray-500` in 14 rules | `main.css:2018, 2139, 2514, 2927…` |

**Breakpoints** are the other soft spot. The documented contract is two (768px /
769px), but the source uses **13 distinct** values: 768 ×25, 769 ×10, 640 ×7,
600 ×5, 1199 ×4, 1024 ×4, 800 ×3, 480 ×3, 1280 ×2, 782 ×2, plus 160, 92, 76.
---

## 4. Measured findings

### 4.1 Design-system divergence across pages

Extracted from live computed styles. The left column counts **distinct rendered
treatments for that slot across all 24 pages**.

| Slot | Desktop | Mobile | Verdict |
|---|---|---|---|
| Body | **1** | **1** | Exemplary |
| Card surface | 2 | 2 | Good — differs only by shadow on the homepage |
| Metadata | 2 | 2 | Acceptable |
| Card title | **4** | **4** | Divergent (weight 600 vs 700; 17 / 18 / 18.4px) |
| Button | 3 | 3 | Reasonable — genuinely different button roles |
| Page `h1` | 4 | 3 | Divergent — see §4.2 |
| **Section `h2`** | **6** | **7** | **The single worst inconsistency** |

The desktop `h2` spread, measured:

| Size / weight | Pages |
|---|---|
| 17px / 600 | blog, guides, search, sponsors (×2 languages) |
| 40px / 700 white | courses, events, events-filtered |
| 40px / 700 ink | contact, home |
| 28px / 700 | jobs |
| 18px / 700 | leisure |
| 16.8px / 700 | leisure-single |

**A section heading ranges from 16.8px to 40px — a 2.4× spread — across seven
sibling pages of the same site.** Four of those are the *same* semantic element.
`design-system.css:98-108` defines `.section-title { clamp(1.5rem, 3vw, 2rem) }`
and it is overridden or ignored almost everywhere.

### 4.2 Defects found

**D1 — `/empregos/` renders an orphan column with 60% dead space.**
`.empregos-landing` (`main.css:6891`) is
`grid-template-columns: minmax(220px,340px) minmax(0,1fr)`, designed for a portrait
illustration in column 1. The portrait is **absent** (`mediaPresent: False`), so
body content falls into the 340px column and 812px sit empty. Measured:
`grid: '340px 812px'`, `paraW: 340`, at 1.0625rem/1.8. Affects `/empregos/` **and**
`/en/empregos/`. Visually this reads as a broken page — the most damaging single
defect found.

**D2 — Duplicate event cards.** `/eventos/` renders 10 cards from 6 distinct events:

```
2 × Mick Creagh One Man Show        2 × Kirtan Practice At Arunachala
2 × Happy Hiking Hill Skills Day    2 × Bingo Loco At Midlands Park Hotel
```

Recurring occurrences each emit their own card, so the most visible content on the
archive is duplicated. Whether recurring events should occupy one card with a "next
occurrence" line is a product decision, but the **current** outcome reads as broken.

**D3 — Accent orange fails WCAG AA as text and as a surface.**

| Pair | Ratio | Result |
|---|---|---|
| `#F68B1F` on white | **2.43:1** | FAIL (AA needs 4.5:1) |
| white on `#F68B1F` | **2.43:1** | FAIL |
| `#F68B1F` on `#fff8f0` | **2.31:1** | FAIL |
| `#adb5bd` (gray-500) on white | **2.07:1** | FAIL |
| `#0E6B3A` on white | 6.60:1 | Pass |
| `#6c757d` on white | 4.69:1 | Pass |
| `#1a1a2e` on white | 17.06:1 | Pass |

Orange is used as a text colour in **17 rules** (`main.css:279, 984, 1530, 1785,
2001, 2040, 2087, 3132, 3275, 3970, 4768…`) and as a background under white text in
the CTA, the card category pill, the footer border and section rules. `main.css:1623`
contains a comment showing the team *knows* gray-500 fails and deliberately avoided
it — the same rigour was never applied to the accent. This is the clearest
accessibility defect in the codebase.

**D4 — Heading-level jumps on 9 pages.** `h1 → h4` with no `h2`/`h3` on
`event-single`, `guide-single`, `blog-single`, `sponsor-single` (×2 languages),
plus `events-archive`, `courses`, `events-filtered`.

**D5 — Sub-44px touch targets.** Worst on mobile: `/empregos/` **55**, `/lazer/`
**31**, `/guias/` **24**, homepage **21**. Many are inline text links (agency names,
phone numbers, footer legal links) — the classic acceptable inline exception — but
21–55 per page is still too many. Legitimate outliers: `.skip-link` (1×1, correctly
off-screen), `.site-logo-link` (106×36), carousel dots (24×24, documented),
breadcrumbs (34×22).

**D6 — Empty-state placeholders dominate `/lazer/`.** All 10 desktop leisure cards
render "IMAGEM PENDENTE" over a 379×237 grey box — a third of the archive's visual
weight carrying no information. Leisure also uses a **16:10** ratio while events
and guides use **16:9**.

**D7 — Duplicate "Laois" location line.** Event cards print `_event_location`
*and* `conexao_county` as two separate rows with two nearly identical map-pin icons
(`event-card.php:266-286`). Confirmed visually on desktop and mobile.

**D8 — 13 inline `<style>` blocks per page, ~15.9 KB of a 135 KB page.** Six come
---

## 5. Inconsistency inventory

| # | Concept | Divergence | Where |
|---|---|---|---|
| I1 | **Section heading** | 16.8 / 17 / 18 / 28 / 40px, weights 600 vs 700, ink vs white | `main.css:1905,4303`; `leisure.css`; `page-empregos.php`; `archive.php` |
| I2 | **Card title** | 17px/600, 18px/600, 18px/700, 18.4px/700 | `main.css:2998`; `design-system.css:111-126` |
| I3 | **Card padding** | 20px (post) vs 24px (`.card-body`) vs 16px mobile | `main.css:2986`; `design-system.css:388` |
| I4 | **Card surface** | Border-only vs border + `shadow-sm` | `design-system.css:350` vs `main.css:2932` |
| I5 | **Image ratio** | 16:9 (events, guides, featured) / 16:10 (blog, leisure) / 3:4 (jobs, sponsors) / 4:3 (business) | `main.css:1564,1725,2452,2951,3105`; `leisure.css:797`; `sponsor.css:94` |
| I6 | **Category pill** | Two designs: green-on-`primary-50` vs white-on-orange | `design-system.css:395` vs `main.css:2977` |
| I7 | **Card CTA** | Full-width tinted green bar (events/jobs/leisure) vs inline text link (guides/blog) | `event-card.php`; `leisure.css` |
| I8 | **Filters** | Pill row (guides, courses, blog) vs dropdown toolbar (events, lazer, jobs) vs none (sponsors) | `guide-filters.php`, `course-filters.php`, `event-filters.php`, `leisure-filters.php` |
| I9 | **Filter mobile UX** | Bottom sheet (events/lazer/jobs) vs pills (guides/courses/blog) | `main.css:5701+` vs `design-system.css:655` |
| I10 | **Off-scale type** | `1.0625rem` ×12, `0.625rem` ×4 | `main.css` multiple |
| I11 | **Hardcoded spacing** | 20+ raw padding declarations | `main.css`, `leisure.css` |
| I12 | **13 breakpoints** | vs 2 documented | all CSS files |
| I13 | **Dangerous grey** | `gray-500` (#adb5bd, 2.07:1) in 14 rules | `main.css:2018, 2139, 2514, 2927…` |
| I14 | **Accent as text** | 2.43:1 in 17 rules | `main.css` multiple |
| I15 | **Grid minimums** | `minmax(300px)` cards vs `minmax(180px)` sponsors | `main.css:3037, 3055` |

---

## 6. UX evaluation

### 6.1 Visual hierarchy — **needs work**
The homepage reads well (hero → quick access → featured → sections). Archives do
not: because section titles range 17–40px, a user cannot predict where one section
ends. On `/lazer/` the "Encontre o que fazer" filter heading at 18px is *smaller*
than card titles at 18.4px — the filter is subordinate to the content it filters.

### 6.2 Simplicity — **mostly good, with real clutter**
Genuinely clean: hero, mobile menu, footer, newsletter, empty states. Real clutter:
the events date badge (a white card stacking "3", "OUT", "5:45 AM", "HOJE" — four
typographic treatments for one date), the duplicate county row (D7), and the leisure
"IMAGEM PENDENTE" blocks (D6).

### 6.3 Typography — **strongest asset, weakest governance**
Poppins + Inter is a genuinely modern pairing that suits a bilingual community
portal. The problem is purely enforcement: off-scale sizes in 12 places and a 2.4×
section-heading spread. **This is the cheapest, highest-impact modernisation
available.**

### 6.4 Spacing — **inconsistent, not wrong**
The 4px scale exists and is used in newer components; older components hardcode.
Page-header padding is consistent, but vertical rhythm between sections varies
because each archive invents its own.

### 6.5 Cards — **repetitive but already unified in spirit**
Six card types, all "bordered white box, rounded 16px, image on top, green pill,
title, grey excerpt, tinted CTA". The *pattern* is already shared — which is why
the site does **not** need six redesigns. It needs that pattern applied
consistently (I2, I3, I4, I6, I7).

### 6.6 Navigation — **already simple; leave it**
9 items, flat, no dropdowns, breadcrumbs everywhere, language switcher present,
footer complete. The information architecture is right. **Do not restructure it.**

### 6.7 Colour — **simplify, don't replace**
The palette *is* the brand and should not change. The fix is restraint: stop using
orange as text, keep it for CTAs and accents.

### 6.8 Images — **inconsistent**
Five different aspect ratios (I5). The leisure placeholder-heavy archive and the
complete absence of imagery on jobs both make those pages feel unfinished. Events
and guides look good.

### 6.9 Performance
CSS is **14 232 lines / ~71 KB gzipped** across six files — large but not
pathological, and correctly split by concern. The costs that matter are the 13
inline `<style>` blocks (D8) and the absence of any layout-shift guards on card
images in the leisure grid. Nothing in this audit suggests the *visual* work should
add JS; all recommended changes are CSS-only or template-only.

---

## 7. Accessibility

| Check | Result |
|---|---|
| Skip link | Present on all 24 pages |
| `lang` attribute | Correct (`pt` / `en`) |
| `<h1>` count | Exactly 1 on all 24 pages — correct |
| `<main>` landmark | Exactly 1 — correct |
| `alt` on images | 0 missing across all 24 pages |
| Focus visibility | Global `:focus-visible`, 3px orange ring, `outline-offset: 2px` |
---

## 8. Modernisation assessment

### 8.1 Would a minimalist direction suit this site?

**Partly — and the site already has most of the ingredients.** This is not a
generic opinion; it follows from the measurements.

**Already supporting minimalism:**
- Body text has **1** treatment across all 24 pages. That is the hardest thing to
  achieve and it is already done.
- The palette is three colours plus neutrals. Nothing to strip.
- 4px spacing scale, 5 radii, 5 shadows — small, closed systems.
- No decorative shadows, no gradients on surfaces, no glassmorphism, no animation
  beyond hover lifts. The site is already restrained.

**Working against minimalism:**
- Not decoration — **repetition**. Six card types that look nearly identical,
  three filter paradigms, five image ratios, thirteen breakpoints. The visual
  noise is *informational*, not ornamental.
- Density. `/empregos/` shows 24 cards × ~144 links; `/lazer/` 246 results behind
  filters. A minimalist aesthetic that removes information would actively harm
  these pages.

**Where simplification would genuinely help:**
- The **event date badge** (four typographic treatments for one date).
- The **duplicate county row** (D7).
- The **grey placeholder blocks** on `/lazer/` (D6) — removing the empty image box
  would be more minimal *and* more honest.

**Where minimalism would make the site worse:**
- Stripping the category pills would remove the fastest scannable signal on
  archive cards.
- Stripping metadata from jobs cards would make 24 near-identical agency entries
  indistinguishable.
- Removing the filter chips would cost real navigational state that users rely on.

**Conclusion:** minimalism is the right *instinct* but the wrong *frame*. The
problem is not too much decoration; it is too little consistency.

### 8.2 Would a modern editorial style suit this site?

**Yes for content pages, no for transactional pages.** The content genuinely
supports it: guides and blog posts are long-form reading material with real
hierarchies, and the current single-template blog single wastes them (a 44px title
then straight to a 15px meta line and the body).

- **Editorial fits:** blog single, guide single, event single, leisure single,
  the homepage hero.
- **Editorial does not fit:** `/eventos/`, `/lazer/`, `/empregos/` — these are
---

## 9. Three design directions

These are developed from the measurements above, not from generic taste.

### Direction A — **Converged Minimal** (enforce what exists)

**Personality:** quiet, confident, utilitarian. The site stops apologising for its
own consistency problems and starts behaving like one system.

- **Typography:** keep Poppins + Inter exactly as-is. Enforce one scale. H1 `clamp`,
  H2 fixed at the existing `clamp(1.5rem, 3vw, 2rem)`, card titles one size, meta one
  size. Kill the twelve `1.0625rem` declarations.
- **Spacing:** 4px scale everywhere. One card padding, one gap in every grid.
- **Cards:** **one** shared pattern applied to all six types — one padding, one
  radius, one border, one CTA treatment. Image ratio unified at 16:9 except the
  deliberately portrait jobs/sponsor cards.
- **Navigation:** untouched.
- **Colour:** unchanged palette, but orange restricted to CTA/accents only, which
  fixes the 2.43:1 failures at the same time.
- **Imagery:** remove the grey placeholder block when no image exists; let text
  cards be text cards.
- **Buttons:** one `.btn` family, three variants, one focus ring.
- **Filters:** keep the two paradigms, but align their typography and spacing.
- **Mobile:** same CSS, better small-screen type sizes.
- **Accessibility:** fixes D3, D4, D5 — the direction is *defined* by them.
- **Performance:** neutral to slightly positive. CSS-only; removes ~15.9 KB inline.
- **Scope:** **small to moderate.** Almost entirely `design-system.css`, `main.css`
  and `leisure.css` edits plus a handful of template tweaks.

**What it feels like:** a well-run public service site. Predictable, fast,
trustworthy. Not exciting — deliberately so.

---

### Direction B — **Editorial Split** (two archetypes, two treatments)

**Personality:** the reading pages become genuinely magazine-like; the scanning
pages stay exactly as dense as they are now. The site acknowledges it does two
different jobs.

- **Typography:** a high-contrast display cut for H1 on *content* pages only;
  tighter measure (65–70ch), larger body on singles, real pull-quotes, stronger
  H2/H3 rhythm.
- **Spacing:** generous vertical rhythm and asymmetric grids on content pages;
  unchanged density on archives.
- **Cards:** archives keep the compact card; singles get a hero-led article layout.
- **Navigation:** unchanged, plus a reading-progress affordance on singles.
- **Colour:** unchanged palette; imagery does the expressive work.
- **Imagery:** large, immersive heroes on singles; cards unchanged.
- **Buttons/filters:** untouched.
- **Mobile:** long-form reading is the biggest mobile win available.
- **Accessibility:** *risk* — expressive type is easy to get wrong for contrast and
  zoom. Needs explicit regression testing on the 9 D4 pages.
- **Performance:** a new font cut is a real cost (extra network request, LCP risk).
  Hero imagery raises byte weight on exactly the pages users wait for.
- **Scope:** **large.** New templates or a heavily forked `single.php`, new font
  pipeline, new hero components.

**What it feels like:** a publication. Warm, engaging, worth browsing. But half the
site stays as-is, so it does not fix the inconsistency problem.

---

### Direction C — **Civic Utility** (maximum scannability, personality via restraint)

**Personality:** a community-service tool. Optimised for finding a PPS number, an
event tonight, or a job in Dublin — fast, high-contrast, keyboard-friendly, almost
aggressively clear. Character comes from the brand green and strong typographic
rhythm, not decoration.
---

## 10. Recommended direction

**Direction A — Converged Minimal — is the right fit, with Direction B's editorial
treatment adopted narrowly for content singles.**

Grounded in the specific evidence:

1. **The measured problem is divergence, not taste.** Six section-heading
   treatments, four card-title treatments, five image ratios, thirteen breakpoints.
   Direction A is the only one of the three that addresses this directly.
2. **The assets are already correct.** Poppins + Inter, the palette, the tokens and
   the spacing scale all measure as sound. Rebuilding them (B, C) discards working
   assets to solve a problem they do not cause.
3. **Two of the three real defects are invisible to aesthetics.** D1 (the
   `/empregos/` orphan column) and D2 (duplicate event cards) are bugs. No design
   direction fixes them; only targeted work does. A redesign that skipped them
   would ship the same broken pages in prettier clothing.
4. **Accessibility debt is concentrated in contrast and heading order** — both
   Tier 1 items under Direction A, and both *easier* to fix while converging the
   system than alongside a redesign.
5. **Cost is proportionate.** Direction A is CSS-first inside the existing theme and
   component system, honouring the constraint not to replace the theme architecture
   for aesthetic reasons. B adds a font pipeline and forked templates; C rewrites
   every archive.
6. **Maintainability favours A.** Fewer variants means fewer places to reintroduce
   inconsistency — the root cause of everything in §5.

**The one part of B worth taking:** a measured content width and stronger type
hierarchy on **single** templates only (blog/guide/event/leisure). That is a small,
contained improvement to the site's worst-served archetype and does not oblige the
archives to follow.

**Explicitly rejected:** new frontend frameworks, a new font pairing, a changed
palette, a restructured information architecture, and any homepage composition
rewrite. The evidence supports none of them.

---

## 11. Page-by-page evaluation

No scores — descriptive only.

| Page | Strengths | Problems | Modernisation opportunity | Risk |
|---|---|---|---|---|
| **Homepage** | Strong hero, clear section rhythm, good accent use, sponsor carousel works | Quick-access grid of 10 tiles is visually flat; "Precisa de ajuda?" largely repeats 5 of those same 10 tiles; 21 sub-44px targets | Consolidate the two overlapping shortcut blocks into one; tighten section spacing | Medium — most-viewed surface |
| **Events archive** | Filter toolbar excellent; result count clear | **10 cards from 6 events (D2)**; date badge stacks 4 type treatments; duplicate "Laois" row (D7); densest card on the site | Simplify the date badge; merge county/town into one row | Low — component-level |
| **Event single** | Clear title, breadcrumbs, practical detail | `h1 → h4` jump (D4); no hero imagery treatment | Editorial measure for the body (Direction B, narrow) | Low |
| **Guides archive** | **Best-looking archive on the site** — pill filters, clean cards, good meta | 14 filter pills across two rows; 24 sub-44px targets; tight excerpt clamping | Keep as the **reference implementation** for card/filter styling | **Very low — use as the model** |
| **Guide single** | Good long-form read | `h1 → h4` jump (D4); measure not explicitly controlled | Constrain measure to ~68ch, strengthen H2/H3 | Low |
| **Courses** | Strong card imagery, clear provider identity | `h1 → h3` jump (D4); uneven card heights in the last row; pill filters differ from events/lazer | Align to the guides card pattern | Low |
| **Leisure / tourism** | Rich multi-select attribute filtering | **All 10 cards show "IMAGEM PENDENTE" (D6)**; 16:10 ratio unlike siblings; the "Encontre o que fazer" heading (18px) is smaller than card titles (18.4px) | Drop the placeholder box; unify ratio; restore heading hierarchy | Medium — placeholder removal changes layout rhythm |
| **Jobs** | Clear agency grouping, honest scam warning notice | **Orphan 340px column with 812px empty (D1)** — reads as broken; 24 near-identical cards; **55 sub-44px targets**; no imagery | Fix the grid; give the intro full measure; consider row-based list over cards | **Low risk, very high reward — fix first** |
---

## 12. Mobile evaluation

**Good:** the mobile menu (61px rows, full-screen, focus-managed); the filter bottom
sheet with sticky actions; **zero horizontal overflow across all 24 pages**; the
hero's mobile crop (`object-position: 38% center`) with truthful `<picture>`
metadata.

**Needs work:**

| Issue | Detail | Fix that helps mobile **and** desktop |
|---|---|---|
| **Target density** | 55 small targets on `/empregos/`, 31 on `/lazer/`, 24 on `/guias/` | Larger tap padding on card CTAs and meta links — desktop gains a bigger click area too |
| **Card density** | Leisure/courses/event cards stack full-width with a 16:9 image each; a 10-card grid is 7 400px tall on mobile | Reducing image-ratio variance and card padding shortens every archive |
| **Filter paradigm split** | Bottom sheet on events/lazer/jobs vs pills on guides/courses/blog | Unify *typography and spacing* (not mechanics) so both stay usable |
| **Long titles** | EN titles wrap to 2–3 lines, pushing CTAs out of alignment across a grid | Consistent title clamping with the CTA pinned to the card bottom |
| **Long metadata** | Agency job cards stack 4 meta lines (type, contract, location, phone) | Collapse to 2 lines + expand, or move detail to the single page |
| **Typography** | `h1` 28px, section `h2` as low as 16px on mobile | The shared heading scale fixes this on both viewports at once |
| **Buttons** | `.btn` already meets 48px on mobile; card CTAs (`Sabia mais`, `Ver cursos`, `Ver no mapa`) do not | Unify card CTA treatment — the most repeated mobile control on the site |

**Deliberate conclusion: no separate mobile visual system.** Every item above is
fixed by the same CSS that governs desktop, which is both cheaper and more
consistent.

---

## 13. Improvement backlog

Every item traces to a measurement in §4–§5. Nothing here is speculative.

### Tier 1 — High impact / low disruption

| # | Change | Fixes | Complexity |
|---|---|---|---|
| T1.1 | Fix the `/empregos/` orphan grid — collapse to one column when the portrait is absent | D1 | **Small** |
| T1.2 | Stop duplicate event cards; one card per event showing the next occurrence | D2 | Small–moderate (touches the recurrence output contract) |
| T1.3 | Enforce **one** section-heading scale; remove the per-page overrides | I1, 6→1 treatments | **Small** |
| T1.4 | Enforce one card-title size/weight; delete the twelve `1.0625rem` declarations | I2, I10 | **Small** |
| T1.5 | Restrict orange to CTA/accents; use `--color-primary-dark`/`--color-accent-dark` for accent text | D3 (17 rules) | Small |
| T1.6 | Fix the `h1 → h4` jumps on the 9 affected pages | D4 | Small |
| T1.7 | One card padding, radius and border; reconcile `.post-card` with `.card` | I3, I4 | Small |
| T1.8 | Merge the event card's county + town into one location row | D7 | Small |
| T1.9 | Simplify the event date badge from 4 type treatments to 2 | §6.2 | Small |
| T1.10 | Style the comment submit button with `.btn` | D9 | **Very small** |
| T1.11 | Translate the "Uncategorized" blog filter term | D10 | Very small |
| T1.12 | Drop the grey placeholder box when no leisure image exists | D6 | Small |
| T1.13 | Reduce `gray-500` usage in the 14 remaining rules | D3 | Small |
| T1.14 | Consolidate the homepage's two overlapping shortcut blocks | §6.1 | Small–moderate |

### Tier 2 — High impact / moderate effort

| # | Change | Fixes | Complexity |
|---|---|---|---|
| T2.1 | Extract **one** shared card component used by all six card types | I2, I3, I4, I6, I7 | **Moderate** |
| T2.2 | Unify card image ratios (16:9 default; portrait only where intentional) | I5 | Moderate |
---

## 14. Implementation map

| Area | Files | Change | Complexity | Risk |
|---|---|---|---|---|
| Design tokens | `assets/css/design-system.css` | Make the existing scale authoritative; add a documented accent-text token | Small | Low — no new tokens needed, only enforcement |
| Global CSS | `assets/css/main.css` (8 068 lines) | Remove ~20 hardcoded paddings, 12 off-scale sizes, 14 `gray-500` uses, 17 accent-text uses; collapse per-page heading overrides | Moderate | **Medium** — largest blast radius; small commits only |
| Archive CSS | `assets/css/leisure.css` (1 239), `sponsor.css` (329) | Align ratios and card padding to the shared pattern | Small–moderate | Low |
| Header CSS | `assets/css/header-nav.css` | **No change recommended** — one of the strongest files | — | None |
| Dark mode | `assets/css/dark-mode.css` (2 569) | Must mirror every token change; **do not skip** | Moderate | Medium — a token change without a dark override is a regression |
| Card templates | `template-parts/event-card.php`, `leisure-card.php`, `provider-card.php`, post card in `archive.php` | One shared card; D2 recurrence fix; D7 location merge; D9 date badge | Moderate | Medium — D2 touches event runtime output |
| Filters | `template-parts/event-filters.php`, `leisure-filters.php`, `guide-filters.php`, `course-filters.php` | Typography/spacing alignment only — **no mechanic changes** | Small | **High** — the filter ARIA is a proven strength; do not disturb the semantics |
| Jobs layout | `assets/css/main.css:6891` + `inc/empregos-landing.php` | T1.1 orphan-grid fix | Small | Low |
| Singles | `single.php`, `single-leisure.php` | Measure + heading order | Small | Low |
| Assets | `inc/assets.php` | T2.8 inline-style dequeue | Moderate | Low |

**Reusable components that should be created (not copied):** one
`template-parts/card.php` taking a post-type argument, and one `.section-title`
contract consumed by every archive. Both would be *new* shared engines; neither
duplicates an existing helper.

**Dependencies and ordering:** `design-system.css` first (it defines the contract),
then `main.css`, then per-template work, then a dark-mode pass. Dark mode must land
with or immediately after each token change.

---

## 15. Before / after concepts

**Not implemented — described for the next phase.**

### Concept 1 — Section headings (the biggest visual win)

**Current.** `/lazer/` renders "Encontre o que fazer" at **18px** while the card
titles below it render at **18.4px**. `/cursos/` renders "Cursos" at **40px white
on green**. `/empregos/` renders "Oportunidades de emprego" at **28px**. `/guias/`
renders section titles at **17px/600**. One semantic element, four renderings.

**Potential.** One rule, one scale. Every archive section title renders at the
already-written `clamp(1.5rem, 3vw, 2rem)` — roughly 32px desktop / 24px mobile —
weight 600 with the existing `-0.02em` tracking. Nothing new is invented; the token
simply stops being overridden.

### Concept 2 — The event card

**Current.** A date badge stacking "3 / OUT / 5:45 AM / HOJE" in four type
treatments; a category pill; the title; then `19:30 — 22:00`, then `Laois`, then
`Laois` **again**; then the excerpt; then a full-width tinted "Sabia mais" bar.

**Potential.** One date line ("3 Oct · 19:30–22:00") and one location line
("Laois"), both at the shared metadata size. The badge keeps only day number and
month. The CTA becomes the shared card CTA used by every other card type. The card
gets *shorter* — which is what "minimal" should mean here.

### Concept 3 — `/empregos/` (a bug, not a redesign)

**Current.** A 340px column of text beside 812px of empty space, at 1.0625rem/1.8.

**Potential.** When `.empregos-landing-media` is absent, collapse the grid to one
column and let the intro run to the shared measure. No new layout, no new art — the
existing breakpoint logic stops reserving space for an image that is not there.
**Highest visual improvement per line of code on the entire site.**

### Concept 4 — Leisure cards without images

**Current.** Ten 379×237 grey boxes reading "IMAGEM PENDENTE" above every card.

**Potential.** Text-led cards: category, title, location, excerpt, CTA — the same
information at roughly a third less height. The archive becomes scannable instead
of a wall of placeholders, and this generalises to any content type missing images.

---

## 16. Conclusion — no implementation approval yet

**Current UI character:** A competent, well-engineered community portal with a real
design-token system, a strong brand palette, an excellent mobile menu and
best-in-class filter accessibility — undermined by the absence of any enforcement
of its own system, so that identical concepts render up to seven different ways.

**Main issues:** A 2.4× spread in section-heading sizes (16.8–40px); four card-title
treatments; five image aspect ratios; thirteen breakpoints; off-scale typography in
17 places. Two genuine bugs — the `/empregos/` orphan grid (D1) and duplicated event
cards (D2) — that no aesthetic change would fix. Accent orange at 2.43:1 against
white, the clearest accessibility failure in the codebase.

**Most promising design direction:** **Direction A — Converged Minimal**, plus
Direction B's editorial measure applied narrowly to content singles. Not because
minimalism is fashionable, but because the measured defect is *divergence*, the
assets are already correct, and this is the only direction proportionate to the
evidence and the only one that leaves accessibility and performance improving.

**What should remain unchanged:** The information architecture and the 9-item
navigation. The Poppins + Inter pairing. The green/orange/navy palette. The filter
mechanics and their ARIA semantics. The hero and its LCP engineering. The mobile
bottom sheet. Dark mode's approach. The 1200px container. No new framework, no
replaced theme architecture, no changed routes.

**First prototype to build:** **`/empregos/`** — it contains the worst visual
defect (D1), it is two pages (PT + EN), and the fix is a few lines of CSS with no
template or behaviour change. It proves the Direction A approach end to end at
minimum risk before anything else is touched.

**Highest-value changes, in order:**
1. T1.1 — fix the `/empregos/` orphan grid (D1).
2. T1.2 — stop duplicate event cards (D2).
3. T1.3 — enforce one section-heading scale (I1).
4. T1.4 — enforce one card-title scale (I2).
5. T1.5 — restrict orange to CTAs, fixing 2.43:1 contrast (D3).
6. T2.1 — one shared card component for all six card types.

**The next implementation phase should begin only after this audit's direction is
confirmed.** No production, route, content, translation or design change has been
made.

---

## 17. Verification performed by this audit

| Command | Result |
|---|---|
| `git status --short` before and after | **clean** — the audit modified no tracked file |
| `git rev-parse --short HEAD` | `6ec382b` (unchanged) |
| `php scripts/generate-registry-docs.php --check` | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `./scripts/run-tests.sh --scripts` | **18 suites: 17 passed, 1 failed** |
| Playwright render, 24 pages × 2 viewports | 48 renders, 48 screenshots, **0 horizontal overflow** |
| Contrast computation | 12 pairs computed; 4 fail AA (D3) |

**The single failing suite** is `tests/scripts/verify-stage14-secret-scan.py`:
*"the PHP runtime scanner could not be executed (no local WordPress); conformance is
UNPROVEN, not assumed"*. This is an **environmental** failure — the scanner needs a
local WordPress reachable from the host shell — and is **unrelated to this audit**,
which changed no code. It is recorded as pre-existing baseline debt.

### Limitations of this audit

- **The local site is the deterministic CI fixture site**, not production content.
  Cards show synthetic titles and no real photography, so **image-quality and
  image-crop judgements are weaker** than they would be against real content. The
  leisure "IMAGEM PENDENTE" finding (D6) is partly a fixture artefact and must be
  re-checked against production before acting on it.
- No production site was accessed and no Lighthouse / Core Web Vitals trace was run;
  performance statements are structural (bytes, request count, render-blocking
  blocks), not measured scores.
- Contrast ratios were computed from declared token values **in the light theme
  only**. Dark-mode ratios were not computed and must be verified before any colour
  change ships.
- Screenshots were taken in Chromium only; no Firefox, WebKit or real-device pass.
- No automated axe/Lighthouse audit was run — accessibility findings come from
  computed-style and DOM inspection plus manual WCAG ratio maths.

---

## 18. Evidence paths

| File | Proves |
|---|---|
| `docs/evidence/2026-10-03-ui-audit/audit-data.json` | Raw computed-style, layout and a11y data for all 24 pages × 2 viewports |
| `docs/evidence/2026-10-03-ui-audit/consistency.txt` | The cross-page treatment-divergence tables in §4.1 and the a11y roll-up |
| `docs/evidence/2026-10-03-ui-audit/script-contract-baseline.log` | The 17/18 script-contract baseline, including the one environmental failure |

Screenshots and the audit harness live in the gitignored `ui-audit-work/`
(`audit.py`, `consistency.py`, `shots/`). They are scratch tooling, deliberately not
committed; regenerate with `python3 ui-audit-work/audit.py`.

---

_Last verified: 2026-10-03 — UI/UX audit, evaluation only, no implementation._
### Prototype recommendation

If a controlled prototype is built before a broader rollout, these are the
candidates, in order:

1. **`/empregos/`** — smallest change, most visible defect, both languages.
2. **The events archive card** — the most-repeated component on the site.
3. **The guides archive** — already the best implementation; validates that the new
   shared card pattern does not regress the site's strongest page.
4. **`/lazer/` text-led cards** — validates the "no placeholder" pattern.

Prototypes should be built as **theme-scoped CSS variants behind a body class**,
comparable to the existing dark-mode `[data-theme="dark"]` pattern, so they can be
compared side by side and discarded without touching production templates.
| T2.3 | Unify the card CTA treatment across events/jobs/leisure/guides/blog | I7, mobile targets | Moderate |
| T2.4 | Align filter typography and spacing across both paradigms | I8, I9 | Moderate |
| T2.5 | Constrain single-template measure to ~68ch and strengthen H2/H3 | §6.3, D4 | Moderate |
| T2.6 | Increase tap padding on card CTAs and inline meta links | D5 | Moderate |
| T2.7 | Rationalise 13 breakpoints to 4–5 documented ones | I12 | Moderate |
| T2.8 | Dequeue the theme's inline `<style>` from the critical path | D8 | Moderate |

### Tier 3 — Larger opportunities (**not recommended now**)

| # | Change | Why not now |
|---|---|---|
| T3.1 | New homepage composition | The current homepage works; the duplication (T1.14) is the real defect |
| T3.2 | Major archive redesign | Directions B and C both cost more than the measured problem justifies |
| T3.3 | Full design-system refactor into a token-only pipeline | The tokens already exist and are correct; the issue is bypass, not absence |
| T3.4 | Replace the filter bottom sheet with a unified control | The current filter UX is a genuine strength — do not replace working accessibility |
| T3.5 | Typography-led editorial system across all singles | Contains real value (T2.5) at a fraction of the cost |

**Tier 3 should only be reconsidered after Tier 1 and Tier 2 are complete and
re-measured.** The measurements in §4 are cheap to regenerate, so that decision can
be made on evidence rather than on this report's assumptions.
| **Blog archive** | Clean cards, readable | "Uncategorized" leaks untranslated (D10); section heading 17px | One heading scale + translate the term | Very low |
| **Blog single** | Share buttons, breadcrumb, clean form | **Submit button is an unstyled native control (D9)**; `h1 → h4` jump | Style the submit with `.btn`; editorial measure | Very low |
| **Sponsors** | Scannable logo grid, correct portrait images | Only 4 cards — archive feels empty; no filters | Leave; align card ratio consistency | Very low |
| **Contact** | Simple, functional | Heading scale inconsistent with siblings | Align to the shared scale | Very low |
| **Search results** | Reuses the guides card pattern — good consistency | Bare page header, no query echo | Echo the query prominently | Low |

**EN equivalents** are structurally identical to their PT twins in body, card,
button and image treatment — a genuine success. The only language-dependent risk
is **title wrapping**: EN guide titles such as *"Autism and Air Travel in Ireland:
Dublin and Cork Airport"* occupy 2 lines where PT occupies 1. Any card title-size
reduction must be validated against EN first; no visual decision in this backlog
should be made from PT text alone.

- **Typography:** one family (Inter) with a wide weight range; a tightened variant
  for headings. Maximum contrast between levels, minimal size variation.
- **Spacing:** the most aggressive whitespace increase of the three; dense boxes
  replaced by generous rows.
- **Cards:** lean list rows with a strong left rule and a single strong CTA, not
  boxes-in-boxes.
- **Navigation:** unchanged IA, but the fastest possible scan path and heavier
  persistent filter access.
- **Colour:** green-led, orange almost eliminated except the primary CTA. Highest
  contrast of the three — the easiest to make fully AA.
- **Imagery:** small thumbnails or none in archives; illustration-led.
- **Buttons:** flat, rectangular, high-contrast.
- **Filters:** a single unified filter pattern across all archives.
- **Mobile:** best of the three — large targets, simple lists.
- **Accessibility:** strongest by a wide margin.
- **Performance:** best of the three — less DOM, less imagery, less CSS variance.
- **Scope:** **moderate to large.** Touches every archive template and every card.

**What it feels like:** fast, clear, civic. It would lose the warmth of the current
hero and the community feel, and it is a bigger change than the evidence demands.
  comparison/filter surfaces where the user's task is scanning and filtering, not
  reading. Larger imagery and more whitespace actively *hurt* them: at 246 results,
  density is a feature.

This split is the most important strategic conclusion in the audit: **the site has
two distinct page archetypes — reading and scanning — and currently styles them
almost identically.**

### 8.3 Where typography alone would modernise the site

Because the fonts are already right, enforcing the existing scale would deliver a
"modern" result with **zero font loading cost and zero new dependencies**. This is
the single best value-per-risk change available.
| Filter ARIA | Genuinely strong (multiselectable, focus trap, `aria-live` counts) |
| **Contrast** | **FAILS on accent orange (2.43:1) and gray-500 (2.07:1)** — D3 |
| **Heading order** | **9 pages with `h1 → h4` jumps** — D4 |
| **Target sizes** | 21–55 sub-44px targets per page on mobile — D5 |
| Reduced motion | Honoured |

**A visual modernisation must not regress any passing row.** The accessibility
*debt* is concentrated in colour contrast and heading order — both small, targeted
fixes.
from WordPress core; the theme contributes `conexao-content-inline-css`.
Render-blocking on a cold connection; a cheap performance win.

**D9 — Comment form is visually unstyled.** The blog single renders
`Publicar comentário` as a raw native `<button>` — default grey chrome,
inconsistent with the `.btn` system used everywhere else.

**D10 — "Uncategorized" leaks as a filter label.** The `/blog/` PT filter bar reads
`Todos / Comunidade / Uncategorized` — an untranslated native WordPress term beside
properly translated ones.