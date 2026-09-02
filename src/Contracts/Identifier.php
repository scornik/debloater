<?php
/**
 * Shared identifier patterns.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Identifier grammar used across contracts and registry schemas.
 *
 * Tweak ids, finding ids and fact keys are all dot-namespaced. Keeping the
 * patterns in one place means the PHP contracts and the JSON schemas in
 * registry/schemas cannot drift apart; a test asserts the schema patterns match
 * these constants.
 */
final class Identifier {

	/**
	 * Tweak id: "core.disable_emojis", "db.clean_expired_transients".
	 */
	public const TWEAK_ID_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/';

	/**
	 * Finding id: "wp.heartbeat.aggressive", "plugins.inactive_present".
	 */
	public const FINDING_ID_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9_]*)+$/';

	/**
	 * Fact key: "wp.heartbeat_interval", "db.autoload.top".
	 */
	public const FACT_KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9][a-z0-9_-]*)+$/';

	/**
	 * Registry slug: "woocommerce", "contact-form-7".
	 */
	public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * Actor recorded on runs and journal rows: "user:123", "cli", "cron".
	 */
	public const ACTOR_PATTERN = '/^(cli|cron|system|user:[1-9][0-9]*)$/';

	/**
	 * A lowercase hexadecimal sha256 digest.
	 */
	public const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}
}
