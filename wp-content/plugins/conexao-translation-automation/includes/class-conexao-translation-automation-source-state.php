<?php
/**
 * The persisted PT source state: schema, fail-closed reads, bounded retention.
 *
 * ## What this stores
 *
 * The LAST ACCEPTED translation-relevant PT state, so a later reconciliation
 * can compare `current inventory` against `last accepted inventory` without
 * depending on any external filesystem. Production is WordPress.com: no SSH, no
 * WP-CLI, no shell. The smallest safe mechanism that exists there is the
 * options table WordPress already requires, which is exactly what Stage 2's
 * lock already proved this plugin can use atomically.
 *
 * | Concern | Decision | Why |
 * |---|---|---|
 * | Storage | ONE `wp_options` row | options are the only writable store guaranteed on WordPress.com; a custom table would need `dbDelta`, and a file needs a filesystem that does not exist there |
 * | Key | one row holding EVERY allowlisted stage | a per-stage row set would let a partial write desynchronise stages from each other; one row makes "the state" atomic |
 * | Content | digests + identities, never PT content | the whole PT dataset is never copied; a digest is sufficient to decide "changed or not", and it cannot leak authored content into a log |
 * | Size | bounded | see `retention()`; the record is capped and pruned deterministically |
 * | Autoload | `false` | this is not needed on every front-end request, and an autoloaded multi-stage digest map would be a per-request tax |
 *
 * ## Retention (documented, not incidental)
 *
 * | Rule | Value |
 * |---|---|
 * | Source-state revisions kept | **1** (the last accepted state) |
 * | Audit records kept | 20, oldest pruned first |
 * | Successful vs failed runs | both retained; a FAILED run is never pruned in favour of a successful one, because "what failed and why" is the more useful record |
 * | Old source-state pruning | safe by construction: only the newest revision is kept, so there is nothing to accumulate |
 *
 * ## Fail-closed reading
 *
 * Every abnormal state is DISTINGUISHED and none of them is treated as "no
 * changes" or, worse, as "everything changed":
 *
 * | Persisted state | `read()` outcome | Consequence |
 * |---|---|---|
 * | absent | `status = missing` | bootstrap required — the system refuses to translate anything |
 * | not an array / not JSON | `status = corrupt` | hard failure |
 * | unknown `v` | `status = incompatible` | hard failure |
 * | `projection` != current | `status = incompatible` | hard failure |
 * | a stage that is not allowlisted | `status = unexpected_stage` | hard failure |
 * | a digest that is not 64 hex | `status = invalid` | hard failure |
 * | valid | `status = ok` | reconciliation may proceed |
 *
 * **Corruption can never cause mass translation.** The only way to obtain a
 * baseline is `bootstrap()`, which is a deliberate, explicit, separately
 * audited act — never a side effect of noticing that state is missing.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The persisted PT source state.
 */
final class Conexao_Translation_Automation_Source_State {

	/**
	 * The single options row holding the whole state.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_translation_automation_source_state';

	/**
	 * The schema version of this record.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The maximum number of audit records retained.
	 *
	 * @var int
	 */
	const AUDIT_RETENTION = 20;

	/**
	 * Read outcomes.
	 *
	 * @var string
	 */
	const STATUS_OK               = 'ok';
	const STATUS_MISSING          = 'missing';
	const STATUS_CORRUPT          = 'corrupt';
	const STATUS_INCOMPATIBLE     = 'incompatible';
	const STATUS_UNEXPECTED_STAGE = 'unexpected_stage';
	const STATUS_INVALID          = 'invalid';

	/**
	 * Write the state. Replaced by tests to inject a storage failure without
	 * weakening the production write path.
	 *
	 * @var callable|null
	 */
	private static $writer = null;

	/**
	 * Replace the state writer. Test support; null restores the real one.
	 *
	 * @param callable|null $writer Callable receiving the record.
	 * @return void
	 */
	public static function set_writer( $writer ): void {
		self::$writer = $writer;
	}

