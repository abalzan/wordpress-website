# Report — Production deployment readiness audit (`i18n`, read-only)

> **AUDIT ONLY. Nothing was deployed.** No production write, upload, activation,
> menu change, Polylang change or database synchronisation was performed. No
> commit was made. The Flutter/mobile repository was not accessed.
>
> Machine evidence: [`docs/evidence/2026-09-29-production-deployment-audit/`](../evidence/2026-09-29-production-deployment-audit/)

| | |
|---|---|
| **Stage / task name** | Production deployment readiness audit — `i18n` branch |
| **Date** | 2026-09-29 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `a4cd5f65e35c3f6f564e733bc746a07f350cceeb` |
| **Final SHA** | `a4cd5f65e35c3f6f564e733bc746a07f350cceeb` (unchanged) |
| **Working tree at finish** | **clean** (HEAD unmoved; no tracked file modified; only this report + evidence added, and git-ignored `dist/`) |

**Final status: `BLOCKED`** — six blockers (§"Blockers"), the most severe being that
**Polylang is not installed on production**, so the entire `i18n` value proposition
cannot function there, and **no component version was bumped** for +28,738 changed
production lines, which violates the release contract.

---

## 1. Scope completed

1. Branch/HEAD/ahead-behind state established; `master` vs `i18n` diffed.
2. Production payload determined from `plugins.json` (the authoritative registry),
   cross-checked against `docs/deployment.md`, `docs/releases.md` and
   `docs/engineering-standard.md` §11.
3. Release build executed; `dist/release.json` produced; manifest verified.
4. `./scripts/verify-release.sh` executed end to end (**all 7 stages OK**).
5. Version audit for all five production artifacts (`master` vs `i18n`).
6. Every database-write primitive in the four production plugins and the theme
   enumerated and classified by trigger, idempotency and deletion capability.
7. Each production plugin's activation/deactivation hook read in full.
8. Read-only production baseline captured via `scripts/verify-deploy.py` plus
   direct read-only HTTP/REST probes.
9. Production menu order, EN data presence, `/en/empregos/` feasibility,
   language-switcher flag and Polylang state determined.
10. Backup / staging / rollback procedures determined against WordPress.com's
    documented platform behaviour.

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| Any production write, deploy, upload, activation | Task is audit-only (explicit constraint). |
| Confirming **installed plugin/theme versions on production** | `/wp-json/wp/v2/plugins` returns **401**; no admin access. Production currently runs a **different, older theme build** than `master` — version unverifiable. |
| Confirming the site's **WordPress.com plan tier** | Requires the Hosting Dashboard. Gates whether backup/restore and staging exist — **blocker B4**. |
| Confirming Polylang configuration on production | Not installed; nothing to configure. |
| Running translation rollout stages against production | Out of scope (write op) and impossible — the engine is `build: false`. |
| Fixing the 11 failing local test suites | Out of scope: pre-existing local fixture/DB-state failures (§12), not production-artifact defects. |
| Bumping component versions | Task says "Do not change versions during this audit." Recorded as **blocker B1**. |

## 3. Files added / modified / deleted

