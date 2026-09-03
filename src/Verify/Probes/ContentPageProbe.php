<?php
/**
 * Does a real piece of content still work.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Verify\Probes;

use Debloater\Contracts\Context;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\ProbeResult;
use Debloater\Contracts\ProbeStatus;

/**
 * GET the newest published post or page (BUILD-SPEC §11).
 *
 * The home page is often a static template with very little on it. A single
 * post exercises the parts a change is most likely to disturb: the content
 * filters, the embeds, the comment form, the theme's singular template.
 *
 * A site with no published content is not a failure; it is a site with nothing
 * to check, which is what NOT_TESTED is for.
 */
final class ContentPageProbe extends AbstractHttpProbe {

	/**
	 * The probe's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'content_page';
	}

	/**
	 * Whether there is any published content to fetch.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts from the most recent scan.
	 * @return bool
	 */
	public function applies( Context $context, FactSet $facts ): bool {
		unset( $context, $facts );

		return '' !== $this->newestUrl();
	}

	/**
	 * Fetch the newest published post or page.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult {
		unset( $context );

		$url = $this->newestUrl();

		if ( '' === $url ) {
			return new ProbeResult(
				$this->name(),
				ProbeStatus::NOT_TESTED,
				__( 'This site has no published posts or pages, so there was no content page to check.', 'debloater' )
			);
		}

		$response = $this->http->get( $url );

		return $this->judgeHtml( $response ) ?? $this->judgeRendered( $response );
	}

	/**
	 * Description used in messages.
	 *
	 * @return string
	 */
	protected function describe(): string {
		return __( 'The most recent post or page', 'debloater' );
	}

	/**
	 * The permalink of the newest published post or page.
	 *
	 * @return string
	 */
	private function newestUrl(): string {
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);

		if ( array() === $posts ) {
			return '';
		}

		$permalink = get_permalink( $posts[0] );

		return is_string( $permalink ) ? $permalink : '';
	}
}
