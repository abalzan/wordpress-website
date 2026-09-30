# Evidence — EN Translation Rollout, Phase 1: Production Inventory (read-only)

**Production writes: 0. Every request was a `GET`.** Report:
[`../../reports/2026-09-30-en-translation-inventory.md`](../../reports/2026-09-30-en-translation-inventory.md).

Target: `https://conexaobr.ie` (production, read-only). Credentials are read
from the environment (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`) and appear in
**no** file in this directory.

## Files

| File | What it is |
|---|---|
| `en-translation-inventory.py` | The read-only inventory tool. It defines **only** `GET`; there is no write verb, no `--apply` and no production-write path. Reuses `scripts/lib/rest.py` for base-URL resolution, auth, retries and pagination. |
| `00-repository-stage-manifests.json` | The repository's **own** EN stage manifests, dumped by executing `conexao-en-translation`'s data files in PHP. This is the input the manifest is joined against — the expected work is the repository's, not a re-invented list. |
| `01-en-inventory-manifest.json` | The classified manifest: every source object with its PT ID, type, slug, status, canonical URL, language, translation links, stage, B1/B2 class, target identity, category and reason — plus the full report block (Polylang state, per-type counts, validation, PT baseline, digests). |
| `01-summary-tables.txt` | The same numbers as plain tables, for reading without a JSON parser. |
| `02-polylang-baseline.txt` | Live `GET /pll/v1/settings` and `GET /pll/v1/languages`, re-read after the inventory finished. |
| `03-route-matrix.txt` | Status, `<html lang>`, canonical and hreflang for the 12 primary PT/EN routes plus two guide singles — including the 404 that proves the EN guide record does not exist. |
| `04-b2-resolution-samples.txt` | Rendered `/en/blog/`, `/en/empregos/` and `/en/guias/{pt-slug}/` — the positive proof that these resolve to **PT content under the EN shell** (B2) and that `_leisure_excerpt_en` / `_provider_excerpt_en` are absent. |
| `05-determinism.json` | Two full, **uncached** reads of live production: identical manifest digest, byte-identical manifest rows, identical PT identity and per-type content digests. |
| `06-safety-verification.txt` | Three-run comparison (baseline → determinism → safety re-read) and the safety ledger: production writes 0, EN records created 0, translation links 0, taxonomy translations 0, PT drift 0, `.env` unchanged, secrets not exposed. |

## Reproducing

```bash
set -a && . ./.env && set +a
CONEXAO_SITE_URL=https://conexaobr.ie \
python3 docs/evidence/2026-09-30-en-translation-inventory/en-translation-inventory.py \
  --manifests docs/evidence/2026-09-30-en-translation-inventory/00-repository-stage-manifests.json \
  --out /tmp/inventory.json --drafts
```

Exit code `0` means every manifest consistency check passed; `2` means at least
one failed. Omit `--cache-dir` (as above) so the run always re-reads production
— the cache is a development convenience and must not be used for the
determinism proof.

## Key numbers

* PT translated-CPT scope: **2,175** (the documented baseline, re-measured)
* PT total in inventory scope: **2,255** (adds `post` 37 + `page` 43)
* EN records, all post types and taxonomies: **0**
* EN translation links: **0** · orphan EN: **0** · duplicate translations: **0**
* Manifest: **2,277** objects → **430** expected EN objects
  (131 B1 records + 299 B2 fields), 1,820 B2-only, 27 out of scope,
  **0** conflicts, **0** invalid
* Manifest digest: `7d7b066f0eb5aaa89b61642bed65482f784ad1a492e769fdd01549b8624c528a`
* PT identity digest: `cfe4b7a670266da32ba635f3f46994328143d3de77a914aa52491d42e3a6f853`

_Last verified: 2026-09-30 by the EN Translation Rollout — Phase 1 inventory._
