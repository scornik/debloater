<?php
/**
 * Writes the generated runtime to disk.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Apply;

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable,
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// -- WP_Filesystem is the wrong tool here, and using it would be less safe rather
// than more.
//
// It cannot do an atomic replace: there is no move() that guarantees rename(2)
// semantics, and a non-atomic write to a file that is loaded on every request is
// exactly how a site ends up serving half a runtime. It also asks for FTP
// credentials when it cannot write directly, which during an apply means a
// credentials prompt in the middle of a change that is already underway.
//
// Everything written here goes inside wp-content/debloater or mu-plugins, along
// paths this plugin builds itself (BUILD-SPEC §13 rule 6), and
// tests/Integration/SecurityRulesTest.php asserts that boundary.

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages never reach output raw. Rest\Controller::guard() escapes
// every Throwable at the REST edge and Cli\Command catches at the CLI edge, which is where BUILD-SPEC §13 rule 4 puts escaping;
// tests/Integration/ExceptionBoundaryTest.php holds both. Escaping at the throw sites instead would put esc_html() inside
// src/Contracts and src/Registry, which are required not to call WordPress at all.

use RuntimeException;
use Debloater\Contracts\Context;
use Debloater\Contracts\Json;

/**
 * Puts the compiled runtime in place safely (BUILD-SPEC §10, §13 rule 6).
 *
 * The file this class writes is included on every single request to the site.
 * A half-written or syntactically broken runtime.php is a white screen, so the
 * write is staged: compile to a temporary file in the same directory, check that
 * PHP can actually parse it, then rename over the target. `rename()` within one
 * filesystem is atomic, so a request arriving mid-write sees either the old file
 * or the new one, never a partial one.
 *
 * Everything is written under wp-content/debloater/ and nowhere else, and every
 * path is checked against that directory after resolution rather than before.
 */
final class RuntimeWriter {

	/**
	 * Permissions for generated files.
	 */
	private const FILE_MODE = 0644;

	/**
	 * Permissions for the generated directory.
	 */
	private const DIR_MODE = 0755;

	/**
	 * Site context.
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
	 * Write the runtime, or remove it when the source is empty.
	 *
	 * @param string $source        Compiled runtime source, '' for an empty selection.
	 * @param string $selection_hash Hash of the selection the source was compiled from.
	 * @param string $registry_hash  Hash of the registry it was compiled against.
	 * @return string The runtime hash, or '' when no runtime file exists.
	 * @throws RuntimeException When the write cannot be completed safely.
	 */
	public function write( string $source, string $selection_hash, string $registry_hash = '' ): string {
		if ( '' === trim( $source ) ) {
			$this->remove();

			return '';
		}

		$this->assertSyntax( $source );

		$directory = $this->prepareDirectory();
		$target    = $directory . '/runtime.php';
		$hash      = hash( 'sha256', $source );

		$this->atomicWrite( $target, $source );

		$this->atomicWrite(
			$directory . '/runtime.lock',
			Json::encode(
				array(
					'runtime_hash'   => $hash,
					'selection_hash' => $selection_hash,
					'registry_hash'  => $registry_hash,
					'plugin_version' => $this->context->plugin_version,
					'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
				),
				JSON_PRETTY_PRINT
			) . "\n"
		);

		return $hash;
	}

	/**
	 * Remove the runtime and its lock.
	 *
	 * BUILD-SPEC §10: an empty selection leaves no runtime file at all, so there
	 * is nothing to stat, nothing to parse and nothing to go wrong.
	 *
	 * @return void
	 * @throws RuntimeException When an existing file cannot be removed.
	 */
	public function remove(): void {
		foreach ( array( $this->context->runtimeFile(), $this->context->runtimeLockFile() ) as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$this->assertInsideRuntimeDir( $path );

			if ( ! unlink( $path ) ) {
				throw new RuntimeException( sprintf( 'Could not remove %s', $path ) );
			}
		}
	}

	/**
	 * The hash recorded in runtime.lock, or '' when there is no lock.
	 *
	 * @return string
	 */
	public function recordedHash(): string {
		$lock = $this->readLock();

		return is_string( $lock['runtime_hash'] ?? null ) ? $lock['runtime_hash'] : '';
	}

	/**
	 * The full contents of runtime.lock, or an empty array when absent.
	 *
	 * @return array<string,mixed>
	 */
	public function readLock(): array {
		$path = $this->context->runtimeLockFile();

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			return array();
		}

		try {
			$decoded = Json::decodeArray( $raw );
		} catch ( RuntimeException $exception ) {
			unset( $exception );

			return array();
		}

