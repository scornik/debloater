<?php
/**
 * Kind of run recorded in debloater_runs.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Run type (BUILD-SPEC §8, debloater_runs.type).
 */
enum RunType: string {

	case SCAN     = 'scan';
	case APPLY    = 'apply';
	case ROLLBACK = 'rollback';
	case VERIFY   = 'verify';
	case MEASURE  = 'measure';
}
