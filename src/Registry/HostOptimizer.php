<?php
/**
 * An optimization layer that already owns some of this ground.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Registry;

use RuntimeException;

/**
 * A host or stack optimizer (BUILD-SPEC §17 Phase 11).
 *
 * Some sites arrive with an optimizer already installed: the host's own plugin,
 * or a cache plugin whose settings screen has the same switches WP Debloat would
 * offer. When that is true, the useful thing is not to add a second switch for
 * the same thing. It is to say so, and leave it to the tool that already owns it.
 *
 * Two switches doing one job is worse than either alone. Whichever one the site
 * owner remembers is the one they will look at, and it may not be the one that
 * is on.
 *
 * `covers` is therefore a claim about what the optimizer *offers*, not about
 * what it is currently doing. WP Debloat cannot read another plugin's settings
 * and will not pretend to: saying "already handled" when the switch is off would
 * be exactly the kind of invented claim this product exists not to make. What it
 * says instead is that there is a better place to change this, and where.
 */
final class HostOptimizer {

	/**
	 * Presence established from a registry detector.
	 */
	public const SIGNAL_DETECTOR = 'detector';

	/**
	 * Presence established from the recognised host vendor.
	 */
	public const SIGNAL_HOST_VENDOR = 'host_vendor';

	/**
	 * Stable identifier.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * What the optimizer calls itself.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * Signal type, one of the SIGNAL_* constants.
	 *
	 * @var string
	 */
	public readonly string $signal_type;

	/**
	 * Detector id or host vendor value.
	 *
	 * @var string
	 */
	public readonly string $signal_value;

	/**
	 * Finding ids this optimizer offers its own setting for, sorted.
	 *
	 * @var array<int,string>
	 */
	public readonly array $covers;

	/**
	 * Where the setting lives, shown as evidence.
	 *
	 * @var string
	 */
	public readonly string $notes;

	/**
	 * Constructor.
	 *
	 * @param string            $id           Stable identifier.
	 * @param string            $name         Display name.
	 * @param string            $signal_type  One of the SIGNAL_* constants.
	 * @param string            $signal_value Detector id or host vendor.
	 * @param array<int,string> $covers       Finding ids.
	 * @param string            $notes        Where the setting lives.
	 * @throws RuntimeException When the signal type is not one this code understands.
	 */
	public function __construct(
		string $id,
		string $name,
		string $signal_type,
		string $signal_value,
		array $covers,
		string $notes
	) {
		if ( ! in_array( $signal_type, array( self::SIGNAL_DETECTOR, self::SIGNAL_HOST_VENDOR ), true ) ) {
			throw new RuntimeException(
				sprintf( 'Optimizer "%s" has unknown signal type "%s".', $id, $signal_type )
			);
		}

		if ( array() === $covers ) {
			throw new RuntimeException(
				sprintf( 'Optimizer "%s" covers nothing, so its presence changes nothing.', $id )
			);
		}

		$sorted = array_values( array_unique( array_map( 'strval', $covers ) ) );
		sort( $sorted, SORT_STRING );

		$this->id           = $id;
		$this->name         = $name;
		$this->signal_type  = $signal_type;
		$this->signal_value = $signal_value;
		$this->covers       = $sorted;
		$this->notes        = $notes;
	}

	/**
	 * Build from one entry of registry/host-optimizers.json.
	 *
	 * @param array<string,mixed> $entry Decoded entry.
	 * @return self
	 */
	public static function fromArray( array $entry ): self {
		$signal = $entry['signal'] ?? array();

		if ( ! is_array( $signal ) ) {
			$signal = array();
		}

		$covers = $entry['covers'] ?? array();

		return new self(
			(string) ( $entry['id'] ?? '' ),
			(string) ( $entry['name'] ?? '' ),
			(string) ( $signal['type'] ?? '' ),
			(string) ( $signal['value'] ?? '' ),
			is_array( $covers ) ? $covers : array(),
			(string) ( $entry['notes'] ?? '' )
		);
	}

	/**
	 * Whether this optimizer offers its own setting for a finding.
	 *
	 * @param string $finding_id Finding id.
	 * @return bool
	 */
	public function coversFinding( string $finding_id ): bool {
		return in_array( $finding_id, $this->covers, true );
	}

	/**
	 * The canonical form, for the registry hash and for the fact set.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'     => $this->id,
			'name'   => $this->name,
			'signal' => array(
				'type'  => $this->signal_type,
				'value' => $this->signal_value,
			),
			'covers' => $this->covers,
			'notes'  => $this->notes,
		);
	}
}
