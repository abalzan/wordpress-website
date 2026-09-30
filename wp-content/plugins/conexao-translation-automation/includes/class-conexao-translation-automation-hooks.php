<?php
/**
 * Hooks as WAKE-UP HINTS (Stage 3 control B5).
 *
 * ## The whole contract, in one sentence
 *
 * A hook may set one boolean. It may do nothing else.
 *
 * ## What a hook is allowed to do
 *
 * Register on the PT lifecycle hooks, filter down to records that could matter,
 * and call `mark()`. That is all. `mark()` writes a single boolean into one
 * option. Nothing in this file:
 *
 *   - generates a translation;
 *   - mutates an EN record, or any content at all;
 *   - calls the provider;
 *   - enters apply, or calls the orchestrator;
 *   - reads or bypasses the inventory;
 *   - touches the lock;
 *   - schedules anything.
 *
 * The reason is not caution, it is correctness: **inventory is the source of
 * truth**. A hook cannot know whether a change is translation-relevant, because
 * that judgement needs the field projection, the stage profile and the
 * comparison against the accepted baseline. So the hook does not try. It
 * records "reconciliation is worth attempting" and lets reconciliation decide,
 * which is the only component with enough information to be right.
 *
 * ## The two failure modes this design eliminates
 *
 * | Failure | Why it cannot happen here |
 * |---|---|
 * | a MISSED hook loses a PT change forever | impossible: the next reconciliation rebuilds the FULL current inventory and diffs it, so a change whose hook never fired is found anyway. The hook only makes the system *prompt*, never correct |
 * | a DUPLICATE or rapid hook causes duplicate work | impossible: `mark()` is idempotent (writing `true` over `true`), and reconciliation of an unchanged inventory yields an empty change set, so a second run does nothing |
 *
 * ## Language filtering
 *
 * A hook fires for EVERY save, including every EN save the automation itself
 * performs. A hook that marked on an EN save would be pure noise. Every handler
 * therefore requires a provable `pt` language before marking, so an EN-only
 * change is ignored at the source as well as in the diff.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wake-up hint layer.
 */
final class Conexao_Translation_Automation_Hooks {

	/**
	 * The option holding the wake-up marker.
	 *
	 * @var string
	 */
	const MARKER_OPTION = 'conexao_translation_automation_wakeup';

	/**
	 * Recorded reasons, for the audit record and for tests.
	 *
	 * @var string
	 */
	const REASON_POST_SAVED     = 'post_saved';
	const REASON_POST_DELETED   = 'post_deleted';
	const REASON_POST_TRASHED   = 'post_trashed';
	const REASON_POST_UNTRASHED = 'post_untrashed';
	const REASON_TERMS_CHANGED  = 'terms_changed';

	/**
	 * Whether the hooks are currently registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Overridable marker writer, for tests.
	 *
	 * @var callable|null
	 */
	private static $marker_writer = null;

	/**
	 * Replace the marker writer. Test support; null restores the real one.
	 *
	 * @param callable|null $writer Callable receiving (bool, reason).
	 * @return void
	 */
	public static function set_marker_writer( $writer ): void {
		self::$marker_writer = $writer;
	}

