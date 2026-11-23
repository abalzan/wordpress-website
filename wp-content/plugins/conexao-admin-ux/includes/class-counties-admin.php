<?php
/**
 * County repair admin screen.
 *
 * Production is WordPress.com: there is no SSH, no WP-CLI and no database
 * access, so a county data repair must be operable from wp-admin. This screen
 * is the production counterpart of the county guards:
 *
 *   - It inventories the county taxonomy against the ONE master list
 *     (`Conexao_Data_Model_Counties`): approved counties, out-of-list terms
 *     (numeric leaks, `Co Clare Ireland`, leftover `ev-*` fixtures, …) and
 *     canonical slugs whose display name drifted.
 *   - For every out-of-list term it shows the records attached to it and
 *     offers a **preview** (dry run) of exactly what the repair will do.
 *   - Applying re-assigns each record to an approved county (or leaves it
 *     unassigned), then removes the out-of-list term only when nothing is
 *     left pointing at it. No post is ever deleted or otherwise modified.
 *   - The run is idempotent: repeating it on a clean taxonomy does nothing,
 *     and the last run is recorded for audit.
 *
 * The public filters never depend on this screen: they render the master list
 * regardless of what the taxonomy contains. The screen removes the *data* so
 * the taxonomy itself matches the vocabulary again.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Counties_Admin {

	const PAGE_SLUG   = 'conexao-counties';
	const PAGE_ACTION = 'conexao_counties_repair';
	const OPTION_LAST = 'conexao_counties_last_repair';
	const CAPABILITY  = 'manage_categories';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_post_' . self::PAGE_ACTION, array( $this, 'handle_repair' ) );
	}

	/**
	 * Register the page under Tools.
	 */
	public function register_admin_menu() {
		add_management_page(
			__( 'Counties', 'conexao-admin-ux' ),
			__( 'Counties', 'conexao-admin-ux' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the repair action (preview or apply).
	 *
	 * Preview is a no-write redirect to the page; apply writes only taxonomy
	 * relationships and the out-of-list term itself.
	 */
	public function handle_repair() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Você não tem permissão para reparar counties.', 'conexao-admin-ux' ) );
		}

		check_admin_referer( self::PAGE_ACTION, 'conexao_counties_nonce' );

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'preview';
		$target  = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';

		$target_slug = ( '' === $target ) ? '' : Conexao_Data_Model_Counties::normalize( $target );
		$page_url    = admin_url( 'tools.php?page=' . self::PAGE_SLUG );

		if ( $term_id <= 0 ) {
			wp_safe_redirect( add_query_arg( 'conexao_counties_notice', 'invalid_term', $page_url ) );
			exit;
		}

		if ( 'apply' !== $mode && 'normalize' !== $mode ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'preview_term'   => $term_id,
						'preview_target' => $target_slug,
					),
					$page_url
				)
			);
			exit;
		}

		if ( 'normalize' === $mode ) {
			$normalized = Conexao_Data_Model_Counties::canonicalize_term( $term_id );

			wp_safe_redirect(
				add_query_arg(
					'conexao_counties_notice',
					$normalized ? 'normalized' : 'normalize_failed',
					$page_url
				)
			);
			exit;
		}

		$result = self::repair_term( $term_id, $target_slug );

		update_option(
			self::OPTION_LAST,
			array(
				'time'       => current_time( 'mysql' ),
				'user'       => get_current_user_id(),
				'term_id'    => $term_id,
				'term'       => $result['term'],
				'target'     => $target_slug,
				'object_ids' => $result['object_ids'],
				'reassigned' => $result['reassigned'],
				'deleted'    => $result['deleted'],
				'remaining'  => $result['remaining'],
			),
			false
		);

		$notice = 'done';
		if ( ! empty( $result['deleted'] ) ) {
			$notice = 'deleted';
		} elseif ( $result['remaining'] > 0 ) {
			$notice = 'not_empty';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'conexao_counties_notice' => $notice,
					'repaired_term'           => $term_id,
				),
				$page_url
			)
		);
		exit;
	}

	/**
	 * The repair plan for one out-of-list term (used by preview and apply).
	 *
	 * @param int    $term_id     Out-of-list county term id.
	 * @param string $target_slug Approved county slug, or '' to leave unassigned.
	 * @return array{term:string,object_ids:int[],reassigned:int,deleted:bool,remaining:int,target_valid:bool}
	 */
	public static function plan( $term_id, $target_slug ) {
		$plan = array(
			'term'         => '',
			'object_ids'   => array(),
			'reassigned'   => 0,
			'deleted'      => false,
			'remaining'    => 0,
			'target_valid' => ( '' === $target_slug || Conexao_Data_Model_Counties::is_valid_slug( $target_slug ) ),
		);

		$term = get_term( (int) $term_id, 'conexao_county' );
		if ( ! $term instanceof WP_Term ) {
			return $plan;
		}

		$plan['term'] = $term->name . ' (' . $term->slug . ')';

		// A canonical term is never "repaired" here.
		if ( Conexao_Data_Model_Counties::is_valid_slug( $term->slug ) ) {
			return $plan;
		}

		$object_ids         = get_objects_in_term( $term->term_id, 'conexao_county' );
		$plan['object_ids'] = is_wp_error( $object_ids ) ? array() : array_map( 'intval', $object_ids );
		$plan['reassigned'] = count( $plan['object_ids'] );

		return $plan;
	}

	/**
	 * Apply the repair plan for one out-of-list term.
	 *
	 * Order matters: every record is re-assigned away from the invalid term
	 * FIRST, and the term is removed only when no relationship is left.
	 *
	 * @param int    $term_id     Out-of-list county term id.
	 * @param string $target_slug Approved county slug, or '' to leave unassigned.
	 * @return array Plan result after the write (see plan()).
	 */
	public static function repair_term( $term_id, $target_slug ) {
		$plan = self::plan( $term_id, $target_slug );

		if ( '' === $plan['term'] ) {
			return $plan;
		}

		$target_id = '' === $target_slug ? 0 : Conexao_Data_Model_Counties::ensure_term( $target_slug );

		foreach ( $plan['object_ids'] as $object_id ) {
			// Remove ONLY the invalid term from this record.
			wp_remove_object_terms( $object_id, array( (int) $term_id ), 'conexao_county' );

			if ( $target_id > 0 ) {
				wp_set_object_terms( $object_id, array( $target_id ), 'conexao_county', true );
			}
		}

		$remaining         = get_objects_in_term( (int) $term_id, 'conexao_county' );
		$plan['remaining'] = is_wp_error( $remaining ) ? 0 : count( $remaining );

		if ( 0 === $plan['remaining'] ) {
			$deleted         = wp_delete_term( (int) $term_id, 'conexao_county' );
			$plan['deleted'] = ! is_wp_error( $deleted ) && false !== $deleted;
		}

		return $plan;
	}

	/**
	 * Render the inventory, the repair forms and the last-run record.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$invalid  = Conexao_Data_Model_Counties::invalid_terms();
		$misnamed = Conexao_Data_Model_Counties::misnamed_terms();
		$last     = get_option( self::OPTION_LAST, array() );
		$preview  = $this->preview_state();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counties', 'conexao-admin-ux' ) . '</h1>';

		echo '<p>' . esc_html__( 'Fonte única: a lista fixa de 26 condados aprovados da República da Irlanda (Conexao_Data_Model_Counties). Os filtros públicos mostram apenas esta lista; este ecrã corrige os dados da taxonomia para que ela também corresponda.', 'conexao-admin-ux' ) . '</p>';

		$this->render_notice();

		echo '<h2>' . esc_html__( 'Condados aprovados', 'conexao-admin-ux' ) . '</h2>';
		$this->render_approved_table();

		echo '<h2>' . esc_html__( 'Termos fora da lista', 'conexao-admin-ux' ) . '</h2>';
		if ( empty( $invalid ) ) {
			echo '<p><strong>' . esc_html__( 'Nenhum. A taxonomia corresponde aos 26 condados aprovados.', 'conexao-admin-ux' ) . '</strong></p>';
		} else {
			echo '<p>' . esc_html__( 'Estes termos nunca aparecem nos filtros públicos. Reatribua os registos e remova o termo — nenhum registo é apagado.', 'conexao-admin-ux' ) . '</p>';
			$this->render_invalid_table( $invalid );
		}

		if ( ! empty( $misnamed ) ) {
			echo '<h2>' . esc_html__( 'Nomes divergentes', 'conexao-admin-ux' ) . '</h2>';
			echo '<p>' . esc_html__( 'O slug é aprovado mas o nome apresentado diverge da lista. Normalize o nome para o valor canónico.', 'conexao-admin-ux' ) . '</p>';
			$this->render_misnamed_table( $misnamed );
		}

		if ( ! empty( $preview['term_id'] ) ) {
			$this->render_preview( $preview );
		}

		$this->render_last_run( $last );

		echo '</div>';
	}

	/**
	 * Read the preview selection from the query string (read-only).
	 *
	 * @return array{term_id:int,target:string}
	 */
	private function preview_state() {
		$term_id = isset( $_GET['preview_term'] ) ? absint( $_GET['preview_term'] ) : 0;
		$target  = isset( $_GET['preview_target'] ) ? sanitize_text_field( wp_unslash( $_GET['preview_target'] ) ) : '';

		return array(
			'term_id' => $term_id,
			'target'  => '' === $target ? '' : Conexao_Data_Model_Counties::normalize( $target ),
		);
	}

	/**
	 * Render the operation notice.
	 */
	private function render_notice() {
		$notice = isset( $_GET['conexao_counties_notice'] ) ? sanitize_key( wp_unslash( $_GET['conexao_counties_notice'] ) ) : '';

		$messages = array(
			'deleted'          => array( 'success', __( 'Registos reatribuídos e termo fora da lista removido.', 'conexao-admin-ux' ) ),
			'not_empty'        => array( 'warning', __( 'Registos reatribuídos, mas o termo ainda tem referências e NÃO foi removido. Reveja a lista e repita.', 'conexao-admin-ux' ) ),
			'done'             => array( 'success', __( 'Operação concluída.', 'conexao-admin-ux' ) ),
			'normalized'       => array( 'success', __( 'Nome do condado normalizado para o valor canónico.', 'conexao-admin-ux' ) ),
			'normalize_failed' => array( 'error', __( 'Não foi possível normalizar o nome deste termo.', 'conexao-admin-ux' ) ),
			'invalid_term'     => array( 'error', __( 'Termo inválido.', 'conexao-admin-ux' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}
	}

	/**
	 * Approved counties: canonical list, term presence and usage count.
	 */
	private function render_approved_table() {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'County', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Termo', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Registos', 'conexao-admin-ux' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Conexao_Data_Model_Counties::all() as $slug => $name ) {
			$term_id = Conexao_Data_Model_Counties::term_id( $slug );
			$term    = $term_id > 0 ? get_term( $term_id, 'conexao_county' ) : null;

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
			echo '<td><code>' . esc_html( $slug ) . '</code></td>';
			echo '<td>' . ( $term instanceof WP_Term ? esc_html( '#' . $term->term_id ) : esc_html__( 'ausente', 'conexao-admin-ux' ) ) . '</td>';
			echo '<td>' . ( $term instanceof WP_Term ? (int) $term->count : 0 ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Out-of-list terms with their attached records and the repair form.
	 *
	 * @param WP_Term[] $terms Out-of-list terms.
	 */
	private function render_invalid_table( $terms ) {
		foreach ( $terms as $term ) {
			$object_ids = get_objects_in_term( $term->term_id, 'conexao_county' );
			$object_ids = is_wp_error( $object_ids ) ? array() : array_map( 'intval', $object_ids );

			echo '<div class="card" style="max-width:100%;padding:12px 16px;margin-bottom:16px;">';
			printf(
				'<h3 style="margin-top:0;">%1$s <code>%2$s</code> <span class="description">(#%3$d · %4$d registos)</span></h3>',
				esc_html( $term->name ),
				esc_html( $term->slug ),
				(int) $term->term_id,
				count( $object_ids )
			);

			if ( empty( $object_ids ) ) {
				echo '<p>' . esc_html__( 'Nenhum registo associado. O termo pode ser removido com segurança.', 'conexao-admin-ux' ) . '</p>';
			} else {
				echo '<ul style="margin-left:18px;">';
				foreach ( array_slice( $object_ids, 0, 25 ) as $object_id ) {
					$post = get_post( $object_id );
					if ( ! $post ) {
						printf( '<li>#%d — %s</li>', (int) $object_id, esc_html__( 'objeto inexistente', 'conexao-admin-ux' ) );
						continue;
					}
					printf(
						'<li><a href="%1$s">%2$s</a> <span class="description">(%3$s · %4$s)</span></li>',
						esc_url( (string) get_edit_post_link( $post->ID ) ),
						esc_html( '' !== $post->post_title ? $post->post_title : '#' . $post->ID ),
						esc_html( $post->post_type ),
						esc_html( $post->post_status )
					);
				}
				if ( count( $object_ids ) > 25 ) {
					printf( '<li>%s</li>', esc_html( sprintf( '… %d registos adicionais', count( $object_ids ) - 25 ) ) );
				}
				echo '</ul>';
			}

			$this->render_repair_form( $term, $object_ids );
			echo '</div>';
		}
	}

	/**
	 * The per-term repair form: target county + preview (dry-run) + apply.
	 *
	 * @param WP_Term $term       Out-of-list term.
	 * @param int[]   $object_ids Records currently attached to it.
	 */
	private function render_repair_form( $term, $object_ids ) {
		$selected   = isset( $_GET['preview_target'] ) ? Conexao_Data_Model_Counties::normalize( sanitize_text_field( wp_unslash( $_GET['preview_target'] ) ) ) : '';
		$is_preview = ( isset( $_GET['preview_term'] ) && (int) $_GET['preview_term'] === (int) $term->term_id );

		printf(
			'<form method="post" action="%s" style="margin-top:8px;">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PAGE_ACTION ) . '">';
		echo '<input type="hidden" name="term_id" value="' . (int) $term->term_id . '">';
		wp_nonce_field( self::PAGE_ACTION, 'conexao_counties_nonce' );

		$select_id = 'conexao-county-target-' . (int) $term->term_id;
		echo '<p><label for="' . esc_attr( $select_id ) . '"><strong>' . esc_html__( 'Reatribuir os registos a:', 'conexao-admin-ux' ) . '</strong></label> ';
		echo '<select id="' . esc_attr( $select_id ) . '" name="target">';
		echo '<option value="">' . esc_html__( '— Sem condado (deixar sem atribuição) —', 'conexao-admin-ux' ) . '</option>';
		foreach ( Conexao_Data_Model_Counties::all() as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected, $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';

		echo '<p>';
		echo '<button type="submit" name="mode" value="preview" class="button">' . esc_html__( 'Pré-visualizar (sem escrever)', 'conexao-admin-ux' ) . '</button> ';
		if ( $is_preview ) {
			echo '<button type="submit" name="mode" value="apply" class="button button-primary" onclick="return confirm(\'' . esc_js( __( 'Reatribuir os registos e remover este termo? Nenhum registo é apagado.', 'conexao-admin-ux' ) ) . '\');">' . esc_html__( 'Aplicar reparação', 'conexao-admin-ux' ) . '</button>';
		}
		echo '</p>';

		echo '</form>';

		if ( empty( $object_ids ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::PAGE_ACTION ) . '">';
			echo '<input type="hidden" name="term_id" value="' . (int) $term->term_id . '">';
			echo '<input type="hidden" name="mode" value="apply">';
			wp_nonce_field( self::PAGE_ACTION, 'conexao_counties_nonce' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Remover termo vazio', 'conexao-admin-ux' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Render the dry-run plan for the selected term.
	 *
	 * @param array{term_id:int,target:string} $preview Preview state.
	 */
	private function render_preview( $preview ) {
		$term = get_term( (int) $preview['term_id'], 'conexao_county' );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$plan = self::plan( $term->term_id, $preview['target'] );

		echo '<h2>' . esc_html__( 'Pré-visualização da reparação', 'conexao-admin-ux' ) . '</h2>';
		printf(
			'<p><strong>%1$s</strong> <code>%2$s</code> → <strong>%3$s</strong></p>',
			esc_html( $term->name ),
			esc_html( $term->slug ),
			esc_html( '' === $preview['target'] ? __( 'Sem condado', 'conexao-admin-ux' ) : Conexao_Data_Model_Counties::name_for( $preview['target'] ) )
		);

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of records. */
					__( 'Registos que serão reatribuídos: %d. O termo será removido apenas se não restarem referências. Nenhum registo é apagado.', 'conexao-admin-ux' ),
					(int) $plan['reassigned']
				)
			)
		);

		if ( empty( $plan['object_ids'] ) ) {
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Registo', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Tipo', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'conexao-admin-ux' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $plan['object_ids'] as $object_id ) {
			$post = get_post( $object_id );
			if ( ! $post ) {
				continue;
			}
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html( '' !== $post->post_title ? $post->post_title : '#' . $post->ID ),
				esc_html( $post->post_type ),
				esc_html( $post->post_status )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Misnamed canonical terms (slug approved, name drifted) + normalize action.
	 *
	 * @param WP_Term[] $terms Terms with a non-canonical display name.
	 */
	private function render_misnamed_table( $terms ) {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nome atual', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Nome canónico', 'conexao-admin-ux' ) . '</th>';
		echo '<th>' . esc_html__( 'Ação', 'conexao-admin-ux' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $terms as $term ) {
			echo '<tr>';
			echo '<td>' . esc_html( $term->name ) . '</td>';
			echo '<td><code>' . esc_html( $term->slug ) . '</code></td>';
			echo '<td><strong>' . esc_html( Conexao_Data_Model_Counties::name_for( $term->slug ) ) . '</strong></td>';
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::PAGE_ACTION ) . '">';
			echo '<input type="hidden" name="term_id" value="' . (int) $term->term_id . '">';
			echo '<input type="hidden" name="mode" value="normalize">';
			wp_nonce_field( self::PAGE_ACTION, 'conexao_counties_nonce' );
			echo '<button type="submit" class="button">' . esc_html__( 'Normalizar nome', 'conexao-admin-ux' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Last repair run (audit trail).
	 *
	 * @param array $last Stored last-run record.
	 */
	private function render_last_run( $last ) {
		if ( empty( $last ) || ! is_array( $last ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Última reparação', 'conexao-admin-ux' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
		$labels = array(
			'time'       => __( 'Data', 'conexao-admin-ux' ),
			'term'       => __( 'Termo', 'conexao-admin-ux' ),
			'target'     => __( 'Destino', 'conexao-admin-ux' ),
			'reassigned' => __( 'Registos reatribuídos', 'conexao-admin-ux' ),
			'deleted'    => __( 'Termo removido', 'conexao-admin-ux' ),
			'remaining'  => __( 'Referências restantes', 'conexao-admin-ux' ),
		);
		foreach ( $labels as $key => $label ) {
			$value = isset( $last[ $key ] ) ? $last[ $key ] : '';
			if ( 'deleted' === $key ) {
				$value = $value ? __( 'sim', 'conexao-admin-ux' ) : __( 'não', 'conexao-admin-ux' );
			} elseif ( 'target' === $key ) {
				$value = '' === $value ? __( 'Sem condado', 'conexao-admin-ux' ) : Conexao_Data_Model_Counties::name_for( $value );
			}
			printf( '<tr><th style="width:220px;">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
	}
}
