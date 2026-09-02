<?php
/**
 * A terminal that remembers instead of printing.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Integration\Support;

use WPDebloat\Cli\Io;

/**
 * `Io` for tests.
 *
 * Records everything the command said and the exit code it asked for, instead
 * of writing to the terminal and ending the process. Without this, testing "does
 * apply exit 2 when verification fails" would mean letting a test kill the
 * runner.
 */
final class RecordingIo implements Io {

	/**
	 * Every line, in order, whatever its kind.
	 *
	 * @var array<int,string>
	 */
	public array $lines = array();

	/**
	 * Success lines.
	 *
	 * @var array<int,string>
	 */
	public array $successes = array();

	/**
	 * Warnings.
	 *
	 * @var array<int,string>
	 */
	public array $warnings = array();

	/**
	 * Errors.
	 *
	 * @var array<int,string>
	 */
	public array $errors = array();

	/**
	 * JSON documents printed.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $documents = array();

	/**
	 * Tables printed, as rows.
	 *
	 * @var array<int,array<int,array<string,scalar|null>>>
	 */
	public array $tables = array();

	/**
	 * The exit code asked for, or null when the command has not finished.
	 *
	 * @var int|null
	 */
	public ?int $code = null;

	/**
	 * Print a line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function line( string $message ): void {
		$this->lines[] = $message;
	}

	/**
	 * Print a success line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function success( string $message ): void {
		$this->lines[]     = $message;
		$this->successes[] = $message;
	}

	/**
	 * Print a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function warning( string $message ): void {
		$this->lines[]    = $message;
		$this->warnings[] = $message;
	}

	/**
	 * Report an error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( string $message ): void {
		$this->lines[]  = $message;
		$this->errors[] = $message;
	}

	/**
	 * Record a JSON document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return void
	 */
	public function json( array $document ): void {
		$this->documents[] = $document;
	}

	/**
	 * Record a table.
	 *
	 * @param array<int,array<string,scalar|null>> $rows    Rows.
	 * @param array<int,string>                    $headers Column keys.
	 * @return void
	 */
	public function table( array $rows, array $headers ): void {
		unset( $headers );

		$this->tables[] = $rows;
	}

	/**
	 * Record the exit code.
	 *
	 * @param int $code Exit code.
	 * @return void
	 */
	public function halt( int $code ): void {
		$this->code = $code;
	}

	/**
	 * The last JSON document printed.
	 *
	 * @return array<string,mixed>
	 */
	public function lastDocument(): array {
		return $this->documents[ count( $this->documents ) - 1 ] ?? array();
	}

	/**
	 * Everything said, as one string, for substring assertions.
	 *
	 * @return string
	 */
	public function output(): string {
		return implode( "\n", $this->lines );
	}
}
