# Commemorative content playbooks

> **Owner:** content editor + theme · **System reference:**
> [`commemorative-events.md`](commemorative-events.md) ·
> **Calendar and watchlist:** §4 below

A **package brief** is the contract between the developer, the editor, the
translator and the scheduler. Each brief has the seven fields r10 §7.12 requires.
The table below is the initial set; **only `halloween` and `christmas` are
implemented**, the rest are planned editorial briefs. A planned brief is *not* a
promise that the package ships — the event records exist, the visual identity does
not.

| Package | Status | Motif | Palette | Energy |
|---|---|---|---|---|
| `halloween` | **implemented** | one carved pumpkin | dark purple, orange, black, muted green | playful (bounded) |
| `christmas` | **implemented** | one shared star | midnight indigo, candle gold, terracotta warmth, warm ivory | calm |
| `new_year` | planned | one four-point sparkle | white, gold, silver, midnight blue | playful (bounded) |
| `st_patricks_day` | planned | one shamrock | green, gold, white | moderate |
| `valentines_day` | planned (one package, two occasions) | one heart | pink, red, cream | calm |
| `easter` | planned | one decorated egg | soft pastels, white, light green | calm |
| `carnival` | planned (no event record yet) | one stylised feather | vibrant pink, gold, turquoise, deep purple | playful |
| `festa_junina` | planned (no event record yet) | one triangular bandeirinha | warm orange, yellow, red, deep green, brown | moderate |
| `brazil_independence` | planned (no event record yet) | one stylised flag stripe | green, yellow, blue | moderate |

## 1. Implemented packages

### 1.1 `halloween` — implemented

| Field | Content |
|---|---|
| **Occasion summary** | Irish Hallowe'en (Oíche Shamhna) as it is lived in Ireland today: costumes, pumpkin carving, community events and family activities across the 16–31 October window. |
| **Signature motif** | **One carved pumpkin.** The hero card, the ambient decorations and the navigation effects all derive from it. Bats, moon, mist and the witch are subordinate supporting details only. |
| **Palette / tokens** | `--ce-primary #4b2163` (dark purple) · `--ce-secondary #e07b24` (orange) · `--ce-accent #f3a34c` (warm orange) · `--ce-surface #fff2dd` (warm cream). Black and muted green appear only inside the artwork. |
| **Motion / energy** | **playful**: 1 s effect handoff, `cubic-bezier(0.35, 0.03, 0.62, 1)`; ambient 30–60 s apart, 0.9–3 s per effect, one at a time, **session cap 7**. The mobile navigation surprises keep the approved 20–30 s / 25–35 s cadence. |
| **Copy voice** | Playful, warm, family-friendly; informal *você* / direct *you*. PT: “**Feliz Halloween!** — A época mais assustadoramente divertida chegou à Irlanda.” CTA: **Explorar eventos**. EN: “**Happy Halloween!** — The most spooktacular time of year has arrived in Ireland.” CTA: **Explore events**. |
| **Modules** | No landing page in this release. The package renders the homepage hero + site-wide ambient only. |
| **Do not** | No gore-heavy imagery. Nothing designed to frighten families or children. Never more than one ambient decoration at once. Never replace the approved navigation cadence. |

### 1.2 `christmas` — implemented

| Field | Content |
|---|---|
| **Occasion summary** | An Irish Christmas for the Brazilian community: concerts, markets and community gatherings, the Newgrange winter solstice, looking after yourself through the dark months, and reading for the long nights. Window: 10–25 December. |
| **Signature motif** | **One shared star.** Drawn as SVG/CSS (`template-parts/commemorative/christmas/star.php`), never cut out of the photograph. It appears in the hero band, as the control of the two-sky journey, in the closing panel and as the ambient decoration. The whole experience is “One Christmas, Two Skies”: a Brazilian summer evening and an Irish winter night joined by the same light. |
| **Palette / tokens** | `--ce-primary #1b2447` (midnight indigo) · `--ce-secondary #b34a37` (terracotta) · `--ce-accent #d9a441` (candle gold) · `--ce-surface #fdf7ee` (warm ivory). Evergreen, deep teal and soft amber live in the private `--ce-xmas-*` art tokens inside the package stylesheet. Brazilian warmth is coral/terracotta; Irish coolness is evergreen/teal. |
| **Motion / energy** | **calm**: one user-initiated 700 ms travel of the star towards the chosen sky, `cubic-bezier(0.22, 0.61, 0.36, 1)`, never a loop and never autoplay; ambient 60–120 s apart, 2–4 s per star, one at a time, **session cap 3**. Reduced motion removes both. |
| **Copy voice** | Warm, inclusive, quietly wondrous; informal *você* / direct *you*. It says how the season **can** feel, never how a country “is”, and each perspective closes with a note that says so. PT: “**Um Natal, dois céus** — No Brasil, a luz fica até tarde e a noite chega devagar.” CTA: **Explorar os dois céus**. EN: “**One Christmas, two skies**.” CTA: **Explore both skies**. |
| **Modules (the reuse map)** | The landing page `/natal/` (`/en/christmas/`) opens with the **signature two-sky journey** (“The Christmas Star: a journey between two skies”, server-rendered, complete without JavaScript), then **O que atravessa os dois céus** (four shared threads + the master panorama), then five modules backed by EXISTING records: (1) **Agenda de Natal** — the shared upcoming-events engine, filtered by an editorial Christmas keyword list; (2) **Um lugar para o solstício** — the `newgrange` leisure record; (3) **Cuidar do inverno** — the `inverno-irlanda-depressao-sazonal-saude-mental` guide; (4) **Leituras de inverno** — three winter blog posts; (5) **Guias úteis para as festas** — the travel and emergency-services guides. It closes with **Uma luz que continua depois de dezembro**. |
| **Do not** | Never present either country as culturally uniform. Never place copy over the artwork — every heading, sentence and button is real localised HTML. Never reuse the Halloween motif, palette or motion language. No countdown timers, no auto-playing music, no snow in Brazil. |

