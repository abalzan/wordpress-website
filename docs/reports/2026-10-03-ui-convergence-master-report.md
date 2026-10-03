# Report — UI convergence Phase 2 (T1.6–T1.14), master execution

| | |
|---|---|
| **Stage / task name** | UI convergence **Phase 2** — remaining Tier-1 backlog (audit T1.6–T1.14) |
| **Date** | 2026-10-03 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `master` |
| **Start SHA** | `a89ea59` |
| **Skills followed** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `docs/routing.md`, `docs/audit/2026-10-03-ui-ux-audit.md` |

---

## 1. Executive summary

The authoritative roadmap is the UI/UX audit's **Tier 1–3 backlog**
(`docs/audit/2026-10-03-ui-ux-audit.md` §13). Phase 1 (T1.1–T1.5) was already
complete at `HEAD`. This phase executed **the remaining nine Tier-1 items**:
**7 implemented, 2 deliberately not implemented with evidence**. Tier 2
(T2.1–T2.8) and Tier 3 (T3.1–T3.5) are recorded in §3 as out of scope.

| Outcome | Items |
|---|---|
| Implemented | T1.6, T1.7, T1.8, T1.10, T1.11, T1.12, T1.13 |
| Not implemented (evidence recorded) | T1.9, T1.14 |
| Tier 2 / Tier 3 | Not in scope for this task (see §3) |

**Measured outcomes (152 renders, 19 pages × 4 widths × light/dark):**

| Metric | Before | After |
|---|---|---|
| Heading-order jumps (D4) | **5** affected URLs | **0** |
| Distinct card surface treatments | **4** | **2** (= the 2 themes) |
| Duplicated county rows on `/eventos/` | **7** | **0** |
| Untranslated `Uncategorized` on `/blog/` | **10** | **0** |
| Leisure placeholder boxes rendered | **20** (10 PT + 10 EN) | **0** |
| Light-mode AA contrast failures (gray `#adb5bd`) | **588** element renders | **0** |
| Horizontal overflow | **0** | **0** |
| `h1`/`main` count violations | **0** | **0** |

**Two of the audit's diagnoses were corrected by measurement** (T1.6 and
T1.13) — see §5. This continues the Phase-1 lesson that an audit may correctly
identify a symptom while misidentifying the cause.

---

## 2. Working-tree ownership

**The working tree was CLEAN at the start of this task.** Recorded exactly:

```
$ git status --short        -> (empty)
$ git diff --stat           -> (empty)
$ git diff --name-only      -> (empty)
$ git ls-files --others --exclude-standard -> (empty)
HEAD = a89ea59 "Update gate.json to reflect failure in i18n freshness check"
```

The task brief anticipated pre-existing owner changes; **there were none**, so
no owner work was at risk and none was discarded. No `git reset --hard`,
`git checkout` of owner work, or stash-and-drop was used against anything but
my own edits. Every change in this report is task-owned.

One `git checkout --` **was** used, on the six theme PHP files, to discard an
over-broad `phpcbf` auto-format that reformatted ~168 lines of pre-existing code
unrelated to this task (array literals in `event-card.php`, and similar). The
intended edits were then re-applied surgically, per the "no unrelated
refactoring" rule. This is disclosed rather than hidden.

---

## 3. Roadmap reconstruction and scope

| Tier | Items | Status |
|---|---|---|
| Tier 1 | T1.1–T1.14 | Phase 1 did T1.1–T1.5; **this phase did T1.6–T1.14** |
| Tier 2 | T2.1–T2.8 | **NOT IN SCOPE.** Moderate/large refactors ("extract one shared card component", "rationalise 13 breakpoints", "dequeue inline styles"). The audit rates them "High impact / moderate effort"; a distinct programme. |
| Tier 3 | T3.1–T3.5 | **NOT APPLICABLE.** The audit marks all five "**not recommended now**" and states Tier 3 "should only be reconsidered after Tier 1 and Tier 2 are complete and re-measured". Tier 2 is not done, so the audit's own precondition is unmet. |

