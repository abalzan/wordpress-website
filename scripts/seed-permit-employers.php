<?php
/**
 * Seeder for the "Empresas com histórico de Employment Permits" directory.
 *
 * Usage:  php scripts/seed-permit-employers.php
 * Output: a short report of created/updated records.
 *
 * IMPORTANT: verify every employer's data against the official DETE
 * "Permits issued to companies" statistics and each company's own website
 * before running on production. Evidence years below were confirmed
 * against the official files on enterprise.gov.ie (2023, 2024 and 2025
 * publications) on 2026-09-02.
 *
 * Editorial rules (see docs/research/2026-09-empregos-agencies-and-employment-permits.md):
 * - 'verified' = verified HISTORICAL permit evidence in the official DETE
 *   statistics. NEVER "currently sponsoring".
 * - Kepak is seeded as 'unverified' (plain employer entry, NO permit
 *   indicator) until its exact DETE legal entity is matched and validated.
 * - Nua Healthcare is seeded as 'exception': it has historical DETE
 *   evidence but its own site states it is NOT currently recruiting
 *   internationally or sponsoring General Employment Permits. It renders
 *   in the "Importante" block, never with a generic verified badge.
 * - Farm Solutions is NOT seeded: on HOLD until its WRC/licensing status
 *   and applicability are manually resolved. Do not describe it as
 *   unlicensed or illegitimate — simply do not publish it yet.
 * - Evidence sources and DETE legal-entity names stay in admin data
 *   (_employer_evidence_source / _employer_notes); the frontend shows one
 *   shared source note instead.
 */

$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

if ( ! post_type_exists( 'permit_employer' ) ) {
	fwrite( STDERR, "Erro: o post type 'permit_employer' não está registrado. Ative o plugin conexao-data-model (>= 1.5.0).\n" );
	exit( 1 );
}

/**
 * Employer definitions.
 *
 * 'sector'/'roles'/'location' are free text shown on the card. 'permit'
 * is the normalized status consumed by conexao_permit_employer_status()
 * (verified | unverified | exception). 'source'/'notes' are admin-only.
 *
 * @return array[]
 */
