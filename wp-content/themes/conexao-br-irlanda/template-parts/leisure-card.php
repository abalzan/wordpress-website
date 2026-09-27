<?php
/**
 * Lazer Card Template Part
 *
 * Renders a single leisure / tourism location card consistent with the
 * site's existing directory cards (event-card / provider-card). Each card
 * shows the location image (or an "Image pending" state when no properly
 * licensed image is available), category, place name, short description,
 * county, optional useful attributes and a primary CTA.
 *
 * @package Conexao_BR_Irlanda
 */

$leisure_id          = get_the_ID();
$leisure_categories  = get_the_terms( $leisure_id, 'conexao_category' );
$leisure_counties    = get_the_terms( $leisure_id, 'conexao_county' );
$leisure_town        = get_post_meta( $leisure_id, '_leisure_town', true );
$leisure_permalink   = get_permalink();

// Phase 3C — shared attribute-resolution helper (same as the single page).
// Returns only actual display names, never empty labels.
$leisure_attr_names = conexao_leisure_attributes( $leisure_id );

// Destination classification:
// 1. External record (official/Discover Ireland URL, no _leisure_internal_page
//    flag) → link directly to the external destination.
// 2. Internal record WITH the _leisure_internal_page flag (Phase 3B — explicitly
//    preserved useful internal page) → link to the internal /lazer/{slug}/ page.
// 3. Internal record WITHOUT the flag → no primary destination. The card shows
//    no primary CTA and the image/title are not linked, avoiding navigation to
//    a low-value intermediate page.
$leisure_external      = function_exists( 'conexao_leisure_external_url' ) ? conexao_leisure_external_url( $leisure_id ) : '';
$leisure_is_external   = (bool) $leisure_external;
$leisure_has_official  = (bool) get_post_meta( $leisure_id, '_leisure_official_website', true );
$leisure_internal_page = (bool) get_post_meta( $leisure_id, '_leisure_internal_page', true );

if ( $leisure_is_external ) {
	$leisure_link_url = $leisure_external;
} elseif ( $leisure_internal_page ) {
	$leisure_link_url = $leisure_permalink;
} else {
	$leisure_link_url = '';
}

// Phase 3D — resolved map URL for the secondary "Ver no mapa" action.
// Uses the existing canonical helper (functions.php): a stored _leisure_map_url
// wins, otherwise a deterministic Google Maps search URL is derived at render
// time from title/town/county. Empty when no sufficient location data exists.
$leisure_map_url = function_exists( 'conexao_leisure_map_url' ) ? conexao_leisure_map_url( $leisure_id ) : '';

// Image handling: the image is always a local WordPress Media Library
// attachment (featured thumbnail). When no image is available, the card
// renders a neutral "Image pending" state. Alt text is fetched from the
// dedicated meta field, falling back to the location title for accessibility.
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
}

// Determine if attribution should be shown.
$leisure_show_attribution = $leisure_has_image && ( $leisure_attribution || $leisure_author || $leisure_license );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'leisure-card' . ( $leisure_is_external ? ' leisure-card--external' : '' ) ); ?>>

<div class="leisure-card-image-wrapper">
<?php if ( $leisure_link_url ) : ?>
<a class="leisure-card-image" href="<?php echo esc_url( $leisure_link_url ); ?>"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?> tabindex="-1" aria-hidden="true">
<?php endif; ?>
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
<?php if ( $leisure_link_url ) : ?>
</a>
<?php endif; ?>

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

<h3 class="leisure-card-title"><?php if ( $leisure_link_url ) : ?><a href="<?php echo esc_url( $leisure_link_url ); ?>"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?>><?php the_title(); ?></a><?php else : ?><?php the_title(); ?><?php endif; ?></h3>

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

<?php
// STAGE 7 — language-aware description source. PT keeps the exact
// pre-existing pipeline (get_the_excerpt()); an EN request renders the
// authored English description when one exists and keeps the approved
// B2 fallback (PT excerpt) when it does not. The presentation pipeline
// below (18-word trim + esc_html) is IDENTICAL for both languages.
?>
<p class="leisure-card-excerpt"><?php echo esc_html( wp_trim_words( conexao_leisure_card_excerpt( $leisure_id ), 18, '...' ) ); ?></p>

<?php if ( ! empty( $leisure_attr_names ) ) : ?>
<?php
// Cards stay concise: show a capped, prioritized set of high-value
// practical attributes only (Phase 2 audit). Priority: Entrada
// (Gratuito/Pago/condicional), Ambiente (Interior/Exterior),
// Acessibilidade, Estacionamento; then the remaining profile
// attributes. Transport/bicycle details live on the individual page.
$leisure_card_attr_priority = array(
	'gratuito',
	'pago',
	'gratuito-em-determinadas-condicoes',
	'interior-exterior',
	'exterior',
	'interior',
	'acessivel',
	'estacionamento',
	'familias',
	'necessita-reserva',
	'pet-friendly',
);
$leisure_card_attrs = array();
foreach ( $leisure_card_attr_priority as $priority_slug ) {
	if ( isset( $leisure_attr_names[ $priority_slug ] ) ) {
		$leisure_card_attrs[ $priority_slug ] = $leisure_attr_names[ $priority_slug ];
	}
	if ( count( $leisure_card_attrs ) >= 4 ) {
		break;
	}
}
?>
<?php if ( ! empty( $leisure_card_attrs ) ) : ?>
<ul class="leisure-card-attrs">
<?php foreach ( $leisure_card_attrs as $attr_name ) : ?>
<li class="leisure-card-attr"><span class="leisure-attr-dot"></span><?php echo esc_html( $attr_name ); ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>

<div class="leisure-card-actions">
<?php
// Primary CTA — rendered only when a primary destination exists:
// 1. External + official website → "Ver site oficial" (external-link icon)
// 2. External without official (Discover Ireland only) → "Ver mais" (arrow icon)
// 3. Internal with _leisure_internal_page flag → "Ver mais" (arrow icon)
// 4. Internal without the flag → no primary CTA (low-value page, no navigation)
?>
<?php if ( $leisure_link_url ) : ?>
<a href="<?php echo esc_url( $leisure_link_url ); ?>" class="leisure-card-cta"<?php echo $leisure_is_external ? ' rel="noopener"' : ''; ?>>
<?php if ( $leisure_is_external && $leisure_has_official ) : ?>
<?php esc_html_e( 'Ver site oficial', 'conexao-br-irlanda' ); ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
<polyline points="15 3 21 3 21 9"></polyline>
<line x1="10" y1="14" x2="21" y2="3"></line>
</svg>
<?php else : ?>
<?php esc_html_e( 'Ver mais', 'conexao-br-irlanda' ); ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<line x1="5" y1="12" x2="19" y2="12"></line>
<polyline points="12 5 19 12 12 19"></polyline>
</svg>
<?php endif; ?>
</a>
<?php endif; ?>
<?php if ( $leisure_map_url ) : ?>
<a href="<?php echo esc_url( $leisure_map_url ); ?>" class="leisure-card-cta leisure-card-cta--map" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( sprintf( __( 'Ver localização de %s no mapa (abre em nova aba)', 'conexao-br-irlanda' ), get_the_title() ) ); ?>">
<?php esc_html_e( 'Ver no mapa', 'conexao-br-irlanda' ); ?>
<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
<circle cx="12" cy="10" r="3"></circle>
</svg>
</a>
<?php endif; ?>
</div>
</div>
</article>
