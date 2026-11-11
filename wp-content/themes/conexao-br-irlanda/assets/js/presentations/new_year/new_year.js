/**
 * New Year experience package — package behaviour.
 *
 * Two responsibilities, both small and both local to this package:
 *
 *   1. `createWelcome()` — the homepage welcome dialog. The server renders the
 *      whole hero ONCE inside a CLOSED `<dialog>` plus a compact fallback card;
 *      this controller calls `showModal()` on the first eligible homepage view of
 *      a tab session, locks the background scroll, restores focus on close and
 *      records the TAB-SESSION dismissal (never from a signed preview). It
 *      creates no timer, no interval, no `requestAnimationFrame` loop and no
 *      network request, reads no clock and never decides whether the event is
 *      active (the server does that). The card opens at the TOP of the screen,
 *      pinned below whatever occupies the top of the viewport (WordPress admin
 *      bar, signed-preview notice, sticky header), and the signed private preview
 *      auto-opens the SAME modal — an editor sees the exact visitor experience,
 *      while the preview close never writes the dismissal key, so a reload shows
 *      the popup again. Nothing is ever rendered inline: no band, no reserved
 *      hero height.
 *   2. `createAmbientDecoration()` — the ambient decoration factory. The timing,
 *      the per-session cap, the visibility pause and the node cleanup all belong
 *      to the shared framework (`assets/js/commemorative.js`); this file only
 *      supplies the marker lookup, so a second ambient scheduler can never
 *      appear.
 *   3. `boot()` — wires both, in the browser only, welcome first.
 *
 * The controllers take their scope element (and, for the tests, their document,
 * window and storage) as arguments.
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
		root.ConexaoNewYear = api;
		api.boot();
	}
})(typeof window !== 'undefined' ? window : null, function (win) {
	'use strict';

	var WELCOME_SELECTOR = '.ce-new-year-welcome[data-ce-welcome]';
	var WELCOME_DIALOG_SELECTOR = '[data-ce-welcome-dialog]';
	var WELCOME_CLOSE_SELECTOR = '[data-ce-welcome-close]';
	var WELCOME_PREVIEW_OPEN_SELECTOR = '[data-ce-welcome-preview-open]';
	var WELCOME_STATE = 'data-ce-welcome-state';
	var WELCOME_PREVIEW = 'data-ce-welcome-preview';
	var WELCOME_CLAIMED = 'data-ce-welcome-claimed';
	var WELCOME_OPEN_CLASS = 'is-welcome-open';
	var WELCOME_LOCK_CLASS = 'ce-new-year-welcome-locked';
	var WELCOME_STORAGE_KEY = 'ce_new_year_welcome_dismissed_v1';

	var AMBIENT_MARKER_SELECTOR = '.ce-new-year-ambient-marker';

	/**
	 * The tab-scoped store, or null when the browser denies it.
	 *
	 * Merely reading `window.sessionStorage` throws in some private modes, so even
	 * the property access is guarded.
	 *
	 * @param {Object} view The window-like object.
	 * @returns {Object|null} A Storage, or null.
	 */
	function welcomeStorage(view) {
		if (!view) {
			return null;
		}

		try {
			return view.sessionStorage || null;
		} catch (error) {
			return null;
		}
	}

	/**
	 * A per-tab-session flag with an in-memory fallback.
	 *
	 * The welcome is shown once per BROWSER TAB SESSION. `sessionStorage` is
	 * cleared when the session ends, so a genuinely new session is eligible again
	 * — this is the deliberate compromise between "see it immediately" and "never
	 * be nagged". Nothing leaves the tab: no cookie, no `localStorage`, no server
	 * round trip.
	 *
	 * @param {Object|null} storage A Storage, or null.
	 * @param {string}      key     The namespaced key.
	 * @returns {Object} `{key, read, write}`.
	 */
	function createSessionFlag(storage, key) {
		var memory = false;

		function read() {
			if (!storage) {
				return memory;
			}

			try {
				return '1' === storage.getItem(key);
			} catch (error) {
				return memory;
			}
		}

		function write() {
			memory = true;

			if (!storage) {
				return;
			}

			try {
				storage.setItem(key, '1');
			} catch (error) {
				// Storage denied or full: the in-memory flag still caps this document.
			}
		}

		return { key: key, read: read, write: write };
	}

	function attr(element, name) {
		return element && typeof element.getAttribute === 'function' ? element.getAttribute(name) : null;
	}

	/**
	 * Build the homepage welcome controller for one `[data-ce-welcome]` root.
	 *
	 * The server renders the WHOLE hero once inside a CLOSED `<dialog>`, plus a
	 * compact fallback card. This controller owns every page state:
	 *
	 *   - `open()` → `showModal()`: the browser's own top layer makes the page
	 *     behind inert and keeps sequential focus inside the dialog, so there is
	 *     no hand-written focus trap to get wrong;
	 *   - the background scroll position is captured and restored exactly (iOS
	 *     included, via `position: fixed` + a negative `top`);
	 *   - `close()` records the TAB-SESSION dismissal (never in a signed preview)
	 *     and leaves NOTHING inline: the dialog stays closed and the fallback
	 *     stays hidden, so the hero keeps its exact original geometry — no band,
	 *     no reserved height, no residue;
	 *   - everything fails closed: no dialog, no close control or no
	 *     `showModal()` keeps the dialog closed, hides the close button and
	 *     leaves the compact fallback card as the usable state;
	 *   - the signed preview auto-opens exactly like the public view — same
	 *     modal, same top layer — but closing it NEVER touches the visitor
	 *     dismissal key, so reloading the preview shows the popup again;
	 *   - claiming is idempotent: a root claimed once yields no second
	 *     controller, no second set of listeners and never a second
	 *     `showModal()`.
	 *
	 * It is NOT a scheduler: no timer, no interval, no `requestAnimationFrame`,
	 * no network, no analytics. Eligibility is decided by the server (the
	 * placement is only rendered when the dispatcher selects the package) —
	 * never by a date computation in here.
	 *
	 * @param {Element} root The `[data-ce-welcome]` element.
	 * @param {Object}  deps {document, window, storage, key} — injected for the runtime tests.
	 * @returns {Object|null} The controller, or null when it must not exist.
	 */
	function createWelcome(root, deps) {
		var options = deps || {};
		var doc = options.document || (win && win.document) || null;
		var view = options.window || win || null;

		if (!root || typeof root.querySelector !== 'function') {
			return null;
		}

		// Idempotence: a repeated boot on an already-claimed root adds nothing.
		if (typeof root.getAttribute === 'function' && '1' === root.getAttribute(WELCOME_CLAIMED)) {
			return null;
		}

		var dialog = root.querySelector(WELCOME_DIALOG_SELECTOR);
		var closeButton = root.querySelector(WELCOME_CLOSE_SELECTOR);

		function setState(state) {
			if (doc && doc.documentElement && typeof doc.documentElement.setAttribute === 'function') {
				doc.documentElement.setAttribute(WELCOME_STATE, state);
			}
		}

		function hideClose() {
			if (closeButton && typeof closeButton.setAttribute === 'function') {
				closeButton.setAttribute('hidden', 'hidden');
			}
		}

		function isPreview() {
			return '1' === attr(root, WELCOME_PREVIEW);
		}

		function onPreviewTriggerClick(event) {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}

			open();
		}

		// Fail closed: incomplete markup, or an engine without a modal dialog,
		// keeps the dialog CLOSED (never an inline card) and leaves the compact
		// fallback card as the usable state — with no control that cannot work.
		if (
			!dialog ||
			!closeButton ||
			typeof dialog.showModal !== 'function' ||
			typeof dialog.close !== 'function'
		) {
			hideClose();
			setState('fallback');
			return null;
		}

		var flag = createSessionFlag(options.storage || null, options.key || WELCOME_STORAGE_KEY);
		var modal = false;
		var previousFocus = null;
		var scroll = null;

		function focusQuietly(target) {
			if (!target || typeof target.focus !== 'function') {
				return false;
			}

			try {
				target.focus({ preventScroll: true });
				return true;
			} catch (error) {
				try {
					target.focus();
					return true;
				} catch (nested) {
					return false;
				}
			}
		}

		function lockScroll() {
			if (!doc || !doc.body) {
				return;
			}

			var style = doc.body.style || {};
			var offset = view && typeof view.pageYOffset === 'number'
				? view.pageYOffset
				: (doc.documentElement && doc.documentElement.scrollTop) || 0;

			scroll = {
				offset: offset,
				position: style.position,
				top: style.top,
				left: style.left,
				right: style.right,
				width: style.width,
				overflow: style.overflow
			};

			style.position = 'fixed';
			style.top = '-' + offset + 'px';
			style.left = '0';
			style.right = '0';
			style.width = '100%';
			style.overflow = 'hidden';

			if (doc.documentElement && doc.documentElement.classList && doc.documentElement.classList.add) {
				doc.documentElement.classList.add(WELCOME_LOCK_CLASS);
			}
		}

		function unlockScroll() {
			if (!scroll) {
				return;
			}

			var style = doc && doc.body ? doc.body.style || {} : {};
			var offset = scroll.offset;

			style.position = scroll.position || '';
			style.top = scroll.top || '';
			style.left = scroll.left || '';
			style.right = scroll.right || '';
			style.width = scroll.width || '';
			style.overflow = scroll.overflow || '';

			if (doc && doc.documentElement && doc.documentElement.classList && doc.documentElement.classList.remove) {
				doc.documentElement.classList.remove(WELCOME_LOCK_CLASS);
			}

			scroll = null;

			if (view && typeof view.scrollTo === 'function') {
				view.scrollTo(0, offset);
			}
		}

		function restoreFocus() {
			var target = previousFocus;

			previousFocus = null;

			if (
				target &&
				target !== (doc && doc.body) &&
				target !== (doc && doc.documentElement) &&
				target.isConnected !== false &&
				focusQuietly(target)
			) {
				return;
			}

			if (!doc || typeof doc.querySelector !== 'function') {
				return;
			}

			var landmark = doc.querySelector('main, [role="main"], #primary');

			if (landmark) {
				focusQuietly(landmark);
			}
		}

		function settle() {
			if (dialog.classList && typeof dialog.classList.remove === 'function') {
				dialog.classList.remove(WELCOME_OPEN_CLASS);
			}

			hideClose();

			if (typeof dialog.removeAttribute === 'function') {
				dialog.removeAttribute('open');
			}

			setState(isPreview() ? 'preview' : 'dismissed');
			unlockScroll();
			restoreFocus();
		}

		function open() {
			if (modal) {
				return;
			}

			if (flag && flag.read()) {
				setState('dismissed');
				return;
			}

			previousFocus = doc && typeof doc.activeElement !== 'undefined' ? doc.activeElement : null;

			lockScroll();

			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			}

			if (dialog.classList && typeof dialog.classList.add === 'function') {
				dialog.classList.add(WELCOME_OPEN_CLASS);
			}

			if (typeof dialog.setAttribute === 'function') {
				dialog.setAttribute('open', 'open');
			}

			modal = true;

			setState(isPreview() ? 'preview' : 'open');

			if (closeButton && typeof closeButton.focus === 'function') {
				closeButton.focus({ preventScroll: true });
			}
		}

		function close() {
			if (!modal) {
				return;
			}

			if (!isPreview() && flag && typeof flag.write === 'function') {
				flag.write();
			}

			if (typeof dialog.close === 'function') {
				dialog.close();
			}

			settle();
			modal = false;
		}

		if (closeButton && typeof closeButton.addEventListener === 'function') {
			closeButton.addEventListener('click', function (event) {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault();
				}

				close();
			});
		}

		if (typeof dialog.addEventListener === 'function') {
			dialog.addEventListener('click', function (event) {
				if (event && event.target === dialog) {
					close();
				}
			});

			dialog.addEventListener('cancel', function (event) {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault();
				}

				close();
			});
		}

		// Auto-open on the first eligible homepage view of a tab session.
		if (!isPreview() && !flag.read()) {
			if (doc && doc.readyState === 'loading') {
				doc.addEventListener('DOMContentLoaded', function () {
					open();
				});
			} else {
				open();
			}
		}

		if (isPreview()) {
			open();
		}

		if (typeof root.setAttribute === 'function') {
			root.setAttribute(WELCOME_CLAIMED, '1');
		}

		return {
			open: open,
			close: close,
			isOpen: function () {
				return modal;
			}
		};
	}

	/**
	 * The ambient decoration: a small decorative marker node for the shared
	 * scheduler.
	 *
	 * @returns {Element|null} A node for the shared scheduler, or null without a marker.
	 */
	function createAmbientDecoration() {
		if (!win || !win.document) {
			return null;
		}

		var marker = win.document.querySelector(AMBIENT_MARKER_SELECTOR);

		if (!marker) {
			return null;
		}

		var node = win.document.createElement('span');

		node.className = 'ce-ambient-decoration';
		node.setAttribute('aria-hidden', 'true');

		// Bounded, non-obstructive placement: near an edge, never centred, never
		// over the header or a fixed control (r10 §7.7).
		var edge = Math.random() < 0.5 ? 'left' : 'right';
		var top = 14 + Math.random() * 56;

		node.style[edge] = (2 + Math.random() * 6).toFixed(2) + '%';
		node.style.top = top.toFixed(2) + '%';
		node.style.width = (44 + Math.random() * 26).toFixed(0) + 'px';

		return node;
	}

	/**
	 * Boot the package: welcome first, then ambient.
	 *
	 * @returns {void}
	 */
	function boot() {
		if (!win || !win.document) {
			return;
		}

		var root = win.document.querySelector(WELCOME_SELECTOR);

		if (root) {
			createWelcome(root);
		}
	}

	return {
		createWelcome: createWelcome,
		createAmbientDecoration: createAmbientDecoration,
		boot: boot
	};
});
