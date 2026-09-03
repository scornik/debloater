<?php
/**
 * A tweak as the registry declares it.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use Debloater\Contracts\Assert;
use Debloater\Contracts\Category;
use Debloater\Contracts\ContractViolation;
use Debloater\Contracts\Identifier;
use Debloater\Contracts\Risk;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakKind;
use Debloater\Contracts\TweakParams;

/**
 * A registry tweak definition (BUILD-SPEC §7.1).
 *
 * The definition says what a tweak *can* be; `Contracts\Tweak` says what it *is*
 * for one site, after parameters have been chosen and the RiskEngine has had its
 * say. `resolve()` is the only bridge between the two, and it validates
 * parameters against the declared schema on the way across — which is what makes
 * BUILD-SPEC §13 rule 5 ("params via var_export of validated values only")
 * enforceable rather than aspirational.
 */
final class TweakDefinition {

	/**
	 * Tweak id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Registry schema version this definition was authored against.
	 *
	 * @var int
	 */
	public readonly int $schema_version;

	/**
	 * Human-readable title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Category.
	 *
	 * @var Category
	 */
	public readonly Category $category;

	/**
	 * Config or data.
	 *
	 * @var TweakKind
	 */
	public readonly TweakKind $kind;

	/**
	 * Declared risk, before any site-specific adjustment.
	 *
	 * @var Risk
	 */
	public readonly Risk $risk;

	/**
	 * Confidence before ConfidenceCalculator applies penalties.
	 *
	 * @var float
	 */
	public readonly float $base_confidence;

	/**
	 * Whether the change can be undone.
	 *
	 * @var bool
	 */
	public readonly bool $reversible;

	/**
	 * Whether applying deletes rows.
	 *
	 * @var bool
	 */
	public readonly bool $destructive;

	/**
	 * Handler file (config) or DataOperation class name (data).
	 *
	 * @var string
	 */
	public readonly string $handler;

	/**
	 * Parameter schema, keyed by parameter name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public readonly array $params;

	/**
	 * What the tweak does, in the user's terms.
	 *
	 * @var string
	 */
	public readonly string $description;

	/**
	 * Plain statements of what stops working.
	 *
	 * @var array<int,string>
	 */
	public readonly array $breaks;

	/**
	 * Tweak ids or fact predicates that must hold.
	 *
	 * @var array<int,string>
	 */
	public readonly array $requires;

	/**
	 * Tweak ids that must not be applied alongside this one.
	 *
	 * @var array<int,string>
	 */
	public readonly array $conflicts;

	/**
	 * Reserved for engine use from Phase 4. The runtime never reads these.
	 *
	 * @var array<int,string>
	 */
	public readonly array $conditions;

	/**
	 * Meter metric names this tweak is expected to move.
	 *
	 * @var array<int,string>
	 */
	public readonly array $measurements;

	/**
	 * Probes that should verify the site after this tweak is applied.
	 *
	 * @var array<int,string>
	 */
	public readonly array $probes;

	/**
	 * WordPress version the underlying hook or option appeared in.
	 *
	 * @var string|null
	 */
	public readonly ?string $since_wp;

	/**
	 * Documentation URL.
	 *
	 * @var string|null
	 */
	public readonly ?string $docs_url;

	/**
	 * Constructor.
	 *
	 * @param string                            $id              Tweak id.
	 * @param int                               $schema_version  Registry schema version.
	 * @param string                            $title           Title.
	 * @param Category                          $category        Category.
	 * @param TweakKind                         $kind            Config or data.
	 * @param Risk                              $risk            Declared risk.
	 * @param float                             $base_confidence Base confidence.
	 * @param bool                              $reversible      Whether reversible.
	 * @param bool                              $destructive     Whether rows are deleted.
	 * @param string                            $handler         Handler file or class.
	 * @param array<string,array<string,mixed>> $params          Parameter schema.
	 * @param string                            $description     Description.
	 * @param array<int,string>                 $breaks          What stops working.
	 * @param array<int,string>                 $requires        Requirements.
	 * @param array<int,string>                 $conflicts       Conflicting tweak ids.
	 * @param array<int,string>                 $conditions      Reserved.
	 * @param array<int,string>                 $measurements    Metric names.
	 * @param array<int,string>                 $probes          Probe names.
	 * @param string|null                       $since_wp        WordPress version.
	 * @param string|null                       $docs_url        Documentation URL.
	 * @throws ContractViolation When an invariant is violated.
	 */
	public function __construct(
		string $id,
		int $schema_version,
		string $title,
		Category $category,
		TweakKind $kind,
		Risk $risk,
		float $base_confidence,
		bool $reversible,
		bool $destructive,
		string $handler,
		array $params,
		string $description,
		array $breaks = array(),
		array $requires = array(),
		array $conflicts = array(),
		array $conditions = array(),
		array $measurements = array(),
		array $probes = array(),
		?string $since_wp = null,
		?string $docs_url = null
	) {
		if ( 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $id ) ) {
			throw ContractViolation::range(
				self::class,
				'id',
				sprintf( 'must be a dotted tweak id, got "%s"', $id )
			);
		}

