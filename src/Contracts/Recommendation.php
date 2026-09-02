<?php
/**
 * The tweak a finding recommends, with its parameters.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * A finding's recommendation (BUILD-SPEC §6, `recommendation` field).
 *
 * A recommendation names a tweak id and the parameters the Recommendation
 * Engine chose for this site. It is a proposal, not an action: nothing is
 * applied until the tweak survives compatibility resolution, the §7.4
 * invariants, preview and explicit confirmation.
 */
final class Recommendation {

	/**
	 * Tweak id, e.g. "core.heartbeat_interval".
	 *
	 * @var string
	 */
	public readonly string $tweak_id;

	/**
	 * Parameters for the tweak.
	 *
	 * @var TweakParams
	 */
	public readonly TweakParams $params;

	/**
	 * Constructor.
	 *
	 * @param string           $tweak_id Tweak id.
	 * @param TweakParams|null $params   Parameters, defaulting to none.
	 * @throws ContractViolation When the tweak id is malformed.
	 */
	public function __construct( string $tweak_id, ?TweakParams $params = null ) {
		if ( 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $tweak_id ) ) {
			throw ContractViolation::range(
				self::class,
				'tweak_id',
				sprintf( 'must be a dotted tweak id such as "core.disable_emojis", got "%s"', $tweak_id )
			);
		}

		$this->tweak_id = $tweak_id;
		$this->params   = $params ?? new TweakParams();
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'tweak_id', 'params' ) );

		return new self(
			Assert::string( self::class, $data, 'tweak_id' ),
			TweakParams::fromArray( Assert::stringKeyedMap( self::class, $data, 'params' ) )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array{tweak_id:string,params:array<string,mixed>}
	 */
	public function toArray(): array {
		return array(
			'tweak_id' => $this->tweak_id,
			'params'   => $this->params->toArray(),
		);
	}
}
