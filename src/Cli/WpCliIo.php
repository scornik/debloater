<?php
/**
 * The real terminal.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Cli;

use WP_CLI;
use WP_CLI\Utils;
use Debloater\Contracts\Json;

/**
 * `Io` implemented against WP-CLI.
 *
 * Thin on purpose: every method here is one call. Anything with a decision in
 * it belongs in the command, and anything with a decision about the *site* in
 * it belongs further down still, in the engine.
 */
final class WpCliIo implements Io {

	/**
	 * Print a line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function line( string $message ): void {
		WP_CLI::log( $message );
	}

	/**
	 * Print a success line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function success( string $message ): void {
		WP_CLI::success( $message );
	}

	/**
	 * Print a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( string $message ): void {
		WP_CLI::warning( $message );
	}

	/**
	 * Report an error.
	 *
	 * Printed without halting, so the command decides the exit code. WP-CLI's
	 * own `error()` exits with 1, which would make the "verification failed"
	 * and "verified with warnings" codes unreachable.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( string $message ): void {
		WP_CLI::error( $message, false );
	}

	/**
	 * Print a JSON document.
	 *
	 * @param array<string,mixed> $document Document to print.
	 * @return void
	 */
	public function json( array $document ): void {
		WP_CLI::line( Json::encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Print a table.
	 *
	 * @param array<int,array<string,scalar|null>> $rows    Rows.
	 * @param array<int,string>                    $headers Column keys, in order.
	 * @return void
	 */
	public function table( array $rows, array $headers ): void {
		if ( array() === $rows ) {
			return;
		}

		Utils\format_items( 'table', $rows, $headers );
	}

	/**
	 * Stop with an exit code.
	 *
	 * @param int $code Exit code.
	 * @return void
	 */
	public function halt( int $code ): void {
		WP_CLI::halt( $code );
	}
}
