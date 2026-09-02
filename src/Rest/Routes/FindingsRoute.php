<?php
/**
 * GET wpdebloat/v1/findings.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Rest\Routes;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\Risk;
use WPDebloat\Plugin;

/**
 * Returns the findings from the most recent scan (BUILD-SPEC §17 Phase 3).
 *
 * Reads a recorded scan rather than running a new one. That is what lets the
 * dashboard reload without re-scanning, and it is what makes the findings a
 * user acts on the same ones they were shown.
 *
 * When no scan has been recorded, the response says so explicitly rather than
 * returning an empty list — "we have not looked yet" and "we looked and found
 * nothing" are different answers, and only one of them is good news.
 */
final class FindingsRoute implements RouteInterface {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Route path.
	 *
	 * @return string
	 */
	public function path(): string {
		return '/findings';
	}

	/**
	 * HTTP methods.
	 *
	 * @return string
	 */
	public function methods(): string {
		return 'GET';
	}

	/**
	 * Argument definitions.
	 *
	 * Both filters are enumerated, so an unknown value is rejected by WordPress
	 * before this route sees it (BUILD-SPEC §13 rule 3).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function args(): array {
		return array(
			'risk'     => array(
				'description' => __( 'Only findings at this risk level.', 'wp-debloat' ),
				'type'        => 'string',
				'enum'        => array_map( static fn ( Risk $risk ): string => $risk->value, Risk::cases() ),
				'required'    => false,
			),
			'decision' => array(
				'description' => __( 'Only findings with this decision.', 'wp-debloat' ),
				'type'        => 'string',
				'enum'        => array_map( static fn ( Decision $decision ): string => $decision->value, Decision::cases() ),
				'required'    => false,
			),
			'category' => array(
				'description' => __( 'Only findings in this category.', 'wp-debloat' ),
				'type'        => 'string',
				'enum'        => array_map( static fn ( Category $category ): string => $category->value, Category::cases() ),
				'required'    => false,
			),
			'run_id'   => array(
				'description' => __( 'Read a specific scan run instead of the most recent one.', 'wp-debloat' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => false,
			),
		);
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$run = $this->plugin->latestScan( $request->get_param( 'run_id' ) );

		if ( null === $run ) {
			return new WP_REST_Response(
				array(
					'scanned'  => false,
					'message'  => __( 'This site has not been scanned yet.', 'wp-debloat' ),
					'findings' => array(),
				),
				200
			);
		}

		$analysis = $run->payload['analysis'] ?? array();

		if ( ! is_array( $analysis ) ) {
			return new WP_Error(
				'wpdebloat_unreadable_run',
				__( 'That scan was recorded by a different version and cannot be read.', 'wp-debloat' ),
				array( 'status' => 409 )
			);
		}

		$findings = $this->filter(
			is_array( $analysis['findings'] ?? null ) ? $analysis['findings'] : array(),
			is_string( $request->get_param( 'risk' ) ) ? $request->get_param( 'risk' ) : null,
			is_string( $request->get_param( 'decision' ) ) ? $request->get_param( 'decision' ) : null,
			is_string( $request->get_param( 'category' ) ) ? $request->get_param( 'category' ) : null
		);

		return new WP_REST_Response(
			array(
				'scanned'       => true,
				'run_id'        => $run->id,
				'scanned_at'    => $run->finished_at ?? $run->started_at,
				'registry_hash' => $run->registry_hash,
				'score'         => $analysis['score'] ?? array(),
				'not_evaluated' => $analysis['not_evaluated'] ?? array(),
				'findings'      => $findings,
				'total'         => count( $findings ),
			),
			200
		);
	}

	/**
	 * Apply the risk and decision filters.
	 *
	 * @param array<int,mixed> $findings Findings from the run payload.
	 * @param string|null      $risk     Risk filter.
	 * @param string|null      $decision Decision filter.
	 * @return array<int,mixed>
	 */
	private function filter( array $findings, ?string $risk, ?string $decision, ?string $category = null ): array {
		$filtered = array();

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}

			if ( null !== $risk && ( $finding['risk'] ?? null ) !== $risk ) {
				continue;
			}

			if ( null !== $decision && ( $finding['decision'] ?? null ) !== $decision ) {
				continue;
			}

			if ( null !== $category && ( $finding['category'] ?? null ) !== $category ) {
				continue;
			}

			$filtered[] = $finding;
		}

		return $filtered;
	}
}
