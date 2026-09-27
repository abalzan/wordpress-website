<?php
/**
 * Compact "Precisa de ajuda?" shortcut item.
 *
 * Part of the homepage utility section (front-page.php). The card data
 * (label / icon / destination) comes from the SAME Quick Access card
 * definitions rendered by the Acesso Rápido grid, so the two areas can never
 * become inconsistent — only the selection differs. Destination resolution
 * mirrors template-parts/quick-access-card.php using the same helper
 * functions: Guides archive (guides flag) → Guide category filter (term) →
 * explicit URL.
 *
 * @package Conexao_BR_Irlanda
 */

$card  = isset( $args['card'] ) ? $args['card'] : array();
$icons = isset( $args['icons'] ) ? $args['icons'] : array();
$icon  = isset( $card['icon'] ) && isset( $icons[ $card['icon'] ] ) ? $icons[ $card['icon'] ] : '';

if ( empty( $card['title'] ) ) {
	return;
}

if ( ! empty( $card['guides'] ) ) {
	$card_url = conexao_get_guides_archive_url();
} elseif ( ! empty( $card['term'] ) ) {
	$card_url = conexao_get_guide_category_url( $card['term'], $card['term'] );
} elseif ( ! empty( $card['url'] ) ) {
	$card_url = home_url( $card['url'] );
} else {
	return;
}
?>
<li class="help-shortcut-item">
	<a href="<?php echo esc_url( $card_url ); ?>" class="help-shortcut">
		<span class="help-shortcut-icon" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</svg>
		</span>
		<span class="help-shortcut-label"><?php echo esc_html( $card['title'] ); ?></span>
	</a>
</li>
