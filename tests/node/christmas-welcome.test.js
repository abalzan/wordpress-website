/**
 * Runtime test — the Christmas homepage welcome dialog.
 *
 * Executed with `node --test` (see tests/scripts/verify-commemorative-runtime.py).
 * It loads the REAL `assets/js/presentations/christmas/christmas.js` and drives
 * `createWelcome()` / `bootWelcome()` with a fake DOM, a fake window and fake
 * session storage, so the behaviour is PROVEN BY RUNNING IT — not by searching its
 * source text.
 *
 * Covered:
 *   - it opens immediately on the first eligible view, with the close control
 *     revealed, the background locked and the dialog focused;
 *   - it does nothing when the placement is absent;
 *   - dismissal is capped to the tab session and survives a reload in that session;
 *   - a fresh session is eligible again;
 *   - a storage that throws never breaks the page and still caps this document;
 *   - the close button, Escape and a genuine backdrop click all dismiss;
 *   - no resize/orientation/focus change can reopen it;
 *   - the CTA keeps its normal navigation;
 *   - incomplete markup or a missing `showModal()` fails closed (band inline, no
 *     dead close button);
 *   - the signed private preview is never auto-opened but stays driveable;
 *   - IT IS NOT A SCHEDULER: no timer, interval or requestAnimationFrame.
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

const KEY = 'ce_christmas_welcome_dismissed_v1';

/* ------------------------------------------------------------------------- */
/* Test doubles                                                               */
/* ------------------------------------------------------------------------- */

/**
 * A minimal element: attributes, classList, listeners, focus and dialog methods.
 *
 * @param {Object} attributes Initial attributes.
 * @param {Object} behaviour  Optional `{openWidth, openHeight, onShow}`.
 * @returns {Object} Fake element.
 */
function element(attributes, behaviour) {
	const store = Object.assign({}, attributes || {});
	const listeners = {};
	const options = behaviour || {};

	const node = {
		attributes: store,
		focused: 0,
		showModalCount: 0,
		closeCount: 0,
		classList: {
			names: new Set(),
			add(name) {
				node.classList.names.add(name);
			},
			remove(name) {
				node.classList.names.delete(name);
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
		listenerCount() {
			return Object.keys(listeners).length;
		},
		listenerTypes() {
			return Object.keys(listeners).sort();
		},
		dispatch(type, event) {
			const payload = Object.assign({ currentTarget: node, target: node, defaultPrevented: false }, event || {});

			payload.preventDefault = () => {
				payload.defaultPrevented = true;
			};

			(listeners[type] || []).forEach((handler) => handler(payload));

			return payload;
		},
		focus() {
			node.focused += 1;
		},
		showModal() {
			node.showModalCount += 1;

			if (node.hasAttribute('open')) {
				// What Chromium really does with a non-modal dialog.
				throw new Error('The dialog is already open as a non-modal dialog');
			}

			node.setAttribute('open', '');
		},
		close() {
			node.closeCount += 1;
			node.removeAttribute('open');
			node.dispatch('close', {});
		},
		getBoundingClientRect() {
			return {
				left: 10,
				top: 10,
				right: 10 + (options.openWidth || 300),
				bottom: 10 + (options.openHeight || 300)
			};
		}
	};

	return node;
}

/**
 * A fake sessionStorage.
 *
 * @param {boolean} throws Make every access throw, like a denied private mode.
 * @returns {Object} Storage.
 */
function storage(throws) {
	const map = new Map();

	return {
		map: map,
		getItem(key) {
			if (throws) {
				throw new Error('SecurityError');
			}
			return map.has(key) ? map.get(key) : null;
		},
		setItem(key, value) {
			if (throws) {
				throw new Error('SecurityError');
			}
			map.set(key, String(value));
		}
	};
}

/**
 * Build one complete welcome, exactly as the server renders it.
 *
 * @param {Object} options `{preview, dialog, close, showModal}`.
 * @returns {Object} The parts plus a document/window pair.
 */
function scene(options) {
	const opts = Object.assign({ preview: false, dialog: true, close: true, showModal: true }, options || {});
	const html = element({ 'data-ce-welcome-state': 'pending' });
	const body = element({});

	body.style = { position: '', top: '', left: '', right: '', width: '', overflow: '' };

	const dialog = element({ 'data-ce-welcome-dialog': '', open: '', id: 'ce-christmas-welcome-dialog' });

	if (!opts.showModal) {
		delete dialog.showModal;
	}

	const closeButton = element({ 'data-ce-welcome-close': '', hidden: 'hidden' });
	const cta = element({});
	const rootAttributes = { 'data-ce-welcome': '' };

	if (opts.preview) {
		rootAttributes['data-ce-welcome-preview'] = '1';
	}

	const root = element(rootAttributes);

	root.querySelector = (selector) => {
		if ('[data-ce-welcome-dialog]' === selector) {
			return opts.dialog ? dialog : null;
		}

		if ('[data-ce-welcome-close]' === selector) {
			return opts.close ? closeButton : null;
		}

		return null;
	};

	const scrolls = [];
	const doc = {
		documentElement: html,
		body: body,
		activeElement: null,
		readyState: 'interactive',
		querySelector: (selector) => ('main, [role="main"], #primary' === selector ? null : null),
		querySelectorAll: (selector) => ('.ce-xmas-welcome[data-ce-welcome]' === selector ? (opts.detached ? [] : [root]) : [])
	};

	const view = {
		document: doc,
		pageYOffset: 240,
		scrollTo: (x, y) => scrolls.push([x, y])
	};

	view.sessionStorage = opts.store || storage(false);

	return { root, dialog, closeButton, cta, doc, view, html, body, scrolls };
}

/**
 * Build the controller for one scene.
 *
 * @param {Object} parts  Scene parts.
 * @param {Object} opts   `{store}`.
 * @returns {Object|null} Controller.
 */
function controller(parts, opts) {
	const options = opts || {};

	return api.createWelcome(parts.root, {
		document: parts.doc,
		window: parts.view,
		storage: Object.prototype.hasOwnProperty.call(options, 'store') ? options.store : storage(false)
	});
}

/**
 * Run the real boot over a scene (the same path the browser takes).
 *
 * @param {Object} parts Scene parts.
 * @returns {Array} Controllers.
 */
function boot(parts) {
	return api.bootWelcome(parts.view);
}

/* ------------------------------------------------------------------------- */
/* Tests                                                                      */
/* ------------------------------------------------------------------------- */

test('the welcome opens immediately on the first eligible view', () => {
	const parts = scene();
	const created = boot(parts);

	assert.strictEqual(created.length, 1, 'one controller is created');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'showModal() is called without a user gesture');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the dialog is open');
	assert.strictEqual(parts.dialog.classList.contains('is-welcome-open'), true, 'the card state class is set');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'open', 'the page state is open');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), false, 'the close control is revealed');
	assert.strictEqual(parts.dialog.focused, 1, 'the dialog itself takes the focus');
	assert.strictEqual(parts.body.style.position, 'fixed', 'the background is locked');
	assert.strictEqual(parts.body.style.top, '-240px', 'the scroll offset is preserved in the lock');
	assert.strictEqual(parts.html.classList.contains('ce-xmas-welcome-locked'), true, 'the lock class is on <html>');
});

