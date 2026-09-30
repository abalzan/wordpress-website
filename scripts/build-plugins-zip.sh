#!/usr/bin/env bash
#
# build-plugins-zip.sh
#
# Packages the Conexão BR Irlanda WordPress plugins into zip files
# suitable for importing via WordPress Admin > Plugins > Add New > Upload Plugin.
#
# Each zip is structured as: <plugin-slug>/**
#

set -euo pipefail

# --- Configuration -----------------------------------------------------------

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="${PROJECT_ROOT}/wp-content/plugins"
REGISTRY="${PROJECT_ROOT}/plugins.json"
GENERATOR="${PROJECT_ROOT}/scripts/generate-registry-docs.php"

# --- Plugin registry ---------------------------------------------------------
#
# The plugin list below is GENERATED from plugins.json (Stage G, engineering
# standard 1.8). plugins.json is the single source of truth for load order,
# lifecycle, build inclusion and mounts; this script never maintains its own.
#
# The generator is run in --check mode so a malformed registry, a stale
# generated list or a missing plugin directory fails the build before anything
# is packaged. --check performs zero writes.
if ! php "${GENERATOR}" --check; then
    echo "ERROR: plugin registry check failed — refusing to build." >&2
    echo "       Fix plugins.json, then run: php scripts/generate-registry-docs.php --write" >&2
    exit 1
fi

# BEGIN GENERATED PLUGIN REGISTRY: release build list
# Source of truth: plugins.json (build: true), in load order. Do not edit by hand.
# Retired rollout plugins are intentionally absent.
# Regenerate: php scripts/generate-registry-docs.php --write
PLUGIN_SLUGS=(
    "conexao-data-model"
    "conexao-content"
    "conexao-admin-ux"
    "conexao-event-runtime"
    "conexao-event-importer"
    "conexao-leisure-migration"
    "conexao-sponsor-migration"
    "conexao-translation-rollout"
    "conexao-translation-automation"
)
# END GENERATED PLUGIN REGISTRY: release build list

# Output destination (override with BUILD_OUTPUT_DIR env var if needed)
OUTPUT_DIR="${BUILD_OUTPUT_DIR:-${PROJECT_ROOT}/dist}"

# --- Checks ------------------------------------------------------------------

if [[ ! -d "${PLUGINS_DIR}" ]]; then
    echo "ERROR: Plugins directory not found: ${PLUGINS_DIR}" >&2
    exit 1
fi

if ! command -v zip &>/dev/null; then
    echo "ERROR: 'zip' command not found. Please install it (e.g. apt install zip)." >&2
    exit 1
fi

# A build:true plugin must exist on disk. Missing it is a hard failure, never a
# silent skip: a release must not quietly lose a component.
for slug in "${PLUGIN_SLUGS[@]}"; do
    if [[ ! -d "${PLUGINS_DIR}/${slug}" ]]; then
        echo "ERROR: build:true plugin directory not found: ${PLUGINS_DIR}/${slug}" >&2
        echo "       The registry requires it; refusing to build a partial release." >&2
        exit 1
    fi
done

# Guard against a plugin directory outside the registry being packaged by
# accident: the generated build list and the registry must be the same set.
# The count comes from the registry itself, read with jq when available and
# with a small PHP fallback otherwise (PHP is already required above).
if command -v jq &>/dev/null; then
    REGISTRY_BUILD_COUNT="$(jq '[.load_order[] | select(.build == true)] | length' "${REGISTRY}")"
else
    REGISTRY_BUILD_COUNT="$(php "${GENERATOR}" --build-count)"
fi

if [[ "${REGISTRY_BUILD_COUNT}" != "${#PLUGIN_SLUGS[@]}" ]]; then
    echo "ERROR: generated build list (${#PLUGIN_SLUGS[@]}) disagrees with the registry build count (${REGISTRY_BUILD_COUNT})." >&2
    echo "       Run: php scripts/generate-registry-docs.php --write" >&2
    exit 1
fi

