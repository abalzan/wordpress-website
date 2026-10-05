/**
 * Event Importer admin UI enhancements.
 *
 * Adds loading states and duplicate-submission protection to the import
 * buttons on the Event Import dashboard and Event Sources pages.
 *
 * Also orchestrates the multi-file import workflow.
 *
 * ## Multi-file request flow
 *
 * The multi-file form used to rely on a native POST + 302 redirect to hand a
 * batch token back to the browser through the query string, and only then read
 * that token on the NEXT page load. That made the page visibly reload, lost
 * the selected files, and meant a single user activation produced one document
 * navigation before any per-file request was issued.
 *
 * The form now submits through the form's own `submit` event:
 *
 *   submit -> preventDefault() -> FormData (all selected files in ONE
 *   multipart body) -> one staging XHR -> one XHR per file -> one cleanup
 *   XHR -> results rendered in place. The URL never changes.
 *
 * The native POST path is deliberately KEPT: the hidden action input carries a
 * `conexao_multi_response=json` marker, and the server only returns JSON for
 * that marker. With JavaScript disabled the marker is absent, the server
 * redirects as before, and the importer still works. The client-side
 * interception is a usability fix, never a security boundary — the server
 * still enforces capability, nonce and batch-token validation on every
 * request.
 *
 * @package Conexao_Event_Importer
 */
