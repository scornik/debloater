<?php
/**
 * Seed the wp-env development site with what the end-to-end scenarios need.
 *
 * The E2E suite drives a real browser through a real store: it buys a product,
 * submits a Contact Form 7 form and opens the Elementor editor. None of those
 * exist on a fresh install, so this creates them.
 *
 * Run it with:
 *
 *     npm run test:e2e:seed
 *
 * Idempotent by slug: running it twice leaves one of each rather than two. It
 * refuses to run anywhere but a local environment, because it creates a shop
 * with a product in it and that is not something to do to somebody's site by
 * accident.
 *
 * @package WPDebloat
 */

/*
 * No `declare( strict_types = 1 )` here on purpose. `wp eval-file` runs a script
 * through eval(), where a declare must be the very first statement of the file
 * and therefore cannot be — the file fatals before it does anything. Every other
 * PHP file in this repository declares strict types; these two are eval'd rather
 * than included, which is the whole difference.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error(
		'This script creates fixture content and will only run on a local environment. '
		. 'WP_ENVIRONMENT_TYPE is currently "' . wp_get_environment_type() . '".'
	);
}

/**
 * Find a page or post by slug, or create it.
 *
 * @param string $slug    Post slug.
 * @param string $title   Post title.
 * @param string $content Post content.
 * @param string $type    Post type.
 * @return int
 */
function wpdebloat_e2e_page( string $slug, string $title, string $content, string $type = 'page' ): int {
	$existing = get_page_by_path( $slug, OBJECT, $type );

	if ( $existing instanceof WP_Post ) {
		wp_update_post(
			array(
				'ID'           => $existing->ID,
				'post_content' => $content,
			)
		);

		return (int) $existing->ID;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => $content,
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

// ---------------------------------------------------------------- Contact Form 7
$form_id = 0;

if ( class_exists( 'WPCF7_ContactForm' ) ) {
	$forms = get_posts(
		array(
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => 1,
			'post_status'    => 'any',
		)
	);

	if ( array() !== $forms ) {
		$form_id = (int) $forms[0]->ID;
	}
}

if ( $form_id > 0 ) {
	wpdebloat_e2e_page(
		'contact-form-7',
		'Contact',
		sprintf( '[contact-form-7 id="%d"]', $form_id ),
		'page'
	);

	WP_CLI::log( sprintf( 'Contact page carries form %d.', $form_id ) );
} else {
	WP_CLI::warning( 'Contact Form 7 is not active, so no contact page was created.' );
}

// --------------------------------------------------------------- An ordinary page
wpdebloat_e2e_page(
	'about-this-site',
	'About this site',
	'<p>An ordinary page, for the scenarios that need one that is not a shop.</p>',
	'page'
);

// ------------------------------------------------------------------- WooCommerce
if ( class_exists( 'WooCommerce' ) ) {
	$product = get_page_by_path( 'a-test-product', OBJECT, 'product' );

	if ( ! $product instanceof WP_Post ) {
		$product_id = wp_insert_post(
			array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_name'    => 'a-test-product',
				'post_title'   => 'A test product',
				'post_content' => 'Something to put in a cart.',
			)
		);

		if ( ! is_wp_error( $product_id ) ) {
			// A simple, in-stock, purchasable product. Anything less and the
			// add-to-cart button does not render, and the scenario would skip
			// rather than fail — which is the worst of both.
			update_post_meta( $product_id, '_price', '9.99' );
			update_post_meta( $product_id, '_regular_price', '9.99' );
			update_post_meta( $product_id, '_stock_status', 'instock' );
			update_post_meta( $product_id, '_manage_stock', 'no' );
			update_post_meta( $product_id, '_virtual', 'yes' );
			update_post_meta( $product_id, '_downloadable', 'no' );
			update_post_meta( $product_id, '_sold_individually', 'no' );

			wp_set_object_terms( (int) $product_id, 'simple', 'product_type' );

			WP_CLI::log( sprintf( 'Created product %d.', (int) $product_id ) );
		}
	} else {
		WP_CLI::log( sprintf( 'Product %d already exists.', (int) $product->ID ) );
	}

	// Guest checkout, so the scenario buys something the way a customer would
	// rather than the way an administrator would.
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );

	// Cart fragments are only enqueued at all when AJAX add-to-cart is on, and
	// the whole point of woo.cart_fragments_conditional is what happens to that
	// script. Without this the scenario would pass by measuring nothing.
	update_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' );
	update_option( 'woocommerce_cart_redirect_after_add', 'no' );

	// A fresh WooCommerce ships with "coming soon" on, which serves every
	// visitor a launch page instead of the shop. A scenario that browsed that
	// would be checking a placeholder and reporting it as a working checkout.
	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_store_pages_only', 'no' );

	WP_CLI::log( 'Guest checkout is on.' );
} else {
	WP_CLI::warning( 'WooCommerce is not active, so no product was created.' );
}

// --------------------------------------------------------------------- Elementor
if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$page_id = wpdebloat_e2e_page(
		'built-with-elementor',
		'Built with Elementor',
		'<p>A page with a saved Elementor design on it.</p>',
		'page'
	);

	if ( $page_id > 0 ) {
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$page_id,
			'_elementor_data',
			wp_slash(
				(string) wp_json_encode(
					array(
						array(
							'id'       => 'aaa111',
							'elType'   => 'container',
							'settings' => array(),
							'elements' => array(
								array(
									'id'         => 'bbb222',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Built with Elementor' ),
								),
							),
						),
					)
				)
			)
		);

		WP_CLI::log( sprintf( 'Elementor page %d has a saved design.', $page_id ) );
	}
} else {
	WP_CLI::warning( 'Elementor is not active, so no Elementor page was created.' );
}

WP_CLI::success( 'Seeded the end-to-end fixtures.' );
