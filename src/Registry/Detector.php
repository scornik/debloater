<?php
/**
 * A registry detector.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Registry;

use WPDebloat\Contracts\Assert;
use WPDebloat\Contracts\ContractViolation;
use WPDebloat\Contracts\Identifier;

/**
 * Recognises a plugin, theme or component from local signals (BUILD-SPEC §7.5).
 *
 * A detector observes and nothing else. It never names a tweak, never makes a
 * network request, and never reads anything expensive: the signals are an active
 * plugin file, a defined constant, a declared class or function, an option that
 * exists, or the active theme. Several signals may be given, and any one of them
 * matching is a detection — plugins get renamed, forked and bundled, and a
 * detector that insisted on all of them would quietly stop recognising things.
 *
 * Every fact a detector writes lands under `plugins.detected.`, which is what
 * keeps detection inside PluginScanner's namespace and stops a detector file
 * from being able to rewrite an unrelated observation.
 */
final class Detector {

	/**
	 * The prefix every detector fact must fall under.
	 */
	public const FACT_PREFIX = 'plugins.detected.';

	/**
	 * Signal names a detector may use.
	 */
	private const SIGNALS = array( 'plugin_file', 'plugin_files', 'constant', 'class', 'function', 'option', 'theme' );

	/**
	 * Detector id, which is also the slug it reports under.
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
	 * Signals to look for, keyed by signal name.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $match;

	/**
	 * Facts to write on a match, keyed by fact path.
	 *
	 * @var array<string,scalar|null>
	 */
	public readonly array $sets;

	/**
	 * Why the detector exists, when that is not obvious.
	 *
	 * @var string|null
	 */
	public readonly ?string $notes;

	/**
	 * Constructor.
	 *
	 * @param string                   $id    Detector id.
	 * @param string                   $title Human-readable name.
	 * @param array<string,mixed>      $signals Signals.
	 * @param array<string,scalar|null> $sets Facts to write.
	 * @param string|null              $notes Notes.
	 * @throws ContractViolation When the shape is invalid.
	 */
	public function __construct( string $id, string $title, array $signals, array $sets, ?string $notes = null ) {
		if ( 1 !== preg_match( Identifier::SLUG_PATTERN, $id ) ) {
			throw ContractViolation::range(
				self::class,
				'id',
				sprintf( 'must be a lowercase slug, got "%s"', $id )
			);
		}

		if ( array() === $signals ) {
			throw ContractViolation::range(
				self::class,
				'match',
				'must declare at least one signal; a detector with none would match every site'
			);
		}

		foreach ( array_keys( $signals ) as $signal ) {
			if ( ! in_array( $signal, self::SIGNALS, true ) ) {
				throw ContractViolation::range(
					self::class,
					'match',
					sprintf( 'unknown signal "%s"; allowed: %s', (string) $signal, implode( ', ', self::SIGNALS ) )
				);
			}
		}

		if ( array() === $sets ) {
			throw ContractViolation::range( self::class, 'sets', 'must write at least one fact' );
		}

		foreach ( $sets as $path => $value ) {
			if ( ! is_string( $path ) || ! str_starts_with( $path, self::FACT_PREFIX ) ) {
				throw ContractViolation::range(
					self::class,
					'sets',
					sprintf( 'every fact path must start with "%s", got "%s"', self::FACT_PREFIX, (string) $path )
				);
			}

			if ( null !== $value && ! is_scalar( $value ) ) {
				throw ContractViolation::type( self::class, 'sets.' . $path, 'scalar or null', $value );
			}
		}

		ksort( $sets, SORT_STRING );

		$this->id    = $id;
		$this->title = '' === trim( $title ) ? $id : $title;
		$this->match = $signals;
		$this->sets  = $sets;
		$this->notes = $notes;
	}

	/**
	 * Build from a decoded registry document.
	 *
	 * @param array<string,mixed> $data Decoded detector JSON.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys( self::class, $data, array( 'id', 'title', 'match', 'sets', 'notes' ) );

		$sets = array();

		foreach ( Assert::stringKeyedMap( self::class, $data, 'sets' ) as $path => $value ) {
			if ( null !== $value && ! is_scalar( $value ) ) {
				throw ContractViolation::type( self::class, 'sets.' . $path, 'scalar or null', $value );
			}

			$sets[ $path ] = $value;
		}

		$id = Assert::string( self::class, $data, 'id' );

		return new self(
			$id,
			Assert::stringOr( self::class, $data, 'title', $id ),
			Assert::stringKeyedMap( self::class, $data, 'match' ),
			$sets,
			Assert::nullableString( self::class, $data, 'notes' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'    => $this->id,
			'title' => $this->title,
			'match' => $this->match,
			'sets'  => $this->sets,
			'notes' => $this->notes,
		);
	}

	/**
	 * The signals this detector declares, as a flat list.
	 *
	 * Evaluating a signal means asking WordPress a question — is this plugin
	 * active, is that constant defined, does this option exist — and a registry
	 * document has no business doing that. Detectors describe what to look for;
	 * PluginScanner, which is allowed to know about WordPress, does the looking.
	 *
	 * @return array<int,array{type:string,value:string}>
	 */
	public function signals(): array {
		$signals = array();

		foreach ( $this->match as $type => $value ) {
			if ( 'plugin_files' === $type && is_array( $value ) ) {
				foreach ( $value as $plugin_file ) {
					$signals[] = array(
						'type'  => 'plugin_file',
						'value' => (string) $plugin_file,
					);
				}

				continue;
			}

			if ( is_scalar( $value ) ) {
				$signals[] = array(
					'type'  => (string) $type,
					'value' => (string) $value,
				);
			}
		}

		return $signals;
	}

	/**
	 * The slug this detector reports under, taken from its fact paths.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->id;
	}

	/**
	 * The facts to write when the detector does not match.
	 *
	 * A detector reports both outcomes. "WooCommerce is not installed" is a fact
	 * a rule may legitimately need, and leaving the key absent would make it
	 * indistinguishable from "we did not look".
	 *
	 * Only boolean facts have a meaningful negative. A detector that writes a
	 * version string, say, has nothing to say when it does not match, so that
	 * key is genuinely absent rather than set to a placeholder.
	 *
	 * @return array<string,bool>
	 */
	public function negativeFacts(): array {
		$facts = array();

		foreach ( $this->sets as $path => $value ) {
			if ( is_bool( $value ) ) {
				$facts[ $path ] = ! $value;
			}
		}

		return $facts;
	}
}
