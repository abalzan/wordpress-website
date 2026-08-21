/**
 * Conexão BR Irlanda - Main JavaScript
 * Premium Community Portal Interactive Features
 */
(function() {
	'use strict';

	// ===== DOM Ready =====
	document.addEventListener('DOMContentLoaded', function() {
		initThemeToggle();
		initMobileMenu();
		initMobileSearch();
		initCopyButtons();
		initLeisureFilters();
	});

	// ===== Theme Toggle =====
	function initThemeToggle() {
		const toggle = document.querySelector('.theme-toggle');
		if (!toggle) return;

		// Sync the aria-pressed state with the current theme.
		function syncToggleState() {
			const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
			toggle.setAttribute('aria-pressed', String(isDark));
		}

		syncToggleState();

		toggle.addEventListener('click', function() {
			const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
			const next = current === 'dark' ? 'light' : 'dark';

			document.documentElement.setAttribute('data-theme', next);

			try {
				localStorage.setItem('conexao-theme', next);
			} catch (e) {
				// localStorage unavailable — theme still applies for this session.
			}

			syncToggleState();
		});
	}

	// ===== Mobile Menu Toggle (Full-Screen) =====
	function initMobileMenu() {
		const menuToggle = document.querySelector('.mobile-menu-toggle');
		const menuOverlay = document.querySelector('.mobile-menu-overlay');
		const menuClose = document.querySelector('.mobile-menu-close');
		const body = document.body;

		if (!menuToggle || !menuOverlay) return;

		function openMenu() {
			menuOverlay.classList.add('active');
			menuOverlay.setAttribute('aria-hidden', 'false');
			menuToggle.setAttribute('aria-expanded', 'true');
			body.style.overflow = 'hidden';

			// Focus trap - focus the close button
			if (menuClose) {
				setTimeout(function() {
					menuClose.focus();
				}, 100);
			}
		}

		function closeMenu() {
			menuOverlay.classList.remove('active');
			menuOverlay.setAttribute('aria-hidden', 'true');
			menuToggle.setAttribute('aria-expanded', 'false');
			body.style.overflow = '';
			menuToggle.focus();
		}

		menuOverlay.addEventListener('click', function(e) {
			if (e.target === menuOverlay) closeMenu();
		});

		// Toggle menu
		menuToggle.addEventListener('click', function() {
			const isExpanded = this.getAttribute('aria-expanded') === 'true';
			if (isExpanded) {
				closeMenu();
			} else {
				openMenu();
			}
		});

		// Close button
		if (menuClose) {
			menuClose.addEventListener('click', closeMenu);
		}

		// Close on escape key
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && menuOverlay.classList.contains('active')) {
				closeMenu();
			}
		});

		// Focus trap for full-screen menu
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Tab' || !menuOverlay.classList.contains('active')) return;

			const focusableElements = menuOverlay.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);

			if (focusableElements.length === 0) return;

			const firstElement = focusableElements[0];
			const lastElement = focusableElements[focusableElements.length - 1];

			if (e.shiftKey && document.activeElement === firstElement) {
				e.preventDefault();
				lastElement.focus();
			} else if (!e.shiftKey && document.activeElement === lastElement) {
				e.preventDefault();
				firstElement.focus();
			}
		});

		// Handle submenu toggles in mobile menu
		const mobileDropdowns = document.querySelectorAll('.mobile-menu li.menu-item-has-children');
		mobileDropdowns.forEach(function(dropdown) {
			const link = dropdown.querySelector('a');
			const submenu = dropdown.querySelector('ul, .sub-menu');

			if (link && submenu) {
				const toggle = document.createElement('button');
				toggle.type = 'button';
				toggle.className = 'mobile-submenu-toggle';
				toggle.setAttribute('aria-label', 'Abrir submenu de ' + link.textContent.trim());
				toggle.setAttribute('aria-expanded', 'false');
				toggle.addEventListener('click', function() {
					const expanded = toggle.getAttribute('aria-expanded') === 'true';
					submenu.classList.toggle('active', !expanded);
					dropdown.classList.toggle('active', !expanded);
					toggle.setAttribute('aria-expanded', String(!expanded));
				});
				dropdown.insertBefore(toggle, submenu);
			}
		});
	}

	function initMobileSearch() {
		const toggle = document.querySelector('.mobile-search-toggle');
		const overlay = document.querySelector('.mobile-search-overlay');
		const close = document.querySelector('.mobile-search-close');
		const field = document.querySelector('.mobile-search-field');
		if (!toggle || !overlay) return;

		function closeSearch() {
			overlay.classList.remove('active');
			overlay.setAttribute('aria-hidden', 'true');
			toggle.setAttribute('aria-expanded', 'false');
			document.body.style.overflow = '';
			toggle.focus();
		}

		toggle.addEventListener('click', function() {
			overlay.classList.add('active');
			overlay.setAttribute('aria-hidden', 'false');
			toggle.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
			setTimeout(function() { if (field) field.focus(); }, 100);
		});
		if (close) close.addEventListener('click', closeSearch);
		overlay.addEventListener('click', function(e) { if (e.target === overlay) closeSearch(); });
		document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && overlay.classList.contains('active')) closeSearch(); });
	}

	// ===== Copy Link Buttons =====
	function initCopyButtons() {
		// Theme share buttons (data-copy-url)
		const copyButtons = document.querySelectorAll('.share-copy');
		copyButtons.forEach(function(button) {
			button.addEventListener('click', function() {
				const url = this.getAttribute('data-copy-url');
				copyToClipboard(url, button);
			});
		});

		// Plugin share buttons (data-copy-link)
		const pluginCopyButtons = document.querySelectorAll('[data-copy-link]');
		pluginCopyButtons.forEach(function(button) {
			button.addEventListener('click', function() {
				const url = this.getAttribute('data-copy-link');
				const originalText = this.textContent;
				copyToClipboard(url, button, originalText);
			});
		});
	}

	function copyToClipboard(url, button, originalText) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(function() {
				showCopySuccess(button, originalText);
			}).catch(function() {
				fallbackCopy(url, button, originalText);
			});
		} else {
			fallbackCopy(url, button, originalText);
		}
	}

	function fallbackCopy(text, button, originalText) {
		const textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild(textarea);
		textarea.select();

		try {
			document.execCommand('copy');
			showCopySuccess(button, originalText);
		} catch (err) {
			console.error('Copy failed:', err);
		}

		document.body.removeChild(textarea);
	}

	function showCopySuccess(button, originalText) {
		// Plugin buttons use text content, theme buttons use innerHTML (SVG icons)
		if (originalText !== undefined) {
			button.textContent = 'Link copiado';
			setTimeout(function() {
				button.textContent = originalText;
			}, 2000);
			return;
		}

		const originalHTML = button.innerHTML;
		button.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
		button.style.background = '#0E6B3A';

		setTimeout(function() {
			button.innerHTML = originalHTML;
			button.style.background = '';
		}, 2000);
	}

	// ===== Leisure Filters (desktop dropdowns + mobile bottom sheet) =====
	// The filtering stays server-side and URL driven: desktop options are real
	// hyperlinks and the mobile options are a native GET form that posts the
	// selected county/category back to /lazer/. This enhancement wires up the
	// interaction layer:
	//   - only one desktop dropdown is open at a time,
	//   - clicking the trigger toggles its own menu,
	//   - clicking outside or pressing Escape closes open menus / the sheet,
	//   - the mobile sheet is a fixed overlay with a focusable close control,
	//   - empty "Todos" radio values are stripped so URLs stay clean (e.g.
	//     /lazer/?categoria=castelos instead of /lazer/?county=&categoria=castelos).
	function initLeisureFilters() {
		const root = document.querySelector('[data-leisure-filters]');
		if (!root) return;

		const dropdowns = Array.prototype.slice.call(root.querySelectorAll('[data-dropdown]'));

		function setDropdown(dropdown, open) {
			const trigger = dropdown.querySelector('[data-dropdown-trigger]');
			if (trigger) {
				trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
			}
		}

		function closeAllDropdowns(except) {
			dropdowns.forEach(function(dd) {
				if (dd === except) return;
				setDropdown(dd, false);
			});
		}

		dropdowns.forEach(function(dropdown) {
			const trigger = dropdown.querySelector('[data-dropdown-trigger]');
			if (!trigger) return;

			trigger.addEventListener('click', function(e) {
				e.stopPropagation();
				const wasOpen = trigger.getAttribute('aria-expanded') === 'true';
				closeAllDropdowns(dropdown);
				setDropdown(dropdown, !wasOpen);
			});
		});

		// Click outside any dropdown closes every open menu.
		document.addEventListener('click', function(e) {
			if (!e.target.closest('[data-dropdown]')) {
				closeAllDropdowns();
			}
		});

		// Mobile bottom sheet.
		const sheetTrigger = root.querySelector('[data-mobile-trigger]');
		const sheetOverlay = root.querySelector('[data-mobile-sheet]');
		const sheetClose = root.querySelector('[data-mobile-close]');
		const sheetPanel = sheetOverlay ? sheetOverlay.querySelector('.leisure-mobile-sheet-panel') : null;

		function openMobileSheet() {
			if (!sheetOverlay) return;
			sheetOverlay.classList.add('is-open');
			sheetOverlay.setAttribute('aria-hidden', 'false');
			if (sheetTrigger) sheetTrigger.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
			if (sheetClose) {
				setTimeout(function() { sheetClose.focus(); }, 50);
			}
		}

		function closeMobileSheet(restoreFocus) {
			if (!sheetOverlay) return;
			sheetOverlay.classList.remove('is-open');
			sheetOverlay.setAttribute('aria-hidden', 'true');
			if (sheetTrigger) sheetTrigger.setAttribute('aria-expanded', 'false');
			document.body.style.overflow = '';
			if (restoreFocus && sheetTrigger) {
				sheetTrigger.focus();
			}
		}

		if (sheetTrigger && sheetOverlay) {
			sheetTrigger.addEventListener('click', function() {
				const isOpen = sheetOverlay.classList.contains('is-open');
				if (isOpen) {
					closeMobileSheet();
				} else {
					openMobileSheet();
				}
			});

			if (sheetClose) {
				sheetClose.addEventListener('click', function() {
					closeMobileSheet(true);
				});
			}

			// Clicking the dark backdrop (outside the sheet panel) closes it.
			sheetOverlay.addEventListener('click', function(e) {
				if (e.target === sheetOverlay || (sheetPanel && !sheetPanel.contains(e.target))) {
					closeMobileSheet(true);
				}
			});
		}

		// Escape closes any open dropdown and the mobile sheet.
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Escape') return;
			closeAllDropdowns();
			closeMobileSheet(true);
		});

		// Mobile form: strip empty params and navigate to a clean URL.
		const form = root.querySelector('[data-mobile-form]');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();

				var url = new URL(form.getAttribute('action'), window.location.origin);
				var params = new URLSearchParams(new FormData(form));

				params.forEach(function(value, key) {
					if (value === '' || value === null) {
						params.delete(key);
					}
				});

				var qs = params.toString();
				window.location.href = url.pathname + (qs ? '?' + qs : '');
			});
		}
	}

	// ===== Expose functions globally if needed =====
	window.ConexaoPortal = {
		initMobileMenu: initMobileMenu
	};

})();