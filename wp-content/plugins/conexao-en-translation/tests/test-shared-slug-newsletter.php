<?php
/**
 * PERMANENT CONTRACT — the shared-slug page permit, and `newsletter` as a
 * shared-slug B1 page.
 *
 * Normative rules proven here:
 *
 *   1. "A page whose EN translation deliberately reuses the PT post_name is a
 *       SHARED-SLUG page: one canonical path serves two languages, the EN
 *       record is a NEW `en` record linked to the PT original, and the PT
 *       record is only ever read."
 *   2. "A stage declares a shared slug ONLY through its own manifest (a row
 *       whose `en_slug` equals its PT stable key), and the permit is
 *       fail-closed: no declaration, or an ambiguous one, means NO exception."
 *   3. "The uniqueness exception is never broader than the one declared slug."
 *   4. "`newsletter` is a B1 page, never a B2 page."
 *
 * ## Why `newsletter` needed this
 *
 * The `en-page` manifest authors the EN slug `newsletter`, the very `post_name`
 * the PT record already holds. WordPress makes page slugs unique per tree, so a
 * plain `wp_insert_post()` silently renames the EN record to `newsletter-2` and
 * the authored URL `/en/newsletter/` becomes unreachable. `en-blog-page` and
 * `en-jobs-page` already solved exactly this by holding the scoped
 * `wp_unique_post_slug()` exception; `newsletter` is the third instance of the
 * SAME shape, not a new mechanism, so the existing policy was generalised rather
 * than duplicated.
 *
 * The retired `conexao-page-translation` plugin already declared
 * `'newsletter' => array( 'en_slug' => 'newsletter', 'shared_slug' => true )`,
 * and `test-stage45-pages.php` still asserts that allowlist is exactly
 * `blog + newsletter`. This test is the maintained successor of that intent on
 * the current engine.
 *
 * @package Conexao_EN_Translation
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_ROOT . '/lib/assertions.php';

// The plugin's own data + config, loaded explicitly (the same convention as
// test-stage45-pages.php): the assertions below are about the POLICY this
// repository ships, so they must read the shipped files and must not depend on
// the plugin happening to be active in this environment.
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/manifest-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/guide-translation-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/guide-terms-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/blog-translation-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/blog-page-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/jobs-page-data.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/translation-map.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/stage-fields.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-en-translation/includes/stage-config.php';

test_title( 'conexao-en-translation: shared-slug page permit, with newsletter as a shared-slug B1 page' );

/**
 * Assert the permit is torn down once its own scope ends.
 *
 * @return void
 */
function conexao_newsletter_gate_permit_released() {
	assert_true(
		! get_transient( 'conexao_en_translation_shared_page_slug' ),
		'the permit transient is removed when the stage write finishes'
	);
	assert_true(
		false === has_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter' ),
		'the permit filter is removed when the stage write finishes'
	);
}

// ---------------------------------------------------------------------------
// 1. The policy is derived from the stage manifests, per stage.
// ---------------------------------------------------------------------------

test_section( 'the shared-slug permit is declared by the stage manifest, per stage' );

assert_equals( 'newsletter', conexao_en_translation_shared_page_slug_for( 'en-page' ), 'en-page declares newsletter as its one shared slug' );
assert_equals( 'blog', conexao_en_translation_shared_page_slug_for( 'en-blog-page' ), 'en-blog-page still declares blog' );
assert_equals( 'empregos', conexao_en_translation_shared_page_slug_for( 'en-jobs-page' ), 'en-jobs-page still declares empregos' );

assert_equals( '', conexao_en_translation_shared_page_slug_for( 'en-guide' ), 'en-guide declares no shared slug, so it holds no uniqueness exception' );
assert_equals( '', conexao_en_translation_shared_page_slug_for( 'en-post' ), 'en-post declares no shared slug, so it holds no uniqueness exception' );
assert_equals( '', conexao_en_translation_shared_page_slug_for( 'en-leisure-description' ), 'a B2 field stage declares no shared slug' );
assert_equals( '', conexao_en_translation_shared_page_slug_for( 'en-stage-that-does-not-exist' ), 'an unknown stage declares no shared slug (fail closed)' );

// The declaration IS the manifest row, not a constant: the row keeps the PT
// stable key as its own EN slug.
$page_manifest = conexao_en_translation_stage_manifest( 'en-page' );
assert_set_is_present( $page_manifest['records'], 'newsletter' );
assert_equals( 'newsletter', $page_manifest['records']['newsletter']['en_slug'], 'the en-page newsletter row still authors the slug newsletter' );
assert_equals( 'Newsletter', $page_manifest['records']['newsletter']['en_title'], 'the en-page newsletter row keeps its authored EN title ("Newsletter" is a label that does not translate)' );

