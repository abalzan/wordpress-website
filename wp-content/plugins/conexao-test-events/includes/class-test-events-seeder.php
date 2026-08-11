<?php
/**
 * Seeds realistic dummy events with banner images, destination URLs
 * and registration information for testing the Events page.
 *
 * @package Conexao_Test_Events
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Test_Events_Seeder {

	const OPTION_KEY = 'conexao_test_events_seeded';

	/**
	 * @var array[] Each dummy event definition.
	 */
	private static $events = array(
		array(
			'title'        => 'Workshop de Tecnologia para Iniciantes',
			'slug'         => 'sample-tech-workshop',
			'date'         => '+2 weeks',
			'time'         => '10:00 — 13:00',
			'location'     => 'The Rediscovery Centre, Dublin 9',
			'excerpt'      => 'Aprenda os fundamentos de programação, noções de segurança digital e como dar os primeiros passos na área de tecnologia na Irlanda.',
			'content'      => '<h2>Sobre o workshop</h2><p>Um encontro introdutório e gratuito voltado para brasileiros que querem conhecer o mercado de tecnologia na Irlanda. Não é necessário experiência prévia.</p><h2>O que você vai aprender</h2><ul><li>Lógica de programação (Python básico)</li><li>Segurança digital e proteção de dados</li><li>Networking com profissionais da área</li></ul><h2>Inscrição</h2><p>Evento gratuito com vagas limitadas. É necessário garantir o seu lugar pelo formulário de inscrição.</p>',
			'category'     => 'Educação',
			'county'       => 'Dublin',
			'url'          => '/events/sample-tech-workshop',
			'banner_label' => 'Tecnologia',
			'icon'         => 'laptop',
			'registration' => 'Inscrição gratuita — vagas limitadas',
			'cta'          => 'Reservar vaga',
		),
		array(
			'title'        => 'Encontro Comunitário em Laois',
			'slug'         => 'community-meetup',
			'date'         => '+3 weeks',
			'time'         => '14:00 — 17:00',
			'location'     => 'Portlaoise Community Centre, Laois',
			'excerpt'      => 'Um encontro descontraído para a comunidade brasileira: café, conversa, troca de experiências e apoio para quem está chegando.',
			'content'      => '<h2>Sobre o encontro</h2><p>Venha conhecer outros brasileiros, trocar dicas sobre a vida na Irlanda e participar de conversas em grupo sobre temas úteis para a comunidade.</p><h2>Programação</h2><ul><li>Café de boas-vindas e networking</li><li>Roda de conversa: desafios e soluções</li><li>Informações sobre serviços locais</li></ul><h2>Participação</h2><p>Entrada gratuita, aberto a todos. Crianças são bem-vindas.</p>',
			'category'     => 'Família',
			'county'       => 'Laois',
			'url'          => '/events/community-meetup',
			'banner_label' => 'Comunidade',
			'icon'         => 'users',
			'registration' => 'Entrada gratuita — sem necessidade de inscrição',
			'cta'          => 'Saiba mais',
		),
		array(
			'title'        => 'Networking de Negócios para Brasileiros',
			'slug'         => 'business-networking',
			'date'         => '+4 weeks',
			'time'         => '18:30 — 21:00',
			'location'     => 'Dogpatch Labs, The CHQ Building, Dublin 1',
			'excerpt'      => 'Noite de networking para empreendedores e profissionais brasileiros: conexões, pitches rápidos e troca de contatos no coração de Dublin.',
			'content'      => '<h2>Sobre o evento</h2><p>Um espaço para empreendedores, freelancers e profissionais em transição de carreira se conectarem e crescerem juntos.</p><h2>Programação</h2><ul><li>Pitches relâmpago (3 min por participante)</li><li>Speed networking guiado</li><li>Mesa-redonda: desafios de empreender na Irlanda</li></ul><h2>Investimento</h2><p>Ingresso: €15 (inclui welcome drink e aperitivos). Vagas limitadas.</p>',
			'category'     => 'Negócios',
			'county'       => 'Dublin',
			'url'          => '/events/business-networking',
			'banner_label' => 'Negócios',
			'icon'         => 'briefcase',
			'registration' => 'Ingressos: €15 — reserve online',
			'cta'          => 'Comprar ingresso',
		),
		array(
			'title'        => 'Feira de Saúde e Bem-Estar',
			'slug'         => 'health-wellness-fair',
			'date'         => '+5 weeks',
			'time'         => '11:00 — 16:00',
			'location'     => 'Cork City Hall, Cork',
			'excerpt'      => 'Um dia de palestras, atendimentos e atividades gratuitas para cuidar da saúde física e mental da comunidade brasileira.',
			'content'      => '<h2>Sobre a feira</h2><p>Profissionais de saúde voluntários oferecem orientações, pequenos atendimentos e oficinas práticas em português.</p><h2>Atividades</h2><ul><li>Aferição de pressão e glicemia</li><li>Palestra sobre saúde mental</li><li>Orientação sobre GP e Medical Card</li><li>Yoga ao ar livre (traga sua esteira)</li></ul><h2>Entrada</h2><p>Evento gratuito, aberto à comunidade. Faça sua inscrição para garantir um horário de atendimento.</p>',
			'category'     => 'Saúde',
			'county'       => 'Cork',
			'url'          => '/events/health-wellness-fair',
			'banner_label' => 'Bem-estar',
			'icon'         => 'heart',
			'registration' => 'Gratuito — inscrição recomendada',
			'cta'          => 'Inscrever-se',
		),
		array(
			'title'        => 'Festival Cultural Brasileiro',
			'slug'         => 'brazilian-culture-festival',
			'date'         => '+6 weeks',
			'time'         => '12:00 — 20:00',
			'location'     => 'Eyre Square, Galway',
			'excerpt'      => 'Música ao vivo, comidas típicas, artesanato e dança: um dia inteiro celebrando a cultura brasileira no oeste da Irlanda.',
			'content'      => '<h2>Sobre o festival</h2><p>Ao longo do dia, o centro de Galway recebe apresentações culturais, barracas gastronômicas e atividades para toda a família.</p><h2>Destaques</h2><ul><li>Shows de choro, samba e forró</li><li>Barracas com culinária brasileira</li><li>Feira de artesanato</li><li>Espaço kids com contação de histórias</li></ul><h2>Entrada</h2><p>Evento ao ar livre. Entrada livre. Consumo nas barracas à parte.</p>',
			'category'     => 'Turismo',
			'county'       => 'Galway',
			'url'          => '/events/brazilian-culture-festival',
			'banner_label' => 'Cultura',
			'icon'         => 'music',
			'registration' => 'Entrada livre — evento ao ar livre',
			'cta'          => 'Ver detalhes',
		),
		array(
			'title'        => 'Curso de CV e Entrevistas em Inglês',
			'slug'         => 'cv-interview-course',
			'date'         => '+2 months',
			'time'         => '09:30 — 12:30',
			'location'     => 'Limerick City Library, Limerick',
			'excerpt'      => 'Oficina prática para montar um CV no formato irlandês e treinar entrevistas de emprego em inglês com dicas de profissionais de RH.',
			'content'      => '<h2>Sobre o curso</h2><p>Um treinamento focado no mercado de trabalho irlandês, com revisão de currículos, simulação de entrevistas e orientação individual.</p><h2>Programação</h2><ul><li>Estrutura ideal do CV irlandês</li><li>Simulação de entrevista com feedback</li><li>Dicas de vocabulary profissional</li></ul><h2>Inscrição</h2><p>Evento gratuito, com material incluso. Leve uma versão impressa do seu CV atual.</p>',
			'category'     => 'Empregos',
			'county'       => 'Limerick',
			'url'          => '/events/cv-interview-course',
			'banner_label' => 'Carreira',
			'icon'         => 'documents',
			'registration' => 'Gratuito — inscrição obrigatória',
			'cta'          => 'Garantir vaga',
		),
		array(
			'title'        => 'Café da Manhã para Novos Imigrantes',
			'slug'         => 'newcomers-breakfast',
			'date'         => '+10 days',
			'time'         => '09:00 — 11:00',
			'location'     => 'Newbridge Parish Centre, Kildare',
			'excerpt'      => 'Um café da manhã acolhedor para quem acabou de chegar na Irlanda: informações essenciais, apoio da comunidade e boas energias.',
			'content'      => '<h2>Sobre o café</h2><p>Recém-chegados e brasileiros mais experientes se encontram para compartilhar informações práticas sobre os primeiros meses na Irlanda.</p><h2>O que levar</h2><ul><li>Suas dúvidas sobre PPS, IRP e moradia</li><li>Disposição para conhecer gente nova</li><li>Bom humor (o café é por nossa conta)</li></ul><h2>Participação</h2><p>Evento gratuito para recém-chegados e voluntários da comunidade.</p>',
			'category'     => 'Família',
			'county'       => 'Kildare',
			'url'          => '/events/newcomers-breakfast',
			'banner_label' => 'Boas-vindas',
			'icon'         => 'coffee',
			'registration' => 'Gratuito — confirme sua presença',
			'cta'          => 'Confirmar presença',
		),
		array(
			'title'        => 'Caminhada em Grupo pelo Parque Nacional',
			'slug'         => 'group-park-walk',
			'date'         => '+3 months',
			'time'         => '13:00 — 16:00',
			'location'     => 'Wicklow Mountains National Park, Wicklow',
			'excerpt'      => 'Uma trilha leve de primavera pelas montanhas de Wicklow, com paradas para fotos, piquenique e bate-papo. Nível: iniciante.',
			'content'      => '<h2>Sobre a caminhada</h2><p>Uma tarde ao ar livre para desconectar, conhecer a natureza irlandesa e fortalecer os laços da comunidade.</p><h2>Roteiro</h2><ul><li>Trilha de 6 km — nível iniciante</li><li>Guia voluntário experiente</li><li>Piquenique compartilhado no meio do caminho</li></ul><h2>O que trazer</h2><p>Calçado de trilha, roupa impermeável, água e algo para o piquenique compartilhado. Crianças são bem-vindas em vans de pais.</p>',
			'category'     => 'Turismo',
			'county'       => 'Wicklow',
			'url'          => '/events/group-park-walk',
			'banner_label' => 'Natureza',
			'icon'         => 'leaf',
			'registration' => 'Gratuito — inscrição para organização do transporte',
			'cta'          => 'Participar',
		),
	);

	public static function seed() {
		// Guard: don't re-seed if the option is already set. For development,
		// delete the option (wp option delete conexao_test_events_seeded) to re-run.
		if ( get_option( self::OPTION_KEY ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$created = 0;
		foreach ( self::$events as $event ) {
			$post_id = self::create_event( $event );
			if ( $post_id ) {
				$created++;
			}
		}

		update_option( self::OPTION_KEY, time() );

		// Ensure rewrite rules know about any new taxonomies/terms (e.g. "Educação").
		flush_rewrite_rules();
	}

	/**
	 * Create one event post with taxonomy terms and meta.
	 *
	 * @param array $event Event definition.
	 * @return int|false Post ID or false on failure.
	 */
	private static function create_event( $event ) {
		// Skip if a post with this slug already exists.
		$existing = get_page_by_path( $event['slug'], OBJECT, 'event' );
		if ( $existing ) {
			return $existing->ID;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'event',
				'post_status'  => 'publish',
				'post_title'   => $event['title'],
				'post_name'    => $event['slug'],
				'post_excerpt' => $event['excerpt'],
				'post_content' => $event['content'],
				'post_date'    => current_time( 'mysql' ),
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}

		// Date/time/location (existing fields from the data-model plugin).
		update_post_meta( $post_id, '_event_date', self::future_date( $event['date'] ) );
		update_post_meta( $post_id, '_event_time', $event['time'] );
		update_post_meta( $post_id, '_event_location', $event['location'] );

		// Destination URL (clickable banner destination).
		update_post_meta( $post_id, '_event_url', home_url( $event['url'] ) );

		// Registration / booking info.
		update_post_meta( $post_id, '_event_registration', $event['registration'] );

		// CTA button label.
		if ( ! empty( $event['cta'] ) ) {
			update_post_meta( $post_id, '_event_cta', $event['cta'] );
		}

		// Banner: theme asset inside assets/images/events.
		$banner_url = self::create_banner( $event );
		if ( $banner_url ) {
			update_post_meta( $post_id, '_event_banner', $banner_url );
		}

		// Categories and counties (create if missing).
		if ( ! empty( $event['category'] ) ) {
			$term = term_exists( $event['category'], 'conexao_category' );
			if ( ! $term ) {
				$term = wp_insert_term( $event['category'], 'conexao_category' );
			}
			if ( ! is_wp_error( $term ) && is_array( $term ) ) {
				wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), 'conexao_category' );
			}
		}

		if ( ! empty( $event['county'] ) ) {
			$county = term_exists( $event['county'], 'conexao_county' );
			if ( ! $county ) {
				$county = wp_insert_term( $event['county'], 'conexao_county' );
			}
			if ( ! is_wp_error( $county ) && is_array( $county ) ) {
				wp_set_object_terms( $post_id, array( (int) $county['term_id'] ), 'conexao_county' );
			}
		}

		return $post_id;
	}

	/**
	 * Compute a future date for the event.
	 *
	 * @param string $offset Date offset, e.g. "+2 weeks".
	 * @return string Y-m-d
	 */
	private static function future_date( $offset ) {
		return wp_date( 'Y-m-d', strtotime( $offset, current_time( 'timestamp' ) ) );
	}

	/**
	 * Generate a consistent SVG banner/thumbnail for the event inside the
	 * theme's assets/images/events directory and return its URL.
	 *
	 * @param array $event Event definition.
	 * @return string|false Banner URL, or false on failure.
	 */
	private static function create_banner( $event ) {
		$theme_dir = get_template_directory();
		$events_dir = trailingslashit( $theme_dir ) . 'assets/images/events';
		$events_uri = trailingslashit( get_template_directory_uri() ) . 'assets/images/events';

		if ( ! is_dir( $events_dir ) ) {
			wp_mkdir_p( $events_dir );
		}

		$svg = self::render_banner_svg( $event );
		$file = trailingslashit( $events_dir ) . $event['slug'] . '.svg';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $file, $svg );
		if ( false === $written ) {
			return false;
		}

		return trailingslashit( $events_uri ) . $event['slug'] . '.svg';
	}

	/**
	 * Render the SVG markup for a consistent, branded event banner.
	 *
	 * The banners share the same layout each time: a branded gradient
	 * background, a subtle geometric pattern, a category/icon chip and the
	 * event label. The accent colour rotates by event so each banner stays
	 * visually consistent yet distinguishable.
	 *
	 * @param array $event Event definition.
	 * @return string SVG markup.
	 */
	private static function render_banner_svg( $event ) {
		$accents = array(
			'#F68B1F', // orange (accent)
			'#0E6B3A', // primary green
			'#1D4ED8', // blue
			'#7C3AED', // violet
			'#0F766E', // teal
			'#B45309', // amber
			'#BE123C', // rose
			'#1E40AF', // indigo
		);

		// Pick a stable accent per event slug.
		$idx = abs( crc32( $event['slug'] ) ) % count( $accents );
		$accent = $accents[ $idx ];

		$background_from = self::hex2rgb( $accent, 'hsla', 0.92 );

		$icons = array(
			'laptop'    => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
			'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
			'heart'     => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
			'music'     => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
			'documents' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
			'coffee'    => '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>',
			'leaf'      => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
		);

		$icon = isset( $icons[ $event['icon'] ] ) ? $icons[ $event['icon'] ] : $icons['users'];
		$label = esc_html( $event['banner_label'] );

		$title = $event['title'];
		if ( function_exists( 'mb_strtoupper' ) ) {
			$title = mb_strtoupper( mb_substr( $title, 0, 28 ) );
			if ( mb_strlen( $event['title'] ) > 28 ) {
				$title .= '…';
			}
		} else {
			$title = strtoupper( substr( $title, 0, 28 ) ) . ( strlen( $event['title'] ) > 28 ? '…' : '' );
		}
		$title = esc_html( $title );

		$date_str = wp_date( 'j M Y', strtotime( self::future_date( $event['date'] ) ) );

		$palette = array( '#FFFFFF', '#FFFBEB', '#F0FDF4' );
		$p = $palette[ $idx % count( $palette ) ];

		return '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450" role="img" aria-label="' . esc_attr( $event['title'] ) . '">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="' . $background_from . '"/>
      <stop offset="55%" stop-color="' . $accent . '"/>
      <stop offset="100%" stop-color="#0B1120"/>
    </linearGradient>
    <radialGradient id="glow" cx="75%" cy="20%" r="60%">
      <stop offset="0%" stop-color="' . $p . '" stop-opacity="0.35"/>
      <stop offset="100%" stop-color="' . $p . '" stop-opacity="0"/>
    </radialGradient>
    <pattern id="dots" width="28" height="28" patternUnits="userSpaceOnUse">
      <circle cx="2" cy="2" r="1.2" fill="' . $p . '" fill-opacity="0.18"/>
    </pattern>
  </defs>
  <rect width="800" height="450" fill="url(#bg)"/>
  <rect width="800" height="450" fill="url(#dots)"/>
  <rect width="800" height="450" fill="url(#glow)"/>
  <circle cx="680" cy="80" r="150" fill="' . $p . '" fill-opacity="0.08"/>
  <circle cx="120" cy="400" r="180" fill="' . $p . '" fill-opacity="0.06"/>
  <g transform="translate(56 56)">
    <circle cx="40" cy="40" r="40" fill="' . $p . '" fill-opacity="0.18"/>
    <g transform="translate(16 16)" fill="none" stroke="#FFFFFF" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round">
      ' . $icon . '
    </g>
  </g>
  <text x="56" y="190" font-family="Inter, Arial, sans-serif" font-size="15" font-weight="700" letter-spacing="3" fill="' . $p . '" fill-opacity="0.85">' . $label . '</text>
  <text x="56" y="250" font-family="Poppins, Arial, sans-serif" font-size="34" font-weight="700" fill="#FFFFFF">' . $title . '</text>
  <text x="56" y="300" font-family="Inter, Arial, sans-serif" font-size="18" fill="#FFFFFF" fill-opacity="0.9">' . $date_str . '</text>
  <text x="56" y="408" font-family="Inter, Arial, sans-serif" font-size="13" letter-spacing="2" fill="#FFFFFF" fill-opacity="0.65">CONEXÃO BR IRLANDA</text>
  <line x1="56" y1="372" x2="304" y2="372" stroke="' . $p . '" stroke-width="3" stroke-opacity="0.8"/>
</svg>
';
	}

	/**
	 * Convert a hex colour to an rgb/rgba/hsl/hsla string.
	 *
	 * @param string $hex Hex colour (#RRGGBB).
	 * @param string $format Output format.
	 * @param float  $alpha Alpha for hsla.
	 * @return string
	 */
	private static function hex2rgb( $hex, $format = 'rgb', $alpha = 1.0 ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		if ( 'rgb' === $format ) {
			return "rgb($r,$g,$b)";
		}
		if ( 'rgba' === $format ) {
			return "rgba($r,$g,$b,$alpha)";
		}
		if ( 'hsla' === $format ) {
			// Convert to HSL for a subtle, lighter gradient stop.
			$r_n = $r / 255;
			$g_n = $g / 255;
			$b_n = $b / 255;
			$max = max( $r_n, $g_n, $b_n );
			$min = min( $r_n, $g_n, $b_n );
			$l = ( $max + $min ) / 2;
			$d = $max - $min;
			$h = 0;
			$s = 0;
			if ( 0 !== $d ) {
				$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
				switch ( $max ) {
					case $r_n:
						$h = ( $g_n - $b_n ) / $d + ( $g_n < $b_n ? 6 : 0 );
						break;
					case $g_n:
						$h = ( $b_n - $r_n ) / $d + 2;
						break;
					default:
						$h = ( $r_n - $g_n ) / $d + 4;
				}
				$h *= 60;
			}
			// Lighten the HSL-L for the gradient start so the banner isn't too dark.
			$l = min( 0.85, $l + 0.2 );
			return "hsla(" . round( $h ) . "," . round( $s * 100 ) . "%," . round( $l * 100 ) . "%," . $alpha . ")";
		}

		return "rgb($r,$g,$b)";
	}
}