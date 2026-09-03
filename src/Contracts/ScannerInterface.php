<?php
/**
 * Contract for a fact collector.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A scanner collects facts (BUILD-SPEC §5).
 *
 * A scanner observes and reports. It never names a tweak, never grades what it
 * sees, and never writes outside the namespace it declares. Those three rules
 * are what keep "what is true about this site" separable from "what we think
 * should change", which is the hard boundary the whole architecture rests on.
 */
interface ScannerInterface {

	/**
	 * The fact namespace this scanner owns.
	 *
	 * Every key it writes must begin with this segment. FactSet::withNamespaced()
	 * enforces it.
	 *
	 * @return string
	 */
	public function namespaceName(): string;

	/**
	 * Collect facts and return the set with this scanner's facts added.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts collected so far.
	 * @return FactSet
	 */
	public function scan( Context $context, FactSet $facts ): FactSet;
}
