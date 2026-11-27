# Item 2 — is `test-source-fetch-failure.php` genuinely pre-existing?

**Classification: CONFIRMED PRE-EXISTING.** The clean-state control was run
against the code at the pre-Stage-2 SHA and reproduced the failure exactly.

## 1. Which proof was used

The task ranks three proofs. The Stage 2 evidence
(`docs/evidence/2026-11-15-stage-2/08-preexisting-failures.md`) used only the
**weakest** one: it showed that the Stage 2 diff contains no reference to the
rate-limit status, and that the failure mode looks like Stage 1's status model.
The two stronger proofs — a clean checkout at the pre-Stage-2 SHA, and a run
against the pre-Stage-2 code — were not attempted.

This verification performed the **strongest available** version: a run against
the actual tree at the pre-Stage-2 SHA.

Method (no repository file changed, no `git worktree` or stash used):

```bash
git archive b5a3ee9 | tar -x -C .local/s2prev          # .local/ is gitignored
docker compose run --rm -T --no-deps \
  -v "$PWD/.local/s2prev/wp-content/plugins/conexao-event-importer:/var/www/html/wp-content/plugins/conexao-event-importer" \
  -e CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
  -e WORDPRESS_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
  -e CONEXAO_TEST_SHARED_DB_NAME=wordpress \
  wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-source-fetch-failure.php
```

The mount shadows the plugin directory with the `b5a3ee9` tree, so every plugin
include resolved to pre-Stage-2 code. The database is the disposable clone
(preflight `ISOLATED_DB_PREFLIGHT=PASS`); `tests/bootstrap.php` is byte-identical
at both commits (`git diff --stat b5a3ee9..HEAD -- tests/` is empty), so the
harness is the same in both runs. The same command was then run without the
override, i.e. at HEAD `3f6141a` with the Stage 2 code.

## 2. What actually fails

Pre-Stage-2 (`b5a3ee9`) — `02a-source-fetch-failure-pre-stage2.log`:

```
ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db
  FAIL: Eventbrite first page 429: status is failed (got "rate_limited")
  FAIL: Eventbrite first page 429: fatal_errors > 0 (failure evidence recorded)
  FAIL: Eventbrite first page 429: the persisted history row is status=failed
  FAIL: Eventbrite first page 429: the persisted history row carries fatal_errors
  FAIL: Eventbrite first page 429: HTTP 429 is surfaced as failure evidence
  FAIL: Heritage Week first page 429: status is failed (got "rate_limited")
  … (the same five for Heritage Week)
122 passed, 10 failed
```

At HEAD `3f6141a` (Stage 2 code) — `02b-source-fetch-failure-head.log`:

```
122 passed, 10 failed
```

`diff` between the two logs: **identical apart from the container ID**.

## 3. What the failure is, and why it is not a P3 problem

The failing assertions are all "a first-page HTTP 429 must be recorded as
`failed` with `fatal_errors`". The code records `rate_limited` with a source
cooldown instead — the **Stage 1** rate-limit status model. The test asserts the
pre-Stage-1 contract.

The distinction the task asks for:

| Question | Answer |
|---|---|
| Is it a test that should now pass under P3's new behaviour? | No. P3 is about handlers returning an *empty result on a failed retrieval*. A first-page 429 is a whole-source rate-limit outcome; it never reaches the handler's parse/complete decision. |
| Is it a test of pre-change behaviour failing because behaviour changed? | Yes — it encodes `failed` + `fatal_errors`, which Stage 1 replaced with `rate_limited` + cooldown for 429. |
| Does it fail for an unrelated reason (fixtures/environment)? | No — it fails for exactly the status-model reason above, deterministically, in both trees. |
| Could Stage 2 have broken it? | **Excluded empirically**: the identical test on identical data against the pre-Stage-2 tree produces the identical 10 failures. |

Every non-429 failure class in the same suite (500, 502, 503, timeout, connection
failure) still passes as `failed`, which locates the divergence precisely at the
429 path and confirms it is the Stage 1 status model, not the P3 fetch contract.

## 4. Conclusion

`test-source-fetch-failure.php` (122 passed / 10 failed) is **confirmed
pre-existing**, and the attribution is to **Stage 1's** 429 rate-limit status
model, not to Stage 2. The Stage 2 report's classification is correct; its proof
was weaker than the classification deserved and is now replaced by a clean-state
control.

Disposition is unchanged and out of scope here: the suite needs a decision on
whether the G1 assertions should be updated to the Stage 1 model, which is a
Stage 1 follow-up.

_Last verified: 2026-11-15 by the Stage 2 post-implementation verification_
