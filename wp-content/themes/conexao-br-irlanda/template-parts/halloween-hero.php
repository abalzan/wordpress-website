<?php
/**
 * Halloween seasonal welcome for the homepage hero.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$seasonal_occasion = isset( $args['seasonal_occasion'] ) ? $args['seasonal_occasion'] : null;
if (
	! ( $seasonal_occasion instanceof Conexao_Seasonal_Occasion )
	|| 'halloween' !== $seasonal_occasion->id
	|| ! $seasonal_occasion->enabled
) {
	return;
}

$occurrence_date = $seasonal_occasion->resolve_occurrence_date();
if ( ! ( $occurrence_date instanceof DateTime ) ) {
	return;
}

$current_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$is_english      = in_array( $current_locale, array( 'en_US', 'en_GB', 'en' ), true );
$halloween_title = $is_english ? __( 'Happy Halloween!', 'conexao-br-irlanda' ) : __( 'Feliz Halloween!', 'conexao-br-irlanda' );
$halloween_copy  = $is_english
	? __( 'The most spooktacular time of year has arrived in Ireland. Wishing our whole community a magical Halloween!', 'conexao-br-irlanda' )
	: __( 'A época mais assustadoramente divertida chegou à Irlanda. Desejamos um Halloween mágico a toda a nossa comunidade!', 'conexao-br-irlanda' );
$cta_label       = $is_english ? __( 'Explore events', 'conexao-br-irlanda' ) : __( 'Explorar eventos', 'conexao-br-irlanda' );
$close_label     = $is_english ? __( 'Close Halloween message', 'conexao-br-irlanda' ) : __( 'Fechar mensagem de Halloween', 'conexao-br-irlanda' );
?>
<div
	class="seasonal-halloween-presentation"
	data-dismiss-key="<?php echo esc_attr( 'conexao-seasonal-halloween-' . $occurrence_date->format( 'Y' ) ); ?>"
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
		<button class="seasonal-halloween-close" type="button" aria-label="<?php echo esc_attr( $close_label ); ?>">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path d="M6 6l12 12M18 6 6 18" />
			</svg>
		</button>
		<svg class="seasonal-halloween-pumpkin" viewBox="0 0 96 96" aria-hidden="true" focusable="false">
			<defs>
				<radialGradient id="seasonal-pumpkin-fill" cx="38%" cy="28%" r="74%">
					<stop offset="0" stop-color="#ffbf69" />
					<stop offset=".48" stop-color="#ed7929" />
					<stop offset="1" stop-color="#8f341e" />
				</radialGradient>
				<linearGradient id="seasonal-pumpkin-stem" x1="0" x2="1">
					<stop offset="0" stop-color="#4d512d" />
					<stop offset="1" stop-color="#8c7b3d" />
				</linearGradient>
			</defs>
			<ellipse cx="48" cy="84" rx="31" ry="6" fill="#f18a38" opacity=".2" />
			<path d="M48 27c-7-4-8-11-5-18 4 4 8 5 12 3-1 7-1 11 4 15" fill="url(#seasonal-pumpkin-stem)" stroke="#3f4528" stroke-width="2" stroke-linejoin="round" />
			<path d="M48 25c-9-7-22-4-30 4-11 11-11 32-3 45 8 12 20 14 33 7 13 7 25 5 33-7 8-13 8-34-3-45-8-8-21-11-30-4z" fill="url(#seasonal-pumpkin-fill)" stroke="#9c451f" stroke-width="2" />
			<path d="M48 26c-8 9-9 42 0 55m0-55c9 9 10 42 0 55M32 27c-6 12-7 36 1 51m31-51c6 12 7 36-1 51" fill="none" stroke="#8e3a21" stroke-width="3" opacity=".48" />
			<path d="m26 43 15 4-10 12zm44 0-15 4 10 12z" fill="#ffd16f" stroke="#8a351b" stroke-width="2" stroke-linejoin="round" />
			<path d="M29 64c5 3 10 3 15 0l4 6 4-6c5 3 10 3 15 0-2 12-9 17-19 17S31 76 29 64z" fill="#ffc45f" stroke="#8a351b" stroke-width="2" stroke-linejoin="round" />
			<path d="M45 66h6m-4 7h4m-7 6h5" stroke="#a34c20" stroke-width="2" stroke-linecap="round" opacity=".75" />
		</svg>
		<p class="seasonal-halloween-kicker"><?php esc_html_e( 'Halloween', 'conexao-br-irlanda' ); ?></p>
		<h2 class="seasonal-halloween-title" id="seasonal-halloween-title"><?php echo esc_html( $halloween_title ); ?></h2>
		<p class="seasonal-halloween-copy"><?php echo esc_html( $halloween_copy ); ?></p>
		<a class="btn btn-primary seasonal-halloween-cta" href="<?php echo esc_url( conexao_lang_url( '/eventos/' ) ); ?>">
			<?php echo esc_html( $cta_label ); ?>
		</a>
	</section>
</div>
