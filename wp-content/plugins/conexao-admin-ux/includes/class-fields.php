<?php
/**
 * Field renderer and saver for the Conexão Admin UX.
 *
 * Maps configuration field definitions to the correct WordPress admin
 * control: date picker, time picker, select/dropdown, searchable town
 * selector, media library picker, URL, currency, editor, taxonomy etc.
 *
 * Also handles save + validation for every field type.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Fields {

	/**
	 * Render a single form field.
	 *
	 * @param array  $field  Field config.
	 * @param mixed  $value  Current value.
	 * @param int    $post_id Post ID.
	 * @return string HTML.
	 */
	public static function render( $field, $value, $post_id ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$key   = $field['key'];
		$label = isset( $field['label'] ) ? $field['label'] : '';
		$help  = isset( $field['help'] ) ? $field['help'] : '';
		$req   = ! empty( $field['required'] );
		$id    = 'conexao_field_' . sanitize_html_class( ltrim( $key, '_' ) );
		$name  = 'conexao_fields[' . esc_attr( ltrim( $key, '_' ) ) . ']';

		$aria_req = $req ? ' aria-required="true"' : '';
		$req_html = $req ? ' <span class="conexao-required" aria-hidden="true">*</span>' : '';

		// Do NOT render the HTML5 `required` attribute. On a brand-new post
		// (auto-draft) the virtual title field is empty, so the browser's
		// native validation would block form submission entirely — making the
		// Publish button appear "disabled" with no admin UX explanation.
		// Required-field enforcement is handled server-side in validate(),
		// which produces a clear, specific error message on the redirect.
		$required_attr = '';

		$html = '';
		$html .= '<div class="conexao-field conexao-field--' . esc_attr( $type ) . '" data-field-key="' . esc_attr( $key ) . '">';
		$html .= '<label class="conexao-field-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $req_html . '</label>';

		switch ( $type ) {
			case 'text':
				$html .= '<input type="text" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $required_attr . $aria_req . ' />';
				break;

			case 'textarea':
				$html .= '<textarea class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4"' . $required_attr . $aria_req . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'editor':
				$editor_id = esc_attr( ltrim( $key, '_' ) );
				ob_start();
				wp_editor(
					$value,
					'conexao_editor_' . $editor_id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => 12,
						'media_buttons' => true,
						'tinymce'       => array( 'toolbar1' => 'bold,italic,underline,link,unlink,blockquote,bullist,numlist,alignleft,aligncenter,alignright,wp_adv', 'toolbar2' => 'formatselect,strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo' ),
						'quicktags'     => true,
					)
				);
				$html .= ob_get_clean();
				break;

			case 'date':
				$html .= '<input type="date" class="conexao-field-input conexao-date-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $required_attr . $aria_req . ' />';
				break;

			case 'time':
				$html .= '<input type="time" class="conexao-field-input conexao-time-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				break;

			case 'select':
				$options = isset( $field['options'] ) ? $field['options'] : array();
				$html   .= '<select class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $required_attr . $aria_req . '>';
				if ( ! empty( $field['placeholder'] ) ) {
					$html .= '<option value="">' . esc_html( $field['placeholder'] ) . '</option>';
				}
				foreach ( $options as $option ) {
					$html .= '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				$html .= '</select>';
				break;

			case 'taxonomy':
				$taxonomy = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'conexao_category';
				$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) );
				$current  = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				$current  = is_wp_error( $current ) ? array() : $current;
				$selected = ! empty( $current ) ? $current[0] : '';
				$html    .= '<select class="conexao-field-input" id="' . esc_attr( $id ) . '" name="conexao_taxonomies[' . esc_attr( $taxonomy ) . ']">';
				$html    .= '<option value="">' . esc_html__( '— Selecionar —', 'conexao-admin-ux' ) . '</option>';
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$html .= '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( (int) $selected, $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
					}
				}
				$html .= '</select>';
				if ( current_user_can( 'manage_categories' ) ) {
					$html .= '<p class="conexao-field-help conexao-term-links"><a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . get_post_type( $post_id ) ) ) . '" target="_blank">Gerenciar categorias →</a></p>';
				}
				break;

			case 'town':
				$html .= '<input type="text" class="conexao-field-input conexao-town-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" autocomplete="off" />';
				$html .= '<div class="conexao-town-suggestions" id="' . esc_attr( $id ) . '-suggestions" role="listbox" aria-label="Sugestões de cidades"></div>';
				// Hidden input used by the dependent county → town binding.
				$html .= '<script type="text/javascript">window.conexaoLaoisTowns = ' . wp_json_encode( Conexao_Admin_Ux_Config::laois_towns() ) . ';</script>';
				break;

			case 'url':
				$html .= '<input type="url" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="https://"' . $required_attr . $aria_req . ' />';
				break;

			case 'email':
				$html .= '<input type="email" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				break;

			case 'phone':
				$html .= '<input type="tel" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				break;

			case 'currency':
				$html .= '<div class="conexao-currency-wrap">';
				$html .= '<span class="conexao-currency-symbol">€</span>';
				$html .= '<input type="text" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="0.00 ou Grátis" inputmode="decimal" />';
				$html .= '</div>';
				break;

			case 'number':
				$html .= '<input type="number" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $required_attr . $aria_req . ' />';
				break;

			case 'media':
				// The hidden input MUST always carry the stored value verbatim
				// (attachment ID, or a legacy URL). It must never be derived
				// from whether a preview could be resolved: when no preview
				// size exists the old logic rendered an empty field, and the
				// next save silently wiped the saved image relationship.
				$value         = (string) $value;
				$attachment_id = absint( $value );
				$preview       = '';
				if ( $attachment_id ) {
					$preview_src = wp_get_attachment_image_url( $attachment_id, 'medium' );
					if ( ! $preview_src ) {
						// Fall back to the original file (e.g. logos smaller
						// than the "medium" size, or missing size metadata).
						$preview_src = wp_get_attachment_url( $attachment_id );
					}
					if ( $preview_src ) {
						$preview = '<img src="' . esc_url( $preview_src ) . '" alt="" />';
					}
				} elseif ( '' !== $value ) {
					// Legacy URL-only value: display it and keep it intact.
					$preview = '<img src="' . esc_url( $value ) . '" alt="" />';
				}
				$html         .= '<div class="conexao-media-picker" data-field-id="' . esc_attr( $id ) . '">';
				$html         .= '<input type="hidden" class="conexao-media-value" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				$html         .= '<div class="conexao-media-preview' . ( $preview ? ' has-image' : '' ) . '">' . $preview . '<span class="conexao-media-placeholder">' . esc_html__( 'Nenhuma imagem selecionada', 'conexao-admin-ux' ) . '</span></div>';
				$html         .= '<div class="conexao-media-actions">';
				$html         .= '<button type="button" class="button conexao-media-choose">' . esc_html__( 'Selecionar imagem', 'conexao-admin-ux' ) . '</button>';
				$html         .= ' <button type="button" class="button conexao-media-remove"' . ( $preview ? '' : ' style="display:none"' ) . '>' . esc_html__( 'Remover', 'conexao-admin-ux' ) . '</button>';
				$html         .= '</div></div>';
				break;

			case 'checkbox':
				$checked = ! empty( $value ) ? ' checked="checked"' : '';
				$html   .= '<label class="conexao-checkbox-label">';
				$html   .= '<input type="checkbox" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . $checked . ' />';
				$html   .= ' <span>' . esc_html__( 'Sim', 'conexao-admin-ux' ) . '</span>';
				$html   .= '</label>';
				break;

			case 'readonly':
				$html .= '<div class="conexao-readonly-value">';
				if ( empty( $value ) ) {
					$html .= '<span class="conexao-muted">' . esc_html__( '—', 'conexao-admin-ux' ) . '</span>';
				} else {
					$html .= esc_html( $value );
				}
				$html .= '</div>';
				break;

			default:
				$html .= '<input type="text" class="conexao-field-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
				break;
		}

		if ( $help ) {
			$html .= '<p class="conexao-field-help">' . esc_html( $help ) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Save fields from $_POST into post meta + taxonomies.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $config  Content type config.
	 * @param array  $data    $_POST data (wp_unslash'ed).
	 */
	public static function save( $post_id, $config, $data ) {
		$fields = self::collect_fields( $config );
		$meta   = isset( $data['conexao_fields'] ) ? $data['conexao_fields'] : array();

		foreach ( $fields as $field ) {
			$key = $field['key'];
			$id  = ltrim( $key, '_' );

			// Skip virtual fields (prefixed title etc.) that map to the post itself.
			if ( self::is_virtual( $key ) ) {
				continue;
			}

			// Taxonomies saved separately.
			if ( 'taxonomy' === $field['type'] ) {
				continue;
			}

			$value = isset( $meta[ $id ] ) ? $meta[ $id ] : '';

			switch ( $field['type'] ) {
				case 'date':
					$value = self::sanitize_date( $value );
					break;
				case 'time':
					$value = self::sanitize_time( $value );
					break;
				case 'url':
					$value = self::sanitize_url( $value );
					break;
				case 'email':
					$value = sanitize_email( $value );
					break;
				case 'phone':
					$value = sanitize_text_field( $value );
					break;
				case 'currency':
					$value = sanitize_text_field( $value );
					break;
				case 'media':
					$value = self::normalize_media_value( $value );
					break;
				case 'number':
					$value = ( '' !== $value && null !== $value ) ? absint( $value ) : '';
					break;
				case 'checkbox':
					$value = ! empty( $value ) ? 1 : 0;
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				case 'editor':
					$value = wp_kses_post( $value );
					break;
				default:
					$value = sanitize_text_field( $value );
					break;
			}

			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Save taxonomies.
		if ( isset( $data['conexao_taxonomies'] ) && is_array( $data['conexao_taxonomies'] ) ) {
			foreach ( $data['conexao_taxonomies'] as $taxonomy => $term_id ) {
				$taxonomy = sanitize_key( $taxonomy );
				$term_id  = absint( $term_id );
				if ( taxonomy_exists( $taxonomy ) ) {
					wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), $taxonomy );
				}
			}
		}

		// Keep the county taxonomy in sync for leisure posts so the public
		// /lazer/ ?county= filter works. The county is stored as a select meta
		// (_leisure_county) in the editor, but the public filter queries the
		// shared conexao_county taxonomy.
		if ( 'leisure' === get_post_type( $post_id ) && ! empty( $meta['leisure_county'] ) ) {
			$county = sanitize_text_field( $meta['leisure_county'] );
			if ( taxonomy_exists( 'conexao_county' ) ) {
				$term = term_exists( $county, 'conexao_county' );
				if ( ! $term ) {
					$term = wp_insert_term( $county, 'conexao_county' );
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = is_array( $term ) ? $term['term_id'] : $term;
					wp_set_object_terms( $post_id, array( (int) $term_id ), 'conexao_county' );
				}
			}
		}
	}

	/**
	 * Validate required fields. Returns an array of error messages.
	 *
	 * @param array $config Content type config.
	 * @param array $data   $_POST data.
	 * @return string[] List of human-readable errors.
	 */
	public static function validate( $config, $data ) {
		$errors     = array();
		$fields     = self::collect_fields( $config );
		$meta       = isset( $data['conexao_fields'] ) ? $data['conexao_fields'] : array();
		$label_map  = array(
			'event'  => array(
				'_event_date'  => 'data de início',
				'_event_title' => 'título',
			),
			'sponsor' => array(
				'_sponsor_name' => 'o nome do apoiador',
			),
			'course_provider' => array(
				'_provider_name' => 'o nome do provedor',
			),
		);

		// Virtual fields (title, content) are rendered with name
		// conexao_fields[key] but map to post_title/post_content columns.
		// The form also includes hidden post_title/content inputs that
		// mirror the current post values (empty for new auto-draft posts).
		// We must check the conexao_fields values first, then fall back
		// to the hidden post_title/content for cases where the visible
		// field was not rendered or submitted.
		$virtual_title_keys = array(
			'_event_title',
			'_news_title',
			'_guide_title',
			'_job_title',
			'_sponsor_name',
			'_provider_name',
			'_leisure_name',
		);

		$virtual_content_keys = array(
			'_event_description',
			'_news_content',
			'_guide_content',
			'_job_description',
			'_sponsor_description',
			'_provider_description',
			'_leisure_description',
		);

		foreach ( $fields as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$key = $field['key'];
			$id  = ltrim( $key, '_' );

			// For virtual title fields, check conexao_fields first (the visible
			// input), then fall back to post_title (the hidden mirror field).
			if ( in_array( $key, $virtual_title_keys, true ) ) {
				$value = isset( $meta[ $id ] ) ? trim( (string) $meta[ $id ] ) : '';
				if ( '' === $value && isset( $data['post_title'] ) ) {
					$value = trim( (string) $data['post_title'] );
				}
			} elseif ( in_array( $key, $virtual_content_keys, true ) ) {
				// For virtual content fields, check conexao_fields first,
				// then fall back to content.
				$value = isset( $meta[ $id ] ) ? trim( (string) $meta[ $id ] ) : '';
				if ( '' === $value && isset( $data['content'] ) ) {
					$value = trim( (string) $data['content'] );
				}
			} else {
				$value = isset( $meta[ $id ] ) ? trim( (string) $meta[ $id ] ) : '';
			}

			if ( '' === $value ) {
				$label = isset( $label_map[ $config['post_type'] ][ $key ] )
					? $label_map[ $config['post_type'] ][ $key ]
					: strtolower( $field['label'] );
				$errors[] = 'Por favor, informe ' . $label . '.';
			}
		}

		return $errors;
	}

	/**
	 * Collect all field configs from all sections.
	 *
	 * @param array $config Content type config.
	 * @return array
	 */
	public static function collect_fields( $config ) {
		$fields = array();
		if ( empty( $config['sections'] ) ) {
			return $fields;
		}
		foreach ( $config['sections'] as $section ) {
			if ( ! empty( $section['fields'] ) ) {
				$fields = array_merge( $fields, $section['fields'] );
			}
		}
		return $fields;
	}

	/**
	 * Whether a field key maps to a virtual/prefixed field (title, content).
	 *
	 * These are handled directly by the editor form (post_title, post_content).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_virtual( $key ) {
		$virtual = array( '_event_title', '_event_description', '_news_title', '_news_content', '_guide_title', '_guide_content', '_job_title', '_job_description', '_sponsor_name', '_sponsor_description', '_provider_name', '_provider_description', '_leisure_name', '_leisure_description' );
		return in_array( $key, $virtual, true );
	}

	/**
	 * Sanitize a date into Y-m-d.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		// d/m/Y or d.m.Y (European format used in Ireland).
		if ( preg_match( '/^(\d{1,2})[\/\.](\d{1,2})[\/\.](\d{4})/', $value, $m ) ) {
			$ts = strtotime( $m[3] . '-' . $m[2] . '-' . $m[1] );
			return $ts ? date( 'Y-m-d', $ts ) : '';
		}
		$ts = strtotime( $value );
		return $ts ? date( 'Y-m-d', $ts ) : '';
	}

	/**
	 * Sanitize a time into H:i (24h).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}
		if ( preg_match( '/^(\d{1,2}):(\d{2})\s*([AaPp][Mm])?$/', $value, $m ) ) {
			$hour = (int) $m[1];
			$min  = (int) $m[2];
			$ampm = isset( $m[3] ) ? strtolower( $m[3] ) : '';
			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			}
			return sprintf( '%02d:%02d', $hour, $min );
		}
		return '';
	}

	/**
	 * Normalize a media field value into a Media Library attachment ID.
	 *
	 * Numeric values are kept as attachment IDs — the single source of truth
	 * for the Apoiador → attachment relationship. Legacy URL values (stored
	 * before the editor standardized on IDs) are resolved back to an existing
	 * Media Library attachment via attachment_url_to_postid(); unresolvable
	 * legacy URLs are preserved as-is instead of being destroyed.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int|string Attachment ID (int), legacy URL (string), or ''.
	 */
	public static function normalize_media_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0' === $value ) {
			return '';
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			$id = attachment_url_to_postid( $value );
			if ( $id ) {
				return (int) $id;
			}
			return esc_url_raw( $value );
		}

		return '';
	}

	/**
	 * Sanitize an absolute http/https URL.
	 *
	 * Ensures only well-formed, absolute http(s) URLs are allowed. Malformed
	 * URLs, dangerously-protocoled URLs (e.g. javascript:, data:, file:) and
	 * empty values result in an empty string. This is used for all `url`
	 * field types so that stored values remain safe when output later.
	 *
	 * @param string $value Raw URL value.
	 * @return string Sanitized URL, or '' when invalid.
	 */
	public static function sanitize_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// Reject any non-http(s) scheme outright (javascript:, data:, file:, etc.).
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		// Use WordPress's standard URL sanitization as the final gatekeeper.
		$clean = esc_url_raw( $value );
		if ( ! $clean || $clean !== esc_url_raw( $clean ) ) {
			// esc_url strips dangerous protocols; if the result lost its
			// scheme or differs, treat as invalid.
			return '';
		}

		return $clean;
	}

	/**
	 * Get the saved value for a field config from a post.
	 *
	 * @param WP_Post $post  Post object.
	 * @param array   $field Field config.
	 * @return mixed
	 */
	public static function get_value( $post, $field ) {
		$key = $field['key'];

		// Virtual fields map to the post object itself.
		switch ( $key ) {
			case '_event_title':
			case '_news_title':
			case '_guide_title':
			case '_job_title':
			case '_sponsor_name':
			case '_provider_name':
			case '_leisure_name':
				return $post->post_title;
			case '_event_description':
			case '_news_content':
			case '_guide_content':
			case '_job_description':
			case '_sponsor_description':
			case '_provider_description':
			case '_leisure_description':
				return $post->post_content;
		}

		// County and Town: prefer taxonomy (public site uses taxonomies for
		// filtering), fall back to stored meta.
		if ( '_event_county' === $key || '_guide_county' === $key || '_leisure_county' === $key ) {
			$terms = wp_get_object_terms( $post->ID, 'conexao_county', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return $terms[0];
			}
		}

		if ( '_event_town' === $key || '_guide_town' === $key || '_leisure_town' === $key ) {
			$terms = wp_get_object_terms( $post->ID, 'conexao_town', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return $terms[0];
			}
		}

		// Event venue fallback: legacy _event_location may hold the venue.
		if ( '_event_venue' === $key ) {
			$venue = get_post_meta( $post->ID, '_event_venue', true );
			if ( $venue ) {
				return $venue;
			}
			return get_post_meta( $post->ID, '_event_location', true );
		}

		return get_post_meta( $post->ID, $key, true );
	}

	/**
	 * Sync legacy meta used by the public theme.
	 *
	 * The public event-card theme template reads _event_time and
	 * _event_location. This helper keeps those in sync with the new
	 * structured fields so the public site keeps working unchanged.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Unslashed POST data.
	 */
	public static function sync_legacy_event_meta( $post_id, $data ) {
		if ( 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		$fields = isset( $data['conexao_fields'] ) ? $data['conexao_fields'] : array();

		// Legacy _event_time = start — end (or just start).
		$start_time = isset( $fields['event_start_time'] ) ? self::sanitize_time( $fields['event_start_time'] ) : get_post_meta( $post_id, '_event_start_time', true );
		$end_time   = isset( $fields['event_end_time'] ) ? self::sanitize_time( $fields['event_end_time'] ) : get_post_meta( $post_id, '_event_end_time', true );
		if ( $start_time && $end_time ) {
			update_post_meta( $post_id, '_event_time', $start_time . ' — ' . $end_time );
		} elseif ( $start_time ) {
			update_post_meta( $post_id, '_event_time', $start_time );
		}

		// Legacy _event_location = venue or town.
		$venue = isset( $fields['event_venue'] ) ? sanitize_text_field( $fields['event_venue'] ) : get_post_meta( $post_id, '_event_venue', true );
		$town  = isset( $fields['event_town'] ) ? sanitize_text_field( $fields['event_town'] ) : get_post_meta( $post_id, '_event_town', true );
		if ( $venue ) {
			update_post_meta( $post_id, '_event_location', $venue );
		} elseif ( $town ) {
			update_post_meta( $post_id, '_event_location', $town );
		}

		// Ensure county + town taxonomies stay in sync with structured fields.
		$county = isset( $fields['event_county'] ) ? sanitize_text_field( $fields['event_county'] ) : '';
		if ( $county && taxonomy_exists( 'conexao_county' ) ) {
			$term = term_exists( $county, 'conexao_county' );
			if ( ! $term ) {
				$term = wp_insert_term( $county, 'conexao_county' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = is_array( $term ) ? $term['term_id'] : $term;
				wp_set_object_terms( $post_id, array( (int) $term_id ), 'conexao_county' );
			}
		} elseif ( '' === $county && isset( $fields['event_county'] ) ) {
			wp_set_object_terms( $post_id, array(), 'conexao_county' );
		}

		$town_meta = isset( $fields['event_town'] ) ? sanitize_text_field( $fields['event_town'] ) : '';
		if ( $town_meta && taxonomy_exists( 'conexao_town' ) ) {
			$term = term_exists( $town_meta, 'conexao_town' );
			if ( ! $term ) {
				$term = wp_insert_term( $town_meta, 'conexao_town' );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$term_id = is_array( $term ) ? $term['term_id'] : $term;
				wp_set_object_terms( $post_id, array( (int) $term_id ), 'conexao_town' );
			}
		} elseif ( '' === $town_meta && isset( $fields['event_town'] ) ) {
			wp_set_object_terms( $post_id, array(), 'conexao_town' );
		}
	}
}
 