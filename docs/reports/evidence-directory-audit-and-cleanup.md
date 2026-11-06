# Report — `docs/evidence/` directory audit and cleanup

| | |
|---|---|
| **Stage / task name** | Evidence directory audit and cleanup |
| **Date** | 2026-11-06 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `c26bdb6637bd0ec882d094d12e3cbfaff50440f4` |
| **Final SHA** | unchanged — **no commit, no push** |
| **Working tree at finish** | dirty, by design (see §12) |
| **Skills followed** | `wp-update-docs`, with `wp-repository` for the plan/build/verify/report loop |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§9, §12, §13.2), `docs/README.md`, `docs/reports/README.md`, `docs/evidence/README.md`, `docs/testing.md`, `docs/templates/report.md`, `.agents/skills/wp-update-docs/SKILL.md` |

> Every number below came from a command that ran in this repository. The
> machine output is in
> [`docs/evidence/2026-11-06-evidence-directory-audit/`](../evidence/2026-11-06-evidence-directory-audit/).
> No production system was contacted. Nothing was committed or pushed.

## 1. Scope completed

1. **Preflight.** Read `AGENTS.md`, the engineering standard (§9 documentation,
   §12 Git, §13.2 agent rules), `docs/README.md`, `docs/reports/README.md`,
   `docs/evidence/README.md`, `docs/testing.md`, the report template and the
   `wp-update-docs` skill. Recorded branch, HEAD, worktree, staged/unstaged,
   untracked and stash state (§12).
2. **Complete inventory.** Every one of the 1,445 files was read by path and
   hashed (SHA-256) — not by filename, not from the GitHub listing:
   140 top-level folders, 156 directories including nested ones, 121.71 MiB.
   Per-file rows: `01-inventory-pre.tsv` (path, bytes, SHA-256, tracked flag).
3. **Reference / dependency analysis.** Every evidence folder name was searched
   against `AGENTS.md`, `README.md`, `docs/`, `scripts/`, `tests/`, `.github/`
   and `plugins.json` (669 files scanned), and against the other evidence
   folders. Results: `03-folder-reference-matrix.tsv`.
4. **Duplicate and redundancy analysis.** 64 byte-identical groups found
   (152 files, 4.48 MiB of duplicated bytes); each group classified by *what
   the equality means*. Results: `02-duplicate-groups.txt`.
5. **Classification** of every folder and file into A–E (§12).
6. **Cleanup executed** — 1 file removed, 8 empty directories removed (§13).
7. **Documentation improved** — `docs/evidence/README.md` rewritten from a
   partial index (29 of 140 folders described) into a complete index plus the
   retention, commit-prohibition and add-evidence rules (§19).
8. **Verification** — script-contract suite, registry drift, documentation
   drift, agent governance, and a link/reference re-check after the edits (§15).

## 2. Scope NOT completed

- **The directory is not materially smaller.** It is 219.2 KiB *larger* than
  before: the only genuinely removable artefacts were a 469-byte `.bak` and
  eight empty directories, while the audit itself added 224.4 KiB of machine
  evidence and a rewritten index (§13.4). Every other candidate was rejected
  with a written reason, not deferred silently. This is the finding, not an
  omission — §13.
- **No bulk consolidation was performed** on the 4.04 MiB of cross-folder
  duplicates. In every case the duplication *is* the proof (idempotence,
  pre-apply recheck, cross-stage reproduction) — §11.3.
- **No untracked file was deleted.** All 230 untracked files are operator work
  in progress (the Stage 9 migration record and the proximity-button-removal
  record) or git-ignored residue. Deleting another operator's uncommitted
  evidence is out of scope by construction — §16.
- **The full in-process PHP / HTTP acceptance layers were not run.** They need
  the Docker WordPress; the task named `./scripts/run-tests.sh --scripts`, and
  this change touches no PHP, no route and no test — §11, §15.

## 3. Files added / modified / deleted

**6 added, 3 modified, 1 deleted** (plus 8 empty directories removed, which Git
does not track).

| Path | Change | Note |
|---|---|---|
| `docs/evidence/2026-11-06-evidence-directory-audit/01-inventory-pre.tsv` | added | 1,445-row pre-state inventory (path, bytes, SHA-256, tracked) |
| `docs/evidence/2026-11-06-evidence-directory-audit/02-duplicate-groups.txt` | added | the 64 byte-identical groups, with hashes and paths |
| `docs/evidence/2026-11-06-evidence-directory-audit/03-folder-reference-matrix.tsv` | added | per-folder file count, size, tracked count, report references |
| `docs/evidence/2026-11-06-evidence-directory-audit/04-empty-directories-pre.txt` | added | the 8 empty directories found and removed |
| `docs/evidence/2026-11-06-evidence-directory-audit/05-verification.txt` | added | post-cleanup tree state and the gate outputs |
| `docs/evidence/README.md` | modified | complete folder index, retention policy, commit prohibitions, add-evidence procedure; stale "Current contents" table relabelled "Selected records" |
| `docs/reports/README.md` | modified | this report added to the index |
| `docs/README.md` | modified | `_Last verified_` line for the evidence rule change |
| `docs/evidence/2026-10-19-ui-ux-improvement-5/implementation-report.md.bak` | **deleted** | byte-identical backup of the `.md` beside it; referenced nowhere |