**No phases or stages were invented.** No T2.x/T3.x item was implemented.

---

## 4. Phase matrix (real measured values)

| Phase | Stage | Before | After | Result |
|---|---|---|---|---|
| Phase 2 | **T1.6** D4 heading order | 5 URLs with a jump (`h1→h3` ×4, `h1→h4` ×1) | **0** | **PASS** |
| Phase 2 | **T1.7** I3/I4 card surface | 4 distinct (radius, border, shadow, bg) | **2** (= light + dark) | **PASS** |
| Phase 2 | **T1.8** D7 duplicate county | 7 `--county` rows on `/eventos/` | **0** | **PASS** |
| Phase 2 | **T1.9** §6.2 date badge | 4 treatments | 4 (unchanged) | **NOT IMPLEMENTED — blocked** |
| Phase 2 | **T1.10** D9 comment submit | unstyled native `<input id=submit>` | `.btn` contract, 44px, focus ring | **PASS** |
| Phase 2 | **T1.11** D10 `Uncategorized` | 10 occurrences on `/blog/` | **0** | **PASS** |
| Phase 2 | **T1.12** D6 leisure placeholder | 20 placeholder boxes (PT+EN) | **0** | **PASS** |
| Phase 2 | **T1.13** D3/I13 gray-500 | **588** failing light renders | **0** | **PASS** |
| Phase 2 | **T1.14** §6.1 homepage blocks | 13 quick-access + 6 help chips | unchanged | **NOT IMPLEMENTED — product** |
| Phase 1 | T1.1–T1.5 | complete at `HEAD` | **preserved** (guards green, §6) | **PASS** |
---

## 5. Root-cause corrections

### T1.6 — the audit's shape was wrong

```
Original symptom      heading-level jumps on 9 pages
Original diagnosis    "h1 -> h4 jumps on the 9 affected pages"
Measured root cause   h1 -> h3 on the event + course archives, because those
                      grids sit under the page <h1> with NO <h2> above them,
                      and the card title was a hard-coded <h3>. Separately,
                      one h1 -> h4 came from the FOOTER column title, a
                      different component at a different landmark scope.
Actual fix            Resolve the level: archive/home/search -> <h2>; other
                      contexts -> <h3>; clamp any caller value to h2..h6.
                      Footer column title -> <h2>.
Why this layer        Heading level is a SEMANTIC property of position in the
                      document, not a style. Styling is class-based, so the
                      element level changes no pixel. Fixing it in CSS would
                      have been impossible - CSS cannot change an element's tag.
```

### T1.13 — the audit identified one of two causes, and the smaller one

```
Original symptom      gray-500 used in "14 remaining rules"
Original diagnosis    reduce gray-500 usage in those rules
Measured root cause   TWO causes. (a) 11 direct `color: var(--conexao-gray-500)`
                      declarations in main.css - the audit's guess. (b) THE
                      LARGER cause: `--color-text-muted` in design-system.css
                      was itself aliased to gray-500, so *every* element
                      resolving through that token failed (card meta, filter
                      counts, pagination ellipsis, breadcrumbs, newsletter
                      privacy). Fixing only the 14 rules would have left the
                      bigger failure untouched.
Actual fix            (a) 11 declarations -> --conexao-gray-600 (the value this
                      same file already chose for .featured-article-meta);
                      (b) the --color-text-muted alias -> gray-600.
Why this layer        The token IS the contract. Editing the 14 call sites
                      would have been 14 local patches over one wrong
                      definition, and the next component using the token would
                      silently inherit the failure again.
Dark mode             Deliberately untouched and verified: dark
                      --color-text-muted is #94a8ba = 7.36:1 on the dark body
                      / 6.72:1 on the dark card. Only light mode was failing.
```

---

## 6. Phase 1 preserved (regression guards)

| Guard | Result |
|---|---|
| **T1.3/T1.4** card-title treatments | **1** (`18px/600/23.4px`) across `event-card-title`, `leisure-card-title`, `post-card-title`, `provider-card-title` — unchanged |
| **T1.5** orange restraint | 0 failing light-mode orange text (carried from Phase 1) |
| **T1.1** `/empregos/` | dead space 0, no regression |
| **T1.2** `/eventos/` duplicates | `test-ui-convergence-t1.php` still **70 passed, 0 failed** |
| Dark mode | 0 overflow, 0 a11y invariant failures across all dark renders |

