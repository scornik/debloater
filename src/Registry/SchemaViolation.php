<?php
/**
 * One schema validation failure.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

/**
 * A single validation failure, located by JSON pointer.
 *
 * Validation collects violations rather than throwing on the first one, because
 * both audiences need the whole list: a registry author fixing a tweak file, and
 * a REST caller being told which parameters were rejected (BUILD-SPEC §13
 * rule 3).
 */
final class SchemaViolation {

	/**
	 * JSON pointer to the offending value, e.g. "/params/interval".
	 *
	 * @var string
	 */
	public readonly string $pointer;

	/**
	 * The schema keyword that failed, e.g. "type", "required", "enum".
	 *
	 * @var string
	 */
	public readonly string $keyword;

	/**
	 * Human-readable explanation.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * Constructor.
	 *
	 * @param string $pointer JSON pointer to the value.
	 * @param string $keyword Failing keyword.
	 * @param string $message Explanation.
	 */
	public function __construct( string $pointer, string $keyword, string $message ) {
		$this->pointer = '' === $pointer ? '/' : $pointer;
		$this->keyword = $keyword;
		$this->message = $message;
	}

	/**
	 * A single-line description, suitable for logs and test failures.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return sprintf( '%s: %s (%s)', $this->pointer, $this->message, $this->keyword );
	}

	/**
	 * Array shape.
	 *
	 * @return array{pointer:string,keyword:string,message:string}
	 */
	public function toArray(): array {
		return array(
			'pointer' => $this->pointer,
			'keyword' => $this->keyword,
			'message' => $this->message,
		);
	}
}