Empty directories removed (untracked, zero files, no repository effect):
`2026-10-12-empregos-cta-dark`, `2026-10-17-eventos-filter-cache-completion`,
`2026-10-19-event-date-badge/final`, `2026-10-21-stage-e`,
`2026-10-21-ui-ux-image-ratio`, `2026-10-21-ui-ux-image-ratio-verification`,
`2026-10-21-ui-ux-improvement-9`, `2026-10-24-ai-instructions-audit`.

**No file under `wp-content/`, `scripts/`, `tests/` or `.github/` was touched.**
No stage report, plan, release artefact or migration script was edited.

## 4. Runtime impact

**None.** The change is documentation and evidence only: `git status --short`
shows no path under `wp-content/`, `scripts/`, `tests/`, `docker/` or
`.github/`, and no PHP, JS, CSS or SQL was modified. WordPress behaves
identically; there is no route, template, query, meta or Polylang effect.

### 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** — no network call of any kind was made |
| No production content, Polylang or database change | **confirmed** — no database was connected |
| No application behaviour change (`wp-content/` diff empty) | **confirmed** |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** — `.env` was never opened |

## 5. Content / data impact

**None.** No content record, meta value, term or Polylang relationship was
touched: this task ran no importer, no rollout and no migration, and made no
network request.

## 6. Polylang impact

**None.** No PT or EN record, slug, language assignment or translation link
appears in any changed file. The PT-unchanged and EN-completeness gates were
not exercised because nothing they measure was touched; "PT content is
immutable" holds trivially — no data change occurred.

## 7. Route / HTTP impact

**None.** `docs/routing.md` was not modified and no route, rewrite, redirect,
canonical, hreflang or sitemap behaviour changed.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database).
Nothing in this task requires a production capability, and none was used.

## 9. Inventory (pre-state)

### 9.1 Method

Every file was *read* and hashed — not inferred from a filename or from the
GitHub directory listing:

```bash
python3 - <<'PY'   # → docs/evidence/2026-11-06-evidence-directory-audit/01-inventory-pre.tsv
import os, hashlib, subprocess
tracked = set(subprocess.run(['git','ls-files','docs/evidence'],
                             capture_output=True, text=True).stdout.splitlines())
for dirpath, dirnames, filenames in os.walk('docs/evidence'):
    for fn in sorted(filenames):
        p = os.path.join(dirpath, fn)
        data = open(p, 'rb').read()
        print(os.path.relpath(p, 'docs/evidence'), len(data),
              hashlib.sha256(data).hexdigest(), int(p in tracked), sep='\t')
PY
find docs/evidence -type d | wc -l          # 156
find docs/evidence -type f | wc -l          # 1445
git ls-files docs/evidence | wc -l          # 1215
```

### 9.2 Counts

| Measure | Pre | Post |
|---|---|---|
| Top-level folders | 140 | 134 |
| Directories (incl. nested) | 156 | 149 |
| Files | 1,445 | 1,449 |
| Bytes | 127,622,840 (121.71 MiB) | 127,847,261 (121.92 MiB) |
| `du -sh` | 126M | 126M |
| Tracked files | 1,215 | 1,214 (the Git index still lists the deleted `.bak` until the change is committed) |
| Untracked files | 230 | 235 |
| …of which git-ignored residue (`.log`, `.pyc`) | 160 | 160 |
| Empty directories | 8 | 0 |
| `git stash list` entries | 9 | 9 |

The 230 untracked files break down as **160 git-ignored** (158 `*.log`, 2
`*.pyc`) plus **70 real new files** — 48 in
`2026-11-05-leisure-location-stage-9/` and 22 in
`2026-11-05-lazer-proximity-button-removal/` — the uncommitted record of the
two most recent pieces of work. Post-figures include the 5 audit files of §3.

### 9.3 File-type distribution (pre)

| Type | Files | KiB | Type | Files | KiB |
|---|---|---|---|---|---|
| `.txt` | 742 | 17,842.2 | `.php` | 34 | 112.9 |
| `.json` | 242 | 62,472.6 | `.tsv` | 9 | 4.6 |
| `.log` (never tracked) | 158 | 1,298.6 | `.csv` | 8 | 75.9 |
| `.png` | 76 | 21,718.2 | `.sh` | 7 | 19.7 |
| `.md` | 62 | 455.2 | `.sql` | 6 | 16,028.1 |
| `.html` | 41 | 2,999.0 | `.out` | 5 | 46.7 |
| `.py` | 40 | 562.3 | other (8 names: `.css`, `.before`, `.after`, `.patch`, `.pyc`, `.mjs`, `.js`, `.bak`) | 15 | 995.7 |

`*.log` and `*.pyc` are **never tracked** (0 of 158 and 0 of 2): local run
residue that the root `.gitignore` rule `*.log` has always excluded.

### 9.4 Where the weight actually is

Ten folders hold 95.4 MiB of the 121.7 MiB (78%):

