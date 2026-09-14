/**
 * A warning when stored changes did not register.
 *
 * `GET /status` is its own request, so its `runtime` section says what that
 * request's runtime actually registered, against what is stored (D-0079). A
 * stored change that did not register is not in effect, whatever the run that
 * applied it said, and this is the one place on the screen that can say so.
 *
 * This replaces a notice about a generated file on disk not matching what
 * Debloater wrote. There has been no generated file since 0.3.0 (D-0070), the
 * status document stopped sending the keys it read, and it could never show.
 */

import { Notice } from '@wordpress/components';
import { _n, sprintf } from '@wordpress/i18n';

/**
 * How many stored handlers did not register in the status request.
 *
 * @param {Object} runtime The `runtime` section of `GET /status`.
 * @return {number} Handlers stored and not registered; 0 when unknown.
 */
export const unregisteredCount = ( runtime ) => {
	const stored = Array.isArray( runtime?.stored ) ? runtime.stored : [];
	const registered = Array.isArray( runtime?.registered )
		? runtime.registered
		: null;

	if ( stored.length === 0 || registered === null ) {
		return 0;
	}

	return stored.filter( ( handler ) => ! registered.includes( handler ) )
		.length;
};

const RuntimeNotice = ( { runtime } ) => {
	const count = unregisteredCount( runtime );

	if ( count === 0 ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			{ sprintf(
				/* translators: %d: number of stored changes that did not register. */
				_n(
					'%d applied change did not load on this site, so it is not in effect. Run `wp debloater status` to see why.',
					'%d applied changes did not load on this site, so they are not in effect. Run `wp debloater status` to see why.',
					count,
					'hakeemify-debloater'
				),
				count
			) }
		</Notice>
	);
};

export default RuntimeNotice;
