<?php get_header(); ?>

<!-- Hero Section -->
<section class="hero-section">
	<div class="site-container">
		<div class="hero-content">
			<h1 class="hero-title"><?php esc_html_e( 'SUA REVISTA DIGITAL PARA BRASILEIROS NA IRLANDA', 'conexao-br-irlanda' ); ?></h1>
			<p class="hero-subtitle"><?php esc_html_e( 'Conectando a comunidade brasileira na Irlanda com informações, eventos, cursos e muito mais.', 'conexao-br-irlanda' ); ?></p>
			<div class="hero-categories">
				<?php foreach ( array( 'Eventos', 'Empregos', 'Cursos', 'Passeios', 'Família', 'Negócios' ) as $category_name ) {
					$category = get_category_by_slug( sanitize_title( $category_name ) );
					if ( $category ) { echo '<a href="' . esc_url( get_category_link( $category->term_id ) ) . '" class="hero-category">' . esc_html( $category_name ) . '</a>'; }
					else { echo '<span class="hero-category">' . esc_html( $category_name ) . '</span>'; }
				} ?>
			</div>
			<div class="hero-ctas">
				<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Eventos da Semana', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/contato/' ) ); ?>" class="btn btn-outline"><?php esc_html_e( 'Seja um Apoiador', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" class="btn btn-outline"><?php esc_html_e( 'Empregos', 'conexao-br-irlanda' ); ?></a>
			</div>
		</div>
	</div>
</section>

<!-- Latest Posts -->
<section class="section">
	<div class="site-container">
		<div class="section-header">
			<h2 class="section-title"><?php esc_html_e( 'Últimas Publicações', 'conexao-br-irlanda' ); ?></h2>
			<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" class="section-link"><?php esc_html_e( 'Ver todos →', 'conexao-br-irlanda' ); ?></a>
		</div>
		<?php
		$latest_posts = new WP_Query( array( 'posts_per_page' => 6, 'ignore_sticky_posts' => true, 'no_found_rows' => true ) );
		if ( $latest_posts->have_posts() ) : $count = 0; ?>
			<?php while ( $latest_posts->have_posts() ) : $latest_posts->the_post(); ?>
				<?php if ( 0 === $count ) : ?>
					<article class="featured-post">
						<?php if ( has_post_thumbnail() ) : ?>
							<div class="featured-post-image"><a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-hero', array( 'loading' => 'lazy' ) ); ?></a></div>
						<?php endif; ?>
						<div class="featured-post-content">
							<span class="featured-post-tag"><?php esc_html_e( 'Destaque', 'conexao-br-irlanda' ); ?></span>
							<h3 class="featured-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<div class="card-meta"><?php conexao_post_meta(); ?></div>
							<p class="featured-post-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 25, '...' ) ); ?></p>
							<a href="<?php the_permalink(); ?>" class="btn btn-green"><?php esc_html_e( 'Ler mais', 'conexao-br-irlanda' ); ?></a>
						</div>
					</article>
				<?php else : ?>
					<?php if ( 1 === $count ) : ?><div class="cards-grid"><?php endif; ?>
					<article class="post-card">
						<?php if ( has_post_thumbnail() ) : ?>
							<div class="post-card-image"><a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a></div>
						<?php endif; ?>
						<div class="post-card-body">
							<h3 class="post-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<p class="post-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20, '...' ) ); ?></p>
							<div class="post-card-meta"><?php conexao_post_meta(); ?></div>
						</div>
					</article>
					<?php if ( $count === $latest_posts->post_count - 1 ) : ?></div><?php endif; ?>
				<?php endif; ?>
				<?php $count++; ?>
			<?php endwhile; wp_reset_postdata(); ?>
		<?php endif; ?>
	</div>
</section>

