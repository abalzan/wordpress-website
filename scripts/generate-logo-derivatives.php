<?php
/**
 * Generate small responsive logo derivatives for the site header.
 *
 * Usage: docker compose exec wordpress php wp-cli.phar eval-file scripts/generate-logo-derivatives.php --allow-root
 *
 * The header logo renders at ~72px (desktop) / ~96px (mobile) CSS width, so
 * the 480px WebP / 1600px JPEG masters are far larger than needed on mobile.
 * This script creates 240px-wide WebP + JPEG derivatives of the light and
 * dark logo theme assets (quality 85) alongside the originals. The originals
 * are never modified. Run again after replacing either logo master.
 *
 * @package Conexao_BR_Irlanda
  *
 * Bootstrap exception: runs only through WP-CLI (wp eval-file), which has
 * already loaded WordPress, so scripts/lib/bootstrap.php is deliberately not
 * required. Current, not historical.
*/

$theme_images = get_template_directory() . '/assets/images';

$jobs = array(
	// [ source, destination suffix, target width ]
	array( $theme_images . '/logo_conexao_br_irlanda.webp', 'logo_conexao_br_irlanda-240.webp', 240 ),
	array( $theme_images . '/logo_conexao_br_irlanda.jpeg', 'logo_conexao_br_irlanda-240.jpeg', 240 ),
	array( $theme_images . '/logo_dark_mode.webp', 'logo_dark_mode-240.webp', 240 ),
	array( $theme_images . '/logo_dark_mode.jpeg', 'logo_dark_mode-240.jpeg', 240 ),
);

foreach ( $jobs as list( $src, $dst_name, $target_w ) ) {
	$dst = $theme_images . '/' . $dst_name;

	if ( ! file_exists( $src ) ) {
		WP_CLI::warning( "Missing source: $src" );
		continue;
	}

	$info = getimagesize( $src );
	if ( ! $info ) {
		WP_CLI::warning( "Unreadable image: $src" );
		continue;
	}

	list( $w, $h ) = $info;
	if ( $w <= $target_w ) {
		WP_CLI::log( "Skip (source smaller than target): $dst_name" );
		continue;
	}

	$new_h = (int) round( $h * $target_w / $w );

	switch ( $info['mime'] ) {
		case 'image/webp':
			$img = imagecreatefromwebp( $src );
			break;
		case 'image/jpeg':
			$img = imagecreatefromjpeg( $src );
			break;
		default:
			WP_CLI::warning( "Unsupported type for $src" );
			continue 2;
	}

	if ( ! $img ) {
		WP_CLI::warning( "Decode failed: $src" );
		continue;
	}

	// Preserve alpha (dark logo may have transparency).
	imagealphablending( $img, true );

	$out = imagecreatetruecolor( $target_w, $new_h );
	if ( 'image/webp' === $info['mime'] ) {
		imagealphablending( $out, false );
		imagesavealpha( $out, true );
		imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
		imagealphablending( $out, true );
	} else {
		// Flatten onto white for the JPEG fallback.
		imagefill( $out, 0, 0, imagecolorallocate( $out, 255, 255, 255 ) );
	}
	imagecopyresampled( $out, $img, 0, 0, 0, 0, $target_w, $new_h, $w, $h );

	$ok = ( 'image/webp' === $info['mime'] )
		? imagewebp( $out, $dst, 85 )
		: imagejpeg( $out, $dst, 85 );

	imagedestroy( $img );
	imagedestroy( $out );

	WP_CLI::log( $ok ? "Wrote $dst ($target_w x $new_h)" : "FAILED: $dst" );
}
