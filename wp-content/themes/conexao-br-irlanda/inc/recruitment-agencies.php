<?php
/**
 * Empregos — "Agências de recrutamento" recruitment-agencies module.
 *
 * Backs the recruitment-agency section rendered by page-empregos.php AFTER the
 * existing "Onde procurar emprego" job-search resources. The section is a
 * second useful pathway for the audience — Instagram remains the primary
 * current-vacancy CTA and the job boards remain the secondary resources.
 *
 * Data source: the `recruitment_agency` custom post type (registered by the
 * conexao-data-model plugin, managed in wp-admin under
 * Empregos → Agências de Recrutamento via the Conexão Admin UX). Each record
 * is a compact card with the agency name, main job types (canonical labels
 * via Conexao_Data_Model_Agency — legacy free-text values pass through
 * unchanged), temp/permanent flags, location, phone (tel: link) and an
 * external website button.
 *
 * Agencies are ordered by the `_agency_order` meta (lower first) so the
 * agencies most relevant to a general / entry-level audience come first.
 * Only published records render; archived/draft agencies stay hidden.
 *
 * @package Conexao_BR_Irlanda
 */

defined( 'ABSPATH' ) || exit;

/**
 * The recruitment agencies shown on /empregos/.
 *
 * Only published records are returned. Ordering uses the `_agency_order`
 * meta (lower first, ties by newest first), but records without an explicit
 * order are still included — sorted last — so an agency saved without an
 * "Ordem de exibição" value is never silently dropped from the directory.
 *
 * @return WP_Post[] Published agency records.
 */
