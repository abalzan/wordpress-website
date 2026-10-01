# Evidence — Stage 4: translation provider and translation-plan adapter

Cited by
`docs/reports/2026-09-30-stage-4-provider-and-translation-plan.md`.

| File | What it proves |
|---|---|
| `00-baseline-tests.log` | The suite **before** any Stage 4 edit: 72 in-process suites, `test-en-jobs-shared-slug.php` failing, and `test-stage7-hero-sponsors.php` intermittent. The comparison point for every number in the report. |
| `01-stage4-structural-gate.txt` | `verify-stage4-provider.py` — **110 checks, 0 failed**: exactly one provider implementation, outbound request confined to the designated file, no WordPress write in any file, no cron, no public route, no second engine, no apply path, no approval state, credential named in one file only. |
| `02-stage3-gate-after-supersession.txt` | `verify-stage3-automation.py` — **123 checks, 0 failed** after the one superseded assertion was narrowed. Everything Stage 3 proved still holds. |
| `03-engine-digest-before-after.txt` | SHA-256 of all three `conexao-translation-rollout` files before and after. The engine is `baf85283…a6ce4` — **exact equality** with the required baseline — and `git diff` over that plugin is **empty**. |
| `04-provider-structural-properties.txt` | Per-file scan of the four new files (comments stripped) against nine forbidden categories. **All `none`**: no write, no Polylang, no cron, no route, no persistence, no engine vocabulary, no apply path, no self-validation, no credential literal. |
| `05-live-smoke-skips-cleanly.txt` | The live-provider smoke test **skips with exit 0** both without authorisation and without a credential, makes no call, and is **not discovered** by `./scripts/run-tests.sh` (0 matches in `--list`). |
| `06-lint.txt` | `./scripts/lint.sh` — **lint: OK**. Syntax clean, no new PHPCS violations, PHPStan level 5 no errors. Records that 2 real PHPCS violations were found and **fixed in the code**, not baselined away. |
| `07-end-to-end-dry-run.txt` | The whole chain on a real record: provider → validator → plan (`REVIEW_REQUIRED`, `human_review_required`, `write_owned_en_field`) → stale-source rejection → composed manifest → **engine gate PASS**, 0 errors. |
| `08-pt-immutability-proof.txt` | PT immutability across the full dry run, using object accessors: 8 post fields, `_leisure_excerpt_en`, **all** post meta, category terms and Polylang translations. **Whole snapshot identical — zero writes.** |
| `09-pre-existing-failure-attribution.txt` | Controlled experiment: `test-en-jobs-shared-slug.php` fails **identically with all Stage 4 changes stashed**, so it is not a Stage 4 regression. |
| `10-final-full-suite.txt` | The final `./scripts/run-tests.sh`: 74/73/1 in-process, 9/8/1 script-contract, 3/3/3 acceptance, with both failures attributed. |
| `11-credential-boundary.txt` | The credential boundary at runtime: fails closed with **0 HTTP calls**; a credential-shaped fake appears in **no** observable output; reaches the header and never the body; the variable name exists in exactly one plugin file. |

_Last verified: 2026-09-30 by Stage 4 — translation provider and translation-plan adapter_
