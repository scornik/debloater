<?php
/**
 * Action recorded in the journal.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Journal action (BUILD-SPEC §8, wpdebloat_journal.action).
 */
enum JournalAction: string {

	case APPLY  = 'apply';
	case REVERT = 'revert';
	case SKIP   = 'skip';
}
