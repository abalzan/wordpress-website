/**
 * Runtime test — the shared commemorative ambient scheduler.
 *
 * Executed with `node --test` (see tests/scripts/verify-commemorative-runtime.py).
 * It loads the REAL `assets/js/commemorative.js` and drives it with fake timers,
 * a fake DOM and fake session storage, so the behaviour is PROVEN BY RUNNING IT —
 * not by searching its source text.
 *
 * Covered:
 *   - randomised interval inside the configured bounds, and the initial/subsequent
 *     split (the approved Halloween cadence);
 *   - duration inside the configured bounds;
 *   - at most one decoration at a time;
 *   - the node is removed after its duration;
 *   - the per-session cap stops the scheduler, survives a "reload" (same storage)
 *     and resets with a new session (new storage);
 *   - a hidden tab schedules nothing, and returning schedules a FRESH interval
 *     plus a cooldown instead of replaying missed work;
 *   - reduced motion disables the scheduler entirely;
 *   - every decoration is aria-hidden, non-focusable and pointer-events: none.
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

const api = require(path.join(__dirname, '..', '..', 'wp-content', 'themes', 'conexao-br-irlanda', 'assets', 'js', 'commemorative.js'));

/* ------------------------------------------------------------------------- */
/* Test doubles                                                               */
/* ------------------------------------------------------------------------- */

function fakeClock() {
	const timers = new Map();
	let nextId = 1;

	return {
		timers,
		setTimeout(fn, delay) {
			const id = nextId++;
			timers.set(id, { fn, delay: Number(delay) });
			return id;
		},
		clearTimeout(id) {
			timers.delete(id);
		},
		/** Run the next pending timer (FIFO by id) and return its delay. */
		runNext() {
			const entry = [...timers.entries()].sort((a, b) => a[0] - b[0])[0];
			if (!entry) {
				return null;
			}
			timers.delete(entry[0]);
			entry[1].fn();
			return entry[1].delay;
		},
		delays() {
			return [...timers.values()].map((t) => t.delay);
		},
		get pending() {
			return timers.size;
		}
	};
}

function fakeElement(tag) {
	const attributes = {};
	const element = {
		tagName: (tag || 'div').toUpperCase(),
		className: '',
		style: {},
		children: [],
		parentNode: null,
		setAttribute(name, value) {
			attributes[name] = String(value);
		},
		getAttribute(name) {
			return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null;
		},
		appendChild(child) {
			child.parentNode = element;
			element.children.push(child);
			return child;
		},
		removeChild(child) {
			element.children = element.children.filter((c) => c !== child);
			child.parentNode = null;
			return child;
		}
	};

	return element;
}

function fakeStorage() {
	const map = new Map();

	return {
		getItem: (key) => (map.has(key) ? map.get(key) : null),
		setItem: (key, value) => map.set(key, String(value)),
		removeItem: (key) => map.delete(key),
		dump: () => Object.fromEntries(map)
	};
}

const PLAYFUL_HALLOWEEN = {
	energy: 'playful',
	intervalMinMs: 20000,
	intervalMaxMs: 30000,
	subsequentIntervalMinMs: 25000,
	subsequentIntervalMaxMs: 35000,
	durationMinMs: 900,
	durationMaxMs: 3000,
	maxSimultaneous: 1,
	sessionCap: 7
};

const CALM_CHRISTMAS = {
	energy: 'calm',
	intervalMinMs: 60000,
	intervalMaxMs: 120000,
	durationMinMs: 2000,
	durationMaxMs: 4000,
	maxSimultaneous: 1,
	sessionCap: 3
};

