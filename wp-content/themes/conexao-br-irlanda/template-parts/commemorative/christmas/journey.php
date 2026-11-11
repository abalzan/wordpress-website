<?php
/**
 * Christmas package — "The Christmas Star: a journey between two skies".
 *
 * The signature, user-controlled feature of the experience (brief §4). It is
 * SERVER-RENDERED and complete without JavaScript:
 *
 *   - both skies are always in the DOM, as two fully written articles with their own
 *     heading, paragraph, three observed details and a nuance note;
 *   - the tabs live in a `hidden` container, so a visitor without JavaScript never
 *     meets a control that cannot work — they simply read both skies in order;
 *   - `presentations/christmas/christmas.js` reveals the control and upgrades the two
 *     articles into a standard WAI-ARIA tabs widget (roving tabindex, Arrow/Home/End,
 *     `aria-selected`, one visible panel at a time).
 *
 * Both perspectives are equal by construction: the copy is ONE data structure with two
 * sibling keys rendered by the same loop, so neither can drift into a footnote.
 * Nothing is stored, nothing is requested from the server, no preference persists and
 * no analytics event is emitted — the state lives in the DOM for the life of the page.
 *
 * The star between the tabs is the shared motif, drawn as SVG (`christmas/star.php`);
 * it travels once per selection (700 ms) and rests. Reduced motion removes the travel.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || 'christmas' !== $ce['package']['slug'] ) {
	return;
}

$ce_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );

$ce_heading = $ce_english ? __( 'Two skies, one star', 'conexao-br-irlanda' ) : __( 'Dois céus, uma mesma estrela', 'conexao-br-irlanda' );
$ce_intro   = $ce_english
	? __( 'The same star appears on both sides of the Atlantic. Choose a sky to light up, and notice what changes — and what stays the same.', 'conexao-br-irlanda' )
	: __( 'A mesma estrela aparece dos dois lados do Atlântico. Escolha um céu para acender e repare no que muda — e no que continua igual.', 'conexao-br-irlanda' );

/*
 * One data structure, two sibling keys, one rendering loop. The copy is deliberately
 * about how the season CAN feel, never about how a country "is": each side closes with
 * a note that says so, so neither perspective becomes a claim about everyone.
 */
