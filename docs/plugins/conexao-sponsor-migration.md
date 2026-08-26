# conexao-sponsor-migration

Apoiadores (sponsors) export/import for moving the supporter dataset between
installations (e.g. local → production). JSON payload with **embedded image
bytes** — the destination site recreates every Media Library attachment
locally, with no external requests and no localhost URLs in production data.

- Path: `wp-content/plugins/conexao-sponsor-migration/`
- Version: 1.1.0 (export file format 1.1.0 — adds the per-sponsor `contacts` collection; 1.0.0 files remain importable)
- Text domain: `conexao-sponsor-migration`
- Admin: top-level menu **Apoiadores Migration** → *Exportar Apoiadores* /
  *Importar Apoiadores* (`manage_options`)

## What is transferred

Per sponsor:

- Title, description/excerpt, status, slug, dates
- `_sponsor_category`, `_sponsor_type`, `_sponsor_link`, `_sponsor_featured`,
  `_sponsor_display_order`, `_sponsor_status`, `_sponsor_created_date`
- Taxonomies by term name: `conexao_category`, `conexao_county`
- **Contacts** ("Contatos" repeater): the ordered list of `{type, url}` rows
  from `_sponsor_contacts`, carried as a top-level `contacts` array per sponsor.
  Types: website, instagram, facebook, whatsapp, linkedin, tiktok, email, outro.
  The canonical `_sponsor_link` stays a regular meta key (see below).
- **Both responsive carousel images plus the legacy logo**, with bytes embedded:

| Role | Meta key | Meaning |
|---|---|---|
| `desktop` | `_sponsor_desktop_image` | Imagem Desktop (landscape carousel artwork) |
| `mobile` | `_sponsor_mobile_image` | Imagem Mobile (portrait carousel artwork) |
| `legacy_logo` | `_sponsor_logo` | Pre-two-field single image |

Each image entry carries a stable content hash (`id`), filename, MIME type,
alt text, source URL fallback, and base64 `data_base64` (≤ 5 MB; larger images
fall back to URL sideloading on import).

Backward compatibility: a record whose artwork lives solely in the WordPress
featured image exports that attachment **as** the Imagem Desktop (the
documented "existing Apoiador image → Imagem Desktop" migration).

## Identifiers (portability rules)

- Sponsor matching on import: stable export UUID (`_sponsor_export_uuid`,
  generated once per record at export time) → slug → title. Local WordPress
  IDs are never used as portable identifiers.
- Image dedupe: MD5 content hash stored as attachment meta
  (`_conexao_import_hash`). Re-imports reuse existing attachments instead of
  creating duplicates.

## Import behavior

- File validated before anything is written (format manifest + sponsor list).
- Duplicate strategy: **update** or **skip** existing matches.
- Images are created as local Media Library attachments from the embedded
  bytes (magic-byte validated, core file-type re-validated) and assigned to
  the correct meta keys; alt text is restored onto each attachment.
- Localhost / own-host URLs are never fetched.
- Featured image follows the public fallback chain:
  desktop → mobile → legacy logo.
- Contacts: rows are sanitized through `Conexao_Data_Model_Contacts` (the same
  rules as the admin editor) before storage. A payload **with** the `contacts`
  key replaces existing rows (an empty list clears them); a legacy 1.0.0
  payload **without** the key leaves existing contacts untouched, so old files
  never destroy newer data.
- Additive only: touches sponsor posts, the two shared taxonomies (matched by
  name), and attachments it creates. Never events/guides/jobs/courses/leisure
  or unrelated media.
- If `conexao-data-model` is inactive, the plugin provisions the `sponsor`
  CPT + shared taxonomies itself so imports never write into an inconsistent
  schema.

## Data format

```json
{
  "manifest": { "format": "conexao-sponsor-export", "version": "1.1.0", "...": "..." },
  "sponsors": [
    {
      "uuid": "…",
      "post": { "title": "…", "content": "…", "excerpt": "…", "status": "publish", "slug": "…" },
      "meta": { "_sponsor_link": "…", "_sponsor_desktop_image": "12", "...": "…" },
      "taxonomies": { "conexao_category": ["Serviços Profissionais"] },
      "contacts": [
        { "type": "instagram", "url": "https://instagram.com/…" },
        { "type": "whatsapp",  "url": "https://wa.me/353…" }
      ],
      "images": {
        "desktop":     { "id": "<md5>", "filename": "…", "mime_type": "image/png", "data_base64": "…", "alt": "…" },
        "mobile":      { "id": "<md5>", "filename": "…", "mime_type": "image/png", "data_base64": "…", "alt": "…" },
        "legacy_logo": { "id": "", "url": "", "data_base64": "" }
      }
    }
  ]
}
```

## Classes

| Class | Purpose |
|---|---|
| `Conexao_Sponsor_Exporter` | Builds the payload; streams the `.json` download |
| `Conexao_Sponsor_Importer` | Validates, matches, creates/updates posts, recreates images |
| `Conexao_Sponsor_Transfer_Admin` | Menu pages, nonce/cap checks, oversized-upload interception |