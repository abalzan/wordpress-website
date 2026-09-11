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
		initLeisureInstantFilters();
		initAgencyFilters();
		initEventFilters();
		initSponsorsCarousel();
		initInfiniteScroll();
		initLoadMore();
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
	// hyperlinks and the mobile options are radio inputs bound to the same
	// ?county=/?categoria= parameters. This enhancement wires up the
	// interaction layer:
	//   - only one desktop dropdown is open at a time,
	//   - clicking the trigger toggles its own menu,
	//   - clicking outside or pressing Escape closes open menus / the sheet
	//     (Escape returns focus to the trigger of the dropdown it closed),
	//   - the Localização popover has a client-side search field that filters
	//     the already server-rendered options (no extra request, no change to
	//     the filter values) — Escape inside it first clears the query, then
	//     a second Escape closes the menu,
	//   - the mobile sheet is a fixed overlay with a focusable close control,
	//   - on mobile, changing the single-select Localização radio applies the
	//     filter immediately (initLeisureInstantFilters below) — the sheet
	//     auto-closes so the user lands on the results; the multi-select
	//     Tipo/Características checkbox groups accumulate selections while
	//     the sheet stays open and are submitted together by "Mostrar
	//     resultados" (each section's "Todos"/"Todas" checkbox is a reset
	//     action that clears only its own section),
	//   - empty "Todos" radio values are stripped so URLs stay clean (e.g.
	//     /lazer/?categoria=castelos instead of /lazer/?county=&categoria=castelos).

	// Desktop dropdown state and helpers live at module scope (not inside
	// initLeisureFilters) so the instant-filter module can rebuild the
	// dropdown bindings after swapping the toolbar markup in place.
	var leisureDropdowns = [];

	function normalize(text) {
		return (text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	}

	function getTrigger(dropdown) {
		return dropdown.querySelector('[data-dropdown-trigger]');
	}

	function isOpen(dropdown) {
		const trigger = getTrigger(dropdown);
		return !!trigger && trigger.getAttribute('aria-expanded') === 'true';
	}

	function setDropdown(dropdown, open) {
		const trigger = getTrigger(dropdown);
		if (trigger) {
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
		// Focus the search field when a searchable menu opens so the user
		// can type immediately; the query is kept (not reset) so reopening
		// preserves the filtering in progress.
		const search = dropdown.querySelector('[data-dropdown-search]');
		if (open && search) {
			search.focus();
			search.select();
		}
	}

	function closeAllDropdowns(except) {
		leisureDropdowns.forEach(function(dd) {
			if (dd === except) return;
			setDropdown(dd, false);
		});
	}

	function bindLeisureDropdowns(root) {
		leisureDropdowns = Array.prototype.slice.call(root.querySelectorAll('[data-dropdown]'));

		leisureDropdowns.forEach(function(dropdown) {
			const trigger = getTrigger(dropdown);
			if (!trigger) return;

			trigger.addEventListener('click', function(e) {
				e.stopPropagation();
				const wasOpen = isOpen(dropdown);
				closeAllDropdowns(dropdown);
				setDropdown(dropdown, !wasOpen);
				// Return focus to the trigger when a menu closes via toggle so
				// keyboard users never lose their place.
				if (wasOpen) trigger.focus();
			});
		});

		// Client-side search inside a dropdown panel: filters the options that
		// were already rendered by PHP (accent/case-insensitive match on the
		// visible label). Purely cosmetic filtering — the underlying links and
		// their URLs are untouched, so with JS disabled every option remains
		// a working hyperlink.
		leisureDropdowns.forEach(function(dropdown) {
			const search = dropdown.querySelector('[data-dropdown-search]');
			if (!search) return;

			const options = Array.prototype.slice.call(dropdown.querySelectorAll('.leisure-dropdown-link'));
			const empty = dropdown.querySelector('[data-dropdown-empty]');
			const list = dropdown.querySelector('.leisure-dropdown-list');

			function applyFilter() {
				const query = normalize(search.value.trim());
				let visible = 0;
				options.forEach(function(option) {
					const match = !query || normalize(option.textContent).indexOf(query) !== -1;
					option.hidden = !match;
					if (match) visible++;
				});
				if (empty) {
					empty.hidden = visible > 0;
				}
				if (list) {
					list.hidden = visible === 0;
				}
			}

			search.addEventListener('input', applyFilter);

			// Escape inside the search first clears the query (restoring every
			// option), and only closes the menu on a second press.
			search.addEventListener('keydown', function(e) {
				if (e.key !== 'Escape') return;
				if (search.value !== '') {
					e.stopPropagation();
					search.value = '';
					applyFilter();
				}
			});
		});
	}

	// ===== Mobile filter bottom sheet helpers (shared) =====
	// Single open/close path for the sheet, shared by the sheet bindings in
	// initLeisureFilters() and the instant-filter pipeline in
	// initLeisureInstantFilters() — the latter dismisses the panel when any
	// filter is chosen, and must use exactly the same close steps (class,
	// aria state, body scroll, focus return) as the explicit close paths so
	// the two behaviors never drift apart. The nodes are looked up at call
	// time because the instant pipeline swaps the results markup around the
	// sheet (the sheet itself is never part of the swap), so these helpers
	// are safe to call from either module. closeMobileSheet(true) restores
	// focus to the mobile "Filtrar" trigger, so a keyboard user is never
	// left inside the now-hidden panel.
	function leisureSheetNodes() {
		const root = document.querySelector('[data-leisure-filters]');
		if (!root) return null;
		return {
			trigger: root.querySelector('[data-mobile-trigger]'),
			overlay: root.querySelector('[data-mobile-sheet]'),
			close: root.querySelector('[data-mobile-close]')
		};
	}

	function openMobileSheet() {
		const sheet = leisureSheetNodes();
		if (!sheet || !sheet.overlay) return;
		sheet.overlay.classList.add('is-open');
		sheet.overlay.setAttribute('aria-hidden', 'false');
		if (sheet.trigger) sheet.trigger.setAttribute('aria-expanded', 'true');
		document.body.style.overflow = 'hidden';
		// NOTE: the radio state is server-rendered from the URL and is
		// deliberately never reset here — reopening the sheet must show
		// the current selections.
		if (sheet.close) {
			setTimeout(function() {
				// The sheet may already be closed before this fires (the
				// instant filter pipeline closes it on any selection;
				// the user may also tap Fechar/the backdrop). Never move
				// focus into a hidden element.
				if (sheet.overlay.classList.contains('is-open')) {
					sheet.close.focus();
				}
			}, 50);
		}
	}

	function closeMobileSheet(restoreFocus) {
		const sheet = leisureSheetNodes();
		if (!sheet || !sheet.overlay || !sheet.overlay.classList.contains('is-open')) return;
		sheet.overlay.classList.remove('is-open');
		sheet.overlay.setAttribute('aria-hidden', 'true');
		if (sheet.trigger) sheet.trigger.setAttribute('aria-expanded', 'false');
		document.body.style.overflow = '';
		if (restoreFocus && sheet.trigger) {
			sheet.trigger.focus();
		}
	}

	function initLeisureFilters() {
		const root = document.querySelector('[data-leisure-filters]');
		if (!root) return;

		bindLeisureDropdowns(root);

		// Click outside any dropdown closes every open menu.
		document.addEventListener('click', function(e) {
			if (!e.target.closest('[data-dropdown]')) {
				closeAllDropdowns();
			}
		});

		// Mobile sheet option search (Localização): the same client-side,
		// accent/case-insensitive filtering as the desktop popover, applied to
		// the radio labels. The field is only rendered for long lists (decided
		// in PHP), so a short county list stays free of unnecessary search UI.
		// Escape first clears the query; a second press falls through to the
		// sheet's own Escape handling and closes it.
		Array.prototype.forEach.call(root.querySelectorAll('[data-option-search]'), function(search) {
			const section = search.closest('.leisure-mobile-section');
			if (!section) return;

			const options = Array.prototype.slice.call(section.querySelectorAll('.leisure-filter-option'));
			const empty = section.querySelector('[data-option-empty]');
			const list = section.querySelector('[data-option-list]');

			function applySearch() {
				const query = normalize(search.value.trim());
				let visible = 0;
				options.forEach(function(option) {
					const match = !query || normalize(option.textContent).indexOf(query) !== -1;
					option.hidden = !match;
					if (match) visible++;
				});
				if (empty) empty.hidden = visible > 0;
				if (list) list.hidden = visible === 0;
			}

			search.addEventListener('input', applySearch);

			search.addEventListener('keydown', function(e) {
				if (e.key !== 'Escape') return;
				if (search.value !== '') {
					e.stopPropagation();
					search.value = '';
					applySearch();
				}
			});
		});

		// Mobile bottom sheet.
		const sheetTrigger = root.querySelector('[data-mobile-trigger]');
		const sheetOverlay = root.querySelector('[data-mobile-sheet]');
		const sheetClose = root.querySelector('[data-mobile-close]');
		const sheetPanel = sheetOverlay ? sheetOverlay.querySelector('.leisure-mobile-sheet-panel') : null;

		// openMobileSheet() and closeMobileSheet() are the shared module
		// helpers above — the sheet nodes are re-looked-up at call time, so
		// this closure keeps only the consts the event bindings below need.

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

			// Modal focus trap: while the sheet is open, Tab cycles inside the
			// panel so keyboard focus never escapes to the page behind it.
			sheetOverlay.addEventListener('keydown', function(e) {
				if (e.key !== 'Tab' || !sheetPanel) return;
				const focusables = Array.prototype.filter.call(
					sheetPanel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
					function(el) {
						return !el.disabled && el.offsetParent !== null;
					}
				);
				if (!focusables.length) return;
				const first = focusables[0];
				const last = focusables[focusables.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			});
		}

		// Escape closes the sheet (and any desktop dropdown, returning focus
		// to its trigger); focus returns to the "Filtrar" trigger for the sheet.
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Escape') return;
			leisureDropdowns.forEach(function(dd) {
				if (isOpen(dd)) {
					setDropdown(dd, false);
					const trigger = getTrigger(dd);
					if (trigger) trigger.focus();
				}
			});
			closeMobileSheet(true);
		});

		// Mobile form (staged fallback): with the instant enhancement active
		// (initLeisureInstantFilters below) this submit is owned by that
		// module, so only the reset of the default action happens here. In
		// browsers without the instant enhancement the old staged behaviour
		// still applies. Empty params are stripped so URLs stay clean (e.g.
		// /lazer/?categoria=castelos instead of /lazer/?county=&categoria=castelos).
		const form = root.querySelector('[data-mobile-form]');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();

				// The instant module binds its own submit handler and applies
				// the selection in place — skip the full page navigation.
				if (root.dataset.instantBound === 'true') return;

				var url = new URL(form.getAttribute('action'), window.location.origin);

				// Build the query string from only the non-empty radios. The
				// point is to strip the "Todos" (value="") options. (This must
				// not mutate the params collection while iterating it — the
				// empty "Todos" value of every group would otherwise be
				// skipped and leak into the URL as an empty parameter.)
				var params = new URLSearchParams();
				new FormData(form).forEach(function(value, key) {
					if (value !== '' && value !== null) params.append(key, value);
				});

				var qs = params.toString();
				window.location.href = url.pathname + (qs ? '?' + qs : '');
			});
		}
	}

	// ===== Leisure Instant Mobile Filtering =====
	// On mobile the filter sheet applies single-select changes instantly:
	// every Localização radio change (and every chip / "Limpar" tap) applies
	// the filter by fetching the filtered archive URL and swapping the
	// results in place. The multi-select Tipo/Características checkbox
	// groups accumulate their selections while the sheet stays open and are
	// submitted together by "Mostrar resultados" (or by the next Localização
	// radio change, since the URL is always built from the whole form
	// state). Everything else about the filtering is untouched —
	// the URLs, the server-side query, the pagination and the infinite
	// scroll behave exactly like a normal page load, because the swapped
	// markup IS a normal server render of the target URL.
	//   - Any selection applies AND dismisses the panel in the same
	//     gesture (focus returns to the "Filtrar" trigger) so the user
	//     lands directly on the filtered results; the panel can be
	//     reopened to stack another filter (selections are preserved
	//     across reopen because openMobileSheet() never resets radios),
	//   - the URL is kept in sync via history.pushState (refresh, share,
	//     back/forward all keep working; back/forward restores the matching
	//     filter state),
	//   - one request per selection: starting a new apply aborts the previous
	//     one and stale responses are discarded, so an older result can never
	//     overwrite a newer selection,
	//   - a subtle updating state (dimmed grid + "Atualizando..." in the
	//     result-count line) — the old results stay visible until the new
	//     markup has arrived, so there is no flashing,
	//   - focus returns to the "Filtrar" trigger after every selection
	//     (the sheet and its radios live outside the swapped markup, so
	//     reopening the panel shows the current selections without
	//     losing state),
	//   - the result-count line (role="status") announces the new count,
	//   - desktop is untouched: the mobile form only exists inside the sheet,
	//     and the swapped desktop toolbar keeps its behaviour through
	//     bindLeisureDropdowns().
	// Browsers without fetch/DOMParser/AbortController keep the previous
	// staged behaviour (the "Mostrar resultados" submit handler still runs).
	function initLeisureInstantFilters() {
		var root = document.querySelector('[data-leisure-filters]');
		var results = document.querySelector('[data-leisure-results]');
		var form = root ? root.querySelector('[data-mobile-form]') : null;
		var trigger = root ? root.querySelector('[data-mobile-trigger]') : null;
		if (!root || !results || !form) return;
		if (!window.fetch || !window.DOMParser || !window.AbortController || !window.history || !window.URLSearchParams || !window.FormData) return;
		if (root.dataset.instantBound) return;
		root.dataset.instantBound = 'true';

		// Mark the form so the CSS can align the footer buttons: with
		// multi-select Tipo/Características sections, "Mostrar resultados"
		// is the primary apply action for those groups (individual checkbox
		// selections never navigate), while Localização radios still apply
		// instantly. The button therefore stays visible.
		form.classList.add('leisure-mobile-form--instant');

		var controller = null;
		var requestId = 0;
		// The control that started an in-place apply (a chip or "Limpar
		// filtros", both outside the sheet). Recorded so the swap can return
		// focus to its successor when the original node is removed by the
		// update — radios and the sheet controls are never swapped, so their
		// focus is left untouched.
		var lastFocused = null;

		function isMobileViewport() {
			return !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
		}

		// Current value of the single-select Localização group, read from the
		// live radio state.
		function selectedValue(name) {
			var checked = form.querySelector('input[type="radio"][name="' + name + '"]:checked');
			return checked ? checked.value : '';
		}

		// Selected slugs of a multi-select checkbox group (Tipo /
		// Características), in DOM order — the same order the server renders
		// the terms in, so the built URL is deterministic.
		function checkedValues(name) {
			return Array.prototype.map.call(
				form.querySelectorAll('input[type="checkbox"][name="' + name + '"]:checked'),
				function(input) { return input.value; }
			);
		}

		// The nameless "Todos"/"Todas" checkbox of a multi-select section is
		// the section reset: it is checked exactly when no individual option
		// of that section is checked.
		function syncClearCheckboxes() {
			Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"][data-filter-clear]'), function(clearInput) {
				var section = clearInput.closest('.leisure-mobile-section');
				var any = section ? section.querySelectorAll('input[type="checkbox"][name]:checked').length : 0;
				clearInput.checked = !any;
			});
		}

		function activeFilterCount() {
			return (selectedValue('county') ? 1 : 0) + checkedValues('categoria[]').length + checkedValues('atributo[]').length;
		}

		// The clean URL for the current radio state — the exact same
		// construction the staged submit used: form action + non-empty
		// params only (e.g. /lazer/?county=dublin&categoria=natureza).
		function urlFromForm() {
			var url = new URL(form.getAttribute('action'), window.location.origin);

			// Append only the non-empty values: the Localização radios submit a
			// "Todos" empty value (stripped below), while the multi-select
			// checkbox groups submit their checked slugs as categoria[] /
			// atributo[]. (Do NOT collect all params and delete while iterating:
			// deleting the first emptied entry shifts the iteration index and
			// lets later empty values leak back into the URL, e.g. "?categoria=".)
			var params = new URLSearchParams();
			new FormData(form).forEach(function(value, key) {
				if (value !== '' && value !== null) params.append(key, value);
			});
			var qs = params.toString();
			return url.pathname + (qs ? '?' + qs : '');
		}

		// Keep the live form state in sync with an applied URL — used after
		// chip removals and "Limpar", which arrive as hyperlink navigations
		// rather than control changes. A missing county param re-selects the
		// "Todos" radio; missing multi-select params clear every checkbox of
		// that group (and re-activate its section reset).
		function syncFormFromUrl(targetUrl) {
			var query = targetUrl.indexOf('?') !== -1 ? targetUrl.slice(targetUrl.indexOf('?') + 1) : '';
			var params = new URLSearchParams(query);

			// Localização stays single-select.
			var county = params.get('county') || '';
			var countyInput = form.querySelector('input[type="radio"][name="county"][value="' + county + '"]');
			if (countyInput) countyInput.checked = true;

			// Tipo / Características are multi-select checkbox groups driven
			// by comma-separated URL params.
			[['categoria[]', 'categoria'], ['atributo[]', 'atributo']].forEach(function(pair) {
				var values = (params.get(pair[1]) || '').split(',').filter(Boolean);
				Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"][name="' + pair[0] + '"]'), function(input) {
					input.checked = values.indexOf(input.value) !== -1;
				});
			});

			syncClearCheckboxes();
		}

		// Keep the "Filtrar" trigger (count badge + accessible name) in sync
		// with the number of active filters, exactly like the server render.
		function updateTriggerState() {
			if (!trigger) return;
			var count = activeFilterCount();
			var badge = trigger.querySelector('.leisure-mobile-filter-count');
			if (count > 0) {
				if (badge) {
					badge.textContent = String(count);
				} else {
					badge = document.createElement('span');
					badge.className = 'leisure-mobile-filter-count';
					badge.setAttribute('aria-hidden', 'true');
					badge.textContent = String(count);
					trigger.appendChild(badge);
				}
				trigger.setAttribute('aria-label', 'Filtrar (' + count + ' filtros ativos)');
			} else {
				if (badge) badge.remove();
				trigger.removeAttribute('aria-label');
			}
		}

		// The result-count line doubles as the status region: it is kept in
		// the DOM (stable role="status" region) and only its text changes, so
		// screen readers announce loading and the new count reliably.
		function countLine() {
			var line = root.querySelector('.leisure-results-count');
			if (line) return line;
			line = document.createElement('p');
			line.className = 'leisure-results-count';
			line.setAttribute('role', 'status');
			var active = root.querySelector('[data-leisure-active-filters]');
			if (active) {
				active.insertAdjacentElement('afterend', line);
			} else {
				root.appendChild(line);
			}
			return line;
		}

		function setUpdating(updating) {
			results.classList.toggle('is-updating', updating);
			if (updating) {
				results.setAttribute('aria-busy', 'true');
				var line = countLine();
				while (line.firstChild) line.removeChild(line.firstChild);
				var spinner = document.createElement('span');
				spinner.className = 'infinite-scroll__spinner';
				spinner.setAttribute('aria-hidden', 'true');
				line.appendChild(spinner);
				line.appendChild(document.createTextNode('Atualizando...'));
			} else {
				results.removeAttribute('aria-busy');
			}
		}

		// Replace the contents of a live element with the parsed equivalent.
		function importChildren(fromEl, toEl) {
			while (toEl.firstChild) toEl.removeChild(toEl.firstChild);
			Array.prototype.forEach.call(fromEl.children, function(child) {
				toEl.appendChild(document.importNode(child, true));
			});
		}

		// Swap the results area (grid + pagination / empty state) and the
		// filter bar state (desktop toolbar, chips, result count) with the
		// freshly fetched markup. The mobile sheet and its form are NOT part
		// of the swap, so the open panel and radio focus survive untouched.
		function swapMarkup(doc) {
			var newResults = doc.querySelector('[data-leisure-results]');
			if (newResults) {
				importChildren(newResults, results);
			}

			var newRoot = doc.querySelector('[data-leisure-filters]');
			if (newRoot) {
				// Desktop toolbar — hidden on mobile, kept in sync so rotating
				// the device never shows stale dropdown state. Its bindings are
				// rebuilt because the nodes are new.
				var newToolbar = newRoot.querySelector('.leisure-filter-toolbar');
				var liveToolbar = root.querySelector('.leisure-filter-toolbar');
				if (newToolbar && liveToolbar) {
					liveToolbar.replaceWith(document.importNode(newToolbar, true));
					bindLeisureDropdowns(root);
				}

				// Active-filter chips + "Limpar filtros" (rendered only while a
				// filter is active).
				var newActive = newRoot.querySelector('[data-leisure-active-filters]');
				var liveActive = root.querySelector('[data-leisure-active-filters]');
				if (newActive && liveActive) {
					liveActive.replaceWith(document.importNode(newActive, true));
				} else if (newActive && !liveActive) {
					var toolbar = root.querySelector('.leisure-filter-toolbar');
					if (toolbar) {
						toolbar.insertAdjacentElement('afterend', document.importNode(newActive, true));
					} else {
						root.appendChild(document.importNode(newActive, true));
					}
				} else if (!newActive && liveActive) {
					liveActive.remove();
				}

				// Result count: text-only update on the stable live node.
				var newCount = newRoot.querySelector('.leisure-results-count');
				var liveCount = countLine();
				if (newCount) {
					liveCount.hidden = false;
					liveCount.textContent = newCount.textContent;
				} else {
					liveCount.hidden = true;
					liveCount.textContent = '';
				}
			}

			// NOTE: updateTriggerState() deliberately runs AFTER the pipeline
			// re-syncs the radios (syncFormFromUrl, in the apply callback),
			// so the "Filtrar" badge/aria-label always reflect the new state
			// — chip removals and "Limpar" arrive as URL navigations, and at
			// swap time the form still holds the OLD checked radios.

			// The grid node is new: re-run the infinite scroll enhancement so
			// it binds to the fresh markup and keeps loading the FILTERED
			// pages — its next-page URLs come from the swapped pagination,
			// which was rendered from the filtered query.
			initInfiniteScroll();

			restoreFocusAfterSwap();
		}

		// If the control that triggered the update was removed by the swap
		// (a chip or "Limpar filtros" outside the sheet), keep keyboard
		// focus flowing to its successor instead of dropping to <body>.
		// Anything still in the document (radios, the sheet's own "Limpar",
		// the "Mostrar resultados" button) keeps focus exactly where it was.
		function restoreFocusAfterSwap() {
			if (!lastFocused || lastFocused.isConnected) return;
			if (!isMobileViewport()) return;
			var chips = root.querySelectorAll('.leisure-filter-chip');
			if (chips.length) {
				chips[0].focus();
				return;
			}
			// Every filter was cleared and the chip row is gone: move focus
			// to the primary filtering control rather than leaving it on
			// <body>.
			if (trigger) {
				trigger.focus();
				return;
			}
			results.tabIndex = -1;
			results.focus();
		}

		function applyFilterUrl(targetUrl, opts) {
			opts = opts || {};
			var absolute = new URL(targetUrl, window.location.href).href;
			var id = ++requestId;

			// Exactly one request per selection: a new selection aborts the
			// previous in-flight request, and the id guard discards any
			// response that still sneaks through.
			if (controller) controller.abort();
			controller = new AbortController();

			setUpdating(true);

			fetch(absolute, { credentials: 'same-origin', signal: controller.signal }).then(function(response) {
				if (!response.ok) throw new Error('HTTP ' + response.status);
				return response.text();
			}).then(function(html) {
				if (id !== requestId) return; // a newer selection superseded this one
				var doc = new DOMParser().parseFromString(html, 'text/html');
				if (!doc.querySelector('[data-leisure-results]')) {
					throw new Error('Unexpected response markup');
				}
				setUpdating(false);
				swapMarkup(doc);
				syncFormFromUrl(absolute);
				// Badge and accessible name are computed from the LIVE radio
				// state, which syncFormFromUrl has just reconciled with the
				// applied URL — so chip removals and "Limpar" can never show
				// a stale count.
				updateTriggerState();
				var title = doc.querySelector('title');
				if (title) document.title = title.textContent;
				// Push a normal entry for user selections (radio, chip,
				// "Limpar"), so Back walks through each applied state. A
				// back/forward RESTORE replaces the entry it stands on —
				// otherwise re-pushing would stack a duplicate entry and the
				// subsequent Forward stop would go nowhere.
				if (opts.replace) {
					history.replaceState({}, '', urlFromForm());
				} else {
					history.pushState({}, '', urlFromForm());
				}
			}).catch(function(err) {
				if (err && err.name === 'AbortError') return; // superseded request
				// Anything unexpected (offline, 500, ...): fall back to a
				// normal navigation — filtering itself keeps working.
				window.location.href = targetUrl;
			});
		}

		// Localização (single-select radio) still applies immediately AND
		// dismisses the panel in the same gesture — the URL is built from the
		// WHOLE form state, so any Tipo/Características checkboxes already
		// ticked are submitted together with it. Tapping the already-active
		// radio fires no change event, so no redundant request is possible.
		// closeMobileSheet(true) uses the shared close path and restores focus
		// to the "Filtrar" trigger; the sheet can be reopened to stack more
		// selections (checkbox state is preserved because openMobileSheet()
		// never resets the form).
		//
		// The multi-select checkbox groups NEVER navigate on an individual
		// selection — checked options accumulate while the sheet stays open
		// and only "Mostrar resultados" (or a Localização radio) applies them.
		// A change only re-syncs that section's reset checkbox and the
		// trigger badge. Checking "Todos"/"Todas" (data-filter-clear) is the
		// section reset: it clears every named checkbox of its section and
		// stays checked; unchecking it with nothing else selected re-activates
		// it, since "nothing selected" IS the reset state.
		form.addEventListener('change', function(e) {
			var input = e.target;
			if (!input) return;

			if (input.type === 'radio') {
				applyFilterUrl(urlFromForm());
				if (isMobileViewport()) closeMobileSheet(true);
				return;
			}

			if (input.type !== 'checkbox') return;

			if (input.hasAttribute('data-filter-clear')) {
				var section = input.closest('.leisure-mobile-section');
				if (input.checked && section) {
					Array.prototype.forEach.call(section.querySelectorAll('input[type="checkbox"][name]'), function(option) {
						option.checked = false;
					});
					input.checked = true;
				} else if (section && !section.querySelectorAll('input[type="checkbox"][name]:checked').length) {
					input.checked = true;
				}
				updateTriggerState();
				return;
			}

			syncClearCheckboxes();
			updateTriggerState();
		});

		// "Mostrar resultados" (kept as an explicit fallback) re-routes
		// through the same instant pipeline instead of a full page load.
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			applyFilterUrl(urlFromForm());
		});

		// Chips ([Dublin ×]), "Limpar filtros" and the sheet's "Limpar" are
		// real hyperlinks; on mobile they run through the same instant
		// pipeline (delegation on the root survives every markup swap above).
		// On desktop they keep their native hyperlink navigation exactly as
		// before — desktop behaviour is untouched by this enhancement.
		root.addEventListener('click', function(e) {
			var link = e.target.closest('.leisure-filter-chip, .leisure-toolbar-clear, .leisure-mobile-clear');
			if (!link || !root.contains(link)) return;
			if (!isMobileViewport()) return;
			e.preventDefault();
			lastFocused = document.activeElement;
			applyFilterUrl(link.getAttribute('href'));
		});

		// Back/forward restores the matching filter state. Popstates fired by
		// the infinite scroll's own page pushes (same filter params) are
		// ignored, matching the existing no-restore behaviour.
		window.addEventListener('popstate', function() {
			var params = new URLSearchParams(window.location.search);
			if ((params.get('county') || '') === selectedValue('county')
				&& (params.get('categoria') || '') === checkedValues('categoria[]').join(',')
				&& (params.get('atributo') || '') === checkedValues('atributo[]').join(',')) return;
			applyFilterUrl(window.location.href, { replace: true });
		});
	}

	// ===== Directory filter interaction (shared: Empregos + Eventos) =====
	// One implementation of the Lazer filter interaction (initLeisureFilters),
	// reused by every directory filter widget built on the data-dropdown /
	// data-mobile-* contract:
	//   /empregos/ agency directory → [data-agency-filters] (initAgencyFilters)
	//   /eventos/ events archive    → [data-event-filters] (initEventFilters)
	//   - desktop hyperlink dropdowns: one open at a time, toggle re-focuses
	//     the trigger, outside click / Escape close with focus return,
	//   - a client-side search inside long popovers (and the mobile sheet
	//     sections) that filters the already server-rendered options,
	//   - a modal mobile bottom sheet with always-visible radio fieldsets:
	//     focus trap, Escape/backdrop close, focus return to the trigger,
	//   - selecting a radio applies the filter immediately (the form is
	//     submitted, navigating to the server-rendered filtered URL — the
	//     sheet auto-dismisses because the page navigates, exactly like the
	//     Lazer apply-and-close gesture),
	//   - the submit pipeline strips the empty "Todas"/"Todos" values so URLs
	//     stay clean (/empregos/?area=warehouse, /eventos/?county=laois —
	//     never /.../?area=&...).
	// `ns` carries the widget's CSS class prefix ('agency-filters' /
	// 'event-filters') for the few class-name lookups below; everything else
	// is driven by the shared data-* contract. Filtering itself is
	// server-side and URL driven (?area=/?localizacao=/?contrato= and
	// ?county=/?cidade=/?categoria=); refresh, back/forward and shared URLs
	// all work natively.
	function initDirectoryFilters(root, ns) {
		if (!root) return;

		var dropdowns = Array.prototype.slice.call(root.querySelectorAll('[data-dropdown]'));

		function getTrigger(dropdown) {
			return dropdown.querySelector('[data-dropdown-trigger]');
		}

		function isOpen(dropdown) {
			var trigger = getTrigger(dropdown);
			return !!trigger && trigger.getAttribute('aria-expanded') === 'true';
		}

		function setDropdown(dropdown, open) {
			var trigger = getTrigger(dropdown);
			if (trigger) {
				trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
			}
			var search = dropdown.querySelector('[data-option-search]');
			if (open && search) {
				search.focus();
				search.select();
			}
		}

		function closeAllDropdowns(except) {
			dropdowns.forEach(function(dd) {
				if (dd === except) return;
				setDropdown(dd, false);
			});
		}

		dropdowns.forEach(function(dropdown) {
			var trigger = getTrigger(dropdown);
			if (!trigger) return;

			trigger.addEventListener('click', function(e) {
				e.stopPropagation();
				var wasOpen = isOpen(dropdown);
				closeAllDropdowns(dropdown);
				setDropdown(dropdown, !wasOpen);
				if (wasOpen) trigger.focus();
			});
		});

		// Click outside any dropdown closes every open menu.
		document.addEventListener('click', function(e) {
			if (!e.target.closest('[data-dropdown]')) {
				closeAllDropdowns();
			}
		});

		// Escape closes open dropdowns (focus back to the trigger) and the
		// mobile sheet (focus back to the "Filtrar" trigger).
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Escape') return;
			dropdowns.forEach(function(dd) {
				if (isOpen(dd)) {
					setDropdown(dd, false);
					var trigger = getTrigger(dd);
					if (trigger) trigger.focus();
				}
			});
			if (sheetOverlay && sheetOverlay.classList.contains('is-open')) {
				closeSheet(true);
			}
		});

		// Client-side option search (accent/case-insensitive match on the
		// visible label) — purely cosmetic: the underlying links and radio
		// values are untouched, so with JS disabled every option remains
		// usable. Works for both the desktop popover and the mobile section.
		function normalizeText(text) {
			return (text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}

		Array.prototype.forEach.call(root.querySelectorAll('[data-option-search]'), function(search) {
			var scope = search.closest('[data-option-scope]');
			if (!scope) return;

			var options = Array.prototype.slice.call(scope.querySelectorAll('[data-option-item]'));
			var container = search.closest('.' + ns + '-dropdown-panel') || search.closest('.' + ns + '-mobile-section') || root;
			var empty = container.querySelector('[data-option-empty]');
			var list = scope.querySelector('[data-option-list]');

			function applySearch() {
				var query = normalizeText(search.value.trim());
				var visible = 0;
				options.forEach(function(option) {
					var match = !query || normalizeText(option.textContent).indexOf(query) !== -1;
					option.hidden = !match;
					if (match) visible++;
				});
				if (empty) {
					empty.hidden = visible > 0;
				}
				if (list) {
					list.hidden = visible === 0;
				}
			}

			search.addEventListener('input', applySearch);

			// Escape inside the search first clears the query (restoring every
			// option); a second press falls through and closes the panel/sheet.
			search.addEventListener('keydown', function(e) {
				if (e.key !== 'Escape') return;
				if (search.value !== '') {
					e.stopPropagation();
					search.value = '';
					applySearch();
				}
			});
		});

		// Mobile bottom sheet (modal dialog, same UX as the Lazer sheet).
		var sheetTrigger = root.querySelector('[data-mobile-trigger]');
		var sheetOverlay = root.querySelector('[data-mobile-sheet]');
		var sheetClose = root.querySelector('[data-mobile-close]');
		var sheetPanel = sheetOverlay ? sheetOverlay.querySelector('.' + ns + '-sheet-panel') : null;

		function openSheet() {
			if (!sheetOverlay) return;
			sheetOverlay.classList.add('is-open');
			sheetOverlay.setAttribute('aria-hidden', 'false');
			if (sheetTrigger) sheetTrigger.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
			// NOTE: the radio state is server-rendered from the URL and is
			// deliberately never reset here — reopening the sheet must show
			// the current selections.
			if (sheetClose) {
				setTimeout(function() {
					// The sheet may already be closed before this fires. Never
					// move focus into a hidden element.
					if (sheetOverlay.classList.contains('is-open')) {
						sheetClose.focus();
					}
				}, 50);
			}
		}

		function closeSheet(restoreFocus) {
			if (!sheetOverlay || !sheetOverlay.classList.contains('is-open')) return;
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
				if (sheetOverlay.classList.contains('is-open')) {
					closeSheet();
				} else {
					openSheet();
				}
			});

			if (sheetClose) {
				sheetClose.addEventListener('click', function() {
					closeSheet(true);
				});
			}

			// Clicking the dark backdrop (outside the sheet panel) closes it.
			sheetOverlay.addEventListener('click', function(e) {
				if (e.target === sheetOverlay || (sheetPanel && !sheetPanel.contains(e.target))) {
					closeSheet(true);
				}
			});

			// Modal focus trap: while the sheet is open, Tab cycles inside the
			// panel so keyboard focus never escapes to the page behind it.
			sheetOverlay.addEventListener('keydown', function(e) {
				if (e.key !== 'Tab' || !sheetPanel) return;
				var focusables = Array.prototype.filter.call(
					sheetPanel.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
					function(el) {
						return !el.disabled && el.offsetParent !== null;
					}
				);
				if (!focusables.length) return;
				var first = focusables[0];
				var last = focusables[focusables.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			});
		}

		// Mobile form: every radio change applies the filter immediately by
		// navigating to the server-rendered URL (the "Mostrar resultados"
		// button remains as the no-JS fallback submit). Empty "Todas"/"Todos"
		// values are stripped so URLs stay clean, and the section anchor is
		// preserved so the user lands back on the directory.
		var form = root.querySelector('[data-mobile-form]');
		if (form) {
			form.addEventListener('submit', function(e) {
				e.preventDefault();

				var url = new URL(form.getAttribute('action'), window.location.origin);

				var params = new URLSearchParams();
				new FormData(form).forEach(function(value, key) {
					if (value !== '' && value !== null) params.append(key, value);
				});

				var qs = params.toString();
				window.location.href = url.pathname + (qs ? '?' + qs : '') + (url.hash || '');
			});

			Array.prototype.forEach.call(form.querySelectorAll('input[type="radio"]'), function(radio) {
				radio.addEventListener('change', function() {
					// requestSubmit() runs the submit pipeline above (clean URL,
					// anchor preserved); the raw submit() is the legacy fallback.
					if (typeof form.requestSubmit === 'function') {
						form.requestSubmit();
					} else {
						form.submit();
					}
				});
			});
		}
	}

	// /empregos/ agency directory (Área / Localização / Contrato).
	function initAgencyFilters() {
		initDirectoryFilters(document.querySelector('[data-agency-filters]'), 'agency-filters');
	}

	// /eventos/ events archive (Localização / Cidade / Categoria) — the same
	// interaction contract as the agency directory, over the event URL
	// dimensions (?county= / ?cidade= / ?categoria=).
	function initEventFilters() {
		initDirectoryFilters(document.querySelector('[data-event-filters]'), 'event-filters');
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
			// Takes the already-measured current index so the caller can
			// complete its layout reads BEFORE any DOM write happens here.
			function updateDots(active) {
				if (!dots.length) return;
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

			// Sync the dots + live region with the visible slide. `max` may
			// carry a maxScroll() value the caller measured BEFORE any DOM
			// write, so one read pass can feed several writers. Ordering
			// matters: every layout read (maxScroll, stepSize's offsetLeft,
			// scrollLeft) happens BEFORE the first write (dot classes, status
			// text). Writing first and measuring afterwards is exactly the
			// read → write → read pattern browsers surface as "forced
			// reflow" (Lighthouse diagnostic) — this ordering is purely a
			// measurement reorder and does not change any behavior.
			function announce(max) {
				var m = (max === undefined) ? maxScroll() : max;
				var active = currentIndex();
				updateDots(active);
				if (!status) return;
				if (m <= 2) {
					status.textContent = '';
					return;
				}
				status.textContent = 'Apoiador ' + (active + 1) + ' de ' + slides.length;
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
				var max = maxScroll();
				if (max <= 2) return;
				var index = currentIndex();
				scrollToIndex(index >= slides.length - 1 ? 0 : index + 1);
				// Hand the already-measured maxScroll() to the caller so a
				// following scheduleAutoplay() needs no fresh layout read
				// (scrollWidth is scroll-position independent, so the value
				// is identical to a post-scroll measurement).
				return max;
			}

			function goPrev() {
				var max = maxScroll();
				if (max <= 2) return;
				var index = currentIndex();
				scrollToIndex(index <= 0 ? slides.length - 1 : index - 1);
				return max;
			}

			// --- Autoplay control --------------------------------------------
			// The cycle applies to the Hero variant only, needs at least two
			// slides plus actual overflow, and never runs for users who asked
			// for reduced motion (manual navigation keeps working there).
			// `max` may carry a maxScroll() value measured by the caller
			// before any DOM write, keeping eligibility a read-free decision.
			function autoplayEligible(max) {
				var m = (max === undefined) ? maxScroll() : max;
				return carousel.classList.contains('sponsors-carousel--hero') &&
					slides.length > 1 &&
					m > 2 &&
					!prefersReducedMotion();
			}

			// Single entry point for arming / re-arming / pausing the cycle:
			// it always clears any pending timer before deciding, so repeated
			// calls can never stack timers or speed the carousel up.
			function scheduleAutoplay(max) {
				if (autoplayTimer !== null) {
					clearTimeout(autoplayTimer);
					autoplayTimer = null;
				}
				if (!autoplayEligible(max) || hoverPaused || focusPaused || pressPaused || document.hidden) {
					return;
				}
				autoplayTimer = setTimeout(function() {
					autoplayTimer = null;
					// If the component left the DOM meanwhile, stop cycling.
					if (!carousel.isConnected) return;
					scheduleAutoplay(goNext());
				}, SPONSORS_AUTOPLAY_INTERVAL);
			}

			// Manual navigation moves first, THEN restarts the full 2-second
			// countdown — an arrow click never causes an immediate follow-up
			// advance; the next automatic step comes a full interval later.
			function goNextManual() {
				scheduleAutoplay(goNext());
			}

			function goPrevManual() {
				scheduleAutoplay(goPrev());
			}

			// Adaptive layout: when everything fits without scrolling, mark
			// the carousel static (CSS hides the arrows and centers the row).
			function sync() {
				// Read phase: take every layout measurement BEFORE the first
				// DOM write, so nothing here can force a synchronous reflow.
				var max = maxScroll();
				announce(max);
				// Write phase: only now mutate the DOM.
				carousel.classList.toggle('is-static', max <= 2);
				// Geometry changed — re-decide whether a cycle should run
				// (reads the pre-measured max; no further layout access).
				scheduleAutoplay(max);
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
					// One read pass feeds both writers: maxScroll() is taken
					// before announce()'s dot-class writes, and the autoplay
					// re-arm consumes the same measurement — so no layout
					// read ever follows a DOM write in this callback.
					var max = maxScroll();
					announce(max);
					if (!autoScrolling) scheduleAutoplay(max);
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

	// ===== Infinite Scroll (progressive enhancement) =====
	// Applies to the Blog (/blog/), Guias (/guias/) and Lazer (/lazer/)
	// archives. It rides entirely on top of the existing WordPress
	// pagination: the "next page" URL is read from the server-rendered
	// pagination component and the real page-2/page-3 URLs are fetched,
	// so every query var (?categoria=, ?county=, category archives, ...)
	// and the main-query ordering are preserved by construction — no
	// custom endpoint and no duplicate query. Without JavaScript the
	// normal pagination links keep working untouched (the pagination
	// markup is only hidden, never removed, when this enhancement runs).
	function initInfiniteScroll() {
		var grid = document.querySelector('[data-infinite-scroll]');
		if (!grid || grid.dataset.infiniteBound) return;
		// No IntersectionObserver (very old browsers): keep normal pagination.
		if (!('IntersectionObserver' in window) || !window.DOMParser) return;
		grid.dataset.infiniteBound = 'true'; // guard against double init

		var pagination = grid.parentElement && grid.parentElement.querySelector('.conexao-pagination');
		if (!pagination) return;

		var nextLink = pagination.querySelector('a.page-numbers.next');
		if (!nextLink) return; // single page — nothing to enhance

		var nextUrl = nextLink.getAttribute('href');
		var busy = false;     // only one request at a time
		var errored = false;  // errors wait for an explicit retry
		var finished = false;

		// Hide the numeric pagination visually while keeping it in the
		// markup for no-JS visitors, crawlers and keyboard fallbacks.
		pagination.classList.add('conexao-pagination--infinite-hidden');

		// Status region: announced to screen readers via aria-live="polite".
		var footer = document.createElement('div');
		footer.className = 'infinite-scroll';
		var status = document.createElement('p');
		status.className = 'infinite-scroll__status';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		footer.appendChild(status);

		// Sentinel: observed instead of any continuous scroll listener.
		var sentinel = document.createElement('div');
		sentinel.className = 'infinite-scroll__sentinel';
		sentinel.setAttribute('aria-hidden', 'true');
		footer.appendChild(sentinel);
		pagination.insertAdjacentElement('afterend', footer);

		var observer = new IntersectionObserver(onIntersect, { rootMargin: '480px 0px' });
		observer.observe(sentinel);

		function onIntersect(entries) {
			for (var i = 0; i < entries.length; i++) {
				if (!entries[i].isIntersecting) continue;
				if (busy || errored || finished) return;
				loadNext();
				return;
			}
		}

		function setStatusLoading() {
			clearStatus();
			var spinner = document.createElement('span');
			spinner.className = 'infinite-scroll__spinner';
			spinner.setAttribute('aria-hidden', 'true');
			status.appendChild(spinner);
			status.appendChild(document.createTextNode('Carregando...'));
		}

		function setStatusError() {
			clearStatus();
			var text = document.createElement('span');
			text.className = 'infinite-scroll__error';
			text.textContent = 'Não foi possível carregar mais conteúdo.';
			var retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'infinite-scroll__retry';
			retry.textContent = 'Tentar novamente';
			retry.addEventListener('click', function() {
				if (busy) return;
				errored = false;
				loadNext();
			});
			status.appendChild(text);
			status.appendChild(retry);
		}

		function clearStatus() {
			while (status.firstChild) status.removeChild(status.firstChild);
		}

		function loadNext() {
			if (busy || finished || errored || !nextUrl) return;

			busy = true;
			setStatusLoading();

			fetch(nextUrl, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'conexao-infinite-scroll' }
			}).then(function(response) {
				if (!response.ok) throw new Error('HTTP ' + response.status);
				return response.text();
			}).then(function(html) {
				// The grid may have been swapped in place by the mobile
				// leisure instant filter while this request was in flight.
				// If so, discard the stale batch — it must never reach the
				// DOM, update the URL via pushState or re-arm the observer
				// on the old, detached markup.
				if (!grid.isConnected) {
					busy = false;
					return;
				}
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var remoteGrid = doc.querySelector('[data-infinite-scroll]');
				if (!remoteGrid) throw new Error('Unexpected archive markup');

				appendBatch(remoteGrid);

				// Resolve the following page from the fetched document's own
				// pagination — exactly the links WordPress rendered.
				var remoteNext = doc.querySelector('.conexao-pagination a.page-numbers.next');
				var following = remoteNext ? remoteNext.getAttribute('href') : null;

				// One history entry per loaded page: /blog/ → /blog/page/2/ →
				// /blog/page/3/. The Back button then restores the previous
				// page URL naturally (the browser restores the scroll
				// position; the already-appended content stays in place).
				if (following && history.pushState) {
					try {
						history.pushState({ conexaoInfiniteScroll: true }, '', nextUrl);
					} catch (e) {
						// Same-origin violation or quota — history is optional.
					}
				}

				busy = false;
				clearStatus();

				if (!following) {
					finish();
					return;
				}
				nextUrl = following;

				// Re-arm the observer: if the sentinel is still inside the
				// (enlarged) rootMargin, observing again fires a fresh
				// callback and the next batch loads immediately.
				observer.unobserve(sentinel);
				observer.observe(sentinel);
			}).catch(function() {
				// Network/server/parse failure: show a recoverable error
				// state. No automatic retries — the user decides.
				busy = false;
				errored = true;
				setStatusError();
			});
		}

		// Back/forward navigation (bfcache): a fetch that was in flight
		// when the user left the page never resolves after restore, which
		// would leave `busy` stuck and silently kill the enhancement.
		// Reset the in-flight state so scrolling simply continues where it
		// stopped (same bfcache guard used by the event-importer admin JS).
		window.addEventListener('pageshow', function(event) {
			if (!event.persisted) return;
			// The grid was replaced by an in-place filter swap — nothing to
			// resume on the old markup.
			if (!grid.isConnected) return;
			busy = false;
			// Keep a rendered error/retry state; only clear a stale
			// "Carregando..." spinner left over from the abandoned fetch.
			if (!errored && !finished) clearStatus();
			observer.unobserve(sentinel);
			observer.observe(sentinel);
		});

		function appendBatch(remoteGrid) {
			var fragment = document.createDocumentFragment();
			Array.prototype.forEach.call(remoteGrid.children, function(node) {
				if (node.nodeType !== 1) return;
				// Duplicate guard: every archive card carries id="post-{ID}".
				if (node.id && document.getElementById(node.id)) return;
				fragment.appendChild(document.importNode(node, true));
			});
			grid.appendChild(fragment);
		}

		function finish() {
			finished = true;
			observer.disconnect(); // stop requesting nonexistent pages
			clearStatus();
			var end = document.createElement('span');
			end.className = 'infinite-scroll__end';
			end.textContent = 'Você chegou ao fim.';
			status.appendChild(end);
		}
	}

	// ===== Manual Load More (progressive enhancement) =====
	// Applies to the Eventos (/eventos/) and Cursos (/cursos/) archives.
	// Unlike the automatic infinite scroll above, these archives NEVER
	// fetch a batch on their own: the next page loads only after an
	// explicit "Carregar mais" click. Everything else works exactly like
	// the infinite scroll — the next-page URL is read from the
	// server-rendered pagination component and the real /page/N/ URL is
	// fetched, so every query var (?cidade=, ?categoria=) and the
	// main-query ordering (including the chronological event order) are
	// preserved by construction. Without JavaScript the numeric
	// pagination keeps working untouched (it is only hidden, never
	// removed, when this enhancement runs).
	function initLoadMore() {
		var grid = document.querySelector('[data-load-more]');
		if (!grid || grid.dataset.loadMoreBound) return;
		// No DOMParser (very old browsers): keep normal pagination.
		if (!window.DOMParser) return;
		grid.dataset.loadMoreBound = 'true'; // guard against double init

		var pagination = grid.parentElement && grid.parentElement.querySelector('.conexao-pagination');
		if (!pagination) return;

		var nextLink = pagination.querySelector('a.page-numbers.next');
		if (!nextLink) return; // single page — no button, nothing to enhance

		var nextUrl = nextLink.getAttribute('href');
		var busy = false;     // only one request at a time
		var finished = false;
		var noun = grid.closest('.events-page') ? 'eventos' : 'cursos';

		// Hide the numeric pagination visually while keeping it in the
		// markup for no-JS visitors, crawlers and keyboard fallbacks.
		pagination.classList.add('conexao-pagination--infinite-hidden');

		// Footer: the manual button + a polite status region. The status
		// reuses the infinite-scroll status/end classes so styling (and
		// dark mode, via the shared design tokens) stays identical.
		var footer = document.createElement('div');
		footer.className = 'load-more';

		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'load-more__button';
		button.textContent = 'Carregar mais';
		button.addEventListener('click', loadNext); // single handler, never re-bound

		var status = document.createElement('p');
		status.className = 'infinite-scroll__status';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');

		footer.appendChild(button);
		footer.appendChild(status);
		pagination.insertAdjacentElement('afterend', footer);

		function setLoading(loading) {
			// Disabled while a request is active: duplicate clicks can
			// never start a second request (loadNext also re-checks `busy`).
			button.disabled = loading;
			button.textContent = loading ? 'Carregando...' : 'Carregar mais';
		}

		function clearStatus() {
			while (status.firstChild) status.removeChild(status.firstChild);
		}

		function loadNext() {
			if (busy || finished || !nextUrl) return;

			busy = true;
			setLoading(true);
			clearStatus();

			fetch(nextUrl, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'conexao-load-more' }
			}).then(function(response) {
				if (!response.ok) throw new Error('HTTP ' + response.status);
				return response.text();
			}).then(function(html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				var remoteGrid = doc.querySelector('[data-load-more]');
				if (!remoteGrid) throw new Error('Unexpected archive markup');

				var added = appendBatch(remoteGrid);

				// Resolve the following page from the fetched document's
				// own pagination — exactly the links WordPress rendered.
				var remoteNext = doc.querySelector('.conexao-pagination a.page-numbers.next');
				var following = remoteNext ? remoteNext.getAttribute('href') : null;

				busy = false;

				if (!following) {
					finish();
					return;
				}

				nextUrl = following;
				setLoading(false);

				// Polite announcement — focus stays where the user left it.
				if (added > 0) {
					clearStatus();
					status.appendChild(document.createTextNode('Mais ' + added + ' ' + noun + ' carregados.'));
				}
			}).catch(function() {
				// Network/server/parse failure: recoverable. Re-enable the
				// button so the user can retry with a fresh click — no
				// automatic retries, no second concurrent request.
				busy = false;
				setLoading(false);
				clearStatus();
				status.appendChild(document.createTextNode('Não foi possível carregar mais conteúdo. Tente novamente.'));
			});
		}

		// Back/forward navigation (bfcache): a fetch that was in flight
		// when the user left the page never resolves after restore, which
		// would leave the button stuck on "Carregando..." forever. Re-enable
		// it so the next click just works (same bfcache guard used by the
		// event-importer admin JS).
		window.addEventListener('pageshow', function(event) {
			if (!event.persisted) return;
			busy = false;
			if (!finished) setLoading(false);
		});

		function appendBatch(remoteGrid) {
			var fragment = document.createDocumentFragment();
			var added = 0;
			Array.prototype.forEach.call(remoteGrid.children, function(node) {
				if (node.nodeType !== 1) return;
				// Duplicate guard: every archive card carries id="post-{ID}".
				if (node.id && document.getElementById(node.id)) return;
				fragment.appendChild(document.importNode(node, true));
				added++;
			});
			grid.appendChild(fragment);
			return added;
		}

		function finish() {
			finished = true;
			// No further pages: remove the button (nothing left to request)
			// and show a muted end-of-results note in its place.
			if (button.parentNode) {
				footer.removeChild(button);
			}
			clearStatus();
			var end = document.createElement('span');
			end.className = 'infinite-scroll__end';
			end.textContent = 'Você chegou ao fim.';
			status.appendChild(end);
		}
	}

	// ===== Expose functions globally if needed =====
	window.ConexaoPortal = {
		initMobileMenu: initMobileMenu
	};

})();