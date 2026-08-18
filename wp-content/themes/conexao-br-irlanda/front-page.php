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
$hero_image_id = conexao_hero_image_attachment_id();
?>

<main id="primary" class="site-main">

<!-- Hero Section -->
<section class="hero-section">
	<div class="site-container">
		<div class="hero-layout">
			<div class="hero-content">
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

			<!-- Hero Image -->
			<div class="hero-image">
				<?php if ( $hero_image_id ) : ?>
					<?php
					// The hero is the primary LCP element on the front page, so it is
					// loaded eagerly with high priority and never lazy-loaded. Using
					// wp_get_attachment_image() generates srcset, sizes, width, height
					// and a descriptive alt attribute from the Media Library.
					echo wp_get_attachment_image(
						$hero_image_id,
						'conexao-hero',
						false,
						array(
							'class'         => 'hero-image-img',
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'alt'           => get_bloginfo( 'name' ),
						)
					);
					?>
				<?php else : ?>
					<div class="hero-image-placeholder">
						<svg viewBox="0 0 640 480" width="640" height="480" role="img" aria-label="<?php esc_attr_e( 'Comunidade brasileira na Irlanda', 'conexao-br-irlanda' ); ?>">
							<defs>
								<linearGradient id="heroGrad" x1="0%" y1="0%" x2="100%" y2="100%">
									<stop offset="0%" style="stop-color:#073B2F"/>
									<stop offset="100%" style="stop-color:#0E6B3A"/>
								</linearGradient>
							</defs>
							<rect width="640" height="480" fill="url(#heroGrad)"/>
							<circle cx="320" cy="240" r="120" fill="none" stroke="#F68B1F" stroke-width="4" opacity="0.8"/>
							<circle cx="320" cy="240" r="80" fill="none" stroke="#ffffff" stroke-width="3" opacity="0.6"/>
							<text x="320" y="255" text-anchor="middle" fill="#fff" font-size="48" font-weight="bold" font-family="Poppins, sans-serif">CB</text>
							<text x="320" y="420" text-anchor="middle" fill="#fff" font-size="20" font-family="Inter, sans-serif" opacity="0.9">Conexão BR Irlanda</text>
						</svg>
					</div>
				<?php endif; ?>

				<?php get_template_part( 'template-parts/hero', 'events' ); ?>
			</div>
		</div>
	</div>
</section>

<!-- Quick Access Categories -->
<section class="quick-access-section">
	<div class="site-container">
		<div class="quick-access-grid">
			<?php
			$quick_access_cards = array(
				array( 'icon' => 'home', 'title' => __( 'Moradia', 'conexao-br-irlanda' ), 'description' => __( 'Casas e apartamentos', 'conexao-br-irlanda' ), 'term' => 'moradia' ),
				array( 'icon' => 'briefcase', 'title' => __( 'Empregos', 'conexao-br-irlanda' ), 'description' => __( 'Vagas de trabalho', 'conexao-br-irlanda' ), 'term' => 'empregos' ),
				array( 'icon' => 'heart', 'title' => __( 'Saúde', 'conexao-br-irlanda' ), 'description' => __( 'Acesso à saúde', 'conexao-br-irlanda' ), 'term' => 'saude' ),
				array( 'icon' => 'users', 'title' => __( 'Família', 'conexao-br-irlanda' ), 'description' => __( 'Família e crianças', 'conexao-br-irlanda' ), 'url' => '/familia/' ),
				array( 'icon' => 'car', 'title' => __( 'Transporte', 'conexao-br-irlanda' ), 'description' => __( 'Como se locomover', 'conexao-br-irlanda' ), 'term' => 'transporte' ),
				array( 'icon' => 'dollar', 'title' => __( 'Finanças', 'conexao-br-irlanda' ), 'description' => __( 'Bancos e impostos', 'conexao-br-irlanda' ), 'term' => 'financas' ),
				array( 'icon' => 'gift', 'title' => __( 'Benefícios', 'conexao-br-irlanda' ), 'description' => __( 'Auxílios e subsídios', 'conexao-br-irlanda' ), 'term' => 'beneficios' ),
				array( 'icon' => 'utensils', 'title' => __( 'Onde Comer', 'conexao-br-irlanda' ), 'description' => __( 'Restaurantes e mercados', 'conexao-br-irlanda' ), 'url' => '/onde-comer/' ),
				array( 'icon' => 'calendar', 'title' => __( 'Eventos', 'conexao-br-irlanda' ), 'description' => __( 'Agenda da comunidade', 'conexao-br-irlanda' ), 'url' => '/eventos/' ),
				array( 'icon' => 'graduation-cap', 'title' => __( 'Educação', 'conexao-br-irlanda' ), 'description' => __( 'Cursos e escolas', 'conexao-br-irlanda' ), 'term' => 'educacao' ),
				array( 'icon' => 'file-text', 'title' => __( 'Documentos', 'conexao-br-irlanda' ), 'description' => __( 'Vistos e PPS Number', 'conexao-br-irlanda' ), 'term' => 'documentos' ),
				array( 'icon' => 'map', 'title' => __( 'Ver todas', 'conexao-br-irlanda' ), 'description' => __( 'Todas as categorias', 'conexao-br-irlanda' ), 'url' => '/categorias/' ),
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
			);

			foreach ( $quick_access_cards as $card ) :
				get_template_part( 'template-parts/quick-access-card', null, array( 'card' => $card, 'icons' => $icon_svgs ) );
			endforeach;
			?>
		</div>
	</div>
