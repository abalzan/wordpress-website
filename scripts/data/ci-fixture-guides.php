<?php
/**
 * CI FIXTURE DATASET — synthetic PT `guide` records (Stage P, CI readiness).
 *
 * The ONLY reason this file exists: the maintained HTTP acceptance suite
 * (`tests/acceptance/verify-guides-en-http.py`) and the B1 translation-
 * completeness gate both need a NON-EMPTY guide population on a database
 * built from nothing. The /guias/ archive paginates at 10 records per page,
 * so `/guias/page/2/` and `/en/guias/page/2/` are 404 without at least 11
 * published guides. This dataset supplies them.
 *
 * IDENTITY: the PT slug, and nothing else. A local post ID is never
 * cross-environment identity (engineering standard 0.4).
 *
 * WHY THESE SPECIFIC SLUGS: they are a subset of the AUTHORED English
 * manifest in `wp-content/plugins/conexao-en-translation/includes/
 * guide-translation-data.php`. The `en-guide` stage of the SHARED
 * translation-rollout engine resolves its records by PT slug, so a guide
 * created under any other slug would be a B1 record with no authored
 * English and would make the completeness gate fail for a reason that has
 * nothing to do with the code under test. This dataset therefore creates
 * the PT half of a pair whose EN half the repository ALREADY ships.
 *
 * THE DATASET TRACKS THE MANIFEST'S STABLE KEYS EXACTLY, and the 2026-09-30 EN
 * rollout reconciliation is encoded here rather than left implicit
 * (`docs/reports/2026-09-30-en-rollout-blocker-resolution.md`):
 *
 *   - `carteira-de-motorista-2` was RE-KEYED to `carteira-motorista-brasileiros`.
 *     The old PT guide was permanently deleted in production (its id range has
 *     exactly one gap, at 463) and the authored EN row now resolves against the
 *     real successor guide. This dataset must therefore create the PT record
 *     under the SUCCESSOR slug, or the authored English would have no PT half
 *     and the completeness gate would fail for a fixture reason.
 *   - `learner-permit-theory-test-irlanda-cnh-brasileira` was RETIRED: its PT
 *     source was permanently deleted and the authored EN row was withdrawn with
 *     operator authorisation (recorded in
 *     `wp-content/plugins/conexao-en-translation/includes/exclusions-data.php`,
 *     classification `NO_REAL_PT_SOURCE`). No PT record is created for it here.
 *     Creating one would be a FAKE PT identity — a record that does not exist in
 *     production — and the retired row must not reappear as an eligible PT
 *     record merely to give the fixture site a population.
 *
 * Both directions are fail-closed by the existing gate: a fixture slug with no
 * authored EN row, or an authored row with no PT record, makes the permanent
 * `translation_completeness` gate report `missing_en` and the run fails.
 *
 * CATEGORY COVERAGE IS LOAD-BEARING, not decorative. The en-guide stage
 * also runs a taxonomy gate over every authored `conexao_category` term
 * (13 of them, in guide-terms-data.php). Between them these 51 records
 * use all 13, so the gate has every PT term it expects to translate and
 * the EN terms it creates are all actually referenced. Dropping a
 * category here would silently un-reference an authored EN term.
 *
 * CONTENT: synthetic. Every body is a deterministic placeholder that
 * states its own synthetic nature. No production copy, no scraped
 * third-party body, no personal data. The EN side of every pair is the
 * real authored English and is written by the existing stage, never here.
 *
 * IDEMPOTENCE: keyed by slug, create-or-repair. A second run creates
 * nothing and rewrites nothing. Nothing here depends on run order, on a
 * developer's database, or on any state a previous seed produced.
 *
 * @package Conexao_BR_Scripts
 */

defined( 'ABSPATH' ) || defined( 'CONEXAO_SCRIPTS_BOOTSTRAP' ) || exit;

/**
 * The synthetic PT guides, keyed by PT slug (the stable key).
 *
 * @return array<string,array{title:string,category:string,excerpt:string,content:string}>
 */
