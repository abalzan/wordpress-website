<?php
// Guide content builder part 1.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_footer( string $last_checked, array $links ): string {
	$c = '<!-- wp:heading --><h2>Official sources</h2><!-- /wp:heading -->' . "\n";
	$c .= '<!-- wp:paragraph --><p>This guide is for information only. Rules and procedures can change. Always check the official source before making a decision.</p><!-- /wp:paragraph -->' . "\n";
	$c .= '<!-- wp:paragraph --><p><strong>Last checked: ' . $last_checked . '</strong></p><!-- /wp:paragraph -->' . "\n";
	$c .= '<!-- wp:list --><ul>' . "\n";
	foreach ( $links as $label => $url ) {
		$c .= '<li><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $label . '</a></li>' . "\n";
	}
	$c .= '</ul><!-- /wp:list -->' . "\n";
	$c .= '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->' . "\n\n";
	return $c;
}
function conexao_guide_en_faq_head(): string {
	return '<!-- wp:heading --><h2>Frequently asked questions</h2><!-- /wp:heading -->' . "\n";
}
function conexao_guide_en_know(): string {
	return '<!-- wp:heading --><h2>Good to know</h2><!-- /wp:heading -->' . "\n";
}
function g_h2( string $t ): string { return '<!-- wp:heading --><h2>' . $t . '</h2><!-- /wp:heading -->' . "\n"; }
function g_h3( string $t ): string { return '<!-- wp:heading --><h3>' . $t . '</h3><!-- /wp:heading -->' . "\n"; }
function g_p( string $t ): string { return '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->' . "\n"; }
function g_ul( array $items ): string {
	$o = '<!-- wp:list --><ul>' . "\n";
	foreach ( $items as $i ) { $o .= '<li>' . $i . '</li>' . "\n"; }
	return $o . '</ul><!-- /wp:list -->' . "\n";
}
function g_ol( array $items ): string {
	$o = '<!-- wp:list --><ol>' . "\n";
	foreach ( $items as $i ) { $o .= '<li>' . $i . '</li>' . "\n"; }
	return $o . '</ol><!-- /wp:list -->' . "\n";
}
function g_table( array $head, array $rows ): string {
	$o = '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr>';
	foreach ( $head as $h ) { $o .= '<th>' . $h . '</th>'; }
	$o .= '</tr></thead><tbody>' . "\n";
	foreach ( $rows as $r ) {
		$o .= '<tr>';
		foreach ( $r as $c ) { $o .= '<td>' . $c . '</td>'; }
		$o .= '</tr>' . "\n";
	}
	return $o . '</tbody></table></figure><!-- /wp:table -->' . "\n";
}
function g_a( string $label, string $url ): string { return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>'; }
function g_links( array $pairs ): string {
	$o = '<strong>Official links:</strong> ';
	foreach ( $pairs as $k => $v ) { $o .= g_a( $k, $v ) . ' · '; }
	return g_p( rtrim( $o, ' ·' ) );
}
function g_faq( array $qa ): string {
	$o = conexao_guide_en_faq_head();
	foreach ( $qa as $q => $a ) { $o .= g_h3( $q ) . g_p( $a ); }
	return $o;
}
function g_know( array $items ): string { return conexao_guide_en_know() . g_ul( $items ); }
function g_src( array $links ): string { return conexao_guide_en_footer( '3 September 2026', $links ); }
function g_intro( string $meta_desc, string $url, string $intro ): string {
	return g_p( '<strong>Meta description:</strong> ' . $meta_desc )
		. g_p( '<strong>Suggested URL:</strong> ' . $url )
		. g_h2( 'Introduction' )
		. g_p( $intro );
}
function g_audience( string $t ): string { return g_h2( 'Who this guide is for' ) . g_p( $t ); }
function g_steps( array $items ): string { return g_h2( 'Step by step' ) . g_ul( $items ); }
function g_documents( array $items ): string { return g_h2( 'Documents and information needed' ) . g_ul( $items ); }
function g_costs( string $t ): string { return g_h2( 'Costs and fees' ) . g_p( $t ); }
function g_timelines( string $t ): string { return g_h2( 'Timelines and important dates' ) . g_p( $t ); }
function g_pitfalls( array $items ): string { return g_h2( 'Common mistakes and points to note' ) . g_ul( $items ); }
function g_plain_links( string $heading, array $links ): string {
	$o = g_h2( $heading ) . '<!-- wp:list --><ul>' . "\n";
	foreach ( $links as $label => $url ) { $o .= '<li>' . g_a( $label, $url ) . '</li>' . "\n"; }
	return $o . '</ul><!-- /wp:list -->' . "\n";
}
function g_check( string $date, string $note ): string {
	return g_h2( 'Last checked' ) . g_p( 'Information checked on ' . $date . '. Reconfirm fees, deadlines, forms, occupation lists, opening hours and procedures before publication and before advising readers on a specific case.' );
}
function g_disclaimer( string $t ): string { return g_h2( 'Important notice' ) . g_p( $t ); }
function g_sep(): string { return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->' . "\n\n"; }
/**
 * Normalise authored HTML to the exact form WordPress stores.
 *
 * On save, KSES (`wp_kses_normalize_entities`, wired to `content_save_pre` for
 * every user without `unfiltered_html`) rewrites a self-closing tag from
 * `attr="v"/>` to `attr="v" />`. Without this normalisation the importer would
 * write the same content on every run and `post_modified` would never settle,
 * because the stored value would never be byte-identical to the manifest.
 *
 * Only the whitespace before a self-closing slash is touched — no content, no
 * link, no entity changes.
 *
 * @param string $html Authored HTML.
 * @return string Stored form.
 */
function g_content( string $html ): string {
	return preg_replace( '#"\s*/>#', '" />', $html );
}
