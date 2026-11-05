<?php
/** Editorial fields used by the existing homepage cards. */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Meta {

	private static $fields = array(
		'guide'   => array( '_conexao_featured' => 'checkbox' ),
		'event'   => array( '_event_date' => 'date', '_event_time' => 'text', '_event_location' => 'text' ),
		'sponsor' => array( '_sponsor_link' => 'url', '_sponsor_display_order' => 'text' ),
		'job'     => array( '_job_company' => 'text', '_job_location' => 'text', '_job_salary' => 'text', '_job_employment_type' => 'text', '_job_expiration_date' => 'date' ),
	);

	/**
	 * Leisure attraction coordinates (Lazer location services).
	 *
	 * Latitude/longitude are stored as canonical decimal STRINGS. An empty
	 * string means "not supplied" and is never coerced to zero; a legitimate
	 * zero coordinate ("0") is stored and preserved as-is. Values are
	 * validated to the geographic range and normalised to a plain
	 * dot-separated decimal (no exponent, no locale comma).
	 */
	const LEISURE_LAT_KEY = '_leisure_lat';
	const LEISURE_LNG_KEY = '_leisure_lng';

	/**
	 * Coordinate PROVENANCE (editorial metadata).
	 *
	 * `_leisure_coord_source` records how the coordinates were obtained and
	 * `_leisure_coord_verified` records whether an editor confirmed them
	 * against the attraction's real map location. Both are deliberately kept
	 * OUT of the REST API (`show_in_rest => false`) — only the coordinate
	 * values themselves are exposed, and only server-side consumers read the
	 * provenance.
	 */
	const LEISURE_COORD_SOURCE_KEY   = '_leisure_coord_source';
	const LEISURE_COORD_VERIFIED_KEY = '_leisure_coord_verified';

	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ), 1 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
	}

	public function register_meta() {
		foreach ( self::$fields as $post_type => $fields ) {
			foreach ( $fields as $key => $type ) {
				register_post_meta( $post_type, $key, array( 'single' => true, 'type' => 'checkbox' === $type ? 'boolean' : 'string', 'show_in_rest' => true, 'sanitize_callback' => 'url' === $type ? 'esc_url_raw' : ( 'checkbox' === $type ? 'rest_sanitize_boolean' : 'sanitize_text_field' ) ) );
			}
		}

		$this->register_leisure_coordinates();
	}

	/**
	 * Register the leisure coordinate meta (and its editorial provenance).
	 *
	 * `register_post_meta()` runs the `sanitize_callback` on EVERY write path
	 * (REST, the admin-ux editor's update_post_meta(), importers), so an
	 * out-of-range or malformed value can never be persisted through any of
	 * them. The two coordinate values are readable through REST; the
	 * provenance fields stay private to the server.
	 *
	 * Both coordinate keys are PROTECTED (underscore-prefixed), so REST write
	 * permission must be declared explicitly: without an `auth_callback`,
	 * `register_meta()` falls back to `__return_false` for protected keys and
	 * every REST write fails with `403 rest_cannot_update` — even for an
	 * administrator. The callback allows exactly what the wp-admin editor
	 * allows: a user who can edit the leisure record. It is the same pattern
	 * as the `_leisure_image_*` keys (conexao-data-model.php) and the
	 * agency/permit-employer meta; it is deliberately NOT `__return_true`, so
	 * an anonymous or unprivileged caller stays refused.
	 *
	 * `_leisure_coord_source` / `_leisure_coord_verified` are not writable over
	 * REST at all (`show_in_rest => false`), so they declare no auth_callback
	 * and the REST surface is unchanged by this permission fix.
	 */
	private function register_leisure_coordinates() {
		register_post_meta(
			'leisure',
			self::LEISURE_LAT_KEY,
			array(
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_latitude' ),
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			)
		);

		register_post_meta(
			'leisure',
			self::LEISURE_LNG_KEY,
			array(
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_longitude' ),
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_post', $object_id );
				},
			)
		);

		register_post_meta(
			'leisure',
			self::LEISURE_COORD_SOURCE_KEY,
			array(
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_post_meta(
			'leisure',
			self::LEISURE_COORD_VERIFIED_KEY,
			array(
				'single'            => true,
				'type'              => 'boolean',
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * Sanitize a latitude value (-90..90).
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical decimal string, or '' when invalid/missing.
	 */
	public static function sanitize_latitude( $value ) {
		return self::sanitize_coordinate( $value, -90.0, 90.0 );
	}

	/**
	 * Sanitize a longitude value (-180..180).
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical decimal string, or '' when invalid/missing.
	 */
	public static function sanitize_longitude( $value ) {
		return self::sanitize_coordinate( $value, -180.0, 180.0 );
	}

	/**
	 * Validate + normalise a coordinate.
	 *
	 * Rules (single source of truth for every write path):
	 *   - empty / null / array → '' (missing, never 0);
	 *   - non-numeric → '' (rejected, never guessed);
	 *   - out of range → '' (rejected);
	 *   - a legitimate zero → '0';
	 *   - otherwise a plain dot-separated decimal string.
	 *
	 * @param mixed $value Raw value.
	 * @param float $min   Inclusive minimum.
	 * @param float $max   Inclusive maximum.
	 * @return string
	 */
	public static function sanitize_coordinate( $value, $min, $max ) {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}

		// Accept a leading + and a comma decimal separator (editors paste
		// values from Google Maps in both shapes). Everything else — text,
		// exponents, "1.2.3" — is rejected.
		$normalised = str_replace( ',', '.', $raw );
		if ( ! preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $normalised ) ) {
			return '';
		}

		$number = (float) $normalised;
		if ( ! is_finite( $number ) || $number < $min || $number > $max ) {
			return '';
		}

		if ( 0.0 === $number ) {
			return '0';
		}

		// Canonical form: up to 6 decimal places (≈0.11 m precision), no
		// trailing zeros, no locale formatting.
		$canonical = rtrim( rtrim( number_format( $number, 6, '.', '' ), '0' ), '.' );

		return '' === $canonical || '-' === $canonical ? '0' : $canonical;
	}

	/**
	 * Whether a latitude string is a valid coordinate.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_valid_latitude( $value ) {
		if ( null === $value || is_array( $value ) ) {
			return false;
		}
		$raw = trim( (string) $value );
		return '' !== $raw && '' !== self::sanitize_coordinate( $raw, -90.0, 90.0 );
	}

	/**
	 * Whether a longitude string is a valid coordinate.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_valid_longitude( $value ) {
		if ( null === $value || is_array( $value ) ) {
			return false;
		}
		$raw = trim( (string) $value );
		return '' !== $raw && '' !== self::sanitize_coordinate( $raw, -180.0, 180.0 );
	}

	/**
	 * The stored coordinates of a leisure record.
	 *
	 * @param int $post_id Post ID.
	 * @return array{lat:float,lng:float}|null Null when missing or invalid.
	 */
	public static function get_coordinates( $post_id ) {
		$lat = get_post_meta( (int) $post_id, self::LEISURE_LAT_KEY, true );
		$lng = get_post_meta( (int) $post_id, self::LEISURE_LNG_KEY, true );

		if ( ! self::is_valid_latitude( $lat ) || ! self::is_valid_longitude( $lng ) ) {
			return null;
		}

		return array(
			'lat' => (float) self::sanitize_coordinate( $lat, -90.0, 90.0 ),
			'lng' => (float) self::sanitize_coordinate( $lng, -180.0, 180.0 ),
		);
	}

	/**
	 * The coordinate inventory state of a leisure record.
	 *
	 * Distinguishes the four editorial states an administrator must be able
	 * to see in the list: nothing supplied yet, supplied but not confirmed,
	 * confirmed against the real map location, and a stored value that is
	 * malformed or out of range.
	 *
	 * @param int $post_id Post ID.
	 * @return array{status:string,lat:string,lng:string}
	 */
	public static function coordinate_status( $post_id ) {
		$post_id = (int) $post_id;
		$lat_raw = get_post_meta( $post_id, self::LEISURE_LAT_KEY, true );
		$lng_raw = get_post_meta( $post_id, self::LEISURE_LNG_KEY, true );
		$lat     = is_string( $lat_raw ) ? trim( $lat_raw ) : '';
		$lng     = is_string( $lng_raw ) ? trim( $lng_raw ) : '';

		if ( '' === $lat && '' === $lng ) {
			return array( 'status' => 'missing', 'lat' => '', 'lng' => '' );
		}

		if ( ! self::is_valid_latitude( $lat ) || ! self::is_valid_longitude( $lng ) ) {
			return array( 'status' => 'invalid', 'lat' => $lat, 'lng' => $lng );
		}

		$verified = (bool) get_post_meta( $post_id, self::LEISURE_COORD_VERIFIED_KEY, true );

		return array(
			'status' => $verified ? 'verified' : 'unverified',
			'lat'    => $lat,
			'lng'    => $lng,
		);
	}

	public function add_meta_boxes() {
		foreach ( self::$fields as $post_type => $fields ) {
			add_meta_box( 'conexao_' . $post_type . '_details', ucfirst( $post_type ) . ' details', array( $this, 'render_meta_box' ), $post_type, 'normal', 'default', $fields );
		}
	}

	public function render_meta_box( $post, $box ) {
		wp_nonce_field( 'conexao_data_model_meta', 'conexao_data_model_nonce' );
		foreach ( $box['args'] as $key => $type ) {
			$id = ltrim( $key, '_' );
			$label = ucwords( str_replace( array( '_', 'conexao', 'sponsor', 'job' ), array( ' ', '', '', '' ), $id ) );
			if ( 'checkbox' === $type ) {
				printf( '<p><label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label></p>', esc_attr( $id ), checked( (bool) get_post_meta( $post->ID, $key, true ), true, false ), esc_html( $label ) );
				continue;
			}
			printf( '<p><label for="%1$s">%2$s</label><input class="widefat" type="%3$s" id="%1$s" name="%1$s" value="%4$s"></p>', esc_attr( $id ), esc_html( $label ), esc_attr( $type ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
		}
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['conexao_data_model_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conexao_data_model_nonce'] ) ), 'conexao_data_model_meta' ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( empty( self::$fields[ $post_type ] ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Skip post types managed by the Conexão Admin UX plugin.
		// The admin UX plugin has its own save handler that persists all
		// fields through Conexao_Admin_Ux_Fields::save(). Running this
		// legacy handler alongside it would be redundant and could cause
		// conflicts (e.g. overwriting values with empty POST data because
		// the admin UX form uses different field names).
		if ( class_exists( 'Conexao_Admin_Ux_Config' ) ) {
			$managed_types = Conexao_Admin_Ux_Config::SUPPORTED_TYPES;
			if ( in_array( $post_type, $managed_types, true ) ) {
				return;
			}
		}
		foreach ( self::$fields[ $post_type ] as $key => $type ) {
			$id = ltrim( $key, '_' );
			if ( 'checkbox' === $type ) {
				update_post_meta( $post_id, $key, isset( $_POST[ $id ] ) ? 1 : 0 );
			} elseif ( isset( $_POST[ $id ] ) ) {
				$value = wp_unslash( $_POST[ $id ] );
				update_post_meta( $post_id, $key, 'url' === $type ? esc_url_raw( $value ) : sanitize_text_field( $value ) );
			}
		}
	}
}

new Conexao_Data_Model_Meta();
