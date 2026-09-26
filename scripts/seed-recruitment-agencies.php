<?php
/**
 * Seeder for the curated recruitment-agency directory (Empregos).
 *
 * Usage:  php scripts/seed-recruitment-agencies.php
 * Output: a short report of created/updated records.
 *
 * IMPORTANT: verify every agency's data against the original research
 * document before running on production.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

if ( ! post_type_exists( 'recruitment_agency' ) ) {
	fwrite( STDERR, "Erro: o post type 'recruitment_agency' não está registrado. Ative o plugin conexao-data-model.\n" );
	exit( 1 );
}

/**
 * Agency definitions (27 agencies: original Top 15 + 12 validated in the
 * 2026-09 Employment page expansion — see
 * docs/research/2026-09-empregos-agencies-and-employment-permits.md).
 *
 * Data source: the agency research document supplied with the directory task,
 * re-verified against each agency's own official website (2026-09-02): title,
 * contact page and phone/location where published. Only values present in
 * that verification are stored here.
 *
 * 'job_types' holds comma-separated CANONICAL keys from
 * Conexao_Data_Model_Agency::job_types(). Research terms without a canonical
 * equivalent (e.g. "engenharia", "TI") are deliberately NOT stored — the card
 * only shows the site's standardized labels. The 'healthcare' key was added
 * to the registry for this expansion (TTM, Servisource, Cpl, Access
 * Healthcare, Hollilander all recruit healthcare professionals).
 *
 * 'location': nationwide agencies are stored as "Nacional"; multi-location
 * agencies use a concise comma-separated list of locations confirmed on the
 * agency's own site.
 *
 * 'wrc': only populated where the research explicitly provides the licence
 * number; left empty otherwise (never guessed). NONE of the 12 agencies
 * added in the 2026-09 expansion have verified WRC numbers, so none show
 * the "Licenciada" badge until one is confirmed.
 *
 * 'order': 16–27 continue after the original Top 15.
 *
 * @return array[]
 */
