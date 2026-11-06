/**
 * Runtime test — the Christmas two-sky journey controller.
 *
 * Executed with `node --test` (see tests/scripts/verify-commemorative-runtime.py).
 * It loads the REAL `assets/js/presentations/christmas/christmas.js` and drives
 * `createJourney()` with a fake DOM, so the behaviour is PROVEN BY RUNNING IT — not by
 * searching its source text.
 *
 * Covered:
 *   - the upgrade sets role=tablist/tab/tabpanel, aria-labelledby and reveals the
 *     control that the server deliberately ships `hidden`;
 *   - exactly one panel is visible and one tab is in the tab order after init;
 *   - selecting the other sky moves aria-selected, tabindex, `hidden` and the
 *     `data-ce-journey-state` the CSS animates the shared star from;
 *   - a click selects; ArrowRight/ArrowLeft/Home/End move the selection and the focus;
 *   - an incomplete scene fails closed: nothing is revealed, no roles are invented;
 *   - IT IS NOT A SCHEDULER: no timer and no interval is ever created.
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

const api = require(path.join(
	__dirname,
	'..',
	'..',
	'wp-content',
	'themes',
	'conexao-br-irlanda',
	'assets',
	'js',
	'presentations',
	'christmas',
	'christmas.js'
));

/* ------------------------------------------------------------------------- */
/* Test doubles                                                               */
/* ------------------------------------------------------------------------- */

/**
 * A minimal element: attributes, classList, listeners and focus state.
 *
 * @param {Object} attributes Initial attributes.
 * @returns {Object} Fake element.
 */
function element(attributes) {
	const store = Object.assign({}, attributes || {});
	const listeners = {};

	const node = {
		attributes: store,
		hidden: false,
		focusCount: 0,
		classList: {
			names: new Set(),
			add(name) {
				node.classList.names.add(name);
			},
			toggle(name, force) {
				if (force) {
					node.classList.names.add(name);
				} else {
					node.classList.names.delete(name);
				}
			},
			contains(name) {
				return node.classList.names.has(name);
			}
		},
		getAttribute(name) {
			return Object.prototype.hasOwnProperty.call(store, name) ? store[name] : null;
		},
		setAttribute(name, value) {
			store[name] = String(value);
		},
		removeAttribute(name) {
			delete store[name];
		},
		hasAttribute(name) {
			return Object.prototype.hasOwnProperty.call(store, name);
		},
		addEventListener(type, handler) {
			listeners[type] = listeners[type] || [];
			listeners[type].push(handler);
		},
		dispatch(type, event) {
			const payload = Object.assign({ currentTarget: node, defaultPrevented: false }, event || {});
			payload.preventDefault = () => {
				payload.defaultPrevented = true;
			};
			(listeners[type] || []).forEach((handler) => handler(payload));
			return payload;
		},
		focus() {
			node.focusCount += 1;
		}
	};

	return node;
}


/**
 * Build one complete journey scene, exactly as the server renders it.
 *
 * @returns {Object} The scene parts.
 */
function scene() {
	const tabs = ['brasil', 'irlanda'].map((key) =>
		element({
			'data-ce-journey-tab': key,
			id: 'ce-christmas-journey-tab-' + key,
			'aria-controls': 'ce-christmas-journey-panel-' + key,
			tabindex: '-1'
		})
	);

	const panels = ['brasil', 'irlanda'].map((key) =>
		element({
			'data-ce-journey-panel': key,
			id: 'ce-christmas-journey-panel-' + key
		})
	);

	const tablist = element({});
	const controls = element({ hidden: 'hidden' });
	const scope = element({ 'data-ce-journey-state': 'brasil' });

	controls.querySelector = (selector) => ('[data-ce-journey-tabs]' === selector ? tablist : null);

	scope.querySelector = (selector) => ('[data-ce-journey-controls]' === selector ? controls : null);
	scope.querySelectorAll = (selector) => {
		if ('[data-ce-journey-tab]' === selector) {
			return tabs;
		}
		if ('[data-ce-journey-panel]' === selector) {
			return panels;
		}
		return [];
	};

	return { scope, controls, tablist, tabs, panels };
}

/* ------------------------------------------------------------------------- */
/* Tests                                                                      */
/* ------------------------------------------------------------------------- */

test('the upgrade reveals the control and sets the tab semantics', () => {
	const parts = scene();
	const journey = api.createJourney(parts.scope);

	assert.ok(journey, 'a complete scene upgrades');
	assert.strictEqual(parts.tablist.getAttribute('role'), 'tablist');
	assert.strictEqual(parts.controls.hasAttribute('hidden'), false, 'the hidden control is revealed');
	assert.strictEqual(parts.scope.classList.contains('is-enhanced'), true);

	parts.tabs.forEach((tab) => assert.strictEqual(tab.getAttribute('role'), 'tab'));
	parts.panels.forEach((panel) => assert.strictEqual(panel.getAttribute('role'), 'tabpanel'));

	assert.strictEqual(parts.panels[0].getAttribute('aria-labelledby'), 'ce-christmas-journey-tab-brasil');
	assert.strictEqual(parts.panels[1].getAttribute('aria-labelledby'), 'ce-christmas-journey-tab-irlanda');
});

