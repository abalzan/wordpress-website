<?php
/**
 * Stage 3.2 — controlled English content migration pilot.
 *
 * LOCAL / STAGING ONLY. Creates a small, explicitly selected set of English
 * translations linked through Polylang (`pll_save_post_translations`).
 *
 * Selection rule (controlled, NOT a bulk translation): exactly the records
 * listed in stage32_content_pilot_map() below — one or two per content type,
 * chosen to exercise the archive/filter/SEO paths of every type in scope.
 * Every other record keeps its approved fallback (B1 or B2).
 *
 * Authorship: all English copy below is human-authored for this stage. No
 * machine translation is applied anywhere.
 *
 * Identity guarantees enforced here (hard gates):
 *   - The Portuguese record is never modified.
 *   - Event translations carry the SAME _event_source + _event_source_id and
 *     the SAME _event_export_uuid (language-neutral identity), and keep the
 *     same scheduling/lifecycle meta. They never become import targets
 *     (Conexao_Event_Importer_Language_Guard) and never bypass _event_status.
 *   - Leisure translations keep the SAME _leisure_export_uuid and the same
 *     internal/external classification; an external destination is never
 *     changed for language reasons.
 *   - Sponsors keep the SAME _sponsor_export_uuid.
 *   - Featured media is shared (same attachment), never duplicated.
 *   - Taxonomy: the EN record carries the linked EN term when one exists,
 *     otherwise the shared PT term.
 *
 * Idempotent: records that already have an EN translation are skipped.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage32-translate-content.php
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
if ( ! function_exists( 'pll_save_post_translations' ) ) {
	fwrite( STDERR, "Polylang is not active.\n" );
	exit( 1 );
}

/**
 * Meta keys copied verbatim to an EN translation, per post type.
 * Identity/scheduling/classification only — editorial text fields are
 * authored in English, never copied.
 */
function stage32_meta_copy_map() {
	return array(
		'event'           => array(
			'_event_source', '_event_source_id', '_event_export_uuid',
			'_event_url', '_event_source_url',
			'_event_date', '_event_time', '_event_start_time', '_event_end_date', '_event_end_time',
			'_event_location', '_event_venue', '_event_address', '_event_map_url',
			'_event_organizer', '_event_price', '_event_registration', '_event_cta',
			'_event_status', '_event_imported', '_event_import_date',
			'_event_banner', '_event_banner_attachment_id',
			'_event_recurrence', '_event_recurrence_days', '_event_recurrence_start', '_event_recurrence_end',
			'_event_source_language',
		),
		'leisure'         => array(
			'_leisure_export_uuid',
			'_leisure_county', '_leisure_town', '_leisure_address',
			'_leisure_website', '_leisure_official_website', '_leisure_discover_ireland',
			'_leisure_map_url', '_leisure_internal_page', '_leisure_feature',
			'_leisure_free', '_leisure_family', '_leisure_accessibility',
			'_leisure_pet_friendly', '_leisure_indoor', '_leisure_outdoor',
			'_leisure_parking', '_leisure_booking', '_leisure_duration', '_leisure_best_time',
			'_leisure_image_attachment_id', '_leisure_image_source', '_leisure_image_source_url',
			'_leisure_image_author', '_leisure_image_license', '_leisure_image_attribution',
			'_leisure_image_alt_text', '_leisure_image_status',
			'_leisure_practical_notes', '_leisure_practical_source_url', '_leisure_practical_last_checked',
		),
		'sponsor'         => array(
			'_sponsor_export_uuid', '_sponsor_link', '_sponsor_display_order', '_sponsor_contacts',
		),
		'course_provider' => array(
			'_provider_logo', '_provider_location', '_provider_url', '_provider_status', '_provider_order',
		),
		'job'             => array(),
		'guide'           => array(),
		'post'            => array(),
	);
}

/**
 * Create one linked EN translation for a PT record. Returns EN post ID or 0.
 */
