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
 *   - it does nothing when the placement is absent, and the dialog keeps its
 *     server-rendered CLOSED state (never an inline card);
 *   - dismissal is capped to the tab session and survives a reload in that session,
 *     leaving NOTHING inline (no band, no open attribute, dismissed state);
 *   - a fresh session is eligible again;
 *   - a storage that throws never breaks the page and still caps this document;
 *   - the close button, Escape and a genuine backdrop click all dismiss;
 *   - no resize/orientation/focus change can reopen it;
 *   - the CTA keeps its normal navigation;
 *   - incomplete markup or a missing `showModal()` fails closed (dialog closed,
 *     fallback state, no dead close button);
 *   - a `showModal()` that throws leaves the same safe fallback, never a
 *     half-initialized dialog;
 *   - the signed private preview auto-opens the same modal (no click, no
 *     trigger), its close NEVER touches the dismissal key, and a reload in the
 *     same session shows the popup again;
 *   - the open card is pinned below the admin bar, the sticky header and the
 *     preview notice through one measured top offset that is cleared on close;
 *   - a repeated boot claims nothing twice (one controller, one showModal(),
 *     no duplicate listeners), and a late controller legitimately overrides the
 *     guard's timeout state instead of racing it;
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
	const styleProps = {};

	const node = {
		attributes: store,
		focused: 0,
		showModalCount: 0,
		closeCount: 0,
		style: {
			setProperty(name, value) {
				styleProps[name] = String(value);
			},
			removeProperty(name) {
				delete styleProps[name];
			},
			getPropertyValue(name) {
				return Object.prototype.hasOwnProperty.call(styleProps, name) ? styleProps[name] : '';
			}
		},
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
 * Build one complete welcome, exactly as the server renders it: a CLOSED dialog,
 * the compact fallback card and the hidden-until-wired preview trigger.
 *
 * @param {Object} options `{preview, dialog, close, showModal, showModalThrows}`.
 * @returns {Object} The parts plus a document/window pair.
 */
function scene(options) {
	const opts = Object.assign({ preview: false, dialog: true, close: true, showModal: true }, options || {});
	const html = element({ 'data-ce-welcome-state': 'pending' });
	const body = element({});

	body.style = { position: '', top: '', left: '', right: '', width: '', overflow: '' };

	// The server renders the dialog CLOSED: an `open` attribute here would be the
	// broken inline card the fix removed.
	const dialog = element({ 'data-ce-welcome-dialog': '', id: 'ce-christmas-welcome-dialog' });

	if (!opts.showModal) {
		delete dialog.showModal;
	}

	if (opts.showModalThrows) {
		dialog.showModal = function () {
			dialog.showModalCount += 1;
			throw new Error('simulated showModal() failure');
		};
	}

	const closeButton = element({ 'data-ce-welcome-close': '', hidden: 'hidden' });
	const previewTrigger = element({ 'data-ce-welcome-preview-open': '', hidden: 'hidden' });
	const fallback = element({ 'data-ce-welcome-fallback': '' });
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

		if ('[data-ce-welcome-preview-open]' === selector) {
			return previewTrigger;
		}

		if ('[data-ce-welcome-fallback]' === selector) {
			return fallback;
		}

		return null;
	};

	const scrolls = [];
	const viewListeners = {};
	const doc = {
		documentElement: html,
		body: body,
		activeElement: null,
		readyState: 'interactive',
		// Only the obstruction selectors ever answer: `opts.obstructions` maps a
		// selector to the bottom edge of that top-of-viewport element (0 = absent
		// or scrolled away). The focus landmark stays null like the real page's.
		querySelector: (selector) => {
			if (opts.obstructions && Object.prototype.hasOwnProperty.call(opts.obstructions, selector)) {
				const bottom = opts.obstructions[selector];
				const obstruction = element({});

				obstruction.getBoundingClientRect = () => ({
					top: 0,
					bottom: bottom,
					left: 0,
					right: 0,
					width: 0,
					height: bottom
				});

				return obstruction;
			}

			return null;
		},
		querySelectorAll: (selector) => ('.ce-xmas-welcome[data-ce-welcome]' === selector ? (opts.detached ? [] : [root]) : [])
	};

	const view = {
		document: doc,
		pageYOffset: 240,
		scrollTo: (x, y) => scrolls.push([x, y]),
		addEventListener(type, handler) {
			(viewListeners[type] = viewListeners[type] || []).push(handler);
		},
		removeEventListener(type, handler) {
			if (!viewListeners[type]) {
				return;
			}
			viewListeners[type] = viewListeners[type].filter((registered) => registered !== handler);
		},
		dispatch(type) {
			(viewListeners[type] || []).forEach((handler) => handler({ type: type }));
		},
		listenerCount(type) {
			return (viewListeners[type] || []).length;
		}
	};

	view.sessionStorage = opts.store || storage(false);

	return { root, dialog, closeButton, previewTrigger, fallback, cta, doc, view, html, body, scrolls };
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
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog keeps its server-rendered CLOSED state');
});

