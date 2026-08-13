# WordPress Migration Requirements

## Conexão BR Irlanda / Revista Virtual

**Project:** Wix to WordPress functional-parity migration  
**Source site:** <https://tdcriativo.wixsite.com/brasileirosnairlanda>  
**Audience:** Brazilian community in Ireland, with emphasis on Laois and the Midlands  
**Public language:** Brazilian Portuguese (`pt-BR`)

## 1. Executive Summary

This project will migrate the existing Conexão BR Irlanda / Revista Virtual website from Wix to WordPress while preserving the current site's functionality, content, information architecture, visual identity, and mobile experience. The site remains a digital magazine for Brazilians in Ireland.

The work is a functional-parity migration, not a redesign or a new community portal. The future PDF vision—county portals, restaurant pages, native events calendar, and similar initiatives—is explicitly out of scope and recorded in Appendix A.

Before build work begins, the migration team must complete the accompanying content and redirect inventory to reconcile this specification with the live Wix site.

## 2. Site Map

| Wix URL | Navigation label | WordPress URL | Purpose |
| --- | --- | --- | --- |
| `/` | Início | `/` | Homepage hub |
| `/blog` | BLOG | `/blog/` | Blog archive and community content blocks |
| `/turismo-e-lazer` | EVENTOS | `/eventos/` | Curated external event recommendations |
| `/capacitação` | CURSOS | `/courses/` | Training and course advertiser grid |
| `/fique-por-dentro` | NOTÍCIAS | `/noticias/` | News resources and newcomer information |
| `/s-projects-basic` | GUIAS PRÁTICOS | `/guias-praticos/` | Practical-guide listing |
| `/contato` | CONTATO | `/contato/` | Contact form and WhatsApp contact |

GUIAS PRÁTICOS remains a submenu item under NOTÍCIAS. The five Wix placeholder guide records named “Project Name” must not be published; retain them only as draft/private migration records if they are copied at all.

## 3. Functional Requirements

| ID | Requirement |
| --- | --- |
| FR-01 | Replicate all seven pages in the site map with equivalent content, hierarchy, and intended layout. |
| FR-02 | Provide a WordPress blog with the categories Saúde e Bem-estar, Capacitação, Empreendedor, Receitas, and Lazer; include an archive, category filtering, and a single-post template. |
| FR-03 | Preserve blog title, featured image, author, publish date, reading time, category, rich-text body, headings, guest-author attribution, and guest-author external links where present. |
| FR-04 | Provide Facebook, X, LinkedIn, and copy-link sharing controls on blog posts. |
| FR-05 | Show related or recent posts on every single-post page. |
| FR-06 | Provide an editable homepage sponsor section titled “Empresas que Apoiam nosso Projeto”, using clickable logo/image cards with external URLs. |
| FR-07 | Provide an editable homepage business directory with image-link cards grouped as Serviços Profissionais, Saúde e Bem-estar, Marketing e Negócios, Artes e craft, Alimentação, and Informações. |
| FR-08 | Display the latest blog posts on the homepage. |
| FR-09 | Build EVENTOS as an editable curated external-link directory with image grids for Família & Crianças, Lazer & Social, and Bem-estar & Natureza; it is not a native event calendar. |
| FR-10 | Build CURSOS as an editable external-link advertiser image grid with its existing supporting entrepreneurship/training copy. |
| FR-11 | Preserve NOTÍCIAS as an editable static resource page, including radio/news links, emergency numbers, housing, embassy, jobs, immigration, Citizens Information, hate-crime, tourism, and domestic-abuse resources. |
| FR-12 | Provide a GUIAS PRÁTICOS listing and individual guide pages, including “Guia para quem está com problemas financeiros na Irlanda”. |
| FR-13 | Provide the contact form headed “Tem uma sugestão ou dúvida? Nos mande uma mensagem” and deliver submitted messages by email to the site owner. |
| FR-14 | Preserve the site-wide WhatsApp CTA, including the footer number `+353 89 945 1428` and `https://wa.me/353899451428` destination. |
| FR-15 | Preserve the Instagram destination for `@conexaobr.ie`. |
| FR-16 | Provide a mobile-responsive experience equivalent to the current Wix site. |
| FR-17 | Configure and test permanent redirects from Wix URLs to their WordPress equivalents. |

### Homepage content

The homepage must retain this section order: header with Conexão BR Irlanda and TD Criativo branding, primary navigation, and Instagram; hero banner with the tagline “SUA REVISTA DIGITAL PARA BRASILEIROS NA IRLANDA” and the category list Eventos, Empregos, Cursos, Passeios, Família, Negócios; CTA row for Eventos da Semana, Seja um Apoiador, and Empregos; sponsors; business directory; latest posts; Mário Sérgio Cortella quote/community message; and footer.

The BLOG page additionally retains the LOFFA / All Abilities Ireland blocks, “Que tal um pouco de movimento em Laois?”, and “Mundo Atípico” sections.

## 4. Non-Functional Requirements

| ID | Requirement |
| --- | --- |
| NFR-01 | Use `pt-BR` throughout the public site and WordPress administration labels where supported. |
| NFR-02 | Target mobile page load below three seconds and a Lighthouse Performance score of at least 70. |
| NFR-03 | Provide editable meta titles/descriptions, Open Graph metadata, an XML sitemap, and Article structured data for posts. |
| NFR-04 | Meet WCAG 2.1 AA for navigation and forms; all migrated images require meaningful alt text or a documented decorative-image exception. |
| NFR-05 | Use SSL, form-spam protection (honeypot and/or CAPTCHA), least-privilege roles, and regular WordPress/plugin updates. |
| NFR-06 | Allow the client to publish and edit blog posts without developer support. |
| NFR-07 | Allow the client to update sponsor logos, directory items, curated event/course cards, guides, and static pages without developer support. |

