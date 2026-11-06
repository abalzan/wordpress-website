# Report — Christmas signature experience: “One Christmas, Two Skies”

| | |
|---|---|
| **Stage / task name** | Commemorative Events — Christmas signature experience (artwork-integrated revision) |
| **Date** | 2026-11-06 |
| **Author / agent** | Coding agent (Cline), on the local `wordpress-website` repository |
| **Branch** | `commemorative-events` |
| **Start SHA** | `657c3340f85f7b9f783edc85c43805ee8a35347c` |
| **Final SHA** | `b18fa58c1052b69340cdfc639e140f53ddbc3d08` — **advanced during the session by a commit this task did not create** (see §13) |
| **Working tree at finish** | dirty — exactly the 16 modified/deleted + 8 new paths listed in §9; the pre-existing dirty work from the start snapshot was committed by that external commit, not discarded |
| **Skills followed** | `wp-add-content-type` (package/placement contract), `wp-testing` + `wp-write-in-process-test` + `wp-http-acceptance-matrix` (suites/gates), `wp-frontend-perf` (assets, payload, responsive), `wp-security-review` (escaping, no new endpoints/superglobals), `wp-update-docs` (indexes, drift gate), `wp-repository` (plan → build → verify → report loop) |
| **Authoritative docs consulted** | `docs/engineering-standard.md`; `docs/commemorative-events.md`; `docs/commemorative-content.md`; `docs/content-model.md`; `docs/themes/conexao-br-irlanda.md`; `docs/testing.md`; the r10 architecture proposal; the Stage 0–6 plans/reports; the r10 final-review and local-remediation reports |
| **Evidence** | [`docs/evidence/2026-11-06-christmas-signature-experience/`](../evidence/2026-11-06-christmas-signature-experience/) (23 files: screenshots, browser JSON, gate and suite output, byte measurements, git snapshots) |

## 1. Outcome and verdict

**What was built.** The existing `christmas` package was upgraded from a small bauble
band into a signature seasonal experience, entirely on top of the commemorative
foundation that already existed. The supplied GPT Images panoramas became the visual
identity: six measured WebP derivatives of the three supplied compositions, plus one
code-drawn shared star used in four places. The homepage hero band now carries the
panorama beside the copy (never under it) with one call to action; the landing pages
gained “The Christmas Star: a journey between two skies” — a server-rendered,
JavaScript-optional two-perspective scene — a “what crosses both skies” section built
on the master panorama, and an inclusive close, wrapped around the five **unchanged**
reuse modules. The package identity moved from a bauble ornament to a shared star with
a twilight palette, and the ambient decoration became that same star.

**Verdict.** `PASS` for the local implementation scope, **`PASS WITH LIMITATION`** for
the repository as a whole: every Christmas-specific criterion in the brief is
demonstrated with a command that ran (§10), and two repository-wide gates stay red for
reasons this task did not create and was not authorised to resolve (§13).

**Explicitly not a release.** This is **local implementation work**. No production
access, no production write, no cache purge, no activation, no release manifest change,
no theme version bump, no tag, no commit and no push happened. `dist/release.json` was
not regenerated. Nothing here is “release-ready”; the items listed in the brief §9
remain outstanding (see §14).

## 2. Scope completed / not completed

**Completed (mapped to the brief).**

| Brief requirement | Where it lives |
|---|---|
| §3 creative brief “One Christmas, Two Skies” | §3 of this report; `christmas.css` token/art system; the two-sky scenes |
| §4 signature interaction | `template-parts/commemorative/christmas/journey.php` + `createJourney()` in `christmas.js` (§5) |
| §5.1 homepage hero | `template-parts/commemorative/christmas/hero.php` (§6.1) |
| §5.2 ambient placement | `ambient.php` + `createStarDecoration()`; shared scheduler untouched (§6.2) |
| §5.3 landing pages | `christmas/landing.php` composition (§6.3) |
| §5.4/§11 artwork rules | §4 (selection, derivatives, sizes, rejected alternatives) |
| §6 accessibility/performance | §11, §10.3, §12 |
| §7 method + §8 acceptance criteria | §10 (commands), §15 (criterion-by-criterion verdict) |
| §10 deliverables | this report + the evidence folder |

**Deliberately not done.**

- **POT regeneration** for the new and changed strings. The brief §9 lists it as a
  release/commit-step activity and instructs that it be deferred explicitly. The
  `i18n_freshness` gate is red partly for that reason (§13). All new copy is already
  language-complete at render time because the package resolves PT and EN explicitly
  (§8), so the English page is not blocked by the missing catalogue.
- **No new artwork was generated.** The supplied collection contains no standalone
  transparent star, no isolated ornaments and no single-country scenes; the star is
  drawn as SVG/CSS (allowed by brief §3.2/§4) and the missing “separate country scene”
  artwork is recorded as a follow-up in §4.4 rather than blocked on.
- **No content records were created or edited.** The event records, the `/natal/` and
  `/en/christmas/` pages and every reused record are inputs, untouched.
- **The six mutation-bearing engine suites** were not re-run: the brief forbids running
  suites that mutate a non-disposable database (brief §1.7/§7 Step D8).
- **Production sitemap / CDN / purge verification** was not attempted: separately
  authorised work (brief §1.6, §9).

## 3. Creative direction

**Concept.** *One Christmas, two skies.* Not two flags and not a generic red-and-green
Christmas: two seasonal atmospheres that coexist without competing, joined by one light.

| Element | Decision |
|---|---|
| **Shared canvas** | Midnight indigo → deep twilight (`#1b2447` … `#080c22`), used as the journey panel's surface and the hero/ambient anchor colour. |
| **Light** | Candle gold and soft amber (`#d9a441`, `#e8c273`, `#fff6d8`) — the star, the sun glow, the lit windows. |
| **Brazil — summer** | Warm coral/terracotta (`#b34a37`, `#c2553f`, `#f2a65a`): a low sun over the sea, the evening starting in daylight, the table spilling outside. No snow, no carnival, no football, no single regional aesthetic. |
| **Ireland — winter** | Evergreen/deep teal (`#1f4d46`, `#2f6b63`) against near-black blue: the dark arriving early, the light coming from inside — windows, a fire, a candle. No shamrocks, no leprechauns, no pub imagery. |
| **The thread** | A single eight-point star with two symmetric arcs of light, drawn once as SVG and reused in the hero kicker, the scene control, the closing panel and the ambient layer. It encodes *connection across distance*, not a national mash-up. |
| **Rhythm** | The existing theme type scale is untouched. Rhythm comes from space, a restrained palette and short paragraphs — no new fonts, no new libraries, no new CSS framework. |

