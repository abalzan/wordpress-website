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
 * - `exception` renders in the "Importante" block below the cards with
 *   the employer's current-position statement (e.g. Nua Healthcare).
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
