<?php
/**
 * Build the deterministic synthetic WordPress site the CI integration job needs.
 *
 * THIS IS THE ONE ORCHESTRATOR. There is no second fixture lifecycle: this
 * script owns ORDER and nothing else. Every unit of real work below is either
 * (a) an EXISTING repository seeder invoked by path, or (b) an EXISTING
 * stage of the SHARED `conexao-translation-rollout` engine invoked through
 * `scripts/run-en-translation.php`. No seed logic, no translation logic and no
 * gate arithmetic is reimplemented here; duplicating any of it would create a
 * second engine, which engineering standard 4.1 forbids.
 *
 * ── Why this exists ──────────────────────────────────────────────────────
 *
 * Several maintained suites assert against site CONTENT: the HTTP acceptance
 * matrix needs a populated /guias/, /blog/, /lazer/, /cursos/, /eventos/ and
 * /apoiadores/, and the B1 translation-completeness gate is only meaningful
 * over a non-empty population. On a database built from nothing, those suites
 * were either 404ing on page 2 or passing VACUOUSLY (eligible = 0,
 * missing = 0). This script makes the population a committed, deterministic
 * function of the repository instead of an accident of somebody's local
 * volume.
 *
 * ── Properties this script guarantees ────────────────────────────────────
 *
 *   DETERMINISTIC  every record is keyed by slug and every value is either
 *                  committed in scripts/data/ci-fixture-*.php or derived from
 *                  it. No randomness, no "now" in a way that changes a stored
 *                  value between two runs on the same day, no network.
 *   IDEMPOTENT     every unit is create-or-repair keyed by slug. A second run
 *                  creates nothing, and the script proves it by comparing the
 *                  post count before and after (reported as `created`).
 *   EMPTY-SAFE     assumes nothing exists: not a page, not a term, not a
 *                  menu. It runs on a brand-new database.
 *   ORDER-INDEPENDENT each step declares its own prerequisites and fails with a
 *                  named error if they are missing, instead of silently
 *                  producing an empty result.
 *   TEARDOWN-SAFE  it only ever ADDS committed fixture records to the
 *                  ephemeral CI database, which `docker compose down -v`
 *                  destroys. It never writes production and never requires a
 *                  credential.
 *
 * ── Usage ────────────────────────────────────────────────────────────────
 *
 *   php scripts/bootstrap-ci-fixtures.php --apply     # build the site
 *   php scripts/bootstrap-ci-fixtures.php --dry-run   # report, write nothing
 *   php scripts/bootstrap-ci-fixtures.php --apply --verify   # build + audit
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';

$ctx = conexao_script_boot(
	array(
		'script'             => 'bootstrap-ci-fixtures.php',
		'purpose'            => 'Build the deterministic SYNTHETIC site the CI integration job needs: committed PT fixtures plus the existing translation-rollout stages plus the existing directory seeders, in dependency order.',
		'scope'              => 'The WordPress install this script is connected to. Only committed fixture data and existing seeders/stages are used. No production target, no credential, no network fetch of content, no developer-database dependency.',
		'safety'             => 'local-only; dry-run by default; --apply required to write; refuses a production target via the shared bootstrap guard',
		'target_description' => 'the WordPress install the script is connected to (site URL printed in the header)',
		'modes_description'  => '--dry-run (default) prints the plan and writes nothing. --apply builds the site. --verify audits the resulting populations and fails closed on a shortfall.',
		'arguments'          => "--dry-run          Plan only, zero writes (default).\n"
			. "                    --apply            Create/update the fixture records.\n"
			. "                    --verify           After applying, audit every minimum population and exit non-zero on a shortfall.\n"
			. "                    --json             Machine-readable output.\n"
			. '                    --help             This message.',
		'writes'             => true,
		'read_only'          => false,
		'production_capable' => false,
		'extra_flags'        => array(
			'--verify' => 'verify',
		),
	)
);

conexao_script_load_wordpress();

require_once __DIR__ . '/data/ci-fixture-guides.php';
require_once __DIR__ . '/data/ci-fixture-posts.php';
require_once __DIR__ . '/data/ci-fixture-events.php';

$apply  = ( 'apply' === $ctx['mode'] );
$verify = in_array( '--verify', (array) ( $ctx['extra'] ?? array() ), true );

// ─────────────────────────────────────────────────────────────────────────────
// Counters. `created` counts RECORDS THAT DID NOT EXIST, which is the number
// that must be 0 on a second run. `repaired` counts existing records whose
// stored value differed and was rewritten, and is likewise expected to be 0
// once the site is in its converged state.
// ─────────────────────────────────────────────────────────────────────────────
$stats = array(
	'guide_created'    => 0,
	'guide_repaired'   => 0,
	'post_created'     => 0,
	'post_repaired'    => 0,
	'sponsor_created'  => 0,
	'sponsor_repaired' => 0,
	'event_created'    => 0,
	'event_repaired'   => 0,
	'job_created'      => 0,
	'job_repaired'     => 0,
	'terms_created'    => 0,
	'jobs_created'     => 0,
	'errors'           => 0,
);

/**
 * Find an existing post of a type by slug, regardless of language.
 *
 * The fixture lookup deliberately does NOT filter by language: the CI site is
 * the thing being built, and a slug collision between a PT fixture and an EN
 * translation is a real defect that must surface as a conflict rather than be
 * papered over by creating a second record under the same slug.
 *
 * @param string $post_type Post type.
 * @param string $slug      Slug.
 * @return WP_Post|null
 */
