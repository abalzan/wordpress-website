# Evidence index — Stage 5 production commissioning and canary

Status: **BLOCKED**. No production mutation was attempted or performed.

| File | Contents |
|---|---|
| `01-integrity-and-artifacts.txt` | Engine SHA-256 (before and after) and built artifact hashes |
| `02-full-test-suite.log` | Complete `./scripts/run-tests.sh` output |
| `03-release-build.log` | `./scripts/build-plugins-zip.sh` output |
| `04-preconditions-and-live-probe.md` | The twelve preconditions, entry-point scan, live provider probe, transport defect proof |

## Commands run, verbatim

```bash
sha256sum wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php
./scripts/run-tests.sh
./scripts/build-plugins-zip.sh
./scripts/lint.sh
php scripts/generate-registry-docs.php --check
python3 scripts/release-manifest.py --verify
curl -sS -o /dev/null -w '%{http_code}' https://conexaobr.ie/          # 200, read-only
```

## Headline numbers

- Engine SHA-256 before and after: `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` — **exact equality**
- `conexao-translation-automation.zip`: `2b4857ae372b385c00f76e19a8f1a2294da6f04efca7480e47a75906f3dd26ef` (deterministic across two builds)
- `conexao-translation-rollout.zip`: `5b87fbc533c352197e6bf6be14b49d27537323a8370862ec7bb8031bb3d15d5b` (deterministic across two builds)
- Test suite: 74 PHP suites (72 pass / 2 fail), 5383 assertions passed, 6 failed; 9 script-contract suites (8/1); 3 HTTP acceptance (2/1)
- `lint.sh`: **OK**; registry check: **OK**, zero writes; release manifest: **verified, 10/10**
- Live provider: **HTTP 429 `insufficient_quota`** — transport reached, no credit
- Production credential: **absent** (`WP_USERNAME` / `WP_APPLICATION_PASSWORD` unset)
- Production writes: **0**
