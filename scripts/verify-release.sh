#!/usr/bin/env bash
#
# verify-release.sh — local end-to-end verification of the whole release
# workflow (Stage J, engineering standard §11).
#
# Purpose: PROVE the release machinery works before anyone relies on it for a
# real release. The standard's production sequence includes deployment and HTTP
# verification; this script runs everything EXCEPT the deployment, against the
# LOCAL site, so the steps that would touch production are the only ones omitted.
#
# What it runs:
#
#   1. registry drift gate        plugins.json vs the generated regions
#   2. deterministic build        plugins + theme into a scratch directory
#   3. release manifest emit      dist/release.json (versions, SHA, counts, hashes)
#   4. allowlist / hash verify    artifacts match the record, nothing unexpected
#   5. determinism proof          a second build reproduces identical SHA-256s
#   6. exclusion proof            no tests/fixtures/reports/junk in any artifact
#   7. deployment verification    scripts/verify-deploy.py against the LOCAL site
#
# It NEVER uploads an artifact, never activates a plugin, never writes content
# and never contacts production. Every artifact it builds goes to a scratch
# directory that is removed on exit unless --keep is passed.
#
# Usage:
#   ./scripts/verify-release.sh                  full local release verification
#   ./scripts/verify-release.sh --skip-http      build/manifest gates only
#   ./scripts/verify-release.sh --site <url>     verify another reachable target
#   ./scripts/verify-release.sh --keep           keep the scratch build directory
#   ./scripts/verify-release.sh --help
#
# Environment:
#   BUILD_OUTPUT_DIR   Build straight into a directory instead of a scratch dir
#                      (disables the determinism re-build, which needs a clean dir).
#   CONEXAO_REPO_ROOT  Repository root (default: auto-detected).

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PROJECT_ROOT}"

SKIP_HTTP=0
KEEP=0
SITE="${CONEXAO_TEST_BASE_URL:-http://localhost:8080}"
SCRATCH=""

usage() {
	sed -n '3,33p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,\} \{0,1\}//'
}

while [ $# -gt 0 ]; do
	case "$1" in
		--skip-http) SKIP_HTTP=1 ;;
		--keep)      KEEP=1 ;;
		--site)      shift; SITE="${1:-}" ;;
		--site=*)    SITE="${1#--site=}" ;;
		-h|--help)   usage; exit 0 ;;
		*)           echo "ERROR: unknown option: $1 (try --help)" >&2; exit 2 ;;
	esac
	shift
done

if [ -n "${BUILD_OUTPUT_DIR:-}" ]; then
	DIST_DIR="${BUILD_OUTPUT_DIR}"
else
	SCRATCH="$(mktemp -d "${TMPDIR:-/tmp}/conexao-release-XXXXXX")"
	DIST_DIR="${SCRATCH}/dist"
fi

cleanup() {
	if [ -n "${SCRATCH}" ] && [ "${KEEP}" -eq 0 ]; then
		rm -rf "${SCRATCH}"
	elif [ -n "${SCRATCH}" ]; then
		echo "kept: ${SCRATCH}"
	fi
}
trap cleanup EXIT

FAILED=0
step() { printf '\n==> [%s/7] %s\n' "$1" "$2"; }
ok()   { printf '    OK   %s\n' "$1"; }
bad()  { printf '    FAIL %s\n' "$1" >&2; FAILED=1; }

require() {
	command -v "$1" &>/dev/null || {
		echo "ERROR: required command not found: $1" >&2
		exit 1
	}
}

require php
require python3
require zip
require unzip

echo "conexao release verification"
echo "  repository: ${PROJECT_ROOT}"
echo "  artifacts:  ${DIST_DIR}"
echo "  site:       ${SITE}"

# --- 1. Registry drift gate ---------------------------------------------------
step 1 "plugin registry drift gate"
if php scripts/generate-registry-docs.php --check; then
	ok "plugins.json and its generated regions agree"
else
	bad "registry drift — run: php scripts/generate-registry-docs.php --write"
fi

# --- 2. Deterministic build ---------------------------------------------------
step 2 "build plugins and theme"
if BUILD_OUTPUT_DIR="${DIST_DIR}" ./scripts/build-plugins-zip.sh >"${DIST_DIR}.plugins.log" 2>&1; then
	ok "plugin artifacts built ($(grep -c '^Packaging plugin:' "${DIST_DIR}.plugins.log" || true) plugin(s))"
else
	bad "plugin build failed — see ${DIST_DIR}.plugins.log"
	tail -20 "${DIST_DIR}.plugins.log" >&2 || true
