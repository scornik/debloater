<?php
/**
 * Finding and tweak categories.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Contracts;

/**
 * Category of a finding or tweak (BUILD-SPEC §6).
 *
 * Categories are also the sub-score buckets (BUILD-SPEC §12). ADMIN becomes a
 * sub-score in Phase 12 and ASSETS is deferred past Phase 13, but both exist
 * here from the start so findings never need re-categorising later.
 */
enum Category: string {

	case WORDPRESS     = 'wordpress';
	case CONFIGURATION = 'configuration';
	case DATABASE      = 'database';
	case PLUGINS       = 'plugins';
	case MAINTENANCE   = 'maintenance';
	case ADMIN         = 'admin';
	case ASSETS        = 'assets';
}