## 5. Technical Architecture

WordPress 6.x will run on client-owned managed hosting with a client-controlled domain and credentials. Use a lightweight Gutenberg-compatible block theme and the native Block Editor; Elementor is not part of this baseline.

| Wix capability | WordPress implementation |
| --- | --- |
| Wix Blog | Native `post` content type and categories |
| Static pages | Native `page` content type and block editor |
| Sponsor logos | `sponsor` custom post type: logo, external URL, display order |
| Business directory | `directory_item` custom post type: category taxonomy, image, external URL, display order |
| Event/course cards | `curated_link` custom post type: group taxonomy, image, external URL, display order |
| Practical guides | `guide` custom post type: featured image, excerpt, body, display order |
| News resource directory | Editable WordPress page using blocks |

Use Advanced Custom Fields (or equivalent WordPress-native custom fields) for structured content. Install and configure a form plugin (WPForms or Contact Form 7), an SEO plugin (Rank Math or Yoast), a redirect manager, and a hosting-compatible caching plugin. Plugin selection must not duplicate host-provided caching/security features.

## 6. Design Requirements

- Recreate the current green/orange palette, Brazilian and Irish flag motifs, Conexão BR Irlanda logo, and TD Criativo logo.
- Reuse approved Wix photography and brand assets after exporting them to the WordPress Media Library.
- Preserve the existing section order and visual hierarchy. Changes are limited to adjustments necessary for responsive, accessible WordPress implementation.
- Images must be optimized for web delivery while preserving editorial quality.

## 7. Content Migration and URL Policy

Wix does not provide a clean WordPress export. Migrate blog entries through available RSS/export data where possible, then manually validate rich text, images, attribution, reading time, and links. Copy static pages manually and upload all approved media to WordPress.

Canonical post URLs are `/blog/{slug}/`. Redirect every discovered `/post/{slug}` URL permanently to its matching canonical post URL. Redirect category URLs to `/category/{slug}/` after validating actual Wix category paths and slugs. Preserve query strings on redirects unless a documented exception is required.

| Wix URL | WordPress URL |
| --- | --- |
| `/` | `/` |
| `/blog` | `/blog/` |
| `/blog/categories/{cat}` | `/category/{cat}/` |
| `/post/{slug}` | `/blog/{slug}/` |
| `/turismo-e-lazer` | `/eventos/` |
| `/capacitação` | `/courses/` |
| `/fique-por-dentro` | `/noticias/` |
| `/s-projects-basic` | `/guias-praticos/` |
| `/contato` | `/contato/` |

The completed inventory is the source of truth for exact final URLs, media, external links, redirect rows, and migration status.

## 8. Delivery Phases

1. **Setup:** provision staging, install WordPress, configure theme, plugins, roles, SSL, and backups.
2. **Inventory and migration:** audit Wix content; migrate posts, pages, media, links, sponsors, directory items, curated links, and guides.
3. **Templates:** implement the homepage, post/archive templates, content-type displays, and static page templates.
4. **QA and redirects:** test content parity, links, forms, performance, accessibility, mobile layouts, SEO, and redirects.
5. **Launch:** approve staging, complete DNS cutover, verify SSL and production redirects, then retire Wix or redirect it where possible.

## 9. Admin and Handoff

Provide a one-to-two-hour training session for Tatiane covering post publishing, sponsor updates, curated event/course updates, guides, and static pages. Assign the client an Editor role and keep developer Administrator access only for delivery/support needs. A Portuguese written admin guide is an optional follow-on deliverable.

## 10. Acceptance Criteria

- All seven pages are live and their content parity is verified against the final Wix inventory.
- All approved blog posts and five categories are migrated with required metadata and media.
- All external links pass validation; broken, expired, or intentionally removed links are documented in the inventory.
- Contact-form messages arrive at the approved owner email, and spam protection is verified.
- WhatsApp and Instagram destinations work from desktop and mobile.
- All redirect-map rows return a single 301 redirect to the expected final page.
- Mobile layout is verified on current iOS and Android browsers.
- SEO metadata, sitemap, Open Graph, article schema, alt text, security controls, and performance requirements pass review.
- The client independently publishes a test post, updates one sponsor, and updates one curated-link card.
- DNS cutover, SSL, and post-launch checks are complete before the Wix site is decommissioned or redirected.

## Appendix A. Phase 2 Roadmap (Out of Scope)

The following are future enhancements and are not included in this migration: a six-button portal home (Moradia, Empregos, Eventos, Onde Comer, Capacitação, Família); restaurant, trail, and county pages; native event calendar; weekly editorial automation; community spotlight features; Europe travel guides; “Economize na Irlanda” deals; “Primeiros 30 dias” onboarding; and public-services wizards for PPS, IRP, and related services.

## Appendix B. Open Items to Confirm Before Launch

- Final custom domain, registrar, hosting provider, DNS owner, and site-owner email for contact-form notifications.
- Approved image rights and replacement policy for unavailable Wix assets.
- Final inventory and redirect-map sign-off after the live-site audit.