| Folder | MiB | What the weight is |
|---|---|---|
| `2026-09-30-pt-trash-restoration` | 16.2 | three 6.7 MB classified EN manifests + two 480 KB mutation snapshots ×2 |
| `2026-10-16-event-cleanup-purge` | 15.3 | **one** file: the pre-purge local database dump |
| `2026-09-30-ci-regression-fix` | 14.1 | four full GitHub Actions job logs (3.7 MB ×3 + 2.9 MB) |
| `2026-10-19-event-date-badge` | 10.1 | 45 viewport/themed PNG screenshots |
| `2026-09-30-phase-3-translation-verification` | 8.2 | 6.8 MB EN manifest + snapshots |
| `2026-09-30-en-rollout-blocker-resolution` | 8.2 | 6.6 MB EN manifest + 432-object snapshot |
| `2026-09-30-en-deleted-source-exclusion` | 8.0 | manifests and snapshots |
| `2026-09-30-en-translation-dry-run` | 6.1 | 5.3 MB production revalidation |
| `2026-10-21-homepage-shortcut-consolidation` | 4.2 | four full-page PNGs at two viewports |
| `2026-09-30-en-translation-inventory` | 2.7 | manifests |

Each is a **single** artefact that is the only record of the state it
captures. There is no "many small redundant files" problem at the top of the
distribution — which is why §13 concludes the directory cannot be materially
shrunk without destroying provenance.


## 10. Reference and dependency analysis

### 10.1 Method

Every one of the 140 folder names was searched as a substring across
`AGENTS.md`, `README.md`, `docs/**` (excluding `docs/evidence/`), `scripts/**`,
`tests/**`, `.github/**` and `plugins.json` — 669 files — and separately against
`docs/evidence/README.md` as it stood at `HEAD`
(`git show HEAD:docs/evidence/README.md`). Per-folder file counts, sizes and
reference counts: `03-folder-reference-matrix.tsv`.

### 10.2 Result — how each folder is reachable

| How the folder is named | Folders (of 140) |
|---|---|
| In at least one `docs/reports/*.md` | **130** |
| Only from `AGENTS.md` (`2026-09-26-stage-p`) | 1 |
| Only from the pre-change `docs/evidence/README.md` (`2026-09-26-stage-k`, `…-stage-o`) | 2 |
| **Named nowhere outside `docs/evidence/`** | **7** |

The seven, and why each is still evidence:

| Folder | Why it is kept |
|---|---|
| `2026-09-10-retire-eventbrite-windows` | three `*.log` captures, all untracked/ignored — local residue, not part of the tracked record |
| `2026-09-26-stage-m` | **contains its own report and plan** (Permanent Invariant Debt Remediation) plus `gate.json`, dry-runs and negative proofs — Class A |
| `2026-10-05-enable-all-event-sources` | self-documenting bundle with its own `README.md`, dry-run + apply JSON and verification |
| `2026-10-12-eventos-stale-results` | one focused-query proof |
| `2026-10-19-cursos-infinite-scroll` | one browser-acceptance log (untracked) |
| `2026-10-21-ui-ux-improvement-6-dead-end` | **its own `report.md`** recording a deliberately abandoned approach — the only record that it was tried and why it stopped |
| `2026-11-04-lazer-proximity-release` | release-preparation record for the proximity feature |

A folder is **not** orphaned because nothing links to it. Five of the seven are
self-documenting and one is the only written record of a negative decision.

### 10.3 Hard dependencies — code that reads or writes this directory

| Referencing file | What it does | Consequence |
|---|---|---|
| `.github/workflows/ci.yml:568` | uploads `docs/evidence/2026-09-26-stage-l/gate.json` as the integration job's evidence artifact (`if: always()`, `if-no-files-found: warn`) | **`gate.json` can never be removed** — the CI artifact of record would silently empty |
| `scripts/verify-permanent-gates.py` | documents `docs/evidence/2026-09-26-stage-l/gate.json` as the aggregate's output path | same file |
| `tests/experiments/g16-activate-sources.php:220`, `g16-controlled-import.php:476`, `g16-source-eligibility.php:342` | **write** into `docs/evidence/2026-10-05-eventbrite-g16/` | that folder is a live output target, not only a record |
| `tests/scripts/verify-eventbrite-token-retirement.py:65` | treats `docs/evidence`, `docs/reports`, `docs/importers` as `HISTORICAL_DIRS`, exempt from the "do not instruct a token" scan | nothing to update — the whole directory is classified |
| `tests/scripts/verify-script-conventions.py:280` | same: historical records are exempt from the current-catalogue rules | nothing to update |
| `scripts/repair-event-town-terms.php`, `scripts/repair-event-county-terms.php`, `scripts/data/ci-fixture-guides.php` | cite an evidence folder in a provenance comment (`2026-09-26-stage-p`, `2026-09-26-stage7`, `2026-09-30-en-rollout-blocker-resolution`) | those folders must not move; a rename would leave the comments dangling |
| `scripts/README.md`, `AGENTS.md` | cite `2026-09-26-stage-i`, `2026-09-29-stage-p` | same |

`tests/scripts/verify-documentation-drift.py` enforces the §9 rules but does
**not** validate evidence links, so no governance check breaks when an evidence
file is removed. That is a real gap, and it is why §15's link-checking was done
by hand rather than delegated to CI.

### 10.4 References *inside* the evidence directory

