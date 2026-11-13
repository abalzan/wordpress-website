/**
 * Runtime tests — bulk selection for the Event Sources page.
 *
 * Executed with `node --test`. Loads the real `assets/admin.js` and drives the
 * bulk-selection controller against a hand-built fake DOM that mirrors the
 * markup `Conexao_Event_Sources` renders, so the behaviour is PROVEN BY
 * RUNNING IT — not by searching its source text.
 *
 * Covered:
 *   - Initial state: no selection, counter at zero, header unchecked, Apply
 *     and action selector disabled.
 *   - Selecting one row increments the counter and enables Apply.
 *   - Selecting several rows increments correctly.
 *   - Deselecting a row decrements correctly.
 *   - Select All selects every row, updates the counter, checks the header.
 *   - Deselect All clears everything and disables Apply.
 *   - Header checkbox: checked = all selected, unchecked = none, indeterminate
 *     = partial.
 *   - Counter updates reflect the live DOM.
 *   - Apply button disabled until both a selection and a valid action exist.
 *   - Duplicate initialisation is prevented (data-attribute guard).
 *   - Replacing table rows keeps the selection system working.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const path = require( 'node:path' );
const fs = require( 'fs' );
const vm = require( 'vm' );

/* ------------------------------------------------------------------------- */
/* Fake DOM                                                                  */
/* ------------------------------------------------------------------------- */

/**
 * A minimal element: attributes, classList, listeners, and dispatch.
 * Properties like `checked`, `value`, `disabled`, `indeterminate` are stored
 * directly on the node so the controller can read/write them.
 *
 * @param {Object} attributes Initial attributes/properties.
 * @returns {Object} Fake element.
 */
function element( attributes ) {
	const store = Object.assign( {}, attributes || {} );
	const listeners = {};
	const classNames = ( store.class || '' ).split( /\s+/ ).filter( Boolean );
	const node = {
		attributes: store,
		style: {},
		classList: {
			names: new Set( classNames ),
			add( name ) { node.classList.names.add( name ); store.class = Array.from( node.classList.names ).join( ' ' ); },
			remove( name ) { node.classList.names.delete( name ); store.class = Array.from( node.classList.names ).join( ' ' ); },
			contains( name ) { return node.classList.names.has( name ); },
		},
		get textContent() {
			return store.textContent === undefined ? '' : store.textContent;
		},
		set textContent( value ) {
			store.textContent = String( value );
		},
		get className() {
			return Array.from( node.classList.names ).join( ' ' );
		},
		set className( value ) {
			node.classList.names = new Set( String( value ).split( /\s+/ ).filter( Boolean ) );
		},
		get options() {
			return ( node.children || [] ).filter(
				( child ) => ( child.tagName || '' ).toUpperCase() === 'OPTION'
			);
		},
		get tagName() {
			return store.tagName || '';
		},
		get checked() {
			return !! store.checked;
		},
		set checked( value ) {
			store.checked = !! value;
		},
		get disabled() {
			return !! store.disabled;
		},
		set disabled( value ) {
			store.disabled = !! value;
		},
		get indeterminate() {
			return !! store.indeterminate;
		},
		set indeterminate( value ) {
			store.indeterminate = !! value;
		},
		get value() {
			return store.value === undefined ? '' : store.value;
		},
		set value( value ) {
			store.value = String( value );
		},
		getAttribute( name ) {
			return Object.prototype.hasOwnProperty.call( store, name ) ? store[ name ] : null;
		},
		setAttribute( name, value ) {
			store[ name ] = String( value );
			if ( name === 'class' ) {
				node.classList.names = new Set( String( value ).split( /\s+/ ).filter( Boolean ) );
			}
		},
		removeAttribute( name ) {
			delete store[ name ];
			if ( name === 'class' ) {
				node.classList.names = new Set();
			}
		},
		hasAttribute( name ) {
			return Object.prototype.hasOwnProperty.call( store, name );
		},
		addEventListener( type, handler ) {
			listeners[ type ] = listeners[ type ] || [];
			listeners[ type ].push( handler );
		},
		listenerCount( type ) {
			return ( listeners[ type ] || [] ).length;
		},
		removeEventListener() {},
		dispatch( type, event, _originalTarget ) {
			const originalTarget = _originalTarget || node;
			const payload = Object.assign(
				{ currentTarget: node, target: originalTarget, defaultPrevented: false },
				event || {}
			);
			payload.preventDefault = () => {
				payload.defaultPrevented = true;
			};
			// Call listeners on this node.
			( listeners[ type ] || [] ).forEach( ( handler ) => handler( payload ) );
			// Bubble up the tree (delegated listeners rely on this).
			if ( ( event && event.bubbles ) !== false && node.parentNode ) {
				node.parentNode.dispatch( type, event, originalTarget );
			}
			return payload;
		},
		appendChild( child ) {
			if ( ! node.children ) {
				node.children = [];
			}
			node.children.push( child );
			child.parentNode = node;
			return child;
		},
		insertBefore( newChild, refChild ) {
			if ( ! node.children ) {
				node.children = [];
			}
			const idx = refChild ? node.children.indexOf( refChild ) : node.children.length;
			if ( idx >= 0 ) {
				node.children.splice( idx, 0, newChild );
			} else {
				node.children.push( newChild );
			}
			newChild.parentNode = node;
			return newChild;
		},
		replaceChild( newChild, oldChild ) {
			const idx = node.children.indexOf( oldChild );
			if ( idx >= 0 ) {
				node.children[ idx ] = newChild;
			}
			newChild.parentNode = node;
			return oldChild;
		},
		querySelector( selector ) {
			return query( node, selector );
		},
		querySelectorAll( selector ) {
			return queryAll( node, selector );
		},
		closest( selector ) {
			let cur = node;
			while ( cur ) {
				if ( matches( cur, selector ) ) {
					return cur;
				}
				cur = cur.parentNode;
			}
			return null;
		},
		matches( selector ) {
			return matches( node, selector );
		},
		parentNode: null,
		children: [],
	};
	return node;
}

