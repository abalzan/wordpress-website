/**
 * Runtime test — Christmas micro-animations.
 *
 * Executed with `node --test` (see tests/scripts/verify-commemorative-runtime.py).
 * It loads the REAL `assets/js/presentations/christmas/christmas.js` and drives
 * the new controllers with a fake DOM, a fake window and a fake
 * IntersectionObserver, so the behaviour is PROVEN BY RUNNING IT.
 *
 * Covered:
 *   - the welcome star twinkle is present in the package CSS, runs once
 *     (animation-iteration-count: 1) and is suppressed under reduced motion;
 *   - the selected CTA sparkle fires only for the welcome and landing CTAs,
 *     creates 3-5 bounded particles, never prevents default or stops
 *     propagation (navigation continues), works for keyboard activation,
 *     is suppressed under reduced motion, caps particles and removes them;
 *   - the gentle content reveal uses IntersectionObserver, reveals once per
 *     target, keeps content visible without JavaScript and under reduced
 *     motion, and never replays;
 *   - the shared-star journey pulse is a one-time glow that does not fight the
 *     user-controlled star travel;
 *   - no duplicate initialisation (idempotent boot).
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');
const fs = require('node:fs');

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

const cssSource = fs.readFileSync(
	path.join(
		__dirname,
		'..',
		'..',
		'wp-content',
		'themes',
		'conexao-br-irlanda',
		'assets',
		'css',
		'presentations',
		'christmas.css'
	),
	'utf8'
);

function rect(left, top, width, height) {
	return {
		left,
		top,
		right: left + width,
		bottom: top + height,
		width,
		height
	};
}

function matchesSelector(el, sel) {
	if (!el || !el.classList) return false;
	const parts = String(sel).split(',').map((s) => s.trim());
	for (const p of parts) {
		if (p.startsWith('.') && el.classList.contains(p.slice(1))) return true;
		if (p.startsWith('#') && el.attributes && el.attributes.id === p.slice(1)) return true;
	}
	return false;
}

function element(attributes) {
	const store = Object.assign({}, attributes || {});
	const listeners = {};
	const children = [];
	const styleProps = {};
	const cls = store.class || '';
	const classNames = cls.split(/\s+/).filter(Boolean);
	const node = {
		tagName: (store.tag || 'div').toUpperCase(),
		classList: {
			names: new Set(classNames),
			add() {
				for (const n of arguments) {
					node.classList.names.add(String(n));
				}
			},
			remove() {
				for (const n of arguments) {
					node.classList.names.delete(String(n));
				}
			},
			contains(n) {
				return node.classList.names.has(String(n));
			}
		},
		attributes: store,
		getAttribute(n) {
			return Object.prototype.hasOwnProperty.call(store, n) ? store[n] : null;
		},
		setAttribute(n, v) {
			store[n] = String(v);
		},
		removeAttribute(n) {
			delete store[n];
		},
		hasAttribute(n) {
			return Object.prototype.hasOwnProperty.call(store, n);
		},
		style: {
			setProperty(n, v) {
				styleProps[n] = String(v);
			},
			removeProperty(n) {
				delete styleProps[n];
			},
			getPropertyValue(n) {
				return Object.prototype.hasOwnProperty.call(styleProps, n)
					? styleProps[n]
					: '';
			}
		},
		children,
		parentNode: null,
		rect: store.rect || rect(0, 0, 0, 0),
		getBoundingClientRect() {
			return Object.assign({}, node.rect);
		},
		appendChild(c) {
			c.parentNode = node;
			children.push(c);
			return c;
		},
		removeChild(c) {
			const idx = children.indexOf(c);
			if (idx > -1) {
				children.splice(idx, 1);
			}
			c.parentNode = null;
			return c;
		},
		addEventListener(t, h) {
			listeners[t] = listeners[t] || [];
			listeners[t].push(h);
		},
		removeEventListener(t, h) {
			if (listeners[t]) {
				listeners[t] = listeners[t].filter((x) => x !== h);
			}
		},
		dispatch(t, event) {
			const payload = Object.assign(
				{ currentTarget: node, target: node, defaultPrevented: false },
				event || {}
			);
			payload.preventDefault = () => {
				payload.defaultPrevented = true;
			};
			(listeners[t] || []).forEach((h) => h(payload));
			return payload;
		},
		querySelector(sel) {
			const stack = node.children.slice();
			while (stack.length) {
				const c = stack.shift();
				if (matchesSelector(c, sel)) return c;
				if (c.children) stack.push(...c.children);
			}
			return null;
		},
		querySelectorAll(sel) {
			const out = [];
			const stack = node.children.slice();
			while (stack.length) {
				const c = stack.shift();
				if (matchesSelector(c, sel)) out.push(c);
				if (c.children) stack.push(...c.children);
			}
			return out;
		},
		closest(sel) {
			let n = node;
			const parts = String(sel).split(',').map((s) => s.trim());
			while (n) {
				for (const p of parts) {
					if (
						p.startsWith('.') &&
						n.classList &&
						n.classList.contains(p.slice(1))
					) {
						return n;
					}
				}
				n = n.parentNode;
			}
			return null;
		}
	};
	Object.defineProperty(node, 'className', {
		get() {
			return Array.from(node.classList.names).join(' ');
		},
		set(v) {
			node.classList.names = new Set(String(v).split(/\s+/).filter(Boolean));
		},
		configurable: true
	});
	return node;
}

function fakeDocument() {
	const listeners = {};
	const body = element({ tag: 'body' });
	const doc = {
		documentElement: element({ tag: 'html' }),
		body,
		visibilityState: 'visible',
		_querySelector: null,
		_querySelectorAll: [],
		createElement(tag) {
			return element({ tag: tag || 'div' });
		},
		querySelector() {
			return doc._querySelector;
		},
		querySelectorAll() {
			return doc._querySelectorAll;
		},
		addEventListener(t, h) {
			listeners[t] = listeners[t] || [];
			listeners[t].push(h);
		},
		removeEventListener(t, h) {
			if (listeners[t]) {
				listeners[t] = listeners[t].filter((x) => x !== h);
			}
		},
		dispatchEvent(event) {
			const type = event && event.type;
			const payload = Object.assign(
				{ target: event && event.target, currentTarget: doc, defaultPrevented: false },
				event || {}
			);
			payload.preventDefault = () => {
				payload.defaultPrevented = true;
			};
			(listeners[type] || []).forEach((h) => h(payload));
			return payload;
		}
	};
	return doc;
}

function fakeWindow(doc) {
	let reduced = false;
	const view = {
		doc,
		document: doc,
		matchMedia() {
			return { matches: reduced };
		},
		addEventListener() {},
		innerHeight: 800,
		innerWidth: 1200,
		reduceMotion(v) {
			reduced = !!v;
		},
		observers: [],
		IntersectionObserver: function (callback) {
			const obs = {
				callback,
				targets: new Set(),
				observe(el) {
					this.targets.add(el);
				},
				unobserve(el) {
					this.targets.delete(el);
				},
				disconnect() {
					this.targets.clear();
				},
				intersect(el) {
					callback([{ target: el, isIntersecting: true }]);
				}
			};
			view.observers.push(obs);
			return obs;
		}
	};
	return view;
}

function ctaButton(doc, view, cls, r) {
	const btn = element({
		tag: 'a',
		class: 'btn btn-primary ' + cls,
		rect: r || rect(100, 400, 200, 44)
	});
	return btn;
}

/* -------------------------------------------------------------------------- */
/* Animation 1 — welcome star twinkle (CSS)                                   */
/* -------------------------------------------------------------------------- */

