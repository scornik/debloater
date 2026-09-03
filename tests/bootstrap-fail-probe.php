<?php
/**
 * Bootstrap for the forced-verification-failure suite.
 *
 * `DEBLOATER_TEST_FAIL_PROBE` is a constant, and a constant cannot be undefined
 * once it is set. Defining it in the middle of the main integration suite would
 * make every subsequent apply fail verification and roll back, so the one test
 * that needs it runs in its own process with its own bootstrap.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

/**
 * Make the `rest` probe report FAIL, so the rollback path can be exercised
 * without breaking a real site to do it.
 */
define( 'DEBLOATER_TEST_FAIL_PROBE', 'rest' );

require __DIR__ . '/bootstrap-integration.php';

require_once __DIR__ . '/FailProbe/FailProbeTestCase.php';
