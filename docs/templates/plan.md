# Plan — <task title>

> **This is a template.** Copy it to
> `docs/reports/<date>-<stage>-<slug>.md` (or the location the standard requires
> for your work) and fill it in. Do **not** edit this file to turn it into a
> repository-specific plan, and do not leave a filled-in plan at this path.
>
> Required by [`docs/engineering-standard.md`](../engineering-standard.md) §13.2
> before multi-file work, and before anything that changes **content, routes or
> English/Polylang**. A short task may answer the headings inline, but it may
> not skip the impact sections — an unstated impact is the thing this document
> exists to prevent.

| | |
|---|---|
| **Task title** | |
| **Date** | |
| **Author / agent** | |
| **Branch** | |
| **Repository baseline (starting SHA)** | |
| **Plan status** | draft \| approved \| superseded |

## 1. Scope

What this task does, in the active voice. One paragraph or a short list.

## 2. Explicit non-goals

What this task deliberately does **not** do, and why. Name the tempting
adjacent work you are refusing so a reviewer does not read it as an oversight.

## 3. Relevant authoritative documents

The documents that constrain this change, read before starting. At minimum,
`AGENTS.md` and `docs/engineering-standard.md`; add the specific ones
(`docs/routing.md`, `docs/content-model.md`, the plugin doc, `docs/releases.md`,
`scripts/README.md`).

## 4. Current-state findings

What the repository actually does today, verified by reading the code or running
a command — not what is assumed. Include the current behaviour, the current
numbers, and the baseline commit.

## 5. Proposed approach

The design, in enough detail that a reviewer can disagree with it before work
starts. State the chosen option **and the options rejected**, with the reason.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|

## 7. Files explicitly expected NOT to change

Name the paths a reviewer will check, and why they must stay untouched
(e.g. `wp-content/plugins/*` if this is a documentation change, or
`plugins.json` if the plugin set does not change).

## 8. Content / data impact

- Records created / updated / deleted: ______
- Portable identifier strategy (never local post/attachment IDs): ______
- Does this write content? If yes, the six-step contract (§5.2) applies and the
  dry-run output must be captured as evidence.
- PT content changed? **It must not, unless this plan explicitly says so and
  explains why.**

## 9. Route / HTTP impact

- Routes added, removed or changed: ______
- Redirects needed (and where precedence is decided): ______
- Sitemap coverage: ______
- HTTP matrix rows to add or update: ______

## 10. Polylang / English impact

- Translated post types / taxonomies affected: ______
- Is PT immutable in this change? **Yes, unless explicitly justified below.**
- B1 (302 → PT) or B2 (PT body under EN shell + notice) for each destination: ______
- Canonical, hreflang and language-switcher behaviour: ______
- Language-scoped cache keys affected: ______
- Completeness gate that must reach `0` (or an explicit allowlist): ______

## 11. Security impact

- Capabilities checked: ______
- Nonces added/changed: ______
- Sanitisation / escaping: ______
- SQL: prepared statements or WP APIs: ______
- REST surface change (new endpoint = explicit decision): ______
- Secrets involved (must be none, and from the environment only): ______

## 12. Performance impact

- Queries added or removed (and the resulting per-page count): ______
- Caching: new/changed transient keys, and their invalidation: ______
- Assets or images affected: ______
- Expected measurable improvement, and how it will be measured: ______

## 13. Production impact

State explicitly, for each:

| Question | Answer |
|---|---|
| Will anything be written to production? | yes / **no** |
| Will a deploy, upload or activation happen? | yes / **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | |
| What is the verification of the production result? | |

## 14. Test plan

- In-process suite(s) to add or update (`<component>/tests/test-*.php`): ______
- Acceptance area / matrix rows: ______
- Script contract impact: ______
- Registry drift check (if `plugins.json` changes): ______
- What constitutes a pass, numerically: ______

## 15. Acceptance matrix plan

The specific rows to add, with the schema fields
(`id`, `url`, `expect_status`, `expect_contains`, `expect_absent`, `note`) and
the exact assertion counts expected.

## 16. Rollback plan

How to undo this change, in order, and what rollback does **not** cover
(content changes need their own reversal). Include the pre-change state that
must be captured first (snapshots, counts, previous ZIPs).

## 17. Documentation plan

Which documents change, per the `wp-update-docs` change → document map:

| Document | Change |
|---|---|

## 18. Release implications

- Component version bump(s) and where (the component header, not the registry): ______
- Effect on the artifact allowlist (derived from `plugins.json`): ______
- Is a release in scope? If not, say so explicitly.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|

Include at least one risk of **doing too much** (scope creep into unrelated
refactoring).

## 20. Verification gates

The executable checks that must pass before this task is complete, each with the
command that produces a number:

1. ______
2. ______

## 21. Completion criteria

The conditions that make this task done — restated from
`docs/engineering-standard.md` §14, not invented. The task is not complete
until every applicable item holds and the report contains real numbers.
