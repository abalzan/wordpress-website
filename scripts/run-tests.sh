#!/usr/bin/env bash
#
# run-tests.sh — the canonical test entrypoint (Stage E, engineering standard §8).
#
# Runs the test layers through one command and one aggregate exit code:
#
#   1. In-process PHP   — real WordPress bootstrap, one OS process per suite.
#   2. Script contract  — static checks over scripts/ (Stage I). No WordPress,
#                         no network, no production.
#   3. HTTP acceptance  — real requests against the LOCAL WordPress.
#
# Usage:
#   ./scripts/run-tests.sh                    # all layers
#   ./scripts/run-tests.sh --scripts          # script-contract gate only
#   ./scripts/run-tests.sh --acceptance       # HTTP acceptance only
#   ./scripts/run-tests.sh --php              # in-process PHP only
#   ./scripts/run-tests.sh --only theme       # one component
#   ./scripts/run-tests.sh --only scripts     # the Stage I script gate
#   ./scripts/run-tests.sh --only conexao-event-runtime
#   ./scripts/run-tests.sh --list             # list discovered suites
#   ./scripts/run-tests.sh --help
#
# Environment:
#   CONEXAO_TEST_BASE_URL   Acceptance base URL. DEFAULT http://localhost:8080.
#                           A production host (conexaobr.ie) is REJECTED.
#   CONEXAO_TEST_WP_ROOT    Directory containing wp-load.php (in-process layer).
#   CONEXAO_REPO_ROOT       Repository root for the script-contract layer.
#
# Safety: this runner never contacts production, never uses production
# credentials and never writes to a production database.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# --- Configuration ---------------------------------------------------------

# Local-only by default. PHASE 17/43: the harness must never default to
# production, and a production host is refused outright.
BASE_URL="${CONEXAO_TEST_BASE_URL:-http://localhost:8080}"

# Production hosts that must never be used by the default harness.
PRODUCTION_HOSTS="conexaobr.ie www.conexaobr.ie"

# Per-suite timeout. Serial execution (PHASE 22) keeps failures reproducible.
SUITE_TIMEOUT="${CONEXAO_TEST_TIMEOUT:-300}"

# Worker pool size for PARALLEL-classified suites.
#   1  = fully sequential (default, preserves existing behaviour)
#   2  = initial rollout for the proven read-only group
#   4  = only after the two-worker configuration is stable
#   6  = only when resource capacity permits and timing shows benefit
CONEXAO_TEST_WORKERS="${CONEXAO_TEST_WORKERS:-1}"

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

# --- Output helpers --------------------------------------------------------

if [ -t 1 ]; then
	C_RESET=$'\033[0m'; C_PASS=$'\033[32m'; C_FAIL=$'\033[31m'
	C_BLOCK=$'\033[33m'; C_HEAD=$'\033[1m'
else
	C_RESET=''; C_PASS=''; C_FAIL=''; C_BLOCK=''; C_HEAD=''
fi

log()     { printf '%s\n' "$*"; }
head1()   { printf '\n%s%s%s\n' "$C_HEAD" "$*" "$C_RESET"; }
blocked() { printf '%sBLOCKED: %s%s\n' "$C_BLOCK" "$*" "$C_RESET"; }
fail()    { printf '%sERROR: %s%s\n' "$C_FAIL" "$*" "$C_RESET" >&2; }

usage() {
	sed -n '3,25p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,\} \{0,1\}//'
}

# --- Argument parsing ------------------------------------------------------

MODE="both"        # both | php | acceptance
ONLY=""           # component filter
DO_LIST=0

while [ $# -gt 0 ]; do
	case "$1" in
		--acceptance) MODE="acceptance" ;;
		--scripts)    MODE="scripts" ;;
		--php)        MODE="php" ;;
		--only)
			shift
			if [ $# -eq 0 ]; then fail "--only requires a component name (see --list)"; exit 2; fi
			ONLY="$1"
			;;
		--only=*)    ONLY="${1#--only=}" ;;
		--list)      DO_LIST=1 ;;
		-h|--help)   usage; exit 0 ;;
		*)           fail "unknown option: $1 (try --help)"; exit 2 ;;
	esac
	shift
done

# Fail clearly on a missing dependency (PHASE 42).
require_cmd() {
	command -v "$1" >/dev/null 2>&1 || { fail "required command not found: $1"; exit 2; }
}

# --- Discovery (PHASE 21) --------------------------------------------------
#
# Discovery is convention-based, never a hardcoded list (PHASE 21 / "no second
# source of truth"). A new standard-compliant test becomes discoverable
# automatically.
#
#   in-process: wp-content/themes/<theme>/tests/test-*.php
#               wp-content/plugins/<plugin>/tests/test-*.php
#   acceptance: tests/acceptance/verify-*-http.py
#   scripts:    tests/scripts/verify-*.py   (Stage I script-contract gate)