**1 added** (this report), **1 evidence directory added**, **0 modified**, **0 deleted.**

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-29-production-deployment-readiness.md` | added | this report |
| `docs/evidence/2026-09-29-production-deployment-audit/` | added | 7 machine-evidence files |

**No `wp-content/` change. No source change. No commit.** `dist/` is git-ignored
(`.gitignore:6`); building produced no tracked-file change.

## 4. Runtime impact

None — no code was changed and nothing was deployed.

## 5. Content / data impact

**None.** Confirmed: HEAD unmoved; `git status wp-content/` empty at finish.

Production was only ever **read** (HTTP GET). Every production probe was anonymous,
credential-free and read-only. No post, term, menu, option or meta row was written.

## 6. Polylang impact

**This is the central finding of the audit.**

- **Polylang is NOT installed on production.** Proof: `GET /wp-json/pll/v1/languages`
  → **404**; no `pll`/`polylang` namespace in `/wp-json/`
  (evidence `production-rest-types.json`).
- Consequently production has a **single language**: `?lang=pt` and `?lang=en`
  return **identical** totals for every post type (guide 56/56, event 1808/1808,
  leisure 289/289, pages 43/43) and `?lang=en` returns **Portuguese slugs**.
- Every PT page serves `<html lang="en-US">` (WordPress's `WPLANG` default, since no
  bilingual filter is loaded) and **zero `hreflang` tags site-wide**.

Consequence: **every EN code path on `i18n` is a hard no-op on production today.**
The theme gates its entire bilingual layer behind `conexao_polylang_active()`
(`inc/i18n/guard.php:28-30` — `function_exists('pll_current_language') && …`).
Without Polylang the guard returns `false` and the EN layer never engages.

**Deploying the `i18n` code to production as-is would deliver none of the i18n
functionality it was built to deliver.** PT content, PT URLs and PT slugs would be
unaffected — but so would every EN benefit.

PT drift: **0** (nothing written). EN records created: **0**.

## 7. Route / HTTP impact

No production change was made. The read-only baseline records the **current**
production state, which differs materially from what the release expects.

| Path | Production now | Release expects |
|---|---|---|
| `/` | 200, `<html lang="en-US">` | 200, `<html lang="pt-BR">` |
| `/en/` | **301** → `/eventos/encore-halloween-edition-special-guest-1926/` | 200 |
| `/en/guias/` | **404** | 200 |
| `/en/eventos/` | **404** | 200 |
| `/en/lazer/`, `/en/blog/` | **301** → PT equivalents | 200 |
| `/en/empregos/` | **301** → `/empregos/` | 200 |
| `/en/guias/<slug>/` | **301** → PT single | — |
| `/guias/ /eventos/ /cursos/ /empregos/ /apoiadores/ /lazer/ /blog/` | 200 | 200 |
| `/sitemap.xml`, `/robots.txt` | 200 | 200 |
| `/nao-existe-xyz-stage-j/`, `/en/nao-existe-xyz-stage-j/` | 404 | 404 |
| `/guides/` | 301 → `/guias/` | 301 |

`verify-deploy.py --site https://conexaobr.ie`: **20 passed, 21 failed**
(`docs/evidence/.../deploy-production-baseline.json`).

Note the `/en/` → a specific *event* redirect: that is **not** a designed behaviour
of the `i18n` branch — it is production's existing fallback, and must be understood
before any rollout.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | read-only GET only |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |
| Menu change | **no** | |
| Polylang change | **no** | Polylang is not installed |
| Staging→production DB sync | **no** | explicitly forbidden and not done |
| Local commit | **no** | HEAD unchanged |
| Flutter/mobile repository | **no** | not accessed |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `git status --porcelain -b` | 0 | clean; `## i18n...origin/i18n` |
| `git rev-list --left-right --count master...i18n` | 0 | `0  129` — **i18n is 129 ahead, 0 behind** |
| `git merge-base master i18n` | 0 | `9cb051178afcb57b17b36255832c562a9bec30e9` (= master HEAD) |
| `./scripts/lint.sh` | **0** | syntax 472 files OK; PHPCS no new violations (3211 err / 2668 warn vs baseline 3290/2672); **PHPStan level 5: 0 errors** |
| `./scripts/run-tests.sh` | **1** | **TESTS FAILED** — see §12 |
| `./scripts/build-plugins-zip.sh` | 0 | 7 plugin ZIPs |
| `./scripts/build-theme-zip.sh` | 0 | 1 theme ZIP |
| `python3 scripts/release-manifest.py --verify` | **0** | `release manifest verification: OK` |
| `./scripts/verify-release.sh` | **0** | **RELEASE VERIFICATION: OK** (7/7 stages) |
| `php scripts/generate-registry-docs.php --check` | 0 | 14 plugins, 23 regions current, zero writes |
| `git diff --check` | 0 | clean |
| `python3 scripts/verify-deploy.py --site https://conexaobr.ie` | 0 | **20 passed, 21 failed** (baseline captured) |

### Numeric test results

- **In-process PHP: 62 suites total, 52 passed, 10–11 failed** (count varies between
  runs; one suite is order-sensitive). **Assertions: 2,910 passed, 2 failed.**
  - 1 **prerequisite failure** (local DB state).
- **Script-contract: 6 suites, 6 passed, 0 failed.**
- **HTTP acceptance: 3 suites, 3 passed, 0 failed** —
  `verify-guides-en-http.py` 20/20, `verify-release-http.py` 44/44,
  `verify-routing-http.py` 92/92 (against the local Docker site).

### Release / build results

`dist/release.json` — `v2026.09.29`, git `a4cd5f65e35c3` on `i18n`, `dirty: false`.

