<?php
/**
 * Works out how sure we are about a finding.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use WPDebloat\Contracts\FactSet;

/**
 * Confidence = base × penalties (BUILD-SPEC §6, docs/SCORING.md).
 *
 * Confidence is a separate dimension from risk, and it answers a different
 * question. Risk asks "how bad would it be if this change went wrong?".
 * Confidence asks "how sure are we that this reading of the site is correct?".
 *
 * A rule declares a base confidence for the ideal case: a site we can see
 * clearly, with nothing sitting between us and the truth. Every penalty here is
 * a specific reason the view is less clear than that:
 *
 * - **Unknown host.** Managed hosts apply their own optimisations at the server
 *   level. On a host we recognise we know what is already handled; on one we do
 *   not, our reading of what the site does could be incomplete.
 * - **Cache plugin present.** A page cache means what a visitor receives is not
 *   necessarily what WordPress just generated, so a front-end observation may be
 *   describing a page nobody is served.
 * - **Detected dependents.** Every plugin that declares a dependency on what a
 *   tweak would change is another thing that could break in a way we have not
 *   modelled. This penalty compounds, because two dependents are meaningfully
 *   worse than one.
 * - **Custom mu-plugins.** Code in mu-plugins is site-specific, invisible to the
 *   registry, and runs before everything. It is the most common reason a site
 *   behaves unlike any site we have seen.
 *
 * The multipliers are recorded in docs/SCORING.md and docs/DECISIONS.md D-0010.
 * They are deliberately mild: confidence is an honesty signal shown next to a
 * recommendation, not a mechanism for quietly withdrawing one.
 */
final class ConfidenceCalculator {

	/**
	 * Applied when no known host signature matched.
	 */
	public const PENALTY_UNKNOWN_HOST = 0.95;

	/**
	 * Applied when a page-cache plugin is active.
	 */
	public const PENALTY_CACHE_PLUGIN = 0.95;

	/**
	 * Applied once per detected dependent, compounding.
	 */
	public const PENALTY_PER_DEPENDENT = 0.90;

	/**
	 * How many dependents can compound before the penalty stops growing.
	 *
	 * Past this point the message is already "several things depend on this";
	 * driving confidence towards zero would turn a caution into a refusal, and a
	 * refusal is what `dont_touch` is for.
	 */
	public const MAX_DEPENDENTS_COUNTED = 3;

	/**
	 * Applied when the site has its own mu-plugins.
	 */
	public const PENALTY_CUSTOM_CODE = 0.90;

	/**
	 * Confidence never falls below this through penalties alone.
	 */
	public const FLOOR = 0.3;

	/**
	 * The facts this calculation reads.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Whether the site has custom mu-plugins.
	 *
	 * Passed in rather than detected here: this class must stay usable without
	 * WordPress, and the filesystem check belongs to the scanner layer.
	 *
	 * @var bool
	 */
	private bool $has_custom_code;

	/**
	 * Constructor.
	 *
	 * @param FactSet $facts           Facts from the scan.
	 * @param bool    $has_custom_code Whether the site has custom mu-plugins.
	 */
	public function __construct( FactSet $facts, bool $has_custom_code = false ) {
		$this->facts           = $facts;
		$this->has_custom_code = $has_custom_code;
	}

	/**
	 * Confidence for a finding.
	 *
	 * @param float $base                  Rule-declared base confidence, 0..1.
	 * @param int   $dependencies_detected How many detected components depend on this.
	 * @return float Rounded to two decimals, so the same inputs always print the same figure.
	 */
	public function calculate( float $base, int $dependencies_detected = 0 ): float {
		$confidence = max( 0.0, min( 1.0, $base ) );

		foreach ( $this->penalties( $dependencies_detected ) as $multiplier ) {
			$confidence *= $multiplier;
		}

		return round( max( self::FLOOR, $confidence ), 2 );
	}

	/**
	 * The penalties that apply, keyed by reason.
	 *
	 * Exposed so the UI can say *why* confidence is what it is, rather than
	 * showing a number with no account of itself.
	 *
	 * @param int $dependencies_detected How many detected components depend on this.
	 * @return array<string,float>
	 */
	public function penalties( int $dependencies_detected = 0 ): array {
		$penalties = array();

		if ( 'unknown' === $this->facts->value( 'env.host_vendor', 'unknown' ) ) {
			$penalties['unknown_host'] = self::PENALTY_UNKNOWN_HOST;
		}

		if ( 'none' !== $this->facts->value( 'env.cache_plugin', 'none' ) ) {
			$penalties['cache_plugin'] = self::PENALTY_CACHE_PLUGIN;
		}

		if ( $dependencies_detected > 0 ) {
			$counted = min( $dependencies_detected, self::MAX_DEPENDENTS_COUNTED );

			$penalties['dependents'] = self::PENALTY_PER_DEPENDENT ** $counted;
		}

		if ( $this->has_custom_code ) {
			$penalties['custom_code'] = self::PENALTY_CUSTOM_CODE;
		}

		return $penalties;
	}

	/**
	 * Plain-language reasons confidence was reduced.
	 *
	 * @param int $dependencies_detected How many detected components depend on this.
	 * @return array<int,string>
	 */
	public function reasons( int $dependencies_detected = 0 ): array {
		$reasons = array();

		foreach ( array_keys( $this->penalties( $dependencies_detected ) ) as $reason ) {
			$reasons[] = match ( $reason ) {
				'unknown_host' => __( 'This host was not recognised, so we cannot tell what it already optimises.', 'wp-debloat' ),
				'cache_plugin' => __( 'A page-cache plugin is active, so what visitors receive may differ from what WordPress generates.', 'wp-debloat' ),
				'dependents'   => __( 'Something installed on this site depends on what this change would alter.', 'wp-debloat' ),
				'custom_code'  => __( 'This site has custom must-use plugins, which we cannot inspect.', 'wp-debloat' ),
				default        => $reason,
			};
		}

		return $reasons;
	}
}