discover_php_suites() {
	local f
	for f in wp-content/themes/*/tests/test-*.php wp-content/plugins/*/tests/test-*.php; do
		[ -f "$f" ] || continue
		printf '%s\n' "$f"
	done | LC_ALL=C sort
}

discover_acceptance_suites() {
	local f
	for f in tests/acceptance/verify-*-http.py; do
		[ -f "$f" ] || continue
		printf '%s\n' "$f"
	done | LC_ALL=C sort
}

# Stage I script-contract suites. They are STATIC checks over the repository's
# scripts/ tree: no WordPress, no HTTP, no production. They run on the host with
# plain python3, so they work with or without the Docker stack.
discover_script_suites() {
	local f
	for f in tests/scripts/verify-*.py; do
		[ -f "$f" ] || continue
		printf '%s\n' "$f"
	done | LC_ALL=C sort
}

# Component of a suite path: the theme or plugin slug, or a layer name.
component_of() {
	printf '%s' "$1" | awk -F/ '{
		if ($1 == "wp-content") { print $3 }
		else if ($1 == "tests" && $2 == "scripts") { print "scripts" }
		else { print "acceptance" }
	}'
}

# Pretty suite name, e.g. plugin/conexao-event-runtime/test-event-query.php
suite_name() {
	printf '%s' "$1" | awk -F/ '{
		if ($1 == "wp-content") { print ( $2 == "themes" ? "theme/" : "plugin/" ) $3 "/" $5 }
		else { print }
	}'
}

# Filter by component. `--only theme` selects every theme component;
# `--only conexao-event-runtime` selects exactly that plugin.
filter_suites() {
	local p comp
	while IFS= read -r p; do
		[ -n "$p" ] || continue
		comp="$(component_of "$p")"
		if [ -z "$ONLY" ] || [ "$comp" = "$ONLY" ]; then
			printf '%s\n' "$p"
		elif [ "$ONLY" = "theme" ] && [ "${p#wp-content/themes/}" != "$p" ]; then
			printf '%s\n' "$p"
		elif [ "$ONLY" = "plugin" ] && [ "${p#wp-content/plugins/}" != "$p" ]; then
			printf '%s\n' "$p"
		fi
	done
}

# Unknown --only filters must fail loudly, never run everything (PHASE 25).
validate_filter() {
	local known
	known="$( { discover_php_suites | while IFS= read -r p; do component_of "$p"; done
		discover_script_suites | while IFS= read -r p; do component_of "$p"; done
		discover_acceptance_suites | while IFS= read -r p; do component_of "$p"; done
	} | LC_ALL=C sort -u)"
	if grep -qxF "$ONLY" <<<"$known"; then return 0; fi
	if [ "$ONLY" = "theme" ] || [ "$ONLY" = "plugin" ]; then return 0; fi
	fail "unknown test component: $ONLY"
	log "known components:"
	printf '%s\n' "$known" | sed 's/^/  - /'
	exit 2
}

# --- Environment detection (PHASE 28) --------------------------------------
#
# The repository's Compose stack (compose.yaml) runs the WordPress container
# with the repository's theme and plugin directories bind-mounted, plus the
# Stage E dev mounts for tests/ and scripts/. The in-process suites therefore
# execute INSIDE that container, where wp-load.php is at /var/www/html.
# This runner works on the host (via `docker compose exec`), inside the
# container (plain `php`) and on CI.

IN_CONTAINER=0
if [ -f /.dockerenv ] || grep -qs 'docker' /proc/1/cgroup 2>/dev/null; then
	IN_CONTAINER=1
fi

HAVE_DOCKER=0
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
	HAVE_DOCKER=1
fi

# --- Suite metadata (PHASE 4 classification) -------------------------------
#
# A maintained suite may need a CLI argument, or may require an unusual
# prerequisite (e.g. a different plugin-activation state) that makes it a
# MANUAL test rather than a default one. That classification lives HERE, in the
# runner, as an explicit table — it is never "silently excluded": every entry is
# printed by --list and the counts are reported in the summary.
#
#   "<suite path>|<arg1,arg2,...>"  run once per argument
#   "<suite path>|MANUAL:<reason>"  listed, documented, never run by default
suite_variants() {
	case "$1" in
		wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php)
			# Read-only per-language menu-selection check; both variants are
			# ordinary regression coverage and both run in the default suite.
			printf 'pt\nen\n'
			;;
		wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php)
			# MANUAL: asserts plugin separation under a DIFFERENT plugin
			# activation state (importer deactivated / activated), which
			# changes the environment rather than testing it. It cannot run
			# as an ordinary default suite. See docs/testing.md.
			printf 'MANUAL:requires a deliberate plugin-activation change '
			printf '(docker compose exec wordpress wp --allow-root plugin deactivate conexao-event-importer)\n'
			;;
		*)
			printf '\n'
			;;
	esac
}

