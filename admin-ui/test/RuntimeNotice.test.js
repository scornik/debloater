/**
 * The warning for stored changes that did not register.
 *
 * The keys are the ones `GET /status` sends, written as literals because the
 * PHP side's tests pin the same literals (P4).
 */

import { render, screen } from '@testing-library/react';

import RuntimeNotice, {
	unregisteredCount,
} from '../src/components/RuntimeNotice';

describe( 'RuntimeNotice', () => {
	const stored = [
		'Debloater_Handler_Core_Remove_Generator',
		'Debloater_Handler_Core_Remove_Rsd',
	];

	it( 'says nothing when every stored change registered', () => {
		const { container } = render(
			<RuntimeNotice runtime={ { stored, registered: stored } } />
		);

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'counts the stored changes that did not register', () => {
		render(
			<RuntimeNotice
				runtime={ {
					stored,
					registered: [ 'Debloater_Handler_Core_Remove_Generator' ],
				} }
			/>
		);

		expect(
			screen.getByText( /1 applied change did not load on this site/ )
		).toBeInTheDocument();
	} );

	it( 'warns when the guard registered nothing', () => {
		expect(
			unregisteredCount( { guard: 'disabled', stored, registered: [] } )
		).toBe( 2 );
	} );

	it( 'claims nothing from a status without the report', () => {
		expect( unregisteredCount( { handlers: 2 } ) ).toBe( 0 );
		expect( unregisteredCount( undefined ) ).toBe( 0 );
	} );
} );
