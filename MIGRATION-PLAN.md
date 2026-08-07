# Elementor Pro + SEO migration plan for the Wix to WordPress rollout

This plan is tailored to the current repository structure, which already contains a lightweight WordPress theme and a companion plugin for content types, legacy redirects, and basic page scaffolding. For the visual rebuild, use Elementor Pro on top of a lightweight base theme such as Hello Elementor or the existing custom theme if you want to preserve the current codebase.

## 1. Step-by-step migration checklist

### Phase 1 — Discovery and SEO preservation
1. Inventory the live Wix site with a full crawl of all public URLs, including:
   - pages
   - blog posts
   - category/archive URLs
   - image URLs
   - schema/OG metadata
2. Build a migration sheet with one row per source URL and the target WordPress URL.
3. Export and review all existing metadata from the Wix site:
   - title tags
   - meta descriptions
   - focus keywords
   - OG images and social tags
   - heading hierarchy
   - image alt text
4. Create a redirect map before changing any URLs. Use the repository redirect inventory as the source of truth.

### Phase 2 — WordPress setup and content architecture
1. Install and activate the following on staging:
   - Elementor Pro
   - Rank Math SEO (or Yoast if preferred)
   - Redirection
   - WPForms or Contact Form 7
   - a caching plugin compatible with the host
   - an image optimization plugin such as ShortPixel or Imagify
2. Configure the site for Portuguese (pt-BR) and set the homepage to the new WordPress page.
3. Build the content structure for:
   - homepage
   - blog archive and single posts
   - static pages for contato, turismo-e-lazer, fique-por-dentro, capacitação
   - custom content types if you want to preserve the current card-based layouts
4. Keep the existing content plugin and theme structure as the foundation for custom post types and legacy redirects.

### Phase 3 — Visual rebuild in Elementor
1. Recreate global styling in Elementor Site Settings:
   - colors
   - typography
   - spacing
   - button styles
   - form styles
2. Build the header and footer in Theme Builder.
3. Rebuild each page with Elementor Flexbox Containers and reusable templates.
4. Recreate interactive blocks such as:
   - accordions
   - forms
   - CTA buttons
   - popups
   - hover states
5. Match the original Wix layout as closely as possible while keeping the design responsive.

### Phase 4 — SEO and technical QA
1. Implement 301 redirects before launch.
2. Recreate title tags, meta descriptions, OG tags, schema, and alt text.
3. Validate heading hierarchy so each page has one H1 and nested H2-H4 sections.
4. Optimize images to WebP and add missing alt text.
5. Test the mobile experience in Elementor’s responsive modes and on real devices.

### Phase 5 — Launch and monitoring
1. Run a full link check.
2. Verify all redirects return 301 and land on the correct destination.
3. Submit the site to Google Search Console and Bing Webmaster Tools.
4. Monitor indexing, crawl errors, and organic traffic for the first few weeks after launch.

## 2. Recommended 301 redirect strategy

### Recommended plugin stack
- Redirection for rule management and CSV import
- Rank Math for metadata and schema
- WPForms for forms
- ShortPixel or Imagify for image compression to WebP

### Redirect approach
1. Use 301 redirects for all legacy Wix URLs.
2. Keep URL structure simple and consistent.
3. Preserve query strings only when necessary and only if the destination page can safely use them.
4. Redirect old blog URLs to the canonical WordPress blog URLs.
5. Monitor 404s after launch and add missed mappings quickly.

### Recommended redirect map

| Wix URL | WordPress URL | Status |
| --- | --- | --- |
| / | / | 301 |
| /blog | /blog/ | 301 |
| /blog/categories/{cat} | /category/{cat}/ | 301 |
| /post/{slug} | /blog/{slug}/ | 301 |
| /turismo-e-lazer | /eventos/ | 301 |
| /capacitação | /cursos/ | 301 |
| /fique-por-dentro | /noticias/ | 301 |
| /s-projects-basic | /guias-praticos/ | 301 |
| /contato | /contato/ | 301 |

### Example Redirection rules
- Source: `/post/(.+)` -> Target: `/blog/$1/` (regex)
- Source: `/turismo-e-lazer` -> Target: `/eventos/`
- Source: `/capacitação` -> Target: `/cursos/`
- Source: `/fique-por-dentro` -> Target: `/noticias/`
- Source: `/s-projects-basic` -> Target: `/guias-praticos/`
- Source: `/contato` -> Target: `/contato/`

## 3. Elementor design implementation notes

### Global settings to recreate
- Primary color: use the current Wix brand palette as the default accent color.
- Secondary color: use the supporting brand color for buttons and highlights.
- Text color: keep a strong contrast ratio for accessibility.
- Typography: match the Wix font pairing closely, but use fallback stack if a font is not available.
- Spacing and container width: match the original site’s section padding and content widths.

### Theme Builder templates
- Header: sticky, transparent on top sections, solid on scroll
- Footer: consistent across all pages, with WhatsApp and Instagram links preserved
- Single post template: include title, featured image, reading time, share buttons, and related posts
- Archive templates: blog, category, and custom content pages

### Functional rebuilds
- Contact page: Elementor Form with spam protection and email notification
- FAQ or accordion blocks: use the Accordion widget
- Hero area: use a background image or video with overlay and clear CTA
- Popups: use lightweight popups only where the original Wix site had them

## 4. Custom CSS snippets for Wix-style effects

### 1. Glassy sticky header
```css
.site-header .elementor-nav-menu--main {
  backdrop-filter: blur(12px);
  background: rgba(255, 255, 255, 0.78);
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}
```

### 2. Card hover lift and shadow
```css
.elementor-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
  transition: all 0.25s ease;
}
```

### 3. Hero section overlay
```css
.hero-overlay {
  position: relative;
}

.hero-overlay::before {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, rgba(0,0,0,0.75), rgba(0,0,0,0.2));
  z-index: 0;
}
```

### 4. Button polish
```css
.elementor-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}
```

## 5. SEO checklist for launch
- Use one H1 per page
- Keep H2-H4 structure logical and close to the Wix structure
- Add unique meta titles and descriptions for every page
- Add OG tags and social image for every important page
- Add article schema for posts and local/business schema where applicable
- Optimize images for WebP and lazy loading
- Submit the sitemap to Google Search Console
- Track rankings, errors, and traffic after launch
