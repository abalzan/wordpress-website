<?php
/**
 * Lazer expansion — Stage C: post-import local audit.
 *
 * Audits the 12 approved NEW records + the Knocknarea improvement in the
 * LOCAL WordPress database (read-only; exits non-zero when any check fails):
 * identity/slug/county/town, category, attribute terms, official + DI links,
 * external-vs-internal classification, deterministic map URL, practical
 * verification metadata, no imported images, and a normalized-title
 * duplicate scan against all leisure posts.
 *
 * Run via:
 *   wp eval-file scripts/verify-lazer-stage-c.php --allow-root
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$expected = array(
	'chester-beatty' => array(
		'title' => 'Chester Beatty', 'county' => 'Dublin', 'town' => 'Dublin City',
		'category' => 'Museus',
		'attrs' => array( 'Interior + exterior', 'Acessível', 'Acesso de transporte público', 'Gratuito' ),
		'official' => 'https://chesterbeatty.ie/',
		'discover' => 'https://www.discoverireland.ie/dublin/chester-beatty',
		'free_meta' => 'Gratuito',
	),
	'forty-foot' => array(
		'title' => 'The Forty Foot', 'county' => 'Dublin', 'town' => 'Sandycove',
		'category' => 'Praias',
		'attrs' => array( 'Exterior', 'Estacionamento', 'Acesso de transporte público', 'Gratuito' ),
		'official' => '', 'discover' => '', 'free_meta' => 'Gratuito',
	),
	'derrigimlagh' => array(
		'title' => 'Derrigimlagh', 'county' => 'Galway', 'town' => 'Ballyconneely',
		'category' => 'História',
		'attrs' => array( 'Exterior', 'Bicicleta', 'Gratuito' ),
		'official' => '',
		'discover' => 'https://www.discoverireland.ie/galway/derrigimlagh',
		'free_meta' => 'Gratuito',
	),
	'joyce-tower-museum' => array(
		'title' => 'James Joyce Tower Museum', 'county' => 'Dublin', 'town' => 'Sandycove',
		'category' => 'Museus',
		'attrs' => array( 'Interior', 'Acesso de transporte público', 'Gratuito' ),
		'official' => 'https://joycetower.ie/',
		'discover' => 'https://www.discoverireland.ie/dublin/james-joyce-museum',
		'free_meta' => 'Gratuito',
	),
	'the-model' => array(
		'title' => 'The Model', 'county' => 'Sligo', 'town' => 'Sligo',
		'category' => 'Museus',
		'attrs' => array( 'Interior', 'Famílias', 'Acessível', 'Gratuito' ),
		'official' => 'https://www.themodel.ie/',
		'discover' => 'https://www.discoverireland.ie/sligo/the-model-home-of-the-niland-collection',
		'free_meta' => 'Gratuito',
	),
	'doagh-famine-village' => array(
		'title' => 'Doagh Famine Village', 'county' => 'Donegal', 'town' => 'Ballyliffin',
		'category' => 'Patrimônio',
		'attrs' => array( 'Interior + exterior', 'Famílias', 'Pet friendly', 'Estacionamento', 'Pago' ),
		'official' => 'https://www.doaghfaminevillage.com/',
		'discover' => 'https://www.discoverireland.ie/donegal/doagh-famine-village',
		'free_meta' => 'Pago',
	),
	'dursey-island' => array(
		'title' => 'Dursey Island', 'county' => 'Cork', 'town' => 'Dursey / Allihies (Beara)',
		'category' => 'Ilhas',
		'attrs' => array( 'Exterior', 'Famílias', 'Gratuito em determinadas condições' ),
		'official' => '',
		'discover' => 'https://www.discoverireland.ie/cork/dursey-island',
		'free_meta' => 'Gratuito em determinadas condições',
	),
	'old-head-of-kinsale' => array(
		'title' => 'Old Head of Kinsale', 'county' => 'Cork', 'town' => 'Kinsale',
		'category' => 'Natureza',
		'attrs' => array( 'Exterior', 'Estacionamento', 'Gratuito em determinadas condições' ),
		'official' => '',
		'discover' => 'https://www.discoverireland.ie/cork/old-head-signal-tower-signature-discovery-point',
		'free_meta' => 'Gratuito em determinadas condições',
	),
	'lough-muckno-leisure-park' => array(
		'title' => 'Lough Muckno Leisure Park', 'county' => 'Monaghan', 'town' => 'Castleblayney',
		'category' => 'Natureza',
		'attrs' => array( 'Exterior', 'Famílias', 'Pet friendly', 'Estacionamento', 'Gratuito' ),
		'official' => '',
		'discover' => 'https://www.discoverireland.ie/monaghan/lough-muckno-leisure-park',
		'free_meta' => 'Gratuito',
	),
	'jfk-arboretum' => array(
		'title' => 'The John F. Kennedy Arboretum', 'county' => 'Wexford', 'town' => 'New Ross',
		'category' => 'Jardins',
		'attrs' => array( 'Exterior', 'Famílias', 'Pet friendly', 'Estacionamento', 'Gratuito em determinadas condições' ),
		'official' => '',
		'discover' => 'https://www.discoverireland.ie/wexford/the-john-f-kennedy-arboretum',
		'free_meta' => 'Gratuito em determinadas condições',
	),
	'cavan-cathedral' => array(
		'title' => 'Cavan Cathedral', 'county' => 'Cavan', 'town' => 'Cavan',
		'category' => 'Patrimônio',
		'attrs' => array( 'Interior', 'Gratuito' ),
		'official' => '', 'discover' => '', 'free_meta' => 'Gratuito',
	),
	'national-design-craft-gallery' => array(
		'title' => 'National Design & Craft Gallery', 'county' => 'Kilkenny', 'town' => 'Kilkenny',
		'category' => 'Cultura',
		'attrs' => array( 'Interior', 'Gratuito' ),
		'official' => 'https://www.ndcg.ie/', 'discover' => '',
		'free_meta' => 'Gratuito',
	),
);

// CONTINUED: audit loop + duplicate scan + Knocknarea checks.

$failures = array();
$checks   = 0;

function stagec_check( &$failures, &$checks, $label, $condition, $detail = '' ) {
	$checks++;
	if ( ! $condition ) {
		$failures[] = "{$label}" . ( $detail ? " ({$detail})" : '' );
		echo "  FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
	} else {
		echo "  ok    {$label}\n";
	}
}

echo "=== Stage C post-import audit (12 NEW records) ===\n";

foreach ( $expected as $slug => $exp ) {
	echo "\n-- {$slug}\n";
	$posts = get_posts( array( 'post_type' => 'leisure', 'name' => $slug, 'numberposts' => 1, 'post_status' => 'any' ) );
	stagec_check( $failures, $checks, 'post exists', (bool) $posts );
	if ( ! $posts ) {
		continue;
	}
	$p = $posts[0];

	$ids = get_posts( array( 'post_type' => 'leisure', 'name' => $slug, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) );
	stagec_check( $failures, $checks, 'unique slug', count( $ids ) === 1 && (int) $ids[0] === (int) $p->ID );
	stagec_check( $failures, $checks, 'title', $p->post_title === $exp['title'], "got '{$p->post_title}'" );
	stagec_check( $failures, $checks, 'published', 'publish' === $p->post_status, $p->post_status );

	$county = wp_get_post_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
	$cats   = wp_get_post_terms( $p->ID, 'conexao_category', array( 'fields' => 'names' ) );
	$attrs  = wp_get_post_terms( $p->ID, 'conexao_leisure_attribute', array( 'fields' => 'names' ) );
	stagec_check( $failures, $checks, 'county', in_array( $exp['county'], (array) $county, true ), implode( ',', (array) $county ) );
	stagec_check( $failures, $checks, 'category', in_array( $exp['category'], (array) $cats, true ) && count( $cats ) === 1, implode( ',', (array) $cats ) );

	$missing = array_diff( $exp['attrs'], (array) $attrs );
	$extra   = array_diff( (array) $attrs, $exp['attrs'] );
	stagec_check( $failures, $checks, 'attributes', empty( $missing ) && empty( $extra ), 'missing: [' . implode( ',', $missing ) . '] extra: [' . implode( ',', $extra ) . ']' );

	stagec_check( $failures, $checks, 'official website meta', (string) get_post_meta( $p->ID, '_leisure_official_website', true ) === $exp['official'], (string) get_post_meta( $p->ID, '_leisure_official_website', true ) );
	stagec_check( $failures, $checks, 'Discover Ireland meta', (string) get_post_meta( $p->ID, '_leisure_discover_ireland', true ) === $exp['discover'], (string) get_post_meta( $p->ID, '_leisure_discover_ireland', true ) );
	stagec_check( $failures, $checks, '_leisure_free label', (string) get_post_meta( $p->ID, '_leisure_free', true ) === $exp['free_meta'], (string) get_post_meta( $p->ID, '_leisure_free', true ) );
	stagec_check( $failures, $checks, 'town meta', (string) get_post_meta( $p->ID, '_leisure_town', true ) === $exp['town'], (string) get_post_meta( $p->ID, '_leisure_town', true ) );

	$external = function_exists( 'conexao_leisure_external_url' ) ? conexao_leisure_external_url( $p->ID ) : '';
	if ( $exp['official'] || $exp['discover'] ) {
		stagec_check( $failures, $checks, 'external classification', '' !== $external && ! get_post_meta( $p->ID, '_leisure_internal_page', true ), "external='{$external}'" );
		stagec_check( $failures, $checks, 'external target = official/DI', in_array( $external, array( $exp['official'], $exp['discover'] ), true ), "external='{$external}'" );
	} else {
		stagec_check( $failures, $checks, 'internal classification (no external URL)', '' === $external && ! get_post_meta( $p->ID, '_leisure_internal_page', true ), "external='{$external}'" );
	}

	$map_url = function_exists( 'conexao_leisure_map_url' ) ? conexao_leisure_map_url( $p->ID ) : '';
	stagec_check( $failures, $checks, 'deterministic map URL', (bool) $map_url && false !== strpos( $map_url, 'maps/search/?api=1&query=' ), $map_url );
	stagec_check( $failures, $checks, 'no stored map URL', '' === (string) get_post_meta( $p->ID, '_leisure_map_url', true ) );

	stagec_check( $failures, $checks, 'practical notes present', '' !== (string) get_post_meta( $p->ID, '_leisure_practical_notes', true ) );
	$psrc = (string) get_post_meta( $p->ID, '_leisure_practical_source_url', true );
	$pchk = (string) get_post_meta( $p->ID, '_leisure_practical_last_checked', true );
	stagec_check( $failures, $checks, 'practical verification date = 2026-10-09', '2026-10-09' === $pchk, $pchk );
	if ( $psrc ) {
		stagec_check( $failures, $checks, 'practical source URL is a verified URL', in_array( $psrc, array( $exp['official'], $exp['discover'], 'https://www.ireland.com/en-gb/destinations/county/cork/dursey-island/' ), true ), $psrc );
	}

	// Image decision: none imported for NEW records.
	$img_id = (int) get_post_meta( $p->ID, '_leisure_image_attachment_id', true );
	stagec_check( $failures, $checks, 'no imported image', 0 === $img_id, "attachment={$img_id}" );

	// Description policy sanity: original PT excerpt, compact.
	$excerpt = (string) $p->post_excerpt;
	stagec_check( $failures, $checks, 'original excerpt present', '' !== $excerpt && strlen( $excerpt ) <= 300, strlen( $excerpt ) . ' chars' );
}

// --- Duplicate scan (all published leisure posts, normalized titles) ---
echo "\n=== Duplicate scan ===\n";
function stagec_norm( $title ) {
	$t = mb_strtolower( $title, 'UTF-8' );
	$t = str_replace( array( '&', '–', '—', '/' ), ' and ', $t );
	$t = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $t );
	$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );
	$tokens = array_filter( explode( ' ', trim( preg_replace( '/\s+/', ' ', $t ) ) ), function ( $tok ) { return strlen( $tok ) > 1; } );
	return implode( ' ', $tokens );
}
$all = get_posts( array( 'post_type' => 'leisure', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
$norm_index = array();
foreach ( $all as $a ) {
	$norm_index[ $a->ID ] = stagec_norm( $a->post_title );
}
$dupes = 0;
$seen  = array();
foreach ( $norm_index as $id => $norm ) {
	if ( isset( $seen[ $norm ] ) ) {
		$dupes++;
		echo "  DUP: [{$id}] '{$norm}' duplicates [{$seen[$norm]}]\n";
	} else {
		$seen[ $norm ] = $id;
	}
}
stagec_check( $failures, $checks, 'no duplicate normalized titles across all leisure posts', 0 === $dupes, "{$dupes} found (total posts: " . count( $all ) . ')' );

// --- Knocknarea improvement check ---
echo "\n=== Knocknarea improvement ===\n";
$kn = get_posts( array( 'post_type' => 'leisure', 'name' => 'queen-maeves-trail-knocknarea', 'numberposts' => 1, 'post_status' => 'any' ) );
stagec_check( $failures, $checks, 'Knocknarea record exists', (bool) $kn );
if ( $kn ) {
	$kn = $kn[0];
	stagec_check( $failures, $checks, 'town = Strandhill', 'Strandhill' === get_post_meta( $kn->ID, '_leisure_town', true ), get_post_meta( $kn->ID, '_leisure_town', true ) );
	stagec_check( $failures, $checks, 'DI URL = queen-maeve-trail', 'https://www.discoverireland.ie/sligo/queen-maeve-trail' === get_post_meta( $kn->ID, '_leisure_discover_ireland', true ), get_post_meta( $kn->ID, '_leisure_discover_ireland', true ) );
	stagec_check( $failures, $checks, 'internal page preserved', '' === conexao_leisure_external_url( $kn->ID ), 'external=' . conexao_leisure_external_url( $kn->ID ) );
	stagec_check( $failures, $checks, 'Estacionamento attribute', has_term( 'Estacionamento', 'conexao_leisure_attribute', $kn->ID ) );
	stagec_check( $failures, $checks, 'deterministic map (Strandhill)', false !== strpos( (string) conexao_leisure_map_url( $kn->ID ), 'Strandhill' ), conexao_leisure_map_url( $kn->ID ) );
}

echo "\n=== Summary ===\n";
echo "Checks: {$checks} | Failures: " . count( $failures ) . "\n";
foreach ( $failures as $f ) {
	echo "  FAIL: {$f}\n";
}
echo empty( $failures ) ? "\nSTAGE C LOCAL AUDIT PASSED\n" : "\nSTAGE C LOCAL AUDIT FAILED\n";
exit( empty( $failures ) ? 0 : 1 );

