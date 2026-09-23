#!/usr/bin/env python3
"""
Stage 4.5 — static validation of the EN page translation manifest.

Simulates, without WordPress, the exact apply run of
wp-content/plugins/conexao-page-translation (map + engine) against the
production page inventory captured by scripts/stage45-page-inventory.py.

Checks (Phase 2/4/5/8/11 gates, as far as static analysis allows):
  1. map parses; every classified "translate" page is present exactly once,
     in the mandatory creation order (front page first, legal last);
  2. every PT source exists in the production inventory;
  3. EN slugs are unique, URL-safe, free of collisions with existing pages,
     legacy redirect source paths (inc/seo.php) and WordPress core routes;
  4. shared slugs are exactly the reviewed allowlist (blog, newsletter);
  5. block structure parity: the EN content uses the same Gutenberg block
     sequence (type + level) as the PT original's rendered content;
  6. internal links in EN content resolve to an EN counterpart (page or
     archive) after the simulated run; external links are preserved verbatim;
  7. meta descriptions exist and differ from the PT ones (translated);
  8. the excluded set matches the inventory classification exactly.

Exit code 0 = all checks pass.

Usage:
  python3 scripts/stage45-validate-manifest.py \
      --inventory stage45-work/page-inventory.json
"""

import argparse
import json
import re
import sys

MAP_PHP = "wp-content/plugins/conexao-page-translation/includes/translation-map.php"
INVENTORY_PY = "scripts/stage45-page-inventory.py"

# Legacy redirect source paths (inc/seo.php conexao_seo_redirects()). All rules
# are root-anchored, so /en/<slug>/ can never match them; flagged anyway so the
# choice is conscious.
LEGACY_REDIRECT_SOURCES = {
    "guides", "events", "jobs", "courses", "sponsors", "ireland", "about-us",
    "about", "contact", "empresas", "businesses", "privacidade", "termos",
    "sobre", "capacitação", "capacitacao", "s-projects-basic", "turismo-e-lazer",
    "guias-praticos", "post", "categories", "counties",
}
WP_CORE_RESERVED = {
    "wp-admin", "wp-login", "wp-json", "feed", "rss2", "atom", "xmlrpc.php",
    "sitemap.xml", "robots.txt", "favicon.ico", "page", "search", "en",
}
# Real CPT archives (has_archive) — these win over pages during link
# resolution. /empregos/ is a PAGE (job has_archive=false) and /blog/ is the
# posts page, so both resolve through the page/translation lookup instead.
REAL_ARCHIVE_SLUGS = {"guias", "eventos", "cursos", "apoiadores", "lazer"}
# Path roots an EN page slug must not take (CPT singles/posts namespaces).
CPT_ARCHIVE_SLUGS = REAL_ARCHIVE_SLUGS | {"empregos", "blog"}


def parse_php_single_quoted(text, start):
    """Parse a PHP single-quoted string starting at the opening quote."""
    assert text[start] == "'"
    out = []
    i = start + 1
    while i < len(text):
        c = text[i]
        if c == "\\" and i + 1 < len(text) and text[i + 1] in "'\\":
            out.append(text[i + 1])
            i += 2
            continue
        if c == "'":
            return "".join(out), i + 1
        out.append(c)
        i += 1
    raise ValueError("unterminated string")


