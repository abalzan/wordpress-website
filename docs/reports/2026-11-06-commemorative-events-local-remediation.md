# Report — Commemorative Events r10: local remediation after the final review

> **Local remediation only.** No production access, no deployment, release, tag,
> push, plugin/theme activation or content migration was performed, and no
> WordPress record was created, modified or deleted. The final-review report is
> **unchanged**.

| | |
|---|---|
| **Task** | Remediate the local findings of [`2026-11-06-commemorative-events-r10-final-review.md`](2026-11-06-commemorative-events-r10-final-review.md) |
| **Date** | 2026-11-06 |
| **Branch** | `commemorative-events` (not switched, not created, not deleted) |
| **HEAD at start / end** | `657c3340f85f7b9f783edc85c43805ee8a35347c` / **identical** |
| **Upstream** | none — nothing pushed |
| **Stashes** | **9** at start and end (`stash@{0}`…`stash@{8}`), untouched |
| **Review verdict being remediated** | NEEDS WORK |
| **Remediation status** | **COMPLETE (local)** — H2/H1/M1/M2/M3/M4/L4/L5/L8/L10 addressed; M5 **NOT VERIFIED**; release readiness remains blocked on the separately authorised production and release-manifest work |

## 1. Preservation

| | At start | At end |
|---|---|---|
| Branch | `commemorative-events` | `commemorative-events` |
| `HEAD` | `657c3340f85f7b9f783edc85c43805ee8a35347c` | **identical** |
| `git status --short` | exactly `?? docs/reports/2026-11-06-commemorative-events-r10-final-review.md` | 19 modified files + 6 untracked paths (below) |
| Stash entries | **9** | **9**, contents untouched |
| Upstream | none | none |
| Final-review report | untracked, unmodified | **untracked, unmodified** |

End-state `git status --short` (full text in
[`evidence/…/git-state-end.txt`](../evidence/2026-11-06-commemorative-local-remediation/git-state-end.txt)):

```text
 M docs/README.md
 M docs/commemorative-events.md
 M docs/content-model.md
 M docs/plugins/conexao-commemorative.md
 M docs/releases.md
 M docs/reports/README.md
 M docs/testing.md
 M scripts/verify-permanent-gates.py
 M tests/acceptance/matrices/commemorative.json
 M tests/acceptance/verify-commemorative-http.py
 M wp-content/plugins/conexao-admin-ux/includes/class-editor.php
 M wp-content/plugins/conexao-admin-ux/includes/class-fields.php
 M wp-content/plugins/conexao-admin-ux/includes/class-list.php
 M wp-content/plugins/conexao-commemorative/includes/class-conexao-commemorative-admin.php
 M wp-content/plugins/conexao-commemorative/includes/class-conexao-commemorative-repository.php
 M wp-content/plugins/conexao-commemorative/includes/class-conexao-commemorative.php
 M wp-content/plugins/conexao-data-model/includes/class-conexao-data-model-commemorative.php
 M wp-content/themes/conexao-br-irlanda/assets/js/commemorative.js
 M wp-content/themes/conexao-br-irlanda/inc/occasion.php
?? docs/evidence/2026-11-06-commemorative-local-remediation/
?? docs/evidence/2026-11-06-commemorative-precedence/
?? docs/reports/2026-11-06-commemorative-events-local-remediation.md      (this report)
?? docs/reports/2026-11-06-commemorative-events-r10-final-review.md       (pre-existing, untouched)
?? wp-content/themes/conexao-br-irlanda/inc/class-conexao-seasonal-occasion.php
?? wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-translation-ownership.php
```

**No** `reset`, `clean`, `checkout --`, `restore`, stash apply/pop/drop/clear,
rebase, cherry-pick, amend or commit was executed. No file was overwritten: every
modified path is a deliberate edit and every new path did not exist before.

## 2. Findings: addressed / documented / deferred

