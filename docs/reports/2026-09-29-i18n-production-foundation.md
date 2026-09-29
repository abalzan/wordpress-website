# Report — Production i18n Foundation Deployment Attempt (`i18n`)

> **BLOCKED. Nothing was deployed, nothing was configured, nothing was changed.**
> Every production request issued by this task was a `GET` or an `OPTIONS`.
> **Production writes: 0.** The previous audit's baseline is reproduced exactly.

| | |
|---|---|
| **Stage / task name** | Resolve Production i18n Blockers — deploy theme 1.0.1 + configure Polylang |
| **Date** | 2026-09-29 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start / Final SHA** | `33114c6dec671df6d07f097a0bb570454af0a8cd` (unchanged) |
| **Working tree at finish** | Only this report + `docs/evidence/2026-09-29-i18n-production-foundation/`; no `wp-content/`, no source change |
| **Production writes** | **0** |
| **Status** | **BLOCKED — PRODUCTION i18N FOUNDATION NOT READY** |

**Headline:** The release artifact is built, verified and correct — `conexao-br-irlanda`
**1.0.1**, 126 files, clean exclusions, manifest consistent, `lint`/registry green.
**But the deployment itself is not executable from this repository**, and this is a
capability fact, not a permissions problem: the WordPress REST API exposes **no
writable theme route at all**, and production has no SSH, no WP-CLI and no shell.
Installing a theme on WordPress.com is an `Appearance → Themes → Add New → Upload
Theme` screen operation that only a human at a browser can perform. The repository
says so normatively (`docs/releases.md:9-12`): *"none of the scripts can deploy:
they package, record and verify."*

Because Phase 3 (theme live) could not be executed, Phases 4–9 were correctly
**not** performed. Phase 5 (Polylang configuration) *is* technically reachable over
REST — `pll/v1/settings` and `pll/v1/languages` both accept writes — but executing it
was refused, because the task orders it strictly *after* the `i18n` theme is proven
live. Configuring languages on top of the still-deployed `master` build would ship a
half-applied bilingual configuration across 2,285 PT records with no verified
rollback and no verified recovery point. That is the exact failure mode the task's
sequencing exists to prevent.

---

## The blocker

| Check | Result | Evidence |
|---|---|---|
| Writable theme route exists | **NO** — 328 writable routes enumerated across all 22 namespaces; the only theme routes are `GET`-only | `06-wpjson-index.json`, `09-themes-OPTIONS.json` |
| `OPTIONS /wp/v2/themes` | `{"methods":["GET"]}` | `09-themes-OPTIONS.json` |
| Repository tooling can deploy | **NO** — `build-theme-zip.sh` prints manual wp-admin steps; `docs/releases.md` says no script can deploy | `docs/releases.md:9-12` |
| SSH / WP-CLI / shell on production | **NO** — AGENTS.md: "Production has no CLI" | `AGENTS.md` |
| UpdraftPlus backup verifiable read-only | **NO** — no `updraft*` REST route exists; the backup catalogue is wp-admin only | `06-wpjson-index.json` |
| Polylang settings writable | **YES** (GET/POST/PUT/PATCH) — *not exercised* | `07-pll-settings-OPTIONS.json` |
| Polylang languages writable | **YES** (GET/POST) — *not exercised* | `08-pll-languages-OPTIONS.json` |

---

## Phase 1 — Release identity and artifact gates ✅ PASS

| Gate | Expected | Actual | Result |
|---|---|---|---|
| Branch | `i18n` | `i18n` | ✅ |
| HEAD | intended | `33114c6dec671df6d07f097a0bb570454af0a8cd` | ✅ |
| Working tree clean except report/evidence | yes | `M docs/reports/README.md`, 2 untracked report/evidence paths — audit artefacts only, no `wp-content/` | ✅ |
| Theme version | **1.0.1** | **1.0.1** in `style.css` **and committed at HEAD** (`git diff HEAD` empty) | ✅ |
| Manifest internally consistent | yes | `release-manifest.py --verify` → **exit 0**, 8 artifacts, 8 built | ✅ |
| Artifact exclusions | correct | 0 `.env`, 0 `/tests/`, 0 fixtures, 0 `node_modules`, 0 `.git/` in the ZIP | ✅ |
| Build | canonical path | `./scripts/build-theme-zip.sh` → **exit 0**, `dist/conexao-br-irlanda.zip`, 5,894,438 bytes, **126 files** | ✅ |
| i18n runtime present in artifact | yes | `inc/i18n/` ✅, `inc/rest-language.php` ✅ | ✅ |
| `lint.sh` | 0 | **exit 0** — syntax clean, no new PHPCS violations, PHPStan clean | ✅ |
| Registry drift gate | 0 | `php scripts/generate-registry-docs.php --check` → **exit 0**, 14 plugins, 23 regions, zero writes | ✅ |

> `dist/release.json` records `dirty: true` and base SHA `a4cd5f6` (an ancestor of
> HEAD). This is the documented, intentional consequence of the version bump being
> committed in `33114c6` *after* the artifacts were built — the same state the
> release-identity report already recorded. The manifest and the registry agree, so
> this is not drift.

