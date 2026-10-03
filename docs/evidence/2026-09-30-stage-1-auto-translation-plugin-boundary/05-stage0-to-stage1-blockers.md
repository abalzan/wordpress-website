# Stage 0 → Stage 1 blocker ledger

| Blocker | Stage 0 status | Stage 1 status | Evidence |
|---|---|---|---|
| **B1** release/activation path for a new permanent production plugin | OPEN | **PARTIALLY RESOLVED** | The plugin exists, is registered, packages deterministically and activates inertly. Promotion to `production: true` / `build: true` is **blocked on promoting `conexao-translation-rollout`**, which is still `tooling` / `production: false` / `build: false`. |
| B2 WordPress.com pv-cron reliability | OPEN | **Not started (by design)** | Stage 1 introduces no cron. The trigger model is still undecided. |
| B3 no concurrency lock primitive | OPEN | **Not started (by design)** | No lock exists; apply is unreachable. |
| B4 no translation-provider interface | OPEN | **Not started (by design)** | No provider, not even an interface. |
| B5 no change-detection contract | OPEN | **Not started (by design)** | No persisted diff state, no trigger. |
| B6 B2 protected meta unreadable over REST | OPEN | **Not started (out of scope)** | Confirms the in-WordPress entry point chosen in Stage 0. |

## Why B1 is only partially resolved — the actual finding

Stage 0 recorded that *no `production: true` tooling plugin has ever been
produced by this repository*. Stage 1 established a stronger constraint, read
from `plugins.json` and `scripts/generate-registry-docs.php`:

1. `validate_lifecycle_rules()` **forbids** `class: tooling` together with
   `production: true`. A permanent automation plugin is therefore not `tooling`
   if it is ever to be production — it must become `platform`, which in turn
   means its dependency must be production too.
2. `build: true` places the plugin in the release allowlist
   (`scripts/lib/release.py:build_slugs`). Because this plugin declares
   `Requires Plugins: conexao-translation-rollout`, shipping it alone would
   produce a ZIP whose declared dependency is absent from the same release.
   WordPress would then refuse to activate it.
3. Therefore **promoting the automation plugin requires promoting the shared
   engine first**. That is a single deliberate registry change plus a release,
   not something Stage 1 could assert on the engine's behalf.

Stage 1 consequently registers the plugin truthfully as
`tooling` / `active` / `production: false` / `build: false` / `mount: true`, and
proves the packaging path out-of-band using the shared packager so that
promotion is a known, tested one-line registry change later.

**Not claimed:** that the plugin can be installed or activated in production.
That requires promoting the engine and an operator performing the upload.