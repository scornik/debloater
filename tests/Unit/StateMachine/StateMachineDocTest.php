<?php
/**
 * Keeps docs/STATE-MACHINE.md in step with the enums.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Unit\StateMachine;

use PHPUnit\Framework\TestCase;
use Debloater\Tests\Unit\Support\StateMachineDoc;

/**
 * BUILD-SPEC §9 requires the committed transition table to be generated from the
 * enum so it can never drift. This test is that guarantee.
 *
 * Run with DEBLOATER_UPDATE_DOCS=1 to rewrite the document after changing a
 * state machine. Without the flag the test only reports staleness, so a test run
 * never silently edits the repository.
 */
final class StateMachineDocTest extends TestCase {

	/**
	 * The committed document must match what the enums produce.
	 *
	 * @return void
	 */
	public function test_committed_document_matches_the_enums(): void {
		$expected = StateMachineDoc::render();
		$path     = StateMachineDoc::path();

		if ( '1' === getenv( 'DEBLOATER_UPDATE_DOCS' ) ) {
			file_put_contents( $path, $expected );
		}

		$this->assertFileExists( $path, 'docs/STATE-MACHINE.md is missing; regenerate with DEBLOATER_UPDATE_DOCS=1' );

		$actual = file_get_contents( $path );

		$this->assertIsString( $actual );
		$this->assertSame(
			$expected,
			str_replace( "\r\n", "\n", $actual ),
			'docs/STATE-MACHINE.md is stale. Regenerate it with DEBLOATER_UPDATE_DOCS=1 vendor/bin/phpunit.'
		);
	}

	/**
	 * The document must actually contain every state, not just parse.
	 *
	 * @return void
	 */
	public function test_document_lists_every_state(): void {
		$document = StateMachineDoc::render();

		foreach ( \Debloater\Contracts\RunState::cases() as $state ) {
			$this->assertStringContainsString( '`' . $state->value . '`', $document );
		}

		foreach ( \Debloater\Contracts\TweakState::cases() as $state ) {
			$this->assertStringContainsString( '`' . $state->value . '`', $document );
		}
	}
}
