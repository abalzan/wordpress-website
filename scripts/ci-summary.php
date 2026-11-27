<?php
/**
 * GitHub Actions job-summary generator for the CI test and analysis reports.
 *
 * Purpose: turn the machine output this repository already produces — the
 * `scripts/run-tests.sh` harness log, a PHPStan JSON report, the Stage L
 * `gate.json` and the release-integrity log — into one GitHub-Flavoured
 * Markdown block suitable for appending to `$GITHUB_STEP_SUMMARY`.
 *
 * Scope: read-only reporting. It never runs a test, never writes a repository
 * file, never mutates source or data, and never contacts a network host or
 * production. Its only output is Markdown on STDOUT.
 *
 * Safety: it reads exactly the file named by `--log`/`--json` and prints
 * aggregates plus the failure lines those files already contain. It never
 * reads `.env`, a credential file, a container environment or a database, so it
 * cannot publish a secret by construction. Long lines are truncated so a single
 * pathological message cannot flood a summary.
 *
 * Bootstrap exception: this is a static reporting tool. It does NOT require
 * `scripts/lib/bootstrap.php` and never loads WordPress — there is nothing to
 * bootstrap for parsing a text log, and loading WordPress would make a
 * reporting step depend on the database being healthy.
 *
 * Exit status: always 0. This is a REPORTING step attached to a job whose
 * verdict was already decided by the step that produced the input file, so a
 * summary helper must never be able to change that verdict. When an input is
 * missing or unparseable it prints a visible warning section instead of failing
 * silently, and `tests/scripts/verify-ci-summary-output.py` proves the parser
 * still understands every shape it claims to.
 *
 * Usage:
 *   php scripts/ci-summary.php --kind=harness --log=/tmp/run-tests-php.log
 *   php scripts/ci-summary.php --kind=phpstan --json=/tmp/phpstan.json
 *   php scripts/ci-summary.php --kind=gates   --json=docs/evidence/x/gate.json
 *   php scripts/ci-summary.php --kind=release --log=/tmp/release.log
 *
 * Options: `--title=<heading>` overrides the section heading, and
 * `--max-items=<n>` caps the number of detail rows (default 20).
 *
 * @package Conexao_BR_Irlanda
 * @subpackage Dev_Tooling
 */

/**
 * Parse `--name=value` style arguments.
 *
 * @param array<int, string> $argv Raw argv.
 * @return array<string, string>
 */
function conexao_ci_summary_args( array $argv ) {
	$out = array();
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 0 === strpos( $arg, '--' ) && false !== strpos( $arg, '=' ) ) {
			list( $key, $value ) = explode( '=', substr( $arg, 2 ), 2 );
			$out[ trim( $key ) ] = $value;
		}
	}
	return $out;
}

/**
 * Read a file, or return null when it is absent/unreadable.
 *
 * @param string $path File path.
 * @return string|null
 */
function conexao_ci_summary_read( $path ) {
	if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return null;
	}
	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI tool reading a local log.
	return is_string( $contents ) ? $contents : null;
}

/**
 * Strip ANSI colour sequences so a TTY-coloured log parses identically.
 *
 * @param string $text Input.
 * @return string
 */
function conexao_ci_summary_strip_ansi( $text ) {
	return (string) preg_replace( '/\x1b\[[0-9;]*[A-Za-z]/', '', $text );
}

/**
 * Escape a value for a Markdown table cell.
 *
 * @param string $text Input.
 * @param int    $max  Maximum length before truncation.
 * @return string
 */
function conexao_ci_summary_cell( $text, $max = 120 ) {
	$text = trim( (string) $text );
	if ( strlen( $text ) > $max ) {
		$text = substr( $text, 0, $max - 1 ) . '…';
	}
	return str_replace( '|', '\\|', $text );
}

/**
 * Emit the "input unavailable" notice used by every kind.
 *
 * @param string $detail What was missing.
 * @return void
 */
function conexao_ci_summary_unavailable( $detail ) {
	echo '> :warning: **Summary unavailable** — ' . conexao_ci_summary_cell( $detail, 300 ) . "\n\n";
}


