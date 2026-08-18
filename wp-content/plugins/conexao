/**
 * Event Importer admin UI enhancements.
 *
 * Adds loading states and duplicate-submission protection to the import
 * buttons on the Event Import dashboard and Event Sources pages.
 *
 * @package Conexao_Event_Importer
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initImportForms();
	});

	/**
	 * Attach submit handlers to all import forms.
	 */
	function initImportForms() {
		var forms = document.querySelectorAll(
			'form[data-conexao-import-form]'
		);

		forms.forEach(function (form) {
			form.addEventListener('submit', function (e) {
				handleImportSubmit(form, e);
			});
		});
	}

	/**
	 * Handle an import form submission: disable the button, show a spinner,
	 * and prevent double-submission.
	 *
	 * @param {HTMLFormElement} form The form being submitted.
	 * @param {Event} e Submit event.
	 */
	function handleImportSubmit(form, e) {
		var submitBtn = form.querySelector('button[type="submit"]');
		if (!submitBtn) {
			return;
		}

		// Prevent double-submission.
		if (submitBtn.disabled) {
			e.preventDefault();
			return;
		}

		// Disable the button and show a spinner.
		submitBtn.disabled = true;
		submitBtn.classList.add('disabled');
		submitBtn.dataset.originalText = submitBtn.innerHTML;

		var spinner = document.createElement('span');
		spinner.className = 'spinner is-active';
		spinner.setAttribute('aria-hidden', 'true');
		spinner.style.float = 'none';
		spinner.style.margin = '0 0 0 8px';
		spinner.style.verticalAlign = 'middle';

		submitBtn.appendChild(spinner);

		// Add a class to the wrap for potential CSS hooks.
		var wrap = form.closest('.wrap');
		if (wrap) {
			wrap.classList.add('conexao-importing');
		}

		// Show a status message near the button.
		var status = document.createElement('span');
		status.className = 'conexao-import-running';
		status.style.marginLeft = '10px';
		status.innerHTML =
			'<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>' +
			'<span class="conexao-import-running-text">' +
			conexaoEventImporter.i18n.importing +
			'</span>';
		submitBtn.parentNode.insertBefore(status, submitBtn.nextSibling);

		// Re-enable the button if the page is restored from bfcache.
		window.addEventListener(
			'pageshow',
			function (event) {
				if (event.persisted) {
					restoreButton(submitBtn);
				}
			},
			{ once: true }
		);
	}

	/**
	 * Restore a submit button to its original state.
	 *
	 * @param {HTMLButtonElement} btn The button to restore.
	 */
	function restoreButton(btn) {
		btn.disabled = false;
		btn.classList.remove('disabled');
		if (btn.dataset.originalText) {
			btn.innerHTML = btn.dataset.originalText;
			delete btn.dataset.originalText;
		}
	}
})();