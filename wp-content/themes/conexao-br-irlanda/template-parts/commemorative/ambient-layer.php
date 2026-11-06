<?php
/**
 * Shared site-wide ambient placement shell.
 *
 * A decorative layer, never a widget (r10 §7.7): the wrapper is
 * `aria-hidden="true"`, `pointer-events: none` and contains no focusable node.
 * The package supplies the decoration markup/behaviour; the shared scheduler in
 * `assets/js/commemorative.js` owns the timing, the cap and the cleanup.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || empty( $ce['placement'] ) || empty( $ce['package']['slug'] ) ) {
	return;
}

$ce_attributes = conexao_commemorative_wrapper_attributes( $ce );
$ce_content    = isset( $ce['placement']['content'] ) ? (string) $ce['placement']['content'] : '';
$ce_options    = conexao_commemorative_ambient_options( $ce['placement'] );
$ce_config     = wp_json_encode(
	array(
		'package'                 => (string) $ce['package']['slug'],
		'placement'               => (string) $ce['placement_id'],
		'occurrenceYear'          => null !== ( $ce['event'] ?? null ) && null !== $ce['event']->occurrence_year ? (int) $ce['event']->occurrence_year : 0,
		'energy'                  => (string) $ce_options['energy'],
		'intervalMinMs'           => (int) $ce_options['interval_min_ms'],
		'intervalMaxMs'           => (int) $ce_options['interval_max_ms'],
		'subsequentIntervalMinMs' => (int) $ce_options['subsequent_interval_min_ms'],
		'subsequentIntervalMaxMs' => (int) $ce_options['subsequent_interval_max_ms'],
		'durationMinMs'           => (int) $ce_options['duration_min_ms'],
		'durationMaxMs'           => (int) $ce_options['duration_max_ms'],
		'maxSimultaneous'         => (int) $ce_options['max_simultaneous'],
		'sessionCap'              => (int) $ce_options['session_cap'],
	)
);
?>
<div<?php echo $ce_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by conexao_commemorative_wrapper_attributes(). ?> data-ce-ambient-config="<?php echo esc_attr( (string) $ce_config ); ?>" aria-hidden="true">
	<?php
	if ( '' !== $ce_content && conexao_commemorative_theme_file_exists( $ce_content . '.php' ) ) {
		get_template_part( $ce_content, null, array( 'ce' => $ce ) );
	}
	?>
</div>
