<?php
/**
 * A registry profile.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

use Debloater\Contracts\Assert;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Identifier;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Tweak;

/**
 * A named selection policy (BUILD-SPEC §7.3).
 *
 * A profile narrows what the engine may propose. It is a filter and only a
 * filter: it cannot make a change safer, it cannot overrule a `dont_touch`
 * decision, and it cannot admit a destructive operation. Deleting rows is never
 * something a profile decides on someone's behalf — that needs its own
 * confirmation and its own recovery point (BUILD-SPEC §7.4, §13 rule 8).
 *
 * `exclude_destructive` is therefore not really a choice in v1: every shipped
 * profile sets it, and `admits()` refuses a destructive tweak regardless. The
 * flag stays in the schema because it makes the intent explicit in the file
 * rather than only in code.
 */
final class Profile {

	/**
	 * The profile "Fix Safe Issues" uses.
	 */
	public const SAFE = 'safe';

	/**
	 * Profile id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * What this profile is for, in the user's terms.
	 *
	 * @var string
	 */
	public readonly string $description;

	/**
	 * Risk levels this profile is willing to include.
	 *
	 * @var array<int,Risk>
	 */
	public readonly array $include_risk;

	/**
	 * Whether destructive tweaks are excluded.
	 *
	 * @var bool
	 */
	public readonly bool $exclude_destructive;

	/**
	 * Tweak ids this profile always considers.
	 *
	 * @var array<int,string>
	 */
	public readonly array $tweaks;

	/**
	 * Parameter overrides keyed by tweak id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public readonly array $params;

	/**
	 * Constructor.
	 *
	 * @param string                            $id                  Profile id.
	 * @param string                            $title               Human-readable name.
	 * @param array<int,Risk>                   $include_risk        Risk levels included.
	 * @param bool                              $exclude_destructive Whether destructive tweaks are excluded.
	 * @param array<int,string>                 $tweaks              Always-considered tweak ids.
	 * @param array<string,array<string,mixed>> $params              Parameter overrides.
	 * @param string                            $description         What the profile is for.
	 * @throws ContractViolation When the shape is invalid.
	 */
	public function __construct(
		string $id,
		string $title,
		array $include_risk,
		bool $exclude_destructive,
		array $tweaks = array(),
		array $params = array(),
		string $description = ''
	) {
		if ( 1 !== preg_match( Identifier::SLUG_PATTERN, $id ) ) {
			throw ContractViolation::range( self::class, 'id', sprintf( 'must be a lowercase slug, got "%s"', $id ) );
		}

		if ( array() === $include_risk ) {
			throw ContractViolation::range(
				self::class,
				'include_risk',
				'must include at least one risk level; a profile that includes none would select nothing'
			);
		}

		$risks = array();

		foreach ( $include_risk as $risk ) {
			if ( ! $risk instanceof Risk ) {
				throw ContractViolation::type( self::class, 'include_risk[]', Risk::class, $risk );
			}

			$risks[ $risk->value ] = $risk;
		}

		uasort( $risks, static fn ( Risk $a, Risk $b ): int => $a->rank() <=> $b->rank() );

		$this->id                  = $id;
		$this->title               = '' === trim( $title ) ? $id : $title;
		$this->description         = $description;
		$this->include_risk        = array_values( $risks );
		$this->exclude_destructive = $exclude_destructive;
		$this->tweaks              = array_values( $tweaks );
		$this->params              = $params;
	}

	/**
	 * Build from a decoded registry document.
	 *
	 * @param array<string,mixed> $data Decoded profile JSON.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys(
			self::class,
			$data,
			array( 'id', 'title', 'description', 'include_risk', 'exclude_destructive', 'tweaks', 'params' )
		);

		$risks = array();

		foreach ( Assert::stringList( self::class, $data, 'include_risk' ) as $value ) {
			$risk = Risk::tryFrom( $value );

			if ( null === $risk ) {
				throw ContractViolation::range(
					self::class,
					'include_risk',
					sprintf( 'unknown risk level "%s"', $value )
				);
			}

			$risks[] = $risk;
		}

		$params = array();

		foreach ( Assert::stringKeyedMap( self::class, $data, 'params' ) as $tweak_id => $values ) {
			if ( ! is_array( $values ) ) {
				throw ContractViolation::type( self::class, 'params.' . $tweak_id, 'array', $values );
			}

			/** @var array<string,mixed> $values */
			$params[ $tweak_id ] = $values;
		}

		$id = Assert::string( self::class, $data, 'id' );

		return new self(
			$id,
			Assert::stringOr( self::class, $data, 'title', $id ),
			$risks,
			Assert::bool( self::class, $data, 'exclude_destructive' ),
			Assert::stringList( self::class, $data, 'tweaks' ),
			$params,
			Assert::stringOr( self::class, $data, 'description', '' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'                  => $this->id,
			'title'               => $this->title,
			'description'         => $this->description,
			'include_risk'        => array_map( static fn ( Risk $risk ): string => $risk->value, $this->include_risk ),
			'exclude_destructive' => $this->exclude_destructive,
			'tweaks'              => $this->tweaks,
			'params'              => $this->params,
		);
	}

	/**
	 * Whether this profile would admit a tweak.
	 *
	 * Necessary, never sufficient: PreviewPlanner still applies the §7.4
	 * invariants on top, and a profile cannot admit something they exclude.
	 *
	 * @param Tweak $tweak Tweak to consider.
	 * @return bool
	 */
	public function admits( Tweak $tweak ): bool {
		if ( $tweak->destructive ) {
			// Not conditional on the flag. A profile is a filter, and no filter
			// setting turns "delete rows" into something applied because a
			// preset said so.
			return false;
		}

		return in_array( $tweak->risk, $this->include_risk, true );
	}

	/**
	 * The highest risk this profile will include.
	 *
	 * @return Risk
	 */
	public function maximumRisk(): Risk {
		$highest = Risk::SAFE;

		foreach ( $this->include_risk as $risk ) {
			$highest = $highest->max( $risk );
		}

		return $highest;
	}

	/**
	 * Parameter overrides for a tweak.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return array<string,mixed>
	 */
	public function paramsFor( string $tweak_id ): array {
		return $this->params[ $tweak_id ] ?? array();
	}
}
