<?php
/**
 * What the site is for, and what its owner wants.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Recommend;

use WPDebloat\Contracts\Assert;
use WPDebloat\Contracts\ContractViolation;

/**
 * The user's stated intent (BUILD-SPEC §17 Phase 4).
 *
 * Two answers, and the defaults are deliberately the cautious ones: a site whose
 * owner has told us nothing is treated as a site we know nothing about. `other`
 * and `balanced` do not unlock anything, and no part of the engine reads an
 * unanswered profile as permission.
 *
 * This is intent, not detection. A WooCommerce install detected by a scanner is
 * a fact; "this is a store and downtime costs money" is a statement only the
 * owner can make, and the two are kept apart on purpose. The detectors can be
 * wrong about a site that has WooCommerce installed and unused; the owner
 * cannot be wrong about what their own site is for.
 */
final class IntentProfile {

	/**
	 * Site types the wizard offers.
	 */
	public const SITE_TYPES = array( 'blog', 'store', 'business', 'membership', 'other' );

	/**
	 * Priorities the wizard offers.
	 */
	public const PRIORITIES = array( 'conservative', 'balanced', 'aggressive' );

	/**
	 * What kind of site this is.
	 *
	 * @var string
	 */
	public readonly string $site_type;

	/**
	 * How much change the owner is willing to accept.
	 *
	 * @var string
	 */
	public readonly string $priority;

	/**
	 * Constructor.
	 *
	 * @param string $site_type Site type.
	 * @param string $priority  Priority.
	 * @throws ContractViolation When either value is outside its vocabulary.
	 */
	public function __construct( string $site_type = 'other', string $priority = 'balanced' ) {
		if ( ! in_array( $site_type, self::SITE_TYPES, true ) ) {
			throw ContractViolation::range(
				self::class,
				'site_type',
				sprintf( 'must be one of %s, got "%s"', implode( ', ', self::SITE_TYPES ), $site_type )
			);
		}

		if ( ! in_array( $priority, self::PRIORITIES, true ) ) {
			throw ContractViolation::range(
				self::class,
				'priority',
				sprintf( 'must be one of %s, got "%s"', implode( ', ', self::PRIORITIES ), $priority )
			);
		}

		$this->site_type = $site_type;
		$this->priority  = $priority;
	}

	/**
	 * The default: nothing stated, so nothing assumed.
	 *
	 * @return self
	 */
	public static function unstated(): self {
		return new self();
	}

	/**
	 * Build from stored state.
	 *
	 * Falls back to the defaults rather than throwing. A malformed stored
	 * profile should mean "we do not know what this site is", not a fatal error
	 * on the dashboard.
	 *
	 * @param array<string,mixed> $data Stored profile.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$site_type = $data['site_type'] ?? 'other';
		$priority  = $data['priority'] ?? 'balanced';

		if ( ! is_string( $site_type ) || ! in_array( $site_type, self::SITE_TYPES, true ) ) {
			$site_type = 'other';
		}

		if ( ! is_string( $priority ) || ! in_array( $priority, self::PRIORITIES, true ) ) {
			$priority = 'balanced';
		}

		return new self( $site_type, $priority );
	}

	/**
	 * Build from user input, refusing anything outside the vocabulary.
	 *
	 * Used at the REST and CLI boundaries, where a rejected value should be an
	 * error the caller sees rather than a silent fallback.
	 *
	 * @param array<string,mixed> $data Submitted values.
	 * @return self
	 * @throws ContractViolation When a value is outside its vocabulary.
	 */
	public static function fromInput( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'site_type', 'priority' ) );

		return new self(
			Assert::stringOr( self::class, $data, 'site_type', 'other' ),
			Assert::stringOr( self::class, $data, 'priority', 'balanced' )
		);
	}

	/**
	 * Array shape, for storage.
	 *
	 * @return array{site_type:string,priority:string}
	 */
	public function toArray(): array {
		return array(
			'site_type' => $this->site_type,
			'priority'  => $this->priority,
		);
	}

	/**
	 * Whether the owner has actually told us anything.
	 *
	 * @return bool
	 */
	public function isStated(): bool {
		return 'other' !== $this->site_type || 'balanced' !== $this->priority;
	}

	/**
	 * Whether this is a site where a mistake costs money directly.
	 *
	 * @return bool
	 */
	public function isTransactional(): bool {
		return in_array( $this->site_type, array( 'store', 'membership' ), true );
	}

	/**
	 * The profile this intent implies, when the user has not named one.
	 *
	 * Deliberately conservative. "Aggressive" earns the performance profile;
	 * anything else, including having said nothing, gets the safe one. A
	 * dropdown answer is not consent to a medium-risk change — that consent is
	 * given at the preview, per change.
	 *
	 * @return string
	 */
	public function suggestedProfile(): string {
		if ( 'aggressive' === $this->priority && ! $this->isTransactional() ) {
			return 'performance';
		}

		return 'safe';
	}
}