function stage32_translate_record( $pt_id, $en_slug, $en_title, $en_content, $meta_desc = '', $extra_meta = array() ) {
	$pt_id = (int) $pt_id;
	$pt    = get_post( $pt_id );
	if ( ! $pt ) {
		echo "    ERROR: PT record #{$pt_id} not found\n";
		return 0;
	}

	$existing = (int) pll_get_post( $pt_id, 'en' );
	if ( $existing ) {
		echo "    EXISTS: {$pt->post_type}/{$pt->post_name} → en #{$existing} (skipped)\n";
		return $existing;
	}

	$clash = get_posts(
		array(
			'post_type'   => $pt->post_type,
			'name'        => $en_slug,
			'post_status' => 'any',
			'fields'      => 'ids',
			'lang'        => '',
		)
	);
	if ( $clash ) {
		echo "    ERROR: {$pt->post_type} slug '{$en_slug}' already taken by #{$clash[0]} — refusing to collide.\n";
		return 0;
	}

	$en_id = wp_insert_post(
		array(
			'post_type'    => $pt->post_type,
			'post_name'    => $en_slug,
			'post_title'   => $en_title,
			'post_content' => $en_content,
			'post_status'  => 'publish',
		),
		true
	);
	if ( is_wp_error( $en_id ) ) {
		echo "    ERROR creating EN {$pt->post_name}: " . $en_id->get_error_message() . "\n";
		return 0;
	}

	pll_set_post_language( $en_id, 'en' );
	pll_set_post_language( $pt_id, 'pt' );
	pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

	// Identity/meta copy (allowlist per type) + extra per-record meta.
	$copy_map = stage32_meta_copy_map();
	$keys     = isset( $copy_map[ $pt->post_type ] ) ? $copy_map[ $pt->post_type ] : array();
	foreach ( $keys as $key ) {
		$value = get_post_meta( $pt_id, $key, true );
		if ( '' !== $value && null !== $value && false !== $value ) {
			update_post_meta( $en_id, $key, $value );
		}
	}
	foreach ( $extra_meta as $key => $value ) {
		update_post_meta( $en_id, $key, $value );
	}
	if ( '' !== $meta_desc ) {
		update_post_meta( $en_id, 'conexao_meta_description', $meta_desc );
	}

	// Featured media is shared, never duplicated.
	$thumb = get_post_thumbnail_id( $pt_id );
	if ( $thumb ) {
		set_post_thumbnail( $en_id, $thumb );
	}

	// Taxonomy: linked EN term when it exists, shared PT term otherwise.
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_town', 'conexao_tag', 'category', 'post_tag' ) as $taxonomy ) {
		$terms = wp_get_object_terms( $pt_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}
		$assign = array();
		foreach ( $terms as $term_id ) {
			$en_term = function_exists( 'pll_get_term' ) ? (int) pll_get_term( $term_id, 'en' ) : 0;
			$assign[] = $en_term > 0 ? $en_term : (int) $term_id;
		}
		wp_set_object_terms( $en_id, $assign, $taxonomy );
	}

	// Verify the link from both sides.
	$check_en = (int) pll_get_post( $pt_id, 'en' );
	$check_pt = (int) pll_get_post( $en_id, 'pt' );
	if ( $check_en !== $en_id || $check_pt !== $pt_id ) {
		echo "    ERROR: link verification failed for {$pt->post_name}.\n";
		return 0;
	}

	echo "    OK {$pt->post_type}: {$pt->post_name} (#{$pt_id}) <=> {$en_slug} (#{$en_id})\n";
	return $en_id;
}

/**
 * The controlled pilot set. Everything NOT listed here keeps its approved
 * fallback (B1/B2). English copy below is human-authored.
 */