---

## 7. Verification (real numbers)

| Check | Command | Result |
|---|---|---|
| Static quality | `./scripts/lint.sh` | **OK** — syntax clean, **no new PHPCS violations**, PHPStan **0 errors** |
| PHPCS debt | (inside lint) | **2857 → 2841 errors** (net **−16**); baseline 3290 not touched |
| PHPStan | level 5 | **0 errors** |
| In-process PHP | `./scripts/run-tests.sh` | 84 total, 54 passed, 30 failed |
| Assertions | | **3240 passed, 5 failed** (baseline: 3208 passed, 4 failed) |
| Script-contract | | 18 total, 16 passed, 2 failed |
| HTTP acceptance | | 4 total, 3 passed, 1 failed |
| New suite | `test-ui-convergence-t2.php` | **38 passed, 0 failed** |
| Registry drift | `php scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 generated regions current, **zero writes** |
| Permanent gates | `python3 scripts/verify-permanent-gates.py` | **6/7 pass**; `i18n_freshness` fails **1 pre-existing, 0 new** |
| i18n catalogue | `./scripts/i18n-make-pot.sh conexao-br-irlanda --apply` | regenerated via the **canonical generator** (§9.3); `Sem categoria` present |
| Browser matrix | Playwright/Chromium | **152 renders, 0 errors**; 19 pages × 4 widths × 2 themes |

### Failing-suite comparison (the regression test that matters)

| | Baseline (`a89ea59`) | After |
|---|---|---|
| Failing suites | **32** | **32** (same set + 1 flaky importer test) |
| In-process failing | 29 | 29 + 1 flaky |

**The one delta is `plugin/conexao-event-importer/test-error-handling.php`**
("History entry was added"), a plugin test this task never touched. It was
proven **pre-existing and flaky**:

```
With MY changes:      55 passed 0 failed / 54 passed 1 failed / 54 passed 1 failed
With my changes STASHED (baseline code):  54/1, 54/1, 54/1, 54/1
```
---

## 8. Negative proofs

Seven mutation attempts (5 distinct + 2 re-runs after closing gaps).
**The harness distinguished real from vacuous, and two vacuous mutations were
found and closed** — this is the substantive result of this section.

| # | Mutation | Detected? | Verdict |
|---|---|---|---|
| 1 | Restore hard-coded `<h3 class="event-card-title">` (undo T1.6) | `FAIL: no hard-coded <h3 class="event-card-title">` | **REAL** |
| 2 | Revert only `--color-text-muted` to gray-500 | **initially PASSED → VACUOUS** | gap closed |
| 2′ | *(after closing the gap)* | `FAIL` ×2 (token assertions) | **REAL** |
| 3 | Force the leisure image block always on (undo T1.12) | `FAIL` in **both** suites | **REAL** |
| 4 | Delete the county from the merged row (the *wrong* fix) | `FAIL: the county is still rendered…not deleted` | **REAL** |
| 5 | Revert one T1.11 call site | **initially PASSED → VACUOUS** | gap closed |
| 5′ | *(after closing the gap)* | `FAIL` ×2, each call site separately | **REAL** |

**Totals:** 7 attempts → **5 real mutations**, **2 vacuous mutations found and
closed**, **0 fail-open**.

### The two vacuous mutations, and why they mattered

**(NP2)** Reverting only the T1.13 **token** left the suite green, because the
original test asserted only `main.css`. The token was the *larger* of the two
causes. Fixed by adding three assertions: the alias must be gray-600, no
`design-system.css` block may re-alias it to gray-500, and dark mode must
**keep** its own override (which pins that a future "fix" cannot degrade dark
mode while repairing light).

**(NP5)** Reverting one T1.11 call site left the suite green: the assertion was
`substr_count(...) >= 2`, which still saw the *other* call site. Fixed by
asserting each call site individually and pinning the absence of the raw-name
form.

A third defect surfaced during NP5: several T1.11 template assertions sat
**after `test_finish()`** and therefore **never executed at all** — invisible in
the pass count, and found *only* because the mutation that should have been
caught was not. Fixed by moving `test_finish()` to the end; the suite went from
30 to **38 real assertions**.

Every mutation was reverted and the suite re-run green afterwards; the tree was
confirmed restored each time.

---

## 9. Content, translation and performance safety

- **No content was deleted or modified.** No post, meta, attachment or term was
  written. The T1.2 precedent (never delete real records to satisfy a visual
  metric) was respected: the duplicated event records are untouched.
- **T1.11 deliberately did NOT rename the `uncategorized` term.** The term is
  real and assigned to real posts; renaming it is a content change requiring the
  §5.2 six-step contract. Only the **display label** is resolved. Verified: the
  term, its slug, the `?categoria=` URL, the archive query, Polylang and the PT
  canonical record are all unchanged.
- **Polylang / B1-B2 / EN untouched.** No route, redirect, hreflang or language
  policy changed. `/en/empregos/` and `/en/lazer/` verified rendering correctly
  in the matrix.
- **Generated file:** `conexao-br-irlanda.pot` regenerated with the canonical
  `./scripts/i18n-make-pot.sh --apply`, never hand-edited (§9.3).
- **Hero / LCP untouched.** `<picture>`, desktop and mobile preload and
  `fetchpriority` were not modified. No new `Vary`, no viewport detection, no
  device-specific caching. This phase added **no JavaScript**.
- **Flutter/mobile repository never accessed.** No mobile inspection, test, build
  or deployment.

---

## 10. Responsive and theme verification

All four required widths (390 / 768 / 1024 / 1440) × light and dark were
measured on 19 pages.

- **Horizontal overflow: 0** on all 152 renders.
- **Layout shift / CLS:** no new image boxes are reserved (T1.12 *removes* a
  379×237 box), so CLS can only improve on `/lazer/`.
- **Card widths and readable measure:** unchanged (`.card` padding untouched).
- **Dark mode:** inspected by computed style, not source order — which is how
  the Phase-1 cascade bug was found. The T1.13 dark values were verified as
  **7.36:1 / 6.72:1 (AA-passing)** and deliberately left alone.

---

## 11. Accessibility verification

| Invariant | Result |
|---|---|
| Exactly one `<h1>` and one `<main>` | **0 violations** across 152 renders |
| Heading order | jumps **5 → 0** (WCAG 2.4.10) |
| Interactive target | comment submit **44px** minimum (WCAG 2.5.5) |
| Focus visibility | `#submit.submit:focus-visible` uses the shared `--focus-ring` |
| Color contrast | **588 → 0** failing light-mode text renders |
| Card link integrity | each leisure card retains exactly **one** primary link; the map CTA never replaces it |

