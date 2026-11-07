/**
 * Christmas experience package — package behaviour.
 *
 * Four responsibilities, all small and all local to this package:
 *
 *   1. `createWelcome()` — the homepage welcome dialog. The server renders the whole
 *      hero ONCE inside a CLOSED `<dialog>` plus a compact fallback card; this
 *      controller calls `showModal()` on the first eligible homepage view of a tab
 *      session, locks the background scroll, restores focus on close and records the
 *      TAB-SESSION dismissal (never from a signed preview). It creates no timer, no
 *      interval, no `requestAnimationFrame` loop and no network request, reads no
 *      clock and never decides whether the event is active (the server does that).
 *      The card opens at the TOP of the screen, pinned below whatever occupies the
 *      top of the viewport (WordPress admin bar, signed-preview notice, sticky
 *      header), and the signed private preview auto-opens the SAME modal — an
 *      editor sees the exact visitor experience, while the preview close never
 *      writes the dismissal key, so a reload shows the popup again. Nothing is
 *      ever rendered inline: no band, no reserved hero height.
 *   2. `createJourney()` — upgrades the server-rendered "two skies" pair into an
 *      accessible WAI-ARIA tabs widget (roving tabindex, Arrow/Home/End, one visible
 *      panel, the shared star travelling once towards the chosen sky). It is NOT a
 *      scheduler: no timers, no intervals, no storage, no network, no analytics. The
 *      state lives in the DOM for the life of the page and is gone on reload.
 *   3. `createStarDecoration()` — the ambient decoration factory. The timing, the
 *      per-session cap, the visibility pause and the node cleanup all belong to the
 *      shared framework (`assets/js/commemorative.js`); this file only supplies the
 *      artwork, so a second ambient scheduler can never appear.
 *   4. `boot()` — wires all three, in the browser only, welcome first.
 *
 * The controllers take their scope element (and, for the tests, their document,
 * window and storage) as arguments, so `tests/node/christmas-journey.test.js` and
 * `tests/node/christmas-welcome.test.js` drive the REAL code with a fake DOM.
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

	var WELCOME_SELECTOR = '.ce-xmas-welcome[data-ce-welcome]';
	var WELCOME_DIALOG_SELECTOR = '[data-ce-welcome-dialog]';
	var WELCOME_CLOSE_SELECTOR = '[data-ce-welcome-close]';
	var WELCOME_PREVIEW_OPEN_SELECTOR = '[data-ce-welcome-preview-open]';
	var WELCOME_STATE = 'data-ce-welcome-state';
	var WELCOME_PREVIEW = 'data-ce-welcome-preview';
	var WELCOME_CLAIMED = 'data-ce-welcome-claimed';
	var WELCOME_OPEN_CLASS = 'is-welcome-open';
	var WELCOME_LOCK_CLASS = 'ce-xmas-welcome-locked';
	var WELCOME_STORAGE_KEY = 'ce_christmas_welcome_dismissed_v1';

	// The page's welcome controller (at most one per page). It is exposed through the
	// public API so a maintainer or a test can drive the dialog explicitly.
	var welcome = null;

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
	 * The welcome is shown once per BROWSER TAB SESSION. `sessionStorage` is cleared
	 * when the session ends, so a genuinely new session is eligible again — this is
	 * the deliberate compromise between "see it immediately" and "never be nagged".
	 * Nothing leaves the tab: no cookie, no `localStorage`, no server round trip.
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

	/**
	 * Build the homepage welcome controller for one `[data-ce-welcome]` root.
	 *
	 * The server renders the WHOLE hero once inside a CLOSED `<dialog>`, plus a
	 * compact fallback card. This controller owns every page state:
	 *
	 *   - `open()` → `showModal()`: the browser's own top layer makes the page behind
	 *     inert and keeps sequential focus inside the dialog, so there is no
	 *     hand-written focus trap to get wrong;
	 *   - the background scroll position is captured and restored exactly (iOS
	 *     included, via `position: fixed` + a negative `top`);
	 *   - `close()` records the TAB-SESSION dismissal (never in a signed preview)
	 *     and leaves NOTHING inline: the dialog stays closed and the fallback stays
	 *     hidden, so the hero keeps its exact original geometry — no band, no
	 *     reserved height, no residue;
	 *   - everything fails closed: no dialog, no close control or no `showModal()`
	 *     keeps the dialog closed, hides the close button and leaves the compact
	 *     fallback card as the usable state;
	 *   - the signed preview auto-opens exactly like the public view — same modal,
	 *     same top layer — but closing it NEVER touches the visitor dismissal key,
	 *     so reloading the preview shows the popup again; its card is pinned below
	 *     the preview notice, the admin bar and the sticky header, and the server
	 *     marks the dialog itself so the preview backdrop is tinted lighter and the
	 *     notice stays readable above the card;
	 *   - claiming is idempotent: a root claimed once yields no second controller,
	 *     no second set of listeners and never a second `showModal()`.
	 *
	 * It is NOT a scheduler: no timer, no interval, no `requestAnimationFrame`, no
	 * network, no analytics. Eligibility is decided by the server (the placement is
	 * only rendered when the dispatcher selects the package) — never by a date
	 * computation in here.
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

		// Fail closed: incomplete markup, or an engine without a modal dialog, keeps
		// the dialog CLOSED (never an inline card) and leaves the compact fallback
		// card as the usable state — with no control that cannot work.
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
				// The exact previous scroll state: the page must not jump to the top.
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

			// Auto-open: nothing was focused before, so continue from the page's own
			// landmark instead of stealing focus into an arbitrary control.
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

			// The dialog stays CLOSED: a non-modal `open` dialog is an in-flow card,
			// which is the broken banner this welcome must never show. Nothing is left
			// in the flow, so the hero keeps its exact original geometry after dismissal.
			if (typeof dialog.removeAttribute === 'function') {
				dialog.removeAttribute('open');
			}

			// The obstruction offset only matters while the card is open.
			clearObstructionOffset();

			if (view && typeof view.removeEventListener === 'function') {
				view.removeEventListener('resize', applyObstructionOffset);
				view.removeEventListener('load', reapplyObstructionOffset);
			}

			setState(isPreview() ? 'preview' : 'dismissed');
			unlockScroll();
			restoreFocus();
		}

		/**
		 * Pin the card below whatever occupies the top of the viewport.
		 *
		 * The WordPress admin bar, the signed-preview notice and the sticky site
		 * header must all stay visible ABOVE the card, so the lowest of their
		 * bottom edges (plus a gap) is written to the dialog as
		 * `--ce-xmas-welcome-obstruction-top`; the stylesheet folds that value
		 * into the same `max()` as `env(safe-area-inset-top)`. Nothing is
		 * scheduled here — it runs once per open and once per resize while open.
		 */
		function applyObstructionOffset() {
			if (
				!doc ||
				typeof doc.querySelector !== 'function' ||
				!dialog ||
				!dialog.style ||
				typeof dialog.style.setProperty !== 'function'
			) {
				return;
			}

			var obstructionSelectors = [ '#wpadminbar', '.site-header', '.conexao-preview-banner' ];
			var bottom = 0;

			for (var index = 0; index < obstructionSelectors.length; index++) {
				var obstruction = null;

				try {
					obstruction = doc.querySelector( obstructionSelectors[ index ] );
				} catch (error) {
					obstruction = null;
				}

				if (!obstruction || typeof obstruction.getBoundingClientRect !== 'function') {
					continue;
				}

				var rect = obstruction.getBoundingClientRect();

				// A notice scrolled out of view (or absent) never pins the card.
				if (!rect || typeof rect.bottom !== 'number' || rect.bottom <= 0) {
					continue;
				}

				bottom = Math.max( bottom, rect.bottom );
			}

			if (bottom > 0) {
				dialog.style.setProperty(
					'--ce-xmas-welcome-obstruction-top',
					Math.ceil( bottom + 12 ) + 'px'
				);
			}
		}

		function clearObstructionOffset() {
			if (dialog && dialog.style && typeof dialog.style.removeProperty === 'function') {
				dialog.style.removeProperty( '--ce-xmas-welcome-obstruction-top' );
			}
		}

		function reapplyObstructionOffset() {
			// Only while the modal is actually open: a late `load`/fonts callback
			// must never leave a stale offset on a settled (closed) dialog.
			if (modal) {
				applyObstructionOffset();
			}
		}

		function open() {
			if (modal) {
				return true;
			}

			previousFocus = doc ? doc.activeElement : null;

			if (typeof closeButton.removeAttribute === 'function') {
				closeButton.removeAttribute('hidden');
			}

			setState('open');

			if (dialog.classList && typeof dialog.classList.add === 'function') {
				dialog.classList.add(WELCOME_OPEN_CLASS);
			}

			// A dialog that still carries `open` is NON-modal, and the engine refuses to
			// upgrade it in place ("already open as a non-modal dialog"), so any stale
			// `open` is released for the instant it takes to hand the node to the top
			// layer. Both steps happen in one task, so nothing is painted in between.
			if (typeof dialog.removeAttribute === 'function') {
				dialog.removeAttribute('open');
			}

			// Top-of-screen placement: the card starts below the admin bar, the
			// preview notice and the sticky header for the whole time it is open.
			applyObstructionOffset();

			try {
				dialog.showModal();
			} catch (error) {
				// No modal dialog here: keep the dialog CLOSED (never a half-open or
				// inline card) and fall back to the compact fallback card instead.
				if (dialog.classList && typeof dialog.classList.remove === 'function') {
					dialog.classList.remove(WELCOME_OPEN_CLASS);
				}
				if (typeof dialog.removeAttribute === 'function') {
					dialog.removeAttribute('open');
				}
				clearObstructionOffset();
				hideClose();
				setState('fallback');
				return false;
			}

			modal = true;
			lockScroll();
			focusQuietly(dialog);

			// The obstructions can still grow AFTER this first measurement (the
			// header reflows when web fonts and late images settle), so re-measure
			// when the document finishes loading, when the fonts are ready and on
			// every resize — plain listeners and one promise, never a timer,
			// interval or animation frame; all removed again on settle.
			if (view && typeof view.addEventListener === 'function') {
				view.addEventListener('resize', applyObstructionOffset);
				view.addEventListener('load', reapplyObstructionOffset);
			}

			if (doc && doc.fonts && doc.fonts.ready && typeof doc.fonts.ready.then === 'function') {
				doc.fonts.ready.then(reapplyObstructionOffset, reapplyObstructionOffset);
			}

			return true;
		}

		function close(remember) {
			if (!modal) {
				return false;
			}

			modal = false;

			// The signed preview must never mutate the visitor's dismissal state:
			// viewing (and closing) a preview changes nothing outside this document.
			if (false !== remember && !isPreview()) {
				flag.write();
			}

			try {
				dialog.close();
			} catch (error) {
				// Already closed by the engine: settle() below still restores the
				// final state (dismissed, or back to the preview trigger).
			}

			settle();

			return true;
		}

		function onClose() {
			// Escape, the browser's own close request, or a programmatic close.
			if (!modal) {
				return;
			}

			close(true);
		}

		function onClick(event) {
			// `detail === 0` is a keyboard-generated click, never a backdrop tap.
			if (!modal || !event || 0 === event.detail) {
				return;
			}

			if (event.target !== dialog || typeof dialog.getBoundingClientRect !== 'function') {
				return;
			}

			var rect = dialog.getBoundingClientRect();
			var x = event.clientX || 0;
			var y = event.clientY || 0;

			// Only a click genuinely OUTSIDE the dialog box counts as a backdrop click;
			// the card's own padding must never close it.
			if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
				close(true);
			}
		}

		function onCloseClick(event) {
			if (event && typeof event.preventDefault === 'function') {
				event.preventDefault();
			}

			close(true);
		}

		if (typeof dialog.addEventListener === 'function') {
			dialog.addEventListener('close', onClose);
			dialog.addEventListener('click', onClick);
		}

		if (typeof closeButton.addEventListener === 'function') {
			closeButton.addEventListener('click', onCloseClick);
		}

		// The preview trigger ships `hidden` and stays hidden: the signed preview
		// auto-opens the same modal, so no click is ever required to inspect it.
		// It stays wired for a maintainer who reveals it by hand — never for a
		// visitor, because the fallback card it lives in is hidden in every state
		// a wired controller owns.
		var previewTrigger = typeof root.querySelector === 'function'
			? root.querySelector(WELCOME_PREVIEW_OPEN_SELECTOR)
			: null;

		if (previewTrigger && typeof previewTrigger.addEventListener === 'function') {
			previewTrigger.addEventListener('click', onPreviewTriggerClick);
		}

		// Claimed exactly once: a repeated boot creates no second controller, adds
		// no second set of listeners and can never open the dialog twice.
		if (typeof root.setAttribute === 'function') {
			root.setAttribute(WELCOME_CLAIMED, '1');
		}

		return {
			root: root,
			dialog: dialog,
			closeButton: closeButton,
			storageKey: flag.key,
			open: open,
			close: close,
			setState: setState,
			isOpen: function () {
				return modal;
			},
			isDismissed: function () {
				return flag.read();
			},
			isPreview: isPreview
		};
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

	/**
	 * Enhance every homepage welcome rendered on this page.
	 *
	 * There is at most one: the placement is homepage-only and the dispatcher renders
	 * a single package. Auto-open is decided HERE and only here — a dismissal already
	 * recorded for this tab session leaves the public welcome closed with nothing
	 * inline, while every other eligible view (the signed private preview included)
	 * opens the modal exactly once; the preview close never touches the dismissal
	 * key, so reloading the preview shows the popup again.
	 *
	 * @param {Object} scope Optional window-like object (the runtime tests inject one).
	 * @returns {Array} The controllers that were created.
	 */
	function bootWelcome(scope) {
		var view = scope || win;

		if (!view || !view.document || typeof view.document.querySelectorAll !== 'function') {
			return [];
		}

		var roots = toArray(view.document.querySelectorAll(WELCOME_SELECTOR));
		var controllers = [];

		for (var index = 0; index < roots.length; index++) {
			var controller = createWelcome(roots[index], {
				document: view.document,
				window: view,
				storage: welcomeStorage(view)
			});

			if (!controller) {
				continue;
			}

			controllers.push(controller);

			if (!controller.isPreview() && controller.isDismissed()) {
				// A dismissal in THIS tab session suppresses the public welcome and
				// leaves nothing inline: no band, no reserved height, no residue.
				controller.setState('dismissed');
			} else {
				// Every other eligible view opens the modal exactly once — the
				// signed private preview included, so an editor inspects the exact
				// visitor experience without clicking anything. The preview close
				// never writes the dismissal key (see `close()`), so reloading the
				// preview shows the popup again.
				controller.open();
			}
		}

		// A repeated boot finds every root already claimed and yields no new
		// controller; keep the first controller alive instead of clobbering it.
		if (controllers.length) {
			welcome = controllers[0];
		}

		return controllers;
	}

	function boot() {
		if (!win || !win.document) {
			return;
		}

		function start() {
			// The welcome goes first: it is the only part of this package that must
			// happen before the visitor can interact with the page, and nothing later
			// may stop it from claiming the welcome.
			bootWelcome();
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
		createWelcome: createWelcome,
		createSessionFlag: createSessionFlag,
		createStarDecoration: createStarDecoration,
		bootJourneys: bootJourneys,
		bootWelcome: bootWelcome,
		bootAmbient: bootAmbient,
		boot: boot,
		openWelcome: function () {
			return welcome ? welcome.open() : false;
		},
		closeWelcome: function () {
			return welcome ? welcome.close(true) : false;
		},
		getWelcome: function () {
			return welcome;
		}
	};
});