def parse_map(path):
    """Tolerant parser for THIS manifest file's exact shape."""
    text = open(path, encoding="utf-8").read()
    entries = {}

    for m in re.finditer(r"'([a-z0-9-]+)'\s*=>\s*conexao_page_translation_hub\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\)", text):
        slug, title, topic = m.group(1), m.group(2), m.group(3)
        entries[slug] = {
            "en_slug": re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-"),
            "title": title,
            "content": (
                f'<!-- wp:heading --><h2>{title}</h2><!-- /wp:heading -->\n'
                f'<!-- wp:paragraph --><p>Help the Brazilian community in Ireland with information about {topic}.</p><!-- /wp:paragraph -->\n'
                f'<!-- wp:paragraph --><p>Content for this section is being prepared. In the meantime, explore our guides below.</p><!-- /wp:paragraph -->'
            ),
            "meta_desc": f"A complete guide to {title} for Brazilians in Ireland. Information, tips and useful resources.",
            "helper": "hub", "shared_slug": False, "template": None,
            "pos": m.start(),
        }

    for m in re.finditer(r"'([a-z0-9-]+)'\s*=>\s*conexao_page_translation_county\(\s*'((?:[^'\\]|\\.)*)'\s*\)", text):
        slug, name = m.group(1), m.group(2)
        c = (
            f'<!-- wp:heading --><h2>{name} for Brazilians</h2><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>A complete guide to County {name} in Ireland. Information about housing, events, businesses, guides, jobs and restaurants.</p><!-- /wp:paragraph -->\n'
            f'<!-- wp:heading --><h3>Events</h3><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>Check out the events and activities happening in County {name}.</p><!-- /wp:paragraph -->\n'
            f'<!-- wp:heading --><h3>Businesses and Services</h3><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>Find Brazilian businesses and services in County {name}.</p><!-- /wp:paragraph -->\n'
            f'<!-- wp:heading --><h3>Practical Guides</h3><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>Useful guides for living in County {name}.</p><!-- /wp:paragraph -->\n'
            f'<!-- wp:heading --><h3>Jobs</h3><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>Work opportunities in County {name}.</p><!-- /wp:paragraph -->\n'
            f'<!-- wp:heading --><h3>Restaurants</h3><!-- /wp:heading -->\n'
            f'<!-- wp:paragraph --><p>Discover restaurants and food options in County {name}.</p><!-- /wp:paragraph -->'
        )
        entries[slug] = {
            "en_slug": "county-" + name.lower(),
            "title": "County " + name,
            "content": c,
            "meta_desc": f"A complete guide to County {name} in Ireland. Events, businesses, guides and jobs for Brazilians.",
            "helper": "county", "shared_slug": False, "template": None,
            "pos": m.start(),
        }

    for m in re.finditer(r"'([a-z0-9-]+)'\s*=>\s*array\(", text):
        slug = m.group(1)
        i = m.end()
        depth = 1
        start = i
        while i < len(text) and depth:
            ch = text[i]
            if ch == "'":
                _, i = parse_php_single_quoted(text, i)
                continue
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
            i += 1
        body = text[start:i - 1]

        def field_str(name):
            fm = re.search(r"'" + name + r"'\s*=>\s*'", body)
            if not fm:
                return None
            val, _ = parse_php_single_quoted(body, fm.end() - 1)
            return val

        entries[slug] = {
            "en_slug": field_str("en_slug"),
            "title": field_str("title"),
            "content": field_str("content"),
            "meta_desc": field_str("meta_desc"),
            "template": field_str("template"),
            "shared_slug": bool(re.search(r"'shared_slug'\s*=>\s*true", body)),
            "helper": None,
            "pos": m.start(),
        }

    order = [s for s, _ in sorted(entries.items(), key=lambda kv: kv[1]["pos"])]
    return entries, order


def block_sequence(content):
    """The rendered block skeleton: ordered (tag) list of structural elements,
    read identically from raw block markup or rendered HTML."""
    no_comments = re.sub(r"<!--/?wp:[^>]*-->", "", content)
    seq = []
    for m in re.finditer(r"<(h[1-6]|p|ul|ol)\b[^>]*>", no_comments):
        seq.append(m.group(1))
    return seq

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--inventory", default="stage45-work/page-inventory.json")
    ap.add_argument("--emit-manifest", default=None, help="write the deployable JSON manifest to this path")
    args = ap.parse_args()

    failures = []
    warnings = []

    inv = json.load(open(args.inventory))
    pages = {p["slug"]: p for p in inv["pages"]}
    # PT content for block-parity comes from the raw REST capture (the
    # inventory JSON is intentionally content-free).
    rest = json.load(open("stage45-work/inventory-cache/pages-rest.json"))
    pt_content = {p["slug"]: p["content"]["rendered"] for p in rest}
    classified_translate = {p["slug"]: p for p in inv["pages"] if p["classification"]["action"] == "translate"}
    classified_exclude = {p["slug"]: p for p in inv["pages"] if p["classification"]["action"] == "exclude"}
    order_expected = [p["slug"] for p in sorted(
        (p for p in inv["pages"] if p["classification"]["action"] == "translate"),
        key=lambda p: p["translation_order"])]

    entries, order = parse_map(MAP_PHP)

    # 1. coverage + order
    if set(entries) != set(classified_translate):
        failures.append(f"map/inventory set mismatch: only-in-map={sorted(set(entries) - set(classified_translate))} only-in-inventory={sorted(set(classified_translate) - set(entries))}")
    if order != order_expected:
        failures.append(f"creation order mismatch:\n  map:       {order}\n  inventory: {order_expected}")

    # 2. PT sources exist
    for slug in entries:
        if slug not in pages:
            failures.append(f"{slug}: PT source page not found in inventory")

    # 3. EN slug rules
    seen_en = {}
    for slug, e in entries.items():
        en = e["en_slug"]
        if not en or not re.fullmatch(r"[a-z0-9-]+", en):
            failures.append(f"{slug}: bad EN slug {en!r}")
        if en in seen_en:
            failures.append(f"EN slug {en!r} used twice ({seen_en[en]}, {slug})")
        seen_en[en] = slug
        if en in WP_CORE_RESERVED:
            failures.append(f"{slug}: EN slug {en!r} collides with a WP core route")
        if en in CPT_ARCHIVE_SLUGS and not e["shared_slug"]:
            failures.append(f"{slug}: EN slug {en!r} collides with a CPT archive base")
        if en in pages and not e["shared_slug"]:
            failures.append(f"{slug}: EN slug {en!r} already used by PT page #{pages[en]['pt_id']}")
        if en in pages and e["shared_slug"]:
            if en != slug:
                failures.append(f"{slug}: shared_slug set but EN slug differs from PT slug")
        if en in LEGACY_REDIRECT_SOURCES:
            warnings.append(f"{slug}: EN slug {en!r} is also a legacy redirect source at ROOT (/{en}/ 301s to PT); /en/{en}/ is unaffected (root-anchored rule) — conscious choice")

    # 4. shared-slug allowlist
    shared = sorted(s for s, e in entries.items() if e["shared_slug"])
    if shared != ["blog", "newsletter"]:
        failures.append(f"shared-slug set drifted: {shared}")

    # 5. block parity with the PT original (rendered PT content)
    for slug, e in entries.items():
        if slug not in pt_content:
            continue
        pt_seq = block_sequence(pt_content[slug])
        en_seq = block_sequence(e["content"] or "")
        if pt_seq != en_seq:
            failures.append(f"{slug}: block structure differs\n  pt={pt_seq}\n  en={en_seq}")

    # 6. link localisation simulation (apply order)
    en_of_pt = {}   # pt slug -> en slug as the run proceeds
    for slug in order:
        e = entries[slug]
        content = e["content"] or ""
        hrefs = re.findall(r'href="([^"]+)"', content)
        for href in hrefs:
            if href.startswith("http") and "conexaobr.ie" not in href:
                continue  # external — preserved
            if href.startswith("mailto:") or href.startswith("tel:") or href.startswith("#"):
                continue
            path = href
            m = re.match(r"^https://conexaobr\.ie(/[^\"]*)$", href)
            if m:
                path = m.group(1)
            t = path.strip("/")
            if t in REAL_ARCHIVE_SLUGS:
                continue  # resolves to the EN archive via conexao_language_archive_url
            if t in en_of_pt:
                continue  # resolves to the freshly created EN page
            if t in entries:
                failures.append(f"{slug}: links to /{t}/ which is translated LATER in the run (order violation)")
            else:
                failures.append(f"{slug}: internal link /{t}/ has no EN counterpart and is not an archive")
        en_of_pt[slug] = e["en_slug"]

    # 7. meta descriptions
    for slug, e in entries.items():
        if not e["meta_desc"]:
            failures.append(f"{slug}: missing EN meta description")
        pt_meta = ((pages.get(slug) or {}).get("http") or {}).get("meta_description")
        if pt_meta and e["meta_desc"] and e["meta_desc"].strip() == pt_meta.strip():
            failures.append(f"{slug}: EN meta description equals the PT one (untranslated)")

    # 8. exclusion parity
    excluded_in_map = set(pages) - set(entries)
    if excluded_in_map != set(classified_exclude):
        failures.append(f"exclusion set drift: map excludes {sorted(excluded_in_map)} vs classification {sorted(set(classified_exclude))}")

    print(f"map entries: {len(entries)}; inventory translate: {len(classified_translate)}; excluded: {len(classified_exclude)}")
    for w in warnings:
        print("WARN:", w)
    if failures:
        print("\nFAILURES:")
        for f in failures:
            print(" -", f)
        sys.exit(1)
    print("ALL MANIFEST CHECKS PASSED")

    if args.emit_manifest:
        emit_manifest(entries, order, pages, args.emit_manifest)


