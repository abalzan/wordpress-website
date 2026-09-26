<?php
/**
 * Shared translation-rollout engine.
 *
 * Implements the content-change contract for one-shot EN translation stages
 * (engineering standard, Stage H):
 *
 *   inventory -> manifest -> dry-run plan -> snapshot -> apply -> verify + gate
 *
 * plus rollback/remove for stages that declare it safe.
 *
 * Design:
 *   - pure logic (config validation, manifest validation, plan diff, snapshot
 *     compare, numeric gate) is static and takes plain arrays, so it is
 *     testable without WordPress or Polylang;
 *   - WordPress-bound primitives (create, link, copy, snapshot) are injected
 *     by the stage adapter, so the engine owns the orchestration and the stage
 *     owns only its field mapping;
 *   - the engine contains no translated copy and no stage record list;
 *   - the engine never writes on plugin bootstrap or activation;
 *   - dry-run performs zero writes by construction.
 *
 * Identity: the only portable identity is the authored stable key (the PT
 * slug). Local post IDs are reported, never described as portable identity;
 * slug and title matching are reported fallbacks with explicit counts.
 *
 * @package Conexao_Translation_Rollout
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared translation-rollout engine.
 */
final class Conexao_Translation_Rollout_Engine {

	/**
	 * Registered stage configurations, keyed by stage identifier.
	 *
	 * @var array<string,array>
	 */
	private static $stages = array();

	/**
	 * Register a stage configuration with the shared engine.
	 *
	 * Registration is a pure in-memory declaration: it performs zero writes and
	 * is never triggered by activation.
	 *
	 * @param array $config Stage configuration.
	 * @return true|WP_Error True on success, WP_Error describing the defect.
	 */
	public static function register_stage( array $config ) {
		$check = self::validate_config( $config );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		self::$stages[ (string) $config['stage'] ] = $config;

		return true;
	}

	/**
	 * Registered stage identifiers.
	 *
	 * @return array List of stage slugs.
	 */
	public static function registered_stages(): array {
		return array_keys( self::$stages );
	}

	/**
	 * Fetch a registered stage configuration.
	 *
	 * @param string $stage Stage identifier.
	 * @return array|null Stage configuration, or null when not registered.
	 */
	public static function get_stage( string $stage ) {
		return isset( self::$stages[ $stage ] ) ? self::$stages[ $stage ] : null;
	}

	/**
	 * Forget every registered stage. Test support only.
	 *
	 * @return void
	 */
	public static function reset_stages(): void {
		self::$stages = array();
	}

