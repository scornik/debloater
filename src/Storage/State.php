<?php
/**
 * The plugin's persisted state.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Storage;

use Debloater\Brand;
use Debloater\Contracts\Json;
use Debloater\Contracts\TweakState;

/**
 * Reads and writes the single option Debloater stores (BUILD-SPEC §8).
 *
 * Everything lives in one option, and that option is not autoloaded. A plugin
 * whose job is to reduce what WordPress loads on every request would be a poor
 * advertisement for itself if it added its own row to the autoload set. Nothing
 * on a front-end request reads this option at all: the runtime is a plain file
 * include and knows nothing about state.
 *
 * Unknown keys found in the stored option are preserved rather than dropped, so
 * a downgrade after an upgrade does not silently discard newer settings.
 */
final class State {

	/**
	 * Current state schema version.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Keys this version knows about, with their defaults.
	 */
	private const DEFAULTS = array(
		'schema_version'    => self::SCHEMA_VERSION,
		'selection'         => array(),
		'tweak_states'      => array(),
		'intent_profile'    => array(),
		'last_scan_run_id'  => 0,
		'runtime_hash'      => '',
		'loader_mode'       => '',
		'installed_at'      => '',
		'uninstall_cleanup' => false,
		'attestation'       => array(),
	);

	/**
	 * In-request cache, so repeated reads do not re-decode the option.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * The whole state, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( Brand::STATE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		/** @var array<string,mixed> $stored */
		$this->cache = array_merge( self::DEFAULTS, $stored );

		return $this->cache;
	}

	/**
	 * One value, with its default.
	 *
	 * @param string $key      State key.
	 * @param mixed  $fallback Returned when the key is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$state = $this->all();

		if ( ! array_key_exists( $key, $state ) ) {
			return $fallback;
		}

		return $state[ $key ];
	}

	/**
	 * Write one or more values.
	 *
	 * @param array<string,mixed> $values Values to merge into the state.
	 * @return bool Whether the option was written.
	 */
	public function set( array $values ): bool {
		$state = array_merge( $this->all(), $values );

		$state['schema_version'] = self::SCHEMA_VERSION;

		$this->cache = $state;

		// autoload 'no': this option is only read on our own screens, in the CLI
		// and during a run, never on a front-end request.
		return update_option( Brand::STATE_OPTION, $state, false );
	}

	/**
	 * The current selection: tweak id to parameter values.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function selection(): array {
		$selection = $this->get( 'selection', array() );

		if ( ! is_array( $selection ) ) {
			return array();
		}

		$clean = array();

		foreach ( $selection as $tweak_id => $params ) {
			if ( ! is_string( $tweak_id ) ) {
				continue;
			}

			$clean[ $tweak_id ] = is_array( $params ) ? $params : array();
		}

		ksort( $clean, SORT_STRING );

		/** @var array<string,array<string,mixed>> $clean */
		return $clean;
	}

	/**
	 * Replace the selection.
	 *
	 * @param array<string,array<string,mixed>> $selection Tweak id to parameters.
	 * @return bool
	 */
	public function setSelection( array $selection ): bool {
		ksort( $selection, SORT_STRING );

		return $this->set( array( 'selection' => $selection ) );
	}

	/**
	 * The recorded lifecycle state of every tweak.
	 *
	 * @return array<string,TweakState>
	 */
	public function tweakStates(): array {
		$stored = $this->get( 'tweak_states', array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$states = array();

		foreach ( $stored as $tweak_id => $value ) {
			if ( ! is_string( $tweak_id ) || ! is_string( $value ) ) {
				continue;
			}

			$state = TweakState::tryFrom( $value );

			if ( null !== $state ) {
				$states[ $tweak_id ] = $state;
			}
		}

		return $states;
	}

	/**
	 * Record the lifecycle state of one tweak.
	 *
	 * @param string     $tweak_id Tweak id.
	 * @param TweakState $state    New state.
	 * @return bool
	 */
	public function setTweakState( string $tweak_id, TweakState $state ): bool {
		$stored = $this->get( 'tweak_states', array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored[ $tweak_id ] = $state->value;

		ksort( $stored, SORT_STRING );

		return $this->set( array( 'tweak_states' => $stored ) );
	}

	/**
	 * The hash of the runtime the plugin believes it generated.
	 *
	 * @return string
	 */
	public function runtimeHash(): string {
		$hash = $this->get( 'runtime_hash', '' );

		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Record the generated runtime hash and how the loader was installed.
	 *
	 * @param string $runtime_hash Runtime hash, '' when there is no runtime.
	 * @param string $loader_mode  Loader mode, see Apply\RuntimeLoader.
	 * @return bool
	 */
	public function setRuntime( string $runtime_hash, string $loader_mode ): bool {
		return $this->set(
			array(
				'runtime_hash' => $runtime_hash,
				'loader_mode'  => $loader_mode,
			)
		);
	}

	/**
	 * How the runtime loader is installed.
	 *
	 * @return string
	 */
	public function loaderMode(): string {
		$mode = $this->get( 'loader_mode', '' );

		return is_string( $mode ) ? $mode : '';
	}

	/**
	 * Whether uninstalling should drop tables and options.
	 *
	 * Defaults to false: removing a plugin is not consent to delete the record
	 * of what it changed (BUILD-SPEC §13 rule 10).
	 *
	 * @return bool
	 */
	public function uninstallCleanup(): bool {
		return true === $this->get( 'uninstall_cleanup', false );
	}

	/**
	 * Record first installation, if not already recorded.
	 *
	 * @return void
	 */
	public function markInstalled(): void {
		if ( '' === (string) $this->get( 'installed_at', '' ) ) {
			$this->set( array( 'installed_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
	}

	/**
	 * Forget the in-request cache.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Delete the option entirely. Used by uninstall and by tests.
	 *
	 * @return void
	 */
	public function delete(): void {
		$this->cache = null;

		delete_option( Brand::STATE_OPTION );
	}

	/**
	 * A stable hash of the current selection.
	 *
	 * @return string
	 */
	public function selectionHash(): string {
		return hash( 'sha256', Json::canonical( $this->selection() ) );
	}
}
