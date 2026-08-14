<?php
/**
 * Plugin Name: Conexão BR Irlanda Content
 * Description: Editable cards, guides, share controls, and default pages for the Conexão BR Irlanda migration.
 * Version: 1.0.0
 * Text Domain: conexao-content
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_BR_Content {
	const META_URL = '_conexao_external_url';
	const META_ORDER = '_conexao_display_order';

	public function __construct() {
		add_action( 'init', array( $this, 'register_content' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'init', array( $this, 'register_routes' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( $this, 'redirect_legacy_paths' ) );
		add_filter( 'post_link', array( $this, 'post_permalink' ), 10, 2 );
		add_shortcode( 'conexao_grid', array( $this, 'grid_shortcode' ) );
		add_shortcode( 'conexao_blog_categories', array( $this, 'blog_categories_shortcode' ) );
		add_filter( 'the_content', array( $this, 'append_sharing' ) );
		add_filter( 'the_content', array( $this, 'append_reading_time' ), 8 );
	}

	public function register_content() {
		$types = array(
			'directory_item' => array( 'name' => 'Diretório', 'singular_name' => 'Item do diretório', 'has_archive' => false ),
			'curated_link'   => array( 'name' => 'Links selecionados', 'singular_name' => 'Link selecionado', 'has_archive' => false ),
		);
		foreach ( $types as $type => $labels ) {
			register_post_type( $type, array(
				'labels' => $labels + array( 'add_new_item' => 'Adicionar ' . $labels['singular_name'], 'edit_item' => 'Editar ' . $labels['singular_name'] ),
				'public' => true, 'show_in_rest' => true, 'menu_icon' => isset( $labels['menu_icon'] ) ? $labels['menu_icon'] : 'dashicons-screenoptions',
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
				'has_archive' => $labels['has_archive'], 'rewrite' => isset( $labels['rewrite'] ) ? $labels['rewrite'] : true,
			) );
		}
		register_taxonomy( 'directory_category', 'directory_item', array( 'labels' => array( 'name' => 'Categorias do diretório', 'singular_name' => 'Categoria do diretório' ), 'public' => true, 'show_in_rest' => true ) );
		register_taxonomy( 'curated_group', 'curated_link', array( 'labels' => array( 'name' => 'Grupos de links', 'singular_name' => 'Grupo de links' ), 'public' => true, 'show_in_rest' => true ) );
	}

	public function register_meta() {
		foreach ( array( self::META_URL, self::META_ORDER ) as $key ) {
			register_post_meta( '', $key, array( 'single' => true, 'type' => self::META_ORDER === $key ? 'integer' : 'string', 'show_in_rest' => true, 'auth_callback' => function () { return current_user_can( 'edit_posts' ); } ) );
		}
	}

	public function register_routes() {
		// Rewrite /blog/ to the posts archive (paginated).
		add_rewrite_rule( '^blog/?$', 'index.php?post_type=post', 'top' );
		add_rewrite_rule( '^blog/page/([0-9]+)/?$', 'index.php?post_type=post&paged=$matches[1]', 'top' );
		// Individual post URLs: /blog/{post-name}/
		add_rewrite_rule( '^blog/([^/]+)/?$', 'index.php?name=$matches[1]', 'top' );
	}

	public function enqueue_assets() {
		// Only load plugin CSS where needed:
		// 1. Singular posts (sharing + reading-time markup is appended).
		// 2. Pages using the plugin's shortcodes.
		$should_load = is_singular( 'post' );
		if ( ! $should_load && is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( $post->post_content, 'conexao_grid' ) || has_shortcode( $post->post_content, 'conexao_blog_categories' ) ) ) {
				$should_load = true;
			}
		}
		if ( $should_load ) {
			wp_enqueue_style( 'conexao-content', plugins_url( 'assets.css', __FILE__ ), array(), '1.0.0' );
		}
	}

	public function post_permalink( $permalink, $post ) {
		return 'post' === $post->post_type ? home_url( '/blog/' . $post->post_name . '/' ) : $permalink;
	}

	public function redirect_legacy_paths() {
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		// Legacy Wix redirects. All point DIRECTLY to the final destination
		// (no redirect chains). '/contato' is intentionally omitted: the page
		// now exists as /contato/, and redirecting to it would create a loop.
		$redirects = array(
			'/turismo-e-lazer'   => '/events/',
			'/capacitação'       => '/courses/',
			'/cursos'            => '/courses/',
			'/s-projects-basic'  => '/guides/',
			'/guias'             => '/guides/',
			'/eventos'           => '/events/',
			'/empregos'          => '/jobs/',
			'/empresas'          => '/apoiadores/',
			'/businesses'        => '/apoiadores/',
			'/privacidade'       => '/politica-de-privacidade/',
			'/termos'            => '/termos-de-uso/',
			'/sobre'             => '/sobre-nos/',
		);
		$path = untrailingslashit( rawurldecode( $path ) );
		foreach ( $redirects as $from => $to ) if ( untrailingslashit( $from ) === $path ) { wp_safe_redirect( home_url( $to ), 301 ); exit; }
		if ( preg_match( '#^/post/([^/]+)$#', $path, $matches ) ) { wp_safe_redirect( home_url( '/blog/' . $matches[1] . '/' ), 301 ); exit; }
		if ( preg_match( '#^/guias-praticos/([^/]+)$#', $path, $matches ) ) { wp_safe_redirect( home_url( '/guides/' . $matches[1] . '/' ), 301 ); exit; }
		if ( preg_match( '#^/categories/([^/]+)$#', $path, $matches ) ) { wp_safe_redirect( home_url( '/' . $matches[1] . '/' ), 301 ); exit; }
		if ( preg_match( '#^/counties/([^/]+)$#', $path, $matches ) ) { wp_safe_redirect( home_url( '/' . $matches[1] . '/' ), 301 ); exit; }
	}

	public function add_meta_boxes() {
		foreach ( array( 'directory_item', 'curated_link' ) as $type ) {
			add_meta_box( 'conexao-card-details', 'Detalhes do cartão', array( $this, 'card_fields' ), $type, 'side' );
		}
	}

	public function card_fields( $post ) {
		wp_nonce_field( 'conexao_card_meta', 'conexao_card_nonce' );
		$url = get_post_meta( $post->ID, self::META_URL, true ); $order = get_post_meta( $post->ID, self::META_ORDER, true );
		echo '<p><label for="conexao_external_url">URL de destino</label><input class="widefat" type="url" id="conexao_external_url" name="conexao_external_url" value="' . esc_attr( $url ) . '" placeholder="https://" /></p>';
		echo '<p><label for="conexao_display_order">Ordem de exibição</label><input class="widefat" type="number" min="0" id="conexao_display_order" name="conexao_display_order" value="' . esc_attr( $order ) . '" /></p>';
		echo '<p class="description">Defina uma imagem destacada para o cartão.</p>';
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['conexao_card_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_card_nonce'] ) ), 'conexao_card_meta' ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
		if ( isset( $_POST['conexao_external_url'] ) ) update_post_meta( $post_id, self::META_URL, esc_url_raw( wp_unslash( $_POST['conexao_external_url'] ) ) );
		if ( isset( $_POST['conexao_display_order'] ) ) update_post_meta( $post_id, self::META_ORDER, absint( $_POST['conexao_display_order'] ) );
	}

	public function grid_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'type' => 'sponsor', 'group' => '', 'limit' => -1 ), $atts, 'conexao_grid' );
		$allowed = array( 'sponsor', 'directory_item', 'curated_link' ); if ( ! in_array( $atts['type'], $allowed, true ) ) return '';
		$args = array( 'post_type' => $atts['type'], 'posts_per_page' => (int) $atts['limit'], 'meta_key' => self::META_ORDER, 'orderby' => array( 'meta_value_num' => 'ASC', 'title' => 'ASC' ), 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
		if ( $atts['group'] && in_array( $atts['type'], array( 'directory_item', 'curated_link' ), true ) ) $args['tax_query'] = array( array( 'taxonomy' => 'directory_item' === $atts['type'] ? 'directory_category' : 'curated_group', 'field' => 'slug', 'terms' => sanitize_title( $atts['group'] ) ) );
		$query = new WP_Query( $args ); if ( ! $query->have_posts() ) return current_user_can( 'edit_posts' ) ? '<p>Adicione itens em <strong>' . esc_html( $atts['type'] ) . '</strong> para exibi-los aqui.</p>' : '';
		$html = '<div class="conexao-card-grid conexao-card-grid--' . esc_attr( $atts['type'] ) . '">';
		while ( $query->have_posts() ) { $query->the_post(); $url = get_post_meta( get_the_ID(), self::META_URL, true ); $tag = $url ? 'a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"' : 'div'; $html .= '<article class="conexao-card"><' . $tag . '>' . get_the_post_thumbnail( get_the_ID(), 'medium_large', array( 'alt' => get_the_title() ) ) . '<h3>' . esc_html( get_the_title() ) . '</h3></' . ( $url ? 'a' : 'div' ) . '></article>'; }
		wp_reset_postdata(); return $html . '</div>';
	}

	/** Displays the blog filters as a compact, accessible horizontal navigation. */
	public function blog_categories_shortcode() {
		$categories = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( empty( $categories ) ) return '';
		$html = '<nav class="conexao-blog-categories" aria-label="Categorias do blog"><a href="' . esc_url( home_url( '/blog/' ) ) . '">Todos</a>';
		foreach ( $categories as $category ) {
			$html .= '<a href="' . esc_url( get_category_link( $category ) ) . '">' . esc_html( $category->name ) . '</a>';
		}
		return $html . '</nav>';
	}

	public function append_reading_time( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
		$minutes = max( 1, (int) ceil( str_word_count( wp_strip_all_tags( $content ) ) / 200 ) );
		return '<p class="conexao-reading-time">' . esc_html( $minutes ) . ' min de leitura</p>' . $content;
	}

	public function append_sharing( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
		$url = rawurlencode( get_permalink() ); $title = rawurlencode( get_the_title() );
		$links = array( 'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $url, 'X' => 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title, 'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url );
		$html = '<nav class="conexao-share" aria-label="Compartilhar publicação"><span>Compartilhe:</span>';
		foreach ( $links as $label => $href ) $html .= '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
		$html .= '<button type="button" data-copy-link="' . esc_attr( get_permalink() ) . '">Copiar link</button></nav>';
		return $content . $html;
	}

	public function ensure_default_guides() {
		if ( ! get_page_by_path( 'guia-para-quem-esta-com-problemas-financeiros-na-irlanda', OBJECT, 'guide' ) ) {
			wp_insert_post(
				array(
					'post_type'    => 'guide',
					'post_status'  => 'publish',
					'post_title'   => 'Guia para quem está com problemas financeiros na Irlanda',
					'post_name'    => 'guia-para-quem-esta-com-problemas-financeiros-na-irlanda',
					'post_excerpt' => 'Orientações iniciais, recursos úteis e pontos de apoio para brasileiros enfrentando dificuldades financeiras na Irlanda.',
					'post_content' => '<!-- wp:paragraph --><p>Use este guia para reunir informações verificadas sobre apoio financeiro, orçamento doméstico, Citizens Information, organizações locais e serviços de emergência na Irlanda.</p><!-- /wp:paragraph --><!-- wp:list --><ul><li>Organize despesas essenciais e dívidas prioritárias.</li><li>Busque orientação em serviços públicos e entidades de apoio local.</li><li>Atualize este conteúdo com telefones, links oficiais e serviços da sua região.</li></ul><!-- /wp:list -->',
				)
			);
		}
	}
}

