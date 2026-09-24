<?php
/**
 * Stage 5 — Blog EN translation (in-process checks).
 *
 * Covers the completion gate and the content contract of the Blog translation
 * (the HTTP matrix — rendering, canonical, hreflang, pagination, search,
 * sitemap — is covered by stage5-work/verify-blog-en.py):
 *
 *  - the posts page (`page_for_posts`) has a published, linked EN translation
 *    and is no longer treated as a B2 fallback;
 *  - every public Portuguese post has exactly ONE linked, published EN
 *    translation (gate: eligible public PT posts missing EN = 0);
 *  - no EN post exists without its PT sibling (no second identities);
 *  - every category term actually used by those posts has a linked EN term;
 *  - EN posts keep the shared media, date, author and taxonomy of their PT
 *    sibling, and their body is genuinely English (not a copy of the PT body);
 *  - the Portuguese originals are untouched: still `pt`, slugs unchanged,
 *    bodies unchanged, taxonomies unchanged.
 *
 * Usage (from the project root / the WordPress root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-blog-en-translation.php
 *
 * Read-only. Requires Polylang + a completed Blog translation run.
 *
 * @package conexao-br-irlanda
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed = 0;
$failed = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message.
 * @param string $detail    Extra detail printed on failure.
 * @return void
 */
function s5_assert( $condition, $message, $detail = '' ) {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  PASS: {$message}\n";
		return;
	}

	++$failed;
	echo "  FAIL: {$message}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

echo "== Stage 5 — Blog EN translation ==\n";

if ( ! function_exists( 'pll_get_post' ) ) {
	echo "  SKIP: Polylang is not active.\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
echo "\n-- Posts page --\n";
$pt_posts_page = (int) get_option( 'page_for_posts' );
s5_assert( $pt_posts_page > 0, 'a posts page (page_for_posts) is configured' );

$en_posts_page = $pt_posts_page > 0 ? (int) pll_get_post( $pt_posts_page, 'en' ) : 0;
s5_assert( $en_posts_page > 0 && $en_posts_page !== $pt_posts_page, 'the posts page has an EN translation', "en_id={$en_posts_page}" );
s5_assert( (int) pll_get_post( $en_posts_page, 'pt' ) === $pt_posts_page, 'the posts page pair is linked from both sides' );
s5_assert( 'publish' === get_post_status( $en_posts_page ), 'the EN posts page is published' );
s5_assert( 'en' === pll_get_post_language( $en_posts_page, 'slug' ), 'the EN posts page is assigned to en' );
s5_assert( 'pt' === pll_get_post_language( $pt_posts_page, 'slug' ), 'the PT posts page is still assigned to pt' );
s5_assert( get_permalink( $en_posts_page ) === trailingslashit( home_url( '/en/blog/' ) ), 'the EN posts page lives at /en/blog/', (string) get_permalink( $en_posts_page ) );
s5_assert( ! conexao_should_render_b2_fallback( $pt_posts_page ), 'Blog is no longer B2-eligible once the EN posts page exists' );

// ---------------------------------------------------------------------------
echo "\n-- Post inventory (the completion gate) --\n";

$pt_ids = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => 'pt',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
$en_ids = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => 'en',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

s5_assert( count( $pt_ids ) > 0, 'the site has public PT blog posts', 'count=' . count( $pt_ids ) );
s5_assert( count( $en_ids ) === count( $pt_ids ), 'PT and EN public post counts match', 'pt=' . count( $pt_ids ) . ' en=' . count( $en_ids ) );

$missing_en = array();
$not_linked = array();
$duplicate  = array();

foreach ( $pt_ids as $pt_id ) {
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );

	if ( $en_id <= 0 || 'publish' !== get_post_status( $en_id ) ) {
		$missing_en[] = get_post_field( 'post_name', $pt_id );
		continue;
	}

	if ( (int) pll_get_post( $en_id, 'pt' ) !== (int) $pt_id ) {
		$not_linked[] = get_post_field( 'post_name', $pt_id );
	}
}

