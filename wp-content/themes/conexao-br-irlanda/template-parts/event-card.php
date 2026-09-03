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
$event_target     = conexao_event_link_target_attrs( $event_id );
$event_banner     = get_post_meta( $event_id, '_event_banner', true );
$banner_attach_id = get_post_meta( $event_id, '_event_banner_attachment_id', true );
// Recurring events show their NEXT occurrence, not the series start date.
$event_date       = conexao_event_display_date( $event_id );
$event_time       = get_post_meta( $event_id, '_event_time', true );
$event_location   = get_post_meta( $event_id, '_event_location', true );
$event_reg        = get_post_meta( $event_id, '_event_registration', true );
$event_counties   = get_the_terms( $event_id, 'conexao_county' );
$event_categories = get_the_terms( $event_id, 'conexao_category' );

// Determine the best image source: prefer WordPress attachment, then external URL, then placeholder.
$has_attachment = $banner_attach_id && wp_attachment_is_image( $banner_attach_id );
if ( ! $has_attachment ) {
	$has_attachment = has_post_thumbnail( $event_id );
}

$event_end_date = get_post_meta( $event_id, '_event_end_date', true );

/*
 * Localized date presentation for the Event archive.
 *
 * Site content and imported events are Portuguese, but the WP install itself
 * runs in English — so date_i18n( 'M' ) would print English month names. These
 * maps derive a consistent Portuguese abbreviation set from the REAL stored
 * dates (never hard-coded, never a replacement for the data), matching the
 * surrounding UI: JAN–DEZ and DOM–SÁB.
 */
$month_short_pt = array(
	1 => 'JAN', 2 => 'FEV', 3 => 'MAR', 4 => 'ABR', 5 => 'MAI', 6 => 'JUN',
	7 => 'JUL', 8 => 'AGO', 9 => 'SET', 10 => 'OUT', 11 => 'NOV', 12 => 'DEZ',
);
$month_full_pt = array(
	1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
	7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
);
$weekday_short_pt = array(
	0 => 'DOM', 1 => 'SEG', 2 => 'TER', 3 => 'QUA', 4 => 'QUI', 5 => 'SEX', 6 => 'SÁB',
);
$weekday_full_pt = array(
	0 => 'domingo', 1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira',
	4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado',
);

$date_ts    = $event_date ? strtotime( $event_date ) : 0;
$end_ts     = $event_end_date ? strtotime( $event_end_date ) : 0;
$month_num  = $event_date ? (int) date( 'n', $date_ts ) : 0;

$day     = $month = $weekday = '--';
$date_iso = '';
$sr_label = '';

/*
 * Recurrence presentation (Step 4): a concise label such as
 * "Toda quarta-feira" and an optional end-date range ("até 15 SET").
 * Computed here — before the date block — so the values enrich both the
 * screen-reader <time> sentence and the visual badge. All recurrence
 * logic lives in the runtime class; this is presentation only and never
 * surfaces raw weekday CSV, ISO numbers or internal meta keys.
 */
$recurrence_label    = conexao_event_recurrence_label( $event_id );
$recurrence_end_html = '';
if ( conexao_event_is_recurring( $event_id ) ) {
	$r_end = Conexao_Event_Recurrence::recurrence_end( (int) $event_id );
	if ( $r_end ) {
		$r_end_ts  = strtotime( $r_end );
		$r_end_day = (int) date( 'j', $r_end_ts );
		$r_end_mon = isset( $month_short_pt[ (int) date( 'n', $r_end_ts ) ] )
			? $month_short_pt[ (int) date( 'n', $r_end_ts ) ] : '';
		$recurrence_end_html = sprintf( 'até %d %s', $r_end_day, $r_end_mon );
	}
}

