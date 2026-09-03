<?php
/**
 * The tweaks a set of findings supports.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Recommend;

use Debloater\Contracts\Tweak;

/**
 * Everything the findings support, before any profile filtering.
 *
 * Keeps the link back to the finding each tweak came from. That matters for the
 * interface — a tweak in a plan should be traceable to the observation that
 * justified it — and for the report afterwards, where "why did this change?"
 * should have an answer better than "it was in the list".
 */
final class Recommendations {

	/**
	 * Tweaks, in id order.
	 *
	 * @var array<int,Tweak>
	 */
	public readonly array $tweaks;

	/**
	 * Tweak id to the finding id that recommended it.
	 *
	 * @var array<string,string>
	 */
	public readonly array $sources;

	/**
	 * Finding ids that supported nothing, mapped to the reason.
	 *
	 * @var array<string,string>
	 */
	public readonly array $skipped;

	/**
	 * Constructor.
	 *
	 * @param array<int,Tweak>     $tweaks  Recommended tweaks.
	 * @param array<string,string> $sources Tweak id to finding id.
	 * @param array<string,string> $skipped Finding ids that supported nothing.
	 */
	public function __construct( array $tweaks, array $sources = array(), array $skipped = array() ) {
		$this->tweaks  = array_values( $tweaks );
		$this->sources = $sources;
		$this->skipped = $skipped;
	}

	/**
	 * Whether a tweak id is recommended.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return bool
	 */
	public function includes( string $tweak_id ): bool {
		return in_array( $tweak_id, $this->tweakIds(), true );
	}

	/**
	 * The recommended tweak ids, in order.
	 *
	 * @return array<int,string>
	 */
	public function tweakIds(): array {
		return array_map( static fn ( Tweak $tweak ): string => $tweak->id, $this->tweaks );
	}

	/**
	 * A recommended tweak by id, or null.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return Tweak|null
	 */
	public function get( string $tweak_id ): ?Tweak {
		foreach ( $this->tweaks as $tweak ) {
			if ( $tweak->id === $tweak_id ) {
				return $tweak;
			}
		}

		return null;
	}

	/**
	 * The finding a tweak came from, or null.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string|null
	 */
	public function sourceOf( string $tweak_id ): ?string {
		return $this->sources[ $tweak_id ] ?? null;
	}

	/**
	 * How many tweaks are recommended.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->tweaks );
	}

	/**
	 * Whether anything is recommended.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->tweaks;
	}

	/**
	 * Array shape, for persistence and the API.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'tweaks'  => array_map( static fn ( Tweak $tweak ): array => $tweak->toArray(), $this->tweaks ),
			'sources' => $this->sources,
			'skipped' => $this->skipped,
		);
	}
}
