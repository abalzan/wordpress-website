# Report — EN Jobs URL consistency: `/en/jobs/` → `/en/empregos/`

| | |
|---|---|
| **Stage / task name** | EN Jobs URL consistency (shared-slug landing page) |
| **Date** | 2026-09-28 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `95b229c668c10c82d28d1feffc1b6562cf71a651` |
| **Final SHA** | `95b229c668c10c82d28d1feffc1b6562cf71a651` (no commit made — not authorised) |
| **Working tree at finish** | **dirty** — 9 modified + 3 untracked, all listed in §3. Nothing committed, nothing deployed. |

## 1. Scope completed

The EN Jobs destination is now `/en/empregos/`, the PT-derived path, consistent
with every other EN route in the `/en/` layer.

- The EN Jobs landing page (record **33309**) now reuses the PT `empregos`
  `post_name`, making `/empregos/` ↔ `/en/empregos/` a **shared-slug pair** —
  the same shape `/blog/` ↔ `/en/blog/` already used.
- A new `en-jobs-page` stage of `conexao-en-translation` performs the change
  through the **existing** `conexao-translation-rollout` engine, reusing the
  Stage O `wp_unique_post_slug` permit (generalised into one shared
  implementation, not a second copy).
- A new theme helper `conexao_resolve_shared_slug_page_request()` resolves the
  ambiguous `pagename` in the requested language, mirroring the existing
  `conexao_resolve_posts_page_request()` contract.
- A new permanent gate `shared_slug_page` proves the invariant, including the
  negative cases.
- Routing docs, the theme doc, the plugin doc and the HTTP acceptance matrix
  were updated to the new contract.

## 2. Scope NOT completed

- **No `/en/jobs/` → `/en/empregos/` redirect** was added. This was an explicit
  user decision (§ Redirect behaviour): `/en/jobs/` is simply no longer a route.
  The root-anchored `conexao_seo_redirects()` table and its permanent gate
  invariant ("destinations are never `/en/`") are untouched.
- **No production action** of any kind (§8).
- **Static analysis: fully clean.** An earlier `lint.sh` run aborted PHPStan
  (`composer: command not found`); the final run resolved the vendored PHPStan
  and reported `[OK] No errors`.

## 3. Files added / modified / deleted

**3 added, 9 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/jobs-page-data.php` | **added** | `jobs-page-v1` single-record dataset; EN title/body/excerpt/meta carried verbatim |
| `wp-content/themes/conexao-br-irlanda/tests/test-en-jobs-shared-slug.php` | **added** | permanent gate `shared_slug_page` |
| `docs/evidence/2026-09-28-en-jobs-empregos/` | **added** | machine evidence (snapshots, rollback dumps, HTTP, gates, negatives) |
| `wp-content/plugins/conexao-en-translation/includes/stage-config.php` | modified | `en-jobs-page` stage; shared-slug permit generalised to one implementation |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modified | requires the new data file; registers the stage |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/urls.php` | modified | `conexao_resolve_shared_slug_page_request()` |
| `wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php` | modified | E5b asserted the retired `/en/jobs/` destination |
| `tests/acceptance/matrices/routing.json` | modified | 4 Jobs rows replace `en-b2-302-en-empregos`; a11y row contract updated |
| `scripts/run-en-translation.php` | modified | stage list/usage string |
| `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/plugins/conexao-en-translation.md` | modified | routing contract + new mechanism + stage |

`wp-content/` changes are the plugin stage/data and the theme resolver + tests —
all in scope. **No Flutter/mobile file was accessed or modified.**

## 4. Runtime impact

`/en/empregos/` changed from **302 → `/empregos/`** to **200** serving the real
English Jobs page (`lang="en-US"`, self-canonical). Everything else is
byte-identical; `/en/jobs/` is no longer a route.

`conexao_resolve_shared_slug_page_request()` is the only new runtime hook. It is
a **no-op** unless all four conditions hold (non-default language request;
single page slug; **more than one** published page on that slug; the two records
are a **linked Polylang pair**). The gate proves a unique-slug page, a CPT slug,
an unpaired duplicate and every Portuguese request are untouched, and that the
site's real shared-slug pairs are exactly `blog` and `empregos`.

## 5. Content / data impact

**One field on one EN record**: `post_name` `jobs` → `empregos` (record 33309).
No record created, none deleted.

Full before/after snapshot diff of both records is **exactly two lines**:

```
- "slug": "jobs",                                  + "slug": "empregos",
- "permalink": ".../en/jobs/",                     + "permalink": ".../en/empregos/",
```

PT content hash, title hash, excerpt, status, template, parent, meta and
taxonomy are all **byte-identical**. EN title, content hash, excerpt and
`conexao_meta_description` are unchanged too.

## 6. Polylang impact

- PT Jobs page (11293) language remains `pt`; EN page (33309) remains `en`.
- The pair is intact and **bidirectional**: `pll_get_post(11293,'en')=33309` and
  `pll_get_post(33309,'pt')=11293`, same translation group `pll_6aba37075acd8`.