No accessibility test was weakened. One test
(`test-leisure-card-map-action.php` §G) was **rewritten to pin the invariant
rather than the markup** — see §12.

It fails on **unmodified** code, so it is **not a regression** from this work.
---

## 12. Files changed

**Modified (8 source + 1 test + 1 generated):**

| File | Change | Items |
|---|---|---|
| `template-parts/event-card.php` | heading-level resolution; merged location row | T1.6, T1.8 |
| `template-parts/provider-card.php` | heading-level resolution | T1.6 |
| `footer.php` | column title `<h4>` → `<h2>` | T1.6 |
| `assets/css/main.css` | 11 gray-500 → gray-600; `.post-card` shadow removed; comment-submit `.btn` contract | T1.7, T1.10, T1.13 |
| `assets/css/design-system.css` | `--color-text-muted` alias → gray-600 | T1.13 |
| `assets/css/leisure.css` | placeholder rules **removed** (not left dead) | T1.12 |
| `template-parts/leisure-card.php` | image block gated on a real image | T1.12 |
| `home.php` + `inc/i18n/terms.php` | `conexao_blog_category_label()` | T1.11 |
| `languages/conexao-br-irlanda.pot` | regenerated by the canonical script | T1.11 |
| `tests/test-leisure-card-map-action.php` | §G pins the invariant, not the markup | T1.12 |
| `tests/test-ui-convergence-t2.php` | **new**, 38 assertions | all |