---

## Phase 2 — Production backup verification ⛔ NOT POSSIBLE

Phase 2 requires proving a recovery point exists, is recent, has the right
coverage, and is restorable. All four are **wp-admin-screen facts**. Enumerating
every route for `updraft`/`backup` returns no UpdraftPlus route at all. The Sunday
backup therefore remains **unverified**: I cannot confirm it exists, its timestamp,
its database/files coverage, or its restorability.

The task's own rule — "Do not restore anything" and "production recovery safety
cannot be established" ⇒ BLOCKED — makes this an independent hard blocker, separate
from the theme-upload blocker.

---

## Phase 0 — Pre-change baseline (read-only, preserved as evidence)

| Item | Value |
|---|---|
| Active theme | `conexao-br-irlanda` **1.0.0** (`master` build) |
| Other themes | `assembler` 0.0.124 (inactive), `twentytwentytwo` 2.1-wpcom (inactive) |
| Plugins | **18** total; Polylang **3.8.10 active**; UpdraftPlus 1.26.8 active |
| `default_lang` | `""` (empty) |
| Polylang languages | **0** (`[]`) |
| translated post types / taxonomies | `[]` / `[]` |
| `force_lang` / `hide_default` / `rewrite` | `1` / `true` / `true` |
| `redirect_lang` / `browser` / `media_support` | `false` / `false` / `false` |
| `nav_menus` | `[]` |
| `page_on_front` / `page_for_posts` | `9` / `10` |
| `language` taxonomy | **absent** (10 taxonomies) |
| `conexao_language` in REST | **absent** |

**Content (PT):** posts 37 · pages 43 · guide 56 · event 1808 · leisure 289 ·
sponsor 10 · job 1 · course_provider 11. Taxonomies: `conexao_category` 58 ·
`conexao_tag` 0 · `conexao_county` 26 · `conexao_town` 251.

**Routes:** `/` 200 · `/guias/` 200 · `/eventos/` 200 · `/lazer/` 200 ·
`/empregos/` 200 · `/blog/` 200 · `/en/` **301 → an unrelated PT event** ·
`/en/guias/` **404** · `/en/eventos/` **404** · `/en/lazer/` **301 → `/lazer/`** ·
`/en/empregos/` **301 → `/empregos/`** · `/en/blog/` **301 → `/blog/`**.

**`<html lang="en-US">` on all 6 PT pages; hreflang count 0 on all 6.**
`?lang=` ignored: `guide` returns 56 unfiltered, and 56 for both `?lang=pt` and
`?lang=en`.

**Theme fingerprint (positive discrimination — reproduces the audit exactly):**

| File | prod | prod bytes | prod md5 | i18n HEAD md5 | match |
|---|---|---|---|---|---|
| `assets/css/main.css` | 200 | 192920 | `a4bab9a49b7dd0bdcce665f2380ce423` | `a367b7950e0511958a3768d284a4d35b` | **NO** |
| `inc/i18n/guard.php` | **404** | — | — | `0f982c38686ed287185f37ed8f00d1fe` | **NO** |
| `inc/rest-language.php` | **404** | — | — | `0b1f05d24cca8b4da4914a304aa86b71` | **NO** |

Production is byte-identical to `master`, not `i18n`. The previous audit's blocker
B2 is **confirmed still open**.

---

## Phases 3–10 — NOT EXECUTED

Phase 3 could not be executed (no writable theme route). Every later phase is
gated on it, so none was performed. Specifically: **no theme upload, no theme
activation, no Polylang language created, no Polylang setting written, no
translated post type/taxonomy configured, no `conexao-content` touch, no menu
change, no front-page change, no media change.** Phase 10 (translation rollout) was
**not** started — `scripts/run-en-translation.php` was not run and no EN record,
page or taxonomy translation was created.

---

## Phase 11 — Permanent gates (exact results)

| Gate | Command | Exit | Result |
|---|---|---|---|
| Static quality | `./scripts/lint.sh` | **0** | ✅ syntax clean · no new PHPCS violations · PHPStan level 5 clean |
| Registry drift | `php scripts/generate-registry-docs.php --check` | **0** | ✅ 14 plugins, 23 regions, zero writes |
| Release integrity / manifest | `python3 scripts/release-manifest.py --verify` | **0** | ✅ 8 artifacts, 8 built, hashes match |
| In-process PHP suites | `./scripts/run-tests.sh` | **1** | ⚠️ 62 total, **52 passed, 10 failed**; **2910 assertions passed, 2 failed** |
| Script-contract suites | same run | — | 6 total, **5 passed, 1 failed** (`verify-i18n-freshness`: 2 stale `.pot`) |
| HTTP acceptance | same run | — | 3 total, **3 passed, 0 failed** |
| Build | `./scripts/build-theme-zip.sh` | **0** | ✅ theme 1.0.1, 126 files, exclusions clean |
| Production verification | `scripts/verify-deploy.py` | **not run** | ⛔ requires a deployment that did not occur |

