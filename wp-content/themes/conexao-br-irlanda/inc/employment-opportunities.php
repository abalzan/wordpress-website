<?php
/**
 * Empregos — shared "Employment Opportunities" data/model + filter layer.
 *
 * One structured representation for every employment resource shown in the
 * unified /empregos/ directory (template-parts/employment-opportunities.php):
 * recruitment agencies, official public-sector recruitment portals and
 * Employment Permit-history employers, browsable through ONE shared filter
 * system.
 *
 *   - agency          — the `recruitment_agency` CPT (inc/recruitment-agencies.php)
 *   - public_sector   — the official public-sector recruitment portals
 *                        (conexao_public_sector_jobs(), consumed by
 *                        template-parts/public-sector-jobs.php)
 *   - permit_history  — the `permit_employer` CPT (inc/permit-employers.php)
 *
 * The normalizers REUSE the existing data layer instead of duplicating it:
 *
 *   - Areas come from `_agency_job_types` canonical keys via
 *     conexao_recruitment_agency_area_keys() (registry: Conexao_Data_Model_Agency).
 *   - Locations come from `_agency_location` normalized through the canonical
 *     registry via conexao_recruitment_agency_location_slugs() ("Nacional"
 *     matches every specific location, exactly like the agency filter).
 *   - Contract types come from the `_agency_temporary` / `_agency_permanent`
 *     booleans ('temporario' / 'permanente').
 *   - has_permit_history is derived from `_employer_permit_status` — the
 *     single source of truth. 'verified' AND 'exception' both carry official
 *     DETE historical evidence (an exception record merely adds the
 *     employer's current-position statement), so both map to TRUE;
 *     'unverified' (no validated evidence) maps to FALSE. This is
 *     HISTORICAL evidence only — it must never be read as "currently
 *     sponsoring", and no second "sponsor" flag exists.
 *
 * Fields that have no meaning for a resource type are left empty rather
 * than invented (e.g. public-sector resources carry no areas, locations,
 * contract types or permit flag; permit employers carry no areas or
 * contract types — their free-text `_employer_location` stays a display
 * string in `meta`, not a canonical location).
 *
 * Filtering: conexao_employment_opportunities_filter_state() is the single
 * entry point for the unified directory's filters (?tipo= plus the existing
 * ?area= / ?localizacao= / ?contrato=), with AND logic between dimensions.
 * The agency dimensions keep the exact existing semantics by delegating to
 * conexao_recruitment_agency_matches_filters() for agency records.
 * Non-agency records never match those dimensions — they carry no
 * filterable data — so existing agency filter URLs produce the same
 * results as before. The ?tipo= dimension matches the canonical
 * resource_type ('permit_history' additionally requires the structured
 * has_permit_history flag — historical evidence only).
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Employment Opportunities resource types.
 *
 * @return array<string,string> resource_type => Portuguese display label.
 */
function conexao_employment_opportunity_types() {
	return array(
		'agency'         => __( 'Agência de recrutamento', 'conexao-br-irlanda' ),
		'public_sector'  => __( 'Setor público', 'conexao-br-irlanda' ),
		'permit_history' => __( 'Histórico de Employment Permits', 'conexao-br-irlanda' ),
	);
}

// ============================================================================
// Public-sector recruitment portals (resource_type = public_sector)
//
// Moved verbatim from template-parts/public-sector-jobs.php so the shared
// model layer owns the data (single source of truth). The template part now
// consumes conexao_public_sector_jobs() and renders the exact same markup.
//
// These are OFFICIAL recruitment portals — never recruitment agencies and
// never described as one (see the template part's wording rules).
// ============================================================================

/**
 * The official public-sector recruitment portals shown on /empregos/.
 *
 * Intentionally static (three high-value official entry points) — no CPT,
 * no admin UI. Each entry: name, description, url, cta.
 *
 * @return array[] Portal definitions.
 */
function conexao_public_sector_jobs() {
	$jobs = array(
		array(
			'name'        => 'Publicjobs.ie',
			'description' => __( 'Portal oficial para recrutamento do Civil Service e outras oportunidades no setor público irlandês.', 'conexao-br-irlanda' ),
			'url'         => 'https://publicjobs.ie/en/',
			'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
		),
		array(
			'name'        => 'HSE Jobs',
			'description' => __( 'Vagas no HSE em áreas de saúde, administração, serviços de apoio e outras funções.', 'conexao-br-irlanda' ),
			'url'         => 'https://about.hse.ie/jobs/',
			'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
		),
		array(
			'name'        => 'Local Government Jobs',
			'description' => __( 'Oportunidades de emprego nas autoridades locais da Irlanda.', 'conexao-br-irlanda' ),
			'url'         => 'https://www.localgovernmentjobs.ie/',
			'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
		),
	);

	/**
	 * Filter the /empregos/ public-sector recruitment portals.
	 *
	 * @param array[] $jobs Portal definitions.
	 */
	return apply_filters( 'conexao_public_sector_jobs', $jobs );
}

