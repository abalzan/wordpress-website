<?php
/**
 * Event Preview Template Part (Homepage compact preview)
 *
 * Renders a compact, scannable event preview for the homepage "Próximos
 * Eventos" section. This is intentionally lighter than the full event card
 * used on the /eventos/ archive: it shows only the image, title, date, time
 * and city/location — no full venue address, no long description, no price
 * or registration details.
 *
 * It reads the SAME underlying event data (no separate copy) and reuses the
 * existing event link logic, so clicking a preview opens the exact same
 * destination as the archive card.
 *
 * Relies on the WordPress loop; expected custom fields:
 *   - _event_date            (Y-m-d)
 *   - _event_time
 *   - _event_url             (destination URL; defaults to permalink)
 *   - _event_banner          (banner image URL; falls back to featured image)
 *   - _event_banner_attachment_id
 * Location shown here is derived from the conexao_town / conexao_county
 * taxonomies (never the full _event_address).
 *
 * @package Conexao_BR_Irlanda
 */

$event_id         = get_the_ID();
$event_url        = get_post_meta( $event_id, '_event_url', true );
$event_url        = $event_url ? $event_url : get_permalink();
$event_target     = conexao_event_link_target_attrs( $event_id );
$event_banner     = get_post_meta( $event_id, '_event_banner', true );
$banner_attach_id = get_post_meta( $event_id, '_event_banner_attachment_id', true );
$event_date       = get_post_meta( $event_id, '_event_date', true );
$event_time       = get_post_meta( $event_id, '_event_time', true );

// Determine the best image source: prefer WordPress attachment, then the
// featured image, then the external banner URL, then the placeholder.
$has_attachment = $banner_attach_id && wp_attachment_is_image( $banner_attach_id );
if ( ! $has_attachment ) {
	$has_attachment = has_post_thumbnail( $event_id );
}

// Compact "where": show the town/city via the conexao_town taxonomy, falling
// back to the county. The full street address (_event_address) is intentionally
// NOT shown on the homepage preview.
$preview_location = '';
$event_towns    = get_the_terms( $event_id, 'conexao_town' );
$event_counties = get_the_terms( $event_id, 'conexao_county' );
if ( $event_towns && ! is_wp_error( $event_towns ) && ! empty( $event_towns ) ) {
	$preview_location = $event_towns[0]->name;
} elseif ( $event_counties && ! is_wp_error( $event_counties ) && ! empty( $event_counties ) ) {
	$preview_location = $event_counties[0]->name;
}
?>

<article id="event-preview-<?php the_ID(); ?>" <?php post_class( 'event-preview' ); ?>>

	<a class="event-preview-link" href="<?php echo esc_url( $event_url ); ?>"<?php echo $event_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>

		<div class="event-preview-media">
			<?php if ( $has_attachment ) : ?>
				<?php
				if ( $banner_attach_id && wp_attachment_is_image( $banner_attach_id ) ) {
					echo wp_get_attachment_image(
						$banner_attach_id,
						'conexao-event-banner',
						false,
						array(
							'class'   => 'event-preview-img',
							'loading' => 'lazy',
							'alt'     => esc_attr( get_the_title() ),
						)
					);
				} else {
					the_post_thumbnail( 'conexao-event-banner', array( 'class' => 'event-preview-img', 'loading' => 'lazy', 'alt' => esc_attr( get_the_title() ) ) );
				}
				?>
			<?php elseif ( $event_banner ) : ?>
				<img class="event-preview-img" src="<?php echo esc_url( $event_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
			<?php else : ?>
				<img class="event-preview-img" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
			<?php endif; ?>
		</div>

		<div class="event-preview-body">
			<h3 class="event-preview-title"><?php the_title(); ?></h3>

			<?php if ( $event_date ) : ?>
				<p class="event-preview-meta">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
						<line x1="16" y1="2" x2="16" y2="6"></line>
						<line x1="8" y1="2" x2="8" y2="6"></line>
						<line x1="3" y1="10" x2="21" y2="10"></line>
					</svg>
					<?php echo esc_html( date_i18n( 'd M Y', strtotime( $event_date ) ) ); ?>
					<?php if ( $event_time ) : ?>
						<span class="event-preview-meta-sep">&middot;</span>
						<span class="event-preview-time"><?php echo esc_html( $event_time ); ?></span>
					<?php endif; ?>
				</p>
			<?php elseif ( $event_time ) : ?>
				<p class="event-preview-meta">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="12" cy="12" r="10"></circle>
						<polyline points="12 6 12 12 16 14"></polyline>
					</svg>
					<?php echo esc_html( $event_time ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $preview_location ) : ?>
				<p class="event-preview-location">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
					<?php echo esc_html( $preview_location ); ?>
				</p>
			<?php endif; ?>
		</div>

	</a>

</article>