function matches( node, selector ) {
	selector = selector.trim();
	// Extract pseudo-classes for attribute-based matching.
	const pseudo = selector.match( /:(checked|disabled|enabled|selected)/ );
	// Remove pseudo-classes entirely (including the colon) for selector matching.
	const base = selector.replace( /:(checked|disabled|enabled|selected|empty|first-child|last-child|nth-child\([^)]*\)|not\([^)]*\))/g, '' );

	// Apply pseudo-class filters.
	if ( pseudo ) {
		const kind = pseudo[ 1 ];
		if ( kind === 'checked' && ! node.checked ) {
			return false;
		}
		if ( kind === 'disabled' && ! node.disabled ) {
			return false;
		}
		if ( kind === 'enabled' && node.disabled ) {
			return false;
		}
		if ( kind === 'selected' && ! node.selected ) {
			return false;
		}
	}

	if ( base.startsWith( '#' ) ) {
		return node.getAttribute( 'id' ) === base.slice( 1 );
	}
	if ( base.startsWith( '.' ) ) {
		// Handle compound class selectors like .wrap.conexao-event-sources.
		const classes = base.slice( 1 ).split( '.' ).filter( Boolean );
		return classes.every( ( cls ) => node.classList.contains( cls ) );
	}
	if ( base.startsWith( '[' ) && base.endsWith( ']' ) ) {
		const inner = base.slice( 1, -1 );
		const eq = inner.indexOf( '=' );
		if ( eq >= 0 ) {
			const attr = inner.slice( 0, eq );
			const val = inner.slice( eq + 1 );
			return node.getAttribute( attr ) === val;
		}
		return node.hasAttribute( inner );
	}
	// Tag name selector (e.g. 'table', 'tr', 'th'). Case-insensitive like real browsers.
	if ( base ) {
		return ( node.tagName || '' ).toUpperCase() === base.toUpperCase();
	}
	return true;
}

function query( root, selector ) {
	const all = queryAll( root, selector );
	return all.length ? all[ 0 ] : null;
}

function queryAll( root, selector ) {
	const results = [];
	( function walk( node ) {
		if ( node.matches && node.matches( selector ) ) {
			results.push( node );
		}
		( node.children || [] ).forEach( walk );
	} )( root );
	return results;
}

/**
 * A minimal document.
 *
 * @returns {Object} Fake document.
 */
