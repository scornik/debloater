<?php
/**
 * Deciding whether a page rendered, from its text.
 *
 * @package WPDebloat
 */

declare( strict_types = 1 );

namespace WPDebloat\Tests\Unit\Verify;

use PHPUnit\Framework\TestCase;
use WPDebloat\Verify\Markers;
use WPDebloat\Verify\Response;

/**
 * BUILD-SPEC §11, docs/DECISIONS.md D-0019.
 *
 * The markers decide whether a user's site gets rolled back, so the interesting
 * tests here are the ones that must *not* fire: a blog post about a fatal error
 * is a page that rendered perfectly, and treating it as a broken site would undo
 * a change for no reason.
 */
final class MarkersTest extends TestCase {

	/**
	 * Each fatal marker is recognised.
	 *
	 * @return void
	 */
	public function test_every_fatal_marker_is_recognised(): void {
		foreach ( Markers::FATAL as $marker ) {
			$this->assertSame(
				$marker,
				Markers::fatalIn( '<html><body>' . $marker . ': something went wrong</body></html>' )
			);
		}
	}

	/**
	 * Matching ignores case, because PHP's own output does not agree with
	 * itself about it.
	 *
	 * @return void
	 */
	public function test_matching_ignores_case(): void {
		$this->assertSame( 'Fatal error', Markers::fatalIn( 'FATAL ERROR: too much' ) );
		$this->assertSame( 'Fatal error', Markers::fatalIn( 'fatal error: too much' ) );
	}

	/**
	 * A healthy page has no fatal marker in it.
	 *
	 * @return void
	 */
	public function test_a_healthy_page_has_no_fatal_marker(): void {
		$this->assertSame(
			'',
			Markers::fatalIn( '<!DOCTYPE html><html><head><title>Hello</title></head><body>Hi</body></html>' )
		);
	}

	/**
	 * Whole phrases, not words: a page that merely mentions errors is fine.
	 *
	 * @return void
	 */
	public function test_a_page_that_talks_about_errors_is_not_an_error_page(): void {
		$bodies = array(
			'<h1>What to do about a critical error</h1>',
			'<p>Our error rate fell this quarter.</p>',
			'<p>The parse ran without incident.</p>',
			'<p>WP_Errors are objects; this post explains them.</p>',
			'<code>if ( is_wp_error( $result ) ) { ... }</code>',
		);

		foreach ( $bodies as $body ) {
			$this->assertSame( '', Markers::fatalIn( $body ), $body );
		}
	}

	/**
	 * A WP_Error that has been printed onto the page is caught, which is the
	 * thing §11's `WP_Error` marker is actually about.
	 *
	 * @return void
	 */
	public function test_a_printed_wp_error_is_caught(): void {
		$this->assertSame(
			'WP_Error Object',
			Markers::fatalIn(
				'WP_Error Object
(
    [errors] => Array
)' 
			)
		);

		$this->assertSame(
			'object(WP_Error)',
			Markers::fatalIn( 'object(WP_Error)#42 (2) { ["errors"]=> array(0) {} }' )
		);
	}

	/**
	 * The first marker found is the one reported, so the message names
	 * something that is actually in the page.
	 *
	 * @return void
	 */
	public function test_the_reported_marker_is_present_in_the_body(): void {
		$body   = 'Parse error: syntax error, unexpected token';
		$marker = Markers::fatalIn( $body );

		$this->assertNotSame( '', $marker );
		$this->assertStringContainsString( $marker, $body );
	}

	/**
	 * Missing markers are listed so the message can name them.
	 *
	 * @return void
	 */
	public function test_missing_markers_are_listed(): void {
		$this->assertSame(
			array( '</html>' ),
			Markers::missing( '<html><head><title>x</title></head><body>truncated', Markers::DOCUMENT_END )
		);

		$this->assertSame(
			array(),
			Markers::missing( '<html></html>', Markers::DOCUMENT_END )
		);
	}

	/**
	 * All-present and any-present are not the same question.
	 *
	 * @return void
	 */
	public function test_all_present_and_any_present_differ(): void {
		$body = '<div id="wpbody"></div>';

		$this->assertTrue( Markers::anyPresent( $body, Markers::ADMIN ) );
		$this->assertFalse( Markers::allPresent( $body, Markers::ADMIN ) );
	}

	/**
	 * A response that never completed is not a response with a status.
	 *
	 * @return void
	 */
	public function test_an_unreachable_response_is_distinguishable(): void {
		$failed = new Response( 0, '', 'cURL error 7', 12, 'https://example.test' );

		$this->assertFalse( $failed->reachable() );
		$this->assertFalse( $failed->isSuccess() );
		$this->assertTrue( $failed->isEmpty() );
		$this->assertNull( $failed->json() );
	}

	/**
	 * A JSON body decodes; anything else reports null rather than guessing.
	 *
	 * @return void
	 */
	public function test_json_decoding_is_all_or_nothing(): void {
		$this->assertSame( array( 'a' => 1 ), ( new Response( 200, '{"a":1}' ) )->json() );
		$this->assertNull( ( new Response( 200, 'Notice: x{"a":1}' ) )->json() );
		$this->assertNull( ( new Response( 200, '<html></html>' ) )->json() );
	}

	/**
	 * Every response carries the same evidence, so probe output is comparable.
	 *
	 * @return void
	 */
	public function test_evidence_is_uniform(): void {
		$evidence = ( new Response( 200, 'body', '', 34, 'https://example.test/' ) )->evidence();

		$this->assertSame(
			array( 'http_status', 'elapsed_ms', 'bytes', 'url' ),
			array_keys( $evidence )
		);

		$this->assertSame( 4, $evidence['bytes'] );
	}
}
