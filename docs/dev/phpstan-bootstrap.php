<?php
/**
 * PHPStan bootstrap — development/static analysis ONLY.
 *
 * ⚠️ This file is NEVER loaded by WordPress and is never deployed to
 * production (WordPress.com). It exists solely so `composer analyse`
 * (phpstan) can resolve symbols that static analysis cannot otherwise
 * discover in this repository:
 *
 *  1. WordPress core constants and function stubs come from the
 *     phpstan-wordpress extension's own bootstrap (see
 *     vendor/szepeviktor/phpstan-wordpress/bootstrap.php); the guarded
 *     ABSPATH definition below is only a fallback if that ever changes.
 *  2. Repository constants whose runtime values come from function calls
 *     (`get_template_directory_uri()`, `plugin_dir_url()`, …) — PHPStan
 *     cannot infer those across files, so the names are declared here with
 *     the types the runtime values have (string paths/URLs).
 *  3. The Polylang API (`pll_*` functions and the `PLL` object). Polylang is
 *     a third-party plugin that is deliberately NOT committed to this
 *     repository (see docs/development.md), so only its API signatures are
 *     stubbed. Every stub carries `@param`/`@return` metadata and no logic.
 *
 * Everything is capability-guarded (`defined()`/`function_exists()`/
 * `class_exists()`), so the file stays inert if a real symbol exists.
 * When editing runtime code, edit the real definitions in the theme/plugins
 * — NOT this file.
 *
 * @package Conexao_BR_Irlanda
 * @subpackage Dev_Tooling
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', './' );
}

/*
 * Repository constants (theme + plugin bootstrap files define the same
 * names at runtime from dynamic values; these declarations only give
 * PHPStan the names/types).
 */
if ( ! defined( 'CONEXAO_THEME_DIR' ) ) {
	define( 'CONEXAO_THEME_DIR', './wp-content/themes/conexao-br-irlanda' );
}
if ( ! defined( 'CONEXAO_THEME_URI' ) ) {
	define( 'CONEXAO_THEME_URI', './wp-content/themes/conexao-br-irlanda' );
}
if ( ! defined( 'CONEXAO_ADMIN_UX_DIR' ) ) {
	define( 'CONEXAO_ADMIN_UX_DIR', './wp-content/plugins/conexao-admin-ux' );
}
if ( ! defined( 'CONEXAO_ADMIN_UX_URL' ) ) {
	define( 'CONEXAO_ADMIN_UX_URL', './wp-content/plugins/conexao-admin-ux' );
}
if ( ! defined( 'CONEXAO_LEISURE_TRANSLATION_DIR' ) ) {
	define( 'CONEXAO_LEISURE_TRANSLATION_DIR', './wp-content/plugins/conexao-leisure-translation' );
}

/*
 * Polylang API stubs (third-party plugin, not committed to this repository).
 *
 * Signatures mirror the Polylang public API used by this codebase. Bodies are
 * intentionally unreachable (the guards above prevent these definitions when
 * the real plugin is present).
 */
if ( ! class_exists( 'PLL_Language' ) ) {
	/**
	 * PLL_Language stub.
	 */
	class PLL_Language {

		/** @var string */
		public $slug;

		/** @var string */
		public $name;

		/** @var string */
		public $locale;

		/**
		 * Home URL of the language.
		 *
		 * @return string
		 */
		public function get_home_url() {
			return '';
		}
	}
}

if ( ! class_exists( 'PLL_Model' ) ) {
	/**
	 * PLL_Model stub.
	 */
	class PLL_Model {

		/**
		 * Find a language by slug, locale, term ID…
		 *
		 * @param string|int $value Language slug, locale, or term ID.
		 * @return PLL_Language|false
		 */
		public function get_language( $value ) {
			return false;
		}

		/**
		 * @return PLL_Language[]
		 */
		public function get_languages_list() {
			return array();
		}

		/**
		 * @return void
		 */
		public function clean_languages_cache() {
		}

		/**
		 * @return string[] Post type names.
		 */
		public function get_translated_post_types() {
			return array();
		}

		/**
		 * @return string[] Taxonomy names.
		 */
		public function get_translated_taxonomies() {
			return array();
		}
	}
}

