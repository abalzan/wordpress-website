# Add or change user-facing strings

## Purpose

Add or change a user-facing PHP string without breaking i18n catalogue
freshness: use the owning component's text domain, regenerate the `.pot` with
the repository's script, and leave the freshness gate green.

## When to use

- Any new or changed user-facing string in PHP — templates, admin screens,
  notices, shortcodes, REST error messages.
- Any change that adds or edits a `__()`, `_e()`, `_x()`, `esc_html__()`,
  `esc_html_e()`, `esc_attr__()`, `esc_html_x()` (or numbered/plural variants)
  call site.

## When not to use

- Post/page/record **content** text — that is a content write
  (`wp-content-change`) or a translation rollout (`wp-translation-rollout`);
  content is not a gettext string.
- JavaScript or CSS asset text — this repository ships plain assets with no
  JS string-translation mechanism (see `docs/frontend.md`).
- Renaming a catalogue by hand because a script is "easier" — never.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §9.3 (i18n catalogues — MUST regenerate,
  never hand-edit) and §4.1 (the `Text Domain` plugin header field).
- `scripts/README.md` — the `i18n-make-pot.sh` and `i18n-check.sh` entries:
  usage, safety, what each writes.
- The owning component's doc under `docs/plugins/` (its text domain and
  admin-surface strings) or `docs/themes/conexao-br-irlanda.md` for the theme.

## Authoritative sources

- Each component's `Text Domain` header **is** its text domain; the component
  list is discovered from `plugins.json` (plus the single active theme) by
  `scripts/i18n-make-pot.sh` itself — never restate it by hand.
- `scripts/i18n-make-pot.sh` is the **only** supported way to regenerate a
  catalogue; `scripts/i18n-check.sh` is the documented freshness check.
- The freshness gate `tests/scripts/verify-i18n-freshness.py` is enforced by
  the default suite and CI; it compares catalogue freshness by **git commit
  time**, so committing a string change without regenerating fails CI.

## Preconditions

- GNU gettext (`xgettext`) reachable on the host, or `XGETTEXT_CMD` pointing
  at a command prefix that reaches it (the script exits 2 with instructions
  when it is missing; it never falls back to hand-editing).
- The string change is committed (or about to be) in the same change that
  regenerates the catalogue — freshness is measured from commit time.

## Steps

1. **Use the owning component's text domain — only.** The theme uses
   `conexao-br-irlanda`; a plugin uses its own slug domain (declared in its
   `Text Domain` header). Never borrow another component's domain and never
   introduce a second domain into a component.
2. **Wrap the string at the call site.** `esc_html__()` / `esc_html_e()` for
   HTML output, `esc_attr__()` for attributes, `__()` for non-HTML contexts;
   contextual strings use `_x()`. Escape at output as usual
   (`wp-security-review`).
3. **Regenerate the catalogue.**
   ```bash
   ./scripts/i18n-make-pot.sh <component-slug>   # one component
   ./scripts/i18n-make-pot.sh                    # every catalogue
   ```
   The script writes only `<component>/languages/<component-slug>.pot`; it
   never touches a `.po` or `.mo`. Use `--dry-run` / `--check` first to see
   the plan without writing.
4. **Leave translations to the translator.** `.po` / `.mo` files (the theme's
   `pt_BR` / `en_US` catalogues) are hand-maintained by a translator. When a
   msgid is added or changed, note it in the report for the translation
   pass; do not machine-edit a `.po`.
5. **Verify freshness.**
   ```bash
   ./scripts/i18n-check.sh                        # must pass
   python3 tests/scripts/verify-i18n-freshness.py # the permanent gate
   ```
   Both must be green with the change committed; the gate reads commit times,
   so the regenerated catalogue and the string change belong to the same
   commit.

## Guardrails

- **Never hand-edit a `.pot`.** Regeneration is the only supported write,
  and only through `scripts/i18n-make-pot.sh`.
- Never widen, weaken or suppress the freshness gate to merge a stale
  catalogue; it is fail-closed by design.
- One text domain per component; no domain borrowing, no new domain
  invention.
- The `.pot` ships in release ZIPs (it is release content); `tests/` never
  does — a string change does not alter that packaging contract
  (`wp-release-deploy`).
- Changing a msgid is a content change for translators: coordinate it in the
  report; do not silently reword visible strings.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/i18n-check.sh                          # fresh: exit 0
python3 tests/scripts/verify-i18n-freshness.py   # the permanent gate
./scripts/run-tests.sh --scripts                 # the gate in the suite
```

- Paste the per-catalogue freshness result and the gate output into the
  report.
- If `xgettext` was unavailable, the regeneration is **blocked**, the
  catalogue is stale, and the change must not be claimed verified — record
  the limitation instead.

## Failure handling

- **The gate reports a stale catalogue:** regenerate it (step 3) and commit
  both together; never hand-edit the catalogue or the gate.
- **A catalogue is stale but no string changed:** another component's strings
  moved into the scan scope — regenerate and record why.
- **`xgettext` is missing:** install GNU gettext or set `XGETTEXT_CMD`; the
  script refuses to proceed (exit 2) rather than degrade.
- **A wrong domain appeared in a catalogue:** the string used a foreign
  domain — fix the call site, not the catalogue.

## Evidence and reporting

- Save the `i18n-check.sh` and freshness-gate output under
  `docs/evidence/<date>-<stage>/`; note any translator coordination item
  (added/changed msgids) for the translation pass.

## Definition of done

- [ ] Every new string uses the owning component's text domain.
- [ ] The `.pot` was regenerated by `scripts/i18n-make-pot.sh`, not edited.
- [ ] `./scripts/i18n-check.sh` passes; the freshness gate passes in the
      default suite.
- [ ] The string change and the regenerated catalogue are in the same commit.
- [ ] `.po` / `.mo` were left to the translator; coordination notes exist.
- [ ] The report records the real check output and any limitation.
