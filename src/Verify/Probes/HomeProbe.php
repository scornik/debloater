<?php
/**
 * Does the front page still work.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Verify\Probes;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\ProbeResult;

/**
 * GET `/` as a guest (BUILD-SPEC §11).
 *
 * The single most important probe, and the one fetched as an anonymous visitor
 * rather than as the administrator: a change that breaks the site for everyone
 * except the person who is logged in is exactly the change that must not be
 * allowed to stand.
 */
final class HomeProbe extends AbstractHttpProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'home';
	}

	/**
	 * Fetch the home page.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		$response = $this->http->get( $context->home_url );

		return $this->judgeHtml( $response ) ?? $this->judgeRendered( $response );
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The home page', 'wp-debloat' );
	}
}
