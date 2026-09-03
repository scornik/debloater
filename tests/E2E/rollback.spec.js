/**
 * A forced probe failure, and the rollback that must follow it.
 *
 * BUILD-SPEC §17 Phase 16, product invariant 9.
 *
 * The most important scenario in the suite. Everything else here checks that WP
 * Debloat does what it says; this checks what happens when it cannot — and the
 * answer has to be that the site goes back exactly as it was, and that the
 * person is told so plainly rather than left to work it out.
 *
 * The failure is produced by `DEBLOATER_TEST_FAIL_PROBE`, a constant this suite
 * writes into the site's config before the run and removes afterwards. A
 * constant rather than a filter on purpose: a rollback path that only fails when
 * a test asks nicely is not the path a real failure takes.
 */

const { test, expect } = require( '@playwright/test' );
const {
	login,
	openDebloat,
	wpCli,
	debloatStatus,
	APPLY_EXIT_CODES,
} = require( './support/wordpress' );

/**
 * Make the named probe fail for every run until it is removed.
 *
 * @param {string} probe Probe name.
 */
async function forceProbeFailure( probe ) {
	await wpCli( [
		'config',
		'set',
		'DEBLOATER_TEST_FAIL_PROBE',
		probe,
		'--type=constant',
		'--allow-root',
		'--skip-themes',
	] );
}

/**
 * Stop forcing it.
 */
async function stopForcingFailure() {
	await wpCli( [ 'config', 'delete', 'DEBLOATER_TEST_FAIL_PROBE', '--type=constant', '--allow-root', '--skip-themes' ] ).catch(
		() => {}
	);
}

test.describe( 'When verification fails', () => {
	test.afterEach( async () => {
		await stopForcingFailure();
		await wpCli( [ 'debloater', 'rollback', '--yes', '--allow-root', '--skip-themes' ], APPLY_EXIT_CODES ).catch( () => {} );
	} );

	test( 'the change is undone and the previous runtime hash is restored', async ( { page } ) => {
		// Every WP-CLI call goes through the wp-env wrapper, which costs about
		// twenty seconds before WordPress even boots. This scenario makes six
		// of them.
		test.setTimeout( 300_000 );

		await login( page );

		// A first, successful apply, so there is a previous state worth
		// restoring. Rolling back to nothing proves less than rolling back to
		// something.
		await wpCli( [ 'debloater', 'scan', '--allow-root', '--skip-themes' ] );
		await wpCli( [
			'debloater',
			'apply',
			'--tweaks=core.remove_generator',
			'--yes',
			'--allow-root',
			'--skip-themes',
		], APPLY_EXIT_CODES );

		const before = await debloatStatus();

		expect( before.runtime.present ).toBe( true );

		const previousHash = before.runtime.hash;

		expect( previousHash ).toBeTruthy();

		await forceProbeFailure( 'rest' );

		// Now apply something else. Verification will fail, and the run must
		// roll itself back rather than leave the site half-changed.
		await wpCli( [
			'debloater',
			'apply',
			'--tweaks=core.remove_generator,core.remove_rsd,core.disable_emojis',
			'--yes',
			'--allow-root',
			'--skip-themes',
		], [ 0, 2, 3 ] ).catch( () => {} );

		const after = await debloatStatus();

		expect( after.runtime.present ).toBe( true );
		expect( after.runtime.hash ).toBe( previousHash );
		expect( after.runtime.matches_state ).toBe( true );

		// And the lock is released. A run that failed and kept the lock would
		// leave the site unable to change anything ever again.
		expect( after.lock.held ).toBe( false );
	} );

	test( 'the screen says the change was undone', async ( { page } ) => {
		test.setTimeout( 300_000 );

		await login( page );

		await wpCli( [ 'debloater', 'scan', '--allow-root', '--skip-themes' ] );
		await forceProbeFailure( 'rest' );

		await openDebloat( page );

		// Scan from the screen rather than relying on whatever an earlier
		// scenario left behind. This test used to skip itself whenever the
		// dashboard happened to open in its empty state, which meant the one
		// assertion about what a person *sees* after a rollback quietly never
		// ran.
		const scan = page.getByRole( 'button', {
			name: /Scan this site|Scan again/,
		} );

		if ( await scan.count() ) {
			await scan.click();
			await expect(
				page.getByRole( 'heading', { name: 'What the scan found' } )
			).toBeVisible( { timeout: 120_000 } );
		}

		const fix = page.getByRole( 'button', { name: 'Fix safe issues' } );

		await expect( fix ).toBeEnabled( { timeout: 30_000 } );

		await fix.click();

		await expect(
			page.getByRole( 'heading', { name: 'Review the change' } )
		).toBeVisible();

		await expect(
			page.getByRole( 'heading', { name: 'What will change' } )
		).toBeVisible( { timeout: 60_000 } );

		await page
			.getByRole( 'button', { name: /^Create (snapshot|recovery)/ } )
			.first()
			.click();

		// The report has to say what happened in words a person can act on.
		await expect(
			page.getByRole( 'heading', { name: 'The change was undone' } )
		).toBeVisible( { timeout: 90_000 } );

		await expect( page.locator( '.debloater-report' ) ).toContainText(
			'Previous configuration restored.'
		);

		// And name the probe that failed, so the reason is visible rather than
		// mysterious.
		await expect( page.locator( '.debloater-report' ) ).toContainText( 'rest' );
	} );
} );
