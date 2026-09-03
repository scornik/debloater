<?php
/**
 * Analyzer rule: plugins.duplicate_functionality.
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
 * Reports two or more active plugins doing the same kind of job. Info only.
 *
 * This is the finding most likely to be right about the facts and wrong about
 * the conclusion, so it is worth being clear about what it does and does not
 * say.
 *
 * It says: these plugins are, according to a list WP Debloat maintains, in the
 * same functional category, and they are all switched on. That is checkable and
 * usually interesting — two page caches genuinely do fight, two SEO plugins
 * genuinely do write competing tags into the same `<head>`.
 *
 * It does not say which one to remove, and it never proposes removing either.
 * Every one of these situations has a version where it is deliberate: a
 * migration halfway done, an old forms plugin kept alive for one legacy form, a
 * second analytics plugin because the first one is the client's and the second
 * one is yours. WP Debloat cannot tell those apart from a plugin list, and a
 * plugin that deactivates something a person is mid-migration on has done more
 * damage than every millisecond it ever saved.
 *
 * So: reported, with what that particular category actually costs when doubled,
 * and left entirely alone. Deleting a plugin is not reversible from here and is
 * not a decision this product will make.
 */
final class DuplicateFunctionalityRule extends AbstractRule {

	/**
	 * How many active plugins in one category make it worth saying.
	 */
	public const THRESHOLD = 2;

	/**
	 * What running two of a given kind actually costs.
	 *
	 * This lives here rather than in the registry because it is reasoning, and
	 * reasoning is the analyzer's job — the registry says what a plugin *is*.
	 * It is per category because the answers are genuinely different: two page
	 * caches break each other, two forms plugins usually do not.
	 *
	 * A category with no sentence here still produces a finding. It just gets
	 * the general one, which is honest about knowing less.
	 *
	 * @return array<string,string>
	 */
	private function costs(): array {
		return array(
			'cache'     => __(
				'Two page caches is the case that genuinely breaks: each caches the other\'s output, and purging one leaves the other still serving what you just purged.',
				'wp-debloat'
			),
			'seo'       => __(
				'Two SEO plugins write the same titles, canonicals and structured data into the same <head>. Which one wins comes down to hook priority rather than to anything you chose.',
				'wp-debloat'
			),
			'security'  => __(
				'Two security plugins is rarely broken, but it is two sets of firewall rules, two scan schedules, and two places to look when something legitimate gets blocked.',
				'wp-debloat'
			),
			'image'     => __(
				'Two image optimizers compress each other\'s output. That loses quality for nothing, and can leave the media library with two competing sets of derivative files.',
				'wp-debloat'
			),
			'forms'     => __(
				'Several forms plugins is often deliberate — one came bundled with a theme, or an old one still serves a single legacy form. Worth knowing, rarely worth acting on.',
				'wp-debloat'
			),
			'backup'    => __(
				'Two backup plugins means two schedules, two destinations, and a restore that has to pick one. Sometimes that is on purpose.',
				'wp-debloat'
			),
			'analytics' => __(
				'Two analytics plugins usually means two tracking scripts on every page, and often two copies of the same one.',
				'wp-debloat'
			),
		);
	}

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'plugins.duplicate_functionality';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * High, because the claim is narrow. "These two plugins are both SEO
	 * plugins and both active" is a fact about a list, not a judgement about a
	 * site. What is uncertain is whether it matters, and that is exactly why
	 * this rule proposes nothing.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.95;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'plugins.categories' );
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

		$overlaps = $this->overlaps( $facts );

		if ( array() === $overlaps ) {
			return null;
		}

		return $this->inform(
			array(
				'category' => Category::PLUGINS,
				'severity' => Severity::INFO,
				'title'    => sprintf(
					/* translators: %d: number of jobs being done by more than one active plugin. */
					_n(
						'%d job on this site is being done by more than one plugin',
						'%d jobs on this site are being done by more than one plugin',
						count( $overlaps ),
						'wp-debloat'
					),
					count( $overlaps )
				),
				'summary'  => $this->summary( $overlaps ),
				'why'      => $this->why( $overlaps ),
				'evidence' => $this->evidence( $facts )
					->fact( __( 'Active plugins by category', 'wp-debloat' ), 'plugins.categories' )
					->optional( __( 'Active plugins', 'wp-debloat' ), 'plugins.active' )
					->build(),
			)
		);
	}

	/**
	 * The categories with more than one active plugin in them.
	 *
	 * The fact is one row per classified plugin; the grouping is done here,
	 * because grouping is a question about this site and the fact is a list of
	 * observations.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return array<int,array{id:string,label:string,plugins:array<int,string>}>
	 */
	private function overlaps( FactSet $facts ): array {
		$rows = $facts->value( 'plugins.categories', array() );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$grouped = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['category'], $row['plugin'] ) ) {
				continue;
			}

			$category = (string) $row['category'];

			$grouped[ $category ]['id']        = $category;
			$grouped[ $category ]['label']     = (string) ( $row['label'] ?? $category );
			$grouped[ $category ]['plugins'][] = (string) $row['plugin'];
		}

		ksort( $grouped, SORT_STRING );

		$overlaps = array();

		foreach ( $grouped as $group ) {
			$plugins = array_values( array_unique( $group['plugins'] ) );

			if ( count( $plugins ) < self::THRESHOLD ) {
				continue;
			}

			sort( $plugins, SORT_STRING );

			$overlaps[] = array(
				'id'      => $group['id'],
				'label'   => $group['label'],
				'plugins' => $plugins,
			);
		}

		return $overlaps;
	}

	/**
	 * One clause per doubled-up category, naming the plugins.
	 *
	 * @param array<int,array{id:string,label:string,plugins:array<int,string>}> $overlaps Overlaps.
	 * @return string
	 */
	private function summary( array $overlaps ): string {
		$lines = array();

		foreach ( $overlaps as $overlap ) {
			$lines[] = sprintf(
				/* translators: 1: category name, 2: comma-separated plugin slugs. */
				__( '%1$s: %2$s.', 'wp-debloat' ),
				$overlap['label'],
				implode( ', ', $overlap['plugins'] )
			);
		}

		return implode( ' ', $lines );
	}

	/**
	 * What each doubled-up category costs, then what this finding will not do.
	 *
	 * @param array<int,array{id:string,label:string,plugins:array<int,string>}> $overlaps Overlaps.
	 * @return string
	 */
	private function why( array $overlaps ): string {
		$costs = $this->costs();
		$notes = array();

		foreach ( $overlaps as $overlap ) {
			if ( isset( $costs[ $overlap['id'] ] ) ) {
				$notes[] = $costs[ $overlap['id'] ];
			}
		}

		$closing = __(
			'None of this is automatically a mistake, and WP Debloat has no way to tell a duplicate from a migration part-way through or an old plugin kept alive for one thing that still needs it. So it says what it sees and leaves the decision with you. Nothing here will deactivate or delete anything.',
			'wp-debloat'
		);

		return array() === $notes ? $closing : implode( ' ', $notes ) . ' ' . $closing;
	}
}
