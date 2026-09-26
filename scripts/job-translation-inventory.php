<?php
/**
 * job-translation-inventory.php — deterministic EN Job translation inventory.
 *
 * Purpose: emit the machine-readable inventory used by the EN Jobs audit and by
 * the "Portuguese originals unchanged" verification. Nothing is hard-coded:
 * every `job` record is discovered through WordPress itself (all statuses are
 * reported), together with the Jobs landing page state, the job meta actually
 * stored, the taxonomy terms actually used and the Polylang relationship state.
 *
 * Safety: read-only. It creates, updates, deletes and publishes nothing.
 *
 * Scope:
 *   Reads job records and reports translation completeness.
 *   Does not create, update, delete or publish content.
 *
 * Usage (local Docker, from the project root):
 *   docker compose exec -T wordpress wp eval-file scripts/job-translation-inventory.php --allow-root
 *   docker compose exec -T wordpress wp eval-file scripts/job-translation-inventory.php --allow-root -- --out=/path/to/job-inventory.json
 *   docker compose exec -T wordpress wp eval-file scripts/job-translation-inventory.php --allow-root -- --help
 *
 * LOCAL / STAGING ONLY.
 *
 * @package Conexao_Job_Translation
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'job-translation-inventory.php',
		'purpose'            => 'Emit the machine-readable EN Job translation inventory.',
		'scope'              => "Reads job records and reports translation completeness.\n"
			. 'Does not create, update, delete or publish content.',
		'safety'             => 'read-only; this script never writes to WordPress.',
		'safety_level'       => 'read-only',
		'target_description' => 'The local/staging WordPress this script is executed against.',
		'modes_description'  => 'Read-only: there is no apply mode.',
		'arguments'          => array( '--out=PATH  Write the JSON inventory to PATH instead of stdout.' ),
		'read_only'          => true,
		'json'               => true,
		'extra_flags'        => array( '--out' => 'out' ),
		'environment'        => array(
			'CONEXAO_SITE_URL  Optional target override; defaults to the loaded install.',
		),
	)
);

$out_file = isset( $ctx['extra']['out'] ) ? (string) $ctx['extra']['out'] : '';

/**
 * All post meta of a record as a flat key => value map (read-only inventory).
 *
 * @param int $post_id Post ID.
 * @return array
 */
function conexao_job_inventory_all_meta( int $post_id ): array {
	$meta = get_post_meta( $post_id );
	$flat = array();
	foreach ( (array) $meta as $key => $values ) {
		$flat[ (string) $key ] = is_array( $values ) && 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', (array) $values );
	}
	ksort( $flat );
	return $flat;
}

