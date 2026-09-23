#!/usr/bin/env python3
"""Validate the EN blog translations against their PT sources.

Checks per post:
  * JSON shape (all required fields, non-empty)
  * PT slug matches the file name and the source payload exists
  * HTML tag multiset parity (the translation must not drop/add structure)
  * href/src attribute set parity
  * heading level counts parity
  * no Portuguese leftovers in the English text
  * meta description length, slug format/uniqueness, reserved slugs

Exits non-zero when any error is found; warnings are informational.
"""
import glob
import html
import json
import os
import re
import sys
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
RESERVED = set(
    "blog guias eventos cursos empregos apoiadores lazer inicio sobre-nos contato "
    "newsletter revista anuncie politica-de-privacidade termos-de-uso cookies search "
    "moradia saude familia financas educacao negocios servicos compras transporte "
    "turismo voluntariado onde-comer beneficios documentos dublin cork galway limerick "
    "kildare meath wicklow waterford laois irlanda about home jobs sponsors guides "
    "events courses leisure about-us contact privacy-policy terms-of-use cookie-policy"
    .split()
)
PT_MARKERS = re.compile(
    r"\b(você|voce|não|nao|como|também|tambem|porque|para|sobre|muito|mais|é|são|"
    r"nós|nos|seu|sua|seus|suas|ainda|depois|quando|onde|isso|isto|está|estão|"
    r"fazer|pode|poder|sempre|nunca|nada|tudo|dias|anos|hoje|amanhã)\b",
    re.IGNORECASE,
)

errors = []
warnings = []


def tags(html_text):
    return Counter(re.findall(r"<\s*([a-zA-Z0-9]+)", html_text))


def attrs(html_text):
    hrefs = set(re.findall(r'href="([^"]+)"', html_text))
    srcs = set(re.findall(r'src="([^"]+)"', html_text))
    return hrefs, srcs


def main():
    en_files = sorted(glob.glob(os.path.join(HERE, "en", "*.json")))
    slugs = {}
    for path in en_files:
        name = os.path.basename(path)[:-5]
        data = json.load(open(path, encoding="utf-8"))
        where = name

        for key in ("pt_slug", "en_slug", "en_title", "en_excerpt",
                    "en_meta_description", "en_content"):
            if not data.get(key):
                errors.append(f"{where}: missing/empty {key}")

        if data.get("pt_slug") != name:
            errors.append(f"{where}: pt_slug mismatch ({data.get('pt_slug')})")

        src_path = os.path.join(HERE, "source", name + ".json")
        if not os.path.exists(src_path):
            errors.append(f"{where}: no PT source payload")
            continue
        src = json.load(open(src_path, encoding="utf-8"))
        src_html = src["content"]["rendered"]
        en_html = data.get("en_content", "")

        src_tags, en_tags = tags(src_html), tags(en_html)
        if src_tags != en_tags:
            missing = src_tags - en_tags
            extra = en_tags - src_tags
            errors.append(f"{where}: tag mismatch missing={dict(missing)} extra={dict(extra)}")

        src_href, src_src = attrs(src_html)
        en_href, en_src = attrs(en_html)
        link_map = data.get("link_map") or []
        mapped = {r["from"] for r in link_map}
        lost_hrefs = src_href - en_href - mapped
        new_hrefs = en_href - src_href
        if lost_hrefs:
            errors.append(f"{where}: hrefs lost {sorted(lost_hrefs)[:3]}")
        if new_hrefs:
            warnings.append(f"{where}: new hrefs {sorted(new_hrefs)[:3]}")
        if src_src != en_src:
            errors.append(f"{where}: src attributes differ")

        for level in ("h2", "h3", "h4", "h5", "h6"):
            a = len(re.findall(rf"<{level}[ >]", src_html))
            b = len(re.findall(rf"<{level}[ >]", en_html))
            if a != b:
                errors.append(f"{where}: {level} count {a} → {b}")

        text = html.unescape(re.sub(r"<[^>]+>", " ", en_html))
        text = re.sub(r"https?://\S+", " ", text)          # URLs keep PT path segments
        for proper in ("São Paulo", "Brasília", "Conexão BR", "Portlaoise",
                       "Laois", "Abbeyleix", "Portarlington"):
            text = text.replace(proper, " ")               # proper nouns stay verbatim
        leftover = sorted(set(m.group(0).lower() for m in PT_MARKERS.finditer(text)))
        if leftover:
            errors.append(f"{where}: Portuguese leftovers {leftover}")

        slug = data.get("en_slug", "")
        if not re.fullmatch(r"[a-z0-9-]+", slug):
            errors.append(f"{where}: bad en_slug {slug!r}")
        if slug in RESERVED:
            errors.append(f"{where}: en_slug collides with a reserved slug ({slug})")
        slugs.setdefault(slug, []).append(name)

        desc = data.get("en_meta_description", "")
        if len(desc) > 155:
            warnings.append(f"{where}: meta description {len(desc)} chars")
        if len(data.get("en_excerpt", "")) > 400:
            warnings.append(f"{where}: excerpt {len(data.get('en_excerpt'))} chars")

    dupes = {k: v for k, v in slugs.items() if len(v) > 1}
    if dupes:
        errors.append(f"duplicate EN slugs: {dupes}")

    print(f"checked {len(en_files)} translations")
    for warning in warnings:
        print("WARN:", warning)
    for error in errors:
        print("ERROR:", error)
    if errors:
        return 1
    print("ALL CHECKS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