</section>

<!-- Featured Content -->
<section class="section">
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
				$featured = new WP_Query( array(
					'post_type'           => array( 'guide', 'event', 'job', 'sponsor' ),
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
									<?php if ( 'sponsor' !== get_post_type() ) : ?>
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
									<?php endif; ?>
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
									<?php if ( 'sponsor' !== get_post_type() ) : ?>
									<div class="post-card-meta">
										<span><?php echo esc_html( get_the_date() ); ?></span>
										<span><?php echo esc_html( conexao_reading_time_text() ); ?></span>
									</div>
									<?php endif; ?>
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
<section class="section section--gray">
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
<section class="section">
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

		<div class="events-grid">
			<?php
			$events_list = new WP_Query( array(
				'post_type'      => 'event',
				'posts_per_page' => 3,
				'meta_key'       => '_event_date',
				'meta_value'     => current_time( 'Y-m-d' ),
				'meta_compare'   => '>=',
				'meta_type'      => 'DATE',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( $events_list->have_posts() ) :
				while ( $events_list->have_posts() ) : $events_list->the_post();
					get_template_part( 'template-parts/event', 'card' );
				endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Nenhum evento próximo no momento.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- Apoiadores -->
<section class="section">
	<div class="site-container">
		<div class="section-header">
			<div class="section-header-left">
				<span class="section-eyebrow"><?php esc_html_e( 'Apoiadores', 'conexao-br-irlanda' ); ?></span>
				<h2 class="section-title"><?php esc_html_e( 'Apoiadores', 'conexao-br-irlanda' ); ?></h2>
				<p class="section-subtitle"><?php esc_html_e( 'Conheça quem apoia e fortalece a nossa comunidade.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<a href="<?php echo esc_url( home_url( '/apoiadores/' ) ); ?>" class="section-link">
				<?php esc_html_e( 'Ver todos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="5" y1="12" x2="19" y2="12"></line>
					<polyline points="12 5 19 12 12 19"></polyline>
				</svg>
			</a>
		</div>

		<div class="businesses-grid">
			<?php
			$featured_sponsors = new WP_Query( array(
				'post_type'      => 'sponsor',
				'posts_per_page' => 4,
				'meta_key'       => '_sponsor_featured',
				'meta_value'     => '1',
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_sponsor_display_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );
			if ( ! $featured_sponsors->have_posts() ) {
				$featured_sponsors = new WP_Query( array(
					'post_type'      => 'sponsor',
					'posts_per_page' => 4,
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
			}
		if ( $featured_sponsors->have_posts() ) :
			while ( $featured_sponsors->have_posts() ) : $featured_sponsors->the_post();
				$sponsor_link = get_post_meta( get_the_ID(), '_sponsor_link', true );
				$terms = get_the_terms( get_the_ID(), 'conexao_category' );
				$sponsor_counties = get_the_terms( get_the_ID(), 'conexao_county' );
				// Determine if the card should be clickable (external link takes priority, then permalink).
				$card_link = $sponsor_link ? esc_url( $sponsor_link ) : get_permalink();
				$card_target = $sponsor_link ? ' target="_blank"' : '';
				$card_rel = $sponsor_link ? ' rel="noopener noreferrer"' : '';
				$card_classes = 'business-card' . ( $sponsor_link ? ' business-card--clickable' : '' );
				?>
				<div class="<?php echo esc_attr( $card_classes ); ?>">
					<a href="<?php echo esc_url( $card_link ); ?>" class="business-card-link"<?php echo $card_target . $card_rel; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
						<div class="business-logo">
							<?php if ( has_post_thumbnail() ) : ?>
								<?php the_post_thumbnail( 'thumbnail', array( 'loading' => 'lazy' ) ); ?>
							<?php else : ?>
								<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="#adb5bd" stroke-width="1.5">
									<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
									<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
								</svg>
							<?php endif; ?>
						</div>
						<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
							<span class="business-category"><?php echo esc_html( $terms[0]->name ); ?></span>
						<?php endif; ?>
						<h3 class="business-name"><?php the_title(); ?></h3>
						<?php if ( $sponsor_counties && ! is_wp_error( $sponsor_counties ) ) : ?><span class="business-location"><?php echo esc_html( $sponsor_counties[0]->name ); ?></span><?php endif; ?>
						<p class="business-description"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 12, '...' ) ); ?></p>
					</a>
				</div>
			<?php endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Organizações e empresas que apoiam a comunidade serão apresentados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- Latest Jobs -->
<section class="section section--gray">
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

<!-- Newsletter -->
<section class="newsletter-section">
	<div class="site-container">
		<div class="newsletter-content">
			<div class="newsletter-text">
				<h2 class="newsletter-title"><?php esc_html_e( 'Fique por dentro de tudo!', 'conexao-br-irlanda' ); ?></h2>
				<p class="newsletter-description"><?php esc_html_e( 'Receba as últimas notícias, eventos e guias práticos diretamente no seu email. Sem spam, apenas conteúdo relevante para brasileiros na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<div class="newsletter-form">
				<h3 class="newsletter-form-title"><?php esc_html_e( 'Assine nossa newsletter', 'conexao-br-irlanda' ); ?></h3>
				<p class="newsletter-form-subtitle"><?php esc_html_e( 'Junte-se a milhares de brasileiros que já recebem nossas atualizações.', 'conexao-br-irlanda' ); ?></p>
				<form class="newsletter-input-group" action="#" method="post">
					<input type="email" class="newsletter-input" placeholder="<?php esc_attr_e( 'Seu melhor email', 'conexao-br-irlanda' ); ?>" required>
					<button type="submit" class="newsletter-btn"><?php esc_html_e( 'Assinar', 'conexao-br-irlanda' ); ?></button>
				</form>
				<p class="newsletter-privacy"><?php esc_html_e( 'Ao assinar, você concorda com nossa política de privacidade.', 'conexao-br-irlanda' ); ?></p>
			</div>
		</div>
	</div>
</section>

<!-- Quote Section -->
<section class="quote-section">
	<div class="site-container">
		<div class="quote-content">
			<blockquote class="quote-text">"<?php esc_html_e( 'A vida é uma constante oportunidade de recomeçar. Cada dia é uma nova chance de construir algo melhor.', 'conexao-br-irlanda' ); ?>"</blockquote>
			<p class="quote-author">— <?php esc_html_e( 'Mário Sérgio Cortella', 'conexao-br-irlanda' ); ?></p>
		</div>
	</div>
</section>

</main>

<?php get_footer();
