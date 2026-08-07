/**
 * Conexão BR Irlanda - Main JavaScript
 */
(function($) {
	'use strict';

	// Mobile menu toggle
	const menuToggle = document.querySelector('.menu-toggle');
	const navMenu = document.querySelector('.nav-menu');

	if (menuToggle && navMenu) {
		menuToggle.addEventListener('click', function() {
			navMenu.classList.toggle('active');
			menuToggle.classList.toggle('active');
		});
	}

	// Copy link button
	const copyButtons = document.querySelectorAll('.share-copy');
	copyButtons.forEach(function(button) {
		button.addEventListener('click', function() {
			const url = this.getAttribute('data-copy-url');
			if (navigator.clipboard) {
				navigator.clipboard.writeText(url).then(function() {
					const original = button.innerHTML;
					button.innerHTML = '✓';
					setTimeout(function() {
						button.innerHTML = original;
					}, 2000);
				});
			}
		});
	});

	// Smooth scroll for anchor links
	document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
		anchor.addEventListener('click', function(e) {
			const target = document.querySelector(this.getAttribute('href'));
			if (target) {
				e.preventDefault();
				target.scrollIntoView({ behavior: 'smooth' });
			}
		});
	});

	// Sticky header shadow on scroll
	const header = document.querySelector('.site-header');
	if (header) {
		window.addEventListener('scroll', function() {
			if (window.scrollY > 50) {
				header.classList.add('scrolled');
			} else {
				header.classList.remove('scrolled');
			}
		});
	}

	// Lazy load images
	if ('IntersectionObserver' in window) {
		const lazyImages = document.querySelectorAll('img[loading="lazy"]');
		const imageObserver = new IntersectionObserver(function(entries, observer) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					const img = entry.target;
					img.src = img.dataset.src || img.src;
					img.classList.add('loaded');
					observer.unobserve(img);
				}
			});
		});

		lazyImages.forEach(function(img) {
			imageObserver.observe(img);
		});
	}

})(jQuery);