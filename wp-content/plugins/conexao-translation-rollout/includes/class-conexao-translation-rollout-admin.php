<?php
/**
 * DEPRECATED rollout admin screen (Tools -> Translation Rollouts).
 *
 * ## Stage 8: this endpoint is CLOSED
 *
 * This class used to be a second, apply-capable production mutation entry
 * point. Its call graph was:
 *
 *     admin-post.php  ->  handle_run()
 *       -> manage_options + check_admin_referer()
 *       -> $_POST['mode'] === 'apply'   (CALLER-CONTROLLED)
 *       -> call_user_func( $config['run_callback'], [ 'dry_run' => false ] )
 *       -> Conexao_Translation_Rollout_Engine::run( ..., [ 'dry_run' => false ] )
 *       -> apply_plan()   ->   REAL WRITES
 *
 * It bypassed every control the automation program established: the Stage 0
 * stage allowlist, the Stage 2 F7 "PASS dry run before apply" gate, the
 * digest-bound approval, the concurrency lock, the audit trail, the
 * environment guard, the Stage 3 change detector, provider validation and
 * human review. A capability and a nonce were the whole authorisation story.
 *
 * The disposition is HARD FAILURE, not redirection (Stage 8 §16). There is no
 * compatibility concern to preserve: the only two plugins that register stages
 * with the engine (`conexao-en-translation`, `conexao-job-translation`) are
 * both `production: false` in `plugins.json`, so in the production steady
 * state NO stage is registered and `handle_run()` could only ever have reached
 * `unknown stage`. The bypass was latent, not load-bearing -- which is exactly
 * why it had to be closed rather than left merely declared.
 *
 * ## Why the hook is still registered at all
 *
 * Removing the `add_action()` would make an old bookmarked URL fall through to
 * WordPress's generic "0 handlers" response, indistinguishable from a broken
 * install. Registering a handler that refuses loudly, by name, with a named
 * replacement, is the honest deprecation: an operator holding this URL is told
 * what happened and where to go instead.
 *
 * ## What is deliberately NOT here any more
 *
 * | Absent | Why |
 * |---|---|
 * | `call_user_func` on a stage `run_callback` | no code path from this file reaches `Engine::run()` at all |
 * | any `apply` / `remove` mode | there is no mode vocabulary left to map |
 * | a preview/dry-run path | a dry run writes nothing but still runs stage code; the replacement control plane owns that too |
 * | silent redirect to the new endpoint | a redirect would preserve the old POST contract as a live alias; a refusal cannot |
 *
 * ## Replacement
 *
 * `conexao-translation-automation` -> `admin_post_conexao_translation_automation_proof`
 * (Tools -> Translation Automation). It is the single production control plane:
 * authorised -> locked -> inventoried -> validated -> planned -> dry-run ->
 * PASS gate -> digest-bound approval -> snapshot -> apply -> verify.
 *
 * @package Conexao_Translation_Rollout
 */

defined( 'ABSPATH' ) || exit;

/**
 * The closed rollout admin screen.
 */
final class Conexao_Translation_Rollout_Admin {

	/**
	 * Capability required for every rollout action.
	 */
	const CAP = 'manage_options';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'conexao-translation-rollout';

	/**
	 * The admin-post.php action name this class USED to serve.
	 *
	 * Kept only so the refusal can name the deprecated endpoint, and so the
	 * Stage 8 structural gate can resolve this exact action name and assert it
	 * can no longer reach apply.
	 *
	 * @var string
	 */
	const ACTION = 'conexao_translation_rollout_run';

	/**
	 * The action that replaced it: the one approved production control plane.
	 *
	 * @var string
	 */
	const REPLACEMENT_ACTION = 'conexao_translation_automation_proof';

	/**
	 * Register the admin hooks. Registration performs no writes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	/**
	 * Add the Tools -> Translation Rollouts page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_management_page(
			'Translation Rollouts',
			'Translation Rollouts',
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Refuse every request to the deprecated endpoint.
	 *
	 * There is no branch here that reaches the engine, a stage callback or a
	 * write. The authorisation check is kept so an anonymous or unprivileged
	 * caller learns nothing about the deprecation, so the refusal is not a
	 * public oracle; the refusal itself is unconditional for anyone who clears
	 * it.
	 *
	 * The strings are plain escaped text rather than gettext calls. This plugin
	 * intentionally ships no translatable runtime strings (see
	 * `languages/README.md`): it is English-only operational tooling, exactly
	 * like `conexao-translation-automation`, which uses escaped output rather
	 * than a text domain throughout.
	 *
	 * (Naming the gettext functions is deliberately avoided in this comment: the
	 * i18n-freshness gate scans source text for those call names, so merely
	 * NAMING one in a docblock makes it think this component has translatable
	 * strings and demands a catalogue the policy says must not exist.)
	 *
	 * @return void
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}

		wp_die(
			esc_html(
				sprintf(
					'The %1$s endpoint was retired: it could apply EN translations without the review, approval and audit controls the automation program requires. Use %2$s (Tools -> Translation Automation) instead.',
					self::ACTION,
					self::REPLACEMENT_ACTION
				)
			),
			'Endpoint retired',
			array( 'response' => 410 )
		);
	}

	/**
	 * Render the screen. Read-only, and it starts no run.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>Translation Rollouts (deprecated)</h1>';
		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html( 'This screen no longer runs rollouts.' );
		echo '</strong> ';
		printf(
			/* translators: %s: deprecated action name. */
			esc_html(
				sprintf(
					'The %s endpoint was retired because it could apply EN translations without a preceding PASS dry run, a digest-bound approval, a lock or an audit record.',
					self::ACTION
				)
			)
		);
		echo '</p><p>';
		printf(
			/* translators: %s: replacement action name. */
			esc_html(
				sprintf(
					'Use Tools -> Translation Automation (%s) instead. It is the only supported production entry point, and it performs no writes at all.',
					self::REPLACEMENT_ACTION
				)
			)
		);
		echo '</p></div></div>';
	}
}