def emit_manifest(entries, order, pages, out_path):
    """Render the reviewable JSON manifest: the deployable translation data
    with the simulated post-state (EN URLs, link resolution)."""
    en_of_pt = {}
    items = []
    for slug in order:
        e = entries[slug]
        content = e["content"] or ""
        links = {}
        for href in re.findall(r'href="([^"]+)"', content):
            if href.startswith("mailto:") or href.startswith("tel:") or href.startswith("#"):
                continue
            if href.startswith("http") and "conexaobr.ie" not in href:
                continue
            path = href
            m = re.match(r"^https://conexaobr\.ie(/[^\"]*)$", href)
            if m:
                path = m.group(1)
            t = path.strip("/")
            if t in REAL_ARCHIVE_SLUGS:
                links[href] = f"/en/{t}/"
            elif t in en_of_pt:
                links[href] = f"/en/{en_of_pt[t]}/"
        pt = pages.get(slug) or {}
        items.append({
            "pt_slug": slug,
            "pt_id": pt.get("pt_id"),
            "pt_url": f"/{slug}/",
            "en_slug": e["en_slug"],
            "en_url": f"/en/{e['en_slug']}/",
            "en_title": e["title"],
            "meta_desc_en": e["meta_desc"],
            "template": e["template"] or pt.get("template") or "default",
            "shared_slug": bool(e["shared_slug"]),
            "menu_order": pt.get("menu_order", 0),
            "parent_pt": pt.get("parent_slug"),
            "parent_en": None,
            "featured_media": pt.get("featured_media", 0),
            "internal_links_localized": links,
            "content_en": content,
        })
        en_of_pt[slug] = e["en_slug"]

    doc = {
        "generated_by": "scripts/stage45-validate-manifest.py --emit-manifest",
        "source_of_truth": MAP_PHP + " (this JSON is a rendered view for review/ops)",
        "site": "https://conexaobr.ie",
        "count": len(items),
        "items": items,
    }
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=1)
    print(f"wrote {out_path} ({len(items)} pages)")


if __name__ == "__main__":
    main()
