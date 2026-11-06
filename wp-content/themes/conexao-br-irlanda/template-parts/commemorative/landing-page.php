<?php
/**
 * Shared dedicated landing-page placement shell.
 *
 * A normal page, never an overlay (r10 §7.8): the shell renders a semantic
 * `<main>`-level hero (one `<h1>`), the page's own intro and then the package's
 * module composition. Because the copy comes from the PAGE (not the event), the
 * page keeps its archival value after the event window closes (r10 §7.13).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || empty( $ce['placement'] ) || empty( $ce['page_id'] ) ) {
	return;
}

$ce_page = get_post( (int) $ce['page_id'] );

if ( ! $ce_page instanceof WP_Post ) {
	return;
}

$ce_attributes = conexao_commemorative_wrapper_attributes( $ce );
$ce_content    = isset( $ce['placement']['content'] ) ? (string) $ce['placement']['content'] : '';
?>
<div<?php echo $ce_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by conexao_commemorative_wrapper_attributes(). ?> data-ce-landing="<?php echo esc_attr( (string) $ce['page_id'] ); ?>">
	<div class="site-container ce-landing">
		<header class="ce-landing__hero">
			<p class="ce-landing__kicker"><?php echo esc_html( $ce['package']['label'] ); ?></p>
			<h1 class="ce-landing__title"><?php echo esc_html( get_the_title( $ce_page ) ); ?></h1>
			<?php if ( '' !== trim( (string) $ce_page->post_content ) ) : ?>
				<div class="ce-landing__intro"><?php echo wp_kses_post( apply_filters( 'the_content', $ce_page->post_content ) ); ?></div>
			<?php endif; ?>
		</header>
		<?php
		if ( '' !== $ce_content && conexao_commemorative_theme_file_exists( $ce_content . '.php' ) ) {
			get_template_part(
				$ce_content,
				null,
				array(
					'ce'   => $ce,
					'page' => $ce_page,
				)
			);
		}
		?>
	</div>
</div>
