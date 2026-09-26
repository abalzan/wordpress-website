#!/usr/bin/env python3
"""Compare hook REGISTRATION ORDER (not just membership) before vs after."""
import re
import os
import sys

REPO = "/home/andrei/IdeaProjects/wordpress-website"
THEME = os.path.join(REPO, "wp-content/themes/conexao-br-irlanda")

# The loader order, read from the new functions.php.
LOADER = [
    "inc/i18n.php",
    "inc/i18n/guard.php", "inc/i18n/locale.php", "inc/i18n/urls.php",
    "inc/i18n/terms.php", "inc/i18n/fallback.php", "inc/i18n/hreflang.php",
    "inc/i18n/switcher.php",
    "inc/rest-language.php",
    "inc/seo/titles.php", "inc/seo/meta.php", "inc/seo/canonical.php",
    "inc/seo/hreflang.php", "inc/seo/open-graph.php", "inc/seo/robots.php",
    "inc/seo/schema.php", "inc/seo/sitemap.php",
    "inc/seo/redirects.php", "inc/seo/related.php",
    "inc/empregos-landing.php", "inc/job-resources.php",
    "inc/recruitment-agencies.php", "inc/permit-employers.php",
    "inc/employment-opportunities.php", "inc/post-views.php",
    "inc/search.php",
    "inc/admin.php", "inc/queries.php",
    "inc/setup.php", "inc/assets.php", "inc/performance.php", "inc/cache.php",
    "inc/content.php", "inc/sponsors.php", "inc/customizer.php",
    "inc/archive-filters.php",
    "inc/leisure.php", "inc/events.php", "inc/archive-query.php",
    "inc/b2-fallback.php", "inc/shortcodes.php", "inc/ui.php",
    "inc/navigation.php",
]

# The OLD effective load order: i18n foundation, polylang, REST, SEO, then the
# domain modules the old loader required, then the body of functions.php
# (which is where all the new inc/*.php modules came from, in source order).
OLD_ORDER = [
    "inc/i18n.php",
    "__polylang__",
    "inc/rest-language.php",
    "__seo__",
    "inc/empregos-landing.php", "inc/job-resources.php",
    "inc/recruitment-agencies.php", "inc/permit-employers.php",
    "inc/employment-opportunities.php", "inc/post-views.php",
    "inc/search.php",
]
# modules extracted from the functions.php body, in original source order
BODY_ORDER = [
    "inc/admin.php", "inc/queries.php", "inc/setup.php", "inc/assets.php",
    "inc/performance.php", "inc/cache.php", "inc/content.php",
    "inc/sponsors.php", "inc/customizer.php", "inc/archive-filters.php",
    "inc/leisure.php", "inc/events.php", "inc/archive-query.php",
    "inc/b2-fallback.php", "inc/shortcodes.php", "inc/ui.php",
    "inc/navigation.php",
]

HOOK_RE = re.compile(
    r'^(add_action|add_filter|remove_action|remove_filter|do_action|apply_filters)'
    r'\s*\(\s*([\'"])([^\'"]+)\2\s*,\s*(.+?)\);', re.M | re.S)


def collect(paths_and_sources):
    seq = []
    for rel, src in paths_and_sources:
        for m in HOOK_RE.finditer(src):
            kind, hook, rest = m.group(1), m.group(3), " ".join(m.group(4).split())
            seq.append("%s|%s|%s" % (kind, hook, rest))
    return seq


import subprocess


def git_show(rel):
    return subprocess.run(
        ["git", "show", "HEAD:wp-content/themes/conexao-br-irlanda/" + rel],
        cwd=REPO, capture_output=True, text=True, check=True).stdout


def disk(rel):
    with open(os.path.join(THEME, rel), encoding="utf-8") as fh:
        return fh.read()


def slice_original(monolith_rel, spec_path, key):
    """Read the body modules' hook registrations out of the original monolith."""
    import json
    lines = git_show(monolith_rel).split("\n")
    out = []
    for path, _t, _d, spans in json.load(open(spec_path))[key]["modules"]:
        body = "".join("\n".join(lines[s - 1:e]) + "\n" for s, e in spans)
        out.append((path, body))
    return out


old_files = [(e, git_show(e) if e not in ("__polylang__", "__seo__")
              else git_show("inc/polylang.php" if e == "__polylang__" else "inc/seo.php"))
             for e in OLD_ORDER]
# The body modules came from the tail of the old functions.php: they ran AFTER
# the domain modules. Reconstruct that order explicitly.
old_files = old_files + slice_original("functions.php", "/tmp/stagef/spec-functions.json", 0)
old_seq = collect(old_files)

new_seq = collect([(m, disk(m)) for m in LOADER])

# The old body hooks ran AFTER the domain modules (they were at the end of
# functions.php); the new loader runs the body modules BEFORE them. Compare as
# multisets first, then report the ordering difference explicitly.
if sorted(old_seq) == sorted(new_seq):
    print("HOOK MULTISET: IDENTICAL (%d registrations)" % len(old_seq))
else:
    print("HOOK MULTISET: DIFFERS")
    for line in sorted(set(old_seq) ^ set(new_seq)):
        print("  " + line)

print()
print("first 6 OLD:", old_seq[:6])
print("first 6 NEW:", new_seq[:6])
print()
old, new = old_seq, new_seq


def by_hook(seq):
    """WordPress only preserves order among callbacks of the SAME hook at the
    SAME priority. Group into {hook: {priority: [callbacks in order]}}."""
    out = {}
    for item in seq:
        kind, hook, rest = item.split("|", 2)
        if kind not in ("add_action", "add_filter"):
            continue
        parts = [p.strip() for p in rest.split(",")]
        cb = parts[0]
        prio = parts[1] if len(parts) > 1 else "10"
        out.setdefault(hook, {}).setdefault(prio, []).append(cb)
    return out


oh, nh = by_hook(old), by_hook(new)
problems = []
for hook in sorted(set(oh) | set(nh)):
    op, np_ = oh.get(hook, {}), nh.get(hook, {})
    for prio in sorted(set(op) | set(np_), key=lambda x: int(x)):
        o, n = op.get(prio, []), np_.get(prio, [])
        if o != n:
            problems.append("hook=%-28s priority=%-4s OLD=%s NEW=%s"
                            % (hook, prio, o, n))

print()
print("=== SAME-HOOK / SAME-PRIORITY EXECUTION ORDER ===")
if problems:
    for p in problems:
        print("  DIFF " + p)
else:
    print("  IDENTICAL: every hook fires its callbacks in the same order")
print("flat registration sequence identical:", old == new)
print("flat registrations differing in position:",
      len([i for i, (a, b) in enumerate(zip(old, new)) if a != b]))