/**
 * Summarise a `scripts/run-tests.sh` harness log.
 *
 * Recognised shapes (exactly what run-tests.sh prints):
 *   In-process PHP suites: N total, N passed, N failed
 *   Assertions: N passed, N failed
 *   Script-contract suites: N total, N passed, N failed
 *   HTTP acceptance suites: N total, N passed, N failed
 *   WordPress bootstrap failures: N / prerequisite failures: N
 *   acceptance environment: BLOCKED
 *   per-suite `[PASS] …` / `[FAIL] …` / `[TIMEOUT] …` / `[BLOCKED] …`
 *   `failing suites:` followed by `  - <name>` lines
 *   the final verdict `ALL TESTS PASSED` or `TESTS FAILED`
 *
 * @param string $log Harness log.
 * @param int    $max Detail-row cap.
 * @return void
 */
function conexao_ci_summary_harness( $log, $max ) {
	$lines = explode( "\n", conexao_ci_summary_strip_ansi( $log ) );

	$totals = array(
		'php'            => null,
		'assert'         => null,
		'scripts'        => null,
		'accept'         => null,
		'blocked'        => 0,
		'prereq'         => 0,
		'accept_blocked' => false,
	);

	$suites  = array();
	$failing = array();
	$verdict = '';
	$in_fail = false;

	foreach ( $lines as $line ) {
		$line = rtrim( $line, "\r" );

		if ( preg_match( '/^In-process PHP suites: (\d+) total, (\d+) passed, (\d+) failed/', $line, $m ) ) {
			$totals['php'] = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			continue;
		}
		if ( preg_match( '/^Assertions: (\d+) passed, (\d+) failed/', $line, $m ) ) {
			$totals['assert'] = array( (int) $m[1], (int) $m[2] );
			continue;
		}
		if ( preg_match( '/^Script-contract suites: (\d+) total, (\d+) passed, (\d+) failed/', $line, $m ) ) {
			$totals['scripts'] = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			continue;
		}
		if ( preg_match( '/^HTTP acceptance suites: (\d+) total, (\d+) passed, (\d+) failed/', $line, $m ) ) {
			$totals['accept'] = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			continue;
		}
		if ( preg_match( '/WordPress bootstrap failures: (\d+)/', $line, $m ) ) {
			$totals['blocked'] = (int) $m[1];
			continue;
		}
		if ( preg_match( '/prerequisite failures:\s+(\d+)/', $line, $m ) ) {
			$totals['prereq'] = (int) $m[1];
			continue;
		}
		if ( false !== strpos( $line, 'acceptance environment: BLOCKED' ) ) {
			$totals['accept_blocked'] = true;
			continue;
		}
		if ( preg_match( '/^\[(PASS|FAIL|TIMEOUT|BLOCKED)\]\s+(\S+)/', $line, $m ) ) {
			$suites[] = array( $m[1], $m[2] );
			continue;
		}
		if ( preg_match( '/^(ALL TESTS PASSED|TESTS FAILED)$/', $line, $m ) ) {
			$verdict = $m[1];
			continue;
		}
		if ( 'failing suites:' === $line ) {
			$in_fail = true;
			continue;
		}
		if ( $in_fail ) {
			if ( preg_match( '/^\s+- (.+)$/', $line, $m ) ) {
				$failing[] = trim( $m[1] );
				continue;
			}
			$in_fail = false;
		}
	}

	if ( null === $totals['php'] && '' === $verdict && ! $suites ) {
		conexao_ci_summary_unavailable( 'the harness log contained no recognisable run-tests.sh summary block' );
		return;
	}

	if ( 'ALL TESTS PASSED' === $verdict ) {
		echo ':white_check_mark: **ALL TESTS PASSED**' . "\n\n";
	} elseif ( 'TESTS FAILED' === $verdict ) {
		echo ':x: **TESTS FAILED**' . "\n\n";
	} else {
		echo ':grey_question: **verdict not found in the log**' . "\n\n";
	}

	echo "| Layer | Total | Passed | Failed |\n|---|---|---|---|\n";
	$labels = array(
		'php'     => 'In-process PHP suites',
		'scripts' => 'Script-contract suites',
		'accept'  => 'HTTP acceptance suites',
	);
	foreach ( $labels as $key => $label ) {
		$row = $totals[ $key ];
		if ( null === $row ) {
			echo '| ' . $label . " | — | — | — |\n";
		} else {
			echo '| ' . $label . ' | ' . $row[0] . ' | ' . $row[1] . ' | ' . $row[2] . " |\n";
		}
	}
	if ( null !== $totals['assert'] ) {
		echo '| Assertions (in-process PHP) | ' . ( $totals['assert'][0] + $totals['assert'][1] )
			. ' | ' . $totals['assert'][0] . ' | ' . $totals['assert'][1] . " |\n";
	}
	echo "\n";

	if ( $totals['blocked'] > 0 ) {
		echo '- :warning: WordPress bootstrap failures: **' . (int) $totals['blocked'] . "**\n";
	}
	if ( $totals['prereq'] > 0 ) {
		echo '- :warning: prerequisite failures: **' . (int) $totals['prereq'] . "**\n";
	}
	if ( $totals['accept_blocked'] ) {
		echo "- :warning: the HTTP acceptance environment reported **BLOCKED** — that is not a pass.\n";
	}

	$non_pass = array();
	foreach ( $suites as $row ) {
		if ( 'PASS' !== $row[0] ) {
			$non_pass[] = $row;
		}
	}

	if ( $non_pass ) {
		echo "\n#### Suites that did not pass\n\n| Status | Suite |\n|---|---|\n";
		foreach ( array_slice( $non_pass, 0, $max ) as $row ) {
			echo '| `' . $row[0] . '` | `' . conexao_ci_summary_cell( $row[1], 140 ) . "` |\n";
		}
		if ( count( $non_pass ) > $max ) {
			echo "\n_… and " . ( count( $non_pass ) - $max ) . " more (see the artifact log)._\n";
		}
	}

	if ( $failing ) {
		echo "\n<details><summary>Failing suite names reported by the harness (" . count( $failing ) . ")</summary>\n\n";
		foreach ( $failing as $name ) {
			echo '- `' . conexao_ci_summary_cell( $name, 160 ) . "`\n";
		}
		echo "\n</details>\n";
	}
}

