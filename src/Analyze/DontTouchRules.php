<?php
/**
 * Decides which findings must not be acted on.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Analyze;

use WPDebloat\Contracts\Decision;
use WPDebloat\Contracts\FactSet;
use WPDebloat\Contracts\Finding;
use WPDebloat\Registry\Registry;

/**
 * Turns a recommendation into a refusal (BUILD-SPEC §6, locked decision #6).
 *
 * "Don't touch" is a first-class outcome, not the absence of one. A site where
 * Contact Form 7 depends on the public REST API should not be shown a greyed-out
 * suggestion to restrict it, and should certainly not be shown an enabled one:
 * it should be told, in plain words, that this is one of the things WP Debloat
 * looked at and decided to leave alone, and why.
 *
 * Two sources feed the decision:
 *
 * - **Capability dependencies**, from the compatibility registry. A finding
 *   declares the capability its change would remove; if anything present on the
 *   site depends on that capability, the finding becomes dont_touch and names
 *   the dependent.
 * - **Situational rules**, which are about the site rather than about a
 *   declared dependency. BUILD-SPEC §17 Phase 3 gives the first case: Heartbeat
 *   on a store with several recent editors, where slowing the poll is defensible
 *   in general and wrong here. Phase 15 gives the second and sharper one: cart
 *   fragments on a store with a mini-cart in its header, where the change would
 *   leave a cart total that never updates.
 *
 * A refusal always carries its reason. The contract enforces that a dont_touch
 * finding has a `decision_reason`; this class is where the reason is written,
 * naming what depends on what.
 */
final class DontTouchRules {

	/**
	 * Capability each finding's change would take away entirely.
	 *
	 * A dependent on one of these is a refusal: the capability the dependent
	 * declared would simply stop being there.
	 *
	 * A finding not listed here has no declared capability, so no compatibility
	 * rule can refuse it. That is a deliberate whitelist: a finding whose
	 * consequences nobody has mapped should not be silently assumed harmless,
	 * but neither should it be refused for a dependency nobody declared.
	 */
	private const REMOVES_CAPABILITY = array(
		'wp.jquery_migrate.loaded' => 'jquery-migrate',
		'wp.dashicons.frontend'    => 'dashicons:frontend',
		'wp.embeds.enabled'        => 'embeds',
		'wp.xmlrpc.enabled'        => 'xmlrpc',
		'wp.rest.public'           => 'rest:public',
	);

	/**
	 * Capability each finding's change *affects* without removing.
	 *
	 * The distinction is the difference between a refusal and a caution, and
	 * getting it wrong in either direction is a real failure.
	 *
	 * Heartbeat is the case that forced it. WooCommerce declares a dependency on
	 * `heartbeat`, and it is a real one: the checkout keep-alive needs it. But
	 * `core.heartbeat_interval` does not remove Heartbeat — it slows it from 15
	 * seconds to 60. Treating that as a removal would refuse a reasonable change
	 * on every WooCommerce site in existence, on the strength of a dependency
	 * that is still satisfied afterwards.
	 *
	 * So a dependent on one of these counts towards `dependencies_detected` —
	 * lowering confidence, because a site with something depending on this is a
	 * site where we should be less sure — and does not refuse. Refusing a change
	 * of degree is the job of a situational rule, which can weigh how the site is
	 * actually used.
	 *
	 * The `heartbeat` capability becomes a refusal again the moment a tweak
	 * exists that switches Heartbeat off rather than slowing it, because that
	 * tweak belongs in the map above.
	 */
	private const AFFECTS_CAPABILITY = array(
		'wp.heartbeat.aggressive' => 'heartbeat',
	);

	/**
	 * How many recent editors make Heartbeat load-bearing.
	 *
	 * One person editing cannot collide with themselves. Two can.
	 */
	public const COLLABORATIVE_EDITOR_THRESHOLD = 2;

	/**
	 * The registry holding the compatibility rules.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Facts from the scan.
	 *
	 * @var FactSet
	 */
	private FactSet $facts;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry Registry with compatibility rules.
	 * @param FactSet  $facts    Facts from the scan.
	 */
	public function __construct( Registry $registry, FactSet $facts ) {
		$this->registry = $registry;
		$this->facts    = $facts;
	}

	/**
	 * Apply every refusal rule to a finding.
	 *
	 * Returns the finding unchanged when nothing refuses it. An info finding is
	 * never refused: it proposes nothing, so there is nothing to decline.
	 *
	 * @param Finding $finding Finding to consider.
	 * @return Finding
	 */
	public function apply( Finding $finding ): Finding {
		if ( Decision::RECOMMEND !== $finding->decision ) {
			return $finding;
		}

		$reason = $this->situationalReason( $finding ) ?? $this->dependencyReason( $finding );

		if ( null === $reason ) {
			return $finding;
		}

		return $finding->withDecision( Decision::DONT_TOUCH, $reason );
	}

	/**
	 * How many present components depend on what a finding would change.
	 *
	 * Used for the finding's `dependencies_detected` count and, through it, for
	 * the confidence penalty — a dependency that does not amount to a refusal
	 * still amounts to a reason for less certainty.
	 *
	 * @param string $finding_id Finding id.
	 * @return int
	 */
	public function dependentCount( string $finding_id ): int {
		return count( $this->dependents( $finding_id ) );
	}

