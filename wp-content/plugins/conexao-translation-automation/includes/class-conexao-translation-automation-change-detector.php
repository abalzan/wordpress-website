<?php
/**
 * The change-detection contract (Stage 3 control B5).
 *
 * ## Inventory is the source of truth
 *
 * The algorithm is exactly:
 *
 *     current inventory
 *       -> compare with persisted source state
 *       -> deterministic change set
 *
 * A hook can only mark `reconciliation_needed`. It can never contribute a row
 * to the change set, because a change is only ever *derived* by comparing two
 * inventories. That is what makes a MISSED hook harmless: the next
 * reconciliation rebuilds the whole current inventory and the change appears
 * anyway. It is also what makes a DUPLICATE hook harmless: a second
 * reconciliation of an unchanged inventory produces an empty change set.
 *
 * ## The change types, and what each one means
 *
 * | Change | Detected as | Translation effect | Mutation here |
 * |---|---|---|---|
 * | `new` | in current, not in persisted | a translation would be created | none |
 * | `modified` | in both, digest differs | existing EN would be updated | none |
 * | `restored` | in both, previously marked absent | existing EN re-checked | none |
 * | `deleted` | in persisted, not in current | **manual intervention** | none |
 * | `unchanged` | in both, digest identical | nothing | none |
 * | `invalid` | a record cannot be digested | **manual intervention** | none |
 *
 * ## Why `deleted` is NEVER a speculative EN removal
 *
 * The repository has no safe automatic policy for a PT deletion. `allow_remove`
 * exists on every stage, but the engine's `remove` mode is a documented
 * OPERATOR rollback, it is reached only through an explicit `mode => remove`
 * argument, and Stage 0 control S8 forbids the automated path from defaulting
 * to it. Deleting an EN record because its PT source disappeared could destroy
 * published English content that a human authored, and would do so with no
 * dry-run, no gate and no snapshot. So `deleted` is reported as
 * `manual_intervention_required` and the detector performs **no mutation at
 * all**. Inventing a deletion policy here would be exactly the speculation the
 * brief forbids.
 *
 * ## B1 and B2 stay distinct
 *
 * The detector never decides *how* to translate; the engine does. What it must
 * not do is misread a B2 field change as a B1 object creation. It cannot,
 * because:
 *
 *   - the change identity is the stage plus the PT stable key, so a B2 record
 *     is keyed `en-leisure-description:<slug>` and a B1 record
 *     `en-guide:<slug>` — they are different objects in different maps;
 *   - the recorded `mode` (`b1`/`b2`) travels with every row, so a consumer
 *     can tell which strategy a change belongs to without re-deriving it;
 *   - the digest for a B2 record is taken over `post_excerpt`, the exact field
 *     the B2 stage writes as its English, so a B2 edit changes the digest and
 *     is classified `modified` — never `new`, and never an object creation.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The reconciliation between the current inventory and the persisted state.
 */
final class Conexao_Translation_Automation_Change_Detector {

	/**
	 * Change types.
	 *
	 * @var string
	 */
	const CHANGE_NEW       = 'new';
	const CHANGE_MODIFIED  = 'modified';
	const CHANGE_RESTORED  = 'restored';
	const CHANGE_DELETED   = 'deleted';
	const CHANGE_UNCHANGED = 'unchanged';
	const CHANGE_INVALID   = 'invalid';

	/**
	 * Dispositions a change may carry.
	 *
	 * @var string
	 */
	const DISPOSITION_TRANSLATE = 'translation_operation';
	const DISPOSITION_UPDATE    = 'update_existing_en';
	const DISPOSITION_NONE      = 'audit_only';
	const DISPOSITION_MANUAL    = 'manual_intervention_required';

