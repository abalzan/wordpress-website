/**
 * Empregos — "Onde procurar emprego" admin screen behaviors.
 *
 * Plain vanilla JS (no build tooling): adds/removes resource rows from the
 * server-rendered <template>, keeps row titles in sync with the card heading,
 * and wires the Media Library picker for each card image.
 */
( function () {
	'use strict';

	var rowsContainer = document.getElementById( 'conexao-job-resources-rows' );
	var addBtn = document.getElementById( 'conexao-job-resources-add' );
	var template = document.getElementById( 'conexao-job-resource-row-template' );

	if ( ! rowsContainer || ! addBtn || ! template || ! window.wp || ! window.wp.media ) {
		return;
	}

	/**
	 * Open the media picker for a row and store the chosen attachment ID.
	 *
	 * @param {HTMLElement} row Resource row element.
	 */
	function openPicker( row ) {
		var frame = window.wp.media( {
			title: row.querySelector( '.conexao-job-resource-title-input' ).value || 'Imagem do cartão',
			multiple: false,
			library: { type: 'image' }
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var idInput = row.querySelector( '.conexao-job-resource-image-id' );
			var preview = row.querySelector( '.conexao-job-resource-image-preview' );
			var removeBtn = row.querySelector( '.conexao-job-resource-image-remove' );
			var sizes = attachment.sizes || {};
			var thumb = sizes.thumbnail ? sizes.thumbnail.url : attachment.url;

			idInput.value = attachment.id;
			preview.innerHTML = '';

			var img = document.createElement( 'img' );
			img.src = thumb;
			img.alt = attachment.alt || '';
			img.className = 'conexao-job-resource-thumb';
			preview.appendChild( img );

			removeBtn.style.display = '';
		} );

		frame.open();
	}

	/**
	 * Bind all behaviors for a single row.
	 *
	 * @param {HTMLElement} row Resource row element.
	 */
	function bindRow( row ) {
		var titleInput = row.querySelector( '.conexao-job-resource-title-input' );
		var rowTitle = row.querySelector( '.conexao-job-resource-row-title' );

		titleInput.addEventListener( 'input', function () {
			rowTitle.textContent = titleInput.value || 'Novo site';
		} );

		row.querySelector( '.conexao-job-resource-image-select' ).addEventListener( 'click', function () {
			openPicker( row );
		} );

		row.querySelector( '.conexao-job-resource-image-remove' ).addEventListener( 'click', function () {
			row.querySelector( '.conexao-job-resource-image-id' ).value = '0';
			row.querySelector( '.conexao-job-resource-image-preview' ).innerHTML = '';
			this.style.display = 'none';
		} );

		row.querySelector( '.conexao-job-resource-remove' ).addEventListener( 'click', function () {
			if ( window.confirm( 'Remover este site da lista? (As alterações só valem após salvar.)' ) ) {
				row.remove();
			}
		} );
	}

	addBtn.addEventListener( 'click', function () {
		var clone = template.content.cloneNode( true );
		var row = clone.querySelector( '.conexao-job-resource-row' );
		rowsContainer.appendChild( clone );
		bindRow( row );
		row.querySelector( '.conexao-job-resource-title-input' ).focus();
	} );

	Array.prototype.forEach.call(
		rowsContainer.querySelectorAll( '.conexao-job-resource-row' ),
		bindRow
	);
} )();
