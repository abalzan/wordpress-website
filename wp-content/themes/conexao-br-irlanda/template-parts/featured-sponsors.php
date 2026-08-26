<?php
/**
 * Template Part: Featured Apoiadores carousel
 *
 * Reusable presentation component over the EXISTING sponsor post type and
 * its editorial fields — no new content model, no duplicated data:
 *
 *   - "Apoiador em destaque" (_sponsor_featured = 1) decides WHAT appears.
 *   - "Ordem de exibição"   (_sponsor_display_order) decides WHERE it appears.
 *
 * Data comes exclusively from conexao_get_featured_sponsors() (functions.php),
 * which queries published sponsors, sorts deterministically and caches the
 * result under the "conexao_home_sponsors" transient (invalidated whenever a
 * sponsor is saved). Editing a supporter in wp-admin updates this component
 * automatically — no code change required.
 *
 * Renders NOTHING when no supporter is marked as featured, so callers can
 * include it unconditionally (e.g. inside the homepage Hero).
 *
 * Each slide's artwork is a responsive <picture> built in
 * conexao_sponsor_carousel_image() (functions.php) from the Apoiador's two
 * independent image relationships: the portrait "Imagem Mobile" is served at
 * ≤768px via <source media>, the landscape "Imagem Desktop" via the <img>.
 * The browser selects the correct asset naturally — no JavaScript source
 * swapping — and missing images fall back gracefully (mobile → desktop →
 * legacy logo → featured image), never rendering a broken image.
 *
 * Markup reuses the shared .sponsors-carousel scroll-snap engine (CSS in
 * assets/css/main.css, behavior in assets/js/main.js — prev/next buttons,
 * pagination dots, keyboard support, swipe, polite live region, adaptive
 * static mode, plus a 2-second autoplay unique to this Hero variant:
 * one setTimeout chain per carousel, paused on hover/focus/touch/hidden tab,
 * restarted with a full fresh interval after manual navigation or swipe, and
 * disabled entirely under prefers-reduced-motion).
 *
 * The .sponsors-carousel--hero modifier restyles the component for the Hero
 * context: EXACTLY ONE supporter visible at a time as a single large featured
 * image card. The sponsor artwork fills the whole .sponsor-tile surface; the
 * sponsor name is overlaid at the top of the image over a soft scrim, small
 * translucent prev/next arrows are vertically centered INSIDE the image, and
 * pagination dots sit at the bottom center of the tile. All controls live in
 * .sponsors-carousel-stage (positioned over the tile) and keep the SAME data
 * attributes and accessible labels as before — only the presentation became
 * integrated; the underlying navigation logic is unchanged.
 *
 * @package Conexao_BR_Irlanda
 */

$featured_sponsors = conexao_get_featured_sponsors();

// No supporter flagged "Apoiador em destaque" → render nothing at all.
if ( empty( $featured_sponsors ) ) {
	return;
}

$sponsor_total = count( $featured_sponsors );
?>