// ============================================================================
// Normalizers — WP_Post / source array => shared opportunity item
//
// Every item carries the same common shape (empty values when a field has
// no meaning for the resource type) plus the original record in `_source`
// so card renderers can keep using the existing per-type helpers without
// the model duplicating their logic:
//
//   key                (string)  Stable identity: '{type}:{ID|slug}'.
//   title              (string)  Raw (unescaped) name.
//   url                (string)  External URL ('' when the record has none).
//   resource_type      (string)  'agency' | 'public_sector' | 'permit_history'.
//   areas              (string[]) Canonical area keys (agency data only).
//   locations          (string[]) Canonical location slugs (agency data only).
//   contract_types     (string[]) 'temporario' / 'permanente' (agency data only).
//   has_permit_history (bool)    From `_employer_permit_status` (see header).
//   description        (string)  Public-sector description; '' otherwise
//                                (the agency/employer cards have no
//                                description field).
//   phone              (string)  Agency phone ('' otherwise).
//   licensing_status   (string)  'licensed' when the agency has a WRC
//                                licence reference, else ''. No other
//                                value exists.
//   meta               (array)   Card-UI metadata for the resource type
//                                (display strings/flags, NOT filter data).
//   _source            (WP_Post|array) The underlying record.
// ============================================================================

/**
 * Normalize one recruitment-agency record into the shared opportunity shape.
 *
 * @param WP_Post $agency Agency post.
 * @return array Opportunity item.
 */
function conexao_employment_opportunity_from_agency( $agency ) {
	$temporary = (bool) get_post_meta( $agency->ID, '_agency_temporary', true );
	$permanent = (bool) get_post_meta( $agency->ID, '_agency_permanent', true );
	$wrc       = conexao_recruitment_agency_meta( $agency, '_agency_wrc_licence' );

	$contract_types = array();
	if ( $temporary ) {
		$contract_types[] = 'temporario';
	}
	if ( $permanent ) {
		$contract_types[] = 'permanente';
	}

	return array(
		'key'               => 'agency:' . $agency->ID,
		'title'             => $agency->post_title,
		'url'               => conexao_recruitment_agency_meta( $agency, '_agency_website' ),
		'resource_type'     => 'agency',
		'areas'             => conexao_recruitment_agency_area_keys( $agency ),
		'locations'         => conexao_recruitment_agency_location_slugs( conexao_recruitment_agency_meta( $agency, '_agency_location' ) ),
		'contract_types'    => $contract_types,
		'has_permit_history' => false,
		'description'       => '',
		'phone'             => conexao_recruitment_agency_meta( $agency, '_agency_phone' ),
		'licensing_status'  => '' !== trim( $wrc ) ? 'licensed' : '',
		'meta'              => array(
			// Display strings/flags used by the existing agency card UI.
			'job_type_labels'  => conexao_recruitment_agency_job_type_labels( conexao_recruitment_agency_meta( $agency, '_agency_job_types' ) ),
			'location_display' => conexao_recruitment_agency_meta( $agency, '_agency_location' ),
			'temporary'        => $temporary,
			'permanent'        => $permanent,
			'licensed'         => '' !== trim( $wrc ),
			'order'            => conexao_recruitment_agency_meta( $agency, '_agency_order' ),
		),
		'_source'           => $agency,
	);
}

/**
 * Normalize one public-sector portal definition into the shared shape.
 *
 * Public-sector portals are official resources, not agencies: no areas,
 * locations, contract types, phone, licence or permit flag is invented.
 *
 * @param array $job Portal definition (conexao_public_sector_jobs() shape).
 * @return array Opportunity item.
 */
function conexao_employment_opportunity_from_public_sector_job( $job ) {
	$name = isset( $job['name'] ) ? (string) $job['name'] : '';

	return array(
		'key'               => 'public_sector:' . sanitize_title( $name ),
		'title'             => $name,
		'url'               => isset( $job['url'] ) ? (string) $job['url'] : '',
		'resource_type'     => 'public_sector',
		'areas'             => array(),
		'locations'         => array(),
		'contract_types'    => array(),
		'has_permit_history' => false,
		'description'       => isset( $job['description'] ) ? (string) $job['description'] : '',
		'phone'             => '',
		'licensing_status'  => '',
		'meta'              => array(
			'cta' => isset( $job['cta'] ) ? (string) $job['cta'] : '',
		),
		'_source'           => $job,
	);
}

