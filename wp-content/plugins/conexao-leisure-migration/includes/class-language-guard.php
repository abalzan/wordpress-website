<?php
/**
 * Lazer migration language guard (Stage 2).
 *
 * Imported Lazer records are Portuguese by default. This guard assigns the
 * site's default language to any Lazer post that has no language yet — it
 * NEVER reassigns an existing language, and it never touches identity data:
 * `_leisure_uuid`, image attachments, external-URL classification and the
 * ZIP matching logic (UUID → slug → title) stay exactly as they were, so a
 * translation can never become a duplicate UUID or a competing import target.
 *
 * No-op when Polylang is inactive.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language guard for imported Lazer records.
 */
final class Conexao_Leisure_Migration_Language_Guard {

	/**
	 * Wire the guard on every creation/update route (admin ZIP import,
	 * WP-CLI import, REST tooling).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post_leisure', array( __CLASS__, 'assign_language' ), 20, 1 );
	}

	/**
	 * Language imported Lazer records belong to.
	 *
	 * @return string Language slug, or '' when Polylang is inactive.
	 */
	public static function import_language(): string {
		if ( ! function_exists( 'pll_default_language' ) ) {
			return '';
		}

		$slug = pll_default_language( 'slug' );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Assign the import language to a Lazer record that has none yet.
	 *
	 * @param int $post_id Lazer post ID.
	 * @return void
	 */
	public static function assign_language( $post_id ) {
		$language = self::import_language();

		if ( '' === $language || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$current = pll_get_post_language( $post_id, 'slug' );

		if ( is_string( $current ) && '' !== $current ) {
			return;
		}

		pll_set_post_language( (int) $post_id, $language );
	}
}
