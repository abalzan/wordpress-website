<?php
/**
 * Christmas package — homepage-hero content ("One Christmas, Two Skies").
 *
 * "One Christmas, Two Skies": the supplied GPT Images panorama (Brazilian summer
 * evening on the left, Irish winter village on the right, one shared star between
 * them) carries the visual message; ALL copy stays real, localised HTML beside it.
 *
 * THE HOMEPAGE WELCOME. This one node is both the seasonal band and the welcome
 * dialog, so there is exactly one source of the headline, the paragraph, the star,
 * the artwork and the call to action:
 *
 *   - the hero is rendered ONCE inside a native <dialog> that ships `open`, so a
 *     visitor without JavaScript — or with a package script that never ran — reads
 *     the whole hero inline in its usual place;
 *   - `assets/js/presentations/christmas/christmas.js` → `createWelcome()` calls
 *     `showModal()` on the first eligible homepage view of a tab session, which
 *     gives the browser's own top layer, background inertness and focus
 *     containment without a hand-built focus trap;
 *   - dismissal is capped to the TAB SESSION (`sessionStorage`, every access
 *     guarded, with an in-memory fallback) and never persisted beyond it: no
 *     cookie, option, post meta, account or remote storage;
 *   - after dismissal the dialog returns to its inline band, so the height the
 *     theme reserves on `.hero-section` stays truthful and the visitor keeps the
 *     seasonal content instead of a 300 px hole;
 *   - the signed private preview renders the band INLINE (see the preview
 *     attribute below): the non-dismissible preview banner must stay visible and
 *     can never be covered by a modal top layer. Preview authorisation is not
 *     touched by this template.
 *
 * The inline guard script below runs at parse time, before this dialog is laid
 * out, and is a CAPABILITY TEST (does the browser really implement `showModal()`?),
 * never a decision: no date, no event window and no dismissal rule is evaluated
 * in JavaScript. If the package script never claims the node, the same guard's
 * DOMContentLoaded fallback reveals the band inline again.
 *
 * Layout contract: the art and the copy are two siblings, never layered, so no
 * sentence is ever placed on top of the photograph (contrast is guaranteed by
 * construction and survives both site themes). The art keeps its intrinsic 16:9
 * box, so both seasonal worlds stay visible at every width instead of one being
 * cropped away on a phone — in the band and in the dialog.
 *
 * One CTA only (r10 §7.12 / brief §5.1): the event record may override it; otherwise
 * it leads to this package's own landing page, and to the shared calendar only when
 * that page cannot be resolved.
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

$ce_heading = '' !== $ce_event->heading ? $ce_event->heading : ( $ce_english ? __( 'One Christmas, two skies', 'conexao-br-irlanda' ) : __( 'Um Natal, dois céus', 'conexao-br-irlanda' ) );

$ce_copy = '' !== $ce_event->subheading
	? $ce_event->subheading
	: ( $ce_english
		? __( 'In Brazil the light stays late and the evening arrives slowly; in Ireland the dark comes early and the windows light up. In between, the same star — and the same wish to be close to the people who matter.', 'conexao-br-irlanda' )
		: __( 'No Brasil, a luz fica até tarde e a noite chega devagar; na Irlanda, escurece cedo e são as janelas que acendem. Entre os dois, a mesma estrela — e a mesma vontade de estar perto de quem importa.', 'conexao-br-irlanda' ) );

$ce_landing = function_exists( 'conexao_commemorative_resolve_record' )
	? conexao_commemorative_resolve_record( 'page', $ce_english ? 'christmas' : 'natal' )
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
		? ( $ce_english ? __( 'Explore both skies', 'conexao-br-irlanda' ) : __( 'Explorar os dois céus', 'conexao-br-irlanda' ) )
		: ( $ce_english ? __( 'See the Christmas calendar', 'conexao-br-irlanda' ) : __( 'Ver a agenda de Natal', 'conexao-br-irlanda' ) ) );

$ce_art_uri = get_template_directory_uri() . '/assets/images/presentations/christmas/two-skies/';

$ce_art_alt = $ce_english
	? __( 'One lit Christmas star above a summer beach in Brazil, on the left, and a snow-covered Irish village with warm windows and a castle on a hill, on the right.', 'conexao-br-irlanda' )
	: __( 'Uma estrela de Natal acesa sobre uma praia de verão no Brasil, à esquerda, e uma vila irlandesa coberta de neve, com janelas iluminadas e um castelo no alto, à direita.', 'conexao-br-irlanda' );

$ce_kicker = $ce_english ? __( 'Christmas · Brazil and Ireland', 'conexao-br-irlanda' ) : __( 'Natal · Brasil e Irlanda', 'conexao-br-irlanda' );

// The close control names what it closes, so the visible label is also the
// accessible name in both languages (WCAG 2.4.6 / 2.5.3).
$ce_close_label = $ce_english ? __( 'Close', 'conexao-br-irlanda' ) : __( 'Fechar', 'conexao-br-irlanda' );

$ce_is_preview = function_exists( 'conexao_commemorative_is_preview' ) && conexao_commemorative_is_preview();
?>
<div class="ce-xmas-welcome" data-ce-welcome<?php echo $ce_is_preview ? ' data-ce-welcome-preview="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute, no user data. ?>>
	<script>
	/*
	 * Progressive-enhancement guard. Parse-time only: it asks the browser whether a
	 * modal dialog is really available and, if so, claims the band so the visitor
	 * never sees it painted inline and then jump into the dialog. If the package
	 * script never takes the claim, the DOMContentLoaded fallback puts the band back
	 * inline. Nothing is stored, calculated or decided here.
	 */
	(function () {
		try {
			var html = document.documentElement;
			var probe = document.createElement('dialog');

			if (!probe || typeof probe.showModal !== 'function') {
				return;
			}

			html.setAttribute('data-ce-welcome-state', 'pending');

			var revealInline = function () {
				if ('pending' === html.getAttribute('data-ce-welcome-state')) {
					html.setAttribute('data-ce-welcome-state', 'inline');
				}
			};

			if ('loading' === document.readyState) {
				document.addEventListener('DOMContentLoaded', revealInline);
			} else {
				revealInline();
			}
		} catch (error) {
			/* No dialog support: the band stays inline, exactly as rendered. */
		}
	}());
	</script>
	<dialog
		class="ce-xmas-welcome__dialog"
		id="ce-christmas-welcome-dialog"
		data-ce-welcome-dialog
		open
		aria-labelledby="ce-christmas-welcome-title"
		aria-describedby="ce-christmas-welcome-desc"
	>
		<?php
		/*
		 * Fail closed (r10 §7.8): the close control ships `hidden` and is revealed only
		 * by the controller that can actually close a modal. A visitor without
		 * JavaScript never meets a button that cannot work.
		 */
		?>
		<button
			type="button"
			class="ce-xmas-welcome__close"
			id="ce-christmas-welcome-close"
			data-ce-welcome-close
			hidden
		>
			<span class="ce-xmas-welcome__close-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false"><path d="M6 6l12 12M18 6L6 18"></path></svg>
			</span>
			<span class="ce-xmas-welcome__close-label"><?php echo esc_html( $ce_close_label ); ?></span>
		</button>
		<div class="ce-christmas-hero ce-hero">
			<figure class="ce-christmas-hero__art">
				<img
					src="<?php echo esc_url( $ce_art_uri . 'two-skies-hero-1200.webp' ); ?>"
					srcset="<?php echo esc_url( $ce_art_uri . 'two-skies-hero-840.webp' ); ?> 840w, <?php echo esc_url( $ce_art_uri . 'two-skies-hero-1200.webp' ); ?> 1200w"
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
			<div class="ce-christmas-hero__body">
				<p class="ce-christmas-hero__kicker">
					<span class="ce-christmas-hero__star" aria-hidden="true">
						<?php
						get_template_part(
							'template-parts/commemorative/christmas/star',
							null,
							array(
								'id'    => 'hero',
								'class' => 'ce-christmas-hero__star-svg',
							)
						);
						?>
					</span>
					<?php echo esc_html( $ce_kicker ); ?>
				</p>
				<h2 class="ce-christmas-hero__title" id="ce-christmas-welcome-title"><?php echo esc_html( $ce_heading ); ?></h2>
				<p class="ce-christmas-hero__copy" id="ce-christmas-welcome-desc"><?php echo esc_html( $ce_copy ); ?></p>
				<div class="ce-christmas-hero__actions">
					<a class="btn btn-primary ce-christmas-hero__cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
						<?php echo esc_html( $ce_cta ); ?>
					</a>
				</div>
			</div>
		</div>
	</dialog>
</div>
