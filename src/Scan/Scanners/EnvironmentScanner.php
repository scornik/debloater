<?php
/**
 * Facts about the environment the site runs in.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;

/**
 * Collects the `env.*` facts (BUILD-SPEC §5).
 *
 * Host and cache-plugin detection matter later for confidence rather than for
 * recommendations: an unknown host is a reason to be less sure, not a reason to
 * refuse. Both are reported as "unknown" and "none" rather than guessed, because
 * a wrong host identification would silently skew every confidence figure that
 * depends on it.
 */
final class EnvironmentScanner extends AbstractScanner {

	/**
	 * Host signatures, in the order they are checked.
	 *
	 * Each is a constant or server variable the host itself sets. Nothing here
	 * infers a host from something a site owner could plausibly have set.
	 */
	private const HOST_SIGNATURES = array(
		'wpengine'   => array( 'constants' => array( 'WPE_APIKEY', 'IS_WPE', 'WPE_API' ) ),
		'kinsta'     => array( 'constants' => array( 'KINSTA_CACHE_ZONE', 'KINSTAMU_VERSION' ) ),
		'siteground' => array( 'constants' => array( 'SITEGROUND_OPTIMIZER_VERSION', 'SG_CACHEPRESS_VERSION' ) ),
	);

	/**
	 * Cache plugins we recognise, keyed by the fact value they produce.
	 */
	private const CACHE_PLUGINS = array(
		'litespeed-cache' => 'litespeed-cache/litespeed-cache.php',
		'wp-rocket'       => 'wp-rocket/wp-rocket.php',
		'wp-super-cache'  => 'wp-super-cache/wp-cache.php',
		'w3-total-cache'  => 'w3-total-cache/w3-total-cache.php',
	);

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'env';
	}

	/**
	 * Collect environment facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		return array(
			'env.wp_version'   => $context->wp_version,
			'env.php_version'  => $context->php_version,
			'env.host_vendor'  => $this->hostVendor(),
			'env.cache_plugin' => $this->cachePlugin(),
			'env.is_multisite' => $context->is_multisite,
		);
	}

	/**
	 * Identify the host, or report that we could not.
	 *
	 * @return string
	 */
	private function hostVendor(): string {
		foreach ( self::HOST_SIGNATURES as $vendor => $signature ) {
			foreach ( $signature['constants'] as $constant ) {
				if ( $this->constantExists( $constant ) ) {
					return $vendor;
				}
			}
		}

		// LiteSpeed is a web server rather than a host, but it is the signature
		// that matters for the tweaks that interact with its cache.
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		if ( '' !== $software && false !== stripos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		return 'unknown';
	}

	/**
	 * The active page-cache plugin, or "none".
	 *
	 * @return string
	 */
	private function cachePlugin(): string {
		foreach ( self::CACHE_PLUGINS as $slug => $plugin_file ) {
			if ( $this->isPluginActive( $plugin_file ) ) {
				return $slug;
			}
		}

		return 'none';
	}

	/**
	 * Whether a plugin file is active.
	 *
	 * @param string $plugin_file Plugin file, e.g. "wp-rocket/wp-rocket.php".
	 * @return bool
	 */
	private function isPluginActive( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
