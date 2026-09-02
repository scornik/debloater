<?php
/**
 * A plan and an account of what was left out of it.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Recommend;

use WPDebloat\Contracts\PreviewPlan;

/**
 * The plan, plus why each candidate that did not make it was excluded.
 *
 * The exclusions are not diagnostics. A user who ran a scan, saw eleven
 * findings, and is now looking at a plan with six changes in it is owed an
 * answer to "what happened to the other five" that is better than silence — and
 * the answers are all interesting: this one is refused because Contact Form 7
 * depends on it, that one is medium-risk and wants reviewing, the other needs
 * something the scan could not observe.
 */
final class PlanResult {

	/**
	 * The plan itself.
	 *
	 * @var PreviewPlan
	 */
	public readonly PreviewPlan $plan;

	/**
	 * Tweak ids left out, mapped to the reason.
	 *
	 * @var array<string,string>
	 */
	public readonly array $excluded;

	/**
	 * Constructor.
	 *
	 * @param PreviewPlan          $plan     The plan.
	 * @param array<string,string> $excluded Tweak ids left out, with reasons.
	 */
	public function __construct( PreviewPlan $plan, array $excluded = array() ) {
		ksort( $excluded, SORT_STRING );

		$this->plan     = $plan;
		$this->excluded = $excluded;
	}

	/**
	 * Whether the plan would change anything.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->plan->isEmpty();
	}

	/**
	 * How many changes the plan contains.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->plan->tweaks );
	}

	/**
	 * Why a tweak was left out, or null when it is in the plan.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string|null
	 */
	public function exclusionReason( string $tweak_id ): ?string {
		return $this->excluded[ $tweak_id ] ?? null;
	}

	/**
	 * Whether the plan includes a tweak.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return bool
	 */
	public function includes( string $tweak_id ): bool {
		return $this->plan->contains( $tweak_id );
	}

	/**
	 * Array shape, for persistence and the API.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'plan'     => $this->plan->toArray(),
			'excluded' => $this->excluded,
		);
	}
}
