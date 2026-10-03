#!/usr/bin/env python3
"""
Stage F - before/after symbol + hook differential.

Usage: diffsymbols.py <mode> <out-file>
  mode=before  scan the three ORIGINAL monoliths (from git HEAD)
  mode=after   scan every theme runtime PHP file (loader + inc/**)
"""
import re
import os
import sys
import subprocess

REPO = "/home/andrei/IdeaProjects/wordpress-website"
THEME = os.path.join(REPO, "wp-content/themes/conexao-br-irlanda")
MONOLITHS = ["functions.php", "inc/polylang.php", "inc/seo.php"]
# Stage F created these; together with the untouched inc/*.php modules they form
# exactly the set of files that made up the three monoliths plus the modules the
# loader already required. Both modes scan the SAME set, so the comparison is
# like-for-like.
REFACTORED = [
    "functions.php",
    "inc/i18n/guard.php", "inc/i18n/locale.php", "inc/i18n/urls.php",
    "inc/i18n/terms.php", "inc/i18n/fallback.php", "inc/i18n/hreflang.php",
    "inc/i18n/switcher.php",
    "inc/seo/titles.php", "inc/seo/meta.php", "inc/seo/canonical.php",
    "inc/seo/open-graph.php", "inc/seo/schema.php", "inc/seo/sitemap.php",
    "inc/seo/robots.php", "inc/seo/redirects.php", "inc/seo/related.php",
    "inc/admin.php", "inc/queries.php", "inc/setup.php", "inc/assets.php",
    "inc/performance.php", "inc/cache.php", "inc/content.php",
    "inc/sponsors.php", "inc/customizer.php", "inc/archive-filters.php",
    "inc/leisure.php", "inc/events.php", "inc/archive-query.php",
    "inc/b2-fallback.php", "inc/shortcodes.php", "inc/ui.php",
    "inc/navigation.php",
    # pre-existing modules the loader already required
    "inc/i18n.php", "inc/rest-language.php", "inc/empregos-landing.php",
    "inc/job-resources.php", "inc/recruitment-agencies.php",
    "inc/permit-employers.php", "inc/employment-opportunities.php",
    "inc/post-views.php", "inc/search.php",
]
PREEXISTING = [
    f for f in REFACTORED
    if not f.startswith("inc/i18n/") and not f.startswith("inc/seo/")
    and f not in ("functions.php",) and f in (
        "inc/i18n.php", "inc/rest-language.php", "inc/empregos-landing.php",
        "inc/job-resources.php", "inc/recruitment-agencies.php",
        "inc/permit-employers.php", "inc/employment-opportunities.php",
        "inc/post-views.php", "inc/search.php")
]


def read_after(rel):
    with open(os.path.join(THEME, rel), encoding="utf-8") as fh:
        return fh.read()


def read_before(rel):
    return subprocess.run(
        ["git", "show", "HEAD:wp-content/themes/conexao-br-irlanda/" + rel],
        cwd=REPO, capture_output=True, text=True, check=True).stdout


def theme_php_files():
    out = []
    for root, dirs, files in os.walk(THEME):
        dirs[:] = [d for d in dirs if d != "tests"]
        for f in files:
            if f.endswith(".php"):
                p = os.path.join(root, f)
                out.append(os.path.relpath(p, THEME))
    return sorted(out)


def collect(mode):
    if mode == "before":
        # The pre-refactor runtime was exactly: the 3 monoliths + the 9 modules
        # the old loader already required. The Stage F modules did not exist.
        files = [(m, read_before(m)) for m in MONOLITHS]
        files += [(f, read_before(f)) for f in PREEXISTING]
    else:
        files = [(f, read_after(f)) for f in REFACTORED
                 if os.path.exists(os.path.join(THEME, f))]

    funcs, hooks = {}, []
    for rel, src in files:
        for m in re.finditer(r'^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*(\([^)]*\))', src, re.M):
            funcs.setdefault(m.group(1), []).append(
                (rel, m.group(2).replace(" ", "")))
        for m in re.finditer(
                r'^(add_action|add_filter|remove_action|remove_filter|do_action|apply_filters)'
                r'\s*\(\s*([\'"])([^\'"]+)\2\s*,\s*(.+?)\);',
                src, re.M | re.S):
            kind, hook, rest = m.group(1), m.group(3), " ".join(m.group(4).split())
            rest = re.sub(r"\s+", " ", rest)
            hooks.append((kind, hook, rest))

    return funcs, hooks


if __name__ == "__main__":
    mode, out = sys.argv[1], sys.argv[2]
    funcs, hooks = collect(mode)
    with open(out, "w", encoding="utf-8") as fh:
        fh.write("## FUNCTIONS (%d unique)\n" % len(funcs))
        for n in sorted(funcs):
            for rel, sig in funcs[n]:
                fh.write("F|%s|%s|%s\n" % (n, sig, rel))
        fh.write("\n## HOOKS (%d)\n" % len(hooks))
        for k, h, r in sorted(hooks):
            fh.write("H|%s|%s|%s\n" % (k, h, r))
        dups = {n: v for n, v in funcs.items() if len(v) > 1}
        fh.write("\n## DUPLICATE DEFINITIONS (%d)\n" % len(dups))
        for n, v in sorted(dups.items()):
            fh.write("D|%s|%s\n" % (n, ",".join(r for r, _ in v)))
    print("%s: %d unique functions, %d hook calls, %d duplicated defs"
          % (mode, len(funcs), len(hooks),
             sum(1 for v in funcs.values() if len(v) > 1)))