| Artifact | Kind | Version | Files | Bytes | SHA-256 (first 16) |
|---|---|---|---|---|---|
| `conexao-data-model` | plugin | 1.6.0 | 6 | 15,846 | `5de7054ab021019b` |
| `conexao-content` | plugin | 1.0.0 | 5 | 22,483 | `ef6f2f13dba156f2` |
| `conexao-admin-ux` | plugin | 1.0.6 | 14 | 75,457 | `1954f3a528430a31` |
| `conexao-event-runtime` | plugin | 1.2.1 | 7 | 18,482 | `b25fcd7bd6b1e3c6` |
| `conexao-event-importer` | plugin | 1.7.1 | 42 | 177,550 | `551ef74cdf161b63` |
| `conexao-leisure-migration` | plugin | 2.1.0 | 7 | 28,967 | `e91eb8b09bd69710` |
| `conexao-sponsor-migration` | plugin | 1.1.0 | 6 | 21,738 | `b4d4e52562bee987` |
| `conexao-br-irlanda` | **theme** | 1.0.0 | 115 | 5,894,436 | `e91377ac6e107089` |

Totals: **8 artifacts, 8 built, 202 files, 6,254,959 bytes.**
`production_order`: `conexao-data-model → conexao-content → conexao-admin-ux → conexao-event-runtime`.

`verify-release.sh` proved: registry gate OK · build OK · manifest OK ·
allowlist/hash OK · **determinism (byte-identical rebuild)** OK ·
**exclusion proof (no tests/fixtures/reports/junk)** OK · local HTTP 44/44.

## 10. Failure proofs / negative tests

The production `verify-deploy.py` run **is itself a negative proof**: 21 of 41 checks
fail against a live site, demonstrating the gate can and does fail on imperfect
deployments. The determinism and exclusion stages add proof that the release
machinery rejects drift.

## 11. Regression comparison

No change was made, so before == after by construction. Recorded as the
**pre-change baseline** for future comparison:

| Metric | Baseline (2026-09-29, `a4cd5f6`) |
|---|---|
| Failing in-process suites | 10–11 of 62 |
| Failing assertions | 2 of 2,912 |
| Failing script-contract | 0 of 6 |
| Failing acceptance assertions | 0 of 156 |
| Production `verify-deploy` | 20 passed / **21 failed** |

## 12. Known pre-existing failures

All failing in-process suites are **local-environment / fixture failures, not
production-artifact defects**. Grouped by root cause:

| Cause | Count | Suites affected |
|---|---|---|
| Retired rollout plugin dirs exist in git but their `includes/` payload was removed (`conexao-page-translation/includes/translation-map.php` missing) — tests `require_once` a file no longer there | 4× "Failed opening required" | `test-jobs-en-language`, `test-stage41-rest-language`, `test-stage45-pages`, +1 |
| Functions expected from the **local-only** `conexao-en-translation` / `conexao-translation-rollout` engine not loadable in that suite's context | 2× "not found or invalid function name" | `test-en-jobs-shared-slug`, +1 |
| Local DB lacks the Polylang EN translation fixtures ("insufficient data: polylang") | 2 | `test-guide-en-translation`, `test-leisure-card-excerpt-language` |
| Shared rollout engine + stage not loaded | 2 assertion FAILs | `test-course-provider-card-excerpt-language`, `test-leisure-card-excerpt-language` |

None of these compile into a production artifact: `verify-release.sh`'s **exclusion
proof confirms no `tests/` directory ships in any ZIP**, and
`conexao-translation-rollout` / `conexao-en-translation` are `build: false`.
They are **not** a deployment blocker, but `run-tests.sh` is red and must not be
silently waived — fix or formally triage as fixture debt before cutting a tag.

## 13. Limitations

1. **No wp-admin access** — installed plugin set and versions on production are
   unverifiable (`/wp-json/wp/v2/plugins` → 401). Production runs a **materially
   different theme build** from `master`, and none of the four custom plugins can be
   confirmed present. The "production plugin set" precondition is **unknown**, not
   "known good".
2. **WordPress.com plan tier unknown** — whether Jetpack Backup/restore-point and
   Staging exist depends on Business vs Commerce vs Premium/Free. Unreadable without
   the Hosting Dashboard.
3. **Polylang state on production cannot be read** beyond confirming it is absent.
4. Production `verify-deploy` ran once against a **cached** CDN edge; some failures
   may be cache artefacts — though the `en-US` lang attribute and absent Polylang
   namespace are structural, not cache.
