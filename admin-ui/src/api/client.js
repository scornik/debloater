/**
 * The only place this app talks to the site.
 *
 * Every request carries the nonce the screen was rendered with, and every
 * failure comes back in one shape, so a screen never has to guess whether it is
 * holding an error object, a WP_Error payload or a thrown exception.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const bootstrap = window.debloater || {};

if ( bootstrap.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );
}

if ( bootstrap.root ) {
	apiFetch.use(
		apiFetch.createRootURLMiddleware(
			bootstrap.root.replace( /\/?$/, '/' )
		)
	);
}

/**
 * The REST namespace every path here sits under.
 *
 * Paths are written as `/status` and `/findings` because that is what they are
 * about; the namespace is added here, once.
 *
 * It has to be added to the *path* rather than baked into the root URL. On a
 * site with plain permalinks — WordPress's default — the REST root is
 * `…/index.php?rest_route=/`, and api-fetch's root middleware knows how to join
 * a path onto that. A root with the namespace already in it does not survive
 * the same join: it produces `…/debloater/v1//status`, which matches no route,
 * and every screen shows "No route was found matching the URL".
 */
const NAMESPACE = ( bootstrap.namespace || 'debloater/v1' ).replace(
	/^\/|\/$/g,
	''
);

const namespaced = ( path ) => `/${ NAMESPACE }${ path }`;

/**
 * An error with a message a person can act on.
 *
 * WordPress sends `{ code, message, data: { status } }`; a network failure
 * sends nothing at all. Both end up here as the same shape.
 */
export class RequestError extends Error {
	constructor( message, code = 'debloater_request_failed', status = 0 ) {
		super( message );

		this.name = 'RequestError';
		this.code = code;
		this.status = status;
	}
}

const normalise = ( error ) => {
	if ( error instanceof RequestError ) {
		return error;
	}

	if ( error && typeof error === 'object' && error.message ) {
		return new RequestError(
			error.message,
			error.code,
			error.data?.status ?? 0
		);
	}

	return new RequestError(
		__(
			'The site did not answer. Check that it is reachable and try again.',
			'debloater'
		)
	);
};

const request = async ( options ) => {
	try {
		return await apiFetch( options );
	} catch ( error ) {
		throw normalise( error );
	}
};

export const get = ( path, query = {} ) => {
	// An array value becomes one parameter per item, which is what WordPress
	// reads back as an array. Joining them with a comma — which is what the
	// default string conversion does — produces a single parameter holding
	// "a,b" and a route that sees one change named `a,b`. It worked until now
	// only because every array passed here had one item in it.
	const pairs = [];

	Object.entries( query ).forEach( ( [ key, value ] ) => {
		const values = Array.isArray( value ) ? value : [ value ];

		values.forEach( ( item ) => {
			if ( item === undefined || item === null || item === '' ) {
				return;
			}

			pairs.push(
				`${ encodeURIComponent( key ) }=${ encodeURIComponent( item ) }`
			);
		} );
	} );

	const search = pairs.join( '&' );

	const full = namespaced( path );

	return request( { path: search ? `${ full }?${ search }` : full } );
};

export const post = ( path, data ) =>
	request( { path: namespaced( path ), method: 'POST', data } );

export const canManage = () => Boolean( bootstrap.canManage );

export const pluginVersion = () => bootstrap.pluginVersion || '';
