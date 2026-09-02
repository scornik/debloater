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
			register_rest_route(
				self::NAMESPACE,
				$route->path(),
				array(
					'methods'             => $route->methods(),
					'callback'            => array( $route, 'handle' ),
					'permission_callback' => array( self::class, 'permissionCallback' ),
					'args'                => $route->args(),
				)
			);
		}
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
