<?php
/**
 * Analyzer rule: wp.file_editor.enabled.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze\Rules;

use WPDebloat\Contracts\Category;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Contracts\Severity;

/**
 * Reports that the built-in theme and plugin file editor is available. Info only.
 *
 * Turning this off is a well-known hardening step, and it would be easy to
 * present it as a one-click fix. It is not one, for a reason worth stating: the
 * change lives in `wp-config.php`, not in a hook, and WP Debloat does not edit
 * `wp-config.php`.
 *
 * That is a deliberate limit rather than a missing feature. A plugin that
 * rewrites the file every request depends on is a plugin that can take a site
 * off the internet by getting one line wrong, and no amount of care makes that a
 * good trade for a setting the user can change in ten seconds.
 *
 * So the finding explains the risk, gives the exact line, and proposes nothing.
 */
final class FileEditorRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.file_editor.enabled';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.99;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'wp.file_editor_enabled' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'wp.file_editor_enabled' ) ) {
			return null;
		}

		return $this->inform(
			array(
				'category' => Category::CONFIGURATION,
				'severity' => Severity::LOW,
				'title'    => __( 'Theme and plugin files can be edited from the dashboard', 'wp-debloat' ),
				'summary'  => __( 'The built-in file editor is available under Appearance and Plugins.', 'wp-debloat' ),
				'why'      => __(
					'The file editor lets anyone with administrator access rewrite PHP that runs on every request. That is convenient once and dangerous every other day: it turns a stolen administrator password into the ability to run code, and it makes it easy to break the site with a typo and no way back. Adding define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php removes it. WP Debloat does not edit wp-config.php — a plugin that rewrites the file every request depends on can take a site offline by getting one line wrong.',
					'wp-debloat'
				),
				'evidence' => $this->evidence( $facts )
					->formatted( __( 'File editor', 'wp-debloat' ), __( 'Available', 'wp-debloat' ), 'wp.file_editor_enabled' )
					->optional( __( 'Administrators', 'wp-debloat' ), 'users.admin_count' )
					->build(),
			)
		);
	}
}
