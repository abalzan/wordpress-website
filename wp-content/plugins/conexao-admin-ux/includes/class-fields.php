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

		// Conditional visibility (config `conditional_on`): rendered fields
		// carry a `conexao-conditional` class plus data attributes describing
		// the controlling field + value; admin.js shows/hides them generically.
		$class_extra = '';
		$data_cond   = '';
		if ( ! empty( $field['conditional_on'] ) && is_array( $field['conditional_on'] ) ) {
			$cond_field = isset( $field['conditional_on']['field'] ) ? $field['conditional_on']['field'] : '';
			$cond_value = isset( $field['conditional_on']['value'] ) ? $field['conditional_on']['value'] : '';
			if ( '' !== $cond_field ) {
				$class_extra = ' conexao-conditional';
				$data_cond   = ' data-conditional-field="' . esc_attr( $cond_field ) . '" data-conditional-value="' . esc_attr( (string) $cond_value ) . '"';
			}
		}

		$html .= '<div class="conexao-field conexao-field--' . esc_attr( $type ) . $class_extra . '" data-field-key="' . esc_attr( $key ) . '"' . $data_cond . '>';
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

			case 'multiselect':
				// Checkbox group: options is a value => label map. The value
				// is stored as a comma-separated list of canonical keys, so
				// the submitted array is normalized on save (never trust the
				// raw values) and the frontend renders consistent labels.
				$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$selected = is_array( $value )
					? $value
					: array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' );
				$selected = array_map( 'strval', (array) $selected );

				$html .= '<div class="conexao-multiselect">';
				foreach ( $options as $option_value => $option_label ) {
					$opt_id  = $id . '-' . sanitize_html_class( $option_value );
					$checked = in_array( (string) $option_value, $selected, true ) ? ' checked="checked"' : '';
					$html   .= '<label class="conexao-multiselect-option" for="' . esc_attr( $opt_id ) . '">';
					$html   .= '<input type="checkbox" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $option_value ) . '"' . $checked . ' />';
					$html   .= ' <span>' . esc_html( $option_label ) . '</span>';
					$html   .= '</label>';
				}
				$html .= '</div>';
				break;

