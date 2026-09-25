# Conexão Data Model

- **Path**: `wp-content/plugins/conexao-data-model/`
- **Version**: 1.6.0
- **Purpose**: Registers custom post types, shared taxonomies, and editorial meta fields.

## Responsibilities

- Register 8 CPTs: `guide`, `event`, `job`, `sponsor`, `course_provider`, `leisure`, `recruitment_agency`, `permit_employer`
- Register 3 shared taxonomies: `conexao_category`, `conexao_county`, `conexao_tag`
- Register editorial meta fields for guides, events, sponsors, jobs, recruitment agencies
- Seed default category/county taxonomy terms on activation

## Key Components

| File | Purpose |
|------|---------|
| `conexao-data-model.php` | Main plugin file, class `Conexao_Data_Model` |
| `includes/class-meta.php` | Editorial meta boxes (legacy, replaced by admin-ux for supported types) |
| `includes/class-relationships.php` | Taxonomies and default term seeding |
| `includes/class-contacts.php` | Apoiador contacts model (`_sponsor_contacts` repeater: types, sanitization, storage) |
| `includes/class-agency.php` | Recruitment-agency job-type registry (`Conexao_Data_Model_Agency`: canonical keys, pt-BR labels, legacy passthrough) |
| `register_permit_employer_meta()` (main file) | `_employer_*` meta for the employment-permit employers directory (same protected-meta/REST auth pattern as the agency meta) |

## Data Model

### Custom Post Types

All registered in `Conexao_Data_Model::register_content_types()`. Portuguese slugs are canonical. All are public, show in REST, have archives.

See `docs/content-model.md` for complete details.

### Taxonomies

| Taxonomy | Slug | Apps to | Hierarchical |
|----------|------|---------|--------------|
| `conexao_category` | `categories` | All 6 CPTs | Yes |
| `conexao_county` | `counties` | All 6 CPTs | Yes |
| `conexao_tag` | `tags` | All 6 CPTs | No |

### Meta Fields (editorial)

| Post Type | Meta Keys |
|-----------|-----------|
| `guide` | `_conexao_featured` |
| `event` | `_event_date`, `_event_time`, `_event_location` |
| `sponsor` | `_sponsor_link`, `_sponsor_display_order` |
| `job` | `_job_company`, `_job_location`, `_job_salary`, `_job_employment_type`, `_job_expiration_date` |

Leisure meta (`_leisure_*`) and course provider meta (`_provider_*`) are registered in this plugin's `register_leisure_meta()` and `register_provider_meta()` methods.

#### Leisure practical-information meta (Phase 2)

Registered by `register_leisure_meta()` (protected, `show_in_rest => true`,
optional — empty values never affect existing records):

| Meta key | Meaning |
|----------|---------|
| `_leisure_practical_notes` | "Observações práticas" — concise visitor tips for the individual leisure page |
| `_leisure_practical_source_url` | Source URL used to verify the practical notes (admin/verification field; since Phase 3B also surfaced as a labelled display-only "Mais informações" link on internal pages, never as raw text) |
| `_leisure_practical_last_checked` | Date the practical information was last verified |
| `_leisure_internal_page` | Phase 3B — boolean flag: keep the internal `/lazer/{slug}/` page even when `_leisure_official_website`/`_leisure_discover_ireland` are set; those URLs become display-only reference links instead of triggering the external redirect. Records without the flag classify exactly as before |

Verification metadata applies to the practical notes only — never to stable
taxonomy attributes. Opening hours are deliberately not modelled.

### Sponsor Contacts (`_sponsor_contacts`)

`Conexao_Data_Model_Contacts` owns the structured multi-contact storage for
Apoiadores — a single array meta holding an ordered list of rows:

```
[ ['type' => 'instagram', 'url' => 'https://instagram.com/…'],
  ['type' => 'whatsapp',  'url' => 'https://wa.me/353…'] ]
```

- **Types**: `website`, `instagram`, `facebook`, `whatsapp`, `linkedin`,
  `tiktok`, `email`, `outro`.
- **Canonical website stays separate**: `_sponsor_link` remains the official
  link used by archive cards, the homepage carousel and SEO schema. A
  Website-type row here is an additional link, never a replacement.
- **Sanitization** (`sanitize_rows()`, shared by the admin editor save path
  and the sponsor importer): http(s)-only URLs; scheme-less domains get a
  `https://` prefix; unsafe protocols rejected; WhatsApp accepts bare numbers
  (normalized to `wa.me`) plus any full wa.me/api.whatsapp.com/
  chat.whatsapp.com link; e-mails validated via `sanitize_email()` and stored
  as `mailto:` links.
- **Validation** (`validate_submission()`) returns per-row pt-BR error
  messages for invalid input instead of silently dropping it.
- **API**: `get($post_id)` / `update($post_id, $rows)` / `types()` /
  `display_value($type, $url)` for front-end or tooling consumers.
