<?php
/**
 * Action recorded in the journal.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * Journal action (BUILD-SPEC §8, debloater_journal.action).
 */
enum JournalAction: string {

	case APPLY  = 'apply';
	case REVERT = 'revert';
	case SKIP   = 'skip';
}
