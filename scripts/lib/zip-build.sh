#!/usr/bin/env bash
#
# zip-build.sh — shared deterministic ZIP packaging for the release build.
#
# Purpose: one implementation of "how a release artifact is produced", used by
# both scripts/build-plugins-zip.sh and scripts/build-theme-zip.sh. Engineering
# standard §11 makes the *rules* binding (exclude tests/fixtures/reports/junk,
# every artifact carries a version, file count and sha256); duplicating the
# implementation in two build scripts is exactly the "two places is a bug" drift
# the standard forbids, so the rules live here once.
#
# Scope:
#   - Selects the files that belong in a release artifact (exclusion rules).
#   - Packages them DETERMINISTICALLY: identical inputs produce a
#     byte-identical ZIP, so a sha256 in dist/release.json is reproducible.
#   - Emits the per-artifact facts the release manifest records:
#     file count, byte size and SHA-256.
#   - This library WRITES to the build output directory only. It never touches
#     production, never contacts the network and never modifies sources.
#
# Safety: local-write. It only creates files under the caller-supplied output
# directory (dist/ by default, which is git-ignored).
#
# Sourced, never executed.

# Guard against double-sourcing (both build scripts use `set -euo pipefail`).
if [ -n "${CONEXAO_ZIP_BUILD_LOADED:-}" ]; then
    return 0 2>/dev/null || true
fi
CONEXAO_ZIP_BUILD_LOADED=1

# --- Deterministic epoch ----------------------------------------------------
#
# ZIP entries store an mtime. Left alone, `zip` embeds the checkout mtime, so the
# same source tree produces a different sha256 on every fresh clone and the
# manifest hash stops being a reproducibility claim.
#
# Resolution order (first match wins):
#   1. SOURCE_DATE_EPOCH — the reproducible-builds convention.
#   2. the newest mtime among the files being packaged, i.e. the last real
#      edit. This is the defensible default: stable for a given source state
#      and changing exactly when the content changes.
#   3. 0 — fallback, still deterministic.
conexao_zip_epoch() {
    if [ -n "${SOURCE_DATE_EPOCH:-}" ]; then
        printf '%s\n' "${SOURCE_DATE_EPOCH}"
        return 0
    fi

    local newest
    newest="$(find "$@" -type f -printf '%T@\n' 2>/dev/null \
        | sort -rn | head -1 | cut -d. -f1 || true)"

    if [ -n "${newest}" ]; then
        printf '%s\n' "${newest}"
        return 0
    fi

    printf '0\n'
}

# --- File selection ----------------------------------------------------------
#
# conexao_zip_file_list <source-root> <slug> <profile>
#
# Prints the release-eligible files of <slug>, one relative path per line,
# sorted byte-wise (LC_ALL=C) so the order is stable across machines and locales.
#
# profile = plugin | theme
#
# Rules (engineering standard §11):
#   - tests/ and fixtures/ never ship in a release ZIP.
#   - dev-only trees and editor/OS junk never ship.
#   - plugin ZIPs additionally exclude *.json reports (§11) but KEEP the
#     languages/*.pot translation catalogues, which are release content.
conexao_zip_file_list() {
    local source_root="$1" slug="$2" profile="$3"

    (
        cd "${source_root}" || exit 1

        find "${slug}" \
            -type d \
                \( -name tests -o -name fixtures -o -name node_modules \
                   -o -name .git -o -name __pycache__ -o -name .idea \
                   -o -name .vscode -o -name vendor \) -prune \
            -o -type f \
                ! -name '*.json' \
                ! -name '*.log' \
                ! -name '*.pyc' \
                ! -name '.DS_Store' \
                ! -name 'Thumbs.db' \
                ! -name '*.swp' \
                ! -name '*~' \
                ! -name '*.orig' \
                ! -name '*.rej' \
                ! -name '*.bak' \
                -print
    ) | LC_ALL=C sort
}

# --- Packaging ---------------------------------------------------------------
#
# conexao_zip_package <source-root> <slug> <output-zip> <profile>
#
# Builds <output-zip> containing <slug>/** and prints a single machine-readable
# record on stdout:
#
#   <slug> <file-count> <bytes> <sha256>
#
# Fails loudly (non-zero) rather than silently producing a partial artifact.
conexao_zip_package() {
    local source_root="$1" slug="$2" output_zip="$3" profile="$4"

    local staging list_file epoch count
    staging="$(mktemp -d "${TMPDIR:-/tmp}/conexao-zip-XXXXXX")"
    list_file="${staging}/.file-list"

    # The staging copy is what actually gets zipped. Normalising mtimes and
    # permissions here — rather than on the working tree — is what makes the
    # archive reproducible without mutating a developer's checkout.
    conexao_zip_file_list "${source_root}" "${slug}" "${profile}" >"${list_file}"

    count="$(wc -l <"${list_file}" | tr -d ' ')"

    if [ "${count}" -eq 0 ]; then
        rm -rf "${staging}"
        echo "ERROR: packaging found no release-eligible files for '${slug}'." >&2
        echo "       Refusing to emit an empty artifact." >&2
        return 1
    fi

    epoch="$(conexao_zip_epoch "${source_root}/${slug}")"

    mkdir -p "${staging}/tree"
    while IFS= read -r rel; do
        mkdir -p "${staging}/tree/$(dirname "${rel}")"
        cp -p "${source_root}/${rel}" "${staging}/tree/${rel}"
    done <"${list_file}"

    # Preserve explicit directory entries, in sorted order, so the archive has
    # the same shape WordPress produced before (and unzip lists directories).
    # Parent directories sort before their children, so one sorted pass is
    # enough to keep the ordering valid.
    : >"${staging}/.dir-list"
    while IFS= read -r rel; do
        dirname "${rel}"
    done <"${list_file}" | LC_ALL=C sort -u | sed 's|$|/|' >>"${staging}/.dir-list"
    {
        cat "${staging}/.dir-list"
        cat "${list_file}"
    } | LC_ALL=C sort >"${staging}/.zip-list"

    # Deterministic metadata: fixed mtime, fixed permission bits, no ownership.
    find "${staging}/tree" -exec touch -h -d "@${epoch}" {} +
    find "${staging}/tree" -type d -exec chmod 755 {} +
    find "${staging}/tree" -type f -exec chmod 644 {} +

    rm -f "${output_zip}"

    # -X drops the extra fields (uid/gid/extended timestamps) that would
    # otherwise make the archive machine-dependent; -9 is the deflate level.
    # Entries are fed from the pre-sorted list, never from directory order.
    (
        cd "${staging}/tree" || exit 1
        TZ=UTC zip -X -q -9 "${output_zip}" -@ <"${staging}/.zip-list"
    )

    if [ ! -s "${output_zip}" ]; then
        rm -rf "${staging}"
        echo "ERROR: packaging produced no output for '${slug}'." >&2
        return 1
    fi

    local bytes sha
    bytes="$(wc -c <"${output_zip}" | tr -d ' ')"
    sha="$(sha256sum "${output_zip}" | cut -d' ' -f1)"

    rm -rf "${staging}"

    printf '%s %s %s %s\n' "${slug}" "${count}" "${bytes}" "${sha}"
}

