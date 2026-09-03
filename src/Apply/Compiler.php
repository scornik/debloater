<?php
/**
 * Turns a selection of tweaks into the generated runtime source.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export() is not debug output here: it is the code generator.
// A validated value is turned back into PHP source with the one function whose
// contract is that its result parses to the value it was given, which is what
// makes BUILD-SPEC §13 rule 5 hold ("params via var_export of validated values
// only"). Building that source by hand is how injection gets in.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Contracts\Context;
use Debloater\Contracts\Json;
use Debloater\Contracts\Tweak;
use Debloater\Contracts\TweakKind;

/**
 * Compiles a selection into the PHP source of wp-content/debloater/runtime.php
 * (BUILD-SPEC §10).
 *
 * Three properties matter here and each is enforced rather than assumed.
 *
 * **Determinism.** Tweaks are emitted in sorted id order and parameters in
 * sorted name order, so the same selection always produces byte-identical
 * source. That is what lets runtime.lock's hash mean "this file is the one the
 * selection describes", and what makes an unexpected diff a signal rather than
 * noise.
 *
 * **No timestamp in the file.** BUILD-SPEC §10 sketches a "generated <date>"
 * header, but §17 Phase 1 requires regeneration to be byte-identical, and the
 * two cannot both hold. The header therefore carries the selection and registry
 * hashes, which are the facts worth having, and the generation time is recorded
 * in runtime.lock instead (docs/DECISIONS.md D-0005).
 *
 * **Nothing user-controlled reaches the source.** Handler paths come from the
 * registry and are realpath-checked into the plugin's own runtime-handlers
 * directory; parameter values are emitted through var_export after schema
 * validation. Neither a tweak id nor a parameter name is ever interpolated
 * into the output as text (BUILD-SPEC §13 rule 5).
 */
final class Compiler {