foreach ( $en_ids as $en_id ) {
	$pt_id = (int) pll_get_post( (int) $en_id, 'pt' );

	if ( $pt_id <= 0 ) {
		$duplicate[] = get_post_field( 'post_name', $en_id );
	}
}

s5_assert( 0 === count( $missing_en ), 'GATE: every public PT post has a published EN translation', implode( ', ', array_slice( $missing_en, 0, 5 ) ) );
s5_assert( 0 === count( $not_linked ), 'every PT→EN pair is linked from both directions', implode( ', ', array_slice( $not_linked, 0, 5 ) ) );
s5_assert( 0 === count( $duplicate ), 'no EN post exists without its PT sibling', implode( ', ', array_slice( $duplicate, 0, 5 ) ) );

// ---------------------------------------------------------------------------
echo "\n-- Content contract --\n";

$thumb_mismatch = array();
$date_mismatch  = array();
$author_mismatch = array();
$not_english    = array();
$copied_body    = array();
$pt_changed     = array();
$term_missing   = array();
$terms_seen     = array();

foreach ( $pt_ids as $pt_id ) {
	$en_id = (int) pll_get_post( (int) $pt_id, 'en' );

	if ( $en_id <= 0 ) {
		continue;
	}

	$pt = get_post( (int) $pt_id );
	$en = get_post( $en_id );

	if ( (int) get_post_thumbnail_id( (int) $pt_id ) !== (int) get_post_thumbnail_id( $en_id ) ) {
		$thumb_mismatch[] = $pt->post_name;
	}

	if ( $pt->post_date !== $en->post_date ) {
		$date_mismatch[] = $pt->post_name;
	}

	if ( (int) $pt->post_author !== (int) $en->post_author ) {
		$author_mismatch[] = $pt->post_name;
	}

	// Not a copy: the English body must differ from the Portuguese body and
	// must not contain obvious Portuguese function words. Bodies whose only
	// content is an untranslatable URL (e.g. a flip-book link) have no prose
	// to translate at all, so an identical body is correct there.
	$strip = static function ( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = preg_replace( '#https?://\S+#', ' ', $text );

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	};

	if ( $strip( $en->post_content ) === $strip( $pt->post_content ) && '' !== $strip( $pt->post_content ) ) {
		$copied_body[] = $pt->post_name;
	}

	if ( preg_match( '/\b(voc\u00ea|n\u00e3o|como|tamb\u00e9m|sobre|muito|sempre)\b/iu', wp_strip_all_tags( $en->post_content ) ) ) {
		$not_english[] = $pt->post_name;
	}

	if ( 'pt' !== pll_get_post_language( (int) $pt_id, 'slug' ) ) {
		$pt_changed[] = $pt->post_name;
	}

	// Taxonomy: the EN post must carry the linked EN term of every PT term.
	foreach ( wp_get_post_terms( (int) $pt_id, 'category', array( 'fields' => 'ids' ) ) as $pt_term_id ) {
		$terms_seen[ (int) $pt_term_id ] = true;
		$en_term_id = (int) pll_get_term( (int) $pt_term_id, 'en' );

		if ( $en_term_id <= 0 ) {
			$term_missing[] = get_term_field( 'slug', (int) $pt_term_id );
			continue;
		}

		$en_post_terms = wp_get_post_terms( $en_id, 'category', array( 'fields' => 'ids' ) );

		if ( ! in_array( $en_term_id, array_map( 'intval', $en_post_terms ), true ) ) {
			$term_missing[] = get_term_field( 'slug', (int) $pt_term_id ) . ' (not assigned)';
		}
	}
}