**Documentation:** `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md`,
`docs/README.md`, and this report.

**Evidence:** `docs/evidence/2026-10-03-ui-convergence-t2/` —
`browser-before.{json,txt}`, `browser-after.{json,txt}` (raw computed-style,
layout and a11y data for 152 renders, before and after), plus the test, lint,
registry and gate logs.

The Playwright harness lives in the gitignored `ui-convergence-work/`
(`diagnose.py`, `probe_p2.py`, `gen_probe.py`). Regenerate with
`python3 ui-convergence-work/diagnose.py <tag>`.

### Why `test-leisure-card-map-action.php` §G was changed

T1.12 removed the decorative `<a class="leisure-card-image">` for image-less
cards, so §G failed. Investigation showed the section's *purpose* is **"the map
CTA must not replace the card's primary link"** — which **still holds** (the
title link carries the destination; the test's own `image/title link points to
primary destination` assertion still passed). The assertions were coupled to the
pre-T1.12 markup, not to the invariant. They were rewritten to assert the
invariant directly, plus the new contract. **This is a test update, not a
weakened gate**: 47 assertions now pass (was 42 passing + 2 failing), and the
suite gained coverage.

---

## 13. Known limitations

1. **No production verification.** Production writes = **0**. Production has no
   CLI; nothing was deployed and nothing was authorised. All numbers are local.
2. **`i18n_freshness` permanent gate still red** — pre-existing, **0 new**. The
   gate compares **git commit times**, so it cannot be cleared until the
   regenerated `.pot` is committed alongside `inc/customizer.php`.
3. **Chromium only.** No Firefox, WebKit, real-device or automated axe/Lighthouse
   pass. Accessibility evidence is computed-style/DOM inspection plus WCAG ratio
   maths.
4. **T1.9 and T1.14 not implemented** (§4, §14) — the two open roadmap items.
5. **`verify-event-occurrence-http.py`** has 4 pre-existing failures documented
   in the Phase 1 report, unrelated to this phase.
6. **Fixture imagery.** `/lazer/` has no licensed images locally, so T1.12's
   *image-present* branch is proven by template/assertion, not by a visual render
   with real photography.
7. **Tier 2 / Tier 3 not started** (§3).

---

## 14. Items not implemented, and why

### T1.9 — event date badge (4 treatments → 2): **BLOCKED pending evidence**

The finding is **real and measured** (4 treatments: `16px/400`, `18px/600`,
`14px/400`, `14px/600`). But the badge sits inside `aria-hidden="true"`
decorative markup **alongside** a separate
`.event-card-date-iso.screen-reader-text` node carrying the machine-readable
`<time datetime>`. Collapsing four treatments into two requires deciding which
elements are redundant; a wrong choice degrades an accessible date that is
**currently correct**. Per the brief ("do not implement an item whose root cause
cannot be established" / "classify it honestly as requiring further evidence"),
it needs its own dedicated pass.

### T1.14 — homepage shortcut blocks: **NOT IMPLEMENTED (product decision)**

Measured: **13** `.quick-access-card` tiles and **6** `help-shortcut-card` chips
drawn from the *same* definitions, so 6 of 10 visible tiles are duplicated by
design. `front-page.php`'s own comments state this was **deliberate**: the help
section reuses the quick-access definitions as a single source of truth and
differs only in *selection*. Consolidating means **removing a navigation
affordance the maintainers chose on purpose** — a product decision, not a
convergence defect. The audit itself declined the homepage rewrite (T3.1: "the
current homepage works; the duplication (T1.14) is the real defect"). Recorded,
not silently skipped.

---

## 15. Final status

**Overall: `PASS WITH LIMITATION`**

All implemented items are complete and measured; two are honestly recorded as
not implemented with evidence. The limitation is environmental, not
---

## 16. Conclusion

1. **Did every authoritative phase execute?** Every **authorised** Tier-1 item
   executed. Tier 2/3 were not authorised and are documented as such (§3).
2. **Did every applicable stage execute?** 7 of 9 implemented; T1.9 and T1.14
   recorded with evidence, not skipped.
3. **Confirmed diagnoses:** T1.8 (duplicate location row), T1.10 (unstyled comment
   submit), T1.11 (native term leak), T1.12 (placeholder block), T1.7
   (card-surface divergence), and T1.13's *first* cause.
4. **Corrected after measurement:** **T1.6** (`h1→h3` on 5 URLs, not `h1→h4` on
   9) and **T1.13** (the token alias was the larger cause than the 14 rules).
5. **Measurable improvements:** heading jumps 5→0; card surfaces 4→2; duplicate
   county rows 7→0; untranslated terms 10→0; leisure placeholders 20→0; failing
   light-mode contrast renders **588→0**; comment submit now 44px with a focus
   ring; new 38-assertion regression suite; PHPCS debt −16; PHPStan 0 errors.
6. **Genuine limitations:** no production verification; `i18n_freshness` red
   pre-existing; Chromium-only; T1.9/T1.14 open; Tier 2/3 not started.
7. **Was production touched?** **NO. Production writes = 0.** No deployment, no
   content change, no Polylang change, no upload, no activation.
8. **Was Flutter/mobile untouched?** **YES** — never accessed.
9. **Ready for the next deployment step?** **Yes, with one prerequisite:** the
   regenerated `.pot` must be committed together with the theme sources so the
   `i18n_freshness` gate can clear. Nothing else blocks it.

**Production writes: 0.**

---

## 17. Rollback

Every change is CSS, theme-template logic, one display helper and a test. There
is **no data migration, no content change, no database write and no cache
invalidation** to undo. The asset cache-buster is `filemtime()`, so reverted
files are re-served immediately.

```bash
# whole phase
git checkout -- wp-content/themes/conexao-br-irlanda/
git checkout -- docs/themes/conexao-br-irlanda.md docs/frontend.md docs/README.md
rm -rf docs/evidence/2026-10-03-ui-convergence-t2
rm -f docs/reports/2026-10-03-ui-convergence-master-report.md

