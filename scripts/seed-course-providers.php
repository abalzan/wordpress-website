<?php
/**
 * Seeder for the curated Irish course-provider directory (Cursos).
 *
 * Creates or updates one `course_provider` record per trusted provider. The
 * Cursos page is intentionally a curated directory — we do NOT import
 * individual courses here.
 *
 * Usage:  php scripts/seed-course-providers.php
 * Output: a short report of created/updated records and logo status.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

if ( ! post_type_exists( 'course_provider' ) ) {
	fwrite( STDERR, "Erro: o post type 'course_provider' não está registrado. Ative o plugin conexao-data-model.\n" );
	exit( 1 );
}

/**
 * Official logo source URLs.
 *
 * Only URLs hosted on each provider's own official domain are used, so the
 * logo is always attributable to the institution. If a download fails or the
 * URL changes, the seeder falls back to the theme placeholder (no misleading
 * logo is ever attached).
 *
 * @return array<string,string> Provider slug => logo URL.
 */
function conexao_provider_logo_sources() {
	return array(
		'fetch-courses'    => 'https://www.fetchcourses.ie/',
		'qualifax'         => 'https://www.qualifax.ie/',
		'springboard-plus' => 'https://springboardcourses.ie/',
		'skillnet-ireland' => 'https://www.skillnetireland.ie/',
		'solas-ecollege'   => 'https://www.ecollege.ie/',
		'local-enterprise-office' => 'https://www.localenterprise.ie/',
		'courses-ie'       => 'https://www.courses.ie/',
		'etbi'             => 'https://www.etbi.ie/',
	);
}

/**
 * Try to find an official logo image URL from a provider's own homepage.
 *
 * Because we cannot hard-code a guaranteed logo path, we opportunistically
 * parse the official homepage for an apple-touch-icon / og:image / favicon,
 * which are the most stable, institution-owned assets. Returns '' when no
 * image could be found so the caller uses the placeholder fallback.
 *
 * @param string $homepage Provider homepage URL.
 * @return string Absolute image URL, or ''.
 */
function conexao_provider_logo_from_homepage( $homepage ) {
	$response = wp_remote_get( $homepage, array( 'timeout' => 15, 'redirection' => 5, 'sslverify' => false ) );
	if ( is_wp_error( $response ) ) {
		return '';
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return '';
	}

	$html = wp_remote_retrieve_body( $response );

	// apple-touch-icon (highest-quality, institution-owned wordmark).
	if ( preg_match( '#<link[^>]+rel=["\'](?:[^"\']*\s)?apple-touch-icon(?:-precomposed)?(?:\s[^"\']*)?["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m ) ) {
		return strtok( $m[1], '?' );
	}

	// og:image (official social/brand image).
	if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m ) ) {
		return strtok( $m[1], '?' );
	}
	if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']/i', $html, $m ) ) {
		return strtok( $m[1], '?' );
	}

	// NOTE: plain favicons are intentionally NOT used. A favicon is often a
	// small brand mark rather than the full wordmark logo, so attaching it
	// risks rendering an inconsistent, non-wordmark "logo". Per the project
	// rules we fall back to the theme placeholder instead of a misleading
	// image.

	return '';
}

/**
 * Sideload an image URL into the WordPress media library.
 *
 * @param string $url          Image URL.
 * @param string $provider_name Provider name for the attachment title.
 * @return int Attachment ID, or 0 on failure.
 */
function conexao_sideload_logo( $url, $provider_name ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = media_sideload_image( $url, 0, 'Logo oficial de ' . $provider_name, 'id' );
	if ( is_wp_error( $attachment_id ) ) {
		return 0;
	}

	return (int) $attachment_id;
}

$providers = array(
	array(
		'slug'        => 'fetch-courses',
		'name'        => 'FETCH Courses',
		'description' => 'Encontre cursos de formação e educação continuada em toda a Irlanda, incluindo aprendizagens, traineeships, cursos PLC, educação de adultos e outras oportunidades de FET.',
		'category'    => 'Educação',
		'location'    => 'Irlanda',
		'url'         => 'https://www.fetchcourses.ie/',
		'order'       => 1,
	),
	array(
		'slug'        => 'qualifax',
		'name'        => 'Qualifax',
		'description' => 'A base de dados nacional da Irlanda para cursos e orientação ao estudante, ajudando estudantes, candidatos a emprego e adultos a explorar opções de educação e formação.',
		'category'    => 'Educação',
		'location'    => 'Irlanda',
		'url'         => 'https://www.qualifax.ie/',
		'order'       => 2,
	),
	array(
		'slug'        => 'springboard-plus',
		'name'        => 'Springboard+',
		'description' => 'Descubra cursos superiores subsidiados criados para ajudar as pessoas a requalificar, aprimorar competências e desenvolver novas oportunidades de carreira.',
		'category'    => 'Formação Profissional',
		'location'    => 'Irlanda',
		'url'         => 'https://springboardcourses.ie/',
		'order'       => 3,
	),
	array(
		'slug'        => 'skillnet-ireland',
		'name'        => 'Skillnet Ireland',
		'description' => 'Descubra oportunidades de formação e requalificação desenhadas em torno das necessidades de empresas, colaboradores e setores da indústria em toda a Irlanda.',
		'category'    => 'Formação Profissional',
		'location'    => 'Irlanda',
		'url'         => 'https://www.skillnetireland.ie/',
		'order'       => 4,
	),
	array(
		'slug'        => 'solas-ecollege',
		'name'        => 'SOLAS eCollege',
		'description' => 'Aprendizagem online e desenvolvimento de competências da SOLAS, incluindo competências digitais, TI, gestão de projetos, negócios e outras áreas.',
		'category'    => 'Cursos Online',
		'location'    => 'Online / Irlanda',
		'url'         => 'https://www.ecollege.ie/',
		'order'       => 5,
	),
	array(
		'slug'        => 'local-enterprise-office',
		'name'        => 'Local Enterprise Office',
		'description' => 'Programas de formação e cursos de negócios para quem está a iniciar, desenvolver ou fazer crescer um negócio na Irlanda.',
		'category'    => 'Negócios',
		'location'    => 'Irlanda',
		'url'         => 'https://www.localenterprise.ie/',
		'order'       => 6,
	),
	array(
		'slug'        => 'courses-ie',
		'name'        => 'Courses.ie',
		'description' => 'Explore milhares de cursos de faculdades, universidades, fornecedores de formação e educadores independentes em toda a Irlanda.',
		'category'    => 'Diretórios de Cursos',
		'location'    => 'Irlanda',
		'url'         => 'https://www.courses.ie/',
		'order'       => 7,
	),
	array(
		'slug'        => 'etbi',
		'name'        => 'Education and Training Boards Ireland',
		'description' => 'Encontre oportunidades de educação e formação oferecidas através da rede de Education and Training Boards na Irlanda.',
		'category'    => 'Educação',
		'location'    => 'Irlanda',
		'url'         => 'https://www.etbi.ie/',
		'order'       => 8,
	),
);

