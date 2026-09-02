<?php
/**
 * Registers the REST routes.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Rest;

use WPDebloat\Brand;
use WPDebloat\Plugin;
use WPDebloat\Security\Capabilities;

/**
 * The REST surface (BUILD-SPEC §13 rules 1 and 2).
 *
 * Routes are registered here and nowhere else, so "does every route check the
 * capability?" is a question with a single place to look. Each route object
 * supplies its own arguments and callback; the permission callback is supplied
 * here, uniformly, because a route that could forget it is a route that
 * eventually will.
 */
final class Controller {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = Brand::REST_NAMESPACE;

	/**
	 * The plugin instance routes are given access to.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Registered route objects.
	 *
	 * @var array<int,Routes\RouteInterface>
	 */
	private array $routes;

	/**
	 * Constructor.
	 *
	 * @param Plugin                          $plugin Plugin instance.
	 * @param array<int,Routes\RouteInterface> $routes Routes to register.
	 */
	public function __construct( Plugin $plugin, array $routes ) {
		$this->plugin = $plugin;
		$this->routes = $routes;
	}

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		foreach ( $this->routes as $route ) {
			$changes_state = self::changesState( $route->methods() );

			register_rest_route(
				self::NAMESPACE,
				$route->path(),
				array(
					'methods'             => $route->methods(),
					'callback'            => array( $route, 'handle' ),
					'permission_callback' => $changes_state
						? array( self::class, 'writePermissionCallback' )
						: array( self::class, 'permissionCallback' ),
					'args'                => $route->args(),
				)
			);
		}
	}

	/**
	 * Whether a route's methods change the site.
	 *
	 * @param string $methods Comma-separated HTTP methods.
	 * @return bool
	 */
	private static function changesState( string $methods ): bool {
		foreach ( explode( ',', strtoupper( $methods ) ) as $method ) {
			if ( in_array( trim( $method ), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The permission callback for routes that change the site.
	 *
	 * The capability, and a valid nonce (BUILD-SPEC §13 rule 12). WordPress
	 * checks the nonce itself when a request is cookie-authenticated, but only
	 * then — and "only when authenticated a particular way" is not the same
	 * promise as "always". Checking here makes the guarantee unconditional, and
	 * makes it something a test can assert.
	 *
	 * The consequence is that these endpoints cannot be driven by an application
	 * password or any other nonce-less credential. That is deliberate: automation
	 * has WP-CLI, which is a better fit for it and leaves a clearer trail.
	 *
	 * @param \WP_REST_Request<array<string,mixed>> $request The request.
	 * @return true|\WP_Error
	 */
	public static function writePermissionCallback( $request ) {
		$allowed = self::permissionCallback();

		if ( $allowed instanceof \WP_Error ) {
			return $allowed;
		}

		$nonce = $request->get_header( 'x_wp_nonce' );

		if ( ! is_string( $nonce ) || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'wpdebloat_bad_nonce',
				__( 'That request could not be verified as coming from this screen. Reload the page and try again.', 'wp-debloat' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The permission callback used by every route.
	 *
	 * Read routes need the capability just as much as write routes: the scan
	 * results describe the site's configuration in detail, which is not
	 * something to hand to an unauthenticated caller.
	 *
	 * @return true|\WP_Error
	 */
	public static function permissionCallback() {
		if ( Capabilities::currentUserCanManage() ) {
			return true;
		}

		return new \WP_Error(
			'wpdebloat_forbidden',
			__( 'You do not have permission to manage WP Debloat on this site.', 'wp-debloat' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * The plugin instance, for routes that need it.
	 *
	 * @return Plugin
	 */
	public function plugin(): Plugin {
		return $this->plugin;
	}
}
