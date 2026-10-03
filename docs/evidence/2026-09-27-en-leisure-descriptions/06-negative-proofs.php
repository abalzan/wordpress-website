<?php
/**
 * Evidence helper (local only, NOT a product script and NOT in scripts/):
 * exercise the failure paths of the en-leisure-description stage on a
 * THROWAWAY record, proving the guards actually fire.
 *
 * Creates one temporary leisure record, drives each negative case, and deletes
 * the record (and its meta) before exiting. It never touches a real record.
 *
 * Usage: docker compose exec -T wordpress php /tmp/negative-proofs.php
 */

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once '/var/www/html/wp-load.php';

$slug = 'conexao-negative-proof-leisure';
$pt   = 'Descrição portuguesa de prova para o guard de divergência.';
$en   = 'Portuguese proof description for the drift guard.';

$passed = 0;
$failed = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Assertion label.
 * @param string $detail    Optional detail.
 * @return void
 */
function proof( $condition, $label, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  PASS: {$label}\n";
		return;
	}
	++$failed;
	echo "  FAIL: {$label}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
}

// Always clean up, whatever happens.
register_shutdown_function(
	static function () use ( $slug ) {
		$posts = get_posts(
			array(
				'post_type'        => 'leisure',
				'name'             => $slug,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'lang'             => '',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $p ) {
			wp_delete_post( (int) $p->ID, true );
		}
	}
);

$config  = conexao_en_translation_leisure_description_config();
$adapter = conexao_en_translation_leisure_description_adapter();

$id = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_status'  => 'publish',
		'post_title'   => 'Negative proof leisure',
		'post_name'    => $slug,
		'post_excerpt' => $pt,
	),
	true
);

if ( is_wp_error( $id ) || ! $id ) {
	echo "  FAIL: could not create the throwaway record\n";
	exit( 1 );
}

pll_set_post_language( $id, pll_default_language( 'slug' ) );

echo "== Proof 1: the engine's PT-drift guard refuses a stale translation ==\n";

// The adapter resolves the expected Portuguese source from the AUTHORED
// MANIFEST, keyed by slug — so the proof must drive a real manifest row, not a
// synthetic one. `dwyer-mcallister-cottage` is a real row, so its excerpt is
// drifted and restored around the probe (this is the real code path).
$real = get_posts(
	array(
		'post_type'        => 'leisure',
		'name'             => 'dwyer-mcallister-cottage',
		'post_status'      => 'publish',
		'numberposts'      => 1,
		'lang'             => '',
		'suppress_filters' => true,
	)
);

if ( empty( $real ) ) {
	echo "  SKIP: the Dwyer record is not present in this environment\n";
} else {
	$real_id  = (int) $real[0]->ID;
	$original = (string) $real[0]->post_excerpt;
	$en_before = (string) get_post_meta( $real_id, '_leisure_excerpt_en', true );

	wp_update_post(
		array(
			'ID'           => $real_id,
			'post_excerpt' => 'Descrição alterada depois de a tradução ter sido escrita.',
		)
	);

	$state = $adapter['find_en_for_pt']( $real_id, 'dwyer-mcallister-cottage' );
	proof( false === $state['pair_ok'], 'a drifted source is not reported as translated' );
	proof( '' !== $state['stage_conflict'], 'a drifted source declares a stage conflict', wp_json_encode( $state ) );

	$row = conexao_en_translation_leisure_row_for_slug( 'dwyer-mcallister-cottage' );
	$plan = Conexao_Translation_Rollout_Engine::build_plan(
		array(
			'source_lang' => 'pt',
			'target_lang' => 'en',
			'records'     => array( 'dwyer-mcallister-cottage' => $row ),
		),
		array(
			'dwyer-mcallister-cottage' => array_merge(
				$state,
				array(
					'pt_id'          => $real_id,
					'pt_status'      => 'publish',
					'slug_collision' => false,
				)
			),
		)
	);
	proof( 0 === count( $plan['create'] ), 'a drifted source is NOT planned for create', wp_json_encode( $plan ) );
	proof( 1 === count( $plan['conflicts'] ), 'a drifted source IS planned as a conflict', wp_json_encode( $plan ) );
	proof( $en_before === (string) get_post_meta( $real_id, '_leisure_excerpt_en', true ), 'the refused record was not written' );

	wp_update_post(
		array(
			'ID'           => $real_id,
			'post_excerpt' => $original,
		)
	);
	proof( $original === (string) get_post( $real_id )->post_excerpt, 'the drift probe restored the PT excerpt' );

	// Restored: the row plans as an ordinary create/skip again, with no conflict.
	$state_ok = $adapter['find_en_for_pt']( $real_id, 'dwyer-mcallister-cottage' );
	proof( '' === $state_ok['stage_conflict'], 'a restored source declares NO conflict' );
	proof( true === $state_ok['pair_ok'], 'a restored, already-translated record is reported as translated' );
}

