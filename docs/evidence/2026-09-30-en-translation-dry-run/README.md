# Evidence — EN Translation Rollout, Phase 2: Dry-Run + Snapshot (read-only)

**Production writes: 0. Every request this phase issued was a `GET`. No apply,
no writer, no `--apply`, no deployment.** Report:
[`../../reports/2026-09-30-en-translation-dry-run.md`](../../reports/2026-09-30-en-translation-dry-run.md).

Target: `https://conexaobr.ie` (production, read-only). Credentials are read from
the environment (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`) and appear in **no**
file in this directory.

## The engine is the repository's own, not a re-implementation

This phase contains **no second translation lifecycle**. The plan is produced by
the repository's own shared engine, loaded **unmodified** from
`wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php`,
by calling its own public static methods:

| Engine method | Role here |
|---|---|
| `validate_manifest()` | validates each stage manifest exactly as a real run would (fails closed) |
| `build_plan()` | produces `create` / `update` / `skip` / `conflicts` — the plan |
| `calculate_gate()` | the numeric per-stage gate |

`Conexao_Translation_Rollout_Engine::run()` is **never** called, so no apply, no
remove and no writer is reachable from this tool. The authored English is read
from `wp-content/plugins/conexao-en-translation` by executing its own data files
in PHP — the expected work is the repository's, never a re-invented list, and the
B1/B2 classification is read from HEAD, not re-decided here.

The engine's WordPress-bound adapters cannot execute on production (WordPress.com
has no PHP/CLI), so `en-dry-run.py` mirrors each adapter primitive (`find_pt`,
`find_en_for_pt`, `slug_collision`) as a **read-only** lookup over the same REST
read Phase 1 established. The plan, the counting and the gate remain the
engine's own code.

## Files

| File | What it is |
|---|---|
| `en-dry-run.py` | The read-only dry-run + snapshot tool. Defines only `GET`; no write verb, no `--apply`, no write assertion. Imports the Phase 1 reader as a module (reuse, not a copy). |
| `01-production-revalidation.json` | A fresh, uncached production re-read proving the Phase 1 baseline still holds (PT identity digest `cfe4b7a6…`, EN 0, 2,175 PT translated scope). The Phase 1 manifest rows are omitted here because they are already committed byte-identical in the Phase 1 evidence directory. |
| `02-dry-run-plan.json` | The engine's plan per stage, the acceptance gates, the taxonomy plan, the slug-collision resolutions, the missing-source list and the full operation list. |
| `03-mutation-snapshot.json` | The read-only mutation snapshot: 417 record entries + 13 taxonomy terms = **430 objects**. |
| `04-summary-tables.txt` | The same numbers as plain tables. |
| `05-route-and-collision-verification.txt` | Live proof of the `newsletter → newsletter-2` collision and of what `/en/newsletter/` serves today. |
| `06-determinism.json` | The repeated-dry-run comparison (two independent executions, the second with its own uncached production read). |
| `07-snapshot-verification.txt` | Proof the snapshot is readable, reconciles 1:1 to the plan (417 + 13 = 430) and references the same PT baseline. |
| `08-safety-verification.txt` | The safety ledger, repository-integrity check and determinism result. |

## Reproducing

```bash
set -a && . ./.env && set +a
CONEXAO_SITE_URL=https://conexaobr.ie \
python3 docs/evidence/2026-09-30-en-translation-dry-run/en-dry-run.py \
  --out /tmp/plan.json --snapshot /tmp/snapshot.json --drafts
```

Re-reading production takes several minutes. Passing `--live-from <phase1.json>`
reuses a captured read instead; it is a development convenience and must not be
used for the determinism proof.

## Key numbers

* PT translated-CPT scope: **2,175** · PT total scope: 2,255 · **EN: 0**
* Manifest rows across the 7 registered stages: **426** (127 B1 + 299 B2)
* B1 creates **118** · B1 conflicts **0** · B1 missing PT source **9**
* B2 field updates **299** · B2 EN records created **0**
* Taxonomy creates **13** (`conexao_category`); `conexao_county` 26 and
  `conexao_town` 251 stay **shared**, 0 with a language
* Conflicts **0** · invalid **0** · orphans **0** · duplicate target identities
  **0** · PT overwrite attempts **0** · unexpected EN objects **0**
* Unresolved slug conflicts **1** (`en-page::newsletter`)
* Plan digest `048543cf…` · snapshot digest `f1f3c3bb…` (430 objects)
* PT identity digest `cfe4b7a6…` — **identical to Phase 1**

_Last verified: 2026-09-30 by the EN Translation Rollout — Phase 2 dry-run + snapshot._
