# Plan — Phoenix Park UUID repair (Option A)

| | |
|---|---|
| **Task title** | Fix the coordinate resolver's shared-UUID matching order (no data-model change) |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Repository baseline (starting SHA)** | `80340da8eb3ea9b96f37d6bf3b264bce28fc04c3` |
| **Applicable skill(s)** | `wp-content-change`, `wp-repository`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved by the owner ("I approve Option A") |

## 1. Scope

Change `conexao_leisure_coord_resolve()` in `scripts/apply-leisure-coordinates.php`
so that a `_leisure_export_uuid` match returning more than one record is
disambiguated by the manifest entry's **slug**, then its **language**, and fails
safely (`uuid_ambiguous`, nothing written) when neither resolves it. Add the
seven regression tests, fix the `_lazer_uuid` documentation drift, and correct
the earlier report's incorrect "identity defect" framing.

## 2. Explicit non-goals

- **No UUID value change.** 10150 and 115357 keep sharing
  `ci-fixture-0000-0000-4000-8000-000000000002`.
- **No new identity model**, no schema change, no meta-key change.
- **No coordinate, provenance or `_leisure_coord_verified` change.**
- No change to the Lazer exporter/importer contract or its tests.
- No production action; the Flutter/mobile repository stays frozen and out of scope.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §0.2/§0.4/§5.2/§8/§9;
`docs/content-model.md` (Import/Export Identifiers);
`docs/reports/2026-11-03-phoenix-park-uuid-investigation.md`;
`docs/plugins/conexao-leisure-migration.md`; `scripts/README.md`;
`wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php`
(the contract this change must not break).

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-content-change` (with `wp-write-in-process-test` for the new suite) |
| **Its preconditions** | A written plan with the content/data impact answered and the portable-identifier strategy stated |
| **Its guardrails that bind this task** | Inventory first; manifest keyed on the portable identifier; dry-run before apply; snapshot before write; idempotent apply; numeric gate; never key on a local post ID; no production write |
| **Its verification commands** | the new suite, `test-language-uuid.php`, `test-stage32-bilingual.php`, the two coordinate suites, `./scripts/run-tests.sh`, `./scripts/lint.sh` |
| **Its evidence location** | `docs/evidence/2026-11-03-leisure-coordinate-research/` |

## 4. Current-state findings

`conexao_leisure_coord_resolve()` used `$wpdb->get_var(... LIMIT 1)` on
`_leisure_export_uuid`, so a UUID shared by a PT master and its EN translation
always returned the FIRST post. With the manifest carrying one entry per record,
both entries resolved to the same post: the second was a no-op (`unchanged`) and
the EN sibling never received a coordinate. Measured before the change: 294
published leisure, 290 with a UUID, 289 distinct values, 1 duplicated value (the
Phoenix Park pair).

## 5. Proposed approach

1. `$wpdb->get_col(...)` to collect EVERY post carrying the UUID.
2. Exactly one → `stable-id` (unchanged behaviour).
3. More than one → try the entry's slug (`stable-id+slug`), then its language
   (`stable-id+lang`), narrowing the candidate set at each step.
4. Still more than one → return `WP_Error('uuid_ambiguous', …)`; the runner
   counts it as `unresolved`, increments `errors` and exits non-zero, writing
   nothing for that entry.
5. Log the error CODE as well as the message so `uuid_ambiguous` is greppable.
6. Carry `lang` into the portable manifest (it was already in the research
   manifest) so the language fallback has an input.

**Rejected:** giving the EN sibling its own UUID (contradicts three tests and
creates two identities for one attraction); deleting the EN sibling (removes
Stage 4.1 REST coverage).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/apply-leisure-coordinates.php` | modified | the resolver, the ambiguity error, the log code, the two new strategy counters |
| `wp-content/plugins/conexao-leisure-migration/tests/test-leisure-coordinate-resolver.php` | added | the regression suite (tests 1–4 below) |
| `scripts/data/leisure-coordinates-selftest.json` | modified | shared-UUID ambiguity fixture |
| `scripts/data/leisure-coordinates-2026-11-03.json` | regenerated | carries `lang` per entry |
| `docs/content-model.md` | modified | `_lazer_uuid` → `_leisure_export_uuid` + per-attraction semantics |
| `scripts/README.md` | modified | the resolver's shared-UUID behaviour |
| `docs/plans/…`, `docs/reports/…` | added | plan + report |

## 7. Files explicitly expected NOT to change

