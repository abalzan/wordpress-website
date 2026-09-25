# Stage B — Repository Hygiene and Baseline Cleanup

**Stage:** B of the standardisation roadmap (`docs/engineering-standard.md` §15;
audit `docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md` Phase 6).
**Scope:** website repository hygiene only. **No WordPress runtime/behaviour
change** — no plugin, theme, CPT, taxonomy, route, Polylang, REST, CSS, JS,
database, content or production change. No Flutter/mobile change.
**Status:** PASS (with one documented limitation: pack size, §7).

---

## 1. Phase 1 — Baseline (fresh counts from the checkout, not the audit)

| Measure | Value |
|---|---|
| Branch / HEAD | `i18n` @ `7963fca` ("Add WordPress Engineering Standard (WP-ES) document") — the repository has a **single commit**; work for this stage was done on new branch `cline/gxkdheys` |
| `git status --short` | clean |
| Tracked files | **1213** |
| Working-tree size (excl. `.git`) | **64 MB** |
| `.git` size | **34 MB** (pack 32.84 MiB, 1193 objects) |
| `docs/README.md` | **does not exist** (Stage A index deliverable never landed — flagged, not created here) |
| Duplicate engineering audit | **none found** — exactly one audit (`docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md`) and one standard (`docs/engineering-standard.md`) exist |

Tracked generated/local artifacts found at baseline:

| Artifact | Files | Size | Notes |
|---|---|---|---|
| `stage43-work/` | 288 | 3.6 MB | Stage 4.3 production-baseline probe output: 64 `*.body` + 64 `*.meta.json`, `probe.py`, `probe.log`, `lint-all.php`, digests |
| `stage45-work/` | 89 | 5.2 MB | Stage 4.5 raw HTTP matrix HTML captures, scans, caches |
| `stage5-work/` | 83 | 1.1 MB | Stage 5 EN blog translation JSON + manifest builder |
| `stage6-work/` | 25 | 580 KB | Stage 6 job inventories, scans, HTTP caches |
| `stage7-work/` | 65 | 11 MB | Stage 7 leisure inventories + production HTML captures |
| `stage9-work/` | 47 | 388 KB | Stage 9 guide source HTML |
| `.local/` | 2 | **24 MB** | Committed PHP CLI toolchain (`php-cli.tar.gz` 12 MB + `php` 12 MB) |
| `wp-content/plugins/conexao-event-importer/tests/*.log` | 13 | ~5 MB dir | C2 import run/diagnostic logs |
| `stage41-rest-matrix.json` (root) | 1 | 18 KB | Stage 4.1 generated REST matrix (cited as committed evidence) |
| `wp-content/plugins/conexao-admin-ux/wikimedia-image-report.json` | 1 | 12 KB | Generated image-seed report inside a plugin |
| Root `CONEXAO_*.md` reports | 20 | 11 128 lines | Historical stage/fix reports at repository root |
| `scripts/tmp-mp-*.php` | 3 | small | Temporary Mondello import probes, superseded |

`.gitignore` at baseline: 6 entries (`.env`, `dist/`, `scripts/__pycache__/`,
`.agents/skills/`, `.idea/`, `__pycache__/`). `.env.example` at baseline:
a 7-line block duplicated verbatim plus an unlabelled REST credential pair
(audit B-06). No `.gitattributes`. No tracked `.pyc`/`.zip`; no `dist/` on
disk; no `.env` on disk.

## 2. Phase 2 — Cleanup inventory (classification before removal)

### KEEP — source / required (untouched)

All of `wp-content/` (theme + 12 plugins, including plugin `tests/` PHP and
JSON baselines), `scripts/` (except the 3 probes below), `scripts/data/`
fixtures, `docker/`, `content-inventory/`, `docs/` evergreen reference,
`.htaccess`, `compose.yaml`, `AGENTS.md`, `README.md`, `.env.example`
(cleaned, not removed), and `skills-lock.json` (agent-infrastructure lock
file; audit Phase 8 explicitly leaves the Flutter skill library out of scope
for deletion, and this stage touches no Flutter/mobile material).

