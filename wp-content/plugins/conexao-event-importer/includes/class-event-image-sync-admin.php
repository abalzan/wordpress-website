<?php
/**
 * Event image sync admin interface.
 *
 * Adds a "Sync Event Images" page under the Event Importer menu. The page lets
 * an administrator scan all existing events, detect those still referencing an
 * external (e.g. Eventbrite) image URL, download those images into the
 * WordPress Media Library, and set them as the event's banner + featured image.
 *
 * Events whose images are already stored locally are left untouched.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Image_Sync_Admin {

	/**
	 * @var Conexao_Event_Image_Handler
	 */
	protected $image_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->image_handler = new Conexao_Event_Image_Handler();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_conexao_sync_event_images', array( $this, 'handle_sync' ) );
	}

	/**
	 * Register the "Sync Event Images" submenu page.
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'conexao-event-import',
			__( 'Sync Event Images', 'conexao-event-importer' ),
			__( 'Sync Event Images', 'conexao-event-importer' ),
			'manage_options',
			'conexao-event-image-sync',
			array( $this, 'render_sync_page' )
		);
	}

	/**
	 * Handle the bulk image sync request.
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to sync event images.', 'conexao-event-importer' ) );
		}

		check_admin_referer( 'conexao_sync_event_images', 'conexao_sync_images_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-event-image-sync' );

		// Process a batch of events per request to stay within safe execution limits.
		$event_ids = $this->find_events_with_external_banners( 50 );

		if ( empty( $event_ids ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_sync_images_synced', 0, $redirect ) );
			exit;
		}

		$synced = 0;
		$failed = array();

		foreach ( $event_ids as $post_id ) {
			$result = $this->image_handler->sync_event_image( $post_id );

			if ( 'ok' === $result['status'] ) {
				$synced++;
			} elseif ( 'failed' === $result['status'] ) {
				$failed[] = array(
					'id'    => $post_id,
					'title' => get_the_title( $post_id ),
				);
			}
		}

		$args = array(
			'conexao_sync_images_synced' => $synced,
			'conexao_sync_images_total'  => count( $event_ids ),
		);

		if ( ! empty( $failed ) ) {
			$args['conexao_sync_images_failed'] = rawurlencode( wp_json_encode( $failed ) );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Find events that still reference an external image URL.
	 *
	 * Queries events that have a _event_banner meta value that does not contain
	 * /wp-content/uploads/. The image handler performs a more precise
	 * external-host check and local-attachment check per event.
	 *
	 * @param int $limit Maximum number of events per run.
	 * @return int[] Event post IDs.
	 */
	protected function find_events_with_external_banners( $limit = 50 ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'event',
				'post_status'            => 'any',
				'posts_per_page'         => max( 1, absint( $limit ) ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_event_banner',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_event_banner',
						'value'   => '/wp-content/uploads/',
						'compare' => 'NOT LIKE',
					),
				),
			)
		);

		return $query->posts ? array_map( 'absint', $query->posts ) : array();
	}

	/**
	 * Render the Sync Event Images admin page.
	 */
	public function render_sync_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$synced = isset( $_GET['conexao_sync_images_synced'] ) ? (int) $_GET['conexao_sync_images_synced'] : null;
		$total  = isset( $_GET['conexao_sync_images_total'] ) ? (int) $_GET['conexao_sync_images_total'] : null;
		$failed = isset( $_GET['conexao_sync_images_failed'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['conexao_sync_images_failed'] ) ), true ) : array();

		$pending = count( $this->find_events_with_external_banners( 200 ) );
		?>
		<div class="wrap conexao-event-transfer">
			<h1><?php esc_html_e( 'Sync Event Images', 'conexao-event-importer' ); ?></h1>
			<p><?php esc_html_e( 'Scans existing events for images still hosted on external servers (e.g. Eventbrite) and downloads them into the WordPress Media Library. The downloaded image is set as the event\'s banner and featured image, so event cards no longer depend on external image servers.', 'conexao-event-importer' ); ?></p>

			<?php if ( null !== $synced ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Image sync complete', 'conexao-event-importer' ); ?></strong>
						<br>
						<?php echo esc_html( sprintf( __( 'Processed %d events; %d image(s) downloaded into the Media Library.', 'conexao-event-importer' ), $total, $synced ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $failed ) && is_array( $failed ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Some images could not be downloaded', 'conexao-event-importer' ); ?></strong></p>
					<ul style="list-style: disc; padding-left: 20px;">
						<?php foreach ( $failed as $failure ) : ?>
							<li>
								<?php echo esc_html( isset( $failure['title'] ) ? $failure['title'] : '#' . absint( $failure['id'] ) ); ?>
								(<a href="<?php echo esc_url( get_edit_post_link( $failure['id'] ) ); ?>"><?php echo esc_html( absint( $failure['id'] ) ); ?></a>)
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'Pending events', 'conexao-event-importer' ); ?></h2>
				<p class="conexao-transfer-count">
					<strong><?php echo esc_html( number_format_i18n( $pending ) ); ?></strong>
					<?php echo esc_html( _n( 'event still uses an external image', 'events still use an external image', $pending, 'conexao-event-importer' ) ); ?>
				</p>
				<p><?php esc_html_e( 'The sync runs in batches of 50 events per request to stay within safe execution limits. Run it again to process the next batch.', 'conexao-event-importer' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_sync_event_images">
					<?php wp_nonce_field( 'conexao_sync_event_images', 'conexao_sync_images_nonce' ); ?>
					<?php submit_button( __( 'Sync Event Images', 'conexao-event-importer' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="conexao-transfer-card">
				<h2><?php esc_html_e( 'What happens', 'conexao-event-importer' ); ?></h2>
				<ul class="conexao-transfer-list">
					<li><?php esc_html_e( 'Events with an existing local WordPress image are skipped.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'External images are downloaded with validated JPEG/PNG/WebP/GIF magic bytes and size checks.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Each attachment is created through the WordPress Media Library and normal image sizes (srcset, thumbnail) are generated.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'The same image used by several events is downloaded only once (deduplicated by source URL).', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Event cards render the local WordPress image URL after the sync.', 'conexao-event-importer' ); ?></li>
					<li><?php esc_html_e( 'Downloads that fail are logged and listed on this page with a link to the event.', 'conexao-event-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}