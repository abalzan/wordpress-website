# Security review

## Purpose

Review a change for the security defects this repository actually produces —
missing capability checks, missing nonces, unescaped output, unprepared SQL,
leaked secrets, REST boundary violations — and prove every guard **negatively**
(blocks when it should).

## When to use

Any change that reads a request, writes to the database, registers a REST or
AJAX endpoint, touches an admin screen, handles media, or moves data between
environments. Run this review **before** shipping, not after. Pair it with
`wp-add-admin-screen` (nonce/capability surface) and content-writing work
(`wp-content-change`, `wp-translation-rollout`) for data movement.

## When not to use

- Performance or query-count review — `wp-frontend-perf`.
- Test-layer mechanics — `wp-write-in-process-test`, `wp-run-tests`.
- There is no "later": a change shipped without this review is a finding in
  itself, not an acceptable state.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §2.1 (PHP rules), §4.2 (admin write paths),
  §7 (REST standard) and §0 principle 8 (no localhost URLs, no hotlinks, no
  secrets).
- `docs/plugins/` — each plugin doc states its own boundary and admin surface.
- `scripts/lib/rest.py` and `scripts/lib/bootstrap.php` — the shared clients
  that already implement the credential and target rules.

## Authoritative sources

- `docs/engineering-standard.md` §2.1, §4.2, §7 and §0 principle 8 own the
  normative rules — this skill is their review procedure, not a second rule set.
- `wp-content/themes/conexao-br-irlanda/inc/rest-language.php` owns the
  bilingual REST boundary — the single module that registers REST language
  filters/fields.
- `scripts/lib/rest.py` and `scripts/lib/bootstrap.php` are the shared clients
  that already implement the credential and target rules — reuse them; never
  restates their guards in a new script.

## Preconditions

- The change is implemented (this reviews real code, not intentions).
- The owning plugin for each touched concern is known (step 10).

## Steps