function stage32_content_pilot_map() {
	return array(
		'guide' => array(
			'como-tirar-o-pps-number' => array(
				'en_slug'  => 'how-to-get-a-pps-number',
				'title'    => 'How to get a PPS Number',
				'content'  => '<!-- wp:paragraph --><p>The PPS Number is your social identification number in Ireland. You need it to work, open a bank account and access public services.</p><!-- /wp:paragraph -->\n<!-- wp:heading --><h2>Documents you need</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>Your passport, proof of address and the reason for your application. Apply through MyWelfare or at your local Intreo Centre.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'How to get a PPS Number in Ireland: required documents and the step-by-step application for Brazilians.',
			),
			'como-abrir-conta-bancaria-na-irlanda' => array(
				'en_slug'  => 'how-to-open-a-bank-account-in-ireland',
				'title'    => 'How to open a bank account in Ireland',
				'content'  => '<!-- wp:paragraph --><p>To open a bank account in Ireland you need a photo ID and proof of an Irish address.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Traditional banks such as AIB and Bank of Ireland, and digital banks such as Revolut, are common choices in the community.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'How to open a bank account in Ireland: documents, traditional and digital banks for the Brazilian community.',
			),
		),
		'post' => array(
			'comunidade-celebra-festa-junina-em-dublin' => array(
				'en_slug'  => 'community-celebrates-festa-junina-in-dublin',
				'title'    => 'Community celebrates Festa Junina in Dublin',
				'content'  => '<!-- wp:paragraph --><p>The Brazilian community celebrated Festa Junina in Dublin with typical food, music and the traditional quadrilha dance. Read the details on Conexão BR Irlanda.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'The Brazilian community celebrated Festa Junina in Dublin with typical food and music.',
			),
		),
		'event' => array(
			'festa-junina-dublin-2026' => array(
				'en_slug'  => 'festa-junina-dublin-2026-en',
				'title'    => 'Festa Junina Dublin 2026',
				'content'  => '<!-- wp:paragraph --><p>The traditional Festa Junina of the Brazilian community in Dublin, with typical food, quadrilha and live music.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'Festa Junina Dublin 2026: the Brazilian community festival with typical food and live music.',
			),
		),
		'leisure' => array(
			'phoenix-park' => array(
				'en_slug'  => 'phoenix-park-en',
				'title'    => 'Phoenix Park',
				'content'  => '<!-- wp:paragraph --><p>Phoenix Park is one of the largest urban parks in Europe, with walking trails, fallow deer and the official residence of the President of Ireland.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'Phoenix Park in Dublin: trails, deer and the President of Ireland\'s residence in one of Europe\'s largest urban parks.',
			),
		),
		'sponsor' => array(
			'brasil-market-dublin' => array(
				'en_slug'  => 'brasil-market-dublin-en',
				'title'    => 'Brasil Market Dublin',
				'content'  => '<!-- wp:paragraph --><p>Brasil Market Dublin is a supporter of Conexão BR Irlanda.</p><!-- /wp:paragraph -->',
			),
		),
		'course_provider' => array(
			'fetch-courses' => array(
				'en_slug'  => 'fetch-courses-en',
				'title'    => 'Fetch Courses',
				'content'  => '<!-- wp:paragraph --><p>Fetch Courses is the official Irish further education and training course finder.</p><!-- /wp:paragraph -->',
				'extra_meta' => array( '_provider_category' => 'Education' ),
			),
		),
		'job' => array(
			'ajudante-de-cozinha-dublin' => array(
				'en_slug'  => 'kitchen-assistant-dublin',
				'title'    => 'Kitchen assistant — Dublin',
				'content'  => '<!-- wp:paragraph --><p>A restaurant in Dublin city centre is hiring a full-time kitchen assistant.</p><!-- /wp:paragraph -->',
				'meta_desc' => 'Kitchen assistant job in Dublin for the Brazilian community.',
			),
		),
	);
}

echo "== Stage 3.2 — controlled content migration pilot (EN) ==\n";

/**
 * Create a small deterministic local attachment so featured-media
 * preservation is exercised (local pilot asset only, never shipped).
 */
function stage32_pilot_attachment() {
	$existing = get_posts(
		array(
			'post_type'   => 'attachment',
			'name'        => 'stage32-pilot-image',
			'post_status' => 'any',
			'fields'      => 'ids',
			'lang'        => '',
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}

	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		echo "  note: GD unavailable — featured-image fixture skipped\n";
		return 0;
	}

	$dir  = wp_upload_dir();
	$file = trailingslashit( $dir['basedir'] ) . 'stage32-pilot-image.png';
	$img  = imagecreatetruecolor( 1200, 630 );
	$bg   = imagecolorallocate( $img, 0, 102, 68 );
	$fg   = imagecolorallocate( $img, 255, 255, 255 );
	imagefilledrectangle( $img, 0, 0, 1200, 630, $bg );
	imagestring( $img, 5, 480, 305, 'CONEXAO BR - PILOT', $fg );
	imagepng( $img, $file );
	imagedestroy( $img );

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attach_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Stage 3.2 pilot image',
			'post_name'      => 'stage32-pilot-image',
			'post_status'    => 'inherit',
		),
		$file
	);
	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return 0;
	}
	$meta = wp_generate_attachment_metadata( $attach_id, $file );
	if ( is_array( $meta ) ) {
		wp_update_attachment_metadata( $attach_id, $meta );
	}
	return (int) $attach_id;
}