# Suite concurrency classification (PHASE 2 / PHASE 4 classification).
#
# Every suite starts as SERIAL. A suite is PARALLEL only after its source,
# dependencies, setup and teardown have been reviewed and proven safe for
# concurrent execution against the shared WordPress database.
#
#   "<suite path>|PARALLEL"   — explicitly approved for concurrent execution
#   "<suite path>|SERIAL"     — shared-state writes or not yet reviewed (default)
#   "<suite path>|MANUAL:<reason>"  — documented special handling, never run by default
#
# Suites listed as PARALLEL have been verified as read-only: no wp_insert_post, no update_option, no taxonomy changes, no shared cache writes.
# Default: SERIAL. Every discovered suite that is not listed here is SERIAL.
suite_classification() {
	case "$1" in
		# PARALLEL: proven read-only, no shared-state mutation
		wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-cache-language-scoping.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-commemorative/tests/test-commemorative-schedule.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-packages.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-event-date-badge.php)
			printf 'PARALLEL\n'
			;;
		# Additional PARALLEL: proven read-only, no shared-state mutation
		wp-content/themes/conexao-br-irlanda/tests/test-blog-en-translation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-ambient.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-journey.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-ambient-decoration.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-assets.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-cpt-privacy.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-placement-assets.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-public-activation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-single-package.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-gate-translation-ownership.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-landing-page.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-commemorative-presentation-dispatch.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-cursos-infinite-scroll.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-empregos-landing-cta.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-en-archive-rewrite-guard.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-eventos-infinite-scroll.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-header-cta-dark-mode.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-hero-preload-fallback.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-homepage-content-type-label.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-homepage-shortcut-consolidation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-job-en-translation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-jobs-en-language.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-language-switcher-flag.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context-logic.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-recurrence-i18n.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-presentation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-seasonal-occasion.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-stage32-bilingual.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-stage33-bilingual.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-ui-convergence-t2.php)
			printf 'PARALLEL\n'
			;;
		wp-content/themes/conexao-br-irlanda/tests/test-ui-polish-p3.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-en-translation/tests/test-shared-slug-newsletter.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-county-registry.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-event-address.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-eventbrite-api-contract.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-eventbrite-importer.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-eventbrite-rate-limit.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-eventbrite-source-expansion.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-eventbrite-volatility.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-event-image-sync.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-motorsport-ireland-importer.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-multi-file-import-ui.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-past-event-filter.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-request-budget.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-request-retry-policy.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-request-reuse.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-event-importer/tests/test-town-sanitization.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-admin-trigger.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-apply-safety.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-audit.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-boundary.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-change-detection.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-promotion.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-provider-implementation.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-provider.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-provider-secrets.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-secret-scan.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-translation-plan.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-transport-shape.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-automation-trigger.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-automation/tests/test-manual-translation-workflow.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-rollout/tests/test-future-stage-smoke.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-rollout/tests/test-legacy-apply-endpoint-closure.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-rollout/tests/test-rollout-failure-proofs.php)
			printf 'PARALLEL\n'
			;;
		wp-content/plugins/conexao-translation-rollout/tests/test-translation-rollout-engine.php)
			printf 'PARALLEL\n'
			;;
		# MANUAL: existing documented special handling
		wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php)
			printf 'MANUAL:requires a deliberate plugin-activation change '
			printf '(docker compose exec wordpress wp --allow-root plugin deactivate conexao-event-importer)\n'
			;;
		# SERIAL: everything else (default)
		*)
			printf 'SERIAL\n'
			;;
	esac
}

# Emit "<suite>\t<arg>" lines, one per variant. Manual suites emit nothing.
suite_instances() {
	local suite="$1" arg first=1
	while IFS= read -r arg; do
		[ -n "$arg" ] || continue
		case "$arg" in
			MANUAL:*) return 0 ;;
		esac
		if [ "$first" = "1" ]; then printf '%s\t%s\n' "$suite" "$arg"; first=0
		else printf '%s\t%s\n' "$suite" "$arg"; fi
	done < <(suite_variants "$suite")
	[ "$first" = "1" ] && printf '%s\t\n' "$suite"
	return 0
}

# Report manual suites so they are never silently dropped.
report_manual_suites() {
	local suite v found=0
	while IFS= read -r suite; do
		while IFS= read -r v; do
			case "$v" in
				MANUAL:*)
					found=1
					printf '  MANUAL  %s\n' "$suite"
					printf '          %s\n' "${v#MANUAL:}"
					;;
			esac
		done < <(suite_variants "$suite")
	done < <(discover_php_suites)
	[ "$found" = "1" ] || log "  (none)"
}

