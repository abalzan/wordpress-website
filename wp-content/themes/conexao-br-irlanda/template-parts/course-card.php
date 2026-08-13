<?php
/**
 * Course Card Template Part
 *
 * Renders a single course card with a fully clickable banner, title,
 * date/time, location, category, short description and booking info.
 *
 * External course URLs open in a new browser tab (target="_blank").
 *
 * @package Conexao_BR_Irlanda
 */

$course_id         = get_the_ID();
$course_url        = get_post_meta( $course_id, '_course_url', true );
$course_url        = $course_url ? $course_url : get_permalink();
$course_target     = conexao_course_link_target_attrs( $course_id );
$course_banner     = get_post_meta( $course_id, '_course_banner', true );
$banner_attach_id  = get_post_meta( $course_id, '_course_banner_attachment_id', true );
$course_date       = get_post_meta( $course_id, '_course_date', true );
$course_time       = get_post_meta( $course_id, '_course_time', true );
$course_location   = get_post_meta( $course_id, '_course_location', true );
$course_delivery   = get_post_meta( $course_id, '_course_delivery_mode', true );
$course_price      = get_post_meta( $course_id, '_course_price', true );
$course_categories = get_the_terms( $course_id, 'conexao_category' );
$course_counties   = get_the_terms( $course_id, 'conexao_county' );

// Determine the best image source.
$has_attachment = $banner_attach_id && wp_attachment_is_image( $banner_attach_id );
if ( ! $has_attachment ) {
	$has_attachment = has_post_thumbnail( $course_id );
}

$day   = $course_date ? date( 'd', strtotime( $course_date ) ) : '--';
$month = $course_date ? date( 'M', strtotime( $course_date ) ) : '---';

// Determine location display: prefer delivery mode for online courses.
$location_display = $course_location;
if ( 'online' === strtolower( $course_delivery ) ) {
	$location_display = __( 'Online', 'conexao-br-irlanda' );
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'course-card' ); ?>>

	<a class="course-card-banner" href="<?php echo esc_url( $course_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Banner para %s', get_the_title() ) ); ?>"<?php echo $course_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
		<?php if ( $has_attachment ) : ?>
			<?php
			if ( $banner_attach_id && wp_attachment_is_image( $banner_attach_id ) ) {
				echo wp_get_attachment_image(
					$banner_attach_id,
					'conexao-event-banner',
					false,
					array(
						'class'   => 'course-card-banner-img',
						'loading' => 'lazy',
						'alt'     => esc_attr( get_the_title() ),
					)
				);
			} else {
				the_post_thumbnail( 'conexao-event-banner', array( 'class' => 'course-card-banner-img', 'loading' => 'lazy', 'alt' => esc_attr( get_the_title() ) ) );
			}
			?>
		<?php elseif ( $course_banner ) : ?>
			<img class="course-card-banner-img" src="<?php echo esc_url( $course_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
		<?php else : ?>
			<img class="course-card-banner-img" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
		<?php endif; ?>

		<?php if ( $course_date ) : ?>
			<div class="course-card-date-badge">
				<span class="course-card-date-day"><?php echo esc_html( $day ); ?></span>
				<span class="course-card-date-month"><?php echo esc_html( $month ); ?></span>
			</div>
		<?php endif; ?>
	</a>

	<div class="course-card-body">
		<?php if ( $course_categories && ! is_wp_error( $course_categories ) ) : ?>
			<span class="course-card-category">
				<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
					<line x1="7" y1="7" x2="7.01" y2="7"></line>
				</svg>
				<?php echo esc_html( $course_categories[0]->name ); ?>
			</span>
		<?php endif; ?>

		<h3 class="course-card-title"><a href="<?php echo esc_url( $course_url ); ?>"<?php echo $course_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>><?php the_title(); ?></a></h3>

		<div class="course-card-details">
			<?php if ( $course_date ) : ?>
				<span class="course-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
						<line x1="16" y1="2" x2="16" y2="6"></line>
						<line x1="8" y1="2" x2="8" y2="6"></line>
						<line x1="3" y1="10" x2="21" y2="10"></line>
					</svg>
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $course_date ) ) ); ?>
					<?php if ( $course_time ) : ?>
						<span class="course-card-time-sep">&middot;</span>
						<span class="course-card-time"><?php echo esc_html( $course_time ); ?></span>
					<?php endif; ?>
				</span>
			<?php elseif ( $course_time ) : ?>
				<span class="course-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"></circle>
						<polyline points="12 6 12 12 16 14"></polyline>
					</svg>
					<?php echo esc_html( $course_time ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $location_display ) : ?>
				<span class="course-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<?php if ( 'online' === strtolower( $course_delivery ) ) : ?>
							<rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
							<line x1="8" y1="21" x2="16" y2="21"></line>
							<line x1="12" y1="17" x2="12" y2="21"></line>
						<?php else : ?>
							<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
							<circle cx="12" cy="10" r="3"></circle>
						<?php endif; ?>
					</svg>
					<?php echo esc_html( $location_display ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $course_counties && ! is_wp_error( $course_counties ) ) : ?>
				<span class="course-card-detail course-card-detail--county">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
					<?php echo esc_html( $course_counties[0]->name ); ?>
				</span>
			<?php endif; ?>
		</div>

		<p class="course-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>

		<?php if ( $course_price ) : ?>
			<p class="course-card-price">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
					<line x1="12" y1="1" x2="12" y2="23"></line>
					<path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
				</svg>
				<?php echo esc_html( $course_price ); ?>
			</p>
		<?php endif; ?>

		<a href="<?php echo esc_url( $course_url ); ?>" class="course-card-cta"<?php echo $course_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
			<?php esc_html_e( 'Saiba mais', 'conexao-br-irlanda' ); ?>
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<line x1="5" y1="12" x2="19" y2="12"></line>
				<polyline points="12 5 19 12 12 19"></polyline>
			</svg>
		</a>
	</div>
</article>