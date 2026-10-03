# Evidence — Stage 6: transport contract fix and protected production trigger

Run date: 2026-10-01 · Branch: `i18n` · Base commit: `27c06c956a38543d63180574caddc80221ab2c9c`

**Production writes: 0.** Production was never contacted.

| File | What it proves |
|---|---|
| `01-integrity-before.txt` | The first gate: clean tree, branch, HEAD, per-file engine SHA-256, automation-plugin tree SHA, provider SHA, registry state, and the required engine baseline — **captured before any change**. |
| `02-test-baseline-before.txt` | The complete baseline `./scripts/run-tests.sh` run: 74 PHP suites, 5383 assertions, 6 known failures. |
| `03-transport-regression-proof.txt` | The regression proof: the same suite run against the **Stage 5** provider (`got conexao_automation_provider_bad_transport`) and against the **Stage 6** provider (64 passed, 0 failed). Only the provider file changed between the two runs. |
| `04-final-verification.txt` | Baseline-vs-after comparison, engine before/after SHA-256 with an equality assertion, lint, registry check, all structural gates, the permanent-gate aggregate, the pre-existence proof for `i18n_freshness`, and the final state summary. |
| `05-test-suite-after.txt` | The complete `./scripts/run-tests.sh` run after Stage 6: 76 PHP suites, 5563 assertions, the same 6 known failures. |

## Headline numbers

| | Before | After |
|---|---|---|
| In-process PHP suites | 74 (72 passed, 2 failed) | **76** (74 passed, 2 failed) |
| Assertions | 5383 passed, 6 failed | **5563 passed, 6 failed** |
| Script-contract suites | 9 (8 passed, 1 failed) | **10** (9 passed, 1 failed) |
| HTTP acceptance suites | 3 (2 passed, 1 failed) | 3 (2 passed, 1 failed) |
| Engine SHA-256 | `baf85283…a6ce4` | `baf85283…a6ce4` (**unchanged**) |

**+180 assertions, zero new failures.** The failing set is byte-identical
before and after.

## How to reproduce the regression proof

```bash
D=docs/evidence/2026-10-01-stage-6-transport-trigger-commissioning
P=wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-provider-openai.php
cp "$P" /tmp/s6.php
git show HEAD:"$P" > "$P"                       # Stage 5 provider
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/conexao-translation-automation/tests/test-automation-transport-shape.php
cp /tmp/s6.php "$P"                             # Stage 6 provider
docker compose exec -T wordpress php /var/www/html/wp-content/plugins/conexao-translation-automation/tests/test-automation-transport-shape.php
```