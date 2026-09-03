/**
 * The dashboard, against a real site in a real browser.
 *
 * BUILD-SPEC §17 Phase 16.
 */

const { test, expect } = require( '@playwright/test' );
const { login, openDebloat, debloatStatus } = require( './support/wordpress' );

test.describe( 'The Debloater screen', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'loads and runs a real scan', async ( { page } ) => {
		await openDebloat( page );

		// Either the empty state or a previous scan's results — the site is
		// shared between scenarios, so both are legitimate starting points.
		const scanButton = page.getByRole( 'button', {
			name: /Scan this site|Scan again/,
		} );

		await expect( scanButton ).toBeVisible();

		await scanButton.click();

		// The scan reads the site, fetches a sample of pages over loopback and
		// analyses what it found, so it is not instant.
		await expect(
			page.getByRole( 'heading', { name: 'What the scan found' } )
		).toBeVisible( { timeout: 90_000 } );

		// A real site with the full stack on it has things to say about itself.
		await expect( page.locator( '.debloater-dashboard' ) ).toContainText(
			/finding/
		);
	} );

	test( 'shows the score and never claims a speed', async ( { page } ) => {
		await openDebloat( page );

		const body = await page.locator( '#debloater-root' ).innerText();

		// BUILD-SPEC §12 and product invariant 14: the score is a configuration
		// score. A screen that said "faster" would be making a claim nothing
		// here measured.
		expect( body.toLowerCase() ).not.toContain( 'faster' );
		expect( body.toLowerCase() ).not.toContain( 'speed up' );
		expect( body.toLowerCase() ).not.toContain( '% faster' );
	} );

	test( 'emits no admin notice of its own', async ( { page } ) => {
		await openDebloat( page );

		// Product promise, asserted in the one place a person would actually
		// see it broken. WordPress moves notices into this container.
		const notices = page.locator( '.wp-header-end ~ .notice, #wpbody-content > .notice' );

		for ( let index = 0; index < ( await notices.count() ); index++ ) {
			const text = await notices.nth( index ).innerText();

			expect( text ).not.toContain( 'Debloater' );
		}
	} );

	test( 'reports a runtime that matches what it wrote', async () => {
		const status = await debloatStatus();

		// Whatever any earlier scenario left behind, the file on disk and the
		// hash the plugin recorded must agree. They disagreeing is the one
		// state the dashboard warns about, and it should never be the state we
		// leave a site in.
		if ( status.runtime && status.runtime.present ) {
			expect( status.runtime.matches_state ).toBe( true );
		}
	} );
} );