(function () {
	'use strict';

	/* The multi-file form's action value. It is a hidden INPUT, not the form's
	 * action attribute, so it is the only reliable discriminator. */
	var MULTI_ACTION = 'conexao_import_events_multi';

	document.addEventListener('DOMContentLoaded', function () {
		initImportForms();
		bindMultiFileSubmit();
		initMultiFileImport();
	});

	function findSubmitButton(form) {
		return form.querySelector('button[type="submit"], input[type="submit"]');
	}

	/**
	 * Locate the multi-file import form.
	 *
	 * The form posts to admin-post.php and carries its action in a HIDDEN
	 * INPUT, not in the action attribute, so a `form[action*=...]` selector
	 * never matches it. The hidden action input is the stable discriminator:
	 * it is rendered by the plugin itself and is what the server dispatches on.
	 *
	 * @return {HTMLFormElement|null} The form, or null when absent.
	 */
	function findMultiFileForm() {
		var actionInputs = document.querySelectorAll(
			'form input[name="action"][value="' + MULTI_ACTION + '"]'
		);
		for (var i = 0; i < actionInputs.length; i++) {
			if (actionInputs[i].closest('form')) { return actionInputs[i].closest('form'); }
		}
		return null;
	}

	function initImportForms() {
		var forms = document.querySelectorAll('form[data-conexao-import-form]');
		forms.forEach(function (form) {
			// The multi-file form owns its own submit lifecycle (it must not
			// navigate). Binding the shared handler here as well would disable
			// its submit button before the multi-file handler runs, and would
			// leave a second, confusing "importing" status behind.
			if (isMultiFileForm(form)) { return; }
			form.addEventListener('submit', function (e) {
				handleImportSubmit(form, e);
			});
		});
	}

	function isMultiFileForm(form) {
		if (!form) { return false; }
		var hidden = form.querySelector('input[name="action"][value="' + MULTI_ACTION + '"]');
		return !!hidden;
	}

	function handleImportSubmit(form, e) {
		var submitBtn = findSubmitButton(form);
		if (!submitBtn) { return; }
		if (submitBtn.disabled) { e.preventDefault(); return; }
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
		var wrap = form.closest('.wrap');
		if (wrap) { wrap.classList.add('conexao-importing'); }
		var status = document.createElement('span');
		status.className = 'conexao-import-running';
		status.style.marginLeft = '10px';
		status.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>' +
			'<span class="conexao-import-running-text">' + conexaoEventImporter.i18n.importing + '</span>';
		submitBtn.parentNode.insertBefore(status, submitBtn.nextSibling);
		window.addEventListener('pageshow', function (event) {
			if (event.persisted) { restoreButton(submitBtn); }
		}, { once: true });
	}

	function restoreButton(btn) {
		btn.disabled = false;
		btn.classList.remove('disabled');
		if (btn.dataset.originalText) {
			btn.innerHTML = btn.dataset.originalText;
			delete btn.dataset.originalText;
		}
	}

	/**
	 * Resume a batch staged by the no-JavaScript (native POST) path.
	 *
	 * With JavaScript enabled the form never navigates, so this query-string
	 * path is only reached after the native fallback redirect. It is kept so
	 * the importer keeps working with JavaScript disabled, and it is a no-op
	 * when the batch parameters are absent.
	 */
	function initMultiFileImport() {
		var batchToken = getQueryParam('conexao_multi_batch');
		var fileCount  = parseInt(getQueryParam('conexao_multi_file_count'), 10);
		var strategy   = getQueryParam('conexao_multi_strategy') || 'update';
		if (!batchToken || isNaN(fileCount) || fileCount < 1) { return; }
		var multiForm = findMultiFileForm();
		if (!multiForm) { return; }
		startMultiFileImport(batchToken, fileCount, strategy, multiForm);
	}

	/**
	 * Bind the multi-file submit handler.
	 *
	 * A single delegated listener on the document is used rather than one
	 * listener per form: the markup is server-rendered and static here, but
	 * delegation keeps the binding correct if the form is ever re-rendered or
	 * moved, and it scopes strictly to the form carrying the multi-file action
	 * input, so no other admin form on the page is affected.
	 */
	function bindMultiFileSubmit() {
		document.addEventListener('submit', function (event) {
			var form = event.target;
			if (!isMultiFileForm(form)) { return; }
			handleMultiFileSubmit(form, event);
		});
	}

	/**
	 * Intercept the multi-file form's submit event.
	 *
	 * This is the fix for the reported defect. The form previously had NO
	 * submit handler, so the browser performed its default submission: a
	 * document-level multipart POST to admin-post.php, a 302 back to the
	 * importer page, and a full page reload before any per-file request was
	 * issued. The operator saw "the page refreshes and nothing happens".
	 *
	 * Handling the `submit` event (not a click handler) is deliberate: it also
	 * covers Enter-key submission from the file input and keyboard activation
	 * of the submit control, and it runs after native constraint validation, so
	 * a `required` file input is still enforced by the browser.
	 *
	 * @param {HTMLFormElement} form  The multi-file form.
	 * @param {Event}           event The submit event.
	 */
	function handleMultiFileSubmit(form, event) {
		// preventDefault() is the FIRST statement: any exception or early
		// return placed before it would let the browser fall through to native
		// submission and reproduce the defect exactly.
		event.preventDefault();

		if (isMultiFileBusy(form)) { return; }

		var fileInput = form.querySelector('input[type="file"][name="conexao_import_files[]"]');
		if (!fileInput || !fileInput.files || fileInput.files.length < 1) {
			renderMultiFileError(form, conexaoEventImporter.i18n.noFilesSelected);
			return;
		}

		var selectedCount = fileInput.files.length;
		var strategy = readStrategy(form);

		// FormData built from the live form carries every selected file under
		// the real field name, plus the nonce, the action and the strategy —
		// one multipart body, exactly as the native path would have sent.
		var formData = new FormData(form);
		formData.append('conexao_multi_response', 'json');

		var submittedCount = formData.getAll('conexao_import_files[]').length;
		if (submittedCount !== selectedCount) {
			// Never start an import we cannot account for: report truthfully
			// instead of silently importing a subset.
			renderMultiFileError(form, conexaoEventImporter.i18n.stagingFailed);
			return;
		}

		setMultiFileBusy(form, true, selectedCount);

		var xhr = new XMLHttpRequest();
		xhr.open('POST', conexaoEventImporter.ajaxUrl, true);
		xhr.onload = function () {
			var payload = null;
			try { payload = JSON.parse(xhr.responseText); } catch (e) { payload = null; }
			if (!payload || !payload.success) {
				renderMultiFileError(form, stageErrorMessage(payload));
				setMultiFileBusy(form, false);
				return;
			}
			startMultiFileImport(
				payload.batch_token,
				parseInt(payload.file_count, 10),
				strategy,
				form,
				payload.rejected
			);
		};
		xhr.onerror = function () {
			renderMultiFileError(form, conexaoEventImporter.i18n.networkError);
			setMultiFileBusy(form, false);
		};
		xhr.send(formData);
	}

	function isMultiFileBusy(form) {
		return form.getAttribute('data-conexao-multi-busy') === '1';
	}

	/**
	 * Toggle the processing state of the multi-file form.
	 *
	 * Disables the real submit control (whatever WordPress rendered), marks the
	 * form and the wrapper busy for assistive technology, and always restores
	 * them, so a failure never leaves the administrator locked out.
	 *
	 * @param {HTMLFormElement} form      The form.
	 * @param {boolean}         busy      True while processing.
	 * @param {number}          fileCount Selected file count, for the status text.
	 */
	function setMultiFileBusy(form, busy, fileCount) {
		var submitBtn = findSubmitButton(form);
		var wrap = form.closest('.wrap');
		removeMultiFileStatus(form);

		if (busy) {
			form.setAttribute('data-conexao-multi-busy', '1');
			form.setAttribute('aria-busy', 'true');
			if (wrap) { wrap.classList.add('conexao-importing'); }
			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.classList.add('disabled');
			}
			if (!submitBtn || !submitBtn.parentNode) { return; }
			var status = document.createElement('span');
			status.className = 'conexao-import-running';
			status.setAttribute('role', 'status');
			status.setAttribute('aria-live', 'polite');
			status.style.marginLeft = '10px';
			var spinner = document.createElement('span');
			spinner.className = 'spinner is-active';
			spinner.setAttribute('aria-hidden', 'true');
			spinner.style.cssText = 'float:none;margin:0 4px 0 0;vertical-align:middle;';
			var text = document.createElement('span');
			text.className = 'conexao-import-running-text';
			text.textContent = sprintf(conexaoEventImporter.i18n.stagingFiles, fileCount || 0);
			status.appendChild(spinner);
			status.appendChild(text);
			submitBtn.parentNode.insertBefore(status, submitBtn.nextSibling);
			return;
		}

		form.removeAttribute('data-conexao-multi-busy');
		form.removeAttribute('aria-busy');
		if (wrap) { wrap.classList.remove('conexao-importing'); }
		if (submitBtn) {
			submitBtn.disabled = false;
			submitBtn.classList.remove('disabled');
		}
	}

	function removeMultiFileStatus(form) {
		if (!form.parentNode) { return; }
		var nodes = form.parentNode.querySelectorAll('.conexao-import-running');
		Array.prototype.forEach.call(nodes, function (node) {
			if (node.closest && node.closest('form') === form) { node.remove(); }
		});
	}

	/**
	 * Show a truthful, non-technical error and keep the operator on the page.
	 *
	 * The message never contains a filesystem path, a batch token, a nonce or
	 * a stack trace: it is either a known, already-translated staging reason or
	 * a generic message.
	 *
	 * @param {HTMLFormElement} form    The form.
	 * @param {string}          message Human-readable reason.
	 */
	function renderMultiFileError(form, message) {
		var wrap = form.closest('.wrap') || form.parentNode;
		if (!wrap) { return; }
		removeMultiFileError(wrap);
		var notice = document.createElement('div');
		notice.className = 'notice notice-error conexao-multi-file-error';
		notice.setAttribute('role', 'alert');
		var p = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = conexaoEventImporter.i18n.multiFileError;
		p.appendChild(strong);
		p.appendChild(document.createTextNode(' '));
		p.appendChild(document.createTextNode(message || conexaoEventImporter.i18n.unknownFailure));
		notice.appendChild(p);
		wrap.insertBefore(notice, form);
	}

	function removeMultiFileError(wrap) {
		var existing = wrap.querySelectorAll('.conexao-multi-file-error');
		Array.prototype.forEach.call(existing, function (node) { node.remove(); });
	}

	/**
	 * Map a staging failure to a safe, truthful message.
	 *
	 * @param {Object|null} payload Parsed staging response, or null.
	 * @return {string} Message safe to show an administrator.
	 */
	function stageErrorMessage(payload) {
		var i18n = conexaoEventImporter.i18n;
		if (!payload) { return i18n.serverError; }
		var byCode = {};
		byCode.no_files = i18n.noFilesSelected;
		byCode.too_many = i18n.tooManyFiles;
		byCode.manifest = i18n.manifestInvalid;
		byCode.stage_failed = i18n.stagingFailed;
		if (payload.error && byCode[payload.error]) { return byCode[payload.error]; }
		return i18n.stagingFailed;
	}

	function readStrategy(form) {
		var select = form.querySelector('[name="conexao_multi_duplicate_strategy"]');
		return (select && select.value) ? select.value : 'update';
	}

	function startMultiFileImport(batchToken, fileCount, strategy, form, rejected) {
		var submitBtn = findSubmitButton(form);
		var wrap = form.closest('.wrap');
		form.style.display = 'none';
		var progressUI = createProgressUI(fileCount);
		form.parentNode.insertBefore(progressUI.root, form);
		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.classList.add('disabled');
		}
		if (wrap) { wrap.classList.add('conexao-importing'); }
		var state = {
			currentIndex: 0,
			fileCount: fileCount,
			batchToken: batchToken,
			strategy: strategy,
			results: [],
			stopped: false,
			rejected: (rejected && rejected.length) ? rejected : [],
			totalCreated: 0, totalUpdated: 0, totalSkipped: 0,
			totalMedia: 0, totalErrors: 0, totalDuration: 0, maxPeakMemory: 0
		};
		// Files PHP rejected before staging (oversized, partial) never reach a
		// per-file request, so they are reported once, truthfully, up front.
		renderRejectedFiles(state, wrap);
		processNextFile(state, progressUI, form, submitBtn, wrap);
	}

	/**
	 * Report files the server refused to stage.
	 *
	 * @param {Object} state Import state.
	 * @param {Element|null} wrap The admin wrapper.
	 */
	function renderRejectedFiles(state, wrap) {
		if (!state.rejected.length || !wrap) { return; }
		var notice = document.createElement('div');
		notice.className = 'notice notice-warning conexao-multi-file-rejected';
		var title = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = conexaoEventImporter.i18n.filesRejected;
		title.appendChild(strong);
		notice.appendChild(title);
		var list = document.createElement('ul');
		state.rejected.forEach(function (entry) {
			var li = document.createElement('li');
			li.textContent = sprintf(
				conexaoEventImporter.i18n.rejectedDetail,
				(entry && entry.filename) ? entry.filename : '?',
				(entry && entry.reason) ? entry.reason : conexaoEventImporter.i18n.unknownFailure
			);
			list.appendChild(li);
		});
		notice.appendChild(list);
		wrap.insertBefore(notice, state.progressRoot || wrap.firstChild);
	}

	function createProgressUI(fileCount) {
		var root = document.createElement('div');
		root.className = 'conexao-multi-import-progress';
		var header = document.createElement('h3');
		header.textContent = conexaoEventImporter.i18n.multiInProgress;
		root.appendChild(header);
		var statusLine = document.createElement('p');
		statusLine.className = 'conexao-multi-status';
		statusLine.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 8px 0 0;vertical-align:middle;"></span>' +
			'<span class="conexao-multi-status-text">' + conexaoEventImporter.i18n.preparing + '</span>';
		root.appendChild(statusLine);
		var partInfo = document.createElement('p');
		partInfo.className = 'conexao-multi-part-info';
		partInfo.textContent = sprintf(conexaoEventImporter.i18n.fileOfTotal, 1, fileCount);
		root.appendChild(partInfo);
		var fileList = document.createElement('ul');
		fileList.className = 'conexao-multi-file-list';
		for (var i = 0; i < fileCount; i++) {
			var li = document.createElement('li');
			li.className = 'conexao-multi-file-pending';
			li.id = 'conexao-multi-file-' + i;
			li.innerHTML = '<span class="conexao-multi-file-status">○</span> ' +
				'<span class="conexao-multi-file-label">' + sprintf(conexaoEventImporter.i18n.fileNumber, i + 1) + '</span>' +
				'<span class="conexao-multi-file-detail"></span>';
			fileList.appendChild(li);
		}
		root.appendChild(fileList);
		var aggregate = document.createElement('div');
		aggregate.className = 'conexao-multi-aggregate';
		aggregate.innerHTML = '<h4>' + conexaoEventImporter.i18n.summary + '</h4>' +
			'<ul class="conexao-import-stats">' +
			'<li>' + conexaoEventImporter.i18n.filesProcessed + ' <span class="conexao-multi-files-processed">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.filesImported + ' <span class="conexao-multi-files-imported">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.filesUnchanged + ' <span class="conexao-multi-files-unchanged">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.filesFailed + ' <span class="conexao-multi-files-failed">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalCreated + ' <span class="conexao-multi-total-created">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalUpdated + ' <span class="conexao-multi-total-updated">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalSkipped + ' <span class="conexao-multi-total-skipped">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalMedia + ' <span class="conexao-multi-total-media">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalErrors + ' <span class="conexao-multi-total-errors">0</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalDuration + ' <span class="conexao-multi-total-duration">0s</span></li>' +
			'<li>' + conexaoEventImporter.i18n.totalPeakMemory + ' <span class="conexao-multi-peak-memory">0 MB</span></li>' +
			'</ul>';
		root.appendChild(aggregate);
		var retryBtn = document.createElement('button');
		retryBtn.type = 'button';
		retryBtn.className = 'button conexao-multi-retry-btn';
		retryBtn.style.display = 'none';
		retryBtn.textContent = conexaoEventImporter.i18n.retry;
		retryBtn.addEventListener('click', function () { location.reload(); });
		root.appendChild(retryBtn);
		return {
			root: root, statusLine: statusLine,
			statusText: statusLine.querySelector('.conexao-multi-status-text'),
			partInfo: partInfo, fileList: fileList,
			filesProcessed: aggregate.querySelector('.conexao-multi-files-processed'),
			filesImported: aggregate.querySelector('.conexao-multi-files-imported'),
			filesUnchanged: aggregate.querySelector('.conexao-multi-files-unchanged'),
			filesFailed: aggregate.querySelector('.conexao-multi-files-failed'),
			totalCreated: aggregate.querySelector('.conexao-multi-total-created'),
			totalUpdated: aggregate.querySelector('.conexao-multi-total-updated'),
			totalSkipped: aggregate.querySelector('.conexao-multi-total-skipped'),
			totalMedia: aggregate.querySelector('.conexao-multi-total-media'),
			totalErrors: aggregate.querySelector('.conexao-multi-total-errors'),
			totalDuration: aggregate.querySelector('.conexao-multi-total-duration'),
			peakMemory: aggregate.querySelector('.conexao-multi-peak-memory'),
			retryBtn: retryBtn
		};
	}

	function processNextFile(state, progressUI, form, submitBtn, wrap) {
		if (state.stopped || state.currentIndex >= state.fileCount) {
			finishMultiFileImport(state, progressUI, form, submitBtn, wrap);
			return;
		}
		var index = state.currentIndex;
		var fileItem = document.getElementById('conexao-multi-file-' + index);
		progressUI.statusText.textContent = sprintf(conexaoEventImporter.i18n.importingFile, index + 1, state.fileCount);
		progressUI.partInfo.textContent = sprintf(conexaoEventImporter.i18n.fileOfTotal, index + 1, state.fileCount);
		if (fileItem) {
			fileItem.className = 'conexao-multi-file-processing';
			fileItem.querySelector('.conexao-multi-file-status').textContent = '●';
			fileItem.querySelector('.conexao-multi-file-detail').textContent = conexaoEventImporter.i18n.importingShort;
		}
		importSingleFile(state, progressUI, form, submitBtn, wrap, index, fileItem);
	}

	function importSingleFile(state, progressUI, form, submitBtn, wrap, index, fileItem) {
		var formData = new FormData();
		formData.append('action', 'conexao_import_events_multi_file');
		formData.append('batch_token', state.batchToken);
		formData.append('file_index', index);
		formData.append('conexao_multi_duplicate_strategy', state.strategy);
		formData.append('conexao_import_multi_file_nonce', conexaoEventImporter.nonces.importFile);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', conexaoEventImporter.ajaxUrl, true);
		xhr.onload = function () {
			var result;
			try { result = JSON.parse(xhr.responseText); } catch (e) {
				result = { success: false, status: 'failed', errors: [conexaoEventImporter.i18n.serverError] };
			}
			handleFileResult(result, state, progressUI, form, submitBtn, wrap, index, fileItem);
		};
		xhr.onerror = function () {
			var result = { success: false, status: 'failed', errors: [conexaoEventImporter.i18n.networkError] };
			handleFileResult(result, state, progressUI, form, submitBtn, wrap, index, fileItem);
		};
		xhr.send(formData);
	}

	function handleFileResult(result, state, progressUI, form, submitBtn, wrap, index, fileItem) {
		state.results[index] = result;
		state.totalCreated += result.created || 0;
		state.totalUpdated += result.updated || 0;
		state.totalSkipped += result.skipped || 0;
		state.totalMedia += result.media_count || 0;
		state.totalErrors += (result.errors ? result.errors.length : 0);
		state.totalDuration += result.duration || 0;
		if (result.peak_memory && result.peak_memory > state.maxPeakMemory) {
			state.maxPeakMemory = result.peak_memory;
		}
		progressUI.totalCreated.textContent = state.totalCreated;
		progressUI.totalUpdated.textContent = state.totalUpdated;
		progressUI.totalSkipped.textContent = state.totalSkipped;
		progressUI.totalMedia.textContent = state.totalMedia;
		progressUI.totalErrors.textContent = state.totalErrors;
		progressUI.totalDuration.textContent = state.totalDuration.toFixed(1) + 's';
		progressUI.peakMemory.textContent = formatBytes(state.maxPeakMemory);
		if (fileItem) {
			if (result.success) {
				fileItem.className = 'conexao-multi-file-success';
				fileItem.querySelector('.conexao-multi-file-status').textContent = '✓';
				fileItem.querySelector('.conexao-multi-file-detail').textContent =
					sprintf(conexaoEventImporter.i18n.fileSucceeded, result.filename || sprintf(conexaoEventImporter.i18n.fileNumber, index + 1), result.created || 0, result.updated || 0);
			} else {
				fileItem.className = 'conexao-multi-file-failed';
				fileItem.querySelector('.conexao-multi-file-status').textContent = '✗';
				fileItem.querySelector('.conexao-multi-file-detail').textContent =
					sprintf(conexaoEventImporter.i18n.fileFailedDetail, result.filename || sprintf(conexaoEventImporter.i18n.fileNumber, index + 1), result.errors && result.errors.length ? result.errors.join('; ') : conexaoEventImporter.i18n.unknownFailure);
			}
		}
		state.currentIndex++;
		if (!result.success) {
			state.stopped = true;
			for (var i = state.currentIndex; i < state.fileCount; i++) {
				var remainingItem = document.getElementById('conexao-multi-file-' + i);
				if (remainingItem) {
					remainingItem.className = 'conexao-multi-file-pending';
					remainingItem.querySelector('.conexao-multi-file-status').textContent = '○';
					remainingItem.querySelector('.conexao-multi-file-detail').textContent = conexaoEventImporter.i18n.notProcessed;
				}
			}
			finishMultiFileImport(state, progressUI, form, submitBtn, wrap);
			return;
		}
		processNextFile(state, progressUI, form, submitBtn, wrap);
	}

	function finishMultiFileImport(state, progressUI, form, submitBtn, wrap) {
		if (wrap) { wrap.classList.remove('conexao-importing'); }
		var spinner = progressUI.statusLine.querySelector('.spinner');
		if (spinner) { spinner.remove(); }
		if (state.stopped) {
			progressUI.statusText.textContent = conexaoEventImporter.i18n.importStopped;
			progressUI.root.classList.add('conexao-multi-import-stopped');
			progressUI.retryBtn.style.display = 'inline-block';
		} else {
			progressUI.statusText.textContent = conexaoEventImporter.i18n.importComplete;
			progressUI.root.classList.add('conexao-multi-import-complete');
		}
		renderMultiFileSummary(state, progressUI);
		cleanupBatch(state.batchToken);
		// Stay on the importer page and hand the form back: the administrator
		// must be able to run another batch without a reload, and a stalled
		// request must never leave the control permanently disabled.
		form.style.display = '';
		setMultiFileBusy(form, false);
		if (submitBtn) {
			submitBtn.disabled = false;
			submitBtn.classList.remove('disabled');
		}
		form.reset();
	}

	/**
	 * Render the aggregate, truthful result summary.
	 *
	 * @param {Object} state       Import state.
	 * @param {Object} progressUI  Progress UI handles.
	 */
	function renderMultiFileSummary(state, progressUI) {
		var processed = state.results.filter(Boolean).length;
		var imported = state.results.reduce(function (sum, r) {
			return sum + (r && r.success ? ((r.created || 0) + (r.updated || 0)) : 0);
		}, 0);
		var failed = state.results.filter(function (r) { return r && !r.success; }).length;
		var unchanged = state.totalSkipped;

		progressUI.totalCreated.textContent = state.totalCreated;
		progressUI.totalUpdated.textContent = state.totalUpdated;
		progressUI.totalSkipped.textContent = state.totalSkipped;
		progressUI.totalMedia.textContent = state.totalMedia;
		progressUI.totalErrors.textContent = state.totalErrors;
		progressUI.totalDuration.textContent = state.totalDuration.toFixed(1) + 's';
		progressUI.peakMemory.textContent = formatBytes(state.maxPeakMemory);

		if (!progressUI.filesProcessed) { return; }
		progressUI.filesProcessed.textContent = processed;
		progressUI.filesImported.textContent = imported;
		progressUI.filesUnchanged.textContent = unchanged;
		progressUI.filesFailed.textContent = failed;
	}

	function cleanupBatch(batchToken) {
		var formData = new FormData();
		formData.append('action', 'conexao_import_events_multi_cleanup');
		formData.append('batch_token', batchToken);
		formData.append('conexao_import_multi_cleanup_nonce', conexaoEventImporter.nonces.cleanup);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', conexaoEventImporter.ajaxUrl, true);
		xhr.send(formData);
	}

	function getQueryParam(name) {
		var match = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
		return match ? decodeURIComponent(match[1]) : null;
	}

	function formatBytes(bytes) {
		if (!bytes || bytes <= 0) { return '0 MB'; }
		var units = ['B', 'KB', 'MB', 'GB'];
		var unitIndex = 0;
		var value = bytes;
		while (value >= 1024 && unitIndex < units.length - 1) {
			value /= 1024;
			unitIndex++;
		}
		return value.toFixed(1) + ' ' + units[unitIndex];
	}

	/**
	 * Minimal printf for the translated progress strings.
	 *
	 * Supports both positional (`%1$s`, `%2$d`) and sequential (`%s`, `%d`)
	 * specifiers. Positional support is required because the plugin's
	 * translators use positional placeholders; without it the per-file result
	 * lines rendered the raw `%1$s — %2$d created` template instead of the
	 * real counts.
	 *
	 * @param {string} str Format string.
	 * @return {string} Formatted string.
	 */
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(str).replace(/%(\d+)\$[sd]|%[sd]/g, function (match, position) {
			var value;
			if (position) {
				value = args[parseInt(position, 10) - 1];
			} else {
				value = args[next];
				next++;
			}
			return value === undefined ? match : String(value);
		});
	}
})();
