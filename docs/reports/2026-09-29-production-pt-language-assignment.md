# Report — Production PT Language Assignment (`i18n`)

> **BLOCKED at §9. The PT bulk assignment cannot be executed from this repository.**
> **Production writes: 0.** Every request issued was a `GET` or an `OPTIONS`.
> Sections 0–3 of the task are complete and are the reason this is not simply
> "nothing was done": the language contract is re-established from current HEAD,
> the live baseline is captured, and the two Polylang configuration values that
> are genuinely writable are identified as writable. The blocker is a
> **capability** fact, not a permissions problem and not a permissions refusal.

| | |
|---|---|
| **Stage / task name** | Production PT Language Assignment (read-only capability + baseline) |
| **Date** | 2026-09-29 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start / Final SHA** | `33114c6dec671df6d07f097a0bb570454af0a8cd` (unchanged) |
| **Working tree at finish** | only this report + `docs/evidence/2026-09-29-production-pt-language-assignment/`, plus this report's entry in `docs/reports/README.md`. No `wp-content/` change, no source change. |
| **Production writes** | **0** — GET/OPTIONS only |
| **Status** | **BLOCKED — PT LANGUAGE FOUNDATION NOT READY** |

---

## 1. Verdict against the task's own completion criteria (§21)

| # | Required condition | Result |
|---|---|---|
| 1 | required Polylang model configured | ❌ **NOT DONE — not written.** `post_types`/`taxonomies` are `[]` in the stored option |
| 2 | PT corpus correctly assigned to `pt` | ❌ **NOT DONE — impossible from here.** `pt` term count **0** |
| 3 | `?lang=pt` returns the PT corpus | ❌ **FAIL** — `guide?lang=pt` → **0** while unfiltered → **56** |
| 4 | `?lang=en` does not falsely claim PT records | ✅ **PASS** — `?lang=en` → 0 everywhere |
| 5 | `conexao_language` reflects actual assignments | ❌ **FAIL** — reports `pt` by **default-language fallback**, not a real assignment |
| 6 | routing remains correct | ✅ **PASS** — 12/12 resolve, 0 redirects, hreflang + locale correct |
| 7 | PT drift = 0 | ✅ **PASS** — 0 writes issued, so drift is structurally 0 |
| 8 | idempotence = 0 additional mutations | ⚠️ **NOT APPLICABLE** — no assignment run was possible to repeat |
| 9 | no EN content created | ✅ **PASS** — EN term count **0**, EN routes carry no content |
| 10 | no unexpected production mutation | ✅ **PASS** — 0 writes of any kind |
| 11 | required gates pass | ✅ **PASS WITH PRE-EXISTING FAILURES** — identical to baseline, 0 new |

Two conditions (1, 2) and their consequences (3, 5) fail. Per §21 the correct
outcome is **BLOCKED**, and per §18 translation rollout must not begin.

---

## 2. §0–§1 — baseline and the repository language contract

### 2.1 Live baseline (read-only, captured 2026-09-29)

`pll/v1/settings` (verbatim):

```
force_lang 1 · hide_default true · rewrite true · redirect_lang false
browser false · media_support false · domains [] · nav_menus [] · sync []
default_lang "pt" · post_types [] · taxonomies [] · version 3.8.10
```

| Property | Value | Required | Status |
|---|---|---|---|
| language count | **2** | 2 | ✅ |
| `pt` | `Português`, `pt_BR`, `is_default: true`, **term_id 1747**, count **0** | default | ✅ definition / ❌ count |
| `en` | `English`, `en_US`, `is_default: false`, **term_id 1744**, count **0** | secondary | ✅ |
| `force_lang` | `1` | 1 | ✅ |
| `rewrite` | `true` | directory | ✅ |
| `hide_default` | `true` | true | ✅ |
| `browser` | `false` | false | ✅ |
| `redirect_lang` | `false` | false | ✅ |
| `media_support` | `false` | false | ✅ |
| `domains` | `[]` | none | ✅ |
| `nav_menus` | `[]` | preserve | ✅ |
| **`post_types`** | **`[]`** | 6 types | ❌ **FAIL** |
| **`taxonomies`** | **`[]`** | 2 taxonomies | ❌ **FAIL** |

### 2.2 The contract, re-read from current HEAD (not an older report)

From `wp-content/themes/conexao-br-irlanda/inc/i18n/guard.php`:

* `conexao_polylang_translated_post_types()` (`:50-61`) declares
  `guide, event, leisure, sponsor, job, course_provider`.
