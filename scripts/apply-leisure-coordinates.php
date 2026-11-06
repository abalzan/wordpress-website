<?php
/**
 * Apply reviewed leisure (Lazer) coordinates from a portable research manifest.
 *
 * Purpose:
 *   Populate `_leisure_lat` / `_leisure_lng` on published `leisure` records
 *   from a reviewed, portable JSON manifest produced by the coordinate
 *   research pipeline. This is the "apply" half of the six-step
 *   content-change contract (docs/engineering-standard.md §5.2) for the
 *   Lazer coordinate dataset; the manifest is the versioned plan.
 *
 * Scope:
 *   The `leisure` post type ONLY, and ONLY the four coordinate meta keys
 *   `_leisure_lat`, `_leisure_lng`, `_leisure_coord_source` and
 *   `_leisure_coord_verified`. Titles, slugs, content, excerpts, taxonomies,
 *   images, translations and every other meta key are never touched.
 *
 * Safety:
 *   local-write. Dry-run is the default and performs ZERO writes; `--apply`
 *   is required to write. Only entries whose manifest `action` is
 *   `POPULATE` and whose `confidence` is `HIGH` are ever written, with ONE
 *   narrow exception: an entry the owner has explicitly approved by hand
 *   (`owner_approved => true`) may be written at MEDIUM, because that is a
 *   human decision about a named access point rather than an automatic
 *   promotion of an unverified coordinate. Every such write is counted in
 *   the `owner_approved` summary field so it can never pass unnoticed.
 *   An existing valid coordinate is PRESERVED, never silently replaced — a
 *   differing value is reported as a conflict and skipped. A production
 *   target additionally requires `--confirm-production`. The apply is
 *   idempotent: a second run reports zero changes.
 *
 * Usage:
 *   php scripts/apply-leisure-coordinates.php --manifest=<path> --dry-run
 *   php scripts/apply-leisure-coordinates.php --manifest=<path> --apply
 *   php scripts/apply-leisure-coordinates.php --verify
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'apply-leisure-coordinates.php',
		'purpose'            => 'Populate reviewed leisure coordinates from a portable manifest.',
		'scope'              => "`leisure` posts only; `_leisure_lat`, `_leisure_lng`,\n`_leisure_coord_source`, `_leisure_coord_verified` meta only.",
		'safety'             => 'local-write; dry-run by default; existing valid coordinates are preserved. Only POPULATE + HIGH entries are written, except entries the owner explicitly approved by hand (owner_approved), which are counted separately.',
		'target_description' => 'the WordPress install this script is executed against (resolved from CONEXAO_SITE_URL or home_url()).',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing; --apply performs the plan; --verify reports the coordinate inventory and writes nothing.',
		'arguments'          => array(
			'--manifest=<path>          Path to the portable JSON research manifest (required for --dry-run/--apply).',
			'--verify                   Report the coordinate inventory and exit (read-only).',
		),
		'safety_level'       => 'local-write',
		'writes'             => true,
		'production_capable' => true,
		'json'               => true,
		'extra_flags'        => array(
			'--manifest' => 'manifest',
			'--verify'   => 'verify',
		),
	)
);

/**
 * Load and structurally validate the portable research manifest.
 *
 * @param string $path Absolute or relative path to the JSON manifest.
 * @return array{entries:array<int,array<string,mixed>>}|WP_Error
 */
function conexao_leisure_coord_load_manifest( $path ) {
	if ( '' === $path || ! is_readable( $path ) ) {
		return new WP_Error( 'manifest_missing', sprintf( 'manifest not readable: %s', $path ) );
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return new WP_Error( 'manifest_unreadable', sprintf( 'could not read manifest: %s', $path ) );
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'manifest_invalid', 'manifest is not valid JSON.' );
	}

	// Accept either a bare array of entries or an object with an `entries` key.
	$entries = isset( $decoded['entries'] ) && is_array( $decoded['entries'] ) ? $decoded['entries'] : $decoded;

	$out = array();
	foreach ( $entries as $entry ) {
		if ( is_array( $entry ) ) {
			$out[] = $entry;
		}
	}

	if ( empty( $out ) ) {
		return new WP_Error( 'manifest_empty', 'manifest contains no entries.' );
	}

	return array( 'entries' => $out );
}

