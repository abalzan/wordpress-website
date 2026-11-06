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

$ce_modules = array();

// 1. Upcoming community events, curated by an EDITORIAL keyword list (never a
// date rule): the shared event engine supplies the candidates.
$ce_modules[] = conexao_commemorative_module_upcoming_events(
	array(
		'id'       => 'agenda',
		'title'    => __( 'Agenda de Natal', 'conexao-br-irlanda' ),
		'intro'    => __( 'Concertos, feiras e encontros da comunidade nesta época do ano, todos já publicados na agenda do portal.', 'conexao-br-irlanda' ),
		'keywords' => array( 'christmas', 'natal', 'santa', 'wreath', 'solstice', 'solstício', 'réveillon', 'reveillon', 'new year', 'ano novo', 'gathering' ),
		'limit'    => 6,
	)
);

// 2. A real place record: Newgrange and the Irish winter solstice.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'lugar',
		'title'   => __( 'Um lugar para o solstício', 'conexao-br-irlanda' ),
		'intro'   => __( 'No inverno irlandês, o solstício tem um endereço: a passagem de Newgrange, no condado de Meath.', 'conexao-br-irlanda' ),
		'records' => array(
			array(
				'type'  => 'leisure',
				'slug'  => 'newgrange',
				'label' => __( 'Lugar', 'conexao-br-irlanda' ),
			),
		),
	)
);

// 3. A real guide record: looking after yourself through an Irish winter.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'bem-estar',
		'title'   => __( 'Cuidar do inverno', 'conexao-br-irlanda' ),
		'intro'   => __( 'Dias curtos e pouca luz afetam muita gente. Este guia explica o que fazer e onde procurar apoio.', 'conexao-br-irlanda' ),
		'records' => array(
			array(
				'type'  => 'guide',
				'slug'  => 'inverno-irlanda-depressao-sazonal-saude-mental',
				'label' => __( 'Guia', 'conexao-br-irlanda' ),
			),
		),
	)
);

// 4. Real articles already published on the blog.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'leituras',
		'title'   => __( 'Leituras de inverno', 'conexao-br-irlanda' ),
		'intro'   => __( 'Textos da nossa comunidade para as noites mais longas do ano.', 'conexao-br-irlanda' ),
		'records' => array(
			array(
				'type'  => 'post',
				'slug'  => 'coragem-a-estrela-que-ilumina-o-caminho-do-empreendedor-especial-de-natal',
				'label' => __( 'Artigo', 'conexao-br-irlanda' ),
			),
			array(
				'type'  => 'post',
				'slug'  => 'o-calor-que-nasce-do-inverno',
				'label' => __( 'Artigo', 'conexao-br-irlanda' ),
			),
			array(
				'type'  => 'post',
				'slug'  => 'preparando-o-corpo-para-o-inverno',
				'label' => __( 'Artigo', 'conexao-br-irlanda' ),
			),
		),
	)
);

// 5. Practical guides for travelling and documents over the holidays.
$ce_modules[] = conexao_commemorative_module_from_records(
	array(
		'id'      => 'guias',
		'title'   => __( 'Guias úteis para as festas', 'conexao-br-irlanda' ),
		'intro'   => __( 'Se vai viajar ou receber visita nesta época, estes guias resolvem as dúvidas mais comuns.', 'conexao-br-irlanda' ),
		'records' => array(
			array(
				'type'  => 'guide',
				'slug'  => 'checklist-viagem-internacional-irlanda-brasileiros-irp',
				'label' => __( 'Guia', 'conexao-br-irlanda' ),
			),
			array(
				'type'  => 'guide',
				'slug'  => 'viajar-fora-irlanda-com-irp-brasileiros',
				'label' => __( 'Guia', 'conexao-br-irlanda' ),
			),
			array(
				'type'  => 'guide',
				'slug'  => 'servicos-emergencia',
				'label' => __( 'Guia', 'conexao-br-irlanda' ),
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
