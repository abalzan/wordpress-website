<?php
/**
 * Language switcher (Stage 2).
 *
 * Rendered by conexao_language_switcher() from the theme header (desktop
 * actions row and the mobile menu drawer). URLs come from
 * conexao_language_switcher_data() → conexao_language_switch_url(), so the
 * switcher only ever points at a real translation or a real target-language
 * archive/home — never at a non-existent detail page (approved B5 policy).
 *
 * Text labels ("PT" / "EN"), no flags: flags are not part of the site's
 * visual language.
 *
 * @package Conexao_BR_Irlanda
 *
 * @param array $args {
 *     @type string $context 'desktop' or 'mobile'.
 * }
 */

defined( 'ABSPATH' ) || exit;

$rows = conexao_language_switcher_data();

if ( count( $rows ) < 2 ) {
	return;
}

$context = isset( $args['context'] ) ? sanitize_html_class( (string) $args['context'] ) : 'desktop';
?>
<div class="language-switcher language-switcher--<?php echo esc_attr( $context ); ?>" role="group" aria-label="<?php esc_attr_e( 'Idioma do site', 'conexao-br-irlanda' ); ?>">
	<?php foreach ( $rows as $row ) : ?>
		<?php if ( $row['current'] ) : ?>
			<span class="language-switcher-item is-current" aria-current="true" lang="<?php echo esc_attr( conexao_hreflang_code( $row['slug'] ) ); ?>"><?php echo esc_html( $row['label'] ); ?></span>
		<?php else : ?>
			<a
				class="language-switcher-item"
				href="<?php echo esc_url( $row['url'] ); ?>"
				hreflang="<?php echo esc_attr( conexao_hreflang_code( $row['slug'] ) ); ?>"
				lang="<?php echo esc_attr( conexao_hreflang_code( $row['slug'] ) ); ?>"
				<?php /* translators: %s: language name, e.g. "English". */ ?>
				aria-label="<?php echo esc_attr( sprintf( __( 'Ver em %s', 'conexao-br-irlanda' ), $row['name'] ) ); ?>"
			><?php echo esc_html( $row['label'] ); ?></a>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
