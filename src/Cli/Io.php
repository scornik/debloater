<?php
/**
 * Everything the CLI says, and how it stops.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Cli;

/**
 * The boundary between the commands and WP-CLI itself.
 *
 * The commands are meant to contain no logic of their own (BUILD-SPEC §17
 * Phase 7); they read from the engine and print. This interface is what makes
 * that testable: the real implementation calls `WP_CLI::log()` and friends, and
 * the tests use one that records what was said and what exit code was asked
 * for. Without it, testing "does `apply` exit 2 when verification fails" would
 * mean letting a test kill the process.
 */
interface Io {

	/**
	 * Print a line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function line( string $message ): void;

	/**
	 * Print a success line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function success( string $message ): void;

	/**
	 * Print a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( string $message ): void;

	/**
	 * Report an error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( string $message ): void;

	/**
	 * Print a JSON document.
	 *
	 * @param array<string,mixed> $document Document to print.
	 * @return void
	 */
	public function json( array $document ): void;

	/**
	 * Print a table.
	 *
	 * @param array<int,array<string,scalar|null>> $rows    Rows.
	 * @param array<int,string>                    $headers Column keys, in order.
	 * @return void
	 */
	public function table( array $rows, array $headers ): void;

	/**
	 * Stop with an exit code.
	 *
	 * @param int $code Exit code: 0 ok, 1 error, 2 rolled back, 3 warnings.
	 * @return void
	 */
	public function halt( int $code ): void;
}
