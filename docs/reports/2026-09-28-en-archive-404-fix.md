# Report — Fix broken English archive routes: `/en/apoiadores/`, `/en/eventos/`, `/en/guias/`

| | |
|---|---|
| **Task** | Regression investigation + minimal forward fix for three 404ing EN routes |
| **Date** | 2026-09-28 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `e7303ba47d0080e3778ba7e70dd5b9c29f44e170` (recovery closeout) |
| **Recovered baseline** | `b47d098` — `git diff b47d098 HEAD -- wp-content/themes/` = **empty** |
| **Rollback tag** | `recovery-snapshot-before-restore` (`bd81ba5`, not moved) |
| **Final status** | **PASS WITH LIMITATION** |

All three reported routes were intended to work. They were **not** intended to be
manufactured, redirected, or allowlisted: the repository's routing code was never
broken, and this fix restores the intended behaviour without adding any routing
exception.

---

## 1. Root cause

The three routes 404'd because the **persisted `rewrite_rules` option was a rule
set flushed before Polylang attaches its rewrite filters**, and therefore
contained no `(en)/<archive>` rules at all.

Polylang builds the `/en/<archive>/` rules by filtering the *per-post-type*
`{$post_type}_rewrite_rules` sets. Those filters are attached on `wp_loaded` at
priority 9 (`PLL_Links_Permalinks::do_prepare_rewrite_rules()`). Any
`flush_rewrite_rules()` that runs **before** that point persists a rule set in
which every English archive resolves to nothing, so WordPress answers 404 — while
the Portuguese archives keep working, because their rules do not depend on
Polylang's filters.

Measured, not assumed:

| Persisted rule set | Rules | `(en)/` post_type rules |
|---|---|---|
| Stale (the reported state) | 336 | **0** |
| Healthy | 478 | **20** |

The stale set is not merely "missing the EN rules" — 20 Portuguese rules were
also *different* (`index.php?post_type=guide` instead of
`index.php?lang=pt&post_type=guide`), the `hide_default` marker Polylang adds.
That proves the whole rule set had been generated **without Polylang's filters
attached**, which identifies the cause rather than the symptom.

**Decisive negative test.** A flush issued with Polylang's rewrite filters
removed reproduced the broken state *exactly*:

```
NO-POLYLANG-FLUSH: total=336 en_post_type=0
>>> MATCHES STALE 336-RULE SET
```

**How a flush precedes `wp_loaded@9` in this repository.** Three plugins call
`flush_rewrite_rules()` from their activation hooks
(`conexao-data-model.php:468/472`, `conexao-event-runtime.php:454/465`,
`conexao-content.php:63`). Activation hooks run inside the activation request,
before `wp_loaded`. The 597b04e → cd800be recovery cycle involved theme and
plugin activation (`wp_options.theme_switched` is set), which is exactly the
window in which such a flush persists the incomplete rule set. The theme is a
bind mount, so a theme file change is not itself the cause.

### Where the request becomes invalid

```
request /en/guias/
→ Apache front controller (.htaccess RewriteRule . /index.php)
→ WordPress rewrite match: NO rule matches  <-- request becomes invalid here
→ WP_Query: empty → is_404() → 404 response
```

The failure is at rewrite matching. Polylang's language context, the CPT
registration (`has_archive => 'guias'`, `public => true`), the template
resolution and the response layer were all correct and were never reached.

## 2. Historical evidence

* `docs/evidence/2026-09-27-recovery-closeout/06-http-acceptance-matrix.txt`
  records, at the recovered baseline: `/en/guias/ exp=200 got=200` and
  `/en/eventos/ exp=200 got=200`, both with `canonical: …/en/guias/` and
  `…/en/eventos/`. **The routes demonstrably worked.**
* `git diff b47d098 HEAD -- wp-content/themes/` is **empty** and the same diff
  over `wp-content/plugins/` is empty except the event-importer `.pot`, which
  `cd800be` deliberately preserved. The `597b04e` regression therefore
  **cannot** be the cause: the code in place when the 404s appeared is the
  verified-good code.
* Therefore the regression is **local runtime/DB state**, not source. This is
  the answer to "did `597b04e` break these routes": **no** — the recovery
  restored the code, and the routes failed afterwards because of a stale
  persisted rule set.

## 3. Route model