	/**
	 * Register the wake-up hooks.
	 *
	 * Idempotent: calling it twice registers nothing twice, so a duplicated
	 * plugin load cannot double-mark.
	 *
	 * @return bool True when this call performed the registration.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return false;
		}

		self::$registered = true;

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 10, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash_post' ), 10, 1 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 10, 4 );

		return true;
	}

	/**
	 * Remove the wake-up hooks. Test support and the operator escape hatch.
	 *
	 * @return void
	 */
	public static function unregister(): void {
		remove_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10 );
		remove_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10 );
		remove_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 10 );
		remove_action( 'untrashed_post', array( __CLASS__, 'on_untrash_post' ), 10 );
		remove_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 10 );

		self::$registered = false;
	}

	/**
	 * Are the hooks registered?
	 *
	 * @return bool
	 */
	public static function is_registered(): bool {
		return self::$registered;
	}

	/**
	 * `save_post`: the PT record was created or edited.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this was an update.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post = null, $update = false ): void {
		unset( $update );

		self::maybe_mark( (int) $post_id, self::REASON_POST_SAVED );
	}

	/**
	 * `before_delete_post`: the PT record is being deleted.
	 *
	 * `before_delete_post` rather than `deleted_post` because at that point the
	 * record still exists, so its post type and language can still be read. A
	 * hint raised after the row is gone could not be filtered correctly.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( $post_id ): void {
		self::maybe_mark( (int) $post_id, self::REASON_POST_DELETED );
	}

	/**
	 * `wp_trash_post`: the PT record moved to the trash.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_trash_post( $post_id ): void {
		self::maybe_mark( (int) $post_id, self::REASON_POST_TRASHED );
	}

	/**
	 * `untrashed_post`: a PT record came back out of the trash.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_untrash_post( $post_id ): void {
		self::maybe_mark( (int) $post_id, self::REASON_POST_UNTRASHED );
	}

	/**
	 * `set_object_terms`: a translated term assignment changed.
	 *
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms set.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy name.
	 * @return void
	 */
	public static function on_set_object_terms( $object_id, $terms = array(), $tt_ids = array(), $taxonomy = '' ): void {
		unset( $terms, $tt_ids );

		// Only the TRANSLATED taxonomies can change a translation: a shared
		// proper-name taxonomy is the same term in both languages, so changing
		// it changes no English. Which taxonomics those are is read from the
		// profiles, not restated here.
		$post_type = (string) get_post_type( (int) $object_id );
		$profiles  = Conexao_Translation_Automation_Digest::stage_profiles();
		$relevant  = false;

		foreach ( $profiles as $profile ) {
			if ( ! in_array( (string) $taxonomy, (array) $profile['taxonomies'], true ) ) {
				continue;
			}

			if ( (string) $post_type === (string) $profile['source_post_type'] ) {
				$relevant = true;

				break;
			}
		}

		if ( ! $relevant ) {
			return;
		}

		self::maybe_mark( (int) $object_id, self::REASON_TERMS_CHANGED );
	}

	/**
	 * Mark reconciliation as needed, if this record could matter.
	 *
	 * Two gates, both required: the post type must belong to some stage's
	 * projection, and the record must be provably `pt`.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  One of the REASON_* constants.
	 * @return bool True when the marker was written.
	 */
	public static function maybe_mark( int $post_id, string $reason ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			// A hard delete fires `before_delete_post` before the row is gone,
			// so an absent record here means this hook is not ours to answer for.
			return false;
		}

		$post_type = (string) $post->post_type;
		$profiles  = Conexao_Translation_Automation_Digest::stage_profiles();
		$watched   = false;

		foreach ( $profiles as $profile ) {
			if ( (string) $post_type === (string) $profile['source_post_type'] ) {
				$watched = true;

				break;
			}
		}

		if ( ! $watched ) {
			// An UNRELATED post type. Not a hint, and not an error.
			return false;
		}

		if ( 'pt' !== Conexao_Translation_Automation_Digest::record_language( $post_id ) ) {
			// An EN save — including one this program itself performed. The
			// automation must never wake itself up for its own EN output.
			return false;
		}

		return self::mark( $reason );
	}

	/**
	 * Record that reconciliation is worth attempting.
	 *
	 * The ONLY thing a hook may do. Idempotent: marking again is a no-op, which
	 * is what makes a rapid or duplicate hook harmless. The marker carries a
	 * reason and a timestamp purely for the audit trail; neither is consulted to
	 * decide anything.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @return bool
	 */
	public static function mark( string $reason ): bool {
		if ( is_callable( self::$marker_writer ) ) {
			return (bool) call_user_func( self::$marker_writer, true, $reason );
		}

		$current = get_option( self::MARKER_OPTION, array() );

		if ( is_array( $current ) && ! empty( $current['needed'] ) ) {
			// Already marked. Writing again would only churn the row.
			return false;
		}

		// autoload = false. This is a wake-up marker, not a value any request
		// needs, and it must not join the per-request autoload set.
		update_option(
			self::MARKER_OPTION,
			array(
				'needed' => true,
				'reason' => (string) $reason,
				'at'     => gmdate( 'c' ),
			),
			false
		);

		return true;
	}

	/**
	 * Is reconciliation currently marked as needed?
	 *
	 * @return bool
	 */
	public static function is_marked(): bool {
		$marker = get_option( self::MARKER_OPTION, array() );

		return is_array( $marker ) && ! empty( $marker['needed'] );
	}

	/**
	 * The marker record, for the audit trail.
	 *
	 * @return array
	 */
	public static function marker(): array {
		$marker = get_option( self::MARKER_OPTION, array() );

		return is_array( $marker ) ? $marker : array();
	}

	/**
	 * Clear the marker. Called by reconciliation once it has looked.
	 *
	 * Clearing the marker does NOT clear the requirement to reconcile: the
	 * marker is a hint about *when to look*, never a record of *what changed*.
	 * If a change arrived without a hint, it is still found by the next full
	 * inventory diff.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::MARKER_OPTION );
	}
}
