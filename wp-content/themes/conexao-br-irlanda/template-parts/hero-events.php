<?php
/**
 * Hero Events Widget Template Part
 *
 * Renders a large "Próximos Eventos" panel that fills the hero area.
 * Each event uses the same compact card layout: a fixed thumbnail with
 * an overlaid date badge on the left, and the event information on the
 * right. Every event links to its event page (or external source URL).
 *
 * Relies on the WordPress loop; expected custom fields:
 *   - _event_date       (Y-m-d)
 *   - _event_time
 *   - _event_location
 *   - _event_url        (destination URL; defaults to permalink)
 *   - _event_banner     (banner image URL; falls back to featured image)
 *   - _event_banner_attachment_id (validated WordPress attachment)
 *
 * @package Conexao_BR_Irlanda
 */

$hero_events = new WP_Query( array(
	'post_type'              => 'event',
	'posts_per_page'         => 4,
	'meta_key'               => '_event_date',
	'meta_value'             => current_time( 'Y-m-d' ),
	'meta_compare'           => '>=',
	'meta_type'              => 'DATE',
	'orderby'                => 'meta_value',
	'order'                  => 'ASC',
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
) );

if ( ! $hero_events->have_posts() ) {
	return;
}
?>

<div class="hero-events-widget">
	<header class="hero-events-header">
		<h3 class="hero-events-title">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
				<line x1="16" y1="2" x2="16" y2="6"></line>
				<line x1="8" y1="2" x2="8" y2="6"></line>
				<line x1="3" y1="10" x2="21" y2="10"></line>
			</svg>
			<?php esc_html_e( 'Próximos Eventos', 'conexao-br-irlanda' ); ?>
		</h3>
		<span class="hero-events-count"><?php echo esc_html( $hero_events->post_count ); ?> <?php esc_html_e( 'na agenda', 'conexao-br-irlanda' ); ?></span>
	</header>

	<div class="hero-events-list">
		<?php
		while ( $hero_events->have_posts() ) :
			$hero_events->the_post();

			$event_id       = get_the_ID();
			$event_url      = get_post_meta( $event_id, '_event_url', true );
			$event_url      = $event_url ? $event_url : get_permalink();
			$event_target   = conexao_event_link_target_attrs( $event_id );
			$event_banner   = get_post_meta( $event_id, '_event_banner', true );
			$banner_attach  = get_post_meta( $event_id, '_event_banner_attachment_id', true );
			$event_date     = get_post_meta( $event_id, '_event_date', true );
			$event_time     = get_post_meta( $event_id, '_event_time', true );
			$event_location = get_post_meta( $event_id, '_event_location', true );

			$day   = $event_date ? date( 'd', strtotime( $event_date ) ) : '--';
			$month = $event_date ? date( 'M', strtotime( $event_date ) ) : '---';

			// Resolve the best image source:
			// 1. Validated WordPress attachment (preferred).
			// 2. Post thumbnail (featured image).
			// 3. External banner URL (only if it looks like a valid URL).
			// 4. Theme placeholder.
			$has_attachment = $banner_attach && wp_attachment_is_image( $banner_attach );
			if ( ! $has_attachment ) {
				$has_attachment = has_post_thumbnail( $event_id );
			}

			$has_valid_banner = false;
			if ( ! $has_attachment && $event_banner ) {
				$has_valid_banner = ( false !== filter_var( $event_banner, FILTER_VALIDATE_URL ) );
			}
			?>

			<a class="hero-event-card" href="<?php echo esc_url( $event_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Ver evento: %s', get_the_title() ) ); ?>"<?php echo $event_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
				<div class="hero-event-thumb">
					<?php if ( $has_attachment ) : ?>
						<?php
						if ( $banner_attach && wp_attachment_is_image( $banner_attach ) ) {
							echo wp_get_attachment_image(
								$banner_attach,
								'conexao-thumb',
								false,
								array(
									'class'   => 'hero-event-thumb-img',
									'loading' => 'lazy',
									'alt'     => '',
								)
							);
						} else {
							the_post_thumbnail( 'conexao-thumb', array( 'class' => 'hero-event-thumb-img', 'loading' => 'lazy', 'alt' => '' ) );
						}
						?>
					<?php elseif ( $has_valid_banner ) : ?>
						<img class="hero-event-thumb-img" src="<?php echo esc_url( $event_banner ); ?>" alt="" loading="lazy">
					<?php else : ?>
						<img class="hero-event-thumb-img" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
					<?php endif; ?>

					<span class="hero-event-date-badge">
						<span class="hero-event-date-day"><?php echo esc_html( $day ); ?></span>
						<span class="hero-event-date-month"><?php echo esc_html( $month ); ?></span>
					</span>
				</div>

				<div class="hero-event-info">
					<h4 class="hero-event-title"><?php the_title(); ?></h4>

					<div class="hero-event-meta">
						<?php if ( $event_time ) : ?>
							<span class="hero-event-time">
								<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<circle cx="12" cy="12" r="10"></circle>
									<polyline points="12 6 12 12 16 14"></polyline>
								</svg>
								<?php echo esc_html( $event_time ); ?>
							</span>
						<?php endif; ?>

						<?php if ( $event_location ) : ?>
							<span class="hero-event-location">
								<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
									<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
									<circle cx="12" cy="10" r="3"></circle>
								</svg>
								<?php echo esc_html( $event_location ); ?>
							</span>
						<?php endif; ?>
					</div>

					<?php if ( has_excerpt() ) : ?>
						<p class="hero-event-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>
					<?php endif; ?>
				</div>
			</a>

		<?php endwhile; ?>
	</div>

	<a href="<?php echo esc_url( home_url( '/events/' ) ); ?>" class="hero-events-cta">
		<?php esc_html_e( 'Ver todos os eventos', 'conexao-br-irlanda' ); ?>
		<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
			<line x1="5" y1="12" x2="19" y2="12"></line>
			<polyline points="12 5 19 12 12 19"></polyline>
		</svg>
	</a>
</div>
<?php
wp_reset_postdata();