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
//                     1.8:1 full-bleed compositions (WebP 900×500, PNG
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
			     WebP first (900×500, ≈100 KB — re-encoded from the PNG master
			     at q85; 900 device px covers the mobile hero at DPR 2 up to
			     450 CSS px, which spans every phone width this layout ships),
			     PNG fallback (1683×935 — same 1.8:1 framing at higher
			     resolution). The browser selects the matching asset
			     automatically — no JavaScript. The width/height attributes
			     give each source a truthful pre-load intrinsic-ratio hint
			     (1.8:1); they never stretch the image because CSS fully
			     determines the rendered box. -->
			<source
				media="(max-width: 768px)"
				type="image/webp"
				width="900"
				height="500"
				srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image_mobile.webp' ); ?>">
			<source
				media="(max-width: 768px)"
				width="1683"
				height="935"
				srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image_mobile.png' ); ?>">
			<!-- Desktop (≥769px): WebP first with a two-candidate responsive
			     ladder (q88, visually lossless, same encoding as the master):
			       - conexaobr_Hero_image-1600.webp (1600×594, ≈140 KB) — covers
			         DPR-1 desktops rendering the hero at ≤1600 CSS px (the
			         hero is full-bleed, so sizes="100vw" is exact). PageSpeed
			         measured the hero rendering at ~1516×563 while the
			         2057px master was being downloaded (~84.5 KiB waste).
			       - conexaobr_Hero_image.webp (2057×764, ≈225 KB) — kept for
			         DPR-2 / very wide screens where it is genuinely needed.
			     PNG fallback for browsers without WebP support. Placed AFTER
			     the mobile sources so mobile WebP-capable browsers keep
			     matching the dedicated mobile asset first. -->
			<source
				media="(min-width: 769px)"
				type="image/webp"
				width="2057"
				height="764"
				sizes="100vw"
				srcset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image-1600.webp' ); ?> 1600w, <?php echo esc_url( get_template_directory_uri() . '/assets/images/conexaobr_Hero_image.webp' ); ?> 2057w">
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
		     left, Featured Apoiadores right at every breakpoint — including
		     mobile (≤768px), where both zones share the full-bleed photo with
		     mobile-tuned proportions (~55/45, ~58/42 on small phones). Without
		     featured sponsors the modifier class is omitted and the hero stays
		     single-column. -->
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
					<a href="<?php echo esc_url( conexao_lang_url( '/eventos/' ) ); ?>" class="btn btn-outline">
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
			// Each card carries an inert 'key' slug: it is not used by the
			// Quick Access rendering at all — it exists so the homepage
			// "Precisa de ajuda?" utility section (further down) can select a
			// small, explicit subset of these SAME definitions as its single
			// source of truth for label / icon / destination, instead of
			// hard-coding a second, drift-prone copy.
			$quick_access_cards = array(
				array( 'key' => 'moradia', 'icon' => 'home', 'title' => __( 'Moradia', 'conexao-br-irlanda' ), 'description' => __( 'Casas e apartamentos', 'conexao-br-irlanda' ), 'term' => 'moradia' ),
				array( 'key' => 'empregos', 'icon' => 'briefcase', 'title' => __( 'Empregos', 'conexao-br-irlanda' ), 'description' => __( 'Vagas de trabalho', 'conexao-br-irlanda' ), 'url' => '/empregos/', 'mobile_priority' => 'empregos' ),
				array( 'key' => 'saude', 'icon' => 'heart', 'title' => __( 'Saúde', 'conexao-br-irlanda' ), 'description' => __( 'Acesso à saúde', 'conexao-br-irlanda' ), 'term' => 'saude' ),
				array( 'key' => 'lazer', 'icon' => 'compass', 'title' => __( 'Lazer', 'conexao-br-irlanda' ), 'description' => __( 'Lazer e turismo', 'conexao-br-irlanda' ), 'url' => '/lazer/', 'mobile_priority' => 'lazer', 'mobile_label' => __( 'Lazer e turismo', 'conexao-br-irlanda' ) ),
				array( 'key' => 'transporte', 'icon' => 'car', 'title' => __( 'Transporte', 'conexao-br-irlanda' ), 'description' => __( 'Como se locomover', 'conexao-br-irlanda' ), 'term' => 'transporte' ),
				array( 'key' => 'financas', 'icon' => 'dollar', 'title' => __( 'Finanças', 'conexao-br-irlanda' ), 'description' => __( 'Bancos e impostos', 'conexao-br-irlanda' ), 'term' => 'financas' ),
				array( 'key' => 'beneficios', 'icon' => 'gift', 'title' => __( 'Benefícios', 'conexao-br-irlanda' ), 'description' => __( 'Auxílios e subsídios', 'conexao-br-irlanda' ), 'term' => 'beneficios' ),
				array( 'key' => 'eventos', 'icon' => 'calendar', 'title' => __( 'Eventos', 'conexao-br-irlanda' ), 'description' => __( 'Agenda da comunidade', 'conexao-br-irlanda' ), 'url' => '/eventos/' ),
				array( 'key' => 'educacao', 'icon' => 'graduation-cap', 'title' => __( 'Educação', 'conexao-br-irlanda' ), 'description' => __( 'Cursos e escolas', 'conexao-br-irlanda' ), 'url' => '/cursos/' ),
				array( 'key' => 'documentos', 'icon' => 'file-text', 'title' => __( 'Documentos', 'conexao-br-irlanda' ), 'description' => __( 'Vistos e PPS Number', 'conexao-br-irlanda' ), 'term' => 'documentos' ),
				// Apoiadores stays a mobile-only priority card: it is kept out of the
				// desktop grid (mobile_only) and promoted into the compact mobile 4x1
				// navigation via the mobile_priority flag, together with the Empregos
				// and Lazer cards above. The mobile priority set is exactly:
				// Apoiadores | Empregos | Blog | Lazer e turismo.
				array( 'key' => 'apoiadores', 'icon' => 'users', 'title' => __( 'Apoiadores', 'conexao-br-irlanda' ), 'description' => __( 'Negócios parceiros', 'conexao-br-irlanda' ), 'url' => '/apoiadores/', 'mobile_priority' => 'apoiadores', 'mobile_only' => true ),
				// Blog carries a mobile_priority flag (it sits third in the compact
				// mobile 4x1 row) but no mobile_only flag, so the same single card
				// renders in both the desktop grid and the mobile priority row.
				array( 'key' => 'blog', 'icon' => 'pen', 'title' => __( 'Blog', 'conexao-br-irlanda' ), 'description' => __( 'Novidades e artigos', 'conexao-br-irlanda' ), 'url' => '/blog/', 'mobile_priority' => 'blog' ),
				// Guias remains defined as a mobile-only card but is no longer part
				// of the four-slot mobile priority row. With mobile_only set and no
				// mobile_priority it stays hidden on every breakpoint until it is
				// re-promoted (the desktop grid must remain unchanged).
				array( 'key' => 'guias', 'icon' => 'book', 'title' => __( 'Guias', 'conexao-br-irlanda' ), 'description' => __( 'Guias práticos', 'conexao-br-irlanda' ), 'guides' => true, 'mobile_only' => true ),
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
			<a href="<?php echo esc_url( conexao_lang_url( '/blog/' ) ); ?>" class="section-link">
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
				// Editorial featured content only. The "Conteudo em Destaque" /
				// "Ultimas Publicacoes" section is restricted to genuinely
				// editorial and informational content types only:
				// Guias, Cursos (course_provider) and Blog (post).
				//
				// Eventos (event), Lazer (leisure), Apoiadores (sponsor) and
				// Empregos (job) are intentionally excluded here so they never
				// appear in this section. Each has its own dedicated homepage
				// section or archive (see "Proximos Eventos" and "Latest Jobs"
				// below, plus the Hero carousel for featured Apoiadores).
				// Featured supporters have their own carousel inside the Hero
				// (template-parts/featured-sponsors.php) and must not be
				// duplicated here.
				$featured = new WP_Query( array(
					'post_type'           => array( 'guide', 'post' ),
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
										<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-hero', array( 'loading' => 'lazy', 'sizes' => '(max-width: 1024px) 92vw, 66vw' ) ); ?></a>
									</div>
								<?php endif; ?>
								<div class="featured-article-content">
									<?php
									$categories = get_the_terms( get_the_ID(), 'conexao_category' );
									$content_type = get_post_type_object( get_post_type() );
									if ( $content_type || ( $categories && ! is_wp_error( $categories ) ) ) : ?>
										<div class="featured-article-categories">
											<?php if ( $content_type ) : ?><span class="featured-article-category"><?php echo esc_html( $content_type->labels->singular_name ); ?></span><?php endif; ?>
											<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
												<span class="featured-article-category"><?php echo esc_html( $categories[0]->name ); ?></span>
											<?php endif; ?>
										</div>
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
										<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy', 'sizes' => '(max-width: 768px) 92vw, 380px' ) ); ?></a>
									</div>
								<?php endif; ?>
								<div class="post-card-body">
								<?php
								$cats = get_the_terms( get_the_ID(), 'conexao_category' );
								$content_type = get_post_type_object( get_post_type() );
								if ( $content_type || ( $cats && ! is_wp_error( $cats ) ) ) : ?>
									<div class="post-card-categories">
										<?php if ( $content_type ) : ?><span class="post-card-category"><?php echo esc_html( $content_type->labels->singular_name ); ?></span><?php endif; ?>
										<?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
											<span class="post-card-category"><?php echo esc_html( $cats[0]->name ); ?></span>
										<?php endif; ?>
									</div>
								<?php endif; ?>
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
						// which ranks by the _conexao_view_count meta recorded
						// server-side (inc/post-views.php); content without
						// views yet falls back to recent content — avoiding
						// the expensive ORDER BY comment_count.
						// Scope is Blog (post) + Guias (guide) only.
						$popular_ids = conexao_popular_posts( 5 );
						$pop_count   = 1;
						if ( ! empty( $popular_ids ) ) :
							$popular_query = new WP_Query( array(
								'post__in'               => $popular_ids,
								// Must match the scope of conexao_popular_posts():
								// Blog (post) + Guias (guide) only. Without it
								// WP_Query defaults to 'post' only and silently
								// drops guides.
								'post_type'              => conexao_view_count_post_types(),
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

<!-- Precisa de ajuda? (compact utility shortcuts) -->
<?php
// "Precisa de ajuda?" — a compact, action-oriented utility section answering
// "what do I need right now?". It is intentionally NOT a second Quick Access
// grid: the Quick Access section stays the broad navigation; this one shows a
// small, fixed set of problem-solving destinations as shortcut chips.
//
// Single source of truth: the cards below are the SAME definitions the Quick
// Access grid above renders (matched by the inert 'key' annotation), so the
// label / icon / destination of each shortcut can never drift from Acesso
// Rápido. Only the SELECTION here is explicit — and deliberately kept small.
// Rendering goes through template-parts/help-shortcut-card.php, which resolves
// destinations with the same helpers the Quick Access cards use
// (conexao_get_guides_archive_url(), conexao_get_guide_category_url(),
// home_url()).
$help_shortcut_keys = array( 'moradia', 'empregos', 'documentos', 'saude', 'beneficios', 'financas' );
$help_shortcuts     = array();
foreach ( $quick_access_cards as $qa_card ) {
	if ( ! empty( $qa_card['key'] ) && in_array( $qa_card['key'], $help_shortcut_keys, true ) ) {
		$help_shortcuts[ $qa_card['key'] ] = $qa_card;
	}
}
if ( ! empty( $help_shortcuts ) ) : ?>
<section class="help-section" aria-labelledby="help-section-heading">
	<div class="site-container">
		<div class="help-section-panel">
			<h2 id="help-section-heading" class="help-section-heading"><?php esc_html_e( 'Precisa de ajuda?', 'conexao-br-irlanda' ); ?></h2>
			<nav class="help-shortcuts" aria-label="<?php esc_attr_e( 'Atalhos para as principais áreas do site', 'conexao-br-irlanda' ); ?>">
				<ul class="help-shortcuts-grid">
					<?php foreach ( $help_shortcut_keys as $help_key ) :
						if ( empty( $help_shortcuts[ $help_key ] ) ) {
							continue;
						}
						get_template_part( 'template-parts/help-shortcut-card', null, array( 'card' => $help_shortcuts[ $help_key ], 'icons' => $icon_svgs ) );
					endforeach; ?>
				</ul>
			</nav>
		</div>
	</div>
</section>
<?php endif; ?>

<!-- Latest News (Blog) -->
<?php
// "Ultimas Novidades" — the newest Blog posts, answering "what's new?"
// (Mais Lidos answers "what's popular?"). Blog posts (post) ONLY: Guias,
// Eventos, Cursos, Lazer, Empregos and Apoiadores are intentionally
// excluded. One bounded query, ID list transient-cached under
// conexao_home_latest and invalidated on save/delete (functions.php),
// then re-fetched by post__in so the cards reuse the existing post-card
// component and its responsive-image treatment unchanged. The section
// renders nothing while there are no posts to show.
$latest_news_ids = conexao_latest_blog_posts( 3 );
if ( ! empty( $latest_news_ids ) ) :
	?>
<section class="section section--gray section--latest-news" aria-labelledby="latest-news-heading">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Blog', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title" id="latest-news-heading"><?php esc_html_e( 'Últimas novidades', 'conexao-br-irlanda' ); ?></h2>
			</div>
			<a href="<?php echo esc_url( conexao_lang_url( '/blog/' ) ); ?>" class="section-link">
				<?php esc_html_e( 'Ver todos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>

		<div class="cards-grid">
			<?php
			$latest_news_query = new WP_Query( array(
				'post__in'               => $latest_news_ids,
				'post_type'              => 'post',
				// Must match conexao_latest_blog_posts(): publication date,
				// newest first (post__in preserves the cached ID order).
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $latest_news_ids ),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( $latest_news_query->have_posts() ) :
				while ( $latest_news_query->have_posts() ) : $latest_news_query->the_post();
					$latest_news_cats = get_the_terms( get_the_ID(), 'conexao_category' );
					?>
					<article class="post-card">
						<?php if ( has_post_thumbnail() ) : ?>
							<div class="post-card-image">
								<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy', 'sizes' => '(max-width: 768px) 92vw, 380px' ) ); ?></a>
							</div>
						<?php endif; ?>
						<div class="post-card-body">
							<?php if ( $latest_news_cats && ! is_wp_error( $latest_news_cats ) ) : ?>
								<div class="post-card-categories">
									<span class="post-card-category"><?php echo esc_html( $latest_news_cats[0]->name ); ?></span>
								</div>
							<?php endif; ?>
							<h3 class="post-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<p class="post-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
							<div class="post-card-meta">
								<span><?php echo esc_html( get_the_date() ); ?></span>
								<span><?php echo esc_html( conexao_reading_time_text() ); ?></span>
							</div>
						</div>
					</article>
				<?php endwhile; wp_reset_postdata();
			endif; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<!-- Upcoming Events -->
<section class="section section--events">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Agenda', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Próximos Eventos', 'conexao-br-irlanda' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'Não perca os eventos da comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<a href="<?php echo esc_url( conexao_lang_url( '/eventos/' ) ); ?>" class="section-link">
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
			//
			// Recurrence: consumes the shared ordered upcoming-event ID list
			// (Conexao_Event_Query via conexao_event_upcoming_ids()) when the
			// event runtime is active; legacy date-meta query otherwise.
			$front_upcoming_ids = conexao_event_upcoming_ids();

			$front_events_args = array(
				'post_type'              => 'event',
				'post_status'            => 'publish',
				'posts_per_page'         => 2,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			// B2 fallback: secondary queries do not inherit the archive's
			// language scope, so Polylang would narrow this query to `en`
			// only and filter out the curated PT fallback records in
			// $front_upcoming_ids (EN records + PT records with no EN
			// translation). Widen to EN+PT exactly like the /eventos/
			// archive does in conexao_content_archive_query(); the ID list
			// itself stays language-curated so no event ever appears twice.
			// PT behaviour is byte-for-byte unchanged.
			if ( function_exists( 'conexao_polylang_active' ) && conexao_polylang_active() && function_exists( 'conexao_requested_language_slug' ) && 'en' === conexao_requested_language_slug() ) {
				$front_events_args['lang'] = 'en,pt';
			}

			if ( is_array( $front_upcoming_ids ) ) {
				$front_events_args['post__in'] = empty( $front_upcoming_ids ) ? array( 0 ) : $front_upcoming_ids;
				$front_events_args['orderby']  = 'post__in';
				$front_events_args['order']    = 'ASC';
			} else {
				$front_events_args['meta_key']     = '_event_date';
				$front_events_args['meta_value']   = current_time( 'Y-m-d' );
				$front_events_args['meta_compare'] = '>=';
				$front_events_args['meta_type']    = 'DATE';
				$front_events_args['orderby']      = 'meta_value';
				$front_events_args['order']        = 'ASC';
			}

			$events_list = new WP_Query( $front_events_args );
			if ( $events_list->have_posts() ) :
				while ( $events_list->have_posts() ) : $events_list->the_post();
					get_template_part( 'template-parts/event', 'preview' );
				endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Nenhum evento próximo no momento.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="events-section-footer">
			<a href="<?php echo esc_url( conexao_lang_url( '/eventos/' ) ); ?>" class="events-section-link">
				<?php esc_html_e( 'Ver todos os eventos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>
	</div>
</section>

<!-- Job Search Resources (single source of truth: conexao_job_resources(), same data as /empregos/) -->
<section class="section section--gray section--jobs" aria-labelledby="jobs-home-title">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Oportunidades', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title" id="jobs-home-title"><?php esc_html_e( 'Onde procurar emprego', 'conexao-br-irlanda' ); ?></h2>
			</div>
			<a href="<?php echo esc_url( conexao_lang_url( '/empregos/' ) ); ?>" class="section-link"><?php esc_html_e( 'Ver mais', 'conexao-br-irlanda' ); ?></a>
		</div>
		<?php get_template_part( 'template-parts/job-resources', 'preview' ); ?>
	</div>
</section>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

</main>

<?php get_footer();
