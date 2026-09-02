<?php
/**
 * A site's configuration, as a document.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Config;

use WPDebloat\Contracts\Context;
use WPDebloat\Contracts\ContractViolation;
use WPDebloat\Contracts\Identifier;
use WPDebloat\Contracts\TweakKind;
use WPDebloat\Recommend\IntentProfile;
use WPDebloat\Registry\Registry;
use WPDebloat\Storage\State;

/**
 * Configuration as code (BUILD-SPEC §17 Phase 7).
 *
 * What a site has chosen, in a form that can be committed to a repository and
 * applied to another site: the selected tweaks with their parameters, and the
 * stated intent that produced them.
 *
 * What is deliberately **not** in it: findings, facts, scores, snapshots, run
 * history. Those describe one site at one moment and cannot be transplanted;
 * exporting them would invite somebody to import another site's conclusions and
 * act on them here.
 *
 * The `site_hash` and `registry_hash` travel with the document as provenance,
 * never as a gate. A document from another site is exactly the use case, and a
 * document from a different registry version is worth warning about but not
 * worth refusing — that is what the per-tweak validation is for.
 */
final class ConfigDocument {

	/**
	 * Document format version.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Tweak id to parameters.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public readonly array $selection;

	/**
	 * The stated intent.
	 *
	 * @var IntentProfile
	 */
	public readonly IntentProfile $intent;

	/**
	 * Plugin version that produced the document.
	 *
	 * @var string
	 */
	public readonly string $plugin_version;

	/**
	 * Registry hash it was produced against.
	 *
	 * @var string
	 */
	public readonly string $registry_hash;

	/**
	 * Site it came from.
	 *
	 * @var string
	 */
	public readonly string $site_hash;

	/**
	 * When it was produced, in UTC.
	 *
	 * @var string
	 */
	public readonly string $exported_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $selection      Tweak id to parameters.
	 * @param IntentProfile                     $intent         Stated intent.
	 * @param string                            $plugin_version Plugin version.
	 * @param string                            $registry_hash  Registry hash.
	 * @param string                            $site_hash      Originating site.
	 * @param string                            $exported_at    UTC timestamp.
	 * @throws ContractViolation When a tweak id or parameter block is malformed.
	 */
	public function __construct(
		array $selection,
		IntentProfile $intent,
		string $plugin_version,
		string $registry_hash = '',
		string $site_hash = '',
		string $exported_at = ''
	) {
		$clean = array();

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! is_string( $tweak_id ) || 1 !== preg_match( Identifier::TWEAK_ID_PATTERN, $tweak_id ) ) {
				throw ContractViolation::type( self::class, 'selection key', 'tweak id', $tweak_id );
			}

			if ( ! is_array( $params ) ) {
				throw ContractViolation::type( self::class, 'selection[' . $tweak_id . ']', 'array', $params );
			}

			foreach ( array_keys( $params ) as $name ) {
				if ( ! is_string( $name ) ) {
					throw ContractViolation::type( self::class, 'parameter name', 'string', $name );
				}
			}