| # | Finding (review) | Status | What changed |
|---|---|---|---|
| **H2** | The Polylang single-owner rule was convention + detection, not mechanism | **ADDRESSED (complete)** | Enforced at the copy, editor and save layers, and promoted to a registered permanent gate. §3. |
| **H1** | Sitemap exclusion "proven" by a Jetpack index; production unverified | **ADDRESSED (documentation + evidence)**; production **NOT VERIFIED** | Matrix note, `docs/testing.md` §"Sitemap evidence semantics", `docs/commemorative-events.md` §6, and an environment-aware classification in the HTTP suite. §4. |
| **M1** | ~16 PHPCS instances attributable to this work; lint red | **ADDRESSED** | All 16 fixed in code; the baseline was **not** touched. §5. |
| **M2** | Landing-page asset exception undocumented | **ADDRESSED** | `docs/commemorative-events.md` §2 + the gate row in `docs/testing.md`; the ordinary-route zero-asset gate is unchanged. |
| **M3** | Reports' "no commit / dirty tree" record stale; commit message under-describes the change | **ADDRESSED (documentation only)** | Report → commit mapping in `docs/releases.md`; index wording corrected. History not rewritten. |
| **M4** | `show_in_rest => false` deviation unrecorded | **ADDRESSED** | Deviation, rationale and the "§9.8 not applicable" consequence recorded in `docs/content-model.md` + `docs/plugins/conexao-commemorative.md`; the route stays absent. |
| **M5** | Independent-admin + overlapping-preview verification | **NOT VERIFIED** | No independent administrator was available. A read-only in-memory precedence demonstration is recorded as *supplementary*, explicitly not the required evidence. §7. |
| **L4** | Broken architecture-audit links | **ADDRESSED** | Both indexes point at the real r10 proposal file; a third broken relative link in `docs/README.md` fixed too. |
| **L5** | Stale index wording ("r10 Stages 0–3", "working tree, no commit") | **ADDRESSED** | Corrected; the final review is now described as a **review report**, not a commit or an approval. |
| **L6** | Theme version does not move | **DOCUMENTED, NOT APPLIED** | A version bump is a release decision here (artifact identity + `dist/release.json`); the proposal `1.0.2 → 1.0.3` is recorded in `docs/releases.md`. |
| **L8** | Part of the purge is a no-op; the host action has no listener | **ADDRESSED (documentation)** | `docs/plugins/conexao-commemorative.md` states what is actually effective and that the host listener is not in this repository. |
| **L10** | JS comment overstates the scheduler rule | **ADDRESSED** | `assets/js/commemorative.js` names the approved Halloween navigation scheduler as the single exception. |
| **L1, L3, L7, L9** | Optional | **DEFERRED** | Not changed — see §9. |

## 3. H2 — the single-owner invariant, enforced in three layers

**The Polylang contract actually used.** The installed Polylang is **3.8.9**, and
its sync layer applies

```php
apply_filters( 'pll_copy_post_metas', array $keys, bool $sync, int $from, int $to, string $lang )
```

(`wp-content/plugins/polylang/src/modules/sync/sync-post-metas.php:78`, called from
`PLL_Sync_Post_Metas::copy()` / `sync()` / `save_object()`). The payload is the
list of meta keys Polylang is **about to copy** — an *inclusion* list — so
removing a key from it is what excludes that key. (The same filter is also applied
by `wpml-legacy-api.php` and by integrations; the callback is a no-op for every
post type that is not a `commemorative_event`.) The callback signature was read
from the installed source, not inferred from the hook name.

**One source of truth for the split.** `Conexao_Data_Model_Commemorative`
(the declarative owner of the record shape) now exposes the split and the
ownership question, and every layer asks it — there is no second list anywhere:

| Symbol | Purpose |
|---|---|
| `shared_meta_keys()` / `translated_meta_keys()` / `identity_meta_keys()` | the three key sets, read from the existing constants |
| `is_shared_meta_key( $key )` | membership test |
| `strip_shared_meta_keys( array $keys )` | removes exactly the shared keys from a copy list |
| `canonical_language()` / `language_of( $post_id )` | Polylang's configured default language and a record's language |
| `is_canonical_language( $post_id )` / `can_write_shared_meta( $post_id )` | the ownership predicate |

`Conexao_Commemorative_Repository::is_canonical_language()` and `::language_of()`
now **delegate** to those methods (same semantics, same public signatures), so the
engine, the editor and the copy filter can never disagree about which record owns
the configuration. `_ce_year_precedence` ownership and the resolver's precedence
algorithm are untouched.