test('dismissal is remembered for the tab session and nothing stays inline', () => {
	const store = storage(false);
	const parts = scene({ store: store });
	const created = boot(parts)[0];

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(created.isOpen(), false, 'the dialog is no longer modal');
	assert.strictEqual(store.getItem(KEY), '1', 'the tab-session dismissal key is written');
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog stays closed — no inline band');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'dismissed', 'the dismissed state hides the fallback too');
	assert.strictEqual(parts.dialog.classList.contains('is-welcome-open'), false, 'the card state is gone');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'the close control is hidden again');
	assert.strictEqual(parts.body.style.position, '', 'the background scroll lock is released');
	assert.deepStrictEqual(parts.scrolls, [[0, 240]], 'the exact previous scroll position is restored');

	// The same session, a fresh controller (a reload): never reopened.
	const again = scene({ store: store });
	boot(again);

	assert.strictEqual(again.dialog.showModalCount, 0, 'a dismissal in this session suppresses the auto-open');
	assert.strictEqual(again.dialog.hasAttribute('open'), false, 'a reload renders nothing inline after the dismissal');
	assert.strictEqual(again.html.getAttribute('data-ce-welcome-state'), 'dismissed', 'the reload keeps the dismissed state');
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
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog stays closed after Escape');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'dismissed', 'the dismissed state is exposed');
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

test('an incomplete welcome fails closed and never becomes an inline card', () => {
	const parts = scene({ close: false });

	assert.strictEqual(controller(parts), null, 'no controller without a close control');
	assert.strictEqual(parts.dialog.showModalCount, 0, 'nothing opens');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'fallback', 'the fallback state is exposed');
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog never opens as an inline card');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'no dead close button is offered');
});

test('an engine without showModal() fails closed instead of faking a dialog', () => {
	const parts = scene({ showModal: false });

	assert.strictEqual(controller(parts), null, 'no controller without a modal dialog');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'fallback', 'the fallback state is exposed');
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog stays closed');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'no dead close button is offered');
});

test('a showModal() that throws leaves the safe fallback, never a half-open dialog', () => {
	const parts = scene({ showModalThrows: true });
	const created = boot(parts);

	assert.strictEqual(created.length, 1, 'the controller exists (it may retry explicitly)');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'showModal() was attempted exactly once');
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'the dialog stays CLOSED, never an inline card');
	assert.strictEqual(parts.dialog.classList.contains('is-welcome-open'), false, 'no card state is left behind');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), true, 'no dead close control is revealed');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'fallback', 'the fallback state is exposed');
	assert.strictEqual(parts.body.style.position, '', 'the background was never locked');
});

test('a repeated boot claims nothing twice: one controller, one showModal(), no duplicate listeners', () => {
	const parts = scene();

	assert.strictEqual(boot(parts).length, 1, 'the first boot claims the root');
	assert.strictEqual(boot(parts).length, 0, 'a second boot finds the root already claimed');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'still exactly one showModal()');
	assert.deepStrictEqual(parts.dialog.listenerTypes(), ['click', 'close'], 'no duplicate dialog listeners');
	assert.deepStrictEqual(parts.closeButton.listenerTypes(), ['click'], 'no duplicate close listeners');
	assert.strictEqual(parts.previewTrigger.listenerTypes().length, 1, 'the preview trigger listener is registered once');
});

test('a late controller legitimately overrides the guard timeout instead of racing it', () => {
	const parts = scene();

	// The guard's DOMContentLoaded fallback already ran (controller never arrived
	// in time): the page shows the fallback card...
	parts.html.setAttribute('data-ce-welcome-state', 'fallback');

	// ...and then the deferred controller finally claims the root.
	const created = boot(parts)[0];

	assert.strictEqual(created.isOpen(), true, 'the late controller still opens the modal');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'open', 'the state transitions deterministically to open');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'exactly one showModal()');
});

