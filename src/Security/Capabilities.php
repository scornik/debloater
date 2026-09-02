<?php
/**
 * Who is allowed to use WP Debloat.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Security;

use WPDebloat\Brand;

/**
 * The single capability check (BUILD-SPEC §13 rule 1).
 *
 * Every REST route, admin screen and state-changing action asks this one
 * question, so there is exactly one place to audit and exactly one place to
 * change. The capability maps to `manage_options` by default and is filterable,
 * which lets a site grant it to a role that is not a full administrator without
 * anyone having to edit the plugin.
 */
final class Capabilities {

	/**
	 * Capability required to manage WP Debloat.
	 */
	public const MANAGE = Brand::CAPABILITY;

	/**
	 * Capability the plugin's own capability maps onto by default.
	 */
	public const MAPS_TO = 'manage_options';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Register the mapping so `current_user_can( 'wpdebloat_manage' )` works.
	 *
	 * Mapping rather than granting means the capability is never written into
	 * the roles table, so removing the plugin leaves no trace in user data and
	 * a site's role configuration is never silently edited.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'map' ), 10, 4 );
	}

	/**
	 * Grant our capability to anyone holding the capability it maps to.
	 *
	 * @param array<string,bool> $allcaps All capabilities the user has.
	 * @param array<int,string>  $caps    Capabilities being checked.
	 * @param array<int,mixed>   $args    Arguments to the check.
	 * @param \WP_User           $user    The user being checked.
	 * @return array<string,bool>
	 */
	public static function map( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );

		if ( ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		/**
		 * Filters the capability WP Debloat's own capability maps onto.
		 *
		 * @param string $capability Capability required to manage WP Debloat.
		 */
		$required = (string) apply_filters( 'wpdebloat_required_capability', self::MAPS_TO );

		if ( '' !== $required && ! empty( $allcaps[ $required ] ) ) {
			$allcaps[ self::MANAGE ] = true;
		}

		return $allcaps;
	}

	/**
	 * Whether the current user may manage WP Debloat.
	 *
	 * @return bool
	 */
	public static function currentUserCanManage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * The actor string for the current request (BUILD-SPEC §8).
	 *
	 * @return string
	 */
	public static function currentActor(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		$user_id = get_current_user_id();

		return $user_id > 0 ? 'user:' . $user_id : 'system';
	}
}
