# conexao-page-translation (REMOVED — Stage 19)

> **This plugin no longer exists.** Stage 19 removed it from the active
> repository on 2026-10-02. It was a historical one-shot **rollout container**,
> and its authored translation data had already been consolidated into the
> shared translation stages. It was never part of the production steady state,
> was never included in a release ZIP, and no supported runtime path loaded it.

## Why it was removed

Five one-shot rollout plugins each carried their own copy of the rollout
lifecycle. Stage H introduced the shared engine (`conexao-translation-rollout`)
precisely so that a new rollout would not need its own `apply.php` + `audit.php`
+ admin class. This plugin was the last container for Stage 4.5. With its data
already consolidated, keeping the directory only preserved a duplicate dormant
translation engine — which is the second source of truth the engineering
standard forbids.

## Where the authored data lives now

**`conexao-en-translation` stages `en-page` (the ordinary pages), `en-blog-page` and `en-jobs-page`**


Two rows are worth recording explicitly, because they look like losses and are not:

| Retired row | Why it is not in `en-page` |
|---|---|
| `carteira-de-motorista-2`-style re-keys | the active stage data re-keys a row when the real PT `post_name` differs from the historical key; the authored English moves with it |
| `empregos` / `blog` | both moved to their own single-record stages (`en-jobs-page`, `en-blog-page`) so the `en-page` stage cannot mint a second EN page for the same PT source |

The EN link localiser this plugin implemented is **not** re-implemented anywhere:
link re-pointing through the Polylang relationship is the shared engine's job.
## Data-preservation proof (measured, Stage 19)

27 of its 37 authored rows are present verbatim or by documented re-authoring in the active page stages; the remaining 10 (the nine county landing pages plus the `irlanda` country hub) are on `conexao_b2_page_allowlist()` by decision §8, so they are deliberately served by the documented B2 fallback rather than by a translated page record.

The executable proof is
[`docs/evidence/2026-10-02-stage-19/data-preservation-proof.php`](../evidence/2026-10-02-stage-19/data-preservation-proof.php),
run inside the real WordPress container; its output is
`docs/evidence/2026-10-02-stage-19/02-data-preservation-proof-BEFORE.txt`.

## Historical provenance

The implementation is recoverable in full from Git history:

```bash
git log --oneline -- wp-content/plugins/conexao-page-translation
git show <stage-19-parent>:wp-content/plugins/conexao-page-translation
```

The stage reports under [`docs/reports/`](../reports/) describe the architecture
as it was when the stage ran, and are deliberately **not** rewritten: a Stage 9
report should keep describing Stage 9.

See also: [`conexao-en-translation.md`](./conexao-en-translation.md) (the active
data/stage layer) and [`conexao-translation-rollout.md`](./conexao-translation-rollout.md)
(the shared engine).

_Last verified: 2026-10-02 by Stage 19 — removal of the retired translation plugins_
