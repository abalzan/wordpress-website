<?php
/**
 * Course Provider Card Template Part
 *
 * Renders a single course provider / learning platform card for the Cursos
 * directory. Each card links directly to the provider's external website in a
 * new browser tab (target="_blank" rel="noopener noreferrer").
 *
 * @package Conexao_BR_Irlanda
 */

/*
 * STAGE 9 — language-aware category presentation. The stored
 * `_provider_category` meta is free-text Portuguese and is NEVER rewritten; only
 * the DISPLAY value is localized, so a PT request renders the original
 * Portuguese label exactly as before and an EN request renders the English
 * presentation label. An unknown value falls through unchanged, and the
 * esc_html() in the markup still escapes whatever comes back.
 */
$provider_id             = get_the_ID();
$provider_url            = get_post_meta( $provider_id, '_provider_url', true );
$provider_category       = get_post_meta( $provider_id, '_provider_category', true );
$provider_category_label = conexao_provider_category_label( $provider_category );
$provider_location       = get_post_meta( $provider_id, '_provider_location', true );
$provider_logo           = get_post_meta( $provider_id, '_provider_logo', true );
$logo_id                 = absint( $provider_logo );

if ( ! $logo_id && has_post_thumbnail( $provider_id ) ) {
	$logo_id = get_post_thumbnail_id( $provider_id );
}

$provider_url = $provider_url ? $provider_url : get_permalink();
$target_attrs = ' target="_blank" rel="noopener noreferrer"';
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'provider-card' ); ?>>
	<a class="provider-card-link" href="<?php echo esc_url( $provider_url ); ?>"<?php echo $target_attrs; ?>>
		<div class="provider-card-logo">
			<?php if ( $logo_id && wp_attachment_is_image( $logo_id ) ) : ?>
				<?php echo wp_get_attachment_image( $logo_id, 'conexao-provider-logo', false, array( 'class' => 'provider-card-logo-img', 'loading' => 'lazy', 'alt' => esc_attr( sprintf( 'Logo de %s', get_the_title() ) ) ) ); ?>
			<?php else : ?>
				<img class="provider-card-logo-img provider-card-logo-img--fallback" src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/courses/provider-placeholder.svg' ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
			<?php endif; ?>
		</div>
		<div class="provider-card-body">
			<?php if ( $provider_category_label ) : ?>
				<span class="provider-card-category"><?php echo esc_html( $provider_category_label ); ?></span>
			<?php endif; ?>
			<h3 class="provider-card-title"><?php the_title(); ?></h3>
			<?php
			// STAGE 8 — language-aware description source. PT keeps the exact
			// pre-existing pipeline (get_the_excerpt()); an EN request renders the
			// authored English description when one exists and keeps the approved
			// B2 fallback (the PT excerpt) when it does not. The presentation
			// pipeline below (20-word trim + esc_html) is IDENTICAL for both
			// languages.
			?>
			<p class="provider-card-excerpt"><?php echo esc_html( wp_trim_words( conexao_provider_card_excerpt( $provider_id ), 20, '...' ) ); ?></p>
			<?php if ( $provider_location ) : ?>
				<div class="provider-card-meta">
					<span class="provider-card-detail">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
						<?php echo esc_html( $provider_location ); ?>
					</span>
				</div>
			<?php endif; ?>
			<span class="provider-card-cta">
				<?php esc_html_e( 'Ver cursos', 'conexao-br-irlanda' ); ?>
				<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
			</span>
		</div>
	</a>
</article>