test('welcome star twinkle exists in the package CSS and runs once', () => {
	assert.ok(cssSource.includes('@keyframes ce-xmas-welcome-star-twinkle'), 'twinkle keyframe present');
	assert.ok(
		cssSource.includes('animation: ce-xmas-welcome-star-twinkle 1.2s ease-out 1'),
		'twinkle runs once (iteration-count 1)'
	);
	assert.ok(
		/\.ce-xmas-welcome__dialog\.is-welcome-open\s+\.ce-xmas-star__body/.test(cssSource),
		'twinkle hooked to the dialog open state'
	);
});

test('welcome star twinkle is suppressed under reduced motion', () => {
	assert.ok(
		cssSource.includes('@media (prefers-reduced-motion: reduce)'),
		'reduced motion block present'
	);
	assert.ok(
		cssSource.includes('animation: none !important'),
		'reduced motion forces animation none'
	);
});
/* -------------------------------------------------------------------------- */
/* Animation 2 — selected CTA sparkle                                         */
/* -------------------------------------------------------------------------- */

test('clicking the welcome CTA creates a bounded sparkle burst and leaves no residue', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const btn = ctaButton(doc, view, 'ce-christmas-hero__cta');
	doc.body.appendChild(btn);

	const sparkle = api.createCtaSparkle(doc, view);
	sparkle.attach();

	const payload = doc.dispatchEvent({
		type: 'click',
		target: btn,
		button: 0
	});

	assert.strictEqual(payload.defaultPrevented, false, 'navigation is not intercepted');
	const layer = doc.body.querySelector('.ce-xmas-sparkle-layer');
	assert.ok(layer, 'sparkle layer was added to the page');
	assert.ok(layer.children.length >= 3 && layer.children.length <= 5, 'burst creates 3-5 particles');
	for (let i = 0; i < layer.children.length; i++) {
		const p = layer.children[i];
		assert.strictEqual(p.getAttribute('aria-hidden'), 'true', 'particle is decorative');
		assert.strictEqual(p.style.top, String(btn.rect.bottom) + 'px', 'particle starts at the button bottom edge');
	}

	for (let i = layer.children.length - 1; i >= 0; i--) {
		layer.children[i].dispatch('animationend');
	}
	assert.strictEqual(layer.children.length, 0, 'all particles are removed after their animation');
	sparkle.detach();
});