/**
 * Resolve a leisure post from a portable manifest entry.
 *
 * Matching order is the repository's documented importer order for the Lazer
 * dataset: portable export UUID → slug → title. Local post IDs are NEVER
 * used as identity.
 *
 * `_leisure_export_uuid` identifies the ATTRACTION, not the WordPress post: a
 * Portuguese master and its Polylang English translation deliberately share one
 * value (see `Conexao_Lazer_Importer::pick_import_language()` and
 * `test-language-uuid.php`). A UUID lookup can therefore legitimately return two
 * records, so a duplicate match is disambiguated by the manifest entry's own
 * slug and then its language. If the target is still ambiguous the function
 * returns a WP_Error and NOTHING is written, so the tool can never silently
 * write the same coordinate twice to one post and skip the other.
 *
 * @param array $entry Manifest entry.
 * @return array{id:int,strategy:string}|WP_Error
 */
function conexao_leisure_coord_resolve( array $entry ) {
	global $wpdb;

	$uuid = isset( $entry['uuid'] ) ? trim( (string) $entry['uuid'] ) : '';
	if ( '' !== $uuid ) {
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC",
				'_leisure_export_uuid',
				$uuid
			)
		);
		$found = array_values( array_unique( array_map( 'intval', (array) $found ) ) );

		if ( 1 === count( $found ) ) {
			return array( 'id' => $found[0], 'strategy' => 'stable-id' );
		}

		if ( count( $found ) > 1 ) {
			return conexao_leisure_coord_disambiguate( $entry, $found );
		}
	}

	$slug = isset( $entry['slug'] ) ? sanitize_title( (string) $entry['slug'] ) : '';
	if ( '' !== $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'leisure' );
		if ( $post instanceof WP_Post ) {
			return array( 'id' => (int) $post->ID, 'strategy' => 'slug' );
		}
	}

	$title = isset( $entry['title'] ) ? trim( (string) $entry['title'] ) : '';
	if ( '' !== $title ) {
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'leisure' AND post_title = %s LIMIT 1",
				$title
			)
		);
		if ( $found ) {
			return array( 'id' => (int) $found, 'strategy' => 'title' );
		}
	}

	return new WP_Error(
		'unresolved',
		sprintf( 'no leisure record for uuid=%s slug=%s title=%s', $uuid, $slug, $title )
	);
}

/**
 * Disambiguate a UUID that legitimately matches more than one leisure record.
 *
 * `_leisure_export_uuid` identifies the attraction, so a PT master and its
 * linked Polylang English translation share one value and a UUID lookup returns
 * both. The manifest entry itself says WHICH of the pair it describes, so the
 * entry's slug is tried first and its language second. If neither narrows the
 * set to exactly one record, the caller is given a WP_Error and writes nothing.
 *
 * @param array $entry Manifest entry.
 * @param int[] $ids   Every leisure post carrying the entry's UUID.
 * @return array{id:int,strategy:string}|WP_Error
 */
function conexao_leisure_coord_disambiguate( array $entry, array $ids ) {
	$candidates = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$slug       = isset( $entry['slug'] ) ? sanitize_title( (string) $entry['slug'] ) : '';
	$lang       = isset( $entry['lang'] ) ? sanitize_key( (string) $entry['lang'] ) : '';

	if ( '' !== $slug ) {
		$by_slug = array();
		foreach ( $candidates as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && $post->post_name === $slug ) {
				$by_slug[] = $id;
			}
		}
		if ( 1 === count( $by_slug ) ) {
			return array( 'id' => $by_slug[0], 'strategy' => 'stable-id+slug' );
		}
		if ( count( $by_slug ) > 1 ) {
			$candidates = $by_slug;
		}
	}

	if ( '' !== $lang && function_exists( 'pll_get_post_language' ) ) {
		$by_lang = array();
		foreach ( $candidates as $id ) {
			if ( $lang === (string) pll_get_post_language( $id, 'slug' ) ) {
				$by_lang[] = $id;
			}
		}
		if ( 1 === count( $by_lang ) ) {
			return array( 'id' => $by_lang[0], 'strategy' => 'stable-id+lang' );
		}
		if ( count( $by_lang ) > 1 ) {
			$candidates = $by_lang;
		}
	}

	return new WP_Error(
		'uuid_ambiguous',
		sprintf(
			'uuid=%s matches %d leisure records (%s) and the entry (slug=%s lang=%s) does not disambiguate them; nothing was written.',
			(string) ( $entry['uuid'] ?? '' ),
			count( $candidates ),
			implode( ',', $candidates ),
			'' === $slug ? '-' : $slug,
			'' === $lang ? '-' : $lang
		)
	);
}