function conexao_seed_agencies() {
	return array(
		array( 'name' => 'InSource Recruitment', 'slug' => 'insource-recruitment', 'job_types' => 'logistics,warehouse,general_operative,cleaning,hospitality', 'location' => 'Nacional', 'website' => 'https://www.insource.ie/', 'phone' => '+353 86 028 6985', 'temporary' => true, 'permanent' => true, 'order' => 1, 'wrc' => 'EA 3972' ),
		array( 'name' => 'Flexsource', 'slug' => 'flexsource', 'job_types' => 'warehouse,factory_production,logistics,hospitality,construction_labour,retail', 'location' => 'Nacional', 'website' => 'https://www.flexsource.ie/', 'phone' => '+353 1 895 5700', 'temporary' => true, 'permanent' => true, 'order' => 2 ),
		array( 'name' => 'Team Obair', 'slug' => 'team-obair', 'job_types' => 'warehouse,logistics,factory_production,general_operative', 'location' => 'Dublin', 'website' => 'https://teamobair.com/', 'phone' => '+353 1 453 6722', 'temporary' => true, 'permanent' => true, 'order' => 3 ),
		array( 'name' => 'Noel Group', 'slug' => 'noel-group', 'job_types' => 'general_operative,factory_production,logistics,warehouse,construction_labour,hospitality', 'location' => 'Dublin, Limerick, Cork, Waterford, Galway, Naas', 'website' => 'https://noelgroup.ie/', 'phone' => '+353 1 677 9332', 'temporary' => true, 'permanent' => true, 'order' => 4 ),
		array( 'name' => 'Total Solutions', 'slug' => 'total-solutions', 'job_types' => 'construction_labour,warehouse,hospitality,factory_production,logistics,office_admin', 'location' => 'Nacional', 'website' => 'https://totalsolutions.ie/', 'phone' => '+353 1 628 3610', 'temporary' => true, 'permanent' => true, 'order' => 5 ),
		array( 'name' => 'Staffline Recruitment', 'slug' => 'staffline-recruitment', 'job_types' => 'factory_production,logistics,warehouse,hospitality,construction_labour,retail,office_admin', 'location' => 'Nacional', 'website' => 'https://www.staffline.ie/', 'phone' => '+353 1 890 0190', 'temporary' => true, 'permanent' => true, 'order' => 6 ),
		array( 'name' => 'Excel Recruitment', 'slug' => 'excel-recruitment', 'job_types' => 'hospitality,warehouse,retail,logistics,construction_labour,factory_production', 'location' => 'Dublin, Cork, Naas, Galway, Belfast', 'website' => 'https://www.excelrecruitment.com/', 'phone' => '+353 1 871 7676', 'temporary' => true, 'permanent' => true, 'order' => 7 ),
		array( 'name' => 'CREGG', 'slug' => 'cregg', 'job_types' => 'factory_production', 'location' => 'Shannon, Galway, Limerick, Cork, Dublin, Kilkenny, Roscommon', 'website' => 'https://www.cregg.ie/', 'phone' => '+353 61 363 318', 'temporary' => true, 'permanent' => true, 'order' => 8 ),
		array( 'name' => 'FRS Recruitment', 'slug' => 'frs-recruitment', 'job_types' => 'factory_production,construction_labour,agriculture_seasonal', 'location' => 'Dublin, Cork, Galway, Limerick, Kilkenny, Cavan, Kerry, Portlaoise', 'website' => 'https://www.frsrecruitment.com/', 'phone' => '0818 890 890', 'temporary' => true, 'permanent' => true, 'order' => 9 ),
		array( 'name' => 'PE Global', 'slug' => 'pe-global', 'job_types' => 'factory_production,construction_labour', 'location' => 'Nacional', 'website' => 'https://www.peglobal.net/', 'phone' => '+353 21 429 7900', 'temporary' => true, 'permanent' => true, 'order' => 10 ),
		array( 'name' => 'Matrix Recruitment', 'slug' => 'matrix-recruitment', 'job_types' => 'factory_production,office_admin,logistics', 'location' => 'Waterford, Carlow, Athlone, Dublin', 'website' => 'https://matrixrecruitment.ie/', 'phone' => '+353 51 353 825', 'temporary' => true, 'permanent' => true, 'order' => 11 ),
		array( 'name' => 'FlexiStaff', 'slug' => 'flexistaff', 'job_types' => 'logistics,warehouse,factory_production,construction_labour,retail', 'location' => 'Nacional', 'website' => 'https://flexistaff.ie/', 'phone' => '+353 1 687 6461', 'temporary' => true, 'permanent' => true, 'order' => 12 ),
		array( 'name' => 'RecruitmentPlus', 'slug' => 'recruitmentplus', 'job_types' => 'office_admin,hospitality,logistics,general_operative', 'location' => 'Deansgrange, Co. Dublin; Dundalk, Co. Louth', 'website' => 'https://www.recruitmentplus.ie/', 'phone' => '+353 1 278 8610', 'temporary' => true, 'permanent' => true, 'order' => 13 ),
		array( 'name' => 'Gi Group Ireland', 'slug' => 'gi-group-ireland', 'job_types' => 'construction_labour,office_admin', 'location' => 'Cork, Galway', 'website' => 'https://ie.gigroup.com/', 'phone' => '+353 21 427 4700', 'temporary' => true, 'permanent' => true, 'order' => 14 ),
		array( 'name' => 'MCR Personnel', 'slug' => 'mcr-personnel', 'job_types' => 'construction_labour,general_operative,factory_production,warehouse,cleaning', 'location' => 'Nacional', 'website' => 'https://mcrgroup.ie/personnel/', 'phone' => '+353 1 889 9100', 'temporary' => true, 'permanent' => true, 'order' => 15 ),
		// --- 2026-09 validated expansion (orders 16-27) ---
		array( 'name' => 'TTM Healthcare Solutions', 'slug' => 'ttm-healthcare-solutions', 'job_types' => 'healthcare', 'location' => 'Nacional (Ennis, Co. Clare; Galway)', 'website' => 'https://www.ttmhealthcare.com/', 'phone' => '+353 65 686 9300', 'temporary' => true, 'permanent' => true, 'order' => 16 ),
		array( 'name' => 'Servisource', 'slug' => 'servisource', 'job_types' => 'healthcare', 'location' => 'Nacional', 'website' => 'https://www.servisource.ie/', 'phone' => '', 'temporary' => true, 'permanent' => true, 'order' => 17 ),
		array( 'name' => 'Cpl', 'slug' => 'cpl', 'job_types' => 'healthcare,office_admin,warehouse,logistics,factory_production', 'location' => 'Nacional (Dublin)', 'website' => 'https://www.cpl.com/', 'phone' => '+353 1 614 6000', 'temporary' => true, 'permanent' => true, 'order' => 18 ),
		array( 'name' => 'Access Healthcare', 'slug' => 'access-healthcare', 'job_types' => 'healthcare', 'location' => 'Nacional (Dublin)', 'website' => 'https://www.accesshealthcare.ie/', 'phone' => '+353 1 649 8500', 'temporary' => true, 'permanent' => true, 'order' => 19 ),
		array( 'name' => 'Gibbons Recruitment', 'slug' => 'gibbons-recruitment', 'job_types' => 'construction_labour,factory_production,warehouse,agriculture_seasonal', 'location' => 'Dublin', 'website' => 'https://gibbonsrecruitment.ie/', 'phone' => '', 'temporary' => true, 'permanent' => true, 'order' => 20 ),
		array( 'name' => 'Ward Personnel', 'slug' => 'ward-personnel', 'job_types' => 'construction_labour,general_operative', 'location' => 'Dublin, Cork, Athlone', 'website' => 'https://www.wardpersonnel.com/', 'phone' => '+353 1 539 0600', 'temporary' => true, 'permanent' => true, 'order' => 21 ),
		array( 'name' => 'OSS Recruitment', 'slug' => 'oss-recruitment', 'job_types' => 'construction_labour,factory_production,general_operative', 'location' => 'Dublin', 'website' => 'https://www.ossrecruitment.ie/', 'phone' => '+353 1 460 5517', 'temporary' => true, 'permanent' => true, 'order' => 22 ),
		array( 'name' => 'Hollilander', 'slug' => 'hollilander', 'job_types' => 'healthcare', 'location' => 'Dublin', 'website' => 'https://www.hollilander.ie/', 'phone' => '+353 1 204 0921', 'temporary' => true, 'permanent' => true, 'order' => 23 ),
		array( 'name' => 'Adecco Ireland', 'slug' => 'adecco-ireland', 'job_types' => 'warehouse,logistics,office_admin,factory_production,hospitality', 'location' => 'Nacional', 'website' => 'https://www.adecco.ie/', 'phone' => '', 'temporary' => true, 'permanent' => true, 'order' => 24 ),
		array( 'name' => '3D Personnel', 'slug' => '3d-personnel', 'job_types' => 'construction_labour', 'location' => 'Dublin, Cork, Galway', 'website' => 'https://www.3dpersonnel.com/', 'phone' => '+353 1 513 3101', 'temporary' => true, 'permanent' => true, 'order' => 25 ),
		array( 'name' => 'Collins McNicholas', 'slug' => 'collins-mcnicholas', 'job_types' => 'factory_production,office_admin,logistics', 'location' => 'Galway, Cork, Sligo, Athlone', 'website' => 'https://www.collinsmcnicholas.ie/', 'phone' => '', 'temporary' => true, 'permanent' => true, 'order' => 26 ),
		array( 'name' => 'Sigmar Recruitment', 'slug' => 'sigmar-recruitment', 'job_types' => 'office_admin,warehouse,logistics,factory_production', 'location' => 'Galway, Dublin, Cork, Athlone', 'website' => 'https://www.sigmarrecruitment.com/', 'phone' => '+353 1 474 4600', 'temporary' => true, 'permanent' => true, 'order' => 27 ),
	);
}
// ─────────────── Runner ───────────────
echo "=== Seed Recruitment Agencies ===\n";

