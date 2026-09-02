/**
 * The only place this app talks to the site.
 *
 * Every request carries the nonce the screen was rendered with, and every
 * failure comes back in one shape, so a screen never has to guess whether it is
 * holding an error object, a WP_Error payload or a thrown exception.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const bootstrap = window.wpDebloat || {};

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
 * An error with a message a person can act on.
 *
 * WordPress sends `{ code, message, data: { status } }`; a network failure
 * sends nothing at all. Both end up here as the same shape.
 */
export class RequestError extends Error {
	constructor( message, code = 'wpdebloat_request_failed', status = 0 ) {
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
			'wp-debloat'
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
	const search = Object.entries( query )
		.filter(
			( [ , value ] ) =>
				value !== undefined && value !== null && value !== ''
		)
		.map(
			( [ key, value ] ) =>
				`${ encodeURIComponent( key ) }=${ encodeURIComponent(
					value
				) }`
		)
		.join( '&' );

	return request( { path: search ? `${ path }?${ search }` : path } );
};

export const post = ( path, data ) => request( { path, method: 'POST', data } );

export const canManage = () => Boolean( bootstrap.canManage );

export const pluginVersion = () => bootstrap.pluginVersion || '';