# Run a PHP file with WordPress available, wherever we are.
run_php() {
	local file="$1" arg="${2:-}"
	if [ "$IN_CONTAINER" = "1" ]; then
		if [ -n "$arg" ]; then
			timeout "$SUITE_TIMEOUT" php "$file" "$arg"
		else
			timeout "$SUITE_TIMEOUT" php "$file"
		fi
	elif [ "$HAVE_DOCKER" = "1" ]; then
		if [ -n "$arg" ]; then
			timeout "$SUITE_TIMEOUT" docker compose exec -T wordpress php "/var/www/html/$file" "$arg"
		else
			timeout "$SUITE_TIMEOUT" docker compose exec -T wordpress php "/var/www/html/$file"
		fi
	else
		# Host PHP with WordPress at the repository root.
		CONEXAO_TEST_WP_ROOT="${CONEXAO_TEST_WP_ROOT:-$REPO_ROOT}" \
			timeout "$SUITE_TIMEOUT" php "$file" ${arg:+"$arg"}
	fi
}

# --- Production URL safety (PHASE 17 / PHASE 43 / PHASE 61) ----------------
#
# The acceptance layer must never run against production. This is checked in
# the runner AND again in the Python acceptance library; the runner check makes
# the refusal visible before any request library is even loaded.

reject_production_base_url() {
	local host
	host="$(printf '%s' "$BASE_URL" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##; s#:[0-9]+$##')"
	local p
	for p in $PRODUCTION_HOSTS; do
		if [ "$host" = "$p" ]; then
			fail "refusing to run acceptance tests against the production host: $p"
			log "The Stage E harness is local-only by design. A production verifier"
			log "(scripts/c3-production-*.py) is a separate, manual tool and is never"
			log "part of this test runner or of CI."
			exit 2
		fi
	done
}

# --- Listing ---------------------------------------------------------------

if [ "$DO_LIST" = "1" ]; then
	head1 "In-process PHP suites"
	log "  PARALLEL = approved for concurrent execution  SERIAL = serial only  MANUAL = documented special handling"
	while IFS= read -r p; do
		[ -n "$p" ] || continue
		cls="$(suite_classification "$p")"
		case "$cls" in
			PARALLEL)  cls_label="PARALLEL" ;;
			MANUAL:*)  cls_label="MANUAL" ;;
			*)         cls_label="SERIAL" ;;
		esac
		printf '  %-72s [%s] %s\n' "$(suite_name "$p")" "$(component_of "$p")" "$cls_label"
	done < <(discover_php_suites | filter_suites)
	head1 "Manual suites (documented, NOT run by default)"
	report_manual_suites
	head1 "Script-contract suites"
	while IFS= read -r p; do
		[ -n "$p" ] || continue
		printf '  %s\n' "$p"
	done < <(discover_script_suites)
	head1 "HTTP acceptance suites"
	while IFS= read -r p; do
		printf '  %s\n' "$p"
	done < <(discover_acceptance_suites)
	log ""
	log "components: $( { discover_php_suites; discover_script_suites; discover_acceptance_suites; } | while IFS= read -r p; do component_of "$p"; done | LC_ALL=C sort -u | tr '\n' ' ')"
	exit 0
fi

# --- In-process PHP layer (PHASE 22 / PHASE 23) ---------------------------
#
# One OS process per suite, serial, deterministic order. Process isolation
# prevents global-variable contamination, function redeclaration and static
# state leaking between suites. The database stays shared, which is why suites
# that write must clean up after themselves.

PHP_TOTAL=0; PHP_PASSED=0; PHP_FAILED=0; PHP_BLOCKED=0; PHP_PREREQ=0
PHP_ASSERT_PASS=0; PHP_ASSERT_FAIL=0
failed_names=""

# Extract "N passed, M failed" from a suite's output. Suites that have not yet
# been migrated to test_finish() may print a different shape; those are
# reported honestly rather than silently counted as a pass.
extract_counts() {
	local out="$1"
	local n m
	n="$(printf '%s' "$out" | grep -aoE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+' || true)"
	m="$(printf '%s' "$out" | grep -aoE '[0-9]+ failed' | tail -1 | grep -oE '[0-9]+' || true)"
	printf '%s %s' "${n:-}" "${m:-}"
}

# Run one PHP suite, capture exit code to a result file.
# Used by the parallel worker pool (PHASE 3).
run_php_captured() {
	local suite="$1" arg="${2:-}" out_file="$3" result_file="$4"
	set +e
	run_php "$suite" "$arg" >"$out_file" 2>&1
	local code=$?
	set -e
	printf '%s' "$code" > "$result_file"
}

