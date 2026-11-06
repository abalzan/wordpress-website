<?php
/**
 * Christmas package — homepage-hero content.
 *
 * Signature motif: ONE BAUBLE ORNAMENT (r10 §7.12). The identity is deliberately
 * calm: it renders server-side, is visible without JavaScript, is not dismissible
 * (it is a band, not an overlay) and never animates indefinitely.
 *
 * Copy resolution: the event record's translated `_ce_heading` / `_ce_subheading` /
 * `_ce_cta_*` when the editor filled them in, otherwise this package's documented
 * default voice (§7.12: PT and EN examples).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['event'] ) ) {
	return;
}

$ce_event    = $ce['event'];
$ce_locale   = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english  = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );
$ce_heading  = '' !== $ce_event->heading ? $ce_event->heading : ( $ce_english ? __( 'A Christmas that feels like home', 'conexao-br-irlanda' ) : __( 'Um Natal com sabor de casa', 'conexao-br-irlanda' ) );
$ce_copy     = '' !== $ce_event->subheading
	? $ce_event->subheading
	: ( $ce_english
		? __( 'Concerts, markets and community gatherings across Ireland — plus the guides that make the season easier.', 'conexao-br-irlanda' )
		: __( 'Concertos, feiras e encontros da comunidade por toda a Irlanda — e os guias que deixam esta época mais simples.', 'conexao-br-irlanda' ) );
$ce_cta      = '' !== $ce_event->cta_label ? $ce_event->cta_label : ( $ce_english ? __( 'See the Christmas calendar', 'conexao-br-irlanda' ) : __( 'Ver a agenda de Natal', 'conexao-br-irlanda' ) );
$ce_cta_href = '' !== $ce_event->cta_url ? $ce_event->cta_url : conexao_lang_url( '/eventos/' );
$ce_landing  = conexao_commemorative_resolve_record( 'page', $ce_english ? 'christmas' : 'natal' );
?>
<div class="ce-christmas-hero ce-hero">
	<span class="ce-christmas-hero__ornament" aria-hidden="true">
		<?php
		// The one signature motif: a single bauble ornament.
		$ce_bauble = get_template_directory() . '/assets/images/presentations/christmas/decorations/bauble.svg';

		if ( is_file( $ce_bauble ) ) {
			echo file_get_contents( $ce_bauble ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- repository-authored static SVG; escaping would corrupt the markup and no user input is involved.
		}
		?>
	</span>
	<div class="ce-christmas-hero__body">
		<p class="ce-christmas-hero__kicker"><?php esc_html_e( 'Natal', 'conexao-br-irlanda' ); ?></p>
		<h2 class="ce-christmas-hero__title"><?php echo esc_html( $ce_heading ); ?></h2>
		<p class="ce-christmas-hero__copy"><?php echo esc_html( $ce_copy ); ?></p>
		<div class="ce-christmas-hero__actions">
			<a class="btn btn-primary ce-christmas-hero__cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
				<?php echo esc_html( $ce_cta ); ?>
			</a>
			<?php if ( $ce_landing instanceof WP_Post ) : ?>
				<a class="btn btn-outline ce-christmas-hero__link" href="<?php echo esc_url( get_permalink( $ce_landing ) ); ?>">
					<?php echo esc_html( $ce_english ? __( 'Open the Christmas page', 'conexao-br-irlanda' ) : __( 'Abrir a página de Natal', 'conexao-br-irlanda' ) ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</div>