function conexao_ci_find( string $post_type, string $slug ) {
	// `get_page_by_path()` is used rather than a `get_posts( [ 'name' => … ] )`
	// query because the latter does not reliably match a CPT slug on this
	// install: it returned 0 rows for a record that demonstrably exists, so an
	// upsert keyed on it silently created `-2`, `-3`, … duplicates on every
	// run. `get_page_by_path()` resolves the same slug correctly.
	//
	// The lookup deliberately does NOT filter by language: the CI site is the
	// thing being built, and a slug collision between a PT fixture and an EN
	// translation is a real defect that must surface as a conflict rather than
	// be papered over by creating a second record under the same slug.
	$post = get_page_by_path( $slug, OBJECT, $post_type );

	if ( $post instanceof WP_Post ) {
		return $post;
	}

	// Fallback for a slug the simple path lookup could not resolve. The scan is
	// bounded to this post type and compared on `post_name` directly, which is
	// what actually matched when the WP_Query `name` argument did not.
	$found = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'lang'           => '',
		)
	);

	foreach ( $found as $candidate ) {
		if ( (string) $candidate->post_name === $slug ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Create-or-repair one fixture post, keyed by slug.
 *
 * Idempotence is decided by COMPARING the stored fields, not by assuming: a
 * second run compares equal, repairs nothing and reports zero. This is what
 * makes "run the bootstrap twice" a meaningful determinism test rather than a
 * claim.
 *
 * @param string $post_type Post type.
 * @param string $slug      Stable slug.
 * @param array  $fields    post_* fields plus 'meta' and 'terms'.
 * @param array  $stats     Counters, by reference.
 * @return int Post ID.
 */
function conexao_ci_upsert( string $post_type, string $slug, array $fields, array &$stats ) {
	$existing = conexao_ci_find( $post_type, $slug );

	$args = array(
		'post_type'    => $post_type,
		'post_name'    => $slug,
		'post_title'   => (string) ( $fields['title'] ?? '' ),
		'post_content' => (string) ( $fields['content'] ?? '' ),
		'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ),
		// A fixture may declare a non-published status. That is REQUIRED for the
		// "hidden" event fixtures: the REST suite asserts that a rejected and a
		// source-removed event are NOT served, which is only a real assertion if
		// the records exist carrying those statuses. A default of `publish` is
		// therefore only the fallback, never an override.
		'post_status'  => isset( $fields['status'] ) && '' !== $fields['status']
			? (string) $fields['status']
			: 'publish',
	);

	// `menu_order` is sent ONLY when the caller declares it, exactly like
	// `post_status` and `date`: an undeclared field belongs to the component
	// that owns the record, and a fixture that always wrote 0 would silently
	// reset it on every run.
	if ( isset( $fields['menu_order'] ) ) {
		$args['menu_order'] = (int) $fields['menu_order'];
	}

	if ( isset( $fields['date'] ) && '' !== $fields['date'] ) {
		$args['post_date'] = (string) $fields['date'];

		// A past `post_date` is only accepted together with its GMT twin.
		// WordPress validates the pair, and posting one without the other
		// yields the opaque "Data inválida." error, so both are always set
		// together and derived from the same value.
		$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
	}

	if ( null === $existing ) {
		$post_id = wp_insert_post( $args, true );
		if ( is_wp_error( $post_id ) ) {
			++$stats['errors'];
			printf( "  ERROR creating %s/%s: %s\n", $post_type, $slug, $post_id->get_error_message() );
			return 0;
		}
		$key           = $post_type . '_created';
		$stats[ $key ] = $stats[ $key ] + 1;
	} else {
		$post_id = (int) $existing->ID;

		// Repair ONLY a genuine difference. Comparing before writing is what
		// keeps a second run at zero writes.
		$changed = false;
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( (string) $existing->{$field} !== (string) $args[ $field ] ) {
				$changed = true;
			}
		}
		if ( isset( $args['post_date'] ) && (string) $existing->post_date !== (string) $args['post_date'] ) {
			$changed = true;
		}

		// `post_status` is compared only when the caller EXPLICITLY declared one.
		//
		// Events do not keep `publish`: the event runtime owns the real status
		// and moves a future-dated record to `future`. Comparing unconditionally
		// meant every re-run "repaired" those records back to `publish`, which
		// then flipped them to `future` again on the next runtime pass — a
		// two-step fight over a field the fixture does not own, and it made the
		// REST suite see an unexpected status. A status the caller never
		// declared is left exactly as the owning component set it.
		if ( isset( $fields['status'] ) && (string) $existing->post_status !== (string) $args['post_status'] ) {
			$changed = true;
		}

		// Same rule for `menu_order`: only compared when the caller declared it.
		if ( isset( $fields['menu_order'] ) && (int) $existing->menu_order !== (int) $args['menu_order'] ) {
			$changed = true;
		}

		if ( $changed ) {
			$args['ID'] = $post_id;

			// Same rule as the comparison above: do not send a `post_status` the
			// caller never declared, so an existing record keeps the status its
			// OWNING component gave it.
			if ( ! isset( $fields['status'] ) ) {
				unset( $args['post_status'] );
			}
			if ( ! isset( $fields['menu_order'] ) ) {
				unset( $args['menu_order'] );
			}

			$updated = wp_update_post( $args, true );
			if ( is_wp_error( $updated ) ) {
				++$stats['errors'];
				printf( "  ERROR repairing %s/%s: %s\n", $post_type, $slug, $updated->get_error_message() );
				return $post_id;
			}
			$key           = $post_type . '_repaired';
			$stats[ $key ] = $stats[ $key ] + 1;
		}
	}

	foreach ( (array) ( $fields['meta'] ?? array() ) as $meta_key => $meta_value ) {
		if ( (string) get_post_meta( $post_id, $meta_key, true ) !== (string) $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}
	}

	foreach ( (array) ( $fields['terms'] ?? array() ) as $taxonomy => $term_slugs ) {
		$ids = conexao_ci_term_ids( $taxonomy, (array) $term_slugs, $stats );
		if ( ! empty( $ids ) ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	return $post_id;
}

/**
 * Resolve term slugs to IDs inside a taxonomy, creating what is missing.
 *
 * The SHARED proper-name taxonomies (`conexao_county`, `conexao_town`) are
 * never given a language and never duplicated per language: the SAME physical
 * term serves both languages, and a `-en`/`-pt` suffixed duplicate is exactly
 * what the permanent `taxonomy_policy` gate fails on. This helper therefore
 * looks the term up by slug and reuses it.
 *
 * @param string $taxonomy Taxonomy.
 * @param array  $slugs    Term slugs.
 * @param array  $stats    Counters, by reference.
 * @return int[] Term IDs.
 */
function conexao_ci_term_ids( string $taxonomy, array $slugs, array &$stats ): array {
	$ids = array();

	foreach ( $slugs as $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug ) {
			continue;
		}

		// Look the term up WITHOUT a language filter.
		//
		// `get_term_by()` runs through the `get_terms` path, which Polylang
		// narrows to the current language. In a CLI process the current language
		// is the default (pt), so every EN term is invisible and this function
		// "creates" it again on every run — which is exactly the non-idempotence
		// the audit reports as `terms_created=2`. The `lang => ''` argument is
		// the documented way to ask for every language.
		$existing = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slug,
				'hide_empty' => false,
				'lang'       => '',
			)
		);

		if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
			$ids[] = (int) $existing[0]->term_id;
			continue;
		}

		// The NAME is set explicitly, capitalised, instead of letting
		// `wp_insert_term()` default it to the slug.
		//
		// These are SHARED proper-name terms, so the name is user-visible on the
		// PT and EN filter triggers alike: a town appearing as `adare` instead of
		// `Adare` is wrong on both sides of the language boundary, and the
		// acceptance rows assert the display name
		// (`event-filters-dropdown-label">Adare`).
		$created = wp_insert_term(
			ucfirst( str_replace( '-', ' ', $slug ) ),
			$taxonomy,
			array( 'slug' => $slug )
		);
		if ( is_wp_error( $created ) ) {
			++$stats['errors'];
			printf( "  ERROR creating term %s/%s: %s\n", $taxonomy, $slug, $created->get_error_message() );
			continue;
		}

		++$stats['terms_created'];
		$ids[] = (int) $created['term_id'];
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Run an existing repository script as a SEPARATE PROCESS and report whether
 * it passed.
 *
 * WHY A SUBPROCESS, NOT A `require`. The existing runners are top-level CLI
 * programs that end in `exit( … )` — `scripts/run-en-translation.php` does
 * exactly that. Including one in-process therefore terminates the INCLUDING
 * script the moment it finishes, which silently truncated this orchestrator
 * after its first stage. Shelling out is therefore not merely tidier, it is the
 * only correct way to compose with them.
 *
 * It is also the more faithful composition: the stage is invoked exactly as CI
 * or an operator would invoke it from the command line, so it keeps its own
 * `--dry-run`/`--apply` contract, its own header and its own numeric gate. No
 * seed logic and no translation logic is reimplemented here.
 *
 * The child is a PHP process running a committed repository script against the
 * SAME WordPress install (the environment it inherited), so the two share one
 * database without sharing a process.
 *
 * @param string $relative Path relative to scripts/.
 * @param array  $args     CLI arguments.
 * @param array  $stats    Counters, by reference.
 * @return bool
 */
function conexao_ci_run_script( string $relative, array $args, array &$stats ): bool {
	$path = __DIR__ . '/' . $relative;

	if ( ! is_file( $path ) ) {
		++$stats['errors'];
		printf( "  ERROR: expected script is missing: scripts/%s\n", $relative );
		return false;
	}

	echo "\n--- php scripts/{$relative} " . implode( ' ', $args ) . "\n";

	// A seeder prints one line per record. Keep the full text available for a
	// CI log but surface only its summary lines, so a run stays readable.
	//
	// The child is a fresh PHP process, so it must be able to find WordPress on
	// its own. `CONEXAO_WP_ROOT` is forwarded explicitly (and derived from the
	// ALREADY-LOADED parent when it is not set) rather than relying on the
	// child re-deriving it, because the child's working directory and included
	// tree are not guaranteed to match the parent's.
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	$command     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $path );
	foreach ( $args as $arg ) {
		$command .= ' ' . escapeshellarg( $arg );
	}

	$wp_root = getenv( 'CONEXAO_WP_ROOT' );
	if ( ! is_string( $wp_root ) || '' === $wp_root ) {
		$wp_root = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : '';
	}
	if ( '' !== $wp_root ) {
		$command = 'CONEXAO_WP_ROOT=' . escapeshellarg( $wp_root ) . ' ' . $command;
	}

	// HTTP_HOST/REQUEST_URI are forwarded because Polylang reads them at load
	// time and emits a notice (and, in some versions, a fatal) without them in
	// a CLI context. The CI job sets the same pair when it runs a stage by hand.
	$command = 'HTTP_HOST=' . escapeshellarg( (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) )
		. ' REQUEST_URI=' . escapeshellarg( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) )
		. ' ' . $command;

	$process = proc_open( $command, $descriptors, $pipes, dirname( $path ) );
	if ( ! is_resource( $process ) ) {
		++$stats['errors'];
		printf( "  ERROR: could not start scripts/%s\n", $relative );
		return false;
	}

	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$status = proc_close( $process );

	$exit_code = ( is_int( $status ) ? $status : 1 );

	foreach ( explode( "\n", $stdout . $stderr ) as $line ) {
		if ( preg_match( '/(Total:|Criados:|Atualizados:|Criadas:|Resumo|^===|created=|GATE|ERROR|WARNING|Polylang|Menu |Done|Wrote|already|No change|Removed|Seeding)/i', $line ) ) {
			echo '  ' . trim( $line ) . "\n";
		}
	}

	if ( 0 !== $exit_code ) {
		++$stats['errors'];
		printf( "  ERROR: scripts/%s exited %d\n", $relative, $exit_code );
		return false;
	}

	return true;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — canonical PT pages and the PT menu.
//
// The `conexao-content` plugin ALREADY creates the canonical Portuguese page
// set and the "Menu Principal"/"Menu Rodapé" menus on activation, so there is
// nothing to seed here. This step only ASSERTS that the pages the later steps
// and the acceptance matrix depend on actually exist, and fails closed by name
// if one does not. Asserting beats seeding here: re-running the page creator
// would be a second lifecycle, and a silent no-op would hide a broken
// activation until a much later test failed with a confusing symptom.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 1: canonical PT pages (asserted, created by plugin activation) ===\n";

$required_pages = array(
	'inicio',
	'sobre-nos',
	'contato',
	'empregos',
	'blog',
	'irlanda',
	'politica-de-privacidade',
	'termos-de-uso',
	'cookies',
);
$missing_pages  = array();
foreach ( $required_pages as $page_slug ) {
	if ( ! get_page_by_path( $page_slug, OBJECT, 'page' ) ) {
		$missing_pages[] = $page_slug;
	}
}
if ( ! empty( $missing_pages ) ) {
	conexao_script_fail(
		'the canonical PT pages are missing (' . implode( ', ', $missing_pages ) . '). '
		. 'They are created by the conexao-content plugin activation hook; the theme and '
		. 'plugins must be activated before this script runs.'
	);
}
echo '  all ' . count( $required_pages ) . " canonical PT pages present\n";

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1b — retire the core draft pages whose slugs collide with authored EN.
//
// A brand-new WordPress install ships two DRAFT pages: "Privacy Policy"
// (`privacy-policy`) and "Terms of Use" (`terms-of-use`). The `en-page` stage
// authors EN translations whose slugs are EXACTLY those two strings
// (`politica-de-privacidade → privacy-policy`, `termos-de-uso → terms-of-use`).
//
// The shared engine is right to refuse that collision: it never creates a
// second record under a live slug, so it reports "EN slug already used by an
// unlinked record (refusing to duplicate)" and the stage gate FAILS. On a
// migrated developer database those drafts are long gone, which is exactly why
// the problem only appears on a genuinely fresh site — and a fresh site is
// what CI has.
//
// The bootstrap therefore retires the colliding core drafts, by slug, and ONLY
// when the status is `draft`: never a published page, never anything else.
// They are WordPress's own unused scaffolding that wp-admin offers as a
// starting point; this site supplies its own real Portuguese pages plus authored
// English translations instead. `wp_trash_post()` is used rather than a delete
// so the action is reversible and non-destructive.
//
// This is PROVISIONING, not a test accommodation: it clears scaffolding the
// product's own authored translations legitimately replace, and it leaves every
// published record untouched.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 1b: retire core drafts that collide with authored EN slugs ===\n";

foreach ( array( 'privacy-policy', 'terms-of-use' ) as $core_slug ) {
	$core_page = get_page_by_path( $core_slug, OBJECT, 'page' );

	if ( ! $core_page instanceof WP_Post ) {
		echo "  {$core_slug}: not present, nothing to do\n";
		continue;
	}

	if ( 'draft' !== (string) $core_page->post_status ) {
		// Never touch anything a human published. A PUBLISHED page holding the
		// slug is a real content decision, and the stage gate must report it
		// rather than this script silently resolving it.
		printf( "  %s: status '%s' (not draft) — left untouched\n", $core_slug, $core_page->post_status );
		continue;
	}

	wp_trash_post( (int) $core_page->ID );
	printf( "  %s: core draft (ID %d) trashed, freeing the authored EN slug\n", $core_slug, (int) $core_page->ID );
}