Evidence files cite sibling evidence freely (each stage's `README.md` names its
own numbered files; several pre-apply records cite the Stage 8 folder as
"unchanged"). Neither removal in §13 leaves a dangling reference: the deleted
`.bak` is referenced by nothing, and the removed directories contained no files.

## 11. Duplicate and redundancy analysis

### 11.1 Method and headline

SHA-256 over every file (1,445), grouped by digest, then each group classified by
**what the byte-equality means**, not by filename. Full list:
`02-duplicate-groups.txt`.

**64 byte-identical groups, 152 files, 4,694,109 bytes (4.48 MiB)** would be
reclaimed if one copy of each group were kept.

### 11.2 Classification of the 64 groups

| What the equality means | Groups | Reclaimable | Verdict |
|---|---|---|---|
| **Same-folder `*-before`/`*-after` or `runN` repeat** — the equality *is* the result (PT unchanged, engine unmodified, rendering unchanged, deterministic run) | 22 | 0.30 MiB | **Keep** — Class A/B |
| **Cross-folder copy of one output** — one artefact captured at one moment and cited by two stages | 21 | 4.04 MiB | **Keep** — the copy is what makes it evidence for *that* stage |
| **Duplicated tool script** — the same verifier committed into two bundles | 14 | 0.12 MiB | Consolidation candidate (D), **not actioned** — §13.3 |
| **Git-ignored residue** — every member is `*.log`/`*.pyc`, untracked | 6 | 0.01 MiB | Not part of the tracked record — §16 |
| **Backup copy** — `implementation-report.md.bak` identical to the `.md` beside it | 1 | 469 B | **Deleted** — §13.1 |

### 11.3 The four large groups, and why they stay

| Digest (prefix) | Members | Bytes | Why the duplication is the finding |
|---|---|---|---|
| `734c7079…` | `en-deleted-source-exclusion/03-repository-stage-manifests.json`, `pt-trash-restoration/08-…`, `pt-trash-restoration/12-…-run2` | 621 KB ×3 | the repository stage manifests were **unchanged** between the source-exclusion work and both trash-restoration runs — deleting `run2` deletes the proof that re-running changed nothing |
| `82d66b41…` | `en-rollout-phase-3-apply/02-`, `…/10-…-phase3r2`, `pt-trash-restoration/11-`, `…/15-…-run2` (mutation snapshots) | 480 KB ×4 | the same 432-object mutation snapshot at four points; the equality is the idempotence result |
| `83de2f1c…` | Stage 8 `dryrun-02` + Stage 9 `dryrun-02` / `03-repeat` / `04-preapply-recheck` | 143 KB ×4 | Stage 9 re-ran Stage 8's dry-run immediately before applying and got **byte-identical** output — the strongest single safety proof in the migration record |
| `58ca84e7…` | `en-deleted-source-exclusion/07-mutation-snapshot.json`, `en-rollout-blocker-resolution/03-mutation-snapshot.json` | 474 KB ×2 | two stages observed the same unchanged snapshot |

Each member is Class A/B. Replacing one with a checksum pointer would destroy
the artefact and substitute a *claim about* it — precisely what evidence exists
to prevent.

### 11.4 Near-duplicates investigated and rejected

The 76 groups of files that share a byte length but **not** a digest (≥1,000 B)
were checked. The largest —
`pt-trash-restoration/09-en-inventory-manifest.json` vs
`13-en-inventory-manifest-run2.json`, both 6,984,364 B — are **not** identical:
different digests, because the second run observed a genuinely different
inventory state. They are two measurements, not a copy. Same for the
`verification-pre` / `verification-post` and `production-inventory-pre` / `post`
pairs in the Stage 7/8/9 folders. No near-duplicate was treated as a duplicate.

## 12. Classification

Folders with files: **133** (the other 7 of the 140 were empty and are outside
the classification — §13.2). File-level verdicts follow from the folder verdict
plus §11.

| Class | Meaning | Folders | Retention |
|---|---|---|---|
| **A** | Only record of a production action or a production decision — inventories, pre/post snapshots, approval checkpoints, write results, independent reconciliation, permission/security proofs, release and deploy verification, proofs that a destructive operation did not happen | **37** | **Mandatory — never delete** |
| **B** | Supporting proof of a claim — test output, CI output, gate aggregates, regression comparisons, failure proofs, deployment verification | **91** | **Default — keep** |
| **C** | Regenerable intermediate output with no independent historical value | **2** | Cleanup candidates; **not actioned** (§12.2) |
| **D** | Byte-identical duplicate of a file still present | file-level | **1 deleted**; 14 tool-script groups retained (§13.3) |
| **E** | Purpose or significance not established | **3** | **Kept** (§12.3) |

### 12.1 Class A, by project — the records this audit protects

**The leisure-location production sequence (2026-11-05)** — the whole chain,
each link independently cited by its own report:

