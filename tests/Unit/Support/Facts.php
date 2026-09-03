<?php
/**
 * Fact-set builders for analyzer tests.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\Support;

use Debloater\Contracts\FactSet;

/**
 * Realistic fact sets, so a rule test reads like a description of a site.
 *
 * The fact sets here are complete enough that every rule's supports() passes,
 * which matters: a rule that quietly cannot evaluate would otherwise look like a
 * rule that decided not to fire, and the tests would prove nothing.
 */
final class Facts {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * A default WordPress install with nothing configured and nothing accumulated.
	 *
	 * Every core feature is on, because that is what a fresh install looks like.
	 *
	 * @param array<string,mixed> $overrides Facts to override.
	 * @return FactSet
	 */
	public static function freshInstall( array $overrides = array() ): FactSet {
		return FactSet::fromArray(
			array_merge(
				array(
					'env.wp_version'                => '6.8.1',
					'env.php_version'               => '8.2.19',
					'env.host_vendor'               => 'kinsta',
					'env.cache_plugin'              => 'none',
					'env.is_multisite'              => false,

					'wp.heartbeat_interval'         => 15,
					'wp.xmlrpc_enabled'             => true,
					'wp.emojis_enabled'             => true,
					'wp.embeds_enabled'             => true,
					'wp.rss_enabled'                => true,
					'wp.generator_tag'              => true,
					'wp.rsd_link'                   => true,
					'wp.shortlink'                  => true,
					'wp.self_pingbacks'             => true,
					'wp.dashicons_frontend'         => false,
					'wp.jquery_migrate'             => true,
					'wp.revisions_limit'            => -1,
					'wp.file_editor_enabled'        => true,
					'wp.debug'                      => false,

					'users.admin_count'             => 1,
					'users.recent_editors_7d'       => 0,

					'plugins.active'                => array(),
					'plugins.inactive'              => array(),
					'plugins.meta'                  => array(),
					'plugins.detected'              => self::noDetections(),
					'plugins.categories'            => array(),
					'plugins.update_source'         => 'file_mtime',
					'plugins.host_optimizers'       => array(),

					'theme.active'                  => 'twentytwentyfour',
					'theme.parent'                  => null,

					'db.size_bytes'                 => 2048000,
					'db.revisions.count'            => 4,
					'db.autodrafts.count'           => 0,
					'db.trash.count'                => 0,
					'db.spam_comments.count'        => 0,
					'db.transients.count'           => 3,
					'db.transients.expired'         => 0,
					'db.orphan_postmeta.count'      => 0,
					'db.orphan_termmeta.count'      => 0,
					'db.orphan_usermeta.count'      => 0,
					'db.autoload.bytes'             => 120000,
					'db.autoload.top'               => array(),

					'admin.notices.count'           => 0,
					'admin.notices'                 => array(),
					'admin.notice_vendors'          => array(),
					'admin.dashboard_widgets.count' => 4,
					'admin.dashboard_widgets'       => self::coreDashboard(),
					'admin.menu_items.count'        => 2,
					'admin.menu_items'              => array(
						array(
							'slug'   => 'index.php',
							'source' => 'wordpress',
						),
						array(
							'slug'   => 'plugins.php',
							'source' => 'wordpress',
						),
					),
					'admin.scripts.count'           => 1,
					'admin.scripts'                 => array(
						array(
							'handle' => 'common',
							'source' => 'wordpress',
						),
					),
					'admin.styles.count'            => 1,
					'admin.styles'                  => array(
						array(
							'handle' => 'wp-admin',
							'source' => 'wordpress',
						),
					),
					'admin.welcome_panel'           => true,
					'admin.update_nag'              => false,

					'assets.available'              => true,
					'assets.pages_sampled'          => 2,
					'assets.pages_offered'          => 2,
					'assets.elapsed_ms'             => 180,
					'assets.post_types'             => array( 'home', 'post' ),
					'assets.scripts.count'          => 1,
					'assets.scripts'                => array(
						array(
							'handle' => 'jquery-core',
							'source' => 'wordpress',
							'pages'  => 2,
							'bytes'  => 89476,
						),
					),
					'assets.styles.count'           => 1,
					'assets.styles'                 => array(
						array(
							'handle' => 'wp-block-library',
							'source' => 'wordpress',
							'pages'  => 2,
							'bytes'  => 84329,
						),
					),
					'assets.external_hosts'         => array(),
					'assets.google_fonts'           => false,
					'assets.cf7_asset_pages'        => 0,
					'assets.cf7_form_pages'         => 0,

					'woo.present'                   => false,
					'elementor.present'             => false,

					'cron.events.count'             => 12,
					'cron.events.subminute'         => array(),
					'cron.orphans.count'            => 0,
					'cron.disable_wp_cron'          => false,
				),
				$overrides
			)
		);
	}