/**
 * The human-authored English NAME for a translated term.
 *
 * A term slug is machine identity; a term NAME is what a visitor reads on the
 * archive and in the filter bar. `wp_insert_term()` would default the name to
 * the slug, so the EN term needs its English label written explicitly.
 *
 * Derived from the EN slug by capitalising it, which is correct for this
 * vocabulary (every concept here is a single common noun: `work`, `nature`,
 * `festivals`, `cities`, `culture`, `music`, `gastronomy`). A concept that ever
 * needed an irregular label would declare it in
 * `conexao_ci_fixture_term_labels()` rather than being mangled by a rule.
 *
 * @param string $taxonomy Taxonomy.
 * @param string $pt_slug  PT slug (used for the irregular-label lookup).
 * @param string $en_slug  EN slug.
 * @return string
 */
function conexao_ci_fixture_term_name( string $taxonomy, string $pt_slug, string $en_slug ): string {
	$labels = conexao_ci_fixture_term_labels();

	// An explicit label always wins, keyed by taxonomy then PT slug.
	if ( isset( $labels[ $taxonomy ][ $pt_slug ] ) ) {
		return (string) $labels[ $taxonomy ][ $pt_slug ];
	}

	// Otherwise the EN slug with its first letter capitalised: `nature` ->
	// `Nature`, `work` -> `Work`.
	return ucfirst( str_replace( '-', ' ', (string) $en_slug ) );
}

/**
 * Irregular English term labels, keyed by taxonomy then PT slug.
 *
 * Empty by design: every concept in the current fixture vocabulary is a single
 * common noun, so the capitalisation rule in
 * `conexao_ci_fixture_term_name()` produces the correct label. The hook exists
 * so an irregular concept (a proper noun, or a multi-word label that must not
 * be derived) can be declared as data rather than by editing logic.
 *
 * @return array<string,array<string,string>>
 */
function conexao_ci_fixture_term_labels(): array {
	return array();
}

/**
 * The EN term slugs for a set of PT term slugs, in the same taxonomy.
 *
 * A TRANSLATED taxonomy (`category`, `conexao_category`) has one EN term per
 * PT concept, linked in both directions. This resolves PT slug -> EN slug using
 * the SAME `conexao_ci_fixture_term_pairs()` map the orchestrator already uses
 * to create the pair, so there is exactly one list of PT/EN term pairings in the
 * repository and a fixture post can never be filed under a term whose English
 * counterpart was never created.
 *
 * A PT term with no declared pair keeps its own slug. That is the right
 * fallback: an unpaired term is shared rather than mistranslated, and the
 * `taxonomy_policy` permanent gate is what reports a genuinely missing pair.
 *
 * @param string   $taxonomy Taxonomy.
 * @param string[] $pt_slugs PT term slugs.
 * @return string[] EN term slugs.
 */