| Folder | What it is the only record of |
|---|---|
| `leisure-location-production-audit` | production holds **0/289** coordinates; the blocking `auth_callback` finding (`403 rest_cannot_update`); the 58-assertion discriminating mock |
| `leisure-coordinate-rest-permission` | the `current_user_can('edit_post', $object_id)` fix (1.6.2 → 1.6.3), its 74-assertion discriminator (45/29 with the fix reverted), and the pre-existing PHPCS drift proof |
| `leisure-location-stage-7` | production permission verification plus the **authorised single-record write and its restore** (leisure 10551) |
| `leisure-location-stage-8` | the read-only dry-run: `populate=289, written=0`, zero write requests in a 58/58 mock, identical before/after sweep |
| `leisure-location-stage-9` | **the applied migration**: approval checkpoint, pre-apply recheck, 289 coordinate + 289 attribute writes, 14/14 independent reconciliation, failure proofs, disclosed limitations |

**The PT trash-restoration / EN source-exclusion sequence (2026-09-30)** —
`pt-trash-restoration`, `en-deleted-source-exclusion`,
`en-rollout-blocker-resolution`, `en-rollout-phase-3-apply`,
`en-translation-inventory`, `en-translation-dry-run`,
`en-pt-source-reconciliation`.

**The automation commissioning gates (2026-09-30 → 2026-10-01)** —
`stage-5-production-commissioning-canary` and `stage-6` … `stage-15`
(11 folders): the record of every decision *not* to act, or of the exact
precondition that blocked it.

**The destructive-operation record** — `2026-10-15/16-event-cleanup-purge`,
including the only pre-purge database snapshot.

**Stage L** (`2026-09-26-stage-l`) — `gate.json` is the CI artifact of record.

**Release / deploy** — `2026-09-26-stage-j`, `2026-09-29-release-identity`,
`2026-09-29-production-deployment-audit`.

**Baseline recovery (2026-09-27)** — `baseline-recovery`,
`baseline-recovery-implementation`, `recovery-closeout`.

**Production i18n foundation** — `2026-09-29-i18n-production-foundation`,
`2026-09-29-polylang-production-audit`,
`2026-09-29-post-admin-i18n-verification`,
`2026-09-29-production-pt-language-assignment`.

### 12.2 Class C — examined, not actioned

`2026-09-10-retire-eventbrite-windows` (3 untracked `*.log`) and
`2026-10-19-cursos-infinite-scroll` (1 untracked `*.log`). Regenerable, but they
are **untracked operator output**, not repository content; deleting another
operator's working files is not this task's call (§16). Where a tracked
distilled form already exists (e.g. `2026-09-29-stage-p/lint.txt` beside five
identical `lint.log` files), the tracked form is authoritative and the residue
is ignored by Git.

### 12.3 Class E — kept because the purpose is not established

| Folder | Why it is uncertain |
|---|---|
| `2026-10-12-eventos-stale-results` | a single proof with no narrative beside it |
| `2026-10-21-ui-ux-improvement-6-dead-end` | a report of an abandoned approach — valuable precisely because nothing else records it |
| `2026-11-04-lazer-proximity-release` | a release-preparation note whose release record lives outside this directory |

## 13. Cleanup proposal and what was executed

Format required by the task brief: path, classification, reason, references,
unique content, replacement, risk, action.

| # | Path | Class | Reason | References | Unique content | Replacement | Risk | Action |
|---|---|---|---|---|---|---|---|---|
| 1 | `2026-10-19-ui-ux-improvement-5/implementation-report.md.bak` | **D** | byte-identical (SHA-256 `8a6d48a8…`) to `implementation-report.md` in the same folder | none anywhere in the repo | **none** — identical bytes to the retained `.md` | `implementation-report.md` (retained) | **Low** | **Deleted** ✅ |
| 2 | 8 empty directories (§3) | — (no content, outside A–E) | 0 files each; a name with no evidence is a false lead | none | none | — | **Low** | **Removed** ✅ (Git-untracked, no repository effect) |
| 3 | 14 duplicated tool-script groups (16 files: `en-dry-run.py` ×2, `en-translation-inventory.py` ×2, `stage8-dry-run-verify.py`/`stage9-pre-migration-verify.py`, 12 `verification-scripts/*.php` ×2) | **D** | identical verifier committed into two bundles | the two owning reports | none in content — but each copy is *the tool that actually ran* in that bundle | the copy in the earlier bundle | **Medium** — removing one breaks the bundle's self-containedness for a 0.12 MiB saving | **Keep** (not actioned) |
| 4 | 21 cross-folder output groups (4.04 MiB), incl. the four large ones in §11.3 | **A/B** | the duplicate bytes are the safety/idempotence result | both stages' reports cite their own copy | yes — per-stage provenance | — | **High** — destroys a proof | **Keep** |
| 5 | `2026-10-16-event-cleanup-purge/local-db-before-cleanup.sql` (15.25 MiB, 12% of the directory) | **A** | the only restore point of an executed destructive purge | `docs/reports/2026-10-16-event-cleanup-purge*.md` | yes — the entire pre-purge dataset | **none exists** | **High** | **Keep** — flagged in §13.5 |
| 6 | `2026-09-30-ci-regression-fix/` `00-`, `01-`, `02-`, `03-` full CI logs (14.1 MiB) | **B** | four different GitHub Actions run IDs; `01`–`03` are near-identical outputs of three separate runs | `docs/reports/…ci-regression-fix*` | yes — each run ID is citable provenance | — | **Medium-High** | **Keep** |
| 7 | 160 git-ignored files (158 `*.log`, 2 `*.pyc`) | **C** | regenerable residue | none | no | the tracked distilled form where one exists | **Low, but not this task's** | **Left in place** — untracked operator output (§16) |
| 8 | 70 untracked new-work files (Stage 9 = 48, proximity button removal = 22) | **A/B** | the uncommitted record of the two most recent pieces of work | their reports | yes | — | **High** | **Left untouched** |
| 9 | 2 `__pycache__/` directories (`en-rollout-blocker-resolution`, `en-translation-inventory`) | **C** | Python build residue | none | no | regenerated on next run | **Low** | **Left in place** — ignored, and not mine to delete |