		if ( $schema_version < 1 ) {
			throw ContractViolation::range( self::class, 'schema_version', 'must be at least 1' );
		}

		if ( $base_confidence < 0.0 || $base_confidence > 1.0 ) {
			throw ContractViolation::range( self::class, 'base_confidence', 'must be between 0 and 1 inclusive' );
		}

		if ( '' === trim( $handler ) ) {
			throw ContractViolation::range( self::class, 'handler', 'must not be empty' );
		}

		if ( TweakKind::CONFIG === $kind && ! str_starts_with( $handler, 'runtime-handlers/' ) ) {
			throw ContractViolation::range(
				self::class,
				'handler',
				sprintf(
					'a config tweak handler must live under runtime-handlers/, got "%s"; '
					. 'generated code may only require files from that directory',
					$handler
				)
			);
		}

		if ( TweakKind::DATA === $kind && str_contains( $handler, '/' ) ) {
			throw ContractViolation::range(
				self::class,
				'handler',
				sprintf( 'a data tweak handler must be a class name, got "%s"', $handler )
			);
		}

		foreach ( $params as $name => $schema ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
				throw ContractViolation::range( self::class, 'params', 'parameter names must be lower_snake_case' );
			}

			if ( ! is_array( $schema ) || ! array_key_exists( 'type', $schema ) ) {
				throw ContractViolation::range(
					self::class,
					'params.' . $name,
					'must declare a type; an unvalidated parameter cannot be emitted into generated code'
				);
			}
		}

		ksort( $params, SORT_STRING );

		$this->id              = $id;
		$this->schema_version  = $schema_version;
		$this->title           = $title;
		$this->category        = $category;
		$this->kind            = $kind;
		$this->risk            = $risk;
		$this->base_confidence = $base_confidence;
		$this->reversible      = $reversible;
		$this->destructive     = $destructive;
		$this->handler         = $handler;
		$this->params          = $params;
		$this->description     = $description;
		$this->breaks          = array_values( $breaks );
		$this->requires        = array_values( $requires );
		$this->conflicts       = array_values( $conflicts );
		$this->conditions      = array_values( $conditions );
		$this->measurements    = array_values( $measurements );
		$this->probes          = array_values( $probes );
		$this->since_wp        = $since_wp;
		$this->docs_url        = $docs_url;
	}

	/**
	 * Build from a decoded registry document.
	 *
	 * The document is expected to have passed schema validation already; this
	 * repeats the structural checks the contract layer owns, so a definition
	 * built by hand in a test is held to the same standard as one on disk.
	 *
	 * @param array<string,mixed> $data Decoded registry JSON.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys(
			self::class,
			$data,
			array(
				'id',
				'schema_version',
				'title',
				'category',
				'kind',
				'risk',
				'base_confidence',
				'reversible',
				'destructive',
				'handler',
				'params',
				'description',
				'breaks',
				'requires',
				'conflicts',
				'conditions',
				'measurements',
				'probes',
				'since_wp',
				'docs_url',
			)
		);

		$params = array();

		foreach ( Assert::stringKeyedMap( self::class, $data, 'params' ) as $name => $schema ) {
			if ( ! is_array( $schema ) ) {
				throw ContractViolation::type( self::class, 'params.' . $name, 'array', $schema );
			}

			/** @var array<string,mixed> $schema */
			$params[ $name ] = $schema;
		}

		return new self(
			Assert::string( self::class, $data, 'id' ),
			Assert::int( self::class, $data, 'schema_version' ),
			Assert::string( self::class, $data, 'title' ),
			Assert::enum( self::class, $data, 'category', Category::class ),
			Assert::enum( self::class, $data, 'kind', TweakKind::class ),
			Assert::enum( self::class, $data, 'risk', Risk::class ),
			Assert::float( self::class, $data, 'base_confidence' ),
			Assert::bool( self::class, $data, 'reversible' ),
			Assert::bool( self::class, $data, 'destructive' ),
			Assert::string( self::class, $data, 'handler' ),
			$params,
			Assert::string( self::class, $data, 'description' ),
			Assert::stringList( self::class, $data, 'breaks' ),
			Assert::stringList( self::class, $data, 'requires' ),
			Assert::stringList( self::class, $data, 'conflicts' ),
			Assert::stringList( self::class, $data, 'conditions' ),
			Assert::stringList( self::class, $data, 'measurements' ),
			Assert::stringList( self::class, $data, 'probes' ),
			Assert::nullableString( self::class, $data, 'since_wp' ),
			Assert::nullableString( self::class, $data, 'docs_url' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'schema_version'  => $this->schema_version,
			'title'           => $this->title,
			'category'        => $this->category->value,
			'kind'            => $this->kind->value,
			'risk'            => $this->risk->value,
			'base_confidence' => $this->base_confidence,
			'reversible'      => $this->reversible,
			'destructive'     => $this->destructive,
			'handler'         => $this->handler,
			'params'          => $this->params,
			'description'     => $this->description,
			'breaks'          => $this->breaks,
			'requires'        => $this->requires,
			'conflicts'       => $this->conflicts,
			'conditions'      => $this->conditions,
			'measurements'    => $this->measurements,
			'probes'          => $this->probes,
			'since_wp'        => $this->since_wp,
			'docs_url'        => $this->docs_url,
		);
	}

	/**
	 * The declared default for every parameter that has one.
	 *
	 * @return TweakParams
	 */
	public function defaultParams(): TweakParams {
		$defaults = array();

		foreach ( $this->params as $name => $schema ) {
			if ( array_key_exists( 'default', $schema ) ) {
				$defaults[ $name ] = $schema['default'];
			}
		}

		return new TweakParams( $defaults );
	}

	/**
	 * Validate parameter values against the declared schema.
	 *
	 * Missing parameters fall back to their declared default. Unknown parameters
	 * are rejected: an unrecognised key must never reach code generation.
	 *
	 * @param array<string,mixed> $values Candidate values.
	 * @return TweakParams
	 * @throws \RuntimeException When a value does not satisfy the declared schema.
	 * @throws ContractViolation When a parameter is unknown or malformed.
	 */
	public function validateParams( array $values ): TweakParams {
		$unknown = array_values( array_diff( array_keys( $values ), array_keys( $this->params ) ) );

		if ( array() !== $unknown ) {
			throw ContractViolation::unknownKeys( self::class . '::params', $unknown );
		}

		$resolved = $this->defaultParams()->toArray();

		foreach ( $values as $name => $value ) {
			$validator = new SchemaValidator( $this->params[ $name ] );

			$validator->assertValid( $value, sprintf( 'Parameter "%s" of tweak "%s"', $name, $this->id ) );

			$resolved[ $name ] = $value;
		}

		return new TweakParams( $resolved );
	}

	/**
	 * Resolve this definition into a tweak for one site.
	 *
	 * @param array<string,mixed> $values Parameter values, defaults filled in.
	 * @param Risk|null           $risk   Final risk, defaulting to the declared risk.
	 * @return Tweak
	 * @throws ContractViolation When the resulting tweak is invalid.
	 */
	public function resolve( array $values = array(), ?Risk $risk = null ): Tweak {
		return new Tweak(
			$this->id,
			$this->title,
			$this->category,
			$this->kind,
			$risk ?? $this->risk,
			$this->destructive,
			$this->reversible,
			$this->validateParams( $values ),
			$this->handler,
			$this->requires,
			$this->conflicts,
			$this->probes
		);
	}

	/**
	 * Tweak ids this definition requires, ignoring fact predicates.
	 *
	 * Fact predicates ("fact:plugins.detected.woocommerce=true") are resolved by
	 * DependencyResolver v2 in Phase 4; v1 handles tweak ids only.
	 *
	 * @return array<int,string>
	 */
	public function requiredTweakIds(): array {
		return array_values(
			array_filter( $this->requires, static fn ( string $requirement ): bool => ! str_starts_with( $requirement, 'fact:' ) )
		);
	}

	/**
	 * Fact predicates this definition requires.
	 *
	 * @return array<int,string>
	 */
	public function requiredFactPredicates(): array {
		return array_values(
			array_filter( $this->requires, static fn ( string $requirement ): bool => str_starts_with( $requirement, 'fact:' ) )
		);
	}
}
