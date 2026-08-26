<?php
/**
 * Apoiador contacts model.
 *
 * Structured repeater-style storage for multiple contact/social links per
 * sponsor (Apoiador). All rows live in ONE post meta key (`_sponsor_contacts`)
 * as an ordered list, instead of one meta field per platform:
 *
 *   Apoiador
 *   └── _sponsor_contacts
 *       ├── [ 'type' => 'instagram', 'url' => 'https://instagram.com/...' ]
 *       ├── [ 'type' => 'whatsapp',  'url' => 'https://wa.me/353871234567' ]
 *       └── ...
 *
 * Design notes:
 *
 * - One serialized meta key (not hardcoded per-platform fields such as
 *   `_sponsor_instagram`, `_sponsor_facebook`, …). The project previously
 *   used that pattern and removed it via scripts/cleanup-sponsor-fields.php;
 *   this repeater replaces it in an extensible, export-friendly way.
 * - The canonical/official website REMAINS the separate `_sponsor_link` meta
 *   (archive cards, homepage carousel and SEO schema click through to it).
 *   A Website-type row here is an additional link, never a replacement.
 * - Row order is meaningful: it preserves insertion order and the admin
 *   editor exposes up/down controls. Front-end consumers should render rows
 *   in stored order.
 * - Every write path (admin editor save, import) funnels through
 *   sanitize_rows(), so stored data is always safe to output and free of
 *   unsafe protocols (javascript:, data:, …). Only http(s) links and
 *   mailto: addresses are accepted.
 *
 * @package Conexao_Data_Model
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Contacts {

	/**
	 * Meta key storing the ordered contact rows for a sponsor.
	 */
	const META_KEY = '_sponsor_contacts';

	/**
	 * Supported contact types (value => label).
	 *
	 * Labels follow the project's pt-BR admin language conventions.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'website'   => 'Website',
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'whatsapp'  => 'WhatsApp',
			'linkedin'  => 'LinkedIn',
			'tiktok'    => 'TikTok',
			'email'     => 'E-mail',
			'outro'     => 'Outro',
		);
	}

	/**
	 * Human-readable label for a type value.
	 *
	 * Unknown/missing values fall back to "Outro".
	 *
	 * @param string $type Type slug.
	 * @return string
	 */
	public static function type_label( $type ) {
		$types = self::types();
		$type  = sanitize_key( (string) $type );
		return isset( $types[ $type ] ) ? $types[ $type ] : $types['outro'];
	}

	/**
	 * Get the stored contacts for a sponsor, normalized.
	 *
	 * Always returns a list of well-formed rows:
	 * `[ ['type' => 'instagram', 'url' => 'https://…'], … ]`.
	 *
	 * @param int $post_id Sponsor post ID.
	 * @return array<int,array{type:string,url:string}>
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( absint( $post_id ), self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return self::normalize( $raw );
	}

	/**
	 * Sanitize and persist the contacts for a sponsor.
	 *
	 * An empty sanitized result deletes the meta entirely, keeping the
	 * database clean for records without contacts.
	 *
	 * @param int   $post_id Sponsor post ID.
	 * @param array $rows    Raw rows (unslashed). See sanitize_rows().
	 * @return array The sanitized rows that were stored.
	 */
	public static function update( $post_id, $rows ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		$clean = self::sanitize_rows( $rows );

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return array();
		}

		update_post_meta( $post_id, self::META_KEY, $clean );

		return $clean;
	}

	/**
	 * Sanitize submitted rows.
	 *
	 * Expects UNSLASHED input (the admin editor passes wp_unslash'ed POST
	 * data; the importer passes JSON-decoded data). Rules:
	 *
	 * - Rows with an empty value are dropped silently (never persisted).
	 * - Unknown/missing types are coerced to "outro".
	 * - URL types accept http(s) links only. Scheme-less domains
	 *   ("instagram.com/foo") are prefixed with https:// to preserve the
	 *   administrator's intent. Unsafe protocols are rejected.
	 * - WhatsApp additionally accepts bare phone numbers (with or without
	 *   "+" and formatting), normalized to https://wa.me/<digits>. Full
	 *   WhatsApp links (wa.me, api.whatsapp.com, chat.whatsapp.com) pass
	 *   through the standard URL rules — no single format is forced.
	 * - E-mail values are validated and stored as mailto: addresses so
	 *   every stored row is directly usable as a link href.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array{type:string,url:string}>
	 */
	public static function sanitize_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
				$type = 'outro';
			}

			$raw = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			if ( '' === $raw ) {
				continue;
			}

			$url = self::sanitize_contact_value( $type, $raw );
			if ( '' === $url ) {
				continue;
			}

			$clean[] = array(
				'type' => $type,
				'url'  => $url,
			);
		}

		return $clean;
	}

	/**
	 * Validate submitted rows without persisting anything.
	 *
	 * Returns human-readable (pt-BR) error messages for rows that carried
	 * input but failed sanitization, so the administrator sees exactly which
	 * contact was rejected and why. Empty rows produce no errors (they are
	 * dropped silently by sanitize_rows()).
	 *
	 * @param mixed $rows Raw rows (unslashed).
	 * @return string[]
	 */
	public static function validate_submission( $rows ) {
		$errors = array();
		if ( ! is_array( $rows ) ) {
			return $errors;
		}

		$position = 0;

		foreach ( $rows as $row ) {
			$position++;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
				$type = 'outro';
			}

			$raw = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			if ( '' === $raw ) {
				continue;
			}

			if ( '' === self::sanitize_contact_value( $type, $raw ) ) {
				if ( 'email' === $type ) {
					/* translators: 1: row number, 2: contact type label */
					$errors[] = sprintf(
						__( 'Contato %1$d (%2$s): informe um e-mail válido.', 'conexao-data-model' ),
						$position,
						self::type_label( $type )
					);
				} else {
					/* translators: 1: row number, 2: contact type label */
					$errors[] = sprintf(
						__( 'Contato %1$d (%2$s): informe um endereço válido começando com https://.', 'conexao-data-model' ),
						$position,
						self::type_label( $type )
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Value displayed inside the admin input for a stored row.
	 *
	 * E-mail rows are stored as mailto: addresses; the bare address is what
	 * the administrator expects to see and edit.
	 *
	 * @param string $type Contact type.
	 * @param string $url  Stored value.
	 * @return string
	 */
	public static function display_value( $type, $url ) {
		if ( 'email' === $type && 0 === stripos( (string) $url, 'mailto:' ) ) {
			return substr( (string) $url, 7 );
		}

		return (string) $url;
	}

	/**
	 * Normalize already-stored data into well-formed rows.
	 *
	 * Used by get(). Unlike sanitize_rows(), this does NOT re-run URL
	 * sanitation — stored values were sanitized at write time — but it still
	 * guarantees the row shape and drops malformed/empty entries defensively.
	 *
	 * @param mixed $rows Stored rows.
	 * @return array<int,array{type:string,url:string}>
	 */
	private static function normalize( $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
				$type = 'outro';
			}

			$url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}

			$out[] = array(
				'type' => $type,
				'url'  => $url,
			);
		}

		return $out;
	}

	/**
	 * Sanitize one contact value according to its type.
	 *
	 * @param string $type Contact type slug.
	 * @param string $raw  Raw submitted value (trimmed).
	 * @return string Sanitized value, or '' when invalid.
	 */
	private static function sanitize_contact_value( $type, $raw ) {
		$value = trim( (string) $raw );
		if ( '' === $value ) {
			return '';
		}

		// E-mail: store as a mailto: link so every row is a usable href.
		if ( 'email' === $type ) {
			if ( 0 === stripos( $value, 'mailto:' ) ) {
				$value = substr( $value, 7 );
			}
			$email = sanitize_email( $value );
			return $email ? 'mailto:' . $email : '';
		}

		// WhatsApp: accept bare numbers (with or without "+" and common
		// formatting) and normalize them to a wa.me deep link. Full links
		// fall through to the standard URL rules below, so wa.me,
		// api.whatsapp.com/send and chat.whatsapp.com formats all work.
		if ( 'whatsapp' === $type && preg_match( '/^[\+(]?[0-9][0-9\s\-()]{5,}$/', $value ) ) {
			$digits = preg_replace( '/[^0-9]/', '', $value );
			if ( is_string( $digits ) && strlen( $digits ) >= 8 ) {
				return 'https://wa.me/' . $digits;
			}
			return '';
		}

		// Scheme-less domain input ("instagram.com/foo") keeps the
		// administrator's intent: assume https:// instead of rejecting.
		// Note the % delimiter: the pattern itself contains "#" (in the
		// fragment part of [/?#]), which would terminate a #-delimited
		// pattern early.
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value )
			&& preg_match( '%^[a-z0-9\-]+(\.[a-z0-9\-]+)+([/?#].*)?$%i', $value ) ) {
			$value = 'https://' . $value;
		}

		// Only http(s) is allowed for contact links. javascript:, data:,
		// file: and any other protocol are rejected outright.
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		// WordPress's standard URL sanitization as the final gatekeeper.
		$clean = esc_url_raw( $value );
		if ( ! $clean || $clean !== esc_url_raw( $clean ) ) {
			return '';
		}

		return $clean;
	}
}