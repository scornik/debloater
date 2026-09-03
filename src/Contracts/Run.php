<?php
/**
 * One recorded run.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Contracts;

/**
 * A row of debloater_runs (BUILD-SPEC §8).
 *
 * Every scan, apply, rollback, verification and measurement is a run, and every
 * run records what it was planned against: the plugin version and the registry
 * hash. That is what lets a result from last month be read honestly — you can
 * tell whether it was produced by the definitions in front of you or by
 * different ones.
 *
 * `status` holds a state-machine value (§9.2) rather than a free-form string, so
 * a run found mid-flight at boot can be recognised and recovered.
 */
final class Run {

	/**
	 * Row id, or null before the run has been persisted.
	 *
	 * @var int|null
	 */
	public readonly ?int $id;

	/**
	 * What kind of run this is.
	 *
	 * @var RunType
	 */
	public readonly RunType $type;

	/**
	 * Current status. For apply runs this is a RunState value.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Who started it: "user:123", "cli", "cron" or "system".
	 *
	 * @var string
	 */
	public readonly string $actor;

	/**
	 * Start time, UTC, "Y-m-d H:i:s".
	 *
	 * @var string
	 */
	public readonly string $started_at;

	/**
	 * Finish time, or null while the run is in flight.
	 *
	 * @var string|null
	 */
	public readonly ?string $finished_at;

	/**
	 * Plugin version that produced this run.
	 *
	 * @var string
	 */
	public readonly string $plugin_version;

	/**
	 * Hash of the registry the run was planned against.
	 *
	 * @var string
	 */
	public readonly string $registry_hash;

	/**
	 * Payload: facts and findings for a scan, the plan for an apply, results
	 * otherwise.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $payload;

	/**
	 * Failure message, when the run failed.
	 *
	 * @var string|null
	 */
	public readonly ?string $error;

	/**
	 * Constructor.
	 *
	 * @param int|null            $id             Row id, null when unsaved.
	 * @param RunType             $type           Run type.
	 * @param string              $status         Status.
	 * @param string              $actor          Acting principal.
	 * @param string              $started_at     UTC start time.
	 * @param string|null         $finished_at    UTC finish time, or null.
	 * @param string              $plugin_version Plugin version.
	 * @param string              $registry_hash  Registry hash.
	 * @param array<string,mixed> $payload        Run payload.
	 * @param string|null         $error          Failure message.
	 * @throws ContractViolation When a field is malformed.
	 */
	public function __construct(
		?int $id,
		RunType $type,
		string $status,
		string $actor,
		string $started_at,
		?string $finished_at,
		string $plugin_version,
		string $registry_hash,
		array $payload = array(),
		?string $error = null
	) {
		if ( null !== $id && $id < 1 ) {
			throw ContractViolation::range( self::class, 'id', 'must be a positive row id or null' );
		}

		if ( '' === trim( $status ) ) {
			throw ContractViolation::range( self::class, 'status', 'must not be empty' );
		}

		if ( 1 !== preg_match( Identifier::ACTOR_PATTERN, $actor ) ) {
			throw ContractViolation::range(
				self::class,
				'actor',
				sprintf( 'must be "cli", "cron", "system" or "user:<id>", got "%s"', $actor )
			);
		}

		self::assertTimestamp( 'started_at', $started_at );

		if ( null !== $finished_at ) {
			self::assertTimestamp( 'finished_at', $finished_at );

			if ( $finished_at < $started_at ) {
				throw ContractViolation::range( self::class, 'finished_at', 'must not be before started_at' );
			}
		}

		if ( '' === trim( $plugin_version ) ) {
			throw ContractViolation::range( self::class, 'plugin_version', 'must not be empty' );
		}

		if ( 1 !== preg_match( Identifier::SHA256_PATTERN, $registry_hash ) ) {
			throw ContractViolation::range( self::class, 'registry_hash', 'must be a lowercase sha256 hex digest' );
		}

		foreach ( array_keys( $payload ) as $key ) {
			if ( ! is_string( $key ) ) {
				throw ContractViolation::type( self::class, 'payload key', 'string', $key );
			}
		}

		$this->id             = $id;
		$this->type           = $type;
		$this->status         = $status;
		$this->actor          = $actor;
		$this->started_at     = $started_at;
		$this->finished_at    = $finished_at;
		$this->plugin_version = $plugin_version;
		$this->registry_hash  = $registry_hash;
		$this->payload        = $payload;
		$this->error          = $error;
	}

