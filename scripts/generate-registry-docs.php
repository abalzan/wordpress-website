<?php
/**
 * generate-registry-docs.php — Stage G of the engineering-standardisation
 * roadmap (docs/engineering-standard.md §1.8).
 *
 * plugins.json is the single authoritative plugin registry. This script reads
 * it, validates it against the real repository, derives every derived list
 * (load order, production activation order, release build list, local Compose
 * mount list, lifecycle inventory, documentation metadata) and writes them into
 * clearly marked generated regions.
 *
 * Usage:
 *   php scripts/generate-registry-docs.php --check   # validate + detect drift. ZERO writes.
 *   php scripts/generate-registry-docs.php --write   # validate + write generated regions only.
 *   php scripts/generate-registry-docs.php --help
 *
 * Design rules (non-negotiable):
 *   - Never writes outside a BEGIN/END generated marker pair.
 *   - --check performs ZERO writes and exits non-zero when output is stale.
 *   - Unknown arguments fail non-zero.
 *   - A target missing its markers fails safely; it is never blind-overwritten.
 *   - Deterministic output: identical registry + identical repository => byte-identical files.
 *   - Never contacts production, never touches WordPress content or the database,
 *     never activates or deactivates a plugin. It is a text generator.
 *
 * Exit codes: 0 = clean, 1 = validation or drift failure, 2 = usage error.
 *
 * @package Conexao_BR_Irlanda
  *
 * Bootstrap exception: static registry tool. It reads plugins.json and the
 * working tree and never loads WordPress, so scripts/lib/bootstrap.php is
 * deliberately not used. Current, not historical.
*/

declare(strict_types=1);

const EXIT_OK    = 0;
const EXIT_FAIL  = 1;
const EXIT_USAGE = 2;

const MARKER_BEGIN = '<!-- BEGIN GENERATED PLUGIN REGISTRY -->';
const MARKER_END   = '<!-- END GENERATED PLUGIN REGISTRY -->';

const VALID_CLASSES   = ['platform', 'tooling', 'rollout'];
const VALID_STATUSES  = ['active', 'retired'];
const REQUIRED_FIELDS = [
	'slug', 'name', 'class', 'status', 'production', 'build',
	'mount', 'dependencies', 'version_source', 'documentation',
];

/**
 * Collects validation problems.
 *
 * A tiny holder rather than a global, so the stored list has a declared type
 * that is neither re-inferred nor narrowed away at the call sites.
 */
final class Registry_Problems {

	/**
	 * Collected problems, reported together before the script exits non-zero.
	 *
	 * @var list<string>
	 */
	public static array $items = array();

	/**
	 * Record a validation problem. Never throws.
	 */
	public static function add(string $message): void {
		self::$items[] = $message;
	}

	/**
	 * Have any problems been recorded?
	 */
	public static function any(): bool {
		return count(self::$items) > 0;
	}

	/**
	 * All recorded problems, in the order they were found.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return self::$items;
	}

	/**
	 * How many problems have been recorded so far.
	 *
	 * Callers compare counts across a section rather than testing for
	 * emptiness, so a section that reported nothing is provably clean.
	 */
	public static function count(): int {
		return count(self::$items);
	}
}

/**
 * Usage text.
 */
function usage(): string {
	return <<<'TXT'
generate-registry-docs.php - derive plugin load order, build, mounts and
documentation from plugins.json (engineering standard 1.8).

Usage:
  php scripts/generate-registry-docs.php --check   Validate + detect drift (ZERO writes)
  php scripts/generate-registry-docs.php --write   Validate + write generated regions
  php scripts/generate-registry-docs.php --help    This message
  php scripts/generate-registry-docs.php --build-count
                                                Print the registry build count.
                                                Used by build-plugins-zip.sh to
                                                cross-check its generated list.
                                                Writes nothing.

Exit codes:
  0  clean (or --write completed)
  1  registry validation failed, or generated output is stale under --check
  2  usage error
TXT;
}

/**
 * Read and decode plugins.json.
 *
 * @return array{root:string,registry:array<string,mixed>,entries:list<array<string,mixed>>}
 */
function load_registry(string $root): array {
	$path = $root . '/plugins.json';
	if (!is_readable($path)) {
		Registry_Problems::add('plugins.json not found or unreadable at ' . $path);
		return ['root' => $root, 'registry' => [], 'entries' => []];
	}
	$raw = file_get_contents($path);
	if ($raw === false) {
		Registry_Problems::add('plugins.json could not be read');
		return ['root' => $root, 'registry' => [], 'entries' => []];
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		Registry_Problems::add('plugins.json is not valid JSON: ' . json_last_error_msg());
		return ['root' => $root, 'registry' => [], 'entries' => []];
	}
	if (!isset($decoded['load_order']) || !is_array($decoded['load_order'])) {
		Registry_Problems::add('plugins.json is missing the required "load_order" array');
		return ['root' => $root, 'registry' => $decoded, 'entries' => []];
	}
	return ['root' => $root, 'registry' => $decoded, 'entries' => array_values($decoded['load_order'])];
}

/**
 * Read the WordPress plugin header fields this stage cares about.
 *
 * Deliberately narrow: Plugin Name and Requires Plugins (to cross-check the
 * registry) and Version (to report the authoritative version in generated
 * docs). Stage G never edits a header; it only reads them.
 *
 * @return array{name:?string,version:?string,requires_plugins:list<string>,text_domain:?string}
 */