1. **Capabilities.** Every privileged entry point — admin render callback, AJAX
   handler, REST route permission callback, CLI-invoked action, cron callback —
   checks `current_user_can()` (or the plugin's named capability) **before** any
   work. A handler that renders before checking is a defect. Test it negatively:
   a user without the capability must get nothing.
2. **Nonces.** Every state-changing path verifies a nonce
   (`check_admin_referer()` / `wp_verify_nonce()` / `check_ajax_referer()`).
   Nonces are CSRF defence, not authorisation — both are required. Confirm a
   valid request without a nonce writes nothing.
3. **Sanitisation on input.** `sanitize_text_field()`, `sanitize_textarea_field()`,
   `sanitize_email()`, `sanitize_key()`, `absint()`, and
   `wp_kses_post()` for rich text. Never trust `$_GET` / `$_POST` / `$_REQUEST`
   / `$_SERVER` / a REST parameter, however it was declared.
4. **Escaping on output.** `esc_html()`, `esc_attr()`, `esc_url()`,
   `esc_js()`, `wp_kses_post()`. Escape late, at output, not on storage. Every
   echo of a variable in a template, list table, notice or JSON response is
   checked — unescaped output is the most common finding in this repository.
5. **SQL preparation.** Direct `$wpdb` use only with `$wpdb->prepare()`, with
   placeholders for **every** value including LIMIT/OFFSET and table names that
   are not static. Prefer WP APIs (`WP_Query`, `get_posts()`, `get_terms()`).
   No string-concatenated query.
6. **Authentication boundaries.** The bilingual REST surface is owned by
   `wp-content/themes/conexao-br-irlanda/inc/rest-language.php` — the only module
   that registers REST language filters/fields or filters REST queries. No other
   module changes what a collection returns. Registering a **new** public REST
   endpoint is an allowlist decision: it must be explicitly justified in the
   report and documented in `docs/routing.md` §English before release, and its
   language behaviour must mirror the front end. Event collections must respect
   `_event_status` exactly as the front end does; a fallback record is exposed
   as a fallback (`is_fallback = true`), never as a translation.
7. **Secrets and targets.** No credentials in code, docs, skills or evidence —
   they come from `WP_USERNAME` / `WP_APPLICATION_PASSWORD` (and `.env`, which is
   git-ignored). No hard-coded production URL in operational code. A production
   write requires an explicit target plus a confirmation flag.
8. **Media and URLs.** Production data never contains a `localhost` URL. Images
   are local Media Library attachments, never hotlinks; Wikimedia metadata is
   attribution-only. Attribution/license meta (`_leisure_image_author`,
   `_license`, `_attribution`) is preserved on import.
9. **Mutations on read.** A GET or a page load must never write. Front-end
   requests that write on view (counters, transients) must be explicit, cached
   and language-scoped.
10. **Ownership.** Confirm the change is in the plugin that owns the concern
    (`conexao-data-model` for schema, `conexao-admin-ux` for admin UX,
    `conexao-event-runtime` for event behaviour) and that a plugin does not
    re-implement another plugin's guard.

## Guardrails

- Never suppress errors with `@`; never use `extract()`, `eval()` or a dynamic
  include built from user input.
- `wp_remote_*` for outbound HTTP — never raw `curl` / `file_get_contents()` on a
  URL.
- No `var_dump` / `print_r` / `error_log` in shipped code (allowed in `tests/`).
- A write-capable script defaults to `--dry-run`; a production write needs
  `--confirm-production` and a resolved, printed target.
- Never add a GET-parameter-triggered write, an unauthenticated mutating REST
  route, or a capability check that can be bypassed by a filter.
- If a change requires production shell access, that is a design defect — expose
  an admin screen instead.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/lint.sh                                 # PHPCS (WordPress standards) + PHPStan
./scripts/run-tests.sh --scripts                  # credential/target contract gate
php -l <changed file>                             # syntax
```

- Each capability and nonce guard has a **negative** test showing it blocks.
- The script-contract gate (`tests/scripts/verify-script-conventions.py`) proves
  no credential literal and no hard-coded production target in `scripts/`.
- Any finding you could not test is reported as **not tested**, with the reason.
- No security claim in the report is made without the check that supports it.

## Failure handling

- **A guard is missing:** fix the code; a compensating document note is not a
  fix. A capability or nonce guard that "will be added later" is a defect
  shipped now.
- **A guard cannot be tested in this environment:** report it as **not
  tested** with the reason — never as verified; CI is the authoritative re-run.
- **A finding sits in code the task did not touch:** record it as a follow-up
  finding with its exact location; do not fix unrelated defects inside this
  change (scope creep), and do not leave it unrecorded.
- **A public REST endpoint seems necessary:** it is an explicit allowlist
  decision — justify it in the report and document it in `docs/routing.md`
  §English before release; its language behaviour must mirror the front end.

## Evidence and reporting

- Every finding is reported with the exact path and what proves it; every
  clean area is reported as reviewed, not as "assumed fine".
- Negative-proof evidence (the guard blocked the bad input) goes under
  `docs/evidence/<date>-<stage>/` and the numbers into the report.

## Definition of done

- [ ] Every privileged entry point checks a capability, proven negatively.
- [ ] Every state-changing path verifies a nonce; a missing nonce writes nothing.
- [ ] All input is sanitised and all output escaped at the point of output.
- [ ] Every direct `$wpdb` call uses `prepare()` with bound values.
- [ ] REST changes stayed in `wp-content/themes/conexao-br-irlanda/inc/rest-language.php`;
      a new public endpoint is justified in the report and documented.
- [ ] No secret, credential, production URL default, localhost URL or hotlinked
      media was introduced.
- [ ] No GET or page load mutates state.
- [ ] The change sits in the plugin that owns the concern.
- [ ] `./scripts/lint.sh` reports no new violations.
- [ ] Untestable items are listed explicitly in the report's limitations.
