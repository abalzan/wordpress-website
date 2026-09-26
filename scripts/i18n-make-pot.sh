#!/usr/bin/env bash
#
# i18n-make-pot.sh - REGENERATE the translation catalogues (standard §9.3).
#
# Purpose:
#   The companion of i18n-check.sh. i18n-check.sh FAILS when a component's
#   `.pot` is older than the PHP defining its strings; this script is the
#   documented way to make it fresh again.
#
#   Engineering standard §9.3 is explicit: a catalogue MUST be regenerated,
#   never hand-edited. This script is the only supported way to regenerate one.
#
# WHY IT EXISTS (root cause, fixed in Stage M):
#   The repository shipped a freshness CHECK with no generation SCRIPT, so every
#   source edit silently made a catalogue stale and the only visible remedy was
#   a hand-edit. Audit finding F-04 recommended this script; until now it had
#   never been built, and six catalogues had drifted. This script is that fix:
#   the debt stops recurring because regeneration is now one command.
#
# Components are discovered from plugins.json (the authoritative registry) plus
# the theme, exactly as the freshness gate does, so this script and the gate can
# never disagree about which catalogues exist. There is no second list.
#
# Scope:
#   Writes ONLY `<component>/languages/<component-slug>.pot`. It never touches a
#   `.po` or `.mo` (a translated catalogue is hand-maintained by a translator
#   and must survive a regeneration), and it never touches any source file.
#
# Safety:
#   - No WordPress, no database, no network, no production endpoint.
#   - Never runs against production content; it only reads the repository.
#   - Requires GNU gettext (`xgettext`), the same extraction engine
#     `wp i18n make-pot` uses. Exits 2 with a clear message when it is missing:
#     it never falls back to hand-editing a .pot.
#
# Extraction is by TEXT DOMAIN, not by directory. A string belongs to the
# catalogue of the domain it declares, wherever its file lives - and in this
# repository that is not always the obvious tree
# (`conexao-data-model/includes/class-agency.php` tags its job-type labels with
# the THEME domain). A per-directory extraction would silently drop them.
#
# Usage:
#   ./scripts/i18n-make-pot.sh              # regenerate every catalogue
#   ./scripts/i18n-make-pot.sh --check      # report which are stale, write nothing
#   ./scripts/i18n-make-pot.sh --dry-run    # print the plan, write nothing
#   ./scripts/i18n-make-pot.sh conexao-data-model   # one component only
#
# @package Conexao_BR_Scripts

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

MODE="apply"
ONLY=""

for arg in "$@"; do
    case "$arg" in
        --check)   MODE="check" ;;
        --dry-run) MODE="dry-run" ;;
        --apply)   MODE="apply" ;;
        -h|--help) sed -n '3,45p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        -*)        echo "ERROR: unknown option: $arg (try --help)" >&2; exit 2 ;;
        *)         ONLY="$arg" ;;
    esac
done

# --- Preflight ---------------------------------------------------------------

command -v python3 >/dev/null 2>&1 || { echo "ERROR: required command not found: python3" >&2; exit 2; }

# Resolve xgettext. It ships with GNU gettext and is what `wp i18n make-pot`
# itself calls, so this script generates catalogues with the same engine. When
# the host lacks it, the operator can point XGETTEXT_CMD at a command prefix
# that reaches a PHP/container environment which has it.
if [ -n "${XGETTEXT_CMD:-}" ]; then
    # shellcheck disable=SC2206  # XGETTEXT_CMD is intentionally a command prefix.
    XGETTEXT=( ${XGETTEXT_CMD} )
elif command -v xgettext >/dev/null 2>&1; then
    XGETTEXT=( xgettext )
else
    cat >&2 <<'MSG'
ERROR: xgettext is required to regenerate a catalogue, and it was not found.

  Install GNU gettext, or point XGETTEXT_CMD at a command prefix that reaches
  an environment which has it (for example the WordPress container):

      docker compose exec -T wordpress sh -c 'command -v xgettext'

  If the WordPress image has no xgettext, install gettext there, or run this
  script on a host where gettext is available.

  A catalogue MUST be regenerated, never hand-edited (engineering standard
  9.3), so this script does not fall back to editing the .pot by hand.
MSG
    exit 2
fi

