<?php
/**
 * Event Card Template Part
 *
 * Renders a single event card with a fully clickable banner, title,
 * date/time, location, category, short description and registration info.
 *
 * Relies on the WordPress loop; expected custom fields:
 *   - _event_date       (Y-m-d)
 *   - _event_time
 *   - _event_location
 *   - _event_url        (destination URL the banner links to; defaults to permalink)
 *   - _event_banner     (banner image URL; falls back to the featured image)
 *   - _event_registration (registration / booking information)
 *
 * @package Conexao_BR_Irlanda
 */

$event_id         = get_the_ID();
$event_url        = get_post_meta( $event_id, '_event_url', true );
$event_url        = $event_url ? $event_url : get_permalink();
$event_banner     = get_post_meta( $event_id, '_event_banner', true );
$event_date       = get_post_meta( $event_id, '_event_date', true );
$event_time       = get_post_meta( $event_id, '_event_time', true );
$event_location   = get_post_meta( $event_id, '_event_location', true );
$event_reg        = get_post_meta( $event_id, '_event_registration', true );
$event_counties   = get_the_terms( $event_id, 'conexao_county' );
$event_categories = get_the_terms( $event_id, 'conexao_category' );

$day   = $event_date ? date( 'd', strtotime( $event_date ) ) : '--';
$month = $event_date ? date( 'M', strtotime( $event_date ) ) : '---';
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'event-card' ); ?>>

	<a class="event-card-banner" href="<?php echo esc_url( $event_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Banner para %s', get_the_title() ) ); ?>">
		<?php if ( $event_banner ) : ?>
			<img class="event-card-banner-img" src="<?php echo esc_url( $event_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
		<?php elseif ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'conexao-card', array( 'class' => 'event-card-banner-img', 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<img class="event-card-banner-img" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
		<?php endif; ?>

		<div class="event-card-date-badge">
			<span class="event-card-date-day"><?php echo esc_html( $day ); ?></span>
			<span class="event-card-date-month"><?php echo esc_html( $month ); ?></span>
		</div>
	</a>

	<div class="event-card-body">
		<?php if ( $event_categories && ! is_wp_error( $event_categories ) ) : ?>
			<span class="event-card-category">
				<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
					<line x1="7" y1="7" x2="7.01" y2="7"></line>
				</svg>
				<?php echo esc_html( $event_categories[0]->name ); ?>
			</span>
		<?php endif; ?>

		<h3 class="event-card-title"><a href="<?php echo esc_url( $event_url ); ?>"><?php the_title(); ?></a></h3>

		<div class="event-card-details">
			<?php if ( $event_date ) : ?>
				<span class="event-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
						<line x1="16" y1="2" x2="16" y2="6"></line>
						<line x1="8" y1="2" x2="8" y2="6"></line>
						<line x1="3" y1="10" x2="21" y2="10"></line>
					</svg>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event_date ) ) ); ?>
					<?php if ( $event_time ) : ?>
						<span class="event-card-time-sep">&middot;</span>
						<span class="event-card-time"><?php echo esc_html( $event_time ); ?></span>
					<?php endif; ?>
				</span>
			<?php elseif ( $event_time ) : ?>
				<span class="event-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"></circle>
						<polyline points="12 6 12 12 16 14"></polyline>
					</svg>
					<?php echo esc_html( $event_time ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $event_location ) : ?>
				<span class="event-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
					<?php echo esc_html( $event_location ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $event_counties && ! is_wp_error( $event_counties ) ) : ?>
				<span class="event-card-detail event-card-detail--county">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
					<?php echo esc_html( $event_counties[0]->name ); ?>
				</span>
			<?php endif; ?>
		</div>

		<p class="event-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>

		<?php if ( $event_reg ) : ?>
			<p class="event-card-registration"><?php echo esc_html( $event_reg ); ?></p>
		<?php endif; ?>

		<a href="<?php echo esc_url( $event_url ); ?>" class="event-card-cta">
			<?php echo esc_html( get_post_meta( $event_id, '_event_cta', true ) ? get_post_meta( $event_id, '_event_cta', true ) : __( 'Saiba mais', 'conexao-br-irlanda' ) ); ?>
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<line x1="5" y1="12" x2="19" y2="12"></line>
				<polyline points="12 5 19 12 12 19"></polyline>
			</svg>
		</a>
	</div>
</article>