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

# Plugins to package, in load order (dependencies first).
PLUGIN_SLUGS=(
    "conexao-data-model"
    "conexao-content"
    "conexao-admin-ux"
    "conexao-event-runtime"
    "conexao-event-importer"
    "conexao-leisure-migration"
    "conexao-sponsor-migration"
    "conexao-page-translation"
    "conexao-blog-translation"
    "conexao-job-translation"
    "conexao-leisure-translation"
)

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

# --- Build -------------------------------------------------------------------

mkdir -p "${OUTPUT_DIR}"

for slug in "${PLUGIN_SLUGS[@]}"; do
    PLUGIN_DIR="${PLUGINS_DIR}/${slug}"
    OUTPUT_ZIP="${OUTPUT_DIR}/${slug}.zip"

    if [[ ! -d "${PLUGIN_DIR}" ]]; then
        echo "WARNING: Plugin directory not found, skipping: ${PLUGIN_DIR}"
        continue
    fi

    echo "Packaging plugin: ${slug}"
    echo "  Source:    ${PLUGIN_DIR}"
    echo "  Output:    ${OUTPUT_ZIP}"

    # Remove any previous build
    rm -f "${OUTPUT_ZIP}"

    # Create the zip from the plugins directory so the plugin folder appears at
    # the zip root (WordPress requires this structure for plugin import).
    pushd "${PLUGINS_DIR}" >/dev/null

    # Exclude tests, fixtures, common junk/version-control files and OS metadata.
    zip -r "${OUTPUT_ZIP}" "${slug}" \
        -x "*/tests/*" \
        -x "*/.git/*" \
        -x "*/node_modules/*" \
        -x "*/.DS_Store" \
        -x "*/Thumbs.db" \
        -x "*.swp" \
        -x "*~" \
        >/dev/null

    popd >/dev/null

    if [[ ! -f "${OUTPUT_ZIP}" ]]; then
        echo "ERROR: Build failed for ${slug} - output file not created." >&2
        exit 1
    fi

    SIZE="$(du -h "${OUTPUT_ZIP}" | cut -f1)"
    FILE_COUNT="$(unzip -l "${OUTPUT_ZIP}" 2>/dev/null | tail -1 | awk '{print $2}')"

    echo "  Size:    ${SIZE}"
    echo "  Files:   ${FILE_COUNT}"
    echo ""
done

echo "✓ All plugins packaged successfully:"
for slug in "${PLUGIN_SLUGS[@]}"; do
    if [[ -f "${OUTPUT_DIR}/${slug}.zip" ]]; then
        echo "  - ${OUTPUT_DIR}/${slug}.zip"
    fi
done
echo ""
echo "To import:"
echo "  1. Go to WordPress Admin → Plugins → Add New → Upload Plugin"
echo "  2. Select each .zip file and click 'Install Now'"
echo "  3. Activate plugins in this order:"
echo "     - conexao-data-model"
echo "     - conexao-content"
echo "     - conexao-admin-ux"
echo "     - conexao-event-runtime   (production dependency)"
echo "     - conexao-event-importer  (local-only tooling)"
echo "     - conexao-leisure-migration"
echo "     - conexao-sponsor-migration"
echo "     - conexao-page-translation (Stage 4.5 rollout tooling)"
echo "     - conexao-blog-translation (Stage 5 rollout tooling)"
echo "     - conexao-job-translation  (Stage 6 rollout tooling)"
echo "     - conexao-leisure-translation (Stage 7 rollout tooling)"