| Layer | Implementation | Where |
|---|---|---|
| **1. Copy** | `add_filter( 'pll_copy_post_metas', [ $this, 'filter_copied_metas' ], 10, 5 )` in `Conexao_Commemorative::boot_components()`; the callback removes `SHARED_META_KEYS` when `is_commemorative_copy( $from, $to )` (either side is a `commemorative_event`) and returns every other list unchanged | `class-conexao-commemorative.php` |
| **2. Editor** | `Conexao_Admin_Ux_Editor::lock_shared_fields( $fields, $post_id )` sets `readonly => true` on the shared fields of a non-canonical record; `Conexao_Admin_Ux_Fields::render()` renders a `readonly` field as **no input at all** (value + help text only), plus an informational notice at the top of the editor. Canonical records and every other post type are returned unchanged | `class-editor.php`, `class-fields.php` |
| **3. Save** | `Conexao_Admin_Ux_Fields::writable_field_keys( $post_id, $config )` is the single decision, and `save()` persists **only** the keys it returns (`continue` otherwise), so a crafted POST is dropped. `Conexao_Commemorative_Admin::save_precedence()` returns before writing anything when the record is not canonical, and the engine's own meta box renders the annual-preference control only on the canonical record | `class-fields.php`, `class-conexao-commemorative-admin.php` |

**UI-only hiding was explicitly rejected**: the writable list is re-derived
server-side on save, and the enforcement lives in the same function the save loop
uses, so the tested behaviour *is* the enforced behaviour.

**Measured behaviour** (read-only probes; evidence
[`h2-layer-probe.txt`](../evidence/2026-11-06-commemorative-local-remediation/h2-layer-probe.txt),
[`editor-render-probe.txt`](../evidence/2026-11-06-commemorative-local-remediation/editor-render-probe.txt)):

```text
filter registered: yes
pt canonical: true          en canonical: false
pt can write shared: true   en can write shared: false
shared keys surviving a commemorative copy list: (none)
kept: _ce_heading,_ce_subheading,_ce_cta_label,_ce_cta_url,_ce_event_key,_wp_page_template,_thumbnail_id
non-commemorative page copy list unchanged: true
writable keys on PT: 17
writable keys on EN: _ce_name,_ce_event_key,_ce_heading,_ce_subheading,_ce_cta_label,_ce_cta_url

PT (canonical)  : inputs=17  readonly_markers=0   notice=no
EN (translation): inputs=6   readonly_markers=11  notice=yes
```




### 3.1 The permanent gate and its negative proof

| | |
|---|---|
| **Gate id** | `commemorative_translation_ownership` |
| **Suite** | `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-translation-ownership.php` |
| **Registered in** | `scripts/verify-permanent-gates.py` (domain `commemorative_polylang`) **and** discovered automatically by `./scripts/run-tests.sh` (a `tests/test-*.php` file is the discovery convention) |
| **Documented in** | `docs/testing.md` §"The commemorative gates" (now eight) and `docs/commemorative-events.md` §6 |
| **Assertions** | **30 passed, 0 failed** (exit 0) — [`gate-translation-ownership.txt`](../evidence/2026-11-06-commemorative-local-remediation/gate-translation-ownership.txt) |
| **Mutates state** | **No.** Read-only SQL (`SELECT meta_key`), `get_posts`, `apply_filters`, `has_filter`, the pure helpers and two `file_get_contents` source inspections |
| **Why it cannot pass vacuously** | it `require`s a canonical record **and** its linked translation (a missing prerequisite is a FAILURE), it exercises the copy filter / editor / save guard directly, and it asserts that the canonical record actually carries the shared rows |

**Injected negative proofs inside the gate:** the copy check is also run over a
list that *still* contains a shared key and must flag it
(`gate:copy_leak_not_detected`), the same renderer must still emit an input for an
*unlocked* field (`gate:locked_render_check_is_vacuous`), and the canonical record
must stay editable (`editor:canonical_field_locked`).

**Injected negative proof at the gate level.** The gate was re-run in a
deliberately regressed environment — a later `pll_copy_post_metas` listener that
appends `_ce_enabled`, i.e. exactly the drift class H2 describes:

```text
  FAIL: a commemorative copy list carries no shared scheduling/presentation key — 1 violation(s): {"leaks":["_ce_enabled"]}
  FAIL: a non-commemorative post type keeps its copy list unchanged
28 passed, 2 failed        exit 1
```

Evidence:
[`gate-translation-ownership-negative-proof.txt`](../evidence/2026-11-06-commemorative-local-remediation/gate-translation-ownership-negative-proof.txt).
The regression was injected from a throw-away wrapper script; **no repository

## 4. H1 — corrected sitemap evidence semantics (production stays unverified)

