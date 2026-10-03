<?php
/**
 * Event source-language metadata (Stage 3.2).
 *
 * Owns the `_event_source_language` meta contract for imported events:
 * the language the SOURCE CONTENT is written in, as an explicit signal
 * (a source-declared locale/language field, or an editor's classification),
 * never inferred from the title/body text.
 *
 * This is NOT the record's language: a Portuguese-source event stays a
 * Portuguese record; an English-source event is an English record. The meta
 * exists so the pipeline can tell those apart before any editorial decision,
 * and so the local export JSON can carry the additive `lang` field.
 *
 * The class is intentionally dependency-free (no Polylang calls) so the
 * importer, the runtime and the exporter can all use it in any context.
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Event_Source_Language {

	/**
	 * Meta key storing the source language on the event record.
	 */
	const META_KEY = '_event_source_language';

	/**
	 * Portuguese source content.
	 */
	const PT = 'pt';

	/**
	 * English source content (source-inherited EN record candidate).
	 */
	const EN = 'en';

	/**
	 * Source content in a language other than Portuguese or English.
	 */
	const OTHER = 'other';

	/**
	 * Export-only value for records whose source language was never
	 * classified (legacy imports, manual events without a source, or sources
	 * that declare no language). The 'unknown' value is never STORED: an
	 * unclassified record simply has no meta, and the export normalizes the
	 * absence to 'unknown'.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Values allowed in the stored meta.
	 *
	 * @return string[]
	 */
	public static function allowed(): array {
		return array( self::PT, self::EN, self::OTHER );
	}

	/**
	 * Validate/normalize a value for storage.
	 *
	 * @param mixed $value Candidate value.
	 * @return string One of allowed(), or '' when the value is not a valid
	 *                classification (never stored).
	 */
	public static function sanitize( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, self::allowed(), true ) ? $value : '';
	}

	/**
	 * The value for the export JSON `lang` field.
	 *
	 * @param mixed $value Stored meta value (may be empty/absent).
	 * @return string One of pt|en|other|unknown.
	 */
	public static function export_value( $value ): string {
		$sanitized = self::sanitize( $value );
		return '' === $sanitized ? self::UNKNOWN : $sanitized;
	}

	/**
	 * Map an explicit source locale/language tag (e.g. Eventbrite `locale`
	 * such as pt_BR or en_IE) to a classification.
	 *
	 * This is the ONLY automated path and it never reads title/body text:
	 * it consumes a structured signal the source itself declared. An absent
	 * or unrecognized signal yields '' (unclassified), never a guess.
	 *
	 * @param mixed $locale Locale tag like 'pt_BR', 'en_IE', 'es_ES'.
	 * @return string One of allowed(), or '' when no signal exists.
	 */
	public static function from_locale( $locale ): string {
		$locale = strtolower( trim( (string) $locale ) );
		if ( '' === $locale ) {
			return '';
		}

		$primary = strtolower( str_replace( '-', '_', $locale ) );
		$primary = explode( '_', $primary )[0];

		if ( 'pt' === $primary ) {
			return self::PT;
		}
		if ( 'en' === $primary ) {
			return self::EN;
		}

		return self::OTHER;
	}
}
