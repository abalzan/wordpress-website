<?php
/**
 * Reusable content actions for the Conexão Admin UX.
 *
 * Handles all secondary CRUD actions shared by every content type:
 * Duplicate, Archive, Delete, and bulk status/taxonomy/delete.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Actions {

	/**
	 * Status meta key for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function status_meta_key( $post_type ) {
		$config = Conexao_Admin_Ux_Config::get( $post_type );
		if ( $config && ! empty( $config['publishing']['status_meta'] ) ) {
			return $config['publishing']['status_meta'];
		}
		return '_' . $post_type . '_status';
	}

	/**
	 * Get the current custom status for a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_status( $post_id, $post_type ) {
		if ( 'event' === $post_type && class_exists( 'Conexao_Event_Status' ) ) {
			return Conexao_Event_Status::get_status( $post_id );
		}

		$meta = get_post_meta( $post_id, self::status_meta_key( $post_type ), true );
		if ( empty( $meta ) ) {
			$post = get_post( $post_id );
			return ( $post && 'publish' === $post->post_status ) ? 'published' : 'draft';
		}
		return $meta;
	}

	/**
	 * Set the custom status for a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param string $status    Status key.
	 */
	public static function set_status( $post_id, $post_type, $status ) {
		if ( 'event' === $post_type && class_exists( 'Conexao_Event_Status' ) && 'archived' !== $status ) {
			Conexao_Event_Status::set_status( $post_id, $status );

			// Keep WP post_status in sync so the public site filters correctly.
			$post = get_post( $post_id );
			if ( $post && 'published' === $status && 'publish' !== $post->post_status ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			} elseif ( $post && 'published' !== $status && 'publish' === $post->post_status ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			}
			return;
		}

		$meta_key = self::status_meta_key( $post_type );
		update_post_meta( $post_id, $meta_key, $status );

		$post = get_post( $post_id );
		if ( $post ) {
			if ( 'published' === $status && 'publish' !== $post->post_status ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			} elseif ( 'archived' === $status && 'publish' === $post->post_status ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			}
		}
	}

	/**
	 * Duplicate a post (content + metadata + terms) as a new draft.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Post type.
	 * @return int|WP_Error New post ID.
	 */
	public static function duplicate( $post_id, $post_type ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return new WP_Error( 'invalid_post', __( 'Conteúdo inválido para duplicação.', 'conexao-admin-ux' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Você não tem permissão para duplicar conteúdo.', 'conexao-admin-ux' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $post->post_title . ' (Cópia)',
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Copy all post meta.
		$meta = get_post_meta( $post_id );
		foreach ( $meta as $key => $values ) {
			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );
				if ( self::is_unique_meta( $key ) ) {
					continue;
				}
				if ( '_event_status' === $key && 'archived' === $value ) {
					continue;
				}
				add_post_meta( $new_id, $key, $value );
			}
		}

		// Copy taxonomies.
		$config = Conexao_Admin_Ux_Config::get( $post_type );
		if ( $config ) {
			foreach ( self::used_taxonomies( $config ) as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					wp_set_object_terms( $new_id, $terms, $taxonomy );
				}
			}
		}

		// Copy featured image.
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			set_post_thumbnail( $new_id, $thumb_id );
		}

		// Set status to draft and clear unique source fields.
		self::set_status( $new_id, $post_type, 'draft' );
		delete_post_meta( $new_id, '_event_source_id' );

		return $new_id;
	}

	/**
	 * Archive a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function archive( $post_id, $post_type ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		self::set_status( $post_id, $post_type, 'archived' );
		return true;
	}

	/**
	 * Delete a post permanently.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function delete( $post_id, $post_type ) {
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $post_id, true );
	}

	/**
	 * Apply a bulk status change.
	 *
	 * @param int[]  $post_ids  Post IDs.
	 * @param string $post_type Post type.
	 * @param string $status    Status key.
	 * @return int Count updated.
	 */
	public static function bulk_status( $post_ids, $post_type, $status ) {
		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				self::set_status( $post_id, $post_type, $status );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Apply a bulk taxonomy assignment.
	 *
	 * @param int[]  $post_ids  Post IDs.
	 * @param string $taxonomy  Taxonomy slug.
	 * @param int    $term_id   Term ID (0 clears).
	 * @return int Count updated.
	 */
	public static function bulk_taxonomy( $post_ids, $taxonomy, $term_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), $taxonomy );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Apply a bulk permanent delete.
	 *
	 * @param int[]  $post_ids  Post IDs.
	 * @param string $post_type Post type.
	 * @return int Count deleted.
	 */
	public static function bulk_delete( $post_ids, $post_type ) {
		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( self::delete( $post_id, $post_type ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Get list of taxonomies used by a config.
	 *
	 * @param array $config Content type config.
	 * @return string[]
	 */
	public static function used_taxonomies( $config ) {
		$taxonomies = array();
		foreach ( Conexao_Admin_Ux_Fields::collect_fields( $config ) as $field ) {
			if ( 'taxonomy' === $field['type'] && ! in_array( $field['taxonomy'], $taxonomies, true ) ) {
				$taxonomies[] = $field['taxonomy'];
			}
		}
		return $taxonomies;
	}

	/**
	 * Whether a meta key must be unique per post.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_unique_meta( $key ) {
		return in_array(
			$key,
			array(
				'_event_source_id', // External event identifier must not be copied.
				'_wp_old_slug',
				'_edit_lock',
				'_edit_last',
			),
			true
		);
	}
}