function conexao_seed_permit_employers() {
	$source = 'DETE — Permits issued to companies (enterprise.gov.ie, publicações 2023, 2024 e 2025)';

	return array(
		array( 'name' => 'Mowlam Healthcare', 'slug' => 'mowlam-healthcare', 'sector' => 'Saúde e Cuidados', 'roles' => 'Enfermeiros, Healthcare Assistants', 'location' => 'Nacional (Irlanda)', 'website' => 'https://mowlamhealthcare.com/', 'careers' => 'https://mowlamhealthcare.com/careers/', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Mowlam Healthcare Services Unlimited Company.' ),
		array( 'name' => 'Resilience Healthcare', 'slug' => 'resilience-healthcare', 'sector' => 'Saúde e Cuidados', 'roles' => 'Healthcare Assistants, Enfermeiros, Equipe de apoio', 'location' => 'Nacional (Irlanda)', 'website' => 'https://resiliencecare.ie/', 'careers' => '', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Resilience Healthcare Ltd / Limited. Site oficial em resiliencecare.ie.' ),
		array( 'name' => 'InisCare', 'slug' => 'iniscare', 'sector' => 'Cuidados Domiciliários', 'roles' => 'Cuidadores domiciliários (Home Carers)', 'location' => 'Nacional (Irlanda)', 'website' => 'https://www.iniscare.ie/', 'careers' => 'https://iniscare.ie/new-job/', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: InisCare Limited.' ),
		array( 'name' => 'UL Hospitals Group (HSE Mid West)', 'slug' => 'ul-hospitals-group', 'sector' => 'Saúde pública (HSE)', 'roles' => 'Enfermeiros, Profissionais de saúde', 'location' => 'Limerick', 'website' => 'https://www.hse.ie/eng/region/midwest/', 'careers' => '', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: University Limerick Hospitals Group. Site público é a página do HSE Mid West.' ),
		array( 'name' => 'Cork University Hospital', 'slug' => 'cork-university-hospital', 'sector' => 'Saúde pública (HSE)', 'roles' => 'Enfermeiros, Profissionais de saúde', 'location' => 'Cork', 'website' => 'https://cuh.hse.ie/', 'careers' => '', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Cork University Hospital.' ),
		array( 'name' => 'University Hospital Galway', 'slug' => 'university-hospital-galway', 'sector' => 'Saúde pública (HSE)', 'roles' => 'Enfermeiros, Profissionais de saúde', 'location' => 'Galway', 'website' => 'https://www.saolta.ie/', 'careers' => '', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Galway University Hospital. Site oficial do grupo é o Saolta University Health Care Group.' ),
		array( 'name' => 'Rosderra Irish Meats', 'slug' => 'rosderra-irish-meats', 'sector' => 'Alimentos — Processamento de Carne', 'roles' => 'Operadores de produção, Processamento de alimentos', 'location' => 'Nacional (Irlanda)', 'website' => 'https://www.rosderra.ie/', 'careers' => 'https://www.rosderra.ie/careers/', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Rosderra Irish Meats Group UC.' ),
		array( 'name' => 'Dawn Meats', 'slug' => 'dawn-meats', 'sector' => 'Alimentos — Processamento de Carne', 'roles' => 'Operadores de produção, Processamento de alimentos', 'location' => 'Nacional (Irlanda)', 'website' => 'https://www.dawnmeats.com/', 'careers' => 'https://www.dawnmeats.com/careers', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Dawn Meats Ireland UC.' ),
		array( 'name' => 'ABP Food Group', 'slug' => 'abp-food-group', 'sector' => 'Alimentos — Processamento de Carne', 'roles' => 'Operadores de produção, Processamento de alimentos', 'location' => 'Nacional (Irlanda)', 'website' => 'https://abpfoodgroup.com/', 'careers' => 'https://abpfoodgroup.com/careers/', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Anglo Beef Processors Ireland Unlimited Company (e variações). Nome público: ABP Food Group.' ),
		array( 'name' => 'Monaghan Mushrooms', 'slug' => 'monaghan-mushrooms', 'sector' => 'Agroalimentar — Cogumelos', 'roles' => 'Operadores de produção, Colheita e processamento', 'location' => 'Monaghan; Nacional (Irlanda)', 'website' => 'https://www.monaghan.eu/', 'careers' => 'https://www.monaghan.eu/careers/', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Monaghan Mushrooms Ireland UC. Site oficial atual é monaghan.eu (monaghanmushrooms.com não está mais ativo).' ),
		array( 'name' => 'Liffey Meats', 'slug' => 'liffey-meats', 'sector' => 'Alimentos — Processamento de Carne', 'roles' => 'Operadores de produção, Processamento de alimentos', 'location' => 'Nacional (Irlanda)', 'website' => 'https://liffeymeats.ie/', 'careers' => '', 'permit' => 'verified', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Nome público confirmado: "Liffey Meats" (título do site). Entidade DETE: Liffey Meats (Cavan) Unlimited Company. Sem página de carreiras dedicada encontrada — o card não mostra link de vagas.' ),
		// Kepak: valid employer resource, NO permit-history badge yet — the
		// exact DETE legal entity has not been matched and validated.
		array( 'name' => 'Kepak', 'slug' => 'kepak', 'sector' => 'Alimentos — Processamento de Carne', 'roles' => 'Operadores de produção, Processamento de alimentos', 'location' => 'Nacional (Irlanda)', 'website' => 'https://www.kepak.com/', 'careers' => 'https://www.kepak.com/careers/', 'permit' => 'unverified', 'years' => '', 'source' => '', 'notes' => 'Pendente: o DETE lista Kepak Clonee/Cork/Athleague/Longford/Kilbeggan UC (2023–2025), mas a entidade legal exata ainda não foi casada e validada. SEM selo de histórico no frontend até validar; não escrever "patrocina" em hipótese alguma.' ),
		// Nua Healthcare: historical DETE evidence, but the company states it
		// is NOT currently recruiting internationally or sponsoring GEPs.
		// Rendered in the "Importante" exceptions block only.
		array( 'name' => 'Nua Healthcare', 'slug' => 'nua-healthcare', 'sector' => 'Saúde e Cuidados', 'roles' => '', 'location' => 'Nacional (Irlanda)', 'website' => 'https://www.nuahealthcare.ie/', 'careers' => 'https://www.nuahealthcare.ie/careers/', 'permit' => 'exception', 'years' => '2023–2025', 'source' => $source, 'notes' => 'Entidade DETE: Nua Healthcare Services (2023–2025). Segundo o site oficial da empresa, NÃO está recrutando internacionalmente nem patrocinando General Employment Permits no momento. NUNCA receber selo genérico de histórico; não sugerir contato para GEP.' ),
		// Farm Solutions is deliberately ABSENT: on HOLD until its
		// WRC/licensing status/applicability is manually resolved. Do not
		// describe it as unlicensed or illegitimate anywhere.
	);
}
// ─────────────── Runner ───────────────
echo "=== Seed Permit Employers ===\n";

$last_checked = '2026-09-02';

$employers = conexao_seed_permit_employers();
$created   = 0;
$updated   = 0;

foreach ( $employers as $employer ) {
	$existing = get_page_by_path( $employer['slug'], OBJECT, 'permit_employer' );
	$args = array(
		'post_type'    => 'permit_employer',
		'post_status'  => 'publish',
		'post_title'   => $employer['name'],
		'post_name'    => $employer['slug'],
		// The directory card renders ONLY the structured meta below; the
		// post body stays empty so free-text data never diverges from it.
		'post_content' => '',
	);
	if ( $existing ) {
		$args['ID'] = $existing->ID;
		$post_id = wp_update_post( $args );
		$updated++;
	} else {
		$post_id = wp_insert_post( $args );
		$created++;
	}
	if ( is_wp_error( $post_id ) ) {
		echo "  ERRO ao salvar {$employer['name']}: {$post_id->get_error_message()}\n";
		continue;
	}
	update_post_meta( $post_id, '_employer_sector',           $employer['sector'] );
	update_post_meta( $post_id, '_employer_roles',            $employer['roles'] );
	update_post_meta( $post_id, '_employer_location',         $employer['location'] );
	update_post_meta( $post_id, '_employer_official_website', $employer['website'] );
	update_post_meta( $post_id, '_employer_careers_url',      $employer['careers'] );
	update_post_meta( $post_id, '_employer_permit_status',    $employer['permit'] );
	update_post_meta( $post_id, '_employer_evidence_years',   $employer['years'] );
	update_post_meta( $post_id, '_employer_evidence_source',  $employer['source'] );
	update_post_meta( $post_id, '_employer_last_checked',     $last_checked );
	update_post_meta( $post_id, '_employer_status',           'published' );
	$status_labels = array(
		'verified'   => 'ok: historico verificado',
		'unverified' => '!! sem selo (pendente de validacao)',
		'exception'  => '!! excecao (bloco Importante)',
	);
	$label = isset( $status_labels[ $employer['permit'] ] ) ? $status_labels[ $employer['permit'] ] : $employer['permit'];
	echo "  - {$employer['name']} [{$label}]\n";
}

echo "\n=== Resumo ===\n";
echo 'Total:       ' . count( $employers ) . "\n";
echo "Criados:     {$created}\n";
echo "Atualizados: {$updated}\n";