/**
 * Normalize one employment-permit employer record into the shared shape.
 *
 * has_permit_history comes exclusively from `_employer_permit_status`
 * (conexao_permit_employer_status()): 'verified' and 'exception' both mean
 * VERIFIED HISTORICAL permit evidence in the official DETE statistics
 * (exception records carry an additional current-position statement), so
 * both are TRUE; 'unverified' is FALSE. It never means "currently
 * sponsoring" and no separate sponsor flag exists.
 *
 * Employers are never agencies: no areas, contract types or licensing
 * status are invented. `_employer_location` is a free-text display string
 * (no canonical registry), so it stays in `meta` and `locations` stays
 * empty rather than guessing slugs.
 *
 * @param WP_Post $employer Employer post.
 * @return array Opportunity item.
 */
function conexao_employment_opportunity_from_permit_employer( $employer ) {
	$permit_status = conexao_permit_employer_status( $employer );

	return array(
		'key'               => 'permit_employer:' . $employer->ID,
		'title'             => $employer->post_title,
		'url'               => conexao_permit_employer_meta( $employer, '_employer_official_website' ),
		'resource_type'     => 'permit_history',
		'areas'             => array(),
		'locations'         => array(),
		'contract_types'    => array(),
		'has_permit_history' => in_array( $permit_status, array( 'verified', 'exception' ), true ),
		'description'       => '',
		'phone'             => '',
		'licensing_status'  => '',
		'meta'              => array(
			// Display strings/flags used by the existing employer card UI.
			'permit_status'    => $permit_status,
			'is_verified'      => ( 'verified' === $permit_status ),
			'is_exception'     => ( 'exception' === $permit_status ),
			'evidence_years'   => conexao_permit_employer_meta( $employer, '_employer_evidence_years' ),
			'sector'           => conexao_permit_employer_meta( $employer, '_employer_sector' ),
			'roles'            => conexao_permit_employer_roles( $employer ),
			'location_display' => conexao_permit_employer_meta( $employer, '_employer_location' ),
			'careers_url'      => conexao_permit_employer_meta( $employer, '_employer_careers_url' ),
		),
		'_source'           => $employer,
	);
}

// ============================================================================
// Shared collection + filter layer
// ============================================================================

/**
 * The shared Employment Opportunities collection for /empregos/.
 *
 * Draws each resource type from its existing source — the same records and
 * display rules the current sections use — with no duplicated data:
 *
 *   - Agencies: published `recruitment_agency` records WITH a website
 *     (the existing render rule: no website, no card), in the existing
 *     `_agency_order` sequence.
 *   - Public sector: conexao_public_sector_jobs().
 *   - Permit employers: published `permit_employer` records, ordered by
 *     name (the existing ordering).
 *
 * Deduplicated by `key` so a record can never appear twice.
 *
 * @param string[] $resource_types Optional whitelist of canonical resource
 *                                 types to include. Empty = all types.
 * @return array[] List of opportunity items (see the normalizer header).
 */
function conexao_employment_opportunities( $resource_types = array() ) {
	// Resolve the whitelist FIRST so sources that cannot contribute are
	// never queried/normalized (e.g. a caller asking only for permit
	// employers must not re-run the agencies query).
	$wanted = array();
	foreach ( (array) $resource_types as $type ) {
		if ( is_string( $type ) && '' !== $type ) {
			$wanted[ $type ] = true;
		}
	}

	$items = array();

	// Agencies (directory order), via the existing query helper.
	if ( ! $wanted || isset( $wanted['agency'] ) ) {
		foreach ( conexao_recruitment_agencies() as $agency ) {
			if ( '' === conexao_recruitment_agency_meta( $agency, '_agency_website' ) ) {
				continue;
			}
			$items[] = conexao_employment_opportunity_from_agency( $agency );
		}
	}

	// Public-sector portals (entries without a name or URL are skipped —
	// they can never render a usable card).
	if ( ! $wanted || isset( $wanted['public_sector'] ) ) {
		foreach ( conexao_public_sector_jobs() as $job ) {
			if ( '' === trim( (string) ( $job['name'] ?? '' ) ) || '' === trim( (string) ( $job['url'] ?? '' ) ) ) {
				continue;
			}
			$items[] = conexao_employment_opportunity_from_public_sector_job( $job );
		}
	}

	// Permit employers (name order), via the existing query helper.
	if ( ! $wanted || isset( $wanted['permit_history'] ) ) {
		foreach ( conexao_permit_employers() as $employer ) {
			$items[] = conexao_employment_opportunity_from_permit_employer( $employer );
		}
	}

	// De-duplicate by key; a record can never appear twice.
	$unique = array();
	foreach ( $items as $item ) {
		if ( $wanted && ! isset( $wanted[ $item['resource_type'] ] ) ) {
			continue;
		}
		$unique[ $item['key'] ] = $item;
	}

	return array_values( $unique );
}