### 13.1 What was deleted, and why that is safe

One file: `implementation-report.md.bak`. It is a byte-identical copy of the
`implementation-report.md` beside it (same SHA-256), it is referenced by nothing
inside or outside the directory, and `implementation-report.md` — the content —
is retained. Saving: 469 bytes.

### 13.2 What was removed structurally

Eight empty directories. Git does not track empty directories, so **the
repository's content is unchanged**; only the working tree lost eight names that
promised evidence and delivered none. Five of them (`2026-10-21-stage-e`,
`ui-ux-image-ratio`, `ui-ux-image-ratio-verification`, `ui-ux-improvement-9`,
`2026-10-24-ai-instructions-audit`) had no report and no file, so nothing
records what they were meant to hold — which is why they are listed verbatim
here and in `04-empty-directories-pre.txt`.

### 13.3 Why the 14 tool-script groups were not consolidated

They are the only duplication that is *genuinely* redundant content
(0.12 MiB). They stayed because each copy sits inside a bundle whose report says
"the script in this folder produced the output above" — deleting one makes that
sentence false; the saving is 0.1% of the directory; and consolidating would
mean editing two evidence bundles and two reports. Recorded as a deliberate,
reversible judgement, not an oversight.

### 13.4 Net effect on size — stated plainly

Measured with `du -sb docs/evidence` and `find docs/evidence -type f | wc -l`:

| | Files | Bytes |
|---|---|---|
| **Before** | 1,445 | 127,622,840 (121.71 MiB) |
| Removed — the `.bak` | −1 | −469 |
| Rewritten `docs/evidence/README.md` (partial index → complete index + policy) | 0 | +10,823 |
| New audit folder `2026-11-06-evidence-directory-audit/` (5 files) | +5 | +214,067 |
| **After** | **1,449** | **127,847,261 (121.92 MiB)** |
| **Net** | **+4** | **+224,421 (+219.2 KiB)** |

The directory did **not** get smaller. Its own audit evidence is larger than
everything it was safe to remove, because there was almost nothing safe to
remove: 4.48 MiB of byte-identical content exists, and §11 shows that all but
0.12 MiB of it is semantically load-bearing. Shrinking the directory further
means deleting production records, which this task forbids. The navigation and
maintainability goals were met instead — a complete index where 111 folders were
previously undocumented, and a written retention policy that stops the next
agent from pruning a proof.

### 13.5 Security finding — recorded, not "fixed"

`docs/evidence/2026-10-16-event-cleanup-purge/local-db-before-cleanup.sql` is a
**full `mysqldump` of a local WordPress database**, committed to Git. It carries
the complete `wp_users` table (password hashes), `wp_usermeta` (session tokens,
capabilities), `wp_options` and every content table.

- **No value is reproduced here.** Only the path and the shape.
- **It is not a production dump**: the header reads `Host: localhost`,
  `Database: wordpress`; it is the snapshot behind a local purge.
- **It is not deleted** — it is the only pre-purge restore point (Class A;
  deleting it would remove the record of what the purge removed).
- **It must not be joined by another one.** The rule is now written into
  `docs/evidence/README.md`: *no bulk database dump unless it is the sole
  restore point of a destructive operation that actually ran*.
- Replacing the dump with a schema + row-digest subset is a content-change task
  with its own plan. It is **not** performed here.

## 14. Preserved evidence

Nothing Category A was touched. In particular the following remain complete and
byte-identical:

| Preserved | State after this task |
|---|---|
| The leisure migration record (approval → inventory → dry-run → reconciliation → writes → journal → post-verification → limitations) | `2026-11-05-leisure-location-*` (5 folders) + `2026-11-05-leisure-coordinate-rest-permission` — **all files, all hashes unchanged** |
| Stage 7 / 8 / 9 production records | unchanged; the Stage 8/9 byte-identical dry-run pairs intact (§11.3) |
| Stage L `gate.json` (CI artifact) | unchanged |
| The pre-purge database snapshot | unchanged, and now explicitly protected by rule |
| The automation commissioning `BLOCKED` / `NOT PERFORMED` records | unchanged |
| Every `*-before` / `*-after` invariance pair | unchanged |
| Every failure proof and negative test | unchanged |
| The 7 folders named nowhere else | kept, and now listed in the index (§10.2) |
| The 3 Class E folders | kept (§12.3) |
| All 230 untracked operator files | untouched (§16) |


## 15. Verification

### 15.1 Commands actually run, after the cleanup

