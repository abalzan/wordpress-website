# Plan — progressively reduce the PHPStan baseline

| | |
|---|---|
| **Task title** | Progressively reduce the PHPStan baseline (level 5) |
| **Date** | 2026-11-27 |
| **Author / agent** | Cline (senior WordPress/PHP engineer) |
| **Branch** | `reduce-phpstan-baseline` |
| **Repository baseline (starting SHA)** | `ceaa1c0` (merge of PR #10, `master`) |
| **Applicable skill(s)** | `wp-repository`, `wp-testing` |
| **Plan status** | approved |

## 1. Scope

Fix a focused, high-value group of **genuine** PHPStan level-5 findings in
custom plugin domain logic, theme helpers and the analysis bootstrap, then delete
**only** the baseline entries whose underlying problem is now actually fixed.

The work is deliberately narrow: no signature churn, no architecture change, no
level increase, no new suppressions.

## 2. Explicit non-goals

- **Not** regenerating the baseline wholesale. Entries are removed by hand and
  PHPStan is then re-run; PHPStan's own `ignore.unmatched` reports prove that a
  removed entry really became unnecessary.
- **Not** fixing `function.alreadyNarrowedType` / `function.impossibleType` /
  `instanceof.alwaysTrue` defensive checks (`is_array()`, `is_wp_error()`,
  `instanceof WP_Post`). Those are deliberate belt-and-braces guards against
  third-party filters and were introduced knowingly; deleting them would trade
  static tidiness for runtime fragility. They stay baselined.
- **Not** touching the DOM/XML importer source parsers (`DOMNode::getAttribute()`
  / `DOMElement::querySelector()` findings). Correcting those needs
  `instanceof DOMElement` guards across six source classes; that is a real
  hardening task with its own review surface and is recorded as follow-up.
- **Not** raising the analysis level.
- **Not** introducing PHPUnit, a new analyser or any new dependency.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §2.1 (PHP), §8 (testing),
§1.6 (`phpstan.neon.dist` + baseline must not grow); `docs/development.md`
"Quality tooling"; `.agents/skills/wp-repository/SKILL.md`;
`.agents/skills/wp-testing/SKILL.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (plan → build → verify → report), `wp-testing` for the verification commands |
| **Its preconditions** | Clean understanding of which findings are genuine defects vs deliberate defensive guards |
| **Its guardrails that bind this task** | One concern per commit; no behaviour change hidden in a refactor; baseline never grows; never weaken a check to make a change pass |
| **Its verification commands** | `./scripts/lint.sh`, `./scripts/run-tests.sh --only <component>`, `php vendor/bin/phpstan analyse` |
| **Its evidence location** | `docs/evidence/2026-11-27-phpstan-baseline/` |

## 4. Current-state findings

Measured on `ceaa1c0` with `phpstan/phpstan ^2.2` + `szepeviktor/phpstan-wordpress ^2.0`,
level 5, PHP 8.5:

| Measurement | Value |
|---|---|
| Baseline entries (`ignoreErrors` blocks) | **171** |
| Actual suppressed messages (Σ `count`) | **255** |
| Errors outside the baseline | **0** |
| Strictly-unmatched baseline entries | 0 |

Composition of the baseline by identifier (top 8): `function.alreadyNarrowedType`
46, `function.impossibleType` 19, `isset.offset` 18, `argument.type` 17,
`instanceof.alwaysTrue` 13, `empty.variable` 8, `method.notFound` 7,
`class.notFound` 5.

Genuine defects found while reading the flagged code:

1. `conexao-admin-ux/includes/class-wikimedia-client.php:152` —
   `isset( $file['url'] )` reads an **undefined variable** inside a fallback that
   can never change the result (the same value was already tried one line above).
   A latent bug and a dead branch.
2. `conexao-event-importer/includes/class-import-result.php:76` — `$run_id` is
   documented `@var int` but assigned `(string) microtime( true )`, so the
   declared `@return string` of `get_run_id()` contradicts the property type.
3. `conexao-admin-ux/includes/class-editor.php:757` — a dead ternary
   (`'mobile' === $legacy_key`, always false) silently restricted legacy sponsor
   image recovery to the desktop key even though `$state` carries both
   (`'mobile'`, `'desktop'` — built at line 580-581).
4. Nine `esc_attr()` / `esc_html()` calls passing an `int` where a `string` is
   declared (theme + admin + importer).
5. `conexao-admin-ux/includes/class-fields.php` — a private helper
   (`is_event_recurrence_key()`) that existed but was never called, its inline
   duplicate inlined at the call site instead.

Analysis-environment artifacts (not defects): `WP_CLI` and `WP_CLI\Utils` are
defined only when WP-CLI is running; `PLL::$curlang` is a runtime property of
Polylang that is not declared in the committed stub.


## 5. Proposed approach

1. Fix genuine defects with the smallest correct change, preserving runtime
   behaviour:
   - delete the dead wikimedia fallback branch;
   - correct the `$run_id` docblock to `@var string`;
   - restore the intended `$state_key` mapping (correctness fix, called out as its
     own commit);
   - cast `int` → `string` at the nine escaping call sites (escaping already
     coerces; the cast is explicit, not behavioural);
   - route the recurrence-key literal list through the existing helper.
2. Extend the **analysis bootstrap** rather than the baseline for the two
   environment artifacts: add a `WP_CLI` + `WP_CLI\Utils\format_items` stub and a
   `PLL::$curlang` property. Both are capability-guarded, carry no logic and are
   never loaded by WordPress — exactly the mechanism §1.6 documents for symbols
   that cannot exist on disk.
3. Delete precisely the baseline entries that PHPStan now reports as
   `ignore.unmatched`.

**Rejected option:** `--generate-baseline`. It would hide the fact that each
removal corresponds to a real fix and can silently absorb a *new* error.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-admin-ux/includes/class-fields.php` | cast escapable ints; use the existing helper | `argument.type`, `method.unused` |
| `wp-content/plugins/conexao-admin-ux/includes/class-editor.php` | `(string)` cast; correct `$state_key` mapping | `argument.type`, `identical.alwaysFalse` |
| `wp-content/plugins/conexao-admin-ux/includes/class-wikimedia-client.php` | delete dead fallback branch | `variable.undefined`, `isset.variable` |
| `wp-content/plugins/conexao-event-importer/includes/class-import-result.php` | correct `@var` | `assign.propertyType`, `return.type` |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-admin.php` | cast four ints | `argument.type` ×4 |
| `wp-content/plugins/conexao-event-importer/includes/class-event-image-sync-admin.php` | cast one int | `argument.type` |
| `wp-content/themes/conexao-br-irlanda/front-page.php` | cast one int | `argument.type` |
| `wp-content/themes/conexao-br-irlanda/inc/sponsors.php` | cast two ints | `argument.type` ×2 |
| `wp-content/themes/conexao-br-irlanda/single-sponsor.php` | cast two ints | `argument.type` ×2 |
| `wp-content/themes/conexao-br-irlanda/template-parts/hero-events.php` | cast one int | `argument.type` |
| `docs/dev/phpstan-bootstrap.php` | `WP_CLI` + `PLL::$curlang` stubs | `class.notFound` ×5, `function.notFound`, `property.notFound` |
| `phpstan-baseline.neon` | delete the resolved entries | the point of the task |
| `docs/development.md` | record the before/after baseline numbers | §9.2 documentation rule |

## 7. Files explicitly expected NOT to change

`plugins.json` (no plugin set change), `composer.json`/`composer.lock` (no
dependency change), `phpstan.neon.dist` (level and paths unchanged),
`phpcs.xml.dist`, `.github/workflows/ci.yml`, `compose.yaml`, any
`docs/plugins/*.md` content-model claim, and `scripts/`.

## 8. Content / data impact

- Records created / updated / deleted: **none**. No suite here writes content.
- Does this write content? **No.**
- PT content changed? **No.**

## 9. Route / HTTP impact

None. No route, redirect, sitemap or matrix row changes.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none.
- PT immutable: **yes**.
- Language-scoped cache keys affected: none.
- Completeness gate: unaffected.

## 11. Security impact

- Capabilities checked: unchanged.
- Nonces added/changed: **none**.
- Sanitisation / escaping: the nine changes make the *existing* escaping calls
  type-explicit; no call is removed and no output becomes unescaped.
- SQL: unchanged.
- REST surface: unchanged.
- Secrets: none.


## 12. Performance impact

No runtime effect. Removing the dead wikimedia branch removes one `isset()` on
an undefined variable (a no-op today).

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a |
| What is the verification of the production result? | n/a — local static analysis only |

## 14. Test plan

- In-process suites: the whole PHP layer is re-run after the change; the
  affected components are `conexao-admin-ux` and `conexao-event-importer`.
- Acceptance: not applicable (no request-visible change); the acceptance layer is
  re-run to prove no regression.
- Script contract: `./scripts/run-tests.sh --scripts-static`.
- Numeric pass: `phpstan-baseline.neon` entries strictly < 171, PHPStan exit 0,
  and the PHP layer's failing-suite list identical to the baseline run.

## 15. Acceptance matrix plan

None.

## 16. Rollback plan

`git revert` the PR. The baseline file returns to 171 entries and the nine call
sites return to their previous form. No data, option or transient is involved, so
there is nothing else to roll back.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/development.md` | record the new baseline number and the "fix, then delete the entry" rule | — (already owned by `wp-testing`) |

## 18. Release implications

No version bump: no shipped source file changes user-facing behaviour and no
plugin/theme contract changes. Not a release.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A cast changes output (e.g. `0` vs `''`) | Low | Each cast is `(string)` on a value already rendered by `esc_*`, which coerces identically |
| The `$state_key` fix changes sponsor thumbnail behaviour | Medium | It is the *intended* behaviour per the `$state` construction at line 580; called out as its own commit, checked by the plugin's suites |
| Scope creep into the 46 `alreadyNarrowedType` findings | Medium | Explicitly listed as a non-goal |
| A deleted baseline entry was still needed | Low | PHPStan reports `ignore.unmatched`; the run is the proof |

## 20. Verification gates

1. `php vendor/bin/phpstan analyse --memory-limit=2G` → exit 0, no
   `ignore.unmatched`, no unbaselined error.
2. Baseline entry count → strictly less than 171.
3. `./scripts/run-tests.sh --only conexao-admin-ux` → same pass/fail as baseline.
4. `./scripts/run-tests.sh --only conexao-event-importer` → same pass/fail as
   baseline.
5. `./scripts/lint.sh` → exit 0.

## 21. Completion criteria

`docs/engineering-standard.md` §14 holds: static quality green, the PHP suite
green at the same numbers, the baseline demonstrably smaller, the report carrying
real numbers, and no production action.

_Last verified: 2026-11-27 by the PHPStan baseline reduction plan_
