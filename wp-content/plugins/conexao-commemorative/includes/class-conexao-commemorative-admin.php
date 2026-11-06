<?php
/**
 * Engine-side wp-admin surface: diagnostics, annual precedence and operations.
 *
 * The admin UX plugin owns the list/editor chrome. This class adds ONLY what
 * belongs to the engine, and it never renders an event experience:
 *
 *   - a diagnostics meta box (next window, eligibility, reason, armed boundary,
 *     last purge, preview link, read-only date simulation);
 *   - the r10 manual annual-precedence control (`_ce_year_precedence`), with its
 *     own nonce and record-level capability check;
 *   - the "Pré-visualizar" row action (signed, capability-gated link);
 *   - the "Purgar caches comemorativos" admin action;
 *   - a non-blocking warning when more than one event is marked preferred for
 *     the same occurrence year (flag counting only — no collision detection);
 *   - the computed list-column values, exposed to the admin UX through a render
 *     filter so the admin never computes scheduling itself.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin diagnostics and operations.
 */
final class Conexao_Commemorative_Admin {

	/**
	 * Nonce action for the annual-precedence control.
	 */
	const PRECEDENCE_NONCE = 'conexao_commemorative_precedence';

	/**
	 * Nonce action for the read-only date simulation.
	 */
	const SIMULATION_NONCE = 'conexao_commemorative_simulate';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 20 );
		add_action( 'save_post_' . Conexao_Data_Model_Commemorative::POST_TYPE, array( __CLASS__, 'save_precedence' ), 20 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_filter( 'conexao_admin_ux_render_column', array( __CLASS__, 'render_column' ), 10, 5 );
	}

	/**
	 * Register the diagnostics / precedence meta box.
	 *
	 * Added at `add_meta_boxes` priority 20 so it survives the admin UX editor's
	 * own meta-box cleanup.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'conexao_commemorative_diagnostics',
			__( 'Comemorativo — diagnóstico e preferência anual', 'conexao-commemorative' ),
			array( __CLASS__, 'render_meta_box' ),
			Conexao_Data_Model_Commemorative::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the diagnostics meta box.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$overview = conexao_commemorative_admin_overview( $post->ID );
		$event    = $overview ? $overview['event'] : null;
		$boundary = Conexao_Commemorative_Cache::armed_boundary();
		$last     = Conexao_Commemorative_Cache::last_result();

		wp_nonce_field( self::PRECEDENCE_NONCE, 'conexao_commemorative_precedence_nonce' );

		echo '<div class="conexao-commemorative-diagnostics">';

		self::render_warnings();

		echo '<h3>' . esc_html__( 'Janela calculada (próxima ocorrência)', 'conexao-commemorative' ) . '</h3>';
		echo '<p>';
		if ( $overview && '' !== $overview['window_label'] ) {
			printf(
				/* translators: 1: window range, 2: occurrence year. */
				esc_html__( '%1$s — ano de ocorrência %2$s', 'conexao-commemorative' ),
				esc_html( $overview['window_label'] ),
				esc_html( '' !== $overview['next_year_label'] ? $overview['next_year_label'] : '—' )
			);
		} else {
			esc_html_e( 'Sem janela resolvível com a configuração atual.', 'conexao-commemorative' );
		}
		echo '</p>';

		echo '<p>';
		printf(
			/* translators: %s: reason label. */
			esc_html__( 'Estado: %s', 'conexao-commemorative' ),
			esc_html( $overview ? conexao_commemorative_reason_label( $overview['evaluation'], $overview['next_window'] ) : '—' )
		);
		echo '</p>';

		self::render_boundary_section( $boundary, $last );
		self::render_preview_section( $post );
		self::render_precedence_control( $post, $event );
		self::render_simulation( $post, $event );

		echo '</div>';
	}

	/**
	 * Cache-boundary diagnostics (armed boundary + last purge + guarantee).
	 *
	 * @param array|null $boundary Armed boundary.
	 * @param array|null $last     Last purge result.
	 * @return void
	 */
	private static function render_boundary_section( $boundary, $last ) {
		echo '<h3>' . esc_html__( 'Cache e limite de ativação', 'conexao-commemorative' ) . '</h3>';
		echo '<p>';

		if ( is_array( $boundary ) && ! empty( $boundary['datetime'] ) ) {
			printf(
				/* translators: 1: datetime, 2: event key, 3: boundary type. */
				esc_html__( 'Próximo limite agendado: %1$s (%2$s, %3$s)', 'conexao-commemorative' ),
				esc_html( $boundary['datetime'] ),
				esc_html( '' !== $boundary['event_key'] ? $boundary['event_key'] : '—' ),
				esc_html( isset( $boundary['type'] ) ? $boundary['type'] : '—' )
			);
		} else {
			esc_html_e( 'Nenhum limite agendado (nenhum evento publicado e ativo).', 'conexao-commemorative' );
		}

		echo '</p><p>';

		if ( is_array( $last ) && ! empty( $last['time'] ) ) {
			printf(
				/* translators: 1: reason, 2: datetime, 3: number of transients deleted. */
				esc_html__( 'Última purga: %1$s em %2$s (%3$d transients removidos).', 'conexao-commemorative' ),
				esc_html( isset( $last['reason'] ) ? $last['reason'] : '—' ),
				esc_html( gmdate( 'Y-m-d H:i:s', (int) $last['time'] ) ),
				(int) ( isset( $last['transients'] ) ? $last['transients'] : 0 )
			);
		} else {
			esc_html_e( 'Nenhuma purga registrada nesta instalação.', 'conexao-commemorative' );
		}

		echo '</p><p class="description">';
		esc_html_e( 'Garantia registrada: Option B — o limite vale no primeiro pedido PHP após a data (ou dentro do TTL verificado). A verificação do cache de produção é uma atividade separada e não é afirmada aqui.', 'conexao-commemorative' );
		echo '</p>';
	}

	/**
	 * The signed preview link (state B).
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	private static function render_preview_section( $post ) {
		$link = Conexao_Commemorative_Preview::link( $post->ID );

		echo '<h3>' . esc_html__( 'Pré-visualização privada', 'conexao-commemorative' ) . '</h3>';

		if ( '' === $link ) {
			echo '<p class="description">' . esc_html__( 'Defina uma chave de evento (_ce_event_key) para gerar o link de pré-visualização.', 'conexao-commemorative' ) . '</p>';

			return;
		}

		echo '<p><a class="button" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Pré-visualizar no site ↗', 'conexao-commemorative' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'O link é assinado, funciona apenas para administradores que possam editar este registro, não altera o agendamento e não ativa o evento para o público.', 'conexao-commemorative' ) . '</p>';
	}

	/**
	 * The r10 manual annual-precedence control.
	 *
	 * One occurrence-year selector, one checkbox, one optional note. It never
	 * changes `_ce_enabled`, the schedule or the global priority (§5.7).
	 *
	 * @param WP_Post    $post  Post object.
	 * @param array|null $event Normalised event.
	 * @return void
	 */
	private static function render_precedence_control( $post, $event ) {
		$map   = is_array( $event ) && isset( $event['year_precedence'] ) ? $event['year_precedence'] : array();
		$years = Conexao_Data_Model_Commemorative::allowed_years();

		$selected = isset( $_GET['ce_precedence_year'] ) ? sanitize_text_field( wp_unslash( $_GET['ce_precedence_year'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector default.
		if ( ! in_array( (int) $selected, $years, true ) ) {
			$selected = (string) $years[2];
		}

		$current = isset( $map[ $selected ] ) ? $map[ $selected ] : array(
			'preferred' => false,
			'note'      => '',
		);

		echo '<h3>' . esc_html__( 'Preferência anual manual', 'conexao-commemorative' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Marque este evento para prevalecer sobre outro evento comemorativo, apenas no ano de ocorrência selecionado. A preferência nunca altera o agendamento, a ativação ou a prioridade global.', 'conexao-commemorative' ) . '</p>';

		echo '<p><label for="ce_precedence_year"><strong>' . esc_html__( 'Ano de ocorrência', 'conexao-commemorative' ) . '</strong></label><br />';
		echo '<select id="ce_precedence_year" name="ce_precedence_year">';
		foreach ( $years as $year ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( (string) $year ),
				selected( $selected, (string) $year, false )
			);
		}
		echo '</select></p>';

		printf(
			'<p><label><input type="checkbox" name="ce_precedence_preferred" value="1" %1$s /> %2$s</label></p>',
			checked( ! empty( $current['preferred'] ), true, false ),
			esc_html(
				sprintf(
					/* translators: %s: occurrence year. */
					__( 'Preferir este evento se colidir com outro evento comemorativo em %s', 'conexao-commemorative' ),
					$selected
				)
			)
		);

		echo '<p><label for="ce_precedence_note"><strong>' . esc_html__( 'Nota (opcional, máx. 200 caracteres)', 'conexao-commemorative' ) . '</strong></label><br />';
		echo '<input type="text" id="ce_precedence_note" name="ce_precedence_note" class="regular-text" maxlength="200" value="' . esc_attr( isset( $current['note'] ) ? $current['note'] : '' ) . '" /></p>';

		if ( ! empty( $map ) ) {
			echo '<p class="description">' . esc_html__( 'Preferências registradas:', 'conexao-commemorative' ) . '</p><ul>';
			foreach ( $map as $year => $entry ) {
				printf(
					'<li><code>%1$s</code> — %2$s%3$s</li>',
					esc_html( (string) $year ),
					! empty( $entry['preferred'] ) ? esc_html__( 'preferido', 'conexao-commemorative' ) : esc_html__( 'não preferido', 'conexao-commemorative' ),
					! empty( $entry['note'] ) ? esc_html( ' — ' . $entry['note'] ) : ''
				);
			}
			echo '</ul>';
		}
	}

	/**
	 * Read-only date simulation (scheduling inspector, §5.6).
	 *
	 * Evaluates "would this event be eligible at date X?" and shows the computed
	 * window and reason. It never renders the experience, never enqueues an asset
	 * and never touches the stored schedule.
	 *
	 * @param WP_Post    $post  Post object.
	 * @param array|null $event Normalised event.
	 * @return void
	 */
	private static function render_simulation( $post, $event ) {
		echo '<h3>' . esc_html__( 'Simulação de data (somente leitura)', 'conexao-commemorative' ) . '</h3>';

		if ( ! is_array( $event ) ) {
			return;
		}

		echo '<form method="get" action="">';
		printf( '<input type="hidden" name="post" value="%d" />', (int) $post->ID );
		echo '<input type="hidden" name="action" value="edit" />';
		wp_nonce_field( self::SIMULATION_NONCE, 'ce_simulate_nonce', false );
		echo '<p><label for="ce_simulate_date"><strong>' . esc_html__( 'Data a testar', 'conexao-commemorative' ) . '</strong></label> ';
		echo '<input type="date" id="ce_simulate_date" name="ce_simulate_date" value="' . esc_attr( isset( $_GET['ce_simulate_date'] ) ? sanitize_text_field( wp_unslash( $_GET['ce_simulate_date'] ) ) : '' ) . '" /> '; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- value echoed back; nonce verified below.
		echo '<button type="submit" class="button">' . esc_html__( 'Testar data', 'conexao-commemorative' ) . '</button></p>';
		echo '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		$submitted = isset( $_GET['ce_simulate_date'] ) ? sanitize_text_field( wp_unslash( $_GET['ce_simulate_date'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified here.
		$nonce = isset( $_GET['ce_simulate_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['ce_simulate_nonce'] ) ) : '';

		if ( '' === $submitted || ! wp_verify_nonce( $nonce, self::SIMULATION_NONCE ) ) {
			return;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $submitted, Conexao_Commemorative_Schedule::timezone() );

		if ( ! $date ) {
			echo '<p>' . esc_html__( 'Data inválida.', 'conexao-commemorative' ) . '</p>';

			return;
		}

		$evaluation = Conexao_Commemorative_Resolver::evaluate( $event, $date );
		$window     = Conexao_Commemorative_Schedule::resolve( $event, $date );

		echo '<p><strong>' . esc_html__( 'Resultado', 'conexao-commemorative' ) . ':</strong> ';
		echo esc_html( $evaluation['eligible'] ? __( 'Elegível nessa data', 'conexao-commemorative' ) : __( 'Não elegível nessa data', 'conexao-commemorative' ) );
		echo ' — ' . esc_html( conexao_commemorative_reason_label( $evaluation, $evaluation['next_window'] ) ) . '</p>';

		if ( $window ) {
			printf(
				/* translators: 1: start, 2: end, 3: occurrence year. */
				'<p>' . esc_html__( 'Janela nessa data: %1$s → %2$s (ano de ocorrência %3$d)', 'conexao-commemorative' ) . '</p>',
				esc_html( $window['start']->format( 'Y-m-d H:i:s' ) ),
				esc_html( $window['end']->format( 'Y-m-d H:i:s' ) ),
				(int) $window['occurrence_year']
			);
		}

		echo '<p class="description">' . esc_html__( 'A simulação não altera o agendamento nem publica qualquer conteúdo.', 'conexao-commemorative' ) . '</p>';
	}

	/**
	 * Render and clear the pending validation warnings for this record.
	 *
	 * @return void
	 */
	private static function render_warnings() {
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
		$key      = 'conexao_commemorative_warnings_' . $post_id;
		$warnings = $post_id ? get_option( $key, array() ) : array();

		if ( ! is_array( $warnings ) || empty( $warnings ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><ul>';
		foreach ( $warnings as $warning ) {
			echo '<li>' . esc_html( (string) $warning ) . '</li>';
		}
		echo '</ul></div>';

		delete_option( $key );
	}

	/**
	 * Persist the manual annual preference (r10 §5.7).
	 *
	 * Capability + nonce are both required. Selecting `preferred` writes
	 * `_ce_year_precedence[year].preferred = true`; clearing it removes ONLY that
	 * year's entry. It never changes `_ce_enabled`, the schedule or the priority.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_precedence( $post_id ) {
		if ( ! isset( $_POST['conexao_commemorative_precedence_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['conexao_commemorative_precedence_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::PRECEDENCE_NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		self::collect_validation_warnings( $post_id );

		$year      = isset( $_POST['ce_precedence_year'] ) ? sanitize_text_field( wp_unslash( $_POST['ce_precedence_year'] ) ) : '';
		$preferred = ! empty( $_POST['ce_precedence_preferred'] );
		$note      = isset( $_POST['ce_precedence_note'] ) ? sanitize_text_field( wp_unslash( $_POST['ce_precedence_note'] ) ) : '';

		if ( '' === $year ) {
			return;
		}

		if ( ! Conexao_Data_Model_Commemorative::is_valid_occurrence_year( $year ) ) {
			self::add_warning(
				$post_id,
				sprintf(
					/* translators: 1: submitted year, 2: minimum year, 3: maximum year. */
					__( 'O ano de ocorrência %1$s está fora do intervalo aceito (%2$d–%3$d) e não foi gravado.', 'conexao-commemorative' ),
					$year,
					(int) min( Conexao_Data_Model_Commemorative::allowed_years() ),
					(int) max( Conexao_Data_Model_Commemorative::allowed_years() )
				)
			);

			return;
		}

		$map = Conexao_Data_Model_Commemorative::decode_year_precedence( get_post_meta( $post_id, '_ce_year_precedence', true ) );

		if ( $preferred ) {
			$map[ (string) (int) $year ] = array(
				'preferred' => true,
				'note'      => $note,
			);
		} else {
			// Clearing removes ONLY this year's entry.
			unset( $map[ (string) (int) $year ] );
		}

		update_post_meta( $post_id, '_ce_year_precedence', $map );
	}

	/**
	 * Record a clear admin message when a submitted date was rejected at save.
	 *
	 * §4.5: `02-29` and malformed dates are rejected rather than silently
	 * coerced, and the administrator is told.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function collect_validation_warnings( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_precedence() verified the nonce before calling this helper.
		if ( ! isset( $_POST['conexao_fields'] ) || ! is_array( $_POST['conexao_fields'] ) ) {
			return;
		}

		$submitted = wp_unslash( $_POST['conexao_fields'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by save_precedence(); each value is sanitised by the data-model sanitizer below.

		$month_day_fields = array(
			'ce_date'         => '_ce_date',
			'ce_window_start' => '_ce_window_start',
			'ce_window_end'   => '_ce_window_end',
		);

		foreach ( $month_day_fields as $input => $meta_key ) {
			$raw = isset( $submitted[ $input ] ) && is_scalar( $submitted[ $input ] ) ? trim( (string) $submitted[ $input ] ) : '';

			if ( '' === $raw ) {
				continue;
			}

			if ( '' === Conexao_Data_Model_Commemorative::sanitize_month_day( $raw ) ) {
				self::add_warning(
					$post_id,
					sprintf(
						/* translators: 1: submitted value, 2: field name. */
						__( 'Data rejeitada: "%1$s" (%2$s). Use MM-DD; 29 de fevereiro não é aceito porque só existe em anos bissextos.', 'conexao-commemorative' ),
						$raw,
						$meta_key
					)
				);
			}
		}

		$raw_one_off = isset( $submitted['ce_one_off_date'] ) && is_scalar( $submitted['ce_one_off_date'] ) ? trim( (string) $submitted['ce_one_off_date'] ) : '';

		if ( '' !== $raw_one_off && '' === Conexao_Data_Model_Commemorative::sanitize_iso_date( $raw_one_off ) ) {
			self::add_warning(
				$post_id,
				sprintf(
					/* translators: %s: submitted value. */
					__( 'Data única rejeitada: "%s". Use AAAA-MM-DD.', 'conexao-commemorative' ),
					$raw_one_off
				)
			);
		}
	}

	/**
	 * Append one warning for the next editor load.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Message.
	 * @return void
	 */
	private static function add_warning( $post_id, $message ) {
		$key      = 'conexao_commemorative_warnings_' . (int) $post_id;
		$warnings = get_option( $key, array() );
		$warnings = is_array( $warnings ) ? $warnings : array();

		$warnings[] = (string) $message;

		update_option( $key, array_values( array_unique( $warnings ) ), false );
	}

	/**
	 * Add the signed "Pré-visualizar" row action.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$link = Conexao_Commemorative_Preview::link( $post->ID );

		if ( '' !== $link ) {
			$actions['conexao_commemorative_preview'] = '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Pré-visualizar', 'conexao-commemorative' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Admin notices on the commemorative event list screen.
	 *
	 * - the "Purgar caches comemorativos" action (production has no CLI);
	 * - the armed boundary and the last purge result;
	 * - a non-blocking warning when more than one event is marked preferred for
	 *   the same occurrence year (flag counting only);
	 * - the purge success notice.
	 *
	 * @return void
	 */
	public static function render_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-' . Conexao_Data_Model_Commemorative::POST_TYPE !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['conexao_commemorative_purged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Caches comemorativos purgados e próximo limite reagendado.', 'conexao-commemorative' ) . '</p></div>';
		}

		$boundary = Conexao_Commemorative_Cache::armed_boundary();
		$last     = Conexao_Commemorative_Cache::last_result();

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Eventos comemorativos', 'conexao-commemorative' ) . '</strong> — ';
		if ( is_array( $boundary ) && ! empty( $boundary['datetime'] ) ) {
			printf(
				/* translators: %s: datetime. */
				esc_html__( 'próximo limite agendado: %s.', 'conexao-commemorative' ),
				esc_html( $boundary['datetime'] )
			);
		} else {
			esc_html_e( 'nenhum limite agendado.', 'conexao-commemorative' );
		}

		if ( is_array( $last ) && ! empty( $last['time'] ) ) {
			echo ' ';
			printf(
				/* translators: 1: reason, 2: datetime. */
				esc_html__( 'Última purga: %1$s (%2$s).', 'conexao-commemorative' ),
				esc_html( isset( $last['reason'] ) ? $last['reason'] : '—' ),
				esc_html( gmdate( 'Y-m-d H:i:s', (int) $last['time'] ) )
			);
		}

		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="conexao_commemorative_purge" />';
		wp_nonce_field( 'conexao_commemorative_purge' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Purgar caches comemorativos', 'conexao-commemorative' ) . '</button></p>';
		echo '</form>';

		$conflicts = self::preferred_flag_conflicts();

		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Revisão recomendada', 'conexao-commemorative' ) . '</strong></p><ul>';
			foreach ( $conflicts as $year => $keys ) {
				printf(
					'<li>%1$s</li>',
					esc_html(
						sprintf(
							/* translators: 1: occurrence year, 2: comma-separated event keys. */
							__( 'Mais de um evento está marcado como preferido em %1$s: %2$s. A prioridade global e a chave do evento decidem a ordem.', 'conexao-commemorative' ),
							(string) $year,
							implode( ', ', $keys )
						)
					)
				);
			}
			echo '</ul></div>';
		}

		echo '</div>';
	}

	/**
	 * Events marked preferred for the same occurrence year (flag counting only).
	 *
	 * @return array<string,string[]>
	 */
	public static function preferred_flag_conflicts() {
		$by_year = array();

		foreach ( Conexao_Commemorative_Repository::all( array( 'post_status' => 'publish' ) ) as $event ) {
			$canonical = isset( $event['canonical_id'] ) ? (int) $event['canonical_id'] : (int) $event['id'];

			if ( ! Conexao_Commemorative_Repository::is_canonical_language( $canonical ) ) {
				continue;
			}

			if ( '' === $event['event_key'] ) {
				continue;
			}

			foreach ( $event['year_precedence'] as $year => $entry ) {
				if ( empty( $entry['preferred'] ) ) {
					continue;
				}

				$by_year[ (string) $year ][] = (string) $event['event_key'];
			}
		}

		$conflicts = array();

		foreach ( $by_year as $year => $keys ) {
			$keys = array_values( array_unique( $keys ) );

			if ( count( $keys ) > 1 ) {
				sort( $keys );
				$conflicts[ $year ] = $keys;
			}
		}

		return $conflicts;
	}

	/**
	 * Render a computed list column for the admin UX list table.
	 *
	 * The admin UX asks the ENGINE for these values; it never computes
	 * scheduling itself (§2.2).
	 *
	 * @param string|null $html      Pre-rendered HTML (null = not handled).
	 * @param string      $render    Render key from the config.
	 * @param int         $post_id   Post ID.
	 * @param string      $post_type Post type.
	 * @param array       $config    Column config.
	 * @return string|null
	 */
	public static function render_column( $html, $render, $post_id, $post_type, $config = array() ) {
		unset( $config );

		if ( Conexao_Data_Model_Commemorative::POST_TYPE !== $post_type ) {
			return $html;
		}

		$overview = conexao_commemorative_admin_overview( $post_id );

		if ( ! $overview ) {
			return $html;
		}

		switch ( $render ) {
			case 'commemorative_state':
				$published = 'publish' === $overview['status'];
				$parts     = array(
					$published
						? '<span class="conexao-status-badge conexao-status-badge--published">● ' . esc_html__( 'Publicado', 'conexao-commemorative' ) . '</span>'
						: '<span class="conexao-status-badge conexao-status-badge--draft">● ' . esc_html__( 'Rascunho', 'conexao-commemorative' ) . '</span>',
					$overview['enabled']
						? '<span class="conexao-status-badge conexao-status-badge--published">● ' . esc_html__( 'Ativo', 'conexao-commemorative' ) . '</span>'
						: '<span class="conexao-status-badge conexao-status-badge--review">● ' . esc_html__( 'Desativado', 'conexao-commemorative' ) . '</span>',
				);

				return implode( ' ', $parts );

			case 'commemorative_window':
				if ( '' === $overview['window_label'] ) {
					return '<span aria-hidden="true">—</span>';
				}

				return esc_html( $overview['window_label'] ) . '<br /><small>' . esc_html(
					sprintf(
						/* translators: %s: occurrence year. */
						__( 'ano de ocorrência %s', 'conexao-commemorative' ),
						$overview['next_year_label']
					)
				) . '</small>';

			case 'commemorative_eligible':
				return $overview['eligible']
					? '<strong>' . esc_html__( 'Sim', 'conexao-commemorative' ) . '</strong>'
					: esc_html__( 'Não', 'conexao-commemorative' );

			case 'commemorative_reason':
				return esc_html( conexao_commemorative_reason_label( $overview['evaluation'], $overview['next_window'] ) );

			case 'commemorative_priority':
				$out = '<strong>' . esc_html( (string) $overview['priority'] ) . '</strong>';

				if ( $overview['preferred'] ) {
					$out .= '<br /><span class="conexao-status-badge conexao-status-badge--review">★ ' . esc_html(
						sprintf(
							/* translators: %s: occurrence year. */
							__( 'preferido em %s', 'conexao-commemorative' ),
							$overview['next_year_label']
						)
					) . '</span>';
				}

				return $out;
		}

		return $html;
	}
}
