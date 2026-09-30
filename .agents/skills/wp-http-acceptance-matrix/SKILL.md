# Write an HTTP acceptance matrix

## Purpose

Prove **what a real request returns** — status, redirects, canonical, hreflang,
sitemap, filters, language pairs — by adding rows to the repository's fixed
HTTP acceptance matrices, validated in full before any request is made.

## When to use

Anything **request-visible** needs an HTTP row: a new or changed route, an
archive or single, a filter, pagination, a redirect, a canonical or hreflang
emission, a sitemap entry, a REST language parameter, or a language-pair URL.
This layer proves what a real request returns; the in-process layer
(`wp-write-in-process-test`) proves internal behaviour.

## When not to use

- Internal behaviour that no request can observe —
  `wp-write-in-process-test`.
- Running the full contract / regression comparison — `wp-run-tests`.
- Production verification — `scripts/verify-deploy.py` under
  `wp-production-operations`; the acceptance layer never targets production.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §8.2 (matrix schema) and §6.1 (canonical,
  hreflang, sitemap, B1/B2) and §11 (the release smoke matrix).
- `docs/testing.md` — the acceptance layer, base URL and classification.
- `tests/acceptance/lib/matrix.py` — **the** schema and runner. Read it before
  writing a row; do not re-implement a loader.
- `tests/acceptance/lib/http_client.py` — the shared client, including its
  production-host refusal.
- `tests/acceptance/matrices/routing.json` and
  `tests/acceptance/matrices/guides-en.json` — existing rows
  to imitate, and `scripts/data/release-smoke-matrix.json` for the release set.

## Authoritative sources

- `tests/acceptance/lib/matrix.py` is **the** schema and loader — never
  re-implement either; `tests/acceptance/lib/http_client.py` is the shared
  client including its production-host refusal.
- `scripts/data/release-smoke-matrix.json` is the **fixed** release smoke
  matrix — it deliberately does not grow between releases.
- `docs/testing.md` owns the acceptance layer model and the base URL; the
  route contract being asserted lives in `docs/routing.md`.

## Preconditions

- The local Compose site is up and answering on the acceptance base URL
  (`CONEXAO_TEST_BASE_URL`; default and overrides in `docs/testing.md`).
- The existing rows were read before new ones are written.

## Steps

1. **Add rows to the right matrix.** `tests/acceptance/matrices/routing.json`
   for routes, redirects, canonical/hreflang, filters, sitemap, 404 and REST
   language scoping; `tests/acceptance/matrices/guides-en.json` for EN guide
   coverage. A genuinely new domain gets a new matrix file plus a
   `tests/acceptance/verify-<area>-http.py` runner — never a bespoke loader.
2. **Use the fixed schema, exactly.** `tests/acceptance/lib/matrix.py` validates every row in full
   **before any request is made**, and raises on a missing or malformed field:
   ```json
   { "id": "unique-within-matrix", "url": "/relative/path",
     "expect_status": 200, "expect_contains": ["fragment"],
     "expect_absent": ["fragment"], "note": "why this row exists" }
   ```
   `id` is unique, `url` is relative to the base URL, and `note` says *why* —
   a row without a reason becomes an unexplained tripwire.
3. **Run locally, never against production.** `./scripts/run-tests.sh
   --acceptance` uses the `CONEXAO_TEST_BASE_URL` environment variable (the
   local Compose site; the default and how to override it are documented in
   `docs/testing.md`) and the runner **refuses a production host**; the Python
   client refuses it again. Do not pass a production URL. Production
   checking is the separate, read-only `scripts/verify-deploy.py --site <url>`.
4. **Assert the real contract per route type.**
   - Language: `/` and `/en/` both return the right `html lang`; the PT URL is
     never served as EN (`expect_absent`).
   - Canonical: PT canonical points at PT; a B2 fallback canonical points at
     the **PT** URL, not the EN URL.
   - hreflang: emitted only where a real translation relationship exists; assert
     both presence and absence.
   - Sitemap: the sitemap response is the XML prolog plus the schema
     namespace, and a fallback record is **absent** from it.
   - Redirects: a legacy EN→PT redirect still wins; assert the 3xx and the
     `Location`.
   - Filters: a `?categoria=` slug matches PT records, a translated-taxonomy
     slug is language-specific, a shared county/town slug matches both languages.
5. **Handle intentional redirects explicitly.** A directory type may 3xx
   off-site by design (the `leisure` precedent in
   `wp-content/themes/conexao-br-irlanda/inc/seo/redirects.php`). Assert the
   redirect leaves the site; an unexpected 3xx on any other type is a failure.
6. **Record exact assertion counts.** The runner prints
   `<suite> — N passed, M failed`. Paste those numbers in the report and state
   the number of rows. Do not write "acceptance passes" without numbers.

## Guardrails

- **No production mutation, ever.** The acceptance layer issues GET requests to
  a local site. Never add a write, a POST, or a production target; never use
  production credentials in a matrix.
- A production host as the base URL is refused by both the runner and the client.
- One schema, one loader, one client — no new ad-hoc HTTP code in a suite.
- A malformed matrix fails loudly before the first request; never skip a bad row
  silently.
- Rows assert the *contract*, not incidental markup: prefer stable fragments
  (`<html lang="pt-BR">`, a canonical href) over volatile class names.
- Evidence (matrices, capture summaries) belongs in
  `docs/evidence/<date>-<stage>/`, not in the repository root.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --list                      # the suite is discovered
./scripts/run-tests.sh --acceptance                # every acceptance suite
CONEXAO_TEST_BASE_URL=<local site> \
  python3 tests/acceptance/verify-routing-http.py      # one area
```

- The suite exits 0 and prints `N passed, 0 failed`; record the exact numbers.
- Negative proof: a deliberately wrong `expect_contains` must fail the row.
  If it passes, the row is vacuous and must be rewritten.
- Regression comparison: the failing-row list must be identical before and
  after the change.

## Failure handling

- **A row fails:** the contract in `docs/routing.md` decides — either the code
  is wrong (fix the code) or the documented contract genuinely changed (update
  `docs/routing.md` deliberately, in the same change).
- **The matrix fails validation before any request:** fix the row's fields;
  never delete a failing row to get green.
- **The environment is unavailable (no local site):** the runner reports
  blocked and exits non-zero — report it as blocked, never as passed.
- **A row was proven vacuous (a wrong expectation passes):** rewrite the row
  on stable fragments; volatile class names are not contract.

## Evidence and reporting

- Matrices, capture summaries and exact `N passed, M failed` counts go under
  `docs/evidence/<date>-<stage>/` and into the report; state the number of rows.
- The negative proof (a deliberately wrong `expect_contains` failed the row)
  is recorded — it is the proof the row discriminates.

## Definition of done

- [ ] Rows live in an existing matrix, or a new matrix + runner follows the
      convention with no hand-maintained suite list.
- [ ] Every row matches the fixed schema and states why it exists.
- [ ] The matrix is validated in full before any request.
- [ ] The base URL is local; no production host, credential or write appears.
- [ ] Language, canonical, hreflang and sitemap behaviour is asserted for every
      bilingual route touched.
- [ ] Intentional off-site redirects are asserted explicitly.
- [ ] Exact row and assertion counts are recorded in the report.
- [ ] The suite exits 0, and one row was proven to fail when made wrong.
- [ ] Evidence, if captured, is under `docs/evidence/<date>-<stage>/`.
