# Evidence — Phase 3 production translation verification (2026-09-30)

**Read-only audit of the authorised Phase 3 EN translation rollout against live
production (`https://conexaobr.ie`, WordPress.com).**

**Production writes performed by this audit: 0. HTTP verbs used: `GET` only.**
Nothing was created, updated, deleted, un-trashed, re-keyed, deactivated or
activated. The rollout engine's `run()` was never called; the idempotence pass
drove `build_plan()` / `calculate_gate()` only.

Credentials came from the repository `.env` and were written to
`/tmp/prod-rest.env` (mode `0600`, **outside** the repository) exactly as
`scripts/verify-english-http.py` expects. No secret is reproduced in any artefact
in this directory.

## Files

| File | What it is |
|---|---|
| `00-repository-stage-manifests.json` | The repository's own EN stage manifests, dumped by executing the plugin's pure data files in PHP (read-only). The expected work is read from the repository, never re-invented. |
| `01-en-inventory-manifest.json` | Phase 1 read-only inventory re-run against live production, **after** the apply. 2,279 source objects; `CREATE_CANDIDATE 0`. |
| `02-inventory-run.log` | Its console summary. |
| `03-polylang-config.json` | `GET /wp-json/pll/v1/settings` + `/languages`. |
| `04-route-matrix.json` | 20-route EN/PT HTTP acceptance matrix: status, redirect chain, final URL, canonical, `html lang`, title, `hreflang`. |
| `05-b1-records-and-relationships.json` | B1 EN record + bidirectional relationship verification for `guide` and `post`. |
| `05-b1-verify.log` | Its console summary. |
| `05b-b1-guide.json` | Retained intermediate guide-only capture. |
| `06-run-tests.log` | `./scripts/run-tests.sh` — in-process PHP, script-contract and HTTP acceptance suites. |
| `07-permanent-gates.log` | `python3 scripts/verify-permanent-gates.py` — the 7 fail-closed Stage L gates. |
| `08-lint.log` | `./scripts/lint.sh` — PHP syntax, PHPCS baseline, PHPStan level 5. |
| `09-pt-integrity-restored-six.json` | The six restored PT-BR sources compared field-by-field against the committed pre-write snapshot. |
| `10-taxonomies.json` / `.log` | Taxonomy inventory: `conexao_category`, `conexao_tag`, `conexao_county`, `conexao_town`. |
| `11-b2-archive-render.json` | First B2 probe (archive page 1 only — superseded by `18-`). |
| `13-b1-pages.json` / `.log` | The 33 authored B1 `page` rows resolved through their own `/en/` URLs. |
| `14-idempotence-plan.json` / `15-idempotence-snapshot.json` | The second planning pass — the idempotence check. `b1_creates 0`, links `0`, `taxonomy_creates 0`. |
| `14-idempotence-summary.txt` / `.log` | Its console summary and gate table. |
| `16-gate-json-snapshot-after-run.json` | Copy of `docs/evidence/2026-09-26-stage-l/gate.json` as the gate run left it. The committed file was then restored with `git checkout --` so committed evidence is not rewritten. |
| `17-b2-full-archive-verification.json` | B2 probe across `/page/N/` (superseded: the archive paginates over admin-ajax POST, not GET). |
| `18-b2-leisure-county-partitions.json` / `.log` | B2 leisure verification across 53 GET-reachable archive partitions (1 unfiltered + 26 counties + type + feature axes). |
| `en-translation-inventory.py`, `en-dry-run.py` | Copies of the repository's own read-only tools, invoked unmodified. `Engine::run()` is never called; the only verb in either file is `GET`. |

## Reproducing

```bash
# inventory (read-only, GET only)
CONEXAO_SITE_URL=https://conexaobr.ie \
python3 en-translation-inventory.py \
  --manifests 00-repository-stage-manifests.json \
  --out /tmp/inventory.json

# idempotence / second planning pass (read-only, GET only, run() never called)
CONEXAO_SITE_URL=https://conexaobr.ie \
python3 en-dry-run.py --out /tmp/plan.json --snapshot /tmp/snapshot.json

# local gates
./scripts/run-tests.sh
python3 scripts/verify-permanent-gates.py
./scripts/lint.sh
php scripts/generate-registry-docs.php --check
```

Production rate-limits hard (HTTP 429). The verification scripts therefore page
per status and back off; a full run takes tens of minutes.

## Two things a reader must not misread

1. **`b2_field_updates 299` in the idempotence pass is NOT 299 unapplied
   writes.** `_leisure_excerpt_en` and `_provider_excerpt_en` are read by the
   theme with `get_post_meta()` and are **not** exposed through the REST `meta`
   object, so no API-based tool can observe them. The rendered EN archive is the
   authoritative read, and it shows the English descriptions. See the report's
   B2 section.
2. **`b1_conflicts 3` in the idempotence pass is NOT three broken
   relationships.** The three shared-slug B1 pages resolve their PT source by
   slug, so the lookup returns the **EN** record and reports a collision with
   the true PT record. All three pages are correct in production. See the
   report's idempotence section.