function plugin_header(string $file): array {
	$result = ['name' => null, 'version' => null, 'requires_plugins' => [], 'text_domain' => null];
	$handle = fopen($file, 'rb');
	if ($handle === false) {
		return $result;
	}
	$read = fread($handle, 8192);
	fclose($handle);
	if (!is_string($read)) {
		return $result;
	}
	foreach (
		[
			'name' => 'Plugin Name',
			'version' => 'Version',
			'text_domain' => 'Text Domain',
		] as $key => $header_key
	) {
		if (preg_match('/^[ \t\/*#@]*' . preg_quote($header_key, '/') . ':(.*)$/mi', $read, $m) === 1) {
			$result[$key] = trim($m[1]);
		}
	}
	if (preg_match('/^[ \t\/*#@]*Requires Plugins:(.*)$/mi', $read, $m) === 1) {
		$parts = array_map('trim', explode(',', $m[1]));
		$result['requires_plugins'] = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
	}
	return $result;
}

/**
 * Validate the registry in isolation and against the real repository.
 *
 * @param list<array<string,mixed>> $entries
 */
function validate_entries(string $root, array $entries): void {
	if ($entries === []) {
		Registry_Problems::add('plugins.json load_order is empty - the registry must inventory every custom plugin');
		return;
	}

	$slugs    = [];
	$docpaths = [];
	$position = [];

	foreach ($entries as $index => $entry) {
		$label = sprintf('load_order[%d]', $index);

		if (!is_array($entry)) {
			Registry_Problems::add($label . ' is not an object');
			continue;
		}

		foreach (REQUIRED_FIELDS as $field) {
			if (!array_key_exists($field, $entry)) {
				Registry_Problems::add($label . ' is missing the required field "' . $field . '"');
			}
		}

		$slug = is_string($entry['slug'] ?? null) ? $entry['slug'] : '';

		// --- slug format + uniqueness --------------------------------------
		if ($slug === '') {
			Registry_Problems::add($label . ' has an empty slug');
		} elseif (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
			Registry_Problems::add($label . ' slug "' . $slug . '" is not a lowercase kebab-case plugin slug');
		} elseif (isset($slugs[$slug])) {
			Registry_Problems::add('duplicate plugin slug "' . $slug . '" at load_order[' . $slugs[$slug] . '] and load_order[' . $index . ']');
		} else {
			$slugs[$slug]    = $index;
			$position[$slug] = $index;
		}

		// --- enum fields ----------------------------------------------------
		$class = $entry['class'] ?? null;
		if (!is_string($class) || !in_array($class, VALID_CLASSES, true)) {
			Registry_Problems::add($label . ' (' . $slug . ') has invalid class ' . var_export($class, true) . '; expected one of ' . implode(' | ', VALID_CLASSES));
		}
		$status = $entry['status'] ?? null;
		if (!is_string($status) || !in_array($status, VALID_STATUSES, true)) {
			Registry_Problems::add($label . ' (' . $slug . ') has invalid status ' . var_export($status, true) . '; expected one of ' . implode(' | ', VALID_STATUSES));
		}

		// --- boolean lifecycle flags ---------------------------------------
		foreach (['production', 'build', 'mount'] as $flag) {
			if (!is_bool($entry[$flag] ?? null)) {
				Registry_Problems::add($label . ' (' . $slug . ') field "' . $flag . '" must be a JSON boolean, got ' . gettype($entry[$flag] ?? null));
			}
		}

		// --- dependencies ---------------------------------------------------
		$deps = $entry['dependencies'] ?? null;
		if (!is_array($deps)) {
			Registry_Problems::add($label . ' (' . $slug . ') field "dependencies" must be an array');
		} else {
			foreach ($deps as $dep) {
				if (!is_string($dep) || $dep === '') {
					Registry_Problems::add($label . ' (' . $slug . ') has a non-string dependency');
					continue;
				}
				if ($dep === $slug) {
					Registry_Problems::add($label . ' (' . $slug . ') depends on itself');
				}
			}
		}

		// --- version source must exist --------------------------------------
		$version_source = $entry['version_source'] ?? null;
		if (!is_string($version_source) || $version_source === '') {
			Registry_Problems::add($label . ' (' . $slug . ') has an empty version_source');
		} elseif (!is_file($root . '/' . $version_source)) {
			Registry_Problems::add($label . ' (' . $slug . ') version_source does not exist: ' . $version_source);
		}

		// --- documentation path --------------------------------------------
		$doc = $entry['documentation'] ?? null;
		if (!is_string($doc) || $doc === '') {
			Registry_Problems::add($label . ' (' . $slug . ') has an empty documentation path');
		} else {
			if (isset($docpaths[$doc])) {
				Registry_Problems::add('duplicate documentation path "' . $doc . '" used by "' . ($slugs[$doc] ?? '?') . '" and "' . $slug . '"');
			}
			$docpaths[$doc] = $slug;
		}

		// --- the plugin directory itself must exist ------------------------
		if ($slug !== '' && !is_dir($root . '/wp-content/plugins/' . $slug)) {
			Registry_Problems::add($label . ' (' . $slug . ') has no plugin directory: wp-content/plugins/' . $slug);
		}
	}

	// --- every plugin directory on disk must be registered -----------------
	$on_disk = glob($root . '/wp-content/plugins/*', GLOB_ONLYDIR) ?: [];
	foreach ($on_disk as $dir) {
		$name = basename($dir);
		if (!isset($slugs[$name])) {
			Registry_Problems::add('orphaned plugin directory not represented in the registry: wp-content/plugins/' . $name);
		}
	}

	// --- dependencies resolve and precede their dependents -----------------
	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$slug = is_string($entry['slug'] ?? null) ? $entry['slug'] : '';
		$deps = is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : [];
		foreach ($deps as $dep) {
			if (!is_string($dep)) {
				continue;
			}
			if (!isset($slugs[$dep])) {
				Registry_Problems::add('"' . $slug . '" depends on "' . $dep . '", which is not in the registry');
				continue;
			}
			if ($position[$dep] > $position[$slug]) {
				Registry_Problems::add(
					'load order violation: "' . $slug . '" (index ' . $position[$slug] . ') is loaded before its dependency "'
					. $dep . '" (index ' . $position[$dep] . ')'
				);
			}
		}
	}

	detect_dependency_cycles($entries);
	validate_lifecycle_rules($entries);
	validate_headers_against_registry($root, $entries);
}

