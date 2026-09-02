/**
 * Loading, failed, or here.
 *
 * Three states, named, in one place. Screens that track `isLoading` and `data`
 * separately end up rendering "no findings" for the half-second before the
 * findings arrive, which is a lie told by accident.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export const useResource = ( loader, dependencies = [] ) => {
	const [ state, setState ] = useState( {
		status: 'loading',
		data: null,
		error: null,
	} );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;

		return () => {
			mounted.current = false;
		};
	}, [] );

	const load = useCallback( async () => {
		setState( ( previous ) => ( {
			...previous,
			status: 'loading',
			error: null,
		} ) );

		try {
			const data = await loader();

			if ( mounted.current ) {
				setState( { status: 'ready', data, error: null } );
			}

			return data;
		} catch ( error ) {
			if ( mounted.current ) {
				setState( { status: 'error', data: null, error } );
			}

			return null;
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, dependencies );

	useEffect( () => {
		load();
	}, [ load ] );

	return { ...state, reload: load };
};
