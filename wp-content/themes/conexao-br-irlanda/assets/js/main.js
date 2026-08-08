/**
 * Conexão BR Irlanda - Main JavaScript
 * Premium Community Portal Interactive Features
 */
(function() {
	'use strict';

	// ===== DOM Ready =====
	document.addEventListener('DOMContentLoaded', function() {
		initMobileMenu();
		initMobileSearch();
		initLanguageSelector();
		initSmoothScroll();
		initCopyButtons();
		initLazyLoad();
	});

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

	// ===== Language Selector =====
	function initLanguageSelector() {
		const langButtons = document.querySelectorAll('.language-selector button, .mobile-menu-lang button');

		langButtons.forEach(function(button) {
			button.addEventListener('click', function() {
				const lang = this.getAttribute('data-lang');

				// Update active state for all language buttons
				langButtons.forEach(function(btn) {
					btn.classList.remove('active');
				});

				// Set active for clicked button and its pair
				document.querySelectorAll('[data-lang="' + lang + '"]').forEach(function(btn) {
					btn.classList.add('active');
				});

				// Store language preference
				localStorage.setItem('conexao_lang', lang);

				// Trigger custom event for other scripts
				window.dispatchEvent(new CustomEvent('languageChanged', {
					detail: { lang: lang }
				}));
			});
		});

		// Restore language preference
		const savedLang = localStorage.getItem('conexao_lang');
		if (savedLang) {
			document.querySelectorAll('[data-lang="' + savedLang + '"]').forEach(function(btn) {
				btn.classList.add('active');
			});
			document.querySelectorAll('.language-selector button:not([data-lang="' + savedLang + '"]), .mobile-menu-lang button:not([data-lang="' + savedLang + '"])').forEach(function(btn) {
				btn.classList.remove('active');
			});
		}
	}

	// ===== Smooth Scroll for Anchor Links =====
	function initSmoothScroll() {
		document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
			anchor.addEventListener('click', function(e) {
				const href = this.getAttribute('href');

				// Skip if it's just "#"
				if (href === '#') return;

				const target = document.querySelector(href);

				if (target) {
					e.preventDefault();

					const headerHeight = document.querySelector('.site-header')?.offsetHeight || 0;
					const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;

					window.scrollTo({
						top: targetPosition,
						behavior: 'smooth'
					});

					// Update URL without scrolling
					history.pushState(null, null, href);
				}
			});
		});
	}

	// ===== Copy Link Buttons =====
	function initCopyButtons() {
		const copyButtons = document.querySelectorAll('.share-copy');

		copyButtons.forEach(function(button) {
			button.addEventListener('click', function() {
				const url = this.getAttribute('data-copy-url');

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(url).then(function() {
						showCopySuccess(button);
					}).catch(function() {
						fallbackCopy(url, button);
					});
				} else {
					fallbackCopy(url, button);
				}
			});
		});
	}

	function fallbackCopy(text, button) {
		const textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild(textarea);
		textarea.select();

		try {
			document.execCommand('copy');
			showCopySuccess(button);
		} catch (err) {
			console.error('Copy failed:', err);
		}

		document.body.removeChild(textarea);
	}

	function showCopySuccess(button) {
		const originalHTML = button.innerHTML;
		button.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
		button.style.background = '#0E6B3A';

		setTimeout(function() {
			button.innerHTML = originalHTML;
			button.style.background = '';
		}, 2000);
	}

	// ===== Lazy Load Images =====
	function initLazyLoad() {
		if ('IntersectionObserver' in window) {
			const imageObserver = new IntersectionObserver(function(entries, observer) {
				entries.forEach(function(entry) {
					if (entry.isIntersecting) {
						const img = entry.target;

						// Load the image
						if (img.dataset.src) {
							img.src = img.dataset.src;
						}

						img.classList.add('loaded');
						observer.unobserve(img);
					}
				});
			}, {
				rootMargin: '50px 0px',
				threshold: 0.01
			});

			document.querySelectorAll('img[loading="lazy"]').forEach(function(img) {
				imageObserver.observe(img);
			});
		} else {
			// Fallback for browsers that don't support IntersectionObserver
			document.querySelectorAll('img[loading="lazy"]').forEach(function(img) {
				if (img.dataset.src) {
					img.src = img.dataset.src;
				}
				img.classList.add('loaded');
			});
		}
	}

	// ===== Expose functions globally if needed =====
	window.ConexaoPortal = {
		initMobileMenu: initMobileMenu,
		initLanguageSelector: initLanguageSelector
	};

})();
