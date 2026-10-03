# Add an admin screen

## Purpose

Make a maintainer capability operable through wp-admin, with capability, nonce,
read-only rendering, a dry-run preview and honest result reporting — because
production has no CLI, so anything operated in production needs an admin screen.

## When to use

A capability must be operable by a maintainer through wp-admin — a rollout
control, a bulk edit, a diagnostic, an import trigger, a status filter. This
skill exists because **production has no CLI**: anything operated in production
needs an admin screen, not a shell command.

Use `wp-write-in-process-test` for the test layer and `wp-security-review`
before shipping.

## When not to use

- A capability that is a *script* only and never needs a production operator.
- A post type, taxonomy or meta schema change — `wp-add-content-type`.
- A content-write contract — `wp-content-change` (the screen implements it; the
  six-step rules are defined there).
- Tests or acceptance rows alone.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §4.2 (plugin file contract and the
  capability/nonce/dry-run rules) and §5.2 (the content-change contract).
- `docs/plugins/conexao-admin-ux.md` — the plugin that owns admin UX.
- `wp-content/plugins/conexao-admin-ux/includes/class-admin.php` and
  `wp-content/plugins/conexao-admin-ux/includes/class-list.php` — the existing menu, screen and list-table
  conventions to extend.
- `docs/releases.md` §Production constraints — why no WP-CLI exists.

## Authoritative sources

| Fact | Read it from |
|---|---|
| Plugin/capability/nonce/dry-run file contract | `docs/engineering-standard.md` §4.2 |
| The six-step content-change contract the screen implements | `docs/engineering-standard.md` §5.2 |
| Existing admin UX conventions to extend | `docs/plugins/conexao-admin-ux.md` + the plugin itself |
| Why there is no WP-CLI in production | `docs/releases.md` §Production constraints |
| The shared plan shape | `scripts/lib/plan.py` |

## Preconditions

- The ownership boundary is known: does this belong to `conexao-admin-ux`, or to
  the rollout engine's own admin class?
- A plan exists with the security and production impact sections answered.
- The capability genuinely must exist in production; if it cannot be an admin
  screen, that is a limitation to state, not an excuse for an SSH path.

## Steps

1. **Confirm the ownership boundary.** Admin UX belongs to
   `conexao-admin-ux`. Check its `wp-content/plugins/conexao-admin-ux/includes/` classes first: the new screen is
   usually a new class in that plugin, or an extension of an existing one, not a
   new top-level file elsewhere. Put a rollout's *orchestration* in
   `conexao-translation-rollout` (`wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-admin.php`
   is the worked example) and its *engine* in the engine class — never both in
   one place.
2. **Place the screen.** Under the CPT menu for editing UX, or under Tools for
   rollout/diagnostic tooling. Follow the existing `add_submenu_page()` calls in
   `wp-content/plugins/conexao-admin-ux/includes/class-admin.php`; do not introduce a new top-level menu.
3. **Capability check, always.** Use the plugin's existing capability constant
   (or `current_user_can()` with an explicitly named capability) on **every**
   entry point: the render callback, every AJAX/REST handler and every action.
   Reading the page without the capability must return the standard WordPress
   "no permission" response — never a partial render.
4. **Nonce check, always, on writes.** Every state-changing path verifies
   `check_admin_referer()` / `wp_verify_nonce()` (AJAX: `check_ajax_referer()`)
   before touching anything. A write path with no nonce is a defect even if the
   capability is correct.
5. **GET and page load must not write.** A page render — including a list table
   sorting, a filter dropdown and a `?page=…` view — must be read-only. Writing
   during page load makes a refresh a mutation and is a hard defect. Writes happen
   only in an explicit POST/handler branch.
6. **Make writes explicit and previewable.** Follow §5.2: the screen offers a
   **dry-run preview** (the plan: create / update / skip-with-reason / conflicts)
   before any write, and the write itself is a distinct, confirmed action. See
   `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-admin.php`
   for Preview → Apply → Remove, and `scripts/lib/plan.py` for the shared plan
   shape.

