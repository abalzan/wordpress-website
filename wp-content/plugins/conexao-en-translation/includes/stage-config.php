<?php
/**
 * Stage M declarative configuration for the shared translation-rollout engine.
 *
 * One stage per B1 post type. This file contains the WordPress-bound adapter
 * and the stage declaration only; the engine owns the orchestration.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress-bound primitives the engine orchestrates.
 *
 * @param string $post_type Post type.
 * @return array<string,callable>
 */
function conexao_en_translation_engine_adapter( string $post_type ): array {
	return array(
		'find_pt'        => static function ( string $stable_key ) use ( $post_type ) {
			$post = get_page_by_path( $stable_key, OBJECT, $post_type );

			if ( ! $post instanceof WP_Post ) {
				return null;
			}

			return array(
				'id'     => (int) $post->ID,
				'status' => (string) $post->post_status,
			);
		},
		'find_en_for_pt' => static function ( int $pt_id, string $expected_en_slug = '' ) {
			$absent = array(
				'en_id'           => 0,
				'en_status'       => 'absent',
				'pair_ok'         => false,
				'en_slug_matches' => true,
			);

			if ( ! function_exists( 'pll_get_post' ) ) {
				return $absent;
			}

			$en_id = (int) pll_get_post( $pt_id, 'en' );

			if ( $en_id <= 0 || $en_id === $pt_id ) {
				return $absent;
			}

			$actual = (string) get_post_field( 'post_name', $en_id );

			return array(
				'en_id'           => $en_id,
				'en_status'       => (string) get_post_status( $en_id ),
				'pair_ok'         => conexao_en_translation_pair_ok( $pt_id, $en_id ),
				'en_slug_matches' => ( '' === $expected_en_slug || $actual === $expected_en_slug ),
			);
		},
		'slug_collision' => static function ( string $en_slug, int $pt_id ) use ( $post_type ) {
			if ( '' === $en_slug ) {
				return true;
			}

			$hit = get_page_by_path( $en_slug, OBJECT, $post_type );

			// A collision only matters when the slug belongs to an UNLINKED
			// record: reusing the slug of the PT record itself is not a clash.
			return $hit instanceof WP_Post
				&& (int) $hit->ID !== $pt_id
				&& 0 !== (int) pll_get_post( (int) $hit->ID, 'pt' );
		},
		'create_en'      => static function ( int $pt_id, array $row ) use ( $post_type ) {
			$pt = get_post( $pt_id );

			if ( ! $pt instanceof WP_Post ) {
				return new WP_Error( 'conexao_en_missing_pt', 'PT record missing.' );
			}

			$en_id = wp_insert_post(
				array(
					'post_type'    => $post_type,
					'post_name'    => (string) $row['en_slug'],
					'post_title'   => (string) $row['en_title'],
					'post_content' => (string) $row['en_content'],
					'post_excerpt' => (string) $row['en_excerpt'],
					'post_status'  => 'publish',
					'post_date'    => $pt->post_date,
					'post_date_gmt'=> $pt->post_date_gmt,
					'post_author'  => (int) $pt->post_author,
					'menu_order'   => (int) $pt->menu_order,
				),
				true
			);

			return is_wp_error( $en_id ) ? $en_id : (int) $en_id;
		},
		'repair_en'      => static function ( int $pt_id, int $en_id, array $row ): bool {
			$update = array( 'ID' => $en_id );
			$en     = get_post( $en_id );

			if ( $en instanceof WP_Post && $en->post_name !== (string) $row['en_slug'] ) {
				$update['post_name'] = (string) $row['en_slug'];
			}
			if ( $en instanceof WP_Post && $en->post_title !== (string) $row['en_title'] ) {
				$update['post_title'] = (string) $row['en_title'];
			}
			if ( $en instanceof WP_Post && $en->post_content !== (string) $row['en_content'] ) {
				$update['post_content'] = (string) $row['en_content'];
			}
			if ( $en instanceof WP_Post && $en->post_excerpt !== (string) $row['en_excerpt'] ) {
				$update['post_excerpt'] = (string) $row['en_excerpt'];
			}

			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}

			return true;
		},
		'link_pair'      => static function ( int $pt_id, int $en_id ): bool {
			if ( ! function_exists( 'pll_set_post_language' ) ) {
				return false;
			}

			pll_set_post_language( $en_id, 'en' );

			if ( function_exists( 'pll_get_post_language' ) && ! pll_get_post_language( $pt_id ) ) {
				pll_set_post_language( $pt_id, 'pt' );
			}

			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations(
					array(
						'pt' => $pt_id,
						'en' => $en_id,
					)
				);
			}

			return true;
		},
		'pair_ok'        => static function ( int $pt_id, int $en_id ): bool {
			return conexao_en_translation_pair_ok( $pt_id, $en_id );
		},
		'remove_en'      => static function ( int $en_id ): bool {
			return (bool) wp_delete_post( $en_id, true );
		},
	);
}

/**
 * The declarative stage configuration for one post type.
 *
 * @param string $post_type Post type.
 * @return array<string,mixed>
 */
function conexao_en_translation_engine_config( string $post_type ): array {
	return array(
		'stage'                  => 'en-' . $post_type,
		'source_post_type'       => $post_type,
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () use ( $post_type ) {
			return conexao_en_translation_manifest_for( $post_type );
		},
		'snapshot_callback'      => 'conexao_en_translation_snapshot',
		'build_en_args_callback' => static function () use ( $post_type ) {
			return conexao_en_translation_manifest_for( $post_type );
		},
		'copy_fields_callback'   => 'conexao_en_translation_copy_fields',
		'verify_landing_callback'=> null,
		'extra_gate_callback'    => null,
		// Removal is safe and is the documented rollback: it deletes only the EN
		// records this manifest owns, and never touches a PT original.
		'allow_remove'           => true,
		'run_callback'           => static function ( array $args = array() ) use ( $post_type ) {
			return Conexao_Translation_Rollout_Engine::run(
				conexao_en_translation_engine_config( $post_type ),
				conexao_en_translation_engine_adapter( $post_type ),
				$args
			);
		},
	);
}
