<?php
/**
 * The translation-plan adapter: validated provider data -> existing engine
 * input (Stage 4).
 *
 * ## The integration point, and why the engine is untouched
 *
 * `Conexao_Translation_Rollout_Engine::run()` takes its manifest from
 * `$config['manifest_callback']` — an INJECTED callable — and this repository
 * already exports one: `conexao_en_translation_stage_manifest( $stage )`, which
 * reads the stage's OWN authored dataset. So the adapter does not build a
 * manifest, does not register a stage, and does not reimplement inventory,
 * planning, snapshots, apply, the PT-drift guard, verification or the numeric
 * gate.
 *
 * It composes ONE thing: a `manifest_callback` that returns the stage's own
 * manifest with the provider's validated English overlaid on the rows the plan
 * names, and every other row left exactly as authored. The engine then receives
 * a normal, valid manifest and does everything else, unchanged.
 *
 * | Plan row | Manifest row |
 * |---|---|
 * | `post_title` | `en_title` |
 * | `post_content` | `en_content` |
 * | `post_excerpt` | `en_excerpt` |
 * | `conexao_meta_description` | `en_meta_description` |
 * | `post_name` | never taken from the provider (see below) |
 *
 * ## Slugs and taxonomies: the provider is not allowed an opinion
 *
 * - **Slugs.** `post_name` is NOT requested, never sent and never overlaid. The
 *   repository's slug rules are authored per record and the EN URL is a
 *   deliberate SEO decision; a model inventing `en_slug` would let a
 *   probabilistic system change a public URL. Any new slug policy must be
 *   authored and separately gated, exactly as §16 requires.
 * - **Taxonomies.** Translated terms are the stage's own `taxonomy_callback`
 *   business, keyed by authored PT term slug. The provider receives no term
 *   vocabulary and can return none; this adapter performs no
 *   `wp_insert_term` and no `wp_set_object_terms`.
 *
 * ## Stale-source protection
 *
 * Before a row is allowed into the composed manifest, the adapter re-reads the
 * live PT digest and compares it with the digest the plan was built from. A
 * mismatch means PT moved while the provider was thinking, so the row is
 * REJECTED and the plan records it. The engine never sees a translation of a
 * source that no longer exists.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The adapter: the smallest bridge between a plan and the shared engine.
 */
final class Conexao_Translation_Automation_Plan_Adapter {

	/**
	 * How a PT projection field maps onto an EN manifest key.
	 *
	 * One table, so the mapping is inspectable and cannot drift between the
	 * request builder, the plan and the manifest. A field absent from this table
	 * is NOT overlayable — it cannot reach content, which is the safe default.
	 *
	 * @var array<string,string>
	 */
	const FIELD_MAP = array(
		'post_title'               => 'en_title',
		'post_content'             => 'en_content',
		'post_excerpt'             => 'en_excerpt',
		'conexao_meta_description' => 'en_meta_description',
	);

	/**
	 * The B2 stage's own English field.
	 *
	 * A B2 stage writes ONE authored field on the SAME PT record. It is named by
	 * the stage, not invented here.
	 *
	 * @var string
	 */

	/**
	 * The B2 stage's own English field.
	 *
	 * A B2 stage writes ONE authored field on the SAME PT record. It is named by
	 * the stage, not invented here.
	 *
	 * @var string
	 */
	const B2_FIELD = 'en_description';

	/**
	 * The PT projection field a B2 stage translates.
	 *
	 * `post_excerpt` is the field the B2 projection DIGESTS and the field the
	 * stage's own PT-drift guard compares, so it is what the provider is asked
	 * for; `en_description` is where the answer is STORED. Keeping the two
	 * distinct is what stops a B2 row from being mistaken for a B1 title or
	 * body write.
	 *
	 * @var string
	 */
	const B2_SOURCE_FIELD = 'post_excerpt';

	/**
	 * Fields the provider may never supply, whatever it answers.
	 *
	 * `post_name` is the important one: an EN slug is a public URL and a
	 * deliberate, authored decision, so letting a probabilistic system choose
	 * one would let it change the site's URL space.
	 *
	 * @var array<int,string>
	 */
	const FORBIDDEN_FIELDS = array( 'post_name', 'en_slug', 'slug', 'ID', 'post_status', 'pt_id', 'en_id' );

	/**
	 * Overridable stage-manifest resolver, for tests.
	 *
	 * @var callable|null
	 */
	private static $manifest_resolver = null;

	/**
	 * Overridable live-digest reader, for tests.
	 *
	 * @var callable|null
	 */
	private static $digest_reader = null;

	/**
	 * Replace the stage manifest resolver. Test support.
	 *
	 * @param callable|null $resolver Callable receiving the stage id.
	 * @return void
	 */
	public static function set_manifest_resolver( $resolver ): void {
		self::$manifest_resolver = $resolver;
	}

