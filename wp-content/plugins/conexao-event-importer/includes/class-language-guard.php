<?php
/**
 * Event importer language guard (Stage 2).
 *
 * The event importer owns Portuguese-source records. Polylang makes a
 * translation a *separate WordPress post* that carries a copy of the
 * language-neutral identity meta (`_event_source`, `_event_source_id`,
 * `_event_export_uuid`, `_event_url`, scheduling fields, `_event_status`).
 * Two invariants follow, and this class enforces both:
 *
 *  1. **One production source identity per import target.** A translation must
 *     never become a competing import target: if deduplication resolves to a
 *     record in another language, the importer refuses to write (it reports a
 *     skip) instead of overwriting the translation with source-language
 *     content and instead of creating a second copy of the same identity.
 *  2. **Imported events are always language-assigned.** Every imported event
 *     (CLI, admin, ZIP/JSON transfer) gets the import language — the site's
 *     default language (`pt_BR`) — so no event can exist outside the language
 *     model and leak into both language contexts.
 *
 * Source-language detection for English-native sources is Stage 3 work and is
 * deliberately NOT implemented here.
 *
 * Everything is a no-op when Polylang is inactive, so the plugin keeps
 * working (single-language) without it.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Language guard for imported events.
 */
final class Conexao_Event_Importer_Language_Guard {

	/**
	 * Wire the guard.
	 *
	 * `save_post_event` is used (rather than a call inside each importer path)
	 * so *every* creation route is covered: single import, multi-import,
	 * dry-run is read-only, ZIP/JSON transfer and any future importer.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post_event', array( __CLASS__, 'assign_language' ), 20, 1 );
	}

	/**
	 * Language that imported content belongs to.
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
	 * Assign the import language to an event that has none yet.
	 *
	 * Never reassigns an existing language: a hand-made English translation of
	 * an event must keep its language.
	 *
	 * @param int $post_id Event post ID.
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

	/**
	 * Can the importer write to this record?
	 *
	 * True when the record is in the import language, or has no language at
	 * all (Polylang inactive / legacy row). False when the record belongs to
	 * another language — i.e. it is a translation, not the import target.
	 *
	 * @param int $post_id Event post ID.
	 * @return bool
	 */
	public static function is_import_target( $post_id ): bool {
		$language = self::import_language();

		if ( '' === $language || ! function_exists( 'pll_get_post_language' ) ) {
			return true;
		}

		$current = pll_get_post_language( (int) $post_id, 'slug' );

		return ! is_string( $current ) || '' === $current || $current === $language;
	}
}