# per item
# T1.6  git checkout -- template-parts/event-card.php template-parts/provider-card.php footer.php
# T1.7  assets/css/main.css        (also reverts T1.10/T1.13)
# T1.8  git checkout -- template-parts/event-card.php
# T1.10 assets/css/main.css        (#submit.submit block)
# T1.11 git checkout -- home.php inc/i18n/terms.php languages/conexao-br-irlanda.pot
# T1.12 git checkout -- template-parts/leisure-card.php assets/css/leisure.css
# T1.13 git checkout -- assets/css/main.css assets/css/design-system.css
```

After any rollback, re-run `./scripts/lint.sh` and
`tests/test-leisure-card-map-action.php` (which now expects the T1.12 contract).

---

_Last verified: 2026-10-03 by UI convergence Phase 2 (T1.6–T1.14), agent Cline_
implementation: **no production verification was possible or authorised**, the
`i18n_freshness` gate is red **pre-existing** (0 new violations) and cannot clear
until commit, and browser verification is Chromium-only.

### Pre-existing failures (unchanged, not mine)

- `tests/scripts/verify-stage14-secret-scan.py` — environmental (needs a WordPress
  reachable from the host shell).
- `verify-i18n-freshness.py` — the gate measures **git commit time**; the theme's
  `.pot` is 265s older than `inc/customizer.php` from **Phase 1's** commit. This
  cannot be cleared by an uncommitted regeneration; it needs a commit. Reported
  as **0 new violations**.
- 27 translation-automation / translation-rollout suites — fail identically
  before and after; untouched.
- `tests/acceptance/verify-event-occurrence-http.py` — 4 pre-existing failures
  documented in the Phase 1 report; not re-litigated here.
- 4 theme translation suites — unchanged.