	/**
	 * Site context, for path resolution.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Site context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Compile a selection of tweaks into runtime source.
	 *
	 * An empty selection compiles to an empty string: BUILD-SPEC §10 requires
	 * that nothing be written at all in that case, and returning source that
	 * "does nothing" would still cost a file stat and a parse on every request.
	 *
	 * @param array<int,Tweak> $tweaks        Selected tweaks.
	 * @param string           $registry_hash Hash of the registry they came from.
	 * @return string Runtime source, or '' when the selection is empty.
	 * @throws RuntimeException When a handler cannot be resolved safely.
	 */
	public function compile( array $tweaks, string $registry_hash = '' ): string {
		$config = $this->configTweaksSorted( $tweaks );

		if ( array() === $config ) {
			return '';
		}

		$lines = array(
			'<?php',
			'/**',
			' * Debloater runtime. Generated file: DO NOT EDIT.',
			' *',
			' * Editing this file will not change anything for long; it is rewritten',
			' * from the saved selection whenever the selection changes. Any change',
			' * made here is also detected, because runtime.lock holds this file\'s',
			' * hash and the status endpoint compares them.',
			' *',
			' * selection ' . $this->selectionHash( $config ),
			'' === $registry_hash ? ' * registry  (not recorded)' : ' * registry  ' . $registry_hash,
			' *',
			' * To switch the runtime off: define( \'DEBLOATER_DISABLE\', true ) in wp-config.php.',
			' *',
			' * @package Debloater',
			' */',
			'',
			'if ( ! defined( \'ABSPATH\' ) ) {',
			"\treturn;",
			'}',
			'',
			'require_once ' . var_export( $this->guardPath(), true ) . ';',
			'',
			'if ( Debloater_Runtime_Guard::disabled() || Debloater_Runtime_Guard::bypass_allowed() ) {',
			"\treturn;",
			'}',
			'',
		);

		foreach ( $config as $tweak ) {
			$lines[] = '// ' . $this->comment( $tweak->id );
			$lines[] = 'require_once ' . var_export( $this->handlerPath( $tweak ), true ) . ';';
			$lines[] = $this->handlerClass( $tweak->id ) . '::register( ' . $this->exportParams( $tweak ) . ' );';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * The hash identifying a selection.
	 *
	 * Computed from tweak ids and their parameters, so changing a parameter
	 * changes the hash, but reordering the selection does not.
	 *
	 * @param array<int,Tweak> $tweaks Selected tweaks.
	 * @return string
	 */
	public function selectionHash( array $tweaks ): string {
		$canonical = array();

		foreach ( $this->configTweaksSorted( $tweaks ) as $tweak ) {
			$canonical[ $tweak->id ] = $tweak->params->toArray();
		}

		return hash( 'sha256', Json::canonical( $canonical ) );
	}

	/**
	 * The handler class name for a tweak id.
	 *
	 * "core.disable_emojis" becomes "Debloater_Handler_Core_Disable_Emojis"
	 * (CONVENTIONS.md). The id has already been validated against the tweak-id
	 * grammar, so the result cannot contain anything but word characters.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string
	 */
	public function handlerClass( string $tweak_id ): string {
		$segments = preg_split( '/[._]/', $tweak_id );

		if ( false === $segments ) {
			throw new RuntimeException( sprintf( 'Could not derive a handler class from "%s".', $tweak_id ) );
		}

		return 'Debloater_Handler_' . implode( '_', array_map( 'ucfirst', $segments ) );
	}

	/**
	 * The absolute, verified path of a tweak's handler file.
	 *
	 * @param Tweak $tweak Tweak to resolve.
	 * @return string
	 * @throws RuntimeException When the handler is missing or outside the plugin.
	 */
	public function handlerPath( Tweak $tweak ): string {
		$handlers_dir = $this->realDirectory( $this->context->handlersDir() );
		$candidate    = $this->context->plugin_dir . '/' . ltrim( $tweak->handler, '/' );
		$resolved     = realpath( $candidate );

		if ( false === $resolved ) {
			throw new RuntimeException(
				sprintf( 'Handler file for tweak "%s" not found: %s', $tweak->id, $tweak->handler )
			);
		}

		$resolved = str_replace( '\\', '/', $resolved );

		// BUILD-SPEC §13 rule 5: generated code may only require files from the
		// plugin's own handler directory. Resolving first and comparing after
		// means a traversal in the registry value cannot escape it.
		if ( ! str_starts_with( $resolved, $handlers_dir . '/' ) ) {
			throw new RuntimeException(
				sprintf(
					'Handler for tweak "%s" resolves outside the plugin handler directory (%s); refusing to generate code that loads it.',
					$tweak->id,
					$resolved
				)
			);
		}

		return $resolved;
	}

	/**
	 * The absolute path of the runtime guard.
	 *
	 * @return string
	 * @throws RuntimeException When the guard is missing.
	 */
	public function guardPath(): string {
		$path = realpath( $this->context->handlersDir() . '/runtime-guard.php' );

		if ( false === $path ) {
			throw new RuntimeException( 'The runtime guard is missing; refusing to generate a runtime without a kill switch.' );
		}

		return str_replace( '\\', '/', $path );
	}

	/**
	 * Config tweaks from a selection, in deterministic id order.
	 *
	 * Data tweaks run once through a DataOperation and never appear in the
	 * runtime, so they are filtered out here rather than at every call site.
	 *
	 * @param array<int,Tweak> $tweaks Selected tweaks.
	 * @return array<int,Tweak>
	 * @throws RuntimeException When a tweak id appears twice.
	 */
	private function configTweaksSorted( array $tweaks ): array {
		$by_id = array();

		foreach ( $tweaks as $tweak ) {
			if ( TweakKind::CONFIG !== $tweak->kind ) {
				continue;
			}

			if ( array_key_exists( $tweak->id, $by_id ) ) {
				throw new RuntimeException( sprintf( 'Tweak "%s" appears twice in the selection.', $tweak->id ) );
			}

			$by_id[ $tweak->id ] = $tweak;
		}

		ksort( $by_id, SORT_STRING );

		return array_values( $by_id );
	}

	/**
	 * Export a tweak's parameters as a PHP array literal.
	 *
	 * Every value goes through var_export, which is the whole point: values have
	 * already been validated against the tweak's declared parameter schema, and
	 * var_export is what guarantees the validated value is what ends up in the
	 * file rather than a string that happens to look like it.
	 *
	 * @param Tweak $tweak Tweak whose parameters to export.
	 * @return string
	 * @throws RuntimeException When a parameter name is not a safe identifier.
	 */
	private function exportParams( Tweak $tweak ): string {
		$params = $tweak->params->toArray();

		if ( array() === $params ) {
			return 'array()';
		}

		$parts = array();

		foreach ( $params as $name => $value ) {
			// TweakParams already enforces this; asserting again here means the
			// guarantee is stated where the code is generated, not two files away.
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
				throw new RuntimeException(
					sprintf( 'Parameter name "%s" of tweak "%s" is not a safe identifier.', $name, $tweak->id )
				);
			}

			$parts[] = var_export( $name, true ) . ' => ' . $this->exportValue( $value );
		}

		return 'array( ' . implode( ', ', $parts ) . ' )';
	}

	/**
	 * Export one parameter value.
	 *
	 * @param scalar|array<int,scalar> $value Value to export.
	 * @return string
	 */
	private function exportValue( $value ): string {
		if ( ! is_array( $value ) ) {
			return var_export( $value, true );
		}

		if ( array() === $value ) {
			return 'array()';
		}

		return 'array( ' . implode( ', ', array_map( static fn ( $item ): string => var_export( $item, true ), $value ) ) . ' )';
	}

	/**
	 * A safe comment naming a tweak.
	 *
	 * The id matched the tweak-id grammar before it reached here, so it cannot
	 * contain a comment terminator; stripping anything outside that grammar makes
	 * the guarantee local rather than remembered.
	 *
	 * @param string $tweak_id Tweak id.
	 * @return string
	 */
	private function comment( string $tweak_id ): string {
		return (string) preg_replace( '/[^a-z0-9._]/', '', $tweak_id );
	}

	/**
	 * Resolve a directory, normalised, failing when it does not exist.
	 *
	 * @param string $directory Directory path.
	 * @return string
	 * @throws RuntimeException When the directory cannot be resolved.
	 */
	private function realDirectory( string $directory ): string {
		$resolved = realpath( $directory );

		if ( false === $resolved ) {
			throw new RuntimeException( sprintf( 'Directory not found: %s', $directory ) );
		}

		return rtrim( str_replace( '\\', '/', $resolved ), '/' );
	}
}
