<?php
/**
 * Stage 8 — EN Jobs page: full bilingual-content standard.
 *
 * `/en/jobs/` must be a genuine English presentation of the SAME data that
 * `/empregos/` shows in Portuguese. This suite asserts the language contract
 * of every user-facing Jobs string at its real source:
 *
 *  - the EN Jobs landing Page carries the authored English body (the real
 *    translation record — never a template hardcode) and the PT page is
 *    byte-identical to the importer's snapshot;
 *  - the work-area registry resolves English labels on the EN language and
 *    the exact Portuguese labels on the default language, from ONE registry
 *    (no duplicate terms, no renamed slugs/keys);
 *  - generic location words are language-aware while real Irish place names
 *    are returned byte-identical;
 *  - the Employment-Permit employer descriptors (sector / roles / location)
 *    are language-aware and fall back to the stored value when unknown;
 *  - the date format follows the requested language (no `25 de August`);
 *  - the read time is language-aware with correct pluralization;
 *  - internal filter values (?area=/?localizacao=/?contrato=/?tipo=) are
 *    stable slugs and are never translated.
 *
 * Read-only: this test never creates or modifies content.
 *
 * Usage:
 *   docker compose exec -T wordpress php \
 *     /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-jobs-en-language.php
 *
 * @package conexao-br-irlanda
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/translation-map.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/apply.php';

$passed = 0;
$failed = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message.
 * @param string $detail    Extra detail on failure.
 * @return void
 */
function s8_assert( $condition, $message, $detail = '' ) {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  PASS: {$message}\n";
		return;
	}

	++$failed;
	echo "  FAIL: {$message}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

/**
 * Run a callback with the request language context set to $slug.
 *
 * The theme answers "which language is this request?" through Polylang's
 * current-language object, NOT through the WP locale alone — the same pattern
 * the existing Stage 3.3 / Stage 5 / Stage 7 suites use
 * (test-stage33-bilingual.php, test-leisure-card-excerpt-language.php). The
 * locale is switched too, because that is what gettext consults, so both
 * halves of the language context (Polylang language + gettext locale) move
 * together exactly as they do on a real /en/ request.
 *
 * The explicit `locale` pin matters in CLI: Polylang's own frontend locale
 * filter only runs inside a real routed request, so the Stage-1
 * "an unconfigured en_US install is really pt_BR" correction (inc/i18n.php)
 * would otherwise rewrite en_US straight back to pt_BR and every date would
 * render Portuguese. Pinning the locale reproduces exactly what the live
 * /en/ request resolves, instead of a CLI-only approximation of it.
 *
 * @param string   $slug     Language slug ('en', 'pt').
 * @param callable $callback Code to run in that context.
 * @return mixed
 */
function s8_in_language( string $slug, callable $callback ) {
	$pll        = function_exists( 'PLL' ) ? PLL() : null;
	$previous   = isset( $pll->curlang->slug ) ? $pll->curlang->slug : false;
	$prev_locale = get_locale();
	$target     = 'en' === $slug ? 'en_US' : $prev_locale;

	if ( $pll && $pll->model ) {
		$pll->curlang = $pll->model->get_language( $slug );
	}

	$pin = static function () use ( $target ) {
		return $target;
	};
	add_filter( 'locale', $pin, 1 );
	switch_to_locale( $target );

	try {
		$result = $callback();
	} finally {
		restore_previous_locale();
		remove_filter( 'locale', $pin, 1 );
		if ( $pll && false !== $previous ) {
			$pll->curlang = $pll->model->get_language( $previous );
		}
	}

	return $result;
}

echo "== Stage 8 — EN Jobs page language standard ==\n";