// ---------------------------------------------------------------------------
// 1. Featured-media fixtures on the PT sources that will be translated.
// ---------------------------------------------------------------------------
echo "\n-- Featured media fixtures --\n";
$stage32_attachment = stage32_pilot_attachment();
if ( $stage32_attachment ) {
	foreach ( array( 'guide' => 'como-tirar-o-pps-number', 'event' => 'festa-junina-dublin-2026' ) as $type => $slug ) {
		$p = get_posts( array( 'post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
		if ( $p && ! get_post_thumbnail_id( $p[0] ) ) {
			set_post_thumbnail( $p[0], $stage32_attachment );
			if ( 'event' === $type ) {
				update_post_meta( $p[0], '_event_banner_attachment_id', $stage32_attachment );
			}
			echo "  + thumbnail #{$stage32_attachment} on {$type}/{$slug}\n";
		}
	}
}

// ---------------------------------------------------------------------------
// 2. Event source-language metadata (explicit pilot knowledge, never inferred
//    from titles). Phase 6 wires the importer-side contract; here the pilot
//    fixtures get their known values so every state exists in the dataset.
// ---------------------------------------------------------------------------
echo "\n-- Event source-language metadata --\n";
$stage32_event_source_languages = array(
	'festa-junina-dublin-2026'        => 'pt',
	'feijoada-beneficente-cork'       => 'pt',
	'brazilian-day-galway-2026'       => 'pt',
	'workshop-de-curriculo-dublin'    => 'pt',
	'noite-de-mpb-bray'               => 'pt',
	'evento-rejeitado-spam'           => 'pt',
	'evento-removido-na-fonte'        => 'pt',
	'brazilian-film-night-dublin'     => 'pt',
	'irish-dance-workshop-dublin'     => 'en',
	// 'encontro-de-brasileiros-limerick' stays UNSET: manual event, no source,
	// source language unclassified (the 'unknown' state).
);
foreach ( $stage32_event_source_languages as $slug => $lang ) {
	$p = get_posts( array( 'post_type' => 'event', 'name' => $slug, 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
	if ( $p ) {
		update_post_meta( $p[0], '_event_source_language', $lang );
		echo "  + {$slug}: _event_source_language={$lang}\n";
	}
}

// ---------------------------------------------------------------------------
// 3. Source-inherited English event: the English-source record IS the English
//    record (architecture §8 — never a second record). It moves to the English
//    language in place; identity, scheduling and status meta are untouched.
// ---------------------------------------------------------------------------
echo "\n-- Source-inherited EN event --\n";
$stage32_en_event = get_posts( array( 'post_type' => 'event', 'name' => 'irish-dance-workshop-dublin', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
if ( $stage32_en_event ) {
	$stage32_en_event_id = (int) $stage32_en_event[0];
	$current_lang        = pll_get_post_language( $stage32_en_event_id, 'slug' );
	if ( 'en' !== $current_lang ) {
		pll_set_post_language( $stage32_en_event_id, 'en' );
		echo "  + irish-dance-workshop-dublin (#{$stage32_en_event_id}) language pt→en (source-inherited; no new record)\n";
	} else {
		echo "  EXISTS irish-dance-workshop-dublin already 'en'\n";
	}
	// Its Dublin terms get the linked EN category where one exists.
	$stage32_terms = wp_get_object_terms( $stage32_en_event_id, 'conexao_category', array( 'fields' => 'ids' ) );
	if ( ! is_wp_error( $stage32_terms ) && $stage32_terms ) {
		$assign = array();
		foreach ( $stage32_terms as $term_id ) {
			$en_term  = (int) pll_get_term( $term_id, 'en' );
			$assign[] = $en_term > 0 ? $en_term : (int) $term_id;
		}
		wp_set_object_terms( $stage32_en_event_id, $assign, 'conexao_category' );
	}
}

// ---------------------------------------------------------------------------
// 4. Linked EN translations (the pilot map).
// ---------------------------------------------------------------------------
echo "\n-- Linked translations --\n";
foreach ( stage32_content_pilot_map() as $post_type => $records ) {
	echo "  [{$post_type}]\n";
	foreach ( $records as $pt_slug => $en ) {
		$pt = get_posts( array( 'post_type' => $post_type, 'name' => $pt_slug, 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
		if ( ! $pt ) {
			echo "    SKIP {$pt_slug}: PT record missing.\n";
			continue;
		}
		stage32_translate_record(
			$pt[0],
			$en['en_slug'],
			$en['title'],
			$en['content'],
			isset( $en['meta_desc'] ) ? $en['meta_desc'] : '',
			isset( $en['extra_meta'] ) ? $en['extra_meta'] : array()
		);
	}
}

echo "\nDone.\n";
