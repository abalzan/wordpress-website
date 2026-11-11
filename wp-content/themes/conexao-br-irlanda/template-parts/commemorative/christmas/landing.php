<?php
/**
 * Christmas package — dedicated landing-page modules.
 *
 * The composition below is the package's REUSE MAP (r10 §7.12 field 6): every
 * module surfaces EXISTING records by slug and never copies their content. The
 * slugs are the canonical Portuguese records; the helper resolves the current
 * language through Polylang and falls back to the canonical record when a
 * translation is missing (B2 policy).
 *
 * The Christmas page therefore survives the event window: it reads pages,
 * guides, places, articles and the shared upcoming-events engine — never the
 * event record's schedule.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || 'christmas' !== $ce['package']['slug'] ) {
	return;
}

$ce_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );

/*
 * EDITORIAL ARC (brief §5.3). The page's own title and intro — rendered by the shared
 * shell from the PAGE record, unchanged — are the invitation. This template then opens
 * with the signature star journey, follows with the shared threads, and only then
 * surfaces the records the page already reuses. Nothing below duplicates a record.
 */
get_template_part( 'template-parts/commemorative/christmas/journey', null, array( 'ce' => $ce ) );

$ce_threads = $ce_english
	? array(
		'title' => __( 'What crosses both skies', 'conexao-br-irlanda' ),
		'intro' => __( 'It is not the weather, not the time of day and not the same food. It is something else.', 'conexao-br-irlanda' ),
		'items' => array(
			array(
				'title' => __( 'The table', 'conexao-br-irlanda' ),
				'text'  => __( 'Eating together changes the day — in the heat or in the cold.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'The visit', 'conexao-br-irlanda' ),
				'text'  => __( 'Turning up, staying longer than agreed, hearing the same story again.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'The reminder', 'conexao-br-irlanda' ),
				'text'  => __( 'A card, a message, a dish that travels across the city.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'The effort', 'conexao-br-irlanda' ),
				'text'  => __( 'Taking the bus, the plane or the ocean to be near someone.', 'conexao-br-irlanda' ),
			),
		),
		'caveat' => __( 'None of it is compulsory. Plenty of people spend this season working, alone, or simply differently — and that is Christmas too.', 'conexao-br-irlanda' ),
		'caption' => __( 'One star, two settings: the beach and the village, summer and winter.', 'conexao-br-irlanda' ),
		'alt'     => __( 'An illustrated panorama: a beach and city skyline under a sunset sky on the left, a snow-covered stone village with a castle on the right, and a lit Christmas tree with gifts between them.', 'conexao-br-irlanda' ),
	)
	: array(
		'title' => __( 'O que atravessa os dois céus', 'conexao-br-irlanda' ),
		'intro' => __( 'Não é o clima, não é o horário e não é a mesma comida. É outra coisa.', 'conexao-br-irlanda' ),
		'items' => array(
			array(
				'title' => __( 'A mesa', 'conexao-br-irlanda' ),
				'text'  => __( 'Comer junto muda o dia — no calor ou no frio.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'A visita', 'conexao-br-irlanda' ),
				'text'  => __( 'Aparecer, ficar mais do que o combinado, ouvir a mesma história de novo.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'A lembrança', 'conexao-br-irlanda' ),
				'text'  => __( 'Um cartão, uma mensagem, um prato que atravessa a cidade.', 'conexao-br-irlanda' ),
			),
			array(
				'title' => __( 'O esforço', 'conexao-br-irlanda' ),
				'text'  => __( 'Pegar o ônibus, o avião ou o oceano para estar perto de alguém.', 'conexao-br-irlanda' ),
			),
		),
		'caveat'  => __( 'Nada disso é obrigatório. Muita gente passa essa época trabalhando, sozinha ou simplesmente de outro jeito — e isso também é Natal.', 'conexao-br-irlanda' ),
		'caption' => __( 'Uma mesma estrela, dois cenários: a praia e a vila, o verão e o inverno.', 'conexao-br-irlanda' ),
		'alt'     => __( 'Panorama ilustrado: uma praia e uma cidade sob o céu do pôr do sol à esquerda, uma vila de pedra coberta de neve com um castelo à direita, e uma árvore de Natal iluminada com presentes entre as duas.', 'conexao-br-irlanda' ),
	);

$ce_postcard_uri = get_template_directory_uri() . '/assets/images/presentations/christmas/two-skies/';
?>
<section class="ce-christmas-threads" aria-labelledby="ce-christmas-threads-title">
	<div class="ce-christmas-threads__intro">
		<h2 class="ce-christmas-threads__title" id="ce-christmas-threads-title"><?php echo esc_html( $ce_threads['title'] ); ?></h2>
		<p class="ce-christmas-threads__lede"><?php echo esc_html( $ce_threads['intro'] ); ?></p>
	</div>

	<ul class="ce-christmas-threads__list">
		<?php foreach ( $ce_threads['items'] as $ce_thread ) : ?>
			<li class="ce-christmas-threads__item">
				<h3 class="ce-christmas-threads__item-title"><?php echo esc_html( $ce_thread['title'] ); ?></h3>
				<p class="ce-christmas-threads__item-text"><?php echo esc_html( $ce_thread['text'] ); ?></p>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="ce-christmas-threads__caveat"><?php echo esc_html( $ce_threads['caveat'] ); ?></p>

	<figure class="ce-christmas-threads__figure">
		<img
			src="<?php echo esc_url( $ce_postcard_uri . 'two-skies-postcard-1120.webp' ); ?>"
			srcset="<?php echo esc_url( $ce_postcard_uri . 'two-skies-postcard-760.webp' ); ?> 760w, <?php echo esc_url( $ce_postcard_uri . 'two-skies-postcard-1120.webp' ); ?> 1120w"
			sizes="(max-width: 820px) calc(100vw - 32px), 700px"
			width="1120"
			height="630"
			alt="<?php echo esc_attr( $ce_threads['alt'] ); ?>"
			loading="lazy"
			decoding="async"
			draggable="false"
		>
		<figcaption class="ce-christmas-threads__caption"><?php echo esc_html( $ce_threads['caption'] ); ?></figcaption>
	</figure>
</section>
<?php
/*
 * The module headings and type labels are resolved here, per language, exactly like the
 * hero and the journey above: the package must never render a Portuguese heading on the
 * English page just because a catalogue translation has not been generated yet.
 */
$ce_copy = $ce_english
	? array(
		'agenda_title'    => __( 'Christmas calendar', 'conexao-br-irlanda' ),
		'agenda_intro'    => __( 'Concerts, markets and community gatherings at this time of year — all already published in the portal calendar.', 'conexao-br-irlanda' ),
		'lugar_title'     => __( 'A place for the solstice', 'conexao-br-irlanda' ),
		'lugar_intro'     => __( 'In the Irish winter the solstice has an address: the passage tomb at Newgrange, County Meath.', 'conexao-br-irlanda' ),
		'bem_estar_title' => __( 'Looking after the winter', 'conexao-br-irlanda' ),
		'bem_estar_intro' => __( 'Short days and little light affect many people. This guide explains what helps and where to look for support.', 'conexao-br-irlanda' ),
		'leituras_title'  => __( 'Winter reading', 'conexao-br-irlanda' ),
		'leituras_intro'  => __( 'Writing from our community for the longest nights of the year.', 'conexao-br-irlanda' ),
		'guias_title'     => __( 'Useful guides for the holidays', 'conexao-br-irlanda' ),
		'guias_intro'     => __( 'If you are travelling or hosting at this time of year, these guides answer the usual questions.', 'conexao-br-irlanda' ),
		'label_place'     => __( 'Place', 'conexao-br-irlanda' ),
		'label_guide'     => __( 'Guide', 'conexao-br-irlanda' ),
		'label_article'   => __( 'Article', 'conexao-br-irlanda' ),
		'label_event'     => __( 'Event', 'conexao-br-irlanda' ),
	)
	: array(
		'agenda_title'    => __( 'Agenda de Natal', 'conexao-br-irlanda' ),
		'agenda_intro'    => __( 'Concertos, feiras e encontros da comunidade nesta época do ano, todos já publicados na agenda do portal.', 'conexao-br-irlanda' ),
		'lugar_title'     => __( 'Um lugar para o solstício', 'conexao-br-irlanda' ),
		'lugar_intro'     => __( 'No inverno irlandês, o solstício tem um endereço: a passagem de Newgrange, no condado de Meath.', 'conexao-br-irlanda' ),
		'bem_estar_title' => __( 'Cuidar do inverno', 'conexao-br-irlanda' ),
		'bem_estar_intro' => __( 'Dias curtos e pouca luz afetam muita gente. Este guia explica o que fazer e onde procurar apoio.', 'conexao-br-irlanda' ),
		'leituras_title'  => __( 'Leituras de inverno', 'conexao-br-irlanda' ),
		'leituras_intro'  => __( 'Textos da nossa comunidade para as noites mais longas do ano.', 'conexao-br-irlanda' ),
		'guias_title'     => __( 'Guias úteis para as festas', 'conexao-br-irlanda' ),
		'guias_intro'     => __( 'Se vai viajar ou receber visita nesta época, estes guias resolvem as dúvidas mais comuns.', 'conexao-br-irlanda' ),
		'label_place'     => __( 'Lugar', 'conexao-br-irlanda' ),
		'label_guide'     => __( 'Guia', 'conexao-br-irlanda' ),
		'label_article'   => __( 'Artigo', 'conexao-br-irlanda' ),
		'label_event'     => __( 'Evento', 'conexao-br-irlanda' ),
	);

$ce_modules = array();

// 1. Upcoming community events, curated by an EDITORIAL keyword list (never a
// date rule): the shared event engine supplies the candidates.
$ce_modules[] = conexao_commemorative_module_upcoming_events(
	array(
		'id'       => 'agenda',
		'title'    => $ce_copy['agenda_title'],
		'intro'    => $ce_copy['agenda_intro'],
		'keywords' => array( 'christmas', 'natal', 'santa', 'wreath', 'solstice', 'solstício', 'réveillon', 'reveillon', 'new year', 'ano novo', 'gathering' ),
		'label'    => $ce_copy['label_event'],
		'limit'    => 6,
	)
);

// 2. A real place record: Newgrange and the Irish winter solstice.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'lugar',
		'title'   => $ce_copy['lugar_title'],
		'intro'   => $ce_copy['lugar_intro'],
		'records' => array(
			array(
				'type'  => 'leisure',
				'slug'  => 'newgrange',
				'label' => $ce_copy['label_place'],
			),
		),
	)
);

// 3. A real guide record: looking after yourself through an Irish winter.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'bem-estar',
		'title'   => $ce_copy['bem_estar_title'],
		'intro'   => $ce_copy['bem_estar_intro'],
		'records' => array(
			array(
				'type'  => 'guide',
				'slug'  => 'inverno-irlanda-depressao-sazonal-saude-mental',
				'label' => $ce_copy['label_guide'],
			),
		),
	)
);

// 4. Real articles already published on the blog.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'leituras',
		'title'   => $ce_copy['leituras_title'],
		'intro'   => $ce_copy['leituras_intro'],
		'records' => array(
			array(
				'type'  => 'post',
				'slug'  => 'coragem-a-estrela-que-ilumina-o-caminho-do-empreendedor-especial-de-natal',
				'label' => $ce_copy['label_article'],
			),
			array(
				'type'  => 'post',
				'slug'  => 'o-calor-que-nasce-do-inverno',
				'label' => $ce_copy['label_article'],
			),
			array(
				'type'  => 'post',
				'slug'  => 'preparando-o-corpo-para-o-inverno',
				'label' => $ce_copy['label_article'],
			),
		),
	)
);

