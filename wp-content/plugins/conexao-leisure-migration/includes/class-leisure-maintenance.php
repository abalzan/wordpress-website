<?php
/**
 * Lazer maintenance / legacy data cleanup.
 *
 * Provides a conservative cleanup operation that removes only data belonging
 * to the old external-image architecture:
 *
 *   - `_leisure_image_external_url` meta (external hotlink delivery)
 *   - `_leisure_image_status = 'external'` values (replaced by 'local')
 *   - Legacy image URL fields that are no longer used for delivery
 *
 * It never deletes:
 *   - Valid Media Library images
 *   - Attribution / license / source metadata
 *   - Images used by other WordPress content
 *   - Images manually uploaded by administrators
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Maintenance {

	/**
	 * Get a preview of legacy data that would be cleaned up.
	 *
	 * @return array
	 */
	public function preview_cleanup() {
		$result = array(
			'legacy_external_urls' => 0,
			'legacy_external_status' => 0,
			'affected_items'       => 0,
			'legacy_meta_keys'     => array(),
			'items'                => array(),
		);

		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		foreach ( $query->posts as $post_id ) {
			$external_url = get_post_meta( $post_id, '_leisure_image_external_url', true );
			$status       = get_post_meta( $post_id, '_leisure_image_status', true );

			$has_legacy = false;
			$item = array(
				'post_id'        => $post_id,
				'title'          => get_the_title( $post_id ),
				'external_url'   => $external_url ? $external_url : '',
				'status'         => $status,
				'has_attachment' => (bool) get_post_meta( $post_id, '_leisure_image_attachment_id', true ),
			);

			if ( $external_url ) {
				$result['legacy_external_urls']++;
				$has_legacy = true;
			}
			if ( 'external' === $status ) {
				$result['legacy_external_status']++;
				$has_legacy = true;
			}

			if ( $has_legacy ) {
				$result['affected_items']++;
				$result['items'][] = $item;
			}
		}

		// Count legacy meta keys that exist in the DB.
		global $wpdb;
		$legacy_keys = array( '_leisure_image_external_url' );
		foreach ( $legacy_keys as $key ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				$key
			) );
			if ( $count > 0 ) {
				$result['legacy_meta_keys'][ $key ] = $count;
			}
		}

		return $result;
	}

	/**
	 * Execute the cleanup of legacy external-image data.
	 *
	 * @return array{removed_external_urls:int, removed_external_status:int, affected_items:int}
	 */
	public function run_cleanup() {
		$preview = $this->preview_cleanup();

		$removed_urls    = 0;
		$removed_status  = 0;
		$affected        = 0;

		foreach ( $preview['items'] as $item ) {
			$post_id = (int) $item['post_id'];

			if ( $item['external_url'] ) {
				delete_post_meta( $post_id, '_leisure_image_external_url' );
				$removed_urls++;
			}

			if ( 'external' === $item['status'] ) {
				// If the item has a local attachment, set status to 'local';
				// otherwise set to 'pending' (no image available).
				$new_status = $item['has_attachment'] ? 'local' : 'pending';
				update_post_meta( $post_id, '_leisure_image_status', $new_status );
				$removed_status++;
			}

			$affected++;
		}

		return array(
			'removed_external_urls'   => $removed_urls,
			'removed_external_status' => $removed_status,
			'affected_items'          => $affected,
		);
	}
}