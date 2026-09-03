<?php
/**
 * A registry compatibility rule.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

use Debloater\Contracts\Assert;
use Debloater\Contracts\ContractViolation;

/**
 * What a detected component depends on (BUILD-SPEC §7.2).
 *
 * A compatibility rule is the answer to "if I switch this off, what breaks?"
 * written down in advance, by someone who checked, rather than discovered by a
 * user whose contact form stopped submitting.
 *
 * The `requires` vocabulary is closed on purpose. A resolver can only reason
 * exhaustively about a fixed set of capabilities, and an open vocabulary would
 * mean a rule could declare a dependency nothing knows how to act on — which
 * looks like protection and is not.
 *
 * A rule names a subject and its dependencies. It never names a tweak: which
 * tweak a capability belongs to is the engine's business, and stating it here
 * would mean editing every compatibility file whenever a tweak was renamed.
 */
final class CompatRule {

	/**
	 * The complete capability vocabulary (BUILD-SPEC §7.2).
	 */
	public const CAPABILITIES = array(
		'rest:public',
		'rest:auth',
		'jquery',
		'jquery-migrate',
		'heartbeat',
		'xmlrpc',
		'embeds',
		'dashicons:frontend',
		'cron:wp',
	);

	/**
	 * Subject: "plugin:<slug>", "theme:<slug>" or "host:<vendor>".
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * Capabilities the subject depends on.
	 *
	 * @var array<int,string>
	 */
	public readonly array $requires;

	/**
	 * Why the dependency exists. Shown as evidence when this rule refuses a change.
	 *
	 * @var string|null
	 */
	public readonly ?string $notes;

	/**
	 * How sure we are of the dependency, 0..1.
	 *
	 * @var float
	 */
	public readonly float $confidence;

	/**
	 * Constructor.
	 *
	 * @param string            $subject    Subject identifier.
	 * @param array<int,string> $requires   Capabilities depended on.
	 * @param string|null       $notes      Explanation.
	 * @param float             $confidence Confidence, 0..1.
	 * @throws ContractViolation When the shape is invalid.
	 */
	public function __construct( string $subject, array $requires, ?string $notes = null, float $confidence = 1.0 ) {
		if ( 1 !== preg_match( '/^(plugin|theme|host):[a-z0-9]+(-[a-z0-9]+)*$/', $subject ) ) {
			throw ContractViolation::range(
				self::class,
				'subject',
				sprintf( 'must be plugin:<slug>, theme:<slug> or host:<vendor>, got "%s"', $subject )
			);
		}

		$clean = array();

		foreach ( $requires as $capability ) {
			if ( ! is_string( $capability ) || ! in_array( $capability, self::CAPABILITIES, true ) ) {
				throw ContractViolation::range(
					self::class,
					'requires',
					sprintf(
						'unknown capability "%s"; the vocabulary is closed so the resolver can reason about it exhaustively. Allowed: %s',
						is_string( $capability ) ? $capability : get_debug_type( $capability ),
						implode( ', ', self::CAPABILITIES )
					)
				);
			}

			$clean[ $capability ] = true;
		}

		if ( $confidence < 0.0 || $confidence > 1.0 ) {
			throw ContractViolation::range( self::class, 'confidence', 'must be between 0 and 1 inclusive' );
		}

		$capabilities = array_keys( $clean );
		sort( $capabilities, SORT_STRING );

		$this->subject    = $subject;
		$this->requires   = $capabilities;
		$this->notes      = $notes;
		$this->confidence = $confidence;
	}

	/**
	 * Build from a decoded registry document.
	 *
	 * @param array<string,mixed> $data Decoded compatibility JSON.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'subject', 'requires', 'notes', 'confidence' ) );

		return new self(
			Assert::string( self::class, $data, 'subject' ),
			Assert::stringList( self::class, $data, 'requires' ),
			Assert::nullableString( self::class, $data, 'notes' ),
			array_key_exists( 'confidence', $data )
				? Assert::floatBetween( self::class, $data, 'confidence', 0.0, 1.0 )
				: 1.0
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'subject'    => $this->subject,
			'requires'   => $this->requires,
			'notes'      => $this->notes,
			'confidence' => $this->confidence,
		);
	}

	/**
	 * The kind of thing this rule is about: "plugin", "theme" or "host".
	 *
	 * @return string
	 */
	public function subjectType(): string {
		return substr( $this->subject, 0, (int) strpos( $this->subject, ':' ) );
	}

	/**
	 * The slug this rule is about, without its type prefix.
	 *
	 * @return string
	 */
	public function subjectSlug(): string {
		return substr( $this->subject, (int) strpos( $this->subject, ':' ) + 1 );
	}

	/**
	 * Whether this rule declares a dependency on a capability.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public function requiresCapability( string $capability ): bool {
		return in_array( $capability, $this->requires, true );
	}
}
