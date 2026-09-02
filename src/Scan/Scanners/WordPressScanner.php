<?php
/**
 * Facts about WordPress configuration.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;

/**
 * Collects the configuration half of the `wp.*` facts (BUILD-SPEC §5).
 *
 * The `wp` namespace has two owners: this scanner reports configuration —
 * constants, options and settings — while CoreFeatureScanner reports which of
 * core's optional output features are currently switched on. They write
 * disjoint key sets and ScanRunner refuses any overlap, so the split costs
 * nothing and keeps each file about one thing.
 *
 * Every value here is read the way WordPress itself would read it, filters
 * included. Reading the raw constant instead would report what the site was
 * configured to do rather than what it actually does.
 */
final class WordPressScanner extends AbstractScanner {

	/**
	 * The interval WordPress uses when nothing overrides it.
	 *
	 * The Heartbeat API has no server-side default option: the JavaScript falls
	 * back to 15 seconds in the admin, and `heartbeat_settings` is the only way
	 * to change it. Applying the filter to an empty array is therefore the
	 * honest way to ask "what interval will this site actually use?".
	 */
	private const DEFAULT_HEARTBEAT_INTERVAL = 15;

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'wp';
	}

	/**
	 * Collect configuration facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		return array(
			'wp.heartbeat_interval'  => $this->heartbeatInterval(),
			'wp.xmlrpc_enabled'      => $this->xmlrpcEnabled(),
			'wp.revisions_limit'     => $this->revisionsLimit(),
			'wp.file_editor_enabled' => ! $this->constantIsTrue( 'DISALLOW_FILE_EDIT' ),
			'wp.debug'               => $this->constantIsTrue( 'WP_DEBUG' ),
		);
	}

	/**
	 * The Heartbeat interval this site will actually use, in seconds.
	 *
	 * @return int
	 */
	private function heartbeatInterval(): int {
		/**
		 * This is WordPress's own filter, applied here to observe the result
		 * rather than to change it.
		 *
		 * @param array<string,mixed> $settings Heartbeat settings.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is WordPress's own filter, applied to read its result. Prefixing it would ask a question nobody answers.
		$settings = apply_filters( 'heartbeat_settings', array() );

		if ( is_array( $settings ) && isset( $settings['interval'] ) && is_numeric( $settings['interval'] ) ) {
			return (int) $settings['interval'];
		}

		return self::DEFAULT_HEARTBEAT_INTERVAL;
	}

	/**
	 * Whether XML-RPC will answer requests.
	 *
	 * Both conditions matter: the endpoint file has to exist, and the filter
	 * WordPress consults has to say yes.
	 *
	 * @return bool
	 */
	private function xmlrpcEnabled(): bool {
		if ( ! file_exists( ABSPATH . 'xmlrpc.php' ) ) {
			return false;
		}

		/**
		 * WordPress's own filter, observed rather than changed.
		 *
		 * @param bool $enabled Whether XML-RPC methods requiring authentication are enabled.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above: core's filter, read the way core reads it.
		return (bool) apply_filters( 'xmlrpc_enabled', true );
	}

	/**
	 * The revision limit: -1 for unlimited, 0 for disabled, otherwise the cap.
	 *
	 * @return int
	 */
	private function revisionsLimit(): int {
		if ( ! defined( 'WP_POST_REVISIONS' ) ) {
			return -1;
		}

		$limit = constant( 'WP_POST_REVISIONS' );

		if ( true === $limit ) {
			return -1;
		}

		if ( false === $limit ) {
			return 0;
		}

		if ( is_numeric( $limit ) ) {
			return max( -1, (int) $limit );
		}

		return -1;
	}
}
