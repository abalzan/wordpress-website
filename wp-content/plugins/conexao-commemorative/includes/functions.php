<?php
/**
 * Public, theme-independent commemorative engine API.
 *
 * Templates, the admin UX and tests use ONLY these functions. None of them
 * references a template path, a CSS/JS handle, an asset file or a holiday name:
 * the engine never knows the theme, and a presentation package never knows a
 * date (§2.1, §2.2).
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * The full resolution for this request (winner, superseded, diagnostics).
 *
 * @param DateTimeInterface|null $now Injected clock ("now" when null).
 * @return array
 */
function conexao_commemorative_resolution( $now = null ) {
	static $memo = array();

	$key = ( $now instanceof DateTimeInterface ? $now->format( 'c' ) : 'now' ) . '|' . ( Conexao_Commemorative_Preview::is_active() ? 'preview' : 'live' );

	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}

	$memo[ $key ] = Conexao_Commemorative_Resolver::active(
		$now,
		array( 'preview' => Conexao_Commemorative_Preview::requested() )
	);

	return $memo[ $key ];
}

/**
 * The selected event for this request, or null.
 *
 * @param DateTimeInterface|null $now Injected clock.
 * @return Conexao_Commemorative_Event|null
 */
function conexao_commemorative_active( $now = null ) {
	$resolution = conexao_commemorative_resolution( $now );

	if ( empty( $resolution['event'] ) ) {
		return null;
	}

	$event   = (array) $resolution['event'];
	$content = Conexao_Commemorative_Repository::content( $event );

	return new Conexao_Commemorative_Event( $event, $content, ! empty( $resolution['preview'] ) );
}

/**
 * Whether a commemorative experience is displayed for this request.
 *
 * @param DateTimeInterface|null $now Injected clock.
 * @return bool
 */
function conexao_commemorative_is_active( $now = null ) {
	return null !== conexao_commemorative_active( $now );
}

/**
 * The authorised preview record for this request, or null.
 *
 * @return array|null
 */
function conexao_commemorative_preview_event() {
	return Conexao_Commemorative_Preview::requested();
}

/**
 * Whether the current request is an authorised administrator preview.
 *
 * @return bool
 */
function conexao_commemorative_is_preview() {
	return Conexao_Commemorative_Preview::is_active();
}

/**
 * The armed activation boundary (next window start or end).
 *
 * @return array|null
 */
function conexao_commemorative_next_boundary() {
	return Conexao_Commemorative_Cache::armed_boundary();
}

/**
 * The registered experience packages (theme-owned, plugin-read).
 *
 * @return array
 */
function conexao_commemorative_presentations() {
	$packages = apply_filters( 'conexao_commemorative_presentations', array() );

	return is_array( $packages ) ? $packages : array();
}

/**
 * Everything the wp-admin list and diagnostics need for ONE record.
 *
 * The admin UX never computes scheduling itself: it asks here (§2.2). The
 * window shown is the NEXT occurrence, and the year badge uses that occurrence
 * year — never `$now->format('Y')`.
 *
 * @param int                    $post_id Record ID.
 * @param DateTimeInterface|null $now     Injected clock.
 * @return array|null
 */
function conexao_commemorative_admin_overview( $post_id, $now = null ) {
	$event = Conexao_Commemorative_Repository::find( $post_id );

	if ( ! $event ) {
		return null;
	}

	$evaluation = Conexao_Commemorative_Resolver::evaluate( $event, $now );
	$next       = Conexao_Commemorative_Schedule::next_window( $event, $now );
	$packages   = Conexao_Commemorative_Resolver::packages();

	$next_year  = $next ? (int) $next['occurrence_year'] : null;
	$preferred  = null !== $next_year && Conexao_Commemorative_Resolver::is_preferred( $event, $next_year );
	$package_ok = empty( $packages ) || ( '' !== $event['presentation'] && in_array( $event['presentation'], $packages, true ) );

	return array(
		'event'           => $event,
		'evaluation'      => $evaluation,
		'next_window'     => $next,
		'next_year'       => $next_year,
		'preferred'       => $preferred,
		'package_known'   => $package_ok,
		'status'          => $event['status'],
		'enabled'         => $event['enabled'],
		'priority'        => $event['priority'],
		'presentation'    => $event['presentation'],
		'reason'          => $evaluation['reason'],
		'eligible'        => $evaluation['eligible'],
		'window_label'    => $next ? $next['start']->format( 'Y-m-d' ) . ' → ' . $next['end']->format( 'Y-m-d' ) : '',
		'next_year_label' => null !== $next_year ? (string) $next_year : '',
	);
}

/**
 * A human-readable explanation of why a record is (not) displayed.
 *
 * @param array      $evaluation Evaluation row.
 * @param array|null $next       Next window.
 * @return string
 */
function conexao_commemorative_reason_label( array $evaluation, $next = null ) {
	$reason = isset( $evaluation['reason'] ) ? (string) $evaluation['reason'] : '';

	switch ( $reason ) {
		case Conexao_Commemorative_Resolver::REASON_OK:
			return __( 'Elegível agora', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_DRAFT:
			return __( 'Rascunho (não publicado)', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_DISABLED:
			return __( 'Desativado (não participa do agendamento)', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_NO_IDENTITY:
			return __( 'Sem chave de evento (_ce_event_key)', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_UNKNOWN_PACKAGE:
			return __( 'Pacote de apresentação desconhecido', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_INVALID:
			return __( 'Agendamento inválido', 'conexao-commemorative' );

		case Conexao_Commemorative_Resolver::REASON_OUTSIDE_WINDOW:
			if ( $next && ! empty( $next['start'] ) ) {
				/* translators: %s: next window start date. */
				return sprintf( __( 'Fora da janela (próxima em %s)', 'conexao-commemorative' ), $next['start']->format( 'Y-m-d' ) );
			}

			return __( 'Fora da janela', 'conexao-commemorative' );
	}

	return $reason;
}
