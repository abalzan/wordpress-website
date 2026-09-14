/**
 * Event Importer admin UI enhancements.
 *
 * Adds loading states and duplicate-submission protection to the import
 * buttons on the Event Import dashboard and Event Sources pages.
 *
 * Also orchestrates the multi-file import workflow: when a batch token is
 * present in the query string, sequentially imports each file through
 * individual AJAX requests (one HTTP request per file).
 *
 * @package Conexao_Event_Importer
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		initImportForms();
		initMultiFileImport();
	});

	function initImportForms() {
		var forms = document.querySelectorAll('form[data-conexao-import-form]');
		forms.forEach(function (form) {
			form.addEventListener('submit', function (e) {
				handleImportSubmit(form, e);
			});
		});
	}

	function handleImportSubmit(form, e) {
		var submitBtn = form.querySelector('button[type="submit"]');
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

	function initMultiFileImport() {
		var batchToken = getQueryParam('conexao_multi_batch');
		var fileCount  = parseInt(getQueryParam('conexao_multi_file_count'), 10);
		var strategy   = getQueryParam('conexao_multi_strategy') || 'update';
		if (!batchToken || isNaN(fileCount) || fileCount < 1) { return; }
		var multiForm = document.querySelector('form[action*="conexao_import_events_multi"]');
		if (!multiForm) { return; }
		startMultiFileImport(batchToken, fileCount, strategy, multiForm);
	}

	function startMultiFileImport(batchToken, fileCount, strategy, form) {
		var submitBtn = form.querySelector('button[type="submit"]');
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
			totalCreated: 0, totalUpdated: 0, totalSkipped: 0,
			totalMedia: 0, totalErrors: 0, totalDuration: 0, maxPeakMemory: 0
		};
		processNextFile(state, progressUI, form, submitBtn, wrap);
	}

	function createProgressUI(fileCount) {
		var root = document.createElement('div');
		root.className = 'conexao-multi-import-progress';
		var header = document.createElement('h3');
		header.textContent = 'Importação em andamento';
		root.appendChild(header);
		var statusLine = document.createElement('p');
		statusLine.className = 'conexao-multi-status';
		statusLine.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 8px 0 0;vertical-align:middle;"></span>' +
			'<span class="conexao-multi-status-text">A preparar…</span>';
		root.appendChild(statusLine);
		var partInfo = document.createElement('p');
		partInfo.className = 'conexao-multi-part-info';
		partInfo.textContent = 'Parte 1 de ' + fileCount;
		root.appendChild(partInfo);
		var fileList = document.createElement('ul');
		fileList.className = 'conexao-multi-file-list';
		for (var i = 0; i < fileCount; i++) {
			var li = document.createElement('li');
			li.className = 'conexao-multi-file-pending';
			li.id = 'conexao-multi-file-' + i;
			li.innerHTML = '<span class="conexao-multi-file-status">○</span> ' +
				'<span class="conexao-multi-file-label">Parte ' + (i + 1) + '</span>' +
				'<span class="conexao-multi-file-detail"></span>';
			fileList.appendChild(li);
		}
		root.appendChild(fileList);
		var aggregate = document.createElement('div');
		aggregate.className = 'conexao-multi-aggregate';
		aggregate.innerHTML = '<h4>Resumo</h4>' +
			'<ul class="conexao-import-stats">' +
			'<li>Eventos criados: <span class="conexao-multi-total-created">0</span></li>' +
			'<li>Eventos atualizados: <span class="conexao-multi-total-updated">0</span></li>' +
			'<li>Eventos ignorados: <span class="conexao-multi-total-skipped">0</span></li>' +
			'<li>Mídia processada: <span class="conexao-multi-total-media">0</span></li>' +
			'<li>Erros: <span class="conexao-multi-total-errors">0</span></li>' +
			'<li>Duração total: <span class="conexao-multi-total-duration">0s</span></li>' +
			'<li>Pico de memória: <span class="conexao-multi-peak-memory">0 MB</span></li>' +
			'</ul>';
		root.appendChild(aggregate);
		var retryBtn = document.createElement('button');
		retryBtn.type = 'button';
		retryBtn.className = 'button conexao-multi-retry-btn';
		retryBtn.style.display = 'none';
		retryBtn.textContent = 'Tentar novamente';
		retryBtn.addEventListener('click', function () { location.reload(); });
		root.appendChild(retryBtn);
		return {
			root: root, statusLine: statusLine,
			statusText: statusLine.querySelector('.conexao-multi-status-text'),
			partInfo: partInfo, fileList: fileList,
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
		progressUI.partInfo.textContent = 'Parte ' + (index + 1) + ' de ' + state.fileCount;
		if (fileItem) {
			fileItem.className = 'conexao-multi-file-processing';
			fileItem.querySelector('.conexao-multi-file-status').textContent = '●';
			fileItem.querySelector('.conexao-multi-file-detail').textContent = 'Importando...';
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
					(result.filename || 'Parte ' + (index + 1)) + ' — ' +
					(result.created || 0) + ' criados, ' + (result.updated || 0) + ' atualizados';
			} else {
				fileItem.className = 'conexao-multi-file-failed';
				fileItem.querySelector('.conexao-multi-file-status').textContent = '✗';
				fileItem.querySelector('.conexao-multi-file-detail').textContent =
					(result.filename || 'Parte ' + (index + 1)) + ' — ' +
					(result.errors && result.errors.length ? result.errors.join('; ') : 'Falha desconhecida');
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
					remainingItem.querySelector('.conexao-multi-file-detail').textContent = 'Não processado';
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
		cleanupBatch(state.batchToken);
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

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		return str.replace(/%\d*[sd]/g, function (match) {
			var value = args.shift();
			return String(value);
		});
	}
})();
