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

	// Phase 3B — resolved map URL (existing canonical map link first, then a
	// deterministic Google Maps search URL derived at render time from
	// title/town/county) and display-only authoritative links for internal
	// records. Generated on the fly: no API, no geocoding, no remote requests.
	$leisure_map_resolved = conexao_leisure_map_url( $leisure_id );
	$leisure_info_links   = conexao_leisure_authoritative_links( $leisure_id );

	// Phase 2 — practical-information fields. The source URL is only ever
	// rendered as a labelled display-only authoritative link via
	// conexao_leisure_authoritative_links() ("Mais informações") so the
	// verification message stays truthful — raw source URLs/field names must
	// not leak onto the page.
	$leisure_practical_notes       = get_post_meta( $leisure_id, '_leisure_practical_notes', true );
	$leisure_practical_source_url  = get_post_meta( $leisure_id, '_leisure_practical_source_url', true ); // Labelled output only, via conexao_leisure_authoritative_links().
	$leisure_practical_last_checked = get_post_meta( $leisure_id, '_leisure_practical_last_checked', true );

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
								<?php // Phase 3A — real internal links to the existing Lazer category filter (/lazer/?categoria=slug). ?>
								<a class="hero-category" href="<?php echo esc_url( conexao_leisure_category_filter_url( $category->slug ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver lugares de lazer na categoria %s', 'conexao-br-irlanda' ), $category->name ) ); ?>"><?php echo esc_html( $category->name ); ?></a>
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
							<?php // Phase 3A — the county links to the existing Lazer location filter (/lazer/?county=slug). Towns have no filter and stay as display text. ?>
							<a class="leisure-single-location-link" href="<?php echo esc_url( conexao_leisure_county_filter_url( $leisure_counties[0]->slug ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver lugares de lazer em %s', 'conexao-br-irlanda' ), $leisure_counties[0]->name ) ); ?>"><?php echo esc_html( $leisure_counties[0]->name ); ?></a>
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

				<?php
				// Phase 3B — the location block is fully conditional: it only
				// renders when it has something meaningful to show (never an
				// empty "Localização e Contato" section). The map link prefers
				// existing canonical data (_leisure_map_url / verified
				// address) and falls back to a deterministic Google Maps
				// search URL derived from title + town + county at render
				// time (conexao_leisure_map_url(), functions.php).
				$leisure_block_county = ( $leisure_counties && ! is_wp_error( $leisure_counties ) ) ? $leisure_counties[0]->name : '';
				$leisure_block_title  = ( $leisure_address || $leisure_website )
					? __( 'Localização e Contato', 'conexao-br-irlanda' )
					: __( 'Localização', 'conexao-br-irlanda' );
				// The legacy website row is skipped when the same URL is
				// already displayed as an authoritative link.
				$leisure_show_website = $leisure_website && ! in_array( $leisure_website, wp_list_pluck( $leisure_info_links, 'url' ), true );
				?>
				<?php if ( $leisure_address || $leisure_town || $leisure_block_county || $leisure_show_website || $leisure_map_resolved || ! empty( $leisure_attr_names ) || $leisure_duration || $leisure_best_time ) : ?>
					<div class="leisure-single-info">

						<?php if ( $leisure_address || $leisure_town || $leisure_block_county || $leisure_show_website || $leisure_map_resolved ) : ?>
							<div class="leisure-single-block leisure-single--details">
								<h2 class="leisure-single-block-title"><?php echo esc_html( $leisure_block_title ); ?></h2>
								<ul class="leisure-single-list">
									<?php if ( $leisure_town || $leisure_block_county ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
												<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
												<circle cx="12" cy="10" r="3"></circle>
											</svg>
											<span><?php echo esc_html( implode( ', ', array_filter( array( $leisure_town, $leisure_block_county ) ) ) ); ?></span>
										</li>
									<?php endif; ?>
									<?php if ( $leisure_address ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
												<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
												<circle cx="12" cy="10" r="3"></circle>
											</svg>
											<span><?php echo esc_html( $leisure_address ); ?></span>
										</li>
									<?php endif; ?>
									<?php if ( $leisure_show_website ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
												<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
												<polyline points="15 3 21 3 21 9"></polyline>
												<line x1="10" y1="14" x2="21" y2="3"></line>
											</svg>
											<a href="<?php echo esc_url( $leisure_website ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Site oficial', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (abre em nova aba)', 'conexao-br-irlanda' ); ?></span></a>
										</li>
									<?php endif; ?>
									<?php foreach ( $leisure_info_links as $leisure_info_link ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
												<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
												<polyline points="15 3 21 3 21 9"></polyline>
												<line x1="10" y1="14" x2="21" y2="3"></line>
											</svg>
											<a href="<?php echo esc_url( $leisure_info_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $leisure_info_link['label'] ); ?><span class="screen-reader-text"><?php esc_html_e( ' (abre em nova aba)', 'conexao-br-irlanda' ); ?></span></a>
										</li>
									<?php endforeach; ?>
									<?php if ( $leisure_map_resolved ) : ?>
										<li class="leisure-single-item">
											<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
												<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon>
												<line x1="8" y1="2" x2="8" y2="18"></line>
												<line x1="16" y1="6" x2="16" y2="22"></line>
											</svg>
											<a href="<?php echo esc_url( $leisure_map_resolved ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'Ver localização de %s no mapa (abre em nova aba)', 'conexao-br-irlanda' ), get_the_title() ) ); ?>"><?php esc_html_e( 'Ver localização no mapa', 'conexao-br-irlanda' ); ?></a>
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
				// Phase 2 — practical notes + verification (individual page only).
			if ( $leisure_practical_notes ) :
			?>
				<div class="leisure-single-block leisure-single--practical">
					<h2 class="leisure-single-block-title"><?php esc_html_e( 'Observações práticas', 'conexao-br-irlanda' ); ?></h2>
					<div class="leisure-practical-notes">
						<?php echo wp_kses_post( wpautop( $leisure_practical_notes ) ); ?>
					</div>
					<?php if ( $leisure_practical_last_checked ) : ?>
						<?php
						$checked_timestamp = strtotime( $leisure_practical_last_checked );
						$six_months_ago    = strtotime( '-6 months' );
						if ( $checked_timestamp && $checked_timestamp >= $six_months_ago ) :
							?>
							<p class="leisure-practical-verified">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: formatted date */
										__( 'Informação verificada em %s', 'conexao-br-irlanda' ),
										date_i18n( get_option( 'date_format' ), $checked_timestamp )
									)
								);
								?>
							</p>
						<?php else : ?>
							<p class="leisure-practical-stale">
								<?php
								// Phase 3B — the verification message must be
								// truthful: it only points to an external source
								// when a real, displayable authoritative link
								// exists (the page stays internal). Never imply a
								// link that is not available.
								$leisure_stale_link = ! empty( $leisure_info_links ) ? $leisure_info_links[0] : array();
								if ( ! empty( $leisure_stale_link ) ) :
									if ( 'Site oficial' === $leisure_stale_link['label'] ) :
										?>
										<a href="<?php echo esc_url( $leisure_stale_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verifique as informações no site oficial', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (abre em nova aba)', 'conexao-br-irlanda' ); ?></span></a>.
									<?php elseif ( 'Ver no Discover Ireland' === $leisure_stale_link['label'] ) : ?>
										<a href="<?php echo esc_url( $leisure_stale_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verifique as informações no Discover Ireland', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (abre em nova aba)', 'conexao-br-irlanda' ); ?></span></a>.
									<?php else : ?>
										<?php esc_html_e( 'Verifique as informações na fonte:', 'conexao-br-irlanda' ); ?>
										<a href="<?php echo esc_url( $leisure_stale_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Mais informações', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (abre em nova aba)', 'conexao-br-irlanda' ); ?></span></a>.
									<?php endif; ?>
								<?php else : ?>
									<?php esc_html_e( 'Informações podem estar desatualizadas. Confirme os detalhes diretamente com a atração antes da sua visita.', 'conexao-br-irlanda' ); ?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php
			endif;

			// Related leisure locations in the same county / category.
			// Phase 3A — a genuine internal navigation hub: selection runs
			// through conexao_leisure_related_internal_destinations()
			// (functions.php), which only returns records that do NOT
			// qualify for the external redirect (classified via the
			// canonical conexao_leisure_external_url() in inc/seo.php).
			// Same county first, same-category supplement, up to 3 results;
			// when none are eligible the whole section stays hidden.
				$related_posts = conexao_leisure_related_internal_destinations( $leisure_id );

				if ( ! empty( $related_posts ) ) : ?>
					<section class="related-posts">
						<h3 class="related-posts-title"><?php esc_html_e( 'Outros lugares relacionados', 'conexao-br-irlanda' ); ?></h3>
						<div class="related-posts-grid">
							<?php foreach ( $related_posts as $related_post ) : ?>
								<?php
								// setup_postdata() does not assign the global $post
								// itself — do it explicitly so the_title()/the_permalink()
								// resolve against this related destination.
								$GLOBALS['post'] = $related_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
								setup_postdata( $related_post );
								?>
								<?php
								$related_id       = get_the_ID();
								$related_cats     = get_the_terms( $related_id, 'conexao_category' );
								$related_counties = get_the_terms( $related_id, 'conexao_county' );
								$related_cat      = ( $related_cats && ! is_wp_error( $related_cats ) ) ? $related_cats[0] : null;
								$related_county   = ( $related_counties && ! is_wp_error( $related_counties ) ) ? $related_counties[0] : null;
								?>
								<article class="related-post-card">
									<?php if ( has_post_thumbnail() ) : ?>
										<?php
										$r_alt = get_post_meta( $related_id, '_leisure_image_alt_text', true );
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
										<p class="related-post-meta">
											<?php if ( $related_cat ) : ?>
												<a class="related-post-meta-link" href="<?php echo esc_url( conexao_leisure_category_filter_url( $related_cat->slug ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver mais lugares na categoria %s', 'conexao-br-irlanda' ), $related_cat->name ) ); ?>"><?php echo esc_html( $related_cat->name ); ?></a>
											<?php endif; ?>
											<?php if ( $related_cat && $related_county ) : ?>
												<span class="related-post-meta-sep" aria-hidden="true">&middot;</span>
											<?php endif; ?>
											<?php if ( $related_county ) : ?>
												<a class="related-post-meta-link" href="<?php echo esc_url( conexao_leisure_county_filter_url( $related_county->slug ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver lugares de lazer em %s', 'conexao-br-irlanda' ), $related_county->name ) ); ?>"><?php echo esc_html( $related_county->name ); ?></a>
											<?php endif; ?>
										</p>
									</div>
								</article>
							<?php endforeach; ?>
							<?php wp_reset_postdata(); ?>
						</div>
					</section>
				<?php endif;
				?>

			</article>

		</main>
	</div>

<?php endwhile; ?>

<?php get_footer();