	/**
	 * Reconcile a current inventory against the persisted source state.
	 *
	 * PURE. Reads nothing and writes nothing: it is handed both inventories and
	 * returns a change set. That is what lets a test assert determinism, and it
	 * is why no code path here can reach WordPress content.
	 *
	 * @param array $current   Current inventory: stage => identity => digest.
	 * @param array $persisted Persisted inventory: stage => identity => entry.
	 * @return array{changes:array,counts:array,digest:string}
	 */
	public static function reconcile( array $current, array $persisted ): array {
		$changes = array();
		$counts  = array(
			self::CHANGE_NEW       => 0,
			self::CHANGE_MODIFIED  => 0,
			self::CHANGE_RESTORED  => 0,
			self::CHANGE_DELETED   => 0,
			self::CHANGE_UNCHANGED => 0,
			self::CHANGE_INVALID   => 0,
		);

		foreach ( $current as $stage => $objects ) {
			$stage   = (string) $stage;
			$profile = Conexao_Translation_Automation_Digest::profile_for( $stage );
			$known   = isset( $persisted[ $stage ] ) && is_array( $persisted[ $stage ] )
				? $persisted[ $stage ]
				: array();

			foreach ( (array) $objects as $identity => $entry ) {
				$identity = (string) $identity;
				$digest   = is_array( $entry ) ? (string) ( $entry['digest'] ?? '' ) : (string) $entry;

				// A row the CURRENT inventory reports as absent is a documented
				// absence (the manifest names a record this site does not have),
				// not a change and not a corruption. It is recorded and skipped,
				// so a missing record never masquerades as a new translation.
				if ( is_array( $entry ) && isset( $entry['status'] ) && 'absent' === (string) $entry['status'] ) {
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_UNCHANGED,
						'',
						'',
						self::DISPOSITION_NONE,
						'the manifest names a PT record that is absent on this site'
					);
					++$counts[ self::CHANGE_UNCHANGED ];

					continue;
				}

				// A current row that cannot even name its own digest is
				// UNSUPPORTED. It is reported for a human, never guessed at.
				if ( null === $profile || 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_INVALID,
						'',
						$digest,
						self::DISPOSITION_MANUAL,
						null === $profile
							? sprintf( 'stage "%s" has no projection profile', $stage )
							: 'the current row carries an impossible digest'
					);
					++$counts[ self::CHANGE_INVALID ];

					continue;
				}

				$before = isset( $known[ $identity ] ) && is_array( $known[ $identity ] )
					? (string) ( $known[ $identity ]['digest'] ?? '' )
					: null;

				if ( null === $before ) {
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_NEW,
						'',
						$digest,
						self::DISPOSITION_TRANSLATE,
						'no accepted baseline for this identity'
					);
					++$counts[ self::CHANGE_NEW ];

					continue;
				}

				$was_absent = isset( $known[ $identity ]['status'] )
					&& 'absent' === (string) $known[ $identity ]['status'];

				// A RESTORED identity is classified on its LIFECYCLE, before the
				// digest comparison runs. A record that was tombstoned and has
				// come back is a restoration even when its content is
				// byte-identical to the baseline: the record itself was absent
				// at the last reconciliation, so an EN record may need to be
				// re-checked or re-linked. Checking the digest first would
				// report "unchanged" and silently swallow the restoration.
				if ( $was_absent ) {
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_RESTORED,
						$before,
						$digest,
						self::DISPOSITION_UPDATE,
						'the identity was previously recorded as absent and has returned'
					);
					++$counts[ self::CHANGE_RESTORED ];