/**
 * Whether one shared opportunity item satisfies the existing agency filter
 * dimensions (?area= / ?localizacao= / ?contrato=).
 *
 * Backward compatibility by construction: agency items are matched by
 * delegating to conexao_recruitment_agency_matches_filters() on the source
 * record — the exact same code path as the live agency directory. Items of
 * the other resource types carry no filterable data and therefore never
 * match a non-empty filter: public-sector portals and permit-history
 * employers have no areas/contract data, and the employer free-text
 * `_employer_location` has no canonical location slugs.
 *
 * @param array  $opportunity Opportunity item.
 * @param string $area        Canonical area key or ''.
 * @param string $location    Canonical location slug or ''.
 * @param string $contrato    'temporario', 'permanente' or ''.
 * @return bool
 */
function conexao_employment_opportunity_matches_filters( $opportunity, $area, $location, $contrato, $tipo = '' ) {
	if ( ! is_array( $opportunity ) || empty( $opportunity['resource_type'] ) ) {
		return false;
	}

	// "Tipo de oportunidade" dimension (unified directory). A plain type
	// matches its own resource_type; 'permit_history' additionally requires
	// the structured has_permit_history flag (from `_employer_permit_status`
	// via conexao_permit_employer_status()) — never a visible card label.
	// An item is never matched by more than one tipo value.
	if ( '' !== $tipo ) {
		if ( 'permit_history' === $tipo ) {
			if ( 'permit_history' !== $opportunity['resource_type'] || empty( $opportunity['has_permit_history'] ) ) {
				return false;
			}
		} elseif ( $tipo !== $opportunity['resource_type'] ) {
			return false;
		}
	}

	// No other filters active: every remaining item matches.
	if ( '' === $area && '' === $location && '' === $contrato ) {
		return true;
	}

	if ( 'agency' !== $opportunity['resource_type'] ) {
		return false;
	}

	$source = $opportunity['_source'] ?? null;

	return $source instanceof WP_Post
		&& conexao_recruitment_agency_matches_filters( $source, $area, $location, $contrato );
}

/**
 * The shared collection filtered with the existing agency filter semantics.
 *
 * Convenience wrapper for the unified directory: AND logic between
 * dimensions, single selection per dimension, matching identical to the
 * live agency directory (see conexao_employment_opportunity_matches_filters()).
 *
 * @param string $area     Canonical area key or ''.
 * @param string $location Canonical location slug or ''.
 * @param string $contrato 'temporario', 'permanente' or ''.
 * @param string $tipo     Canonical resource type ('agency',
 *                         'public_sector', 'permit_history') or ''. The
 *                         'permit_history' value additionally requires the
 *                         structured has_permit_history flag.
 * @return array[] Filtered opportunity items.
 */
function conexao_employment_opportunities_filtered( $area = '', $location = '', $contrato = '', $tipo = '' ) {
	$filtered = array();
	foreach ( conexao_employment_opportunities() as $item ) {
		if ( conexao_employment_opportunity_matches_filters( $item, $area, $location, $contrato, $tipo ) ) {
			$filtered[] = $item;
		}
	}

	return $filtered;
}

/**
 * Filter state for the unified /empregos/ opportunities directory.
 *
 * The server-side, URL-driven counterpart of the directory filters — the
 * same architecture as conexao_recruitment_agency_filter_state(), extended
 * with the "Tipo de oportunidade" dimension:
 *
 *   ?tipo=agency|public_sector|permit_history   (canonical resource type;
 *      'permit_history' requires the structured has_permit_history flag —
 *      historical DETE evidence, never a sponsorship claim)
 *   ?area= / ?localizacao= / ?contrato=         (existing agency dimensions,
 *      unchanged — same options, same AND matching, same backward-compatible
 *      URLs)
 *
 * Options are derived from the displayable collection itself, and only
 * where structured data exists: areas/locations/contract values come from
 * the agency items (public-sector portals and permit-history employers
 * carry none, and none is invented). Invalid/unsupported values degrade to
 * no filter, exactly like the agency directory.
 *
 * @return array{
 *   tipo: string,
 *   area: string,
 *   location: string,
 *   contrato: string,
 *   tipo_options: array<string,string>,
 *   area_options: array<string,string>,
 *   location_options: array<string,string>,
 *   contrato_options: array<string,string>,
 *   opportunities: array[],
 * }
 */
