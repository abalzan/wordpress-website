<?php
/**
 * Halloween package — homepage-hero content.
 *
 * The signature motif is ONE CARVED PUMPKIN (r10 §7.6 addendum): the pumpkin is
 * the visual anchor, the moon/bats/mist are subordinate supporting details, and
 * no other event motif may appear here.
 *
 * The package identity, the occurrence year and the copy resolution come from the
 * dispatcher context; this template performs no date or eligibility logic.
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
$ce_year     = null !== $ce_event->occurrence_year ? (string) (int) $ce_event->occurrence_year : '';
$ce_locale   = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english  = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );
$ce_heading  = '' !== $ce_event->heading ? $ce_event->heading : ( $ce_english ? __( 'Happy Halloween!', 'conexao-br-irlanda' ) : __( 'Feliz Halloween!', 'conexao-br-irlanda' ) );
$ce_copy     = '' !== $ce_event->subheading
	? $ce_event->subheading
	: ( $ce_english
		? __( 'The most spooktacular time of year has arrived in Ireland. Wishing our whole community a magical Halloween!', 'conexao-br-irlanda' )
		: __( 'A época mais assustadoramente divertida chegou à Irlanda. Desejamos um Halloween mágico a toda a nossa comunidade!', 'conexao-br-irlanda' ) );
$ce_cta      = '' !== $ce_event->cta_label ? $ce_event->cta_label : ( $ce_english ? __( 'Explore events', 'conexao-br-irlanda' ) : __( 'Explorar eventos', 'conexao-br-irlanda' ) );
$ce_cta_href = '' !== $ce_event->cta_url ? $ce_event->cta_url : conexao_lang_url( '/eventos/' );
$ce_close    = $ce_english ? __( 'Close Halloween message', 'conexao-br-irlanda' ) : __( 'Fechar mensagem de Halloween', 'conexao-br-irlanda' );

$ce_dismiss_key = 'conexao-seasonal-halloween-' . ( '' !== $ce_year ? $ce_year : 'current' );
?>
<div
	class="seasonal-halloween-presentation ce-hero"
	data-dismiss-key="<?php echo esc_attr( $ce_dismiss_key ); ?>"
	data-ce-reveal
	hidden
>
	<div class="seasonal-halloween-scene" aria-hidden="true">
		<span class="seasonal-halloween-moon"></span>
		<span class="seasonal-halloween-star seasonal-halloween-star--1"></span>
		<span class="seasonal-halloween-star seasonal-halloween-star--2"></span>
		<span class="seasonal-halloween-star seasonal-halloween-star--3"></span>
		<span class="seasonal-halloween-star seasonal-halloween-star--4"></span>
		<span class="seasonal-halloween-star seasonal-halloween-star--5"></span>
		<span class="seasonal-halloween-bat seasonal-halloween-bat--1">
			<svg viewBox="0 0 120 64" focusable="false">
				<g class="seasonal-halloween-bat-wing seasonal-halloween-bat-wing--left">
					<path d="M57 26C47 21 36 12 22 5l7 15L7 15l12 18L8 40l19 5-1 9 15-11 9-8 8-3z" />
				</g>
				<g class="seasonal-halloween-bat-wing seasonal-halloween-bat-wing--right">
					<path d="M63 26c10-5 21-14 35-21l-7 15 22-5-12 18 11 7-19 5 1 9-15-11-9-8-8-3z" />
				</g>
				<path class="seasonal-halloween-bat-body" d="M53 24l7-5 7 5 3 16-10 11-10-11z" />
				<circle class="seasonal-halloween-bat-body" cx="60" cy="18" r="7" />
				<path class="seasonal-halloween-bat-body" d="M54 14l-5-7 1 12m16-5 5-7-1 12M56 47l-3 7 7-4 7 4-3-7" />
			</svg>
		</span>
		<span class="seasonal-halloween-bat seasonal-halloween-bat--2">
			<svg viewBox="0 0 120 64" focusable="false">
				<g class="seasonal-halloween-bat-wing seasonal-halloween-bat-wing--left">
					<path d="M57 26C47 21 36 12 22 5l7 15L7 15l12 18L8 40l19 5-1 9 15-11 9-8 8-3z" />
				</g>
				<g class="seasonal-halloween-bat-wing seasonal-halloween-bat-wing--right">
					<path d="M63 26c10-5 21-14 35-21l-7 15 22-5-12 18 11 7-19 5 1 9-15-11-9-8-8-3z" />
				</g>
				<path class="seasonal-halloween-bat-body" d="M53 24l7-5 7 5 3 16-10 11-10-11z" />
				<circle class="seasonal-halloween-bat-body" cx="60" cy="18" r="7" />
				<path class="seasonal-halloween-bat-body" d="M54 14l-5-7 1 12m16-5 5-7-1 12M56 47l-3 7 7-4 7 4-3-7" />
			</svg>
		</span>
		<span class="seasonal-halloween-mist"></span>
	</div>
	<div class="seasonal-halloween-backdrop" aria-hidden="true"></div>
	<section class="seasonal-halloween-card" aria-labelledby="seasonal-halloween-title">
		<button class="seasonal-halloween-close" type="button" data-ce-dismiss aria-label="<?php echo esc_attr( $ce_close ); ?>">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path d="M6 6l12 12M18 6 6 18" />
			</svg>
		</button>
		<?php
		get_template_part( 'template-parts/commemorative/halloween/pumpkin', null, array() );
		?>
		<p class="seasonal-halloween-kicker"><?php esc_html_e( 'Halloween', 'conexao-br-irlanda' ); ?></p>
		<h2 class="seasonal-halloween-title" id="seasonal-halloween-title"><?php echo esc_html( $ce_heading ); ?></h2>
		<p class="seasonal-halloween-copy"><?php echo esc_html( $ce_copy ); ?></p>
		<a class="btn btn-primary seasonal-halloween-cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
			<?php echo esc_html( $ce_cta ); ?>
		</a>
	</section>
</div>