	/**
	 * Replace the live PT digest reader. Test support.
	 *
	 * @param callable|null $reader Callable receiving ( stage, identity ).
	 * @return void
	 */
	public static function set_digest_reader( $reader ): void {
		self::$digest_reader = $reader;
	}

	/**
	 * The stage's own manifest, through the stage's own declared callback.
	 *
	 * Resolution order, both non-invented:
	 *
	 *   1. the stage configuration REGISTERED WITH THE ENGINE, whose
	 *      `manifest_callback` is the one the engine itself validates and the
	 *      one `Inventory` already reads. This covers every stage, B1 and B2,
	 *      because each stage registered its own;
	 *   2. the repository's exported B1 resolver, when the stage is registered
	 *      but its configuration cannot be read.
	 *
	 * This is reuse, not re-implementation: the same callback the engine and the
	 * inventory use, never a second list of records.
	 *
	 * @param string $stage Stage identifier.
	 * @return array
	 */
	private static function stage_manifest( string $stage ): array {
		if ( is_callable( self::$manifest_resolver ) ) {
			$manifest = call_user_func( self::$manifest_resolver, $stage );

			return is_array( $manifest ) ? $manifest : array();
		}

		if ( class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
			$config = Conexao_Translation_Rollout_Engine::get_stage( $stage );

			if ( is_array( $config ) && ! empty( $config['manifest_callback'] ) && is_callable( $config['manifest_callback'] ) ) {
				$manifest = call_user_func( $config['manifest_callback'] );

				if ( is_array( $manifest ) ) {
					return $manifest;
				}
			}
		}

		// The EXISTING exported resolver. Not a re-implementation: the same
		// function the stage's own runner already reads.
		if ( function_exists( 'conexao_en_translation_stage_manifest' ) ) {
			return (array) conexao_en_translation_stage_manifest( $stage );
		}

		return array();
	}

	/**
	 * Compose the engine-compatible manifest for one stage and plan.
	 *
	 * The stage's own authored manifest, with the plan's validated English
	 * overlaid on the rows the plan names. Rows the plan does not name are
	 * returned EXACTLY as authored, so the plan can never silently drop a
	 * record from the engine's scope.
	 *
	 * @param string $stage Stage identifier.
	 * @param array  $plan  Output of `Translation_Plan::rows_by_identity()`.
	 * @return array {
	 *     @type array  $manifest The manifest the engine will validate.
	 *     @type array  $applied  Identities overlaid.
	 *     @type array  $rejected Identity => reason, for stale or unmergeable rows.
	 * }
	 */
	public static function compose( string $stage, array $plan ): array {
		$manifest = self::stage_manifest( $stage );
		$records  = isset( $manifest['records'] ) && is_array( $manifest['records'] )
			? $manifest['records']
			: array();

		$applied  = array();
		$rejected = array();

		foreach ( $plan as $identity => $row ) {
			$identity = (string) $identity;

			// A plan row for a record the stage does not own is a caller
			// defect. Overlaying it would let a plan write outside the stage's
			// declared scope, so it is refused.
			if ( ! isset( $records[ $identity ] ) || ! is_array( $records[ $identity ] ) ) {
				$rejected[ $identity ] = 'the stage manifest declares no row for this identity';

				continue;
			}

			$stale = self::is_stale( $stage, $row );

			if ( '' !== $stale ) {
				$rejected[ $identity ] = $stale;

				continue;
			}

			$merged = self::merge_row( $records[ $identity ], $row, (string) ( $row['mode'] ?? 'b1' ), $rejected, $identity );

			if ( null === $merged ) {
				continue;
			}

			$records[ $identity ] = $merged;
			$applied[]            = $identity;
		}

		$manifest['records'] = $records;

		return array(
			'manifest' => $manifest,
			'applied'  => $applied,
			'rejected' => $rejected,
		);
	}

	/**
	 * Does the live PT source still match the digest the plan was built from?
	 *
	 * @param string $stage Stage identifier.
	 * @param array  $row   Plan row.
	 * @return string Empty when current; otherwise the rejection reason.
	 */
	private static function is_stale( string $stage, array $row ): string {
		$planned = (string) ( $row['source_digest'] ?? '' );

		if ( '' === $planned ) {
			return 'the plan row carries no source digest';
		}

		$live = self::live_digest( $stage, (string) ( $row['source_id'] ?? '' ) );

		if ( '' === $live ) {
			// Unreadable is not "unchanged". Treating it as unchanged would let
			// an unresolvable record through, which is the exact failure the
			// whole digest chain exists to prevent.
			return 'the live PT source digest could not be re-read';
		}

		if ( $live !== $planned ) {
			return 'source digest mismatch: the PT source changed after the provider result was generated';
		}

		return '';
	}