**How the two cultures stay equal.** The scene's copy is **one data structure with two
sibling keys rendered by one loop**, so neither perspective can silently become a
footnote: each has a heading, a paragraph, three observed details and a closing note,
and the render is asserted at exact parity (§10.1). Visually both skies get the same
box, the same height and the same saturation budget, and the artwork places both worlds
on one horizon line.

**Inclusivity.** Both perspectives end with an explicit caveat (“*Isto é um retrato
entre muitos*” / “*Not a rule either…*”), the “what crosses both skies” section closes
with “*Nada disso é obrigatório…*”, and the page's final paragraph addresses visitors
for whom Christmas is not their celebration. No claim is made that a country, a
religion or a family type is uniform.

**Deliberately avoided.** Generic red/green Christmas styling, snow in Brazil, flag
overlays or flag mash-ups *in our own design*, random snowflakes, clip-art ornaments,
stock-photo collages, a carousel of the three panoramas, animation for its own sake, and
any text baked into an image.

## 4. Artwork decisions

### 4.1 The three supplied compositions, compared

All six files are present (1672 × 941, 16:9, WebP quality 92, 427–504 KB) and were
inspected at real dimensions, then compared against the actual templates.

| Supplied asset | Composition | Decision |
|---|---|---|
| `christmas-two-skies-variant-warm-evening.webp` | Symmetric panorama: Brazilian beach, city and Sugarloaf on the **left**, Irish stone cottage with wreath and red door on the **right**, one large eight-point star and two arcs of light **centred**, open dark sky above. | **PRIMARY — homepage hero.** |
| `christmas-two-skies-variant-star-bridge.webp` | The same two worlds, with a **very large** centred star, two symmetric light arcs, star-cutout lanterns and a round tower on a snowy hill. | **Journey header** (landing) — its star literally bridges both sides, which is the signature feature's argument. |
| `christmas-one-christmas-two-skies-master.webp` (the brief's default hero candidate) | One Christmas tree at the centre with gifts, the Brazilian beach left, the Irish village and castle right, the star at the tree top. | **“What crosses both skies” figure** (landing, lazy) — the only composition with the social scene (tree, gifts, gathering). |

**Why the hero is not the master.** The master was evaluated first, as the brief asks.
It was not chosen for the hero for three measured reasons: (1) its composition is
**asymmetric** — the tree occupies the centre and pushes Brazil into a narrower third,
which works against “both cultures equally prominent” at the 480 px the band actually
renders; (2) it is the only composition that **drapes Brazilian and Irish flag ribbons**
on the tree, and the hero is the one surface that must state the identity without a flag
mash-up; (3) at band size its finest content (three vignettes plus a tree) collapses
into texture, whereas the warm-evening view still reads as two worlds plus one star at
288 px wide. The master was therefore kept, at a larger size, where its extra social
detail earns its place.


### 4.2 Installed files, sizes and roles

Every derivative was produced with the environment's existing ImageMagick (`convert`,
WebP `-quality 78 -define webp:method=6`) — no new dependency and no new repository
tooling. The supplied files were read, never written; nothing was overwritten.

| Installed file (`assets/images/presentations/christmas/two-skies/`) | From | Pixels | Bytes |
|---|---|---|---|
| `two-skies-hero-840.webp` | warm-evening | 840 × 473 | 96,892 |
| `two-skies-hero-1200.webp` | warm-evening | 1200 × 675 | 168,988 |
| `star-bridge-780.webp` | star-bridge | 780 × 439 | 94,262 |
| `star-bridge-1280.webp` | star-bridge | 1280 × 720 | 211,828 |
| `two-skies-postcard-760.webp` | master | 760 × 428 | 93,544 |
| `two-skies-postcard-1120.webp` | master | 1120 × 630 | 174,820 |
| `decorations/star.svg` (**new**; replaces `decorations/bauble.svg`) | code-drawn | vector | 1,506 |

Total new image weight committed to the theme: **831 KB**. The 2.8–3.0 MB PNG masters are
deliberately **not** shipped (a test asserts no `*.png` sits in that asset folder); they
remain the source masters in the supplied archive.

Quality was measured rather than assumed: re-encoding `star-bridge-1280` at quality 74
saved only 24 KB (11 %) while risking banding in the dark sky, so quality 78 was kept for
the signature visual.

### 4.3 Responsive and crop behaviour

- Every image is served through `<img srcset sizes>` with truthful intrinsic
  `width`/`height`: no layout shift, no CSS-background request.
- The hero art keeps the **full 16:9 frame on mobile** — no crop — so both seasonal
  worlds stay visible down to 320 px instead of one being cropped away.
- On desktop the art column is 34 % of the band (≈ 370 px at 1366), inside the 840 w
  candidate.
- The postcard and journey figure are `min(700–780 px, 100 %)`, so a phone never
  downloads the 1120/1280 candidate. This was **measured and corrected**: the first
  `srcset` (`700w`, `sizes` = `100vw − 32px`) forced the 1120 w file on a 390 px DPR-2
  phone (170.7 KB); adding the 760 w derivative and an `820px` breakpoint cut that to
  91.4 KB — **−79 KB per mobile view** (§10.3).
- No crop of the unified panorama is presented anywhere as a single-country scene.

### 4.4 What the supplied collection does not contain (follow-up request)

There is no transparent star, no isolated ornaments, and no standalone Brazil-only or
Ireland-only illustration. All three consequences were handled without inventing
substitutes:

1. The star is **drawn** (`christmas/star.php`, plus the same geometry as a standalone
   SVG for the ambient scheduler) instead of being cut out of a photograph.
2. The two-sky scene's skies are **CSS gradients** (sun over the sea; night with lit
   windows), so the interaction costs **zero** additional image bytes.
3. **Recommended follow-up artwork (not blocking):** a dedicated portrait/mobile
   composition for the hero; and — only if a future iteration wants photographic scenes
   inside the switcher — one approved Brazilian summer scene and one approved Irish
   winter scene, art-directed to the same twilight grade.

## 5. The signature interaction

