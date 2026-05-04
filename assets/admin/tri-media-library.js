/*
 * Tidy Resize Images — Media Library list-mode JS.
 *
 * Handles three row actions (Protect/Unprotect, Optimize Now, Restore
 * Original) without a full page reload. Each link carries a
 * data-tri-action attribute; this script maps that to a WordPress AJAX
 * action, posts the request, and updates the row in place.
 *
 * Live updates are deliberately partial:
 *   - Protect/Unprotect: link label toggles between "Protect" and "Unprotect".
 *   - Restore Original: the clicked link's wrapping span is removed.
 *   - Optimize Now: the link label resets to "Optimize Now"; the column
 *     icons reflect the new state. The freshly-applicable Restore Original
 *     link will NOT appear without a page refresh — accepted v1 limitation.
 *
 * Vanilla JS (no jQuery), uses fetch + URLSearchParams. Event delegation
 * on document so the handler keeps working if WP rebuilds the table.
 */
( function () {
	'use strict';

	if ( typeof window.triMediaLibrary !== 'object' ) {
		return;
	}

	var cfg = window.triMediaLibrary;

	// data-tri-action → wp_ajax action name.
	var AJAX_ACTIONS = {
		protect:  'tri_set_protected',
		optimize: 'tri_optimize_now',
		restore:  'tri_restore_original'
	};

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest
			? event.target.closest( '.tri-row-action' )
			: null;

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		if ( link.dataset.busy === '1' ) {
			return;
		}

		var actionType = link.dataset.triAction;
		var ajaxAction = AJAX_ACTIONS[ actionType ];

		if ( ! ajaxAction ) {
			return;
		}

		var postId = parseInt( link.dataset.attachmentId, 10 );

		if ( ! postId ) {
			return;
		}

		var originalLabel = link.textContent;
		link.dataset.busy = '1';
		link.textContent = '…';

		var body = new URLSearchParams();
		body.append( 'action', ajaxAction );
		body.append( 'post_id', String( postId ) );
		body.append( 'nonce', cfg.nonce );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( ! data || ! data.success ) {
					var msg = data && data.data && data.data.message
						? data.data.message
						: cfg.i18n.failed;
					throw new Error( msg );
				}

				applySuccess( link, actionType, data.data || {} );
			} )
			.catch( function ( err ) {
				link.textContent = originalLabel;
				window.alert( err && err.message ? err.message : cfg.i18n.failed );
			} )
			.finally( function () {
				link.dataset.busy = '';
			} );
	} );

	function applySuccess( link, actionType, payload ) {
		// The Tidy column always reflects current state on the server;
		// swap it in regardless of which action ran.
		var row = link.closest( 'tr' );

		if ( row && typeof payload.column_html === 'string' ) {
			var cell = row.querySelector( '.column-tri_tidy' );

			if ( cell ) {
				cell.innerHTML = payload.column_html;
			}
		}

		if ( actionType === 'protect' ) {
			// Toggle between Protect / Unprotect.
			link.textContent = payload.label || '';
		} else if ( actionType === 'restore' ) {
			// No backup remains — drop the row-action span entirely.
			var span = link.parentElement;

			if ( span && span.classList.contains( 'tri_restore' ) ) {
				span.remove();
			} else {
				link.remove();
			}
		} else if ( actionType === 'optimize' ) {
			// Reset the link label. The column update is the operator-
			// visible feedback; we accept that the freshly-applicable
			// "Restore Original" link won't appear until page refresh.
			link.textContent = cfg.i18n.optimizeNow;
		}
	}
}() );
