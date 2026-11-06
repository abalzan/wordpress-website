<?php
/**
 * Shared homepage-hero placement shell.
 *
 * A reusable component (r10 §7.3): the shell owns the placement wrapper, the
 * package design tokens and the layout; the package supplies its own content
 * template. The shell contains no event identity, so no template ever tests for
 * a holiday again.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || empty( $ce['placement'] ) || empty( $ce['package']['slug'] ) ) {
	return;
}

$ce_attributes = conexao_commemorative_wrapper_attributes( $ce );
$ce_content    = isset( $ce['placement']['content'] ) ? (string) $ce['placement']['content'] : '';
?>
<div<?php echo $ce_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by conexao_commemorative_wrapper_attributes(). ?>>
	<?php
	/*
	 * No-JS contract (r10 §7.8): a hero that the package reveals from script must
	 * still be readable when JavaScript is unavailable, and a control that cannot
	 * work without script must not be offered. The shell owns this rule once, so
	 * every package inherits it.
	 */
	?>
	<noscript>
		<style>
			.ce-placement--homepage_hero [data-ce-reveal] { display: grid !important; }
			.ce-placement--homepage_hero [data-ce-dismiss] { display: none !important; }
		</style>
	</noscript>
	<div class="ce-hero-shell">
		<?php
		if ( '' !== $ce_content && conexao_commemorative_theme_file_exists( $ce_content . '.php' ) ) {
			get_template_part( $ce_content, null, array( 'ce' => $ce ) );
		}
		?>
	</div>
</div>