* `conexao_polylang_translated_taxonomies()` (`:94-105`) declares
  `conexao_category, conexao_tag`; `conexao_county`/`conexao_town` are
  deliberately **shared** (documented at `:56-93`).
* `AGENTS.md:92-94` and `docs/routing.md` agree.

**No contradiction was found.** The task's required list matches the repository
exactly, so §1's "stop and report a contradiction" condition did **not** trigger.
The list was neither expanded nor reduced.

**Important nuance the earlier reports missed.** These six types are declared as
*theme policy* through the `pll_get_post_types` filter and are therefore
**already active at runtime** — which is exactly why `guide?lang=pt` returns
**0** while unfiltered returns **56**: Polylang *is* filtering, there is simply
nothing assigned to filter *for*. The empty `post_types` option is therefore a
**settings-screen display artifact**, not proof that the runtime model is
missing. `guard.php:52-54` deliberately returns the stored list unchanged when
`$is_settings` is true, so the admin screen and the runtime model are *designed*
to differ here.

---

## 3. §3–§4 — why the configuration write was not performed

The task authorises the Polylang configuration write, and it **is** reachable:
`/pll/v1/settings` accepts `POST/PUT/PATCH` with exactly these args —
`browser, default_lang, domains, force_lang, hide_default, media_support,
nav_menus, post_types, redirect_lang, rewrite, sync, taxonomies`.

I deliberately did **not** issue it, for one reason: **a configuration write that
cannot be followed by the assignment it exists to enable leaves production
strictly worse than it is now.** Today `?lang=pt` returns 0 while the front end
renders correctly in Portuguese *because* `force_lang=1` pins the default
language. Writing `post_types`/`taxonomies` without a reachable assignment path
converts a cosmetic settings gap into a state where the declared translation
model is live and the corpus is untagged — the exact "half-applied bilingual
configuration" failure mode the previous report already refused once
(`docs/reports/2026-09-29-i18n-production-foundation.md:28-36`).

Ordering is also explicit in the task itself: §4 requires configuration to be
*proven* before any assignment, and §5–§9 assume the assignment is possible. It
is not. So the honest sequence is to establish that fact first.

---

## 4. §5–§9 — the blocker: PT language assignment is not executable

This is a **capability** fact, established by probing every writable surface on
the live site (evidence `02-capability-probe.json`). Four checks, all negative:

| # | Required mechanism | Result on production |
|---|---|---|
| 1 | a REST route that assigns a language to a post/term | **NONE.** Polylang's entire REST surface is `settings` + `languages`. `POST /pll/v1/languages` **creates a language**; there is no post/term assignment route |
| 2 | a writable `lang` / `language` field on the post types | **NONE.** `OPTIONS` on `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` shows **no** `lang` and **no** `language` arg |
| 3 | the `language` taxonomy exposed over REST | **NO.** `/wp/v2/taxonomies` lists 10 taxonomies, `language` is **absent**; `GET /wp/v2/language` → **404** |
| 4 | the canonical shared engine in production | **NO.** `conexao-translation-rollout` is **not installed** (17 plugins enumerated). It is local-only tooling by registry policy (`production: no`, `build: no`) |

Why the shared engine cannot be used, and why that is correct rather than an
excuse:

* The engine is a **WordPress plugin** whose apply path calls
  `pll_set_post_language()` / Polylang's `PLL()->model->set_language_in_mass()`
  (see `scripts/run-polylang-setup.php:180`, the repository's own canonical
  routine). Both require **in-process PHP**.
* Production has **no CLI, no SSH, no WP-CLI, no shell** (`AGENTS.md`). The only
  PHP that runs there is a plugin loaded by WordPress itself, and the engine is
  not one of them.
* §6 says *reuse the engine, do not build a second lifecycle* — and I did not.
  Building a **bulk Polylang writer outside** the shared architecture is
  forbidden by §6 and would be wrong anyway. Deploying the engine to production
  is not a language-assignment change: it is a plugin deployment, outside the
  authorised write scope.
* `wp-abilities/v1` was checked as a possible code-execution surface: 13
  abilities, all `core/get-*`, `jetpack-forms/*`, `akismet/*`. **None** can
  assign a language or execute arbitrary PHP.

The only remaining path is **direct SQL**, which the "Non-negotiable constraints"
section forbids *unless the repository's canonical engine explicitly requires
it* — and the canonical engine is unavailable, so that exception does not open.