<!-- Sponsors -->
<section class="section sponsors-section">
	<div class="site-container">
		<div class="section-header">
			<h2 class="section-title"><?php esc_html_e( 'Empresas que Apoiam nosso Projeto', 'conexao-br-irlanda' ); ?></h2>
		</div>
		<div class="sponsors-grid">
			<?php
			$sponsors = new WP_Query( array( 'post_type' => 'sponsor', 'posts_per_page' => 8, 'orderby' => 'menu_order', 'order' => 'ASC', 'no_found_rows' => true ) );
			if ( $sponsors->have_posts() ) :
				while ( $sponsors->have_posts() ) : $sponsors->the_post();
					$sponsor_url = get_post_meta( get_the_ID(), '_conexao_external_url', true ); ?>
					<a href="<?php echo esc_url( $sponsor_url ? $sponsor_url : '#' ); ?>" class="sponsor-card" target="_blank" rel="noopener noreferrer">
						<?php if ( has_post_thumbnail() ) : ?><?php the_post_thumbnail( 'medium', array( 'loading' => 'lazy' ) ); ?><?php else : ?><span><?php the_title(); ?></span><?php endif; ?>
					</a>
				<?php endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Adicione seus patrocinadores em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- Directory -->
<section class="section directory-section">
	<div class="site-container">
		<div class="section-header">
			<h2 class="section-title"><?php esc_html_e( 'Diretório de Negócios', 'conexao-br-irlanda' ); ?></h2>
			<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" class="section-link"><?php esc_html_e( 'Ver todos →', 'conexao-br-irlanda' ); ?></a>
		</div>
		<div class="directory-grid">
			<?php
			$directory = new WP_Query( array( 'post_type' => 'directory_item', 'posts_per_page' => 6, 'orderby' => 'menu_order', 'order' => 'ASC', 'no_found_rows' => true ) );
			if ( $directory->have_posts() ) :
				while ( $directory->have_posts() ) : $directory->the_post();
					$item_url = get_post_meta( get_the_ID(), '_conexao_external_url', true );
					$terms    = get_the_terms( get_the_ID(), 'directory_category' ); ?>
					<a href="<?php echo esc_url( $item_url ? $item_url : '#' ); ?>" class="directory-card" target="_blank" rel="noopener noreferrer">
						<?php if ( has_post_thumbnail() ) : ?>
							<div class="directory-card-image"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></div>
						<?php endif; ?>
						<div class="directory-card-body">
							<h3 class="directory-card-title"><?php the_title(); ?></h3>
							<?php if ( $terms && ! is_wp_error( $terms ) ) : ?><span class="directory-card-category"><?php echo esc_html( $terms[0]->name ); ?></span><?php endif; ?>
						</div>
					</a>
				<?php endwhile; wp_reset_postdata();
			else : ?>
				<p><?php esc_html_e( 'Adicione itens ao diretório em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<!-- CTA -->
<section class="cta-section">
	<div class="site-container">
		<h2><?php esc_html_e( 'Faça parte da nossa comunidade!', 'conexao-br-irlanda' ); ?></h2>
		<p><?php esc_html_e( 'Siga-nos nas redes sociais e fique por dentro de tudo que acontece na comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ); ?></p>
		<div class="cta-buttons">
			<?php $instagram = get_theme_mod( 'conexao_instagram', 'https://www.instagram.com/conexaobr.ie/' ); $whatsapp = get_theme_mod( 'conexao_whatsapp', 'https://wa.me/353899451428' ); ?>
			<a href="<?php echo esc_url( $instagram ); ?>" class="btn btn-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Seguir no Instagram', 'conexao-br-irlanda' ); ?></a>
			<a href="<?php echo esc_url( $whatsapp ); ?>" class="btn btn-outline" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Falar no WhatsApp', 'conexao-br-irlanda' ); ?></a>
		</div>
	</div>
</section>

<!-- Quote -->
<section class="quote-section">
	<div class="site-container">
		<div class="quote-content">
			<blockquote class="quote-text">"<?php esc_html_e( 'A vida é uma constante oportunidade de recomeçar. Cada dia é uma nova chance de construir algo melhor.', 'conexao-br-irlanda' ); ?>"</blockquote>
			<p class="quote-author">— <?php esc_html_e( 'Mário Sérgio Cortella', 'conexao-br-irlanda' ); ?></p>
		</div>
	</div>
</section>

<?php get_footer();