**Behaviour.** “The Christmas Star: a journey between two skies” is one panel on the
landing page:

1. A kicker, an `h2` and one sentence frame the idea; the star-bridge panorama sits in a
   captioned `<figure>` underneath.
2. A segmented control offers **Brasil · verão** and **Irlanda · inverno**. Between/above
   them the shared star rests over the seam and travels once (700 ms) towards the chosen
   sky — the only motion in the feature.
3. Each perspective is a complete article: a CSS sky (sun over the sea / night with lit
   windows), a season label, an `h3`, a paragraph, three observed details and a nuance
   note.
4. One closing line joins them: “*O que atravessa os dois céus não é o clima. É o
   gesto…*”

**Semantics — and the no-JavaScript contract.** The server renders both articles in
full and ships the control inside a `hidden` container with **no tab roles at all**. A
visitor without JavaScript (or if the script fails to load) therefore reads both skies
in order and never meets a control that cannot work. `createJourney()` then upgrades the
pair into a standard WAI-ARIA tabs widget: `role="tablist"/"tab"/"tabpanel"`,
`aria-controls`, `aria-labelledby`, `aria-selected`, roving `tabindex` (0 / −1), one
visible panel, Arrow/ArrowUp/ArrowDown/ArrowLeft/ArrowRight with wrap-around, Home/End,
and focus that follows the selection. An incomplete scene **fails closed**: nothing is
revealed, no role is invented (§10.2 proves both branches at runtime).

**State indicator not carried by colour alone.** The selected control fills **and** gains
a solid dot marker **and** keeps the heavier weight; the `aria-selected` attribute carries
it for assistive technology. Position/motion are never the only signal.

**Motion and cost.** One CSS transition on the star (700 ms) plus a 200 ms control
background transition; `prefers-reduced-motion: reduce` sets both to `none` (asserted in
a real browser, §10.3). There is **no** timer, interval, `requestAnimationFrame`, storage
entry, network request, analytics event or backend endpoint — proven at runtime by
counting timer creation during a full interaction sequence (§10.2).

**Data.** No personal data, no preference persisted, no WordPress write, no account, no
comment, no feed. The state lives in a class/attribute on the DOM node and dies with the
page.


## 6. Placement coverage

### 6.1 Homepage hero (`homepage_hero`)

`template-parts/commemorative/christmas/hero.php`, rendered by the unchanged shared shell
and the unchanged `front-page.php` dispatch call.

- **Art and copy are siblings, never layers**: a two-column grid (34 % / rest) on desktop,
  a single column at ≤ 768 px. Because no sentence sits on the photograph, contrast is
  guaranteed by construction in both site themes — no scrim, no dark overlay.
- **One CTA.** The event record may override it (`_ce_cta_url` / `_ce_cta_label`, honoured
  when present); otherwise it resolves the package's own landing page (`/natal/`,
  `/en/christmas/`), falling back to the shared calendar only if that page cannot be
  resolved. A test asserts exactly one `<a>` in the band.