| Command | Exit | Result |
|---|---|---|
| `php scripts/generate-registry-docs.php --check` | **0** | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `python3 tests/scripts/verify-documentation-drift.py` | **0** | `14 passed, 0 failed` — `GATE-RESULT {"gate": "documentation_drift", "status": "pass", …}` |
| `python3 tests/scripts/verify-agent-governance.py` | **0** | `357 passed, 0 failed`; every repository path referenced by an active skill exists |
| `./scripts/run-tests.sh --scripts` | **0** | **20 suites, 20 passed, 0 failed — ALL TESTS PASSED** (script-contract layer) |
| relative-link check over the 4 changed/added Markdown files | **0** | **124 links checked, 0 broken** |
| `find`, `du -sb` and `git ls-files` over `docs/evidence` | **0** | the counts in §9.2 and §13.4 |

**Baseline comparison:** `./scripts/run-tests.sh --scripts` was also run
*before* any edit and produced **the same 20/20 / ALL TESTS PASSED**. There is
no pre-existing failure and none was introduced — the failing-suite diff is
empty.

**Not run, and why** (see §17): the in-process PHP suites and the HTTP
acceptance matrix. They require the Docker WordPress and the task named the
script-contract layer; no PHP, route or test was touched, so they cannot be
affected.

### 15.2 The eight post-cleanup assertions required by the brief

| # | Assertion | Result |
|---|---|---|
| 1 | No essential evidence was removed | **pass** — the only deletion is one byte-identical `.bak`; every Class A file is byte-identical (§15.3) |
| 2 | All report references still resolve | **pass** — 0 broken of 124 relative links across `docs/reports/README.md`, this report and the evidence rule |
| 3 | All documentation references still resolve | **pass** — same check, including `docs/README.md`; the documentation-drift gate is green |
| 4 | Scripts and tests that reference evidence still work | **pass** — 20/20, including `verify-release-integrity` (224), `verify-script-conventions` (761), `verify-eventbrite-token-retirement`, `verify-leisure-location-migration` (58), `verify-stage13-commissioning-readiness` (808) |
| 5 | No evidence index entry points to a deleted file | **pass** — the new index was built from the live directory *after* the cleanup: 134 folders listed, 134 present, 0 stale names |
| 6 | No duplicate files remain in the targeted group | **pass** — the `.bak` group is gone: duplicate groups **64 → 63**, and no `.bak` appears in any group |
| 7 | No secret was introduced | **pass** — a scan of the 4 new/changed Markdown files and all 5 audit artefacts for `application_password`, `Bearer …`, `client_secret`, `sk-…` and PEM headers found nothing |
| 8 | Historical migration evidence remains complete | **pass** — see §15.3 |

### 15.3 The preserved record, proven by hash

Every file in the leisure production chain was re-hashed against the pre-state
inventory:

| Folder | Files (pre → post) | Content |
|---|---|---|
| `2026-11-05-leisure-location-production-audit` | 7 → 7 | **byte-identical** |
| `2026-11-05-leisure-coordinate-rest-permission` | 10 → 10 | **byte-identical** |
| `2026-11-05-leisure-location-stage-7` | 15 → 15 | **byte-identical** |
| `2026-11-05-leisure-location-stage-8` | 22 → 22 | **byte-identical** |
| `2026-11-05-leisure-location-stage-9` | 50 → 50 | **byte-identical** (48 of them still untracked — untouched, as they were) |