run_php_layer() {
	local suites total=0 passed=0 failed=0 blocked=0 prereq=0 ap=0 af=0
	local instances="" s
	while IFS= read -r s; do
		[ -n "$s" ] || continue
		instances="${instances}$(suite_instances "$s")"$'\n'
	done < <(discover_php_suites | filter_suites)
	suites="$instances"

	if [ -z "$suites" ]; then
		if [ -n "$ONLY" ]; then
			fail "no maintained suites matched --only $ONLY"
			return 1
		fi
		log "no in-process suites discovered"
		return 0
	fi

	# Classify every suite into PARALLEL or SERIAL (default).
	# MANUAL suites are not run by default. A missing/malformed/ambiguous
	# classification degrades to SERIAL (fail-safe).
	local -a par_suite=() par_arg=() par_name=() par_out=()
	local -a ser_suite=() ser_arg=() ser_name=() ser_out=()
	local manual_count=0
	local suite arg cls
	while IFS=$'\t' read -r suite arg <&3; do
		[ -n "$suite" ] || continue
		cls="$(suite_classification "$suite")"
		case "$cls" in
			PARALLEL)
				par_suite+=("$suite")
				par_arg+=("${arg:-}")
				local pname; pname="$(suite_name "$suite")"
				[ -n "$arg" ] && pname="$pname ($arg)"
				par_name+=("$pname")
				par_out+=("$WORK_DIR/$(printf '%s' "$pname" | tr '/ ()' '____').out")
				;;
			MANUAL:*)
				manual_count=$((manual_count + 1))
				;;
			*)
				# SERIAL: shared-state writes or not yet reviewed (default).
				ser_suite+=("$suite")
				ser_arg+=("${arg:-}")
				local sname; sname="$(suite_name "$suite")"
				[ -n "$arg" ] && sname="$sname ($arg)"
				ser_name+=("$sname")
				ser_out+=("$WORK_DIR/$(printf '%s' "$sname" | tr '/ ()' '____').out")
				;;
		esac
	done 3<<<"$suites"

	if [ "$manual_count" -gt 0 ]; then
		log "  $manual_count manual suite(s) skipped (not run by default)"
	fi

	# --- Serial group: run one at a time (deterministic) ---------------------

	local serial_total=0 serial_passed=0 serial_failed=0
	local serial_blocked=0 serial_prereq=0 serial_ap=0 serial_af=0
	local serial_failed_names=""

	if [ "${#ser_suite[@]}" -gt 0 ]; then
		head1 "In-process PHP suites (serial, one process per suite)"
		local i
		for ((i=0; i<${#ser_suite[@]}; i++)); do
			serial_total=$((serial_total + 1))
			local name="${ser_name[$i]}"
			local out_file="${ser_out[$i]}"
			local out code n m

			set +e
			run_php "${ser_suite[$i]}" "${ser_arg[$i]}" >"$out_file" 2>&1
			code=$?
			set -e

			out="$(cat "$out_file")"

			# A WordPress bootstrap failure is a real failure, never a skip.
			if grep -q 'conexao-test-bootstrap:' <<<"$out"; then
				serial_blocked=$((serial_blocked + 1))
				printf '%s[BLOCKED]%s %s — WordPress bootstrap failed\n' "$C_BLOCK" "$C_RESET" "$name"
				printf '%s\n' "$out" | sed 's/^/         /'
				continue
			fi

			read -r n m <<<"$(extract_counts "$out")"
			[ -n "${n:-}" ] && serial_ap=$((serial_ap + n))
			[ -n "${m:-}" ] && serial_af=$((serial_af + m))

			if grep -qE '^[[:space:]]*insufficient data: ' <<<"$out"; then
				serial_prereq=$((serial_prereq + 1))
			fi

			if [ "$code" -eq 0 ]; then
				serial_passed=$((serial_passed + 1))
				if [ -n "${n:-}" ] && [ -n "${m:-}" ]; then
					printf '%s[PASS]%s %s — %s passed, %s failed\n' "$C_PASS" "$C_RESET" "$name" "$n" "$m"
				else
					printf '%s[PASS]%s %s — no assertion summary emitted\n' "$C_PASS" "$C_RESET" "$name"
				fi
			elif [ "$code" -eq 124 ]; then
				serial_failed=$((serial_failed + 1))
				printf '%s[TIMEOUT]%s %s — exceeded %ss\n' "$C_FAIL" "$C_RESET" "$name" "$SUITE_TIMEOUT"
			else
				serial_failed=$((serial_failed + 1))
				serial_failed_names="${serial_failed_names}${name}"$'\n'
				printf '%s[FAIL]%s %s' "$C_FAIL" "$C_RESET" "$name"
				if [ -n "${n:-}" ] && [ -n "${m:-}" ]; then
					printf ' — %s passed, %s failed' "$n" "$m"
				fi
				printf ' (exit %s)\n' "$code"
				printf '%s' "$out" | grep -aE 'FAIL:|insufficient data:|Fatal error|Warning:' | head -10 | sed 's/^/         /'
				cp "$out_file" "$WORK_DIR/FAILED-$(printf '%s' "$name" | tr '/' '_').log" 2>/dev/null || true
			fi
		done
	fi

	# --- Parallel group: bounded worker pool ---------------------------------

	local parallel_total=0 parallel_passed=0 parallel_failed=0
	local parallel_blocked=0 parallel_prereq=0 parallel_ap=0 parallel_af=0
	local parallel_failed_names=""

	if [ "${#par_suite[@]}" -gt 0 ]; then
		head1 "In-process PHP suites (parallel, workers=$CONEXAO_TEST_WORKERS)"

		local count="${#par_suite[@]}"
		local idx=0 active=0
		local -a pids=()   # PIDs of running workers
		local -a pidx=()   # Index into par_* arrays for each PID

		while [ "$idx" -lt "$count" ] || [ "$active" -gt 0 ]; do
			# Launch new workers while under capacity and work remains.
			while [ "$active" -lt "$CONEXAO_TEST_WORKERS" ] && [ "$idx" -lt "$count" ]; do
				local sout="${par_out[$idx]}"
				local resfile="$WORK_DIR/par-result-$idx"
				set +e
				run_php_captured "${par_suite[$idx]}" "${par_arg[$idx]}" "$sout" "$resfile" &
				local pid=$!
				pids+=("$pid")
				pidx+=("$idx")
				active=$((active + 1))
				idx=$((idx + 1))
				set -e
			done

			if [ "$active" -gt 0 ]; then
				wait -n   # blocks until any child exits; reaps it
				active=$((active - 1))
			fi
		done

		# All workers are done. Collect results from result files.
		local i
		for ((i=0; i<count; i++)); do
			parallel_total=$((parallel_total + 1))
			local name="${par_name[$i]}"
			local out_file="${par_out[$i]}"
			local resfile="$WORK_DIR/par-result-$i"
			local code out n m

			code="$(cat "$resfile" 2>/dev/null || printf '1')"
			out="$(cat "$out_file")"

			# A WordPress bootstrap failure is a real failure, never a skip.
			if grep -q 'conexao-test-bootstrap:' <<<"$out"; then
				parallel_blocked=$((parallel_blocked + 1))
				printf '%s[BLOCKED]%s %s — WordPress bootstrap failed\n' "$C_BLOCK" "$C_RESET" "$name"
				printf '%s\n' "$out" | sed 's/^/         /'
				continue
			fi

			read -r n m <<<"$(extract_counts "$out")"
			[ -n "${n:-}" ] && parallel_ap=$((parallel_ap + n))
			[ -n "${m:-}" ] && parallel_af=$((parallel_af + m))

			if grep -qE '^[[:space:]]*insufficient data: ' <<<"$out"; then
				parallel_prereq=$((parallel_prereq + 1))
			fi

			if [ "$code" -eq 0 ]; then
				parallel_passed=$((parallel_passed + 1))
				if [ -n "${n:-}" ] && [ -n "${m:-}" ]; then
					printf '%s[PASS]%s %s — %s passed, %s failed\n' "$C_PASS" "$C_RESET" "$name" "$n" "$m"
				else
					printf '%s[PASS]%s %s — no assertion summary emitted\n' "$C_PASS" "$C_RESET" "$name"
				fi
			elif [ "$code" -eq 124 ]; then
				parallel_failed=$((parallel_failed + 1))
				printf '%s[TIMEOUT]%s %s — exceeded %ss\n' "$C_FAIL" "$C_RESET" "$name" "$SUITE_TIMEOUT"
			else
				parallel_failed=$((parallel_failed + 1))
				parallel_failed_names="${parallel_failed_names}${name}"$'\n'
				printf '%s[FAIL]%s %s' "$C_FAIL" "$C_RESET" "$name"
				if [ -n "${n:-}" ] && [ -n "${m:-}" ]; then
					printf ' — %s passed, %s failed' "$n" "$m"
				fi
				printf ' (exit %s)\n' "$code"
				printf '%s' "$out" | grep -aE 'FAIL:|insufficient data:|Fatal error|Warning:' | head -10 | sed 's/^/         /'
				cp "$out_file" "$WORK_DIR/FAILED-$(printf '%s' "$name" | tr '/' '_').log" 2>/dev/null || true
			fi
		done
	fi

	# --- Aggregate ------------------------------------------------------------

	total=$((serial_total + parallel_total))
	passed=$((serial_passed + parallel_passed))
	failed=$((serial_failed + parallel_failed))
	blocked=$((serial_blocked + parallel_blocked))
	prereq=$((serial_prereq + parallel_prereq))
	ap=$((serial_ap + parallel_ap))
	af=$((serial_af + parallel_af))

	PHP_TOTAL=$total; PHP_PASSED=$passed; PHP_FAILED=$failed
	PHP_BLOCKED=$blocked; PHP_PREREQ=$prereq
	PHP_ASSERT_PASS=$ap; PHP_ASSERT_FAIL=$af
	PHP_FAILED_NAMES="${serial_failed_names}${parallel_failed_names}"

	if [ "$failed" -gt 0 ] || [ "$blocked" -gt 0 ]; then
		return 1
	fi
	return 0
}

# --- HTTP acceptance layer (PHASE 26 / PHASE 27) ---------------------------

ACCEPT_TOTAL=0; ACCEPT_PASSED=0; ACCEPT_FAILED=0; ACCEPT_BLOCKED=0

# The acceptance base URL must be reachable AND local. An unavailable
# environment is a BLOCKED result with a non-zero runner exit, never a silent
# downgrade to PHP-only execution (PHASE 27).
acceptance_available() {
	local code
	code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_URL/" 2>/dev/null || printf '000')"
	case "$code" in
		200|301|302) return 0 ;;
		*) return 1 ;;
	esac
}

