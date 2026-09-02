<?php
/**
 * Empregos — "Agências de recrutamento" recruitment-agencies module.
 *
 * Backs the recruitment-agency section rendered by page-empregos.php AFTER the
 * existing "Onde procurar emprego" job-search resources. The section is a
 * second useful pathway for the audience — Instagram remains the primary
 * current-vacancy CTA and the job boards remain the secondary resources.
 *
 * Data source: the `recruitment_agency` custom post type (registered by the
 * conexao-data-model plugin, managed in wp-admin under
 * Empregos → Agências de Recrutamento via the Conexão Admin UX). Each record
 * is a compact card with the agency name, main job types (canonical labels
 * via Conexao_Data_Model_Agency — legacy free-text values pass through
 * unchanged), temp/permanent flags, location, phone (tel: link) and an
 * external website button.
 *
 * Agencies are ordered by the `_agency_order` meta (lower first) so the
 * agencies most relevant to a general / entry-level audience come first.
 * Only published records render; archived/draft agencies stay hidden.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * The recruitment agencies shown on /empregos/.
 *
 * Only published records are returned. Ordering uses the `_agency_order`
 * meta (lower first, ties by newest first), but records without an explicit
 * order are still included — sorted last — so an agency saved without an
 * "Ordem de exibição" value is never silently dropped from the directory.
 *
 * @return WP_Post[] Published agency records.
 */
function conexao_recruitment_agencies() {
	$query = new WP_Query(
		array(
			'post_type'      => 'recruitment_agency',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	$agencies = $query->posts;

	usort(
		$agencies,
		static function ( $a, $b ) {
			$order_a = get_post_meta( $a->ID, '_agency_order', true );
			$order_b = get_post_meta( $b->ID, '_agency_order', true );
			$order_a = ( '' === $order_a || null === $order_a ) ? PHP_INT_MAX : (int) $order_a;
			$order_b = ( '' === $order_b || null === $order_b ) ? PHP_INT_MAX : (int) $order_b;

			if ( $order_a === $order_b ) {
				// Same order (or both unordered): newest first, matching the
				// previous SQL ordering behavior.
				return strcmp( (string) $b->post_date, (string) $a->post_date );
			}

			return $order_a <=> $order_b;
		}
	);

	return $agencies;
}

/**
 * The tel: URI for a stored phone number ('' when none).
 *
 * Strips everything but the leading + and digits so the link works on any
 * device. Falls back to the raw value if nothing parseable remains.
 *
 * @param string $phone Display phone, e.g. "+353 1 234 5678".
 * @return string Escaped tel: URI or '' when empty.
 */
function conexao_recruitment_agency_tel_uri( $phone ) {
	$phone = is_string( $phone ) ? trim( $phone ) : '';

	if ( '' === $phone ) {
		return '';
	}

	$digits = preg_replace( '/[^\d+]/', '', $phone );
	if ( '' === $digits ) {
		$digits = $phone;
	}

	return esc_url( 'tel:' . $digits );
}

function conexao_recruitment_agency_job_type_labels( $raw ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';

	if ( '' === $raw ) {
		return array();
	}

	// Canonical labels come from the data-model plugin so the admin editor
	// and the frontend always agree (see Conexao_Data_Model_Agency). If the
	// plugin is unavailable, the stored text is shown as-is.
	if ( class_exists( 'Conexao_Data_Model_Agency' ) ) {
		return Conexao_Data_Model_Agency::job_type_labels( $raw );
	}

	return array( $raw );
}

/**
 * A single agency meta value, normalized to string.
 *
 * @param WP_Post $agency Agency post.
 * @param string  $key    Meta key (with or without leading underscore).
 * @return string
 */
function conexao_recruitment_agency_meta( $agency, $key ) {
	if ( ! $agency instanceof WP_Post ) {
		return '';
	}

	$value = get_post_meta( $agency->ID, $key, true );

	if ( is_array( $value ) || is_object( $value ) ) {
		return '';
	}

	return (string) $value;
}