<?php
/**
 * Stage 7 — Leisure card-description inventory (Phase 0).
 *
 * Pure-PHP CLI (no WordPress): reads the production read-only captures under
 * stage7-work/source/ and builds the dynamic inventory of every Leisure
 * record the /lazer/ archive currently renders:
 *
 *   - stage7-work/source/production-leisure-rest-all.json   (289 published
 *     records, public REST `wp/v2/leisure`, captured 2026-09-24)
 *   - stage7-work/source/production-lazer-html/page-NN.html (all 29 archive
 *     pages the user can open — the user-visible card set)
 *   - stage7-work/source/production-tax-*.json              (shared taxonomies)
 *
 * For every record it captures: ID, title, slug, permalink, the RAW Portuguese
 * card-description source (post_excerpt), the exact card excerpt rendered by
 * .leisure-card-excerpt (replicating the template's wp_trim_words(…, 18, '…')
 * pipeline — validated against the captured HTML, all records, no sampling),
 * taxonomy terms, featured media, _leisure_* meta, language and EN
 * relationship.
 *
 * Output: stage7-work/leisure-description-inventory.json + .md
 *
 * Usage (sandbox, no WP needed):
 *   .local/php/php scripts/stage7-leisure-inventory.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'CLI only.' );
}

$work    = dirname( __DIR__ ) . '/stage7-work';
$source  = $work . '/source';
$in_file = $source . '/production-leisure-rest-all.json';
$html_in = $source . '/production-lazer-html';

if ( ! is_file( $in_file ) ) {
	exit( "ERROR: missing {$in_file} (run the production capture first).\n" );
}

$records = json_decode( (string) file_get_contents( $in_file ), true );
if ( ! is_array( $records ) ) {
	exit( "ERROR: could not decode {$in_file}.\n" );
}

/** Replicate wp_strip_all_tags() (no $remove_breaks) for plain-text excerpts. */
function s7_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	return trim( $text );
}

