<?php
/** Editorial fields used by the existing homepage cards. */

defined( 'ABSPATH' ) || exit;

final class Conexao_Data_Model_Meta {

	private static $fields = array(
		'guide'    => array( '_conexao_featured' => 'checkbox' ),
		'event'    => array( '_event_date' => 'date', '_event_time' => 'text', '_event_location' => 'text' ),
		'business' => array( '_conexao_featured' => 'checkbox', '_business_phone' => 'text', '_business_whatsapp' => 'url', '_business_website' => 'url', '_business_location' => 'text' ),
		'job'      => array( '_job_company' => 'text', '_job_location' => 'text', '_job_salary' => 'text', '_job_employment_type' => 'text', '_job_expiration_date' => 'date' ),
	);

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
			$label = ucwords( str_replace( array( '_', 'conexao', 'event', 'business', 'job' ), array( ' ', '', '', '', '' ), $id ) );
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
