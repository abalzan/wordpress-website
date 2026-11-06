<?php
/**
 * One-time, idempotent, dry-run-first migration of the six legacy occasions.
 *
 * §8.2: the seed creates one `commemorative_event` per legacy registry entry,
 * mapping `id → _ce_event_key` and carrying the current dates/lead-in, so the
 * observable behaviour is identical with ONE intentional exception: New Year is
 * seeded with the corrected seven-day `12-26 → 01-01` window.
 *
 * There is NO runtime fallback to a built-in list (§8.2): after the migration
 * the CPT is the only source of truth, and a brand-new install has zero events
 * until an administrator creates one.
 *
 * Idempotence is enforced twice: an existing `_ce_event_key` is skipped, and a
 * key recorded in `conexao_commemorative_seeded_keys` is skipped even when its
 * record was permanently deleted — so a deliberately removed event never
 * reappears.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seed migration for the legacy occasions.
 */
final class Conexao_Commemorative_Migration {

	/**
	 * Option recording every key this migration has already created.
	 */
	const SEEDED_OPTION = 'conexao_commemorative_seeded_keys';

	/**
	 * The six legacy occasions, as commemorative_event records.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		return array(
			'christmas'       => array(
				'title_pt'     => 'Natal',
				'title_en'     => 'Christmas',
				'presentation' => 'christmas',
				'rule'         => 'fixed_annual',
				'date'         => '12-25',
				'lead'         => 15,
				'note'         => 'Migrado do registro legado (fixed_annual 12-25, lead 15).',
			),
			'halloween'       => array(
				'title_pt'     => 'Halloween',
				'title_en'     => 'Halloween',
				'presentation' => 'halloween',
				'rule'         => 'fixed_annual',
				'date'         => '10-31',
				'lead'         => 15,
				'note'         => 'Migrado do registro legado (fixed_annual 10-31, lead 15).',
			),
			'new_year'        => array(
				'title_pt'     => 'Ano Novo',
				'title_en'     => 'New Year',
				'presentation' => 'new_year',
				'rule'         => 'window',
				'window_start' => '12-26',
				'window_end'   => '01-01',
				'lead'         => 0,
				'note'         => 'Janela explícita de sete dias 12-26 → 01-01 (correção intencional do r10).',
			),
			'st_patricks_day' => array(
				'title_pt'     => 'Dia de São Patrício',
				'title_en'     => 'St Patrick\'s Day',
				'presentation' => 'st_patricks_day',
				'rule'         => 'fixed_annual',
				'date'         => '03-17',
				'lead'         => 15,
				'note'         => 'Migrado do registro legado (fixed_annual 03-17, lead 15).',
			),
			'valentines_day'  => array(
				'title_pt'     => 'Dia dos Namorados',
				'title_en'     => 'Valentine\'s Day',
				'presentation' => 'valentines_day',
				'rule'         => 'fixed_annual',
				'date'         => '02-14',
				'lead'         => 15,
				'note'         => 'Migrado do registro legado (fixed_annual 02-14, lead 15).',
			),
			'easter'          => array(
				'title_pt'     => 'Páscoa',
				'title_en'     => 'Easter',
				'presentation' => 'easter',
				'rule'         => 'variable_annual',
				'variable'     => 'easter',
				'lead'         => 15,
				'note'         => 'Migrado do registro legado (variable_annual easter, lead 15).',
			),
		);
	}

	/**
	 * The keys this migration has already created in this install.
	 *
	 * @return string[]
	 */
	public static function seeded_keys() {
		$keys = get_option( self::SEEDED_OPTION, array() );

		return is_array( $keys ) ? array_values( array_map( 'strval', $keys ) ) : array();
	}

	/**
	 * Plan the migration without writing anything.
	 *
	 * Fixed bucket order: create / skip / conflicts (the shared plan shape).
	 *
	 * @return array{create:array,skip:array,conflicts:array}
	 */
	public static function plan() {
		$plan   = array(
			'create'    => array(),
			'skip'      => array(),
			'conflicts' => array(),
		);
		$seeded = self::seeded_keys();
		$by_key = array();

		foreach ( Conexao_Commemorative_Repository::all( array( 'post_status' => 'any' ) ) as $event ) {
			if ( '' !== $event['event_key'] ) {
				$by_key[ $event['event_key'] ] = $event;
			}
		}

		foreach ( self::definitions() as $key => $definition ) {
			if ( isset( $by_key[ $key ] ) ) {
				$plan['skip'][] = array(
					'key'    => $key,
					'reason' => 'exists',
					'id'     => (int) $by_key[ $key ]['id'],
				);
				continue;
			}

			if ( in_array( $key, $seeded, true ) ) {
				$plan['skip'][] = array(
					'key'    => $key,
					'reason' => 'deleted_after_seed',
					'id'     => 0,
				);
				continue;
			}

			$plan['create'][] = array(
				'key'        => $key,
				'definition' => $definition,
			);
		}

		return $plan;
	}