	/**
	 * Validate a stage configuration. Fails closed; performs zero writes.
	 *
	 * @param mixed $config Candidate configuration.
	 * @return true|WP_Error
	 */
	public static function validate_config( $config ) {
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'conexao_rollout_bad_config', 'Stage config must be an array.' );
		}

		foreach ( array( 'stage', 'source_post_type', 'source_lang', 'target_lang' ) as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) || '' === trim( $config[ $key ] ) ) {
				return new WP_Error(
					'conexao_rollout_bad_config',
					sprintf( 'Stage config is missing required string key "%s".', $key )
				);
			}
		}

		if ( ! preg_match( '/^[a-z0-9-]+$/', (string) $config['stage'] ) ) {
			return new WP_Error( 'conexao_rollout_bad_config', 'Stage identifier must match [a-z0-9-].' );
		}

		$required_callables = array( 'manifest_callback', 'snapshot_callback', 'build_en_args_callback', 'copy_fields_callback' );

		foreach ( $required_callables as $key ) {
			if ( ! isset( $config[ $key ] ) || ! is_callable( $config[ $key ] ) ) {
				return new WP_Error(
					'conexao_rollout_bad_config',
					sprintf( 'Stage config key "%s" must be callable.', $key )
				);
			}
		}

		foreach ( array( 'extra_gate_callback', 'verify_landing_callback', 'run_callback' ) as $key ) {
			if ( isset( $config[ $key ] ) && null !== $config[ $key ] && ! is_callable( $config[ $key ] ) ) {
				return new WP_Error(
					'conexao_rollout_bad_config',
					sprintf( 'Stage config key "%s" must be callable or omitted.', $key )
				);
			}
		}

		if ( isset( $config['allowlist'] ) && ! is_array( $config['allowlist'] ) ) {
			return new WP_Error( 'conexao_rollout_bad_config', 'Stage config key "allowlist" must be an array.' );
		}

		return true;
	}

	/**
	 * Validate a versioned stage data manifest. Fails closed with zero writes
	 * on any defect: missing key, language mismatch, malformed row, or a
	 * duplicate stable identifier.
	 *
	 * @param mixed $manifest Manifest payload.
	 * @param array $config     Stage configuration.
	 * @param array $required Required per-record keys.
	 * @return true|WP_Error
	 */
	public static function validate_manifest( $manifest, array $config, array $required = array( 'en_slug' ) ) {
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'conexao_rollout_bad_manifest', 'Manifest must be an array.' );
		}

		foreach ( array( 'source_lang', 'target_lang', 'records' ) as $key ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				return new WP_Error(
					'conexao_rollout_bad_manifest',
					sprintf( 'Manifest is missing required key "%s".', $key )
				);
			}
		}

		if ( (string) $manifest['source_lang'] !== (string) $config['source_lang'] ) {
			return new WP_Error( 'conexao_rollout_bad_manifest', 'Manifest source_lang does not match the stage config.' );
		}

		if ( (string) $manifest['target_lang'] !== (string) $config['target_lang'] ) {
			return new WP_Error( 'conexao_rollout_bad_manifest', 'Manifest target_lang does not match the stage config.' );
		}

		if ( ! is_array( $manifest['records'] ) || array() === $manifest['records'] ) {
			return new WP_Error( 'conexao_rollout_bad_manifest', 'Manifest records must be a non-empty array keyed by stable key.' );
		}

		$seen_slugs = array();

		foreach ( $manifest['records'] as $stable_key => $row ) {
			if ( ! is_string( $stable_key ) || '' === trim( $stable_key ) ) {
				return new WP_Error( 'conexao_rollout_bad_manifest', 'Manifest records must be keyed by non-empty stable key.' );
			}

			if ( ! is_array( $row ) ) {
				return new WP_Error(
					'conexao_rollout_bad_manifest',
					sprintf( 'Manifest row "%s" must be an array.', $stable_key )
				);
			}

			foreach ( $required as $req_key ) {
				if ( ! isset( $row[ $req_key ] ) || ! is_string( $row[ $req_key ] ) || '' === trim( (string) $row[ $req_key ] ) ) {
					return new WP_Error(
						'conexao_rollout_bad_manifest',
						sprintf( 'Manifest row "%s" is missing required key "%s".', $stable_key, $req_key )
					);
				}
			}

			$slug = trim( (string) $row['en_slug'] );

			if ( isset( $seen_slugs[ $slug ] ) ) {
				return new WP_Error(
					'conexao_rollout_bad_manifest',
					sprintf( 'Duplicate en_slug "%s" in rows "%s" and "%s" (zero writes).', $slug, $seen_slugs[ $slug ], $stable_key )
				);
			}

			$seen_slugs[ $slug ] = $stable_key;
		}

		return true;
	}

	/**
	 * Build the deterministic dry-run plan. Pure; performs zero writes.
	 *
	 * State row: pt_id, pt_status, en_id, en_status, pair_ok, en_slug_matches,
	 * slug_collision.
	 *
	 * @param array $manifest Validated manifest payload.
	 * @param array $states     Live state rows keyed by stable key.
	 * @return array Categories: create, update, skip, conflicts.
	 */
	public static function build_plan( array $manifest, array $states ): array {
		$plan = array(
			'create'    => array(),
			'update'    => array(),
			'skip'      => array(),
			'conflicts' => array(),
		);

		foreach ( $manifest['records'] as $stable_key => $row ) {
			$stable_key = (string) $stable_key;
			$state      = self::state_row( $states, $stable_key );

			$item = array(
				'stable_key' => $stable_key,
				'pt_id'      => (int) $state['pt_id'],
				'en_id'      => (int) $state['en_id'],
				'en_slug'    => isset( $row['en_slug'] ) ? (string) $row['en_slug'] : '',
			);

			if ( 0 === $item['pt_id'] ) {
				$item['reason'] = 'PT record absent in this site (documented exclusion)';
				$plan['skip'][] = $item;
				continue;
			}

			if ( 'publish' !== $state['pt_status'] ) {
				$item['reason'] = 'PT record not published (not user-facing)';
				$plan['skip'][] = $item;
				continue;
			}

			if ( $state['slug_collision'] ) {
				$item['reason']      = 'EN slug already used by an unlinked record (refusing to duplicate)';
				$plan['conflicts'][] = $item;
				continue;
			}

			if ( $item['en_id'] > 0 && $state['pair_ok'] && 'publish' === $state['en_status'] ) {
				if ( ! $state['en_slug_matches'] ) {
					$item['reason']   = 'EN linked and verified; slug drift repairable on apply';
					$plan['update'][] = $item;
					continue;
				}

				$item['reason'] = 'EN translation already linked and verified';
				$plan['skip'][] = $item;
				continue;
			}

			if ( $item['en_id'] > 0 ) {
				$item['reason']      = 'EN record exists but the pair link is broken';
				$plan['conflicts'][] = $item;
				continue;
			}

			$item['reason']   = 'no EN translation linked yet';
			$plan['create'][] = $item;
		}

		return $plan;
	}

	/**
	 * Default state row for a manifest entry with no live record.
	 *
	 * @param array  $states     State rows keyed by stable key.
	 * @param string $stable_key Stable key.
	 * @return array Normalised state row.
	 */
	private static function state_row( array $states, string $stable_key ): array {
		$defaults = array(
			'pt_id'           => 0,
			'pt_status'       => 'absent',
			'en_id'           => 0,
			'en_status'       => 'absent',
			'pair_ok'         => false,
			'en_slug_matches' => true,
			'slug_collision'  => false,
		);

		if ( ! isset( $states[ $stable_key ] ) || ! is_array( $states[ $stable_key ] ) ) {
			return $defaults;
		}

		return array_merge( $defaults, array_intersect_key( $states[ $stable_key ], $defaults ) );
	}

	/**
	 * Compare two snapshots and return the changed field paths. Pure.
	 *
	 * @param array $before Snapshot before.
	 * @param array $after   Snapshot after.
	 * @return array Changed field paths.
	 */
	public static function diff_snapshots( array $before, array $after ): array {
		$changed = array();
		$keys    = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		foreach ( $keys as $key ) {
			$b = array_key_exists( $key, $before ) ? $before[ $key ] : null;
			$a = array_key_exists( $key, $after ) ? $after[ $key ] : null;

			if ( is_array( $b ) && is_array( $a ) ) {
				foreach ( self::diff_snapshots( $b, $a ) as $path ) {
					$changed[] = $key . '.' . $path;
				}

				continue;
			}

			if ( $b !== $a ) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}

	/**
	 * Numeric completeness gate. Pure; performs zero writes.
	 *
	 * PASS requires missing_en, conflicts, pt_drift and extra_failures to be
	 * zero. A textual "looks good" result is never produced.
	 *
	 * @param array $args Gate counters.
	 * @return array Gate payload including a PASS|FAIL verdict.
	 */
	public static function calculate_gate( array $args ): array {
		$gate = array(
			'stage'              => isset( $args['stage'] ) ? (string) $args['stage'] : '',
			'eligible_public_pt' => isset( $args['eligible_public_pt'] ) ? (int) $args['eligible_public_pt'] : 0,
			'with_en'            => isset( $args['with_en'] ) ? (int) $args['with_en'] : 0,
			'missing_en'         => isset( $args['missing_en'] ) ? (int) $args['missing_en'] : 0,
			'conflicts'          => isset( $args['conflicts'] ) ? (int) $args['conflicts'] : 0,
			'pt_drift'           => isset( $args['pt_drift'] ) ? (int) $args['pt_drift'] : 0,
			'extra_failures'     => isset( $args['extra_failures'] ) ? (int) $args['extra_failures'] : 0,
		);

		$gate['gate'] = (
			0 === $gate['missing_en']
			&& 0 === $gate['conflicts']
			&& 0 === $gate['pt_drift']
			&& 0 === $gate['extra_failures']
		) ? 'PASS' : 'FAIL';

		return $gate;
	}

	/**
	 * Collect verification counters and the numeric gate.
	 *
	 * @param array $config     Stage configuration.
	 * @param array $adapter   WordPress-bound adapter.
	 * @param array $manifest Validated manifest payload.
	 * @param array $summary   Run summary (conflicts, PT drift).
	 * @return array Verify payload including the gate.
	 */
	public static function collect_verify( array $config, array $adapter, array $manifest, array $summary ): array {
		$eligible = 0;
		$with_en  = 0;

		foreach ( $manifest['records'] as $stable_key => $row ) {
			$stable_key = (string) $stable_key;
			$pt         = call_user_func( $adapter['find_pt'], $stable_key );

			if ( ! is_array( $pt ) || empty( $pt['id'] ) || 'publish' !== ( $pt['status'] ?? '' ) ) {
				continue;
			}

			++$eligible;

			$en = call_user_func( $adapter['find_en_for_pt'], (int) $pt['id'], isset( $row['en_slug'] ) ? (string) $row['en_slug'] : '' );

			if ( ! empty( $en['en_id'] ) && ! empty( $en['pair_ok'] ) && 'publish' === ( $en['en_status'] ?? '' ) ) {
				++$with_en;
			}
		}

		$missing = $eligible - $with_en;

		if ( $missing < 0 ) {
			$missing = 0;
		}

		$extra = 0;

		if ( ! empty( $config['extra_gate_callback'] ) && is_callable( $config['extra_gate_callback'] ) ) {
			$extra = (int) call_user_func( $config['extra_gate_callback'] );
		}

		$landing = array( 'status' => 'not-applicable' );

		if ( ! empty( $config['verify_landing_callback'] ) && is_callable( $config['verify_landing_callback'] ) ) {
			$landing = call_user_func( $config['verify_landing_callback'] );

			if ( ! is_array( $landing ) || 'verified' !== ( $landing['status'] ?? '' ) ) {
				++$extra;
			}
		}

		$allowlisted = isset( $config['allowlist'] ) && is_array( $config['allowlist'] ) ? count( $config['allowlist'] ) : 0;

		$gate = self::calculate_gate(
			array(
				'stage'              => (string) $config['stage'],
				'eligible_public_pt' => $eligible,
				'with_en'            => $with_en,
				'missing_en'         => $missing,
				'conflicts'          => (int) ( $summary['conflicts'] ?? 0 ),
				'pt_drift'           => (int) ( $summary['pt_changed'] ?? 0 ),
				'extra_failures'     => $extra,
			)
		);

		return array(
			'eligible_public_pt' => $eligible,
			'with_en'            => $with_en,
			'missing_en'         => $missing,
			'conflicts'          => (int) ( $summary['conflicts'] ?? 0 ),
			'pt_drift'           => (int) ( $summary['pt_changed'] ?? 0 ),
			'extra_failures'     => $extra,
			'allowlisted'        => $allowlisted,
			'landing'            => $landing,
			'gate'               => $gate,
		);
	}

	/**
	 * The adapter callables a mode requires.
	 *
	 * @param string $mode Run mode (run|remove).
	 * @return array Required adapter keys.
	 */
	private static function required_adapter_keys( string $mode ): array {
		$base = array( 'find_pt', 'find_en_for_pt', 'slug_collision', 'pair_ok' );

		if ( 'remove' === $mode ) {
			$base[] = 'remove_en';
		} else {
			$base[] = 'create_en';
			$base[] = 'repair_en';
			$base[] = 'link_pair';
		}

		return $base;
	}

	/**
	 * Inventory the live state for every manifest record (read-only).
	 *
	 * @param array $config   Stage configuration.
	 * @param array $adapter  WordPress-bound adapter.
	 * @param array $manifest Validated manifest payload.
	 * @param array $counters Match-strategy counters (by reference).
	 * @return array State rows keyed by stable key.
	 */
	private static function collect_states( array $config, array $adapter, array $manifest, array &$counters ): array {
		$states = array();

		foreach ( $manifest['records'] as $stable_key => $row ) {
			$stable_key = (string) $stable_key;
			$en_slug    = isset( $row['en_slug'] ) ? (string) $row['en_slug'] : '';
			$pt         = call_user_func( $adapter['find_pt'], $stable_key );

			if ( ! is_array( $pt ) || empty( $pt['id'] ) ) {
				$states[ $stable_key ] = self::state_row( array(), $stable_key );
				continue;
			}

			$pt_id                 = (int) $pt['id'];
			$en                    = call_user_func( $adapter['find_en_for_pt'], $pt_id, $en_slug );
			$states[ $stable_key ] = array(
				'pt_id'           => $pt_id,
				'pt_status'       => isset( $pt['status'] ) ? (string) $pt['status'] : 'unknown',
				'en_id'           => (int) ( $en['en_id'] ?? 0 ),
				'en_status'       => isset( $en['en_status'] ) ? (string) $en['en_status'] : 'absent',
				'pair_ok'         => ! empty( $en['pair_ok'] ),
				'en_slug_matches' => array_key_exists( 'en_slug_matches', $en ) ? (bool) $en['en_slug_matches'] : true,
				'slug_collision'  => (bool) call_user_func( $adapter['slug_collision'], $en_slug, $pt_id ),
			);

			// The authored stable key is the only portable identity, so every
			// resolved record counts as a stable-id match; slug/title fallbacks
			// stay at zero unless a stage explicitly reports one.
			++$counters['stable-id'];
		}

		return $states;
	}

	/**
	 * Snapshot every affected PT record (read-only capture).
	 *
	 * @param array    $manifest Validated manifest payload.
	 * @param array    $states   State rows keyed by stable key.
	 * @param callable $callback Snapshot callback.
	 * @return array Snapshots keyed by stable key.
	 */
	private static function capture_snapshots( array $manifest, array $states, callable $callback ): array {
		$snapshots = array();

		foreach ( $manifest['records'] as $stable_key => $row ) {
			$stable_key = (string) $stable_key;

			if ( empty( $states[ $stable_key ]['pt_id'] ) ) {
				continue;
			}

			$snapshots[ $stable_key ] = call_user_func( $callback, (int) $states[ $stable_key ]['pt_id'] );
		}

		return $snapshots;
	}

	/**
	 * Apply the create and update categories. Idempotent by construction:
	 * a record that is already linked and verified is never re-created.
	 *
	 * @param array $config   Stage configuration.
	 * @param array $adapter  WordPress-bound adapter.
	 * @param array $manifest Validated manifest payload.
	 * @param array $plan     Dry-run plan.
	 * @param array $summary  Run summary (by reference).
	 * @param array $rows     Result rows (by reference).
	 * @return void
	 */
	private static function apply_plan( array $config, array $adapter, array $manifest, array $plan, array &$summary, array &$rows ): void {
		$copy = $config['copy_fields_callback'];

		foreach ( $plan['create'] as $item ) {
			$stable_key = $item['stable_key'];
			$pt_id      = (int) $item['pt_id'];
			$row        = $manifest['records'][ $stable_key ];

			$en_id = call_user_func( $adapter['create_en'], $pt_id, $row );

			if ( is_wp_error( $en_id ) || (int) $en_id <= 0 ) {
				$rows[] = array_merge(
					$item,
					array(
						'action'  => 'error',
						'message' => is_wp_error( $en_id ) ? $en_id->get_error_message() : 'create failed',
					)
				);
				++$summary['errors'];
				continue;
			}

			$en_id = (int) $en_id;

			call_user_func( $adapter['link_pair'], $pt_id, $en_id );

			$copied = (int) call_user_func( $copy, $pt_id, $en_id, $row );

			$summary['meta_copied'] += $copied;

			if ( ! call_user_func( $adapter['pair_ok'], $pt_id, $en_id ) ) {
				$rows[] = array_merge(
					$item,
					array(
						'en_id'   => $en_id,
						'action'  => 'error',
						'message' => 'translation link verification failed',
					)
				);
				++$summary['errors'];
				continue;
			}

			$rows[] = array_merge(
				$item,
				array(
					'en_id'   => $en_id,
					'action'  => 'created',
					'message' => sprintf( 'EN record created and linked; %d field(s) copied', $copied ),
				)
			);
			++$summary['created'];
		}

		foreach ( $plan['update'] as $item ) {
			$stable_key = $item['stable_key'];
			$pt_id      = (int) $item['pt_id'];
			$en_id      = (int) $item['en_id'];
			$row        = $manifest['records'][ $stable_key ];

			if ( ! call_user_func( $adapter['repair_en'], $pt_id, $en_id, $row ) ) {
				$rows[] = array_merge(
					$item,
					array(
						'action'  => 'error',
						'message' => 'repair failed',
					)
				);
				++$summary['errors'];
				continue;
			}

			$copied = (int) call_user_func( $copy, $pt_id, $en_id, $row );

			$summary['meta_copied'] += $copied;

			$rows[] = array_merge(
				$item,
				array(
					'action'  => 'updated',
					'message' => 'EN record repaired to the authored manifest',
				)
			);
			++$summary['updated'];
		}
	}

	/**
	 * Rollback: delete the EN translation each manifest record owns. The PT
	 * originals are never touched, and the PT snapshot is re-compared after the
	 * removal exactly as it is after an apply.
	 *
	 * @param array $manifest Validated manifest payload.
	 * @param array $adapter   WordPress-bound adapter.
	 * @param array $states     State rows keyed by stable key.
	 * @param array $summary   Run summary (by reference).
	 * @param array $rows         Result rows (by reference).
	 * @return void
	 */
	private static function apply_removals( array $manifest, array $adapter, array $states, array &$summary, array &$rows ): void {
		foreach ( $states as $stable_key => $state ) {
			if ( empty( $state['en_id'] ) ) {
				continue;
			}

			$base = array(
				'stable_key' => (string) $stable_key,
				'pt_id'      => (int) $state['pt_id'],
				'en_id'      => (int) $state['en_id'],
				'en_slug'    => (string) $manifest['records'][ $stable_key ]['en_slug'],
			);

			if ( ! call_user_func( $adapter['remove_en'], (int) $state['en_id'] ) ) {
				$rows[] = array_merge(
					$base,
					array(
						'action'  => 'error',
						'message' => 'remove failed',
					)
				);
				++$summary['errors'];
				continue;
			}

			$rows[] = array_merge(
				$base,
				array(
					'action'  => 'removed',
					'message' => 'EN translation removed; PT untouched',
				)
			);
			++$summary['removed'];
		}
	}

	/**
	 * PT-drift gate: every snapshotted PT record must compare identical. The
	 * only permitted difference is a Polylang language backfill from empty to
	 * the source language on a record that had none.
	 *
	 * @param array    $states    State rows keyed by stable key.
	 * @param array    $snapshots Snapshots captured before the run.
	 * @param callable $callback  Snapshot callback.
	 * @param array    $summary   Run summary (by reference).
	 * @param array    $rows      Result rows (by reference).
	 * @return void
	 */
	private static function assert_no_pt_drift( array $states, array $snapshots, callable $callback, array &$summary, array &$rows ): void {
		foreach ( $snapshots as $stable_key => $before ) {
			if ( ! is_array( $before ) ) {
				continue;
			}

			$pt_id = ! empty( $states[ $stable_key ]['pt_id'] ) ? (int) $states[ $stable_key ]['pt_id'] : 0;

			if ( $pt_id <= 0 ) {
				continue;
			}

			$after = call_user_func( $callback, $pt_id );

			if ( '' === ( $before['language'] ?? 'x' ) && '' !== ( $after['language'] ?? '' ) ) {
				$before['language'] = $after['language'];
			}

			$drift = self::diff_snapshots( $before, is_array( $after ) ? $after : array() );

			if ( array() === $drift ) {
				continue;
			}

			++$summary['pt_changed'];
			++$summary['errors'];
			$rows[] = array(
				'stable_key' => (string) $stable_key,
				'pt_id'      => $pt_id,
				'en_id'      => 0,
				'en_slug'    => '',
				'action'     => 'error',
				'message'    => 'PT SOURCE CHANGED: ' . implode( ',', $drift ),
			);
		}
	}

	/**
	 * Run a stage end to end: inventory, manifest validation, dry-run plan,
	 * snapshot, apply, PT-drift gate, verify and numeric gate.
	 *
	 * With `dry_run => true` the apply path is never entered, so a dry-run
	 * performs zero writes by construction.
	 *
	 * @param array $config   Stage configuration.
	 * @param array $adapter WordPress-bound adapter.
	 * @param array $args       Run arguments (dry_run, mode).
	 * @return array|WP_Error Report array, or WP_Error before any write.
	 */
	public static function run( array $config, array $adapter, array $args = array() ) {
		$check = self::validate_config( $config );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$manifest = call_user_func( $config['manifest_callback'] );
		$mcheck   = self::validate_manifest( $manifest, $config );

		if ( is_wp_error( $mcheck ) ) {
			return $mcheck;
		}

		$mode    = isset( $args['mode'] ) ? (string) $args['mode'] : 'run';
		$dry_run = ! empty( $args['dry_run'] );

		foreach ( self::required_adapter_keys( $mode ) as $callback ) {
			if ( ! isset( $adapter[ $callback ] ) || ! is_callable( $adapter[ $callback ] ) ) {
				return new WP_Error(
					'conexao_rollout_bad_adapter',
					sprintf( 'Adapter is missing callable "%s" (zero writes).', $callback )
				);
			}
		}

		if ( 'remove' === $mode && empty( $config['allow_remove'] ) ) {
			return new WP_Error( 'conexao_rollout_no_remove', 'This stage does not support remove (zero writes).' );
		}

		$match   = array(
			'stable-id' => 0,
			'slug'      => 0,
			'title'     => 0,
		);
		$states  = self::collect_states( $config, $adapter, $manifest, $match );
		$plan    = self::build_plan( $manifest, $states );
		$summary = array(
			'stage'                  => (string) $config['stage'],
			'created'                => 0,
			'updated'                => 0,
			'skipped'                => 0,
			'removed'                => 0,
			'errors'                 => 0,
			'conflicts'              => count( $plan['conflicts'] ),
			'pt_changed'             => 0,
			'meta_copied'            => 0,
			'match'                  => $match,
			'fallback_slug_matches'  => 0,
			'fallback_title_matches' => 0,
		);
		$rows    = array();

		foreach ( $plan['skip'] as $item ) {
			$rows[] = array_merge(
				$item,
				array(
					'action'  => 'skipped',
					'message' => $item['reason'],
				)
			);
			++$summary['skipped'];
		}

		foreach ( $plan['conflicts'] as $item ) {
			$rows[] = array_merge(
				$item,
				array(
					'action'  => 'error',
					'message' => $item['reason'],
				)
			);
			++$summary['errors'];
		}

		$snapshot_fn = $config['snapshot_callback'];
		$snapshots   = self::capture_snapshots( $manifest, $states, $snapshot_fn );

		if ( $dry_run ) {
			foreach ( $plan['create'] as $item ) {
				$rows[] = array_merge(
					$item,
					array(
						'action'  => 'would-create',
						'message' => 'EN record would be created, linked and verified',
					)
				);
				++$summary['created'];
			}

			foreach ( $plan['update'] as $item ) {
				$rows[] = array_merge(
					$item,
					array(
						'action'  => 'would-update',
						'message' => 'EN record would be repaired to the authored manifest',
					)
				);
				++$summary['updated'];
			}

			if ( 'remove' === $mode ) {
				foreach ( $plan['skip'] as $item ) {
					if ( $item['en_id'] > 0 ) {
						++$summary['removed'];
					}
				}
			}

			$verify = self::collect_verify( $config, $adapter, $manifest, $summary );

			return array(
				'summary'  => $summary,
				'rows'     => $rows,
				'plan'     => $plan,
				'snapshot' => $snapshots,
				'verify'   => $verify,
				'gate'     => $verify['gate'],
			);
		}

		if ( 'remove' === $mode ) {
			self::apply_removals( $manifest, $adapter, $states, $summary, $rows );
		} else {
			self::apply_plan( $config, $adapter, $manifest, $plan, $summary, $rows );
		}

		self::assert_no_pt_drift( $states, $snapshots, $snapshot_fn, $summary, $rows );

		$verify = self::collect_verify( $config, $adapter, $manifest, $summary );

		return array(
			'summary'  => $summary,
			'rows'     => $rows,
			'plan'     => $plan,
			'snapshot' => $snapshots,
			'verify'   => $verify,
			'gate'     => $verify['gate'],
		);
	}
}