# --- Script-contract layer (Stage I) ---------------------------------------
#
# Static, repository-local checks over the scripts/ estate. They need neither
# WordPress nor a running site, so they run on the host with plain python3 and
# can never contact production. They are part of THIS harness (one runner, one
# aggregate exit code) — there is no second test entrypoint.
#
# The suites are discovered by convention: tests/scripts/verify-*.py.

run_script_layer() {
	local suites total=0 passed=0 failed=0
	suites="$(discover_script_suites | filter_suites)"

	if [ -z "$suites" ]; then
		log "no script-contract suites discovered under tests/scripts/"
		return 0
	fi

	head1 "Script-contract suites (static, no WordPress, no network)"

	require_cmd python3

	local suite
	while IFS= read -r suite <&3; do
		[ -n "$suite" ] || continue
		total=$((total + 1))
		local out_file out code
		out_file="$WORK_DIR/$(printf '%s' "$suite" | tr '/' '_').out"

		set +e
		# The suites are repository-local and must work from any cwd; they
		# resolve their own repository root.
		CONEXAO_REPO_ROOT="$REPO_ROOT" \
			timeout "$SUITE_TIMEOUT" python3 "$suite" >"$out_file" 2>&1
		code=$?
		set -e

		out="$(cat "$out_file")"
		if [ "$code" -eq 0 ]; then
			passed=$((passed + 1))
			printf '%s[PASS]%s %s\n' "$C_PASS" "$C_RESET" "$suite"
			printf '%s' "$out" | grep -aE '^[0-9]+ passed' | sed 's/^/         /'
		elif [ "$code" -eq 124 ]; then
			failed=$((failed + 1))
			printf '%s[TIMEOUT]%s %s (exceeded %ss)\n' "$C_FAIL" "$C_RESET" "$suite" "$SUITE_TIMEOUT"
		else
			failed=$((failed + 1))
			printf '%s[FAIL]%s %s (exit %s)\n' "$C_FAIL" "$C_RESET" "$suite" "$code"
			printf '%s' "$out" | grep -aE 'FAIL' | head -25 | sed 's/^/         /'
			cp "$out_file" "$WORK_DIR/FAILED-$(printf '%s' "$suite" | tr '/' '_').log" 2>/dev/null || true
		fi
	done 3<<<"$suites"

	SCRIPT_TOTAL=$total; SCRIPT_PASSED=$passed; SCRIPT_FAILED=$failed

	if [ "$failed" -gt 0 ]; then
		return 1
	fi
	return 0
}