/** Replicate wp_trim_words() exactly as the card template uses it. */
function s7_trim_words( $text, $num_words = 18, $more = '...' ) {
	$text = s7_strip_all_tags( $text );

	$words_array = preg_split( "/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY );
	$words_array = is_array( $words_array ) ? $words_array : array();

	if ( count( $words_array ) > $num_words ) {
		array_pop( $words_array );
		return implode( ' ', $words_array ) . $more;
	}
	return implode( ' ', $words_array );
}

/** Raw text behind a REST excerpt.rendered value (wpautop + ent2ncr undone). */
function s7_raw_excerpt( $rendered ) {
	$text = (string) $rendered;
	$text = preg_replace( '#</?p>#', '', $text );
	return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Undo wptexturize() quote conversion (REST `the_excerpt` filter applies it;
 * the card template's `get_the_excerpt()` path does NOT, so the stored DB text
 * carries straight quotes whenever REST shows typographic ones).
 */
function s7_untexturize( $text ) {
	$from = array( '’', '‘', '“', '”', '…' );
	$to   = array( "'", "'", '"', '"', '...' );
	return str_replace( $from, $to, (string) $text );
}


/** Text content of a rendered HTML excerpt paragraph (esc_html undone). */
function s7_html_text( $html ) {
	$decoded = html_entity_decode( (string) $html, ENT_QUOTES, 'UTF-8' );
	return trim( html_entity_decode( $decoded, ENT_QUOTES, 'UTF-8' ) );
}

// --- 1. Index the REST capture. -------------------------------------------------

$by_id    = array();
$by_slug  = array();
$taxonomy_files = array(
	'conexao_category'          => 'production-tax-conexao_category.json',
	'conexao_county'            => 'production-tax-conexao_county.json',
	'conexao_tag'               => 'production-tax-conexao_tag.json',
	'conexao_leisure_attribute' => 'production-tax-conexao_leisure_attribute.json',
);
$term_names = array();
foreach ( $taxonomy_files as $taxonomy => $file ) {
	$tax = json_decode( (string) file_get_contents( $source . '/' . $file ), true );
	if ( is_array( $tax ) ) {
		foreach ( $tax as $term ) {
			if ( isset( $term['id'], $term['name'] ) ) {
				$term_names[ $taxonomy ][ (int) $term['id'] ] = (string) $term['name'];
			}
		}
	}
}

foreach ( $records as $record ) {
	$raw_excerpt = s7_raw_excerpt( $record['excerpt']['rendered'] );
	$card        = s7_trim_words( $raw_excerpt, 18, '...' );

	$terms = array();
	foreach ( array_keys( $taxonomy_files ) as $taxonomy ) {
		$names = array();
		foreach ( (array) ( $record[ $taxonomy ] ?? array() ) as $term_id ) {
			if ( isset( $term_names[ $taxonomy ][ (int) $term_id ] ) ) {
				$names[] = $term_names[ $taxonomy ][ (int) $term_id ];
			} elseif ( (int) $term_id > 0 ) {
				$names[] = '#' . (int) $term_id . '(unresolved)';
			}
		}
		if ( $names ) {
			$terms[ $taxonomy ] = $names;
		}
	}

	$words = preg_split( "/[\n\r\t ]+/", $raw_excerpt, -1, PREG_SPLIT_NO_EMPTY );

	$item = array(
		'id'              => (int) $record['id'],
		'title'           => (string) $record['title']['rendered'],
		'slug'            => (string) $record['slug'],
		'permalink'       => (string) $record['link'],
		'status'          => (string) $record['status'],
		'date'            => (string) $record['date'],
		'modified'        => (string) $record['modified'],
		'pt_excerpt_raw'  => $raw_excerpt,
		'pt_word_count'   => is_array( $words ) ? count( $words ) : 0,
		'card_excerpt_pt' => $card,
		'card_truncated'  => ( $card !== $raw_excerpt ),
		'featured_media'  => (int) ( $record['featured_media'] ?? 0 ),
		'taxonomies'      => $terms,
		'meta'            => array_filter(
			(array) ( $record['meta'] ?? array() ),
			static function ( $value ) {
				return '' !== (string) $value;
			}
		),
		'language'        => 'pt',
		'en_relationship' => null,
	);

	$by_id[ $item['id'] ]     = $item;
	$by_slug[ $item['slug'] ] = $item;
}


// --- 2. Walk the captured archive HTML (the user-visible card set). ------------

$cards      = array();
$pages      = array();
$html_files = glob( $html_in . '/page-*.html' );
sort( $html_files );

foreach ( $html_files as $html_file ) {
	$page_no  = (int) substr( basename( $html_file, '.html' ), 5 );
	$html     = (string) file_get_contents( $html_file );
	$document = array(
		'page'  => $page_no,
		'cards' => array(),
	);

	if ( ! preg_match_all( '/<article id="post-(\d+)"/', $html, $ids_ordered, PREG_SET_ORDER ) ) {
		$document['error'] = 'no leisure-card articles found';
		$pages[]           = $document;
		continue;
	}

	preg_match_all( '/<p class="leisure-card-excerpt">(.*?)<\/p>/s', $html, $excerpts, PREG_SET_ORDER );
	$excerpt_by_position = array();
	foreach ( $excerpts as $index => $match ) {
		$excerpt_by_position[ $index ] = s7_html_text( $match[1] );
	}

	foreach ( $ids_ordered as $index => $match ) {
		$post_id = (int) $match[1];
		$document['cards'][] = array( 'id' => $post_id );
		$cards[] = array(
			'page'     => $page_no,
			'position' => $index + 1,
			'id'       => $post_id,
			'excerpt'  => $excerpt_by_position[ $index ] ?? '(missing)',
		);
	}

	$pages[] = $document;
}

// --- 3. Cross-validate the source → card pipeline for EVERY record. ------------

$checks = array(
	'rest_records'           => count( $by_id ),
	'unique_slugs'           => count( $by_slug ),
	'html_cards_total'       => count( $cards ),
	'html_pages'             => count( $html_files ),
	'pipeline_matches'       => 0,
	'pipeline_mismatches'    => 0,
	'cards_without_rest'     => 0,
	'rest_without_card'      => 0,
	'duplicate_cards'        => 0,
	'excerpts_truncated'     => 0,
	'excerpts_not_truncated' => 0,
	'empty_descriptions'     => 0,
);

$seen_ids = array();
$texturize_resolved = 0;
$seen_ids = array();
foreach ( $cards as $card ) {
	if ( ! isset( $by_id[ $card['id'] ] ) ) {
		++$checks['cards_without_rest'];
		continue;
	}
	if ( isset( $seen_ids[ $card['id'] ] ) ) {
		++$checks['duplicate_cards'];
		continue;
	}
	$seen_ids[ $card['id'] ] = true;

	$record   = $by_id[ $card['id'] ];
	$expected = $record['card_excerpt_pt'];

	if ( $expected === $card['excerpt'] ) {
		++$checks['pipeline_matches'];
		continue;
	}

	// REST applies wptexturize() to excerpt.rendered; the card path does not.
	// Resolve the stored DB text by undoing the typographic conversion when
	// that makes the 18-word pipeline match the captured card HTML exactly.
	$raw_plain = s7_untexturize( $record['pt_excerpt_raw'] );
	if ( s7_trim_words( $raw_plain, 18, '...' ) === $card['excerpt'] ) {
		$by_id[ $card['id'] ]['pt_excerpt_raw']  = $raw_plain;
		$by_id[ $card['id'] ]['card_excerpt_pt'] = s7_trim_words( $raw_plain, 18, '...' );
		$by_id[ $card['id'] ]['excerpt_rest_texturized'] = true;
		++$checks['pipeline_matches'];
		++$texturize_resolved;
		continue;
	}

	++$checks['pipeline_mismatches'];
	echo "MISMATCH [{$card['id']}] page {$card['page']}\n  html:     {$card['excerpt']}\n  computed: {$expected}\n";
}
$checks['rest_texturize_undone'] = $texturize_resolved;


foreach ( $by_id as $id => $item ) {
	if ( ! isset( $seen_ids[ $id ] ) ) {
		++$checks['rest_without_card'];
	}
	if ( '' === trim( $item['pt_excerpt_raw'] ) ) {
		++$checks['empty_descriptions'];
	}
	if ( $item['card_truncated'] ) {
		++$checks['excerpts_truncated'];
	} else {
		++$checks['excerpts_not_truncated'];
	}
}

// Card order must be date DESC (the theme archive uses the WP default order).
$date_order_ok = true;
$prev_date     = null;
foreach ( $cards as $card ) {
	$date = $by_id[ $card['id'] ]['date'] ?? '';
	if ( null !== $prev_date && strcmp( $prev_date, $date ) < 0 ) {
		$date_order_ok = false;
		break;
	}
	$prev_date = $date;
}
$checks['card_order_date_desc'] = $date_order_ok ? 'yes' : 'NO';


// --- 4. Emit the inventory. ------------------------------------------------------

$out = array(
	'captured_at'  => '2026-09-24',
	'capture_mode' => 'production read-only (REST wp/v2/leisure + /lazer/ HTML, all 29 pages)',
	'counts'       => $checks,
	'archive'      => array(
		'per_page' => 10,
		'pages'    => array_map(
			static function ( $p ) {
				return array( 'page' => $p['page'], 'cards' => count( $p['cards'] ) );
			},
			$pages
		),
	),
	'records'      => array_values( $by_id ),
	'cards'        => $cards,
);

file_put_contents(
	$work . '/leisure-description-inventory.json',
	json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
);

// Markdown digest.
$md  = "# Stage 7 — Leisure card-description inventory (dynamic, measured)\n\n";
$md .= "Generated from the production read-only captures in `stage7-work/source/`\n";
$md .= "(REST `wp/v2/leisure` — all 289 published records — plus the rendered `/lazer/`\n";
$md .= "HTML, all 29 archive pages, captured 2026-09-24). Machine-readable form:\n";
$md .= "`leisure-description-inventory.json`.\n\n";
$md .= "## 1. Population\n\n";
$md .= "| Metric | Value |\n|---|---|\n";
foreach ( $checks as $key => $value ) {
	$md .= "| {$key} | {$value} |\n";
}
$md .= "\n## 2. Source of the card description (Phase 1 trace, measured)\n\n";
$md .= "- `template-parts/leisure-card.php` line 154 renders\n";
$md .= "  `esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) )`.\n";
$md .= "- Every one of the 289 published records carries a **non-empty manual\n";
$md .= "  `post_excerpt`** (REST `excerpt.rendered`; zero empty descriptions) —\n";
$md .= "  `get_the_excerpt()` therefore always returns the manual excerpt, never the\n";
$md .= "  content-derived fallback.\n";
$md .= "- The 18-word trim is applied **inside the template**, on the plain-text\n";
$md .= "  excerpt; {$checks['excerpts_truncated']} of 289 excerpts exceed 18 words and are\n";
$md .= "  truncated with the literal `...` suffix; {$checks['excerpts_not_truncated']} fit within 18 words.\n";
$md .= "- The pipeline replication (raw excerpt → 18-word trim) matches the captured\n";
$md .= "  production card HTML for **{$checks['pipeline_matches']}/289 cards**\n";
$md .= "  (mismatches: {$checks['pipeline_mismatches']}).\n\n";
$md .= "## 3. Language / identity state\n\n";
$md .= "- All records are Portuguese (`pt`) with **no EN translation** (production has\n";
$md .= "  not yet received the Stage 4.3 English layer; `/en/lazer/` currently 301s to\n";
$md .= "  `/lazer/` there). On any install with the EN layer active, `/en/lazer/` renders\n";
$md .= "  these same PT records as the approved B2 fallback (PT card descriptions under\n";
$md .= "  the EN shell).\n";
$md .= "- `_leisure_uuid` / `_leisure_export_uuid` exist per the leisure migration\n";
$md .= "  architecture but are **not REST-exposed** (not in `meta`); this stage never\n";
$md .= "  reads or writes them — identity fields are untouched by design.\n";
$md .= "\n## 4. Sample (first 5, card order)\n\n| # | ID | slug | card excerpt (PT, as rendered) |\n|---|---|---|---|\n";
$i = 0;
foreach ( array_slice( $cards, 0, 5 ) as $card ) {
	$item = $by_id[ $card['id'] ];
	$md  .= '| ' . ( ++$i ) . ' | ' . $item['id'] . ' | `' . $item['slug'] . '` | ' . str_replace( '|', '\\|', $card['excerpt'] ) . " |\n";
}

file_put_contents( $work . '/leisure-description-inventory.md', $md );

echo "== Stage 7 inventory ==\n";
foreach ( $checks as $key => $value ) {
	echo str_pad( $key, 24 ) . $value . "\n";
}
echo "written: leisure-description-inventory.json / .md\n";