Only **documentation and test semantics** changed. No Jetpack filter, robots rule,
sitemap-plugin configuration, theme producer change or production integration was
added.

| Change | Where |
|---|---|
| The row's `note` now says the check is **VACUOUS IN THIS ENVIRONMENT** (the local/CI stack is answered by Jetpack's sitemap **index**, which enumerates no page, so the theme producer never runs), that the exclusion decision is proven in-process by `commemorative_cpt_privacy`, and that the production serving path is **NOT VERIFIED** | `tests/acceptance/matrices/commemorative.json` |
| The suite now **classifies the serving producer** instead of trusting the row: an index response requires the matrix note to say "vacuous", while a real `urlset` response is checked for the absent URLs (an effective proof *in that environment*) | `tests/acceptance/verify-commemorative-http.py` (`report_sitemap_evidence`) |
| A new "Sitemap evidence semantics" section separates the **theme producer** (a real in-repo proof) from the **serving producer** (unverified), and the gate table row is scoped accordingly | `docs/testing.md` |
| `docs/commemorative-events.md` §6 states what the gate does and does not prove | `docs/commemorative-events.md` |

**What remains true after this correction**

- `commemorative_cpt_privacy` still renders the real theme producer and still
  fails if the producer includes `/natal/`, `/en/christmas/` or the CPT — that
  gate is unchanged and green (**11/0**).
- The HTTP row can no longer be read as end-to-end exclusion proof; it is now
  self-documenting and environment-aware.
- **The production sitemap-serving path and the landing pages' inclusion status
  remain NOT VERIFIED.** That requires a separately authorised production check
  (`GET /sitemap.xml` and the page sitemap on the live site) and, only if it
  fails, a separately planned exclusion for whichever producer serves it.

## 5. M1 — PHPCS: fixed in code, baseline untouched

The per-sniff gate was red on exactly the six categories the review named.
**16 instances were fixed**; `phpcs-baseline.json` was **not** regenerated, and no
entry was added, removed or reclassified.

| Sniff | Before | After | Baseline | Fix |
|---|---|---|---|---|
| `Universal.CodeAnalysis.NoDoubleNegative.FoundDouble` | 1 | **0** | 0 | `! ! ( … )` → `(bool) ( … )` in the occasion constructor |
| `Generic.WhiteSpace.ScopeIndent.IncorrectExact` | 21 | **19** | 19 | two zero-indented lines re-indented (`class-editor.php`, `class-fields.php`) |
| `Squiz.Commenting.VariableComment.Missing` | 15 | **5** | 5 | `@var` docblocks for the 10 occasion properties |
| `Universal.Files.SeparateFunctionsFromOO.Mixed` | 6 | **5** | 5 | the occasion class (and its enum) moved into `inc/class-conexao-seasonal-occasion.php`; `inc/occasion.php` keeps the helper functions and `require_once`s the class file |
| `WordPress.Files.FileName.InvalidClassFileName` | 75 | **74** | 74 | same split: the class now lives in a file named after it |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 102 | **99** | 101 | translator comments added (bulk-action notice, back-to-list link, occasion heading) |

```text
PHPCS legacy baseline: 2837 errors / 2221 warnings in 239 files (baseline: 3290 / 2672).
Debt paid down in 36 sniff(s) - regenerate the baseline deliberately when convenient.
OK: no new PHPCS violations against the baseline.        exit 0
```

(A further two new instances that the fixes themselves briefly introduced — one
unused-parameter warning and one function-call-signature pair — were removed
before the final run, so **no sniff regressed relative to the pre-remediation
state**.) Evidence:
[`phpcs-per-sniff.txt`](../evidence/2026-11-06-commemorative-local-remediation/phpcs-per-sniff.txt),
[`phpcs-baseline-check.txt`](../evidence/2026-11-06-commemorative-local-remediation/phpcs-baseline-check.txt).
PHPStan is clean (`EXIT=0`) —
[`phpstan.txt`](../evidence/2026-11-06-commemorative-local-remediation/phpstan.txt).

**Attribution note (honest).** A base-tree comparison shows that most of these 16
predate the two commemorative commits (they appear in the Halloween-era
`inc/occasion.php` and in the pre-existing admin/editor indentation), while the
`class-list.php` translator comment **is** genuinely new in `cfea1fa`. They are all
fixed anyway: they sit in files this work touches, they are behaviour-preserving
edits, and fixing them is what returns the gate to green without moving the
baseline.