function build(options) {
	const clock = options.clock || fakeClock();
	const container = options.container || fakeElement('div');
	const visibility = options.visibility || { state: 'visible' };
	const reduced = options.reduced || { state: false };

	const scheduler = api.createAmbientScheduler({
		document: { visibilityState: visibility.state },
		container,
		config: options.config,
		packageSlug: options.packageSlug || 'halloween',
		occurrenceYear: options.occurrenceYear || 2026,
		storage: options.storage || fakeStorage(),
		createDecoration: options.createDecoration || (() => fakeElement('span')),
		setTimeout: clock.setTimeout,
		clearTimeout: clock.clearTimeout,
		random: options.random || (() => 0),
		visibility: () => visibility.state,
		reducedMotion: () => reduced.state
	});

	return { clock, container, visibility, reduced, scheduler };
}

/* ------------------------------------------------------------------------- */
/* The tests                                                                  */
/* ------------------------------------------------------------------------- */

test('the first interval is inside the configured bounds and the subsequent one is too', () => {
	const { clock, scheduler } = build({ config: PLAYFUL_HALLOWEEN });

	scheduler.start();

	const first = clock.delays()[0];
	assert.ok(first >= 20000 && first <= 30000, `first delay ${first} is inside 20-30 s`);

	clock.runNext();
	assert.strictEqual(scheduler.stats().count, 1);

	clock.runNext(); // removal timer -> schedules the next appearance

	const subsequent = clock.delays()[0];
	assert.ok(subsequent >= 25000 && subsequent <= 35000, `subsequent delay ${subsequent} is inside 25-35 s`);
});

test('the delay is randomised across the whole configured range', () => {
	for (const sample of [0, 0.25, 0.5, 0.75, 0.999]) {
		const { clock, scheduler } = build({ config: PLAYFUL_HALLOWEEN, random: () => sample });

		scheduler.start();

		const delay = clock.delays()[0];
		assert.ok(delay >= 20000 && delay <= 30000, `delay ${delay} stays inside the range for sample ${sample}`);
	}

	const min = build({ config: PLAYFUL_HALLOWEEN, random: () => 0 });
	min.scheduler.start();
	assert.strictEqual(min.clock.delays()[0], 20000);

	const max = build({ config: PLAYFUL_HALLOWEEN, random: () => 0.999999 });
	max.scheduler.start();
	assert.strictEqual(max.clock.delays()[0], 30000);
});

test('the decoration lives for a duration inside the configured bounds and is then removed', () => {
	const { clock, container, scheduler } = build({ config: PLAYFUL_HALLOWEEN });

	scheduler.start();
	clock.runNext();

	assert.strictEqual(container.children.length, 1, 'one decoration node exists');
	const duration = clock.delays()[0];
	assert.ok(duration >= 900 && duration <= 3000, `duration ${duration} is inside 0.9-3 s`);

	clock.runNext();

	assert.strictEqual(container.children.length, 0, 'the node is removed after its duration');
});

test('at most one decoration is ever shown at the same time', () => {
	const { clock, container, scheduler } = build({ config: PLAYFUL_HALLOWEEN });

	scheduler.start();
	clock.runNext();

	assert.strictEqual(container.children.length, 1);

	scheduler.show();

	assert.strictEqual(container.children.length, 1, 'the second attempt added no node');
	assert.strictEqual(scheduler.stats().count, 1, 'and did not consume the session budget');
});

test('the session cap stops the scheduler after N decorations in one tab session', () => {
	const storage = fakeStorage();
	const { clock, container, scheduler } = build({ config: PLAYFUL_HALLOWEEN, storage });

	scheduler.start();

	for (let i = 0; i < 7; i += 1) {
		clock.runNext();
		clock.runNext();
	}

	assert.strictEqual(scheduler.stats().count, 7, 'seven decorations were counted');
	assert.strictEqual(scheduler.isEligible(), false, 'the cap makes the scheduler ineligible');
	assert.strictEqual(clock.pending, 0, 'no timer remains once the cap is reached');

	scheduler.start();

	assert.strictEqual(clock.pending, 0, 'restarting does not schedule past the cap');
	assert.strictEqual(container.children.length, 0, 'no extra node appears');
});

