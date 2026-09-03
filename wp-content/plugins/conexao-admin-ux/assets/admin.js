/**
 * Conexão BR Irlanda — Admin UX
 * Media picker, town autocomplete, county→town dependency, confirm dialogs.
 */
(function ($) {
	'use strict';

	// Confirmation prompts.
	$(document).on('click', '.conexao-delete-link', function (e) {
		var msg = (window.ConexaoAdminUx && window.ConexaoAdminUx.confirmDelete) || 'Excluir permanentemente? Esta ação não pode ser desfeita.';
		if (!window.confirm(msg)) e.preventDefault();
	});

	$(document).on('click', '.conexao-archive-link', function (e) {
		var msg = (window.ConexaoAdminUx && window.ConexaoAdminUx.confirmArchive) || 'Arquivar este conteúdo? Ele deixará de aparecer no site público.';
		if (!window.confirm(msg)) e.preventDefault();
	});

	// Media library picker.
	$(document).on('click', '.conexao-media-choose', function (e) {
		e.preventDefault();
		var picker = $(this).closest('.conexao-media-picker');
		var input = picker.find('.conexao-media-value');
		var preview = picker.find('.conexao-media-preview');
		var removeBtn = picker.find('.conexao-media-remove');

		if (picker.data('frame')) {
			picker.data('frame').open();
			return;
		}

		var frame = wp.media({
			title: 'Selecionar imagem',
			button: { text: 'Usar esta imagem' },
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var selected = frame.state().get('selection').first();
			if (!selected) return;
			var attachment = selected.toJSON();
			// The attachment ID is the value persisted with the post; without
			// it there is nothing to save, so leave the field untouched.
			if (!attachment || !attachment.id) return;
			input.val(attachment.id);
			var sizes = attachment.sizes || {};
			var img = (sizes.medium && sizes.medium.url) || (sizes.full && sizes.full.url) || attachment.url;
			preview.html('<img src="' + img + '" alt="" />').addClass('has-image');
			removeBtn.show();
		});

		picker.data('frame', frame);
		frame.open();
	});

	$(document).on('click', '.conexao-media-remove', function (e) {
		e.preventDefault();
		var picker = $(this).closest('.conexao-media-picker');
		picker.find('.conexao-media-value').val('');
		picker.find('.conexao-media-preview').empty().removeClass('has-image')
			.html('<span class="conexao-media-placeholder">Nenhuma imagem selecionada</span>');
		$(this).hide();
	});

	// County → Town dependency: Laois shows a datalist of known towns.
	var laoisTowns = (window.ConexaoAdminUx && window.ConexaoAdminUx.laoisTowns) || [];

	function attachCountyDependency() {
		$('.conexao-field--select select').each(function () {
			var select = $(this);
			var section = select.closest('.conexao-section');
			if (!section.length) return;

			select.on('change', function () {
				var isLaois = 'Laois' === select.val();
				section.find('.conexao-town-input').each(function () {
					var townInput = $(this);
					if (isLaois) {
						var listId = townInput.attr('id') + '-laois-list';
						if (!document.getElementById(listId)) {
							var dl = $('<datalist id="' + listId + '"></datalist>');
							$.each(laoisTowns, function (i, town) {
								dl.append('<option value="' + town + '"></option>');
							});
							$('body').append(dl);
						}
						townInput.attr('list', listId).attr('placeholder', 'Selecione uma cidade de Laois');
					} else {
						townInput.removeAttr('list').attr('placeholder', '');
					}
				});
			});
		});
	}

	// Town autocomplete with keyboard navigation.
	function attachTownAutocomplete() {
		$('.conexao-town-input').each(function () {
			var input = $(this);
			var container = input.parent();
			var suggestions = container.find('.conexao-town-suggestions');
			var highlighted = -1;

			if (!suggestions.length) {
				suggestions = $('<div class="conexao-town-suggestions"></div>');
				container.append(suggestions);
			}

			function showMatches() {
				var query = input.val().toLowerCase().trim();
				if (!query) {
					suggestions.removeClass('is-visible').empty();
					return;
				}
				var matches = laoisTowns.filter(function (t) {
					return t.toLowerCase().indexOf(query) !== -1;
				});
				if (!matches.length) {
					suggestions.removeClass('is-visible').empty();
					return;
				}
				suggestions.empty();
				$.each(matches.slice(0, 8), function (i, town) {
					var item = $('<div class="conexao-town-suggestion" role="option">' + town + '</div>');
					item.on('mousedown', function (e) {
						e.preventDefault();
						input.val(town);
						suggestions.removeClass('is-visible').empty();
					});
					suggestions.append(item);
				});
				suggestions.addClass('is-visible');
			}

			input.on('input focus', showMatches);
			input.on('keydown', function (e) {
				var items = suggestions.find('.conexao-town-suggestion');
				if (!items.length) return;
				if (e.key === 'ArrowDown') {
					e.preventDefault();
					highlighted = (highlighted + 1) % items.length;
					items.removeClass('is-highlighted').eq(highlighted).addClass('is-highlighted');
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					highlighted = (highlighted - 1 + items.length) % items.length;
					items.removeClass('is-highlighted').eq(highlighted).addClass('is-highlighted');
				} else if (e.key === 'Enter' || e.key === 'Tab') {
					if (highlighted >= 0 && items.eq(highlighted).length) {
						e.preventDefault();
						input.val(items.eq(highlighted).text());
						suggestions.removeClass('is-visible').empty();
						highlighted = -1;
					}
				}
			});

			$(document).on('click', function (e) {
				if (!$(e.target).closest('.conexao-field').length) {
					suggestions.removeClass('is-visible');
				}
			});
		});
	}

	// ------------------------------------------------------------------
	// Apoiador contacts repeater (Contatos).
	// Rows are rendered server-side; JS only adds (from the inline
	// template), removes and reorders them. The save handler iterates
	// rows in submission order, so indexes never need renumbering.
	// ------------------------------------------------------------------

	function updateContactsEmptyState(wrap) {
		wrap.find('.conexao-contacts-empty').prop('hidden', wrap.find('.conexao-contact-row').length > 0);
	}

	function applyContactTypeHints(row) {
		var type = row.find('.conexao-contact-type').val();
		var input = row.find('.conexao-contact-url');
		if ('email' === type) {
			input.attr({ placeholder: 'nome@exemplo.com', inputmode: 'email' });
		} else if ('whatsapp' === type) {
			input.attr({ placeholder: 'https://wa.me/353… ou número com DDI', inputmode: 'url' });
		} else {
			input.attr({ placeholder: 'https://', inputmode: 'url' });
		}
	}

	$(document).on('click', '.conexao-contacts-add', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.conexao-contacts');
		// .text() is the documented way to read <script> contents; .html()
		// can behave inconsistently across jQuery versions for script nodes.
		var template = $.trim(wrap.find('.conexao-contacts-template').text() || '');
		if (!template) {
			return;
		}
		var next = parseInt(wrap.data('next-index'), 10);
		if (isNaN(next)) {
			next = wrap.find('.conexao-contact-row').length;
		}
		wrap.find('.conexao-contacts-rows').append(template.replace(/__INDEX__/g, next));
		wrap.data('next-index', next + 1);
		updateContactsEmptyState(wrap);
		wrap.find('.conexao-contact-row').last().find('.conexao-contact-type').trigger('focus');
	});

	$(document).on('click', '.conexao-contact-remove', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.conexao-contacts');
		$(this).closest('.conexao-contact-row').remove();
		updateContactsEmptyState(wrap);
	});

	$(document).on('click', '.conexao-contact-up', function (e) {
		e.preventDefault();
		var row = $(this).closest('.conexao-contact-row');
		var prev = row.prev('.conexao-contact-row');
		if (prev.length) {
			row.insertBefore(prev);
		}
	});

	$(document).on('click', '.conexao-contact-down', function (e) {
		e.preventDefault();
		var row = $(this).closest('.conexao-contact-row');
		var nextRow = row.next('.conexao-contact-row');
		if (nextRow.length) {
			row.insertAfter(nextRow);
		}
	});

	// Type-dependent hints: placeholder + mobile keyboard per contact type.
	$(document).on('change', '.conexao-contact-type', function () {
		applyContactTypeHints($(this).closest('.conexao-contact-row'));
	});

	$(document).ready(function () {
		attachCountyDependency();
		attachTownAutocomplete();

		// Apply correct hints to pre-filled rows on load.
		$('.conexao-contact-row').each(function () {
			applyContactTypeHints($(this));
		});
	});
