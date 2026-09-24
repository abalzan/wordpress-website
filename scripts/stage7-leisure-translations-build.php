<?php
/**
 * Stage 7 — assemble + validate the Leisure EN description manifest (Phase 2/3).
 *
 * Pure-PHP CLI (no WordPress). Merges the authored batch files
 * (stage7-work/translations-batch-*.json, flat slug → EN text) with the
 * measured inventory (stage7-work/leisure-description-inventory.json,
 * slug → raw PT post_excerpt) into the final Stage 7 manifest:
 *
 *   stage7-work/leisure-description-translations.json
 *
 * Validation gates (hard failures abort the build):
 *   - every inventory slug has exactly ONE authored EN description;
 *   - no extra slugs that the archive does not render;
 *   - no empty / whitespace-only EN values;
 *   - no HTML tags in EN values (the PT sources are plain text; the card
 *     pipeline esc_html()s the trimmed text — markup cannot survive there);
 *   - EN length is proportionate to PT (0.5×–1.75× word count), flag-only;
 *   - EN must not equal PT (no untranslated copies).
 *
 * Usage:
 *   .local/php/php scripts/stage7-leisure-translations-build.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}

$work   = dirname( __DIR__ ) . '/stage7-work';
$batches = glob( $work . '/translations-batch-*.json' );
sort( $batches );

if ( empty( $batches ) ) {
	exit( "ERROR: no translation batch files found.\n" );
}

$inventory_file = $work . '/leisure-description-inventory.json';
$inventory      = json_decode( (string) file_get_contents( $inventory_file ), true );
if ( ! is_array( $inventory ) || empty( $inventory['records'] ) ) {
	exit( "ERROR: could not read {$inventory_file}.\n" );
}

// --- 1. Merge the authored batches. ---------------------------------------------

$authored = array();
foreach ( $batches as $batch_file ) {
	$batch = json_decode( (string) file_get_contents( $batch_file ), true );
	if ( ! is_array( $batch ) ) {
		exit( "ERROR: could not decode {$batch_file}.\n" );
	}
	foreach ( $batch as $slug => $en ) {
		if ( isset( $authored[ $slug ] ) ) {
			exit( "ERROR: duplicate authored slug '{$slug}' (in more than one batch).\n" );
		}
		$authored[ $slug ] = (string) $en;
	}
}

// --- 2. Validate against the inventory. -----------------------------------------

$entries = array();
$errors  = array();
$flags   = array();
$seq     = 0;

$order = array();
foreach ( (array) $inventory['cards'] as $card ) {
	$order[ (int) $card['id'] ] = true;
}
$records = array();
foreach ( (array) $inventory['records'] as $record ) {
	$records[ (int) $record['id'] ] = $record;
}

foreach ( $records as $record ) {
	$slug = $record['slug'];
	$seq++;

	if ( ! isset( $authored[ $slug ] ) ) {
		$errors[] = "missing EN description for '{$slug}'";
		continue;
	}

	$en = trim( $authored[ $slug ] );
	$pt = trim( (string) $record['pt_excerpt_raw'] );

	if ( '' === $en ) {
		$errors[] = "empty EN description for '{$slug}'";
		continue;
	}
	if ( strip_tags( $en ) !== $en ) {
		$errors[] = "EN description for '{$slug}' contains HTML markup (PT source is plain text)";
		continue;
	}
	if ( $en === $pt ) {
		$errors[] = "EN description for '{$slug}' is identical to the PT source (untranslated copy)";
		continue;
	}

	$pt_words = count( preg_split( '/[\n\r\t ]+/', $pt, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	$en_words = count( preg_split( '/[\n\r\t ]+/', $en, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	$ratio    = $pt_words > 0 ? $en_words / $pt_words : 0;
	if ( $ratio < 0.5 || $ratio > 1.75 ) {
		$flags[] = sprintf( "length ratio %.2f for '%s' (pt %d / en %d words)", $ratio, $slug, $pt_words, $en_words );
	}

	$entries[] = array(
		'seq'        => $seq,
		'id'         => (int) $record['id'],
		'slug'       => $slug,
		'title'      => $record['title'],
		'pt_excerpt' => $pt,
		'en_excerpt' => $en,
	);

	unset( $authored[ $slug ] );
}

if ( ! empty( $authored ) ) {
	foreach ( array_keys( $authored ) as $extra ) {
		$errors[] = "authored slug '{$extra}' does not exist in the inventory (archive does not render it)";
	}
}

if ( ! empty( $errors ) ) {
	echo "BUILD FAILED — " . count( $errors ) . " error(s):\n";
	foreach ( $errors as $error ) {
		echo "  - {$error}\n";
	}
	exit( 1 );
}

// --- 3. Emit the manifest. --------------------------------------------------------

$manifest = array(
	'stage'       => '7 — EN Leisure card descriptions',
	'source'      => 'PT: production read-only capture 2026-09-24 (post_excerpt of every published leisure record)',
	'authoring'   => 'EN: human-authored translations of the PT card descriptions (Stage 7)',
	'entry_count' => count( $entries ),
	'entries'     => $entries,
);

file_put_contents(
	$work . '/leisure-description-translations.json',
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);

echo "OK — {$manifest['entry_count']} entries written to leisure-description-translations.json\n";
echo 'flags (non-blocking): ' . count( $flags ) . "\n";
foreach ( $flags as $flag ) {
	echo "  ! {$flag}\n";
}