/**
 * Format the provenance string stored in `_leisure_coord_source`.
 *
 * The stored point must be traceable to the source that actually produced it:
 * an owner-supplied entry records the owner's stated source, and only a
 * pipeline entry records the two-source agreement. Mixing the two would make
 * the audit field contradict the stored coordinate.
 *
 * @param array $entry Manifest entry.
 * @return string
 */
function conexao_leisure_coord_source_text( array $entry ) {
	if ( ! empty( $entry['owner_source'] ) ) {
		$text = (string) $entry['owner_source'];
		if ( ! empty( $entry['owner_approved'] ) ) {
			$text .= ' [owner-approved at MEDIUM confidence]';
		}

		return $text;
	}

	$parts = array();
	if ( ! empty( $entry['wikipedia']['title'] ) ) {
		$parts[] = 'Wikipedia: ' . $entry['wikipedia']['title'];
	}
	if ( ! empty( $entry['osm']['osm'] ) ) {
		$parts[] = 'OpenStreetMap: ' . $entry['osm']['osm'];
	}
	$parts[] = 'two-source agreement';
	if ( isset( $entry['distance_m'] ) && is_numeric( $entry['distance_m'] ) ) {
		$parts[] = (int) round( (float) $entry['distance_m'] ) . ' m apart';
	}
	$parts[] = 'research 2026-11-03';

	return implode( '; ', $parts );
}

/**
 * The coordinate inventory of every published leisure record.
 *
 * @return array{total:int,with:int,missing:int,invalid:int,verified:int,ids:array<int,int>}
 */
function conexao_leisure_coord_inventory() {
	$ids = get_posts(
		array(
			'post_type'      => 'leisure',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// Language-neutral inventory: the EN sibling of a PT record is the
			// same attraction and must not silently drop out of the counts.
			'lang'           => '',
		)
	);

	$counts = array(
		'total'    => count( $ids ),
		'with'     => 0,
		'missing'  => 0,
		'invalid'  => 0,
		'verified' => 0,
		'ids'      => array_map( 'intval', $ids ),
	);

	foreach ( $ids as $id ) {
		$state = Conexao_Data_Model_Meta::coordinate_status( (int) $id );
		switch ( $state['status'] ) {
			case 'verified':
				$counts['verified']++;
				// Fall through: a verified coordinate is also a stored one.
			case 'unverified':
				$counts['with']++;
				break;
			case 'invalid':
				$counts['invalid']++;
				break;
			default:
				$counts['missing']++;
		}
	}

	return $counts;
}

/**
 * Print the coordinate inventory (read-only).
 *
 * @param array $ctx Boot context.
 * @return int Exit code.
 */
function conexao_leisure_coord_report( array $ctx ) {
	$inv = conexao_leisure_coord_inventory();

	$summary = array(
		'published_leisure'   => $inv['total'],
		'with_coordinates'    => $inv['with'],
		'verified_flag_set'   => $inv['verified'],
		'missing_coordinates' => $inv['missing'],
		'invalid_coordinates' => $inv['invalid'],
		'errors'              => 0,
	);

	return conexao_script_summary( $ctx, $summary, array( 'inventory' => $inv ) );
}

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

$manifest_path = isset( $ctx['extra']['manifest'] ) ? (string) $ctx['extra']['manifest'] : '';
// The bootstrap records a valueless extra flag in `tokens` only, so the
// read-only `--verify` switch is read from there.
$verify_only = in_array( '--verify', $ctx['tokens'], true );