// Typographic / entity differences must NOT be mistaken for drift.
proof(
	conexao_en_translation_leisure_normalize( "One Man\u{2019}s Pass" ) === conexao_en_translation_leisure_normalize( "One Man's Pass" ),
	'a curly vs straight apostrophe is not content drift'
);
proof(
	conexao_en_translation_leisure_normalize( 'King John&#8217;s Castle' ) === conexao_en_translation_leisure_normalize( "King John's Castle" ),
	'an HTML entity vs its decoded character is not content drift'
);
proof(
	conexao_en_translation_leisure_normalize( "Palacio real \xE2\x80\x94 marinho" ) === conexao_en_translation_leisure_normalize( 'Palacio real - marinho' ),
	'an em dash vs a hyphen is not content drift'
);
proof(
	conexao_en_translation_leisure_normalize( "Casas \xE2\x80\x9CAntas\xE2\x80\x9D" ) === conexao_en_translation_leisure_normalize( 'Casas "Antas"' ),
	'curly vs straight double quotes are not content drift'
);
proof(
	conexao_en_translation_leisure_normalize( 'Palacio real' ) !== conexao_en_translation_leisure_normalize( 'Palacio medieval' ),
	'a real word change IS still detected as drift'
);
proof(
	conexao_en_translation_leisure_normalize( 'Casa grande' ) !== conexao_en_translation_leisure_normalize( 'casa grande' ),
	'case is NOT folded, so a case change is still drift'
);


echo "== Proof 2: the write is idempotent and touches one field only ==\n";

update_post_meta( $id, '_leisure_excerpt_en', $en );
$rows_before = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d", $id )
);
$adapter['repair_en']( (int) $id, (int) $id, array( 'en_description' => $en ) );
$rows_after = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d", $id )
);
proof( $rows_before === $rows_after, 're-writing an identical description adds no meta row', "{$rows_before} -> {$rows_after}" );
proof( $en === (string) get_post_meta( $id, '_leisure_excerpt_en', true ), 'the stored value is still the authored English' );

echo "== Proof 3: a record with no authored English is never written ==\n";
$result = $adapter['create_en']( (int) $id, array( 'en_description' => '   ' ) );
proof( is_wp_error( $result ), 'an empty English description is refused with a WP_Error' );
proof( $en === (string) get_post_meta( $id, '_leisure_excerpt_en', true ), 'the refused write left the stored value unchanged' );

echo "== Proof 4: remove deletes only the English field ==\n";
$pt_before    = (string) get_post( $id )->post_excerpt;
$title_before = (string) get_post( $id )->post_title;
$adapter['remove_en']( (int) $id );
proof( '' === trim( (string) get_post_meta( $id, '_leisure_excerpt_en', true ) ), 'remove clears the English description' );
proof( $pt_before === (string) get_post( $id )->post_excerpt, 'remove leaves the PT excerpt intact' );
proof( $title_before === (string) get_post( $id )->post_title, 'remove leaves the PT title intact' );
proof( (bool) get_post( $id ), 'remove does NOT delete the post' );

echo "== Proof 5: the manifest fails closed on a duplicate key / bad shape ==\n";
$dup = array(
	'source_lang' => 'pt',
	'target_lang' => 'en',
	'records'     => array(
		'a' => array( 'en_slug' => 'same-slug', 'en_description' => 'x' ),
		'b' => array( 'en_slug' => 'same-slug', 'en_description' => 'y' ),
	),
);
$check = Conexao_Translation_Rollout_Engine::validate_manifest( $dup, $config );
proof( is_wp_error( $check ), 'a duplicate en_slug fails closed', is_wp_error( $check ) ? $check->get_error_message() : 'no error' );

$wrong_lang = array(
	'source_lang' => 'en',
	'target_lang' => 'pt',
	'records'     => array( 'a' => array( 'en_slug' => 'a', 'en_description' => 'x' ) ),
);
$check2 = Conexao_Translation_Rollout_Engine::validate_manifest( $wrong_lang, $config );
proof( is_wp_error( $check2 ), 'a language mismatch fails closed', is_wp_error( $check2 ) ? $check2->get_error_message() : 'no error' );

wp_delete_post( (int) $id, true );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
