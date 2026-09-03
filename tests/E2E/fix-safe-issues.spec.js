/**
 * The whole "Fix safe issues" flow, end to end, in a browser.
 *
 * BUILD-SPEC §17 Phase 16. This is the path a person actually takes: open the
 * screen, click one button, read what it is about to do, agree, and get a
 * report. Everything under it has integration tests; what this checks is that
 * the path exists and arrives somewhere truthful.
 */

const { test, expect } = require( '@playwright/test' );
const {
	login,
	openDebloat,
	debloatStatus,
	wpCli,
	APPLY_EXIT_CODES,
} = require( './support/wordpress' );

test.describe( 'Fix safe issues', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );

		// Start from nothing selected, so the report is about this run.
		await wpCli( [ 'debloater', 'rollback', '--yes', '--allow-root', '--skip-themes' ], APPLY_EXIT_CODES ).catch( () => {} );
	} );

	test( 'previews, applies and reports', async ( { page } ) => {
		// A scan that fetches a sample of pages, then a preview, then an apply
		// that writes a runtime file and verifies — all through the browser.
		test.setTimeout( 300_000 );

		await openDebloat( page );

		const scan = page.getByRole( 'button', { name: /Scan this site|Scan again/ } );

		if ( await scan.count() ) {
			await scan.click();
			await expect(
				page.getByRole( 'heading', { name: 'What the scan found' } )
			).toBeVisible( { timeout: 90_000 } );
		}

		await page.getByRole( 'button', { name: 'Fix safe issues' } ).click();

		// The dialog says what will change *before* anything does. That
		// ordering is the product: nothing is applied from a button whose
		// consequences have not been shown.
		await expect(
			page.getByRole( 'heading', { name: 'Review the change' } )
		).toBeVisible();

		await expect(
			page.getByRole( 'heading', { name: 'What will change' } )
		).toBeVisible( { timeout: 60_000 } );

		// And what recovery it takes first, which is not optional.
		await expect(
			page.getByRole( 'heading', { name: 'Recovery taken first' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: /^Create (snapshot|recovery)/ } )
			.first()
			.click();

		// Applying writes the runtime file, reloads it, verifies over loopback
		// and measures before and after.
		await expect(
			page.locator( '.debloater-report' )
		).toBeVisible( { timeout: 90_000 } );

		const report = await page.locator( '.debloater-report' ).innerText();

		expect( report ).toMatch( /optimization[s]? applied|The change was undone/ );
	} );

	test( 'leaves the runtime file and the recorded hash in agreement', async () => {
		test.setTimeout( 180_000 );

		await wpCli(
			[ 'debloater', 'apply', '--tweaks=core.remove_generator', '--yes', '--allow-root', '--skip-themes' ],
			APPLY_EXIT_CODES
		);

		const status = await debloatStatus();

		expect( status.runtime.present ).toBe( true );
		expect( status.runtime.matches_state ).toBe( true );

		// The loader is what makes the runtime load at all. A runtime on disk
		// with no loader is a change that silently does nothing.
		expect( status.loader.installed ).toBe( true );
	} );

	test( 'can be undone completely', async () => {
		test.setTimeout( 180_000 );

		// Apply something first. Undoing nothing proves nothing, and the
		// assertion below is only interesting if there was a runtime file to
		// remove.
		await wpCli(
			[ 'debloater', 'apply', '--tweaks=core.remove_generator', '--yes', '--allow-root', '--skip-themes' ],
			APPLY_EXIT_CODES
		);

		expect( ( await debloatStatus() ).runtime.present ).toBe( true );

		await wpCli( [ 'debloater', 'rollback', '--yes', '--allow-root', '--skip-themes' ], APPLY_EXIT_CODES );

		const status = await debloatStatus();

		// Empty selection produces no runtime file at all (product invariant
		// 10). Not an empty file — no file.
		expect( status.runtime.present ).toBe( false );
	} );
} );