<div class="sponsors-carousel sponsors-carousel--hero" data-sponsors-carousel>
	<p class="sponsors-hero-label"><?php esc_html_e( 'Apoiadores em destaque', 'conexao-br-irlanda' ); ?></p>

	<!-- Polite live region announcing the current position while scrolling. -->
	<p class="screen-reader-text" data-sponsors-status aria-live="polite"></p>

	<!-- Stage: positions the integrated controls (arrows, dots) OVER the
	     visible tile instead of in a separate row beneath it. -->
	<div class="sponsors-carousel-stage">
		<div class="sponsors-carousel-viewport"
			tabindex="0"
			role="group"
			aria-roledescription="carousel"
			aria-label="<?php esc_attr_e( 'Apoiadores em destaque', 'conexao-br-irlanda' ); ?>">
			<ul class="sponsors-carousel-list">
				<?php foreach ( $featured_sponsors as $sponsor_index => $featured_sponsor ) :
					$sponsor_title = $featured_sponsor['title'];
					// External link takes priority, then the sponsor permalink —
					// preserving the established target/rel behavior.
					$sponsor_href  = $featured_sponsor['url'] ? $featured_sponsor['url'] : $featured_sponsor['permalink'];
					$sponsor_target = $featured_sponsor['url'] ? ' target="_blank"' : '';
					$sponsor_rel    = $featured_sponsor['url'] ? ' rel="noopener noreferrer"' : '';
					?>
					<li class="sponsors-slide"
						role="group"
						aria-roledescription="slide"
						aria-label="<?php echo esc_attr( sprintf( __( 'Apoiador %1$d de %2$d', 'conexao-br-irlanda' ), $sponsor_index + 1, $sponsor_total ) ); ?>">
						<a href="<?php echo esc_url( $sponsor_href ); ?>"
							class="sponsor-tile"
							aria-label="<?php echo esc_attr( $sponsor_title ); ?>"<?php echo $sponsor_target . $sponsor_rel; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
						<!-- The logo area IS the tile: CSS stretches it over the
						     full card surface (object-fit: contain keeps every
						     asset's natural proportions — sponsor artwork is
						     never cropped or recolored). The <picture> serves
						     the portrait Imagem Mobile at ≤768px and the
						     landscape Imagem Desktop above; with no usable
						     image at all the fallback icon scales with the
						     tile via CSS. -->
						<span class="sponsor-tile-logo">
							<?php if ( ! empty( $featured_sponsor['image'] ) ) : ?>
								<?php echo $featured_sponsor['image']; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by conexao_sponsor_carousel_image(), which escapes its own output. */ ?>
							<?php else : ?>
								<svg viewBox="0 0 24 24" fill="none" stroke="#adb5bd" stroke-width="1.5" aria-hidden="true">
									<rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
									<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
								</svg>
							<?php endif; ?>
						</span>
							<!-- Name overlays the top of the image (scrim gradient
							     in CSS) instead of occupying its own block below. -->
							<span class="sponsor-tile-name"><?php echo esc_html( $sponsor_title ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<!-- Prev/next arrows overlay the image, vertically centered. Same
		     data attributes and accessible labels as the previous external
		     row — the shared carousel JS binds them exactly as before. -->
		<button type="button" class="sponsors-carousel-arrow sponsors-carousel-arrow--prev" data-sponsors-prev aria-label="<?php esc_attr_e( 'Apoiador anterior', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<polyline points="15 18 9 12 15 6"></polyline>
			</svg>
		</button>
		<button type="button" class="sponsors-carousel-arrow sponsors-carousel-arrow--next" data-sponsors-next aria-label="<?php esc_attr_e( 'Próximo apoiador', 'conexao-br-irlanda' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<polyline points="9 18 15 12 9 6"></polyline>
			</svg>
		</button>

		<?php if ( $sponsor_total > 1 ) : ?>
			<!-- Pagination dots: one per slide, bottom center of the tile.
			     The shared carousel JS wires the clicks (jump + autoplay
			     timer reset) and keeps .is-active / aria-current in sync
			     while scrolling. -->
			<div class="sponsors-carousel-dots" role="group" aria-label="<?php esc_attr_e( 'Navegar entre apoiadores', 'conexao-br-irlanda' ); ?>">
				<?php foreach ( $featured_sponsors as $dot_index => $featured_sponsor ) : ?>
					<button type="button"
						class="sponsors-carousel-dot<?php echo 0 === $dot_index ? ' is-active' : ''; ?>"
						data-sponsors-dot="<?php echo esc_attr( $dot_index ); ?>"
						aria-label="<?php echo esc_attr( sprintf( __( 'Ir para Apoiador %1$d', 'conexao-br-irlanda' ), $dot_index + 1 ) ); ?>"<?php echo 0 === $dot_index ? ' aria-current="true"' : ''; ?>></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</div>