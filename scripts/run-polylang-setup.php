<?php
/**
 * Stage 2 — Polylang configuration + existing-content language assignment.
 *
 * LOCAL / STAGING ONLY. Never run against production (see
 * CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md §Production safety).
 *
 * What it does (idempotent — safe to re-run):
 *   1. Creates the two approved languages:
 *        - pt_BR  → slug "pt" (default language, Portuguese)
 *        - en_US  → slug "en" (secondary language, English)
 *      The first language created becomes Polylang's default language, so
 *      pt_BR is created first and re-asserted as default afterwards.
 *   2. Asserts the approved URL configuration:
 *        force_lang = 1   (language code in a directory: /en/)
 *        hide_default = 1 (default language has NO prefix: /guias/)
 *        rewrite = 1      (pretty permalinks)
 *        browser = 0      (language is URL-determined, never browser-guessed)
 *        redirect_lang = 0
 *        media_support = 0 (media is shared, not per-language)
 *      Translated post types / taxonomies are NOT stored here: they are
 *      declared in code (theme inc/i18n/guard.php, `pll_get_post_types` /
 *      `pll_get_taxonomies`) so local, staging and production cannot drift.
 *   3. Assigns the default language (pt_BR) to every pre-existing
 *      post/term that has no language yet, using Polylang's own
 *      `PLL_Model::set_language_in_mass()` — the exact routine the Polylang
 *      setup wizard uses for "assign untranslated contents". Nothing is
 *      duplicated and no identity meta is touched.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress wp eval-file - --allow-root < scripts/run-polylang-setup.php
 *   docker compose exec -T wordpress wp eval-file - --allow-root < scripts/run-polylang-setup.php -- --dry-run
 *
 * @package Conexao_BR_Irlanda
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

$dry_run = (bool) array_intersect( array( 'dry-run', '--dry-run' ), (array) $args );

if ( ! function_exists( 'pll_languages_list' ) && class_exists( 'Polylang' ) ) {
	add_filter(
		'pll_context',
		static function () {
			return 'PLL_Frontend';
		},
		PHP_INT_MAX
	);
	(new Polylang())->init();
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	echo "ERROR: Polylang is not active. Install/activate it first.\n";
	return;
}

echo "== Stage 2 — Polylang setup ==\n";
echo 'Mode: ' . ( $dry_run ? "DRY RUN (no changes)\n" : "APPLY\n" );

/**
 * Create a language when missing.
 *
 * @param string $locale WordPress locale, e.g. pt_BR.
 * @param bool   $dry_run Report only.
 * @return string Status message.
 */
function conexao_stage2_ensure_language( $locale, $dry_run ) {
	$existing = PLL()->model->get_language( $locale );

	if ( $existing ) {
		return "language {$locale} already exists (slug: {$existing->slug})";
	}

	if ( $dry_run ) {
		return "language {$locale} WOULD be created";
	}

	$language = PLL()->model->add_language( array( 'locale' => $locale ) );

	if ( is_wp_error( $language ) ) {
		return "language {$locale} FAILED: " . $language->get_error_message();
	}

	return "language {$locale} created (slug: {$language->slug})";
}

echo "\n-- 1. Languages --\n";
echo '  ' . conexao_stage2_ensure_language( 'pt_BR', $dry_run ) . "\n";
echo '  ' . conexao_stage2_ensure_language( 'en_US', $dry_run ) . "\n";

if ( ! $dry_run ) {
	// The first language created wins the default; assert it explicitly.
	$result = PLL()->model->update_default_lang( 'pt' );
	if ( is_wp_error( $result ) && $result->has_errors() ) {
		echo '  ERROR setting default language: ' . $result->get_error_message() . "\n";
	}
}

$default = PLL()->model->get_default_language();
echo '  default language: ' . ( $default ? $default->slug . ' (' . $default->locale . ')' : 'MISSING' ) . "\n";

echo "\n-- 2. URL configuration --\n";
$expected = array(
	'force_lang'    => 1,
	'hide_default'  => true,
	'rewrite'       => true,
	'browser'       => false,
	'redirect_lang' => false,
	'media_support' => false,
);
$changed = false;
foreach ( $expected as $key => $value ) {
	$current = PLL()->options[ $key ];
	if ( $current === $value ) {
		echo "  {$key} = " . var_export( $current, true ) . " (unchanged)\n";
		continue;
	}
	echo "  {$key} = " . var_export( $current, true ) . " → " . var_export( $value, true ) . "\n";
	if ( ! $dry_run ) {
		$result = PLL()->options->set( $key, $value );
		if ( $result->has_errors() ) {
			echo '    ERROR: ' . $result->get_error_message() . "\n";
		} else {
			$changed = true;
		}
	}
}
if ( $changed ) {
	PLL()->options->save();
	echo "  options saved\n";
}

echo "\n-- 3. Existing content language assignment --\n";
$translated_post_types = PLL()->model->get_translated_post_types();
$translated_taxonomies = PLL()->model->get_translated_taxonomies();
echo '  translated post types: ' . implode( ', ', $translated_post_types ) . "\n";
echo '  translated taxonomies: ' . implode( ', ', $translated_taxonomies ) . "\n";

$no_lang = PLL()->model->get_objects_with_no_lang( -1 );
$before  = array(
	'posts' => is_array( $no_lang ) && isset( $no_lang['posts'] ) ? count( $no_lang['posts'] ) : 0,
	'terms' => is_array( $no_lang ) && isset( $no_lang['terms'] ) ? count( $no_lang['terms'] ) : 0,
);
echo "  objects with no language before: {$before['posts']} posts / {$before['terms']} terms\n";

if ( $dry_run ) {
	echo "  DRY RUN: assignment skipped\n";
	return;
}

$language = PLL()->model->get_default_language();
PLL()->model->set_language_in_mass( $language );

$no_lang_after = PLL()->model->get_objects_with_no_lang( -1 );
$after         = array(
	'posts' => is_array( $no_lang_after ) && isset( $no_lang_after['posts'] ) ? count( $no_lang_after['posts'] ) : 0,
	'terms' => is_array( $no_lang_after ) && isset( $no_lang_after['terms'] ) ? count( $no_lang_after['terms'] ) : 0,
);
echo "  objects with no language after: {$after['posts']} posts / {$after['terms']} terms\n";
echo "\nDone.\n";