function document() {
	const doc = element();
	doc.getElementById = function ( id ) {
		// Search the whole document tree (documentElement + body).
		const found = query( this.documentElement, '#' + id );
		return found || query( this.body, '#' + id );
	};
	doc.createElement = function ( tag ) {
		return element( { tagName: tag.toUpperCase() } );
	};
	doc.createTextNode = function ( text ) {
		return {
			textContent: text,
			nodeType: 3,
			parentNode: null,
		};
	};
	doc.querySelector = function ( selector ) {
		const found = query( this.documentElement, selector );
		return found || query( this.body, selector );
	};
	doc.querySelectorAll = function ( selector ) {
		const fromDocEl = queryAll( this.documentElement, selector );
		const fromBody  = queryAll( this.body, selector );
		return fromDocEl.concat( fromBody );
	};
	doc.documentElement = element();
	doc.body = element();
	// Make body part of the document tree so recursive walks find it.
	doc.documentElement.appendChild( doc.body );
	doc.body.appendChild = function ( child ) {
		this.children.push( child );
		child.parentNode = this;
		return child;
	};
	doc.readyState = 'complete';
	return doc;
}

/**
 * A minimal window.
 *
 * @param {Object} doc Fake document.
 * @returns {Object} Fake window.
 */
function window( doc ) {
	const win = {
		document: doc,
		Event: function ( type, init ) {
			return { type: type, bubbles: !! ( init && init.bubbles ), defaultPrevented: false };
		},
		addEventListener() {},
		removeEventListener() {},
		// Confirm is stubbed so a delete confirmation can be asserted without
		// a real modal. `confirmCalls` records every message shown.
		confirmCalls: [],
		confirmResult: true,
	};
	win.confirm = function ( message ) {
		win.confirmCalls.push( String( message ) );
		return win.confirmResult !== false;
	};
	// A real browser has a `window` self-reference; admin.js feature-detects it.
	win.window = win;
	doc.defaultView = win;
	return win;
}

/**
 * Dispatch a change event on a checkbox through the fake DOM.
 *
 * @param {Object} checkbox
 * @param {Object} win
 */
function change( checkbox, win ) {
	checkbox.dispatch( 'change', { bubbles: true } );
}

/* ------------------------------------------------------------------------- */
/* Build the Event Sources page DOM                                          */
/* ------------------------------------------------------------------------- */

/**
 * Build a minimal DOM mirroring the Event Sources page markup.
 *
 * @param {number} rowCount Number of source rows to render.
 * @returns {Object} { win, doc, form, rowCheckboxes, headerCheckbox,
 *   actionSelect, apply, countNum, status, wrap }
 */