- **No duplicate EN Jobs page**: exactly 1 EN record on the `empregos` slug
  (SQL-verified) and the gate's blast-radius check counts 2 shared pairs.
- Apply reported `PT-drift=0`; the engine's own PT-immutability comparison
  passed.
- `translation_completeness` gate: 20 passed, 0 failed, 1836 allowlisted.

## 7. Route / HTTP impact

| ID | URL | Before | After |
|---|---|---|---|
| `jobs-en-new` | `/en/empregos/` | 302 → PT | **200**, `en-US`, self-canonical |
| `jobs-en-hreflang` | `/en/empregos/` | — | **200**, en self / pt-BR `/empregos/` / x-default PT |
| `jobs-pt` | `/empregos/` | 200 | **200**, unchanged, hreflang now → `/en/empregos/` |
| `jobs-legacy-301-pt` | `/jobs/` | 301 → `/empregos/` | **301 → `/empregos/`** (unchanged) |
| `jobs-en-old` | `/en/jobs/` | 200 (canonical EN Jobs) | **no longer a route** (404-family core guess) |
| `a11y-en-empregos-real-english-page` | `/en/empregos/` | 302 | **200** |

Final standalone routing matrix: **90 rows passed, 2 failed** — all 9 Jobs rows
pass; the 2 failures are the pre-existing Lazer excerpt rows, proven unrelated by
re-testing with this change stashed (`Keadeen` match count 0 in both runs).

Navigation, verified at runtime with **no nav code change** (the destination
follows `pll_get_post()` → `get_permalink()`): EN desktop `nav-link` and EN
mobile drawer both → `/en/empregos/`. PT nav unchanged → `/empregos/`. No
language-context leakage; `/empregos/oportunidades/` and
`/en/empregos/oportunidades/` (EN job singles, already on the `empregos` base)
both still 200.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** (production) | Local Docker DB only, via the approved content-change workflow |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `scripts/run-en-translation.php --dry-run --only=jobs-page` | 0 | `updated=1 created=0 conflicts=0 PT-drift=0` |
| `scripts/run-en-translation.php --apply --only=jobs-page` | 0 | `updated=1`, `GATE PASS`, `PT-drift=0` |
| re-run `--dry-run` (idempotency) | 0 | `skipped=1 updated=0` — "already linked and verified" |
| `python3 scripts/verify-permanent-gates.py` | 0 | **7/7 gates PASS**, 86 assertions, 0 violations, AGGREGATE PASS |
| `git diff --check` | 0 | clean |
| `./scripts/lint.sh` (syntax + PHPCS + PHPStan) | **0** | **lint: OK** — syntax clean (468 files), **no new PHPCS violations** (legacy debt even fell 3290→3207), **PHPStan level 5: [OK] No errors** (206 files) |
| `./scripts/run-tests.sh` | 1 | see §11 — failing-set diff vs baseline |

### Numeric test results

- In-process PHP: **61 suites, 44 passed, 17 failed**; **3680 assertions passed,
  69 failed**. Baseline was 60/44/16 and 3669/64 — the extra suite is the new
  gate (16/16 pass) and the extra failures are two stale-contract assertions
  since corrected (§11).
- Script-contract: **6 suites, 6 passed, 0 failed**.
- HTTP acceptance: **3 suites**; routing matrix **89 passed, 3 failed** on first
  run — 1 was a stale Jobs contract row (fixed), 2 are pre-existing Lazer data
  failures unrelated to this change.
- Permanent gates: **7 total, 7 passed, 0 failed, 0 blocked**.

## 10. Failure proofs / negative tests

Recorded in `docs/evidence/2026-09-28-en-jobs-empregos/06-negative-tests.txt`:

| Proof | Result |
|---|---|
| `/en/empregos/` does not resolve to PT content | **proved** — `lang="en-US"`, canonical self |
| `/en/jobs/` creates no second Jobs page | **proved** — SQL count = 1 |
| PT Jobs does not redirect to EN | **proved** — `/empregos/` 200, no `Location` |
| EN Jobs does not redirect back to PT | **proved** — `/en/empregos/` 200, no `Location` |
| No redirect loop | **proved** — `-L --max-redirs 10` → `redirects=0`, final 200 |
| No duplicate canonical | **proved** — 1 canonical on each of the two pages |
| Unrelated `/en/*` routes unchanged | **proved** — 9 sampled routes all 200 |
| Unrelated page slugs unchanged | **proved** — `/sobre-nos/`, `/contato/`, `/blog/` all 200 |
| Unique-slug page not captured by the new filter | **proved in gate** — `contato` untouched |
| CPT slug not captured | **proved in gate** — `guias` untouched |
| Unpaired duplicate not bound to a language | **proved in gate** |
| PT request not rewritten | **proved in gate** |

The gate itself was shown to fail: its first run reported
`14 passed, 2 failed` (a fixture got uniquified to `-2`, and a comparison
compared annotated strings). Both were defects **in the new test**, fixed, and
the gate then reported `16 passed, 0 failed`. A gate never seen red is not a
gate.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 16 | 17 → **16** after the two stale-contract fixes |
| Failing assertions | 64 | 69 → **64** |
| Failing script-contract | 0 | 0 |
| Failing acceptance rows | 3 | 3 (1 Jobs contract row fixed) → **2** after the fix: only the 2 pre-existing Lazer rows |

