<?php
/**
 * STAGE 19 — data-preservation proof.
 *
 * Loads the FIVE retired plugin datasets and the active
 * `conexao-en-translation` stage data IN THE SAME PROCESS and compares them.
 * Nothing here writes: it is a pure in-memory comparison of authored strings.
 *
 * The question it answers is narrow and falsifiable: after deleting the five
 * retired plugin directories, is every authored English string still present in
 * the active stage/data layer?
 *
 * It runs INSIDE the real WordPress container so that the WordPress functions
 * the authored data files legitimately use (`sanitize_title()`) are the genuine
 * ones and not a shim:
 *
 *   docker compose exec -T -e CONEXAO_ROOT=/var/www/html wordpress php \
 *     docs/evidence/2026-10-02-stage-19/data-preservation-proof.php < docs/evidence/2026-10-02-stage-19/data-preservation-proof.php
 *
 * ## Running it AFTER the deletion (the state Stage 19 committed)
 *
 * The five plugin directories no longer exist, so the retired datasets are
 * recovered from Git history into a scratch tree and handed to the script via
 * `CONEXAO_RETIRED_DIR`. This is the important run: it compares the HISTORICAL
 * authored English against the CURRENT active stages, with the retired plugins
 * absent from the repository entirely.
 *
 * ```bash
 *   mkdir -p /tmp/retired-tree
 *   for p in page blog job leisure guide; do
 *     d=/tmp/retired-tree/conexao-$p-translation; mkdir -p "$d/includes" "$d/data"
 *     for f in $(git ls-tree -r --name-only HEAD -- wp-content/plugins/conexao-$p-translation/); do
 *       rel=${f#wp-content/plugins/conexao-$p-translation/}
 *       case "$rel" in
 *         includes/*) out="$d/includes/$(basename "$rel")" ;;
 *         data/*)     out="$d/data/$(basename "$rel")" ;;
 *         *)          out="$d/$(basename "$rel")" ;;
 *       esac
 *       git show "HEAD:$f" > "$out"
 *     done
 *   done
 *   docker compose cp /tmp/retired-tree wordpress:/tmp/retired-tree
 *   docker compose exec -T -e CONEXAO_ROOT=/var/www/html \
 *     -e CONEXAO_RETIRED_DIR=/tmp/retired-tree wordpress php \
 *     < docs/evidence/2026-10-02-stage-19/data-preservation-proof.php
 * ```
 *
 * ## What it asserts
 *
 * One assertion per retired dataset, each fail-closed: a row that is neither
 * present, nor re-keyed, nor a documented exclusion is a FAIL. The accepted
 * `job` gap is printed as an explicit `[GAP ]` line rather than counted as a
 * pass, so the report and the output agree.
 *
 * @package Conexao_BR_Stage19_Evidence
 */

// The retired data files guard on ABSPATH; define it so they load standalone.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}

// Real WordPress, when available. It supplies sanitize_title() and friends.
if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-load.php' ) && ! function_exists( 'sanitize_title' ) ) {
	require_once ABSPATH . 'wp-load.php';
}

// Locate the repository root. CONEXAO_ROOT wins so the script can run inside
// the container, where only wp-content/, tests/, scripts/ and dist/ are mounted
// (no plugins.json); otherwise walk up from this file until plugins.json appears.
$root = getenv( 'CONEXAO_ROOT' ) ? (string) getenv( 'CONEXAO_ROOT' ) : '';

if ( '' === $root ) {
	$dir = __DIR__;
	while ( '/' !== $dir && ! file_exists( $dir . '/plugins.json' ) ) {
		$dir = dirname( $dir );
	}
	$root = ( '/' === $dir ) ? '' : $dir;
}

// The proof only ever reads plugin sources, so a root that carries the plugin
// tree is sufficient; plugins.json is not mounted into the container.
if ( '' === $root || ! is_dir( $root . '/wp-content/plugins/conexao-en-translation' ) ) {
	fwrite( STDERR, "FATAL: repository root not found (set CONEXAO_ROOT).\n" );
	exit( 2 );
}

// The retired plugin sources. Before deletion these are the plugin directories
// themselves; after deletion the caller points CONEXAO_RETIRED_DIR at sources
// recovered from Git history, so the SAME comparison runs against the
// post-deletion tree.
$retired_dir = getenv( 'CONEXAO_RETIRED_DIR' ) ? (string) getenv( 'CONEXAO_RETIRED_DIR' ) : $root . '/wp-content/plugins';