	/**
	 * A busy WooCommerce store: several editors, plenty accumulated, a cache
	 * plugin in front, and a host we do not recognise.
	 *
	 * This is the site the Heartbeat refusal exists for.
	 *
	 * @param array<string,mixed> $overrides Facts to override.
	 * @return FactSet
	 */
	public static function busyStore( array $overrides = array() ): FactSet {
		return self::freshInstall(
			array_merge(
				array(
					'env.host_vendor'             => 'unknown',
					'env.cache_plugin'            => 'litespeed-cache',
					'users.admin_count'           => 4,
					'users.recent_editors_7d'     => 3,
					'plugins.active'              => array(
						'woocommerce/woocommerce.php',
						'contact-form-7/wp-contact-form-7.php',
						'litespeed-cache/litespeed-cache.php',
					),
					'plugins.inactive'            => array( 'hello-dolly/hello.php', 'akismet/akismet.php' ),
					'plugins.meta'                => self::meta(
						array(
							'woocommerce/woocommerce.php' => 'WooCommerce',
							'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
							'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
						)
					),
					'plugins.detected'            => self::detections(
						array( 'woocommerce', 'contact-form-7', 'litespeed-cache' )
					),
					'plugins.categories'          => array(
						array(
							'plugin'   => 'litespeed-cache',
							'category' => 'cache',
							'label'    => 'Page caching',
						),
						array(
							'plugin'   => 'contact-form-7',
							'category' => 'forms',
							'label'    => 'Forms',
						),
					),
					'plugins.host_optimizers'     => array(
						array(
							'id'      => 'litespeed-cache',
							'name'    => 'LiteSpeed Cache',
							'finding' => 'wp.emojis.loaded',
						),
					),
					'woo.present'                 => true,
					'woo.version'                 => '9.2.0',
					'woo.pages_sampled'           => 4,
					'woo.shop_pages'              => array( '/shop/', '/product/a-thing/' ),
					'woo.other_pages'             => array( '/', '/hello-world/' ),
					'woo.fragments_on_other'      => array( '/', '/hello-world/' ),
					'woo.block_styles_on_other'   => array( '/hello-world/' ),
					'woo.mini_cart_pages'         => array(),
					'woo.mini_cart'               => false,
					'woo.admin_analytics'         => true,
					'woo.marketplace_suggestions' => true,
					'assets.cf7_asset_pages'      => 2,
					'assets.cf7_form_pages'       => 1,
					'assets.external_hosts'       => array(
						array(
							'host'  => 'fonts.googleapis.com',
							'count' => 2,
						),
					),
					'assets.google_fonts'         => true,
					'admin.notices.count'         => 5,
					'admin.notices'               => array(
						array(
							'hook'   => 'admin_notices',
							'source' => 'woocommerce',
						),
						array(
							'hook'   => 'admin_notices',
							'source' => 'woocommerce',
						),
						array(
							'hook'   => 'all_admin_notices',
							'source' => 'woocommerce',
						),
						array(
							'hook'   => 'admin_notices',
							'source' => 'wordpress-seo',
						),
						array(
							'hook'   => 'admin_notices',
							'source' => 'unknown',
						),
					),
					'admin.notice_vendors'        => array(
						array(
							'vendor' => 'woocommerce',
							'name'   => 'WooCommerce',
							'source' => 'woocommerce',
						),
						array(
							'vendor' => 'yoast',
							'name'   => 'Yoast SEO',
							'source' => 'wordpress-seo',
						),
					),
					'admin.update_nag'            => true,
					'theme.active'                => 'storefront',
					'db.revisions.count'          => 31421,
					'db.transients.count'         => 5210,
					'db.transients.expired'       => 4832,
					'db.autoload.bytes'           => 9646080,
				),
				$overrides
			)
		);
	}

	/**
	 * The four widgets a default WordPress dashboard registers.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function coreDashboard(): array {
		$widgets = array();

		foreach ( array( 'dashboard_activity', 'dashboard_primary', 'dashboard_quick_press', 'dashboard_right_now' ) as $id ) {
			$widgets[] = array(
				'id'     => $id,
				'source' => 'wordpress',
			);
		}

		return $widgets;
	}

	/**
	 * Plugin metadata for the given files, all recently updated.
	 *
	 * Recent on purpose: the abandoned-plugin rule should stay quiet on the
	 * general-purpose fixtures, so the tests that care about it can set their own
	 * dates without every other test acquiring a finding it did not ask for.
	 *
	 * @param array<string,string> $plugins Plugin file to display name.
	 * @param int|null             $mtime   Modification time; now when omitted.
	 * @return array<string,array<string,mixed>>
	 */
	public static function meta( array $plugins, ?int $mtime = null ): array {
		$meta = array();

		foreach ( $plugins as $plugin_file => $name ) {
			$meta[ $plugin_file ] = array(
				'name'       => $name,
				'version'    => '1.0.0',
				'file_mtime' => $mtime ?? time(),
			);
		}

		ksort( $meta, SORT_STRING );

		return $meta;
	}

	/**
	 * Every detector reporting absent.
	 *
	 * @return array<string,bool>
	 */
	public static function noDetections(): array {
		return self::detections( array() );
	}

	/**
	 * Detector results with the named slugs present and the rest absent.
	 *
	 * Every slug is always reported, present or not: an absent key would be
	 * indistinguishable from "we did not look".
	 *
	 * @param array<int,string> $present Slugs that are present.
	 * @return array<string,bool>
	 */
	public static function detections( array $present ): array {
		$all = array(
			'contact-form-7',
			'elementor',
			'elementor-pro',
			'litespeed-cache',
			'rank-math',
			'woocommerce',
			'wordfence',
			'wp-rocket',
			'wp-super-cache',
			'yoast',
		);

		$detected = array();

		foreach ( $all as $slug ) {
			$detected[ $slug ] = in_array( $slug, $present, true );
		}

		return $detected;
	}
}