// ... and it is the ONLY such row in that stage, so arming the permit for the
// stage can never reach any other page.
$shared_rows = 0;
foreach ( $page_manifest['records'] as $stable_key => $row ) {
	if ( (string) $row['en_slug'] === (string) $stable_key ) {
		++$shared_rows;
	}
}
assert_equals( 1, $shared_rows, 'en-page declares exactly one shared slug, so the permit reaches exactly one page' );

// The Blog posts page and the Jobs landing are unchanged: both still resolve
// their shared slug from their own manifest, through the same helper.
assert_equals( 'blog', conexao_en_translation_blog_page_shared_slug(), 'the Stage O Blog posts page shared slug is unchanged' );
assert_equals( 'empregos', conexao_en_translation_jobs_page_shared_slug(), 'the EN Jobs landing shared slug is unchanged' );

// ---------------------------------------------------------------------------
// 2. `newsletter` stays B1. It is NOT a B2 page.
// ---------------------------------------------------------------------------

test_section( 'newsletter is a B1 page: it is never added to the B2 page allowlist' );

if ( ! function_exists( 'conexao_b2_page_allowlist' ) ) {
	test_prerequisite_hint( 'theme-active' );
	test_fail( 'conexao_b2_page_allowlist() is unavailable, so the B1/B2 boundary cannot be proven' );
} else {
	$b2_pages = (array) conexao_b2_page_allowlist();
	assert_not_contains( 'newsletter', $b2_pages, 'newsletter is not a B2 fallback page' );
	assert_contains( 'blog', $b2_pages, 'the documented blog B2 entry is untouched' );

	$pt_newsletter_probe = get_page_by_path( 'newsletter', OBJECT, 'page' );
	assert_true(
		! ( $pt_newsletter_probe instanceof WP_Post ) || ! conexao_is_b2_page( (int) $pt_newsletter_probe->ID ),
		'the newsletter page is not classified as a B2 page'
	);
}

// ---------------------------------------------------------------------------
// 3. The permit itself: narrow on post type, on slug, and on lifetime.
// ---------------------------------------------------------------------------

test_section( 'the wp_unique_post_slug exception is narrow on post type, on slug and on lifetime' );

set_transient( 'conexao_en_translation_shared_page_slug', 'newsletter', 5 * MINUTE_IN_SECONDS );
add_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10, 6 );

try {
	// POSITIVE: the declared slug on a page keeps the authored slug.
	assert_equals(
		'newsletter',
		conexao_en_translation_shared_page_slug_filter( 'newsletter-2', 0, 'publish', 'page', 0, 'newsletter' ),
		'a page write on the declared shared slug keeps the authored slug'
	);

	// NEGATIVE: a DIFFERENT page slug is never captured by the permit.
	assert_equals(
		'contato-2',
		conexao_en_translation_shared_page_slug_filter( 'contato-2', 0, 'publish', 'page', 0, 'contato' ),
		'an unrelated page slug is still governed by WordPress uniqueness'
	);

	// NEGATIVE: the same slug on another post type is never captured.
	assert_equals(
		'newsletter-2',
		conexao_en_translation_shared_page_slug_filter( 'newsletter-2', 0, 'publish', 'post', 0, 'newsletter' ),
		'the declared slug on a non-page post type is still governed by WordPress uniqueness'
	);
} finally {
	remove_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10 );
	delete_transient( 'conexao_en_translation_shared_page_slug' );
}

// NEGATIVE: with nothing armed, the filter is a total no-op.
assert_equals(
	'newsletter-2',
	conexao_en_translation_shared_page_slug_filter( 'newsletter-2', 0, 'publish', 'page', 0, 'newsletter' ),
	'with no armed permit the filter changes nothing at all'
);

// POSITIVE: the permit is armed for exactly the stage write, and released after.
conexao_en_translation_with_shared_page_slug(
	'newsletter',
	static function () {
		assert_equals(
			'newsletter',
			get_transient( 'conexao_en_translation_shared_page_slug' ),
			'the permit is armed for the duration of the stage write'
		);
		assert_true(
			has_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter' ) > 0,
			'the filter is attached while the permit is armed'
		);

		return null;
	}
);
conexao_newsletter_gate_permit_released();

// NEGATIVE: a stage that declares no shared slug runs its writes with NO permit.
conexao_en_translation_with_shared_page_slug(
	conexao_en_translation_shared_page_slug_for( 'en-post' ),
	static function () {
		assert_true(
			! get_transient( 'conexao_en_translation_shared_page_slug' ),
			'a stage that declares no shared slug arms no permit whatsoever'
		);
		assert_true(
			false === has_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter' ),
			'a stage that declares no shared slug attaches no filter whatsoever'
		);

		return null;
	}
);