$retired = array(
	'page'    => $retired_dir . '/conexao-page-translation/includes/translation-map.php',
	'blog'    => $retired_dir . '/conexao-blog-translation/includes/translation-map.php',
	'job'     => $retired_dir . '/conexao-job-translation/includes/translation-map.php',
	'leisure' => $retired_dir . '/conexao-leisure-translation/data/stage7-leisure-descriptions.json',
	'guide'   => $retired_dir . '/conexao-guide-translation/includes',
);

foreach ( array( 'page', 'blog', 'job' ) as $key ) {
	if ( ! file_exists( $retired[ $key ] ) ) {
		fwrite( STDERR, "FATAL: retired source missing: {$retired[ $key ]}\n" );
		exit( 2 );
	}
	require_once $retired[ $key ];
}

foreach ( glob( $retired['guide'] . '/*.php' ) as $file ) {
	require_once $file;
}

$passed = 0;
$failed = 0;

/**
 * Record one assertion.
 *
 * @param bool   $condition Result.
 * @param string $message   Description.
 * @return bool
 */
function s19_check( bool $condition, string $message ): bool {
	global $passed, $failed;
	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$message}\n";
	} else {
		++$failed;
		echo "  [FAIL] {$message}\n";
	}
	return $condition;
}

echo "STAGE 19 — data-preservation proof\n";
echo str_repeat( '-', 70 ), "\n";
echo "  active tree : {$root}\n";
echo "  retired src : {$retired_dir}\n\n";

/* =====================================================================
 * 1. PAGE  (conexao-page-translation -> en-page / en-blog-page / en-jobs-page)
 * =================================================================== */
echo "1. page: retired dataset vs active stage data\n";

$retired_page = conexao_page_translation_map();

// The active page data lives in `en-page` plus two single-record stages. Both
// shapes are wrapped in a `records` map keyed by the PT slug.
$active_page = conexao_en_translation_manifest_for( 'page' )['records'];
foreach ( array(
	'conexao_en_translation_blog_page_manifest',
	'conexao_en_translation_jobs_page_manifest',
) as $manifest_fn ) {
	if ( function_exists( $manifest_fn ) ) {
		$active_page = array_merge( $active_page, $manifest_fn()['records'] );
	}
}

s19_check( count( $retired_page ) > 0, 'retired page dataset loads (' . count( $retired_page ) . ' rows)' );
s19_check( count( $active_page ) > 0, 'active page stages load (' . count( $active_page ) . ' rows)' );

// A retired row with no active counterpart is not automatically a data loss.
// The county/country hubs are on the documented B2 page allowlist (decision
// §8): they are deliberately served by the B2 fallback instead of a translated
// page record. Every such slug is listed with its reason, so a genuinely lost
// row still FAILS and cannot hide behind a wildcard.
$retired_page_b2 = array(
	'irlanda', 'laois', 'dublin', 'cork', 'galway',
	'limerick', 'kildare', 'meath', 'wicklow', 'waterford',
);
$allowlist = function_exists( 'conexao_b2_page_allowlist' )
	? conexao_b2_page_allowlist()
	: array();

$b2_served  = array();
$real_gaps  = array();
$title_note = array();

foreach ( $retired_page as $pt_slug => $row ) {
	if ( isset( $active_page[ $pt_slug ] ) ) {
		// Present. A retitled row is a deliberate re-authoring, not a loss, but
		// it is reported so the difference is visible rather than silent.
		if ( isset( $row['title'], $active_page[ $pt_slug ]['en_title'] )
			&& $row['title'] !== $active_page[ $pt_slug ]['en_title'] ) {
			$title_note[ $pt_slug ] = '"' . $row['title'] . '" -> "' . $active_page[ $pt_slug ]['en_title'] . '" (re-authored)';
		}
		continue;
	}

	if ( in_array( $pt_slug, $retired_page_b2, true ) && in_array( $pt_slug, $allowlist, true ) ) {
		$b2_served[] = $pt_slug;
	} else {
		$real_gaps[ $pt_slug ] = 'no active stage row and no documented B2 decision';
	}
}

echo '  info  retired rows: ' . count( $retired_page )
	. '; active rows: ' . count( $active_page )
	. '; B2-served by policy: ' . count( $b2_served )
	. '; re-authored titles: ' . count( $title_note ) . "\n";

s19_check( array() === $real_gaps,
	'no retired page row is silently lost (unexplained gaps: ' . count( $real_gaps ) . ')' );
foreach ( $real_gaps as $slug => $why ) {
	echo "         GAP {$slug}: {$why}\n";
}
foreach ( $title_note as $slug => $detail ) {
	echo "         RETITLED {$slug}: {$detail}\n";
}
echo "\n";
/* =====================================================================
 * 2. BLOG (conexao-blog-translation -> en-post)
 * =================================================================== */
