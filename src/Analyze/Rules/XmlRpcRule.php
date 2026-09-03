<?php
/**
 * Analyzer rule: wp.xmlrpc.enabled.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Analyze\Rules;

use Debloater\Contracts\Category;
use Debloater\Contracts\FactSet;
use Debloater\Contracts\Finding;
use Debloater\Contracts\Severity;

/**
 * Reports that XML-RPC is answering requests. Info only in the MVP.
 *
 * XML-RPC is the most-recommended thing to switch off in every WordPress
 * hardening article, and it is exactly the kind of change this product should be
 * slow about. Jetpack uses it. The mobile apps use it. Some backup plugins and
 * some publishing tools use it. On the sites where it is used, disabling it
 * breaks something the owner will not connect to a change they made in a
 * different plugin last week.
 *
 * Deciding that safely needs the compatibility resolver and the intent profile
 * from Phase 4, so the MVP reports and does not act. That is not a placeholder:
 * an honest "here is what we found, and here is why we are not touching it yet"
 * is worth more than a confident switch.
 */
final class XmlRpcRule extends AbstractRule {

	/**
	 * The finding this rule produces.
	 *
	 * @return string
	 */
	public function findingId(): string {
		return 'wp.xmlrpc.enabled';
	}

	/**
	 * Base confidence for the ideal case.
	 *
	 * @return float
	 */
	public function baseConfidence(): float {
		return 0.97;
	}

	/**
	 * The facts this rule needs.
	 *
	 * @return array<int,string>
	 */
	protected function requiredFacts(): array {
		return array( 'wp.xmlrpc_enabled' );
	}

	/**
	 * Evaluate the facts.
	 *
	 * @param FactSet $facts Facts from the scan.
	 * @return Finding|null
	 */
	public function analyze( FactSet $facts ): ?Finding {
		if ( ! $this->supports( $facts ) || true !== $facts->value( 'wp.xmlrpc_enabled' ) ) {
			return null;
		}

		return $this->inform(
			array(
				'category' => Category::CONFIGURATION,
				'severity' => Severity::LOW,
				'title'    => __( 'XML-RPC is answering requests', 'debloater' ),
				'summary'  => __( 'The xmlrpc.php endpoint exists and nothing is filtering it, so it accepts requests.', 'debloater' ),
				'why'      => __(
					'XML-RPC is the old remote-publishing interface. It attracts steady automated login attempts, and its multicall feature lets an attacker try many passwords in one request. It is also what Jetpack, the WordPress mobile apps and several backup plugins use to talk to a site. Whether switching it off is right here depends on whether anything is using it — which is a question this version reports rather than answers.',
					'debloater'
				),
				'evidence' => $this->evidence( $facts )
					->formatted( __( 'XML-RPC endpoint', 'debloater' ), __( 'Reachable', 'debloater' ), 'wp.xmlrpc_enabled' )
					->optional( __( 'RSD discovery link', 'debloater' ), 'wp.rsd_link' )
					->build(),
			)
		);
	}
}