// ---------------------------------------------------------------------------
// 4. The duplicate guard that makes the exception safe.
// ---------------------------------------------------------------------------

test_section( 'the shared-slug duplicate guard is scoped to the ONE declared slug' );

$guarded   = conexao_en_translation_stage_adapter( 'page', 'en-page' );
$unguarded = conexao_en_translation_engine_adapter( 'page' );

assert_true(
	is_callable( $guarded['find_en_for_pt'] ) && is_callable( $unguarded['find_en_for_pt'] ),
	'both adapters expose the find_en_for_pt primitive'
);
assert_equals( array_keys( $unguarded ), array_keys( $guarded ), 'the shared-slug adapter adds no primitive and removes none' );

$pt_newsletter = get_page_by_path( 'newsletter', OBJECT, 'page' );

/**
 * Create an UNLINKED page that really lands on the declared shared slug.
 *
 * The permit is armed for the insert exactly as a stage arms it, because without
 * it WordPress would uniquify the fixture to `newsletter-2` and the fixture would
 * be testing nothing.
 *
 * @param string $lang Language slug for the fixture.
 * @return int Created id, or 0.
 */
function conexao_newsletter_gate_unlinked_page( string $lang ) {
	set_transient( 'conexao_en_translation_shared_page_slug', 'newsletter', 5 * MINUTE_IN_SECONDS );
	add_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10, 6 );

	try {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'conexao-en-translation shared-slug guard fixture',
				'post_name'    => 'newsletter',
				'post_content' => 'Created and removed by test-shared-slug-newsletter.php.',
			),
			true
		);
	} finally {
		remove_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10 );
		delete_transient( 'conexao_en_translation_shared_page_slug' );
	}

	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	$post_id = (int) $post_id;

	if ( '' !== $lang && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, $lang );
	}

	return $post_id;
}

// The real PT newsletter page: the PT record holding the shared slug is never a
// "duplicate" of itself, whatever else sits on the slug.
if ( $pt_newsletter instanceof WP_Post ) {
	$real_pt_id = (int) $pt_newsletter->ID;
	$found      = call_user_func( $guarded['find_en_for_pt'], $real_pt_id, 'newsletter' );
	assert_true(
		empty( $found['duplicate'] ),
		'the PT record holding the shared slug is never reported as a duplicate'
	);

	// A LINKED but drifted EN translation is not a duplicate either: it is the
	// existing translation, and the engine's `update` path repairs its slug. This
	// is the pre-existing `newsletter-2` debt this policy exists to prevent
	// recurring, so the case is proven rather than assumed.
	//
	// The drift is CREATED deterministically here, not hoped for: on an install
	// whose linked EN newsletter already holds the authored slug (the synthetic
	// CI database, where the shared `en-page` stage has already run), the
	// record starts UN-drifted, so the repairable `update` classification can
	// only be proven by renaming the linked record to the historical
	// `newsletter-2` and restoring it afterwards. The original slug is restored
	// under the same scoped permit the stage arms, and the restoration is
	// asserted, not assumed.
	$drifted_en = (int) pll_get_post( $real_pt_id, 'en' );
	if ( $drifted_en > 0 && $drifted_en !== $real_pt_id ) {
		$drifted = call_user_func( $guarded['find_en_for_pt'], $real_pt_id, 'newsletter' );
		assert_true(
			! empty( $drifted['pair_ok'] ),
			'a linked EN translation is accepted even when its slug has drifted (the engine repairs it on apply)'
		);

		$original_en_slug = (string) get_post_field( 'post_name', $drifted_en );

		if ( $drifted['en_slug_matches'] ) {
			// POST-PAIR: the linked EN already holds the authored slug. Recreate
			// the historical drift, prove the `update` classification against it,
			// then restore the authored slug.
			wp_update_post(
				array(
					'ID'        => $drifted_en,
					'post_name' => 'newsletter-2',
				),
				true
			);

			$drifted = call_user_func( $guarded['find_en_for_pt'], $real_pt_id, 'newsletter' );

			assert_true(
				! empty( $drifted['pair_ok'] ),
				'the drifted but linked EN translation is still the pair, never a duplicate'
			);
			assert_true(
				! $drifted['en_slug_matches'],
				'the drifted slug is reported as the engine\'s repairable `update` case, not as a create'
			);

			set_transient( 'conexao_en_translation_shared_page_slug', 'newsletter', 5 * MINUTE_IN_SECONDS );
			add_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10, 6 );

			try {
				wp_update_post(
					array(
						'ID'        => $drifted_en,
						'post_name' => $original_en_slug,
					),
					true
				);
			} finally {
				remove_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter', 10 );
				delete_transient( 'conexao_en_translation_shared_page_slug' );
			}

			assert_equals(
				$original_en_slug,
				(string) get_post_field( 'post_name', $drifted_en ),
				'the linked EN newsletter record\'s authored slug is restored exactly'
			);
		} else {
			assert_true(
				! $drifted['en_slug_matches'],
				'the drifted slug is reported as the engine\'s repairable `update` case, not as a create'
			);
		}
	} else {
		assert_true(
			true,
			'no linked EN newsletter exists on this install, so the stage plans a create (nothing to assert)'
		);
	}
} else {
	test_prerequisite_hint( 'page-newsletter-exists' );
	test_fail( 'the PT newsletter page does not exist, so the shared-slug duplicate guard cannot be proven against it' );
}

