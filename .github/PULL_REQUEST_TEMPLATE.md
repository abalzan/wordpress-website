<!--
Repository: Conexão BR Irlanda (WordPress).
Before filling this in, read AGENTS.md and docs/engineering-standard.md.
Keep it short and factual. Delete no section — write "none" or "n/a" instead,
so a reviewer can see the question was considered.
-->

## Summary

<!-- What changed, in two or three sentences. -->

## Scope

<!-- What this PR does. -->

## Non-goals

<!-- What it deliberately does not do, and why. -->

## Related plan

<!-- Link the plan from docs/templates/plan.md, or state why the task was
     single-file / documentation-only and no plan was required. -->

## Runtime impact

<!-- Does WordPress behave differently? Which module owns the change? -->

## Content / data impact

<!-- Records created/updated/deleted and match strategy. "None" if none. -->

## English / Polylang impact

<!-- PT unchanged? EN records added? Translated vs shared taxonomies?
     Completeness gate number (0 or an explicit allowlist)? -->

## Security considerations

<!-- Capabilities, nonces, sanitisation, escaping, prepared SQL, REST exposure.
     "None" is fine, but say why. -->

## Performance considerations

<!-- Queries added/removed, cache keys and invalidation, assets. -->

## Tests run

<!-- Real numbers, not "tests pass". Include the exact command output summary:
     suites total/passed/failed, assertions passed/failed. -->

## HTTP verification

<!-- Matrix rows added, rows/assertion counts, local base URL. -->

## Release impact

<!-- Version bumped (in the component header), effect on the artifact allowlist
     derived from plugins.json, or "no release impact". -->

## Documentation updated

<!-- Which docs, per the change → document map. -->

## Production changes

<!-- Explicitly: none, or a precise description of what a maintainer must do.
     Remember: production is WordPress.com — no SSH, no WP-CLI. -->

## Rollback

<!-- How to revert, and what rollback does not cover. -->

## Known limitations

<!-- What could not be verified and why. "None" if genuinely none. -->

## Checklist

- [ ] `AGENTS.md` and `docs/engineering-standard.md` were reviewed before changing code.
- [ ] `docs/templates/plan.md` was used (or the task was genuinely single-file/documentation-only).
- [ ] No unintended PT content changes.
- [ ] Polylang changes are intentional and PT immutability is asserted where relevant.
- [ ] `plugins.json` remains the single source of truth; no second plugin registry was added.
- [ ] No duplicate source of truth introduced for any list.
- [ ] Shared engines/helpers were reused instead of new copies (justification recorded if a copy was unavoidable).
- [ ] Tests were run and the **real** numbers are recorded above.
- [ ] HTTP acceptance was run when the change is request-visible.
- [ ] Documentation was updated in this same change.
- [ ] No secrets, no localhost URLs in production data, no hotlinked media.
- [ ] Production changes are explicitly documented (or explicitly "none").
- [ ] A rollback path is documented.
- [ ] The Flutter/mobile repository was not touched.
- [ ] Generated regions were regenerated (not hand-edited) and the registry drift gate passes.
