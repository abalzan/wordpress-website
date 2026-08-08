<?php
/**
 * Reusable quick-access card.
 *
 * @package Conexao_BR_Irlanda
 */

$card = isset( $args['card'] ) ? $args['card'] : array();
$icons = isset( $args['icons'] ) ? $args['icons'] : array();
$icon  = isset( $card['icon'] ) && isset( $icons[ $card['icon'] ] ) ? $icons[ $card['icon'] ] : '';

if ( empty( $card['title'] ) || empty( $card['url'] ) ) {
	return;
}
?>
<a href="<?php echo esc_url( home_url( $card['url'] ) ); ?>" class="quick-access-card">
	<div class="quick-access-icon">
		<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</svg>
	</div>
	<span class="quick-access-label"><?php echo esc_html( $card['title'] ); ?></span>
	<?php if ( ! empty( $card['description'] ) ) : ?>
		<span class="quick-access-desc"><?php echo esc_html( $card['description'] ); ?></span>
	<?php endif; ?>
</a>
