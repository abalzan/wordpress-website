# Report — <stage / task name>

> **This is a template.** Copy it to `docs/reports/<date>-<stage>-<topic>.md`
> (or `docs/reports/site/` for repository-engineering stages) and fill it in. Do
> **not** edit this file to turn it into a repository-specific report.
>
> Required by [`docs/engineering-standard.md`](../engineering-standard.md) §9.2
> and §13.2. Machine evidence goes in `docs/evidence/<date>-<stage>/`; this
> document is the narrative that cites it. **No report is ever added at the
> repository root.**
>
> The reporting rule that matters: **a claim is only as good as the command that
> produced its number.** Write `passed` only for something that ran and passed;
> write `failed` for something that ran and failed; write `blocked` for a verifier
> that could not run; write `not tested` for something never attempted; and list
> pre-existing failures separately from your own. Never write "fully verified"
> when a required verifier was unavailable.

| | |
|---|---|
| **Stage / task name** | |
| **Date** | |
| **Author / agent** | |
| **Branch** | |
| **Start SHA** | |
| **Final SHA** | |
| **Working tree at finish** | clean / dirty (list anything dirty and why) |
| **Skills followed** | `<name from .agents/skills/README.md>` |
| **Authoritative docs consulted** | |

## 1. Scope completed

What was actually delivered, mapped to the request.

## 2. Scope NOT completed

What was deliberately or unavoidably not done, and why. An empty section is only
acceptable when the scope really was complete.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|

Give the counts too: **N added, N modified, N deleted**. Call out any
`wp-content/` change explicitly, and any change outside the areas the plan named.

## 4. Runtime impact

Does WordPress behave differently after this change? If yes, exactly how. If the
change was behaviour-neutral, say so and say how that was verified (for example
`git diff --name-only` touching no file under `wp-content/`, plus a green test
run before and after). A documentation, skills or governance change **must** land
here as behaviour-neutral.

## 4a. Scope integrity

For any change that is documentation-, skills- or governance-only, confirm
explicitly:

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | |
| No production content, Polylang or database change | |
| No application behaviour change (`wp-content/` diff is empty) | |
| No Flutter/mobile repository accessed | |
| No `.env` or secret change | |

## 5. Content / data impact

Records created / updated / deleted, and the match strategies used. For a
behaviour-neutral or documentation change: **none**, confirmed by `git status`.

## 6. Polylang impact

PT immutability result (the PT-unchanged assertion and the fields compared), EN
records created, and the completeness gate number. State `0` or the allowlist.

## 7. Route / HTTP impact

Routes, redirects, canonical, hreflang and sitemap effects — and the HTTP
assertion results that prove them.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database), so
anything not operable in wp-admin is a documented limitation, not a silent gap.

## 9. Verification commands and results

Every command that was actually executed, with its **real** output summarised.

| Command | Exit | Result |
|---|---|---|

### Numeric test results

In-process PHP: **N suites total, N passed, N failed**; **N assertions passed,
N failed**. Exact numbers, not "all good".

### HTTP acceptance

**N suites**, **N rows**, **N assertions passed, N failed**. Name the matrix.

### Static analysis

PHPCS / PHPStan / PHP syntax results — or an explicit statement that the tool
could not run in this environment and CI is authoritative.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **N suites, N passed, N failed**.

### Release / build results (only if a release was in scope)

Artifact count, `dist/release.json` verification, determinism and deployment
verification outcome — or `not in scope`.

## 10. Failure proofs / negative tests

Checks that **prove the verifier can fail**. A gate that has never been shown to
fail is not a gate. For each: what was broken, in a temporary copy or fixture,
and the real exit code.

| Proof | Result |
|---|---|

## 11. Regression comparison

Before vs after, on the same environment:

| | Before | After |
|---|---|---|
| Failing in-process suites | | |
| Failing assertion count | | |
| Failing script-contract | | |
| Failing acceptance assertions | | |

State plainly whether the diff of the failing lists is empty, and identify every
failure that pre-existed this change.

## 12. Known pre-existing failures

Each failure that was already present, what it actually is (usually a local
content-data condition), and the proof that it is not yours (e.g. it fails
identically with the change stashed). Out of scope unless unavoidable.

## 13. Limitations

Everything that could **not** be verified here, and why: missing tooling, no
production access, no profiler, no deployed site, unavailable network. This
section is mandatory; if it is empty, justify why.

## 14. Evidence paths

`docs/evidence/<date>-<stage>/` and what each file proves.

## 15. Documentation updated

Which documents changed, per the change → document map, each ending with its
`_Last verified_` line. If a **procedure** was moved out of a document into a
skill, name both ends so no workflow is lost:

| What moved | From (document) | To (skill) |
|---|---|---|

## 16. Rollback / recovery

How to revert this change, and what rollback does **not** cover.

## 17. Final status

Exactly one of:

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate in the standard passed, with real numbers, and no required verifier was unavailable. |
| `PASS WITH LIMITATION` | The work is complete, but a **required** verification capability was unavailable; the limitation is stated in §13. |
| `BLOCKED` | A required implementation could not be completed safely. |
| `NOT TESTABLE` | The intended change could not be meaningfully verified at all. |

**Final status: ______**

Justify the chosen status in one sentence. `PASS WITH LIMITATION` is the honest
answer whenever a verifier did not run; do not reach for `PASS` to look
complete.

_Last verified: 2026-09-26 by Stage K — Agent Skills + Templates_
_Last verified: 2026-09-30 by the agent skills / documentation migration_