/**
 * Summarise a PHPStan JSON report (`--error-format=json`).
 *
 * @param string $json Raw JSON document.
 * @param int    $max  Detail-row cap.
 * @return void
 */
function conexao_ci_summary_phpstan( $json, $max ) {
	$data = json_decode( $json, true );

	if ( ! is_array( $data ) || ! isset( $data['totals'] ) || ! is_array( $data['totals'] ) ) {
		conexao_ci_summary_unavailable( 'the PHPStan report was not the expected JSON document' );
		return;
	}

	$errors      = isset( $data['totals']['errors'] ) ? (int) $data['totals']['errors'] : 0;
	$file_errors = isset( $data['totals']['file_errors'] ) ? (int) $data['totals']['file_errors'] : 0;
	$files       = isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array();
	$affected    = count( $files );
	$generic     = isset( $data['errors'] ) && is_array( $data['errors'] ) ? count( $data['errors'] ) : 0;

	if ( 0 === $errors && 0 === $file_errors ) {
		echo ':white_check_mark: **PHPStan: no errors** (level 5, baseline honoured)' . "\n\n";
		return;
	}

	echo ':x: **PHPStan reported ' . $file_errors . ' file error(s) in ' . $affected
		. ' file(s)**, plus ' . $generic . " non-file error(s).\n\n";

	$rows = array();
	foreach ( $files as $path => $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['messages'] ) || ! is_array( $entry['messages'] ) ) {
			continue;
		}
		$rows[] = array( 'path' => (string) $path, 'count' => count( $entry['messages'] ) );
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		}
	);

	if ( $rows ) {
		echo "| File | Errors |\n|---|---|\n";
		foreach ( array_slice( $rows, 0, $max ) as $row ) {
			$short = preg_replace( '#^.*/wp-content/#', 'wp-content/', $row['path'] );
			echo '| `' . conexao_ci_summary_cell( (string) $short, 140 ) . '` | ' . $row['count'] . " |\n";
		}
		echo "\n";
	}

	$flat = array();
	foreach ( $files as $path => $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['messages'] ) || ! is_array( $entry['messages'] ) ) {
			continue;
		}
		$short = preg_replace( '#^.*/wp-content/#', 'wp-content/', (string) $path );
		foreach ( $entry['messages'] as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$flat[] = array(
				'location'   => $short . ':' . ( isset( $message['line'] ) ? (int) $message['line'] : 0 ),
				'identifier' => isset( $message['identifier'] ) ? (string) $message['identifier'] : '',
				'message'    => isset( $message['message'] ) ? (string) $message['message'] : '',
			);
		}
	}

	if ( $flat ) {
		echo '<details><summary>First ' . min( count( $flat ), $max ) . ' of ' . count( $flat )
			. " error(s)</summary>\n\n```\n";
		foreach ( array_slice( $flat, 0, $max ) as $item ) {
			$ident = '' === $item['identifier'] ? '' : ' [' . $item['identifier'] . ']';
			echo conexao_ci_summary_cell( $item['location'] . $ident . ': ' . $item['message'], 300 ) . "\n";
		}
		if ( count( $flat ) > $max ) {
			echo '… and ' . ( count( $flat ) - $max ) . " more (see the artifact).\n";
		}
		echo "```\n\n</details>\n";
	}
}