function conexao_employment_opportunities_filter_state() {
	$all = conexao_employment_opportunities();

	// --- Options derived from the displayable agency items ------------------
	$area_options     = array();
	$location_options = array();
	$contrato_options = array();
	$registry         = conexao_recruitment_agency_locations();

	foreach ( $all as $item ) {
		if ( 'agency' !== $item['resource_type'] ) {
			continue;
		}
		foreach ( (array) $item['areas'] as $key ) {
			$area_options[ $key ] = true;
		}
		foreach ( (array) $item['locations'] as $slug ) {
			$location_options[ $slug ] = true;
		}
		foreach ( (array) $item['contract_types'] as $slug ) {
			$contrato_options[ $slug ] = true;
		}
	}

	// Keep the canonical registry order for areas (it matches the Admin UX
	// editor); locations read best with "Nacional" first, then alphabetical.
	$area_list = array();
	foreach ( conexao_recruitment_agency_areas() as $key => $label ) {
		if ( isset( $area_options[ $key ] ) ) {
			$area_list[ $key ] = $label;
		}
	}

	$location_list = array();
	if ( isset( $location_options['nacional'] ) ) {
		$location_list['nacional'] = $registry['nacional']['label'];
	}
	$specific = array_diff_key( $location_options, $location_list );
	foreach ( array_keys( $specific ) as $slug ) {
		$location_list[ $slug ] = isset( $registry[ $slug ] ) ? $registry[ $slug ]['label'] : $slug;
	}
	uasort(
		$location_list,
		static function ( $a, $b ) {
			return strcmp( $a, $b );
		}
	);
	if ( isset( $location_list['nacional'] ) ) {
		// Re-pin "Nacional" at the top after the alphabetical sort.
		$nacional      = array( 'nacional' => $registry['nacional']['label'] );
		$location_list = $nacional + array_diff_key( $location_list, $nacional );
	}

	$contrato_labels = array(
		'temporario' => __( 'Temporário', 'conexao-br-irlanda' ),
		'permanente' => __( 'Permanente', 'conexao-br-irlanda' ),
	);
	$contrato_list   = array();
	foreach ( $contrato_labels as $slug => $label ) {
		if ( isset( $contrato_options[ $slug ] ) ) {
			$contrato_list[ $slug ] = $label;
		}
	}

	// "Tipo de oportunidade": Todas + the three canonical resource types,
	// always offered (the directory always contains every type).
	$tipo_list = array( '' => __( 'Todas', 'conexao-br-irlanda' ) ) + conexao_employment_opportunity_types();

	// --- Selected values (invalid values degrade to no filter) --------------
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filter state, like ?categoria= on the archives.
	$tipo = isset( $_GET['tipo'] ) ? sanitize_title( wp_unslash( $_GET['tipo'] ) ) : '';
	if ( ! isset( $tipo_list[ $tipo ] ) ) {
		$tipo = '';
	}

	$area = isset( $_GET['area'] ) ? sanitize_title( wp_unslash( $_GET['area'] ) ) : '';
	if ( ! isset( $area_list[ $area ] ) ) {
		$area = '';
	}

	$location = isset( $_GET['localizacao'] ) ? sanitize_title( wp_unslash( $_GET['localizacao'] ) ) : '';
	if ( ! isset( $location_list[ $location ] ) ) {
		$location = '';
	}

	$contrato = isset( $_GET['contrato'] ) ? sanitize_title( wp_unslash( $_GET['contrato'] ) ) : '';
	if ( ! isset( $contrato_list[ $contrato ] ) ) {
		$contrato = '';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// --- AND filtering across all dimensions --------------------------------
	if ( '' === $tipo && '' === $area && '' === $location && '' === $contrato ) {
		$filtered = $all;
	} else {
		$filtered = conexao_employment_opportunities_filtered( $area, $location, $contrato, $tipo );
	}

	return array(
		'tipo'             => $tipo,
		'area'             => $area,
		'location'         => $location,
		'contrato'         => $contrato,
		'tipo_options'     => $tipo_list,
		'area_options'     => $area_list,
		'location_options' => $location_list,
		'contrato_options' => $contrato_list,
		'opportunities'    => $filtered,
	);
}




