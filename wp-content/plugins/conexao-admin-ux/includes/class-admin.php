<?php
/**
 * Main admin orchestrator for the Conexão Admin UX.
 *
 * Boots the reusable list + editor components for every supported content
 * type, and handles the row-level actions (duplicate, archive, delete).
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux {

	/** @var Conexao_Admin_Ux|null */
	private static $instance = null;

	/** @var Conexao_Admin_Ux_List[] */
	private $lists = array();

	/** @var Conexao_Admin_Ux_Editor[] */
	private $editors = array();

	/**
	 * Singleton.
	 *
	 * @return Conexao_Admin_Ux
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'init_components' ) );
		add_action( 'admin_init', array( $this, 'handle_row_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_list_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_empty_state' ) );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'bypass_empty_content_guard_for_editor' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor_for_post_type' ), 100, 2 );
		add_filter( 'use_block_editor_for_post', array( $this, 'force_classic_editor_for_post' ), 100, 2 );
		add_filter( 'post_type_labels_event', array( $this, 'event_labels' ) );
		add_filter( 'post_type_labels_guide', array( $this, 'guide_labels' ) );
		add_filter( 'post_type_labels_job', array( $this, 'job_labels' ) );
		add_action( 'wp_untrash_post_status', array( $this, 'restore_original_status_on_untrash' ), 10, 3 );
		add_filter( 'post_type_labels_sponsor', array( $this, 'sponsor_labels' ) );
		add_filter( 'post_type_labels_course_provider', array( $this, 'course_provider_labels' ) );
	}

	/**
	 * Bypass core's "empty content" guard for Admin UX editor submissions.
	 *
	 * Root cause of the first-save data loss on brand-new records:
	 *
	 * The sectioned editor stores the real title/description in
	 * `conexao_fields[...]` and renders hidden mirror inputs (`post_title`,
	 * `content`) that carry the CURRENT post values. On a brand-new post
	 * (auto-draft) those mirrors are empty and no excerpt input exists, so a
	 * first submission reaches `edit_post()` → `wp_update_post()` with empty
	 * title + content + excerpt. Because every managed post type supports
	 * title, editor AND excerpt, `wp_insert_post()` treats the update as
	 * "empty" and aborts BEFORE firing any hook — `save_post_{type}` never
	 * runs, so `Conexao_Admin_Ux_Editor::save()` never persists the
	 * conexao_fields data. The user sees a success redirect while only
	 * `_edit_last` was written. On the second save the mirror carries the
	 * existing title, the guard passes, and everything saves — which is why
	 * updating worked while creating did not.
	 *
	 * When one of our editor forms is being submitted (valid nonce), return
	 * false so the normal insert/update proceeds and the standard
	 * `save_post_{type}` hooks fire with the full $_POST payload. This makes
	 * the very first save behave exactly like an update: one request, all
	 * fields persisted.
	 *
	 * Everything else (autosaves, Quick Edit, bulk edit, REST, importers,
	 * front-end) keeps core's default behavior.
	 *
	 * @param bool  $maybe_empty Whether the post is considered "empty".
	 * @param array $postarr     Post data being inserted/updated.
	 * @return bool
	 */
	public function bypass_empty_content_guard_for_editor( $maybe_empty, $postarr ) {
		if ( ! $maybe_empty ) {
			return $maybe_empty;
		}

		// Never interfere with autosaves; they must keep core semantics.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $maybe_empty;
		}

		// Only relevant to wp-admin form submissions.
		if ( ! is_admin() || empty( $_POST['action'] ) || 'editpost' !== sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
			return $maybe_empty;
		}

		// Must be one of the post types this plugin manages.
		$post_type = isset( $postarr['post_type'] ) ? sanitize_key( $postarr['post_type'] ) : '';
		if ( '' === $post_type || ! in_array( $post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return $maybe_empty;
		}

		// Must be an existing post (auto-draft) reached from our editor form,
		// authenticated with the editor nonce. Without the nonce we keep the
		// default guard so unauthenticated/foreign requests are unaffected.
		if ( empty( $postarr['ID'] ) || ! isset( $_POST['conexao_admin_ux_nonce'] ) ) {
			return $maybe_empty;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_admin_ux_nonce'] ) ), 'conexao_admin_ux_save' ) ) {
			return $maybe_empty;
		}

		return false;
	}

	/**
	 * Boot the reusable list + editor for each supported content type.
	/**
	 * Force the classic editor for the content types managed by Admin UX.
	 *
	 * Root cause of the broken Empregos (and Eventos/Guias/Apoiadores) CRUD:
	 *
	 * The Admin UX editor is a classic meta-box implementation. It renders
	 * its own sectioned form fields and a custom publish box, and persists
	 * everything through the classic wp-admin flow: the edit form POSTs to
	 * post.php, the $_POST payload is read inside `save_post_{type}`, and
	 * virtual title/description fields are mapped onto post_title/content.
	 *
	 * When the block editor is active instead, edit-form-advanced.php's main
	 * `<form name="post">` is never rendered (meta boxes are printed inside
	 * non-submitting `metabox-location-*` wrapper forms and saving happens
	 * through the REST API). Consequences on those screens:
	 *   - The custom "Publicar"/"Atualizar" buttons submit nothing.
	 *   - `$_POST['conexao_fields']` never exists during the REST save, so
	 *     `Conexao_Admin_Ux_Editor::save()` persists no custom fields and
	 *     the virtual title/description are never written.
	 *   - Core title/content edits bypass the structured editor entirely.
	 * Net effect: creating or editing a record "succeeds" while losing all
	 * admin-UX-managed data — the Empregos admin appeared non-functional.
	 *
	 * This filter restores the classic editor (and thus the working classic
	 * save flow) for the supported content types only. Posts, pages and any
	 * unmanaged post types keep the block editor untouched.
	 *
	 * @param bool   $use_block_editor Whether the post type uses the block editor.
	 * @param string $post_type        Post type name.
	 * @return bool
	 */
	public function force_classic_editor_for_post_type( $use_block_editor, $post_type ) {
		if ( in_array( $post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Same as force_classic_editor_for_post_type() but for a specific post
	 * being loaded (e.g. edit.php/post.php on an existing Emprego record).
	 *
	 * @param bool    $use_block_editor Whether the post uses the block editor.
	 * @param WP_Post $post             Post object.
	 * @return bool
	 */
	public function force_classic_editor_for_post( $use_block_editor, $post ) {
		if ( $post instanceof WP_Post
			&& in_array( $post->post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Restore the original status when a managed post is taken out of the
	 * Trash. WordPress defaults to "draft" on restore; for the Admin UX
	 * content types the archive/publish status is part of the record, so
	 * restoring a previously published Emprego should put it back to
	 * publish (mirrors the pre-trash state, including the custom status).
	 *
	 * @param string $new_status      Status to apply after untrash.
	 * @param int    $post_id         ID of the post being restored.
	 * @param string $previous_status Status before the post was trashed.
	 * @return string
	 */
	public function restore_original_status_on_untrash( $new_status, $post_id, $previous_status ) {
		if ( in_array( get_post_type( $post_id ), Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true )
			&& in_array( $previous_status, array( 'publish', 'future', 'private' ), true ) ) {
			return $previous_status;
		}

		return $new_status;
	}

	/**
	 * Boot the reusable list + editor for each supported content type.
	 */
	public function init_components() {
		foreach ( Conexao_Admin_Ux_Config::SUPPORTED_TYPES as $post_type ) {
			$list   = new Conexao_Admin_Ux_List( $post_type );
			$editor = new Conexao_Admin_Ux_Editor( $post_type );

			$list->register();
			$editor->register();

			$this->lists[ $post_type ]   = $list;
			$this->editors[ $post_type ] = $editor;
		}
	}

	/**
	 * Handle row-level actions (duplicate, archive, delete).
	 */
	public function handle_row_actions() {
		if ( ! is_admin() || empty( $_GET['conexao_action'] ) || empty( $_GET['post'] ) ) {
			return;
		}

		$action    = sanitize_key( wp_unslash( $_GET['conexao_action'] ) );
		$post_id   = absint( $_GET['post'] );
		$post_type = get_post_type( $post_id );

		if ( ! in_array( $post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return;
		}

		$nonce_key = 'conexao_' . $action . '_' . $post_id;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_key ) ) {
			wp_die( esc_html__( 'Link inválido. Tente novamente.', 'conexao-admin-ux' ) );
		}

		$config = Conexao_Admin_Ux_Config::get( $post_type );
		$labels = $config['labels'];

		switch ( $action ) {
			case 'duplicate':
				$new_id = Conexao_Admin_Ux_Actions::duplicate( $post_id, $post_type );
				if ( is_wp_error( $new_id ) ) {
					wp_die( esc_html( $new_id->get_error_message() ) );
				}
				wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit&conexao_notice=duplicated' ) );
				exit;

			case 'archive':
				Conexao_Admin_Ux_Actions::archive( $post_id, $post_type );
				wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type . '&conexao_notice=archived' ) );
				exit;

			case 'delete':
				Conexao_Admin_Ux_Actions::delete( $post_id, $post_type );
				wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type . '&conexao_notice=deleted' ) );
				exit;
		}
	}

	/**
	 * Enqueue list-screen assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_list_assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( ! in_array( $post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return;
		}

		wp_enqueue_style( 'conexao-admin-ux', CONEXAO_ADMIN_UX_URL . 'assets/admin.css', array(), CONEXAO_ADMIN_UX_VERSION );
		wp_enqueue_script( 'conexao-admin-ux', CONEXAO_ADMIN_UX_URL . 'assets/admin.js', array( 'jquery' ), CONEXAO_ADMIN_UX_VERSION, true );
		wp_localize_script(
			'conexao-admin-ux',
			'ConexaoAdminUx',
			array(
				'confirmDelete'  => __( 'Excluir permanentemente? Esta ação não pode ser desfeita.', 'conexao-admin-ux' ),
				'confirmArchive' => __( 'Arquivar este conteúdo? Ele deixará de aparecer no site público.', 'conexao-admin-ux' ),
			)
		);
	}

	/**
	 * Render a friendly empty state when a list has no posts.
	 */
	public function render_empty_state() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		$post_type = $screen->post_type;
		if ( ! in_array( $post_type, Conexao_Admin_Ux_Config::SUPPORTED_TYPES, true ) ) {
			return;
		}

		$config = Conexao_Admin_Ux_Config::get( $post_type );
		$count  = wp_count_posts( $post_type );
		$total  = 0;
		foreach ( (array) $count as $status => $n ) {
			$total += (int) $n;
		}

		if ( $total > 0 ) {
			return;
		}

		$add_url = admin_url( 'post-new.php?post_type=' . $post_type );
		?>
		<div class="conexao-empty-state" id="conexao-empty-state">
			<div class="conexao-empty-state-inner">
				<span class="conexao-empty-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<h2><?php echo esc_html( $config['labels']['empty_title'] ); ?></h2>
				<p><?php echo esc_html( $config['labels']['empty_message'] ); ?></p>
				<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary button-hero"><?php echo esc_html( $config['labels']['add_button'] ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Improve the event post type labels for the admin menu.
	 *
	 * @param object $labels Post type labels.
	 * @return object
	 */
	public function event_labels( $labels ) {
		$labels->menu_name        = 'Eventos';
		$labels->all_items        = 'Todos os Eventos';
		$labels->add_new          = 'Adicionar Evento';
		$labels->add_new_item     = 'Adicionar Evento';
		$labels->edit_item        = 'Editar Evento';
		$labels->new_item         = 'Novo Evento';
		$labels->view_item        = 'Ver Evento';
		$labels->search_items     = 'Buscar Eventos';
		$labels->not_found        = 'Nenhum evento encontrado';
		$labels->not_found_in_trash = 'Nenhum evento encontrado na lixeira';
		return $labels;
	}

	/**
	 * Improve the Guias post type labels.
	 *
	 * @param object $labels Post type labels.
	 * @return object
	 */
	public function guide_labels( $labels ) {
		$labels->menu_name        = 'Guias';
		$labels->all_items        = 'Todos os Guias';
		$labels->add_new          = 'Adicionar Guia';
		$labels->add_new_item     = 'Adicionar Guia';
		$labels->edit_item        = 'Editar Guia';
		$labels->new_item         = 'Novo Guia';
		$labels->view_item        = 'Ver Guia';
		$labels->search_items     = 'Buscar Guias';
		$labels->not_found        = 'Nenhum guia encontrado';
		$labels->not_found_in_trash = 'Nenhum guia encontrado na lixeira';
		return $labels;
	}

	/**
	 * Improve the Empregos post type labels.
	 *
	 * @param object $labels Post type labels.
	 * @return object
	 */
	public function job_labels( $labels ) {
		$labels->menu_name        = 'Empregos';
		$labels->all_items        = 'Todas as Vagas';
		$labels->add_new          = 'Adicionar Vaga';
		$labels->add_new_item     = 'Adicionar Vaga';
		$labels->edit_item        = 'Editar Vaga';
		$labels->new_item         = 'Nova Vaga';
		$labels->view_item        = 'Ver Vaga';
		$labels->search_items     = 'Buscar Vagas';
		$labels->not_found        = 'Nenhuma vaga encontrada';
		$labels->not_found_in_trash = 'Nenhuma vaga encontrada na lixeira';
		return $labels;
	}

	/**
	 * Improve the Apoiadores post type labels.
	 *
	 * @param object $labels Post type labels.
	 * @return object
	 */
	public function sponsor_labels( $labels ) {
		$labels->menu_name        = 'Apoiadores';
		$labels->all_items        = 'Todos os Apoiadores';
		$labels->add_new          = 'Adicionar Apoiador';
		$labels->add_new_item     = 'Adicionar Apoiador';
		$labels->edit_item        = 'Editar Apoiador';
		$labels->new_item         = 'Novo Apoiador';
		$labels->view_item        = 'Ver Apoiador';
		$labels->search_items     = 'Buscar Apoiadores';
		$labels->not_found        = 'Nenhum apoiador encontrado';
		$labels->not_found_in_trash = 'Nenhum apoiador encontrado na lixeira';
		return $labels;
	}

	/**
	 * Improve the Cursos (course providers) post type labels.
	 *
	 * @param object $labels Post type labels.
	 * @return object
	 */
	public function course_provider_labels( $labels ) {
		$labels->menu_name        = 'Cursos';
		$labels->all_items        = 'Todos os Provedores';
		$labels->add_new          = 'Adicionar Provedor';
		$labels->add_new_item     = 'Adicionar Provedor';
		$labels->edit_item        = 'Editar Provedor';
		$labels->new_item         = 'Novo Provedor';
		$labels->view_item        = 'Ver Provedor';
		$labels->search_items     = 'Buscar Provedores';
		$labels->not_found        = 'Nenhum provedor encontrado';
		$labels->not_found_in_trash = 'Nenhum provedor encontrado na lixeira';
		return $labels;
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}