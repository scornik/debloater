<?php
/**
 * Shared behaviour for fact collectors.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Scan\Scanners;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\ScannerInterface;

/**
 * Base for the scanners in BUILD-SPEC §4.
 *
 * A scanner declares the namespace it owns and returns a flat map of facts.
 * Writing goes through FactSet::withNamespaced(), so a scanner that reaches
 * outside its namespace throws rather than quietly overwriting another
 * scanner's observation.
 *
 * Subclasses collect and nothing else. There is deliberately no helper here for
 * grading, scoring or recommending: the boundary between "what is true" and
 * "what should change" is the one the whole architecture rests on, and the
 * easiest way to keep it is to give the scanning layer no vocabulary for
 * opinions.
 */
abstract class AbstractScanner implements ScannerInterface {

	/**
	 * Collect this scanner's facts.
	 *
	 * Returns a flat map of fully-qualified fact key to value. Keys outside the
	 * declared namespace are refused by scan().
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	abstract protected function collect( Context $context ): array;

	/**
	 * Collect facts and merge them into the set.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts collected so far.
	 * @return FactSet
	 */
	public function scan( Context $context, FactSet $facts ): FactSet {
		return $facts->withNamespaced( $this->namespaceName(), $this->collect( $context ) );
	}

	/**
	 * Forget anything cached from a previous scan.
	 *
	 * Almost every scanner reads the site fresh each time and has nothing to
	 * forget, so the default does nothing. The exceptions are the ones that
	 * share the fetched page sample: a scan answered with pages fetched during
	 * an earlier one would not be an observation of this site now, and the whole
	 * product rests on facts being observations.
	 *
	 * Called by ScanRunner before each scanner runs.
	 *
	 * @return void
	 */
	public function reset(): void {
	}

	/**
	 * Whether a constant is defined and truthy.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function constantIsTrue( string $name ): bool {
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Whether a constant is defined at all.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function constantExists( string $name ): bool {
		return defined( $name );
	}
}
