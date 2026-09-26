<?php
/**
 * Debug script: check and fix Heritage Week event images.
 * Run inside the WordPress container: php scripts/debug-heritage-images.php
 */

require_once __DIR__ . '/../lib/bootstrap.php';
conexao_script_load_wordpress();

echo "=== Heritage Week Event Image Audit ===\n\n";

$query = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => 50,
		'post_status'    => 'any',
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => 'heritage_week',
			),
		),
	)
);

echo 'Total Heritage Week events: ' . $query->found_posts . "\n\n";

$has_attachment    = 0;
$has_external_only = 0;
$has_no_image      = 0;
$needs_download    = 0;

while ( $query->have_posts() ) {
	$query->the_post();
	$id             = get_the_ID();
	$title          = get_the_title();
	$banner         = get_post_meta( $id, '_event_banner', true );
	$attach_id      = get_post_meta( $id, '_event_banner_attachment_id', true );
	$thumbnail_id   = get_post_thumbnail_id( $id );
	$status         = get_post_meta( $id, '_event_status', true );

	$short_banner = $banner ? substr( $banner, 0, 80 ) : '(empty)';

	echo "- {$title}\n";
	echo "  Status: {$status}\n";
	echo "  Banner URL: {$short_banner}\n";

	if ( $attach_id && wp_attachment_is_image( $attach_id ) ) {
		$attach_url = wp_get_attachment_url( $attach_id );
		$attach_meta = wp_get_attachment_metadata( $attach_id );
		$width  = isset( $attach_meta['width'] ) ? $attach_meta['width'] : '?';
		$height = isset( $attach_meta['height'] ) ? $attach_meta['height'] : '?';
		echo "  Attachment ID: {$attach_id} ({$width}x{$height})\n";
		echo "  Attachment URL: " . substr( $attach_url, 0, 80 ) . "\n";

		// Check available sizes.
		$sizes = get_intermediate_image_sizes();
		echo "  Available sizes: ";
		foreach ( $sizes as $size ) {
			$img = wp_get_attachment_image_src( $attach_id, $size );
			if ( $img ) {
				echo "{$size}({$img[1]}x{$img[2]}) ";
			}
		}
		echo "\n";
		$has_attachment++;
	} elseif ( $thumbnail_id && wp_attachment_is_image( $thumbnail_id ) ) {
		echo "  Thumbnail ID (fallback): {$thumbnail_id}\n";
		$has_attachment++;
	} elseif ( $banner ) {
		echo "  ** EXTERNAL URL ONLY - needs download **\n";
		$has_external_only++;
		$needs_download++;
	} else {
		echo "  ** NO IMAGE **\n";
		$has_no_image++;
	}

	// Check for /logos/ in banner (bad).
	if ( $banner && strpos( $banner, '/logos/' ) !== false ) {
		echo "  ** WARNING: Banner URL contains /logos/ (likely Heritage Week logo, not event image) **\n";
	}

	echo "\n";
}

wp_reset_postdata();

echo "=== Summary ===\n";
echo "Events with attachment: {$has_attachment}\n";
echo "Events with external URL only: {$has_external_only}\n";
echo "Events with no image: {$has_no_image}\n";
echo "Events needing image download: {$needs_download}\n";

if ( $needs_download > 0 ) {
	echo "\nRun the event import to download missing images:\n";
	echo "  php scripts/run-event-import.php\n";
}

echo "\n=== Check for /logos/ images (bad) ===\n";
$bad = new WP_Query(
	array(
		'post_type'      => 'event',
		'posts_per_page' => 10,
		'post_status'    => 'any',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => '_event_source',
				'value' => 'heritage_week',
			),
			array(
				'key'     => '_event_banner',
				'value'   => '/logos/',
				'compare' => 'LIKE',
			),
		),
	)
);
echo 'Events still using /logos/: ' . $bad->found_posts . "\n";
wp_reset_postdata();