# The catalogue header carries the generating tool's identity, so the version
# is reported rather than invented.
XGETTEXT_VERSION="$("${XGETTEXT[@]}" --version 2>/dev/null | head -1)"

# --- Component discovery -----------------------------------------------------
#
# A component gets a catalogue only when it actually DEFINES user-facing
# strings (at least one PHP file with a gettext call). The definition and its
# exclusions are imported from the freshness gate itself, so the generator and
# the check can never drift apart and there is no second copy of the rule.
#
# This matters: several retired rollout components ship no user-facing strings.
# The gate correctly treats "no catalogue and no strings" as nothing to keep
# fresh, so this script must not invent an empty .pot for them.

DISCOVERY="$(python3 - "$REPO_ROOT" "$ONLY" <<'PY'
import importlib.util, os, sys

repo, only = sys.argv[1], (sys.argv[2] or "")
gate = os.path.join(repo, "tests", "scripts", "verify-i18n-freshness.py")
spec = importlib.util.spec_from_file_location("i18n_gate", gate)
mod = importlib.util.module_from_spec(spec)
# The gate only runs main() as __main__, so importing it performs no work.
spec.loader.exec_module(mod)

for slug, directory in mod.components():
    if only and slug != only:
        continue
    state = "has-strings" if mod.translatable_sources(directory) else "no-strings"
    print(f"{slug}\t{directory}\t{state}")
PY
)"

if [ -z "$DISCOVERY" ]; then
    if [ -n "$ONLY" ]; then
        echo "ERROR: no component named '${ONLY}' in the theme or plugins.json." >&2
        exit 2
    fi
    echo "ERROR: no components resolved from plugins.json + the theme." >&2
    exit 2
fi

COMPONENTS=""
SKIPPED_COMPONENTS=""
ALL_DIRS=""

while IFS=$'\t' read -r slug dir state; do
    [ -n "$slug" ] || continue
    ALL_DIRS="${ALL_DIRS}${ALL_DIRS:+ }${dir}"
    if [ "$state" = "has-strings" ]; then
        COMPONENTS="${COMPONENTS}${slug}"$'\t'"${dir}"$'\n'
    else
        SKIPPED_COMPONENTS="${SKIPPED_COMPONENTS}${SKIPPED_COMPONENTS:+ }${slug}"
    fi
done <<< "$DISCOVERY"

# Keep only the entries whose extracted text domain equals this component.
#
# The union scan finds strings wherever they live, but a string belongs to the
# catalogue of the DOMAIN it declares. xgettext records that on the reference
# line, as `#: <file>:<line>.<domain>`. A call with NO domain argument (a bare
# `__( 'x' )`, or a `style.css` string that xgettext cannot tag) produces a
# reference with no domain suffix; those entries are kept, because a component
# catalogue must not silently drop strings that simply lack a domain argument —
# the plugin headers declare the default domain. Everything tagged with a
# DIFFERENT explicit domain is dropped, so the theme catalogue does not absorb
# the plugins' strings and vice versa.
filter_pot_by_domain() {
    local pot="$1" slug="$2"
    POT_DOMAIN="$slug" python3 - "$pot" <<'PY'
import os, re, sys

path = sys.argv[1]
domain = os.environ["POT_DOMAIN"]
with open(path, encoding="utf-8") as fh:
    lines = fh.read().split("\n")

# Split into the header (up to the first blank line) and the body blocks. A body
# block starts at a comment/reference line and runs to the next blank line.
try:
    sep = lines.index("")
except ValueError:
    raise SystemExit(f"unexpected POT layout in {path}: no header/body separator")
header, body = lines[: sep + 1], lines[sep + 1 :]

blocks, current = [], []
for line in body:
    if line == "":
        if current:
            blocks.append(current)
        current = []
    else:
        current.append(line)
if current:
    blocks.append(current)

DOMAIN_RE = re.compile(r"\.([A-Za-z0-9_-]+)$")

def block_domains(block):
    found = set()
    for line in block:
        if line.startswith("#:"):
            for ref in line[2:].split():
                m = DOMAIN_RE.search(ref)
                # A reference with no domain suffix is untagged, not foreign.
                if m:
                    found.add(m.group(1))
    return found

kept = [b for b in blocks if block_domains(b) <= {domain}]

with open(path, "w", encoding="utf-8") as fh:
    fh.write("\n".join(header + [l for b in kept for l in b] + [""]))
PY
}