**The repository's own documented answer is an admin-screen action.**
`docs/reports/english-stage43-production-deployment.md:142` and
`docs/reports/CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md:120-123` both specify
Polylang's own wizard action — *"Assign untranslated contents to the default
language"* (`PLL_Model::set_language_in_mass()`) — performed **in wp-admin by a
human**. `AGENTS.md` states the governing rule: *"Production has no CLI.
Anything operated in production must have an admin screen."*

### 4.1 §5 — exact live assignment scope (measured, not from a report)

The task asked to verify the operator's "~2,295" figure. Measured live:

| Post type | Unfiltered | `?lang=pt` | `?lang=en` |
|---|---|---|---|
| `guide` | 56 | **0** | 0 |
| `event` | 1808 | **0** | 0 |
| `leisure` | 289 | **0** | 0 |
| `sponsor` | 10 | **0** | 0 |
| `job` | 1 | **0** | 0 |
| `course_provider` | 11 | **0** | 0 |
| **translated total** | **2175** | **0** | **0** |
| `post` (not translated by contract) | 37 | 0 | 0 |
| `page` (not translated by contract) | 43 | 43 | 43 |

The six contract types total **2175** published records. The operator's "~2,295"
corresponds to the wider figure including `post`/`page` (**2255** unfiltered),
which the repository contract does **not** put in the translated scope — pages
correctly return 43 for both languages because they are untranslated. The
authoritative number for this task is therefore **2175**, not 2295.

### 4.2 §10 — taxonomy scope (measured, unchanged, not written)

| Taxonomy | Terms | Contract | Action taken |
|---|---|---|---|
| `conexao_category` | 58 | translated | **none** |
| `conexao_tag` | 0 | translated | **none** |
| `conexao_county` | 26 | **shared** | **none — correctly untouched** |
| `conexao_town` | 251 | **shared** | **none — correctly untouched** |

The same capability gap applies: with no `language` REST route, term-language
assignment is equally unreachable. The shared-taxonomy guard
(`scripts/remediate-shared-taxonomy-language.php`) is likewise in-process PHP.

### 4.3 §7, §8 — dry-run and snapshot

Not produced, deliberately rather than skipped. §7 requires a dry-run that
*proves* only language state changes, and §8 requires a snapshot *sufficient to
restore language-assignment state*. Both are only meaningful against a real
executable apply path. Emitting a manifest and a snapshot for an operation that
cannot run would produce artefacts that look like rollback safety without being
it — and §8 says plainly: **"Do not claim rollback safety without a usable
snapshot."** I claim none.

---

## 5. §11–§15 — verification of the unchanged live state

### 5.1 Language counts

| Term | ID | Count |
|---|---|---|
| `pt` | 1747 | **0** |
| `en` | 1744 | **0** |

Translation-linked pairs: **0**. EN taxonomy terms created: **0**.

### 5.2 §12 — language filtering (critical acceptance test)

`guide?lang=pt` → 0 while unfiltered → 56. **The failure mode the task named
still exists** and is *not* papered over: Polylang filters correctly, the PT
corpus is simply untagged. No assertion was weakened to accommodate it.

### 5.3 §13 — `conexao_language`

```json
{"lang":"pt","is_fallback":false,"translations":[]}
```