`wp-content/plugins/conexao-leisure-migration/includes/**` (the exporter,
importer and language guard are correct and untouched),
`wp-content/plugins/conexao-data-model/**`, the theme, `plugins.json`,
`compose.yaml`, `tests/**` (no existing test is weakened), and every
`_leisure_export_uuid` / coordinate value in the database.

## 8. Content / data impact

- Records created / updated / deleted: **0** expected; the repair changes tool
  behaviour, not data.
- Portable identifier strategy: `_leisure_export_uuid` (attraction-level) → slug
  → language → title, with the per-strategy counts reported.
- Six-step contract: inventory (the read-only investigation) → plan (this file)
  → dry-run → snapshot → apply → verify + numeric gate.
- PT content changed? **No.** No UUID, coordinate, provenance, taxonomy or
  translation change.

## 9. Route / HTTP impact

None. No route, redirect, canonical, hreflang or sitemap change; the REST
surfaces are untouched.

## 10. Polylang / English impact

- The shared-UUID contract is **preserved and now enforced by a test** in the
  coordinate path as well.
- PT/EN immutability: the translation relationship is asserted unchanged.
- No language-scoped cache key changes.

## 11. Security impact

- `$wpdb->prepare()` for the UUID lookup; WP APIs elsewhere.
- No capability, nonce or REST change; no secret.

## 12. Performance impact

One `get_col` instead of one `get_var` per manifest entry, plus at most a few
`get_post()` calls only when a UUID is duplicated (one record in the whole
dataset). Negligible; the tool runs offline.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody |
| What is the verification of the production result? | out of scope; not performed |

## 14. Test plan

1. New in-process suite: shared UUID → two different posts by slug; ambiguity →
   non-zero exit + `uuid_ambiguous` + nothing written; apply + idempotence;
   UUID, translation link and verification flag unchanged.
2. `conexao-leisure-migration/tests/test-language-uuid.php` unchanged and green.
3. `theme/tests/test-stage32-bilingual.php` unchanged and green.
4. `theme/tests/test-leisure-location-services.php` and
   `plugin/conexao-admin-ux/tests/test-leisure-coordinates.php` green.
5. `./scripts/run-tests.sh` — no new failures against the recorded baseline.
6. Self-test manifest dry run shows `uuid_ambiguous` for the real pair.
7. Numeric pass: `written=0` on the real manifest, `gate=0`, snapshot diff empty.

## 15. Acceptance matrix plan

No new HTTP row: the change is offline tooling, and the `/lazer` surface is
already covered by the existing theme suite.

## 16. Rollback plan

Snapshot the coordinate/UUID state before the change (already captured in
`docs/evidence/…/pre-apply-snapshot.json` and `post-apply-snapshot.json`); revert
`scripts/apply-leisure-coordinates.php` and delete the new test file. Rollback
does **not** cover: nothing in the database, because nothing was written.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/content-model.md` | `_lazer_uuid` → `_leisure_export_uuid`; per-attraction semantics; consumer obligations | — |
| `scripts/README.md` | the resolver's shared-UUID behaviour and new strategy counts | — |
| `docs/reports/2026-11-03-phoenix-park-uuid-repair.md` | new report | — |
| `docs/README.md`, `docs/reports/README.md` | index the report | — |

## 18. Release implications

No component version bump: no plugin or theme source changes, so no artifact is
affected. No release in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The change alters a coordinate value | low | Dry run first; snapshot diff must be empty; `written=0` on the real manifest |
| The new strictness breaks a legitimate entry | low | Only entries whose UUID is duplicated AND whose slug+language are absent are affected; exactly one such record exists and its manifest entry is REVIEW |
| Scope creep into the Lazer plugin | medium | §7 forbids touching `includes/**`; the plugin's tests must stay green |

## 20. Verification gates

1. New suite: 29 assertions, 0 failures.
2. `test-language-uuid.php` and `test-stage32-bilingual.php`: unchanged, green.
3. Real manifest: `planned=0, written=0, unchanged=289, gate=0`; snapshot diff empty.
4. Self-test manifest: `uuid_ambiguous`, `written=0`, non-zero exit.
5. `./scripts/run-tests.sh` and `./scripts/lint.sh`.

## 21. Completion criteria

Shared UUIDs resolve correctly or fail safely; all seven tests exist and pass;
the drift is fixed; no coordinate, UUID, provenance, verification flag or
Polylang relationship changed; no production action; the report carries real
numbers and states that mobile UUID consumption was not verified.

_Last verified: 2026-11-03 by the Phoenix Park UUID repair_