- **Headline/copy** use the package's documented voice (`Um Natal, dois céus` / `One
  Christmas, two skies`) unless the editor filled the record's fields — the records are
  currently empty, so the defaults render (verified read-only, §8).
- **Reserved space without JS.** `body.ce-active.ce-package-christmas.ce-placement-homepage-hero`
  reserves the band height server-side, so nothing shifts after load. The reservation was
  **re-measured** after the first build: the band is 243–261 px tall between 769 and
  1600 px, so the reserve is `clamp(286px, 21vw, 344px)`. Measured overlap check at
  769/800/900/1024/1200/1366/1600 px: **no overlap at any width** (§10.3).
- Below 768 px the band leaves the overlay and joins the flow under the hero (the
  pre-existing, package-scoped rule), where it stops competing with the mobile hero copy.

### 6.2 Ambient layer (`site_wide_ambient`)

- The decoration is now the **shared star** (`decorations/star.svg`, attribute renamed to
  `data-ce-star-asset`); `bauble.svg` is deleted.
- **No new scheduler.** `createStarDecoration()` only supplies the node; the single timer,
  randomised interval, session cap (calm: 60–120 s, 2–4 s, one at a time, cap 3),
  visibility pause, reduced-motion stop and node removal all remain in
  `assets/js/commemorative.js`, untouched.
- Still `aria-hidden`, `pointer-events: none`, non-focusable, no audio, bounded away from
  the header and controls; the ambient gate and the Node runtime gate both still pass.
- The CSS gives it one drift-and-fade over its own duration and nothing else.

### 6.3 The landing pages (`landing_page`) — `/natal/` and `/en/christmas/`

Architecture unchanged: a normal page with the assigned template **and**
`_ce_landing_package`, rendered by `page-commemorative-landing.php` →
`landing-page.php` shell → `christmas/landing.php`. The page's own title and intro remain
the opening and are **not** duplicated.

| # | Section | Source |
|---|---|---|
| 1 | Page title + intro (the invitation) | the **page record**, unchanged |
| 2 | **Dois céus, uma mesma estrela** — the signature journey | package template (new) |
| 3 | **O que atravessa os dois céus** — four shared threads + the master panorama | package template (new, authored copy) |
| 4 | Agenda de Natal | shared upcoming-events engine (existing) |
| 5 | Um lugar para o solstício (Newgrange) | existing `leisure` record |
| 6 | Cuidar do inverno | existing `guide` record |
| 7 | Leituras de inverno | three existing posts |
| 8 | Guias úteis para as festas | three existing guides |
| 9 | **Uma luz que continua depois de dezembro** — inclusive close + one real CTA | package template (new) |

The five reuse modules are unchanged apart from their headings/intros now resolving per
language (§8); they still address records by slug, never by ID, and still render nothing
when a record is missing. A suite asserts ≥ 3 modules and ≥ 4 real records after the
event window (§10.1).

The archival exception is intact and re-proved: `/natal/` and `/en/christmas/` still serve
200 with their own package CSS handle, JS and images while **no Christmas event is active**
(today is 2026-11-06; the window is 10–25 December) — §10.3.


## 7. Architecture compliance

| r10 boundary | Evidence that it holds |
|---|---|
| `conexao-commemorative` stays the theme-independent scheduling engine | **No plugin file was modified by this task** (`git status` lists none). No resolver, collision algorithm, scheduler, timer, cache, route or content registry was added. |
| One event → one package → only that package's placements | The `christmas` package key is reused unchanged; the placements are the same three (`homepage_hero`, `site_wide_ambient`, `landing_page`). The new suite asserts that exact key list, so a fourth placement cannot appear unnoticed. |
| The theme owns presentation | All new markup, CSS, JS and art is theme-side, inside the package's own template and asset folders. |
| Registry is the single source of truth | The package entry was edited **in place** (motif, four tokens, motion, voice, do-not list, decoration asset) and the stylesheet mirrors the four tokens exactly, as `test-commemorative-packages.php` requires (parity test still green). |
| Fail-closed validation | Unchanged: missing templates/assets/tokens are still dropped silently. The new `journey`/`star` parts are *content templates* of an already-validated placement, so validation semantics are untouched. |
| Polylang single-owner rule | Untouched — no `_ce_*` write, no meta copy, no English shared configuration. `commemorative_translation_ownership` still passes (30 assertions). |
| Routes, canonical, hreflang | No rewrite, no new route, no duplicate page. Both routes answer 200 and remain excluded from the sitemap by the unchanged decision function (suite still green). |
| Landing-page asset exception | Preserved, and re-documented in `docs/commemorative-content.md` and `docs/themes/conexao-br-irlanda.md`; re-proved over HTTP (§10.3). |
| Zero commemorative assets on ordinary routes | Re-proved on `/`, `/guias/`, `/lazer/`, `/empregos/`, `/en/`: no `conexao-ce-*` handle, no package CSS/JS/image reference (§10.3). |
| No public activation, no CPT exposure, no REST surface | Untouched; `commemorative_no_public_activation` and `commemorative_cpt_privacy` still pass. |
| No Christmas code in the global bundles | `assets/css/main.css`, `assets/js/main.js` and `assets/css/dark-mode.css` are **unmodified** (`git status` = 0 changes). Package CSS/JS grew only inside `presentations/christmas/`. |

The engine's public API, the occurrence-year rules, the schedule windows, the cache-boundary
logic and the annual precedence were not touched. The only registry-visible identity change
is the documented one: `motif` `bauble_ornament` → `shared_star`, the four token values, the
motion description and the decoration asset name — mirrored in the package stylesheet in the
same edit.


## 8. Content and localisation

**Reused, not rewritten.** The page's title and intro, the five reuse modules (their records
and their relationships), the event records, the editorial keyword list and every existing
string come from the repository as they were. No record, option, term, user or attachment
was created, edited or deleted, and no migration or seed script was run.

**What was added (package copy, HTML only — never baked into an image).**

| Surface | PT-BR | English |
|---|---|---|
| Hero headline | Um Natal, dois céus | One Christmas, two skies |
| Hero CTA | Explorar os dois céus | Explore both skies |
| Journey h2 | Dois céus, uma mesma estrela | Two skies, one star |
| Journey control | Escolha um céu · Brasil/verão · Irlanda/inverno | Choose a sky · Brazil/summer · Ireland/winter |
| Brazil panel | Verão, e a noite custa a chegar (+ 3 details + nuance) | Summer, and the night takes its time (+ 3 + note) |
| Ireland panel | Inverno, e são as janelas que acendem (+ 3 details + nuance) | Winter, and the windows do the lighting (+ 3 + note) |
| Shared line | O que atravessa os dois céus não é o clima… | What crosses both skies is not the weather… |
| Threads section | O que atravessa os dois céus (4 threads + caveat) | What crosses both skies (4 threads + caveat) |
| Close | Uma luz que continua depois de dezembro | A light that outlasts December |

**Why the copy is written twice rather than translated at runtime.** The package resolves
each language explicitly (`$ce_english ? __( '…' ) : __( '…' )`), the convention this package
already used for its hero. That makes the English page fully English **now**, even though
the `.pot` regeneration is deferred (§13) — verified in a real browser: `/en/christmas/`
renders “Two skies, one star”, “Winter, and the windows do the lighting”, “Choose a sky” and
no Portuguese heading (§10.3). It also means nothing is a literal mirror: the English reads
as English (“It's dark by mid-afternoon…”, “Plenty of people spend this season working,
alone, or simply differently”).

**Sources for the factual content.** The copy makes almost no cultural claim; the two
factual anchors are the seasons themselves.

| Claim in the copy | Basis |
|---|---|
| “December in Brazil is summer” / “the sun is slow to go down” | Southern-hemisphere season and the long December days around the summer solstice — almanac-level fact. No tradition asserted. |
| “December in Ireland is winter”, “the dark arrives in the middle of the afternoon” | Northern-hemisphere winter; Dublin's December sunsets fall around 16:00–16:15, so “middle of the afternoon” is a deliberate non-numeric hedge rather than a figure. |
| Newgrange and the winter solstice (module 5) | **Existing approved record** (`newgrange`); nothing new was written about it, and OPW / Heritage Ireland remains the standing source. |
| “Some people spend Christmas far from family, some work that day, and some do not celebrate it” | An inclusion statement, not a cultural claim about either country. |

Traditions that would need sourcing were **not** asserted: no claim about specific foods,
masses, gift customs, windowsill candles, or “Irish Christmas” as a monolith. Where the copy
gestures at how a season *can* feel it uses hedges (“muitas casas”, “costuma”, “pode
acontecer”), and every perspective closes with an explicit caveat.


## 9. File inventory

**15 modified · 1 deleted · 12 added (source/assets/tests) · 23 evidence files · 1 report.**
Nothing outside `wp-content/themes/conexao-br-irlanda/`, `tests/` and `docs/` was touched.

| Path | Change | Why |
|---|---|---|
| `…/inc/commemorative-presentations.php` | modified | package identity: motif `shared_star`, four new token values, 700 ms motion description, voice examples, do-not list, decoration asset renamed `star` |
| `…/inc/commemorative-modules.php` | modified | `upcoming_events` accepts an optional localised `label` (additive; default unchanged) so an English page is not forced to print “Evento” |
| `…/template-parts/commemorative/christmas/hero.php` | rewritten | panorama + copy as siblings, one CTA, star motif, responsive `<img>` |
| `…/christmas/landing.php` | rewritten | journey → threads (+postcard) → five unchanged modules → inclusive close; per-language module headings |
| `…/christmas/ambient.php` | modified | star asset, `data-ce-star-asset` |
| `…/christmas/journey.php` | **new** | the signature two-sky scene (server-rendered, no-JS complete) |
| `…/christmas/star.php` | **new** | the code-drawn shared star motif (SVG, decorative by default) |
| `…/assets/css/presentations/christmas.css` | rewritten | “One Christmas, Two Skies” design system: tokens, hero band, journey, threads, close, ambient, responsive, reduced motion |
| `…/assets/js/presentations/christmas/christmas.js` | rewritten | `createJourney()` (tabs upgrade, no scheduler) + `createStarDecoration()` + `boot()` |
| `…/assets/images/…/christmas/decorations/star.svg` | **new** | ambient star |
| `…/assets/images/…/christmas/decorations/bauble.svg` | **deleted** | retired with the motif |
| `…/assets/images/…/christmas/two-skies/*.webp` (6) | **new** | measured derivatives of the three supplied compositions |
| `…/tests/test-commemorative-christmas-journey.php` | **new** | 82-assertion contract suite: scene, no-JS fallback, hero, ambient marker, assets, PT/EN parity |
| `…/tests/test-commemorative-presentation-dispatch.php` | modified | motif assertions (`bauble_ornament` → `shared_star`) |
| `…/tests/test-commemorative-packages.php`, `…/tests/test-commemorative-ambient.php` | modified | label text only (“bauble” → “star”); no threshold changed |
| `tests/node/christmas-journey.test.js` | **new** | 8 runtime cases driving the real controller with a fake DOM |
| `tests/scripts/verify-commemorative-runtime.py` | modified | runs **both** Node suites inside the one existing gate (no second runner) |
| `tests/browser/verify-christmas-signature-browser.py` | **new** | opt-in real-Chromium verifier + screenshots (local only, refuses production) |
| `docs/commemorative-content.md` | modified | the `christmas` brief (motif, palette, motion, voice, modules, do-not) |
| `docs/themes/conexao-br-irlanda.md` | modified | package paths + a “Christmas signature scene” internals table |
| `docs/reports/README.md` | modified | index row for this report |
| `docs/evidence/README.md` | modified | this evidence folder added to the index |
| `docs/evidence/2026-11-06-christmas-signature-experience/` (23 files) | **new** | screenshots, browser JSON, gate/suite output, byte measurements, git snapshots |
| `docs/reports/2026-11-06-commemorative-events-christmas-signature-experience.md` | **new** | this report |

### 9.1 Why the change in `inc/commemorative-modules.php` is not a duplicate engine

`conexao_commemorative_module_upcoming_events()` hard-coded `__( 'Evento' )` as the type label
for every caller. The new optional `label` argument lets a package pass its already-resolved
string instead of forcing a second gettext lookup; every existing caller keeps the exact
previous behaviour, and no new query, cache or registry was introduced.


## 10. Tests and evidence

Everything below ran on this machine against the local Docker stack
(`wordpress-website-wordpress-1`, WordPress on `http://localhost:8080`). Raw output is in the
evidence folder; nothing here is inferred.

### 10.1 In-process PHP suites (real WordPress bootstrap, read-only)

| Command | Result |
|---|---|
| `docker compose exec -T wordpress php /var/www/html/…/tests/test-commemorative-christmas-journey.php` (**new**) | **82 passed, 0 failed** |
| The same command over all 15 commemorative/seasonal suites | **639 assertions passed, 0 failed** (ambient 53, christmas-journey 82, gate-ambient-decoration 7, gate-assets 5, gate-cpt-privacy 11, gate-placement-assets 4, gate-public-activation 5, gate-single-package 6, gate-translation-ownership 30, landing-page 45, packages 131, presentation-dispatch 99, halloween-nav-effects 114, halloween-presentation 33, seasonal-occasion 14) |
| `./scripts/run-tests.sh --only conexao-br-irlanda --php` (whole theme layer) | **65 suites: 55 passed, 10 failed; 3,797 assertions passed, 37 failed** — the 10 failures are pre-existing localisation/jobs suites (§13.1) |

What the new suite asserts (one line may be several assertions):

- the package keeps **exactly** the three approved placements and the `shared_star` motif;
- both perspectives are server-rendered with their own heading, paragraph, three details and
  note, and **the control ships `hidden`**;
- **no** `role="tab"`, `role="tablist"` or `role="tabpanel"` exists before the script runs (the
  fallback must not pretend to be a widget);
- tab ids ↔ `aria-controls` ↔ panel ids are unique and paired;
- both stars are SVG, `aria-hidden="true"` and `focusable="false"`; the scenes contain **no**
  `<img>`;
- all six derivatives exist, are non-empty and stay under 300 KB; no PNG master is shipped; the
  ambient marker points at `star.svg` and never at `bauble`;
- the hero has **exactly one** `<a>`, keeps an `h2` (never an `h1`), declares intrinsic
  width/height, carries alt text and links to the landing page;
- PT and EN render **different** copy (no leakage either way) with **identical** detail counts.

### 10.2 Node runtime gate (behaviour that cannot be grepped)

`python3 tests/scripts/verify-commemorative-runtime.py` → **`status: pass`, 38 passed**, 19
runtime cases across two suites:

- `tests/node/commemorative-ambient.test.js` — **11 cases** (unchanged): intervals, cap, node
  removal, visibility pause, reduced motion;
- `tests/node/christmas-journey.test.js` — **8 new cases**: roles / `aria-labelledby` / control
  reveal, initial state, switching (state + visibility + tab order), unknown-key safety, click
  selection, Arrow/Home/End with focus follow and wrap-around, **fail-closed on an incomplete
  scene**, and **“not a scheduler”** — `setTimeout`/`setInterval` are patched during a full
  interaction sequence and must be called **zero** times.

Evidence: `node-runtime-gate.txt`.


### 10.3 Real-browser verification (Chromium via Playwright)

`python3 tests/browser/verify-christmas-signature-browser.py` →
**`PASS`, 0 failures** on `/natal/` and `/en/christmas/`.
Browser: the Playwright-cached **Chromium** (`browser-verification.json` records the exact
version). Viewports: **320, 390, 768, 1366 px**, each with a full-page screenshot.

| Check (both locales) | Result |
|---|---|
| Route answers 200; exactly one `h1`; not a preview | pass |
| Two controls, two panels, `role="tablist"`, both `role="tabpanel"`, each labelled by its own control | pass |
| Exactly one visible sky; equal detail depth `[3, 3]`; zero `<img>` inside the scenes | pass |
| Every star `aria-hidden`; no star focusable | pass |
| Click switches the sky; `aria-selected` follows; one visible sky remains | pass |
| `ArrowRight` moves to the next sky; `End` jumps to the last; focus follows the selection | pass |
| No horizontal overflow at any of the four widths | pass — **after a real defect was found and fixed** (below) |
| Reduced motion → star and control transitions compute to `0s` | pass |
| No console error/warning, no page error, no failed request | pass |

**Defect found by this check and fixed:** at 320 px the Portuguese close widened the document
by 5 px (the closing `<h2>` and then the CTA pill were sized to their content inside a centred
grid). Fixed by clamping the close's children to `max-width: 100%` and letting the button label
wrap; re-verified green at 320 px in both locales.

**Hero band — synthetic harness.** The real homepage needs an active event or an authenticated
preview, and this task may create neither, so the dispatcher's own output for
`2026-12-15 12:00:00` was mounted inside a copy of the homepage hero structure with the real
theme CSS (`docs/evidence/…/christmas-hero-harness.html` + four screenshots). Measured at
769/800/900/1024/1200/1366/1600 px: band height 243–261 px, reserved padding 286–336 px, **no
overlap between the band and the hero copy at any width**. The harness is a rendering aid, not a
product artefact: the hero's *markup contract* is proven in-process (§10.1).

### 10.4 Static gates

| Command | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — 583 PHP files: no parse errors; PHPCS: **no new violations** against the legacy baseline (2,837 errors / 2,230 warnings in 241 files vs baseline 3,290 / 2,672 — debt paid down in 36 sniffs); PHPStan level 5: **No errors** |
| `php scripts/generate-registry-docs.php --check` | **OK** — 11 plugins validated, 20 generated regions current, zero writes |
| `python3 tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed** (15 living docs carry their marker; no root-level report; AGENTS.md 230 lines) |
| `./scripts/run-tests.sh --scripts` | **9 of 10 suites pass**; the only failure is `verify-i18n-freshness.py` (§13.2) |
| `python3 scripts/verify-permanent-gates.py --out /tmp/xmas/gate.json` | **15 gates: 13 passed, 2 failed, 0 blocked; 188 assertions passed, 5 failed** — the two failures are `translation_completeness` (pre-existing local data) and `i18n_freshness` (POT), §13. **All seven commemorative gates pass, including the runtime gate (38).** Run with `--out` so the tracked Stage L `gate.json` was **not** overwritten |

### 10.5 Asset dispatch, measured over HTTP

`http-zero-asset-and-archival.txt`, produced with plain Python `urllib` (no browser):

| Route | Christmas event active? | Commemorative references |
|---|---|---|
| `/` , `/guias/` , `/lazer/` , `/empregos/` , `/en/` | **no** | **NONE** — no `conexao-ce-*` handle, no package CSS/JS/image |
| `/natal/` , `/en/christmas/` | no (archival exception) | package CSS handle present (`conexao-ce-css-presentations-christmas` in the concatenated bundle), package JS present, 4 WebP references, 2 journey panels, control `hidden` in the source |

Because the local stack concatenates CSS, the stylesheet is identified by its **handle** in
`data-handles="…"` rather than by file path — the honest signal on this stack.

### 10.6 Not run (and why)

- **Six mutation-bearing engine suites** (window/precedence/cache boundaries): the brief forbids
  tests that mutate a non-disposable database. **Not run.**
- **HTTP acceptance matrix** (`tests/acceptance/verify-*-http.py`): it logs in as the local test
  administrator, which writes a session token; the brief forbids database writes unless the
  isolation is demonstrably disposable. Substituted with read-only HTTP checks (§10.5) plus the
  browser verification (§10.3). **Not run.**
- **Production sitemap / CDN / purge verification**: separately authorised work. **Not run.**
- **POT regeneration**: deferred by instruction (§13.2). **Not run.**


## 11. Performance

**Global bundles: unchanged.** `main.css`, `main.js` and `dark-mode.css` show **0** changes in
`git status`; no Christmas rule or line entered them.

**Package payload (before → after, raw / gzip):**

| File | Before | After | Δ |
|---|---|---|---|
| `assets/css/presentations/christmas.css` | 5,594 B / 1,914 B | 25,611 B / 6,712 B | +20 KB raw, **+4.8 KB gzip** |
| `assets/js/presentations/christmas/christmas.js` | 1,956 B / 1,003 B | 8,649 B / 2,888 B | +6.7 KB raw, **+1.9 KB gzip** |
| `template-parts/…/christmas/landing.php` | 4,494 B | 13,893 B | +9.4 KB of server HTML |
| `template-parts/…/christmas/hero.php` | 3,329 B | 4,826 B | +1.5 KB of server HTML |
| `template-parts/…/christmas/ambient.php` | 805 B | 834 B | +29 B |

Both package files load **only** when a Christmas placement renders — never on an ordinary route
and never for another package (re-proved in §10.5 and by `commemorative_placement_assets`).

**Images actually transferred** (Chromium, `image-transfer-bytes.json`; the site's own logos,
≈ 32 KB, excluded):

| Context | Christmas images fetched | Bytes |
|---|---|---|
| `/natal/` desktop, DPR 1, 1366 px | `star-bridge-780` 92.1 KB + `two-skies-postcard-760` 91.4 KB | **183.5 KB** |
| `/natal/` desktop, DPR 2, 1366 px | `star-bridge-1280` 206.9 KB + `two-skies-postcard-1120` 170.7 KB | **377.6 KB** |
| `/natal/` mobile, DPR 2, 390 px | `star-bridge-780` 92.1 KB + `two-skies-postcard-760` 91.4 KB | **183.5 KB** |

The mobile number is the result of the correction in §4.3 (−79 KB versus the first build).
Below-the-fold images are `loading="lazy"`; the hero art is `loading="eager"` with
`fetchpriority="low"` so it never competes with the site's LCP hero. No layout shift: every image
carries intrinsic dimensions and the hero band's space is reserved server-side.

**Runtime cost of the interaction:** one CSS transition per selection, no timers (proven), no
`requestAnimationFrame`, no canvas/WebGL/video, no third-party script.

**Honest limitation:** no Lighthouse/PageSpeed field run was made. The figures above are transfer
sizes, DOM/CSS facts and measured layout geometry — not Core Web Vitals.

## 12. Accessibility findings

Verified in-process (§10.1) and in a real browser (§10.3):

| Requirement | Finding |
|---|---|
| Landmarks and heading order | One `h1` per page (the page title), then the journey `h2`, its two `h3` perspectives, the threads `h2` + four `h3`, the five module `h2`s with `h3` cards, then the closing `h2`. Browser-verified order, no skipped levels. |
| Keyboard | Both controls are native `<button type="button">` inside the tablist, reachable with Tab; Arrow keys / Home / End move the selection **and** the focus (measured: `document.activeElement` is the selected tab); visible focus ring (`:focus-visible`, 2 px gold on the dark panel). No focus trap, no hidden focusable node. |
| Screen-reader semantics | Tabs expose `role="tab"`, `aria-selected`, `aria-controls`; panels expose `role="tabpanel"` + `aria-labelledby` pointing at their control. The decorative skies, the star motif and the ambient layer are `aria-hidden="true"` and `focusable="false"`; the star's trails and sparkles are decorative SVG. |
| State not by colour alone | The selected control is filled **and** gains a solid dot **and** keeps the heavier weight; assistive technology reads `aria-selected`. |
| No-JS | Both perspectives render with their headings and text; the control is `hidden`, so no unusable button is offered. |
| Contrast | Body copy `#ece5d6` on `#12173a` ≈ 11:1; headings `#fdf7ee` ≈ 14:1; notes `rgba(236,229,214,.82)` ≈ 8.5:1; gold label `#e8c273` ≈ 8:1; selected control `#1b2447` on a gold gradient ≈ 9:1. Light sections keep indigo/terracotta on ivory (≥ 6:1). No copy sits on artwork, so no scrim is needed. |
| Reduced motion | Browser-verified: with `prefers-reduced-motion: reduce` the star's and the control's transition durations compute to `0s`, and the ambient drift is `animation: none`. Nothing loops in either mode. |
| Zoom / reflow | 320 px is equivalent to 1280 px at 200 % zoom; **no horizontal overflow at 320, 390, 768 or 1366 px** in either language (that check found and killed a 5 px overflow, §10.3). |
| Images | The informational panoramas carry descriptive alt text naming **both** worlds; the decorative star and the ambient decoration are hidden from assistive technology. No text is baked into any image. |


## 13. Preservation snapshot

| | Start of task | End of task |
|---|---|---|
| Branch | `commemorative-events` | `commemorative-events` |
| `HEAD` | `657c3340f85f7b9f783edc85c43805ee8a35347c` | `b18fa58c1052b69340cdfc639e140f53ddbc3d08` |
| Stashes | **9** | **9** |
| Working tree | 19 modified + 7 untracked (pre-existing work) | 15 modified + 1 deleted + 8 new paths — **all from this task** |

Snapshots: `git-state-start.txt` and `git-state-end.txt`.

### 13.1 The `HEAD` moved during the session — and this task did not move it

Between the start-of-task snapshot and the end, `HEAD` advanced from `657c3340` to `b18fa58c`
(“*Add Conexao_Seasonal_Occasion class and tests for commemorative translation ownership*”,
committed 13:18:17 UTC). **No `git` write command was run by this task** — no commit, amend,
rebase, reset, clean, checkout, stash, pop or push. The commit is a fast-forward on the same
branch (`657c3340` is an ancestor of `b18fa58c`) that captured **exactly the pre-existing dirty
work** from the start snapshot: the 19 modified paths and the two untracked theme files, plus the
earlier evidence and reports. Nothing was lost or reverted, and none of that content was altered
by this task.

Consequence recorded honestly: that commit made `conexao-commemorative`'s `.pot` older than
`class-conexao-commemorative-admin.php`, which is why `i18n_freshness` reports **one** “new”
stale catalogue (§13.2). It is a side effect of that external commit, not of any file this task
edited — this task touched **no** plugin PHP.

### 13.2 Two repository gates stay red, for reasons this task did not create

Neither was introduced here, and neither was silenced: no baseline was edited, no allowlist was
extended, no gate was weakened.

1. **`translation_completeness`** — 3 violations: `post_type:guide:missing_en` 1
   (`carteira-motorista-brasileiros`) and `post_type:page:malformed_relationships` 2 (the
   `/empregos/` pages). These are the **same rows** the 2026-11-06 local-remediation report
   recorded as pre-existing local data (pages created/modified 2026-10-21).
2. **`i18n_freshness`** — 3 stale catalogues: `conexao-br-irlanda` and `conexao-admin-ux`
   (pre-existing, already in the gate's reporting baseline) and `conexao-commemorative` (from the
   external commit above). **POT regeneration is deliberately deferred** to the release/commit
   step, as the brief instructs; the new strings are `.pot`-regeneration work, not a defect in the
   experience.

### 13.3 The ten red theme suites are pre-existing localisation/jobs data

`test-blog-en-translation`, `test-en-jobs-shared-slug`, `test-event-filter-cache`,
`test-guide-en-translation`, `test-job-en-translation`, `test-jobs-en-language`,
`test-stage33-bilingual`, `test-stage41-rest-language`, `test-stage45-pages`,
`test-translation-completeness` — 37 failed assertions in total.

Why they are not attributable to this task: (a) every one of them asserts **local EN translation
/ Polylang relationship data** ("missing EN translation", "en_without_pt:empregos", "orphan EN
page"), and this task performed **no database write of any kind**; (b) a grep shows **zero**
references to `christmas` or `commemorative` in all ten files, so none of them loads a file this
task changed; (c) two of the failures are the exact rows the local-remediation report already
recorded as pre-existing local data. They were **not** re-verified against a pre-change tree,
because that would require reverting the working tree — explicitly forbidden.

### 13.4 Scope statements

- **Database writes: none.** No post, page, option, term, user, attachment or meta was created,
  edited or deleted; no seed, migration, importer or repair script was executed.
- **Production: untouched.** No production request, credential, configuration change, cache purge,
  activation/deactivation, deployment, release, tag, commit or push.
- **Artifacts: untouched.** `dist/release.json` was not regenerated, the theme version was not
  bumped, no release ZIP was built.
- **The gates aggregator wrote only to `/tmp`** (`--out /tmp/xmas/gate.json`), so the tracked
  Stage L `docs/evidence/2026-09-26-stage-l/gate.json` was not overwritten.


## 14. Remaining limitations

| Item | Status |
|---|---|
| Production sitemap serving path and `/natal/` / `/en/christmas/` inclusion | **NOT VERIFIED** (unchanged; separately authorised) |
| Independent administrator validation of annual precedence (M5) | **NOT VERIFIED** (unchanged) |
| `dist/release.json` regeneration and the proposed theme version bump `1.0.2 → 1.0.3` | **Not done** — release preparation, not this task |
| POT regeneration for the new/changed strings | **Deferred** to the commit/release step by instruction (§13.2) |
| Six mutation-bearing engine suites | **Not run** — no demonstrably disposable database (§10.6) |
| Aggregate `run-tests.sh` stall in unrelated importer suites | **Unchanged** — not touched by this task; the theme layer was therefore run scoped (`--only conexao-br-irlanda --php`) |
| `docs/evidence/README.md` 2026-11-06 row | Was already incomplete before this task (it listed only `evidence-directory-audit` while three commemorative folders existed). This task added **its own** folder and did **not** retro-fix the others |
| Lighthouse / Core Web Vitals field data | **Not measured** (§11 states the limitation) |
| Real homepage hero in a live browser | Verified via a synthetic harness, not on the live homepage: the Christmas event is not active today and no preview/test-login was permitted (§10.3) |
| Dedicated mobile artwork + optional single-country scenes | **Recorded as a follow-up artwork request**, not fabricated (§4.4) |

## 15. Acceptance criteria — verdict

Every criterion from the brief §8, with the evidence that satisfies it.

**Creative and editorial**

- [x] *“One Christmas, Two Skies” expressed through a coherent magical visual identity* — §3, §4, `christmas.css`.
- [x] *Equal creative and editorial prominence* — one two-key data structure, identical structure per side, exact-parity assertions (§10.1), symmetric artwork.
- [x] *Original to Conexão Brasil–Irlanda, not generic* — twilight palette, shared star, custom scenes; the package's `do_not` list forbids generic red/green and flag mash-ups.
- [x] *Hero/landing artwork drawn from the supplied collection, with selection rationale* — §4.1.
- [x] *Supplied assets unchanged; installed files local, responsive and measured* — §4.2/§4.3; the sources were only read.
- [x] *Mobile crops preserve the Brazil/Ireland concept* — full 16:9 frame at every width, no horizontal crop; verified at 320 px.
- [x] *Cultural references thoughtful, accurate, non-stereotyped, sourced where factual* — §8.
- [x] *Inclusive of different family, cultural and religious experiences* — §3, §8.
- [x] *PT-BR and English naturally localised and complete* — §8; browser-verified per language.

**Signature experience**

- [x] *A memorable, user-controlled two-sky interaction* — §5.
- [x] *Both perspectives complete and equally accessible* — parity assertions + tab pattern.
- [x] *Keyboard, touch, screen reader and reduced-motion verified* — §10.3 (touch uses the same native buttons).
- [x] *Graceful no-JS fallback* — the control ships `hidden`; both skies render in full.
- [x] *No personal data, endpoint, persistent preference or database write* — §5, §13.4.

**Architecture and content**

- [x] *Existing `christmas` event and package reused* — no new record, no new key, no `xmas`-style alias.
- [x] *No new scheduler, resolver, event record, CPT route, REST surface or activation path* — §7.
- [x] *One-event/one-package/placement rules intact* — asserted.
- [x] *Polylang ownership intact and its gate passes* — 30 assertions.
- [x] *Landing routes, canonical and hreflang correct* — 200s, sitemap-exclusion suite green, no rewrite.
- [x] *Archival asset exception preserved and documented* — re-proved over HTTP.
- [x] *Ordinary no-event routes emit zero commemorative assets* — 5 routes, NONE (§10.5).

**Quality**

- [x] *No Christmas code in global bundles* — 0 changes to `main.css` / `main.js` / `dark-mode.css`.
- [x] *New assets optimised and locally hosted* — §4.2; no hotlink (asserted).
- [x] *No console errors, horizontal overflow, focus trap or broken CTA* — browser-verified.
- [x] *Light/dark and responsive checks complete* — dark-theme overrides verified, responsive verified at four widths.
- [x] *Focused suites and relevant permanent gates pass, exceptions explained* — §10, §13.
- [x] *Before/after bundle and asset measurements reported* — §11.
- [x] *Screenshots and test outputs stored in the evidence convention* — `docs/evidence/2026-11-06-christmas-signature-experience/`.

**Preservation and scope**

- [x] *Branch, `HEAD` and stash count unchanged by this task* — branch and stash count identical; `HEAD` moved by an external commit, fully documented in §13.1.
- [x] *Existing user changes preserved; no destructive Git command* — §13.1.
- [x] *No WordPress record, option or content created, modified or deleted* — §13.4.
- [x] *No production access/write/purge/activation/release/tag/commit/push/deploy* — §13.4.

Verdict: **all applicable criteria demonstrated**, with the two red repository gates and the
`.pot` deferral recorded rather than hidden (§13.2). This is **local implementation work**, not
release approval.


## 16. Recommended next step

1. **Review the experience with the editor/owner in the local stack** (both languages, both
   themes, 320 → 1366 px) and tune the two vignettes' wording if the tone needs it — the copy is
   plain data in `journey.php` / `landing.php`, so wording changes touch no layout.
2. **Decide on the artwork follow-ups** in §4.4 (a dedicated mobile hero composition is the only
   one with real upside; the single-country scenes are optional).
3. **Regenerate the theme `.pot`** as part of the eventual commit (`wp i18n make-pot`), which
   clears the one stale catalogue this task's strings contribute to.
4. **Then** the pre-existing items in §14 that are not this task's: the `.pot` for
   `conexao-commemorative`, the `translation_completeness` local data, the six mutation-bearing
   engine suites in a disposable database, and M5 plus the release manifest/version bump under the
   release workflow.
5. **Only after that**, and only with explicit authorisation, the production verification of the
   sitemap and the `/natal/` / `/en/christmas/` inclusion.

Nothing in this list authorises (or implies) a production or release action.

_Last verified: 2026-11-06 by the Christmas signature experience implementation (local only; no production write, no database write, no release)_

