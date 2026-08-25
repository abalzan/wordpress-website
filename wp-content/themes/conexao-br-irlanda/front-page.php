<?php
/**
 * Front Page Template
 *
 * @package Conexao_BR_Irlanda
 */

get_header();

// Get dynamic content or use defaults
$hero_title    = get_theme_mod( 'conexao_hero_title', __( 'Tudo que o brasileiro precisa para viver melhor na <span>Irlanda</span>', 'conexao-br-irlanda' ) );
$hero_subtitle = get_theme_mod( 'conexao_hero_subtitle', __( 'Conectando a comunidade brasileira com informações, eventos, guias práticos e muito mais.', 'conexao-br-irlanda' ) );
// The hero background is a responsive <picture> element (see below) that
// selects the correct asset based on viewport:
//   Desktop (≥769px): conexaobr_Hero_image.png  (3.71:1 composition with the
//                     green text-safe zone on the left — unchanged)
//   Mobile (≤768px):  conexaobr_Hero_image_mobile.webp/.png — dedicated
//                     1.8:1 full-bleed compositions (WebP 1080×600, PNG
//                     fallback 1683×935). On mobile the
//                     image covers the entire .hero-section and the copy sits
//                     on top of it, anchored bottom-left over the quiet lake
//                     area; a subtle green gradient (CSS only) keeps the text
//                     readable. WebP is served first, PNG is the fallback.
// All assets are committed project files loaded via
// get_template_directory_uri() so they resolve identically in local and
// production (no Media Library / localhost URL). See the <picture> inside
// the hero below and the mobile hero rules in assets/css/main.css.

// Featured Apoiadores for the Hero carousel. Data-driven from the EXISTING
// sponsor fields ("Apoiador em destaque" / "Ordem de exibição") via
// conexao_get_featured_sponsors() — see template-parts/featured-sponsors.php.
// Checked here so .hero-content can tighten its spacing only when the
// carousel will actually render (the transient-cached query makes this cheap).
$hero_has_sponsors = ! empty( conexao_get_featured_sponsors() );
?>

<main id="primary" class="site-main">

<!-- Hero Section -->
<section class="hero-section" aria-label="<?php esc_attr_e( 'Destaque da Comunidade', 'conexao-br-irlanda' ); ?>">

	<!-- Full-bleed cinematic hero image (project asset). The img is decorative:
	     the overlaid copy carries the page message, so alt="" + aria-hidden. -->
	<div class="hero-background" aria-hidden="true">
		<picture>
			<!-- Mobile (≤768px): dedicated 1.8:1 full-bleed compositions.
			     WebP first (1080×600, ≈128 KB), PNG fallback (1683×935 —
			     same 1.8:1 framing at higher resolution). The browser selects
			     the matching asset automatically — no JavaScript. The
			     width/height attributes give each source a truthful
			     pre-load intrinsic-ratio hint (1.8:1); they never stretch the
			     image because CSS fully determines the rendered box. -->
			<source
				media="(max-width: 768px)"
				type="image/webp"
				width="1080"
				height="600"
				srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image_mobile.webp' ); ?>">
			<source
				media="(max-width: 768px)"
				width="1683"
				height="935"
				srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image_mobile.png' ); ?>">
			<!-- Desktop: cinematic composition (conexaobr_Hero_image.png,
			     2057×764 intrinsic). The 3.71:1 hero slot is reserved by the
			     .hero-section aspect-ratio rule in CSS — not by these
			     attributes — so width/height only carry honest pre-load
			     intrinsic metadata; object-fit:cover handles the crop. -->
			<img class="hero-background-img"
				src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image.png' ); ?>"
				width="2057"
				height="764"
				alt=""
				loading="eager"
				fetchpriority="high"
				aria-hidden="true">
		</picture>
	</div>

	<div class="site-container">
		<!-- Two-column Hero composition (CSS in assets/css/main.css): hero copy
		     left, Featured Apoiadores right on desktop/tablet (≥769px);
		     stacked copy-first on mobile (≤768px). Without featured sponsors
		     the modifier class is omitted and the hero stays single-column. -->
		<div class="hero-content<?php echo $hero_has_sponsors ? ' hero-content--with-sponsors' : ''; ?>">
			<!-- Left column: Badge → Heading → Description → CTAs. Content and
			     links unchanged; only wrapped for the two-column layout. -->
			<div class="hero-copy">
				<div class="hero-badge">
					<span class="hero-badge-dot"></span>
					<?php esc_html_e( 'Portal da Comunidade Brasileira', 'conexao-br-irlanda' ); ?>
				</div>
				<h1 class="hero-title"><?php echo wp_kses_post( $hero_title ); ?></h1>
				<p class="hero-subtitle"><?php echo esc_html( $hero_subtitle ); ?></p>
				<div class="hero-ctas">
					<a href="<?php echo esc_url( conexao_get_guides_archive_url() ); ?>" class="btn btn-primary">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
							<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
						</svg>
						<?php esc_html_e( 'Explorar Guias', 'conexao-br-irlanda' ); ?>
					</a>
					<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="btn btn-outline">
						<?php esc_html_e( 'Ver Eventos', 'conexao-br-irlanda' ); ?>
					</a>
				</div>
			</div>
			<?php if ( $hero_has_sponsors ) : ?>
			<!-- Right column: Featured Apoiadores carousel — the SAME working
			     component moved into its own layout wrapper (query, ordering,
			     links, JS and accessibility untouched). Rendered only when a
			     supporter is marked "Apoiador em destaque", so no empty column
			     is reserved otherwise. See template-parts/featured-sponsors.php. -->
			<div class="hero-sponsors">
				<?php get_template_part( 'template-parts/featured-sponsors' ); ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- Quick Access Categories -->
