<?php
/**
 * The outcome of dependency resolution.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Recommend;

/**
 * What survived resolution, and why the rest did not.
 *
 * Rejections carry a reason because they are shown to the user. "Two of the
 * tweaks you picked cannot both be applied" is only useful if it also says which
 * two and which one was dropped.
 */
final class Resolution {

	/**
	 * Accepted tweak ids, in sorted order.
	 *
	 * @var array<int,string>
	 */
	public readonly array $accepted;

	/**
	 * Rejected tweak ids mapped to the reason.
	 *
	 * @var array<string,string>
	 */
	public readonly array $rejected;

	/**
	 * Constructor.
	 *
	 * @param array<int,string>    $accepted Accepted tweak ids.
	 * @param array<string,string> $rejected Rejected ids mapped to reasons.
	 */
	public function __construct( array $accepted, array $rejected = array() ) {
		$accepted = array_values( array_unique( $accepted ) );
		sort( $accepted, SORT_STRING );
		ksort( $rejected, SORT_STRING );

		$this->accepted = $accepted;
		$this->rejected = $rejected;
	}

	/**
	 * Whether every candidate was accepted.
	 *
	 * @return bool
	 */
	public function isComplete(): bool {
		return array() === $this->rejected;
	}

	/**
	 * Whether a tweak id was accepted.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return bool
	 */
	public function accepts( string $tweak_id ): bool {
		return in_array( $tweak_id, $this->accepted, true );
	}

	/**
	 * Why a tweak was rejected, or null when it was not.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string|null
	 */
	public function reasonFor( string $tweak_id ): ?string {
		return $this->rejected[ $tweak_id ] ?? null;
	}

	/**
	 * Array shape.
	 *
	 * @return array{accepted:array<int,string>,rejected:array<string,string>}
	 */
	public function toArray(): array {
		return array(
			'accepted' => $this->accepted,
			'rejected' => $this->rejected,
		);
	}
}