5. No attempt to determine production `_event_status` distributions or per-record
   meta (admin-only).

## 14. Evidence paths

`docs/evidence/2026-09-29-production-deployment-audit/`

| File | Proves |
|---|---|
| `deploy-production-baseline.json` | the 20-pass / 21-fail production HTTP baseline |
| `release.json` | the exact release record: versions, SHA, counts, bytes, SHA-256 |
| `lint.log` | lint gates green (syntax, PHPCS baseline, PHPStan 0 errors) |
| `run-tests.log` | full test output incl. failing suites and causes |
| `verify-release.log` | all 7 release stages OK, incl. determinism + exclusion proof |
| `production-rest-types.json` | production CPT/taxonomy registration surface |
| `production-home-baseline.html` | production `<html lang="en-US">`, 0 hreflang, menu order |

---

# Production rollout decision table — the primary deployment artifact

| Change | In release ZIP? | Writes production DB? | Must be separately migrated? | Risk | Safe procedure |
|---|---|---|---|---|---|
| **Theme** `conexao-br-irlanda` | **Yes** (5.9 MB, 115 files) | **No** — no activation hook; all write paths are admin-POST-only, nonce + `current_user_can` gated (`inc/empregos-landing.php:172` on `save_post_page`; `inc/job-resources.php:297` option, `edit_others_posts` + nonce) | No | **MEDIUM** — but delivers **zero i18n value** without Polylang | Upload ZIP, activate. Theme mods live in DB (`theme_mods_*`) and **survive** file replacement. One media regenerate for the new image sizes. |
| **data-model** 1.6.0 | Yes (6 files) | **Only on activation** — `Conexao_Data_Model_Relationships::seed_terms()` `wp_insert_term` × 4 lists. **Additive only**, each guarded by `term_exists()`; **no update, no delete, no relationship change**. Plus `flush_rewrite_rules()` | No | **LOW** (idempotent term seeding) | Update in place. **Do NOT deactivate/reactivate** to "force" it. |
| **content** 1.0.0 | Yes (5 files) | **YES — on activation it DESTROYS AND RECREATES BOTH MENUS** (see activation audit). Also `update_option` × 3 (`show_on_front`, `page_on_front`, `page_for_posts`), `set_theme_mod('nav_menu_locations')`, and page creation | No | **HIGH on activation; LOW on update-in-place** | **Update in place (replace ZIP). NEVER deactivate/reactivate `conexao-content` on production.** See blocker B2. |
| **admin-ux** 1.0.6 | Yes (14 files) | **No** — `activate()`/`deactivate()` are `flush_rewrite_rules()` only. Runtime writes are `save_post`-driven (`class-translation-state.php:181,188` set/clear `_translation_outdated`) | No | **LOW** | Update in place. Note: the new `save_post` hook starts stamping `_translation_outdated` on EN translations as soon as PT posts are edited. |
| **event-runtime** 1.2.1 | Yes (7 files) | **No** — `activate()` = `register_meta` + `register_town_taxonomy` + flush. `mark_expired_events()` writes `_event_status` but is **only** invoked by the local importer, never on production requests | No | **LOW** on data; **HIGH on behaviour if deactivated** — removes the public `_event_status` gate | Update in place. Never deactivate in production. |
| **EN Guides** (48) | **No** | n/a | **YES — `en-guide` rollout** | **HIGH if improvised** | Shared engine + stage plugin installed manually (not in a release ZIP), admin screen → dry-run → snapshot → apply → verify → idempotence. **PT immutable.** |
| **EN pages** | **No** | n/a | **YES — page rollout** | MEDIUM | Same six-step contract. |
| **EN Jobs page** (`/en/empregos/`) | **No** (code only) | n/a | **YES — requires a linked EN Page record** | MEDIUM | The shared-slug resolver is a **no-op unless a published EN `empregos` page exists AND is a linked Polylang pair** (`inc/i18n/urls.php:238-250`). Production has **one** `empregos` page (REST id 11086) and **no EN translation** (`?lang=en` returns the PT record). **Do not manufacture it during code deployment.** |
| **13 EN `conexao_category` terms** | **No** | n/a | **YES — taxonomy rollout** | MEDIUM | Engine has taxonomy capability (commit `cd8b6c6`). Translated-taxonomy policy must hold. |
| **Navigation** | **No** | n/a | **No — already correct** | **NONE** | Production's rendered PT menu is **already** exactly `Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato` (evidence `production-home-baseline.html`, both desktop and mobile blocks). **No wp-admin reorder required. No plugin reactivation as a menu "fix".** |
| **Language switcher** | **Yes** (constant, `functions.php:44`) | No | No | **NONE (must stay off)** | `CONEXAO_LANGUAGE_SWITCHER_ENABLED = false` is a **file constant**, not a DB option — deploying the theme preserves it. Direct EN URLs remain reachable. **Do not re-enable.** |
| **Polylang** | **No** (third-party, installed not vendored) | Would create the language layer | **YES — prerequisite, entirely manual** | **BLOCKER B3** | Must be installed + configured in wp-admin **before** any EN work. Configure PT default / EN secondary; set translated vs shared taxonomies. |

