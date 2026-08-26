<?php
/**
 * Apoiadores transfer admin.
 *
 * Registers the "Exportar Apoiadores" and "Importar Apoiadores" admin pages,
 * handling the export download and the import file upload (with dry-run
 * preview). Mirrors the event/leisure transfer admin UX so administrators see
 * one consistent migration workflow across content types.
 *
 * @package Conexao_Sponsor_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Sponsor_Transfer_Admin {

	/** @var Conexao_Sponsor_Exporter */
	protected $exporter;

	/** @var Conexao_Sponsor_Importer */
	protected $importer;

	public function __construct() {
		$this->exporter = new Conexao_Sponsor_Exporter();
		$this->importer = new Conexao_Sponsor_Importer();

		add_action( 'admin_init', array( $this, 'intercept_oversized_post' ) );
		add_action( 'admin_post_conexao_export_sponsors', array( $this, 'handle_export' ) );
		add_action( 'admin_post_conexao_import_sponsors', array( $this, 'handle_import' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the admin menu pages.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Apoiadores Migration', 'conexao-sponsor-migration' ),
			__( 'Apoiadores Migration', 'conexao-sponsor-migration' ),
			'manage_options',
			'conexao-sponsor-migration',
			array( $this, 'render_export_page' ),
			'dashicons-heart',
			81
		);

		add_submenu_page(
			'conexao-sponsor-migration',
			__( 'Exportar Apoiadores', 'conexao-sponsor-migration' ),
			__( 'Exportar Apoiadores', 'conexao-sponsor-migration' ),
			'manage_options',
			'conexao-sponsor-export',
			array( $this, 'render_export_page' )
		);

		add_submenu_page(
			'conexao-sponsor-migration',
			__( 'Importar Apoiadores', 'conexao-sponsor-migration' ),
			__( 'Importar Apoiadores', 'conexao-sponsor-migration' ),
			'manage_options',
			'conexao-sponsor-import',
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Handle the export download request.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para exportar apoiadores.', 'conexao-sponsor-migration' ) );
		}

		check_admin_referer( 'conexao_export_sponsors', 'conexao_export_nonce' );

		$this->exporter->download();
	}

	/**
	 * Handle the import file upload request.
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para importar apoiadores.', 'conexao-sponsor-migration' ) );
		}

		check_admin_referer( 'conexao_import_sponsors', 'conexao_import_nonce' );

		$redirect = admin_url( 'admin.php?page=conexao-sponsor-import' );

		if ( empty( $_FILES['conexao_sponsor_import_file'] ) || empty( $_FILES['conexao_sponsor_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'conexao_sponsor_import_error', 'no_file', $redirect ) );
			exit;
		}

		$file = $_FILES['conexao_sponsor_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated below.

		$upload_error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) {
			wp_safe_redirect( add_query_arg( 'conexao_sponsor_import_error', 'too_large', $redirect ) );
			exit;
		}

		$strategy = isset( $_POST['conexao_duplicate_strategy'] ) ? sanitize_key( wp_unslash( $_POST['conexao_duplicate_strategy'] ) ) : 'update';

		$stats = $this->importer->import_file(
			$file['tmp_name'],
			array( 'duplicate_strategy' => $strategy )
		);

		$args = array(
			'conexao_sponsor_imported' => $stats['imported'],
			'conexao_sponsor_updated'  => $stats['updated'],
			'conexao_sponsor_skipped'  => $stats['skipped'],
			'conexao_sponsor_failed'   => $stats['failed'],
			'conexao_sponsor_images'   => $stats['images_imported'],
			'conexao_sponsor_images_reuse' => $stats['images_reused'],
		);

		if ( ! empty( $stats['errors'] ) ) {
			$args['conexao_sponsor_import_errors'] = rawurlencode( wp_json_encode( array_slice( $stats['errors'], 0, 20 ) ) );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * PHP silently discards uploads larger than post_max_size — intercept the
	 * bounce and show a clear message instead of a blank redirect.
	 *
	 * Only requests originating from this import screen are intercepted.
	 */
	public function intercept_oversized_post() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Method check only.
			return;
		}
		if ( empty( $_SERVER['CONTENT_LENGTH'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Numeric length check only.
			return;
		}

		$max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( ! $max || (int) $_SERVER['CONTENT_LENGTH'] <= $max ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Numeric comparison only.
			return;
		}

		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' === $referer || false === strpos( $referer, 'page=conexao-sponsor-import' ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				'conexao_sponsor_import_error',
				'post_max_size',
				admin_url( 'admin.php?page=conexao-sponsor-import' )
			)
		);
		exit;
	}

	/**
	 * Render the export page.
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$count = $this->exporter->count_sponsors();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Exportar Apoiadores', 'conexao-sponsor-migration' ); ?></h1>
			<p><?php esc_html_e( 'Exporte todos os apoiadores desta instalação para um arquivo JSON portátil com as imagens embutidas. Você pode então importar este arquivo em outra instalação WordPress com a mesma estrutura de apoiadores.', 'conexao-sponsor-migration' ); ?></p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;max-width:720px;">
				<h2><?php esc_html_e( 'Apoiadores disponíveis', 'conexao-sponsor-migration' ); ?></h2>
				<p>
					<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<?php echo esc_html( _n( 'apoiador pronto para exportação', 'apoiadores prontos para exportação', $count, 'conexao-sponsor-migration' ) ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="conexao_export_sponsors">
					<?php wp_nonce_field( 'conexao_export_sponsors', 'conexao_export_nonce' ); ?>
					<?php submit_button( __( 'Baixar arquivo de exportação (.json)', 'conexao-sponsor-migration' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'O que é exportado', 'conexao-sponsor-migration' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:1.7;">
					<li><?php esc_html_e( 'Nome, descrição, status e slug de cada apoiador', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Categoria, tipo, link, destaque e ordem de exibição', 'conexao-sponsor-migration' ); ?></li>
					<li><strong><?php esc_html_e( 'Imagem Desktop (composição horizontal do carousel) — bytes da imagem embutidos no arquivo', 'conexao-sponsor-migration' ); ?></strong></li>
					<li><strong><?php esc_html_e( 'Imagem Mobile (composição vertical do carousel) — bytes da imagem embutidos no arquivo', 'conexao-sponsor-migration' ); ?></strong></li>
					<li><?php esc_html_e( 'Logo antigo (campo legado), quando existir', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Texto alternativo (alt) de cada imagem', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Um ID estável por apoiador e um hash estável por imagem (reimportações nunca duplicam)', 'conexao-sponsor-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the import page.
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$imported = isset( $_GET['conexao_sponsor_imported'] ) ? (int) $_GET['conexao_sponsor_imported'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a completed action's result.
		$updated  = isset( $_GET['conexao_sponsor_updated'] ) ? (int) $_GET['conexao_sponsor_updated'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped  = isset( $_GET['conexao_sponsor_skipped'] ) ? (int) $_GET['conexao_sponsor_skipped'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed   = isset( $_GET['conexao_sponsor_failed'] ) ? (int) $_GET['conexao_sponsor_failed'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$images   = isset( $_GET['conexao_sponsor_images'] ) ? (int) $_GET['conexao_sponsor_images'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$images_reuse = isset( $_GET['conexao_sponsor_images_reuse'] ) ? (int) $_GET['conexao_sponsor_images_reuse'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$errors   = isset( $_GET['conexao_sponsor_import_errors'] ) ? json_decode( rawurldecode( wp_unslash( $_GET['conexao_sponsor_import_errors'] ) ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error    = isset( $_GET['conexao_sponsor_import_error'] ) ? sanitize_key( wp_unslash( $_GET['conexao_sponsor_import_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$max_upload_bytes = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );

		$has_result = null !== $imported;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Importar Apoiadores', 'conexao-sponsor-migration' ); ?></h1>
			<p><?php esc_html_e( 'Envie um arquivo de exportação de apoiadores (.json) para importá-los nesta instalação. As imagens Desktop e Mobile são recriadas como anexos locais na Biblioteca de Mídia e atribuídas ao apoiador correto.', 'conexao-sponsor-migration' ); ?></p>

			<?php if ( 'no_file' === $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Escolha um arquivo de exportação (.json) para enviar.', 'conexao-sponsor-migration' ); ?></p></div>
			<?php elseif ( 'too_large' === $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( sprintf( __( 'O arquivo selecionado excede o tamanho máximo de envio (%s). Aumente os limites PHP upload_max_filesize / post_max_size ou divida o arquivo de exportação.', 'conexao-sponsor-migration' ), size_format( $max_upload_bytes ) ) ); ?></p>
				</div>
			<?php elseif ( 'post_max_size' === $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'O envio foi rejeitado pelo servidor porque excedeu o limite de tamanho do POST. Aumente o limite PHP post_max_size ou divida o arquivo de exportação.', 'conexao-sponsor-migration' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $has_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Importação concluída', 'conexao-sponsor-migration' ); ?></strong></p>
					<ul style="list-style:disc;padding-left:20px;">
						<li><?php echo esc_html( sprintf( __( 'Criados: %d', 'conexao-sponsor-migration' ), $imported ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Atualizados: %d', 'conexao-sponsor-migration' ), $updated ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Ignorados: %d', 'conexao-sponsor-migration' ), $skipped ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Falhas: %d', 'conexao-sponsor-migration' ), $failed ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Imagens importadas: %d', 'conexao-sponsor-migration' ), $images ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Imagens reaproveitadas: %d', 'conexao-sponsor-migration' ), $images_reuse ) ); ?></li>
					</ul>
					<?php if ( ! empty( $errors ) && is_array( $errors ) ) : ?>
						<p><strong><?php esc_html_e( 'Erros:', 'conexao-sponsor-migration' ); ?></strong></p>
						<ul style="list-style:disc;padding-left:20px;color:#b32d2e;">
							<?php foreach ( $errors as $error_message ) : ?>
								<li><?php echo esc_html( $error_message ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;max-width:720px;">
				<h2><?php esc_html_e( 'Enviar arquivo de exportação', 'conexao-sponsor-migration' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="conexao_import_sponsors">
					<?php wp_nonce_field( 'conexao_import_sponsors', 'conexao_import_nonce' ); ?>

					<p>
						<label for="conexao_sponsor_import_file"><strong><?php esc_html_e( 'Arquivo de exportação (.json)', 'conexao-sponsor-migration' ); ?></strong></label><br>
						<input type="file" name="conexao_sponsor_import_file" id="conexao_sponsor_import_file" accept=".json,application/json" required>
						<span class="description"><?php echo esc_html( sprintf( __( 'Tamanho máximo de envio: %s.', 'conexao-sponsor-migration' ), size_format( $max_upload_bytes ) ) ); ?></span>
					</p>
					<p>
						<label for="conexao_duplicate_strategy"><strong><?php esc_html_e( 'Se um apoiador já existir', 'conexao-sponsor-migration' ); ?></strong></label><br>
						<select name="conexao_duplicate_strategy" id="conexao_duplicate_strategy">
							<option value="update"><?php esc_html_e( 'Atualizar o apoiador existente', 'conexao-sponsor-migration' ); ?></option>
							<option value="skip"><?php esc_html_e( 'Ignorar o apoiador existente', 'conexao-sponsor-migration' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( 'Os apoiadores são identificados pelo UUID estável de exportação, depois pelo slug e pelo título.', 'conexao-sponsor-migration' ); ?></span>
					</p>

					<?php submit_button( __( 'Importar Apoiadores', 'conexao-sponsor-migration' ), 'primary', 'submit', false ); ?>
				</form>
				<script>
					(function () {
						var input = document.getElementById('conexao_sponsor_import_file');
						var form = input ? input.closest('form') : null;
						if (!input || !form) return;
						form.addEventListener('submit', function (event) {
							var maxBytes = <?php echo (int) $max_upload_bytes; ?>;
							if (maxBytes && input.files.length && input.files[0].size > maxBytes) {
								event.preventDefault();
								window.alert(<?php echo wp_json_encode( sprintf( __( 'O arquivo selecionado é maior que o tamanho máximo de envio (%s). Escolha um arquivo menor ou aumente os limites PHP.', 'conexao-sponsor-migration' ), size_format( $max_upload_bytes ) ) ); ?>);
							}
						});
					})();
				</script>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;margin-top:12px;max-width:720px;">
				<h2><?php esc_html_e( 'O que acontece na importação', 'conexao-sponsor-migration' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:1.7;">
					<li><?php esc_html_e( 'O arquivo é validado antes que qualquer dado seja gravado.', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'As imagens Desktop e Mobile são criadas como anexos locais na Biblioteca de Mídia.', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Imagens idênticas são reaproveitadas (sem duplicatas) via hash estável de conteúdo.', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'A imagem destacada de cada apoiador segue a mesma cadeia de fallback do site público: Desktop → Mobile → logo legado.', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Apoiadores existentes são atualizados ou ignorados conforme sua escolha.', 'conexao-sponsor-migration' ); ?></li>
					<li><?php esc_html_e( 'Importar o mesmo arquivo duas vezes não cria duplicatas.', 'conexao-sponsor-migration' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}