test('the cap survives a reload in the same tab and resets in a new session', () => {
	const storage = fakeStorage();

	for (let i = 0; i < 5; i += 1) {
		const first = build({ config: PLAYFUL_HALLOWEEN, storage });
		first.scheduler.start();
		first.clock.runNext();
		first.clock.runNext();
	}

	const reloaded = build({ config: PLAYFUL_HALLOWEEN, storage });
	reloaded.scheduler.start();
	reloaded.clock.runNext();
	reloaded.clock.runNext();
	reloaded.clock.runNext();

	const afterReload = build({ config: PLAYFUL_HALLOWEEN, storage });

	assert.strictEqual(afterReload.scheduler.stats().count, 7, 'the count continued across the reload (5 + 2)');

	const newSession = build({ config: PLAYFUL_HALLOWEEN, storage: fakeStorage() });

	assert.strictEqual(newSession.scheduler.stats().count, 0, 'a new session resets the counter');
	assert.strictEqual(newSession.scheduler.isEligible(), true, 'and is eligible again');
});



test('a hidden tab schedules nothing; returning schedules a fresh interval plus a cooldown', () => {
	const { clock, visibility, scheduler } = build({ config: PLAYFUL_HALLOWEEN });

	visibility.state = 'hidden';
	scheduler.start();

	assert.strictEqual(clock.pending, 0, 'nothing is scheduled while the tab is hidden');

	visibility.state = 'visible';
	scheduler.onVisibilityChange();

	const resumed = clock.delays()[0];
	const cooldown = api.RESUME_COOLDOWN_MS;

	assert.ok(
		resumed >= 20000 + cooldown && resumed <= 30000 + cooldown,
		`the resumed delay ${resumed} is a FRESH interval plus the ${cooldown} ms cooldown`
	);

	visibility.state = 'hidden';
	scheduler.onVisibilityChange();

	assert.strictEqual(clock.pending, 0, 'hiding the tab clears the pending timer (no catch-up)');
});

test('reduced motion disables the scheduler entirely', () => {
	const { clock, container, reduced, scheduler } = build({ config: CALM_CHRISTMAS, packageSlug: 'christmas' });

	reduced.state = true;
	scheduler.start();

	assert.strictEqual(clock.pending, 0, 'no timer is created under reduced motion');
	assert.strictEqual(scheduler.isEligible(), false, 'the scheduler is ineligible');

	scheduler.show();

	assert.strictEqual(container.children.length, 0, 'a forced show still produces nothing');
});

test('every decoration is decorative: aria-hidden, out of the tab order, pointer-events none', () => {
	const { clock, container, scheduler } = build({ config: CALM_CHRISTMAS, packageSlug: 'christmas' });

	scheduler.start();
	clock.runNext();

	const node = container.children[0];

	assert.strictEqual(node.getAttribute('aria-hidden'), 'true');
	assert.strictEqual(node.getAttribute('tabindex'), '-1');
	assert.strictEqual(node.style.pointerEvents, 'none');
	assert.ok(node.className.includes('ce-ambient-decoration'), 'the shared decoration class is applied');
});

test('stop() cleans up the pending timer and any active decoration', () => {
	const { clock, container, scheduler } = build({ config: PLAYFUL_HALLOWEEN });

	scheduler.start();
	clock.runNext();

	assert.strictEqual(container.children.length, 1);

	scheduler.stop();

	assert.strictEqual(container.children.length, 0, 'the active node is removed');
	assert.strictEqual(clock.pending, 0, 'the timers are cleared');
	assert.strictEqual(scheduler.isEligible(), false, 'a stopped scheduler is ineligible');
});

test('the calm profile never uses the playful cadence', () => {
	const { clock, scheduler } = build({ config: CALM_CHRISTMAS, packageSlug: 'christmas' });

	scheduler.start();

	const delay = clock.delays()[0];

	assert.ok(delay >= 60000 && delay <= 120000, `a calm delay ${delay} is inside 60-120 s`);
	assert.ok(delay > 35000, 'the calm profile never accepts the Halloween cadence');
});
