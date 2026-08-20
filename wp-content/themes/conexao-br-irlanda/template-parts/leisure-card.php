<?php
/**
 * Lazer Card Template Part
 *
 * Renders a single leisure / tourism location card consistent with the
 * site's existing directory cards (event-card / provider-card). Each card
 * shows the location image (or an "Image pending" state when no properly
 * licensed image is available), category, place name, short description,
 * county, optional useful attributes and a "Ver local" CTA.
 *
 * @package Conexao_BR_Irlanda
 */

$leisure_id          = get_the_ID();
$leisure_categories  = get_the_terms( $leisure_id, 'conexao_category' );
$leisure_counties    = get_the_terms( $leisure_id, 'conexao_county' );
$leisure_town        = get_post_meta( $leisure_id, '_leisure_town', true );
$leisure_free        = get_post_meta( $leisure_id, '_leisure_free', true );
$leisure_family      = get_post_meta( $leisure_id, '_leisure_family', true );
$leisure_outdoor     = get_post_meta( $leisure_id, '_leisure_outdoor', true );
$leisure_booking     = get_post_meta( $leisure_id, '_leisure_booking', true );
$leisure_permalink   = get_permalink();

// Destination: when an external official website (or Discover Ireland page)
// is configured, the card links directly there. Otherwise it uses the normal
// internal /lazer/{slug}/ page.
$leisure_external      = function_exists( 'conexao_leisure_external_url' ) ? conexao_leisure_external_url( $leisure_id ) : '';
$leisure_link_url      = $leisure_external ? $leisure_external : $leisure_permalink;
$leisure_is_external   = (bool) $leisure_external;
$leisure_has_official  = (bool) get_post_meta( $leisure_id, '_leisure_official_website', true );

// Image handling: prefer a properly-licensed local Media Library image
// (featured thumbnail). Falls back to an explicitly-licensed external URL.
// When neither exists, the card renders a neutral "Image pending" state.
// Alt text is fetched from the dedicated meta field, falling back to the
// location title for accessibility.
$leisure_image_status = get_post_meta( $leisure_id, '_leisure_image_status', true );
$leisure_alt_text     = get_post_meta( $leisure_id, '_leisure_image_alt_text', true );
$leisure_alt          = $leisure_alt_text ? $leisure_alt_text : get_the_title();
$leisure_src_url      = get_post_meta( $leisure_id, '_leisure_image_source_url', true );
$leisure_source_label = get_post_meta( $leisure_id, '_leisure_image_source', true );
$leisure_author       = get_post_meta( $leisure_id, '_leisure_image_author', true );
$leisure_license      = get_post_meta( $leisure_id, '_leisure_image_license', true );
$leisure_attribution  = get_post_meta( $leisure_id, '_leisure_image_attribution', true );
$leisure_has_image    = false;
$leisure_img_html     = '';

if ( has_post_thumbnail() ) {
$leisure_has_image = true;
$leisure_img_html  = get_the_post_thumbnail(
$leisure_id,
'conexao-card',
array(
'loading' => 'lazy',
'alt'     => esc_attr( $leisure_alt ),
)
);
} elseif ( 'external' === $leisure_image_status ) {
$external_img = get_post_meta( $leisure_id, '_leisure_image_external_url', true );
if ( $external_img ) {
$leisure_has_image = true;
$leisure_img_html  = sprintf(
'<img src="%s" alt="%s" loading="lazy" width="400" height="300">',
esc_url( $external_img ),
esc_attr( $leisure_alt )
);
}
}

// Determine if attribution should be shown.
$leisure_show_attribution = $leisure_has_image && ( $leisure_attribution || $leisure_author || $leisure_license );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'leisure-card' . ( $leisure_is_external ? ' leisure-card--external' : '' ) ); ?>>

