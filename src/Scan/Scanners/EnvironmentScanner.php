<?php
/**
 * Facts about the environment the site runs in.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;
use Debloater\Scan\HostVendor;

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
			'env.host_vendor'  => HostVendor::identify(),
			'env.cache_plugin' => $this->cachePlugin(),
			'env.is_multisite' => $context->is_multisite,
		);
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