/**
 * Depth-first cycle detection over the dependency graph.
 *
 * @param list<array<string,mixed>> $entries
 */
function detect_dependency_cycles(array $entries): void {
	$graph = [];
	foreach ($entries as $entry) {
		if (!is_array($entry) || !is_string($entry['slug'] ?? null)) {
			continue;
		}
		$graph[$entry['slug']] = array_values(array_filter(
			is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : [],
			'is_string'
		));
	}

	$state = []; // 0 = unvisited, 1 = on stack, 2 = done.
	$stack  = [];

	$visit = function (string $node) use (&$visit, &$state, &$stack, $graph): void {
		$state[$node] = 1;
		$stack[]      = $node;
		foreach ($graph[$node] ?? [] as $dep) {
			if (!isset($graph[$dep])) {
				continue;
			}
			if (($state[$dep] ?? 0) === 1) {
				$start = array_search($dep, $stack, true);
				$cycle = array_slice($stack, $start === false ? 0 : $start);
				$cycle[] = $dep;
				Registry_Problems::add('dependency cycle: ' . implode(' -> ', $cycle));
				continue;
			}
			if (($state[$dep] ?? 0) === 0) {
				$visit($dep);
			}
		}
		array_pop($stack);
		$state[$node] = 2;
	};

	foreach (array_keys($graph) as $node) {
		if (($state[$node] ?? 0) === 0) {
			$visit($node);
		}
	}
}

/**
 * Lifecycle invariants that must hold regardless of the rest of the registry.
 *
 * @param list<array<string,mixed>> $entries
 */
function validate_lifecycle_rules(array $entries): void {
	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$slug   = is_string($entry['slug'] ?? null) ? $entry['slug'] : '(unknown)';
		$class  = $entry['class']  ?? null;
		$status = $entry['status'] ?? null;
		$prod   = $entry['production'] ?? null;
		$build  = $entry['build'] ?? null;

		if ($status === 'retired' && $prod === true) {
			Registry_Problems::add('"' . $slug . '" is retired, so production must be false (a retired rollout is never steady-state production)');
		}
		if ($status === 'retired' && $build === true) {
			Registry_Problems::add('"' . $slug . '" is a retired rollout and must never be build-enabled (build must be false)');
		}
		if ($class === 'rollout' && $status === 'active') {
			Registry_Problems::add('"' . $slug . '" has class "rollout" but status "active"; a rollout plugin is either retired or explicitly active tooling');
		}
		if ($class === 'rollout' && $prod === true) {
			Registry_Problems::add('"' . $slug . '" has class "rollout" and must never be a production plugin (production must be false)');
		}
		if ($class === 'tooling' && $prod === true) {
			Registry_Problems::add('"' . $slug . '" has class "tooling" and must not be marked production (production must be false)');
		}
	}
}

/**
 * Dependencies MUST be traceable to a real "Requires Plugins" header.
 *
 * This is the guard against inventing dependencies: a registry edge the plugin
 * header does not declare is a registry bug, and a header declaration the
 * registry omits is registry drift. Neither is ever guessed - both fail.
 *
 * @param list<array<string,mixed>> $entries
 */
function validate_headers_against_registry(string $root, array $entries): void {
	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$slug   = is_string($entry['slug'] ?? null) ? $entry['slug'] : '';
		$source = is_string($entry['version_source'] ?? null) ? $entry['version_source'] : '';
		if ($slug === '' || $source === '' || !is_file($root . '/' . $source)) {
			continue;
		}
		$header   = plugin_header($root . '/' . $source);
		$declared = $header['requires_plugins'];
		$registry = array_values(array_filter(
			is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : [],
			'is_string'
		));
		sort($declared);
		sort($registry);
		if ($declared !== $registry) {
			Registry_Problems::add(sprintf(
				'"%s" dependency mismatch: plugin header declares [Requires Plugins: %s] but the registry declares [%s]',
				$slug,
				$declared === [] ? 'none' : implode(', ', $declared),
				$registry === [] ? 'none' : implode(', ', $registry)
			));
		}
	}
}

/**
 * Filter the registry down to a single derived list, preserving load order.
 *
 * @param list<array<string,mixed>> $entries
 * @return list<array<string,mixed>>
 */
function filter_entries(array $entries, string $field, bool $value): array {
	return array_values(array_filter(
		$entries,
		static fn(array $e): bool => ($e[$field] ?? null) === $value
	));
}

/**
 * The production activation order: the production:true subset of load_order.
 *
 * By construction it can never contain a production:false entry, and a retired
 * rollout is production:false, so it can never contain one either.
 *
 * @param list<array<string,mixed>> $entries
 * @return list<string>
 */
function production_activation_order(array $entries): array {
	return array_map(
		static fn(array $e): string => (string) $e['slug'],
		filter_entries($entries, 'production', true)
	);
}

/**
 * Release build order (build: true), in load order.
 *
 * @param list<array<string,mixed>> $entries
 * @return list<string>
 */
function build_order(array $entries): array {
	return array_map(
		static fn(array $e): string => (string) $e['slug'],
		filter_entries($entries, 'build', true)
	);
}

/**
 * Local Compose mount order (mount: true), in load order.
 *
 * @param list<array<string,mixed>> $entries
 * @return list<string>
 */
function mount_order(array $entries): array {
	return array_map(
		static fn(array $e): string => (string) $e['slug'],
		filter_entries($entries, 'mount', true)
	);
}

