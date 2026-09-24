<?php
/**
 * Empregos Landing page module.
 *
 * Supports the Jobs information hub at /empregos/ (backed by a normal
 * WordPress Page rendered through page-empregos.php):
 *
 *   Empregos → portrait image → editable body content → "Mais informações" CTA
 *
 * Content (title, portrait featured image, body) is edited through the native
 * Page editor in wp-admin. This module only adds the single minimal field the
 * hub needs on top of what WordPress already provides: the optional external
 * URL used by the "Mais informações" button. If it is left empty, the button is
 * not rendered at all (no empty CTA).
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the `_empregos_link` meta so the block editor / REST API are aware
 * of it and it is saved reliably. It is a simple string (sanitized URL).
 */
function conexao_register_empregos_meta() {
	register_post_meta(
		'page',
		'_empregos_link',
		array(
			'single'            => true,
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
			'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
				return current_user_can( 'edit_post', $object_id );
			},
		)
	);
}
add_action( 'init', 'conexao_register_empregos_meta' );

/**
 * Whether a given post is the Jobs landing page.
 *
 * True when the post is a page using the `page-empregos.php` template, or whose
 * slug is `empregos` (the two normally coincide), so the field also behaves on
 * already-published pages before a template is explicitly chosen.
 *
 * @param WP_Post|int|null $post Post object, ID or null (current post).
 * @return bool
 */
function conexao_is_empregos_landing( $post = null ) {
	$post = get_post( $post );
	if ( ! $post || 'page' !== $post->post_type ) {
		return false;
	}
	if ( 'empregos' === $post->post_name ) {
		return true;
	}
	return 'page-empregos.php' === get_page_template_slug( $post->ID );
}

/**
 * Add the minimal admin field for the "Mais informações" link.
 * Only rendered on the Jobs landing page.
 */
function conexao_empregos_add_meta_box() {
	add_meta_box(
		'conexao-empregos-link',
		'Jobs — Link "Mais informações"',
		'conexao_empregos_meta_box_cb',
		'page',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'conexao_empregos_add_meta_box' );

/**
 * Render the metabox for the CTA link.
 *
 * @param WP_Post $post Current post.
 */
function conexao_empregos_meta_box_cb( $post ) {
	// Only relevant on the Jobs landing page — hide it everywhere else.
	if ( ! conexao_is_empregos_landing( $post ) ) {
		echo '<p class="description">'
			. esc_html__( 'Este campo é exibido apenas na página de destino de Empregos (template "Empregos — Página de Difusão").', 'conexao-br-irlanda' )
			. '</p>';
		return;
	}

	wp_nonce_field( 'conexao_empregos_link_save', 'conexao_empregos_link_nonce' );
	$value = get_post_meta( $post->ID, '_empregos_link', true );
	?>
	<p class="description">
		<?php esc_html_e( 'URL ou destino do botão "Mais informações". Se deixado em branco, o botão não será exibido.', 'conexao-br-irlanda' ); ?>
	</p>
	<p>
		<input type="url" class="widefat" name="conexao_empregos_link"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="https://example.com" />
	</p>
	<?php
}

/**
 * Persist the CTA link when the landing page is saved.
 *
 * @param int $post_id Post ID.
 */
function conexao_empregos_save_meta( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['conexao_empregos_link_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_empregos_link_nonce'] ) ), 'conexao_empregos_link_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['conexao_empregos_link'] ) ) {
		$url = esc_url_raw( trim( wp_unslash( $_POST['conexao_empregos_link'] ) ) );
		if ( $url ) {
			update_post_meta( $post_id, '_empregos_link', $url );
		} else {
			delete_post_meta( $post_id, '_empregos_link' );
		}
	}
}
add_action( 'save_post_page', 'conexao_empregos_save_meta' );

/**
 * The escaped CTA URL for a given Jobs landing page ('' when none).
 *
 * @param int $post_id Post ID (defaults to the current post).
 * @return string URL, escaped for output, or empty string.
 */
function conexao_empregos_link( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : (int) get_the_ID();
	if ( ! $post_id ) {
		return '';
	}
	$url = trim( (string) get_post_meta( $post_id, '_empregos_link', true ) );
	return $url ? esc_url( $url ) : '';
}

/**
 * Canonical URL of the Jobs landing page ('' when the page is missing).
 * Used by the theme breadcrumb for job singles, which previously linked to the
 * (now disabled) job CPT archive.
 *
 * Language-aware (Stage 6): on a non-default-language request the URL of the
 * published, linked translation of the landing page is returned (the Stage 4.5
 * EN Jobs page, `/en/jobs/`), so an English job detail breadcrumbs
 * Home › Jobs › … instead of sending the visitor back to the Portuguese hub.
 * On the default language (and whenever Polylang or the translation is
 * missing) the behaviour is byte-identical to before.
 *
 * @return string
 */