$logo_sources = conexao_provider_logo_sources();
$created = 0;
$updated = 0;
$total   = count( $providers );

echo "=== Seed Providers (Cursos) ===\n";

foreach ( $providers as $provider ) {
	$existing = get_page_by_path( $provider['slug'], OBJECT, 'course_provider' );

	$args = array(
		'post_type'    => 'course_provider',
		'post_status'  => 'publish',
		'post_title'   => $provider['name'],
		'post_name'    => $provider['slug'],
		'post_excerpt' => $provider['description'],
	);

	if ( $existing ) {
		$args['ID'] = $existing->ID;
		$post_id    = wp_update_post( $args );
		$updated++;
	} else {
		$post_id = wp_insert_post( $args );
		$created++;
	}

	if ( is_wp_error( $post_id ) ) {
		echo "  ERRO ao salvar {$provider['name']}: {$post_id->get_error_message()}\n";
		continue;
	}

	update_post_meta( $post_id, '_provider_category', $provider['category'] );
	update_post_meta( $post_id, '_provider_location', $provider['location'] );
	update_post_meta( $post_id, '_provider_url', $provider['url'] );
	update_post_meta( $post_id, '_provider_order', $provider['order'] );
	update_post_meta( $post_id, '_provider_status', 'published' );

	// Best-effort official logo (only from the provider's own domain).
	$logo_id = (int) get_post_meta( $post_id, '_provider_logo', true );
	$has_valid_logo = $logo_id && wp_attachment_is_image( $logo_id );

	if ( ! $has_valid_logo && isset( $logo_sources[ $provider['slug'] ] ) ) {
		$homepage = $logo_sources[ $provider['slug'] ];
		$logo_url = conexao_provider_logo_from_homepage( $homepage );
		if ( $logo_url ) {
			// Resolve relative URLs against the homepage.
			if ( 0 === strpos( $logo_url, '/' ) ) {
				$parts   = wp_parse_url( $homepage );
				$base    = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( $parts['host'] ?? '' );
				$logo_url = $base . $logo_url;
			}
			$new_id = conexao_sideload_logo( $logo_url, $provider['name'] );
			if ( $new_id ) {
				update_post_meta( $post_id, '_provider_logo', $new_id );
				set_post_thumbnail( $post_id, $new_id );
				$logo_id = $new_id;
			}
		}
	}

	$logo_label = ( $logo_id && wp_attachment_is_image( $logo_id ) ) ? "ID={$logo_id}" : 'placeholder (sem logo oficial)';
	echo "  - {$provider['name']} [{$provider['category']} | {$provider['location']}] logo: {$logo_label}\n";
}

// Ensure the Cursos page uses the provider-directory shortcode. We replace the
// content whenever it does not already contain the new shortcode (covers empty
// pages and pages still using the legacy curated_link grid).
$cursos_new_content = '<!-- wp:heading --><h2>Encontre cursos, formação e oportunidades de aprendizagem na Irlanda</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Uma diretoria curada de instituições e plataformas de ensino confiáveis na Irlanda. Cada cartão leva você diretamente ao site oficial do provedor.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[conexao_course_providers]<!-- /wp:shortcode -->';

$cursos_page = get_page_by_path( 'courses' );
if ( $cursos_page ) {
	$content = $cursos_page->post_content;
	if ( false === strpos( $content, 'conexao_course_providers' ) ) {
		wp_update_post( array(
			'ID'           => $cursos_page->ID,
			'post_content' => $cursos_new_content,
		) );
		echo "\nCursos page atualizada para a diretoria de provedores.\n";
	}
}

echo "\n=== Resumo ===\n";
echo "Total:      {$total}\n";
echo "Criados:    {$created}\n";
echo "Atualizados: {$updated}\n";

// Ensure rewrite rules know about the new CPT.
flush_rewrite_rules();