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
		initSponsorsCarousel();
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
			// Each visit starts with the filter accordions collapsed.
			closeAllAccordions();
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

		// Mobile filter accordions: only one open at a time. Selecting an
		// option closes the accordion, previews the chosen value in the
		// collapsed header (or clears it for "Todos"), and moves focus to the
		// next accordion summary (or the apply button) so keyboard users can
		// continue refining. Actual server-side filtering still happens on
		// form submit — the URL-driven architecture is unchanged.
		const accordions = Array.prototype.slice.call(root.querySelectorAll('[data-filter-accordion]'));

		function syncAccordionCaret(accordion) {
			const summary = accordion.querySelector('.leisure-filter-accordion-summary');
			if (summary) {
				summary.setAttribute('aria-expanded', accordion.open ? 'true' : 'false');
			}
		}

		function closeAllAccordions() {
			accordions.forEach(function(acc) {
				if (acc.open) {
					acc.open = false;
				}
			});
		}

		function closeOtherAccordions(except) {
			accordions.forEach(function(acc) {
				if (acc !== except && acc.open) {
					acc.open = false;
				}
			});
		}

		function updateAccordionValue(accordion, radio) {
			const valueEl = accordion.querySelector('.leisure-accordion-value');
			if (!valueEl) return;
			const label = radio.closest('.leisure-filter-option');
			const text = label ? label.querySelector('span').textContent.trim() : '';
			if (!radio.value) {
				// "Todos" selected — return the header to its neutral state.
				valueEl.textContent = '';
				valueEl.classList.remove('is-visible');
			} else {
				valueEl.textContent = ' · ' + text;
				valueEl.classList.add('is-visible');
			}
		}

		function focusAfterSelection(accordion) {
			const index = accordions.indexOf(accordion);
			if (index === -1) return;
			const next = accordions[index + 1];
			if (next) {
				const nextSummary = next.querySelector('.leisure-filter-accordion-summary');
				if (nextSummary) nextSummary.focus();
				return;
			}
			const apply = root.querySelector('.leisure-apply-button');
			if (apply) apply.focus();
		}

		accordions.forEach(function(accordion) {
			// Keep the summary aria-expanded in sync with the details state.
			syncAccordionCaret(accordion);

			accordion.addEventListener('toggle', function() {
				syncAccordionCaret(accordion);
				if (accordion.open) {
					closeOtherAccordions(accordion);
				}
			});

			// Selecting an option closes the accordion immediately and shows
			// the chosen value in the collapsed header.
			const radios = accordion.querySelectorAll('.leisure-filter-radio');
			radios.forEach(function(radio) {
				radio.addEventListener('change', function() {
					updateAccordionValue(accordion, radio);
					accordion.open = false;
					syncAccordionCaret(accordion);
					focusAfterSelection(accordion);
				});
			});
		});

		// The mobile "Limpar" link navigates to the unfiltered archive; also
		// collapse any open accordion so the sheet closes cleanly before the
		// server-rendered state takes over.
		const mobileClear = root.querySelector('.leisure-mobile-clear');
		if (mobileClear) {
			mobileClear.addEventListener('click', function() {
				closeAllAccordions();
			});
		}

		// Escape closes an open accordion first; otherwise it closes the sheet.
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Escape') return;
			closeAllDropdowns();
			const anyOpen = accordions.some(function(acc) { return acc.open; });
			if (anyOpen) {
				closeAllAccordions();
				return;
			}
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

	// ===== Sponsors Carousel (homepage Apoiadores) =====
	// Lightweight, dependency-free enhancement over a native scroll-snap
	// track. Which supporters appear — and in what order — is decided entirely
	// in PHP from the existing sponsor fields ("Apoiador em destaque" and
	// "Ordem de exibição"); this script only adds navigation and autoplay:
	//   - prev/next buttons that step one card at a time,
	//   - pagination dots (Hero variant) that jump straight to a slide,
	//   - keyboard support on the scrollable track (arrows, Home, End),
	//   - wrap-around at both ends so controls never dead-end,
	//   - a polite live region announcing the current position,
	//   - 2-second autoplay on the Hero variant ONLY: a single setTimeout
	//     chain per carousel (never setInterval), paused while the user
	//     hovers/focuses/presses the component or the tab is hidden,
	//     restarted with a full fresh interval after any manual navigation
	//     or swipe, and disabled entirely under prefers-reduced-motion.
	// When every card fits the viewport (few supporters or wide screens) the
	// carousel adds .is-static, hides its arrows and centers the row instead —
	// a simple responsive layout rather than a pointless carousel. Mobile
	// relies on natural touch swipe plus the labelled arrow row kept by the
	// hero variant.
	var SPONSORS_AUTOPLAY_INTERVAL = 2000;

	// Checked live (not cached once at load) so changing the OS setting
	// mid-session is respected at the next scheduling decision.
	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function initSponsorsCarousel() {
		var carousels = document.querySelectorAll('[data-sponsors-carousel]');
		if (!carousels.length) return;

		Array.prototype.forEach.call(carousels, function(carousel) {
			var viewport = carousel.querySelector('.sponsors-carousel-viewport');
			var prevBtn  = carousel.querySelector('[data-sponsors-prev]');
			var nextBtn  = carousel.querySelector('[data-sponsors-next]');
			var status   = carousel.querySelector('[data-sponsors-status]');
			var slides   = viewport ? Array.prototype.slice.call(viewport.querySelectorAll('.sponsors-slide')) : [];
			var dots     = Array.prototype.slice.call(carousel.querySelectorAll('[data-sponsors-dot]'));

			if (!viewport || slides.length === 0) return;

			// --- Autoplay state ---------------------------------------------
			// Exactly ONE pending timeout per carousel: the timer id doubles
			// as the "armed" flag (null = stopped), and every re-arm clears
			// the previous timer first, so duplicate timers are impossible.
			var autoplayTimer = null;
			// Independent pause reasons — the cycle stays off while ANY is set.
			var hoverPaused = false; // pointer rests over the component
			var focusPaused = false; // any descendant owns focus
			var pressPaused = false; // pointer/touch pressed on the component
			// True while a programmatic scrollTo animates, so the scroll
			// listener can tell autoplay movement apart from user swipes.
			var autoScrolling = false;
			var autoScrollResetTimer = null;

			// Distance between consecutive slide starts (card width + gap).
			// Falls back to the first card's width for single-card tracks.
			function stepSize() {
				if (slides.length > 1) {
					var delta = slides[1].offsetLeft - slides[0].offsetLeft;
					if (delta > 0) return delta;
				}
				return slides[0].offsetWidth;
			}

			function maxScroll() {
				return viewport.scrollWidth - viewport.clientWidth;
			}

			function currentIndex() {
				var step = stepSize();
				if (step <= 0) return 0;
				return Math.min(slides.length - 1, Math.max(0, Math.round(viewport.scrollLeft / step)));
			}

			// Keep the pagination dots in sync with the visible slide: the
			// active dot gets .is-active + aria-current, the rest reset.
			// Runs wherever the position is re-evaluated (scroll, sync).
			function updateDots() {
				if (!dots.length) return;
				var active = currentIndex();
				Array.prototype.forEach.call(dots, function(dot, dotIndex) {
					var isActive = dotIndex === active;
					dot.classList.toggle('is-active', isActive);
					if (isActive) {
						dot.setAttribute('aria-current', 'true');
					} else {
						dot.removeAttribute('aria-current');
					}
				});
			}

			function announce() {
				updateDots();
				if (!status) return;
				if (maxScroll() <= 2) {
					status.textContent = '';
					return;
				}
				status.textContent = 'Apoiador ' + (currentIndex() + 1) + ' de ' + slides.length;
			}

			function scrollToIndex(index) {
				// Flag programmatic movement so the scroll listener below does
				// not mistake this animation for a user swipe; the flag clears
				// once the smooth scroll has had time to settle.
				autoScrolling = true;
				clearTimeout(autoScrollResetTimer);
				autoScrollResetTimer = setTimeout(function() {
					autoScrolling = false;
				}, prefersReducedMotion() ? 80 : 700);
				viewport.scrollTo({
					left: index * stepSize(),
					behavior: prefersReducedMotion() ? 'auto' : 'smooth'
				});
			}

			function goNext() {
				if (maxScroll() <= 2) return;
				var index = currentIndex();
				scrollToIndex(index >= slides.length - 1 ? 0 : index + 1);
			}

			function goPrev() {
				if (maxScroll() <= 2) return;
				var index = currentIndex();
				scrollToIndex(index <= 0 ? slides.length - 1 : index - 1);
			}

			// --- Autoplay control --------------------------------------------
			// The cycle applies to the Hero variant only, needs at least two
			// slides plus actual overflow, and never runs for users who asked
			// for reduced motion (manual navigation keeps working there).
			function autoplayEligible() {
				return carousel.classList.contains('sponsors-carousel--hero') &&
					slides.length > 1 &&
					maxScroll() > 2 &&
					!prefersReducedMotion();
			}

			// Single entry point for arming / re-arming / pausing the cycle:
			// it always clears any pending timer before deciding, so repeated
			// calls can never stack timers or speed the carousel up.
			function scheduleAutoplay() {
				if (autoplayTimer !== null) {
					clearTimeout(autoplayTimer);
					autoplayTimer = null;
				}
				if (!autoplayEligible() || hoverPaused || focusPaused || pressPaused || document.hidden) {
					return;
				}
				autoplayTimer = setTimeout(function() {
					autoplayTimer = null;
					// If the component left the DOM meanwhile, stop cycling.
					if (!carousel.isConnected) return;
					goNext();
					scheduleAutoplay();
				}, SPONSORS_AUTOPLAY_INTERVAL);
			}

			// Manual navigation moves first, THEN restarts the full 2-second
			// countdown — an arrow click never causes an immediate follow-up
			// advance; the next automatic step comes a full interval later.
			function goNextManual() {
				goNext();
				scheduleAutoplay();
			}

			function goPrevManual() {
				goPrev();
				scheduleAutoplay();
			}

			// Adaptive layout: when everything fits without scrolling, mark
			// the carousel static (CSS hides the arrows and centers the row).
			function sync() {
				carousel.classList.toggle('is-static', maxScroll() <= 2);
				announce();
				// Geometry changed — re-decide whether a cycle should run.
				scheduleAutoplay();
			}

			if (prevBtn) prevBtn.addEventListener('click', goPrevManual);
			if (nextBtn) nextBtn.addEventListener('click', goNextManual);

			// Pagination dots (Hero variant): jump straight to the matching
			// slide, then restart the full 2-second countdown - the same
			// manual-navigation contract as the arrow buttons (no immediate
			// follow-up advance).
			Array.prototype.forEach.call(dots, function(dot, dotIndex) {
				dot.addEventListener('click', function() {
					scrollToIndex(dotIndex);
					scheduleAutoplay();
				});
			});

			// Keyboard support on the scrollable track itself.
			viewport.addEventListener('keydown', function(e) {
				if (e.key === 'ArrowRight') { e.preventDefault(); goNextManual(); }
				else if (e.key === 'ArrowLeft') { e.preventDefault(); goPrevManual(); }
				else if (e.key === 'Home') { e.preventDefault(); scrollToIndex(0); scheduleAutoplay(); }
				else if (e.key === 'End') { e.preventDefault(); scrollToIndex(slides.length - 1); scheduleAutoplay(); }
			});

			// rAF-throttled scroll updates keep the live region in sync with
			// swipes and drags without flooding assistive tech. User-driven
			// scrolling also restarts the 2-second countdown; autoplay's own
			// animated scrolls are excluded via the autoScrolling flag.
			var ticking = false;
			viewport.addEventListener('scroll', function() {
				if (ticking) return;
				ticking = true;
				window.requestAnimationFrame(function() {
					ticking = false;
					announce();
					if (!autoScrolling) scheduleAutoplay();
				});
			});

			// Pause the cycle whenever the user engages with the component —
			// hovering it, focusing anything inside it, or pressing/touching
			// it — and resume from a fresh full interval once engagement ends.
			// Hover pause is attached only where real hover exists: on pure
			// touch devices mouseenter fires on tap but mouseleave may never
			// fire afterwards, which would pause autoplay forever.
			var canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
			if (canHover) {
				carousel.addEventListener('mouseenter', function() {
					hoverPaused = true;
					scheduleAutoplay();
				});
				carousel.addEventListener('mouseleave', function() {
					hoverPaused = false;
					scheduleAutoplay();
				});
			}
			carousel.addEventListener('focusin', function() {
				focusPaused = true;
				scheduleAutoplay();
			});
			carousel.addEventListener('focusout', function() {
				// Wait a tick so the next focus target is known before
				// deciding whether focus truly left the component.
				window.setTimeout(function() {
					focusPaused = carousel.contains(document.activeElement);
					scheduleAutoplay();
				}, 0);
			});
			carousel.addEventListener('pointerdown', function() {
				pressPaused = true;
				scheduleAutoplay();
			});
			carousel.addEventListener('pointerup', function() {
				pressPaused = false;
				scheduleAutoplay();
			});
			carousel.addEventListener('pointercancel', function() {
				pressPaused = false;
				scheduleAutoplay();
			});

			// Background tabs must not burn through the cycle unobserved;
			// returning to the tab resumes from a fresh full interval.
			document.addEventListener('visibilitychange', scheduleAutoplay);

			// Respect live changes of the OS reduced-motion preference.
			if (window.matchMedia) {
				var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
				var onMotionPreferenceChange = function() { scheduleAutoplay(); };
				if (motionQuery.addEventListener) {
					motionQuery.addEventListener('change', onMotionPreferenceChange);
				} else if (motionQuery.addListener) {
					motionQuery.addListener(onMotionPreferenceChange); // Safari < 14
				}
			}

			// Breakpoint changes alter how many cards fit — re-evaluate.
			var resizeTimer = null;
			window.addEventListener('resize', function() {
				clearTimeout(resizeTimer);
				resizeTimer = setTimeout(sync, 150);
			});

			// Initial layout pass also arms the first interval — the first
			// sponsor stays visible for the full 2 seconds before advancing.
			sync();
		});
	}

	// ===== Expose functions globally if needed =====
	window.ConexaoPortal = {
		initMobileMenu: initMobileMenu
	};

})();