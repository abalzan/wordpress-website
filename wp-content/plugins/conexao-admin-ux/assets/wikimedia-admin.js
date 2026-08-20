/**
 * Wikimedia Commons search + approval UI for the Leisure Images admin page.
 *
 * Allows the admin to search Wikimedia Commons for a location, preview results
 * with author/license info, approve a candidate (which imports it into the
 * Media Library and fills in the attribution fields), or reject it.
 *
 * Requires conexaoWikimedia localised data (ajax_url, nonce, i18n).
 */
(function ($) {
	'use strict';

	var i18n = window.conexaoWikimedia && window.conexaoWikimedia.i18n ? window.conexaoWikimedia.i18n : {};

	function renderCandidates(candidates) {
		var html = '<div class="conexao-wikimedia-candidates">';
		if (!candidates || !candidates.length) {
			html += '<p class="conexao-muted">' + (i18n.no_results || 'Nenhuma imagem com licença adequada encontrada.') + '</p>';
			html += '</div>';
			return html;
		}

		$.each(candidates, function (i, c) {
			var licenseClass = 'license-p' + c.license_priority;
			var priorityLabel = c.license_priority === 1 ? (i18n.pd || 'Domínio Público') : c.license_priority === 2 ? (i18n.by || 'CC BY') : (i18n.bysa || 'CC BY-SA');

			html += '<div class="conexao-wikimedia-candidate" data-file-title="' + escapeAttr(c.file_title) + '">';
			html += '<img src="' + escapeAttr(c.image_url) + '" alt="' + escapeAttr(i18n.preview_of || 'Prévia de ') + escapeAttr(c.filename) + '" class="candidate-img">';
			html += '<div class="candidate-info">';
			html += '<div class="candidate-title">' + escapeHtml(c.filename) + '</div>';
			html += '<div class="candidate-meta"><strong>' + (i18n.author || 'Autor:') + '</strong> ' + escapeHtml(c.author || (i18n.unknown || 'Desconhecido')) + '</div>';
			html += '<div class="candidate-meta"><strong>' + (i18n.license || 'Licença:') + '</strong> <span class="' + licenseClass + '">' + escapeHtml(c.license_label) + '</span> (' + escapeHtml(priorityLabel) + ')</div>';
			html += '<div class="candidate-meta"><strong>' + (i18n.size || 'Tamanho:') + '</strong> ' + c.image_width + '×' + c.image_height + '</div>';
			html += '<div class="candidate-meta"><strong>' + (i18n.source || 'Fonte:') + '</strong> <a href="' + escapeAttr(c.page_url) + '" target="_blank" rel="noopener">' + (i18n.view_on || 'Ver no Wikimedia') + ' ↗</a></div>';
			html += '</div>';
			html += '<div class="candidate-actions">';
			html += '<button type="button" class="button button-primary button-small approve-btn" data-file-title="' + escapeAttr(c.file_title) + '">' + (i18n.approve || 'Aprovar e importar') + '</button>';
			html += '<button type="button" class="button button-small reject-btn" data-file-title="' + escapeAttr(c.file_title) + '">' + (i18n.reject || 'Rejeitar') + '</button>';
			html += '</div>';
			html += '</div>';
		});
		html += '</div>';
		return html;
	}

	function escapeHtml(str) {
		if (!str) return '';
		return String(str)
			.replace(/&/g, '&')
			.replace(/</g, '<')
			.replace(/>/g, '>')
			.replace(/"/g, '"');
	}

	function escapeAttr(str) {
		return escapeHtml(str);
	}

	// Global search box.
	function initGlobalSearch() {
		var $btn = $('#conexao-wikimedia-search-btn');
		var $input = $('#conexao-wikimedia-search');
		var $results = $('#conexao-wikimedia-results');

		$btn.on('click', function () {
			var query = $input.val().trim();
			if (!query) return;
			searchAndRender(query, $results, null);
		});

		$input.on('keypress', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				$btn.trigger('click');
			}
		});

		function searchAndRender(query, $target, postId) {
			$target.html('<p class="conexao-muted">' + (i18n.searching || 'Buscando...') + '</p>');
			$target.data('post-id', postId);

			$.ajax({
				url: window.conexaoWikimedia.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'conexao_leisure_wiki_search',
					nonce: window.conexaoWikimedia.nonce,
					query: query
				},
				success: function (resp) {
					if (resp.success && resp.data.candidates.length) {
						$target.html(renderCandidates(resp.data.candidates));
					} else {
						$target.html('<p class="conexao-muted">' + (i18n.no_results || 'Nenhuma imagem com licença adequada encontrada.') + '</p>');
					}
				},
				error: function () {
					$target.html('<p class="error">' + (i18n.error || 'Erro na busca.') + '</p>');
				}
			});
		}
	}

	// Per-row "Buscar no Wikimedia" button.
	function initRowLookups() {
		$('.conexao-wikimedia-lookup').on('click', function () {
			var $btn = $(this);
			var postId = $btn.data('post-id');
			var query = $btn.data('query');
			var $row = $btn.closest('tr.conexao-leisure-row');
			var $results = $('<div class="conexao-wikimedia-row-results"></div>');

			// Remove any existing results from this row.
			$row.find('.conexao-wikimedia-row-results').remove();

			if ($row.find('.conexao-wikimedia-modal').length) {
				return;
			}

			$btn.prop('disabled', true).text(i18n.searching || 'Buscando...');
			$row.append($results);

			$.ajax({
				url: window.conexaoWikimedia.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'conexao_leisure_wiki_search',
					nonce: window.conexaoWikimedia.nonce,
					query: query
				},
				success: function (resp) {
					$btn.prop('disabled', false).text($btn.text().replace(i18n.searching || '', '').trim() || 'Buscar no Wikimedia');

					if (resp.success && resp.data.candidates && resp.data.candidates.length) {
						$results.html(renderCandidatesWithApprove(resp.data.candidates, postId, $row));
					} else {
						$results.html('<p class="conexao-muted" style="margin:6px 0;">' + (i18n.no_results || 'Nenhuma imagem com licença adequada encontrada.') + '</p>');
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Buscar no Wikimedia');
					$results.html('<p class="error">' + (i18n.error || 'Erro na busca.') + '</p>');
				}
			});
		});
	}

	function renderCandidatesWithApprove(candidates, postId, $row) {
		var html = '<div class="conexao-wikimedia-candidates">' + renderCandidates(candidates).replace('<div class="conexao-wikimedia-candidates">', '').replace('</div>', '') + '</div>';

		// Wire up approve buttons.
		function wireApprove() {
			$row.find('.approve-btn').off('click').on('click', function () {
				var fileTitle = $(this).data('file-title');
				if (!confirm(i18n.confirm_import || 'Importar esta imagem?')) return;

				var candidate = null;
				$.each(candidates, function (i, c) {
					if (c.file_title === fileTitle) { candidate = c; return false; }
				});

				if (!candidate) return;

				// Fetch full file info with download URL via the same endpoint.
				// Actually we already have image_url — call a side-load endpoint.
				var $approveBtn = $(this);
				$approveBtn.prop('disabled', true).text(i18n.importing || 'Importando...');

				$.ajax({
					url: window.conexaoWikimedia.ajax_url,
					method: 'POST',
					dataType: 'json',
					data: {
						action: 'conexao_leisure_wiki_import',
						nonce: window.conexaoWikimedia.nonce,
						post_id: postId,
						file_title: fileTitle,
					},
					success: function (resp) {
						if (resp.success) {
							$row.find('.conexao-wikimedia-row-results').html(
								'<p class="notice notice-success" style="margin:6px 0;">' +
								(i18n.imported || 'Imagem importada e associada com sucesso!') +
								'</p>'
							);
							// Reload the row's image info.
							location.reload();
						} else {
							$row.find('.conexao-wikimedia-row-results').html(
								'<p class="error" style="margin:6px 0;">' +
								escapeHtml(resp.data && resp.data.message ? resp.data.message : (i18n.import_error || 'Erro ao importar.')) +
								'</p>'
							);
							$approveBtn.prop('disabled', false).text(i18n.approve || 'Aprovar e importar');
						}
					},
					error: function () {
						$approveBtn.prop('disabled', false).text(i18n.approve || 'Aprovar e importar');
						$row.find('.conexao-wikimedia-row-results').html('<p class="error">' + (i18n.import_error || 'Erro ao importar.') + '</p>');
					}
				});
			});
		}

		function wireReject() {
			$row.find('.reject-btn').off('click').on('click', function () {
				$(this).closest('.conexao-wikimedia-candidate').hide();
			});
		}

		setTimeout(function () { wireApprove(); wireReject(); }, 0);

		return html;
	}

	$(document).ready(function () {
		if (window.conexaoWikimedia) {
			initGlobalSearch();
			initRowLookups();
		}
	});

})(jQuery);