Across the whole directory, exactly **one** pre-existing file changed
(`README.md`, the intended rewrite), **one** was removed (the `.bak`) and
**five** were added (this audit's evidence). Nothing else moved by a byte.

## 16. Git state

### 16.1 Before

| | |
|---|---|
| Branch | `leisure-location` |
| HEAD | `c26bdb6637bd0ec882d094d12e3cbfaff50440f4` |
| Staged changes | none |
| Unstaged changes | `docs/reports/2026-11-03-commemorative-events-architecture-audit.md` (**pre-existing operator work — preserved untouched**) |
| Untracked | `docs/evidence/2026-11-05-lazer-proximity-button-removal/` (31 files), `docs/evidence/2026-11-05-leisure-location-stage-9/` (48 files) + 160 git-ignored `.log`/`.pyc` files across 46 folders |
| Stashes | **9** |

### 16.2 After

| | |
|---|---|
| Branch | `leisure-location` (**unchanged**) |
| HEAD | `c26bdb6637bd0ec882d094d12e3cbfaff50440f4` (**unchanged**) |
| Staged changes | none — nothing was `git add`-ed |
| Unstaged changes | the pre-existing `2026-11-03-…-audit.md` **plus** this task's 3 modifications and 1 deletion |
| Untracked | the pre-existing two folders **plus** `docs/evidence/2026-11-06-evidence-directory-audit/` and this report |
| Stashes | **9** — unchanged, none created, dropped or modified |
| Commit / push | **none** — `git log -1` still shows `c26bdb6` |

`git status --porcelain` for the whole worktree, with the pre-existing untracked
evidence folders filtered out:

```
 M docs/README.md
 D docs/evidence/2026-10-19-ui-ux-improvement-5/implementation-report.md.bak
 M docs/evidence/README.md
 M docs/reports/2026-11-03-commemorative-events-architecture-audit.md   ← pre-existing
 M docs/reports/README.md
?? docs/evidence/2026-11-06-evidence-directory-audit/
?? docs/reports/evidence-directory-audit-and-cleanup.md
```

**Destructive commands: none.** No `git reset`, `git clean`, `git checkout --`,
`git restore`, `git stash` operation, or history rewrite was executed. The one
deletion was a plain `rm` of a tracked file, left unstaged.


## 17. Limitations

1. **The directory is not smaller** (§13.4). No safe bulk reduction exists: the
   size is concentrated in single artefacts that are the only record of what
   they capture, and the byte-identical content is almost entirely
   invariance/idempotence proofs. Anyone expecting a 30–50% reduction from this
   task should read §11 before repeating it.
2. **The in-process PHP suites and the HTTP acceptance matrix were not run.**
   They need the Docker WordPress; this change touches no PHP, route, template
   or test, and the task named `./scripts/run-tests.sh --scripts`. The
   script-contract layer (20/20) and the three static gates are green both
   before and after.
3. **No automated evidence-link gate exists**, so the reference check in §15.1
   is a one-off script, not a permanent guard. If evidence links matter enough
   to gate, that is a separate change to `verify-documentation-drift.py` — out
   of scope here, and deliberately not bolted on during a cleanup.
4. **Folder classification is a judgement.** Class A/B boundaries are stated
   with their reasoning and the full A list, so a maintainer can disagree
   folder-by-folder; nothing was deleted on the strength of a classification.
5. **The `.log`/`.pyc` residue was left in place** even where a tracked distilled
   form exists. It is untracked and git-ignored; `git clean` is the operator's
   call, not an agent's, and the brief forbids it.
6. **The database dump was audited for path and shape only.** Its content was
   not inspected row by row, deliberately: reading and quoting local credential
   material is exactly what the evidence rule forbids.
7. **No retention period was set** and none should be inferred. The rule in
   `docs/evidence/README.md` is class-based, not age-based.

## 18. Evidence paths

`docs/evidence/2026-11-06-evidence-directory-audit/`

| File | What it proves |
|---|---|
| `01-inventory-pre.tsv` | the complete pre-state: 1,445 rows of `path`, `bytes`, `sha256`, `tracked`. Re-derivable only until the change is committed — after that it is the only record of the pre-state |
| `02-duplicate-groups.txt` | the 64 byte-identical groups with digests and all member paths — the raw input to §11 |
| `03-folder-reference-matrix.tsv` | per folder: file count, size, tracked count, number of `docs/reports/` files naming it — the raw input to §10 |
| `04-empty-directories-pre.txt` | the eight empty directories found and removed |
| `05-verification.txt` | the post-cleanup tree counts, Git state, and the raw gate results of §15.1 |

## 19. Documentation updated

| Document | Change | `_Last verified_` |
|---|---|---|
| `docs/evidence/README.md` | new **How evidence is named, and how it relates to a report**; new **Index — every evidence folder** (all 134, date-grouped) replacing the partial "Current contents" table, which is retained as **Selected records — where to start**; new **Retention — what stays, forever** (A–E, "an identical before/after pair is not a duplicate", "when uncertain keep it"); new **What must never be committed here**; new **Adding evidence for new work**; the `docs/evidence/<date>-<stage>/` row of the disposal table corrected (no longer "small", no longer "drop raw dumps", now "never pruned for age") | updated |
| `docs/reports/README.md` | new section **Evidence directory audit and cleanup (2026-11-06)** with this report | updated |
| `docs/README.md` | the `evidence/README.md` row now describes what the document actually contains | updated |

No procedure moved out of a document into a skill, so the change → skill map is
unaffected. Nothing in `.agents/skills/` was touched; the agent-governance gate
(357 assertions) confirms every skill path still resolves.

## 20. Rollback / recovery

| Action | Reverse |
|---|---|
| Deleted `.bak` | `git checkout -- docs/evidence/2026-10-19-ui-ux-improvement-5/implementation-report.md.bak` (the content is also in the retained `.md`) |
| Removed empty directories | nothing to restore — they held no file; all eight names are recorded in `04-empty-directories-pre.txt` |
| `docs/evidence/README.md` rewrite | `git checkout -- docs/evidence/README.md` — the previous index is at `HEAD` |
| `docs/reports/README.md`, `docs/README.md` | `git checkout -- <path>` |
| Audit evidence and this report | new files; delete them, nothing depends on them |

Rollback **does not** cover the four claims now written into the evidence rule
(the retention policy, the "identical before/after is not a duplicate" rule, the
commit prohibitions, the add-evidence procedure) — if the rewrite is reverted,
those rules go with it, and the next agent is back to a 29-of-140 index.

## 21. Final status

**Final status: `PASS WITH LIMITATION`**

The audit is complete and verified, the two safe cleanups were performed, the
index and the retention policy now cover the whole directory, and every gate
that ran is green — before and after. The limitations are stated, not hidden:
the directory is **not** smaller (there was no safe way to make it so), and the
in-process and HTTP layers were not run because the change touches none of what
they measure.

A verdict of `AUDIT COMPLETE — NO SAFE CLEANUP IDENTIFIED` was rejected because
two safe cleanups *were* identified and executed; `PASS` was rejected because a
required verification layer did not run.

_Last verified: 2026-11-06 by the evidence directory audit and cleanup_