# --- Build -------------------------------------------------------------------
#
# Packaging itself lives in scripts/lib/zip-build.sh so the exclusion rules and
# the determinism guarantees have exactly ONE implementation shared with the
# theme build (engineering standard §11; "two places is a bug").

# shellcheck source=scripts/lib/zip-build.sh
source "${PROJECT_ROOT}/scripts/lib/zip-build.sh"

mkdir -p "${OUTPUT_DIR}"

BUILT_COUNT=0

for slug in "${PLUGIN_SLUGS[@]}"; do
    OUTPUT_ZIP="${OUTPUT_DIR}/${slug}.zip"

    printf 'Packaging plugin: %s\n' "${slug}"

    # The packager prints "<slug> <file-count> <bytes> <sha256>" and fails loudly
    # on an empty or unwritable artifact.
    RECORD="$(conexao_zip_package "${PLUGINS_DIR}" "${slug}" "${OUTPUT_ZIP}" plugin)" || exit 1

    read -r _rec_slug FILE_COUNT BYTES SHA256 <<<"${RECORD}"
    BUILT_COUNT=$((BUILT_COUNT + 1))

    printf '  Output:  %s\n' "${OUTPUT_ZIP}"
    printf '  Files:   %s\n' "${FILE_COUNT}"
    printf '  Bytes:   %s\n' "${BYTES}"
    printf '  SHA-256: %s\n' "${SHA256}"
    printf '\n'
done

printf '✓ Packaged %d plugin artifact(s):\n' "${BUILT_COUNT}"
for slug in "${PLUGIN_SLUGS[@]}"; do
    printf '  - %s\n' "${OUTPUT_DIR}/${slug}.zip"
done

# --- Release manifest --------------------------------------------------------
#
# §11 MUST: every build emits dist/release.json. It is written after the
# artifacts exist, so it records what was ACTUALLY built (sha256, size, file
# count) next to what the registry PERMITS (the allowlist). A theme that has not
# been built yet is recorded as built:false rather than being invented.
echo ""
echo "Emitting release manifest:"

MANIFEST_CMD=(python3 "${PROJECT_ROOT}/scripts/release-manifest.py" --write
              --dist "${OUTPUT_DIR}" --root "${PROJECT_ROOT}")
if ! command -v python3 &>/dev/null; then
    echo "ERROR: python3 is required to emit the release manifest." >&2
    exit 1
fi
"${MANIFEST_CMD[@]}" || exit 1

echo ""

echo "To import:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Select each .zip file and click 'Install Now'"
# BEGIN GENERATED PLUGIN REGISTRY: activation order
echo "  3. Activate plugins in this order:"
echo "     - conexao-data-model  (production platform)"
echo "     - conexao-content  (production platform)"
echo "     - conexao-admin-ux  (production platform)"
echo "     - conexao-event-runtime  (production platform)"
echo "     - conexao-event-importer  (local-only tooling)"
echo "     - conexao-leisure-migration  (local-only tooling)"
echo "     - conexao-sponsor-migration  (local-only tooling)"
echo "     - conexao-translation-rollout  (production platform)"
echo "     - conexao-en-translation  (local-only tooling (not in this release build))"
echo "     - conexao-translation-automation  (production platform)"
echo "     - conexao-page-translation  (retired rollout — activate → apply → remove (not in this release build))"
echo "     - conexao-blog-translation  (retired rollout — activate → apply → remove (not in this release build))"
echo "     - conexao-job-translation  (retired rollout — activate → apply → remove (not in this release build))"
echo "     - conexao-leisure-translation  (retired rollout — activate → apply → remove (not in this release build))"
echo "     - conexao-guide-translation  (retired rollout — activate → apply → remove (not in this release build))"
echo ""
echo "  Production steady state (plugins.json production: true, in registry order):"
echo "     - conexao-data-model"
echo "     - conexao-content"
echo "     - conexao-admin-ux"
echo "     - conexao-event-runtime"
echo "     - conexao-translation-rollout"
echo "     - conexao-translation-automation"
echo "  Local-only tooling and retired rollouts are NOT part of the production steady state."
# END GENERATED PLUGIN REGISTRY: activation order