## 2. Planned briefs (not implemented)

Each row below is an editorial brief only. Shipping one means creating the package
(§5.1 of [`commemorative-events.md`](commemorative-events.md)), not only the event
record.

### 2.1 `new_year`

- **Occasion summary.** Réveillon and the Irish New Year, inside the seven-day
  `26 December → 1 January` window. The whole wrapping window belongs to the
  January end year (2026-12-26 → 2027-01-01 is occurrence year **2027**).
- **Motif.** One four-point sparkle.
- **Palette.** White, gold, silver, midnight blue.
- **Motion / energy.** Playful but *brief*: 30–60 s apart, 3–5 s, session cap 7.
- **Voice.** Bright and celebratory. PT: “**Um novo ano começa** — branco, lentilhas
  e boa sorte.” CTA: **Ver o que fazer no Réveillon**. EN: “**A new year begins** —
  white clothes, lentils and good luck.” CTA: **See New Year plans**.
- **Modules.** Brazilian Réveillon traditions (white clothes, lentils, grapes, sea
  offerings), Irish first-footing, celebrations in Dublin, community events.
- **Do not.** Do not reuse the Carnival palette. Do not imply the window is only
  31 December — it is **26 December → 1 January**.

### 2.2 `st_patricks_day`

- **Occasion summary.** St Patrick's Day in Ireland, 3–17 March, as experienced by
  the Brazilian community.
- **Motif.** One shamrock. **Palette.** Green, gold, white.
- **Motion / energy.** Moderate: 45–90 s, 3–6 s, cap 5.
- **Voice.** Celebratory, never bouncy. PT: “**São Patrício em Dublin** — o desfile, a
  música e a comunidade.” CTA: **Ver a programação**. EN: “**St Patrick's in Dublin** —
  the parade, the music and the community.” CTA: **See the programme**.
- **Modules.** How Brazilians celebrate in Ireland, parade information, Irish-Brazilian
  cultural connections, Irish music sessions, related events.
- **Do not.** Do not reduce Irish culture to leprechauns and pots of gold. Never use
  Carnival imagery.

### 2.3 `valentines_day` — one package, two occasions

- **Occasion summary.** Two distinct annual event records share this one visual
  package: **international Valentine's Day (7–14 February)** and **Dia dos Namorados
  (12 June)**. They are separate records with separate windows; the package is
  reusable identity, not a merged event.
- **Motif.** One heart. **Palette.** Pink, red, cream.
- **Motion / energy.** Calm: 60–120 s, 2–4 s, cap 3.
- **Voice.** Soft and warm. PT: “**Amor em Dublin** — presentes, jantares e o Dia dos
  Namorados.” CTA: **Ver onde comemorar**. EN: “**Love in Dublin** — gifts, dinners and
  Valentine's Day.” CTA: **See where to celebrate**.
- **Modules.** Irish vs Brazilian Valentine's traditions, where to find Brazilian gifts,
  romantic restaurants, related events.
- **Do not.** Do not reuse the Carnival visual. When the two windows ever overlap
  another occasion, the editor sets the annual-precedence box — the package stays
  unchanged.

### 2.4 `easter`

- **Occasion summary.** Páscoa in Ireland, using the engine's Easter rule with the
  existing 15-day lead-in through Easter Sunday.
- **Motif.** One decorated egg. **Palette.** Soft pastels, white, light green.
- **Motion / energy.** Calm: 60–120 s, 2–4 s, cap 3.
- **Voice.** Gentle, spring-like. PT: “**Páscoa em família** — tradições brasileiras e
  irlandesas.” CTA: **Ver a agenda da Páscoa**. EN: “**Easter with the family** —
  Brazilian and Irish traditions.” CTA: **See the Easter calendar**.
