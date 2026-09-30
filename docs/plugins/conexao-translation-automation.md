# conexao-translation-automation

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | `conexao-translation-rollout` |
| **Version** | 0.1.0 (authoritative source: `wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

## Purpose

The **permanent** plugin for the automatic PT→EN translation program. Stage 1
establishes only the *invocation boundary*: a thin orchestrator that validates
its invocation context, resolves the **existing** shared translation engine and
delegates to that engine in a non-mutating **proof** mode, returning a
structured result.

It is deliberately **not** the automation program. Stage 1 contains no
translation logic, no provider, no cron, no change detection, no locking and no
apply path.

Owner: project maintainer. Introduced by Stage 1
(`docs/reports/2026-09-30-stage-1-auto-translation-plugin-boundary.md`).

## The ownership boundary (the whole point of this plugin)

| Concern | Owner |
|---|---|
| inventory, manifest validation, dry-run plan | `Conexao_Translation_Rollout_Engine` |
| snapshot, apply, PT-drift guard, verify, numeric gate, remove | `Conexao_Translation_Rollout_Engine` |
| stage configurations (manifest, adapter, `run_callback`) | the stage plugin (`conexao-en-translation`) |
| **authorisation, mode gate, stage allowlist, result contract** | **this plugin** |

This plugin calls exactly one lifecycle function — the stage's own
`run_callback`, which is the engine's supported entry point. It never reads a
manifest, never builds an adapter, never interprets a plan and never recomputes
a gate, so a second mutation authority cannot exist here.

## Files

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-translation-automation/` |
| Entry point | `conexao-translation-automation.php` |
| Orchestrator | `includes/class-conexao-translation-automation-orchestrator.php` |
| Result contract | `includes/class-conexao-translation-automation-result.php` |
| Frontend effect | **none** |
| Admin screen | **none** in Stage 1 |
| REST routes | **none** in Stage 1 |
| Cron hooks | **none** in Stage 1 |
| Tests | `tests/test-automation-boundary.php` |

## Invocation modes

| Mode | Stage 1 behaviour |
|---|---|
| `proof` | The only mode honoured. Forces `dry_run => true`; zero writes by construction. |
| `apply` | **Recognised, hard-disabled.** Always fails closed (`apply_disabled`). |
| *(missing)* | Fails closed (`missing_mode`). **There is no default mode.** |
| *(anything else)* | Fails closed (`unknown_mode`). Never coerced to apply or proof. |

There is deliberately no `missing mode => apply` and no `unknown mode => apply`
behaviour, and no fallback that could reach a mutating path.

## Stage allowlist (Stage 0 control S6)

An explicit, code-level allowlist — not "whatever is registered":

`en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page`,
`en-leisure-description`, `en-course-provider-description`

A stage absent from this list cannot be reached even when another plugin
registers it with the engine.

## Security boundary

| Control | Stage 1 implementation |
|---|---|
| Authorisation | Required and checked **first**, before the engine is resolved |
| Capability | `manage_options`; a mismatched assertion is refused, not downgraded |
| Nonce | No browser/admin entry point exists, so no nonce is accepted or required yet. A future admin path MUST add `manage_options` **and** a nonce, following `Conexao_Translation_Rollout_Admin`. |
| Public endpoints | None. No `register_rest_route`, no `admin_post_*`, no `__return_true` |
| Secrets | `Conexao_Translation_Automation_Result::assert_no_secrets()` refuses credential-shaped keys and values; the source references no credential constant |
| Fail-closed | Every failure path returns `mutation_permitted = false` |

Controls deferred to later stages and **not** claimed here: transport-level
authentication, rate limiting, and audit retention.

## Dependency

Declared as a real WordPress plugin dependency
(`Requires Plugins: conexao-translation-rollout`), so WordPress itself refuses
to activate this plugin without the engine. At run time the boundary also
checks `class_exists()` and fails closed with `missing_engine` when the engine
is absent — no mutation, structured failure.

## Activation safety

The plugin registers **no** activation, deactivation or uninstall hook, and
contains **no** call to `wp_insert_post`, `wp_update_post`, `wp_delete_post`,
`wp_insert_term`, `wp_update_term`, `update_option`, `add_option`,
`wp_update_nav_menu` or any `pll_*` write. Activation is inert. This is
asserted by the test suite rather than merely documented.

## Why `production` and `build` are false (B1)

The engine this plugin depends on is itself `class: tooling`,
`production: false`, `build: false`. Setting this plugin to `build: true` would
put it in a release ZIP whose declared dependency is **not** in that release,
and setting `production: true` would require activating a plugin whose
dependency is absent from production — WordPress would refuse, and the
registry forbids `tooling` + `production: true`.

Promotion is therefore a **Stage 2+ decision** that must promote the engine
first. The registry entry is deliberately truthful today; see the Stage 1 report
§8.

## Tests

`tests/test-automation-boundary.php` proves plugin loading, activation safety,
absence of cron/REST/`__return_true`, every fail-closed path (including that the
engine was never reached), engine delegation, result propagation, secret
containment and the shared engine's SHA-256.

Run with `./scripts/run-tests.sh --only conexao-translation-automation`.