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
#
# Packaging lives in scripts/lib/zip-build.sh — the same implementation the
# plugin build uses, so the exclusion rules and the determinism guarantee are
# defined once (engineering standard §11).

# shellcheck source=scripts/lib/zip-build.sh
source "${PROJECT_ROOT}/scripts/lib/zip-build.sh"

mkdir -p "${OUTPUT_DIR}"

echo "Packaging theme: ${THEME_SLUG}"
echo "  Source:    ${THEME_DIR}"
echo "  Output:    ${OUTPUT_ZIP}"

# The packager prints "<slug> <file-count> <bytes> <sha256>" and fails loudly on
# an empty or unwritable artifact.
if ! RECORD="$(conexao_zip_package "$(dirname "${THEME_DIR}")" "${THEME_SLUG}" "${OUTPUT_ZIP}" theme)"; then
    exit 1
fi

read -r _rec_slug FILE_COUNT BYTES SHA256 <<<"${RECORD}"

echo ""
echo "✓ Theme packaged successfully:"
echo "  File:     ${OUTPUT_ZIP}"
echo "  Files:    ${FILE_COUNT}"
echo "  Bytes:    ${BYTES}"
echo "  SHA-256:  ${SHA256}"
echo ""

# --- Release manifest --------------------------------------------------------
#
# §11 MUST: every build emits dist/release.json. Re-emitting it here is safe and
# intentional: the manifest is derived from the artifacts on disk, so running the
# theme build last leaves a record that describes the COMPLETE release.
echo "Emitting release manifest:"

if ! command -v python3 &>/dev/null; then
    echo "ERROR: python3 is required to emit the release manifest." >&2
    exit 1
fi

python3 "${PROJECT_ROOT}/scripts/release-manifest.py" \
    --write --dist "${OUTPUT_DIR}" --root "${PROJECT_ROOT}" || exit 1

echo ""

echo "To import:"
echo "  1. Go to WordPress Admin → Appearance → Themes → Add New"
echo "  2. Click 'Upload Theme'"
echo "  3. Select ${OUTPUT_ZIP} and click 'Install Now'"
echo "  4. After installation, click 'Activate'"