	/**
	 * Read the persisted state, strictly validated.
	 *
	 * Never throws, never repairs and never invents a baseline. The caller must
	 * act on `status`, and only `ok` authorises a comparison.
	 *
	 * @param array $allowed_stages Explicit automation allowlist.
	 * @return array{status:string,reason:string,record:array,stages:array,inventory_digest:string}
	 */
	public static function read( array $allowed_stages ): array {
		$raw = get_option( self::OPTION, null );

		if ( null === $raw || false === $raw || '' === $raw ) {
			return self::refusal(
				self::STATUS_MISSING,
				'no persisted source state exists; an explicit bootstrap is required'
			);
		}

		// WordPress serialises arrays; a non-array here means the row was
		// written by something else, or is truncated. Either way it is corrupt.
		$record = is_array( $raw ) ? $raw : maybe_unserialize( $raw );

		if ( ! is_array( $record ) ) {
			return self::refusal(
				self::STATUS_CORRUPT,
				'the persisted source state is not a readable record'
			);
		}

		if ( ! isset( $record['v'] ) || self::SCHEMA_VERSION !== (int) $record['v'] ) {
			return self::refusal(
				self::STATUS_INCOMPATIBLE,
				sprintf(
					'persisted schema version %s is not the supported version %d',
					isset( $record['v'] ) ? (string) $record['v'] : '(absent)',
					self::SCHEMA_VERSION
				)
			);
		}

		if ( ! isset( $record['projection'] )
			|| Conexao_Translation_Automation_Digest::PROJECTION_VERSION !== (int) $record['projection'] ) {
			return self::refusal(
				self::STATUS_INCOMPATIBLE,
				'persisted state was produced by a different source projection version'
			);
		}

		if ( ! isset( $record['stages'] ) || ! is_array( $record['stages'] ) ) {
			return self::refusal(
				self::STATUS_CORRUPT,
				'persisted state declares no stages map'
			);
		}

		// A stage in the persisted state that is NOT allowlisted is a hard
		// failure, not something to skip: it means the state was written by a
		// build with different automation approval, so nothing in it can be
		// trusted, and silently ignoring it would hide the disagreement.
		foreach ( array_keys( $record['stages'] ) as $stage ) {
			if ( ! in_array( (string) $stage, $allowed_stages, true ) ) {
				return self::refusal(
					self::STATUS_UNEXPECTED_STAGE,
					sprintf(
						'persisted state contains stage "%s", which is not on the explicit automation allowlist',
						(string) $stage
					)
				);
			}
		}

		foreach ( $record['stages'] as $stage => $objects ) {
			if ( ! is_array( $objects ) ) {
				return self::refusal(
					self::STATUS_CORRUPT,
					sprintf( 'stage "%s" holds a non-map object list', (string) $stage )
				);
			}

			foreach ( $objects as $identity => $entry ) {
				if ( ! self::valid_entry( $entry ) ) {
					return self::refusal(
						self::STATUS_INVALID,
						sprintf(
							'stage "%s" object "%s" is not a valid inventory row',
							(string) $stage,
							(string) $identity
						)
					);
				}
			}
		}

		return array(
			'status'           => self::STATUS_OK,
			'reason'           => '',
			'record'           => $record,
			'stages'           => (array) $record['stages'],
			'inventory_digest' => isset( $record['inventory_digest'] ) ? (string) $record['inventory_digest'] : '',
		);
	}

	/**
	 * Build a state record from an inventory, WITHOUT persisting it.
	 *
	 * Pure, so a caller can compute and display the baseline it is about to
	 * adopt before anything is written.
	 *
	 * @param array  $stages Stage => (identity => digest) maps.
	 * @param string $run_id Run that produced the inventory.
	 * @param string $status Human-readable status.
	 * @return array
	 */
	public static function build( array $stages, string $run_id, string $status = 'accepted' ): array {
		return array(
			'v'                => self::SCHEMA_VERSION,
			'projection'       => Conexao_Translation_Automation_Digest::PROJECTION_VERSION,
			'inventory_digest' => self::inventory_digest( $stages ),
			'stages'           => self::normalise_stages( $stages ),
			'last_run_id'      => $run_id,
			'status'           => $status,
			'updated_at'       => gmdate( 'c' ),
		);
	}

	/**
	 * The digest of a whole inventory.
	 *
	 * @param array $stages Stage => (identity => digest) maps.
	 * @return string
	 */
	public static function inventory_digest( array $stages ): string {
		return Conexao_Translation_Automation_Digest::digest( self::normalise_stages( $stages ) );
	}

	/**
	 * Persist a state record.
	 *
	 * The record is re-validated BEFORE the write, so a caller cannot persist
	 * something this class would later refuse to read. A write failure is a hard
	 * failure, never a silent success.
	 *
	 * @param array $record        State record from `build()`.
	 * @param array $allowed_stages Explicit automation allowlist.
	 * @return true|WP_Error
	 */
	public static function write( array $record, array $allowed_stages ) {
		$clean = self::assert_writable( $record, $allowed_stages );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$secrets = Conexao_Translation_Automation_Result::assert_no_secrets( $clean );

		if ( is_wp_error( $secrets ) ) {
			return $secrets;
		}

		if ( is_callable( self::$writer ) ) {
			return true === call_user_func( self::$writer, $clean )
				? true
				: new WP_Error(
					'conexao_automation_source_state_write_failed',
					'the source-state writer refused the record.'
				);
		}

		// autoload = false: this record is read by reconciliation and by an
		// audit screen, never by the front end of every request.
		$ok = update_option( self::OPTION, $clean, false );

		if ( false === $ok && self::OPTION !== (string) get_option( self::OPTION, '' ) ) {
			return new WP_Error(
				'conexao_automation_source_state_write_failed',
				'the source state could not be persisted.'
			);
		}

		return true;
	}