Present on records, but **not proof of assignment**: the record carries no
`language` term (that taxonomy has no REST route at all), the `pt` term count
is 0, and `?lang=pt` returns nothing. Per §13 ("do not accept the REST field
alone as proof"), this **fails** the cross-check.

### 5.4 §14 — route matrix (12/12, zero redirects)

| Path | Status | `<html lang>` | Canonical | hreflang |
|---|---|---|---|---|
| `/` | 200 | `pt-BR` | `https://conexaobr.ie/` | en, pt, x-default |
| `/en/` | 200 | `en-US` | `https://conexaobr.ie/en/` | en, pt-BR, x-default |
| `/guias/` | 200 | `pt-BR` | `/guias/` | pt-BR, x-default |
| `/en/guias/` | 200 | `en-US` | `/en/guias/` | en |
| `/eventos/` | 200 | `pt-BR` | `/eventos/` | pt-BR, x-default |
| `/en/eventos/` | 200 | `en-US` | `/en/eventos/` | en |
| `/lazer/` | 200 | `pt-BR` | `/lazer/` | pt-BR, x-default |
| `/en/lazer/` | 200 | `en-US` | `/en/lazer/` | en |
| `/empregos/` | 200 | `pt-BR` | `/empregos/` | x-default |
| `/en/empregos/` | 200 | `en-US` | `/empregos/` (shared slug) | x-default |
| `/blog/` | 200 | `pt-BR` | `/blog/` | x-default |
| `/en/blog/` | 200 | `en-US` | `/blog/` (B1 shape) | x-default |

**PT routing is correct and no incorrect PT 301 fallback has returned.** EN
routes are *interpreted* as EN (`lang="en-US"`, self-canonical where an EN
target exists) but serve no content — **expected and correct**, since this task
creates no EN records. `/en/empregos/` canonicalising to PT and `/en/blog/`
falling back to PT are the approved shapes (`docs/routing.md:54`, `:336`).

**Honest limitation:** the B2 resolver still has *no correctly tagged PT records*
to work against, so §14's B2 objective is **not** achieved — it is blocked by
the same assignment gap, not by routing.

### 5.5 §15 — locale and hreflang

PT pages emit `pt-BR`, EN routes emit `en-US`, hreflang infrastructure is
active on all 12 rows, canonicals are unchanged, and **no false reciprocal
links** were introduced (this task wrote nothing).

### 5.6 §16 — PT immutability gates

| Gate | Result |
|---|---|
| PT title/content drift | **0** |
| PT slug drift | **0** |
| PT status drift | **0** |
| PT ID set drift | **0** |
| PT taxonomy semantic drift | **0** |
| PT media drift | **0** |
| menu drift | **0** |
| option drift | **0** |
| theme-mod drift | **0** |
| **unintended mutations** | **0** |
| **production writes** | **0** |

---

## 6. §17 — idempotence

**Not applicable, and not claimed.** With no assignment run possible, there is
no second run to prove is a no-op. Calling an unexecuted operation "idempotent"
would be exactly the false claim §17 forbids.

---

## 7. §18 — translation rollout protection

| Check | Result |
|---|---|
| EN records created | **0** |
| translation-linked pairs created | **0** |
| EN taxonomy terms created | **0** |
| `scripts/run-en-translation.php` executed | **no** |
| any bulk EN content stage executed | **no** |
| EN routes serving EN content | **none** |

The task ends at the correct boundary and stops there.

---

## 8. §19 — permanent gates (exact numbers)

| Gate | Command | Result |
|---|---|---|
| Test suite | `./scripts/run-tests.sh` | **62 suites: 52 passed, 10 failed**; **2910 assertions passed, 2 failed**; 1 prerequisite failure — **TESTS FAILED** |
| Script-contract | (same run) | **6 total, 5 passed, 1 failed** |
| HTTP acceptance | (same run) | **3 total, 3 passed, 0 failed** (20 + 44 + 92 = **156 assertions**) |
| Lint / static analysis | `./scripts/lint.sh` | **exit 0** — 472 files syntax-clean, no new PHPCS violations, **PHPStan clean (206 files, 0 errors)** |
| Manifest verification | `release-manifest.py --verify` | **exit 0** — release `v2026.09.29`, artifacts 8, built 8, theme `1.0.1` (115 files) |
| Registry verification | `generate-registry-docs.php --check` | **exit 0** — 14 plugins validated, 23 generated regions current, **zero writes** |
| Permanent release gates | `verify-release-integrity.py` | **210 passed, 0 failed** |
| Script conventions | `verify-script-conventions.py` | **321 passed, 0 failed** |
| Documentation drift | `verify-documentation-drift.py` | **14 passed, 0 failed** |
| Cache-key scoping | `verify-cache-key-scoping.py` | **2 passed, 0 failed** |
| Agent governance | `verify-agent-governance.py` | **PASS** |
| Production verification | REST/route probes above | baseline captured; **0 writes** |

### Newly introduced failures: **0**

The failing set is **identical** to the pre-task baseline recorded in
`docs/evidence/2026-09-29-post-admin-i18n-verification/19-gate-results.md`
(62/52/10 · 2910/2 · 6/5/1 · 3/3). Causes are all pre-existing and
repository-side: retired rollout plugin files absent from the local stack
(`conexao-job-translation`, `conexao-translation-rollout`, and the
`translation-map.php` consumers), plus the stale `.pot` catalogues in
`verify-i18n-freshness.py`. I changed **no** source — `git diff HEAD -- wp-content/
scripts/ tests/` is empty — so no regression is attributable to this task. No
environmental failures.

---

## 9. §20 — Safety summary

| Item | Result |
|---|---|
| PT drift | **0** — PASS |
| unintended mutations | **0** — PASS |
| EN records created | **0** — PASS |
| translation links created | **0** — PASS |
| rollback snapshot verified | **FAIL / NOT CLAIMED** — no apply was possible; no snapshot is claimed |
| `.env` unchanged | **PASS** — read only, never written |
| secrets exposed | **NO** — credentials read from `.env` via HTTP Basic, never printed, logged or written to evidence |
| production writes | **0** |

---

## 10. What unblocks this

One human action in wp-admin, then re-running this same verification:

1. **Polylang → Settings** — confirm the 6 post types and 2 taxonomies are
   checked. Prefer leaving them to the theme filter (`guard.php`) as designed;
   the stored option is display-only (§2.2 above).
2. **Polylang → the language-assignment action** — *"Assign untranslated
   contents to the default language"* (`PLL_Model::set_language_in_mass()`), the
   routine `scripts/run-polylang-setup.php:180` uses and the one
   `docs/reports/english-stage43-production-deployment.md:142` prescribes. This
   is the step no repository script can perform, because it needs in-process
   PHP that only a loaded plugin or a human can provide.
3. **Verify**: `?lang=pt` must return the corpus (guide → 56, event → 1808,
   leisure → 289, sponsor → 10, job → 1, course_provider → 11 = **2175**);
   `?lang=en` must stay 0; the `pt` term count must become 2175; and
   `conexao_language` must then be corroborated by the term count and the
   `?lang=` probes.

Only after that does §12 pass and the EN rollout become eligible. **Do not
begin translation rollout before then** — the B2 resolvers and the engine's
PT-immutability checks all key off the `language` term.

---

## 11. Evidence

`docs/evidence/2026-09-29-production-pt-language-assignment/`

| File | Proves |
|---|---|
| `00-baseline-polylang-raw.json` | raw `pll/v1/settings`, `pll/v1/languages`, `wp/v2/taxonomies` |
| `02-capability-probe.json` | **the decisive artefact** — Polylang route/arg surface, writable fields per post type, `language` taxonomy absence, installed plugins, abilities inventory |
| `01-run-tests.log`, `06-gate-totals.txt` | full gate output and exact totals |
| `03-lint.log` | lint exit 0 |
| `04-registry-check.log`, `04b-release-manifest.log` | registry + manifest exit 0 |
| `05-route-matrix.json` | the 12-row route matrix |

---

## 12. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | GET/OPTIONS only, 0 writes |
| Polylang settings saved | **no** | verified writable, deliberately not written (§3) |
| PT language assigned | **no** | no reachable mechanism (§4) |
| Taxonomy language assigned | **no** | same |
| Plugin activated / deactivated / installed | **no** | |
| Content / menu / option / theme-mod mutation | **no** | |
| Translation creation | **no** | |
| `scripts/run-en-translation.php` | **no** | not run |
| Database synchronization / direct SQL | **no** | |
| `.env` alteration | **no** | read-only |
| Translation rollout | **no** | explicitly out of scope, not started |

---

# STATUS: BLOCKED — PT LANGUAGE FOUNDATION NOT READY

The language contract is re-established from current HEAD and matches the task
exactly: six translated post types, two translated taxonomies, two shared
proper-noun taxonomies. Production is confirmed healthy — two languages with
`pt` as default, every URL-modification switch correct, 12/12 routes resolving
with correct locales and hreflang, and zero redirects. **PT drift is 0 because
this task issued zero writes.**

The blocker is singular and structural: **no mechanism reachable from this
repository can assign a language to a post or a term in production.** Polylang's
REST API manages settings and languages but exposes no assignment route; no
translated post type accepts a writable `lang` field; the `language` taxonomy has
no REST surface; and the shared `conexao-translation-rollout` engine — the
correct home for this work by §6 — is not installed in production and needs
in-process PHP that WordPress.com does not offer. Direct SQL is forbidden, and
building a writer outside the shared engine is forbidden too.

Therefore `post_types`/`taxonomies` were left unwritten as well: enabling the
translation model without a way to satisfy it would trade a cosmetic settings
gap for a genuinely half-configured bilingual site. One documented wp-admin
action — Polylang's own *"Assign untranslated contents to the default
language"*, the same routine `scripts/run-polylang-setup.php` uses — assigns all
**2175** PT records, after which `?lang=pt` will return the corpus and the
repository's own REST contract (`legacy = PT ∪ EN`) will hold.

**Do not begin English translation rollout. The prerequisite is unmet.**

_Last verified: 2026-09-29 by Cline (read-only production capability + baseline)_
