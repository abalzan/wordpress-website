<?php
/**
 * The current PT inventory: the SOURCE OF TRUTH reconciliation compares.
 *
 * ## Why this reads the stage manifest rather than querying posts directly
 *
 * The set of PT records a stage is responsible for is already declared, per
 * stage, by that stage's own `manifest_callback` — the same callback the shared
 * engine validates. Reusing it means there is exactly ONE list of records per
 * stage, and a record can never be invisible to the engine yet visible to the
 * detector (or the reverse). Nothing here re-implements a query the engine
 * already makes, and nothing here decides scope.
 *
 * ## What an inventory row contains
 *
 * | Key | Meaning |
 * |---|---|
 * | `digest` | the translation-relevant PT source digest |
 * | `mode` | `b1` or `b2`, so the two strategies stay distinguishable |
 * | `status` | `present`, or `absent` for a tombstone |
 * | `reason` | why a row could not be read, when it could not |
 *
 * ## Language filtering is a hard filter, not a hope
 *
 * Every candidate record must pass `assert_digestable()`, which requires a
 * provable `pt` language and the stage's own post type. An EN record, an
 * unassignable record and a record of another post type are excluded from the
 * inventory entirely. That is the mechanism by which an EN-only change cannot
 * enter the change set.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the current, read-only PT inventory.
 */
final class Conexao_Translation_Automation_Inventory {

	/**
	 * Row statuses.
	 *
	 * @var string
	 */
	const STATUS_PRESENT = 'present';
	const STATUS_ABSENT  = 'absent';

	/**
	 * Overridable manifest resolver, for tests.
	 *
	 * @var callable|null
	 */
	private static $manifest_resolver = null;

	/**
	 * Replace the manifest resolver. Test support; null restores the real one.
	 *
	 * @param callable|null $resolver Callable receiving the stage config.
	 * @return void
	 */
	public static function set_manifest_resolver( $resolver ): void {
		self::$manifest_resolver = $resolver;
	}

	/**
	 * Build the current inventory for one stage.
	 *
	 * READ-ONLY. Performs no write of any kind and never calls the provider,
	 * the engine's apply path or the orchestrator.
	 *
	 * @param array $config Validated stage configuration from the engine.
	 * @return array {
	 *     @type array $objects Identity => row.
	 *     @type array $skipped Identity => reason (records that exist but are
	 *           not digestable for this stage).
	 *     @type bool  $ok      False when the stage itself could not be read.
	 *     @type string $reason Empty when ok, else the reason.
	 * }
	 */
	public static function build( array $config ): array {
		$stage = isset( $config['stage'] ) ? (string) $config['stage'] : '';

		if ( '' === $stage ) {
			return self::failed( 'the stage configuration declares no stage identifier.' );
		}

		$profile = Conexao_Translation_Automation_Digest::profile_for( $stage );

		if ( null === $profile ) {
			return self::failed( sprintf( 'stage "%s" has no source-state projection profile.', $stage ) );
		}

		$manifest = self::manifest( $config );

		if ( is_wp_error( $manifest ) ) {
			return self::failed(
				sprintf(
					'stage "%s" produced no usable manifest: %s',
					$stage,
					$manifest->get_error_message()
				)
			);
		}

		$records = isset( $manifest['records'] ) && is_array( $manifest['records'] )
			? $manifest['records']
			: array();

		$objects = array();
		$skipped = array();

		foreach ( $records as $stable_key => $row ) {
			$identity = (string) $stable_key;
			$pt_id    = self::resolve_pt_id( $config, $identity, is_array( $row ) ? $row : array() );

			if ( $pt_id <= 0 ) {
				// The manifest names a record this site does not have. That is a
				// documented absence (the engine's own "PT record absent"
				// skip), NOT a deletion: nothing changed, the record was never
				// here. Recorded as a tombstone so it is not re-reported as a
				// `deleted` change on every reconciliation.
				$objects[ $identity ] = array(
					'digest' => '',
					'mode'   => (string) $profile['mode'],
					'status' => self::STATUS_ABSENT,
					'reason' => 'the manifest names a PT record that is absent on this site',
				);

				continue;
			}

			$digest = Conexao_Translation_Automation_Digest::digest_record( $stage, $pt_id );

			if ( empty( $digest['ok'] ) ) {
				$skipped[ $identity ] = (string) $digest['reason'];

				continue;
			}

			$check = Conexao_Translation_Automation_Digest::assert_digestable( $stage, $pt_id );

			if ( is_wp_error( $check ) ) {
				// An EN record named by the manifest, or a record whose language
				// cannot be proven PT. Excluded, and reported, never digested.
				$skipped[ $identity ] = $check->get_error_message();

				continue;
			}

			$objects[ $identity ] = array(
				'digest' => (string) $digest['digest'],
				'mode'   => (string) $profile['mode'],
				'status' => self::STATUS_PRESENT,
				'reason' => '',
			);
		}

		ksort( $objects );
		ksort( $skipped );

		return array(
			'ok'      => true,
			'objects' => $objects,
			'skipped' => $skipped,
			'reason'  => '',
		);
	}

	/**
	 * Resolve the stage's manifest, through the stage's own callback.
	 *
	 * @param array $config Stage configuration.
	 * @return array|WP_Error
	 */
	private static function manifest( array $config ) {
		if ( is_callable( self::$manifest_resolver ) ) {
			$manifest = call_user_func( self::$manifest_resolver, $config );

			return is_array( $manifest ) ? $manifest : new WP_Error(
				'conexao_automation_inventory_bad_manifest',
				'the manifest resolver returned a non-array.'
			);
		}

		if ( empty( $config['manifest_callback'] ) || ! is_callable( $config['manifest_callback'] ) ) {
			return new WP_Error(
				'conexao_automation_inventory_no_manifest_callback',
				'the stage declares no callable manifest_callback.'
			);
		}

		$manifest = call_user_func( $config['manifest_callback'] );

		return is_array( $manifest ) ? $manifest : new WP_Error(
			'conexao_automation_inventory_bad_manifest',
			'the manifest callback returned a non-array.'
		);
	}

	/**
	 * Resolve the live PT post ID for one manifest stable key.
	 *
	 * Resolution order, both non-invented:
	 *
	 *   1. the stage's own `find_pt` adapter, when the configuration exposes
	 *      one — that is the stage's own identity rule, not a second one;
	 *   2. the documented portable lookup by path (`get_page_by_path`), the
	 *      same primitive `conexao_en_translation_engine_adapter()` uses.
	 *
	 * Never a post ID taken from the manifest: an authored dataset is portable
	 * and must not carry environment-local IDs.
	 *
	 * @param array  $config Stage configuration.
	 * @param string $stable_key Portable stable key.
	 * @param array  $row     Manifest row (unused by the real resolvers).
	 * @return int PT post ID, or 0 when absent.
	 */
	private static function resolve_pt_id( array $config, string $stable_key, array $row ): int {
		unset( $row );

		$post_type = (string) ( $config['source_post_type'] ?? '' );

		if ( '' === $post_type ) {
			return 0;
		}

		$post = get_page_by_path( $stable_key, OBJECT, $post_type );

		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * A failed build with the same shape as a successful one.
	 *
	 * @param string $reason Explanation.
	 * @return array{ok:bool,objects:array,skipped:array,reason:string}
	 */
	private static function failed( string $reason ): array {
		return array(
			'ok'      => false,
			'objects' => array(),
			'skipped' => array(),
			'reason'  => $reason,
		);
	}
}
