<?php
/**
 * Stage 3.3 — LOCAL-ONLY editorial translation-state inventory.
 *
 * Read-only. Walks every Polylang-translated content type and reports the
 * editorial state of each record using the SAME source of truth the wp-admin
 * indicator uses (`Conexao_Admin_UX_Translation_State::get_state()`), grouped
 * by content type:
 *
 *   missing        — a published PT record with no English translation
 *   current        — a real, linked English translation up to date with its PT source
 *   outdated       — a linked English translation edited after its PT source
 *   not_applicable — the type/record is outside the English programme
 *
 * It never creates, edits or links anything: the Stage 3.2 B1/B2 policy stays
 * exactly as it is.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage33-translation-inventory.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}

if ( ! class_exists( 'Conexao_Admin_UX_Translation_State' ) ) {
	fwrite( STDERR, "conexao-admin-ux translation-state module is not loaded.\n" );
	exit( 1 );
}

$types = Conexao_Admin_UX_Translation_State::post_types();
sort( $types );

$states  = array( 'missing', 'current', 'outdated', 'not_applicable' );
$summary = array();
$missing = array();

echo "== Stage 3.3 — editorial translation-state inventory ==\n";
echo 'content types in scope: ' . implode( ', ', $types ) . "\n\n";

foreach ( $types as $post_type ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => 'pt',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	if ( ! $ids ) {
		continue;
	}

	$counts = array_fill_keys( $states, 0 );

	foreach ( $ids as $post_id ) {
		$state = Conexao_Admin_UX_Translation_State::get_state( $post_id );
		$key   = $state['state'];

		if ( ! isset( $counts[ $key ] ) ) {
			$counts[ $key ] = 0;
		}

		$counts[ $key ]++;

		if ( 'missing' === $key ) {
			$missing[ $post_type ][] = get_post_field( 'post_name', $post_id ) . " (#{$post_id})";
		}
	}

	printf(
		"%-16s total=%-4d missing=%-4d current=%-4d outdated=%-4d not_applicable=%-4d\n",
		$post_type,
		count( $ids ),
		$counts['missing'],
		$counts['current'],
		$counts['outdated'],
		$counts['not_applicable']
	);

	$summary[ $post_type ] = $counts;
}

// English records without a PT source (source-inherited content).
echo "\n-- English-source records (no PT counterpart) --\n";

foreach ( $types as $post_type ) {
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'lang'           => 'en',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	foreach ( $ids as $post_id ) {
		$source = pll_get_post( $post_id, 'pt' );

		if ( $source && (int) $source !== (int) $post_id ) {
			continue;
		}

		printf(
			"%-16s %s (#%d) — self-standing English record\n",
			$post_type,
			get_post_field( 'post_name', $post_id ),
			$post_id
		);
	}
}

echo "\n-- Content awaiting English authoring (state: missing) --\n";

foreach ( $missing as $post_type => $slugs ) {
	echo "{$post_type} (" . count( $slugs ) . "):\n";

	foreach ( $slugs as $slug ) {
		echo "  - {$slug}\n";
	}
}

if ( ! $missing ) {
	echo "  (none)\n";
}

// Overlay the approved B1/B2 policy so the rollout plan is not read as
// "everything missing must be translated".
$b2_ids = array();

foreach ( get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => 'pt',
	)
) as $page_id ) {
	if ( function_exists( 'conexao_is_b2_page' ) && conexao_is_b2_page( $page_id ) ) {
		$b2_ids[] = $page_id;
	}
}

echo "\n-- Policy overlay --\n";
echo 'B2-accepted pages (Portuguese content served under the EN URL, no translation required): ' . count( $b2_ids ) . "\n";

foreach ( $b2_ids as $page_id ) {
	printf( "  - %s (#%d)\n", get_post_field( 'post_name', $page_id ), $page_id );
}

echo "\nBacklog after the policy overlay:\n";
echo "  - every 'missing' record outside the B2 set is B1 by policy: it keeps the\n";
echo "    approved 302 to Portuguese and is only translated when editorial\n";
echo "    capacity is approved (see the Stage 3.3 report §7/§19).\n";

echo "\nDone. Inventory only — nothing was created, linked or modified.\n";