if ( $verify_only ) {
	exit( conexao_leisure_coord_report( $ctx ) );
}

if ( '' === $manifest_path ) {
	conexao_script_fail( 'no manifest supplied. Pass --manifest=<path> (see --help).', 2 );
}

$loaded = conexao_leisure_coord_load_manifest( $manifest_path );
if ( is_wp_error( $loaded ) ) {
	conexao_script_fail( $loaded->get_error_message(), 2 );
}

$dry_run = ( 'apply' !== $ctx['mode'] );

$before = conexao_leisure_coord_inventory();

$counts = array(
	'entries'      => 0,
	'populate'     => 0,
	'preserve'     => 0,
	'review'       => 0,
	'planned'      => 0,
	'written'      => 0,
	'unchanged'    => 0,
	'conflict'     => 0,
	'invalid'      => 0,
	'unresolved'   => 0,
	'owner_approved' => 0,
	'provenance_repaired' => 0,
	'provenance_planned' => 0,
	'errors'       => 0,
);
$strategies = array( 'stable-id' => 0, 'stable-id+slug' => 0, 'stable-id+lang' => 0, 'slug' => 0, 'title' => 0 );
$log        = array();

foreach ( $loaded['entries'] as $entry ) {
	$counts['entries']++;

	$action     = strtoupper( (string) ( $entry['action'] ?? '' ) );
	$confidence = strtoupper( (string) ( $entry['confidence'] ?? '' ) );
	$label      = (string) ( $entry['slug'] ?? ( $entry['title'] ?? '?' ) );

	// The one and only exception to the HIGH-only rule: an entry the owner has
	// explicitly approved by hand (`owner_approved => true`) may be written at
	// MEDIUM, because that is a human decision about a named access point, not
	// an automatic promotion of an unverified coordinate. Every such write is
	// counted and printed separately so it can never pass unnoticed.
	$owner_approved = ! empty( $entry['owner_approved'] );

	if ( 'POPULATE' !== $action || ( 'HIGH' !== $confidence && ! $owner_approved ) ) {
		if ( 'PRESERVE' === $action ) {
			$counts['preserve']++;
		} else {
			$counts['review']++;
		}
		continue;
	}

	if ( 'HIGH' !== $confidence ) {
		$counts['owner_approved']++;
	}

	$counts['populate']++;

	// The coordinate values are validated by the data model's own sanitizer —
	// the single source of truth for every write path.
	$lat = Conexao_Data_Model_Meta::sanitize_latitude( $entry['proposed_latitude'] ?? '' );
	$lng = Conexao_Data_Model_Meta::sanitize_longitude( $entry['proposed_longitude'] ?? '' );

	if ( '' === $lat || '' === $lng ) {
		$counts['invalid']++;
		$counts['errors']++;
		$log[] = sprintf( '%s: INVALID proposed pair (%s, %s)', $label, $entry['proposed_latitude'] ?? '', $entry['proposed_longitude'] ?? '' );
		continue;
	}

	$resolved = conexao_leisure_coord_resolve( $entry );
	if ( is_wp_error( $resolved ) ) {
		$counts['unresolved']++;
		$counts['errors']++;
		$log[] = sprintf( '%s: UNRESOLVED [%s] — %s', $label, $resolved->get_error_code(), $resolved->get_error_message() );
		continue;
	}

	$strategies[ $resolved['strategy'] ]++;
	$post_id  = $resolved['id'];
	$existing = Conexao_Data_Model_Meta::coordinate_status( $post_id );

	if ( 'invalid' === $existing['status'] ) {
		$counts['conflict']++;
		$log[] = sprintf( '#%d %s: existing value is INVALID (%s, %s) — flagged, not overwritten', $post_id, $label, $existing['lat'], $existing['lng'] );
		continue;
	}

	if ( 'missing' !== $existing['status'] ) {
		if ( $existing['lat'] === $lat && $existing['lng'] === $lng ) {
			// Idempotence: an identical value is already stored. The provenance
			// string is still reconciled, so a coordinate written before the
			// source-text rule was corrected converges on the next run instead
			// of keeping a source that contradicts the stored point.
			$expected_source = conexao_leisure_coord_source_text( $entry );
			$current_source  = (string) get_post_meta( $post_id, Conexao_Data_Model_Meta::LEISURE_COORD_SOURCE_KEY, true );

			if ( $current_source === $expected_source ) {
				$counts['unchanged']++;
				continue;
			}

			if ( $dry_run ) {
				$counts['provenance_planned']++;
				$log[] = sprintf( '#%d %s: WOULD REPAIR provenance text', $post_id, $label );
				continue;
			}

			update_post_meta( $post_id, Conexao_Data_Model_Meta::LEISURE_COORD_SOURCE_KEY, $expected_source );
			$counts['provenance_repaired']++;
			$log[] = sprintf( '#%d %s: provenance text repaired', $post_id, $label );
			continue;
		}
		$counts['conflict']++;
		$log[] = sprintf( '#%d %s: existing (%s, %s) differs from proposed (%s, %s) — PRESERVED', $post_id, $label, $existing['lat'], $existing['lng'], $lat, $lng );
		continue;
	}

	$source = conexao_leisure_coord_source_text( $entry );

	if ( $dry_run ) {
		$counts['planned']++;
		$log[] = sprintf( '#%d %s: WOULD SET (%s, %s) [%s]', $post_id, $label, $lat, $lng, $resolved['strategy'] );
		continue;
	}

	update_post_meta( $post_id, Conexao_Data_Model_Meta::LEISURE_LAT_KEY, $lat );
	update_post_meta( $post_id, Conexao_Data_Model_Meta::LEISURE_LNG_KEY, $lng );
	update_post_meta( $post_id, Conexao_Data_Model_Meta::LEISURE_COORD_SOURCE_KEY, $source );

	$after_state = Conexao_Data_Model_Meta::coordinate_status( $post_id );
	if ( 'missing' === $after_state['status'] || 'invalid' === $after_state['status'] ) {
		$counts['errors']++;
		$log[] = sprintf( '#%d %s: WRITE DID NOT LAND', $post_id, $label );
		continue;
	}

	$counts['written']++;
	$log[] = sprintf( '#%d %s: SET (%s, %s)', $post_id, $label, $after_state['lat'], $after_state['lng'] );
}