function yn(bool $value): string {
	return $value ? 'yes' : 'no';
}

/**
 * The authoritative version, read from the plugin header (never duplicated in
 * the registry). "?" when the header declares no Version field.
 *
 * @param array<string,mixed> $entry
 */
function version_of(string $root, array $entry): string {
	$source = is_string($entry['version_source'] ?? null) ? $entry['version_source'] : '';
	if ($source === '' || !is_file($root . '/' . $source)) {
		return '?';
	}
	$header = plugin_header($root . '/' . $source);
	return $header['version'] ?? '?';
}

/**
 * @param array<string,mixed> $entry
 * @return list<string>
 */
function deps_of(array $entry): array {
	return array_values(array_filter(
		is_array($entry['dependencies'] ?? null) ? $entry['dependencies'] : [],
		'is_string'
	));
}

/**
 * @param list<array<string,mixed>> $entries
 * @return list<string>
 */
function slugs_where(array $entries, callable $predicate): array {
	$out = [];
	foreach ($entries as $entry) {
		if (is_array($entry) && $predicate($entry)) {
			$out[] = (string) $entry['slug'];
		}
	}
	return $out;
}

/**
 * The marker pair for a target, in the target's own comment syntax.
 *
 * @return array{0:string,1:string}
 */
function markers_for(string $style, string $id): array {
	if ($style === 'hash') {
		return ['# BEGIN GENERATED PLUGIN REGISTRY: ' . $id, '# END GENERATED PLUGIN REGISTRY: ' . $id];
	}
	return [
		'<!-- BEGIN GENERATED PLUGIN REGISTRY: ' . $id . ' -->',
		'<!-- END GENERATED PLUGIN REGISTRY: ' . $id . ' -->',
	];
}

/**
 * Every generated region in the repository.
 *
 * Each target is a self-contained [path, style, marker-id, body] region inside a
 * larger hand-written file. The body is wrapped in markers of the file's own
 * comment syntax:
 *   - 'html'  Markdown:  <!-- BEGIN GENERATED PLUGIN REGISTRY: id -->
 *   - 'hash'  shell/YAML: # BEGIN GENERATED PLUGIN REGISTRY: id
 *
 * @param list<array<string,mixed>> $entries
 * @return list<array{0:string,1:string,2:string,3:string}>
 */
function render_blocks(string $root, array $entries): array {
	$blocks = [];

	// --- configuration -----------------------------------------------------
	$blocks[] = ['compose.yaml', 'hash', 'plugin mounts', render_compose_mounts($entries)];
	$blocks[] = ['scripts/build-plugins-zip.sh', 'hash', 'release build list', render_build_list($entries)];
	$blocks[] = ['scripts/build-plugins-zip.sh', 'hash', 'activation order', render_build_activation_notes($entries)];

	// --- documentation indexes --------------------------------------------
	$blocks[] = ['AGENTS.md', 'html', 'AGENTS.md plugin inventory', render_agents_table($entries)];
	$blocks[] = ['README.md', 'html', 'README.md plugin registry', render_readme_registry($entries)];
	$blocks[] = ['docs/plugins/README.md', 'html', 'docs/plugins/README.md load order', render_plugins_readme($root, $entries)];
	$blocks[] = ['docs/deployment.md', 'html', 'docs/deployment.md activation order', render_deployment_orders($entries)];
	$blocks[] = ['docs/project-inventory.md', 'html', 'docs/project-inventory.md plugins', render_inventory_table($root, $entries)];
	$blocks[] = ['docs/architecture.md', 'html', 'docs/architecture.md plugin load order', render_architecture_order($root, $entries)];

	// --- per-plugin documentation metadata, one region per registered plugin
	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$doc = $entry['documentation'] ?? null;
		if (is_string($doc) && $doc !== '') {
			$blocks[] = [$doc, 'html', 'plugin lifecycle metadata', render_plugin_metadata($root, $entry)];
		}
	}

	return $blocks;
}

/**
 * compose.yaml: the local plugin bind-mount list, in registry order.
 *
 * Uses the repository's existing mount convention verbatim:
 *   ./wp-content/plugins/<slug>:/var/www/html/wp-content/plugins/<slug>
 *
 * mount: true is mounted locally; mount: false is not mounted. This is local
 * development only and never implies production activation.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_compose_mounts(array $entries): string {
	$lines = [
		'      # Source of truth: plugins.json (mount: true), in load order. Do not edit by hand.',
		'      # Regenerate: php scripts/generate-registry-docs.php --write',
	];
	foreach ($entries as $entry) {
		if (($entry['mount'] ?? null) !== true) {
			continue;
		}
		$slug = (string) $entry['slug'];
		$lines[] = '      - ./wp-content/plugins/' . $slug . ':/var/www/html/wp-content/plugins/' . $slug;
	}
	return implode("\n", $lines);
}

/**
 * build-plugins-zip.sh: the release build list, in registry order.
 *
 * Every build:true entry is packaged; every build:false entry is excluded;
 * retired rollouts can never be build-enabled (enforced by validation).
 *
 * @param list<array<string,mixed>> $entries
 */
function render_build_list(array $entries): string {
	$lines = [
		'# Source of truth: plugins.json (build: true), in load order. Do not edit by hand.',
		'# Retired rollout plugins are intentionally absent.',
		'# Regenerate: php scripts/generate-registry-docs.php --write',
		'PLUGIN_SLUGS=(',
	];
	foreach (build_order($entries) as $slug) {
		$lines[] = '    "' . $slug . '"';
	}
	$lines[] = ')';
	return implode("\n", $lines);
}

