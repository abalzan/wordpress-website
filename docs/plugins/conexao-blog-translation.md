# conexao-blog-translation (REMOVED — Stage 19)

> **This plugin no longer exists.** Stage 19 removed it from the active
> repository on 2026-10-02. It was a historical one-shot **rollout container**,
> and its authored translation data had already been consolidated into the
> shared translation stages. It was never part of the production steady state,
> was never included in a release ZIP, and no supported runtime path loaded it.

## Why it was removed

Five one-shot rollout plugins each carried their own copy of the rollout
lifecycle. Stage H introduced the shared engine (`conexao-translation-rollout`)
precisely so that a new rollout would not need its own `apply.php` + `audit.php`
+ admin class. This plugin was the last container for Stage 5. With its data
already consolidated, keeping the directory only preserved a duplicate dormant
translation engine — which is the second source of truth the engineering
standard forbids.

## Where the authored data lives now

**the `en-post` stage of `conexao-en-translation` (`includes/blog-translation-data.php`) plus `en-blog-page` (`includes/blog-page-data.php`)**


The 26 EN titles that differ from the retired map are **re-authorings**, not
truncations: Stage N re-wrote and re-proofread the Blog English after Stage 5
(the retired strings are in Git history). The active copy is the reviewed one.
## Data-preservation proof (measured, Stage 19)

33 of its 36 authored posts have a real PT record and every one of them exists in `en-post`. The other 3 (`carne-refogada-ao-estilo-korean-bbq`, `informacoes-para-as-mulheres-na-irlanda`, `turismo-e-lazer-em-co-laois-na-irlanda`) have **no PT record at all** — there is nothing to translate, so no active row can exist for them.

The executable proof is
[`docs/evidence/2026-10-02-stage-19/data-preservation-proof.php`](../evidence/2026-10-02-stage-19/data-preservation-proof.php),
run inside the real WordPress container; its output is
`docs/evidence/2026-10-02-stage-19/02-data-preservation-proof-BEFORE.txt`.

## Historical provenance

The implementation is recoverable in full from Git history:

```bash
git log --oneline -- wp-content/plugins/conexao-blog-translation
git show <stage-19-parent>:wp-content/plugins/conexao-blog-translation
```

The stage reports under [`docs/reports/`](../reports/) describe the architecture
as it was when the stage ran, and are deliberately **not** rewritten: a Stage 9
report should keep describing Stage 9.

See also: [`conexao-en-translation.md`](./conexao-en-translation.md) (the active
data/stage layer) and [`conexao-translation-rollout.md`](./conexao-translation-rollout.md)
(the shared engine).

_Last verified: 2026-10-02 by Stage 19 — removal of the retired translation plugins_
