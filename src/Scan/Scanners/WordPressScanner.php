<?php
/**
 * Facts about WordPress configuration.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;

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
	 * The Heartbeat API has no server-side default option. The JavaScript starts
	 * from `mainInterval: 60` (`wp-includes/js/heartbeat.js`), and
	 * `heartbeat_settings` is the only way a site changes that. Applying the
	 * filter to an empty array is therefore the honest way to ask "what interval
	 * will this site actually use?", and 60 is the answer when nothing sets one.
	 *
	 * This was 15 until 0.5.0, which made `wp.heartbeat.aggressive` (under 60)
	 * fire on every site that had never touched Heartbeat (`D-0079`). The
	 * classic editor asks for 10 seconds while a post-lock dialog is showing
	 * (`wp-admin/js/post.js`); that is a temporary request from one screen, not
	 * the site's interval, and is not what this reports.
	 */
	private const DEFAULT_HEARTBEAT_INTERVAL = 60;

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
	 * The revision limit WordPress will enforce: -1 for unlimited, 0 for none,
	 * otherwise the number kept per post.
	 *
	 * Read through `wp_revisions_to_keep()`, which is what core itself consults
	 * when it prunes, rather than from `WP_POST_REVISIONS`. The constant is only
	 * the starting value: `wp_revisions_to_keep` and the per-post-type filter
	 * both run after it, and a limit set through either — including
	 * `core.limit_revisions` — was invisible while this read the constant, so the
	 * finding recommending that tweak survived the tweak being applied.
	 *
	 * Asked for a post of type `post`, because a limit is a per-post answer and
	 * that is the type revisions matter for on most sites. Core treats any
	 * negative value as unlimited, so every negative is reported as -1.
	 *
	 * @return int
	 */
	private function revisionsLimit(): int {
		$post = new \WP_Post(
			(object) array(
				'ID'        => 0,
				'post_type' => 'post',
			)
		);

		return max( -1, (int) wp_revisions_to_keep( $post ) );
	}
}
