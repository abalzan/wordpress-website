/**
 * Christmas experience package — package behaviour.
 *
 * Three responsibilities, all small and all local to this package:
 *
 *   1. `createJourney()` — upgrades the server-rendered "two skies" pair into an
 *      accessible WAI-ARIA tabs widget (roving tabindex, Arrow/Home/End, one visible
 *      panel, the shared star travelling once towards the chosen sky). It is NOT a
 *      scheduler: no timers, no intervals, no storage, no network, no analytics. The
 *      state lives in the DOM for the life of the page and is gone on reload.
 *   2. `createStarDecoration()` — the ambient decoration factory. The timing, the
 *      per-session cap, the visibility pause and the node cleanup all belong to the
 *      shared framework (`assets/js/commemorative.js`); this file only supplies the
 *      artwork, so a second ambient scheduler can never appear.
 *   3. `boot()` — wires both, in the browser only.
 *
 * The controller takes its scope element as an argument, so `tests/node/
 * christmas-journey.test.js` drives the REAL code with a fake DOM.
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
		root.ConexaoChristmas = api;
		api.boot();
	}
})(typeof window !== 'undefined' ? window : null, function (win) {
	'use strict';

	var TAB_SELECTOR = '[data-ce-journey-tab]';
	var PANEL_SELECTOR = '[data-ce-journey-panel]';
	var CONTROLS_SELECTOR = '[data-ce-journey-controls]';
	var TABS_SELECTOR = '[data-ce-journey-tabs]';
	var CONTROLS_LABEL_ID = 'ce-christmas-journey-controls-label';

	function toArray(list) {
		var out = [];
		var index;

		if (!list || typeof list.length !== 'number') {
			return out;
		}

		for (index = 0; index < list.length; index++) {
			out.push(list[index]);
		}

		return out;
	}

	function attr(element, name) {
		return element && typeof element.getAttribute === 'function' ? element.getAttribute(name) : null;
	}

	/**
	 * Build the two-sky tabs widget inside one journey scope.
	 *
	 * @param {Element} scope The `[data-ce-journey]` element.
	 * @returns {Object|null} `{select, getState}` or null when the markup is incomplete.
	 */
	function createJourney(scope) {
		if (!scope || typeof scope.querySelectorAll !== 'function') {
			return null;
		}

		var controls = typeof scope.querySelector === 'function' ? scope.querySelector(CONTROLS_SELECTOR) : null;
		var tablist = controls && typeof controls.querySelector === 'function' ? controls.querySelector(TABS_SELECTOR) : null;
		var tabs = toArray(scope.querySelectorAll(TAB_SELECTOR));
		var panels = toArray(scope.querySelectorAll(PANEL_SELECTOR));

		// Fail closed: an incomplete scene keeps the plain server-rendered markup, where
		// both skies are already visible and nothing pretends to be a control.
		if (!controls || !tablist || tabs.length < 2 || panels.length !== tabs.length) {
			return null;
		}

		var current = attr(scope, 'data-ce-journey-state') || attr(tabs[0], 'data-ce-journey-tab');

		function indexOfTab(key) {
			var index;

			for (index = 0; index < tabs.length; index++) {
				if (attr(tabs[index], 'data-ce-journey-tab') === key) {
					return index;
				}
			}

			return -1;
		}

		function select(key, moveFocus) {
			var index;
			var target = null;

			if (indexOfTab(key) < 0) {
				return current;
			}

			for (index = 0; index < tabs.length; index++) {
				var isSelected = attr(tabs[index], 'data-ce-journey-tab') === key;

				tabs[index].setAttribute('aria-selected', isSelected ? 'true' : 'false');
				tabs[index].setAttribute('tabindex', isSelected ? '0' : '-1');

				if (tabs[index].classList && typeof tabs[index].classList.toggle === 'function') {
					tabs[index].classList.toggle('is-selected', isSelected);
				}

				if (isSelected) {
					target = tabs[index];
				}
			}

			for (index = 0; index < panels.length; index++) {
				var isShown = attr(panels[index], 'data-ce-journey-panel') === key;

				if (isShown) {
					panels[index].removeAttribute('hidden');
					panels[index].setAttribute('tabindex', '0');
				} else {
					panels[index].setAttribute('hidden', 'hidden');
					panels[index].removeAttribute('tabindex');
				}
			}

			scope.setAttribute('data-ce-journey-state', key);
			current = key;

			if (moveFocus && target && typeof target.focus === 'function') {
				target.focus();
			}

			return current;
		}

		function onClick(event) {
			var key = attr(event.currentTarget, 'data-ce-journey-tab');

			if (key) {
				select(key, false);
			}
		}

		function onKeydown(event) {
			var key = event && event.key;
			var index = indexOfTab(attr(event.currentTarget, 'data-ce-journey-tab'));
			var next = -1;

			if (index < 0) {
				return;
			}

			if ('ArrowRight' === key || 'ArrowDown' === key) {
				next = (index + 1) % tabs.length;
			} else if ('ArrowLeft' === key || 'ArrowUp' === key) {
				next = (index - 1 + tabs.length) % tabs.length;
			} else if ('Home' === key) {
				next = 0;
			} else if ('End' === key) {
				next = tabs.length - 1;
			}

			if (next < 0) {
				return;
			}

			if (typeof event.preventDefault === 'function') {
				event.preventDefault();
			}

			select(attr(tabs[next], 'data-ce-journey-tab'), true);
		}

		// --- Upgrade: roles and relationships first, then reveal the control. ---
		tablist.setAttribute('role', 'tablist');
		tablist.setAttribute('aria-labelledby', CONTROLS_LABEL_ID);

		for (var index = 0; index < tabs.length; index++) {
			tabs[index].setAttribute('role', 'tab');
			tabs[index].addEventListener('click', onClick);
			tabs[index].addEventListener('keydown', onKeydown);
		}

		for (var panelIndex = 0; panelIndex < panels.length; panelIndex++) {
			panels[panelIndex].setAttribute('role', 'tabpanel');
			panels[panelIndex].setAttribute(
				'aria-labelledby',
				'ce-christmas-journey-tab-' + attr(panels[panelIndex], 'data-ce-journey-panel')
			);
		}

		if (scope.classList && typeof scope.classList.add === 'function') {
			scope.classList.add('is-enhanced');
		}

		controls.removeAttribute('hidden');

		select(current, false);

		return {
			select: select,
			getState: function () {
				return current;
			}
		};
	}

	/**
	 * The ambient decoration: the same star as a small, decorative image.
	 *
	 * @returns {Element|null} A node for the shared scheduler, or null without an asset.
	 */
	function createStarDecoration() {
		if (!win || !win.document) {
			return null;
		}

		var marker = win.document.querySelector('.ce-christmas-ambient-marker');
		var asset = marker ? attr(marker, 'data-ce-star-asset') : '';

		if (!asset) {
			return null;
		}

		var node = win.document.createElement('span');
		var image = win.document.createElement('img');

		image.src = asset;
		image.alt = '';
		image.setAttribute('aria-hidden', 'true');
		image.setAttribute('draggable', 'false');
		image.width = 60;
		image.height = 75;
		node.appendChild(image);

		// Bounded, non-obstructive placement: near an edge, never centred, never over
		// the header or a fixed control (r10 §7.7).
		var edge = Math.random() < 0.5 ? 'left' : 'right';
		var top = 14 + Math.random() * 56;

		node.style[edge] = (2 + Math.random() * 6).toFixed(2) + '%';
		node.style.top = top.toFixed(2) + '%';
		node.style.width = (44 + Math.random() * 26).toFixed(0) + 'px';

		return node;
	}

	/** Upgrade every journey scene on the page. */
	function bootJourneys() {
		if (!win || !win.document || typeof win.document.querySelectorAll !== 'function') {
			return [];
		}

		var scopes = toArray(win.document.querySelectorAll('[data-ce-journey]'));
		var journeys = [];

		for (var index = 0; index < scopes.length; index++) {
			var journey = createJourney(scopes[index]);

			if (journey) {
				journeys.push(journey);
			}
		}

		return journeys;
	}

	/** Boot the ambient placement through the shared framework. */
	function bootAmbient() {
		var shared = win && win.ConexaoCommemorative;

		if (!shared || typeof shared.bootAmbientPlacement !== 'function') {
			return null;
		}

		return shared.bootAmbientPlacement({
			packageSlug: 'christmas',
			decorate: createStarDecoration
		});
	}

	function boot() {
		if (!win || !win.document) {
			return;
		}

		function start() {
			bootJourneys();
			bootAmbient();
		}

		if ('loading' === win.document.readyState) {
			win.document.addEventListener('DOMContentLoaded', start);
		} else {
			start();
		}
	}

	return {
		createJourney: createJourney,
		createStarDecoration: createStarDecoration,
		bootJourneys: bootJourneys,
		bootAmbient: bootAmbient,
		boot: boot
	};
});