<section class="quick-access-section" aria-labelledby="quick-access-heading">
	<div class="site-container">
		<h2 id="quick-access-heading" class="quick-access-heading"><?php esc_html_e( 'Acesso Rápido', 'conexao-br-irlanda' ); ?></h2>
		<div class="quick-access-grid">
			<?php
			$quick_access_cards = array(
				array( 'icon' => 'home', 'title' => __( 'Moradia', 'conexao-br-irlanda' ), 'description' => __( 'Casas e apartamentos', 'conexao-br-irlanda' ), 'term' => 'moradia' ),
				array( 'icon' => 'briefcase', 'title' => __( 'Empregos', 'conexao-br-irlanda' ), 'description' => __( 'Vagas de trabalho', 'conexao-br-irlanda' ), 'url' => '/empregos/', 'mobile_priority' => 'empregos' ),
				array( 'icon' => 'heart', 'title' => __( 'Saúde', 'conexao-br-irlanda' ), 'description' => __( 'Acesso à saúde', 'conexao-br-irlanda' ), 'term' => 'saude' ),
				array( 'icon' => 'compass', 'title' => __( 'Lazer', 'conexao-br-irlanda' ), 'description' => __( 'Lazer e turismo', 'conexao-br-irlanda' ), 'url' => '/lazer/', 'mobile_priority' => 'lazer', 'mobile_label' => __( 'Lazer e turismo', 'conexao-br-irlanda' ) ),
				array( 'icon' => 'car', 'title' => __( 'Transporte', 'conexao-br-irlanda' ), 'description' => __( 'Como se locomover', 'conexao-br-irlanda' ), 'term' => 'transporte' ),
				array( 'icon' => 'dollar', 'title' => __( 'Finanças', 'conexao-br-irlanda' ), 'description' => __( 'Bancos e impostos', 'conexao-br-irlanda' ), 'term' => 'financas' ),
				array( 'icon' => 'gift', 'title' => __( 'Benefícios', 'conexao-br-irlanda' ), 'description' => __( 'Auxílios e subsídios', 'conexao-br-irlanda' ), 'term' => 'beneficios' ),
				array( 'icon' => 'utensils', 'title' => __( 'Onde Comer', 'conexao-br-irlanda' ), 'description' => __( 'Restaurantes e mercados', 'conexao-br-irlanda' ), 'url' => '/onde-comer/' ),
				array( 'icon' => 'calendar', 'title' => __( 'Eventos', 'conexao-br-irlanda' ), 'description' => __( 'Agenda da comunidade', 'conexao-br-irlanda' ), 'url' => '/eventos/' ),
				array( 'icon' => 'graduation-cap', 'title' => __( 'Educação', 'conexao-br-irlanda' ), 'description' => __( 'Cursos e escolas', 'conexao-br-irlanda' ), 'url' => '/cursos/' ),
				array( 'icon' => 'file-text', 'title' => __( 'Documentos', 'conexao-br-irlanda' ), 'description' => __( 'Vistos e PPS Number', 'conexao-br-irlanda' ), 'term' => 'documentos' ),
				array( 'icon' => 'map', 'title' => __( 'Ver todas', 'conexao-br-irlanda' ), 'description' => __( 'Todas as categorias', 'conexao-br-irlanda' ), 'url' => '/categorias/' ),
				// Mobile-only priority cards. These stay out of the desktop grid
				// (mobile_only) and are promoted into the compact mobile 4x1
				// navigation via the mobile_priority flag, together with the
				// Empregos and Lazer cards above. The mobile priority set is
				// exactly: Apoiadores | Empregos | Blog | Lazer e turismo.
				array( 'icon' => 'users', 'title' => __( 'Apoiadores', 'conexao-br-irlanda' ), 'description' => __( 'Negócios parceiros', 'conexao-br-irlanda' ), 'url' => '/apoiadores/', 'mobile_priority' => 'apoiadores', 'mobile_only' => true ),
				array( 'icon' => 'pen', 'title' => __( 'Blog', 'conexao-br-irlanda' ), 'description' => __( 'Novidades e artigos', 'conexao-br-irlanda' ), 'url' => '/blog/', 'mobile_priority' => 'blog', 'mobile_only' => true ),
				// Guias remains defined as a mobile-only card but is no longer part
				// of the four-slot mobile priority row. With mobile_only set and no
				// mobile_priority it stays hidden on every breakpoint until it is
				// re-promoted (the desktop grid must remain unchanged).
				array( 'icon' => 'book', 'title' => __( 'Guias', 'conexao-br-irlanda' ), 'description' => __( 'Guias práticos', 'conexao-br-irlanda' ), 'guides' => true, 'mobile_only' => true ),
			);

			$icon_svgs = array(
				'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
				'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
				'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>',
				'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
				'car' => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"></path><circle cx="6.5" cy="16.5" r="2.5"></circle><circle cx="16.5" cy="16.5" r="2.5"></circle>',
				'dollar' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
				'gift' => '<polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path>',
				'utensils' => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line>',
				'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
				'graduation-cap' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 2.5 3 6 3s3 0 6-3v-5"></path>',
				'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
				'map' => '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line>',
				'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>',
				'pen' => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>',
				'compass' => '<circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>',
			);

			foreach ( $quick_access_cards as $card ) :
				get_template_part( 'template-parts/quick-access-card', null, array( 'card' => $card, 'icons' => $icon_svgs ) );
			endforeach;
			?>
		</div>
	</div>