- **Modules.** Brazilian Páscoa vs Irish Easter, chocolate traditions, religious
  observances, family activities, related events.
- **Do not.** No Halloween-style imagery. Do not reduce Easter to chocolate eggs only.

### 2.5 `carnival`, `festa_junina`, `brazil_independence`

These three have **no event record yet**. Their briefs live in the calendar rows
below and in r10 §7.12; creating them is editorial work plus a developer turn.

- `carnival` — one feather, vibrant palette, playful. **Dates are entered or updated
  manually each year in wp-admin. There is no Carnaval date formula.**
- `festa_junina` — one bandeirinha, warm palette, moderate. Annual default 1–30 June;
  an editor may refine the window.
- `brazil_independence` — one flag stripe, green/yellow/blue, moderate. 1–7 September.

## 3. Content-operations policy (r10 §7.13)

1. **PT canonical authorship.** The PT copy is written on the canonical PT record.
2. **EN is a translation or an adaptation.** The editor decides per event, based on
   the audience, and records the decision and its rationale in `_ce_description`
   (internal, untranslated).
3. **Shared vs translated.** Scheduling, priority, package selection and
   `_ce_year_precedence` are shared and stored once on the PT record. Titles,
   headings, subheadings, CTA labels and CTA URLs are translated.
4. **Two reviews.** (1) content review — editor plus a community reviewer for
   culturally specific occasions (Carnival and Festa Junina especially); (2) technical
   review — preview in both languages, desktop and mobile, light and dark, reduced
   motion on.
5. **Quarterly calendar review.** The content editor owns the calendar and reviews it
   every quarter. Movable dates are updated manually in wp-admin.
6. **Reuse, never duplicate.** Modules surface existing records; the reuse map is
   part of each brief (§1.2 above shows a complete one).
7. **Empty-landing-page rule.** A landing page exists only when at least **three**
   real, non-duplicative modules can be populated. Otherwise the event uses the hero
   and the ambient layer only.
8. **Archival.** A landing page is never unpublished after its window; it is excluded
   from the sitemap unless inclusion is explicitly approved.
9. **Bilingual completeness.** An event is not marked reviewed until both languages
   exist or a PT fallback is explicitly accepted in `_ce_description`.


## 4. Content calendar and overlap watchlist

The calendar is an **editorial planning aid**, never a date-calculation engine. It
lists event records, not a promise that every package ships.

| Month | Event | Window / date operation | Package | Landing page |
|---|---|---|---|---|
| January | New Year (tail) | 1 January; same occurrence as the preceding 26–31 December window | `new_year` | `/ano-novo/` (planned) |
| February | Valentine's Day (international) | 7–14 February | `valentines_day` | optional |
| February/March | Carnaval | **entered or updated manually for each occurrence year** | `carnival` (planned) | `/carnaval/` (planned) |
| March | St. Patrick's Day | 3–17 March | `st_patricks_day` | `/st-patricks-day/` (planned) |
| March/April | Easter | the engine's Easter rule, 15-day lead-in through Easter Sunday | `easter` | `/pascoa/` (planned) |
| June | Festa Junina | 1–30 June (annual default; the editor may refine) | `festa_junina` (planned) | `/festa-junina/` (planned) |
| June | Dia dos Namorados | 12 June | `valentines_day` | optional |
| September | Brazilian Independence | 1–7 September | `brazil_independence` (planned) | `/independencia/` (planned) |
| October | Halloween | 16–31 October | `halloween` **(implemented)** | none in this release |
| December | Christmas | 10–25 December | `christmas` **(implemented)** | `/natal/` · `/en/christmas/` **(implemented)** |
| December | New Year (head) | 26 December–1 January; the occurrence year is the January end year | `new_year` | `/ano-novo/` (planned) |

### Manual overlap watchlist

**The engine never detects or calculates an overlap.** When two events are
eligible at the same time, the editor opens the event that should win and ticks the
annual-precedence box for that occurrence year. Nothing else changes: the checkbox
orders already-eligible events, it never changes a date or an eligibility.

| Events to review | When | Admin action |
|---|---|---|
| Carnaval and Valentine's Day (international) | before the February occurrence | update Carnaval's date/window manually; if both are eligible, tick the annual-precedence box on the event that should win |
| Easter and St. Patrick's Day | before March/April | review the actual windows; set a preference only if they overlap |
| Festa Junina and Dia dos Namorados | before June | review the configured June window; set a preference if the overlap needs a winner |
| Christmas and New Year | before December | no normal overlap is expected; verify the 25/26 December handover and the New Year occurrence year |

_Last verified: 2026-11-06 by the commemorative-events Stages 4–6 implementation_
_Last verified: 2026-11-06 by the Christmas signature experience (the `christmas` brief now describes the shared star, the twilight palette and the two-sky journey)_