// A row that is NOT the declared slug is never inspected by the shared-slug
// guard, so the stage's other rows keep the generic collision rules.
$other = call_user_func( $guarded['find_en_for_pt'], $pt_newsletter instanceof WP_Post ? (int) $pt_newsletter->ID : 0, 'contato' );
assert_true(
	empty( $other['duplicate'] ),
	'a non-shared row of the same stage is not inspected by the shared-slug duplicate guard'
);

// POSITIVE: an unpaired second page on the shared slug IS a duplicate, so the
// engine refuses to create a second EN identity beside it.
$fixture_pt = conexao_newsletter_gate_unlinked_page( 'pt' );
$fixture_en = conexao_newsletter_gate_unlinked_page( 'en' );

if ( $fixture_pt > 0 && $fixture_en > 0 ) {
	assert_equals( 'newsletter', (string) get_post_field( 'post_name', $fixture_en ), 'the EN fixture really landed on the shared slug, so the guard is exercised' );

	$with_duplicate = call_user_func( $guarded['find_en_for_pt'], $fixture_pt, 'newsletter' );
	assert_true(
		! empty( $with_duplicate['duplicate'] ),
		'an UNPAIRED duplicate page on the shared slug is reported as a duplicate, so the engine refuses to create a second EN identity'
	);
	assert_equals( $fixture_en, (int) ( $with_duplicate['en_id'] ?? 0 ), 'the duplicate is reported as the record already holding the slug' );
	assert_true( empty( $with_duplicate['pair_ok'] ), 'the unpaired duplicate is never reported as a valid translation pair' );

	// NEGATIVE: the GENERIC adapter cannot see the duplicate at all, which is
	// precisely why the shared-slug guard has to exist beside the permit.
	$generic_view = call_user_func( $unguarded['find_en_for_pt'], $fixture_pt, 'newsletter' );
	assert_true(
		empty( $generic_view['duplicate'] ),
		'the generic adapter does not apply the shared-slug guard (this is why the guard is required)'
	);
} else {
	test_prerequisite_hint( 'shared-slug-duplicate-fixture' );
	test_fail( 'the unpaired duplicate fixtures could not be created, so the shared-slug duplicate guard is unproven' );
}

foreach ( array( $fixture_pt, $fixture_en ) as $fixture_id ) {
	if ( $fixture_id > 0 ) {
		wp_delete_post( $fixture_id, true );
	}
}

$survivors = get_posts(
	array(
		'post_type'      => 'page',
		'post_status'    => 'any',
		'name'           => 'newsletter',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
	)
);

// The pages that must survive this suite: the real PT newsletter page, plus —
// when one exists and holds the slug — its REAL linked EN translation (the
// shared engine's `en-page` stage authors it; it is site content, not this
// suite's fixture, so it is never created or deleted here).
$expected_survivors = array();

if ( $pt_newsletter instanceof WP_Post ) {
	$expected_survivors[] = (int) $pt_newsletter->ID;

	$real_en = (int) pll_get_post( (int) $pt_newsletter->ID, 'en' );

	if ( $real_en > 0 && 'newsletter' === (string) get_post_field( 'post_name', $real_en ) ) {
		$expected_survivors[] = $real_en;
	}
}

sort( $expected_survivors );
$actual_survivors = array_map( 'intval', (array) $survivors );
sort( $actual_survivors );

assert_equals(
	$expected_survivors,
	$actual_survivors,
	'the shared-slug guard fixtures were removed and the real newsletter pages are untouched'
);

test_finish( 'conexao-en-translation shared-slug newsletter' );
