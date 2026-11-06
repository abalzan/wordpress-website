/**
 * Halloween experience package — package behaviour.
 *
 * Moved verbatim out of the global `main.js` bundle (r10 §7.9) so a request with
 * no active Halloween package ships none of this code. It contains:
 *
 *   1. the homepage-hero welcome (reveal, session dismissal, focus return);
 *   2. the approved mobile navigation-surprise scheduler — 20-30 s first effect,
 *      25-35 s subsequent effects — and the bat/pumpkin/ghost/witch effects;
 *   3. the cross-navigation handoff that continues an effect across a real
 *      document navigation.
 *
 * The cadence is an APPROVED, explicit package override of the generic ambient
 * defaults (r10 §7.7 exception, §9.9). Do not change these values without a new
 * approved change: `tests/test-seasonal-halloween-nav-effects.php` asserts them.
 *
 * The per-session decoration cap is delegated to the shared framework
 * (`ConexaoCommemorative.sessionCounter`), so the cap and its storage semantics
 * have exactly one implementation.
 *
 * @package Conexao_BR_Irlanda
 */

(function () {
	'use strict';

	// The single reduced-motion query for this package (the shared framework
	// exports the same helper; it is duplicated here only to keep this file
	// independent of the framework's boot order).
	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	// Per-session decoration cap for this package (the registry declares the same
	// value; this literal is the fallback when the framework payload is absent).
	var SESSION_CAP = 7;
	var sessionCounter = null;

	function decorationBudget() {
		if (sessionCounter) {
			return sessionCounter;
		}

		var container = document.querySelector('.ce-placement--site_wide_ambient[data-ce-package="halloween"]');
		var config = {};

		if (container) {
			try {
				config = JSON.parse(container.getAttribute('data-ce-ambient-config') || '{}');
			} catch (error) {
				config = {};
			}
		}

		if (config.sessionCap) {
			SESSION_CAP = config.sessionCap;
		}

		var shared = window.ConexaoCommemorative;

		if (shared && typeof shared.sessionCounter === 'function') {
			var storage = null;

			try {
				storage = window.sessionStorage;
			} catch (error) {
				storage = null;
			}

			sessionCounter = shared.sessionCounter({
				package: 'halloween',
				year: config.occurrenceYear || 0,
				storage: storage
			});
		} else {
			// No shared framework: an in-memory counter still bounds this page.
			var count = 0;
			sessionCounter = {
				read: function () {
					return count;
				},
				increment: function () {
					count += 1;
					return count;
				}
			};
		}

		return sessionCounter;
	}

	function decorationCapReached() {
		return decorationBudget().read() >= SESSION_CAP;
	}

	document.addEventListener('DOMContentLoaded', function () {
		initHalloweenWelcome();
		initHalloweenNavEffects();
	});


	// ===== Seasonal Halloween welcome =====
	function initHalloweenWelcome() {
		var presentation = document.querySelector('.seasonal-halloween-presentation[hidden]');
		if (!presentation) return;

		var closeButton = presentation.querySelector('.seasonal-halloween-close');
		var dismissalKey = presentation.getAttribute('data-dismiss-key');
		if (!closeButton || !dismissalKey) return;

		var isDismissed = false;
		try {
			isDismissed = window.sessionStorage.getItem(dismissalKey) === 'dismissed';
		} catch (error) {
			// Storage may be unavailable; the presentation remains dismissible.
		}
		if (isDismissed) return;

		var heroContent = presentation.closest('.hero-section')
			? presentation.closest('.hero-section').querySelector('.hero-content')
			: null;
		if (heroContent) {
			heroContent.setAttribute('inert', '');
			heroContent.setAttribute('aria-hidden', 'true');
		}

		presentation.hidden = false;

		closeButton.addEventListener('click', function() {
			var returnFocus = document.activeElement === closeButton;
			try {
				window.sessionStorage.setItem(dismissalKey, 'dismissed');
			} catch (error) {
				// Dismissal still applies for this page when storage is unavailable.
			}

			presentation.hidden = true;
			if (heroContent) {
				heroContent.removeAttribute('inert');
				heroContent.removeAttribute('aria-hidden');
			}

			if (returnFocus && heroContent) {
				var focusTarget = heroContent.querySelector('.hero-ctas a[href]');
				if (focusTarget) focusTarget.focus();
			}
		});
	}

// ===== Seasonal Halloween navigation and spontaneous mobile effects =====
function initHalloweenNavEffects() {
	'use strict';

	var marker = document.querySelector('.seasonal-halloween-active-marker');
	var COOLDOWN_MS = 1500;
	var ANIMATION_DURATION_MS = 1000;
	var WITCH_ANIMATION_DURATION_MS = 3000;
	var STATE_EXPIRY_MS = 3500;
	var TRANSITION_KEY = 'conexao-halloween-nav-effect-v1';
	var lastEffectTime = 0;
	var effectInProgress = false;
	var lastEffectName = null;
	var activeLayer = null;
	var activeCleanup = null;
	var activeEffectIsSpontaneous = false;
	var activeAnimationTimer = null;
	var spontaneousTimer = null;
	var hasSpontaneousAppeared = false;
	var mobileViewport = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
	var INITIAL_DELAY_MIN_MS = 20000;
	var INITIAL_DELAY_MAX_MS = 30000;
	var INTERVAL_MIN_MS = 25000;
	var INTERVAL_MAX_MS = 35000;

	var EFFECTS = ['bat', 'pumpkin', 'ghost', 'witch'];

	var EXCLUDED_SELECTORS = '.mobile-menu-toggle, .mobile-menu-close, ' +
		'.theme-toggle, .mobile-search-toggle, .mobile-search-close, ' +
		'.seasonal-halloween-close, [aria-controls="mobile-search"], ' +
		'[aria-controls="mobile-menu"]';

	function getClock() {
		if (window.performance && typeof window.performance.now === 'function' &&
			Number.isFinite(window.performance.timeOrigin)) {
			return {
				type: 'performance',
				value: window.performance.timeOrigin + window.performance.now()
			};
		}
		return { type: 'wall', value: Date.now() };
	}

	function removeTransitionState() {
		try {
			window.sessionStorage.removeItem(TRANSITION_KEY);
		} catch (error) {
			// Storage may be unavailable; the animation remains optional.
		}
	}

	function isNormalDocumentNavigation() {
		if (!window.performance ||
			typeof window.performance.getEntriesByType !== 'function') {
			return false;
		}

		var navigationEntries = window.performance.getEntriesByType('navigation');
		return !!navigationEntries.length && navigationEntries[0].type === 'navigate';
	}

	function getAnimationDuration(effectName) {
		return 'witch' === effectName ? WITCH_ANIMATION_DURATION_MS : ANIMATION_DURATION_MS;
	}

	function getSpontaneousAnimationDuration(effectName) {
		if ('witch' === effectName) return WITCH_ANIMATION_DURATION_MS;
		if ('ghost' === effectName) return 1200;
		if ('pumpkin' === effectName) return 1100;
		if ('bat' === effectName) return 900;
		return ANIMATION_DURATION_MS;
	}

	function randomDelay(minimum, maximum) {
		return minimum + Math.floor(Math.random() * (maximum - minimum + 1));
	}

	function isSpontaneousEligible() {
		var presentation = document.querySelector('.seasonal-halloween-presentation:not([hidden])');
		return !!(
			marker &&
			marker.isConnected &&
			mobileViewport &&
			mobileViewport.matches &&
			!prefersReducedMotion() &&
			'visible' === document.visibilityState &&
			!presentation &&
			!document.querySelector('.mobile-menu-overlay.active, .mobile-search-overlay.active')
		);
	}

	function clearSpontaneousTimer() {
		if (null === spontaneousTimer) return;
		window.clearTimeout(spontaneousTimer);
		spontaneousTimer = null;
	}

	function scheduleSpontaneous() {
		clearSpontaneousTimer();
		if (!isSpontaneousEligible() || effectInProgress) return;

		var minimum = hasSpontaneousAppeared ? INTERVAL_MIN_MS : INITIAL_DELAY_MIN_MS;
		var maximum = hasSpontaneousAppeared ? INTERVAL_MAX_MS : INITIAL_DELAY_MAX_MS;
		spontaneousTimer = window.setTimeout(appearSpontaneously, randomDelay(minimum, maximum));
	}

	function stopSpontaneous() {
		clearSpontaneousTimer();
		if (activeEffectIsSpontaneous && activeCleanup) {
			activeCleanup(false);
		}
	}

	function readSafeAreaInsets() {
		var probe = document.createElement('div');
		probe.style.cssText = 'position:fixed;left:0;top:0;width:0;height:0;visibility:hidden;pointer-events:none;padding:env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px);';
		document.body.appendChild(probe);

		var style = window.getComputedStyle(probe);
		var insets = {
			top: parseFloat(style.paddingTop) || 0,
			right: parseFloat(style.paddingRight) || 0,
			bottom: parseFloat(style.paddingBottom) || 0,
			left: parseFloat(style.paddingLeft) || 0
		};
		probe.parentNode.removeChild(probe);
		return insets;
	}

	function getSafeViewport() {
		var insets = readSafeAreaInsets();
		var width = window.innerWidth;
		var height = window.innerHeight;
		var header = document.querySelector('.site-header');
		var top = insets.top + 12;

		if (header) {
			top = Math.max(top, header.getBoundingClientRect().bottom + 12);
		}

		return {
			left: insets.left + 12,
			right: width - insets.right - 12,
			top: top,
			bottom: height - insets.bottom - 12,
			width: width,
			height: height
		};
	}

	function shuffle(items) {
		for (var i = items.length - 1; i > 0; i--) {
			var j = Math.floor(Math.random() * (i + 1));
			var item = items[i];
			items[i] = items[j];
			items[j] = item;
		}
		return items;
	}

	function intersectsVisibleContent(box) {
		var obstacles = document.querySelectorAll(
			'.site-header, h1, h2, h3, h4, h5, h6, p, li, blockquote, ' +
			'figcaption, summary, dt, dd, th, td, .hero-badge, [class*="title"], ' +
			'[class*="subtitle"], [class*="badge"], [class*="kicker"], a[href], button, input, select, ' +
			'textarea, label, [role="button"], [contenteditable="true"], ' +
			'.seasonal-halloween-presentation:not([hidden])'
		);

		for (var i = 0; i < obstacles.length; i++) {
			var obstacle = obstacles[i];
			if (obstacle.hidden || 'none' === window.getComputedStyle(obstacle).display) continue;

			var rect = obstacle.getBoundingClientRect();
			if (
				rect.width > 0 &&
				rect.height > 0 &&
				box.left < rect.right &&
				box.right > rect.left &&
				box.top < rect.bottom &&
				box.bottom > rect.top
			) {
				return true;
			}
		}

		return false;
	}

	function getPlacement(effectName) {
		var viewport = getSafeViewport();
		var clearance = 8;
		var sizes = {
			bat: { width: 64, height: 42 },
			pumpkin: { width: 56, height: 56 },
			ghost: { width: 50, height: 66 }
		};

		if ('witch' === effectName) {
			sizes.witch = {
				width: Math.min(148, Math.max(112, viewport.width * 0.36)),
				height: Math.min(148, Math.max(112, viewport.width * 0.36)) * 230 / 420
			};
		}

		var size = sizes[effectName];
		if (!size ||
			viewport.right - viewport.left < size.width + clearance * 2 ||
			viewport.bottom - viewport.top < size.height + clearance * 2) {
			return null;
		}

		var yCandidates = [];
		var pathTop = viewport.top + clearance;
		var pathBottom = viewport.bottom - size.height - clearance;

		if ('witch' === effectName) {
			var witchDrift = viewport.height * 0.03;
			pathTop += viewport.height * 0.02;
			pathBottom -= witchDrift;
			for (var witchBand = 0; witchBand <= 4; witchBand++) {
				yCandidates.push(pathTop + (pathBottom - pathTop) * witchBand / 4);
			}

			for (var w = 0; w < shuffle(yCandidates).length; w++) {
				var witchTop = yCandidates[w];
				var witchBox = {
					left: 0,
					right: viewport.width,
					top: witchTop - viewport.height * 0.02,
					bottom: witchTop + size.height + witchDrift
				};
				if (!intersectsVisibleContent(witchBox)) {
					return { x: viewport.width / 2, y: witchTop + size.height / 2 };
				}
			}
			return null;
		}

		if ('bat' === effectName) {
			pathTop += 12;
			pathBottom -= 12;
			for (var batBand = 0; batBand <= 4; batBand++) {
				yCandidates.push(pathTop + (pathBottom - pathTop) * batBand / 4);
			}

			for (var b = 0; b < shuffle(yCandidates).length; b++) {
				var batTop = yCandidates[b];
				var batBox = {
					left: 0,
					right: viewport.width,
					top: batTop - 12,
					bottom: batTop + size.height + 12
				};
				if (!intersectsVisibleContent(batBox)) {
					return { x: viewport.width / 2, y: batTop + size.height / 2 };
				}
			}
			return null;
		}

		var xCandidates = [
			viewport.left + size.width / 2 + clearance,
			viewport.right - size.width / 2 - clearance
		];
		var availableHeight = viewport.bottom - viewport.top - size.height - clearance * 2;
		for (var row = 0; row <= 3; row++) {
			var centerY = viewport.top + size.height / 2 + clearance + availableHeight * row / 3;
			for (var column = 0; column < xCandidates.length; column++) {
				var centerX = xCandidates[column];
				yCandidates.push({ x: centerX, y: centerY });
			}
		}

		var zones = shuffle(yCandidates);
		for (var z = 0; z < zones.length; z++) {
			var zone = zones[z];
			var box = {
				left: zone.x - size.width / 2 - clearance,
				right: zone.x + size.width / 2 + clearance,
				top: zone.y - size.height / 2 - clearance,
				bottom: zone.y + size.height / 2 + clearance
			};
			if (
				box.left >= viewport.left &&
				box.right <= viewport.right &&
				box.top >= viewport.top &&
				box.bottom <= viewport.bottom &&
				!intersectsVisibleContent(box)
			) {
				return zone;
			}
		}

		return null;
	}

	function appearSpontaneously() {
		spontaneousTimer = null;
		if (!isSpontaneousEligible()) return;

		// Per-session cap (r10 §7.7 addendum 2): after N decorations in one browser
		// session the scheduler stops for that session. The counter lives in the
		// shared framework so the storage semantics have one implementation.
		if (decorationCapReached()) return;

		if (effectInProgress) {
			scheduleSpontaneous();
			return;
		}

		var effectName = pickEffect();
		var placement = getPlacement(effectName);
		if (!placement) {
			scheduleSpontaneous();
			return;
		}

		decorationBudget().increment();
		playEffect(effectName, placement.x, placement.y, 0, true);
	}

	function handleSpontaneousUiChange(event) {
		if (!event.target || typeof event.target.closest !== 'function') return;

		var target = event.target.closest(
			'.seasonal-halloween-close, .mobile-menu-toggle, .mobile-menu-close, ' +
			'.mobile-search-toggle, .mobile-search-close'
		);
		if (!target) return;

		if (isSpontaneousEligible()) {
			scheduleSpontaneous();
		} else {
			stopSpontaneous();
		}
	}

	function consumeTransitionState() {
		var rawState;
		try {
			rawState = window.sessionStorage.getItem(TRANSITION_KEY);
			if (rawState !== null) {
				window.sessionStorage.removeItem(TRANSITION_KEY);
			}
		} catch (error) {
			return null;
		}

		if (!rawState) return null;

		var state;
		try {
			state = JSON.parse(rawState);
		} catch (error) {
			return null;
		}

		if (!state || Array.isArray(state) || state.version !== 1 ||
			EFFECTS.indexOf(state.effect) === -1 ||
			!Number.isFinite(state.startedAt) ||
			!Number.isFinite(state.x) || !Number.isFinite(state.y) ||
			('performance' !== state.clock && 'wall' !== state.clock) ||
			typeof state.target !== 'string') {
			return null;
		}

		if (!isNormalDocumentNavigation()) return null;

		try {
			var target = new URL(state.target, window.location.origin);
			var referrer = new URL(document.referrer);
			if (state.target.charAt(0) !== '/' ||
				target.origin !== window.location.origin ||
				target.pathname !== window.location.pathname ||
				target.search || target.hash ||
				referrer.origin !== window.location.origin) {
				return null;
			}
		} catch (error) {
			return null;
		}

		var now = getClock();
		if (now.type !== state.clock) return null;

		var elapsedMs = now.value - state.startedAt;
		if (!Number.isFinite(elapsedMs) || elapsedMs < 0 ||
			elapsedMs > STATE_EXPIRY_MS ||
			state.x < 0 || state.x > window.innerWidth ||
			state.y < 0 || state.y > window.innerHeight) {
			return null;
		}

		return {
			effect: state.effect,
			elapsedMs: elapsedMs,
			x: state.x,
			y: state.y
		};
	}

	function persistTransitionState(effectName, startedAt, clockType, target, centerX, centerY) {
		var state = {
			version: 1,
			effect: effectName,
			startedAt: startedAt,
			clock: clockType,
			target: target,
			x: centerX,
			y: centerY
		};
		var rawState;

		try {
			rawState = JSON.stringify(state);
			window.sessionStorage.setItem(TRANSITION_KEY, rawState);
		} catch (error) {
			return;
		}

		window.setTimeout(function() {
			try {
				if (window.sessionStorage.getItem(TRANSITION_KEY) === rawState) {
					window.sessionStorage.removeItem(TRANSITION_KEY);
				}
			} catch (error) {
				// The stored record is short-lived and still validated on use.
			}
		}, STATE_EXPIRY_MS);
	}

	function pickEffect() {
		if ('witch' !== lastEffectName && Math.random() < 0.125) {
			lastEffectName = 'witch';
			return 'witch';
		}

		var available = EFFECTS.filter(function(name) {
			return 'witch' !== name && name !== lastEffectName;
		});
		if (available.length === 0) {
			available = ['bat', 'pumpkin', 'ghost'];
		}
		var index = Math.floor(Math.random() * available.length);
		lastEffectName = available[index];
		return available[index];
	}

	function createSvgMarkup(type) {
		if ('bat' === type) {
			return '<svg viewBox="0 0 100 60" class="bat-wing" focusable="false" aria-hidden="true">' +
				'<path d="M50 20 C35 12 20 5 10 8 L4 14 L8 22 L4 30 L12 38 L6 45 L16 40 L22 34 L30 40 L38 32 L46 38 L54 32 L62 38 L58 45 L52 38 L44 44 L38 38 L30 44 L22 38 L16 44 L10 38 L14 30 L18 24 L25 18 L35 20 L42 22 L50 20 Z" fill="currentColor"/>' +
				'<path d="M50 24 L45 28 L55 28 Z" fill="currentColor"/>' +
				'</svg>';
		}
		if ('pumpkin' === type) {
			return '<svg viewBox="0 0 80 80" focusable="false" aria-hidden="true">' +
				'<defs>' +
				'<radialGradient id="nav-pumpkin-fill" cx="40%" cy="30%" r="70%">' +
				'<stop offset="0%" stop-color="var(--halloween-pumpkin-highlight, #ffbf69)"/>' +
				'<stop offset="50%" stop-color="var(--halloween-pumpkin-mid, #ed7929)"/>' +
				'<stop offset="100%" stop-color="var(--halloween-pumpkin-shadow, #8f341e)"/>' +
				'</radialGradient>' +
				'</defs>' +
				'<ellipse cx="40" cy="50" rx="24" ry="30" fill="url(#nav-pumpkin-fill)"/>' +
				'<path d="M40 30 L36 22 L44 22 Z" fill="var(--halloween-pumpkin-stem-light, #4d512d)"/>' +
				'<circle cx="30" cy="42" r="3" fill="var(--halloween-pumpkin-face-ink, #4b2519)"/>' +
				'<circle cx="50" cy="42" r="3" fill="var(--halloween-pumpkin-face-ink, #4b2519)"/>' +
				'<path d="M30 52 Q35 58 40 52 Q45 58 50 52" fill="none" stroke="var(--halloween-pumpkin-face-ink, #4b2519)" stroke-width="3" stroke-linecap="round"/>' +
				'</svg>';
		}
		if ('ghost' === type) {
			return '<svg viewBox="0 0 60 80" focusable="false" aria-hidden="true">' +
				'<path d="M30 8C18 8 10 18 10 31v32c0 3 3 4 5 2l6-6 7 8c1 2 3 2 4 0l7-8 6 6c2 2 5 1 5-2V31C50 18 42 8 30 8Z" fill="currentColor"/>' +
				'<circle cx="22" cy="28" r="2" fill="#3f3f3f"/>' +
				'<circle cx="38" cy="28" r="2" fill="#3f3f3f"/>' +
				'<path d="M24 37q6 6 12 0" fill="none" stroke="#3f3f3f" stroke-width="2" stroke-linecap="round"/>' +
				'</svg>';
		}
		return '';
	}

	function playEffect(effectName, centerX, centerY, elapsedMs, spontaneous) {
		if (effectInProgress || EFFECTS.indexOf(effectName) === -1 ||
			elapsedMs >= getAnimationDuration(effectName)) return;
		effectInProgress = true;
		activeEffectIsSpontaneous = !!spontaneous;
		lastEffectTime = getClock().value;
		var animationDuration = spontaneous
			? getSpontaneousAnimationDuration(effectName)
			: getAnimationDuration(effectName);

		var layer = document.createElement('div');
		layer.className = 'seasonal-halloween-effect-layer';
		layer.setAttribute('aria-hidden', 'true');
		activeLayer = layer;
		document.body.appendChild(layer);

		function cleanup(reschedule) {
			if (null !== activeAnimationTimer) {
				window.clearTimeout(activeAnimationTimer);
				activeAnimationTimer = null;
			}
			if (layer.parentNode) layer.parentNode.removeChild(layer);
			if (activeLayer === layer) {
				activeLayer = null;
				activeCleanup = null;
				activeEffectIsSpontaneous = false;
				effectInProgress = false;
			}
			if (false !== reschedule) scheduleSpontaneous();
		}
		activeCleanup = cleanup;

		function setProgress(element, duration) {
			element.style.animationDuration = duration + 'ms';
			element.style.animationDelay = '-' + elapsedMs + 'ms';
		}

		try {
			if ('bat' === effectName) {
				var bat = document.createElement('div');
				bat.className = 'seasonal-halloween-bat-nav flying' + (spontaneous ? ' spontaneous' : '');
				bat.innerHTML = createSvgMarkup('bat');
				if (spontaneous) {
					bat.style.left = '0px';
					bat.style.top = (centerY - 21) + 'px';
				}
				setProgress(bat, animationDuration);
				layer.appendChild(bat);
				for (var p = 0; p < (spontaneous ? 0 : 3); p++) {
					var particle = document.createElement('div');
					particle.className = 'seasonal-halloween-particle trailing';
					particle.style.width = '4px';
					particle.style.height = '4px';
					particle.style.left = (spontaneous ? centerX - window.innerWidth / 2 : centerX - 30 - p * 10) + 'px';
					particle.style.top = (spontaneous ? centerY - 8 : centerY - 10 - p * 8) + 'px';
					particle.style.setProperty('--px', '-' + (20 + p * 12) + 'px');
					particle.style.setProperty('--py', (10 + p * 8) + 'px');
					particle.style.animationDuration = '500ms';
					particle.style.animationDelay = '-' + elapsedMs + 'ms';
					layer.appendChild(particle);
				}
			} else if ('pumpkin' === effectName) {
				var pumpkin = document.createElement('div');
				pumpkin.className = 'seasonal-halloween-pumpkin-nav laughing' + (spontaneous ? ' spontaneous' : '');
				pumpkin.innerHTML = createSvgMarkup('pumpkin');
				var pumpkinSize = spontaneous ? 56 : 64;
				pumpkin.style.left = (centerX - pumpkinSize / 2) + 'px';
				pumpkin.style.top = (centerY - pumpkinSize / 2) + 'px';
				setProgress(pumpkin, animationDuration);
				layer.appendChild(pumpkin);
			} else if ('ghost' === effectName) {
				var ghost = document.createElement('div');
				ghost.className = 'seasonal-halloween-ghost-nav floating' + (spontaneous ? ' spontaneous' : '');
				ghost.innerHTML = createSvgMarkup('ghost');
				var ghostWidth = spontaneous ? 50 : 52;
				var ghostHeight = spontaneous ? 66 : 68;
				ghost.style.left = (centerX - ghostWidth / 2) + 'px';
				ghost.style.top = (centerY - ghostHeight / 2) + 'px';
				setProgress(ghost, animationDuration);
				layer.appendChild(ghost);
			} else if ('witch' === effectName) {
				var witchAsset = marker.getAttribute('data-witch-asset');
				if (!witchAsset) {
					cleanup();
					return;
				}

				var witch = document.createElement('div');
				witch.className = 'seasonal-halloween-witch-nav flying' + (spontaneous ? ' spontaneous' : '');
				var illustration = document.createElement('img');
				illustration.src = witchAsset;
				illustration.alt = '';
				illustration.setAttribute('aria-hidden', 'true');
				illustration.setAttribute('draggable', 'false');
				witch.appendChild(illustration);
				if (spontaneous) {
					var witchWidth = Math.min(148, Math.max(112, window.innerWidth * 0.36));
					witch.style.width = witchWidth + 'px';
					witch.style.top = (centerY - witchWidth * 230 / 420 / 2) + 'px';
				}
				for (var s = 0; s < 4; s++) {
					var sparkle = document.createElement('span');
					sparkle.className = 'seasonal-halloween-witch-sparkle';
					sparkle.style.left = (s * 5 - 8) + '%';
					sparkle.style.top = (s % 2 ? 56 : 30) + '%';
					sparkle.style.animationDelay = 'calc(-' + elapsedMs + 'ms + ' + (s * 150) + 'ms)';
					witch.appendChild(sparkle);
				}
				setProgress(witch, animationDuration);
				layer.appendChild(witch);
			}
			activeAnimationTimer = window.setTimeout(function() {
				activeAnimationTimer = null;
				cleanup();
			}, Math.max(0, animationDuration - elapsedMs));
			if (spontaneous) hasSpontaneousAppeared = true;
		} catch (err) {
			cleanup();
		}
	}

	function cancelActiveSpontaneous() {
		if (!activeEffectIsSpontaneous || !activeCleanup) return;
		activeCleanup(false);
	}

	function isExcluded(target) {
		return !!target.closest(EXCLUDED_SELECTORS);
	}

	function handleClick(event) {
		try {
			if (prefersReducedMotion()) return;
			if (event.button !== 0) return;
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

			var link = event.target.closest('a.nav-link');
			if (!link) return;
			if (isExcluded(link)) return;

			var href = link.getAttribute('href');
			if (!href) return;
			if (link.hasAttribute('download')) return;

			var targetName = link.getAttribute('target');
			if (targetName && '_self' !== targetName.toLowerCase()) return;

			try {
				var url = new URL(href, window.location.href);
				if (url.origin !== window.location.origin) return;
			} catch (err) {
				return;
			}

			var interruptedSpontaneous = activeEffectIsSpontaneous;
			if (interruptedSpontaneous) cancelActiveSpontaneous();
			if (effectInProgress) return;

			clearSpontaneousTimer();
			var now = getClock().value;
			if (!interruptedSpontaneous && now - lastEffectTime < COOLDOWN_MS) {
				scheduleSpontaneous();
				return;
			}

			var rect = link.getBoundingClientRect();
			var centerX = Math.max(0, Math.min(window.innerWidth, rect.left + rect.width / 2));
			var centerY = Math.max(0, Math.min(window.innerHeight, rect.top + rect.height / 2));
			var effectName = pickEffect();
			var clock = getClock();

			persistTransitionState(effectName, clock.value, clock.type, url.pathname, centerX, centerY);
			playEffect(effectName, centerX, centerY, 0);
		} catch (err) {
			// Never let the decorative system break navigation.
		}
	}

	var transition = consumeTransitionState();
	if (!marker || prefersReducedMotion()) {
		removeTransitionState();
		return;
	}

	if (transition && transition.elapsedMs < getAnimationDuration(transition.effect)) {
		playEffect(
			transition.effect,
			transition.x,
			transition.y,
			transition.elapsedMs
		);
	}

	document.addEventListener('click', handleClick, { passive: true });
	document.addEventListener('click', handleSpontaneousUiChange);
	document.addEventListener('visibilitychange', function() {
		if ('visible' === document.visibilityState) {
			scheduleSpontaneous();
		} else {
			stopSpontaneous();
		}
	});
	window.addEventListener('pagehide', stopSpontaneous);
	window.addEventListener('pageshow', function(event) {
		if (event.persisted) scheduleSpontaneous();
	});

	if (mobileViewport) {
		mobileViewport.addEventListener('change', function() {
			if (mobileViewport.matches) {
				scheduleSpontaneous();
			} else {
				stopSpontaneous();
			}
		});
	}

	if (window.matchMedia) {
		window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', function(event) {
			if (event.matches) {
				stopSpontaneous();
			} else {
				scheduleSpontaneous();
			}
		});
	}

	if (window.MutationObserver && marker.parentNode) {
		var markerObserver = new MutationObserver(function() {
			if (marker.isConnected) return;
			stopSpontaneous();
			markerObserver.disconnect();
		});
		markerObserver.observe(marker.parentNode, { childList: true });
	}

	scheduleSpontaneous();
}

})();