					continue;
				}

				if ( $before === $digest ) {
					// UNCHANGED rows are recorded, not emitted as work. They are
					// what proves a reconciliation actually LOOKED at the
					// record instead of skipping it.
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_UNCHANGED,
						$before,
						$digest,
						self::DISPOSITION_NONE,
						''
					);
					++$counts[ self::CHANGE_UNCHANGED ];

					continue;
				}

				$changes[] = self::row(
					$stage,
					$identity,
					self::CHANGE_MODIFIED,
					$before,
					$digest,
					self::DISPOSITION_UPDATE,
					'a translation-relevant PT field changed'
				);
				++$counts[ self::CHANGE_MODIFIED ];
			}
		}

		// Whatever the current inventory no longer contains. A tombstoned
		// identity that is still absent is reported `unchanged`, not
		// `deleted`, so a deletion is reported ONCE rather than on every
		// subsequent reconciliation.
		foreach ( $persisted as $stage => $objects ) {
			$stage = (string) $stage;

			foreach ( (array) $objects as $identity => $entry ) {
				$identity = (string) $identity;

				if ( isset( $current[ $stage ][ $identity ] ) ) {
					continue;
				}

				if ( is_array( $entry ) && isset( $entry['status'] ) && 'absent' === (string) $entry['status'] ) {
					$changes[] = self::row(
						$stage,
						$identity,
						self::CHANGE_UNCHANGED,
						(string) ( $entry['digest'] ?? '' ),
						'',
						self::DISPOSITION_NONE,
						'already recorded as absent'
					);
					++$counts[ self::CHANGE_UNCHANGED ];

					continue;
				}

				$changes[] = self::row(
					$stage,
					$identity,
					self::CHANGE_DELETED,
					is_array( $entry ) ? (string) ( $entry['digest'] ?? '' ) : '',
					'',
					self::DISPOSITION_MANUAL,
					'the PT source is absent from the current inventory; no safe automatic deletion policy exists'
				);
				++$counts[ self::CHANGE_DELETED ];
			}
		}

		// Deterministic ordering, so the change-set digest depends only on
		// CONTENT and never on the order the inventory happened to be built in.
		usort(
			$changes,
			static function ( array $a, array $b ): int {
				return array( $a['stage'], $a['identity'], $a['change'] )
					<=> array( $b['stage'], $b['identity'], $b['change'] );
			}
		);

		return array(
			'changes' => $changes,
			'counts'  => $counts,
			'digest'  => Conexao_Translation_Automation_Digest::digest( $changes ),
		);
	}

	/**
	 * Build one change row.
	 *
	 * @param string $stage       Stage identifier.
	 * @param string $identity    Portable PT stable key.
	 * @param string $change      One of the CHANGE_* constants.
	 * @param string $before      Previous digest, or ''.
	 * @param string $after       Current digest, or ''.
	 * @param string $disposition One of the DISPOSITION_* constants.
	 * @param string $reason      Explanation.
	 * @return array<string,string>
	 */
	private static function row( string $stage, string $identity, string $change, string $before, string $after, string $disposition, string $reason ): array {
		$profile = Conexao_Translation_Automation_Digest::profile_for( $stage );

		return array(
			'stage'       => $stage,
			'identity'    => $identity,
			// The B1/B2 strategy travels WITH the row, so a consumer never has
			// to re-derive it and can never mistake a B2 field change for a B1
			// object creation.
			'mode'        => null === $profile ? '' : (string) $profile['mode'],
			'change'      => $change,
			'before'      => $before,
			'after'       => $after,
			'disposition' => $disposition,
			'reason'      => $reason,
		);
	}

	/**
	 * How many rows in this change set would become translation work?
	 *
	 * Counts `translation_operation` and `update_existing_en` only. `deleted`
	 * and `invalid` are deliberately excluded: they require a human, so
	 * reporting them as "pending work" would be a false promise.
	 *
	 * @param array $change_set Output of `reconcile()`.
	 * @return int
	 */
	public static function actionable_count( array $change_set ): int {
		$count = 0;

		foreach ( (array) ( $change_set['changes'] ?? array() ) as $row ) {
			if ( in_array( (string) ( $row['disposition'] ?? '' ), array( self::DISPOSITION_TRANSLATE, self::DISPOSITION_UPDATE ), true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The rows that need a human decision.
	 *
	 * @param array $change_set Output of `reconcile()`.
	 * @return array
	 */
	public static function manual_rows( array $change_set ): array {
		$rows = array();

		foreach ( (array) ( $change_set['changes'] ?? array() ) as $row ) {
			if ( self::DISPOSITION_MANUAL === (string) ( $row['disposition'] ?? '' ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}
}
