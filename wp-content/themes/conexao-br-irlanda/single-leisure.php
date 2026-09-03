<?php
/**
 * Single Leisure / Tourism Location template.
 *
 * Renders the detail page for a /lazer/ entry. Shows the featured image,
 * name, county, categories, description, location info, official website,
 * map link, useful attributes and related leisure locations. No event
 * calendar is rendered here — events live exclusively under /eventos/.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();
?>

<?php while ( have_posts() ) : the_post(); ?>

	<?php
	$leisure_id            = get_the_ID();
	$leisure_town          = get_post_meta( $leisure_id, '_leisure_town', true );
	$leisure_address       = get_post_meta( $leisure_id, '_leisure_address', true );
	$leisure_official      = get_post_meta( $leisure_id, '_leisure_official_website', true );
	$leisure_website       = $leisure_official ? $leisure_official : get_post_meta( $leisure_id, '_leisure_website', true );
	$leisure_discover      = get_post_meta( $leisure_id, '_leisure_discover_ireland', true );
	$leisure_map_url       = get_post_meta( $leisure_id, '_leisure_map_url', true );
	$leisure_duration      = get_post_meta( $leisure_id, '_leisure_duration', true );
	$leisure_best_time     = get_post_meta( $leisure_id, '_leisure_best_time', true );

	// Resolve the display set for the practical attributes of this destination.
	// Primary source: the structured conexao_leisure_attribute taxonomy.
	// Fallback: legacy checkbox meta during the transition. Never render the
	// same attribute twice when both sources are present.
	$leisure_attr_names = array();

	$attr_terms = get_the_terms( $leisure_id, 'conexao_leisure_attribute' );
	if ( $attr_terms && ! is_wp_error( $attr_terms ) ) {
		foreach ( $attr_terms as $term ) {
			$leisure_attr_names[ sanitize_title( $term->name ) ] = $term->name;
		}
	}

	// Legacy fallback — only add names not already present from the taxonomy.
	$legacy_attr_map = array(
		'_leisure_family'        => array( 'familias', 'Famílias' ),
		'_leisure_outdoor'       => array( 'exterior', 'Exterior' ),
		'_leisure_indoor'        => array( 'interior', 'Interior' ),
		'_leisure_booking'       => array( 'necessita-reserva', 'Necessita reserva' ),
		'_leisure_accessibility' => array( 'acessivel', 'Acessível' ),
		'_leisure_pet_friendly'  => array( 'pet-friendly', 'Pet friendly' ),
		'_leisure_parking'       => array( 'estacionamento', 'Estacionamento' ),
	);
	foreach ( $legacy_attr_map as $meta_key => $mapping ) {
		if ( '1' === (string) get_post_meta( $leisure_id, $meta_key, true ) && ! isset( $leisure_attr_names[ $mapping[0] ] ) ) {
			$leisure_attr_names[ $mapping[0] ] = $mapping[1];
		}
	}
	$legacy_free = get_post_meta( $leisure_id, '_leisure_free', true );
	if ( '' !== (string) $legacy_free ) {
		$free_lower = mb_strtolower( (string) $legacy_free, 'UTF-8' );
		$is_free    = ( '1' === (string) $legacy_free )
			|| false !== strpos( $free_lower, 'gratuit' )
			|| false !== strpos( $free_lower, 'free' )
			|| false !== strpos( $free_lower, 'grátis' );
		if ( $is_free && ! isset( $leisure_attr_names['gratuito'] ) ) {
			$leisure_attr_names['gratuito'] = 'Gratuito';
		}
	}

	// Image metadata for hero + attribution.
	$leisure_alt_text     = get_post_meta( $leisure_id, '_leisure_image_alt_text', true );
	$leisure_alt          = $leisure_alt_text ? $leisure_alt_text : get_the_title();
	$leisure_src_url      = get_post_meta( $leisure_id, '_leisure_image_source_url', true );
	$leisure_source_label = get_post_meta( $leisure_id, '_leisure_image_source', true );
	$leisure_author       = get_post_meta( $leisure_id, '_leisure_image_author', true );
	$leisure_license      = get_post_meta( $leisure_id, '_leisure_image_license', true );
	$leisure_attribution  = get_post_meta( $leisure_id, '_leisure_image_attribution', true );

	$leisure_categories = get_the_terms( $leisure_id, 'conexao_category' );
	$leisure_counties   = get_the_terms( $leisure_id, 'conexao_county' );
	?>

	<div class="site-container leisure-single">
		<main id="primary" class="content-area">

			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

				<header class="leisure-single-header">
					<?php if ( $leisure_categories && ! is_wp_error( $leisure_categories ) ) : ?>
						<div class="post-categories">
							<?php foreach ( $leisure_categories as $category ) : ?>
								<span class="hero-category"><?php echo esc_html( $category->name ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h1 class="entry-title"><?php the_title(); ?></h1>

					<?php if ( $leisure_counties && ! is_wp_error( $leisure_counties ) ) : ?>
						<div class="leisure-single-location">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
								<circle cx="12" cy="10" r="3"></circle>
							</svg>
							<span><?php echo esc_html( $leisure_counties[0]->name ); ?></span>
							<?php if ( $leisure_town ) : ?>
								<span class="leisure-single-location-sep">&middot;</span>
								<span><?php echo esc_html( $leisure_town ); ?></span>
							<?php endif; ?>
						</div>
					<?php elseif ( $leisure_town ) : ?>
						<div class="leisure-single-location">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
								<circle cx="12" cy="10" r="3"></circle>
							</svg>
							<span><?php echo esc_html( $leisure_town ); ?></span>
						</div>
					<?php endif; ?>
				</header>

				<?php
				// Hero image: always a local WordPress Media Library attachment
				// (featured thumbnail). When no image is available, no hero is
				// shown.
				$leisure_hero_html = '';
				if ( has_post_thumbnail() ) {
					$leisure_hero_html = get_the_post_thumbnail( $leisure_id, 'conexao-hero', array( 'loading' => 'eager', 'alt' => esc_attr( $leisure_alt ) ) );
				}

				if ( $leisure_hero_html ) : ?>
					<figure class="leisure-single-hero">
						<?php echo $leisure_hero_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $leisure_attribution || $leisure_author || $leisure_license ) : ?>
							<figcaption class="leisure-single-hero-caption">
								<?php
								$display_attr = $leisure_attribution;
								if ( ! $display_attr && $leisure_author && $leisure_license ) {
									$display_attr = sprintf( 'Foto: %s, %s, %s', $leisure_author, $leisure_license, $leisure_source_label ? $leisure_source_label : 'Wikimedia Commons' );
								} elseif ( ! $display_attr && $leisure_author ) {
									$display_attr = 'Foto: ' . $leisure_author;
								}
								echo esc_html( $display_attr );
								?>
								<?php if ( $leisure_src_url ) : ?>
									<a href="<?php echo esc_url( $leisure_src_url ); ?>" target="_blank" rel="noopener noreferrer">↗ <?php esc_html_e( 'Ver no Wikimedia Commons', 'conexao-br-irlanda' ); ?></a>
								<?php endif; ?>
							</figcaption>
						<?php endif; ?>
					</figure>
				<?php endif; ?>

				<div class="leisure-single-content">
					<?php the_content(); ?>
				</div>

				<?php if ( $leisure_address || $leisure_website || $leisure_map_url || ! empty( $leisure_attr_names ) || $leisure_duration || $leisure_best_time ) : ?>
					<div class="leisure-single-info">

						<?php if ( $leisure_address || $leisure_website || $leisure_map_url ) : ?>
							<div class="leisure-single-block leisure-single--details">
								<h2 class="leisure-single-block-title"><?php esc_html_e( 'Localização e Contato', 'conexao-br-irlanda' ); ?></h2>
								<ul class="leisure-single-list">
									<?php if ( $leisure_address ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
												<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
												<circle cx="12" cy="10" r="3"></circle>
											</svg>
											<span><?php echo esc_html( $leisure_address ); ?></span>
										</li>
									<?php endif; ?>
									<?php if ( $leisure_website ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
												<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
												<polyline points="15 3 21 3 21 9"></polyline>
												<line x1="10" y1="14" x2="21" y2="3"></line>
											</svg>
											<a href="<?php echo esc_url( $leisure_website ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Site oficial', 'conexao-br-irlanda' ); ?></a>
										</li>
									<?php endif; ?>
									<?php if ( $leisure_map_url ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
												<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
												<line x1="8" y1="2" x2="8" y2="18"></line>
												<line x1="16" y1="6" x2="16" y2="22"></line>
											</svg>
											<a href="<?php echo esc_url( $leisure_map_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver no mapa', 'conexao-br-irlanda' ); ?></a>
										</li>
									<?php endif; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $leisure_attr_names ) || $leisure_duration || $leisure_best_time ) : ?>
							<div class="leisure-single-block leisure-single--attrs">
								<h2 class="leisure-single-block-title"><?php esc_html_e( 'Informações úteis', 'conexao-br-irlanda' ); ?></h2>
								<ul class="leisure-attr-grid">
									<?php foreach ( $leisure_attr_names as $attr_name ) : ?>
										<li class="leisure-attr-item leisure-attr-yes"><?php echo esc_html( $attr_name ); ?></li>
									<?php endforeach; ?>
									<?php if ( $leisure_duration ) : ?>
										<li class="leisure-attr-item"><strong><?php esc_html_e( 'Duração', 'conexao-br-irlanda' ); ?>:</strong> <?php echo esc_html( $leisure_duration ); ?></li>
									<?php endif; ?>
									<?php if ( $leisure_best_time ) : ?>
										<li class="leisure-attr-item"><strong><?php esc_html_e( 'Melhor época', 'conexao-br-irlanda' ); ?>:</strong> <?php echo esc_html( $leisure_best_time ); ?></li>
									<?php endif; ?>
								</ul>
							</div>
						<?php endif; ?>

					</div>
				<?php endif; ?>

				<?php
				// Related leisure locations in the same county / category.
				$related_tax = array();
				if ( $leisure_counties && ! is_wp_error( $leisure_counties ) ) {
					$related_tax[] = array(
						'taxonomy' => 'conexao_county',
						'field'    => 'term_id',
						'terms'    => wp_list_pluck( $leisure_counties, 'term_id' ),
					);
				}
				if ( $leisure_categories && ! is_wp_error( $leisure_categories ) ) {
					$related_tax[] = array(
						'taxonomy' => 'conexao_category',
						'field'    => 'term_id',
						'terms'    => wp_list_pluck( $leisure_categories, 'term_id' ),
					);
				}

				$related = new WP_Query( array(
					'post_type'      => 'leisure',
					'post__not_in'   => array( $leisure_id ),
					'posts_per_page' => 3,
					'no_found_rows'  => true,
					'tax_query'      => array_merge( array( 'relation' => 'OR' ), $related_tax ),
				) );

				if ( $related->have_posts() ) : ?>
					<section class="related-posts">
						<h3 class="related-posts-title"><?php esc_html_e( 'Outros lugares relacionados', 'conexao-br-irlanda' ); ?></h3>
						<div class="related-posts-grid">
							<?php while ( $related->have_posts() ) : $related->the_post(); ?>
								<article class="related-post-card">
									<?php if ( has_post_thumbnail() ) : ?>
										<?php
										$r_alt = get_post_meta( get_the_ID(), '_leisure_image_alt_text', true );
										if ( ! $r_alt ) {
											$r_alt = get_the_title();
										}
										?>
										<a href="<?php the_permalink(); ?>" class="related-post-thumb">
											<?php the_post_thumbnail( 'medium', array( 'loading' => 'lazy', 'alt' => esc_attr( $r_alt ) ) ); ?>
										</a>
									<?php endif; ?>
									<div class="related-post-content">
										<h4 class="related-post-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
										<span class="related-post-date">
											<?php
											$r_counties = get_the_terms( get_the_ID(), 'conexao_county' );
											echo $r_counties && ! is_wp_error( $r_counties ) ? esc_html( $r_counties[0]->name ) : '';
											?>
										</span>
									</div>
								</article>
							<?php endwhile; ?>
						</div>
					</section>
				<?php endif;
				wp_reset_postdata();
				?>

			</article>

		</main>
	</div>

<?php endwhile; ?>

<?php get_footer();
