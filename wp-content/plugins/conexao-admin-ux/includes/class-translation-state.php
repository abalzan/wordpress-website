<?php
/**
 * Editorial translation-state indicator (Stage 3.2).
 *
 * The smallest useful editorial signal for the bilingual rollout, built on
 * the existing Conexão Admin UX architecture (per-type list columns + editor
 * meta box). It is NOT a workflow engine: no statuses, no assignments, no
 * notifications.
 *
 * State model (per translatable record, Polylang-linked):
 *   - missing   — the default-language (pt) record has no English translation.
 *   - current   — an English translation exists and is not flagged outdated.
 *   - outdated  — an English translation exists AND carries the
 *                 `_translation_outdated` flag.
 *
 * The documented rule behind the flag (derived from real editing events,
 * never guessed from content length or timestamps):
 *   1. Saving a record in the default language flags every linked English
 *      translation as `_translation_outdated = 1` (the source moved; the
 *      translation may need attention). This includes importer updates,
 *      which is intentional: a source-content update invalidates the
 *      translation.
 *   2. Saving an English translation clears its own flag.
 *   3. Creating/linking a translation starts clean (no flag).
 *
 * Visibility rules (hard requirements):
 *   - Admin-only: list column + editor meta box. Nothing is exposed on the
 *     front end, in feeds, in REST responses, or in the sitemap, and no
 *     front-end SEO/routing decision reads this state.
 *   - `_translation_outdated` lives on the ENGLISH record; the Portuguese
 *     master is never mutated by this module (it only READS links).
 *   - Full no-op when Polylang is inactive.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Translation_State {

	/**
	 * Flag meta key stored on the English translation record.
	 */
	const OUTDATED_META = '_translation_outdated';

	/**
	 * Secondary (translation) language slug handled by this indicator.
	 */
	const SECOND_LANGUAGE = 'en';

	/**
	 * Wire the module. No-op without Polylang.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_default_language' ) ) {
			return;
		}

		add_action( 'save_post', array( __CLASS__, 'sync_on_save' ), 20, 2 );

		foreach ( self::post_types() as $post_type ) {
			$is_page = ( 'page' === $post_type );
			add_filter( $is_page ? 'manage_pages_posts_columns' : "manage_{$post_type}_posts_columns", array( __CLASS__, 'column' ) );
			add_action( $is_page ? 'manage_pages_posts_custom_column' : "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
			add_action( 'add_meta_boxes_' . $post_type, array( __CLASS__, 'register_metabox' ) );
		}
	}

	/**
	 * Post types the indicator covers: Polylang-translated content types,
	 * minus internal utility types (reusable blocks, nav items, …).
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = function_exists( 'PLL' ) && PLL() ? PLL()->model->get_translated_post_types() : array();
		$types = array_values( array_diff( array_map( 'strval', $types ), array( 'wp_block', 'attachment' ) ) );

		/**
		 * Filter the post types carrying the translation-state indicator.
		 *
		 * @param string[] $types Polylang-translated content types.
		 */
		return (array) apply_filters( 'conexao_translation_state_post_types', $types );
	}

	/**
	 * Resolve the editorial translation state for a record.
	 *
	 * @param int $post_id Post ID (any language of the pair).
	 * @return array{state:string, source_id:int, translation_id:int}
	 *               state: not_applicable|missing|current|outdated.
	 *               source_id: the default-language record (0 when unknown).
	 *               translation_id: the English record (0 when missing).
	 */
	public static function get_state( $post_id ) {
		$result = array(
			'state'          => 'not_applicable',
			'source_id'      => 0,
			'translation_id' => 0,
		);

		if ( ! function_exists( 'pll_get_post' ) ) {
			return $result;
		}

		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return $result;
		}

		$default = (string) pll_default_language( 'slug' );
		$lang    = (string) pll_get_post_language( $post_id, 'slug' );

		if ( '' === $default || '' === $lang ) {
			return $result;
		}

		if ( $lang === $default ) {
			$result['source_id']      = (int) $post_id;
			$result['translation_id'] = (int) pll_get_post( $post_id, self::SECOND_LANGUAGE );
		} else {
			$result['source_id']      = (int) pll_get_post( $post_id, $default );
			$result['translation_id'] = (int) $post_id;
		}

		if ( ! $result['source_id'] ) {
			// A translation with no default-language source: treat as current
			// standalone English content (source-inherited records).
			$result['state'] = ( $lang === self::SECOND_LANGUAGE ) ? 'current' : 'not_applicable';
			return $result;
		}

		if ( ! $result['translation_id'] ) {
			$result['state'] = 'missing';
			return $result;
		}

		$result['state'] = get_post_meta( $result['translation_id'], self::OUTDATED_META, true ) ? 'outdated' : 'current';

		return $result;
	}

	/**
	 * Keep `_translation_outdated` in sync with real edit events.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public static function sync_on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return;
		}

		$lang    = (string) pll_get_post_language( $post_id, 'slug' );
		$default = (string) pll_default_language( 'slug' );

		if ( '' === $lang || '' === $default ) {
			return;
		}

		if ( $lang === $default ) {
			// The source moved: every linked translation may need attention.
			$translations = pll_get_post_translations( $post_id );
			foreach ( $translations as $tlang => $tid ) {
				if ( $tlang === $default ) {
					continue;
				}
				update_post_meta( (int) $tid, self::OUTDATED_META, 1 );
			}
			return;
		}

		// Saving a translation clears its own outdated flag.
		if ( self::SECOND_LANGUAGE === $lang ) {
			delete_post_meta( $post_id, self::OUTDATED_META );
		}
	}

	/**
	 * Human-readable label + CSS class for a state (admin chrome, PT UI).
	 *
	 * @param string $state State slug.
	 * @return array{label:string, class:string}
	 */
	public static function state_label( $state ) {
		switch ( $state ) {
			case 'current':
				return array(
					'label' => __( 'Atual', 'conexao-admin-ux' ),
					'class' => 'publish',
				);
			case 'outdated':
				return array(
					'label' => __( 'Desatualizada', 'conexao-admin-ux' ),
					'class' => 'pending',
				);
			case 'missing':
				return array(
					'label' => __( 'Ausente', 'conexao-admin-ux' ),
					'class' => 'draft',
				);
			default:
				return array(
					'label' => '—',
					'class' => '',
				);
		}
	}

	/**
	 * Register the list-table column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$columns['conexao_translation_state'] = __( 'EN', 'conexao-admin-ux' );
		return $columns;
	}

	/**
	 * Render the list-table cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'conexao_translation_state' !== $column ) {
			return;
		}

		$state = self::get_state( $post_id );
		if ( 'not_applicable' === $state['state'] ) {
			echo '—';
			return;
		}

		$label = self::state_label( $state['state'] );
		echo '<span class="post-state ' . esc_attr( $label['class'] ) . '">' . esc_html( $label['label'] ) . '</span>';

		if ( $state['translation_id'] ) {
			$link = get_edit_post_link( $state['translation_id'] );
			if ( $link ) {
				echo ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'editar', 'conexao-admin-ux' ) . '</a>';
			}
		}
	}

	/**
	 * Register the editor meta box.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public static function register_metabox( $post ) {
		add_meta_box(
			'conexao-translation-state',
			__( 'Tradução (EN)', 'conexao-admin-ux' ),
			array( __CLASS__, 'render_metabox' ),
			$post->post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render the editor meta box. PT source: English state. EN translation:
	 * its relationship to the PT source. Admin-only by construction.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_metabox( $post ) {
		$state = self::get_state( $post->ID );
		$lang  = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '';
		$label = self::state_label( $state['state'] );

		echo '<div class="conexao-translation-state">';

		if ( 'not_applicable' === $state['state'] ) {
			echo '<p>' . esc_html__( 'Conteúdo fora do fluxo de tradução EN.', 'conexao-admin-ux' ) . '</p></div>';
			return;
		}

		if ( 'en' === $lang ) {
			if ( $state['source_id'] ) {
				$source_link = get_edit_post_link( $state['source_id'] );
			echo '<p>' . esc_html__( 'Tradução de:', 'conexao-admin-ux' ) . ' ';
				echo $source_link
					? '<a href="' . esc_url( $source_link ) . '">' . esc_html( get_post_field( 'post_title', $state['source_id'] ) ) . '</a>'
					: esc_html( get_post_field( 'post_title', $state['source_id'] ) );
				echo '</p>';
			} else {
				echo '<p>' . esc_html__( 'Conteúdo original em inglês (sem fonte em português).', 'conexao-admin-ux' ) . '</p>';
			}
			echo '<p>' . esc_html__( 'Estado:', 'conexao-admin-ux' ) . ' <span class="post-state ' . esc_attr( $label['class'] ) . '">' . esc_html( $label['label'] ) . '</span></p>';
		} else {
			echo '<p>' . esc_html__( 'Tradução em inglês:', 'conexao-admin-ux' ) . ' <span class="post-state ' . esc_attr( $label['class'] ) . '">' . esc_html( $label['label'] ) . '</span></p>';
			if ( $state['translation_id'] ) {
				$link = get_edit_post_link( $state['translation_id'] );
				if ( $link ) {
					echo '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Editar tradução', 'conexao-admin-ux' ) . '</a></p>';
				}
			} elseif ( function_exists( 'PLL' ) && PLL() && isset( PLL()->links ) ) {
				$new = PLL()->links->get_new_post_translation_link( $post->ID, PLL()->model->get_language( self::SECOND_LANGUAGE ) );
				if ( $new ) {
					echo '<p><a href="' . esc_url( $new ) . '">' . esc_html__( 'Adicionar tradução', 'conexao-admin-ux' ) . '</a></p>';
				}
			}
		}

		echo '</div>';
	}
}
