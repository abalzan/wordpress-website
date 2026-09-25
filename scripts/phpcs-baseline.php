<?php
/**
 * PHPCS legacy-baseline helper (Stage C of the engineering-standard roadmap).
 *
 * Companion to `phpcs-baseline.json` (the per-sniff legacy violation counts)
 * and `scripts/lint.sh` (the gate that uses it).
 *
 * Usage:
 *
 *   # Compare a fresh PHPCS JSON report against the baseline (the gate):
 *   php scripts/phpcs-baseline.php check <phpcs-report.json> <phpcs-baseline.json>
 *   # Exit 0 = no new violations; exit 1 = new violations (or invalid input).
 *
 *   # Regenerate the baseline after deliberately paying down debt:
 *   php scripts/phpcs-baseline.php update <phpcs-report.json> <phpcs-baseline.json>
 *
 * Policy (docs/development.md, "Quality tooling"):
 *   - The baseline stores per-sniff legacy ERROR/WARNING counts. It MUST NOT
 *     grow: any sniff whose count increases, and any sniff that is not in the
 *     baseline yet, fails the check.
 *   - Shrinking counts are debt paid down; re-run `update` after a deliberate
 *     reduction so the baseline tracks the new floor.
 *   - Moving a violation between files without changing counts is net-zero
 *     debt and passes (the aggregate is per sniff, not per file).
 *
 * Development tooling only — this script is never loaded by WordPress and is
 * never deployed to production.
 *
 * @package Conexao_BR_Irlanda
 * @subpackage Dev_Tooling
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}

/**
 * Print usage and exit with a failure code.
 *
 * @return void
 */
function phpcs_baseline_usage() {
	fwrite( STDERR, "Usage:\n" );
	fwrite( STDERR, "  php scripts/phpcs-baseline.php check <phpcs-report.json> <phpcs-baseline.json>\n" );
	fwrite( STDERR, "  php scripts/phpcs-baseline.php update <phpcs-report.json> <phpcs-baseline.json>\n" );
	exit( 2 );
}

/**
 * Aggregate a PHPCS JSON report into per-sniff error/warning counts.
 *
 * @param string $report_path Path to the PHPCS --report=json output.
 * @return array{totals: array{errors: int, warnings: int, fixable: int, files: int}, sniffs: array<string, array{errors: int, warnings: int}>}
 */
function phpcs_baseline_aggregate( $report_path ) {
	$raw = file_get_contents( $report_path );
	if ( false === $raw || '' === trim( $raw ) ) {
		fwrite( STDERR, "ERROR: PHPCS report is missing or empty: {$report_path}\n" );
		exit( 1 );
	}
	$report = json_decode( $raw, true );
	if ( ! is_array( $report ) || ! isset( $report['files'] ) || ! is_array( $report['files'] ) ) {
		fwrite( STDERR, "ERROR: PHPCS report is not valid PHPCS JSON: {$report_path}\n" );
		exit( 1 );
	}

	$totals = array(
		'errors'   => 0,
		'warnings' => 0,
		'fixable'  => 0,
		'files'    => 0,
	);
	$sniffs  = array();

	foreach ( $report['files'] as $file => $data ) {
		++$totals['files'];
		if ( isset( $data['messages'] ) && is_array( $data['messages'] ) ) {
			foreach ( $data['messages'] as $message ) {
				if ( ! isset( $message['source'], $message['type'] ) ) {
					continue;
				}
				$sniff = (string) $message['source'];
				if ( ! isset( $sniffs[ $sniff ] ) ) {
					$sniffs[ $sniff ] = array(
						'errors'   => 0,
						'warnings' => 0,
					);
				}
				if ( 'ERROR' === $message['type'] ) {
					++$sniffs[ $sniff ]['errors'];
					++$totals['errors'];
				} else {
					++$sniffs[ $sniff ]['warnings'];
					++$totals['warnings'];
				}
				if ( ! empty( $message['fixable'] ) ) {
					++$totals['fixable'];
				}
			}
		}
	}

	ksort( $sniffs );

	return array(
		'totals' => $totals,
		'sniffs' => $sniffs,
	);
}

/**
 * Run the baseline check: fail on any per-sniff growth or new sniff.
 *
 * @param string $report_path   Path to the fresh PHPCS JSON report.
 * @param string $baseline_path Path to phpcs-baseline.json.
 * @return void
 */