| Route | Intended route type | PT route | EN mechanism | EN content required? | Before |
|---|---|---|---|---|---|
| `/en/apoiadores/` | CPT archive (`sponsor`), B2 | `/apoiadores/` 200 | Polylang `(en)/` archive rule → `post_type=sponsor` | No (B2) | 404 |
| `/en/eventos/` | CPT archive (`event`), B2 | `/eventos/` 200 | Polylang `(en)/` archive rule → `post_type=event` | No (B2) | 404 |
| `/en/guias/` | CPT archive (`guide`), B1 real EN archive | `/guias/` 200 | Polylang `(en)/` archive rule → `post_type=guide` | Yes, but the **route** needs no EN record | 404 |

None of the three needed fabricated content to route. `/en/guias/` is a real EN
archive by design (Stage 9), but its *route* resolves on the CPT archive
regardless of how many EN records exist — which is why the archive itself is 200
while `page/2/` is 404 (§8).

## 4. Data / Polylang

* PT canonical objects exist and are untouched: 48 PT guides, plus the event and
  sponsor archives.
* EN guides: **0** local records. `/en/guias/` therefore renders an empty-but-valid
  EN archive. **No EN guide was fabricated.**
* EN events: 3. Events remain **B2**; the EN archive renders the PT event records
  under the EN shell exactly as the model prescribes.
* Sponsors remain **B2**, as documented.
* Polylang relationships, `conexao_category`/`conexao_tag` translation and the
  shared `conexao_county`/`conexao_town` policy are **unchanged** — the fix
  touches no query, no taxonomy and no content.
* No PT record was created, edited, translated or re-slugged.

## 5. Rewrite rules

| | Rules | `(en)/` post_type |
|---|---|---|
| Before | 336 | 0 |
| After | 478 | 20 |

* **A local flush alone restored all three routes with no code change** — that
  is what proved the fault was state, not source.
* The flush performed during this work was a **local DB/runtime operation**,
  recorded as such. It is recorded here as a *diagnostic*, not as the fix.
* **Would production receive equivalent rules?** The registration code that
  produces them is in the theme and is shipped (`conexao-data-model` +
  Polylang). Production does not need a manual flush *provided* the rules are
  generated after `wp_loaded@9`. The risk identified above is precisely that an
  early flush (activation hook) can persist an incomplete set anywhere,
  production included. The fix removes that dependency on timing.
* **The final fix does NOT require a rewrite flush**: the guard repairs the
  state on the next ordinary request.

## 6. Files changed