if ( $event_date ) {
	$start_day = (string) date( 'j', $date_ts );
	$day       = $start_day;
	$month     = isset( $month_short_pt[ $month_num ] ) ? $month_short_pt[ $month_num ] : '---';
	$weekday   = isset( $weekday_short_pt[ (int) date( 'w', $date_ts ) ] ) ? $weekday_short_pt[ (int) date( 'w', $date_ts ) ] : '---';
	$date_iso  = $event_date;

	// Full readable date sentence for assistive tech (the visual badge above
	// keeps the compact "12 SET SÁB" form; this <time> carries the rest).
	$month_full = isset( $month_full_pt[ $month_num ] ) ? $month_full_pt[ $month_num ] : '';
	$year_num   = date( 'Y', $date_ts );
	if ( $event_end_date && $end_ts > $date_ts ) {
		// Multi-day event: surface the stored _event_end_date as a range.
		if ( date( 'Y-m', $end_ts ) === date( 'Y-m', $date_ts ) ) {
			$day      = $start_day . '-' . date( 'j', $end_ts ); // "12-15" on the same line.
			$sr_label = sprintf( '%s a %s de %s de %s', $start_day, date( 'j', $end_ts ), $month_full, $year_num );
		} else {
			$end_month = isset( $month_full_pt[ (int) date( 'n', $end_ts ) ] ) ? $month_full_pt[ (int) date( 'n', $end_ts ) ] : '';
			$sr_label  = sprintf( 'de %s de %s a %s de %s de %s', $start_day, $month_full, date( 'j', $end_ts ), $end_month, date( 'Y', $end_ts ) );
		}
		$date_iso .= '/' . $event_end_date; // ISO 8601 range, machine-readable.
	} else {
		$sr_label = sprintf( '%s de %s de %s', $start_day, $month_full, $year_num );
	}
	$sr_label .= ', ' . $weekday_full_pt[ (int) date( 'w', $date_ts ) ];
	if ( $event_time ) {
		$sr_label .= ', às ' . $event_time;
	}
	if ( $recurrence_label ) {
		$sr_label .= ' — ' . $recurrence_label;
	}
}