function conexao_empregos_page_url() {
	$page = get_page_by_path( 'empregos', OBJECT, 'page' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}

	if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && function_exists( 'pll_get_post' ) ) {
		$current = conexao_current_language_slug();
		$default = conexao_default_language_slug();

		if ( '' !== $current && '' !== $default && $current !== $default ) {
			$translation = (int) pll_get_post( (int) $page->ID, $current );

			if ( $translation > 0 && 'publish' === get_post_status( $translation ) ) {
				$permalink = get_permalink( $translation );

				if ( $permalink ) {
					return (string) $permalink;
				}
			}
		}
	}

	return get_permalink( $page );
}

/**
 * Remind the editor that the featured image should be an Instagram-style
 * portrait (same convention as the per-Job artwork) when editing the landing.
 */
function conexao_empregos_featured_image_hint( $content, $post_id ) {
	if ( ! $post_id || ! conexao_is_empregos_landing( $post_id ) ) {
		return $content;
	}

	$hint = '<p class="description">'
		. __( 'Imagem da página de Empregos: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories).', 'conexao-br-irlanda' )
		. '</p>';

	return $hint . $content;
}

/**
 * Published `job` records for the Jobs landing page, in the CURRENT language.
 *
 * Stage 6 — the Jobs landing page lists the real Job CPT records (each card
 * opens the job detail). The set follows the approved B2 archive contract
 * (the same one Conexao_Event_Query implements for events):
 *
 *   - default-language request → published default-language jobs;
 *   - English request          → published EN jobs PLUS published PT jobs that
 *     have no EN translation yet (the B2 set) — a PT master that HAS a linked
 *     EN translation is replaced by it, so one identity never appears twice;
 *   - records with no language (legacy rows, Polylang inactive) stay visible.
 *
 * Card URLs are the records' own permalinks: an EN record links to its
 * `/en/empregos/{slug}/` detail; an untranslated PT record links to its
 * canonical Portuguese detail (the measured behaviour of every other B2
 * archive — the EN-shell single remains reachable at `/en/empregos/{pt-slug}/`
 * through the language switcher and direct URL).
 *
 * The ID list is cached in a language-scoped transient (architecture §18:
 * PT and EN caches never share a key) and flushed on every job save/delete.
 *
 * @param int $limit Maximum number of jobs (newest first).
 * @return int[] Published job IDs for the current language context.
 */
function conexao_empregos_current_jobs( int $limit = 12 ): array {
	$cache_key = function_exists( 'conexao_lang_cache_key' )
		? conexao_lang_cache_key( 'conexao_empregos_current_jobs' )
		: 'conexao_empregos_current_jobs';

	$cached = get_transient( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return array_map( 'intval', $cached );
	}

	$query_args = array(
		'post_type'      => 'job',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	);

	$current = function_exists( 'conexao_current_language_slug' ) ? conexao_current_language_slug() : '';
	$default = function_exists( 'conexao_default_language_slug' ) ? conexao_default_language_slug() : '';

	if ( '' !== $current && function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_post' ) ) {
		// All languages — the language curation below decides membership
		// (Polylang's automatic filter would hide the B2 set on EN requests).
		$query_args['lang'] = '';
	}

	$ids = get_posts( $query_args );

	if ( '' !== $current && function_exists( 'pll_get_post_language' ) ) {
		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( int $id ) use ( $current, $default ): bool {
					$language = (string) pll_get_post_language( $id, 'slug' );

					// Legacy rows without a language stay visible everywhere.
					if ( '' === $language ) {
						return true;
					}

					if ( $language === $current ) {
						return true;
					}

					// B2: a PT record with no EN translation belongs to the EN
					// listing; once its translation exists the EN record is
					// listed instead (never both).
					if ( 'en' === $current && $language === $default && function_exists( 'pll_get_post' ) ) {
						$translated = (int) pll_get_post( $id, 'en' );
						return 0 === $translated || $translated === $id;
					}

					return false;
				}
			)
		);
	}

	set_transient( $cache_key, $ids, 5 * MINUTE_IN_SECONDS );

	return $ids;
}

/**
 * Flush the Jobs landing listing cache in EVERY language on job save/delete.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function conexao_empregos_flush_jobs_cache( $post_id ): void {
	if ( 'job' !== get_post_type( (int) $post_id ) ) {
		return;
	}

	if ( function_exists( 'conexao_flush_language_cache' ) ) {
		conexao_flush_language_cache( 'conexao_empregos_current_jobs' );
	} else {
		delete_transient( 'conexao_empregos_current_jobs' );
	}
}
add_action( 'save_post_job', 'conexao_empregos_flush_jobs_cache' );
add_action( 'delete_post', 'conexao_empregos_flush_jobs_cache' );

add_filter( 'admin_post_thumbnail_html', 'conexao_empregos_featured_image_hint', 11, 2 );