/**
 * build-plugins-zip.sh: the closing activation-order note, derived from the
 * same registry so the script never states a second order.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_build_activation_notes(array $entries): string {
	$lines = ['echo "  3. Activate plugins in this order:"'];
	foreach ($entries as $entry) {
		$slug     = (string) $entry['slug'];
		$class    = (string) $entry['class'];
		$status   = (string) $entry['status'];
		$produced = ($entry['build'] ?? false) === true;

		if ($status === 'retired' || $class === 'rollout') {
			$note = 'retired rollout — activate → apply → remove';
		} elseif ($class === 'platform') {
			$note = 'production platform';
		} else {
			$note = 'local-only tooling';
		}
		if (!$produced) {
			$note .= ' (not in this release build)';
		}
		$lines[] = sprintf('echo "     - %s  (%s)"', $slug, $note);
	}
	$lines[] = 'echo ""';
	$lines[] = 'echo "  Production steady state (plugins.json production: true, in registry order):"';
	foreach (production_activation_order($entries) as $slug) {
		$lines[] = sprintf('echo "     - %s"', $slug);
	}
	$lines[] = 'echo "  Local-only tooling and retired rollouts are NOT part of the production steady state."';
	return implode("\n", $lines);
}

/**
 * AGENTS.md: the plugin inventory an agent needs - slug, class, status,
 * production, build, mount and documentation path, in load order.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_agents_table(array $entries): string {
	$lines = [
		'All custom plugins live in `wp-content/plugins/`. **Load order matters.**',
		'',
		'This table is generated from [`plugins.json`](../plugins.json) - the single',
		'authoritative registry. Edit the registry and run',
		'`php scripts/generate-registry-docs.php --write`; never hand-edit this table.',
		'',
		'| # | Plugin | Class | Status | Production | Build | Compose mount | Documentation |',
		'|---|--------|-------|--------|------------|-------|---------------|---------------|',
	];
	$i = 0;
	foreach ($entries as $entry) {
		++$i;
		$lines[] = sprintf(
			'| %d | `%s` | %s | %s | %s | %s | %s | `%s` |',
			$i,
			(string) $entry['slug'],
			(string) $entry['class'],
			(string) $entry['status'],
			yn(($entry['production'] ?? false) === true),
			yn(($entry['build'] ?? false) === true),
			yn(($entry['mount'] ?? false) === true),
			(string) $entry['documentation']
		);
	}
	$lines[] = '';
	$lines[] = lifecycle_summary_sentences($entries);
	return implode("\n", $lines);
}

/**
 * One sentence per lifecycle group, shared by several generated blocks.
 *
 * @param list<array<string,mixed>> $entries
 */
function lifecycle_summary_sentences(array $entries): string {
	$platform = slugs_where($entries, static fn(array $e): bool => ($e['class'] ?? '') === 'platform' && ($e['status'] ?? '') === 'active');
	$tooling  = slugs_where($entries, static fn(array $e): bool => ($e['class'] ?? '') === 'tooling' && ($e['status'] ?? '') === 'active');
	$rollouts = slugs_where($entries, static fn(array $e): bool => ($e['class'] ?? '') === 'rollout' && ($e['status'] ?? '') === 'retired');

	$out   = [];
	$out[] = '**Production steady state** (platform, `production: true`) - activate in this order: '
		. implode(' -> ', $platform) . '.';
	$out[] = '**Local-only tooling** (never production): ' . implode(', ', $tooling) . '.';
	$out[] = '**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a'
		. ' production dependency and not in any release ZIP): ' . implode(', ', $rollouts) . '.';
	return implode("\n\n", $out);
}

