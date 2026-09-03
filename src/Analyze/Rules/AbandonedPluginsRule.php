<?php
/**
 * Analyzer rule: plugins.abandoned.
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
 * Reports active plugins that look as though nobody is maintaining them.
 * Info only.
 *
 * The finding is the same either way; what changes is how much it is worth.
 *
 * **With the wordpress.org check (the user opted in for this scan).** The date
 * is the plugin's last release. "No release in two years" is the same signal
 * wordpress.org itself surfaces, and it is a statement about the plugin.
 *
 * **Without it (the default).** The date is the modification time of the
 * plugin's main file on this server. That answers a narrower question — when
 * this copy last changed here — and it is wrong in both directions. A site moved
 * by copying files has every mtime reset to the day of the move, so a genuinely
 * abandoned plugin can look brand new. And a plugin whose author has shipped
 * three releases the site never installed looks abandoned when it is not: what
 * is stale is the installation, not the plugin.
 *
 * That second reading is still worth having — an install nobody has updated in
 * two years is worth knowing about on its own terms — but it is a different
 * claim, so it is made in different words and at lower confidence, and the
 * evidence says which reading produced it.
 *
 * Either way this proposes nothing. "Abandoned" is a reason to go and look, and
 * plenty of small plugins are simply finished.
 */
final class AbandonedPluginsRule extends AbstractRule {

	/**
	 * How long without a sign of life before it is worth saying, in days.
	 *
	 * Two years is what wordpress.org itself uses to mark a plugin untested.
	 */
	public const STALE_DAYS = 730;

	/**
	 * Confidence when the reading came from wordpress.org.
	 */
	public const CONFIDENCE_WP_ORG = 0.9;