function conexao_recruitment_agencies() {
	$query = new WP_Query(
		array(
			'post_type'      => 'recruitment_agency',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	$agencies = $query->posts;

	usort(
		$agencies,
		static function ( $a, $b ) {
			$order_a = get_post_meta( $a->ID, '_agency_order', true );
			$order_b = get_post_meta( $b->ID, '_agency_order', true );
			$order_a = ( '' === $order_a || null === $order_a ) ? PHP_INT_MAX : (int) $order_a;
			$order_b = ( '' === $order_b || null === $order_b ) ? PHP_INT_MAX : (int) $order_b;

			if ( $order_a === $order_b ) {
				// Same order (or both unordered): newest first, matching the
				// previous SQL ordering behavior.
				return strcmp( (string) $b->post_date, (string) $a->post_date );
			}

			return $order_a <=> $order_b;
		}
	);

	return $agencies;
}

/**
 * The tel: URI for a stored phone number ('' when none).
 *
 * Strips everything but the leading + and digits so the link works on any
 * device. Falls back to the raw value if nothing parseable remains.
 *
 * @param string $phone Display phone, e.g. "+353 1 234 5678".
 * @return string Escaped tel: URI or '' when empty.
 */
function conexao_recruitment_agency_tel_uri( $phone ) {
	$phone = is_string( $phone ) ? trim( $phone ) : '';

	if ( '' === $phone ) {
		return '';
	}

	$digits = preg_replace( '/[^\d+]/', '', $phone );
	if ( '' === $digits ) {
		$digits = $phone;
	}

	return esc_url( 'tel:' . $digits );
}

function conexao_recruitment_agency_job_type_labels( $raw ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';

	if ( '' === $raw ) {
		return array();
	}

	// Canonical labels come from the data-model plugin so the admin editor
	// and the frontend always agree (see Conexao_Data_Model_Agency). If the
	// plugin is unavailable, the stored text is shown as-is.
	if ( class_exists( 'Conexao_Data_Model_Agency' ) ) {
		return Conexao_Data_Model_Agency::job_type_labels( $raw );
	}

	return array( $raw );
}

/**
 * Language-aware display of a stored coverage string (`_agency_location`).
 *
 * The stored value stays the single source of truth (it is what the location
 * filter normalizes) and real Irish place names are never touched. Only the
 * generic coverage words inside it are UI labels, so they resolve through
 * gettext:
 *
 *   Nacional                       → "Nacional" / "Nationwide"
 *   Nacional (Dublin)              → "Nationwide (Dublin)"
 *   Nacional (Ennis, Co. Clare; Galway)
 *
 * Segments that are not generic labels (Dublin, Cork, "Co. Clare",
 * "Deansgrange") are returned byte-identical — they are proper place names.
 * An unrecognised or empty value is returned exactly as stored, so a future
 * coverage string can never be corrupted or dropped by this helper.
 *
 * @param string $raw Stored `_agency_location` value.
 * @return string Display string for the requested language.
 */
function conexao_recruitment_agency_location_display( $raw ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';

	if ( '' === $raw ) {
		return '';
	}

	// Only the standalone generic word is a UI label; a parenthetical place
	// list ("Nacional (Dublin)") stays byte-identical around it.
	$display = preg_replace_callback(
		'/(?<![\p{L}\p{N}])Nacional(?![\p{L}\p{N}])/u',
		static function () {
			return __( 'Nacional', 'conexao-br-irlanda' );
		},
		$raw
	);

	// "Irlanda" is the PORTUGUESE name of the country (the exonym); the English
	// form is "Ireland". This is a language label, not a place-name rewrite:
	// every real location in the coverage string (Dublin, Cork, Co. Clare,
	// Monaghan, Galway…) is deliberately absent from this map and is returned
	// byte-identical, exactly as the task requires.
	$display = str_replace(
		array( 'Irlanda', 'irlanda' ),
		array( __( 'Irlanda', 'conexao-br-irlanda' ), __( 'irlanda', 'conexao-br-irlanda' ) ),
		(string) $display
	);

	return is_string( $display ) ? trim( $display ) : $raw;
}

/**
 * A single agency meta value, normalized to string.
 *
 * @param WP_Post $agency Agency post.
 * @param string  $key    Meta key (with or without leading underscore).
 * @return string
 */
function conexao_recruitment_agency_meta( $agency, $key ) {
	if ( ! $agency instanceof WP_Post ) {
		return '';
	}

	$value = get_post_meta( $agency->ID, $key, true );

	if ( is_array( $value ) || is_object( $value ) ) {
		return '';
	}

	return (string) $value;
}

// ============================================================================
// Directory filters (Área de trabalho / Localização / Tipo de contrato)
//
// Server-side, URL-driven filtering for the recruitment-agency directory
// rendered by template-parts/recruitment-agencies.php — the same architecture
// as the /lazer/ filters (?county=/?categoria=): filter state lives in clean
// query parameters (?area=/?localizacao=/?contrato=), the options are derived
// from the actual directory data, and matching never depends on how a value
// happens to be formatted for display.
//
// Data sources per dimension:
//   - Área: `_agency_job_types` already stores canonical keys from
//     Conexao_Data_Model_Agency::job_types() — reused as the filter slugs.
//   - Contrato: `_agency_temporary` / `_agency_permanent` booleans.
//   - Localização: `_agency_location` is a human-readable coverage string
//     ("Dublin, Limerick", "Nacional", "Nacional (Dublin)"). It stays the
//     single source of truth for display AND filtering; the registry below
//     normalizes it into canonical location slugs. Only known locations are
//     matched — unknown segments fail safely (the agency simply never matches
//     a location filter and never appears as a filter option).
// ============================================================================

/**
 * Canonical filterable areas: storage key => Portuguese display label.
 *
 * Delegates to the data-model plugin registry (the single source of truth
 * shared with the Admin UX editor). Legacy free-text segments are never
 * filterable — only canonical keys count.
 *
 * @return array<string,string>
 */
function conexao_recruitment_agency_areas() {
	if ( class_exists( 'Conexao_Data_Model_Agency' ) ) {
		return Conexao_Data_Model_Agency::job_types();
	}

	return array();
}

/**
 * Canonical filterable locations: slug => label + normalized name aliases.
 *
 * The registry covers the locations present in the current agency dataset
 * (see scripts/seed-recruitment-agencies.php). Add a location here before
 * tagging an agency with it — agencies are never matched on unknown values.
 *
 * The SLUGS and the alias list are the stable, language-neutral filtering
 * identity (?localizacao=dublin) and are never translated. The `label` is the
 * display name only: real Irish place names are proper nouns and stay
 * identical in both languages, while the single generic label ("Nacional",
 * i.e. nationwide coverage) is a UI label and runs through gettext so the
 * English page shows its own language.
 *
 * @return array<string,array{label:string,aliases:string[]}>
 */
function conexao_recruitment_agency_locations() {
	return array(
		'nacional'   => array(
			'label'   => __( 'Nacional', 'conexao-br-irlanda' ),
			// Nationwide coverage; matches every specific location filter.
			'aliases' => array( 'nacional', 'national', 'nationwide' ),
		),
		'dublin'     => array(
			'label'   => 'Dublin',
			'aliases' => array( 'dublin', 'co dublin', 'county dublin', 'deansgrange' ),
		),
		'cork'       => array(
			'label'   => 'Cork',
			'aliases' => array( 'cork', 'co cork', 'county cork' ),
		),
		'galway'     => array(
			'label'   => 'Galway',
			'aliases' => array( 'galway', 'co galway', 'county galway' ),
		),
		'limerick'   => array(
			'label'   => 'Limerick',
			'aliases' => array( 'limerick', 'co limerick', 'county limerick' ),
		),
		'waterford'  => array(
			'label'   => 'Waterford',
			'aliases' => array( 'waterford' ),
		),
		'naas'       => array(
			'label'   => 'Naas',
			'aliases' => array( 'naas' ),
		),
		'athlone'    => array(
			'label'   => 'Athlone',
			'aliases' => array( 'athlone' ),
		),
		'sligo'      => array(
			'label'   => 'Sligo',
			'aliases' => array( 'sligo' ),
		),
		'carlow'     => array(
			'label'   => 'Carlow',
			'aliases' => array( 'carlow' ),
		),
		'kilkenny'   => array(
			'label'   => 'Kilkenny',
			'aliases' => array( 'kilkenny' ),
		),
		'portlaoise' => array(
			'label'   => 'Portlaoise',
			'aliases' => array( 'portlaoise' ),
		),
		'shannon'    => array(
			'label'   => 'Shannon',
			'aliases' => array( 'shannon' ),
		),
		'cavan'      => array(
			'label'   => 'Cavan',
			'aliases' => array( 'cavan', 'co cavan' ),
		),
		'kerry'      => array(
			'label'   => 'Kerry',
			'aliases' => array( 'kerry', 'co kerry', 'county kerry' ),
		),
		'roscommon'  => array(
			'label'   => 'Roscommon',
			'aliases' => array( 'roscommon' ),
		),
		'dundalk'    => array(
			'label'   => 'Dundalk',
			'aliases' => array( 'dundalk', 'louth', 'co louth', 'county louth' ),
		),
	);
}

/**
 * Normalize one location fragment for registry lookup.
 *
 * Accent-, case- and punctuation-insensitive ("Co. Dublin" and "co dublin"
 * both resolve to the Dublin alias), so filter matching never depends on
 * how the coverage string was written.
 *
 * @param string $segment Raw fragment from `_agency_location`.
 * @return string Normalized alias, or '' when nothing remains.
 */
function conexao_recruitment_agency_normalize_location( $segment ) {
	$segment = (string) $segment;

	if ( function_exists( 'remove_accents' ) ) {
		$segment = remove_accents( $segment );
	}

	$segment = strtolower( $segment );
	$segment = str_replace( '.', '', $segment );
	$segment = preg_replace( '/[^a-z0-9]+/', ' ', $segment );

	return trim( (string) preg_replace( '/\s+/', ' ', $segment ) );
}

/**
 * The canonical location slugs an agency covers, from `_agency_location`.
 *
 * Splits the display string on commas/semicolons (parenthetical details such
 * as "Nacional (Ennis, Co. Clare; Galway)" are unwrapped into the list) and
 * matches each fragment against the canonical registry. Unknown fragments
 * are ignored rather than guessed.
 *
 * @param string $raw Stored `_agency_location` value.
 * @return string[] Canonical location slugs (may be empty).
 */
function conexao_recruitment_agency_location_slugs( $raw ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';
	if ( '' === $raw ) {
		return array();
	}

	$lookup = array();
	foreach ( conexao_recruitment_agency_locations() as $slug => $location ) {
		foreach ( $location['aliases'] as $alias ) {
			$lookup[ $alias ] = $slug;
		}
	}

	// Parentheses stay part of the coverage list, not part of one name.
	$raw   = str_replace( array( '(', ')', '[', ']' ), ',', $raw );
	$slugs = array();

	foreach ( preg_split( '/[,;]+/', $raw ) as $segment ) {
		$normalized = conexao_recruitment_agency_normalize_location( $segment );
		if ( '' === $normalized || ! isset( $lookup[ $normalized ] ) ) {
			continue;
		}
		$slugs[ $lookup[ $normalized ] ] = true;
	}

	return array_keys( $slugs );
}

/**
 * The canonical area keys an agency recruits for.
 *
 * @param WP_Post $agency Agency post.
 * @return string[] Canonical keys from `_agency_job_types` (may be empty).
 */
function conexao_recruitment_agency_area_keys( $agency ) {
	$raw = conexao_recruitment_agency_meta( $agency, '_agency_job_types' );
	if ( '' === $raw ) {
		return array();
	}

	$canonical = conexao_recruitment_agency_areas();
	$keys      = array();

	foreach ( explode( ',', $raw ) as $segment ) {
		$segment = trim( $segment );
		if ( '' !== $segment && isset( $canonical[ $segment ] ) ) {
			$keys[ $segment ] = true;
		}
	}

	return array_keys( $keys );
}

/**
 * Whether one agency satisfies all three filter dimensions (AND logic).
 *
 * Within a dimension the selection is single-valued. Nationwide agencies
 * match every specific location; an agency flagged for both contract types
 * matches either contract filter.
 *
 * @param WP_Post $agency   Agency post.
 * @param string  $area     Canonical area key or ''.
 * @param string  $location Canonical location slug or ''.
 * @param string  $contrato 'temporario', 'permanente' or ''.
 * @return bool
 */
function conexao_recruitment_agency_matches_filters( $agency, $area, $location, $contrato ) {
	if ( '' !== $area && ! in_array( $area, conexao_recruitment_agency_area_keys( $agency ), true ) ) {
		return false;
	}

	if ( '' !== $location ) {
		$slugs = conexao_recruitment_agency_location_slugs( conexao_recruitment_agency_meta( $agency, '_agency_location' ) );
		if ( ! in_array( 'nacional', $slugs, true ) && ! in_array( $location, $slugs, true ) ) {
			return false;
		}
	}

	if ( '' !== $contrato ) {
		if ( 'temporario' === $contrato && ! (bool) get_post_meta( $agency->ID, '_agency_temporary', true ) ) {
			return false;
		}
		if ( 'permanente' === $contrato && ! (bool) get_post_meta( $agency->ID, '_agency_permanent', true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Current filter state + available options for the agency directory.
 *
 * Options are derived from the agencies actually on display, so a dimension
 * value is only offered while at least one agency supports it. Invalid or
 * unsupported URL values fail safely and behave as "no filter".
 *
 * @param WP_Post[] $agencies Displayable agency posts (full directory).
 * @return array{
 *   area:string, location:string, contrato:string,
 *   area_options:array<string,string>, location_options:array<string,string>,
 *   contrato_options:array<string,string>, agencies:WP_Post[]
 * }
 */
function conexao_recruitment_agency_filter_state( $agencies ) {
	$registry = conexao_recruitment_agency_locations();

	$area_options     = array();
	$location_options = array();
	$contrato_options = array();

	foreach ( $agencies as $agency ) {
		foreach ( conexao_recruitment_agency_area_keys( $agency ) as $key ) {
			$area_options[ $key ] = true;
		}
		foreach ( conexao_recruitment_agency_location_slugs( conexao_recruitment_agency_meta( $agency, '_agency_location' ) ) as $slug ) {
			$location_options[ $slug ] = true;
		}
		if ( (bool) get_post_meta( $agency->ID, '_agency_temporary', true ) ) {
			$contrato_options['temporario'] = true;
		}
		if ( (bool) get_post_meta( $agency->ID, '_agency_permanent', true ) ) {
			$contrato_options['permanente'] = true;
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
		$nacional = array( 'nacional' => $registry['nacional']['label'] );
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

	// Selected values (invalid/unsupported values degrade to no filter).
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filter state, like ?categoria= on the archives.
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

	$filtered = array();
	if ( '' === $area && '' === $location && '' === $contrato ) {
		$filtered = $agencies;
	} else {
		foreach ( $agencies as $agency ) {
			if ( conexao_recruitment_agency_matches_filters( $agency, $area, $location, $contrato ) ) {
				$filtered[] = $agency;
			}
		}
	}

	return array(
		'area'             => $area,
		'location'         => $location,
		'contrato'         => $contrato,
		'area_options'     => $area_list,
		'location_options' => $location_list,
		'contrato_options' => $contrato_list,
		'agencies'         => $filtered,
	);
}