/**
 * Describe one job record for the inventory.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function conexao_job_inventory_record( int $post_id ): array {
	$post = get_post( $post_id );

	$taxonomies = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
		$terms                   = wp_get_post_terms( $post_id, $taxonomy );
		$taxonomies[ $taxonomy ] = is_wp_error( $terms ) ? array() : array_map(
			static function ( $term ) {
				return array(
					'id'   => (int) $term->term_id,
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
				);
			},
			$terms
		);
	}

	$language    = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '';
	$translation = ( function_exists( 'pll_get_post' ) && '' !== $language )
		? (int) pll_get_post( $post_id, 'pt' === $language ? 'en' : 'pt' )
		: 0;

	return array(
		'id'           => $post_id,
		'slug'         => (string) $post->post_name,
		'title'        => (string) $post->post_title,
		'status'       => (string) $post->post_status,
		'date'         => (string) $post->post_date,
		'modified'     => (string) $post->post_modified,
		'author'       => (int) $post->post_author,
		'menu_order'   => (int) $post->menu_order,
		'permalink'    => (string) get_permalink( $post_id ),
		'language'     => $language,
		'translation'  => $translation,
		'excerpt'      => (string) $post->post_excerpt,
		'content'      => (string) $post->post_content,
		'content_hash' => md5( (string) $post->post_content ),
		'thumbnail'    => (int) get_post_thumbnail_id( $post_id ),
		'template'     => (string) get_page_template_slug( $post_id ),
		'taxonomies'   => $taxonomies,
		'meta'         => conexao_job_inventory_all_meta( $post_id ),
		'meta_desc'    => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
	);
}

$all_ids = get_posts(
	array(
		'post_type'      => 'job',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

$query_base = array(
	'post_type'      => 'job',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'orderby'        => 'date',
	'order'          => 'DESC',
);

$pt_ids = get_posts( array_merge( $query_base, array( 'lang' => 'pt' ) ) );
$en_ids = get_posts( array_merge( $query_base, array( 'lang' => 'en' ) ) );

$by_status = array();
foreach ( $all_ids as $id ) {
	$status               = (string) get_post_status( $id );
	$by_status[ $status ] = isset( $by_status[ $status ] ) ? $by_status[ $status ] + 1 : 1;
}

// The Jobs landing page state (PT + EN).
$pt_page    = get_page_by_path( 'empregos', OBJECT, 'page' );
$en_page_id = ( $pt_page && function_exists( 'pll_get_post' ) ) ? (int) pll_get_post( (int) $pt_page->ID, 'en' ) : 0;

$job_type_obj = get_post_type_object( 'job' );

$inventory = array(
	'generated_by'  => 'scripts/job-translation-inventory.php',
	'site'          => home_url( '/' ),
	'polylang'      => function_exists( 'pll_languages_list' ),
	'job_post_type' => array(
		'has_archive'    => (bool) ( $job_type_obj->has_archive ?? null ),
		'rewrite_slug'   => (string) ( $job_type_obj->rewrite['slug'] ?? '' ),
		'public'         => (bool) ( $job_type_obj->public ?? null ),
		'b2_eligible'    => function_exists( 'conexao_is_b2_post_type' ) ? conexao_is_b2_post_type( 'job' ) : null,
		'pll_translated' => function_exists( 'pll_is_translated_post_type' ) ? pll_is_translated_post_type( 'job' ) : null,
	),
	'jobs_page'     => array(
		'pt_id'    => $pt_page ? (int) $pt_page->ID : 0,
		'pt_url'   => $pt_page ? (string) get_permalink( (int) $pt_page->ID ) : '',
		'template' => $pt_page ? (string) get_page_template_slug( (int) $pt_page->ID ) : '',
		'en_id'    => $en_page_id,
		'en_url'   => $en_page_id > 0 ? (string) get_permalink( $en_page_id ) : '',
		'linked'   => $pt_page && $en_page_id > 0 && (int) pll_get_post( $en_page_id, 'pt' ) === (int) $pt_page->ID,
	),
	'counts'        => array(
		'total_records_any_status' => count( $all_ids ),
		'by_status'                => $by_status,
		'published_pt'             => count( $pt_ids ),
		'published_en'             => count( $en_ids ),
	),
	'pt'            => array_map( 'conexao_job_inventory_record', $pt_ids ),
	'en'            => array_map( 'conexao_job_inventory_record', $en_ids ),
	'all_statuses'  => array_map(
		static function ( $id ) {
			return array(
				'id'     => (int) $id,
				'slug'   => (string) get_post_field( 'post_name', $id ),
				'status' => (string) get_post_status( $id ),
			);
		},
		$all_ids
	),
	// Stage H: the completeness audit is the shared engine's numeric gate.
	'gate'          => (
		class_exists( 'Conexao_Translation_Rollout_Engine' ) && function_exists( 'conexao_job_translation_engine_config' )
			? Conexao_Translation_Rollout_Engine::run(
				conexao_job_translation_engine_config(),
				conexao_job_translation_engine_adapter(),
				array( 'dry_run' => true )
			)['gate']
			: null
	),
);

$json = wp_json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

$summary = array(
	'total_records' => (int) $inventory['counts']['total_records_any_status'],
	'published_pt'  => (int) $inventory['counts']['published_pt'],
	'published_en'  => (int) $inventory['counts']['published_en'],
	'errors'        => 0,
);

if ( '' !== $out_file ) {
	$written = file_put_contents( $out_file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local tooling.
	echo "wrote {$written} bytes to {$out_file}\n";
	conexao_script_summary( $ctx, $summary );
	return;
}

// The inventory document IS the machine-readable output, so it goes to stdout
// verbatim rather than being wrapped in the summary envelope.
echo $json, "\n";
fwrite( STDERR, conexao_script_summary_line( $summary ) );