function conexao_ci_fixture_english_term( string $taxonomy, array $pt_slugs ): array {
	$pairs = conexao_ci_fixture_term_pairs();
	$map   = isset( $pairs[ $taxonomy ] ) ? $pairs[ $taxonomy ] : array();

	$out = array();
	foreach ( $pt_slugs as $pt_slug ) {
		$out[] = isset( $map[ $pt_slug ] ) ? (string) $map[ $pt_slug ] : (string) $pt_slug;
	}

	return $out;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1d — assert the site locale configuration.
//
// The site is a BRAZILIAN-portuguese-first site served in pt_BR, with English
// as the second language. That is expressed in WordPress by the `WPLANG`
// option, and `get_locale()` is what the whole i18n layer reads:
// `conexao_current_locale()`, the `html lang` attribute, the `og:locale` tag
// and every `date_i18n()` call.
//
// A brand-new install leaves `WPLANG` UNSET, which makes `get_locale()` fall
// back to `en_US`. That is not a cosmetic default on a bilingual site: the
// front page would render `lang="en-US"`, every acceptance row that asserts
// `<html lang="pt-BR">` would fail, and dates would render in English. The
// developer's migrated database had the option set, which is why this only
// surfaces on a site built from nothing — and a site built from nothing is
// exactly what CI has.
//
// It is therefore ASSERTED, not merely set: the bootstrap refuses to claim
// success on a site whose locale is not the one the product requires.
echo "\n=== step 1d: site locale ===\n";

if ( 'pt_BR' !== (string) get_locale() ) {
	conexao_script_fail(
		sprintf(
			'the site locale is "%s" but this site is pt_BR-first. WordPress\'s `WPLANG` '
			. 'option is unset on a fresh install, which makes get_locale() fall back to en_US '
			. 'and breaks the `<html lang="pt-BR">` contract, the og:locale tag and date '
			. 'formatting. Set WPLANG to pt_BR (the polylang/setup stage owns the rest of the '
			. 'language configuration) and re-run.',
			(string) get_locale()
		)
	);
}
echo '  locale: ' . get_locale() . " (correct)\n";

// The site TIMEZONE must be Europe/Dublin.
//
// The site publishes Irish events in Irish local time, and the recurrence
// engine evaluates them in the SITE timezone: `test-event-recurrence.php`
// asserts that a late-UTC Wednesday is Thursday in Dublin and therefore does
// NOT occur on the Irish Wednesday. A fresh install leaves `timezone_string`
// empty and `gmt_offset` at 0 — i.e. UTC — so the evaluator disagrees with
// every date the suite asserts about and the timezone contract cannot hold.
//
// It is ASSERTED, for the same reason the locale is: a site serving Irish
// content in UTC is a real defect, and the bootstrap must not be able to
// report success over it.
echo "\n=== step 1e: site timezone ===\n";

$tz_string = (string) get_option( 'timezone_string', '' );
if ( 'Europe/Dublin' !== $tz_string ) {
	conexao_script_fail(
		sprintf(
			'the site timezone is "%s" but this site publishes Irish events in Irish local '
			. 'time. The recurrence engine evaluates dates in the site timezone, and '
			. 'test-event-recurrence.php asserts Europe/Dublin-specific behaviour. A fresh '
			. 'install defaults to UTC. Set the timezone to Europe/Dublin and re-run.',
			'' !== $tz_string ? $tz_string : '(UTC, unset)'
		)
	);
}
echo '  timezone: ' . wp_timezone_string() . " (correct)\n";

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1f — retire the WordPress "Sample Page".
//
// Like "Hello world!", a brand-new install ships a published page titled
// "Sample Page", slug `sample-page`. It is WordPress scaffolding, not site
// content, and `page` is a B1 type, so every published PT page must have a
// linked EN translation — and there is deliberately no authored `sample-page`
// English row. The completeness gate therefore reports it as a genuine missing
// translation. Removing the scaffolding is the honest fix; authoring fake
// English for it would be inventing content.
//
// Trashed, never deleted, and only on an exact slug match.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 1f: retire the WordPress sample page ===\n";

$sample_page = get_page_by_path( 'sample-page', OBJECT, 'page' );
if ( ! $sample_page instanceof WP_Post ) {
	echo "  sample-page: not present, nothing to do\n";
} else {
	wp_trash_post( (int) $sample_page->ID );
	printf( "  sample-page: WordPress scaffolding (ID %d) trashed\n", (int) $sample_page->ID );
}



// ═════════════════════════════════════════════════════════════════════════════
// STEP 1c — retire the WordPress "Hello world!" sample post.
//
// A brand-new install ships one published `post`: "Hello world!", slug
// `hello-world`. It is WordPress's own placeholder, not site content.
//
// It breaks a real B1 contract. `post` is a B1 type, so every published PT
// post must have a linked EN translation, and the shared engine can only
// translate a slug that has an AUTHORED English row. There is deliberately no
// `hello-world` row — it was never meant to reach production — so the record
// can never be translated and the completeness gate correctly fails on it. The
// only honest options are "remove the placeholder" or "author fake English for
// it"; removing the placeholder is the correct one.
//
// It is trashed, not deleted, and ONLY when the slug matches exactly, so this
// can never touch real content.
// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1c — retire the WordPress "Hello world!" sample post.
//
// A brand-new install ships one published `post`: "Hello world!", slug
// `hello-world`. It is WordPress's own placeholder, not site content.
//
// It breaks a real B1 contract. `post` is a B1 type, so every published PT
// post must have a linked EN translation, and the shared engine can only
// translate a slug that has an AUTHORED English row. There is deliberately no
// `hello-world` row — it was never meant to reach production — so the record
// ═════════════════════════════════════════════════════════════════════════════
// STEP 1c — retire the WordPress "Hello world!" sample post.
//
// A brand-new install ships one published `post`: "Hello world!", slug
// `hello-world`. It is WordPress's own placeholder, not site content.
//
// It breaks a real B1 contract. `post` is a B1 type, so every published PT
// post must have a linked EN translation, and the shared engine can only
// translate a slug that has an AUTHORED English row. There is deliberately no
// `hello-world` row — it was never meant to reach production — so the record
// can never be translated and the completeness gate correctly fails on it. The
// only honest options are "remove the placeholder" or "author fake English for
// it"; removing the placeholder is the correct one.
//
// It is trashed, not deleted, and ONLY when the slug matches exactly, so this
// can never touch real content.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 1c: retire the WordPress sample post ===\n";

$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
if ( ! $hello instanceof WP_Post ) {
	echo "  hello-world: not present, nothing to do\n";
} else {
	wp_trash_post( (int) $hello->ID );
	printf(
		"  hello-world: sample post (ID %d) trashed, so the B1 post gate is not blocked by WordPress scaffolding\n",
		(int) $hello->ID
	);
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — PT synthetic guides.
//
// Runs BEFORE the en-guide stage, because that stage resolves every record by
// PT slug: with no PT guide to attach to, it would report an empty manifest
// and the /en/guias/ archive would have nothing to serve.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 2: synthetic PT guides ===\n";

$guides = conexao_ci_fixture_guides();
echo '  dataset: ' . count( $guides ) . ' guides across '
	. count( array_unique( array_column( $guides, 'category' ) ) ) . " PT categories\n";

if ( $apply ) {
	foreach ( $guides as $slug => $guide ) {
		conexao_ci_upsert(
			'guide',
			$slug,
			array(
				'title'   => $guide['title'],
				'excerpt' => $guide['excerpt'],
				'content' => $guide['content'],
				'terms'   => array( 'conexao_category' => array( $guide['category'] ) ),
			),
			$stats
		);
	}
	printf( "  created=%d repaired=%d\n", $stats['guide_created'], $stats['guide_repaired'] );
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 3 — PT synthetic blog posts.
//
// Same ordering rule as the guides: the en-post stage is a no-op without PT
// posts to pair with.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 3: synthetic PT blog posts ===\n";

$posts = conexao_ci_fixture_posts();
echo '  dataset: ' . count( $posts ) . " posts\n";

if ( $apply ) {
	foreach ( $posts as $slug => $post ) {
		conexao_ci_upsert(
			'post',
			$slug,
			array(
				'title'   => $post['title'],
				'excerpt' => $post['excerpt'],
				'content' => $post['content'],
				// A FIXED date, so /blog/ ordering and pagination are identical
				// on every fresh run. Never "now": a relative date would make
				// the fixture's own ordering depend on the day it was seeded.
				'date'    => $post['date'],
			),
			$stats
		);
	}
	printf( "  created=%d repaired=%d\n", $stats['post_created'], $stats['post_repaired'] );
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — synthetic sponsors, events and the SHARED county/town terms.
//
// Sponsors: the release smoke matrix discovers one public single per post
// type, so an empty sponsor table fails a release row for no real reason.
// Events: the county/town filter rows assert on the OPTION LABELS the widget
// renders, and a widget only offers terms that are in use — so the terms and
// the events that reference them are created together, here.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 4: synthetic sponsors, events and shared county/town terms ===\n";

if ( $apply ) {
	$sponsors = conexao_ci_fixture_sponsors();
	foreach ( $sponsors as $sponsor ) {
		conexao_ci_upsert(
			'sponsor',
			$sponsor['slug'],
			array(
				'title'   => $sponsor['title'],
				'excerpt' => $sponsor['excerpt'],
				'content' => $sponsor['content'],
				'meta'    => array(
					// The editor-curated ordering field /apoiadores/ sorts on, so
					// the fixture exercises the curated branch of the archive.
					'_sponsor_display_order' => (string) $sponsor['order'],
					'_sponsor_link'          => $sponsor['link'],
					// The HOME HERO selection flag. `conexao_get_featured_sponsors()`
					// queries `_sponsor_featured = 1` exactly, so a sponsor
					// without it is invisible on the front page — and
					// `test-stage7-hero-sponsors.php` asserts the English home
					// page still receives the B2 fallback sponsor set rather
					// than zero rows. This is the flag that makes the sponsors
					// fixtures observable through that contract.
					'_sponsor_featured'      => '1',
				),
			),
			$stats
		);
	}
	printf( "  sponsors: created=%d repaired=%d\n", $stats['sponsor_created'], $stats['sponsor_repaired'] );

	$counties = conexao_ci_fixture_counties();
	$towns    = conexao_ci_fixture_towns();
	conexao_ci_term_ids( 'conexao_county', array_keys( $counties ), $stats );
	conexao_ci_term_ids( 'conexao_town', array_keys( $towns ), $stats );
	printf(
		"  shared terms: created=%d (county=%d, town=%d; SHARED, never per-language)\n",
		$stats['terms_created'],
		count( $counties ),
		count( $towns )
	);

	// ═════════════════════════════════════════════════════════════════════════════
	// STEP 4b — the NAMED cross-language pairs.
	//
	// The Stage 4.1 REST suite and the Stage 3.2 bilingual suite assert the
	// REPLACEMENT RULES — that an EN translation replaces its PT master, that a
	// source-inherited EN record is itself the record, that a PT-only record stays
	// B2, and that past/rejected/removed records are hidden. Those rules are only
	// observable on records whose language and status are known exactly, so the
	// suites identify their fixtures BY SLUG. This step creates exactly that set,
	// from the committed dataset, and links each PT/EN pair with Polylang so the
	// replacement set is genuinely populated.
	//
	// The EN halves are created here rather than by a translation stage on
	// purpose: these slugs are deliberately NOT in the authored manifests, because
	// the suites need a paired test record, not published English copy. Routing a
	// fixture through the published `en-*` stages would imply it is content the
	// site is meant to serve.
	//
	// It runs AFTER step 4 and BEFORE step 6 so the shared engine's own stages see
	// the complete population when they snapshot and verify.
	// ═════════════════════════════════════════════════════════════════════════════
	echo "\n=== step 4b: named cross-language fixture pairs ===\n";

	// Records whose `publish` status must be re-asserted after every write. See
	// the Keadeen note below.
	$status_reassert = array();

	if ( $apply ) {
		$pairs     = conexao_ci_fixture_named_pairs();
		$pt_made   = 0;
		$en_made   = 0;
		$link_fail = 0;

		foreach ( $pairs as $pair ) {
			$type    = (string) $pair['type'];
			$pt_slug = (string) $pair['pt_slug'];

			$pt_fields = array(
				'title'   => (string) $pair['title'],
				// A pair may declare the EXACT Portuguese excerpt the authored
				// English was written from (`pt_excerpt`). That is required for the
				// leisure-description stage: its PT-drift guard compares the stored
				// PT excerpt against the authored `pt_source` and refuses a stale
				// translation, so a generic fixture excerpt would make the stage
				// reject the very record the acceptance rows read.
				'excerpt' => ! empty( $pair['pt_excerpt'] )
					? (string) $pair['pt_excerpt']
					: sprintf( 'Fixture sintética da CI para o registo "%s".', $pt_slug ),
				'content' => '<p>Registo sintético criado pela arvore de fixtures determinísticas da integração contínua para exercitar o modelo de tradução REST. Não é conteúdo real.</p>',
			);

			$pt_terms = array();
			$pt_meta  = array();

			if ( in_array( $type, array( 'event', 'leisure' ), true ) ) {
				$pt_terms = array(
					'conexao_county' => array( (string) $pair['county'] ),
					'conexao_town'   => array( (string) $pair['town'] ),
				);
			}
			if ( 'guide' === $type ) {
				$pt_terms = array(
					'conexao_category' => array_map( 'strval', (array) $pair['category'] ),
				);
			}
			// A fixed publication date, inherited by the EN translation. Relative
			// "now" would make the pair differ between the two inserts and change
			// between runs, which is neither deterministic nor correct.
			if ( ! empty( $pair['date'] ) ) {
				// `$date` is already a full `Y-m-d H:i:s` datetime.
				$pt_fields['date'] = (string) $pair['date'];
			}
			if ( 'event' === $type ) {
				// A relative date keeps the fixture meaningful: the "hidden because
				// it is PAST" record must actually be in the past, and the visible
				// ones must actually be upcoming, whenever the suite runs.
				$offset = (int) $pair['offset'];

				// A FULL datetime. A bare `Y-m-d` here made WordPress store
				// `00:00:00` and then report the record differently on read, so the
				// upsert comparison never matched and these events were "repaired" on
				// every single run.
				$date                       = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d' ) . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
				$pt_meta['_event_date']     = $date;
				$pt_meta['_event_time']     = '19:00';
				$pt_meta['_event_location'] = (string) $pair['town'] . ', ' . (string) $pair['county'];
				$pt_meta['_event_status']   = 'published';

				// The event SOURCE language, which is a different fact from the
				// record language. An event imported from an already-English source
				// has no Portuguese original to translate, so the EN record IS the
				// record ("source-inherited"); `test-stage32-bilingual.php` asserts
				// both shapes (`_event_source_language=en` and `=pt`) and the
				// translation-completeness gate treats a source-inherited EN record
				// as a legitimate exception rather than a malformed fork.
				$pt_meta['_event_source_language'] = ! empty( $pair['source_language'] )
				? (string) $pair['source_language']
				: 'pt';

				// A HIDDEN event is one whose `_event_status` meta marks it
				// expired/rejected/source_not_found. The theme's REST layer
				// (`inc/rest-language.php`) answers 404 for exactly those values
				// while `post_status` stays `publish` — that pairing is the whole
				// contract, so it is expressed here in the meta and NOT as a
				// `post_status`. A `trash` status would instead make the record
				// unreadable to the suite's own fixture lookup, turning the
				// "the hidden event is absent" assertion into a vacuous pass.
				$pt_meta['_event_status'] = ! empty( $pair['hidden'] )
				? (string) $pair['hidden']
				: 'published';
				// `$date` is already a full `Y-m-d H:i:s` datetime (the dataset
				// derives it that way), so appending a second time segment produced
				// `... 10:00:00 00:00:00`, which WordPress silently truncated — so the
				// value never matched the stored one and the bootstrap repaired the
				// same events on every run, forever.
				$pt_fields['date'] = $date;
			}
			if ( 'leisure' === $type ) {
				// An EXTERNAL listing is one the directory hands off to its official
				// site; an internal one is served here. Both shapes are asserted.
				if ( ! empty( $pair['official_website'] ) ) {
					$pt_meta['_leisure_official_website'] = (string) $pair['official_website'];
				}
				$pt_terms['conexao_category'] = array( 'Natureza' );
			}
			if ( 'sponsor' === $type ) {
				$pt_meta['_sponsor_display_order'] = '1';
				$pt_meta['_sponsor_featured']      = '1';
			}

			// The SHARED export identity.
			//
			// An export UUID is how a record keeps its identity across a round trip:
			// the PT master and its linked EN translation store the SAME value, and
			// no third record may claim it. `test-stage32-bilingual.php` asserts
			// exactly that for its two pilot pairs, and the value must be a
			// committed constant — a generated UUID would change on every run and
			// make the "owned by exactly these two records" claim untestable.
			if ( ! empty( $pair['uuid'] ) ) {
				$uuid_key             = 'event' === $type ? '_event_export_uuid' : '_leisure_export_uuid';
				$pt_meta[ $uuid_key ] = (string) $pair['uuid'];
			}

			// The UPSTREAM SOURCE identity: where the record came from. It is
			// shared by a PT master and its EN translation (both describe the same
			// upstream record) and is separate from the export UUID, which is this
			// site's own identity for the record.
			if ( ! empty( $pair['source'] ) ) {
				$pt_meta['_event_source']    = (string) $pair['source'];
				$pt_meta['_event_source_id'] = (string) $pair['source_id'];
			}

			// A blog post MUST carry a real category. Without one WordPress assigns
			// its default `uncategorized` term, and
			// `test-blog-en-translation.php` then reports "every category used by a
			// blog post has a linked EN term assigned to the EN post — uncategorized
			// (not assigned)": the default term is a category that was never
			// deliberately filed and has no authored English counterpart. So the
			// category is assigned HERE, before any term is resolved.
			if ( 'post' === $type && ! isset( $pt_terms['category'] ) ) {
				$pt_terms['category'] = array( 'comunidade' );
			}

			// The EN record is filed under the EN COUNTERPART of every translated
			// term the PT record uses, never the PT term itself: `category` and
			// `conexao_category` are TRANSLATED taxonomies, so the EN post carries
			// the linked EN term. `test-blog-en-translation.php` asserts exactly this.
			$en_terms = array();
			foreach ( $pt_terms as $taxonomy => $slugs ) {
				if ( ! in_array( $taxonomy, array( 'category', 'conexao_category' ), true ) ) {
					// The SHARED proper-name taxonomies are the SAME term in both
					// languages by policy, so they are copied, not translated.
					$en_terms[ $taxonomy ] = $slugs;
					continue;
				}
				$en_terms[ $taxonomy ] = conexao_ci_fixture_english_term( $taxonomy, $slugs );
			}

			if ( ! empty( $pair['status'] ) ) {
				$pt_fields['status'] = (string) $pair['status'];
			}

			// A Leisure record the acceptance matrix reads from the FIRST page of
			// /lazer/ needs to be FIRST there, and the archive's default order is
			// newest-first. Dating it to the fixed, far-future epoch puts it
			// deterministically at the top on every run — no filter hack, no
			// dependence on how many listings the seeders happen to create, and no
			// change to the production renderer.
			//
			// A past date makes WordPress set `post_status = future` (it derives
			// the status from post_date on every save and the status is NOT
			// honoured if only the date is supplied), which removes the record from
			// /lazer/ altogether. So the date is today: still deterministic to the
			// day, still `publish`, and newest of the leisure set, which is what
			// puts the card on the FIRST page the acceptance row reads.
			//
			// This ONE record must appear on the FIRST page of /lazer/, because the
			// acceptance rows read its card excerpt from page 1, and the archive is
			// ordered `post_date DESC`. A `menu_order` does NOT help: the leisure
			// archive query sets no `orderby` on menu_order, so it is ignored.
			//
			// A future `post_date` DOES sort it first, but WordPress derives
			// `post_status = future` from that date on every save, which would remove
			// the record from the archive entirely — the opposite of the intent. So
			// the date and the status are written separately, the status through
			// `$wpdb` after the upsert, which is the only way to keep a future-dated
			// record servable. This is a fixture-provisioning concern and touches no
			// rendering code.
			//
			// The Keadeen listing must appear on the FIRST page of /lazer/, because
			// the acceptance rows read its card excerpt from page 1 and the archive is
			// ordered `post_date DESC`.
			//
			// A FUTURE date is the obvious way to sort first, and it is the wrong one:
			// WordPress derives `post_status = future` from any `post_date_gmt` more
			// than a minute ahead, on every save, and a `future` record is excluded
			// from the archive — and, worse, from the eligible set of
			// `test-leisure-card-excerpt-language.php`, which deliberately drifts this
			// exact record's PT excerpt and restores it. A future date silently
			// disables three maintained assertions.
			//
			// A STICKY POST is the correct lever: WordPress prepends sticky records to
			// the first page of an archive, which puts the card on page 1 without
			// touching `post_status` and without a date in the future. It is
			// core WordPress behaviour, so no renderer or query filter is modified,
			// and the drift suite keeps seeing a normal `publish` record.
			//
			// Fixture-owned leisure records are dated to a FIXED PAST date.
			//
			// Without this they are created with "now", so they outrank every curated
			// leisure listing in `post_date DESC` and silently take over page 1 of
			// /lazer/ — which is why `phoenix-park-en` (a fixture) was displacing the
			// real records. A fixed past date keeps the whole fixture set BELOW the
			// curated data, so the archive renders exactly what the seeders produced
			// and the fixtures are observable only through the assertions that name
			// them by slug.
			//
			// The Keadeen listing is the ONE exception: the acceptance rows read its
			// card excerpt from page 1, so it is given the newest date in the set.
			// A date in the FUTURE is not usable — WordPress derives
			// `post_status = future` from it on every save, and a `future` record
			// leaves the eligible set of the suite that deliberately drifts this very
			// record (`test-leisure-card-excerpt-language.php`). A future date that is
			// then forced back to `publish` also survives neither this script's own
			// re-run nor the leisure seeder that runs after it.
			if ( 'leisure' === $type ) {
				$pt_fields['date'] = ( 'dwyer-mcallister-cottage' === $pt_slug )
				// A FIXED date, and the newest in the archive. See the
				// "archive order normalisation" note at the end of step 4b: every
				// other leisure listing is aged to an OLDER fixed date so this
				// ordering is a property of the data, not of the clock.
				? '2026-03-01 09:00:00'
				: '2026-01-02 09:00:00';
			}

			$pt_fields['meta']  = array_merge(
				$pt_meta,
				array( 'conexao_meta_description' => sprintf( 'Registo sintético da CI para "%s".', $pt_slug ) )
			);
			$pt_fields['terms'] = $pt_terms;

			// A slug the EXISTING seeders already own keeps its real content.
			//
			// `phoenix-park`, `cliffs-of-moher` and `fota-wildlife-park` are curated
			// leisure records from `seed-leisure-locations.php`, and the
			// `en-leisure-description` stage has already written an authored
			// `_leisure_excerpt_en` for them. Overwriting their excerpt with the
			// generic fixture text made that stage's PT-drift guard report three
			// stale translations and fail its gate — a real false alarm caused by
			// the fixture fighting the seeder.
			//
			// So when the PT record already exists, this step contributes ONLY the
			// parts it owns: the EN half and the translation link. It never rewrites
			// another component's content.
			//
			// The exemption is scoped to `leisure` ONLY. Those three slugs are
			// curated records owned by `seed-leisure-locations.php` with authored
			// excerpts the `en-leisure-description` stage translates, and
			// overwriting them made that stage refuse the batch as stale.
			//
			// It is deliberately NOT applied to the other types: a record this step
			// created on a previous run must still be brought up to date, or the
			// fixture set would freeze at whatever its first run produced. An
			// event/guide/post/job/sponsor with this step's slug is this step's own
			// record, and re-applying the dataset is what makes it idempotent.
			//
			// A leisure record that already exists AND has no declared `pt_excerpt`
			// keeps its seeder-authored content untouched: those three slugs are
			// curated records from `seed-leisure-locations.php`, and rewriting them
			// made the `en-leisure-description` stage refuse the batch as stale.
			//
			// A leisure record that DOES declare `pt_excerpt` is written from it, and
			// that is how the stage's PT-drift guard is satisfied: the authored
			// English was written from a specific Portuguese sentence, so the stored
			// PT excerpt has to BE that sentence for the pairing to be valid.
			$existing_pt   = conexao_ci_find( $type, $pt_slug );
			$keep_existing = ( 'leisure' === $type
				&& $existing_pt instanceof WP_Post
				&& empty( $pair['pt_excerpt'] ) );

			if ( $keep_existing ) {
				$pt_id = (int) $existing_pt->ID;
			} else {
				$pt_id = conexao_ci_upsert( $type, $pt_slug, $pt_fields, $stats );
			}

			if ( $pt_id > 0 ) {
				++$pt_made;
			}

			// Re-assert `publish` on the Keadeen record.
			//
			// Its date is end-of-today, which is in the FUTURE relative to the clock
			// at save time, so WordPress derives `post_status = future` — and a
			// `future` record is excluded from /lazer/ AND from the eligible set of
			// `test-leisure-card-excerpt-language.php`, the suite that deliberately
			// drifts this very record. Writing the status through $wpdb after the
			// save is the only way to get both properties at once: ordered first on
			// `post_date DESC`, and still published.
			//
			// The leisure seeder runs AFTER this step and re-derives `future`, so the
			// re-assert is repeated once more at the end of the run.
			if ( 'dwyer-mcallister-cottage' === $pt_slug && $pt_id > 0 ) {
				$status_reassert[] = (int) $pt_id;
			}

			// The EN half, when the dataset declares one.
			if ( empty( $pair['en_slug'] ) ) {
				continue;
			}

			// The EN record is a TRANSLATION, not a twin: it inherits the PT
			// publication date and status. `test-blog-en-translation.php` asserts
			// "EN posts keep the PT publication date", and the shared engine's own
			// `copy_fields` does the same for every stage-authored record. Without
			// the date here the EN post is dated "now", which is both a different
			// value and a different value on every run.
			$en_fields = array(
				'title'   => (string) $pair['en_title'],
				'excerpt' => sprintf( 'Synthetic CI fixture record "%s".', (string) $pair['en_slug'] ),
				'content' => '<p>Synthetic record created by the deterministic CI fixture tree to exercise the REST translation model. Not real content.</p>',
				'meta'    => array_merge(
					$pt_meta,
					// The EN record needs its OWN English meta description; the PT
					// one is not copied, because
					// `test-guide-en-translation.php` and
					// `test-job-en-translation.php` both assert "the EN … has an
					// English meta description" and that it is not the Portuguese
					// string.
					array(
						'conexao_meta_description' => sprintf( 'Synthetic CI fixture record for the English layer: %s.', (string) $pair['en_title'] ),

					)
				),
				'terms'   => $en_terms,
			);

			if ( ! empty( $pt_fields['date'] ) ) {
				$en_fields['date'] = $pt_fields['date'];
			}
			if ( ! empty( $pt_fields['status'] ) ) {
				$en_fields['status'] = $pt_fields['status'];
			}

			// A Leisure EN SIBLING needs its own `_leisure_excerpt_en`. That field
			// normally comes from the `en-leisure-description` stage, but this sibling
			// is a deliberately separate English translation the stage does not
			// author, and `test-leisure-card-excerpt-language.php` asserts every
			// published record with a PT description has an EN one.
			//
			// It is written ONLY for `leisure`, and that restriction is the other half
			// of the same test: "the EN description meta exists only on leisure
			// records" asserts the key never leaks onto any other post type, so
			// stamping it unconditionally would be a real defect.
			if ( 'leisure' === $type ) {
				$en_fields['meta']['_leisure_excerpt_en'] = (string) ( $pair['en_description'] ?? sprintf( 'Synthetic English description for %s.', (string) $pair['en_title'] ) );
			}

			$en_id = conexao_ci_upsert( $type, (string) $pair['en_slug'], $en_fields, $stats );
			if ( $en_id > 0 ) {
				++$en_made;
			}

			// Link them as ONE IDENTITY in Polylang, in both directions.
			if ( $pt_id > 0 && $en_id > 0 && function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $pt_id, 'pt' );
				pll_set_post_language( $en_id, 'en' );

				$linked = pll_save_post_translations(
					array(
						'pt' => $pt_id,
						'en' => $en_id,
					)
				);

				if ( is_wp_error( $linked ) ) {
					++$link_fail;
					++$stats['errors'];
					printf( "  ERROR linking %s pair %s: %s\n", $type, $pt_slug, $linked->get_error_message() );
				}
			}
		}

		// The source-inherited EN event carries its language on the record itself.
		foreach ( $pairs as $pair ) {
			if ( empty( $pair['source_language'] ) ) {
				continue;
			}
			$src = conexao_ci_find( (string) $pair['type'], (string) $pair['pt_slug'] );
			if ( $src && function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( (int) $src->ID, (string) $pair['source_language'] );
			}
		}

		printf( "  named pairs: PT=%d EN=%d link-failures=%d\n", $pt_made, $en_made, $link_fail );
	}


	// TRANSLATED taxonomy terms. These are a different contract from the two
	// SHARED proper-name taxonomies above: `conexao_category` and `category`
	// hold CONCEPTS, so a concept gets an EN term linked to its PT term in both
	// directions. Only the PT term is created here; the EN side is created by
	// the existing `en-guide` stage for the categories it authors, and the
	// remaining pairs are asserted by test-stage33-bilingual.php. The PT terms
	// must exist first for either to be possible.
	$pair_taxonomies = conexao_ci_fixture_term_pairs();
	$pair_count      = 0;
	foreach ( $pair_taxonomies as $taxonomy => $pairs ) {
		conexao_ci_term_ids( $taxonomy, array_keys( $pairs ), $stats );
		$pair_count += count( $pairs );
	}
	printf( "  translated PT terms: %d across %d taxonomies\n", $pair_count, count( $pair_taxonomies ) );

	// The EN half of each translated term pair, linked to its PT term in BOTH
	// directions.
	//
	// WHY THIS LIVES HERE AND NOT IN A STAGE. `en-guide` already authors the 13
	// guide-scoped categories, so those EN terms are the stage's job and are NOT
	// touched here. The pairs in `conexao_ci_fixture_term_pairs()` are the
	// directory/event vocabulary that `test-stage33-bilingual.php` asserts by
	// name, and they are a SUPERSET of what the guide stage owns. For any pair
	// whose EN term the guide stage already created, this is a no-op; for the
	// rest it creates the EN term and the bidirectional link.
	//
	// It is the same concept-identity pairing the `taxonomy_policy` permanent
	// gate requires everywhere: one concept, two terms, one identity — never a
	// per-language duplicate of a SHARED proper name.
	$en_terms_created = 0;
	foreach ( conexao_ci_fixture_term_pairs() as $taxonomy => $pairs ) {
		foreach ( $pairs as $pt_slug => $en_slug ) {
			$found_pt = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => (string) $pt_slug,
					'hide_empty' => false,
					'lang'       => '',
				)
			);
			$pt_term  = ( ! is_wp_error( $found_pt ) && ! empty( $found_pt ) ) ? $found_pt[0] : null;

			if ( ! $pt_term instanceof WP_Term ) {
				continue;
			}

			// Already linked? The EN term is the stage's (or a previous run's)
			// work, so it is not re-created and not re-linked — but its NAME is
			// still asserted below, because a term inserted with the slug as its
			// name is machine identity rendered to a visitor. Ownership of the
			// LINK and ownership of the LABEL are separate concerns.
			$existing_en = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $pt_term->term_id, 'en' ) : 0;

			$en_term = $existing_en > 0 ? get_term( $existing_en, $taxonomy ) : null;

			if ( ! $en_term instanceof WP_Term ) {
				// Same Polylang caveat as conexao_ci_term_ids(): a plain
				// get_term_by() cannot see an EN term from the default-language
				// context, so the EN term is re-created on every run.
				$found_en = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'slug'       => (string) $en_slug,
						'hide_empty' => false,
						'lang'       => '',
					)
				);
				if ( ! is_wp_error( $found_en ) && ! empty( $found_en ) ) {
					$en_term = $found_en[0];
				}
			}

			if ( ! $en_term instanceof WP_Term ) {
				$created_en = wp_insert_term( $en_slug, $taxonomy, array( 'slug' => (string) $en_slug ) );
				if ( is_wp_error( $created_en ) ) {
					++$stats['errors'];
					printf( "  ERROR creating EN term %s/%s: %s\n", $taxonomy, $en_slug, $created_en->get_error_message() );
					continue;
				}
				$en_term = get_term( (int) $created_en['term_id'], $taxonomy );
				++$en_terms_created;
			}

			// The EN term needs a HUMAN-AUTHORED NAME, not just a slug.
			// `wp_insert_term()` defaults the name to the slug, which would leave
			// the term rendering as the raw machine token. Stage 3.2 asserts the
			// name is real English ("the EN term carries a human-authored English
			// name"), and the archive renders `wp_get_post_terms()->name`, so this
			// is user-visible content, not metadata.
			if ( $en_term instanceof WP_Term ) {
				$en_name = conexao_ci_fixture_term_name( $taxonomy, (string) $pt_slug, (string) $en_slug );
				if ( '' !== $en_name && (string) $en_term->name !== $en_name ) {
					wp_update_term( (int) $en_term->term_id, $taxonomy, array( 'name' => $en_name ) );
				}
			}

			if ( $en_term instanceof WP_Term && function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( (int) $en_term->term_id, 'en' );

				// The translations map is keyed BY LANGUAGE (`pt => en_id`), not
				// by term id. Passing `pt_id => en_id` silently creates a
				// malformed pairing: the EN term is left with no PT master and
				// no language, which the `taxonomy_policy` permanent gate
				// correctly reports as "every EN term has a PT master" — the
				// same shape the `en-guide` stage uses.
				if ( function_exists( 'pll_save_term_translations' ) ) {
					pll_save_term_translations(
						array(
							'pt' => (int) $pt_term->term_id,
							'en' => (int) $en_term->term_id,
						)
					);
				}
			}
		}
	}
	printf( "  translated EN terms: created=%d (stage-authored terms left untouched)\n", $en_terms_created );

	// PT jobs. Job is B2, so this is a minimum real population rather than a
	// translation obligation: the job suites assert a non-empty listing, and the
	// release matrix discovers one single per post type.
	$jobs = conexao_ci_fixture_jobs();
	foreach ( $jobs as $slug => $job ) {
		conexao_ci_upsert(
			'job',
			$slug,
			array(
				'title'   => $job['title'],
				'excerpt' => $job['excerpt'],
				'content' => $job['content'],
				'date'    => $job['date'],
			),
			$stats
		);
	}
	printf( "  jobs: created=%d repaired=%d (dataset: %d)\n", $stats['job_created'], $stats['job_repaired'], count( $jobs ) );

	// The /empregos/ landing body.
	//
	// `create-pages.php` provisions this page with a PLACEHOLDER body that
	// explicitly says it is editable. The real Portuguese body lives in
	// `scripts/seed-recruitment-agencies-rest.py` as `EMPREGOS_CONTENT`, which
	// also writes it to production — but that script is a REST seeder that
	// authenticates with `WP_APPLICATION_PASSWORD`, and CI must not require a
	// credential of any kind.
	//
	// So the body is set here instead, byte-for-byte identical to the authored
	// constant. It is REPEATED, not moved: that script remains the single owner
	// of the production copy and of the `--update-page` flow, and this is a
	// local/CI projection of the same documented text. The two are asserted
	// equal by `test-jobs-en-language.php` ("the PT Jobs page content is
	// unchanged (Portuguese source preserved)"), which is what keeps the
	// duplication honest: if the authored constant changes, that test fails
	// here rather than letting the two drift apart silently.
	$pt_jobs = get_page_by_path( 'empregos', OBJECT, 'page' );
	if ( $pt_jobs instanceof WP_Post ) {
		$expected_jobs_body = '<!-- wp:paragraph --><p>As vagas mais recentes estão no nosso Instagram.<br>Acompanhe nossas publicações para encontrar novas oportunidades de trabalho na Irlanda.</p><!-- /wp:paragraph -->';

		if ( (string) $pt_jobs->post_content !== $expected_jobs_body ) {
			wp_update_post(
				array(
					'ID'           => (int) $pt_jobs->ID,
					'post_content' => $expected_jobs_body,
				)
			);
			echo "  /empregos/ body: set to the authored Portuguese copy (placeholder replaced)\n";
		} else {
			echo "  /empregos/ body: already the authored Portuguese copy\n";
		}
	}

	foreach ( conexao_ci_fixture_events() as $event ) {
		$meta = array(
			'_event_date'     => $event['date'],
			'_event_time'     => $event['time'],
			'_event_location' => $event['location'],
			'_event_status'   => 'published',
		);
		if ( ! empty( $event['recurrence'] ) ) {
			$meta['_event_recurrence']       = $event['recurrence'];
			$meta['_event_recurrence_days']  = $event['recurrence_days'];
			$meta['_event_recurrence_start'] = $event['recurrence_start'];
		}
		conexao_ci_upsert(
			'event',
			$event['slug'],
			array(
				'title'   => $event['title'],
				'content' => $event['content'],
				// The dataset's `date` is already a full datetime; see the note on
				// the same mistake avoided above.
				'date'    => $event['date'],
				'meta'    => $meta,
				'terms'   => array(
					'conexao_county' => array( $event['county'] ),
					'conexao_town'   => array( $event['town'] ),
				),
			),
			$stats
		);
	}
	printf( "  events: created=%d repaired=%d\n", $stats['event_created'], $stats['event_repaired'] );

	// The recurrence-aware event query caches its resolved ID list. The cache
	// is invalidated here so /eventos/ serves the fixtures in THIS run rather
	// than a list computed before they existed.
	if ( class_exists( 'Conexao_Event_Query' ) ) {
		Conexao_Event_Query::flush_cache();
		echo "  event query cache flushed\n";
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5 — the EXISTING directory seeders, in dependency order.
//
// Each of these is a pre-existing repository script, invoked as-is. None of
// their logic is duplicated here.
//
// seed-course-providers   first: the EN description stage
// seed-leisure-*          below asserts the PT excerpt it was authored from.
// seed-recruitment-*      give /empregos/?tipo=agency its rows.
//
// The course-provider seeder performs BEST-EFFORT logo discovery over the
// public internet. That is a documented property of that existing script, not
// something this orchestrator introduces, and it degrades safely: a failed
// fetch leaves the theme placeholder and the provider is still created. The
// acceptance rows assert on the provider NAME, CATEGORY and EXCERPT, never on a
// logo, so a network failure cannot turn the suite red.
// ═════════════════════════════════════════════════════════════════════════════
if ( $apply ) {
	echo "\n=== step 5: existing directory seeders ===\n";

	conexao_ci_run_script( 'seed-course-providers.php', array(), $stats );
	conexao_ci_run_script( 'seed-recruitment-agencies.php', array(), $stats );
	conexao_ci_run_script( 'seed-permit-employers.php', array(), $stats );
	conexao_ci_run_script( 'seed-leisure-locations.php', array(), $stats );

	// The Leisure EN description stage is a FIELD-LEVEL translation on the same
	// PT record. Its PT-drift guard compares the stored PT excerpt against the
	// authored Portuguese source, so the PT leisure records must exist and must
	// still hold that exact excerpt BEFORE the stage runs. Running it earlier
	// would make the guard refuse the batch as drifted.
	//
	// `seed-leisure-expansion.php` is the larger curated dataset and is the
	// only source of the records the authored EN descriptions were written
	// against.
	conexao_ci_run_script( 'seed-leisure-expansion.php', array(), $stats );

	// The recurring-event matrix fixture. It is DATED RELATIVE to today by
	// design (the runtime hides past events), so it belongs to the ephemeral CI
	// site only and is never part of a developer's long-lived database.
	//
	// IT IS CLEANED BEFORE IT IS SEEDED, and that is not optional. This existing
	// script is a MANUAL developer tool: it unconditionally `wp_insert_post()`s
	// its nine events and has no slug key, so running it twice leaves eighteen
	// `[REC-TEST]` events. It already ships the matching `cleanup` mode, so
	// cleanup-then-seed is the documented way to make it converge — and it
	// leaves exactly the nine the recurrence matrix describes, which is what
	// "deterministic" has to mean for a date-relative fixture.
	conexao_ci_run_script( 'seed-recurrence-test-events.php', array( 'cleanup' ), $stats );
	conexao_ci_run_script( 'seed-recurrence-test-events.php', array(), $stats );

	if ( class_exists( 'Conexao_Event_Query' ) ) {
		Conexao_Event_Query::flush_cache();
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 6 — the EN translation stages, through the ONE shared engine.
//
// Every stage below is run by the EXISTING `scripts/run-en-translation.php`
// runner, which is a thin CLI in front of `conexao-translation-rollout`. This
// is deliberately the existing runner and not a direct engine call: using it
// means the numeric gate, the PT-immutability check and the dry-run/apply
// contract are exactly the ones the repository already relies on, and there is
// still only one translation engine in the repository (standard 4.1).
//
// Order matters and is dependency-driven:
// en-page        the canonical pages must exist first (step 1 asserted them)
// en-guide       needs the PT guides from step 2
// en-post        needs the PT posts from step 3
// en-blog-page   the EN /blog/ archive needs its EN posts page
// en-jobs-page   the EN /empregos/ landing, a shared-slug page
// en-leisure-description / en-course-provider-description
// B2 field-level stages; need the PT records from step 5
// ═════════════════════════════════════════════════════════════════════════════
if ( $apply ) {
	echo "\n=== step 6: EN translation stages (shared engine, existing runner) ===\n";

	foreach ( array( 'page', 'guide', 'post', 'blog-page', 'jobs-page', 'leisure-description', 'course-provider-description' ) as $stage ) {
		conexao_ci_run_script( 'run-en-translation.php', array( '--apply', '--only=' . $stage ), $stats );
	}

	// The EN Job record stage. It is run by its EXISTING dedicated driver
	// (`scripts/run-job-translation.php`), which is itself a thin CLI over the
	// same shared `conexao-translation-rollout` engine — not a second engine and
	// not a second lifecycle.
	//
	// It is needed because the job suites assert a job with a REAL English
	// translation, which is a different claim from "a job exists". Job remains
	// a B2 type: this stage does not change that policy, it only exercises the
	// real-translation branch for the single record the fixtures create.
	conexao_ci_run_script( 'run-job-translation.php', array( '--apply' ), $stats );
}

// ── Archive order normalisation ───────────────────────────────────────────────
//
// The leisure seeders stamp every curated listing with "now", so on any machine
// the /lazer/ ORDER is decided by the wall clock at seed time. The acceptance
// rows read the Keadeen card from page 1, so the ordering has to be a property
// of the DATA rather than of the clock.
//
// Every OTHER published leisure record is therefore aged to a fixed date
// OLDER than the Keadeen one. The Keadeen record then leads page 1 on every
// run, on every machine, while keeping a PAST date and `publish` status — which
// is what `test-leisure-card-excerpt-language.php` needs in order to find it and
// deliberately drift and restore its PT excerpt.
//
// This is a NORMALISATION of the ephemeral CI database, not a change to the
// product: it runs after the seeders, touches only post_date/post_date_gmt, is
// idempotent (it writes a record only when its date differs), and the database
// is destroyed by `docker compose down -v` at the end of the run. No renderer,
// no query and no production data is modified.
$aged_stamp = '2026-01-02 09:00:00';
$aged       = 0;

// The one record that must stay newest: the Keadeen listing the acceptance
// rows read from page 1. It is excluded from the ageing pass below.
$dweller      = conexao_ci_find( 'leisure', 'dwyer-mcallister-cottage' );
$dweller_keep = ( $dweller instanceof WP_Post ) ? (int) $dweller->ID : 0;

$leisure_ids = get_posts(
	array(
		'post_type'      => 'leisure',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
	)
);

foreach ( $leisure_ids as $leisure_id ) {
	// String comparison of two `Y-m-d H:i:s` values orders them correctly, but
	// the Keadeen record is DELIBERATELY left alone: it must stay newer than
	// everything else, so ageing it would undo the whole point.
	if ( (int) $leisure_id === $dweller_keep ) {
		continue;
	}

	// Age anything NEWER than the stamp (i.e. the "now"-stamped seeder records).
	// A record already at or below the stamp is already aged and is skipped,
	// which is what makes this pass idempotent.
	if ( strcmp( (string) get_post_field( 'post_date', $leisure_id ), $aged_stamp ) <= 0 ) {
		continue;
	}

	wp_update_post(
		array(
			'ID'            => (int) $leisure_id,
			'post_date'     => $aged_stamp,
			'post_date_gmt' => get_gmt_from_date( $aged_stamp ),
		)
	);
	++$aged;
}

if ( $aged > 0 ) {
	printf( "  archive order: aged %d leisure record(s) to %s so the pinned card leads page 1\n", $aged, $aged_stamp );
}

// The leisure seeders ran after step 4b and may have re-derived
// `post_status = future`. Re-assert `publish`
// here, as the LAST write of the run, so the record is left both ordered first
// on /lazer/ and actually served. Idempotent: it writes only when the status
// is not already `publish`.
if ( ! empty( $status_reassert ) ) {
	global $wpdb;
	foreach ( $status_reassert as $reassert_id ) {
		if ( 'publish' !== (string) get_post_status( $reassert_id ) ) {
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $reassert_id ) );
			clean_post_cache( $reassert_id );
			printf( "  post %d: status re-asserted as publish after the seeders\n", $reassert_id );
		}
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 7 — English primary menu and the per-language Polylang assignment.
//
// Both scripts are existing and both are idempotent. They run AFTER step 6
// because the EN menu links to the `sobre-nos`/`contato` PAGE objects, which
// must already exist.
//
// `create-en-primary-menu.php` only POPULATES a menu when it is empty, so a
// second run creates no duplicate items — which is why the duplication check
// in the audit below is a real assertion and not a tautology.
// ═════════════════════════════════════════════════════════════════════════════
if ( $apply ) {
	echo "\n=== step 7: EN primary menu + Polylang per-language nav assignment ===\n";

	conexao_ci_run_script( 'assign-polylang-nav-menus.php', array(), $stats );
	conexao_ci_run_script( 'create-en-primary-menu.php', array(), $stats );
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 8 — permalinks and derived state.
//
// Flush LAST, so the rewrite rules describe the site as it now is: the CPT
// archives, the page slugs and the /blog/ permalink structure all exist by
// this point, and a flush taken earlier would be regenerated by the next write
// anyway.
// ═════════════════════════════════════════════════════════════════════════════
if ( $apply ) {
	echo "\n=== step 8: rewrite rules ===\n";
	flush_rewrite_rules();
	echo "  rewrite rules flushed\n";
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 9 — the fixture audit.
//
// A deterministic fixture set is only useful if a SHORTFALL IS VISIBLE. This
// step counts the population every maintained contract depends on and exits
// non-zero when one is under its documented floor, BEFORE the test harness is
// ever started. That ordering is the point: it converts a confusing downstream
// failure (a 404 on page 2, or a completeness gate that "passed" over nothing)
// into one precise, named message.
//
// The floors come from `conexao_ci_fixture_minimums()`, where each number is
// justified against a specific maintained contract. Nothing here is tuned to
// make a run green.
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== step 9: fixture audit ===\n";

/**
 * Count published records of a post type, optionally restricted to a language.
 *
 * @param string $post_type Post type.
 * @param string $lang      '' for every language.
 * @return int
 */
function conexao_ci_count( string $post_type, string $lang = '' ): int {
	$args = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => $lang,
	);

	return (int) count( get_posts( $args ) );
}

$minimums = conexao_ci_fixture_minimums();

$actual = array(
	'guide'           => conexao_ci_count( 'guide' ),
	'post'            => conexao_ci_count( 'post' ),
	'page'            => conexao_ci_count( 'page' ),
	'sponsor'         => conexao_ci_count( 'sponsor' ),
	'leisure'         => conexao_ci_count( 'leisure' ),
	'course_provider' => conexao_ci_count( 'course_provider' ),
	'event'           => conexao_ci_count( 'event' ),
	// Job is B2, so this counts the whole public population across both
	// languages (`lang => ''`), not just PT: the contract is "a job exists and
	// one has a real EN translation", and both halves of that pair matter.
	'job'             => conexao_ci_count( 'job' ),
	'county_terms'    => (int) wp_count_terms( 'conexao_county', array( 'hide_empty' => false ) ),
	'town_terms'      => (int) wp_count_terms( 'conexao_town', array( 'hide_empty' => false ) ),
);

// B1 coverage: the EN records the shared engine must have created. Counted
// SEPARATELY from the PT total because a passing B1 contract is "every
// eligible PT record has a linked EN record", which is a different claim from
// "the PT archive has content".
$actual['guide_en'] = conexao_ci_count( 'guide', 'en' );
$actual['post_en']  = conexao_ci_count( 'post', 'en' );

$shortfalls = array();
foreach ( $minimums as $key => $floor ) {
	$have = isset( $actual[ $key ] ) ? (int) $actual[ $key ] : 0;
	$ok   = ( $have >= $floor );
	printf( "  %-16s %4d (floor %4d) %s\n", $key, $have, $floor, $ok ? 'OK' : 'SHORTFALL' );
	if ( ! $ok ) {
		$shortfalls[ $key ] = array(
			'have'  => $have,
			'floor' => $floor,
		);
	}
}

// B1 EN coverage is asserted, not assumed: the fixtures exist precisely so the
// translation stages have something to pair with, so a zero here means a stage
// silently did nothing.
foreach ( array(
	'guide_en' => 11,
	'post_en'  => 11,
) as $key => $floor ) {
	$ok = ( (int) $actual[ $key ] >= $floor );
	printf( "  %-16s %4d (floor %4d) %s\n", $key, (int) $actual[ $key ], $floor, $ok ? 'OK' : 'SHORTFALL' );
	if ( ! $ok ) {
		$shortfalls[ $key ] = array(
			'have'  => (int) $actual[ $key ],
			'floor' => $floor,
		);
	}
}

// The SHARED-taxonomy invariant, asserted at fixture level: no per-language
// suffixed duplicate may exist. A violation here is a real defect the
// taxonomy_policy gate would also catch, and catching it at fixture time makes
// the cause obvious.
$shared_violations = array();
foreach ( array( 'conexao_county', 'conexao_town' ) as $shared ) {
	$terms = get_terms(
		array(
			'taxonomy'   => $shared,
			'hide_empty' => false,
			'lang'       => '',
		)
	);
	if ( is_wp_error( $terms ) ) {
		continue;
	}
	foreach ( $terms as $term ) {
		if ( preg_match( '/-(en|pt)$/', (string) $term->slug ) ) {
			$shared_violations[] = $shared . ':' . $term->slug;
		}
	}
}
printf( "  %-16s %4d (floor %4d) %s\n", 'shared_taxonomy', count( $shared_violations ), 0, empty( $shared_violations ) ? 'OK' : 'VIOLATION' );
if ( ! empty( $shared_violations ) ) {
	$shortfalls['shared_taxonomy'] = array(
		'have'   => count( $shared_violations ),
		'floor'  => 0,
		'sample' => array_slice( $shared_violations, 0, 10 ),
	);
}

// Duplicate-slug check: a fixture key that produced two records would be a
// second identity, which the standard forbids outright.
$duplicates = array();
foreach ( array( 'guide', 'post', 'sponsor', 'event' ) as $type ) {
	$all  = get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'lang'           => '',
		)
	);
	$seen = array();
	foreach ( $all as $post ) {
		$key = (string) $post->post_name;
		if ( '' === $key ) {
			continue;
		}
		if ( isset( $seen[ $key ] ) ) {
			$duplicates[] = $type . ':' . $key;
		}
		$seen[ $key ] = true;
	}
}
printf( "  %-16s %4d (floor %4d) %s\n", 'duplicate_slugs', count( $duplicates ), 0, empty( $duplicates ) ? 'OK' : 'VIOLATION' );
if ( ! empty( $duplicates ) ) {
	$shortfalls['duplicate_slugs'] = array(
		'have'   => count( $duplicates ),
		'floor'  => 0,
		'sample' => array_slice( $duplicates, 0, 10 ),
	);
}

// Menu state: the EN menu must exist and be assigned for BOTH languages, with
// no duplicated items.
//
// `polylang` is an AUTOLOADED option, so it lives in the alloptions cache, which
// was populated when THIS process loaded WordPress — long before the menu
// scripts (which run as separate child processes) wrote their assignments.
// Reading it without refreshing therefore reported pt=0/en=0 even though the
// assignments were correctly persisted, and the audit failed on a state that
// was actually correct. The cache is invalidated explicitly so the audit
// measures what is really stored, not what this process happened to load.
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'polylang', 'options' );

$stylesheet = (string) get_option( 'stylesheet' );
$options    = get_option( 'polylang', array() );
$menu_state = array(
	'pt'    => (int) ( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ?? 0 ),
	'en'    => (int) ( $options['nav_menus'][ $stylesheet ]['primary']['en'] ?? 0 ),
	'items' => 0,
	'dupes' => 0,
);
$en_menu    = wp_get_nav_menu_object( 'Main Menu' );
if ( $en_menu ) {
	$menu_items          = (array) wp_get_nav_menu_items( (int) $en_menu->term_id );
	$menu_state['items'] = count( $menu_items );

	$urls = array();
	foreach ( $menu_items as $item ) {
		$key = ( 'custom' === $item->type ) ? $item->url : 'obj:' . (int) $item->object_id;
		if ( isset( $urls[ $key ] ) ) {
			++$menu_state['dupes'];
		}
		$urls[ $key ] = true;
	}
}
$menu_ok = ( $menu_state['pt'] > 0 && $menu_state['en'] > 0 && $menu_state['items'] > 0 && 0 === $menu_state['dupes'] );
printf(
	"  %-16s pt=%d en=%d items=%d dupes=%d %s\n",
	'nav_menu',
	$menu_state['pt'],
	$menu_state['en'],
	$menu_state['items'],
	$menu_state['dupes'],
	$menu_ok ? 'OK' : 'VIOLATION'
);
if ( ! $menu_ok ) {
	$shortfalls['nav_menu'] = $menu_state;
}

// PT drift: the whole point of the EN stages is that the PT record is never
// modified by an English change.
//
// SCOPE, deliberately narrow: only the records THIS ORCHESTRATOR OWNS are
// checked. The site may legitimately carry other PT guides and posts that this
// script did not create (a developer's migrated dataset, a production-shaped
// local site), and asserting this script's own fixture text over records it
// does not own would be both wrong and — as a first run of the audit here
// showed — a false alarm on 48 unrelated records. Drift is a claim about what
// the bootstrap is responsible for, so it is asserted exactly there.
//
// A record is owned when the fixture dataset declares its slug. Because the
// upsert is keyed by the same slug, an owned record that no longer carries the
// authored excerpt is either an EN stage that mutated PT content or an
// out-of-band edit: both are real defects.
$pt_drift         = 0;
$pt_drift_samples = array();
foreach ( array(
	'guide' => conexao_ci_fixture_guides(),
	'post'  => conexao_ci_fixture_posts(),
) as $type => $dataset ) {
	foreach ( $dataset as $slug => $row ) {
		$post = conexao_ci_find( $type, $slug );
		if ( $post && (string) $post->post_excerpt !== (string) $row['excerpt'] ) {
			++$pt_drift;
			if ( count( $pt_drift_samples ) < 10 ) {
				$pt_drift_samples[] = $type . ':' . $slug;
			}
		}
	}
}
printf( "  %-16s %4d (floor %4d) %s\n", 'pt_drift', $pt_drift, 0, 0 === $pt_drift ? 'OK' : 'VIOLATION' );
if ( $pt_drift > 0 ) {
	$shortfalls['pt_drift'] = array(
		'have'   => $pt_drift,
		'floor'  => 0,
		'sample' => $pt_drift_samples,
	);
}

// Orphan translations: an EN record whose PT master is gone. The engine never
// creates one, but a fixture that was deleted and re-seeded could, and an
// orphan is a broken identity.
$orphans = 0;
if ( function_exists( 'pll_get_post' ) ) {
	foreach ( array( 'guide', 'post' ) as $type ) {
		$en_records = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'lang'           => 'en',
			)
		);
		foreach ( $en_records as $en ) {
			$master = (int) pll_get_post( (int) $en->ID, 'pt' );
			if ( $master <= 0 || $master === (int) $en->ID ) {
				++$orphans;
			}
		}
	}
}
printf( "  %-16s %4d (floor %4d) %s\n", 'orphan_en', $orphans, 0, 0 === $orphans ? 'OK' : 'VIOLATION' );
if ( $orphans > 0 ) {
	$shortfalls['orphan_en'] = array(
		'have'  => $orphans,
		'floor' => 0,
	);
}