// Initial "Last checked" date — when the agency data was verified against
// the research document (not a guarantee of current vacancies).
$last_checked = '2026-09-02';

$agencies = conexao_seed_agencies();
$created  = 0;
$updated  = 0;

foreach ( $agencies as $agency ) {
	$existing = get_page_by_path( $agency['slug'], OBJECT, 'recruitment_agency' );
	$args = array(
		'post_type'    => 'recruitment_agency',
		'post_status'  => 'publish',
		'post_title'   => $agency['name'],
		'post_name'    => $agency['slug'],
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
		echo "  ERRO ao salvar {$agency['name']}: {$post_id->get_error_message()}\n";
		continue;
	}
	update_post_meta( $post_id, '_agency_job_types', $agency['job_types'] );
	update_post_meta( $post_id, '_agency_location',  $agency['location'] );
	update_post_meta( $post_id, '_agency_website',   $agency['website'] );
	update_post_meta( $post_id, '_agency_phone',     $agency['phone'] );
	update_post_meta( $post_id, '_agency_temporary', $agency['temporary'] ? '1' : '0' );
	update_post_meta( $post_id, '_agency_permanent', $agency['permanent'] ? '1' : '0' );
	update_post_meta( $post_id, '_agency_order',     $agency['order'] );
	update_post_meta( $post_id, '_agency_status',    'published' );
	// Initial import date — records when the agency data was last verified
	// against the research document (not a guarantee of current vacancies).
	update_post_meta( $post_id, '_agency_last_checked', $last_checked );
	if ( ! empty( $agency['wrc'] ) ) {
		update_post_meta( $post_id, '_agency_wrc_licence', $agency['wrc'] );
	} else {
		// No licence in the research: never carry over a stale value.
		delete_post_meta( $post_id, '_agency_wrc_licence' );
	}
	$wrc_label = ! empty( $agency['wrc'] ) ? "WRC: {$agency['wrc']}" : 'sem WRC';
	echo "  - {$agency['name']} [{$agency['location']}] {$wrc_label}\n";
}

echo "\n=== Resumo ===\n";
echo "Total:      " . count( $agencies ) . "\n";
echo "Criadas:    {$created}\n";
echo "Atualizadas: {$updated}\n";

// Ensure /empregos/ page uses the right template.
$empregos_page = get_page_by_path( 'empregos', OBJECT, 'page' );
if ( $empregos_page && 'page-empregos.php' !== get_page_template_slug( $empregos_page->ID ) ) {
	update_post_meta( $empregos_page->ID, '_wp_page_template', 'page-empregos.php' );
	echo "\nTemplate /empregos/ atualizado para page-empregos.php.\n";
}