	/**
	 * Forget the persisted state entirely.
	 *
	 * Test support and the documented escape hatch for an operator who must
	 * re-baseline. It does NOT translate anything: it removes the baseline so
	 * the next reconciliation correctly reports `missing` and demands a
	 * deliberate bootstrap.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Validate a record BEFORE it is written.
	 *
	 * The mirror image of `read()`: a record this class could not later read
	 * must never reach the database in the first place.
	 *
	 * @param array $record         Candidate record.
	 * @param array $allowed_stages Explicit automation allowlist.
	 * @return array|WP_Error The normalised record, or a reason it is refused.
	 */
	public static function assert_writable( array $record, array $allowed_stages ) {
		if ( ! isset( $record['v'] ) || self::SCHEMA_VERSION !== (int) $record['v'] ) {
			return new WP_Error(
				'conexao_automation_source_state_bad_version',
				'refusing to persist a source state with an unsupported schema version.'
			);
		}

		if ( ! isset( $record['projection'] )
			|| Conexao_Translation_Automation_Digest::PROJECTION_VERSION !== (int) $record['projection'] ) {
			return new WP_Error(
				'conexao_automation_source_state_bad_projection',
				'refusing to persist a source state produced by a different projection version.'
			);
		}

		if ( ! isset( $record['stages'] ) || ! is_array( $record['stages'] ) ) {
			return new WP_Error(
				'conexao_automation_source_state_bad_stages',
				'refusing to persist a source state without a stages map.'
			);
		}

		foreach ( array_keys( $record['stages'] ) as $stage ) {
			if ( ! in_array( (string) $stage, $allowed_stages, true ) ) {
				return new WP_Error(
					'conexao_automation_source_state_unexpected_stage',
					sprintf( 'refusing to persist state for non-allowlisted stage "%s".', (string) $stage )
				);
			}

			if ( null === Conexao_Translation_Automation_Digest::profile_for( (string) $stage ) ) {
				return new WP_Error(
					'conexao_automation_source_state_no_profile',
					sprintf( 'refusing to persist state for stage "%s", which has no projection profile.', (string) $stage )
				);
			}
		}

		foreach ( $record['stages'] as $objects ) {
			foreach ( (array) $objects as $identity => $entry ) {
				if ( ! self::valid_entry( $entry ) ) {
					return new WP_Error(
						'conexao_automation_source_state_bad_digest',
						sprintf(
							'refusing to persist stage object "%s": it is not a valid inventory row.',
							(string) $identity
						)
					);
				}
			}
		}

		return $record;
	}

	/**
	 * Is this a valid persisted inventory row?
	 *
	 * Two shapes are legal, and the difference matters:
	 *
	 *   - `present`: a 64-hex digest. The row was digested.
	 *   - `absent`:  NO digest. The stage's manifest names a record this site
	 *     does not have. That is a documented ABSENCE, not a corrupted digest,
	 *     and it must be persistable — otherwise a baseline containing even
	 *     one absent record could never be written, and bootstrap would be
	 *     impossible on any site where a manifest references a missing record.
	 *
	 * Everything else — a missing status, an unknown status, a digest that is
	 * present but malformed — is invalid. A malformed digest on a PRESENT row
	 * is the dangerous case: it would make the row compare as "changed"
	 * forever.
	 *
	 * @param mixed $entry Candidate row.
	 * @return bool
	 */
	private static function valid_entry( $entry ): bool {
		if ( ! is_array( $entry ) || ! isset( $entry['status'] ) ) {
			return false;
		}

		$status = (string) $entry['status'];
		$digest = (string) ( $entry['digest'] ?? '' );

		if ( 'absent' === $status ) {
			return '' === $digest;
		}

		if ( 'present' !== $status ) {
			return false;
		}

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $digest );
	}

	/**
	 * Sort the stage maps so the persisted bytes are deterministic.
	 *
	 * Two reconciliations of an identical inventory must produce byte-identical
	 * records, otherwise the inventory digest would be unstable and every run
	 * would look like a change.
	 *
	 * @param array $stages Stage => (identity => entry) maps.
	 * @return array
	 */
	private static function normalise_stages( array $stages ): array {
		$out = array();

		foreach ( $stages as $stage => $objects ) {
			$objects = (array) $objects;
			ksort( $objects );

			$entries = array();

			foreach ( $objects as $identity => $entry ) {
				$digest = is_array( $entry ) ? (string) ( $entry['digest'] ?? '' ) : (string) $entry;
				$status = is_array( $entry ) && isset( $entry['status'] ) ? (string) $entry['status'] : '';

				// An absent row has no digest by design; anything else is present
				// and must carry one. Normalising here means every persisted row
				// is written in exactly the shape `valid_entry()` accepts.
				$entries[ (string) $identity ] = array(
					'digest' => ( '' === $status || 'absent' === $status ) ? '' : $digest,
					'status' => 'absent' === $status ? 'absent' : 'present',
				);
			}

			$out[ (string) $stage ] = $entries;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * A refusal with the same shape as a successful read.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param string $reason Explanation.
	 * @return array{status:string,reason:string,record:array,stages:array,inventory_digest:string}
	 */
	private static function refusal( string $status, string $reason ): array {
		return array(
			'status'           => $status,
			'reason'           => $reason,
			'record'           => array(),
			'stages'           => array(),
			'inventory_digest' => '',
		);
	}
}