## 6. M2 / M4 / M3 / L4 / L5 / L6 / L8 / L10 — documentation accuracy

| Finding | Change |
|---|---|
| **M2** landing-page asset exception | `docs/commemorative-events.md` §2 now states that ordinary routes emit **zero** commemorative bytes with no active event, that an explicitly opted-in landing page **deliberately** keeps its package assets outside the window as an archival/seasonal page, that landing resolution is independent of the active-event resolver, and that this is a *constrained* exception (both opt-ins + slug match + only its own package's assets). The gate row in `docs/testing.md` carries the same scope, and `docs/commemorative-events.md` §6 repeats it. **The ordinary-route zero-asset gate and its injected negative proof were not changed.** |
| **M4** REST deviation | `docs/content-model.md` and `docs/plugins/conexao-commemorative.md` record that `commemorative_event` is `show_in_rest => false`, that the route is therefore **absent (404)** rather than permission-gated, the security/privacy rationale, and that r10 §9.8's authenticated REST-read row is **not applicable**. The route stays absent. The stale data-model docblock that claimed "the CPT itself is REST-enabled" was corrected. |
| **M3** report → commit mapping | `docs/releases.md` gains a "Commemorative events r10 — report → commit provenance (NOT a release)" table: plan → —, Stages 0–3 → `cfea1fa`, Stages 4–6 → `657c334` (with an explicit note that the commit message under-describes it and that history was not rewritten), final review → a **review of `657c334`, not an implementation commit and not an approval**, remediation → working tree. |
| **L4** broken links | `docs/README.md` and `docs/reports/README.md` now link `2026-11-03-commemorative-events-architecture-proposal-r10-revised.md` (the file that exists). A third broken relative link in `docs/README.md` (the Stage 0–3 implementation entry missing its `reports/` prefix) was fixed at the same time. |
| **L5** stale index wording | "(r10 Stages 0–3)" and both "(working tree, no commit)" labels were replaced with the committed facts and the commit SHAs. |
| **L6** theme version | **Documented, not applied**: see the "Proposed, NOT applied" note in `docs/releases.md` (`conexao-br-irlanda` 1.0.2 → **1.0.3**, in `style.css` + `CONEXAO_THEME_VERSION`, together with the release record). |
| **L8** purge no-op | `docs/plugins/conexao-commemorative.md` states that the two transients the purge deletes are not written by this version (that part is a no-op) and that `conexao_commemorative_purge_caches` has **no listener in this repository** — the host/CDN integration lives outside it. Nothing was invented or simulated. |
| **L10** scheduler comment | `assets/js/commemorative.js` now says no package may ship a second **ambient** scheduler and names the single approved exception (the Halloween mobile navigation scheduler). Comment only; no behaviour change. |

## 7. M5 — NOT VERIFIED

The review's Stage 6 items (a) an administrator **not on the implementation team**
verifying the annual-precedence control through the normal admin workflow and
(b) an overlapping pair of eligible events demonstrated in local preview have **no
admissible evidence**. I am the implementer, I have no second administrator to
hand, and impersonating one would fabricate the evidence. **M5 therefore remains
NOT VERIFIED.**

What *was* done, and what it is worth:

- A **read-only, in-memory** demonstration of the precedence rules was run against
  the engine's pure resolver (`Resolver::evaluate()` + `Resolver::rank()` with an
  injected clock of `2026-12-15`), using two synthetic event arrays. It created and
  changed **no** WordPress record and needed no cleanup. Evidence + script:
  [`evidence/2026-11-06-commemorative-precedence/`](../evidence/2026-11-06-commemorative-precedence/).

```text
1. no preference, equal priority (deterministic tie-break)      PASS expected=alpha actual=alpha
2. beta preferred for 2026                                      PASS expected=beta  actual=beta
3. beta preferred for 2027 only (adjacent year unaffected)      PASS expected=alpha actual=alpha
4. beta preferred for 2026 but DISABLED                          PASS expected=alpha actual=alpha
5. beta preferred for 2026 but DRAFT                             PASS expected=alpha actual=alpha
6. beta preferred for 2026 but OUT OF WINDOW (July window)       PASS expected=alpha actual=alpha
result: PASS
```

- This proves the **algorithm** (preference is a per-occurrence-year hint; it
  cannot enable a draft/disabled/out-of-window event; adjacent years are
  unaffected). It does **not** replace (a) an independent human driving the control
  in wp-admin, or (b) an overlapping **fixture pair in local preview**.