function buildDom( rowCount ) {
	const doc = document();
	const win = window( doc );

	win.conexaoEventImporter = {
		i18n: {
			bulkDeleteConfirm:
				'Delete %d source(s)? This will remove the selected Event Source configurations. Imported events will remain in the database.',
			bulkSelectedOne: '1 source selected',
			bulkSelectedMany: '%d sources selected',
		},
	};

	const wrap = element( { class: 'wrap conexao-event-sources' } );
	doc.body.appendChild( wrap );

	const form = element( {
		id: 'conexao-bulk-actions-form',
		method: 'post',
		class: 'conexao-bulk-actions',
	} );
	wrap.appendChild( form );

	const controls = element( { class: 'conexao-bulk-controls' } );
	form.appendChild( controls );

	const selectAll = element( { id: 'conexao-bulk-select-all', type: 'button' } );
	selectAll.textContent = 'Select All';
	controls.appendChild( selectAll );

	const deselectAll = element( { id: 'conexao-bulk-deselect-all', type: 'button' } );
	deselectAll.textContent = 'Deselect All';
	controls.appendChild( deselectAll );

	const count = element( { id: 'conexao-bulk-count', class: 'conexao-bulk-count' } );
	const countNum = element( {
		id: 'conexao-bulk-selected-count',
		class: 'conexao-bulk-selected-count',
	} );
	countNum.textContent = '0';
	count.appendChild( countNum );
	count.appendChild( doc.createTextNode( ' of ' ) );
	const totalNum = element( {
		id: 'conexao-bulk-total-count',
		class: 'conexao-bulk-total-count',
	} );
	totalNum.textContent = String( rowCount );
	count.appendChild( totalNum );
	count.appendChild( doc.createTextNode( ' selected' ) );
	controls.appendChild( count );

	const status = element( { id: 'conexao-bulk-status' } );
	status.setAttribute( 'aria-live', 'polite' );
	controls.appendChild( status );

	const actionSelect = element( {
		id: 'conexao-bulk-action-select',
		name: 'bulk_action_type',
		class: 'regular-text',
		tagName: 'SELECT',
	} );
	const opts = [
		{ value: '', text: 'Bulk action' },
		{ value: 'enable', text: 'Enable Selected' },
		{ value: 'disable', text: 'Disable Selected' },
		{ value: 'delete', text: 'Delete Selected' },
	];
	for ( const o of opts ) {
		const opt = element( { value: o.value, tagName: 'OPTION' } );
		opt.textContent = o.text;
		actionSelect.appendChild( opt );
	}
	controls.appendChild( actionSelect );

	const apply = element( {
		id: 'conexao-bulk-apply',
		type: 'submit',
		class: 'button button-primary',
	} );
	apply.disabled = true;
	apply.textContent = 'Apply';
	controls.appendChild( apply );

	const table = element( { class: 'widefat striped', tagName: 'TABLE' } );
	wrap.appendChild( table );

	const thead = element( { tagName: 'THEAD' } );
	table.appendChild( thead );
	const headRow = element( { tagName: 'TR' } );
	thead.appendChild( headRow );

	const headerTh = element( { scope: 'col', class: 'check-column', tagName: 'TH' } );
	const headerCheckbox = element( {
		id: 'conexao-bulk-header-checkbox',
		type: 'checkbox',
	} );
	headerTh.appendChild( headerCheckbox );
	headRow.appendChild( headerTh );

	const tbody = element( { tagName: 'TBODY' } );
	table.appendChild( tbody );

	const rowCheckboxes = [];
	for ( let i = 0; i < rowCount; i++ ) {
		const row = element( { tagName: 'TR' } );
		const th = element( { scope: 'row', class: 'check-column', tagName: 'TH' } );
		const cb = element( {
			name: 'bulk_source_ids[]',
			value: 'src_' + i,
			class: 'conexao-bulk-row-checkbox',
			type: 'checkbox',
			form: 'conexao-bulk-actions-form',
		} );
		th.appendChild( cb );
		row.appendChild( th );
		const nameTd = element( { tagName: 'TD' } );
		nameTd.textContent = 'Source ' + i;
		row.appendChild( nameTd );
		tbody.appendChild( row );
		rowCheckboxes.push( cb );
	}

	return {
		win,
		doc,
		form,
		table,
		tbody,
		rowCheckboxes,
		headerCheckbox,
		actionSelect,
		apply,
		countNum,
		totalNum,
		status,
		wrap,
	};
}

/**
 * Load admin.js into the given window context.
 *
 * @param {Object} win Fake window.
 */
function loadAdminJs( win ) {
	const jsPath = path.join(
		process.cwd(),
		'wp-content',
		'plugins',
		'conexao-event-importer',
		'assets',
		'admin.js'
	);
	const source = fs.readFileSync( jsPath, 'utf8' );
	vm.createContext( win );
	vm.runInContext( source, win );
}

/* ------------------------------------------------------------------------- */
/* Tests                                                                     */
/* ------------------------------------------------------------------------- */

test( 'bulk selection: initial state', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum, totalNum, status } = buildDom( 5 );
	loadAdminJs( win );

	for ( const cb of rowCheckboxes ) {
		assert.strictEqual( cb.checked, false );
	}

	assert.strictEqual( countNum.textContent, '0' );
	// The total is counted from the live DOM, never hardcoded.
	assert.strictEqual( totalNum.textContent, '5' );
	assert.strictEqual( headerCheckbox.checked, false );
	assert.strictEqual( headerCheckbox.indeterminate, false );
	assert.strictEqual( apply.disabled, true );
	assert.strictEqual( actionSelect.disabled, true );
	assert.strictEqual( status.textContent, '' );
} );

test( 'bulk selection: selecting one row', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	assert.strictEqual( countNum.textContent, '1' );
	assert.strictEqual( headerCheckbox.checked, false );
	assert.strictEqual( headerCheckbox.indeterminate, true );
	// Apply disabled until a valid action is also chosen.
	assert.strictEqual( apply.disabled, true );
	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
	assert.strictEqual( actionSelect.disabled, false );
} );