7. **Escape and sanitise.** Sanitise on input, escape on output — `esc_html()`,
   `esc_attr()`, `esc_url()`, `wp_kses_post()` for rich text. The list table
   follows the existing `wp-content/plugins/conexao-admin-ux/includes/class-list.php` conventions. Notices use
   `add_settings_error()`-style output rather than raw HTML.
8. **Notices and result reporting.** A completed write reports counts and the
   numeric gate. A blocked or dry-run-only run says so visibly and exits
   non-zero. Never claim success for a partial result.
9. **Test it.** Add an in-process suite under the owning plugin's `tests/`
   (see `wp-write-in-process-test`) and, for a user-visible screen change, an
   admin UX regression run. Update `docs/plugins/conexao-admin-ux.md`.

## Guardrails

- **Production is WordPress.com.** No SSH, no WP-CLI, no filesystem, no database
  access. Anything not operable in wp-admin does not exist in production; if a
  capability genuinely cannot be an admin screen, say so explicitly in the report
  as a limitation rather than inventing an unmaintainable path.
- An admin write path verifies **both** nonce and capability, always.
- Activation must validate dependencies and never write content; deactivation
  removes hooks only and never deletes data.
- No `extract()`, no dynamic includes from user input, no `eval`, no `@`
  error suppression, no `var_dump`/`error_log` in shipped code.
- No credentials in the screen or in code — they come from the environment
  (`WP_USERNAME` / `WP_APPLICATION_PASSWORD`).
- New user-facing strings use the plugin's own text domain; catalogues are
  regenerated by script, never hand-edited.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --only conexao-admin-ux     # in-process suites
./scripts/run-tests.sh --php                      # full in-process layer
./scripts/lint.sh                                 # PHPCS + PHPStan, no new violations
```

- The capability check is proven **negatively**: a suite (or a documented manual
  check) shows a user without the capability gets no access, and that a valid
  nonce-less POST does not write.
- The screen's own regression suite prints `N passed, 0 failed` and exits 0.
- Report real numbers. If an admin E2E check could not be run in the
  environment, record it as **not tested** — never as verified.

## Failure handling

- *A user without the capability sees partial output.* The check is too late in
  the render path. Move it to the earliest entry point; a partial render is a
  defect, not a cosmetic issue.
- *A page load writes.* Locate the write outside an explicit action branch. A
  refresh must never be a mutation.
- *The dry run performed writes.* The preview is not a preview. Stop, re-inventor,
  and re-plan before showing anything to a maintainer.
- *A partial result is reported as success.* Fix the result reporting: counts, the
  numeric gate, and a non-zero exit for a blocked or preview-only run.
- *The admin E2E check cannot run in this environment.* Record it as not tested
  with the reason; do not infer a pass.

## Evidence and reporting

Record: the capability and nonce surfaces with their negative proofs, the dry-run
plan output, the in-process suite result with exact numbers, the plugin doc
update, new user-facing strings and their text domain, and an explicit statement
that no production write occurred. Machine evidence goes to
`docs/evidence/<date>-<stage>/`.

## Definition of done

- [ ] The screen lives in the owning plugin (`conexao-admin-ux` or the rollout
      engine), and the ownership boundary is documented.
- [ ] Capability is checked on every entry point, proven negatively.
- [ ] Every write path verifies a nonce; a missing nonce writes nothing.
- [ ] GET/page load performs no write.
- [ ] A dry-run preview precedes every write; the write is a separate action.
- [ ] Output is escaped and input sanitised; notices are the shared mechanism.
- [ ] The result reports counts and the numeric gate; blocked runs exit non-zero.
- [ ] A suite exists, is discovered by convention and passes.
- [ ] The plugin doc is updated; new strings use the plugin text domain.
- [ ] No production write occurred; limitations are stated honestly.