function conexao_ci_fixture_guides(): array {
	$rows = array();

	$map = array(
		'pps-number-2'                                     => 'documentos',
		'medical-card-2'                                   => 'saude',
		'gp-registration-2'                                => 'saude',
		'abrir-conta-bancaria-2'                           => 'financas',
		'alugar-casa-2'                                    => 'moradia',
		'comprar-carro-2'                                  => 'transporte',
		'carteira-motorista-brasileiros'                   => 'transporte',
		'impostos-2'                                       => 'impostos-e-revenue',
		'cidadania-irlandesa-2'                            => 'documentos',
		'passaporte-irlandes-2'                            => 'documentos',
		'child-benefit-2'                                  => 'beneficios',
		'social-welfare-2'                                 => 'beneficios',
		'abrir-empresa-2'                                  => 'negocios',
		'visto-irlanda-2'                                  => 'imigracao-e-vistos',
		'irp-renewal-2'                                    => 'imigracao-e-vistos',
		'mygovid'                                          => 'documentos',
		'direitos-trabalhistas'                            => 'empregos',
		'transporte-publico'                               => 'transporte',
		'susi'                                             => 'educacao',
		'servicos-emergencia'                              => 'servicos-publicos',
		'nct'                                              => 'transporte',
		'motor-tax'                                        => 'transporte',
		'eircode'                                          => 'documentos',
		'hap'                                              => 'moradia',
		'rtb'                                              => 'moradia',
		'cao'                                              => 'educacao',
		'direitos-consumidor'                              => 'financas',
		'protecao-dados'                                   => 'documentos',
		'garda'                                            => 'justica-e-seguranca',
		'revenue-myaccount'                                => 'impostos-e-revenue',
		'primeira-inscricao-irp-irlanda-brasileiros'       => 'imigracao-e-vistos',
		'viajar-fora-irlanda-com-irp-brasileiros'          => 'imigracao-e-vistos',
		'employment-permit-irlanda-brasileiros'            => 'empregos',
		'primeiro-emprego-irlanda-emergency-tax'           => 'impostos-e-revenue',
		'sole-trader-autonomo-irlanda-brasileiros'         => 'negocios',
		'hse-irlanda-gp-out-of-hours-injury-unit-emergency-department' => 'saude',
		'ehic-irlanda-brasileiros-residentes'              => 'saude',
		'reconhecer-diploma-brasileiro-na-irlanda-naric-qqi' => 'educacao',
		'leap-card-irlanda-como-usar'                      => 'transporte',
		'assistencia-juridica-legal-aid-irlanda-brasileiros' => 'justica-e-seguranca',
		'casamento-registro-nascimento-irlanda-brasileiros' => 'documentos',
		'reclamar-banco-seguro-servico-financeiro-irlanda' => 'financas',
		'reclamar-servico-publico-irlanda-ombudsman'       => 'servicos-publicos',
		'checklist-viagem-internacional-irlanda-brasileiros-irp' => 'imigracao-e-vistos',
		'reclamacao-trabalhista-wrc-irlanda-brasileiros'   => 'empregos',
		'beneficios-pais-solteiros-irlanda'                => 'beneficios',
		'violencia-domestica-irlanda-onde-encontrar-ajuda' => 'justica-e-seguranca',
		'inverno-irlanda-depressao-sazonal-saude-mental'   => 'saude',
		'autismo-na-irlanda-diagnostico-hse-apoio'         => 'saude',
		'autismo-viagem-aviao-irlanda-dublin-cork-airport' => 'transporte',
	);

	foreach ( $map as $slug => $category ) {
		$rows[ $slug ] = array(
			'title'    => conexao_ci_fixture_guide_title( $slug ),
			'category' => $category,
			'excerpt'  => conexao_ci_fixture_guide_excerpt( $slug ),
			'content'  => conexao_ci_fixture_guide_content( $slug ),
		);
	}

	return $rows;
}

/**
 * Human-readable Portuguese title for a fixture guide.
 *
 * Derived from the slug rather than hand-listed, so the dataset stays small,
 * reviewable and impossible to desynchronise from its own key: there is exactly
 * one source of truth for a fixture's identity (the slug), and the title is a
 * pure function of it. The topic wording is the slug's own words, so the title
 * still reads as a real guide title in wp-admin while being unmistakably
 * synthetic in content.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_guide_title( string $slug ): string {
	$words = explode( '-', $slug );

	return 'Guia CI: ' . ucwords( implode( ' ', $words ) );
}

/**
 * Deterministic synthetic Portuguese excerpt.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_guide_excerpt( string $slug ): string {
	return sprintf(
		'Conteúdo sintético de teste automático para o guia "%s". Gerado pela arvore de fixtures determinísticas da CI; não é conteúdo editorial e não deve ser publicado.',
		$slug
	);
}

/**
 * Deterministic synthetic Portuguese body.
 *
 * The body is the same Gutenberg-block paragraph on every record. That is
 * deliberate: the acceptance suite asserts on ARCHIVE and SINGLE structure
 * (status, canonical, hreflang, pagination, language attributes), never on the
 * prose of a fixture body, so a uniform body removes 51 chances for the
 * fixture data to drift without changing what any test observes. The English
 * body of the paired record is real authored English, written by the existing
 * `en-guide` stage, and is what the EN assertions actually read.
 *
 * @param string $slug PT slug.
 * @return string
 */
function conexao_ci_fixture_guide_content( string $slug ): string {
	return sprintf(
		'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Este é um registo sintético criado apenas pela arvore de fixtures determinísticas da integração contínua. Serve para que a suite de aceitação tenha conteúdo suficiente para exercitar paginação, canónicos, hreflang e tradução. Não é conteúdo editorial.</p><!-- /wp:paragraph -->',
		esc_html( conexao_ci_fixture_guide_excerpt( $slug ) )
	);
}