function phpcs_baseline_check( $report_path, $baseline_path ) {
	$current = phpcs_baseline_aggregate( $report_path );

	$raw = file_get_contents( $baseline_path );
	if ( false === $raw ) {
		fwrite( STDERR, "ERROR: baseline file is missing: {$baseline_path}\n" );
		exit( 1 );
	}
	$baseline = json_decode( $raw, true );
	if ( ! is_array( $baseline ) || ! isset( $baseline['sniffs'] ) || ! is_array( $baseline['sniffs'] ) ) {
		fwrite( STDERR, "ERROR: baseline file is not valid baseline JSON: {$baseline_path}\n" );
		exit( 1 );
	}

	$grew   = array();
	$new    = array();
	$shrank = array();

	foreach ( $current['sniffs'] as $sniff => $counts ) {
		if ( ! isset( $baseline['sniffs'][ $sniff ] ) ) {
			$new[ $sniff ] = $counts;
			continue;
		}
		$base = $baseline['sniffs'][ $sniff ];
		if ( $counts['errors'] > $base['errors'] || $counts['warnings'] > $base['warnings'] ) {
			$grew[ $sniff ] = array( 'base' => $base, 'current' => $counts );
		} elseif ( $counts['errors'] < $base['errors'] || $counts['warnings'] < $base['warnings'] ) {
			$shrank[ $sniff ] = array( 'base' => $base, 'current' => $counts );
		}
	}

	$base_totals = isset( $baseline['totals'] ) ? $baseline['totals'] : array();

	printf(
		"PHPCS legacy baseline: %d errors / %d warnings in %d files (baseline: %s / %s).\n",
		$current['totals']['errors'],
		$current['totals']['warnings'],
		$current['totals']['files'],
		isset( $base_totals['errors'] ) ? (string) $base_totals['errors'] : '?',
		isset( $base_totals['warnings'] ) ? (string) $base_totals['warnings'] : '?'
	);

	if ( ! empty( $new ) ) {
		fwrite( STDERR, "NEW VIOLATIONS - sniffs not in the baseline:\n" );
		foreach ( $new as $sniff => $counts ) {
			fwrite( STDERR, sprintf( "  + %s (%d errors, %d warnings)\n", $sniff, $counts['errors'], $counts['warnings'] ) );
		}
	}

	if ( ! empty( $grew ) ) {
		fwrite( STDERR, "NEW VIOLATIONS - per-sniff counts grew:\n" );
		foreach ( $grew as $sniff => $pair ) {
			fwrite( STDERR, sprintf( "  + %s %d/%d -> %d/%d (errors/warnings)\n", $sniff, $pair['base']['errors'], $pair['base']['warnings'], $pair['current']['errors'], $pair['current']['warnings'] ) );
		}
	}

	if ( ! empty( $new ) || ! empty( $grew ) ) {
		fwrite( STDERR, "FAILED: the PHPCS baseline grew. Fix the new violations, or pay down debt and regenerate the baseline deliberately.\n" );
		exit( 1 );
	}

	if ( ! empty( $shrank ) ) {
		printf( "Debt paid down in %d sniff(s) - regenerate the baseline deliberately when convenient (scripts/phpcs-baseline.php update).\n", count( $shrank ) );
	}

	echo "OK: no new PHPCS violations against the baseline.\n";
}

/**
 * Regenerate the baseline file from a fresh PHPCS JSON report.
 *
 * @param string $report_path   Path to the fresh PHPCS JSON report.
 * @param string $baseline_path Path to phpcs-baseline.json (rewritten).
 * @return void
 */
function phpcs_baseline_update( $report_path, $baseline_path ) {
	$aggregate = phpcs_baseline_aggregate( $report_path );

	$baseline = array(
		'_meta'  => array(
			'generated' => date( 'Y-m-d' ),
			'policy'    => 'Per-sniff legacy ERROR/WARNING counts for phpcs.xml.dist. MUST NOT grow: any increased or new sniff count fails scripts/lint.sh. Regenerate via "php scripts/phpcs-baseline.php update <report>" only after deliberately paying down debt (see docs/reports/site/).',
		),
		'totals' => $aggregate['totals'],
		'sniffs' => $aggregate['sniffs'],
	);

	$json = json_encode( $baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$json = str_replace( '    ', '  ', $json ); // 2-space indent, like composer.json.

	if ( false === file_put_contents( $baseline_path, $json ) ) {
		fwrite( STDERR, "ERROR: could not write baseline: {$baseline_path}\n" );
		exit( 1 );
	}

	printf(
		"Baseline written: %d errors / %d warnings in %d files, %d sniffs.\n",
		$aggregate['totals']['errors'],
		$aggregate['totals']['warnings'],
		$aggregate['totals']['files'],
		count( $aggregate['sniffs'] )
	);
}

if ( $argc < 4 ) {
	phpcs_baseline_usage();
}

$mode          = (string) $argv[1];
$report_path   = (string) $argv[2];
$baseline_path = (string) $argv[3];

if ( 'check' === $mode ) {
	phpcs_baseline_check( $report_path, $baseline_path );
} elseif ( 'update' === $mode ) {
	phpcs_baseline_update( $report_path, $baseline_path );
} else {
	phpcs_baseline_usage();
}

