<?php
/**
 * Kind of run recorded in wpdebloat_runs.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Run type (BUILD-SPEC §8, wpdebloat_runs.type).
 */
enum RunType: string {

	case SCAN     = 'scan';
	case APPLY    = 'apply';
	case ROLLBACK = 'rollback';
	case VERIFY   = 'verify';
	case MEASURE  = 'measure';
}
