<?php
/**
 * Base class for the forced-failure suite.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\FailProbe;

use Debloater\Tests\Integration\IntegrationTestCase;

/**
 * The same setup as the integration suite, in a process where verification is
 * guaranteed to fail.
 *
 * It exists only so the suite has a base class its bootstrap can require
 * directly; everything it does, `IntegrationTestCase` already does.
 */
abstract class FailProbeTestCase extends IntegrationTestCase {
}
