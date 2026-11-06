/**
 * Commemorative presentation framework — shared JavaScript.
 *
 * Loaded ONLY when at least one commemorative placement renders (r10 §7.9).
 * It provides the reusable capabilities every package needs, and no artwork:
 *
 *   1. `prefersReducedMotion()` — the single reduced-motion query.
 *   2. `sessionCounter()` — a namespaced, tab-scoped counter used for the
 *      per-package session cap. `sessionStorage` survives internal navigation and
 *      reloads in the SAME tab and is cleared when the tab (the session) ends, so
 *      a genuinely new session starts the count again. Nothing is written to
 *      `localStorage`, cookies or the server: no persistent tracking.
 *   3. `createAmbientScheduler()` — ONE timer for the whole placement (never one
 *      per decoration), randomised intervals inside the configured bounds, at
 *      most one decoration at a time, node removal after each occurrence, a
 *      visibility pause with a fresh interval plus a cooldown, and a hard stop
 *      under reduced motion.
 *
 * The module is dependency-injected (document, timers, clock, storage, media
 * query) so the same code is executed by the Node runtime test in
 * `tests/node/commemorative-ambient.test.js` — the behaviour is proven by
 * running it, not by grepping its source.
 *
 * @package Conexao_BR_Irlanda
 */

(function (root, factory) {
	'use strict';

	var api = factory(root);

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}

	if (root && typeof root === 'object') {
		root.ConexaoCommemorative = api;
	}
})(typeof window !== 'undefined' ? window : null, function (win) {
	'use strict';

	var STORAGE_PREFIX = 'conexao-ce-ambient:';
	var RESUME_COOLDOWN_MS = 3000;

	function prefersReducedMotion() {
		if (!win || typeof win.matchMedia !== 'function') {
			return false;
		}
		return !!win.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/**
	 * A tab-scoped counter for one package occurrence.
	 *
	 * @param {Object} options {package, year, storage}
	 * @returns {Object} {key, read, increment, reset}
	 */
	function sessionCounter(options) {
		var opts = options || {};
		var packageSlug = String(opts.package || 'package');
		var year = String(opts.year || 'current');
		var storage = opts.storage || null;
		var key = STORAGE_PREFIX + packageSlug + ':' + year;
		var memory = 0;

		function read() {
			if (!storage) {
				return memory;
			}
			try {
				var raw = storage.getItem(key);
				var parsed = raw ? parseInt(raw, 10) : 0;
				return isFinite(parsed) && parsed > 0 ? parsed : 0;
			} catch (error) {
				return memory;
			}
		}

		function increment() {
			var next = read() + 1;
			memory = next;
			if (storage) {
				try {
					storage.setItem(key, String(next));
				} catch (error) {
					// Storage unavailable: the in-memory count still bounds this page.
				}
			}
			return next;
		}

		function reset() {
			memory = 0;
			if (!storage) {
				return;
			}
			try {
				storage.removeItem(key);
			} catch (error) {
				// Nothing to do: the key simply stays.
			}
		}

		return { key: key, read: read, increment: increment, reset: reset };
	}

	function normaliseConfig(config) {
		var source = config && typeof config === 'object' ? config : {};
		var min = Math.max(1000, parseInt(source.intervalMinMs, 10) || 60000);
		var max = Math.max(min, parseInt(source.intervalMaxMs, 10) || min);
		var subsequentMin = Math.max(1000, parseInt(source.subsequentIntervalMinMs, 10) || min);
		var subsequentMax = Math.max(subsequentMin, parseInt(source.subsequentIntervalMaxMs, 10) || subsequentMin);
		var durationMin = Math.max(200, parseInt(source.durationMinMs, 10) || 2000);
		var durationMax = Math.max(durationMin, parseInt(source.durationMaxMs, 10) || durationMin);

		return {
			energy: source.energy || 'calm',
			intervalMinMs: min,
			intervalMaxMs: max,
			subsequentIntervalMinMs: subsequentMin,
			subsequentIntervalMaxMs: subsequentMax,
			durationMinMs: durationMin,
			durationMaxMs: durationMax,
			maxSimultaneous: 1,
			sessionCap: Math.max(1, parseInt(source.sessionCap, 10) || 1)
		};
	}

	function randomBetween(min, max, random) {
		var r = typeof random === 'function' ? random() : Math.random();
		return min + Math.floor(r * (max - min + 1));
	}

	/**
	 * Create the single ambient scheduler for one placement.
	 *
	 * @param {Object} options Injected collaborators.
	 * @returns {Object} {start, stop, schedule, show, stats}
	 */
	function createAmbientScheduler(options) {
		var opts = options || {};
		var doc = opts.document || (win && win.document) || null;
		var container = opts.container || null;
		var config = normaliseConfig(opts.config);
		var createDecoration = opts.createDecoration;
		var timer = typeof opts.setTimeout === 'function' ? opts.setTimeout : (win && win.setTimeout);
		var untimer = typeof opts.clearTimeout === 'function' ? opts.clearTimeout : (win && win.clearTimeout);
		var random = typeof opts.random === 'function' ? opts.random : Math.random;
		var visibility = typeof opts.visibility === 'function' ? opts.visibility : function () {
			return doc ? doc.visibilityState : 'visible';
		};
		var reduced = typeof opts.reducedMotion === 'function' ? opts.reducedMotion : prefersReducedMotion;
		var counter = opts.counter || sessionCounter({
			package: opts.packageSlug,
			year: opts.occurrenceYear,
			storage: opts.storage
		});

		var handle = null;
		var activeNode = null;
		var removeTimer = null;
		var appeared = false;
		var stopped = false;

		function isEligible() {
			if (stopped || !container) {
				return false;
			}
			if (reduced()) {
				return false;
			}
			if ('visible' !== visibility()) {
				return false;
			}
			return counter.read() < config.sessionCap;
		}

		function nextDelay() {
			return appeared
				? randomBetween(config.subsequentIntervalMinMs, config.subsequentIntervalMaxMs, random)
				: randomBetween(config.intervalMinMs, config.intervalMaxMs, random);
		}

		function clearPending() {
			if (null !== handle && untimer) {
				untimer(handle);
			}
			handle = null;
		}

		function removeActive() {
			if (null !== removeTimer && untimer) {
				untimer(removeTimer);
				removeTimer = null;
			}
			if (activeNode && activeNode.parentNode) {
				activeNode.parentNode.removeChild(activeNode);
			}
			activeNode = null;
		}

		function schedule(extraDelay) {
			clearPending();

			if (!isEligible() || null !== activeNode) {
				return;
			}

			var delay = nextDelay() + (typeof extraDelay === 'number' ? extraDelay : 0);

			handle = timer(function () {
				handle = null;
				show();
			}, delay);
		}

		function show() {
			if (!isEligible() || null !== activeNode) {
				return;
			}

			var node = createDecoration ? createDecoration() : null;

			if (!node) {
				schedule();
				return;
			}

			node.className = (node.className ? node.className + ' ' : '') + 'ce-ambient-decoration';
			node.setAttribute('aria-hidden', 'true');
			node.setAttribute('tabindex', '-1');
			node.style.pointerEvents = 'none';

			container.appendChild(node);
			activeNode = node;
			appeared = true;
			counter.increment();

			var duration = randomBetween(config.durationMinMs, config.durationMaxMs, random);

			removeTimer = timer(function () {
				removeTimer = null;
				removeActive();
				schedule();
			}, duration);
		}

		function onVisibilityChange() {
			if ('visible' === visibility()) {
				schedule(RESUME_COOLDOWN_MS);
			} else {
				clearPending();
				if (activeNode) {
					removeActive();
				}
			}
		}

		function start() {
			stopped = false;
			schedule();
		}

		function stop() {
			stopped = true;
			clearPending();
			removeActive();
		}

		return {
			start: start,
			stop: stop,
			schedule: schedule,
			show: show,
			onVisibilityChange: onVisibilityChange,
			isEligible: isEligible,
			isRunning: function () {
				return !stopped;
			},
			stats: function () {
				return {
					count: counter.read(),
					cap: config.sessionCap,
					appeared: appeared,
					active: null !== activeNode
				};
			}
		};
	}


	/**
	 * Boot every ambient placement rendered on the page.
	 *
	 * Each package supplies its own decoration factory; the timer, the cap, the
	 * visibility handling and the cleanup stay here, so no package may ship a
	 * second AMBIENT scheduler. The one approved exception is the Halloween
	 * mobile navigation surprise in
	 * `presentations/halloween/halloween.js`, whose own scheduler drives that
	 * package's navigation effect (never the ambient layer).
	 *
	 * @param {Object} options {packageSlug, decorate}
	 * @returns {Object|null} The running scheduler, or null.
	 */
	function bootAmbientPlacement(options) {
		var opts = options || {};

		if (!win || !win.document) {
			return null;
		}

		var container = win.document.querySelector('[data-ce-placement="site_wide_ambient"][data-ce-package="' + opts.packageSlug + '"]');

		if (!container || typeof opts.decorate !== 'function') {
			return null;
		}

		var config = {};

		try {
			config = JSON.parse(container.getAttribute('data-ce-ambient-config') || '{}');
		} catch (error) {
			config = {};
		}

		var scheduler = createAmbientScheduler({
			container: container,
			config: config,
			packageSlug: opts.packageSlug,
			occurrenceYear: config.occurrenceYear,
			storage: (function () {
				try {
					return win.sessionStorage;
				} catch (error) {
					return null;
				}
			}()),
			createDecoration: opts.decorate
		});

		win.document.addEventListener('visibilitychange', scheduler.onVisibilityChange);
		win.addEventListener('pagehide', scheduler.stop);
		win.addEventListener('pageshow', function (event) {
			if (event.persisted) {
				scheduler.start();
			}
		});

		if (win.matchMedia) {
			var query = win.matchMedia('(prefers-reduced-motion: reduce)');
			var onChange = function () {
				if (query.matches) {
					scheduler.stop();
				} else {
					scheduler.start();
				}
			};

			if (typeof query.addEventListener === 'function') {
				query.addEventListener('change', onChange);
			} else if (typeof query.addListener === 'function') {
				query.addListener(onChange);
			}
		}

		scheduler.start();

		return scheduler;
	}

	return {
		STORAGE_PREFIX: STORAGE_PREFIX,
		RESUME_COOLDOWN_MS: RESUME_COOLDOWN_MS,
		prefersReducedMotion: prefersReducedMotion,
		sessionCounter: sessionCounter,
		normaliseConfig: normaliseConfig,
		randomBetween: randomBetween,
		createAmbientScheduler: createAmbientScheduler,
		bootAmbientPlacement: bootAmbientPlacement
	};
});

