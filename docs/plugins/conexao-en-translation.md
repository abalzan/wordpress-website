# conexao-en-translation

## Purpose

The Stage M EN translation rollout. It closes the **pre-existing EN translation
completeness debt** that the permanent `translation_completeness` gate reports
for the three B1 content types — `guide`, `page` and `post` — by creating one
linked EN translation per eligible public PT record.

It is a **DATA + CONFIG consumer** of the shared
[`conexao-translation-rollout`](conexao-translation-rollout.md) engine, exactly
like the earlier `job` / `guide` / `blog` / `page` rollouts. It owns no
orchestration, no planning and no gate calculation: the engine implements the
content-change contract (inventory → manifest validation → dry-run plan →
snapshot → apply → verify + numeric gate → remove) and this plugin supplies only
the authored English and the WordPress-bound adapter.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-en-translation/` |
| Authored copy | `includes/manifest-data.php` (keyed by PT post type, then PT slug) |
| Adapter + config | `includes/stage-fields.php`, `includes/stage-config.php` |
| Manifest shape | `includes/translation-map.php` |
| Runner | `scripts/run-en-translation.php` |
| Admin screen | none (the rollout is operated through the shared engine's screen and the runner script) |
| Frontend effect | **none** — it creates EN content records only; no template, route or runtime hook |
| Safe to deactivate | yes, after the rollout has been applied and verified |

## Why this stage exists

Stage L installed a permanent, fail-closed completeness gate
(`test-translation-completeness.php`) that measures
`eligible public PT <type> missing EN = 0`. Against the dataset it correctly
reported **72 missing EN translations** as pre-existing debt:

| Post type | Eligible PT | Translated | Missing EN | Policy |
|---|---|---|---|---|
| `guide` | 53 | 52 | 1 | B1 |
| `page` | 44 | 4 | 29 | B1 (11 are B2-allowlisted by policy) |
| `post` | 42 | 0 | 42 | B1 |

The B2 directory types (`event`, `leisure`, `sponsor`, `course_provider`,
`job`) are **not** in this stage: they are already covered by the documented B2
policy (`conexao_b2_post_types()`), which the gate reports explicitly. Adding
them here would create a second, competing policy — exactly what
`docs/routing.md` forbids.

## The rules this stage must not break

- **Portuguese is immutable.** A PT record is only ever read. The engine
  re-captures a full PT snapshot after an apply and reports any difference as
  `PT drift`, which fails the numeric gate.
- **English is a layer, never a fork.** Every EN record is created as a
  *linked translation* of the PT master and the pair is verified in **both**
  directions (`pll_get_post(pt,'en') === en` and `pll_get_post(en,'pt') === pt`).
- **The PT slug is the only portable identity.** The manifest is keyed by PT
  slug; local post IDs are never used as cross-environment identity.
- **The EN body is authored English**, never a copy of the PT body.
- **Shared proper-name terms stay shared.** `conexao_county` and `conexao_town`
  are copied as the *same physical term*; `conexao_category` is linked to its EN
  counterpart through Polylang, never duplicated.
- **No second engine.** Everything lifecycle-related is the shared engine's.

## The numeric gate

```
eligible public PT <type> = N
with EN                  = N
missing EN               = 0     <-- the invariant
conflicts                = 0
PT drift                 = 0
GATE                     = PASS
```

A conflict is a *hard stop*, never a silent overwrite: an EN slug already used
by an unlinked record, or an existing EN record whose pair link is broken, is
reported and left alone for a maintainer to resolve.

## Rollback

`allow_remove: true`, so the rollback is a first-class operation:

```bash
php scripts/run-en-translation.php --remove --apply --only=guide
```

It deletes only the EN records this manifest owns and re-asserts that the PT
originals are unchanged. It can never touch a PT record.

## Verification

```bash
php scripts/run-en-translation.php --dry-run          # plan, zero writes
php wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php
```

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | `conexao-translation-rollout` |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

_Last verified: 2026-09-26 by Stage M — Permanent Invariant Debt Remediation_