</section>

<!-- Featured Content -->
<section class="section section--featured">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Conteúdo em Destaque', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Últimas Publicações', 'conexao-br-irlanda' ); ?></h2>
			</div>
			<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" class="section-link">
				<?php esc_html_e( 'Ver todas', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>

		<div class="featured-content-layout">
			<div class="featured-main">
				<?php
				// Editorial featured content only. Sponsors are intentionally NOT
				// part of this generic query: featured supporters have their own
				// dedicated carousel inside the Hero (template-parts/
				// featured-sponsors.php) and must not be duplicated here.
				$featured = new WP_Query( array(
					'post_type'           => array( 'guide', 'event', 'job' ),
					'posts_per_page'      => 5,
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
				$count = 0;
				if ( $featured->have_posts() ) :
					while ( $featured->have_posts() ) : $featured->the_post();
						if ( 0 === $count ) : ?>
							<article class="featured-article">
								<?php if ( has_post_thumbnail() ) : ?>
									<div class="featured-article-image">
										<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-hero', array( 'loading' => 'lazy' ) ); ?></a>
									</div>
								<?php endif; ?>
								<div class="featured-article-content">
									<?php
									$categories = get_the_terms( get_the_ID(), 'conexao_category' );
									$content_type = get_post_type_object( get_post_type() );
									if ( $content_type ) : ?><span class="featured-article-category"><?php echo esc_html( $content_type->labels->singular_name ); ?></span><?php endif; ?>
									<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
										<span class="featured-article-category"><?php echo esc_html( $categories[0]->name ); ?></span>
									<?php endif; ?>
									<h3 class="featured-article-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
									<p class="featured-article-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 30, '...' ) ); ?></p>
									<div class="featured-article-meta">
										<span>
											<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
												<circle cx="12" cy="12" r="10"></circle>
												<polyline points="12 6 12 12 16 14"></polyline>
											</svg>
											<?php echo esc_html( get_the_date() ); ?>
										</span>
										<span>
											<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
												<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
												<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
											</svg>
											<?php echo esc_html( conexao_reading_time_text() ); ?>
										</span>
									</div>
								</div>
							</article>
						<?php else : ?>
							<?php if ( 1 === $count ) : ?><div class="cards-grid" style="margin-top: 24px;"><?php endif; ?>
							<article class="post-card">
								<?php if ( has_post_thumbnail() ) : ?>
									<div class="post-card-image">
										<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a>
									</div>
								<?php endif; ?>
								<?php
							$cats = get_the_terms( get_the_ID(), 'conexao_category' );
							$content_type = get_post_type_object( get_post_type() );
							if ( $content_type ) : ?><span class="post-card-category"><?php echo esc_html( $content_type->labels->singular_name ); ?></span><?php endif; ?>
							<?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
									<span class="post-card-category"><?php echo esc_html( $cats[0]->name ); ?></span>
								<?php endif; ?>
								<div class="post-card-body">
									<h3 class="post-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
									<p class="post-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
									<div class="post-card-meta">
										<span><?php echo esc_html( get_the_date() ); ?></span>
										<span><?php echo esc_html( conexao_reading_time_text() ); ?></span>
									</div>
								</div>
							</article>
							<?php if ( $count === $featured->post_count - 1 ) : ?></div><?php endif; ?>
						<?php endif; ?>
						<?php $count++; ?>
					<?php endwhile; wp_reset_postdata(); ?>
				<?php else : ?>
					<p><?php esc_html_e( 'Novos conteúdos serão publicados em breve.', 'conexao-br-irlanda' ); ?></p>
									<?php endif; ?>
								</div>

			<div class="featured-sidebar">
				<!-- Popular Guides -->
				<div class="sidebar-widget">
					<h4 class="sidebar-widget-title">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
							<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
						</svg>
						<?php esc_html_e( 'Guias Populares', 'conexao-br-irlanda' ); ?>
					</h4>
					<ul class="sidebar-list">
						<?php
						$guides = new WP_Query( array(
							'post_type'      => 'guide',
							'posts_per_page' => 5,
							'no_found_rows'  => true,
							'update_post_meta_cache' => false,
							'update_post_term_cache' => false,
						) );
						$guide_count = 1;
						if ( $guides->have_posts() ) :
							while ( $guides->have_posts() ) : $guides->the_post(); ?>
								<li>
									<a href="<?php the_permalink(); ?>">
										<span class="sidebar-list-number"><?php echo esc_html( $guide_count ); ?></span>
										<?php the_title(); ?>
									</a>
								</li>
							<?php $guide_count++;
							endwhile; wp_reset_postdata();
						else : ?>
							<li><span class="sidebar-list-empty"><?php esc_html_e( 'Novos guias serão publicados em breve.', 'conexao-br-irlanda' ); ?></span></li>
						<?php endif; ?>
					</ul>
				</div>

				<!-- Most Read -->
				<div class="sidebar-widget">
					<h4 class="sidebar-widget-title">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
							<circle cx="12" cy="12" r="3"></circle>
						</svg>
						<?php esc_html_e( 'Mais Lidos', 'conexao-br-irlanda' ); ?>
					</h4>
					<ul class="sidebar-list">
						<?php
						// Lightweight "Mais Lidos" list. Uses conexao_popular_posts()
						// which is view-count-ready (_conexao_view_count meta) and,
						// until a view-count system exists, falls back to recent
						// content — avoiding the expensive ORDER BY comment_count.
						$popular_ids = conexao_popular_posts( 5 );
						$pop_count   = 1;
						if ( ! empty( $popular_ids ) ) :
							$popular_query = new WP_Query( array(
								'post__in'               => $popular_ids,
								'orderby'                => 'post__in',
								'posts_per_page'         => count( $popular_ids ),
								'ignore_sticky_posts'    => true,
								'no_found_rows'          => true,
								'update_post_meta_cache' => false,
								'update_post_term_cache' => false,
							) );
							while ( $popular_query->have_posts() ) : $popular_query->the_post(); ?>
								<li>
									<a href="<?php the_permalink(); ?>">
										<span class="sidebar-list-number"><?php echo esc_html( $pop_count ); ?></span>
										<?php the_title(); ?>
									</a>
								</li>
							<?php $pop_count++;
							endwhile; wp_reset_postdata();
						endif; ?>
					</ul>
				</div>
			</div>
		</div>
	</div>
</section>

<!-- Practical Guides -->
<section class="section section--gray section--guides">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Guias Práticos', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Guia em Destaque', 'conexao-br-irlanda' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'Um guia completo para ajudar você em sua jornada na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<a href="<?php echo esc_url( conexao_get_guides_archive_url() ); ?>" class="section-link">
				<?php esc_html_e( 'Ver todos os guias', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>

		<div class="guides-grid">
			<?php
			$guide_list = array(
				array( 'icon' => 'id-card', 'title' => __( 'PPS Number', 'conexao-br-irlanda' ), 'desc' => __( 'Como conseguir seu Personal Public Service Number', 'conexao-br-irlanda' ), 'url' => '/guias/pps-number/' ),
				array( 'icon' => 'heart-pulse', 'title' => __( 'Medical Card', 'conexao-br-irlanda' ), 'desc' => __( 'Guia completo sobre o cartão de saúde irlandês', 'conexao-br-irlanda' ), 'url' => '/guias/medical-card/' ),
				array( 'icon' => 'car', 'title' => __( 'Carteira de Motorista', 'conexao-br-irlanda' ), 'desc' => __( 'Como trocar sua CNH brasileira pela irlandesa', 'conexao-br-irlanda' ), 'url' => '/guias/carteira-de-motorista/' ),
				array( 'icon' => 'landmark', 'title' => __( 'Conta Bancária', 'conexao-br-irlanda' ), 'desc' => __( 'Passo a passo para abrir sua conta na Irlanda', 'conexao-br-irlanda' ), 'url' => '/guias/abrir-conta-bancaria/' ),
				array( 'icon' => 'home', 'title' => __( 'Alugar Casa', 'conexao-br-irlanda' ), 'desc' => __( 'Tudo sobre o mercado imobiliário irlandês', 'conexao-br-irlanda' ), 'url' => '/guias/alugar-casa/' ),
				array( 'icon' => 'receipt', 'title' => __( 'Impostos', 'conexao-br-irlanda' ), 'desc' => __( 'Entenda o sistema de impostos na Irlanda', 'conexao-br-irlanda' ), 'url' => '/guias/impostos/' ),
				array( 'icon' => 'stethoscope', 'title' => __( 'GP Registration', 'conexao-br-irlanda' ), 'desc' => __( 'Como se registrar em um médico na Irlanda', 'conexao-br-irlanda' ), 'url' => '/guias/gp-registration/' ),
				array( 'icon' => 'flag', 'title' => __( 'Cidadania Irlandesa', 'conexao-br-irlanda' ), 'desc' => __( 'Requisitos e processo para obter a cidadania', 'conexao-br-irlanda' ), 'url' => '/guias/cidadania-irlandesa/' ),
			);

			$guide_icons = array(
				'id-card' => '<rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line>',
				'heart-pulse' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>',
				'car' => '<path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"></path><circle cx="6.5" cy="16.5" r="2.5"></circle><circle cx="16.5" cy="16.5" r="2.5"></circle>',
				'landmark' => '<line x1="3" y1="22" x2="21" y2="22"></line><line x1="6" y1="18" x2="6" y2="11"></line><line x1="10" y1="18" x2="10" y2="11"></line><line x1="14" y1="18" x2="14" y2="11"></line><line x1="18" y1="18" x2="18" y2="11"></line><polygon points="12 2 20 7 4 7"></polygon>',
				'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>',
				'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1-2-1z"></path><line x1="8" y1="7" x2="16" y2="7"></line><line x1="8" y1="11" x2="16" y2="11"></line><line x1="8" y1="15" x2="12" y2="15"></line>',
				'stethoscope' => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"></path><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"></path><circle cx="20" cy="10" r="2"></circle>',
				'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line>',
			);

			// Keep the existing card presentation, but populate it exclusively from Guides.
			// Single query with fallback: try featured first, then latest.
			$guide_list   = array();
			$guides_query = new WP_Query( array(
				'post_type'      => 'guide',
				'posts_per_page' => 1,
				'meta_key'       => '_conexao_featured',
				'meta_value'     => '1',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( ! $guides_query->have_posts() ) {
				$guides_query = new WP_Query( array(
					'post_type'      => 'guide',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
			}
			if ( $guides_query->have_posts() ) {
				$guide_list = array();
				while ( $guides_query->have_posts() ) {
					$guides_query->the_post();
					$guide_list[] = array( 'icon' => 'home', 'title' => get_the_title(), 'desc' => wp_trim_words( get_the_excerpt(), 18, '...' ), 'url' => get_permalink() );
				}
				wp_reset_postdata();
			}

			if ( empty( $guide_list ) ) : ?>
				<p><?php esc_html_e( 'Novos guias serão publicados em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; foreach ( $guide_list as $guide ) :
				$icon = isset( $guide_icons[ $guide['icon'] ] ) ? $guide_icons[ $guide['icon'] ] : '';
				$guide_url = 0 === strpos( $guide['url'], 'http' ) ? $guide['url'] : home_url( $guide['url'] );
				?>
				<a href="<?php echo esc_url( $guide_url ); ?>" class="guide-card">
					<div class="guide-icon">
						<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<?php echo $icon; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>
						</svg>
					</div>
					<h3 class="guide-title"><?php echo esc_html( $guide['title'] ); ?></h3>
					<p class="guide-description"><?php echo esc_html( $guide['desc'] ); ?></p>
					<span class="guide-link">
						<?php esc_html_e( 'Ler guia', 'conexao-br-irlanda' ); ?>
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
							<line x1="5" y1="12" x2="19" y2="12"></line>
							<polyline points="12 5 19 12 12 19"></polyline>
						</svg>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<!-- Upcoming Events -->
<section class="section section--events">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Agenda', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Próximos Eventos', 'conexao-br-irlanda' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'Não perca os eventos da comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="section-link">
				<?php esc_html_e( 'Ver todos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>

		<div class="events-grid events-grid--preview">
			<?php
			// Homepage compact preview: only upcoming, published events ordered
			// chronologically, limited to 2. This uses the SAME underlying event
			// data as the /eventos/ archive — it never creates or duplicates
			// records and it does not modify event metadata.
			$events_list = new WP_Query( array(
				'post_type'           => 'event',
				'post_status'         => 'publish',
				'posts_per_page'      => 2,
				'meta_key'            => '_event_date',
				'meta_value'          => current_time( 'Y-m-d' ),
				'meta_compare'        => '>=',
				'meta_type'           => 'DATE',
				'orderby'             => 'meta_value',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( $events_list->have_posts() ) :
				while ( $events_list->have_posts() ) : $events_list->the_post();
					get_template_part( 'template-parts/event', 'preview' );
				endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Nenhum evento próximo no momento.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="events-section-footer">
			<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="events-section-link">
				<?php esc_html_e( 'Ver todos os eventos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>
	</div>
</section>

<!-- Latest Jobs -->
<section class="section section--gray section--jobs">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Oportunidades', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Últimas Vagas', 'conexao-br-irlanda' ); ?></h2>
			</div>
			<a href="<?php echo esc_url( home_url( '/empregos/' ) ); ?>" class="section-link"><?php esc_html_e( 'Ver todas', 'conexao-br-irlanda' ); ?></a>
		</div>
		<div class="news-grid">
			<?php $jobs = new WP_Query( array( 'post_type' => 'job', 'posts_per_page' => 3, 'meta_key' => '_job_expiration_date', 'meta_value' => current_time( 'Y-m-d' ), 'meta_compare' => '>=', 'meta_type' => 'DATE', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) ); ?>
			<?php if ( $jobs->have_posts() ) : while ( $jobs->have_posts() ) : $jobs->the_post(); $job_categories = get_the_terms( get_the_ID(), 'conexao_category' ); ?>
				<article class="news-card"><div class="news-card-body">
					<?php if ( $job_categories && ! is_wp_error( $job_categories ) ) : ?><span class="news-card-category"><?php echo esc_html( $job_categories[0]->name ); ?></span><?php endif; ?>
					<h3 class="news-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
					<?php $job_details = array_filter( array( get_post_meta( get_the_ID(), '_job_company', true ), get_post_meta( get_the_ID(), '_job_location', true ), get_post_meta( get_the_ID(), '_job_salary', true ), get_post_meta( get_the_ID(), '_job_employment_type', true ) ) ); ?>
					<?php if ( $job_details ) : ?><p class="news-card-category"><?php echo esc_html( implode( ' · ', $job_details ) ); ?></p><?php endif; ?>
					<p class="news-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>
					<a href="<?php the_permalink(); ?>" class="event-card-cta"><?php esc_html_e( 'Ver vaga', 'conexao-br-irlanda' ); ?></a>
				</div></article>
			<?php endwhile; wp_reset_postdata(); else : ?>
				<p><?php esc_html_e( 'Novas oportunidades de emprego serão publicadas em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

</main>

<?php get_footer();