// ------------------------------------------------------------------
	// Conditional field visibility (e.g. recurrence fields shown only
	// when "Recorrente" is selected).
	// Controlled via data-conditional-field + data-conditional-value
	// attributes rendered by the field renderer. No widget-specific
	// JS is needed; the generic handler below listens on all radio/
	// select/checkbox inputs with a matching field-key and toggles the
	// conditional fields.
	// ------------------------------------------------------------------

	function updateConditionalFields(container) {
		if (!container) container = document;
		$(container).find('.conexao-conditional').each(function() {
			var el = $(this);
			var fieldKey = el.data('conditional-field');
			var requiredValue = el.data('conditional-value');
			if (!fieldKey) return;

			var metaKey = fieldKey.replace(/^_/, '');

			// Find the controlling input(s) in the same section.
			// Radio/checkbox groups share a name attribute.
			var name = 'conexao_fields[' + metaKey + ']';
			var section = el.closest('.conexao-section');
			var inputs = section.length
				? section.find('[name="' + name + '"]')
				: $('[name="' + name + '"]');

			if (!inputs.length) {
				el.removeClass('conexao-conditional--visible');
				return;
			}

			var show = false;
			var firstInput = inputs.get(0);
			if (firstInput.type === 'radio' || firstInput.type === 'checkbox') {
				inputs.each(function() {
					if ($(this).prop('checked') && String($(this).val()) === String(requiredValue)) {
						show = true;
					}
				});
			} else {
				show = String(firstInput.value) === String(requiredValue);
			}

			el.toggleClass('conexao-conditional--visible', show);
		});
	}

	$(document).on('change', '.conexao-field-input', function() {
		var section = $(this).closest('.conexao-section');
		updateConditionalFields(section.length ? section.get(0) : document);
	});

	$(document).ready(function() {
		updateConditionalFields(document);
	});
})(jQuery);