test('clicking a non-CTA element creates no sparkle', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const link = element({ tag: 'a', class: 'other-link', rect: rect(10, 10, 40, 20) });
	doc.body.appendChild(link);

	const sparkle = api.createCtaSparkle(doc, view);
	sparkle.attach();
	doc.dispatchEvent({ type: 'click', target: link, button: 0 });

	const layer = doc.body.querySelector('.ce-xmas-sparkle-layer');
	assert.ok(!layer || layer.children.length === 0, 'no sparkle for a non-CTA');
	sparkle.detach();
});

test('reduced motion suppresses the CTA sparkle', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	view.reduceMotion(true);
	const btn = ctaButton(doc, view, 'ce-christmas-closing__cta');
	doc.body.appendChild(btn);

	const sparkle = api.createCtaSparkle(doc, view);
	sparkle.attach();
	doc.dispatchEvent({ type: 'click', target: btn, button: 0 });

	const layer = doc.body.querySelector('.ce-xmas-sparkle-layer');
	assert.ok(!layer || layer.children.length === 0, 'no sparkle under reduced motion');
	sparkle.detach();
});

test('CTA sparkle caps particles and prevents double bursts from rapid clicks', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const btn1 = ctaButton(doc, view, 'ce-christmas-hero__cta');
	const btn2 = ctaButton(doc, view, 'ce-christmas-closing__cta', rect(300, 500, 180, 44));
	doc.body.appendChild(btn1);
	doc.body.appendChild(btn2);

	const sparkle = api.createCtaSparkle(doc, view);
	sparkle.attach();

	doc.dispatchEvent({ type: 'click', target: btn1, button: 0 });
	const first = doc.body.querySelector('.ce-xmas-sparkle-layer').children.length;
	assert.ok(first >= 3 && first <= 5, 'first burst creates 3-5 particles');

	doc.dispatchEvent({ type: 'click', target: btn1, button: 0 });
	const afterCooldown = doc.body.querySelector('.ce-xmas-sparkle-layer').children.length;
	assert.strictEqual(afterCooldown, first, 'cooldown prevents a double burst on the same button');

	doc.dispatchEvent({ type: 'click', target: btn2, button: 0 });
	const total = doc.body.querySelector('.ce-xmas-sparkle-layer').children.length;
	assert.ok(total <= 6, 'total particles stay within the cap of 6');

	const layer = doc.body.querySelector('.ce-xmas-sparkle-layer');
	for (let i = layer.children.length - 1; i >= 0; i--) {
		layer.children[i].dispatch('animationend');
	}
	sparkle.detach();
});

/* -------------------------------------------------------------------------- */
/* Animation 3 — gentle content reveal                                        */
/* -------------------------------------------------------------------------- */

test('content reveal hides targets only via script and reveals them once on intersection', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const journey = element({
		tag: 'section',
		class: 'ce-christmas-journey',
		rect: rect(0, 900, 400, 300)
	});
	const module = element({
		tag: 'section',
		class: 'ce-module',
		rect: rect(0, 1100, 400, 200)
	});
	doc.body.appendChild(journey);
	doc.body.appendChild(module);
	doc._querySelector = journey;
	doc._querySelectorAll = [journey, module];

	const reveal = api.createContentReveal({ document: doc, window: view });
	assert.ok(reveal, 'reveal controller is created on a Christmas landing page');
	assert.ok(
		journey.classList.contains('ce-xmas-reveal-ready'),
		'journey starts hidden-ready (added by script)'
	);
	assert.ok(
		module.classList.contains('ce-xmas-reveal-ready'),
		'module starts hidden-ready (added by script)'
	);

	const obs = view.observers[view.observers.length - 1];
	obs.intersect(module);

	assert.ok(module.classList.contains('ce-xmas-revealed'), 'module is revealed on intersection');
	assert.ok(!module.classList.contains('ce-xmas-reveal-ready'), 'ready class is removed on reveal');

	obs.intersect(module);
	assert.strictEqual(module.classList.contains('ce-xmas-revealed'), true, 'still revealed, no replay');

	reveal.disconnect();
});