test('no placement on the page means no controller and no error', () => {
	const parts = scene({ detached: true });

	assert.deepStrictEqual(boot(parts), [], 'nothing is enhanced');
	assert.strictEqual(parts.dialog.showModalCount, 0, 'nothing opens');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the band keeps its server-rendered state');
});

test('dismissal is remembered for the tab session and the band returns', () => {
	const store = storage(false);
	const parts = scene({ store: store });
	const created = boot(parts)[0];

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(created.isOpen(), false, 'the dialog is no longer modal');
	assert.strictEqual(store.getItem(KEY), '1', 'the tab-session dismissal key is written');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the node returns to the inline band');
	assert.strictEqual(parts.dialog.classList.contains('is-welcome-open'), false, 'the card state is gone');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'the close control is hidden again');
	assert.strictEqual(parts.body.style.position, '', 'the background scroll lock is released');
	assert.deepStrictEqual(parts.scrolls, [[0, 240]], 'the exact previous scroll position is restored');

	// The same session, a fresh controller (a reload): never reopened.
	const again = scene({ store: store });
	boot(again);

	assert.strictEqual(again.dialog.showModalCount, 0, 'a dismissal in this session suppresses the auto-open');
	assert.strictEqual(again.dialog.hasAttribute('open'), true, 'the band is still inline and readable');
});

test('a fresh session is eligible again', () => {
	const parts = scene({ store: storage(false) });
	boot(parts);

	assert.strictEqual(parts.dialog.showModalCount, 1, 'an empty session storage opens the welcome');
});

test('storage that throws never breaks the page and still caps this document', () => {
	const store = storage(true);
	const parts = scene({ store: store });
	const created = boot(parts)[0];

	assert.strictEqual(parts.dialog.showModalCount, 1, 'the welcome still opens');
	assert.strictEqual(created.isDismissed(), false, 'nothing is recorded before the dismissal');

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(created.isOpen(), false, 'the close still works');
	assert.strictEqual(created.isDismissed(), true, 'the in-memory flag caps this document');
	assert.strictEqual(parts.body.style.position, '', 'the page is fully restored');
});

test('Escape (the native close event) dismisses and is remembered', () => {
	const store = storage(false);
	const parts = scene({ store: store });
	const created = boot(parts)[0];

	// What the engine does on Escape: close() removes `open` and fires `close`.
	parts.dialog.removeAttribute('open');
	parts.dialog.dispatch('close', {});

	assert.strictEqual(created.isOpen(), false, 'the dialog is dismissed');
	assert.strictEqual(store.getItem(KEY), '1', 'the dismissal is recorded');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the band is restored inline');
});