/**
 * README.md: the human-facing registry summary. The rest of the README stays
 * hand-written.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_readme_registry(array $entries): string {
	$lines = [
		'### Plugin registry',
		'',
		'**The authoritative plugin list is [`plugins.json`](plugins.json)** - it owns load order,',
		'dependencies, lifecycle status, the production activation order, release build inclusion',
		'and local Compose mounts. The table below is generated from it by',
		'`scripts/generate-registry-docs.php`; every derived list in this repository comes from',
		'that one file.',
		'',
		'| # | Plugin | Class | Status | Production | Build | Compose mount | Docs |',
		'|---|--------|-------|--------|------------|-------|---------------|------|',
	];
	$i = 0;
	foreach ($entries as $entry) {
		++$i;
		$doc = (string) $entry['documentation'];
		$lines[] = sprintf(
			'| %d | `%s` | %s | %s | %s | %s | %s | [%s](%s) |',
			$i,
			(string) $entry['slug'],
			(string) $entry['class'],
			(string) $entry['status'],
			yn(($entry['production'] ?? false) === true),
			yn(($entry['build'] ?? false) === true),
			yn(($entry['mount'] ?? false) === true),
			basename($doc, '.md'),
			$doc
		);
	}
	$lines[] = '';
	$lines[] = lifecycle_summary_sentences($entries);
	$lines[] = '';
	$lines[] = 'Versions are **not** duplicated in the registry: the WordPress plugin header is the';
	$lines[] = 'authoritative source, and each entry records only where to read it (`version_source`).';
	return implode("\n", $lines);
}

/**
 * docs/plugins/README.md: the canonical load-order and lifecycle table.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_plugins_readme(string $root, array $entries): string {
	$lines = [
		'This project contains **' . count($entries) . ' custom WordPress plugins**, all in',
		'`wp-content/plugins/`. The authoritative registry is [`plugins.json`](../../plugins.json);',
		'the tables below are generated from it by `scripts/generate-registry-docs.php` and must',
		'not be hand-edited.',
		'',
		'## Load order and lifecycle',
		'',
		'Load order is the row order. Dependencies always precede their dependents.',
		'',
		'| # | Plugin | Class | Status | Production | Build | Mount | Dependencies | Version | Docs |',
		'|---|--------|-------|--------|------------|-------|-------|--------------|---------|------|',
	];
	$i = 0;
	foreach ($entries as $entry) {
		++$i;
		$deps = deps_of($entry);
		$lines[] = sprintf(
			'| %d | `%s` | %s | %s | %s | %s | %s | %s | %s | [%s](%s) |',
			$i,
			(string) $entry['slug'],
			(string) $entry['class'],
			(string) $entry['status'],
			yn(($entry['production'] ?? false) === true),
			yn(($entry['build'] ?? false) === true),
			yn(($entry['mount'] ?? false) === true),
			$deps === [] ? '-' : implode(', ', array_map(static fn(string $d): string => '`' . $d . '`', $deps)),
			version_of($root, $entry),
			basename((string) $entry['documentation'], '.md'),
			'./' . basename((string) $entry['documentation'])
		);
	}
	$lines[] = '';
	$lines[] = lifecycle_summary_sentences($entries);
	$lines[] = '';
	$lines[] = '### Dependency evidence';
	$lines[] = '';
	$lines[] = "Dependencies are **not** inferred. They are read from the plugins' own";
	$lines[] = '`Requires Plugins:` headers, and the generator fails when the registry and the headers';
	$lines[] = 'disagree. Only these headers declare a dependency today:';
	$lines[] = '';
	$lines[] = '- `conexao-event-runtime` -> `Requires Plugins: conexao-data-model`';
	$lines[] = '- `conexao-event-importer` -> `Requires Plugins: conexao-data-model, conexao-event-runtime`';
	$lines[] = '';
	$lines[] = 'WordPress therefore refuses to activate `conexao-event-importer` (and keeps it from';
	$lines[] = 'running) without the event runtime plugin.';
	$lines[] = '';
	$lines[] = '### Lifecycle vocabulary';
	$lines[] = '';
	$lines[] = '| Term | Meaning |';
	$lines[] = '|------|---------|';
	$lines[] = '| `class: platform` | Production runtime the site depends on. |';
	$lines[] = '| `class: tooling` | Local-only operational tooling. Never a production dependency. |';
	$lines[] = '| `class: rollout` | One-shot English-rollout plugin. **Retired** - documented as *activate → apply → remove*. Not a production dependency, never in a release ZIP. |';
	$lines[] = '| `status: active` | In the current lifecycle. |';
	$lines[] = '| `status: retired` | Rollout already applied; kept for history only. |';
	$lines[] = '| `production: true` | Part of the production steady state (activation order). |';
	$lines[] = '| `build: true` | Included in release plugin ZIPs by `scripts/build-plugins-zip.sh`. |';
	$lines[] = '| `mount: true` | Bind-mounted locally by `compose.yaml`. Local development only; it never implies production activation. |';
	$lines[] = '';
	$lines[] = '## Third-Party Plugins';
	$lines[] = '';
	$lines[] = 'No third-party plugins are bundled in this repository. The project relies only on these';
	$lines[] = count($entries) . ' custom plugins and core WordPress functionality (plus Polylang Free 3.8.9 for the';
	$lines[] = 'English layer).';
	$lines[] = '';
	$lines[] = '## Regenerating this page';
	$lines[] = '';
	$lines[] = '```bash';
	$lines[] = 'php scripts/generate-registry-docs.php --check   # validate + drift gate (zero writes)';
	$lines[] = 'php scripts/generate-registry-docs.php --write   # write generated regions';
	$lines[] = '```';
	return implode("\n", $lines);
}

/**
 * docs/deployment.md: the production activation order, the release build
 * output, local-only tooling and retired rollouts - all registry-derived.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_deployment_orders(array $entries): string {
	$production = production_activation_order($entries);
	$build      = build_order($entries);
	$tooling    = slugs_where($entries, static fn(array $e): bool => ($e['class'] ?? '') === 'tooling');
	$rollouts   = slugs_where($entries, static fn(array $e): bool => ($e['class'] ?? '') === 'rollout');

	$lines = [
		'Generated from [`plugins.json`](../../plugins.json) by',
		'`scripts/generate-registry-docs.php`. Do not hand-edit this section.',
		'',
		'### Production activation order',
		'',
		'The filtered subset of the registry load order where `production: true`. It contains no',
		'`production: false` entry:',
		'',
	];
	$i = 0;
	foreach ($production as $slug) {
		++$i;
		$lines[] = $i . '. `' . $slug . '`';
	}
	$lines[] = '';
	$lines[] = 'Production needs exactly these ' . count($production) . ' platform plugins. They are the only';
	$lines[] = 'plugins that must be installed **and active** on production.';
	$lines[] = '';
	$lines[] = '### Release build output';
	$lines[] = '';
	$lines[] = '```bash';
	$lines[] = './scripts/build-plugins-zip.sh';
	$lines[] = '```';
	$lines[] = '';
	$lines[] = 'Produces one ZIP per `build: true` entry (' . count($build) . ' files), in registry order:';
	$lines[] = '';
	foreach ($build as $slug) {
		$lines[] = '- `dist/' . $slug . '.zip`';
	}
	$lines[] = '';
	$lines[] = 'Import via WordPress Admin -> Plugins -> Add New -> Upload Plugin, then activate in';
	$lines[] = 'the production order above.';
	$lines[] = '';
	$lines[] = '### Local-only tooling (not production)';
	$lines[] = '';
	$lines[] = 'Built and mounted so a developer can run them locally, but **not** production';
	$lines[] = 'dependencies and never left active on production:';
	$lines[] = '';
	foreach ($tooling as $slug) {
		$lines[] = '- `' . $slug . '`';
	}
	$lines[] = '';
	$lines[] = '### Retired rollout plugins (historical tooling)';
	$lines[] = '';
	$lines[] = 'These one-shot English-rollout plugins have already been applied. They are **not** part';
	$lines[] = 'of a normal production release: each is `build: false` (no ZIP is produced) and none is';
	$lines[] = 'activated in the production steady state. They remain in the repository, and are locally';
	$lines[] = 'mounted only so the historical importer stays reproducible.';
	$lines[] = '';
	$lines[] = '**Lifecycle: activate → apply → remove.**';
	$lines[] = '';
	foreach ($rollouts as $slug) {
		$lines[] = '- `' . $slug . '` - activate → apply → remove';
	}
	return implode("\n", $lines);
}

/**
 * docs/project-inventory.md: the plugin inventory. The version column is read
 * from each plugin header, so this table can never go stale against the code.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_inventory_table(string $root, array $entries): string {
	$lines = [
		'Generated from [`plugins.json`](../plugins.json) by `scripts/generate-registry-docs.php`.',
		'Versions are read from the plugin headers at generation time, which is the authoritative',
		'source. Do not hand-edit this table.',
		'',
		'| Slug | Version | Class | Status | Production | Build | Mount | Path |',
		'|------|---------|-------|--------|------------|-------|-------|------|',
	];
	foreach ($entries as $entry) {
		$slug = (string) $entry['slug'];
		$lines[] = sprintf(
			'| %s | %s | %s | %s | %s | %s | %s | `wp-content/plugins/%s/` |',
			$slug,
			version_of($root, $entry),
			(string) $entry['class'],
			(string) $entry['status'],
			yn(($entry['production'] ?? false) === true),
			yn(($entry['build'] ?? false) === true),
			yn(($entry['mount'] ?? false) === true),
			$slug
		);
	}
	$lines[] = '';
	$lines[] = lifecycle_summary_sentences($entries);
	return implode("\n", $lines);
}

/**
 * docs/architecture.md: the plugin load order as prose, derived from the
 * registry so the architecture doc cannot state a different order.
 *
 * @param list<array<string,mixed>> $entries
 */
