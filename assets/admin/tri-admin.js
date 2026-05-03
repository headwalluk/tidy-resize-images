/**
 * Tidy Resize Images — admin behaviour.
 *
 * Hash-based tab navigation for the settings page. Scoped to .tri-settings
 * so we don't interfere with other nav-tab-wrappers in the WP admin.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		const wrap = document.querySelector( '.tri-settings' );

		if ( ! wrap ) {
			return;
		}

		const tabs = wrap.querySelectorAll( '.nav-tab' );
		const panels = wrap.querySelectorAll( '.tri-tab-panel' );

		if ( 0 === tabs.length || 0 === panels.length ) {
			return;
		}

		const defaultTab = tabs[ 0 ].dataset.tab;

		function activateTab( tabName ) {
			tabs.forEach( function ( tab ) {
				tab.classList.remove( 'nav-tab-active' );
			} );
			const navTab = wrap.querySelector( '[data-tab="' + tabName + '"]' );
			if ( navTab ) {
				navTab.classList.add( 'nav-tab-active' );
			}

			panels.forEach( function ( panel ) {
				panel.style.display = 'none';
				panel.classList.remove( 'active' );
			} );
			const panel = wrap.querySelector( '#' + tabName + '-panel' );
			if ( panel ) {
				panel.style.display = 'block';
				panel.classList.add( 'active' );
			}
		}

		// Wire tab clicks: update hash, then activate.
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const tabName = tab.dataset.tab;
				window.location.hash = tabName;
				activateTab( tabName );
			} );
		} );

		// Browser back/forward should sync the active tab.
		window.addEventListener( 'hashchange', function () {
			const tabName = window.location.hash.substring( 1 ) || defaultTab;
			activateTab( tabName );
		} );

		// Initial activation: respect URL hash, fall back to first tab.
		const initial = window.location.hash.substring( 1 ) || defaultTab;
		activateTab( initial );
	} );
} )();
