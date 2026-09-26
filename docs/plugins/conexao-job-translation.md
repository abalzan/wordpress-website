# conexao-job-translation

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | retired |
| **Class** | rollout |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.0 (authoritative source: `wp-content/plugins/conexao-job-translation/conexao-job-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Lifecycle: activate → apply → remove.** This is a retired one-shot rollout plugin.
> It is **not** a production steady-state dependency and is **not** included in a
> release plugin ZIP. It is kept in the repository (and locally mounted) only so the
> historical importer stays reproducible.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

> **Stage H migration (2026-09-26).** This stage now runs on the shared
> translation-rollout engine, `conexao-translation-rollout`. Its duplicated
> orchestration (`includes/apply.php`, `includes/audit.php` and the
> `Conexao_Job_Translation_Admin` class) has been **removed**. What remains is
> authored data plus stage configuration:
>
> | File | Role |
> |---|---|
> | `includes/translation-map.php` | authored English data manifest (unchanged) |
> | `includes/stage-fields.php` | job-specific field mapping + eligibility |
> | `includes/stage-config.php` | declarative stage configuration + WP adapter |
> | `conexao-job-translation.php` | loader: requires the three files, registers the stage |
>
> The admin screen is now the shared one: Tools → **Translation Rollouts**
> (see [`conexao-translation-rollout.md`](./conexao-translation-rollout.md)).
> See `docs/reports/2026-09-26-stage-h-shared-rollout-engine.md`.

## What it does

1. **Jobs landing page check** — verifies the PT `empregos` page and its linked,
   published EN `jobs` page exist (the stage refuses to run without them and
   never creates or modifies a page: Stage 4.5 owns the Page layer). This is
   now the engine's `verify_landing_callback` and counts as an **extra gate
   failure** when it is not `verified`.
2. **Job records** — for every manifest entry (keyed by the **Portuguese slug**,
   never by local ID), creates or refreshes exactly ONE linked English `job`
   with the authored English title, body, excerpt and meta description
   (`includes/translation-map.php` is the source of truth, unchanged by
   Stage H).
3. **Verbatim field layer** — copies the shared featured image and every
   `_job_*` meta key actually stored on the PT record. Publication date, author
   and menu order are preserved.
4. **Relationships** — `pll_set_post_language` + `pll_save_post_translations`,
   verified in BOTH directions by the engine before a row is reported
   successful.
5. **Duplicate gates** — the EN-slug collision check is stage-specific (it must
   not fire on a legitimate EN record of the same identity, which is why the
   adapter checks the Polylang link before declaring a collision); re-runs are
   idempotent.
6. **PT regression gate** — the job-specific field list in
   `includes/stage-fields.php` (`conexao_job_translation_snapshot_job()`:
   name, title, content, excerpt, status, date, author, menu order, thumbnail,
   `conexao_category`/`conexao_county`/`conexao_tag` assignments, every
   `_job_*` meta key, Polylang language, meta description) is snapshotted by
   the engine before the run and byte-compared after it.
7. **Gate** — the engine's numeric gate: `eligible public PT jobs missing
   EN = 0`, plus zero conflicts, zero PT drift and a verified Jobs page pair.

## Source identity / deduplication

The `job` CPT has **no importer and no source-identity meta** (no source ID, no
UUID). The portable identity is therefore the **authored stable key** — the
Portuguese slug in the versioned manifest — plus the Polylang pair itself and
the EN-slug uniqueness probe inside the `job` namespace. Local post IDs are
reported in result rows but are never described as portable identity.

## Removal / rollback

This stage declares `allow_remove => false`. The Stage 6 EN jobs carry a
verbatim `_job_*` meta layer and shared featured media whose pre-removal state
is not reconstructible from the manifest alone, so **a destructive remove is
not implemented and is not claimed**. The shared engine refuses `remove` for
this stage with a `WP_Error` and zero writes. Recovery is therefore
snapshot-based: the engine's PT snapshot (and the versioned manifest, which
reproduces every EN field) is the documented recovery source, and re-applying
the stage is idempotent.

## Audit rows

- total public PT jobs / total public EN jobs
- translated job pairs verified
- eligible public PT jobs missing EN (gate condition)
- EN jobs missing PT translation
- jobs excluded (documented)
- taxonomy terms used by PT jobs / missing EN
- Jobs page PT/EN IDs + pair status
- match strategy counts (stable-id / slug / title fallbacks)

## Related theme pieces (Stage 6)

- `inc/empregos-landing.php` — `conexao_empregos_current_jobs()` (the
  language-aware Jobs listing query on the landing template) and the
  language-aware `conexao_empregos_page_url()` (EN job breadcrumbs point at
  `/en/jobs/`).
- `page-empregos.php` — the landing template. Its Stage 6 «Vagas»/"Openings"
  section (the real job records in the current language) was **removed by
  product decision — rendering only**: the job records, the language-aware
  query and the CSS are retained, so the section can be restored unchanged.

See `docs/routing.md` § English for the URL contract.

_Last verified: 2026-09-26 by Stage H — Shared Translation Rollout Engine_