new Conexao_BR_Content();

/**
 * Migrate the 'cursos' page slug to 'courses'.
 * This runs on init to ensure the page slug is updated for existing installations.
 */
function conexao_migrate_cursos_slug() {
	static $done = false;
	if ( $done ) return;
	$done = true;
	
	$cursos_page = get_page_by_path( 'cursos' );
	if ( $cursos_page && $cursos_page->post_name !== 'courses' ) {
		wp_update_post( array(
			'ID'        => $cursos_page->ID,
			'post_name' => 'courses',
		) );
		flush_rewrite_rules();
	}
}
add_action( 'init', 'conexao_migrate_cursos_slug', 5 );

register_activation_hook( __FILE__, function () {
	$plugin = new Conexao_BR_Content(); $plugin->register_content(); $plugin->register_routes(); flush_rewrite_rules();
	$pages = array(
		'blog' => array( 'BLOG', '' ),
		'eventos' => array( 'EVENTOS', '<!-- wp:heading --><h2>Eventos para a comunidade brasileira na Irlanda</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Recomendações selecionadas de atividades, passeios e bem-estar.</p><!-- /wp:paragraph --><!-- wp:heading {"level":3} --><h3>Família &amp; Crianças</h3><!-- /wp:heading --><!-- wp:shortcode -->[conexao_grid type="curated_link" group="familia-e-criancas"]<!-- /wp:shortcode --><!-- wp:heading {"level":3} --><h3>Lazer &amp; Social</h3><!-- /wp:heading --><!-- wp:shortcode -->[conexao_grid type="curated_link" group="lazer-e-social"]<!-- /wp:shortcode --><!-- wp:heading {"level":3} --><h3>Bem-estar &amp; Natureza</h3><!-- /wp:heading --><!-- wp:shortcode -->[conexao_grid type="curated_link" group="bem-estar-e-natureza"]<!-- /wp:shortcode -->' ),
		'courses' => array( 'CURSOS', '<!-- wp:heading --><h2>Encontre cursos, formação e oportunidades de aprendizagem na Irlanda</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Uma diretoria curada de instituições e plataformas de ensino confiáveis na Irlanda. Cada cartão leva você diretamente ao site oficial do provedor.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[conexao_course_providers]<!-- /wp:shortcode -->' ),
		'contato' => array( 'CONTATO', '<!-- wp:heading --><h2>Tem uma sugestão ou dúvida? Nos mande uma mensagem</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Insira aqui o bloco do formulário escolhido (WPForms ou Contact Form 7). Configure as notificações para o e-mail do proprietário do site e habilite a proteção antispam.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><a href="https://wa.me/353899451428">Fale conosco pelo WhatsApp</a></p><!-- /wp:paragraph -->' ),
	);
	foreach ( $pages as $slug => $page ) if ( ! get_page_by_path( $slug ) ) wp_insert_post( array( 'post_title' => $page[0], 'post_name' => $slug, 'post_content' => $page[1], 'post_status' => 'publish', 'post_type' => 'page' ) );
	// Blog categories relevant to the Brazilian community in Ireland.
	// These categories cover the main content areas for the blog.
	$blog_categories = array(
		'Irlanda',
		'Vida na Irlanda',
		'Trabalho',
		'Imigração',
		'Comunidade',
		'Educação',
		'Finanças',
		'Moradia',
		'Família',
		'Notícias',
		'Saúde e Bem-estar',
		'Capacitação',
		'Empreendedor',
		'Receitas',
		'Lazer',
	);
	foreach ( $blog_categories as $name ) {
		if ( ! term_exists( $name, 'category' ) ) {
			wp_insert_term( $name, 'category' );
		}
	}
	foreach ( array( 'Serviços Profissionais', 'Saúde e Bem-estar', 'Marketing e Negócios', 'Artes e craft', 'Alimentação', 'Informações' ) as $name ) if ( ! term_exists( $name, 'directory_category' ) ) wp_insert_term( $name, 'directory_category' );
	foreach ( array( 'Família e Crianças', 'Lazer e Social', 'Bem-estar e Natureza', 'Cursos', 'Movimento em Laois', 'Mundo Atípico' ) as $name ) if ( ! term_exists( $name, 'curated_group' ) ) wp_insert_term( $name, 'curated_group' );
	$plugin->ensure_default_guides();
} );
register_deactivation_hook( __FILE__, function () { flush_rewrite_rules(); } );
