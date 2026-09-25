<?php
/**
 * Recruitment agency shared data helpers (Conexão Data Model).
 *
 * The "Agências de recrutamento" directory (`recruitment_agency` CPT) is
 * edited through the Conexão Admin UX and rendered on /empregos/ by the
 * theme. Both sides need the same canonical job-type list so the frontend
 * always shows consistent labels regardless of who edits the record —
 * the same reason Conexao_Data_Model_Contacts owns the Apoiador contact
 * types.
 *
 * Storage: `_agency_job_types` holds a comma-separated list of canonical
 * keys (e.g. "warehouse,logistics"). Legacy records may hold free text;
 * job_type_labels() passes unknown segments through unchanged so old data
 * keeps rendering while new data gets consistent labels.
 *
 * @package Conexao_BR_Irlanda_Data_Model
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Agency {

	/**
	 * Canonical job types: storage key => display label.
	 *
	 * These are the only values the Admin UX editor offers. Do not add
	 * values without confirming they are supported by the underlying
	 * research — agencies are never auto-tagged.
	 *
	 * The KEYS are the stable, language-neutral storage/filter identity
	 * (`?area=warehouse`) and must never be renamed. The VALUES are display
	 * labels only, so they run through gettext: Portuguese (the default
	 * language) renders the source strings byte-identically, while the
	 * English Jobs page renders the catalog's English labels. This is the
	 * existing translation architecture — no duplicate terms, no
	 * language-specific storage, no frontend string replacement.
	 *
	 * @return array<string,string>
	 */
	public static function job_types() {
		return array(
			'warehouse'            => __( 'Armazém', 'conexao-br-irlanda' ),
			'general_operative'    => __( 'Operacional Geral', 'conexao-br-irlanda' ),
			'factory_production'   => __( 'Fábrica / Produção', 'conexao-br-irlanda' ),
			'logistics'            => __( 'Logística', 'conexao-br-irlanda' ),
			'hospitality'          => __( 'Hotelaria', 'conexao-br-irlanda' ),
			'cleaning'             => __( 'Limpeza', 'conexao-br-irlanda' ),
			'retail'               => __( 'Varejo', 'conexao-br-irlanda' ),
			'construction_labour'  => __( 'Construção Civil', 'conexao-br-irlanda' ),
			'driving_delivery'     => __( 'Condução / Entregas', 'conexao-br-irlanda' ),
			'office_admin'         => __( 'Escritório / Administrativo', 'conexao-br-irlanda' ),
			'agriculture_seasonal' => __( 'Agricultura / Sazonal', 'conexao-br-irlanda' ),
			// Added for the 2026-09 Empregos expansion: several validated
			// agencies (TTM, Servisource, Access Healthcare, Hollilander,
			// Cpl) recruit healthcare professionals — a sector the original
			// registry did not cover. Confirmed against each agency's own
			// site before adding (agencies are never auto-tagged).
			'healthcare'           => __( 'Saúde / Cuidados', 'conexao-br-irlanda' ),
		);
	}

	/**
	 * Display labels for a stored `_agency_job_types` value.
	 *
	 * Canonical keys are mapped to their (translated) labels; anything else
	 * (legacy free-text segments) is passed through as its own label so
	 * pre-existing records keep rendering exactly what was written.
	 *
	 * @param string $raw Stored meta value, e.g. "warehouse,logistics".
	 * @return string[] Ordered, de-duplicated display labels.
	 */
	public static function job_type_labels( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		$types  = self::job_types();
		$labels = array();

		foreach ( explode( ',', $raw ) as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}
			$labels[] = isset( $types[ $segment ] ) ? $types[ $segment ] : $segment;
		}

		return array_values( array_unique( $labels ) );
	}
}