- Meta is registered in `register_sponsor_contacts_meta()` (array type,
  REST exposure off, sanitize callback as defense-in-depth).
- Row order is meaningful: preserve insertion order when rendering.

## Recruitment Agency Job Types (`Conexao_Data_Model_Agency`)

Single source of truth for the "Principais tipos de trabalho" of the
Agências de Recrutamento directory, shared by the Admin UX editor
(`multiselect` options) and the frontend card rendering (consistent
labels — the same pattern as the Apoiador contact types).

- `job_types()`: canonical key => display label (12 values: warehouse,
  general_operative, factory_production, logistics, hospitality, cleaning,
  retail, construction_labour, driving_delivery, office_admin,
  agriculture_seasonal, healthcare — `healthcare` was added in 1.5.0 for
  the validated healthcare-recruitment agencies). The **keys** are the stable,
  language-neutral storage/filter identity (`?area=warehouse`) and are never
  renamed or translated; the **labels** run through gettext in the theme text
  domain, so the Admin UX editor, the `/empregos/` filter and the opportunity
  cards all render Portuguese on the default language and English on `/en/jobs/`
  from this single registry (Stage 8).
- Storage: `_agency_job_types` holds a comma-separated list of canonical
  keys (e.g. `warehouse,logistics`). The Admin UX save path whitelists every
  submitted value against `job_types()`; unknown values are dropped.
- `job_type_labels($raw)`: maps stored keys to labels; segments that are not
  canonical keys (legacy free text from records created before the
  structured editor) pass through unchanged so old data keeps rendering.
- Agencies are never auto-tagged — only values confirmed by the supplied
  research may be assigned, and only through the editor.

## Agency Meta & REST (`register_agency_meta()`)

All `_agency_*` meta keys are protected (underscore-prefixed) and registered
with `show_in_rest => true` plus an explicit `auth_callback`
(`current_user_can('edit_post', $object_id)`). The auth callback is required:
WordPress core defaults protected-meta REST writes to `__return_false`, which
would make every REST create/update of an agency fail with
`403 rest_cannot_update` — even for administrators. With the callback, the
directory can be seeded/edited through the REST API exactly as broadly as the
wp-admin editor allows (the same convention as the `_empregos_link` page meta).
`_agency_notes` stays REST-hidden (`show_in_rest => false`).

The WordPress.com-compatible seeding path is
`scripts/seed-recruitment-agencies-rest.py` (Application Password auth,
upsert-by-slug, same data as `scripts/seed-recruitment-agencies.php`).

## Employment-Permit Employers Meta (`register_permit_employer_meta()`)

Backs the Employment Permit-history employers in the unified
"Oportunidades de emprego" directory on /empregos/ (theme modules
`inc/permit-employers.php` + `inc/employment-opportunities.php` +
template part `template-parts/employment-opportunities.php`), edited in
wp-admin under **Empregos → Empregadores — Employment Permits** via the
Admin UX.
Same protected-meta/`auth_callback` REST pattern as the agency meta;
`_employer_notes` is REST-hidden.

| Meta key | Meaning |
|----------|---------|
| `_employer_official_website` | Official public-facing website (required) |
| `_employer_careers_url` | Careers/jobs page, only when confirmed |
| `_employer_sector` / `_employer_roles` / `_employer_location` | Card content (roles: free text, employer-specific) |
| `_employer_permit_status` | `verified` (verified HISTORICAL permit evidence — never "currently sponsoring"), `unverified` (plain entry, no indicator), `exception` (same historical evidence + company-stated current-position caveat in admin data; renders as a normal permit-history card) |
| `_employer_evidence_years` | Years with official records, e.g. "2023–2025" |
| `_employer_evidence_source` | Exact source — admin data only, never rendered |
| `_employer_last_checked` | Verification date |
| `_employer_status` | Custom publishing status |

Editorial rules live in
`docs/research/2026-09-empregos-agencies-and-employment-permits.md`.
Seeding: `scripts/seed-permit-employers.php` (WP-CLI/local) and
`scripts/seed-permit-employers-rest.py` (WordPress.com, Application
Password auth, upsert-by-slug — keep the two in sync).

## Admin UI

Legacy meta boxes (Class `Conexao_Data_Model_Meta`). For event/guide/job/sponsor/course_provider/leisure, the admin-ux plugin replaces these with its own editor. The legacy handler skips types managed by admin-ux.

## Dependencies

- None. Must be activated first.
- Other plugins depend on its CPTs/taxonomies existing.

## Important Rules

- Do not rename CPT slugs without updating `.htaccess`, `inc/seo.php`, and all menu filters.
- Term seeding is idempotent.

## Files to Inspect First

- `conexao-data-model.php` — main plugin file, CPT/taxonomy registration
- `includes/class-relationships.php` — taxonomy seeds
- `includes/class-meta.php` — meta registration and editorial boxes
- `includes/class-contacts.php` — Apoiador contacts model
