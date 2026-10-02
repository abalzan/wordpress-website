# conexao-job-translation (REMOVED — Stage 19)

> **This plugin no longer exists.** Stage 19 removed it from the active
> repository on 2026-10-02. It was a historical one-shot **rollout container**,
> and its authored translation data had already been consolidated into the
> shared translation stages. It was never part of the production steady state,
> was never included in a release ZIP, and no supported runtime path loaded it.

## Why it was removed

Five one-shot rollout plugins each carried their own copy of the rollout
lifecycle. Stage H introduced the shared engine (`conexao-translation-rollout`)
precisely so that a new rollout would not need its own `apply.php` + `audit.php`
+ admin class. This plugin was the last container for Stage 6. With its data
already consolidated, keeping the directory only preserved a duplicate dormant
translation engine — which is the second source of truth the engineering
standard forbids.

## Where the authored data lives now

****nowhere** — this is the one authored dataset with no active stage**


**If job English is ever needed again**, do not resurrect this plugin. Add an
`en-job` stage to `conexao-en-translation` (`includes/job-translation-data.php`
+ a stage config) and recover the authored row from Git history at the Stage 19
parent commit:

```bash
git show <stage-19-parent>:wp-content/plugins/conexao-job-translation/includes/translation-map.php
```

Do **not** delete production job records as part of that work: the retired
plugin's own documentation recorded that its EN jobs carry a verbatim `_job_*`
meta layer and shared media that the manifest alone does not reconstruct.
## Data-preservation proof (measured, Stage 19)

This is the accepted, recorded gap of Stage 19. `job` is a B2 post type, none of the seven active stages covers job *records* (`en-jobs-page` is the Jobs **landing page**), and the stage declared `allow_remove => false`. The operator authorised retiring it with the plugin rather than standing up an `en-job` stage, so its single authored row survives only in Git history.

The executable proof is
[`docs/evidence/2026-10-02-stage-19/data-preservation-proof.php`](../evidence/2026-10-02-stage-19/data-preservation-proof.php),
run inside the real WordPress container; its output is
`docs/evidence/2026-10-02-stage-19/02-data-preservation-proof-BEFORE.txt`.

## Historical provenance

The implementation is recoverable in full from Git history:

```bash
git log --oneline -- wp-content/plugins/conexao-job-translation
git show <stage-19-parent>:wp-content/plugins/conexao-job-translation
```

The stage reports under [`docs/reports/`](../reports/) describe the architecture
as it was when the stage ran, and are deliberately **not** rewritten: a Stage 9
report should keep describing Stage 9.

See also: [`conexao-en-translation.md`](./conexao-en-translation.md) (the active
data/stage layer) and [`conexao-translation-rollout.md`](./conexao-translation-rollout.md)
(the shared engine).

_Last verified: 2026-10-02 by Stage 19 — removal of the retired translation plugins_
