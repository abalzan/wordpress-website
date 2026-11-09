# conexao-guide-translation (REMOVED — Stage 19)

> **This plugin no longer exists.** Stage 19 removed it from the active
> repository on 2026-10-02. It was a historical one-shot **rollout container**,
> and its authored translation data had already been consolidated into the
> shared translation stages. It was never part of the production steady state,
> was never included in a release ZIP, and no supported runtime path loaded it.

## Why it was removed

Five one-shot rollout plugins each carried their own copy of the rollout
lifecycle. Stage H introduced the shared engine (`conexao-translation-rollout`)
precisely so that a new rollout would not need its own `apply.php` + `audit.php`
+ admin class. This plugin was the last container for Stage 9. With its data
already consolidated, keeping the directory only preserved a duplicate dormant
translation engine — which is the second source of truth the engineering
standard forbids.

## Where the authored data lives now

**the `en-guide` stage of `conexao-en-translation` (`includes/guide-translation-data.php` + `includes/guide-terms-data.php`)**


The plugin also owned the authored `conexao_category` **terms** those guides use;
those live in `includes/guide-terms-data.php` and are applied through the shared
engine's translated-taxonomy capability.

The retired `includes/body-*.php` files were generated authored-body fragments,
excluded from PHPCS/PHPStan as machine-generated content. They are gone; the
authored English survives inside the stage data records.
## Data-preservation proof (measured, Stage 19)

49 of its 51 authored rows are present unchanged (title, EN slug and body identical). Row 50 is `carteira-de-motorista-2`, re-keyed to `carteira-motorista-brasileiros` with an identical body and title. Row 51 (`learner-permit-theory-test-irlanda-cnh-brasileira`) was retired by operator decision and is recorded in `includes/exclusions-data.php` with classification `NO_REAL_PT_SOURCE`.

The executable proof is
[`docs/evidence/2026-10-02-stage-19/data-preservation-proof.php`](../evidence/2026-10-02-stage-19/data-preservation-proof.php),
run inside the real WordPress container; its output is
`docs/evidence/2026-10-02-stage-19/02-data-preservation-proof-BEFORE.txt`.

## Historical provenance

The implementation is recoverable in full from Git history:

```bash
git log --oneline -- wp-content/plugins/conexao-guide-translation
git show <stage-19-parent>:wp-content/plugins/conexao-guide-translation
```

The stage reports under [`docs/reports/`](../reports/) describe the architecture
as it was when the stage ran, and are deliberately **not** rewritten: a Stage 9
report should keep describing Stage 9.

See also: [`conexao-en-translation.md`](./conexao-en-translation.md) (the active
data/stage layer) and [`conexao-translation-rollout.md`](./conexao-translation-rollout.md)
(the shared engine).

_Last verified: 2026-10-02 by Stage 19 — removal of the retired translation plugins_
