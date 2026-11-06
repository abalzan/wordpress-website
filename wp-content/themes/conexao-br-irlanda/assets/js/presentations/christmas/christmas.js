/**
 * Christmas experience package — package behaviour.
 *
 * Calm energy (r10 §7.7 addendum): the ambient layer shows ONE bauble at a time,
 * 60–120 s apart, for 2–4 s, capped at 3 per browser session. All of that —
 * the single timer, the randomised interval, the cap, the visibility pause and
 * the node cleanup — lives in the shared framework
 * (`assets/js/commemorative.js`); this file only supplies the decoration factory,
 * so a second scheduler can never appear.
 *
 * The hero and the landing page need no JavaScript at all.
 *
 * @package Conexao_BR_Irlanda
 */

(function () {
	'use strict';

	function baubleAsset() {
		var marker = document.querySelector('.ce-christmas-ambient-marker');

		return marker ? marker.getAttribute('data-ce-bauble-asset') : '';
	}

	function createBauble() {
		var asset = baubleAsset();

		if (!asset) {
			return null;
		}

		var node = document.createElement('span');
		var image = document.createElement('img');

		image.src = asset;
		image.alt = '';
		image.setAttribute('aria-hidden', 'true');
		image.setAttribute('draggable', 'false');
		image.width = 72;
		image.height = 90;
		node.appendChild(image);

		// Bounded, non-obstructive placement: near an edge, never centred, never
		// over the header or a fixed control (r10 §7.7).
		var edge = Math.random() < 0.5 ? 'left' : 'right';
		var top = 12 + Math.random() * 58;

		node.style[edge] = (2 + Math.random() * 6).toFixed(2) + '%';
		node.style.top = top.toFixed(2) + '%';
		node.style.width = (48 + Math.random() * 32).toFixed(0) + 'px';

		return node;
	}

	function init() {
		var shared = window.ConexaoCommemorative;

		if (!shared || typeof shared.bootAmbientPlacement !== 'function') {
			return;
		}

		shared.bootAmbientPlacement({
			packageSlug: 'christmas',
			decorate: createBauble
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