$after = conexao_leisure_coord_inventory();

// The numeric gate: every planned write must have landed, and the count of
// attractions carrying coordinates must have grown by exactly that many.
$expected_with = $before['with'] + ( $dry_run ? 0 : $counts['written'] );
$gate          = $after['with'] - $expected_with;

if ( $dry_run ) {
	foreach ( $log as $line ) {
		fwrite( STDOUT, '  ' . $line . "\n" );
	}
	fwrite( STDOUT, "  (dry run — nothing written)\n" );
}

$summary = array(
	'manifest_entries'  => $counts['entries'],
	'populate_entries'  => $counts['populate'],
	'preserve_entries'  => $counts['preserve'],
	'review_entries'    => $counts['review'],
	'planned'           => $counts['planned'],
	'written'           => $counts['written'],
	'unchanged'         => $counts['unchanged'],
	'conflicts'         => $counts['conflict'],
	'invalid'           => $counts['invalid'],
	'unresolved'        => $counts['unresolved'],
	'owner_approved'    => $counts['owner_approved'],
	'provenance_repaired' => $counts['provenance_repaired'],
	'provenance_planned'  => $counts['provenance_planned'],
	'match_stable_id'   => $strategies['stable-id'],
	'match_stable_id_slug' => $strategies['stable-id+slug'],
	'match_stable_id_lang' => $strategies['stable-id+lang'],
	'match_slug'        => $strategies['slug'],
	'match_title'       => $strategies['title'],
	'with_before'       => $before['with'],
	'with_after'        => $after['with'],
	'missing_after'     => $after['missing'],
	'gate'              => $gate,
	'errors'            => $counts['errors'] + ( 0 === $gate ? 0 : 1 ),
);

exit(
	conexao_script_summary(
		$ctx,
		$summary,
		array( 'plan' => $log )
	)
);