**Two stale-contract failures were introduced by the URL change and then
corrected** — they asserted the retired `/en/jobs/` contract, not behaviour:

1. `test-header-menu-selection.php (en)` — "E5b EN Jobs item points at the
   linked EN translation `/en/jobs/`". Updated to `/en/empregos/`; now
   **16 passed, 0 failed**.
2. `routing.json` row `a11y-en-empregos-b1-still-302` — asserted `/en/empregos/`
   302s. Updated to the real-EN-page contract.

No unrelated failure appeared, and no gate was weakened or allowlisted.

## 12. Known pre-existing failures

Unchanged by this work (16 in-process suites fail identically before and after):

- `test-jobs-en-language.php` (**92 passed, 11 failed** — identical both runs),
  `test-job-en-translation.php`, `test-blog-en-translation.php` (PCRE2 has no
  `\F`), `test-stage32/33/41/45`, `test-i18n-foundation`, `test-event-*`,
  `test-leisure-attribute-normalization`, and the `conexao-event-importer` /
  `conexao-leisure-migration` suites. These are local content-data and
  environment conditions (missing EN job/leisure fixture data, a PCRE2 build
  without `\F`), not routing.
- Two routing-matrix Lazer rows (`en-lazer-card-excerpt-is-english`,
  `pt-lazer-card-excerpt-stays-portuguese`) fail because the expected Keadeen
  excerpt text is absent from `/lazer/`; verified independently that this change
  touches no Lazer code or data.

## 13. Limitations

- The routing acceptance suite is slow (`/eventos/` alone takes ~16.5s) and
  exceeded the 300s per-suite budget in the full run, appearing as
  `[TIMEOUT]`; it was therefore also run standalone to completion.
- **Production was not exercised at all** (no access, and out of scope). The
  Polylang shared-slug resolution and the stage both run identically in
  production, but that is an expectation, not a measurement.
- `/en/jobs/` returning WordPress core's misleading 301 to an unrelated PT
  event is **pre-existing** behaviour for any unmatched `/en/` path
  (`/en/courses/`, `/en/post/foo/` do the same) and is documented in
  `docs/reports/CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md:388`. It was left
  deliberately rather than suppressed, since suppressing it would be a routing
  policy change outside this task's scope.

## 14. Evidence paths

`docs/evidence/2026-09-28-en-jobs-empregos/`

| File | Proves |
|---|---|
| `00-baseline-content-snapshot.json` | pre-change state of both Jobs records |
| `01-rollback-wp_posts.sql`, `01-rollback-wp_postmeta.sql`, `01-rollback-wp_term_relationships.sql` | pre-mutation rows, sufficient to restore the previous EN slug |
| `02-after-content-snapshot.json` | post-change state; diff vs 00 is slug + permalink only |
| `baseline-http.txt`, `baseline-http2.txt` | pre-change HTTP for every route touched |
| `03-after-http.txt`, `04-after-http-matrix.txt` | post-change HTTP matrix |
| `05-gate-shared-slug-page.txt` | gate output `16 passed, 0 failed` |
| `06-negative-tests.txt` | all negative proofs, incl. the `/en/jobs/` core-guess note |

## 15. Documentation updated

- `docs/routing.md` — the `/en/` table row for the Empregos landing, the
  `conexao_lang_url()` example, the EN-nav Jobs bullet, a new
  §"Shared-slug pages" section, and a `_Last verified_` line.
- `docs/themes/conexao-br-irlanda.md` — the new resolver in the function table
  and the five `/en/jobs/` references.
- `docs/plugins/conexao-en-translation.md` — the `en-jobs-page` stage, and the
  dataset/stage inventory rows.

## 16. Rollback / recovery

Code: `git checkout -- <the 9 modified files>` and delete the 3 added paths.

Content: the EN record is restored by the pre-change dump, or by re-running the
stage with the EN slug reverted. The simplest supported path is
`--remove --apply --only=jobs-page`, which deletes the EN translation the stage
owns and re-compares the PT snapshot (PT is never touched either way). For a
pure slug revert without removing the translation, restore record 33309's
`post_name` to `jobs` from `01-rollback-wp_posts.sql`.

Rollback does **not** cover production (nothing was written there) and does not
cover the two pre-existing Lazer matrix failures.

## 17. Final status

**Final status: PASS**

Every applicable verifier ran and passed with real numbers — the 7 permanent
gates (0 violations), the new `shared_slug_page` gate (16/16), the routing
matrix (90 passed, only the 2 pre-existing Lazer rows failing), static analysis
(PHPCS + PHPStan clean), and every negative proof; no gate was weakened or
allowlisted. Production was not exercised, but no production action was in
scope, so that is not a missing verifier for this work.

_Last verified: 2026-09-28 by the EN Jobs URL consistency change_