/**
 * Summarise the Stage L permanent-gate report (`gate.json`).
 *
 * @param string $json Raw JSON document.
 * @return void
 */
function conexao_ci_summary_gates( $json ) {
	$data = json_decode( $json, true );

	if ( ! is_array( $data ) || ! isset( $data['gates'] ) || ! is_array( $data['gates'] ) ) {
		conexao_ci_summary_unavailable( 'gate.json was not the expected document' );
		return;
	}

	$overall = isset( $data['status'] ) ? (string) $data['status'] : 'unknown';
	$counts  = array( 'pass' => 0, 'fail' => 0, 'blocked' => 0 );
	$new     = 0;
	$pre     = 0;

	echo ( 'pass' === $overall ? ':white_check_mark: ' : ':x: ' )
		. '**Overall: ' . strtoupper( $overall ) . '**';
	if ( isset( $data['repository_sha'] ) ) {
		echo ' — commit `' . substr( (string) $data['repository_sha'], 0, 12 ) . '`';
	}
	echo "\n\n";

	echo "| Gate | Status | Passed | Failed | Violations | Pre-existing | New |\n";
	echo "|---|---|---|---|---|---|---|\n";
	foreach ( $data['gates'] as $name => $gate ) {
		if ( ! is_array( $gate ) ) {
			continue;
		}
		$status = isset( $gate['status'] ) ? (string) $gate['status'] : 'unknown';
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ]++;
		}
		$new += isset( $gate['new'] ) ? (int) $gate['new'] : 0;
		$pre += isset( $gate['pre_existing'] ) ? (int) $gate['pre_existing'] : 0;

		echo '| `' . conexao_ci_summary_cell( (string) $name, 60 ) . '` | `' . $status . '` | '
			. ( isset( $gate['passed'] ) ? (int) $gate['passed'] : 0 ) . ' | '
			. ( isset( $gate['failed'] ) ? (int) $gate['failed'] : 0 ) . ' | '
			. ( isset( $gate['violations'] ) ? (int) $gate['violations'] : 0 ) . ' | '
			. ( isset( $gate['pre_existing'] ) ? (int) $gate['pre_existing'] : 0 ) . ' | '
			. ( isset( $gate['new'] ) ? (int) $gate['new'] : 0 ) . " |\n";
	}

	echo "\n**" . $counts['pass'] . ' passed, ' . $counts['fail'] . ' failed, '
		. $counts['blocked'] . " blocked.**\n\n";
	echo 'Violations: **' . $new . ' new**, ' . $pre . " pre-existing.\n";

	if ( $counts['fail'] > 0 || $counts['blocked'] > 0 ) {
		echo "\n> A failing or blocked gate is a real repository/data condition, not a reporting artefact.\n";
	}
}

/**
 * Summarise the release-integrity log (`FAIL: …` lines + `N passed, M failed`).
 *
 * @param string $log Release-integrity log.
 * @param int    $max Detail-row cap.
 * @return void
 */
