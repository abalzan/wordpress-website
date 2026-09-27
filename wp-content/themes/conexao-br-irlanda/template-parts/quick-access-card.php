<?php
/**
 * Reusable quick-access card.
 *
 * @package Conexao_BR_Irlanda
 */

$card = isset( $args['card'] ) ? $args['card'] : array();
$icons = isset( $args['icons'] ) ? $args['icons'] : array();
$icon  = isset( $card['icon'] ) && isset( $icons[ $card['icon'] ] ) ? $icons[ $card['icon'] ] : '';

if ( empty( $card['title'] ) ) {
	return;
}

// Resolve the destination URL. Cards that carry a 'term' key resolve to the
// existing /guias/ archive filter (the same tax_query used by the filter bar),
// using the real conexao_category term slug — and, in an English request, the
// linked English term (see conexao_get_guide_category_url()). Cards with an
// explicit 'url' hold the canonical PORTUGUESE path and are resolved through
// conexao_lang_url(), so an English visitor reaches the real English
// destination whenever one exists (Stage 3.3) while the Portuguese output
// stays byte-identical. Cards flagged with 'guides' point to the canonical
// Guides archive.
if ( ! empty( $card['guides'] ) ) {
	$card_url = conexao_get_guides_archive_url();
} elseif ( ! empty( $card['term'] ) ) {
	$card_url = conexao_get_guide_category_url( $card['term'], $card['term'] );
} elseif ( ! empty( $card['url'] ) ) {
	$card_url = conexao_lang_url( $card['url'] );
} else {
	$card_url = '';
}

if ( '' === $card_url ) {
	return;
}

// Compose the card classes. Cards tagged with a mobile_priority slug are the
// ones promoted into the compact mobile 2x2 navigation; cards flagged
// mobile_only are kept out of the desktop grid and shown on mobile only.
// A priority-specific class (e.g. --priority-empregos) is also added so the
// mobile-only CSS can control the 2x2 ordering of the priority cards.
$card_classes = array( 'quick-access-card' );
if ( ! empty( $card['mobile_priority'] ) ) {
	$card_classes[] = 'quick-access-card--mobile-priority';
	$card_classes[] = 'quick-access-card--priority-' . sanitize_html_class( $card['mobile_priority'] );
}
if ( ! empty( $card['mobile_only'] ) ) {
	$card_classes[] = 'quick-access-card--mobile-only';
}
?>
<a href="<?php echo esc_url( $card_url ); ?>" class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
	<div class="quick-access-icon">
		<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</svg>
	</div>
	<span class="quick-access-label"><?php echo esc_html( $card['title'] ); ?></span>
	<?php if ( ! empty( $card['mobile_label'] ) ) : ?>
		<span class="quick-access-label quick-access-label--mobile"><?php echo esc_html( $card['mobile_label'] ); ?></span>
	<?php endif; ?>
	<?php if ( ! empty( $card['description'] ) ) : ?>
		<span class="quick-access-desc"><?php echo esc_html( $card['description'] ); ?></span>
	<?php endif; ?>
</a>