function render_architecture_order(string $root, array $entries): string {
	$lines = [
		'Generated from [`plugins.json`](../plugins.json) by `scripts/generate-registry-docs.php`.',
		'Plugins load in registry order, and dependencies always precede their dependents.',
		'',
	];
	$i = 0;
	foreach ($entries as $entry) {
		++$i;
		$class = (string) $entry['class'];

		$note = match ($class) {
			'platform' => '**Required on production.**',
			'tooling'  => '**Never required on production.**',
			default    => 'Retired rollout tooling - *activate → apply → remove*. Not a production dependency.',
		};

		$line = sprintf(
			'%d. **%s** (`%s`, %s) v%s. %s',
			$i,
			(string) $entry['slug'],
			$class,
			(string) $entry['status'],
			version_of($root, $entry),
			$note
		);

		$deps = deps_of($entry);
		if ($deps !== []) {
			$line .= ' Declared dependencies (`Requires Plugins` header): '
				. implode(', ', array_map(static fn(string $d): string => '`' . $d . '`', $deps)) . '.';
		}
		$lines[] = $line;
	}
	$lines[] = '';
	$lines[] = 'The authoritative registry is [`plugins.json`](../plugins.json): the load order, the';
	$lines[] = 'production activation order, the release build list and the local Compose mount list are';
	$lines[] = 'all derived from it by `scripts/generate-registry-docs.php`.';
	return implode("\n", $lines);
}

/**
 * Per-plugin documentation: the generated lifecycle metadata block.
 *
 * @param array<string,mixed> $entry
 */
function render_plugin_metadata(string $root, array $entry): string {
	$slug   = (string) $entry['slug'];
	$class  = (string) $entry['class'];
	$status = (string) $entry['status'];
	$deps   = deps_of($entry);

	$lines = [
		'| | |',
		'|---|---|',
		'| **Status** | ' . $status . ' |',
		'| **Class** | ' . $class . ' |',
		'| **Production** | ' . yn(($entry['production'] ?? false) === true) . ' |',
		'| **Build** | ' . yn(($entry['build'] ?? false) === true) . ' |',
		'| **Compose mount** | ' . yn(($entry['mount'] ?? false) === true) . ' |',
		'| **Dependencies** | ' . ($deps === [] ? 'none' : implode(', ', array_map(static fn(string $d): string => '`' . $d . '`', $deps))) . ' |',
		'| **Version** | ' . version_of($root, $entry) . ' (authoritative source: `' . (string) $entry['version_source'] . '` header) |',
		'| **Registry** | [`plugins.json`](../../plugins.json) |',
	];

	if ($class === 'rollout' || $status === 'retired') {
		$lines[] = '';
		$lines[] = '> **Lifecycle: activate → apply → remove.** This is a retired one-shot rollout plugin.';
		$lines[] = '> It is **not** a production steady-state dependency and is **not** included in a';
		$lines[] = '> release plugin ZIP. It is kept in the repository (and locally mounted) only so the';
		$lines[] = '> historical importer stays reproducible.';
	} elseif ($class === 'tooling') {
		$lines[] = '';
		$lines[] = '> **Local-only tooling.** Not a production steady-state dependency.';
	} else {
		$lines[] = '';
		$lines[] = '> **Production platform plugin.** Part of the production steady state.';
	}

	return implode("\n", $lines);
}

/**
 * Is the generated region between the markers already exactly the expected body?
 *
 * @return bool true when the region is up to date.
 */
function region_is_current(string $content, string $style, string $id, string $body): bool {
	[$begin, $end] = markers_for($style, $id);

	$begin_at = strpos($content, $begin);
	if ($begin_at === false) {
		return false;
	}
	$end_at = strpos($content, $end, $begin_at + strlen($begin));
	if ($end_at === false) {
		return false;
	}

	$expected = $begin . "\n" . $body . "\n" . $end;
	return substr($content, $begin_at, $end_at + strlen($end) - $begin_at) === $expected;
}

