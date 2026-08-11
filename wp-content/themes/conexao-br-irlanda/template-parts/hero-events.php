<?php
/**
 * Hero Events Widget Template Part
 *
 * Renders a large "Próximos Eventos" panel that fills the hero area.
 * Uses a featured event with a prominent banner followed by compact
 * upcoming event list items. Each event links to its event page.
 *
 * Relies on the WordPress loop; expected custom fields:
 *   - _event_date       (Y-m-d)
 *   - _event_time
 *   - _event_location
 *   - _event_url        (destination URL; defaults to permalink)
 *   - _event_banner     (banner image URL; falls back to featured image)
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

	<?php
	$hero_event_index = 0;
	$hero_list_open   = false;

	while ( $hero_events->have_posts() ) :
		$hero_events->the_post();
		$hero_event_index++;

		$event_id       = get_the_ID();
		$event_url      = get_post_meta( $event_id, '_event_url', true );
		$event_url      = $event_url ? $event_url : get_permalink();
		$event_banner   = get_post_meta( $event_id, '_event_banner', true );
		$event_date     = get_post_meta( $event_id, '_event_date', true );
		$event_time     = get_post_meta( $event_id, '_event_time', true );
		$event_location = get_post_meta( $event_id, '_event_location', true );

		$day   = $event_date ? date( 'd', strtotime( $event_date ) ) : '--';
		$month = $event_date ? date( 'M', strtotime( $event_date ) ) : '---';

		if ( 1 === $hero_event_index ) : ?>

			<a class="hero-event-featured" href="<?php echo esc_url( $event_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Ver evento: %s', get_the_title() ) ); ?>">
				<div class="hero-event-featured-banner">
					<?php if ( $event_banner ) : ?>
						<img src="<?php echo esc_url( $event_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
					<?php elseif ( has_post_thumbnail() ) : ?>
						<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
					<?php else : ?>
						<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
					<?php endif; ?>

					<span class="hero-event-date-badge hero-event-date-badge--lg">
						<span class="hero-event-date-day"><?php echo esc_html( $day ); ?></span>
						<span class="hero-event-date-month"><?php echo esc_html( $month ); ?></span>
					</span>
				</div>

				<div class="hero-event-featured-body">
					<h4 class="hero-event-featured-title"><?php the_title(); ?></h4>

					<div class="hero-event-meta">
						<?php if ( $event_time ) : ?>
							<span class="hero-event-time">
								<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
									<circle cx="12" cy="12" r="10"></circle>
									<polyline points="12 6 12 12 16 14"></polyline>
								</svg>
								<?php echo esc_html( $event_time ); ?>
							</span>
						<?php endif; ?>

						<?php if ( $event_location ) : ?>
							<span class="hero-event-location">
								<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
									<circle cx="12" cy="10" r="3"></circle>
								</svg>
								<?php echo esc_html( $event_location ); ?>
							</span>
						<?php endif; ?>
					</div>

					<p class="hero-event-featured-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 16, '...' ) ); ?></p>
				</div>
			</a>

		<?php else : ?>

			<?php
			if ( ! $hero_list_open ) {
				echo '<div class="hero-events-list">';
				$hero_list_open = true;
			}
			?>

			<a class="hero-event-card" href="<?php echo esc_url( $event_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Ver evento: %s', get_the_title() ) ); ?>">
				<div class="hero-event-thumb">
					<?php if ( $event_banner ) : ?>
						<img src="<?php echo esc_url( $event_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
					<?php elseif ( has_post_thumbnail() ) : ?>
						<?php the_post_thumbnail( 'thumbnail', array( 'loading' => 'lazy' ) ); ?>
					<?php else : ?>
						<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
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
								<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
									<circle cx="12" cy="12" r="10"></circle>
									<polyline points="12 6 12 12 16 14"></polyline>
								</svg>
								<?php echo esc_html( $event_time ); ?>
							</span>
						<?php endif; ?>

						<?php if ( $event_location ) : ?>
							<span class="hero-event-location">
								<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
									<circle cx="12" cy="10" r="3"></circle>
								</svg>
								<?php echo esc_html( $event_location ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</a>

		<?php endif; ?>

	<?php
	endwhile;
	if ( $hero_list_open ) {
		echo '</div>';
	}
	wp_reset_postdata();
	?>

	<a href="<?php echo esc_url( home_url( '/events/' ) ); ?>" class="hero-events-cta">
		<?php esc_html_e( 'Ver todos os eventos', 'conexao-br-irlanda' ); ?>
		<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
			<line x1="5" y1="12" x2="19" y2="12"></line>
			<polyline points="12 5 19 12 12 19"></polyline>
		</svg>
	</a>
</div>