**Exact remaining action for M5:** an administrator who did not write this code,
on the local stack, opens wp-admin → *Eventos Comemorativos*, creates (or uses) two
overlapping eligible events for the same occurrence year, ticks the manual
preference on the intended one, saves, uses the signed preview link on the
homepage, and records: date/time, role (not personal details), both record
identifiers, the occurrence year, the selected event, the expected result and the
cleanup. Then delete the disposable fixtures and confirm both records are gone.


## 8. Tests and gates (exact commands, results, mutation characteristics)

Every row was **executed** in this remediation. "Mutates" answers "does it write a
WordPress record, option or file?" — the answer is `no` everywhere on this page.

### Layer 1 — the new gate and the H2-specific proofs

| Command | Exit | Result | Mutates | Compared with baseline |
|---|---|---|---|---|
| `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-translation-ownership.php` | 0 | **30 passed, 0 failed** | no | **new** (gate created here) |
| same gate, wrapped by a script that re-adds `_ce_enabled` on `pll_copy_post_metas` | 1 | 28 passed, **2 failed** | no | **new** (injected negative proof, must fail) |
| `docker compose exec -T wordpress php /tmp/ce-h2-probe.php` (read-only layer probe) | 0 | all three layers behave as designed | no | new |
| `docker compose exec -T wordpress php /tmp/ce-editor-probe.php` (read-only editor render) | 0 | PT 17 inputs / 0 locked · EN 6 inputs / 11 locked / notice | no | new |

### Layer 2 — commemorative theme suites (in-process PHP)

All executed with
`docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/<suite>.php`.

