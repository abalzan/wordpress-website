<?php
/**
 * Plugin Name: Conexão BR Irlanda — Test Events
 * Description: Seeds realistic dummy events with banner images and destination URLs for testing the Events page.
 * Version: 1.0.0
 * Text Domain: conexao-test-events
 *
 * @package Conexao_Test_Events
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Test_Events {

	const META_URL          = '_event_url';
	const META_BANNER       = '_event_banner';
	const META_REGISTRATION = '_event_registration';
	const META_CTA          = '_event_cta';

	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );

		// One-time seed (safe fallback if the plugin files are added while the
		// plugin is already active and the activation hook never fired).
		add_action( 'admin_init', array( $this, 'maybe_seed' ) );
	}

	public function register_meta() {
		foreach ( array( self::META_URL, self::META_BANNER, self::META_REGISTRATION, self::META_CTA ) as $key ) {
			register_post_meta(
				'event',
				$key,
				array(
					'single'            => true,
					'type'              => 'string',
					'show_in_rest'      => true,
					'sanitize_callback' => in_array( $key, array( self::META_URL, self::META_BANNER ), true )
						? 'esc_url_raw'
						: 'sanitize_text_field',
				)
			);
		}
	}

	public function add_meta_boxes() {
		add_meta_box(
			'conexao_test_event_extra',
			'Detalhes extras do evento (teste)',
			array( $this, 'render_meta_box' ),
			'event',
			'normal',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'conexao_test_events_meta', 'conexao_test_events_nonce' );
		?>
		<p>
			<label for="event_url"><strong>URL de destino</strong></label>
			<input class="widefat" type="url" id="event_url" name="event_url"
				value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_URL, true ) ); ?>"
				placeholder="https:// ou /events/meu-evento/">
			<span class="description">O banner clicável aponta para esta URL. Deixe em branco para usar o link do próprio evento.</span>
		</p>
		<p>
			<label for="event_banner"><strong>URL do banner / imagem</strong></label>
			<input class="widefat" type="url" id="event_banner" name="event_banner"
				value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_BANNER, true ) ); ?>"
				placeholder="https:// ou caminho para a imagem do banner">
			<span class="description">A imagem exibida como miniatura do evento (recomendado: 800×450).</span>
		</p>
		<p>
			<label for="event_registration"><strong>Informações de inscrição / ingresso</strong></label>
			<input class="widefat" type="text" id="event_registration" name="event_registration"
				value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_REGISTRATION, true ) ); ?>"
				placeholder="Ex.: Inscrição gratuita — vagas limitadas">
		</p>
		<?php
	}

	public function save_meta( $post_id ) {
		if (
			! isset( $_POST['conexao_test_events_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_test_events_nonce'] ) ), 'conexao_test_events_meta' )
			|| wp_is_post_autosave( $post_id )
			|| wp_is_post_revision( $post_id )
		) {
			return;
		}

		if ( 'event' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['event_url'] ) ) {
			update_post_meta( $post_id, self::META_URL, esc_url_raw( wp_unslash( $_POST['event_url'] ) ) );
		}
		if ( isset( $_POST['event_banner'] ) ) {
			update_post_meta( $post_id, self::META_BANNER, esc_url_raw( wp_unslash( $_POST['event_banner'] ) ) );
		}
		if ( isset( $_POST['event_registration'] ) ) {
			update_post_meta( $post_id, self::META_REGISTRATION, sanitize_text_field( wp_unslash( $_POST['event_registration'] ) ) );
		}
	}

	public function maybe_seed() {
		if ( ! get_option( 'conexao_test_events_seeded' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-test-events-seeder.php';
			Conexao_Test_Events_Seeder::seed();
		}
	}
}

new Conexao_Test_Events();

register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-test-events-seeder.php';
	Conexao_Test_Events_Seeder::seed();
	flush_rewrite_rules();
} );