<div class="leisure-card-image-wrapper">
<a class="leisure-card-image" href="<?php echo esc_url( $leisure_link_url ); ?>"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?> tabindex="-1" aria-hidden="true">
<?php if ( $leisure_has_image ) : ?>
<?php echo $leisure_img_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php else : ?>
<span class="leisure-card-image-pending">
<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<rect x="3" y="3" width="18" height="18" rx="2"></rect>
<circle cx="8.5" cy="8.5" r="1.5"></circle>
<polyline points="21 15 16 10 5 21"></polyline>
</svg>
<span class="leisure-card-image-pending-text"><?php esc_html_e( 'Imagem pendente', 'conexao-br-irlanda' ); ?></span>
</span>
<?php endif; ?>
</a>

<?php if ( $leisure_show_attribution ) : ?>
<div class="leisure-card-attribution">
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
<a href="<?php echo esc_url( $leisure_src_url ); ?>" class="leisure-card-attribution-link" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Ver fonte da imagem', 'conexao-br-irlanda' ); ?>">
↗
</a>
<?php endif; ?>
</div>
<?php endif; ?>
</div>

<div class="leisure-card-body">
<?php if ( $leisure_categories && ! is_wp_error( $leisure_categories ) ) : ?>
<span class="leisure-card-category"><?php echo esc_html( $leisure_categories[0]->name ); ?></span>
<?php endif; ?>

<h3 class="leisure-card-title"><a href="<?php echo esc_url( $leisure_link_url ); ?>"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?>><?php the_title(); ?></a></h3>

<div class="leisure-card-details">
<?php if ( $leisure_counties && ! is_wp_error( $leisure_counties ) ) : ?>
<span class="leisure-card-detail">
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
<circle cx="12" cy="10" r="3"></circle>
</svg>
<?php echo esc_html( $leisure_counties[0]->name ); ?>
<?php if ( $leisure_town ) : ?>
<span class="leisure-card-time-sep">&middot;</span>
<span class="leisure-card-town"><?php echo esc_html( $leisure_town ); ?></span>
<?php endif; ?>
</span>
<?php elseif ( $leisure_town ) : ?>
<span class="leisure-card-detail">
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
<circle cx="12" cy="10" r="3"></circle>
</svg>
<?php echo esc_html( $leisure_town ); ?>
</span>
<?php endif; ?>
</div>

<p class="leisure-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>

<?php if ( $leisure_free || $leisure_family || $leisure_outdoor || $leisure_booking ) : ?>
<ul class="leisure-card-attrs">
<?php if ( $leisure_free ) : ?>
<li class="leisure-card-attr"><span class="leisure-attr-dot"></span><?php echo esc_html( $leisure_free ); ?></li>
<?php endif; ?>
<?php if ( $leisure_family ) : ?>
<li class="leisure-card-attr"><span class="leisure-attr-dot"></span><?php esc_html_e( 'Famílias', 'conexao-br-irlanda' ); ?></li>
<?php endif; ?>
<?php if ( $leisure_outdoor ) : ?>
<li class="leisure-card-attr"><span class="leisure-attr-dot"></span><?php esc_html_e( 'Exterior', 'conexao-br-irlanda' ); ?></li>
<?php endif; ?>
<?php if ( $leisure_booking ) : ?>
<li class="leisure-card-attr"><span class="leisure-attr-dot"></span><?php esc_html_e( 'Reserva', 'conexao-br-irlanda' ); ?></li>
<?php endif; ?>
</ul>
<?php endif; ?>

<a href="<?php echo esc_url( $leisure_link_url ); ?>" class="leisure-card-cta"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?>>
<?php if ( $leisure_is_external ) : ?>
<?php esc_html_e( $leisure_has_official ? 'Ver site oficial' : 'Ver mais', 'conexao-br-irlanda' ); ?>
<?php if ( $leisure_has_official ) : ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
<polyline points="15 3 21 3 21 9"></polyline>
<line x1="10" y1="14" x2="21" y2="3"></line>
</svg>
<?php else : ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<line x1="5" y1="12" x2="19" y2="12"></line>
<polyline points="12 5 19 12 12 19"></polyline>
</svg>
<?php endif; ?>
<?php else : ?>
<?php esc_html_e( 'Ver local', 'conexao-br-irlanda' ); ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<line x1="5" y1="12" x2="19" y2="12"></line>
<polyline points="12 5 19 12 12 19"></polyline>
</svg>
<?php endif; ?>
</a>
</div>
</article>