**No assertion was weakened and no gate was converted to PASS.** The 10 failing PHP
suites and the 1 failing script-contract suite are the **pre-existing** set recorded
in `docs/reports/2026-09-29-release-identity.md` (62/52/10, 2910/2) — identical. They
stem from retired-rollout plugins whose files are absent
(`conexao-page-translation/includes/translation-map.php`) and from `.pot` files
older than their sources. **Newly introduced failures: 0.**

---

## Phase 12 — Regression evidence (PRE vs POST)

| Dimension | PRE | POST | Delta |
|---|---|---|---|
| Theme version | 1.0.0 (master) | 1.0.0 (master) | **0** |
| Theme fingerprint | `a4bab9a…` | `a4bab9a…` | **0** |
| Plugin inventory | 18 | 18 | **0** |
| Polylang version | 3.8.10 active | 3.8.10 active | **0** |
| Polylang languages | 0 | 0 | **0** |
| `default_lang` | `""` | `""` | **0** |
| Translated post types / taxonomies | `[]` / `[]` | `[]` / `[]` | **0** |
| PT records | 2255 | 2255 | **0** |
| EN records | 0 | 0 | **0** |
| Translation-linked records | 0 | 0 | **0** |
| Route statuses | 200/404/301 matrix above | identical | **0** |
| `<html lang>` | `en-US` ×6 | `en-US` ×6 | **0** |
| hreflang | 0 | 0 | **0** |
| `conexao_language` in REST | absent | absent | **0** |
| PT content / slug / title byte-drift | — | — | **0** |
| Unexpected taxonomy / menu / option / theme-mod mutations | — | — | **0** |
| Media changes | — | — | **0** |
| **Production writes** | 0 | **0** | **0** |

**Production data was fully preserved. PT drift = 0. No unexpected mutation of any
kind occurred.**

---

## What a human must do to unblock

1. **Verify the recovery point** in wp-admin → UpdraftPlus: confirm the Sunday
   backup exists, check its timestamp, and confirm database + files coverage.
2. **Upload the theme**: Appearance → Themes → Add New → Upload Theme →
   `dist/conexao-br-irlanda.zip` (version 1.0.1; hash recorded in
   `dist/release.json`) → Activate.
3. **Verify the theme is live** with the positive fingerprint: `main.css` must
   become `a367b7950e0511958a3768d284a4d35b` (196,327 bytes), and
   `inc/i18n/guard.php` + `inc/rest-language.php` must stop returning 404.
4. **Only then** configure Polylang (PT `pt` / `pt-BR` as default first, then EN
   `en`), keeping `force_lang=1`, `hide_default=on`, `rewrite=on`, `browser=off`,
   `redirect_lang=off`, `media_support=off`, and preserving `nav_menus=[]`.
5. Then re-run this verification, and only then consider translation rollout.

The pre-built, verified artifact is ready at `dist/conexao-br-irlanda.zip`.

---

## Completion status against the task's own checklist

| # | Requirement | Status |
|---|---|---|
| 1 | `conexao-br-irlanda 1.0.1` positively verified in production | ❌ **artifact ready, not deployed** — no writable theme route |
| 2 | Polylang configured PT default + EN secondary | ❌ not configured (correctly gated on #1) |
| 3 | Directory-based `/en/` routing active | ❌ not active |
| 4 | Browser detection remains off | ✅ already off, unchanged |
| 5 | Polylang language redirect remains off | ✅ already off, unchanged |
| 6 | Translated post types/taxonomies configured | ❌ not configured |
| 7 | `conexao_language` present in REST | ❌ absent (master build) |
| 8 | PT content byte-identical, drift 0 | ✅ **drift 0** |
| 9 | No unintended menus/options/theme mods changed | ✅ **0 changes** |
| 10 | Mandatory gates have numeric evidence | ✅ recorded in Phase 11 |
| 11 | No translation rollout performed | ✅ **none performed** |
| 12 | Final production evidence report written | ✅ this report + `docs/evidence/2026-09-29-i18n-production-foundation/` |
| 13 | Working tree and release state documented | ✅ see Phase 1 note; only report/evidence files added |
| 14 | Flutter/mobile repository not accessed | ✅ never accessed, never referenced |

**Items 1, 2, 3, 6 and 7 are unmet and are unmeetable from this repository.**
Items 4, 5, 8, 9, 10, 11, 12, 13, 14 are satisfied.

---

# STATUS: BLOCKED — PRODUCTION i18N FOUNDATION NOT READY

**Do not proceed to translation rollout.** Two independent blockers, either of
which alone is sufficient:

1. **Theme deployment is impossible from this repository** — no writable theme
   route exists in the WordPress REST API, and production has no CLI, SSH or
   shell. Requires a human at wp-admin.
2. **The recovery point is unverified** — the UpdraftPlus backup catalogue is
   admin-screen-only and cannot be inspected read-only, so "production recovery
   safety" cannot be established.

No production state was altered. The artifact is built, verified and staged, so
the remaining work is a short human admin sequence followed by this same
verification.

