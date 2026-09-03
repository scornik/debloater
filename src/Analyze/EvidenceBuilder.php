<?php
/**
 * Builds the evidence behind a finding.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze;

use RuntimeException;
use Debloater\Contracts\Evidence;
use Debloater\Contracts\FactSet;

/**
 * Turns facts into the evidence a finding shows (BUILD-SPEC §6, decision #5).
 *
 * The point of this class is one check: **evidence must come from a fact that
 * was actually observed**. A rule cannot invent a supporting number, and it
 * cannot cite a fact key the scan never produced. Both attempts throw.
 *
 * That matters because evidence is what the whole product asks the user to
 * trust. "Heartbeat polls every 15 s" is only worth showing if it came from
 * `wp.heartbeat_interval` on this site, this scan. Anything else is a plausible
 * sentence, and plausible sentences are what Debloater exists not to produce.
 */
final class EvidenceBuilder {

	/**
	 * The facts evidence may be drawn from.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Evidence collected so far.
	 *
	 * @var array<int,Evidence>
	 */
	private array $entries = array();

	/**
	 * Constructor.
	 *
	 * @param FactSet $facts Facts from the scan.
	 */
	public function __construct( FactSet $facts ) {
		$this->facts = $facts;
	}

	/**
	 * Add an entry whose value is the fact itself.
	 *
	 * @param string $label Human-readable label.
	 * @param string $fact  Fact key.
	 * @return self
	 * @throws RuntimeException When the fact was not observed.
	 */
	public function fact( string $label, string $fact ): self {
		return $this->add( $label, $this->require( $fact ), $fact );
	}

	/**
	 * Add an entry with a formatted value, still citing its fact.
	 *
	 * Used where the raw value needs a unit to make sense — "15 s" rather than
	 * 15 — while keeping the provenance exact.
	 *
	 * @param string $label     Human-readable label.
	 * @param string $formatted Value as the user should see it.
	 * @param string $fact      Fact key the value came from.
	 * @return self
	 * @throws RuntimeException When the fact was not observed.
	 */
	public function formatted( string $label, string $formatted, string $fact ): self {
		$this->require( $fact );

		return $this->add( $label, $formatted, $fact );
	}

	/**
	 * Add an entry drawn from a key inside a map-valued fact.
	 *
	 * `plugins.detected` is one fact holding many answers, and evidence about
	 * WooCommerce should cite `plugins.detected.woocommerce` rather than the
	 * whole map.
	 *
	 * @param string $label Human-readable label.
	 * @param string $fact  Fact key holding a map.
	 * @param string $key   Key within the map.
	 * @return self
	 * @throws RuntimeException When the fact or the key was not observed.
	 */
	public function within( string $label, string $fact, string $key ): self {
		$map = $this->require( $fact );

		if ( ! is_array( $map ) || ! array_key_exists( $key, $map ) ) {
			throw new RuntimeException(
				sprintf( 'Evidence cites "%s.%s", which the scan did not observe.', $fact, $key )
			);
		}

		return $this->add( $label, $map[ $key ], $fact . '.' . $key );
	}

	/**
	 * Add an entry only when the fact was observed.
	 *
	 * For evidence that strengthens a finding without being necessary to it: a
	 * rule that fires on WordPress 6.5 should not fail because the admin counts
	 * were unavailable.
	 *
	 * @param string $label Human-readable label.
	 * @param string $fact  Fact key.
	 * @return self
	 */
	public function optional( string $label, string $fact ): self {
		if ( ! $this->facts->has( $fact ) ) {
			return $this;
		}

		return $this->add( $label, $this->facts->value( $fact ), $fact );
	}

	/**
	 * The evidence collected.
	 *
	 * @return array<int,Evidence>
	 */
	public function build(): array {
		return $this->entries;
	}

	/**
	 * Whether anything has been collected.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->entries;
	}

	/**
	 * Append an entry.
	 *
	 * @param string $label Human-readable label.
	 * @param mixed  $value Displayed value.
	 * @param string $fact  Fact key.
	 * @return self
	 */
	private function add( string $label, mixed $value, string $fact ): self {
		$this->entries[] = new Evidence( $label, $value, $fact );

		return $this;
	}

	/**
	 * Read a fact, refusing to cite one that was never observed.
	 *
	 * @param string $fact Fact key.
	 * @return mixed
	 * @throws RuntimeException When the fact is absent.
	 */
	private function require( string $fact ): mixed {
		if ( ! $this->facts->has( $fact ) ) {
			throw new RuntimeException(
				sprintf(
					'Evidence cites the fact "%s", which this scan did not observe. '
					. 'A rule must check supports() before claiming a fact it needs.',
					$fact
				)
			);
		}

		return $this->facts->value( $fact );
	}
}
