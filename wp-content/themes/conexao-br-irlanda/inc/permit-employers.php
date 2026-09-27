<?php
/**
 * Empregos — "Empresas com histórico de Employment Permits" module.
 *
 * Backs the employment-permit employers section rendered by
 * page-empregos.php AFTER the recruitment-agencies section. Instagram
 * remains the primary current-vacancy CTA and the agencies remain the
 * first directory; this section is intentionally the quieter, third
 * pathway on the page.
 *
 * Data source: the `permit_employer` custom post type (registered by the
 * conexao-data-model plugin, managed in wp-admin under Empregos →
 * "Empregadores — Employment Permits" via the Conexão Admin UX).
 *
 * Editorial semantics (do not weaken):
 * - `verified` permit status means VERIFIED HISTORICAL permit evidence in
 *   the official DETE "Permits issued to companies" statistics. It NEVER
 *   means "currently sponsoring" and the frontend copy must never imply
 *   it does.
 * - `unverified` renders as a normal employer entry with NO permit
 *   indicator (e.g. Kepak until its exact DETE legal entity is matched).
 * - `exception` carries the same historical evidence plus the employer's
 *   current-position statement (e.g. Nua Healthcare) and renders as a
 *   normal permit-history card in the unified directory.
 * - WRC/DETE reference numbers stay in the admin data only. Evidence
 *   sources stay in the admin data only — the section shows ONE shared
 *   source note plus a single link to the official guidance.
 * - No salary thresholds, quotas, ratings, sponsorship wording, search,
 *   filters or maps.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * The employment-permit employers shown on /empregos/.
 *
 * Only published records are returned, ordered by name so the directory
 * is stable regardless of when each record was created or edited.
 *
 * @return WP_Post[] Published employer records.
 */
function conexao_permit_employers() {
	$query = new WP_Query(
		array(
			'post_type'      => 'permit_employer',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$employers = $query->posts;

	usort(
		$employers,
		static function ( $a, $b ) {
			return strcasecmp( $a->post_title, $b->post_title );
		}
	);

	return $employers;
}

/**
 * A single employer meta value, normalized to string.
 *
 * @param WP_Post $employer Employer post.
 * @param string  $key      Meta key (with or without leading underscore).
 * @return string
 */
function conexao_permit_employer_meta( $employer, $key ) {
	if ( ! $employer instanceof WP_Post ) {
		return '';
	}

	$value = get_post_meta( $employer->ID, $key, true );

	if ( is_array( $value ) || is_object( $value ) ) {
		return '';
	}

	return (string) $value;
}

/**
 * Normalized permit-status key for an employer record.
 *
 * Unknown/empty values degrade to 'unverified' (a plain employer entry
 * with no permit indicator) so bad data can never fabricate a badge.
 *
 * @param WP_Post $employer Employer post.
 * @return string One of: 'verified', 'unverified', 'exception'.
 */
function conexao_permit_employer_status( $employer ) {
	$status = strtolower( trim( conexao_permit_employer_meta( $employer, '_employer_permit_status' ) ) );

	if ( in_array( $status, array( 'verified', 'unverified', 'exception' ), true ) ) {
		return $status;
	}

	return 'unverified';
}

/**
 * Relevant role types for an employer, as a clean list of strings.
 *
 * Stored as a comma-separated free-text field (roles are employer-specific
 * and deliberately NOT standardized like agency job types).
 *
 * @param WP_Post $employer Employer post.
 * @return string[] Trimmed, non-empty role labels.
 */
function conexao_permit_employer_roles( $employer ) {
	$raw = conexao_permit_employer_meta( $employer, '_employer_roles' );

	if ( '' === $raw ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'trim', explode( ',', $raw ) ),
			static function ( $role ) {
				return '' !== $role;
			}
		)
	);
}

// ============================================================================
// Language-aware display of the employer's editorial descriptors
//
// `_employer_sector`, `_employer_roles` and the generic part of
// `_employer_location` are authored Portuguese free text. They are USER-FACING
// LABELS, so they must render in the requested language on `/en/jobs/` while
// the stored values — and therefore Portuguese — stay byte-identical.
//
// Model identical to Conexao_Data_Model_Agency: a CANONICAL KEY registry whose
// keys are the language-neutral identity and whose LABELS run through gettext.
// The stored Portuguese text is normalized to a key; an unknown value is
// returned exactly as stored, so a new/edited record can never be dropped,
// mangled or silently emptied. No duplicate records, no string replacement in
// the template, no frontend translation layer.
// ============================================================================

/**
 * Canonical Employment-Permit employer sector vocabulary: stored text => key.
 *
 * @return array<string,string>
 */