| Suite | Result | Exit | Mutates | Baseline |
|---|---|---|---|---|
| `test-commemorative-presentation-dispatch` | 99 passed, 0 failed | 0 | no | identical to the review |
| `test-commemorative-packages` | 131 passed, 0 failed | 0 | no | identical |
| `test-commemorative-ambient` | 53 passed, 0 failed | 0 | no | identical |
| `test-commemorative-landing-page` | 45 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-assets` | 5 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-public-activation` | 5 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-cpt-privacy` | 11 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-single-package` | 6 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-ambient-decoration` | 7 passed, 0 failed | 0 | no | identical |
| `test-commemorative-gate-placement-assets` | 4 passed, 0 failed | 0 | no | identical |
| **`test-commemorative-gate-translation-ownership`** | **30 passed, 0 failed** | 0 | no | **new** |
| `test-seasonal-halloween-nav-effects` | 114 passed, 0 failed | 0 | no | identical |
| `test-seasonal-halloween-presentation` | 33 passed, 0 failed | 0 | no | identical |
| `test-seasonal-occasion` | 14 passed, 0 failed | 0 | no | identical (extra suite, unaffected) |

**Not run, deliberately:** the six engine suites inside
`wp-content/plugins/conexao-commemorative/tests/` (`admin`, `cache-boundary`,
`migration`, `preview`, `resolution`, `schedule`). They create and delete
`commemorative_event` records and options, and this task forbids mutating a
database whose isolation is not proven. Their recorded evidence (209 assertions)
was **not** re-proved here and is not claimed as such. The new gate covers the
PT/EN split that the migration suite asserts, read-only.

### Layer 3 — static gates

| Command | Exit | Result | Mutates | Baseline |
|---|---|---|---|---|
| `python3 tests/scripts/verify-commemorative-runtime.py` | 0 | 22 passed, 0 failed (11 Node cases) | no | identical |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | 14 passed, 0 failed | no | identical |
| `php scripts/generate-registry-docs.php --check` | 0 | 11 plugins, 20 regions current, **zero writes** | no | identical |
| `python3 tests/scripts/verify-script-conventions.py` | 0 | 779 passed, 0 failed | no | identical |
| `python3 tests/scripts/verify-i18n-freshness.py` | 0 | 3 passed, 0 failed (**still green**) | no | identical |
| `vendor/bin/phpcs -q --report=json …` + `php scripts/phpcs-baseline.php check …` | **0** | 2837/2221 vs 3290/2672 — "no new violations" | no | **was FAIL (16 new instances)** |
| `vendor/bin/phpstan analyse --no-progress` | 0 | `EXIT=0`, no errors | no | identical to `final-lint.txt` |

`i18n_freshness` stays green because its signal is **git commit time** and these
changes are uncommitted. ⚠ **Whoever commits this work must run
`./scripts/i18n-make-pot.sh --apply` in the same commit**: new/changed admin
strings and translator comments now exist in `conexao-admin-ux`,
`conexao-commemorative` and the theme.

### Layer 4 — permanent gates

`python3 scripts/verify-permanent-gates.py --out docs/evidence/2026-11-06-commemorative-local-remediation/gate.json`

| Result | Detail |
|---|---|
| **15 gates: 14 passed, 1 failed, 0 blocked** | assertions 172 passed / 2 failed; violations 3 (1 pre-existing, 2 new) |
| The one failure is `translation_completeness` | **pre-existing local data**: `post_type:guide:missing_en` 1 and `post_type:page:malformed_relationships` 2 — the same `/empregos/` pages the review recorded (created/modified 2026-10-21), not this work |
| New gate included | `commemorative_polylang / commemorative_translation_ownership` → **PASS 30/0** |
| Mutates | the script writes **only** its `--out` file, pointed at this task's evidence directory |

### Layer 5 — HTTP acceptance

| Command | Exit | Result | Mutates | Baseline |
|---|---|---|---|---|
| `python3 tests/acceptance/verify-commemorative-http.py` | 0 | **24 passed, 0 failed** (12 matrix rows + 11 authenticated preview rows + the new sitemap-producer classification) | no (logs in, reads pages) | 23/0 in the review; **+1 is the new classification check** |

### Not run (and why)

| Item | Reason |
|---|---|
| `./scripts/run-tests.sh` (aggregate) | The review recorded a multi-hour stall in `conexao-event-importer`; the layers above were run individually instead. The aggregate is **not claimed green**. |
| Browser/Playwright review | Not re-run. My only front-end edit is a **JS comment**, so the recorded 50/0 browser evidence still applies; the admin editor change is covered by the render probe and the gate. |
| `./scripts/verify-release.sh`, `release-manifest.py` | Builds `dist/` artifacts — release-preparation work, explicitly out of scope. |
| Production anything | Out of scope; not attempted. |

## 9. Deferred optional findings

| # | Why deferred |
|---|---|
| **L1** predictable preview token | A change here touches the cache-bypass contract and its HTTP preview rows; the review itself conditioned it on the host bypass proving unreliable, which cannot be measured from this repository. Left for the production-cache investigation. |
| **L3** ordering-only saves re-arm | The current behaviour is idempotent (clear + schedule one) and the single-boundary invariant holds; changing the hook sequence is a behaviour change with no defect to justify it. |
| **L7** landing-module query/cache cost | The review asks for measurement first; no query-count budget exists, and a transient without language scoping and explicit invalidation would be a cache-poisoning risk. Not measured, not added. |
| **L9** zero-placement package can win | It is a **content-calendar** rule, already documented in `docs/commemorative-content.md`, and no current seeded window overlaps. A "renders nothing" diagnostic is a feature, not a remediation. |

## 10. Limitations and separately authorised next actions

1. **Production sitemap (H1).** Authorise a read-only production check of
   `GET /sitemap.xml` **and** the page sitemap; if the landing pages appear, plan a
   separate exclusion for the producer that actually serves them. Until then the
   production path is **NOT VERIFIED**.
2. **M5.** An independent administrator must perform the precedence verification
   in local preview (§7). Not achievable by the implementer.
3. **Release manifest (M3/L6).** `dist/release.json` is stale (tag `v2026.11.06`,
   branch `leisure-location`, sha `83082ed`, data-model `1.6.3`, no
   `conexao-commemorative` artifact). Rebuilding it — together with the theme
   version bump if approved — belongs to the release-preparation change.
4. **Engine suites.** The six mutation-bearing plugin suites were not re-run here
   (isolation not provable); re-run them in an environment whose database is
   disposable before release.
5. **Aggregate runner.** The importer stall is unresolved and outside this task.
6. **i18n catalogues.** The commit that lands this work must regenerate the POTs
   (§8, Layer 3).

**This remediation does not make the project release-ready.** H2, the lint gateway
and the documentation are now in order; release readiness still depends on the
production sitemap verification, the release-manifest rebuild, the engine-suite
re-run and M5 — all expressly outside this task.

_Last verified: 2026-11-06 by the commemorative-events r10 local remediation_