function conexao_ci_summary_release( $log, $max ) {
	$lines    = explode( "\n", conexao_ci_summary_strip_ansi( $log ) );
	$failures = array();
	$totals   = '';

	foreach ( $lines as $line ) {
		$line = rtrim( $line, "\r" );
		if ( 0 === strpos( $line, 'FAIL: ' ) ) {
			$failures[] = substr( $line, 6 );
			continue;
		}
		if ( preg_match( '/^(\d+) passed, (\d+) failed$/', $line, $m ) ) {
			$totals = $m[1] . ' passed, ' . $m[2] . ' failed';
		}
	}

	if ( '' === $totals && ! $failures ) {
		conexao_ci_summary_unavailable( 'the release-integrity log contained no recognisable result' );
		return;
	}

	echo ( $failures ? ':x: ' : ':white_check_mark: ' )
		. '**Release integrity: ' . ( '' !== $totals ? $totals : 'no totals line' ) . "**\n\n";

	if ( $failures ) {
		echo "| # | Failure |\n|---|---|\n";
		$i = 0;
		foreach ( array_slice( $failures, 0, $max ) as $failure ) {
			++$i;
			echo '| ' . $i . ' | ' . conexao_ci_summary_cell( $failure, 200 ) . " |\n";
		}
		if ( count( $failures ) > $max ) {
			echo "\n_… and " . ( count( $failures ) - $max ) . " more (see the artifact log)._\n";
		}
	}
}


/**
 * Summarise a `scripts/lint.sh` log.
 *
 * The three sections `lint.sh` prints are parsed so a reviewer sees the actual
 * counts without opening the raw log, and without re-running any analyser (the
 * point of parsing the log rather than re-invoking the tool).
 *
 * Recognised shapes:
 *   `==> [1/3] PHP syntax check (php -l, N files)` + `OK: no PHP parse errors.`
 *   `PHPCS legacy baseline: E errors / W warnings in F files (baseline: E / W).`
 *   `OK: no new PHPCS violations against the baseline.` / `NEW VIOLATIONS`
 *   `[OK] No errors` / `Found N error(s)` from PHPStan
 *   `lint: OK (...)`
 *
 * @param string $log lint.sh log.
 * @param int    $max Detail-row cap.
 * @return void
 */
function conexao_ci_summary_lint( $log, $max ) {
	$lines = explode( "\n", conexao_ci_summary_strip_ansi( $log ) );

	$syntax = '';
	$phpcs  = '';
	$phpcs_ok = null;
	$stan   = '';
	$verdict = '';
	$errors = array();

	foreach ( $lines as $line ) {
		$line = rtrim( $line, "\r" );
		if ( preg_match( '/PHP syntax check \(php -l, (\d+) files\)/', $line, $m ) && '' === $syntax ) {
			$syntax = $m[1] . ' files';
			continue;
		}
		if ( 0 === strpos( $line, 'PHPCS legacy baseline: ' ) ) {
			$phpcs = substr( $line, strlen( 'PHPCS legacy baseline: ' ) );
			continue;
		}
		if ( 0 === strpos( $line, 'OK: no new PHPCS violations' ) ) {
			$phpcs_ok = true;
			continue;
		}
		if ( false !== strpos( $line, 'NEW VIOLATIONS' ) ) {
			$phpcs_ok = false;
			continue;
		}
		if ( false !== strpos( $line, '[OK] No errors' ) ) {
			$stan = 'no errors';
			continue;
		}
		if ( preg_match( '/Found (\d+) error/', $line, $m ) ) {
			$stan = $m[1] . ' error(s)';
			continue;
		}
		if ( 0 === strpos( $line, 'lint: ' ) ) {
			$verdict = trim( substr( $line, 6 ) );
			continue;
		}
		if ( preg_match( '/^\s*(?:[A-Za-z0-9_.\/-]+\.php)?\s*(\d+)\s/', $line ) && false !== strpos( $line, 'ERROR' ) ) {
			$errors[] = $line;
			continue;
		}
		// A PHPStan table row: a line number followed by the message column.
		if ( preg_match( '/^\s*(\d+)\s{2,}(.+)$/', $line, $m ) && strlen( trim( $m[2] ) ) > 15 ) {
			$errors[] = 'line ' . $m[1] . ': ' . trim( $m[2] );
		}
	}

	if ( '' === $syntax && '' === $phpcs && '' === $stan && '' === $verdict ) {
		conexao_ci_summary_unavailable( 'the lint log contained no recognisable section' );
		return;
	}

	$clean = 0 === strpos( $verdict, 'OK' );
	echo ( $clean ? ':white_check_mark: ' : ':x: ' )
		. '**lint.sh: ' . ( '' !== $verdict ? $verdict : 'no verdict line' ) . "**\n\n";

	echo "| Check | Result |\n|---|---|\n";
	echo '| PHP syntax (`php -l`) | ' . ( '' !== $syntax ? conexao_ci_summary_cell( $syntax, 60 ) : '—' ) . " |\n";
	echo '| PHPCS legacy baseline | '
		. ( '' !== $phpcs ? conexao_ci_summary_cell( $phpcs, 160 ) : '—' ) . " |\n";
	echo '| PHPCS new violations | '
		. ( null === $phpcs_ok ? '—' : ( $phpcs_ok ? 'none' : '**present**' ) ) . " |\n";
	echo '| PHPStan (level 5) | ' . ( '' !== $stan ? conexao_ci_summary_cell( $stan, 60 ) : '—' ) . " |\n";

	if ( $errors ) {
		echo "\n#### First " . min( count( $errors ), $max ) . ' PHPCS/PHPStan error line(s)' . "\n\n```\n";
		foreach ( array_slice( $errors, 0, $max ) as $error ) {
			echo conexao_ci_summary_cell( $error, 300 ) . "\n";
		}
		echo "```\n";
	}
}