function conexao_permit_employer_sector_keys() {
	return array(
		'Saúde e Cuidados'              => 'health_care',
		'Saúde pública (HSE)'           => 'public_health',
		'Cuidados Domiciliários'       => 'home_care',
		'Alimentos — Processamento de Carne' => 'food_meat_processing',
		'Agroalimentar — Cogumelos'     => 'food_mushrooms',
	);
}

/**
 * Canonical Employment-Permit employer role vocabulary: stored text => key.
 *
 * @return array<string,string>
 */
function conexao_permit_employer_role_keys() {
	return array(
		'Enfermeiros'                  => 'nurses',
		'Profissionais de saúde'       => 'healthcare_professionals',
		'Healthcare Assistants'        => 'healthcare_assistants',
		'Equipe de apoio'              => 'support_staff',
		'Operadores de produção'       => 'production_operators',
		'Processamento de alimentos'   => 'food_processing',
		'Colheita e processamento'     => 'harvesting_processing',
		'Cuidadores domiciliários (Home Carers)' => 'home_carers',
	);
}

/**
 * Normalize an accented stored descriptor to a lowercase lookup key.
 *
 * @param string $value Stored descriptor.
 * @return string
 */
function conexao_permit_employer_label_key( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';

	if ( function_exists( 'remove_accents' ) ) {
		$value = remove_accents( $value );
	}

	$value = strtolower( $value );
	$value = str_replace( array( '—', '–', '-' ), ' ', $value );
	$value = preg_replace( '/\s+/', ' ', $value );

	return trim( (string) $value );
}

/**
 * Language-aware display label for a canonical descriptor.
 *
 * @param array    $registry Stored text => key map.
 * @param string   $stored   Stored descriptor text.
 * @param string[] $labels   key => gettext label map.
 * @return string
 */
function conexao_permit_employer_label_display( array $registry, array $labels, $stored ) {
	$stored = is_string( $stored ) ? trim( $stored ) : '';

	if ( '' === $stored ) {
		return '';
	}

	$normalized_registry = array();
	foreach ( $registry as $text => $key ) {
		$normalized_registry[ conexao_permit_employer_label_key( $text ) ] = $key;
	}

	$key = isset( $normalized_registry[ conexao_permit_employer_label_key( $stored ) ] )
		? $normalized_registry[ conexao_permit_employer_label_key( $stored ) ]
		: '';

	// Unknown value: return exactly what is stored (never invented, never lost).
	return isset( $key, $labels[ $key ] ) ? $labels[ $key ] : $stored;
}

/**
 * Language-aware sector display for one employer record.
 *
 * @param WP_Post $employer Employer post.
 * @return string
 */
function conexao_permit_employer_sector_display( $employer ) {
	return conexao_permit_employer_label_display(
		conexao_permit_employer_sector_keys(),
		array(
			'health_care'            => __( 'Saúde e Cuidados', 'conexao-br-irlanda' ),
			'public_health'          => __( 'Saúde pública (HSE)', 'conexao-br-irlanda' ),
			'home_care'              => __( 'Cuidados Domiciliários', 'conexao-br-irlanda' ),
			'food_meat_processing'   => __( 'Alimentos — Processamento de Carne', 'conexao-br-irlanda' ),
			'food_mushrooms'         => __( 'Agroalimentar — Cogumelos', 'conexao-br-irlanda' ),
		),
		conexao_permit_employer_meta( $employer, '_employer_sector' )
	);
}

/**
 * Language-aware role list for one employer record.
 *
 * Each role is resolved independently so a single unknown role never discards
 * the known ones.
 *
 * @param WP_Post $employer Employer post.
 * @return string[]
 */
function conexao_permit_employer_roles_display( $employer ) {
	$registry = conexao_permit_employer_role_keys();
	$labels   = array(
		'nurses'                  => __( 'Enfermeiros', 'conexao-br-irlanda' ),
		'healthcare_professionals' => __( 'Profissionais de saúde', 'conexao-br-irlanda' ),
		'healthcare_assistants'   => __( 'Healthcare Assistants', 'conexao-br-irlanda' ),
		'support_staff'           => __( 'Equipe de apoio', 'conexao-br-irlanda' ),
		'production_operators'    => __( 'Operadores de produção', 'conexao-br-irlanda' ),
		'food_processing'         => __( 'Processamento de alimentos', 'conexao-br-irlanda' ),
		'harvesting_processing'   => __( 'Colheita e processamento', 'conexao-br-irlanda' ),
		'home_carers'             => __( 'Cuidadores domiciliários (Home Carers)', 'conexao-br-irlanda' ),
	);

	$roles = array();
	foreach ( conexao_permit_employer_roles( $employer ) as $role ) {
		$roles[] = conexao_permit_employer_label_display( $registry, $labels, $role );
	}

	return $roles;
}