## Activation audit

| Plugin | Update in place safe? | Activation writes? | Deactivation risk? | Data-loss risk |
|---|---|---|---|---|
| `conexao-data-model` | **Yes** | **Yes** — seeds 13 + 15 `conexao_category`, 26 `conexao_county`, 13 `conexao_leisure_attribute` terms, each guarded by `term_exists()`; then `flush_rewrite_rules()` | Flush only | **None** — additive, idempotent, no update/delete |
| `conexao-content` | **Yes** | **YES — destructive. Runs `create-pages.php`, which for BOTH `Menu Principal` and `Menu Rodapé` executes `wp_delete_post($item->ID, true)` on every existing item, then re-adds. Also `update_option` × 3 and `set_theme_mod('nav_menu_locations')`.** | Leaves front-page/blog/menu state rewritten | **HIGH if activated**: any hand-curated menu item, custom link or CSS class is permanently destroyed; menu `menu_order` reset |
| `conexao-admin-ux` | **Yes** | **No** — `flush_rewrite_rules()` only | Flush only | **None** |
| `conexao-event-runtime` | **Yes** | **No** — `register_meta` + `register_town_taxonomy` + flush | Flush only, **but removes the public `_event_status` gate from the site** | **None on data**; **HIGH on behaviour if left off** |

**Update ≠ activation.** A ZIP upload over an already-active plugin **does not** fire
`register_activation_hook`. That is exactly why `conexao-content` can be deployed
safely here: its destructive code is reachable **only** through activation. The
deployment instruction must therefore read *"replace the plugin files"* — never
"deactivate, then activate".

## Existing production data impact

| Category | Status | Notes |
|---|---|---|
| Posts / Pages | **UNCHANGED BY CODE UPDATE** | no activation-time content write unless `conexao-content` is activated |
| Events | **UNCHANGED BY CODE UPDATE** | `mark_expired_events()` is importer-driven, not request-driven |
| Guides / Jobs / Leisure / Sponsors / Course providers | **UNCHANGED BY CODE UPDATE** | |
| Taxonomy terms | **POTENTIALLY AFFECTED BY ACTIVATION** | `data-model` activation adds missing terms only |
| Taxonomy relationships | **UNCHANGED** | no `wp_set_object_terms` outside admin POST handlers |
| Menus / Menu items | **POTENTIALLY AFFECTED BY ACTIVATION** | `conexao-content` activation deletes + recreates — **avoid** |
| Media / Attachments | **UNCHANGED** | theme registers additive image sizes; old attachments need one manual regenerate for `srcset` |
| Post meta | **REQUIRES BACKUP BEFORE CHANGE** | `admin-ux`'s new `save_post` hook begins stamping `_translation_outdated`; event importer writes `_event_status` |
| Term meta | **UNCHANGED** | |
| Options | **POTENTIALLY AFFECTED BY ACTIVATION** | `conexao-content` writes `show_on_front`, `page_on_front`, `page_for_posts` |
| Users | **UNCHANGED** | |
| Comments | **UNCHANGED** | |
| Redirects | **UNCHANGED** | theme rewrite logic is request-time; see `docs/releases.md` §"What a rollback does not cover" |
| Polylang relationships | **REQUIRES EXPLICIT CONTENT MIGRATION** | none exist in production today; created only by rollouts |

## Data-loss scenarios