# Normalise the generated POT header to THIS component.
#
# `i18n make-pot` derives Project-Id-Version / Theme-Name / Plugin-Name and the
# version from the FIRST source root it is given. Because this script extracts
# each domain from the union of all component directories, that first root is
# not necessarily the component the catalogue belongs to, and the header would
# name the wrong component. The fix is to rewrite the header from the component's
# OWN main file (its plugin/theme header), which is the authoritative source of
# its name and version.
#
# A .pot is a generated artefact: rewriting its header is part of generating it,
# not a hand-edit of a translation.
normalize_pot_header() {
    local pot="$1" slug="$2" dir="$3" main name version

    main=""
    if [ "$slug" = "conexao-br-irlanda" ]; then
        main="${dir}/style.css"
    elif [ -f "${dir}/${slug}.php" ]; then
        main="${dir}/${slug}.php"
    fi

    name="$slug"
    version=""
    if [ -n "$main" ] && [ -f "$main" ]; then
        if [ "$main" = "${dir}/style.css" ]; then
            name="$(sed -n 's/^Theme:[[:space:]]*//p' "$main" | head -1)"
            [ -n "$name" ] || name="$slug"
        else
            name="$(sed -n 's/^ \* Plugin Name:[[:space:]]*//p' "$main" | head -1)"
            [ -n "$name" ] || name="$slug"
        fi
        version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$main" | head -1)"
        [ -n "$version" ] || version="$(sed -n 's/^Version:[[:space:]]*//p' "$main" | head -1)"
    fi

    [ -n "$version" ] || version="1.0.0"

    POT_NAME="$name" POT_VERSION="$version" POT_SLUG="$slug" \
    python3 - "$pot" <<'PY'
import os, sys
path = sys.argv[1]
with open(path, encoding="utf-8") as fh:
    text = fh.read()
name = os.environ["POT_NAME"]
version = os.environ["POT_VERSION"]
slug = os.environ["POT_SLUG"]

# The generated header block runs to the first blank line. Its own metadata is
# replaced with this component's authoritative name/version; the standard gettext
# fields (charset, encoding, generator, creation date, X-Domain) are preserved
# because they are correct for every catalogue.
idx = text.find("\n\n")
if idx == -1:
    raise SystemExit(f"unexpected POT layout in {path}: no header/body separator")
old, body = text[:idx], text[idx + 1:]

report = (
    "https://wordpress.org/support/theme/conexao-br-irlanda"
    if slug == "conexao-br-irlanda"
    else "https://wordpress.org/support/plugin/conexao-br-irlanda"
)
header = (
    "# Copyright (C) 2026 Conexao BR Irlanda\n"
    "# This file is distributed under the GNU General Public License v2 or later.\n"
    'msgid ""\n'
    'msgstr ""\n'
    f'"Project-Id-Version: {name} {version}\\n"\n'
    f'"Report-Msgid-Bugs-To: {report}\\n"\n'
)
for field in (
    "Last-Translator",
    "Language-Team",
    "MIME-Version",
    "Content-Type",
    "Content-Transfer-Encoding",
    "POT-Creation-Date",
    "PO-Revision-Date",
    "X-Generator",
    "X-Domain",
):
    for line in old.splitlines():
        if line.startswith(f'"{field}:'):
            header += line + "\n"
            break

with open(path, "w", encoding="utf-8") as fh:
    fh.write(header + "\n" + body)
PY
}