| File | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/i18n/guard.php` | **+129 lines, append only** | Adds `conexao_polylang_language_archive_post_types()` (derived from the existing translation policy — no new list) and `conexao_polylang_rewrite_rules_ensure()` on `wp_loaded` priority 20. |
| `wp-content/themes/conexao-br-irlanda/tests/test-en-archive-rewrite-guard.php` | **new, 17 assertions** | Locks the contract; discovered automatically by the existing runner. |
| `docs/evidence/2026-09-28-en-archive-404-fix/*` | **new** | Machine evidence. |
| `docs/reports/2026-09-28-en-archive-404-fix.md` | **new** | This report. |
| `docs/evidence/2026-09-26-stage-l/gate.json` | regenerated | Only `generated_at`, `repository_sha` and two run-metadata counts. No gate weakened. |
| `scripts/lint.sh` | **pre-existing, untouched** | Already modified in the working tree when this task began; unrelated to routing. Not part of this fix. |

### What the fix is — and is not

`conexao_polylang_rewrite_rules_ensure()` follows the self-heal precedent already
in the same file (`conexao_polylang_language_home_ensure()`, which repairs
Polylang's cached language list the same way). It inspects the persisted rule
set and, only when an English archive rule is actually missing, calls
`flush_rewrite_rules(false)` at `wp_loaded` priority 20 — strictly after
Polylang's priority-9 prepare step.

It is deliberately **not**: a route allowlist, a `(en)/…` URL handler, a
redirect, a 404 suppressor, a second routing system, or anything that bypasses
Polylang. It never inspects the request; it repairs persisted state and
delegates the generation entirely to Polylang. Steady state is one `get_option()`
and a scan — proven no-churn in §7.

## 7. Rewrite-rule negative test (fail → sabotage → restore → pass)

Full cycle, all four steps, evidence `03`, `04`, `06`:

1. **Fail** — injected the stale 336-rule set: `rules=336 en_post_type=0`.
2. **Sabotage** — neutralised the guard (temporary mu-plugin) and re-injected
   the stale set. The three routes returned **404**, and the new suite exited
   **1**, naming every missing archive:
   ```
   FAIL: every bilingual archive has its live /en/ rewrite rule persisted
         — missing: guias, eventos, lazer, apoiadores, cursos
   test-en-archive-rewrite-guard: 15 passed, 2 failed
   ```
3. **Restore** — sabotage removed; a single ordinary request self-healed
   `336 → 478`; all three routes 200.
4. **Clean state** — re-verified: EN 200, PT 200, suite 17/17.

Steady-state stability (no rewrite churn on ordinary traffic):

```
rules md5 before traffic: 768ed977ad98d3ca1a3845f95d8c8d62
rules md5 after  traffic: 768ed977ad98d3ca1a3845f95d8c8d62
STABLE: no rewrite churn in the steady state
```

**All temporary mu-plugin probes were removed**; `git status` in §13 confirms no
sabotage remains.

## 8. Tests

`./scripts/run-tests.sh`, before vs after:

| Metric | Baseline | After | Δ |
|---|---|---|---|
| In-process suites | 59 (39p / 20f) | **60 (40p / 20f)** | +1 total, **+1 passing** |
| Assertions passed | 2900 | **2917** | **+17** (the new suite) |
| Assertions failed | 102 | **102** | **0** |
| Prerequisite failures | 2 | 2 | 0 |
| Script-contract | 6/6 | **6/6** | 0 |
| HTTP acceptance | 1p / 2f | 1p / 2f | 0 suites changed |

**Sorted failing-suite diff against the baseline: IDENTICAL — 0 new failures.**

New suite: `theme/conexao-br-irlanda/test-en-archive-rewrite-guard.php —
17 passed, 0 failed`.

Pre-existing failures **classified, not suppressed** (evidence `11`):
`en-home-no-pt-content-type-label` (proved pre-existing by reverting the fix and
re-measuring: `/en/` still renders the chip as `Guides`, 0 × `Practical Guide`);
`en-guides-page-2` + `an EN guide link is discoverable` (0 EN guide records
locally — a **content** limitation, see §12).

`./scripts/lint.sh`: **FAILS, identically with and without this change**
(verified by stashing the change and re-running: byte-identical PHPCS baseline
output). Pre-existing repository debt, not introduced here. The two changed files
pass PHPCS individually (`phpcs` on both: clean), and `php -l` is clean.
`php scripts/generate-registry-docs.php --check`: **OK, 14 plugins, 23 regions
current, zero writes.**

## 9. HTTP results

| Route | Before | After | `html lang` | Canonical | hreflang | PHP errors |
|---|---|---|---|---|---|---|
| `/en/apoiadores/` | 404 | **200** | `en-US` | self `/en/apoiadores/` | ✓ | 0 |
| `/en/eventos/` | 404 | **200** | `en-US` | self `/en/eventos/` | ✓ | 0 |
| `/en/guias/` | 404 | **200** | `en-US` | self `/en/guias/` | ✓ | 0 |
| `/guias/` | 200 | 200 | `pt-BR` | self `/guias/` | ✓ | 0 |
| `/eventos/` | 200 | 200 | `pt-BR` | self `/eventos/` | ✓ | 0 |
| `/apoiadores/` | 200 | 200 | `pt-BR` | self `/apoiadores/` | ✓ | 0 |

No route redirects to PT. `verify-routing-http.py`: **86 passed, 3 failed**
(the baseline run of this suite **timed out** and produced no result; the three
failures are the pre-existing ones in §8).

**Bonus repair, same root cause:** `/en/cursos/` was **301 → a single PT post**
and now correctly returns **200**, matching its `en-200-en-cursos` contract row.

Regression scope — all healthy: `/` 200, `/en/` 200, `/en/cursos/` 200,
`/en/lazer/` 200, `/en/blog/` 200, `/en/dublin/` 200, `/en/housing/` 200,
`/en/eventos/?county=laois` 200, `/en/eventos/?cidade=adare` 200,
`/en/guias/?categoria=documents` 200, `/en/empregos/` **302 → PT** (documented
B1, unchanged).

Event architecture preserved: 26 county and 215 town filter links render on
`/en/eventos/`, and `?county=carlow` navigates and stays in the EN shell.

## 10. Browser (Chromium/Playwright, desktop 1440×900 + mobile 390×844)

All **6 navigations** (3 routes × 2 viewports): **HTTP 200**, `html lang="en-US"`,
**self-canonical**, `no_pt_redirect: true`, `page_errors: []`,
`h_overflow: false`. Titles: "Supporters in Ireland", "Events in Ireland",
"Practical Guides". Full output in evidence `09`/`10`.

A dedicated subresource probe on `/en/eventos/` reported **zero** failing
subresources; the transient 404 console lines seen during navigation are

## 11. Gates

All seven, unchanged and fail-closed:

| Gate | Result | Violations | Pre-existing | New |
|---|---|---|---|---|
| taxonomy_policy | PASS | 0 | 0 | 0 |
| translation_completeness | **FAIL** | 53 | 52 | 1 |
| cache_scoping | PASS | 0 | 0 | 0 |
| cache_scoping_static | PASS | 0 | 0 | 0 |
| redirect_precedence | PASS | 0 | 0 | 0 |
| documentation_drift | PASS | 0 | 0 | 0 |
| i18n_freshness | PASS | 0 | 0 | 0 |

**7 total, 6 passed, 1 failed. Assertions 83 passed / 3 failed. AGGREGATE: FAIL** —
**byte-identical to the recorded baseline**. No threshold changed, no finding
suppressed, no allowlist added, and `tests/baseline/permanent-gates.json` is
untouched.

The single "new" violation is the **pre-existing event `11553`**
(`mabon-full-moon-day-retreat`, an EN event with no PT master) already documented
in the recovery closeout as a reporting-classification artefact. It was **not**
fixed, **not** allowlisted, and remains a failing gate, exactly as required. The
route investigation proved **no causal relationship** to this task: the gate
reads Polylang DB state only, and the fix changes no data.

## 12. PT immutability

**No PT content changed.** No post, page, translation, term, meta or attachment
was created, edited or deleted. PT canonical URLs, slugs and record identities
are untouched. `/guias/`, `/eventos/`, `/apoiadores/` all still 200 with
`lang="pt-BR"`, self-canonical PT URLs, and unchanged bodies. The only writes to
the local database in this task were to the `rewrite_rules` option (the
diagnosis and the self-heal), never to content.

Two pre-existing content limitations remain **unfixed and documented**, not
masked:

## 13. Production, Flutter, rollback, cleanliness

* **No production writes or deployment were performed.** No publish, no DB
  mutation, no SSH/WP-CLI, no deploy. Only the local Docker stack was used.
* **Flutter/mobile repository was not accessed.**
* Rollback: `recovery-snapshot-before-restore` (`bd81ba5`), preserved and not
  moved. No reset, revert, cherry-pick, merge, history rewrite or force-push.
  `597b04e` remains in the log and fully auditable.
* `git diff --check`, `git status --short` and `git diff --stat` are clean apart
  from the intended files. No temporary files, no test sabotage, no generated
  junk, no database dumps, no unrelated routing changes. `scripts/lint.sh` was
  already modified before this task and was deliberately left untouched.

## 14. Final status

### **PASS WITH LIMITATION**

All three required routes are fixed — `/en/apoiadores/`, `/en/eventos/` and
`/en/guias/` serve **200** with the EN shell, self-canonical URL, correct
hreflang, no PT redirect and no PHP errors, and the fix provably does not
regress PT, Polylang, B1/B2, redirects or any nearby route.

**The limitation is not a broken route:** the local dataset has **0 EN guide
records**, so `/en/guias/page/2/` legitimately has no page 2, and the
`translation_completeness` gate still fails on the **pre-existing** event
`11553` finding. Both were left visible, documented and unweakened; closing them
requires a separate content plan and a separate decision, not a routing change.

## 15. Evidence paths

`docs/evidence/2026-09-28-en-archive-404-fix/`

```
00-frozen-starting-state.txt              reproduction at the start (404 x3, PT 200 x3)
01-rewrite-rules-stale-vs-flushed.txt     336/0 vs 478/20, full rule diff
02-root-cause-mechanism.txt                Polylang lifecycle + activation-hook trigger
03-rewrite-negate-positive-proof.txt      fail -> self-heal -> stability
04-anti-vacuity-guard-disabled.txt        guard neutralised -> 404 + suite exit 1
05-full-runner.txt                         runner totals before/after
06-sabotage-removed-clean-state.txt        restored, clean, re-verified
07-permanent-gates.txt                    all seven gates
08-http-pt-immutability.txt               per-route status/canonical/hreflang/hashes
09-browser-desktop-mobile.txt              6 navigations, desktop + mobile
10-browser-interactions.txt                event filters, guides archive vs pagination
11-remaining-failures-classification.txt  the 3 pre-existing HTTP failures
```


* **0 EN guide records** locally (48 PT). `/en/guias/` is a correct, empty EN
  archive; `page/2/` and "an EN guide link is discoverable" stay red because
  there is no EN content to paginate. Producing it requires a separate content
  plan — **no EN guide was fabricated** to green a route test.
* `/en/empregos/` remains **302 → PT** (documented B1, no EN translation).

preload/favicon requests, not route failures.