/**
 * Replace the generated body between a marker pair.
 *
 * Fails safely: a missing, unbalanced or duplicated marker is reported as a
 * problem and the file is left completely untouched, so arbitrary hand-written
 * content is never overwritten.
 *
 * @return array{0:string,1:bool} [new-content, changed]
 */
function apply_region(string $content, string $style, string $id, string $body): array {
	[$begin, $end] = markers_for($style, $id);

	$begin_at = strpos($content, $begin);
	if ($begin_at === false) {
		Registry_Problems::add(sprintf('missing BEGIN marker for region "%s" (expected: %s)', $id, $begin));
		return [$content, false];
	}
	$end_at = strpos($content, $end, $begin_at + strlen($begin));
	if ($end_at === false) {
		Registry_Problems::add(sprintf('missing END marker for region "%s" (expected: %s)', $id, $end));
		return [$content, false];
	}
	if (strpos($content, $end, $end_at + strlen($end)) !== false) {
		Registry_Problems::add(sprintf('region "%s" has more than one END marker; refusing to guess', $id));
		return [$content, false];
	}

	$head = substr($content, 0, $begin_at + strlen($begin));
	$tail = substr($content, $end_at);
	$new  = $head . "\n" . $body . "\n" . $tail;

	return [$new, $new !== $content];
}

function report_problems(string $headline): void {
	fwrite(STDERR, 'ERROR: ' . $headline . ":\n");
	foreach (Registry_Problems::all() as $p) {
		fwrite(STDERR, '  - ' . $p . "\n");
	}
}

/**
 * Main entrypoint.
 *
 * --check : validate, render in memory, compare with the working tree, ZERO writes.
 * --write : validate, then write only the generated regions that changed.
 *
 * @param list<string> $argv
 */
function main(array $argv): int {
	$mode        = null;
	$build_count = false;
	foreach (array_slice($argv, 1) as $arg) {
		switch ($arg) {
			case '--check':
			case '--write':
				if ($mode !== null && $mode !== $arg) {
					fwrite(STDERR, "ERROR: --check and --write are mutually exclusive.\n");
					return EXIT_USAGE;
				}
				$mode = $arg;
				break;
			case '--build-count':
				$build_count = true;
				break;
			case '-h':
			case '--help':
				echo usage() . "\n";
				return EXIT_OK;
			default:
				fwrite(STDERR, 'ERROR: unknown argument: ' . $arg . "\n\n" . usage() . "\n");
				return EXIT_USAGE;
		}
	}

	if ($mode === null && !$build_count) {
		fwrite(STDERR, "ERROR: no mode given.\n\n" . usage() . "\n");
		return EXIT_USAGE;
	}
	if ($mode !== null && $build_count) {
		fwrite(STDERR, "ERROR: --build-count cannot be combined with --check or --write.\n");
		return EXIT_USAGE;
	}

	$root = dirname(__DIR__);

	// --- 1. Load + validate ------------------------------------------------
	$loaded  = load_registry($root);
	$entries = $loaded['entries'];

	if ($entries !== []) {
		validate_entries($root, $entries);
	}

	// Validation gates everything: an invalid registry never mutates a file.
	if (Registry_Problems::any()) {
		report_problems('registry validation failed');
		return EXIT_FAIL;
	}

	// Read-only query used by build-plugins-zip.sh to prove its generated list
	// and the registry agree. Writes nothing.
	if ($build_count) {
		echo count(build_order($entries)), "\n";
		return EXIT_OK;
	}

	// --- 2. Render every generated region in memory ------------------------
	$blocks = render_blocks($root, $entries);

	// A registry entry pointing at a documentation file that does not exist is
	// a hard error: the metadata block would have nowhere to land.
	$before = Registry_Problems::count();
	foreach ($blocks as $block) {
		if (!is_file($root . '/' . $block[0])) {
			Registry_Problems::add('generated target does not exist: ' . $block[0]);
		}
	}
	if (Registry_Problems::count() > $before) {
		report_problems('generated-target validation failed');
		return EXIT_FAIL;
	}

	// --- 3. Compare (check) or write (write) -------------------------------
	$stale  = [];
	$changed = 0;

	foreach ($blocks as [$path, $style, $id, $body]) {
		$full = $root . '/' . $path;
		$content = file_get_contents($full);
		if ($content === false) {
			report_problems('could not read ' . $path);
			return EXIT_FAIL;
		}

		if ($mode === '--check') {
			if (!region_is_current($content, $style, $id, $body)) {
				$stale[] = $path . ' :: ' . $id;
			}
			continue;
		}

		[$new, $did_change] = apply_region($content, $style, $id, $body);
		if (Registry_Problems::count() > $before) {
			report_problems('marker validation failed');
			return EXIT_FAIL;
		}
		if (!$did_change) {
			continue;
		}
		if (file_put_contents($full, $new) === false) {
			report_problems('failed to write ' . $path);
			return EXIT_FAIL;
		}
		++$changed;
	}

	if ($mode === '--check') {
		if ($stale !== []) {
			fwrite(STDERR, "ERROR: generated output is stale. Run:\n");
			fwrite(STDERR, "  php scripts/generate-registry-docs.php --write\n\n");
			fwrite(STDERR, "Stale generated regions:\n");
			foreach ($stale as $s) {
				fwrite(STDERR, '  - ' . $s . "\n");
			}
			return EXIT_FAIL;
		}
		printf(
			"registry OK: %d plugins validated, %d generated regions current (zero writes).\n",
			count($entries),
			count($blocks)
		);
		return EXIT_OK;
	}

	printf(
		"registry written: %d plugins, %d generated regions, %d file(s) changed.\n",
		count($entries),
		count($blocks),
		$changed
	);
	return EXIT_OK;
}

exit(main(is_array($argv ?? null) ? $argv : []));