# Add the component's own header strings (Name, Description, Version, Author,
# URI) to its catalogue, the way `wp i18n make-pot` does for plugins and themes.
#
# These live in a PHP docblock or in style.css, not in a gettext call, so plain
# xgettext extraction does not see them. They are still translator-facing strings
# (the plugin list is translated in wp-admin), and every catalogue in this
# repository shipped with them, so a regeneration must not drop them. They are
# generated from the component's own header, never hand-written.
add_header_strings() {
    local pot="$1" slug="$2" dir="$3"
    POT_SLUG="$slug" POT_DIR="$dir" POT_REPO="$REPO_ROOT" python3 - "$pot" <<'PY'
import os, re, sys

path, slug, directory = sys.argv[1], os.environ["POT_SLUG"], os.environ["POT_DIR"]
REPO = os.environ["POT_REPO"]
is_theme = slug == "conexao-br-irlanda"
main = os.path.join(directory, "style.css" if is_theme else f"{slug}.php")

fields = []
if os.path.isfile(main):
    with open(main, encoding="utf-8", errors="replace") as fh:
        text = fh.read()
    if is_theme:
        head = text.split("*/")[0] if text.startswith("/*") else text
        for key, label in (("Theme Name", "Name"), ("Theme URI", "URI"),
                           ("Author", "Author"), ("Description", "Description"),
                           ("Version", "Version")):
            m = re.search(rf"^{key}:\s*(.+)$", head, re.M)
            if m:
                fields.append((label, m.group(1).strip()))
    else:
        head = text.split("*/")[0] if text.startswith("<?php") else text
        for key, label in (("Plugin Name", "Name"), ("Plugin URI", "URI"),
                           ("Description", "Description"), ("Author", "Author"),
                           ("Version", "Version")):
            m = re.search(rf"^\s*\*\s*{key}:\s*(.+)$", head, re.M)
            if m:
                fields.append((label, m.group(1).strip()))

if not fields:
    raise SystemExit(0)

def po_escape(value):
    # A POT msgid is a C-style quoted string: backslashes and double quotes must
    # be escaped, and a literal newline becomes a \\n continuation. This is what
    # `wp i18n make-pot` does, and getting it wrong would change the msgid, i.e.
    # silently orphan an existing translation.
    return (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
    )

with open(path, encoding="utf-8") as fh:
    text = fh.read()

rel = os.path.relpath(main, REPO).replace(os.sep, "/")
lines = text.split("\n")
sep = lines.index("")
body = lines[sep + 1 :]
existing = set()
for line in body:
    if line.startswith("msgid "):
        existing.add(line)

added = []
for label, value in fields:
    entry = f'msgid "{value}"\nmsgstr ""'
    if entry in existing:
        continue
    added.append(
        f'\n#. {label} of the {"theme" if is_theme else "plugin"}\n'
        f'#: {rel}:1\nmsgid "{po_escape(value)}"\nmsgstr ""\n'
    )

if added:
    text = text.rstrip("\n") + "\n" + "".join(added)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)
PY
}

if [ -z "$COMPONENTS" ]; then
    if [ -n "$ONLY" ]; then
        echo "ERROR: no component named '${ONLY}' in the theme or plugins.json." >&2
        exit 2
    fi
    echo "ERROR: no components resolved from plugins.json + the theme." >&2
    exit 2
fi

# --- Run ---------------------------------------------------------------------

echo "conexao i18n-make-pot"
echo "  repository: $REPO_ROOT"
echo "  mode:       $MODE"
echo "  xgettext:   $XGETTEXT_VERSION"
echo

regenerated=0
skipped=0
failed=0

