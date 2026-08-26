<?php
/**
 * Single Apoiador (sponsor) template.
 *
 * Renders the detail page for an /apoiadores/ entry — the rich information
 * destination behind every sponsor card and homepage carousel slide:
 *
 *   Who the Apoiador is  → name, category, county, main image
 *   What they do         → the existing WordPress content (description)
 *   How to contact them  → "Entre em contato" with one button per configured
 *                          channel (Website / Instagram / Facebook / WhatsApp /
 *                          LinkedIn / TikTok / E-mail / Outro), rendered ONLY
 *                          for channels that actually exist.
 *
 * Contact data comes exclusively from the existing data model — no new meta,
 * no duplicated storage:
 *
 *   - `_sponsor_contacts` structured repeater rows (admin-curated order),
 *     read through conexao_sponsor_contact_rows() which ALSO folds in the
 *     legacy `_sponsor_link` official website first, so records that only
 *     have that single field keep producing a complete detail page.
 *   - The main image reuses conexao_sponsor_image_ids() (Imagem Desktop →
 *     legacy _sponsor_logo → featured image; Imagem Mobile fallbacks).
 *
 * The homepage Hero carousel stays visually clean: it links here instead of
 * carrying contact icons. External links open safely in a new tab
 * (target="_blank" rel="noopener noreferrer"); WhatsApp wa.me deep links
 * work immediately on mobile.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();
?>

<?php while ( have_posts() ) : the_post(); ?>

	<?php
	$sponsor_id = get_the_ID();

	// Ordered contact rows (official website + repeater rows, deduplicated).
	$sponsor_contact_rows = conexao_sponsor_contact_rows( $sponsor_id );

	// Taxonomy context.
	$sponsor_categories = get_the_terms( $sponsor_id, 'conexao_category' );
	$sponsor_counties   = get_the_terms( $sponsor_id, 'conexao_county' );

	// Main image attachment IDs with legacy fallbacks (0 = none at all).
	list( $sponsor_desktop_id, $sponsor_mobile_id ) = conexao_sponsor_image_ids( $sponsor_id );

	$sponsor_archive_url = get_post_type_archive_link( 'sponsor' );
	if ( ! $sponsor_archive_url ) {
		$sponsor_archive_url = home_url( '/apoiadores/' );
	}
	?>

	<div class="site-container sponsor-single">
		<main id="primary" class="content-area">

			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

				<header class="sponsor-single-header">
					<a href="<?php echo esc_url( $sponsor_archive_url ); ?>" class="sponsor-single-back">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
						<span><?php esc_html_e( 'Todos os apoiadores', 'conexao-br-irlanda' ); ?></span>
					</a>

					<?php if ( $sponsor_categories && ! is_wp_error( $sponsor_categories ) ) : ?>
						<div class="post-categories">
							<?php foreach ( $sponsor_categories as $category ) : ?>
								<span class="hero-category"><?php echo esc_html( $category->name ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h1 class="entry-title"><?php the_title(); ?></h1>

					<?php if ( $sponsor_counties && ! is_wp_error( $sponsor_counties ) ) : ?>
						<div class="sponsor-single-location">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
								<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
								<circle cx="12" cy="10" r="3"></circle>
							</svg>
							<span><?php echo esc_html( wp_list_pluck( $sponsor_counties, 'name' )[0] ); ?></span>
						</div>
					<?php endif; ?>
				</header>

				<?php
				// Main image: responsive <picture> over the SAME two-image model
				// as the homepage carousel — portrait Imagem Mobile at ≤768px via
				// <source media>, landscape Imagem Desktop via the <img>. When both
				// roles resolve to the same asset only the <img> is emitted. This
				// image is the page's LCP element: eager + fetchpriority high.
				$sponsor_hero_html = '';
				if ( $sponsor_desktop_id ) {
					$sponsor_desktop_src = wp_get_attachment_image_url( $sponsor_desktop_id, 'large' );

					if ( $sponsor_desktop_src ) {
						$sponsor_alt = trim( (string) get_post_meta( $sponsor_desktop_id, '_wp_attachment_image_alt', true ) );
						if ( '' === $sponsor_alt ) {
							$sponsor_alt = get_the_title();
						}

						$sponsor_hero_html = '<picture class="sponsor-single-picture">';

						if ( $sponsor_mobile_id && $sponsor_mobile_id !== $sponsor_desktop_id ) {
							$sponsor_mobile_src = wp_get_attachment_image_url( $sponsor_mobile_id, 'large' );

							if ( $sponsor_mobile_src && $sponsor_mobile_src !== $sponsor_desktop_src ) {
								$sponsor_mobile_dimensions = wp_get_attachment_image_src( $sponsor_mobile_id, 'large' );

								$sponsor_hero_html .= '<source media="(max-width: 768px)" srcset="' . esc_attr( $sponsor_mobile_src ) . '"';
								if ( $sponsor_mobile_dimensions ) {
									$sponsor_hero_html .= ' width="' . esc_attr( (int) $sponsor_mobile_dimensions[1] ) . '"'
										. ' height="' . esc_attr( (int) $sponsor_mobile_dimensions[2] ) . '"';
								}
								$sponsor_hero_html .= ' />';
							}
						}

						$sponsor_desktop_dimensions = wp_get_attachment_image_src( $sponsor_desktop_id, 'large' );
						$sponsor_desktop_srcset     = wp_get_attachment_image_srcset( $sponsor_desktop_id, 'large' );

						$sponsor_hero_html .= '<img src="' . esc_url( $sponsor_desktop_src ) . '"';
						if ( $sponsor_desktop_srcset ) {
							$sponsor_hero_html .= ' srcset="' . esc_attr( $sponsor_desktop_srcset ) . '"';
						}
						$sponsor_hero_html .= ' sizes="(min-width: 769px) 720px, 92vw"';
						if ( $sponsor_desktop_dimensions ) {
							$sponsor_hero_html .= ' width="' . esc_attr( (int) $sponsor_desktop_dimensions[1] ) . '"'
								. ' height="' . esc_attr( (int) $sponsor_desktop_dimensions[2] ) . '"';
						}
						$sponsor_hero_html .= ' alt="' . esc_attr( $sponsor_alt ) . '" loading="eager" fetchpriority="high" decoding="async" />';

						$sponsor_hero_html .= '</picture>';
					}
				}

				if ( $sponsor_hero_html ) : ?>
					<figure class="sponsor-single-hero">
						<?php echo $sponsor_hero_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts. ?>
					</figure>
				<?php endif; ?>

				<div class="sponsor-single-body">

					<?php if ( trim( get_the_content() ) ) : ?>
						<div class="sponsor-single-content entry-content">
							<?php the_content(); ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $sponsor_contact_rows ) ) : ?>
						<aside class="sponsor-single-contact" aria-labelledby="sponsor-contact-title">
							<h2 class="sponsor-single-contact-title" id="sponsor-contact-title"><?php esc_html_e( 'Entre em contato', 'conexao-br-irlanda' ); ?></h2>
							<p class="sponsor-single-contact-intro"><?php esc_html_e( 'Escolha um canal para falar diretamente com o Apoiador:', 'conexao-br-irlanda' ); ?></p>

							<ul class="sponsor-contact-list">
								<?php foreach ( $sponsor_contact_rows as $contact_row ) :
									$contact_external = ! empty( $contact_row['external'] );
									$contact_attrs    = $contact_external ? ' target="_blank" rel="noopener noreferrer"' : '';
									?>
									<li class="sponsor-contact-item">
										<a href="<?php echo esc_url( $contact_row['url'] ); ?>"
											class="sponsor-contact-btn sponsor-contact-btn--<?php echo esc_attr( sanitize_html_class( $contact_row['type'] ) ); ?>"<?php echo $contact_attrs; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute pair built above. */ ?>>
											<span class="sponsor-contact-icon"><?php echo conexao_contact_icon( $contact_row['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG built by conexao_contact_icon(). ?></span>
											<span class="sponsor-contact-label"><?php echo esc_html( $contact_row['label'] ); ?></span>
											<?php if ( $contact_external ) : ?>
												<span class="screen-reader-text"><?php esc_html_e( '(abre em nova aba)', 'conexao-br-irlanda' ); ?></span>
											<?php endif; ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</aside>
					<?php endif; ?>

				</div>

			</article>

		</main>
	</div>

<?php endwhile; ?>

<?php get_footer();