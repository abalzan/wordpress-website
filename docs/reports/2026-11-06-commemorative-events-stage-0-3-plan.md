# Plan — Commemorative Events architecture r10, Stages 0–3

| | |
|---|---|
| **Task title** | Implement the Commemorative Events Architecture Proposal r10: data model, scheduling engine, administration + seed migration, secure preview + cache handling |
| **Date** | 2026-11-06 |
| **Author / agent** | Cline (implementation) |
| **Branch** | `commemorative-events` (HEAD `50ae318`) |
| **Repository baseline (starting SHA)** | `50ae318b473da7cc973a08855e696130e8ee73f7` (clean worktree, 9 stashes preserved) |
| **Applicable skill(s)** | `wp-add-content-type`, `wp-add-admin-screen`, `wp-write-in-process-test`, `wp-plugin-registry`, `wp-security-review`, `wp-update-docs` |
| **Plan status** | approved (r10 is the approved specification; Stage 0 is its decision gate) |

## 1. Scope

Implement roadmap **Stages 0–3** of
[`docs/reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](2026-11-03-commemorative-events-architecture-proposal-r10-revised.md):

- **Stage 0** — lock the decisions: a new **platform plugin**
  `conexao-commemorative` (the recommended option in §2.3), the `commemorative_event`
  CPT in `conexao-data-model`, the frozen public API (`conexao_commemorative_active()`,
  the `conexao_commemorative_presentations` filter) and the field set including
  `_ce_year_precedence`.
- **Stage 1** — the clock-injected scheduling engine: four rule types, inclusive
  boundaries, the 31 December wrap, occurrence-year identity, priority and the
  manual annual preference.
- **Stage 2** — events become data: CPT + registered meta + sanitizers, the
  `conexao-admin-ux` list/editor entry, the engine diagnostics box, the
  idempotent dry-run-first seed of the six legacy occasions, Polylang PT/EN
  records with the shared configuration stored once, REST lockdown.
- **Stage 3** — secure administrator preview (signed, conjunctive capability
  check, cache bypass) and the activation-boundary cache handling (one armed
  boundary, per-request due check, targeted invalidation, re-arm chain, lock,
  wp-admin purge action).

## 2. Explicit non-goals

- **Stages 4–6** are not implemented: the experience-package **dispatcher**, the
  Halloween multi-placement migration and visual refresh, the second package and
  its landing page, the content playbooks and the new permanent gates. They
  depend on Stage 3 acceptance and are a separate change. The theme therefore
  registers the six package **identities** (labels only) and the legacy
  Halloween presentation keeps rendering exactly as before.
- The theme's legacy `inc/occasion.php` engine and the Halloween partial/assets
  are **not modified**. Migrating the front end onto the engine is Stage 4 work;
  doing it now would change public rendering without the dispatcher.
- No public override (`_ce_force_until`), no `allow_concurrent`, no collision
  dashboard, no Carnival date formula — all explicitly deferred by r10.
- No commit, no push, no deploy, no production write, no production cache
  verification (§4.6 point 8 is a separately authorised activity).
- No unrelated refactor: the only shared-code edits are the additive admin-ux
  config/renderer hooks and the map entries needed for a new managed type.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`,
`docs/routing.md`, `docs/testing.md`, `docs/releases.md`, `plugins.json`,
`scripts/README.md`, `docs/themes/conexao-br-irlanda.md`, and the r10 proposal.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-add-content-type` (primary), plus `wp-add-admin-screen`, `wp-write-in-process-test`, `wp-plugin-registry`, `wp-security-review`, `wp-update-docs` |
| **Its preconditions** | the content model and routing documents read; the proposal approved; the plugin registry understood as the single source of truth |
| **Its guardrails that bind this task** | PT content immutable; no second source of truth; sanitizer on every write path; capability + nonce on every admin action; no production write |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check`, `python3 scripts/verify-permanent-gates.py` |
| **Its evidence location** | `docs/evidence/2026-11-06-commemorative-events/` |

## 4. Current-state findings

- The date engine lives in the theme (`inc/occasion.php`, 318 lines), has no
  configuration surface, pins the occurrence to `now.year` (so New Year can
  never appear 26–31 December, W2), cannot express a wrapping window (W3/W4),
  resolves the primary event by array order (W5) and has no injectable clock
  (W6).
- There is **no** `commemorative_event` post type, no `_ce_*` meta, no
  commemorative admin screen and no commemorative cache layer in the repository.
## 5. Proposed approach

**Option chosen (§2.3 recommended):** a new platform plugin
`conexao-commemorative` that depends on `conexao-data-model`, plus the CPT and
meta in the data model and the list/editor entry in the admin UX.

Rejected: putting the engine inside `conexao-data-model` (cheaper, but mixes data
ownership with behaviour), and keeping the engine in the theme (it must be
deployable and admin-operable without the theme).

Engine internals (files named after their classes, as the coding standard requires):
`class-conexao-commemorative-schedule.php` (pure date maths, injected clock),
`-repository.php` (records + canonical-language resolution), `-resolver.php`
(eligibility, ranking, reasons), `-cache.php` (targeted invalidation + boundary
scheduling), `-preview.php` (authorisation + cache bypass), `-admin.php`
(diagnostics box, precedence control, columns, purge action), `-migration.php`
(seed), `-event.php` (the value object) and `class-conexao-commemorative.php`
(the bootstrap class).

Implementation note: WordPress loads active plugins in an order that activation
sorts alphabetically, so no plugin may reference another plugin's classes at
file-load time. The engine therefore boots its components on `plugins_loaded`.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-commemorative/**` | new plugin | the engine (§2.3) |
| `wp-content/plugins/conexao-data-model/includes/class-commemorative.php` | new | the `_ce_*` schema + sanitizers (§3) |
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | CPT + meta registration, version 1.7.0 | §3.2 |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | config entry | §5.2/§5.3 |
| `wp-content/plugins/conexao-admin-ux/includes/class-list.php` | one render filter | the engine answers the computed columns |
| `wp-content/plugins/conexao-admin-ux/includes/class-editor.php`, `class-fields.php` | map entries (`_ce_name`) | virtual title field |
| `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` | new | theme-owned package registry + preview banner |
| `wp-content/themes/conexao-br-irlanda/functions.php` | one `require_once` | load the registry |
| `scripts/seed-commemorative-events.php` | new | §8.2 seed |
| `plugins.json`, `compose.yaml`, `.github/workflows/ci.yml`, generated regions | registry entry + mount + CI activation | `wp-plugin-registry` |
| `docs/plugins/conexao-commemorative.md`, `docs/content-model.md`, `docs/README.md`, `docs/reports/README.md` | docs | `wp-update-docs` |

## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/inc/occasion.php`,
  `template-parts/halloween-hero.php`, `assets/js/main.js`,
  `assets/css/main.css` — the legacy presentation is Stage 4 work.
- `wp-content/themes/conexao-br-irlanda/inc/i18n/guard.php` — the CPT is an
  admin-only container; registering it as a translated post type belongs with the
  Stage 4 dispatcher decision.
- Any other plugin, the Flutter/mobile repository, `dist/`, production state.

## 8. Content / data impact

- Records created: **6** `commemorative_event` records (+ their linked EN
  records) by `scripts/seed-commemorative-events.php --apply`, locally only.
- Portable identifier: `_ce_event_key` (never a local post ID).
- The six-step content-change contract applies to the seed: inventory (the
  legacy registry) → dry-run plan → apply → verify → numeric gate. PT content of
  every existing record is untouched.

## 9. Route / HTTP impact

- No route added: the CPT has no rewrite, no archive, no query var, no sitemap
  entry. No redirect changes. No HTTP acceptance row added (there is no public
  surface yet).

## 10. Polylang / English impact

- The seed assigns the canonical language to each PT record and creates the EN
  record with the translated title + `_ce_event_key` only.
- PT content immutable: the seed creates new records only.
- Language-scoped cache keys: the purge clears this plugin's transients in every
  language.
- Completeness gate: not applicable (no public translated content yet).

## 11. Security impact

- Capabilities: `edit_post` for the precedence control and the preview link;
  `manage_options` **and** `edit_post` for preview authorisation;
  `manage_options` + nonce for the admin purge action.
- Nonces: `conexao_commemorative_precedence`, `conexao_commemorative_preview|<key>`,
  `conexao_commemorative_simulate`, `conexao_commemorative_purge`.
- Sanitisation: every `_ce_*` key is registered with a `sanitize_callback`;
  output escaped in every renderer; the preview link is built with
  `add_query_arg`/`esc_url`.
- SQL: none added (WP APIs only; the migration test reads `$wpdb->postmeta` with
  a prepared statement).
- REST: `_ce_*` meta is `show_in_rest => false`; the CPT is REST-enabled only for
  editor plumbing.
- Secrets: none.

## 12. Performance impact

- Records read once per request (`get_posts`, cached per request in the
  repository). No transient caching of eligibility.
- The due-boundary check reads one option per request and performs no query
  unless the boundary has passed.
- No front-end assets are added (Stage 4 owns asset loading).

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | nobody in this task |
| What is the verification of the production result? | none claimed; the cache guarantee is documented as Option B until separately verified (§4.6 point 8) |

## 14. Test plan

- Six in-process suites under `wp-content/plugins/conexao-commemorative/tests/`:
  schedule (56 assertions), resolution (26), cache/boundary (40), admin (41),
  preview (20), migration (26).
- Script contract: `python3 tests/scripts/verify-script-conventions.py`.
- Registry drift: `php scripts/generate-registry-docs.php --check`.
- Gates: `python3 tests/scripts/verify-documentation-drift.py`,
  `python3 tests/scripts/verify-i18n-freshness.py`,
  `python3 scripts/verify-permanent-gates.py`.
- Pass = every suite `0 failed`, every gate `pass` (or a documented, explained
  limitation).

## 15. Acceptance matrix plan

No HTTP acceptance rows: the CPT has no public route in this stage. The negative
proof is asserted in-process (no rewrite, no archive, no query var).

## 16. Rollback plan

1. Deactivate/remove `conexao-commemorative` (its records stay but nothing reads
   them).
2. Delete the six seeded records + their translations (the seed is idempotent and
   never recreates a recorded key).
3. Revert `plugins.json` and re-run `php scripts/generate-registry-docs.php --write`.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/plugins/conexao-commemorative.md` | new plugin page | — |
| `docs/content-model.md` | the `commemorative_event` section | — |
| `docs/README.md`, `docs/reports/README.md` | index entries | — |
| `scripts/README.md` | the seed row | — |
| generated regions | regenerated from `plugins.json` | `wp-plugin-registry` |

## 18. Release implications

- Component versions bumped: `conexao-data-model` 1.6.3 → **1.7.0**,
  `conexao-admin-ux` 1.0.8 → **1.0.9** (asset cache-busting constant too), new
  `conexao-commemorative` **1.0.0**.
- The artifact allowlist is derived from `plugins.json` (`build: true`), so a
  future release now includes the new plugin. **No release is in scope here.**

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Two scheduling engines temporarily coexist (theme legacy + new plugin) | certain | documented; the front-end migration is Stage 4; the new engine is authoritative for CPT records |
| Admin-ux shared code changes affect other types | low | the edits are additive and the existing admin suites still pass |
| Polylang copies shared config onto EN | medium | the seed writes identity only; the migration suite asserts the DB split |
| Cache guarantee overstated | medium | the documented guarantee is Option B; no exact activation is claimed |
| Scope creep into the presentation framework | high | Stages 4–6 are explicitly out of scope |

## 20. Verification gates

1. `./scripts/run-tests.sh --only conexao-commemorative`
2. `./scripts/run-tests.sh --php`
3. `./scripts/lint.sh`
4. `php scripts/generate-registry-docs.php --check`
5. `python3 scripts/verify-permanent-gates.py`

## 21. Completion criteria

Every applicable item of `docs/engineering-standard.md` §14 holds, the suites
above are green with real output, and the report states what remains
(Stages 4–6, production cache verification) as a limitation rather than a pass.

_Last verified: 2026-11-06 by the commemorative-events implementation_