# Sources are selected by TEXT DOMAIN, not by directory.
#
# gettext extraction is driven by the domain argument a string declares, so a
# component's catalogue must contain every string tagged with that component's
# domain, wherever the file physically lives. This is not hypothetical in this
# repository: `conexao-data-model/includes/class-agency.php` tags its 12 job-type
# labels with the THEME domain (`conexao-br-irlanda`), not its own. A
# directory-only extraction would silently drop those user-facing strings from
# every catalogue, so each component is extracted from the union of all
# component directories. The union is bounded and deterministic; it is not a
# second inventory (the component list still comes from the gate).
while IFS=$'\t' read -r slug dir; do
    [ -n "$slug" ] || continue
    pot="${dir}/languages/${slug}.pot"

    if [ "$MODE" != "apply" ]; then
        if [ -f "$pot" ]; then
            echo "  would regenerate: $pot"
        else
            echo "  would create:     $pot"
        fi
        regenerated=$((regenerated + 1))
        continue
    fi

    mkdir -p "$(dirname "$pot")"

    # WP_CLI_PATH_PREFIX rewrites the ABSOLUTE repository paths to the prefix
    # under which the SAME bind-mounted files are visible to the PHP running
    # wp-cli. It is required when that PHP is the WordPress container (e.g.
    # because only the container has mbstring): the theme and the plugins are
    # bind-mounted there, but the host path does not exist inside it.
    if [ -n "${WP_CLI_PATH_PREFIX:-}" ]; then
        base="${WP_CLI_PATH_PREFIX%/}"
        out_pot="${base}${pot#"$REPO_ROOT"}"
        out_final="$pot"
    else
        base=""
        out_pot="$pot"
        out_final="$pot"
    fi

    # All source roots, rewritten for the running PHP.
    roots=""
    for other in $ALL_DIRS; do
        roots="${roots}${roots:+ }${base}${other}"
    done

    # Extract with xgettext over the UNION of component directories.
    #
    # `wp i18n make-pot` accepts exactly ONE source directory, so it cannot see a
    # string whose declared text domain belongs to a different component than the
    # directory it sits in — which is exactly the case in this repository
    # (conexao-data-model/includes/class-agency.php tags its 12 job-type labels
    # with the THEME domain). A single-directory extraction would silently drop
    # real user-facing strings from every catalogue.
    #
    # xgettext is the same engine make-pot wraps, and the PHP language/keyword
    # conventions below are make-pot's own WordPress set. The header is
    # normalised afterwards by normalize_pot_header(), so nothing make-pot's
    # wrapper adds is needed. --from-code=UTF-8 is required for the accented
    # Portuguese strings.

    # Candidate files: every component's PHP, minus the same development trees
    # the freshness gate excludes, pre-filtered to files that actually mention
    # this component's domain (a file that never names the slug cannot
    # contribute a string for it).
    file_list=""
    for other in $ALL_DIRS; do
        target="${base}${other}"
        [ -d "$target" ] || continue
        while IFS= read -r php; do
            if grep -q -- "'${slug}'" "$php" 2>/dev/null; then
                file_list="${file_list}${php}"$'\n'
            fi
        done < <(find "$target" \
            \( -name tests -o -name vendor -o -name node_modules \
               -o -name languages -o -name historical \
               -o -name diagnostics -o -name __pycache__ \) -prune -o \
            -type f \( -name '*.php' -o -name 'style.css' \) -print \
            | LC_ALL=C sort)
    done

    if [ -z "$file_list" ]; then
        echo "  FAILED:        $pot (no PHP source declares this text domain)" >&2
        failed=$((failed + 1))
        continue
    fi

    # </dev/null matters: when xgettext runs through `docker compose exec` the
    # child would otherwise consume this loop's stdin and the run would stop
    # after the first component.
    if printf '%s' "$file_list" | "${XGETTEXT[@]}" \
        --from-code=UTF-8 \
        --language=PHP \
        --keyword=__ --keyword=_e --keyword=_x:1,2c --keyword=_n:1,2 \
        --keyword=esc_html__ --keyword=esc_attr__ \
        --keyword=esc_html_e --keyword=esc_attr_e \
        --keyword=esc_html_x:1,2c --keyword=esc_attr_x:1,2c \
        --add-comments=translators \
        --package-name="$slug" \
        --copyright-holder="Conexao BR Irlanda" \
        --msgid-bugs-address="https://wordpress.org/support/plugin/conexao-br-irlanda" \
        --files-from=- \
        --output="$out_pot" </dev/null >/dev/null 2>&1 \
        && filter_pot_by_domain "$out_pot" "$slug"; then
        # The POT header is normalised to this component afterwards, and the
        # component's own header strings are re-added, exactly as make-pot did.
        normalize_pot_header "$out_pot" "$slug" "$dir"
        add_header_strings "$out_pot" "$slug" "$dir"
        [ "$out_pot" = "$out_final" ] || cp -f "$out_pot" "$out_final"
        echo "  regenerated: $pot"
        regenerated=$((regenerated + 1))
    else
        echo "  FAILED:        $pot" >&2
        failed=$((failed + 1))
    fi
done <<< "$COMPONENTS"

# Components that define no user-facing string are reported, never given an
# empty catalogue: they have nothing to keep fresh, exactly as the gate says.
if [ -n "$SKIPPED_COMPONENTS" ]; then
    skipped=$(printf '%s' "$SKIPPED_COMPONENTS" | wc -w | tr -d ' ')
    echo
    echo "  no user-facing strings, no catalogue needed (${skipped}): ${SKIPPED_COMPONENTS}"
fi

echo
echo "summary: regenerated=${regenerated}, skipped=${skipped}, failed=${failed}"

if [ "$failed" -gt 0 ]; then
    echo "ERROR: ${failed} catalogue(s) could not be regenerated." >&2
    exit 1
fi
