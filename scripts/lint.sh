#!/usr/bin/env bash
#
# lint.sh
#
# The single local quality gate for Stage C of the engineering-standard
# roadmap (docs/engineering-standard.md §1.4–§1.6; see
# docs/reports/site/2026-09-25-stage-c-static-quality-tooling.md).
#
# Runs, in order:
#
#   1. PHP syntax check (`php -l`) over every PHP file known to Git
#      (tracked + untracked, not ignored — vendor/ is never scanned).
#   2. PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP per
#      phpcs.xml.dist) with the legacy-baseline gate (phpcs-baseline.json):
#      legacy debt is recorded, NEW violations fail the run.
#   3. PHPStan (level 5, phpstan-wordpress, phpstan.neon.dist) via
#      `composer analyse`: legacy errors are isolated in
#      phpstan-baseline.neon; NEW errors fail the run.
#
# Exit status: 0 only when every check passes; 1 otherwise.
#
# Prerequisites: `php` and `composer` on PATH, and `composer install` run at
# least once (creates vendor/). Override the PHP binary with PHP_BIN.
#
# This script does NOT run the behavioural test suites — the unified test
# harness is Stage E. Development tooling only; never deployed.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

PHP_BIN="${PHP_BIN:-php}"

if [ ! -f vendor/bin/phpcs ] || [ ! -f vendor/bin/phpstan ]; then
    echo "ERROR: vendor/ tooling is missing — run: composer install" >&2
    exit 1
fi

FAILED=0
SYNTAX_OUTPUT="$(mktemp)"
PHPCS_REPORT="$(mktemp)"
trap 'rm -f "$SYNTAX_OUTPUT" "$PHPCS_REPORT"' EXIT

# --- 1. PHP syntax check ------------------------------------------------------

SYNTAX_COUNT="$(git ls-files --cached --others --exclude-standard -- '*.php' | wc -l)"

echo "==> [1/3] PHP syntax check (php -l, ${SYNTAX_COUNT} files)"

SYNTAX_STATUS=0
git ls-files -z --cached --others --exclude-standard -- '*.php' \
    | xargs -0 -r -n1 -P8 "$PHP_BIN" -l >"$SYNTAX_OUTPUT" 2>&1 || SYNTAX_STATUS=1

if [ "$SYNTAX_STATUS" -ne 0 ]; then
    FAILED=1
    echo "FAILED: PHP parse errors:" >&2
    grep -E 'PHP Parse error|PHP Fatal error|Errors parsing' "$SYNTAX_OUTPUT" >&2 || cat "$SYNTAX_OUTPUT" >&2
else
    echo "OK: no PHP parse errors."
fi

# --- 2. PHPCS + legacy baseline gate ------------------------------------------

echo "==> [2/3] PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP) + legacy baseline gate"

# PHPCS exits non-zero whenever violations exist (legacy debt included);
# the baseline gate below decides pass/fail, so exit codes are collected
# per check instead of aborting the run here.
PHPCS_STATUS=0
vendor/bin/phpcs -q --report=json --no-colors >"$PHPCS_REPORT" 2>/dev/null || true
"$PHP_BIN" scripts/phpcs-baseline.php check "$PHPCS_REPORT" phpcs-baseline.json || PHPCS_STATUS=1

if [ "$PHPCS_STATUS" -ne 0 ]; then
    FAILED=1
fi

# --- 3. PHPStan (level 5) via composer analyse ---------------------------------

echo "==> [3/3] PHPStan level 5 via composer analyse (phpstan.neon.dist + baseline)"

PHPSTAN_STATUS=0
composer analyse --no-interaction --no-ansi 2>&1 || PHPSTAN_STATUS=1

if [ "$PHPSTAN_STATUS" -ne 0 ]; then
    FAILED=1
fi

# --- Summary --------------------------------------------------------------------

if [ "$FAILED" -ne 0 ]; then
    echo "lint: FAILED (see the check output above)" >&2
    exit 1
fi

echo "lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)"