run_acceptance_layer() {
	local suites total=0 passed=0 failed=0 blocked=0
	suites="$(discover_acceptance_suites)"
	reject_production_base_url

	if [ -z "$suites" ]; then
		blocked=1
		blocked "HTTP acceptance environment unavailable"
		log "  no acceptance suites discovered under tests/acceptance/"
		ACCEPT_BLOCKED=1
		return 1
	fi

	head1 "HTTP acceptance suites (base: $BASE_URL)"

	if ! acceptance_available; then
		blocked=1
		blocked "HTTP acceptance environment unavailable"
		log "  could not reach $BASE_URL/. Start the local stack: docker compose up -d"
		ACCEPT_BLOCKED=1
		return 1
	fi

	require_cmd python3

	local suite
	while IFS= read -r suite <&3; do
		[ -n "$suite" ] || continue
		total=$((total + 1))
		local out_file out code
		out_file="$WORK_DIR/$(printf '%s' "$suite" | tr '/' '_').out"

		set +e
		CONEXAO_TEST_BASE_URL="$BASE_URL" \
			timeout "$SUITE_TIMEOUT" python3 "$suite" >"$out_file" 2>&1
		code=$?
		set -e

		out="$(cat "$out_file")"
		local n m
		n="$(printf '%s' "$out" | grep -aoE '[0-9]+ passed' | tail -1 | grep -oE '[0-9]+' || true)"
		m="$(printf '%s' "$out" | grep -aoE '[0-9]+ failed' | tail -1 | grep -oE '[0-9]+' || true)"

		if [ "$code" -eq 0 ]; then
			passed=$((passed + 1))
			printf '%s[PASS]%s %s' "$C_PASS" "$C_RESET" "$suite"
			[ -n "${n:-}" ] && printf ' — %s passed, %s failed' "$n" "${m:-0}"
			printf '\n'
		elif [ "$code" -eq 124 ]; then
			failed=$((failed + 1))
			printf '%s[TIMEOUT]%s %s\n' "$C_FAIL" "$C_RESET" "$suite"
		else
			failed=$((failed + 1))
			printf '%s[FAIL]%s %s (exit %s)\n' "$C_FAIL" "$C_RESET" "$suite" "$code"
			printf '%s' "$out" | grep -aE 'FAIL|ERROR|error|invalid|matrix' | head -10 | sed 's/^/         /'
			cp "$out_file" "$WORK_DIR/FAILED-$(printf '%s' "$suite" | tr '/' '_').log" 2>/dev/null || true
		fi
	done 3<<<"$suites"

	ACCEPT_TOTAL=$total; ACCEPT_PASSED=$passed; ACCEPT_FAILED=$failed; ACCEPT_BLOCKED=$blocked

	if [ "$failed" -gt 0 ] || [ "$blocked" -gt 0 ]; then
		return 1
	fi
	return 0
}