if ( $ce_english ) {
	$ce_skies = array(
		'brasil'  => array(
			'tab'     => __( 'Brazil', 'conexao-br-irlanda' ),
			'season'  => __( 'summer', 'conexao-br-irlanda' ),
			'title'   => __( 'Summer, and the night takes its time', 'conexao-br-irlanda' ),
			'body'    => __( 'December in Brazil is summer. The sun is slow to go down, the street stays busy, and the meal can happen on a balcony, in a back garden or on the pavement — fan on, ice in everything, somebody complaining about the heat.', 'conexao-br-irlanda' ),
			'details' => array(
				__( 'Daylight becomes part of the decoration: the evening starts in the light.', 'conexao-br-irlanda' ),
				__( 'The table usually spills out of the house.', 'conexao-br-irlanda' ),
				__( 'People drop by after midnight — and stay.', 'conexao-br-irlanda' ),
			),
			'note'    => __( 'This is one picture among many. Brazil is huge, and every household keeps Christmas its own way.', 'conexao-br-irlanda' ),
		),
		'irlanda' => array(
			'tab'     => __( 'Ireland', 'conexao-br-irlanda' ),
			'season'  => __( 'winter', 'conexao-br-irlanda' ),
			'title'   => __( 'Winter, and the windows do the lighting', 'conexao-br-irlanda' ),
			'body'    => __( 'December in Ireland is winter. The dark arrives in the middle of the afternoon, and that is when the light inside becomes a signal: a lit window, a fire, lights in the garden, a candle on the table.', 'conexao-br-irlanda' ),
			'details' => array(
				__( 'The dark comes early, and it makes the light at home count for more.', 'conexao-br-irlanda' ),
				__( 'The cold gathers everyone into the same room.', 'conexao-br-irlanda' ),
				__( 'A walk at dusk, just to see the lights on your own street.', 'conexao-br-irlanda' ),
			),
			'note'    => __( 'Not a rule either: some people spend Christmas far from family, some work that day, and some do not celebrate it at all.', 'conexao-br-irlanda' ),
		),
	);
	$ce_thread        = __( 'What crosses both skies is not the weather. It is the gesture: someone thinks of you, and does something about it.', 'conexao-br-irlanda' );
	$ce_caption       = __( 'Illustration: the same star over a Brazilian summer evening and an Irish winter night.', 'conexao-br-irlanda' );
	$ce_tablist_label = __( 'Choose a sky', 'conexao-br-irlanda' );
	$ce_art_alt       = __( 'A large Christmas star at the centre, with arcs of light reaching a Brazilian beach and city on the left and a snowy Irish hillside with a stone tower on the right.', 'conexao-br-irlanda' );
} else {
	$ce_skies = array(
		'brasil'  => array(
			'tab'     => __( 'Brasil', 'conexao-br-irlanda' ),
			'season'  => __( 'verão', 'conexao-br-irlanda' ),
			'title'   => __( 'Verão, e a noite custa a chegar', 'conexao-br-irlanda' ),
			'body'    => __( 'Dezembro no Brasil é verão. O sol demora a cair, a rua continua cheia e a ceia pode acontecer na varanda, no quintal ou na calçada — ventilador ligado, gelo em tudo, alguém reclamando do calor.', 'conexao-br-irlanda' ),
			'details' => array(
				__( 'A luz do dia entra na decoração: a noite começa claro.', 'conexao-br-irlanda' ),
				__( 'A mesa costuma se espalhar para fora de casa.', 'conexao-br-irlanda' ),
				__( 'As visitas chegam depois da meia-noite — e ficam.', 'conexao-br-irlanda' ),
			),
			'note'    => __( 'Isto é um retrato entre muitos. O Brasil é grande, e cada casa guarda o seu jeito de fazer Natal.', 'conexao-br-irlanda' ),
		),
		'irlanda' => array(
			'tab'     => __( 'Irlanda', 'conexao-br-irlanda' ),
			'season'  => __( 'inverno', 'conexao-br-irlanda' ),
			'title'   => __( 'Inverno, e são as janelas que acendem', 'conexao-br-irlanda' ),
			'body'    => __( 'Dezembro na Irlanda é inverno. Escurece no meio da tarde, e é aí que a luz de dentro vira sinal: janela acesa, lareira, luzinhas no jardim, uma vela na mesa.', 'conexao-br-irlanda' ),
			'details' => array(
				__( 'A escuridão chega cedo, e faz a luz de casa valer mais.', 'conexao-br-irlanda' ),
				__( 'O frio junta todo mundo no mesmo cômodo.', 'conexao-br-irlanda' ),
				__( 'Um passeio no fim da tarde só para ver as luzes da sua rua.', 'conexao-br-irlanda' ),
			),
			'note'    => __( 'Também não é regra: há quem passe o Natal longe da família, quem trabalhe nesse dia e quem não comemore.', 'conexao-br-irlanda' ),
		),
	);
	$ce_thread        = __( 'O que atravessa os dois céus não é o clima. É o gesto: alguém pensa em você e faz alguma coisa a respeito.', 'conexao-br-irlanda' );
	$ce_caption       = __( 'Ilustração: a mesma estrela sobre um fim de tarde de verão no Brasil e uma noite de inverno na Irlanda.', 'conexao-br-irlanda' );
	$ce_tablist_label = __( 'Escolha um céu', 'conexao-br-irlanda' );
	$ce_art_alt       = __( 'Uma grande estrela de Natal no centro, com arcos de luz que chegam a uma praia e a uma cidade brasileiras à esquerda e a uma encosta irlandesa coberta de neve, com uma torre de pedra, à direita.', 'conexao-br-irlanda' );
}

