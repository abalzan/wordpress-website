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
	 * Canonical job types: storage key => Portuguese display label.
	 *
	 * These are the only values the Admin UX editor offers. Do not add
	 * values without confirming they are supported by the underlying
	 * research — agencies are never auto-tagged.
	 *
	 * @return array<string,string>
	 */
	public static function job_types() {
		return array(
			'warehouse'            => 'Armazém',
			'general_operative'    => 'Operacional Geral',
			'factory_production'   => 'Fábrica / Produção',
			'logistics'            => 'Logística',
			'hospitality'          => 'Hotelaria',
			'cleaning'             => 'Limpeza',
			'retail'               => 'Varejo',
			'construction_labour'  => 'Construção Civil',
			'driving_delivery'     => 'Condução / Entregas',
			'office_admin'         => 'Escritório / Administrativo',
			'agriculture_seasonal' => 'Agricultura / Sazonal',
		);
	}

	/**
	 * Display labels for a stored `_agency_job_types` value.
	 *
	 * Canonical keys are mapped to their Portuguese labels; anything else
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