	/**
	 * The live PT source digest for one stable key.
	 *
	 * Resolved the way `Inventory` resolves it: the portable slug, never an
	 * environment-local post ID.
	 *
	 * @param string $stage    Stage identifier.
	 * @param string $identity Portable PT stable key.
	 * @return string Digest, or '' when it cannot be established.
	 */
	private static function live_digest( string $stage, string $identity ): string {
		if ( is_callable( self::$digest_reader ) ) {
			return (string) call_user_func( self::$digest_reader, $stage, $identity );
		}

		$post_type = (string) Conexao_Translation_Automation_Digest::profile_for( $stage )['source_post_type'];
		$post      = '' === $post_type ? null : get_page_by_path( $identity, OBJECT, $post_type );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$read = Conexao_Translation_Automation_Digest::digest_record( $stage, (int) $post->ID );

		return ! empty( $read['ok'] ) ? (string) $read['digest'] : '';
	}

	/**
	 * Overlay one plan row's translations onto one authored manifest row.
	 *
	 * Returns null when nothing could be merged, having recorded the reason.
	 * Never adds a key the stage did not declare: a plan cannot widen a stage's
	 * field set.
	 *
	 * @param array  $existing Authored manifest row.
	 * @param array  $row      Plan row.
	 * @param string $mode     b1 or b2.
	 * @param array  $rejected Rejection map, by reference.
	 * @param string $identity Portable stable key.
	 * @return array|null The merged row, or null.
	 */
	private static function merge_row( array $existing, array $row, string $mode, array &$rejected, string $identity ) {
		$translations = (array) ( $row['translations'] ?? array() );
		$merged       = $existing;
		$applied      = 0;

		foreach ( $translations as $field => $value ) {
			$field = (string) $field;

			if ( in_array( $field, self::FORBIDDEN_FIELDS, true ) ) {
				$rejected[ $identity ] = sprintf( 'refused provider field "%s": it is not a translatable field', $field );

				return null;
			}

			// A B2 stage owns EXACTLY ONE field, on the SAME PT record. A B1
			// field name offered to a B2 stage is a scope violation — it would
			// write `en_title` on a record whose B2 manifest row does not even
			// declare it, so the stage's own adapter would never read it while
			// the row silently changed. Refused, not ignored.
			if ( 'b2' === $mode && self::B2_SOURCE_FIELD !== $field ) {
				$rejected[ $identity ] = sprintf(
					'a B2 stage owns only "%s"; "%s" is not one of its fields',
					self::B2_FIELD,
					$field
				);

				return null;
			}

			$target = 'b2' === $mode
				? self::B2_FIELD
				: ( self::FIELD_MAP[ $field ] ?? '' );

			if ( '' === $target ) {
				$rejected[ $identity ] = sprintf( 'field "%s" has no manifest mapping for this stage', $field );

				return null;
			}

			// A B1 row may only overwrite a key the stage ALREADY declares. That
			// is what keeps a plan from adding a field the engine's adapter
			// would never read, and from inventing a B1 field on a B2 stage.
			if ( 'b2' !== $mode && ! array_key_exists( $target, $existing ) ) {
				$rejected[ $identity ] = sprintf(
					'the stage manifest declares no "%s" key for this row; refusing to add one',
					$target
				);

				return null;
			}

			$merged[ $target ] = (string) $value;
			++$applied;
		}

		if ( 0 === $applied ) {
			$rejected[ $identity ] = 'the plan row carried no translatable field this stage can accept';

			return null;
		}

		// A B2 stage's PT-drift guard compares the AUTHORED PT source it was
		// written against. Overlaying English while leaving a stale
		// `pt_source` behind would make the stage's own guard compare against
		// the wrong text, so the current PT excerpt is re-stamped. This is the
		// existing stage's field and its existing semantics, not a new policy.
		if ( 'b2' === $mode && array_key_exists( 'pt_source', $existing ) ) {
			$merged['pt_source'] = (string) ( $row['pt_source'] ?? $existing['pt_source'] );
		}

		return $merged;
	}

	/**
	 * Build the stage configuration the engine will run, with the composed
	 * manifest injected.
	 *
	 * This is the WHOLE integration. The engine receives an ordinary config
	 * whose `manifest_callback` yields the composed manifest; everything else —
	 * planning, snapshots, apply, drift, verify, gate — stays the engine's.
	 *
	 * @param string $stage    Stage identifier.
	 * @param array  $config   The stage's own registered configuration.
	 * @param array  $manifest The composed manifest.
	 * @return array
	 */
	public static function engine_config( string $stage, array $config, array $manifest ): array {
		$config['stage']             = $stage;
		$config['manifest_callback'] = static function () use ( $manifest ): array {
			return $manifest;
		};

		return $config;
	}
}