| Scenario | Assessment |
|---|---|
| **Plugin deactivate/reactivate** | **The one real code-side risk.** Reactivating `conexao-content` destroys both menus. Deactivating `event-runtime` removes the status gate. **Mitigation: update in place only.** |
| **Theme replacement** | **Safe.** Theme mods, widget layout and menu assignments live in the DB (`theme_mods_*`, `sidebars_widgets`) and survive file replacement. |
| **Staging → production DB sync** | **Forbidden.** WordPress.com: *"Syncing from staging to production will overwrite matching content on your live site, including your user list. Any data added to production after your last sync will be replaced."* |
| **Bad translation manifest** | Mitigated by the engine's `pt_drift` gate (= 0 required) and the PT-immutable rule. Never improvise SQL. |
| **EN/PT menu assignment overwrite** | Avoided entirely — production nav already correct; **do not** run menu-assignment scripts against production. |
| **Taxonomy repair / term merge** | Avoided — `data-model` only inserts missing terms. Do not run `scripts/cleanup-*` / `repair-*` on production. |
| **One-shot content scripts** | `scripts/` holds ~40 write scripts (`seed-*`, `import-*`, `remove-*`, `migrate-*`, `cleanup-*`). **None are production tools.** Production has no CLI so none can run there — but do not port them. |
| **Rollback by DB restore** | **Destroys post-backup content.** See the rollback plan. |

## Backup & staging requirement

WordPress.com Jetpack Backup and Staging are **Business/Commerce-plan features**
(`wordpress.com/support/restore/`, `/sync-staging-site/`, `/how-to-create-a-staging-site/`).
This site runs Jetpack (namespace present). **Tier unconfirmed → blocker B4.**

- Restore: *Jetpack → Backup → select date → "Restore to this point"*.
- ⚠️ *Restoring a backup will overwrite your site. Any changes made after that backup
  will be lost.*
- ⚠️ Database restores can re-trigger subscription billing (Action Scheduler).
- Staging: one per production site. A **files-only** staging→production sync is the
  safe direction for code. **Never sync the database staging→production.**
- A verified recent backup/restore point is a **hard prerequisite** for any
  write-capable production operation (any rollout, any menu edit).

## Deployment sequence — validated against the release contract

Standard §11's normative order is *build → upload theme → upload plugins → activate
to steady state → verify*. For this branch the **"activate" step must be replaced by
"update in place"**, and **Polylang must be installed first**. Corrected order:

1. **Verified production backup/restore point** (Jetpack → Backup), timestamped.
2. Resolve blockers B1–B5.
3. `lint.sh` + `run-tests.sh` + `verify-release.sh` + `release-manifest.py --verify` green.
4. Capture pre-deploy inventory: PT counts + **slugs** (never IDs), menu order,
   `verify-deploy` baseline.
5. **Install + configure Polylang** in wp-admin (PT default, EN secondary; translated
   vs shared taxonomies per standard §6). Verify PT is still canonical.
6. Create staging; sync **production → staging** (files + database) to prove the change.
7. Apply ZIPs to staging; verify; only then to production.
8. Upload the **theme** ZIP → activate (theme mods in DB survive).
9. Update the 4 platform plugins **in `plugins.json` order, in place — no
   deactivate/reactivate**. Upload only `conexao-data-model`, `conexao-content`,
   `conexao-admin-ux`, `conexao-event-runtime`. **Do NOT upload**
   `conexao-event-importer`, `conexao-leisure-migration` or
   `conexao-sponsor-migration` even though they are `build: true` — they are
   `production: false`.
10. `verify-deploy.py --site https://conexaobr.ie` — expect 41/41 (baseline 20/41).
11. *Separately and explicitly authorised:* translation rollouts via the admin workflow.
12. Record in `docs/releases.md` (versions + SHA + verification result) — currently
    the release log reads *"(no release performed by this tooling yet)"*.

## Translation rollout requirements

Production has no WP-CLI and no SQL, so rollouts use the **admin workflow**:

```
inventory → manifest → dry-run → snapshot → apply → verify → idempotence → gate
```

The engine (`conexao-translation-rollout`) and stage plugins are `build: false` /
`production: false` and must be **installed manually, temporarily**
(`activate → apply → remove`). Required numbers: `pt_drift = 0`, duplicate
translations = 0, orphans = 0, unexpected updates = 0, gate = PASS; a second apply
must be a no-op. This satisfies standard §0.7 and the repo's six-step content-change
contract. Cross-environment matching must use `slug` / UUID / translation
relationship — **never WordPress post IDs**.

## Rollback plan

