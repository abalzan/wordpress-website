# Test fixtures — purpose, budget and rules

Engineering standard §8.2: "Heavy fixtures live in `tests/fixtures/` with a
documented size budget and are excluded from ZIPs."

This file documents the fixture inventory measured at the end of Stage E, and
the rules that govern fixtures in this repository.

## Measured inventory (Stage E, 2026-09-25)

| Location | Files | Bytes | Purpose |
|---|---:|---:|---|
| `wp-content/plugins/conexao-event-importer/tests/fixtures/` | 9 | 903,980 | Event-importer parser fixtures (grandfathered; see below) |
| `tests/fixtures/` (repository root) | 0 | 0 | Reserved for new shared fixtures |

### Grandfathered event-importer fixtures

These pre-date Stage E and live **inside the plugin**, not in the repository
root `tests/fixtures/`. They were deliberately **not moved**: moving them would
break the plugin's self-contained test layout and the Compose mounts for no
functional gain (PHASE 39 — "Do not blindly move them").

| File | Bytes | Used by |
|---|---:|---|
| `mi-listing.html` | 576,595 | `test-mondello-park-importer.php`, `test-motorsport-ireland-importer.php` (real-world Motor Italy listing markup) |
| `mp-rest.json` | 315,506 | `test-mondello-park-importer.php` (real-world Mondello Park REST payload) |
| `eventbrite-page-1.html` | 8,845 | `test-eventbrite-importer.php` (Eventbrite listing page markup) |
| `address/jsonld-multiple-addresses.html` | 914 | `test-event-address.php` |
| `address/jsonld-malformed.html` | 707 | `test-event-address.php` |
| `address/jsonld-full-structured.html` | 443 | `test-event-address.php` |
| `address/jsonld-venue-only.html` | 412 | `test-event-address.php` |
| `address/jsonld-text-address.html` | 315 | `test-event-address.php` |
| `address/jsonld-town-only.html` | 243 | `test-event-address.php` |

The two large files are **real-world third-party HTML/JSON captures** used to
prove the parsers handle production markup. They are the reason the budget
below is expressed as a ceiling rather than a tight target: they are the single
dominant share of test bytes and they are genuinely required by maintained
suites.

## Size budget (evidence-based)

| Measure | Value |
|---|---|
| Total fixture bytes (grandfathered) | **903,980** |
| Largest single fixture | **576,595** (`mi-listing.html`) |
| Fixture file count | **9** |
| New fixture bytes added by Stage E | **0** |
| New fixture bytes added by Stage P (CI synthetic site) | **23,971** (3 PHP data files, no binary) |

### Stage P — the CI synthetic-site dataset

The GitHub Actions `integration` job needs a site with real content. Before
Stage P it had none, which is why that job was `workflow_dispatch`-only: the
content-dependent suites either 404'd on page 2 or, worse, passed **vacuously**
over an empty population. Stage P supplied that site, and hosted determinism was
then proven on two consecutive fresh `workflow_dispatch` runs, so `integration`
is now a **required blocking** push/PR check.

These are committed PHP **data** files, not fixtures a parser reads, but they
are governed by the same rules and are listed here so the inventory stays the
single place the budget is measured.

| File | Bytes | Records | Purpose |
|---|---:|---:|---|
| `scripts/data/ci-fixture-guides.php` | 7,526 | 50 PT guides | PT half of the `en-guide` pairs; covers all 13 authored `conexao_category` terms; excludes the permanently deleted `learner-permit-theory-test-irlanda-cnh-brasileira` source and carries the re-keyed `carteira-motorista-brasileiros` instead of the retired `carteira-de-motorista-2` |
| `scripts/data/ci-fixture-posts.php` | 6,537 | 40 PT posts | PT half of the `en-post` pairs; makes `/blog/page/2/` and `/en/blog/page/2/` real |
| `scripts/data/ci-fixture-events.php` | 9,908 | 3 events, 3 sponsors, 6 shared terms | Laois/Adare filter rows, recurrence, sponsor singles, and the SHARED county/town terms |
| **Total** | **23,971** | | |

Orchestrated by exactly one script, `scripts/bootstrap-ci-fixtures.php`, which
is a thin coordinator: it owns **order** and nothing else. Every real unit of
work is either a committed dataset (above) or an **existing** repository script
it invokes — the existing `seed-*.php` directory seeders and the existing
`en-*` stages run through the existing `scripts/run-en-translation.php` and the
shared `conexao-translation-rollout` engine. There is no second fixture
lifecycle and no second translation engine.

Why the slugs are *those* slugs: every guide and post fixture uses a PT slug
that already exists as an **authored English row** in the `en-guide` /
`en-post` manifests. The shared engine resolves records by PT slug, so a
fixture under any other slug would be a B1 record with no authored English and
would fail translation completeness for a reason unrelated to the code under
test. Creating the PT half of pairs the repository already ships is what lets
the existing stages do real work instead of being no-ops.

### Rules for the Stage P CI dataset

- **Synthetic.** Every body is a generated placeholder that states its own
  synthetic nature. No production copy, no scraped body, no personal data.
- **Slug is the only identity.** No local post/term ID is ever identity.
- **Idempotent.** Every record is create-or-repair keyed by slug, and the
  orchestrator *proves* it: a second run reports `created=0 repaired=0`.
- **No database dump.** Committed PHP data only — see the "Do not commit a
  database dump as a fixture" rule above, which this honours.
- **Shared taxonomies stay shared.** `conexao_county` / `conexao_town` terms
  are never given a language and never duplicated per language. The orchestrator
  asserts this explicitly (`shared_taxonomy` in the audit).
- **Excluded from release artifacts.** They live under `scripts/`, which is
  never packaged; `tests/scripts/verify-release-integrity.py` proves no
  `tests/`, `fixtures/` or `*.json` report ships, and these files ship in no ZIP.

Rules:

- **Do not add new large fixtures without a measured reason.** Stage E added
  zero fixture bytes. A new fixture over ~100 KB needs a written justification
  in the stage/feature report.
- **Do not commit a database dump as a fixture.** A large parser fixture is
  acceptable; a multi-megabyte DB export to make a test pass is not.
- **Prefer a small purpose-built fixture** when a behaviour can be expressed
  without real-world capture (this is what the `address/*.html` fixtures do).

## Rules

1. **Development/test only.** Fixtures are never runtime dependencies. Nothing
   in `functions.php`, a plugin bootstrap, a template or a REST handler may
   reference a fixture path.
2. **Never shipped in a release ZIP.** `scripts/build-plugins-zip.sh` and
   `scripts/build-theme-zip.sh` exclude `tests/` (verified in the Stage E
   report). A fixture entering a production ZIP is a release defect.
3. **Synthetic or non-sensitive.** Fixtures must be synthetic, already
   documented as local, derived from an existing safe test fixture, or
   explicitly approved non-sensitive developer data. **No production data, no
   credentials, no personal data.**
4. **No production URLs inside a fixture's *assertions*.** A fixture may
   legitimately contain a third-party URL (that is what is being parsed), but
   the harness must never *request* it — acceptance tests use the shared
   local-only client.
5. **Every fixture is required by a maintained suite.** Before adding one, name
   the suite that reads it. An unreferenced fixture is dead weight and should
   be deleted.

_Last verified: 2026-09-25 by Stage E (unified test harness)_
