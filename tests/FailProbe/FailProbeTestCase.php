<?php
/**
 * Base class for the forced-failure suite.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\FailProbe;

use WPDebloat\Tests\Integration\IntegrationTestCase;

/**
 * The same setup as the integration suite, in a process where verification is
 * guaranteed to fail.
 *
 * It exists only so the suite has a base class its bootstrap can require
 * directly; everything it does, `IntegrationTestCase` already does.
 */
abstract class FailProbeTestCase extends IntegrationTestCase {
}