| Failure | Response | Warning |
|---|---|---|
| Theme or plugin code defect | Re-upload the **previous ZIPs** (keep them — `dist/` is git-ignored and ephemeral), reactivate the previous theme, plugins in registry order | **A file-only rollback is sufficient and correct for a code fault. Do NOT restore the database.** |
| A single plugin is the cause | Deactivate it in wp-admin, re-verify | Acceptable fallback; the 4-plugin steady state is always known-good |
| Translation rollout defect | Restore from the rollout **snapshot** and re-run the documented recovery | A code rollback does **not** undo a content change |
| Unexpected DB mutation | Verified Jetpack Backup restore | ⚠️ **A full restore removes everything created after the backup point.** Never the first-line response |

## Blockers

| # | Blocker | Severity | Evidence | Must be resolved by |
|---|---|---|---|---|
| **B1** | **No version bump on any production artifact.** `master`→`i18n` changed **127 files / +28,738 / −6,619** across the 4 plugins + theme, yet all 5 versions are byte-identical (data-model 1.6.0, content 1.0.0, admin-ux 1.0.6, event-runtime 1.2.1, theme 1.0.0). Violates §11 MUST: *"Versions are bumped in the component header and recorded in `docs/releases.md` with the git SHA."* Without a bump, production cannot distinguish the new build and WordPress will not prompt an update. | **BLOCKER** | version table above; `git diff master..i18n` | Maintainer bumps versions + changelog before tagging |
| **B2** | **`conexao-content` activation deletes and recreates both production menus** (`create-pages.php:472` and `:539`). | **BLOCKER for the activation path** | activation audit above | Deploy by **in-place update only**; document the prohibition |
| **B3** | **Polylang is not installed on production.** The whole bilingual layer is gated behind `conexao_polylang_active()`; without it the deployment delivers **none** of the intended functionality and no rollout can run. | **BLOCKER** | §6 | Install + configure Polylang in wp-admin, then re-baseline |
| **B4** | **Backup/restore-point and staging availability unverified** (Business/Commerce tier unknown). | **BLOCKER** | backup section | Confirm tier in the Hosting Dashboard |
| **B5** | **Production plugin set / installed versions unknown** (`/wp-json/wp/v2/plugins` → 401), and production runs a **materially different theme build** than `master`. | **BLOCKER** | §13 | wp-admin inventory before deploy |
| B6 | `./scripts/run-tests.sh` is **red** (11 suites, fixture-caused). Not a production-artifact defect, but the §11 gate must not be silently waived. | High (process) | §12 | Fix fixtures or formally triage |

### Verified safe — not blockers

- **Navigation**: production's PT menu is **already** in the required order. No action.
- **Language switcher**: `CONEXAO_LANGUAGE_SWITCHER_ENABLED = false` is a **file
  constant** (`functions.php:44`), so deploying preserves it; direct EN URLs stay live.
- **data-model / admin-ux / event-runtime activation**: idempotent seeds or flush-only.
- **Theme replacement**: DB-backed theme mods survive file replacement.
- **Artifact integrity**: allowlist, hashes, determinism and exclusion all verified OK.
- **Release machinery**: `verify-release.sh` passed all 7 stages.

## Documentation updated

None — this is an audit; the only additions are this report and its evidence
directory. `docs/releases.md` is intentionally **not** edited: no release was performed.

---

## 17. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate passed, real numbers, no verifier unavailable |
| `PASS WITH LIMITATION` | Complete, but a **required** verification capability was unavailable |
| **`BLOCKED`** | **A required implementation could not be completed safely** |

### Final status: `BLOCKED`

The release machinery itself is proven healthy — `verify-release.sh` is **OK on all 7
stages**, with byte-identical determinism and a clean exclusion proof — but the branch
**cannot be deployed safely today**: Polylang is absent from production so the i18n
functionality would not function (B3), no component version was bumped across
+28,738 changed production lines (B1), `conexao-content` activation is destructive to
production menus (B2), and backup/staging availability and the production plugin set
are both unverified (B4, B5).

Deploying the code alone would be **safe for PT data** — PT content, URLs, slugs and
taxonomy are untouched by an in-place update — but it would deliver **none** of the
intended English capability. The two operations must stay separate, exactly as this
report separates them.

**The correct next step is not a deployment.** It is: bump versions, install and
configure Polylang, prove the backup/restore point, inventory the production plugin
set, then re-run this audit's deployment sequence with the corrected
update-in-place step.

_Reviewed: 2026-09-29 — read-only production deployment safety audit of `i18n` @ `a4cd5f6`_