if ( ! class_exists( 'PLL_Links_Model' ) ) {
	/**
	 * PLL_Links_Model stub.
	 */
	class PLL_Links_Model {

		/**
		 * Home URL for a language slug.
		 *
		 * @param string $lang Language slug.
		 * @return string
		 */
		public function home_url( $lang ) {
			return '';
		}
	}
}

if ( ! class_exists( 'PLL_Links' ) ) {
	/**
	 * PLL_Links stub.
	 */
	class PLL_Links {

		/**
		 * "New translation" link for a post in a language.
		 *
		 * @param int          $post_id  Source post ID.
		 * @param PLL_Language $language Target language.
		 * @return string|false
		 */
		public function get_new_post_translation_link( $post_id, $language ) {
			return false;
		}
	}
}

if ( ! class_exists( 'PLL' ) ) {
	/**
	 * Polylang context object stub (what PLL() returns).
	 */
	class PLL {

		/** @var PLL_Model */
		public $model;

		/** @var PLL_Links_Model */
		public $links_model;

		/** @var PLL_Links */
		public $links;
	}
}

if ( ! function_exists( 'PLL' ) ) {
	/**
	 * Polylang context accessor stub.
	 *
	 * @return PLL|false
	 */
	function PLL() {
		return false;
	}
}

if ( ! function_exists( 'pll_current_language' ) ) {
	/**
	 * Current language.
	 *
	 * @param string $field 'slug' (default), 'name' or 'locale'.
	 * @return string|false
	 */
	function pll_current_language( $field = 'slug' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_default_language' ) ) {
	/**
	 * Default (site) language.
	 *
	 * @param string $field 'slug' (default), 'name' or 'locale'.
	 * @return string|false
	 */
	function pll_default_language( $field = 'slug' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_home_url' ) ) {
	/**
	 * Home URL of a language.
	 *
	 * @param string $lang Language slug (default: current language).
	 * @return string
	 */
	function pll_home_url( $lang = '' ) {
		return '';
	}
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	/**
	 * Languages list.
	 *
	 * @param array<string, mixed> $args 'fields' => '' (objects) or a field name ('slug'…).
	 * @return array<int, PLL_Language|string>
	 */
	function pll_languages_list( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * Translation of a post in a language.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Target language slug.
	 * @return int|false Translated post ID, or false.
	 */
	function pll_get_post( $post_id, $lang = '' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	/**
	 * Language of a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   'slug' (default), 'name' or 'locale'.
	 * @return string|false
	 */
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	/**
	 * Assign a post to a language.
	 *
	 * @param int                $post_id Post ID.
	 * @param string|PLL_Language $lang    Language slug or object.
	 * @return void
	 */
	function pll_set_post_language( $post_id, $lang ) {
	}
}

if ( ! function_exists( 'pll_set_term_language' ) ) {
	/**
	 * Assign a term to a language.
	 *
	 * @param int                $term_id Term ID.
	 * @param string|PLL_Language $lang    Language slug or object.
	 * @return void
	 */
	function pll_set_term_language( $term_id, $lang ) {
	}
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	/**
	 * Translations of a post, as a map of language slug => post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int>
	 */
	function pll_get_post_translations( $post_id ) {
		return array();
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	/**
	 * Save post translation links.
	 *
	 * @param array<string, int> $translations Map of language slug => post ID.
	 * @return void
	 */
	function pll_save_post_translations( $translations ) {
	}
}

if ( ! function_exists( 'pll_save_term_translations' ) ) {
	/**
	 * Save term translation links.
	 *
	 * @param array<string, int> $translations Map of language slug => term ID.
	 * @return void
	 */
	function pll_save_term_translations( $translations ) {
	}
}