### MOVE — historical/curated documentation & evidence

| From | To | Why |
|---|---|---|
| 20 × root `CONEXAO_*.md` | `docs/reports/` (filenames preserved) | Audit B-04; standard §9.1. Bare-filename citations across docs/code keep resolving; `docs/reports/README.md` index added. |
| `stage41-rest-matrix.json` | `docs/evidence/stage-4-1-rest-matrix/` | Generated but cited as committed gate proof by the Stage 4.1 report — curated evidence, standard §9.1. |
| `conexao-admin-ux/wikimedia-image-report.json` | `docs/evidence/lazer-wikimedia-image-seed/` | Generated report (audit line 75, D-06); curated copy kept as attribution cross-check evidence; regenerable by the seed scripts. |

### DELETE — disposable generated output

| What | Files | Rationale |
|---|---|---|
| `stage43-work/`, `stage45-work/`, `stage5-work/`, `stage6-work/`, `stage7-work/`, `stage9-work/` | 597 | Audit B-01 raw work product. Curated narratives survive as the stage reports now in `docs/reports/`. Safety-checked: rollout plugins are self-contained (e.g. `conexao-blog-translation/includes/translation-map.php` was generated *into* the plugin); nothing at runtime reads these trees. |
| `.local/php-cli.tar.gz`, `.local/php/php` | 2 | Audit B-02 — 24 MB of committed binaries. Local PHP is documented in `docs/development.md` §Repository Hygiene (`docker compose exec wordpress php`, or `PHP_BIN=` for the scripts that accept it). |
| 13 `*.log` in `conexao-event-importer/tests/` (+ `probe.log` inside `stage43-work`) | 14 | Generated run logs; Stage B exit gate requires no tracked `.log`. Deleting logs is hygiene, not a plugin change. |
| `scripts/tmp-mp-export-inspect.php`, `tmp-mp-state-check.php`, `tmp-mp-verify.php` | 3 | Obsolete temporary probes for the completed Mondello import; referenced only by the audit as "not part of any documented workflow" (B-05). |

### REVIEW — ambiguous, deliberately left untouched

| What | Why left |
|---|---|
| `conexao-event-importer/tests/c2-*.php`, `probe-*.php`, `repair-mi-css-leak.php`, `c2-*.json` baselines | Plugin test-directory internals; removing scripts would be a plugin change. Stage E (test harness) migrates/rationalises these suites. |
| `scripts/debug-heritage-images.php` | Diagnostic, but not in the B-05 removal list and plausibly reusable; per the hard safety rule it stays. |
| `scripts/data/url-verification-report.json` | Generated, but the audit classifies `scripts/data/` as data/fixtures, not generated output. |
| Stage scripts defaulting to removed work-tree paths (`stage45-*`, `stage6-*`, `stage7-*`) | Stage-complete historical tools; relocating/renaming scripts is Stage I. They fail closed (missing input file) if re-run. |
| `docs/README.md` | Absent; a full docs index is a Stage A deliverable. This stage adds the scoped indexes `docs/reports/README.md` and `docs/evidence/README.md` only. |

## 3. Changes made

| File | Change |
|---|---|
| `.gitignore` | Extended to the standard §1.3 list verbatim: `.env`, `.env.local`, `dist/`, `vendor/`, `node_modules/`, `__pycache__/`, `*.pyc`, `phpcs.cache`, `.phpunit.result.cache`, `*-work/`, `*.body`, `*.log`, `.local/`, `.idea/`, `.DS_Store`, `Thumbs.db`, `.agents/skills/` — grouped and commented. |
| `.gitattributes` | **New**, standard §1.2 verbatim: `* text=auto eol=lf`, text marks for `*.php`/`*.json`/`*.md`/`*.yml`/`*.yaml`/`*.sh`/`*.svg`, binary marks for images/`*.mo`/`*.zip`/`*.tar.gz`. |
| `.env.example` | De-duplicated (B-06) and grouped by purpose: Compose variables (matching the `compose.yaml` defaults) vs. REST credentials (`WP_USERNAME`/`WP_APPLICATION_PASSWORD`, used only by the Python REST/HTTP scripts). Placeholders only; no secrets. |
| `AGENTS.md` | Two citations updated to `docs/reports/CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md`. |
| `docs/development.md` | New "Repository Hygiene" section: work-tree/evidence rule + how to run PHP locally now that `.local/php` is gone (B-02 documentation requirement). |
| `docs/reports/README.md` | **New** index of the 20 moved reports + the naming rule for new reports. |
| `docs/evidence/README.md` | **New** — the standing rule for generated work/evidence directories + contents map. |
| `docs/reports/2026-09-25-stage-b-repository-hygiene.md` | This report. |