			$clean[ $tweak_id ] = $params;
		}

		ksort( $clean, SORT_STRING );

		$this->selection      = $clean;
		$this->intent         = $intent;
		$this->plugin_version = $plugin_version;
		$this->registry_hash  = $registry_hash;
		$this->site_hash      = $site_hash;
		$this->exported_at    = '' === $exported_at ? gmdate( 'Y-m-d\TH:i:s\Z' ) : $exported_at;
	}

	/**
	 * Build from what a site currently has.
	 *
	 * @param State         $state    Plugin state.
	 * @param IntentProfile $intent   Stated intent.
	 * @param Registry      $registry The registry.
	 * @param Context       $context  Site context.
	 * @return self
	 */
	public static function fromSite(
		State $state,
		IntentProfile $intent,
		Registry $registry,
		Context $context
	): self {
		return new self(
			$state->selection(),
			$intent,
			$context->plugin_version,
			$registry->hash(),
			$context->siteHash()
		);
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is wrong.
	 */
	public static function fromArray( array $data ): self {
		$version = $data['schema_version'] ?? null;

		if ( self::SCHEMA_VERSION !== $version ) {
			throw ContractViolation::range(
				self::class,
				'schema_version',
				sprintf( 'must be %d; this file says %s', self::SCHEMA_VERSION, wp_json_encode( $version ) )
			);
		}

		$selection = $data['selection'] ?? array();

		if ( ! is_array( $selection ) ) {
			throw ContractViolation::type( self::class, 'selection', 'object', $selection );
		}

		$intent = $data['intent_profile'] ?? array();

		return new self(
			$selection,
			IntentProfile::fromArray( is_array( $intent ) ? $intent : array() ),
			is_string( $data['plugin_version'] ?? null ) ? $data['plugin_version'] : '',
			is_string( $data['registry_hash'] ?? null ) ? $data['registry_hash'] : '',
			is_string( $data['site_hash'] ?? null ) ? $data['site_hash'] : '',
			is_string( $data['exported_at'] ?? null ) ? $data['exported_at'] : ''
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'plugin_version' => $this->plugin_version,
			'registry_hash'  => $this->registry_hash,
			'site_hash'      => $this->site_hash,
			'exported_at'    => $this->exported_at,
			'intent_profile' => $this->intent->toArray(),
			'selection'      => (object) $this->selection,
		);
	}

	/**
	 * Everything wrong with this document on this site.
	 *
	 * Returns reasons rather than throwing on the first one, because somebody
	 * importing a colleague's configuration wants to know everything that will
	 * not transfer, not to discover it one run at a time.
	 *
	 * @param Registry $registry The registry to check against.
	 * @return array<string,string> Tweak id to the reason it cannot be used.
	 */
	public function problems( Registry $registry ): array {
		$problems = array();

		foreach ( $this->selection as $tweak_id => $params ) {
			if ( ! $registry->has( $tweak_id ) ) {
				$problems[ $tweak_id ] = __(
					'This version of WP Debloat does not know that change, so it cannot be applied here.',
					'wp-debloat'
				);

				continue;
			}

			try {
				$registry->tweak( $tweak_id )->resolve( $params );
			} catch ( \Throwable $error ) {
				$problems[ $tweak_id ] = $error->getMessage();
			}
		}

		return $problems;
	}

	/**
	 * The document with the unusable entries removed.
	 *
	 * @param Registry $registry The registry to check against.
	 * @return self
	 */
	public function withoutProblems( Registry $registry ): self {
		$problems = $this->problems( $registry );

		if ( array() === $problems ) {
			return $this;
		}

		return new self(
			array_diff_key( $this->selection, $problems ),
			$this->intent,
			$this->plugin_version,
			$this->registry_hash,
			$this->site_hash,
			$this->exported_at
		);
	}

	/**
	 * The config tweaks in the document.
	 *
	 * @param Registry $registry The registry.
	 * @return array<int,string>
	 */
	public function configTweakIds( Registry $registry ): array {
		$ids = array();

		foreach ( array_keys( $this->selection ) as $tweak_id ) {
			if ( $registry->has( $tweak_id ) && TweakKind::CONFIG === $registry->tweak( $tweak_id )->kind ) {
				$ids[] = $tweak_id;
			}
		}

		return $ids;
	}

	/**
	 * Whether the document came from this registry version.
	 *
	 * A mismatch is worth telling the user about — a tweak may mean something
	 * slightly different now — but it is not a reason to refuse.
	 *
	 * @param Registry $registry The registry.
	 * @return bool
	 */
	public function matchesRegistry( Registry $registry ): bool {
		return '' === $this->registry_hash || hash_equals( $registry->hash(), $this->registry_hash );
	}

	/**
	 * How many changes the document carries.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->selection );
	}
}
