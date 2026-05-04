/*
 * Tidy Resize Images — Media Library list-mode JS.
 *
 * Handles the Protect / Unprotect row action without a full page reload.
 * Click a row-action link → POST to tri_set_protected → swap the link
 * label and the Tidy column cell using the HTML the server returns.
 *
 * Vanilla JS, no jQuery dependency. Uses event delegation on document so
 * we don't have to re-bind when the table is rebuilt (which WordPress
 * doesn't do for list mode, but defensive coding doesn't cost us much).
 */
( function () {
	'use strict';

	if ( typeof window.triMediaLibrary !== 'object' ) {
		return;
	}

	var cfg = window.triMediaLibrary;

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest
			? event.target.closest( '.tri-row-action-protect' )
			: null;

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		if ( link.dataset.busy === '1' ) {
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
		body.append( 'action', 'tri_set_protected' );
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
				if ( ! data || ! data.success || ! data.data ) {
					throw new Error( 'tri-set-protected-failed' );
				}

				link.textContent = data.data.label;

				var row = link.closest( 'tr' );

				if ( row ) {
					var cell = row.querySelector( '.column-tri_tidy' );

					if ( cell && typeof data.data.column_html === 'string' ) {
						cell.innerHTML = data.data.column_html;
					}
				}
			} )
			.catch( function () {
				link.textContent = originalLabel;
				window.alert( cfg.i18n.failed );
			} )
			.finally( function () {
				link.dataset.busy = '';
			} );
	} );
}() );