// 5. Practical guides for travelling and documents over the holidays.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'guias',
		'title'   => $ce_copy['guias_title'],
		'intro'   => $ce_copy['guias_intro'],
		'records' => array(
			array(
				'type'  => 'guide',
				'slug'  => 'checklist-viagem-internacional-irlanda-brasileiros-irp',
				'label' => $ce_copy['label_guide'],
			),
			array(
				'type'  => 'guide',
				'slug'  => 'viajar-fora-irlanda-com-irp-brasileiros',
				'label' => $ce_copy['label_guide'],
			),
			array(
				'type'  => 'guide',
				'slug'  => 'servicos-emergencia',
				'label' => $ce_copy['label_guide'],
			),
		),
	)
);

foreach ( $ce_modules as $ce_module ) {
	if ( empty( $ce_module['items'] ) ) {
		continue;
	}

	get_template_part( 'template-parts/commemorative/modules/module', null, array( 'module' => $ce_module ) );
}


/*
 * The warm close (brief §5.3, step 5). One sentence of inclusion, one real
 * destination inside the portal — never an empty button.
 */
$ce_closing_lines = $ce_english
	? array(
		'title' => __( 'A light that outlasts December', 'conexao-br-irlanda' ),
		'text'  => __( 'Whether Christmas is your celebration or not, December still carries meetings, rest and good food. The portal calendar keeps going after the holidays — and this page stays here.', 'conexao-br-irlanda' ),
		'cta'   => __( 'See what is on this season', 'conexao-br-irlanda' ),
	)
	: array(
		'title' => __( 'Uma luz que continua depois de dezembro', 'conexao-br-irlanda' ),
		'text'  => __( 'Se o Natal é a sua festa ou não, dezembro também traz encontro, descanso e comida boa. A agenda do portal continua viva depois das festas — e esta página fica aqui.', 'conexao-br-irlanda' ),
		'cta'   => __( 'Ver o que acontece nesta época', 'conexao-br-irlanda' ),
	);
?>
<section class="ce-christmas-closing" aria-labelledby="ce-christmas-closing-title">
	<span class="ce-christmas-closing__star" aria-hidden="true">
		<?php
		get_template_part(
			'template-parts/commemorative/christmas/star',
			null,
			array(
				'id'    => 'closing',
				'class' => 'ce-christmas-closing__star-svg',
			)
		);
		?>
	</span>
	<h2 class="ce-christmas-closing__title" id="ce-christmas-closing-title"><?php echo esc_html( $ce_closing_lines['title'] ); ?></h2>
	<p class="ce-christmas-closing__text"><?php echo esc_html( $ce_closing_lines['text'] ); ?></p>
	<p class="ce-christmas-closing__actions">
		<a class="btn btn-primary ce-christmas-closing__cta" href="<?php echo esc_url( conexao_lang_url( '/eventos/' ) ); ?>">
			<?php echo esc_html( $ce_closing_lines['cta'] ); ?>
		</a>
	</p>
</section>