/**
 * Entry point.
 *
 * @param array<int, string> $argv Raw argv.
 * @return int Always 0 — a reporting helper never decides a job verdict.
 */
function conexao_ci_summary_main( array $argv ) {
	$args  = conexao_ci_summary_args( $argv );
	$kind  = isset( $args['kind'] ) ? $args['kind'] : '';
	$max   = isset( $args['max-items'] ) ? max( 1, (int) $args['max-items'] ) : 20;
	$title = isset( $args['title'] ) ? $args['title'] : '';

	if ( '' !== $title ) {
		echo '### ' . $title . "\n\n";
	}

	switch ( $kind ) {
		case 'harness':
			$log = conexao_ci_summary_read( isset( $args['log'] ) ? $args['log'] : '' );
			if ( null === $log ) {
				conexao_ci_summary_unavailable( 'log file not found: ' . ( isset( $args['log'] ) ? $args['log'] : '(none given)' ) );
				break;
			}
			conexao_ci_summary_harness( $log, $max );
			break;

		case 'phpstan':
			$json = conexao_ci_summary_read( isset( $args['json'] ) ? $args['json'] : '' );
			if ( null === $json ) {
				conexao_ci_summary_unavailable( 'PHPStan report not found: ' . ( isset( $args['json'] ) ? $args['json'] : '(none given)' ) );
				break;
			}
			conexao_ci_summary_phpstan( $json, $max );
			break;

		case 'gates':
			$json = conexao_ci_summary_read( isset( $args['json'] ) ? $args['json'] : '' );
			if ( null === $json ) {
				conexao_ci_summary_unavailable( 'gate.json not found: ' . ( isset( $args['json'] ) ? $args['json'] : '(none given)' ) );
				break;
			}
			conexao_ci_summary_gates( $json );
			break;

		case 'release':
			$log = conexao_ci_summary_read( isset( $args['log'] ) ? $args['log'] : '' );
			if ( null === $log ) {
				conexao_ci_summary_unavailable( 'release log not found: ' . ( isset( $args['log'] ) ? $args['log'] : '(none given)' ) );
				break;
			}
			conexao_ci_summary_release( $log, $max );
			break;

		case 'lint':
			$log = conexao_ci_summary_read( isset( $args['log'] ) ? $args['log'] : '' );
			if ( null === $log ) {
				conexao_ci_summary_unavailable( 'lint log not found: ' . ( isset( $args['log'] ) ? $args['log'] : '(none given)' ) );
				break;
			}
			conexao_ci_summary_lint( $log, $max );
			break;

		default:
			conexao_ci_summary_unavailable( '--kind must be one of harness, phpstan, gates, release, lint' );
	}

	return 0;
}

conexao_ci_summary_main( $argv );