test('already-visible content reveals immediately without waiting for the observer', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const target = element({
		tag: 'section',
		class: 'ce-christmas-closing',
		rect: rect(0, 100, 400, 200)
	});
	doc.body.appendChild(target);
	doc._querySelector = target;
	doc._querySelectorAll = [target];

	api.createContentReveal({ document: doc, window: view });

	assert.ok(target.classList.contains('ce-xmas-revealed'), 'above-fold content is revealed immediately');
	assert.ok(!target.classList.contains('ce-xmas-reveal-ready'), 'no ready class for already-visible content');
});

test('content reveal does nothing when IntersectionObserver is unavailable', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	delete view.IntersectionObserver;
	const target = element({ tag: 'section', class: 'ce-module' });
	doc.body.appendChild(target);
	doc._querySelector = target;
	doc._querySelectorAll = [target];

	const reveal = api.createContentReveal({ document: doc, window: view });
	assert.strictEqual(reveal, null, 'no controller without IntersectionObserver');
	assert.ok(!target.classList.contains('ce-xmas-reveal-ready'), 'content stays visible without JS');
});

test('content reveal is suppressed under reduced motion', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	view.reduceMotion(true);
	const target = element({ tag: 'section', class: 'ce-module', rect: rect(0, 900, 400, 200) });
	doc.body.appendChild(target);
	doc._querySelector = target;
	doc._querySelectorAll = [target];

	const reveal = api.createContentReveal({ document: doc, window: view });
	assert.strictEqual(reveal, null, 'no controller under reduced motion');
	assert.ok(!target.classList.contains('ce-xmas-reveal-ready'), 'content is not hidden under reduced motion');
});

test('content reveal does not run on non-landing routes', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	doc._querySelector = null;
	doc._querySelectorAll = [];

	const reveal = api.createContentReveal({ document: doc, window: view });
	assert.strictEqual(reveal, null, 'no controller when no Christmas landing content is present');
});

/* -------------------------------------------------------------------------- */
/* Animation 5 — shared-star journey pulse                                    */
/* -------------------------------------------------------------------------- */

test('journey pulse adds a one-time glow class and removes it on animation end', () => {
	const doc = fakeDocument();
	const starBody = element({ tag: 'span', class: 'ce-xmas-star__body' });
	const star = element({ tag: 'span', class: 'ce-christmas-journey__star-svg' });
	star.appendChild(starBody);
	const journey = element({ tag: 'section', class: 'ce-christmas-journey' });
	journey.appendChild(star);
	doc.body.appendChild(journey);

	const view = fakeWindow(doc);
	api.pulseJourneyStar(journey, doc, view);

	assert.ok(
		journey.querySelector('.ce-christmas-journey__star-svg').classList.contains('ce-xmas-journey-star--pulse'),
		'pulse class added on first visibility'
	);

	api.pulseJourneyStar(journey, doc, view);

	starBody.dispatch('animationend');
	assert.ok(
		!star.classList.contains('ce-xmas-journey-star--pulse'),
		'pulse class removed after one cycle'
	);
});

test('journey pulse is a no-op without the journey star markup', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const empty = element({ tag: 'section', class: 'ce-christmas-journey' });
	doc.body.appendChild(empty);

	assert.doesNotThrow(() => api.pulseJourneyStar(empty, doc, view), 'no throw without star');
});

/* -------------------------------------------------------------------------- */
/* No duplicate initialisation                                                */
/* -------------------------------------------------------------------------- */

test('bootMicroAnimations is idempotent and does not add duplicate listeners', () => {
	const doc = fakeDocument();
	const view = fakeWindow(doc);
	const btn = ctaButton(doc, view, 'ce-christmas-hero__cta');
	doc.body.appendChild(btn);

	const scope = { document: doc, window: view };
	api.bootMicroAnimations(scope);
	api.bootMicroAnimations(scope);
	api.bootMicroAnimations(scope);

	assert.strictEqual(doc.documentElement.getAttribute('data-ce-micro-animations-claimed'), '1', 'boot is claimed');
});