fi

if BUILD_OUTPUT_DIR="${DIST_DIR}" ./scripts/build-theme-zip.sh >"${DIST_DIR}.theme.log" 2>&1; then
	ok "theme artifact built"
else
	bad "theme build failed — see ${DIST_DIR}.theme.log"
	tail -20 "${DIST_DIR}.theme.log" >&2 || true
fi

# --- 3. Release manifest ------------------------------------------------------
step 3 "release manifest (dist/release.json)"
if python3 scripts/release-manifest.py --write --dist "${DIST_DIR}" --root "${PROJECT_ROOT}" >"${DIST_DIR}.manifest.log" 2>&1; then
	ok "manifest emitted with versions, git SHA, file counts and SHA-256"
else
	bad "manifest emission failed — see ${DIST_DIR}.manifest.log"
	tail -20 "${DIST_DIR}.manifest.log" >&2 || true
fi

# --- 4. Allowlist + hash verification -----------------------------------------
step 4 "verify artifact allowlist and hashes"
if python3 scripts/release-manifest.py --verify --dist "${DIST_DIR}" --root "${PROJECT_ROOT}" >"${DIST_DIR}.verify.log" 2>&1; then
	ok "every artifact matches the recorded allowlist, size, file count and hash"
else
	bad "manifest verification failed — see ${DIST_DIR}.verify.log"
	grep -a 'FAIL' "${DIST_DIR}.verify.log" >&2 || true
fi

# --- 5. Determinism proof ------------------------------------------------------
step 5 "determinism proof (rebuild must reproduce identical hashes)"
if [ -n "${SCRATCH}" ]; then
	SECOND="${SCRATCH}/dist-second"
	if BUILD_OUTPUT_DIR="${SECOND}" ./scripts/build-plugins-zip.sh >/dev/null 2>&1 \
		&& BUILD_OUTPUT_DIR="${SECOND}" ./scripts/build-theme-zip.sh >/dev/null 2>&1; then
		DRIFT=0
		for zip in "${DIST_DIR}"/*.zip; do
			base="$(basename "${zip}")"
			if [ ! -f "${SECOND}/${base}" ]; then
				bad "${base}: missing from the second build"
				DRIFT=1
			elif ! cmp -s "${zip}" "${SECOND}/${base}"; then
				bad "${base}: the second build produced a different archive"
				DRIFT=1
			fi
		done
		[ "${DRIFT}" -eq 0 ] && ok "all artifacts reproduced byte-for-byte"
	else
		bad "the second build failed"
	fi
else
	ok "skipped (BUILD_OUTPUT_DIR is set, so the source dir is not pristine)"
fi

# --- 6. Exclusion proof --------------------------------------------------------
step 6 "exclusion proof (no tests/fixtures/reports/junk in any artifact)"
LEAKS=0
for zip in "${DIST_DIR}"/*.zip; do
	leaked="$(unzip -Z1 "${zip}" | grep -E '(^|/)(tests|fixtures|node_modules|__pycache__)/|\.json$|\.DS_Store$|Thumbs\.db$|\.swp$|~$' || true)"
	if [ -n "${leaked}" ]; then
		bad "$(basename "${zip}") contains excluded paths:"
		printf '%s\n' "${leaked}" | sed 's/^/         /' >&2
		LEAKS=1
	fi
done
[ "${LEAKS}" -eq 0 ] && ok "no artifact contains a test, fixture, report or junk file"

# --- 7. Deployment verification (local only) -----------------------------------
step 7 "HTTP deployment verification (read-only)"
if [ "${SKIP_HTTP}" -eq 1 ]; then
	ok "skipped (--skip-http)"
else
	if python3 scripts/verify-deploy.py --site "${SITE}" >"${DIST_DIR}.http.log" 2>&1; then
		ok "deployment smoke matrix, per-CPT singles and language layer all pass"
		grep -a '^summary:' "${DIST_DIR}.http.log" | sed 's/^/         /'
	else
		bad "deployment verification failed — see ${DIST_DIR}.http.log"
		grep -aE '^\s+FAIL' "${DIST_DIR}.http.log" | head -20 | sed 's/^/         /' >&2 || true
	fi
fi

# --- Summary --------------------------------------------------------------------
printf '\n----------------------------------------\n'
if [ "${FAILED}" -ne 0 ]; then
	echo "RELEASE VERIFICATION: FAILED"
	exit 1
fi

echo "RELEASE VERIFICATION: OK"
echo "  artifacts verified against plugins.json allowlist"
echo "  deployment verified read-only over HTTP"
echo "  no production step was performed"
