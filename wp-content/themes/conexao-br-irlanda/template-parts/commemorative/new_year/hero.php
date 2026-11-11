<?php
/**
 * New Year package — homepage-hero content ("Two Countries, One New Beginning").
 *
 * The supplied New Year artwork (Brazilian landscape + Dublin riverside, shared
 * night sky, fireworks, champagne glasses) carries the visual message; ALL copy
 * stays real, localised HTML beside it. The artwork already contains the words
 * "Happy New Year", so no large heading is placed over it — only a small kicker
 * and the concise shared message sit next to the art.
 *
 * Layout contract: the art and the copy are two siblings, never layered, so no
 * sentence is ever placed on top of the photograph. The art keeps its intrinsic
 * 16:9 box at every width.
 *
 * One CTA only: the event record may override it; otherwise it leads to this
 * package's own landing page, and to the shared calendar only when that page
 * cannot be resolved.
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

$ce_event   = $ce['event'];
$ce_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );

$ce_heading = '' !== $ce_event->heading ? $ce_event->heading : ( $ce_english ? __( 'Two countries, one new beginning', 'conexao-br-irlanda' ) : __( 'Dois países, um novo começo', 'conexao-br-irlanda' ) );

$ce_copy = '' !== $ce_event->subheading
	? $ce_event->subheading
	: ( $ce_english
		? __( 'Two countries, two cultures, one shared celebration. May the New Year bring new opportunities, meaningful connections, happiness, and prosperity to our Brazilian and Irish communities.', 'conexao-br-irlanda' )
		: __( 'Dois países, duas culturas, uma celebração compartilhada. Que o Ano Novo traga novas oportunidades, conexões significativas, felicidade e prosperidade às nossas comunidades brasileira e irlandesa.', 'conexao-br-irlanda' ) );

$ce_landing = function_exists( 'conexao_commemorative_resolve_record' )
	? conexao_commemorative_resolve_record( 'page', $ce_english ? 'new-year' : 'ano-novo' )
	: null;

if ( '' !== (string) $ce_event->cta_url ) {
	$ce_cta_href = (string) $ce_event->cta_url;
} elseif ( $ce_landing instanceof WP_Post ) {
	$ce_cta_href = (string) get_permalink( $ce_landing );
} else {
	$ce_cta_href = conexao_lang_url( '/eventos/' );
}

$ce_cta = '' !== (string) $ce_event->cta_label
	? (string) $ce_event->cta_label
	: ( $ce_landing instanceof WP_Post
		? ( $ce_english ? __( 'See the New Year page', 'conexao-br-irlanda' ) : __( 'Ver a página de Ano Novo', 'conexao-br-irlanda' ) )
		: ( $ce_english ? __( 'See the events calendar', 'conexao-br-irlanda' ) : __( 'Ver a agenda de eventos', 'conexao-br-irlanda' ) ) );

$ce_art_uri = get_template_directory_uri() . '/assets/images/presentations/new_year/';

$ce_art_alt = $ce_english
	? __( 'New Year celebration: fireworks over a shared night sky above Rio de Janeiro and Dublin, with champagne glasses and festive ribbons in the foreground.', 'conexao-br-irlanda' )
	: __( 'Celebração de Ano Novo: fogos de artifício sobre um céu noturno compartilhado acima do Rio de Janeiro e de Dublin, com taças de champanhe e fitas festivas ao primeiro plano.', 'conexao-br-irlanda' );

$ce_kicker = $ce_english ? __( 'New Year · Brazil and Ireland', 'conexao-br-irlanda' ) : __( 'Ano Novo · Brasil e Irlanda', 'conexao-br-irlanda' );

$ce_close_label = $ce_english ? __( 'Close', 'conexao-br-irlanda' ) : __( 'Fechar', 'conexao-br-irlanda' );

$ce_is_preview = function_exists( 'conexao_commemorative_is_preview' ) && conexao_commemorative_is_preview();
?>
<div class="ce-new-year-welcome" data-ce-welcome<?php echo $ce_is_preview ? ' data-ce-welcome-preview="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute, no user data. ?>>
	<script>
	/*
	 * Progressive-enhancement guard. Parse-time only: it asks the browser whether a
	 * modal dialog is really available and, if so, marks the page `pending` so the
	 * compact fallback card is hidden before first paint (no flash of a card that
	 * is about to become a modal). If the package script never takes the claim by
	 * DOMContentLoaded, the same guard reveals the fallback instead. Nothing is
	 * stored, calculated or decided here: never a date, never a dismissal rule.
	 */
	(function () {
		try {
			var html = document.documentElement;
			var probe = document.createElement('dialog');

			if (!probe || typeof probe.showModal !== 'function') {
				return;
			}

			html.setAttribute('data-ce-welcome-state', 'pending');

			var revealFallback = function () {
				if ('pending' === html.getAttribute('data-ce-welcome-state')) {
					html.setAttribute('data-ce-welcome-state', 'fallback');
				}
			};

			if ('loading' === document.readyState) {
				document.addEventListener('DOMContentLoaded', revealFallback);
			} else {
				revealFallback();
			}
		} catch (error) {
			/* No dialog support: the fallback card stays visible, exactly as rendered. */
		}
	}());
	</script>
	<dialog
		class="ce-new-year-welcome__dialog"
		id="ce-new-year-welcome-dialog"
		data-ce-welcome-dialog<?php echo $ce_is_preview ? ' data-ce-welcome-preview="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute, no user data. ?>
		aria-labelledby="ce-new-year-welcome-title"
		aria-describedby="ce-new-year-welcome-desc"
	>
		<button
			type="button"
			class="ce-new-year-welcome__close"
			id="ce-new-year-welcome-close"
			data-ce-welcome-close
			hidden
		>
			<span class="ce-new-year-welcome__close-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false"><path d="M6 6l12 12M18 6L6 18"></path></svg>
			</span>
			<span class="ce-new-year-welcome__close-label"><?php echo esc_html( $ce_close_label ); ?></span>
		</button>
		<div class="ce-new-year-hero ce-hero">
			<figure class="ce-new-year-hero__art">
				<img
					src="<?php echo esc_url( $ce_art_uri . 'new_year-hero-1200.webp' ); ?>"
					srcset="<?php echo esc_url( $ce_art_uri . 'new_year-hero-840.webp' ); ?> 840w, <?php echo esc_url( $ce_art_uri . 'new_year-hero-1200.webp' ); ?> 1200w"
					sizes="(max-width: 768px) calc(100vw - 48px), 480px"
					width="1200"
					height="675"
					alt="<?php echo esc_attr( $ce_art_alt ); ?>"
					loading="eager"
					decoding="async"
					fetchpriority="low"
					draggable="false"
				>
			</figure>
			<div class="ce-new-year-hero__body">
				<p class="ce-new-year-hero__kicker">
					<?php echo esc_html( $ce_kicker ); ?>
				</p>
				<h2 class="ce-new-year-hero__title" id="ce-new-year-welcome-title"><?php echo esc_html( $ce_heading ); ?></h2>
				<p class="ce-new-year-hero__copy" id="ce-new-year-welcome-desc"><?php echo esc_html( $ce_copy ); ?></p>
				<div class="ce-new-year-hero__actions">
					<a class="btn btn-primary ce-new-year-hero__cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
						<?php echo esc_html( $ce_cta ); ?>
					</a>
				</div>
			</div>
		</div>
	</dialog>
	<div class="ce-new-year-welcome__fallback" data-ce-welcome-fallback>
		<p class="ce-new-year-welcome__fallback-title"><?php echo esc_html( $ce_heading ); ?></p>
		<a class="btn btn-primary ce-new-year-welcome__fallback-cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
			<?php echo esc_html( $ce_cta ); ?>
		</a>
		<button
			type="button"
			class="ce-new-year-welcome__preview-trigger"
			data-ce-welcome-preview-open
			hidden
		>
			<?php
			/* translators: preview-only trigger label. */
			echo esc_html( __( 'Open the welcome preview', 'conexao-br-irlanda' ) );
			?>
		</button>
	</div>
</div>
