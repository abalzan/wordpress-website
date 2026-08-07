/**
 * Conexão BR Irlanda - Main JavaScript
 * Premium Community Portal Interactive Features
 */
(function() {
	'use strict';

	// ===== DOM Ready =====
	document.addEventListener('DOMContentLoaded', function() {
		initMobileMenu();
		initStickyHeader();
		initLanguageSelector();
		initSmoothScroll();
		initCopyButtons();
		initLazyLoad();
		initSubmenuToggle();
	});

	// ===== Mobile Menu Toggle =====
	function initMobileMenu() {
		const menuToggle = document.querySelector('.menu-toggle');
		const navMenu = document.querySelector('.nav-menu');

		if (menuToggle && navMenu) {
			menuToggle.addEventListener('click', function() {
				navMenu.classList.toggle('active');
				this.classList.toggle('active');
				
				// Update aria-expanded
				const isExpanded = navMenu.classList.contains('active');
				this.setAttribute('aria-expanded', isExpanded);
			});

			// Close menu when clicking outside
			document.addEventListener('click', function(e) {
				if (!menuToggle.contains(e.target) && !navMenu.contains(e.target)) {
					navMenu.classList.remove('active');
					menuToggle.classList.remove('active');
					menuToggle.setAttribute('aria-expanded', 'false');
				}
			});

			// Close menu on escape key
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && navMenu.classList.contains('active')) {
					navMenu.classList.remove('active');
					menuToggle.classList.remove('active');
					menuToggle.setAttribute('aria-expanded', 'false');
					menuToggle.focus();
				}
			});
		}
	}

	// ===== Submenu Toggle for Mobile =====
	function initSubmenuToggle() {
		const dropdowns = document.querySelectorAll('.nav-item.has-dropdown');
		
		dropdowns.forEach(function(dropdown) {
			const link = dropdown.querySelector('.nav-link');
			const submenu = dropdown.querySelector('.sub-menu');
			
			if (link && submenu && window.innerWidth <= 768) {
				link.addEventListener('click', function(e) {
					if (window.innerWidth <= 768) {
						e.preventDefault();
						submenu.classList.toggle('active');
						dropdown.classList.toggle('active');
					}
				});
			}
		});

		// Handle resize
		let resizeTimer;
		window.addEventListener('resize', function() {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function() {
				if (window.innerWidth > 768) {
					document.querySelectorAll('.sub-menu.active').forEach(function(submenu) {
						submenu.classList.remove('active');
					});
					document.querySelectorAll('.nav-item.active').forEach(function(item) {
						item.classList.remove('active');
					});
				}
			}, 250);
		});
	}

	// ===== Sticky Header Shadow on Scroll =====
	function initStickyHeader() {
		const header = document.querySelector('.site-header');
		
		if (header) {
			let lastScroll = 0;
			
			window.addEventListener('scroll', function() {
				const currentScroll = window.pageYOffset;
				
				if (currentScroll > 50) {
					header.classList.add('scrolled');
				} else {
					header.classList.remove('scrolled');
				}
				
				lastScroll = currentScroll;
			}, { passive: true });
		}
	}

	// ===== Language Selector =====
	function initLanguageSelector() {
		const langButtons = document.querySelectorAll('.language-selector button');
		
		langButtons.forEach(function(button) {
			button.addEventListener('click', function() {
				const lang = this.getAttribute('data-lang');
				
				// Update active state
				langButtons.forEach(function(btn) {
					btn.classList.remove('active');
				});
				this.classList.add('active');
				
				// Store language preference
				localStorage.setItem('conexao_lang', lang);
				
				// Trigger custom event for other scripts
				window.dispatchEvent(new CustomEvent('languageChanged', { 
					detail: { lang: lang } 
				}));
				
				// In a real implementation, this would reload the page with the new language
				// window.location.href = window.location.pathname + '?lang=' + lang;
			});
		});
		
		// Restore language preference
		const savedLang = localStorage.getItem('conexao_lang');
		if (savedLang) {
			langButtons.forEach(function(button) {
				if (button.getAttribute('data-lang') === savedLang) {
					button.classList.add('active');
				} else {
					button.classList.remove('active');
				}
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

	// ===== Newsletter Form Handler =====
	document.addEventListener('DOMContentLoaded', function() {
		const newsletterForm = document.querySelector('.newsletter-input-group');
		
		if (newsletterForm) {
			newsletterForm.addEventListener('submit', function(e) {
				e.preventDefault();
				
				const emailInput = this.querySelector('.newsletter-input');
				const submitBtn = this.querySelector('.newsletter-btn');
				const email = emailInput.value;
				
				// Basic email validation
				if (!isValidEmail(email)) {
					showFormMessage(emailInput, 'Por favor, insira um email válido.', 'error');
					return;
				}
				
				// Disable button and show loading state
				submitBtn.disabled = true;
				submitBtn.textContent = 'Enviando...';
				
				// Simulate API call (replace with actual AJAX request)
				setTimeout(function() {
					showFormMessage(emailInput, 'Inscrição realizada com sucesso!', 'success');
					emailInput.value = '';
					submitBtn.disabled = false;
					submitBtn.textContent = 'Assinar';
				}, 1500);
			});
		}
	});
	
	function isValidEmail(email) {
		const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		return re.test(email);
	}
	
	function showFormMessage(input, message, type) {
		// Remove existing message
		const existingMsg = input.parentElement.querySelector('.form-message');
		if (existingMsg) {
			existingMsg.remove();
		}
		
		// Create new message
		const msg = document.createElement('span');
		msg.className = 'form-message form-message--' + type;
		msg.textContent = message;
		msg.style.cssText = 'display:block;margin-top:8px;font-size:0.8125rem;' + 
			(type === 'success' ? 'color:#0E6B3A;' : 'color:#dc3545;');
		
		input.parentElement.appendChild(msg);
		
		// Remove message after 5 seconds
		setTimeout(function() {
			msg.remove();
		}, 5000);
	}

	// ===== Back to Top Button (optional enhancement) =====
	function initBackToTop() {
		// Create back to top button if it doesn't exist
		if (!document.querySelector('.back-to-top')) {
			const btn = document.createElement('button');
			btn.className = 'back-to-top';
			btn.setAttribute('aria-label', 'Voltar ao topo');
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>';
			btn.style.cssText = 'position:fixed;bottom:90px;right:24px;width:44px;height:44px;' +
				'background:#0E6B3A;color:#fff;border:none;border-radius:50%;cursor:pointer;' +
				'opacity:0;visibility:hidden;transition:all 0.3s ease;z-index:998;' +
				'display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
			document.body.appendChild(btn);
			
			// Show/hide based on scroll
			window.addEventListener('scroll', function() {
				if (window.pageYOffset > 300) {
					btn.style.opacity = '1';
					btn.style.visibility = 'visible';
				} else {
					btn.style.opacity = '0';
					btn.style.visibility = 'hidden';
				}
			}, { passive: true });
			
			// Scroll to top on click
			btn.addEventListener('click', function() {
				window.scrollTo({
					top: 0,
					behavior: 'smooth'
				});
			});
		}
	}
	
	// Initialize back to top after DOM ready
	document.addEventListener('DOMContentLoaded', initBackToTop);

	// ===== Search Toggle (for mobile) =====
	function initSearchToggle() {
		const searchToggle = document.querySelector('.search-toggle');
		const searchForm = document.querySelector('.header-search');
		
		if (searchToggle && searchForm) {
			searchToggle.addEventListener('click', function() {
				searchForm.classList.toggle('active');
				this.classList.toggle('active');
				
				if (searchForm.classList.contains('active')) {
					searchForm.querySelector('input').focus();
				}
			});
		}
	}

	// ===== Expose functions globally if needed =====
	window.ConexaoPortal = {
		initMobileMenu: initMobileMenu,
		initStickyHeader: initStickyHeader,
		initLanguageSelector: initLanguageSelector
	};

})();