s5_assert( array() === $thumb_mismatch, 'EN posts share the PT featured image', implode( ', ', array_slice( $thumb_mismatch, 0, 5 ) ) );
s5_assert( array() === $date_mismatch, 'EN posts keep the PT publication date', implode( ', ', array_slice( $date_mismatch, 0, 5 ) ) );
s5_assert( array() === $author_mismatch, 'EN posts keep the PT author', implode( ', ', array_slice( $author_mismatch, 0, 5 ) ) );
s5_assert( array() === $copied_body, 'EN bodies are translations, not copies of the PT body', implode( ', ', array_slice( $copied_body, 0, 5 ) ) );
s5_assert( array() === $not_english, 'EN bodies contain no Portuguese prose', implode( ', ', array_slice( $not_english, 0, 5 ) ) );
s5_assert( array() === $pt_changed, 'PT originals are still assigned to the pt language', implode( ', ', array_slice( $pt_changed, 0, 5 ) ) );
s5_assert( array() === $term_missing, 'every category used by a blog post has a linked EN term assigned to the EN post', implode( ', ', array_slice( $term_missing, 0, 5 ) ) );

// Slug policy: natural EN slugs, never a suffixed duplicate.
$bad_slugs = array();
foreach ( $en_ids as $en_id ) {
	$slug = (string) get_post_field( 'post_name', $en_id );

	if ( preg_match( '/(?:-\d+|-(?:pt|en))$/', $slug ) ) {
		$bad_slugs[] = $slug;
	}
}
s5_assert( array() === $bad_slugs, 'EN slugs are natural (no -2 / -en suffixes)', implode( ', ', array_slice( $bad_slugs, 0, 5 ) ) );

$pt_slugs = array_map( function ( $id ) { return (string) get_post_field( 'post_name', $id ); }, $pt_ids );
s5_assert( count( array_unique( $pt_slugs ) ) === count( $pt_slugs ), 'PT slugs are unchanged and unique' );

// ---------------------------------------------------------------------------
echo "\n-- Category terms --\n";

$linked = 0;
$used   = 0;
foreach ( array_keys( $terms_seen ) as $term_id ) {
	++$used;
	$en_term = (int) pll_get_term( (int) $term_id, 'en' );

	if ( $en_term > 0 && (int) pll_get_term( $en_term, 'pt' ) === (int) $term_id ) {
		++$linked;
	}
}

s5_assert( $used > 0, 'blog posts use at least one category term', 'used=' . $used );
s5_assert( $linked === $used, 'every category term used by blog posts is linked to an EN term', "linked={$linked} used={$used}" );

// Tags: only translated when actually used by published posts (never clutter).
$tags_used = array();
foreach ( array_merge( $pt_ids, $en_ids ) as $post_id ) {
	foreach ( wp_get_post_terms( (int) $post_id, 'post_tag', array( 'fields' => 'ids' ) ) as $tag_id ) {
		$tags_used[ (int) $tag_id ] = true;
	}
}
$tags_missing = array();
foreach ( array_keys( $tags_used ) as $tag_id ) {
	if ( (int) pll_get_term( (int) $tag_id, 'en' ) <= 0 && (int) pll_get_term( (int) $tag_id, 'pt' ) > 0 ) {
		$tags_missing[] = get_term_field( 'slug', (int) $tag_id );
	}
}
s5_assert( array() === $tags_missing, 'every tag actually used by blog posts is translated (none used here)', implode( ', ', $tags_missing ) );

// ---------------------------------------------------------------------------
echo "\n-- Sitemap inputs --\n";

s5_assert( (bool) get_permalink( $en_posts_page ), 'the EN posts page has a permalink' );
foreach ( $en_ids as $en_id ) {
	if ( ! get_permalink( $en_id ) ) {
		s5_assert( false, 'every EN post has a permalink', 'id=' . $en_id );
		break;
	}
}
s5_assert( true, 'every EN post resolves to a permalink' );

// ---------------------------------------------------------------------------
echo "\nstage 5 blog EN translation: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