		/** @var array<string,mixed> $decoded */
		return $decoded;
	}

	/**
	 * The hash of the runtime file as it currently exists on disk.
	 *
	 * @return string Empty when there is no runtime file.
	 */
	public function actualHash(): string {
		$path = $this->context->runtimeFile();

		if ( ! is_readable( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path );

		return false === $contents ? '' : hash( 'sha256', $contents );
	}

	/**
	 * Whether the runtime on disk is the one the lock describes.
	 *
	 * A mismatch means the file was edited or replaced outside the plugin. It is
	 * reported rather than repaired: silently overwriting somebody's edit would
	 * destroy the evidence of whatever they were trying to do.
	 *
	 * @return bool
	 */
	public function isIntact(): bool {
		$recorded = $this->recordedHash();
		$actual   = $this->actualHash();

		if ( '' === $recorded && '' === $actual ) {
			return true;
		}

		return '' !== $recorded && hash_equals( $recorded, $actual );
	}

	/**
	 * Check that PHP can parse the source before it is put in place.
	 *
	 * `token_get_all()` with TOKEN_PARSE runs the real parser rather than only
	 * the lexer, so it raises ParseError on anything `php -l` would reject. It
	 * does this in-process, which matters twice over: there is no subprocess to
	 * spawn on every apply, and there is no dependency on exec functions, which
	 * are disabled on a large share of shared hosting.
	 *
	 * This file used to run `php -l` through proc_open() as a second opinion.
	 * It was removed: TOKEN_PARSE already catches every case it caught, and on
	 * exactly the hosts where a corrupted write would be hardest to recover
	 * from, proc_open() was disabled and the check had been quietly doing
	 * nothing. A safety net that is absent where it is most needed is worse
	 * than no safety net, because it is mistaken for one.
	 *
	 * The generated source comes entirely from Compiler, out of validated
	 * inputs, so this is a last line rather than the first.
	 *
	 * @param string $source Source to check.
	 * @return void
	 * @throws RuntimeException When the source does not parse.
	 */
	public function assertSyntax( string $source ): void {
		try {
			$tokens = token_get_all( $source, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			throw new RuntimeException(
				'Generated runtime failed lexical validation: ' . $error->getMessage(),
				0,
				$error
			);
		}

		if ( array() === $tokens ) {
			throw new RuntimeException( 'Generated runtime is empty; refusing to write it.' );
		}

		if ( T_OPEN_TAG !== $tokens[0][0] ) {
			throw new RuntimeException( 'Generated runtime does not start with a PHP open tag; refusing to write it.' );
		}
	}

	/**
	 * Create the generated-files directory if it is missing.
	 *
	 * @return string The directory path.
	 * @throws RuntimeException When the directory cannot be created or written to.
	 */
	private function prepareDirectory(): string {
		$directory = $this->context->runtimeDir();

		if ( ! is_dir( $directory ) && ! mkdir( $directory, self::DIR_MODE, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( sprintf( 'Could not create the runtime directory: %s', $directory ) );
		}

		if ( ! is_writable( $directory ) ) {
			throw new RuntimeException( sprintf( 'The runtime directory is not writable: %s', $directory ) );
		}

		$index = $directory . '/index.php';

		if ( ! file_exists( $index ) ) {
			// Directory listings are usually off, but a bare index costs nothing
			// and removes the "usually".
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			@chmod( $index, self::FILE_MODE );
		}

		return $directory;
	}

	/**
	 * Write a file through a temporary file and an atomic rename.
	 *
	 * @param string $target   Destination path.
	 * @param string $contents File contents.
	 * @return void
	 * @throws RuntimeException When the write fails at any stage.
	 */
	private function atomicWrite( string $target, string $contents ): void {
		$this->assertInsideRuntimeDir( $target );

		$temporary = $target . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		if ( false === file_put_contents( $temporary, $contents, LOCK_EX ) ) {
			throw new RuntimeException( sprintf( 'Could not write the temporary file for %s', $target ) );
		}

		if ( ! chmod( $temporary, self::FILE_MODE ) ) {
			unlink( $temporary );

			throw new RuntimeException( sprintf( 'Could not set permissions on the temporary file for %s', $target ) );
		}

		if ( ! rename( $temporary, $target ) ) {
			unlink( $temporary );

			throw new RuntimeException( sprintf( 'Could not move the generated file into place: %s', $target ) );
		}

		// Opcache would otherwise keep serving the previous runtime for the rest
		// of this process, and on some setups for subsequent requests too.
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true );
		}

		clearstatcache( true, $target );
	}

	/**
	 * Refuse to touch anything outside the generated-files directory.
	 *
	 * The check is on the resolved parent directory, so a path containing "../"
	 * cannot pass by looking innocent as a string.
	 *
	 * @param string $path Path about to be written or removed.
	 * @return void
	 * @throws RuntimeException When the path escapes the runtime directory.
	 */
	private function assertInsideRuntimeDir( string $path ): void {
		$normalised = str_replace( '\\', '/', $path );
		$parent     = realpath( dirname( $normalised ) );
		$runtime    = realpath( $this->context->runtimeDir() );

		if ( false === $parent || false === $runtime ) {
			throw new RuntimeException( sprintf( 'Could not resolve the runtime directory for %s', $path ) );
		}

		$parent  = rtrim( str_replace( '\\', '/', $parent ), '/' );
		$runtime = rtrim( str_replace( '\\', '/', $runtime ), '/' );

		if ( $parent !== $runtime ) {
			throw new RuntimeException(
				sprintf( 'Refusing to write outside %s (asked for %s)', $runtime, $path )
			);
		}
	}
}
