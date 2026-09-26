<?php
/**
 * hreflang alternate output
 *
 * Emits the <link rel="alternate" hreflang> set in wp_head. The DATA comes
 * from conexao_hreflang_links() in inc/i18n/hreflang.php, which owns the
 * language policy; this module is the single SEO owner of the markup.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Translation-aware hreflang alternates (Stage 2).
 *
 * The theme is the single owner of hreflang output (architecture §14). Links
 * come from `conexao_hreflang_links()` (inc/polylang.php), which only ever
 * returns alternates for REAL translation relationships or content-backed
 * language archives — never for a missing translation, a redirect-only URL or
 * an empty archive. With Polylang inactive the list is empty, so
 * single-language installs emit nothing.
 */
function conexao_seo_hreflang() {
	foreach ( conexao_hreflang_links() as $link ) {
		if ( empty( $link['hreflang'] ) || empty( $link['url'] ) ) {
			continue;
		}

		echo '<link rel="alternate" hreflang="' . esc_attr( $link['hreflang'] ) . '" href="' . esc_url( $link['url'] ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_hreflang', 5 );
