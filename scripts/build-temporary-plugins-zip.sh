#!/usr/bin/env bash
#
# build-temporary-plugins-zip.sh — package the TEMPORARY, local-only
# operational plugins into dist/temporary/ for manual wp-admin installation.
#
# Purpose: make the EN translation rollout pair installable in a disposable
# WordPress through wp-admin → Plugins → Add New → Upload Plugin, WITHOUT ever
# changing their permanent registry classification.
#
# Why a separate script exists at all: `scripts/build-plugins-zip.sh` builds
# exactly the `build: true` set derived from plugins.json and always emits
# dist/release.json. Both plugins packaged here are `build: false` on purpose —
# they are temporary rollout tooling, not production platform plugins — so
# making them appear in the normal release would be registry pollution, not
# packaging. This script therefore never touches plugins.json, never emits
# dist/release.json, and REFUSES any slug whose registry entry is not
# production:false + build:false.
#
# Scope: reads plugins.json and wp-content/plugins/, and writes only into the
# temporary build output directory (dist/temporary/ by default, git-ignored).
# It loads no WordPress, opens no socket, uses no credentials and never
# contacts production.
#
# Safety: local-write to dist/temporary/ only.
#
# Packaging rules, the exclusion list and the determinism guarantee are NOT
# reimplemented here: they come from scripts/lib/zip-build.sh, the single
# shared implementation already used by the plugin and theme release builds
# (engineering standard §11 — "two places is a bug").
#
# Usage:
#   ./scripts/build-temporary-plugins-zip.sh            # build into dist/temporary/
#   ./scripts/build-temporary-plugins-zip.sh --verify   # rebuild and re-hash
#
# Environment:
#   TEMPORARY_BUILD_OUTPUT_DIR   Output directory (default <repo>/dist/temporary).
#   SOURCE_DATE_EPOCH            Reproducible-builds epoch override.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="${PROJECT_ROOT}/wp-content/plugins"
REGISTRY="${PROJECT_ROOT}/plugins.json"
GENERATOR="${PROJECT_ROOT}/scripts/generate-registry-docs.php"
OUTPUT_DIR="${TEMPORARY_BUILD_OUTPUT_DIR:-${PROJECT_ROOT}/dist/temporary}"

# --- The temporary artifact list -------------------------------------------
#
# This list is NOT derived from plugins.json `build: true`: that derivation is
# the production release build, and these two plugins are deliberately excluded
# from it. Keeping the list here is the whole point — it is the explicit
# temporary set, and the registry guard below proves each entry really is
# production:false + build:false, so this list can never drift into the
# production release.
TEMPORARY_PLUGIN_SLUGS=(
    "conexao-translation-rollout"
    "conexao-en-translation"
)

MODE="build"
if [ "${1:-}" = "--verify" ]; then
    MODE="verify"
fi

# --- Registry guards --------------------------------------------------------
#
# The generator runs in --check mode: zero writes, and a malformed registry or
# a stale generated list fails before anything is packaged.
if ! php "${GENERATOR}" --check >/dev/null; then
    echo "ERROR: plugin registry check failed — refusing to build." >&2
    exit 1
fi

registry_fact() {
    # registry_fact <slug> <key> -> "true" / "false" / "absent" from plugins.json
    #
    # shellcheck disable=SC2016  # $argv is PHP's argv, not a shell variable: the
    #                              single quotes are deliberate, no expansion is wanted.
    php -r '
        $slug = $argv[1];
        $key  = $argv[2];
        $reg  = json_decode( file_get_contents( $argv[3] ), true );
        foreach ( (array) ( $reg["load_order"] ?? array() ) as $entry ) {
            if ( ( $entry["slug"] ?? "" ) === $slug ) {
                echo ( ! empty( $entry[ $key ] ) ) ? "true" : "false";
                exit( 0 );
            }
        }
        echo "absent";
    ' "$1" "$2" "${REGISTRY}"
}

for slug in "${TEMPORARY_PLUGIN_SLUGS[@]}"; do
    if [ ! -d "${PLUGINS_DIR}/${slug}" ]; then
        echo "ERROR: temporary plugin directory not found: ${PLUGINS_DIR}/${slug}" >&2
        exit 1
    fi

    # A temporary artifact may only ever be a local-only, non-released plugin.
    # If someone ever flips production or build to true in plugins.json, this
    # script refuses rather than quietly producing a second release path.
    for key in production build; do
        value="$(registry_fact "${slug}" "${key}")"
        if [ "${value}" != "false" ]; then
            echo "ERROR: ${slug} has ${key}=${value} in plugins.json." >&2
            echo "       Only production:false + build:false plugins may be packaged here." >&2
            echo "       The production release build is scripts/build-plugins-zip.sh." >&2
            exit 1
        fi
    done
done

# --- The one packaging implementation ---------------------------------------
#
# shellcheck source=scripts/lib/zip-build.sh
source "${PROJECT_ROOT}/scripts/lib/zip-build.sh"

mkdir -p "${OUTPUT_DIR}"

echo "Temporary plugin artifacts"
echo "  output:    ${OUTPUT_DIR}"
echo "  registry:  production:false + build:false (verified for every slug)"
echo "  manifest:  dist/release.json is NOT touched by this script"
echo ""

BUILT_COUNT=0

for slug in "${TEMPORARY_PLUGIN_SLUGS[@]}"; do
    OUTPUT_ZIP="${OUTPUT_DIR}/${slug}.zip"

    if [ ! -f "${OUTPUT_ZIP}" ] && [ "${MODE}" = "verify" ]; then
        echo "ERROR: ${OUTPUT_ZIP} does not exist; build it first." >&2
        exit 1
    fi

    # The packager prints "<slug> <file-count> <bytes> <sha256>" and fails
    # loudly on an empty or unwritable artifact. It re-derives the epoch from
    # the source tree, so two builds of the same source state are
    # byte-identical.
    RECORD="$(conexao_zip_package "${PLUGINS_DIR}" "${slug}" "${OUTPUT_ZIP}" plugin)" || exit 1

    read -r _rec_slug FILE_COUNT BYTES SHA256 <<<"${RECORD}"
    BUILT_COUNT=$((BUILT_COUNT + 1))

    printf '%s: %s\n' "${MODE}" "${slug}"
    printf '  Output:  %s\n' "${OUTPUT_ZIP}"
    printf '  Files:   %s\n' "${FILE_COUNT}"
    printf '  Bytes:   %s\n' "${BYTES}"
    printf '  SHA-256: %s\n' "${SHA256}"
    printf '\n'
done

printf '✓ %d temporary plugin artifact(s) in %s\n' "${BUILT_COUNT}" "${OUTPUT_DIR}"
echo ""
echo "These are NOT part of the production release: dist/release.json and the"
echo "production activation order are unchanged. Install them by hand through"
echo "wp-admin → Plugins → Add New → Upload Plugin, and remove them when the"
echo "rollout is finished."