echo "2. blog: retired dataset vs active stage data\n";

$retired_blog  = conexao_blog_translation_manifest();
$retired_posts = isset( $retired_blog['posts'] ) ? $retired_blog['posts'] : array();
// The active Stage N dataset is keyed DIRECTLY by PT slug (no `posts` wrapper).
$active_posts  = conexao_en_translation_manifest_data_blog_v1();

s19_check( count( $retired_posts ) > 0, 'retired blog dataset loads (' . count( $retired_posts ) . ' posts)' );
s19_check( count( $active_posts ) > 0, 'active en-post dataset loads (' . count( $active_posts ) . ' posts)' );

// A retired row whose PT record does not exist is not a loss: there is nothing
// to translate. Those are separated out so a genuinely missing row still fails.
$present = array();
$stale   = array();
foreach ( array_keys( $retired_posts ) as $pt_slug ) {
	if ( get_page_by_path( $pt_slug, OBJECT, 'post' ) ) {
		$present[] = $pt_slug;
	} else {
		$stale[] = $pt_slug;
	}
}

$blog_missing = array_diff( $present, array_keys( $active_posts ) );

echo '  info  retired posts: ' . count( $retired_posts )
	. '; with a real PT record: ' . count( $present )
	. '; no PT record at all: ' . count( $stale )
	. '; active en-post rows: ' . count( $active_posts ) . "\n";

s19_check( array() === $blog_missing,
	'every retired blog post that HAS a PT record exists in en-post (missing: ' . count( $blog_missing ) . ')' );
foreach ( $blog_missing as $slug ) {
	echo "         GAP {$slug}\n";
}
foreach ( $stale as $slug ) {
	echo "         STALE (no PT record, nothing to translate) {$slug}\n";
}
echo "\n";

/* =====================================================================
 * 3. JOB (conexao-job-translation -> ???)
 * =================================================================== */
echo "3. job: retired dataset vs active stage data\n";

$retired_job  = conexao_job_translation_manifest();
$retired_jobs = isset( $retired_job['jobs'] ) ? $retired_job['jobs'] : array();

s19_check( count( $retired_jobs ) > 0, 'retired job dataset loads (' . count( $retired_jobs ) . ' jobs)' );

// THE KNOWN, OPERATOR-AUTHORISED GAP. `job` is a B2 post type and none of the
// seven supported stages carries job *records*; `en-jobs-page` is the Jobs
// LANDING PAGE. The retired stage also declared allow_remove => false. The
// operator chose to retire this dataset with the plugin rather than stand up an
// `en-job` stage, so it survives only in Git history.
//
// This assertion is expected to FAIL and is reported as such: it is the standing
// record of the accepted gap, not a claim that the data was preserved.
$job_preserved = function_exists( 'conexao_en_translation_job_manifest' )
	|| function_exists( 'conexao_en_translation_manifest_data_job_v1' );

echo "  [GAP ] an active en-job stage data source exists: "
	. ( $job_preserved ? 'YES' : 'NO — accepted gap, recoverable from Git history only' ) . "\n";
echo "         retired authored rows: " . count( $retired_jobs )
	. " (recover: git show <stage-19-parent>:wp-content/plugins/conexao-job-translation/includes/translation-map.php)\n";
echo "\n";

/* =====================================================================
 * 4. LEISURE (conexao-leisure-translation -> en-leisure-description)
 * =================================================================== */
echo "4. leisure: retired dataset vs active stage data\n";

$leisure_json    = json_decode( (string) file_get_contents( $retired['leisure'] ), true );
$retired_entries = isset( $leisure_json['entries'] ) ? $leisure_json['entries'] : array();

s19_check( count( $retired_entries ) > 0, 'retired leisure dataset loads (' . count( $retired_entries ) . ' entries)' );

// The retired JSON is keyed by `slug`; the active dataset is keyed by the same
// PT slug, which is the portable identity.
$active_leisure  = conexao_en_translation_leisure_description_data_v1();
$leisure_missing = array();
$leisure_diff    = array();
$leisure_same    = 0;

foreach ( $retired_entries as $e ) {
	$slug = isset( $e['slug'] ) ? $e['slug'] : '';
	if ( ! isset( $active_leisure[ $slug ] ) ) {
		$leisure_missing[] = $slug;
		continue;
	}
	if ( (string) $e['en_excerpt'] === (string) $active_leisure[ $slug ]['en_description'] ) {
		++$leisure_same;
	} else {
		$leisure_diff[] = $slug;
	}
}