	/**
	 * Apply the plan (or report it in dry-run mode).
	 *
	 * @param bool $dry_run          Report only. Default TRUE.
	 * @param bool $with_translations Create the linked EN records when Polylang is active.
	 * @return array{created:int,skipped:int,translations:int,records:array}
	 */
	public static function apply( $dry_run = true, $with_translations = true ) {
		$plan   = self::plan();
		$result = array(
			'created'      => 0,
			'skipped'      => count( $plan['skip'] ),
			'translations' => 0,
			'records'      => array(),
		);

		foreach ( $plan['create'] as $item ) {
			$result['records'][] = array(
				'key'    => $item['key'],
				'action' => $dry_run ? 'would_create' : 'created',
			);

			if ( $dry_run ) {
				continue;
			}

			$post_id = self::create_event( $item['key'], $item['definition'], $with_translations );

			if ( $post_id > 0 ) {
				++$result['created'];

				if ( $with_translations && self::polylang_active() ) {
					++$result['translations'];
				}
			}
		}

		if ( ! $dry_run ) {
			Conexao_Commemorative_Repository::reset_cache();
			Conexao_Commemorative_Cache::arm();
		}

		return $result;
	}

	/**
	 * Create one event record (plus its linked EN record when requested).
	 *
	 * @param string $key              Stable event key.
	 * @param array  $definition       Definition.
	 * @param bool   $with_translations Create the EN record.
	 * @return int New PT post ID (0 on failure).
	 */
	private static function create_event( $key, array $definition, $with_translations ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => Conexao_Data_Model_Commemorative::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $definition['title_pt'],
				'post_name'   => $key,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, '_ce_event_key', $key );
		update_post_meta( $post_id, '_ce_rule', $definition['rule'] );
		update_post_meta( $post_id, '_ce_presentation', $definition['presentation'] );
		update_post_meta( $post_id, '_ce_lead_in_days', (string) $definition['lead'] );
		update_post_meta( $post_id, '_ce_enabled', '1' );
		update_post_meta( $post_id, '_ce_priority', '10' );
		update_post_meta( $post_id, '_ce_description', $definition['note'] );

		if ( isset( $definition['date'] ) ) {
			update_post_meta( $post_id, '_ce_date', $definition['date'] );
		}

		if ( isset( $definition['variable'] ) ) {
			update_post_meta( $post_id, '_ce_variable', $definition['variable'] );
		}

		if ( isset( $definition['window_start'] ) ) {
			update_post_meta( $post_id, '_ce_window_start', $definition['window_start'] );
		}

		if ( isset( $definition['window_end'] ) ) {
			update_post_meta( $post_id, '_ce_window_end', $definition['window_end'] );
		}

		$seeded   = self::seeded_keys();
		$seeded[] = $key;
		update_option( self::SEEDED_OPTION, array_values( array_unique( $seeded ) ), false );

		if ( ! self::polylang_active() ) {
			return $post_id;
		}

		$default = pll_default_language( 'slug' );

		if ( $default ) {
			pll_set_post_language( $post_id, $default );
		}

		if ( $with_translations ) {
			self::create_translation( $post_id, $key, $definition );
		}

		return $post_id;
	}

	/**
	 * Create the linked EN record: translated title + identity, NOTHING else.
	 *
	 * §3.6: the EN record carries `post_title` (translated) and `_ce_event_key`
	 * (identity) and no other shared `_ce_*` meta.
	 *
	 * @param int    $post_id    PT post ID.
	 * @param string $key        Stable event key.
	 * @param array  $definition Definition.
	 * @return int Translation post ID (0 when impossible).
	 */
	private static function create_translation( $post_id, $key, array $definition ) {
		$default = pll_default_language( 'slug' );
		$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
		$target  = '';

		foreach ( (array) $slugs as $slug ) {
			if ( (string) $slug !== (string) $default ) {
				$target = (string) $slug;
				break;
			}
		}

		if ( '' === $target ) {
			return 0;
		}

		$translation = wp_insert_post(
			array(
				'post_type'   => Conexao_Data_Model_Commemorative::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $definition['title_en'],
				'post_name'   => $key . '-' . $target,
			),
			true
		);

		if ( is_wp_error( $translation ) ) {
			return 0;
		}

		$translation = (int) $translation;

		// Identity only: the shared configuration stays on the PT record.
		update_post_meta( $translation, '_ce_event_key', $key );

		pll_set_post_language( $translation, $target );

		pll_save_post_translations(
			array(
				(string) $default => (int) $post_id,
				$target           => $translation,
			)
		);

		return $translation;
	}

	/**
	 * Whether the Polylang translation API is available.
	 *
	 * @return bool
	 */
	public static function polylang_active() {
		return function_exists( 'pll_default_language' )
			&& function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_languages_list' );
	}
}