test( 'bulk selection: selecting several rows', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	rowCheckboxes[ 1 ].checked = true;
	rowCheckboxes[ 2 ].checked = true;
	change( rowCheckboxes[ 0 ], win );
	change( rowCheckboxes[ 1 ], win );
	change( rowCheckboxes[ 2 ], win );

	assert.strictEqual( countNum.textContent, '3' );
	assert.strictEqual( headerCheckbox.indeterminate, true );
	// Apply disabled until a valid action is chosen.
	assert.strictEqual( apply.disabled, true );
	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: deselecting a row decrements', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	for ( const cb of rowCheckboxes ) {
		cb.checked = true;
		change( cb, win );
	}
	assert.strictEqual( countNum.textContent, '5' );

	rowCheckboxes[ 2 ].checked = false;
	change( rowCheckboxes[ 2 ], win );

	assert.strictEqual( countNum.textContent, '4' );
	assert.strictEqual( headerCheckbox.indeterminate, true );
	assert.strictEqual( apply.disabled, true );
	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: Select All', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	const btn = doc.getElementById( 'conexao-bulk-select-all' );
	btn.dispatch( 'click' );

	for ( const cb of rowCheckboxes ) {
		assert.strictEqual( cb.checked, true );
	}
	assert.strictEqual( countNum.textContent, '5' );
	assert.strictEqual( headerCheckbox.checked, true );
	assert.strictEqual( headerCheckbox.indeterminate, false );
	assert.strictEqual( apply.disabled, true );
	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: Deselect All', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	for ( const cb of rowCheckboxes ) {
		cb.checked = true;
		change( cb, win );
	}
	assert.strictEqual( countNum.textContent, '5' );

	const btn = doc.getElementById( 'conexao-bulk-deselect-all' );
	btn.dispatch( 'click' );

	for ( const cb of rowCheckboxes ) {
		assert.strictEqual( cb.checked, false );
	}
	assert.strictEqual( countNum.textContent, '0' );
	assert.strictEqual( headerCheckbox.checked, false );
	assert.strictEqual( headerCheckbox.indeterminate, false );
	assert.strictEqual( apply.disabled, true );
} );

test( 'bulk selection: header checkbox checks all', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	headerCheckbox.checked = true;
	change( headerCheckbox, win );

	for ( const cb of rowCheckboxes ) {
		assert.strictEqual( cb.checked, true );
	}
	assert.strictEqual( countNum.textContent, '5' );
	assert.strictEqual( headerCheckbox.checked, true );
	assert.strictEqual( headerCheckbox.indeterminate, false );
	assert.strictEqual( apply.disabled, true );
	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: header checkbox unchecks all', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	headerCheckbox.checked = true;
	change( headerCheckbox, win );
	assert.strictEqual( countNum.textContent, '5' );

	headerCheckbox.checked = false;
	change( headerCheckbox, win );

	for ( const cb of rowCheckboxes ) {
		assert.strictEqual( cb.checked, false );
	}
	assert.strictEqual( countNum.textContent, '0' );
	assert.strictEqual( headerCheckbox.checked, false );
	assert.strictEqual( headerCheckbox.indeterminate, false );
	assert.strictEqual( apply.disabled, true );
} );

test( 'bulk selection: indeterminate state for partial selection', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, countNum } = buildDom( 5 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	rowCheckboxes[ 1 ].checked = true;
	change( rowCheckboxes[ 0 ], win );
	change( rowCheckboxes[ 1 ], win );

	assert.strictEqual( countNum.textContent, '2' );
	assert.strictEqual( headerCheckbox.checked, false );
	assert.strictEqual( headerCheckbox.indeterminate, true );
} );

