# Conexão BR Irlanda — WordPress build

This repository contains the custom WordPress theme and companion plugin for the Wix-to-WordPress migration described in `REQUIREMENTS.md`.

## Run locally with Docker

Docker Compose is included. From this project directory, run:

```bash
docker compose up -d
```

Then open [http://localhost:8080](http://localhost:8080) and complete WordPress's initial setup. Choose **Português do Brasil** when prompted.

In **Plugins**, activate **Conexão BR Irlanda Content**. In **Appearance → Themes**, activate **Conexão BR Irlanda**. The plugin creates the main pages and initial taxonomies when it is activated.

To stop the local site without deleting data:

```bash
docker compose down
```

To reset the local database and uploaded-media volume completely:

```bash
docker compose down -v
```

Use `cp .env.example .env` before starting only if you want to change the port or local database credentials. Theme and plugin changes under `wp-content/` are mounted directly, so refresh the browser after editing them.

## Install on a WordPress 6.x staging site

1. Copy `wp-content/themes/conexao-br-irlanda` and `wp-content/plugins/conexao-content` into the equivalent directories in WordPress.
2. Activate **Conexão BR Irlanda Content**, then activate the **Conexão BR Irlanda** theme.
3. In **Settings → Reading**, select a static homepage and use the theme’s front-page template. The plugin creates the other main pages on activation.
4. Add the final WordPress form block to **Contato** and configure its site-owner notification email and spam protection.
5. Populate content from the reviewed CSV inventories. Add images with meaningful alt text, destinations, and display order to sponsors, directory cards, and curated links.

## What is included

- Block-theme templates for the home, blog archive, category archive, post, and page layouts.
- A responsive visual base using the required green/orange palette.
- Editable custom content types for sponsors, business directory items, curated links, and practical guides.
- Sponsor, directory, and event/course grid shortcodes; share controls and reading time on posts.
- Five required blog categories, directory/event grouping terms, canonical `/blog/{slug}/` post links, and legacy Wix route redirects.

## Still required before launch

Content, approved logo and photography assets, final owner email, hosting/SSL configuration, form/SEO/cache/redirect plugins, and the audited redirect rows are intentionally not guessed. They must be completed from `content-inventory/` and the approved Wix audit before production launch.