$ce_art_uri = get_template_directory_uri() . '/assets/images/presentations/christmas/two-skies/';
?>
<section class="ce-christmas-journey" aria-labelledby="ce-christmas-journey-title">
	<div class="ce-christmas-journey__intro">
		<p class="ce-christmas-journey__kicker"><?php echo esc_html( $ce_english ? __( 'The journey', 'conexao-br-irlanda' ) : __( 'A viagem', 'conexao-br-irlanda' ) ); ?></p>
		<h2 class="ce-christmas-journey__title" id="ce-christmas-journey-title"><?php echo esc_html( $ce_heading ); ?></h2>
		<p class="ce-christmas-journey__lede"><?php echo esc_html( $ce_intro ); ?></p>
	</div>

	<figure class="ce-christmas-journey__art">
		<img
			src="<?php echo esc_url( $ce_art_uri . 'star-bridge-1280.webp' ); ?>"
			srcset="<?php echo esc_url( $ce_art_uri . 'star-bridge-780.webp' ); ?> 780w, <?php echo esc_url( $ce_art_uri . 'star-bridge-1280.webp' ); ?> 1280w"
			sizes="(max-width: 820px) calc(100vw - 32px), 780px"
			width="1280"
			height="720"
			alt="<?php echo esc_attr( $ce_art_alt ); ?>"
			loading="lazy"
			decoding="async"
			draggable="false"
		>
		<figcaption class="ce-christmas-journey__caption"><?php echo esc_html( $ce_caption ); ?></figcaption>
	</figure>

	<div class="ce-christmas-journey__scene" data-ce-journey data-ce-journey-state="brasil">
		<div class="ce-christmas-journey__controls" data-ce-journey-controls hidden>
			<p class="ce-christmas-journey__controls-label" id="ce-christmas-journey-controls-label"><?php echo esc_html( $ce_tablist_label ); ?></p>
			<div class="ce-christmas-journey__tabs" data-ce-journey-tabs>
				<?php foreach ( $ce_skies as $ce_key => $ce_sky ) : ?>
					<button
						type="button"
						class="ce-christmas-journey__tab ce-christmas-journey__tab--<?php echo esc_attr( $ce_key ); ?>"
						id="ce-christmas-journey-tab-<?php echo esc_attr( $ce_key ); ?>"
						data-ce-journey-tab="<?php echo esc_attr( $ce_key ); ?>"
						aria-controls="ce-christmas-journey-panel-<?php echo esc_attr( $ce_key ); ?>"
						tabindex="-1"
					>
						<span class="ce-christmas-journey__tab-name"><?php echo esc_html( $ce_sky['tab'] ); ?></span>
						<span class="ce-christmas-journey__tab-season"><?php echo esc_html( $ce_sky['season'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<span class="ce-christmas-journey__star" aria-hidden="true">
				<?php
				get_template_part(
					'template-parts/commemorative/christmas/star',
					null,
					array(
						'id'    => 'journey',
						'class' => 'ce-christmas-journey__star-svg',
					)
				);
				?>
			</span>
		</div>

		<div class="ce-christmas-journey__panels">
			<?php foreach ( $ce_skies as $ce_key => $ce_sky ) : ?>
				<div
					class="ce-christmas-journey__panel ce-christmas-journey__panel--<?php echo esc_attr( $ce_key ); ?>"
					id="ce-christmas-journey-panel-<?php echo esc_attr( $ce_key ); ?>"
					data-ce-journey-panel="<?php echo esc_attr( $ce_key ); ?>"
				>
					<div class="ce-christmas-journey__sky ce-christmas-journey__sky--<?php echo esc_attr( $ce_key ); ?>" aria-hidden="true">
						<span class="ce-christmas-journey__sky-light"></span>
						<span class="ce-christmas-journey__sky-horizon"></span>
						<span class="ce-christmas-journey__sky-dots"></span>
					</div>
					<div class="ce-christmas-journey__panel-body">
						<p class="ce-christmas-journey__season"><?php echo esc_html( $ce_sky['season'] ); ?></p>
						<h3 class="ce-christmas-journey__panel-title"><?php echo esc_html( $ce_sky['title'] ); ?></h3>
						<p class="ce-christmas-journey__panel-text"><?php echo esc_html( $ce_sky['body'] ); ?></p>
						<ul class="ce-christmas-journey__details">
							<?php foreach ( $ce_sky['details'] as $ce_detail ) : ?>
								<li><?php echo esc_html( $ce_detail ); ?></li>
							<?php endforeach; ?>
						</ul>
						<p class="ce-christmas-journey__note"><?php echo esc_html( $ce_sky['note'] ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="ce-christmas-journey__thread">
			<span class="ce-christmas-journey__thread-star" aria-hidden="true">
				<?php
				get_template_part(
					'template-parts/commemorative/christmas/star',
					null,
					array(
						'id'    => 'thread',
						'class' => 'ce-christmas-journey__thread-star-svg',
					)
				);
				?>
			</span>
			<?php echo esc_html( $ce_thread ); ?>
		</p>
	</div>
</section>