if ( ! function_exists( 'pll_get_post' ) ) {
	echo "  SKIP: Polylang is not active.\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
echo "\n-- 1. EN Jobs landing page carries real English content --\n";

$pt_page = get_page_by_path( 'empregos', OBJECT, 'page' );
$en_id   = $pt_page ? (int) pll_get_post( (int) $pt_page->ID, 'en' ) : 0;

s8_assert( $pt_page instanceof WP_Post, 'the PT Jobs landing page exists' );
s8_assert( $en_id > 0 && $en_id !== (int) $pt_page->ID, 'the Jobs page has an EN translation', "en_id={$en_id}" );
s8_assert( $en_id > 0 && (int) pll_get_post( $en_id, 'pt' ) === (int) $pt_page->ID, 'the Jobs page pair is linked from both sides' );
s8_assert( $en_id > 0 && 'publish' === get_post_status( $en_id ), 'the EN Jobs page is published' );

$manifest    = conexao_page_translation_map();
$jobs_spec   = $manifest['empregos'] ?? array();
$en_post     = $en_id ? get_post( $en_id ) : null;
$en_content  = $en_post ? $en_post->post_content : '';
$en_rendered = $en_id ? trim( wp_strip_all_tags( $en_content ) ) : '';

s8_assert( 'jobs' === ( $jobs_spec['en_slug'] ?? '' ), "the manifest owns the EN Jobs slug 'jobs'" );
s8_assert(
	$en_id > 0 && $en_post->post_title === $jobs_spec['title'],
	'the EN Jobs page title is the authored English title',
	$en_id ? $en_post->post_title : ''
);
s8_assert(
	$en_id > 0 && false !== strpos( $en_content, $jobs_spec['content'] ),
	'the EN Jobs body is the authored English body from the manifest (not a template hardcode)'
);
s8_assert( $en_id > 0 && '' !== $en_rendered, 'the EN Jobs body is not empty' );
s8_assert(
	$en_id > 0 && false === strpos( $en_rendered, 'As vagas mais recentes' )
		&& false === strpos( $en_rendered, 'Acompanhe nossas' ),
	'no Portuguese prose from the PT body survives on the EN Jobs page',
	$en_rendered
);
s8_assert(
	$en_id > 0 && '' !== (string) get_post_meta( $en_id, 'conexao_meta_description', true ),
	'the EN Jobs page has an English meta description'
);
s8_assert(
	$en_id > 0 && false === strpos( (string) get_post_meta( $en_id, 'conexao_meta_description', true ), 'vagas' ),
	'the EN Jobs meta description is not the Portuguese one'
);

// The Portuguese source must be untouched by the English layer.
$pt_now = conexao_page_translation_snapshot_page( (int) $pt_page->ID );
s8_assert(
	'Empregos' === $pt_now['title'] && false !== strpos( $pt_now['content'], 'As vagas mais recentes' ),
	'the PT Jobs page content is unchanged (Portuguese source preserved)'
);
s8_assert( 'page-empregos.php' === $pt_now['template'], 'the PT Jobs page keeps its template' );
s8_assert(
	(int) get_post_thumbnail_id( (int) $pt_page->ID ) === (int) get_post_thumbnail_id( $en_id ),
	'the EN Jobs page shares the PT featured media (no duplicated attachment)'
);


// ---------------------------------------------------------------------------
echo "\n-- 2. Work-area registry: one source, language-aware labels --\n";

$expected_areas = array(
	'warehouse'            => array( 'pt' => 'Armazém', 'en' => 'Warehouse' ),
	'general_operative'    => array( 'pt' => 'Operacional Geral', 'en' => 'General Operative' ),
	'factory_production'   => array( 'pt' => 'Fábrica / Produção', 'en' => 'Factory / Production' ),
	'logistics'            => array( 'pt' => 'Logística', 'en' => 'Logistics' ),
	'hospitality'          => array( 'pt' => 'Hotelaria', 'en' => 'Hospitality' ),
	'cleaning'             => array( 'pt' => 'Limpeza', 'en' => 'Cleaning' ),
	'retail'               => array( 'pt' => 'Varejo', 'en' => 'Retail' ),
	'construction_labour'  => array( 'pt' => 'Construção Civil', 'en' => 'Construction' ),
	'office_admin'         => array( 'pt' => 'Escritório / Administrativo', 'en' => 'Office / Administration' ),
	'agriculture_seasonal' => array( 'pt' => 'Agricultura / Sazonal', 'en' => 'Agriculture / Seasonal' ),
	'healthcare'           => array( 'pt' => 'Saúde / Cuidados', 'en' => 'Healthcare / Care' ),
);

switch_to_locale( 'en_US' );
$en_areas = conexao_recruitment_agency_areas();
restore_previous_locale();
$pt_areas = conexao_recruitment_agency_areas();

foreach ( $expected_areas as $key => $labels ) {
	s8_assert(
		isset( $en_areas[ $key ] ) && $en_areas[ $key ] === $labels['en'],
		"work area '{$key}' renders '{$labels['en']}' in English",
		$en_areas[ $key ] ?? '(missing)'
	);
	s8_assert(
		isset( $pt_areas[ $key ] ) && $pt_areas[ $key ] === $labels['pt'],
		"work area '{$key}' still renders '{$labels['pt']}' in Portuguese",
		$pt_areas[ $key ] ?? '(missing)'
	);
}

// The filter slugs are the language-neutral identity and must never change.
s8_assert(
	array_keys( $en_areas ) === array_keys( $pt_areas ),
	'the work-area keys are identical in both languages (one registry, no duplicates)'
);
s8_assert(
	array_key_exists( 'warehouse', $en_areas ) && ! array_key_exists( 'armazem', $en_areas ),
	'no translated/suffixed work-area key was introduced (?area=warehouse stays valid)'
);

// Card labels use the same registry as the filter.
s8_assert(
	conexao_recruitment_agency_job_type_labels( 'warehouse,logistics' ) === array( 'Armazém', 'Logística' ),
	'job-type card labels keep the Portuguese source strings by default',
	implode( ', ', conexao_recruitment_agency_job_type_labels( 'warehouse,logistics' ) )
);
s8_assert(
	array( 'Desconhecido' ) === conexao_recruitment_agency_job_type_labels( 'Desconhecido' ),
	'an unknown legacy job-type value still passes through unchanged'
);

// ---------------------------------------------------------------------------
echo "\n-- 3. Location values: generic words localized, place names untouched --\n";

s8_assert(
	'Nacional' === conexao_recruitment_agency_location_display( 'Nacional' ),
	'"Nacional" is unchanged in Portuguese',
	conexao_recruitment_agency_location_display( 'Nacional' )
);
s8_assert(
	'Nacional (Dublin)' === conexao_recruitment_agency_location_display( 'Nacional (Dublin)' ),
	'"Nacional (Dublin)" keeps its parenthetical place name in Portuguese',
	conexao_recruitment_agency_location_display( 'Nacional (Dublin)' )
);
s8_assert(
	'Dublin, Cork, Athlone' === conexao_recruitment_agency_location_display( 'Dublin, Cork, Athlone' ),
	'a pure place-name coverage string is returned byte-identical',
	conexao_recruitment_agency_location_display( 'Dublin, Cork, Athlone' )
);
s8_assert(
	'Deansgrange, Co. Dublin; Dundalk, Co. Louth' === conexao_recruitment_agency_location_display( 'Deansgrange, Co. Dublin; Dundalk, Co. Louth' ),
	'an address-style coverage string is returned byte-identical'
);
s8_assert( '' === conexao_recruitment_agency_location_display( '   ' ), 'an empty coverage string stays empty' );
s8_assert(
	'Local-desconhecido' === conexao_recruitment_agency_location_display( 'Local-desconhecido' ),
	'an unknown coverage value is never dropped or mangled'
);

switch_to_locale( 'en_US' );
$en_locations      = conexao_recruitment_agency_locations();
$en_location_label = conexao_recruitment_agency_location_display( 'Nacional (Irlanda)' );
restore_previous_locale();
$pt_locations = conexao_recruitment_agency_locations();

s8_assert(
	'Nationwide' === ( $en_locations['nacional']['label'] ?? '' ),
	'the nationwide location option label is English on the EN page',
	$en_locations['nacional']['label'] ?? '(missing)'
);
s8_assert(
	'Nacional' === ( $pt_locations['nacional']['label'] ?? '' ),
	'the nationwide location option label is unchanged in Portuguese',
	$pt_locations['nacional']['label'] ?? '(missing)'
);
s8_assert( 'Nationwide (Ireland)' === $en_location_label, '"Nacional (Irlanda)" is fully English on the EN page', $en_location_label );
s8_assert(
	'Nacional (Irlanda)' === conexao_recruitment_agency_location_display( 'Nacional (Irlanda)' ),
	'the Portuguese coverage string is unchanged'
);

// Real Irish place names are language-neutral: same label in both languages.
$place_slugs = array( 'dublin', 'cork', 'galway', 'limerick', 'waterford', 'naas', 'athlone', 'sligo', 'carlow', 'kilkenny', 'portlaoise', 'shannon', 'cavan', 'kerry', 'roscommon', 'dundalk' );
$place_ok     = true;
$place_detail = '';
foreach ( $place_slugs as $slug ) {
	switch_to_locale( 'en_US' );
	$en_label = $en_locations[ $slug ]['label'] ?? '';
	restore_previous_locale();
	$pt_label = $pt_locations[ $slug ]['label'] ?? '';
	if ( $en_label !== $pt_label || '' === $en_label ) {
		$place_ok     = false;
		$place_detail = "{$slug}: en='{$en_label}' pt='{$pt_label}'";
		break;
	}
}
s8_assert( $place_ok, 'every real Irish place name is identical in both languages', $place_detail );

// Filter slugs stay canonical.
s8_assert(
	array( 'nacional', 'dublin' ) === conexao_recruitment_agency_location_slugs( 'Nacional, Dublin' ),
	'location filter slugs are unchanged (?localizacao=nacional|dublin)'
);

// ---------------------------------------------------------------------------
echo "\n-- 4. Employment-Permit employer descriptors --\n";

$employer_ids = get_posts(
	array(
		'post_type'      => 'permit_employer',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'lang'           => '',
		'fields'         => 'ids',
	)
);

s8_assert( ! empty( $employer_ids ), 'the install has Employment-Permit employer records to audit' );

$pt_sectors   = array();
$en_sectors   = array();
$pt_roles_all = array();
$en_roles_all = array();
$en_locs      = array();
$stored_sectors = array();
$stored_roles   = array();
$stored_locs    = array();

foreach ( $employer_ids as $employer_id ) {
	$employer = get_post( $employer_id );

	$stored_sectors[] = conexao_permit_employer_meta( $employer, '_employer_sector' );
	$stored_locs[]    = conexao_permit_employer_meta( $employer, '_employer_location' );
	foreach ( conexao_permit_employer_roles( $employer ) as $role ) {
		$stored_roles[] = $role;
	}

	$pt_sectors[] = conexao_permit_employer_sector_display( $employer );
	foreach ( conexao_permit_employer_roles_display( $employer ) as $role ) {
		$pt_roles_all[] = $role;
	}

	switch_to_locale( 'en_US' );
	$en_sectors[] = conexao_permit_employer_sector_display( $employer );
	foreach ( conexao_permit_employer_roles_display( $employer ) as $role ) {
		$en_roles_all[] = $role;
	}
	$en_locs[] = conexao_recruitment_agency_location_display( conexao_permit_employer_meta( $employer, '_employer_location' ) );
	restore_previous_locale();
}

$unique = static function ( $values ) {
	return implode( ' | ', array_unique( array_filter( $values ) ) );
};

$pt_sector_text = $unique( $pt_sectors );
$en_sector_text = $unique( $en_sectors );
$pt_role_text   = $unique( $pt_roles_all );
$en_role_text   = $unique( $en_roles_all );
$en_loc_text    = $unique( $en_locs );

s8_assert( '' !== $pt_sector_text, 'employer sectors are stored', $pt_sector_text );
s8_assert( $en_sector_text !== $pt_sector_text, 'employer sectors are language-aware', "en={$en_sector_text}" );
s8_assert( false === strpos( $en_sector_text, 'Saúde' ), 'no Portuguese sector text leaks into the English view', $en_sector_text );
s8_assert( false === strpos( $en_role_text, 'Enfermeiros' ), 'no Portuguese role text leaks into the English view', $en_role_text );
s8_assert( false === strpos( $en_loc_text, 'Nacional' ), 'no generic Portuguese location word leaks into the English view', $en_loc_text );
s8_assert( false === strpos( $en_loc_text, 'Irlanda' ), 'the country exonym is not left Portuguese in the English view', $en_loc_text );

// Portuguese is byte-identical to the stored data.
s8_assert( $pt_sector_text === $unique( $stored_sectors ), 'Portuguese employer sectors are exactly the stored values (source data unchanged)' );
s8_assert( $pt_role_text === $unique( $stored_roles ), 'Portuguese employer roles are exactly the stored values (source data unchanged)' );


// ---------------------------------------------------------------------------
echo "\n-- 5. Dates follow the requested language --\n";

$site_format = get_option( 'date_format' );

$pt_filtered = conexao_localized_date_format( $site_format );
s8_assert( $site_format === $pt_filtered, 'the date format is untouched for the default language', "{$site_format} vs {$pt_filtered}" );

$en_filtered = s8_in_language(
	'en',
	static function () use ( $site_format ) {
		return conexao_localized_date_format( $site_format );
	}
);

s8_assert( 'F j, Y' === $en_filtered, 'the date format is English on a non-default language', $en_filtered );
s8_assert( false === strpos( $en_filtered, '\d\e' ), 'no Portuguese "de" connector survives in the English date format', $en_filtered );

$timestamp = strtotime( '2026-08-25 12:00:00' );
s8_assert(
	'25 de agosto de 2026' === wp_date( $site_format, $timestamp ),
	'the Portuguese date presentation is preserved',
	wp_date( $site_format, $timestamp )
);

// The RENDERED date is asserted end-to-end over HTTP by
// scripts/jobs-en-language-verify.py, which is where the user-level contract
// lives. It is deliberately not asserted here: in a bare CLI process
// switch_to_locale() does not rebuild the WP_Locale month table on this WordPress
// build, so an in-process date would measure the harness rather than the page.
// What IS asserted here is the source-level contract the theme owns: the FORMAT
// resolves per language, the default language is byte-identical, and no
// Portuguese connector survives the English resolution.

// ---------------------------------------------------------------------------
echo "\n-- 6. Read time is language-aware --\n";

switch_to_locale( 'en_US' );
$en_read      = conexao_reading_time_text();
$catalog_one  = sprintf( _n( '%d minute read', '%d minutes read', 1, 'conexao-br-irlanda' ), 1 );
$catalog_many = sprintf( _n( '%d minute read', '%d minutes read', 3, 'conexao-br-irlanda' ), 3 );
restore_previous_locale();
$pt_read = conexao_reading_time_text();

s8_assert( false !== strpos( $en_read, 'minute read' ), 'the English read time is "N minute(s) read"', $en_read );
s8_assert( false === strpos( $en_read, 'leitura' ), 'no Portuguese read-time text in English', $en_read );
s8_assert( false !== strpos( $pt_read, 'min de leitura' ), 'the Portuguese read time is unchanged', $pt_read );

// ---------------------------------------------------------------------------
echo "\n-- 7. Opportunity types / contract types / UI strings --\n";

switch_to_locale( 'en_US' );
$en_types = conexao_employment_opportunity_types();
$en_state = conexao_employment_opportunities_filter_state();
restore_previous_locale();
$pt_types = conexao_employment_opportunity_types();
$pt_state = conexao_employment_opportunities_filter_state();

s8_assert( 'Recruitment agency' === ( $en_types['agency'] ?? '' ), 'opportunity type: agency', $en_types['agency'] ?? '' );
s8_assert( 'Public sector' === ( $en_types['public_sector'] ?? '' ), 'opportunity type: public sector', $en_types['public_sector'] ?? '' );
s8_assert( 'Employment Permit history' === ( $en_types['permit_history'] ?? '' ), 'opportunity type: permit history', $en_types['permit_history'] ?? '' );
s8_assert( 'Agência de recrutamento' === ( $pt_types['agency'] ?? '' ), 'Portuguese opportunity type is unchanged', $pt_types['agency'] ?? '' );
s8_assert( 'Setor público' === ( $pt_types['public_sector'] ?? '' ), 'Portuguese public-sector type is unchanged', $pt_types['public_sector'] ?? '' );
s8_assert( 'Histórico de Employment Permits' === ( $pt_types['permit_history'] ?? '' ), 'Portuguese permit-history type is unchanged', $pt_types['permit_history'] ?? '' );

s8_assert( 'All' === ( $en_state['tipo_options'][''] ?? '' ), 'the "All" option is English', $en_state['tipo_options'][''] ?? '' );
s8_assert( 'Todas' === ( $pt_state['tipo_options'][''] ?? '' ), 'the Portuguese "Todas" option is unchanged', $pt_state['tipo_options'][''] ?? '' );
s8_assert( 'Temporary' === ( $en_state['contrato_options']['temporario'] ?? '' ), 'contract type: Temporary', $en_state['contrato_options']['temporario'] ?? '' );
s8_assert( 'Permanent' === ( $en_state['contrato_options']['permanente'] ?? '' ), 'contract type: Permanent', $en_state['contrato_options']['permanente'] ?? '' );
s8_assert( 'Temporário' === ( $pt_state['contrato_options']['temporario'] ?? '' ), 'Portuguese contract type is unchanged', $pt_state['contrato_options']['temporario'] ?? '' );
s8_assert( 'Permanente' === ( $pt_state['contrato_options']['permanente'] ?? '' ), 'Portuguese permanent contract is unchanged', $pt_state['contrato_options']['permanente'] ?? '' );

// The FILTER VALUES are the language-neutral identity and must not be translated.
s8_assert(
	array_key_exists( 'warehouse', $en_state['area_options'] )
		&& array_key_exists( 'temporario', $en_state['contrato_options'] )
		&& array_key_exists( 'nacional', $en_state['location_options'] ),
	'internal filter values (?area=warehouse, ?contrato=temporario, ?localizacao=nacional) are unchanged'
);
s8_assert(
	array_keys( $en_state['area_options'] ) === array_keys( $pt_state['area_options'] ),
	'the offered area VALUES are identical in both languages (only labels differ)'
);
s8_assert(
	array_keys( $en_state['location_options'] ) === array_keys( $pt_state['location_options'] ),
	'the offered location VALUES are identical in both languages (only labels differ)'
);
s8_assert(
	array_key_exists( 'agency', $en_state['tipo_options'] ) && array_key_exists( 'permit_history', $en_state['tipo_options'] ),
	'internal opportunity-type values (?tipo=agency|permit_history) are unchanged'
);
s8_assert(
	isset( $en_state['area_options']['warehouse'], $pt_state['area_options']['warehouse'] )
		&& $en_state['area_options']['warehouse'] !== $pt_state['area_options']['warehouse'],
	'the SAME work-area value carries a different LABEL per language (label vs value are separate)'
);

switch_to_locale( 'en_US' );
$count_en  = sprintf(
	/* translators: %s: number of opportunities. */
	_n( '%s oportunidade encontrada', '%s oportunidades encontradas', 43, 'conexao-br-irlanda' ),
	number_format_i18n( 43 )
);
$empty_en  = __( 'Nenhuma oportunidade encontrada com esses filtros.', 'conexao-br-irlanda' );
$no_loc_en = __( 'Nenhuma localização encontrada', 'conexao-br-irlanda' );
$clear_en  = __( 'Limpar', 'conexao-br-irlanda' );
$apply_en  = __( 'Mostrar resultados', 'conexao-br-irlanda' );
$filter_en = __( 'Filtrar', 'conexao-br-irlanda' );
$sheets_en = __( 'Filtros', 'conexao-br-irlanda' );
$warning_en = __( 'nunca pague por uma promessa de emprego, visto ou Employment Permit. Uma agência de recrutamento legítima não deve cobrar para encontrar emprego.', 'conexao-br-irlanda' );
$permit_en  = __( 'indica uso anterior nos dados oficiais do Department of Enterprise e não garante sponsorship atual — a elegibilidade depende da vaga, do empregador e das regras vigentes na Irlanda.', 'conexao-br-irlanda' );
restore_previous_locale();

$count_pt = sprintf(
	/* translators: %s: number of opportunities. */
	_n( '%s oportunidade encontrada', '%s oportunidades encontradas', 43, 'conexao-br-irlanda' ),
	number_format_i18n( 43 )
);
$empty_pt = __( 'Nenhuma oportunidade encontrada com esses filtros.', 'conexao-br-irlanda' );

s8_assert( '43 opportunities found' === $count_en, 'the result count is English and pluralized', $count_en );
s8_assert( 'No opportunities found with these filters.' === $empty_en, 'the empty state is English', $empty_en );
s8_assert( 'No location found' === $no_loc_en, 'the location empty state is English', $no_loc_en );
s8_assert( 'Clear' === $clear_en, 'the clear action is English', $clear_en );
s8_assert( 'Show results' === $apply_en, 'the apply action is English', $apply_en );
s8_assert( 'Filter' === $filter_en, 'the mobile filter button is English', $filter_en );
s8_assert( 'Filters' === $sheets_en, 'the mobile filter dialog title is English', $sheets_en );
s8_assert( '43 oportunidades encontradas' === $count_pt, 'the Portuguese result count is unchanged', $count_pt );
s8_assert( 'Nenhuma oportunidade encontrada com esses filtros.' === $empty_pt, 'the Portuguese empty state is unchanged', $empty_pt );

// The employment-safety warning keeps its official references and full meaning.
s8_assert( false !== strpos( $warning_en, 'never pay' ) && false !== strpos( $warning_en, 'Employment Permit' ), 'the safety warning is English and keeps the official term', $warning_en );
s8_assert( false !== strpos( $warning_en, 'legitimate recruitment agency' ), 'the safety warning keeps its full meaning (not shortened)', $warning_en );
s8_assert( false !== strpos( $permit_en, 'Department of Enterprise' ), 'the official Department of Enterprise reference is preserved', $permit_en );
s8_assert( false !== strpos( $permit_en, 'does not guarantee current sponsorship' ), 'the permit caveat is preserved in English', $permit_en );

// ---------------------------------------------------------------------------
echo "\n-- 8. Language-aware URLs, no string replacement --\n";

$pt_url = conexao_empregos_page_url();
$en_url = s8_in_language(
	'en',
	static function () {
		return conexao_empregos_page_url();
	}
);

s8_assert( false !== strpos( (string) $pt_url, '/empregos/' ), 'the PT jobs URL stays canonical', (string) $pt_url );
s8_assert( $en_url !== $pt_url, 'the EN request resolves a different jobs URL', (string) $en_url );
s8_assert( '' !== $en_url, 'conexao_empregos_page_url() resolves on the EN language' );

$pt_source = conexao_page_translation_snapshot_page( (int) $pt_page->ID );
s8_assert( 'empregos' === $pt_source['name'], 'the canonical PT path is still "empregos" (no translated slug)' );

echo "\nstage 8 EN Jobs language: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