test( 'bulk selection: Apply disabled until valid action chosen', ( t ) => {
	const { win, doc, rowCheckboxes, actionSelect, apply, countNum } = buildDom( 5 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	assert.strictEqual( apply.disabled, true );

	actionSelect.value = 'enable';
	actionSelect.dispatch( 'change', { bubbles: true } );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: duplicate initialisation prevented', ( t ) => {
	const { win, doc, form, wrap, rowCheckboxes, countNum } = buildDom( 5 );
	loadAdminJs( win );

	assert.strictEqual( form.getAttribute( 'data-conexao-bulk-init' ), '1' );

	const changeListeners = wrap.listenerCount( 'change' );
	const clickListeners = wrap.listenerCount( 'click' );
	const submitListeners = form.listenerCount( 'submit' );

	// Execute the whole script a second time in the SAME document, exactly as a
	// duplicated enqueue would.
	loadAdminJs( win );

	assert.strictEqual( wrap.listenerCount( 'change' ), changeListeners, 'no second change listener' );
	assert.strictEqual( wrap.listenerCount( 'click' ), clickListeners, 'no second click listener' );
	assert.strictEqual( form.listenerCount( 'submit' ), submitListeners, 'no second submit listener' );

	// And the controller still works after the second execution.
	rowCheckboxes[ 3 ].checked = true;
	change( rowCheckboxes[ 3 ], win );
	assert.strictEqual( countNum.textContent, '1' );
} );

test( 'bulk selection: dynamic row replacement keeps working', ( t ) => {
	const { win, doc, rowCheckboxes, headerCheckbox, actionSelect, apply, countNum, wrap } = buildDom( 3 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );
	assert.strictEqual( countNum.textContent, '1' );

	const oldTable = wrap.querySelector( 'table' );
	const newTable = element( { class: 'widefat striped', tagName: 'TABLE' } );

	const newTbody = element( { tagName: 'TBODY' } );
	newTable.appendChild( newTbody );

	const newRowCheckboxes = [];
	for ( let i = 0; i < 4; i++ ) {
		const row = element( { tagName: 'TR' } );
		const th = element( { scope: 'row', class: 'check-column', tagName: 'TH' } );
		const cb = element( {
			name: 'bulk_source_ids[]',
			value: 'new_src_' + i,
			class: 'conexao-bulk-row-checkbox',
			type: 'checkbox',
			form: 'conexao-bulk-actions-form',
		} );
		th.appendChild( cb );
		row.appendChild( th );
		const nameTd = element( { tagName: 'TD' } );
		nameTd.textContent = 'New Source ' + i;
		row.appendChild( nameTd );
		newTbody.appendChild( row );
		newRowCheckboxes.push( cb );
	}

	const newThead = element( { tagName: 'THEAD' } );
	const headRow = element( { tagName: 'TR' } );
	newThead.appendChild( headRow );
	const headerTh = element( { scope: 'col', class: 'check-column', tagName: 'TH' } );
	const newHeaderCheckbox = element( {
		id: 'conexao-bulk-header-checkbox',
		type: 'checkbox',
	} );
	headerTh.appendChild( newHeaderCheckbox );
	headRow.appendChild( headerTh );
	newTable.insertBefore( newThead, newTbody );

	wrap.replaceChild( newTable, oldTable );

	newRowCheckboxes[ 0 ].checked = true;
	newRowCheckboxes[ 1 ].checked = true;
	change( newRowCheckboxes[ 0 ], win );
	change( newRowCheckboxes[ 1 ], win );

	// Choose a valid action so Apply becomes enabled.
	actionSelect.value = 'enable';
	change( actionSelect, win );

	const newCountNum = doc.getElementById( 'conexao-bulk-selected-count' );
	assert.strictEqual( newCountNum.textContent, '2' );

	const newHeader = doc.getElementById( 'conexao-bulk-header-checkbox' );
	assert.strictEqual( newHeader.indeterminate, true );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: total follows dynamically added and removed rows', ( t ) => {
	const { win, doc, tbody, rowCheckboxes, countNum, totalNum, headerCheckbox } = buildDom( 3 );
	loadAdminJs( win );

	assert.strictEqual( totalNum.textContent, '3' );

	// A row added after initialisation is counted on the next resync.
	const addedRow = element( { tagName: 'TR' } );
	const addedTh = element( { scope: 'row', class: 'check-column', tagName: 'TH' } );
	const addedCheckbox = element( {
		name: 'bulk_source_ids[]',
		value: 'added_src',
		class: 'conexao-bulk-row-checkbox',
		type: 'checkbox',
		form: 'conexao-bulk-actions-form',
	} );
	addedTh.appendChild( addedCheckbox );
	addedRow.appendChild( addedTh );
	tbody.appendChild( addedRow );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	assert.strictEqual( totalNum.textContent, '4' );
	assert.strictEqual( countNum.textContent, '1' );
	assert.strictEqual( headerCheckbox.indeterminate, true );

	// A removed row is dropped from the total too.
	tbody.replaceChild( element( { tagName: 'TR' } ), addedRow );
	change( rowCheckboxes[ 0 ], win );

	assert.strictEqual( totalNum.textContent, '3' );
	assert.strictEqual( countNum.textContent, '1' );
} );

test( 'bulk selection: the translated counter suffix is never rewritten', ( t ) => {
	const { win, doc, rowCheckboxes, countNum } = buildDom( 3 );
	loadAdminJs( win );

	const count = doc.getElementById( 'conexao-bulk-count' );
	const childrenBefore = count.children.length;

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	assert.strictEqual( count.children.length, childrenBefore, 'no counter node added' );
	assert.strictEqual( countNum.textContent, '1' );
	assert.doesNotMatch( countNum.textContent, /selected/, 'suffix never enters the numeric span' );
} );

test( 'bulk selection: unknown action value cannot enable Apply', ( t ) => {
	const { win, doc, rowCheckboxes, actionSelect, apply } = buildDom( 5 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	// A value that is not one of the rendered options is not a valid action.
	actionSelect.value = 'purge';
	change( actionSelect, win );
	assert.strictEqual( apply.disabled, true );

	actionSelect.value = 'delete';
	change( actionSelect, win );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: submit with no selection is blocked with a message', ( t ) => {
	const { win, form, status } = buildDom( 3 );
	loadAdminJs( win );

	const event = form.dispatch( 'submit' );

	assert.strictEqual( event.defaultPrevented, true );
	assert.strictEqual( status.textContent, 'Select at least one Event Source before applying a bulk action.' );
	assert.match( status.className, /conexao-bulk-status--error/ );
	assert.strictEqual( status.getAttribute( 'aria-live' ), 'polite' );
} );

test( 'bulk selection: submit with no action is blocked with a message', ( t ) => {
	const { win, form, rowCheckboxes, status } = buildDom( 3 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	// Simulate a submission that bypasses the disabled Apply button.
	const event = form.dispatch( 'submit' );

	assert.strictEqual( event.defaultPrevented, true );
	assert.strictEqual( status.textContent, 'Choose a bulk action before applying it.' );
	assert.match( status.className, /conexao-bulk-status--error/ );
} );

test( 'bulk selection: selection change clears a stale validation error', ( t ) => {
	const { win, doc, rowCheckboxes, actionSelect, status, apply } = buildDom( 3 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	actionSelect.value = '';
	change( actionSelect, win );
	doc.getElementById( 'conexao-bulk-actions-form' ).dispatch( 'submit' );
	assert.match( status.className, /conexao-bulk-status--error/ );

	actionSelect.value = 'enable';
	change( actionSelect, win );

	assert.strictEqual( status.textContent, '1 source selected' );
	assert.doesNotMatch( status.className, /conexao-bulk-status--error/ );
	assert.strictEqual( apply.disabled, false );
} );

test( 'bulk selection: delete asks for confirmation with the actual count', ( t ) => {
	const { win, form, rowCheckboxes, actionSelect } = buildDom( 4 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	rowCheckboxes[ 1 ].checked = true;
	change( rowCheckboxes[ 0 ], win );
	change( rowCheckboxes[ 1 ], win );

	actionSelect.value = 'delete';
	change( actionSelect, win );

	win.confirmResult = false;
	let event = form.dispatch( 'submit' );

	assert.strictEqual( win.confirmCalls.length, 1 );
	assert.match( win.confirmCalls[ 0 ], /^Delete 2 source\(s\)\?/ );
	assert.match( win.confirmCalls[ 0 ], /Imported events will remain in the database/ );
	assert.doesNotMatch( win.confirmCalls[ 0 ], /\\n/, 'the confirmation contains no literal escape sequence' );
	assert.strictEqual( event.defaultPrevented, true, 'declining the confirmation blocks the submit' );

	win.confirmResult = true;
	event = form.dispatch( 'submit' );

	assert.strictEqual( win.confirmCalls.length, 2 );
	assert.strictEqual( event.defaultPrevented, false, 'accepting the confirmation submits' );
} );

test( 'bulk selection: enable and disable do not ask for confirmation', ( t ) => {
	const { win, form, rowCheckboxes, actionSelect } = buildDom( 3 );
	loadAdminJs( win );

	rowCheckboxes[ 0 ].checked = true;
	change( rowCheckboxes[ 0 ], win );

	for ( const action of [ 'enable', 'disable' ] ) {
		actionSelect.value = action;
		change( actionSelect, win );
		const event = form.dispatch( 'submit' );
		assert.strictEqual( event.defaultPrevented, false, action + ' submits without a dialog' );
	}

	assert.strictEqual( win.confirmCalls.length, 0 );
} );