test('the signed preview auto-opens the real modal, never reveals the trigger, and never touches dismissal state', () => {
	const store = storage(false);
	const parts = scene({ preview: true, store: store });
	const created = boot(parts)[0];

	assert.strictEqual(created.isPreview(), true, 'the controller knows it is a preview');
	assert.strictEqual(parts.dialog.showModalCount, 1, 'a preview auto-opens the same modal — no gesture, no trigger click');
	assert.strictEqual(parts.dialog.hasAttribute('open'), true, 'the preview dialog is open');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'open', 'the preview is in the open state like the public view');
	assert.strictEqual(parts.closeButton.hasAttribute('hidden'), false, 'the close control is revealed with the modal');
	assert.strictEqual(parts.previewTrigger.hasAttribute('hidden'), true, 'the preview trigger stays hidden — no click is ever required');
	assert.strictEqual(created.isDismissed(), false, 'merely viewing the preview records nothing');

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(created.isOpen(), false, 'the close works');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'preview', 'closing returns to the closed-preview state');
	assert.strictEqual(store.getItem(KEY), null, 'a preview close NEVER writes the visitor dismissal key');
	assert.strictEqual(parts.dialog.hasAttribute('open'), false, 'no inline dialog after the preview close');
	assert.strictEqual(parts.previewTrigger.hasAttribute('hidden'), true, 'the trigger stays hidden after the close too');

	// The key was never written, so a reload of the preview shows the popup
	// again — and a preview opened AFTER a public dismissal in the same
	// session still shows it, because an editor must always see the real
	// experience (while a public reload would stay closed).
	const reloaded = scene({ preview: true, store: store });
	boot(reloaded);
	assert.strictEqual(reloaded.dialog.showModalCount, 1, 'a preview reload in the same session shows the popup again');

	store.setItem(KEY, '1');
	const afterPublicDismissal = scene({ preview: true, store: store });
	boot(afterPublicDismissal);
	assert.strictEqual(
		afterPublicDismissal.dialog.showModalCount,
		1,
		'the preview ignores a recorded public dismissal — the editor always sees the popup'
	);

	const publicScene = scene({ store: store });
	boot(publicScene);
	assert.strictEqual(publicScene.dialog.showModalCount, 0, 'the public view still honours the same recorded dismissal');
	assert.strictEqual(publicScene.html.getAttribute('data-ce-welcome-state'), 'dismissed', 'the public view settles dismissed with nothing inline');
});

test('the open card is pinned below the admin bar, the sticky header and the preview notice', () => {
	const parts = scene({
		obstructions: {
			'#wpadminbar': 32,
			'.site-header': 116,
			'.conexao-preview-banner': 160
		}
	});
	const created = boot(parts)[0];

	assert.strictEqual(created.isOpen(), true, 'the modal is open');
	assert.strictEqual(
		parts.dialog.style.getPropertyValue('--ce-xmas-welcome-obstruction-top'),
		'172px',
		'the lowest bottom edge (160) plus the 12 px gap pins the card below every obstruction'
	);
	assert.strictEqual(parts.view.listenerCount('resize'), 1, 'a resize re-measures while the card is open');

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(
		parts.dialog.style.getPropertyValue('--ce-xmas-welcome-obstruction-top'),
		'',
		'the offset is cleared when the modal settles — nothing is left behind'
	);
	assert.strictEqual(parts.view.listenerCount('resize'), 0, 'the resize listener is removed on close');
});

test('focus enters the dialog on open and returns to the previously focused element on close', () => {
	const parts = scene();
	const previous = element({ id: 'somewhere-else' });

	// What the visitor was focused on before the auto-open.
	parts.doc.activeElement = previous;

	const created = boot(parts)[0];

	assert.strictEqual(parts.dialog.focused, 1, 'focus moves into the dialog when it opens');
	assert.strictEqual(created.isOpen(), true, 'the modal is open');

	parts.closeButton.dispatch('click', { detail: 1 });

	assert.strictEqual(previous.focused, 1, 'focus returns to the previously focused element');
	assert.strictEqual(parts.html.getAttribute('data-ce-welcome-state'), 'dismissed', 'the welcome is dismissed');
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
	assert.strictEqual(
		source.includes("setAttribute('open'"),
		false,
		'the controller never re-opens the dialog as a non-modal inline card'
	);
	assert.strictEqual(/localStorage\s*[.(]/.test(source), false, 'no cross-session storage is used');
	assert.strictEqual(source.includes('document.cookie'), false, 'no cookie is used');
	assert.strictEqual(
		/setInterval\s*\(|setTimeout\s*\(|requestAnimationFrame\s*\(/.test(source),
		false,
		'no scheduler call is introduced'
	);
});
