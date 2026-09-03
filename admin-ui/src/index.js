/**
 * Mount the app, and nothing else.
 *
 * This file runs on one admin screen and no other. If the mount point is not
 * there, it is on the wrong page and does nothing at all.
 */

import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'debloater-root' );

	if ( ! root ) {
		return;
	}

	root.textContent = '';

	createRoot( root ).render( <App /> );
} );