# --- Main -----------------------------------------------------------------

[ -n "$ONLY" ] && validate_filter

log "conexao test harness"
log "  repository:    $REPO_ROOT"
log "  php:           $(command -v php >/dev/null 2>&1 && php -r 'echo PHP_VERSION;' || echo 'n/a (container)')"
if [ "$IN_CONTAINER" = "1" ]; then
	ENV_LABEL='inside container'
elif [ "$HAVE_DOCKER" = "1" ]; then
	ENV_LABEL='host (docker compose exec)'
else
	ENV_LABEL='host (local php)'
fi
log "  environment:   $ENV_LABEL"
log "  base url:      $BASE_URL"

PHP_RC=0
ACCEPT_RC=0
SCRIPT_RC=0
SCRIPT_TOTAL=0; SCRIPT_PASSED=0; SCRIPT_FAILED=0

case "$MODE" in
	php)
		run_php_layer || PHP_RC=1
		;;
	acceptance)
		run_acceptance_layer || ACCEPT_RC=1
		;;
	scripts)
		run_script_layer || SCRIPT_RC=1
		;;
	both)
		run_php_layer || PHP_RC=1
		run_script_layer || SCRIPT_RC=1
		run_acceptance_layer || ACCEPT_RC=1
		;;
esac

# --- Aggregate summary (PHASE 24) ------------------------------------------

head1 "----------------------------------------"
log "In-process PHP suites: $PHP_TOTAL total, $PHP_PASSED passed, $PHP_FAILED failed"
if [ "$PHP_BLOCKED" -gt 0 ]; then
	log "  WordPress bootstrap failures: $PHP_BLOCKED"
fi
if [ "$PHP_PREREQ" -gt 0 ]; then
	log "  prerequisite failures:         $PHP_PREREQ"
fi
if [ "$PHP_ASSERT_PASS" -gt 0 ] || [ "$PHP_ASSERT_FAIL" -gt 0 ]; then
	log "Assertions: $PHP_ASSERT_PASS passed, $PHP_ASSERT_FAIL failed"
fi
if [ "$SCRIPT_TOTAL" -gt 0 ] || [ "$MODE" = "scripts" ]; then
	log "Script-contract suites: $SCRIPT_TOTAL total, $SCRIPT_PASSED passed, $SCRIPT_FAILED failed"
fi
if [ "$MODE" != "php" ] && [ "$MODE" != "scripts" ]; then
	log "HTTP acceptance suites: $ACCEPT_TOTAL total, $ACCEPT_PASSED passed, $ACCEPT_FAILED failed"
	if [ "$ACCEPT_BLOCKED" -gt 0 ]; then
		log "  acceptance environment: BLOCKED"
	fi
fi
log "----------------------------------------"

if [ "$PHP_RC" -ne 0 ] || [ "$ACCEPT_RC" -ne 0 ] || [ "$SCRIPT_RC" -ne 0 ]; then
	printf '%sTESTS FAILED%s\n' "$C_FAIL" "$C_RESET"
	if [ -n "$PHP_FAILED_NAMES" ]; then
		log "failing suites:"
		printf '%s' "$PHP_FAILED_NAMES" | sed '/^$/d' | sed 's/^/  - /'
	fi
	if [ -d "$WORK_DIR" ] && ls "$WORK_DIR"/FAILED-*.log >/dev/null 2>&1; then
		log "full suite output kept in $WORK_DIR"
	fi
	exit 1
fi

printf '%sALL TESTS PASSED%s\n' "$C_PASS" "$C_RESET"
exit 0