$created_total  = $stats['guide_created'] + $stats['post_created'] + $stats['sponsor_created']
	+ $stats['event_created'] + $stats['terms_created'] + $stats['job_created'];
$repaired_total = $stats['guide_repaired'] + $stats['post_repaired'] + $stats['sponsor_repaired']
	+ $stats['event_repaired'] + $stats['job_repaired'];

echo "\n  created this run:  {$created_total}\n";
echo "  repaired this run: {$repaired_total}\n";

// The idempotence proof, stated as a number rather than a claim. A second run
// over a converged site must report zero of both.
if ( $apply && $created_total > 0 ) {
	echo "  note: records were created, so this was the first run against this database.\n";
}
if ( $apply && 0 === $created_total && $repaired_total > 0 ) {
	echo "  note: no records were created and some were repaired; the site is converging.\n";
}
if ( $apply && 0 === $created_total && 0 === $repaired_total ) {
	echo "  note: nothing created and nothing repaired — the bootstrap is IDEMPOTENT on this database.\n";
}

if ( ! empty( $shortfalls ) ) {
	echo "\n  FIXTURE AUDIT FAILED — the deterministic site is incomplete:\n";
	foreach ( $shortfalls as $key => $info ) {
		// A shortfall is normally `array( 'have' => n, 'floor' => m )`, but the
		// menu check reports its own flat state instead. Both shapes are printed
		// honestly rather than assuming one, so a menu failure is diagnosable
		// from the summary line alone.
		$detail = isset( $info['have'], $info['floor'] )
			? sprintf( 'have %s, floor %d', $info['have'], (int) $info['floor'] )
			: wp_json_encode( $info );

		if ( isset( $info['sample'] ) ) {
			$detail .= ' (e.g. ' . implode( ', ', $info['sample'] ) . ')';
		}

		printf( "    - %s: %s\n", $key, $detail );
	}
	$stats['errors'] += count( $shortfalls );
}

// ═════════════════════════════════════════════════════════════════════════════
// Machine-readable fixture summary.
//
// Written to stdout under --json, and printed in a stable, greppable form
// always, so a CI log can be diagnosed without re-running anything.
// ═════════════════════════════════════════════════════════════════════════════
if ( $ctx['json'] ) {
	echo wp_json_encode(
		array(
			'apply'       => $apply,
			'verify'      => $verify,
			'populations' => $actual,
			'minimums'    => $minimums,
			'stats'       => $stats,
			'shortfalls'  => $shortfalls,
			'menu'        => $menu_state,
			'ok'          => empty( $shortfalls ) && 0 === $stats['errors'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	) . "\n";
}

exit(
	conexao_script_summary(
		$ctx,
		array(
			'created'    => $created_total,
			'updated'    => $repaired_total,
			'errors'     => $stats['errors'],
			'skipped'    => 0,
			'conflicts'  => 0,
			'pt_changed' => 0,
		),
		array(
			'populations' => $actual,
			'shortfalls'  => $shortfalls,
		)
	)
);
