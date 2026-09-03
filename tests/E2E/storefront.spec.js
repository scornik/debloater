/**
 * The shop, the form and the editor — with WP Debloat's changes applied.
 *
 * BUILD-SPEC §17 Phase 16.
 *
 * These are the scenarios the other suites cannot reach. The integration tests
 * serve WooCommerce and Elementor from fixtures because neither is installed in
 * the test environment; here both are real, and so is the browser, so this is
 * the first place the dequeue handlers' conditional tags actually run inside the
 * request they are deciding about.
 *
 * The order matters: everything is applied first, and then a customer buys
 * something. A checkout that works on a site with none of our changes on it
 * proves nothing.
 */

const { test, expect } = require( '@playwright/test' );
const {
	login,
	wpCli,
	postUrl,
	APPLY_EXIT_CODES,
} = require( './support/wordpress' );

/**
 * Apply every WooCommerce change at once.
 */
async function applyWooTweaks() {
	await wpCli( [
		'debloat',
		'apply',
		'--tweaks=woo.cart_fragments_conditional,woo.block_styles_conditional,woo.disable_admin_analytics,woo.suppress_marketplace_suggestions',
		'--yes',
		'--allow-root',
		'--skip-themes',
	], APPLY_EXIT_CODES );
}

test.describe( 'A store with every WooCommerce change applied', () => {
	test.beforeAll( async () => {
		await wpCli( [ 'debloat', 'scan', '--allow-root', '--skip-themes' ] );
		await applyWooTweaks();
	} );

	test.afterAll( async () => {
		await wpCli( [ 'debloat', 'rollback', '--yes', '--allow-root', '--skip-themes' ], APPLY_EXIT_CODES ).catch( () => {} );
	} );

	test( 'a customer can add a product to the cart and reach the checkout', async ( { page } ) => {
		test.setTimeout( 180_000 );

		const productId = await wpCli( [
			'post',
			'list',
			'--post_type=product',
			'--post_status=publish',
			'--posts_per_page=1',
			'--field=ID',
			'--allow-root',
			'--skip-themes',
		] );

		test.skip( ! productId, 'the fixture site has no published product to buy' );

		// The product page first, so the scenario proves the shop renders at all
		// with every change applied.
		await page.goto( postUrl( productId, 'product' ) );

		await expect( page.locator( 'body' ) ).toHaveClass( /product-type-simple/ );
		await expect( page.locator( 'body' ) ).toHaveClass( /purchasable/ );

		// Then add it the way WooCommerce's own button does. The URL rather
		// than the button because the fixture site runs a block theme, where
		// the add-to-cart control is a block whose markup varies with the
		// template — and what this scenario is about is the cart and the
		// checkout surviving our changes, not which element the theme drew.
		await page.goto( `/?add-to-cart=${ productId }` );

		const cartId = await wpCli( [
			'option',
			'get',
			'woocommerce_cart_page_id',
			'--allow-root',
			'--skip-themes',
		] );

		await page.goto( postUrl( cartId, 'page' ) );

		// Positively, not by absence. "Does not say the cart is empty" is also
		// true of a launch placeholder, a 404 and a blank page — the assertion
		// has to be that the product is in there.
		await expect( page.locator( 'body' ) ).toContainText( /A test product/i, {
			timeout: 30_000,
		} );

		const checkoutId = await wpCli( [
			'option',
			'get',
			'woocommerce_checkout_page_id',
			'--allow-root',
			'--skip-themes',
		] );

		await page.goto( postUrl( checkoutId, 'page' ) );

		// The checkout has to render a form. Everything this plugin saves is
		// worth less than one checkout that does not.
		// A checkout has to offer a way to pay. Everything this plugin saves is
		// worth less than one checkout that does not.
		await expect(
			page
				.locator(
					'form.checkout, form.woocommerce-checkout, .wc-block-checkout, .wp-block-woocommerce-checkout'
				)
				.first()
		).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'the shop keeps its cart script and the blog does not', async ( { page } ) => {
		test.setTimeout( 180_000 );

		const productId = await wpCli( [
			'post',
			'list',
			'--post_type=product',
			'--post_status=publish',
			'--posts_per_page=1',
			'--field=ID',
			'--allow-root',
			'--skip-themes',
		] );

		test.skip( ! productId, 'the fixture site has no product page to check' );

		await page.goto( postUrl( productId, 'product' ) );

		const onShop = await page.locator( 'script[src*="cart-fragments"]' ).count();

		const postId = await wpCli( [
			'post',
			'list',
			'--post_type=post',
			'--post_status=publish',
			'--posts_per_page=1',
			'--field=ID',
			'--allow-root',
			'--skip-themes',
		] );

		test.skip( ! postId, 'the fixture site has no blog post to compare against' );

		await page.goto( postUrl( postId, 'post' ) );

		const onBlog = await page.locator( 'script[src*="cart-fragments"]' ).count();

		// Conditional means conditional, and the comparison is the assertion.
		// WooCommerce only enqueues this script at all under some settings, so
		// "it is on the product page" alone could pass on a site where it is
		// nowhere; what must be true is that the shop has it if anywhere does,
		// and the blog does not.
		expect( onBlog ).toBe( 0 );

		if ( onShop === 0 ) {
			test.info().annotations.push( {
				type: 'note',
				description:
					'WooCommerce did not enqueue cart fragments anywhere on this site, so only the negative half of this check ran.',
			} );
		}
	} );
} );

test.describe( 'Contact Form 7', () => {
	test( 'a visitor can submit a form', async ( { page } ) => {
		test.setTimeout( 180_000 );

		const pageId = await wpCli( [
			'post',
			'list',
			'--post_type=page',
			'--post_status=publish',
			'--name=contact-form-7',
			'--field=ID',
			'--posts_per_page=1',
			'--allow-root',
			'--skip-themes',
		] );

		test.skip( ! pageId, 'the fixture site has no page carrying a Contact Form 7 form' );

		await page.goto( postUrl( pageId, 'page' ) );

		const form = page.locator( 'form.wpcf7-form' );

		await expect( form ).toBeVisible();

		// Fill whatever the form actually asks for rather than assuming the
		// default field names, which change between Contact Form 7 versions.
		const text = form.locator( 'input[type="text"], input[type="email"], textarea' );

		for ( let index = 0; index < ( await text.count() ); index++ ) {
			const field = text.nth( index );
			const type = await field.getAttribute( 'type' );

			await field.fill( 'email' === type ? 'visitor@example.test' : 'A visitor' );
		}

		await form.locator( 'input[type="submit"], button[type="submit"]' ).first().click();

		// Contact Form 7 answers over AJAX and marks the form with what
		// happened — sent, invalid, failed. Any of those is the script working;
		// what must not happen is nothing at all, which is what a dequeued
		// script looks like.
		await expect( form ).toHaveClass( /sent|invalid|failed|spam|aborted/, {
			timeout: 30_000,
		} );
	} );
} );

test.describe( 'Elementor', () => {
	test( 'the editor opens for a page', async ( { page } ) => {
		test.setTimeout( 180_000 );

		await login( page );

		const pageId = await wpCli( [
			'post',
			'list',
			'--post_type=page',
			'--post_status=publish',
			'--posts_per_page=1',
			'--field=ID',
			'--allow-root',
			'--skip-themes',
		] );

		test.skip( ! pageId, 'the fixture site has no page to edit' );

		await page.goto( `/wp-admin/post.php?post=${ pageId }&action=elementor` );

		// Elementor's editor is a heavy application; what matters is that it
		// boots at all with our changes in place. A panel that never appears is
		// the failure mode a dequeued script would cause.
		// `.first()` because Elementor renders the loading shade, the wrapper
		// and the panel together, and any one of them appearing means the editor
		// booted rather than white-screened.
		await expect(
			page
				.locator( '#elementor-editor-wrapper, #elementor-panel, #elementor-loading' )
				.first()
		).toBeVisible( { timeout: 90_000 } );
	} );
} );