	/**
	 * Confidence when the reading came from file modification times.
	 *
	 * Low on purpose. This is the number that says the finding is a prompt to go
	 * and look, not a conclusion.
	 */
	public const CONFIDENCE_FILE_MTIME = 0.35;

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'plugins.abandoned';
	}

	/**
	 * Base confidence for the ideal case, which is the opt-in lookup.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return self::CONFIDENCE_WP_ORG;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'plugins.active', 'plugins.meta', 'plugins.update_source' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) ) {
			return null;
		}

		$source = (string) $facts->value( 'plugins.update_source', 'file_mtime' );
		$stale  = $this->stale( $facts, $source );

		if ( array() === $stale ) {
			return null;
		}

		$from_wp_org = 'wp_org' === $source;

		return $this->inform(
			array(
				'category'   => Category::PLUGINS,
				'severity'   => Severity::INFO,
				'confidence' => $from_wp_org ? self::CONFIDENCE_WP_ORG : self::CONFIDENCE_FILE_MTIME,
				'title'      => $this->title( count( $stale ), $from_wp_org ),
				'summary'    => $this->summary( $stale ),
				'why'        => $this->why( $from_wp_org ),
				'evidence'   => $this->evidence( $facts )
					->fact( __( 'Where this reading came from', 'wp-debloat' ), 'plugins.update_source' )
					->formatted(
						__( 'Plugins with no sign of life', 'wp-debloat' ),
						$this->summary( $stale ),
						'plugins.meta'
					)
					->optional( __( 'Active plugins', 'wp-debloat' ), 'plugins.active' )
					->build(),
			)
		);
	}

	/**
	 * Active plugins whose newest sign of life is older than the threshold.
	 *
	 * @param FactSet $facts  Facts from the scan.
	 * @param string  $source Which reading is in play.
	 * @return array<string,string> Plugin name to the date behind it.
	 */
	private function stale( FactSet $facts, string $source ): array {
		$active = $facts->value( 'plugins.active', array() );
		$meta   = $facts->value( 'plugins.meta', array() );

		if ( ! is_array( $active ) || ! is_array( $meta ) ) {
			return array();
		}

		$cutoff = time() - ( self::STALE_DAYS * DAY_IN_SECONDS );
		$stale  = array();

		foreach ( $active as $plugin_file ) {
			$entry = $meta[ $plugin_file ] ?? null;

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$seen = $this->lastSignOfLife( $entry, $source );

			if ( null === $seen || $seen > $cutoff ) {
				continue;
			}

			$name = '' !== (string) ( $entry['name'] ?? '' )
				? (string) $entry['name']
				: (string) $plugin_file;

			$stale[ $name ] = gmdate( 'Y-m-d', $seen );
		}

		ksort( $stale, SORT_STRING );

		return $stale;
	}

	/**
	 * The newest sign of life for one plugin, as a timestamp.
	 *
	 * With the opt-in lookup, a plugin that was not found on wordpress.org has
	 * no release date at all, and no answer is returned. Falling back to the
	 * file time there would quietly mix two different claims inside one finding.
	 *
	 * @param array<string,mixed> $entry  One entry from plugins.meta.
	 * @param string              $source Which reading is in play.
	 * @return int|null
	 */
	private function lastSignOfLife( array $entry, string $source ): ?int {
		if ( 'wp_org' === $source ) {
			if ( ! isset( $entry['last_updated'] ) || ! is_string( $entry['last_updated'] ) ) {
				return null;
			}

			$released = strtotime( $entry['last_updated'] . ' UTC' );

			return false === $released ? null : $released;
		}

		$mtime = $entry['file_mtime'] ?? null;

		return is_int( $mtime ) && $mtime > 0 ? $mtime : null;
	}

	/**
	 * The headline, which says which question was answered.
	 *
	 * @param int  $count       How many plugins.
	 * @param bool $from_wp_org Whether the reading came from wordpress.org.
	 * @return string
	 */
	private function title( int $count, bool $from_wp_org ): string {
		if ( $from_wp_org ) {
			return sprintf(
				/* translators: %d: number of active plugins with no recent release. */
				_n(
					'%d active plugin has had no release in two years',
					'%d active plugins have had no release in two years',
					$count,
					'wp-debloat'
				),
				$count
			);
		}

		return sprintf(
			/* translators: %d: number of active plugins whose files have not changed in two years. */
			_n(
				'%d active plugin has not been updated on this server in two years',
				'%d active plugins have not been updated on this server in two years',
				$count,
				'wp-debloat'
			),
			$count
		);
	}

	/**
	 * The plugins and their dates.
	 *
	 * @param array<string,string> $stale Plugin name to date.
	 * @return string
	 */
	private function summary( array $stale ): string {
		$parts = array();

		foreach ( $stale as $name => $date ) {
			$parts[] = sprintf(
				/* translators: 1: plugin name, 2: ISO date. */
				__( '%1$s (%2$s)', 'wp-debloat' ),
				$name,
				$date
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * What the reading means, and what it does not.
	 *
	 * @param bool $from_wp_org Whether the reading came from wordpress.org.
	 * @return string
	 */
	private function why( bool $from_wp_org ): string {
		if ( $from_wp_org ) {
			return __(
				'A plugin with no release in two years is one nobody is watching for security advisories, and one that has not been tested against anything WordPress has shipped since. That is worth knowing. It is not automatically a problem: small plugins that do one thing are sometimes simply finished. This is a reason to go and look, not a recommendation, and WP Debloat will not deactivate or delete anything.',
				'wp-debloat'
			);
		}

		return __(
			'You did not ask WP Debloat to check wordpress.org on this scan, so nothing left your server and this reading is the weaker one: when each plugin\'s files last changed here. That is not a release date. Moving a site by copying files resets it, so a genuinely abandoned plugin can look new; and a plugin whose author has shipped releases you never installed looks abandoned when what is actually stale is your copy. Either way it is worth a look. Run the scan with the wordpress.org check if you want the real release dates.',
			'wp-debloat'
		);
	}
}