	/**
	 * Build from an array shape.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return self
	 * @throws ContractViolation When the shape is invalid.
	 */
	public static function fromArray( array $data ): self {
		Assert::onlyKeys(
			self::class,
			$data,
			array(
				'id',
				'type',
				'status',
				'actor',
				'started_at',
				'finished_at',
				'plugin_version',
				'registry_hash',
				'payload',
				'error',
			)
		);

		$id = array_key_exists( 'id', $data ) ? $data['id'] : null;

		if ( null !== $id && ! is_int( $id ) ) {
			throw ContractViolation::type( self::class, 'id', 'int or null', $id );
		}

		return new self(
			$id,
			Assert::enum( self::class, $data, 'type', RunType::class ),
			Assert::string( self::class, $data, 'status' ),
			Assert::string( self::class, $data, 'actor' ),
			Assert::string( self::class, $data, 'started_at' ),
			Assert::nullableString( self::class, $data, 'finished_at' ),
			Assert::string( self::class, $data, 'plugin_version' ),
			Assert::string( self::class, $data, 'registry_hash' ),
			Assert::stringKeyedMap( self::class, $data, 'payload' ),
			Assert::nullableString( self::class, $data, 'error' )
		);
	}

	/**
	 * Array shape, the inverse of fromArray().
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'type'           => $this->type->value,
			'status'         => $this->status,
			'actor'          => $this->actor,
			'started_at'     => $this->started_at,
			'finished_at'    => $this->finished_at,
			'plugin_version' => $this->plugin_version,
			'registry_hash'  => $this->registry_hash,
			'payload'        => $this->payload,
			'error'          => $this->error,
		);
	}

	/**
	 * Whether the run has finished, successfully or not.
	 *
	 * @return bool
	 */
	public function isFinished(): bool {
		return null !== $this->finished_at;
	}

	/**
	 * The facts recorded by a scan run.
	 *
	 * @return FactSet
	 * @throws ContractViolation When the payload does not hold a valid fact set.
	 */
	public function facts(): FactSet {
		$facts = $this->payload['facts'] ?? array();

		if ( ! is_array( $facts ) ) {
			throw ContractViolation::type( self::class, 'payload.facts', 'array', $facts );
		}

		/** @var array<string,mixed> $facts */
		return FactSet::fromArray( $facts );
	}

	/**
	 * A copy carrying the row id assigned on insert.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function withId( int $id ): self {
		return new self(
			$id,
			$this->type,
			$this->status,
			$this->actor,
			$this->started_at,
			$this->finished_at,
			$this->plugin_version,
			$this->registry_hash,
			$this->payload,
			$this->error
		);
	}

	/**
	 * A copy with a new status, and optionally a finish time and error.
	 *
	 * @param string      $status      New status.
	 * @param string|null $finished_at UTC finish time, or null to leave open.
	 * @param string|null $error       Failure message.
	 * @return self
	 */
	public function withStatus( string $status, ?string $finished_at = null, ?string $error = null ): self {
		return new self(
			$this->id,
			$this->type,
			$status,
			$this->actor,
			$this->started_at,
			$finished_at ?? $this->finished_at,
			$this->plugin_version,
			$this->registry_hash,
			$this->payload,
			$error ?? $this->error
		);
	}

	/**
	 * A copy with a different payload.
	 *
	 * @param array<string,mixed> $payload New payload.
	 * @return self
	 */
	public function withPayload( array $payload ): self {
		return new self(
			$this->id,
			$this->type,
			$this->status,
			$this->actor,
			$this->started_at,
			$this->finished_at,
			$this->plugin_version,
			$this->registry_hash,
			$payload,
			$this->error
		);
	}

	/**
	 * Reject a timestamp that is not in the stored format.
	 *
	 * @param string $field Field name, for the error message.
	 * @param string $value Timestamp to check.
	 * @return void
	 * @throws ContractViolation When the timestamp is malformed.
	 */
	private static function assertTimestamp( string $field, string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			throw ContractViolation::range( self::class, $field, 'must be a UTC "Y-m-d H:i:s" timestamp' );
		}
	}
}