case 'radio':
				// Radio button group: options is a value => label map.
				// The stored value is one of the option keys (or '' when
				// a blank option exists, e.g. "Evento único"). Whitelisted
				// on save.
				$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$html    .= '<div class="conexao-radio-options">';
				foreach ( $options as $option_value => $option_label ) {
					$opt_id  = $id . '-' . sanitize_html_class( '' === (string) $option_value ? 'none' : $option_value );
					$checked = ( (string) $value === (string) $option_value ) ? ' checked="checked"' : '';
					$html    .= '<label class="conexao-radio-option" for="' . esc_attr( $opt_id ) . '">';
					$html    .= '<input type="radio" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $option_value ) . '"' . $checked . ' />';
					$html    .= ' <span>' . esc_html( $option_label ) . '</span>';
					$html    .= '</label>';
				}
				$html .= '</div>';
				break;

			case 'weekdays':
				// Weekday multi-checkbox group: options is an ISO weekday
				// number (1=Mon … 7=Sun) => label map. The stored value is
				// a CSV of ascending ISO weekday numbers (e.g. "1,3");
				// sanitize_weekdays() rejects non-numeric values, values
				// outside 1–7, and duplicates, normalizing to ascending
				// order deterministically.
				$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$selected = array_map( 'strval', self::sanitize_weekdays( $value ) );

				$html .= '<div class="conexao-weekdays">';
				foreach ( $options as $option_value => $option_label ) {
					$opt_id  = $id . '-' . sanitize_html_class( $option_value );
					$checked = in_array( (string) $option_value, $selected, true ) ? ' checked="checked"' : '';
					$html    .= '<label class="conexao-weekday-option" for="' . esc_attr( $opt_id ) . '">';
					$html    .= '<input type="checkbox" id="' . esc_attr( $opt_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $option_value ) . '"' . $checked . ' />';
					$html    .= ' <span>' . esc_html( $option_label ) . '</span>';
					$html    .= '</label>';
				}
				$html .= '</div>';
				break;

			case 'static':
				// Display-only row (e.g. "Repetição: Semanal"): no input,
				// never saved. The label renders above via the generic
				// label markup.
				$static_text = isset( $field['static_text'] ) ? $field['static_text'] : '';
				$html       .= '<div class="conexao-static-value">' . esc_html( $static_text ) . '</div>';
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

			case 'contacts':
				// Structured repeater (Apoiadores): ordered rows of
				// [type, url] stored as a single array meta. Rows are
				// rendered server-side from the stored value so a first
				// save of a brand-new post works without any client-side
				// hydration; JS only adds/removes/reorders rows.
				$rows  = is_array( $value ) ? array_values( $value ) : array();
				$types = class_exists( 'Conexao_Data_Model_Contacts' )
					? Conexao_Data_Model_Contacts::types()
					: array();

				$html .= '<div class="conexao-contacts" data-next-index="' . esc_attr( count( $rows ) ) . '">';
				$html .= '<div class="conexao-contacts-rows">';
				foreach ( $rows as $row_index => $row ) {
					$row_type = isset( $row['type'] ) ? $row['type'] : 'outro';
					$row_url  = isset( $row['url'] ) ? $row['url'] : '';
					if ( class_exists( 'Conexao_Data_Model_Contacts' ) ) {
						$row_url = Conexao_Data_Model_Contacts::display_value( $row_type, $row_url );
					}
					$html .= self::contact_row( $row_index, $row_type, $row_url, $types );
				}
				$html .= '</div>';
				$html .= '<p class="conexao-contacts-empty"' . ( $rows ? ' hidden' : '' ) . '>' . esc_html__( 'Nenhum contato adicional.', 'conexao-admin-ux' ) . '</p>';
				$html .= '<button type="button" class="button conexao-contacts-add">+ ' . esc_html__( 'Adicionar contato', 'conexao-admin-ux' ) . '</button>';
				// Row template cloned by admin.js when adding a contact.
				// __INDEX__ is replaced with an unused row index; the save
				// handler iterates rows in submission order and never trusts
				// the indexes themselves.
				$html .= '<script type="text/html" class="conexao-contacts-template">' . self::contact_row( '__INDEX__', '', '', $types ) . '</script>';
				$html .= '</div>';
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

			// Display-only fields (e.g. "Repetição: Semanal") have no input
			// and no meta key to persist.
			if ( 'static' === $field['type'] ) {
				continue;
			}

			// Event recurrence metadata is persisted atomically by
			// save_event_recurrence() below so that invalid rules are never
			// stored and switching back to "Evento único" clears the entire
			// group.
			if ( 'event' === get_post_type( $post_id ) && in_array(
				$key,
				array( '_event_recurrence', '_event_recurrence_days', '_event_recurrence_start', '_event_recurrence_end' ),
				true
			) ) {
				continue;
			}

			$value = isset( $meta[ $id ] ) ? $meta[ $id ] : '';

			switch ( $field['type'] ) {
				case 'contacts':
					// Structured repeater: sanitize every row through the
					// shared model so unsafe protocols never reach storage.
					// An empty result deletes the meta entirely.
					$rows = ( isset( $meta[ $id ] ) && is_array( $meta[ $id ] ) ) ? $meta[ $id ] : array();
					if ( class_exists( 'Conexao_Data_Model_Contacts' ) ) {
						$value = Conexao_Data_Model_Contacts::sanitize_rows( $rows );
					} else {
						$value = array();
					}
					if ( empty( $value ) ) {
						$value = ''; // Triggers delete_post_meta below.
					}
					break;
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
				case 'multiselect':
					// Checkbox group: whitelist every submitted value against
					// the configured options and store a comma-separated list
					// of canonical keys. Accepts either the editor's array
					// submission or a comma-separated string; anything unknown
					// is dropped. An empty selection deletes the meta entirely.
					if ( is_array( $value ) ) {
						$values = $value;
					} else {
						$values = array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' );
					}
					$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$allowed = array_map( 'strval', array_keys( $options ) );
					$clean   = array();
					foreach ( $values as $single ) {
						$single = trim( (string) $single );
						if ( '' !== $single && in_array( $single, $allowed, true ) ) {
							$clean[] = $single;
						}
					}
					$value = implode( ',', array_unique( $clean ) );
					break;
				case 'textarea':
case 'radio':
					// Radio group: whitelist the submitted value against the
					// configured option keys ('' is a valid key, representing
					// "Evento único"). Any unrecognised value is dropped.
					$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$allowed = array_map( 'strval', array_keys( $options ) );
					$value   = in_array( (string) $value, $allowed, true ) ? (string) $value : '';
					break;
				case 'weekdays':
					// Weekday multi-checkbox: normalizes to a CSV of ascending
					// ISO weekday numbers (1=Mon … 7=Sun), rejecting
					// non-numeric, out-of-range, or duplicate values.
					// An empty selection deletes the meta entirely.
					$value = implode( ',', self::sanitize_weekdays( $value ) );
					break;
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

		// Sponsor image migration semantics: once the redesigned editor
		// submits the "Imagem do Apoiador" field, an explicitly emptied
		// field must also clear the LEGACY image relationships
		// (_sponsor_desktop_image / _sponsor_mobile_image / _sponsor_logo).
		// Without this, a removed attachment would keep resurrecting through
		// the front-end fallback chain even after the administrator removed
		// it. Attachments themselves are never deleted.
		if ( 'sponsor' === get_post_type( $post_id ) && array_key_exists( 'sponsor_image', $meta ) ) {
			$submitted_image = isset( $meta['sponsor_image'] ) ? self::normalize_media_value( $meta['sponsor_image'] ) : '';
			if ( '' === $submitted_image ) {
				delete_post_meta( $post_id, '_sponsor_logo' );
				delete_post_meta( $post_id, '_sponsor_desktop_image' );
				delete_post_meta( $post_id, '_sponsor_mobile_image' );
			}
		}

// Event recurrence: persists the weekly rule atomically (all four
		// meta keys together, validate-then-write), clears the whole group
		// when the event is one-time, and never writes invalid rules —
		// mirroring the validation messages shown by the editor.
		if ( 'event' === get_post_type( $post_id ) ) {
			self::save_event_recurrence( $post_id, $meta );
		}

		// Save taxonomies.
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
			$key = $field['key'];
			$id  = ltrim( $key, '_' );

			// Structured contact repeater: validate each submitted row
			// against its selected type. Invalid rows produce specific,
			// human-readable errors instead of being silently dropped.
			if ( 'contacts' === $field['type'] && class_exists( 'Conexao_Data_Model_Contacts' ) ) {
				$rows    = ( isset( $meta[ $id ] ) && is_array( $meta[ $id ] ) ) ? $meta[ $id ] : array();
				$errors  = array_merge( $errors, Conexao_Data_Model_Contacts::validate_submission( $rows ) );
				continue;
			}

			if ( empty( $field['required'] ) ) {
				continue;
			}

			// For virtual title fields, check conexao_fields first (the
			// sectioned editor input), then fall back to post_title (the
			// core title input).
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
		$virtual = array( '_event_title', '_event_description', '_news_title', '_news_content', '_guide_title', '_guide_content', '_job_title', '_job_description', '_sponsor_name', '_sponsor_description', '_provider_name', '_provider_description', '_leisure_name', '_leisure_description', '_agency_name', '_employer_name' );
		return in_array( $key, $virtual, true );
	}

	/**
	 * Render one contact repeater row.
	 *
	 * Used both for stored rows and for the JS template (with the literal
	 * "__INDEX__" placeholder). Inputs deliberately avoid id attributes so
	 * template clones can never collide with existing rows.
	 *
	 * @param int|string $index Row index (or "__INDEX__" placeholder).
	 * @param string     $type  Selected contact type.
	 * @param string     $url   Contact URL / e-mail display value.
	 * @param array      $types Available types (value => label).
	 * @return string HTML.
	 */
	private static function contact_row( $index, $type, $url, $types ) {
		$name_base = 'conexao_fields[sponsor_contacts][' . $index . ']';

		$row  = '<div class="conexao-contact-row">';
		$row .= '<select class="conexao-contact-type" name="' . esc_attr( $name_base . '[type]' ) . '" aria-label="' . esc_attr__( 'Tipo de contato', 'conexao-admin-ux' ) . '">';
		$row .= '<option value="">' . esc_html__( 'Tipo…', 'conexao-admin-ux' ) . '</option>';
		foreach ( $types as $type_value => $type_label ) {
			$row .= '<option value="' . esc_attr( $type_value ) . '" ' . selected( $type, $type_value, false ) . '>' . esc_html( $type_label ) . '</option>';
		}
		$row .= '</select>';

		// Text inputs (not type=url/email) on purpose: native constraint
		// validation would block saving drafts mid-edit with browser popups
		// that cannot explain our per-type rules. Server-side validation in
		// Conexao_Data_Model_Contacts produces clear pt-BR messages instead.
		$input_type  = 'text';
		$input_mode  = 'email' === $type ? 'email' : 'url';
		$placeholder  = 'email' === $type
			? __( 'nome@exemplo.com', 'conexao-admin-ux' )
			: ( 'whatsapp' === $type
				? __( 'https://wa.me/353… ou número com DDI', 'conexao-admin-ux' )
				: __( 'https://', 'conexao-admin-ux' ) );

		$row .= '<input type="' . esc_attr( $input_type ) . '" inputmode="' . esc_attr( $input_mode ) . '" class="conexao-contact-url" name="' . esc_attr( $name_base . '[url]' ) . '" value="' . esc_attr( $url ) . '" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr__( 'Link do contato', 'conexao-admin-ux' ) . '" autocomplete="off" />';

		$row .= '<span class="conexao-contact-actions">';
		$row .= '<button type="button" class="conexao-contact-action conexao-contact-up" aria-label="' . esc_attr__( 'Mover para cima', 'conexao-admin-ux' ) . '"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
		$row .= '<button type="button" class="conexao-contact-action conexao-contact-down" aria-label="' . esc_attr__( 'Mover para baixo', 'conexao-admin-ux' ) . '"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>';
		$row .= '<button type="button" class="conexao-contact-action conexao-contact-remove" aria-label="' . esc_attr__( 'Remover contato', 'conexao-admin-ux' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
		$row .= '</span>';

		$row .= '</div>';

		return $row;
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
			case '_agency_name':
			case '_employer_name':
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

		// Sponsor canonical image ("Imagem do Apoiador"): fall back to the
		// pre-consolidation image metas so existing Apoiadores keep showing
		// their current artwork prefilled in the editor — resolution order
		// mirrors the front-end fallback chain:
		//
		//   _sponsor_image → _sponsor_mobile_image (portrait) →
		//   _sponsor_desktop_image → legacy _sponsor_logo
		//
		// The first save through this editor persists the resolved value into
		// _sponsor_image, after which the new field is authoritative on its own.
		if ( '_sponsor_image' === $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( empty( $value ) ) {
				foreach ( array( '_sponsor_mobile_image', '_sponsor_desktop_image', '_sponsor_logo' ) as $legacy_key ) {
					$legacy_value = get_post_meta( $post->ID, $legacy_key, true );
					if ( ! empty( $legacy_value ) ) {
						return $legacy_value;
					}
				}
			}
			return $value;
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
/**
	 * Whether the key is part of the event recurrence meta group.
	 *
	 * The group is persisted atomically by save_event_recurrence() — never
	 * through the generic per-field loop — so a partially-edited recurring
	 * event can never persist an inconsistent rule.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_event_recurrence_key( $key ) {
		return in_array(
			$key,
			array(
				'_event_recurrence',
				'_event_recurrence_days',
				'_event_recurrence_start',
				'_event_recurrence_end',
			),
			true
		);
	}

	/**
	 * Normalize a weekday multi-checkbox submission into a deterministic
	 * ascending list of ISO weekday numbers (1 = Monday … 7 = Sunday).
	 *
	 * Rejects non-numeric tokens, values outside 1–7, and duplicate
	 * weekdays. Accepts the editor's array submission or a pre-parsed
	 * comma-separated string (e.g. from get_post_meta()).
	 *
	 * @param mixed $value Submitted value (array or CSV string).
	 * @return int[] Sorted, deduplicated weekday numbers.
	 */
	public static function sanitize_weekdays( $value ) {
		if ( is_array( $value ) ) {
			$tokens = $value;
		} else {
			$tokens = array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' );
		}

		$days = array();
		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( ! preg_match( '/^([1-7])$/', $token, $m ) ) {
				continue;
			}
			$days[ (int) $m[1] ] = true;
		}

		$days = array_keys( $days );
		sort( $days, SORT_NUMERIC );
		return $days;
	}
/**
	 * Validate the submitted event recurrence fields.
	 *
	 * Returns both blocking errors (rendered in the "Não foi possível
	 * publicar." notice and forced to draft on a publish attempt) and
	 * non-blocking warnings (rendered as an amber notice after save).
	 *
	 * One-time events have nothing to validate. Weekly events require at
	 * least one selected weekday and a recurrence end that is not before
	 * the recurrence start. When an explicit recurrence start falls on a
	 * non-selected weekday the save is still allowed, but a warning is
	 * raised so the editor can double-check the intent.
	 *
	 * @param array $data Unslashed POST data (typically $_POST).
	 * @return array{errors:string[],warnings:string[]}
	 */
	public static function validate_recurrence( $data ) {
		$errors   = array();
		$warnings = array();

		$meta = isset( $data['conexao_fields'] ) && is_array( $data['conexao_fields'] )
			? $data['conexao_fields']
			: array();

		if ( ! isset( $meta['event_recurrence'] ) ) {
			return array( 'errors' => $errors, 'warnings' => $warnings );
		}

		// Evento único: nothing to validate; the save handler clears any
		// previously stored recurrence metadata.
		if ( 'weekly' !== trim( (string) $meta['event_recurrence'] ) ) {
			return array( 'errors' => $errors, 'warnings' => $warnings );
		}

		$days  = self::sanitize_weekdays(
			isset( $meta['event_recurrence_days'] ) ? $meta['event_recurrence_days'] : array()
		);

		if ( empty( $days ) ) {
			$errors[] = 'Para eventos recorrentes, selecione pelo menos um dia da semana.';
		}

		$start = self::sanitize_date(
			isset( $meta['event_recurrence_start'] ) ? $meta['event_recurrence_start'] : ''
		);
		$end   = self::sanitize_date(
			isset( $meta['event_recurrence_end'] ) ? $meta['event_recurrence_end'] : ''
		);

		// Y-m-d strings compare chronologically; blank values are allowed.
		if ( '' !== $start && '' !== $end && $end < $start ) {
			$errors[] = 'A data final da repetição deve ser igual ou posterior à data inicial.';
		}

		// Non-blocking warning: a start date that does not fall on a
		// selected weekday.
		if ( '' !== $start && ! empty( $days ) ) {
			$start_weekday = self::date_weekday_iso( $start );
			if ( null !== $start_weekday && ! in_array( $start_weekday, $days, true ) ) {
				$warnings[] = 'A data inicial da repetição não cai em um dos dias da semana selecionados. A primeira ocorrência começa no próximo dia selecionado após essa data.';
			}
		}

		return array( 'errors' => $errors, 'warnings' => $warnings );
	}
/**
	 * Persist the event recurrence group atomically.
	 *
	 * Weekly rules are written only when they are valid (≥ 1 weekday and a
	 * valid start/end window); invalid submissions are refused entirely so
	 * the previously stored rule survives an unsuccessful edit and a
	 * brand-new event never gains broken recurrence metadata. Selecting
	 * "Evento único" (or an unknown recurrence value) deletes the whole
	 * group, preventing stale rules from surviving a switch.

	 * @param int   $post_id Event post ID.
	 * @param array $meta    Unslashed conexao_fields POST data.
	 */
	public static function save_event_recurrence( $post_id, array $meta ) {
		$raw_type = isset( $meta['event_recurrence'] ) ? trim( (string) $meta['event_recurrence'] ) : '';
		$type     = ( 'weekly' === $raw_type ) ? 'weekly' : '';

		if ( 'weekly' !== $type ) {
			foreach ( array(
				'_event_recurrence',
				'_event_recurrence_days',
				'_event_recurrence_start',
				'_event_recurrence_end',
			) as $key ) {
				delete_post_meta( $post_id, $key );
			}
			return;
		}

		$days = self::sanitize_weekdays(
			isset( $meta['event_recurrence_days'] ) ? $meta['event_recurrence_days'] : array()
		);

		if ( empty( $days ) ) {
			return;
		}

		$start = self::sanitize_date(
			isset( $meta['event_recurrence_start'] ) ? $meta['event_recurrence_start'] : ''
		);
		$end   = self::sanitize_date(
			isset( $meta['event_recurrence_end'] ) ? $meta['event_recurrence_end'] : ''
		);

		if ( '' !== $start && '' !== $end && $end < $start ) {
			return;
		}

		update_post_meta( $post_id, '_event_recurrence', 'weekly' );
		update_post_meta( $post_id, '_event_recurrence_days', implode( ',', $days ) );

		if ( '' === $start ) {
			delete_post_meta( $post_id, '_event_recurrence_start' );
		} else {
			update_post_meta( $post_id, '_event_recurrence_start', $start );
		}
		if ( '' === $end ) {
			delete_post_meta( $post_id, '_event_recurrence_end' );
		} else {
			update_post_meta( $post_id, '_event_recurrence_end', $end );
		}
	}

	/**
	 * ISO weekday number (1 = Monday … 7 = Sunday) for a validated Y-m-d
	 * date, or null when the value is not a real calendar date.
	 *
	 * Uses DateTimeImmutable + the site timezone (matching the event runtime
	 * recurrence evaluator conventions) — no strtotime()/date() in the
	 * recurrence validation path.
	 *
	 * @param string $date Validated Y-m-d date string.
	 * @return int|null
	 */
	private static function date_weekday_iso( $date ) {
		$datetime = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		if ( false === $datetime ) {
			return null;
		}
		return (int) $datetime->format( 'N' );
	}
}
 