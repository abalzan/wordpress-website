#!/usr/bin/env bash
#
# build-theme-zip.sh
#
# Packages the "conexao-br-irlanda" WordPress theme into a zip file
# suitable for importing via WordPress Admin > Appearance > Themes > Add New > Upload Theme.
#
# The zip is structured as: conexao-br-irlanda/**
#

set -euo pipefail

# --- Configuration -----------------------------------------------------------

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME_DIR="${PROJECT_ROOT}/wp-content/themes/conexao-br-irlanda"
THEME_SLUG="conexao-br-irlanda"

# Output destination (override with BUILD_OUTPUT_DIR env var if needed)
OUTPUT_DIR="${BUILD_OUTPUT_DIR:-${PROJECT_ROOT}/dist}"
OUTPUT_ZIP="${OUTPUT_DIR}/${THEME_SLUG}.zip"

# --- Checks ------------------------------------------------------------------

if [[ ! -d "${THEME_DIR}" ]]; then
    echo "ERROR: Theme directory not found: ${THEME_DIR}" >&2
    exit 1
fi

if ! command -v zip &>/dev/null; then
    echo "ERROR: 'zip' command not found. Please install it (e.g. apt install zip)." >&2
    exit 1
fi

# --- Build -------------------------------------------------------------------

mkdir -p "${OUTPUT_DIR}"

echo "Packaging theme: ${THEME_SLUG}"
echo "  Source:    ${THEME_DIR}"
echo "  Output:    ${OUTPUT_ZIP}"

# Remove any previous build
rm -f "${OUTPUT_ZIP}"

# Create the zip from the themes directory so the theme folder appears at the
# zip root (WordPress requires this structure for theme import).
pushd "$(dirname "${THEME_DIR}")" >/dev/null

# Exclude tests, fixtures, common junk/version-control files and OS metadata.
# The theme's tests/ directory is development/test-only and must never be
# installed on production (engineering standard §8.2: tests and fixtures are
# excluded from release ZIPs). Stage E fixed this — the theme ZIP previously
# shipped all 24 theme test files.
zip -r "${OUTPUT_ZIP}" "${THEME_SLUG}" \
    -x "*/tests/*" \
    -x "*/.git/*" \
    -x "*/node_modules/*" \
    -x "*/.DS_Store" \
    -x "*/Thumbs.db" \
    -x "*.swp" \
    -x "*~" \
    >/dev/null

popd >/dev/null

# --- Verify ------------------------------------------------------------------

if [[ ! -f "${OUTPUT_ZIP}" ]]; then
    echo "ERROR: Build failed - output file not created." >&2
    exit 1
fi

SIZE="$(du -h "${OUTPUT_ZIP}" | cut -f1)"
FILE_COUNT="$(unzip -l "${OUTPUT_ZIP}" 2>/dev/null | tail -1 | awk '{print $2}')"

echo ""
echo "✓ Theme packaged successfully:"
echo "  File:    ${OUTPUT_ZIP}"
echo "  Size:    ${SIZE}"
echo "  Files:   ${FILE_COUNT}"
echo ""
echo "To import:"
echo "  1. Go to WordPress Admin → Appearance → Themes → Add New"
echo "  2. Click 'Upload Theme'"
echo "  3. Select ${OUTPUT_ZIP} and click 'Install Now'"
echo "  4. After installation, click 'Activate'"