	/**
	 * Everything present on the site that depends on what a finding would change.
	 *
	 * Covers both removal and effect, because both are reasons for less
	 * confidence. Only the removal set refuses.
	 *
	 * @param string $finding_id Finding id.
	 * @return array<int,\WPDebloat\Registry\CompatRule>
	 */
	public function dependents( string $finding_id ): array {
		$capability = self::REMOVES_CAPABILITY[ $finding_id ] ?? ( self::AFFECTS_CAPABILITY[ $finding_id ] ?? null );

		return $this->dependentsOnCapability( $capability );
	}

	/**
	 * The dependents that amount to a refusal.
	 *
	 * @param string $finding_id Finding id.
	 * @return array<int,\WPDebloat\Registry\CompatRule>
	 */
	public function blockingDependents( string $finding_id ): array {
		return $this->dependentsOnCapability( self::REMOVES_CAPABILITY[ $finding_id ] ?? null );
	}

	/**
	 * Present subjects depending on a capability, or none when there is no
	 * capability to depend on.
	 *
	 * @param string|null $capability Capability name.
	 * @return array<int,\WPDebloat\Registry\CompatRule>
	 */
	private function dependentsOnCapability( ?string $capability ): array {
		if ( null === $capability ) {
			return array();
		}

		$detected = $this->facts->value( 'plugins.detected', array() );

		if ( ! is_array( $detected ) ) {
			$detected = array();
		}

		/** @var array<string,bool> $detected */
		return $this->registry->dependentsOn(
			$capability,
			$detected,
			(string) $this->facts->value( 'theme.active', '' ),
			(string) $this->facts->value( 'env.host_vendor', '' )
		);
	}

	/**
	 * A refusal grounded in a declared dependency, or null.
	 *
	 * @param Finding $finding Finding to consider.
	 * @return string|null
	 */
	private function dependencyReason( Finding $finding ): ?string {
		$dependents = $this->blockingDependents( $finding->id );

		if ( array() === $dependents ) {
			return null;
		}

		$names = array();
		$notes = array();

		foreach ( $dependents as $rule ) {
			$names[] = $rule->subjectSlug();

			if ( null !== $rule->notes ) {
				$notes[] = $rule->notes;
			}
		}

		$reason = sprintf(
			/* translators: %s: comma-separated list of plugin or theme slugs. */
			_n(
				'%s is installed and depends on this, so changing it would break something you are using.',
				'These are installed and depend on this, so changing it would break something you are using: %s.',
				count( $names ),
				'wp-debloat'
			),
			implode( ', ', $names )
		);

		if ( array() !== $notes ) {
			$reason .= ' ' . $notes[0];
		}

		return $reason;
	}

	/**
	 * A refusal grounded in how the site is used, or null.
	 *
	 * @param Finding $finding Finding to consider.
	 * @return string|null
	 */
	private function situationalReason( Finding $finding ): ?string {
		if ( 'woo.cart_fragments.everywhere' === $finding->id ) {
			return $this->miniCartReason();
		}

		if ( 'wp.heartbeat.aggressive' !== $finding->id ) {
			return null;
		}

		$editors = (int) $this->facts->value( 'users.recent_editors_7d', 0 );
		$store   = $this->isDetected( 'woocommerce' );

		if ( $editors < self::COLLABORATIVE_EDITOR_THRESHOLD || ! $store ) {
			return null;
		}

		return sprintf(
			/* translators: %d: number of people who edited content in the last seven days. */
			__(
				'%d people edited content here in the last week and this is a WooCommerce store. Heartbeat is what warns them they are about to overwrite each other, and what keeps a checkout session from expiring mid-order. Slowing it down here would cost more than it saves.',
				'wp-debloat'
			),
			$editors
		);
	}

	/**
	 * A refusal because something on this site shows a cart away from the shop.
	 *
	 * The cart-fragments change is the one where WP Debloat is most likely to be
	 * confidently wrong. Most shop themes put a cart total in the header, and on
	 * such a site the fragments are needed on every page — that is what keeps the
	 * total correct. Making them conditional there leaves a number that never
	 * changes until the visitor reloads, which is worse than the request it saved
	 * and looks like the shop is broken.
	 *
	 * So this is a refusal rather than a warning or a confidence penalty. There
	 * is no version of "apply it anyway and see" that is acceptable on a store.
	 *
	 * @return string|null
	 */
	private function miniCartReason(): ?string {
		if ( true !== $this->facts->value( 'woo.mini_cart' ) ) {
			return null;
		}

		$pages = $this->facts->value( 'woo.mini_cart_pages', array() );
		$pages = is_array( $pages ) ? array_map( 'strval', $pages ) : array();

		if ( array() === $pages ) {
			return __(
				'Something on this site shows a cart away from the shop, so the cart-fragments script is needed on every page to keep it correct. Making it conditional here would leave a cart total that never updates.',
				'wp-debloat'
			);
		}

		return sprintf(
			/* translators: %s: comma-separated page paths showing a cart. */
			_n(
				'This page shows a cart away from the shop: %s. The cart-fragments script is what keeps that total correct, so it is needed on every page; making it conditional would leave a number that never updates.',
				'These pages show a cart away from the shop: %s. The cart-fragments script is what keeps those totals correct, so it is needed on every page; making it conditional would leave numbers that never update.',
				count( $pages ),
				'wp-debloat'
			),
			implode( ', ', array_slice( $pages, 0, 5 ) )
		);
	}

	/**
	 * Whether a detector reported a component as present.
	 *
	 * @param string $slug Detector slug.
	 * @return bool
	 */
	private function isDetected( string $slug ): bool {
		$detected = $this->facts->value( 'plugins.detected', array() );

		return is_array( $detected ) && ! empty( $detected[ $slug ] );
	}
}
