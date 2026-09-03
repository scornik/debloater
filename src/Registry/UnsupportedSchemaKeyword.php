<?php
/**
 * Thrown when a schema uses a keyword the validator does not implement.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Registry;

use RuntimeException;

/**
 * A schema used a keyword outside the supported subset (docs/DECISIONS.md D-0001).
 *
 * This is a hard error, not a silent pass. Ignoring an unknown keyword would
 * mean an author who writes, say, `dependentRequired` gets a document that
 * appears validated but is not — and schema validation is the barrier between
 * user input and generated executable code (BUILD-SPEC §13 rule 5). Failing
 * loudly at schema-load time keeps that barrier honest.
 */
final class UnsupportedSchemaKeyword extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $keyword The unsupported keyword.
	 * @param string $pointer JSON pointer to where it appeared in the schema.
	 */
	public function __construct( string $keyword, string $pointer ) {
		parent::__construct(
			sprintf(
				'Schema keyword "%s" at %s is not supported by Debloater\Registry\SchemaValidator. '
				. 'Add support for it before using it in a registry schema (see docs/DECISIONS.md D-0001).',
				$keyword,
				'' === $pointer ? '/' : $pointer
			)
		);
	}
}
