<?php
/**
 * The header for our theme
 *
 * @package Conexao_BR_Irlanda
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="https://gmpg.org/xfn/11">
<script>
/* Theme initialization — runs before paint to prevent FOUC. */
(function() {
	try {
		var stored = localStorage.getItem('conexao-theme');
		var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
		var theme = stored || (prefersDark ? 'dark' : 'light');
		document.documentElement.setAttribute('data-theme', theme);
	} catch (e) {
		document.documentElement.setAttribute('data-theme', 'light');
	}
})();
</script>
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Pular para o conteúdo', 'conexao-br-irlanda' ); ?></a>

	<header id="masthead" class="site-header">
		<?php
		$instagram = get_theme_mod( 'conexao_instagram', 'https://www.instagram.com/conexaobr.ie/' );
		$whatsapp  = get_theme_mod( 'conexao_whatsapp', 'https://wa.me/353899451428' );
		$facebook  = get_theme_mod( 'conexao_facebook', '' );
		?>

		<!-- Main Header -->
		<div class="main-header">
			<div class="site-container">
				<!-- Mobile Menu Toggle -->
				<button class="mobile-menu-toggle" aria-label="<?php esc_attr_e( 'Abrir menu', 'conexao-br-irlanda' ); ?>" aria-controls="mobile-menu" aria-expanded="false">
					<span class="hamburger-line"></span>
					<span class="hamburger-line"></span>
					<span class="hamburger-line"></span>
				</button>

				<!-- Logo -->
				<div class="logo">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" class="site-logo-link">
						<picture>
							<source type="image/webp" srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/logo_conexao_br_irlanda.webp' ); ?>">
							<img
								src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/logo_conexao_br_irlanda.jpeg' ); ?>"
								width="480"
								height="200"
								alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
								class="site-logo-img site-logo-img--light"
								loading="eager"
							>
						</picture>
						<picture>
							<source type="image/webp" srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/logo_dark_mode.webp' ); ?>">
							<img
								src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/logo_dark_mode.jpeg' ); ?>"
								width="480"
								height="180"
								alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
								class="site-logo-img site-logo-img--dark"
							>
						</picture>
					</a>
				</div>

				<!-- Primary Navigation -->
				<nav id="site-navigation" class="primary-navigation" aria-label="<?php esc_attr_e( 'Menu Principal', 'conexao-br-irlanda' ); ?>">
					<?php
					wp_nav_menu( array(
						'theme_location' => 'primary',
						'menu_id'        => 'primary-menu',
						'container'      => false,
						'fallback_cb'    => 'wp_page_menu',
						'depth'          => 3,
					) );
					?>
				</nav>

				<!-- Header Actions -->
				<div class="header-actions">
					<button type="button" class="theme-toggle" aria-label="<?php esc_attr_e( 'Alternar tema claro/escuro', 'conexao-br-irlanda' ); ?>" aria-pressed="false">
						<span class="theme-toggle-icon theme-toggle-icon--moon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
						</span>
						<span class="theme-toggle-icon theme-toggle-icon--sun" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
						</span>
					</button>
					<button type="button" class="mobile-search-toggle" aria-label="<?php esc_attr_e( 'Abrir pesquisa', 'conexao-br-irlanda' ); ?>" aria-controls="mobile-search" aria-expanded="false">
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					</button>
					<div class="header-search">
						<svg class="header-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="11" cy="11" r="8"></circle>
							<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
						</svg>
						<form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
							<label class="screen-reader-text" for="header-search-field"><?php esc_html_e( 'Pesquisar', 'conexao-br-irlanda' ); ?></label>
							<input id="header-search-field" type="search" class="search-field" placeholder="<?php esc_attr_e( 'Buscar no portal...', 'conexao-br-irlanda' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
							<button type="submit" class="screen-reader-text header-search-submit"><?php esc_html_e( 'Buscar', 'conexao-br-irlanda' ); ?></button>
						</form>
					</div>
					<a href="<?php echo esc_url( home_url( '/anuncie/' ) ); ?>" class="header-cta">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="17 8 12 3 7 8"></polyline>
							<line x1="12" y1="3" x2="12" y2="15"></line>
						</svg>
						<?php esc_html_e( 'Anuncie Aqui', 'conexao-br-irlanda' ); ?>
					</a>
				</div>
			</div>
		</div>
	</header>

	<!-- Mobile Menu Overlay -->
	<div id="mobile-menu" class="mobile-menu-overlay" aria-hidden="true">
		<div class="mobile-menu-content">
			<div class="mobile-menu-header">
				<span class="mobile-menu-title"><?php esc_html_e( 'Menu', 'conexao-br-irlanda' ); ?></span>
				<button class="mobile-menu-close" aria-label="<?php esc_attr_e( 'Fechar menu', 'conexao-br-irlanda' ); ?>">
					<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
					</svg>
				</button>
			</div>
			<div class="mobile-menu-search">
				<form role="search" method="get" class="mobile-menu-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
					<label class="screen-reader-text" for="mobile-menu-search-field"><?php esc_html_e( 'Pesquisar', 'conexao-br-irlanda' ); ?></label>
					<input id="mobile-menu-search-field" type="search" class="mobile-menu-search-field" placeholder="<?php esc_attr_e( 'Buscar no portal...', 'conexao-br-irlanda' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
					<button type="submit" class="mobile-menu-search-submit" aria-label="<?php esc_attr_e( 'Buscar', 'conexao-br-irlanda' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
					</button>
				</form>
			</div>
			<nav class="mobile-menu-nav" aria-label="<?php esc_attr_e( 'Menu Mobile', 'conexao-br-irlanda' ); ?>">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'menu_class'     => 'mobile-menu',
					'container'      => false,
					'fallback_cb'    => 'wp_page_menu',
					'depth'          => 3,
				) );
				?>
			</nav>
			<div class="mobile-menu-footer">
				<div class="mobile-menu-social">
					<?php if ( $instagram ) : ?>
						<a href="<?php echo esc_url( $instagram ); ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
						</a>
					<?php endif; ?>
					<?php if ( $whatsapp ) : ?>
						<a href="<?php echo esc_url( $whatsapp ); ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div id="mobile-search" class="mobile-search-overlay" aria-hidden="true">
		<div class="mobile-search-overlay-content" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Pesquisar no site', 'conexao-br-irlanda' ); ?>">
			<form role="search" method="get" class="mobile-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="mobile-search-field"><?php esc_html_e( 'Pesquisar', 'conexao-br-irlanda' ); ?></label>
				<input id="mobile-search-field" type="search" class="mobile-search-field" placeholder="<?php esc_attr_e( 'Buscar no portal...', 'conexao-br-irlanda' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
				<button type="submit" class="mobile-search-submit"><?php esc_html_e( 'Buscar', 'conexao-br-irlanda' ); ?></button>
				<button type="button" class="mobile-search-close" aria-label="<?php esc_attr_e( 'Fechar pesquisa', 'conexao-br-irlanda' ); ?>">&times;</button>
			</form>
		</div>
	</div>

	<div id="content" class="site-content">
		<?php conexao_seo_breadcrumbs(); ?>