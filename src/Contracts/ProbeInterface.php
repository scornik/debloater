<?php
/**
 * Contract for a verification probe.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * A verification probe (BUILD-SPEC §11).
 *
 * A probe answers one question about the live site after a change: does the
 * home page still render, does the REST API still respond, is the runtime
 * loaded. It must distinguish "this does not apply here" (NOT_TESTED) from "I
 * could not find out" (UNKNOWN) — collapsing the two would let an untested
 * checkout be reported as a passing one.
 */
interface ProbeInterface {

	/**
	 * The probe's name, as used in tweak `probes` arrays.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Whether this probe applies to the current stack.
	 *
	 * When false, the probe still reports, with status NOT_TESTED, so the user
	 * can see what was not checked.
	 *
	 * @param Context $context Site context.
	 * @param FactSet $facts   Facts from the most recent scan.
	 * @return bool
	 */
	public function applies( Context $context, FactSet $facts ): bool;

	/**
	 * Run the probe.
	 *
	 * A probe never throws for an expected failure: a non-2xx response, a fatal
	 * marker or a timeout is a result, not an exception.
	 *
	 * @param Context $context Site context.
	 * @return ProbeResult
	 */
	public function run( Context $context ): ProbeResult;
}