Moves and deletions exactly as classified in §2 (22 `git mv` renames, 615
files removed). No other file was modified; `wp-content/` source, routing,
REST, Polylang, CSS/JS, `compose.yaml`, `.htaccess` and the database are
untouched.

## 4. Verification (real numbers)

| Check | Before | After |
|---|---|---|
| Tracked files (`git ls-files | wc -l`) | 1213 | **598** (615 deleted, 22 renamed, 0 source files lost) |
| Working tree (excl. `.git`) | 64 MB | **19 MB** |
| Tracked `stage*-work/` files | 597 | **0** |
| Tracked `.local/` files | 2 | **0** |
| Tracked `*.body` / `*.meta.json` | 64 / 64 | **0 / 0** |
| Tracked `*.log` | 14 | **0** |
| Root-level `CONEXAO_*.md` | 20 | **0** (all under `docs/reports/`) |
| Root clutter files | `stage41-rest-matrix.json` + 20 reports | only source/config remains |

Gate commands (audit Stage B exit gate):

```bash
git ls-files | grep -cE '(^|/)[^/]*-work/|^\.local/|\.body$|\.log$'   # → 0  PASS
ls CONEXAO_*.md 2>/dev/null                                            # → none at root  PASS
```

PT-integrity: N/A — no content, database or runtime file was touched;
`git diff --cached -- wp-content/` shows only the two evidence-file renames
out of plugin directories and the 13 log deletions; zero `.php`/`.css`/`.js`
modifications.

## 5. Exit-gate result vs. audit Phase 6 row B

| Gate | Result |
|---|---|
| `.gitignore` covers work dirs, `.local/`, `*.body`/`*.log`, `dist/` | PASS (standard §1.3 verbatim) |
| `.gitattributes` added | PASS |
| Binaries + work trees removed from Git | PASS (615 files) |
| `.env.example` cleanup | PASS |
| Root reports moved to `docs/reports/` | PASS (20/20 + index) |
| `git ls-files` shows no `*-work/`, `.local/`, `.body`/`.log` | PASS |
| Docs still complete | PASS — every citation was a bare filename and still resolves via the preserved names; `docs/reports/README.md` maps locations |
| Clean `git status` | PASS (all changes staged/committed on `cline/gxkdheys`) |
| **Pack < 15 MB** | **NOT MET — see §7 limitation** |

## 6. Rollback

Every deletion is a tree-level change on top of commit `7963fca`, which stays
reachable (branch `i18n` and this branch's history): `git checkout 7963fca --
<path>` restores any removed file. Nothing was force-pushed or rewritten.

## 7. Limitations and follow-ups

- **Pack size (32.84 MiB) is unchanged.** The repository has a single commit,
  so the removed 24 MB of binaries and ~22 MB of work trees remain as
  unreachable-from-HEAD-but-packed blobs in that commit. Meeting the audit's
  "pack < 15 MB" target requires the alternative the audit itself offers —
  **archive history to a bundle** (or squash to a fresh root) — which rewrites
  history. That is a maintainer decision and was deliberately not done in this
  stage (no force-push without an explicit request).
- Stage scripts whose default input paths pointed into the removed work trees
  are historical and non-rerunnable without re-fetching data; Stage I
  (scripts standardisation) owns their relocation.
- Plugin test-directory probes (`c2-*.php` etc.) remain pending Stage E.
- `docs/README.md` (full docs index) remains an open Stage A leftover.

_Last verified: 2026-09-25 by repository hygiene (Stage B)_
