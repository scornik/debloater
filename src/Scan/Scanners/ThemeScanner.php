<?php
/**
 * Facts about the active theme.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Scan\Scanners;

use Debloater\Contracts\Context;

/**
 * Collects the `theme.*` facts (BUILD-SPEC §5).
 *
 * Deliberately two facts and no more. A theme's own behaviour — what it
 * enqueues, what it registers — is an asset question, and Phase 13 answers it by
 * fetching real pages. Guessing at it from the theme name here would produce
 * confident-looking facts with nothing behind them.
 */
final class ThemeScanner extends AbstractScanner {

	/**
	 * The namespace this scanner owns.
	 *
	 * @return string
	 */
	public function namespaceName(): string {
		return 'theme';
	}

	/**
	 * Collect theme facts.
	 *
	 * @param Context $context Site context.
	 * @return array<string,mixed>
	 */
	protected function collect( Context $context ): array {
		unset( $context );

		$stylesheet = (string) get_stylesheet();
		$template   = (string) get_template();

		return array(
			'theme.active' => '' === $stylesheet ? 'unknown' : $stylesheet,
			// A child theme is the only case where these differ, so this is
			// null for the overwhelming majority of sites rather than a
			// duplicate of theme.active.
			'theme.parent' => ( '' !== $template && $template !== $stylesheet ) ? $template : null,
		);
	}
}
