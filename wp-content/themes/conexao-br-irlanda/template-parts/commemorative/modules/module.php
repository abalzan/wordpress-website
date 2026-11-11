<?php
/**
 * Shared landing-page module component.
 *
 * One reusable markup contract for every module (r10 §7.3): a labelled
 * `<section>` with an `<h2>`, an optional intro and a list of cards. A module with
 * no real items renders NOTHING, so the "empty-landing-page rule" (§7.13) can
 * never be satisfied by placeholder copy.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce_module = isset( $args['module'] ) && is_array( $args['module'] ) ? $args['module'] : array();

if ( empty( $ce_module['items'] ) || empty( $ce_module['title'] ) ) {
	return;
}

$ce_module_id  = '' !== (string) $ce_module['id'] ? (string) $ce_module['id'] : 'section';
$ce_heading_id = 'ce-module-' . sanitize_html_class( $ce_module_id );
?>
<section class="ce-module ce-module--<?php echo esc_attr( sanitize_html_class( $ce_module_id ) ); ?>" aria-labelledby="<?php echo esc_attr( $ce_heading_id ); ?>">
	<h2 class="ce-module__title" id="<?php echo esc_attr( $ce_heading_id ); ?>"><?php echo esc_html( $ce_module['title'] ); ?></h2>
	<?php if ( ! empty( $ce_module['intro'] ) ) : ?>
		<p class="ce-module__intro"><?php echo esc_html( $ce_module['intro'] ); ?></p>
	<?php endif; ?>
	<ul class="ce-module__list">
		<?php foreach ( $ce_module['items'] as $ce_item ) : ?>
			<li class="ce-module__item">
				<article class="ce-card">
					<p class="ce-card__type">
						<?php echo esc_html( $ce_item['type'] ); ?>
						<?php if ( ! empty( $ce_item['meta'] ) ) : ?>
							<span class="ce-card__meta"><?php echo esc_html( $ce_item['meta'] ); ?></span>
						<?php endif; ?>
					</p>
					<h3 class="ce-card__title">
						<a href="<?php echo esc_url( $ce_item['url'] ); ?>"><?php echo esc_html( $ce_item['title'] ); ?></a>
					</h3>
					<?php if ( ! empty( $ce_item['summary'] ) ) : ?>
						<p class="ce-card__summary"><?php echo esc_html( $ce_item['summary'] ); ?></p>
					<?php endif; ?>
				</article>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
