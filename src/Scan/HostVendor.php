<?php
/**
 * Which host this site is on, if we can tell.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan;

/**
 * Host identification, in one place (BUILD-SPEC §5).
 *
 * Two scanners need this answer and they must not be allowed to disagree.
 * `EnvironmentScanner` writes it as `env.host_vendor`; `PluginScanner` needs it
 * to recognise a host's own optimizer, and cannot read another scanner's facts
 * because scanners are deliberately isolated from one another. Rather than have
 * the second one re-implement the signatures, both ask here.
 *
 * Every signature is something the host itself sets. Nothing here infers a host
 * from anything a site owner could plausibly have set themselves: a wrong host
 * identification would quietly skew every confidence figure that depends on it,
 * and — since Phase 11 — would also put a plugin's name in front of a user as
 * something they own when they do not.
 */
final class HostVendor {

	/**
	 * The value meaning no signature matched.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Host signatures, in the order they are checked.
	 */
	private const SIGNATURES = array(
		'wpengine'   => array( 'WPE_APIKEY', 'IS_WPE', 'WPE_API' ),
		'kinsta'     => array( 'KINSTA_CACHE_ZONE', 'KINSTAMU_VERSION' ),
		'siteground' => array( 'SITEGROUND_OPTIMIZER_VERSION', 'SG_CACHEPRESS_VERSION' ),
	);

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Identify the host, or report that we could not.
	 *
	 * @return string
	 */
	public static function identify(): string {
		foreach ( self::SIGNATURES as $vendor => $constants ) {
			foreach ( $constants as $constant ) {
				if ( defined( $constant ) ) {
					return $vendor;
				}
			}
		}

		// LiteSpeed is a web server rather than a host, but it is the signature
		// that matters for the tweaks that interact with its cache.
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		if ( '' !== $software && false !== stripos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}

		return self::UNKNOWN;
	}
}
