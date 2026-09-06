/**
 * The profiles panel.
 *
 * What matters here is what the panel does *not* do: importing a file must
 * hand its selection to the preview and never apply anything
 * (docs/DECISIONS.md D-0063, BUILD-SPEC §13 rule 8). There is no apply call in
 * this component, and the test that would notice one being added is the one
 * asserting nothing but `/profiles/import` was posted.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import Profiles from '../src/components/Profiles';
import * as client from '../src/api/client';

jest.mock( '../src/api/client', () => ( {
	get: jest.fn(),
	post: jest.fn(),
	canManage: () => true,
	pluginVersion: () => '0.1.1',
} ) );

const listing = {
	profiles: [
		{
			id: 'safe',
			name: 'Safe',
			builtin: true,
			changes: 0,
			selection: [],
			document: '{"name":"Safe"}\n',
		},
		{
			id: 'client-baseline',
			name: 'Client baseline',
			builtin: false,
			changes: 2,
			selection: [ 'core.remove_rsd', 'core.disable_emojis' ],
			document: '{"name":"Client baseline"}\n',
		},
	],
	saved: 1,
	max: 50,
};

/**
 * Put a query string on the page, the way a link from Pro's screen would.
 *
 * @param {string} query Query string, or '' for none.
 */
const arriveWith = ( query ) => {
	window.history.replaceState( {}, '', `/wp-admin/admin.php${ query }` );
};

describe( 'Profiles', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		client.get.mockResolvedValue( listing );
		arriveWith( '' );
	} );

	afterEach( () => {
		arriveWith( '' );
	} );

	it( 'lists built-in profiles on a site that has saved none', async () => {
		client.get.mockResolvedValue( {
			profiles: [ listing.profiles[ 0 ] ],
			saved: 0,
			max: 50,
		} );

		render( <Profiles onPreview={ jest.fn() } epoch={ 0 } /> );

		expect( await screen.findByText( 'Safe' ) ).toBeInTheDocument();
		expect( screen.getByText( 'built in' ) ).toBeInTheDocument();
	} );

	it( 'previews a profile rather than applying it', async () => {
		const onPreview = jest.fn();

		render( <Profiles onPreview={ onPreview } epoch={ 0 } /> );

		const preview = await screen.findByRole( 'button', {
			name: 'Preview',
		} );

		await act( async () => {
			fireEvent.click( preview );
		} );

		expect( onPreview ).toHaveBeenCalledWith( [
			'core.remove_rsd',
			'core.disable_emojis',
		] );

		// The panel asked the server for nothing. Applying is the preview's
		// job, behind its own confirmation.
		expect( client.post ).not.toHaveBeenCalled();
	} );

	it( 'saves the current setup under a name', async () => {
		client.post.mockResolvedValue( {
			id: 'client-baseline',
			name: 'Client baseline',
			changes: 2,
			document: '{}',
		} );

		render( <Profiles onPreview={ jest.fn() } epoch={ 0 } /> );

		await act( async () => {
			fireEvent.change(
				screen.getByLabelText( 'Save this setup as a profile' ),
				{ target: { value: 'Client baseline' } }
			);
		} );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save profile' } )
			);
		} );

		await waitFor( () =>
			expect( client.post ).toHaveBeenCalledWith( '/profiles/save', {
				name: 'Client baseline',
			} )
		);
	} );

	it( 'names the changes an imported profile could not use', async () => {
		client.post.mockResolvedValue( {
			id: 'from-elsewhere',
			name: 'From elsewhere',
			selection: [ 'core.remove_rsd' ],
			skipped: [ 'not.a_real_change' ],
			registry_match: false,
			applied: false,
			document: '{}',
		} );

		render( <Profiles onPreview={ jest.fn() } epoch={ 0 } /> );

		const file = new File( [ '{}' ], 'profile.json', {
			type: 'application/json',
		} );

		file.text = () => Promise.resolve( '{}' );

		await act( async () => {
			fireEvent.change(
				document.querySelector( '.debloater-profiles__file' ),
				{ target: { files: [ file ] } }
			);
		} );

		expect(
			await screen.findByText( /not\.a_real_change/ )
		).toBeInTheDocument();

		// The registry warning, and the promise that nothing happened.
		expect(
			screen.getByText( /different version of the change list/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Nothing has been applied/ )
		).toBeInTheDocument();
	} );

	it( 'opens the preview for a profile named in the URL', async () => {
		// Pro's Profiles panel links here rather than applying anything
		// itself; docs/HOOKS.md documents the argument as a contract.
		arriveWith( '?page=debloater&debloater_profile=client-baseline' );

		const onPreview = jest.fn();

		render( <Profiles onPreview={ onPreview } epoch={ 0 } /> );

		await waitFor( () =>
			expect( onPreview ).toHaveBeenCalledWith( [
				'core.remove_rsd',
				'core.disable_emojis',
			] )
		);

		// A link opens a preview and nothing else. The preview issues its own
		// confirmation token, exactly as it does when the button was on this
		// page (BUILD-SPEC §13 rule 8).
		expect( client.post ).not.toHaveBeenCalled();
	} );

	it( 'opens nothing for a profile the site does not have', async () => {
		// A URL somebody edited. It names an id, so the worst it can ask for is
		// a profile this site already has; asking for one it does not have gets
		// nothing rather than a selection of the URL's choosing.
		arriveWith( '?page=debloater&debloater_profile=not-a-profile' );

		const onPreview = jest.fn();

		render( <Profiles onPreview={ onPreview } epoch={ 0 } /> );

		expect( await screen.findByText( 'Client baseline' ) ).toBeInTheDocument();

		expect( onPreview ).not.toHaveBeenCalled();
		expect( client.post ).not.toHaveBeenCalled();
	} );

	it( 'imports without applying', async () => {
		client.post.mockResolvedValue( {
			id: 'from-elsewhere',
			name: 'From elsewhere',
			selection: [ 'core.remove_rsd' ],
			skipped: [],
			registry_match: true,
			applied: false,
			document: '{}',
		} );

		render( <Profiles onPreview={ jest.fn() } epoch={ 0 } /> );

		const file = new File( [ '{}' ], 'profile.json', {
			type: 'application/json',
		} );

		file.text = () => Promise.resolve( '{}' );

		await act( async () => {
			fireEvent.change(
				document.querySelector( '.debloater-profiles__file' ),
				{ target: { files: [ file ] } }
			);
		} );

		await waitFor( () => expect( client.post ).toHaveBeenCalled() );

		// Every call this panel made, and the only write it is allowed.
		const paths = client.post.mock.calls.map( ( call ) => call[ 0 ] );

		expect( paths ).toEqual( [ '/profiles/import' ] );
		expect( paths ).not.toContain( '/apply' );
	} );
} );
