# Evidence — EN Translation Rollout, blocker resolution (read-only)

**Production writes: 0. Every request issued against production was a `GET`. No
apply, no writer, no `--apply`, no `Engine::run()`, no deployment, no SQL, no
Polylang change, no EN record, no translation link.**

Report:
[`../../reports/2026-09-30-en-rollout-blocker-resolution.md`](../../reports/2026-09-30-en-rollout-blocker-resolution.md).

Target: `https://conexaobr.ie` (production, read-only). Credentials are read from
the environment (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`) and appear in **no**
file in this directory.

## What this phase resolved

1. **9 authored rows with no current PT source** → 2 **re-keyed** to their real
   production record (authored English carried across byte-for-byte, verified by
   payload SHA-256), 7 explicitly classified `NO_REAL_PT_SOURCE` for an editorial
   decision. `MISSING_SOURCE` 9 → 7.
2. **`en-page::newsletter` would have been created as `newsletter-2`** → the
   existing shared-slug permit is now read from each stage's own manifest, so
   `en-page` holds it for `newsletter` and the plan targets `newsletter`.
   `unresolved_slug_conflicts` 1 → **0**.

## The engine is the repository's own, not a re-implementation

`en-dry-run.py` loads `conexao-translation-rollout`'s engine **unmodified**
(`class-conexao-translation-rollout-engine.php`, sha256
`baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` — the same digest
the 2026-09-30 Phase 2 baseline recorded) and calls only its **pure** methods
`validate_manifest()`, `build_plan()`, `calculate_gate()`. `Engine::run()` is
**never** called, so no apply, no remove and no writer is reachable. The plan
JSON reports `apply_invoked: false` and `http_verbs_used: ["GET"]`.

The authored English is read from `wp-content/plugins/conexao-en-translation` by
**executing its own data files in PHP** — never re-listed here. The shared-slug
permit is read the same way, through
`conexao_en_translation_shared_page_slug_for()`, so the measured plan cannot
drift from the code that implements it; a dump missing that key is a hard error,
not a silent default.

## Files

| file | what it is |
|---|---|
| `dump-stage-manifests.php` | Read-only dumper: executes the plugin's data + config files in PHP and prints the stage manifests, including `shared_slug_policy` read from `conexao_en_translation_shared_page_slug_for()`. |
| `00-repository-stage-manifests.json` | The output of the above, from the current HEAD. The input the manifest is joined against — the expected work is the repository's, not a re-invented list. |
| `01-en-inventory-manifest.json` | The **regenerated** manifest joined to a fresh, uncached production read, with the PT protection baseline. |
| `02-dry-run-plan.json` | The clean dry-run: 30 acceptance gates, 419 operations, the taxonomy plan, the slug-collision resolutions, the missing-source classification, the PT baseline. |
| `03-mutation-snapshot.json` | The read-only mutation snapshot: 419 record entries + 13 taxonomy terms = **432 objects**. |
| `04-summary-tables.txt` | The same numbers as plain tables. |
| `05-source-resolution-table.txt` | The nine rows: old authored key, actual PT source, PT id, type, evidence and action — plus what each `NO_REAL_PT_SOURCE` row still owes an editor. |
| `06-newsletter-policy.txt` | Why the collision existed, why `newsletter-2` is rejected, why Option A, the exact mechanism extended, the tests, the negative safeguards and the resulting target slug. |
| `07-determinism.json` | Two **independent uncached** production reads compared field by field, including the exact two non-content leaves that differ in the raw plan JSON. |
| `08-snapshot-verification.txt` | Proof the snapshot reconciles 1:1 with the plan, carries the required fields per B1/B2 class, and contains no PT overwrite. |
| `09-safety-verification.txt` | The safety ledger, what was and was not done, and the Phase 7 production revalidation (scope, EN state, digests, routes). |
| `10-permanent-gates.json` | `python3 scripts/verify-permanent-gates.py` output for this SHA. |
| `11-gates.txt` | Every repository gate with exact numbers, plus the before/after proof that the single failure is pre-existing. |
| `en-translation-inventory.py` | The read-only inventory tool (`GET` only; no `--apply`, no write verb). |
| `en-dry-run.py` | The read-only dry-run + snapshot tool (`GET` only; no `--apply`, no write verb). |

## Reproducing

```bash
set -a && . ./.env && set +a
EV=docs/evidence/2026-09-30-en-rollout-blocker-resolution

# 1. dump the repository's own stage manifests (no network, no WordPress)
php $EV/dump-stage-manifests.php > $EV/00-repository-stage-manifests.json

# 2. regenerate the manifest against live production (read-only GET)
CONEXAO_SITE_URL=https://conexaobr.ie python3 $EV/en-translation-inventory.py \
  --manifests $EV/00-repository-stage-manifests.json \
  --out $EV/01-en-inventory-manifest.json --drafts

# 3. the canonical dry-run + snapshot (read-only GET; run() is never called)
CONEXAO_SITE_URL=https://conexaobr.ie python3 $EV/en-dry-run.py \
  --out $EV/02-dry-run-plan.json --snapshot $EV/03-mutation-snapshot.json --drafts
```

Steps 2 and 3 each re-read production, which takes several minutes. Omit
`--cache-dir` (there is none by default) so a run always re-reads production —
a cache is a development convenience and must not be used for the determinism
proof. `--live-from <phase1.json>` reuses a captured read instead; that is also
a development convenience, and is not how either determinism run was made.

## Key numbers

* PT translated-CPT scope **2,175** · PT total scope 2,255 · **EN: 0** · links **0**
* Manifest **2,275** objects → **432** expected EN objects (432 create candidates), 1,820 B2-only, 23 out of scope, **0** conflicts, **0** invalid
* Manifest digest `2872808c…` · plan digest `9c245082…` · snapshot digest `b177ce59…` (432 objects)
* B1 creates **120** · B1 links **120** · B1 updates 0 · B1 conflicts 0
* B2 field updates **299** · B2 EN records created **0**
* **Missing source 7** — all `NO_REAL_PT_SOURCE`, all listed by name
* Taxonomy creates **13** (`conexao_category`); `conexao_county` 26 and `conexao_town` 251 stay **shared**
* **PT overwrite attempts 0** · duplicate target identities 0 · unexpected EN objects 0 · unclassified 0 · orphans 0
* **Unresolved slug conflicts 0** — `en-page::newsletter` → target slug **`newsletter`**
* PT identity digest `cfe4b7a6…` — **identical** to the 2026-09-30 Phase 1 and Phase 2 baseline
* Deterministic: snapshot byte-identical; plan byte-identical once the run timestamp and the output path (two documented non-content fields) are normalised; all 419 operations and all 30 gates identical

_Last verified: 2026-09-30 by the EN Translation Rollout — blocker resolution._