// "Hoje"/"Amanhã" — only when the event genuinely falls today/tomorrow
// (site-local date). Always a subtle chip alongside the real date, never a
// replacement for it.
//
// For recurring events the shared recurrence evaluator decides whether the
// event occurs on the current local date — more robust than a raw string
// comparison against the cached "next occurrence" date, and keeps all
// recurrence logic in the runtime class (no logic duplicated in template).
// One-time events keep the existing exact date comparison so their output
// is byte-for-byte identical to production.
$is_today = false;
$hint     = '';
if ( $event_date ) {
	if ( conexao_event_is_recurring( $event_id ) ) {
		$now = current_datetime();
		if ( Conexao_Event_Recurrence::occurs_on_date( (int) $event_id, $now ) ) {
			$is_today = true;
			$hint     = __( 'Hoje', 'conexao-br-irlanda' );
		} else {
			$tomorrow = $now->modify( '+1 day' );
			if ( Conexao_Event_Recurrence::occurs_on_date( (int) $event_id, $tomorrow ) ) {
				$hint = __( 'Amanhã', 'conexao-br-irlanda' );
			}
		}
	} else {
		// One-time events: preserve existing raw date comparison.
		$today = current_time( 'Y-m-d' );
		if ( $event_date === $today ) {
			$is_today = true;
			$hint     = __( 'Hoje', 'conexao-br-irlanda' );
		} elseif ( $event_date === date( 'Y-m-d', strtotime( $today . ' +1 day' ) ) ) {
			$hint = __( 'Amanhã', 'conexao-br-irlanda' );
		}
	}
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'event-card' ); ?>>

	<a class="event-card-banner" href="<?php echo esc_url( $event_url ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Banner para %s', get_the_title() ) ); ?>"<?php echo $event_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
		<?php if ( $has_attachment ) : ?>
			<?php
			// Use WordPress image functions for responsive images and proper sizing.
			// This ensures the image is correctly scaled for the card dimensions.
			if ( $banner_attach_id && wp_attachment_is_image( $banner_attach_id ) ) {
				echo wp_get_attachment_image(
					$banner_attach_id,
					'conexao-event-banner',
					false,
					array(
						'class'   => 'event-card-banner-img',
						'loading' => 'lazy',
						'alt'     => esc_attr( get_the_title() ),
						// Real rendered banner widths (main.css): 1-column mobile
						// (~85vw) and a 3-column desktop grid cell (~320px inside
						// the site container). Capped at 320px so the DPR-2 need
						// stays ≤640 device px and the browser always picks the
						// 640×360 cropped conexao-event-banner derivative instead
						// of the uncropped (often portrait) original.
						'sizes'   => '(max-width: 768px) 85vw, 320px',
					)
				);
			} else {
				the_post_thumbnail( 'conexao-event-banner', array(
					'class'   => 'event-card-banner-img',
					'loading' => 'lazy',
					'alt'     => esc_attr( get_the_title() ),
					'sizes'   => '(max-width: 768px) 85vw, 320px',
				) );
			}
			?>
		<?php elseif ( $event_banner ) : ?>
			<img class="event-card-banner-img" src="<?php echo esc_url( $event_banner ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
		<?php else : ?>
			<img class="event-card-banner-img" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/events/event-placeholder.svg' ); ?>" alt="" loading="lazy">
		<?php endif; ?>

		<div class="event-card-date-badge<?php echo $is_today ? ' is-today' : ''; ?>" aria-hidden="true">
			<span class="event-card-date-day"><?php echo esc_html( $day ); ?></span>
			<span class="event-card-date-month"><?php echo esc_html( $month ); ?></span>
			<span class="event-card-date-weekday"><?php echo esc_html( $weekday ); ?></span>
			<?php if ( $recurrence_label ) : ?>
				<span class="event-card-recurrence"><?php echo esc_html( $recurrence_label ); ?></span>
			<?php endif; ?>
			<?php if ( $event_end_date && $end_ts > $date_ts && date( 'Y-m', $end_ts ) !== date( 'Y-m', $date_ts ) ) : ?>
				<span class="event-card-date-range"><?php echo esc_html( 'até ' . date( 'j', $end_ts ) . ' ' . ( isset( $month_short_pt[ (int) date( 'n', $end_ts ) ] ) ? $month_short_pt[ (int) date( 'n', $end_ts ) ] : '' ) ); ?></span>
			<?php endif; ?>
			<?php if ( $recurrence_end_html ) : ?>
				<span class="event-card-recurrence-end"><?php echo esc_html( $recurrence_end_html ); ?></span>
			<?php endif; ?>
			<?php if ( $hint ) : ?>
				<span class="event-card-date-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
	</a>

	<div class="event-card-body">
		<?php if ( $event_date && $sr_label ) : ?>
			<time class="event-card-date-iso screen-reader-text" datetime="<?php echo esc_attr( $date_iso ); ?>"><?php echo esc_html( $sr_label ); ?></time>
		<?php endif; ?>

		<?php if ( $event_categories && ! is_wp_error( $event_categories ) ) : ?>
			<span class="event-card-category">
				<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
					<line x1="7" y1="7" x2="7.01" y2="7"></line>
				</svg>
				<?php echo esc_html( $event_categories[0]->name ); ?>
			</span>
		<?php endif; ?>

		<h3 class="event-card-title"><a href="<?php echo esc_url( $event_url ); ?>"<?php echo $event_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>><?php the_title(); ?></a></h3>

		<div class="event-card-details">
			<?php if ( $event_time ) : ?>
				<span class="event-card-detail">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="12" cy="12" r="10"></circle>
						<polyline points="12 6 12 12 16 14"></polyline>
					</svg>
					<span class="event-card-time"><?php echo esc_html( $event_time ); ?></span>
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

		<?php if ( $event_price = get_post_meta( $event_id, '_event_price', true ) ) : ?>
			<p class="event-card-price">
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
					<circle cx="12" cy="10" r="3"></circle>
				</svg>
				<?php echo esc_html( $event_price ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $event_reg ) : ?>
			<p class="event-card-registration"><?php echo esc_html( $event_reg ); ?></p>
		<?php endif; ?>

		<a href="<?php echo esc_url( $event_url ); ?>" class="event-card-cta"<?php echo $event_target; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
			<?php echo esc_html( get_post_meta( $event_id, '_event_cta', true ) ? get_post_meta( $event_id, '_event_cta', true ) : __( 'Saiba mais', 'conexao-br-irlanda' ) ); ?>
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<line x1="5" y1="12" x2="19" y2="12"></line>
				<polyline points="12 5 19 12 12 19"></polyline>
			</svg>
		</a>
	</div>
</article>