test('a genuine backdrop click dismisses, a click inside the card does not', () => {
	const parts = scene();
	const created = boot(parts)[0];

	// Inside the dialog box (10..310): the card's own padding.
	parts.dialog.dispatch('click', { detail: 1, target: parts.dialog, clientX: 40, clientY: 40 });
	assert.strictEqual(created.isOpen(), true, 'a click inside the card never closes it');

	// Outside the dialog box: the backdrop.
	parts.dialog.dispatch('click', { detail: 1, target: parts.dialog, clientX: 900, clientY: 900 });
	assert.strictEqual(created.isOpen(), false, 'a click on the backdrop dismisses');

	// A keyboard-generated click (detail === 0) is never a backdrop tap.
	const second = scene();
	const other = boot(second)[0];

	second.dialog.dispatch('click', { detail: 0, target: second.dialog, clientX: 900, clientY: 900 });
	assert.strictEqual(other.isOpen(), true, 'a keyboard click on the dialog never dismisses');
});

test('nothing reopens the welcome on resize, orientation or focus changes', () => {
	const parts = scene();
	const created = boot(parts)[0];

	parts.closeButton.dispatch('click', { detail: 1 });

	const dialogsBefore = parts.dialog.showModalCount;

	parts.doc.dispatch = undefined;
	parts.dialog.dispatch('resize', {});
	parts.dialog.dispatch('orientationchange', {});
	parts.dialog.dispatch('focus', {});
	parts.dialog.dispatch('visibilitychange', {});

	assert.strictEqual(created.isOpen(), false, 'the dialog stays dismissed');
	assert.strictEqual(parts.dialog.showModalCount, dialogsBefore, 'no further showModal() was attempted');
	assert.deepStrictEqual(
		parts.dialog.listenerTypes(),
		['click', 'close'],
		'only the dismissal listeners are registered on the dialog'
	);
	assert.deepStrictEqual(
		parts.closeButton.listenerTypes(),
		['click'],
		'only the close listener is registered on the control'
	);
});

test('the CTA keeps its normal navigation', () => {
	const parts = scene();
	const created = boot(parts)[0];

	// A click on the link bubbles to the dialog with the link as its target.
	const event = parts.dialog.dispatch('click', {
		detail: 1,
		target: parts.cta,
		clientX: 60,
		clientY: 60
	});

	assert.strictEqual(event.defaultPrevented, false, 'the controller never prevents the CTA navigation');
	assert.strictEqual(created.isOpen(), true, 'and it never closes the dialog instead');
});

test('an incomplete welcome fails closed and leaves the band inline', () => {
	const parts = scene({ close: false });

	assert.strictEqual(controller(parts), null, 'no controller without a close control');
	assert.strictEqual(parts.dialog.showModalCount, 0, 'nothing opens');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'inline', 'the band is shown inline');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the hero stays readable');
});

test('an engine without showModal() fails closed instead of faking a dialog', () => {
	const parts = scene({ showModal: false });

	assert.strictEqual(controller(parts), null, 'no controller without a modal dialog');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'inline', 'the band is shown inline');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'no dead close button is offered');
});

test('the signed preview is never auto-opened, but stays driveable', () => {
	const parts = scene({ preview: true });
	const created = boot(parts)[0];

	assert.strictEqual(parts.dialog.showModalCount, 0, 'a preview keeps the band inline (banner visible)');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'inline', 'the band is inline');
	assert.strictEqual(created.isPreview(), true, 'the controller knows it is a preview');

	assert.strictEqual(created.open(), true, 'a maintainer can still drive it explicitly');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'and only then does the modal dialog appear');
});

test('it is not a scheduler: no timer, interval or requestAnimationFrame', () => {
	const original = {
		setTimeout: global.setTimeout,
		setInterval: global.setInterval,
		requestAnimationFrame: global.requestAnimationFrame
	};
	const calls = { timeout: 0, interval: 0, frame: 0 };

	global.setTimeout = () => {
		calls.timeout += 1;
		return 0;
	};
	global.setInterval = () => {
		calls.interval += 1;
		return 0;
	};
	global.requestAnimationFrame = () => {
		calls.frame += 1;
		return 0;
	};

	try {
		const parts = scene();
		const created = boot(parts)[0];

		parts.closeButton.dispatch('click', { detail: 1 });
		created.open();
	} finally {
		global.setTimeout = original.setTimeout;
		global.setInterval = original.setInterval;
		global.requestAnimationFrame = original.requestAnimationFrame;
	}

	assert.deepStrictEqual(calls, { timeout: 0, interval: 0, frame: 0 }, 'the welcome schedules nothing');
});

test('the dismissal key is session-scoped and never persistent', () => {
	const source = require('node:fs').readFileSync(
		path.join(
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
		),
		'utf8'
	);

	assert.ok(source.includes(KEY), 'the namespaced session key is the documented one');
	assert.strictEqual(/localStorage\s*[.(]/.test(source), false, 'no cross-session storage is used');
	assert.strictEqual(source.includes('document.cookie'), false, 'no cookie is used');
	assert.strictEqual(
		/setInterval\s*\(|setTimeout\s*\(|requestAnimationFrame\s*\(/.test(source),
		false,
		'no scheduler call is introduced'
	);
});