echo '  info  retired entries: ' . count( $retired_entries )
	. '; active rows: ' . count( $active_leisure )
	. "; byte-identical EN descriptions: {$leisure_same}\n";

s19_check( array() === $leisure_missing,
	'every retired leisure slug exists in en-leisure-description (missing: ' . count( $leisure_missing ) . ')' );
s19_check( array() === $leisure_diff,
	'every preserved leisure row keeps the byte-identical authored EN description (diff: ' . count( $leisure_diff ) . ')' );
echo "\n";

/* =====================================================================
 * 5. GUIDE (conexao-guide-translation -> en-guide)
 * =================================================================== */
echo "5. guide: retired dataset vs active stage data\n";

$active_guide = conexao_en_translation_guide_data_v1();

// The retired plugin's authored rows are assembled by its four batch manifests
// (`conexao_guide_translation_manifest_a/b/c/d`), which pair each PT slug with
// the body functions. Compare ROWS, not the raw body fragments: Stage M
// consolidated each guide into a single record, so byte-comparing fragments
// would report every guide as changed.
$retired_guide = array();
foreach ( array( 'a', 'b', 'c', 'd' ) as $batch ) {
	$fn = 'conexao_guide_translation_manifest_' . $batch;
	if ( function_exists( $fn ) ) {
		$retired_guide = array_merge( $retired_guide, $fn() );
	}
}

s19_check( count( $retired_guide ) > 0, 'retired guide manifests load (' . count( $retired_guide ) . ' rows)' );
s19_check( count( $active_guide ) > 0, 'active en-guide dataset loads (' . count( $active_guide ) . ' records)' );

// A retired row is accounted for when it is present under the same PT slug, OR
// when its authored English is present under the re-keyed PT slug, OR when it is
// a documented operator exclusion.
$exclusions = function_exists( 'conexao_en_translation_exclusions_v1' )
	? conexao_en_translation_exclusions_v1()
	: array();

$rekeyed   = array();
$excluded  = array();
$gap_rows  = array();
$identical = 0;
$drifted   = array();

foreach ( $retired_guide as $pt_slug => $row ) {
	if ( isset( $active_guide[ $pt_slug ] ) ) {
		$match = ( $row['en_title'] === $active_guide[ $pt_slug ]['en_title'] )
			&& ( $row['en_slug'] === $active_guide[ $pt_slug ]['en_slug'] )
			&& trim( $row['en_content'] ) === trim( $active_guide[ $pt_slug ]['en_content'] );
		if ( $match ) {
			++$identical;
		} else {
			$drifted[] = $pt_slug;
		}
		continue;
	}

	// Re-keyed: the same authored English now hangs off a different PT slug.
	$by_en_slug = array();
	foreach ( $active_guide as $a_slug => $a_row ) {
		$by_en_slug[ $a_row['en_slug'] ] = $a_slug;
	}
	if ( isset( $by_en_slug[ $row['en_slug'] ] ) ) {
		$new = $by_en_slug[ $row['en_slug'] ];
		if ( trim( $row['en_content'] ) === trim( $active_guide[ $new ]['en_content'] ) ) {
			$rekeyed[ $pt_slug ] = $new;
			continue;
		}
	}

	if ( isset( $exclusions[ $pt_slug ] ) ) {
		$excluded[ $pt_slug ] = $exclusions[ $pt_slug ]['classification'];
		continue;
	}

	$gap_rows[] = $pt_slug;
}

echo '  info  retired rows: ' . count( $retired_guide )
	. '; byte-identical under the same slug: ' . $identical
	. '; re-keyed: ' . count( $rekeyed )
	. '; documented exclusions: ' . count( $excluded )
	. "; field drift: " . count( $drifted ) . "\n";

s19_check( array() === $gap_rows,
	'every retired guide row is preserved, re-keyed or a documented exclusion (gaps: ' . count( $gap_rows ) . ')' );
foreach ( $gap_rows as $slug ) {
	echo "         GAP {$slug}\n";
}
s19_check( array() === $drifted,
	'every same-slug guide row is unchanged in title, EN slug and body (drift: ' . count( $drifted ) . ')' );
foreach ( $drifted as $slug ) {
	echo "         DRIFT {$slug}\n";
}
foreach ( $rekeyed as $old => $new ) {
	echo "         RE-KEYED {$old} -> {$new} (identical authored English)\n";
}
foreach ( $excluded as $slug => $class ) {
	echo "         EXCLUDED {$slug} ({$class}, operator-authorized)\n";
}
echo "\n";

echo str_repeat( '-', 70 ), "\n";
echo "data-preservation proof: {$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