test('exactly one sky is selected and visible after init', () => {
	const parts = scene();

	api.createJourney(parts.scope);

	assert.strictEqual(parts.tabs[0].getAttribute('aria-selected'), 'true');
	assert.strictEqual(parts.tabs[0].getAttribute('tabindex'), '0', 'the selected tab is in the tab order');
	assert.strictEqual(parts.tabs[1].getAttribute('aria-selected'), 'false');
	assert.strictEqual(parts.tabs[1].getAttribute('tabindex'), '-1', 'the other tab is reachable by arrow only');

	assert.strictEqual(parts.panels[0].hasAttribute('hidden'), false, 'the default sky is visible');
	assert.strictEqual(parts.panels[1].hasAttribute('hidden'), true, 'the other sky is hidden until chosen');
});

test('selecting the other sky moves the state, the visibility and the tab order', () => {
	const parts = scene();
	const journey = api.createJourney(parts.scope);

	journey.select('irlanda', false);

	assert.strictEqual(journey.getState(), 'irlanda');
	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'irlanda');
	assert.strictEqual(parts.tabs[1].getAttribute('aria-selected'), 'true');
	assert.strictEqual(parts.tabs[0].getAttribute('aria-selected'), 'false');
	assert.strictEqual(parts.tabs[0].getAttribute('tabindex'), '-1');
	assert.strictEqual(parts.tabs[1].getAttribute('tabindex'), '0');
	assert.strictEqual(parts.panels[1].hasAttribute('hidden'), false);
	assert.strictEqual(parts.panels[0].hasAttribute('hidden'), true);
});

test('an unknown sky never breaks the scene', () => {
	const parts = scene();
	const journey = api.createJourney(parts.scope);

	assert.strictEqual(journey.select('marte', false), 'brasil', 'the state is unchanged');
	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'brasil');
});

test('a click selects its sky', () => {
	const parts = scene();

	api.createJourney(parts.scope);
	parts.tabs[1].dispatch('click');

	assert.strictEqual(parts.tabs[1].getAttribute('aria-selected'), 'true');
	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'irlanda');
});

test('Arrow, Home and End move the selection and take the focus with them', () => {
	const parts = scene();

	api.createJourney(parts.scope);

	const forward = parts.tabs[0].dispatch('keydown', { key: 'ArrowRight' });

	assert.strictEqual(forward.defaultPrevented, true, 'the arrow key is consumed, so the page never scrolls');
	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'irlanda');
	assert.strictEqual(parts.tabs[1].focusCount, 1, 'the focus follows the selection');

	parts.tabs[1].dispatch('keydown', { key: 'ArrowRight' });

	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'brasil', 'ArrowRight wraps around');

	parts.tabs[0].dispatch('keydown', { key: 'ArrowLeft' });

	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'irlanda', 'ArrowLeft wraps backwards');

	parts.tabs[1].dispatch('keydown', { key: 'Home' });

	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'brasil', 'Home selects the first sky');

	parts.tabs[0].dispatch('keydown', { key: 'End' });

	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'irlanda', 'End selects the last sky');

	parts.tabs[1].dispatch('keydown', { key: 'ArrowUp' });

	assert.strictEqual(parts.scope.getAttribute('data-ce-journey-state'), 'brasil', 'ArrowUp behaves like ArrowLeft');

	const unrelated = parts.tabs[0].dispatch('keydown', { key: 'a' });

	assert.strictEqual(unrelated.defaultPrevented, false, 'typing does nothing and is never swallowed');
});

test('an incomplete scene fails closed and reveals nothing', () => {
	const no_tabs = scene();
	const no_tabs_scope = no_tabs.scope;

	no_tabs_scope.querySelectorAll = () => [];

	assert.strictEqual(api.createJourney(no_tabs_scope), null, 'a scene without tabs does not upgrade');
	assert.strictEqual(no_tabs.controls.hasAttribute('hidden'), true, 'the control stays hidden — no unusable widget');
	assert.strictEqual(no_tabs.tablist.getAttribute('role'), null, 'no role is invented');

	const mismatched = scene();

	mismatched.scope.querySelectorAll = (selector) =>
		'[data-ce-journey-tab]' === selector ? mismatched.tabs : [mismatched.panels[0]];

	assert.strictEqual(api.createJourney(mismatched.scope), null, 'a tab without its panel does not upgrade');
	assert.strictEqual(api.createJourney(null), null, 'no scope is a no-op');
});

test('the controller is not a scheduler: it creates no timer and no interval', () => {
	const realSetTimeout = global.setTimeout;
	const realSetInterval = global.setInterval;
	let scheduled = 0;

	global.setTimeout = () => {
		scheduled += 1;
		return 0;
	};
	global.setInterval = () => {
		scheduled += 1;
		return 0;
	};

	try {
		const parts = scene();
		const journey = api.createJourney(parts.scope);

		journey.select('irlanda', false);
		parts.tabs[0].dispatch('click');
		parts.tabs[0].dispatch('keydown', { key: 'End' });
		parts.tabs[1].dispatch('keydown', { key: 'ArrowLeft' });
	} finally {
		global.setTimeout = realSetTimeout;
		global.setInterval = realSetInterval;
	}

	assert.strictEqual(scheduled, 0, 'no timer and no interval is created at any point');
});

